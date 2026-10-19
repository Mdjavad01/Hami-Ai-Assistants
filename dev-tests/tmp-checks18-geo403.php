<?php
/**
 * Regression suite: the 403 geo-block message.
 *
 * A 403 from Gemini/xAI/OpenAI/Claude is almost never an account problem
 * when the site sits on an Iranian host — the provider refuses the IP
 * (the very same keys work on a local dev machine behind a VPN). The
 * admin used to see «سطح اکانت یا کلید را بررسی کنید» and went hunting
 * for a key that was fine. The mapper now recognises the three real
 * shapes of that wall and says «پراکسی یا رله» instead:
 *
 *   1. Google's generic HTML block page ("Your client does not have
 *      permission to get URL /v1beta/models/…") — exactly what a Gemini
 *      call from an Iranian host returns;
 *   2. Gemini's JSON geo error ("User location is not supported for the
 *      API use.");
 *   3. xAI's one-word body ("Forbidden") from a known-blocked host.
 *
 * Everything else keeps the honest account/key message: a 403 from
 * OpenRouter or a named "project does not have access" is NOT geo.
 *
 * Run: php dev-tests/tmp-checks18-geo403.php
 */
$GLOBALS['haai_use_real_api'] = true; // the REAL transport, not the stub fake
require __DIR__ . '/tmp-wp-stubs.php';

$fail = 0;
function ok( $label, $cond, $extra = '' ) {
        global $fail;
        if ( ! $cond ) { $fail++; }
        echo ( $cond ? '  ok   ' : '  FAIL ' ) . str_pad( $label, 58 ) . $extra . "\n";
}

$rc  = new ReflectionClass( 'Haa_API' );
$m   = $rc->getMethod( 'map_http_error' );
$m->setAccessible( true );
$api = new Haa_API( array( 'base_url' => 'https://generativelanguage.googleapis.com/v1beta' ) );

function map403( $m, $api, $base_url, $json, $raw ) {
        $api->with_config( array( 'base_url' => $base_url ) );
        $err = $m->invoke( $api, 403, $json, $raw );
        return is_wp_error( $err ) ? $err->get_error_message() : '';
}

echo "=== A. the three real geo shapes ===\n";

// A1 — the user's exact report: Google's HTML wall for /v1beta/models/…:generateContent.
$html = '<!DOCTYPE html><html lang=en><meta charset=utf-8><title>Error 403 (Forbidden)!!1</title>'
        . '<p>403. That&rsquo;s an error. Your client does not have permission to get URL '
        . '/v1beta/models/gemini-3.5-flash-lite:generateContent from this server. That&rsquo;s all we know.</p>';
$msg = map403( $m, $api, 'https://generativelanguage.googleapis.com/v1beta', array(), $html );
ok( 'Google HTML wall -> geo message', false !== strpos( $msg, 'IP ایران' ), mb_substr( $msg, 0, 60 ) . '…' );
ok( 'geo message does NOT blame the key', false === strpos( $msg, 'سطح اکانت' ) );

// A2 — Gemini JSON geo error.
$msg = map403( $m, $api, 'https://generativelanguage.googleapis.com/v1beta', array( 'error' => array( 'code' => 403, 'message' => 'User location is not supported for the API use.', 'status' => 'PERMISSION_DENIED' ) ), wp_json_encode( array( 'error' => array( 'message' => 'User location is not supported for the API use.' ) ) ) );
ok( 'Gemini JSON "location is not supported" -> geo', false !== strpos( $msg, 'IP ایران' ) );

// A3 — xAI's bare "Forbidden" body (the whole payload).
$msg = map403( $m, $api, 'https://api.x.ai/v1', array(), 'Forbidden' );
ok( 'xAI bare Forbidden on api.x.ai -> geo', false !== strpos( $msg, 'IP ایران' ) );

echo "=== B. honest account-level 403s stay generic ===\n";

// B1 — same one-word body but from a relay that does NOT block Iran.
$msg = map403( $m, $api, 'https://openrouter.ai/api/v1', array(), 'Forbidden' );
ok( 'bare Forbidden on OpenRouter -> account msg', false !== strpos( $msg, 'سطح اکانت' ) );

// B2 — a named account error from a known-blocked host is still the key message.
$msg = map403( $m, $api, 'https://api.openai.com/v1', array( 'error' => array( 'message' => 'Project proj_1 does not have access to model gpt-4o.' ) ), '' );
ok( 'named project error on api.openai.com -> account msg', false !== strpos( $msg, 'سطح اکانت' ) );

// B3 — a key-restriction 403 from Gemini is not geo either.
$msg = map403( $m, $api, 'https://generativelanguage.googleapis.com/v1beta', array( 'error' => array( 'message' => 'API key not authorized. Please check the referrer restrictions.' ) ), '' );
ok( 'key restriction on Gemini -> account msg', false !== strpos( $msg, 'سطح اکانت' ) );

echo "=== C. detail still travels, masked ===\n";

// C1 — the geo message keeps the (masked, HTML-stripped) provider detail for the log.
$msg = map403( $m, $api, 'https://generativelanguage.googleapis.com/v1beta', array(), $html );
ok( 'geo message carries detail', false !== strpos( $msg, 'does not have permission' ) );
ok( 'detail is HTML-stripped', false === strpos( $msg, '<p>' ) && false === strpos( $msg, 'title>' ) );
ok( 'error code kept', 'haai_http_403' === $m->invoke( $api, 403, array(), 'Forbidden' )->get_error_code() );

// ---------------------------------------------------------------------------
// D. 2.1.35 — a 429 that is a quota wall says so, and sheds the English.
//     Live report on 2.1.34: the panel's «مدل:» line showed «سرویس موقتاً
//     درخواست‌ها را محدود کرده است» followed by Gemini's raw paragraph —
//     «You exceeded your current quota … plan and billing details … docs
//     URL …» — cut mid-word. A quota ceiling is not a wait-a-second
//     throttle: the line must name the quota and the two real fixes
//     (wait in minutes / change plan or key) without the raw wall.
// ---------------------------------------------------------------------------
function map429( $m, $api, $json, $raw ) {
        $err = $m->invoke( $api, 429, $json, $raw );
        return is_wp_error( $err ) ? $err->get_error_message() : '';
}

echo "\n=== D. 2.1.35 — quota-shaped 429s get the quota line ===\n";

// D1 — the user's exact report: Gemini's free-tier quota wall.
$gem_d = 'You exceeded your current quota, please check your plan and billing details. '
        . 'For more information on this error, head to: https://ai.google.dev/gemini-api/docs/rate-limits.';
$msg = map429( $m, $api, array( 'error' => array( 'code' => 429, 'message' => $gem_d, 'status' => 'RESOURCE_EXHAUSTED' ) ), '' );
ok( 'Gemini quota wall -> the quota line', false !== strpos( $msg, 'سهمیهٔ کلید مدل پر شده است' ), mb_substr( $msg, 0, 50 ) . '…' );
ok( 'the quota line sheds the raw English wall', false === strpos( $msg, 'You exceeded' ) && false === strpos( $msg, ' — ' ), '' );
ok( 'the quota line says the two real fixes', false !== strpos( $msg, 'ارتقا دهید' ) && false !== strpos( $msg, 'کلید/مدل دیگری' ), '' );

// D2 — OpenRouter-style per-minute wording maps to the quota line too.
$msg = map429( $m, $api, array( 'error' => array( 'message' => 'Rate limit exceeded: free-model requests per minute limit reached.' ) ), '' );
ok( 'per-minute rate-limit wording -> quota line', false !== strpos( $msg, 'سهمیهٔ کلید مدل پر شده است' ), '' );

// D3 — a plain throttle stays the generic transient sentence, detail intact.
$msg = map429( $m, $api, array( 'error' => array( 'message' => 'Secret sk-live-ZZZ1234567890yyyAAA is invalid for model capture-model' ) ), '' );
ok( 'non-quota 429 keeps the generic sentence', false !== strpos( $msg, 'سرویس موقتاً درخواست‌ها را محدود کرده است' ), '' );
ok( 'non-quota 429 keeps the masked detail', false !== strpos( $msg, ' — ' ) && false !== strpos( $msg, '***' ), '' );
ok( 'non-quota 429 does NOT claim a quota', false === strpos( $msg, 'سهمیهٔ کلید مدل' ), '' );

echo "\n" . ( $fail ? "FAILURES: $fail\n" : "ALL OK\n" );
exit( $fail ? 1 : 0 );
