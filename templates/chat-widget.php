<?php
/**
 * Chat widget template — the single source of truth for the frontend markup.
 *
 * Used by the [hami_ai_assistant] shortcode (aliases: [haai_assistant],
 * [hami_ai_chat]), the REST "widget" endpoint (floating mode) and the live
 * preview inside the admin panel.
 *
 * @package Hami_AI_Assistant
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders the chat widget markup.
 *
 * @param array $args Display args: `width` (inline css), `class`, `preview`,
 *                    `inline` (add the boxed border/margin treatment).
 */
function haa_render_widget( $args = array() ) {
	$args = wp_parse_args(
		$args,
		array(
			'width'   => '',
			'class'   => '',
			'preview' => false,
			'inline'  => true,
		)
	);

	$s    = Haa_Settings::get();
	$mode = isset( $s['appearance']['theme_mode'] ) ? $s['appearance']['theme_mode'] : 'light';

	$style = '';
	if ( '' !== $args['width'] ) {
		$style = ' style="max-width:' . esc_attr( $args['width'] ) . '"';
	}

	$extra_class = $args['class'] ? ' ' . sanitize_html_class( $args['class'] ) : '';
	$extra_class .= $args['preview'] ? ' haai-widget--preview' : '';
	// The admin live preview is a second copy on the page, so it must not reuse the id.
	$id_attr = $args['preview'] ? '' : ' id="haai-widget"';
	?>
	<div class="haai-widget<?php echo $args['inline'] ? ' haai-widget--inline' : ''; ?><?php echo esc_attr( $extra_class ); ?>"<?php echo $id_attr; // phpcs:ignore WordPress.Security.EscapeOutput ?> data-theme="<?php echo esc_attr( $mode ); ?>" data-init="0"<?php echo $style; // phpcs:ignore WordPress.Security.EscapeOutput ?>>
		<div class="haai-widget__head">
			<div class="haai-widget__title">
				<span class="haai-widget__avatar" aria-hidden="true"><?php echo haa_avatar_svg( $s ); /* phpcs:ignore WordPress.Security.EscapeOutput */ ?></span>
				<span class="haai-widget__name"><?php echo esc_html( get_bloginfo( 'name' ) ); ?></span>
			</div>
			<div class="haai-widget__tools">
				<button type="button" class="haai-btn haai-btn--restart" data-haai-restart aria-label="<?php esc_attr_e( 'شروع مجدد گفتگو', 'hami-ai-assistant' ); ?>" title="<?php esc_attr_e( 'شروع مجدد', 'hami-ai-assistant' ); ?>">
					<svg viewBox="0 0 24 24" width="16" height="16" aria-hidden="true"><path d="M12 5V2L7 6.5 12 11V8c3.3 0 6 2.7 6 6s-2.7 6-6 6-6-2.7-6-6H4c0 4.4 3.6 8 8 8s8-3.6 8-8-3.6-8-8-8z" fill="currentColor"/></svg>
				</button>
				<button type="button" class="haai-btn haai-btn--close" data-haai-close aria-label="<?php esc_attr_e( 'بستن', 'hami-ai-assistant' ); ?>" title="<?php esc_attr_e( 'بستن', 'hami-ai-assistant' ); ?>">
					<svg viewBox="0 0 24 24" width="16" height="16" aria-hidden="true"><path d="M18.3 5.7 12 12l6.3 6.3-1.4 1.4L10.6 13.4 4.3 19.7l-1.4-1.4L9.2 12 2.9 5.7l1.4-1.4L10.6 10.6 16.9 4.3z" fill="currentColor"/></svg>
				</button>
			</div>
		</div>

		<?php
		// Preview mode is a decorative copy in the admin panel: no IDs, no wiring.
		$p = (bool) $args['preview'];
		?>
		<div class="haai-messages"<?php echo $p ? '' : ' id="haai-messages"'; // phpcs:ignore WordPress.Security.EscapeOutput ?> role="log" aria-live="polite" aria-label="<?php esc_attr_e( 'گفتگو', 'hami-ai-assistant' ); ?>">
			<?php
			$welcome = isset( $s['appearance']['welcome_message'] ) ? $s['appearance']['welcome_message'] : '';
			if ( '' !== $welcome ) {
				echo '<div class="haai-msg haai-msg--bot"><div class="haai-msg__bubble">' . wp_kses( wpautop( $welcome ), array( 'p' => array(), 'br' => array(), 'strong' => array(), 'em' => array(), 'a' => array( 'href' => true, 'title' => true, 'rel' => true, 'target' => true ) ) ) . '</div></div>';
			}
			$suggested = Haa_Settings::suggested_questions();
			if ( ! empty( $suggested ) && ! $p ) {
				echo '<div class="haai-suggest" id="haai-suggest">';
				foreach ( $suggested as $q ) {
					echo '<button type="button" class="haai-suggest__item" data-haai-suggest>' . esc_html( $q ) . '</button>';
				}
				echo '</div>';
			}
			?>
		</div>

		<div class="haai-typing"<?php echo $p ? '' : ' id="haai-typing"'; // phpcs:ignore WordPress.Security.EscapeOutput ?> hidden aria-hidden="true">
			<span></span><span></span><span></span>
		</div>

		<div class="haai-error"<?php echo $p ? '' : ' id="haai-error"'; // phpcs:ignore WordPress.Security.EscapeOutput ?> hidden role="alert"></div>

		<?php
		// Lead capture is rendered only when the leads module is on and the admin
		// asked for something. Nothing is stored unless the visitor actually types.
		$lead_on = Haa_Settings::is_module_enabled( 'leads' )
			&& ( ! empty( $s['chat']['lead_capture'] ) )
			&& 'off' !== (string) ( $s['chat']['lead_requirement'] ?? 'off' );
		if ( $lead_on && ! $p ) :
			?>
			<div class="haai-lead" data-haai-lead>
				<label class="haai-lead__label"><?php esc_html_e( 'برای پیگیری سریع‌تر مشخصاتت رو بده (اختیاری):', 'hami-ai-assistant' ); ?></label>
				<div class="haai-lead__row">
					<input type="text" class="haai-lead__name" data-haai-lead-name name="lead_name"
						placeholder="<?php echo esc_attr( $s['chat']['lead_name_label'] ?? __( 'نام', 'hami-ai-assistant' ) ); ?>"
						autocomplete="name" maxlength="120">
					<input type="tel" class="haai-lead__phone" data-haai-lead-phone name="lead_phone" inputmode="numeric"
						placeholder="<?php echo esc_attr( $s['chat']['lead_phone_label'] ?? __( 'شماره موبایل', 'hami-ai-assistant' ) ); ?>"
						autocomplete="tel" maxlength="20">
				</div>
				<p class="haai-lead__consent"><?php esc_html_e( 'با ارسال شماره، رضایت می‌دهید برای پیگیری سفارش با شما تماس بگیریم.', 'hami-ai-assistant' ); ?></p>
			</div>
		<?php endif; ?>

		<?php
		// Preview mode is painted inside the settings <form>, so it must not
		// nest a second form or carry postable names — the wrapper becomes a
		// div, the honeypot is dropped, and the send button stops being one.
		?>
		<?php if ( $p ) : ?>
			<div class="haai-form">
		<?php else : ?>
			<form class="haai-form" id="haai-form">
		<?php endif; ?>
			<?php if ( ! $p ) : ?>
				<div class="haai-hp" aria-hidden="true">
					<label><?php esc_attr_e( 'آدرس ایمیل', 'hami-ai-assistant' ); ?></label>
					<input type="text" name="haai_hp" tabindex="-1" autocomplete="off" data-haai-hp>
				</div>
			<?php endif; ?>
			<textarea class="haai-form__input"<?php echo $p ? '' : ' id="haai-input" name="message"'; // phpcs:ignore WordPress.Security.EscapeOutput ?> rows="1"
				placeholder="<?php echo esc_attr( isset( $s['appearance']['placeholder'] ) ? $s['appearance']['placeholder'] : __( 'سوالت رو بپرس…', 'hami-ai-assistant' ) ); ?>"
				aria-label="<?php esc_attr_e( 'پیام شما', 'hami-ai-assistant' ); ?>"></textarea>
			<button type="<?php echo $p ? 'button' : 'submit'; ?>" class="haai-form__send" aria-label="<?php esc_attr_e( 'ارسال', 'hami-ai-assistant' ); ?>">
				<svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true"><path d="M3.4 20.4 21 12 3.4 3.6 3 10l12 2-12 2z" fill="currentColor"/></svg>
			</button>
		<?php echo $p ? '</div>' : '</form>'; // phpcs:ignore WordPress.Security.EscapeOutput ?>

		<div class="haai-widget__foot"><?php esc_html_e( 'پشتیبانی هوش مصنوعی', 'hami-ai-assistant' ); ?></div>
	</div>
	<?php
}

/**
 * Inline avatar SVG (chat bubble) — overridden by the uploaded icon.
 *
 * @param array $s Settings array.
 * @return string SVG markup.
 */
function haa_avatar_svg( $s ) {
	if ( ! empty( $s['appearance']['custom_icon'] ) ) {
		$url = wp_get_attachment_url( (int) $s['appearance']['custom_icon'] );
		if ( $url ) {
			return '<img src="' . esc_url( $url ) . '" alt="" width="32" height="32" loading="lazy">';
		}
	}
	return '<svg viewBox="0 0 24 24" width="20" height="20" aria-hidden="true"><path d="M12 3C6.5 3 2 6.6 2 11c0 2.2 1.2 4.2 3.1 5.6L4 21l4.9-2.6c1 .3 2 .4 3.1.4 5.5 0 10-3.6 10-8s-4.5-8-10-8zm-4 9.5a1.4 1.4 0 1 1 0-2.8 1.4 1.4 0 0 1 0 2.8zm4 0a1.4 1.4 0 1 1 0-2.8 1.4 1.4 0 0 1 0 2.8zm4 0a1.4 1.4 0 1 1 0-2.8 1.4 1.4 0 0 1 0 2.8z" fill="currentColor"/></svg>';
}
