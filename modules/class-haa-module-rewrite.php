<?php
/**
 * Rewrite module: rework an EXISTING post, page or product with the model.
 *
 * The generate step never touches the post: it stores an editable preview in a
 * two-hour transient and shows it on the module screen. Only «اعمال روی نوشته»
 * writes, and it goes through wp_update_post() so WordPress keeps a revision.
 *
 * @package Hami_AI_Assistant
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Haa_Module_Rewrite implements Haa_Module_Interface {

	/** Preview transient prefix (per post, per user). */
	const DRAFT_PREFIX = 'haai_rw_draft_';

	/** Marker written on the post after an approved rewrite. */
	const META_AT = '_haai_rewrite_at';

	/** Last failure, per user (shown in the panel so the message is never generic). */
	const FAIL_KEY = 'haai_rw_fail_';

	/**
	 * One rewrite is a single long call. Cap it well under the host's PHP limit:
	 * 45s and no retry, because two timeouts in a row is what turned a slow
	 * provider into «پاسخ نامعتبر» (the request died before printing JSON).
	 */
	const CALL_TIMEOUT = 45;

	/** Words per chunk: one chunk must finish inside CALL_TIMEOUT. */
	const CHUNK_WORDS = 700;

	/** Longest body sent to the model, in characters. */
	const MAX_INPUT = 24000;

	/** @return string */
	public function slug() {
		return 'rewrite';
	}

	/** @return string */
	public function label() {
		return __( 'بازنویسی با AI', 'hami-ai-assistant' );
	}

	/** @return string */
	public function description() {
		return __( 'نوشته، برگه یا محصولی که از قبل هست را بازنویسی می‌کند: پیش‌نمایش قابل ویرایش، و به‌روزرسانی فقط بعد از تأیید شما.', 'hami-ai-assistant' );
	}

	/** @return bool */
	public function requires_woocommerce() {
		return false;
	}

	/** @return void */
	public function register() {
		add_action( 'haai_settings_sections', array( $this, 'register_section' ), 10 );
		add_action( 'haai_settings_fields_rewrite', array( $this, 'register_fields' ) );
		add_action( 'haai_admin_menus', array( $this, 'register_menu' ) );
		add_action( 'admin_post_haai_rw_generate', array( $this, 'handle_generate' ) );
		add_action( 'admin_post_haai_rw_apply', array( $this, 'handle_apply' ) );
		add_action( 'admin_post_haai_rw_discard', array( $this, 'handle_discard' ) );
		add_action( 'add_meta_boxes', array( $this, 'meta_box' ) );
		add_filter( 'post_row_actions', array( $this, 'row_action' ), 10, 2 );
		add_filter( 'page_row_actions', array( $this, 'row_action' ), 10, 2 );
	}

	/** @return void */
	public function activate() {
	}

	/** @return void */
	public function deactivate() {
	}

	/* ------------------------------------------------------------------
	 * Settings
	 * ---------------------------------------------------------------- */

	/**
	 * Post types this module may rewrite.
	 *
	 * @return array<int,string>
	 */
	private static function types() {
		$raw = (array) Haa_Settings::get( 'rewrite.post_types', array( 'post', 'page' ) );
		$raw = array_values( array_intersect( array( 'post', 'page', 'product' ), array_map( 'strval', $raw ) ) );
		if ( in_array( 'product', $raw, true ) && ! haa_is_woo() ) {
			$raw = array_values( array_diff( $raw, array( 'product' ) ) );
		}
		return $raw ? $raw : array( 'post' );
	}

	/**
	 * @param array $sections Tabs so far.
	 * @return array
	 */
	public function register_section( $sections ) {
		$sections['rewrite'] = __( 'بازنویسی', 'hami-ai-assistant' );
		return $sections;
	}

	/**
	 * @param array $fields Fields so far.
	 * @return array
	 */
	public function register_fields( $fields ) {
		$fields[] = array(
			'key'     => 'rewrite.post_types',
			'type'    => 'chipbox',
			'label'   => __( 'کدام انواع محتوا بازنویسی شوند', 'hami-ai-assistant' ),
			'choices' => array(
				'post'    => __( 'نوشته‌ها', 'hami-ai-assistant' ),
				'page'    => __( 'برگه‌ها', 'hami-ai-assistant' ),
				'product' => __( 'محصولات ووکامرس', 'hami-ai-assistant' ),
			),
			'hint'    => __( 'دکمه‌ی «بازنویسی با AI» فقط روی همین انواع دیده می‌شود. لحن و طول از «محتوا و تصویر» خوانده می‌شود.', 'hami-ai-assistant' ),
		);
		$fields[] = array(
			'key'   => 'rewrite.instructions',
			'type'  => 'textarea',
			'label' => __( 'دستور ثابت بازنویسی', 'hami-ai-assistant' ),
			'hint'  => __( 'هر بار به پرامپت اضافه می‌شود؛ مثلاً «لحن رسمی‌تر» یا «اصطلاحات محلی را حذف کن». خالی بگذارید تا فقط قواعد پایه اجرا شود.', 'hami-ai-assistant' ),
		);
		return $fields;
	}

	/**
	 * @param string $parent Parent menu slug.
	 * @return void
	 */
	public function register_menu( $parent ) {
		add_submenu_page(
			$parent,
			__( 'بازنویسی با AI', 'hami-ai-assistant' ),
			__( 'بازنویسی', 'hami-ai-assistant' ),
			'manage_options',
			Haa_Util::screen( 'rewrite' ),
			array( $this, 'render_screen' )
		);
	}

	/* ------------------------------------------------------------------
	 * Entry points: meta box + list row action
	 * ---------------------------------------------------------------- */

	/**
	 * @param string $post_type Current screen's post type.
	 * @return void
	 */
	public function meta_box( $post_type ) {
		if ( ! in_array( (string) $post_type, self::types(), true ) ) {
			return;
		}
		add_meta_box(
			'haai-rewrite',
			__( 'بازنویسی با AI', 'hami-ai-assistant' ),
			array( $this, 'render_meta_box' ),
			$post_type,
			'side',
			'high'
		);
	}

	/**
	 * @param WP_Post $post Post being edited.
	 * @return void
	 */
	public function render_meta_box( $post ) {
		$at = (int) get_post_meta( (int) $post->ID, self::META_AT, true );
		if ( $at ) {
			printf(
				'<p class="description">%s</p>',
				esc_html(
					sprintf(
						/* translators: %s: date. */
						__( 'آخرین بازنویسی: %s', 'hami-ai-assistant' ),
						wp_date( 'Y-m-d H:i', $at )
					)
				)
			);
		}
		printf(
			'<p><a class="button" href="%1$s">%2$s</a></p><p class="description">%3$s</p>',
			esc_url( self::url( (int) $post->ID ) ),
			esc_html__( 'بازنویسی و پیش‌نمایش', 'hami-ai-assistant' ),
			esc_html__( 'متن ذخیره‌شده بازنویسی می‌شود؛ روی همین صفحه چیزی عوض نمی‌شود و اول پیش‌نمایش را می‌بینید.', 'hami-ai-assistant' )
		);
	}

	/**
	 * Adds «بازنویسی با AI» next to ویرایش / ویرایش سریع / انتقال به زباله‌دان.
	 *
	 * @param array   $actions Row actions.
	 * @param WP_Post $post    Row post.
	 * @return array
	 */
	public function row_action( $actions, $post ) {
		if ( ! $post instanceof WP_Post || ! in_array( (string) $post->post_type, self::types(), true ) ) {
			return $actions;
		}
		if ( ! current_user_can( 'edit_post', (int) $post->ID ) ) {
			return $actions;
		}
		$actions['haai_rewrite'] = sprintf(
			'<a href="%1$s">%2$s</a>',
			esc_url( self::url( (int) $post->ID ) ),
			esc_html__( 'بازنویسی با AI', 'hami-ai-assistant' )
		);
		return $actions;
	}

	/**
	 * Screen URL, optionally for one post.
	 *
	 * @param int   $post_id Post id (0 = list view).
	 * @param array $args    Extra query args.
	 * @return string
	 */
	private static function url( $post_id = 0, $args = array() ) {
		$args = array_merge( array( 'page' => Haa_Util::screen( 'rewrite' ) ), $args );
		if ( $post_id ) {
			$args['post'] = (int) $post_id;
		}
		return add_query_arg( $args, admin_url( 'admin.php' ) );
	}

	/* ------------------------------------------------------------------
	 * Screen
	 * ---------------------------------------------------------------- */

	/** @return void */
	public function render_screen() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$pid  = isset( $_GET['post'] ) ? (int) $_GET['post'] : 0;
		$post = $pid ? get_post( $pid ) : null;
		$msg  = isset( $_GET['haai_msg'] ) ? sanitize_text_field( wp_unslash( $_GET['haai_msg'] ) ) : '';
		$err  = isset( $_GET['haai_err'] ) ? sanitize_text_field( wp_unslash( $_GET['haai_err'] ) ) : '';
		?>
		<div class="wrap haai-wrap">
			<?php Haa_Admin::page_header( __( 'بازنویسی با AI', 'hami-ai-assistant' ), 'rewrite' ); ?>

			<?php if ( '' !== $err ) : ?>
				<div class="notice notice-error inline"><p><?php echo esc_html( $err ); ?></p></div>
			<?php endif; ?>
			<?php if ( '' !== $msg ) : ?>
				<div class="notice notice-success inline"><p><?php echo esc_html( $msg ); ?></p></div>
			<?php endif; ?>

			<p class="haai-panel__note">
				<?php esc_html_e( 'متن فعلی خوانده می‌شود، بازنویسی می‌شود و به‌صورت پیش‌نمایش نشان داده می‌شود. تا «اعمال روی نوشته» را نزنید هیچ‌چیز عوض نمی‌شود؛ بعد از اعمال، همان نوشته یک نسخه‌ی قبلی در «بازبینی» وردپرس دارد.', 'hami-ai-assistant' ); ?>
			</p>

			<?php
			if ( $post && in_array( (string) $post->post_type, self::types(), true ) ) {
				$this->render_editor( $post );
			} else {
				$this->render_list();
			}
			?>
		</div>
		<?php
	}

	/**
	 * Picker of existing content.
	 *
	 * @return void
	 */
	private function render_list() {
		$search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		$rows   = get_posts(
			array(
				'post_type'           => self::types(),
				'post_status'         => array( 'publish', 'draft', 'pending', 'private' ),
				'posts_per_page'      => 20,
				's'                   => $search,
				'orderby'             => 'modified',
				'order'               => 'DESC',
				'ignore_sticky_posts' => true,
				'no_found_rows'       => true,
			)
		);
		?>
		<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" class="haai-filterbar">
			<input type="hidden" name="page" value="<?php echo esc_attr( Haa_Util::screen( 'rewrite' ) ); ?>" />
			<input type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'جست‌وجوی عنوان…', 'hami-ai-assistant' ); ?>" />
			<button type="submit" class="button"><?php esc_html_e( 'جست‌وجو', 'hami-ai-assistant' ); ?></button>
		</form>

		<?php if ( ! $rows ) : ?>
			<div class="haai-alert haai-alert--info">
				<?php esc_html_e( 'چیزی پیدا نشد. نوع محتوا را در تنظیمات ← بازنویسی بررسی کنید.', 'hami-ai-assistant' ); ?>
			</div>
		<?php else : ?>
			<table class="widefat striped haai-queuetable">
				<thead>
					<tr>
						<th><?php esc_html_e( 'عنوان', 'hami-ai-assistant' ); ?></th>
						<th><?php esc_html_e( 'نوع', 'hami-ai-assistant' ); ?></th>
						<th><?php esc_html_e( 'وضعیت', 'hami-ai-assistant' ); ?></th>
						<th><?php esc_html_e( 'کلمه', 'hami-ai-assistant' ); ?></th>
						<th><?php esc_html_e( 'آخرین بازنویسی', 'hami-ai-assistant' ); ?></th>
						<th></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $rows as $row ) : ?>
						<?php $at = (int) get_post_meta( (int) $row->ID, self::META_AT, true ); ?>
						<tr>
							<td><strong><?php echo esc_html( get_the_title( $row ) ); ?></strong></td>
							<td><?php echo esc_html( (string) $row->post_type ); ?></td>
							<td><?php echo esc_html( get_post_status_object( (string) $row->post_status )->label ?? (string) $row->post_status ); ?></td>
							<td><?php echo esc_html( (string) self::words( (string) $row->post_content ) ); ?></td>
							<td><?php echo $at ? esc_html( wp_date( 'Y-m-d', $at ) ) : '—'; ?></td>
							<td>
								<a class="button button-primary" href="<?php echo esc_url( self::url( (int) $row->ID ) ); ?>">
									<?php esc_html_e( 'بازنویسی', 'hami-ai-assistant' ); ?>
								</a>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
		<?php
	}

	/**
	 * One post: stats, the run form, and the pending preview.
	 *
	 * @param WP_Post $post Post.
	 * @return void
	 */
	private function render_editor( $post ) {
		$html    = (string) $post->post_content;
		$words   = self::words( $html );
		$draft   = get_transient( self::draft_key( (int) $post->ID ) );
		$fail    = get_transient( self::FAIL_KEY . (int) get_current_user_id() );
		$state   = get_transient( self::state_key( (int) $post->ID ) );
		$edit    = get_edit_post_link( (int) $post->ID, 'raw' );
		$view    = get_permalink( (int) $post->ID );
		$types   = self::types();
		?>
		<div id="haai-rewrite-panel">
		<?php if ( is_string( $fail ) && '' !== $fail ) : ?>
			<div class="haai-alert haai-alert--warn">
				<?php echo esc_html( $fail ); ?>
			</div>
		<?php endif; ?>
		<?php if ( is_array( $state ) && ! empty( $state['chunks'] ) ) : ?>
			<div class="haai-alert haai-alert--info">
				<?php
				printf(
					/* translators: 1: done, 2: total. */
					esc_html__( 'بازنویسی بخش‌به‌بخش: %1$d از %2$d بخش تمام شده. هر بخش یک درخواست کوتاه است تا از مهلت PHP رد نشود.', 'hami-ai-assistant' ),
					(int) $state['index'],
					count( (array) $state['chunks'] )
				);
				?>
			</div>
		<?php endif; ?>
		<p class="haai-plan__meta">
			<span><?php echo esc_html( get_the_title( $post ) ); ?></span>
			<span><?php echo esc_html( (string) $post->post_type ); ?></span>
			<span>
				<?php
				printf(
					/* translators: %d: word count. */
					esc_html__( '%d کلمه', 'hami-ai-assistant' ),
					(int) $words
				);
				?>
			</span>
			<?php if ( $edit ) : ?>
				<a href="<?php echo esc_url( $edit ); ?>"><?php esc_html_e( 'ویرایش در وردپرس', 'hami-ai-assistant' ); ?></a>
			<?php endif; ?>
			<?php if ( $view ) : ?>
				<a href="<?php echo esc_url( $view ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'نمایش', 'hami-ai-assistant' ); ?></a>
			<?php endif; ?>
		</p>

		<?php if ( '' === trim( wp_strip_all_tags( $html ) ) ) : ?>
			<div class="haai-alert haai-alert--warn">
				<?php esc_html_e( 'این نوشته متن ندارد؛ چیزی برای بازنویسی نیست.', 'hami-ai-assistant' ); ?>
			</div>
		<?php else : ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="haai-panel" data-haai-rw-run="1" data-haai-busy="در حال بازنویسی… این پنجره را نبندید.">
				<?php wp_nonce_field( 'haai_rw' ); ?>
				<input type="hidden" name="action" value="haai_rw_generate" />
				<input type="hidden" name="post_id" value="<?php echo (int) $post->ID; ?>" />

				<h2 class="haai-panel__title"><?php esc_html_e( 'بازنویسی', 'hami-ai-assistant' ); ?></h2>
				<p class="haai-field__hint">
					<?php
					printf(
						/* translators: 1: tone, 2: target words. */
						esc_html__( 'با لحن «%1$s» و حدود %2$d کلمه (از تنظیمات محتوا). داده‌های واقعی متن حفظ می‌شوند؛ چیز تازه‌ای ساخته نمی‌شود.', 'hami-ai-assistant' ),
						esc_html( self::tone_label() ),
						(int) self::target_words( $words )
					);
					?>
				</p>
				<textarea name="instructions" rows="2" class="large-text" placeholder="<?php esc_attr_e( 'دستور این نوبت (اختیاری): مثلاً «مقدمه را کوتاه‌تر کن».', 'hami-ai-assistant' ); ?>"></textarea>
				<p class="haai-plan__actions">
					<button type="submit" class="button button-primary">
						<?php echo ( is_array( $state ) && ! empty( $state['chunks'] ) ) ? esc_html__( 'ادامه‌ی بازنویسی', 'hami-ai-assistant' ) : esc_html__( 'ساخت پیش‌نمایش', 'hami-ai-assistant' ); ?>
					</button>
				</p>
			</form>
		<?php endif; ?>

		<?php if ( is_array( $draft ) && ! empty( $draft['html'] ) ) : ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="haai-panel haai-panel--review">
				<?php wp_nonce_field( 'haai_rw' ); ?>
				<input type="hidden" name="action" value="haai_rw_apply" />
				<input type="hidden" name="post_id" value="<?php echo (int) $post->ID; ?>" />

				<h2 class="haai-panel__title">
					<?php esc_html_e( 'پیش‌نمایش بازنویسی', 'hami-ai-assistant' ); ?>
					<span class="haai-badge haai-badge--accent">
						<?php
						printf(
							/* translators: 1: before, 2: after. */
							esc_html__( '%1$d → %2$d کلمه', 'hami-ai-assistant' ),
							(int) ( $draft['words_before'] ?? 0 ),
							(int) ( $draft['words_after'] ?? 0 )
						);
						?>
					</span>
				</h2>
				<p class="haai-field__hint"><?php esc_html_e( 'هر جا لازم است ویرایش کنید؛ همین متن ذخیره می‌شود. تا دکمه‌ی «اعمال روی نوشته» را نزنید، نوشته دست‌نخورده می‌ماند.', 'hami-ai-assistant' ); ?></p>

				<p>
					<label class="haai-field__hint" for="haai-rw-title"><?php esc_html_e( 'عنوان', 'hami-ai-assistant' ); ?></label>
					<input type="text" id="haai-rw-title" name="title" class="large-text" value="<?php echo esc_attr( (string) ( $draft['title'] ?? get_the_title( $post ) ) ); ?>" />
				</p>
				<p>
					<label class="haai-field__hint" for="haai-rw-html"><?php esc_html_e( 'متن (HTML)', 'hami-ai-assistant' ); ?></label>
					<textarea id="haai-rw-html" name="html" rows="18" class="large-text code"><?php echo esc_textarea( (string) $draft['html'] ); ?></textarea>
				</p>

				<?php if ( ! empty( $draft['notes'] ) ) : ?>
					<div class="haai-alert haai-alert--info">
						<?php echo esc_html( (string) $draft['notes'] ); ?>
					</div>
				<?php endif; ?>

				<p class="haai-plan__actions">
					<button type="submit" class="button button-primary"><?php esc_html_e( 'اعمال روی نوشته', 'hami-ai-assistant' ); ?></button>
					<button type="submit" class="button button-link-delete" name="discard" value="1" formnovalidate onclick="return confirm('<?php echo esc_js( __( 'پیش‌نمایش دور انداخته شود؟', 'hami-ai-assistant' ) ); ?>');">
						<?php esc_html_e( 'دور انداختن', 'hami-ai-assistant' ); ?>
					</button>
				</p>
			</form>
		<?php endif; ?>
		</div>
		<?php
	}

	/* ------------------------------------------------------------------
	 * Handlers
	 * ---------------------------------------------------------------- */

	/** @return void */
	public function handle_generate() {
		$this->arm_fatal_guard();
		Haa_Util::guard_admin_post( 'haai_rw' );

		$post = $this->posted_post();
		if ( is_wp_error( $post ) ) {
			$this->back( $post->get_error_message(), true, 0 );
		}
		if ( '' === trim( wp_strip_all_tags( (string) $post->post_content ) ) ) {
			$this->back( __( 'این نوشته متن ندارد؛ چیزی برای بازنویسی نیست.', 'hami-ai-assistant' ), true, (int) $post->ID );
		}
		if ( '' === (string) ( Haa_Settings::provider()['api_key'] ?? '' ) ) {
			$this->back( __( 'کلید API تنظیم نشده است؛ تب «سرویس AI» را کامل کنید.', 'hami-ai-assistant' ), true, (int) $post->ID );
		}

		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 120 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}

		$extra = isset( $_POST['instructions'] ) ? sanitize_textarea_field( wp_unslash( $_POST['instructions'] ) ) : '';

		/*
		 * A 2400-word article cannot be rewritten inside one request: the output alone
		 * is thousands of tokens, and the host kills PHP long before the provider
		 * answers (that is what «پاسخ نامعتبر» was). So the body is split into chunks
		 * and ONE chunk is rewritten per request; admin.js resubmits until the panel
		 * says it is finished. Every request stays well under the PHP limit.
		 */
		$state = get_transient( self::state_key( (int) $post->ID ) );
		if ( ! is_array( $state ) || empty( $state['chunks'] ) ) {
			$chunks = self::split_chunks( (string) $post->post_content );
			if ( ! $chunks ) {
				$this->back( __( 'متنی برای بازنویسی پیدا نشد.', 'hami-ai-assistant' ), true, (int) $post->ID );
			}
			$state = array(
				'chunks'       => $chunks,
				'index'        => 0,
				'done'         => array(),
				'instructions' => $extra,
				'before'       => self::words( (string) $post->post_content ),
				'made'         => time(),
			);
		}

		$total = count( $state['chunks'] );

		// A chunk that is only a heading, a caption or a table row is not worth a
		// model call — passing it through costs nothing and removes the «پاسخ خالی»
		// failure those fragments used to cause.
		while ( $state['index'] < $total && self::words( (string) $state['chunks'][ $state['index'] ] ) < 15 ) {
			$state['done'][] = (string) $state['chunks'][ $state['index'] ];
			$state['index']++;
		}

		if ( $state['index'] < $total ) {
			$piece = self::rewrite_chunk( $post, (string) $state['chunks'][ $state['index'] ], (string) $state['instructions'] );

			if ( is_wp_error( $piece ) ) {
				$this->remember_failure( $piece->get_error_message() );
				set_transient( self::state_key( (int) $post->ID ), $state, 2 * HOUR_IN_SECONDS );
				$this->respond_with_panel( $post, array( 'finished' => false, 'error' => true ) );
				$this->back( $piece->get_error_message(), true, (int) $post->ID );
			}

			$state['done'][] = $piece;
			$state['index']++;
		}

		if ( $state['index'] < $total ) {
			set_transient( self::state_key( (int) $post->ID ), $state, 2 * HOUR_IN_SECONDS );
			$this->respond_with_panel(
				$post,
				array( 'done' => (int) $state['index'], 'total' => (int) $total, 'finished' => false )
			);
			$this->back(
				sprintf(
					/* translators: 1: done, 2: total. */
					__( 'بخش %1$d از %2$d بازنویسی شد؛ ادامه می‌دهد…', 'hami-ai-assistant' ),
					(int) $state['index'],
					(int) $total
				),
				false,
				(int) $post->ID
			);
		}

		$html  = trim( implode( "\n", array_map( 'strval', (array) $state['done'] ) ) );
		$draft = array(
			'html'         => $html,
			'title'        => (string) get_the_title( $post ),
			'words_before' => (int) $state['before'],
			'words_after'  => self::words( $html ),
			'made'         => time(),
			'notes'        => '',
		);

		delete_transient( self::state_key( (int) $post->ID ) );
		delete_transient( self::FAIL_KEY . (int) get_current_user_id() );
		set_transient( self::draft_key( (int) $post->ID ), $draft, 2 * HOUR_IN_SECONDS );
		$this->respond_with_panel( $post, array( 'finished' => true ) );
		$this->back( __( 'پیش‌نمایش کامل شد؛ بررسی و ویرایش کنید و بعد اعمال بزنید.', 'hami-ai-assistant' ), false, (int) $post->ID );
	}

	/** @return void */
	public function handle_apply() {
		$this->arm_fatal_guard();
		Haa_Util::guard_admin_post( 'haai_rw' );

		$post = $this->posted_post();
		if ( is_wp_error( $post ) ) {
			$this->back( $post->get_error_message(), true, 0 );
		}

		// «دور انداختن» از همین فرم می‌آید تا یک دکمه و یک nonce کافی باشد.
		if ( ! empty( $_POST['discard'] ) ) {
			delete_transient( self::draft_key( (int) $post->ID ) );
			$this->back( __( 'پیش‌نمایش دور انداخته شد؛ نوشته دست‌نخورده است.', 'hami-ai-assistant' ), false, (int) $post->ID );
		}

		$draft = get_transient( self::draft_key( (int) $post->ID ) );
		if ( ! is_array( $draft ) || empty( $draft['html'] ) ) {
			$this->back( __( 'پیش‌نمایشی پیدا نشد (منقضی شده است). دوباره بازنویسی را بزنید.', 'hami-ai-assistant' ), true, (int) $post->ID );
		}

		$html = isset( $_POST['html'] ) ? wp_kses_post( wp_unslash( $_POST['html'] ) ) : '';
		$html = trim( (string) $html );
		if ( '' === $html ) {
			$this->back( __( 'متن پیش‌نمایش خالی است؛ چیزی ذخیره نشد.', 'hami-ai-assistant' ), true, (int) $post->ID );
		}

		// A rewrite that lost most of the article is a failure, not an edit:
		// refuse instead of silently replacing a 3000-word page with 200 words.
		$before = self::words( (string) $post->post_content );
		$after  = self::words( $html );
		if ( $before > 200 && $after < (int) floor( $before * 0.5 ) ) {
			$this->back(
				sprintf(
					/* translators: 1: before, 2: after. */
					__( 'متن تازه خیلی کوتاه‌تر است (%1$d → %2$d کلمه)؛ برای جلوگیری از خرابی ذخیره نشد. دوباره بسازید یا دستی ویرایش کنید.', 'hami-ai-assistant' ),
					(int) $before,
					(int) $after
				),
				true,
				(int) $post->ID
			);
		}

		$title = isset( $_POST['title'] ) ? sanitize_text_field( wp_unslash( $_POST['title'] ) ) : '';
		$data  = array(
			'ID'           => (int) $post->ID,
			'post_content' => wp_slash( $html ),
		);
		if ( '' !== $title ) {
			$data['post_title'] = $title;
		}

		$ok = wp_update_post( $data, true );
		if ( is_wp_error( $ok ) ) {
			$this->back( $ok->get_error_message(), true, (int) $post->ID );
		}

		delete_transient( self::draft_key( (int) $post->ID ) );
		update_post_meta( (int) $post->ID, self::META_AT, time() );

		$this->respond_with_panel( $post );

		$this->back(
			sprintf(
				/* translators: 1: before, 2: after. */
				__( 'اعمال شد: %1$d → %2$d کلمه. نسخه‌ی قبلی در بازبینی وردپرس است.', 'hami-ai-assistant' ),
				(int) $before,
				(int) $after
			),
			false,
			(int) $post->ID
		);
	}

	/** @return void */
	public function handle_discard() {
		Haa_Util::guard_admin_post( 'haai_rw' );
		$post = $this->posted_post();
		if ( is_wp_error( $post ) ) {
			$this->back( $post->get_error_message(), true, 0 );
		}
		delete_transient( self::draft_key( (int) $post->ID ) );
		delete_transient( self::state_key( (int) $post->ID ) );
		$this->respond_with_panel( $post );
		$this->back( __( 'پیش‌نمایش/اجرای نیمه‌کاره دور انداخته شد.', 'hami-ai-assistant' ), false, (int) $post->ID );
	}

	/* ------------------------------------------------------------------
	 * Helpers
	 * ---------------------------------------------------------------- */

	/**
	 * The post named by the current request, validated against the module policy.
	 *
	 * @return WP_Post|WP_Error
	 */
	private function posted_post() {
		$pid  = isset( $_POST['post_id'] ) ? (int) $_POST['post_id'] : 0;
		$post = $pid ? get_post( $pid ) : null;
		if ( ! $post instanceof WP_Post ) {
			return new WP_Error( 'haai_rw_post', __( 'نوشته پیدا نشد.', 'hami-ai-assistant' ) );
		}
		if ( ! in_array( (string) $post->post_type, self::types(), true ) ) {
			return new WP_Error( 'haai_rw_type', __( 'این نوع محتوا برای بازنویسی فعال نیست.', 'hami-ai-assistant' ) );
		}
		if ( ! current_user_can( 'edit_post', (int) $post->ID ) ) {
			return new WP_Error( 'haai_rw_cap', __( 'دسترسی به این نوشته ندارید.', 'hami-ai-assistant' ) );
		}
		return $post;
	}

	/**
	 * @param int $post_id Post id.
	 * @return string Transient key (per user, so two admins never share a draft).
	 */
	private static function draft_key( $post_id ) {
		return self::DRAFT_PREFIX . (int) $post_id . '_' . (int) get_current_user_id();
	}

	/**
	 * Progress of a run that is still being chunked.
	 *
	 * @param int $post_id Post id.
	 * @return string
	 */
	private static function state_key( $post_id ) {
		return self::draft_key( $post_id ) . '_state';
	}

	/**
	 * @param string $html Body.
	 * @return int Word count of the visible text.
	 */
	private static function words( $html ) {
		$text = trim( preg_replace( '/\s+/u', ' ', wp_strip_all_tags( (string) $html ) ) );
		if ( '' === $text ) {
			return 0;
		}
		return count( preg_split( '/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY ) );
	}

	/** @return string Tone label from the content settings. */
	private static function tone_label() {
		$tones = Haa_Util::tones();
		$key   = (string) Haa_Settings::get( 'content.default_tone', 'friendly' );
		return (string) ( $tones[ $key ] ?? $key );
	}

	/**
	 * Target length: keep the current size unless the admin picked another.
	 *
	 * @param int $current Current word count.
	 * @return int
	 */
	private static function target_words( $current ) {
		$floor = (int) Haa_Util::length_words( (string) Haa_Settings::get( 'content.default_length', 'medium' ) );
		return max( 200, (int) ( $current > 0 ? $current : $floor ) );
	}

	/**
	 * Split the body into rewrite-sized chunks.
	 *
	 * Boundaries are H2 headings (the natural section), and any section longer
	 * than CHUNK_WORDS is cut again at paragraph ends. Every chunk is small enough
	 * for a single call to finish in seconds, which is what keeps the request
	 * inside PHP's execution limit.
	 *
	 * @param string $html Body HTML.
	 * @return array<int,string>
	 */
	private static function split_chunks( $html ) {
		$html = trim( (string) $html );
		if ( '' === $html ) {
			return array();
		}

		$parts = preg_split( '/(?=<h2\b)/i', $html, -1, PREG_SPLIT_NO_EMPTY );
		if ( ! is_array( $parts ) || ! $parts ) {
			$parts = array( $html );
		}

		$out = array();
		foreach ( $parts as $part ) {
			foreach ( self::split_by_words( (string) $part, self::CHUNK_WORDS ) as $chunk ) {
				$out[] = $chunk;
			}
		}

		return $out;
	}

	/**
	 * Cut one block at paragraph ends once it passes the word budget.
	 *
	 * @param string $html  Block.
	 * @param int    $limit Max words per piece.
	 * @return array<int,string>
	 */
	private static function split_by_words( $html, $limit ) {
		if ( self::words( $html ) <= (int) $limit ) {
			return array( $html );
		}

		$blocks = preg_split( '/(?<=<\/p>)/i', $html, -1, PREG_SPLIT_NO_EMPTY );
		if ( ! is_array( $blocks ) || ! $blocks ) {
			return array( $html );
		}

		$out = array();
		$buf = '';
		$n   = 0;
		foreach ( $blocks as $block ) {
			$bw = self::words( (string) $block );
			if ( $n > 0 && ( $n + $bw ) > (int) $limit ) {
				$out[] = $buf;
				$buf   = '';
				$n     = 0;
			}
			$buf .= (string) $block;
			$n   += $bw;
		}
		if ( '' !== trim( $buf ) ) {
			$out[] = $buf;
		}

		return $out;
	}

	/**
	 * Rewrite ONE chunk. Nothing is written here.
	 *
	 * @param WP_Post $post         Post (for the title and the site memory).
	 * @param string  $chunk        Body chunk.
	 * @param string  $instructions Per-run instruction.
	 * @return string|WP_Error
	 */
	private static function rewrite_chunk( $post, $chunk, $instructions = '' ) {
		$chunk = trim( (string) $chunk );
		if ( '' === $chunk ) {
			return new WP_Error( 'haai_rw_empty_chunk', __( 'یک بخش خالی بود؛ دوباره بزنید.', 'hami-ai-assistant' ) );
		}

		$chunk_words = max( 1, self::words( $chunk ) );
		$target      = max( 120, $chunk_words );
		$system = self::system_prompt( $target, $instructions );
		$user   = sprintf(
			/* translators: 1: title, 2: chunk. */
			__( "عنوان مقاله: %1\$s\n\nاین بخش را بازنویسی کن و فقط همان را برگردان:\n%2\$s", 'hami-ai-assistant' ),
			(string) get_the_title( $post ),
			$chunk
		);

		$api = new Haa_API();
		$out = $api->chat(
			array(
				array( 'role' => 'system', 'content' => $system ),
				array( 'role' => 'user', 'content' => $user ),
			),
			array(
				'module'     => 'rewrite',
				'operation'  => 'rewrite',
				'max_tokens' => (int) min( 4000, max( 1200, $target * 4 ) ),
				// Bounded on purpose: see CALL_TIMEOUT.
				'timeout'    => self::CALL_TIMEOUT,
				'retries'    => 0,
			)
		);

		if ( is_wp_error( $out ) ) {
			return $out;
		}

		$raw  = trim( (string) $out );
		$html = trim( (string) preg_replace( '/^```[a-z]*\s*|\s*```$/i', '', $raw ) );
		$html = trim( (string) Haa_Content::strip_meta_markers( $html ) );

		// The floor scales with the chunk: a 200-word section may legitimately come
		// back shorter, while a 700-word one may not. A fixed 20-word floor rejected
		// small sections and reported it as «پاسخ قابل‌استفاده نداشت».
		$min = max( 8, (int) floor( $chunk_words / 3 ) );
		if ( '' === $html ) {
			return new WP_Error(
				'haai_rw_empty',
				sprintf(
					/* translators: %s: what the model actually returned. */
					__( 'پاسخ مدل خالی بود%1$s — دوباره بزنید؛ اگر تکرار شد مدل دیگری را در تب «ارائه‌دهنده AI» انتخاب کنید.', 'hami-ai-assistant' ),
					'' !== $raw ? ' (پاسخ خام: «' . Haa_Util::cut( wp_strip_all_tags( $raw ), 160 ) . '»)' : ''
				)
			);
		}
		if ( self::words( $html ) < $min ) {
			return new WP_Error(
				'haai_rw_short',
				sprintf(
					/* translators: 1: returned words, 2: expected words, 3: raw reply. */
					__( 'پاسخ مدل برای این بخش کوتاه بود (%1$d کلمه؛ انتظار حدود %2$d). پاسخ خام: «%3$s»', 'hami-ai-assistant' ),
					(int) self::words( $html ),
					(int) $min,
					Haa_Util::cut( wp_strip_all_tags( $raw ), 160 )
				)
			);
		}

		return $html;
	}

	/**
	 * Rules shared by every chunk: honesty, structure, links, tone, output shape.
	 *
	 * @param int    $target       Target words for this chunk.
	 * @param string $instructions Per-run instruction.
	 * @return string
	 */
	private static function system_prompt( $target, $instructions = '' ) {
		$system = Haa_Content::editor_core() . "\n"
			. __( 'این کار بازنویسی یک بخش است، نه نوشتن تازه:', 'hami-ai-assistant' ) . "\n"
			. '- ' . __( 'فقط همین بخش را بنویس؛ مقدمه یا نتیجه‌گیری تازه اضافه نکن و بخش دیگری از مقاله را تکرار نکن.', 'hami-ai-assistant' ) . "\n"
			. '- ' . __( 'همه‌ی داده‌های واقعی متن (نام، عدد، ویژگی، لینک) باید بمانند؛ چیزی که در متن نیست نساز.', 'hami-ai-assistant' ) . "\n"
			. '- ' . __( 'سرفصل‌های همین بخش را نگه دار و لینک‌های موجود را حذف نکن.', 'hami-ai-assistant' ) . "\n"
			. '- ' . sprintf(
				/* translators: 1: tone, 2: words. */
				__( 'لحن: %1$s. طول تقریبی: %2$d کلمه (کمتر از ۷۰٪ آن نرو).', 'hami-ai-assistant' ),
				self::tone_label(),
				(int) $target
			) . "\n"
			. '- ' . __( 'خروجی فقط HTML همین بخش است (h2/h3/p/ul/li/table و همان لینک‌ها)؛ بدون <html>، بدون توضیح، بدون ```.', 'hami-ai-assistant' ) . "\n";

		$profile = Haa_Site_Profile::block( '' );
		if ( '' !== $profile ) {
			$system .= "\n" . __( 'حافظه‌ی سایت (برای هماهنگی لحن و واژه‌ها):', 'hami-ai-assistant' ) . "\n" . $profile . "\n";
		}

		$settings_hint = trim( (string) Haa_Settings::get( 'rewrite.instructions', '' ) );
		if ( '' !== $settings_hint ) {
			$system .= "\n" . __( 'دستور ثابت مدیر:', 'hami-ai-assistant' ) . ' ' . $settings_hint . "\n";
		}
		if ( '' !== trim( (string) $instructions ) ) {
			$system .= "\n" . __( 'دستور این نوبت:', 'hami-ai-assistant' ) . ' ' . $instructions . "\n";
		}

		return $system;
	}

	/**
	 * The panel markup rides back in the same AJAX response.
	 *
	 * Unlike the article-writer panels there is no second request: this screen
	 * has no wp_ajax panel endpoint, and the preview only exists for the request
	 * that just wrote the transient.
	 *
	 * @param WP_Post    $post     Post being edited.
	 * @param array|null $progress Chunk progress for the auto-chain.
	 * @return void
	 */
	private function respond_with_panel( $post, $progress = null ) {
		if ( ! Haa_Ajax_Form::wants() ) {
			return;
		}
		ob_start();
		$this->render_editor( $post );
		$GLOBALS['haai_ajax_extra'] = array(
			'target' => 'haai-rewrite-panel',
			'html'   => (string) ob_get_clean(),
		);
		if ( is_array( $progress ) ) {
			$GLOBALS['haai_ajax_extra']['progress'] = $progress;
		}
	}

	/** @var bool One shutdown guard per request. */
	private static $armed = false;

	/** @var bool True once a real JSON answer was emitted. */
	private static $answered = false;

	/**
	 * A rewrite is one long blocking call. If PHP dies mid-flight (max execution
	 * time, memory, a fatal in a hook) the browser used to get an HTML error page
	 * and admin.js could only say «پاسخ نامعتبر بود». This turns that death into a
	 * parseable JSON error with the real reason, and remembers it for the panel.
	 *
	 * @return void
	 */
	private function arm_fatal_guard() {
		if ( self::$armed ) {
			return;
		}
		self::$armed = true;

		register_shutdown_function(
			static function () {
				if ( self::$answered ) {
					return;
				}
				$e = error_get_last();
				if ( ! is_array( $e ) || ! in_array( (int) $e['type'], array( E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR ), true ) ) {
					return;
				}

				$msg = sprintf(
					/* translators: %s: PHP error message. */
					__( 'سرور وسط بازنویسی متوقف شد: %s — معمولاً مهلت اجرای PHP تمام شده است؛ متن کوتاه‌تر یا «مهلت پاسخ» ارائه‌دهنده را کم کنید.', 'hami-ai-assistant' ),
					(string) $e['message']
				);
				set_transient( self::FAIL_KEY . (int) get_current_user_id(), $msg, HOUR_IN_SECONDS );

				if ( ! Haa_Ajax_Form::wants() ) {
					return;
				}
				while ( ob_get_level() > 0 ) {
					ob_end_clean();
				}
				if ( ! headers_sent() ) {
					status_header( 200 );
					header( 'Content-Type: application/json; charset=utf-8' );
				}
				echo wp_json_encode(
					array(
						'success' => false,
						'data'    => array( 'ok' => false, 'msg' => $msg ),
					)
				);
			}
		);
	}

	/**
	 * Keep the last failure for this admin so the panel can explain it.
	 *
	 * @param string $message Message.
	 * @return void
	 */
	private function remember_failure( $message ) {
		set_transient( self::FAIL_KEY . (int) get_current_user_id(), Haa_Util::cut( (string) $message, 300 ), HOUR_IN_SECONDS );
	}

	/**
	 * One funnel for both worlds: JSON under the AJAX transport, a redirect
	 * otherwise (the no-JS fallback still works).
	 *
	 * @param string $message Message.
	 * @param bool   $error   Error styling.
	 * @param int    $post_id Post to return to (0 = list).
	 * @return void
	 */
	private function back( $message, $error = false, $post_id = 0 ) {
		self::$answered = Haa_Ajax_Form::wants();
		if ( Haa_Ajax_Form::respond( (string) $message, (bool) $error ) ) {
			return;
		}
		$args = $post_id ? array( 'post' => (int) $post_id ) : array();
		$args[ $error ? 'haai_err' : 'haai_msg' ] = rawurlencode( Haa_Util::cut( (string) $message, 300 ) );
		wp_safe_redirect( self::url( 0, $args ) );
		exit;
	}
}
