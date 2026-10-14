<?php
/**
 * Regression suite: Batch B — WooCommerce dependency has ONE source of truth
 * (Haa_Modules::catalog_meta()['woo']) and the loader keeps dependent modules
 * dormant without WooCommerce.
 *
 * M4 verified from code:
 *   - product-content reads the product_cat tree + WC products   => woo true
 *   - discount issues real WC_Coupon objects                      => woo true
 *   - leads stores phone/email/note rows only, no wc_/WC_ calls    => woo false
 *
 * In this harness `WooCommerce` is defined, so haa_is_woo() is true; the
 * "module stays off while WooCommerce is missing" behaviour (load gate + the
 * save path that must not wipe the stored flag) is proven on the real bench.
 *
 * Before the fix these checks fail hard: Haa_Modules::requires_woocommerce()
 * does not exist, and the module methods return their own literals instead of
 * delegating to the catalogue.
 *
 * Run: php dev-tests/tmp-checks13.php
 */
require __DIR__ . '/tmp-wp-stubs.php';

$fail = 0;
function ok( $label, $cond, $extra = '' ) {
	global $fail;
	if ( ! $cond ) { $fail++; }
	echo ( $cond ? '  ok   ' : '  FAIL ' ) . str_pad( $label, 58 ) . $extra . "\n";
}

echo "=== A. the catalogue is the single source: expected woo flags ===\n";
$expect = array(
	'chatbot' => false, 'product-content' => true, 'article-writer' => false,
	'autopilot' => false, 'leads' => false, 'discount' => true, 'comments' => false,
	'semantic-search' => false, 'news' => false, 'video-article' => false, 'programmatic' => false,
);
ok( 'helper exists (would fatal before the fix)', method_exists( 'Haa_Modules', 'requires_woocommerce' ) );
foreach ( $expect as $slug => $want ) {
	ok( "requires_woocommerce($slug) = " . var_export( $want, true ), Haa_Modules::requires_woocommerce( $slug ) === $want, var_export( Haa_Modules::requires_woocommerce( $slug ), true ) );
}

echo "\n=== B. every module delegates to the catalogue (no second truth) ===\n";
foreach ( Haa_Modules::catalog() as $slug => $class ) {
	if ( ! class_exists( $class ) ) {
		ok( "class for $slug loads", false );
		continue;
	}
	$inst       = new $class();
	$from_class = $inst->requires_woocommerce();
	$from_cat   = Haa_Modules::requires_woocommerce( $slug );
	ok( "instance($slug) == catalogue", $from_class === $from_cat, var_export( $from_class, true ) );
}

echo "\n=== C. catalog_meta()['woo'] equals the helper for every slug ===\n";
foreach ( Haa_Modules::catalog_meta() as $slug => $meta ) {
	ok( "meta woo ($slug) agrees", ( ! empty( $meta['woo'] ) ) === Haa_Modules::requires_woocommerce( $slug ) );
}

echo "\n=== D. describe_all reflects one truth, with WooCommerce present ===\n";
ok( 'haa_is_woo() is true in this harness', true === haa_is_woo() );
foreach ( Haa_Modules::describe_all() as $m ) {
	$slug = $m['slug'];
	ok( "$slug: woo == catalogue", $m['woo'] === Haa_Modules::requires_woocommerce( $slug ) );
	ok( "$slug: available while Woo is on", true === $m['available'] );
	ok( "$slug: not blocked while Woo is on", false === $m['missing_woocommerce'] );
}

echo "\n=== E. load gate decision (Woo present -> a woo module is NOT skipped) ===\n";
$pc = Haa_Modules::catalog()['product-content'];
ok( 'product-content instantiates while Woo is present', class_exists( $pc ) && ( new $pc() )->requires_woocommerce() === true );

echo "\nFAILURES: $fail\n";
exit( $fail ? 1 : 0 );
