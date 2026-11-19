<?php
/**
 * Database dump and restore.
 *
 * Dump: one statement per line, rows read in batches using the integer primary key when there
 * is one (constant speed on huge tables, unlike LIMIT/OFFSET).
 * Restore: every table is first rebuilt under a temporary name, then all tables are swapped in
 * at the end, so the live site keeps working until the restore is complete.
 *
 * @package Lifeboat_Backup
 */

defined( 'ABSPATH' ) || exit;

class Lifeboat_DB {

	const BATCH      = 500;
	const STMT_BYTES = 1048576;
	const TMP        = 'lbr_';
	const OLD        = 'lbo_';

	public static function tables() {
		global $wpdb;
		$prefix = $wpdb->base_prefix;
		$rows   = $wpdb->get_results( 'SHOW FULL TABLES', ARRAY_N ); // phpcs:ignore
		$tables = array();
		if ( $rows ) {
			foreach ( $rows as $r ) {
				if ( ! isset( $r[1] ) || 'VIEW' !== strtoupper( $r[1] ) ) {
					$tables[] = $r[0];
				}
			}
		} else {
			$tables = (array) $wpdb->get_col( 'SHOW TABLES' ); // phpcs:ignore
		}
		$tables = array_filter(
			$tables,
			function ( $t ) use ( $prefix ) {
				return 0 === strpos( $t, $prefix ) && 0 !== strpos( $t, self::TMP ) && 0 !== strpos( $t, self::OLD );
			}
		);
		return array_values( $tables );
	}

	private static function int_pk( $table ) {
		global $wpdb;
		$suppress = $wpdb->suppress_errors( true );
		$keys     = $wpdb->get_results( "SHOW KEYS FROM `{$table}` WHERE Key_name = 'PRIMARY'", ARRAY_A ); // phpcs:ignore
		$pk       = null;
		if ( is_array( $keys ) && 1 === count( $keys ) ) {
			$col  = $keys[0]['Column_name'];
			$cols = $wpdb->get_results( "SHOW COLUMNS FROM `{$table}`", ARRAY_A ); // phpcs:ignore
			foreach ( (array) $cols as $c ) {
				if ( $c['Field'] === $col && preg_match( '/int/i', $c['Type'] ) ) {
					$pk = $col;
				}
			}
		}
		$wpdb->suppress_errors( $suppress );
		return $pk;
	}

	/**
	 * @return bool True when the whole database has been written.
	 */
	public static function dump_step( array &$st, Lifeboat_Archive_Writer $w, $deadline, $throttle ) {
		global $wpdb;
		if ( ! isset( $st['tables'] ) ) {
			$st = array(
				'tables' => self::tables(),
				'ti'     => 0,
				'phase'  => 'create',
				'rows'   => 0,
			);
			$w->write(
				'-- Lifeboat Backup ' . LIFEBOAT_VERSION . ' | ' . home_url() . ' | ' . gmdate( 'c' ) . "\n"
				. '-- prefix: ' . $wpdb->base_prefix . "\n"
				. "SET NAMES utf8mb4;\nSET foreign_key_checks = 0;\nSET sql_mode = 'NO_AUTO_VALUE_ON_ZERO';\n"
			);
		}
		while ( microtime( true ) < $deadline ) {
			if ( $st['ti'] >= count( $st['tables'] ) ) {
				$w->write( "SET foreign_key_checks = 1;\n-- end\n" );
				return true;
			}
			$t = $st['tables'][ $st['ti'] ];

			if ( 'create' === $st['phase'] ) {
				$row = $wpdb->get_row( "SHOW CREATE TABLE `{$t}`", ARRAY_N ); // phpcs:ignore
				if ( ! $row || empty( $row[1] ) ) {
					$st['ti']++;
					continue;
				}
				$create = preg_replace( '/\s*[\r\n]+\s*/', ' ', $row[1] );
				$w->write( "DROP TABLE IF EXISTS `{$t}`;\n{$create};\n" );
				$st['phase']  = 'rows';
				$st['pk']     = self::int_pk( $t );
				$st['last']   = null;
				$st['offset'] = 0;
				continue;
			}

			$limit = self::BATCH;
			if ( $st['pk'] ) {
				$pk    = $st['pk'];
				$where = null === $st['last'] ? '' : $wpdb->prepare( "WHERE `{$pk}` > %d", $st['last'] ); // phpcs:ignore
				$rows  = $wpdb->get_results( "SELECT * FROM `{$t}` {$where} ORDER BY `{$pk}` ASC LIMIT {$limit}", ARRAY_A ); // phpcs:ignore
			} else {
				$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM `{$t}` LIMIT %d, %d", $st['offset'], $limit ), ARRAY_A ); // phpcs:ignore
			}
			if ( $wpdb->last_error && ! $rows ) {
				throw new RuntimeException( 'Database read failed on ' . $t . ': ' . $wpdb->last_error );
			}
			if ( ! $rows ) {
				$st['ti']++;
				$st['phase'] = 'create';
				$w->maybe_rotate();
				continue;
			}

			$cols  = '`' . implode( '`,`', array_keys( $rows[0] ) ) . '`';
			$head  = "INSERT INTO `{$t}` ({$cols}) VALUES ";
			$stmt  = '';
			foreach ( $rows as $r ) {
				$vals = array();
				foreach ( $r as $v ) {
					$vals[] = null === $v ? 'NULL' : "'" . $wpdb->_real_escape( $v ) . "'";
				}
				$tuple = '(' . implode( ',', $vals ) . ')';
				if ( '' !== $stmt && strlen( $stmt ) + strlen( $tuple ) > self::STMT_BYTES ) {
					$w->write( $wpdb->remove_placeholder_escape( $head . $stmt ) . ";\n" );
					$stmt = '';
				}
				$stmt .= ( '' === $stmt ? '' : ',' ) . $tuple;
			}
			if ( '' !== $stmt ) {
				$w->write( $wpdb->remove_placeholder_escape( $head . $stmt ) . ";\n" );
			}
			$st['rows'] += count( $rows );
			if ( $st['pk'] ) {
				$last       = end( $rows );
				$st['last'] = (int) $last[ $st['pk'] ];
			} else {
				$st['offset'] += count( $rows );
			}
			if ( count( $rows ) < $limit ) {
				$st['ti']++;
				$st['phase'] = 'create';
			}
			$w->maybe_rotate();
			if ( $throttle ) {
				usleep( 20000 ); // Easy mode: let the database breathe between batches.
			}
		}
		return false;
	}

	/* =============================================================== restore */

	/**
	 * Execute the statements of one archive member into temporary tables.
	 */
	public static function restore_member( array &$st, $data ) {
		global $wpdb;
		$buffer = ( isset( $st['carry'] ) ? $st['carry'] : '' ) . $data;
		$lines  = explode( "\n", $buffer );
		$st['carry'] = array_pop( $lines );
		foreach ( $lines as $line ) {
			$line = rtrim( $line, "\r" );
			if ( '' === $line || 0 === strpos( $line, '--' ) ) {
				continue;
			}
			if ( preg_match( '/^CREATE TABLE `([^`]+)`/', $line, $cm ) ) {
				$st['creates'][ $cm[1] ] = $line;
			}
			$line = preg_replace_callback(
				'/^(DROP TABLE IF EXISTS|CREATE TABLE|INSERT INTO) `([^`]+)`/',
				function ( $m ) use ( &$st ) {
					if ( 'CREATE TABLE' === $m[1] ) {
						$st['tables'][ $m[2] ] = true;
					}
					return $m[1] . ' `' . self::TMP . $m[2] . '`';
				},
				$line
			);
			$suppress = $wpdb->suppress_errors( true );
			$ok       = $wpdb->query( $line ); // phpcs:ignore
			$wpdb->suppress_errors( $suppress );
			if ( false === $ok && 0 !== strpos( $line, 'SET ' ) ) {
				$st['errors'] = isset( $st['errors'] ) ? $st['errors'] + 1 : 1;
				if ( $st['errors'] <= 5 ) {
					$st['error_samples'][] = substr( $wpdb->last_error, 0, 200 ) . ' — ' . substr( $line, 0, 120 );
				}
			} else {
				$st['statements'] = isset( $st['statements'] ) ? $st['statements'] + 1 : 1;
			}
		}
	}

	/**
	 * Swap the rebuilt tables in. Lifeboat's own settings (cloud connections) and, optionally,
	 * the current site URL are carried over so the site keeps working after a migration-style restore.
	 */
	public static function swap_in( array $tables, $keep_url, array $creates = array() ) {
		global $wpdb;
		$keep = $wpdb->get_results( "SELECT option_name, option_value, autoload FROM {$wpdb->options} WHERE option_name LIKE 'lifeboat\\_%'" . ( $keep_url ? " OR option_name IN ('siteurl','home')" : '' ), ARRAY_A ); // phpcs:ignore
		$active = get_option( 'active_plugins', array() );

		$wpdb->query( 'SET foreign_key_checks = 0' ); // phpcs:ignore
		foreach ( array_keys( $tables ) as $t ) {
			self::swap_table( $t, isset( $creates[ $t ] ) ? $creates[ $t ] : '' );
		}
		$wpdb->query( 'SET foreign_key_checks = 1' ); // phpcs:ignore

		foreach ( (array) $keep as $o ) {
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s", $o['option_name'] ) ); // phpcs:ignore
			$wpdb->insert( $wpdb->options, $o ); // phpcs:ignore
		}
		// Keep Lifeboat active even if the backup predates it.
		$restored = maybe_unserialize( $wpdb->get_var( "SELECT option_value FROM {$wpdb->options} WHERE option_name = 'active_plugins'" ) ); // phpcs:ignore
		$restored = is_array( $restored ) ? $restored : array();
		$me       = plugin_basename( LIFEBOAT_FILE );
		if ( in_array( $me, $active, true ) && ! in_array( $me, $restored, true ) ) {
			$restored[] = $me;
			$wpdb->update( $wpdb->options, array( 'option_value' => maybe_serialize( $restored ) ), array( 'option_name' => 'active_plugins' ) ); // phpcs:ignore
		}
		wp_cache_flush();
	}

	private static function table_exists( $t ) {
		global $wpdb;
		return $t === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $t ) ) ); // phpcs:ignore
	}

	private static function count_rows( $t ) {
		global $wpdb;
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$t}`" ); // phpcs:ignore
	}

	/**
	 * Replace table $t with its rebuilt copy. Uses an atomic RENAME where the database supports
	 * it, verifies the result, and otherwise falls back to copying rows. Never trusts a
	 * "success" return value without checking.
	 */
	private static function swap_table( $t, $create ) {
		global $wpdb;
		$tmp = self::TMP . $t;
		$old = self::OLD . $t;
		if ( ! self::table_exists( $tmp ) ) {
			throw new RuntimeException( sprintf( 'Restored table %s is missing; the live database was left unchanged.', $t ) );
		}
		$expected = self::count_rows( $tmp );
		$suppress = $wpdb->suppress_errors( true );
		$wpdb->query( "DROP TABLE IF EXISTS `{$old}`" ); // phpcs:ignore
		$wpdb->query( self::table_exists( $t ) ? "RENAME TABLE `{$t}` TO `{$old}`, `{$tmp}` TO `{$t}`" : "RENAME TABLE `{$tmp}` TO `{$t}`" ); // phpcs:ignore
		$wpdb->suppress_errors( $suppress );

		if ( ! self::table_exists( $tmp ) && self::table_exists( $t ) && self::count_rows( $t ) === $expected ) {
			$wpdb->query( "DROP TABLE IF EXISTS `{$old}`" ); // phpcs:ignore
			return;
		}

		// Fallback (databases without RENAME TABLE): recreate and copy.
		if ( '' === $create ) {
			throw new RuntimeException( sprintf( 'Could not switch table %s and no definition is available to copy it.', $t ) );
		}
		$wpdb->query( "DROP TABLE IF EXISTS `{$t}`" ); // phpcs:ignore
		$wpdb->query( $create ); // phpcs:ignore
		$wpdb->query( "INSERT INTO `{$t}` SELECT * FROM `{$tmp}`" ); // phpcs:ignore
		$got = self::table_exists( $t ) ? self::count_rows( $t ) : -1;
		if ( $got !== $expected ) {
			throw new RuntimeException( sprintf( 'Table %1$s has %2$d rows after restore, expected %3$d. Error: %4$s', $t, $got, $expected, $wpdb->last_error ) );
		}
		$wpdb->query( "DROP TABLE IF EXISTS `{$tmp}`" ); // phpcs:ignore
		$wpdb->query( "DROP TABLE IF EXISTS `{$old}`" ); // phpcs:ignore
	}

	/** Remove leftovers of an interrupted restore. */
	public static function drop_temp_tables( array $tables ) {
		global $wpdb;
		foreach ( array_keys( $tables ) as $t ) {
			$wpdb->query( 'DROP TABLE IF EXISTS `' . self::TMP . $t . '`' ); // phpcs:ignore
		}
	}
}
