<?php
/**
 * Regression suite: relay bench behaviour + builder-site retrieval.
 *
 * The user's live report on 2.1.10: (1) the bot went slow and then
 * "unavailable" — a failing provider was retried with the full backoff
 * budget even while freshly benched, and every retry RESTARTED the
 * 5-minute bench clock, so the "~N دقیقه تا بازگشت" badge kept lying and
 * recovery never arrived; (2) on their Elementor-built Gilan site the bot
 * still said "no data" about cafés — builder copy lives in
 * _elementor_data postmeta, where the post_content LIKE never looks.
 *
 * Locked here:
 *   1. a source benched < BENCH_PROBE_AFTER seconds gets NO attempt while
 *      anything else is available, and when nothing else is available the
 *      chat fails fast (haai_all_benched) with zero HTTP calls;
 *   2. a benched source at probe age gets exactly ONE attempt (no backoff);
 *   3. a failed probe does NOT restart the bench clock;
 *   4. a successful probe returns the source to full health immediately;
 *   5. image() reports haai_all_benched instead of faking success;
 *   6. relay Base-URL path contracts for groq/openrouter (worker side);
 *   7. ZWNJ-merged live search ("می‌خانه" found by the token "میخانه");
 *   8. the Elementor postmeta pass answers the café question on a
 *      builder-built site with empty post_content;
 *   9. build_source() indexes builder copy so the KB path works too;
 *  10. elementor_text() extraction (decoded tree + truncated-JSON regex).
 *
 * Run: php dev-tests/tmp-checks22-relaybench-elementor.php
 */
$GLOBALS['haai_use_real_api'] = true; // the REAL transport, not the stub fake
require __DIR__ . '/tmp-wp-stubs.php';

$fail = 0;
function ok( $label, $cond, $extra = '' ) {
        global $fail;
        if ( ! $cond ) { $fail++; }
        echo ( $cond ? '  ok   ' : '  FAIL ' ) . str_pad( $label, 58 ) . $extra . "\n";
}

function reset_kb_config() {
        $rc = new ReflectionClass( 'Haa_Search' );
        $p  = $rc->getProperty( 'config' );
        $p->setAccessible( true );
        $p->setValue( null, null );
}

function ck( $base, $model ) {
        return 'haai_fb_' . substr( md5( $base . '|' . $model ), 0, 32 );
}

function hosts_called() {
        $out = array();
        foreach ( (array) $GLOBALS['http_log'] as $call ) {
                if ( preg_match( '#https?://([^/]+)#', (string) ( $call['url'] ?? '' ), $m ) ) {
                        $out[] = $m[1];
                }
        }
        return $out;
}

$mk = function ( $over = array() ) {
        return array_merge( array(
                'provider' => 'openai', 'adapter' => 'openai', 'base_url' => 'https://primary.example/v1',
                'api_key' => 'pk-main', 'model' => 'm-main', 'timeout' => 30, 'retries' => 2,
                'temperature' => 0.7, 'max_tokens' => 1000, 'proxy_host' => '', 'failovers' => array(),
        ), $over );
};

$reply_body = function ( $text ) {
        return array( 'code' => 200, 'body' => wp_json_encode( array( 'choices' => array( array( 'message' => array( 'content' => $text ) ) ) ) ) );
};

echo "=== A. fresh bench: fail fast, zero HTTP ===\n";
$api = new Haa_API( $mk() );
$GLOBALS['http_map'] = array( 'primary.example' => array( 'error' => 'cURL error 28: timed out' ) );
$GLOBALS['http_log'] = array();
$out1 = $api->chat( array( array( 'role' => 'user', 'content' => 'ping' ) ), array( 'retries' => 0 ) );
ok( 'healthy failure benches the primary', is_wp_error( $out1 ) );
ok( 'bench set for 300s', Haa_API::cooldown_left( $mk() ) > 290 );
$GLOBALS['http_log'] = array();
$out2 = $api->chat( array( array( 'role' => 'user', 'content' => 'ping' ) ), array( 'retries' => 3 ) );
ok( 'fresh bench: fast WP_Error', is_wp_error( $out2 ) && 'haai_all_benched' === $out2->get_error_code() );
ok( 'fresh bench: zero HTTP calls', 0 === count( http_requests() ) );

echo "\n=== B. probe age: one attempt, no backoff ===\n";
// Age the bench past the probe window (left 200s of 300 → age 100s).
$GLOBALS['saved'][ ck( 'https://primary.example/v1', 'm-main' ) ] = time() + 200;
$GLOBALS['http_map'] = array( 'primary.example' => array( 'error' => 'cURL error 28: timed out' ) );
$GLOBALS['http_log'] = array();
$out3 = $api->chat( array( array( 'role' => 'user', 'content' => 'ping' ) ), array( 'retries' => 3 ) );
ok( 'probe attempt made', is_wp_error( $out3 ) );
ok( 'probe = exactly one HTTP call (no backoff)', 1 === count( http_requests() ), 'got ' . count( http_requests() ) );
$left3 = Haa_API::cooldown_left( $mk() );
ok( 'failed probe did NOT restart the clock', $left3 > 150 && $left3 <= 200, 'left=' . $left3 );

echo "\n=== C. successful probe restores full health ===\n";
$GLOBALS['saved'][ ck( 'https://primary.example/v1', 'm-main' ) ] = time() + 200;
$GLOBALS['http_map'] = array( 'primary.example' => $reply_body( 'پاسخ بعد از بهبود' ) );
$GLOBALS['http_log'] = array();
$out4 = $api->chat( array( array( 'role' => 'user', 'content' => 'ping' ) ), array( 'retries' => 2 ) );
ok( 'probe succeeds, reply returned', is_string( $out4 ) && 'پاسخ بعد از بهبود' === $out4 );
ok( 'bench transient deleted (full health)', ! array_key_exists( ck( 'https://primary.example/v1', 'm-main' ), $GLOBALS['saved'] ) );

echo "\n=== D. multi-source: fresh bench skipped, healthy serves ===\n";
$GLOBALS['saved'][ ck( 'https://primary.example/v1', 'm-main' ) ] = time() + 280; // fresh.
$GLOBALS['http_map'] = array(
        'primary.example' => array( 'error' => 'cURL error 28: timed out' ),
        'backup.example'  => $reply_body( 'پاسخ از منبع یدکی' ),
);
$h2 = new Haa_API( $mk( array( 'failovers' => array(
        array( 'adapter' => 'openai', 'base_url' => 'https://backup.example/v1', 'api_key' => 'pk-backup', 'model' => 'm-backup', 'hash' => 'h1' ),
) ) ) );
$GLOBALS['http_log'] = array();
$out5 = $h2->chat( array( array( 'role' => 'user', 'content' => 'ping' ) ), array( 'retries' => 3 ) );
ok( 'backup serves while primary fresh-benched', is_string( $out5 ) && 'پاسخ از منبع یدکی' === $out5 );
ok( 'primary never called', ! in_array( 'primary.example', hosts_called(), true ) );

// All sources freshly benched: fail fast.
$GLOBALS['saved'][ ck( 'https://primary.example/v1', 'm-main' ) ] = time() + 280;
$GLOBALS['saved'][ ck( 'https://backup.example/v1', 'm-backup' ) ] = time() + 270;
$GLOBALS['http_log'] = array();
$out6 = $h2->chat( array( array( 'role' => 'user', 'content' => 'ping' ) ), array( 'retries' => 3 ) );
ok( 'all fresh: fast WP_Error', is_wp_error( $out6 ) && 'haai_all_benched' === $out6->get_error_code() );
ok( 'all fresh: zero HTTP calls', 0 === count( http_requests() ) );
unset( $GLOBALS['saved'][ ck( 'https://primary.example/v1', 'm-main' ) ], $GLOBALS['saved'][ ck( 'https://backup.example/v1', 'm-backup' ) ] );

echo "\n=== E. healthy lone primary keeps its retry budget ===\n";
$GLOBALS['http_map'] = array( 'primary.example' => array( 'error' => 'cURL error 28: timed out' ) );
$GLOBALS['http_log'] = array();
$api2 = new Haa_API( $mk( array( 'retries' => 2 ) ) );
$api2->chat( array( array( 'role' => 'user', 'content' => 'ping' ) ), array( 'retries' => 2 ) );
ok( 'healthy primary still gets 1+retries calls', 3 === count( http_requests() ), 'got ' . count( http_requests() ) );
unset( $GLOBALS['saved'][ ck( 'https://primary.example/v1', 'm-main' ) ] );

echo "\n=== F. image: all fresh-benched reports honestly ===\n";
$GLOBALS['saved'][ ck( 'https://primary.example/v1', 'm-main' ) ] = time() + 280;
$GLOBALS['http_map'] = array( 'primary.example' => array(
        'code' => 200,
        'body' => wp_json_encode( array( 'data' => array( array( 'url' => 'https://img.example/a.png' ) ) ) ),
) );
$GLOBALS['http_log'] = array();
$img = $api2->image( 'تصویر تست', array() );
ok( 'image: fast haai_all_benched (no fake success)', is_wp_error( $img ) && 'haai_all_benched' === $img->get_error_code() );
ok( 'image: zero HTTP calls', 0 === count( http_requests() ) );
unset( $GLOBALS['saved'][ ck( 'https://primary.example/v1', 'm-main' ) ] );

echo "\n=== G. relay Base-URL contracts (worker side) ===\n";
$check_worker_path = function ( $url, $provider, $prefix ) {
        $path = (string) wp_parse_url( (string) $url, PHP_URL_PATH );
        $parts = explode( '/', trim( (string) $path, '/' ) ); // r, TOKEN, PROVIDER, rest…
        if ( count( $parts ) < 4 || 'r' !== $parts[0] ) {
                return false;
        }
        if ( $provider !== $parts[2] ) {
                return false;
        }
        $rest = implode( '/', array_slice( $parts, 3 ) );
        return 0 === strpos( $rest, $prefix );
};
$GLOBALS['http_map'] = array( 'worker-relay.example' => $reply_body( 'سلام' ) );

// Groq through the relay: the plugin appends /chat/completions to the /v1 base.
$groq = new Haa_API( array(
        'base_url' => 'https://worker-relay.example/r/TOK123/groq/openai/v1',
        'adapter'  => 'openai', 'api_key' => 'gsk_test', 'model' => 'llama-3.3-70b-versatile', 'timeout' => 30, 'temperature' => 0.7, 'max_tokens' => 1000, 'retries' => 0,
) );
$GLOBALS['http_log'] = array();
$groq->chat( array( array( 'role' => 'user', 'content' => 'ping' ) ), array( 'retries' => 0 ) );
$calls = http_requests();
$gurl  = (string) ( $calls[0]['url'] ?? '' );
ok( 'groq relay URL built once', 'https://worker-relay.example/r/TOK123/groq/openai/v1/chat/completions' === $gurl );
ok( 'groq relay passes worker validation', $check_worker_path( $gurl, 'groq', 'openai/v1/' ) );

// OpenRouter through the relay: /v1 base, worker maps to api/v1/.
$orr = new Haa_API( array(
        'base_url' => 'https://worker-relay.example/r/TOK123/openrouter/v1',
        'adapter'  => 'openai', 'api_key' => 'sk-or_test', 'model' => 'm/free', 'timeout' => 30, 'temperature' => 0.7, 'max_tokens' => 1000, 'retries' => 0,
) );
$GLOBALS['http_log'] = array();
$orr->chat( array( array( 'role' => 'user', 'content' => 'ping' ) ), array( 'retries' => 0 ) );
$calls = http_requests();
$ourl  = (string) ( $calls[0]['url'] ?? '' );
ok( 'openrouter relay URL built once', 'https://worker-relay.example/r/TOK123/openrouter/v1/chat/completions' === $ourl );
ok( 'openrouter relay passes worker validation', $check_worker_path( $ourl, 'openrouter', 'v1/' ) );

// Gemini chat endpoint through the relay stays single-path (suite 20 lock, kept).
$ref = new ReflectionMethod( 'Haa_Adapter_Gemini', 'endpoint' );
$ref->setAccessible( true );
$gm = new Haa_API( array(
        'base_url' => 'https://worker-relay.example/r/TOK123/gemini/v1beta',
        'adapter'  => 'gemini', 'api_key' => 'AIza-x', 'model' => 'gemini-2.0-flash', 'timeout' => 30, 'temperature' => 0.7, 'max_tokens' => 1000, 'retries' => 0,
) );
$ep = $ref->invoke( $gm->adapter() );
ok( 'gemini relay endpoint single v1beta', 'https://worker-relay.example/r/TOK123/gemini/v1beta/models/gemini-2.0-flash:generateContent' === $ep );

echo "\n=== H. ZWNJ-merged live search (می‌خانه vs میخانه) ===\n";
reset_kb_config();
$GLOBALS['chunk_rows'] = array();
$GLOBALS['post_content'] = array(
        910 => 'در دل جنگل‌های لاهیجان یک می‌خانه چای سنتی هست؛ سرو چای با نان برشته و مربوی خانگی.',
        911 => 'جاذبه‌های گردشگری گیلان: تالاب انزلی و بازار رشت.',
);
$GLOBALS['posts'] = array(
        array( 'id' => 910, 'title' => 'می‌خانه سنتی لاهیجان', 'type' => 'post', 'status' => 'publish' ),
        array( 'id' => 911, 'title' => 'جاذبه‌های گیلان', 'type' => 'post', 'status' => 'publish' ),
);
$hits = Haa_Search::search( 'میخانه لاهیجان کجاست؟', 3, 1500 );
ok( 'merged token finds the ZWNJ copy', is_array( $hits ) && count( $hits ) > 0 );
ok( 'top hit is the traditional teahouse', 910 === (int) ( $hits[0]['post_id'] ?? 0 ) );
ok( 'snippet shows the raw ZWNJ text', false !== strpos( (string) ( $hits[0]['content'] ?? '' ), 'می‌خانه' ) );

echo "\n=== I. Elementor page answers the café question (empty post_content) ===\n";
reset_kb_config();
$GLOBALS['chunk_rows'] = array();
$el_json = wp_json_encode( array(
        array(
                'id' => 'sec1', 'elType' => 'section',
                'elements' => array(
                        array(
                                'id' => 'col1', 'elType' => 'column',
                                'elements' => array(
                                        array( 'id' => 'w1', 'elType' => 'widget', 'settings' => array( 'title' => 'کافه‌های رشت' ), 'elements' => array() ),
                                        array( 'id' => 'w2', 'elType' => 'widget', 'settings' => array( 'editor' => '<p>کافه ایران، کافه مارتا و کافه دو همسفر بهترین کافه‌های رشت‌اند؛ قهوه‌ی اسپشیالیتی، فضای کار و دسر خانگی.</p>' ), 'elements' => array() ),
                                ),
                        ),
                ),
        ),
) );
$GLOBALS['post_content'] = array( 920 => '' );
$GLOBALS['post_elementor'] = array( 920 => $el_json );
$GLOBALS['posts'] = array(
        array( 'id' => 920, 'title' => 'کافه‌های رشت', 'type' => 'page', 'status' => 'publish' ),
);
$hits = Haa_Search::search( 'کافه های رشت بهتریناش کدومن؟', 3, 1500 );
ok( 'builder pass returns the Elementor page', is_array( $hits ) && count( $hits ) > 0 );
ok( 'hit is the café page', 920 === (int) ( $hits[0]['post_id'] ?? 0 ) );
ok( 'snippet is clean copy (no JSON noise)', false !== strpos( (string) ( $hits[0]['content'] ?? '' ), 'کافه' ) && false === strpos( (string) ( $hits[0]['content'] ?? '' ), 'elType' ) );

echo "\n=== J. build_source indexes builder copy ===\n";
$GLOBALS['post_meta'] = array( 920 => array( '_elementor_data' => $el_json ) );
$src = Haa_Search::build_source( 920 );
ok( 'source content carries the café copy', false !== strpos( (string) ( $src['content'] ?? '' ), 'کافه ایران' ) );
ok( 'source content carries the heading', false !== strpos( (string) ( $src['content'] ?? '' ), 'کافه‌های رشت' ) );

echo "\n=== K. elementor_text extraction ===\n";
$t1 = Haa_Search::elementor_text( $el_json );
ok( 'decoded tree: heading + editor text', false !== strpos( $t1, 'کافه‌های رشت' ) && false !== strpos( $t1, 'کافه مارتا' ) );
ok( 'HTML stripped from editor values', false === strpos( $t1, '<p>' ) );
$t2 = Haa_Search::elementor_text( '{"settings":{"title":"کافه مارتا","ed' ); // truncated JSON.
ok( 'truncated JSON falls back to regex', false !== strpos( $t2, 'کافه مارتا' ) );
$t3 = Haa_Search::elementor_text( '' );
ok( 'empty meta → empty text', '' === $t3 );
$t4 = Haa_Search::elementor_text( '{"a":{"b":{"c":"deep"}}}' );
ok( 'tree without copy keys → empty', '' === $t4 );

echo "\n=== L. 2.1.31: hard-zero retries + the capped-timeout contract ===\n";
// The keyword panel sends retries=0 + timeout=25: each source must get EXACTLY
// one HTTP attempt (the old multi-source budget would double the panel's wait)
// and the timeout message must report the CAPPED wait, not the configured one.
$mk2 = new Haa_API( $mk( array( 'failovers' => array(
        array( 'adapter' => 'openai', 'base_url' => 'https://backup.example/v1', 'api_key' => 'pk-backup', 'model' => 'm-backup', 'hash' => 'h9' ),
) ) ) );
$GLOBALS['http_map'] = array(
        'primary.example' => array( 'error' => 'cURL error 28: timed out' ),
        'backup.example'  => array( 'error' => 'cURL error 28: timed out' ),
);
$GLOBALS['http_log'] = array();
$out_l = $mk2->chat( array( array( 'role' => 'user', 'content' => 'ping' ) ), array( 'retries' => 0, 'timeout' => 25 ) );
ok( 'hard-zero retries: two sources, exactly two attempts', is_wp_error( $out_l ) && 2 === count( http_requests() ), 'got ' . count( http_requests() ) );
ok( 'timeout message reports the capped wait (25s, not 30)',
        false !== strpos( (string) $out_l->get_error_message(), '(25 ثانیه' ), (string) $out_l->get_error_message() );
unset(
        $GLOBALS['saved'][ ck( 'https://primary.example/v1', 'm-main' ) ],
        $GLOBALS['saved'][ ck( 'https://backup.example/v1', 'm-backup' ) ]
);
$opts_def = Haa_API::chat_opts( $mk( array( 'retries' => 2 ) ), array( 'model' => 'x' ) );
ok( 'chat_opts defaults: timeout=0 (configured value wins) + ignore_fresh_bench=false',
        0 === (int) $opts_def['timeout'] && false === (bool) $opts_def['ignore_fresh_bench'] && 2 === (int) $opts_def['retries'], '' );

echo "\n" . ( $fail ? "FAILURES: {$fail}\n" : "ALL OK\n" );
exit( $fail ? 1 : 0 );
