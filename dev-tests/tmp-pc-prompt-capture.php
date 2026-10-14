<?php
/**
 * Capture the real outbound product-brief prompt (bench only, no network).
 *
 * Loads the fact array dumped from the live site and runs the production
 * builder Haa_Content::product_brief(). The HTTP call is short-circuited by the
 * pre_http_request filter, which records the exact request body and answers with
 * a canned OpenAI-shaped reply — so the model is never reached and no key is
 * used. What is printed is byte-for-byte what would have been POSTed.
 *
 * Usage: wp --path=C:\xampp\htdocs\haai-test eval-file dev-tests/tmp-pc-prompt-capture.php 13111
 */
$pid  = isset( $args[0] ) ? (int) $args[0] : 13111;
$file = dirname( __DIR__ ) . '/dev-tests/pc-facts-' . $pid . '.json';
if ( ! is_readable( $file ) ) {
	echo "missing dump: $file\n";
	return;
}
$dump  = json_decode( (string) file_get_contents( $file ), true );
$facts = (array) $dump['facts'];

$GLOBALS['pc_capture'] = null;

// chat() refuses to run without a key/base_url. The transport is short-circuited
// below, so these are placeholders written to the BENCH only (a literal public
// IP keeps the SSRF guard on its fast path: no DNS, no network). Restored at the
// end of this script.
$restore = get_option( HAAI_OPTION );
$live    = (array) Haa_Settings::get();
$live['provider']['base_url'] = 'https://8.8.8.8/v1';
$live['provider']['api_key']  = 'capture-only';
$live['provider']['model']    = 'capture-model';
$live['provider']['retries']  = 0;
update_option( HAAI_OPTION, $live, 'no' );
Haa_Settings::flush_cache();

add_filter(
	'pre_http_request',
	function ( $preempt, $args, $url ) {
		$GLOBALS['pc_capture'] = array(
			'url'  => $url,
			'args' => $args,
		);
		return array(
			'headers' => array(),
			'body'    => wp_json_encode(
				array(
					'choices' => array(
						array(
							'message'      => array(
								'role'    => 'assistant',
								'content' => '{"html":"<p>capture-only</p>","seo_title":"x","seo_description":"y","slug":"z","excerpt":"w","faq":[]}',
							),
							'finish_reason' => 'stop',
						),
					),
				)
			),
			'response' => array(
				'code'    => 200,
				'message' => 'OK',
			),
			'cookies'  => array(),
		);
	},
	10,
	3
);

$started = microtime( true );
$brief   = Haa_Content::product_brief(
	$dump['title'],
	$facts,
	array(
		'tone'     => 'friendly',
		'length'   => 'xlong',
		'keywords' => '',
	),
	array(
		'module'    => 'product-content',
		'operation' => 'product-content',
	)
);
$ms = (int) round( ( microtime( true ) - $started ) * 1000 );

update_option( HAAI_OPTION, $restore, 'no' );
Haa_Settings::flush_cache();
echo "bench settings restored (" . ( get_option( HAAI_OPTION ) === $restore ? 'identical' : 'DIFFERENT' ) . ")\n";

echo '=== captured request (mocked transport, no API key, no network) ===' . "\n";
echo 'bench ok=' . var_export( $brief['ok'], true ) . " in {$ms}ms\n";
if ( ! $GLOBALS['pc_capture'] ) {
	echo "NOT CAPTURED — no outbound call was made\n";
	return;
}
echo 'url       : ' . $GLOBALS['pc_capture']['url'] . "\n";
echo 'timeout arg: ' . var_export( $GLOBALS['pc_capture']['args']['timeout'], true ) . "\n";

$body = json_decode( (string) $GLOBALS['pc_capture']['args']['body'], true );
echo 'wire model=' . ( $body['model'] ?? '' ) . ' max_tokens=' . var_export( $body['max_tokens'] ?? null, true ) . ' temperature=' . var_export( $body['temperature'] ?? null, true ) . "\n";

echo "\n----- SYSTEM -----\n" . ( $body['messages'][0]['content'] ?? '' );
echo "\n----- USER -----\n" . ( $body['messages'][1]['content'] ?? '' );
echo "\n----- sizes -----\n";
echo 'system chars=' . mb_strlen( (string) ( $body['messages'][0]['content'] ?? '' ), 'UTF-8' )
	. ' user chars=' . mb_strlen( (string) ( $body['messages'][1]['content'] ?? '' ), 'UTF-8' ) . "\n";
echo 'facts keys sent=' . count( $facts ) . ' (' . implode( ', ', array_keys( $facts ) ) . ")\n";
