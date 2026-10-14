<?php
/**
 * Dashboard: health at a glance, today's spend, queue state, KB index size.
 *
 * @package Hami_AI_Assistant
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Haa_Admin_Dashboard {

	/** @return void */
	public static function render() {
		$usage   = Haa_Usage::summary( 7 );
		$today   = Haa_Usage::today();

		// Cards below read these three keys; summary() only covers the window.
		$usage['messages_today'] = $today['messages'];
		$usage['tokens_today']   = $today['tokens'];
		$usage['tokens_7d']      = (int) $usage['tokens'];

		$queue   = Haa_Queue::stats();
		$search  = Haa_Search::stats();
		$health  = self::health();
		$modules = Haa_Modules::describe_all();
		?>
		<div class="wrap haai-wrap">
			<?php Haa_Admin::page_header( __( 'داشبورد دستیار هوشمند', 'hami-ai-assistant' ) ); ?>

			<h2 class="haai-sec-title"><?php esc_html_e( 'وضعیت', 'hami-ai-assistant' ); ?></h2>
			<div class="haai-cards">
				<?php foreach ( $health as $card ) : ?>
					<?php $tone = isset( $card['tone'] ) ? $card['tone'] : ''; ?>
					<div class="haai-card haai-card--health<?php echo '' !== $tone ? ' haai-card--' . esc_attr( $tone ) : ''; ?>">
						<div class="haai-card__value">
							<span class="haai-dot<?php echo '' !== $tone ? ' haai-dot--' . esc_attr( $tone ) : ''; ?>" aria-hidden="true"></span>
							<?php echo esc_html( $card['value'] ); ?>
						</div>
						<div class="haai-card__label"><?php echo esc_html( $card['label'] ); ?></div>
						<?php if ( ! empty( $card['hint'] ) ) : ?>
							<div class="haai-card__hint"><?php echo esc_html( $card['hint'] ); ?></div>
						<?php endif; ?>
					</div>
				<?php endforeach; ?>
			</div>

			<h2 class="haai-sec-title"><?php esc_html_e( 'مصرف و کارها', 'hami-ai-assistant' ); ?></h2>
			<div class="haai-cards">
				<div class="haai-card">
					<div class="haai-card__value"><?php echo esc_html( number_format_i18n( (int) $usage['messages_today'] ) ); ?></div>
					<div class="haai-card__label"><?php esc_html_e( 'پیام امروز', 'hami-ai-assistant' ); ?></div>
				</div>
				<div class="haai-card">
					<div class="haai-card__value"><?php echo esc_html( number_format_i18n( (int) $usage['tokens_today'] ) ); ?></div>
					<div class="haai-card__label"><?php esc_html_e( 'توکن امروز', 'hami-ai-assistant' ); ?></div>
				</div>
				<div class="haai-card">
					<div class="haai-card__value"><?php echo esc_html( number_format_i18n( (int) $usage['tokens_7d'] ) ); ?></div>
					<div class="haai-card__label"><?php esc_html_e( 'توکن ۷ روز اخیر', 'hami-ai-assistant' ); ?></div>
				</div>
				<div class="haai-card<?php echo empty( $queue['failed'] ) ? '' : ' haai-card--danger'; ?>">
					<div class="haai-card__value"><?php echo esc_html( number_format_i18n( (int) $queue['pending'] ) ); ?></div>
					<div class="haai-card__label"><?php esc_html_e( 'کارهای در صف', 'hami-ai-assistant' ); ?></div>
					<?php if ( ! empty( $queue['failed'] ) ) : ?>
						<div class="haai-card__hint">
							<?php
							printf(
								/* translators: %s: number of failed jobs. */
								esc_html__( '%s مورد ناموفق', 'hami-ai-assistant' ),
								esc_html( number_format_i18n( (int) $queue['failed'] ) )
							);
							?>
						</div>
					<?php endif; ?>
				</div>
				<div class="haai-card">
					<div class="haai-card__value"><?php echo esc_html( number_format_i18n( (int) $search['chunks'] ) ); ?></div>
					<div class="haai-card__label"><?php esc_html_e( 'بخش‌های ایندکس‌شده', 'hami-ai-assistant' ); ?></div>
					<?php if ( ! empty( $search['vectors'] ) ) : ?>
						<div class="haai-card__hint"><?php echo esc_html( sprintf( '%s بردار', number_format_i18n( (int) $search['vectors'] ) ) ); ?></div>
					<?php endif; ?>
				</div>
			</div>

			<h2 class="haai-sec-title">
				<?php esc_html_e( 'ماژول‌ها', 'hami-ai-assistant' ); ?>
				<span class="haai-badge">
					<?php
					$on_count = count( array_filter( $modules, fn( $m ) => ! empty( $m['enabled'] ) ) );
					echo esc_html(
						sprintf(
							/* translators: 1: enabled module count, 2: total module count. */
							__( '%1$d از %2$d', 'hami-ai-assistant' ),
							$on_count,
							count( $modules )
						)
					);
					?>
				</span>
			</h2>
			<div class="haai-mod-grid">
				<?php foreach ( $modules as $m ) : ?>
					<?php
					$on      = ! empty( $m['enabled'] );
					$blocked = ! empty( $m['missing_woocommerce'] );
					?>
					<article class="haai-mod-card haai-mod-card--static<?php echo $on ? ' is-on' : ''; ?><?php echo $blocked ? ' is-blocked' : ''; ?>">
						<div class="haai-mod-card__top">
							<span class="haai-mod-card__icon"><?php echo Haa_Admin::module_icon( $m['slug'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?></span>
							<div class="haai-mod-card__names">
								<h3 class="haai-mod-card__title"><?php echo esc_html( $m['label'] ); ?></h3>
								<code class="haai-slug"><?php echo esc_html( $m['slug'] ); ?></code>
							</div>
						</div>
						<div class="haai-mod-card__foot">
							<?php if ( $blocked ) : ?>
								<span class="haai-badge haai-badge--warn"><?php esc_html_e( 'نیازمند ووکامرس', 'hami-ai-assistant' ); ?></span>
							<?php elseif ( $on ) : ?>
								<span class="haai-badge haai-badge--on"><?php esc_html_e( 'روشن', 'hami-ai-assistant' ); ?></span>
							<?php else : ?>
								<span class="haai-badge"><?php esc_html_e( 'خاموش', 'hami-ai-assistant' ); ?></span>
							<?php endif; ?>
						</div>
					</article>
				<?php endforeach; ?>
			</div>

			<p class="haai-actions">
				<a class="button button-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=' . Haa_Admin::PAGE ) ); ?>">
					<?php esc_html_e( 'تنظیمات', 'hami-ai-assistant' ); ?>
				</a>
				<a class="button" href="<?php echo esc_url( Haa_Util::screen_url( 'modules' ) ); ?>">
					<?php esc_html_e( 'مدیریت ماژول‌ها', 'hami-ai-assistant' ); ?>
				</a>
			</p>

			<?php self::render_diagnostics(); ?>
		</div>
		<?php
	}

	/**
	 * Settings-consistency diagnostic (admin only, read-only).
	 */
	private static function render_diagnostics() {
		$issues = Haa_Settings::diagnose();
		if ( empty( $issues ) ) {
			?>
			<p class="haai-diag-clean">
				<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 6 9 17l-5-5"/></svg>
				<?php esc_html_e( 'تشخیص تنظیمات: هیچ ناسازگاری یافت نشد.', 'hami-ai-assistant' ); ?>
			</p>
			<?php
			return;
		}
		?>
		<details class="haai-diagnostics">
			<summary>
				<?php esc_html_e( 'تشخیص تنظیمات', 'hami-ai-assistant' ); ?>
				<span class="haai-badge haai-badge--warn haai-diagnostics__count"><?php echo esc_html( number_format_i18n( count( $issues ) ) ); ?></span>
			</summary>
			<div class="haai-table-scroll">
				<table class="widefat striped haai-table">
					<thead>
						<tr>
							<th><?php esc_html_e( 'بخش', 'hami-ai-assistant' ); ?></th>
							<th><?php esc_html_e( 'کلید', 'hami-ai-assistant' ); ?></th>
							<th><?php esc_html_e( 'مشکل', 'hami-ai-assistant' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $issues as $issue ) : ?>
							<tr>
								<td><code><?php echo esc_html( $issue['section'] ); ?></code></td>
								<td><code><?php echo esc_html( $issue['key'] ); ?></code></td>
								<td><?php echo esc_html( $issue['issue'] ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		</details>
		<?php
	}

	/**
	 * Small health cards. Every check is a cheap local read — the dashboard
	 * never makes a network call.
	 *
	 * `tone` is presentation only: 'ok', 'warn', 'danger' or '' for a plain
	 * informational card. It drives the dot colour and nothing else.
	 *
	 * @return array<int,array{value:string,label:string,hint:string,tone:string}>
	 */
	private static function health() {
		$provider = Haa_Settings::provider();
		$has_key  = '' !== (string) $provider['api_key'];
		$woo      = haa_is_woo();

		$out = array();

		$out[] = array(
			'value' => $has_key ? __( 'متصل', 'hami-ai-assistant' ) : __( 'قطع', 'hami-ai-assistant' ),
			'label' => __( 'سرویس AI', 'hami-ai-assistant' ),
			'hint'  => $has_key ? $provider['provider'] : __( 'کلید API ثبت نشده است.', 'hami-ai-assistant' ),
			'tone'  => $has_key ? 'ok' : 'warn',
		);

		// Hidden is often deliberate (display mode, role rules), so it gets no
		// colour — only the positive state is highlighted.
		$out[] = array(
			'value' => Haa_Plugin::should_display( Haa_Settings::get() ) ? __( 'نمایش داده می‌شود', 'hami-ai-assistant' ) : __( 'پنهان', 'hami-ai-assistant' ),
			'label' => __( 'چت‌بات در سایت', 'hami-ai-assistant' ),
			'hint'  => '',
			'tone'  => '',
		);

		// Same reasoning: a store without WooCommerce is not a broken store.
		$out[] = array(
			'value' => $woo ? __( 'فعال', 'hami-ai-assistant' ) : __( 'نصب نیست', 'hami-ai-assistant' ),
			'label' => __( 'ووکامرس', 'hami-ai-assistant' ),
			'hint'  => $woo ? '' : __( 'ماژول‌های فروشگاهی غیرفعال می‌مانند.', 'hami-ai-assistant' ),
			'tone'  => '',
		);

		$sched = wp_next_scheduled( 'haai_daily_cleanup' );
		$out[] = array(
			'value' => $sched ? __( 'زمان‌بندی شده', 'hami-ai-assistant' ) : __( 'غیرفعال', 'hami-ai-assistant' ),
			'label' => __( 'پاک‌سازی خودکار', 'hami-ai-assistant' ),
			'hint'  => $sched ? gmdate( 'Y-m-d H:i', (int) $sched ) : __( 'کرون ثبت نشده است.', 'hami-ai-assistant' ),
			'tone'  => $sched ? 'ok' : 'danger',
		);

		return $out;
	}
}
