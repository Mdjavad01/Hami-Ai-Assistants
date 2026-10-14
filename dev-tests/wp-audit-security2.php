<?php
/**
 * Bench tool (report-only), pass 2: exact password-post backstop + IPv6 SSRF proof.
 * Run: php wp-cli.phar eval-file dev-tests/wp-audit-security2.php --path=C:\xampp\htdocs\haai-test
 * Mock LLM must be serving on BOTH 127.0.0.1:8801 and [::1]:8802.
 */
function ok( $label, $cond, $extra = '' ) {
	echo ( $cond ? '  ok   ' : '  FAIL ' . $label ) . "\n";
	if ( ! $cond ) { echo "  FAIL $label" . ( $extra ? " [$extra]" : '' ) . "\n"; }
}

$s = Haa_Settings::get();
$orig_provider = $s['provider'];
$chunks_table  = $GLOBALS['wpdb']->prefix . 'haai_chunks';

/* ---- A. password-protected published post, done properly ---- */
$pw = wp_insert_post( array( 'post_title' => 'رمزدار REPUBLIC-PW', 'post_content' => 'متن رمزدار REPUBLIC-PW.', 'post_status' => 'publish', 'post_type' => 'post' ) );
wp_update_post( array( 'ID' => $pw, 'post_password' => 'REPUBLIC-123' ) );
$p = get_post( $pw );
echo 'password field set: ' . var_export( '' !== (string) $p->post_password, true ) . "\n";
Haa_Search::index_post( $pw );
$chunks = (int) $GLOBALS['wpdb']->get_var( $GLOBALS['wpdb']->prepare( "SELECT COUNT(*) FROM $chunks_table WHERE post_id = %d", $pw ) );
ok( 'password post indexable (storage exposure)', 0 === $chunks, 'chunks=' . $chunks . ' (0 expected = ideal)' );
$hits = Haa_Search::search( 'REPUBLIC-PW', 5, 1500 );
ok( 'backstop keeps password post out of results', array() === $hits, 'hits=' . count( $hits ) );

/* ---- B. IPv6 bracketed SSRF, end-to-end ---- */
$s['provider'] = array_merge( $orig_provider, array( 'base_url' => 'http://[::1]:8802/v1', 'api_key' => 'mock-key', 'allow_localhost' => 1, 'adapter' => 'openai' ) );
$s['advanced']['enable_rag']   = 0;
$s['advanced']['enable_cache'] = 0;
update_option( HAAI_OPTION, $s, 'no' );
Haa_Settings::flush_cache();

$url = 'http://[::1]:8802/v1/chat/completions';
$safe = Haa_API::is_url_safe( $url );
echo 'is_url_safe(' . $url . ') with allow_localhost=1 => ' . ( true === $safe ? 'true' : 'WP_Error' ) . "\n";

// Same URL with allow_localhost OFF proves the filter, not the dev flag, is what fails.
$s['provider']['allow_localhost'] = 0;
update_option( HAAI_OPTION, $s, 'no' );
Haa_Settings::flush_cache();
$safe_off = Haa_API::is_url_safe( 'http://[::1]:8802/v1' );
ok( 'production settings block [::1]', is_wp_error( $safe_off ), true === $safe_off ? 'ALLOWED (gap)' : 'blocked' );

// End-to-end: does the request actually reach an IPv6 loopback service?
$s['provider'] = array_merge( $orig_provider, array( 'base_url' => 'http://[::1]:8802/v1', 'api_key' => 'mock-key', 'allow_localhost' => 1, 'adapter' => 'openai', 'timeout' => 6, 'retries' => 0 ) );
update_option( HAAI_OPTION, $s, 'no' );
Haa_Settings::flush_cache();
$r = Haa_Chat::ask( 'سلام', array(), 'probe-v6' );
$reached = is_array( $r ) && ! empty( $r['ok'] );
ok( 'chat really reaches a service on [::1] (exploitable)', $reached, is_wp_error( $r ) ? 'wp_error' : ( is_array( $r ) ? ( $r['error'] ?? mb_strimwidth( (string) ( $r['reply'] ?? '' ), 0, 40, '…' ) ) : '?' ) );

/* ---- cleanup ---- */
wp_delete_post( $pw, true );
$s             = Haa_Settings::get();
$s['provider'] = $orig_provider;
$s['provider']['allow_localhost'] = 0;
update_option( HAAI_OPTION, $s, 'no' );
Haa_Settings::flush_cache();
echo "done\n";
