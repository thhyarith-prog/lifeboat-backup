<?php
/**
 * Filesystem and formatting helpers.
 *
 * @package Lifeboat_Backup
 */

defined( 'ABSPATH' ) || exit;

class Lifeboat_Util {

	/**
	 * Backup folder. The random suffix keeps archives unguessable on servers that ignore .htaccess (Nginx).
	 */
	public static function backup_dir() {
		$key = get_option( 'lifeboat_dir_key' );
		if ( ! $key ) {
			$existing = glob( WP_CONTENT_DIR . '/lifeboat-backups-*', GLOB_ONLYDIR );
			$key      = $existing ? substr( basename( $existing[0] ), 17 ) : wp_generate_password( 12, false );
			update_option( 'lifeboat_dir_key', $key, false );
		}
		$dir = WP_CONTENT_DIR . '/lifeboat-backups-' . $key;
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}
		self::protect_dir( $dir );
		return $dir;
	}

	public static function protect_dir( $dir ) {
		$files = array(
			'.htaccess'  => "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nOrder allow,deny\nDeny from all\n</IfModule>\n",
			'index.php'  => "<?php // Silence is golden.\n",
			'index.html' => '',
			'web.config' => '<?xml version="1.0"?><configuration><system.webServer><authorization><deny users="*" /></authorization></system.webServer></configuration>',
		);
		foreach ( $files as $name => $content ) {
			if ( ! file_exists( $dir . '/' . $name ) ) {
				@file_put_contents( $dir . '/' . $name, $content ); // phpcs:ignore
			}
		}
	}

	public static function read_json( $file ) {
		if ( ! is_file( $file ) ) {
			return null;
		}
		$data = json_decode( (string) file_get_contents( $file ), true );
		return is_array( $data ) ? $data : null;
	}

	/** Atomic JSON write (temp file + rename) so a crash never leaves half a file. */
	public static function write_json( $file, $data ) {
		$tmp = $file . '.' . wp_generate_password( 6, false ) . '.tmp';
		if ( false === file_put_contents( $tmp, wp_json_encode( $data ), LOCK_EX ) ) { // phpcs:ignore
			return false;
		}
		return @rename( $tmp, $file ); // phpcs:ignore
	}

	public static function rrmdir( $dir ) {
		if ( ! is_dir( $dir ) ) {
			return;
		}
		$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
		foreach ( $it as $f ) {
			$f->isDir() && ! $f->isLink() ? @rmdir( $f->getPathname() ) : @unlink( $f->getPathname() ); // phpcs:ignore
		}
		@rmdir( $dir ); // phpcs:ignore
	}

	public static function bytes( $n ) {
		$n = (float) $n;
		foreach ( array( 'B', 'KB', 'MB', 'GB', 'TB' ) as $unit ) {
			if ( $n < 1024 || 'TB' === $unit ) {
				return ( 'B' === $unit ? (int) $n : number_format_i18n( $n, $n < 10 ? 1 : 0 ) ) . ' ' . $unit;
			}
			$n /= 1024;
		}
		return '';
	}

	public static function site_slug() {
		$host = wp_parse_url( home_url(), PHP_URL_HOST );
		$path = trim( (string) wp_parse_url( home_url(), PHP_URL_PATH ), '/' );
		return sanitize_title( $host . ( $path ? '-' . $path : '' ) );
	}

	/** Number of CPU cores, or 0 when it cannot be determined (then load is not used). */
	public static function cpu_count() {
		static $n = null;
		if ( null !== $n ) {
			return $n;
		}
		$n = 0;
		if ( @is_readable( '/proc/cpuinfo' ) ) { // phpcs:ignore
			$n = (int) preg_match_all( '/^processor\s*:/m', (string) @file_get_contents( '/proc/cpuinfo' ) ); // phpcs:ignore
		}
		if ( ! $n && function_exists( 'shell_exec' ) && ! in_array( 'shell_exec', array_map( 'trim', explode( ',', (string) ini_get( 'disable_functions' ) ) ), true ) ) {
			$n = (int) trim( (string) @shell_exec( 'nproc 2>/dev/null || sysctl -n hw.ncpu 2>/dev/null' ) ); // phpcs:ignore
		}
		$n = (int) apply_filters( 'lifeboat_cpu_count', max( 0, $n ) );
		return $n;
	}

	/** True when the server is busy enough that Easy mode should wait. */
	public static function server_busy() {
		if ( ! function_exists( 'sys_getloadavg' ) ) {
			return false;
		}
		$cores = self::cpu_count();
		if ( ! $cores ) {
			return false; // Unknown core count: a load number alone means nothing.
		}
		$load = @sys_getloadavg(); // phpcs:ignore
		return is_array( $load ) && $load[0] > $cores * 0.9;
	}
}
