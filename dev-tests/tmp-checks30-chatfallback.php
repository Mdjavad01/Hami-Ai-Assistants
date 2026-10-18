<?php
/**
 * Regression suite: deterministic chat answers that must not depend on the
 * crawl KB being populated (2.1.19).
 *
 * The user's live report (2026-10-18): crawl "finished" at 0 صفحه / 0 تکه, and
 * the bot then claimed — for a shop with twenty real categories and a contact
 * page — «اطلاعاتی درباره دسته‌بندی‌ها نداریم» and «اطلاعاتی درباره راه‌های
 * ارتباطی در دسترس نیست». Locked here:
 *
 *   1. Haa_Chat::categories_reply() — a real category index answered
 *      deterministically for listing intents, never for price questions,
 *      never when a category is actually named (that keeps the live digest).
 *   2. Haa_Chat::contact_reply() fallback — settings channels empty → the
 *      crawler's harvested facts → the stored contact chunk → a real WP
 *      «تماس با ما» page; the model only speaks when nothing true exists.
 *   3. Haa_Crawl::diagnose() + dashboard verdicts — an empty KB is explained
 *      in plain Persian (RAG off / table missing / all-HTTP-failed / budget).
 *
 * Run: php dev-tests/tmp-checks30-chatfallback.php
 */
require __DIR__ . '/tmp-wp-stubs.php';

$fail = 0;
function ok( $label, $cond, $extra = '' ) {
	global $fail;
	if ( ! $cond ) { $fail++; }
	echo ( $cond ? '  ok   ' : '  FAIL ' ) . str_pad( $label, 58 ) . $extra . "\n";
}

$rc = new ReflectionClass( 'Haa_Chat' );
$m_categories = $rc->getMethod( 'categories_reply' );
$m_categories->setAccessible( true );
$m_contact = $rc->getMethod( 'contact_reply' );
$m_contact->setAccessible( true );

/* Contact channels empty (fresh defaults) — assert, don't assume. */
$settings = Haa_Settings::get();
foreach ( array( 'contact_whatsapp', 'contact_telegram', 'contact_phone', 'contact_instagram', 'contact_page' ) as $k ) {
	$GLOBALS['opts'] = array_diff_key( (array) $GLOBALS['opts'], array() ); // no-op guard
}
$ch = $settings['chat'];
$channels_empty = '' === (string) $ch['contact_whatsapp'] . $ch['contact_telegram'] . $ch['contact_phone'] . $ch['contact_instagram'] . $ch['contact_page'];

echo "=== A. categories_reply — the exact question the shop owner asked ===\n";
$GLOBALS['product_cats'] = array(
	(object) array( 'name' => 'ابزار قالیبافی', 'slug' => 'abzar-ghalibafi', 'count' => 12 ),
	(object) array( 'name' => 'چرخ قالیبافی',   'slug' => 'charkh-ghalibafi', 'count' => 5 ),
	(object) array( 'name' => 'خالی',           'slug' => 'empty-cat', 'count' => 0 ),
);
$r = $m_categories->invoke( null, 'دسته بندی محصولاتت کدوما هستن؟' );
ok( 'listing intent fires (user wording, spaced)', '' !== $r, $channels_empty ? '' : '(note: channels filled)' );
ok( 'reply lists the big category first', false !== strpos( (string) $r, 'ابزار قالیبافی' ) );
ok( 'reply carries the archive link', false !== strpos( (string) $r, 'product-cat/abzar-ghalibafi' ) );
ok( 'empty category stays hidden (hide_empty)', false === strpos( (string) $r, 'خالی' ) );

$r = $m_categories->invoke( null, 'دسته‌بندی‌هات چیه؟' );
ok( 'ZWNJ variant fires too', false !== strpos( (string) $r, 'دسته‌بندی‌های فروشگاه' ) );

$r = $m_categories->invoke( null, 'قیمت محصولات چقدره؟' );
ok( 'bare price question keeps product_facts path', '' === $r );
$r = $m_categories->invoke( null, 'قیمت ابزار قالیبافی چقدره؟' );
ok( 'named category keeps the live digest path', '' === $r );
$r = $m_categories->invoke( null, 'سلام خوبی؟' );
ok( 'small talk never triggers the list', '' === $r );

echo "=== B. contact_reply — channels empty, crawl facts present ===\n";
if ( ! $channels_empty ) {
	Haa_Settings::save_section( 'chat', array(
		'contact_whatsapp' => '', 'contact_telegram' => '', 'contact_phone' => '',
		'contact_instagram' => '', 'contact_page' => '',
	) );
}
$GLOBALS['opts']['hami_ai_crawl_state'] = array(
	'facts' => array( 'phone:09121234567', 'instagram:https://instagram.com/example.shop', 'contact_page:https://site.test/contact' ),
);
$r = $m_contact->invoke( null, 'راه های ارتباطی کدومن؟' );
ok( 'crawl facts answer the contact ask', false !== strpos( (string) $r, '09121234567' ) );
ok( 'instagram link carried verbatim', false !== strpos( (string) $r, 'instagram.com/example.shop' ) );
ok( 'contact page link carried verbatim', false !== strpos( (string) $r, 'site.test/contact' ) );
$r = $m_contact->invoke( null, 'قیمت دارفروش چنده؟' );
ok( 'non-contact message returns empty', '' === $r );

echo "=== C. contact_reply — no state facts, stored contact chunk speaks ===\n";
$GLOBALS['opts']['hami_ai_crawl_state'] = array( 'facts' => array() );
$GLOBALS['chunk_rows'] = array(
	array(
		'post_id' => -1,
		'source'  => 'contact',
		'content' => "شماره تماس و راه‌های ارتباطی رسمی این فروشگاه:\nتلفن: 01333445566\nاینستاگرام: https://instagram.com/shop\nهنگام معرفی راه‌های ارتباطی، همین مقادیر را عیناً بنویس.",
	),
);
$r = $m_contact->invoke( null, 'شماره تماس فروشگاه چنده؟' );
ok( 'stored chunk phone reaches the visitor', false !== strpos( (string) $r, '01333445566' ) );
ok( 'model instruction line never leaks', false === strpos( (string) $r, 'عیناً' ) );
ok( 'chunk header never leaks', false === strpos( (string) $r, 'رسمی این فروشگاه' ) );

echo "=== D. contact_reply — nothing crawled, a real WP page answers ===\n";
$GLOBALS['chunk_rows'] = array();
$GLOBALS['posts']      = array(
	array( 'id' => 70, 'title' => 'تماس با ما', 'type' => 'page', 'status' => 'publish' ),
	array( 'id' => 71, 'title' => 'درباره ما',  'type' => 'page', 'status' => 'publish' ),
);
$r = $m_contact->invoke( null, 'صفحه تماس کجاست؟' );
ok( 'WP contact page found by title', '' !== $r );
ok( 'reply carries a permalink', false !== strpos( (string) $r, 'local.test' ) );

echo "=== E. settings channels win over every fallback ===\n";
Haa_Settings::save_section( 'chat', array( 'contact_phone' => '02112345678' ) );
$GLOBALS['opts']['hami_ai_crawl_state'] = array(
	'facts' => array( 'phone:09121234567', 'contact_page:https://site.test/contact' ),
);
$r = $m_contact->invoke( null, 'راه های ارتباطی کدومن؟' );
ok( 'configured phone is the one shown', false !== strpos( (string) $r, '02112345678' ) );
ok( 'crawl phone does not leak past settings', false === strpos( (string) $r, '09121234567' ) );

echo "=== F. Haa_Crawl::diagnose() — the empty-KB verdicts ===\n";
Haa_Settings::save_section( 'chat', array( 'contact_phone' => '' ) );
$GLOBALS['opts']['hami_ai_crawl_state'] = array(
	'running' => 0, 'finished_at' => time(), 'pages' => 0, 'chunks' => 0,
	'failed' => 30, 'total' => 30, 'last_error' => 'HTTP 403',
);
$d = Haa_Crawl::diagnose();
foreach ( array( 'rag_on', 'table_ok', 'rows_all', 'rows_crawl', 'queue', 'http', 'budget', 'counts', 'last' ) as $k ) {
	ok( "diagnose() carries $k", array_key_exists( $k, $d ) );
}
ok( 'chunks table seen as present', true === (bool) $d['table_ok'] );
ok( 'last run read as failed', 30 === (int) $d['last']['failed'] && 0 === (int) $d['last']['pages'] );
ok( 'queue at least holds the homepage', (int) $d['queue'] >= 1 );
ok( 'http targets counted', array_key_exists( 'http', $d ) && (int) $d['http'] >= 1 );

$GLOBALS['chunks_table_missing'] = 1;
$d = Haa_Crawl::diagnose();
ok( 'missing table is detected', false === (bool) $d['table_ok'] );
unset( $GLOBALS['chunks_table_missing'] );

$dash = file_get_contents( HAAI_DEV_ROOT . 'includes/class-haa-admin-dashboard.php' );
ok( 'dashboard: RAG-off verdict present', false !== strpos( $dash, 'پایگاه دانش (RAG)» خاموش است' ) );
ok( 'dashboard: all-HTTP-failed verdict present', false !== strpos( $dash, 'هیچ صفحه‌ای را ذخیره نکرد' ) );
ok( 'dashboard: zero-stored verdict present', false !== strpos( $dash, 'هیچ صفحه‌ای ذخیره نشد' ) );
ok( 'dashboard: table repair verdict present', false !== strpos( $dash, 'جدول پایگاه دانش موجود نبود' ) );
ok( 'dashboard: budget hint present', false !== strpos( $dash, 'بودجه‌ی خزش فعلی' ) );
ok( 'dashboard calls diagnose()', false !== strpos( $dash, 'Haa_Crawl::diagnose()' ) );

echo "=== G. nothing true exists → '' (model fallback preserved) ===\n";
$GLOBALS['opts']['hami_ai_crawl_state'] = array( 'facts' => array() );
$GLOBALS['chunk_rows']                  = array();
$GLOBALS['posts']                       = array();
$r = $m_contact->invoke( null, 'راه های ارتباطی کدومن؟' );
ok( 'no facts, no chunk, no page → empty', '' === $r );

echo "\n";
if ( 0 === $fail ) {
	echo "OK — all checks passed (tmp-checks30-chatfallback)\n";
} else {
	echo "FAILURES: $fail\n";
}
exit( $fail ? 1 : 0 );
