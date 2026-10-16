<?php
/**
 * Regression suite: "the bot must answer from the site's content".
 *
 * The user's live report: a Gilan-intro site with blog articles, chatbot on,
 * yet the bot said «اطلاعاتی درباره‌ی کافه‌های رشت در داده‌های وبلاگ من وجود
 * ندارد». Root cause: Haa_Search::search() only reads the plugin's chunks
 * table, and that table was only ever written by the semantic-search module's
 * save_post hook — with the module off the KB stayed empty forever, so RAG
 * returned nothing no matter what the site published.
 *
 * Fixes locked here:
 *   1. live_posts_search() — one bounded LIKE query over wp_posts when the
 *      KB yields zero rows, so un-indexed sites still answer from content;
 *   2. Haa_Search::on_save_post() — core save_post chunk maintenance
 *      (priority 9), so the KB stays fresh with the semantic module off;
 *   3. the semantic module's hook now only embeds (no double indexing);
 *   4. the bundled Vazirmatn variable font actually reaches @font-face.
 *
 * Run: php dev-tests/tmp-checks21-siteanswer.php
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

function seed_gilan_site() {
	$GLOBALS['chunk_rows']   = array();
	$GLOBALS['written']      = array();
	$GLOBALS['post_content'] = array(
		900 => 'معرفی بهترین کافه‌های رشت. کافه ایران، کافه مارتا و کافه دو همسفر از پرطرفدارترین کافه‌های رشت هستند؛ قهوه‌ی اسپشیالیتی، فضای کار و دسرهای خانگی دارند.',
		901 => 'جاذبه‌های گردشگری گیلان: تالاب انزلی، بازار رشت و روستای صومعه‌سرا. راهنمای سفر به شمال.',
		902 => 'کافه‌گردی در انزلی: کافه موج کنار تالاب، صبحانه‌ی دریایی و چای محلی.',
		903 => 'این پیش‌نویس هرگز نباید در نتایج چت ظاهر شود. کافه مخفی.',
		905 => 'محصول ووکامرسی: قهوه‌ی ترکی ۲۵۰ گرمی. کافه هم قید شده ولی نوع محصول خارج از دامنه است.',
		906 => 'پیش‌نویس مقاله‌ای درباره‌ی کافه‌های لاهیجان.',
	);
	$GLOBALS['posts']        = array(
		array( 'id' => 900, 'title' => 'بهترین کافه‌های رشت', 'type' => 'post', 'status' => 'publish' ),
		array( 'id' => 901, 'title' => 'جاذبه‌های گردشگری گیلان', 'type' => 'post', 'status' => 'publish' ),
		array( 'id' => 902, 'title' => 'کافه‌گردی در انزلی', 'type' => 'post', 'status' => 'publish' ),
		array( 'id' => 903, 'title' => 'کافه مخفی (پیش‌نویس)', 'type' => 'post', 'status' => 'draft' ),
		array( 'id' => 905, 'title' => 'قهوه‌ی ترکی ۲۵۰ گرمی', 'type' => 'product', 'status' => 'publish' ),
		array( 'id' => 906, 'title' => 'کافه‌های لاهیجان', 'type' => 'post', 'status' => 'draft' ),
	);
}

echo "=== A. live fallback — KB empty, content exists (the user's exact case) ===\n";
reset_kb_config();
seed_gilan_site();
$hits = Haa_Search::search( 'سلام خوبی؟ از کسب‌وکارهای اینجا خبر داری؟ کافه‌های رشت بهترین‌اش کدوم‌اند؟', 3, 1500 );
ok( 'fallback returns hits with an empty KB', is_array( $hits ) && count( $hits ) > 0 );
$top = $hits[0] ?? array();
ok( 'top hit is the Rasht cafés post', 900 === (int) ( $top['post_id'] ?? 0 ) );
ok( 'hit carries the BM25 field shape', isset( $top['id'], $top['post_id'], $top['title'], $top['url'], $top['content'], $top['score'] ) );
ok( 'draft post never surfaces', ! in_array( 903, array_map( 'intval', wp_list_pluck( $hits, 'post_id' ) ), true ) );
ok( 'product out of default scope', ! in_array( 905, array_map( 'intval', wp_list_pluck( $hits, 'post_id' ) ), true ) );
ok( 'snippet mentions cafés', false !== strpos( (string) ( $top['content'] ?? '' ), 'کافه' ) );
ok( 'url is a permalink', false !== strpos( (string) ( $top['url'] ?? '' ), '?post=900' ) );
$rc = new ReflectionMethod( 'Haa_Chat', 'build_context' );
$rc->setAccessible( true );
$ctx = $rc->invoke( null, $hits );
ok( 'chat context carries title + body', false !== strpos( $ctx, 'بهترین کافه‌های رشت' ) && false !== strpos( $ctx, 'کافه' ) );

echo "=== B. core save_post hook — KB fills without the semantic module ===\n";
reset_kb_config();
Haa_Search::on_save_post( 900 );
$kb_rows = array_values( array_filter( (array) $GLOBALS['chunk_rows'], function ( $r ) { return 900 === (int) ( $r['post_id'] ?? 0 ); } ) );
ok( 'chunks written for post 900', count( $kb_rows ) > 0 );
ok( 'chunks carry normalised text', '' !== (string) ( $kb_rows[0]['content_norm'] ?? '' ) );
$hits = Haa_Search::search( 'کافه‌های رشت', 3, 1500 );
ok( 'search now rides the KB (BM25), not the fallback', count( $hits ) > 0 && 900 === (int) ( $hits[0]['post_id'] ?? 0 ) );
ok( 'BM25 hit holds the real chunk body', false !== strpos( (string) ( $hits[0]['content'] ?? '' ), 'اسپشیالیتی' ) );

echo "=== C. hook gates: kb_enabled off / out-of-scope / draft ===\n";
reset_kb_config();
$before = count( $GLOBALS['chunk_rows'] );
$s = Haa_Settings::get();
$s['advanced']['kb_enabled'] = 0;
Haa_Settings::save_section( 'advanced', array( 'kb_enabled' => 0 ) );
reset_kb_config();
Haa_Search::on_save_post( 900 );
ok( 'kb_enabled=0: no re-index', count( $GLOBALS['chunk_rows'] ) === $before );
Haa_Settings::save_section( 'advanced', array( 'kb_enabled' => 1 ) );
reset_kb_config();
Haa_Search::on_save_post( 905 ); // product — outside default kb_post_types.
Haa_Search::on_save_post( 906 ); // draft.
$products = array_filter( $GLOBALS['chunk_rows'], function ( $r ) { return in_array( (int) ( $r['post_id'] ?? 0 ), array( 905, 906 ), true ); } );
ok( 'product + draft never indexed', 0 === count( $products ) );
Haa_Search::on_save_post( 903 ); // flip draft post — stays out via in_scope.
$draft_rows = array_filter( $GLOBALS['chunk_rows'], function ( $r ) { return 903 === (int) ( $r['post_id'] ?? 0 ); } );
ok( 'draft post 903 still unindexed', 0 === count( $draft_rows ) );

echo "=== D. semantic module hook only embeds (no double indexing) ===\n";
reset_kb_config();
$s = Haa_Settings::get();
Haa_Settings::save_section( 'modules', array( 'semantic-search' => 1 ) );
$before = count( $GLOBALS['chunk_rows'] );
$mod = new Haa_Module_Semantic_Search();
$mod->on_save_post( 900 ); // vectors_model() is '' in the harness → embed no-ops.
ok( 'module hook does not duplicate chunks', count( $GLOBALS['chunk_rows'] ) === $before );
Haa_Settings::save_section( 'modules', array( 'semantic-search' => 0 ) );

echo "=== E. KB disabled → retrieval stays silent (no hidden cost) ===\n";
reset_kb_config();
Haa_Settings::save_section( 'advanced', array( 'kb_enabled' => 0, 'enable_rag' => 1 ) );
reset_kb_config();
$hits = Haa_Search::search( 'کافه‌های رشت', 3, 1500 );
ok( 'kb_enabled=0: search returns nothing', array() === $hits );
Haa_Settings::save_section( 'advanced', array( 'kb_enabled' => 1 ) );
reset_kb_config();

echo "=== F. bundled Vazirmatn reaches @font-face ===\n";
ok( 'font file shipped + readable', is_readable( HAAI_PATH . 'assets/fonts/Vazirmatn-var.woff2' ) );
ok( 'OFL license shipped', is_readable( HAAI_PATH . 'assets/fonts/OFL.txt' ) );
ok( 'settings default font = vazirmatn', 'vazirmatn' === Haa_Settings::get( 'appearance.font' ) );
$face = haa_font_face( 'vazirmatn' );
ok( 'vazirmatn face emitted', false !== strpos( $face, 'Vazirmatn-var.woff2' ) && false !== strpos( $face, 'font-weight:100 900' ) && false !== strpos( $face, 'font-display:swap' ) );
ok( 'missing families stay silent', '' === haa_font_face( 'iransans' ) && '' === haa_font_face( 'system' ) && '' === haa_font_face( 'dana' ) );
ok( 'vazirmatn stack leads with the face', 0 === strpos( haa_font_stack( 'vazirmatn' ), '"Vazirmatn",system-ui' ) );
$inline = haa_inline_css( Haa_Settings::get() );
ok( 'inline CSS carries the face for admin+widget', false !== strpos( $inline, '@font-face' ) && false !== strpos( $inline, 'Vazirmatn-var.woff2' ) );

echo "\n" . ( $fail ? "FAILURES: $fail\n" : "ALL OK\n" );
exit( $fail ? 1 : 0 );
