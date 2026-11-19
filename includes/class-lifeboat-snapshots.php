<?php
/**
 * Update snapshots: just before WordPress replaces a plugin or theme during an update (manual,
 * bulk or automatic), Lifeboat zips the version that is installed now. If the new version
 * breaks something, one click puts the previous version back.
 *
 * Rolling back first snapshots the version being replaced, so a rollback can be undone too.
 * Snapshots cover the plugin's or theme's files; the database is not changed.
 *
 * @package Lifeboat_Backup
 */

defined( 'ABSPATH' ) || exit;

class Lifeboat_Snapshots {

	const OPTION     = 'lifeboat_snapshots';
	const MAX_BYTES  = 209715200; // Folders over 200 MB are skipped (and noted).
	const KEEP_EACH  = 3;
	const KEEP_TOTAL = 40;

	public static function init() {
		add_filter( 'upgrader_pre_install', array( __CLASS__, 'before_update' ), 5, 2 );
		add_filter( 'plugin_action_links', array( __CLASS__, 'plugin_links' ), 20, 2 );
	}

	public static function dir() {
		$dir = Lifeboat_Util::backup_dir() . '/snapshots';
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
			Lifeboat_Util::protect_dir( $dir );
		}
		return $dir;
	}

	/** @return array Snapshot records, newest first (only those whose zip still exists). */
	public static function all() {
		$list = get_option( self::OPTION, array() );
		$list = is_array( $list ) ? $list : array();
		$list = array_values(
			array_filter(
				$list,
				function ( $r ) {
					return ! empty( $r['zip'] ) && is_file( Lifeboat_Snapshots::dir() . '/' . $r['zip'] );
				}
			)
		);
		usort(
			$list,
			function ( $a, $b ) {
				return $b['time'] - $a['time'];
			}
		);
		return $list;
	}

	public static function get( $id ) {
		foreach ( self::all() as $r ) {
			if ( $r['id'] === $id ) {
				return $r;
			}
		}
		return null;
	}

	/* ============================================================ taking */

	/** What is installed for an upgrader item: type, slug, folder, file, name, version. */
	public static function item( array $extra ) {
		if ( ! empty( $extra['plugin'] ) ) {
			$file = (string) $extra['plugin'];
			$slug = dirname( $file );
			if ( '.' === $slug || '' === $slug ) {
				return null; // Single-file plugins (hello.php) have no folder to snapshot.
			}
			if ( ! function_exists( 'get_plugin_data' ) ) {
				require_once ABSPATH . 'wp-admin/includes/plugin.php';
			}
			$path = WP_PLUGIN_DIR . '/' . $file;
			$data = is_file( $path ) ? get_plugin_data( $path, false, false ) : array();
			return array(
				'type'    => 'plugin',
				'slug'    => $slug,
				'file'    => $file,
				'folder'  => WP_PLUGIN_DIR . '/' . $slug,
				'name'    => ! empty( $data['Name'] ) ? $data['Name'] : $slug,
				'version' => ! empty( $data['Version'] ) ? $data['Version'] : '',
			);
		}
		if ( ! empty( $extra['theme'] ) ) {
			$slug  = (string) $extra['theme'];
			$theme = wp_get_theme( $slug );
			return array(
				'type'    => 'theme',
				'slug'    => $slug,
				'file'    => '',
				'folder'  => $theme->get_stylesheet_directory(),
				'name'    => $theme->exists() ? $theme->get( 'Name' ) : $slug,
				'version' => $theme->exists() ? $theme->get( 'Version' ) : '',
			);
		}
		return null;
	}

	/** upgrader_pre_install: runs once per plugin/theme, right before its files are replaced. */
	public static function before_update( $response, $extra ) {
		if ( is_wp_error( $response ) || ! Lifeboat_Settings::get( 'pre_update_snapshot' ) || ! is_array( $extra ) ) {
			return $response;
		}
		if ( ! empty( $extra['action'] ) && 'update' !== $extra['action'] ) {
			return $response; // Fresh installs have nothing to protect.
		}
		$item = self::item( $extra );
		if ( $item && is_dir( $item['folder'] ) ) {
			self::take( $item, 'update' );
		}
		return $response; // Never block an update, whatever happens here.
	}

	/** @return array|WP_Error The new record. */
	public static function take( array $item, $reason ) {
		$bytes = 0;
		$it    = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $item['folder'], FilesystemIterator::SKIP_DOTS ) );
		foreach ( $it as $f ) {
			if ( $f->isFile() ) {
				$bytes += $f->getSize();
				if ( $bytes > self::MAX_BYTES ) {
					return new WP_Error( 'lifeboat_snapshot_size', sprintf( __( '%s is larger than 200 MB, so no update snapshot was made. Your regular backups still include it.', 'lifeboat-backup' ), $item['name'] ) );
				}
			}
		}
		$id   = gmdate( 'Ymd-His' ) . '-' . strtolower( wp_generate_password( 4, false ) );
		$zip  = sanitize_file_name( $item['type'] . '-' . $item['slug'] . '-' . ( $item['version'] ? $item['version'] : 'unknown' ) . '-' . $id . '.zip' );
		$path = self::dir() . '/' . $zip;
		$ok   = self::zip_folder( $item['folder'], $item['slug'], $path );
		if ( is_wp_error( $ok ) ) {
			return $ok;
		}
		$record = array(
			'id'      => $id,
			'type'    => $item['type'],
			'slug'    => $item['slug'],
			'file'    => $item['file'],
			'name'    => $item['name'],
			'version' => $item['version'],
			'time'    => time(),
			'size'    => (int) filesize( $path ),
			'zip'     => $zip,
			'reason'  => $reason,
		);
		$list   = get_option( self::OPTION, array() );
		$list   = is_array( $list ) ? $list : array();
		$list[] = $record;
		update_option( self::OPTION, $list, false );
		self::prune();
		return $record;
	}

	/** Zip a folder so it unpacks as "<slug>/…". ZipArchive, or WordPress's bundled PclZip. */
	private static function zip_folder( $folder, $slug, $dest ) {
		$folder = untrailingslashit( wp_normalize_path( $folder ) );
		if ( class_exists( 'ZipArchive' ) ) {
			$z = new ZipArchive();
			if ( true !== $z->open( $dest, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
				return new WP_Error( 'lifeboat_zip', __( 'Could not create the snapshot file.', 'lifeboat-backup' ) );
			}
			$z->addEmptyDir( $slug );
			$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $folder, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::SELF_FIRST );
			foreach ( $it as $f ) {
				$rel = $slug . '/' . ltrim( substr( wp_normalize_path( $f->getPathname() ), strlen( $folder ) ), '/' );
				if ( $f->isLink() ) {
					continue;
				}
				if ( $f->isDir() ) {
					$z->addEmptyDir( $rel );
				} else {
					$z->addFile( $f->getPathname(), $rel );
				}
			}
			return $z->close() ? true : new WP_Error( 'lifeboat_zip', __( 'Could not write the snapshot file.', 'lifeboat-backup' ) );
		}
		require_once ABSPATH . 'wp-admin/includes/class-pclzip.php';
		$z = new PclZip( $dest );
		$r = $z->create( $folder, PCLZIP_OPT_REMOVE_PATH, dirname( $folder ) );
		return 0 === $r ? new WP_Error( 'lifeboat_zip', $z->errorInfo( true ) ) : true;
	}

	/** Keep the newest few per plugin/theme and a total cap. */
	private static function prune() {
		$list  = get_option( self::OPTION, array() );
		$list  = is_array( $list ) ? $list : array();
		usort(
			$list,
			function ( $a, $b ) {
				return $b['time'] - $a['time'];
			}
		);
		$seen = array();
		$keep = array();
		foreach ( $list as $r ) {
			$k          = $r['type'] . ':' . $r['slug'];
			$seen[ $k ] = isset( $seen[ $k ] ) ? $seen[ $k ] + 1 : 1;
			if ( $seen[ $k ] <= self::KEEP_EACH && count( $keep ) < self::KEEP_TOTAL ) {
				$keep[] = $r;
			} else {
				@unlink( self::dir() . '/' . $r['zip'] ); // phpcs:ignore
			}
		}
		update_option( self::OPTION, $keep, false );
	}

	public static function delete( $id ) {
		$list = get_option( self::OPTION, array() );
		$out  = array();
		foreach ( (array) $list as $r ) {
			if ( $r['id'] === $id ) {
				@unlink( self::dir() . '/' . $r['zip'] ); // phpcs:ignore
			} else {
				$out[] = $r;
			}
		}
		update_option( self::OPTION, $out, false );
	}

	/* ========================================================== rolling back */

	public static function installed_version( array $r ) {
		if ( 'plugin' === $r['type'] ) {
			if ( ! function_exists( 'get_plugin_data' ) ) {
				require_once ABSPATH . 'wp-admin/includes/plugin.php';
			}
			$path = WP_PLUGIN_DIR . '/' . $r['file'];
			if ( ! is_file( $path ) ) {
				return '';
			}
			$d = get_plugin_data( $path, false, false );
			return isset( $d['Version'] ) ? $d['Version'] : '';
		}
		$t = wp_get_theme( $r['slug'] );
		return $t->exists() ? $t->get( 'Version' ) : '';
	}

	public static function can_roll_back( array $r ) {
		return current_user_can( 'plugin' === $r['type'] ? 'update_plugins' : 'update_themes' );
	}

	/** @return array|WP_Error [name, version] on success. */
	public static function roll_back( $id ) {
		global $wp_filesystem;
		$r = self::get( $id );
		if ( ! $r ) {
			return new WP_Error( 'lifeboat_snapshot', __( 'Snapshot not found.', 'lifeboat-backup' ) );
		}
		if ( ! self::can_roll_back( $r ) ) {
			return new WP_Error( 'lifeboat_snapshot', __( 'You are not allowed to change this plugin or theme.', 'lifeboat-backup' ) );
		}
		$target = 'plugin' === $r['type'] ? WP_PLUGIN_DIR . '/' . $r['slug'] : get_theme_root( $r['slug'] ) . '/' . $r['slug'];
		$target = untrailingslashit( wp_normalize_path( $target ) );

		// Make the rollback undoable: snapshot what is there now.
		if ( is_dir( $target ) ) {
			$now = self::item( 'plugin' === $r['type'] ? array( 'plugin' => $r['file'] ) : array( 'theme' => $r['slug'] ) );
			if ( $now ) {
				self::take( $now, 'rollback' );
			}
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		if ( ! WP_Filesystem() || ! $wp_filesystem ) {
			return new WP_Error( 'lifeboat_fs', __( 'WordPress cannot write to the plugins folder on this server.', 'lifeboat-backup' ) );
		}
		$tmp = WP_CONTENT_DIR . '/upgrade/lifeboat-rollback-' . strtolower( wp_generate_password( 8, false ) );
		wp_mkdir_p( $tmp );
		$unzip = unzip_file( self::dir() . '/' . $r['zip'], $tmp );
		$fresh = $tmp . '/' . $r['slug'];
		if ( is_wp_error( $unzip ) || ! is_dir( $fresh ) ) {
			$wp_filesystem->delete( $tmp, true );
			return is_wp_error( $unzip ) ? $unzip : new WP_Error( 'lifeboat_snapshot', __( 'The snapshot file is damaged.', 'lifeboat-backup' ) );
		}

		// Swap: current → aside, snapshot → in place. Undo the first step if the second fails.
		$aside = $target . '.lifeboat-old-' . time();
		if ( is_dir( $target ) && ! @rename( $target, $aside ) ) { // phpcs:ignore
			$wp_filesystem->delete( $tmp, true );
			return new WP_Error( 'lifeboat_fs', __( 'Could not move the current version aside.', 'lifeboat-backup' ) );
		}
		if ( ! @rename( $fresh, $target ) ) { // phpcs:ignore
			if ( is_dir( $aside ) ) {
				@rename( $aside, $target ); // phpcs:ignore
			}
			$wp_filesystem->delete( $tmp, true );
			return new WP_Error( 'lifeboat_fs', __( 'Could not put the previous version in place. Nothing was changed.', 'lifeboat-backup' ) );
		}
		if ( is_dir( $aside ) ) {
			$wp_filesystem->delete( $aside, true );
		}
		$wp_filesystem->delete( $tmp, true );

		if ( function_exists( 'opcache_invalidate' ) ) {
			$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $target, FilesystemIterator::SKIP_DOTS ) );
			foreach ( $it as $f ) {
				if ( 'php' === strtolower( $f->getExtension() ) ) {
					@opcache_invalidate( $f->getPathname(), true ); // phpcs:ignore
				}
			}
		}
		if ( 'plugin' === $r['type'] ) {
			wp_clean_plugins_cache( true );
		} else {
			wp_clean_themes_cache( true );
			delete_site_transient( 'update_themes' );
		}
		// Pages cached by Slipstream may include the newer version's output.
		if ( class_exists( 'Slipstream_Cache' ) ) {
			Slipstream_Cache::purge_all_soon();
		}
		do_action( 'lifeboat_rolled_back', $r );
		return array( $r['name'], $r['version'] );
	}

	/** "Roll back to 1.2" on the Plugins screen when an older snapshot exists. */
	public static function plugin_links( $links, $file ) {
		if ( ! current_user_can( 'update_plugins' ) ) {
			return $links;
		}
		static $by_file = null;
		if ( null === $by_file ) {
			$by_file = array();
			foreach ( self::all() as $r ) {
				if ( 'plugin' === $r['type'] && ! isset( $by_file[ $r['file'] ] ) ) {
					$by_file[ $r['file'] ] = $r; // Newest first.
				}
			}
		}
		if ( empty( $by_file[ $file ] ) ) {
			return $links;
		}
		$r = $by_file[ $file ];
		if ( '' === $r['version'] || self::installed_version( $r ) === $r['version'] ) {
			return $links;
		}
		$url     = wp_nonce_url( admin_url( 'admin-post.php?action=lifeboat_rollback&snapshot=' . rawurlencode( $r['id'] ) ), 'lifeboat_rollback_' . $r['id'] );
		$confirm = esc_js( sprintf( __( 'Put %1$s %2$s back? The current version is saved first, so you can undo this.', 'lifeboat-backup' ), $r['name'], $r['version'] ) );
		$links['lifeboat_rollback'] = '<a href="' . esc_url( $url ) . '" onclick="return confirm(\'' . $confirm . '\')">' . esc_html( sprintf( __( 'Roll back to %s', 'lifeboat-backup' ), $r['version'] ) ) . '</a>';
		return $links;
	}
}
