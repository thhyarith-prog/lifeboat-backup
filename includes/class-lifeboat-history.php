<?php
/**
 * Backup history: what exists locally and on each cloud destination.
 *
 * @package Lifeboat_Backup
 */

defined( 'ABSPATH' ) || exit;

class Lifeboat_History {

	const OPTION = 'lifeboat_history';
	const MAX    = 300;

	public static function all() {
		$h = get_option( self::OPTION, array() );
		$h = is_array( $h ) ? $h : array();
		uasort(
			$h,
			function ( $a, $b ) {
				return $b['time'] - $a['time'];
			}
		);
		return $h;
	}

	public static function get( $id ) {
		$h = self::all();
		return isset( $h[ $id ] ) ? $h[ $id ] : null;
	}

	public static function put( array $entry ) {
		$h                 = self::all();
		$h[ $entry['id'] ] = $entry;
		if ( count( $h ) > self::MAX ) {
			$h = array_slice( $h, 0, self::MAX, true );
		}
		update_option( self::OPTION, $h, false );
	}

	public static function remove( $id ) {
		$h = self::all();
		unset( $h[ $id ] );
		update_option( self::OPTION, $h, false );
	}

	public static function local_dir( $id ) {
		return Lifeboat_Util::backup_dir() . '/' . sanitize_file_name( $id );
	}

	public static function has_local( $id ) {
		return is_file( self::local_dir( $id ) . '/manifest.json' );
	}

	/** Pick up backups found on disk that are missing from the history (e.g. after a DB restore). */
	public static function sync_local() {
		$job     = Lifeboat_Runner::job();
		$running = Lifeboat_Runner::is_active( $job ) ? $job['id'] : '';
		$h       = self::all();
		$changed = false;
		foreach ( (array) glob( Lifeboat_Util::backup_dir() . '/*/manifest.json' ) as $mf ) {
			$m = Lifeboat_Util::read_json( $mf );
			if ( ! $m || empty( $m['id'] ) || isset( $h[ $m['id'] ] ) ) {
				continue;
			}
			$h[ $m['id'] ] = self::entry_from_manifest( $m );
			$changed       = true;
		}
		foreach ( $h as $id => $e ) {
			$local = self::has_local( $id );
			if ( $local !== ! empty( $e['local'] ) ) {
				$h[ $id ]['local'] = $local;
				$changed           = true;
			}
			// Nothing left anywhere (finished and pruned, or an abandoned attempt): drop it,
			// unless it is the backup currently being written.
			if ( ! $local && empty( $e['remote'] ) && $id !== $running ) {
				unset( $h[ $id ] );
				$changed = true;
			}
		}
		if ( $changed ) {
			update_option( self::OPTION, $h, false );
		}
	}

	public static function entry_from_manifest( array $m ) {
		$files = array();
		$size  = 0;
		foreach ( $m['parts'] as $name => $p ) {
			$files[ $name ] = array(
				'size'      => $p['size'],
				'sha1'      => isset( $p['sha1'] ) ? $p['sha1'] : '',
				'component' => $p['component'],
				'enc'       => ! empty( $p['enc'] ),
			);
			$size          += $p['size'];
		}
		return array(
			'id'         => $m['id'],
			'time'       => $m['time'],
			'trigger'    => isset( $m['trigger'] ) ? $m['trigger'] : 'manual',
			'components' => $m['components'],
			'kind'       => self::kind( $m['components'] ),
			'files'      => $files,
			'size'       => $size,
			'local'      => true,
			'remote'     => array(),
			'status'     => 'done',
			'site'       => isset( $m['home'] ) ? $m['home'] : '',
			'encryption' => isset( $m['encryption'] ) ? $m['encryption'] : null,
		);
	}

	/** "db" backups and "full" backups are retained separately. */
	public static function kind( array $components ) {
		return array( 'db' ) === array_values( $components ) ? 'db' : 'full';
	}
}
