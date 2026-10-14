#!/usr/bin/env bash
# Runner for dev-tests/woo-modules-e2e.cjs. Puts the bench in the tested shape
# (WooCommerce off, every module on), runs the browser test, restores the bench
# (WooCommerce on, base modules). haai-test only.
set -u

PHP="C:/xampp/php/php.exe"
WPCLI="C:/xampp/wp-cli/wp-cli.phar"
BENCH="C:/xampp/htdocs/haai-test"
NODE="C:/Users/user/.workbuddy-ai/binaries/node/versions/22.22.2-6/node.exe"
PLUGIN="D:/work-webs/0 Build/Hami-Chat-AI/hami-ai-assistant"
WP="$PHP $WPCLI --path=$BENCH"

cd "$PLUGIN" || exit 1

echo "== setup: every module on, WooCommerce off =="
cd "$BENCH" || exit 1
$WP eval 'Haa_Settings::set_enabled_modules( array_fill_keys( array_keys( Haa_Modules::catalog() ), 1 ) ); echo "modules_on=" . count( Haa_Settings::enabled_modules() ) . "\n";' 2>&1 | tail -1
$WP plugin deactivate woocommerce 2>&1 | tail -1

cd "$PLUGIN" || exit 1
echo "== running woo-modules-e2e.cjs =="
"$NODE" dev-tests/woo-modules-e2e.cjs
RC=$?

echo "== restore: WooCommerce on, base modules =="
cd "$BENCH" || exit 1
$WP plugin activate woocommerce 2>&1 | tail -1
$WP eval 'Haa_Settings::set_enabled_modules( array( "chatbot" => 1, "product-content" => 1 ) ); echo "base=" . implode( ",", array_keys( Haa_Settings::enabled_modules() ) ) . "\n";' 2>&1 | tail -1

exit $RC
