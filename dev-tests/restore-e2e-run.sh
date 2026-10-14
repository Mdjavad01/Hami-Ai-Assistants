#!/usr/bin/env bash
# Runner for dev-tests/restore-e2e.cjs: seeds a distinctive live config, runs
# reset() so a safety snapshot exists (live values drop to defaults), drives the
# restore button in the browser, then puts the bench back. haai-test only.
set -u

PHP="C:/xampp/php/php.exe"
WPCLI="C:/xampp/wp-cli/wp-cli.phar"
BENCH="C:/xampp/htdocs/haai-test"
NODE="C:/Users/user/.workbuddy-ai/binaries/node/versions/22.22.2-6/node.exe"
PLUGIN="D:/work-webs/0 Build/Hami-Chat-AI/hami-ai-assistant"

cd "$PLUGIN" || exit 1

echo "== bench: seed a live config, then reset() to leave a snapshot =="
cd "$BENCH" || exit 1
"$PHP" "$WPCLI" eval '
delete_option( Haa_Settings::SNAPSHOT_OPTION );
$s = Haa_Settings::defaults();
$s["general"]["enabled"] = 1;
$s["provider"]["allow_localhost"] = 1;
$s["provider"]["api_key"] = Haa_Settings::encrypt( "sk-restore-e2e-keepme" );
$s["provider"]["model"] = "e2e-restore-model";
$s["appearance"]["primary_color"] = "#abcdef";
Haa_Settings::save_all( $s );
Haa_Settings::set_enabled_modules( array( "chatbot" => 1, "product-content" => 1 ) );
Haa_Settings::flush_cache();
// reset() snapshots the live values above, then wipes to defaults.
Haa_Settings::reset();
echo "snapshot_present=" . var_export( Haa_Settings::has_snapshot(), true ) . "\n";
' 2>&1 | tail -2

cd "$PLUGIN" || exit 1
echo "== running restore-e2e.cjs =="
"$NODE" dev-tests/restore-e2e.cjs
RC=$?

echo "== bench restored to base =="
cd "$BENCH" || exit 1
"$PHP" "$WPCLI" eval '
$s = Haa_Settings::defaults();
$s["general"]["enabled"] = 1;
$s["provider"]["allow_localhost"] = 1;
Haa_Settings::save_all( $s );
Haa_Settings::set_enabled_modules( array( "chatbot" => 1, "product-content" => 1 ) );
delete_option( Haa_Settings::SNAPSHOT_OPTION );
Haa_Settings::flush_cache();
echo "provider cleared, modules base\n";
' 2>&1 | tail -1

exit $RC
