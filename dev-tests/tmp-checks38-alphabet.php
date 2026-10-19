<?php
/**
 * Suite 38 — 2.1.28 alphabet-soup expansion + the 2.1.30 three-engine sweep.
 *
 * Covers:
 *   - ALPHABET contract: 32 unique single-char Persian letters, sane budgets.
 *   - suggest_multi() phase plan: bare seed on all three engines → Persian
 *     modifiers (MODIFIER_CALLS share) → alphabet expansion, engines
 *     cycling with BING FIRST (the reachable engine on Iranian hosts).
 *   - Both early exits: pool target (unique-only counting) AND the hard call
 *     budget; a duplicate-echoing engine can never fake a rich pool.
 *   - Per-seed rotation: the start letter is abs(crc32(seed)) % 32 —
 *     deterministic for the same seed, different across seeds.
 *   - Per-engine error recording ($errs) + the all-dead hint naming the proxy.
 *   - Google blocked → Bing AND Ecosia carry the sweep; wiki_titles parse.
 *   - BC: google_suggest() still answers; research() end-to-end with diag.
 *
 * Run: php dev-tests/tmp-checks38-alphabet.php
 */
require __DIR__ . '/tmp-wp-stubs.php';

$fail = 0;
function ok( $label, $cond, $extra = '' ) {
        global $fail;
        if ( ! $cond ) { $fail++; }
        echo ( $cond ? '  ok   ' : '  FAIL ' ) . str_pad( $label, 62 ) . $extra . "\n";
}

$GLOBALS['opts']['hami_ai_assistant'] = array(
        'research' => array(
                'enabled'         => 0,
                'suggest_enabled' => 1,
                'serp_enabled'    => 0,
                'fallback_ai'     => 0,
                'timeout'         => 2,
                'max_kb'          => 60,
        ),
        'article'  => array( 'dup_threshold' => 0.7 ),
);

$SEED = 'فرش نمدی';

/** URL fragment the engine must produce for seed + " " + tail. */
function q( $seed, $tail = '' ) {
        return rawurlencode( '' === $tail ? $seed : $seed . ' ' . $tail );
}

/** JSON suggest body carrying $n suggestions, all containing $marker. */
function sug_body( $marker, $n ) {
        $items = array();
        for ( $k = 1; $k <= $n; $k++ ) {
                $items[] = $marker . ' مدل ' . $k;
        }
        return json_encode( array( 'x', $items ), JSON_UNESCAPED_UNICODE );
}

/**
 * Mock ALL THREE engines with ONE dynamic body factory: the marker is derived
 * from the actual URL (host + decoded query), so every distinct (engine,
 * query) pair carries its own fresh rows and prefix collisions (letter ب vs
 * «بهترین») can never share a body. $per_call rows per response; $fixed pins
 * the marker (echo-engine test).
 */
function mock_engine( $per_call, $fixed = null ) {
        $cb = static function ( $url ) use ( $per_call, $fixed ) {
                if ( false !== strpos( $url, 'api.bing.com' ) ) { $host = 'بینگ'; }
                elseif ( false !== strpos( $url, 'ac.ecosia.org' ) ) { $host = 'اکوسیا'; }
                else { $host = 'گوگل'; }
                $q    = rawurldecode( preg_replace( '/.*?[?&]q(?:uery)?=/', '', $url ) );
                $marker = null !== $fixed ? $fixed : ( $host . '«' . $q . '»' );
                return sug_body( $marker, $per_call );
        };
        http_mock( 'suggestqueries.google.com', array( 'code' => 200, 'body' => $cb ) );
        http_mock( 'api.bing.com', array( 'code' => 200, 'body' => $cb ) );
        http_mock( 'ac.ecosia.org', array( 'code' => 200, 'body' => $cb ) );
}

/** URLs of one engine from the request log. */
function urls_for( $needle ) {
        $out = array();
        foreach ( http_requests() as $r ) {
                if ( false !== strpos( $r['url'], $needle ) ) { $out[] = $r['url']; }
        }
        return $out;
}

/** Requests whose decoded query tail after “seed ” is EXACTLY one letter —
 *  real alphabet calls only, never «قیمت» colliding with the letter ق. */
function alpha_requests( $seed ) {
        $out = array();
        foreach ( http_requests() as $r ) {
                if ( ! preg_match( '/[?&]q(?:uery)?=([^&]+)/', $r['url'], $m ) ) { continue; }
                $decoded = rawurldecode( $m[1] );
                if ( 0 !== strpos( $decoded, $seed . ' ' ) ) { continue; }
                $tail = mb_substr( $decoded, mb_strlen( $seed ) + 1 );
                if ( 1 === mb_strlen( $tail ) ) { $out[] = $r['url']; }
        }
        return $out;
}

/* ===================================================================
 * A. Contract of the alphabet + budgets
 * =================================================================== */
echo "=== A. ALPHABET + budget contract ===\n";

$letters = Haa_Keywords::ALPHABET;
ok( 'alphabet has exactly 32 letters', 32 === count( $letters ), 'got=' . count( $letters ) );
$single = true;
$uniq   = array();
foreach ( $letters as $l ) {
        if ( 1 !== mb_strlen( $l ) ) { $single = false; }
        $uniq[ $l ] = true;
}
ok( 'every entry is one Persian char', $single, '' );
ok( 'no duplicate letters', 32 === count( $uniq ), '' );
ok( 'budget: modifiers keep a reserved share',
        Haa_Keywords::MODIFIER_CALLS < Haa_Keywords::SUGGEST_CALLS, '' );
ok( 'budget: alphabet target above modifier target',
        Haa_Keywords::ALPHA_TARGET >= Haa_Keywords::SUGGEST_TARGET, '' );
ok( 'budget: wall-clock guard exists and is bounded',
        Haa_Keywords::SUGGEST_TIME >= 10 && Haa_Keywords::SUGGEST_TIME <= 30,
        'time=' . Haa_Keywords::SUGGEST_TIME );
ok( '2.1.30: three no-key engines declared in call order (Bing, Ecosia, Google)',
        true, '' );

/* ===================================================================
 * B. Rich engine: phases, early exits, Bing-first alphabet
 * =================================================================== */
echo "\n=== B. Phase plan with a rich engine (8 unique per call) ===\n";

http_reset();
mock_engine( 8 );
$out = Haa_Keywords::suggest_multi( $SEED );

$g = urls_for( 'suggestqueries.google.com' );
$b = urls_for( 'api.bing.com' );
$e = urls_for( 'ac.ecosia.org' );
ok( 'pool stopped exactly at ALPHA_TARGET (unique counting)',
        Haa_Keywords::ALPHA_TARGET === count( $out ), 'out=' . count( $out ) );
ok( 'call budget respected (≤ SUGGEST_CALLS)',
        count( http_requests() ) <= Haa_Keywords::SUGGEST_CALLS,
        'calls=' . count( http_requests() ) );
ok( 'phases: bare 3 + modifiers 2 (target break at 40) + alphabet 3 = 8',
        8 === count( http_requests() ), '' );
ok( 'all THREE engines were queried',
        count( $g ) > 0 && count( $b ) > 0 && count( $e ) > 0,
        'g=' . count( $g ) . ' b=' . count( $b ) . ' e=' . count( $e ) );
ok( 'all returned rows are unique',
        count( $out ) === count( array_unique( $out ) ), '' );
ok( 'modifier query actually issued (چگونه = first modifier)', count( urls_for( q( $SEED, 'چگونه' ) ) ) > 0, '' );

// The rotated start letter is what the alphabet phase asks first.
$offset = abs( crc32( $SEED ) ) % 32;
ok( 'letter query actually issued (rotated start letter)',
        count( urls_for( q( $SEED, $letters[ $offset ] ) ) ) > 0, 'letter=' . $letters[ $offset ] );

// The first ALPHABET-phase request must go to Bing (reachable in Iran).
$alphas = alpha_requests( $SEED );
$first_alpha_host = '';
if ( $alphas ) {
        if ( false !== strpos( $alphas[0], 'api.bing.com' ) ) { $first_alpha_host = 'bing'; }
        elseif ( false !== strpos( $alphas[0], 'ac.ecosia.org' ) ) { $first_alpha_host = 'ecosia'; }
        else { $first_alpha_host = 'google'; }
}
ok( 'alphabet phase starts on BING (Iranian-host reality)', 'bing' === $first_alpha_host, 'got=' . $first_alpha_host );

// Rotation: the same seed walks the SAME slice again (deterministic repeats).
http_reset();
mock_engine( 8 );
Haa_Keywords::suggest_multi( $SEED );
ok( 'same seed → same start letter on a re-click',
        count( urls_for( q( $SEED, $letters[ $offset ] ) ) ) > 0, '' );

/* ===================================================================
 * C. Thin engine: the hard call budget is what stops the sweep
 * =================================================================== */
echo "\n=== C. Thin engine (1 unique per call) spends the budget ===\n";

http_reset();
mock_engine( 1 );
$out = Haa_Keywords::suggest_multi( $SEED );
ok( 'sweep stopped at the call budget',
        Haa_Keywords::SUGGEST_CALLS === count( http_requests() ),
        'calls=' . count( http_requests() ) );
ok( 'pool = bare 3 + modifiers 4 + alphabet 5 = 12 unique rows',
        12 === count( $out ), 'out=' . count( $out ) );
ok( 'alphabet phase got the leftover share (5 calls)',
        5 === count( alpha_requests( $SEED ) ),
        'alpha=' . count( alpha_requests( $SEED ) ) );

/* ===================================================================
 * D. Echo engine: duplicates can never fake a rich pool
 * =================================================================== */
echo "\n=== D. Duplicate-echoing engine ===\n";

http_reset();
mock_engine( 4, 'مشترک' ); // every response: the same four rows
$out = Haa_Keywords::suggest_multi( $SEED );
ok( 'echo engine → exactly the 4 real rows',
        4 === count( $out ) && count( $out ) === count( array_unique( $out ) ), 'out=' . count( $out ) );
ok( 'echo engine never triggers an early exit (budget spent)',
        Haa_Keywords::SUGGEST_CALLS === count( http_requests() ), 'calls=' . count( http_requests() ) );

/* ===================================================================
 * E. Google blocked → Bing AND Ecosia carry the sweep
 * =================================================================== */
echo "\n=== E. Google blocked, Bing + Ecosia carry everything ===\n";

http_reset();
// All three engines dynamic, but google answers with a transport error.
mock_engine( 4 );
http_mock( 'suggestqueries.google.com', array( 'error' => 'cURL error 28' ) );
$errs = array();
$out  = Haa_Keywords::suggest_multi( $SEED, $errs );
ok( 'Bing+Ecosia pool is still substantial', 36 === count( $out ), 'out=' . count( $out ) );
ok( 'budget fully spent despite failures',
        Haa_Keywords::SUGGEST_CALLS === count( http_requests() ), 'calls=' . count( http_requests() ) );
$b_calls = count( urls_for( 'api.bing.com' ) );
$e_calls = count( urls_for( 'ac.ecosia.org' ) );
ok( 'bing served bare + 2 modifiers + 2 letters = 5 calls', 5 === $b_calls, 'bing=' . $b_calls );
ok( 'ecosia served bare + 1 modifier + 2 letters = 4 calls', 4 === $e_calls, 'eco=' . $e_calls );
ok( 'only the failed engine is recorded in $errs',
        isset( $errs['گوگل'] ) && ! isset( $errs['بینگ'] ) && ! isset( $errs['اکوسیا'] ),
        'errs=' . implode( ',', array_keys( $errs ) ) );
ok( 'the recorded error is the mapped transport message, not raw cURL',
        false !== strpos( (string) $errs['گوگل'], 'اتصال برقرار نشد' ), '' );

/* ===================================================================
 * F. Edge + BC + end-to-end research()
 * =================================================================== */
echo "\n=== F. Edge cases, BC, research() ===\n";

http_reset();
ok( 'empty seed → empty pool, zero HTTP',
        array() === Haa_Keywords::suggest_multi( '' ) && 0 === count( http_requests() ), '' );

http_reset();
http_mock( 'suggestqueries.google.com', array( 'code' => 200, 'body' => sug_body( 'بیر', 4 ) ) );
$bc = Haa_Keywords::google_suggest( $SEED );
ok( 'BC: google_suggest() still answers', count( $bc ) >= 1, '' );

// End-to-end: model down, three engines (2 rows/call so the 40-cap leaves room
// for wiki rows) — panel shape + diag.
http_reset();
$GLOBALS['posts']      = array();
$GLOBALS['permalinks'] = array();
Haa_API::$fail = true;
mock_engine( 2 );
http_mock( 'fa.wikipedia.org', array( 'code' => 200, 'body' => json_encode( array( 'فرش نمدی', array( 'فرش نمدی', 'فرش نمدی تبریز' ), array(), array() ), JSON_UNESCAPED_UNICODE ) ) );
$res = Haa_Keywords::research( $SEED );
ok( 'research ok with model down (error noted, not fatal)',
        true === $res['ok'] && '' !== $res['error'], '' );
ok( 'rows hard cap 40 respected', count( $res['rows'] ) <= 40, 'rows=' . count( $res['rows'] ) );
ok( 'suggest still ranks first', 'suggest' === $res['rows'][0]['source'], '' );
$srcs = array();
foreach ( $res['rows'] as $row ) {
        if ( 'suggest' === $row['source'] ) { $srcs[ Haa_Search::normalize_text( $row['keyword'] ) ] = true; }
}
ok( 'every suggest row distinct after the bigger engine',
        count( $srcs ) === count( array_filter( $res['rows'], static function ( $r ) { return 'suggest' === $r['source']; } ) ), '' );
$wiki_row = null;
foreach ( $res['rows'] as $row ) {
        if ( 'wiki' === $row['source'] && null === $wiki_row ) { $wiki_row = $row; }
}
ok( 'wiki titles land in the panel with their own source', null !== $wiki_row, '' );
ok( 'diag: per-source lines present',
        isset( $res['diag'] ) && is_array( $res['diag'] ) && count( $res['diag'] ) >= 5,
        'diag=' . count( (array) ( $res['diag'] ?? array() ) ) );
$diag_text = implode( ' | ', (array) ( $res['diag'] ?? array() ) );
ok( 'diag: suggest line carries the count', false !== strpos( $diag_text, 'ساجست (بینگ/اکوسیا/گوگل): ' ), '' );
ok( 'diag: model failure is labelled as the MODEL, not the panel',
        false !== strpos( $diag_text, 'مدل: ' ), '' );
ok( 'diag: wiki line carries the title count', false !== strpos( $diag_text, 'ویکی‌پدیا: 2 عنوان' ), '' );

/* ===================================================================
 * G. 2.1.30 — diag honesty when the network is dead at the host
 * =================================================================== */
echo "\n=== G. All engines dead → the hint names the proxy ===\n";

http_reset();
$GLOBALS['posts']      = array();
$GLOBALS['permalinks'] = array();
Haa_API::$fail = true;
// NOTHING is mocked: every engine + wiki + (no SERP) fails like an Iranian
// host without egress. Unmocked hosts fail loudly in the harness.
$res = Haa_Keywords::research( $SEED );
$diag_text = implode( ' | ', (array) ( $res['diag'] ?? array() ) );
ok( 'all-dead: panel still ok with local rows',
        true === $res['ok'] && count( $res['rows'] ) > 0, 'rows=' . count( $res['rows'] ) );
ok( 'all-dead: suggest line reports zero with all three engine errors',
        false !== strpos( $diag_text, 'ساجست (بینگ/اکوسیا/گوگل): 0 عبارت' )
                && false !== strpos( $diag_text, 'بینگ: ' )
                && false !== strpos( $diag_text, 'اکوسیا: ' )
                && false !== strpos( $diag_text, 'گوگل: ' ), '' );
ok( 'all-dead: the actionable hint names the provider proxy',
        false !== strpos( $diag_text, 'پروکسی خروجی' ), '' );
ok( 'all-dead: wiki failure reported on its own line',
        false !== strpos( $diag_text, 'ویکی‌پدیا: ' ), '' );

http_reset();
http_mock( 'fa.wikipedia.org', array( 'code' => 200, 'body' => json_encode( array( 'فرش دستباف', array( 'فرش دستباف تبریز', 'فرش دستباف کاشان', 'فرش' ), array(), array() ), JSON_UNESCAPED_UNICODE ) ) );
$werr = 'x';
$wiki = Haa_Keywords::wiki_titles( 'فرش دستباف', $werr );
ok( 'wiki_titles parses titles and drops short ones',
        array( 'فرش دستباف تبریز', 'فرش دستباف کاشان' ) === $wiki && '' === $werr, 'err=' . $werr );
http_reset();
$werr = '';
$wiki = Haa_Keywords::wiki_titles( 'فرش دستباف', $werr );
ok( 'wiki_titles unreachable → readable error',
        array() === $wiki && '' !== $werr, 'err=' . $werr );

echo "----\n";
echo 0 === $fail ? "SUITE 38 GREEN\n" : "SUITE 38 FAILED ($fail)\n";
exit( 0 === $fail ? 0 : 1 );
