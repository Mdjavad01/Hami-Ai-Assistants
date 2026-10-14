#!/usr/bin/env bash
# Accept/reject matrix for the settings-write guard (Batch A2, real WordPress).
#
# Every destructive action on the settings screen goes through
# Haa_Util::guard_admin_post(): nonce first, then manage_options. Each case
# runs the real guard and then checks whether the marker line was printed —
# reaching it would mean the guard let the call through — and that the stored
# settings were not touched.
#
#   A  administrator, no nonce       -> refused
#   B  subscriber, own valid nonce   -> refused (capability branch)
#   C  administrator, valid nonce    -> allowed
#
# haai-test only; the temporary subscriber is created and removed here.
set -u

PHP="C:/xampp/php/php.exe"
WPCLI="C:/xampp/wp-cli/wp-cli.phar"
BENCH="C:/xampp/htdocs/haai-test"
WP="$PHP $WPCLI --path=$BENCH"
fail=0

chk() { if [ "$2" = "1" ]; then echo "  ok   $1"; else echo "  FAIL $1"; fail=$((fail+1)); fi; }
reached() { echo "$1" | grep -q "GUARD-PASSED" && echo 0 || echo 1; }
unchanged() { $WP eval 'echo Haa_Settings::get("provider.model") === "probe-model" ? 1 : 0;' 2>/dev/null | tail -1; }

cd "$BENCH" || exit 1
$WP user delete haai_low --yes >/dev/null 2>&1
$WP user create haai_low low-person@example.test --role=subscriber --user_pass=Low-2026-haai >/dev/null 2>&1
$WP eval '
$s = Haa_Settings::get();
$s["general"]["enabled"] = 1;
$s["provider"]["model"] = "probe-model";
Haa_Settings::save_all( $s );
Haa_Settings::flush_cache();
echo "seeded\n";
' >/dev/null 2>&1

echo "== A. administrator without a nonce =="
A=$($WP eval '
wp_set_current_user( (int) get_user_by( "login", "haai_probe" )->ID );
$_POST = $_REQUEST = array( "action" => "haai_reset", "confirm_word" => "بازنشانی" );
Haa_Util::guard_admin_post();
echo "GUARD-PASSED\n";
' 2>&1)
chk "the nonce-less call was refused" "$(reached "$A")"
chk "nothing was written by it" "$(unchanged)"

echo "== B. subscriber with a nonce that is valid for them =="
B=$($WP eval '
wp_set_current_user( (int) get_user_by( "login", "haai_low" )->ID );
$nonce = wp_create_nonce( "haai_admin" );
$_POST = $_REQUEST = array( "action" => "haai_reset", "confirm_word" => "بازنشانی", "_wpnonce" => $nonce );
echo "nonce_valid=" . var_export( (bool) wp_verify_nonce( $nonce, "haai_admin" ), true )
	. " can_manage=" . var_export( current_user_can( "manage_options" ), true ) . "\n";
Haa_Util::guard_admin_post();
echo "GUARD-PASSED\n";
' 2>&1)
echo "$B" | grep "nonce_valid=" | sed 's/^/    /'
chk "the nonce itself validated (so the rejection is about the capability)" "$(echo "$B" | grep -q 'nonce_valid=true' && echo 1 || echo 0)"
chk "the user really lacks manage_options" "$(echo "$B" | grep -q 'can_manage=false' && echo 1 || echo 0)"
chk "the guard refused the write" "$(reached "$B")"
chk "settings still untouched after B" "$(unchanged)"

echo "== C. administrator with a valid nonce (accept path) =="
C=$($WP eval '
wp_set_current_user( (int) get_user_by( "login", "haai_probe" )->ID );
$nonce = wp_create_nonce( "haai_admin" );
$_POST = $_REQUEST = array( "action" => "haai_reset", "confirm_word" => "بازنشانی", "_wpnonce" => $nonce );
Haa_Util::guard_admin_post();
echo "GUARD-PASSED\n";
' 2>&1)
chk "the legitimate call is allowed through" "$(echo "$C" | grep -q "GUARD-PASSED" && echo 1 || echo 0)"

echo "== restore bench =="
$WP eval '
Haa_Settings::reset();
$s = Haa_Settings::defaults();
$s["general"]["enabled"] = 1;
$s["provider"]["allow_localhost"] = 1;
Haa_Settings::save_all( $s );
Haa_Settings::set_enabled_modules( array( "chatbot" => 1, "product-content" => 1 ) );
delete_option( Haa_Settings::SNAPSHOT_OPTION );
Haa_Settings::flush_cache();
echo "modules=" . json_encode( Haa_Settings::enabled_modules() ) . " diagnose=" . count( Haa_Settings::diagnose() ) . "\n";
' 2>&1 | tail -1
$WP user delete haai_low --yes 2>&1 | tail -1

echo "SUMMARY failures=$fail"
exit $fail
