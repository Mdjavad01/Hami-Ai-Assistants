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
         * Cron callback: clear a stale mutex, revive article jobs whose final
         * failure is old enough for the scheduled retry (2.1.42), then drain
         * a few jobs. The batch is bounded so a default 30-second WP-Cron
         * request can never time out.
         *
         * @return int Jobs run.
         */
        public static function run_tick() {
                self::break_stale_lock();
                self::revive_failed();
                return self::drain( 3 );
        }

        /**
         * Normalize a topic for duplicate comparison. Persian text needs more
         * than mb_strtolower: the ZWNJ («نیم‌فاصله») comes and goes between
         * copy-pastes, Arabic ي/ك and Persian ی/ک look identical but hash
         * differently, and a trailing «؟» is punctuation, not a different
         * subject.
         *
         * @param string $topic Raw topic/title.
         * @return string
         */
        public static function normalize_topic( $topic ) {
                $t = (string) $topic;
                $t = str_replace( "\xE2\x80\x8C", '', $t ); // ZWNJ.
                $t = str_replace( array( 'ي', 'ك', 'ۀ' ), array( 'ی', 'ک', 'ه' ), $t );
                $t = mb_strtolower( $t, 'UTF-8' );
                $t = preg_replace( '/[\x{064B}-\x{065F}\x{0670}]/u', '', $t ); // diacritics.
                $t = preg_replace( '/\s+/u', ' ', $t );
                $t = trim( $t );
                $t = preg_replace( '/[\s\p{P}]+$/u', '', $t ); // trailing ؟ ? ! . ؛ ، :
                return trim( $t );
        }

        /**
         * 2.1.42: has this subject already been sent to the queue or written?
         *
         * The live report was «صف تولید محتوا از یه مقاله دو تا تولید کرده» —
         * the same topic built twice, because every enqueue path (manual box,
         * planner bulk, keyword panel) pushed without looking at what was
         * already pending/done, the planner's title check was byte-exact
         * (a missing «؟» created a second row), and deleting a queued planner
         * row left its queue job alive. Every enqueue path now asks here
         * first: one normalized comparison against the module's own queue
         * rows AND against the site's existing posts.
         *
         * @param string $topic  Raw topic or title.
         * @param string $module Owning module slug.
         * @return bool True when an active/finished row or a post already carries this subject.
         */
        public static function topic_taken( $topic, $module = 'article-writer' ) {
                global $wpdb;
                $norm = self::normalize_topic( $topic );
                if ( '' === $norm ) {
                        return false;
                }

                // Queue rows of this module (payload carries the topic). The table
                // is purged after 7 days, so a bounded scan is cheap.
                $table = Haa_DB::table( 'queue' );
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                $rows = $wpdb->get_results( $wpdb->prepare( "SELECT payload FROM $table WHERE module = %s AND job = 'aw_article' ORDER BY id DESC LIMIT 100", $module ), ARRAY_A );
                foreach ( (array) $rows as $r ) {
                        $payload = json_decode( (string) ( $r['payload'] ?? '' ), true );
                        $have    = is_array( $payload ) ? (string) ( $payload['topic'] ?? '' ) : '';
                        if ( '' !== $have && self::normalize_topic( $have ) === $norm ) {
                                return true;
                        }
                }

                // Existing posts with the same title (any state but trash) — the
                // duplicate the admin actually sees twice in «نوشته‌ها».
                $needle = '%' . $wpdb->esc_like( mb_substr( $norm, 0, 25 ) ) . '%';
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery
                $posts = $wpdb->get_results(
                        $wpdb->prepare(
                                "SELECT post_title FROM {$wpdb->posts} WHERE post_type = 'post' AND post_status IN ('publish','draft','pending','future','private') AND post_title LIKE %s LIMIT 30",
                                $needle
                        ),
                        ARRAY_A
                );
                foreach ( (array) $posts as $p ) {
                        if ( self::normalize_topic( (string) $p['post_title'] ) === $norm ) {
                                return true;
                        }
                }

                return false;
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

                // 2.1.38: 'running' rows whose lock expired are reclaimed too. A tick
                // that died mid-generation (OOM, proxy cut) used to orphan its job in
                // 'running' FOREVER — claim_next only ever looked at 'pending', so the
                // topic sat «در صف» with nothing happening. The expired locked_until
                // is the admission ticket, same as it always was for the mutex.
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                $row = $wpdb->get_row(
                        $wpdb->prepare(
                                "SELECT * FROM $table WHERE status IN ('pending','running') AND (locked_until IS NULL OR locked_until < %s) ORDER BY priority ASC, id ASC LIMIT 1",
                                $now
                        ),
                        ARRAY_A
                );
                if ( ! $row ) {
                        return null;
                }

                $locked_until = gmdate( 'Y-m-d H:i:s', time() + 15 * MINUTE_IN_SECONDS );

                // Conditional UPDATE: only succeeds if the row is still claimable
                // (pending, or running with an expired lock). $wpdb->query() returns
                // affected rows — 0 means another process claimed it first.
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                $affected = $wpdb->query(
                        $wpdb->prepare(
                                "UPDATE $table SET status = 'running', attempts = attempts + 1, locked_until = %s, updated = %s WHERE id = %d AND status IN ('pending','running') AND (locked_until IS NULL OR locked_until < %s)",
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

                // 2.1.42: article retries get a spacing. The live report was
                // «برنامه و زمانبندی نداریم؟» — three attempts fired within ten
                // minutes (cron every 5), so a one-hour provider hiccup burned the
                // whole cap and left the topic permanently failed. A pending row
                // with a future locked_until is invisible to claim_next, which is
                // exactly a "not before" schedule without a schema change.
                $retry_in = 0;
                if ( $can_retry && 'article-writer' === $module ) {
                        $retry_in = max( 0, min( 120, (int) Haa_Settings::get( 'article.retry_backoff_minutes', 10 ) ) );
                }

                $wpdb->update(
                        Haa_DB::table( 'queue' ),
                        array(
                                'status'       => $can_retry ? 'pending' : 'failed',
                                'locked_until' => $can_retry ? ( $retry_in ? gmdate( 'Y-m-d H:i:s', time() + $retry_in * MINUTE_IN_SECONDS ) : null ) : null,
                                'last_error'   => substr( (string) $error, 0, 2000 ),
                                'updated'      => current_time( 'mysql', true ),
                        ),
                        array( 'id' => $id ),
                        array( '%s', '%s', '%s', '%s' ),
                        array( '%d' )
                );
        }

        /**
         * 2.1.42: the scheduled second chance. A failed article job whose last
         * attempt is old enough (article.auto_retry_hours, default 2) is put
         * back in line ONCE — payload gains _revived so a job can never loop
         * forever on a topic the model simply cannot write. Called from
         * run_tick() before the drain; bounded to two revivals per tick.
         *
         * @return int Rows revived.
         */
        public static function revive_failed() {
                global $wpdb;
                $hours = (int) Haa_Settings::get( 'article.auto_retry_hours', 2 );
                if ( $hours <= 0 ) {
                        return 0;
                }

                $table   = Haa_DB::table( 'queue' );
                $cutoff  = gmdate( 'Y-m-d H:i:s', time() - $hours * HOUR_IN_SECONDS );
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                $rows    = $wpdb->get_results(
                        $wpdb->prepare(
                                "SELECT * FROM $table WHERE module = 'article-writer' AND job = 'aw_article' AND status = 'failed' AND updated < %s ORDER BY id ASC LIMIT 5",
                                $cutoff
                        ),
                        ARRAY_A
                );

                $revived = 0;
                foreach ( (array) $rows as $row ) {
                        if ( $revived >= 2 ) {
                                break;
                        }
                        $payload = json_decode( (string) ( $row['payload'] ?? '' ), true );
                        if ( ! is_array( $payload ) ) {
                                $payload = array();
                        }
                        if ( ! empty( $payload['_revived'] ) ) {
                                continue;
                        }
                        $payload['_revived'] = 1;
                        $wpdb->update(
                                $table,
                                array(
                                        'status'       => 'pending',
                                        'attempts'     => 0,
                                        'locked_until' => null,
                                        'payload'      => wp_json_encode( $payload ),
                                        'updated'      => current_time( 'mysql', true ),
                                ),
                                array( 'id' => (int) $row['id'] ),
                                array( '%s', '%d', '%s', '%s', '%s' ),
                                array( '%d' )
                        );
                        $revived++;
                }
                return $revived;
        }

        /**
         * 2.1.42: remove one row, whatever its state. The escape hatch for the
         * dedup: a blocked «این موضوع قبلاً صف شده» tells the admin to delete
         * the row they no longer want, so re-queuing the subject becomes
         * possible again. Deleting a planner row must also remove its pending
         * job — the orphaned job is what built the duplicate article.
         *
         * @param int $id Job id.
         * @return bool Whether a row was deleted.
         */
        public static function delete( $id ) {
                global $wpdb;
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery
                return (bool) $wpdb->delete( Haa_DB::table( 'queue' ), array( 'id' => (int) $id ), array( '%d' ) );
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
         * 2.1.38: an optional module filter so the article-writer screen can
         * show ITS OWN numbers — the page used to print the counts of every
         * module's jobs together, and an admin who queued three articles saw
         * numbers that matched nothing on the page.
         *
         * @param string|null $module Optional module slug filter.
         * @return array<string,int>
         */
        public static function stats( $module = null ) {
                global $wpdb;
                $table = Haa_DB::table( 'queue' );
                if ( $module ) {
                        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                        $rows = $wpdb->get_results( $wpdb->prepare( "SELECT status, COUNT(*) AS c FROM $table WHERE module = %s GROUP BY status", $module ), ARRAY_A );
                } else {
                        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                        $rows = $wpdb->get_results( "SELECT status, COUNT(*) AS c FROM $table GROUP BY status", ARRAY_A );
                }
                $out = array( 'pending' => 0, 'running' => 0, 'done' => 0, 'failed' => 0 );
                foreach ( (array) $rows as $r ) {
                        $out[ $r['status'] ] = (int) $r['c'];
                }
                return $out;
        }

        /**
         * Recent jobs of one module, newest first — the visible queue.
         *
         * 2.1.38: the queue used to be a black box; this is the reader behind
         * the «صف تولید محتوا» panel. Payload is decoded so the panel can name
         * the topic without knowing the JSON spelling.
         *
         * @param string $module Module slug.
         * @param int    $limit  Row cap.
         * @return array<int,array>
         */
        public static function rows( $module, $limit = 12 ) {
                global $wpdb;
                $table = Haa_DB::table( 'queue' );
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                $raw = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $table WHERE module = %s ORDER BY id DESC LIMIT %d", $module, max( 1, min( 50, (int) $limit ) ) ), ARRAY_A );
                return array_map( array( __CLASS__, 'hydrate' ), (array) $raw );
        }

        /**
         * One job by id, payload decoded. Null when gone.
         *
         * @param int $id Job id.
         * @return array|null
         */
        public static function job( $id ) {
                global $wpdb;
                $table = Haa_DB::table( 'queue' );
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE id = %d", (int) $id ), ARRAY_A );
                return $row ? self::hydrate( (array) $row ) : null;
        }

        /**
         * Normalized job row: numbers as numbers, payload as array.
         *
         * @param array $r Raw row.
         * @return array
         */
        private static function hydrate( $r ) {
                $payload = json_decode( (string) ( $r['payload'] ?? '' ), true );
                $r['payload'] = is_array( $payload ) ? $payload : array();
                $r['id']           = (int) $r['id'];
                $r['priority']     = (int) $r['priority'];
                $r['attempts']     = (int) $r['attempts'];
                $r['max_attempts'] = (int) $r['max_attempts'];
                return $r;
        }

        /**
         * Put a failed job back in line (admin pressed «تلاش دوباره»).
         *
         * attempts resets, otherwise fail() would flip it straight back to
         * failed — the cap compares attempts to max_attempts.
         *
         * @param int $id Job id.
         * @return bool Whether a failed row was found and requeued.
         */
        public static function requeue( $id ) {
                global $wpdb;
                $job = self::job( $id );
                if ( ! $job || 'failed' !== $job['status'] ) {
                        return false;
                }
                $wpdb->update(
                        Haa_DB::table( 'queue' ),
                        array(
                                'status'     => 'pending',
                                'attempts'   => 0,
                                'last_error' => '',
                                'updated'    => current_time( 'mysql', true ),
                        ),
                        array( 'id' => (int) $id ),
                        array( '%s', '%d', '%s', '%s' ),
                        array( '%d' )
                );
                return true;
        }

        /**
         * Drop every finished job (the panel's «پاک‌سازی» button).
         *
         * @return int Rows removed.
         */
        public static function purge_done() {
                global $wpdb;
                $table = Haa_DB::table( 'queue' );
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery
                return (int) $wpdb->query( "DELETE FROM $table WHERE status = 'done'" );
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
