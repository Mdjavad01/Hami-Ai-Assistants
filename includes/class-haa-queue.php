<?php
/**
 * A deliberately small job queue for work that must never run inside a
 * visitor's request (long generations, embedding batches, autopilot posts).
 *
 * Design goals for a 128 MB shared host:
 *  - one job per tick, never parallel;
 *  - a lock so WP-Cron and the real host cron cannot collide;
 *  - bounded retries with a backoff, then `failed` with the error kept.
 *
 * @package Hami_AI_Assistant
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Haa_Queue {

	const LOCK_OPTION = 'haai_queue_lock';

	/**
	 * Cron entry points for the drain. `haai_queue_tick` is the core 5-minute
	 * event scheduled by Haa_Plugin::ensure_cron(); `haai_aw_tick` is the
	 * legacy event the article-writer's activate() queues — both run the same
	 * bounded batch so no job can ever wait for a page load.
	 *
	 * @return void
	 */
	public static function hooks() {
		add_action( 'haai_queue_tick', array( __CLASS__, 'run_tick' ) );
		add_action( 'haai_aw_tick', array( __CLASS__, 'run_tick' ) );
	}

	/**
	 * Cron callback: clear a stale mutex, then drain a few jobs. The batch is
	 * bounded so a default 30-second WP-Cron request can never time out.
	 *
	 * @return int Jobs run.
	 */
	public static function run_tick() {
		self::break_stale_lock();
		return self::drain( 3 );
	}

	/**
	 * Map a priority slug (or int) to the numeric column value.
	 * Lower number = earlier execution. Invalid values fall back to 10 (normal).
	 *
	 * @param mixed $priority Raw priority.
	 * @return int
	 */
	public static function normalize_priority( $priority ) {
		if ( is_int( $priority ) || ( is_string( $priority ) && ctype_digit( (string) $priority ) ) ) {
			$n = (int) $priority;
			return max( 1, min( 100, $n ) );
		}
		$map = array(
			'high'   => 5,
			'normal' => 10,
			'low'    => 20,
		);
		$key = strtolower( trim( (string) $priority ) );
		return isset( $map[ $key ] ) ? $map[ $key ] : 10;
	}

	/**
	 * Enqueue a job.
	 *
	 * @param string $job      Job slug, dispatched via the `haai_run_job_{job}` action.
	 * @param array  $payload  Arbitrary JSON-serializable data.
	 * @param array  $args     module, priority (int|'high'|'normal'|'low'), max_attempts.
	 * @return int Job id.
	 */
	public static function push( $job, $payload = array(), $args = array() ) {
		global $wpdb;
		$args = wp_parse_args(
			$args,
			array(
				'module'       => 'core',
				'priority'     => 10,
				'max_attempts' => 3,
			)
		);

		$now = current_time( 'mysql', true );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->insert(
			Haa_DB::table( 'queue' ),
			array(
				'job'          => substr( (string) $job, 0, 60 ),
				'module'       => substr( (string) $args['module'], 0, 40 ),
				'payload'      => wp_json_encode( $payload ),
				'status'       => 'pending',
				'priority'     => self::normalize_priority( $args['priority'] ),
				'attempts'     => 0,
				'max_attempts' => max( 1, min( 10, (int) $args['max_attempts'] ) ),
				'created'      => $now,
				'updated'      => $now,
			),
			array( '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%s', '%s' )
		);
		return (int) $wpdb->insert_id;
	}

	/**
	 * How many jobs are waiting.
	 *
	 * @param string|null $module Optional filter.
	 * @return int
	 */
	public static function pending( $module = null ) {
		global $wpdb;
		$table = Haa_DB::table( 'queue' );
		if ( $module ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $table WHERE status = 'pending' AND module = %s", $module ) );
		}
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table WHERE status = 'pending'" );
	}

	/**
	 * Run exactly one job and return what happened.
	 *
	 * @return array{ran:bool,job?:string,id?:int,error?:string,done?:int}
	 */
	public static function tick() {
		if ( ! self::acquire_lock() ) {
			return array( 'ran' => false, 'error' => 'locked' );
		}

		try {
			return self::tick_once();
		} finally {
			self::release_lock();
		}
	}

	/**
	 * Claim and run one job. Caller must already hold the lock.
	 *
	 * @return array{ran:bool,job?:string,id?:int,error?:string,done?:int}
	 */
	private static function tick_once() {
		$job = self::claim_next();
		if ( ! $job ) {
			return array( 'ran' => false, 'done' => self::pending() );
		}

		// A job whose listener is gone (module disabled mid-queue) must not
		// be silently marked done — fail it immediately with a clear message.
		if ( ! has_action( 'haai_run_job_' . $job['job'] ) ) {
			$msg = sprintf(
				/* translators: %s: job slug. */
				__( 'هیچ شنونده‌ای برای job «%s» ثبت نشده است (ماژول ممکن است غیرفعال شده باشد).', 'hami-ai-assistant' ),
				(string) $job['job']
			);
			self::fail( (int) $job['id'], $msg, (int) $job['attempts'], (int) $job['max_attempts'], (string) $job['module'] );
			return array( 'ran' => true, 'job' => $job['job'], 'id' => (int) $job['id'], 'error' => $msg, 'done' => self::pending() );
		}

		$payload = json_decode( (string) $job['payload'], true );
		if ( ! is_array( $payload ) ) {
			$payload = array();
		}

		/**
		 * Fires for one queued job. Listeners must do a bounded amount of
		 * work and signal failure via $GLOBALS['haai_job_result'] (WP_Error).
		 *
		 * @param array $job     Row (id, job, module, payload, attempts).
		 * @param array $payload Decoded payload.
		 */
		do_action( 'haai_run_job_' . $job['job'], $job, $payload );

		$box = isset( $GLOBALS['haai_job_result'] ) ? $GLOBALS['haai_job_result'] : null;
		unset( $GLOBALS['haai_job_result'] );

		if ( is_wp_error( $box ) ) {
			self::fail( (int) $job['id'], $box->get_error_message(), (int) $job['attempts'], (int) $job['max_attempts'], (string) $job['module'] );
			return array( 'ran' => true, 'job' => $job['job'], 'id' => (int) $job['id'], 'error' => $box->get_error_message(), 'done' => self::pending() );
		}

		self::complete( (int) $job['id'] );
		return array( 'ran' => true, 'job' => $job['job'], 'id' => (int) $job['id'], 'done' => self::pending() );
	}

	/**
	 * Drain the queue up to $max jobs. The lock is acquired once for the
	 * whole batch and refreshed after each tick so a slow batch cannot
	 * lose the mutex mid-run. If the process dies fatally (e.g. OOM) the
	 * finally block does NOT run, but the lock's TTL (autopilot.lock_ttl,
	 * default 900 s) guarantees the mutex frees itself on the next cron
	 * pass via break_stale_lock().
	 *
	 * @param int $max Jobs per invocation.
	 * @return int How many ran.
	 */
	public static function drain( $max = 3 ) {
		if ( ! self::acquire_lock() ) {
			return 0;
		}
		$ran = 0;
		try {
			for ( $i = 0; $i < max( 1, min( 20, (int) $max ) ); $i++ ) {
				$res = self::tick_once();
				if ( empty( $res['ran'] ) ) {
					break;
				}
				$ran++;
				// Refresh the TTL so a 3-job batch that takes longer than
				// the original lock window does not free the mutex early.
				self::refresh_lock();
			}
		} finally {
			self::release_lock();
		}
		return $ran;
	}

	/**
	 * Atomically claim the oldest runnable job.
	 *
	 * The UPDATE is conditional on the row still being pending and
	 * unlocked, so only one process can succeed even if two SELECT the
	 * same row simultaneously. Returns null if another process won the race.
	 *
	 * @return array|null
	 */
	private static function claim_next() {
		global $wpdb;
		$table = Haa_DB::table( 'queue' );
		$now   = current_time( 'mysql', true );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM $table WHERE status = 'pending' AND (locked_until IS NULL OR locked_until < %s) ORDER BY priority ASC, id ASC LIMIT 1",
				$now
			),
			ARRAY_A
		);
		if ( ! $row ) {
			return null;
		}

		$locked_until = gmdate( 'Y-m-d H:i:s', time() + 15 * MINUTE_IN_SECONDS );

		// Conditional UPDATE: only succeeds if the row is still pending
		// and unlocked. $wpdb->query() returns affected rows — 0 means
		// another process claimed it first.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$affected = $wpdb->query(
			$wpdb->prepare(
				"UPDATE $table SET status = 'running', attempts = attempts + 1, locked_until = %s, updated = %s WHERE id = %d AND status = 'pending' AND (locked_until IS NULL OR locked_until < %s)",
				$locked_until,
				$now,
				(int) $row['id'],
				$now
			)
		);

		if ( ! $affected ) {
			// Another process claimed it between our SELECT and UPDATE.
			return null;
		}

		$row['attempts'] = (int) $row['attempts'] + 1;
		return $row;
	}

	/**
	 * @param int $id Job id.
	 */
	private static function complete( $id ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->update(
			Haa_DB::table( 'queue' ),
			array(
				'status'       => 'done',
				'locked_until' => null,
				'updated'      => current_time( 'mysql', true ),
			),
			array( 'id' => $id ),
			array( '%s', '%s', '%s' ),
			array( '%d' )
		);
	}

	/**
	 * @param int    $id           Job id.
	 * @param string $error        Message.
	 * @param int    $attempts     Attempts so far.
	 * @param int    $max_attempts Cap.
	 * @param string $module       Owning module slug.
	 */
	private static function fail( $id, $error, $attempts, $max_attempts, $module = '' ) {
		global $wpdb;
		$cap = max( 1, min( 10, (int) $max_attempts ) );

		// Autopilot jobs honour the module's own retry_limit on top of the
		// per-job cap; every other module just uses its max_attempts.
		if ( 'autopilot' === $module ) {
			$retries  = (int) Haa_Settings::get( 'autopilot.retry_limit', 2 );
			$cap      = min( $cap, $retries + 1 );
		}
		$can_retry = $attempts < $cap;

		$wpdb->update(
			Haa_DB::table( 'queue' ),
			array(
				'status'       => $can_retry ? 'pending' : 'failed',
				'locked_until' => null,
				'last_error'   => substr( (string) $error, 0, 2000 ),
				'updated'      => current_time( 'mysql', true ),
			),
			array( 'id' => $id ),
			array( '%s', '%s', '%s', '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Simple mutex. Held for the duration of one tick only.
	 *
	 * Uses INSERT IGNORE for truly atomic acquisition: returns 1 only if
	 * the row was actually inserted (no one else holds the lock). This
	 * avoids the TOCTOU race in add_option() which checks get_option()
	 * first then INSERTs — two processes could both pass the check.
	 *
	 * @return bool
	 */
	private static function acquire_lock() {
		global $wpdb;
		$ttl = max( 60, (int) Haa_Settings::get( 'autopilot.lock_ttl', 900 ) );
		// A tick that died mid-run must not wedge the queue forever: drop the
		// expired mutex before trying to claim a fresh one.
		self::break_stale_lock();

		// INSERT IGNORE is atomic at the DB level: returns 1 if inserted,
		// 0 if the row already exists (unique key on option_name).
		$result = $wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO `$wpdb->options` (`option_name`, `option_value`, `autoload`) VALUES (%s, %s, %s)",
				self::LOCK_OPTION,
				time() + $ttl,
				'no'
			)
		);

		if ( 1 === (int) $result ) {
			// Invalidate cache so subsequent get_option() sees the lock.
			wp_cache_delete( self::LOCK_OPTION, 'options' );
			wp_cache_delete( 'notoptions', 'options' );
			return true;
		}

		return false;
	}

	private static function release_lock() {
		delete_option( Haa_Queue::LOCK_OPTION );
	}

	/**
	 * Extend the lock TTL (called after each tick in drain()).
	 *
	 * @return void
	 */
	private static function refresh_lock() {
		$ttl = (int) Haa_Settings::get( 'autopilot.lock_ttl', 900 );
		update_option( Haa_Queue::LOCK_OPTION, time() + $ttl, false );
	}

	/**
	 * Clear a stale lock (safety valve if a tick crashed mid-run).
	 */
	public static function break_stale_lock() {
		$held = (int) get_option( self::LOCK_OPTION, 0 );
		if ( $held && $held < time() ) {
			delete_option( self::LOCK_OPTION );
		}
	}

	/**
	 * Queue status for the dashboard.
	 *
	 * @return array<string,int>
	 */
	public static function stats() {
		global $wpdb;
		$table = Haa_DB::table( 'queue' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( "SELECT status, COUNT(*) AS c FROM $table GROUP BY status", ARRAY_A );
		$out  = array( 'pending' => 0, 'running' => 0, 'done' => 0, 'failed' => 0 );
		foreach ( (array) $rows as $r ) {
			$out[ $r['status'] ] = (int) $r['c'];
		}
		return $out;
	}

	/**
	 * Drop finished and failed rows older than $days. Keeps the table small
	 * on a host where nobody will ever open phpMyAdmin.
	 *
	 * @param int $days Age in days.
	 * @return int Rows removed.
	 */
	public static function purge( $days = 7 ) {
		global $wpdb;
		$table = Haa_DB::table( 'queue' );
		$days  = max( 1, (int) $days );
		// `updated` is stored as UTC via current_time('mysql', true); compare
		// against a UTC cutoff, not MySQL NOW() which follows the server clock.
		$before = gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return (int) $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM $table WHERE status IN ('done','failed') AND updated < %s",
				$before
			)
		);
	}

	/**
	 * Clear every failed row so an admin can retry from a clean slate.
	 *
	 * @return int Rows removed.
	 */
	public static function purge_failed() {
		global $wpdb;
		$table = Haa_DB::table( 'queue' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return (int) $wpdb->query( "DELETE FROM $table WHERE status = 'failed'" );
	}
}
