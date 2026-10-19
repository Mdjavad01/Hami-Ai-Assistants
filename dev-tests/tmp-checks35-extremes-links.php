<?php
/**
 * Regression suite: REAL min/max product prices + REAL page links (2.1.25).
 *
 * The user's live report (2026-10-19, shop new.mrdastbaaf.com), two chatbot
 * answers both wrong:
 *
 *   1. «ارزونترین و گرونترین محصول فروشگاهت چیه» → a hallucinated pair. The
 *      informal spellings ارزون/گرون never matched product_facts' formal-only
 *      price nouns, so NO live facts reached the model and it picked a
 *      "cheapest" and "most expensive" out of the handful of KB chunks
 *      retrieval happened to return — not the real catalogue extremes.
 *   2. «صفحه درباره ما یا ارتباط با ما ، لینک میتونی بدی؟» → phone only; the
 *      follow-up «لینک صفحه سایت بده» then made the model TYPE a mangled
 *      Persian-slug URL (/- %8فا------/). No deterministic path owned link
 *      requests and models retype percent-encoded slugs unreliably.
 *
 * Locked here: superlative intent incl. informal spellings and shipping
 * exclusion; DB-order extremes with a price>0 floor; out-of-stock extremes
 * answered WITH their in-stock twin; labeled fact lines with formatted
 * prices; page-links intent resolution from real WP/Woo data; honest
 * fallback list when a named page does not exist; contact answers carrying
 * the real contact-page URL; the anti-fabrication URL rule riding every
 * system prompt; and informal ارزون/گرون now counting as price needs in
 * product_facts.
 *
 * Run: php dev-tests/tmp-checks35-extremes-links.php
 */
require __DIR__ . '/tmp-wp-stubs.php';

$fail = 0;
function ok( $label, $cond, $extra = '' ) {
        global $fail;
        if ( ! $cond ) { $fail++; }
        echo ( $cond ? '  ok   ' : '  FAIL ' ) . str_pad( $label, 64 ) . $extra . "\n";
}

$rc          = new ReflectionClass( 'Haa_Chat' );
$m_extremes  = $rc->getMethod( 'price_extremes_reply' );
$m_extremes->setAccessible( true );
$m_links     = $rc->getMethod( 'page_links_reply' );
$m_links->setAccessible( true );
$m_resolve   = $rc->getMethod( 'resolve_site_page' );
$m_resolve->setAccessible( true );
$m_lines     = $rc->getMethod( 'main_pages_lines' );
$m_lines->setAccessible( true );
$m_facts     = $rc->getMethod( 'product_facts' );
$m_facts->setAccessible( true );
$m_contact   = $rc->getMethod( 'contact_reply' );
$m_contact->setAccessible( true );
$m_messages  = $rc->getMethod( 'build_messages' );
$m_messages->setAccessible( true );

/**
 * Seed a catalogue where the REAL extremes are unambiguous.
 *
 * 10: ارزان‌ترین, 800,000, موجود
 * 20: ارزان‌ترینِ موجود (duplicate price) — not needed; keep min unique.
 * 30: گران‌ترین, 25,000,000, ناموجود  (like the user's قابگلیم ایلیا)
 * 40: گران‌ترینِ موجود, 18,000,000, موجود
 * 50: free/zero price → must never surface (price floor)
 * 60: draft product with a low price → must never surface
 */
function seed_catalogue() {
        $GLOBALS['posts'] = array(
                array( 'id' => 10, 'title' => 'دارفرش کوچک', 'type' => 'product', 'status' => 'publish', 'name' => 'دارفرش کوچک', 'price' => '800000', 'stock' => 'instock' ),
                array( 'id' => 30, 'title' => 'قابگلیم ایلیا', 'type' => 'product', 'status' => 'publish', 'name' => 'قابگلیم ایلیا', 'price' => '25000000', 'stock' => 'outofstock' ),
                array( 'id' => 40, 'title' => 'تابلو فرش درشت', 'type' => 'product', 'status' => 'publish', 'name' => 'تابلو فرش درشت', 'price' => '18000000', 'stock' => 'instock' ),
                array( 'id' => 50, 'title' => 'هدیه رایگان', 'type' => 'product', 'status' => 'publish', 'name' => 'هدیه رایگان', 'price' => '0', 'stock' => 'instock' ),
                array( 'id' => 60, 'title' => 'پیش‌نویس گران', 'type' => 'product', 'status' => 'draft', 'name' => 'پیش‌نویس گران', 'price' => '99000000', 'stock' => 'instock' ),
        );
        $GLOBALS['post_meta'] = array(
                10 => array( '_price' => '800000' ),
                30 => array( '_price' => '25000000' ),
                40 => array( '_price' => '18000000' ),
                50 => array( '_price' => '0' ),
                60 => array( '_price' => '99000000' ),
        );
        $GLOBALS['post_stock'] = array(
                10 => 'instock',
                30 => 'outofstock',
                40 => 'instock',
                50 => 'instock',
                60 => 'instock',
        );
        $GLOBALS['products'] = array();
        foreach ( $GLOBALS['posts'] as $p ) {
                $GLOBALS['products'][ $p['id'] ] = $p;
        }
        $GLOBALS['post_terms'] = array(
                10 => array( 'product_cat' => array( (object) array( 'term_id' => 5, 'name' => 'دارفرش', 'count' => 3 ) ) ),
                30 => array( 'product_cat' => array( (object) array( 'term_id' => 6, 'name' => 'قاب گلیم', 'count' => 2 ) ) ),
                40 => array( 'product_cat' => array( (object) array( 'term_id' => 7, 'name' => 'تابلو فرش', 'count' => 4 ) ) ),
        );
}

function clear_pages() {
        $GLOBALS['posts'] = array();
}

echo "=== A. price extremes — the user's exact wording, the REAL catalogue extremes ===\n";
seed_catalogue();

$r = $m_extremes->invoke( null, 'ارزونترین و گرونترین محصول فروشگاهت چیه' );
ok( 'informal superlatives fire the deterministic answer', '' !== $r, '' );
ok( 'cheapest = the real 800k product', false !== strpos( (string) $r, 'ارزان‌ترین محصول فروشگاه: دارفرش کوچک' ), '' );
ok( 'most expensive = the real 25M product', false !== strpos( (string) $r, 'گران‌ترین محصول فروشگاه: قابگلیم ایلیا' ), '' );
ok( 'out-of-stock extreme admits ناموجود', false !== strpos( (string) $r, 'قابگلیم ایلیا | قیمت: 25,000,000 تومان | موجودی: ناموجود' ), '' );
ok( 'in-stock twin offered for the priciest', false !== strpos( (string) $r, 'گران‌ترین محصولِ موجود: تابلو فرش درشت' ), '' );
ok( 'no in-stock twin for the cheapest (already instock)', false === strpos( (string) $r, 'ارزان‌ترین محصولِ موجود' ), '' );
ok( 'real permalink in the answer', false !== strpos( (string) $r, 'http://local.test/?post=30' ), '' );
ok( 'honest freshness note present', false !== strpos( (string) $r, 'مستقیم از فروشگاه' ), '' );

$r = $m_extremes->invoke( null, 'گران‌ترین محصولت چنده؟' );
ok( 'formal گران‌ترین also fires', '' !== $r && false !== strpos( (string) $r, 'قابگلیم ایلیا' ), '' );
$r = $m_extremes->invoke( null, 'کمترین قیمت چند است؟' );
ok( 'کمترین قیمت fires', '' !== $r && false !== strpos( (string) $r, 'دارفرش کوچک' ), '' );
$r = $m_extremes->invoke( null, 'بهترین محصولت چیه؟' );
ok( 'بهترین does NOT fire (no superlative prefix)', '' === $r, '' );
$r = $m_extremes->invoke( null, 'ارزون‌ترین روش ارسال چیه؟' );
ok( 'shipping superlative does NOT fire', '' === $r, '' );
$r = $m_extremes->invoke( null, 'سلام خوبی؟' );
ok( 'small talk does not fire', '' === $r, '' );

echo "\n=== B. price floor + status guards ===\n";
$GLOBALS['posts']    = array( array( 'id' => 50, 'title' => 'هدیه رایگان', 'type' => 'product', 'status' => 'publish', 'name' => 'هدیه رایگان', 'price' => '0', 'stock' => 'instock' ) );
$GLOBALS['post_meta'] = array( 50 => array( '_price' => '0' ) );
$GLOBALS['post_stock'] = array( 50 => 'instock' );
$GLOBALS['products']  = array( 50 => $GLOBALS['posts'][0] );
$r = $m_extremes->invoke( null, 'ارزون‌ترین محصولت چیه؟' );
ok( 'zero-price-only catalogue stays honest (no reply)', '' === $r, '' );

seed_catalogue();
echo "\n=== C. page links — real permalinks, never a typed URL ===\n";
clear_pages();
$GLOBALS['posts'] = array(
        array( 'id' => 100, 'title' => 'درباره ما', 'type' => 'page', 'status' => 'publish', 'slug' => 'about-us' ),
        array( 'id' => 101, 'title' => 'تماس با ما', 'type' => 'page', 'status' => 'publish', 'slug' => 'contact' ),
        array( 'id' => 102, 'title' => 'خانه', 'type' => 'page', 'status' => 'publish', 'slug' => 'home' ),
);
$GLOBALS['wc_pages'] = array( 'shop' => 102 );

$r = $m_links->invoke( null, 'صفحه درباه ما یا ارتباط با ما ، لینک میتونی بدی؟' );
ok( 'both named pages resolved in one answer', '' !== $r && false !== strpos( (string) $r, 'درباره ما: http://local.test/?post=100' ) && false !== strpos( (string) $r, 'تماس با ما: http://local.test/?post=101' ), '' );
ok( 'no mangled/typed URL possible (only DB permalinks)', false === strpos( (string) $r, '%8' ), '' );

$r = $m_links->invoke( null, 'لینک صفحه سایت بده' );
ok( 'bare link ask → honest main-pages list', '' !== $r && false !== strpos( (string) $r, 'صفحه اصلی: http://local.test/' ) && false !== strpos( (string) $r, 'درباره ما: http://local.test/?post=100' ), '' );

$r = $m_links->invoke( null, 'لینک فروشگاه' );
ok( 'shop link resolves through wc_get_page_id', false !== strpos( (string) $r, 'فروشگاه: http://local.test/?post=102' ), '' );

$GLOBALS['wc_pages'] = array();
$r = $m_links->invoke( null, 'لینک فروشگاه' );
ok( 'missing shop page → falls to the honest list', '' !== $r && false !== strpos( (string) $r, 'این صفحه‌های اصلی سایت ما هستند' ), '' );

clear_pages();
$GLOBALS['posts'] = array( array( 'id' => 102, 'title' => 'خانه', 'type' => 'page', 'status' => 'publish', 'slug' => 'home' ) );
$r = $m_links->invoke( null, 'لینک درباره ما رو بده' );
ok( 'absent about page → honest list, never invented', '' !== $r && false === strpos( (string) $r, 'درباره ما:' ) && false !== strpos( (string) $r, 'صفحه اصلی' ), '' );

$r = $m_links->invoke( null, 'قیمت محصولاتت چنده؟' );
ok( 'price ask never triggers page links', '' === $r, '' );
$r = $m_links->invoke( null, 'لینک محصول دارفرش رو بده' );
ok( 'product link requests stay with product_facts', '' === $r, '' );
$r = $m_links->invoke( null, 'آدرس فیزیکی فروشگاه کجاست؟' );
ok( 'physical address stays with the contact path', '' === $r, '' );
$r = $m_links->invoke( null, 'شماره تلفن لینک بده' );
ok( 'contact nouns keep the contact path', '' === $r, '' );

echo "\n=== D. about-page lookup twin (title contains, slug fallback) ===\n";
clear_pages();
$GLOBALS['posts'] = array( array( 'id' => 110, 'title' => 'کمی درباره ما', 'type' => 'page', 'status' => 'publish', 'slug' => 'whatever' ) );
$r = $m_resolve->invoke( null, 'about' );
ok( 'title-contains match wins', 'http://local.test/?post=110' === $r, '' );
clear_pages();
$GLOBALS['posts'] = array( array( 'id' => 111, 'title' => 'بی عنوان', 'type' => 'page', 'status' => 'publish', 'slug' => 'darbare-ma' ) );
$r = $m_resolve->invoke( null, 'about' );
ok( 'slug fallback works', 'http://local.test/?post=111' === $r, '' );
clear_pages();
$GLOBALS['posts'] = array( array( 'id' => 112, 'title' => 'تماس', 'type' => 'page', 'status' => 'draft', 'slug' => 'contact' ) );
$r = $m_resolve->invoke( null, 'about' );
ok( 'unpublished page never resolves', '' === $r, '' );

echo "\n=== E. contact reply carries the REAL contact-page URL (2.1.25 gap) ===\n";
clear_pages();
$GLOBALS['posts'] = array(
        array( 'id' => 101, 'title' => 'ارتباط با ما', 'type' => 'page', 'status' => 'publish', 'slug' => 'ertebat' ),
        array( 'id' => 102, 'title' => 'فروشگاه', 'type' => 'page', 'status' => 'publish', 'slug' => 'shop' ),
);
clear_chat_channels();
$GLOBALS['opts']['hami_ai_crawl_state'] = array(
        'running' => false,
        'facts'   => array( 'phone: 09309980739' ),
);
$r = $m_contact->invoke( null, 'راه ارتباطی کدوم صفحات هست؟' );
ok( 'phone from crawl facts', false !== strpos( (string) $r, 'تلفن: 09309980739' ), '' );
ok( 'contact-page URL appended when facts lack it', false !== strpos( (string) $r, 'صفحه «تماس با ما»: http://local.test/?post=101' ), '' );

echo "\n=== F. anti-fabrication URL rule rides every system prompt ===\n";
$msgs = $m_messages->invoke( null, 'سلام', array(), 'این پاسخ بدون context است', array() );
$sys  = (string) $msgs[0]['content'];
ok( 'rule present without context', false !== strpos( $sys, 'قانون آدرس‌ها' ), '' );
ok( 'real homepage is the named fallback', false !== strpos( $sys, 'http://local.test/' ), '' );
$msgs = $m_messages->invoke( null, 'سلام', array(), 'درباره محصول', 'درباره ما چیزی نداریم' );
$sys  = (string) $msgs[0]['content'];
ok( 'rule present WITH context too', false !== strpos( $sys, 'قانون آدرس‌ها' ), '' );

echo "\n=== G. product_facts now reads informal ارزون/گرون as price needs ===\n";
seed_catalogue();
$GLOBALS['opts']['hami_ai_crawl_state'] = array();
$GLOBALS['product_cats'] = array(
        (object) array( 'term_id' => 5, 'name' => 'دارفرش', 'slug' => 'darfarsh', 'count' => 3 ),
        (object) array( 'term_id' => 7, 'name' => 'تابلو فرش', 'slug' => 'tablo', 'count' => 4 ),
);
$r = $m_facts->invoke( null, 'ارزون‌ترین قالیت چنده' );
ok( 'informal spelling counts as a price need (facts or extremes flow)', '' !== $r, '' );
$r = $m_facts->invoke( null, 'سلام خوبی؟ حالت چطوره' );
ok( 'no price noun, short message → no forced facts', '' === $r, '' );

echo "\n";
echo ( 0 === $fail ) ? "ALL 35-CHECKS GREEN (tmp-checks35-extremes-links.php)\n" : "SUITE 35 FAILED: {$fail}\n";
exit( $fail ? 1 : 0 );

/** Reset the chat channels to factory-empty (suite 33 helper). */
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
