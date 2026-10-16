<?php
/**
 * Regression suite: the proxy actually reaches CURLOPT_PROXY.
 *
 * The old implementation stuffed 'proxy' / 'proxyauth' / 'proxycuserpwd'
 * into the wp_remote_* args and assumed WordPress would turn them into
 * curl options. It never did — WP_Http::request() builds the Requests
 * options explicitly, ignores unknown keys, and injects a proxy ONLY from
 * the WP_PROXY_* constants. So the admin filled in the proxy fields and
 * the traffic kept going out directly (then timed out on the very
 * blocked endpoints the proxy was meant to bypass).
 *
 * The fix rides the http_api_curl action (fired with the real cURL
 * handle right before curl_exec): proxy_args() stashes a pending entry,
 * on_curl_handle() stamps CURLOPT_PROXY / CURLOPT_PROXYUSERPWD /
 * CURLOPT_PROXYAUTH onto the handle whose request URL matches — one-shot,
 * URL-scoped, so nothing else's traffic is touched. Schemes ride inside
 * the host field (socks5://, socks5h://) and cURL switches proxy type
 * from the prefix itself.
 *
 * Run: php dev-tests/tmp-checks19-proxy.php
 */
$GLOBALS['haai_use_real_api'] = true;
require __DIR__ . '/tmp-wp-stubs.php';

$fail = 0;
function ok( $label, $cond, $extra = '' ) {
	global $fail;
	if ( ! $cond ) { $fail++; }
	echo ( $cond ? '  ok   ' : '  FAIL ' ) . str_pad( $label, 58 ) . $extra . "\n";
}

$rc      = new ReflectionClass( 'Haa_API' );
$pending = $rc->getProperty( 'pending_proxy' );
$pending->setAccessible( true );
$curl_opts = $rc->getMethod( 'curl_proxy_options' );
$curl_opts->setAccessible( true );
$hook = array( 'Haa_API', 'on_curl_handle' );

$endpoint = 'https://generativelanguage.googleapis.com/v1beta/models/x:generateContent';

echo "=== A. no proxy -> nothing happens ===\n";
$args = Haa_API::proxy_args( array( 'timeout' => 30 ), array( 'base_url' => 'https://x/v1' ) );
ok( 'no proxy_host: args untouched', ! isset( $args['proxy'], $args['sslverify'], $args['headers']['Expect'] ) );
ok( 'no proxy_host: no pending', null === $pending->getValue() );

echo "=== B. proxy configured -> pending + hook, no dead keys ===\n";
$cfg = array(
	'base_url'       => 'https://generativelanguage.googleapis.com/v1beta',
	'proxy_host'     => '10.0.0.8',
	'proxy_port'     => '8118',
	'proxy_username' => 'ali',
	'proxy_password' => 'secret123',
);
$args = Haa_API::proxy_args( array( 'timeout' => 30 ), $cfg, $endpoint );
ok( 'legacy dead keys are gone', ! isset( $args['proxy'], $args['proxyauth'], $args['proxycuserpwd'] ) );
ok( 'sslverify relaxed through proxy hop', false === $args['sslverify'] );
ok( 'Expect header kept', isset( $args['headers']['Expect'] ) );
$p = $pending->getValue();
ok( 'pending stashed', is_array( $p ) );
ok( 'proxy URL default-scheme http', is_array( $p ) && 'http://10.0.0.8:8118' === $p['proxy'] );
ok( 'auth user:pass carried', is_array( $p ) && 'ali:secret123' === $p['auth'] );
ok( 'match URL carried', is_array( $p ) && $endpoint === $p['match'] );
ok( 'http_api_curl hook registered once', has_action( 'http_api_curl' ) );
Haa_API::proxy_args( array(), $cfg, $endpoint ); // second call must not double-register.

echo "=== C. curl options, pure helper ===\n";
$opts = $curl_opts->invoke( null, 'http://10.0.0.8:8118', 'ali:secret123' );
ok( 'CURLOPT_PROXY set', isset( $opts[ CURLOPT_PROXY ] ) && 'http://10.0.0.8:8118' === $opts[ CURLOPT_PROXY ] );
ok( 'CURLOPT_PROXYUSERPWD set', isset( $opts[ CURLOPT_PROXYUSERPWD ] ) && 'ali:secret123' === $opts[ CURLOPT_PROXYUSERPWD ] );
ok( 'CURLOPT_PROXYAUTH = CURLAUTH_ANY', isset( $opts[ CURLOPT_PROXYAUTH ] ) && CURLAUTH_ANY === $opts[ CURLOPT_PROXYAUTH ] );
$opts = $curl_opts->invoke( null, 'socks5h://10.0.0.9:1080', '' );
ok( 'socks5h prefix passes through untouched', 'socks5h://10.0.0.9:1080' === $opts[ CURLOPT_PROXY ] );
ok( 'no auth -> no PROXYUSERPWD key', ! isset( $opts[ CURLOPT_PROXYUSERPWD ] ) );

echo "=== D. proxy_url() shapes ===\n";
$proxy_url = $rc->getMethod( 'proxy_url' );
$proxy_url->setAccessible( true );
ok( 'plain host, port from cfg', 'http://p.example.com:3128' === $proxy_url->invoke( null, array( 'proxy_host' => 'p.example.com', 'proxy_port' => '3128' ) ) );
ok( 'no port -> 8080 default', 'http://p.example.com:8080' === $proxy_url->invoke( null, array( 'proxy_host' => 'p.example.com' ) ) );
ok( 'socks5:// prefix preserved', 'socks5://1.2.3.4:1080' === $proxy_url->invoke( null, array( 'proxy_host' => 'socks5://1.2.3.4', 'proxy_port' => '1080' ) ) );
ok( 'socks5h:// prefix preserved', 'socks5h://1.2.3.4:1080' === $proxy_url->invoke( null, array( 'proxy_host' => 'socks5h://1.2.3.4', 'proxy_port' => '1080' ) ) );
ok( 'https:// proxy preserved', 'https://p.example.com:8443' === $proxy_url->invoke( null, array( 'proxy_host' => 'https://p.example.com', 'proxy_port' => '8443' ) ) );
ok( 'user:pass@host stripped to host', 'http://5.6.7.8:1080' === $proxy_url->invoke( null, array( 'proxy_host' => 'ali:secret@5.6.7.8', 'proxy_port' => '1080' ) ) );
ok( 'blank host -> unusable', '' === $proxy_url->invoke( null, array( 'proxy_host' => '   ' ) ) );

echo "=== E. one-shot, URL-scoped consumption ===\n";
Haa_API::proxy_args( array(), $cfg, $endpoint );
$other = 'https://openrouter.ai/api/v1/models';

// A nested foreign request inside our window must NOT consume nor get stamped.
$fake = 'not-a-handle';
call_user_func_array( $hook, array( &$fake, array(), $other ) );
ok( 'foreign URL: pending survives', is_array( $pending->getValue() ) );

// The real endpoint fires the hook with the real handle.
$handle = curl_init( $endpoint );
call_user_func_array( $hook, array( &$handle, array(), $endpoint ) );
ok( 'matching URL: pending consumed', null === $pending->getValue() );
$conf = curl_getinfo( $handle ); // just proves the handle is alive after stamping.
curl_close( $handle );
ok( 'handle stamped without error', is_array( $conf ) );

// A second fire (e.g. a redirect hop) has nothing to stamp.
$handle2 = curl_init( $endpoint );
call_user_func_array( $hook, array( &$handle2, array(), $endpoint ) );
curl_close( $handle2 );
ok( 'second fire is a no-op (one-shot)', null === $pending->getValue() );

// URL-less legacy call sites ('' match) apply to the FIRST fire only.
Haa_API::proxy_args( array(), $cfg, '' );
$h3 = 'still-not-a-handle';
call_user_func_array( $hook, array( &$h3, array(), 'https://anything.example/x' ) );
ok( 'empty match applies to first fire, consumed', null === $pending->getValue() );

echo "\n" . ( $fail ? "FAILURES: $fail\n" : "ALL OK\n" );
exit( $fail ? 1 : 0 );
