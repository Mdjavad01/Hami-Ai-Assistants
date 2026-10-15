<?php
/**
 * Plugin Name:       Hami AI Assistant
 * Plugin URI:        https://hami-ai.local/hami-ai-assistant
 * Description:       دستیار هوشمند ماژولار برای وردپرس و ووکامرس: چت‌بات فروش، تولید محتوای سئو، مقاله‌نویس، اتوپایلوت، باشگاه لید، مذاکره‌گر تخفیف، کامنت‌گذار هوشمند و جستجوی معنایی.
 * Version:           2.1.2
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            Hami
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       hami-ai-assistant
 * Domain Path:       /languages
 *
 * @package Hami_AI_Assistant
 */

if ( ! defined( 'ABSPATH' ) ) {
        exit;
}

/* -------------------------------------------------------------------------
 * Constants
 * ---------------------------------------------------------------------- */

define( 'HAAI_VERSION', '2.1.2' );
define( 'HAAI_DB_VERSION', '7' );
define( 'HAAI_FILE', __FILE__ );
define( 'HAAI_PATH', plugin_dir_path( __FILE__ ) );
define( 'HAAI_URL', plugin_dir_url( __FILE__ ) );
define( 'HAAI_BASENAME', plugin_basename( __FILE__ ) );
define( 'HAAI_REST_NS', 'hami-ai/v1' );       // Kept from v1 so cached pages keep working.
define( 'HAAI_OPTION', 'hami_ai_assistant' ); // Big settings array, autoload = no.
define( 'HAAI_ACTIVE', 'hami_ai_assistant_on' ); // Tiny gate, autoload = yes.

/* -------------------------------------------------------------------------
 * Autoloader
 *
 * Haa_*                 -> includes/class-haa-*.php
 * Haa_Adapter_*         -> includes/class-haa-api.php  (all adapters live together)
 * Haa_Module_*          -> modules/class-haa-module-*.php
 * ---------------------------------------------------------------------- */

spl_autoload_register(
        function ( $class ) {
                if ( 0 !== strpos( $class, 'Haa_' ) ) {
                        return;
                }
                if ( 0 === strpos( $class, 'Haa_Adapter_' ) ) {
                        require_once HAAI_PATH . 'includes/class-haa-api.php';
                        return;
                }
                // Haa_Module_Chatbot -> modules/class-haa-module-chatbot.php
                // (the class name already carries the "Module_" word the file has).
                if ( 0 === strpos( $class, 'Haa_Module_' ) ) {
                        $slug = strtolower( str_replace( '_', '-', substr( $class, strlen( 'Haa_Module_' ) ) ) );
                        $file = HAAI_PATH . 'modules/class-haa-module-' . $slug . '.php';
                        if ( is_readable( $file ) ) {
                                require_once $file;
                        }
                        return;
                }
                $slug = strtolower( str_replace( '_', '-', substr( $class, strlen( 'Haa_' ) ) ) );
                $file = HAAI_PATH . 'includes/class-haa-' . $slug . '.php';
                if ( is_readable( $file ) ) {
                        require_once $file;
                }
        }
);

/* -------------------------------------------------------------------------
 * Global helpers shared by every module.
 * ---------------------------------------------------------------------- */

/**
 * CSS custom properties for the chat widget (appearance section).
 *
 * Every name carries the `--haai-` prefix, the one prefix the stylesheets
 * actually read; a settings value that no rule looks up is a silent no-op.
 * Defaults live in chatbot.css on `:root`, so this block (printed later) wins.
 *
 * @param array $s Full settings array.
 * @return string CSS declarations.
 */
function haa_css_vars( $s ) {
        $a = isset( $s['appearance'] ) ? $s['appearance'] : array();
        $px = function ( $v ) {
                return (int) $v . 'px';
        };
        $primary = (string) ( $a['primary_color'] ?? '#4f46e5' );
        $rgb  = haa_hex_rgb( $primary );
        $trgb = haa_hex_rgb( (string) ( $a['text_color'] ?? '#1f2937' ) );
        return '--haai-primary:' . $primary . ';'
                . '--haai-on-accent:' . haa_contrast_text( $primary ) . ';'
                // Derived tints keep the whole widget coherent with the two colours
                // the admin picked: soft surface, focus ring, muted text, hairline.
                . ( $rgb ? '--haai-accent-soft:rgba(' . $rgb . ',.12);--haai-accent-ring:rgba(' . $rgb . ',.16);' : '' )
                . ( $trgb ? '--haai-faint:rgba(' . $trgb . ',.62);--haai-line:rgba(' . $trgb . ',.09);' : '' )
                . '--haai-bg:' . ( $a['bg_color'] ?? '#fff' ) . ';'
                . '--haai-text:' . ( $a['text_color'] ?? '#1f2937' ) . ';'
                . '--haai-bubble:' . ( $a['secondary_color'] ?? '#eef2ff' ) . ';'
                . '--haai-radius:' . $px( $a['border_radius'] ?? 16 ) . ';'
                . '--haai-fs:' . $px( $a['font_size'] ?? 14 ) . ';'
                . '--haai-btn-size:' . $px( $a['button_size'] ?? 56 ) . ';'
                . '--haai-font:' . haa_font_stack( $a['font'] ?? 'system' ) . ';';
}

/**
 * "R,G,B" channels of a hex colour, or '' when the value is not a colour.
 *
 * Used only to compose rgba() tints (accent-soft, focus ring, hairline) in
 * haa_css_vars(); an empty return simply leaves the stylesheet defaults in
 * place instead of printing a broken declaration.
 *
 * @param string $hex #rgb or #rrggbb.
 * @return string "r,g,b" or ''.
 */
function haa_hex_rgb( $hex ) {
        $hex = ltrim( (string) $hex, '#' );
        if ( 3 === strlen( $hex ) ) {
                $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }
        if ( 6 !== strlen( $hex ) || ! ctype_xdigit( $hex ) ) {
                return '';
        }
        return (string) hexdec( substr( $hex, 0, 2 ) ) . ',' . hexdec( substr( $hex, 2, 2 ) ) . ',' . hexdec( substr( $hex, 4, 2 ) );
}

/**
 * Readable text colour for an arbitrary accent, by WCAG relative luminance.
 *
 * Without this a store owner who picks a pale accent gets white-on-pale text
 * on the send button and the widget header — invisible, and an instant AA
 * failure. White wins up to L≈0.19, near-black after that.
 *
 * @param string $hex Accent colour (#rgb or #rrggbb).
 * @return string Hex colour to print on top of it.
 */
function haa_contrast_text( $hex ) {
        $hex = ltrim( (string) $hex, '#' );
        if ( 3 === strlen( $hex ) ) {
                $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }
        if ( 6 !== strlen( $hex ) || ! ctype_xdigit( $hex ) ) {
                return '#ffffff';
        }
        $rgb = array();
        for ( $i = 0; $i < 3; $i++ ) {
                $v   = hexdec( substr( $hex, $i * 2, 2 ) ) / 255;
                $rgb[] = $v <= 0.03928 ? $v / 12.92 : pow( ( $v + 0.055 ) / 1.055, 2.4 );
        }
        $luminance = 0.2126 * $rgb[0] + 0.7152 * $rgb[1] + 0.0722 * $rgb[2];
        return $luminance > 0.19 ? '#12141a' : '#ffffff';
}

/**
 * Font stack for the chosen family. A family with no local woff2 file simply
 * never resolves and the browser falls through to the system stack — no CDN,
 * no 404, no broken Persian text.
 *
 * @param string $family Font slug from the appearance settings.
 * @return string CSS font-family value.
 */
function haa_font_stack( $family ) {
        $named = array(
                'vazirmatn' => '"Vazirmatn"',
                'iransans'  => '"IRANSans"',
                'dana'      => '"Dana"',
                'yekan'     => '"YekanBakh"',
        );
        $lead = isset( $named[ $family ] ) ? $named[ $family ] . ',' : '';
        return $lead . 'Tahoma,"Segoe UI",system-ui,-apple-system,sans-serif';
}

/**
 * @font-face for locally hosted WOFF2 fonts. Never points at a CDN and never
 * emits a rule for a file that is missing (hosts get a 404 we cannot afford).
 *
 * @param string $family Font slug.
 * @return string CSS or ''.
 */
function haa_font_face( $family ) {
        $map = array(
                'vazirmatn' => array( 'Vazirmatn-Regular.woff2', 'Vazirmatn' ),
                'iransans'  => array( 'IRANSans.woff2', 'IRANSans' ),
                'dana'      => array( 'Dana-Regular.woff2', 'Dana' ),
                'yekan'     => array( 'YekanBakh-Regular.woff2', 'YekanBakh' ),
        );
        if ( ! isset( $map[ $family ] ) ) {
                return '';
        }
        list( $file, $name ) = $map[ $family ];
        $path = HAAI_PATH . 'assets/fonts/' . $file;
        if ( ! is_readable( $path ) ) {
                return '';
        }
        return '@font-face{font-family:"' . $name . '";src:url("' . HAAI_URL . 'assets/fonts/' . $file . '") format("woff2");font-weight:400;font-style:normal;font-display:swap;}';
}

/**
 * Path of a plugin asset, preferring the minified build when one exists.
 *
 * The readable sources stay in version control; `tools/minify.mjs` writes
 * `*.min.css` / `*.min.js` siblings. A checkout that never ran the build step
 * simply has no min files, so every call site falls back to the source and
 * nothing can break. Under WP_DEBUG the source is always used, otherwise
 * editing a stylesheet would appear to do nothing.
 *
 * @param string $rel Path relative to the plugin folder, e.g. assets/css/admin.css.
 * @return string Path relative to the plugin folder.
 */
function haa_asset_path( $rel ) {
        $rel = ltrim( str_replace( '\\', '/', $rel ), '/' );

        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                return $rel;
        }

        $ext = pathinfo( $rel, PATHINFO_EXTENSION );
        if ( '' === $ext ) {
                return $rel;
        }

        $min = substr( $rel, 0, 0 - strlen( $ext ) - 1 ) . '.min.' . $ext;

        return is_readable( HAAI_PATH . $min ) ? $min : $rel;
}

/**
 * Everything the frontend needs, minus every secret.
 *
 * @return array
 */
function haa_public_config() {
        $s = Haa_Settings::get();
        $a = $s['appearance'];
        $g = $s['general'];
        $b = $s['chat'] ?? array();

        return array(
                'i18n'      => array(
                        'offline' => $b['offline_message'] ?? '',
                        'error'   => $b['error_message'] ?? '',
                        'copy'    => __( 'کپی', 'hami-ai-assistant' ),
                        'typing'  => (bool) ( $b['typing_indicator'] ?? 1 ),
                        'login'   => $s['advanced']['login_required_message'] ?? '',
                        'name'    => $b['lead_name_label'] ?? 'نام',
                        'phone'   => $b['lead_phone_label'] ?? 'شماره موبایل',
                        'send'    => __( 'ارسال', 'hami-ai-assistant' ),
                        'leadPhone' => __( 'شماره موبایل معتبر وارد کنید.', 'hami-ai-assistant' ),
                ),
                'rest'      => esc_url_raw( rest_url( HAAI_REST_NS ) ),
                'tokenUrl'  => esc_url_raw( rest_url( HAAI_REST_NS . '/token' ) ),
                'widgetUrl' => esc_url_raw( rest_url( HAAI_REST_NS . '/widget' ) ),
                'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
                'css'       => HAAI_URL . haa_asset_path( 'assets/css/chatbot.css' ) . '?ver=' . HAAI_VERSION,
                'js'        => HAAI_URL . haa_asset_path( 'assets/js/chatbot.js' ) . '?ver=' . HAAI_VERSION,
                'fab'       => array(
                        'side'    => $g['floating_position'],
                        'bottom'  => (int) $g['floating_bottom'],
                        'offset'  => (int) $g['floating_side'],
                        'size'    => (int) $a['button_size'],
                        'primary' => $a['primary_color'],
                        'icon'    => haa_fab_icon( $s ),
                ),
                'history'   => array( 'max' => (int) ( $b['max_history'] ?? 6 ) ),
                        'lead'      => array(
                                'enabled'  => (bool) ( $b['lead_capture'] ?? 0 ),
                                'required' => (string) ( $b['lead_requirement'] ?? 'off' ),
                                'consent'  => (string) ( $b['lead_consent_text'] ?? '' ),
                        ),
        );
}

/**
 * FAB icon markup — inline SVG unless the admin uploaded one.
 *
 * @param array $s Settings.
 * @return string
 */
function haa_fab_icon( $s ) {
        if ( ! empty( $s['appearance']['custom_icon'] ) ) {
                $url = wp_get_attachment_url( (int) $s['appearance']['custom_icon'] );
                if ( $url ) {
                        return '<img src="' . esc_url( $url ) . '" alt="" width="26" height="26" loading="lazy">';
                }
        }
        return '<svg viewBox="0 0 24 24" width="24" height="24" aria-hidden="true"><path d="M12 3C6.5 3 2 6.6 2 11c0 2.2 1.2 4.2 3.1 5.6L4 21l4.9-2.6c1 .3 2 .4 3.1.4 5.5 0 10-3.6 10-8s-4.5-8-10-8zm-4 9.5a1.4 1.4 0 1 1 0-2.8 1.4 1.4 0 0 1 0 2.8zm4 0a1.4 1.4 0 1 1 0-2.8 1.4 1.4 0 0 1 0 2.8zm4 0a1.4 1.4 0 1 1 0-2.8 1.4 1.4 0 0 1 0 2.8z" fill="currentColor"/></svg>';
}

/**
 * Inline CSS: variables + font + the admin's custom CSS.
 *
 * @param array $s Settings.
 * @return string
 */
function haa_inline_css( $s ) {
        $css = ':root{' . haa_css_vars( $s ) . '}';
        $css .= haa_font_face( isset( $s['appearance']['font'] ) ? $s['appearance']['font'] : 'system' );
        if ( ! empty( $s['appearance']['custom_css'] ) ) {
                $css .= $s['appearance']['custom_css'];
        }
        return $css;
}

/**
 * True when the WooCommerce API is available (plugin active, not merely installed).
 *
 * @return bool
 */
function haa_is_woo() {
        return class_exists( 'WooCommerce' );
}

/* -------------------------------------------------------------------------
 * Bootstrap
 * ---------------------------------------------------------------------- */

final class Haa_Plugin {

        /** @var Haa_Plugin|null */
        private static $instance = null;

        /** @var Haa_Modules */
        public $modules;

        /** @var Haa_Admin|null */
        public $admin = null;

        /**
         * @return Haa_Plugin
         */
        public static function instance() {
                if ( null === self::$instance ) {
                        self::$instance = new self();
                }
                return self::$instance;
        }

        private function __construct() {
                register_activation_hook( HAAI_FILE, array( $this, 'activate' ) );
                register_deactivation_hook( HAAI_FILE, array( $this, 'deactivate' ) );
                // Registered here, NOT in boot(): activation runs in plugins.php after
                // init has already fired, and wp_schedule_event() needs the custom
                // interval to exist at that point or it silently rejects the event.
                add_filter( 'cron_schedules', array( $this, 'cron_schedules' ) );
                add_action( 'init', array( $this, 'boot' ), 5 );
        }

        /**
         * Activation: defaults, schema, migration from v1, module activation.
         */
        public function activate() {
                require_once ABSPATH . 'wp-admin/includes/upgrade.php';

                Haa_Settings::install();
                Haa_DB::create();

                // Move a Hami-Ai-Chatbot install over without losing anything.
                Haa_Migrator::maybe_migrate();

                Haa_Modules::activate_all();

                self::ensure_cron();
        }

        /**
         * Deactivation: cron only. uninstall.php owns the data.
         */
        public function deactivate() {
                wp_clear_scheduled_hook( 'haai_daily_cleanup' );
                wp_clear_scheduled_hook( 'haai_autopilot_tick' );
                wp_clear_scheduled_hook( 'haai_aw_tick' );
                wp_clear_scheduled_hook( 'haai_queue_tick' );
        }

        /**
         * Boot. Zero cost when the plugin is switched off.
         */
        public function boot() {
                if ( ! Haa_Settings::is_active() ) {
                        return;
                }

                load_plugin_textdomain( 'hami-ai-assistant', false, dirname( HAAI_BASENAME ) . '/languages' );

                require_once HAAI_PATH . 'templates/chat-widget.php';

                // Schema bookkeeping and the v1 migration are admin/cron work: a visitor
                // request must not pay SHOW TABLES or the migration-flag read (M9).
                if ( is_admin() || wp_doing_cron() ) {
                        Haa_DB::ensure();
                        Haa_Migrator::maybe_migrate();
                }

                add_action( 'haai_daily_cleanup', array( 'Haa_DB', 'cleanup_all' ) );

                // The queue drains from cron only — one bounded batch per event.
                Haa_Queue::hooks();

                // Self-heal: re-queue any missing cron event. Existing installs get the
                // 5-minute ticks they never received, and a module toggled on later
                // finds its event waiting.
                self::ensure_cron();

                $this->modules = Haa_Modules::instance();
                $this->modules->register_enabled();

                Haa_Privacy::hooks();

                // Generic SEO: robots + Article schema for _haai_generated posts.
                Haa_Seo::hooks();

                // REST runs in both contexts: the cached frontend calls /token and /chat.
                Haa_REST::hooks();

                if ( is_admin() ) {
                        $this->admin = new Haa_Admin();
                        $this->admin->hooks();
                } else {
                        $this->frontend_hooks();
                }

                /**
                 * Fires once the plugin and every enabled module have booted.
                 */
                do_action( 'haai_booted' );
        }

        /**
         * Queue every cron event this plugin owns. Idempotent and cheap (the cron
         * option is already in memory on every request), so it runs on activation
         * AND on every boot: installs that activated before the interval was
         * registered heal themselves, and toggling a module on re-queues its tick.
         *
         * Module-specific cron events (autopilot, article-writer) are scheduled
         * ONLY when their module is enabled; toggle-off clears them via the
         * module's own deactivate().
         */
        public static function ensure_cron() {
                if ( ! wp_next_scheduled( 'haai_daily_cleanup' ) ) {
                        wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'haai_daily_cleanup' );
                }
                if ( ! wp_next_scheduled( 'haai_queue_tick' ) ) {
                        wp_schedule_event( time() + MINUTE_IN_SECONDS * 2, 'haai_five_minutes', 'haai_queue_tick' );
                }
                // Only schedule the autopilot tick when the module is actually on;
                // toggle-off clears it via the module's deactivate().
                if ( Haa_Settings::is_module_enabled( 'autopilot' ) && ! wp_next_scheduled( 'haai_autopilot_tick' ) ) {
                        wp_schedule_event( time() + MINUTE_IN_SECONDS * 5, 'haai_five_minutes', 'haai_autopilot_tick' );
                }
                // Same for the article-writer's batch tick.
                if ( Haa_Settings::is_module_enabled( 'article-writer' ) && ! wp_next_scheduled( 'haai_aw_tick' ) ) {
                        wp_schedule_event( time() + MINUTE_IN_SECONDS, 'haai_five_minutes', 'haai_aw_tick' );
                }
        }

        /**
         * Five-minute cron interval for the autopilot queue.
         *
         * @param array $schedules Existing intervals.
         * @return array
         */
        public function cron_schedules( $schedules ) {
                $schedules['haai_five_minutes'] = array(
                        'interval' => 5 * MINUTE_IN_SECONDS,
                        'display'  => __( 'هر ۵ دقیقه (Hami AI)', 'hami-ai-assistant' ),
                );
                return $schedules;
        }

        /**
         * True once an inline (shortcode) widget has been rendered on this page.
         *
         * @var bool
         */
        private static $inline_widget = false;

        /**
         * Frontend: floating launcher + shortcodes. Nothing else runs here.
         */
        private function frontend_hooks() {
                add_action( 'wp_enqueue_scripts', array( $this, 'maybe_enqueue_loader' ) );
                add_action( 'wp_footer', array( $this, 'print_fab' ), 1 );

                // Primary name. Documented in README as the canonical shortcode.
                add_shortcode( 'hami_ai_assistant', array( $this, 'render_shortcode' ) );

                // Aliases, kept so existing content keeps rendering:
                //  - haai_assistant : the short form of the same tag.
                //  - hami_ai_chat   : v1 compatibility.
                add_shortcode( 'haai_assistant', array( $this, 'render_shortcode' ) );
                add_shortcode( 'hami_ai_chat', array( $this, 'render_legacy_shortcode' ) );

                add_shortcode( 'haai_search', array( $this, 'render_search_shortcode' ) );
        }

        /**
         * Critical CSS for the launcher button, printed in <head>.
         *
         * chatbot.css is on purpose not loaded until the panel is first opened —
         * that is what keeps a floating install cheap. Without these few rules the
         * button is a plain static element at the very end of the document, so the
         * visitor has to scroll to the bottom of the page to find it. The values
         * mirror .haai-fab in chatbot.css; both are derived from the same settings.
         *
         * @param array $s Full settings.
         * @return string CSS, ~0.5KB.
         */
        public static function fab_css( $s ) {
                $side = ( isset( $s['general']['floating_position'] ) && 'left' === $s['general']['floating_position'] ) ? 'left' : 'right';
                $far  = 'left' === $side ? 'right' : 'left';
                $size = (int) ( $s['appearance']['button_size'] ?? 56 );
                $off  = (int) ( $s['general']['floating_side'] ?? 24 );
                $bot  = (int) ( $s['general']['floating_bottom'] ?? 24 );
                $bg   = (string) ( $s['appearance']['primary_color'] ?? '#4f46e5' );
                $fg   = haa_contrast_text( $bg );

                return '.haai-fab{position:fixed;z-index:99998;display:flex;align-items:center;justify-content:center;'
                        . 'box-sizing:border-box;width:' . $size . 'px;height:' . $size . 'px;padding:0;line-height:0;border:0;border-radius:50%;cursor:pointer;'
                        . $side . ':calc(' . $off . 'px + env(safe-area-inset-' . $side . ',0px));'
                        . $far . ':auto;bottom:calc(' . $bot . 'px + env(safe-area-inset-bottom,0px));'
                        . 'background:' . $bg . ';color:' . $fg . ';box-shadow:0 6px 20px rgba(0,0,0,.22)}'
                        . '.haai-fab:focus-visible{outline:2px solid ' . $fg . ';outline-offset:2px}'
                        . '.haai-fab[hidden]{display:none}';
        }

        /**
         * The launcher markup, printed in the footer so the button is in the page
         * before any JavaScript runs. loader.js adopts it instead of building its
         * own copy (it still creates one if the markup is missing, e.g. a page
         * cached by an older version of the plugin).
         *
         * @return void
         */
        public function print_fab() {
                // A shortcode widget already on the page means no second launcher.
                if ( self::$inline_widget ) {
                        return;
                }

                $s = Haa_Settings::get();
                if ( ! self::should_display( $s ) || 'shortcode' === $s['general']['display_mode'] ) {
                        return;
                }

                printf(
                        '<button type="button" class="haai-fab" aria-expanded="false" aria-label="%1$s">%2$s</button>',
                        esc_attr__( 'گفتگو با پشتیبانی', 'hami-ai-assistant' ),
                        haa_fab_icon( $s ) // phpcs:ignore WordPress.Security.EscapeOutput
                );
        }

        /**
         * Legacy [hami_ai_chat] — same renderer, old tag. Alias only.
         *
         * @param array $atts Shortcode attributes.
         * @return string
         */
        public function render_legacy_shortcode( $atts ) {
                return $this->render_shortcode( $atts );
        }

        /**
         * Enqueue the floating launcher only when it is actually going to render.
         */
        public function maybe_enqueue_loader() {
                $s = Haa_Settings::get();

                if ( ! self::should_display( $s ) ) {
                        return;
                }
                if ( 'shortcode' === $s['general']['display_mode'] ) {
                        return;
                }
                global $post;
                if ( $post instanceof WP_Post
                        && ( has_shortcode( $post->post_content, 'hami_ai_assistant' )
                                || has_shortcode( $post->post_content, 'haai_assistant' )
                                || has_shortcode( $post->post_content, 'hami_ai_chat' ) ) ) {
                        return;
                }

                // Critical CSS for the button only. The handle is registered with
                // `false` as its source on purpose: enqueueing it prints the inline
                // block in the head and nothing else. Pointing it at chatbot.css here
                // would pull 7KB into every page view and undo the lazy loader.
                wp_register_style( 'haai-fab', false, array(), HAAI_VERSION );
                wp_enqueue_style( 'haai-fab' );
                wp_add_inline_style( 'haai-fab', self::fab_css( $s ) );

                wp_enqueue_script( 'haai-loader', HAAI_URL . haa_asset_path( 'assets/js/loader.js' ), array(), HAAI_VERSION, true );
                wp_add_inline_script(
                        'haai-loader',
                        'window.HAAI_CONFIG=' . wp_json_encode( haa_public_config() ) . ';',
                        'before'
                );
        }

        /**
         * [hami_ai_assistant width="420px"] — inline widget.
         *
         * Canonical shortcode. [haai_assistant] and the v1 [hami_ai_chat] resolve
         * to this same renderer.
         *
         * @param array $atts Attributes.
         * @return string
         */
        public function render_shortcode( $atts ) {
                $s = Haa_Settings::get();
                if ( ! self::should_display( $s ) ) {
                        return '';
                }
                if ( 'floating' === $s['general']['display_mode'] ) {
                        return '';
                }

                $atts = shortcode_atts( array( 'width' => '', 'class' => '' ), $atts, 'hami_ai_assistant' );

                wp_enqueue_style( 'haai-chat', HAAI_URL . 'assets/css/chatbot.css', array(), HAAI_VERSION, 'all' );
                wp_add_inline_style( 'haai-chat', haa_inline_css( $s ) );
                wp_enqueue_script( 'haai-chat', HAAI_URL . 'assets/js/chatbot.js', array(), HAAI_VERSION, true );
                wp_add_inline_script(
                        'haai-chat',
                        'window.HAAI_CONFIG=' . wp_json_encode( haa_public_config() ) . ';',
                        'before'
                );

                ob_start();
                haa_render_widget( $atts );
                $html = ob_get_clean();

                // Tell print_fab() a widget is already on the page, so the floating
                // launcher is not added next to it.
                self::$inline_widget = true;

                return $html;
        }

        /**
         * [haai_search] — smart search box (semantic-search module).
         *
         * @param array $atts Attributes.
         * @return string
         */
        public function render_search_shortcode( $atts ) {
                if ( ! Haa_Modules::is_enabled( 'semantic-search' ) || ! class_exists( 'Haa_Module_Semantic_Search' ) ) {
                        return '';
                }
                $atts = shortcode_atts( array( 'limit' => 5 ), $atts, 'haai_search' );
                return Haa_Module_Semantic_Search::render_form( (int) $atts['limit'] );
        }

        /**
         * Visibility rules shared by the widget, the shortcodes and the REST gate.
         *
         * @param array $s   Settings.
         * @param string $url Page URL the caller renders on (REST gate), or ''.
         * @return bool
         */
        public static function should_display( $s, $url = '' ) {
                if ( empty( $s['general']['enabled'] ) ) {
                        return false;
                }
                if ( ! Haa_Modules::is_enabled( 'chatbot' ) ) {
                        return false;
                }

                $devices = (array) $s['general']['show_devices'];
                $devices = $devices ? $devices : array( 'desktop', 'mobile' );
                $is_mobile = wp_is_mobile();
                if ( $is_mobile && ! in_array( 'mobile', $devices, true ) ) {
                        return false;
                }
                if ( ! $is_mobile && ! in_array( 'desktop', $devices, true ) ) {
                        return false;
                }

                // 'guest' is a pseudo-role so "only visitors who are not logged in"
                // is one control rather than a second setting that can contradict
                // advanced.guest_access.
                $roles = (array) $s['general']['show_roles'];
                if ( $roles ) {
                        if ( ! is_user_logged_in() ) {
                                if ( ! in_array( 'guest', $roles, true ) ) {
                                        return false;
                                }
                        } elseif ( ! array_intersect( $roles, (array) wp_get_current_user()->roles ) ) {
                                return false;
                        }
                }

                // guest_access lives in `advanced` (v1 read it from `general`, which
                // silently disabled the rule).
                $mode = (int) ( $s['advanced']['guest_access'] ?? 0 );
                if ( 1 === $mode && is_user_logged_in() ) {
                        return false;
                }
                if ( 2 === $mode && ! is_user_logged_in() ) {
                        return false;
                }

                $post_types = (array) $s['general']['show_on_post_types'];
                $post_types = $post_types ? $post_types : array( 'post', 'page' );

                // REST has no queried object: when the caller tells us which page it
                // renders on, apply the same singular rules it got.
                $pid = 0;
                if ( '' !== $url ) {
                        $pid = (int) url_to_postid( $url );
                } elseif ( is_singular() ) {
                        $pid = (int) get_queried_object_id();
                }

                $rule = self::pages_mode( $s );

                if ( $pid ) {
                        if ( 'include' === $rule ) {
                                $list = array_map( 'intval', (array) $s['general']['show_on_pages'] );
                                if ( ! in_array( $pid, $list, true ) ) {
                                        return false;
                                }
                        } elseif ( 'exclude' === $rule ) {
                                $list = array_map( 'intval', (array) $s['general']['exclude_pages'] );
                                if ( in_array( $pid, $list, true ) ) {
                                        return false;
                                }
                        }
                        return in_array( get_post_type( $pid ), $post_types, true );
                }

                // Home, archives, search, 404 and the WooCommerce endpoints have no
                // document id, so they are matched by their conditional-tag token.
                $token = self::special_token();
                if ( '' !== $token ) {
                        $special = array_map( 'strval', (array) $s['general']['special_pages'] );
                        if ( 'include' === $rule ) {
                                return in_array( $token, $special, true );
                        }
                        if ( 'exclude' === $rule ) {
                                return ! in_array( $token, $special, true );
                        }
                        return true;
                }

                // Nothing to match: a page template with no main query, or a REST call
                // about an unknown URL. "Only these" is a whitelist, so it stays shut;
                // the other two rules show.
                return 'include' !== $rule;
        }

        /**
         * Which of the three page rules is live: all | include | exclude.
         *
         * '' means the admin never chose since the radio existed, so the mode is
         * read off the two lists — and with exclude winning when both are filled,
         * exactly how the old pair of listboxes behaved.
         *
         * @param array $s Settings.
         * @return string
         */
        public static function pages_mode( $s ) {
                $mode = isset( $s['general']['pages_mode'] ) ? (string) $s['general']['pages_mode'] : '';
                if ( in_array( $mode, array( 'all', 'include', 'exclude' ), true ) ) {
                        return $mode;
                }
                $in  = array_filter( array_map( 'intval', (array) ( $s['general']['show_on_pages'] ?? array() ) ) );
                $out = array_filter( array_map( 'intval', (array) ( $s['general']['exclude_pages'] ?? array() ) ) );
                if ( $out ) {
                        return 'exclude';
                }
                if ( $in ) {
                        return 'include';
                }
                return 'all';
        }

        /**
         * Conditional-tag token for the request being served, '' for a plain
         * singular document. Every branch is a global read — no queries.
         *
         * @return string
         */
        public static function special_token() {
                if ( is_front_page() ) {
                        return 'front';
                }
                if ( is_home() ) {
                        return 'blog';
                }
                if ( is_search() ) {
                        return 'search';
                }
                if ( is_404() ) {
                        return '404';
                }
                // WooCommerce supplies these only when it is active.
                if ( function_exists( 'is_product' ) && is_product() ) {
                        return 'product';
                }
                if ( function_exists( 'is_shop' ) && is_shop() ) {
                        return 'shop';
                }
                if ( function_exists( 'is_cart' ) && is_cart() ) {
                        return 'cart';
                }
                if ( function_exists( 'is_checkout' ) && is_checkout() ) {
                        return 'checkout';
                }
                if ( function_exists( 'is_account_page' ) && is_account_page() ) {
                        return 'account';
                }
                if ( is_archive() ) {
                        return 'archive';
                }
                return '';
        }
}

// Instantiated at file scope so register_activation_hook() is registered
// while the file is being included (activation runs it immediately, without
// waiting for another hook).
Haa_Plugin::instance();
