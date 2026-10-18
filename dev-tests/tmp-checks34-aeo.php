<?php
/**
 * Regression suite: the 2.1.23 AEO + reports toolkit.
 *
 * Builds the backlog's SEO/AEO group: FAQPage JSON-LD (B-1), llms.txt (B-2),
 * the answer-first prompt contract (B-1), index health checks (B-4), the
 * nightly broken-link checker (B-5), product SEO scores + group optimize
 * (B-8), AI-bot counting (B-3) and the unified reports tab (A-6).
 *
 * Locked here: FAQ pairs from the generator meta with the body-parser
 * fallback (Persian headings, entity/tag stripping, 10-question cap, ≥2
 * questions before schema, SEO-plugin block ownership); the llms.txt cache
 * lifecycle (crawl finish → stale → rebuild on request); bot counting with
 * the midnight roll and the toggle-off zero-cost path; the link walker's
 * cursor semantics (bounded checks, cursor passes only checked posts, wrap
 * on the tail, healthy re-scan removes its own finding, 403 is NOT broken);
 * the product score weights (100 = all green, noindex −10) and the group
 * optimize queue push; the reports-screen settings round-trip and the
 * nightly cron's self-clearing when the feature is switched off.
 *
 * Run: php dev-tests/tmp-checks34-aeo.php
 */
require __DIR__ . '/tmp-wp-stubs.php';

$fail = 0;
function ok( $label, $cond, $extra = '' ) {
        global $fail;
        if ( ! $cond ) { $fail++; }
        echo ( $cond ? '  ok   ' : '  FAIL ' ) . str_pad( $label, 62 ) . $extra . "\n";
}

if ( ! function_exists( 'wp_sitemaps_get_server' ) ) {
        class Haai_Fake_Sitemaps {
                public function sitemaps_enabled() { return true; }
        }
        function wp_sitemaps_get_server() { return new Haai_Fake_Sitemaps(); }
}

function seed_post( $id, $title, $type, $content = '', $status = 'publish' ) {
        $GLOBALS['posts'][] = array( 'id' => $id, 'title' => $title, 'type' => $type, 'status' => $status );
        if ( '' !== $content ) {
                $GLOBALS['post_content'][ $id ] = $content;
        }
}

function reset_opts() {
        $GLOBALS['opts'] = array();
}

/* ==================================================================
 * A. FAQ pairs
 * =============================================================== */
echo "== A. FAQ pairs ==\n";

reset_opts();
$GLOBALS['posts']       = array();
$GLOBALS['post_meta']   = array();
$GLOBALS['post_content'] = array();

$GLOBALS['post_meta'][501]['_haai_faq'] = array(
        array( 'q' => 'جنس نخ چیست؟', 'a' => 'نخ پنبه است.' ),
        array( 'q' => '', 'a' => 'بدون سؤال' ),
        array( 'q' => 'ارسال دارید؟', 'a' => '' ),
        array( 'q' => 'اندازه‌ها؟', 'a' => '۲۰۰ در ۳۰۰ سانتی‌متر.' ),
);
$pairs = Haa_Aeo::faq_pairs( 501 );
ok( 'meta pairs survive, empty halves dropped', 2 === count( $pairs ) && 'جنس نخ چیست؟' === $pairs[0]['q'] );

unset( $GLOBALS['post_meta'][501]['_haai_faq'] );
$GLOBALS['post_content'][501] = '<p>مقدمه</p>'
        . '<h2>سؤالات متداول</h2>'
        . '<h3>قیمت <strong>دارفرش</strong> چنده؟</h3><p>از &quot;۸.۵&quot; میلیون شروع می‌شود.</p>'
        . '<h3>سفارشی می‌بافید؟</h3><p>بله، طرح دلخواه بافته می‌شود.</p>'
        . '<h2>بخش دیگر</h2><h3>نباید این بیاید</h3><p>خارج از بخش FAQ</p>';
$pairs = Haa_Aeo::faq_pairs( 501 );
ok( 'body parser finds the FAQ section', 2 === count( $pairs ) && 'سفارشی می‌بافید؟' === $pairs[1]['q'], json_encode( $pairs, JSON_UNESCAPED_UNICODE ) );
ok( 'parser strips tags and entities', false === strpos( $pairs[0]['q'], '<strong>' ) && false === strpos( $pairs[0]['a'], '&quot;' ) );

$GLOBALS['post_content'][502] = '<h2>پرسش‌های متداول</h2><h3>نگهداری چطور است؟</h3><p>با جارو ملایم.</p><h3>ضد آب است؟</h3><p>نه، از رطوبت دور نگه دارید.</p>';
$pairs = Haa_Aeo::faq_pairs( 502 );
ok( 'Persian variant heading «پرسش‌های متداول» matches', 2 === count( $pairs ) );

$GLOBALS['post_content'][503] = '<p>بدون هیچ FAQ ای</p>';
ok( 'no heading → no pairs', 0 === count( Haa_Aeo::faq_pairs( 503 ) ) );

$many = array();
for ( $i = 1; $i <= 12; $i++ ) { $many[] = array( 'q' => 'س' . $i, 'a' => 'ج' . $i ); }
$GLOBALS['post_meta'][504]['_haai_faq'] = $many;
ok( '10-question cap', 10 === count( Haa_Aeo::faq_pairs( 504 ) ) );
unset( $GLOBALS['post_meta'][504]['_haai_faq'] );

/* ==================================================================
 * B. FAQPage JSON-LD
 * =============================================================== */
echo "== B. FAQPage schema ==\n";

$GLOBALS['q'] = array( 'singular' => true, 'id' => 502 );
ob_start();
Haa_Aeo::print_faq_schema();
$out = ob_get_clean();
ok( 'schema printed with FAQPage + 2 questions', false !== strpos( $out, '"@type":"FAQPage"' ) && 2 === substr_count( $out, '"@type":"Question"' ) );
ok( 'schema is valid JSON inside ld+json', preg_match( '#<script[^>]*>(.*?)</script>#s', $out, $sm ) && is_array( json_decode( $sm[1], true ) ) && 2 === count( json_decode( $sm[1], true )['mainEntity'] ) );

$GLOBALS['q']['id'] = 503;
ob_start();
Haa_Aeo::print_faq_schema();
$out = ob_get_clean();
ok( 'fewer than 2 pairs → nothing printed', '' === $out );

$GLOBALS['q']['id'] = 502;
$GLOBALS['post_content'][502] = '<div class="wp-block-yoast-faq-block">…</div><h2>سؤالات متداول</h2><h3>س؟</h3><p>ج</p><h3>س۲؟</h3><p>ج۲</p>';
ob_start();
Haa_Aeo::print_faq_schema();
$out = ob_get_clean();
ok( 'SEO-plugin FAQ block owns the page → skipped', '' === $out );
$GLOBALS['q'] = array();

/* ==================================================================
 * C. llms.txt
 * =============================================================== */
echo "== C. llms.txt ==\n";

reset_opts();
$GLOBALS['posts']        = array();
$GLOBALS['post_content'] = array();
$GLOBALS['product_cats'] = array(
        (object) array( 'term_id' => 5, 'name' => 'نخ و نقشه', 'slug' => 'yarn-and-design-pattern', 'count' => 1635 ),
        (object) array( 'term_id' => 6, 'name' => 'طرح چهره', 'slug' => 'face-pattern', 'count' => 205 ),
);
$GLOBALS['chunk_rows'] = array(
        array( 'post_id' => -1, 'source' => 'contact', 'content' => "تلفن: 09309980739\nاینستاگرام: instagram.com/mr.dastbaaf" ),
);
seed_post( 700, 'راهنمای خرید فرش پامرانین', 'post', '' );
$GLOBALS['post_excerpt'][700] = 'هر آنچه پیش از خرید باید بدانید.';

$text = Haa_Aeo::build_llms();
ok( 'site name + Home + Language lines', 0 === strpos( $text, '# Hami Shop' ) && false !== strpos( $text, 'Home: http://local.test/' ) && false !== strpos( $text, 'Language: fa' ) );
ok( 'key pages + categories with counts', false !== strpos( $text, '## صفحات کلیدی' ) && false !== strpos( $text, '[نخ و نقشه](http://local.test/product-cat/yarn-and-design-pattern) (1635 محصول)' ) );
ok( 'contact chunk lines flow into the file', false !== strpos( $text, 'تلفن: 09309980739' ) && false !== strpos( $text, 'اینستاگرام: instagram.com/mr.dastbaaf' ) );
ok( 'recent posts with excerpt', false !== strpos( $text, '[راهنمای خرید فرش پامرانین](http://local.test/?post=700): هر آنچه پیش از خرید باید بدانید.' ) );

$GLOBALS['opts'][ Haa_Aeo::OPT_LLMS ] = array( 'at' => time(), 'text' => 'CACHED-FILE' );
ok( 'fresh cache is served without a rebuild', 'CACHED-FILE' === Haa_Aeo::llms_text() );
Haa_Aeo::mark_llms_stale();
ok( 'crawl finish → stale → rebuild on next request', 0 === strpos( Haa_Aeo::llms_text(), '# Hami Shop' ) );

/* ==================================================================
 * D. AI-bot counting
 * =============================================================== */
echo "== D. AI bots ==\n";

reset_opts();
$GLOBALS['opts'][ Haa_Aeo::OPT_BOT ] = array( 'day' => gmdate( 'Y-m-d', time() - 90000 ), 'counts' => array( 'ClaudeBot (Anthropic)' => 7 ) );
$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (compatible; GPTBot/1.1; +https://openai.com/gptbot)';
Haa_Aeo::track_bot();
$today = get_option( Haa_Aeo::OPT_BOT );
$sum   = array_sum( (array) $today['counts'] );
ok( 'GPTBot counted once', 1 === $sum && isset( $today['counts']['GPTBot (OpenAI خزنده)'] ) );
$log = (array) get_option( Haa_Aeo::OPT_BOTLOG );
$last = end( $log );
ok( 'yesterday rolled into the 30-day log', $last && 'ClaudeBot (Anthropic)' === key( $last['counts'] ) && 7 === current( $last['counts'] ) );

$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/120 Safari/537.36';
$before = get_option( Haa_Aeo::OPT_BOT );
Haa_Aeo::track_bot();
ok( 'human UA costs nothing', $before === get_option( Haa_Aeo::OPT_BOT ) );

$GLOBALS['opts'][ Haa_Aeo::OPT_BOT ]['counts'] = array();
$GLOBALS['opts'][ Haa_Aeo::OPT_BOT ]['day']    = gmdate( 'Y-m-d' );
$GLOBALS['opts'][ HAAI_OPTION ]['knowledge']['bots_enabled'] = 0;
Haa_Settings::flush_cache();
$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (compatible; PerplexityBot/1.0; +https://perplexity.ai/perplexitybot)';
Haa_Aeo::track_bot();
ok( 'toggle off → no counting', array() === (array) get_option( Haa_Aeo::OPT_BOT )['counts'] );
unset( $GLOBALS['opts'][ HAAI_OPTION ], $_SERVER['HTTP_USER_AGENT'] );
Haa_Settings::flush_cache();

/* ==================================================================
 * E. Broken links
 * =============================================================== */
echo "== E. broken links ==\n";

reset_opts();
$GLOBALS['posts']        = array();
$GLOBALS['post_content'] = array();
$GLOBALS['http_map']     = array(
        'broken.test'    => array( 'code' => 404 ),
        'ok.test'        => array( 'code' => 200 ),
        'forbidden.test' => array( 'code' => 403 ),
        'timeout.test'   => array( 'error' => 'cURL error 28: Operation timed out' ),
);
$GLOBALS['head_log'] = array();

$GLOBALS['post_content'][10] = '<p><a href="https://broken.test/x">a</a> <a href="https://ok.test/y">b</a></p>';
$GLOBALS['post_content'][11] = '<p><a href="https://forbidden.test/f">c</a> <a href="https://timeout.test/z">d</a> <a href="mailto:x@y.z">m</a> <a href="#sec">s</a></p>';
$GLOBALS['post_content'][12] = '<p>بدون لینک</p>';
seed_post( 10, 'مقاله نخ', 'post' );
seed_post( 11, 'برگه تماس', 'page' );
seed_post( 12, 'برگه بی‌لینک', 'page' );

$res = Haa_Aeo::scan_links_batch( 10, 20 );
ok( '4 links checked, mailto/# skipped', 4 === $res['checked'], json_encode( $res ) );
ok( 'only 404 + transport error flagged', 2 === $res['broken'] );
$finds = Haa_Aeo::links_findings();
ok( '403 is NOT flagged as broken', ! isset( $finds[ md5( 'https://forbidden.test/f|11' ) ] ) && isset( $finds[ md5( 'https://broken.test/x|10' ) ] ) );
$state = get_option( Haa_Aeo::OPT_LSTATE );
ok( 'small site: cycle completes in one pass', true === $res['complete'] && 0 === (int) $state['pid'] && (int) $state['cycle_at'] > 0 );

// A healthy re-scan removes its own finding.
$GLOBALS['http_map']['broken.test']  = array( 'code' => 200 );
$GLOBALS['http_map']['timeout.test'] = array( 'code' => 200 );
$res  = Haa_Aeo::scan_links_batch( 10, 20 );
$finds = Haa_Aeo::links_findings();
$state = get_option( Haa_Aeo::OPT_LSTATE );
ok( 'healthy re-scan self-cleans + cycle stamped', 0 === count( $finds ) && true === $res['complete'] && (int) $state['cycle_at'] > 0 && 0 === (int) $state['pid'], json_encode( $res ) );

// The budget: 2 checks → only the first post's links; cursor waits at post 10.
reset_opts();
$GLOBALS['opts'][ Haa_Aeo::OPT_LSTATE ] = array( 'pid' => 0, 'cycle_at' => 0 );
$GLOBALS['http_map']['broken.test'] = array( 'code' => 404 );
$res = Haa_Aeo::scan_links_batch( 2, 20 );
$state = get_option( Haa_Aeo::OPT_LSTATE );
ok( 'bounded budget: 2 checks, cursor at 10', 2 === $res['checked'] && 10 === (int) $state['pid'] && false === $res['complete'], json_encode( $res ) );

// An empty site still stamps an honest empty cycle.
reset_opts();
$GLOBALS['posts'] = array();
$res = Haa_Aeo::scan_links_batch( 10, 20 );
$state = get_option( Haa_Aeo::OPT_LSTATE );
ok( 'no content with links → empty cycle', true === $res['complete'] && 0 === (int) $state['pid'] && (int) $state['cycle_at'] > 0 );

Haa_Aeo::links_clear();
ok( 'clear wipes findings', 0 === count( Haa_Aeo::links_findings() ) );

/* ==================================================================
 * F. Product SEO scores
 * =============================================================== */
echo "== F. product scores ==\n";

reset_opts();
$GLOBALS['posts']        = array();
$GLOBALS['post_content'] = array();
$GLOBALS['post_excerpt'] = array();
$GLOBALS['post_thumb']   = array();
$GLOBALS['post_terms']   = array();
$GLOBALS['post_meta']    = array();
$GLOBALS['db']           = array( 'topics' => array() );

$full_body  = str_repeat( 'این فرش دستباف با نخ پنبه بافته شده و برای پذیرایی مناسب است. ', 8 );
seed_post( 100, 'فرش دستباف پامرانین نقشه برجسته طرح آیه کد ۱۰۰', 'product', $full_body );
$GLOBALS['post_excerpt'][100] = 'فرش پامرانین با نخ پنبه.';
$GLOBALS['post_thumb'][100]   = 55;
$GLOBALS['post_terms'][100]['product_cat'] = array( (object) array( 'term_id' => 5, 'name' => 'نخ و نقشه' ) );
$GLOBALS['post_terms'][100]['product_tag'] = array( (object) array( 'term_id' => 9, 'name' => 'پامرانین' ) );
$GLOBALS['post_meta'][100]['_haai_faq']    = array( array( 'q' => 'جنس چیست؟', 'a' => 'نخ پنبه.' ) );

seed_post( 101, 'کوتاه', 'product', 'خیلی کوتاه' );

seed_post( 102, 'فرش دستباف پامرانین نقشه برجسته طرح گل کد ۱۰۲', 'product', $full_body );
$GLOBALS['post_excerpt'][102] = 'فرش پامرانین گل.';
$GLOBALS['post_thumb'][102]   = 56;
$GLOBALS['post_terms'][102]['product_cat'] = array( (object) array( 'term_id' => 5, 'name' => 'نخ و نقشه' ) );
$GLOBALS['post_terms'][102]['product_tag'] = array( (object) array( 'term_id' => 9, 'name' => 'پامرانین' ) );
$GLOBALS['post_meta'][102]['_haai_faq']     = array( array( 'q' => 'جنس؟', 'a' => 'نخ پنبه.' ) );
$GLOBALS['post_meta'][102]['_haai_noindex'] = '1';

$score = Haa_Aeo::score_of( 100 );
ok( 'fully equipped product scores 100', 100 === $score['score'] && array() === $score['issues'], json_encode( $score ) );
$score = Haa_Aeo::score_of( 101 );
ok( 'bare product is low with named issues', $score['score'] <= 20 && in_array( 'تصویر شاخص ندارد', $score['issues'], true ) && in_array( 'دسته‌بندی ندارد', $score['issues'], true ), json_encode( $score, JSON_UNESCAPED_UNICODE ) );
$score = Haa_Aeo::score_of( 102 );
ok( 'noindex costs 10 points and is named', 90 === $score['score'] && in_array( 'از نمای موتورهای جستجو کنار گذاشته شده (noindex)', $score['issues'], true ), json_encode( $score, JSON_UNESCAPED_UNICODE ) );

$res = Haa_Aeo::score_batch( 2 );
ok( 'batch 1 scores 2, cycle continues', 2 === $res['done'] && false === $res['complete'], json_encode( $res ) );
$res = Haa_Aeo::score_batch( 2 );
ok( 'batch 2 hits the tail → cycle complete', 1 === $res['done'] && true === $res['complete'] && 3 === $res['scored'], json_encode( $res ) );

$summary = Haa_Aeo::scores_summary();
ok( 'distribution: 2 good, 1 weak', 2 === $summary['good'] && 0 === $summary['mid'] && 1 === $summary['low'], json_encode( $summary ) );
ok( 'worst list starts with the weakest', 101 === $summary['worst'][0]['pid'] && 'کوتاه' === $summary['worst'][0]['title'] );

$GLOBALS['jobs'] = array();
$res = Haa_Aeo::optimize_worst();
$queued = array();
foreach ( (array) $GLOBALS['jobs'] as $row ) {
        if ( 'pc_content' === (string) $row['job'] && 101 === (int) ( $row['payload']['product_id'] ?? 0 ) ) {
                $queued[] = $row;
        }
}
ok( 'group optimize queues only the weak one', 1 === (int) $res['queued'] && 1 === count( $queued ), json_encode( $res ) );
Haa_Aeo::scores_reset();
ok( 'reset wipes scores', 0 === Haa_Aeo::scores_summary()['scored'] );

/* ==================================================================
 * G. Index health checks
 * =============================================================== */
echo "== G. index health ==\n";

reset_opts();
$GLOBALS['opts']['blog_public']         = '1';
$GLOBALS['opts']['permalink_structure'] = '/%postname%/';
$GLOBALS['posts']    = array();
$GLOBALS['post_meta'] = array();
seed_post( 900, 'برگه noindex', 'page' );
$GLOBALS['post_meta'][900]['_haai_noindex'] = '1';

$checks = Haa_Aeo::health_checks();
$labels = wp_list_pluck( $checks, 'label' );
ok( 'nine checks, all labelled', 9 === count( $checks ) && in_array( 'اجازه‌ی نمایه‌شدن به موتورهای جستجو', $labels, true ), json_encode( $labels, JSON_UNESCAPED_UNICODE ) );
$by = array();
foreach ( $checks as $c ) { $by[ $c['label'] ] = $c; }
ok( 'blog_public + permalinks + sitemap green', $by['اجازه‌ی نمایه‌شدن به موتورهای جستجو']['ok'] && $by['پیوندهای یکتا']['ok'] && $by['نقشه‌ی سایت XML']['ok'] );
ok( 'https honest against an http harness', false === $by['HTTPS']['ok'] );
ok( 'noindex counter sees the seeded page', false !== strpos( $by['صفحات تولیدیِ کنارگذاشته از ایندکس']['note'], '1' ) );

$GLOBALS['opts']['blog_public'] = '0';
$checks = Haa_Aeo::health_checks();
foreach ( $checks as $c ) { if ( 'اجازه‌ی نمایه‌شدن به موتورهای جستجو' === $c['label'] ) { $public = $c; } }
ok( 'blog_public off is flagged with the fix', false === $public['ok'] && false !== strpos( $public['note'], 'خواندن' ) );
reset_opts();

/* ==================================================================
 * H. Settings round-trip + nightly cron
 * =============================================================== */
echo "== H. settings + cron ==\n";

reset_opts();
$GLOBALS['posts'] = array();
Haa_Settings::save_section( 'knowledge', array(
        'urls'          => '',
        'auto_crawl'    => '1',
        'max_pages'     => '2000',
        'crawl_types'   => array( 'page' ),
        'llms_enabled'  => '1',
        'links_enabled' => '1',
        // bots_enabled deliberately ABSENT: an unchecked checkbox must sanitize to 0.
) );
ok( 'llms + links persist as 1', 1 === (int) Haa_Settings::get( 'knowledge.llms_enabled' ) && 1 === (int) Haa_Settings::get( 'knowledge.links_enabled' ) );
ok( 'absent checkbox saves as 0', 0 === (int) Haa_Settings::get( 'knowledge.bots_enabled' ) );

$GLOBALS['crons'] = array( 'haai_nightly_links' => 12345 );
$GLOBALS['opts'][ HAAI_OPTION ]['knowledge']['links_enabled'] = 0;
Haa_Settings::flush_cache();
Haa_Aeo::nightly_links();
ok( 'toggle off → nightly event self-clears', ! isset( $GLOBALS['crons']['haai_nightly_links'] ) );

$GLOBALS['crons'] = array( 'haai_nightly_links' => 12345 );
$GLOBALS['opts'][ HAAI_OPTION ]['knowledge']['links_enabled'] = 1;
Haa_Settings::flush_cache();
$GLOBALS['http_map'] = array();
$GLOBALS['head_log'] = array();
Haa_Aeo::nightly_links();
$state = get_option( Haa_Aeo::OPT_LSTATE );
ok( 'toggle on → one bounded nightly pass ran', is_array( $state ) && isset( $state['cycle_at'] ), json_encode( $state ) );

echo "----\n";
if ( 0 === $fail ) {
        echo "ALL 34-CHECKS GREEN (" . 'tmp-checks34-aeo.php' . ")\n";
        exit( 0 );
}
echo "$fail CHECK(S) FAILED\n";
exit( 1 );
