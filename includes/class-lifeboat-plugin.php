<?php
/**
 * Bootstrap, cron, notifications, lifecycle.
 *
 * @package Lifeboat_Backup
 */

defined( 'ABSPATH' ) || exit;

class Lifeboat_Plugin {

	/** @var Lifeboat_Plugin */
	private static $instance;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		Lifeboat_Scheduler::init();
		Lifeboat_Snapshots::init();
		add_filter( 'cron_schedules', array( __CLASS__, 'cron_schedules' ) );
		add_action( 'lifeboat_tick', array( 'Lifeboat_Runner', 'cron_tick' ) );
		add_action( 'wp_ajax_lifeboat_run', array( 'Lifeboat_Runner', 'ajax_run' ) );
		add_action( 'wp_ajax_nopriv_lifeboat_run', array( 'Lifeboat_Runner', 'ajax_run' ) );
		add_action( 'init', array( __CLASS__, 'maintain' ) );
		if ( class_exists( 'Lifeboat_Admin' ) ) {
			Lifeboat_Admin::init();
		}
	}

	public static function cron_schedules( $s ) {
		$s['lifeboat_minute'] = array(
			'interval' => 60,
			'display'  => 'Every minute (Lifeboat)',
		);
		return $s;
	}

	public static function maintain() {
		if ( ! wp_next_scheduled( 'lifeboat_tick' ) ) {
			wp_schedule_event( time() + 60, 'lifeboat_minute', 'lifeboat_tick' );
		}
		Lifeboat_Scheduler::ensure();
		if ( get_option( 'lifeboat_version' ) !== LIFEBOAT_VERSION ) {
			update_option( 'lifeboat_version', LIFEBOAT_VERSION, false );
		}
	}

	public static function activate() {
		Lifeboat_Util::backup_dir();
		if ( false === get_option( Lifeboat_Settings::OPTION ) ) {
			add_option( Lifeboat_Settings::OPTION, Lifeboat_Settings::defaults(), '', true );
		}
		add_filter( 'cron_schedules', array( __CLASS__, 'cron_schedules' ) );
		if ( ! wp_next_scheduled( 'lifeboat_tick' ) ) {
			wp_schedule_event( time() + 60, 'lifeboat_minute', 'lifeboat_tick' );
		}
		Lifeboat_Scheduler::reschedule();
		set_transient( 'lifeboat_welcome', 1, HOUR_IN_SECONDS );
	}

	public static function deactivate() {
		foreach ( array( 'lifeboat_tick', Lifeboat_Scheduler::FULL, Lifeboat_Scheduler::DB, Lifeboat_Scheduler::AFTER ) as $hook ) {
			wp_clear_scheduled_hook( $hook );
		}
		// Backups stay on disk and in the cloud.
	}

	/** Email the result of a finished, failed or partially failed job. */
	public static function notify( array $job ) {
		$when = Lifeboat_Settings::get( 'notify' );
		$bad  = 'failed' === $job['status'] || ! empty( $job['failed_dest'] );
		if ( 'never' === $when || ( 'failures' === $when && ! $bad ) ) {
			return;
		}
		$to   = Lifeboat_Settings::get( 'notify_email' );
		$to   = $to ? $to : get_option( 'admin_email' );
		$site = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		$what = 'restore' === $job['type'] ? __( 'Restore', 'lifeboat-backup' ) : __( 'Backup', 'lifeboat-backup' );
		$subject = sprintf( '[%s] %s %s', $site, $what, 'failed' === $job['status'] ? __( 'FAILED', 'lifeboat-backup' ) : ( $bad ? __( 'completed with problems', 'lifeboat-backup' ) : __( 'completed', 'lifeboat-backup' ) ) );
		$body    = $job['message'] . "\n";
		if ( ! empty( $job['failed_dest'] ) ) {
			$body .= "\n" . __( 'Upload problems:', 'lifeboat-backup' ) . "\n";
			foreach ( $job['failed_dest'] as $d => $msg ) {
				$body .= '• ' . $msg . "\n";
			}
		}
		if ( ! empty( $job['warnings'] ) ) {
			$body .= "\n" . __( 'Warnings:', 'lifeboat-backup' ) . "\n• " . implode( "\n• ", array_slice( array_unique( $job['warnings'] ), 0, 15 ) ) . "\n";
		}
		$body .= "\n" . admin_url( 'admin.php?page=lifeboat' );
		wp_mail( $to, $subject, $body );
	}
}
