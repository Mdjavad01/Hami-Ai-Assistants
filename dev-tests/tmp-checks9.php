<?php
/**
 * Checks for مرحله D4: news module, video→article, programmatic pages, monthly
 * refresh — plus the catalog/registry/diagnose parity the three new modules need.
 *
 * The module classes are the real code. Feed bodies, transcripts and CSVs are
 * literal fixtures; the network is the canned transport from tmp-wp-stubs.php;
 * posts land in the in-memory post store and are read back to prove the shape.
 */
require __DIR__ . '/tmp-wp-stubs.php';

$fail = 0;
function ok( $label, $cond, $extra = '' ) {
	global $fail;
	if ( ! $cond ) { $fail++; }
	echo ( $cond ? '  ok   ' : '  FAIL ' ) . str_pad( $label, 58 ) . $extra . "\n";
}

$GLOBALS['logged_out'] = false;
$GLOBALS['roles']      = array( 'administrator' );
$GLOBALS['screen']     = (object) array( 'id' => 'hami-ai-assistant_page_haai-news' );
$GLOBALS['user_id']    = 1;

/** Reset module state between sections. @return void */
function d4_reset() {
	$GLOBALS['db']['news_items'] = array();
	$GLOBALS['db']['prog_items'] = array();
	$GLOBALS['written']          = array();
	$GLOBALS['posts']            = array();
	$GLOBALS['next_post_id']     = 7000;
	$GLOBALS['revisions']        = array();
	$GLOBALS['post_meta']        = array();
	$GLOBALS['jobs']             = array();
	$GLOBALS['api_calls']        = array();
	$GLOBALS['api_replies']      = array();
	$GLOBALS['http_map']         = array();
	$GLOBALS['http_log']         = array();
	$GLOBALS['head_log']         = array();
	$GLOBALS['url2post_map']     = array();
	$GLOBALS['modified']         = array();
	Haa_API::$fail  = false;
	Haa_API::$reply = '';
	delete_option( Haa_Research::SNAPSHOT );
}

/**
 * Adjust one settings section.
 *
 * Merges with what is stored now, not with the defaults: a test that sets
 * min_words and later sets only `template` must not silently snap min_words back
 * to 450 and then "fail" on a length guard it had already tuned.
 *
 * @return void
 */
function section_set( $section, $over ) {
	$s = Haa_Settings::get();
	$cur = isset( $s[ $section ] ) && is_array( $s[ $section ] ) ? $s[ $section ] : Haa_Settings::defaults()[ $section ];
	$s[ $section ] = array_merge( $cur, $over );
	update_option( HAAI_OPTION, $s, 'no' );
	Haa_Settings::flush_cache();
}

/** Set a section back to its defaults plus the overrides. @return void */
function section_fresh( $section, $over ) {
	$s = Haa_Settings::get();
	$s[ $section ] = array_merge( Haa_Settings::defaults()[ $section ], $over );
	update_option( HAAI_OPTION, $s, 'no' );
	Haa_Settings::flush_cache();
}

function module_on( $slug ) {
	$s = Haa_Settings::get();
	$s['modules'][ $slug ] = 1;
	update_option( HAAI_OPTION, $s, 'no' );
	$live = Haa_Settings::enabled_modules();
	$live[ $slug ] = 1;
	update_option( Haa_Settings::MODULES_OPTION, $live, 'yes' );
	Haa_Settings::flush_cache();
}

/* =====================================================================
 * A. Catalogue, registry and diagnose parity (the three new modules)
 * ===================================================================== */
echo "=== A. parity ===\n";

$catalog = Haa_Modules::catalog();
foreach ( array( 'news', 'video-article', 'programmatic' ) as $slug ) {
	ok( 'catalogued: ' . $slug, isset( $catalog[ $slug ] ) );
	ok( 'metadata for: ' . $slug, isset( Haa_Modules::catalog_meta()[ $slug ]['label'] ) );
	ok( 'no WooCommerce claim: ' . $slug, empty( Haa_Modules::catalog_meta()[ $slug ]['woo'] ) );
}
ok( 'catalog keys == meta keys', array_keys( $catalog ) === array_keys( Haa_Modules::catalog_meta() ) );

$reg = Haa_DB::registry();
ok( 'news_items owned by news', 'news' === ( $reg['news_items'] ?? '' ) );
ok( 'prog_items owned by programmatic', 'programmatic' === ( $reg['prog_items'] ?? '' ) );

// Every registered table must be dropped by uninstall.php, or data is left behind.
$uninstall = (string) file_get_contents( HAAI_DEV_ROOT . 'uninstall.php' );
$missing   = array();
foreach ( array_keys( $reg ) as $key ) {
	if ( false === strpos( $uninstall, "'" . $key . "'" ) ) {
		$missing[] = $key;
	}
}
ok( 'uninstall drops every registry table', ! $missing, implode( ',', $missing ) );
ok( 'schema version bumped past D4 tables', version_compare( HAAI_DB_VERSION, '6', '>=' ), HAAI_DB_VERSION );

// Module classes exist and satisfy the contract.
foreach ( array( 'Haa_Module_News', 'Haa_Module_Video_Article', 'Haa_Module_Programmatic' ) as $class ) {
	ok( $class . ' loads', class_exists( $class ) );
	ok( $class . ' implements the contract', in_array( 'Haa_Module_Interface', class_implements( $class ), true ) );
}
$news_mod = new Haa_Module_News();
ok( 'slug() matches the catalog key', 'news' === $news_mod->slug() );
ok( 'video slug matches the catalog key', 'video-article' === ( new Haa_Module_Video_Article() )->slug() );
ok( 'programmatic slug matches the catalog key', 'programmatic' === ( new Haa_Module_Programmatic() )->slug() );

// New sections are off by default.
$defs = Haa_Settings::defaults();
foreach ( array( 'news', 'video', 'programmatic' ) as $sec ) {
	ok( 'section exists: ' . $sec, isset( $defs[ $sec ] ) );
	ok( 'off by default: ' . $sec, empty( $defs[ $sec ]['enabled'] ) );
}
ok( 'news caps are conservative', 5 === $defs['news']['max_per_run'] && 3 === $defs['news']['daily_cap'] );
ok( 'programmatic defaults to noindex', 1 === $defs['programmatic']['noindex'] );
ok( 'programmatic similarity guard is on', 0.75 === $defs['programmatic']['similar_threshold'] );
ok( 'refresh defaults off', empty( $defs['article']['refresh_enabled'] ) );
ok( 'refresh window is monthly-ish', 120 === $defs['article']['refresh_days'] );

$map  = Haa_Settings::sanitize_map();
$lost = array();
foreach ( array_keys( $defs ) as $sec ) {
	if ( ! is_array( $defs[ $sec ] ) ) {
		continue;
	}
	foreach ( array_keys( $defs[ $sec ] ) as $k ) {
		if ( ! isset( $map[ $sec . '.' . $k ] ) ) {
			$lost[] = $sec . '.' . $k;
		}
	}
}
ok( 'every setting has a sanitizer', ! $lost, implode( ',', $lost ) );

module_on( 'news' );
module_on( 'video-article' );
module_on( 'programmatic' );
module_on( 'article-writer' );
module_on( 'chatbot' );
$issues = Haa_Settings::diagnose();
ok( 'diagnose clean with all new modules ON', array() === $issues, implode( ' | ', array_slice( array_map( 'strval', $issues ), 0, 3 ) ) );

$s = Haa_Settings::get();
$s['modules'] = array( 'chatbot' => 1 );
update_option( HAAI_OPTION, $s, 'no' );
update_option( Haa_Settings::MODULES_OPTION, array( 'chatbot' => 1 ), 'yes' );
Haa_Settings::flush_cache();
ok( 'diagnose clean with new modules OFF', array() === Haa_Settings::diagnose() );

module_on( 'news' );
module_on( 'video-article' );
module_on( 'programmatic' );
module_on( 'article-writer' );

/* =====================================================================
 * B. News: feed parsing
 * ===================================================================== */
echo "=== B. feeds ===\n";
d4_reset();

$RSS = '<?xml version="1.0"?><rss version="2.0"><channel><title>خبرگزاری پایدار</title>'
	. '<item><title>قیمت چرم افزایش یافت</title><guid>rss-1</guid>'
	. '<link>https://8.8.8.8/news/1</link><pubDate>' . gmdate( DATE_RFC2822, time() - 3600 ) . '</pubDate>'
	. '<description><![CDATA[این متن کامل خبر است که نباید در مقاله‌ی ما بیاید.]]></description></item>'
	. '<item><title>خبر کهنه</title><guid>rss-old</guid><link>https://8.8.8.8/news/old</link>'
	. '<pubDate>' . gmdate( DATE_RFC2822, time() - 400 * DAY_IN_SECONDS ) . '</pubDate><description>قدیمی</description></item>'
	. '<item><title>بدون لینک</title><guid>rss-nolink</guid><description>x</description></item>'
	. '</channel></rss>';

$items = Haa_Module_News::parse_feed( $RSS, 'https://8.8.8.8/feed' );
ok( 'rss items parsed', 2 === count( $items ), (string) count( $items ) );
ok( 'title read', 'قیمت چرم افزایش یافت' === (string) $items[0]['title'] );
ok( 'guid kept', 'rss-1' === (string) $items[0]['guid'] );
ok( 'CDATA unwrapped into context only', false !== strpos( (string) $items[0]['excerpt'], 'نباید' ) );
ok( 'source name from channel', 'خبرگزاری پایدار' === (string) $items[0]['source'] );
ok( 'item without a link skipped', ! (bool) array_filter( $items, function ( $i ) { return 'rss-nolink' === $i['guid']; } ) );
ok( 'published normalised to mysql', 1 === preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', (string) $items[0]['published'] ) );

$ATOM = '<?xml version="1.0"?><feed xmlns="http://www.w3.org/2005/Atom"><title>آتم</title>'
	. '<entry><title>ورودی آتم</title><id>atom-1</id>'
	. '<link rel="alternate" href="https://8.8.4.4/a/1"/><updated>' . gmdate( DATE_ATOM ) . '</updated>'
	. '<summary type="html">&lt;p&gt;چکیده&lt;/p&gt;</summary></entry></feed>';
$atom = Haa_Module_News::parse_feed( $ATOM, 'https://8.8.4.4/feed' );
ok( 'atom entry parsed', 1 === count( $atom ) );
ok( 'atom link attribute used', 'https://8.8.4.4/a/1' === (string) $atom[0]['link'] );
ok( 'atom id used as guid', 'atom-1' === (string) $atom[0]['guid'] );

ok( 'junk is not a feed', array() === Haa_Module_News::parse_feed( '<html><body>not xml</body></html>' ) );
ok( 'empty body safe', array() === Haa_Module_News::parse_feed( '' ) );

/* =====================================================================
 * C. News: dedupe, windows, caps, poll
 * ===================================================================== */
echo "=== C. news run ===\n";
d4_reset();
section_set(
	'research',
	array(
		'enabled'        => 1,
		'respect_robots' => 0,
		'max_kb'         => 400,
		'timeout'        => 5,
	)
);
section_set(
	'news',
	array(
		'enabled'        => 1,
		'feeds'          => "https://8.8.8.8/feed\nhttps://8.8.8.8/feed",
		'max_per_run'    => 5,
		'daily_cap'      => 3,
		'lookback_hours' => 48,
	)
);

$hash = Haa_Module_News::title_hash( '  قیمت چرم افزایش یافت ' );
ok( 'title hash ignores spacing', $hash === Haa_Module_News::title_hash( 'قیمت چرم افزایش یافت' ) );
ok( 'different title, different hash', $hash !== Haa_Module_News::title_hash( 'قیمت چرم کاهش یافت' ) );

http_mock( '8.8.8.8/feed', array( 'code' => 200, 'body' => $RSS ) );
$r = Haa_Module_News::poll( true );
ok( 'poll queued fresh items', 1 === (int) $r['queued'], (string) $r['queued'] );
ok( 'old item skipped by lookback', $r['skipped'] >= 1, (string) $r['skipped'] );
ok( 'one job per queued item', 1 === count( $GLOBALS['jobs'] ) );
ok( 'job module is news', 'news' === (string) ( $GLOBALS['jobs'][0]['args']['module'] ?? '' ) );
ok( 'payload carries the source link', isset( $GLOBALS['jobs'][0]['payload']['link'] ) );

$before_jobs = count( $GLOBALS['jobs'] );
$r2 = Haa_Module_News::poll( true );
ok( 'second poll queues nothing new', 0 === (int) $r2['queued'], (string) $r2['queued'] );
ok( 'dedupe did not add jobs', $before_jobs === count( $GLOBALS['jobs'] ) );

// A feed whose guid changes on every edit must still be caught by title hash.
$RSS_NEWGUID = str_replace( 'rss-1', 'rss-brand-new-guid', $RSS );
http_mock( '8.8.8.8/feed', array( 'code' => 200, 'body' => $RSS_NEWGUID ) );
$r3 = Haa_Module_News::poll( true );
ok( 'title hash blocks a re-minted guid', 0 === (int) $r3['queued'], (string) $r3['queued'] );

// Dead feed: reported, and it does not stop the others.
d4_reset();
http_mock( '8.8.8.8/feed', array( 'error' => 'cURL error 28: Operation timed out' ) );
$r4 = Haa_Module_News::poll( true );
ok( 'timeout is reported in Persian', (bool) array_filter( $r4['errors'], function ( $e ) { return false !== strpos( (string) $e, 'تایم‌اوت' ) || false !== strpos( (string) $e, 'مهلت' ); } ), implode( '|', $r4['errors'] ) );
ok( 'dead feed queued nothing', 0 === (int) $r4['queued'] );

// Private address as a "feed" must never be requested.
d4_reset();
section_set( 'news', array( 'enabled' => 1, 'feeds' => 'http://169.254.169.254/latest/meta-data/' ) );
$p = Haa_Module_News::poll( true );
ok( 'metadata IP refused as a feed', 0 === (int) $p['fetched'] && 0 === count( $GLOBALS['http_log'] ) );

// daily_cap = 0 means "read nothing, say why".
d4_reset();
section_set( 'news', array( 'enabled' => 1, 'feeds' => 'https://8.8.8.8/feed', 'daily_cap' => 0 ) );
$z = Haa_Module_News::poll( true );
ok( 'daily cap 0 writes nothing', 0 === (int) $z['queued'] && (bool) $z['errors'] );

// Disabled module does nothing at all.
d4_reset();
section_set( 'news', array( 'enabled' => 0, 'feeds' => 'https://8.8.8.8/feed' ) );
http_mock( '8.8.8.8/feed', array( 'code' => 200, 'body' => $RSS ) );
$off = Haa_Module_News::poll( false );
ok( 'disabled poll makes no request', 0 === count( $GLOBALS['http_log'] ) );
ok( 'disabled poll explains itself', (bool) $off['errors'] );

/* =====================================================================
 * D. News: job writes an original note with attribution
 * ===================================================================== */
echo "=== D. news job ===\n";
d4_reset();
section_set( 'news', array( 'enabled' => 1, 'daily_cap' => 3, 'min_words' => 150, 'default_status' => 'draft', 'quote_source' => 0 ) );
$mod  = new Haa_Module_News();
Haa_API::$reply = '<p>خبر افزایش قیمت چرم را بررسی می‌کنیم و اثر آن را بر انتخاب خرید توضیح می‌دهیم.</p><h2>چه معنا دارد</h2><p>' . str_repeat( 'تحلیل خود ما درباره‌ی بازار چرم. ', 30 ) . '</p>';
$mod->run_job(
	1,
	array(
		'item_id' => 0,
		'title'   => 'قیمت چرم افزایش یافت',
		'link'    => 'https://8.8.8.8/news/1',
		'source'  => 'خبرگزاری پایدار',
		'excerpt' => 'این متن کامل خبر است که نباید در مقاله‌ی ما بیاید.',
	)
);
$res = $GLOBALS['haai_job_result'] ?? null;
ok( 'job returned a post id', is_array( $res ) && (int) $res['post_id'] > 0 );
$pid = is_array( $res ) ? (int) $res['post_id'] : 0;
if ( $pid ) {
	$post = get_post( $pid );
	ok( 'post is a draft', 'draft' === (string) $post->post_status, (string) $post->post_status );
	ok( 'source is linked', false !== strpos( (string) $post->post_content, 'https://8.8.8.8/news/1' ) );
	ok( 'source named', false !== strpos( (string) $post->post_content, 'خبرگزاری پایدار' ) );
	ok( 'rel=nofollow on the outbound link', false !== strpos( (string) $post->post_content, 'nofollow' ) );
	ok( 'source body NOT copied into the post', false === strpos( (string) $post->post_content, 'نباید در مقاله‌ی ما بیاید' ) );
	ok( 'marked as generated', '1' === (string) get_post_meta( $pid, '_haai_generated', true ) );
	ok( 'source url stored as meta', 'https://8.8.8.8/news/1' === (string) get_post_meta( $pid, '_haai_news_source', true ) );
}
$sys = (string) $GLOBALS['api_calls'][0]['messages'][0]['content'];
ok( 'job prompt forbids copying the source', false !== strpos( $sys, 'کپی' ) );
ok( 'job prompt carries anti-fabrication', false !== strpos( $sys, Haa_Content::no_fabrication() ) );
$user = (string) $GLOBALS['api_calls'][0]['messages'][1]['content'];
ok( 'quote_source off means their excerpt is not sent', false === strpos( $user, 'نباید در مقاله‌ی ما بیاید' ) );

// With quote_source on, the excerpt is sent as context and still not pasted.
d4_reset();
section_set( 'news', array( 'enabled' => 1, 'quote_source' => 1, 'min_words' => 150 ) );
Haa_API::$reply = '<p>تحلیل تازه‌ی ما.</p><p>' . str_repeat( 'بررسی اثر افزایش قیمت بر خرید. ', 30 ) . '</p>';
$mod->run_job( 1, array( 'title' => 'قیمت چرم', 'link' => '', 'source' => 'س', 'excerpt' => 'چکیده‌ی منبع برای زمینه' ) );
$user2 = (string) $GLOBALS['api_calls'][0]['messages'][1]['content'];
ok( 'quote_source on sends the excerpt as context', false !== strpos( $user2, 'چکیده‌ی منبع برای زمینه' ) );
ok( 'and marks it as not-for-copying', false !== strpos( $user2, 'بازنویسی نکن' ) );

// Transport failure must not leave a half-post or a fatal.
d4_reset();
Haa_API::$fail = true;
$mod->run_job( 1, array( 'title' => 'یک خبر', 'link' => '', 'source' => 'س', 'excerpt' => '' ) );
ok( 'model failure surfaces as WP_Error', is_wp_error( $GLOBALS['haai_job_result'] ?? null ) );
ok( 'no post written on failure', 0 === count( $GLOBALS['written'] ) );
Haa_API::$fail = false;

/* =====================================================================
 * E. Video: transcript parsing
 * ===================================================================== */
echo "=== E. transcript ===\n";
d4_reset();

$SRT = "1\r\n00:00:01,000 --> 00:00:04,000\r\nسلام، درباره‌ی کفش چرم صحبت می‌کنیم\r\n\r\n"
	. "2\r\n00:00:04,000 --> 00:00:07,000\r\nسلام، درباره‌ی کفش چرم صحبت می‌کنیم\r\n\r\n"
	. "3\r\n00:00:07,000 --> 00:00:10,000\r\n<i>دوخت</i> و زیره importance دارد\r\n";
$parsed = Haa_Module_Video_Article::parse_transcript( $SRT );
ok( 'srt indices dropped', ! preg_match( '/\n\d+\n/', $parsed ) );
ok( 'timecodes gone', false === strpos( $parsed, '-->' ) );
ok( 'repeated auto-caption line folded', 1 === substr_count( $parsed, 'صحبت می‌کنیم' ) );
ok( 'inline tags stripped', false === strpos( $parsed, '<i>' ) );
ok( 'persian text preserved whole', false !== strpos( $parsed, 'کفش چرم' ) );
ok( 'one space join, no line breaks', false === strpos( $parsed, "\n" ) );

$VTT = "WEBVTT - notes\r\n\r\nNOTE\r\nthis is a comment\r\n\r\n00:00:01.000 line\r\n\r\n1\r\n00:00:02.000 --> 00:00:05.000\r\n<c.bold>مقدمه</c> و بحث اصلی\r\n";
$v = Haa_Module_Video_Article::parse_transcript( $VTT );
ok( 'webvtt header gone', false === strpos( $v, 'WEBVTT' ) );
ok( 'note block gone', false === strpos( $v, 'this is a comment' ) );
ok( 'vtt cue styling removed', false === strpos( $v, '<c.' ) && false !== strpos( $v, 'مقدمه' ) );

ok( 'empty transcript is empty string', '' === Haa_Module_Video_Article::parse_transcript( '' ) );

// URL whitelist: only the platforms we cite.
ok( 'youtube url accepted', '' !== Haa_Module_Video_Article::video_url( 'https://www.youtube.com/watch?v=abc' ) );
ok( 'youtu.be accepted', '' !== Haa_Module_Video_Article::video_url( 'https://youtu.be/abc' ) );
ok( 'vimeo accepted', '' !== Haa_Module_Video_Article::video_url( 'https://vimeo.com/123' ) );
ok( 'arbitrary host rejected', '' === Haa_Module_Video_Article::video_url( 'https://evil.example.com/watch' ) );
ok( 'javascript url rejected', '' === Haa_Module_Video_Article::video_url( 'javascript:alert(1)' ) );

/* =====================================================================
 * F. Video: generation guards
 * ===================================================================== */
echo "=== F. video run ===\n";
d4_reset();
section_set( 'video', array( 'enabled' => 1, 'min_chars' => 100, 'max_words' => 600, 'default_status' => 'draft' ) );
section_set( 'article', array( 'daily_token_cap' => 200000, 'daily_article_cap' => 5 ) );

$short = Haa_Module_Video_Article::write( array( 'title' => 'یک ویدیو', 'url' => '', 'transcript' => 'متن خیلی کوتاه' ) );
ok( 'too-short transcript refused', is_wp_error( $short ) );
// 100 was asked for but the sanitizer floor for min_chars is 200, and the message
// quotes the number the code actually enforced.
ok( 'refusal states the numbers', false !== strpos( (string) $short->get_error_message(), '200' ), (string) $short->get_error_message() );

$noTitle = Haa_Module_Video_Article::write( array( 'title' => '', 'transcript' => str_repeat( 'حرف گوینده. ', 40 ) ) );
ok( 'missing title refused', is_wp_error( $noTitle ) );

Haa_API::$reply = '<p>مرور گفت‌وگو.</p><h2>انتخاب سایز</h2><p>' . str_repeat( 'نکته‌ی کاربردی درباره‌ی کفش. ', 40 ) . '</p>';
$GLOBALS['api_replies'] = array(
	Haa_API::$reply,
	'{"seo_title":"آموزش انتخاب کفش","seo_description":"توضیح متا","slug":"amoozesh","excerpt":"خلاصه","faq":[]}',
);
$r = Haa_Module_Video_Article::write(
	array(
		'title'      => 'آموزش انتخاب کفش',
		'url'        => 'https://www.youtube.com/watch?v=abc',
		// Repeated so the cleaned transcript clears the 200-character floor: the
		// parser legitimately drops timings, counters and NOTE blocks.
		'transcript' => str_repeat( $SRT . "\n" . $VTT . "\n", 3 ),
	)
);
ok( 'video article produced', is_array( $r ) && (int) $r['post_id'] > 0, is_wp_error( $r ) ? (string) $r->get_error_message() : '' );
if ( is_array( $r ) ) {
	$vp = get_post( (int) $r['post_id'] );
	ok( 'video cited with link', false !== strpos( (string) $vp->post_content, 'youtube.com/watch?v=abc' ) );
	ok( 'transcript NOT dumped verbatim', false === strpos( (string) $vp->post_content, '00:00:01' ) );
	ok( 'two calls: article + meta', 2 === count( $GLOBALS['api_calls'] ), (string) count( $GLOBALS['api_calls'] ) );
	ok( 'marked generated', '1' === (string) get_post_meta( (int) $r['post_id'], '_haai_generated', true ) );
	ok( 'video url kept', false !== strpos( (string) get_post_meta( (int) $r['post_id'], '_haai_video_source', true ), 'youtube.com' ) );
}

// Upload validation, reached directly because is_uploaded_file() is a PHP builtin.
$tmp = tempnam( sys_get_temp_dir(), 'haai' );
file_put_contents( $tmp, $SRT );
ok( 'srt upload accepted', ! is_wp_error( Haa_Module_Video_Article::read_upload( array( 'name' => 'cap.SRT', 'tmp_name' => $tmp, 'size' => filesize( $tmp ), 'error' => 0 ) ) ) );
ok( 'php file renamed .srt rejected? extension gate', ! is_wp_error( Haa_Module_Video_Article::read_upload( array( 'name' => 'x.srt', 'tmp_name' => $tmp, 'size' => 10, 'error' => 0 ) ) ) );
ok( 'exe upload refused', is_wp_error( Haa_Module_Video_Article::read_upload( array( 'name' => 'x.php', 'tmp_name' => $tmp, 'size' => 10, 'error' => 0 ) ) ) );
ok( 'oversized refused', is_wp_error( Haa_Module_Video_Article::read_upload( array( 'name' => 'x.srt', 'tmp_name' => $tmp, 'size' => 9000000, 'error' => 0 ) ) ) );
ok( 'php upload error surfaced', is_wp_error( Haa_Module_Video_Article::read_upload( array( 'name' => 'x.srt', 'tmp_name' => $tmp, 'size' => 10, 'error' => 1 ) ) ) );
$bin = tempnam( sys_get_temp_dir(), 'haai' );
file_put_contents( $bin, "\x00\x01binary" );
ok( 'binary disguised as txt refused', is_wp_error( Haa_Module_Video_Article::read_upload( array( 'name' => 'x.txt', 'tmp_name' => $bin, 'size' => 10, 'error' => 0 ) ) ) );
unlink( $tmp );
unlink( $bin );

// Caption fetch is opt-in and honest when off.
$cap = Haa_Module_Video_Article::fetch_captions( 'https://www.youtube.com/watch?v=abc' );
ok( 'caption fetch off by default', is_wp_error( $cap ) && false !== strpos( (string) $cap->get_error_message(), 'خاموش' ) );
section_set( 'video', array( 'enabled' => 1, 'min_chars' => 100, 'max_words' => 600, 'fetch_captions' => 1 ) );
$cap2 = Haa_Module_Video_Article::fetch_captions( 'https://evil.example.com/x' );
ok( 'non-platform host refused', is_wp_error( $cap2 ) );
$cap3 = Haa_Module_Video_Article::fetch_captions( 'http://169.254.169.254/x' );
ok( 'metadata host refused', is_wp_error( $cap3 ) && 0 === count( $GLOBALS['http_log'] ) );

/* =====================================================================
 * G. Programmatic: sources, template, thin-content guards
 * ===================================================================== */
echo "=== G. programmatic ===\n";
d4_reset();

$csv = "title,color,use,note\nکفش چرم مشکی,مشکی,اداری,\"یک, دو\"\nکیف دوشی,چرم,روزمره,x\nردیف‌بی‌داده,,,\n,,";
$rows = Haa_Module_Programmatic::parse_csv( $csv );
// Three rows carry a title; the fourth (all empty) is dropped at parse time. A
// title-only row is kept here and refused later by item_ok(), which is the layer
// that knows a page needs more than one field.
ok( 'csv rows parsed', 3 === count( $rows ), (string) count( $rows ) );
ok( 'fully empty row dropped', 2 === count( array_filter( $rows, function ( $r ) { return count( $r ) > 1; } ) ) );
ok( 'header slugified', isset( $rows[0]['color'] ) && 'مشکی' === (string) $rows[0]['color'] );
ok( 'quoted comma kept intact', 'یک, دو' === (string) $rows[0]['note'] );
ok( 'every parsed row has a title', ! (bool) array_filter( $rows, function ( $r ) { return empty( $r['title'] ); } ) );

$man = Haa_Module_Programmatic::parse_manual( "کفش چرم | color=مشکی | use=اداری\nبدون فیلد\nکیف | رنگ=قهوه" );
ok( 'manual items parsed', 3 === count( $man ) );
ok( 'manual keeps title plus fields', isset( $man[0]['color'] ) );
ok( 'a line with only a title parses to just a title', array( 'title' ) === array_keys( $man[1] ) );
ok( 'persian key preserved', isset( $man[2]['رنگ'] ) || isset( $man[2]['rang'] ) );
ok( 'titleless line dropped', ! (bool) array_filter( $man, function ( $m ) { return '' === trim( (string) $m['title'] ); } ) );

$rendered = Haa_Module_Programmatic::render( '{{title}} از جنس {{color}} مناسب {{use}}', $rows[0] );
ok( 'placeholders filled', 'کفش چرم مشکی از جنس مشکی مناسب اداری' === (string) $rendered['text'] );
ok( 'no missing keys', ! $rendered['missing'] );
$miss = Haa_Module_Programmatic::render( '{{title}} با {{weight}} گرم', $rows[0] );
ok( 'unknown placeholder reported', array( 'weight' ) === $miss['missing'] );

ok( 'item with one field refused', is_wp_error( Haa_Module_Programmatic::item_ok( array( 'title' => 'تک‌فیلد' ) ) ) );
ok( 'item with two fields passes', true === Haa_Module_Programmatic::item_ok( array( 'title' => 'ت', 'color' => 'مشکی' ) ) );
ok( 'titleless item refused', is_wp_error( Haa_Module_Programmatic::item_ok( array( 'color' => 'مشکی', 'use' => 'اداری' ) ) ) );

ok( 'identical text is fully similar', Haa_Module_Programmatic::similarity( 'کفش چرم مشکی اداری', 'کفش چرم مشکی اداری' ) >= 0.99, (string) Haa_Module_Programmatic::similarity( 'کفش چرم مشکی اداری', 'کفش چرم مشکی اداری' ) );
ok( 'disjoint text is low', Haa_Module_Programmatic::similarity( 'کفش چرم مشکی', 'سیب قرمز تازه باغ' ) < 0.2 );
ok( 'partial overlap mid-range', Haa_Module_Programmatic::similarity( 'کفش چرم مشکی اداری راحت', 'کفش چرم قهوه‌ای اداری راحت' ) > 0.3 );

section_set(
	'programmatic',
	array(
		'enabled'           => 1,
		'source'            => 'csv',
		'template'          => '{{title}} برای استفاده‌ی {{use}}.',
		'title_template'    => '{{title}} | فروشگاه',
		'min_words'         => 200,
		'similar_threshold' => 0.6,
		'daily_cap'         => 10,
		'noindex'           => 1,
		'default_status'    => 'draft',
	)
);

// Two genuinely different items → two pages. Different replies per call, because
// feeding the same text back would trip the duplicate guard for the wrong reason.
$GLOBALS['api_replies'] = array(
	str_repeat( 'کفش چرم مشکی اداری با زیره‌ی لاستیک و دوخت انگشتی برای روز طولانی ساخته شده است. ', 12 ),
	str_repeat( 'کیف دوشی چرم روزمره با بنده‌ی تنظیم‌شونده و یراق ضدزنگ ارائه می‌شود. ', 12 ),
	str_repeat( 'ردیف بی‌داده فقط یک فیلد دارد و صفحه نمی‌شود. ', 12 ),
);
$made = Haa_Module_Programmatic::run( $rows );
ok( 'two usable items made two pages', 2 === (int) $made['made'], (string) $made['made'] );
ok( 'the one-field row was refused', 1 === (int) $made['skipped'], (string) $made['skipped'] );
ok( 'refusal explains the thin item', (bool) array_filter( $made['reasons'], function ( $x ) { return false !== strpos( (string) $x, 'داده' ); } ), implode( '|', $made['reasons'] ) );
$made_ids = array_keys( $GLOBALS['written'] );
ok( 'programmatic pages are created as pages', 'page' === (string) ( $GLOBALS['written'][ $made_ids[0] ]['post_type'] ?? '' ), (string) ( $GLOBALS['written'][ $made_ids[0] ]['post_type'] ?? '' ) );
ok( 'created as a draft, not live', 'draft' === (string) ( $GLOBALS['written'][ $made_ids[0] ]['post_status'] ?? '' ), (string) ( $GLOBALS['written'][ $made_ids[0] ]['post_status'] ?? '' ) );

// Now the duplicate item: same data → same paragraph → refused.
d4_reset();
Haa_API::$reply = str_repeat( 'این مدل خاص با زیره‌ی لاستیک و دوخت انگشتی برای روزهای اداری ساخته شده است. ', 20 );
$dupes = Haa_Module_Programmatic::parse_csv( "title,color,use\nکفش چرم مشکی,مشکی,اداری\nکفش چرم مشکی ۲,مشکی,اداری\n" );
$d = Haa_Module_Programmatic::run( $dupes );
ok( 'first of the pair built', 1 === (int) $d['made'] );
ok( 'near-identical second one refused', 1 === (int) $d['skipped'], (string) $d['skipped'] );
ok( 'refusal explains similarity', (bool) array_filter( $d['reasons'], function ( $x ) { return false !== strpos( (string) $x, 'شبیه' ); } ), implode( '|', $d['reasons'] ) );

// Missing placeholder refuses the item instead of shipping "{{color}}".
d4_reset();
$GLOBALS['api_replies'] = array( str_repeat( 'متن یکتا درباره‌ی همین محصول با ویژگی‌های مشخص آن. ', 20 ) );
section_set( 'programmatic', array( 'template' => '{{title}} برای {{use}}', 'title_template' => '{{title}}' ) );
$miss_items = Haa_Module_Programmatic::parse_csv( "title,color,use\nکفش,مشکی,اداری\nکیف,قهوه,\n" );
$m = Haa_Module_Programmatic::run( $miss_items );
ok( 'complete item built', 1 === (int) $m['made'], (string) $m['made'] . ' / ' . implode( '|', $m['reasons'] ) );
ok( 'item missing a template key skipped', 1 === (int) $m['skipped'], (string) $m['skipped'] );
ok( 'template refusal explains', (bool) array_filter( $m['reasons'], function ( $x ) { return false !== strpos( (string) $x, 'پیدا نشد' ); } ), implode( '|', $m['reasons'] ) );

// noindex written by default, and flippable by a human click.
d4_reset();
Haa_API::$reply = str_repeat( 'توضیح اختصاصی برای این رنگ از چرم با کاربرد اداری. ', 20 );
$n = Haa_Module_Programmatic::run( Haa_Module_Programmatic::parse_csv( "title,color,use\nکفش اداری,مشکی,اداری\n" ) );
$new_id = 0;
foreach ( $GLOBALS['written'] as $wid => $row ) {
	$new_id = (int) $wid;
}
ok( 'page built for the noindex check', 1 === (int) $n['made'] && $new_id > 0 );
ok( 'noindex marker set by default', '1' === (string) get_post_meta( $new_id, '_haai_noindex', true ) );
$flip = Haa_Seo::set_robots( $new_id, false );
ok( 'human can allow indexing', '0' === (string) get_post_meta( $new_id, '_haai_noindex', true ) );
ok( 'set_robots reports the marker', true === $flip['marker'] );

// wp_robots filter honours the marker, only on singular views.
$pm     = new Haa_Module_Programmatic();
$GLOBALS['q'] = array( 'singular' => true, 'id' => $new_id );
update_post_meta( $new_id, '_haai_noindex', '1' );
$robots = $pm->filter_robots( array() );
ok( 'wp_robots marks the page noindex', 'noindex' === (string) ( $robots['index'] ?? '' ) );
ok( 'and still allows following', 'follow' === (string) ( $robots['follow'] ?? '' ) );
$GLOBALS['q'] = array( 'singular' => false );
ok( 'no front-end change on non-singular views', array() === $pm->filter_robots( array() ) );

// The unique-paragraph length guard: a lazy answer is refused, not published.
d4_reset();
section_set( 'programmatic', array( 'enabled' => 1, 'daily_cap' => 5, 'min_words' => 400, 'similar_threshold' => 0.95, 'title_template' => '{{title}}', 'template' => '{{use}}' ) );
$GLOBALS['api_replies'] = array( 'این فقط یک جمله است.' );
$lazy = Haa_Module_Programmatic::run( Haa_Module_Programmatic::parse_csv( "title,color,use\nکفش,مشکی,اداری\n" ) );
ok( 'short unique paragraph refused', 0 === (int) $lazy['made'] && 1 === (int) $lazy['skipped'] );
ok( 'length refusal quoted', (bool) array_filter( $lazy['reasons'], function ( $x ) { return false !== strpos( (string) $x, 'کوتاه ماند' ); } ), implode( '|', $lazy['reasons'] ) );
ok( 'nothing stored as done', ! (bool) array_filter( Haa_Module_Programmatic::rows( 10 ), function ( $r ) { return 'done' === $r['status']; } ) );

// Daily cap: a second run with the ceiling used up makes nothing.
d4_reset();
section_set( 'programmatic', array( 'enabled' => 1, 'daily_cap' => 1, 'min_words' => 200, 'similar_threshold' => 0.6, 'title_template' => '{{title}}', 'template' => '{{use}}' ) );
Haa_API::$reply = str_repeat( 'کلمه‌ی یکتا درباره‌ی همین مورد خاص برای جلوگیری از شباهت. ', 20 );
$c1 = Haa_Module_Programmatic::run( Haa_Module_Programmatic::parse_csv( "title,color,use\nالف,قرمز,اداری\nب,آبی,ورزشی\n" ) );
ok( 'first page within the cap built', 1 === (int) $c1['made'], (string) $c1['made'] );
$c2 = Haa_Module_Programmatic::run( Haa_Module_Programmatic::parse_csv( "title,color,use\nپ,سبز,مدارس\n" ) );
ok( 'cap stops further pages', 0 === (int) $c2['made'] );
ok( 'cap explained', (bool) array_filter( $c2['reasons'], function ( $x ) { return false !== strpos( (string) $x, 'سقف' ); } ) );

// Item key is stable, so a re-run updates rather than duplicating.
$key  = Haa_Module_Programmatic::item_key( array( 'title' => 'کفش چرم مشکی' ) );
$key2 = Haa_Module_Programmatic::item_key( array( 'title' => 'کفش چرم مشکی', 'color' => 'سبز' ) );
ok( 'item key depends on the title only', $key === $key2 && '' !== $key );
d4_reset();
section_set( 'programmatic', array( 'enabled' => 1, 'daily_cap' => 5, 'min_words' => 150, 'similar_threshold' => 0.95, 'title_template' => '{{title}}', 'template' => '{{use}}' ) );
Haa_API::$reply = str_repeat( 'متن یکتای اول. ', 40 );
Haa_Module_Programmatic::save_item( array( 'title' => 'یک مورد', 'use' => 'اداری' ), array( 'status' => 'done' ) );
$found = Haa_Module_Programmatic::find_item( Haa_Module_Programmatic::item_key( array( 'title' => 'یک مورد' ) ) );
ok( 'saved item is findable by key', $found );
Haa_Module_Programmatic::update_item( (int) $found['id'], array( 'status' => 'new' ) );
$again = Haa_Module_Programmatic::find_item( Haa_Module_Programmatic::item_key( array( 'title' => 'یک مورد' ) ) );
ok( 'status update round-trips', 'new' === (string) ( $again['status'] ?? '' ), (string) ( $again['status'] ?? 'null' ) );

// Hub page: refuses when there is nothing to list, builds a real list after.
d4_reset();
section_set( 'programmatic', array( 'enabled' => 1, 'daily_cap' => 5, 'min_words' => 150, 'similar_threshold' => 0.95, 'title_template' => '{{title}}', 'template' => '{{use}}', 'hub_page' => 0 ) );
// The hub must be a real stored page: build_hub() updates it through
// wp_update_post, which only works on a post the store knows about.
$hub_id = wp_insert_post( array( 'post_title' => 'راهنمای کامل', 'post_content' => '<p>مقدمه</p>', 'post_excerpt' => 'فهرست راهنماها', 'post_status' => 'publish', 'post_type' => 'page' ), true );
section_set( 'programmatic', array( 'hub_page' => (int) $hub_id ) );
ok( 'hub refuses with no children', is_wp_error( Haa_Module_Programmatic::build_hub( $hub_id ) ) );
ok( 'hub refuses on unknown page', is_wp_error( Haa_Module_Programmatic::build_hub( 99999 ) ) );
$GLOBALS['api_replies'] = array( str_repeat( 'توضیح اختصاصی برای این رنگ از چرم با کاربرد اداری روزانه. ', 20 ) );
$built = Haa_Module_Programmatic::run( Haa_Module_Programmatic::parse_csv( "title,color,use\nکفش مشکی,مشکی,اداری\n" ) );
ok( 'child page built before hub test', 1 === (int) $built['made'], (string) $built['made'] );
$hub = Haa_Module_Programmatic::build_hub( $hub_id );
ok( 'hub built after children exist', ! is_wp_error( $hub ), is_wp_error( $hub ) ? (string) $hub->get_error_message() : '' );
$hub_post = is_wp_error( $hub ) ? null : get_post( $hub_id );
ok( 'hub lists the child', $hub_post && false !== strpos( (string) $hub_post->post_content, 'کفش مشکی' ) );
ok( 'child links up to the hub', (bool) array_filter( $GLOBALS['written'], function ( $w ) use ( $hub_id ) { return false !== strpos( (string) $w['post_content'], get_permalink( $hub_id ) ); } ) );

/* =====================================================================
 * H. Monthly refresh
 * ===================================================================== */
echo "=== H. refresh ===\n";
d4_reset();
section_set( 'article', array( 'refresh_enabled' => 1, 'refresh_days' => 90, 'daily_token_cap' => 0, 'daily_article_cap' => 0 ) );

ok( 'refresh needs the module too', Haa_Refresh::enabled() );
$s = Haa_Settings::get();
$s['modules']['article-writer'] = 0;
update_option( HAAI_OPTION, $s, 'no' );
update_option( Haa_Settings::MODULES_OPTION, array( 'chatbot' => 1, 'news' => 1 ), 'yes' );
Haa_Settings::flush_cache();
ok( 'refresh off when article-writer is off', ! Haa_Refresh::enabled() );
module_on( 'article-writer' );
section_set( 'article', array( 'refresh_enabled' => 1, 'refresh_days' => 90 ) );

ok( 'window clamped to at least 30 days', 90 === Haa_Refresh::days() );
section_set( 'article', array( 'refresh_days' => 1 ) );
ok( 'window floor is 30', 30 === Haa_Refresh::days() );
section_set( 'article', array( 'refresh_days' => 9999 ) );
ok( 'window ceiling is 365', 365 === Haa_Refresh::days() );
section_set( 'article', array( 'refresh_days' => 90 ) );

// Selection: old vs recent.
$GLOBALS['posts'] = array();
$old_id = wp_insert_post( array( 'post_title' => 'مقاله‌ی قدیمی', 'post_content' => '<p>متن</p>', 'post_status' => 'publish' ), true );
$new_id = wp_insert_post( array( 'post_title' => 'مقاله‌ی تازه', 'post_content' => '<p>متن</p>', 'post_status' => 'publish' ), true );
update_post_meta( $old_id, '_haai_generated', '1' );
update_post_meta( $new_id, '_haai_generated', '1' );
$GLOBALS['modified'] = array(
	$old_id => time() - 200 * DAY_IN_SECONDS,
	$new_id => time() - 5 * DAY_IN_SECONDS,
);
$GLOBALS['get_posts_returns'] = 'generated';
$GLOBALS['posts'] = array(
	array( 'id' => $old_id, 'title' => 'مقاله‌ی قدیمی', 'type' => 'post', 'status' => 'publish' ),
	array( 'id' => $new_id, 'title' => 'مقاله‌ی تازه', 'type' => 'post', 'status' => 'publish' ),
);
$due = Haa_Refresh::due();
ok( 'old post is due', in_array( $old_id, $due, true ), implode( ',', $due ) );
ok( 'recent post not due', ! in_array( $new_id, $due, true ) );

// Staleness wording.
$found = Haa_Refresh::staleness( '<p>امسال بهترین قیمت را داریم و اخیراً جدیدترین مدل آمد.</p>', get_post( $old_id ) );
ok( 'امسال flagged', (bool) array_filter( $found, function ( $f ) { return false !== strpos( (string) $f['text'], 'امسال' ); } ) );
ok( 'اخیراً flagged', (bool) array_filter( $found, function ( $f ) { return false !== strpos( (string) $f['text'], 'اخیراً' ) ; } ) );
ok( 'جدیدترین flagged', (bool) array_filter( $found, function ( $f ) { return false !== strpos( (string) $f['text'], 'جدیدترین' ); } ) );
ok( 'clean text has nothing to report', ! Haa_Refresh::staleness( '<p>زیره‌ی این کفش لاستیک است.</p>', get_post( $old_id ) ) );
ok( 'old year flagged', (bool) array_filter( Haa_Refresh::staleness( '<p>مدل 2015 هنوز فروش دارد.</p>', get_post( $old_id ) ), function ( $f ) { return '2015' === (string) $f['text']; } ) );

// Broken internal link: target gone, or trashed.
$dead = wp_insert_post( array( 'post_title' => 'ذوف', 'post_content' => '<p>x</p>', 'post_status' => 'publish' ), true );
update_post_meta( $dead, '_haai_generated', '1' );
// Actually put the target in the trash; merely adding a second row with the same
// id would not change what get_post() resolves.
wp_update_post( array( 'ID' => $dead, 'post_status' => 'trash' ) );
// The map matches on a substring of the URL: the permalinks here are
// "?p=<name>", so the key must not carry a leading slash.
$GLOBALS['url2post_map'] = array(
	'gone-post'  => 0,
	'trashed'    => $dead,
);
$art = wp_insert_post(
	array(
		'post_title'   => 'راهنما',
		'post_status'  => 'publish',
		'post_content' => '<p>بخوانید <a href="http://local.test/?p=gone-post">این را</a> و <a href="http://local.test/?p=trashed">آن را</a>.</p>',
	),
	true
);
$rep = Haa_Refresh::inspect( $art, false );
ok( 'unresolved internal link found', 1 === count( $rep['broken'] ) || 2 === count( $rep['broken'] ), (string) count( $rep['broken'] ) );
ok( 'trashed target reported', (bool) array_filter( $rep['broken'], function ( $b ) { return false !== strpos( (string) $b['reason'], 'حذف' ); } ) );
ok( 'age in days computed', $rep['age_days'] >= 0 );

// External links: 404 and refusal, without ever touching a private address.
d4_reset();
$ext = wp_insert_post(
	array(
		'post_title'   => 'لینک بیرونی',
		'post_status'  => 'publish',
		'post_content' => '<p><a href="https://8.8.8.8/ok">ok</a> <a href="https://8.8.8.8/gone">gone</a> <a href="http://169.254.169.254/meta">meta</a></p>',
	),
	true
);
http_mock( '8.8.8.8/ok', array( 'code' => 200, 'body' => '' ) );
http_mock( '8.8.8.8/gone', array( 'code' => 404, 'body' => '' ) );
$rep2 = Haa_Refresh::inspect( $ext, true );
ok( 'healthy external not flagged', ! (bool) array_filter( $rep2['broken'], function ( $b ) { return false !== strpos( (string) $b['url'], '/ok' ); } ) );
ok( '404 external flagged', (bool) array_filter( $rep2['broken'], function ( $b ) { return false !== strpos( (string) $b['url'], '/gone' ); } ) );
$heads = implode( ',', $GLOBALS['head_log'] );
ok( 'metadata address never requested', false === strpos( $heads, '169.254' ), $heads );

// Applying a fix: unwrap the anchor, keep the label, record a revision.
d4_reset();
$keep = wp_insert_post(
	array(
		'post_title'   => 'متن با لینک',
		'post_status'  => 'publish',
		'post_content' => '<p>متن قبل <a href="http://local.test/?p=gone-post">عنوان لینک</a> متن بعد.</p>',
	),
	true
);
$before = (string) get_post( $keep )->post_content;
ok( 'drop_link applies', true === Haa_Refresh::drop_link( $keep, 'http://local.test/?p=gone-post' ) );
$after = (string) get_post( $keep )->post_content;
ok( 'anchor removed', false === strpos( $after, 'gone-post' ) );
ok( 'link label kept so the text length survives', false !== strpos( $after, 'عنوان لینک' ) );
ok( 'surrounding words untouched', false !== strpos( $after, 'متن قبل' ) && false !== strpos( $after, 'متن بعد' ) );
ok( 'update recorded a revision', haai_test_revisions( $keep ) > 0 );
ok( 'report re-scanned after the fix', (function () use ( $keep ) {
	$rep = (array) get_post_meta( $keep, Haa_Refresh::META, true );
	foreach ( (array) ( $rep['broken'] ?? array() ) as $b ) {
		if ( false !== strpos( (string) $b['url'], 'gone-post' ) ) {
			return false;
		}
	}
	return true;
})() );
ok( 'dropping a link that is not there is refused', is_wp_error( Haa_Refresh::drop_link( $keep, 'http://local.test/?p=never' ) ) );
ok( 'drop on missing post refused', is_wp_error( Haa_Refresh::drop_link( 999999, 'x' ) ) );

// Scan + dismissal + summary.
d4_reset();
$sc = wp_insert_post(
	array(
		'post_title'   => 'قدیمیِ نیازمند توجه',
		'post_status'  => 'publish',
		'post_content' => '<p>امسال جدیدترین مدل را داریم با <a href="http://local.test/?p=gone-post">لینک</a>.</p>',
	),
	true
);
update_post_meta( $sc, '_haai_generated', '1' );
$GLOBALS['modified']    = array( $sc => time() - 300 * DAY_IN_SECONDS );
$GLOBALS['url2post_map'] = array( 'gone-post' => 0 );
$scan = Haa_Refresh::scan( false );
ok( 'scan found the one due post', 1 === (int) $scan['scanned'], (string) $scan['scanned'] );
ok( 'scan says it needs attention', 1 === (int) $scan['needing'] );
$stored = (array) get_post_meta( $sc, Haa_Refresh::META, true );
ok( 'report stored as post meta', ! empty( $stored ) );
ok( 'empty() of the report is clean=0', empty( $stored['clean'] ) );
ok( 'summary mentions the broken link', false !== strpos( Haa_Refresh::summary( $stored ), 'لینک' ) );
ok( 'summary mentions freshness', false !== strpos( Haa_Refresh::summary( $stored ), 'تازگی' ) );
ok( 'needing() lists it', (bool) array_filter( Haa_Refresh::needing(), function ( $r ) use ( $sc ) { return (int) $r['post']->ID === (int) $sc; } ) );

$url = (string) ( $stored['broken'][0]['url'] ?? '' );
ok( 'dismiss works', true === Haa_Refresh::dismiss( $sc, $url ) );
$after2 = (array) get_post_meta( $sc, Haa_Refresh::META, true );
ok( 'dismissed entry gone', ! $after2['broken'] );
ok( 'dismiss refused without a report', false === Haa_Refresh::dismiss( 999999, 'x' ) );

// A clean post is recorded as clean, not as "needs attention".
d4_reset();
$fine = wp_insert_post(
	array(
		'post_title'   => 'سالم',
		'post_status'  => 'publish',
		'post_content' => '<p>زیره لاستیک دارد و دوخت آن چرم است.</p>',
	),
	true
);
update_post_meta( $fine, '_haai_generated', '1' );
$GLOBALS['posts']        = array( array( 'id' => $fine, 'title' => 'سالم', 'type' => 'post', 'status' => 'publish' ) );
$GLOBALS['modified']     = array( $fine => time() - 300 * DAY_IN_SECONDS );
$scan_clean = Haa_Refresh::scan( false );
ok( 'clean post scanned', 1 === (int) $scan_clean['scanned'] );
ok( 'clean post needs nothing', 0 === (int) $scan_clean['needing'] );
$clean_report = (array) get_post_meta( $fine, Haa_Refresh::META, true );
ok( 'clean verdict stored', ! empty( $clean_report['clean'] ) );
ok( 'clean summary says so', false !== strpos( Haa_Refresh::summary( $clean_report ), 'مشکلی پیدا نشد' ) );
ok( 'needing() skips the clean post', ! (bool) array_filter( Haa_Refresh::needing(), function ( $r ) use ( $fine ) { return (int) $r['post']->ID === (int) $fine; } ) );

// Cron wiring exists only while the setting is on.
$scheduled = array();
foreach ( (array) ( $GLOBALS['hooks']['action']['haai_refresh_tick'] ?? array() ) as $cb ) {
	$scheduled[] = 1;
}
$aw_src = (string) file_get_contents( HAAI_DEV_ROOT . 'modules/class-haa-module-article-writer.php' );
ok( 'article-writer hooks the refresh cron', false !== strpos( $aw_src, "Haa_Refresh::CRON" ) );
ok( 'cron scheduled only when enabled', false !== strpos( $aw_src, 'if ( Haa_Refresh::enabled() )' ) );
ok( 'deactivating unschedules it', false !== strpos( $aw_src, 'Haa_Refresh::unschedule()' ) );
ok( 'setting flip reschedules without a reload', false !== strpos( $aw_src, "add_action( 'update_option_'" ) && false !== strpos( $aw_src, 'HAAI_OPTION' ) );

// Front-end neutrality: the new modules must not touch visitor pages, with the
// single documented exception of the programmatic noindex filter.
foreach ( array( 'news', 'video-article', 'programmatic' ) as $slug ) {
	$src = (string) file_get_contents( HAAI_DEV_ROOT . 'modules/class-haa-module-' . $slug . '.php' );
	ok( $slug . ': no front-end asset hooks', false === strpos( $src, 'wp_enqueue_scripts' ) && false === strpos( $src, 'wp_footer' ) );
	ok( $slug . ': no nopriv entry point', false === strpos( $src, 'nopriv' ) );
	ok( $slug . ': no REST route', false === strpos( $src, 'register_rest_route' ) );
	ok( $slug . ': handlers guard the nonce', false !== strpos( $src, 'guard_admin_post' ) );
}
$prog = (string) file_get_contents( HAAI_DEV_ROOT . 'modules/class-haa-module-programmatic.php' );
ok( 'programmatic wp_robots is gated by the module', false !== strpos( $prog, 'if ( ! self::enabled() || ! is_singular() )' ) );

echo "\nFAILURES: $fail\n";
exit( $fail ? 1 : 0 );
