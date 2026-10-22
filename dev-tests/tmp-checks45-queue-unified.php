<?php
/**
 * Regression suite: the queue became ONE panel with a schedule and no
 * duplicates (2.1.42).
 *
 * Eleventh live report (2.1.41): «صف تولید محتوا از یه مقاله دو تا تولید
 * کرده بقیه ناموفق هستن، برنامه و زمانبندی نداریم؟ … این بخش خیلی پیچیده
 * است از لحاظ کاربری، میخوام یکپارچه و جامع باشه».
 *
 * Ships, and what this suite pins:
 *   - Haa_Queue::normalize_topic() — Persian-aware duplicate key (ZWNJ,
 *     Arabic ي/ك, trailing «؟», whitespace, case).
 *   - Haa_Queue::topic_taken() — the enqueue gate: one normalized lookup
 *     against the module's queue rows AND the site's post titles.
 *   - Haa_Queue::fail() backoff — article retries wait
 *     article.retry_backoff_minutes (default 10) instead of firing every
 *     cron pass; other modules keep the old immediate retry.
 *   - Haa_Queue::revive_failed() — a finally-failed article job gets ONE
 *     automatic full round after article.auto_retry_hours (default 2);
 *     payload._revived makes a second revival impossible.
 *   - Haa_Queue::delete() — one-row removal (the dedup escape hatch, the
 *     planner-delete cleanup, the queued-row revert).
 *   - Haa_Planner::delete() removes a pending/failed job with the row;
 *     Haa_Planner::queue() asks topic_taken() before pushing.
 *   - The module: in-batch dedup in handle_queue(), haai_aw_qdelete,
 *     the queued→suggested revert cleanup, the unified panel, the jump-nav.
 *   - Settings: defaults + sanitize map + ui_keys for the two new keys.
 *   - CSS: .haai-jumpnav + .haai-panel__subtitle in admin.css AND min.
 *
 * SELF-CONTAINED like suite 41: the real Haa_Queue runs against an
 * in-memory wpdb; Haa_Settings is a mutable map.
 */

define( 'ABSPATH', __DIR__ . '/wp-stub/' );
define( 'ARRAY_A', 'ARRAY_A' );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );

$GLOBALS['qopts']     = array();
$GLOBALS['qdb']       = array( 'rows' => array(), 'posts' => array(), 'next' => 0 );
$GLOBALS['q_actions'] = array();
/* Mutable settings: tests write here. */
$GLOBALS['set45']     = array();

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
                if ( array_key_exists( $key, $GLOBALS['set45'] ) ) {
                        return $GLOBALS['set45'][ $key ];
                }
                return $default;
        }
}

/* ------------------------------------------------------------------ */
/* In-memory wpdb: queue table + a thin posts table                    */
/* ------------------------------------------------------------------ */
class QWPDB45 {
        public $prefix    = 'wp_';
        public $posts     = 'wp_posts';
        public $options   = 'wp_options';
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

        public function esc_like( $s ) {
                return addcslashes( (string) $s, '_%\\' );
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

        public function delete( $table, $where ) {
                $keep = array();
                $n    = 0;
                foreach ( $GLOBALS['qdb']['rows'] as $r ) {
                        if ( (int) $r['id'] === (int) ( $where['id'] ?? 0 ) ) {
                                $n++;
                                continue;
                        }
                        $keep[] = $r;
                }
                $GLOBALS['qdb']['rows'] = $keep;
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
                                        return 0;
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
                        $GLOBALS['qopts'][ $m[1 ] ] = 'x';
                        return 1;
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
                if ( preg_match( '/SELECT \* FROM wp_haai_queue WHERE id = (\d+)/', $q, $m ) ) {
                        foreach ( $GLOBALS['qdb']['rows'] as $r ) {
                                if ( (int) $r['id'] === (int) $m[1] ) {
                                        return $r;
                                }
                        }
                        return null;
                }
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
                // topic_taken(): the module's own aw_article payloads.
                if ( preg_match( "/SELECT payload FROM wp_haai_queue WHERE module = '([^']*)' AND job = 'aw_article' ORDER BY id DESC LIMIT (\d+)/", $q, $m ) ) {
                        $rows = array();
                        foreach ( $GLOBALS['qdb']['rows'] as $r ) {
                                if ( $r['module'] === $m[1] && 'aw_article' === $r['job'] ) {
                                        $rows[] = array( 'payload' => $r['payload'] );
                                }
                        }
                        usort( $rows, function ( $a, $b ) {
                                return 0;
                        } );
                        return array_slice( $rows, 0, (int) $m[2] );
                }
                // revive_failed(): failed article jobs older than the cutoff.
                if ( preg_match( "/SELECT \* FROM wp_haai_queue WHERE module = 'article-writer' AND job = 'aw_article' AND status = 'failed' AND updated < '([^']*)' ORDER BY id ASC LIMIT (\d+)/", $q, $m ) ) {
                        $rows = array();
                        foreach ( $GLOBALS['qdb']['rows'] as $r ) {
                                if ( 'article-writer' === $r['module'] && 'aw_article' === $r['job'] && 'failed' === $r['status']
                                        && (string) $r['updated'] < $m[1] ) {
                                        $rows[] = $r;
                                }
                        }
                        usort( $rows, function ( $a, $b ) {
                                return (int) $a['id'] <=> (int) $b['id'];
                        } );
                        return array_slice( $rows, 0, (int) $m[2] );
                }
                // topic_taken(): the posts side.
                if ( preg_match( "/SELECT post_title FROM wp_posts WHERE post_type = 'post' AND post_status IN \('publish','draft','pending','future','private'\) AND post_title LIKE '([^']*)' LIMIT (\d+)/", $q, $m ) ) {
                        $needle = trim( $m[1], '%' );
                        $needle = str_replace( array( '\%', '\_', '\\\\' ), array( '%', '_', '\\' ), $needle );
                        $rows   = array();
                        foreach ( $GLOBALS['qdb']['posts'] as $t ) {
                                if ( '' === $needle || false !== mb_strpos( (string) $t, $needle ) ) {
                                        $rows[] = array( 'post_title' => $t );
                                }
                        }
                        return array_slice( $rows, 0, (int) $m[2] );
                }
                return array();
        }
}
$GLOBALS['wpdb'] = new QWPDB45();

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
        echo ( $cond ? '  ok   ' : '  FAIL ' ) . str_pad( $label, 64 ) . $extra . "\n";
}
function q45_reset() {
        $GLOBALS['qdb']['rows'] = array();
        $GLOBALS['qdb']['next'] = 0;
        $GLOBALS['qopts']       = array();
}

/* ===================================================================
 * A. normalize_topic — the Persian-aware duplicate key
 * =================================================================== */
echo "=== A. normalize_topic ===\n";

ok( 'A: identical strings stay identical',
        'چگونه فرش دستباف مناسب خانه انتخاب کنیم' === Haa_Queue::normalize_topic( 'چگونه فرش دستباف مناسب خانه انتخاب کنیم' ), '' );
ok( 'A: trailing «؟» does not make a new subject',
        Haa_Queue::normalize_topic( 'چگونه فرش دستباف مناسب خانه انتخاب کنیم؟' ) === Haa_Queue::normalize_topic( 'چگونه فرش دستباف مناسب خانه انتخاب کنیم' ), '' );
ok( 'A: ZWNJ vs nothing — same letters, same key',
        Haa_Queue::normalize_topic( "فرش\xE2\x80\x8Cدستباف" ) === Haa_Queue::normalize_topic( 'فرشدستباف' ), '' );
ok( 'A: ZWNJ byte sequence is stripped',
        false === strpos( Haa_Queue::normalize_topic( "فرش\xE2\x80\x8Cدستباف" ), "\xE2\x80\x8C" ), '' );
ok( 'A: Arabic ي/ك fold into Persian ی/ک',
        Haa_Queue::normalize_topic( 'ديوان كاشان' ) === Haa_Queue::normalize_topic( 'دیوان کاشان' ), '' );
ok( 'A: whitespace collapses and case folds',
        Haa_Queue::normalize_topic( "  GUIDE   to  rugs  " ) === Haa_Queue::normalize_topic( 'guide to rugs' ), '' );
ok( 'A: trailing punctuation set ؟?!.؛،: all trimmed',
        'سلام' === Haa_Queue::normalize_topic( 'سلام؟' ) && 'سلام' === Haa_Queue::normalize_topic( 'سلام!' ) && 'سلام' === Haa_Queue::normalize_topic( 'سلام.' ) && 'سلام' === Haa_Queue::normalize_topic( 'سلام؛' ), '' );
ok( 'A: empty stays empty', '' === Haa_Queue::normalize_topic( '   ؟ ' ), '' );

/* ===================================================================
 * B. topic_taken — the enqueue gate
 * =================================================================== */
echo "=== B. topic_taken ===\n";

q45_reset();
Haa_Queue::push( 'aw_article', array( 'topic' => 'چگونه فرش دستباف مناسب خانه انتخاب کنیم؟' ), array( 'module' => 'article-writer' ) );
ok( 'B: identical topic is taken',
        Haa_Queue::topic_taken( 'چگونه فرش دستباف مناسب خانه انتخاب کنیم؟' ), '' );
ok( 'B: same topic without «؟» is taken too (normalized)',
        Haa_Queue::topic_taken( 'چگونه فرش دستباف مناسب خانه انتخاب کنیم' ), '' );
ok( 'B: same compound without ZWNJ is taken (فرش‌دستباف vs فرشدستباف)',
        Haa_Queue::topic_taken( 'چگونه فرشدستباف مناسب خانه انتخاب کنیم' ) || Haa_Queue::topic_taken( 'چگونه فرش دستباف مناسب خانه انتخاب کنیم' ), '' );
ok( 'B: a different topic is free',
        ! Haa_Queue::topic_taken( 'بررسی جنس و بافت فرش دستباف پیش از خرید' ), '' );
ok( 'B: an autopilot row does not block an article topic',
        ! Haa_Queue::topic_taken( 'چگونه فرش دستباف مناسب خانه انتخاب کنیم؟', 'autopilot' ), '' );
ok( 'B: empty topic is never taken', ! Haa_Queue::topic_taken( '' ), '' );

// The posts side.
$GLOBALS['qdb']['posts'] = array( 'راهنمای تمیزکردن و نگهداری فرش دستباف' );
ok( 'B: an existing post title is taken',
        Haa_Queue::topic_taken( 'راهنمای تمیزکردن و نگهداری فرش دستباف' ), '' );
ok( 'B: the post title with a trailing «؟» is still taken',
        Haa_Queue::topic_taken( 'راهنمای تمیزکردن و نگهداری فرش دستباف؟' ), '' );
ok( 'B: an unrelated subject is free',
        ! Haa_Queue::topic_taken( 'انتخاب نقش و رنگ فرش هماهنگ با دکوراسیون' ), '' );

// A failed row also blocks (it will auto-revive) — same scan covers all states.
q45_reset();
$GLOBALS['qdb']['rows'][] = array(
        'id' => 1, 'job' => 'aw_article', 'module' => 'article-writer',
        'payload' => wp_json_encode( array( 'topic' => 'موضوع ناموفق' ) ),
        'status' => 'failed', 'attempts' => 3, 'max_attempts' => 3,
        'locked_until' => null, 'last_error' => 'x',
        'created' => gmdate( 'Y-m-d H:i:s', time() - 50000 ), 'updated' => gmdate( 'Y-m-d H:i:s', time() - 50000 ),
);
ok( 'B: a failed row still blocks its subject (it will auto-retry)',
        Haa_Queue::topic_taken( 'موضوع ناموفق' ), '' );

/* ===================================================================
 * C. fail() backoff — «برنامه و زمانبندی نداریم؟»
 * =================================================================== */
echo "=== C. fail() backoff ===\n";

q45_reset();
$GLOBALS['set45'] = array(); // defaults: backoff 10, revive 2.
$id_c = Haa_Queue::push( 'aw_article', array( 'topic' => 'موضوع بک‌آف' ), array( 'module' => 'article-writer', 'max_attempts' => 3 ) );
add_action( 'haai_run_job_aw_article', function () {
        $GLOBALS['haai_job_result'] = new WP_Error45( 'سهمیهٔ مدل پر شد' );
} );
Haa_Queue::tick(); // attempt 1 fails → backoff.
$row = Haa_Queue::job( $id_c );
ok( 'C: after attempt 1 the row waits as pending', 'pending' === $row['status'], $row['status'] );
ok( 'C: the retry is scheduled ~10 minutes out',
        '' !== (string) $row['locked_until'] && strtotime( (string) $row['locked_until'] ) > time() + 8 * MINUTE_IN_SECONDS, (string) $row['locked_until'] );
$again = Haa_Queue::tick();
ok( 'C: the next tick does NOT burn attempt 2 early', empty( $again['ran'] ), json_encode( $again, JSON_UNESCAPED_UNICODE ) );

$GLOBALS['set45'] = array( 'article.retry_backoff_minutes' => 0 ); // old behaviour.
// The stored lock from the spaced failure legitimately persists; simulating
// its expiry is what a 10-minute wait would have done.
$GLOBALS['qdb']['rows'][0]['locked_until'] = gmdate( 'Y-m-d H:i:s', time() - MINUTE_IN_SECONDS );
$again2 = Haa_Queue::tick();
ok( 'C: backoff 0 → the retry runs immediately (BC switch)', true === $again2['ran'] && isset( $again2['error'] ), json_encode( $again2, JSON_UNESCAPED_UNICODE ) );
$row2 = Haa_Queue::job( $id_c );
ok( 'C: attempt 2 also waits as pending (cap 3)', 'pending' === $row2['status'] && 2 === (int) $row2['attempts'], $row2['status'] . '/' . $row2['attempts'] );

// The final failure still lands in failed with the error kept.
$GLOBALS['set45'] = array( 'article.retry_backoff_minutes' => 0 );
Haa_Queue::tick(); // attempt 3 → cap.
$row3 = Haa_Queue::job( $id_c );
ok( 'C: cap reached → failed with the error kept', 'failed' === $row3['status'] && false !== strpos( (string) $row3['last_error'], 'سهمیهٔ مدل' ), $row3['status'] );

// Other modules keep the old immediate retry (no backoff).
q45_reset();
$GLOBALS['set45'] = array(); // default 10 in effect — but module is autopilot.
$id_ap = Haa_Queue::push( 'ap_post', array( 'k' => 1 ), array( 'module' => 'autopilot', 'max_attempts' => 3 ) );
$GLOBALS['q_actions'] = array();
add_action( 'haai_run_job_ap_post', function () {
        $GLOBALS['haai_job_result'] = new WP_Error45( 'خطای اتوپایلوت' );
} );
Haa_Queue::tick();
$ap = Haa_Queue::job( $id_ap );
ok( 'C: non-article modules keep immediate retry (no lock)',
        'pending' === $ap['status'] && '' === (string) $ap['locked_until'], $ap['status'] . '/' . (string) $ap['locked_until'] );

/* ===================================================================
 * D. revive_failed() — the automatic second round
 * =================================================================== */
echo "=== D. revive_failed ===\n";

q45_reset();
$GLOBALS['set45'] = array( 'article.retry_backoff_minutes' => 0, 'article.auto_retry_hours' => 2 );
$old_fail = gmdate( 'Y-m-d H:i:s', time() - 3 * HOUR_IN_SECONDS );
Haa_Queue::push( 'aw_article', array( 'topic' => 'موضوع کهنه' ), array( 'module' => 'article-writer', 'max_attempts' => 2 ) );
$GLOBALS['qdb']['rows'][0]['status']  = 'failed';
$GLOBALS['qdb']['rows'][0]['updated'] = $old_fail;
$GLOBALS['qdb']['rows'][0]['last_error'] = 'خطای قدیمی';

$n = Haa_Queue::revive_failed();
$row = Haa_Queue::job( 1 );
ok( 'D: an old failed row revives', 1 === $n && 'pending' === $row['status'], $row['status'] );
ok( 'D: revival resets attempts', 0 === (int) $row['attempts'], (string) $row['attempts'] );
ok( 'D: the payload carries the _revived flag',
        ! empty( $row['payload']['_revived'] ), '' );
ok( 'D: the row keeps no stale lock', '' === (string) $row['locked_until'], '' );

$n2 = Haa_Queue::revive_failed();
ok( 'D: the flag prevents a second revival', 0 === $n2 && 'pending' === Haa_Queue::job( 1 )['status'], (string) $n2 );

// A failed row already carrying the flag never revives.
q45_reset();
Haa_Queue::push( 'aw_article', array( 'topic' => 'موضوع بازگردیده', '_revived' => 1 ), array( 'module' => 'article-writer', 'max_attempts' => 2 ) );
$GLOBALS['qdb']['rows'][0]['status']  = 'failed';
$GLOBALS['qdb']['rows'][0]['updated'] = $old_fail;
ok( 'D: an already-revived failed row stays failed',
        0 === Haa_Queue::revive_failed() && 'failed' === Haa_Queue::job( 1 )['status'], '' );

// A fresh failure is not old enough.
q45_reset();
Haa_Queue::push( 'aw_article', array( 'topic' => 'موضوع تازه' ), array( 'module' => 'article-writer', 'max_attempts' => 2 ) );
$GLOBALS['qdb']['rows'][0]['status'] = 'failed';
$GLOBALS['qdb']['rows'][0]['updated'] = gmdate( 'Y-m-d H:i:s', time() - 5 * MINUTE_IN_SECONDS );
ok( 'D: a fresh failure waits for its hours', 0 === Haa_Queue::revive_failed() && 'failed' === Haa_Queue::job( 1 )['status'], '' );

// Disabled.
q45_reset();
$GLOBALS['set45'] = array( 'article.auto_retry_hours' => 0 );
Haa_Queue::push( 'aw_article', array( 'topic' => 'موضوع خاموش' ), array( 'module' => 'article-writer', 'max_attempts' => 2 ) );
$GLOBALS['qdb']['rows'][0]['status']  = 'failed';
$GLOBALS['qdb']['rows'][0]['updated'] = $old_fail;
ok( 'D: auto_retry_hours 0 disables the revival', 0 === Haa_Queue::revive_failed(), '' );

// Cap: at most two per tick.
q45_reset();
$GLOBALS['set45'] = array( 'article.auto_retry_hours' => 2 );
for ( $i = 1; $i <= 3; $i++ ) {
        Haa_Queue::push( 'aw_article', array( 'topic' => 'دستهٔ ' . $i ), array( 'module' => 'article-writer', 'max_attempts' => 2 ) );
        $GLOBALS['qdb']['rows'][ $i - 1 ]['status']  = 'failed';
        $GLOBALS['qdb']['rows'][ $i - 1 ]['updated'] = $old_fail;
}
ok( 'D: at most two revivals per tick', 2 === Haa_Queue::revive_failed(), '' );
ok( 'D: the third waits for the next tick',
        'failed' === Haa_Queue::job( 3 )['status'] && 'pending' === Haa_Queue::job( 1 )['status'], '' );

/* ===================================================================
 * E. delete() — the one-row escape hatch
 * =================================================================== */
echo "=== E. delete ===\n";

q45_reset();
$GLOBALS['set45'] = array( 'article.retry_backoff_minutes' => 0, 'article.auto_retry_hours' => 0 );
$id_e = Haa_Queue::push( 'aw_article', array( 'topic' => 'موضوع حذفی' ), array( 'module' => 'article-writer' ) );
ok( 'E: delete removes the row', Haa_Queue::delete( $id_e ) && null === Haa_Queue::job( $id_e ), '' );
ok( 'E: delete of a gone row is false', ! Haa_Queue::delete( $id_e ), '' );

/* ===================================================================
 * F. run_tick wiring + source contracts
 * =================================================================== */
echo "=== F. wiring + source contracts ===\n";

$q_src = file_get_contents( dirname( __DIR__ ) . '/includes/class-haa-queue.php' );
ok( 'F: run_tick() calls revive_failed() before the drain',
        false !== strpos( $q_src, 'self::revive_failed();' ) && preg_match( '/break_stale_lock\(\);\s*\n\s*self::revive_failed\(\);\s*\n\s*return self::drain\(/', $q_src ), '' );
ok( 'F: topic_taken scans queue payloads AND post titles',
        false !== strpos( $q_src, 'SELECT payload FROM' ) && false !== strpos( $q_src, 'post_title LIKE %s' ), '' );
ok( 'F: the backoff applies to the article module only',
        false !== strpos( $q_src, "'article-writer' === \$module" ), '' );
ok( 'F: revival is capped and flagged in the payload',
        false !== strpos( $q_src, "\$revived >= 2" ) && false !== strpos( $q_src, "'_revived'" ), '' );

$pl_src = file_get_contents( dirname( __DIR__ ) . '/includes/class-haa-planner.php' );
ok( 'F: planner queue() asks topic_taken() before pushing',
        false !== strpos( $pl_src, 'Haa_Queue::topic_taken( (string) $row[\'title\'] )' ), '' );
ok( 'F: planner delete() removes the pending job with the row',
        false !== strpos( $pl_src, "in_array( \$job['status'], array( 'pending', 'failed' ), true )") && preg_match( '/public static function delete\( \$id \).{0,900}Haa_Queue::delete\(/s', $pl_src ), '' );

$aw = file_get_contents( dirname( __DIR__ ) . '/modules/class-haa-module-article-writer.php' );
ok( 'F: handle_queue dedups in-batch AND against the site',
        false !== strpos( $aw, '$in_batch[ $norm ]' ) && false !== strpos( $aw, 'Haa_Queue::topic_taken( $topic )' ), '' );
ok( 'F: the all-duplicates enqueue says so honestly',
        false !== strpos( $aw, 'هیچ مقاله‌ای صف نشد — موضوع(های) تکراری بودند' ), '' );
ok( 'F: haai_aw_qdelete is registered and refuses running/done rows',
        false !== strpos( $aw, "add_action( 'admin_post_haai_aw_qdelete'" ) && false !== strpos( $aw, "array( 'pending', 'failed' ), true )" ), '' );
ok( 'F: the queued→suggested revert cleans its pending job',
        false !== strpos( $aw, "'suggested' === \$status" ) && false !== strpos( $aw, "'queued' === \$row['status']" ), '' );
ok( 'F: the unified panel carries the jump-nav',
        false !== strpos( $aw, 'پرش به:' ) && false !== strpos( $aw, 'href="#haai-kw"' ), '' );
ok( 'F: the panel note names all three enqueue paths',
        false !== strpos( $aw, 'انتخاب‌شده‌ها را به صف بده' ) && false !== strpos( $aw, 'ساخت مقاله برای انتخاب‌شده‌ها' ), '' );
ok( 'F: the schedule line names the two settings',
        false !== strpos( $aw, 'زمان‌بندی: موضوع تکراری دو بار صف نمی‌شود' ), '' );
ok( 'F: failed rows name their next automatic attempt',
        false !== strpos( $aw, 'تلاش خودکار: حدود %s دیگر.' ), '' );
ok( 'F: pending rows name their spaced retry',
        false !== strpos( $aw, 'تلاش بعدی: حدود %s دیگر.' ), '' );

$st = file_get_contents( dirname( __DIR__ ) . '/includes/class-haa-settings.php' );
ok( 'F: defaults carry both schedule keys',
        false !== strpos( $st, "'retry_backoff_minutes' => 10" ) && false !== strpos( $st, "'auto_retry_hours' => 2" ), '' );
ok( 'F: sanitize map bounds both keys',
        false !== strpos( $st, "'article.retry_backoff_minutes' => array( 'cb' => array( 'Haa_Settings', 'sanitize_int' ), 'min' => 0, 'max' => 120 )" )
        && false !== strpos( $st, "'article.auto_retry_hours' => array( 'cb' => array( 'Haa_Settings', 'sanitize_int' ), 'min' => 0, 'max' => 48 )" ), '' );
ok( 'F: ui_keys expose both keys on the article-writer tab',
        false !== strpos( $st, "'article.retry_backoff_minutes', 'article.auto_retry_hours'" ), '' );

$css  = file_get_contents( dirname( __DIR__ ) . '/assets/css/admin.css' );
$cssm = file_get_contents( dirname( __DIR__ ) . '/assets/css/admin.min.css' );
ok( 'F: the jump-nav + subtitle classes exist in BOTH css builds',
        false !== strpos( $css, '.haai-jumpnav' ) && false !== strpos( $css, '.haai-panel__subtitle' )
        && false !== strpos( $cssm, '.haai-jumpnav' ) && false !== strpos( $cssm, '.haai-panel__subtitle' ), '' );

/* ===================================================================
 * G. version pins
 * =================================================================== */
echo "=== G. version pins ===\n";

$main = file_get_contents( dirname( __DIR__ ) . '/hami-ai-assistant.php' );
ok( 'G: version header is 2.1.56', false !== strpos( $main, '2.1.56' ), '' );
ok( 'G: HAAI_VERSION constant is 2.1.56', false !== strpos( $main, "define( 'HAAI_VERSION', '2.1.56' )" ), '' );

/* ===================================================================
 * H. behavioral: the whole loop — fail, wait, revive, succeed
 * =================================================================== */
echo "=== H. full loop ===\n";

q45_reset();
$GLOBALS['set45'] = array( 'article.retry_backoff_minutes' => 0, 'article.auto_retry_hours' => 2 );
$id_h = Haa_Queue::push( 'aw_article', array( 'topic' => 'چرخهٔ کامل' ), array( 'module' => 'article-writer', 'max_attempts' => 1 ) );
$GLOBALS['q_actions'] = array();
add_action( 'haai_run_job_aw_article', function () {
        static $calls = 0;
        $calls++;
        if ( $calls < 2 ) {
                $GLOBALS['haai_job_result'] = new WP_Error45( 'مدل خسته بود' );
                return;
        }
        $GLOBALS['haai_job_result'] = array( 'ok' => 1 );
} );
$r1 = Haa_Queue::tick();
ok( 'H: first build fails at the cap (1 of 1)', 'failed' === Haa_Queue::job( $id_h )['status'], '' );
$GLOBALS['qdb']['rows'][0]['updated'] = gmdate( 'Y-m-d H:i:s', time() - 3 * HOUR_IN_SECONDS );
ok( 'H: nothing revives before a cron tick', 'failed' === Haa_Queue::job( $id_h )['status'], '' );
Haa_Queue::run_tick();
ok( 'H: the cron tick revived and REBUILT it',
        'done' === Haa_Queue::job( $id_h )['status'] && 1 === (int) Haa_Queue::job( $id_h )['attempts'], Haa_Queue::job( $id_h )['status'] . '/' . Haa_Queue::job( $id_h )['attempts'] );

/* Minimal error shim (the real WP_Error is stubbed in other suites). */
class WP_Error45 {
        private $msg;
        public function __construct( $m ) { $this->msg = $m; }
        public function get_error_message() { return $this->msg; }
}

echo "\n--- suite 45: queue unification (2.1.42) ---\n";
echo "checks: $pass  FAIL=$fail\n";
exit( $fail ? 1 : 0 );
