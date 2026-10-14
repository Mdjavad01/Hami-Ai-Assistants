<?php
/**
 * Admin screens: dashboard, settings tabs, modules and the AJAX helpers.
 *
 * Every tab is its own POST form so a long page never loses work to a single
 * validation error. Module tabs are contributed by the modules themselves
 * through the `haai_settings_sections` / `haai_settings_fields_{section}`
 * actions, which means a disabled module leaves no orphan tab behind.
 *
 * @package Hami_AI_Assistant
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Haa_Admin {

	/**
	 * Top-level page slug. This line is the definition; everywhere else reads
	 * Haa_Util::screen( 'settings' ) so the value exists exactly once in code.
	 *
	 * @var string
	 */
	const PAGE = 'haai-settings';

	/** @var int Hard ceiling on the length of a public search query. */
	const SEARCH_MAX_QUERY = 120;

	/** @var int Hard ceiling on the number of hits one request may ask for. */
	const SEARCH_MAX_LIMIT = 20;

	/** @var int Searches allowed per minute, per identity, own bucket. */
	const SEARCH_RATE_PER_MINUTE = 60;

	/**
	 * Transient holding the validated-but-not-yet-applied import (A2).
	 *
	 * It is the second half of the confirm step: the summary the admin saw and
	 * the payload that gets written are the same bytes, and the copy is
	 * single-use and short-lived.
	 */
	const PENDING_OPTION = 'haai_import_pending';

	/** @var int Seconds a pending import stays confirmable. */
	const PENDING_TTL = 600;

	/** @var string|null */
	private $status = null;

	/** @var bool Whether the row currently open is a <fieldset> (bare) row. */
	private $field_bare = false;

	/** @return void */
	public function hooks() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
		add_action( 'admin_post_haai_save', array( $this, 'save' ) );
		add_action( 'admin_post_haai_modules_save', array( $this, 'save_modules' ) );
		add_action( 'admin_post_haai_export', array( $this, 'export' ) );
		add_action( 'admin_post_haai_import', array( $this, 'import_preview' ) );
		add_action( 'admin_post_haai_import_confirm', array( $this, 'import_confirm' ) );
		add_action( 'admin_post_haai_reset', array( $this, 'reset' ) );
		add_action( 'admin_post_haai_restore', array( $this, 'restore' ) );
		add_action( 'wp_ajax_haai_test_connection', array( $this, 'ajax_test_connection' ) );
		add_action( 'wp_ajax_haai_fetch_models', array( $this, 'ajax_fetch_models' ) );
		add_action( 'wp_ajax_haai_search_preview', array( $this, 'ajax_search_preview' ) );
		add_action( 'wp_ajax_haai_page_search', array( $this, 'ajax_page_search' ) );
		add_action( 'wp_ajax_nopriv_haai_search', array( $this, 'ajax_search' ) );
		add_action( 'wp_ajax_haai_search', array( $this, 'ajax_search' ) );
		add_action( 'admin_notices', array( $this, 'notice' ) );
		add_filter( 'admin_body_class', array( $this, 'theme_body_class' ) );
	}

	/**
	 * The appearance theme mode as a body class, on this plugin's screens only.
	 *
	 * A body class instead of an attribute on every page wrapper: the module
	 * screens are eight separate renderers, and none of them has to be touched
	 * for dark mode to reach them.
	 *
	 * @param string $classes Space-separated classes from wp-admin.
	 * @return string
	 */
	public function theme_body_class( $classes ) {
		$screen = get_current_screen();
		if ( ! $screen || false === strpos( (string) $screen->id, 'haai' ) ) {
			return $classes;
		}

		$mode = (string) Haa_Settings::get( 'appearance.theme_mode', 'light' );
		if ( 'dark' === $mode ) {
			$classes .= ' haai-theme-dark';
		} elseif ( 'auto' === $mode ) {
			// CSS decides with prefers-color-scheme; nothing live to compute here.
			$classes .= ' haai-theme-auto';
		}
		return $classes;
	}

	/** @return void */
	public function register_menu() {
		add_menu_page(
			__( 'دستیار هوشمند', 'hami-ai-assistant' ),
			__( 'دستیار هوشمند', 'hami-ai-assistant' ),
			'manage_options',
			self::PAGE,
			array( $this, 'render_page' ),
			'dashicons-format-chat',
			58
		);
		add_submenu_page(
			self::PAGE,
			__( 'داشبورد', 'hami-ai-assistant' ),
			__( 'داشبورد', 'hami-ai-assistant' ),
			'manage_options',
			Haa_Util::screen( 'dashboard' ),
			array( $this, 'render_dashboard' )
		);
		add_submenu_page(
			self::PAGE,
			__( 'ماژول‌ها', 'hami-ai-assistant' ),
			__( 'ماژول‌ها', 'hami-ai-assistant' ),
			'manage_options',
			Haa_Util::screen( 'modules' ),
			array( $this, 'render_modules' )
		);

		/**
		 * Modules add their own top-level screens (Leads, Articles, Coupons…)
		 * here so a disabled module never registers a menu slug.
		 */
		do_action( 'haai_admin_menus', self::PAGE );
	}

	/**
	 * Settings + preview + dashboard assets. Only the pages that need them.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function assets( $hook ) {
		$screen = get_current_screen();
		if ( ! $screen || false === strpos( (string) $screen->id, 'haai' ) ) {
			return;
		}

		wp_enqueue_media();

		wp_enqueue_style( 'haai-admin', HAAI_URL . haa_asset_path( 'assets/css/admin.css' ), array(), HAAI_VERSION, 'all' );
		wp_enqueue_style( 'haai-chat', HAAI_URL . haa_asset_path( 'assets/css/chatbot.css' ), array(), HAAI_VERSION, 'all' );
		wp_add_inline_style( 'haai-chat', haa_inline_css( Haa_Settings::get() ) );

		wp_enqueue_script( 'haai-admin', HAAI_URL . haa_asset_path( 'assets/js/admin.js' ), array(), HAAI_VERSION, true );
		wp_localize_script(
			'haai-admin',
			'haaiAdmin',
			array(
				'ajax'   => admin_url( 'admin-ajax.php' ),
				'nonce'  => wp_create_nonce( 'haai_admin' ),
				'i18n'   => array(
					'copied'  => __( 'کپی شد', 'hami-ai-assistant' ),
					'confirm' => __( 'مطمئنید؟ این کار برگشت‌پذیر نیست.', 'hami-ai-assistant' ),
					'busy'    => __( 'در حال انجام…', 'hami-ai-assistant' ),
					'ok'      => __( 'انجام شد', 'hami-ai-assistant' ),
					'fail'    => __( 'ناموفق', 'hami-ai-assistant' ),
				),
			)
		);
	}

	/* ------------------------------------------------------------------
	 * Save / export / import / reset
	 * ---------------------------------------------------------------- */

	/** @return void */
	public function save() {
		Haa_Util::guard_admin_post();

		$section = isset( $_POST['haai_section'] ) ? sanitize_key( wp_unslash( $_POST['haai_section'] ) ) : '';
		$allowed = array_keys( $this->sections() );
		if ( ! $section || ! in_array( $section, $allowed, true ) ) {
			wp_die( __( 'بخش نامعتبر است.', 'hami-ai-assistant' ) );
		}

		// The form posts nested keys (section[key]). A tab that renders no
		// fields posts nothing at all — treat that as "no changes" instead of
		// silently wiping the section back to its defaults.
		if ( ! isset( $_POST[ $section ] ) || ! is_array( $_POST[ $section ] ) ) {
			$this->redirect( $section );
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$raw = wp_unslash( $_POST[ $section ] );

		Haa_Settings::save_section( $section, $raw );

		// Turning a module on/off has to take effect immediately, so re-boot.
		if ( 'modules' === $section ) {
			Haa_Modules::instance()->register_enabled();
			Haa_DB::ensure();
		}

		$this->status = 'saved';
		$this->redirect( $section );
	}

	/** @return void */
	public function export() {
		Haa_Util::guard_admin_post();
		$payload = Haa_Settings::export();

		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=hami-ai-assistant-' . gmdate( 'Y-m-d' ) . '.json' );
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo $payload;
		exit;
	}

	/**
	 * Step 1 of an import: read, validate and summarise — change nothing.
	 *
	 * The file is size-capped, must be JSON, and every key that is not in the
	 * sanitize map is dropped. What survives is kept for a few minutes in a
	 * transient and shown as a change summary, so the admin confirms the actual
	 * effect instead of a filename.
	 *
	 * @return void
	 */
	public function import_preview() {
		Haa_Util::guard_admin_post();

		if ( empty( $_FILES['haai_import_file']['tmp_name'] ) ) {
			$this->status = 'import-empty';
			$this->redirect( 'advanced' );
		}

		$file = $_FILES['haai_import_file']['tmp_name']; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$err_code = isset( $_FILES['haai_import_file']['error'] ) ? (int) $_FILES['haai_import_file']['error'] : UPLOAD_ERR_OK;
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$size = isset( $_FILES['haai_import_file']['size'] ) ? (int) $_FILES['haai_import_file']['size'] : 0;

		if ( UPLOAD_ERR_OK !== $err_code ) {
			$this->status = 'import-upload';
			$this->redirect( 'advanced' );
		}
		if ( $size < 1 || $size > Haa_Settings::IMPORT_MAX_BYTES || ! is_readable( $file ) ) {
			$this->status = 'import-too-big';
			$this->redirect( 'advanced' );
		}

		$json = file_get_contents( $file );
		if ( ! is_string( $json ) || '' === trim( $json ) ) {
			$this->status = 'import-failed';
			$this->redirect( 'advanced' );
		}

		$decoded = json_decode( $json, true );
		$checked = Haa_Settings::validate_import( $decoded );
		if ( empty( $checked['ok'] ) ) {
			$this->status = 'import-failed';
			$this->redirect( 'advanced' );
		}

		$token = wp_generate_password( 24, false, false );
		set_transient(
			self::PENDING_OPTION,
			array(
				'token'       => $token,
				'json'        => wp_json_encode(
					array(
						'settings' => $checked['settings'],
						'modules'  => $checked['modules'],
					)
				),
				'has_secrets' => ! empty( $checked['has_secrets'] ),
				'summary'     => array(
					'keys'     => (int) $checked['keys'],
					'changed'  => (int) $checked['changed'],
					'sections' => $checked['sections'],
					'modules'  => array_keys( $checked['modules'] ),
				),
			),
			self::PENDING_TTL
		);

		$this->status = 'import-preview';
		$this->redirect( 'advanced', array( 'haai_token' => $token ) );
	}

	/**
	 * Step 2 of an import: apply the exact payload that was just shown.
	 *
	 * The pending copy is single-use, is matched against the token the summary
	 * handed out, and is re-validated before anything is written. Secrets in
	 * the file are applied only when the admin ticked the explicit box.
	 *
	 * @return void
	 */
	public function import_confirm() {
		Haa_Util::guard_admin_post();

		$pending = get_transient( self::PENDING_OPTION );
		$token   = isset( $_POST['haai_token'] ) ? sanitize_text_field( wp_unslash( $_POST['haai_token'] ) ) : '';

		if ( ! is_array( $pending ) || empty( $pending['json'] ) || '' === $token
			|| ! hash_equals( (string) $pending['token'], $token ) ) {
			delete_transient( self::PENDING_OPTION );
			$this->status = 'import-stale';
			$this->redirect( 'advanced' );
		}

		// One-shot: whatever happens next, the summary cannot be replayed.
		delete_transient( self::PENDING_OPTION );

		$allow_keys = ! empty( $_POST['allow_keys'] );
		if ( ! empty( $pending['has_secrets'] ) && ! $allow_keys ) {
			$this->status = 'import-need-keys';
			$this->redirect( 'advanced' );
		}

		$res = Haa_Settings::import( (string) $pending['json'], array( 'allow_secrets' => $allow_keys ) );
		if ( is_wp_error( $res ) ) {
			$this->status = 'import-failed';
			$this->redirect( 'advanced' );
		}

		Haa_Modules::instance()->register_enabled();
		Haa_DB::ensure();
		Haa_Plugin::ensure_cron();

		$this->status = 'imported';
		$this->redirect( 'advanced' );
	}

	/**
	 * Back to defaults — only after the admin types the confirmation word.
	 *
	 * The check runs on the server; the JavaScript that enables the button is
	 * convenience, never the gate. Haa_Settings::reset() snapshots first, so
	 * the advanced tab can undo it.
	 *
	 * @return void
	 */
	public function reset() {
		Haa_Util::guard_admin_post();

		$typed = isset( $_POST['confirm_word'] ) ? trim( (string) wp_unslash( $_POST['confirm_word'] ) ) : '';
		if ( Haa_Settings::RESET_WORD !== $typed ) {
			$this->status = 'reset-unconfirmed';
			$this->redirect( 'advanced' );
		}

		Haa_Settings::reset();
		Haa_Modules::instance()->register_enabled();
		Haa_DB::ensure();

		$this->status = 'reset';
		$this->redirect( 'advanced' );
	}

	/**
	 * Bring back the settings copy taken just before the last reset/import.
	 *
	 * Reachable only from the advanced tab, behind the same nonce + capability
	 * as every other settings write, and only while a snapshot exists.
	 *
	 * @return void
	 */
	public function restore() {
		Haa_Util::guard_admin_post();

		if ( ! Haa_Settings::has_snapshot() ) {
			$this->status = 'restore-none';
			$this->redirect( 'advanced' );
		}

		$ok = Haa_Settings::restore_snapshot();
		Haa_Modules::instance()->register_enabled();
		Haa_DB::ensure();

		$this->status = $ok ? 'restored' : 'restore-none';
		$this->redirect( 'advanced' );
	}

	/**
	 * @param string $section Tab to return to.
	 * @param array  $extra   Additional query args (import token).
	 */
	private function redirect( $section, $extra = array() ) {
		$url = add_query_arg(
			array_merge(
				array(
					'page'    => self::PAGE,
					'tab'     => $section,
					'haai_ok' => rawurlencode( (string) $this->status ),
				),
				$extra
			),
			admin_url( 'admin.php' )
		);
		wp_safe_redirect( $url );
		exit;
	}

	/** @return void */
	public function notice() {
		$ok  = isset( $_GET['haai_ok'] ) ? sanitize_key( wp_unslash( $_GET['haai_ok'] ) ) : '';
		$map = array(
			'saved'            => array( 'success', __( 'ذخیره شد.', 'hami-ai-assistant' ) ),
			'import-preview'   => array( 'info', __( 'فایل بررسی شد. تغییرات را ببینید و سپس تأیید کنید؛ هنوز چیزی اعمال نشده است.', 'hami-ai-assistant' ) ),
			'imported'         => array( 'success', __( 'تنظیمات فایل اعمال شد؛ اگر چیزی را به‌هم‌ریخته می‌بینید، با «بازگردانی نسخه‌ی پشتیبان» در همین تب به حالت قبل برگردید.', 'hami-ai-assistant' ) ),
			'import-failed'    => array( 'error', __( 'فایل JSON معتبر نبود یا هیچ کلید شناخته‌شده‌ای نداشت. چیزی تغییر نکرد.', 'hami-ai-assistant' ) ),
			'import-empty'     => array( 'error', __( 'هیچ فایلی انتخاب نشده است.', 'hami-ai-assistant' ) ),
			'import-upload'    => array( 'error', __( 'آپلود فایل ناموفق بود. دوباره تلاش کنید.', 'hami-ai-assistant' ) ),
			'import-too-big'   => array( 'error', sprintf( /* translators: %s: size cap. */ __( 'فایل بزرگ‌تر از سقف مجاز (%s) است یا خالی است.', 'hami-ai-assistant'), size_format( Haa_Settings::IMPORT_MAX_BYTES ) ) ),
			'import-stale'     => array( 'error', __( 'مهلت تأیید این فایل تمام شده یا نشانه‌ی آن معتبر نیست. لطفاً دوباره فایل را انتخاب کنید.', 'hami-ai-assistant' ) ),
			'import-need-keys' => array( 'error', __( 'فایل حاوی کلید API یا رمز پراکسی است. برای جایگزینی آن‌ها تیک «جایگزینی کلیدها» را بزنید؛ بدون آن تأیید، کلیدهای فعلی دست‌نخورده می‌مانند.', 'hami-ai-assistant' ) ),
			'reset'            => array( 'success', __( 'تنظیمات به حالت پیش‌فرض برگشت. با «بازگردانی نسخه‌ی پشتیبان» در همین تب می‌توانید همه‌چیز را به حالت قبل برگردانید.', 'hami-ai-assistant' ) ),
			'reset-unconfirmed'=> array( 'error', __( 'برای بازنشانی باید کلمه‌ی تأیید را دقیقاً تایپ کنید. چیزی تغییر نکرد.', 'hami-ai-assistant' ) ),
			'restored'         => array( 'success', __( 'تنظیمات از نسخه‌ی پشتیبان قبل از آخرین بازنشانی/دریافت بازگردانده شد.', 'hami-ai-assistant' ) ),
			'restore-none'     => array( 'error', __( 'نسخه‌ی پشتیبانی برای بازگردانی وجود ندارد.', 'hami-ai-assistant' ) ),
			'module-saved'     => array( 'success', __( 'ماژول‌ها به‌روزرسانی شد.', 'hami-ai-assistant' ) ),
		);
		if ( ! isset( $map[ $ok ] ) ) {
			return;
		}
		printf(
			'<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
			esc_attr( $map[ $ok ][0] ),
			esc_html( $map[ $ok ][1] )
		);
	}

	/* ------------------------------------------------------------------
	 * AJAX
	 * ---------------------------------------------------------------- */

	/** @return void */
	public function ajax_test_connection() {
		Haa_Util::guard_admin_ajax();

		$posted = isset( $_POST['provider'] ) && is_array( $_POST['provider'] ) ? wp_unslash( $_POST['provider'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$cfg    = Haa_Settings::provider_from_post( $posted );

		$result = ( new Haa_API( $cfg ) )->test_connection();
		if ( empty( $result['ok'] ) ) {
			// admin.js renders `error`; the probe returns `message`.
			$result['error'] = $result['message'];
		}
		wp_send_json( $result );
	}

	/** @return void */
	public function ajax_fetch_models() {
		Haa_Util::guard_admin_ajax();

		$posted = isset( $_POST['provider'] ) && is_array( $_POST['provider'] ) ? wp_unslash( $_POST['provider'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$cfg    = Haa_Settings::provider_from_post( $posted );

		$models = ( new Haa_API( $cfg ) )->models();
		if ( is_wp_error( $models ) ) {
			wp_send_json( array( 'ok' => false, 'error' => $models->get_error_message() ) );
		}
		if ( ! $models ) {
			wp_send_json( array( 'ok' => false, 'error' => __( 'سرویس فهرست مدلی برگرداند که خوانده نشد — مدل را دستی وارد کنید.', 'hami-ai-assistant' ) ) );
		}
		wp_send_json( array( 'ok' => true, 'models' => $models ) );
	}

	/**
	 * Page lookup for the visibility pickers: 20 rows, published or private
	 * only, restricted to the post types a visitor can actually see.
	 *
	 * Nonce + manage_options are enforced by guard_admin_ajax(), same as the
	 * other admin AJAX calls.
	 *
	 * @return void
	 */
	public function ajax_page_search() {
		Haa_Util::guard_admin_ajax();

		$q     = isset( $_REQUEST['q'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['q'] ) ) : '';
		$q     = trim( $q );
		$types = array_keys( $this->public_post_types() );
		if ( ! $types ) {
			$types = array( 'page', 'post' );
		}

		$args = array(
			'post_type'      => $types,
			'post_status'    => array( 'publish', 'private' ),
			'posts_per_page' => 20,
			'orderby'        => 'title',
			'order'          => 'ASC',
			'no_found_rows'  => true,
		);

		if ( '' !== $q ) {
			if ( ctype_digit( $q ) ) {
				$args['p'] = (int) $q;
			} else {
				$args['s'] = $q;
			}
		}

		$results = array();
		foreach ( get_posts( $args ) as $p ) {
			$obj = get_post_type_object( $p->post_type );
			$type = $obj && isset( $obj->labels->singular_name ) ? $obj->labels->singular_name : $p->post_type;
			$title = '' !== $p->post_title ? $p->post_title : __( '(بدون عنوان)', 'hami-ai-assistant' );

			// Show where the page sits, so a child page is not picked blind.
			if ( $p->post_parent ) {
				$parent = get_the_title( (int) $p->post_parent );
				if ( '' !== $parent ) {
					$title .= ' ← ' . $parent;
				}
			}

			$results[] = array(
				'id'    => (int) $p->ID,
				'title' => $title,
				'type'  => $type,
			);
		}

		wp_send_json( array( 'ok' => true, 'results' => $results ) );
	}

	/** @return void */
	public function ajax_search_preview() {
		Haa_Util::guard_admin_ajax();

		$q = isset( $_POST['q'] ) ? sanitize_text_field( wp_unslash( $_POST['q'] ) ) : '';
		wp_send_json(
			array(
				'ok'    => true,
				'query' => $q,
				'hits'  => Haa_Search::search( $q, 5, 1200 ),
			)
		);
	}

	/**
	 * Public search endpoint used by the [haai_search] form and the
	 * semantic-search module. Runs in admin-ajax so it works on cached pages.
	 *
	 * Protections applied here, in order:
	 *  1. WP nonce for logged-in callers (their response is never cached).
	 *  2. Light nonce for guests: the same short-lived chat token minted by
	 *     /token, verified against its IP+UA binding. Optional because the
	 *     form itself lives in page-cached HTML where a per-visitor value
	 *     cannot be embedded — a caller that CAN mint one is verified, one
	 *     that cannot still passes the visibility gate and the rate limit.
	 *  3. Visibility gate (should_display) for guests, unchanged.
	 *  4. Module must be enabled.
	 *  5. Query length cap (self::SEARCH_MAX_QUERY).
	 *  6. Result cap, hard-clamped server-side (self::SEARCH_MAX_LIMIT).
	 *  7. Separate rate-limit bucket: 60 searches/minute per real identity
	 *     (IP or logged-in user), independent from the chat bucket.
	 *  8. Published + public content only, enforced inside Haa_Search itself.
	 *
	 * @return void
	 */
	public function ajax_search() {
		if ( is_user_logged_in() ) {
			check_ajax_referer( 'haai_search', 'nonce' );
		} else {
			if ( ! Haa_Plugin::should_display( Haa_Settings::get() ) ) {
				wp_send_json( array( 'ok' => false, 'error' => __( 'جستجو در دسترس نیست.', 'hami-ai-assistant' ) ), 403 );
			}
			// The widget token doubles as a light nonce for guests. A page that
			// has no widget (plain [haai_search] in a post) sends none, so the
			// request still works — the module gate, the length/result caps and
			// the dedicated search throttle below are what bound it.
			$token = isset( $_REQUEST['token'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['token'] ) ) : '';
			if ( '' !== $token && ! Haa_REST::token_is_valid( $token ) ) {
				wp_send_json( array( 'ok' => false, 'error' => __( 'درخواست نامعتبر است. صفحه را تازه‌سازی کنید.', 'hami-ai-assistant' ) ), 403 );
			}
		}

		if ( ! Haa_Modules::is_enabled( 'semantic-search' ) ) {
			wp_send_json( array( 'ok' => false, 'error' => __( 'جستجوی هوشمند غیرفعال است.', 'hami-ai-assistant' ) ), 403 );
		}

		$q = isset( $_REQUEST['q'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['q'] ) ) : '';

		// Length cap: a single request can never ask us to tokenise a wall of text.
		if ( mb_strlen( $q ) > self::SEARCH_MAX_QUERY ) {
			wp_send_json(
				array(
					'ok'    => false,
					/* translators: %d: maximum query length in characters. */
					'error' => sprintf( __( 'عبارت جستجو باید حداکثر %d کاراکتر باشد.', 'hami-ai-assistant' ), self::SEARCH_MAX_QUERY ),
				),
				400
			);
		}

		// Result cap: hard ceiling regardless of what the caller sent.
		$limit = isset( $_REQUEST['limit'] ) ? absint( $_REQUEST['limit'] ) : 5;
		$limit = max( 1, min( self::SEARCH_MAX_LIMIT, $limit ) );

		if ( '' === $q ) {
			wp_send_json( array( 'ok' => true, 'hits' => array() ) );
		}

		// Separate throttle: 60 searches/minute per visitor, its own bucket
		// so a search flood cannot spend the chat budget and vice versa.
		$bucket = (int) floor( time() / 60 );
		$hits   = Haa_DB::bump_rate( 'search:' . Haa_REST::identifier(), $bucket );
		if ( $hits > self::SEARCH_RATE_PER_MINUTE ) {
			wp_send_json( array( 'ok' => false, 'error' => __( 'کمی بعد دوباره تلاش کنید.', 'hami-ai-assistant' ) ), 429 );
		}

		wp_send_json(
			array(
				'ok'   => true,
				'hits' => Haa_Search::search( $q, $limit, 1200 ),
			)
		);
	}

	/* ------------------------------------------------------------------
	 * Screens
	 * ---------------------------------------------------------------- */

	/**
	 * The chrome every plugin screen shares: icon, screen title, and one meta
	 * line with the version and where this install is pointed.
	 *
	 * It reports the key as "set / missing" from local settings on purpose. A
	 * real probe would put an HTTP request on every admin page view, and the
	 * provider tab already has a button for that.
	 *
	 * @param string $title Screen title.
	 * @return void
	 */
	public static function page_header( $title ) {
		$provider  = Haa_Settings::provider();
		$has_key   = '' !== (string) $provider['api_key'];
		$presets   = Haa_API::presets();
		$slug      = (string) $provider['provider'];
		$srv_label = isset( $presets[ $slug ] ) ? $presets[ $slug ]['label'] : $slug;
		$model     = (string) $provider['model'];
		?>
		<header class="haai-head">
			<span class="haai-head__icon" aria-hidden="true">
				<svg viewBox="0 0 24 24" width="20" height="20"><path d="M12 3C6.5 3 2 6.6 2 11c0 2.2 1.2 4.2 3.1 5.6L4 21l4.9-2.6c1 .3 2 .4 3.1.4 5.5 0 10-3.6 10-8s-4.5-8-10-8zm-4 9.5a1.4 1.4 0 1 1 0-2.8 1.4 1.4 0 0 1 0 2.8zm4 0a1.4 1.4 0 1 1 0-2.8 1.4 1.4 0 0 1 0 2.8zm4 0a1.4 1.4 0 1 1 0-2.8 1.4 1.4 0 0 1 0 2.8z" fill="currentColor"/></svg>
			</span>
			<h1 class="haai-head__title"><?php echo esc_html( $title ); ?></h1>
			<p class="haai-head__meta">
				<span class="haai-badge <?php echo $has_key ? 'haai-badge--on' : 'haai-badge--warn'; ?>">
					<span class="haai-dot <?php echo $has_key ? 'haai-dot--ok' : 'haai-dot--warn'; ?>" aria-hidden="true"></span>
					<?php
					echo $has_key
						? esc_html__( 'کلید API ثبت شده', 'hami-ai-assistant' )
						: esc_html__( 'بدون کلید API', 'hami-ai-assistant' );
					?>
				</span>
				<span class="haai-badge"><?php echo esc_html( $srv_label ); ?></span>
				<?php if ( '' !== $model ) : ?>
					<span class="haai-badge haai-badge--model" title="<?php echo esc_attr( $model ); ?>"><?php echo esc_html( $model ); ?></span>
				<?php endif; ?>
				<span class="haai-head__version"><?php echo esc_html( HAAI_VERSION ); ?></span>
			</p>
		</header>
		<?php
	}

	/**
	 * Small inline SVG for a module: one stroke language, 24 grid, currentColor.
	 *
	 * Nothing is fetched and no icon font ships, so a disabled module costs the
	 * page nothing — the map is only read for modules that render.
	 *
	 * @param string $slug Module slug.
	 * @return string SVG markup.
	 */
	public static function module_icon( $slug ) {
		$shapes = array(
			'chatbot'         => '<path d="M4 5h16v11H9l-5 4z"/>',
			'product-content' => '<path d="M12 3H5a2 2 0 0 0-2 2v7l10 10 9-9-10-10z"/><circle cx="8" cy="8" r="1.4"/>',
			'article-writer'  => '<path d="M7 3h7l4 4v14H7z"/><path d="M10 10h6M10 14h6"/>',
			'autopilot'       => '<path d="M12 4l1.9 4.1L18 10l-4.1 1.9L12 16l-1.9-4.1L6 10l4.1-1.9z"/><path d="M18 15l.8 1.9L20.7 18l-1.9.8L18 20.7l-.8-1.9L15.3 18l1.9-.8z"/>',
			'leads'           => '<circle cx="12" cy="8" r="4"/><path d="M4 20a8 8 0 0 1 16 0"/>',
			'discount'        => '<path d="M6 18 18 6"/><circle cx="8" cy="8" r="2"/><circle cx="16" cy="16" r="2"/>',
			'comments'        => '<path d="M4 5h16v11H4z"/><path d="M8 9h8M8 12h5"/>',
			'semantic-search' => '<circle cx="11" cy="11" r="6"/><path d="M20 20l-4.6-4.6"/>',
		);
		$inner = isset( $shapes[ $slug ] ) ? $shapes[ $slug ] : '<circle cx="12" cy="12" r="7"/>';
		return '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $inner . '</svg>';
	}

	/** @return void */
	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$sections = $this->sections();
		$tab      = $this->current_tab( $sections );
		?>
		<div class="wrap haai-wrap">
			<?php self::page_header( __( 'دستیار هوشمند', 'hami-ai-assistant' ) ); ?>

			<?php
			/**
			 * Modules can pin a banner (missing API key, WooCommerce off…).
			 */
			do_action( 'haai_admin_banners', $tab );

			$settings = Haa_Settings::get();
			?>
			<nav class="nav-tab-wrapper">
				<?php foreach ( $sections as $slug => $label ) : ?>
					<a class="nav-tab <?php echo $slug === $tab ? 'nav-tab-active' : ''; ?>"
						href="<?php echo esc_url( add_query_arg( array( 'page' => self::PAGE, 'tab' => $slug ), admin_url( 'admin.php' ) ) ); ?>">
						<?php echo esc_html( $label ); ?>
					</a>
				<?php endforeach; ?>
			</nav>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'haai_admin' ); ?>
				<input type="hidden" name="action" value="haai_save">
				<input type="hidden" name="haai_section" value="<?php echo esc_attr( $tab ); ?>">

				<div class="haai-panel">
					<?php
					// Each tab declares its fields; empty tabs fall back to a hint.
					$fields = array();
					$fields = apply_filters( 'haai_settings_fields_' . $tab, $fields );

					if ( empty( $fields ) ) {
						// Core tabs ship their fields inline so the module contract
						// stays optional for the sections every install needs.
						$this->render_core_fields( $tab, $settings );
					} else {
						// Module tabs may label their groups the same way core tabs do.
						$this->render_grouped_fields( $fields, $settings, $this->field_groups( $tab ) );
					}
					?>
				</div>

				<?php if ( 'modules' !== $tab ) : ?>
					<p class="haai-savebar">
						<button type="submit" class="button button-primary"><?php esc_html_e( 'ذخیره', 'hami-ai-assistant' ); ?></button>
						<span class="haai-savebar__hint"><?php esc_html_e( 'تغییرات پس از ذخیره فوراً اعمال می‌شوند.', 'hami-ai-assistant' ); ?></span>
					</p>
				<?php endif; ?>
			</form>

			<?php $this->render_settings_tools( $tab ); ?>
			<?php $this->render_restore_panel( $tab ); ?>
		</div>
		<?php
	}

	/**
	 * Advanced tab only: export, import, reset and the undo of the last one.
	 *
	 * Each action is its own POST form carrying the plugin nonce and the
	 * manage_options capability, and none of them nests inside the settings
	 * form above. Import is deliberately two steps: upload → summary → confirm,
	 * so nothing is written before the admin has seen what would change.
	 *
	 * @param string $tab Current tab slug.
	 * @return void
	 */
	private function render_settings_tools( $tab ) {
		if ( 'advanced' !== $tab ) {
			return;
		}

		$pending = get_transient( self::PENDING_OPTION );
		$token   = isset( $_GET['haai_token'] ) ? sanitize_text_field( wp_unslash( $_GET['haai_token'] ) ) : '';
		$show    = is_array( $pending ) && '' !== $token && hash_equals( (string) $pending['token'], $token )
			? $pending : null;
		$word  = Haa_Settings::RESET_WORD;
		?>
		<div class="haai-panel haai-panel--tools">
			<h2><?php esc_html_e( 'پشتیبان‌گیری و بازنشانی تنظیمات', 'hami-ai-assistant' ); ?></h2>
			<p class="haai-field__hint">
				<?php esc_html_e( 'این سه کار فقط روی تنظیمات افزونه است؛ محتوا و گفتگوها دست‌نخورده می‌مانند. «بازنشانی» و «دریافت» پیش از تغییر یک نسخه‌ی پشتیبان می‌گیرند و در همین تب قابل بازگردانی است.', 'hami-ai-assistant' ); ?>
			</p>

			<div class="haai-actions">
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="haai-form">
					<?php wp_nonce_field( 'haai_admin' ); ?>
					<input type="hidden" name="action" value="haai_export">
					<button type="submit" class="button button-secondary"><?php esc_html_e( 'دریافت فایل تنظیمات (JSON)', 'hami-ai-assistant' ); ?></button>
					<span class="haai-field__hint"><?php esc_html_e( 'بدون کلید API و رمز پراکسی ساخته می‌شود.', 'hami-ai-assistant' ); ?></span>
				</form>
			</div>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="haai-form" enctype="multipart/form-data">
				<?php wp_nonce_field( 'haai_admin' ); ?>
				<input type="hidden" name="action" value="haai_import">
				<div class="haai-field">
					<label class="haai-field__label" for="haai-import-file"><?php esc_html_e( 'فایل تنظیمات برای دریافت', 'hami-ai-assistant' ); ?></label>
					<div class="haai-field__control">
						<input type="file" id="haai-import-file" name="haai_import_file" accept=".json,application/json" />
						<p class="haai-field__hint"><?php
							printf(
								/* translators: %s: size cap. */
								esc_html__( 'حداکثر اندازه: %s. تنها کلیدهای شناخته‌شده پذیرفته می‌شوند؛ کلید API فایل جای کلید فعلی را نمی‌گیرد مگر آنکه صریحاً تأیید کنید.', 'hami-ai-assistant' ),
								esc_html( size_format( Haa_Settings::IMPORT_MAX_BYTES ) )
							);
						?></p>
					</div>
				</div>
				<p class="haai-actions">
					<button type="submit" class="button button-secondary"><?php esc_html_e( 'بررسی و نمایش تغییرات', 'hami-ai-assistant' ); ?></button>
				</p>
			</form>

			<?php if ( $show ) : ?>
				<?php
				$sum = isset( $show['summary'] ) && is_array( $show['summary'] ) ? $show['summary'] : array();
				$keys     = isset( $sum['keys'] ) ? (int) $sum['keys'] : 0;
				$changed  = isset( $sum['changed'] ) ? (int) $sum['changed'] : 0;
				$sections = isset( $sum['sections'] ) && is_array( $sum['sections'] ) ? $sum['sections'] : array();
				$mods     = isset( $sum['modules'] ) && is_array( $sum['modules'] ) ? $sum['modules'] : array();
				$secrets  = ! empty( $show['has_secrets'] );
				$labels   = $this->sections();
				?>
				<div class="haai-panel haai-panel--inner">
					<h3><?php esc_html_e( 'تأیید دریافت', 'hami-ai-assistant' ); ?></h3>
					<p class="haai-field__hint"><?php
						printf(
							/* translators: 1: valid keys, 2: differing values. */
							esc_html__( 'فایل %1$d کلید معتبر دارد که %2$d تای آن با مقدار فعلی فرق می‌کند.', 'hami-ai-assistant' ),
							$keys,
							$changed
						);
					?></p>
					<?php if ( $sections ) : ?>
						<p class="haai-field__hint"><?php
							$parts = array();
							foreach ( $sections as $sec => $n ) {
								$parts[] = ( isset( $labels[ $sec ] ) ? $labels[ $sec ] : $sec ) . ' (' . (int) $n . ')';
							}
							echo esc_html( __( 'بخش‌ها: ', 'hami-ai-assistant' ) . implode( '، ', $parts ) );
						?></p>
					<?php endif; ?>
					<?php if ( $mods ) : ?>
						<p class="haai-field__hint"><?php echo esc_html( __( 'ماژول‌های روشن پس از دریافت: ', 'hami-ai-assistant' ) . implode( '، ', $mods ) ); ?></p>
					<?php endif; ?>
					<?php if ( $secrets ) : ?>
						<p class="haai-field__hint"><?php esc_html_e( 'هشدار: این فایل کلید API یا رمز پراکسی دارد. بدون تیک پایین، کلیدهای فعلی این سایت دست‌نخورده می‌مانند.', 'hami-ai-assistant' ); ?></p>
					<?php endif; ?>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="haai-form" data-haai-confirm>
						<?php wp_nonce_field( 'haai_admin' ); ?>
						<input type="hidden" name="action" value="haai_import_confirm">
						<input type="hidden" name="haai_token" value="<?php echo esc_attr( $token ); ?>">
						<?php if ( $secrets ) : ?>
							<p class="haai-checkbox"><label><input type="checkbox" name="allow_keys" value="1"> <?php esc_html_e( 'کلید API و رمز پراکسی این فایل جایگزین فعلی‌ها شود', 'hami-ai-assistant' ); ?></label></p>
						<?php endif; ?>
						<p class="haai-actions">
							<button type="submit" class="button button-primary"><?php esc_html_e( 'تأیید و اعمال دریافت', 'hami-ai-assistant' ); ?></button>
						</p>
					</form>
				</div>
			<?php endif; ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="haai-form"
				data-haai-reset-word="<?php echo esc_attr( $word ); ?>" data-haai-confirm>
				<?php wp_nonce_field( 'haai_admin' ); ?>
				<input type="hidden" name="action" value="haai_reset">
				<div class="haai-field">
					<label class="haai-field__label" for="haai-reset-word"><?php esc_html_e( 'بازنشانی همه‌ی تنظیمات', 'hami-ai-assistant' ); ?></label>
					<div class="haai-field__control">
						<input type="text" id="haai-reset-word" name="confirm_word" class="regular-text" autocomplete="off"
							placeholder="<?php echo esc_attr( $word ); ?>">
						<p class="haai-field__hint"><?php
							printf(
								/* translators: %s: confirmation word. */
								esc_html__( 'همه‌ی تنظیمات به پیش‌فرض برمی‌گردد. برای اجرا، کلمه‌ی «%s» را دقیقاً تایپ کنید.', 'hami-ai-assistant' ),
								esc_html( $word )
							);
						?></p>
					</div>
				</div>
				<p class="haai-actions">
					<button type="submit" class="button button-link-delete" data-haai-reset-submit disabled><?php esc_html_e( 'بازنشانی تنظیمات', 'hami-ai-assistant' ); ?></button>
				</p>
			</form>
		</div>
		<?php
	}

	/**
	 * Advanced tab only: undo the last reset/import from the safety snapshot.
	 *
	 * The panel appears whenever a snapshot exists. Its own POST form carries
	 * the plugin nonce and the shared confirmation prompt, and never nests
	 * inside the settings form above.
	 *
	 * @param string $tab Current tab slug.
	 * @return void
	 */
	private function render_restore_panel( $tab ) {
		if ( 'advanced' !== $tab || ! Haa_Settings::has_snapshot() ) {
			return;
		}
		$label = Haa_Settings::snapshot_label();
		?>
		<div class="haai-panel haai-panel--restore">
			<h2><?php esc_html_e( 'بازگردانی تنظیمات', 'hami-ai-assistant' ); ?></h2>
			<p class="haai-field__hint">
				<?php esc_html_e( 'پیش از آخرین «بازنشانی» یا «دریافت تنظیمات»، یک نسخه‌ی پشتیبان نگه داشته شد. در صورت نیاز همان تنظیمات را برگردانید.', 'hami-ai-assistant' ); ?>
			</p>
			<?php if ( '' !== $label ) : ?>
				<p class="haai-field__hint"><?php echo esc_html( __( 'زمان نسخه‌ی پشتیبان: ', 'hami-ai-assistant' ) . $label ); ?></p>
			<?php endif; ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="haai-form" data-haai-confirm>
				<?php wp_nonce_field( 'haai_admin' ); ?>
				<input type="hidden" name="action" value="haai_restore">
				<button type="submit" class="button button-secondary"><?php esc_html_e( 'بازگردانی نسخه‌ی پشتیبان', 'hami-ai-assistant' ); ?></button>
			</form>
		</div>
		<?php
	}

	/** @return void */
	public function render_dashboard() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		require_once HAAI_PATH . 'includes/class-haa-admin-dashboard.php';
		Haa_Admin_Dashboard::render();
	}

	/** @return void */
	public function render_modules() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$this->load_modules_screen();
		Haa_Admin_Modules::render();
	}

	/**
	 * Module toggles save through admin-post.php, before any markup is sent,
	 * so the redirect after the toggle actually lands.
	 *
	 * @return void
	 */
	public function save_modules() {
		$this->load_modules_screen();
		Haa_Admin_Modules::save();
	}

	/**
	 * The Modules screen class is only loaded by the two entry points above —
	 * a chatbot-only install never pays for the third admin file.
	 *
	 * @return void
	 */
	private function load_modules_screen() {
		static $loaded = false;
		if ( ! $loaded ) {
			require_once HAAI_PATH . 'includes/class-haa-admin-modules.php';
			$loaded = true;
		}
	}

	/* ------------------------------------------------------------------
	 * Section list / tabs
	 * ---------------------------------------------------------------- */

	/**
	 * Core sections plus whatever enabled modules contribute.
	 *
	 * @return array<string,string> slug => label.
	 */
	public function sections() {
		$sections = array(
			'general'   => __( 'عمومی', 'hami-ai-assistant' ),
			'provider'  => __( 'سرویس AI', 'hami-ai-assistant' ),
			'modules'   => __( 'ماژول‌ها', 'hami-ai-assistant' ),
			'appearance'=> __( 'ظاهر', 'hami-ai-assistant' ),
			'advanced'  => __( 'پیشرفته', 'hami-ai-assistant' ),
		);

		/**
		 * Filters the settings tabs. Modules add their own here.
		 *
		 * @param array $sections slug => label.
		 */
		$sections = apply_filters( 'haai_settings_sections', $sections );

		return $sections;
	}

	/**
	 * @param array $sections Tab list.
	 * @return string
	 */
	private function current_tab( $sections ) {
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'general';
		return isset( $sections[ $tab ] ) ? $tab : key( $sections );
	}

	/* ------------------------------------------------------------------
	 * Field rendering
	 * ---------------------------------------------------------------- */

	/**
	 * @param array $field    Field definition.
	 * @param array $settings Full settings.
	 */
	private function render_field( $field, $settings ) {
		$key     = isset( $field['key'] ) ? $field['key'] : '';
		$label   = isset( $field['label'] ) ? $field['label'] : '';
		$desc    = isset( $field['hint'] ) ? $field['hint'] : '';
		$type    = isset( $field['type'] ) ? $field['type'] : 'text';
		// A field can carry its own value (e.g. the page rule resolved from
		// legacy data) instead of the raw setting.
		$value   = array_key_exists( 'value', $field ) ? $field['value'] : $this->v( $key, $settings );
		$choices = isset( $field['choices'] ) ? $field['choices'] : array();

		// Read-only rows are not a labelled input row at all.
		if ( 'summary' === $type ) {
			echo '<p class="description haai-summary">' . esc_html( Haa_Admin::display_rules_summary( $settings ) ) . '</p>';
			return;
		}

		$this->open_field( $key, $label, $desc, in_array( $type, array( 'multicheck', 'chipbox', 'media', 'pages', 'pagepicker' ), true ) );

		switch ( $type ) {
			case 'radios':
				$this->field_radios( $key, $value, $choices, isset( $field['show'] ) ? $field['show'] : '' );
				break;
			case 'chipbox':
				$this->field_chipbox( $key, (array) $value, $choices, isset( $field['show'] ) ? $field['show'] : '' );
				break;
			case 'secret':
				$this->field_secret( $key, $value );
				break;
			case 'number':
				$this->field_number( $key, $value, isset( $field['min'] ) ? $field['min'] : 0, isset( $field['max'] ) ? $field['max'] : 99999 );
				break;
			case 'float':
				$this->field_float( $key, $value, isset( $field['min'] ) ? $field['min'] : 0, isset( $field['max'] ) ? $field['max'] : 1, isset( $field['step'] ) ? $field['step'] : 0.1 );
				break;
			case 'checkbox':
				$this->field_checkbox( $key, $value );
				break;
			case 'select':
				$this->field_select( $key, $value, $choices );
				break;
			case 'textarea':
				$this->field_textarea( $key, $value );
				break;
			case 'color':
				$this->field_color( $key, $value );
				break;
			case 'media':
				$this->field_media( $key, $value );
				break;
			case 'pages':
				$this->field_pages( $key, $value );
				break;
			case 'pagepicker':
				$this->field_page_picker( $key, (array) $value, isset( $field['show'] ) ? $field['show'] : '' );
				break;
			case 'multicheck':
				$this->field_multicheck( $key, (array) $value, $choices, $label );
				break;
			case 'time':
				$this->field_time( $key, $value );
				break;
			default:
				$this->field_text( $key, $value );
				break;
		}

		$this->close_field();
	}

	/**
	 * Ordered field groups per core tab. Grouping is by the settings key, not
	 * by the order fields were declared in, so reordering the list above can
	 * never shuffle a field into the wrong card.
	 *
	 * A field that matches no group is still rendered (ungrouped, last) — the
	 * form cannot silently lose a setting.
	 *
	 * @param string $tab Current tab.
	 * @return array<string,array{label:string,fold?:bool,match:array<int,string>}>
	 */
	private function field_groups( $tab ) {
		if ( 'general' === $tab ) {
			return array(
				'widget' => array(
					'label' => __( 'نحوه‌ی نمایش', 'hami-ai-assistant' ),
					'match' => array( 'enabled', 'display_mode', 'floating_position', 'floating_bottom', 'floating_side' ),
				),
				'where'  => array(
					'label' => __( 'کجا نمایش داده شود', 'hami-ai-assistant' ),
					'match' => array( 'pages_mode', 'special_pages', 'show_on_pages', 'exclude_pages', 'show_on_post_types' ),
				),
				'who'    => array(
					'label' => __( 'به چه کسی', 'hami-ai-assistant' ),
					'match' => array( 'show_devices', 'show_roles' ),
				),
			);
		}

		if ( 'provider' === $tab ) {
			return array(
				'connect' => array(
					'label' => __( 'اتصال', 'hami-ai-assistant' ),
					'match' => array( 'provider', 'api_key', 'base_url', 'model' ),
				),
				'tuning'  => array(
					'label' => __( 'مدل و پارامترها', 'hami-ai-assistant' ),
					'match' => array( 'embedding_model', 'image_model', 'temperature', 'max_tokens', 'timeout', 'retries', 'adapter', 'allow_localhost' ),
				),
				'proxy'   => array(
					'label' => __( 'پراکسی', 'hami-ai-assistant' ),
					'fold'  => true,
					'match' => array( 'proxy_host', 'proxy_port', 'proxy_username', 'proxy_password' ),
				),
			);
		}

		if ( 'appearance' === $tab ) {
			return array(
				'colors' => array(
					'label' => __( 'رنگ‌ها', 'hami-ai-assistant' ),
					'match' => array( 'primary_color', 'secondary_color', 'bg_color', 'text_color', 'theme_mode' ),
				),
				'type'   => array(
					'label' => __( 'فونت و اندازه', 'hami-ai-assistant' ),
					'match' => array( 'font', 'font_size', 'border_radius', 'button_size' ),
				),
				'copy'   => array(
					'label' => __( 'متن‌ها و آیکون', 'hami-ai-assistant' ),
					'match' => array( 'welcome_message', 'placeholder', 'custom_icon' ),
				),
				'css'    => array(
					'label' => __( 'CSS سفارشی', 'hami-ai-assistant' ),
					'fold'  => true,
					'match' => array( 'custom_css' ),
				),
			);
		}

		if ( 'advanced' === $tab ) {
			return array(
				'kb'     => array(
					'label' => __( 'پایگاه دانش (RAG)', 'hami-ai-assistant' ),
					'match' => array( 'enable_rag', 'kb_enabled', 'kb_*' ),
				),
				'flow'   => array(
					'label' => __( 'کش و سقف نرخ', 'hami-ai-assistant' ),
					'match' => array( 'enable_cache', 'cache_ttl', 'rate_limit_*', 'token_ttl_minutes', 'max_message_chars' ),
				),
				'caps'   => array(
					'label' => __( 'سقف روزانه', 'hami-ai-assistant' ),
					'fold'  => true,
					'match' => array( 'daily_request_cap', 'daily_cap_message', 'daily_user_cap', 'daily_user_cap_message' ),
				),
				'logs'   => array(
					'label' => __( 'ثبت و نگهداری', 'hami-ai-assistant' ),
					'fold'  => true,
					'match' => array( 'log_conversations', 'log_retention_days', 'usage_retention' ),
				),
				'access' => array(
					'label' => __( 'دسترسی و IP', 'hami-ai-assistant' ),
					'match' => array( 'guest_access', 'login_required_message', 'ip_header' ),
				),
			);
		}

		// general + module tabs: one flat card, no sub-headings.
		return array();
	}

	/**
	 * @param string $local  Key without its section prefix.
	 * @param array  $pats   Group patterns, `*` allowed as a suffix.
	 * @return bool
	 */
	private static function key_matches( $local, $pats ) {
		foreach ( $pats as $pat ) {
			if ( $local === $pat ) {
				return true;
			}
			if ( '*' === substr( $pat, -1 ) && 0 === strpos( $local, rtrim( $pat, '*' ) ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Render a flat field list as labelled group cards.
	 *
	 * @param array $fields  Field definitions.
	 * @param array $settings Full settings.
	 * @param array $groups  field_groups() output.
	 * @return void
	 */
	private function render_grouped_fields( $fields, $settings, $groups ) {
		if ( ! $groups ) {
			foreach ( $fields as $field ) {
				$this->render_field( $field, $settings );
			}
			return;
		}

		$buckets = array();
		$extra   = array();
		foreach ( $fields as $field ) {
			// A field may name its group outright (read-only rows have no
			// settings key to match on).
			if ( isset( $field['group'], $groups[ $field['group'] ] ) ) {
				$buckets[ $field['group'] ][] = $field;
				continue;
			}

			$key   = isset( $field['key'] ) ? (string) $field['key'] : '';
			$dot   = strpos( $key, '.' );
			$local = false === $dot ? $key : substr( $key, $dot + 1 );
			$hit   = '';
			foreach ( $groups as $slug => $group ) {
				if ( self::key_matches( $local, (array) $group['match'] ) ) {
					$hit = $slug;
					break;
				}
			}
			if ( '' === $hit ) {
				$extra[] = $field;
			} else {
				$buckets[ $hit ][] = $field;
			}
		}

		foreach ( $groups as $slug => $group ) {
			if ( empty( $buckets[ $slug ] ) ) {
				continue;
			}
			$this->render_group( $group, $buckets[ $slug ], $settings );
		}

		foreach ( $extra as $field ) {
			$this->render_field( $field, $settings );
		}
	}

	/**
	 * One group: a titled card, or a collapsed card when 'fold' is set.
	 *
	 * Collapsed state uses <details>, not display:none — inputs inside a closed
	 * <details> stay in the form and are still submitted, so folding a group
	 * can never wipe those settings.
	 *
	 * @param array $group   Group definition.
	 * @param array $fields  Fields in this group.
	 * @param array $settings Full settings.
	 * @return void
	 */
	private function render_group( $group, $fields, $settings ) {
		$title = '<h2 class="haai-group__title">' . esc_html( $group['label'] ) . '</h2>';

		if ( ! empty( $group['fold'] ) ) {
			echo '<details class="haai-group haai-fold">';
			echo '<summary class="haai-group__title">' . esc_html( $group['label'] ) . '</summary>';
		} else {
			echo '<section class="haai-group">';
			echo $title;
		}

		foreach ( $fields as $field ) {
			$this->render_field( $field, $settings );
		}

		echo ! empty( $group['fold'] ) ? '</details>' : '</section>';
	}

	/**
	 * Core tabs that do not belong to a module.
	 *
	 * @param string $tab      Current tab.
	 * @param array  $settings Settings.
	 */
	private function render_core_fields( $tab, $settings ) {
		$fields = array();

		if ( 'general' === $tab ) {
			$fields[] = array( 'key' => 'general.enabled', 'type' => 'checkbox', 'label' => __( 'فعال', 'hami-ai-assistant' ) );
			$fields[] = array( 'key' => 'general.display_mode', 'type' => 'select', 'label' => __( 'نحوه نمایش', 'hami-ai-assistant' ), 'choices' => array( 'both' => __( 'هر دو', 'hami-ai-assistant' ), 'floating' => __( 'دکمه شناور', 'hami-ai-assistant' ), 'shortcode' => __( 'فقط با شورت‌کد', 'hami-ai-assistant' ) ) );
			$fields[] = array( 'key' => 'general.floating_position', 'type' => 'select', 'label' => __( 'سمت دکمه', 'hami-ai-assistant' ), 'choices' => array( 'right' => __( 'راست', 'hami-ai-assistant' ), 'left' => __( 'چپ', 'hami-ai-assistant' ) ) );
			$fields[] = array( 'key' => 'general.floating_bottom', 'type' => 'number', 'label' => __( 'فاصله از پایین (px)', 'hami-ai-assistant' ), 'min' => 0, 'max' => 200 );
			$fields[] = array( 'key' => 'general.floating_side', 'type' => 'number', 'label' => __( 'فاصله از کنار (px)', 'hami-ai-assistant' ), 'min' => 0, 'max' => 200 );

			// Where it shows. The summary line is read-only, so it carries no
			// settings key — it joins its group by name.
			$fields[] = array( 'key' => '', 'type' => 'summary', 'group' => 'where' );
			$fields[] = array(
				'key'     => 'general.pages_mode',
				'type'    => 'radios',
				'label'   => __( 'قانون صفحات', 'hami-ai-assistant' ),
				// Resolved, so a legacy install shows its derived rule chosen
				// instead of an empty group of radios.
				'value'   => Haa_Plugin::pages_mode( $settings ),
				'choices' => array(
					'all'     => __( 'همه‌جا', 'hami-ai-assistant' ),
					'include' => __( 'فقط در این‌ها', 'hami-ai-assistant' ),
					'exclude' => __( 'همه‌جا به‌جز این‌ها', 'hami-ai-assistant' ),
				),
			);
			$fields[] = array( 'key' => 'general.special_pages', 'type' => 'chipbox', 'label' => __( 'صفحه‌های ویژه', 'hami-ai-assistant' ), 'choices' => $this->special_page_choices(), 'show' => 'any', 'hint' => __( 'این صفحه‌ها شناسه‌ی برگه ندارند و با شرط صفحه شناخته می‌شوند.', 'hami-ai-assistant' ) );
			$fields[] = array( 'key' => 'general.show_on_pages', 'type' => 'pagepicker', 'label' => __( 'فقط این صفحات', 'hami-ai-assistant' ), 'show' => 'include' );
			$fields[] = array( 'key' => 'general.exclude_pages', 'type' => 'pagepicker', 'label' => __( 'همه‌جا به‌جز این‌ها', 'hami-ai-assistant' ), 'show' => 'exclude' );
			$fields[] = array( 'key' => 'general.show_on_post_types', 'type' => 'chipbox', 'label' => __( 'نوع محتوا (خالی = همه)', 'hami-ai-assistant' ), 'choices' => $this->public_post_types(), 'hint' => __( 'فقط نوع‌هایی که در سایت قابل بازدیدند؛ نوع‌های داخلی قالب‌سازها حذف شده‌اند.', 'hami-ai-assistant' ) );

			$fields[] = array( 'key' => 'general.show_devices', 'type' => 'multicheck', 'label' => __( 'دستگاه', 'hami-ai-assistant' ), 'choices' => array( 'desktop' => __( 'دسکتاپ', 'hami-ai-assistant' ), 'mobile' => __( 'موبایل', 'hami-ai-assistant' ) ) );
			$fields[] = array( 'key' => 'general.show_roles', 'type' => 'chipbox', 'label' => __( 'نقش‌ها (خالی = همه)', 'hami-ai-assistant' ), 'choices' => $this->role_choices() );
		} elseif ( 'provider' === $tab ) {
			$presets = Haa_API::presets();
			$choices = array();
			foreach ( $presets as $slug => $p ) {
				$choices[ $slug ] = $p['label'];
			}
			$fields[] = array( 'key' => 'provider.provider', 'type' => 'select', 'label' => __( 'سرویس', 'hami-ai-assistant' ), 'choices' => $choices );
			$fields[] = array( 'key' => 'provider.api_key', 'type' => 'secret', 'label' => __( 'کلید API', 'hami-ai-assistant' ) );
			$fields[] = array( 'key' => 'provider.base_url', 'type' => 'text', 'label' => __( 'آدرس سرویس (اختیاری)', 'hami-ai-assistant' ) );
			$fields[] = array( 'key' => 'provider.model', 'type' => 'text', 'label' => __( 'مدل چت', 'hami-ai-assistant' ), 'hint' => __( 'برای دریافت فهرست مدل‌ها از دکمه‌ی «تست اتصال» استفاده کنید.', 'hami-ai-assistant' ) );
			$fields[] = array( 'key' => 'provider.embedding_model', 'type' => 'text', 'label' => __( 'مدل بردارسازی', 'hami-ai-assistant' ), 'hint' => __( 'برای جستجوی معنایی. اگر خالی باشد از همان مدل چت استفاده می‌شود.', 'hami-ai-assistant' ) );
			$fields[] = array( 'key' => 'provider.image_model', 'type' => 'text', 'label' => __( 'مدل تصویر', 'hami-ai-assistant' ) );
			$fields[] = array( 'key' => 'provider.timeout', 'type' => 'number', 'label' => __( 'مهلت پاسخ (ثانیه)', 'hami-ai-assistant' ), 'min' => 5, 'max' => 300 );
			$fields[] = array( 'key' => 'provider.max_tokens', 'type' => 'number', 'label' => __( 'حداکثر توکن خروجی', 'hami-ai-assistant' ), 'min' => 64, 'max' => 8192 );
			$fields[] = array( 'key' => 'provider.allow_localhost', 'type' => 'checkbox', 'label' => __( 'اجازه آدرس محلی (localhost/127.x)', 'hami-ai-assistant' ), 'hint' => __( 'فقط برای توسعه و سرورهای داخلی. در محیط تولید خاموش بماند.', 'hami-ai-assistant' ) );
			$fields[] = array( 'key' => 'provider.adapter', 'type' => 'select', 'label' => __( 'فرمت درخواست (آداپتر)', 'hami-ai-assistant' ), 'choices' => array( 'openai' => 'OpenAI', 'gemini' => 'Gemini', 'claude' => 'Claude' ), 'hint' => __( 'فقط وقتی «سرویس» روی یکی از presetهاست نادیده گرفته می‌شود؛ برای ارائه‌دهنده‌ی سفارشی (سازگار با یکی از این سه فرمت) انتخاب کنید.', 'hami-ai-assistant' ) );
			$fields[] = array( 'key' => 'provider.temperature', 'type' => 'float', 'label' => __( 'دمای پاسخ (temperature)', 'hami-ai-assistant' ), 'min' => 0, 'max' => 2, 'step' => 0.1, 'hint' => __( '۰ = پاسخ دقیق و تکرارپذیر، ۲ = خلاق‌تر. پیشنهاد: ۰٫۷', 'hami-ai-assistant' ) );
			$fields[] = array( 'key' => 'provider.retries', 'type' => 'number', 'label' => __( 'تعداد تلاش مجدد', 'hami-ai-assistant' ), 'min' => 0, 'max' => 5, 'hint' => __( 'در صورت خطای موقت شبکه یا سرور، درخواست دوباره فرستاده می‌شود.', 'hami-ai-assistant' ) );
			$fields[] = array( 'key' => 'provider.proxy_host', 'type' => 'text', 'label' => __( 'پراکسی — آدرس', 'hami-ai-assistant' ), 'hint' => __( 'مثلاً socks5://127.0.0.1:1080 یا http://user:pass@host:port. خالی = بدون پراکسی.', 'hami-ai-assistant' ) );
			$fields[] = array( 'key' => 'provider.proxy_port', 'type' => 'number', 'label' => __( 'پراکسی — پورت', 'hami-ai-assistant' ), 'min' => 0, 'max' => 65535 );
			$fields[] = array( 'key' => 'provider.proxy_username', 'type' => 'text', 'label' => __( 'پراکسی — نام کاربری', 'hami-ai-assistant' ) );
			$fields[] = array( 'key' => 'provider.proxy_password', 'type' => 'secret', 'label' => __( 'پراکسی — رمز عبور', 'hami-ai-assistant' ), 'hint' => __( 'به‌صورت رمزنگاری‌شده ذخیره و در فرم فقط ماسک نمایش داده می‌شود.', 'hami-ai-assistant' ) );
		} elseif ( 'appearance' === $tab ) {
			$fields[] = array( 'key' => 'appearance.primary_color', 'type' => 'color', 'label' => __( 'رنگ اصلی', 'hami-ai-assistant' ) );
			$fields[] = array( 'key' => 'appearance.secondary_color', 'type' => 'color', 'label' => __( 'رنگ حباب پاسخ', 'hami-ai-assistant' ) );
			$fields[] = array( 'key' => 'appearance.bg_color', 'type' => 'color', 'label' => __( 'رنگ پس‌زمینه', 'hami-ai-assistant' ) );
			$fields[] = array( 'key' => 'appearance.text_color', 'type' => 'color', 'label' => __( 'رنگ متن', 'hami-ai-assistant' ) );
			$fields[] = array( 'key' => 'appearance.theme_mode', 'type' => 'select', 'label' => __( 'حالت رنگی', 'hami-ai-assistant' ), 'choices' => array( 'light' => __( 'روشن', 'hami-ai-assistant' ), 'dark' => __( 'تیره', 'hami-ai-assistant' ), 'auto' => __( 'خودکار', 'hami-ai-assistant' ) ) );
			$fields[] = array( 'key' => 'appearance.font', 'type' => 'select', 'label' => __( 'فونت', 'hami-ai-assistant' ), 'choices' => array( 'system' => __( 'فونت سیستم', 'hami-ai-assistant' ), 'vazirmatn' => 'Vazirmatn', 'iransans' => 'IRANSans', 'dana' => 'Dana', 'yekan' => 'Yekan Bakh' ) );
			$fields[] = array( 'key' => 'appearance.font_size', 'type' => 'number', 'label' => __( 'اندازه فونت (px)', 'hami-ai-assistant' ), 'min' => 11, 'max' => 20 );
			$fields[] = array( 'key' => 'appearance.border_radius', 'type' => 'number', 'label' => __( 'گردی گوشه (px)', 'hami-ai-assistant' ), 'min' => 0, 'max' => 32 );
			$fields[] = array( 'key' => 'appearance.button_size', 'type' => 'number', 'label' => __( 'اندازه دکمه (px)', 'hami-ai-assistant' ), 'min' => 40, 'max' => 80 );
			$fields[] = array( 'key' => 'appearance.welcome_message', 'type' => 'textarea', 'label' => __( 'پیام خوش‌آمد', 'hami-ai-assistant' ) );
			$fields[] = array( 'key' => 'appearance.placeholder', 'type' => 'text', 'label' => __( 'متن جای‌گاه', 'hami-ai-assistant' ) );
			$fields[] = array( 'key' => 'appearance.custom_icon', 'type' => 'media', 'label' => __( 'آیکون سفارشی', 'hami-ai-assistant' ) );
			$fields[] = array( 'key' => 'appearance.custom_css', 'type' => 'textarea', 'label' => __( 'CSS سفارشی', 'hami-ai-assistant' ) );
		} elseif ( 'advanced' === $tab ) {
			$fields[] = array( 'key' => 'advanced.enable_rag', 'type' => 'checkbox', 'label' => __( 'استفاده از محتوای سایت در پاسخ', 'hami-ai-assistant' ) );
			$fields[] = array( 'key' => 'advanced.kb_enabled', 'type' => 'checkbox', 'label' => __( 'پایگاه دانش (RAG)', 'hami-ai-assistant' ), 'hint' => __( 'برای جست‌وجوی معنایی و پاسخ‌های هوشمند لازم است.', 'hami-ai-assistant' ) );
			$fields[] = array( 'key' => 'advanced.kb_post_types', 'type' => 'multicheck', 'label' => __( 'نوع محتوای پایگاه دانش', 'hami-ai-assistant' ), 'choices' => $this->public_post_types() );
			$fields[] = array( 'key' => 'advanced.kb_chunk_words', 'type' => 'number', 'label' => __( 'اندازه هر تکه (کلمه)', 'hami-ai-assistant' ), 'min' => 200, 'max' => 800 );
			$fields[] = array( 'key' => 'advanced.kb_overlap_words', 'type' => 'number', 'label' => __( 'همپوشانی تکه‌ها (کلمه)', 'hami-ai-assistant' ), 'min' => 0, 'max' => 200 );
			$fields[] = array( 'key' => 'advanced.kb_categories', 'type' => 'multicheck', 'label' => __( 'دسته‌های پایگاه دانش (خالی = همه)', 'hami-ai-assistant' ), 'choices' => $this->category_choices(), 'hint' => __( 'فقط مطالب این دسته‌ها ایندکس می‌شوند.', 'hami-ai-assistant' ) );
			$fields[] = array( 'key' => 'advanced.kb_max_file_kb', 'type' => 'number', 'label' => __( 'حداکثر حجم فایل پیوست (KB)', 'hami-ai-assistant' ), 'min' => 64, 'max' => 10240, 'hint' => __( 'فایل‌های بزرگ‌تر از این اندازه در پایگاه دانش نادیده گرفته می‌شوند.', 'hami-ai-assistant' ) );
			$fields[] = array( 'key' => 'advanced.enable_cache', 'type' => 'checkbox', 'label' => __( 'کش پاسخ‌های تکراری', 'hami-ai-assistant' ) );
			$fields[] = array( 'key' => 'advanced.cache_ttl', 'type' => 'number', 'label' => __( 'مدت کش (ثانیه)', 'hami-ai-assistant' ), 'min' => 60, 'max' => 604800 );
			$fields[] = array( 'key' => 'advanced.rate_limit_count', 'type' => 'number', 'label' => __( 'سقف درخواست', 'hami-ai-assistant' ), 'min' => 1, 'max' => 1000 );
			$fields[] = array( 'key' => 'advanced.rate_limit_minutes', 'type' => 'number', 'label' => __( 'بازه سقف (دقیقه)', 'hami-ai-assistant' ), 'min' => 1, 'max' => 1440 );
			$fields[] = array( 'key' => 'advanced.token_ttl_minutes', 'type' => 'number', 'label' => __( 'عمر توکن چت (دقیقه)', 'hami-ai-assistant' ), 'min' => 5, 'max' => 1440, 'hint' => __( 'پیشنهاد: ۳۰ تا ۶۰ دقیقه. توکن به مرورگر و IP کاربر گره می‌خورد و فقط یک‌بار تمدید می‌شود.', 'hami-ai-assistant' ) );
			$fields[] = array( 'key' => 'advanced.max_message_chars', 'type' => 'number', 'label' => __( 'حداکثر طول پیام (کاراکتر)', 'hami-ai-assistant' ), 'min' => 200, 'max' => 8000 );
			$fields[] = array( 'key' => 'advanced.daily_request_cap', 'type' => 'number', 'label' => __( 'سقف روزانه‌ی چت (کل سایت)', 'hami-ai-assistant' ), 'min' => 0, 'max' => 1000000, 'hint' => __( '۰ = بدون سقف. پس از رسیدن به سقف، به همه‌ی کاربران همین پیام نمایش داده می‌شود.', 'hami-ai-assistant' ) );
			$fields[] = array( 'key' => 'advanced.daily_cap_message', 'type' => 'textarea', 'label' => __( 'پیام رسیدن به سقف روزانه', 'hami-ai-assistant' ) );
			$fields[] = array( 'key' => 'advanced.daily_user_cap', 'type' => 'number', 'label' => __( 'سقف روزانه به ازای هر IP/کاربر', 'hami-ai-assistant' ), 'min' => 0, 'max' => 1000000, 'hint' => __( '۰ = بدون سقف. شناسه: کاربر واردشده یا آدرس IP مهمان. مرز روز، نیمه‌شب به وقت سایت است.', 'hami-ai-assistant' ) );
			$fields[] = array( 'key' => 'advanced.daily_user_cap_message', 'type' => 'textarea', 'label' => __( 'پیام رسیدن به سقف هر کاربر', 'hami-ai-assistant' ) );
			$fields[] = array( 'key' => 'advanced.log_conversations', 'type' => 'checkbox', 'label' => __( 'ثبت گفتگوها', 'hami-ai-assistant' ) );
			$fields[] = array( 'key' => 'advanced.log_retention_days', 'type' => 'number', 'label' => __( 'مدت نگه‌داری گفتگوهای ثبت‌شده (روز)', 'hami-ai-assistant' ), 'min' => 1, 'max' => 365, 'hint' => __( 'پس از این مدت، رکوردهای قدیمی در پاک‌سازی روزانه حذف می‌شوند.', 'hami-ai-assistant' ) );
			$fields[] = array( 'key' => 'advanced.usage_retention', 'type' => 'number', 'label' => __( 'مدت نگه‌داری آمار مصرف (روز)', 'hami-ai-assistant' ), 'min' => 7, 'max' => 3650, 'hint' => __( 'سوابق مصرف توکن و پیام‌ها؛ برای نمودار داشبورد.', 'hami-ai-assistant' ) );
			$fields[] = array( 'key' => 'advanced.guest_access', 'type' => 'select', 'label' => __( 'دسترسی مهمان', 'hami-ai-assistant' ), 'choices' => array( 0 => __( 'همه', 'hami-ai-assistant' ), 1 => __( 'فقط مهمان‌ها', 'hami-ai-assistant' ), 2 => __( 'فقط کاربران واردشده', 'hami-ai-assistant' ) ) );
			$fields[] = array( 'key' => 'advanced.login_required_message', 'type' => 'text', 'label' => __( 'پیام ورود الزامی', 'hami-ai-assistant' ) );
			$fields[] = array( 'key' => 'advanced.ip_header', 'type' => 'select', 'label' => __( 'هدر IP', 'hami-ai-assistant' ), 'choices' => array( '' => __( 'خودکار (REMOTE_ADDR)', 'hami-ai-assistant' ), 'CF-Connecting-IP' => 'CF-Connecting-IP (Cloudflare)', 'X-Forwarded-For' => 'X-Forwarded-For', 'X-Real-IP' => 'X-Real-IP' ), 'hint' => __( 'اگر پشت Cloudflare یا CDN هستید انتخاب کنید.', 'hami-ai-assistant' ) );
		}

		$this->render_grouped_fields( $fields, $settings, $this->field_groups( $tab ) );

		// Live preview of the chat widget. Rendered from the SAVED settings;
		// admin.js mirrors the in-progress form values into it as they change.
		if ( 'appearance' === $tab ) {
			ob_start();
			haa_render_widget( array( 'preview' => true ) );
			$widget = ob_get_clean();
			?>
			<section class="haai-group haai-preview__group">
				<h2 class="haai-group__title"><?php esc_html_e( 'پیش‌نمایش زنده', 'hami-ai-assistant' ); ?></h2>
				<div class="haai-preview" data-haai-preview>
					<span class="haai-preview__label"><?php esc_html_e( 'پیش‌نمایش', 'hami-ai-assistant' ); ?></span>
					<div class="haai-preview__frame"><?php echo $widget; // phpcs:ignore WordPress.Security.EscapeOutput ?></div>
				</div>
				<p class="description"><?php esc_html_e( 'این پیش‌نمایش با تغییر فیلدها به‌روز می‌شود؛ برای ذخیره، دکمه‌ی پایین صفحه را بزنید.', 'hami-ai-assistant' ); ?></p>
			</section>
			<?php
		}

		if ( 'provider' === $tab ) {
			?>
			<section class="haai-group">
				<h2 class="haai-group__title"><?php esc_html_e( 'عملیات', 'hami-ai-assistant' ); ?></h2>
				<p>
					<button type="button" class="button" data-haai-test><?php esc_html_e( 'تست اتصال', 'hami-ai-assistant' ); ?></button>
					<button type="button" class="button" data-haai-models><?php esc_html_e( 'دریافت مدل‌ها', 'hami-ai-assistant' ); ?></button>
				</p>
				<div class="haai-test-result" data-haai-test-result></div>
				<p class="description"><?php esc_html_e( 'فهرست مدل‌های دریافتی:', 'hami-ai-assistant' ); ?></p>
				<select multiple size="8" data-haai-model-list class="haai-model-list"></select>
				<div class="haai-presets">
					<?php foreach ( Haa_API::presets() as $slug => $p ) : ?>
						<button type="button" class="button" data-haai-preset="<?php echo esc_attr( $slug ); ?>"
							data-base="<?php echo esc_attr( $p['base_url'] ); ?>"
							data-model="<?php echo esc_attr( $p['model'] ); ?>">
							<?php echo esc_html( $p['label'] ); ?>
						</button>
					<?php endforeach; ?>
				</div>
			</section>
			<?php
		}

		// Modules screen has its own save handler.
		if ( 'modules' === $tab ) {
			echo '<p><a class="button button-secondary" href="' . esc_url( Haa_Util::screen_url( 'modules' ) ) . '">' . esc_html__( 'مدیریت ماژول‌ها', 'hami-ai-assistant' ) . '</a></p>';
		}
	}

	/* ------------------------------------------------------------------
	 * Field primitives (shared with the module screens)
	 * ---------------------------------------------------------------- */

	/**
	 * @param string $key    Dotted key.
	 * @param array  $settings Settings.
	 * @return mixed
	 */
	public function v( $key, $settings = null ) {
		if ( null === $settings ) {
			$settings = Haa_Settings::get();
		}
		$parts = explode( '.', $key );
		$node  = $settings;
		foreach ( $parts as $p ) {
			if ( ! is_array( $node ) || ! array_key_exists( $p, $node ) ) {
				return '';
			}
			$node = $node[ $p ];
		}
		return $node;
	}

	/**
	 * @param string $key  Dotted key.
	 * @param string $label Label.
	 * @param string $desc  Hint.
	 * @param bool   $bare  No wrapper row (multicheck/media/pages).
	 */
	public function open_field( $key, $label = '', $desc = '', $bare = false ) {
		// Remembered so close_field() closes the tag that was actually opened:
		// a bare row is a <fieldset>, every other row is two nested <div>s.
		$this->field_bare = (bool) $bare;

		if ( $bare ) {
			echo '<fieldset class="haai-field haai-field--bare">';
			if ( '' !== $label ) {
				echo '<legend class="haai-field__legend">' . esc_html( $label ) . '</legend>';
			}
			if ( '' !== $desc ) {
				echo '<p class="description">' . esc_html( $desc ) . '</p>';
			}
			return;
		}
		echo '<div class="haai-field">';
		echo '<label class="haai-field__label" for="haai-' . esc_attr( $key ) . '">' . esc_html( $label ) . '</label>';
		echo '<div class="haai-field__control">';
		if ( '' !== $desc ) {
			echo '<p class="description">' . esc_html( $desc ) . '</p>';
		}
	}

	/** @return void */
	public function close_field() {
		echo $this->field_bare ? '</fieldset>' : '</div></div>';
	}

	/**
	 * Nested input name for a dotted settings key so PHP receives
	 * $_POST[section][key] exactly as Haa_Settings::save_section() expects:
	 * "general.enabled" → "general[enabled]".
	 *
	 * @param string $key Dotted key.
	 * @return string
	 */
	private static function input_name( $key ) {
		$parts = explode( '.', (string) $key );
		$name  = array_shift( $parts );
		foreach ( $parts as $part ) {
			$name .= '[' . $part . ']';
		}
		return $name;
	}

	/**
	 * @param string $key   Dotted key.
	 * @param mixed  $value Value.
	 */
	public function field_text( $key, $value ) {
		printf(
			'<input type="text" class="regular-text" id="haai-%1$s" name="%2$s" value="%3$s">',
			esc_attr( $key ),
			esc_attr( self::input_name( $key ) ),
			esc_attr( (string) $value )
		);
	}

	/**
	 * @param string $key   Dotted key.
	 * @param mixed  $value Value (HH:MM).
	 */
	public function field_time( $key, $value ) {
		printf(
			'<input type="time" class="small-text" id="haai-%1$s" name="%2$s" value="%3$s">',
			esc_attr( $key ),
			esc_attr( self::input_name( $key ) ),
			esc_attr( (string) $value )
		);
	}

	/**
	 * Secrets are stored encrypted and displayed only as a mask + last 4 chars.
	 *
	 * @param string $key   Dotted key.
	 * @param mixed  $value Stored (already decrypted) value.
	 */
	public function field_secret( $key, $value ) {
		$plain    = Haa_Settings::decrypt( (string) $value );
		$mask     = '' !== $plain ? '••••' . substr( $plain, -4 ) : '';
		$has_full = '' !== $plain && strlen( $plain ) > 4;
		printf(
			'<input type="password" class="regular-text" id="haai-%1$s" name="%2$s" value="%3$s" autocomplete="off" data-haai-secret placeholder="%4$s">',
			esc_attr( $key ),
			esc_attr( self::input_name( $key ) ),
			esc_attr( $has_full ? Haa_Settings::KEY_MASK : $plain ),
			esc_attr( $mask )
		);
		echo '<p class="description">' . esc_html__( 'برای نگه‌داشتن مقدار فعلی، تغییرش ندهید. کلید به‌صورت رمزنگاری‌شده ذخیره می‌شود.', 'hami-ai-assistant' ) . '</p>';
	}

	/**
	 * @param string $key   Dotted key.
	 * @param mixed  $value Value.
	 * @param int    $min   Min.
	 * @param int    $max   Max.
	 */
	public function field_number( $key, $value, $min = 0, $max = 99999 ) {
		printf(
			'<input type="number" class="small-text" id="haai-%1$s" name="%2$s" value="%3$d" min="%4$d" max="%5$d">',
			esc_attr( $key ),
			esc_attr( self::input_name( $key ) ),
			(int) $value,
			(int) $min,
			(int) $max
		);
	}

	/**
	 * Decimal number input (step != 1) so values like temperature are not
	 * silently rounded to an integer when the form is re-rendered.
	 *
	 * @param string $key   Dotted key.
	 * @param mixed  $value Value.
	 * @param int    $min   Min.
	 * @param int    $max   Max.
	 * @param float  $step  Step.
	 */
	public function field_float( $key, $value, $min = 0, $max = 1, $step = 0.1 ) {
		printf(
			'<input type="number" class="small-text" id="haai-%1$s" name="%2$s" value="%3$s" min="%4$d" max="%5$d" step="%6$s">',
			esc_attr( $key ),
			esc_attr( self::input_name( $key ) ),
			esc_attr( (string) ( is_numeric( $value ) ? $value + 0 : $min ) ),
			(int) $min,
			(int) $max,
			esc_attr( (string) $step )
		);
	}

	/**
	 * @param string $key   Dotted key.
	 * @param mixed  $value Value.
	 */
	public function field_checkbox( $key, $value ) {
		printf(
			'<label class="haai-checkbox"><input type="checkbox" id="haai-%1$s" name="%2$s" value="1" %3$s> %4$s</label>',
			esc_attr( $key ),
			esc_attr( self::input_name( $key ) ),
			checked( ! empty( $value ), true, false ),
			esc_html__( 'روشن', 'hami-ai-assistant' )
		);
	}

	/**
	 * @param string $key     Dotted key.
	 * @param mixed  $value   Value.
	 * @param array  $choices value => label.
	 */
	public function field_select( $key, $value, $choices = array() ) {
		printf( '<select id="haai-%1$s" name="%2$s">', esc_attr( $key ), esc_attr( self::input_name( $key ) ) );
		foreach ( $choices as $v => $l ) {
			printf(
				'<option value="%s" %s>%s</option>',
				esc_attr( (string) $v ),
				selected( (string) $value, (string) $v, false ),
				esc_html( $l )
			);
		}
		echo '</select>';
	}

	/**
	 * @param string $key   Dotted key.
	 * @param mixed  $value Value.
	 */
	public function field_textarea( $key, $value ) {
		printf(
			'<textarea rows="4" class="large-text" id="haai-%1$s" name="%2$s">%3$s</textarea>',
			esc_attr( $key ),
			esc_attr( self::input_name( $key ) ),
			esc_textarea( (string) $value )
		);
	}

	/**
	 * @param string $key   Dotted key.
	 * @param mixed  $value Value.
	 */
	public function field_color( $key, $value ) {
		printf(
			'<input type="color" id="haai-%1$s" name="%2$s" value="%3$s">',
			esc_attr( $key ),
			esc_attr( self::input_name( $key ) ),
			esc_attr( '' !== (string) $value ? (string) $value : '#4f46e5' )
		);
	}

	/**
	 * @param string $key   Dotted key.
	 * @param mixed  $value Attachment id.
	 */
	public function field_media( $key, $value ) {
		$id   = (int) $value;
		$url  = $id ? wp_get_attachment_url( $id ) : '';
		$name = $id ? get_the_title( $id ) : '';
		printf(
			'<input type="hidden" class="regular-text" id="haai-%1$s" name="%2$s" value="%3$d" data-haai-media>',
			esc_attr( $key ),
			esc_attr( self::input_name( $key ) ),
			$id
		);
		echo '<div class="haai-media" data-haai-media-wrap>';
		if ( $url ) {
			printf( '<img src="%s" alt="" width="48" height="48" loading="lazy">', esc_url( $url ) );
		}
		printf(
			'<p><button type="button" class="button" data-haai-media-pick>%s</button> <button type="button" class="button-link-delete" data-haai-media-clear>%s</button></p>',
			esc_html__( 'انتخاب', 'hami-ai-assistant' ),
			esc_html__( 'حذف', 'hami-ai-assistant' )
		);
		echo '</div>';
	}

	/**
	 * Chips holding a check per choice — roles, post types, special pages.
	 *
	 * Same input names as the old list of checkboxes, so nothing about saving
	 * this setting changed.
	 *
	 * @param string $key     Dotted key.
	 * @param array  $value   Selected values.
	 * @param array  $choices value => label.
	 * @param string $show    Page rule this row belongs to ('' = always).
	 */
	public function field_chipbox( $key, $value, $choices = array(), $show = '' ) {
		$selected = array_map( 'strval', (array) $value );
		printf( '<span class="haai-chips"%s>', '' === $show ? '' : ' data-haai-show="' . esc_attr( $show ) . '"' );
		if ( ! $choices ) {
			echo '<span class="description">' . esc_html__( 'گزینه‌ای موجود نیست.', 'hami-ai-assistant' ) . '</span>';
		}
		foreach ( $choices as $v => $label ) {
			printf(
				'<label class="haai-chip"><input type="checkbox" name="%1$s[]" value="%2$s" %3$s> %4$s</label>',
				esc_attr( self::input_name( $key ) ),
				esc_attr( (string) $v ),
				checked( in_array( (string) $v, $selected, true ), true, false ),
				esc_html( $label )
			);
		}
		echo '</span>';
	}

	/**
	 * Radio row for the three page rules. JS reveals only the picker that the
	 * chosen rule actually uses.
	 *
	 * @param string $key     Dotted key.
	 * @param string $value   Resolved rule.
	 * @param array  $choices value => label.
	 * @param string $show    Unused, kept so the call signature is uniform.
	 */
	public function field_radios( $key, $value, $choices = array(), $show = '' ) {
		echo '<span class="haai-chips">';
		foreach ( $choices as $v => $label ) {
			printf(
				'<label class="haai-chip"><input type="radio" name="%1$s" value="%2$s" %3$s data-haai-pages-mode> %4$s</label>',
				esc_attr( self::input_name( $key ) ),
				esc_attr( (string) $v ),
				checked( (string) $value, (string) $v, false ),
				esc_html( $label )
			);
		}
		echo '</span>';
	}

	/**
	 * Searchable page picker.
	 *
	 * Replaces the old <select> that listed the first 200 posts of the site:
	 * only the ids actually chosen are rendered (one query with post__in), and
	 * new ones are looked up over AJAX 20 at a time, so a site with thousands
	 * of pages costs the same to open this screen as one with ten.
	 *
	 * @param string $key  Dotted key.
	 * @param array  $value Saved ids.
	 * @param string $show  Rule this picker belongs to.
	 */
	public function field_page_picker( $key, $value, $show = '' ) {
		$name = self::input_name( $key );
		$ids  = array_values( array_unique( array_filter( array_map( 'absint', (array) $value ) ) ) );
		$types = array_keys( $this->public_post_types() );
		if ( ! $types ) {
			$types = array( 'page', 'post' );
		}
	
		$seen = array();
		if ( $ids ) {
			foreach ( get_posts(
				array(
					'post_type'      => $types,
					'post_status'    => 'any',
					'post__in'       => $ids,
					'orderby'        => 'post__in',
					'posts_per_page' => count( $ids ),
					'no_found_rows'  => true,
				)
			) as $p ) {
				$seen[ (int) $p->ID ] = get_the_title( $p ) . ' (#' . (int) $p->ID . ')';
			}
		}
	
		printf(
			'<div class="haai-picker" data-haai-picker="%1$s"%2$s data-haai-search="%3$s">',
			esc_attr( $name ),
			'' === $show ? '' : ' data-haai-show="' . esc_attr( $show ) . '"',
			esc_url( admin_url( 'admin-ajax.php?action=haai_page_search&nonce=' . wp_create_nonce( 'haai_admin' ) ) )
		);
	
		echo '<span class="haai-chips" data-haai-picker-chosen>';
		if ( ! $ids ) {
			echo '<span class="description">' . esc_html__( 'هیچ صفحه‌ای انتخاب نشده.', 'hami-ai-assistant' ) . '</span>';
		}
		foreach ( $ids as $id ) {
			printf(
				'<span class="haai-chip haai-chip--on"><input type="hidden" name="%1$s[]" value="%2$s">%3$s <button type="button" class="haai-chip__x" data-haai-picker-drop aria-label="%4$s">&times;</button></span>',
				esc_attr( $name ),
				(int) $id,
				esc_html( isset( $seen[ $id ] ) ? $seen[ $id ] : '#' . $id ),
				esc_attr__( 'حذف', 'hami-ai-assistant' )
			);
		}
		echo '</span>';
	
		printf(
			'<p class="haai-picker__row"><input type="search" class="haai-picker__q" data-haai-picker-q placeholder="%1$s"><span class="description" data-haai-picker-count></span></p>',
			esc_attr__( 'جست‌وجو با عنوان یا شناسه…', 'hami-ai-assistant' )
		);
		echo '<ul class="haai-hits haai-picker__results" data-haai-picker-results hidden></ul>';
		echo '</div>';
	}

	/**
	 * @param string $key    Dotted key.
	 * @param mixed  $value Array of page ids.
	 */
	public function field_pages( $key, $value ) {
		$selected = array_map( 'intval', (array) $value );
		$pages    = get_posts(
			array(
				'post_type'      => array( 'page', 'post' ),
				'post_status'    => 'publish',
				'posts_per_page' => 200,
				'orderby'        => 'title',
				'order'          => 'ASC',
			)
		);
		printf( '<select multiple size="6" id="haai-%1$s" name="%2$s">', esc_attr( $key ), esc_attr( self::input_name( $key ) . '[]' ) );
		foreach ( $pages as $p ) {
			printf(
				'<option value="%d" %s>%s</option>',
				(int) $p->ID,
				in_array( (int) $p->ID, $selected, true ) ? 'selected' : '',
				esc_html( $p->post_title . ' (#' . $p->ID . ')' )
			);
		}
		echo '</select>';
	}

	/**
	 * @param string $key     Dotted key.
	 * @param array  $value   Selected values.
	 * @param array  $choices value => label.
	 * @param string $label   Group label.
	 */
	public function field_multicheck( $key, $value, $choices, $label = '' ) {
		foreach ( $choices as $v => $l ) {
			printf(
				'<label class="haai-checkbox" style="display:block"><input type="checkbox" name="%1$s[]" value="%2$s" %3$s> %4$s</label>',
				esc_attr( self::input_name( $key ) ),
				esc_attr( (string) $v ),
				checked( in_array( (string) $v, array_map( 'strval', (array) $value ), true ), true, false ),
				esc_html( $l )
			);
		}
	}

	/** @return array<string,string> */
	public function public_post_types() {
		$out = array();

		// public + publicly_queryable is what a visitor can actually land on.
		// Builders register internal storage post types as public (Elementor's
		// templates, floating buttons, slides; core'sReusable blocks and font
		// posts), and those have no business appearing in a visibility rule.
		$blocked = array(
			'attachment',
			'elementor_library',
			'elementor-hf',
			'e-floating-button',
			'e-slide',
			'e-popup',
			'wp_block',
			'wp_reusable',
			'wp_font_family',
			'wp_font_face',
			'wp_template',
			'wp_template_part',
			'wp_global_styles',
			'wp_navigation',
			'wp_font',
			'nav_menu_item',
			'customize_changeset',
			'custom_css',
			'nav_menu',
		);

		foreach ( get_post_types( array( 'public' => true, 'publicly_queryable' => true ), 'objects' ) as $pt ) {
			if ( in_array( $pt->name, $blocked, true ) ) {
				continue;
			}
			$out[ $pt->name ] = $pt->labels->singular_name;
		}
		return $out;
	}

	/**
	 * Conditional-tag pages that have no document id, so they cannot be
	 * picked from the page list. WooCommerce tokens only appear when Woo is
	 * active, which keeps the list honest.
	 *
	 * @return array<string,string>
	 */
	public function special_page_choices() {
		$out = array(
			'front'   => __( 'صفحه‌ی اصلی', 'hami-ai-assistant' ),
			'blog'    => __( 'صفحه‌ی بلاگ', 'hami-ai-assistant' ),
			'archive' => __( 'آرشیوها (دسته، برچسب، نویسنده)', 'hami-ai-assistant' ),
			'search'  => __( 'نتایج جست‌وجو', 'hami-ai-assistant' ),
			'404'     => __( 'صفحه‌ی ۴۰۴', 'hami-ai-assistant' ),
		);
		if ( haa_is_woo() ) {
			$out['shop']     = __( 'فروشگاه', 'hami-ai-assistant' );
			$out['product']  = __( 'صفحه‌ی محصول', 'hami-ai-assistant' );
			$out['cart']     = __( 'سبد خرید', 'hami-ai-assistant' );
			$out['checkout'] = __( 'پرداخت', 'hami-ai-assistant' );
			$out['account']  = __( 'حساب کاربری', 'hami-ai-assistant' );
		}
		return $out;
	}

	/**
	 * @return array<string,string> Role slugs => labels, plus the guest pseudo-role.
	 */
	public function role_choices() {
		$out = array( 'guest' => __( 'مهمان (وارد نشده)', 'hami-ai-assistant' ) );
		foreach ( wp_roles()->roles as $slug => $role ) {
			$out[ $slug ] = $role['name'];
		}
		return $out;
	}

	/**
	 * One line saying what the current visibility rules do, so the admin does
	 * not have to read three controls to know the outcome.
	 *
	 * @param array $s Settings.
	 * @return string
	 */
	public static function display_rules_summary( $s ) {
		$mode    = Haa_Plugin::pages_mode( $s );
		$special = array_map( 'strval', (array) ( $s['general']['special_pages'] ?? array() ) );
		$roles   = array_map( 'strval', (array) ( $s['general']['show_roles'] ?? array() ) );
		$list    = 'include' === $mode
			? (array) ( $s['general']['show_on_pages'] ?? array() )
			: (array) ( $s['general']['exclude_pages'] ?? array() );
		$count   = count( array_filter( array_map( 'intval', $list ) ) );

		if ( 'all' === $mode ) {
			$where = __( 'نمایش در: همه‌جا', 'hami-ai-assistant' );
		} elseif ( 'include' === $mode ) {
			$where = sprintf( /* translators: %d: number of pages. */ __( 'نمایش در: فقط %d برگه/نوشته', 'hami-ai-assistant' ), $count );
		} else {
			$where = sprintf( /* translators: %d: number of pages. */ __( 'نمایش در: همه‌جا به‌جز %d برگه/نوشته', 'hami-ai-assistant' ), $count );
		}

		if ( 'all' !== $mode && $special ) {
			$where .= '، ' . sprintf( /* translators: %d: number of special pages. */ __( '%d صفحه‌ی ویژه', 'hami-ai-assistant' ), count( $special ) );
		}

		if ( $roles ) {
			$labels = array_map(
				function ( $r ) {
					return 'guest' === $r ? __( 'مهمان', 'hami-ai-assistant' ) : $r;
				},
				$roles
			);
			$where .= '، ' . sprintf( /* translators: %s: role list. */ __( 'نقش‌ها: %s', 'hami-ai-assistant' ), implode( '، ', $labels ) );
		} else {
			$where .= '، ' . __( 'نقش‌ها: همه', 'hami-ai-assistant' );
		}

		return $where;
	}

	/**
	 * Term choices for the knowledge-base category scope.
	 *
	 * @return array<string,string>
	 */
	public function category_choices() {
		$terms = get_categories( array( 'hide_empty' => false, 'orderby' => 'name' ) );
		$out   = array();
		foreach ( (array) $terms as $term ) {
			$out[ (string) $term->term_id ] = $term->name;
		}
		return $out;
	}
}
