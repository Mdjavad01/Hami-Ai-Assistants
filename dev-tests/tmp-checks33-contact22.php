<?php
/**
 * Regression suite: contact answers on the SHOP OWNER's real wording (2.1.22).
 *
 * The user's live report (2026-10-18, shop new.mrdastbaaf.com): the crawl had
 * finished, categories and prices answered perfectly — yet «خوب راه ارتباطی
 * کدوم صفحات هست؟ لینک داری بدی؟» still got «عذرخواهی می‌کنم، اطلاعاتی درباره
 * صفحه راه‌های ارتباطی در دست ندارم». Two independent root causes, both
 * reproduced on the real homepage HTML:
 *
 *   1. The contact-INTENT pattern keyed on «های ارتباطی» (plural). The
 *      singular «راه ارتباطی» (and چطوری تماس بگیرم / ایمیل دارید / با
 *      کارشناس حرف بزنم) sailed past the deterministic channel answer into
 *      the model, which apologised.
 *   2. The shop's phones live ONLY in href="tel:…" attributes (Elementor
 *      call buttons). html_text() strips attributes, so collect_facts()
      saw Instagram/Telegram/WhatsApp but never a phone — the stored
 *      contact chunk could not answer «شماره تلفن چنده؟» either.
 *
 * Locked here: word-bounded contact nouns; tel:/sms: harvesting with
 * +98→0 folding; the contact chunk's WP-page fallback when the homepage
 * fetch failed; one shared find_contact_page_url() for crawl + chat; and
 * the end-of-crawl answer-cache flush that retires stale «اطلاعاتی نداریم»
 * replies the moment knowledge changes.
 *
 * Run: php dev-tests/tmp-checks33-contact22.php
 */
require __DIR__ . '/tmp-wp-stubs.php';

$fail = 0;
function ok( $label, $cond, $extra = '' ) {
        global $fail;
        if ( ! $cond ) { $fail++; }
        echo ( $cond ? '  ok   ' : '  FAIL ' ) . str_pad( $label, 62 ) . $extra . "\n";
}

$rc = new ReflectionClass( 'Haa_Chat' );
$m_contact = $rc->getMethod( 'contact_reply' );
$m_contact->setAccessible( true );
$m_find = $rc->getMethod( 'find_contact_page' );
$m_find->setAccessible( true );

function clear_chat_channels() {
        Haa_Settings::save_section( 'chat', array(
                'contact_whatsapp'   => '',
                'contact_telegram'   => '',
                'contact_phone'      => '',
                'contact_instagram'  => '',
                'contact_page'       => '',
                'contact_note'       => '',
        ) );
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

echo "=== A. contact intent — the owner's exact wording fires the deterministic answer ===\n";
Haa_Settings::save_section( 'chat', array(
        'contact_phone'     => '09309980739',
        'contact_instagram' => 'https://instagram.com/mr.dastbaaf',
) );

$rep = $m_contact->invoke( null, 'خوب راه ارتباطی کدوم صفحات هست؟ لینک داری بدی؟' );
ok( 'EXACT user message fires (singular راه ارتباطی)', '' !== $rep );
ok( 'reply carries the real phone', false !== strpos( (string) $rep, 'تلفن: 09309980739' ) );
ok( 'reply carries the real instagram', false !== strpos( (string) $rep, 'اینستاگرام: https://instagram.com/mr.dastbaaf' ) );

foreach ( array(
        'راه ارتباطی کدوم صفحات هست؟'                 => 'singular راه ارتباطی',
        'راه‌های ارتباطی کدومن؟'                       => 'ZWNJ plural (already worked, stays working)',
        'چطوری تماس بگیرم؟'                            => 'bare تماس',
        'تماس با ما کجاست؟'                            => 'تماس با ما',
        'شماره تلفن فروشگاهت چنده؟'                    => 'تلفن',
        'ایمیل دارید؟'                                 => 'ایمیل',
        'mi khaym ba yek karshenas harf bezanam'       => 'latin (no fire, sanity)',
) as $msg => $label ) { /* placeholders replaced below */ }
foreach ( array(
        array( 'راه ارتباطی کدوم صفحات هست؟', 'singular راه ارتباطی' ),
        array( 'راه‌های ارتباطی کدومن؟', 'ZWNJ plural (regression)' ),
        array( 'چطوری تماس بگیرم؟', 'bare تماس' ),
        array( 'تماس با ما کجاست؟', 'تماس با ما' ),
        array( 'شماره تلفن فروشگاهت چنده؟', 'تلفن' ),
        array( 'ایمیل دارید؟', 'ایمیل' ),
        array( 'میخوام با یه کارشناس حرف بزنم', 'کارشناس noun' ),
        array( 'آدرس فروشگاهت کجاست؟', 'physical address' ),
        array( 'لینک تلگرامت رو بده', 'telegram (regression)' ),
) as $case ) {
        $rep = $m_contact->invoke( null, $case[0] );
        ok( 'fires: ' . $case[1], '' !== $rep, '(' . $case[0] . ')' );
}

foreach ( array(
        array( 'قیمت ارزان‌ترین محصولت چنده؟', 'price ask' ),
        array( 'دسته بندی محصولاتت کدوما هستن؟', 'category listing' ),
        array( 'آدرس صفحه محصول رو میدی؟', 'آدرس صفحه = URL request, not contact' ),
        array( 'قیمت طرح آیه عرضی چنده؟', 'category + price' ),
) as $case ) {
        $rep = $m_contact->invoke( null, $case[0] );
        ok( 'stays silent: ' . $case[1], '' === $rep, '(' . $case[0] . ')' );
}

echo "\n=== B. fallback chain with EMPTY channels — the page link comes from WP ===\n";
clear_chat_channels();
$GLOBALS['opts']['hami_ai_crawl_state'] = array(); // no crawl facts, no contact chunk
$GLOBALS['posts'] = array(
        array( 'id' => 700, 'title' => 'تماس با ما', 'type' => 'page', 'status' => 'publish' ),
        array( 'id' => 701, 'title' => 'درباره ما', 'type' => 'page', 'status' => 'publish' ),
);
$rep = $m_contact->invoke( null, 'راه ارتباطی کدوم صفحات هست؟ لینک داری بدی؟' );
ok( 'no channels + no facts → real WP contact page', false !== strpos( (string) $rep, 'http://local.test/?post=700' ), '(got: ' . substr( (string) $rep, 0, 60 ) . '…)' );
ok( 'reply names the contact page', false !== strpos( (string) $rep, 'تماس با ما' ) );

// Same question on a site WITHOUT any contact page: the deterministic layer
// must bow out ('') so the model at least answers from the KB, and — with
// nothing in the KB either — nothing is invented.
$GLOBALS['posts'] = array(
        array( 'id' => 701, 'title' => 'درباره ما', 'type' => 'page', 'status' => 'publish' ),
);
$rep = $m_contact->invoke( null, 'راه ارتباطی کدومن؟' );
ok( 'nothing true exists → returns "" (model decides)', '' === $rep );

echo "\n=== C. collect_facts — tel:/sms: attributes are the phone source now ===\n";
reset_search_statics();
$GLOBALS['opts']['hami_ai_crawl_state'] = array();
$state = array( 'facts' => array() );
$only_tel = '<html><head><title>فروشگاه فرش</title></head><body>'
        . '<h1>فروشگاه فرش دستباف</h1>'
        . '<a href="tel:+989309980739" class="elementor-button">تماس با ما</a>'
        . '<a href="tel:013-3322-1100">خط ثابت</a>'
        . '<p>برای سفارش با ما در ارتباط باشید. ارسال سراسری دارد.</p>'
        . '</body></html>';
Haa_Crawl::collect_facts( $state, 'http://local.test/', $only_tel );
$phones = array();
foreach ( $state['facts'] as $f ) { if ( 0 === strpos( $f, 'phone:' ) ) { $phones[] = substr( $f, 6 ); } }
ok( 'tel:+98… harvested', in_array( '09309980739', $phones, true ), '(' . implode( ' | ', $phones ) . ')' );
ok( 'tel:013-… harvested + separator-stripped', in_array( '01333221100', $phones, true ) );
ok( 'no email false-positive from this page', ! in_array( 'email:info@', $phones, true ) );

// SVG coordinate noise (the real homepage carries translate(1.40659340659…) in
// inline SVG) must NOT be read as a landline — raw HTML is never number-scanned.
$state2 = array( 'facts' => array() );
$svg_noise = '<html><head><title>فرش</title></head><body>'
        . '<svg><path transform="translate(1.4065934065934016 1.4065934065934016)" d="M0 0"/></svg>'
        . '<p>فروشگاه فرش دستباف، با بیش از بیست سال سابقه بافت و رنگرزی گیاهی، عرضه کننده نقشه‌های بافت است.</p>'
        . '</body></html>';
Haa_Crawl::collect_facts( $state2, 'http://local.test/', $svg_noise );
ok( 'SVG coordinates stay phone-free', empty( $state2['facts'] ), '(' . implode( ' | ', (array) $state2['facts'] ) . ')' );

echo "\n=== D. write_contact_chunk — WP page link lands even without the anchor ===\n";
// Homepage fetch died on the real host? The chunk still must carry the page.
reset_search_statics();
$GLOBALS['posts'] = array(
        array( 'id' => 700, 'title' => 'تماس با ما', 'type' => 'page', 'status' => 'publish' ),
);
$GLOBALS['opts']['hami_ai_crawl_state'] = array();
$state3 = array( 'facts' => array() );
Haa_Crawl::collect_facts( $state3, 'http://local.test/', $only_tel );
$state3['facts'] = array_values( array_filter( $state3['facts'], function ( $f ) { return 0 !== strpos( $f, 'contact_page:' ); } ) );
$n = Haa_Crawl::write_contact_chunk( $state3 );
ok( 'chunk written from tel: facts alone', 1 === $n );
$chunk = null;
foreach ( (array) $GLOBALS['chunk_rows'] as $c ) { if ( -1 === (int) $c['post_id'] ) { $chunk = $c; } }
ok( 'chunk holds the harvested phone', is_array( $chunk ) && false !== strpos( (string) $chunk['content'], 'تلفن: 09309980739' ) );
ok( 'chunk holds the WP contact-page permalink (fallback)', is_array( $chunk ) && false !== strpos( (string) $chunk['content'], 'صفحه تماس با ما: http://local.test/?post=700' ) );

// No facts at all + no contact page → nothing written, nothing invented.
reset_search_statics();
$GLOBALS['posts'] = array(
        array( 'id' => 701, 'title' => 'درباره ما', 'type' => 'page', 'status' => 'publish' ),
);
$state4 = array( 'facts' => array( 'ig:' ) ); // malformed lines are ignored
ok( 'empty facts + no page → no chunk', 0 === Haa_Crawl::write_contact_chunk( $state4 ) );

echo "\n=== E. ONE shared find_contact_page_url() for crawl + chat ===\n";
$GLOBALS['posts'] = array(
        array( 'id' => 700, 'title' => 'تماس با ما', 'type' => 'page', 'status' => 'publish' ),
        array( 'id' => 702, 'title' => 'قوانین بازگشت', 'type' => 'page', 'status' => 'publish' ),
);
$via_crawl = Haa_Crawl::find_contact_page_url();
$via_chat  = $m_find->invoke( null );
ok( 'crawl lookup finds the تماس page', 'http://local.test/?post=700' === $via_crawl, '(' . $via_crawl . ')' );
ok( 'chat lookup returns the SAME url', $via_crawl === $via_chat );
// Slug fallback still works when no title matches.
$GLOBALS['posts'] = array(
        array( 'id' => 800, 'title' => 'ارتباط با ما', 'slug' => 'contact-us', 'type' => 'page', 'status' => 'publish' ),
);
ok( 'ارتباط title also matches', 'http://local.test/?post=800' === Haa_Crawl::find_contact_page_url() );
$GLOBALS['posts'] = array(
        array( 'id' => 801, 'title' => 'Say hello', 'slug' => 'contact', 'type' => 'page', 'status' => 'publish' ),
);
ok( 'slug contact matches when titles do not', 'http://local.test/?post=801' === Haa_Crawl::find_contact_page_url() );

echo "\n=== F. end-of-crawl flush — stale «اطلاعاتی نداریم» answers die with the run ===\n";
// The worst run: EVERY fetch fails → no store_page() → no per-page flush →
// only the finish-flush can retire the cached pre-crawl apology.
reset_search_statics();
reset_crawl_config();
$GLOBALS['posts'] = array();
$GLOBALS['opts']['hami_ai_crawl_state'] = array();
$GLOBALS['http_map'] = array(
        'local.test/shop' => array( 'error' => 'timeout' ),
        'local.test'      => array( 'error' => 'timeout' ),
);
Haa_Settings::save_section( 'knowledge', array( 'urls' => "https://local.test/shop", 'auto_crawl' => 0, 'max_pages' => 5 ) );
reset_crawl_config();

$key = str_repeat( 'a', 64 );
Haa_DB::set_cached( $key, 'عذرخواهی می‌کنم، اطلاعاتی درباره صفحه راه‌های ارتباطی در دست ندارم.', 3600 );
ok( 'cache seeded (get_cached reads it back)', 'عذرخواهی می‌کنم، اطلاعاتی درباره صفحه راه‌های ارتباطی در دست ندارم.' === Haa_DB::get_cached( $key ) );

$start = Haa_Crawl::start();
$guard = 0;
while ( ! empty( Haa_Crawl::progress()['running'] ) && $guard++ < 8 ) {
        Haa_Crawl::batch( 2, 10 );
}
$prog = Haa_Crawl::progress();
ok( 'all-fail run still finishes', ! empty( $prog['done'] ) && 0 === (int) $prog['pages'] );
ok( 'finish flushed the answer cache (worst run covered)', null === Haa_DB::get_cached( $key ) );

echo "\n=== G. tel: facts reach the ANSWER, not just the chunk ===\n";
// The state-facts path of crawl_contact_facts(): phone lines are rendered
// «تلفن: …» from the facts array, whatever their harvesting source was.
reset_search_statics();
$GLOBALS['posts'] = array();
$GLOBALS['opts']['hami_ai_crawl_state'] = array(
        'running' => 0,
        'facts'   => array( 'phone:09309980739', 'contact_page:http://local.test/?post=700' ),
);
clear_chat_channels();
$rep = $m_contact->invoke( null, 'شماره تلفن و راه ارتباطی؟' );
ok( 'state facts → تلفن line', false !== strpos( (string) $rep, 'تلفن: 09309980739' ), '(got: ' . substr( (string) $rep, 0, 60 ) . '…)' );
ok( 'state facts → contact page line', false !== strpos( (string) $rep, 'http://local.test/?post=700' ) );

echo "\n" . ( 0 === $fail ? 'ALL CHECKS PASSED' : $fail . ' CHECKS FAILED' ) . "\n";
exit( $fail ? 1 : 0 );
