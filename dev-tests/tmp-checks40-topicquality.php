<?php
/**
 * Regression suite: the planner stopped minting junk topics (2.1.37).
 *
 * The eighth live report (2.1.36) pasted the planner's own output: 8 proposed
 * topics, of which
 *
 *   - 3 were about the WordPress artifact bucket itself — «راهنمای انتخاب
 *     بدون دسته…»، «مقایسه‌ی بدون دسته…»، «بدون دسته چیست؟…» — because
 *     content_category() files every uncategorized document under «بدون
 *     دسته», gaps() saw "2 items, no article" and gap_rows() minted titles
 *     for it as if it were a real subject («این چیه فقط بدون دسته زده؟»);
 *   - 5 were contact-page chrome — «مسیرهای ارتباطی و راه های تماس…»،
 *     «اطلاعات تماس و راه های ارتباط…»، «بررسی بخش های ارتباطی و پشتیبانی…»،
 *     «راهنمای استفاده از سایت…»، «شماره تماس و روش های ارتباطی…» — because
 *     the model's seed vocabulary («واژه‌های پرتکرار تیترهای سایت») was
 *     dominated by the contact page's words: راه، های، تماس، ارتباط.
 *
 * Locked here:
 *   - is_junk_cluster(): the uncategorized bucket (any spelling, ZWNJ or
 *     spaces, English or Persian) is never a gap.
 *   - is_junk_topic(): ALL 8 pasted rows are rejected by the final gate —
 *     and real subjects («راه پله»، «راه های ابریشم»، «فرش دستبافت») pass.
 *   - site_map(): chrome words no longer reach the term list the model is
 *     seeded with (map cache key bumped so old caches cannot leak them).
 *   - gaps()/propose_from_gaps()/propose_from_rows()/propose_with_model():
 *     every automatic birth path gates through the two filters.
 *   - purge_junk_topics(): the stored junk from older builds is deleted ONCE
 *     (migration v10) — suggested rows only, human decisions untouched,
 *     idempotent.
 *   - The wiring: HAAI_DB_VERSION 10, version 2.1.37, the module's honest
 *     hint line, the v2 map-cache key.
 *
 * Run: php dev-tests/tmp-checks40-topicquality.php
 */
require __DIR__ . '/tmp-wp-stubs.php';

$fail = 0;
function ok( $label, $cond, $extra = '' ) {
        global $fail;
        if ( ! $cond ) { $fail++; }
        echo ( $cond ? '  ok   ' : '  FAIL ' ) . str_pad( $label, 62 ) . $extra . "\n";
}

/* ===================================================================
 * A. is_junk_cluster(): the filing state is not a subject
 * =================================================================== */
echo "=== A. is_junk_cluster() ===\n";

ok( 'A: «بدون دسته» is junk', Haa_Planner::is_junk_cluster( 'بدون دسته' ), '' );
ok( 'A: Uncategorized (any case) is junk', Haa_Planner::is_junk_cluster( 'Uncategorized' ), '' );
ok( 'A: ZWNJ spelling «بدون دسته‌بندی» is junk', Haa_Planner::is_junk_cluster( 'بدون دسته‌بندی' ), '' );
ok( 'A: «دسته‌بندی نشده» is junk', Haa_Planner::is_junk_cluster( 'دسته‌بندی نشده' ), '' );
ok( 'A: «عمومی» (renamed default bucket) is junk', Haa_Planner::is_junk_cluster( 'عمومی' ), '' );
ok( 'A: an empty name cannot seed a title either', Haa_Planner::is_junk_cluster( '' ), '' );
ok( 'A: a real cluster is kept', ! Haa_Planner::is_junk_cluster( 'فرش دستبافت' ), '' );
ok( 'A: a real cluster with ZWNJ is kept', ! Haa_Planner::is_junk_cluster( 'قالیچه دستباف' ), '' );

/* ===================================================================
 * B. is_junk_topic(): the EXACT 8 rows the admin was shown
 * =================================================================== */
echo "\n=== B. the eight pasted rows are all junk ===\n";

$pasted = array(
        array( 'راهنمای انتخاب بدون دسته: چیزهایی که قبل از خرید باید بدانید', 'بدون دسته' ),
        array( 'مقایسه‌ی بدون دسته: کدام مدل برای چه کاری مناسب است؟', 'بهترین بدون دسته' ),
        array( 'بدون دسته چیست؟ تعریف، انواع و کاربرد واقعی آن', 'کاربرد بدون دسته' ),
        array( 'مسیرهای ارتباطی و راه های تماس با مستردستباف', 'راه های تماس' ),
        array( 'اطلاعات تماس و راه های ارتباط با مستردستباف', 'راه های ارتباط' ),
        array( 'بررسی بخش های ارتباطی و پشتیبانی مستردستباف', 'ارتباطی مستردستباف' ),
        array( 'راهنمای استفاده از سایت مستردستباف', 'سایت مستردستباف' ),
        array( 'شماره تماس و روش های ارتباطی مستردستباف', 'شماره تماس مستردستباف' ),
);
foreach ( $pasted as $i => $pair ) {
        ok( sprintf( 'B: pasted row %d is junk (title+keyword)', $i + 1 ),
                Haa_Planner::is_junk_topic( $pair[0], $pair[1] ), '' );
}
ok( 'B: row 3 title alone is junk (kw may be edited away)',
        Haa_Planner::is_junk_topic( 'بدون دسته چیست؟ تعریف، انواع و کاربرد واقعی آن' ), '' );
ok( 'B: row 4 keyword alone is junk (title may be edited away)',
        Haa_Planner::is_junk_topic( '', 'راه های تماس' ), '' );
ok( 'B: row 8 keyword alone is junk', Haa_Planner::is_junk_topic( '', 'شماره تماس مستردستباف' ), '' );
ok( 'B: ZWNJ-free re-spelling still caught', Haa_Planner::is_junk_topic( 'راههاي تماس با ما', '' ), '' );
ok( 'B: «تماس با ما» as a model title is junk', Haa_Planner::is_junk_topic( 'تماس با ما و آدرس فروشگاه', '' ), '' );

/* ===================================================================
 * C. Real subjects must pass untouched
 * =================================================================== */
echo "\n=== C. negatives: real topics stay allowed ===\n";

ok( 'C: the gap template for a REAL cluster passes',
        ! Haa_Planner::is_junk_topic( 'راهنمای انتخاب فرش دستبافت: چیزهایی که قبل از خرید باید بدانید', 'فرش دستبافت' ), '' );
ok( 'C: commercial head title passes', ! Haa_Planner::is_junk_topic( 'بهترین فرش دستبافت اردبیل', 'فرش دستبافت اردبیل' ), '' );
ok( 'C: «راهنمای استفاده از فرش دستبافت» passes (only «از سایت» is junk)',
        ! Haa_Planner::is_junk_topic( 'راهنمای استفاده از فرش دستبافت', '' ), '' );
ok( 'C: «راه های ابریشم» is history, not chrome', ! Haa_Planner::is_junk_topic( '', 'راه های ابریشم' ), '' );
ok( 'C: «راه پله فلزی» is a product subject', ! Haa_Planner::is_junk_topic( 'راهنمای انتخاب راه پله فلزی', 'راه پله فلزی' ), '' );
ok( 'C: «دسته گل عروس» is a product subject', ! Haa_Planner::is_junk_topic( '', 'دسته گل عروس' ), '' );
ok( 'C: a 3-token keyword with a chrome head survives («شماره مدل فرش»)',
        ! Haa_Planner::is_junk_topic( '', 'شماره مدل فرش' ), '' );
ok( 'C: informational چیست-title for a real cluster passes',
        ! Haa_Planner::is_junk_topic( 'فرش دستبافت چیست؟ تعریف، انواع و کاربرد واقعی آن', 'کاربرد فرش دستبافت' ), '' );
ok( 'C: empty inputs are not junk', ! Haa_Planner::is_junk_topic( '', '' ), '' );

/* ===================================================================
 * D. site_map(): chrome words never reach the seed vocabulary
 * =================================================================== */
echo "\n=== D. site_map(): the term list is clean ===\n";

delete_transient( Haa_Planner::MAP_CACHE );
$GLOBALS['chunk_rows'] = array(
        array( 'post_id' => 700, 'title' => 'راه های تماس با ما', 'source' => 'page' ),
        array( 'post_id' => 701, 'title' => 'شماره تماس و راه های ارتباط', 'source' => 'page' ),
        array( 'post_id' => 702, 'title' => 'فرش دستبافت اردبیل', 'source' => 'post' ),
        array( 'post_id' => 703, 'title' => 'قیمت فرش دستبافت', 'source' => 'post' ),
        array( 'post_id' => 704, 'title' => 'فرش دستبافت تبریز', 'source' => 'post' ),
);
$map = Haa_Planner::site_map( true );
ok( 'D: the map still builds', ! empty( $map['terms'] ), '' );
ok( 'D: «تماس» is out of the term list', ! isset( $map['terms']['تماس'] ), '' );
ok( 'D: «راه» is out', ! isset( $map['terms']['راه'] ), '' );
ok( 'D: «های» is out', ! isset( $map['terms']['های'] ), '' );
ok( 'D: «شماره» is out', ! isset( $map['terms']['شماره'] ), '' );
ok( 'D: real content words stay (فرش)', isset( $map['terms']['فرش'] ), '' );
ok( 'D: real content words stay (دستبافت)', isset( $map['terms']['دستبافت'] ), '' );
ok( 'D: the junk cluster is still VISIBLE in the map (honest display)',
        isset( $map['intents']['بدون دسته'] ), '' );

/* ===================================================================
 * E. gaps() + propose_from_gaps(): the artifact never becomes a proposal
 * =================================================================== */
echo "\n=== E. gaps(): no topic is born from «بدون دسته» ===\n";

$GLOBALS['posts'] = array(
        array( 'id' => 700, 'title' => 'راه های تماس با ما', 'type' => 'page', 'status' => 'publish' ),
        array( 'id' => 701, 'title' => 'شماره تماس و راه های ارتباط', 'type' => 'page', 'status' => 'publish' ),
        array( 'id' => 702, 'title' => 'فرش دستبافت اردبیل', 'type' => 'post', 'status' => 'publish' ),
        array( 'id' => 703, 'title' => 'قیمت فرش دستبافت', 'type' => 'post', 'status' => 'publish' ),
        array( 'id' => 704, 'title' => 'فرش دستبافت تبریز', 'type' => 'post', 'status' => 'publish' ),
);
// 702..704 are the «بدون دسته» cluster (uncategorized posts); nothing is
// published in a real category, so the OLD code would mint «راهنمای انتخاب
// بدون دسته…» from the two uncategorized posts.
$GLOBALS['post_terms'][702]['category'] = array( (object) array( 'term_id' => 1, 'name' => 'بدون دسته' ) );
$GLOBALS['post_terms'][703]['category'] = array( (object) array( 'term_id' => 1, 'name' => 'بدون دسته' ) );
$GLOBALS['post_terms'][704]['category'] = array( (object) array( 'term_id' => 1, 'name' => 'بدون دسته' ) );

$gaps = Haa_Planner::gaps();
$names = wp_list_pluck( $gaps, 'cluster' );
ok( 'E: «بدون دسته» is not offered as a gap', ! in_array( 'بدون دسته', $names, true ), implode( ',', $names ) );

$GLOBALS['db']['topics'] = array();
$GLOBALS['db_next_id']   = 1;
$GLOBALS['api_calls']    = array();
$r = Haa_Planner::propose_from_gaps( 6 );
$stored = wp_list_pluck( Haa_Planner::rows( '' ), 'title' );
$no_junk = true;
foreach ( $stored as $t ) {
        if ( Haa_Planner::is_junk_topic( $t ) ) { $no_junk = false; }
}
ok( 'E: whatever gap proposals were stored, none is junk', $no_junk, implode( ' | ', array_slice( $stored, 0, 3 ) ) );
ok( 'E: no «بدون دسته» title exists after a gap run',
        ! in_array( 'راهنمای انتخاب بدون دسته: چیزهایی که قبل از خرید باید بدانید', $stored, true ), '' );
ok( 'E: gap proposals stay free (no model call)', 0 === count( $GLOBALS['api_calls'] ), '' );

/* ===================================================================
 * F. propose_from_rows(): competitor chrome is refused too
 * =================================================================== */
echo "\n=== F. propose_from_rows(): the gate on handed-over headings ===\n";

$GLOBALS['db']['topics'] = array();
$GLOBALS['db_next_id']   = 1;
$r = Haa_Planner::propose_from_rows( array(
        array( 'title' => 'شماره تماس و راه های ارتباط با رقیب', 'keyword' => 'تماس رقیب', 'source' => 'manual', 'intent' => 'informational' ),
        array( 'title' => 'بهترین فرش دستبافت تبریز در ۲۰۲۶', 'keyword' => 'فرش دستبافت تبریز', 'source' => 'manual', 'intent' => 'commercial', 'priority' => 7, 'reason' => 'سرصفحه رقیب' ),
) );
ok( 'F: exactly one row landed', 1 === $r['added'], 'added=' . $r['added'] );
ok( 'F: the chrome heading was skipped', 1 === $r['skipped'], 'skipped=' . $r['skipped'] );
ok( 'F: the surviving row is the real subject',
        1 === count( preg_grep( '/فرش دستبافت تبریز/', wp_list_pluck( Haa_Planner::rows( '' ), 'title' ) ) ), '' );

/* ===================================================================
 * G. propose_with_model(): filtered seeds + filtered output
 * =================================================================== */
echo "\n=== G. propose_with_model(): chrome cannot be born or stored ===\n";

delete_transient( Haa_Planner::MAP_CACHE );
$GLOBALS['db']['topics'] = array();
$GLOBALS['db_next_id']   = 1;
$GLOBALS['api_calls']    = array();
Haa_API::$fail = false;
// The model still tries: two chrome rows (one from each failure family) plus
// one real subject. The gate must keep only the real one.
Haa_API::$reply = wp_json_encode( array(
        array( 'title' => 'شماره تماس و روش های ارتباطی مستردستباف', 'keyword' => 'شماره تماس مستردستباف', 'intent' => 'informational', 'priority' => 6, 'reason' => 'واژه‌های پرتکرار سایت' ),
        array( 'title' => 'راهنمای انتخاب بدون دسته: چیزهایی که قبل از خرید باید بدانید', 'keyword' => 'بدون دسته', 'intent' => 'commercial', 'priority' => 8, 'reason' => 'خوشه بدون مقاله' ),
        array( 'title' => 'چگونه فرش دستبافت را از فرش ماشینی تشخیص دهیم', 'keyword' => 'تشخیص فرش دستبافت', 'intent' => 'informational', 'priority' => 7, 'reason' => 'سوال واقعی خریداران' ),
) );
$r = Haa_Planner::propose_with_model( 3 );
ok( 'G: the run reports ok', true === $r['ok'], '' );
ok( 'G: exactly one row survived the gate', 1 === $r['added'], 'added=' . $r['added'] );
ok( 'G: both chrome rows counted as skipped', 2 === $r['skipped'], 'skipped=' . $r['skipped'] );
ok( 'G: the stored row is the real subject', false !== strpos( implode( '|', wp_list_pluck( Haa_Planner::rows( '' ), 'title' ) ), 'تشخیص' ), '' );

// Prompt hygiene: the model must never again be SEEDED with chrome words.
$user_msg = $GLOBALS['api_calls'][0]['messages'][1]['content'];
ok( 'G: one model request was made', 1 === count( $GLOBALS['api_calls'] ), '' );
if ( preg_match( '/واژه‌های پرتکرار تیترهای سایت: ([^\n]+)/u', $user_msg, $mm ) ) {
        $term_line = $mm[1];
        ok( 'G: the term line carries no «تماس»', false === strpos( $term_line, 'تماس' ), $term_line );
        ok( 'G: the term line carries no «راه»', false === strpos( $term_line, 'راه' ), '' );
        ok( 'G: the term line carries no «های»', false === strpos( $term_line, 'های' ), '' );
        ok( 'G: the term line still carries real vocabulary', false !== strpos( $term_line, 'فرش' ), '' );
} else {
        ok( 'G: the term line was found in the prompt', false, 'no واژه‌های پرتکرار section' );
}
ok( 'G: the system prompt forbids chrome subjects explicitly',
        false !== strpos( $GLOBALS['api_calls'][0]['messages'][0]['content'], 'موضوع مقاله نیستند' ), '' );
ok( 'G: junk clusters are absent from the cluster list line',
        ! preg_match( '/خوشه‌های محتوایی فعلی سایت:[^\n]*بدون دسته/u', $user_msg ), '' );

/* ===================================================================
 * H. purge_junk_topics(): heal the stored rows once
 * =================================================================== */
echo "\n=== H. purge_junk_topics(): suggested junk dies, decisions survive ===\n";

$GLOBALS['db']['topics'] = array();
$GLOBALS['db_next_id']   = 1;
// The live site's shape: 8 suggested junk rows exactly as pasted, plus a
// suggested good row, an APPROVED chrome row (a human wanted it) and a
// queued good row.
$ids = array();
$row_i = 0;
foreach ( $pasted as $pair ) {
        $row_i++;
        Haa_Planner::insert( array(
                'title' => $pair[0], 'keyword' => $pair[1], 'secondary' => '',
                'intent' => 'informational', 'priority' => 5, 'reason' => 'گزارش زنده',
                'source' => $row_i <= 3 ? 'gap' : 'model',
        ) );
}
Haa_Planner::insert( array( 'title' => 'بهترین فرش دستبافت مشهد', 'keyword' => 'فرش دستبافت مشهد', 'secondary' => '', 'intent' => 'commercial', 'priority' => 7, 'reason' => 'تست', 'source' => 'model' ) );
$approved_id = Haa_Planner::insert( array( 'title' => 'تماس با ما و آدرس فروشگاه', 'keyword' => 'تماس با ما', 'secondary' => '', 'intent' => 'informational', 'priority' => 5, 'reason' => 'مدیر خواست', 'source' => 'manual' ) );
Haa_Planner::update( $approved_id, array( 'status' => 'approved' ) );
$queued_id = Haa_Planner::insert( array( 'title' => 'نگهداری فرش دستبافت', 'keyword' => 'نگهداری فرش', 'secondary' => '', 'intent' => 'informational', 'priority' => 5, 'reason' => 'تست', 'source' => 'model' ) );
Haa_Planner::update( $queued_id, array( 'status' => 'queued' ) );

$n_before = count( Haa_Planner::rows( '' ) );
$purged = Haa_Planner::purge_junk_topics();
ok( 'H: exactly the 8 junk suggested rows were purged', 8 === $purged, 'purged=' . $purged );
ok( 'H: nothing else vanished', 3 === count( Haa_Planner::rows( '' ) ), 'before=' . $n_before . ' after=' . count( Haa_Planner::rows( '' ) ) );
$c_after = Haa_Planner::counts();
ok( 'H: the suggested bucket holds exactly the good row', 1 === (int) $c_after['suggested'], 'suggested=' . $c_after['suggested'] );
$surviving = wp_list_pluck( Haa_Planner::rows( '' ), 'title' );
ok( 'H: the good suggested row survived', false !== strpos( implode( '|', $surviving ), 'بهترین فرش دستبافت مشهد' ), '' );
ok( 'H: the approved chrome row survived (human decision)',
        'approved' === Haa_Planner::row( $approved_id )['status'], '' );
ok( 'H: the queued row survived', 'queued' === Haa_Planner::row( $queued_id )['status'], '' );
ok( 'H: a second run purges nothing (idempotent)', 0 === Haa_Planner::purge_junk_topics(), '' );

/* ===================================================================
 * I. Wiring: migration v10, versions, module hint, map-cache key
 * =================================================================== */
echo "\n=== I. wiring ===\n";

$db_src   = (string) file_get_contents( dirname( __DIR__ ) . '/includes/class-haa-db.php' );
$main     = (string) file_get_contents( dirname( __DIR__ ) . '/hami-ai-assistant.php' );
$plan_src = (string) file_get_contents( dirname( __DIR__ ) . '/includes/class-haa-planner.php' );
$mod_src  = (string) file_get_contents( dirname( __DIR__ ) . '/modules/class-haa-module-article-writer.php' );

ok( 'I: the v10 migration exists and dispatches on old_version < 10',
        false !== strpos( $db_src, 'private static function migrate_to_v10()' )
        && false !== strpos( $db_src, '$old_version < 10' ), '' );
ok( 'I: the migration purges through Haa_Planner::purge_junk_topics()',
        false !== strpos( $db_src, 'Haa_Planner::purge_junk_topics()' ), '' );
ok( 'I: the schema constant is bumped to 10', false !== strpos( $main, "define( 'HAAI_DB_VERSION', '10' )" ), '' );
ok( 'I: HAAI_VERSION constant is 2.1.55', false !== strpos( $main, "define( 'HAAI_VERSION', '2.1.55' )" ), '' );
ok( 'I: the planner carries the junk-cluster const', false !== strpos( $plan_src, 'JUNK_CLUSTERS' ), '' );
ok( 'I: the map-cache key was bumped (old caches cannot leak chrome terms)',
        false !== strpos( $plan_src, "MAP_CACHE = 'haai_planner_map_v2'" ), '' );
ok( 'I: the module teaches what is excluded',
        false !== strpos( $mod_src, 'لحاظ نمی‌شوند' ) && false !== strpos( $mod_src, 'بدون دسته' ), '' );
ok( 'I: propose_from_gaps gates through is_junk_topic',
        false !== strpos( $plan_src, 'is_junk_topic( $row[\'title\'], $row[\'keyword\'] )' ), '' );
ok( 'I: propose_with_model gates through is_junk_topic',
        false !== strpos( $plan_src, '! $row || self::is_junk_topic' ), '' );

echo "\n" . ( $fail ? "FAILURES: $fail\n" : "ALL CHECKS PASSED\n" );
exit( $fail ? 1 : 0 );
