<?php
/**
 * Admin screens, AJAX and form handlers, OAuth callback.
 *
 * @package Lifeboat_Backup
 */

defined( 'ABSPATH' ) || exit;

class Lifeboat_Admin {

	const CAP = 'manage_options';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_filter( 'admin_body_class', array( __CLASS__, 'body_class' ) );
		add_action( 'admin_notices', array( __CLASS__, 'notices' ) );
		add_action( 'wp_dashboard_setup', array( __CLASS__, 'dashboard_widget' ) );
		add_action( 'admin_post', array( __CLASS__, 'oauth_callback' ) ); // admin-post.php with no action = OAuth redirect URI.
		add_filter( 'plugin_action_links_' . plugin_basename( LIFEBOAT_FILE ), array( __CLASS__, 'links' ) );
		foreach ( array( 'save_settings', 'save_dest', 'connect', 'disconnect', 'mega_connect', 'download', 'encryption', 'rollback', 'snapshot_delete' ) as $a ) {
			add_action( 'admin_post_lifeboat_' . $a, array( __CLASS__, 'post_' . $a ) );
		}
		foreach ( array( 'status', 'backup_now', 'cancel', 'restore', 'delete', 'test' ) as $a ) {
			add_action( 'wp_ajax_lifeboat_' . $a, array( __CLASS__, 'ajax_' . $a ) );
		}
	}

	public static function menu() {
		add_submenu_page( Prasat_Hub::SLUG, 'Lifeboat Backup', 'Lifeboat Backup', self::CAP, 'lifeboat', array( __CLASS__, 'render' ) );
	}

	/** True on Lifeboat's own screen (its hook name depends on the menu it lives in). */
	public static function on_page() {
		return isset( $_GET['page'] ) && 'lifeboat' === $_GET['page']; // phpcs:ignore
	}

	public static function body_class( $c ) {
		return self::on_page() ? $c . ' lb-body' : $c;
	}

	public static function links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( admin_url( 'admin.php?page=lifeboat' ) ) . '">' . esc_html__( 'Backups', 'lifeboat-backup' ) . '</a>' );
		return $links;
	}

	public static function tabs() {
		return array(
			'backups'  => __( 'Backups', 'lifeboat-backup' ),
			'schedule' => __( 'Schedule & settings', 'lifeboat-backup' ),
			'storage'  => __( 'Cloud storage', 'lifeboat-backup' ),
		);
	}

	public static function url( $tab = 'backups', array $args = array() ) {
		return add_query_arg( array_merge( array( 'page' => 'lifeboat', 'tab' => $tab ), $args ), admin_url( 'admin.php' ) );
	}

	/** Version plus file time, so browsers pick up changed CSS/JS without a version bump. */
	private static function asset_version() {
		return LIFEBOAT_VERSION . '.' . (int) @filemtime( LIFEBOAT_DIR . 'assets/admin.css' ) . (int) @filemtime( LIFEBOAT_DIR . 'assets/admin.js' ); // phpcs:ignore
	}

	public static function assets( $hook ) {
		if ( 'index.php' === $hook && current_user_can( self::CAP ) ) {
			wp_enqueue_style( 'lifeboat-admin', LIFEBOAT_URL . 'assets/admin.css', array(), self::asset_version() ); // Dashboard widget.
			return;
		}
		if ( ! self::on_page() ) {
			return;
		}
		wp_enqueue_style( 'lifeboat-admin', LIFEBOAT_URL . 'assets/admin.css', array(), self::asset_version() );
		wp_enqueue_script( 'lifeboat-ui', LIFEBOAT_URL . 'assets/prasat-ui.js', array(), self::asset_version(), true );
		wp_enqueue_script( 'lifeboat-admin', LIFEBOAT_URL . 'assets/admin.js', array( 'lifeboat-ui' ), self::asset_version(), true );
		wp_localize_script(
			'lifeboat-admin',
			'Lifeboat',
			array(
				'ajax'  => admin_url( 'admin-ajax.php' ),
				'nonce' => wp_create_nonce( 'lifeboat' ),
				'job'   => self::job_summary( Lifeboat_Runner::job() ),
				'i18n'  => array(
					'confirmCancel'  => __( 'Stop this job? Partial files will be removed.', 'lifeboat-backup' ),
					'confirmDelete'  => __( 'Delete this backup from this server and every cloud storage it was sent to? This cannot be undone.', 'lifeboat-backup' ),
					'confirmRestore' => __( 'Restore now? The selected parts of your site will be replaced with the backup. Consider making a fresh backup first.', 'lifeboat-backup' ),
					'error'          => __( 'Something went wrong. Please reload the page.', 'lifeboat-backup' ),
					'done'           => __( 'Done', 'lifeboat-backup' ),
					'failed'         => __( 'Failed', 'lifeboat-backup' ),
					'cancelled'      => __( 'Cancelled', 'lifeboat-backup' ),
					'testing'        => __( 'Testing…', 'lifeboat-backup' ),
					'pickPart'       => __( 'Choose at least one part.', 'lifeboat-backup' ),
					'backingUp'      => __( 'Backing up', 'lifeboat-backup' ),
					'restoring'      => __( 'Restoring', 'lifeboat-backup' ),
					'modeEasy'       => __( 'Easy', 'lifeboat-backup' ),
					'modeRush'       => __( 'Rush', 'lifeboat-backup' ),
					'lostContact'    => __( 'Lost contact with the server. The job keeps running in the background; reload this page to check on it.', 'lifeboat-backup' ),
					'backupDone'     => __( 'Backup successful', 'lifeboat-backup' ),
					'restoreDone'    => __( 'Restore successful', 'lifeboat-backup' ),
					'partialTitle'   => __( 'Backup finished, but not everywhere', 'lifeboat-backup' ),
					'backupFailed'   => __( 'Backup failed', 'lifeboat-backup' ),
					'restoreFailed'  => __( 'Restore failed', 'lifeboat-backup' ),
					'backupCancelled'  => __( 'Backup stopped', 'lifeboat-backup' ),
					'restoreCancelled' => __( 'Restore stopped', 'lifeboat-backup' ),
					'included'       => __( 'Included', 'lifeboat-backup' ),
					'size'           => __( 'Size', 'lifeboat-backup' ),
					'took'           => __( 'Took', 'lifeboat-backup' ),
					'finishedAt'     => __( 'Finished', 'lifeboat-backup' ),
					'verified'       => __( 'saved and checksum-verified', 'lifeboat-backup' ),
					'notUploaded'    => __( 'upload failed (hover for details)', 'lifeboat-backup' ),
					'encryption'     => __( 'Encryption', 'lifeboat-backup' ),
					'encryptedYes'   => __( 'AES-256, password protected', 'lifeboat-backup' ),
					'passwordLabel'  => __( 'Encryption password for this backup', 'lifeboat-backup' ),
					'passwordNeeded' => __( 'Enter the encryption password for this backup.', 'lifeboat-backup' ),
				),
			)
		);
	}

	public static function notices() {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}
		$screen = get_current_screen();
		if ( get_transient( 'lifeboat_welcome' ) && $screen && ! self::on_page() ) {
			printf(
				'<div class="notice notice-info is-dismissible"><p><strong>%s</strong> %s <a href="%s">%s</a></p></div>',
				esc_html__( 'Lifeboat Backup is ready.', 'lifeboat-backup' ),
				esc_html__( 'Weekly full backups and daily database backups are scheduled. Connect a cloud storage so backups also live off this server.', 'lifeboat-backup' ),
				esc_url( self::url( 'storage' ) ),
				esc_html__( 'Connect cloud storage →', 'lifeboat-backup' )
			);
		}
		// A failed backup is shown everywhere in the admin until a later backup succeeds.
		$last = get_option( 'lifeboat_last_backup' );
		if ( $last && 'failed' === $last['status'] && $last['time'] > time() - WEEK_IN_SECONDS && $screen && ! self::on_page() && 'dashboard' !== $screen->id ) {
			printf(
				'<div class="notice notice-error"><p><strong>%s</strong> %s <a href="%s">%s</a></p></div>',
				esc_html( sprintf( __( 'Lifeboat: the last backup failed %s ago.', 'lifeboat-backup' ), human_time_diff( $last['time'] ) ) ),
				esc_html( isset( $last['message'] ) ? $last['message'] : '' ),
				esc_url( self::url() ),
				esc_html__( 'View details', 'lifeboat-backup' )
			);
		}
		// Encryption is on but the key can't be unlocked here (site moved, salts changed): backups would fail.
		if ( $screen && Lifeboat_Vault::needs_password() ) {
			printf(
				'<div class="notice notice-error"><p><strong>%s</strong> %s <a href="%s">%s</a></p></div>',
				esc_html__( 'Lifeboat: backups are paused.', 'lifeboat-backup' ),
				esc_html__( 'Backup encryption is on, but this site needs the encryption password entered again.', 'lifeboat-backup' ),
				esc_url( self::url( 'schedule' ) . '#lb-encryption' ),
				esc_html__( 'Enter password', 'lifeboat-backup' )
			);
		}
		$note = self::on_page() && isset( $_GET['lb_msg'] ) ? get_transient( 'lifeboat_msg_' . get_current_user_id() ) : false; // phpcs:ignore
		if ( is_array( $note ) ) {
			delete_transient( 'lifeboat_msg_' . get_current_user_id() );
			printf( '<div class="notice notice-%s is-dismissible"><p>%s</p></div>', $note['error'] ? 'error' : 'success', esc_html( $note['msg'] ) );
		}
	}

	/**
	 * One-line status of the most recent backup: ✓ successful, ! partial, ✗ failed.
	 */
	/** Last backup state: [status (ok|partial|failed|none), title, detail]. */
	private static function last_backup_info() {
		$last   = get_option( 'lifeboat_last_backup' );
		$status = $last && in_array( $last['status'], array( 'ok', 'partial', 'failed' ), true ) ? $last['status'] : 'none';
		$ago    = $last ? human_time_diff( $last['time'] ) : '';
		if ( 'ok' === $status ) {
			$title = sprintf( __( 'Last backup successful — %s ago', 'lifeboat-backup' ), $ago );
		} elseif ( 'partial' === $status ) {
			$title = sprintf( __( 'Last backup %s ago reached only some places', 'lifeboat-backup' ), $ago );
		} elseif ( 'failed' === $status ) {
			$title = sprintf( __( 'Last backup failed — %s ago', 'lifeboat-backup' ), $ago );
		} else {
			$title = __( 'No backup has run yet', 'lifeboat-backup' );
		}
		$detail = '';
		if ( in_array( $status, array( 'ok', 'partial' ), true ) ) {
			$bits = array();
			if ( ! empty( $last['size'] ) ) {
				$bits[] = Lifeboat_Util::bytes( $last['size'] );
			}
			if ( ! empty( $last['where'] ) ) {
				$bits[] = sprintf( __( 'stored on %s', 'lifeboat-backup' ), implode( ', ', (array) $last['where'] ) );
			}
			$detail = implode( ' · ', $bits );
		} elseif ( 'failed' === $status && ! empty( $last['message'] ) ) {
			$detail = $last['message'];
		}
		return array( $status, $title, $detail, $ago );
	}

	/**
	 * One-line status of the most recent backup: ✓ successful, ! partial, ✗ failed.
	 */
	public static function last_backup_status() {
		list( $status, $title, $detail ) = self::last_backup_info();
		$icons = array( 'ok' => '&#10003;', 'partial' => '!', 'failed' => '&#10007;', 'none' => '&ndash;' );
		printf(
			'<div class="lb-last lb-last--%1$s"><span class="lb-last__icon" aria-hidden="true">%2$s</span><div><strong>%3$s</strong>%4$s</div></div>',
			esc_attr( $status ),
			$icons[ $status ], // phpcs:ignore -- fixed HTML entity from the table above.
			esc_html( $title ),
			$detail ? '<span class="lb-last__detail">' . esc_html( $detail ) . '</span>' : ''
		);
	}

	/** Compact status for the brand header. */
	private static function header_status() {
		list( $status, , , $ago ) = self::last_backup_info();
		if ( Lifeboat_Vault::needs_password() ) {
			$status = 'failed';
			$text   = __( 'Backups paused', 'lifeboat-backup' );
			$sub    = __( 'encryption password needed', 'lifeboat-backup' );
		} elseif ( 'ok' === $status ) {
			$text = __( 'Protected', 'lifeboat-backup' );
			$sub  = sprintf( __( 'last backup %s ago', 'lifeboat-backup' ), $ago );
		} elseif ( 'partial' === $status ) {
			$text = __( 'Needs attention', 'lifeboat-backup' );
			$sub  = __( 'last backup reached only some places', 'lifeboat-backup' );
		} elseif ( 'failed' === $status ) {
			$text = __( 'Last backup failed', 'lifeboat-backup' );
			$sub  = sprintf( __( '%s ago', 'lifeboat-backup' ), $ago );
		} else {
			$text = __( 'Not backed up yet', 'lifeboat-backup' );
			$sub  = '';
		}
		$next = wp_next_scheduled( Lifeboat_Scheduler::FULL );
		if ( 'ok' === $status && $next ) {
			$sub .= ' · ' . sprintf( __( 'next %s', 'lifeboat-backup' ), wp_date( 'D ' . get_option( 'time_format' ), $next ) );
		}
		printf(
			'<a class="lb-brand__status" href="%1$s"><span class="lb-dot lb-dot--%2$s" aria-hidden="true"></span>%3$s%4$s</a>',
			esc_url( self::url() ),
			esc_attr( $status ),
			esc_html( $text ),
			$sub ? ' <small>' . esc_html( $sub ) . '</small>' : ''
		);
	}

	public static function dashboard_widget() {
		if ( current_user_can( self::CAP ) ) {
			wp_add_dashboard_widget( 'lifeboat_status', __( 'Lifeboat Backup', 'lifeboat-backup' ), array( __CLASS__, 'render_dashboard_widget' ) );
		}
	}

	public static function render_dashboard_widget() {
		self::last_backup_status();
		$next = wp_next_scheduled( Lifeboat_Scheduler::FULL );
		if ( $next ) {
			echo '<p>' . esc_html( sprintf( __( 'Next full backup: %s', 'lifeboat-backup' ), wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $next ) ) ) . '</p>';
		}
		printf( '<p><a class="button button-primary" href="%s">%s</a></p>', esc_url( self::url() ), esc_html__( 'Open Lifeboat', 'lifeboat-backup' ) );
	}

	/** "by Prasat Digital" — the maker's credit in the header, linking to the website. */
	private static function credit() {
		printf(
			'<a class="lb-credit" href="%1$s" target="_blank" rel="noopener noreferrer" title="prasatdigital.com"><img src="%2$s" alt="" width="20" height="20"><span>%3$s</span></a>',
			esc_url( 'https://www.prasatdigital.com' ),
			esc_url( LIFEBOAT_URL . 'assets/img/prasat-mark-white.svg' ),
			/* translators: %s: company name, Prasat Digital */
			sprintf( esc_html__( 'by %s', 'lifeboat-backup' ), '<b>Prasat Digital</b>' )
		);
	}


	public static function render() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to access this page.', 'lifeboat-backup' ) );
		}
		delete_transient( 'lifeboat_welcome' );
		Lifeboat_History::sync_local();
		$tab  = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'backups'; // phpcs:ignore
		$tabs = self::tabs();
		$tab  = isset( $tabs[ $tab ] ) ? $tab : 'backups';
		echo '<div class="wrap lifeboat"><header class="lb-brand"><div class="lb-brand__id"><span class="lb-brand__mark lb-brand__mark--icon" aria-hidden="true"><img src="' . esc_url( LIFEBOAT_URL . 'assets/img/icon-lifeboat.png' ) . '" alt="" width="48" height="48"></span><div><h1>Lifeboat Backup</h1><p class="lb-brand__tag">' . esc_html__( 'Backup · Restore · Protect', 'lifeboat-backup' ) . '</p></div></div>'; // phpcs:ignore -- fixed SVG.
		echo '<div class="lb-brand__side">';
		self::header_status();
		self::credit();
		echo '</div></header><nav class="lb-tabs" aria-label="' . esc_attr__( 'Lifeboat sections', 'lifeboat-backup' ) . '">';
		foreach ( $tabs as $k => $label ) {
			printf( '<a href="%s" class="%s"%s>%s</a>', esc_url( self::url( $k ) ), $k === $tab ? 'is-active' : '', $k === $tab ? ' aria-current="page"' : '', esc_html( $label ) );
		}
		echo '</nav><hr class="wp-header-end">';
		include LIFEBOAT_DIR . 'views/' . $tab . '.php';
		echo '</div>';
	}

	/** Compact job state for the browser. */
	public static function job_summary( $job ) {
		if ( ! is_array( $job ) ) {
			return null;
		}
		return array(
			'id'       => $job['id'],
			'type'     => $job['type'],
			'status'   => $job['status'],
			'step'     => $job['step'],
			'mode'     => $job['mode'],
			'message'  => $job['message'],
			'percent'  => (int) $job['percent'],
			'started'  => $job['started'],
			'warnings' => array_slice( array_values( array_unique( isset( $job['warnings'] ) ? (array) $job['warnings'] : array() ) ), -5 ),
			'retry_at' => isset( $job['retry_at'] ) ? (int) $job['retry_at'] : 0,
			'finished' => isset( $job['finished'] ) ? (int) $job['finished'] : 0,
			'result'   => self::job_result( $job ),
		);
	}

	/** Where a finished backup is stored, what failed, size and duration — for the success card. */
	public static function job_result( array $job ) {
		if ( 'backup' !== $job['type'] || ! in_array( $job['status'], array( 'done', 'failed' ), true ) ) {
			return null;
		}
		$stored = array();
		if ( 'done' === $job['status'] && Lifeboat_History::has_local( $job['id'] ) ) {
			$stored[] = __( 'This server', 'lifeboat-backup' );
		}
		foreach ( array_keys( isset( $job['remote'] ) ? (array) $job['remote'] : array() ) as $d ) {
			$dest = Lifeboat_Destination::get( $d );
			if ( $dest ) {
				$stored[] = $dest->label();
			}
		}
		$failed = array();
		foreach ( isset( $job['failed_dest'] ) ? (array) $job['failed_dest'] : array() as $d => $msg ) {
			$dest     = Lifeboat_Destination::get( $d );
			$failed[] = array( 'label' => $dest ? $dest->label() : $d, 'message' => $msg );
		}
		$size = 0;
		foreach ( isset( $job['parts'] ) ? (array) $job['parts'] : array() as $part ) {
			$size += isset( $part['size'] ) ? (int) $part['size'] : 0;
		}
		$end = isset( $job['finished'] ) ? (int) $job['finished'] : (int) $job['updated'];
		return array(
			'stored'     => $stored,
			'failed'     => $failed,
			'size'       => $size ? Lifeboat_Util::bytes( $size ) : '',
			'duration'   => human_time_diff( $job['started'], max( $job['started'] + 1, $end ) ),
			'components' => array_map( array( 'Lifeboat_Backup', 'label' ), (array) $job['components'] ),
			'verified'   => 'done' === $job['status'],
			'encrypted'  => ! empty( $job['vault'] ),
			'when'       => wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $end ),
		);
	}

	public static function trigger_label( $t ) {
		$l = array(
			'manual'      => __( 'Manual', 'lifeboat-backup' ),
			'schedule'    => __( 'Scheduled', 'lifeboat-backup' ),
			'schedule-db' => __( 'Scheduled DB', 'lifeboat-backup' ),
			'update'      => __( 'After update', 'lifeboat-backup' ),
		);
		return isset( $l[ $t ] ) ? $l[ $t ] : $t;
	}

	/* =============================================================== guards */

	/** Put a plugin or theme back to the version saved before an update (also linked from the Plugins screen). */
	public static function post_rollback() {
		$id = isset( $_REQUEST['snapshot'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['snapshot'] ) ) : ''; // phpcs:ignore -- nonce checked next.
		check_admin_referer( 'lifeboat_rollback_' . $id );
		$r = Lifeboat_Snapshots::roll_back( $id );
		if ( is_wp_error( $r ) ) {
			self::back( 'backups', $r->get_error_message(), true );
		}
		$msg = sprintf( /* translators: 1: plugin or theme, 2: version */ __( '%1$s is back to version %2$s. The version it replaced was saved, so you can undo this below.', 'lifeboat-backup' ), $r[0], $r[1] );
		$ref = wp_get_referer();
		if ( $ref && false !== strpos( $ref, 'plugins.php' ) ) {
			set_transient( 'lifeboat_msg_' . get_current_user_id(), array( 'msg' => $msg, 'error' => false ), 60 );
			wp_safe_redirect( self::url( 'backups', array( 'lb_msg' => 1 ) ) . '#lb-snapshots' );
			exit;
		}
		self::back( 'backups', $msg );
	}

	public static function post_snapshot_delete() {
		$id = isset( $_POST['snapshot'] ) ? sanitize_text_field( wp_unslash( $_POST['snapshot'] ) ) : ''; // phpcs:ignore
		self::guard_post( 'lifeboat_snapshot_delete_' . $id );
		Lifeboat_Snapshots::delete( $id );
		self::back( 'backups', __( 'Snapshot deleted.', 'lifeboat-backup' ) );
	}

	/** Turn encryption on, change its password, re-enter it on a moved site, or turn it off. */
	public static function post_encryption() {
		self::guard_post( 'lifeboat_encryption' );
		$do   = isset( $_POST['do'] ) ? sanitize_key( $_POST['do'] ) : '';
		$pass = isset( $_POST['password'] ) ? (string) wp_unslash( $_POST['password'] ) : ''; // phpcs:ignore -- passwords are not sanitized.
		$conf = isset( $_POST['confirm'] ) ? (string) wp_unslash( $_POST['confirm'] ) : ''; // phpcs:ignore
		if ( ! Lifeboat_Vault::available() ) {
			self::back( 'schedule', __( 'This server lacks OpenSSL AES-256-GCM support, so backups cannot be encrypted here.', 'lifeboat-backup' ), true );
		}
		switch ( $do ) {
			case 'off':
				if ( ! Lifeboat_Vault::is_on() ) {
					break;
				}
				if ( ! Lifeboat_Vault::check_password( $pass ) ) {
					self::back( 'schedule', __( 'Enter the current encryption password to turn encryption off.', 'lifeboat-backup' ), true );
				}
				Lifeboat_Vault::turn_off();
				self::back( 'schedule', __( 'Encryption turned off. New backups will not be encrypted; existing encrypted backups still need their password to restore.', 'lifeboat-backup' ) );
				break;
			case 'unlock':
				if ( ! Lifeboat_Vault::unlock( $pass ) ) {
					self::back( 'schedule', __( 'That is not the current encryption password.', 'lifeboat-backup' ), true );
				}
				self::back( 'schedule', __( 'Encryption password accepted. Backups will run again.', 'lifeboat-backup' ) );
				break;
			case 'on':
			case 'change':
				if ( 'change' === $do && Lifeboat_Vault::is_on() ) {
					$current = isset( $_POST['current'] ) ? (string) wp_unslash( $_POST['current'] ) : ''; // phpcs:ignore
					if ( ! Lifeboat_Vault::check_password( $current ) ) {
						self::back( 'schedule', __( 'The current encryption password is not correct.', 'lifeboat-backup' ), true );
					}
				}
				if ( strlen( $pass ) < Lifeboat_Vault::MIN_PASS ) {
					self::back( 'schedule', sprintf( __( 'Use a password of at least %d characters.', 'lifeboat-backup' ), Lifeboat_Vault::MIN_PASS ), true );
				}
				if ( $pass !== $conf ) {
					self::back( 'schedule', __( 'The two passwords do not match.', 'lifeboat-backup' ), true );
				}
				if ( empty( $_POST['understand'] ) ) {
					self::back( 'schedule', __( 'Please confirm that you have saved the password somewhere safe.', 'lifeboat-backup' ), true );
				}
				Lifeboat_Vault::set_password( $pass );
				self::back( 'schedule', 'change' === $do ? __( 'Encryption password changed. New backups use the new password; older backups keep the password they were made with.', 'lifeboat-backup' ) : __( 'Encryption is on. Every new backup will be encrypted before it leaves this server.', 'lifeboat-backup' ) );
				break;
		}
		self::back( 'schedule', __( 'Nothing changed.', 'lifeboat-backup' ) );
	}

	private static function guard_post( $action ) {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Not allowed.', 'lifeboat-backup' ), 403 );
		}
		check_admin_referer( $action );
	}

	private static function back( $tab, $msg, $error = false ) {
		// The message travels in a short-lived per-user transient, so a crafted link can't put text on the screen.
		set_transient( 'lifeboat_msg_' . get_current_user_id(), array( 'msg' => $msg, 'error' => (bool) $error ), 60 );
		wp_safe_redirect( self::url( $tab, array( 'lb_msg' => 1 ) ) );
		exit;
	}

	private static function guard_ajax() {
		check_ajax_referer( 'lifeboat', 'nonce' );
		if ( ! current_user_can( self::CAP ) ) {
			wp_send_json_error( array( 'message' => __( 'Not allowed.', 'lifeboat-backup' ) ), 403 );
		}
	}

	/* ================================================================ forms */

	public static function post_save_settings() {
		self::guard_post( 'lifeboat_save_settings' );
		$in   = isset( $_POST['lb'] ) && is_array( $_POST['lb'] ) ? wp_unslash( $_POST['lb'] ) : array(); // phpcs:ignore
		$vals = array();
		foreach ( array( 'schedule', 'schedule_hour', 'schedule_weekday', 'schedule_monthday', 'db_schedule', 'after_update_scope', 'mode_scheduled', 'mode_manual', 'keep_local', 'keep_remote', 'split_mb', 'max_file_mb', 'exclude', 'notify', 'notify_email', 'relay_url' ) as $k ) {
			if ( isset( $in[ $k ] ) ) {
				$vals[ $k ] = $in[ $k ];
			}
		}
		foreach ( array( 'after_update', 'pre_update_snapshot', 'delete_on_uninstall' ) as $k ) {
			$vals[ $k ] = ! empty( $in[ $k ] );
		}
		$vals['components'] = isset( $in['components'] ) ? (array) $in['components'] : array();
		if ( ! $vals['components'] ) {
			self::back( 'schedule', __( 'Choose at least one thing to back up.', 'lifeboat-backup' ), true );
		}
		Lifeboat_Settings::update( $vals );
		self::back( 'schedule', __( 'Settings saved. Schedules updated.', 'lifeboat-backup' ) );
	}

	private static function dest_from_request() {
		$id   = isset( $_REQUEST['dest'] ) ? sanitize_key( $_REQUEST['dest'] ) : ''; // phpcs:ignore
		$dest = Lifeboat_Destination::get( $id );
		if ( ! $dest ) {
			wp_die( 'Unknown destination' );
		}
		return $dest;
	}

	private static function set_enabled( $id, $on ) {
		$list = (array) Lifeboat_Settings::get( 'destinations' );
		$list = $on ? array_unique( array_merge( $list, array( $id ) ) ) : array_diff( $list, array( $id ) );
		Lifeboat_Settings::update( array( 'destinations' => array_values( $list ) ) );
	}

	public static function post_save_dest() {
		$dest = self::dest_from_request();
		self::guard_post( 'lifeboat_dest_' . $dest->id );
		if ( isset( $_POST['client_id'] ) ) {
			$cid = sanitize_text_field( wp_unslash( $_POST['client_id'] ) );
			if ( $cid !== $dest->get_value( 'client_id' ) ) {
				$dest->disconnect(); // A different app invalidates existing tokens.
			}
			$dest->set( array( 'client_id' => $cid ) );
		}
		if ( ! empty( $_POST['client_secret'] ) ) {
			$dest->set_secret( 'client_secret', trim( wp_unslash( $_POST['client_secret'] ) ) ); // phpcs:ignore
		}
		if ( isset( $_POST['enabled_present'] ) ) {
			self::set_enabled( $dest->id, ! empty( $_POST['enabled'] ) );
		}
		self::back( 'storage', sprintf( __( '%s settings saved.', 'lifeboat-backup' ), $dest->label() ) );
	}

	public static function post_connect() {
		$dest = self::dest_from_request();
		self::guard_post( 'lifeboat_connect_' . $dest->id );
		if ( ! $dest instanceof Lifeboat_OAuth_Destination ) {
			self::back( 'storage', __( 'Unknown storage.', 'lifeboat-backup' ), true );
		}
		$own = ! empty( $_POST['own_app'] ) || ! $dest->relay_available(); // phpcs:ignore WordPress.Security.NonceVerification
		if ( $own && ! $dest->has_app() ) {
			self::back( 'storage', __( 'Enter and save the client ID and secret first.', 'lifeboat-backup' ), true );
		}
		wp_redirect( $own ? $dest->auth_url() : $dest->relay_auth_url() ); // phpcs:ignore WordPress.Security.SafeRedirect -- external provider / relay.
		exit;
	}

	public static function oauth_callback() {
		$state = isset( $_GET['state'] ) ? sanitize_text_field( wp_unslash( $_GET['state'] ) ) : ''; // phpcs:ignore
		if ( 0 !== strpos( $state, 'lifeboat.' ) ) {
			return; // Not ours.
		}
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Not allowed.', 'lifeboat-backup' ), 403 );
		}
		list( , $id, $nonce ) = array_pad( explode( '.', $state, 3 ), 3, '' );
		$dest = Lifeboat_Destination::get( $id );
		if ( ! $dest instanceof Lifeboat_OAuth_Destination || ! wp_verify_nonce( $nonce, 'lifeboat_oauth_' . $id ) ) {
			self::back( 'storage', __( 'The authorization link expired. Please click Connect again.', 'lifeboat-backup' ), true );
		}
		if ( ! empty( $_GET['error'] ) || ! empty( $_GET['relay_error'] ) ) { // phpcs:ignore
			$raw  = ! empty( $_GET['relay_error'] ) ? $_GET['relay_error'] : ( isset( $_GET['error_description'] ) ? $_GET['error_description'] : $_GET['error'] ); // phpcs:ignore
			$desc = sanitize_text_field( wp_unslash( $raw ) );
			if ( 'access_denied' === $desc ) {
				$desc = __( 'access was not allowed, so nothing was connected.', 'lifeboat-backup' );
			}
			self::back( 'storage', $dest->label() . ': ' . $desc, true );
		}
		try {
			if ( isset( $_GET['lifeboat_relay'] ) ) { // phpcs:ignore
				$dest->handle_relay_callback( isset( $_GET['blob'] ) ? sanitize_text_field( wp_unslash( $_GET['blob'] ) ) : '' ); // phpcs:ignore
			} else {
				$dest->handle_callback( isset( $_GET['code'] ) ? sanitize_text_field( wp_unslash( $_GET['code'] ) ) : '' ); // phpcs:ignore
			}
			self::set_enabled( $dest->id, true );
			$who = '';
			try {
				$who = $dest->test();
			} catch ( Exception $e ) {
				$who = '';
			}
			$name = $dest->label() . ( $who ? ' (' . $who . ')' : '' );
			self::back( 'storage', sprintf( __( 'Connected to %s. New backups will be uploaded there.', 'lifeboat-backup' ), $name ) );
		} catch ( Exception $e ) {
			self::back( 'storage', $e->getMessage(), true );
		}
	}

	public static function post_disconnect() {
		$dest = self::dest_from_request();
		self::guard_post( 'lifeboat_disconnect_' . $dest->id );
		$keep = $dest instanceof Lifeboat_OAuth_Destination ? array( 'client_id' => $dest->get_value( 'client_id' ), 'client_secret' => $dest->get_value( 'client_secret' ) ) : array();
		$dest->disconnect();
		if ( $keep ) {
			$dest->set( $keep );
		}
		self::set_enabled( $dest->id, false );
		self::back( 'storage', sprintf( __( 'Disconnected from %s. Backups already uploaded stay there.', 'lifeboat-backup' ), $dest->label() ) );
	}

	public static function post_mega_connect() {
		self::guard_post( 'lifeboat_connect_mega' );
		$mega  = Lifeboat_Destination::get( 'mega' );
		$email = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
		$pass  = isset( $_POST['password'] ) ? (string) wp_unslash( $_POST['password'] ) : ''; // phpcs:ignore -- passwords are not sanitized.
		$mfa   = isset( $_POST['mfa'] ) ? sanitize_text_field( wp_unslash( $_POST['mfa'] ) ) : '';
		if ( ! $email || '' === $pass ) {
			self::back( 'storage', __( 'Enter your MEGA email and password.', 'lifeboat-backup' ), true );
		}
		try {
			$mega->connect( $email, $pass, $mfa );
			self::set_enabled( 'mega', true );
			self::back( 'storage', sprintf( __( 'Connected to MEGA (%s). Your password was not stored.', 'lifeboat-backup' ), $mega->test() ) );
		} catch ( Exception $e ) {
			self::back( 'storage', $e->getMessage(), true );
		}
	}

	/** Stream a local backup file to the browser. */
	public static function post_download() {
		self::guard_post( 'lifeboat_download' );
		$id   = isset( $_GET['backup'] ) ? sanitize_file_name( wp_unslash( $_GET['backup'] ) ) : '';
		$file = isset( $_GET['file'] ) ? sanitize_file_name( wp_unslash( $_GET['file'] ) ) : '';
		$e    = Lifeboat_History::get( $id );
		$path = Lifeboat_History::local_dir( $id ) . '/' . $file;
		if ( ! $e || ( ! isset( $e['files'][ $file ] ) && 'manifest.json' !== $file ) || ! is_file( $path ) ) {
			wp_die( esc_html__( 'File not found on this server.', 'lifeboat-backup' ), 404 );
		}
		while ( ob_get_level() ) {
			ob_end_clean();
		}
		nocache_headers();
		header( 'Content-Type: application/octet-stream' );
		header( 'Content-Disposition: attachment; filename="' . Lifeboat_Util::site_slug() . '-' . $id . '-' . $file . '"' );
		header( 'Content-Length: ' . filesize( $path ) );
		readfile( $path ); // phpcs:ignore
		exit;
	}

	/* ================================================================= AJAX */

	public static function ajax_status() {
		self::guard_ajax();
		$job = Lifeboat_Runner::job();
		// Keep jobs moving on hosts that block loopback requests: the open admin page drives them.
		if ( Lifeboat_Runner::is_active( $job ) && $job['updated'] < time() - 12 && ( empty( $job['retry_at'] ) || $job['retry_at'] <= time() ) ) {
			$job = Lifeboat_Runner::run( min( 15, Lifeboat_Runner::BUDGET[ $job['mode'] ] ) );
		}
		wp_send_json_success( self::job_summary( $job ) );
	}

	public static function ajax_backup_now() {
		self::guard_ajax();
		$comps = isset( $_POST['components'] ) ? array_map( 'sanitize_key', (array) $_POST['components'] ) : array();
		$dests = isset( $_POST['destinations'] ) ? array_map( 'sanitize_key', (array) $_POST['destinations'] ) : array();
		$mode  = isset( $_POST['mode'] ) && 'easy' === $_POST['mode'] ? 'easy' : 'rush';
		if ( ! $comps ) {
			wp_send_json_error( array( 'message' => __( 'Choose at least one thing to back up.', 'lifeboat-backup' ) ) );
		}
		$job = Lifeboat_Runner::start( Lifeboat_Backup::create_job( 'manual', $mode, $comps, $dests ) );
		if ( is_wp_error( $job ) ) {
			wp_send_json_error( array( 'message' => $job->get_error_message() ) );
		}
		wp_send_json_success( self::job_summary( $job ) );
	}

	public static function ajax_cancel() {
		self::guard_ajax();
		Lifeboat_Runner::cancel();
		wp_send_json_success( self::job_summary( Lifeboat_Runner::job() ) );
	}

	public static function ajax_restore() {
		self::guard_ajax();
		$id     = isset( $_POST['backup'] ) ? sanitize_file_name( wp_unslash( $_POST['backup'] ) ) : '';
		$comps  = isset( $_POST['components'] ) ? array_map( 'sanitize_key', (array) $_POST['components'] ) : array();
		$source = isset( $_POST['source'] ) ? sanitize_key( $_POST['source'] ) : 'local';
		$keep   = ! empty( $_POST['keep_url'] );
		$entry  = Lifeboat_History::get( $id );
		if ( ! $entry || ! $comps ) {
			wp_send_json_error( array( 'message' => __( 'Choose a backup and at least one part.', 'lifeboat-backup' ) ) );
		}
		$key = '';
		if ( ! empty( $entry['encryption'] ) ) {
			if ( ! Lifeboat_Vault::available() ) {
				wp_send_json_error( array( 'message' => __( 'This backup is encrypted, but this server lacks the OpenSSL support needed to decrypt it.', 'lifeboat-backup' ) ) );
			}
			$pass = isset( $_POST['password'] ) ? (string) wp_unslash( $_POST['password'] ) : ''; // phpcs:ignore -- passwords are not sanitized.
			$key  = Lifeboat_Vault::key_for( (array) $entry['encryption'], $pass );
			if ( '' === $key ) {
				wp_send_json_error(
					array(
						'message'  => '' === $pass ? __( 'This backup is encrypted. Enter its encryption password.', 'lifeboat-backup' ) : __( 'That password does not unlock this backup. It must be the encryption password that was set when the backup was made.', 'lifeboat-backup' ),
						'password' => true,
					)
				);
			}
		}
		$job = Lifeboat_Runner::start( Lifeboat_Restore::create_job( $id, $comps, $source, $keep, $key ) );
		if ( is_wp_error( $job ) ) {
			wp_send_json_error( array( 'message' => $job->get_error_message() ) );
		}
		wp_send_json_success( self::job_summary( $job ) );
	}

	public static function ajax_delete() {
		self::guard_ajax();
		$id = isset( $_POST['backup'] ) ? sanitize_file_name( wp_unslash( $_POST['backup'] ) ) : '';
		$e  = Lifeboat_History::get( $id );
		if ( ! $e ) {
			wp_send_json_error( array( 'message' => __( 'Backup not found.', 'lifeboat-backup' ) ) );
		}
		$job = Lifeboat_Runner::job();
		if ( Lifeboat_Runner::is_active( $job ) && ( $id === $job['id'] || ( isset( $job['backup'] ) && $id === $job['backup'] ) ) ) {
			wp_send_json_error( array( 'message' => __( 'This backup is in use by the running job.', 'lifeboat-backup' ) ) );
		}
		$errors = array();
		foreach ( (array) $e['remote'] as $d => $files ) {
			$dest = Lifeboat_Destination::get( $d );
			if ( ! $dest || ! $dest->is_connected() ) {
				continue;
			}
			foreach ( $files as $rid ) {
				try {
					$dest->delete( $rid );
				} catch ( Exception $ex ) {
					$errors[] = $ex->getMessage();
				}
			}
		}
		Lifeboat_Util::rrmdir( Lifeboat_History::local_dir( $id ) );
		Lifeboat_History::remove( $id );
		wp_send_json_success( array( 'message' => $errors ? implode( ' ', array_unique( $errors ) ) : __( 'Deleted.', 'lifeboat-backup' ) ) );
	}

	public static function ajax_test() {
		self::guard_ajax();
		$dest = Lifeboat_Destination::get( isset( $_POST['dest'] ) ? sanitize_key( $_POST['dest'] ) : '' );
		if ( ! $dest || ! $dest->is_connected() ) {
			wp_send_json_error( array( 'message' => __( 'Not connected.', 'lifeboat-backup' ) ) );
		}
		try {
			wp_send_json_success( array( 'message' => sprintf( __( 'Working: %s', 'lifeboat-backup' ), $dest->test() ) ) );
		} catch ( Exception $e ) {
			wp_send_json_error( array( 'message' => $e->getMessage() ) );
		}
	}

	/**
	 * Findings for the Prasat Digital one-click site check (Prasat_Hub::run_check).
	 */
	public static function health() {
		$out  = array();
		$page = admin_url( 'admin.php?page=lifeboat' );
		list( $status, $title, $detail ) = self::last_backup_info();
		$last = get_option( 'lifeboat_last_backup' );
		$old  = 'ok' === $status && ! empty( $last['time'] ) && $last['time'] < time() - 8 * DAY_IN_SECONDS;
		$out[] = array(
			'id'        => 'backup_recent',
			'status'    => 'ok' === $status && ! $old ? 'pass' : ( 'partial' === $status || $old ? 'warn' : 'fail' ),
			'severity'  => 'none' === $status || 'failed' === $status ? 'critical' : 'high',
			'title'     => __( 'A recent backup exists', 'lifeboat-backup' ),
			'detail'    => $old ? sprintf( __( 'The last good backup is %s old. Run one now and check the schedule.', 'lifeboat-backup' ), human_time_diff( $last['time'] ) ) : trim( $title . ( $detail ? '. ' . $detail : '' ) ),
			'fix_url'   => $page,
			'fix_label' => __( 'Back up now', 'lifeboat-backup' ),
		);
		if ( Lifeboat_Vault::needs_password() ) {
			$out[] = array( 'id' => 'backup_password', 'status' => 'fail', 'severity' => 'critical', 'title' => __( 'Backups are not paused', 'lifeboat-backup' ), 'detail' => __( 'Backups are paused until the encryption password is entered on this site.', 'lifeboat-backup' ), 'fix_url' => $page );
		}
		$connected = array();
		foreach ( Lifeboat_Destination::registry() as $id => $d ) {
			if ( $d->is_connected() && in_array( $id, (array) Lifeboat_Settings::get( 'destinations' ), true ) ) {
				$connected[] = $d->label();
			}
		}
		$out[] = array( 'id' => 'backup_offsite', 'status' => $connected ? 'pass' : 'fail', 'severity' => 'high', 'title' => __( 'Backups are stored off the server', 'lifeboat-backup' ), 'detail' => $connected ? sprintf( __( 'Copies go to %s.', 'lifeboat-backup' ), implode( ', ', $connected ) ) : __( 'Backups stay on this server only, so they are lost if the server is. Connect Google Drive or another cloud storage.', 'lifeboat-backup' ), 'fix_url' => admin_url( 'admin.php?page=lifeboat&tab=storage' ), 'fix_label' => __( 'Connect storage', 'lifeboat-backup' ) );
		$scheduled = (bool) wp_next_scheduled( Lifeboat_Scheduler::FULL ) || (bool) wp_next_scheduled( Lifeboat_Scheduler::DB );
		$out[]     = array( 'id' => 'backup_schedule', 'status' => $scheduled ? 'pass' : 'warn', 'severity' => 'high', 'title' => __( 'Backups run automatically', 'lifeboat-backup' ), 'detail' => $scheduled ? __( 'Automatic backups are scheduled.', 'lifeboat-backup' ) : __( 'No automatic backup is scheduled, so backups only happen when someone remembers.', 'lifeboat-backup' ), 'fix_url' => admin_url( 'admin.php?page=lifeboat&tab=schedule' ), 'fix_label' => __( 'Set a schedule', 'lifeboat-backup' ) );
		if ( Lifeboat_Runner::loopback_blocked() ) {
			$out[] = array( 'id' => 'backup_loopback', 'status' => 'warn', 'severity' => 'medium', 'title' => __( 'Backups can run at full speed', 'lifeboat-backup' ), 'detail' => __( 'A firewall or bot check (such as Cloudflare\'s) blocks the site\'s requests to itself, so long backups run slower. Allow the server\'s own requests to wp-admin/admin-ajax.php.', 'lifeboat-backup' ), 'fix_url' => $page );
		}
		return $out;
	}

	/** Numbers for the Prasat Digital dashboard (Prasat_Hub::render). */
	public static function dashboard() {
		list( $status, , , $ago ) = self::last_backup_info();
		$last  = get_option( 'lifeboat_last_backup' );
		$where = ! empty( $last['where'] ) ? implode( ', ', (array) $last['where'] ) : __( 'this server', 'lifeboat-backup' );
		$next  = wp_next_scheduled( Lifeboat_Scheduler::DB );
		$full  = wp_next_scheduled( Lifeboat_Scheduler::FULL );
		$next  = $next && $full ? min( $next, $full ) : ( $next ? $next : $full );
		$out   = array( 'tiles' => array(), 'activity' => array(), 'charts' => array(), 'jobs' => array() );
		$out['tiles'][] = array(
			'area'  => __( 'Backups', 'lifeboat-backup' ),
			'value' => 'none' === $status ? __( 'None', 'lifeboat-backup' ) : $ago,
			'label' => 'none' === $status ? __( 'no backup yet', 'lifeboat-backup' ) : ( 'failed' === $status ? __( 'since a failed backup', 'lifeboat-backup' ) : __( 'since last backup', 'lifeboat-backup' ) ),
			'sub'   => ( 'none' === $status ? '' : $where ) . ( $next ? ( 'none' === $status ? '' : ' · ' ) . sprintf( __( 'next %s', 'lifeboat-backup' ), wp_date( 'D H:i', $next ) ) : '' ),
			'tone'  => Lifeboat_Vault::needs_password() || in_array( $status, array( 'failed', 'none' ), true ) ? 'bad' : ( 'partial' === $status ? 'warn' : 'ok' ),
			'url'   => self::url(),
		);
		if ( ! empty( $last['time'] ) ) {
			$out['activity'][] = array(
				'time' => (int) $last['time'],
				'text' => 'ok' === $status ? sprintf( __( 'Backup finished · stored on %s', 'lifeboat-backup' ), $where ) : ( 'failed' === $status ? __( 'Backup failed', 'lifeboat-backup' ) : __( 'Backup reached only some places', 'lifeboat-backup' ) ),
				'tone' => 'ok' === $status ? 'ok' : 'bad',
				'url'  => self::url(),
			);
		}
		$job = Lifeboat_Runner::job();
		if ( Lifeboat_Runner::is_active( $job ) ) {
			$out['jobs'][] = array(
				'title'   => 'restore' === $job['type'] ? __( 'Restore running', 'lifeboat-backup' ) : __( 'Backup running', 'lifeboat-backup' ),
				'percent' => isset( $job['percent'] ) ? (int) $job['percent'] : 0,
				'text'    => isset( $job['message'] ) ? $job['message'] : '',
				'url'     => self::url(),
			);
		}
		return $out;
	}
}
