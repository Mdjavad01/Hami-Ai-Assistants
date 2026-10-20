<?php
/**
 * Regression suite: the planner became selectable again (2.1.36).
 *
 * The seventh live report (2.1.35 on new.mrdastbaaf) ended the funnel in a
 * dead end: «اصلا نمیشه چیزیو انتخاب کرد به صف تولید محتوا برد … از لحاظ
 * رابط کاربری واقعا در هم ریخته ست» and «الان فقط خروجی اکسل میده، جایی برای
 * تیک زدن و افزودن به تقویم محتوایی یا مقاله نویسی نیست واقعا».
 *
 * Two independent root causes, both locked here:
 *
 *   A  admin.js counted the row checkboxes with a DESCENDANT selector
 *      (`#haai-plan-queue input[name="ids[]"]:checked`) while the boxes live
 *      in the table ABOVE the form and point at it with the HTML `form`
 *      attribute — so the count was always 0, the estimate froze on
 *      «برای برآورد هزینه…», the change-filter never fired and the submit
 *      button never unlocked. The selection existed in HTML and still
 *      behaved as if it did not.
 *   B  legacy rows from early builds carry statuses this code never wrote
 *      («0») — the badge printed the raw «0», `$choosable` was false, so the
 *      row shipped with NO checkbox, NO تأیید and NO road into the queue;
 *      `counts()['suggested']` ignored them too, which is why the keyword
 *      panel insisted «برنامه‌ریز: 0 موضوع پیشنهادی» under a visible list.
 *
 * Locked here:
 *   - hydrate()/rows()/row(): any status outside Haa_Planner::STATUSES reads
 *     back as 'suggested'; valid statuses are untouched.
 *   - counts(): legacy buckets fold into 'suggested' (the diag tells the truth).
 *   - repair_statuses(): heals stored rows once, idempotent, valid rows alone.
 *   - queue(): a legacy '0' row now passes the status gate end-to-end —
 *     status becomes 'queued' and a real job row is pushed.
 *   - The JS contract in BOTH admin.js and admin.min.js: form-attributed
 *     counting, the «همه» select-all, the spend confirm.
 *   - The module contracts: the direct «ساخت مقاله برای انتخاب‌شده‌ها» button
 *     (formaction → admin_post_haai_kw_article → handle_kw_article), the
 *     de-cluttered 5-column table with the meta line, the taught guide strip.
 *   - The DB migration v9 wiring (dispatch + HAAI_DB_VERSION 9) and 2.1.36.
 *
 * Run: php dev-tests/tmp-checks39-planfix.php
 */
require __DIR__ . '/tmp-wp-stubs.php';

$fail = 0;
function ok( $label, $cond, $extra = '' ) {
        global $fail;
        if ( ! $cond ) { $fail++; }
        echo ( $cond ? '  ok   ' : '  FAIL ' ) . str_pad( $label, 62 ) . $extra . "\n";
}

/* ===================================================================
 * A. Legacy statuses read back as «پیشنهاد» (the badge + the checkbox)
 * =================================================================== */
echo "=== A. hydrate/rows: legacy statuses normalize to suggested ===\n";

$GLOBALS['db']['topics'] = array(
        array( 'id' => 1, 'title' => 'راهنمای انتخاب بدون دسته', 'keyword' => 'بدون دسته', 'secondary' => '', 'intent' => 'commercial', 'priority' => 8, 'reason' => 'تست', 'source' => 'gap', 'status' => '0', 'post_id' => 0, 'job_id' => 0, 'tokens_est' => 0, 'created' => '2026-01-01 00:00:00', 'updated' => '2026-01-01 00:00:00' ),
        array( 'id' => 2, 'title' => 'مقایسه‌ی بدون دسته', 'keyword' => 'بهترین بدون دسته', 'secondary' => '', 'intent' => 'transactional', 'priority' => 7, 'reason' => 'تست', 'source' => 'gap', 'status' => '', 'post_id' => 0, 'job_id' => 0, 'tokens_est' => 0, 'created' => '2026-01-01 00:00:00', 'updated' => '2026-01-01 00:00:00' ),
        array( 'id' => 3, 'title' => 'موضوع سالم', 'keyword' => 'سالم', 'secondary' => '', 'intent' => 'informational', 'priority' => 5, 'reason' => 'تست', 'source' => 'keyword', 'status' => 'suggested', 'post_id' => 0, 'job_id' => 0, 'tokens_est' => 0, 'created' => '2026-01-01 00:00:00', 'updated' => '2026-01-01 00:00:00' ),
        array( 'id' => 4, 'title' => 'در صف', 'keyword' => 'صف', 'secondary' => '', 'intent' => 'informational', 'priority' => 5, 'reason' => 'تست', 'source' => 'keyword', 'status' => 'queued', 'post_id' => 0, 'job_id' => 9, 'tokens_est' => 0, 'created' => '2026-01-01 00:00:00', 'updated' => '2026-01-01 00:00:00' ),
        array( 'id' => 5, 'title' => 'منتشرشده', 'keyword' => 'منتشر', 'secondary' => '', 'intent' => 'informational', 'priority' => 5, 'reason' => 'تست', 'source' => 'keyword', 'status' => 'published', 'post_id' => 11, 'job_id' => 0, 'tokens_est' => 0, 'created' => '2026-01-01 00:00:00', 'updated' => '2026-01-01 00:00:00' ),
);

$by_id = array();
foreach ( Haa_Planner::rows( '', 50 ) as $r ) {
        $by_id[ (int) $r['id'] ] = $r;
}
ok( 'A: legacy «0» reads back as suggested', 'suggested' === $by_id[1]['status'], '' );
ok( 'A: legacy empty status reads back as suggested', 'suggested' === $by_id[2]['status'], '' );
ok( 'A: a real suggested row is untouched', 'suggested' === $by_id[3]['status'], '' );
ok( 'A: queued is untouched (no false repair)', 'queued' === $by_id[4]['status'], '' );
ok( 'A: published is untouched', 'published' === $by_id[5]['status'], '' );
ok( 'A: row() normalizes too (single-row read)', 'suggested' === Haa_Planner::row( 1 )['status'], '' );
ok( 'A: a legacy row is choosable now (the gate queue() uses)',
        in_array( Haa_Planner::row( 1 )['status'], array( 'suggested', 'approved' ), true ), '' );

/* ===================================================================
 * B. counts(): the «برنامه‌ریز:» diag finally tells the truth
 * =================================================================== */
echo "\n=== B. counts(): legacy buckets fold into suggested ===\n";

$c = Haa_Planner::counts();
ok( 'B: 3 suggested (two legacy + one real)', 3 === (int) $c['suggested'], 'got ' . $c['suggested'] );
ok( 'B: queued bucket intact', 1 === (int) $c['queued'], '' );
ok( 'B: published bucket intact', 1 === (int) $c['published'], '' );
ok( 'B: no invented buckets (approved/rejected stay 0)', 0 === (int) $c['approved'] && 0 === (int) $c['rejected'], '' );

/* ===================================================================
 * C. repair_statuses(): heal once, leave valid rows alone, idempotent
 * =================================================================== */
echo "\n=== C. repair_statuses(): the one-time DB heal ===\n";

$GLOBALS['db']['topics'][0]['status'] = '0';   // the live site's exact shape
$GLOBALS['db']['topics'][1]['status'] = '';
$GLOBALS['db']['topics'][3]['status'] = 'queued';

$fixed = Haa_Planner::repair_statuses();
ok( 'C: heals exactly the 2 legacy rows', 2 === $fixed, 'fixed=' . $fixed );
ok( 'C: first legacy row healed in the STORE (not just display)', '0' !== $GLOBALS['db']['topics'][0]['status'], '' );
ok( 'C: valid queued row left alone', 'queued' === $GLOBALS['db']['topics'][3]['status'], '' );
ok( 'C: second run is a no-op (idempotent)', 0 === Haa_Planner::repair_statuses(), '' );

/* ===================================================================
 * D. queue(): a legacy row can finally reach the article queue
 * =================================================================== */
echo "\n=== D. queue(): the dead end is gone (legacy row → real job) ===\n";

$GLOBALS['db']['topics'] = array(
        array( 'id' => 1, 'title' => 'راهنمای انتخاب بدون دسته', 'keyword' => 'بدون دسته', 'secondary' => '', 'intent' => 'commercial', 'priority' => 8, 'reason' => 'تست', 'source' => 'gap', 'status' => '0', 'post_id' => 0, 'job_id' => 0, 'tokens_est' => 0, 'created' => '2026-01-01 00:00:00', 'updated' => '2026-01-01 00:00:00' ),
);
// A topic with no brief must not need one: the kw-panel insert path never
// writes a brief, and the legacy rows predate the column.
unset( $GLOBALS['jobs'] );
$q = Haa_Planner::queue( array( 1 ), array( 'tone' => '', 'length' => '', 'category' => 0, 'with_image' => false ) );
ok( 'D: the legacy row queues (queued=1)', 1 === (int) $q['queued'], 'reason=' . $q['reason'] );
ok( 'D: nothing skipped', 0 === (int) $q['skipped'], '' );
ok( 'D: row status is now queued', 'queued' === Haa_Planner::row( 1 )['status'], '' );
$jobs = (array) ( $GLOBALS['jobs'] ?? array() );
ok( 'D: exactly one queue job was pushed', 1 === count( $jobs ), 'jobs=' . count( $jobs ) );
$payload_d = isset( $jobs[0] ) ? (array) $jobs[0]['payload'] : array();
ok( 'D: the job carries the topic_id (staged pipeline route)',
        1 === (int) ( $payload_d['topic_id'] ?? 0 ), '' );
ok( 'D: the job carries the title + keyword', 'راهنمای انتخاب بدون دسته' === (string) ( $payload_d['topic'] ?? '' ) && 'بدون دسته' === (string) ( $payload_d['keyword'] ?? '' ), '' );

/* ===================================================================
 * E. The keyword panel → article loop (handle_kw_article logic)
 * =================================================================== */
echo "\n=== E. kw → article: reuse, dedupe, queue ===\n";

$GLOBALS['db']['topics'] = array();
// Phrase A: fresh insert. Phrase B: already suggested → reused, not duplicated.
$id_b = Haa_Planner::insert( array( 'title' => 'فرش دستباف کاشان', 'keyword' => 'فرش دستباف کاشان', 'intent' => 'informational', 'reason' => 'تست', 'source' => 'keyword', 'priority' => 5 ) );
// Phrase C: already queued → the handler must skip it, not queue it twice.
$id_c = Haa_Planner::insert( array( 'title' => 'فرش ماشینی نجفی', 'keyword' => 'فرش ماشینی نجفی', 'intent' => 'informational', 'reason' => 'تست', 'source' => 'keyword', 'priority' => 5 ) );
Haa_Planner::update( $id_c, array( 'status' => 'queued' ) );

$ids   = array();
$dups  = 0;
foreach ( array( 'فرش دستباف تبریز', 'فرش دستباف کاشان', 'فرش ماشینی نجفی' ) as $kw ) {
        $existing = (int) Haa_Planner::existing_topic( $kw );
        if ( $existing ) {
                $row = Haa_Planner::row( $existing );
                if ( $row && in_array( $row['status'], array( 'suggested', 'approved' ), true ) ) {
                        $ids[] = (int) $existing;
                } else {
                        $dups++;
                }
                continue;
        }
        $nid = Haa_Planner::insert( array( 'title' => $kw, 'keyword' => $kw, 'intent' => 'informational', 'reason' => 'تست', 'source' => 'keyword', 'priority' => 5 ) );
        if ( $nid ) { $ids[] = (int) $nid; }
}
ok( 'E: the fresh phrase is inserted and collected', 2 === count( $ids ), 'ids=' . count( $ids ) );
ok( 'E: the queued twin is skipped, not double-queued', 1 === $dups, 'dups=' . $dups );
ok( 'E: the suggested twin is reused (no duplicate row)',
        3 === count( $GLOBALS['db']['topics'] ), 'rows=' . count( $GLOBALS['db']['topics'] ) );
$q_e = Haa_Planner::queue( $ids, array( 'tone' => '', 'length' => '', 'category' => 0, 'with_image' => false ) );
ok( 'E: both phrases queue (the direct loop works)', 2 === (int) $q_e['queued'], 'reason=' . $q_e['reason'] );
ok( 'E: reused row lands queued exactly once', 'queued' === Haa_Planner::row( $id_b )['status'], '' );

/* ===================================================================
 * F. The JS contract — BOTH spellings, both files (source + min)
 * =================================================================== */
echo "\n=== F. JS contracts: counting, select-all, spend guard ===\n";

foreach ( array( 'assets/js/admin.js', 'assets/js/admin.min.js' ) as $jsf ) {
        $js = (string) file_get_contents( dirname( __DIR__ ) . '/' . $jsf );
        $tag = basename( $jsf );
        ok( "F: [$tag] counts form-ATTRIBUTED checkboxes (the 2.1.35 dead selector is gone)",
                false !== strpos( $js, 'input[name="ids[]"][form="haai-plan-queue"]' )
                && false === strpos( $js, '#haai-plan-queue input[name="ids[]"]:checked' ), '' );
        ok( "F: [$tag] the change filter matches by name (fires for table boxes)",
                false !== strpos( $js, 'input[name="ids[]"], [data-haai-plan-image]' ), '' );
        ok( "F: [$tag] the «همه» select-all is wired", false !== strpos( $js, 'data-haai-plan-all' ), '' );
        ok( "F: [$tag] the token-spend confirm is wired", false !== strpos( $js, 'data-haai-spend' ), '' );
}

/* ===================================================================
 * G. The module contracts — the direct button, the lean table
 * =================================================================== */
echo "\n=== G. module: direct article button + de-cluttered planner table ===\n";

$aw = (string) file_get_contents( dirname( __DIR__ ) . '/modules/class-haa-module-article-writer.php' );

$kw_start = strpos( $aw, 'private function render_keywords_panel()' );
$kw_end   = strpos( $aw, 'private function render_research_panel()' );
ok( 'G: keywords panel region found', false !== $kw_start && false !== $kw_end && $kw_end > $kw_start, '' );
$kw_src = false !== $kw_start ? substr( $aw, $kw_start, $kw_end - $kw_start ) : '';

ok( 'G: the research form offers the direct article queue (formaction)',
        false !== strpos( $kw_src, 'formaction=' ) && false !== strpos( $kw_src, 'action=haai_kw_article' ), '' );
ok( 'G: the spend confirm names the consequence in Persian',
        false !== strpos( $kw_src, 'data-haai-spend=' ) && false !== strpos( $kw_src, 'توکن مصرف می‌کند' ), '' );
ok( 'G: the button label exists', false !== strpos( $kw_src, 'ساخت مقاله برای انتخاب‌شده‌ها' ), '' );
ok( 'G: the two-destination hint ships under the buttons',
        false !== strpos( $kw_src, '«افزودن به برنامه‌ریز» رایگان است' ), '' );
ok( 'G: the planner-only primary button is untouched',
        false !== strpos( $kw_src, 'افزودن انتخاب‌شده‌ها به برنامه‌ریز' ), '' );

$pl_start = strpos( $aw, 'private function render_planner_panel()' );
$pl_end   = strpos( $aw, 'private function category_select()' );
ok( 'G: planner panel region found', false !== $pl_start && false !== $pl_end && $pl_end > $pl_start, '' );
$pl_src = false !== $pl_start ? substr( $aw, $pl_start, $pl_end - $pl_start ) : '';

ok( 'G: the header carries the «همه» select-all', false !== strpos( $pl_src, 'data-haai-plan-all' ), '' );
ok( 'G: keyword/intent/priority moved under the title (haai-plan-meta)',
        false !== strpos( $pl_src, 'haai-plan-meta' ), '' );
ok( 'G: the reason column is re-titled «چرا این موضوع»',
        false !== strpos( $pl_src, 'چرا این موضوع' ), '' );
ok( 'G: the 8-column header is gone (no standalone کلیدواژه/هدف/اولویت th)',
        false === strpos( $pl_src, "<th><?php esc_html_e( 'کلیدواژه'" )
        && false === strpos( $pl_src, "<th><?php esc_html_e( 'اولویت'" )
        && false === strpos( $pl_src, "<th><?php esc_html_e( 'هدف'" ), '' );
ok( 'G: checkboxes still point at the queue form', false !== strpos( $pl_src, 'form="haai-plan-queue"' ), '' );

ok( 'G: the handler is registered on admin_post', false !== strpos( $aw, "add_action( 'admin_post_haai_kw_article'" ), '' );
$hk_start = strpos( $aw, 'public function handle_kw_article()' );
$hk_end   = strpos( $aw, 'public function handle_kw_gsc()' );
ok( 'G: handle_kw_article exists', false !== $hk_start, '' );
$hk_src = false !== $hk_start ? substr( $aw, $hk_start, ( false !== $hk_end ? $hk_end - $hk_start : 6000 ) ) : '';
ok( 'G: the handler reuses the planner dedupe (existing_topic)',
        false !== strpos( $hk_src, 'Haa_Planner::existing_topic' ), '' );
ok( 'G: the handler queues through the SAME queue() the planner uses',
        false !== strpos( $hk_src, 'Haa_Planner::queue(' ), '' );
ok( 'G: an already-queued/published twin is skipped honestly',
        false !== strpos( $hk_src, 'هیچ مقاله‌ای صف نشد' ), '' );

ok( 'G: the guide strip teaches the direct loop too',
        false !== strpos( $aw, 'مسیر پیشنهادی' ) && false !== strpos( $aw, 'مستقیم «ساخت مقاله برای انتخاب‌شده‌ها»' ), '' );

/* ===================================================================
 * H. The DB migration + version wiring
 * =================================================================== */
echo "\n=== H. DB migration v9 + 2.1.36 ===\n";

$db_src = (string) file_get_contents( dirname( __DIR__ ) . '/includes/class-haa-db.php' );
$main   = (string) file_get_contents( dirname( __DIR__ ) . '/hami-ai-assistant.php' );
$plan_src = (string) file_get_contents( dirname( __DIR__ ) . '/includes/class-haa-planner.php' );

ok( 'H: the v9 migration exists and dispatches on old_version < 9',
        false !== strpos( $db_src, 'private static function migrate_to_v9()' )
        && false !== strpos( $db_src, '$old_version < 9' ), '' );
ok( 'H: the migration heals through Haa_Planner::repair_statuses()',
        false !== strpos( $db_src, 'Haa_Planner::repair_statuses()' ), '' );
ok( 'H: the migration guards a missing topics table / class',
        false !== strpos( $db_src, 'SHOW TABLES LIKE %s' ), '' );
ok( 'H: the schema constant is bumped to 10', false !== strpos( $main, "define( 'HAAI_DB_VERSION', '10' )" ), '' );
ok( 'H: version header is 2.1.39', false !== strpos( $main, '2.1.39' ), '' );
ok( 'H: HAAI_VERSION constant is 2.1.39', false !== strpos( $main, "define( 'HAAI_VERSION', '2.1.39' )" ), '' );
ok( 'H: hydrate() carries the normalization guard',
        false !== strpos( $plan_src, "! in_array( \$status, self::STATUSES, true )" ), '' );
ok( 'H: counts() folds unknown buckets into suggested',
        false !== strpos( $plan_src, '$out[ $status ] += (int) $row' ), '' );

echo "\n" . ( $fail ? "FAILURES: $fail\n" : "ALL CHECKS PASSED\n" );
exit( $fail ? 1 : 0 );
