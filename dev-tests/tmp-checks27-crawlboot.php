<?php
/**
 * Regression suite: crawl ticker boot fix + batch lock + self-heal (2.1.16).
 *
 * The user's live report on 2.1.15: «الان 5 دقیقه ست تو همین حالته… 0 صفحه /
 * 0 تکه… خزش در حال اجراست…». Root cause found: the dashboard ticker is an
 * INLINE mid-page script but `haaiAdmin` (wp_localize_script on a
 * $in_footer=true handle) prints in the FOOTER — the ticker's first line
 * `if (!window.haaiAdmin) return;` therefore bailed on EVERY page load, no
 * batch was ever fetched, and the crawl sat at 0/0 forever with zero
 * feedback (no retry messages either — silence, exactly as reported).
 * Locked here:
 *   1. the ticker boots on DOMContentLoaded with a retry window and an HONEST
 *      fail message instead of a silent return (source assertions);
 *   2. Haa_Crawl::auto_drive() — a running crawl with an empty/stale
 *      heartbeat advances ONE batch on every dashboard load, so progress is
 *      made even with broken JS, and render() calls it;
 *   3. a batch LOCK (transient) serialises drivers: ticker, self-heal and a
 *      second tab can never race the same cursor, and cancel/wipe release it;
 *   4. fetch failures are COUNTED and surfaced (failed counter + last_error)
 *      instead of the old silent clearing, and progress() exposes 'failed';
 *   5. start() resets the failure counters.
 *
 * Run: php dev-tests/tmp-checks27-crawlboot.php
 */
require __DIR__ . '/tmp-wp-stubs.php';

// tmp-wp-stubs.php has no header reader; the crawler only needs content-type.
if ( ! function_exists( 'wp_remote_retrieve_header' ) ) {
        /** @return string */
        function wp_remote_retrieve_header( $r, $key ) {
                $headers = ( is_array( $r ) && isset( $r['headers'] ) ) ? (array) $r['headers'] : array();
                foreach ( $headers as $k => $v ) {
                        if ( strtolower( (string) $k ) === strtolower( (string) $key ) ) {
                                return (string) $v;
                        }
                }
                return '';
        }
}

$fail = 0;
function ok( $label, $cond, $extra = '' ) {
        global $fail;
        if ( ! $cond ) { $fail++; }
        echo ( $cond ? '  ok   ' : '  FAIL ' ) . str_pad( $label, 58 ) . $extra . "\n";
}

function reset_crawl_config() {
        $rc = new ReflectionClass( 'Haa_Crawl' );
        $p  = $rc->getProperty( 'config' );
        $p->setAccessible( true );
        $p->setValue( null, null );
}

function reset_kb_config() {
        $rc = new ReflectionClass( 'Haa_Search' );
        $p  = $rc->getProperty( 'config' );
        $p->setAccessible( true );
        $p->setValue( null, null );
}

function reset_all() {
        reset_kb_config();
        reset_crawl_config();
        $GLOBALS['opts']['hami_ai_crawl_state'] = array();
        $GLOBALS['chunk_rows']                  = array();
        $GLOBALS['http_log']                    = array();
        $GLOBALS['http_map']                    = array();
        delete_transient( 'haai_crawl_lock' );
}

function crawl_state() {
        return $GLOBALS['opts']['hami_ai_crawl_state'];
}

function good_body( $title, $words ) {
        return '<html><head><title>' . $title . '</title></head><body><p>' . $words
                . ' فروشگاه هدیه گیلان انواع صنایع دستی و فرش دستباف را با ارسال سراسری عرضه می‌کند و برای سفارش تلفنی در خدمت شماست.</p></body></html>';
}

// RAG must be on or start() refuses.
Haa_Settings::save_section( 'advanced', array( 'kb_enabled' => 1, 'enable_rag' => 1 ) );

echo "=== A. state defaults + progress() exposes the failure counter ===\n";
reset_all();
$st = Haa_Crawl::get_state();
ok( 'state default failed=0', isset( $st['failed'] ) && 0 === (int) $st['failed'] );
ok( 'state default last_error empty', '' === (string) $st['last_error'] );
$p0 = Haa_Crawl::progress();
ok( 'progress() carries failed key', isset( $p0['failed'] ) && 0 === (int) $p0['failed'] );

echo "=== B. batch LOCK — one driver at a time ===\n";
reset_all();
$GLOBALS['posts'] = array(
        array( 'id' => 700, 'title' => 'درباره ما', 'type' => 'page', 'status' => 'publish' ),
        array( 'id' => 701, 'title' => 'خدمات', 'type' => 'page', 'status' => 'publish' ),
);
$GLOBALS['http_map'] = array(
        'local.test' => array( 'body' => good_body( 'صفحه', 'محتوای آزمایشی صفحه برای پر کردن حداقل کاراکترها.' ) ),
);
Haa_Settings::save_section( 'knowledge', array( 'urls' => 'http://local.test/direct', 'auto_crawl' => 1, 'max_pages' => 6, 'crawl_types' => array( 'page' ) ) );
reset_crawl_config();
$start = Haa_Crawl::start();
ok( 'crawl started and running', ! empty( $start['running'] ) );

set_transient( 'haai_crawl_lock', 1, 45 );
$before = (int) crawl_state()['offset'];
$held   = Haa_Crawl::batch( 2 );
ok( 'locked batch does NOT advance the cursor', (int) $held['offset'] === $before, '(offset=' . $held['offset'] . ')' );
ok( 'pre-existing lock is kept while held elsewhere', false !== get_transient( 'haai_crawl_lock' ) );

delete_transient( 'haai_crawl_lock' );
$free = Haa_Crawl::batch( 2 );
ok( 'unlocked batch advances the cursor', (int) $free['offset'] > $before, '(offset=' . $free['offset'] . ')' );
ok( 'lock released after the batch', false === get_transient( 'haai_crawl_lock' ) );

echo "=== C. auto_drive() — self-heal without JS ===\n";
reset_all();
// 2.1.18 — one DB batch covers up to 100 pages, so the auto_drive matrix
// needs a queue BIGGER than one batch to keep its partial-progress shape.
$GLOBALS['posts'] = array();
for ( $i = 700; $i < 830; $i++ ) {
        $GLOBALS['posts'][] = array( 'id' => $i, 'title' => 'برگه ' . $i, 'type' => 'page', 'status' => 'publish' );
        $GLOBALS['written'][ $i ] = array( 'ID' => $i, 'post_title' => 'برگه ' . $i, 'post_type' => 'page', 'post_status' => 'publish',
                'post_content' => '<p>محتوای آزمایشی برگه ' . $i . ' فروشگاه هدیه گیلان انواع صنایع دستی و فرش دستباف را با ارسال سراسری عرضه می‌کند و برای سفارش تلفنی در خدمت شماست.</p>' );
}
$GLOBALS['http_map'] = array(
        'local.test' => array( 'body' => good_body( 'صفحه', 'محتوای آزمایشی صفحه برای پر کردن حداقل کاراکترها.' ) ),
);
Haa_Settings::save_section( 'knowledge', array( 'urls' => '', 'auto_crawl' => 1, 'max_pages' => 150, 'crawl_types' => array( 'page' ) ) );
reset_crawl_config();

$idle = Haa_Crawl::auto_drive();
ok( 'auto_drive is a no-op when nothing runs', 0 === (int) $idle['offset'] && empty( $idle['running'] ) );

$start = Haa_Crawl::start();
ok( 'fresh crawl has an empty heartbeat', 0 === (int) $start['current_at'] );
$d1 = Haa_Crawl::auto_drive();
ok( 'empty heartbeat drives ONE batch inline', (int) $d1['offset'] >= 1, '(offset=' . $d1['offset'] . ')' );

$d2 = Haa_Crawl::auto_drive();
ok( 'fresh heartbeat (healthy ticker) does NOT double-drive', (int) $d2['offset'] === (int) $d1['offset'], '(offset=' . $d2['offset'] . ')' );

$s = crawl_state();
$s['current_at'] = time() - 200;
$GLOBALS['opts']['hami_ai_crawl_state'] = $s;
$d3 = Haa_Crawl::auto_drive();
ok( 'stale heartbeat (>90s) drives again', (int) $d3['offset'] > (int) $d1['offset'], '(offset=' . $d3['offset'] . ')' );

echo "=== D. fetch failures are counted, never silent ===\n";
reset_all();
$GLOBALS['http_map'] = array(
        'local.test/dead' => array( 'error' => 'cURL error 28: timeout' ),
        'local.test'      => array( 'body' => good_body( 'صفحه سالم', 'محتوای آزمایشی صفحه سالم برای پر کردن حداقل کاراکترهای لازم.' ) ),
);
$GLOBALS['opts']['hami_ai_crawl_state'] = array(
        'running' => 1, 'offset' => 0, 'total' => 2,
        'urls'    => array( 'http://local.test/dead', 'http://local.test/good' ),
        'keys'    => array(), 'facts' => array(),
        'pages'   => 0, 'chunks' => 0, 'failed' => 0, 'last_error' => '',
        'current' => '', 'current_at' => 0, 'ms_sum' => 0, 'ms_n' => 0,
        'started_at' => time(), 'finished_at' => 0,
);
$prog = Haa_Crawl::batch( 4 );
$st   = crawl_state();
ok( 'one dead page counted as failed', 1 === (int) $st['failed'], '(failed=' . $st['failed'] . ')' );
ok( 'good page still stored', 1 === (int) $st['pages'] );
ok( 'last_error names the failure', false !== strpos( (string) $st['last_error'], 'timeout' ), '(' . $st['last_error'] . ')' );
ok( 'progress() mirrors the counter', 1 === (int) $prog['failed'] );
ok( 'failed pages still advance the cursor (no poisoning)', (int) $prog['offset'] >= 2 );

echo "=== E. start(reset) clears the failure counters ===\n";
Haa_Settings::save_section( 'knowledge', array( 'urls' => 'http://local.test/direct', 'auto_crawl' => 1, 'max_pages' => 6, 'crawl_types' => array( 'page' ) ) );
reset_crawl_config();
$s             = crawl_state();
$s['failed']   = 3;
$s['last_error'] = 'cURL error 28: timeout';
$GLOBALS['opts']['hami_ai_crawl_state'] = $s;
Haa_Crawl::start( true );
$st = crawl_state();
ok( 'reset clears failed', 0 === (int) $st['failed'] );
ok( 'reset clears last_error', '' === (string) $st['last_error'] );

echo "=== F. cancel() and wipe() release the lock ===\n";
set_transient( 'haai_crawl_lock', 1, 45 );
Haa_Crawl::cancel();
ok( 'cancel() released the lock', false === get_transient( 'haai_crawl_lock' ) );
set_transient( 'haai_crawl_lock', 1, 45 );
Haa_Crawl::wipe();
ok( 'wipe() released the lock', false === get_transient( 'haai_crawl_lock' ) );
ok( 'wipe() reset the failure counter', 0 === (int) crawl_state()['failed'] );

echo "=== G. dashboard ticker — boot-order fix is really in the file ===\n";
$src = (string) file_get_contents( __DIR__ . '/../includes/class-haa-admin-dashboard.php' );
ok( 'ticker boots via DOMContentLoaded', false !== strpos( $src, "document.addEventListener('DOMContentLoaded', boot)" ) );
ok( 'boot has a retry window for cached footers', false !== strpos( $src, 'setTimeout(boot, 500)' ) );
ok( 'boot ends in an HONEST message, never silence', false !== strpos( $src, 'اسکریپت پیشرفت بارگذاری نشد' ) );
ok( 'old immediate boot is gone', ! preg_match( '/tick\(\);\s*\n\s*\}\)\(\);/', $src ) );
ok( 'render() drives the self-heal batch', false !== strpos( $src, 'Haa_Crawl::auto_drive()' ) );
ok( 'ticker shows the unread-page count', false !== strpos( $src, 'خوانده‌نشده' ) );
ok( 'finish() names the all-failed case', false !== strpos( $src, 'هیچ صفحه‌ای خوانده نشد' ) );
ok( 'describe() surfaces last_error', false !== strpos( $src, 'آخرین خطا' ) );

echo "\n" . ( 0 === $fail ? "ALL CHECKS PASSED" : $fail . " CHECKS FAILED" ) . "\n";
exit( 0 === $fail ? 0 : 1 );
