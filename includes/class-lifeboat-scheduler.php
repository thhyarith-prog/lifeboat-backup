<?php
/**
 * Schedules (daily / weekly / monthly at a chosen hour, plus database-only backups) and
 * automatic backups after core, plugin or theme updates.
 *
 * Events are scheduled one at a time for their exact next time in the site's timezone,
 * which also makes "monthly on the 1st" possible (WP-Cron has no monthly recurrence).
 *
 * @package Lifeboat_Backup
 */

defined( 'ABSPATH' ) || exit;

class Lifeboat_Scheduler {

	const FULL  = 'lifeboat_scheduled_full';
	const DB    = 'lifeboat_scheduled_db';
	const AFTER = 'lifeboat_after_update';
	const DEBOUNCE = 300;

	public static function init() {
		add_action( self::FULL, array( __CLASS__, 'run_full' ) );
		add_action( self::DB, array( __CLASS__, 'run_db' ) );
		add_action( self::AFTER, array( __CLASS__, 'run_after_update' ) );
		add_action( 'upgrader_process_complete', array( __CLASS__, 'on_update' ), 20, 2 );
		add_action( 'automatic_updates_complete', array( __CLASS__, 'on_auto_update' ) );
		add_action( 'lifeboat_settings_updated', array( __CLASS__, 'reschedule' ) );
	}

	public static function reschedule() {
		wp_clear_scheduled_hook( self::FULL );
		wp_clear_scheduled_hook( self::DB );
		$s = Lifeboat_Settings::all();
		if ( 'off' !== $s['schedule'] ) {
			wp_schedule_single_event( self::next_time( $s['schedule'] ), self::FULL );
		}
		if ( 'off' !== $s['db_schedule'] ) {
			wp_schedule_single_event( self::next_time( $s['db_schedule'] ), self::DB );
		}
	}

	/** Make sure the events exist (they are single events and re-arm themselves). */
	public static function ensure() {
		$s = Lifeboat_Settings::all();
		if ( ( 'off' !== $s['schedule'] && ! wp_next_scheduled( self::FULL ) ) || ( 'off' !== $s['db_schedule'] && ! wp_next_scheduled( self::DB ) ) ) {
			self::reschedule();
		}
	}

	/**
	 * Next run time (UTC timestamp) for a frequency, using the configured hour/day.
	 */
	public static function next_time( $freq, $now = null ) {
		$s    = Lifeboat_Settings::all();
		$tz   = wp_timezone();
		$now  = null === $now ? new DateTimeImmutable( 'now', $tz ) : $now->setTimezone( $tz );
		$hour = (int) $s['schedule_hour'];
		$at   = $now->setTime( $hour, 0, 0 );

		switch ( $freq ) {
			case 'twicedaily':
				$candidates = array( $at, $at->setTime( ( $hour + 12 ) % 24, 0 ), $at->modify( '+1 day' ), $at->setTime( ( $hour + 12 ) % 24, 0 )->modify( '+1 day' ) );
				usort(
					$candidates,
					function ( $a, $b ) {
						return $a <=> $b;
					}
				);
				foreach ( $candidates as $c ) {
					if ( $c > $now ) {
						return $c->getTimestamp();
					}
				}
				break;
			case 'weekly':
				$days = ( (int) $s['schedule_weekday'] - (int) $at->format( 'w' ) + 7 ) % 7;
				$t    = $at->modify( "+{$days} days" );
				if ( $t <= $now ) {
					$t = $t->modify( '+7 days' );
				}
				return $t->getTimestamp();
			case 'monthly':
				$day = (int) $s['schedule_monthday'];
				$y   = (int) $now->format( 'Y' );
				$m   = (int) $now->format( 'n' );
				$t   = $at->setDate( $y, $m, $day );
				if ( $t <= $now ) {
					$m = 12 === $m ? 1 : $m + 1;
					$y = 1 === $m ? $y + 1 : $y;
					$t = $at->setDate( $y, $m, $day );
				}
				return $t->getTimestamp();
			case 'daily':
			default:
				return ( $at > $now ? $at : $at->modify( '+1 day' ) )->getTimestamp();
		}
		return $at->modify( '+1 day' )->getTimestamp();
	}

	private static function start( $trigger, array $components, $retry_hook ) {
		if ( Lifeboat_Runner::is_active() ) {
			// Something else is running: try again in 15 minutes.
			wp_schedule_single_event( time() + 900, $retry_hook );
			return;
		}
		$job = Lifeboat_Backup::create_job( $trigger, Lifeboat_Settings::get( 'mode_scheduled' ), $components );
		Lifeboat_Runner::start( $job );
	}

	public static function run_full() {
		$s = Lifeboat_Settings::all();
		if ( 'off' !== $s['schedule'] ) {
			wp_schedule_single_event( self::next_time( $s['schedule'] ), self::FULL );
			self::start( 'schedule', (array) $s['components'], self::FULL );
		}
	}

	public static function run_db() {
		$s = Lifeboat_Settings::all();
		if ( 'off' !== $s['db_schedule'] ) {
			wp_schedule_single_event( self::next_time( $s['db_schedule'] ), self::DB );
			self::start( 'schedule-db', array( 'db' ), self::DB );
		}
	}

	public static function on_update( $upgrader, $extra ) {
		if ( ! Lifeboat_Settings::get( 'after_update' ) || empty( $extra['type'] ) || ! in_array( $extra['type'], array( 'core', 'plugin', 'theme' ), true ) ) {
			return;
		}
		if ( isset( $extra['action'] ) && 'update' !== $extra['action'] ) {
			return;
		}
		self::queue_after_update( $extra['type'] );
	}

	public static function on_auto_update( $results ) {
		if ( Lifeboat_Settings::get( 'after_update' ) && ( ! empty( $results['core'] ) || ! empty( $results['plugin'] ) || ! empty( $results['theme'] ) ) ) {
			self::queue_after_update( 'auto' );
		}
	}

	/** Debounced: a bulk update of ten plugins produces one backup, five minutes after the last one. */
	private static function queue_after_update( $what ) {
		$pending   = (array) get_option( 'lifeboat_pending_updates', array() );
		$pending[] = $what;
		update_option( 'lifeboat_pending_updates', array_slice( array_unique( $pending ), 0, 10 ), false );
		wp_clear_scheduled_hook( self::AFTER );
		wp_schedule_single_event( time() + self::DEBOUNCE, self::AFTER );
	}

	public static function run_after_update() {
		delete_option( 'lifeboat_pending_updates' );
		$s     = Lifeboat_Settings::all();
		$comps = 'full' === $s['after_update_scope'] ? (array) $s['components'] : array_values( array_intersect( array( 'db', 'plugins', 'themes' ), Lifeboat_Settings::COMPONENTS ) );
		self::start( 'update', $comps, self::AFTER );
	}
}
