<?php
/**
 * Restore job: fetch (download from cloud if needed) → verify checksums → database into
 * temporary tables → swap → files → done. Resumable like backups.
 *
 * @package Lifeboat_Backup
 */

defined( 'ABSPATH' ) || exit;

class Lifeboat_Restore {

	/**
	 * @param string $key Decryption key for an encrypted backup (raw, 32 bytes) or ''.
	 */
	public static function create_job( $backup_id, array $components, $source, $keep_url = true, $key = '' ) {
		return array(
			'type'       => 'restore',
			'id'         => 'restore-' . gmdate( 'Ymd-His' ),
			'backup'     => $backup_id,
			'components' => array_values( array_intersect( Lifeboat_Settings::COMPONENTS, $components ) ),
			'source'     => $source,
			'keep_url'   => (bool) $keep_url,
			'mode'       => 'rush',
			'status'     => 'running',
			'step'       => 'fetch',
			'started'    => time(),
			'message'    => __( 'Preparing restore…', 'lifeboat-backup' ),
			'percent'    => 1,
			'queue'      => array(),
			'dl'         => array( 'i' => 0, 'session' => null, 'retries' => 0 ),
			'db'         => array( 'mi' => 0, 'tables' => array() ),
			'files'      => array( 'pi' => 0, 'mi' => 0, 'tar' => array(), 'count' => 0 ),
			'warnings'   => array(),
			'vault'      => '' !== (string) $key ? Lifeboat_Crypto::encrypt( $key ) : '',
		);
	}

	/** Key for an encrypted part ('' for a plain one). Held in the job, so it survives the database swap. */
	private static function key( array $job, array $part ) {
		if ( empty( $part['enc'] ) ) {
			return '';
		}
		$k = empty( $job['vault'] ) ? '' : Lifeboat_Crypto::decrypt( $job['vault'] );
		if ( 32 !== strlen( $k ) ) {
			throw new RuntimeException( __( 'This backup is encrypted. Start the restore again and enter its encryption password.', 'lifeboat-backup' ) );
		}
		return $k;
	}

	/** Name of the database part ("database.sql.gz", or "database.sql.gz.enc" when encrypted). */
	private static function db_part( array $job ) {
		foreach ( $job['parts'] as $name => $p ) {
			if ( 'db' === $p['component'] ) {
				return $name;
			}
		}
		return '';
	}

	private static function dir( array $job ) {
		return Lifeboat_History::local_dir( $job['backup'] );
	}

	public static function step( array &$job, $deadline ) {
		while ( 'running' === $job['status'] && microtime( true ) < $deadline ) {
			switch ( $job['step'] ) {
				case 'fetch':
					self::fetch( $job );
					break;
				case 'download':
					self::download( $job, $deadline );
					if ( ! empty( $job['retry_at'] ) ) {
						return;
					}
					break;
				case 'db':
					self::db( $job, $deadline );
					break;
				case 'swap':
					$job['message'] = __( 'Switching to the restored database…', 'lifeboat-backup' );
					Lifeboat_DB::swap_in( $job['db']['tables'], $job['keep_url'], isset( $job['db']['creates'] ) ? $job['db']['creates'] : array() );
					$job['db']['tables']  = array();
					$job['db']['creates'] = array();
					$job['step']          = 'files';
					break;
				case 'files':
					self::files( $job, $deadline );
					break;
				case 'done':
					$job['status']   = 'done';
					$job['percent']  = 100;
					$job['finished'] = time();
					$job['message']  = sprintf( __( 'Restore complete in %s.', 'lifeboat-backup' ), human_time_diff( $job['started'], $job['finished'] ) );
					if ( ! empty( $job['db']['errors'] ) ) {
						$job['warnings'][] = sprintf( __( '%d database statements failed.', 'lifeboat-backup' ), $job['db']['errors'] );
					}
					Lifeboat_History::sync_local();
					Lifeboat_Plugin::notify( $job );
					break;
				default:
					throw new RuntimeException( 'Unknown step ' . $job['step'] );
			}
		}
	}

	/** Work out which archive parts are needed and which must be downloaded. */
	private static function fetch( array &$job ) {
		$entry = Lifeboat_History::get( $job['backup'] );
		$dir   = self::dir( $job );
		wp_mkdir_p( $dir );
		Lifeboat_Util::protect_dir( $dir );
		if ( ! $entry ) {
			throw new RuntimeException( __( 'Backup not found.', 'lifeboat-backup' ) );
		}
		$queue = array();
		foreach ( $entry['files'] as $name => $f ) {
			if ( ! in_array( $f['component'], $job['components'], true ) ) {
				continue;
			}
			$local = $dir . '/' . $name;
			if ( is_file( $local ) && filesize( $local ) === (int) $f['size'] ) {
				continue;
			}
			$queue[] = $name;
		}
		if ( ! is_file( $dir . '/manifest.json' ) ) {
			array_unshift( $queue, 'manifest.json' );
		}
		if ( $queue ) {
			$src = 'local' === $job['source'] ? self::pick_source( $entry ) : $job['source'];
			if ( ! $src || empty( $entry['remote'][ $src ] ) ) {
				throw new RuntimeException( __( 'Some backup files are missing locally and no cloud copy is available.', 'lifeboat-backup' ) );
			}
			$job['source'] = $src;
		}
		$job['queue'] = $queue;
		$job['step']  = $queue ? 'download' : 'db';
		if ( ! $queue ) {
			self::load_manifest( $job );
		}
	}

	private static function pick_source( array $entry ) {
		foreach ( array_keys( (array) $entry['remote'] ) as $d ) {
			$dest = Lifeboat_Destination::get( $d );
			if ( $dest && $dest->is_connected() ) {
				return $d;
			}
		}
		return '';
	}

	private static function download( array &$job, $deadline ) {
		unset( $job['retry_at'] );
		$entry = Lifeboat_History::get( $job['backup'] );
		$dest  = Lifeboat_Destination::get( $job['source'] );
		$dl    =& $job['dl'];
		while ( microtime( true ) < $deadline ) {
			if ( $dl['i'] >= count( $job['queue'] ) ) {
				self::load_manifest( $job );
				$job['step'] = 'db';
				return;
			}
			$name = $job['queue'][ $dl['i'] ];
			$rid  = isset( $entry['remote'][ $job['source'] ][ $name ] ) ? $entry['remote'][ $job['source'] ][ $name ] : '';
			$size = 'manifest.json' === $name ? (int) ( isset( $entry['manifest_size'] ) ? $entry['manifest_size'] : 0 ) : (int) $entry['files'][ $name ]['size'];
			if ( ! $rid || ! $size ) {
				throw new RuntimeException( sprintf( __( '%1$s is not available on %2$s.', 'lifeboat-backup' ), $name, $dest->label() ) );
			}
			$job['message'] = sprintf( __( 'Downloading %1$s from %2$s…', 'lifeboat-backup' ), $name, $dest->label() );
			try {
				$s    = (array) $dl['session'];
				$done = $dest->download_chunk( $s, $rid, self::dir( $job ) . '/' . $name, $size );
				$dl['session'] = $s;
				$job['percent'] = 2 + (int) ( 28 * ( $dl['i'] + ( isset( $s['offset'] ) ? $s['offset'] / $size : 0 ) ) / max( 1, count( $job['queue'] ) ) );
				if ( $done ) {
					if ( 'manifest.json' !== $name && ! empty( $entry['files'][ $name ]['sha1'] ) && sha1_file( self::dir( $job ) . '/' . $name ) !== $entry['files'][ $name ]['sha1'] ) {
						throw new RuntimeException( sprintf( __( '%s failed its checksum after download. The cloud copy may be damaged.', 'lifeboat-backup' ), $name ) );
					}
					$dl['i']++;
					$dl['session'] = null;
					$dl['retries'] = 0;
				}
			} catch ( Lifeboat_Remote_Exception $e ) {
				$dl['retries']++;
				if ( $dl['retries'] > 6 || ! $e->retry ) {
					throw $e;
				}
				$job['retry_at'] = time() + 20 * $dl['retries'];
				$job['message']  = $e->getMessage();
				return;
			}
		}
	}

	private static function load_manifest( array &$job ) {
		$m = Lifeboat_Util::read_json( self::dir( $job ) . '/manifest.json' );
		if ( ! $m || empty( $m['parts'] ) ) {
			throw new RuntimeException( __( 'The backup manifest is missing or damaged.', 'lifeboat-backup' ) );
		}
		$parts = array();
		foreach ( $m['parts'] as $name => $p ) {
			if ( in_array( $p['component'], $job['components'], true ) ) {
				$parts[ $name ] = array(
					'component' => $p['component'],
					'members'   => $p['members'],
					'size'      => $p['size'],
				);
				if ( ! empty( $p['enc'] ) ) {
					$parts[ $name ]['enc']        = true;
					$parts[ $name ]['plain_size'] = $p['plain_size'];
				}
			}
		}
		$job['parts'] = $parts;
		// Database first, then files in a stable order.
		$order = array_flip( Lifeboat_Settings::COMPONENTS );
		uasort(
			$job['parts'],
			function ( $a, $b ) use ( $order ) {
				return $order[ $a['component'] ] - $order[ $b['component'] ];
			}
		);
		if ( ! self::db_part( $job ) ) {
			$job['step'] = 'files';
		}
	}

	private static function db( array &$job, $deadline ) {
		$name = self::db_part( $job );
		if ( ! $name ) {
			$job['step'] = 'files';
			return;
		}
		$part = $job['parts'][ $name ];
		$file = self::dir( $job ) . '/' . $name;
		$key  = self::key( $job, $part );
		$n    = count( $part['members'] );
		while ( $job['db']['mi'] < $n && microtime( true ) < $deadline ) {
			list( $start, $end ) = Lifeboat_Archive_Reader::member_range( $part, $job['db']['mi'] );
			Lifeboat_Archive_Reader::read_member(
				$file,
				$start,
				$end,
				function ( $data ) use ( &$job ) {
					Lifeboat_DB::restore_member( $job['db'], $data );
				},
				$key
			);
			$job['db']['mi']++;
			$job['percent'] = 30 + (int) ( 35 * $job['db']['mi'] / max( 1, $n ) );
			$job['message'] = sprintf( __( 'Restoring database… %s statements', 'lifeboat-backup' ), number_format_i18n( isset( $job['db']['statements'] ) ? $job['db']['statements'] : 0 ) );
		}
		if ( $job['db']['mi'] >= $n ) {
			if ( empty( $job['db']['tables'] ) ) {
				throw new RuntimeException( __( 'The database backup contained no tables.', 'lifeboat-backup' ) );
			}
			$job['step'] = 'swap';
		}
	}

	private static function files( array &$job, $deadline ) {
		$names = array_values(
			array_filter(
				array_keys( $job['parts'] ),
				function ( $n ) use ( $job ) {
					return 'db' !== $job['parts'][ $n ]['component'];
				}
			)
		);
		$f =& $job['files'];
		$roots = Lifeboat_Backup::roots();
		$self  = wp_normalize_path( untrailingslashit( LIFEBOAT_DIR ) );
		$bdir  = wp_normalize_path( Lifeboat_Util::backup_dir() );
		while ( microtime( true ) < $deadline ) {
			if ( $f['pi'] >= count( $names ) ) {
				$job['step'] = 'done';
				return;
			}
			$name = $names[ $f['pi'] ];
			$part = $job['parts'][ $name ];
			$root = $roots[ $part['component'] ];
			if ( $f['mi'] >= count( $part['members'] ) ) {
				$f['pi']++;
				$f['mi']  = 0;
				$f['tar'] = array();
				continue;
			}
			// Never overwrite Lifeboat itself (it is running) or wp-config.php (database credentials).
			$filter = function ( $rel ) use ( $root, $self, $bdir ) {
				$abs = $root . '/' . $rel;
				return ! ( 0 === strpos( $abs . '/', $self . '/' ) || 0 === strpos( $abs, $bdir . '/' ) || 'wp-config.php' === $rel );
			};
			$x = new Lifeboat_Tar_Extractor( $f['tar'], $root, $filter );
			list( $start, $end ) = Lifeboat_Archive_Reader::member_range( $part, $f['mi'] );
			Lifeboat_Archive_Reader::read_member( self::dir( $job ) . '/' . $name, $start, $end, array( $x, 'feed' ), self::key( $job, $part ) );
			$x->finish_slice();
			$f['count'] += $x->files;
			$f['mi']++;
			$job['percent'] = 65 + (int) ( 34 * ( $f['pi'] + $f['mi'] / max( 1, count( $part['members'] ) ) ) / max( 1, count( $names ) ) );
			$job['message'] = sprintf( __( 'Restoring files: %1$s (%2$s files so far)', 'lifeboat-backup' ), $name, number_format_i18n( $f['count'] ) );
		}
	}

	public static function cleanup( array $job ) {
		if ( ! empty( $job['db']['tables'] ) ) {
			Lifeboat_DB::drop_temp_tables( $job['db']['tables'] );
		}
	}
}
