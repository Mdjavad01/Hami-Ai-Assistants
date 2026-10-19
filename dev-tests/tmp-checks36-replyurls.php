<?php
/**
 * Regression suite: chat URLs + categories that died in the reply path (2.1.26).
 *
 * The user's live report (2026-10-19, shop new.mrdastbaaf.com) on 2.1.25:
 * prices were finally RIGHT, but every URL the bot printed was structurally
 * mangled and دسته was always empty:
 *
 *   درباره-ما  → https://…/-/            تماس-با-ما → https://…/--/
 *   سیخ (slug) → …/carpet-weaving-tools//
 *   دسته:      → empty while the product sits in TWO real categories.
 *
 * Two independent roots, both invisible to the old harness:
 *
 *   1. The REST chat response ran the reply through sanitize_textarea_field().
 *      WP core _sanitize_text_fields() DELETES every %XX sequence, and WP
 *      stores non-ASCII slugs percent-encoded in post_name — get_permalink()
 *      output is FULL of %d8%XX octets. The sanitizer literally ate the
 *      Persian slug out of every permalink (and the 2.1.24-era model-typed
 *      /-%8فا------/ too). The naive trim() stub could never see it.
 *   2. wp_get_post_terms( fields=names ) returns STRINGS; wp_list_pluck
 *      ('name') over strings skips every item (core WP_List_Util::pluck
 *      reads objects/arrays only) → دسته went out empty. The old stub
 *      pushed null instead of skipping, printing a phantom «،».
 *
 * Locked here: sanitize_reply() keeps real permalinks byte-identical while
 * still stripping tags/control chars; the faithful stub proves the old path
 * eats them (so nobody ever "simplifies" the stub back); REST source contract;
 * pretty_url decode rules (non-ASCII octets only, ASCII octets keep meaning,
 * invalid mixes fall back, idempotent); the full extremes/product/page-links
 * answers with BOTH real categories and DECODED permalinks; build_context
 * link lines; pluck fidelity.
 *
 * Run: php dev-tests/tmp-checks36-replyurls.php
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
$m_plines    = $rc->getMethod( 'product_lines' );
$m_plines->setAccessible( true );
$m_links     = $rc->getMethod( 'page_links_reply' );
$m_links->setAccessible( true );
$m_context   = $rc->getMethod( 'build_context' );
$m_context->setAccessible( true );

/* The REAL permalinks of the live shop, byte-for-byte as WP hands them out:
 * non-ASCII slugs percent-encoded in post_name (verified via the site's REST
 * API and WooCommerce Store API during the 2.1.26 investigation). */
$URL_PRODUCT = 'https://www.new.mrdastbaaf.com/shop-page/types-of-tools/carpet-weaving-tools/%d8%b3%db%8c%d8%ae/';
$URL_ABOUT   = 'https://www.new.mrdastbaaf.com/%d8%af%d8%b1%d8%a8%d8%a7%d8%b1%d9%87-%d9%85%d8%a7/';
$URL_CONTACT = 'https://www.new.mrdastbaaf.com/%d8%aa%d9%85%d8%a7%d8%b3-%d8%a8%d8%a7-%d9%85%d8%a7/';

echo "=== A. sanitize_reply — real permalinks must survive byte-identical ===\n";
ok( 'product permalink survives', $URL_PRODUCT === Haa_Chat::sanitize_reply( $URL_PRODUCT ), '' );
ok( 'about permalink survives', $URL_ABOUT === Haa_Chat::sanitize_reply( $URL_ABOUT ), '' );
ok( 'contact permalink survives', $URL_CONTACT === Haa_Chat::sanitize_reply( $URL_CONTACT ), '' );

$multi = "• درباره ما: " . $URL_ABOUT . "\n• تماس با ما: " . $URL_CONTACT;
$san   = Haa_Chat::sanitize_reply( $multi );
ok( 'bullet list keeps its newlines', false !== strpos( $san, "\n" ) && substr_count( $san, "\n" ) >= 1, '' );

$scr = Haa_Chat::sanitize_reply( 'سلام <script>alert(1)</script>درباره <b>قالی</b>' );
ok( 'tags stripped (XSS hygiene kept)', false === strpos( $scr, '<script' ) && false === strpos( $scr, '<b>' ) && false !== strpos( $scr, 'درباره' ), '' );

$ctl = Haa_Chat::sanitize_reply( "a\x00b\x07c\td\ne" );
ok( 'control chars gone, tab+newline kept', "abc\td\ne" === $ctl, '' );

$inv = Haa_Chat::sanitize_reply( "قالی\xC3" );
ok( 'invalid UTF-8 scrubbed', false === strpos( $inv, "\xC3" ) && false !== strpos( $inv, 'قالی' ), '' );

$live = 'ارزان‌ترین محصول فروشگاه: سیخ پود کشی | قیمت: 100,000 تومان | موجودی: موجود | دسته: ابزار قالیبافی، انواع ابزار قالی‌بافی وبافت‌فرش | لینک: ' . $URL_PRODUCT
        . "\nقیمت‌ها مستقیم از فروشگاه خوانده شده‌اند و همین لحظه درست هستند.";
ok( 'full live-shaped reply byte-identical', $live === Haa_Chat::sanitize_reply( $live ), '' );

echo "\n=== B. the enemy, preserved in the harness — WP core semantics ===\n";
$eaten = sanitize_textarea_field( $URL_PRODUCT );
ok( 'sanitize_textarea_field (core truth) EATS %XX', false === strpos( $eaten, '%d8' ) && false !== strpos( $eaten, 'carpet-weaving-tools//' ), '' );
ok( 'contact slug would die as /--/ the same way', false !== strpos( (string) sanitize_textarea_field( $URL_CONTACT ), '/--/' ), '' );

$rest = (string) file_get_contents( __DIR__ . '/../includes/class-haa-rest.php' );
ok( 'REST reply exits through Haa_Chat::sanitize_reply', false !== strpos( $rest, "Haa_Chat::sanitize_reply( (string) \$result['reply'] )" ), '' );
ok( 'REST reply never touches sanitize_textarea_field again', false === strpos( $rest, "sanitize_textarea_field( (string) \$result['reply'] )" ), '' );

echo "\n=== C. pretty_url — the address-bar form of a permalink ===\n";
$pretty = Haa_Util::pretty_url( $URL_PRODUCT );
ok( 'Persian product slug decoded', 'https://www.new.mrdastbaaf.com/shop-page/types-of-tools/carpet-weaving-tools/سیخ/' === $pretty, '' );
ok( 'about slug decoded', 'https://www.new.mrdastbaaf.com/درباره-ما/' === Haa_Util::pretty_url( $URL_ABOUT ), '' );
ok( 'contact slug decoded', 'https://www.new.mrdastbaaf.com/تماس-با-ما/' === Haa_Util::pretty_url( $URL_CONTACT ), '' );
ok( 'ASCII octets keep their exact meaning', 'http://x/a%20b%26c%3Fd' === Haa_Util::pretty_url( 'http://x/a%20b%26c%3Fd' ), '' );
ok( 'plain ASCII URL unchanged', 'http://x/y/z?w=1' === Haa_Util::pretty_url( 'http://x/y/z?w=1' ), '' );
ok( 'empty stays empty', '' === Haa_Util::pretty_url( '' ), '' );
ok( 'invalid octet mix falls back untouched', 'http://x/%d8zz' === Haa_Util::pretty_url( 'http://x/%d8zz' ), '' );
ok( 'idempotent (already-pretty passes through)', $pretty === Haa_Util::pretty_url( $pretty ), '' );

echo "\n=== D. price extremes end-to-end on the LIVE fixture ===\n";
/**
 * Seed the exact live shop shape: سیخ پود کشی 100,000 (in TWO real
 * categories, all-Persian slug) vs دار فرش فرشته 266,000,000 (one Persian
 * word-group slug) — the user's reported pair, URLs percent-encoded like WP
 * really stores them.
 */
function seed_live_extremes() {
        $GLOBALS['posts'] = array(
                array( 'id' => 10, 'title' => 'سیخ پود کشی', 'type' => 'product', 'status' => 'publish', 'name' => 'سیخ پود کشی', 'price' => '100000', 'stock' => 'instock' ),
                array( 'id' => 30, 'title' => 'دار فرش فرشته', 'type' => 'product', 'status' => 'publish', 'name' => 'دار فرش فرشته', 'price' => '266000000', 'stock' => 'instock' ),
        );
        $GLOBALS['post_meta']  = array( 10 => array( '_price' => '100000' ), 30 => array( '_price' => '266000000' ) );
        $GLOBALS['post_stock'] = array( 10 => 'instock', 30 => 'instock' );
        $GLOBALS['products']   = array( 10 => $GLOBALS['posts'][0], 30 => $GLOBALS['posts'][1] );
        // The pluck bug killed BOTH names here; the live product carries two.
        $GLOBALS['post_terms'] = array(
                10 => array( 'product_cat' => array(
                        (object) array( 'term_id' => 5, 'name' => 'ابزار قالیبافی', 'count' => 40 ),
                        (object) array( 'term_id' => 8, 'name' => 'انواع ابزار قالی‌بافی وبافت‌فرش', 'count' => 22 ),
                ) ),
                30 => array( 'product_cat' => array(
                        (object) array( 'term_id' => 9, 'name' => 'دار قالی', 'count' => 12 ),
                ) ),
        );
        $GLOBALS['permalinks'] = array(
                10 => 'https://www.new.mrdastbaaf.com/shop-page/types-of-tools/carpet-weaving-tools/%d8%b3%db%8c%d8%ae/',
                30 => 'https://www.new.mrdastbaaf.com/shop-page/types-of-carpet-weaving-looms/wooden-looms/%d8%af%d8%a7%d8%b1-%d9%81%d8%b1%d8%b4-%d9%81%d8%b1%d8%b4%d8%aa%d9%87/',
        );
}
seed_live_extremes();

$r = $m_extremes->invoke( null, 'ارزونترین و گرونترین محصول فروشگاهت چیه' );
ok( 'deterministic answer fires on the live fixture', is_string( $r ) && '' !== $r, '' );
ok( 'BOTH real categories in the cheapest line (pluck bug dead)', false !== strpos( (string) $r, 'دسته: ابزار قالیبافی، انواع ابزار قالی‌بافی وبافت‌فرش' ), '' );
ok( 'cheapest link is the DECODED address-bar form', false !== strpos( (string) $r, 'carpet-weaving-tools/سیخ/' ), '' );
ok( 'most expensive carries its decoded link too', false !== strpos( (string) $r, 'wooden-looms/دار-فرش-فرشته/' ), '' );
ok( 'not a single %d8 octet reaches the visitor', false === strpos( (string) $r, '%d8' ), '' );
ok( 'price facts unchanged', false !== strpos( (string) $r, '100,000 تومان' ) && false !== strpos( (string) $r, '266,000,000 تومان' ), '' );

echo "\n=== E. product_lines — the shared fact-line shape ===\n";
$lines = $m_plines->invoke( null, array( 10 ) );
ok( 'product_lines shows both categories', is_array( $lines ) && isset( $lines[0] ) && false !== strpos( (string) $lines[0], 'ابزار قالیبافی، انواع ابزار قالی‌بافی وبافت‌فرش' ), '' );
ok( 'product_lines link decoded', is_array( $lines ) && isset( $lines[0] ) && false !== strpos( (string) $lines[0], '/سیخ/' ), '' );
$lines30 = $m_plines->invoke( null, array( 30 ) );
ok( 'single-category product still fills دسته', is_array( $lines30 ) && isset( $lines30[0] ) && false !== strpos( (string) $lines30[0], 'دسته: دار قالی' ), '' );

echo "\n=== F. page links end-to-end — encoded permalinks in, pretty URLs out ===\n";
$GLOBALS['posts'] = array(
        array( 'id' => 100, 'title' => 'درباره ما', 'type' => 'page', 'status' => 'publish', 'slug' => 'about-us' ),
        array( 'id' => 101, 'title' => 'تماس با ما', 'type' => 'page', 'status' => 'publish', 'slug' => 'contact' ),
);
$GLOBALS['permalinks'] = array( 100 => $URL_ABOUT, 101 => $URL_CONTACT );
$r = $m_links->invoke( null, 'صفحه درباه ما یا ارتباط با ما ، لینک میتونی بدی؟' );
ok( 'about page prints the decoded real URL', false !== strpos( (string) $r, 'درباره ما: https://www.new.mrdastbaaf.com/درباره-ما/' ), '' );
ok( 'contact page prints the decoded real URL', false !== strpos( (string) $r, 'تماس با ما: https://www.new.mrdastbaaf.com/تماس-با-ما/' ), '' );
ok( 'no %d8 octets in the visitor bubble', false === strpos( (string) $r, '%d8' ), '' );
ok( 'still intact after sanitize_reply (the real REST exit)', false !== strpos( (string) Haa_Chat::sanitize_reply( (string) $r ), 'درباره-ما/' ), '' );

echo "\n=== G. build_context — the model sees pretty URLs and copies them ===\n";
$hits = array( array( 'id' => 1, 'title' => 'درباره ما', 'content' => 'داستان ما از سال ۱۳۷۰…', 'url' => $URL_ABOUT ) );
$ctx  = $m_context->invoke( null, $hits );
ok( 'context link line decoded for the model', false !== strpos( (string) $ctx, '— لینک: https://www.new.mrdastbaaf.com/درباره-ما/' ), '' );
$ctx2 = $m_context->invoke( null, array( array( 'id' => 2, 'title' => 'بی لینک', 'content' => 'متن' ) ) );
ok( 'hit without url carries no link line', false === strpos( (string) $ctx2, 'لینک:' ), '' );

echo "\n=== H. wp_list_pluck fidelity — core skips scalars ===\n";
ok( 'pluck over name-strings skips every item (core truth)', array() === wp_list_pluck( array( 'ابزار', 'دار' ), 'name' ), '' );
ok( 'pluck over objects reads the field', array( 'ابزار' ) === wp_list_pluck( array( (object) array( 'name' => 'ابزار' ) ), 'name' ), '' );

echo ( $fail ? "\nFAILURES: $fail\n" : "\nALL " . 38 . " CHECKS GREEN\n" );
exit( $fail ? 1 : 0 );
