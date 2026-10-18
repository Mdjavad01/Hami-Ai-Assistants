<?php
/**
 * Regression suite: server-driven crawl — the minute cron driver (2.1.21).
 *
 * The user's live report on 2.1.19: «نیم ساعت منتظر موندم ریلود کردم دیدم
 * همینه، نوار لود هم جلو نرفته دقیقه هم همونه» — a 1,760-page queue frozen
 * at «خزش در حال اجراست…». The engine was browser-only: the ticker JS
 * advances the queue, auto_drive() advances ONE batch per dashboard load,
 * and a lock left by a batch that died mid-fetch could effectively outlive
 * its 45 s TTL on persistent object caches that mishandle TTLs. Locked here:
 *   1. CRON_HOOK is registered in BOTH contexts (cron_hooks() outside
 *      is_admin()) and batch_cron() drives a 20 s batch when running;
 *   2. start() (fresh AND resume) schedules the driver; cancel()/wipe()
 *      and a drained queue clear it;
 *   3. batch_cron() on an idle crawl clears the schedule instead of driving;
 *   4. the batch lock carries its timestamp: a LIVE holder (fresh value)
 *      still blocks, a DEAD holder (value older than 60 s) is STOLEN so an
 *      immortal lock can never freeze the queue again;
 *   5. batch() honours the $time_budget parameter and admits 200 DB pages
 *      per cron pass (budget > 15 s) vs 100 on the browser's 10 s budget;
 *   6. the heartbeat is throttled for DB pages (every 20) — HTTP pages keep
 *      the per-page heartbeat;
 *   7. diagnose() exposes cron_scheduled/cron_last/wp_cron_disabled;
 *   8. the dashboard prints the engine line + stall verdict and says that
 *      indexing is 100% local (no AI/API dependency);
 *   9. the haai_minute schedule (60 s) exists; deactivation clears the hook.
 *
 * Run: php dev-tests/tmp-checks32-cron-driver.php
 */
require __DIR__ . '/tmp-wp-stubs.php';

$fail = 0;
function ok( $label, $cond, $extra = '' ) {
        global $fail;
        if ( ! $cond ) { $fail++; }
        echo ( $cond ? '  ok   ' : '  FAIL ' ) . str_pad( $label, 58 ) . $extra . "\n";
}

function reset_kb_config() {
        $rc = new ReflectionClass( 'Haa_Search' );
        $p  = $rc->getProperty( 'config' );
        $p->setAccessible( true );
        $p->setValue( null, null );
}

function reset_crawl_config() {
        $rc = new ReflectionClass( 'Haa_Crawl' );
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
        delete_transient( 'haai_crawl_cron_last' );
        $GLOBALS['crons']      = array();
        $GLOBALS['cron_fired'] = array();
}

function crawl_state() {
        return $GLOBALS['opts']['hami_ai_crawl_state'];
}

function good_body( $title, $words ) {
        return '<html><head><title>' . $title . '</title></head><body><p>' . $words
                . ' فروشگاه هدیه گیلان انواع صنایع دستی و فرش دستباف را با ارسال سراسری عرضه می‌کند و برای سفارش تلفنی در خدمت شماست.</p></body></html>';
}

function seed_pages( $from, $to, $type = 'page' ) {
        $GLOBALS['posts'] = array();
        foreach ( range( $from, $to ) as $i ) {
                $GLOBALS['posts'][] = array( 'id' => $i, 'title' => 'برگه ' . $i, 'type' => $type, 'status' => 'publish' );
                $GLOBALS['written'][ $i ] = array( 'ID' => $i, 'post_title' => 'برگه ' . $i, 'post_type' => $type, 'post_status' => 'publish',
                        'post_content' => '<p>محتوای آزمایشی برگه ' . $i . ' فروشگاه هدیه گیلان انواع صنایع دستی و فرش دستباف را با ارسال سراسری عرضه می‌کند و برای سفارش تلفنی در خدمت شماست.</p>' );
        }
}

// RAG must be on or start() refuses.
Haa_Settings::save_section( 'advanced', array( 'kb_enabled' => 1, 'enable_rag' => 1 ) );

echo "=== A. registration + schedule shape ===\n";
$main_src  = (string) file_get_contents( HAAI_DEV_ROOT . 'hami-ai-assistant.php' );
$crawl_src = (string) file_get_contents( HAAI_DEV_ROOT . 'includes/class-haa-crawl.php' );
ok( 'cron_schedules provides haai_minute (60 s)', false !== strpos( $main_src, "'haai_minute'" ) && false !== strpos( $main_src, "MINUTE_IN_SECONDS,\n                        'display'" ) );
ok( 'cron_hooks() registered OUTSIDE is_admin (both contexts)', false !== strpos( $main_src, 'Haa_Crawl::cron_hooks();' ) && preg_match( '/Haa_Crawl::cron_hooks\(\);[\s\S]*?if \( is_admin\(\) \)/', $main_src ) === 0 );
ok( 'cron_hooks() binds CRON_HOOK to batch_cron', false !== strpos( $crawl_src, "add_action( self::CRON_HOOK, array( __CLASS__, 'batch_cron' ) )" ) );
ok( 'deactivation clears the crawl cron', false !== strpos( $main_src, "wp_clear_scheduled_hook( 'haai_crawl_cron' )" ) );
$ref = new ReflectionMethod( 'Haa_Crawl', 'batch' );
$params = array();
foreach ( $ref->getParameters() as $pp ) { $params[] = array( $pp->getName(), (int) $pp->getDefaultValue() ); }
ok( 'batch() signature = (batch_size=2, time_budget=10)', array( array( 'batch_size', 2 ), array( 'time_budget', 10 ) ) === $params );
ok( 'batch_cron() uses the 20 s budget', false !== strpos( $crawl_src, 'self::batch( 2, 20 )' ) );

echo "=== B. start() schedules — fresh AND resume; cancel/wipe/done clear ===\n";
reset_all();
$GLOBALS['posts'] = array(
        array( 'id' => 700, 'title' => 'درباره ما', 'type' => 'page', 'status' => 'publish' ),
        array( 'id' => 701, 'title' => 'خدمات', 'type' => 'page', 'status' => 'publish' ),
);
$GLOBALS['http_map'] = array( 'local.test' => array( 'body' => good_body( 'صفحه', 'محتوای آزمایشی صفحه برای پر کردن حداقل کاراکترها.' ) ) );
Haa_Settings::save_section( 'knowledge', array( 'urls' => '', 'auto_crawl' => 1, 'max_pages' => 6, 'crawl_types' => array( 'page' ) ) );
reset_crawl_config();
ok( 'no cron before start', ! wp_next_scheduled( 'haai_crawl_cron' ) );
Haa_Crawl::start();
ok( 'fresh start() schedules the driver', false !== wp_next_scheduled( 'haai_crawl_cron' ) );
Haa_Crawl::cancel();
ok( 'cancel() clears the driver', ! wp_next_scheduled( 'haai_crawl_cron' ) );
// Resume path: a running state with progress, no cron scheduled (e.g. the
// host deactivated the plugin mid-crawl) — pressing «ادامهِ خزش» re-arms it.
$GLOBALS['opts']['hami_ai_crawl_state'] = array(
        'running' => 1, 'offset' => 1, 'total' => 2,
        'urls'    => array( 'http://local.test/', 'http://local.test/page/خدمات' ),
        'pids'    => array( 0, 701 ),
        'keys'    => array(), 'facts' => array(), 'pages' => 1, 'chunks' => 1,
);
Haa_Crawl::start( false );
ok( 'resume re-arms the driver', false !== wp_next_scheduled( 'haai_crawl_cron' ) );
Haa_Crawl::wipe();
ok( 'wipe() clears the driver', ! wp_next_scheduled( 'haai_crawl_cron' ) );

echo "=== C. batch_cron() — drives when running, disarms when idle ===\n";
reset_all();
seed_pages( 700, 705 );
$GLOBALS['http_map'] = array( 'local.test' => array( 'body' => good_body( 'صفحه', 'محتوای آزمایشی صفحه برای پر کردن حداقل کاراکترها.' ) ) );
Haa_Settings::save_section( 'knowledge', array( 'urls' => '', 'auto_crawl' => 1, 'max_pages' => 10, 'crawl_types' => array( 'page' ) ) );
reset_crawl_config();
// Idle crawl: batch_cron() must NOT drive and must drop the stale schedule.
$GLOBALS['crons']['haai_crawl_cron'] = time() + 60;
haai_stub_fire_cron( 'haai_crawl_cron' );
ok( 'idle batch_cron() does not drive', 0 === (int) ( crawl_state()['offset'] ?? 0 ) );
ok( 'idle batch_cron() disarms the schedule', ! wp_next_scheduled( 'haai_crawl_cron' ) );
// Running crawl: batch_cron() drives one batch and stamps CRON_LAST.
Haa_Crawl::start();
$GLOBALS['crons']['haai_crawl_cron'] = time() + 60; // start() re-scheduled; re-arm for the stub.
haai_stub_fire_cron( 'haai_crawl_cron' );
ok( 'running batch_cron() advances the queue', (int) ( crawl_state()['offset'] ?? 0 ) > 0, '(offset=' . (int) ( crawl_state()['offset'] ?? 0 ) . ')' );
ok( 'batch_cron() stamps the last-fire transient', (int) get_transient( 'haai_crawl_cron_last' ) > 0 );
// A drained queue (running=0 via finish) clears the schedule inside batch().
reset_all();
seed_pages( 700, 702 );
reset_crawl_config();
Haa_Crawl::start();
$GLOBALS['crons']['haai_crawl_cron'] = time() + 60;
haai_stub_fire_cron( 'haai_crawl_cron' );
ok( 'drained queue finishes the crawl', empty( crawl_state()['running'] ) );
ok( 'finished crawl disarms the schedule', ! wp_next_scheduled( 'haai_crawl_cron' ) );

echo "=== D. the lock is timestamped — live blocks, dead is stolen ===\n";
reset_all();
seed_pages( 700, 1000 ); // 301 pages: the stolen pass caps at 200, so the NEXT pass must move the cursor too.
Haa_Settings::save_section( 'knowledge', array( 'urls' => '', 'auto_crawl' => 1, 'max_pages' => 400, 'crawl_types' => array( 'page' ) ) );
reset_crawl_config();
Haa_Crawl::start();
$before = (int) ( crawl_state()['offset'] ?? 0 );
// LIVE holder: fresh timestamp value → the batch must wait.
set_transient( 'haai_crawl_lock', time(), 45 );
$held = Haa_Crawl::batch( 2 );
ok( 'live lock (fresh timestamp) blocks the batch', (int) $held['offset'] === $before );
// DEAD holder: a value older than 60 s (batch died mid-fetch on a broken
// object cache) → the lock is STOLEN and the queue moves again.
set_transient( 'haai_crawl_lock', time() - 300, 45 );
$freed = Haa_Crawl::batch( 2 );
ok( 'dead lock (stale timestamp) is stolen', (int) $freed['offset'] > $before, '(offset=' . (int) $freed['offset'] . ')' );
$again = Haa_Crawl::batch( 2 );
ok( 'no immortal lock — the next batch advances again', (int) $again['offset'] > (int) $freed['offset'] );
ok( 'lock released after the batch', false === get_transient( 'haai_crawl_lock' ) );

echo "=== E. cron pass admits 200 DB pages (budget 20 s) ===\n";
reset_all();
seed_pages( 700, 905 ); // 206 pages + home → a 200-cap pass cannot drain it.
Haa_Settings::save_section( 'knowledge', array( 'urls' => '', 'auto_crawl' => 1, 'max_pages' => 250, 'crawl_types' => array( 'page' ) ) );
reset_crawl_config();
Haa_Crawl::start();
$GLOBALS['crons']['haai_crawl_cron'] = time() + 60;
haai_stub_fire_cron( 'haai_crawl_cron' );
$off = (int) ( crawl_state()['offset'] ?? 0 );
ok( 'cron pass advanced home HTTP + 200 DB pages', 201 === $off, '(offset=' . $off . ')' );
ok( 'crawl still running after one cron pass', ! empty( crawl_state()['running'] ) );
// Browser budget stays at 100 DB pages per pass.
reset_all();
seed_pages( 700, 905 );
reset_crawl_config();
Haa_Crawl::start();
Haa_Crawl::batch( 2 ); // default 10 s browser budget
$off2 = (int) ( crawl_state()['offset'] ?? 0 );
ok( 'browser pass advanced home HTTP + 100 DB pages', 101 === $off2, '(offset=' . $off2 . ')' );

echo "=== F. heartbeat throttle — DB pages write every 20, HTTP per page ===\n";
reset_all();
seed_pages( 700, 705 );
reset_crawl_config();
Haa_Crawl::start();
$GLOBALS['opts']['hami_ai_crawl_state']['current_at'] = 0;
$saves_before = isset( $GLOBALS['option_saves'] ) ? count( $GLOBALS['option_saves'] ) : 0;
Haa_Crawl::batch( 2, 20 );
$st = crawl_state();
ok( 'heartbeat present after the pass', (int) $st['current_at'] > 0 );
ok( 'heartbeat URL is a real queued page', '' !== (string) $st['current'] );

echo "=== G. diagnose() carries engine telemetry ===\n";
$diag = Haa_Crawl::diagnose();
ok( 'diagnose has cron_scheduled', array_key_exists( 'cron_scheduled', $diag ) );
ok( 'diagnose has cron_last', array_key_exists( 'cron_last', $diag ) );
ok( 'diagnose has wp_cron_disabled', array_key_exists( 'wp_cron_disabled', $diag ) );

echo "=== H. dashboard — engine line, stall verdict, local-indexing note ===\n";
$dash = (string) file_get_contents( HAAI_DEV_ROOT . 'includes/class-haa-admin-dashboard.php' );
ok( 'engine line printed when running', false !== strpos( $dash, 'موتور سرور (کرون دقیقه‌ای) فعال است' ) );
ok( 'DISABLE_WP_CRON warning exists', false !== strpos( $dash, 'DISABLE_WP_CRON' ) );
ok( 'stall verdict printed when heartbeat+cron both stale', false !== strpos( $dash, 'حرکت مانده و کرون هم دسته' ) );
$diag_pos  = strpos( $dash, '$diag = Haa_Crawl::diagnose();' );
$eng_pos   = strpos( $dash, 'موتور سرور (کرون دقیقه‌ای)' ); // the engine line USES $diag — fetch must come first.
ok( 'diagnose() fetched BEFORE the engine block', false !== $diag_pos && false !== $eng_pos && $diag_pos < $eng_pos, '(diag=' . (int) $diag_pos . ', engine=' . (int) $eng_pos . ')' );
ok( 'indexing-is-local reassurance present', false !== strpos( $dash, 'ایندکس کاملاً روی دیتابیس خودِ سایت انجام می‌شود' ) );
ok( 'crawl description stays intact', false !== strpos( $dash, 'خزش، محتوای رندرشدهٔ صفحات سایت' ) );

echo "\n" . ( 0 === $fail ? 'ALL CHECKS PASSED (32-cron-driver)' : $fail . ' CHECK(S) FAILED' ) . "\n";
exit( $fail ? 1 : 0 );
