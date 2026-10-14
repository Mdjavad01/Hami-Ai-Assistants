<?php
/**
 * Throwaway: with WP_DEBUG on, the source must win even when a .min file exists.
 *
 * Its own process because WP_DEBUG cannot be undefined once defined, so it cannot
 * be folded into tmp-checks.php.
 */
define( 'WP_DEBUG', true );
require __DIR__ . '/tmp-wp-stubs.php';

$fail = 0;
foreach (
	array(
		'assets/css/admin.css'          => 'assets/css/admin.css',
		'assets/css/chatbot.css'        => 'assets/css/chatbot.css',
		'assets/js/admin.js'            => 'assets/js/admin.js',
		'assets/js/product-picker.js'   => 'assets/js/product-picker.js',
		'assets/js/loader.js'           => 'assets/js/loader.js',
		'assets/js/chatbot.js'          => 'assets/js/chatbot.js',
	) as $in => $want
) {
	$got = haa_asset_path( $in );
	if ( $want !== $got ) {
		$fail++;
		echo '  FAIL ' . str_pad( $in, 30 ) . "got $got\n";
	}
}

// The min files really are present, otherwise this test proves nothing.
if ( ! is_readable( HAAI_PATH . 'assets/css/admin.min.css' ) ) {
	echo "  FAIL min build missing — run: node tools/minify.mjs\n";
	$fail++;
}

echo 0 === $fail ? "  ok   WP_DEBUG serves all 6 sources despite .min siblings\n" : '';
echo "\nFAILURES: $fail\n";
exit( $fail ? 1 : 0 );
