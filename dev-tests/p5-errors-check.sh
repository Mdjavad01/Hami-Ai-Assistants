#!/usr/bin/env bash
# Real-WP check for Batch C1 / P5: every failure mode must come back as a clear
# Persian message with an actionable hint, produced by the REAL Haa_API transport
# (the stub harness fakes Haa_API away, so this can only run against WordPress).
# Drives the mock LLM on [::1]:8802. haai-test only; restores the provider.
set -u

PHP="C:/xampp/php/php.exe"
WPCLI="C:/xampp/wp-cli/wp-cli.phar"
BENCH="C:/xampp/htdocs/haai-test"
PORT=8802
WP="$PHP $WPCLI --path=$BENCH"
fail=0

chk() { if [ "$2" = "1" ]; then echo "  ok   $1"; else echo "  FAIL $1"; fail=$((fail+1)); fi; }

cd "$(dirname "$0")/.." || exit 1
( "$PHP" -S "[::1]:$PORT" dev-tests/mock-api.php >/dev/null 2>&1 & )
sleep 1

echo "== failure messages through the real transport =="
OUT=$($WP eval '
function haai_try( $label, $model, $proxy = "", $timeout = 5, $key = "mock-key", $base = "http://[::1]:'"$PORT"'/v1", $provider = "openai" ) {
	$s = Haa_Settings::get();
	$s["provider"]["provider"]        = $provider;
	$s["provider"]["base_url"]        = $base;
	$s["provider"]["api_key"]         = $key;
	$s["provider"]["model"]          = $model;
	$s["provider"]["allow_localhost"] = 1;
	$s["provider"]["timeout"]         = $timeout;
	$s["provider"]["retries"]         = 0;
	$s["provider"]["proxy_host"]      = $proxy;
	$s["provider"]["proxy_port"]      = $proxy ? "8080" : "";
	update_option( HAAI_OPTION, $s, "no" );
	Haa_Settings::flush_cache();
	$res = Haa_Content::product_brief( "کفش چرم تست", array( "نام" => "کفش چرم تست" ), array(), array( "module" => "product-content", "operation" => "product-content" ) );
	$msg = empty( $res["ok"] ) ? (string) $res["error"] : "(no error)";
	echo "### " . $label . " ###" . "\n" . $msg . "\n\n";
}
haai_try( "http504", "mock-model-504" );
haai_try( "http503", "mock-model-503" );
haai_try( "emptyreply", "mock-model-empty" );
haai_try( "timeout", "mock-model-timeout", "", 5 );
haai_try( "proxydead", "mock-model", "http://haai-no-such-proxy.invalid" );
haai_try( "nokey", "mock-model", "", 5, "" );
// "custom" carries no preset, so an empty base URL is not silently filled in
// and the request never leaves the machine.
haai_try( "nobase", "mock-model", "", 5, "mock-key", "", "custom" );
' 2>&1)
echo "$OUT"

get_case() { echo "$OUT" | awk -v l="### $1 ###" 'index($0,l){f=1;next} f&&/^### /{exit} f&&NF{print}' | tr "\n" " "; }

echo "== assertions =="
M=$(get_case http504)
chk "504 names the status (got: $(echo "$M" | cut -c1-60))" "$(echo "$M" | grep -q "۵۰۴" && echo 1 || echo 0)"
chk "504 tells the user to retry" "$(echo "$M" | grep -q "دوباره بزنید" && echo 1 || echo 0)"
M=$(get_case http503)
chk "503 says the service is temporarily unavailable" "$(echo "$M" | grep -q "۵۰۲/۵۰۳" && echo 1 || echo 0)"
M=$(get_case emptyreply)
chk "empty reply is described in Persian" "$(echo "$M" | grep -q "خالی یا بی‌ساختار" && echo 1 || echo 0)"
chk "empty reply gives a hint" "$(echo "$M" | grep -q "بررسی کنید" && echo 1 || echo 0)"
M=$(get_case timeout)
chk "timeout is described in Persian" "$(echo "$M" | grep -q "مهلت اتصال" && echo 1 || echo 0)"
chk "timeout does NOT dump the raw cURL text" "$(echo "$M" | grep -qi "cURL" && echo 0 || echo 1)"
M=$(get_case proxydead)
chk "proxy failure names the proxy (not a generic error)" "$(echo "$M" | grep -q "پراکسی" && echo 1 || echo 0)"
chk "proxy failure does NOT dump raw cURL detail" "$(echo "$M" | grep -qi "Could not resolve" && echo 0 || echo 1)"
chk "proxy failure tells to check provider settings" "$(echo "$M" | grep -q "بررسی کنید" && echo 1 || echo 0)"
M=$(get_case nokey)
chk "missing key points at the provider tab" "$(echo "$M" | grep -q "ارائه‌دهنده AI" && echo 1 || echo 0)"
M=$(get_case nobase)
chk "missing base URL is explained" "$(echo "$M" | grep -q "Base URL" && echo 1 || echo 0)"

echo "== restore provider =="
$WP eval '
$s = Haa_Settings::get();
$s["provider"]["provider"] = "openai";
$s["provider"]["base_url"] = ""; $s["provider"]["api_key"] = ""; $s["provider"]["model"] = "";
$s["provider"]["proxy_host"] = ""; $s["provider"]["proxy_port"] = ""; $s["provider"]["allow_localhost"] = 1;
update_option( HAAI_OPTION, $s, "no" ); Haa_Settings::flush_cache(); echo "provider cleared, allow_localhost=1\n";
' 2>&1 | tail -1

for pid in $(netstat -ano | grep -E ":$PORT" | awk '{print $NF}' | sort -u); do
	taskkill //PID "$pid" //F >/dev/null 2>&1
done
echo "mock stopped"
echo "SUMMARY failures=$fail"
exit $fail
