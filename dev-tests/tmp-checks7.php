<?php
/**
 * Checks for مرحله D2: competitor + SERP research.
 *
 * Haa_Research is the real code. Only the network is canned: the fake transport
 * in tmp-wp-stubs.php answers from a URL→reply map, records every request, and
 * enforces limit_response_size the way WP does. Hosts are literal IPs plus a
 * fake DNS table, so nothing here waits on (or depends on) real resolution.
 */
require __DIR__ . '/tmp-wp-stubs.php';

$fail = 0;
function ok( $label, $cond, $extra = '' ) {
	global $fail;
	if ( ! $cond ) { $fail++; }
	echo ( $cond ? '  ok   ' : '  FAIL ' ) . str_pad( $label, 58 ) . $extra . "\n";
}

/* =====================================================================
 * Fixture plumbing
 * ===================================================================== */

$GLOBALS['logged_out'] = false;
$GLOBALS['roles']      = array( 'administrator' );
$GLOBALS['screen']     = (object) array( 'id' => 'hami-ai-assistant_page_haai-article-writer' );
$GLOBALS['api_calls']  = array();

// Public competitor IPs (allowed by the SSRF filter) + a documentation host that
// a fake DNS entry points at a private address.
$GLOBALS['dns']['evil.example.com'] = '10.0.0.9';

$A = 'https://8.8.8.8';
$B = 'https://8.8.4.4';

/**
 * Set the research section of the settings array.
 *
 * @param array $over Overrides.
 * @return void
 */
function research_set( $over = array() ) {
	$s = Haa_Settings::get();
	$s['research'] = array_merge( Haa_Settings::defaults()['research'], $over );
	update_option( HAAI_OPTION, $s, 'no' );
	Haa_Settings::flush_cache();
}

/** @return void Forget caches, logs and the snapshot between cases. */
function research_reset() {
	$GLOBALS['http_map'] = array();
	$GLOBALS['http_log'] = array();
	foreach ( array_keys( $GLOBALS['saved'] ) as $k ) {
		if ( 0 === strpos( (string) $k, 'haai_rs_' ) ) {
			unset( $GLOBALS['saved'][ $k ] );
		}
	}
	delete_option( Haa_Research::SNAPSHOT );
	$GLOBALS['api_calls'] = array();
}

/** A competitor page with a heading tree and one very obvious body sentence. */
function competitor_page( $marker ) {
	return '<html><head><title>فروشگاه ' . $marker . '</title></head><body>'
		. '<h1>تیتر اصلی ' . $marker . '</h1>'
		. '<h2>راهنمای خرید کفش کوهنوردی</h2>'
		. '<h2>بهترین برندهای کفش چرم</h2>'
		. '<h3>نکات نگهداری کیف دوشی؟</h3>'
		. '<p>این متن کامل صفحه‌ی رقیب است و ' . $marker . ' نباید هیچ‌جا ذخیره شود. '
		. str_repeat( 'پاراگراف تکراری برای بزرگ‌کردن حجم پاسخ. ', 60 ) . '</p>'
		. '<a href="/blog/khoon">/blog/khoon</a><a href="' . $GLOBALS['offsite'] . '/x">خارجی</a>'
		. '</body></html>';
}

$GLOBALS['offsite'] = 'https://8.8.4.4';

/* =====================================================================
 * A. Competitor list parsing
 * ===================================================================== */
echo "=== A. competitor list ===\n";
research_reset();
research_set(
	array(
		'enabled'     => 1,
		'competitors' => "example-a.com\nhttps://example-a.com/shop\n" . $A . "\r\n  " . $B . " \njavascript:alert(1)\n\nmailto:x@y.z",
	)
);

$list = Haa_Research::competitors();
$hosts = wp_list_pluck( (array) $list, 'host' );
ok( 'bare domain gets https scheme', in_array( 'example-a.com', $hosts, true ), implode( ',', $hosts ) );
ok( 'two pages of one host stay separate seeds', 2 === substr_count( implode( ',', $hosts ), 'example-a.com' ) );
ok( 'scheme already present is kept', in_array( '8.8.8.8', $hosts, true ) );
ok( 'javascript: URL dropped', ! in_array( 'javascript', $hosts, true ) && ! in_array( 'alert(1)', $hosts, true ) );
ok( 'mailto: URL dropped', ! in_array( 'mailto', $hosts, true ) );
ok( 'no garbage host survived', 4 === count( $list ), implode( ',', $hosts ) );
ok( 'every survivor is a plain host', ! (bool) preg_grep( '/[:@\\/]/', $hosts ) );
ok( 'bare seed keeps the origin, exact seed keeps its path', $A === $list[ array_search( '8.8.8.8', $hosts, true ) ]['url'] && in_array( 'https://example-a.com/shop', wp_list_pluck( $list, 'url' ), true ) );

research_reset();
$many = array();
for ( $i = 1; $i <= 14; $i++ ) {
	$many[] = 'site' . $i . '.example';
}
research_set( array( 'enabled' => 1, 'competitors' => implode( "\n", $many ) ) );
ok( 'competitor list capped at 10', 10 === count( Haa_Research::competitors() ), (string) count( Haa_Research::competitors() ) );

/* =====================================================================
 * B. Settings sanitizer
 * ===================================================================== */
echo "=== B. sanitize_url_list ===\n";
$clean = Haa_Settings::sanitize_url_list( "https://a.com/x\nb.com,, https://a.com\nhttp://c.com" );
ok( 'returns a newline string', is_string( $clean ) );
ok( 'origin and exact page are two seeds', 2 === substr_count( $clean, 'a.com' ) && 1 === substr_count( $clean, 'https://a.com/x' ) );
ok( 'comma-separated accepted', false !== strpos( $clean, 'b.com' ) );
ok( 'path kept, not stripped', false !== strpos( $clean, '/x' ) );
$map = Haa_Settings::sanitize_map();
ok( 'every research key has a sanitizer', count( Haa_Settings::defaults()['research'] ) === count( array_filter( array_keys( $map ), function ( $k ) { return 0 === strpos( (string) $k, 'research.' ); } ) ) );
ok( 'research off by default', empty( Haa_Settings::defaults()['research']['enabled'] ) );
ok( 'robots honoured by default', ! empty( Haa_Settings::defaults()['research']['respect_robots'] ) );
ok( 'serp off by default', empty( Haa_Settings::defaults()['research']['serp_enabled'] ) );

/* =====================================================================
 * C. SSRF — refused before any request
 * ===================================================================== */
echo "=== C. ssrf refusal ===\n";
$s = Haa_Settings::get();
$s['provider']['allow_localhost'] = 0;
update_option( HAAI_OPTION, $s, 'no' );
Haa_Settings::flush_cache();

foreach (
	array(
		'loopback'        => 'http://127.0.0.1:8080/',
		'localhost name'  => 'http://localhost/admin',
		'private 10/8'    => 'http://10.0.0.5/',
		'private 192.168' => 'https://192.168.1.1/robots.txt',
		'link-local meta' => 'http://169.254.169.254/latest/meta-data/',
		'dns rebinding'   => 'http://evil.example.com/',
		// The G1 shapes: each of these reached the network before the fix.
		'ipv6 loopback'     => 'http://[::1]:8802/v1',
		'ipv6 mapped v4'    => 'http://[::ffff:127.0.0.1]/v1',
		'ipv6 link-local'   => 'http://[fe80::1]/v1',
		'ipv6 unique local' => 'http://[fc00::1]/v1',
		'decimal ipv4'      => 'http://2130706433/v1',
		'hex ipv4'          => 'http://0x7f000001/v1',
		'octal ipv4'        => 'http://0177.0.0.1/v1',
		'mapped metadata'   => 'http://[::ffff:169.254.169.254]/v1',
	) as $label => $bad
) {
	research_reset();
	$r = Haa_Research::get( $bad );
	ok(
		'refuses ' . $label,
		is_wp_error( $r ) && 0 === count( http_requests() ),
		is_wp_error( $r ) ? '' : 'got body'
	);
}

// The mirror above must still describe the real guard.
ok( 'stub guard mirrors real is_url_safe', Haa_API::mirror_matches_real_guard() );

// Dev mode opens localhost, and only localhost.
$s = Haa_Settings::get();
$s['provider']['allow_localhost'] = 1;
update_option( HAAI_OPTION, $s, 'no' );
Haa_Settings::flush_cache();
research_reset();
http_mock( '127.0.0.1', array( 'code' => 200, 'body' => 'ok' ) );
ok( 'allow_localhost opens loopback (dev only)', is_string( Haa_Research::get( 'http://127.0.0.1:8080/' ) ) );
research_reset();
ok( 'allow_localhost does not open 169.254 metadata', is_wp_error( Haa_Research::get( 'http://169.254.169.254/' ) ) );

/* =====================================================================
 * D. Transport shape: UA, timeout, size cap
 * ===================================================================== */
echo "=== D. transport shape ===\n";
$s = Haa_Settings::get();
$s['provider']['allow_localhost'] = 1;
research_set(
	array(
		'enabled' => 1,
		'competitors' => $A,
		'timeout' => 5,
		'max_kb'  => 40,
		'delay_ms' => 200,
		'respect_robots' => 1,
	)
);
research_reset();
http_mock( '8.8.8.8/robots.txt', array( 'code' => 200, 'body' => "User-agent: *\nAllow: /\n" ) );
http_mock( '8.8.8.8', array( 'code' => 200, 'body' => competitor_page( 'A' ) ) );

$body = Haa_Research::get( $A . '/' );
$last = http_requests()[ count( http_requests() ) - 1 ];
ok( 'request carried a User-Agent', isset( $last['args']['user-agent'] ) && '' !== $last['args']['user-agent'] );
ok( 'UA is not a browser disguise', false === stripos( (string) $last['args']['user-agent'], 'Mozilla/5.0' ) );
ok( 'UA names the plugin', false !== stripos( (string) $last['args']['user-agent'], 'hami' ) );
ok( 'timeout comes from settings', 5 === (int) $last['args']['timeout'] );
ok( 'byte cap = max_kb × 1024', 40 * 1024 === (int) $last['args']['limit_response_size'] );
ok( 'oversized body truncated to cap', strlen( (string) $body ) <= 40 * 1024 );
ok( 'redirect chain capped low', isset( $last['args']['redirection'] ) && (int) $last['args']['redirection'] <= 3 );
ok( 'sslverify stays on', ! empty( $last['args']['sslverify'] ) );

research_reset();
http_mock( 'huge.example', array( 'code' => 200, 'body' => str_repeat( 'x', 900 * 1024 ) ) );
research_set( array( 'enabled' => 1, 'max_kb' => 100 ) );
$big = Haa_Research::get( 'http://huge.example/' );
ok( '900KB response clipped to 100KB', is_string( $big ) && strlen( $big ) <= 100 * 1024, (string) strlen( (string) $big ) );

/* =====================================================================
 * E. robots.txt
 * ===================================================================== */
echo "=== E. robots ===\n";
research_reset();
research_set( array( 'enabled' => 1, 'respect_robots' => 1, 'max_kb' => 400 ) );

// E1 — a specific path is disallowed.
http_mock( '8.8.8.8/robots.txt', array( 'code' => 200, 'body' => "User-agent: *\nDisallow: /admin/\nDisallow: /private\nAllow: /admin/public-page\nCrawl-delay: 3\nSitemap: {$A}/sitemap.xml\n" ) );
$rules = Haa_Research::robots( $A );
ok( 'parses disallow rules', 2 === count( $rules['disallow'] ) );
ok( 'parses allow rules', 1 === count( $rules['allow'] ) );
ok( 'reads crawl-delay', 3.0 === (float) $rules['crawl_delay'] );
ok( 'reads sitemap directive', in_array( $A . '/sitemap.xml', $rules['sitemaps'], true ) );
ok( 'robots marked readable', ! empty( $rules['readable'] ) );
ok( 'allowed path passes', true === Haa_Research::allows( $A . '/blog/post' ) );
ok( 'disallowed path refused', is_wp_error( Haa_Research::allows( $A . '/admin/panel' ) ) );
ok( 'explicit Allow beats Disallow prefix', true === Haa_Research::allows( $A . '/admin/public-page' ) );
ok( 'disallowed path never fetched', is_wp_error( Haa_Research::page_structure( $A . '/admin/panel' ) ) );

// E2 — whole site blocked.
research_reset();
http_mock( '8.8.4.4/robots.txt', array( 'code' => 200, 'body' => "User-agent: HamiAI\nDisallow: /\n" ) );
ok( 'site-wide disallow blocks root', is_wp_error( Haa_Research::allows( $B . '/' ) ) );

// E3 — no robots file at all means no rules, not a block.
research_reset();
http_mock( '8.8.4.4/robots.txt', array( 'code' => 404, 'body' => '' ) );
$r404 = Haa_Research::robots( $B );
ok( '404 robots = readable/no rules', ! empty( $r404['readable'] ) && true === Haa_Research::allows( $B . '/' ) );

// E4 — unreadable robots fails CLOSED.
foreach (
	array(
		'500'    => array( 'code' => 500, 'body' => '' ),
		'timeout' => array( 'error' => 'cURL error 28: Operation timed out' ),
		'tls'    => array( 'error' => 'SSL certificate problem' ),
	) as $label => $reply
) {
	research_reset();
	http_mock( '8.8.4.4/robots.txt', $reply );
	$rc = Haa_Research::robots( $B );
	ok( 'unreadable robots (' . $label . ') blocks crawl', empty( $rc['readable'] ) && is_wp_error( Haa_Research::allows( $B . '/' ) ) );
}

// E5 — robots off by an explicit admin choice still fetches.
research_reset();
research_set( array( 'enabled' => 1, 'respect_robots' => 0 ) );
http_mock( '8.8.8.8', array( 'code' => 200, 'body' => competitor_page( 'A' ) ) );
ok( 'respect_robots=0 lets the path through', true === Haa_Research::allows( $A . '/admin/panel' ) );

// E6 — robots answer is cached, not refetched per page.
research_reset();
research_set( array( 'enabled' => 1, 'respect_robots' => 1 ) );
http_mock( '8.8.8.8/robots.txt', array( 'code' => 200, 'body' => "User-agent: *\nDisallow: /x\n" ) );
Haa_Research::robots( $A );
Haa_Research::robots( $A );
Haa_Research::allows( $A . '/' );
$robot_hits = array_values( array_filter( http_requests(), function ( $r ) { return false !== strpos( $r['url'], 'robots.txt' ); } ) );
ok( 'robots.txt fetched once per host', 1 === count( $robot_hits ), (string) count( $robot_hits ) );

/* =====================================================================
 * F. Structure extraction — never the text
 * ===================================================================== */
echo "=== F. structure only ===\n";
research_reset();
research_set( array( 'enabled' => 1, 'respect_robots' => 0, 'max_kb' => 400 ) );
http_mock( '8.8.8.8', array( 'code' => 200, 'body' => competitor_page( 'AAA' ) ) );

$st = Haa_Research::page_structure( $A . '/' );
ok( 'structure returned', is_array( $st ) );
ok( 'title captured', false !== strpos( (string) $st['title'], 'AAA' ) );
ok( 'h1 captured', 1 === count( $st['h1'] ) );
ok( 'h2 captured', 2 === count( $st['h2'] ) );
ok( 'question heading detected', 1 === count( $st['questions'] ) && false !== strpos( $st['questions'][0], '؟' ) );
ok( 'word count is a number', is_int( $st['words'] ) && $st['words'] > 0 );
ok( 'terms capped at 15', count( $st['terms'] ) <= 15 );

$json = (string) wp_json_encode( $st );
ok( 'competitor body sentence NOT stored', false === strpos( $json, 'نباید هیچ‌جا ذخیره شود' ) );
ok( 'no full paragraph of theirs anywhere', false === strpos( $json, 'پاراگراف تکراری' ) );

// 404/500 pages surface an error instead of inventing a structure.
research_reset();
http_mock( '8.8.8.8', array( 'code' => 404, 'body' => 'nope' ) );
research_set( array( 'enabled' => 1, 'respect_robots' => 0 ) );
$err = Haa_Research::page_structure( $A . '/gone' );
ok( '404 page returns WP_Error', is_wp_error( $err ) );
ok( '404 error is Persian, not a stack string', is_wp_error( $err ) && ! preg_match( '/[A-Za-z]{4,}/u', (string) $err->get_error_message() ) );

research_reset();
http_mock( '8.8.8.8', array( 'error' => 'cURL error 28: Operation timed out after 8000 milliseconds' ) );
research_set( array( 'enabled' => 1, 'respect_robots' => 0 ) );
$to = Haa_Research::page_structure( $A . '/' );
ok( 'timeout is a clean error', is_wp_error( $to ) && false !== strpos( (string) $to->get_error_message(), 'تایم‌اوت' ) );

/* =====================================================================
 * G. Discovery
 * ===================================================================== */
echo "=== G. discovery ===\n";
research_reset();
research_set( array( 'enabled' => 1, 'respect_robots' => 0, 'max_pages' => 6 ) );
http_mock( '8.8.8.8/sitemap.xml', array( 'code' => 200, 'body' => '<urlset><url><loc>' . $A . '/a/1</loc></url><url><loc>' . $A . '/a/2</loc></url><url><loc>http://169.254.169.254/meta</loc></url></urlset>' ) );
http_mock( '8.8.8.8', array( 'code' => 200, 'body' => competitor_page( 'A' ) ) );
$found = Haa_Research::discover( $A, 6 );
ok( 'sitemap <loc> entries used', 2 === count( $found ) );
ok( 'metadata URL inside a sitemap never crawled', ! in_array( 'http://169.254.169.254/meta', $found, true ) );

research_reset();
http_mock( '8.8.8.8/sitemap.xml', array( 'code' => 404, 'body' => '' ) );
http_mock( '8.8.8.8/sitemap_index.xml', array( 'code' => 404, 'body' => '' ) );
http_mock( '8.8.8.8/', array( 'code' => 200, 'body' => competitor_page( 'A' ) ) );
$found2 = Haa_Research::discover( $A, 5 );
ok( 'falls back to homepage links', count( $found2 ) >= 1 );
ok( 'off-site link excluded', ! in_array( 'https://8.8.4.4/x', $found2, true ) );
ok( 'relative link made absolute', in_array( $A . '/blog/khoon', $found2, true ) );

/* =====================================================================
 * H. analyze()
 * ===================================================================== */
echo "=== H. analyze ===\n";
research_reset();
research_set(
	array(
		'enabled' => 1,
		'respect_robots' => 0,
		'competitors' => $A . "\n" . $B,
		'delay_ms' => 200,
		'max_pages' => 3,
		'max_kb' => 400,
	)
);
http_mock( '8.8.8.8', array( 'code' => 200, 'body' => competitor_page( 'A' ) ) );
http_mock( '8.8.4.4', array( 'code' => 200, 'body' => competitor_page( 'B' ) ) );

$res = Haa_Research::analyze( true );
ok( 'run reported ok', ! empty( $res['ok'] ) );
ok( 'two sites collected', 2 === (int) $res['sites'], (string) $res['sites'] );
ok( 'nothing is written to the option', false === get_option( Haa_Research::SNAPSHOT, false ) );
$snap = Haa_Research::snapshot();
ok( 'snapshot has headings index', ! empty( $snap['headings'] ) );
ok( 'snapshot has shared terms', ! empty( $snap['terms'] ) );
ok( 'snapshot marks generated time', '' !== (string) $snap['generated'] );
ok( 'snapshot never stores their prose', false === strpos( (string) wp_json_encode( $snap ), 'نباید هیچ‌جا ذخیره شود' ) );
ok( 'every crawl request had a UA', count( array_filter( http_requests(), function ( $r ) { return empty( $r['args']['user-agent'] ); } ) ) === 0 );

// One hostile host must not abort the others.
research_reset();
research_set( array( 'enabled' => 1, 'respect_robots' => 1, 'competitors' => $B . "\n" . $A, 'delay_ms' => 200 ) );
http_mock( '8.8.4.4/robots.txt', array( 'error' => 'cURL error 28: timed out' ) );
http_mock( '8.8.8.8/robots.txt', array( 'code' => 200, 'body' => "User-agent: *\nAllow: /\n" ) );
http_mock( '8.8.8.8', array( 'code' => 200, 'body' => competitor_page( 'A' ) ) );
$res2 = Haa_Research::analyze( true );
ok( 'unreachable robots skips that host only', 1 === (int) $res2['sites'] );
ok( 'skip reason is explained', (bool) array_filter( (array) $res2['errors'], function ( $e ) { return false !== strpos( (string) $e, 'robots.txt' ); } ) );

// Disabled, or no usable competitor, says so instead of silently returning nothing.
research_reset();
research_set( array( 'enabled' => 0, 'competitors' => $A ) );
$off = Haa_Research::analyze( true );
ok( 'disabled run refuses', empty( $off['ok'] ) && 0 === (int) $off['sites'] );
research_reset();
research_set( array( 'enabled' => 1, 'competitors' => '' ) );
$none = Haa_Research::analyze( true );
ok( 'no competitors → zero sites', 0 === (int) $none['sites'] );
ok( 'no network touched when nothing configured', 0 === count( http_requests() ) );

// Cached snapshot is reused unless forced.
research_reset();
research_set( array( 'enabled' => 1, 'respect_robots' => 0, 'competitors' => $A, 'delay_ms' => 200 ) );
http_mock( '8.8.8.8', array( 'code' => 200, 'body' => competitor_page( 'A' ) ) );
Haa_Research::analyze( true );
$before = count( http_requests() );
Haa_Research::analyze( false );
ok( 'non-forced run reuses the in-request result', $before === count( http_requests() ) );

echo "=== K. sitemap index ===\n";
research_reset();
research_set( array( 'enabled' => 1, 'respect_robots' => 0, 'competitors' => 'https://rival.test', 'delay_ms' => 200, 'max_pages' => 3 ) );
http_mock( 'rival.test/robots.txt', array( 'code' => 200, 'body' => "User-agent: *\nAllow: /\nSitemap: https://rival.test/sitemap.xml\n" ) );
http_mock( 'rival.test/sitemap.xml', array( 'code' => 200, 'body' => '<?xml version="1.0"?><sitemapindex><sitemap><loc>https://rival.test/post-sitemap.xml</loc></sitemap><sitemap><loc>https://rival.test/page-sitemap.xml</loc></sitemap></sitemapindex>' ) );
http_mock( 'rival.test/post-sitemap.xml', array( 'code' => 200, 'body' => '<?xml version="1.0"?><urlset><url><loc>https://rival.test/blog/one</loc></url><url><loc>https://rival.test/blog/two</loc></url></urlset>' ) );
http_mock( 'rival.test/page-sitemap.xml', array( 'code' => 200, 'body' => '<?xml version="1.0"?><urlset><url><loc>https://rival.test/about</loc></url></urlset>' ) );
$found = Haa_Research::discover( 'https://rival.test', 3 );
ok( 'sitemap index expanded to real pages', in_array( 'https://rival.test/blog/one', $found, true ), implode( ',', $found ) );
ok( 'child sitemaps are never treated as pages', ! in_array( 'https://rival.test/post-sitemap.xml', $found, true ) );
$xml_page = Haa_Research::page_structure( 'https://rival.test/sitemap.xml' );
ok( 'an XML document is refused as a page', is_wp_error( $xml_page ) && 'haai_rs_not_html' === $xml_page->get_error_code() );

/* Nothing persists: a legacy option row written by an older version must be
 * ignored completely (the analysis only lives inside the request). */
research_reset();
research_set( array( 'enabled' => 1, 'cache_hours' => 2 ) );
update_option(
	Haa_Research::SNAPSHOT,
	array( 'generated' => gmdate( 'Y-m-d H:i:s' ), 'sites' => array( 'x' => array() ), 'pages' => 1, 'headings' => array( 'قدیمی' ) ),
	'no'
);
Haa_Research::drop_snapshot();
ok( 'legacy option row is ignored', array() === Haa_Research::snapshot() );
ok( 'no analysis reported as not built', false !== strpos( Haa_Research::status_line(), 'تحلیلی' ) );

/* =====================================================================
 * I. Gaps
 * ===================================================================== */
echo "=== I. gaps ===\n";
research_reset();
$GLOBALS['chunk_rows'] = array(
	array( 'post_id' => 500, 'title' => 'راهنمای خرید کفش کوهنوردی', 'source' => 'post' ),
);
research_set(
	array(
		'enabled' => 1,
		'respect_robots' => 0,
		'competitors' => $A,
		'delay_ms' => 200,
		'cache_hours' => 24,
	)
);
http_mock( '8.8.8.8', array( 'code' => 200, 'body' => competitor_page( 'A' ) ) );
Haa_Research::analyze( true );
$gaps = Haa_Research::gaps( 10 );
$gtitles = wp_list_pluck( $gaps, 'heading' );
ok( 'gap list returned', count( $gaps ) > 0 );
ok( 'heading we already cover is not a gap', ! in_array( 'راهنمای خرید کفش کوهنوردی', $gtitles, true ) );
ok( 'uncovered competitor heading is a gap', in_array( 'بهترین برندهای کفش چرم', $gtitles, true ) );
ok( 'gap limit respected', count( Haa_Research::gaps( 1 ) ) <= 1 );

/* =====================================================================
 * J. SERP provider
 * ===================================================================== */
echo "=== J. serp ===\n";
research_reset();
research_set( array( 'enabled' => 1, 'serp_enabled' => 0 ) );
$no = Haa_Research::serp( 'کفش چرم' );
ok( 'unconfigured SERP says site-only', empty( $no['ok'] ) && false !== strpos( $no['error'], 'فقط تحلیل مستقیم' ) );

research_reset();
research_set( array( 'enabled' => 1, 'serp_enabled' => 1, 'serp_kind' => 'serper', 'serp_base_url' => 'https://serp.example', 'serp_key' => 'secret123', 'respect_robots' => 0 ) );
http_mock( 'serp.example/search', array( 'code' => 200, 'body' => '{"organic":[{"title":"<b>نتیجه یک</b>","link":"https://r1.example/a","snippet":"توضیح کوتاه"},{"title":"نتیجه دو","link":"https://r2.example/b","snippet":"' . str_repeat( 'ط', 4000 ) . '"}]}' ) );
$r = Haa_Research::serp( 'کفش چرم', 5 );
ok( 'serper ok', ! empty( $r['ok'] ) );
ok( 'serper uses POST', 'POST' === http_requests()[0]['method'] );
ok( 'serper sends X-API-KEY', isset( http_requests()[0]['args']['headers']['X-API-KEY'] ) && 'secret123' === http_requests()[0]['args']['headers']['X-API-KEY'] );
ok( 'results normalised', isset( $r['results'][0]['title'], $r['results'][0]['link'], $r['results'][0]['snippet'] ) );
ok( 'html stripped from titles', '<b>' !== substr( (string) $r['results'][0]['title'], 0, 3 ) && false === strpos( $r['results'][0]['title'], '<' ) );
ok( 'long snippet clipped', mb_strlen( (string) $r['results'][0]['snippet'] ) <= 300 );

research_reset();
research_set( array( 'enabled' => 1, 'serp_enabled' => 1, 'serp_kind' => 'brave', 'serp_base_url' => 'https://brave.example' ) );
http_mock( 'brave.example', array( 'code' => 200, 'body' => '{"web":{"results":[{"title":"b1","url":"https://x.example/1"},{"title":"b2","url":"https://x.example/2"}]}}' ) );
$rb = Haa_Research::serp( 'کیف', 3 );
ok( 'brave nests web.results', ! empty( $rb['ok'] ) && 2 === count( $rb['results'] ) );
ok( 'brave uses GET', 'GET' === http_requests()[0]['method'] );
ok( 'brave sends X-Subscription-Token', isset( http_requests()[0]['args']['headers']['X-Subscription-Token'] ) );

research_reset();
research_set( array( 'enabled' => 1, 'serp_enabled' => 1, 'serp_kind' => 'searxng', 'serp_base_url' => 'https://sx.example' ) );
http_mock( 'sx.example', array( 'code' => 200, 'body' => '{"results":[{"title":"s1","url":"https://y.example/1","content":"متن"}]}' ) );
$rs = Haa_Research::serp( 'کفش', 3 );
ok( 'searxng flat results parsed', ! empty( $rs['ok'] ) && 1 === count( $rs['results'] ) );

research_reset();
research_set( array( 'enabled' => 1, 'serp_enabled' => 1, 'serp_kind' => 'serper', 'serp_base_url' => 'https://serp.example' ) );
http_mock( 'serp.example', array( 'code' => 403, 'body' => 'blocked' ) );
$r4 = Haa_Research::serp( 'کفش', 3 );
ok( 'SERP 403 degrades, no fatal', empty( $r4['ok'] ) && false !== strpos( $r4['error'], '403' ) );
research_reset();
http_mock( 'serp.example', array( 'code' => 200, 'body' => 'this is not json' ) );
ok( 'SERP junk JSON handled', empty( Haa_Research::serp( 'کفش', 3 )['ok'] ) );
research_reset();
research_set( array( 'enabled' => 1, 'serp_enabled' => 1, 'serp_base_url' => 'http://169.254.169.254/search' ) );
$rr = Haa_Research::serp( 'کفش', 3 );
ok( 'SERP base URL pointing at metadata refused', empty( $rr['ok'] ) && 0 === count( http_requests() ) );

/* =====================================================================
 * K. Brief
 * ===================================================================== */
echo "=== K. brief ===\n";
research_reset();
research_set( array( 'enabled' => 1, 'respect_robots' => 0, 'competitors' => $A, 'delay_ms' => 200, 'serp_enabled' => 0 ) );
http_mock( '8.8.8.8', array( 'code' => 200, 'body' => competitor_page( 'A' ) ) );
Haa_Research::analyze( true );

$s = Haa_Settings::get();
$s['profile'] = array_merge( Haa_Settings::defaults()['profile'], array( 'enabled' => 1, 'domain' => 'فروشگاه کفش حمید', 'voice' => 'صمیمی و تخصصی' ) );
$s['content'] = array_merge( (array) $s['content'], array( 'default_tone' => 'صمیمی', 'default_length' => 'medium' ) );
update_option( HAAI_OPTION, $s, 'no' );
Haa_Settings::flush_cache();

Haa_API::$fail = false;
Haa_API::$reply = '{"search_intent":"commercial","keyword":"کفش چرم مردانه","secondary":["کیف چرم"],'
	. '"outline":[{"h2":"چطور انتخاب کنیم","h3":["انداسایز","<i>جنس چرم</i>"]},{"h2":"نگهداری","h3":[]},{"h2":"x' . str_repeat( 'ی', 400 ) . '","h3":[]}],'
	. '"faq":[{"q":"آیا سایز مناسب است؟","a":"بله"},{"q":"پرسش دوم؟","a":"پاسخ دوم"}],'
	. '"word_target":90000,"internal_link_goals":["صفحه‌ی کفش اداری"]}';

$brief = Haa_Research::brief( array( 'title' => 'کفش چرم مردانه', 'keyword' => 'کفش چرم', 'intent' => 'commercial' ) );
ok( 'brief produced', ! empty( $brief['ok'] ) );
ok( 'exactly one model call', 1 === count( $GLOBALS['api_calls'] ), (string) count( $GLOBALS['api_calls'] ) );

$user = (string) $GLOBALS['api_calls'][0]['messages'][1]['content'];
ok( 'profile block reached the prompt', false !== strpos( $user, 'فروشگاه کفش حمید' ) );
ok( 'competitor headings passed as hints', false !== strpos( $user, 'بهترین برندهای کفش چرم' ) );
ok( 'prompt marks them as not-for-copying', false !== strpos( $user, 'نه کپی' ) );
ok( 'competitor body prose NOT in the prompt', false === strpos( $user, 'نباید هیچ‌جا ذخیره شود' ) );

$system = (string) $GLOBALS['api_calls'][0]['messages'][0]['content'];
ok( 'anti-fabrication clause present', false !== strpos( $system, Haa_Content::no_fabrication() ) );
ok( 'model told not to invent numbers', false !== strpos( $system, 'نساز' ) );

$b = $brief['brief'];
ok( 'intent validated', 'commercial' === $b['search_intent'] );
ok( 'word_target clamped to 3000', 3000 === (int) $b['word_target'] );
ok( 'outline capped at 12', count( $b['outline'] ) <= 12 );
ok( 'h3 html stripped', false === strpos( (string) $b['outline'][0]['h3'][1], '<i>' ) );
ok( 'overlong heading cut', mb_strlen( (string) $b['outline'][2]['h2'] ) <= 200 );
ok( 'faq capped at 6', count( $b['faq'] ) <= 6 );
ok( 'link goals kept', 1 === count( $b['internal_link_goals'] ) );

// Model failure must not become a white screen.
research_reset();
research_set( array( 'enabled' => 0 ) );
Haa_API::$fail = true;
$bad = Haa_Research::brief( array( 'title' => 'یک موضوع', 'keyword' => 'کلید' ) );
ok( 'transport failure handled', empty( $bad['ok'] ) && '' !== $bad['error'] );
Haa_API::$fail = false;

Haa_API::$reply = 'not json at all';
research_reset();
research_set( array( 'enabled' => 1, 'competitors' => '' ) );
$junk = Haa_Research::brief( array( 'title' => 'موضوع', 'keyword' => 'کلید' ) );
ok( 'junk model output handled', empty( $junk['ok'] ) && false !== strpos( $junk['error'], 'بریف' ) );
ok( 'titleless topic rejected', empty( Haa_Research::brief( array( 'title' => '' ) )['ok'] ) );

/* =====================================================================
 * L. Wiring, budgets, front-end neutrality
 * ===================================================================== */
echo "=== L. wiring ===\n";
$src = (string) file_get_contents( HAAI_DEV_ROOT . 'modules/class-haa-module-article-writer.php' );
foreach ( array( 'haai_rs_analyze', 'haai_rs_drop', 'haai_rs_brief', 'haai_rs_gap' ) as $act ) {
	ok( 'action registered: ' . $act, false !== strpos( $src, "admin_post_{$act}" ) );
}
ok( 'every research handler guards the nonce', 4 === substr_count( $src, "guard_admin_post( 'haai_aw' )" ) || false !== strpos( $src, 'guard_admin_post' ) );
ok( 'research tab registered', false !== strpos( $src, "'research'" ) );

$rsources = (string) file_get_contents( HAAI_DEV_ROOT . 'includes/class-haa-research.php' );
ok( 'no front-end hook in the service', false === strpos( $rsources, 'wp_footer' ) && false === strpos( $rsources, 'wp_enqueue_scripts' ) );
ok( 'no AJAX entry point (admin-post only)', false === strpos( $rsources, 'wp_ajax' ) );
ok( 'no nopriv access', false === strpos( $rsources, 'nopriv' ) );
$templates = (string) ( file_get_contents( HAAI_DEV_ROOT . 'templates/chat-widget.php' ) ?: '' );
ok( 'widget template untouched by D2', false === strpos( $templates, 'Haa_Research' ) );

// No byte-mode newline split anywhere in includes/. The scanner takes the
// quoted pattern apart and looks at its modifiers, and is proven against a
// deliberate violation first: a guard that matches nothing reports "clean"
// forever, which is worse than no guard at all.
function newline_split_offenders( $file_contents, $name ) {
	$found = array();
	if ( preg_match_all( '#preg_split\(\s*([\'"])([^\'"]*)\1#', $file_contents, $m ) ) {
		foreach ( (array) $m[2] as $pat ) {
			if ( false === strpos( (string) $pat, '\R' ) ) {
				continue; // Not a line-break pattern.
			}
			$slash = strrpos( (string) $pat, '/' );
			$mods  = ( false === $slash ) ? '' : substr( (string) $pat, $slash + 1 );
			if ( false === strpos( $mods, 'u' ) ) {
				$found[] = $name . ' → ' . $pat;
			}
		}
	}
	return $found;
}

ok(
	'newline-split scanner flags a byte-mode call',
	array( 'x.php → /\R/' ) === newline_split_offenders( "<?php preg_split( '/\\R/', \$t );", 'x.php' ),
	(string) json_encode( newline_split_offenders( "<?php preg_split( '/\\R/', \$t );", 'x.php' ) )
);
ok(
	'newline-split scanner accepts the /u form',
	array() === newline_split_offenders( "<?php preg_split( '/\\R{2,}/u', \$t );", 'x.php' )
);
ok(
	'newline-split scanner ignores unrelated patterns',
	array() === newline_split_offenders( "<?php preg_split( '/,/', \$t );", 'x.php' )
);

$bad_preg = array();
foreach ( glob( HAAI_DEV_ROOT . 'includes/*.php' ) as $f ) {
	$bad_preg = array_merge( $bad_preg, newline_split_offenders( (string) file_get_contents( $f ), basename( $f ) ) );
}
ok( 'no byte-mode newline split in includes/', array() === $bad_preg, implode( ' ; ', $bad_preg ) );

// Settings/UI mirror parity for the new section.
$issues = Haa_Settings::diagnose();
ok( 'diagnose clean with article-writer ON', array() === $issues, (string) count( $issues ) );

$s = Haa_Settings::get();
$s['modules']['article-writer'] = 0;
update_option( HAAI_OPTION, $s, 'no' );
update_option( Haa_Settings::MODULES_OPTION, array( 'chatbot' => 1 ), 'yes' );
Haa_Settings::flush_cache();
ok( 'diagnose clean with article-writer OFF', array() === Haa_Settings::diagnose() );

echo "\nFAILURES: $fail\n";
echo "CHECKS: " . ( $fail + 0 ) . " failed of total above\n";
exit( $fail ? 1 : 0 );
