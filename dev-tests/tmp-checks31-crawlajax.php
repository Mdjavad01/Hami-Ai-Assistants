<?php
/**
 * Regression suite: AJAX crawl start — no URL change, no reload (2.1.20).
 *
 * The user's live report: pressing «کرال و به‌روزرسانی دانش سایت» landed on
 * admin.php?page=haai-dashboard&haai_msg=خزش+صفحات+شروع+شد… — a full admin
 * reload whose message the dashboard NEVER printed (only module screens read
 * haai_msg), so the URL changed for nothing and every press killed the
 * running ticker («چند بار زدم، نوار پیشرفت ادامه نیاورد»). Locked here:
 *   1. wp_ajax_haai_crawl_start is registered → ajax_start() exists;
 *   2. ajax_start() returns {ok:true, message, progress} on success and
 *      honours the reset flag («شروع از نو» → offset 0);
 *   3. ajax_start() returns {ok:false, message} when start() WP_Error's
 *      (RAG off) — the reason now shows INLINE instead of a dead redirect;
 *   4. the dashboard script is ALWAYS printed (idle included) and wires the
 *      form intercept + haai_crawl_start AJAX + reset flag + form.submit()
 *      fallback; stop/reset always render, display:none when idle;
 *   5. notice() prints haai_msg/haai_err ONLY on the three haai screens that
 *      render no copy of their own (no double notices on module screens);
 *   6. the classic admin-post fallback (handle_start) stays intact.
 *
 * Run: php dev-tests/tmp-checks31-crawlajax.php
 */
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

function reset_crawl_config() {
        $rc = new ReflectionClass( 'Haa_Crawl' );
        $p  = $rc->getProperty( 'config' );
        $p->setAccessible( true );
        $p->setValue( null, null );
}

function reset_crawl_all() {
        reset_kb_config();
        reset_crawl_config();
        $GLOBALS['opts']['hami_ai_crawl_state'] = array();
        $GLOBALS['chunk_rows']                  = array();
        $GLOBALS['http_log']                    = array();
        $GLOBALS['http_map']                    = array();
}

echo "=== A. hooks — wp_ajax_haai_crawl_start registered ===\n";
$crawl_src = file_get_contents( HAAI_DEV_ROOT . 'includes/class-haa-crawl.php' );
ok( 'ajax handler registered', false !== strpos( $crawl_src, "add_action( 'wp_ajax_haai_crawl_start'" ) );
ok( 'ajax_start() exists', false !== strpos( $crawl_src, 'public static function ajax_start()' ) );
ok( 'classic admin-post fallback intact', false !== strpos( $crawl_src, "add_action( 'admin_post_haai_crawl_start'" ) );
ok( 'reset flag still honoured in ajax_start', false !== strpos( $crawl_src, "! empty( \$_POST['reset'] )" ) );
ok( 'guard_admin_ajax on ajax_start', false !== strpos( $crawl_src, 'Haa_Util::guard_admin_ajax()' ) );

echo "=== B. ajax_start — success + progress payload ===\n";
reset_crawl_all();
Haa_Settings::save_section( 'advanced', array( 'kb_enabled' => 1, 'enable_rag' => 1 ) );
Haa_Settings::save_section( 'knowledge', array( 'urls' => '', 'auto_crawl' => 1, 'max_pages' => 30, 'crawl_types' => array( 'page' ) ) );
reset_crawl_config();
$GLOBALS['posts'] = array(
        array( 'id' => 700, 'title' => 'تماس با ما', 'type' => 'page', 'status' => 'publish' ),
        array( 'id' => 701, 'title' => 'درباره ما', 'type' => 'page', 'status' => 'publish' ),
);
$GLOBALS['http_map'] = array( 'local.test' => array( 'body' => '<html><head><title>ت</title></head><body><p>محتوای آزمایشی کافی برای تکه‌سازی — ' . str_repeat( 'متن ', 30 ) . '</p></body></html>' ) );
reset_crawl_config();
$_POST = array();
$GLOBALS['json'] = null;
Haa_Crawl::ajax_start();
$d = $GLOBALS['json'];
ok( 'ok:true on success', is_array( $d ) && true === $d['ok'] );
ok( 'message present', is_array( $d ) && '' !== (string) ( $d['message'] ?? '' ), '(' . (string) ( $d['message'] ?? '' ) . ')' );
ok( 'progress payload with running cursor', is_array( $d ) && isset( $d['progress']['running'] ) && 1 === (int) $d['progress']['running'] );
ok( 'progress carries percent for the bar', is_array( $d ) && isset( $d['progress']['percent'] ) );

echo "=== C. ajax_start — «شروع از نو» rebuilds from zero ===\n";
$s           = $GLOBALS['opts']['hami_ai_crawl_state'];
$s['offset'] = 2;
$GLOBALS['opts']['hami_ai_crawl_state'] = $s;
$_POST     = array( 'reset' => '1' );
$GLOBALS['json'] = null;
Haa_Crawl::ajax_start();
$d = $GLOBALS['json'];
ok( 'reset accepted (ok:true)', is_array( $d ) && true === $d['ok'] );
ok( 'reset rebuilt the queue (offset 0)', is_array( $d ) && 0 === (int) $d['progress']['offset'], '(offset=' . (int) ( $d['progress']['offset'] ?? -1 ) . ')' );
Haa_Crawl::cancel();

echo "=== D. ajax_start — WP_Error surfaces as ok:false + message ===\n";
Haa_Settings::save_section( 'advanced', array( 'kb_enabled' => 0, 'enable_rag' => 0 ) );
reset_kb_config();
reset_crawl_config();
reset_crawl_all();
$_POST     = array();
$GLOBALS['json'] = null;
Haa_Crawl::ajax_start();
$d = $GLOBALS['json'];
ok( 'ok:false when RAG is off', is_array( $d ) && false === $d['ok'] );
ok( 'error message carried for inline display', is_array( $d ) && '' !== (string) ( $d['message'] ?? '' ) && false !== strpos( (string) $d['message'], 'RAG' ), '(' . (string) ( $d['message'] ?? '' ) . ')' );
ok( 'no progress key on error', is_array( $d ) && ! array_key_exists( 'progress', $d ) );

echo "=== E. dashboard — script always printed + AJAX start wiring ===\n";
$dash = file_get_contents( HAAI_DEV_ROOT . 'includes/class-haa-admin-dashboard.php' );
ok( 'script is unconditional (no running-gate)', false === strpos( $dash, "if ( ! empty( \$crawl_state['running'] ) ) : ?>" ) );
ok( 'running flag driven from server state', false !== strpos( $dash, "var running = <?php echo empty( \$crawl_state['running'] ) ? 'false' : 'true'; ?>;" ) );
ok( 'form submit intercept wired', false !== strpos( $dash, "form.addEventListener('submit'" ) );
ok( 'AJAX action haai_crawl_start used', false !== strpos( $dash, "body.append('action', 'haai_crawl_start')" ) );
ok( 'reset flag sent over AJAX', false !== strpos( $dash, "body.append('reset', '1')" ) );
ok( 'submitter detection for «شروع از نو»', false !== strpos( $dash, 'e.submitter' ) );
ok( 'Safari fallback flag cleared', false !== strpos( $dash, 'window.haaiResetClicked' ) );
ok( 'classic form.submit() fallback kept', false !== strpos( $dash, 'form.submit()' ) );
ok( 'press while ticking is ignored', false !== strpos( $dash, 'if (running && !isReset)' ) );
ok( 'inline start message', false !== strpos( $dash, 'در حال آغاز خزش' ) );
ok( 'no-JS fallback preserved (haaiAdmin gate)', false !== strpos( $dash, "if (!window.haaiAdmin) return; // no-JS env" ) );
ok( 'stop/reset always render', substr_count( $dash, 'style="display:none"' ) >= 2, '(' . substr_count( $dash, 'style="display:none"' ) . ' spots)' );
ok( 'JS toggles stop/reset visibility', false !== strpos( $dash, "stopBtn.style.display" ) && false !== strpos( $dash, "resetBtn.style.display" ) );
ok( 'epoch guard kills stale tick loops', false !== strpos( $dash, 'epoch++' ) && false !== strpos( $dash, 'e0 !== epoch' ) );
ok( 'labels swap idle/resume', false !== strpos( $dash, 'L_IDLE' ) && false !== strpos( $dash, 'L_RESUME' ) );
ok( 'error colour for honest failures', false !== strpos( $dash, "isErr ? '#b32d2e' : ''" ) );

echo "=== F. notice() — haai_msg prints only where nothing else does ===\n";
$admin_src = file_get_contents( HAAI_DEV_ROOT . 'includes/class-haa-admin.php' );
ok( 'haai_msg read in notice()', false !== strpos( $admin_src, "\$_GET['haai_msg']" ) );
ok( 'haai_err read in notice()', false !== strpos( $admin_src, "\$_GET['haai_err']" ) );
ok( 'gated to the three self-render-less screens', false !== strpos( $admin_src, "'haai-dashboard', 'haai-settings', 'haai-modules'" ) );
foreach ( array( 'class-haa-module-article-writer.php', 'class-haa-module-autopilot.php', 'class-haa-module-discount.php' ) as $m ) {
        $msrc = file_get_contents( HAAI_DEV_ROOT . 'modules/' . $m );
        ok( 'module keeps its own renderer (no doubles): ' . $m, false !== strpos( $msrc, "\$_GET['haai_msg']" ) );
}

echo "\n";
if ( 0 === $fail ) {
        echo "ALL CHECKS PASSED (" . 'suite 31' . ")\n";
} else {
        echo "FAILURES: $fail\n";
        exit( 1 );
}
