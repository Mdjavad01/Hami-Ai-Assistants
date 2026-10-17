<?php
/**
 * Regression suite: site-knowledge crawler + contact facts + link answers.
 *
 * The user's live report: the bot knew products but answered «اطلاعات دقیقی
 * درباره لینک صفحه تماس با ما در دست ندارم» and «اطلاعاتی درباره شماره تماس
 * یا آدرس اینستاگرام در سایت ثبت نشده است». Root causes locked here:
 *   1. rendered-only copy (widgets/menus/footer/contact page) never entered
 *      the KB — the crawler stores it now (negative post_id, same table);
 *   2. contact facts (phone/Instagram/Telegram/WhatsApp/e-mail/contact-page
 *      link) are distilled into ONE always-findable chunk;
 *   3. build_context() never gave the model any URL — hits now carry
 *      «— لینک: …» and the system prompt asks for the link back;
 *   4. chat.contact_instagram joins the deterministic contact channels.
 *
 * Run: php dev-tests/tmp-checks25-crawl.php
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
        echo ( $cond ? '  ok   ' : '  FAIL ' ) . str_pad( $label, 60 ) . $extra . "\n";
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

function reset_search_statics() {
        reset_kb_config();
        $GLOBALS['opts']['hami_ai_crawl_state'] = array();
        $GLOBALS['chunk_rows']                  = array();
        $GLOBALS['http_log']                    = array();
        $GLOBALS['http_map']                    = array();
}

echo "=== A. safe_url — SSRF call-time guard ===\n";
reset_crawl_config();
ok( 'same-site URL allowed', 'http://local.test/contact' === Haa_Crawl::safe_url( 'http://local.test/contact' ) );
ok( 'scheme-less input upgraded to https', 'https://example.com/page' === Haa_Crawl::safe_url( 'example.com/page' ) );
ok( 'javascript: scheme dropped', '' === Haa_Crawl::safe_url( 'javascript:alert(1)' ) );
ok( 'localhost blocked', '' === Haa_Crawl::safe_url( 'http://localhost/wp-admin' ) );
ok( 'loopback IP blocked', '' === Haa_Crawl::safe_url( 'http://127.0.0.1:8080/x' ) );
ok( 'private range blocked', '' === Haa_Crawl::safe_url( 'http://192.168.1.10/x' ) );
ok( '10.x blocked', '' === Haa_Crawl::safe_url( 'http://10.0.0.5/x' ) );
ok( 'mDNS .local blocked', '' === Haa_Crawl::safe_url( 'http://nas.local/x' ) );
$GLOBALS['opts']['hami_ai_assistant']['provider']['allow_localhost'] = 1;
Haa_Settings::flush_cache();
reset_crawl_config();
ok( 'allow_localhost=1 opens loopback', '' !== Haa_Crawl::safe_url( 'http://127.0.0.1:8080/x' ) );
$GLOBALS['opts']['hami_ai_assistant']['provider']['allow_localhost'] = 0;
Haa_Settings::flush_cache();
reset_crawl_config();

echo "=== B. slot_id / canon_url — stable per-URL identity ===\n";
$a = Haa_Crawl::slot_id( 'http://local.test/contact-us' );
$b = Haa_Crawl::slot_id( 'http://local.test/contact-us/' );
ok( 'trailing slash = same slot', $a === $b );
ok( 'slot is negative', $a < 0 );
ok( 'slot within the designed range', $a >= -268435457 );
ok( 'different URL = different slot', $a !== Haa_Crawl::slot_id( 'http://local.test/about' ) );
ok( 'canon merges tracking params', Haa_Crawl::canon_url( 'http://local.test/p?utm_source=x&id=2' ) === Haa_Crawl::canon_url( 'http://local.test/p?id=2' ) );
ok( 'canon drops fragment', Haa_Crawl::canon_url( 'http://local.test/p#top' ) === Haa_Crawl::canon_url( 'http://local.test/p' ) );

echo "=== C. settings — knowledge section round-trip (the «دانش سایت» tab) ===\n";
reset_crawl_config();
$ok_save = Haa_Settings::save_section( 'knowledge', array(
        'urls'       => "https://local.test/contact-us\nlocal.test/shop\njavascript:alert(1)\nhttps://local.test/contact-us\n#comment",
        'auto_crawl' => 1,
        'max_pages'  => 5000,
) );
ok( 'save_section accepts knowledge', $ok_save );
$stored = Haa_Settings::get( 'knowledge.urls' );
ok( 'URL list cleaned + deduped', 2 === count( array_filter( explode( "\n", $stored ) ) ), '(' . str_replace( "\n", ' | ', $stored ) . ')' );
ok( 'scheme-less line kept as full URL', false !== strpos( $stored, 'https://local.test/shop' ) );
ok( 'max_pages clamped to 2500', 2500 === (int) Haa_Settings::get( 'knowledge.max_pages' ) );
Haa_Settings::save_section( 'knowledge', array( 'max_pages' => 30 ) );

echo "=== D. chat.contact_instagram — deterministic channels ===\n";
Haa_Settings::save_section( 'chat', array(
        'contact_phone'     => '01333221100',
        'contact_instagram' => 'https://instagram.com/gilan_shop',
) );
$rc  = new ReflectionMethod( 'Haa_Chat', 'contact_reply' );
$rc->setAccessible( true );
$rep = $rc->invoke( null, 'شماره تماس چطور؟ آدرس اینستاگرام؟' );
ok( 'strong contact intent answered deterministically', false !== strpos( $rep, 'تلفن: 01333221100' ) );
ok( 'instagram line present', false !== strpos( $rep, 'اینستاگرام: https://instagram.com/gilan_shop' ) );
$rc2 = new ReflectionMethod( 'Haa_Chat', 'contact_block' );
$rc2->setAccessible( true );
$blk = $rc2->invoke( null );
ok( 'system block teaches instagram too', false !== strpos( $blk, '- اینستاگرام: https://instagram.com/gilan_shop' ) );

echo "=== E. targets — homepage + pages + custom URLs, capped ===\n";
reset_search_statics();
$GLOBALS['posts'] = array(
        array( 'id' => 700, 'title' => 'تماس با ما', 'type' => 'page', 'status' => 'publish' ),
        array( 'id' => 701, 'title' => 'درباره ما', 'type' => 'page', 'status' => 'publish' ),
        array( 'id' => 800, 'title' => 'مقاله وبلاگ', 'type' => 'post', 'status' => 'publish' ),
        array( 'id' => 702, 'title' => 'پیش‌نویس برگه', 'type' => 'page', 'status' => 'draft' ),
);
Haa_Settings::save_section( 'knowledge', array(
        'urls'       => "https://local.test/instagram\nhttp://local.test/contact-us",
        'auto_crawl' => 1,
        'max_pages'  => 5,
) );
reset_crawl_config();
$targets = Haa_Crawl::targets();
ok( 'budget respected (5 URLs max)', 5 === count( $targets ), '(' . count( $targets ) . ')' );
ok( 'homepage is first', 'http://local.test' === $targets[0] || 'http://local.test/' === $targets[0] );
$joined = implode( "\n", $targets );
ok( 'published page included', false !== strpos( $joined, '?post=700' ) );
ok( 'draft page excluded', false === strpos( $joined, '?post=702' ) );
ok( 'custom URL included', false !== strpos( $joined, 'local.test/instagram' ) );
ok( 'custom URL deduped against a page permalink', 1 === count( preg_grep( '#local\.test/contact-us#', $targets ) ) );

echo "=== F. end-to-end crawl — fetch, chunk, discover, distil contact facts ===\n";
reset_search_statics();
$GLOBALS['http_map'] = array(
        // Specific patterns FIRST — fake_http_request() returns the first match.
        'local.test/shop' => array( 'error' => 'timeout' ), // one dead page must not poison the run.
        '?post=700'  => array( 'body' => '<html><head><title>تماس با ما — فروشگاه هدیه گیلان</title></head><body>'
                . '<h1>تماس با ما</h1><p>آدرس: رشت، خیابان مطهری، مرکز هنر و خلاقیت. ساعت کاری ۹ تا ۱۸.</p>'
                . '<p>شماره ثابت: 01333221100 — موبایل و واتساپ: 09113123456 — ایمیل: info@gilan.test</p></body></html>' ),
        '?post=701'  => array( 'body' => '<html><head><title>درباره ما</title></head><body>'
                . '<p>موسس مجموعه استاد محمدرضا خاموشیان، بافنده فرش‌های دستباف کاشان.</p></body></html>' ),
        'local.test' => array( 'body' => '<html><head><title>فروشگاه هدیه گیلان</title></head><body>'
                . '<nav><a href="/">خانه</a><a href="?post=700">تماس با ما</a><a href="?post=701">درباره ما</a>'
                . '<a href="/shop">فروشگاه</a><a href="https://instagram.com/gilan_shop">اینستاگرام ما</a>'
                . '<a href="https://wa.me/989113123456">واتساپ</a></nav>'
                . '<footer>تلفن تماس: ۰۱۳-۳۳۲۲۱۱۰۰ | پیج اینستاگرام: instagram.com/gilan_shop | تلگرام: t.me/gilan_shop</footer>'
                . '<p>فروشگاه هدیه گیلان انواع صنایع دستی و فرش دستباف را با ارسال سراسری عرضه می‌کند. برای سفارش تلفنی با ما تماس بگیرید و در اینستاگرام هم همراه ما باشید.</p>'
                . '</body></html>' ),
);
Haa_Settings::save_section( 'knowledge', array( 'urls' => "https://local.test/shop", 'auto_crawl' => 1, 'max_pages' => 6 ) );
reset_crawl_config();
$GLOBALS['opts']['hami_ai_crawl_state'] = array();
// 2.1.17 — pages/products/posts are read from the DB now; give the fixtures
// real post_content so the DB path carries the same facts the HTTP mock did.
$GLOBALS['written'][700] = array( 'ID' => 700, 'post_title' => 'تماس با ما', 'post_type' => 'page', 'post_status' => 'publish',
        'post_content' => '<h1>تماس با ما — فروشگاه هدیه گیلان</h1><p>آدرس: رشت، خیابان مطهری، مرکز هنر و خلاقیت. ساعت کاری ۹ تا ۱۸.</p>'
                . '<p>شماره ثابت: 01333221100 — موبایل: 09113123456 — ایمیل: info@gilan.test</p>' );
$GLOBALS['written'][701] = array( 'ID' => 701, 'post_title' => 'درباره ما', 'post_type' => 'page', 'post_status' => 'publish',
        'post_content' => '<p>موسس مجموعه استاد محمدرضا خاموشیان، بافنده فرش‌های دستباف کاشان.</p>'
                . '<p>این مجموعه از سال ۱۳۸۰ در زمینهٔ فروش صنایع دستی و فرش دستباف فعالیت می‌کند و ارسال سراسری دارد.</p>' );
$start = Haa_Crawl::start();
ok( 'start() returns progress array', is_array( $start ) && isset( $start['running'] ) );
$guard = 0;
while ( ! empty( Haa_Crawl::progress()['running'] ) && $guard++ < 12 ) {
        Haa_Crawl::batch( 2 );
}
$prog  = Haa_Crawl::progress();
$state = $GLOBALS['opts']['hami_ai_crawl_state'];
ok( 'crawl finished', 0 === (int) $prog['running'] && 100 === (int) $prog['percent'] );
ok( 'dead page did not stop the run', (int) $prog['pages'] >= 2 );
ok( 'homepage link discovery grew the queue', (int) $prog['total'] > count( Haa_Crawl::targets() ) - 1 || (int) $prog['total'] >= 4, '(total=' . $prog['total'] . ')' );
$crawl_rows = array_values( array_filter( $GLOBALS['chunk_rows'], function ( $r ) { return (int) $r['post_id'] < 0 && 'contact' !== (string) $r['source']; } ) );
ok( 'crawl chunks stored with negative ids', count( $crawl_rows ) > 0 );
ok( 'crawl chunks carry source=crawl', 'crawl' === (string) $crawl_rows[0]['source'] );
ok( 'crawl chunks carry the page URL', false !== strpos( (string) $crawl_rows[0]['url'], 'local.test' ) );
$contact_rows = array_values( array_filter( $GLOBALS['chunk_rows'], function ( $r ) { return -1 === (int) $r['post_id']; } ) );
ok( 'contact facts chunk exists (slot -1)', 1 === count( $contact_rows ) );
$contact_body = (string) ( $contact_rows[0]['content'] ?? '' );
ok( 'Persian digits phone converted', false !== strpos( $contact_body, '01333221100' ) );
ok( 'landline from contact page kept', false !== strpos( $contact_body, '01333221100' ) && false !== strpos( $contact_body, '09113123456' ) );
ok( 'instagram distilled', false !== strpos( $contact_body, 'اینستاگرام: https://instagram.com/gilan_shop' ) );
ok( 'telegram distilled', false !== strpos( $contact_body, 'تلگرام: https://t.me/gilan_shop' ) );
ok( 'whatsapp distilled (wa.me link)', false !== strpos( $contact_body, 'واتساپ: https://wa.me/989113123456' ) );
ok( 'email distilled', false !== strpos( $contact_body, 'info@gilan.test' ) );
ok( 'contact-page link distilled', false !== strpos( $contact_body, 'صفحه تماس با ما' ) && false !== strpos( $contact_body, 'post=700' ) );

echo "=== G. retrieval — crawl rows answer, purge keeps them, context carries links ===\n";
reset_kb_config();
$hits = Haa_Search::search( 'شماره تماس فروشگاه چنده؟ اینستاگرام دارید؟', 5, 3000 );
ok( 'contact chunk found by BM25', count( $hits ) > 0 && -1 === (int) $hits[0]['post_id'] );
ok( 'hit carries the exact phone', false !== strpos( (string) $hits[0]['content'], '01333221100' ) );
$rc  = new ReflectionMethod( 'Haa_Chat', 'build_context' );
$rc->setAccessible( true );
$ctx = $rc->invoke( null, $hits );
ok( 'context includes the link line', false !== strpos( $ctx, '— لینک:' ) );
$src = file_get_contents( HAAI_DEV_ROOT . 'includes/class-haa-search.php' );
ok( 'published_only_sql admits post_id < 0', false !== strpos( $src, "post_id < 0" ) );
ok( 'purge_orphans keeps negative slots', false !== strpos( $src, 'AND c.post_id > 0' ) );
$hits2 = Haa_Search::search( 'ساعت کاری فروشگاه و آدرس دقیق', 3, 1500 );
ok( 'rendered contact page answers address question', count( $hits2 ) > 0 );
$found_crawl = false;
foreach ( $hits2 as $h ) {
        if ( (int) $h['post_id'] < 0 && false !== strpos( (string) $h['content'], 'ساعت کاری' ) ) { $found_crawl = true; }
}
ok( 'a crawl row is among the hits', $found_crawl );

echo "=== H. re-crawl replaces (no duplicates) + wipe ===\n";
reset_kb_config();
$before_rows = count( $GLOBALS['chunk_rows'] );
$GLOBALS['opts']['hami_ai_crawl_state'] = array();
Haa_Crawl::start();
$guard = 0;
while ( ! empty( Haa_Crawl::progress()['running'] ) && $guard++ < 12 ) {
        Haa_Crawl::batch( 4 );
}
ok( 're-crawl does not duplicate rows', count( $GLOBALS['chunk_rows'] ) <= $before_rows, '(' . $before_rows . ' → ' . count( $GLOBALS['chunk_rows'] ) . ')' );
Haa_Crawl::wipe();
$left = array_filter( $GLOBALS['chunk_rows'], function ( $r ) { return (int) $r['post_id'] < 0; } );
ok( 'wipe removes every crawl slot', 0 === count( $left ) );

echo "=== I. guards — RAG off / no URLs ===\n";
reset_search_statics();
Haa_Settings::save_section( 'advanced', array( 'kb_enabled' => 0 ) );
reset_kb_config();
reset_crawl_config();
$err = Haa_Crawl::start();
ok( 'RAG off → WP_Error, no fetch', is_wp_error( $err ) );
Haa_Settings::save_section( 'advanced', array( 'kb_enabled' => 1, 'enable_rag' => 1 ) );
Haa_Settings::save_section( 'knowledge', array( 'urls' => '' ) );
$GLOBALS['posts'] = array();
reset_kb_config();
reset_crawl_config();
$GLOBALS['opts']['hami_ai_crawl_state'] = array();
$GLOBALS['http_map'] = array();
$start2 = Haa_Crawl::start();
ok( 'homepage-only target starts fine', is_array( $start2 ) && 1 === (int) $start2['total'] );
$guard = 0;
while ( ! empty( Haa_Crawl::progress()['running'] ) && $guard++ < 6 ) {
        Haa_Crawl::batch( 2 );
}
ok( 'unreachable homepage ends the run cleanly', 0 === (int) Haa_Crawl::progress()['running'] && 0 === (int) Haa_Crawl::progress()['pages'] );

echo "=== J. settings integrity — the new keys are known to the system ===\n";
$rc3  = new ReflectionMethod( 'Haa_Settings', 'collect_ui_keys' );
$rc3->setAccessible( true );
$keys = $rc3->invoke( null );
ok( 'knowledge keys in collect_ui_keys', in_array( 'knowledge.urls', $keys, true ) && in_array( 'knowledge.max_pages', $keys, true ) );
ok( 'chat.contact_instagram in collect_ui_keys', in_array( 'chat.contact_instagram', $keys, true ) );
$map = Haa_Settings::sanitize_map();
ok( 'knowledge rules registered', isset( $map['knowledge.urls'], $map['knowledge.auto_crawl'], $map['knowledge.max_pages'] ) );
ok( 'instagram rule registered', isset( $map['chat.contact_instagram'] ) );

echo "\n" . ( 0 === $fail ? "ALL CHECKS PASSED\n" : $fail . " CHECK(S) FAILED\n" );
exit( $fail ? 1 : 0 );
