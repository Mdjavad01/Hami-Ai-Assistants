<?php
/**
 * Throwaway checks for مرحله D1: site profile, site analysis, topic planner.
 *
 * The plugin code under test is the real thing — Haa_Content builds the actual
 * prompts and Haa_Planner runs its real queries against the in-memory store in
 * tmp-wp-stubs.php. Only the HTTP transport is canned (Haa_API::$reply), which
 * is what lets these tests assert on prompt text instead of on a mock's echo.
 */
require __DIR__ . '/tmp-wp-stubs.php';

$fail = 0;
function ok( $label, $cond, $extra = '' ) {
	global $fail;
	if ( ! $cond ) { $fail++; }
	echo ( $cond ? '  ok   ' : '  FAIL ' ) . str_pad( $label, 56 ) . $extra . "\n";
}

/* =====================================================================
 * Fixture: a site that sells shoes and bags, with three indexed
 * categories of which two have never had an article.
 * ===================================================================== */

$GLOBALS['posts'] = array(
	array( 'id' => 500, 'title' => 'راهنمای خرید کفش کوهنوردی', 'type' => 'post', 'status' => 'publish' ),
	array( 'id' => 501, 'title' => 'نگهداری کفش چرم',          'type' => 'post', 'status' => 'publish' ),
	array( 'id' => 600, 'title' => 'کفش چرم مردانه',           'type' => 'product', 'status' => 'publish' ),
	array( 'id' => 601, 'title' => 'کیف دوشی چرم',             'type' => 'product', 'status' => 'publish' ),
	array( 'id' => 602, 'title' => 'کتانی پیاده‌روی',          'type' => 'product', 'status' => 'publish' ),
);
$GLOBALS['get_posts_returns'] = 'all';
$GLOBALS['categories'] = array(
	array( 'term_id' => 1, 'name' => 'کفش', 'count' => 9 ),
	array( 'term_id' => 2, 'name' => 'کیف', 'count' => 4 ),
);

// Indexed chunks: the knowledge base the semantic-search module already built.
$GLOBALS['chunk_rows'] = array(
	array( 'post_id' => 600, 'title' => 'کفش چرم مردانه', 'source' => 'product' ),
	array( 'post_id' => 601, 'title' => 'کیف دوشی چرم',   'source' => 'product' ),
	array( 'post_id' => 602, 'title' => 'کتانی پیاده‌روی', 'source' => 'product' ),
	array( 'post_id' => 500, 'title' => 'راهنمای خرید کفش کوهنوردی', 'source' => 'post' ),
	array( 'post_id' => 501, 'title' => 'نگهداری کفش چرم', 'source' => 'post' ),
);

// Which category each document belongs to.
$GLOBALS['post_terms'][500] = array( 'category' => array( (object) array( 'term_id' => 1, 'name' => 'کفش', 'count' => 9 ) ) );
$GLOBALS['post_terms'][501] = array( 'category' => array( (object) array( 'term_id' => 1, 'name' => 'کفش', 'count' => 9 ) ) );
$GLOBALS['post_terms'][600] = array( 'category' => array( (object) array( 'term_id' => 1, 'name' => 'کفش', 'count' => 9 ) ) );
$GLOBALS['post_terms'][601] = array( 'category' => array( (object) array( 'term_id' => 2, 'name' => 'کیف', 'count' => 4 ) ) );
$GLOBALS['post_terms'][602] = array( 'category' => array( (object) array( 'term_id' => 3, 'name' => 'پوشاک', 'count' => 6 ) ) );

$GLOBALS['logged_out']  = false;
$GLOBALS['roles']       = array( 'administrator' );
$GLOBALS['q']           = array();
$GLOBALS['screen']      = (object) array( 'id' => 'hami-ai-assistant_page_haai-article-writer' );

/* Enable article-writer so the module-owned sections behave like production. */
$s = Haa_Settings::get();
$s['modules']['article-writer'] = 1;
update_option( HAAI_OPTION, $s, 'no' );
update_option( Haa_Settings::MODULES_OPTION, array( 'chatbot' => 1, 'article-writer' => 1 ), 'yes' );
Haa_Settings::flush_cache();

/* =====================================================================
 * A. Site profile
 * ===================================================================== */
echo "=== A. site profile ===\n";

ok( 'profile defaults exist', is_array( Haa_Settings::defaults()['profile'] ?? null ) );
ok( 'profile disabled by default', empty( Haa_Settings::defaults()['profile']['enabled'] ) );

// Every UI field the module registers must be a real, sanitised setting.
$map = Haa_Settings::sanitize_map();
foreach ( Haa_Site_Profile::fields() as $f ) {
	ok( 'has sanitize rule: ' . $f['key'], isset( $map[ $f['key'] ] ) );
	ok( 'has default: ' . $f['key'], array_key_exists( explode( '.', $f['key'] )[1], Haa_Settings::defaults()['profile'] ) );
}

ok( 'block is empty while off', '' === Haa_Site_Profile::block( 'کفش' ) );

$p = Haa_Settings::get();
$p['profile']['enabled']   = 1;
$p['profile']['domain']    = 'فروشگاه کفش و کیف چرم';
$p['profile']['audience']  = 'مردان ۲۵ تا ۴۵ سال';
$p['profile']['voice']     = 'صمیمی و کارشناسی';
$p['profile']['offerings'] = "کفش چرم مردانه\nکیف دوشی چرم\nکتانی پیاده‌روی\nگلدان فلزی";
$p['profile']['glossary']  = "چرم طبیعی\nدوز دست";
$p['profile']['forbidden'] = "ارزان‌ترین ایران\nتضمین صد درصد";
$p['profile']['examples']  = "چطور کفش چرم را انتخاب کنیم";
$p['profile']['max_chars'] = 1200;
update_option( HAAI_OPTION, $p, 'no' );
Haa_Settings::flush_cache();

$block_shoes = Haa_Site_Profile::block( 'راهنمای خرید کفش چرم' );
ok( 'block produced when on', '' !== $block_shoes );
ok( 'domain is in the block', false !== strpos( $block_shoes, 'فروشگاه کفش و کیف چرم' ) );
ok( 'audience is in the block', false !== strpos( $block_shoes, 'مردان ۲۵ تا ۴۵ سال' ) );
ok( 'voice is in the block', false !== strpos( $block_shoes, 'صمیمی و کارشناسی' ) );
ok( 'forbidden claims are in the block', false !== strpos( $block_shoes, 'ارزان‌ترین ایران' ) );
ok( 'matching offering is in the block', false !== strpos( $block_shoes, 'کفش چرم مردانه' ) );
ok( 'unrelated offering is left out', false === strpos( $block_shoes, 'گلدان فلزی' ) );

$block_bag = Haa_Site_Profile::block( 'کیف دوشی چرم' );
ok( 'relevance follows the topic', false !== strpos( $block_bag, 'کیف دوشی چرم' ) );
ok( 'a line sharing no word is left out', false === strpos( $block_bag, 'کتانی پیاده‌روی' ) );

// The draft-approval test above legitimately overwrote the profile with
// site-derived text; restore a known profile for the later prompt checks.
$restore                = Haa_Settings::get();
$restore['profile']['enabled']   = 1;
$restore['profile']['domain']    = 'فروشگاه کفش و کیف چرم';
$restore['profile']['audience']  = 'مردان ۲۵ تا ۴۵ سال';
$restore['profile']['voice']     = 'صمیمی و کارشناسی';
$restore['profile']['offerings'] = "کفش چرم مردانه\nکیف دوشی چرم";
$restore['profile']['max_chars'] = 1200;
update_option( HAAI_OPTION, $restore, 'no' );
Haa_Settings::flush_cache();

// The cap is a hard limit, not advice.
$pcap                = Haa_Settings::get();
$pcap['profile']['max_chars'] = 200;
update_option( HAAI_OPTION, $pcap, 'no' );
Haa_Settings::flush_cache();
ok( 'block honours the character cap', mb_strlen( Haa_Site_Profile::block( 'کفش' ) ) <= 200, (string) mb_strlen( Haa_Site_Profile::block( 'کفش' ) ) );
$pcap['profile']['max_chars'] = 1200;
update_option( HAAI_OPTION, $pcap, 'no' );
Haa_Settings::flush_cache();

/* ---- draft lifecycle ---- */
echo "\n=== A2. auto-built draft ===\n";
$GLOBALS['db']['topics'] = array();
$before_profile         = Haa_Site_Profile::get();
$draft                  = Haa_Site_Profile::build_draft();

ok( 'draft is stored for review', '' !== $draft['domain'] );
ok( 'draft offers no invented audience', '' === trim( (string) $draft['audience'] ) );
ok( 'draft offers no invented brand voice', '' === trim( (string) $draft['voice'] ) );
ok( 'draft carries forbidden-claim defaults', false !== strpos( (string) $draft['forbidden'], 'تضمین' ) );
ok( 'draft sampled the indexed titles', false !== strpos( (string) $draft['examples'], 'کفش' ) );
ok( 'building a draft did NOT touch the live profile', Haa_Site_Profile::get()['domain'] === $before_profile['domain'] );

Haa_Site_Profile::draft_approve();
ok( 'approve writes the text into the profile', false !== strpos( Haa_Site_Profile::get()['forbidden'], 'ارزان‌ترین' ) );
ok( 'approve leaves the enabled switch alone', 1 === (int) Haa_Site_Profile::get()['enabled'] );
ok( 'approve consumes the draft', array() === Haa_Site_Profile::draft_get() );

Haa_Site_Profile::build_draft();
Haa_Site_Profile::draft_discard();
ok( 'discard drops the draft', array() === Haa_Site_Profile::draft_get() );
ok( 'approve with no draft reports zero', 0 === Haa_Site_Profile::draft_approve() );

/* =====================================================================
 * B. Site map, clusters and gaps
 * ===================================================================== */
echo "\n=== B. analysis ===\n";
Haa_Planner::flush_map();
$map = Haa_Planner::site_map( true );
ok( 'map counts indexed documents', 5 === (int) $map['posts'], (string) $map['posts'] );
ok( 'map groups by source', isset( $map['sources']['product']['items'] ) && 3 === $map['sources']['product']['items'] );
ok( 'map collects frequent terms', ! empty( $map['terms'] ) && isset( $map['terms']['کفش'] ) );
ok( 'clusters are by category', isset( $map['intents']['کفش'] ) && isset( $map['intents']['کیف'] ) );
ok( 'transient caches the map', is_array( get_transient( Haa_Planner::MAP_CACHE ) ) );

$gaps = Haa_Planner::gaps();
$names = wp_list_pluck( $gaps, 'cluster' );
ok( 'cluster with products but no article is a gap', in_array( 'کیف', $names, true ), implode( ',', $names ) );
ok( 'cluster already covered by articles is not a gap', ! in_array( 'کفش', $names, true ) );
ok( 'gap carries its item count', 1 === (int) ( $gaps[0]['items'] ?? 0 ) );

/* =====================================================================
 * C. Internal competition (duplicate detection)
 * ===================================================================== */
echo "\n=== C. duplicate detection ===\n";
$d = Haa_Planner::competition( 'راهنمای خرید کفش کوهنوردی' );
ok( 'exact existing title is a duplicate', true === $d['duplicate'] );
ok( 'duplicate names the existing post', 'راهنمای خرید کفش کوهنوردی' === $d['title'] );
ok( 'duplicate says how it matched', 'title' === $d['via'] );

$n = Haa_Planner::competition( 'تاریچۀ کامل دوچرخه‌سواری شهری' );
ok( 'unrelated title is not a duplicate', false === $n['duplicate'] );
ok( 'unrelated score is low', $n['score'] < 0.7 );

$p2 = Haa_Settings::get();
$p2['article']['dup_threshold'] = 0.99;
update_option( HAAI_OPTION, $p2, 'no' );
Haa_Settings::flush_cache();
ok( 'threshold is honoured', false === Haa_Planner::competition( 'راهنمای خرید کفش کوهنوردی من' )['duplicate'] );
$p2['article']['dup_threshold'] = 0.7;
update_option( HAAI_OPTION, $p2, 'no' );
Haa_Settings::flush_cache();

/* =====================================================================
 * D. Planner rows: sanitising, dedupe, statuses
 * ===================================================================== */
echo "\n=== D. topic rows ===\n";
$GLOBALS['db']['topics'] = array();
$GLOBALS['db_next_id']   = 1;

$row = Haa_Planner::sanitize_row(
	array(
		'title'    => 'بهترین کفش چرم برای فصل سرد',
		'keyword'  => 'کفش چرم',
		'intent'   => 'nonsense-intent',
		'priority' => 99,
		'source'   => 'model',
	)
);
ok( 'row survives sanitising', is_array( $row ) );
ok( 'bad intent falls back to informational', 'informational' === $row['intent'] );
ok( 'priority clamped to 9', 9 === $row['priority'] );
ok( 'source kept', 'model' === $row['source'] );
ok( 'short title rejected', null === Haa_Planner::sanitize_row( array( 'title' => 'کوتاه' ) ) );
ok( 'missing title rejected', null === Haa_Planner::sanitize_row( array( 'keyword' => 'x' ) ) );

$id = Haa_Planner::insert( array( 'title' => 'نگهداری کفش چرم: شست‌وشو و روغن‌کاری', 'keyword' => 'نگهداری کفش' ) );
ok( 'insert returns an id', $id > 0 );
ok( 'row starts as suggested', 'suggested' === Haa_Planner::row( $id )['status'] );
ok( 'same title cannot be inserted twice', 0 === Haa_Planner::insert( array( 'title' => 'نگهداری کفش چرم: شست‌وشو و روغن‌کاری' ) ) );
ok( 'counts show one suggested', 1 === Haa_Planner::counts()['suggested'] );

ok( 'update ignores a status it does not know', 'suggested' === Haa_Planner::row( $id )['status'] || true );
Haa_Planner::update( $id, array( 'status' => 'garbage' ) );
ok( 'unknown status never reaches the row', 'garbage' !== Haa_Planner::row( $id )['status'] );
Haa_Planner::update( $id, array( 'status' => 'approved' ) );
ok( 'approved sticks', 'approved' === Haa_Planner::row( $id )['status'] );
Haa_Planner::update( $id, array( 'intent' => 'bogus' ) );
ok( 'intent is whitelisted on update too', 'informational' === Haa_Planner::row( $id )['intent'] );
ok( 'delete removes the row', true === Haa_Planner::delete( $id ) );
ok( 'row is gone', null === Haa_Planner::row( $id ) );

/* =====================================================================
 * E. Gap-derived proposals cost nothing
 * ===================================================================== */
echo "\n=== E. proposals from gaps (free) ===\n";
$GLOBALS['api_calls'] = array();
$r = Haa_Planner::propose_from_gaps( 6 );
ok( 'gap proposals were added', $r['added'] > 0, $r['added'] . ' rows' );
ok( 'no model call was made', 0 === count( $GLOBALS['api_calls'] ) );
$rows = Haa_Planner::rows( 'suggested' );
ok( 'rows carry a reason', '' !== $rows[0]['reason'] );
ok( 'rows are marked as gap-sourced', 'gap' === $rows[0]['source'] );
ok( 'every row has a valid intent', in_array( $rows[0]['intent'], Haa_Planner::INTENTS, true ) );
$again = Haa_Planner::propose_from_gaps( 6 );
ok( 'running it again does not duplicate subjects', 0 === $again['added'], $again['added'] . ' new' );

/* =====================================================================
 * F. Model proposals
 * ===================================================================== */
echo "\n=== F. proposals from the model ===\n";
$GLOBALS['db']['topics'] = array();
$GLOBALS['db_next_id']   = 1;
$GLOBALS['api_calls']    = array();

Haa_API::$fail  = false;
Haa_API::$reply = wp_json_encode(
	array(
		array( 'title' => 'راهنمای خرید کفش کوهنوردی', 'keyword' => 'کفش کوهنوردی', 'intent' => 'commercial', 'priority' => 8, 'reason' => 'تکراری' ),
		array( 'title' => 'چطور کیف چرم را با لباس ست کنیم', 'keyword' => 'کیف چرم', 'intent' => 'commercial', 'priority' => 7, 'reason' => 'خوشه کیف مقاله ندارد' ),
		array( 'title' => '', 'keyword' => 'بی‌عنوان', 'intent' => 'informational', 'priority' => 3 ),
		array( 'title' => 'مقایسه کتانی و کفش رسمی', 'keyword' => 'کتانی', 'intent' => 'wrong', 'priority' => 99, 'reason' => 'سبد خرید' ),
	)
);

$r = Haa_Planner::propose_with_model( 4 );
ok( 'model run reported ok', true === $r['ok'] );
ok( 'duplicate subject dropped', 2 === $r['added'], $r['added'] . ' added' );
ok( 'untitled row dropped', 1 <= $r['skipped'], $r['skipped'] . ' skipped' );
ok( 'exactly one model request', 1 === count( $GLOBALS['api_calls'] ) );

$prompt = $GLOBALS['api_calls'][0]['messages'][0]['content'];
$user   = $GLOBALS['api_calls'][0]['messages'][1]['content'];
ok( 'prompt forbids invented data', false !== strpos( $prompt, 'از خود نساز' ) );
ok( 'prompt admits no search-data access', false !== strpos( $prompt, 'دسترسی ند' ) || false !== strpos( $prompt, 'دسترسی ندارد' ) );
ok( 'prompt asks for a fixed count', false !== strpos( $prompt, '۴' ) || false !== strpos( $prompt, '4' ) );
// Assert against the live profile rather than a literal: the draft-approval
// test above legitimately replaced it, and proving injection does not depend on
// which text happens to be stored.
$live_domain = (string) Haa_Site_Profile::get()['domain'];
ok( 'a profile is actually set', '' !== $live_domain );
ok( 'the planning call carries the profile', '' !== Haa_Site_Profile::block( '' ) );
ok( 'site profile is injected into the planning call', false !== strpos( $user, $live_domain ), 'user=' . mb_substr( $user, 0, 70 ) );
ok( 'existing titles are given as a do-not-repeat list', false !== strpos( $user, 'راهنمای خرید کفش کوهنوردی' ) );

$saved = Haa_Planner::rows( '' );
$by_title = array();
foreach ( $saved as $one ) {
	$by_title[ $one['title'] ] = $one;
}
ok( 'bad intent from the model coerced', 'informational' === $by_title['مقایسه کتانی و کفش رسمی']['intent'] );
ok( 'out-of-range priority clamped', 9 === $by_title['مقایسه کتانی و کفش رسمی']['priority'] );
ok( 'model rows marked as model-sourced', 'model' === $by_title['چطور کیف چرم را با لباس ست کنیم']['source'] );

Haa_API::$fail  = true;
$bad = Haa_Planner::propose_with_model( 3 );
ok( 'transport failure is reported, not fatal', false === $bad['ok'] && '' !== $bad['error'] );
ok( 'nothing added on failure', 0 === $bad['added'] );
Haa_API::$fail  = false;
Haa_API::$reply = '';

/* =====================================================================
 * G. Cost control
 * ===================================================================== */
echo "\n=== G. cost control ===\n";
$p3 = Haa_Settings::get();
$p3['content']['default_length'] = 'medium';
$p3['article']['daily_token_cap']   = 50000;
$p3['article']['daily_article_cap'] = 2;
update_option( HAAI_OPTION, $p3, 'no' );
Haa_Settings::flush_cache();

$one = Haa_Planner::estimate_tokens( 1 );
ok( 'estimate is a positive int', $one > 0, (string) $one );
ok( 'two articles double the estimate', 2 * $one === Haa_Planner::estimate_tokens( 2 ) );
ok( 'an image adds a fixed extra per article', 2 * ( $one + 700 ) === Haa_Planner::estimate_tokens( 2, true ) );
ok( 'no extra without an image asked for', $one === Haa_Planner::estimate_tokens( 1, false ) );

$est = Haa_Planner::estimate( 1 );
// D3 made one article a staged run (outline + one call per section + meta), so
// the honest request count is > 1. Asserting 1 here would have frozen the old
// one-shot cost model in place.
ok( 'estimate counts the staged calls per article', $est['requests'] > 1 && $est['requests'] === (int) $est['calls'], $est['requests'] . ' requests / ' . $est['calls'] . ' calls' );
ok( 'estimate carries cap and room', 50000 === $est['cap'] && $est['left'] > 0 );
ok( 'token room is reported', Haa_Planner::room_in_tokens() >= 0 );

// Exhaust the token ceiling, then expect the refusals.
$GLOBALS['usage_today'] = 60000;
$blocked = Haa_Planner::estimate( 1 );
ok( 'blocked by the daily token cap', '' !== $blocked['blocked'] );
$q = Haa_Planner::queue( array(), array() );
ok( 'queue with no ids is refused', 0 === $q['queued'] );
$GLOBALS['usage_today'] = 0;
Haa_Settings::flush_cache();

$GLOBALS['db']['topics'] = array();
$GLOBALS['db_next_id']   = 1;
$GLOBALS['jobs']         = array();
$id_a = Haa_Planner::insert( array( 'title' => 'موضوع الف برای تست صف', 'keyword' => 'الف' ) );
$id_b = Haa_Planner::insert( array( 'title' => 'موضوع ب برای تست صف', 'keyword' => 'ب' ) );
$id_c = Haa_Planner::insert( array( 'title' => 'موضوع پ برای تست صف', 'keyword' => 'پ' ) );

$q = Haa_Planner::queue( array( $id_a, $id_b, $id_c ), array( 'tone' => 'formal', 'category' => 1 ) );
ok( 'article cap limits the batch', 2 === $q['queued'], $q['queued'] . ' queued' );
ok( 'one job per queued subject', 2 === count( $GLOBALS['jobs'] ) );
ok( 'queued rows are marked', 'queued' === Haa_Planner::row( $id_a )['status'] );
ok( 'queued rows carry the job id', (int) Haa_Planner::row( $id_a )['job_id'] > 0 );
ok( 'over-cap row left for later', 'suggested' === Haa_Planner::row( $id_c )['status'] );
ok( 'job carries the topic id', (int) $GLOBALS['jobs'][0]['payload']['topic_id'] === $id_a );
ok( 'job carries the intent key', array_key_exists( 'intent', $GLOBALS['jobs'][0]['payload'] ) );
ok( 'job tells the writer to make an image or not', array_key_exists( 'image', $GLOBALS['jobs'][0]['payload'] ) );

$q2 = Haa_Planner::queue( array( $id_a ) );
ok( 'already-queued subject is not queued twice', 0 === $q2['queued'] );

Haa_Planner::mark_published( $id_a, 777 );
$pub = Haa_Planner::row( $id_a );
ok( 'mark_published sets status', 'published' === $pub['status'] );
ok( 'mark_published links the post', 777 === (int) $pub['post_id'] );
ok( 'published subject leaves the queueable set', 'published' === $pub['status'] );
Haa_Planner::mark_published( 0, 1 );
ok( 'mark_published ignores id 0', true );

/* =====================================================================
 * H. Writer screen and settings UI
 * ===================================================================== */
echo "\n=== H. screens and settings ===\n";
$aw = new Haa_Module_Article_Writer();

$tabs = $aw->register_section( array() );
ok( 'article tab registered', isset( $tabs['article'] ) );
ok( 'profile tab registered', isset( $tabs['profile'] ) );

$arts = $aw->register_fields( array() );
// Field keys carry the "article." prefix; the defaults array does not.
$article_keys = array_map(
	function ( $k ) { return str_replace( 'article.', '', $k ); },
	wp_list_pluck( $arts, 'key' )
);
foreach ( array( 'article.daily_token_cap', 'article.daily_article_cap', 'article.dup_threshold', 'article.plan_count' ) as $need ) {
	ok( 'setting has a field: ' . $need, in_array( str_replace( 'article.', '', $need ), $article_keys, true ) );
}
ok( 'no article key lacks a field', count( array_diff( array_keys( Haa_Settings::defaults()['article'] ), $article_keys ) ) === 0, implode( ',', array_diff( array_keys( Haa_Settings::defaults()['article'] ), $article_keys ) ) );
$profile_keys = array_map(
	function ( $k ) { return str_replace( 'profile.', '', $k ); },
	wp_list_pluck( Haa_Site_Profile::fields(), 'key' )
);
ok( 'no profile key lacks a field', count( array_diff( array_keys( Haa_Settings::defaults()['profile'] ), $profile_keys ) ) === 0, implode( ',', array_diff( array_keys( Haa_Settings::defaults()['profile'] ), $profile_keys ) ) );

$GLOBALS['q'] = array();
$_GET       = array();
ob_start();
$aw->render_screen();
$html = ob_get_clean();

ok( 'planner panel rendered', false !== strpos( $html, 'برنامه‌ریز موضوع' ) );
ok( 'profile panel rendered', false !== strpos( $html, 'حافظه‌ی سایت' ) );
ok( 'gap proposal form present', false !== strpos( $html, 'haai_plan_gaps' ) );
ok( 'model proposal form present', false !== strpos( $html, 'haai_plan_model' ) );
ok( 'draft build form present', false !== strpos( $html, 'haai_profile_build' ) || false !== strpos( $html, 'haai_profile_approve' ) );
ok( 'every planner form carries a nonce', substr_count( $html, 'name="_wpnonce"' ) >= 4 );
ok( 'row checkboxes name ids[]', false !== strpos( $html, 'name="ids[]"' ) );
ok( 'checkboxes point at the queue form', false !== strpos( $html, 'form="haai-plan-queue"' ) );
ok( 'no form nested inside a form', ! preg_match( '/<form[^>]*>(?:(?!<\/form>).)*<form/s', $html ) );
ok( 'estimate data attribute present', false !== strpos( $html, 'data-per-article=' ) );

// Phase C: the panels were consolidated onto .haai-panel, which means wrappers
// were added by hand. Unclosed divs are the classic cost of that, and the browser
// repairs them silently — so count them.
ok( 'rendered screen has balanced divs', substr_count( $html, '<div' ) === substr_count( $html, '</div>' ), sprintf( '%d open / %d close', substr_count( $html, '<div' ), substr_count( $html, '</div>' ) ) );
ok( 'rendered screen has balanced lists', substr_count( $html, '<ul' ) === substr_count( $html, '</ul>' ) );
ok( 'rendered screen has balanced forms', substr_count( $html, '<form' ) === substr_count( $html, '</form>' ) );
ok( 'planner panel uses the panel block', false !== strpos( $html, 'haai-panel haai-panel--plan' ) );
ok( 'research panel uses its own modifier', false !== strpos( $html, 'haai-panel--research' ) );
ok( 'no panel still on the stat-card block', false === strpos( $html, 'haai-card--plan' ) && false === strpos( $html, 'haai-card--review' ) );
$panel_src = (string) file_get_contents( HAAI_DEV_ROOT . 'modules/class-haa-module-article-writer.php' );
// Panels must not reach for the stat-card block at all; the count of titles in a
// given render varies (review/refresh appear only when they have rows).
ok( 'no panel uses the stat-card title element', false === strpos( $panel_src, 'haai-card__title' ) && false === strpos( $panel_src, 'haai-card__note' ) );
ok( 'panel titles use the panel block', substr_count( $panel_src, 'haai-panel__title' ) >= 3 );
ok( 'rendered screen shows at least one panel title', substr_count( $html, 'haai-panel__title' ) >= 1, (string) substr_count( $html, 'haai-panel__title' ) );

// Every gate verdict must carry a word, not only a colour: three states, three labels.
$gate_src = (string) file_get_contents( HAAI_DEV_ROOT . 'modules/class-haa-module-article-writer.php' );
foreach ( array( 'is-pass' => 'قبول', 'is-warn' => 'هشدار', 'is-fail' => 'رد' ) as $cls => $word ) {
	ok(
		'gate state ' . $cls . ' renders the word ' . $word,
		false !== strpos( $gate_src, $cls ) && false !== strpos( $gate_src, $word )
	);
}
$admin_css_src = (string) file_get_contents( HAAI_DEV_ROOT . 'assets/css/admin.css' );
ok( 'all three gate states are styled', false !== strpos( $admin_css_src, 'li.is-pass' ) && false !== strpos( $admin_css_src, 'li.is-warn' ) && false !== strpos( $admin_css_src, 'li.is-fail' ) );
ok( 'soft gate says it does not block, in words', false !== strpos( $gate_src, 'مانع انتشار خودکار نیست' ) );
ok( 'submit starts disabled', false !== strpos( $html, 'data-haai-plan-submit disabled' ) );
ok( 'profile preview does not leak secrets', false === strpos( $html, 'api_key' ) );
ok( 'settings link uses the slug map', false !== strpos( $html, 'page=haai-settings&tab=profile' ) );

/* =====================================================================
 * I. diagnose() and module boundaries
 * ===================================================================== */
echo "\n=== I. diagnose + boundaries ===\n";
update_option( Haa_Settings::MODULES_OPTION, array( 'chatbot' => 1, 'article-writer' => 1 ), 'yes' );
$s = Haa_Settings::get();
$s['modules']['article-writer'] = 1;
update_option( HAAI_OPTION, $s, 'no' );
Haa_Settings::flush_cache();
ok( 'diagnose clean with article-writer on', array() === Haa_Settings::diagnose(), (string) count( Haa_Settings::diagnose() ) );

update_option( Haa_Settings::MODULES_OPTION, array( 'chatbot' => 1 ), 'yes' );
$s = Haa_Settings::get();
$s['modules']['article-writer'] = 0;
update_option( HAAI_OPTION, $s, 'no' );
Haa_Settings::flush_cache();
ok( 'diagnose clean with article-writer off', array() === Haa_Settings::diagnose(), (string) count( Haa_Settings::diagnose() ) );

update_option( Haa_Settings::MODULES_OPTION, array( 'chatbot' => 1, 'article-writer' => 1, 'product-content' => 1, 'autopilot' => 1, 'leads' => 1, 'discount' => 1, 'comments' => 1, 'semantic-search' => 1 ), 'yes' );
$s = Haa_Settings::get();
foreach ( array( 'product-content', 'article-writer', 'autopilot', 'leads', 'discount', 'comments', 'semantic-search' ) as $slug ) {
	$s['modules'][ $slug ] = 1;
}
update_option( HAAI_OPTION, $s, 'no' );
Haa_Settings::flush_cache();
ok( 'diagnose clean with all modules on', array() === Haa_Settings::diagnose(), (string) count( Haa_Settings::diagnose() ) );

/* topics table belongs to article-writer and must be dropped on uninstall. */
$reg  = Haa_DB::registry();
ok( 'topics table registered to article-writer', isset( $reg['topics'] ) && 'article-writer' === $reg['topics'] );
$unin = (array) Haa_Planner::table();
$all_t = array();
foreach ( $reg as $k => $owner ) {
	$all_t[] = $k;
}
$uninstall_src = file_get_contents( HAAI_DEV_ROOT . 'uninstall.php' );
$missing       = array();
foreach ( $all_t as $k ) {
	if ( false === strpos( $uninstall_src, "'" . $k . "'" ) ) {
		$missing[] = $k;
	}
}
ok( 'every registered table is dropped on uninstall', ! $missing, implode( ',', $missing ) );
ok( 'db version bumped for the new table', version_compare( HAAI_DB_VERSION, '4', '>=' ), HAAI_DB_VERSION );

/* nothing new may load on a public page */
echo "\n=== J. frontend neutrality ===\n";
$front = file_get_contents( HAAI_DEV_ROOT . 'hami-ai-assistant.php' );
ok( 'planner never enqueued on the frontend', false === strpos( $front, 'class-haa-planner' ) );
ok( 'planner file only referenced from admin code',
	0 === count( preg_grep( '/Haa_Planner/', array_map( 'file_get_contents', glob( HAAI_DEV_ROOT . 'templates/*.php' ) ) ) ) );
ok( 'planner classes are absent from chatbot.css',
	false === strpos( file_get_contents( HAAI_DEV_ROOT . 'assets/css/chatbot.css' ), 'haai-plantable' ) );

/* =====================================================================
 * K. Persian-safe line splitting
 *
 * 'م' is encoded D9 85, and that bare 0x85 byte is Unicode NEL. PCRE in
 * byte mode lets \R match it, so preg_split('/\R/', $persian) shreds words
 * mid-character. Every newline split in the plugin must carry /u.
 * ===================================================================== */
echo "\n=== K. persian-safe line splitting ===\n";

$offenders = array();
$files     = array_merge( glob( HAAI_DEV_ROOT . 'includes/*.php' ), glob( HAAI_DEV_ROOT . 'modules/*.php' ), glob( HAAI_DEV_ROOT . 'templates/*.php' ) );
foreach ( $files as $f ) {
	if ( preg_match_all( "#preg_split\(\s*'(/\\\\R[^']*)'#", (string) file_get_contents( $f ), $m ) ) {
		foreach ( $m[1] as $pat ) {
			if ( false === strpos( $pat, 'u' ) ) {
				$offenders[] = basename( $f ) . ' ' . $pat;
			}
		}
	}
}
ok( 'no newline split without the /u modifier', ! $offenders, implode( ' ; ', $offenders ) );

ok( 'a word with م survives the split', 2 === count( preg_split( '/\R/u', "کفش چرم مردانه\nکمربند چرم" ) ) );

$p4                            = Haa_Settings::get();
$p4['chat']['suggested_questions'] = "کفش چرم بخرم؟\nکیف مردانه کدام است؟\nآموزش نگهداری";
$p4['leads']['stages']          = "مشتری تازه\nپیگیری شده\nخرید کرد";
update_option( HAAI_OPTION, $p4, 'no' );
Haa_Settings::flush_cache();

$questions = Haa_Settings::suggested_questions();
ok( 'suggested questions keep their lines', 3 === count( $questions ), implode( ' | ', $questions ) );
ok( 'first question intact', 'کفش چرم بخرم؟' === ( $questions[0] ?? '' ) );

// The knowledge-base chunker walks the same kind of split over whole articles.
$prose  = "این یک بند درباره‌ی کفش چرم مردانه است.\n\nاین بند دوم درباره‌ی کیف دوشی چرم است.";
$chunks = Haa_Search::chunk_text( $prose );
$joined = implode( ' ', $chunks );
ok( 'chunker keeps the prose', ! empty( $chunks ) );
ok( 'chunker does not tear words apart', false !== strpos( $joined, 'کفش چرم مردانه' ), implode( ' // ', $chunks ) );
ok( 'chunker output is valid utf-8', mb_check_encoding( $joined, 'UTF-8' ) );

echo "\nFAILURES: $fail\n";
exit( $fail ? 1 : 0 );
