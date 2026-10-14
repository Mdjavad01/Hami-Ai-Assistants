#!/usr/bin/env bash
# Runner for dev-tests/settings-tools-e2e.cjs (Batch A2).
# Creates the subscriber used by the capability test, runs the browser suite,
# then deletes it and puts the bench back to base. haai-test only.
set -u

PHP="C:/xampp/php/php.exe"
WPCLI="C:/xampp/wp-cli/wp-cli.phar"
BENCH="C:/xampp/htdocs/haai-test"
NODE="C:/Users/user/.workbuddy-ai/binaries/node/versions/22.22.2-6/node.exe"
PLUGIN="D:/work-webs/0 Build/Hami-Chat-AI/hami-ai-assistant"
WP="$PHP $WPCLI --path=$BENCH"

cd "$PLUGIN" || exit 1

echo "== bench base + a subscriber for the capability test =="
cd "$BENCH" || exit 1
$WP eval '
$s = Haa_Settings::defaults();
$s["general"]["enabled"] = 1;
$s["provider"]["allow_localhost"] = 1;
Haa_Settings::save_all( $s );
Haa_Settings::set_enabled_modules( array( "chatbot" => 1, "product-content" => 1 ) );
delete_option( Haa_Settings::SNAPSHOT_OPTION );
delete_transient( "haai_import_pending" );
Haa_Settings::flush_cache();
echo "base set\n";
' 2>&1 | tail -1

$WP user delete haai_low --yes 2>&1 | tail -1
$WP user create haai_low low-person@example.test --role=subscriber --user_pass=Low-2026-haai 2>&1 | tail -1
$WP user update haai_low --role=subscriber --user_pass=Low-2026-haai 2>&1 | tail -1
$WP eval 'echo "subscriber present: " . var_export( (bool) get_user_by( "login", "haai_low" ), true ) . "\n";' 2>&1 | tail -1

cd "$PLUGIN" || exit 1
echo "== running settings-tools-e2e.cjs =="
"$NODE" dev-tests/settings-tools-e2e.cjs
RC=$?

echo "== cleanup: subscriber gone, bench back to base =="
cd "$BENCH" || exit 1
$WP user delete haai_low --yes 2>&1 | tail -1
$WP eval '
$s = Haa_Settings::defaults();
$s["general"]["enabled"] = 1;
$s["provider"]["allow_localhost"] = 1;
Haa_Settings::save_all( $s );
Haa_Settings::set_enabled_modules( array( "chatbot" => 1, "product-content" => 1 ) );
delete_option( Haa_Settings::SNAPSHOT_OPTION );
delete_transient( "haai_import_pending" );
Haa_Settings::flush_cache();
echo "diagnose=" . count( Haa_Settings::diagnose() ) . " modules=" . json_encode( Haa_Settings::enabled_modules() ) . " snapshot=" . var_export( Haa_Settings::has_snapshot(), true ) . "\n";
' 2>&1 | tail -2

exit $RC
