<?php
/**
 * Hami AI Assistant — generic AJAX form transport (2.1.40).
 *
 * Every admin form this plugin renders posts to admin-post.php and the
 * handler ends in a redirect with the message riding in the URL
 * (?haai_msg=… / ?haai_err=…). That PRG round-trip churns the browser
 * history with dirty URLs, reloads the whole admin (killing scroll
 * position and any live JS state) and — on screens that never printed
 * haai_msg — bought nothing at all. The owner asked for the crawl
 * progress-bar experience everywhere: «کل این موارد ایجکس باشه، دکمه‌ها و
 * … هی url نزنه».
 *
 * The crawl button already went AJAX in 2.1.20 with a bespoke endpoint.
 * Repeating that per handler would mean ~67 new endpoints and ~67 new
 * fetches. Instead there is ONE transport:
 *
 *   admin.js intercepts every plugin form (input[name="action"] starts
 *   with haai_), renames that field to haai_ajax_action, and POSTs the
 *   whole FormData to admin-ajax.php?action=haai_form_submit. route()
 *   restores the original action, defines HAAI_AJAX_FORM and fires the
 *   very same admin_post_{action} hook the classic path uses — so there
 *   is exactly ONE handler implementation, one sanitization trail and
 *   one nonce check, whichever transport the browser took.
 *
 * The handler's exit funnel (each module's back() / redirect()) asks
 * respond() first: under AJAX it answers JSON {success, data:{ok,msg}}
 * and stops; the classic redirect stays untouched for no-JS fallback.
 *
 * Forms that stream bytes (CSV exports) or upload files skip the
 * transport in JS and are refused here again as defense in depth.
 *
 * @package Hami_AI_Assistant
 */

if ( ! defined( 'ABSPATH' ) ) {
        exit;
}

/**
 * One AJAX endpoint for every admin_post form the plugin owns.
 */
final class Haa_Ajax_Form {

        /**
         * Actions that must NEVER ride the JSON transport: they stream a
         * download, upload a file, or own a bespoke JS flow already.
         *
         * @var string[]
         */
        const SKIP = array(
                'haai_kw_export',      // CSV download.
                'haai_lead_csv',       // CSV download.
                'haai_export',         // Settings JSON download.
                'haai_import',         // File upload → preview flow.
                'haai_import_confirm', // Multipart confirm.
                'haai_restore',        // Multipart restore.
                'haai_crawl_start',    // Dashboard crawl has its own JS transport.
        );

        /**
         * Register the transport endpoint.
         *
         * @return void
         */
        public static function hooks() {
                add_action( 'wp_ajax_haai_form_submit', array( __CLASS__, 'route' ) );
        }

        /**
         * Is the current execution the AJAX form transport?
         *
         * @return bool
         */
        public static function wants() {
                return defined( 'HAAI_AJAX_FORM' ) && HAAI_AJAX_FORM;
        }

        /**
         * The one funnel every back()/redirect() helper calls first.
         *
         * Under the AJAX transport: print the JSON envelope and stop the
         * request (the caller never reaches its redirect). Under the
         * classic POST: return false and let the caller redirect as it
         * always did — the no-JS fallback keeps working unchanged.
         *
         * HAAI_NO_EXIT (dev harness) echoes and returns true instead of
         * exiting, so tests can assert the payload in-process.
         *
         * @param string $message Banner text (Persian, human).
         * @param bool   $error   True → failure styling / success=false.
         * @return bool True when the response was handled here.
         */
        public static function respond( $message, $error = false ) {
                if ( ! self::wants() ) {
                        return false;
                }
                $payload = array(
                        'success' => ! $error,
                        'data'    => array(
                                'ok'  => ! $error,
                                'msg' => (string) Haa_Util::cut( (string) $message, 300 ),
                        ),
                );

                // A handler that produced fresh markup for one panel (the competitor
                // analysis, which is deliberately never stored) rides back in the same
                // envelope so the browser needs no second request to see it.
                if ( ! empty( $GLOBALS['haai_ajax_extra'] ) && is_array( $GLOBALS['haai_ajax_extra'] ) ) {
                        $payload['data'] = array_merge( $payload['data'], $GLOBALS['haai_ajax_extra'] );
                }
                $GLOBALS['haai_ajax_out'] = $payload;
                echo wp_json_encode( $payload );
                if ( defined( 'HAAI_NO_EXIT' ) && HAAI_NO_EXIT ) {
                        return true;
                }
                exit;
        }

        /**
         * wp_ajax_haai_form_submit — dispatch to the classic handler.
         *
         * admin-ajax reads $_REQUEST['action'] to route its own dispatch
         * and PHP lets POST override the query string, so the form's real
         * action travels in haai_ajax_action. It is restored into
         * $_POST/$_REQUEST before firing admin_post_{action} so the
         * handler sees exactly what a classic POST would have delivered.
         *
         * @return void
         */
        public static function route() {
                $action = isset( $_POST['haai_ajax_action'] ) ? (string) wp_unslash( $_POST['haai_ajax_action'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- each handler verifies its own nonce.
                $action = preg_replace( '/[^a-z0-9_]/', '', $action );

                if ( ! $action || 0 !== strpos( $action, 'haai_' ) || in_array( $action, self::SKIP, true ) ) {
                        self::respond( __( 'این عملیات از مسیر ایجکس پشتیبانی نمی‌شود.', 'hami-ai-assistant' ), true );
                        return;
                }

                $_POST['action']    = $action;
                $_REQUEST['action'] = $action;

                if ( ! defined( 'HAAI_AJAX_FORM' ) ) {
                        define( 'HAAI_AJAX_FORM', true );
                }

                do_action( 'admin_post_' . $action );

                // A handler that returns without calling back()/respond() has no
                // JSON answer for the browser — never leave it hanging on a
                // silent empty 200.
                self::respond( __( 'پاسخی از عملیات دریافت نشد؛ صفحه را نوسازی کنید و دوباره تلاش کنید.', 'hami-ai-assistant' ), true );
        }
}
