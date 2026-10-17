<?php
/**
 * Regression suite: category-price answers + full-catalogue crawl (2.1.18).
 *
 * The user's live report: «قیمت محصولات [دستهٔ خاص] چقدره؟» was answered with
 * ONE plausible-looking category link picked out of twenty, and «راه‌های
 * ارتباطی» still said «در دسترس نیست». Root causes locked here:
 *
 *   1. a price question naming a PRODUCT CATEGORY never matched any product
 *      name, so the model received zero live facts — matched_product_cat()
 *      now detects the category (longest name wins, Persian word boundaries,
      Arabic/Persian letter unification) and category_price_lines() returns
 *      the live digest (count + archive link + up to 12 products);
 *   2. a BARE «قیمت محصولات چقدره؟» now receives the shop's REAL category
 *      index (category_index_lines) instead of letting the model invent a URL;
 *   3. the crawl ceiling no longer caps a real catalogue: MAX_URLS 2600,
 *      MAX_CHUNKS 8000, settings max 2500, config clamp 2500, targets_full
 *      one ID-only query per type, 100 DB pages per tick;
 *   4. attempt() widens max_tokens once when a reply died at MAX_TOKENS with
 *      zero visible text (thinking models burn the budget on thought parts —
 *      the 8-token probe stays green while every real chat starves);
 *   5. the widen fires exactly once, never on a SAFETY-blocked empty reply,
 *      and respects the 8192 cap.
 *
 * Run: php dev-tests/tmp-checks29-catprices.php
 */
$GLOBALS['haai_use_real_api'] = true; // the REAL transport, not the stub fake
require __DIR__ . '/tmp-wp-stubs.php';

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

/* ------------------------------------------------------------------ */
echo "=== A. matched_product_cat — longest name, word boundaries, letter forms ===\n";

$GLOBALS['product_cats'] = array(
        (object) array( 'term_id' => 11, 'name' => 'ابزار قالیبافی', 'slug' => 'abzar-ghalibafi', 'count' => 25 ),
        (object) array( 'term_id' => 12, 'name' => 'ابزار', 'slug' => 'abzar', 'count' => 3 ),
        (object) array( 'term_id' => 13, 'name' => 'هدیه', 'slug' => 'hediye', 'count' => 8 ),
        (object) array( 'term_id' => 14, 'name' => 'صنایع دستی', 'slug' => 'sanaye-dasti', 'count' => 40 ),
        (object) array( 'term_id' => 15, 'name' => 'دست', 'slug' => 'dast', 'count' => 1 ),
        (object) array( 'term_id' => 16, 'name' => 'خالی', 'slug' => 'khali', 'count' => 0 ),
);

$mref = new ReflectionMethod( 'Haa_Chat', 'matched_product_cat' );
$mref->setAccessible( true );

$term = $mref->invoke( null, Haa_Search::normalize_text( 'قیمت محصولات ابزار قالیبافی چقدره؟' ) );
ok( 'longest category name wins', is_object( $term ) && 11 === (int) $term->term_id, is_object( $term ) ? (string) $term->name : 'null' );

$term = $mref->invoke( null, Haa_Search::normalize_text( 'ابزار گران چنده؟' ) );
ok( 'generic category still matches alone', is_object( $term ) && 12 === (int) $term->term_id, is_object( $term ) ? (string) $term->name : 'null' );

$term = $mref->invoke( null, Haa_Search::normalize_text( 'قیمت صنایع دستی چقدره؟' ) );
ok( 'word boundary: دستی does NOT match دست', is_object( $term ) && 14 === (int) $term->term_id, is_object( $term ) ? (string) $term->name : 'null' );

$term = $mref->invoke( null, Haa_Search::normalize_text( 'قیمت محصولات ابزار قالىبافى چقدره؟' ) ); // Arabic ى
ok( 'Arabic letter forms unify (ى→ی)', is_object( $term ) && 11 === (int) $term->term_id, is_object( $term ) ? (string) $term->name : 'null' );

$term = $mref->invoke( null, Haa_Search::normalize_text( 'سلام خسته نباشید' ) );
ok( 'no category named → null', null === $term );

/* ------------------------------------------------------------------ */
echo "=== B. category_price_lines — digest with count, link, prices ===\n";

// Products: 501/502 live in «ابزار قالیبافی», 503 in «ابزار», 504 in «هدیه».
$GLOBALS['posts'] = array(
        array( 'id' => 501, 'title' => 'دوخت قراضه', 'type' => 'product', 'status' => 'publish' ),
        array( 'id' => 502, 'title' => 'شانه قالیبافی', 'type' => 'product', 'status' => 'publish' ),
        array( 'id' => 503, 'title' => 'قیچی مخصوص', 'type' => 'product', 'status' => 'publish' ),
        array( 'id' => 504, 'title' => 'دستبند چرم', 'type' => 'product', 'status' => 'publish' ),
);
$GLOBALS['products'] = array(
        501 => array( 'name' => 'دوخت قراضه', 'price' => '125000', 'stock' => 'instock' ),
        502 => array( 'name' => 'شانه قالیبافی', 'price' => '90000', 'stock' => 'outofstock' ),
        503 => array( 'name' => 'قیچی مخصوص', 'price' => '250000', 'stock' => 'instock' ),
        504 => array( 'name' => 'دستبند چرم', 'price' => '180000', 'stock' => 'instock' ),
);
$GLOBALS['post_terms'][501]['product_cat'] = array( (object) array( 'term_id' => 11, 'name' => 'ابزار قالیبافی' ) );
$GLOBALS['post_terms'][502]['product_cat'] = array( (object) array( 'term_id' => 11, 'name' => 'ابزار قالیبافی' ) );
$GLOBALS['post_terms'][503]['product_cat'] = array( (object) array( 'term_id' => 12, 'name' => 'ابزار' ) );
$GLOBALS['post_terms'][504]['product_cat'] = array( (object) array( 'term_id' => 13, 'name' => 'هدیه' ) );

$cref = new ReflectionMethod( 'Haa_Chat', 'category_price_lines' );
$cref->setAccessible( true );

$digest = $cref->invoke( null, $GLOBALS['product_cats'][0] );
ok( 'digest names the category', false !== strpos( (string) $digest, 'دسته: ابزار قالیبافی' ) );
ok( 'digest carries the product count', false !== strpos( (string) $digest, 'تعداد محصولات: 25' ) );
ok( 'digest carries the archive link', false !== strpos( (string) $digest, 'لینک دسته: http://local.test/product-cat/abzar-ghalibafi' ) );
ok( 'digest lists a product price', false !== strpos( (string) $digest, 'قیمت: 125000 تومان' ) );
ok( 'digest lists stock states', false !== strpos( (string) $digest, 'موجودی: موجود' ) && false !== strpos( (string) $digest, 'موجودی: ناموجود' ) );
ok( 'digest links the product page', false !== strpos( (string) $digest, 'لینک: http://local.test/?post=501' ) );
ok( 'honest truncation note when catalogue is bigger', false !== strpos( (string) $digest, 'نمایش 2 محصول از 25' ) );

$empty = $cref->invoke( null, $GLOBALS['product_cats'][5] ); // «خالی» — no products.
ok( 'empty category returns no digest', '' === $empty );

/* ------------------------------------------------------------------ */
echo "=== C. category_index_lines — the REAL choices, not an invented URL ===\n";

$iref = new ReflectionMethod( 'Haa_Chat', 'category_index_lines' );
$iref->setAccessible( true );
$index = $iref->invoke( null );
ok( 'index has the directive header', false !== strpos( (string) $index, 'دسته‌بندی‌های فروشگاه' ) );
ok( 'index lists categories with counts', false !== strpos( (string) $index, 'دسته: صنایع دستی | تعداد محصولات: 40' ) );
ok( 'index carries archive links', false !== strpos( (string) $index, '| لینک: http://local.test/product-cat/hediye' ) );
ok( 'empty category is excluded (hide_empty)', false === strpos( (string) $index, 'خالی' ) );

// No categories at all → '' (never noise).
$GLOBALS['product_cats'] = array();
ok( 'no categories → empty index', '' === $iref->invoke( null ) );
$GLOBALS['product_cats'] = array(
        (object) array( 'term_id' => 11, 'name' => 'ابزار قالیبافی', 'slug' => 'abzar-ghalibafi', 'count' => 25 ),
        (object) array( 'term_id' => 12, 'name' => 'ابزار', 'slug' => 'abzar', 'count' => 3 ),
        (object) array( 'term_id' => 13, 'name' => 'هدیه', 'slug' => 'hediye', 'count' => 8 ),
        (object) array( 'term_id' => 14, 'name' => 'صنایع دستی', 'slug' => 'sanaye-dasti', 'count' => 40 ),
        (object) array( 'term_id' => 15, 'name' => 'دست', 'slug' => 'dast', 'count' => 1 ),
        (object) array( 'term_id' => 16, 'name' => 'خالی', 'slug' => 'khali', 'count' => 0 ),
);

/* ------------------------------------------------------------------ */
echo "=== D. product_facts end-to-end — the reported questions ===\n";

$fref = new ReflectionMethod( 'Haa_Chat', 'product_facts' );
$fref->setAccessible( true );

$out = $fref->invoke( null, 'قیمت محصولات ابزار قالیبافی چقدره؟' );
ok( 'named category → live digest', false !== strpos( (string) $out, 'لینک دسته' ) && false !== strpos( (string) $out, 'دوخت قراضه' ) );

// Bare price ask with NOTHING matching a product name: the shop's real
// category index reaches the model instead of nothing.
$GLOBALS['posts'] = array(); // no products seeded
$out              = $fref->invoke( null, 'قیمت محصولات چقدره؟' );
ok( 'bare price ask → real category index', false !== strpos( (string) $out, 'دسته‌بندی‌های فروشگاه' ) && false !== strpos( (string) $out, 'ابزار قالیبافی' ) );

// A named product still takes the product-name path (no category digest).
$GLOBALS['posts'] = array(
        array( 'id' => 501, 'title' => 'دوخت قراضه', 'type' => 'product', 'status' => 'publish' ),
        array( 'id' => 502, 'title' => 'شانه قالیبافی', 'type' => 'product', 'status' => 'publish' ),
        array( 'id' => 503, 'title' => 'قیچی مخصوص', 'type' => 'product', 'status' => 'publish' ),
        array( 'id' => 504, 'title' => 'دستبند چرم', 'type' => 'product', 'status' => 'publish' ),
);
$out = $fref->invoke( null, 'قیمت دوخت قراضه چنده؟' );
ok( 'named product → product facts (no digest)', false !== strpos( (string) $out, 'محصول:' ) && false === strpos( (string) $out, 'لینک دسته' ) );

// A stock question names a category too («موجود» keyword + category name).
$out = $fref->invoke( null, 'موجودی ابزار قالیبافی چطوره؟' );
ok( 'category-named stock ask also hits the digest', false !== strpos( (string) $out, 'لینک دسته' ) );

/* ------------------------------------------------------------------ */
echo "=== E. attempt() widen — thinking models starve at MAX_TOKENS ===\n";

$mk = function ( $over = array() ) {
        return array_merge( array(
                'provider' => 'gemini', 'adapter' => 'gemini', 'base_url' => 'https://relay.example/v1beta',
                'api_key' => 'gk-main', 'model' => 'gemini-3.5-flash-lite', 'timeout' => 30, 'retries' => 0,
                'temperature' => 0.7, 'max_tokens' => 2000, 'proxy_host' => '', 'failovers' => array(),
        ), $over );
};

$gemini_thought_only = function () {
        // finishReason MAX_TOKENS and ONLY a thought part: extract() finds no
        // visible text → haai_empty, truncated() true → the widen must fire.
        return array( 'code' => 200, 'body' => wp_json_encode( array(
                'candidates' => array( array(
                        'content'      => array( 'role' => 'model', 'parts' => array( array( 'thought' => true, 'text' => 'اندیشه‌های پنهان مدل...' ) ) ),
                        'finishReason' => 'MAX_TOKENS',
                ) ),
        ) ) );
};
$gemini_ok = function ( $text ) {
        return array( 'code' => 200, 'body' => wp_json_encode( array(
                'candidates' => array( array(
                        'content'      => array( 'role' => 'model', 'parts' => array( array( 'text' => $text ) ) ),
                        'finishReason' => 'STOP',
                ) ),
        ) ) );
};
$sent_max_tokens = function () {
        $calls = http_requests();
        $raw   = (string) ( $calls[ count( $calls ) - 1 ]['args']['body'] ?? '' );
        $dec   = json_decode( $raw, true );
        return is_array( $dec ) ? (int) ( $dec['generationConfig']['maxOutputTokens'] ?? 0 ) : -1;
};

$aref = new ReflectionMethod( 'Haa_API', 'attempt' );
$aref->setAccessible( true );
$api  = new Haa_API( $mk() );
$msgs = array(
        array( 'role' => 'system', 'content' => 'پرسونای فروشگاه' ),
        array( 'role' => 'user', 'content' => 'قیمت محصولات چقدره؟' ),
);

// Widen fires exactly once and doubles 2000 → 4000; the second try answers.
$GLOBALS['http_log']  = array();
$GLOBALS['http_map']  = array( 'relay.example' => $gemini_thought_only() );
$opts                 = array( 'temperature' => 0.7, 'max_tokens' => 2000, 'model' => 'gemini-3.5-flash-lite' );
$res                  = $aref->invoke( $api, $msgs, $opts, 0 );
$codes                = array();
foreach ( http_requests() as $i => $call ) {
        $dec = json_decode( (string) ( $call['args']['body'] ?? '' ), true );
        $codes[ $i ] = (int) ( $dec['generationConfig']['maxOutputTokens'] ?? 0 );
}
ok( 'empty+MAX_TOKENS widens once (2 requests)', 2 === count( http_requests() ), '(n=' . count( http_requests() ) . ')' );
ok( 'first try kept the configured budget', 2000 === ( $codes[0] ?? 0 ) );
ok( 'second try doubled the budget', 4000 === ( $codes[1] ?? 0 ) );
ok( 'still honest when the widen cannot help either', is_wp_error( $res ) && 'haai_empty' === $res->get_error_code() );

// Widen succeeds on a healthy second answer.
$GLOBALS['http_log'] = array();
$GLOBALS['http_map'] = array( 'relay.example' => $gemini_ok( 'پاسخ آزمایشی قیمت' ) );
$res                 = $aref->invoke( $api, $msgs, array( 'temperature' => 0.7, 'max_tokens' => 2000, 'model' => 'm' ), 0 );
ok( 'widen path returns the real answer', is_string( $res ) && 'پاسخ آزمایشی قیمت' === $res );
ok( 'widen success needed exactly one extra call', 1 === count( http_requests() ) );

// The 8192 cap: 6000 widens to 8192, never beyond.
$GLOBALS['http_log'] = array();
$GLOBALS['http_map'] = array( 'relay.example' => $gemini_thought_only() );
$aref->invoke( $api, $msgs, array( 'temperature' => 0.7, 'max_tokens' => 6000, 'model' => 'm' ), 0 );
ok( 'widen respects the 8192 cap', 8192 === $sent_max_tokens(), '(max=' . $sent_max_tokens() . ')' );

// A SAFETY-blocked empty reply (no MAX_TOKENS) must NOT widen.
$GLOBALS['http_log'] = array();
$GLOBALS['http_map'] = array( 'relay.example' => array( 'code' => 200, 'body' => wp_json_encode( array(
        'promptFeedback' => array( 'blockReason' => 'SAFETY' ),
) ) ) );
$res                 = $aref->invoke( $api, $msgs, array( 'temperature' => 0.7, 'max_tokens' => 2000, 'model' => 'm' ), 0 );
ok( 'blocked empty does not widen (1 request)', 1 === count( http_requests() ) && is_wp_error( $res ) && false !== strpos( (string) $res->get_error_message(), 'SAFETY' ) );

/* ------------------------------------------------------------------ */
echo "=== F. crawl ceiling — a real catalogue now fits the queue ===\n";

ok( 'MAX_URLS raised to 2600', 2600 === (int) Haa_Crawl::MAX_URLS );
ok( 'MAX_CHUNKS raised to 8000', 8000 === (int) Haa_Crawl::MAX_CHUNKS );

Haa_Settings::save_section( 'knowledge', array( 'urls' => '', 'auto_crawl' => 1, 'max_pages' => 2000, 'crawl_types' => array( 'page', 'product' ) ) );
reset_crawl_config();
ok( 'config clamp accepts a full-catalogue budget', 2000 === (int) Haa_Crawl::config()['pages'], '(pages=' . Haa_Crawl::config()['pages'] . ')' );

// 150 seeded products + homepage: targets_full must queue ALL of them.
$GLOBALS['posts'] = array();
for ( $i = 600; $i < 750; $i++ ) {
        $GLOBALS['posts'][] = array( 'id' => $i, 'title' => 'محصول ' . $i, 'type' => 'product', 'status' => 'publish' );
        $GLOBALS['written'][ $i ] = array( 'ID' => $i, 'post_title' => 'محصول ' . $i, 'post_type' => 'product', 'post_status' => 'publish',
                'post_content' => '<p>محتوای آزمایشی محصول ' . $i . ' با قیمت و موجودی مشخص برای ایندکس شدن.</p>' );
}
$targets = Haa_Crawl::targets_full();
ok( 'queue holds homepage + every product', 151 === count( $targets ), '(n=' . count( $targets ) . ')' );
ok( 'homepage stays first (priority order)', 'http://local.test' === rtrim( (string) $targets[0]['url'], '/' ) );
ok( 'products carry their post ids (DB path)', (int) $targets[1]['pid'] > 0 );

Haa_Settings::save_section( 'knowledge', array( 'urls' => '', 'auto_crawl' => 1, 'max_pages' => 30, 'crawl_types' => array( 'page' ) ) );
reset_crawl_config();

/* ------------------------------------------------------------------ */
echo "\n" . ( 0 === $fail ? "ALL CHECKS PASSED (suite 29)\n" : "$fail CHECK(S) FAILED\n" );
exit( 0 === $fail ? 0 : 1 );
