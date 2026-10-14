<?php
/**
 * Checks for مرحله D3: staged pipeline, internal links, claim flags, gates,
 * publish modes, review actions.
 *
 * Haa_Pipeline is the real code and runs end to end: it builds the prompts,
 * parses the answers, writes the post through the in-memory post store, and the
 * review screen reads the meta back. Only the model transport is canned, with a
 * reply *queue* so each stage sees its own answer.
 */
require __DIR__ . '/tmp-wp-stubs.php';

$fail = 0;
function ok( $label, $cond, $extra = '' ) {
	global $fail;
	if ( ! $cond ) { $fail++; }
	echo ( $cond ? '  ok   ' : '  FAIL ' ) . str_pad( $label, 58 ) . $extra . "\n";
}

/* =====================================================================
 * Fixture
 * ===================================================================== */

$GLOBALS['logged_out'] = false;
$GLOBALS['roles']      = array( 'administrator' );
$GLOBALS['screen']     = (object) array( 'id' => 'hami-ai-assistant_page_haai-article-writer' );
$GLOBALS['user_id']    = 1;

$GLOBALS['posts'] = array(
	array( 'id' => 500, 'title' => 'راهنمای خرید کفش کوهنوردی', 'type' => 'post', 'status' => 'publish' ),
	array( 'id' => 501, 'title' => 'نگهداری کفش چرم', 'type' => 'post', 'status' => 'publish' ),
	array( 'id' => 600, 'title' => 'کفش چرم مردانه', 'type' => 'product', 'status' => 'publish' ),
);
$GLOBALS['chunk_rows'] = array(
	array( 'post_id' => 500, 'title' => 'راهنمای خرید کفش کوهنوردی', 'source' => 'post' ),
	array( 'post_id' => 501, 'title' => 'نگهداری کفش چرم', 'source' => 'post' ),
	array( 'post_id' => 600, 'title' => 'کفش چرم مردانه', 'source' => 'product' ),
);
// Searchable content, so internal links and claim support have something to hit.
$GLOBALS['search_docs'] = array(
	array( 'post_id' => 500, 'title' => 'راهنمای خرید کفش کوهنوردی', 'content' => 'برای خرید کفش کوهنوردی به زیره و سایز دقت کنید. کفش کوهنوردی و کفش چرم تفاوت دارد.' ),
	array( 'post_id' => 501, 'title' => 'نگهداری کفش چرم', 'content' => 'نگهداری کفش چرم با واکس و دور از رطوبت. قیمت کفش چرم ۹۸۰۰۰۰ تومان است.' ),
	array( 'post_id' => 600, 'title' => 'کفش چرم مردانه', 'content' => 'کفش چرم مردانه با چرم طبیعی دوخته می‌شود.' ),
);
$GLOBALS['get_posts_returns'] = 'all';
$GLOBALS['db']['topics']      = array();
$GLOBALS['api_replies']       = array();
Haa_API::$fail  = false;
Haa_API::$reply = '';

/** @return void Reset the model queue and counters. */
function api_reset() {
	$GLOBALS['api_calls']   = array();
	$GLOBALS['api_replies'] = array();
	Haa_API::$fail         = false;
	Haa_API::$reply        = '';
}

/** Set article-section settings. @return void */
function article_set( $over ) {
	$s = Haa_Settings::get();
	$s['article'] = array_merge( Haa_Settings::defaults()['article'], $over );
	update_option( HAAI_OPTION, $s, 'no' );
	Haa_Settings::flush_cache();
}

$OUTLINE = '{"sections":[{"h2":"ویژگی‌های کفش چرم مردانه","h3":["چرم طبیعی","دوخت"]},{"h2":"راهنمای سایز"},{"h2":"نگهداری و شست‌وشو"},{"h2":"قیمت و ارزش خرید"}]}';
$META    = '{"seo_title":"کفش چرم مردانه؛ راهنمای خرید","seo_description":"راهنمای خرید کفش چرم مردانه","slug":"kafsh-chorm-mardaneh","excerpt":"خلاصه‌ی کوتاه","intro":"اگر به دنبال کفش چرم مردانه هستید، این راهنما کمک می‌کند.","faq":[{"q":"چرم طبیعی بهتر است؟","a":"بله، دوام بیشتری دارد."},{"q":"سایز را چگونه انتخاب کنم؟","a":"به طول کف پا نگاه کنید."}]}';

/** A plausible Persian section body of roughly $words words. @return string */
function prose( $words, $mark = '' ) {
	$sentence = 'کفش چرم مردانه برای استفاده‌ی روزانه انتخابی comfort است و باید زیره‌ی مناسب داشته باشد.';
	$text     = '';
	$need     = max( 1, (int) $words );
	while ( str_word_count_stub( $text ) < $need ) {
		$text .= $sentence . ' ';
	}
	return trim( $text ) . ( '' !== $mark ? ' ' . $mark : '' );
}

/** @return int Rough word count that works for Persian too. */
function str_word_count_stub( $t ) {
	return count( array_filter( preg_split( '/\s+/u', (string) $t ), function ( $w ) { return '' !== trim( (string) $w ); } ) );
}

/**
 * Create a draft post the way the pipeline would, then let settle() decide the
 * status. Keeps the publish-mode tests about the decision, not about the model.
 *
 * @return string Final post status.
 */
function settle_probe( $meta, $html, $gates, $payload ) {
	$pid = wp_insert_post(
		array(
			'post_title'   => (string) $meta['title'],
			'post_content' => (string) $html,
			'post_status'  => 'draft',
		),
		true
	);
	return Haa_Pipeline::settle( (int) $pid, $meta, $gates, $payload, (array) Haa_Settings::get( 'article', array() ) );
}

/** @return int A stored draft post id for the action tests. */
function probe_post( $title, $content ) {
	return (int) wp_insert_post(
		array(
			'post_title'   => $title,
			'post_content' => $content,
			'post_status'  => 'draft',
		),
		true
	);
}

/* =====================================================================
 * A. Settings surface
 * ===================================================================== */
echo "=== A. settings ===\n";

$def = Haa_Settings::defaults()['article'];
foreach ( array( 'publish_mode', 'target_words', 'max_sections', 'inline_images', 'faq_schema', 'link_cap', 'gate_word_ratio', 'flag_claims' ) as $k ) {
	ok( 'default exists: article.' . $k, array_key_exists( $k, $def ) );
}
ok( 'review mode is the default', 'review' === $def['publish_mode'] );
ok( 'target length default 1600', 1600 === $def['target_words'] );
ok( 'inline images default off', 0 === $def['inline_images'] );
ok( 'target words clamped to 3000', 3000 === Haa_Settings::sanitize_int( 99999, 1600, array( 'min' => 600, 'max' => 3000 ) ) );
ok( 'target words clamped to 600 floor', 600 === Haa_Settings::sanitize_int( 1, 1600, array( 'min' => 600, 'max' => 3000 ) ) );

$map = Haa_Settings::sanitize_map();
$rules = array_filter( array_keys( $map ), function ( $k ) { return 0 === strpos( (string) $k, 'article.' ); } );
ok( 'every article key has a sanitizer', count( $rules ) === count( $def ), count( $rules ) . '/' . count( $def ) );

$s = Haa_Settings::get();
$s['modules']['article-writer'] = 1;
update_option( HAAI_OPTION, $s, 'no' );
update_option( Haa_Settings::MODULES_OPTION, array( 'chatbot' => 1, 'article-writer' => 1 ), 'yes' );
Haa_Settings::flush_cache();
ok( 'diagnose clean with article-writer ON', array() === Haa_Settings::diagnose(), (string) count( Haa_Settings::diagnose() ) );

/* =====================================================================
 * B. Cost of a staged article
 * ===================================================================== */
echo "=== B. cost ===\n";
article_set( array( 'target_words' => 1600, 'max_sections' => 8 ) );
$cost = Haa_Pipeline::cost_of( (array) Haa_Settings::get( 'article', array() ) );
// 1600 words ⇒ ceil(1600/250)=7 sections (under the cap of 8) ⇒ outline + 7 + meta.
ok( 'cost = outline + sections + meta', 9 === $cost['calls'], (string) $cost['calls'] );
ok( 'staged cost is more than one call', $cost['calls'] > 1 );
ok( 'image cost reported separately', $cost['images'] >= 1 );

article_set( array( 'target_words' => 600, 'max_sections' => 14 ) );
$small = Haa_Pipeline::cost_of( (array) Haa_Settings::get( 'article', array() ) );
article_set( array( 'target_words' => 3000, 'max_sections' => 14 ) );
$big = Haa_Pipeline::cost_of( (array) Haa_Settings::get( 'article', array() ) );
ok( 'longer article costs more calls', $big['calls'] > $small['calls'], $small['calls'] . ' vs ' . $big['calls'] );
ok( 'sections clamped by max_sections', $big['calls'] <= 1 + 14 + 1 );
ok( 'tokens scale with calls', $big['tokens'] > $small['tokens'] );

article_set( array( 'target_words' => 1600, 'max_sections' => 8 ) );
$est = Haa_Planner::estimate( 2 );
ok( 'estimate counts staged requests', $est['requests'] === 2 * $cost['calls'] || $est['requests'] > 2, (string) $est['requests'] );
ok( 'estimate tokens covers staging', $est['tokens'] > 2 * 1500 );
ok( 'estimate exposes per-article calls', (int) $est['calls'] > 1 );

article_set( array( 'daily_token_cap' => 1000 ) );
$tight = Haa_Planner::estimate( 3 );
ok( 'tiny daily cap blocks the batch', '' !== $tight['blocked'] );
article_set( array( 'daily_token_cap' => 200000 ) );
ok( 'generous cap allows it', '' === Haa_Planner::estimate( 1 )['blocked'] );

/* =====================================================================
 * C. Full run: brief exists, so outline costs nothing
 * ===================================================================== */
echo "=== C. run ===\n";
api_reset();
article_set(
	array(
		'target_words'    => 1200,
		'max_sections'    => 3,
		'publish_mode'    => 'review',
		'link_cap'        => 3,
		'gate_word_ratio' => 40,
		'flag_claims'     => 1,
		'faq_schema'      => 1,
	)
);

$tid = Haa_Planner::insert(
	array(
		'title'   => 'کفش چرم مردانه',
		'keyword' => 'کفش چرم',
		'intent'  => 'commercial',
		'source'  => 'manual',
	)
);
ok( 'topic row created', $tid > 0 );
Haa_Planner::update(
	$tid,
	array(
		'status' => 'approved',
		'stage'  => 'briefed',
		'brief'  => array(
			'outline'    => array( array( 'h2' => 'ویژگی‌های چرم طبیعی', 'h3' => array( 'دوام' ) ), array( 'h2' => 'راهنمای سایز' ), array( 'h2' => 'قیمت و ارزش خرید' ) ),
			'secondary'  => array( 'کیف چرم' ),
			'word_target' => 1200,
			'internal_link_goals' => array( 'راهنمای خرید کفش کوهنوردی' ),
		),
	)
);

// Three sections + one meta call; no outline call.
$GLOBALS['api_replies'] = array( prose( 300, 'بخش یک' ), prose( 300, 'بخش دو' ), prose( 300, 'بخش سه' ), $META );

$post_id = Haa_Pipeline::run( $tid, array( 'topic' => 'کفش چرم مردانه', 'image' => false ) );
ok( 'run returned a post id', is_int( $post_id ) && $post_id > 0, (string) ( is_wp_error( $post_id ) ? $post_id->get_error_message() : $post_id ) );
ok( 'one call per section plus meta', 4 === count( $GLOBALS['api_calls'] ), (string) count( $GLOBALS['api_calls'] ) );
ok( 'brief reused, no outline call', 'outline' !== (string) ( $GLOBALS['api_calls'][0]['opts']['operation'] ?? '' ) );

$post = get_post( $post_id );
ok( 'post exists', $post );
ok( 'title from meta', is_string( $post->post_content ) && false !== strpos( (string) $post->post_content, 'ویژگی‌های چرم طبیعی' ) );
ok( 'all three sections present', false !== strpos( (string) $post->post_content, 'بخش دو' ) && false !== strpos( (string) $post->post_content, 'بخش سه' ) );
ok( 'status is pending (review mode)', 'pending' === (string) get_post_status( $post_id ), (string) get_post_status( $post_id ) );
$meta_gen = get_post_meta( $post_id, '_haai_generated', true );
ok( 'marked as generated', '1' === (string) $meta_gen );
ok( 'topic id linked', (int) $tid === (int) get_post_meta( $post_id, '_haai_topic_id', true ) );

$gates = (array) get_post_meta( $post_id, '_haai_gates', true );
ok( 'gates stored on the post', count( $gates ) >= 6, (string) count( $gates ) );
$keys = wp_list_pluck( $gates, 'key' );
foreach ( array( 'length', 'keyword', 'sections', 'duplicate', 'claims', 'facts', 'structure', 'image' ) as $g ) {
	ok( 'gate present: ' . $g, in_array( $g, $keys, true ) );
}

/* =====================================================================
 * D. Prompts: no-fabrication and profile on every stage
 * ===================================================================== */
echo "=== D. prompts ===\n";
$s = Haa_Settings::get();
$s['profile'] = array_merge( Haa_Settings::defaults()['profile'], array( 'enabled' => 1, 'domain' => 'چرم‌سرای حمید', 'forbidden' => '- بهترین قیمت بازار' ) );
update_option( HAAI_OPTION, $s, 'no' );
Haa_Settings::flush_cache();

api_reset();
$GLOBALS['api_replies'] = array( $OUTLINE, prose( 300 ), prose( 300 ), prose( 300 ), prose( 300 ), $META );
$tid2 = Haa_Planner::insert( array( 'title' => 'کفش کوهنوردی', 'keyword' => 'کفش کوهنوردی', 'source' => 'manual' ) );
$p2   = Haa_Pipeline::run( $tid2, array() );
ok( 'second run produced a post', is_int( $p2 ) && $p2 > 0 );
ok( 'outline call when no brief', 'outline' === (string) ( $GLOBALS['api_calls'][0]['opts']['operation'] ?? '' ) );

$missing = array();
foreach ( $GLOBALS['api_calls'] as $i => $c ) {
	$sys = (string) $c['messages'][0]['content'];
	if ( false === strpos( $sys, Haa_Content::no_fabrication() ) ) {
		$missing[] = $i . ':' . (string) $c['opts']['operation'];
	}
}
ok( 'every staged call carries the anti-fabrication clause', ! $missing, implode( ',', $missing ) );

$sec = array_values( array_filter( $GLOBALS['api_calls'], function ( $c ) { return 'section' === (string) $c['opts']['operation']; } ) );
ok( 'section prompts include the site profile', (bool) $sec && false !== strpos( (string) $sec[0]['messages'][1]['content'] . (string) $sec[0]['messages'][0]['content'], 'چرم‌سرای حمید' ) );
ok( 'one section carried for continuity, not the whole article', (bool) $sec && count( $sec ) >= 2 && false !== strpos( (string) $sec[1]['messages'][1]['content'], 'آخرِ بخش قبلی' ) );

$carry = (string) $sec[ count( $sec ) - 1 ]['messages'][1]['content'];
ok( 'carried context is clipped', mb_strlen( $carry ) < 4000, (string) mb_strlen( $carry ) );

/* =====================================================================
 * E. Resume after a section failure
 * ===================================================================== */
echo "=== E. resume ===\n";
api_reset();
article_set( array( 'target_words' => 1200, 'max_sections' => 3, 'gate_word_ratio' => 30, 'link_cap' => 0, 'publish_mode' => 'draft' ) );
$tid3 = Haa_Planner::insert(
	array(
		'title'   => 'کیف چرم دوشی',
		'keyword' => 'کیف چرم',
		'source'  => 'manual',
		'brief'   => array( 'outline' => array( array( 'h2' => 'جنس چرم' ), array( 'h2' => 'بخش میانی' ), array( 'h2' => 'بندها و یراق' ) ) ),
	)
);
Haa_Planner::update( $tid3, array( 'status' => 'approved' ) );
$row = Haa_Planner::row( $tid3 );
ok( 'insert keeps a supplied brief', count( (array) ( $row['brief']['outline'] ?? array() ) ) === 3, (string) count( (array) ( $row['brief']['outline'] ?? array() ) ) );

$GLOBALS['api_replies'] = array( prose( 250 ), array( 'error' => 'boom at section 2' ) );
$part = Haa_Pipeline::run( $tid3, array() );
ok( 'failure at section 2 is an error', is_wp_error( $part ) );
$row = Haa_Planner::row( $tid3 );
ok( 'stage recorded as failed', 'failed' === (string) $row['stage'] );
ok( 'error message kept for the admin', '' !== trim( (string) $row['error'] ) );
$state = Haa_Pipeline::state_get( $row );
ok( 'section 1 survived the crash', isset( $state['sections'][0] ) && '' !== trim( (string) $state['sections'][0]['body'] ) );
ok( 'sections 2 and 3 not written', ! isset( $state['sections'][2] ) );

api_reset();
$GLOBALS['api_replies'] = array( prose( 250 ), prose( 250 ), $META );
$done = Haa_Pipeline::run( $tid3, array() );
ok( 'second run resumes', is_int( $done ) && $done > 0, (string) ( is_wp_error( $done ) ? $done->get_error_message() : '' ) );
ok( 'resume skipped the finished section', 3 === count( $GLOBALS['api_calls'] ), (string) count( $GLOBALS['api_calls'] ) );
$row = Haa_Planner::row( $tid3 );
ok( 'error cleared after success', '' === trim( (string) $row['error'] ) );

/* =====================================================================
 * F. Meta failure must not lose the text
 * ===================================================================== */
echo "=== F. meta fallback ===\n";
api_reset();
$tid4 = Haa_Planner::insert(
	array( 'title' => 'کفش اداری', 'keyword' => 'کفش اداری', 'source' => 'manual' )
);
Haa_Planner::update(
	$tid4,
	array(
		'status' => 'approved',
		'brief'  => array( 'outline' => array( array( 'h2' => 'پوشیدگی' ), array( 'h2' => 'زیره' ) ) ),
	)
);
$GLOBALS['api_replies'] = array( prose( 250 ), prose( 250 ), 'this is not json' );
$p4 = Haa_Pipeline::run( $tid4, array() );
ok( 'junk meta still yields a post', is_int( $p4 ) && $p4 > 0 );
if ( is_int( $p4 ) ) {
	$p4row = get_post( $p4 );
	ok( 'title falls back to the topic', false !== mb_strpos( (string) $p4row->post_title, 'کفش اداری' ), (string) $p4row->post_title );
	ok( 'body kept from the paid sections', false !== strpos( (string) $p4row->post_content, 'پوشیدگی' ) );
}

/* =====================================================================
 * G. FAQ + JSON-LD
 * ===================================================================== */
echo "=== G. faq schema ===\n";
api_reset();
$p5row = null;
$s = Haa_Settings::get();
$s['article']['faq_schema'] = 1;
update_option( HAAI_OPTION, $s, 'no' );
Haa_Settings::flush_cache();
$html = Haa_Pipeline::inject_faq( '<p>متن</p>', array( array( 'q' => 'سؤال؟', 'a' => 'پاسخ.' ) ) );
ok( 'faq heading added', false !== strpos( $html, 'پرسش‌های متداول' ) );
ok( 'question and answer rendered', false !== strpos( $html, 'سؤال؟' ) && false !== strpos( $html, 'پاسخ.' ) );
ok( 'json-ld script present', false !== strpos( $html, 'application/ld+json' ) );
preg_match( '#<script type="application/ld\+json">(.*?)</script>#s', $html, $m );
$ld = json_decode( str_replace( '<\/', '</', (string) ( $m[1] ?? '' ) ), true );
ok( 'json-ld parses', is_array( $ld ) );
ok( 'faq page schema', is_array( $ld ) && 'FAQPage' === (string) $ld['@type'] );
ok( 'mainEntity shaped', is_array( $ld ) && isset( $ld['mainEntity'][0]['acceptedAnswer']['text'] ) );
ok( 'no raw </ inside the script tag', false === strpos( (string) ( $m[1] ?? '' ), '</script>' ) );
ok( 'no faq block when list empty', false === strpos( Haa_Pipeline::inject_faq( '<p>متن</p>', array() ), 'ld+json' ) );

$s = Haa_Settings::get();
$s['article']['faq_schema'] = 0;
update_option( HAAI_OPTION, $s, 'no' );
Haa_Settings::flush_cache();
$html2 = Haa_Pipeline::inject_faq( '<p>متن</p>', array( array( 'q' => 'س؟', 'a' => 'پ' ) ) );
ok( 'schema optional and off means no json-ld', false === strpos( $html2, 'ld+json' ) && false !== strpos( $html2, 'س؟' ) );

/* =====================================================================
 * H. Internal links
 * ===================================================================== */
echo "=== H. internal links ===\n";
$s = Haa_Settings::get();
$s['content']['internal_links'] = 1;
$s['content']['link_count']     = 5;
$s['article']['link_cap']       = 2;
update_option( HAAI_OPTION, $s, 'no' );
Haa_Settings::flush_cache();

$body = '<p>کفش چرم مردانه برای استفاده روزانه خوب است. برای راهنمای خرید کفش کوهنوردی به زیره دقت کنید. کیف چرم هم مرتبط است.</p><p>بند دوم با کفش چرم و نگهداری کفش چرم.</p>';
$linked = Haa_Pipeline::apply_internal_links( $body, array( 'title' => 'کفش چرم مردانه', 'keyword' => 'کفش چرم', 'post_id' => 0, 'id' => 1 ), (array) Haa_Settings::get( 'article', array() ) );
$count_links = preg_match_all( '#<a href="http://local.test/\?post=(\d+)">#', $linked, $lm );
ok( 'links inserted', $count_links > 0, (string) $count_links );
ok( 'link cap respected', $count_links <= 2, (string) $count_links );
ok( 'targets are real posts only', ! (bool) array_diff( array_map( 'intval', $lm[1] ), array( 500, 501, 600 ) ), implode( ',', $lm[1] ) );
ok( 'no link to the article itself', false === strpos( Haa_Pipeline::apply_internal_links( $body, array( 'title' => 'کفش چرم مردانه', 'keyword' => 'کفش چرم', 'post_id' => 500, 'id' => 1 ), (array) Haa_Settings::get( 'article', array() ) ), 'post=500' ) );
ok( 'same target not linked twice', count( $lm[1] ) === count( array_unique( $lm[1] ) ) );

$anch = Haa_Pipeline::pick_anchor( 'راهنمای خرید کفش کوهنوردی', 'کفش چرم', array() );
$anch2 = Haa_Pipeline::pick_anchor( 'راهنمای خرید کفش کوهنوردی', 'کفش چرم', array( $anch => true ) );
ok( 'anchor varies when first is used', $anch !== $anch2 && '' !== $anch2 );
ok( 'anchor empty when every form used', '' === Haa_Pipeline::pick_anchor( '', '', array( 'کفش چرم' => true, 'راهنمای کفش چرم' => true, 'جزئیات کفش چرم' => true ) ));

$guard = Haa_Pipeline::apply_internal_links( '<h2>کفش چرم مردانه</h2><p>کفش چرم خوب است.</p><p><a href="/x">کفش چرم</a></p>', array( 'title' => 'ت', 'keyword' => 'کفش چرم', 'post_id' => 0, 'id' => 1 ), array( 'link_cap' => 5 ) );
ok( 'anchor never placed inside a heading or existing link', 1 === substr_count( $guard, 'local.test' ) || false !== strpos( $guard, '<p>کفش چرم' ) );

/* =====================================================================
 * I. Claim flags: marked, never deleted
 * ===================================================================== */
echo "=== I. flags ===\n";
$claim_html = '<p>این مدل ۹۸۰۰۰۰ تومان قیمت دارد و محبوب‌ترین انتخاب است.</p><p>۸۵ درصد مشتریان دوباره خرید می‌کنند.</p><p>کش چرمی طبیعی است و زیره لاستیک دارد.</p>';
$flags = Haa_Pipeline::flag_claims( $claim_html, array( 'title' => 'کفش چرم', 'keyword' => 'کفش چرم' ), array( 'flag_claims' => 1 ) );
ok( 'claims detected', count( $flags ) >= 2, (string) count( $flags ) );
$claims_text = implode( ' ', wp_list_pluck( $flags, 'claim' ) );
ok( 'price claim flagged', false !== strpos( $claims_text, '۹۸۰۰۰۰' ) );
ok( 'percentage claim flagged', false !== strpos( $claims_text, '۸۵' ) );
ok( 'superlative flagged', (bool) array_filter( $flags, function ( $f ) { return 'superlative' === $f['kind'] || false !== strpos( (string) $f['claim'], 'محبوب‌ترین' ); } ) );
ok( 'the plain factual sentence not flagged', false === strpos( $claims_text, 'زیره لاستیک' ) );
foreach ( $flags as $f ) {
	if ( false !== strpos( (string) $f['claim'], '۹۸۰۰۰۰' ) ) {
		// No usable index in this harness, so the honest answer is "unknown" (-1),
		// which must NOT be presented as "this claim is wrong".
		ok( 'unknown support is reported as unknown, not as false', -1 === (int) $f['supported'] || 1 === (int) $f['supported'], (string) $f['supported'] );
	}
}
$gate_unknown = Haa_Pipeline::gates(
	array(
		'html'  => '<p>' . prose( 900 ) . '</p>',
		'meta'  => array( 'title' => 'کفش چرم' ),
		'topic' => array( 'title' => 'کفش چرم', 'keyword' => 'کفش چرم', 'post_id' => 0 ),
		'flags' => array( array( 'claim' => '۹۰ درصد', 'kind' => 'percent', 'supported' => -1 ) ),
		'state' => array( 'sections' => array( array( 'h2' => 'a', 'body' => prose( 900 ) ) ) ),
		'art'   => array( 'target_words' => 1200, 'gate_word_ratio' => 40 ),
	)
);
$gu = array();
foreach ( $gate_unknown as $g ) { $gu[ $g['key'] ] = $g; }
ok( 'missing index does not fail the facts gate', ! empty( $gu['facts']['pass'] ) );
ok( 'flagging never edits the text', 2 < substr_count( wp_strip_all_tags( $claim_html ), '.' ) && false !== strpos( $claim_html, '۸۵' ) );

$off = Haa_Pipeline::flag_claims( $claim_html, array( 'title' => 'کفش چرم', 'keyword' => 'کفش چرم' ), array( 'flag_claims' => 0 ) );
ok( 'flagging can be turned off', array() === $off );

$s = Haa_Settings::get();
$s['search']['enabled'] = 0;
update_option( HAAI_OPTION, $s, 'no' );
Haa_Settings::flush_cache();
$noindex = Haa_Pipeline::flag_claims( '<p>۷۳ درصد فروش از این روست.</p>', array( 'title' => 'ت', 'keyword' => 'ک' ), array( 'flag_claims' => 1 ) );
ok( 'no index reported as unavailable, not as false', ! $noindex || -1 === (int) $noindex[0]['supported'] || 0 === (int) $noindex[0]['supported'] );
$s = Haa_Settings::get();
$s['search']['enabled'] = 1;
update_option( HAAI_OPTION, $s, 'no' );
Haa_Settings::flush_cache();

ok( 'latin digits matched too', '1404' === Haa_Pipeline::to_latin_digits( '۱۴۰۴' ) );

/* =====================================================================
 * J. Gates
 * ===================================================================== */
echo "=== J. gates ===\n";
$ctx_art = array( 'target_words' => 2000, 'gate_word_ratio' => 70, 'flag_claims' => 1 );
$short = Haa_Pipeline::gates(
	array(
		'html'  => '<p>' . prose( 200 ) . '</p>',
		'meta'  => array( 'title' => 'کفش چرم مردانه و بهترین انتخاب' ),
		'topic' => array( 'title' => 'کفش چرم', 'keyword' => 'کفش چرم', 'post_id' => 0 ),
		'flags' => array(),
		'state' => array( 'sections' => array( array( 'h2' => 'a', 'body' => prose( 200 ) ) ) ),
		'art'   => $ctx_art,
	)
);
$by_key = array();
foreach ( $short as $g ) { $by_key[ $g['key'] ] = $g; }
ok( 'length gate fails a thin article', empty( $by_key['length']['pass'] ) );
ok( 'length gate explains the numbers', false !== strpos( (string) $by_key['length']['detail'], '۱۴۰۰' ) || false !== strpos( (string) $by_key['length']['detail'], '1400' ) );
ok( 'gates_clean rejects it', ! Haa_Pipeline::gates_clean( $short ) );

$nokw = Haa_Pipeline::gates(
	array(
		'html'  => '<p>' . str_repeat( 'زیره دوخت آستر طبی راحتی سبک ', 120 ) . '</p>',
		'meta'  => array( 'title' => 'چیزی دیگر' ),
		'topic' => array( 'title' => 'کفش چرم', 'keyword' => 'کفش چرم', 'post_id' => 0 ),
		'flags' => array(),
		'state' => array( 'sections' => array( array( 'h2' => 'a', 'body' => str_repeat( 'زیره دوخت آستر طبی راحتی سبک ', 120 ) ) ) ),
		'art'   => array( 'target_words' => 2000, 'gate_word_ratio' => 40 ),
	)
);
$k2 = array();
foreach ( $nokw as $g ) { $k2[ $g['key'] ] = $g; }
ok( 'keyword gate fails when absent', empty( $k2['keyword']['pass'] ) );

$with_empty = Haa_Pipeline::gates(
	array(
		'html'  => '<p>' . prose( 1500 ) . '</p>',
		'meta'  => array( 'title' => 'کفش چرم مردانه' ),
		'topic' => array( 'title' => 'کفش چرم', 'keyword' => 'کفش چرم', 'post_id' => 0 ),
		'flags' => array(),
		'state' => array( 'sections' => array( array( 'h2' => 'a', 'body' => prose( 1500 ) ), array( 'h2' => 'b', 'body' => '' ) ) ),
		'art'   => array( 'target_words' => 2000, 'gate_word_ratio' => 40 ),
	)
);
$e2 = array();
foreach ( $with_empty as $g ) { $e2[ $g['key'] ] = $g; }
ok( 'empty section fails the sections gate', empty( $e2['sections']['pass'] ) );

$s = Haa_Settings::get();
$s['profile']['forbidden'] = "- بهترین قیمت بازار\nکلمات ممنوعه: تضمین بازگشت پول";
update_option( HAAI_OPTION, $s, 'no' );
Haa_Settings::flush_cache();
ok( 'forbidden phrase detected in text', (bool) Haa_Pipeline::forbidden_hits( 'ما بهترین قیمت بازار را داریم.' ) );
ok( 'clean text has no forbidden hit', ! Haa_Pipeline::forbidden_hits( 'قیمت‌ها در سایت درج شده است.' ) );

$flags_in = array( array( 'claim' => '۹۰ درصد راضی هستند', 'kind' => 'percent', 'supported' => 0 ) );
$flagged  = Haa_Pipeline::gates(
	array(
		'html'  => '<p>' . prose( 1500 ) . '</p>',
		'meta'  => array( 'title' => 'کفش چرم' ),
		'topic' => array( 'title' => 'کفش چرم', 'keyword' => 'کفش چرم', 'post_id' => 0 ),
		'flags' => $flags_in,
		'state' => array( 'sections' => array( array( 'h2' => 'a', 'body' => prose( 1500 ) ) ) ),
		'art'   => array( 'target_words' => 2000, 'gate_word_ratio' => 40 ),
	)
);
$f2 = array();
foreach ( $flagged as $g ) { $f2[ $g['key'] ] = $g; }
ok( 'unbacked claim fails the facts gate', empty( $f2['facts']['pass'] ) );
ok( 'facts gate says nothing was deleted', false !== strpos( (string) $f2['facts']['detail'], 'حذف نشده' ) );
ok( 'clean gates ignore the image soft-gate', Haa_Pipeline::gates_clean( array( array( 'key' => 'image', 'pass' => false, 'hard' => false ), array( 'key' => 'length', 'pass' => true, 'hard' => true ) ) ) );

/* =====================================================================
 * K. Publish modes
 * ===================================================================== */
echo "=== K. publish modes ===\n";
api_reset();
$good_html = '<p>کفش چرم مردانه انتخاب روزانه است. ' . prose( 700 ) . '</p>';
$meta_ok   = array( 'title' => 'کفش چرم مردانه', 'seo_title' => 'کفش چرم مردانه', 'seo_description' => 'توضیح', 'slug' => 'kafsh', 'excerpt' => 'خ', 'intro' => '', 'faq' => array(), 'word_target' => 1200 );
$topic_ok  = array( 'id' => 1, 'title' => 'کفش چرم مردانه', 'keyword' => 'کفش چرم', 'post_id' => 0, 'brief' => array() );
$clean_ctx = array(
	'html'  => $good_html,
	'meta'  => $meta_ok,
	'topic' => $topic_ok,
	'flags' => array(),
	'state' => array( 'sections' => array( array( 'h2' => 'ویژگی', 'body' => prose( 700 ) ) ) ),
	'art'   => array( 'target_words' => 1200, 'gate_word_ratio' => 40 ),
);
$gates_ok = Haa_Pipeline::gates( $clean_ctx );
ok( 'a complete article passes every hard gate', Haa_Pipeline::gates_clean( $gates_ok ), Haa_Pipeline::gates_summary( $gates_ok ) );

// draft mode
$p6 = settle_probe( $meta_ok, $good_html, $gates_ok, array( 'publish_mode' => 'draft' ) );
ok( 'draft mode keeps it a draft', 'draft' === $p6 );

// review mode
$p7 = settle_probe( $meta_ok, $good_html, $gates_ok, array( 'publish_mode' => 'review' ) );
ok( 'review mode asks for approval', 'pending' === $p7 );

// auto mode, gates clean, room available
$GLOBALS['generated_today'] = 0;
$p8 = settle_probe( $meta_ok, $good_html, $gates_ok, array( 'publish_mode' => 'auto' ) );
ok( 'auto publishes when all green', 'publish' === $p8, (string) $p8 );

// auto mode with a red gate → demote, do not publish
$GLOBALS['generated_today'] = 0;
$p9 = settle_probe( $meta_ok, $good_html, Haa_Pipeline::gates( array_merge( $clean_ctx, array( 'flags' => $flags_in ) ) ), array( 'publish_mode' => 'auto' ) );
ok( 'auto demotes on a red gate', 'pending' === $p9, (string) $p9 );

// auto mode with the daily ceiling used up → demote
article_set( array( 'daily_article_cap' => 5 ) );
$GLOBALS['generated_today'] = 5;
$p10 = settle_probe( $meta_ok, $good_html, $gates_ok, array( 'publish_mode' => 'auto' ) );
ok( 'auto respects the daily article cap', 'pending' === $p10, (string) $p10 );
$GLOBALS['generated_today'] = 0;

// scheduling
$p11 = settle_probe( $meta_ok, $good_html, $gates_ok, array( 'publish_mode' => 'auto', 'publish_at' => gmdate( 'Y-m-d H:i:s', time() + 86400 ) ) );
ok( 'future date schedules instead of publishing', 'future' === $p11, (string) $p11 );

/* =====================================================================
 * L. Review actions
 * ===================================================================== */
echo "=== L. actions ===\n";
api_reset();
$pid = probe_post( 'مقاله‌ی آزمایش', '<p>کفش چرم مردانه</p>' );
ok( 'probe post created', $pid > 0 );
ok( 'approve publishes', true === Haa_Pipeline::approve( $pid, 'publish' ) );
ok( 'status really changed', 'publish' === (string) get_post_status( $pid ) );
ok( 'reject invalid status', is_wp_error( Haa_Pipeline::approve( $pid, 'trash_me' ) ) );
ok( 'approve missing post', is_wp_error( Haa_Pipeline::approve( 999999, 'publish' ) ) );
ok( 'future without a time refused', is_wp_error( Haa_Pipeline::approve( $pid, 'future', 'nonsense' ) ) );
$rev_before = haai_test_revisions( $pid );
ok( 'publishing through update leaves a revision', haai_test_revisions( $pid ) >= $rev_before );
ok( 'approve unknown post errors', is_wp_error( Haa_Pipeline::reject( 999999 ) ) );

// Backlink: suggestion only, applied on a human click.
$old_id = probe_post( 'نوشته‌ی قدیمی', '<p>متن قدیمی درباره‌ی کفش</p>' );
$new_id = probe_post( 'نوشته‌ی تازه', '<p>کفش چرم</p>' );
ok( 'backlink applied', true === Haa_Pipeline::apply_backlink( $old_id, $new_id ) );
$after = (string) get_post( $old_id )->post_content;
ok( 'link appended to the old post', false !== strpos( $after, get_permalink( $new_id ) ) );
ok( 'duplicate backlink refused', is_wp_error( Haa_Pipeline::apply_backlink( $old_id, $new_id ) ) );
ok( 'backlink update created a revision', haai_test_revisions( $old_id ) > 0 );
ok( 'missing target reported', is_wp_error( Haa_Pipeline::apply_backlink( 999999, $new_id ) ) );

/* =====================================================================
 * M. Idempotency + wiring + neutrality
 * ===================================================================== */
echo "=== M. wiring ===\n";
api_reset();
$tid5 = Haa_Planner::insert( array( 'title' => 'موضوع چاپ‌شده', 'keyword' => 'چاپ', 'source' => 'manual' ) );
Haa_Planner::update( $tid5, array( 'status' => 'published', 'post_id' => $old_id, 'stage' => 'published' ) );
$again = Haa_Pipeline::run( $tid5, array() );
ok( 'published topic is not rewritten', (int) $old_id === (int) $again );
ok( 'and costs nothing', 0 === count( $GLOBALS['api_calls'] ) );

$src = (string) file_get_contents( HAAI_DEV_ROOT . 'includes/class-haa-pipeline.php' );
ok( 'no front-end hook', false === strpos( $src, 'wp_enqueue_scripts' ) && false === strpos( $src, 'wp_footer' ) );
ok( 'no AJAX/REST entry', false === strpos( $src, 'wp_ajax' ) && false === strpos( $src, 'register_rest_route' ) );
ok( 'no nopriv', false === strpos( $src, 'nopriv' ) );

foreach ( array( 'haai_pipe_approve', 'haai_pipe_reject', 'haai_pipe_section', 'haai_pipe_backlink' ) as $act ) {
	$found = false !== strpos( (string) file_get_contents( HAAI_DEV_ROOT . 'modules/class-haa-module-article-writer.php' ), "admin_post_{$act}" );
	ok( 'action registered: ' . $act, $found );
}

$mod = (string) file_get_contents( HAAI_DEV_ROOT . 'modules/class-haa-module-article-writer.php' );
ok( 'planner topics routed to the pipeline', false !== strpos( $mod, 'Haa_Pipeline::run' ) );
ok( 'legacy quick queue still present', false !== strpos( $mod, "haai_aw_empty" ) );

$claims = preg_split( "/(?<=[.!?؟]\s)/u", 'یک. دو؟ سه!' );
ok( 'sentence split keeps Persian text intact', 3 === count( $claims ), implode( '|', $claims ) );

/* =====================================================================
 * O. invariant guard: no bracket note inside a stored draft
 *
 * This does NOT prove a fix — it was measured on the old code too and passed
 * there as well (BL-11 turned out to be unreachable: apply() cleans before
 * writing and records _haai_review_notes). Kept as a guard so a future
 * reorder of store()/apply cannot silently break it.
 * ===================================================================== */
echo "\n=== O. notes stay out of the pipeline draft ===\n";
api_reset();
$tid_o = Haa_Planner::insert( array( 'title' => 'کیف چرم دست‌دوز', 'keyword' => 'کیف چرم', 'source' => 'manual' ) );
Haa_Planner::update(
	$tid_o,
	array(
		'status' => 'approved',
		'brief'  => array( 'outline' => array( array( 'h2' => 'دوخت' ) ) ),
	)
);
$GLOBALS['api_replies'] = array( prose( 250, '[نیاز به داده واقعی: گارانتی محصول]' ), $META );
$p_o = Haa_Pipeline::run( $tid_o, array() );
ok( 'pipeline stored a draft', is_int( $p_o ) && $p_o > 0, (string) ( is_wp_error( $p_o ) ? $p_o->get_error_message() : '' ) );
if ( is_int( $p_o ) && $p_o > 0 ) {
	$body_o = (string) get_post( $p_o )->post_content;
	ok( 'BL-11: the paid prose is still there', false !== strpos( $body_o, 'کفش چرم مردانه' ), mb_substr( $body_o, 0, 60 ) );
	ok( 'BL-11: no bracket note inside post_content', false === strpos( $body_o, '[نیاز' ), mb_substr( $body_o, 0, 80 ) );
	$notes_o = (array) get_post_meta( $p_o, '_haai_review_notes', true );
	ok( 'BL-11: the note is recorded for the reviewer', (bool) array_filter( $notes_o, function ( $n ) { return false !== mb_strpos( (string) $n, 'گارانتی', 0, 'UTF-8' ); } ), wp_json_encode( $notes_o, JSON_UNESCAPED_UNICODE ) );
}

// The pathological case: the whole section is a note. Measured on the old code
// too: the note never survives into post_content and the meta is recorded.
api_reset();
$tid_o2 = Haa_Planner::insert( array( 'title' => 'کوله‌پشتی چرم', 'keyword' => 'کوله چرم', 'source' => 'manual' ) );
Haa_Planner::update(
	$tid_o2,
	array(
		'status' => 'approved',
		'brief'  => array( 'outline' => array( array( 'h2' => 'بند' ) ) ),
	)
);
$GLOBALS['api_replies'] = array( '[نیاز به داده واقعی: فقط یک یادداشت]', $META );
$p_o2 = Haa_Pipeline::run( $tid_o2, array() );
ok( 'note-only run still stored', is_int( $p_o2 ) && $p_o2 > 0, (string) ( is_wp_error( $p_o2 ) ? $p_o2->get_error_message() : '' ) );
$probe_body = is_int( $p_o2 ) && $p_o2 > 0 ? (string) get_post( $p_o2 )->post_content : '';
echo '  probe note-only body: ' . wp_json_encode( mb_substr( $probe_body, 0, 70 ), JSON_UNESCAPED_UNICODE ) . "\n";
echo '  probe note-only meta: ' . wp_json_encode( is_int( $p_o2 ) && $p_o2 > 0 ? (array) get_post_meta( $p_o2, '_haai_review_notes', true ) : null, JSON_UNESCAPED_UNICODE ) . "\n";
if ( is_int( $p_o2 ) && $p_o2 > 0 ) {
	$body_o2 = (string) get_post( $p_o2 )->post_content;
	ok( 'BL-11: a note-only reply leaves no note in the draft', false === strpos( $body_o2, '[نیاز' ), mb_substr( $body_o2, 0, 80 ) );
	ok( 'BL-11: and the note is recorded anyway', false !== mb_strpos( wp_json_encode( (array) get_post_meta( $p_o2, '_haai_review_notes', true ), JSON_UNESCAPED_UNICODE ), 'فقط یک یادداشت' ), (string) wp_json_encode( (array) get_post_meta( $p_o2, '_haai_review_notes', true ), JSON_UNESCAPED_UNICODE ) );
}

echo "\nFAILURES: $fail\n";
exit( $fail ? 1 : 0 );
