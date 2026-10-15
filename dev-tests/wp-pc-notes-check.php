<?php
/**
 * Regression test — بند «ب» گام ۲: structured review notes.
 *
 * Runs on the real bench (WP + WooCommerce). The model is a canned transport:
 * pre_http_request answers with the reply each case needs, so nothing leaves the
 * machine and no key is used. What is asserted is the stored post content, the
 * preview transient and the return values of the production functions.
 *
 * Run: php C:\xampp\wp-cli\wp-cli.phar --path=C:\xampp\htdocs\haai-test eval-file dev-tests/wp-pc-notes-check.php
 */
$GLOBALS['fail'] = 0;

/**
 * @param string $label Check name.
 * @param bool   $cond  Result.
 * @param string $extra Evidence.
 * @return void
 */
function nok( $label, $cond, $extra = '' ) {
	if ( ! $cond ) {
		$GLOBALS['fail']++;
	}
	echo ( $cond ? '  ok   ' : '  FAIL ' ) . str_pad( $label, 54 ) . $extra . "\n";
}

/**
 * Does this text still hold a note?
 *
 * @param string $text Text.
 * @return bool
 */
function n_has( $text ) {
	return Haa_Content::has_note( (string) $text );
}

/* ------------------------------------------------------------------
 * Fixture
 * ---------------------------------------------------------------- */

$settings_before = get_option( HAAI_OPTION );
$GLOBALS['settings_before'] = $settings_before;
$s = (array) Haa_Settings::get();
$s['provider']['base_url']      = 'https://8.8.8.8/v1';
$s['provider']['api_key']       = 'capture-only';
$s['provider']['model']         = 'capture-model';
$s['provider']['retries']       = 0;
$s['content']['internal_links'] = 0; // the link block is بند «ج»; keep this test about notes
update_option( HAAI_OPTION, $s, 'no' );
Haa_Settings::flush_cache();

$good_desc = 'کیف دوشی چرم طبیعی با آستر پارچه‌ای و بند تنظیم‌شونده. دوخت دور تا دور کیف دو ردیف است و کفه‌ی کیف ضخیم‌تر از بدنه ساخته شده تا شکل خود را نگه دارد. جیب داخلی برای موبایل در نظر گرفته شده است.';
$prod      = new WC_Product_Simple();
$prod->set_name( 'تست ب — کیف دوشی' );
$prod->set_status( 'publish' );
$prod->set_price( '850000' );
$prod->set_description( $good_desc );
$prod->set_short_description( 'چرم طبیعی با آستر پارچه‌ای' );
$pid = (int) $prod->save();
$GLOBALS['pc_pid'] = $pid;

// A fatal half-way through must not leave a fixture behind on the bench, so the
// cleanup runs twice: now (asserted) and on shutdown (insurance).
register_shutdown_function(
	function () {
		$pid = isset( $GLOBALS['pc_pid'] ) ? (int) $GLOBALS['pc_pid'] : 0;
		if ( $pid && get_post( $pid ) ) {
			wp_delete_post( $pid, true );
		}
		if ( isset( $GLOBALS['settings_before'] ) ) {
			update_option( HAAI_OPTION, $GLOBALS['settings_before'], 'no' );
		}
	}
);

$GLOBALS['mock_reply'] = '{}';
add_filter(
	'pre_http_request',
	function ( $preempt, $args ) {
		$GLOBALS['last_sent'] = array(
			'body' => (string) $args['body'],
			'url'  => '',
		);
		return array(
			'headers'  => array(),
			'body'     => wp_json_encode( array( 'choices' => array( array( 'message' => array( 'role' => 'assistant', 'content' => $GLOBALS['mock_reply'] ) ) ) ) ),
			'response' => array( 'code' => 200, 'message' => 'OK' ),
			'cookies'  => array(),
		);
	},
	10,
	2
);

/**
 * The reply the fake model will give next.
 *
 * @param array $parts html, title, desc, slug, excerpt, faq, notes.
 * @return void
 */
function n_mock( $parts ) {
	$GLOBALS['mock_reply'] = wp_json_encode(
		array(
			'html'            => isset( $parts['html'] ) ? $parts['html'] : '<p>بدنه‌ی نمونه.</p>',
			'seo_title'       => isset( $parts['seo_title'] ) ? $parts['seo_title'] : 'کیف دوشی چرم',
			'seo_description' => isset( $parts['seo_description'] ) ? $parts['seo_description'] : 'کیف چرم طبیعی.',
			'slug'            => isset( $parts['slug'] ) ? $parts['slug'] : 'hami-test-bagh',
			'excerpt'         => isset( $parts['excerpt'] ) ? $parts['excerpt'] : 'چرم طبیعی.',
			'faq'             => isset( $parts['faq'] ) ? $parts['faq'] : array(),
			'notes'           => isset( $parts['notes'] ) ? $parts['notes'] : array(),
		),
		JSON_UNESCAPED_UNICODE
	);
}

/**
 * Put the bench back.
 *
 * @return void
 */
function n_teardown() {
	$pid = isset( $GLOBALS['pc_pid'] ) ? (int) $GLOBALS['pc_pid'] : 0;
	delete_transient( 'haai_pc_preview_' . $pid );
	delete_transient( 'haai_pc_applied_' . $pid );
	update_option( HAAI_OPTION, $GLOBALS['settings_before'], 'no' );
	Haa_Settings::flush_cache();
	if ( $pid ) {
		wp_delete_post( $pid, true );
	}
}

/* ------------------------------------------------------------------
 * G0. the pre-fix behaviour, proven rather than assumed
 * ---------------------------------------------------------------- */
if ( ! method_exists( 'Haa_Content', 'split_notes' ) ) {
	echo "=== G0. code without بند «ب» ===\n";
	nok( 'Haa_Content::split_notes() exists', false, 'method missing' );
	n_mock(
		array(
			'html'            => '<p>متن محصول [نیاز به داده واقعی: گارانتی]</p>',
			'seo_description' => 'گارانتی [نیاز به بررسی]',
		)
	);
	$legacy = Haa_Content::product_brief( 'تست ب — کیف دوشی', array( 'نام' => 'تست ب — کیف دوشی' ), array( 'length' => 'short' ), array( 'module' => 'product-content' ) );
	nok( 'LEGACY: the note stayed inside html', false !== strpos( (string) $legacy['html'], '[نیاز' ), (string) $legacy['html'] );
	nok( 'LEGACY: no notes channel exists', ! isset( $legacy['notes'] ) );
	Haa_Seo::apply( $pid, $legacy );
	nok( 'LEGACY: the note reached the product page', false !== strpos( (string) get_post_field( 'post_content', $pid ), '[نیاز' ), (string) get_post_field( 'post_content', $pid ) );
	n_teardown();
	echo "\nFAILURES: " . $GLOBALS['fail'] . "\n";
	return;
}

/* ------------------------------------------------------------------
 * A. the parser on its own
 * ---------------------------------------------------------------- */
echo "=== A. split_notes: find notes, keep real text ===\n";
$body = '<p>این کیف از چرم طبیعی ساخته شده [نیاز به داده واقعی: نوع دباغی].</p>'
	. '<p>[نیاز به بررسی: ابعاد بند]</p>'
	. '<p>رنگ‌بندی (مشکی و قهوه‌ای) همان چیزی است که در فروشگاه می‌بینید.</p>'
	. '<ul><li>آستر پارچه‌ای (نیازمند تأیید انبار)</li></ul>';
$parts = Haa_Content::split_notes( $body );
nok( 'no bracketed note left in the text', ! n_has( $parts[0] ) );
nok( 'the literal "[نیاز" is gone', false === strpos( $parts[0], '[نیاز' ) );
nok( 'three notes were found', 3 === count( $parts[1] ), wp_json_encode( $parts[1], JSON_UNESCAPED_UNICODE ) );
nok( 'the empty paragraph is removed', false === strpos( $parts[0], '<p></p>' ), $parts[0] );
nok( 'a bracket that is real data survives', false !== strpos( $parts[0], '(مشکی و قهوه‌ای)' ) );
nok( 'note text keeps its meaning', false !== mb_strpos( implode( '|', $parts[1] ), 'نوع دباغی', 0, 'UTF-8' ) );

nok( 'plain prose has no note', ! n_has( 'کیف چرم با یک سال گارانتی سرویس.' ) );
nok( 'a bracketed number is not a note by itself', ! n_has( 'ابعاد (۳۰ در ۲۰ سانتی‌متر) ثبت شده است.' ) );

// The pattern is adjustable: a site can teach it another marker.
$extra = function ( $pattern ) {
	return '/[\[\(\{⟦][^\]\)\}⟧\n]{0,220}(?:نیاز|یادداشت)[^\]\)\}⟧\n]{0,220}[\]\)\}⟧]/u';
};
add_filter( 'haai_note_pattern', $extra );
$custom = Haa_Content::split_notes( '<p>متن محصول⟦نیاز به داده: وزن⟧تمام.</p>' );
nok( 'the filter widens the pattern', 1 === count( $custom[1] ) && ! n_has( $custom[0] ), wp_json_encode( $custom, JSON_UNESCAPED_UNICODE ) );
remove_filter( 'haai_note_pattern', $extra );
$plain = Haa_Content::split_notes( '<p>متن محصول⟦نیاز به داده: وزن⟧تمام.</p>' );
nok( 'without the filter the marker is not special', 0 === count( $plain[1] ) );

/* ------------------------------------------------------------------
 * B. product_brief: contract + defensive split
 * ---------------------------------------------------------------- */
echo "\n=== B. product_brief moves notes out of every field ===\n";
n_mock(
	array(
		'html'            => "<h2>معرفی</h2>\n<p>کیف از چرم طبیعی است.</p>\n<p>[نیاز به داده واقعی: گارانتی]</p>",
		'seo_title'       => 'کیف دوشی چرم [نیاز به بررسی قیمت]',
		'seo_description' => 'کیف چرم طبیعی؛ موجودی [نیازمند داده]',
		'excerpt'         => 'چرم طبیعی.',
		'faq'             => array(
			array( 'q' => 'جنس چیست؟', 'a' => 'چرم طبیعی.' ),
			array( 'q' => 'گارانتی دارد؟', 'a' => '[نیاز به داده: متن گارانتی]' ),
			array( 'q' => 'بند قابل تنظیم است؟', 'a' => 'بله.' ),
		),
		'notes'           => array( 'وزن محصول در داده‌ها نیست', 'وزن محصول در داده‌ها نیست' ),
	)
);
$brief = Haa_Content::product_brief( 'تست ب — کیف دوشی', array( 'نام' => 'تست ب — کیف دوشی' ), array( 'length' => 'short' ), array( 'module' => 'product-content' ) );
nok( 'brief produced', ! empty( $brief['ok'] ), (string) ( $brief['error'] ?? '' ) );
nok( 'html has no note', ! n_has( $brief['html'] ) && false === strpos( $brief['html'], '[نیاز' ), $brief['html'] );
nok( 'seo_title has no note', ! n_has( $brief['seo_title'] ), $brief['seo_title'] );
nok( 'seo_description has no note', ! n_has( $brief['seo_description'] ), $brief['seo_description'] );
nok( 'every faq answer is note-free', ! array_filter( $brief['faq'], function ( $r ) { return n_has( $r['q'] . ' ' . $r['a'] ); } ), wp_json_encode( $brief['faq'], JSON_UNESCAPED_UNICODE ) );
nok( 'the question with no answer was dropped', 2 === count( $brief['faq'] ) );
nok( 'the model notes field is kept', in_array( 'وزن محصول در داده‌ها نیست', $brief['notes'], true ), wp_json_encode( $brief['notes'], JSON_UNESCAPED_UNICODE ) );
nok( 'notes found in prose were moved', count( $brief['notes'] ) >= 4, wp_json_encode( $brief['notes'], JSON_UNESCAPED_UNICODE ) );
$sent = json_decode( (string) $GLOBALS['last_sent']['body'], true );
$sys  = (string) ( $sent['messages'][0]['content'] ?? '' );
nok( 'the contract asks for a notes field', false !== strpos( $sys, '"notes":["..."]' ) );
nok( 'the contract forbids brackets in html', false !== mb_strpos( $sys, 'براکت', 0, 'UTF-8' ) );

// The fallback path (model ignored JSON) must split too.
$GLOBALS['mock_reply'] = '<p>متن ساده‌ی مدل [نیاز به داده: سایز]</p>';
$fallback = Haa_Content::product_brief( 'تست ب — کیف دوشی', array(), array( 'length' => 'short' ), array( 'module' => 'product-content' ) );
nok( 'non-JSON reply still splits notes', ! n_has( $fallback['html'] ) && count( $fallback['notes'] ) >= 1, wp_json_encode( $fallback['notes'], JSON_UNESCAPED_UNICODE ) );

/* ------------------------------------------------------------------
 * C. Haa_Seo::apply is the final guard
 * ---------------------------------------------------------------- */
echo "\n=== C. apply refuses to write a note ===\n";
$stuck_brief = array(
	'html'            => '<p>متن محصول [نیاز به داده: موجودی]</p>',
	'seo_title'       => 'عنوان سالم',
	'seo_description' => 'توضیح سالم.',
	'slug'            => 'guard-test',
	'faq'             => array(),
	'excerpt'         => '',
	'notes'           => array(),
);
$before = (string) get_post_field( 'post_content', $pid );
$res    = Haa_Seo::apply( $pid, $stuck_brief, true );
nok( 'apply reports the block', 'notes' === (string) $res['blocked'], wp_json_encode( $res ) );
nok( 'nothing was written', (string) get_post_field( 'post_content', $pid ) === $before && empty( $res['body'] ) && empty( $res['title'] ) );
nok( 'the note was reported, not swallowed', 1 === count( Haa_Seo::notes_in( $stuck_brief ) ) );

$clean_brief = Haa_Module_Product_Content::clean_notes( $stuck_brief );
$res2        = Haa_Seo::apply( $pid, $clean_brief );
$after       = (string) get_post_field( 'post_content', $pid );
nok( 'a cleaned brief can be written', ! empty( $res2['body'] ) && empty( $res2['blocked'] ), wp_json_encode( $res2 ) );
nok( 'final page html has no "[نیاز"', false === strpos( $after, '[نیاز' ) && ! n_has( $after ), $after );
nok( 'clean_notes moved it to the note list', in_array( 'نیاز به داده: موجودی', $clean_brief['notes'], true ), wp_json_encode( $clean_brief['notes'], JSON_UNESCAPED_UNICODE ) );

/* ------------------------------------------------------------------
 * D. merge_edits cleans what the admin pasted
 * ---------------------------------------------------------------- */
echo "\n=== D. posted edits are cleaned too ===\n";
$_POST = array(
	'seo_title'       => 'عنوان ویرایش‌شده [نیاز به تأیید]',
	'seo_description' => 'توضیح متا.',
	'slug'            => '',
	'html'            => '<p>ویرایش ادمین با [نیاز به داده: کد محصول] همراه شد.</p>',
	'faq_present'     => '1',
	'faq'             => array( array( 'q' => 'سؤال', 'a' => 'پاسخ (نیازمند بررسی)' ) ),
);
$merged = Haa_Module_Product_Content::merge_edits(
	array(
		'html'            => '<p>متن مدل</p>',
		'seo_title'       => 'عنوان مدل',
		'seo_description' => 'متای مدل',
		'slug'            => 'model-slug',
		'excerpt'         => '',
		'faq'             => array(),
		'notes'           => array( 'یادداشت داده' ),
	)
);
nok( 'posted html is clean', ! n_has( $merged['html'] ), $merged['html'] );
nok( 'posted title is clean', ! n_has( $merged['seo_title'] ), $merged['seo_title'] );
nok( 'posted faq row is clean', ! n_has( $merged['faq'][0]['a'] ), wp_json_encode( $merged['faq'], JSON_UNESCAPED_UNICODE ) );
nok( 'an empty slug field keeps the current URL (no slug write)', '' === $merged['slug'] );
nok( 'all pasted notes are collected', count( $merged['notes'] ) >= 4, wp_json_encode( $merged['notes'], JSON_UNESCAPED_UNICODE ) );
nok( 'the data note is still there', in_array( 'یادداشت داده', $merged['notes'], true ) );
$_POST = array();

/* ------------------------------------------------------------------
 * E. the queue path: preview only, never published
 * ---------------------------------------------------------------- */
echo "\n=== E. queued job stores a note, not a page ===\n";
n_mock(
	array(
		'html'  => '<p>متن صف</p><p>[نیاز به داده: رنگ‌بندی]</p>',
		'notes' => array( 'سایزها در داده نیست' ),
		'faq'   => array( array( 'q' => 'جنس؟', 'a' => 'چرم.' ) ),
	)
);
delete_transient( 'haai_pc_preview_' . $pid );
$content_before = (string) get_post_field( 'post_content', $pid );
$module         = new Haa_Module_Product_Content();
$GLOBALS['haai_job_result'] = null;
$module->run_content_job(
	array( 'id' => 1 ),
	array(
		'product_id' => $pid,
		'tone'       => 'friendly',
		'length'     => 'short',
		'keywords'   => '',
	)
);
$stored = get_transient( 'haai_pc_preview_' . $pid );
nok( 'queue job did not fail', ! is_wp_error( $GLOBALS['haai_job_result'] ), is_wp_error( $GLOBALS['haai_job_result'] ) ? $GLOBALS['haai_job_result']->get_error_message() : '' );
nok( 'a preview was stored', is_array( $stored ) );
nok( 'the stored body is note-free', is_array( $stored ) && ! n_has( $stored['html'] ) );
nok( 'the stored preview carries notes', is_array( $stored ) && count( (array) $stored['notes'] ) >= 2, wp_json_encode( is_array( $stored ) ? $stored['notes'] : null, JSON_UNESCAPED_UNICODE ) );
nok( 'the product page was NOT written by the queue', (string) get_post_field( 'post_content', $pid ) === $content_before );
nok( 'no apply marker from a job', false === get_transient( 'haai_pc_applied_' . $pid ) );

// BL-06: the queue shares the per-product lock with the manual click.
nok( 'the job released its lock', ! Haa_Module_Product_Content::generate_locked( $pid ) );
nok( 'lock helpers exist', method_exists( 'Haa_Module_Product_Content', 'acquire_generate_lock' ) );
if ( method_exists( 'Haa_Module_Product_Content', 'acquire_generate_lock' ) ) {
	Haa_Module_Product_Content::acquire_generate_lock( $pid );   // pretend a browser click is mid-flight
	$GLOBALS['last_sent']         = null;
	$GLOBALS['haai_job_result']   = null;
	delete_transient( 'haai_pc_preview_' . $pid );
	$module->run_content_job( array( 'id' => 2 ), array( 'product_id' => $pid, 'tone' => 'friendly', 'length' => 'short' ) );
	$busy = $GLOBALS['haai_job_result'];
	nok( 'a busy product is refused by the queue', is_wp_error( $busy ) && 'haai_pc_busy' === $busy->get_error_code(), is_wp_error( $busy ) ? $busy->get_error_message() : 'not an error' );
	nok( 'the refusal cost no model call', null === $GLOBALS['last_sent'] );
	nok( 'the refusal stored no preview', false === get_transient( 'haai_pc_preview_' . $pid ) );
	nok( 'the refusal left the manual lock alone', Haa_Module_Product_Content::generate_locked( $pid ) );
	Haa_Module_Product_Content::release_generate_lock( $pid );

	$GLOBALS['haai_job_result'] = null;
	$module->run_content_job( array( 'id' => 3 ), array( 'product_id' => $pid, 'tone' => 'friendly', 'length' => 'short' ) );
	nok( 'after the lock is free the job runs', ! is_wp_error( $GLOBALS['haai_job_result'] ) && is_array( get_transient( 'haai_pc_preview_' . $pid ) ) );
	nok( 'and releases the lock again', ! Haa_Module_Product_Content::generate_locked( $pid ) );

	// A non-JSON reply is rescued as plain body (graceful), but the lock must go.
	$GLOBALS['mock_reply']      = 'متن ساده بدون JSON';
	$GLOBALS['haai_job_result'] = null;
	delete_transient( 'haai_pc_preview_' . $pid );
	$module->run_content_job( array( 'id' => 4 ), array( 'product_id' => $pid, 'tone' => 'friendly', 'length' => 'short' ) );
	nok( 'a non-JSON reply still yields a preview', is_array( get_transient( 'haai_pc_preview_' . $pid ) ), is_wp_error( $GLOBALS['haai_job_result'] ) ? $GLOBALS['haai_job_result']->get_error_message() : 'ok' );
	$raw_brief = get_transient( 'haai_pc_preview_' . $pid );
	nok( 'BL-08: the fallback body is the reply itself', is_array( $raw_brief ) && false !== strpos( (string) $raw_brief['html'], 'متن ساده بدون JSON' ), (string) ( $raw_brief['html'] ?? '' ) );
	nok( 'BL-08: and the title comes from the product, not from nothing', is_array( $raw_brief ) && '' !== trim( (string) $raw_brief['seo_title'] ), (string) ( $raw_brief['seo_title'] ?? '' ) );
	nok( 'and releases the lock', ! Haa_Module_Product_Content::generate_locked( $pid ) );
	$GLOBALS['mock_reply'] = '{}';

	// A hard failure (unknown product) must release its lock too.
	$GLOBALS['haai_job_result'] = null;
	$module->run_content_job( array( 'id' => 5 ), array( 'product_id' => 999999, 'tone' => 'friendly', 'length' => 'short' ) );
	nok( 'an unknown product fails the job', is_wp_error( $GLOBALS['haai_job_result'] ), is_wp_error( $GLOBALS['haai_job_result'] ) ? $GLOBALS['haai_job_result']->get_error_message() : 'not an error' );
	nok( 'a failed job releases its lock', ! Haa_Module_Product_Content::generate_locked( 999999 ) );
}

/* ------------------------------------------------------------------
 * F. an old preview (written before this fix) still cannot publish
 * ---------------------------------------------------------------- */
echo "\n=== F. legacy preview ===\n";
set_transient(
	'haai_pc_preview_' . $pid,
	array(
		'ok'             => true,
		'html'           => '<p>پیش‌نمایش قدیمی [نیاز به داده واقعی: گارانتی]</p>',
		'seo_title'      => 'عنوان قدیمی',
		'seo_description' => 'متای قدیمی',
		'slug'           => 'legacy',
		'excerpt'        => '',
		'faq'            => array(),
	),
	HOUR_IN_SECONDS
);
$legacy = get_transient( 'haai_pc_preview_' . $pid );
$cleaned = Haa_Module_Product_Content::clean_notes( $legacy );
nok( 'legacy notes are discovered', 1 === count( Haa_Module_Product_Content::notes_of( $cleaned ) ), wp_json_encode( $cleaned['notes'], JSON_UNESCAPED_UNICODE ) );
nok( 'legacy body is publishable after cleaning', ! n_has( $cleaned['html'] ) );
nok( 'and apply still blocks the raw legacy brief', 'notes' === (string) Haa_Seo::apply( $pid, $legacy, true )['blocked'] );

/* ------------------------------------------------------------------
 * G. the unreviewed writers (article / news / programmatic) keep working
 * ---------------------------------------------------------------- */
echo "\n=== G. non-reviewed writers: clean and record, never block silently ===\n";
$article_brief = array(
	'html'            => '<p>مقاله‌ی نمونه‌ی مدلی که [نیاز به داده: عدد رسمی] ادامه دارد.</p>',
	'seo_title'       => 'عنوان مقاله',
	'seo_description' => 'متای مقاله.',
	'slug'            => 'note-guard-article',
	'excerpt'         => '',
	'faq'             => array( array( 'q' => 'سؤال مقاله؟', 'a' => 'پاسخ مقاله.' ) ),
	'notes'           => array(),
);
$res3   = Haa_Seo::apply( $pid, $article_brief );
$page   = (string) get_post_field( 'post_content', $pid );
$meta_n = (array) get_post_meta( $pid, '_haai_review_notes', true );
nok( 'the write was not refused', empty( $res3['blocked'] ) && ! empty( $res3['body'] ), wp_json_encode( $res3 ) );
nok( 'the page carries no note', ! n_has( $page ) && false === strpos( $page, '[نیاز' ), mb_substr( $page, 0, 80 ) );
nok( 'the real prose is kept, not dropped', false !== mb_strpos( $page, 'ادامه دارد', 0, 'UTF-8' ) );
nok( 'the note is recorded for the reviewer', in_array( 'نیاز به داده: عدد رسمی', $meta_n, true ), wp_json_encode( $meta_n, JSON_UNESCAPED_UNICODE ) );
nok( 'the FAQ survived', false !== mb_strpos( $page, 'سؤال مقاله؟', 0, 'UTF-8' ) );

/* ------------------------------------------------------------------
 * Teardown
 * ---------------------------------------------------------------- */
$kept_settings = $settings_before;
n_teardown();
nok( 'teardown: fixture removed and settings restored', ! get_post( $GLOBALS['pc_pid'] ) && get_option( HAAI_OPTION ) === $kept_settings );
echo "\nFAILURES: " . $GLOBALS['fail'] . "\n";
