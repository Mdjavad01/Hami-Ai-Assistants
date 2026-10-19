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
ok( 'research ok with model down (error noted, not fatal)', true === $res['ok'] && '' !== $res['error'], '' );
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

echo ( $fail ? "\nFAILURES: $fail\n" : "\nALL 51 CHECKS GREEN\n" );
exit( $fail ? 1 : 0 );
