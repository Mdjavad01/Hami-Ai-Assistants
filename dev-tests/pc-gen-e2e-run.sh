#!/usr/bin/env bash
# Runner for dev-tests/pc-gen-e2e.cjs (Batch C1: P3 lock + P4 idempotent apply).
# Points the bench provider at the mock in its 6-second "slow" mode so a click
# has visible duration, clears preview/lock/marker for one product, runs the
# browser test and puts the bench back. haai-test only.
set -u

PHP="C:/xampp/php/php.exe"
WPCLI="C:/xampp/wp-cli/wp-cli.phar"
BENCH="C:/xampp/htdocs/haai-test"
NODE="C:/Users/user/.workbuddy-ai/binaries/node/versions/22.22.2-6/node.exe"
PLUGIN="D:/work-webs/0 Build/Hami-Chat-AI/hami-ai-assistant"
PORT=8802
WP="$PHP $WPCLI --path=$BENCH"

cd "$PLUGIN" || exit 1

echo "== mock on [::1]:$PORT (slow mode = 6 s per call) =="
( "$PHP" -S "[::1]:$PORT" dev-tests/mock-api.php >/dev/null 2>&1 & )
sleep 1

echo "== bench: provider -> mock slow, one clean product =="
cd "$BENCH" || exit 1
$WP eval '
$s = Haa_Settings::get();
$s["provider"]["base_url"]        = "http://[::1]:'"$PORT"'/v1";
$s["provider"]["api_key"]         = "mock-key";
$s["provider"]["model"]           = "mock-model-slow";
$s["provider"]["allow_localhost"] = 1;
$s["provider"]["timeout"]         = 30;
$s["provider"]["retries"]         = 0;
$s["advanced"]["enable_rag"]      = 0;
$s["advanced"]["enable_cache"]    = 0;
update_option( HAAI_OPTION, $s, "no" );
Haa_Settings::set_enabled_modules( array( "chatbot" => 1, "product-content" => 1 ) );
Haa_Settings::flush_cache();

$ids = get_posts( array( "post_type" => "product", "post_status" => "publish", "posts_per_page" => 1, "fields" => "ids", "orderby" => "ID", "order" => "ASC" ) );
$pid = (int) $ids[0];
delete_transient( "haai_pc_preview_" . $pid );
delete_transient( "haai_pc_gen_" . $pid );
delete_transient( "haai_pc_applied_" . $pid );
set_transient( "haai_pc_last_product", $pid, DAY_IN_SECONDS );
echo "target product: $pid\n";
' 2>&1 | tail -1

PID=$($WP eval '
$ids = get_posts( array( "post_type" => "product", "post_status" => "publish", "posts_per_page" => 1, "fields" => "ids", "orderby" => "ID", "order" => "ASC" ) );
echo (int) $ids[0];
' 2>/dev/null | tail -1)

cd "$PLUGIN" || exit 1
echo "== running pc-gen-e2e.cjs (product $PID) =="
"$NODE" dev-tests/pc-gen-e2e.cjs "$PID"
RC=$?

echo "== bench restored =="
cd "$BENCH" || exit 1
$WP eval '
$s = Haa_Settings::get();
$s["provider"]["base_url"] = ""; $s["provider"]["api_key"] = ""; $s["provider"]["model"] = "";
$s["provider"]["allow_localhost"] = 1;
update_option( HAAI_OPTION, $s, "no" );
delete_transient( "haai_pc_gen_'"$PID"'" );
delete_transient( "haai_pc_applied_'"$PID"'" );
Haa_Settings::flush_cache();
echo "provider cleared, allow_localhost=1, lock/marker dropped\n";
' 2>&1 | tail -1

for pid in $(netstat -ano | grep -E ":$PORT" | awk '{print $NF}' | sort -u); do
	taskkill //PID "$pid" //F >/dev/null 2>&1
done
echo "mock stopped"
exit $RC
