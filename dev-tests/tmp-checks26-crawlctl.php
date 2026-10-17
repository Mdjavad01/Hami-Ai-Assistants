<?php
/**
 * Regression suite: crawl scope control + honest progress (2.1.15).
 *
 * The user's live report: «الان ۱۰ دقیقه است خزش زدم… هیچ نواری جلو نمیره،
 * نمیدونم چه خبره، سایت سنگینه… اولویت بندی یا انتخاب صفحه کاش میذاشتی کنار این».
 * Locked here:
 *   1. knowledge.crawl_types — tick WHICH content joins the queue (pages by
 *      default; products/posts opt-in so a heavy shop stays light);
 *   2. targets() is PRIORITY-ORDERED: homepage → admin URLs → pages →
 *      products → posts, each bounded by the remaining budget;
 *   3. start() RESUMES a running crawl instead of resetting it (2.1.14's
 *      «ادامهِ خزش» silently restarted from zero); only the explicit reset
 *      flag rebuilds the queue;
 *   4. progress carries a heartbeat (current URL + when), pages_left, a live
 *      ETA and elapsed time — the dashboard can never look frozen again;
 *   5. the discovery skip list refuses cart/checkout/account/attachment/
 *      add-to-cart/pagination URLs;
 *   6. fetch timeout tightened to 6s so a tick stays well inside host limits.
 *
 * Run: php dev-tests/tmp-checks26-crawlctl.php
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

function reset_crawl_all() {
        reset_kb_config();
        reset_crawl_config();
        $GLOBALS['opts']['hami_ai_crawl_state'] = array();
        $GLOBALS['chunk_rows']                  = array();
        $GLOBALS['http_log']                    = array();
        $GLOBALS['http_map']                    = array();
}

function crawl_state() {
        return $GLOBALS['opts']['hami_ai_crawl_state'];
}

echo "=== A. sanitize_crawl_types — whitelist + sane floor ===\n";
ok( 'valid slugs kept', array( 'page', 'product', 'post' ) === Haa_Settings::sanitize_crawl_types( array( 'page', 'product', 'post' ) ) );
ok( 'junk dropped', array( 'page' ) === Haa_Settings::sanitize_crawl_types( array( 'page', 'wp_users', '' ) ) );
ok( 'empty selection degrades to pages', array( 'page' ) === Haa_Settings::sanitize_crawl_types( array() ) );
ok( 'non-array degrades to pages', array( 'page' ) === Haa_Settings::sanitize_crawl_types( 'x' ) );
ok( 'deduped', array( 'post' ) === Haa_Settings::sanitize_crawl_types( array( 'post', 'post' ) ) );
ok( 'order stable', array( 'post', 'page' ) === Haa_Settings::sanitize_crawl_types( array( 'post', 'page' ) ) );

echo "=== B. config types — default + round-trip ===\n";
reset_crawl_all();
Haa_Settings::save_section( 'knowledge', array( 'urls' => '', 'max_pages' => 30, 'auto_crawl' => 1 ) );
reset_crawl_config();
ok( 'default crawl_types is pages-only', array( 'page' ) === (array) Haa_Settings::get( 'knowledge.crawl_types' ) );
ok( 'config mirrors pages-only', array( 'page' ) === Haa_Crawl::config()['types'] );
Haa_Settings::save_section( 'knowledge', array( 'crawl_types' => array( 'product', 'post' ) ) );
reset_crawl_config();
ok( 'saved types round-trip', array( 'product', 'post' ) === Haa_Crawl::config()['types'] );
Haa_Settings::save_section( 'knowledge', array( 'crawl_types' => array() ) );
reset_crawl_config();
ok( 'empty saved types fall back to pages', array( 'page' ) === Haa_Crawl::config()['types'] );

echo "=== C. type_counts — shape + WooCommerce gating ===\n";
$counts = Haa_Crawl::type_counts();
ok( 'three type keys present', isset( $counts['page'], $counts['post'], $counts['product'] ) );
ok( 'counts are ints', is_int( $counts['page'] ) && is_int( $counts['product'] ) && is_int( $counts['post'] ) );

echo "=== D. targets — priority order (homepage → custom → pages → products → posts) ===\n";
reset_crawl_all();
$GLOBALS['posts'] = array(
        array( 'id' => 700, 'title' => 'تماس با ما', 'type' => 'page', 'status' => 'publish' ),
        array( 'id' => 701, 'title' => 'درباره ما', 'type' => 'page', 'status' => 'publish' ),
        array( 'id' => 800, 'title' => 'محصول الف', 'type' => 'product', 'status' => 'publish' ),
        array( 'id' => 801, 'title' => 'محصول ب', 'type' => 'product', 'status' => 'publish' ),
        array( 'id' => 900, 'title' => 'مقاله یک', 'type' => 'post', 'status' => 'publish' ),
        array( 'id' => 901, 'title' => 'مقاله دو', 'type' => 'post', 'status' => 'publish' ),
);
Haa_Settings::save_section( 'knowledge', array(
        'urls'        => "https://local.test/instagram\nhttp://local.test/contact-us",
        'auto_crawl'  => 1,
        'max_pages'   => 100,
        'crawl_types' => array( 'page', 'product', 'post' ),
) );
reset_crawl_config();
$targets = Haa_Crawl::targets();
$joined  = implode( "\n", $targets );
$pos_home   = 0; // index 0
$pos_custom = strpos( $joined, 'local.test/instagram' );
$pos_page   = strpos( $joined, '?post=700' );
$pos_prod   = strpos( $joined, '?post=800' );
$pos_post   = strpos( $joined, '?post=900' );
ok( 'homepage is index 0', 'http://local.test' === $targets[0] || 'http://local.test/' === $targets[0] );
ok( 'custom URLs come before pages', false !== $pos_custom && false !== $pos_page && $pos_custom < $pos_page, '(custom@' . $pos_custom . ' page@' . $pos_page . ')' );
ok( 'pages before products', false !== $pos_page && false !== $pos_prod && $pos_page < $pos_prod );
ok( 'products before posts', false !== $pos_prod && false !== $pos_post && $pos_prod < $pos_post );
ok( 'all ticked types queued (1+2+2+2+2)', 9 === count( $targets ), '(' . count( $targets ) . ')' );
$unique_ok = true;
foreach ( array( 700, 701, 800, 801, 900, 901 ) as $pid ) {
        if ( 1 !== count( preg_grep( '#\?post=' . $pid . '#', $targets ) ) ) { $unique_ok = false; }
}
ok( 'each seeded id queued exactly once (drafts never seeded)', $unique_ok );

// pages-only default: products/posts must NOT join (save_section replaces the
// section, so every save carries the full key set).
Haa_Settings::save_section( 'knowledge', array(
        'urls'        => "https://local.test/instagram\nhttp://local.test/contact-us",
        'auto_crawl'  => 1,
        'max_pages'   => 100,
        'crawl_types' => array( 'page' ),
) );
reset_crawl_config();
$targets_pages = Haa_Crawl::targets();
ok( 'pages-only excludes products and posts', 5 === count( $targets_pages ) && false === strpos( implode( "\n", $targets_pages ), '?post=800' ) && false === strpos( implode( "\n", $targets_pages ), '?post=900' ), '(' . count( $targets_pages ) . ')' );

// products-only: contact page stays out, shop catalogue comes in.
Haa_Settings::save_section( 'knowledge', array(
        'urls'        => "https://local.test/instagram\nhttp://local.test/contact-us",
        'auto_crawl'  => 1,
        'max_pages'   => 100,
        'crawl_types' => array( 'product' ),
) );
reset_crawl_config();
$targets_prod = Haa_Crawl::targets();
$jp = implode( "\n", $targets_prod );
ok( 'products-only excludes pages/posts', false === strpos( $jp, '?post=700' ) && false === strpos( $jp, '?post=900' ) && false !== strpos( $jp, '?post=800' ) );

// budget: a small cap keeps the queue small no matter what is ticked.
Haa_Settings::save_section( 'knowledge', array(
        'urls'        => "https://local.test/instagram\nhttp://local.test/contact-us",
        'auto_crawl'  => 1,
        'max_pages'   => 5,
        'crawl_types' => array( 'page', 'product', 'post' ),
) );
reset_crawl_config();
ok( 'budget cap respected (5)', 5 === count( Haa_Crawl::targets() ) );
Haa_Settings::save_section( 'knowledge', array(
        'urls'        => "https://local.test/instagram\nhttp://local.test/contact-us",
        'auto_crawl'  => 1,
        'max_pages'   => 30,
        'crawl_types' => array( 'page', 'product', 'post' ),
) );

echo "=== E. start/resume — «ادامهِ خزش» must never restart from zero ===\n";
reset_crawl_all();
$GLOBALS['http_map'] = array( 'local.test' => array( 'body' => '<html><head><title>ت</title></head><body><p>محتوای آزمایشی کافی برای تکه‌سازی و ایندکس شدن در پایگاه دانش آزمایشی — ' . str_repeat( 'متن نمونه ', 30 ) . '</p></body></html>' ) );
Haa_Settings::save_section( 'knowledge', array( 'urls' => '', 'auto_crawl' => 1, 'max_pages' => 30, 'crawl_types' => array( 'page' ) ) );
$GLOBALS['posts'] = array(
        array( 'id' => 700, 'title' => 'تماس با ما', 'type' => 'page', 'status' => 'publish' ),
        array( 'id' => 701, 'title' => 'درباره ما', 'type' => 'page', 'status' => 'publish' ),
        array( 'id' => 702, 'title' => 'قوانین', 'type' => 'page', 'status' => 'publish' ),
        array( 'id' => 703, 'title' => 'ارسال', 'type' => 'page', 'status' => 'publish' ),
);
reset_crawl_config();
$start = Haa_Crawl::start();
ok( 'fresh start: running with cursor at 0', 1 === (int) $start['running'] && 0 === (int) $start['offset'] && $start['total'] >= 5 );
Haa_Crawl::batch( 2 );
ok( 'one batch advanced the cursor to 2', 2 === (int) Haa_Crawl::progress()['offset'] );
$resumed = Haa_Crawl::start();
ok( 'start() RESUMES (offset kept)', 1 === (int) $resumed['running'] && 2 === (int) $resumed['offset'], '(offset=' . $resumed['offset'] . ')' );
Haa_Crawl::batch( 2 );
$mid = Haa_Crawl::progress();
ok( 'second batch advanced to 4', 4 === (int) $mid['offset'] );
$reset = Haa_Crawl::start( true );
ok( 'explicit reset rebuilds from zero', 1 === (int) $reset['running'] && 0 === (int) $reset['offset'] );
Haa_Crawl::cancel();
ok( 'cancel stops the run', 0 === (int) Haa_Crawl::progress()['running'] );
$after_cancel = Haa_Crawl::start();
ok( 'start after cancel runs fresh', 1 === (int) $after_cancel['running'] && 0 === (int) $after_cancel['offset'] );
Haa_Crawl::cancel();

echo "=== F. progress — heartbeat, pages_left, ETA, elapsed ===\n";
reset_crawl_all();
$GLOBALS['http_map'] = array( 'local.test' => array( 'body' => '<html><head><title>ت</title></head><body><p>محتوای آزمایشی کافی برای تکه‌سازی — ' . str_repeat( 'متن ', 30 ) . '</p></body></html>' ) );
reset_crawl_config();
$st = Haa_Crawl::start();
foreach ( array( 'pages_left', 'current', 'current_at', 'eta_s', 'elapsed_s' ) as $k ) {
        ok( 'progress carries ' . $k, array_key_exists( $k, $st ) );
}
ok( 'pages_left = total at start', (int) $st['total'] === (int) $st['pages_left'] );
ok( 'eta_s positive while running', (int) $st['eta_s'] > 0 );
Haa_Crawl::batch( 2 );
$p2 = Haa_Crawl::progress();
ok( 'heartbeat records the last read URL', '' !== (string) $p2['current'] && false !== strpos( (string) $p2['current'], 'local.test' ), '(' . $p2['current'] . ')' );
ok( 'pages_left shrinks', (int) $p2['pages_left'] === (int) $p2['total'] - (int) $p2['offset'] );
$state = crawl_state();
ok( 'timing accumulator grew', (int) $state['ms_n'] >= 2 && (int) $state['ms_sum'] >= 0 );
ok( 'started_at set', (int) $state['started_at'] > 0 );
Haa_Crawl::cancel();

echo "=== G. discovery skip list — commerce/utility URLs never queued ===\n";
$home_html = '<html><body>'
        . '<a href="/">خانه</a>'
        . '<a href="/cart">سبد خرید</a>'
        . '<a href="/checkout-2/">پرداخت</a>'
        . '<a href="/my-account/">حساب کاربری</a>'
        . '<a href="/shop?add-to-cart=801">افزودن به سبد</a>'
        . '<a href="/?attachment_id=55">پیوست</a>'
        . '<a href="/blog/page/2/">صفحه ۲</a>'
        . '<a href="?post=701">درباره ما</a>'
        . '</body></html>';
// Mirror real usage: the homepage itself is always already queued, so the
// self-link must dedupe away and only the real page is discovered.
$links = Haa_Crawl::links_from_home( $home_html, array( 'http://local.test' => 1 ), 20 );
$jl    = implode( "\n", $links );
ok( 'cart skipped', false === strpos( $jl, '/cart' ) );
ok( 'checkout skipped', false === strpos( $jl, 'checkout' ) );
ok( 'my-account skipped', false === strpos( $jl, 'my-account' ) );
ok( 'add-to-cart skipped', false === strpos( $jl, 'add-to-cart' ) );
ok( 'attachment + pagination skipped', false === strpos( $jl, 'attachment_id' ) && false === strpos( $jl, '/page/2' ) );
ok( 'real page still discovered', false !== strpos( $jl, '?post=701' ) && 1 === count( $links ) );

echo "=== H. fetch — tight timeout keeps ticks inside host limits ===\n";
reset_crawl_all();
$GLOBALS['http_map'] = array( 'local.test/x' => array( 'body' => '<html><body><p>' . str_repeat( 'ک', 200 ) . '</p></body></html>' ) );
Haa_Crawl::fetch( 'http://local.test/x' );
$last = end( $GLOBALS['http_log'] );
ok( 'timeout is 6s', isset( $last['args']['timeout'] ) && 6 === (int) $last['args']['timeout'] );
ok( 'redirection still 2', isset( $last['args']['redirection'] ) && 2 === (int) $last['args']['redirection'] );

echo "=== I. admin wiring — reset flag, stop ajax, dashboard controls ===\n";
$src = file_get_contents( HAAI_DEV_ROOT . 'includes/class-haa-crawl.php' );
ok( 'handle_start reads the reset flag', false !== strpos( $src, 'haai_crawl_reset' ) );
ok( 'start() resume guard present', false !== strpos( $src, 'public static function start( $reset = false )' ) );
$dash = file_get_contents( HAAI_DEV_ROOT . 'includes/class-haa-admin-dashboard.php' );
ok( 'dashboard has stop button', false !== strpos( $dash, 'data-haai-crawl-stop' ) );
ok( 'dashboard has restart button', false !== strpos( $dash, 'haai_crawl_reset' ) );
ok( 'ticker retries with backoff', false !== strpos( $dash, 'MAX_RETRY' ) && false !== strpos( $dash, 'setTimeout(tick, wait * 1000)' ) );
ok( 'ticker shows live current URL', false !== strpos( $dash, 'در حال خواندن' ) );
ok( 'scope preview next to the button', false !== strpos( $dash, 'در صف خزش' ) && false !== strpos( $dash, 'data-haai-crawl-scope' ) );
$admin = file_get_contents( HAAI_DEV_ROOT . 'includes/class-haa-admin.php' );
ok( 'knowledge tab has the type multicheck', false !== strpos( $admin, "key'     => 'knowledge.crawl_types'" ) );
ok( 'multicheck labels carry counts', false !== strpos( $admin, 'برگه‌ها (%s)' ) );

echo "=== J. settings integrity — ui keys + sanitize map ===\n";
$rcm  = new ReflectionMethod( 'Haa_Settings', 'collect_ui_keys' );
$rcm->setAccessible( true );
$all  = $rcm->invoke( null );
ok( 'collect_ui_keys includes crawl_types', in_array( 'knowledge.crawl_types', $all, true ) );
Haa_Settings::save_section( 'knowledge', array( 'crawl_types' => array( 'page', 'editor' ) ) );
ok( 'save sanitizes junk slugs', array( 'page' ) === (array) Haa_Settings::get( 'knowledge.crawl_types' ) );

echo "\n";
if ( 0 === $fail ) {
        echo "ALL CHECKS PASSED (" . 'suite 26' . ")\n";
} else {
        echo "FAILURES: $fail\n";
        exit( 1 );
}
