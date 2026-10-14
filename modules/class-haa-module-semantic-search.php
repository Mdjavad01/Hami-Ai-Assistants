<?php
/**
 * Module: Semantic Search — hybrid BM25 + embeddings over your catalog.
 *
 * Indexing and querying are shared services in Haa_Search; this module is the
 * admin screen, the rebuild controls, the widget shortcode and the chat hook
 * that lets the assistant answer from real product data.
 *
 * @package Hami_AI_Assistant
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Haa_Module_Semantic_Search implements Haa_Module_Interface {

	/** @return string */
	public function slug() {
		return 'semantic-search';
	}

	/** @return string */
	public function label() {
		return __( 'جست‌وجوی هوشمند', 'hami-ai-assistant' );
	}

	/** @return string */
	public function description() {
		return __( 'ایندکس ترکیبی روی محصولات و مطالب؛ جست‌وجو را به کدهای سئو و امبدینگ‌های واقعی مجهز می‌کند.', 'hami-ai-assistant' );
	}

	/** @return bool */
	public function requires_woocommerce() {
		// Single source of truth: Haa_Modules::catalog_meta()[ 'woo' ].
		return Haa_Modules::requires_woocommerce( $this->slug() );
	}

	/** @return void */
	public function register() {
		add_action( 'haai_settings_sections', array( $this, 'register_section' ), 10 );
		add_action( 'haai_settings_fields_search', array( $this, 'register_fields' ) );
		add_action( 'haai_admin_menus', array( $this, 'register_menu' ) );
		add_action( 'admin_post_haai_ss_rebuild', array( $this, 'handle_rebuild' ) );
		add_action( 'admin_post_haai_ss_batch', array( $this, 'handle_batch' ) );
		add_action( 'admin_post_haai_ss_reset', array( $this, 'handle_reset' ) );
		// AJAX ticker: one batch per call, driven by the progress bar JS.
		add_action( 'wp_ajax_haai_ss_tick', array( $this, 'ajax_tick' ) );
		// Incremental update: re-index (and re-embed) a post when it changes.
		add_action( 'save_post', array( $this, 'on_save_post' ) );
		// [haai_search] is registered centrally by Haa_Plugin::frontend_hooks()
		// so gating and attribute handling live in exactly one place.
	}

	/**
	 * Keep the index fresh without a full rebuild: every published post that
	 * is re-saved gets its chunks (and, when enabled, its vectors) rebuilt.
	 *
	 * @param int $post_id Post id.
	 * @return void
	 */
	public function on_save_post( $post_id ) {
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}
		if ( ! Haa_Settings::is_module_enabled( 'semantic-search' ) || ! Haa_Settings::get( 'advanced.kb_enabled' ) ) {
			return;
		}
		if ( ! Haa_Search::in_scope( $post_id ) ) {
			// Out of scope (or trashed/unpublished): just drop its rows.
			Haa_Search::remove_post( $post_id );
			return;
		}
		Haa_Search::index_post( $post_id );
		Haa_Search::embed_post_now( $post_id );
	}

	/**
	 * One rebuild/embed batch over AJAX, so the admin screen can drive a
	 * progress bar without a full page reload per batch.
	 *
	 * @return void
	 */
	public function ajax_tick() {
		Haa_Util::guard_admin_ajax();

		if ( ! Haa_Settings::is_module_enabled( 'semantic-search' ) ) {
			wp_send_json( array( 'ok' => false, 'error' => __( 'ماژول جست‌وجوی هوشمند غیرفعال است.', 'hami-ai-assistant' ) ), 403 );
		}

		$progress = Haa_Search::rebuild_batch( 15 );
		wp_send_json( array( 'ok' => true, 'progress' => $progress ) );
	}

	/** @return void */
	public function activate() {
		Haa_DB::create();
	}

	/** @return void */
	public function deactivate() {
		// Keep the index: it is just rows, and reactivating would otherwise
		// force a full rebuild on a large catalog.
	}

	/**
	 * @param array $sections Tabs so far.
	 * @return array
	 */
	public function register_section( $sections ) {
		$sections['search'] = __( 'جست‌وجوی هوشمند', 'hami-ai-assistant' );
		return $sections;
	}

	/**
	 * @param array $fields Fields so far.
	 * @return array
	 */
	public function register_fields( $fields ) {
		$fields[] = array( 'key' => 'search.enabled', 'type' => 'checkbox', 'label' => __( 'فعال', 'hami-ai-assistant' ) );
		$fields[] = array( 'key' => 'search.limit', 'type' => 'number', 'label' => __( 'حداکثر نتیجه', 'hami-ai-assistant' ), 'min' => 1, 'max' => 50 );
		$fields[] = array( 'key' => 'search.min_score', 'type' => 'number', 'label' => __( 'کمینه امتیاز', 'hami-ai-assistant' ), 'min' => 0, 'max' => 10, 'step' => 0.1 );
		$fields[] = array( 'key' => 'search.include_products', 'type' => 'checkbox', 'label' => __( 'محصولات', 'hami-ai-assistant' ) );
		$fields[] = array( 'key' => 'search.include_posts', 'type' => 'checkbox', 'label' => __( 'مطالب', 'hami-ai-assistant' ) );
		$fields[] = array( 'key' => 'search.include_pages', 'type' => 'checkbox', 'label' => __( 'برگه‌ها', 'hami-ai-assistant' ) );
		$fields[] = array( 'key' => 'search.embeddings', 'type' => 'checkbox', 'label' => __( 'جست‌وجوی برداری (نیازمند مدل بردارسازی)', 'hami-ai-assistant' ) );
		$fields[] = array( 'key' => 'search.hybrid', 'type' => 'checkbox', 'label' => __( 'ترکیب کلیدواژه + برداری', 'hami-ai-assistant' ) );
		$fields[] = array( 'key' => 'search.semantic_weight', 'type' => 'number', 'label' => __( 'وزن معنایی', 'hami-ai-assistant' ), 'min' => 0, 'max' => 1, 'step' => 0.05 );
		$fields[] = array( 'key' => 'search.keyword_weight', 'type' => 'number', 'label' => __( 'وزن کلیدواژه', 'hami-ai-assistant' ), 'min' => 0, 'max' => 1, 'step' => 0.05 );
		$fields[] = array( 'key' => 'search.chunk_cap', 'type' => 'number', 'label' => __( 'حداکثر کاراکتر هر تکه', 'hami-ai-assistant' ), 'min' => 200, 'max' => 20000 );
		$fields[] = array( 'key' => 'search.max_vectors', 'type' => 'number', 'label' => __( 'حداکثر تعداد بردار', 'hami-ai-assistant' ), 'min' => 100, 'max' => 100000 );
		return $fields;
	}

	/**
	 * @param string $parent Parent slug.
	 * @return void
	 */
	public function register_menu( $parent ) {
		add_submenu_page(
			$parent,
			__( 'جست‌وجوی هوشمند', 'hami-ai-assistant' ),
			__( 'جست‌وجوی هوشمند', 'hami-ai-assistant' ),
			'manage_options',
			Haa_Util::screen( 'semantic-search' ),
			array( $this, 'render_screen' )
		);
	}

	/* ------------------------------------------------------------------
	 * Screen
	 * ---------------------------------------------------------------- */

	/** @return void */
	public function render_screen() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$stats = Haa_Search::stats();
		$state = Haa_Search::rebuild_progress();

		// Live sample so an admin can sanity-check quality before shipping.
		$sample = '';
		if ( isset( $_GET['haai_q'] ) ) {
			$q = sanitize_text_field( wp_unslash( $_GET['haai_q'] ) );
			if ( '' !== $q ) {
				$results = Haa_Search::search( $q, 10, 6000 );
				$sample  = '';
				foreach ( $results as $r ) {
					$sample .= sprintf(
						'<li><strong>%.3f</strong> %s <small>(%s %d)</small></li>',
						(float) $r['score'],
						esc_html( $r['title'] ),
						esc_html( (string) get_post_type( (int) $r['post_id'] ) ),
						(int) $r['post_id']
					);
				}
			}
		}
		?>
		<div class="wrap haai-wrap">
			<?php Haa_Admin::page_header( __( 'جست‌وجوی هوشمند', 'hami-ai-assistant' ) ); ?>

			<?php
			$msg = isset( $_GET['haai_msg'] ) ? sanitize_text_field( wp_unslash( $_GET['haai_msg'] ) ) : '';
			if ( '' !== $msg ) {
				printf( '<div class="notice notice-success inline"><p>%s</p></div>', esc_html( $msg ) );
			}
			if ( ! empty( $state['running'] ) ) {
				printf(
					'<div class="notice notice-info inline"><p>%s %d%% (%d / %d)</p></div>',
					'vectors' === ( $state['phase'] ?? '' )
						? esc_html__( 'ساخت بردارها در حال اجراست:', 'hami-ai-assistant' )
						: esc_html__( 'بازسازی در حال اجراست:', 'hami-ai-assistant' ),
					(int) $state['percent'],
					(int) $state['offset'],
					(int) $state['total']
				);
			}
			if ( ! empty( $state['last_error'] ) ) {
				printf(
					'<div class="notice notice-error inline"><p>%s %s</p></div>',
					esc_html__( 'خطا در ساخت بردارها:', 'hami-ai-assistant' ),
					esc_html( (string) $state['last_error'] )
				);
			}
			if ( ! empty( $state['capped'] ) ) {
				printf(
					'<div class="notice notice-warning inline"><p>%s</p></div>',
					esc_html( ! empty( $state['notice'] ) ? (string) $state['notice'] : __( 'به سقف «حداکثر تعداد بردار» رسیدید. برای ادامه، مقدار این سقف را بیشتر کنید و دوباره «ادامه‌ی ساخت» را بزنید.', 'hami-ai-assistant' ) )
				);
			}
			?>

			<div class="haai-cards">
				<div class="haai-card">
					<div class="haai-card__value"><?php echo esc_html( number_format_i18n( (int) $stats['items'] ) ); ?></div>
					<div class="haai-card__label"><?php esc_html_e( 'مستندات ایندکس‌شده', 'hami-ai-assistant' ); ?></div>
				</div>
				<div class="haai-card">
					<div class="haai-card__value"><?php echo esc_html( number_format_i18n( (int) $stats['chunks'] ) ); ?></div>
					<div class="haai-card__label"><?php esc_html_e( 'تکه‌های متن', 'hami-ai-assistant' ); ?></div>
				</div>
				<div class="haai-card">
					<div class="haai-card__value"><?php echo esc_html( number_format_i18n( (int) $stats['vectors'] ) ); ?></div>
					<div class="haai-card__label"><?php esc_html_e( 'بردارها', 'hami-ai-assistant' ); ?></div>
				</div>
			</div>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="haai-actions" data-haai-ss-form>
				<?php wp_nonce_field( 'haai_ss' ); ?>
				<input type="hidden" name="action" value="haai_ss_rebuild">
				<button type="submit" class="button button-primary" data-haai-ss-start>
					<?php echo empty( $state['running'] ) ? esc_html__( 'شروع بازسازی و ساخت بردارها', 'hami-ai-assistant' ) : esc_html__( 'ادامه‌ی ساخت', 'hami-ai-assistant' ); ?>
				</button>
				<button type="button" class="button" data-haai-ss-stop hidden><?php esc_html_e( 'توقف', 'hami-ai-assistant' ); ?></button>
			</form>

			<div class="haai-progress" data-haai-ss-progress<?php echo empty( $state['running'] ) ? ' hidden' : ''; ?>>
				<div class="haai-progress__bar" data-haai-ss-bar style="width:<?php echo (int) $state['percent']; ?>%"></div>
			</div>
			<p class="description" data-haai-ss-status>
				<?php esc_html_e( 'ساخت ایندکس دسته‌ای و با نوار پیشرفت انجام می‌شود. اگر جست‌وجوی برداری فعال باشد، پس از تکه‌ها بردارها هم ساخته می‌شوند و جست‌وجوی Hybrid از آن‌ها استفاده می‌کند؛ در غیر این صورت به BM25 برمی‌گردد.', 'hami-ai-assistant' ); ?>
			</p>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'haai_ss' ); ?>
				<input type="hidden" name="action" value="haai_ss_reset">
				<button type="submit" class="button" data-haai-confirm><?php esc_html_e( 'پاک‌سازی کامل', 'hami-ai-assistant' ); ?></button>
			</form>

			<script>
			(function () {
				var form = document.querySelector('[data-haai-ss-form]');
				if (!form || !window.haaiAdmin) return;
				var start = form.querySelector('[data-haai-ss-start]');
				var stop = form.querySelector('[data-haai-ss-stop]');
				var wrap = document.querySelector('[data-haai-ss-progress]');
				var bar = document.querySelector('[data-haai-ss-bar]');
				var status = document.querySelector('[data-haai-ss-status]');
				var running = false;

				function setBar(p) { if (bar) bar.style.width = Math.max(0, Math.min(100, p)) + '%'; }
				function say(t) { if (status) status.textContent = t; }

				function tick() {
					if (!running) return;
					var body = new FormData();
					body.append('action', 'haai_ss_tick');
					body.append('nonce', haaiAdmin.nonce);
					fetch(haaiAdmin.ajax, { method: 'POST', credentials: 'same-origin', body: body })
						.then(function (r) { return r.json(); })
						.then(function (d) {
							if (!running) return;
							var p = d && d.progress ? d.progress : null;
							if (!p) { stopRun(); say(haaiAdmin.i18n.fail); return; }
							setBar(p.percent);
							say(('vectors' === p.phase ? 'ساخت بردارها: ' : 'ایندکس‌گذاری: ') + p.percent + '% (' + p.offset + ' / ' + p.total + ')');
							if (p.last_error) { stopRun(); say(p.last_error); return; }
							if (p.capped) { stopRun(); setBar(100); say(p.notice || 'به سقف حداکثر تعداد بردار رسید؛ مقدار سقف را بیشتر کنید و دوباره ادامه دهید.'); return; }
							if (p.running) { setTimeout(tick, 400); }
							else { stopRun(); say('تمام شد — تکه‌ها: ' + (p.stats ? p.stats.chunks : 0) + '، بردارها: ' + (p.stats ? p.stats.vectors : 0)); }
						})
						.catch(function () { stopRun(); say(haaiAdmin.i18n.fail); });
				}

				function startRun() {
					running = true;
					if (wrap) wrap.hidden = false;
					if (stop) stop.hidden = false;
					if (start) start.disabled = true;
					tick();
				}
				function stopRun() {
					running = false;
					if (stop) stop.hidden = true;
					if (start) start.disabled = false;
				}

				// First batch via the classic POST (starts the cursor), then
				// hand the remaining batches to the AJAX ticker.
				form.addEventListener('submit', function (e) {
					e.preventDefault();
					if (running) return;
					var body = new FormData(form);
					fetch(form.action, { method: 'POST', credentials: 'same-origin', body: body, redirect: 'follow' })
						.then(function () { startRun(); })
						.catch(function () { startRun(); });
				});
				if (stop) stop.addEventListener('click', stopRun);
			})();
			</script>

			<h2><?php esc_html_e( 'آزمون جست‌وجو', 'hami-ai-assistant' ); ?></h2>
			<form method="get">
				<input type="hidden" name="page" value="<?php echo esc_attr( Haa_Util::screen( 'semantic-search' ) ); ?>">
				<input type="search" name="haai_q" class="regular-text" placeholder="<?php esc_attr_e( 'مثلاً: کفش ورزشی مردانه', 'hami-ai-assistant' ); ?>" value="<?php echo esc_attr( isset( $_GET['haai_q'] ) ? sanitize_text_field( wp_unslash( $_GET['haai_q'] ) ) : '' ); ?>">
				<button type="submit" class="button"><?php esc_html_e( 'جست‌وجو', 'hami-ai-assistant' ); ?></button>
			</form>

			<?php if ( '' !== $sample ) : ?>
				<ol><?php echo wp_kses_post( $sample ); ?></ol>
			<?php endif; ?>

			<p class="description">
				<?php
				printf(
					/* translators: 1: shortcode. 2: shortcode. */
					esc_html__( 'برای نمایش کادر جست‌وجو در هر صفحه‌ای از %1$s استفاده کنید؛ دکمه «جست‌وجو» در کادر چت هم همین سرویس را صدا می‌زند.', 'hami-ai-assistant' ),
					'<code>[haai_search]</code>',
					'<code>[haai_search]</code>'
				);
				?>
			</p>
		</div>
		<?php
	}

	/** @return void */
	public function handle_rebuild() {
		Haa_Util::guard_admin_post( 'haai_ss' );
		Haa_Search::rebuild_start();
		// Do a first batch right away so a small site finishes in one click.
		Haa_Search::rebuild_batch( 15 );
		$this->back( __( 'بازسازی ایندکس شروع شد.', 'hami-ai-assistant' ) );
	}

	/** @return void */
	public function handle_batch() {
		Haa_Util::guard_admin_post( 'haai_ss' );
		Haa_Search::rebuild_batch( 15 );
		$this->back( __( 'دسته بعدی ایندکس شد.', 'hami-ai-assistant' ) );
	}

	/** @return void */
	public function handle_reset() {
		Haa_Util::guard_admin_post( 'haai_ss' );
		Haa_Search::reset();
		$this->back( __( 'ایندکس پاک شد.', 'hami-ai-assistant' ) );
	}

	/* ------------------------------------------------------------------
	 * Frontend
	 * ---------------------------------------------------------------- */

	/**
	 * `[haai_search]` — a small, self-contained search box. The form posts
	 * straight to `admin-ajax.php?action=haai_search` so we do not depend on
	 * the REST nonce for anonymous visitors.
	 *
	 * Static and return-based: shortcode callbacks must return their markup
	 * (echoing breaks the surrounding content order) and the plugin wrapper
	 * calls this method statically.
	 *
	 * @param int|array $limit Max results (0 = settings value) or shortcode atts.
	 * @return string
	 */
	public static function render_form( $limit = 0 ) {
		if ( ! Haa_Settings::is_module_enabled( 'semantic-search' ) || empty( Haa_Settings::get( 'search.enabled' ) ) ) {
			return '';
		}
		if ( is_admin() ) {
			return '';
		}

		if ( is_array( $limit ) ) {
			$atts  = shortcode_atts( array( 'limit' => 0 ), $limit, 'haai_search' );
			$limit = (int) $atts['limit'];
		} else {
			$limit = (int) $limit;
		}
		$limit = $limit ? $limit : (int) Haa_Settings::get( 'search.limit', 10 );

		ob_start();
		?>
		<div class="haai-search" data-haai-search data-limit="<?php echo esc_attr( $limit ); ?>">
			<form class="haai-search__form" method="get" action="<?php echo esc_url( home_url( '/' ) ); ?>" role="search">
				<label class="screen-reader-text" for="haai-search-input"><?php esc_html_e( 'جست‌وجو', 'hami-ai-assistant' ); ?></label>
				<input type="search" id="haai-search-input" class="haai-search__input" name="s" placeholder="<?php esc_attr_e( 'دنبال چی می‌گردی؟', 'hami-ai-assistant' ); ?>" autocomplete="off">
				<button type="submit" class="haai-search__button"><?php esc_html_e( 'جست‌وجو', 'hami-ai-assistant' ); ?></button>
			</form>
			<ul class="haai-search__results" hidden></ul>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/** @return void */
	private function back( $message = '' ) {
		$args = array();
		if ( '' !== $message ) {
			$args['haai_msg'] = rawurlencode( Haa_Util::cut( $message, 300 ) );
		}
		wp_safe_redirect( Haa_Util::screen_url( 'semantic-search', $args ) );
		exit;
	}
}
