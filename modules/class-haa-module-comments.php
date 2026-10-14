<?php
/**
 * Module: Comments — pre-drafts human-sounding replies on blog comments.
 *
 * Nothing is ever auto-posted. A comment creates a job; the job fills a
 * draft answer stored in our table; an admin (or a filter) decides whether to
 * push it into WP as the comment author.
 *
 * @package Hami_AI_Assistant
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Haa_Module_Comments implements Haa_Module_Interface {

	/** @return string */
	public function slug() {
		return 'comments';
	}

	/** @return string */
	public function label() {
		return __( 'دیدگاه‌ها', 'hami-ai-assistant' );
	}

	/** @return string */
	public function description() {
		return __( 'برای دیدگاه‌های تازه یک پاسخ پیش‌نویس می‌نویسد؛ هیچ‌چیز بدون تأیید شما منتشر نمی‌شود.', 'hami-ai-assistant' );
	}

	/** @return bool */
	public function requires_woocommerce() {
		// Single source of truth: Haa_Modules::catalog_meta()[ 'woo' ].
		return Haa_Modules::requires_woocommerce( $this->slug() );
	}

	/** @return void */
	public function register() {
		add_action( 'haai_settings_sections', array( $this, 'register_section' ), 10 );
		add_action( 'haai_settings_fields_comments', array( $this, 'register_fields' ) );
		add_action( 'haai_admin_menus', array( $this, 'register_menu' ) );
		add_action( 'comment_post', array( $this, 'on_comment' ), 10, 3 );
		add_action( 'admin_post_haai_cm_run', array( $this, 'handle_run' ) );
		add_action( 'admin_post_haai_cm_apply', array( $this, 'handle_apply' ) );
		add_action( 'admin_post_haai_cm_dismiss', array( $this, 'handle_dismiss' ) );
		add_action( 'admin_post_haai_cm_delete', array( $this, 'handle_delete' ) );
		add_action( 'admin_post_haai_cm_approve', array( $this, 'handle_approve' ) );
		add_action( 'haai_run_job_comment_answer', array( $this, 'run_job' ), 10, 2 );
	}

	/** @return void */
	public function activate() {
		Haa_DB::create();
	}

	/** @return void */
	public function deactivate() {
		// Draft answers remain; nothing is auto-posted to free us.
	}

	/**
	 * @param array $sections Tabs so far.
	 * @return array
	 */
	public function register_section( $sections ) {
		$sections['comments'] = __( 'دیدگاه‌ها', 'hami-ai-assistant' );
		return $sections;
	}

	/**
	 * @param array $fields Fields so far.
	 * @return array
	 */
	public function register_fields( $fields ) {
		$fields[] = array( 'key' => 'comments.enabled', 'type' => 'checkbox', 'label' => __( 'فعال', 'hami-ai-assistant' ) );
		$fields[] = array( 'key' => 'comments.author_name', 'type' => 'text', 'label' => __( 'نام پاسخ‌دهنده', 'hami-ai-assistant' ) );
		$fields[] = array( 'key' => 'comments.author_email', 'type' => 'text', 'label' => __( 'ایمیل پاسخ‌دهنده', 'hami-ai-assistant' ) );
		$fields[] = array( 'key' => 'comments.default_status', 'type' => 'select', 'label' => __( 'وضعیت پیش‌فرض پاسخ', 'hami-ai-assistant' ), 'choices' => array( 'pending' => __( 'در انتظار بررسی', 'hami-ai-assistant' ), 'approved' => __( 'تأییدشده', 'hami-ai-assistant' ) ) );
		$fields[] = array( 'key' => 'comments.generate_questions', 'type' => 'checkbox', 'label' => __( 'سؤال‌های پیشنهادی', 'hami-ai-assistant' ) );
		$fields[] = array( 'key' => 'comments.generate_answers', 'type' => 'checkbox', 'label' => __( 'پاسخ', 'hami-ai-assistant' ) );
		$fields[] = array( 'key' => 'comments.max_per_run', 'type' => 'number', 'label' => __( 'حداکثر در هر اجرا', 'hami-ai-assistant' ), 'min' => 1, 'max' => 20 );
		return $fields;
	}

	/**
	 * @param string $parent Parent slug.
	 * @return void
	 */
	public function register_menu( $parent ) {
		add_submenu_page(
			$parent,
			__( 'دیدگاه‌ها', 'hami-ai-assistant' ),
			__( 'دیدگاه‌ها', 'hami-ai-assistant' ),
			'manage_options',
			Haa_Util::screen( 'comments' ),
			array( $this, 'render_screen' )
		);
	}

	/* ------------------------------------------------------------------
	 * Hook
	 * ---------------------------------------------------------------- */

	/**
	 * @param int        $comment_id       Comment id.
	 * @param int|string $comment_approved 1/0/'spam'.
	 * @param array      $commentdata     Comment data.
	 */
	public function on_comment( $comment_id, $comment_approved, $commentdata = array() ) {
		if ( ! Haa_Settings::is_module_enabled( 'comments' ) || empty( Haa_Settings::get( 'comments.enabled' ) ) ) {
			return;
		}
		// Never react to our own comments or to spam/trash.
		if ( 'spam' === (string) $comment_approved ) {
			return;
		}
		$comment = get_comment( (int) $comment_id );
		if ( ! $comment ) {
			return;
		}
		// Skip replies to product reviews: they are not blog posts.
		if ( 'product_review' === get_comment_type( (int) $comment_id ) ) {
			return;
		}

		$wants_answer = ! empty( Haa_Settings::get( 'comments.generate_answers' ) );
		$wants_asks   = ! empty( Haa_Settings::get( 'comments.generate_questions' ) );
		if ( ! $wants_answer && ! $wants_asks ) {
			return;
		}

		// Already drafted?
		if ( self::has_draft( (int) $comment_id ) ) {
			return;
		}

		Haa_Queue::push(
			'comment_answer',
			array(
				'comment_id' => (int) $comment_id,
				'want'       => $wants_answer ? 'answer' : 'questions',
			),
			array( 'module' => 'comments', 'priority' => 'low' )
		);
	}

	/**
	 * @param int $comment_id Comment id.
	 * @return bool
	 */
	public static function has_draft( $comment_id ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return (bool) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . Haa_DB::table( 'comment_jobs' ) . ' WHERE comment_id = %d', $comment_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/* ------------------------------------------------------------------
	 * Worker
	 * ---------------------------------------------------------------- */

	/**
	 * @param array $job     Queue row.
	 * @param array $payload Payload.
	 */
	public function run_job( $job, $payload ) {
		$comment_id = (int) ( $payload['comment_id'] ?? 0 );
		$want       = (string) ( $payload['want'] ?? 'answer' );

		$comment = get_comment( $comment_id );
		if ( ! $comment ) {
			$GLOBALS['haai_job_result'] = new WP_Error( 'haai_cm_missing', __( 'دیدگاه پیدا نشد.', 'hami-ai-assistant' ) );
			return;
		}
		if ( 'spam' === $comment->comment_approved || 'trash' === $comment->comment_approved ) {
			$GLOBALS['haai_job_result'] = new WP_Error( 'haai_cm_spam', __( 'دیدگاه اسپم یا حذف‌شده است.', 'hami-ai-assistant' ) );
			return;
		}

		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 120 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}

		$post_id   = (int) $comment->comment_post_ID;
		$post      = get_post( $post_id );
		$post_type = $post ? $post->post_type : 'post';

		// Product reviews get the WooCommerce-aware context (shared with the
		// chatbot so both speak about the SKU identically).
		$context = '';
		if ( 'product' === $post_type && function_exists( 'wc_get_product' ) ) {
			$facts = Haa_Content::product_facts( $post_id );
			if ( $facts ) {
				$lines = array();
				foreach ( $facts as $k => $v ) {
					$lines[] = $k . ': ' . $v;
				}
				$context = implode( "\n", $lines );
			}
		} elseif ( $post ) {
			$context = sprintf(
				/* translators: 1: post title. 2: excerpt. */
				__( 'متن مقاله: %1$s\nخلاصه: %2$s', 'hami-ai-assistant' ),
				$post->post_title,
				Haa_Util::cut( wp_strip_all_tags( $post->post_content ), 800 )
			);
		}

		$system = (string) Haa_Settings::get( 'chat.system_prompt' );
		$system .= "\n\n"
			. __( 'وظیفه‌ات پاسخ به دیدگاه خواننده است؛ با لحن مدیر سایت، کوتاه، بدون تکرار حرف او، فارسی روان.', 'hami-ai-assistant' );

		if ( '' !== $context ) {
			$system .= "\n\n" . __( 'زمینه:', 'hami-ai-assistant' ) . "\n" . $context;
		}

		// Haa_Chat::ask takes history and identifier; we piggyback the custom
		// system prompt through the context filter, which the chat stack
		// already applies before every provider call.
		$inject = function ( $ctx ) use ( $system ) {
			$ctx['comment_system'] = $system;
			return $ctx;
		};
		add_filter( 'haai_chat_context', $inject );

		$user = sprintf(
			/* translators: %s: comment text. */
			__( 'دیدگاه خواننده:\n«%s»', 'hami-ai-assistant' ),
			Haa_Util::cut( $comment->comment_content, 1500 )
		);
		if ( 'questions' === $want ) {
			$user .= "\n" . __( 'سه سؤال کوتاه و مرتبط با همین دیدگاه بنویس؛ هر کدام در یک خط، بدون شماره.', 'hami-ai-assistant' );
		} else {
			$user .= "\n" . __( 'یک پاسخ مناسب بنویس؛ حداکثر ۶۰ کلمه، بدون ایموجی.', 'hami-ai-assistant' );
		}

		$answer = Haa_Chat::ask(
			$user,
			array(),
			'comment:' . $comment_id,
			array( 'module' => 'comments', 'operation' => 'comment' )
		);

		remove_filter( 'haai_chat_context', $inject );

		if ( is_wp_error( $answer ) ) {
			$GLOBALS['haai_job_result'] = $answer;
			return;
		}
		if ( is_array( $answer ) && empty( $answer['ok'] ) && ! empty( $answer['error'] ) ) {
			$GLOBALS['haai_job_result'] = new WP_Error( 'haai_cm_api', (string) $answer['error'] );
			return;
		}

		$text = is_array( $answer ) ? (string) ( $answer['reply'] ?? '' ) : (string) $answer;
		$text = trim( (string) preg_replace( '/\s+/u', ' ', $text ) );
		if ( '' === $text ) {
			$GLOBALS['haai_job_result'] = new WP_Error( 'haai_cm_empty', __( 'مدل چیزی برنگرداند.', 'hami-ai-assistant' ) );
			return;
		}

		global $wpdb;
		$table = Haa_DB::table( 'comment_jobs' );
		$now   = current_time( 'mysql', true );

		// Check if a row already exists for this comment.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$existing = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $table WHERE comment_id = %d", $comment_id ) );

		$data = array(
			'comment_id'     => (int) $comment_id,
			'post_id'        => (int) $post_id,
			'post_type'      => (string) $post_type,
			'kind'           => 'questions' === $want ? 'questions' : 'answer',
			'draft'          => $text,
			'author_name'    => (string) Haa_Settings::get( 'comments.author_name', 'پشتیبانی سایت' ),
			'author_email'   => (string) Haa_Settings::get( 'comments.author_email', '' ),
			'comment_status' => (string) Haa_Settings::get( 'comments.default_status', 'pending' ),
			'status'         => 'draft',
			'updated'        => $now,
		);
		$formats = array( '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' );

		if ( $existing ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->update( $table, $data, array( 'id' => (int) $existing ), $formats, array( '%d' ) );
		} else {
			$data['created'] = $now;
			$formats[]       = '%s';
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->insert( $table, $data, $formats );
		}
	}

	/* ------------------------------------------------------------------
	 * Screen
	 * ---------------------------------------------------------------- */

	/** @return void */
	public function render_screen() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$filter = isset( $_GET['haai_filter'] ) ? sanitize_key( wp_unslash( $_GET['haai_filter'] ) ) : 'draft';
		if ( ! in_array( $filter, array( 'draft', 'applied', 'dismissed', 'all' ), true ) ) {
			$filter = 'draft';
		}

		global $wpdb;
		$table   = Haa_DB::table( 'comment_jobs' );
		$status  = 'all' === $filter ? null : $filter;
		$where   = $status ? $wpdb->prepare( ' WHERE status = %s', $status ) : ''; // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$rows    = $wpdb->get_results( "SELECT * FROM $table $where ORDER BY id DESC LIMIT 100", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		?>
		<div class="wrap haai-wrap">
			<?php Haa_Admin::page_header( __( 'دیدگاه‌ها', 'hami-ai-assistant' ) ); ?>

			<?php
			$msg = isset( $_GET['haai_msg'] ) ? sanitize_text_field( wp_unslash( $_GET['haai_msg'] ) ) : '';
			if ( '' !== $msg ) {
				printf( '<div class="notice notice-success inline"><p>%s</p></div>', esc_html( $msg ) );
			}
			?>

			<nav class="haai-subsubsub">
				<?php
				$tabs = array(
					'draft'     => __( 'پیش‌نویس‌ها', 'hami-ai-assistant' ),
					'applied'   => __( 'استفاده‌شده', 'hami-ai-assistant' ),
					'dismissed' => __( 'ردشده', 'hami-ai-assistant' ),
					'all'       => __( 'همه', 'hami-ai-assistant' ),
				);
				$links = array();
				foreach ( $tabs as $slug => $label ) {
					$url = Haa_Util::screen_url( 'comments', array( 'haai_filter' => $slug ) );
					$sep = $slug === array_key_last( $tabs ) ? '' : ' | ';
					$links[] = sprintf(
						'<a href="%s"%s>%s</a>%s',
						esc_url( $url ),
						$slug === $filter ? ' class="current"' : '',
						esc_html( $label ),
						$sep
					);
				}
				echo implode( '', $links ); // phpcs:ignore WordPress.Security.EscapeOutput
				?>
			</nav>

			<table class="widefat striped haai-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'متن دیدگاه', 'hami-ai-assistant' ); ?></th>
						<th><?php esc_html_e( 'نوع', 'hami-ai-assistant' ); ?></th>
						<th><?php esc_html_e( 'پیش‌نویس', 'hami-ai-assistant' ); ?></th>
						<th><?php esc_html_e( 'وضعیت', 'hami-ai-assistant' ); ?></th>
						<th></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $rows ) ) : ?>
						<tr><td colspan="5">
							<div class="haai-empty">
								<svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 5h16v11H4z"/><path d="M8 9h8M8 12h5"/></svg>
								<span class="haai-empty__title"><?php esc_html_e( 'چیزی نیست.', 'hami-ai-assistant' ); ?></span>
								<p class="haai-empty__hint"><?php esc_html_e( 'با هر دیدگاه تازه، پیش‌نویس پاسخ اینجا ساخته می‌شود.', 'hami-ai-assistant' ); ?></p>
							</div>
						</td></tr>
					<?php else : ?>
						<?php
						foreach ( $rows as $r ) :
							$comment = get_comment( (int) $r['comment_id'] );
							$comment_text = $comment ? wp_strip_all_tags( $comment->comment_content ) : '—';
							$original_url = $comment ? get_comment_link( $comment ) : '';
							?>
							<tr>
								<td>
									<strong><?php echo esc_html( Haa_Util::cut( $comment_text, 120 ) ); ?></strong>
									<?php if ( $original_url ) : ?>
										<br><a href="<?php echo esc_url( $original_url ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'مشاهده دیدگاه', 'hami-ai-assistant' ); ?></a>
									<?php endif; ?>
								</td>
								<td><code><?php echo esc_html( $r['kind'] ); ?></code></td>
								<td><?php echo esc_html( Haa_Util::cut( $r['draft'], 160 ) ); ?></td>
								<td>
									<span class="haai-badge <?php echo 'draft' === $r['status'] ? 'haai-badge--on' : ''; ?>">
										<?php echo esc_html( $r['status'] ); ?>
									</span>
								</td>
								<td>
									<?php if ( 'draft' === $r['status'] && 'answer' === $r['kind'] ) : ?>
										<a class="button button-primary button-small" href="<?php echo esc_url( wp_nonce_url( add_query_arg( array( 'action' => 'haai_cm_approve', 'id' => $r['id'] ), admin_url( 'admin-post.php' ) ), 'haai_cm' ) ); ?>"><?php esc_html_e( 'تایید و درج', 'hami-ai-assistant' ); ?></a>
									<?php endif; ?>
									<a class="button button-small" href="<?php echo esc_url( wp_nonce_url( add_query_arg( array( 'action' => 'haai_cm_run', 'id' => $r['id'] ), admin_url( 'admin-post.php' ) ), 'haai_cm' ) ); ?>"><?php esc_html_e( 'تازه‌سازی', 'hami-ai-assistant' ); ?></a>
									<a class="button button-small" href="<?php echo esc_url( wp_nonce_url( add_query_arg( array( 'action' => 'haai_cm_dismiss', 'id' => $r['id'] ), admin_url( 'admin-post.php' ) ), 'haai_cm' ) ); ?>"><?php esc_html_e( 'رد', 'hami-ai-assistant' ); ?></a>
									<a class="button-link-delete button-small" href="<?php echo esc_url( wp_nonce_url( add_query_arg( array( 'action' => 'haai_cm_delete', 'id' => $r['id'] ), admin_url( 'admin-post.php' ) ), 'haai_cm' ) ); ?>" data-haai-confirm><?php esc_html_e( 'حذف', 'hami-ai-assistant' ); ?></a>
								</td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	/* ------------------------------------------------------------------
	 * Handlers
	 * ---------------------------------------------------------------- */

	/** @return void */
	public function handle_run() {
		Haa_Util::guard_admin_post( 'haai_cm' );
		$id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
		$row = self::row( $id );
		if ( ! $row ) {
			$this->back( __( 'ردیف پیدا نشد.', 'hami-ai-assistant' ) );
		}
		Haa_Queue::push(
			'comment_answer',
			array( 'comment_id' => (int) $row['comment_id'], 'want' => 'questions' === $row['kind'] ? 'questions' : 'answer' ),
			array( 'module' => 'comments' )
		);
		$this->back( __( 'در صف تازه‌سازی قرار گرفت.', 'hami-ai-assistant' ) );
	}

	/** @return void */
	public function handle_apply() {
		Haa_Util::guard_admin_post( 'haai_cm' );
		$id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
		$row = self::row( $id );
		if ( ! $row || 'draft' !== $row['status'] ) {
			$this->back( __( 'ردیف پیدا نشد.', 'hami-ai-assistant' ) );
		}

		$result = wp_insert_comment(
			array(
				'comment_post_ID'      => (int) $row['post_id'],
				'comment_content'      => (string) $row['draft'],
				'comment_author'       => (string) $row['author_name'],
				'comment_author_email' => (string) $row['author_email'],
				'comment_approved'     => 'approved' === $row['comment_status'] ? 1 : 0,
				'comment_type'         => '',
				'comment_parent'       => (int) $row['comment_id'],
			)
		);

		global $wpdb;
		if ( $result ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->update( Haa_DB::table( 'comment_jobs' ), array( 'status' => 'applied' ), array( 'id' => $id ), array( '%s' ), array( '%d' ) );
			$this->back( __( 'پاسخ درج شد.', 'hami-ai-assistant' ) );
		}
		$this->back( __( 'درج پاسخ ممکن نشد.', 'hami-ai-assistant' ) );
	}

	/**
	 * "Approve" for the admin's own reply: mark the human's comment approved
	 * and file the draft. Kept separate from apply so a moderator workflow
	 * (approve original + use AI text) is one click.
	 */
	public function handle_approve() {
		Haa_Util::guard_admin_post( 'haai_cm' );
		$id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
		$row = self::row( $id );
		if ( ! $row ) {
			$this->back( __( 'ردیف پیدا نشد.', 'hami-ai-assistant' ) );
		}
		wp_set_comment_status( (int) $row['comment_id'], 'approve' );
		$_GET['id'] = $id;
		$this->handle_apply();
	}

	/** @return void */
	public function handle_dismiss() {
		Haa_Util::guard_admin_post( 'haai_cm' );
		$id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
		if ( $id ) {
			global $wpdb;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->update( Haa_DB::table( 'comment_jobs' ), array( 'status' => 'dismissed' ), array( 'id' => $id ), array( '%s' ), array( '%d' ) );
		}
		$this->back( '' );
	}

	/** @return void */
	public function handle_delete() {
		Haa_Util::guard_admin_post( 'haai_cm' );
		$id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
		if ( $id ) {
			global $wpdb;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->delete( Haa_DB::table( 'comment_jobs' ), array( 'id' => $id ), array( '%d' ) );
		}
		$this->back( '' );
	}

	/**
	 * @param int $id Row id.
	 * @return array|null
	 */
	private static function row( $id ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Haa_DB::table( 'comment_jobs' ) . ' WHERE id = %d', $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $row ? $row : null;
	}

	/** @return void */
	private function back( $message = '' ) {
		$args = array();
		if ( '' !== $message ) {
			$args['haai_msg'] = rawurlencode( Haa_Util::cut( $message, 300 ) );
		}
		wp_safe_redirect( Haa_Util::screen_url( 'comments', $args ) );
		exit;
	}
}
