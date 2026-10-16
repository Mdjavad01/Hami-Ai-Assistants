<?php
/**
 * Regression suite: 2.1.9 provider failover, deterministically and without a
 * real provider:
 *
 *   - the settings triple contract (defaults + sanitize map + UI registry)
 *     knows provider.failovers;
 *   - sanitize_failovers: empty rows dropped, typed keys encrypted, masked
 *     keys re-bound to the same row via its stable hash, max five rows;
 *   - resolve_failover_rows: presets fill the blanks, custom rows speak the
 *     OpenAI wire format, keyless/URL-less rows are skipped, keys decrypt;
 *   - the candidate walk: healthy sources first, cooled ones demoted, an
 *     unusable primary skipped while a fallback exists, the friendly
 *     configuration errors intact when nothing else is configured;
 *   - failover actually happens: a dead primary (timeout) hands the request
 *     to a live fallback and the caller sees the fallback's answer;
 *   - the image walk skips Claude rows instead of failing.
 *
 * Run: php dev-tests/tmp-checks17-failover.php
 */
$GLOBALS['haai_use_real_api'] = true; // the REAL transport, not the stub fake
require __DIR__ . '/tmp-wp-stubs.php';

$fail = 0;
function ok( $label, $cond, $extra = '' ) {
        global $fail;
        if ( ! $cond ) { $fail++; }
        echo ( $cond ? '  ok   ' : '  FAIL ' ) . str_pad( $label, 58 ) . $extra . "\n";
}

echo "=== A. settings triple contract ===\n";
$settings = Haa_Settings::get();
ok( 'default provider.failovers exists', array_key_exists( 'failovers', $settings['provider'] ) );
ok( 'default provider.failovers empty array', array() === $settings['provider']['failovers'] );
$map = Haa_Settings::sanitize_map();
ok( 'sanitize rule provider.failovers', isset( $map['provider.failovers'] ) );
$ui_rc = new ReflectionMethod( 'Haa_Settings', 'collect_ui_keys' );
$ui_rc->setAccessible( true );
$ui = $ui_rc->invoke( null );
$found_ui = false;
foreach ( $ui as $group => $keys ) {
        if ( in_array( 'provider.failovers', (array) $keys, true ) ) { $found_ui = true; }
}
ok( 'UI registry has provider.failovers', $found_ui );

echo "\n=== B. sanitize_failovers ===\n";
$typed = Haa_Settings::sanitize_failovers( array(
        array(), // untouched template — dropped
        array( 'provider' => 'openrouter', 'api_key' => 'sk-test-123', 'base_url' => '', 'model' => '' ),
        array( 'provider' => 'bogus-preset', 'base_url' => 'https://relay.example/v1', 'api_key' => 'key2', 'model' => 'm-1' ),
) );
ok( 'empty template row dropped', 2 === count( $typed ) );
ok( 'typed key stored encrypted', 0 === strpos( (string) $typed[0]['api_key'], 'haaienc:' ) );
ok( 'unknown preset slug cleared', '' === $typed[1]['provider'] );
ok( 'base URL kept', 'https://relay.example/v1' === $typed[1]['base_url'] );
ok( 'rows carry a stable hash', '' !== $typed[0]['hash'] && '' !== $typed[1]['hash'] );

// Mask round-trip: persist the rows first (the mask re-binds against what is
// SAVED), then post the mask back with the same hash — the key must survive.
Haa_Settings::save_section( 'provider', array( 'failovers' => $typed ) );
$masked = Haa_Settings::sanitize_failovers( array(
        array( 'provider' => 'openrouter', 'base_url' => '', 'model' => '', 'api_key' => Haa_Settings::KEY_MASK, 'hash' => $typed[0]['hash'] ),
) );
ok( 'masked key re-bound by hash', $masked[0]['api_key'] === $typed[0]['api_key'] );

// Mask with a WRONG hash must not steal another row's key.
$stolen = Haa_Settings::sanitize_failovers( array(
        array( 'provider' => 'openrouter', 'base_url' => '', 'model' => '', 'api_key' => Haa_Settings::KEY_MASK, 'hash' => 'deadbeefdeadbeefdeadbeefdeadbeef' ),
) );
ok( 'mask without a matching hash stores no key', '' === $stolen[0]['api_key'] );

// Cap at five.
$six = array();
for ( $i = 0; $i < 6; $i++ ) {
        $six[] = array( 'base_url' => 'https://r' . $i . '.example/v1', 'api_key' => 'k' . $i );
}
ok( 'more than five rows capped', 5 === count( Haa_Settings::sanitize_failovers( $six ) ) );

echo "\n=== C. resolve_failover_rows ===\n";
$resolved = Haa_Settings::resolve_failover_rows( $typed );
ok( 'preset fills base_url', 'https://openrouter.ai/api/v1' === $resolved[0]['base_url'] );
ok( 'preset fills model', 'openai/gpt-4o-mini' === $resolved[0]['model'] );
ok( 'preset owns the adapter', 'openai' === $resolved[0]['adapter'] );
ok( 'custom row speaks OpenAI wire', 'openai' === $resolved[1]['adapter'] );
ok( 'keys decrypt to plaintext', 'sk-test-123' === $resolved[0]['api_key'] );

echo "\n=== D. candidate walk ===\n";
$api = new ReflectionClass( 'Haa_API' );
$src = $api->getMethod( 'source_list' );
$src->setAccessible( true );
$cand = $api->getMethod( 'candidates' );
$cand->setAccessible( true );

$mk = function ( $over = array() ) {
        return array_merge( array(
                'provider' => 'openai', 'adapter' => 'openai', 'base_url' => 'https://primary.example/v1',
                'api_key' => 'pk-main', 'model' => 'm-main', 'timeout' => 30, 'retries' => 2,
                'temperature' => 0.7, 'max_tokens' => 1000, 'proxy_host' => '', 'failovers' => array(),
        ), $over );
};

// No failovers: the primary alone.
$h = new Haa_API( $mk() );
$list = $src->invoke( $h );
ok( 'no failovers: single source', 1 === count( $list ) && ! empty( $list[0]['is_primary'] ) );

// Two healthy sources: primary first, transport settings inherited.
$h2 = new Haa_API( $mk( array( 'failovers' => array(
        array( 'adapter' => 'openai', 'base_url' => 'https://backup.example/v1', 'api_key' => 'pk-backup', 'model' => 'm-backup', 'hash' => 'h1' ),
) ) ) );
$list2 = $src->invoke( $h2 );
ok( 'primary leads when both healthy', 'https://primary.example/v1' === $list2[0]['base_url'] );
ok( 'fallback inherits transport (timeout)', 30 === $list2[1]['timeout'] );
ok( 'fallback keeps its own endpoint', 'https://backup.example/v1' === $list2[1]['base_url'] );

// A cooled primary is demoted behind the healthy fallback.
$GLOBALS['saved'][ 'haai_fb_' . substr( md5( 'https://primary.example/v1|m-main' ), 0, 32 ) ] = time() + 300;
$list3 = $src->invoke( $h2 );
ok( 'cooled primary demoted to the back', 'https://backup.example/v1' === $list3[0]['base_url'] );
unset( $GLOBALS['saved'][ 'haai_fb_' . substr( md5( 'https://primary.example/v1|m-main' ), 0, 32 ) ] );

// Unusable primary (no key) skipped while a usable fallback exists.
$h3 = new Haa_API( $mk( array( 'api_key' => '', 'failovers' => array(
        array( 'adapter' => 'openai', 'base_url' => 'https://backup.example/v1', 'api_key' => 'pk-backup', 'model' => 'm-backup', 'hash' => 'h2' ),
) ) ) );
$c3 = $cand->invoke( $h3 );
ok( 'keyless primary skipped, fallback used', 1 === count( $c3 ) && 'https://backup.example/v1' === $c3[0]['base_url'] );

// Nothing usable: the primary stays so the friendly errors surface.
$h4 = new Haa_API( $mk( array( 'api_key' => '', 'base_url' => '' ) ) );
$c4 = $cand->invoke( $h4 );
ok( 'unusable lone primary kept for its error', 1 === count( $c4 ) );
ok( 'haai_no_key message intact', is_wp_error( $h4->chat( array() ) ) && 'haai_no_key' === $h4->chat( array() )->get_error_code() );

echo "\n=== E. live failover (mock HTTP) ===\n";
$GLOBALS['http_map'] = array(
        'primary.example'  => array( 'error' => 'cURL error 28: timed out' ),
        'backup.example'   => array(
                'code' => 200,
                'body' => wp_json_encode( array(
                        'choices' => array(
                                array( 'message' => array( 'content' => 'پاسخ از منبع یدکی' ) ),
                        ),
                ) ),
        ),
);
$h5 = new Haa_API( $mk( array( 'failovers' => array(
        array( 'adapter' => 'openai', 'base_url' => 'https://backup.example/v1', 'api_key' => 'pk-backup', 'model' => 'm-backup', 'hash' => 'h3' ),
) ) ) );
$reply = $h5->chat( array( array( 'role' => 'user', 'content' => 'ping' ) ), array( 'retries' => 3 ) );
ok( 'dead primary hands over to fallback', is_string( $reply ) && 'پاسخ از منبع یدکی' === $reply );
// The failed primary is now benched…
$left = Haa_API::cooldown_left( $mk() );
ok( 'failed primary benched for 5 minutes', $left > 200 && $left <= 300 );
// …so the NEXT call goes straight to the fallback (one HTTP call only).
$GLOBALS['http_log'] = array();
$reply2 = $h5->chat( array( array( 'role' => 'user', 'content' => 'ping' ) ), array( 'retries' => 3 ) );
$hosts  = array();
foreach ( (array) $GLOBALS['http_log'] as $call ) {
        if ( preg_match( '#https?://([^/]+)#', (string) ( $call['url'] ?? '' ), $mm ) ) { $hosts[] = $mm[1]; }
}
ok( 'cooled primary skipped without a call', is_string( $reply2 ) && ! in_array( 'primary.example', $hosts, true ) );

// All sources fail: the last error surfaces and both are benched.
$GLOBALS['http_map'] = array(
        'backup.example'   => array( 'error' => 'cURL error 28: timed out' ),
        'primary.example'  => array( 'error' => 'cURL error 28: timed out' ),
);
$reply3 = $h5->chat( array( array( 'role' => 'user', 'content' => 'ping' ) ), array( 'retries' => 0 ) );
ok( 'all sources down: WP_Error returned', is_wp_error( $reply3 ) );

echo "\n=== F. image walk ===\n";
$img = $api->getMethod( 'image' );
$GLOBALS['http_map'] = array(
        'primary.example'  => array(
                'code' => 200,
                'body' => wp_json_encode( array( 'data' => array( array( 'url' => 'https://img.example/a.png' ) ) ) ),
        ),
);
$h6 = new Haa_API( $mk( array( 'failovers' => array(
        array( 'adapter' => 'claude', 'base_url' => 'https://claude.example/v1', 'api_key' => 'pk-cl', 'model' => 'claude-x', 'hash' => 'h4' ),
) ) ) );
$GLOBALS['http_log'] = array();
$imgreply = $h6->image( 'یک نمونه تصویر', array() );
$hosts2 = array();
foreach ( (array) $GLOBALS['http_log'] as $call ) {
        if ( preg_match( '#https?://([^/]+)#', (string) ( $call['url'] ?? '' ), $mm ) ) { $hosts2[] = $mm[1]; }
}
ok( 'image succeeds on the primary', is_string( $imgreply ) && 'https://img.example/a.png' === $imgreply );
ok( 'claude fallback skipped, not "failed"', ! in_array( 'claude.example', $hosts2, true ) );

echo "\n";
echo ( 0 === $fail ) ? "ALL PASS\n" : "FAILURES: $fail\n";
exit( 0 === $fail ? 0 : 1 );
