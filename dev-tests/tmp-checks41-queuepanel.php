<?php
/**
 * Regression suite: the queue stopped being a black box (2.1.39).
 *
 * Tenth live report (2.1.37): «افزودن به صف و زدم، صف کجا رفت اصلا؟ هیچ جا
 * مشخص نیست، تو اتوپایلوت میره؟» — jobs went into wp_haai_queue, a cron
 * drained them silently, the only traces were four numbers at the top of the
 * article-writer page and a «در صف» badge. Nothing answered WHERE the queue
 * lives, WHO drains it (wp-cron every 5 minutes — NOT the autopilot module)
 * or WHERE drafts land.
 *
 * Ships:
 *   - Haa_Queue::stats($module) / rows($module) / job($id) / requeue($id) /
 *     purge_done() — the reader/writer API behind the new panel.
 *   - claim_next() reclaims stale 'running' rows (a tick that died mid-job
 *     used to orphan the job in 'running' FOREVER — the topic sat «در صف»).
 *   - The «صف تولید محتوا» panel in the article-writer screen + three
 *     handlers (run one now / purge done / requeue failed) + honest notices
 *     that point at the panel + live job state on queued planner rows +
 *     the «بدون دسته» double-entry fix in category_select().
 *
 * NOTE: this suite is SELF-CONTAINED on purpose — tmp-wp-stubs.php replaces
 * Haa_Queue with a fake, and here the REAL queue class is the thing under
 * test. A minimal WP function set + an in-memory wpdb for one table is the
 * whole harness.
 */

define( 'ABSPATH', __DIR__ . '/wp-stub/' );
define( 'ARRAY_A', 'ARRAY_A' );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );

$GLOBALS['qopts']    = array();
$GLOBALS['qdb']      = array( 'rows' => array(), 'next' => 0 );
$GLOBALS['q_actions'] = array();

/* ------------------------------------------------------------------ */
/* Minimal WordPress                                                   */
/* ------------------------------------------------------------------ */
function wp_parse_args( $args, $defaults = array() ) {
        return array_merge( (array) $defaults, (array) $args );
}
function current_time( $type, $gmt = false ) {
        return gmdate( 'Y-m-d H:i:s' );
}
function wp_json_encode( $v ) {
        return json_encode( $v );
}
function wp_cache_delete( $k, $g = '' ) {
        return true;
}
function get_option( $k, $d = false ) {
        return isset( $GLOBALS['qopts'][ $k ] ) ? $GLOBALS['qopts'][ $k ] : $d;
}
function update_option( $k, $v, $a = null ) {
        $GLOBALS['qopts'][ $k ] = $v;
        return true;
}
function delete_option( $k ) {
        unset( $GLOBALS['qopts'][ $k ] );
        return true;
}
function add_action( $h, $cb ) {
        $GLOBALS['q_actions'][ $h ] = $cb;
}
function has_action( $h ) {
        return ! empty( $GLOBALS['q_actions'][ $h ] );
}
function do_action( $h, ...$a ) {
        if ( ! empty( $GLOBALS['q_actions'][ $h ] ) ) {
                call_user_func( $GLOBALS['q_actions'][ $h ], ...$a );
        }
}

class Haa_DB {
        public static function table( $key ) {
                return 'wp_haai_queue';
        }
}
class Haa_Settings {
        public static function get( $key, $default = null ) {
                return $default;
        }
}

/**
 * In-memory wpdb: ONLY the queries class-haa-queue.php actually sends.
 * Branches are matched the way tmp-wp-stubs.php matches them — on the
 * query's own SQL, not on wishful parsing.
 */
class QWPDB {
        public $prefix  = 'wp_';
        public $posts   = 'wp_posts';
        public $options = 'wp_options';
        public $insert_id = 0;

        public function prepare( $q, ...$a ) {
                foreach ( $a as $v ) {
                        if ( is_int( $v ) || is_float( $v ) ) {
                                $q = preg_replace( '/%[dsf]/', (string) (int) $v, $q, 1 );
                        } else {
                                $q = preg_replace( '/%[dsf]/', "'" . addslashes( (string) $v ) . "'", $q, 1 );
                        }
                }
                return $q;
        }

        public function insert( $table, $data, $fmt = array() ) {
                $data['id'] = ++$GLOBALS['qdb']['next'];
                $data += array( 'last_error' => '', 'locked_until' => null );
                $GLOBALS['qdb']['rows'][] = $data;
                $this->insert_id = (int) $data['id'];
                return 1;
        }

        public function update( $table, $data, $where ) {
                $n = 0;
                foreach ( $GLOBALS['qdb']['rows'] as &$r ) {
                        if ( (int) $r['id'] === (int) ( $where['id'] ?? 0 ) ) {
                                foreach ( $data as $k => $v ) {
                                        $r[ $k ] = $v;
                                }
                                $n++;
                        }
                }
                return $n;
        }

        public function query( $q ) {
                $q = (string) $q;
                // claim_next's conditional UPDATE.
                if ( false !== stripos( $q, "SET status = 'running', attempts = attempts + 1" ) ) {
                        if ( ! preg_match( "/locked_until = '([^']*)', updated = '([^']*)' WHERE id = (\d+) AND status IN \('pending','running'\) AND \(locked_until IS NULL OR locked_until < '([^']*)'\)/", $q, $m ) ) {
                                return 0;
                        }
                        foreach ( $GLOBALS['qdb']['rows'] as &$r ) {
                                if ( (int) $r['id'] !== (int) $m[3] ) {
                                        continue;
                                }
                                if ( ! in_array( $r['status'], array( 'pending', 'running' ), true ) ) {
                                        return 0;
                                }
                                $lu = (string) ( $r['locked_until'] ?? '' );
                                if ( '' !== $lu && $lu >= $m[4] ) {
                                        return 0; // lock still valid — someone else's job.
                                }
                                $r['status']       = 'running';
                                $r['attempts']     = (int) $r['attempts'] + 1;
                                $r['locked_until'] = $m[1];
                                $r['updated']      = $m[2];
                                return 1;
                        }
                        return 0;
                }
                // acquire_lock's atomic INSERT IGNORE on the options table.
                if ( false !== stripos( $q, 'INSERT IGNORE INTO' ) && false !== stripos( $q, 'wp_options' ) ) {
                        if ( ! preg_match( "/VALUES \('([^']*)'/", $q, $m ) ) {
                                return 0;
                        }
                        foreach ( array_keys( $GLOBALS['qopts'] ) as $k ) {
                                if ( $k === $m[1] ) {
                                        return 0;
                                }
                        }
                        $GLOBALS['qopts'][ $m[1] ] = 'x';
                        return 1;
                }
                // purges.
                if ( preg_match( "/DELETE FROM wp_haai_queue WHERE status IN \('done','failed'\) AND updated < '([^']*)'/", $q, $m ) ) {
                        $keep = array();
                        $n    = 0;
                        foreach ( $GLOBALS['qdb']['rows'] as $r ) {
                                if ( in_array( $r['status'], array( 'done', 'failed' ), true ) && (string) $r['updated'] < $m[1] ) {
                                        $n++;
                                        continue;
                                }
                                $keep[] = $r;
                        }
                        $GLOBALS['qdb']['rows'] = $keep;
                        return $n;
                }
                if ( preg_match( "/DELETE FROM wp_haai_queue WHERE status = '(\w+)'/", $q, $m ) ) {
                        $keep = array();
                        $n    = 0;
                        foreach ( $GLOBALS['qdb']['rows'] as $r ) {
                                if ( $r['status'] === $m[1] ) {
                                        $n++;
                                        continue;
                                }
                                $keep[] = $r;
                        }
                        $GLOBALS['qdb']['rows'] = $keep;
                        return $n;
                }
                return 0;
        }

        public function get_var( $q ) {
                $q = (string) $q;
                if ( ! preg_match( '/SELECT COUNT\(\*\) FROM wp_haai_queue/', $q ) ) {
                        return 0;
                }
                $n = 0;
                foreach ( $GLOBALS['qdb']['rows'] as $r ) {
                        if ( 'pending' !== $r['status'] ) {
                                continue;
                        }
                        if ( preg_match( "/AND module = '([^']*)'/", $q, $m ) && $r['module'] !== $m[1] ) {
                                continue;
                        }
                        $n++;
                }
                return $n;
        }

        public function get_row( $q, $mode = null ) {
                $q = (string) $q;
                // job( id ).
                if ( preg_match( '/SELECT \* FROM wp_haai_queue WHERE id = (\d+)/', $q, $m ) ) {
                        foreach ( $GLOBALS['qdb']['rows'] as $r ) {
                                if ( (int) $r['id'] === (int) $m[1] ) {
                                        return $r;
                                }
                        }
                        return null;
                }
                // claim_next: pending OR stale-running, oldest by priority then id.
                if ( preg_match( "/SELECT \* FROM wp_haai_queue WHERE status IN \('pending','running'\) AND \(locked_until IS NULL OR locked_until < '([^']*)'\)/", $q, $mm ) ) {
                        $cands = array();
                        foreach ( $GLOBALS['qdb']['rows'] as $r ) {
                                if ( ! in_array( $r['status'], array( 'pending', 'running' ), true ) ) {
                                        continue;
                                }
                                $lu = (string) ( $r['locked_until'] ?? '' );
                                if ( '' !== $lu && $lu >= $mm[1] ) {
                                        continue;
                                }
                                $cands[] = $r;
                        }
                        usort( $cands, function ( $a, $b ) {
                                return array( (int) $a['priority'], (int) $a['id'] ) <=> array( (int) $b['priority'], (int) $b['id'] );
                        } );
                        return $cands ? $cands[0] : null;
                }
                return null;
        }

        public function get_results( $q, $mode = null ) {
                $q = (string) $q;
                // stats().
                if ( preg_match( '/SELECT status, COUNT\(\*\) AS c FROM wp_haai_queue/', $q ) ) {
                        $out = array();
                        foreach ( $GLOBALS['qdb']['rows'] as $r ) {
                                if ( preg_match( "/WHERE module = '([^']*)'/", $q, $m ) && $r['module'] !== $m[1] ) {
                                        continue;
                                }
                                $out[ $r['status'] ] = ( $out[ $r['status'] ] ?? 0 ) + 1;
                        }
                        $rows = array();
                        foreach ( $out as $status => $c ) {
                                $rows[] = array( 'status' => $status, 'c' => $c );
                        }
                        return $rows;
                }
                // rows( module, limit ).
                if ( preg_match( "/SELECT \* FROM wp_haai_queue WHERE module = '([^']*)' ORDER BY id DESC LIMIT (\d+)/", $q, $m ) ) {
                        $rows = array();
                        foreach ( $GLOBALS['qdb']['rows'] as $r ) {
                                if ( $r['module'] === $m[1] ) {
                                        $rows[] = $r;
                                }
                        }
                        usort( $rows, function ( $a, $b ) {
                                return (int) $b['id'] <=> (int) $a['id'];
                        } );
                        return array_slice( $rows, 0, (int) $m[2] );
                }
                return array();
        }
}
$GLOBALS['wpdb'] = new QWPDB();

function is_wp_error( $thing ) {
        return is_object( $thing ) && method_exists( $thing, 'get_error_message' );
}
function __( $s, $d = null ) {
        return $s;
}

require dirname( __DIR__ ) . '/includes/class-haa-queue.php';

$fail = 0;
$pass = 0;
function ok( $label, $cond, $extra = '' ) {
        global $fail, $pass;
        if ( $cond ) { $pass++; } else { $fail++; }
        echo ( $cond ? '  ok   ' : '  FAIL ' ) . str_pad( $label, 62 ) . $extra . "\n";
}

/* ===================================================================
 * A. push / stats / rows / job — the reader API
 * =================================================================== */
echo "=== A. push / stats / rows / job ===\n";

$id1 = Haa_Queue::push( 'aw_article', array( 'topic' => 'چطور مشخصات فرش دستباف را بررسی کنیم؟', 'topic_id' => 11 ), array( 'module' => 'article-writer' ) );
$id2 = Haa_Queue::push( 'aw_article', array( 'topic' => 'راهنمای تمیزکردن فرش دستباف', 'topic_id' => 12 ), array( 'module' => 'article-writer' ) );
$id3 = Haa_Queue::push( 'ap_post', array( 'topic' => 'کار اتوپایلوت' ), array( 'module' => 'autopilot' ) );
ok( 'A: push returns growing ids', $id1 > 0 && $id2 > $id1 && $id3 > $id2, '' );

$aw_rows = Haa_Queue::rows( 'article-writer' );
ok( 'A: rows() filters by module', 2 === count( $aw_rows ) && 'autopilot' !== $aw_rows[0]['module'], count( $aw_rows ) );
ok( 'A: rows() newest first', (int) $aw_rows[0]['id'] === $id2, (string) $aw_rows[0]['id'] );
ok( 'A: payload decoded to array', is_array( $aw_rows[0]['payload'] ) && 12 === (int) $aw_rows[0]['payload']['topic_id'], '' );

$job = Haa_Queue::job( $id1 );
ok( 'A: job() finds one by id', $job && 'چطور مشخصات فرش دستباف را بررسی کنیم؟' === (string) $job['payload']['topic'], '' );
ok( 'A: job() null when gone', null === Haa_Queue::job( 99999 ), '' );

$stats_aw = Haa_Queue::stats( 'article-writer' );
ok( 'A: stats(module) counts only that module', 2 === $stats_aw['pending'] && 0 === $stats_aw['done'] && 0 === $stats_aw['failed'], json_encode( $stats_aw, JSON_UNESCAPED_UNICODE ) );
$stats_all = Haa_Queue::stats();
ok( 'A: stats() without module still counts everything (BC)', 3 === $stats_all['pending'], (string) $stats_all['pending'] );
ok( 'A: pending() module filter', 2 === Haa_Queue::pending( 'article-writer' ) && 1 === Haa_Queue::pending( 'autopilot' ), '' );

ok( 'A: normalize_priority map', 5 === Haa_Queue::normalize_priority( 'high' ) && 10 === Haa_Queue::normalize_priority( 'normal' ) && 20 === Haa_Queue::normalize_priority( 'low' ) && 10 === Haa_Queue::normalize_priority( 'bogus' ) && 7 === Haa_Queue::normalize_priority( 7 ) && 1 === Haa_Queue::normalize_priority( 0 ) && 100 === Haa_Queue::normalize_priority( 500 ), '' );

/* ===================================================================
 * B. claim_next: order + the stale-running rescue
 * =================================================================== */
echo "=== B. claim_next ordering + stale-running reclaim ===\n";

// Lower priority number wins. Give the third article-writer job high priority.
$GLOBALS['qdb']['rows'][1]['priority'] = 5; // id2 → claimed first.
$GLOBALS['wpdb'] = $GLOBALS['wpdb'];

// A stale running row from a dead tick: locked_until far in the past.
$stale = array(
        'job' => 'aw_article', 'module' => 'article-writer', 'payload' => '{"topic":"کار یخ‌زده"}',
        'status' => 'running', 'priority' => 1, 'attempts' => 1, 'max_attempts' => 3,
        'created' => gmdate( 'Y-m-d H:i:s', time() - 3600 ), 'updated' => gmdate( 'Y-m-d H:i:s', time() - 3600 ),
        'last_error' => '', 'locked_until' => gmdate( 'Y-m-d H:i:s', time() - 900 ),
);
$GLOBALS['qdb']['rows'][] = $stale + array( 'id' => ++$GLOBALS['qdb']['next'] );
$stale_id = (int) $GLOBALS['qdb']['rows'][ count( $GLOBALS['qdb']['rows'] ) - 1 ]['id'];

// A fresh running row (someone else's live job): locked_until in the future.
$fresh = $stale;
$fresh['status']       = 'running';
$fresh['locked_until'] = gmdate( 'Y-m-d H:i:s', time() + 600 );
$fresh['payload']      = '{"topic":"کار زنده"}';
$fresh['priority']     = 0; // would win if the lock were ignored.
$GLOBALS['qdb']['rows'][] = $fresh + array( 'id' => ++$GLOBALS['qdb']['next'] );
$fresh_id = (int) $GLOBALS['qdb']['rows'][ count( $GLOBALS['qdb']['rows'] ) - 1 ]['id'];

add_action( 'haai_run_job_aw_article', function ( $job, $payload ) {
        $GLOBALS['haai_job_result'] = array( 'post_id' => 77, 'status' => 'draft' );
} );
$GLOBALS['qopts'] = array(); // clean lock.
$ran1 = Haa_Queue::tick();
ok( 'B: a stale running job is RECLAIMED (was orphaned forever)', ! empty( $ran1['ran'] ) && (int) $ran1['id'] === $stale_id, json_encode( $ran1, JSON_UNESCAPED_UNICODE ) );
ok( 'B: reclaimed job completed', ! isset( $ran1['error'] ), '' );
ok( 'B: reclaimed row is now done', 'done' === Haa_Queue::job( $stale_id )['status'], Haa_Queue::job( $stale_id )['status'] );
ok( 'B: the fresh running job was left alone (valid lock honoured)', 'running' === Haa_Queue::job( $fresh_id )['status'], Haa_Queue::job( $fresh_id )['status'] );

// Ordering: the high-priority pending job (id2) must be next, the fresh running one never.
$GLOBALS['qopts'] = array();
$ran2 = Haa_Queue::tick();
ok( 'B: priority order picks id2 (priority 5) over id1 (10)', ! empty( $ran2['ran'] ) && (int) $ran2['id'] === $id2, json_encode( $ran2, JSON_UNESCAPED_UNICODE ) );

/* ===================================================================
 * C. tick lifecycle: no listener / failure / retry cap
 * =================================================================== */
echo "=== C. tick lifecycle ===\n";

// A job whose listener is gone (module disabled mid-queue) must fail honestly.
$GLOBALS['qdb']['rows'] = array(); $GLOBALS['qdb']['next'] = 0; // C starts from an empty store.
$id_lone = Haa_Queue::push( 'aw_article', array( 'topic' => 'بدون شنونده' ), array( 'module' => 'article-writer' ) );
$GLOBALS['q_actions'] = array(); // listener gone.
$GLOBALS['qopts'] = array();
$r_lone = Haa_Queue::tick();
ok( 'C: missing listener fails the job with a message', ! empty( $r_lone['ran'] ) && false !== strpos( (string) $r_lone['error'], 'شنونده' ), (string) ( $r_lone['error'] ?? '' ) );
ok( 'C: missing-listener job retries first (bounded, error kept)', 'pending' === Haa_Queue::job( $id_lone )['status'] && false !== strpos( (string) Haa_Queue::job( $id_lone )['last_error'], 'شنونده' ), Haa_Queue::job( $id_lone )['status'] );
$GLOBALS['qopts'] = array();
Haa_Queue::tick();
$GLOBALS['qopts'] = array();
Haa_Queue::tick();
ok( 'C: missing-listener job finally fails at the cap (3)', 'failed' === Haa_Queue::job( $id_lone )['status'] && 3 === (int) Haa_Queue::job( $id_lone )['attempts'], Haa_Queue::job( $id_lone )['status'] );

// A failing listener retries, then fails at the cap.
$GLOBALS['qdb']['rows'] = array(); $GLOBALS['qdb']['next'] = 0;
$id_err = Haa_Queue::push( 'aw_article', array( 'topic' => 'خطادار' ), array( 'module' => 'article-writer', 'max_attempts' => 2 ) );
class WPErrBox {
        private $m;
        public function __construct( $m ) { $this->m = $m; }
        public function get_error_message() { return $this->m; }
}
add_action( 'haai_run_job_aw_article', function () {
        $GLOBALS['haai_job_result'] = new WPErrBox( 'سهمیهٔ مدل پر شد' );
} );
$GLOBALS['qopts'] = array();
Haa_Queue::tick();
ok( 'C: failing job goes back to pending (retry)', 'pending' === Haa_Queue::job( $id_err )['status'] . '', Haa_Queue::job( $id_err )['status'] );
$GLOBALS['qopts'] = array();
Haa_Queue::tick();
$err_state = Haa_Queue::job( $id_err );
ok( 'C: at the cap the job is failed with the error kept', 'failed' === $err_state['status'] && false !== strpos( (string) $err_state['last_error'], 'سهمیه' ), $err_state['status'] . '|' . (string) $err_state['last_error'] );
ok( 'C: attempts counted both tries', 2 === (int) $err_state['attempts'], (string) $err_state['attempts'] );

/* ===================================================================
 * D. requeue / purge_done / lock
 * =================================================================== */
echo "=== D. requeue / purge_done / lock ===\n";

ok( 'D: requeue refuses a missing/failed-not job', false === Haa_Queue::requeue( 9999 ), '' );
ok( 'D: requeue() flips failed → pending with attempts reset', true === Haa_Queue::requeue( $id_err ) && 'pending' === Haa_Queue::job( $id_err )['status'] && 0 === (int) Haa_Queue::job( $id_err )['attempts'], '' );
$GLOBALS['qopts'] = array();
$r_retry = Haa_Queue::tick(); // listener still failing → attempts 1, back to pending.
ok( 'D: a requeued job really runs again', ! empty( $r_retry['ran'] ) && (int) $r_retry['id'] === $id_err, json_encode( $r_retry, JSON_UNESCAPED_UNICODE ) );
ok( 'D: the retry failed again into pending (attempts 1)', 'pending' === Haa_Queue::job( $id_err )['status'] && 1 === (int) Haa_Queue::job( $id_err )['attempts'], '' );

// A success job for the purge test.
add_action( 'haai_run_job_aw_article', function () {
        $GLOBALS['haai_job_result'] = array( 'post_id' => 5, 'status' => 'draft' );
} );
$GLOBALS['qopts'] = array();
Haa_Queue::tick(); // attempts 2 → would fail… but the listener now succeeds → done.
ok( 'D: the same job succeeds after the listener heals', 'done' === Haa_Queue::job( $id_err )['status'], Haa_Queue::job( $id_err )['status'] );

// done rows are swept, failed survive.
$failed_row = array( 'id' => ++$GLOBALS['qdb']['next'], 'job' => 'aw_article', 'module' => 'article-writer', 'payload' => '{}', 'status' => 'failed', 'priority' => 10, 'attempts' => 3, 'max_attempts' => 3, 'created' => gmdate( 'Y-m-d H:i:s' ), 'updated' => gmdate( 'Y-m-d H:i:s' ), 'last_error' => 'x', 'locked_until' => null );
$GLOBALS['qdb']['rows'][] = $failed_row;
$failed_id = (int) $failed_row['id'];
Haa_Queue::purge_done();
ok( 'D: purge_done removes ONLY done rows', null === Haa_Queue::job( $id_err ) && 'failed' === Haa_Queue::job( $failed_id )['status'], '' );

// The mutex: a held lock blocks the tick, a stale one is broken.
$GLOBALS['qdb']['rows'] = array(); $GLOBALS['qdb']['next'] = 0;
Haa_Queue::push( 'aw_article', array( 'topic' => 'نوبتی' ), array( 'module' => 'article-writer' ) );
$GLOBALS['qopts'] = array( Haa_Queue::LOCK_OPTION => time() + 900 );
$r_locked = Haa_Queue::tick();
ok( 'D: a held lock blocks the tick', empty( $r_locked['ran'] ) && 'locked' === ( $r_locked['error'] ?? '' ), json_encode( $r_locked, JSON_UNESCAPED_UNICODE ) );
$GLOBALS['qopts'] = array( Haa_Queue::LOCK_OPTION => time() - 10 );
Haa_Queue::break_stale_lock();
ok( 'D: break_stale_lock frees an expired mutex', ! isset( $GLOBALS['qopts'][ Haa_Queue::LOCK_OPTION ] ), '' );
$GLOBALS['qopts'] = array();
$r_free = Haa_Queue::tick();
ok( 'D: the tick runs after the lock frees', ! empty( $r_free['ran'] ), '' );

/* ===================================================================
 * E. the «صف تولید محتوا» panel + handlers (source contracts)
 * =================================================================== */
echo "=== E. queue panel source contracts ===\n";

$aw = file_get_contents( dirname( __DIR__ ) . '/modules/class-haa-module-article-writer.php' );
ok( 'E: the panel exists with its anchor', false !== strpos( $aw, 'id="haai-queue-panel"' ), '' );
ok( 'E: the panel is titled «صف تولید محتوا»', false !== strpos( $aw, 'صف تولید محتوا' ), '' );
ok( 'E: the autopilot question is answered in the UI', false !== strpos( $aw, 'ربطی به اتوپایلوت ندارد' ), '' );
ok( 'E: the cron cadence is stated (هر ۵ دقیقه)', false !== strpos( $aw, 'هر ۵ دقیقه' ), '' );
ok( 'E: drafts destination is named («نوشته‌ها»)', false !== strpos( $aw, 'پیش‌نویس هر مقاله در «نوشته‌ها» ظاهر می‌شود' ), '' );
ok( 'E: next-run line from wp_next_scheduled(haai_queue_tick)', false !== strpos( $aw, "wp_next_scheduled( 'haai_queue_tick' )" ), '' );

$p1 = strpos( $aw, '$this->render_topics_form();' );
$p2 = strpos( $aw, '$this->render_queue_panel();' );
$p3 = strpos( $aw, '$this->render_research_panel();' );
ok( 'E: the panel sits between the write form and research', false !== $p1 && false !== $p2 && false !== $p3 && $p1 < $p2 && $p2 < $p3, '' );

ok( 'E: run-one is wired (admin_post + tick)', false !== strpos( $aw, "admin_post_haai_aw_run_one" ) && false !== strpos( $aw, 'Haa_Queue::tick()' ), '' );
ok( 'E: run-one raises the time limit first', false !== strpos( $aw, 'set_time_limit( 300 )' ), '' );
ok( 'E: run-one button spends tokens → Persian confirm', false !== strpos( $aw, 'data-haai-spend=' ) && false !== strpos( $aw, 'توکن مصرف می‌شود' ), '' );
ok( 'E: run-one disabled when nothing pending', false !== strpos( $aw, 'disabled( ! $stats[\'pending\'] )' ), '' );
ok( 'E: requeue wired (admin_post + Haa_Queue::requeue + «تلاش دوباره»)', false !== strpos( $aw, 'admin_post_haai_aw_requeue' ) && false !== strpos( $aw, 'Haa_Queue::requeue(' ) && false !== strpos( $aw, 'تلاش دوباره' ), '' );
ok( 'E: purge_done wired (admin_post + Haa_Queue::purge_done)', false !== strpos( $aw, 'admin_post_haai_aw_purge_done' ) && false !== strpos( $aw, 'Haa_Queue::purge_done()' ), '' );
ok( 'E: the panel has its own empty state', false !== strpos( $aw, 'صف مقاله‌نویس خالی است' ), '' );
ok( 'E: the screen cards are module-filtered now', false !== strpos( $aw, "Haa_Queue::stats( 'article-writer' )" ), '' );

/* ===================================================================
 * F. notices + planner wiring + category dedupe
 * =================================================================== */
echo "=== F. notices, planner wiring, category dedupe ===\n";

ok( 'F: handle_queue notice points at the panel', false !== strpos( $aw, 'وضعیت هر کدام را در پنل «صف تولید محتوا»' ), '' );
ok( 'F: handle_plan_queue notice points at the panel', false !== strpos( $aw, 'وضعیت هر مقاله در پنل «صف تولید محتوا»' ), '' );
ok( 'F: handle_kw_article notice points at the panel', false !== strpos( $aw, 'در پنل «صف تولید محتوا» (صفحهٔ مقاله‌نویس)' ), '' );

ok( 'F: queued planner rows read the live job', false !== strpos( $aw, "Haa_Queue::job( \$r['job_id'] )" ), '' );
ok( 'F: the sub-states are named in Persian', false !== strpos( $aw, 'منتظر نوبت' ) && false !== strpos( $aw, 'در حال ساخت' ) && false !== strpos( $aw, 'کارِ صف پیدا نشد' ), '' );

$cat_start = strpos( $aw, 'private function category_select()' );
$cat_src   = false !== $cat_start ? substr( $aw, $cat_start, 2400 ) : '';
ok( 'F: category_select skips the default term (no double «بدون دسته»)', false !== strpos( $cat_src, "get_option( 'default_category'" ) && false !== strpos( $cat_src, '=== $default' ), '' );
ok( 'F: the default option is labeled «بدون دسته (پیش‌فرض)»', false !== strpos( $cat_src, 'بدون دسته (پیش‌فرض)' ), '' );
ok( 'F: a site with no real categories is taught where to make them', false !== strpos( $cat_src, 'haai-cat-hint' ) && false !== strpos( $cat_src, 'دسته‌ها' ), '' );

ok( 'F: the guide strip kept its 2.1.36 anchors', false !== strpos( $aw, 'مسیر پیشنهادی' ) && false !== strpos( $aw, 'ساخت مقاله برای انتخاب‌شده‌ها' ), '' );
ok( 'F: the guide strip now ends at the queue panel', false !== strpos( $aw, 'در پنل «صف تولید محتوا» همین پایین ردیف دارد' ), '' );

/* ===================================================================
 * G. queue source: the reclaim SQL exists in BOTH statements
 * =================================================================== */
echo "=== G. claim_next reclaim SQL ===\n";

$q_src = file_get_contents( dirname( __DIR__ ) . '/includes/class-haa-queue.php' );
ok( 'G: SELECT claims pending AND stale running', 1 === substr_count( $q_src, "WHERE status IN ('pending','running') AND (locked_until IS NULL OR locked_until < %s) ORDER BY priority ASC, id ASC LIMIT 1" ), '' );
ok( 'G: the conditional UPDATE claims the same set', 1 === substr_count( $q_src, "WHERE id = %d AND status IN ('pending','running') AND (locked_until IS NULL OR locked_until < %s)" ), '' );
ok( 'G: stats/rows/job/requeue/purge_done all exist', preg_match( '/function stats\( \$module = null \)/', $q_src ) && preg_match( '/function rows\( \$module, \$limit = 12 \)/', $q_src ) && preg_match( '/function job\( \$id \)/', $q_src ) && preg_match( '/function requeue\( \$id \)/', $q_src ) && preg_match( '/function purge_done\(\)/', $q_src ), '' );

echo "\n--- suite 41: queue visibility (2.1.39) ---\n";
echo "checks: " . ( $pass + $fail ) . "  FAIL=$fail\n";
exit( $fail ? 1 : 0 );
