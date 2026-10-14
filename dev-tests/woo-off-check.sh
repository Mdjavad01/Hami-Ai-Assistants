#!/usr/bin/env bash
# Real-bench check for Batch B (M3): with WooCommerce deactivated and every
# module switched on, nothing fatals, the WooCommerce-only modules stay fully
# dormant, and their stored "on" flag survives. Then WooCommerce is switched
# back on and the modules wake up on their own. haai-test only; restores base.
set -u

PHP="C:/xampp/php/php.exe"
WPCLI="C:/xampp/wp-cli/wp-cli.phar"
BENCH="C:/xampp/htdocs/haai-test"
URL="http://localhost/haai-test"
WP="$PHP $WPCLI --path=$BENCH"

fail=0
note() { echo "  $1"; }
chk() { # label  condition-result(0/1)
	if [ "$2" = "1" ]; then echo "  ok   $1"; else echo "  FAIL $1"; fail=$((fail+1)); fi
}

cd "$BENCH" || exit 1

echo "== turn EVERY module on (WooCommerce still present) =="
$WP eval '
Haa_Settings::set_enabled_modules( array_fill_keys( array_keys( Haa_Modules::catalog() ), 1 ) );
echo "modules_on=" . count( Haa_Settings::enabled_modules() ) . "\n";
' 2>&1 | tail -1

echo "== deactivate WooCommerce =="
$WP plugin deactivate woocommerce 2>&1 | tail -1
$WP eval 'echo "haa_is_woo=" . var_export( haa_is_woo(), true ) . "\n";' 2>&1 | tail -1

echo "== boot with every module on + Woo off: which modules actually loaded? =="
LOADED=$($WP eval '
$loaded = array_keys( Haa_Plugin::instance()->modules->loaded() );
echo implode( ",", $loaded );
' 2>/dev/null | tail -1)
note "loaded slugs: $LOADED"
case "$LOADED" in
	*product-content*) chk "product-content stays DORMANT (not loaded)" 0 ;;
	*)                 chk "product-content stays DORMANT (not loaded)" 1 ;;
esac
case "$LOADED" in
	*discount*) chk "discount stays DORMANT (not loaded)" 0 ;;
	*)          chk "discount stays DORMANT (not loaded)" 1 ;;
esac
echo "$LOADED" | grep -q "chatbot" && chk "chatbot still loads" 1 || chk "chatbot still loads" 0

echo "== stored flags are NOT cleared by the missing WooCommerce =="
$WP eval '
$on = Haa_Settings::enabled_modules();
echo "still_on_product_content=" . ( empty( $on["product-content"] ) ? 0 : 1 ) . "\n";
echo "still_on_discount=" . ( empty( $on["discount"] ) ? 0 : 1 ) . "\n";
echo "count=" . count( $on ) . "\n";
' 2>&1 | tail -3

echo "== describe_all marks them blocked =="
$WP eval '
foreach ( Haa_Modules::describe_all() as $m ) {
	if ( $m["slug"] === "product-content" || $m["slug"] === "discount" ) {
		echo $m["slug"] . " missing=" . var_export( $m["missing_woocommerce"], true ) . " available=" . var_export( $m["available"], true ) . "\n";
	}
}
' 2>&1 | tail -2

echo "== admin screen loads without fatal/notice =="
ADMIN=$(curl -s -o /dev/null -w "%{http_code}" "$URL/wp-admin/index.php")
chk "wp-admin HTTP is not 500 (got $ADMIN)" "$([ "$ADMIN" != "500" ] && echo 1 || echo 0)"

echo "== front-end page renders without PHP error strings =="
BODY=$(curl -s "$URL/")
ERR=$(echo "$BODY" | grep -ciE "Fatal error|Uncaught|Warning:|Notice:|Deprecated:")
chk "front page has no PHP error text (matches=$ERR)" "$([ "$ERR" = "0" ] && echo 1 || echo 0)"

echo "== REST token endpoint responds (chat path) without fatal =="
TOK=$(curl -s -o /dev/null -w "%{http_code}" -X POST "$URL/wp-json/hami-ai/v1/token")
chk "REST /token not 500 (got $TOK)" "$([ "$TOK" != "500" ] && echo 1 || echo 0)"

echo "== cron drain runs without fatal =="
$WP cron event run --due-now 2>&1 | tail -1

echo "== reactivate WooCommerce: modules wake up on their own =="
$WP plugin activate woocommerce 2>&1 | tail -1
$WP eval 'echo "haa_is_woo=" . var_export( haa_is_woo(), true ) . "\n";' 2>&1 | tail -1
LOADED2=$($WP eval 'echo implode( ",", array_keys( Haa_Plugin::instance()->modules->loaded() ) );' 2>/dev/null | tail -1)
note "loaded after Woo returns: $LOADED2"
echo "$LOADED2" | grep -q "product-content" && chk "product-content auto-loaded again" 1 || chk "product-content auto-loaded again" 0
echo "$LOADED2" | grep -q "discount" && chk "discount auto-loaded again" 1 || chk "discount auto-loaded again" 0

echo "== plugin activate/deactivate without WooCommerce (fatal check) happens separately =="

# restore bench base modules
$WP eval '
Haa_Settings::set_enabled_modules( array( "chatbot" => 1, "product-content" => 1 ) );
echo "base restored: " . implode( ",", array_keys( Haa_Settings::enabled_modules() ) ) . "\n";
' 2>&1 | tail -1

echo "SUMMARY failures=$fail"
exit $fail
