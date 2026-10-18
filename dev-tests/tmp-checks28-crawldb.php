<?php
/**
 * Regression suite: DB-first crawling (2.1.17).
 *
 * The user's live report: «الان ۴۰ دقیقه است هنوز ۲۰٪ پر نشده از نوار —
 * 0 صفحه / 0 تکه». Root cause: the crawler HTTP-fetched the site's OWN pages
 * (slow on shared/CDN hosts, often firewalled — every page burned its 6 s
 * timeout and stored nothing). Locked here:
 *   1. targets_full() — url+pid pairs; homepage/custom URLs pid=0 (HTTP),
 *      WP objects carry their post id (DB path); targets() keeps returning
 *      URL strings in the same priority order;
 *   2. start() saves pids parallel to urls;
 *   3. the DB path stores under the SAME deterministic negative slot via
 *      Haa_Search::build_source() (title/url/content_norm semantics
 *      identical to the HTTP path) and re-crawling REPLACES, never duplicates;
 *   4. per-tick admission: HTTP ≤ batch_size, DB ≤ 100 (2.1.18) — a mixed 30-URL queue
 *      drains in ONE tick;
 *   5. a queue saved by an older version (no pids) is backfilled from
 *      url_to_postid — homepage and custom URLs stay HTTP;
 *   6. contact facts are harvested from DB text too (phone/email/socials in
 *      post_content reach the «راه‌های ارتباطی» chunk even when the host
 *      refuses to read its own pages);
 *   7. thin DB content (< MIN_TEXT) is skipped without breaking the run;
 *   8. dashboard: DB-aware ETA + finish() names the DB fallback.
 *
 * Run: php dev-tests/tmp-checks28-crawldb.php
 */
require __DIR__ . '/tmp-wp-stubs.php';

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

// RAG must be on or start() refuses.
Haa_Settings::save_section( 'advanced', array( 'kb_enabled' => 1, 'enable_rag' => 1 ) );

echo "=== A. targets_full — url+pid pairs, priority intact ===\n";
reset_all();
$GLOBALS['posts'] = array(
        array( 'id' => 700, 'title' => 'تماس با ما', 'type' => 'page', 'status' => 'publish' ),
        array( 'id' => 701, 'title' => 'درباره ما', 'type' => 'page', 'status' => 'publish' ),
        array( 'id' => 800, 'title' => 'مقاله', 'type' => 'post', 'status' => 'publish' ),
        array( 'id' => 702, 'title' => 'پیش‌نویس', 'type' => 'page', 'status' => 'draft' ),
);
Haa_Settings::save_section( 'knowledge', array(
        'urls'        => "https://local.test/instagram",
        'auto_crawl'  => 1,
        'max_pages'   => 10,
        'crawl_types' => array( 'page', 'post' ),
) );
reset_crawl_config();
$tf = Haa_Crawl::targets_full();
ok( 'targets_full returns url+pid pairs', count( $tf ) > 0 && isset( $tf[0]['url'], $tf[0]['pid'] ) );
ok( 'homepage first with pid 0', 'http://local.test' === (string) $tf[0]['url'] || 'http://local.test/' === (string) $tf[0]['url'] );
ok( 'homepage pid is 0 (HTTP)', 0 === (int) $tf[0]['pid'] );
$custom = null;
foreach ( $tf as $t ) {
        if ( false !== strpos( (string) $t['url'], 'instagram' ) ) { $custom = $t; break; }
}
ok( 'custom URL present with pid 0 (HTTP)', null !== $custom && 0 === (int) $custom['pid'] );
$page_entries = array_values( array_filter( $tf, function ( $t ) { return (int) $t['pid'] > 0; } ) );
ok( 'published pages carry their post id', count( $page_entries ) >= 2 );
ok( 'draft page excluded from the queue', ! count( array_filter( $tf, function ( $t ) { return false !== strpos( (string) $t['url'], 'post=702' ); } ) ) );
$urls_only = Haa_Crawl::targets();
ok( 'targets() mirrors targets_full order', implode( '|', $urls_only ) === implode( '|', array_map( function ( $t ) { return $t['url']; }, $tf ) ) );

echo "=== B. start() saves pids parallel to urls ===\n";
reset_all();
$GLOBALS['opts']['hami_ai_crawl_state'] = array();
$start = Haa_Crawl::start();
$st    = crawl_state();
ok( 'start() keeps running', ! empty( $start['running'] ) );
ok( 'state has pids', isset( $st['pids'] ) && is_array( $st['pids'] ) );
ok( 'pids parallel to urls', count( (array) $st['pids'] ) === count( (array) $st['urls'] ) );
ok( 'homepage pid saved as 0', 0 === (int) $st['pids'][0] );
ok( 'at least one DB pid saved', count( array_filter( (array) $st['pids'] ) ) > 0 );
Haa_Crawl::cancel();

echo "=== C. DB path stores under the same slot contract ===\n";
reset_all();
$GLOBALS['written'][900] = array( 'ID' => 900, 'post_title' => 'تماس با ما', 'post_type' => 'page', 'post_status' => 'publish',
        'post_content' => '<p>آدرس: رشت، خیابان مطهری. ساعت کاری ۹ تا ۱۸. شماره تماس: 01333221100 — موبایل: 09113123456 — ایمیل: info@gilan.test</p>'
                . '<p>برای سفارش تلفنی در خدمت شماستیم و ارسال سراسری داریم.</p>' );
$url900 = 'http://local.test/?post=900';
$res    = Haa_Crawl::store_db_page( 900, $url900 );
ok( 'store_db_page indexed the page', 1 === (int) $res['pages'] && (int) $res['chunks'] > 0, '(chunks=' . $res['chunks'] . ')' );
ok( 'returned text non-empty', false !== strpos( (string) $res['text'], '01333221100' ) );
$rows = array_values( array_filter( $GLOBALS['chunk_rows'], function ( $r ) use ( $res ) { return true; } ) );
$slot = Haa_Crawl::slot_id( $url900 );
$mine = array_values( array_filter( $GLOBALS['chunk_rows'], function ( $r ) use ( $slot ) { return (int) $r['post_id'] === (int) $slot; } ) );
ok( 'rows live under slot_id(permalink)', count( $mine ) === (int) $res['chunks'] );
ok( 'source=crawl on DB rows', 'crawl' === (string) $mine[0]['source'] );
ok( 'row url is the permalink', false !== strpos( (string) $mine[0]['url'], 'post=900' ) );
ok( 'row title is the post title', false !== strpos( (string) $mine[0]['title'], 'تماس' ) );
ok( 'content carries the DB copy', false !== strpos( (string) $mine[0]['content'], 'ساعت کاری' ) );
ok( 'content_norm includes the title', false !== strpos( (string) $mine[0]['content_norm'], Haa_Search::normalize_text( 'تماس با ما' ) ) );

// Re-store → replace, never duplicate.
$before = count( $GLOBALS['chunk_rows'] );
Haa_Crawl::store_db_page( 900, $url900 );
$after  = count( $GLOBALS['chunk_rows'] );
$mine2  = array_values( array_filter( $GLOBALS['chunk_rows'], function ( $r ) use ( $slot ) { return (int) $r['post_id'] === (int) $slot; } ) );
ok( 're-crawl REPLACES (no duplicates)', $after <= $before && count( $mine2 ) === (int) $res['chunks'], '(before=' . $before . ' after=' . $after . ')' );

// Thin content is skipped.
$GLOBALS['written'][901] = array( 'ID' => 901, 'post_title' => 'کوتاه', 'post_type' => 'page', 'post_status' => 'publish', 'post_content' => '<p>کم</p>' );
$res2 = Haa_Crawl::store_db_page( 901, 'http://local.test/?post=901' );
ok( 'thin DB page skipped (0 pages)', 0 === (int) $res2['pages'] );
// Missing post is a no-op.
$res3 = Haa_Crawl::store_db_page( 99999, 'http://local.test/?post=99999' );
ok( 'missing post is a no-op', 0 === (int) $res3['pages'] );

echo "=== D. per-tick admission — HTTP ≤ batch_size, DB ≤ 100 ===\n";
reset_all();
// Mixed queue: 1 HTTP + 4 DB → ONE batch drains it.
$GLOBALS['written'][910] = array( 'ID' => 910, 'post_title' => 'برگه ۹۱۰', 'post_type' => 'page', 'post_status' => 'publish',
        'post_content' => '<p>محتوای آزمایشی برگه ۹۱۰ فروشگاه هدیه گیلان انواع صنایع دستی و فرش دستباف را با ارسال سراسری عرضه می‌کند و برای سفارش تلفنی در خدمت شماست.</p>' );
$GLOBALS['written'][911] = array( 'ID' => 911, 'post_title' => 'برگه ۹۱۱', 'post_type' => 'page', 'post_status' => 'publish',
        'post_content' => '<p>محتوای آزمایشی برگه ۹۱۱ فروشگاه هدیه گیلان انواع صنایع دستی و فرش دستباف را با ارسال سراسری عرضه می‌کند و برای سفارش تلفنی در خدمت شماست.</p>' );
$GLOBALS['posts'] = array(
        array( 'id' => 910, 'title' => 'برگه ۹۱۰', 'type' => 'page', 'status' => 'publish' ),
        array( 'id' => 911, 'title' => 'برگه ۹۱۱', 'type' => 'page', 'status' => 'publish' ),
);
$GLOBALS['http_map'] = array( 'local.test' => array( 'body' => '<html><head><title>خانه</title></head><body><p>فروشگاه هدیه گیلان انواع صنایع دستی و فرش دستباف را با ارسال سراسری عرضه می‌کند و برای سفارش تلفنی در خدمت شماست.</p></body></html>' ) );
Haa_Settings::save_section( 'knowledge', array( 'urls' => '', 'auto_crawl' => 1, 'max_pages' => 10, 'crawl_types' => array( 'page' ) ) );
reset_crawl_config();
$GLOBALS['opts']['hami_ai_crawl_state'] = array();
Haa_Crawl::start();
Haa_Crawl::batch( 2 );
$p = Haa_Crawl::progress();
ok( 'mixed queue drained in ONE tick', (int) $p['total'] === (int) $p['offset'], '(offset=' . $p['offset'] . '/' . $p['total'] . ')' );
ok( 'DB pages actually stored', (int) $p['pages'] >= 3, '(pages=' . $p['pages'] . ')' );
ok( 'crawl finished', empty( $p['running'] ) );

// HTTP-only queue: batch(2) must process exactly 2.
reset_all();
$GLOBALS['http_map'] = array(
        'local.test/dead' => array( 'error' => 'cURL error 28: timeout' ),
        'local.test'      => array( 'body' => '<html><head><title>خ</title></head><body><p>محتوای آزمایشی کافی برای تکه‌سازی و ایندکس شدن — ' . str_repeat( 'متن نمونه ', 30 ) . '</p></body></html>' ),
);
$GLOBALS['opts']['hami_ai_crawl_state'] = array(
        'running' => 1, 'offset' => 0, 'total' => 4,
        'urls'    => array( 'http://local.test/a', 'http://local.test/b', 'http://local.test/c', 'http://local.test/dead' ),
        'pids'    => array( 0, 0, 0, 0 ),
        'keys'    => array(), 'facts' => array(),
        'pages'   => 0, 'chunks' => 0, 'failed' => 0, 'last_error' => '',
        'current' => '', 'current_at' => 0, 'ms_sum' => 0, 'ms_n' => 0,
        'started_at' => time(), 'finished_at' => 0,
);
Haa_Crawl::batch( 2 );
ok( 'HTTP-only tick stops at batch_size (2)', 2 === (int) crawl_state()['offset'] );

// DB-only queue: batch(2) processes up to 100.
reset_all(); // fresh ledger — the HTTP-only tick above already stored rows
$GLOBALS['written'][920] = array( 'ID' => 920, 'post_title' => 'برگه ۹۲۰', 'post_type' => 'page', 'post_status' => 'publish',
        'post_content' => '<p>محتوای آزمایشی برگه ۹۲۰ فروشگاه هدیه گیلان انواع صنایع دستی و فرش دستباف را با ارسال سراسری عرضه می‌کند و برای سفارش تلفنی در خدمت شماست.</p>' );
$st = array(
        'running' => 1, 'offset' => 0, 'total' => 115,
        'urls'    => array(),
        'pids'    => array(),
        'keys'    => array(), 'facts' => array(),
        'pages'   => 0, 'chunks' => 0, 'failed' => 0, 'last_error' => '',
        'current' => '', 'current_at' => 0, 'ms_sum' => 0, 'ms_n' => 0,
        'started_at' => time(), 'finished_at' => 0,
);
for ( $i = 0; $i < 115; $i++ ) {
        $st['urls'][] = 'http://local.test/?post=9' . ( 20 + $i ); // distinct slots
        $st['pids'][] = 920;                                        // same DB source
}
$GLOBALS['opts']['hami_ai_crawl_state'] = $st;
Haa_Crawl::batch( 2 );
ok( 'DB-only tick stops at the 100 cap', 100 === (int) crawl_state()['offset'], '(offset=' . crawl_state()['offset'] . ')' );
ok( 'DB rows stored for the first 100 slots', 100 === count( $GLOBALS['chunk_rows'] ), '(rows=' . count( $GLOBALS['chunk_rows'] ) . ')' );
Haa_Crawl::batch( 2 );
ok( 'next tick drains the rest', 115 === (int) crawl_state()['offset'] );
ok( 'every DB slot stored exactly once', 115 === count( $GLOBALS['chunk_rows'] ) );
Haa_Crawl::cancel();

echo "=== E. backfill — an old queue (no pids) gets the DB-fast path ===\n";
reset_all();
$GLOBALS['written'][920] = array( 'ID' => 920, 'post_title' => 'برگه ۹۲۰', 'post_type' => 'page', 'post_status' => 'publish',
        'post_content' => '<p>محتوای آزمایشی برگه ۹۲۰ فروشگاه هدیه گیلان انواع صنایع دستی و فرش دستباف را با ارسال سراسری عرضه می‌کند و برای سفارش تلفنی در خدمت شماست.</p>' );
$GLOBALS['url2post_map'] = array( 'post=920' => 920 );
$GLOBALS['http_map'] = array( 'local.test' => array( 'body' => '<html><head><title>خانه</title></head><body><p>فروشگاه هدیه گیلان انواع صنایع دستی و فرش دستباف را با ارسال سراسری عرضه می‌کند و برای سفارش تلفنی در خدمت شماست.</p></body></html>' ) );
$GLOBALS['opts']['hami_ai_crawl_state'] = array(
        'running' => 1, 'offset' => 0, 'total' => 3,
        'urls'    => array( 'http://local.test', 'http://local.test/custom', 'http://local.test/?post=920' ),
        'keys'    => array(), 'facts' => array(),
        'pages'   => 0, 'chunks' => 0, 'failed' => 0, 'last_error' => '',
        'current' => '', 'current_at' => 0, 'ms_sum' => 0, 'ms_n' => 0,
        'started_at' => time(), 'finished_at' => 0,
);
Haa_Crawl::batch( 2 );
$st = crawl_state();
ok( 'pids backfilled to full length', 3 === count( (array) $st['pids'] ) );
ok( 'homepage backfilled as HTTP (0)', 0 === (int) $st['pids'][0] );
ok( 'unknown URL backfilled as HTTP (0)', 0 === (int) $st['pids'][1] );
ok( 'permalink backfilled to its post id', 920 === (int) $st['pids'][2] );
ok( 'backfilled DB page stored in the same tick', (int) $st['pages'] >= 2, '(pages=' . $st['pages'] . ')' );
Haa_Crawl::cancel();
unset( $GLOBALS['url2post_map'] );

echo "=== F. contact facts from DB text — the firewalled-host safety net ===\n";
reset_all();
$GLOBALS['written'][930] = array( 'ID' => 930, 'post_title' => 'تماس با ما', 'post_type' => 'page', 'post_status' => 'publish',
        'post_content' => '<p>شماره تماس: 01333221100 — ایمیل: info@gilan.test — اینستاگرام: instagram.com/gilan_shop</p>'
                . '<p>پاسخگویی در ساعات اداری و ارسال به سراسر کشور انجام می‌شود و تیم پشتیبانی آماده پاسخ‌گویی است.</p>' );
$GLOBALS['opts']['hami_ai_crawl_state'] = array(
        'running' => 1, 'offset' => 0, 'total' => 1,
        'urls'    => array( 'http://local.test/?post=930' ),
        'pids'    => array( 930 ),
        'keys'    => array(), 'facts' => array(),
        'pages'   => 0, 'chunks' => 0, 'failed' => 0, 'last_error' => '',
        'current' => '', 'current_at' => 0, 'ms_sum' => 0, 'ms_n' => 0,
        'started_at' => time(), 'finished_at' => 0,
);
Haa_Crawl::batch( 2 );
Haa_Crawl::write_contact_chunk( crawl_state() );
$contact = array_values( array_filter( $GLOBALS['chunk_rows'], function ( $r ) { return -1 === (int) $r['post_id']; } ) );
ok( 'contact chunk written from DB facts', count( $contact ) === 1 );
$body = (string) ( $contact[0]['content'] ?? '' );
ok( 'phone distilled from DB text', false !== strpos( $body, '01333221100' ) );
ok( 'email distilled from DB text', false !== strpos( $body, 'info@gilan.test' ) );
ok( 'instagram distilled from DB text', false !== strpos( $body, 'instagram.com/gilan_shop' ) );
Haa_Crawl::cancel();

echo "=== G. dashboard — DB-aware ETA + finish() names the DB fallback ===\n";
$dash = (string) file_get_contents( __DIR__ . '/../includes/class-haa-admin-dashboard.php' );
ok( 'scope preview uses targets_full', false !== strpos( $dash, 'Haa_Crawl::targets_full()' ) );
ok( 'ETA weights HTTP vs DB', false !== strpos( $dash, '$crawl_http * 3' ) );
ok( 'finish() mentions the DB fallback', false !== strpos( $dash, 'از دیتابیس خوانده شده‌اند' ) );
$src = (string) file_get_contents( __DIR__ . '/../includes/class-haa-crawl.php' );
ok( 'batch admits 100 DB pages per tick (200 on the 20 s cron pass)', false !== strpos( $src, '$db_cap = ( (int) $time_budget > 15 ) ? 200 : 100;' ) && false !== strpos( $src, '$db_done >= $db_cap' ) );
ok( 'DB path reuses build_source', false !== strpos( $src, 'Haa_Search::build_source' ) );
ok( 'shared drop_slot helper exists', false !== strpos( $src, 'private static function drop_slot' ) );
ok( 'fetch timeout unchanged (6s)', false !== strpos( $src, "'timeout'     => 6," ) );

echo "\n" . ( 0 === $fail ? "ALL CHECKS PASSED (" . 'suite 28' . ")\n" : "$fail CHECK(S) FAILED\n" );
exit( 0 === $fail ? 0 : 1 );
