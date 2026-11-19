<?php
/**
 * Settings (no translated strings here so it is safe to read before `init`).
 *
 * @package Lifeboat_Backup
 */

defined( 'ABSPATH' ) || exit;

class Lifeboat_Settings {

	const OPTION     = 'lifeboat_settings';
	const COMPONENTS = array( 'db', 'plugins', 'themes', 'uploads', 'others', 'core' );

	/** @var array|null */
	private static $cache;

	public static function defaults() {
		return array(
			'schedule'           => 'weekly',
			'schedule_hour'      => 3,
			'schedule_weekday'   => 0,
			'schedule_monthday'  => 1,
			'db_schedule'        => 'daily',
			'after_update'       => true,
			'pre_update_snapshot' => true,
			'after_update_scope' => 'quick',
			'components'         => array( 'db', 'plugins', 'themes', 'uploads', 'others' ),
			'mode_scheduled'     => 'easy',
			'mode_manual'        => 'rush',
			'destinations'       => array(),
			'keep_local'         => 2,
			'keep_remote'        => 10,
			'split_mb'           => 500,
			'max_file_mb'        => 0,
			'exclude'            => array( 'wp-content/cache', 'wp-content/upgrade', 'wp-content/updraft', 'wp-content/ai1wm-backups', 'wp-content/backups-dup-lite', 'wp-content/wpvividbackups', 'wp-content/backup-db', 'node_modules', '.git', '*.log', 'error_log' ),
			'notify'             => 'failures',
			'notify_email'       => '',
			'delete_on_uninstall' => false,
			'relay_url'          => '',
		);
	}

	public static function all() {
		if ( null === self::$cache ) {
			$saved       = get_option( self::OPTION, array() );
			self::$cache = array_merge( self::defaults(), is_array( $saved ) ? $saved : array() );
		}
		return self::$cache;
	}

	public static function get( $key ) {
		$all = self::all();
		return isset( $all[ $key ] ) ? $all[ $key ] : null;
	}

	public static function update( array $values ) {
		$all = self::all();
		foreach ( $values as $k => $v ) {
			if ( array_key_exists( $k, self::defaults() ) ) {
				$all[ $k ] = self::sanitize( $k, $v );
			}
		}
		update_option( self::OPTION, $all, true );
		self::$cache = $all;
		do_action( 'lifeboat_settings_updated', $all );
		return $all;
	}

	private static function sanitize( $key, $v ) {
		switch ( $key ) {
			case 'schedule':
				return in_array( $v, array( 'off', 'daily', 'weekly', 'monthly' ), true ) ? $v : 'weekly';
			case 'db_schedule':
				return in_array( $v, array( 'off', 'twicedaily', 'daily', 'weekly' ), true ) ? $v : 'off';
			case 'after_update_scope':
				return 'full' === $v ? 'full' : 'quick';
			case 'mode_scheduled':
			case 'mode_manual':
				return 'rush' === $v ? 'rush' : 'easy';
			case 'notify':
				return in_array( $v, array( 'never', 'failures', 'always' ), true ) ? $v : 'failures';
			case 'schedule_hour':
				return max( 0, min( 23, (int) $v ) );
			case 'schedule_weekday':
				return max( 0, min( 6, (int) $v ) );
			case 'schedule_monthday':
				return max( 1, min( 28, (int) $v ) );
			case 'keep_local':
				return max( 0, min( 100, (int) $v ) );
			case 'keep_remote':
				return max( 1, min( 1000, (int) $v ) );
			case 'split_mb':
				return max( 25, min( 4000, (int) $v ) );
			case 'max_file_mb':
				return max( 0, min( 100000, (int) $v ) );
			case 'components':
				return array_values( array_intersect( self::COMPONENTS, (array) $v ) );
			case 'destinations':
				return array_values( array_intersect( array_keys( Lifeboat_Destination::registry() ), (array) $v ) );
			case 'exclude':
				$lines = is_array( $v ) ? $v : preg_split( '/[\r\n]+/', (string) $v );
				return array_values( array_filter( array_map( 'trim', array_map( 'sanitize_text_field', $lines ) ) ) );
			case 'notify_email':
				return sanitize_email( (string) $v );
			case 'relay_url':
				$v    = esc_url_raw( trim( (string) $v ) );
				$host = (string) wp_parse_url( $v, PHP_URL_HOST );
				$ok   = 0 === strpos( $v, 'https://' ) || ( 0 === strpos( $v, 'http://' ) && preg_match( '/^(localhost|127\.0\.0\.1)$|\.(localhost|test)$/', $host ) );
				return $ok ? untrailingslashit( $v ) : '';
			default:
				return (bool) $v;
		}
	}
}
