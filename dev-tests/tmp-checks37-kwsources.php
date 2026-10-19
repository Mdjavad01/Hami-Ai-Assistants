<?php
/**
 * Regression suite: the keyword-research sources promised by the landing
 * page, shipped in 2.1.27 (2.1.26 audit found them missing).
 *
 * The landing copy promises four free sources and an Excel-ready plan:
 *
 *   G  پیشنهادهای گوگل و بینگ            → was Google-only, 2 modifiers, dead MODIFIERS const
 *   ؟ جست‌وجوهای بی‌نتیجهٔ سایت           → did not exist at all
 *   C  فایل سرچ کنسول (بدون API)          → did not exist at all
 *   ✓  محتوای خود سایت (دارد/نیمه/بی‌جواب) → did not exist
 *   +  خروجی اکسل، نیت (سؤال/محلی…)، فلگ «مناسب پاسخ هوش مصنوعی»
 *
 * Locked here:
 *   - Haa_Misses: privacy-first schema (phrase/day/hits ONLY — no ip/user/ua),
 *     the atomic upsert (one row per phrase+day, counter accumulates), the
 *     noise gate (short/letterless phrases dropped), tag stripping, 120-char
 *     cap, aggregation across days, seed-matching by token overlap, prune.
 *   - Haa_Keywords::import_gsc: Google Search Console's REAL export shape —
 *     UTF-16LE + tab + English headers (the UI download) AND UTF-8 + comma +
 *     Persian headers + Persian digits (other tools). Merge-dedupe by
 *     normalised query, garbage → readable WP_Error.
 *   - Suggest: both engines under a 6-call budget, Bing still fills when
 *     Google is blocked (the Iranian-host reality), rows deduped.
 *   - research(): misses + GSC ranked above serp/model, every row carries
 *     intent + AI flag + coverage, hard cap 40, local intent.
 *   - coverage_of(): title-token overlap → دارد / نیمه / بی‌جواب with a real
 *     permalink; planner accepts the 'local' intent end-to-end.
 *   - CSV exports: RFC-escaped cells, planner rows with Persian intent labels.
 *
 * Run: php dev-tests/tmp-checks37-kwsources.php
 */
require __DIR__ . '/tmp-wp-stubs.php';

$fail = 0;
function ok( $label, $cond, $extra = '' ) {
        global $fail;
        if ( ! $cond ) { $fail++; }
        echo ( $cond ? '  ok   ' : '  FAIL ' ) . str_pad( $label, 62 ) . $extra . "\n";
}

/** UTF-8 → UTF-16LE bytes (with BOM), the encoding GSC's CSV download uses. */
function utf8_to_utf16le( $s ) {
        $out = "\xFF\xFE";
        foreach ( preg_split( '//u', $s, -1, PREG_SPLIT_NO_EMPTY ) as $ch ) {
                $cp = 0;
                $b  = strlen( $ch );
                if ( 1 === $b ) { $cp = ord( $ch[0] ); }
                elseif ( 2 === $b ) { $cp = ( ord( $ch[0] ) & 0x1F ) << 6 | ( ord( $ch[1] ) & 0x3F ); }
                elseif ( 3 === $b ) { $cp = ( ord( $ch[0] ) & 0x0F ) << 12 | ( ord( $ch[1] ) & 0x3F ) << 6 | ( ord( $ch[2] ) & 0x3F ); }
                else { $cp = ( ord( $ch[0] ) & 0x07 ) << 18 | ( ord( $ch[1] ) & 0x3F ) << 12 | ( ord( $ch[2] ) & 0x3F ) << 6 | ( ord( $ch[3] ) & 0x3F ); }
                if ( $cp >= 0x10000 ) { // surrogate pair
                        $cp2 = $cp - 0x10000;
                        $out .= pack( 'vv', 0xD800 | ( $cp2 >> 10 ), 0xDC00 | ( $cp2 & 0x3FF ) );
                } else {
                        $out .= pack( 'v', $cp );
                }
        }
        return $out;
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

/* ===================================================================
 * A. Haa_Misses — logging, privacy, upsert, gates
 * =================================================================== */
echo "=== A. Haa_Misses: privacy-first logging ===\n";

$GLOBALS['db']['misses'] = array();

ok( 'log stores a real phrase', true === Haa_Misses::log( 'فرش نمدی دارید؟' ), '' );
$first = $GLOBALS['db']['misses'][0];
ok( 'row phrase preserved verbatim', 'فرش نمدی دارید؟' === $first['phrase'], '' );
ok( 'row day is today (UTC)', gmdate( 'Y-m-d' ) === $first['day'], '' );
ok( 'first log hits=1', 1 === (int) $first['hits'], '' );
ok( 'stored columns are ONLY id/phrase/day/hits/last (privacy)',
        array( 'id', 'phrase', 'day', 'hits', 'last' ) === array_keys( $first ), '' );

ok( 'second log of same phrase+day bumps the counter', true === Haa_Misses::log( 'فرش نمدی دارید؟' ), '' );
ok( 'still ONE row (atomic upsert, not duplicate)', 1 === count( $GLOBALS['db']['misses'] ), '' );
ok( 'counter now 2', 2 === (int) $GLOBALS['db']['misses'][0]['hits'], '' );

// A miss seen on a previous day too: aggregate across days.
$GLOBALS['db']['misses'][0]['day'] = gmdate( 'Y-m-d', time() - DAY_IN_SECONDS );
Haa_Misses::log( 'فرش نمدی دارید؟' );
ok( 'same phrase on a new day is a second row', 2 === count( $GLOBALS['db']['misses'] ), '' );

ok( 'short noise «سلام» dropped', false === Haa_Misses::log( 'سلام' ), '' );
ok( 'letterless «---؟؟» dropped', false === Haa_Misses::log( '---؟؟' ), '' );
ok( 'empty string dropped', false === Haa_Misses::log( '' ), '' );
Haa_Misses::log( '<b>قیمت دار قفل</b> چقدر است؟' );
$hit_row = null;
foreach ( $GLOBALS['db']['misses'] as $r ) {
        if ( false !== strpos( $r['phrase'], 'دار قفل' ) ) { $hit_row = $r; }
}
ok( 'HTML tags stripped from the phrase', null !== $hit_row && 'قیمت دار قفل چقدر است؟' === $hit_row['phrase'], '' );

Haa_Misses::log( str_repeat( 'ابریشم ', 20 ) );
$long = null;
foreach ( $GLOBALS['db']['misses'] as $r ) {
        if ( false !== strpos( $r['phrase'], 'ابریشم' ) ) { $long = $r; }
}
ok( 'phrase capped at 120 chars', null !== $long && 120 === mb_strlen( $long['phrase'] ), '' );

echo "\n=== B. Haa_Misses: aggregation, matching, prune ===\n";

$top = Haa_Misses::top( 10, 30 );
ok( 'top() aggregates the phrase across days', 3 === (int) $top[0]['hits'] && 2 === (int) $top[0]['days'], '' );

// A rival phrase with more hits + an unrelated one.
Haa_Misses::log( 'ابریشم اصل چقدر؟' );
Haa_Misses::log( 'ابریشم اصل چقدر؟' );
Haa_Misses::log( 'ابریشم اصل چقدر؟' );
Haa_Misses::log( 'کفش رانینگ سایز ۴۶' );
$top = Haa_Misses::top( 10, 30 );
ok( 'top() sorts by hits desc', 'ابریشم اصل چقدر؟' === $top[0]['phrase'] && 3 === (int) $top[0]['hits'], '' );

$match = Haa_Misses::matching( 'فرش', 10 );
$names = wp_list_pluck( $match, 'phrase' );
ok( 'matching(فرش) finds the فرش miss', in_array( 'فرش نمدی دارید؟', $names, true ), '' );
ok( 'matching(فرش) skips the کفش miss', ! in_array( 'کفش رانینگ سایز ۴۶', $names, true ), '' );

$GLOBALS['db']['misses'][] = array( 'id' => 999, 'phrase' => 'قدیمی', 'day' => '2000-01-01', 'hits' => 9, 'last' => '2000-01-01 00:00:00' );
$removed = Haa_Misses::prune();
ok( 'prune() removes rows older than retention', 1 === $removed, '' );

/* ===================================================================
 * C. Search Console import — the real file shapes
 * =================================================================== */
echo "\n=== C. Search-Console CSV import (no API) ===\n";

// C1: GSC's own download — UTF-16LE, tab-separated, English headers.
$u16 = utf8_to_utf16le( "Query\tClicks\tImpressions\tCTR\tPosition\n"
        . "فرش دستباف تبریز\t3\t120\t2,5%\t14.6\n"
        . "قیمت فرش نمدی\t1\t65\t1,5%\t18.2\n" );
$r1 = Haa_Keywords::import_gsc( $u16 );
ok( 'UTF-16LE + tab GSC export parses', is_array( $r1 ) && 2 === $r1['added'], is_wp_error( $r1 ) ? $r1->get_error_message() : '' );
$rows = Haa_Keywords::gsc_rows();
ok( 'Persian query survives UTF-16 decode', 'فرش دستباف تبریز' === $rows[0]['query'], '' );
ok( 'real numbers read: impressions=120 clicks=3', 120 === $rows[0]['impressions'] && 3 === $rows[0]['clicks'], '' );
ok( 'position float read: 14.6', 14.6 === $rows[0]['position'], '' );

// C2: another tool's UTF-8 + comma + Persian headers + Persian digits.
$u8 = "\xEF\xBB\xBFعبارت,کلیک,نمایش,رتبه\n"
        . 'دار قفل چوبی,۱۲,۳۴۰,۷٫۵' . "\n";
$r2 = Haa_Keywords::import_gsc( $u8 );
ok( 'UTF-8 + comma + Persian headers parse', is_array( $r2 ) && 1 === $r2['added'], is_wp_error( $r2 ) ? $r2->get_error_message() : '' );
$rows = Haa_Keywords::gsc_rows();
$dar  = null;
foreach ( $rows as $row ) {
        if ( 'دار قفل چوبی' === $row['query'] ) { $dar = $row; }
}
ok( 'Persian digits converted (نمایش=۳۴۰)', null !== $dar && 340 === $dar['impressions'] && 12 === $dar['clicks'], '' );
ok( 'Persian decimal رتبه ۷٫۵ → 7.5', null !== $dar && 7.5 === $dar['position'], '' );

// C3: merge-dedupe — same query re-imported with fresh numbers wins.
$r3 = Haa_Keywords::import_gsc( "\xEF\xBB\xBFعبارت,کلیک,نمایش,رتبه\nدار قفل چوبی,20,500,6.1\n" );
$rows = Haa_Keywords::gsc_rows();
$dar  = null;
foreach ( $rows as $row ) {
        if ( 'دار قفل چوبی' === $row['query'] ) { $dar = $row; }
}
ok( 're-import merges (no duplicate row)', is_array( $r3 ) && 3 === count( $rows ), '' );
ok( 'fresh numbers win (500 impressions)', null !== $dar && 500 === $dar['impressions'], '' );

// C4: garbage and empties become readable errors, never fatal.
ok( 'no query column → WP_Error', is_wp_error( Haa_Keywords::import_gsc( "name,age\nali,30\n" ) ), '' );
ok( 'empty file → WP_Error', is_wp_error( Haa_Keywords::import_gsc( '' ) ), '' );
ok( 'header-only file → WP_Error', is_wp_error( Haa_Keywords::import_gsc( "\xEF\xBB\xBFعبارت,کلیک\n" ) ), '' );

// C5: quoted CSV cells with comma inside survive the comma parser.
$r5 = Haa_Keywords::import_gsc( "\xEF\xBB\xBF\"Top queries\",\"Clicks\",\"Impressions\",\"CTR\",\"Position\"\n\"فرش, قفل و ابزار\",\"2\",\"88\",\"2.2%\",\"11\"\n" );
$rows = Haa_Keywords::gsc_rows();
$comma = null;
foreach ( $rows as $row ) {
        if ( 'فرش, قفل و ابزار' === $row['query'] ) { $comma = $row; }
}
ok( 'quoted cell with embedded comma kept whole', is_array( $r5 ) && null !== $comma && 88 === $comma['impressions'], '' );

echo "\n=== D. Suggest: Google + Bing under a call budget ===\n";

$SUG = json_encode( array( 'x', array( 'فرش ماشینی ۷۰۰ شانه', 'فرش دستباف تبریز', 'فرش نمدی', 'فرش ابریشم' ) ) );
$GLOBALS['http_map'] = array(
        'suggestqueries.google.com' => array( 'code' => 200, 'body' => $SUG ),
        'api.bing.com'              => array( 'code' => 200, 'body' => $SUG ),
        'ac.ecosia.org'             => array( 'code' => 200, 'body' => $SUG ),
);
$GLOBALS['http_log'] = array();

$out = Haa_Keywords::suggest_multi( 'فرش دستباف' );
$hosts = array();
foreach ( $GLOBALS['http_log'] as $h ) {
        if ( false !== strpos( $h['url'], 'bing' ) ) { $hosts[] = 'bing'; }
        elseif ( false !== strpos( $h['url'], 'ecosia' ) ) { $hosts[] = 'ecosia'; }
        else { $hosts[] = 'google'; }
}
ok( 'all three engines queried (Google AND Bing AND Ecosia)',
        in_array( 'google', $hosts, true ) && in_array( 'bing', $hosts, true ) && in_array( 'ecosia', $hosts, true ), '' );
ok( 'call budget respected (≤ SUGGEST_CALLS)', count( $GLOBALS['http_log'] ) <= Haa_Keywords::SUGGEST_CALLS, 'calls=' . count( $GLOBALS['http_log'] ) );
ok( 'suggestions returned and deduped', count( $out ) >= 4 && count( $out ) === count( array_unique( $out ) ), '' );
ok( 'Persian modifiers actually used (seed+چگونه requested)', false !== strpos( implode( '|', wp_list_pluck( $GLOBALS['http_log'], 'url' ) ), rawurlencode( ' چگونه' ) ), '' );

// Google blocked (the Iranian-host default) — Bing + Ecosia must carry the panel.
$GLOBALS['http_map'] = array(
        'suggestqueries.google.com' => array( 'error' => 'cURL error 28' ),
        'api.bing.com'              => array( 'code' => 200, 'body' => $SUG ),
        'ac.ecosia.org'             => array( 'code' => 200, 'body' => $SUG ),
);
$GLOBALS['http_log'] = array();
$out2 = Haa_Keywords::suggest_multi( 'فرش دستباف' );
ok( 'Google down → Bing + Ecosia still fill the list', count( $out2 ) >= 4, '' );

echo "\n=== E. research() end-to-end: ranks, flags, coverage, cap ===\n";

// Published article the site already has (with a real permalink) + a partial one.
$GLOBALS['posts'] = array(
        array( 'id' => 21, 'type' => 'post', 'status' => 'publish', 'title' => 'فرش نمدی چیست؟ راهنمای کامل' ),
);
$GLOBALS['permalinks'] = array( 21 => 'https://www.new.mrdastbaaf.com/%d9%81%d8%B1%d8%B4-%d9%86%d9%85%d8%af%db%8c/' );
Haa_API::$fail         = true; // Model down: the panel must still be full from local sources.
$GLOBALS['http_map']   = array(
        'suggestqueries.google.com' => array( 'code' => 200, 'body' => $SUG ),
        'api.bing.com'              => array( 'code' => 200, 'body' => $SUG ),
        'ac.ecosia.org'             => array( 'code' => 200, 'body' => $SUG ),
        'fa.wikipedia.org'          => array( 'code' => 200, 'body' => json_encode( array( 'فرش نمدی', array( 'فرش نمدی چیست' ), array(), array() ), JSON_UNESCAPED_UNICODE ) ),
);

$res = Haa_Keywords::research( 'فرش نمدی' );
// 2.1.31: rows delivered → the global error stays EMPTY; the model failure
// lives on its own «مدل:» diag line. The old contract (error != '') put the
// model-layer sentence above a full list and lied about the sources.
ok( 'research ok with model down, headline EMPTY when rows exist', true === $res['ok'] && '' === $res['error'], 'err=' . $res['error'] );
ok( 'rows present', count( $res['rows'] ) > 0, 'rows=' . count( $res['rows'] ) );
ok( 'hard cap 40 respected', count( $res['rows'] ) <= 40, '' );

$sources = array();
$miss_row = null;
$gsc_row  = null;
foreach ( $res['rows'] as $row ) {
        $sources[] = $row['source'];
        if ( 'misses' === $row['source'] && null === $miss_row ) { $miss_row = $row; }
        if ( 'gsc' === $row['source'] && null === $gsc_row ) { $gsc_row = $row; }
}
ok( 'misses source appears in the panel', null !== $miss_row, '' );
ok( 'miss row carries the counter note', null !== $miss_row && '' !== $miss_row['note'], '' );
ok( 'GSC source appears with real numbers in note', null !== $gsc_row && false !== strpos( $gsc_row['note'], 'نمایش' ), '' );

$wiki_row = null;
foreach ( $res['rows'] as $row ) {
        if ( 'wiki' === $row['source'] && null === $wiki_row ) { $wiki_row = $row; }
}
ok( 'wiki source appears with its own badge label', null !== $wiki_row, '' );
ok( 'diag present and counts local sources',
        isset( $res['diag'] ) && is_array( $res['diag'] ) && count( $res['diag'] ) >= 5, '' );
$diag37 = implode( ' | ', (array) ( $res['diag'] ?? array() ) );
ok( 'diag names the model failure explicitly', false !== strpos( $diag37, 'مدل: ' ), '' );

$first = $res['rows'][0];
ok( 'suggest ranks first (real user typing wins)', 'suggest' === $first['source'], 'got=' . $first['source'] );
ok( 'every row carries intent + ai + coverage keys',
        isset( $first['intent'], $first['ai'], $first['coverage']['state'] ), '' );

$covered = null;
foreach ( $res['rows'] as $row ) {
        if ( 'has' === $row['coverage']['state'] ) { $covered = $row; break; }
}
ok( 'existing article marks a row «دارد» with a link', null !== $covered && '' !== $covered['coverage']['url'], '' );
ok( 'coverage link is the DECODED Persian permalink (2.1.26 rule)',
        null !== $covered && 'https://www.new.mrdastbaaf.com/فرش-نمدی/' === $covered['coverage']['url'], '' );

echo "\n=== F. intent + AI flag ===\n";

ok( 'فرق… → question (AI badge)', true === Haa_Keywords::is_question( 'فرق کفش رانینگ با پیاده روی' ), '' );
ok( '…چیست → question', true === Haa_Keywords::is_question( 'کفش رانینگ چیست' ), '' );
ok( 'trailing ؟ → question', true === Haa_Keywords::is_question( 'کفش رانینگ برای کف پای صاف چی بخرم؟' ), '' );
ok( 'قیمت… → not question', false === Haa_Keywords::is_question( 'قیمت کفش رانینگ' ), '' );
ok( 'قیمت → transactional', 'transactional' === Haa_Keywords::guess_intent( 'قیمت کفش رانینگ' ), '' );
ok( 'تهران → local', 'local' === Haa_Keywords::guess_intent( 'فرش دستباف تهران کجا موجود است' ), '' );
ok( 'بهترین → commercial', 'commercial' === Haa_Keywords::guess_intent( 'بهترین فرش ماشینی' ), '' );
ok( 'plain → informational', 'informational' === Haa_Keywords::guess_intent( 'فرش نمدی' ), '' );
ok( 'planner accepts the local intent end-to-end',
        in_array( 'local', Haa_Planner::INTENTS, true ) && 'محلی' === Haa_Planner::intent_label( 'local' ), '' );
$san = Haa_Planner::sanitize_row( array( 'title' => 'فرش دستباف تهران کجا بخریم؟', 'intent' => 'local' ) );
ok( 'sanitize_row keeps local', null !== $san && 'local' === $san['intent'], '' );

echo "\n=== G. coverage thresholds (direct) ===\n";

$posts = array(
        array( 'id' => 21, 'title' => 'فرش نمدی چیست؟ راهنمای کامل', 'tokens' => array_fill_keys( Haa_Search::tokenize( 'فرش نمدی چیست؟ راهنمای کامل' ), 1 ) ),
);
$c1 = Haa_Keywords::coverage_of( 'فرش نمدی چیست', $posts );
ok( 'near-exact topic → has', 'has' === $c1['state'], 'score=' . $c1['score'] );
$c2 = Haa_Keywords::coverage_of( 'قیمت فرش نمدی', $posts );
ok( 'partial overlap → half', 'half' === $c2['state'], 'score=' . $c2['score'] );
$c3 = Haa_Keywords::coverage_of( 'کفش رانینگ سایز ۴۶', $posts );
ok( 'unrelated → none', 'none' === $c3['state'] && '' === $c3['url'], '' );

echo "\n=== H. Excel exports ===\n";

$GLOBALS['db']['topics'] = array();
Haa_Planner::insert( array( 'title' => 'فرش دستباف تهران کجا بخریم؟', 'keyword' => 'فرش تهران', 'intent' => 'local', 'priority' => 8, 'source' => 'keyword', 'status' => 'suggested', 'reason' => 'تست' ) );
$csv = Haa_Keywords::csv_planner( '' );
ok( 'planner export has the promised columns', false !== strpos( $csv, 'عبارت/موضوع' ) && false !== strpos( $csv, 'اولویت' ), '' );
ok( 'local intent exported with its Persian label', false !== strpos( $csv, 'محلی' ), '' );
$csv_m = Haa_Keywords::csv_misses();
ok( 'misses export carries phrase + count', false !== strpos( $csv_m, 'عبارت' ) && false !== strpos( $csv_m, 'ابریشم اصل چقدر؟' ), '' );
$csv_g = Haa_Keywords::csv_gsc();
ok( 'gsc export carries impressions', false !== strpos( $csv_g, '500' ), '' );
$escaped = Haa_Util::escape_csv_field( 'he said "فرش, قفل"' );
ok( 'csv cell escaping survives quotes+comma', false !== strpos( $escaped, '""' ), $escaped );

echo "\n=== I. AJAX wiring contract ===\n";
// 2.1.28 shipped ajax_kw_research() without its wp_ajax_ registration —
// admin-ajax answered wp_die( '0', 400 ) and the panel showed
// «پاسخ سرور JSON نبود (HTTP 400): 0». The suites never saw it because
// they call the engine directly. This contract makes the class of bug
// impossible: EVERY action admin.js posts must be registered in PHP.
$js_src = (string) file_get_contents( dirname( __DIR__ ) . '/assets/js/admin.js' );
preg_match_all( "/post\(\s*'([a-z_]+)'/", $js_src, $m );
$js_actions = array_values( array_unique( $m[1] ) );
ok( 'admin.js posts at least the two known actions', count( $js_actions ) >= 2, 'found=' . implode( ',', $js_actions ) );
$php_registry = '';
foreach ( glob( dirname( __DIR__ ) . '/includes/*.php' ) as $php_file ) {
        $php_registry .= (string) file_get_contents( $php_file );
}
foreach ( glob( dirname( __DIR__ ) . '/modules/*.php' ) as $php_file ) {
        $php_registry .= (string) file_get_contents( $php_file );
}
foreach ( $js_actions as $js_action ) {
        ok( "post('$js_action') has a wp_ajax_ registration",
                false !== strpos( $php_registry, "'wp_ajax_{$js_action}'" ) );
}
ok( 'kw research handler is registered on wp_ajax_ (2.1.28 hotfix)',
        false !== strpos( $php_registry, "add_action( 'wp_ajax_haai_kw_research', array( \$this, 'ajax_kw_research' ) )" ) );

echo "\n=== J. 2.1.32 — per-seed caches + the total research budget ===\n";
// Live report on 2.1.31: first click «Failed to fetch», second/third fine —
// the cold run stacked the suggest sweep (≤20s) + wiki + the 25s model wait
// past the host's ~60s proxy limit, and the browser showed a raw English
// network error. Locked here: RESEARCH_BUDGET=40 caps the whole request,
// per-seed source/model caches make the retry near-instant, the diag says
// «(کش)» / «سقف زمانی» honestly, and admin.js auto-retries a dead fetch once.

$SEED_J  = 'قالیچه دستباف';
$IDEAS_J = '{"keywords":[{"keyword":"قالیچه دستباف تبریز"},{"keyword":"قیمت قالیچه دستباف"}]}';

// J1. first research: the network runs, both caches are written.
delete_transient( Haa_Keywords::src_cache_key( $SEED_J ) );
delete_transient( Haa_Keywords::ai_cache_key( $SEED_J ) );
http_reset();
$GLOBALS['http_log']    = array();
$GLOBALS['api_calls']   = array();
$GLOBALS['api_replies'] = array( $IDEAS_J );
Haa_API::$fail          = false;
$GLOBALS['http_map']    = array(
        'suggestqueries.google.com' => array( 'code' => 200, 'body' => $SUG ),
        'api.bing.com'              => array( 'code' => 200, 'body' => $SUG ),
        'ac.ecosia.org'             => array( 'code' => 200, 'body' => $SUG ),
        'fa.wikipedia.org'          => array( 'code' => 200, 'body' => json_encode( array( $SEED_J, array( 'قالیچه دستباف نایین' ), array(), array() ), JSON_UNESCAPED_UNICODE ) ),
);
$r_j1 = Haa_Keywords::research( $SEED_J );
ok( 'J: first research runs the network', count( $GLOBALS['http_log'] ) > 0, 'http=' . count( $GLOBALS['http_log'] ) );
ok( 'J: first research writes the source cache', is_array( get_transient( Haa_Keywords::src_cache_key( $SEED_J ) ) ), '' );
ok( 'J: successful model ideas are cached too', is_array( get_transient( Haa_Keywords::ai_cache_key( $SEED_J ) ) ), '' );
$j_http = count( $GLOBALS['http_log'] );
$j_api  = count( $GLOBALS['api_calls'] );

// J2. the SAME seed again: zero HTTP, zero model calls, honest «(کش)».
$r_j2 = Haa_Keywords::research( $SEED_J );
ok( 'J: second research makes NO http calls', $j_http === count( $GLOBALS['http_log'] ), 'delta=' . ( count( $GLOBALS['http_log'] ) - $j_http ) );
ok( 'J: second research makes NO model calls', $j_api === count( $GLOBALS['api_calls'] ), 'delta=' . ( count( $GLOBALS['api_calls'] ) - $j_api ) );
$diag_j2 = implode( ' | ', (array) ( $r_j2['diag'] ?? array() ) );
ok( 'J: cache-served diag says «(کش)»', false !== strpos( $diag_j2, '(کش)' ), '' );
ok( 'J: cache-served rows match the fresh run', count( $r_j1['rows'] ) === count( $r_j2['rows'] ), 'fresh=' . count( $r_j1['rows'] ) . ' cached=' . count( $r_j2['rows'] ) );

// J3. invalidation → the network runs again.
delete_transient( Haa_Keywords::src_cache_key( $SEED_J ) );
delete_transient( Haa_Keywords::ai_cache_key( $SEED_J ) );
Haa_Keywords::research( $SEED_J );
ok( 'J: after invalidation the network runs again', count( $GLOBALS['http_log'] ) > $j_http, '' );
delete_transient( Haa_Keywords::src_cache_key( $SEED_J ) );
delete_transient( Haa_Keywords::ai_cache_key( $SEED_J ) );

// J4. exhausted budget (test seam: forced stale $t0): EVERY network stage
//     refuses, the panel still fills from local rows, the diag is honest.
http_reset();
$GLOBALS['http_log']    = array();
$GLOBALS['api_calls']   = array();
unset( $GLOBALS['api_replies'] );
$r_j4 = Haa_Keywords::research( 'بودجه تمام فرش', microtime( true ) - 10000 );
ok( 'J: exhausted budget → zero http calls', 0 === count( $GLOBALS['http_log'] ), 'http=' . count( $GLOBALS['http_log'] ) );
ok( 'J: exhausted budget → zero model calls', 0 === count( $GLOBALS['api_calls'] ), 'api=' . count( $GLOBALS['api_calls'] ) );
ok( 'J: exhausted budget → the panel still fills from local rows',
        true === $r_j4['ok'] && count( $r_j4['rows'] ) > 0, 'rows=' . count( $r_j4['rows'] ) );
$diag_j4 = implode( ' | ', (array) ( $r_j4['diag'] ?? array() ) );
ok( 'J: exhausted budget → suggest family says the ceiling',
        false !== strpos( $diag_j4, 'ساجست/ویکی‌پدیا: رد شد — سقف زمانی' ), '' );
ok( 'J: exhausted budget → the model line says the ceiling',
        false !== strpos( $diag_j4, 'مدل: رد شد — سقف زمانی' ), '' );

// J5. ~16s left → the model call runs with timeout=16 (clamped 12–25).
$GLOBALS['http_map']   = array(
        'suggestqueries.google.com' => array( 'code' => 200, 'body' => $SUG ),
        'api.bing.com'              => array( 'code' => 200, 'body' => $SUG ),
        'ac.ecosia.org'             => array( 'code' => 200, 'body' => $SUG ),
        'fa.wikipedia.org'          => array( 'error' => 'wiki down' ),
);
http_reset();
$GLOBALS['http_log']    = array();
$GLOBALS['api_calls']   = array();
$GLOBALS['api_replies'] = array( $IDEAS_J );
Haa_Keywords::research( 'بودجه نیمه فرش', microtime( true ) - ( Haa_Keywords::RESEARCH_BUDGET - 16 ) );
$j5_timeout = (int) ( $GLOBALS['api_calls'][0]['opts']['timeout'] ?? 0 );
ok( 'J: leftover budget narrows the model timeout (16s left → timeout=16)',
        16 === $j5_timeout, 'timeout=' . $j5_timeout );

// J6. 10s left (< the 12s floor) → the model stage is skipped, diag honest.
http_reset();
$GLOBALS['http_log']    = array();
$GLOBALS['api_calls']   = array();
$GLOBALS['api_replies'] = array( $IDEAS_J );
$r_j6 = Haa_Keywords::research( 'بودجه ته فرش', microtime( true ) - ( Haa_Keywords::RESEARCH_BUDGET - 10 ) );
ok( 'J: <12s left → the model stage is skipped', 0 === count( $GLOBALS['api_calls'] ), 'api=' . count( $GLOBALS['api_calls'] ) );
$diag_j6 = implode( ' | ', (array) ( $r_j6['diag'] ?? array() ) );
ok( 'J: <12s left → the model line names the ceiling', false !== strpos( $diag_j6, 'مدل: رد شد — سقف زمانی' ), '' );

// J7. the JS side: a dead fetch gets ONE automatic retry + Persian copy,
//     in BOTH the source and the shipped minified asset.
ok( 'J: admin.js auto-retries once on a network death',
        false !== strpos( $js_src, 'netRetries' ) && false !== strpos( $js_src, 'setTimeout(search' ), '' );
ok( 'J: admin.js explains «Failed to fetch» in Persian',
        false !== strpos( $js_src, 'Failed to fetch' ) && false !== strpos( $js_src, 'ارتباط با سرور قطع شد' ), '' );
$js_min_src = (string) file_get_contents( dirname( __DIR__ ) . '/assets/js/admin.min.js' );
ok( 'J: admin.min.js carries the same retry contract',
        false !== strpos( $js_min_src, 'netRetries' ) && false !== strpos( $js_min_src, 'ارتباط با سرور قطع شد' ), '' );

echo "\n=== K. 2.1.33 — the planner loop is honest (count, zero-state, dups) ===\n";
// Live report on 2.1.32: the panel said «برنامه‌ریز: 0 موضوع پیشنهادی» right
// after the admin had queued selections. The count came from the same capped
// rows('suggested', 10) loop that renders the display rows, and the add
// handler flattened duplicates and failures into «0 موضوع از N کلیدواژه
// اضافه شد». Locked here: the diag reads the UNCAPPED status histogram, the
// zero state teaches the tick→button→re-search loop, the display stays
// capped at 10 rows, and the add notice tells «already queued» apart from
// «failed».

// K1. zero suggested topics → the diag line teaches the loop.
$GLOBALS['db']['topics'] = array();
$r_k1    = Haa_Keywords::research( 'حلقه برنامه‌ریز', microtime( true ) - 10000 );
$diag_k1 = implode( ' | ', (array) ( $r_k1['diag'] ?? array() ) );
ok( 'K: zero suggested topics → the diag line teaches the tick→button loop',
        false !== strpos( $diag_k1, 'برنامه‌ریز: 0 موضوع پیشنهادی' )
        && false !== strpos( $diag_k1, 'افزودن انتخاب‌شده‌ها به برنامه‌ریز' ), '' );

// K2. the panel's exact insert shape → the next research counts and lists them.
Haa_Planner::insert( array( 'title' => 'آموزش فرش دستباف با قلاب', 'keyword' => 'آموزش فرش دستباف با قلاب', 'intent' => Haa_Keywords::guess_intent( 'آموزش فرش دستباف با قلاب' ), 'reason' => 'از پنل پیشنهاد کلمه کلیدی.', 'source' => 'keyword', 'priority' => 5 ) );
Haa_Planner::insert( array( 'title' => 'قیمت فرش دستباف نایین', 'keyword' => 'قیمت فرش دستباف نایین', 'intent' => Haa_Keywords::guess_intent( 'قیمت فرش دستباف نایین' ), 'reason' => 'از پنل پیشنهاد کلمه کلیدی.', 'source' => 'keyword', 'priority' => 5 ) );
$r_k2    = Haa_Keywords::research( 'حلقه برنامه‌ریز', microtime( true ) - 10000 );
$diag_k2 = implode( ' | ', (array) ( $r_k2['diag'] ?? array() ) );
ok( 'K: two queued topics → «برنامه‌ریز: 2 موضوع پیشنهادی»',
        false !== strpos( $diag_k2, 'برنامه‌ریز: 2 موضوع پیشنهادی' ), '' );
$k_listed = array();
foreach ( (array) $r_k2['rows'] as $r ) {
        if ( 'planner' === $r['source'] ) {
                $k_listed[] = (string) $r['keyword'];
        }
}
ok( 'K: queued topics reappear as planner-sourced rows',
        in_array( 'آموزش فرش دستباف با قلاب', $k_listed, true ), '' );

// K3. the count is the TRUE total; the display keeps its 10-row cap
//     (the harness wpdb is LIMIT-faithful since 2.1.33).
$GLOBALS['db']['topics'] = array();
for ( $i = 1; $i <= 12; $i++ ) {
        Haa_Planner::insert( array( 'title' => 'موضوع آزمون حلقه ' . $i, 'keyword' => 'موضوع آزمون حلقه ' . $i, 'intent' => 'informational', 'reason' => 'تست', 'source' => 'keyword', 'priority' => 5 ) );
}
$r_k3    = Haa_Keywords::research( 'حلقه برنامه‌ریز', microtime( true ) - 10000 );
$diag_k3 = implode( ' | ', (array) ( $r_k3['diag'] ?? array() ) );
ok( 'K: the diag count is uncapped (12 suggested → «برنامه‌ریز: 12»)',
        false !== strpos( $diag_k3, 'برنامه‌ریز: 12 موضوع پیشنهادی' ), '' );
$k_rows = 0;
foreach ( (array) $r_k3['rows'] as $r ) {
        if ( 'planner' === $r['source'] ) {
                $k_rows++;
        }
}
ok( 'K: the display keeps its 10-row cap while the count says 12',
        10 === $k_rows, 'rows=' . $k_rows );

// K4. the add handler's loop contract: a fresh keyword lands, the exact
//     same title is refused, and existing_topic() is the dup probe.
$GLOBALS['db']['topics'] = array();
$id_k4 = Haa_Planner::insert( array( 'title' => 'فرش دستباف کاشان', 'keyword' => 'فرش دستباف کاشان', 'intent' => 'informational', 'reason' => 'تست', 'source' => 'keyword', 'priority' => 5 ) );
ok( 'K: a fresh panel keyword lands in the planner', $id_k4 > 0, '' );
ok( 'K: the exact same keyword is refused as a duplicate',
        0 === Haa_Planner::insert( array( 'title' => 'فرش دستباف کاشان', 'keyword' => 'فرش دستباف کاشان', 'intent' => 'informational', 'reason' => 'تست', 'source' => 'keyword', 'priority' => 5 ) ), '' );
ok( 'K: existing_topic() finds the queued title (the dup probe of the add handler)',
        $id_k4 === (int) Haa_Planner::existing_topic( 'فرش دستباف کاشان' ), '' );

// K5. source contracts: the uncapped count, the honest zero state, and the
//     add handler that separates duplicates from a failed write.
$kw_src_k = (string) file_get_contents( dirname( __DIR__ ) . '/includes/class-haa-keywords.php' );
ok( 'K: the diag count reads the planner status histogram (uncapped)',
        false !== strpos( $kw_src_k, "Haa_Planner::counts()['suggested']" ), '' );
ok( 'K: the zero-state hint ships in the source',
        false !== strpos( $kw_src_k, 'از فهرست بالا تیک بزنید' ), '' );
$aw_src_k = (string) file_get_contents( dirname( __DIR__ ) . '/modules/class-haa-module-article-writer.php' );
ok( 'K: the add handler tells «already queued» apart from a failed write',
        false !== strpos( $aw_src_k, 'existing_topic' )
        && false !== strpos( $aw_src_k, 'همهٔ انتخاب‌ها از قبل در برنامه‌ریز بودند' )
        && false !== strpos( $aw_src_k, 'افزودن به برنامه‌ریز ممکن نشد' ), '' );

// ---------------------------------------------------------------------------
// L. 2.1.34 — branding, the «نتایج جست‌وجو» export, and the taught page order.
//     Live report: the Excel file was named mirza-* («میرزا کیه؟»), the
//     content-plan sheet was empty (the planner WAS empty — legitimate, but
//     it read as a broken export), and the article-writer page order was
//     jumbled («خیلی درهم برهمه» — the write form greeted before research).
// ---------------------------------------------------------------------------
$aw_l = (string) file_get_contents( dirname( __DIR__ ) . '/modules/class-haa-module-article-writer.php' );
$kw_l = (string) file_get_contents( dirname( __DIR__ ) . '/includes/class-haa-keywords.php' );

// L1. the old draft name is gone from the WHOLE plugin source (code + docs).
$mirza_hits = array();
$it_l = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( dirname( __DIR__ ), FilesystemIterator::SKIP_DOTS ) );
foreach ( $it_l as $f_l ) {
        if ( $f_l->isDir() ) { continue; }
        $p_l = $f_l->getPathname();
        if ( false !== strpos( $p_l, '/.git' ) || false !== strpos( $p_l, '/dev-tests/' ) || false !== strpos( $p_l, '/node_modules/' ) || false !== strpos( $p_l, '/shots/' ) ) { continue; }
        if ( ! preg_match( '/\.(php|md|js|css|txt|pot|json)$/', $p_l ) ) { continue; }
        if ( false !== stripos( (string) file_get_contents( $p_l ), 'mirza' ) ) { $mirza_hits[] = basename( $p_l ); }
}
ok( 'L: the old draft name «mirza» is gone from the whole plugin', 0 === count( $mirza_hits ), implode( ',', $mirza_hits ) );

// L2. every export filename carries the plugin brand.
foreach ( array( 'hami-keyword-research-', 'hami-content-plan-', 'hami-misses-', 'hami-search-console-' ) as $n_l ) {
        ok( "L: export filename «{$n_l}» ships", false !== strpos( $aw_l, "'" . $n_l ), '' );
}

// L3. the fourth export refuses an empty state with guidance, not an empty sheet.
ok( 'L: the research export is gated by last_research()',
        false !== strpos( $aw_l, 'if ( ! Haa_Keywords::last_research() )' ), '' );
ok( 'L: the empty-state click explains itself (wp_die guidance)',
        false !== strpos( $aw_l, 'هنوز جست‌وجویی ثبت نشده' ), '' );

// L4. the storage contract in the engine.
ok( 'L: research() stores the delivered panel; last_research()/csv_research() ship',
        false !== strpos( $kw_l, "'haai_kw_last_research'" )
        && false !== strpos( $kw_l, 'public static function last_research()' )
        && false !== strpos( $kw_l, 'public static function csv_research()' ), '' );

// L5. the page order IS the work order: research → planner → write.
$rs_start = strpos( $aw_l, 'public function render_screen()' );
$rs_end   = strpos( $aw_l, 'private function render_topics_form()' );
ok( 'L: render_screen body sits before the extracted topics form', false !== $rs_start && false !== $rs_end && $rs_end > $rs_start, '' );
preg_match_all( '/\\$this->render_(\\w+)\\(\\)/', (string) substr( $aw_l, (int) $rs_start, (int) $rs_end - (int) $rs_start ), $m_l );
ok( 'L: the page order is keywords → planner → topics → research → review → refresh → profile',
        array( 'keywords_panel', 'planner_panel', 'topics_form', 'research_panel', 'review_panel', 'refresh_panel', 'profile_panel' ) === $m_l[1],
        implode( ',', (array) $m_l[1] ) );

// L6. the article form proposes by itself: pre-fill + honest note + guide.
ok( 'L: the topics form pre-fills from planner suggestions',
        false !== strpos( $aw_l, "Haa_Planner::rows( 'suggested', 8 )" ) && false !== strpos( $aw_l, 'esc_textarea( implode' ), '' );
ok( 'L: the pre-fill says where its rows came from',
        false !== strpos( $aw_l, 'این موضوع‌ها از برنامه‌ریز پر شده‌اند' ), '' );
ok( 'L: the page ships the three-step guide strip',
        false !== strpos( $aw_l, 'مسیر پیشنهادی' ), '' );
ok( 'L: the article form is a titled panel («ساخت مقاله»)',
        false !== strpos( $aw_l, 'ساخت مقاله' ), '' );

// L7. the content-plan button cannot produce an empty sheet anymore.
ok( 'L: the content-plan export is disabled while the planner is empty',
        false !== strpos( $aw_l, "\$planner_has = (bool) Haa_Planner::rows( '', 1 )" ) && false !== strpos( $aw_l, 'disabled( ! $planner_has )' ), '' );
ok( 'L: the tools row explains where each export comes from',
        false !== strpos( $aw_l, 'همان فهرستی است که آخرین «پیدا کن» ساخت' ), '' );

// L8. functional: a research run records exactly what it delivered.
unset( $GLOBALS['opts']['haai_kw_last_research'] );
ok( 'L: nothing stored yet → last_research() is empty', '' === Haa_Keywords::last_research(), '' );
$r_l8 = Haa_Keywords::research( 'برندینگ خروجی', microtime( true ) - 10000 );
$last_l = Haa_Keywords::last_research();
ok( 'L: research() records the last panel (seed + rows)',
        is_array( $last_l ) && 'برندینگ خروجی' === $last_l['seed'] && ! empty( $last_l['rows'] ), '' );

// L9. the CSV mirrors the panel: one data row per row, Persian labels.
$csv_l = Haa_Keywords::csv_research();
$lines_l = array_values( array_filter( explode( "\n", $csv_l ), 'strlen' ) );
ok( 'L: csv_research() header mirrors the panel columns',
        false !== strpos( $csv_l, 'عبارت' ) && false !== strpos( $csv_l, 'منبع' ) && false !== strpos( $csv_l, 'پوشش همین سایت' ), '' );
ok( 'L: csv_research() has a data row per stored row',
        count( $lines_l ) === 1 + count( (array) $last_l['rows'] ), 'lines=' . count( $lines_l ) . ' rows=' . count( (array) $last_l['rows'] ) );
ok( 'L: planner rows carry their Persian source label in the CSV',
        false !== strpos( $csv_l, 'برنامه‌ریز' ) && false !== strpos( $csv_l, 'بی‌جواب' ), '' );

// L10. the word map is the panel's own vocabulary (SRC/INT/COV).
update_option( 'haai_kw_last_research', array(
        'seed' => 'آزمون',
        'time' => time(),
        'rows' => array(
                array( 'keyword' => 'فرش شست‌وشو', 'source' => 'suggest', 'intent' => 'informational', 'ai' => true, 'coverage' => array( 'state' => 'has' ), 'note' => 'نمایش: ۱' ),
                array( 'keyword' => 'خرید فرش نایین', 'source' => 'ai', 'intent' => 'transactional', 'ai' => false, 'coverage' => array( 'state' => 'half' ), 'note' => '' ),
        ),
) );
$csv_l2 = Haa_Keywords::csv_research();
ok( 'L: source/intent/coverage/ai map to the panel words',
        false !== strpos( $csv_l2, 'ساجست کاربران' ) && false !== strpos( $csv_l2, 'جواب دارد' ) && false !== strpos( $csv_l2, 'بله' )
        && false !== strpos( $csv_l2, 'مدل' ) && false !== strpos( $csv_l2, 'نیمه' ) && false !== strpos( $csv_l2, 'تراکنشی' ), '' );

// L11. the nothing-stored states stay honest.
update_option( 'haai_kw_last_research', '' );
ok( 'L: an emptied option reads as nothing stored', '' === Haa_Keywords::last_research(), '' );
$csv_l0 = Haa_Keywords::csv_research();
$lines_l0 = array_values( array_filter( explode( "\n", $csv_l0 ), 'strlen' ) );
ok( 'L: csv_research() with nothing stored is header-only',
        1 === count( $lines_l0 ) && false !== strpos( $csv_l0, 'پوشش همین سایت' ), '' );

// ---------------------------------------------------------------------------
// M. 2.1.35 — the «مدل:» line speaks the truth, and the Excel توضیح column
//     earns its place. Live report on 2.1.34: (۱) the panel showed the raw
//     Gemini quota paragraph on the «مدل:» line (fixed in the API mapper,
//     suite 18 section D) and an all-benched walk would STILL have shown
//     «همه منابع…» there — model_diag_text() existed since 2.1.31 but was
//     never wired into research(); (۲) the exported sheet's توضیح column was
//     empty for all 33 suggest rows — the engine that delivered each phrase
//     (بینگ/اکوسیا/گوگل) is real provenance the sheet can carry; (۳)
//     «فرش دستبافت اردبیل» read as informational — most provincial
//     capitals were missing from the local-intent line.
// ---------------------------------------------------------------------------

// M1. the all-benched translation finally reaches the «مدل:» diag line.
$SEED_M = 'بخش ام مدل';
delete_transient( Haa_Keywords::src_cache_key( $SEED_M ) );
delete_transient( Haa_Keywords::ai_cache_key( $SEED_M ) );
set_transient( Haa_API::LAST_CHAT_ERROR, array(
        'code'  => 'haai_timeout',
        'msg'   => 'مهلت اتصال به سرویس AI تمام شد (25 ثانیه).',
        'model' => 'gemini',
        'at'    => time(),
), DAY_IN_SECONDS );
http_reset();
$GLOBALS['http_map']    = array(
        'suggestqueries.google.com' => array( 'code' => 200, 'body' => $SUG ),
        'api.bing.com'              => array( 'code' => 200, 'body' => $SUG ),
        'ac.ecosia.org'             => array( 'code' => 200, 'body' => $SUG ),
        'fa.wikipedia.org'          => array( 'code' => 200, 'body' => json_encode( array( $SEED_M, array(), array(), array() ), JSON_UNESCAPED_UNICODE ) ),
);
$GLOBALS['api_replies'] = array( array(
        'error_code' => 'haai_all_benched',
        'error'      => 'همه منابع در حال خطا هستند؛ چند لحظه بعد دوباره تلاش کنید.',
) );
Haa_API::$fail = false;
$r_m1    = Haa_Keywords::research( $SEED_M );
$diag_m1 = implode( ' | ', (array) ( $r_m1['diag'] ?? array() ) );
ok( 'M: all-benched walk → the «مدل:» line is the translated sentence',
        false !== strpos( $diag_m1, 'ارائه‌دهنده‌های مدل موقتاً خطا داده‌اند' ), '' );
ok( 'M: the generic «همه منابع» sentence never reaches the panel',
        false === strpos( $diag_m1, 'همه منابع' ), '' );
ok( 'M: the real remembered cause rides along',
        false !== strpos( $diag_m1, 'آخرین علت واقعی' ) && false !== strpos( $diag_m1, 'مهلت اتصال' ), '' );
delete_transient( Haa_API::LAST_CHAT_ERROR );

// M2. suggest_multi() hands back the engine provenance (BC: 2-arg callers keep working).
$SEED_M2 = 'فرش ام موتور';
http_reset();
$GLOBALS['http_map'] = array(
        'suggestqueries.google.com' => array( 'code' => 200, 'body' => $SUG ),
        'api.bing.com'              => array( 'code' => 200, 'body' => $SUG ),
        'ac.ecosia.org'             => array( 'code' => 200, 'body' => $SUG ),
);
$errs_m2 = array();
$eng_m2  = array();
$out_m2  = Haa_Keywords::suggest_multi( $SEED_M2, $errs_m2, $eng_m2 );
ok( 'M: suggest_multi still returns clean unique phrases',
        count( $out_m2 ) >= 4 && count( $out_m2 ) === count( array_unique( $out_m2 ) ), 'n=' . count( $out_m2 ) );
$eng_ok_m = true;
foreach ( $out_m2 as $ph_m ) {
        $key_m = Haa_Search::normalize_text( $ph_m );
        if ( ! isset( $eng_m2[ $key_m ] ) || ! in_array( $eng_m2[ $key_m ], array( 'بینگ', 'اکوسیا', 'گوگل' ), true ) ) {
                $eng_ok_m = false;
        }
}
ok( 'M: every phrase carries a real engine in the OUT map',
        $eng_ok_m && count( $eng_m2 ) === count( $out_m2 ), '' );

// M3. research() rows + cache + CSV all carry the provenance. The fake
//     engines echo one shared body, so the FIRST caller (بینگ) owns every row.
delete_transient( Haa_Keywords::src_cache_key( $SEED_M2 ) );
delete_transient( Haa_Keywords::ai_cache_key( $SEED_M2 ) );
$GLOBALS['api_replies'] = array( $IDEAS_J );
$r_m3    = Haa_Keywords::research( $SEED_M2 );
$note_m3 = '';
foreach ( (array) $r_m3['rows'] as $r ) {
        if ( 'suggest' === $r['source'] && '' === $note_m3 ) { $note_m3 = (string) $r['note']; }
}
ok( 'M: suggest rows in the panel carry «ساجست <engine>»',
        'ساجست بینگ' === $note_m3, 'note=' . $note_m3 );
$src_m3 = get_transient( Haa_Keywords::src_cache_key( $SEED_M2 ) );
ok( 'M: the source cache stores the engine map too',
        is_array( $src_m3 ) && ! empty( $src_m3['engines'] ), '' );
$r_m3b   = Haa_Keywords::research( $SEED_M2 );
$note_m3b = '';
foreach ( (array) $r_m3b['rows'] as $r ) {
        if ( 'suggest' === $r['source'] && '' === $note_m3b ) { $note_m3b = (string) $r['note']; }
}
ok( 'M: the cached run keeps the engine note', 'ساجست بینگ' === $note_m3b, 'note=' . $note_m3b );
$csv_m3 = Haa_Keywords::csv_research();
ok( 'M: the CSV توضیح column names the engine', false !== strpos( $csv_m3, 'ساجست بینگ' ), '' );

// M4. the local-intent city list grew (the 2.1.34 live sheet had
//     «فرش دستبافت اردبیل» marked informational).
ok( 'M: اردبیل reads as local now', 'local' === Haa_Keywords::guess_intent( 'فرش دستبافت اردبیل' ), '' );
ok( 'M: همدان reads as local now', 'local' === Haa_Keywords::guess_intent( 'فرش دستبافت همدان' ), '' );
ok( 'M: a plain phrase stays informational', 'informational' === Haa_Keywords::guess_intent( 'فرش دستبافت' ), '' );

echo ( $fail ? "\nFAILURES: $fail\n" : "\nALL 116 CHECKS GREEN\n" );
exit( $fail ? 1 : 0 );
