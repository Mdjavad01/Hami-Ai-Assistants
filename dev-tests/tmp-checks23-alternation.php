<?php
/**
 * Regression suite: role-alternation + extraction + diagnostics.
 *
 * The user's live report on 2.1.11: "تست اتصال" answered green
 * (1023ms, gemini-3.5-flash-lite), "دریافت مدل‌ها" listed every model,
 * but the real visitor chat always said «پاسخ‌دهنده‌ی هوشمند فعلاً در
 * دسترس نیست». The probe ships a single 'ping' with no history, while
 * the visitor session carries history — and after every failed turn the
 * widget stores user,user back to back (the assistant reply that would
 * alternate the roles never arrived). Gemini v1beta enforces strict
 * user/model alternation and answers 400 "Please ensure that multiturn
 * requests alternate between user and model", so the bot looked dead
 * while the admin probe stayed green. Locked here:
 *
 *   1. Gemini body merges consecutive same-role turns (extra part in one
 *      content), drops leading model turns and empty texts;
 *   2. healthy alternation passes through untouched, systemInstruction intact;
 *   3. Gemini extract walks every part (multi-part answers joined,
 *      thought parts skipped, thought-only = false);
 *   4. Claude body merges the same way; extract walks blocks and reads a
 *      string content;
 *   5. invalid UTF-8 in a turn can no longer produce an empty/false body
 *      (JSON_INVALID_UTF8_SUBSTITUTE on all three chat adapters);
 *   6. request_once refuses an empty body with haai_body instead of an
 *      opaque provider 400;
 *   7. end-to-end: the poisoned user,user,user session now answers
 *      through the real transport, and the sent body is merged;
 *   8. the last chat failure (code + message + model) lands in the
 *      haai_last_chat_error transient for the provider tab, and the next
 *      healthy chat deletes it; haai_all_benched writes it too;
 *   9. a 200 with promptFeedback.blockReason / a non-STOP finishReason
 *      names the reason instead of a bare "empty reply".
 *
 * Run: php dev-tests/tmp-checks23-alternation.php
 */
$GLOBALS['haai_use_real_api'] = true; // the REAL transport, not the stub fake
require __DIR__ . '/tmp-wp-stubs.php';

$fail = 0;
function ok( $label, $cond, $extra = '' ) {
        global $fail;
        if ( ! $cond ) { $fail++; }
        echo ( $cond ? '  ok   ' : '  FAIL ' ) . str_pad( $label, 58 ) . $extra . "\n";
}

function ck( $base, $model ) {
        return 'haai_fb_' . substr( md5( $base . '|' . $model ), 0, 32 );
}

$mk = function ( $over = array() ) {
        return array_merge( array(
                'provider' => 'gemini', 'adapter' => 'gemini', 'base_url' => 'https://relay.example/v1beta',
                'api_key' => 'gk-main', 'model' => 'gemini-3.5-flash-lite', 'timeout' => 30, 'retries' => 0,
                'temperature' => 0.7, 'max_tokens' => 2000, 'proxy_host' => '', 'failovers' => array(),
        ), $over );
};

$gemini_ok = function ( $text ) {
        return array( 'code' => 200, 'body' => wp_json_encode( array(
                'candidates' => array( array(
                        'content'      => array( 'role' => 'model', 'parts' => array( array( 'text' => $text ) ) ),
                        'finishReason' => 'STOP',
                ) ),
        ) ) );
};

$last_body = function () {
        $calls = http_requests();
        $raw   = (string) ( $calls[ count( $calls ) - 1 ]['args']['body'] ?? '' );
        $decoded = json_decode( $raw, true );
        return is_array( $decoded ) ? $decoded : null;
};

/* ------------------------------------------------------------------ */
echo "=== A. Gemini body: merge + leading model + empty text ===\n";

$gem = new Haa_Adapter_Gemini( $mk() );
$body = json_decode( (string) $gem->body( array(
        array( 'role' => 'system', 'content' => 'SYS' ),
        array( 'role' => 'user', 'content' => 'سؤال اول' ),
        array( 'role' => 'user', 'content' => 'سؤال دوم' ), // failed turn: no assistant reply between.
        array( 'role' => 'user', 'content' => 'سؤال سوم' ),
), array( 'temperature' => 0.7, 'max_tokens' => 2000, 'model' => 'gemini-3.5-flash-lite' ) ), true );

ok( 'body is valid JSON', is_array( $body ) );
ok( 'system prompt rides systemInstruction', isset( $body['systemInstruction']['parts'][0]['text'] ) && 'SYS' === $body['systemInstruction']['parts'][0]['text'] );
ok( 'user,user,user merged into ONE content', 1 === count( $body['contents'] ), 'got ' . count( $body['contents'] ) );
ok( 'merged content keeps role user', 'user' === ( $body['contents'][0]['role'] ?? '' ) );
ok( 'merged content carries 3 parts', 3 === count( $body['contents'][0]['parts'] ?? array() ), 'got ' . count( $body['contents'][0]['parts'] ?? array() ) );
ok( 'parts keep original order', 'سؤال اول' === $body['contents'][0]['parts'][0]['text'] && 'سؤال دوم' === $body['contents'][0]['parts'][1]['text'] && 'سؤال سوم' === $body['contents'][0]['parts'][2]['text'] );

$body = json_decode( (string) $gem->body( array(
        array( 'role' => 'assistant', 'content' => 'شروع مدل' ), // e.g. legacy session opener.
        array( 'role' => 'user', 'content' => 'ادامه' ),
        array( 'role' => 'assistant', 'content' => '' ),          // empty text must vanish.
        array( 'role' => 'user', 'content' => 'پایان' ),
), array( 'temperature' => 0.7, 'max_tokens' => 2000, 'model' => 'm' ) ), true );
ok( 'leading model turn dropped (first content = user)', 'user' === ( $body['contents'][0]['role'] ?? '' ) );
ok( 'empty text skipped + trailing users merged', 1 === count( $body['contents'] ) && 2 === count( $body['contents'][0]['parts'] ) && 'ادامه' === $body['contents'][0]['parts'][0]['text'], 'got ' . count( $body['contents'] ) );

$body = json_decode( (string) $gem->body( array(
        array( 'role' => 'system', 'content' => 'SYS' ),
        array( 'role' => 'user', 'content' => 'اول' ),
        array( 'role' => 'assistant', 'content' => 'پاسخ' ),
        array( 'role' => 'user', 'content' => 'دوم' ),
), array( 'temperature' => 0.7, 'max_tokens' => 2000, 'model' => 'm' ) ), true );
ok( 'healthy alternation stays 3 contents', 3 === count( $body['contents'] ) );
ok( 'healthy alternation roles intact', 'user' === $body['contents'][0]['role'] && 'model' === $body['contents'][1]['role'] && 'user' === $body['contents'][2]['role'] );

echo "\n=== B. Gemini extract: parts walk ===\n";
$g2 = new Haa_Adapter_Gemini( $mk() );
ok( 'single part still extracted', 'سلام' === $g2->extract( array( 'candidates' => array( array( 'content' => array( 'parts' => array( array( 'text' => 'سلام' ) ) ) ) ) ) ) );
ok( 'multi-part answer joined', 'متن یکمتن دو' === $g2->extract( array( 'candidates' => array( array( 'content' => array( 'parts' => array(
        array( 'text' => 'متن یک' ), array( 'text' => 'متن دو' ),
) ) ) ) ) ) );
ok( 'thought part skipped, text after it read', 'پاسخ واقعی' === $g2->extract( array( 'candidates' => array( array( 'content' => array( 'parts' => array(
        array( 'thought' => true, 'text' => 'فکر ذهنی مدل' ), array( 'text' => 'پاسخ واقعی' ),
) ) ) ) ) ) );
ok( 'thought-only response = false (haai_empty)', false === $g2->extract( array( 'candidates' => array( array( 'content' => array( 'parts' => array(
        array( 'thought' => true, 'text' => 'فقط فکر' ),
) ) ) ) ) ) );
ok( 'functionCall-only response = false', false === $g2->extract( array( 'candidates' => array( array( 'content' => array( 'parts' => array(
        array( 'functionCall' => array( 'name' => 'x' ) ),
) ) ) ) ) ) );
ok( 'no candidates = false', false === $g2->extract( array( 'promptFeedback' => array( 'blockReason' => 'SAFETY' ) ) ) );

echo "\n=== C. Claude body merge + extract blocks ===\n";
$cl = new Haa_Adapter_Claude( $mk( array( 'adapter' => 'claude', 'base_url' => 'https://claude.example/v1', 'model' => 'claude-x' ) ) );
$body = json_decode( (string) $cl->body( array(
        array( 'role' => 'system', 'content' => 'SYS' ),
        array( 'role' => 'user', 'content' => 'یک' ),
        array( 'role' => 'user', 'content' => 'دو' ),
        array( 'role' => 'assistant', 'content' => 'پاسخ' ),
        array( 'role' => 'user', 'content' => 'سه' ),
), array( 'temperature' => 0.5, 'max_tokens' => 1000, 'model' => 'claude-x' ) ), true );
ok( 'claude: user,user merged with newline', 1 === count( array_slice( $body['messages'], 0, 1 ) ) && false !== strpos( $body['messages'][0]['content'], "یک\nدو" ), 'got ' . count( $body['messages'] ) );
ok( 'claude: alternation otherwise intact', 3 === count( $body['messages'] ) );
ok( 'claude: system rides the system field', 'SYS' === ( $body['system'] ?? '' ) );

$cl2 = new Haa_Adapter_Claude( $mk( array( 'adapter' => 'claude', 'base_url' => 'https://claude.example/v1', 'model' => 'claude-x' ) ) );
$body = json_decode( (string) $cl2->body( array(
        array( 'role' => 'assistant', 'content' => 'اول از مدل' ),
        array( 'role' => 'user', 'content' => 'بعد' ),
), array( 'temperature' => 0.5, 'max_tokens' => 1000, 'model' => 'claude-x' ) ), true );
ok( 'claude: leading assistant dropped', 1 === count( $body['messages'] ) && 'user' === $body['messages'][0]['role'] );

ok( 'claude: thinking block first, text after read', 'پاسخ نهایی' === $cl2->extract( array( 'content' => array(
        array( 'type' => 'thinking', 'thinking' => 'فکر' ), array( 'type' => 'text', 'text' => 'پاسخ نهایی' ),
) ) ) );
ok( 'claude: plain string content still read', 'قدیمی' === $cl2->extract( array( 'content' => 'قدیمی' ) ) );
ok( 'claude: no text block = false', false === $cl2->extract( array( 'content' => array( array( 'type' => 'tool_use', 'id' => 't1' ) ) ) ) );

echo "\n=== D. invalid UTF-8 can no longer empty the body ===\n";
$poison = "کافه‌های رشت \xB1\x31 با متن خراب";
$b = $gem->body( array( array( 'role' => 'user', 'content' => $poison ) ), array( 'temperature' => 0.7, 'max_tokens' => 10, 'model' => 'm' ) );
ok( 'gemini: poisoned body is a non-empty JSON string', is_string( $b ) && '' !== trim( $b ) && null !== json_decode( (string) $b, true ) );
$dec = json_decode( (string) $b, true );
ok( 'gemini: poisoned text survived (substituted)', isset( $dec['contents'][0]['parts'][0]['text'] ) && '' !== (string) $dec['contents'][0]['parts'][0]['text'] );

$op = new Haa_Adapter_OpenAI( $mk( array( 'adapter' => 'openai', 'base_url' => 'https://openai.example/v1', 'model' => 'm' ) ) );
$b = $op->body( array( array( 'role' => 'user', 'content' => $poison ) ), array( 'temperature' => 0.7, 'max_tokens' => 10, 'model' => 'm' ) );
ok( 'openai: poisoned body still valid JSON', is_string( $b ) && null !== json_decode( (string) $b, true ) );

$b = $cl->body( array( array( 'role' => 'user', 'content' => $poison ) ), array( 'temperature' => 0.7, 'max_tokens' => 10, 'model' => 'm' ) );
ok( 'claude: poisoned body still valid JSON', is_string( $b ) && null !== json_decode( (string) $b, true ) );

echo "\n=== E. request_once refuses an empty body (haai_body) ===\n";
class Body_Less_Adapter extends Haa_Adapter_OpenAI {
        public function body( $messages, $opts ) { return false; } // the old json_encode-false world.
}
$api = new Haa_API( $mk( array( 'adapter' => 'openai', 'base_url' => 'https://openai.example/v1', 'model' => 'm' ) ) );
$ref = new ReflectionMethod( 'Haa_API', 'request_once' );
$ref->setAccessible( true );
$res = $ref->invoke( $api, new Body_Less_Adapter( $mk( array( 'adapter' => 'openai', 'base_url' => 'https://openai.example/v1', 'model' => 'm' ) ) ), array( array( 'role' => 'user', 'content' => 'x' ) ), array( 'temperature' => 0.7, 'max_tokens' => 10, 'model' => 'm' ) );
ok( 'empty body = haai_body, zero HTTP', is_wp_error( $res ) && 'haai_body' === $res->get_error_code(), is_wp_error( $res ) ? '' : 'got reply' );
ok( 'empty body made no HTTP call', 0 === count( http_requests() ) );

echo "\n=== F. end-to-end: poisoned session answers via real transport ===\n";
$GLOBALS['http_map'] = array( 'relay.example' => $gemini_ok( 'کافه‌های خوب رشت: ...' ) );
$api = new Haa_API( $mk() );
$GLOBALS['http_log'] = array();
$poisoned = array(
        array( 'role' => 'system', 'content' => 'تو دستیار هستی' ),
        array( 'role' => 'user', 'content' => 'سلام' ),
        array( 'role' => 'user', 'content' => 'کافه‌های رشت' ),   // previous failure left this un-paired.
        array( 'role' => 'user', 'content' => 'کافه‌های رشت کدوما خوبن؟' ),
);
$out = $api->chat( $poisoned, array( 'module' => 'chatbot' ) );
ok( 'poisoned user,user,user session now answers', is_string( $out ) && false !== strpos( (string) $out, 'کافه' ) );
$sent = $last_body();
ok( 'sent body: one merged user content', is_array( $sent ) && 1 === count( $sent['contents'] ) && 'user' === $sent['contents'][0]['role'] );
ok( 'sent body: 3 user texts as 3 parts', 3 === count( $sent['contents'][0]['parts'] ) );
ok( 'sent body: systemInstruction intact', 'تو دستیار هستی' === ( $sent['systemInstruction']['parts'][0]['text'] ?? '' ) );
ok( 'sent body: endpoint = relay /models/{model}:generateContent', false !== strpos( (string) http_requests()[0]['url'], 'https://relay.example/v1beta/models/gemini-3.5-flash-lite:generateContent' ), (string) http_requests()[0]['url'] );
ok( 'success cleared the last-error diagnostic', false === get_transient( 'haai_last_chat_error' ) );

echo "\n=== G. last-error diagnostic: failure writes, success clears ===\n";
$GLOBALS['http_map'] = array( 'relay.example' => array(
        'code' => 400,
        'body' => wp_json_encode( array( 'error' => array( 'code' => 400, 'message' => 'Please ensure that multiturn requests alternate between user and model', 'status' => 'INVALID_ARGUMENT' ) ) ),
) );
$GLOBALS['http_log'] = array();
$out = $api->chat( array( array( 'role' => 'user', 'content' => 'سلام' ) ), array( 'module' => 'chatbot' ) );
ok( '400 walked to WP_Error', is_wp_error( $out ) && 'haai_http_400' === $out->get_error_code() );
$err = get_transient( 'haai_last_chat_error' );
ok( 'diagnostic transient written', is_array( $err ) && 'haai_http_400' === ( $err['code'] ?? '' ) );
ok( 'diagnostic carries the provider sentence', false !== strpos( (string) ( $err['msg'] ?? '' ), 'alternate between user and model' ) );
ok( 'diagnostic carries the model', 'gemini-3.5-flash-lite' === ( $err['model'] ?? '' ) );
ok( 'diagnostic carries the timestamp', ( $err['at'] ?? 0 ) <= time() && ( $err['at'] ?? 0 ) > time() - 60 );

$GLOBALS['http_map'] = array( 'relay.example' => $gemini_ok( 'پاسخ سالم' ) );
unset( $GLOBALS['saved'][ ck( 'https://relay.example/v1beta', 'gemini-3.5-flash-lite' ) ] ); // the 400 benched the source — probe again.
$out = $api->chat( array( array( 'role' => 'user', 'content' => 'سلام' ) ), array( 'module' => 'chatbot' ) );
ok( 'next healthy chat succeeds', is_string( $out ) && 'پاسخ سالم' === $out );
ok( 'healthy chat cleared the diagnostic', false === get_transient( 'haai_last_chat_error' ) );

// All-benched fail-fast must also tell the truth.
$GLOBALS['saved'][ ck( 'https://relay.example/v1beta', 'gemini-3.5-flash-lite' ) ] = time() + 280;
$GLOBALS['http_log'] = array();
$out = $api->chat( array( array( 'role' => 'user', 'content' => 'سلام' ) ), array( 'module' => 'chatbot' ) );
ok( 'all-fresh bench fails fast', is_wp_error( $out ) && 'haai_all_benched' === $out->get_error_code() );
$err = get_transient( 'haai_last_chat_error' );
ok( 'all-benched wrote the diagnostic too', is_array( $err ) && 'haai_all_benched' === ( $err['code'] ?? '' ) && 0 === count( http_requests() ) );
unset( $GLOBALS['saved'][ ck( 'https://relay.example/v1beta', 'gemini-3.5-flash-lite' ) ] );

echo "\n=== H. empty 200 names the reason ===\n";
$unbench = function () { unset( $GLOBALS['saved'][ ck( 'https://relay.example/v1beta', 'gemini-3.5-flash-lite' ) ] ); }; // an empty 200 benches the source like any failure.
$GLOBALS['http_map'] = array( 'relay.example' => array(
        'code' => 200,
        'body' => wp_json_encode( array( 'promptFeedback' => array( 'blockReason' => 'PROHIBITED_CONTENT' ) ) ),
) );
$unbench();
$out = $api->chat( array( array( 'role' => 'user', 'content' => 'سلام' ) ), array( 'module' => 'chatbot' ) );
ok( 'blocked 200 = haai_empty with the block named', is_wp_error( $out ) && 'haai_empty' === $out->get_error_code() && false !== strpos( $out->get_error_message(), 'PROHIBITED_CONTENT' ), is_wp_error( $out ) ? '' : (string) $out );

$GLOBALS['http_map'] = array( 'relay.example' => array(
        'code' => 200,
        'body' => wp_json_encode( array( 'candidates' => array( array( 'finishReason' => 'MAX_TOKENS' ) ) ) ),
) );
$unbench();
$out = $api->chat( array( array( 'role' => 'user', 'content' => 'سلام' ) ), array( 'module' => 'chatbot' ) );
ok( 'MAX_TOKENS-empty 200 names the finishReason', is_wp_error( $out ) && false !== strpos( $out->get_error_message(), 'MAX_TOKENS' ), is_wp_error( $out ) ? '' : 'reply?!' );

echo "\n=== I. multi-part reply rides the real transport ===\n";
$unbench();
$GLOBALS['http_map'] = array( 'relay.example' => array( 'code' => 200, 'body' => wp_json_encode( array(
        'candidates' => array( array(
                'content'      => array( 'role' => 'model', 'parts' => array(
                        array( 'thought' => true, 'text' => 'بررسی کافه‌ها' ),
                        array( 'text' => 'کافه یزد ' ),
                        array( 'text' => 'بهترین است' ),
                ) ),
                'finishReason' => 'STOP',
        ) ),
) ) ) );
$out = $api->chat( array( array( 'role' => 'user', 'content' => 'سلام' ) ), array( 'module' => 'chatbot' ) );
ok( 'thought skipped + text parts joined', is_string( $out ) && 'کافه یزد بهترین است' === $out, is_string( $out ) ? '' : ( is_wp_error( $out ) ? $out->get_error_code() : '?' ) );

echo "\n";
if ( 0 === $fail ) {
        echo "ALL CHECKS PASSED\n";
} else {
        echo "FAILURES: $fail\n";
        exit( 1 );
}
