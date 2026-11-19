<?php
/**
 * The backup job: database → files (per component, split into parts) → checksums and manifest →
 * uploads to each destination → retention → done. Every step is resumable.
 *
 * @package Lifeboat_Backup
 */

defined( 'ABSPATH' ) || exit;

class Lifeboat_Backup {

	const FILE_COMPONENTS = array( 'plugins', 'themes', 'uploads', 'others', 'core' );
	const READ            = 4194304;

	public static function create_job( $trigger, $mode, ?array $components = null, ?array $destinations = null ) {
		$components   = array_values( array_intersect( Lifeboat_Settings::COMPONENTS, null === $components ? (array) Lifeboat_Settings::get( 'components' ) : $components ) );
		$destinations = null === $destinations ? (array) Lifeboat_Settings::get( 'destinations' ) : $destinations;
		$destinations = array_values(
			array_filter(
				$destinations,
				function ( $d ) {
					$dest = Lifeboat_Destination::get( $d );
					return $dest && $dest->is_connected();
				}
			)
		);
		$vault = Lifeboat_Vault::params();
		if ( $vault ) {
			$key          = Lifeboat_Vault::site_key();
			$vault['key'] = $key ? Lifeboat_Crypto::encrypt( $key ) : '';
		}
		return array(
			'type'         => 'backup',
			'id'           => gmdate( 'Y-m-d-His' ) . '-' . strtolower( wp_generate_password( 6, false ) ),
			'trigger'      => $trigger,
			'mode'         => 'rush' === $mode ? 'rush' : 'easy',
			'components'   => $components,
			'destinations' => $destinations,
			'status'       => 'running',
			'step'         => 'prepare',
			'started'      => time(),
			'message'      => __( 'Preparing…', 'lifeboat-backup' ),
			'percent'      => 1,
			'parts'        => array(),
			'db'           => array(),
			'ci'           => 0,
			'scan'         => null,
			'upload'       => array( 'qi' => 0, 'session' => null, 'retries' => 0 ),
			'remote'       => array(),
			'warnings'     => array(),
			'vault'        => $vault,
		);
	}

	public static function dir( array $job ) {
		return Lifeboat_History::local_dir( $job['id'] );
	}

	public static function step( array &$job, $deadline ) {
		while ( 'running' === $job['status'] && microtime( true ) < $deadline ) {
			switch ( $job['step'] ) {
				case 'prepare':
					self::prepare( $job );
					break;
				case 'db':
					self::db( $job, $deadline );
					break;
				case 'files':
					self::files( $job, $deadline );
					break;
				case 'encrypt':
					self::encrypt( $job, $deadline );
					break;
				case 'manifest':
					self::manifest( $job, $deadline );
					break;
				case 'upload':
					self::upload( $job, $deadline );
					if ( ! empty( $job['retry_at'] ) ) {
						return; // Back off; the runner resumes later.
					}
					break;
				case 'retention':
					self::retention( $job );
					break;
				case 'done':
					self::finish( $job );
					break;
				default:
					throw new RuntimeException( 'Unknown step ' . $job['step'] );
			}
		}
	}

	private static function prepare( array &$job ) {
		$dir = self::dir( $job );
		if ( ! wp_mkdir_p( $dir ) ) {
			throw new RuntimeException( __( 'Cannot create the backup folder. Check that wp-content is writable.', 'lifeboat-backup' ) );
		}
		Lifeboat_Util::protect_dir( $dir );
		if ( ! empty( $job['vault'] ) && ( empty( $job['vault']['key'] ) || ! Lifeboat_Vault::available() ) ) {
			throw new RuntimeException( __( 'Backup encryption is on, but its password needs to be entered again on this site (Schedule & settings → Encryption). No unencrypted backup was made.', 'lifeboat-backup' ) );
		}
		$free = function_exists( 'disk_free_space' ) ? @disk_free_space( $dir ) : false; // phpcs:ignore
		if ( false !== $free && $free < 104857600 ) {
			throw new RuntimeException( sprintf( __( 'Only %s of disk space left. Free up space or turn off local copies.', 'lifeboat-backup' ), Lifeboat_Util::bytes( $free ) ) );
		}
		$job['step'] = in_array( 'db', $job['components'], true ) ? 'db' : 'files';
	}

	/* ============================================================ database */

	private static function db( array &$job, $deadline ) {
		if ( ! isset( $job['parts']['database.sql.gz'] ) ) {
			$job['parts']['database.sql.gz'] = array(
				'component' => 'db',
				'file'      => 'database.sql.gz',
			);
		}
		$part =& $job['parts']['database.sql.gz'];
		$ws   = self::writer_state( $job, $part );
		$w    = new Lifeboat_Archive_Writer( $ws, self::level( $job ) );
		try {
			$done = Lifeboat_DB::dump_step( $job['db'], $w, $deadline, 'easy' === $job['mode'] );
		} finally {
			$w->close();
			self::merge_writer( $part, $ws );
			unset( $part );
		}
		$t = count( $job['db']['tables'] );
		$job['message'] = sprintf( __( 'Database: table %1$d of %2$d (%3$s rows)', 'lifeboat-backup' ), min( $t, $job['db']['ti'] + 1 ), $t, number_format_i18n( $job['db']['rows'] ) );
		$job['percent'] = 2 + (int) ( 13 * ( $t ? $job['db']['ti'] / $t : 1 ) );
		if ( $done ) {
			$job['step'] = 'files';
		}
	}

	/** Writer state for a part: absolute path plus the gzip members written so far. */
	private static function writer_state( array $job, array $part ) {
		$ws = array( 'file' => self::dir( $job ) . '/' . $part['file'] );
		if ( ! empty( $part['members'] ) ) {
			$ws['members'] = $part['members'];
			$ws['raw']     = $part['raw'];
		}
		return $ws;
	}

	private static function merge_writer( array &$part, array $ws ) {
		$part['members'] = $ws['members'];
		$part['raw']     = $ws['raw'];
		$part['size']    = isset( $ws['size'] ) ? $ws['size'] : 0;
	}

	private static function level( array $job ) {
		return 'rush' === $job['mode'] ? 4 : 1;
	}

	/* =============================================================== files */

	public static function roots() {
		$up = wp_upload_dir( null, false );
		return array(
			'plugins' => wp_normalize_path( WP_PLUGIN_DIR ),
			'themes'  => wp_normalize_path( get_theme_root() ),
			'uploads' => wp_normalize_path( $up['basedir'] ),
			'others'  => wp_normalize_path( WP_CONTENT_DIR ),
			'core'    => wp_normalize_path( untrailingslashit( ABSPATH ) ),
		);
	}

	private static function file_components( array $job ) {
		return array_values( array_intersect( self::FILE_COMPONENTS, $job['components'] ) );
	}

	private static function files( array &$job, $deadline ) {
		$comps = self::file_components( $job );
		if ( $job['ci'] >= count( $comps ) ) {
			$job['step'] = empty( $job['vault'] ) ? 'manifest' : 'encrypt';
			return;
		}
		$comp = $comps[ $job['ci'] ];
		$root = self::roots()[ $comp ];
		if ( null === $job['scan'] ) {
			$job['scan'] = array(
				'comp'  => $comp,
				'stack' => array( '' ),
				'list'  => '.list-' . $comp . '.txt',
				'count' => 0,
				'bytes' => 0,
				'phase' => is_dir( $root ) ? 'scan' : 'done',
				'pos'   => 0,
				'cur'   => null,
				'part'  => 1,
				'done_files' => 0,
			);
			@unlink( self::dir( $job ) . '/' . $job['scan']['list'] ); // phpcs:ignore
		}
		$sc =& $job['scan'];
		if ( 'scan' === $sc['phase'] ) {
			self::scan( $job, $root, $deadline );
			$job['message'] = sprintf( __( 'Listing %1$s files… %2$s found', 'lifeboat-backup' ), self::label( $comp ), number_format_i18n( $sc['count'] ) );
			return;
		}
		if ( 'archive' === $sc['phase'] ) {
			self::archive( $job, $root, $deadline );
			$share          = 70 / max( 1, count( $comps ) );
			$job['percent'] = (int) ( 15 + $share * $job['ci'] + $share * ( $sc['count'] ? $sc['done_files'] / $sc['count'] : 1 ) );
			$job['message'] = sprintf( __( 'Archiving %1$s: %2$s of %3$s files', 'lifeboat-backup' ), self::label( $comp ), number_format_i18n( $sc['done_files'] ), number_format_i18n( $sc['count'] ) );
			return;
		}
		@unlink( self::dir( $job ) . '/' . $sc['list'] ); // phpcs:ignore
		$job['scan'] = null;
		$job['ci']++;
	}

	public static function label( $comp ) {
		$l = array(
			'db'      => __( 'Database', 'lifeboat-backup' ),
			'plugins' => __( 'Plugins', 'lifeboat-backup' ),
			'themes'  => __( 'Themes', 'lifeboat-backup' ),
			'uploads' => __( 'Uploads', 'lifeboat-backup' ),
			'others'  => __( 'Other content', 'lifeboat-backup' ),
			'core'    => __( 'WordPress core', 'lifeboat-backup' ),
		);
		return isset( $l[ $comp ] ) ? $l[ $comp ] : $comp;
	}

	/** Paths excluded from a component (other components, backup folders, user patterns). */
	private static function excluded( $comp, $abs, $is_dir ) {
		static $cache = array();
		if ( ! isset( $cache[ $comp ] ) ) {
			$roots = self::roots();
			$skip  = array( wp_normalize_path( Lifeboat_Util::backup_dir() ) );
			if ( 'others' === $comp ) {
				$skip = array_merge( $skip, array( $roots['plugins'], $roots['themes'], $roots['uploads'] ) );
			}
			if ( 'core' === $comp ) {
				$skip[] = $roots['others'];
			}
			$cache[ $comp ] = array(
				'skip'     => $skip,
				'patterns' => (array) Lifeboat_Settings::get( 'exclude' ),
				'max'      => (int) Lifeboat_Settings::get( 'max_file_mb' ) * 1048576,
				'abspath'  => wp_normalize_path( ABSPATH ),
				'content'  => wp_normalize_path( WP_CONTENT_DIR ),
			);
		}
		$c = $cache[ $comp ];
		foreach ( $c['skip'] as $s ) {
			if ( $abs === $s || 0 === strpos( $abs, $s . '/' ) ) {
				return true;
			}
		}
		// Patterns match the path as seen from the site root, e.g. "wp-content/cache".
		if ( 0 === strpos( $abs, $c['content'] . '/' ) ) {
			$rel = 'wp-content/' . substr( $abs, strlen( $c['content'] ) + 1 );
		} else {
			$rel = 0 === strpos( $abs, $c['abspath'] ) ? substr( $abs, strlen( $c['abspath'] ) ) : $abs;
		}
		$base = basename( $abs );
		foreach ( $c['patterns'] as $p ) {
			$p = trim( $p, '/' );
			if ( '' === $p ) {
				continue;
			}
			if ( false !== strpos( $p, '*' ) ) {
				if ( fnmatch( $p, $base ) || fnmatch( $p, $rel ) ) {
					return true;
				}
			} elseif ( $rel === $p || 0 === strpos( $rel, $p . '/' ) || false !== strpos( '/' . $rel . '/', '/' . $p . '/' ) ) {
				return true;
			}
		}
		return false;
	}

	private static function scan( array &$job, $root, $deadline ) {
		$sc   =& $job['scan'];
		$list = fopen( self::dir( $job ) . '/' . $sc['list'], 'ab' );
		$max  = (int) Lifeboat_Settings::get( 'max_file_mb' ) * 1048576;
		while ( $sc['stack'] && microtime( true ) < $deadline ) {
			$rel_dir = array_pop( $sc['stack'] );
			$abs_dir = '' === $rel_dir ? $root : $root . '/' . $rel_dir;
			$items   = @scandir( $abs_dir ); // phpcs:ignore
			if ( false === $items ) {
				$job['warnings'][] = sprintf( __( 'Could not read folder %s', 'lifeboat-backup' ), $abs_dir );
				continue;
			}
			foreach ( $items as $item ) {
				if ( '.' === $item || '..' === $item ) {
					continue;
				}
				$abs = $abs_dir . '/' . $item;
				$rel = '' === $rel_dir ? $item : $rel_dir . '/' . $item;
				if ( is_link( $abs ) ) {
					continue;
				}
				$is_dir = is_dir( $abs );
				if ( self::excluded( $sc['comp'], $abs, $is_dir ) ) {
					continue;
				}
				if ( $is_dir ) {
					$sc['stack'][] = $rel;
					continue;
				}
				$size = @filesize( $abs ); // phpcs:ignore
				if ( false === $size || ! is_readable( $abs ) ) {
					$job['warnings'][] = sprintf( __( 'Skipped unreadable file %s', 'lifeboat-backup' ), $rel );
					continue;
				}
				if ( $max && $size > $max ) {
					$job['warnings'][] = sprintf( __( 'Skipped %1$s (%2$s, over the size limit)', 'lifeboat-backup' ), $rel, Lifeboat_Util::bytes( $size ) );
					continue;
				}
				if ( $size > 8589934591 ) {
					$job['warnings'][] = sprintf( __( 'Skipped %s (larger than 8 GB)', 'lifeboat-backup' ), $rel );
					continue;
				}
				fwrite( $list, $rel . "\t" . $size . "\n" );
				$sc['count']++;
				$sc['bytes'] += $size;
			}
		}
		fclose( $list );
		if ( ! $sc['stack'] ) {
			$sc['phase'] = $sc['count'] ? 'archive' : 'done';
		}
	}

	private static function part_name( $comp, $n ) {
		return $comp . ( $n > 1 ? '-' . $n : '' ) . '.tar.gz';
	}

	private static function archive( array &$job, $root, $deadline ) {
		$sc    =& $job['scan'];
		$dir   = self::dir( $job );
		$name  = self::part_name( $sc['comp'], $sc['part'] );
		$split = (int) Lifeboat_Settings::get( 'split_mb' ) * 1048576;
		$easy  = 'easy' === $job['mode'];
		if ( ! isset( $job['parts'][ $name ] ) ) {
			$job['parts'][ $name ] = array(
				'component' => $sc['comp'],
				'file'      => $name,
			);
		}
		$ws   = self::writer_state( $job, $job['parts'][ $name ] );
		$path = $ws['file'];
		$w    = new Lifeboat_Archive_Writer( $ws, self::level( $job ) );
		$list         = fopen( $dir . '/' . $sc['list'], 'rb' );
		fseek( $list, $sc['pos'] );
		$rotate = false;

		try {
			while ( microtime( true ) < $deadline ) {
				if ( ! $sc['cur'] ) {
					$line = fgets( $list );
					if ( false === $line ) {
						$w->tar_end();
						$sc['phase'] = 'done';
						break;
					}
					$sc['pos'] = ftell( $list );
					list( $rel, $size ) = explode( "\t", rtrim( $line, "\n" ) );
					$abs = $root . '/' . $rel;
					if ( ! is_file( $abs ) ) {
						$sc['done_files']++;
						continue; // Deleted since the scan.
					}
					$size = (int) @filesize( $abs ); // phpcs:ignore
					$w->tar_header( $rel, $size, (int) @filemtime( $abs ) ); // phpcs:ignore
					$sc['cur'] = array( 'rel' => $rel, 'size' => $size, 'done' => 0 );
				}
				$cur =& $sc['cur'];
				$abs = $root . '/' . $cur['rel'];
				if ( $cur['done'] < $cur['size'] ) {
					$fh = @fopen( $abs, 'rb' ); // phpcs:ignore
					if ( $fh ) {
						fseek( $fh, $cur['done'] );
					}
					while ( $cur['done'] < $cur['size'] && microtime( true ) < $deadline ) {
						$want = min( self::READ, $cur['size'] - $cur['done'] );
						$data = $fh ? (string) fread( $fh, $want ) : '';
						if ( strlen( $data ) < $want && ( ! $fh || feof( $fh ) ) ) {
							$data .= str_repeat( "\0", $want - strlen( $data ) ); // File shrank while reading.
						}
						$w->write( $data );
						$cur['done'] += strlen( $data );
						Lifeboat_Runner::throttle( strlen( $data ), $easy );
					}
					if ( $fh ) {
						fclose( $fh );
					}
					if ( $cur['done'] < $cur['size'] ) {
						break; // Out of time mid-file; continue next slice.
					}
				}
				$w->tar_pad( $cur['size'] );
				$sc['cur'] = null;
				unset( $cur );
				$sc['done_files']++;
				$w->maybe_rotate();
				clearstatcache( true, $path );
				if ( $split && @filesize( $path ) >= $split ) { // phpcs:ignore
					$w->tar_end();
					$rotate = true;
					break;
				}
			}
		} finally {
			fclose( $list );
			$w->close();
			self::merge_writer( $job['parts'][ $name ], $ws );
		}
		if ( $rotate ) {
			$sc['part']++;
		}
	}

	/* ========================================================== encryption */

	/** Encrypt every archive part (name → name.enc) and remove the unencrypted copy. */
	private static function encrypt( array &$job, $deadline ) {
		$key = Lifeboat_Crypto::decrypt( $job['vault']['key'] );
		if ( 32 !== strlen( $key ) ) {
			throw new RuntimeException( __( 'The encryption key could not be unlocked. Enter the encryption password again under Schedule & settings.', 'lifeboat-backup' ) );
		}
		$dir   = self::dir( $job );
		$total = count( $job['parts'] );
		while ( microtime( true ) < $deadline ) {
			$name = '';
			$n    = 0;
			foreach ( $job['parts'] as $k => $p ) {
				if ( empty( $p['enc'] ) ) {
					$name = $k;
					break;
				}
				$n++;
			}
			if ( '' === $name ) {
				unset( $job['enc_state'] );
				$job['step'] = 'manifest';
				return;
			}
			if ( ! isset( $job['enc_state'] ) || $job['enc_state']['name'] !== $name ) {
				$job['enc_state'] = array( 'name' => $name );
			}
			$src  = $dir . '/' . $name;
			$dst  = $src . Lifeboat_Vault::SUFFIX;
			$done = Lifeboat_Vault::encrypt_step( $job['enc_state'], $src, $dst, $key, $job['vault'], $deadline, 'easy' === $job['mode'] );
			$size = (int) @filesize( $src ); // phpcs:ignore
			$job['message'] = sprintf( __( 'Encrypting %1$s… %2$d%%', 'lifeboat-backup' ), $name, $size ? (int) ( 100 * min( $job['enc_state']['done'], $size ) / $size ) : 100 );
			$job['percent'] = 85;
			if ( ! $done ) {
				return;
			}
			clearstatcache();
			$part               = $job['parts'][ $name ];
			$part['file']       = $name . Lifeboat_Vault::SUFFIX;
			$part['enc']        = true;
			$part['plain_size'] = $size;
			$part['size']       = filesize( $dst );
			$parts              = array();
			foreach ( $job['parts'] as $k => $p ) { // Rename in place, keeping the order.
				if ( $k === $name ) {
					$parts[ $part['file'] ] = $part;
				} else {
					$parts[ $k ] = $p;
				}
			}
			$job['parts'] = $parts;
			@unlink( $src ); // phpcs:ignore
			unset( $job['enc_state'] );
			if ( $n + 1 >= $total ) {
				$job['step'] = 'manifest';
				return;
			}
		}
	}

	/* ===================================================== manifest/upload */

	private static function manifest( array &$job, $deadline ) {
		global $wpdb, $wp_version;
		$dir = self::dir( $job );
		foreach ( $job['parts'] as $name => &$p ) {
			if ( empty( $p['sha1'] ) ) {
				if ( microtime( true ) > $deadline ) {
					return;
				}
				$job['message'] = sprintf( __( 'Verifying %s…', 'lifeboat-backup' ), $name );
				$p['sha1']      = sha1_file( $dir . '/' . $name );
				$p['size']      = filesize( $dir . '/' . $name );
			}
		}
		unset( $p );
		$manifest = array(
			'lifeboat'   => LIFEBOAT_VERSION,
			'id'         => $job['id'],
			'time'       => $job['started'],
			'trigger'    => $job['trigger'],
			'home'       => home_url(),
			'siteurl'    => site_url(),
			'prefix'     => $wpdb->base_prefix,
			'wp'         => $wp_version,
			'php'        => PHP_VERSION,
			'components' => $job['components'],
			'parts'      => $job['parts'],
			'roots'      => self::roots(),
		);
		if ( ! empty( $job['vault'] ) ) {
			$manifest['encryption'] = array_diff_key( $job['vault'], array( 'key' => 1 ) );
		}
		Lifeboat_Util::write_json( $dir . '/manifest.json', $manifest );
		$entry                  = Lifeboat_History::entry_from_manifest( $manifest );
		$entry['status']        = 'uploading';
		$entry['manifest_size'] = filesize( $dir . '/manifest.json' );
		Lifeboat_History::put( $entry );

		// Upload queue: every part, then the manifest last (its presence marks a complete remote backup).
		$queue = array();
		foreach ( $job['destinations'] as $d ) {
			foreach ( array_keys( $job['parts'] ) as $name ) {
				$queue[] = array( $d, $name );
			}
			$queue[] = array( $d, 'manifest.json' );
		}
		$job['upload']['queue'] = $queue;
		$job['step']            = $queue ? 'upload' : 'retention';
		$job['percent']         = 86;
	}

	public static function remote_name( array $job, $file ) {
		return $job['id'] . '-' . $file;
	}

	private static function upload( array &$job, $deadline ) {
		$u =& $job['upload'];
		unset( $job['retry_at'] );
		while ( microtime( true ) < $deadline ) {
			if ( $u['qi'] >= count( $u['queue'] ) ) {
				$job['step'] = 'retention';
				return;
			}
			list( $dest_id, $file ) = $u['queue'][ $u['qi'] ];
			$dest  = Lifeboat_Destination::get( $dest_id );
			$local = self::dir( $job ) . '/' . $file;
			$size  = filesize( $local );
			$job['message'] = sprintf( __( 'Uploading %1$s to %2$s…', 'lifeboat-backup' ), $file, $dest->label() );
			try {
				if ( empty( $u['session'] ) ) {
					$u['session'] = $dest->upload_start( $local, self::remote_name( $job, $file ), $size );
				}
				$done = false;
				while ( ! $done && microtime( true ) < $deadline ) {
					$before = isset( $u['session']['offset'] ) ? (int) $u['session']['offset'] : 0;
					$done   = $dest->upload_chunk( $u['session'], $local, $size );
					$after  = isset( $u['session']['offset'] ) ? (int) $u['session']['offset'] : $size;
					Lifeboat_Runner::throttle( max( 0, $after - $before ), 'easy' === $job['mode'] );
					if ( $size ) {
						$job['message'] = sprintf( __( 'Uploading %1$s to %2$s… %3$d%%', 'lifeboat-backup' ), $file, $dest->label(), (int) ( 100 * min( $after, $size ) / $size ) );
					}
				}
				if ( $done ) {
					$job['remote'][ $dest_id ][ $file ] = $u['session']['remote_id'];
					$u['session']                       = null;
					$u['retries']                       = 0;
					$u['qi']++;
					$job['percent'] = 86 + (int) ( 12 * $u['qi'] / max( 1, count( $u['queue'] ) ) );
				}
			} catch ( Lifeboat_Remote_Exception $e ) {
				$u['retries']++;
				if ( $e->restart ) {
					$u['session'] = null;
				}
				if ( $u['retries'] > 6 || ( ! $e->retry && ! $e->restart ) ) {
					// Give up on this destination for this backup; keep going with the others.
					$job['warnings'][] = $e->getMessage();
					$job['failed_dest'][ $dest_id ] = $e->getMessage();
					unset( $job['remote'][ $dest_id ] );
					while ( $u['qi'] < count( $u['queue'] ) && $u['queue'][ $u['qi'] ][0] === $dest_id ) {
						$u['qi']++;
					}
					$u['session'] = null;
					$u['retries'] = 0;
				} else {
					$job['retry_at'] = time() + min( 600, 20 * (int) pow( 2, $u['retries'] - 1 ) );
					$job['message']  = sprintf( __( '%s — retrying shortly', 'lifeboat-backup' ), $e->getMessage() );
					return;
				}
			}
		}
	}

	/* =========================================================== retention */

	private static function retention( array &$job ) {
		$history = Lifeboat_History::all();
		$entry   = isset( $history[ $job['id'] ] ) ? $history[ $job['id'] ] : null;
		if ( $entry ) {
			$entry['remote'] = $job['remote'];
			$entry['status'] = 'done';
			Lifeboat_History::put( $entry );
		}
		$history = Lifeboat_History::all();
		$kind    = Lifeboat_History::kind( $job['components'] );

		// Local copies.
		$keep_local = (int) Lifeboat_Settings::get( 'keep_local' );
		$local      = array_filter(
			$history,
			function ( $e ) use ( $kind ) {
				return ! empty( $e['local'] ) && $kind === $e['kind'] && 'done' === $e['status'];
			}
		);
		$uploaded_ok = ! empty( $job['remote'] );
		$i           = 0;
		foreach ( $local as $id => $e ) {
			$i++;
			// Never delete the only copy of the backup we just made if its upload failed.
			if ( $id === $job['id'] && ( ! $uploaded_ok || $keep_local > 0 ) ) {
				continue;
			}
			if ( $i > $keep_local || ( $id === $job['id'] && 0 === $keep_local ) ) {
				Lifeboat_Util::rrmdir( Lifeboat_History::local_dir( $id ) );
				$history[ $id ]['local'] = false;
				Lifeboat_History::put( $history[ $id ] );
			}
		}

		// Remote copies, per destination.
		$keep_remote = (int) Lifeboat_Settings::get( 'keep_remote' );
		foreach ( Lifeboat_Destination::registry() as $dest_id => $dest ) {
			if ( ! $dest->is_connected() ) {
				continue;
			}
			$n = 0;
			foreach ( Lifeboat_History::all() as $id => $e ) {
				if ( $kind !== $e['kind'] || empty( $e['remote'][ $dest_id ] ) ) {
					continue;
				}
				$n++;
				if ( $n <= $keep_remote ) {
					continue;
				}
				foreach ( $e['remote'][ $dest_id ] as $file => $rid ) {
					try {
						$dest->delete( $rid );
					} catch ( Exception $ex ) {
						$job['warnings'][] = $ex->getMessage();
					}
				}
				unset( $e['remote'][ $dest_id ] );
				if ( empty( $e['remote'] ) && empty( $e['local'] ) ) {
					Lifeboat_History::remove( $id );
				} else {
					Lifeboat_History::put( $e );
				}
			}
		}
		$job['step'] = 'done';
	}

	private static function finish( array &$job ) {
		$dir = self::dir( $job );
		foreach ( (array) glob( $dir . '/.list-*.txt' ) as $f ) {
			@unlink( $f ); // phpcs:ignore
		}
		$job['status']   = 'done';
		$job['percent']  = 100;
		$job['finished'] = time();
		$size            = 0;
		foreach ( $job['parts'] as $p ) {
			$size += isset( $p['size'] ) ? $p['size'] : 0;
		}
		$where = array();
		foreach ( array_keys( $job['remote'] ) as $d ) {
			$where[] = Lifeboat_Destination::get( $d )->label();
		}
		if ( Lifeboat_History::has_local( $job['id'] ) ) {
			$where[] = __( 'this server', 'lifeboat-backup' );
		}
		$job['message'] = sprintf(
			/* translators: 1: size, 2: duration, 3: locations */
			__( 'Backup complete: %1$s in %2$s, stored on %3$s.', 'lifeboat-backup' ),
			Lifeboat_Util::bytes( $size ),
			human_time_diff( $job['started'], $job['finished'] ),
			$where ? implode( ', ', $where ) : '—'
		);
		$failed = array_keys( isset( $job['failed_dest'] ) ? (array) $job['failed_dest'] : array() );
		$entry  = Lifeboat_History::get( $job['id'] );
		if ( $entry ) {
			$entry['failed']   = $failed;
			$entry['duration'] = $job['finished'] - $job['started'];
			Lifeboat_History::put( $entry );
		}
		update_option(
			'lifeboat_last_backup',
			array(
				'time'    => time(),
				'id'      => $job['id'],
				'status'  => $failed ? 'partial' : 'ok',
				'size'    => $size,
				'where'   => $where,
				'trigger' => $job['trigger'],
				'message' => $job['message'],
			),
			false
		);
		Lifeboat_Plugin::notify( $job );
	}

	/** Remove the partial files of a failed or cancelled backup. */
	public static function cleanup( array $job ) {
		Lifeboat_Util::rrmdir( self::dir( $job ) );
		$e = Lifeboat_History::get( $job['id'] );
		if ( $e && empty( $e['remote'] ) ) {
			Lifeboat_History::remove( $job['id'] );
		}
	}
}
