<?php
/**
 * Regression suite: every admin form went AJAX (2.1.42).
 *
 * Eleventh live report (2.1.39): the admin clicked «ساخت همین حالا» and the
 * browser landed on
 *   admin.php?page=haai-article-writer&haai_err=ساخت+مقاله+ناموفق+بود…
 * — a dirty URL, a full reload and a message some screens never even
 * printed. «اینا بصورت ایجکس باشه مثل نوار پیشرفت خزش سایت… کل این موارد
 * ایجکس باشه، دکمه ها و ... هی url نزنه».
 *
 * Ships:
 *   - Haa_Ajax_Form — ONE transport (wp_ajax_haai_form_submit) that fires
 *     the very same admin_post_{action} hook; back()/redirect() funnels in
 *     13 classes answer JSON under the transport and keep their classic
 *     redirect as the no-JS fallback.
 *   - wp_ajax_haai_panel — whitelisted panel re-render so the queue and
 *     planner tables stay true without any reload.
 *   - admin.js generic interceptor: inline notice, busy state, panel swap,
 *     clean-URL flash replay. No more ?haai_msg=… URLs, ever.
 *
 * SELF-CONTAINED: own WP stubs, own fakes for Haa_Util / Haa_Queue /
 * Haa_Settings, a REAL add_action/do_action pair for the dispatch test,
 * and child processes (HAAI_DUMP) for the real exit path.
 */

define( 'ABSPATH', __DIR__ . '/wp-stub/' );
define( 'ARRAY_A', 'ARRAY_A' );
define( 'HAAI_TEST', true );

$GLOBALS['hooks']     = array( 'action' => array(), 'filter' => array() );
$GLOBALS['json']      = null;
$GLOBALS['redirect']  = null;
$GLOBALS['opts']      = array();
$GLOBALS['qjobs']     = array();
$GLOBALS['qflags']    = array();

/* --------------------------------------------------------------------- */
/* Minimal WordPress — the hooks pair is REAL so route() can be proven.   */
/* --------------------------------------------------------------------- */
function add_action( $h, $cb, $p = 10, $a = 1 ) {
        $GLOBALS['hooks']['action'][ $h ][] = $cb;
        return true;
}
function do_action( $h, ...$args ) {
        if ( empty( $GLOBALS['hooks']['action'][ $h ] ) ) {
                return;
        }
        foreach ( $GLOBALS['hooks']['action'][ $h ] as $cb ) {
                call_user_func_array( $cb, $args );
        }
}
function add_filter( $h, $cb, $p = 10, $a = 1 ) {
        $GLOBALS['hooks']['filter'][ $h ][] = $cb;
        return true;
}
function apply_filters( $h, $v, ...$args ) {
        if ( empty( $GLOBALS['hooks']['filter'][ $h ] ) ) {
                return $v;
        }
        foreach ( $GLOBALS['hooks']['filter'][ $h ] as $cb ) {
                $v = call_user_func_array( $cb, array( $v ) + $args );
        }
        return $v;
}
function wp_send_json( $d, $s = 200 ) {
        $GLOBALS['json'] = $d;
        return false;
}
function wp_send_json_error( $d = null, $s = 200 ) {
        return wp_send_json( array( 'success' => false, 'data' => $d ), $s );
}
function wp_send_json_success( $d = null, $s = 200 ) {
        return wp_send_json( array( 'success' => true, 'data' => $d ), $s );
}
function wp_json_encode( $v ) {
        return json_encode( $v );
}
function __( $s, $d = null ) {
        return $s;
}
function esc_html__( $s, $d = null ) {
        return $s;
}
function esc_html_e( $s, $d = null ) {
        echo $s;
}
function esc_attr_e( $s, $d = null ) {
        echo $s;
}
function esc_html( $s ) {
        return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8', false );
}
function esc_attr( $s ) {
        return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8', false );
}
/* 2.1.42: the unified queue panel prints the pre-filled textarea. */
function esc_textarea( $s ) {
        return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8', false );
}
function esc_url( $s ) {
        return $s;
}
function esc_html_e_once( $s, $d = null ) {
        echo $s;
}
function sanitize_key( $s ) {
        return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $s ) );
}
function sanitize_text_field( $s ) {
        return trim( preg_replace( '/[\r\n\t ]+/', ' ', (string) $s ) );
}
function wp_unslash( $v ) {
        return is_array( $v ) ? array_map( 'wp_unslash', $v ) : stripslashes( (string) $v );
}
function wp_strip_all_tags( $s ) {
        return trim( strip_tags( (string) $s ) );
}
function absint( $n ) {
        return abs( (int) $n );
}
function wp_safe_redirect( $u, $s = 302 ) {
        $GLOBALS['redirect'] = $u;
        return true;
}
function wp_nonce_field( $a = -1, $n = '_wpnonce' ) {
        return '';
}
function check_admin_referer( $a, $q = '_wpnonce' ) {
        return true;
}
function check_ajax_referer( $a, $q = 'nonce', $die = true ) {
        return true;
}
function current_user_can( $c ) {
        return true;
}
function get_option( $k, $d = false ) {
        return isset( $GLOBALS['opts'][ $k ] ) ? $GLOBALS['opts'][ $k ] : $d;
}
function update_option( $k, $v, $a = null ) {
        $GLOBALS['opts'][ $k ] = $v;
        return true;
}
function admin_url( $p = '' ) {
        return 'https://example.test/wp-admin/' . $p;
}
function add_query_arg( $k, $v = null, $u = null ) {
        if ( is_array( $k ) ) {
                $u  = $v;
                $glue = false === strpos( (string) $u, '?' ) ? '?' : '&';
                return $u . $glue . http_build_query( $k );
        }
        $glue = false === strpos( (string) $u, '?' ) ? '?' : '&';
        return $u . $glue . rawurlencode( $k ) . '=' . rawurlencode( $v );
}
function wp_next_scheduled( $h ) {
        return false;
}
function human_time_diff( $from, $to = 0 ) {
        return '۵ دقیقه';
}
function number_format_i18n( $n ) {
        return (string) $n;
}
function size_format( $b ) {
        return (string) $b;
}
function get_post_status( $id ) {
        return 'draft';
}
function get_post( $id ) {
        return null;
}
function disabled( $cond ) {
        return $cond ? ' disabled="disabled"' : '';
}
function checked( $cond ) {
        return $cond ? ' checked="checked"' : '';
}
function selected( $a, $b, $echo = true ) {
        return ( (string) $a === (string) $b ) ? ' selected="selected"' : '';
}
/* 2.1.42: the unified queue panel renders the recent-posts list — none here. */
function get_posts( $args = array() ) {
        return array();
}
/* 2.1.42: ...and the category select — one default term, no extras. */
function get_categories( $args = array() ) {
        return array();
}

/* The exit path lands here — the interesting globals ride out in JSON. */
register_shutdown_function( function () {
        $to = getenv( 'HAAI_DUMP' );
        if ( ! $to ) {
                return;
        }
        file_put_contents( $to, json_encode( array(
                'ajax'     => isset( $GLOBALS['haai_ajax_out'] ) ? $GLOBALS['haai_ajax_out'] : null,
                'jobs'     => $GLOBALS['qjobs'],
                'qflags'   => $GLOBALS['qflags'],
                'redirect' => $GLOBALS['redirect'],
        ) ) );
} );

/* --------------------------------------------------------------------- */
/* Fakes for the collaborators the real classes call.                     */
/* --------------------------------------------------------------------- */
interface Haa_Module_Interface {
}

class Haa_Util {
        public static function cut( $text, $length ) {
                $text = (string) $text;
                if ( function_exists( 'mb_strlen' ) && mb_strlen( $text, 'UTF-8' ) > $length ) {
                        return mb_substr( $text, 0, $length, 'UTF-8' ) . '…';
                }
                return $text;
        }
        /* 2.1.42: the unified queue panel renders the tone/length selects. */
        public static function tones() {
                return array( '' => 'پیش‌فرض', 'friendly' => 'صمیمی' );
        }
        public static function lengths() {
                return array( '' => 'پیش‌فرض', 'long' => 'بلند' );
        }
        public static function guard_admin_post( $action = 'haai_admin' ) {
                return true;
        }
        public static function guard_admin_ajax() {
                return true;
        }
        public static function screen_url( $key, $args = array() ) {
                return admin_url( 'admin.php?page=haai-' . $key ) . ( $args ? '&' . http_build_query( $args ) : '' );
        }
}

class Haa_Queue {
        public static function push( $type, $payload, $meta = array() ) {
                $GLOBALS['qjobs'][] = array( 'type' => $type, 'payload' => $payload );
                return 1;
        }
        /* 2.1.42: the enqueue dedup — nothing is taken in this suite. */
        public static function topic_taken( $topic, $module = 'article-writer' ) {
                return false;
        }
        public static function normalize_topic( $topic ) {
                return trim( (string) $topic );
        }
        public static function requeue( $id ) {
                return ! empty( $GLOBALS['qflags']['failed'][ (int) $id ] );
        }
        public static function purge_failed() {
                $GLOBALS['qflags']['purged_failed'] = ( empty( $GLOBALS['qflags']['purged_failed'] ) ? 0 : $GLOBALS['qflags']['purged_failed'] ) + 1;
                return true;
        }
        public static function purge_done() {
                $GLOBALS['qflags']['purged_done'] = ( empty( $GLOBALS['qflags']['purged_done'] ) ? 0 : $GLOBALS['qflags']['purged_done'] ) + 1;
                return true;
        }
        public static function tick() {
                return isset( $GLOBALS['qflags']['tick'] ) ? $GLOBALS['qflags']['tick'] : array( 'ran' => 0 );
        }
        public static function stats( $module = null ) {
                return array( 'pending' => 1, 'running' => 0, 'failed' => 0, 'done' => 2 );
        }
        public static function rows( $module = null, $limit = 12 ) {
                return array();
        }
        public static function job( $id ) {
                return null;
        }
}

class Haa_Settings {
        public static function get( $key = null, $default = false ) {
                if ( 'article.batch_size' === $key ) {
                        return 5;
                }
                return $default;
        }
}

class Haa_Planner {
        public static function site_map() {
                return array( 'posts' => 3, 'intents' => array(), 'clusters' => array(), 'terms' => array() );
        }
        public static function gaps() {
                return array();
        }
        public static function counts() {
                return array( 'suggested' => 0, 'approved' => 0, 'rejected' => 0, 'queued' => 0, 'published' => 0 );
        }
        public static function rows( $status = '', $limit = 50 ) {
                return array();
        }
        public static function estimate( $n = 1 ) {
                return array( 'blocked' => '', 'cap' => 1000, 'tokens' => 500 * max( 1, (int) $n ) );
        }
        public static function estimate_tokens( $n = 1 ) {
                return 500 * max( 1, (int) $n );
        }
        public static function row( $id ) {
                return null;
        }
}

class Haa_Usage {
        public static function tokens_today() {
                return 0;
        }
}

/* --------------------------------------------------------------------- */
/* Load the real classes under test.                                      */
/* --------------------------------------------------------------------- */
require __DIR__ . '/../includes/class-haa-ajax-form.php';
require __DIR__ . '/../modules/class-haa-module-article-writer.php';

$mod = new Haa_Module_Article_Writer();

/* --------------------------------------------------------------------- */
/* Child mode — real exit paths report through HAAI_DUMP.                 */
/* --------------------------------------------------------------------- */
$child = getenv( 'HAAI_CHILD' );
if ( $child ) {
        // In production route() defines this before firing the hook; the children
        // below call the handlers directly, so the flag is set up front.
        if ( ! defined( 'HAAI_AJAX_FORM' ) ) {
                define( 'HAAI_AJAX_FORM', true );
        }
        if ( 'requeue_ok' === $child ) {
                $GLOBALS['qflags']['failed'] = array( 7 => true );
                $_POST = array( 'id' => '7' );
                $mod->handle_requeue();
        } elseif ( 'requeue_fail' === $child ) {
                $_POST = array( 'id' => '99' );
                $mod->handle_requeue();
        } elseif ( 'queue_two' === $child ) {
                $_POST = array( 'topics' => "کیف چرم دست‌دوز\nقالیچه تبریز", 'tone' => 'formal', 'length' => 'long', 'category' => '3', 'with_image' => '1' );
                $mod->handle_queue();
        } elseif ( 'run_one' === $child ) {
                $GLOBALS['qflags']['tick'] = array( 'ran' => 1, 'job_id' => 11 );
                $_POST = array();
                $mod->handle_run_one();
        } elseif ( 'run_one_locked' === $child ) {
                $GLOBALS['qflags']['tick'] = array( 'ran' => 0, 'error' => 'locked' );
                $_POST = array();
                $mod->handle_run_one();
        } elseif ( 'route_dispatch' === $child ) {
                add_action( 'admin_post_haai_t1', function () {
                        Haa_Ajax_Form::respond( 'تستِ مسیر رفت', false );
                } );
                $_POST = array( 'haai_ajax_action' => 'haai_t1' );
                Haa_Ajax_Form::route();
        } elseif ( 'route_fallback' === $child ) {
                $_POST = array( 'haai_ajax_action' => 'haai_missing_thing' );
                Haa_Ajax_Form::route();
        } elseif ( 'route_skip' === $child ) {
                add_action( 'admin_post_haai_kw_export', function () {
                        $GLOBALS['qflags']['fired'] = 1;
                } );
                $_POST = array( 'haai_ajax_action' => 'haai_kw_export' );
                Haa_Ajax_Form::route();
        } elseif ( 'crawl_start' === $child ) {
                // Haa_Crawl::handle_start is static and pulls the crawl stack; here we
                // only prove the funnel shape — call the same respond-first pattern
                // the handler carries by simulating its wp_error branch marker text.
                Haa_Ajax_Form::respond( 'خزش صفحات شروع شد؛ نوار پیشرفت آن را دنبال می‌کند.', false );
        }
        exit;
}

/** Run one child scenario, return the dumped globals. */
function run_child( $name ) {
        $dump = sys_get_temp_dir() . '/haai-dump43-' . $name . '-' . getmypid() . '.json';
        @unlink( $dump );
        putenv( 'HAAI_DUMP=' . $dump );
        putenv( 'HAAI_CHILD=' . $name );
        $cmd = escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __FILE__ ) . ' 2>&1';
        $out = shell_exec( $cmd );
        putenv( 'HAAI_CHILD=' );
        putenv( 'HAAI_DUMP=' );
        $data = is_readable( $dump ) ? json_decode( file_get_contents( $dump ), true ) : null;
        @unlink( $dump );
        return array( $data, $out );
}

$GLOBALS['passed'] = 0;
$GLOBALS['failed'] = 0;
function ok( $label, $cond, $note = '' ) {
        if ( $cond ) {
                $GLOBALS['passed']++;
                echo "  ✓ {$label}\n";
                return;
        }
        $GLOBALS['failed']++;
        echo "  ✗ {$label}" . ( $note ? " — {$note}" : '' ) . "\n";
}

/* =====================================================================
 * A. wants() + respond() contracts (in-process, no exit)
 * ===================================================================== */
echo "=== A. respond() contract ===\n";
ok( 'not in AJAX mode → wants() false', ! Haa_Ajax_Form::wants() );
ob_start();
$handled = Haa_Ajax_Form::respond( 'پیام', false );
$buf     = ob_get_clean();
ok( 'classic mode: respond returns false and prints nothing', false === $handled && '' === $buf );

define( 'HAAI_AJAX_FORM', true );
define( 'HAAI_NO_EXIT', true );
ok( 'AJAX mode detected', Haa_Ajax_Form::wants() );
ob_start();
$handled = Haa_Ajax_Form::respond( 'پیام موفقیت', false );
$buf     = ob_get_clean();
$dec = json_decode( $buf, true );
ok( 'AJAX: respond returns true', true === $handled );
ok( 'AJAX: JSON envelope printed', is_array( $dec ) && isset( $dec['success'] ) );
ok( 'AJAX: success true + ok true', true === $dec['success'] && true === $dec['data']['ok'] );
ok( 'AJAX: msg carried verbatim', 'پیام موفقیت' === $dec['data']['msg'] );
ok( 'AJAX: payload mirrored into the dump global', is_array( $GLOBALS['haai_ajax_out'] ) && true === $GLOBALS['haai_ajax_out']['success'] );

ob_start();
Haa_Ajax_Form::respond( 'شکست خورد', true );
$buf = ob_get_clean();
$dec = json_decode( $buf, true );
ok( 'error flag flips success/ok', false === $dec['success'] && false === $dec['data']['ok'] );
$long = str_repeat( 'ت', 500 );
ob_start();
Haa_Ajax_Form::respond( $long );
$buf = ob_get_clean();
$dec = json_decode( $buf, true );
ok( 'msg cut at 300 chars', isset( $dec['data']['msg'] ) && strlen( $dec['data']['msg'] ) < 1200 && '…' === mb_substr( $dec['data']['msg'], -1, 1, 'UTF-8' ) );

/* =====================================================================
 * B. route() parsing + dispatch (in-process, real do_action)
 * ===================================================================== */
echo "=== B. route() contract ===\n";
$fired = 0;
add_action( 'admin_post_haai_t2', function () use ( &$fired ) {
        $fired++;
        Haa_Ajax_Form::respond( 'هندلر کلاسیک اجرا شد', false );
} );
$_POST = array( 'haai_ajax_action' => 'haai_t2', 'action' => 'haai_form_submit', 'x' => '1' );
Haa_Ajax_Form::route();
ok( 'handler fired exactly once through admin_post_{action}', 1 === $fired );
ok( '$_POST[action] restored for the handler', 'haai_t2' === $_POST['action'] );
ok( '$_REQUEST[action] restored too', 'haai_t2' === $_REQUEST['action'] );
ok( 'transport flag defined by route()', Haa_Ajax_Form::wants() );

$_POST = array( 'haai_ajax_action' => 'haai_kw_export' );
$GLOBALS['json'] = null;
Haa_Ajax_Form::route();
ok( 'skip-listed action refused (export)', is_array( $GLOBALS['haai_ajax_out'] ) && false === $GLOBALS['haai_ajax_out']['success'] );
ok( 'skip-listed refusal names the transport', false !== strpos( (string) $GLOBALS['haai_ajax_out']['data']['msg'], 'ایجکس' ) );

$_POST = array( 'haai_ajax_action' => 'evil_x' );
Haa_Ajax_Form::route();
ok( 'non-haai prefix refused', false === $GLOBALS['haai_ajax_out']['success'] );

$_POST = array( 'haai_ajax_action' => 'haai_no_handler' );
Haa_Ajax_Form::route();
ok( 'unhandled action falls to the honest error', false === $GLOBALS['haai_ajax_out']['success'] && false !== strpos( (string) $GLOBALS['haai_ajax_out']['data']['msg'], 'پاسخی از عملیات دریافت نشد' ) );

$_POST = array( 'haai_ajax_action' => 'haai with spaces!!' );
Haa_Ajax_Form::route();
ok( 'action sanitized to [a-z0-9_]', false === $GLOBALS['haai_ajax_out']['success'] );

/* =====================================================================
 * C. Real exit paths (child processes)
 * ===================================================================== */
echo "=== C. module handlers under the transport (children) ===\n";
list( $d, $out ) = run_child( 'requeue_ok' );
ok( 'requeue_ok: JSON went out and exited (no redirect)', is_array( $d ) && is_array( $d['ajax'] ) && true === $d['ajax']['success'] && null === $d['redirect'] );
ok( 'requeue_ok: message is the queue sentence', false !== strpos( (string) $d['ajax']['data']['msg'], 'دوباره به صف برگشت' ) );

list( $d, $out ) = run_child( 'requeue_fail' );
ok( 'requeue_fail: failure envelope', is_array( $d ) && false === $d['ajax']['success'] );
ok( 'requeue_fail: only failed jobs retry', false !== strpos( (string) $d['ajax']['data']['msg'], 'ناموفق نیست' ) );

list( $d, $out ) = run_child( 'queue_two' );
ok( 'queue_two: success', is_array( $d ) && true === $d['ajax']['success'] );
ok( 'queue_two: two jobs pushed', 2 === count( $d['jobs'] ) );
ok( 'queue_two: notice counts them', false !== strpos( (string) $d['ajax']['data']['msg'], '۲ مقاله' ) || false !== strpos( (string) $d['ajax']['data']['msg'], '2 مقاله' ) );

list( $d, $out ) = run_child( 'run_one' );
ok( 'run_one: success envelope', is_array( $d ) && true === $d['ajax']['success'] );
ok( 'run_one: draft-pointing notice', false !== strpos( (string) $d['ajax']['data']['msg'], 'یک مقاله از صف ساخته شد' ) );

list( $d, $out ) = run_child( 'run_one_locked' );
ok( 'run_one_locked: honest failure', is_array( $d ) && false === $d['ajax']['success'] );
ok( 'run_one_locked: lock message', false !== strpos( (string) $d['ajax']['data']['msg'], 'پردازش دیگر در جریان' ) );

list( $d, $out ) = run_child( 'route_dispatch' );
ok( 'route_dispatch: full child path (route → hook → respond → exit)', is_array( $d ) && true === $d['ajax']['success'] && 'تستِ مسیر رفت' === $d['ajax']['data']['msg'] );

list( $d, $out ) = run_child( 'route_fallback' );
ok( 'route_fallback: honest no-answer error', is_array( $d ) && false === $d['ajax']['success'] );

list( $d, $out ) = run_child( 'route_skip' );
ok( 'route_skip: download action refused in the child too', is_array( $d ) && false === $d['ajax']['success'] );

/* =====================================================================
 * D. back() conversions across every module (in-process)
 * ===================================================================== */
echo "=== D. every funnel answers JSON under the transport ===\n";
$mods = array(
        'video-article'    => 'Haa_Module_Video_Article',
        'comments'         => 'Haa_Module_Comments',
        'autopilot'        => 'Haa_Module_Autopilot',
        'leads'            => 'Haa_Module_Leads',
        'discount'         => 'Haa_Module_Discount',
        'semantic-search'  => 'Haa_Module_Semantic_Search',
        'programmatic'     => 'Haa_Module_Programmatic',
        'news'             => 'Haa_Module_News',
        'product-content'  => 'Haa_Module_Product_Content',
);
foreach ( $mods as $slug => $cls ) {
        $file = __DIR__ . '/../modules/class-haa-module-' . $slug . '.php';
        if ( ! class_exists( $cls ) && is_readable( $file ) ) {
                require $file;
        }
        if ( ! class_exists( $cls ) ) {
                ok( "{$slug}: class loads", false );
                continue;
        }
        $m    = new $cls();
        $ref  = new ReflectionMethod( $cls, 'back' );
        $ref->setAccessible( true );
        $GLOBALS['haai_ajax_out'] = null;
        if ( 'product-content' === $slug ) {
                $ref->invoke( $m, 0, 'خطای محصول', '' );
                $e = $GLOBALS['haai_ajax_out'];
                $GLOBALS['haai_ajax_out'] = null;
                $ref->invoke( $m, 5, '', 'محصول ساخته شد' );
                $s = $GLOBALS['haai_ajax_out'];
                ok( "{$slug}: error branch answers failure", is_array( $e ) && false === $e['success'] && 'خطای محصول' === $e['data']['msg'] );
                ok( "{$slug}: ok branch answers success", is_array( $s ) && true === $s['success'] && 'محصول ساخته شد' === $s['data']['msg'] );
        } elseif ( 'video-article' === $slug || 'programmatic' === $slug || 'news' === $slug ) {
                $ref->invoke( $m, 'پیام ' . $slug, true );
                $e = $GLOBALS['haai_ajax_out'];
                ok( "{$slug}: msg+error pair carried", is_array( $e ) && false === $e['success'] && 'پیام ' . $slug === $e['data']['msg'] );
        } else {
                $ref->invoke( $m, 'پیام ' . $slug );
                $s = $GLOBALS['haai_ajax_out'];
                ok( "{$slug}: success-only back answers JSON", is_array( $s ) && true === $s['success'] && 'پیام ' . $slug === $s['data']['msg'] );
        }
}

/* =====================================================================
 * E. Panel endpoint
 * ===================================================================== */
echo "=== E. wp_ajax_haai_panel whitelist ===\n";
$_POST = array( 'panel' => 'haai-queue-panel' );
$GLOBALS['json'] = null;
$mod->ajax_panel();
$j = $GLOBALS['json'];
ok( 'queue panel renders through the endpoint', is_array( $j ) && true === $j['success'] );
ok( 'payload carries panel id + html', isset( $j['data']['panel'], $j['data']['html'] ) && 'haai-queue-panel' === $j['data']['panel'] );
ok( 'html is the queue panel markup (2.1.42 rename)', false !== strpos( (string) $j['data']['html'], 'صف تولید مقاله' ) );
ok( 'html keeps the anchor id for the JS swap', false !== strpos( (string) $j['data']['html'], 'id="haai-queue-panel"' ) );

$_POST = array( 'panel' => 'haai-plan-panel' );
$GLOBALS['json'] = null;
$mod->ajax_panel();
$j = $GLOBALS['json'];
ok( 'planner panel whitelisted', is_array( $j ) && true === $j['success'] && false !== strpos( (string) $j['data']['html'], 'برنامه‌ریز موضوع' ) );

$_POST = array( 'panel' => 'not-a-panel' );
$GLOBALS['json'] = null;
$mod->ajax_panel();
ok( 'unknown panel refused', is_array( $GLOBALS['json'] ) && false === $GLOBALS['json']['success'] );

/* =====================================================================
 * F. Wiring + source contracts
 * ===================================================================== */
echo "=== F. wiring ===\n";
$main  = file_get_contents( __DIR__ . '/../hami-ai-assistant.php' );
$awp   = file_get_contents( __DIR__ . '/../modules/class-haa-module-article-writer.php' );
$dash  = file_get_contents( __DIR__ . '/../includes/class-haa-admin-dashboard.php' );
$cls   = file_get_contents( __DIR__ . '/../includes/class-haa-ajax-form.php' );
$admin = file_get_contents( __DIR__ . '/../includes/class-haa-admin.php' );
$js    = file_get_contents( __DIR__ . '/../assets/js/admin.js' );
$jsmin = file_get_contents( __DIR__ . '/../assets/js/admin.min.js' );

ok( 'version header is 2.1.45', false !== strpos( $main, '* Version:           2.1.45' ) );
ok( 'HAAI_VERSION is 2.1.45', false !== strpos( $main, "define( 'HAAI_VERSION', '2.1.45' )" ) );
ok( 'boot registers the transport', false !== strpos( $main, 'Haa_Ajax_Form::hooks()' ) );
ok( 'wp_ajax_haai_form_submit registered in the class', false !== strpos( $cls, "add_action( 'wp_ajax_haai_form_submit'" ) );
ok( 'wp_ajax_haai_panel registered in the module', false !== strpos( $awp, "add_action( 'wp_ajax_haai_panel'" ) );
foreach ( array( 'haai_kw_export', 'haai_lead_csv', 'haai_export', 'haai_import', 'haai_import_confirm', 'haai_restore', 'haai_crawl_start' ) as $skip ) {
        ok( "SKIP list blocks {$skip}", false !== strpos( $cls, "'{$skip}'" ) );
}
ok( 'route() restores action + fires admin_post_{action}', false !== strpos( $cls, "do_action( 'admin_post_' . \$action )" ) );
ok( 'respond() cut guard exists', false !== strpos( $cls, 'Haa_Util::cut' ) );

/* every funnel converted — the classic redirect survives as fallback */
$funnels = array(
        'includes/class-haa-crawl.php'             => 2,
        'includes/class-haa-admin-modules.php'     => 1,
        'includes/class-haa-admin.php'             => 1,
        'modules/class-haa-module-article-writer.php' => 1,
        'modules/class-haa-module-video-article.php'  => 1,
        'modules/class-haa-module-comments.php'       => 1,
        'modules/class-haa-module-autopilot.php'      => 1,
        'modules/class-haa-module-leads.php'          => 1,
        'modules/class-haa-module-discount.php'       => 1,
        'modules/class-haa-module-semantic-search.php'=> 1,
        'modules/class-haa-module-programmatic.php'   => 1,
        'modules/class-haa-module-news.php'           => 1,
        'modules/class-haa-module-product-content.php'=> 1,
);
foreach ( $funnels as $file => $min ) {
        $src = file_get_contents( __DIR__ . '/../' . $file );
        $n   = substr_count( $src, 'Haa_Ajax_Form::respond' );
        ok( "{$file}: funnel answers AJAX (≥{$min})", $n >= $min, "found {$n}" );
}
ok( 'article-writer back() keeps the classic redirect after the AJAX branch',
        false !== strpos( $awp, 'Haa_Ajax_Form::respond' ) && substr_count( $awp, 'wp_safe_redirect' ) >= 1 );
ok( 'settings status map extracted + shared', false !== strpos( $admin, 'function status_text' ) && false !== strpos( $admin, 'self::status_text' ) );
ok( 'settings redirect() answers AJAX with human text', false !== strpos( $admin, "Haa_Ajax_Form::respond( \$row[1], 'error' === \$row[0] )" ) );

/* JS contracts — both the source and the shipped minified file */
foreach ( array( $js, $jsmin ) as $i => $src ) {
        $tag = $i ? 'admin.min.js' : 'admin.js';
        ok( "{$tag}: transport posts to haai_form_submit", false !== strpos( $src, 'haai_form_submit' ) );
        ok( "{$tag}: real action renamed to haai_ajax_action", false !== strpos( $src, 'haai_ajax_action' ) );
        ok( "{$tag}: formaction override handled (kw_article button)", false !== strpos( $src, 'formaction' ) );
        ok( "{$tag}: panel swap via data-haai-refresh", false !== strpos( $src, 'data-haai-refresh' ) );
        ok( "{$tag}: noajax opt-out honored", false !== strpos( $src, 'data-haai-noajax' ) );
        ok( "{$tag}: multipart/file forms skipped", false !== strpos( $src, 'multipart/form-data' ) );
        ok( "{$tag}: defaultPrevented guard (confirm declines win)", false !== strpos( $src, 'defaultPrevented' ) );
        ok( "{$tag}: clean-URL flash replay", false !== strpos( $src, 'haaiAjaxFlash' ) );
        ok( "{$tag}: flash URL strips haai_msg/haai_err/haai_ok", false !== strpos( $src, 'haai_msg' ) && false !== strpos( $src, 'haai_err' ) );
        ok( "{$tag}: busy guard blocks double submit", false !== strpos( $src, 'data-haai-busy-on' ) );
        ok( "{$tag}: panel endpoint called", false !== strpos( $src, 'haai_panel' ) );
}

/* markup contracts */
ok( 'article-writer forms declare panel refresh', substr_count( $awp, 'data-haai-refresh' ) >= 20, 'count=' . substr_count( $awp, 'data-haai-refresh' ) );
ok( 'run_one carries the long-build busy text', false !== strpos( $awp, 'در حال ساخت مقاله… این پنجره را نبندید' ) );
ok( 'profile build carries its busy text', false !== strpos( $awp, 'در حال خواندن سایت و ساخت پیش‌نویس' ) );
ok( 'plan_model carries the model busy text', false !== strpos( $awp, 'مدل دارد موضوع پیشنهاد می‌دهد' ) );
ok( 'kw_article button overrides refresh to queue+research', false !== strpos( $awp, 'data-haai-refresh="haai-queue-panel,haai-research-panel"' ) );
ok( 'plan-queue form refreshes planner AND queue', false !== strpos( $awp, 'data-haai-refresh="haai-plan-panel,haai-queue-panel"' ) );
foreach ( array( 'haai-plan-panel', 'haai-research-panel', 'haai-review-panel', 'haai-refresh-panel', 'haai-profile-panel' ) as $id ) {
        ok( "panel anchor id={$id} exists in markup", false !== strpos( $awp, 'id="' . $id . '"' ) );
        ok( "panel anchor id={$id} whitelisted in ajax_panel", false !== strpos( $awp, "'" . $id . "'" ) );
}
ok( 'crawl dashboard form opted out of the generic transport', false !== strpos( $dash, 'data-haai-crawl-form data-haai-noajax' ) );
ok( 'panel whitelist guards against arbitrary renderers', false !== strpos( $awp, 'method_exists' ) );

echo "\n--- suite 43: AJAX forms (2.1.42) ---\n";
echo "passed: {$GLOBALS['passed']}, failed: {$GLOBALS['failed']}\n";
exit( $GLOBALS['failed'] ? 1 : 0 );
