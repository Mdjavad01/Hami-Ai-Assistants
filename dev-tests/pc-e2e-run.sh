#!/usr/bin/env bash
# Runner for dev-tests/pc-e2e.cjs: prepares the bench, serves the mock LLM on
# the IPv6 loopback (the sandbox proxy refuses 127.0.0.1), runs the test and
# puts the bench back. Everything here touches haai-test only.
set -u

PHP="C:/xampp/php/php.exe"
WPCLI="C:/xampp/wp-cli/wp-cli.phar"
BENCH="C:/xampp/htdocs/haai-test"
NODE="C:/Users/user/.workbuddy-ai/binaries/node/versions/22.22.2-6/node.exe"
PLUGIN="D:/work-webs/0 Build/Hami-Chat-AI/hami-ai-assistant"
PORT=8802

cd "$PLUGIN" || exit 1

echo "== mock on [::1]:$PORT =="
( "$PHP" -S "[::1]:$PORT" dev-tests/mock-api.php >/dev/null 2>&1 & )
sleep 1

echo "== bench: provider -> mock, one product without a preview =="
cd "$BENCH" || exit 1
"$PHP" "$WPCLI" eval '
$s = Haa_Settings::get();
$s["provider"]["base_url"]       = "http://[::1]:8802/v1";
$s["provider"]["api_key"]        = "mock-key";
$s["provider"]["model"]          = "mock-model";
$s["provider"]["allow_localhost"] = 1;
$s["provider"]["timeout"]        = 20;
$s["provider"]["retries"]        = 0;
$s["advanced"]["enable_rag"]     = 0;
$s["advanced"]["enable_cache"]   = 0;
update_option( HAAI_OPTION, $s, "no" );
Haa_Settings::set_enabled_modules( array( "chatbot" => 1, "product-content" => 1 ) );
Haa_Settings::flush_cache();

$ids = get_posts( array( "post_type" => "product", "post_status" => "publish", "posts_per_page" => 1, "fields" => "ids", "orderby" => "ID", "order" => "ASC" ) );
$pid = (int) $ids[0];
delete_transient( "haai_pc_preview_" . $pid );
set_transient( "haai_pc_last_product", $pid, DAY_IN_SECONDS );
echo "target product: $pid\n";
' 2>&1 | tail -1

PID=$("$PHP" "$WPCLI" eval '
$ids = get_posts( array( "post_type" => "product", "post_status" => "publish", "posts_per_page" => 1, "fields" => "ids", "orderby" => "ID", "order" => "ASC" ) );
echo (int) $ids[0];
' 2>/dev/null | tail -1)

cd "$PLUGIN" || exit 1
echo "== running pc-e2e.cjs (product $PID) =="
"$NODE" dev-tests/pc-e2e.cjs "$PID"
RC=$?

echo "== bench restored =="
cd "$BENCH" || exit 1
"$PHP" "$WPCLI" eval '
$s = Haa_Settings::get();
$s["provider"]["base_url"]        = "";
$s["provider"]["api_key"]         = "";
$s["provider"]["allow_localhost"] = 0;
update_option( HAAI_OPTION, $s, "no" );
Haa_Settings::flush_cache();
echo "provider cleared\n";
' 2>&1 | tail -1

for pid in $(netstat -ano | grep -E ":$PORT" | awk '{print $NF}' | sort -u); do
	taskkill //PID "$pid" //F >/dev/null 2>&1
done
echo "mock stopped"
exit $RC
