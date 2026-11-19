<?php
/**
 * Background job runner.
 *
 * Work happens in short time slices. Each slice ends by firing a non-blocking loopback request
 * that starts the next one, so no visitor request ever waits on a backup. A once-a-minute
 * WP-Cron tick resumes jobs if a loopback is lost, and the admin screen nudges jobs on hosts
 * that block loopback requests. A file lock (released automatically if PHP dies) guarantees
 * only one slice runs at a time. Job state lives in a JSON file, not the database, so it
 * survives a database restore.
 *
 * Modes:
 *  - easy: 4 s slices, light compression, ~8 MB/s disk/network cap, pauses while the server is busy.
 *  - rush: 25 s slices, no throttling.
 *
 * @package Lifeboat_Backup
 */

defined( 'ABSPATH' ) || exit;

class Lifeboat_Runner {

	const BUDGET   = array( 'easy' => 4, 'rush' => 25 );
	const EASY_BPS = 8388608;

	public static function job_file() {
		return Lifeboat_Util::backup_dir() . '/.job.json';
	}

	public static function job() {
		return Lifeboat_Util::read_json( self::job_file() );
	}

	public static function save( array $job ) {
		$job['updated'] = time();
		Lifeboat_Util::write_json( self::job_file(), $job );
	}

	public static function is_active( $job = null ) {
		$job = null === $job ? self::job() : $job;
		return is_array( $job ) && 'running' === $job['status'];
	}

	/**
	 * @return array|WP_Error The started job.
	 */
	public static function start( array $job ) {
		if ( self::is_active() ) {
			return new WP_Error( 'lifeboat_busy', __( 'Another backup or restore is already running.', 'lifeboat-backup' ) );
		}
		self::save( $job );
		self::kick();
		return $job;
	}

	public static function cancel() {
		$job = self::job();
		if ( ! self::is_active( $job ) ) {
			return;
		}
		$lock = self::lock( true );
		$job  = self::job();
		$job['status']  = 'cancelled';
		$job['message'] = __( 'Cancelled.', 'lifeboat-backup' );
		self::save( $job );
		self::handler( $job )::cleanup( $job );
		self::unlock( $lock );
	}

	private static function handler( array $job ) {
		return 'restore' === $job['type'] ? 'Lifeboat_Restore' : 'Lifeboat_Backup';
	}

	public static function token( $id ) {
		return hash_hmac( 'sha256', 'lifeboat-run|' . $id, wp_salt( 'auth' ) );
	}

	/** Fire-and-forget request that runs the next slice. */
	public static function kick() {
		$job = self::job();
		if ( ! $job ) {
			return;
		}
		wp_remote_post(
			admin_url( 'admin-ajax.php' ),
			array(
				'timeout'   => 0.01,
				'blocking'  => false,
				'sslverify' => apply_filters( 'https_local_ssl_verify', false ),
				'body'      => array(
					'action' => 'lifeboat_run',
					'job'    => $job['id'],
					'token'  => self::token( $job['id'] ),
				),
			)
		);
	}

	/** Loopback endpoint (no login: authenticated by the HMAC token). */
	public static function ajax_run() {
		$job   = self::job();
		$id    = isset( $_POST['job'] ) ? sanitize_text_field( wp_unslash( $_POST['job'] ) ) : ''; // phpcs:ignore
		$token = isset( $_POST['token'] ) ? sanitize_text_field( wp_unslash( $_POST['token'] ) ) : ''; // phpcs:ignore
		if ( ! $job || $job['id'] !== $id || ! hash_equals( self::token( $id ), $token ) ) {
			wp_die( '', '', array( 'response' => 403 ) );
		}
		ignore_user_abort( true );
		if ( get_transient( 'lifeboat_loopback_blocked' ) ) {
			delete_transient( 'lifeboat_loopback_blocked' ); // Loopbacks reach us again.
		}
		self::run();
		wp_die();
	}

	/** WP-Cron safety net: resume a job whose loopback chain broke. */
	public static function cron_tick() {
		$job = self::job();
		if ( ! self::is_active( $job ) || $job['updated'] >= time() - 40 ) {
			return;
		}
		if ( empty( $job['retry_at'] ) || $job['retry_at'] <= time() ) {
			// No slice for 40 s although nothing asked to wait: the loopback request never arrived.
			// Usually a firewall or bot check (Cloudflare "Just a moment…") answering the site's
			// own request. Remember it so the Backups screen can explain the slower progress.
			set_transient( 'lifeboat_loopback_blocked', time(), DAY_IN_SECONDS );
		}
		// Work for most of this cron request instead of a single short slice, so a large backup
		// still finishes in hours, not days, when loopbacks are blocked.
		$limit = (int) ini_get( 'max_execution_time' );
		$end   = time() + ( $limit > 0 ? max( 10, min( 50, $limit - 15 ) ) : 50 );
		do {
			$before = $job['updated'];
			self::run();
			$job = self::job(); // Re-read: run() returns its own copy, without the saved timestamp.
			if ( ! self::is_active( $job ) || ! empty( $job['retry_at'] ) || ! empty( $job['busy_since'] ) || $job['updated'] === $before ) {
				break; // Finished, backing off, waiting for a busy server, or another slice holds the lock.
			}
		} while ( time() < $end );
	}

	/** True when the site's requests to itself were recently seen failing. */
	public static function loopback_blocked() {
		return (bool) get_transient( 'lifeboat_loopback_blocked' );
	}

	private static function lock( $wait = false ) {
		$fh = fopen( Lifeboat_Util::backup_dir() . '/.lock', 'c' );
		if ( ! $fh ) {
			return false;
		}
		if ( ! flock( $fh, $wait ? LOCK_EX : LOCK_EX | LOCK_NB ) ) {
			fclose( $fh );
			return false;
		}
		return $fh;
	}

	private static function unlock( $fh ) {
		if ( $fh ) {
			flock( $fh, LOCK_UN );
			fclose( $fh );
		}
	}

	/**
	 * Run one slice of the active job.
	 *
	 * @return array|null Job state after the slice.
	 */
	public static function run( $budget = null ) {
		$lock = self::lock();
		if ( ! $lock ) {
			return self::job(); // Another slice is running.
		}
		$job = self::job();
		if ( ! self::is_active( $job ) ) {
			self::unlock( $lock );
			return $job;
		}
		if ( ! empty( $job['retry_at'] ) && $job['retry_at'] > time() ) {
			self::unlock( $lock );
			return $job; // Backing off after a cloud error; cron resumes it.
		}
		if ( 'easy' === $job['mode'] && Lifeboat_Util::server_busy() ) {
			if ( empty( $job['busy_since'] ) ) {
				$job['busy_since'] = time();
			}
			// Pause while the server is busy, but never for more than 10 minutes in a row.
			if ( time() - $job['busy_since'] < 600 ) {
				$job['message'] = __( 'Waiting: the server is busy, Easy mode will continue when it calms down.', 'lifeboat-backup' );
				self::save( $job );
				self::unlock( $lock );
				return $job;
			}
		}
		unset( $job['busy_since'] );

		$budget = null === $budget ? self::BUDGET[ $job['mode'] ] : $budget;
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( $budget + 120 ); // phpcs:ignore
		}
		if ( function_exists( 'wp_raise_memory_limit' ) ) {
			wp_raise_memory_limit( 'admin' );
		}
		$deadline = microtime( true ) + $budget;
		$handler  = self::handler( $job );
		try {
			$handler::step( $job, $deadline );
		} catch ( Throwable $e ) {
			$job['status']  = 'failed';
			$job['error']   = $e->getMessage();
			$job['message'] = $e->getMessage();
			self::save( $job );
			$handler::cleanup( $job );
			if ( 'backup' === $job['type'] ) {
				update_option( 'lifeboat_last_backup', array( 'time' => time(), 'id' => $job['id'], 'status' => 'failed', 'message' => $job['message'], 'trigger' => $job['trigger'] ), false );
			}
			Lifeboat_Plugin::notify( $job );
		}
		self::save( $job );
		self::unlock( $lock );

		if ( self::is_active( $job ) && empty( $job['retry_at'] ) ) {
			self::kick();
		}
		return $job;
	}

	/**
	 * Easy mode bandwidth cap: sleep so reads/uploads average at most EASY_BPS.
	 */
	public static function throttle( $bytes, $easy ) {
		static $window_start = null, $window_bytes = 0;
		if ( ! $easy || $bytes <= 0 ) {
			return;
		}
		if ( null === $window_start ) {
			$window_start = microtime( true );
		}
		$window_bytes += $bytes;
		$should_take   = $window_bytes / self::EASY_BPS;
		$elapsed       = microtime( true ) - $window_start;
		if ( $should_take > $elapsed ) {
			usleep( (int) min( 2000000, ( $should_take - $elapsed ) * 1000000 ) );
		}
	}
}
