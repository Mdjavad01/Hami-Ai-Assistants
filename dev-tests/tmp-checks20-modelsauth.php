<?php
/**
 * Regression suite: /models auth + relay path contract.
 *
 * The models list used to authenticate Gemini with ?key=… in the URL. Through
 * a relay (Cloudflare Worker) that put the Gemini API key into the relay's
 * access logs. Now it rides the x-goog-api-key header — the same header the
 * chat path already uses — and the URL stays clean.
 *
 * Also locks the relay Base URL contract the user's Worker depends on:
 * endpoint() / models() append /models and /models/{model}:generateContent to
 * whatever Base URL is configured, so a base of
 *   https://worker.example/r/TOKEN/gemini/v1beta
 * must produce …/gemini/v1beta/models/…  — no doubled segments, no forced
 * trailing slash.
 *
 * Run: php dev-tests/tmp-checks20-modelsauth.php
 */
$GLOBALS['haai_use_real_api'] = true;
require __DIR__ . '/tmp-wp-stubs.php';

$fail = 0;
function ok( $label, $cond, $extra = '' ) {
	global $fail;
	if ( ! $cond ) { $fail++; }
	echo ( $cond ? '  ok   ' : '  FAIL ' ) . str_pad( $label, 58 ) . $extra . "\n";
}

http_reset();

echo "=== A. Gemini /models — header auth, clean URL ===\n";
http_mock( 'gemini-models.example', array(
	'code' => 200,
	'body' => wp_json_encode( array( 'models' => array(
		array( 'name' => 'models/gemini-1.5-flash' ),
		array( 'name' => 'models/gemini-2.0-flash-lite' ),
		array( 'name' => 'models/gemini-1.5-flash' ), // duplicate — must dedupe.
	) ) ),
) );
$api = new Haa_API( array(
	'base_url' => 'https://gemini-models.example/v1beta',
	'adapter'  => 'gemini',
	'api_key'  => 'AIza-test-key-123',
	'model'    => 'gemini-1.5-flash',
	'timeout'  => 30,
) );
$ids = $api->models();
$calls = http_requests();
$last  = $calls ? $calls[ count( $calls ) - 1 ] : array();
ok( 'GET fired', is_array( $last ) && 'GET' === $last['method'] );
ok( 'URL is exactly /models', is_array( $last ) && 'https://gemini-models.example/v1beta/models' === $last['url'] );
ok( 'no ?key= in URL (relay log safety)', is_array( $last ) && false === strpos( (string) $last['url'], 'key=' ) );
ok( 'x-goog-api-key header carried', is_array( $last ) && 'AIza-test-key-123' === ( $last['args']['headers']['x-goog-api-key'] ?? '' ) );
ok( 'Gemini envelope parsed', is_array( $ids ) && array( 'gemini-1.5-flash', 'gemini-2.0-flash-lite' ) === $ids );

echo "=== B. relay Base URL — no doubled path ===\n";
http_mock( 'worker-relay.example', array(
	'code' => 200,
	'body' => wp_json_encode( array( 'models' => array( array( 'name' => 'models/gemini-2.5-flash-lite' ) ) ) ),
) );
$relay = new Haa_API( array(
	// The user's Worker pattern: /r/{RELAY_TOKEN}/gemini/v1beta.
	'base_url' => 'https://worker-relay.example/r/SECRET_TOKEN/gemini/v1beta',
	'adapter'  => 'gemini',
	'api_key'  => 'AIza-relay-key-456',
	'model'    => 'gemini-2.5-flash-lite',
	'timeout'  => 30,
) );
$ids = $relay->models();
$calls = http_requests();
$last  = $calls ? $calls[ count( $calls ) - 1 ] : array();
ok( 'relay /models URL built once', is_array( $last ) && 'https://worker-relay.example/r/SECRET_TOKEN/gemini/v1beta/models' === $last['url'] );
ok( 'no doubled v1beta', is_array( $last ) && 1 === substr_count( (string) $last['url'], 'v1beta' ) );
ok( 'relay key rides header, not URL', is_array( $last ) && 'AIza-relay-key-456' === ( $last['args']['headers']['x-goog-api-key'] ?? '' ) && false === strpos( (string) $last['url'], 'AIza' ) );

// Chat endpoint on the same relay base: /models/{model}:generateContent appended once.
$ref  = new ReflectionMethod( 'Haa_Adapter_Gemini', 'endpoint' );
$ref->setAccessible( true );
$ep   = $ref->invoke( $relay->adapter() );
ok( 'chat endpoint = …/v1beta/models/{model}:generateContent', 'https://worker-relay.example/r/SECRET_TOKEN/gemini/v1beta/models/gemini-2.5-flash-lite:generateContent' === $ep );
ok( 'chat endpoint has single v1beta', 1 === substr_count( $ep, 'v1beta' ) );

echo "=== C. OpenAI-compatible /models — unchanged (Bearer header) ===\n";
http_mock( 'openai-models.example', array(
	'code' => 200,
	'body' => wp_json_encode( array( 'data' => array( array( 'id' => 'llama-3.3-70b-versatile' ) ) ) ),
) );
$oa = new Haa_API( array(
	'base_url' => 'https://openai-models.example/openai/v1',
	'adapter'  => 'openai',
	'api_key'  => 'gsk_test_789',
	'model'    => 'llama-3.3-70b-versatile',
	'timeout'  => 30,
) );
$ids = $oa->models();
$calls = http_requests();
$last  = $calls ? $calls[ count( $calls ) - 1 ] : array();
ok( 'OpenAI /models URL kept', is_array( $last ) && 'https://openai-models.example/openai/v1/models' === $last['url'] );
ok( 'Bearer header kept', is_array( $last ) && 'Bearer gsk_test_789' === ( $last['args']['headers']['Authorization'] ?? '' ) );
ok( 'OpenAI envelope parsed', is_array( $ids ) && array( 'llama-3.3-70b-versatile' ) === $ids );

echo "\n" . ( $fail ? "FAILURES: $fail\n" : "ALL OK\n" );
exit( $fail ? 1 : 0 );
