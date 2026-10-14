<?php
/**
 * Modules screen: turn features on and off.
 *
 * A module that is off is not loaded at all — no file is required, no hook is
 * added, no table is created. That is what keeps a chatbot-only install cheap.
 *
 * @package Hami_AI_Assistant
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Haa_Admin_Modules {

	/**
	 * Toggle handler, reached through admin-post.php (action `haai_modules_save`).
	 *
	 * It used to live inside render(), which could never work: WordPress prints
	 * wp-admin/admin-header.php before it calls a plugin page callback, so the
	 * redirect there died on "headers already sent". Posting to admin-post.php
	 * keeps it in the same shape as every other write in this plugin.
	 *
	 * @return void
	 */
	public static function save() {
		Haa_Util::guard_admin_post( 'haai_modules' );

		$old     = Haa_Settings::enabled_modules();
		$enabled = array();
		$posted  = isset( $_POST['modules'] ) && is_array( $_POST['modules'] ) ? wp_unslash( $_POST['modules'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput

		// Only catalog slugs survive, so a crafted field can never invent a module.
		foreach ( array_keys( Haa_Modules::catalog() ) as $slug ) {
			if ( ! empty( $posted[ $slug ] ) ) {
				$enabled[ $slug ] = 1;
				continue;
			}
			// A WooCommerce-dependent module is rendered with its switch disabled,
			// so its checkbox is never posted. That must not clear a stored "on"
			// flag: the admin turned it on while WooCommerce was present, and it
			// should switch itself back on automatically when WooCommerce returns.
			if ( ! isset( $posted[ $slug ] )
				&& Haa_Modules::requires_woocommerce( $slug )
				&& ! haa_is_woo()
				&& ! empty( $old[ $slug ] ) ) {
				$enabled[ $slug ] = 1;
			}
		}
		Haa_Settings::set_enabled_modules( $enabled );

		// Fire activate() / deactivate() only for modules that actually
		// changed state, so cron and tables follow the toggle immediately.
		foreach ( Haa_Modules::catalog() as $slug => $class ) {
			$was = ! empty( $old[ $slug ] );
			$now = ! empty( $enabled[ $slug ] );
			if ( $was === $now ) {
				continue;
			}
			if ( $now ) {
				Haa_Modules::activate_module( $slug );
			} else {
				Haa_Modules::deactivate_module( $slug );
			}
		}

		Haa_Modules::instance()->register_enabled();
		Haa_DB::ensure();

		// A module switched on may need its cron tick back.
		Haa_Plugin::ensure_cron();

		wp_safe_redirect(
			add_query_arg( array( 'page' => 'haai-modules', 'haai_ok' => 'module-saved' ), admin_url( 'admin.php' ) )
		);
		exit;
	}

	/** @return void */
	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$enabled = Haa_Settings::enabled_modules();
		$woo     = haa_is_woo();
		?>
		<div class="wrap haai-wrap">
			<?php Haa_Admin::page_header( __( 'ماژول‌ها', 'hami-ai-assistant' ) ); ?>
			<p class="description">
				<?php esc_html_e( 'ماژول خاموش کاملاً بارگذاری نمی‌شود: نه فایلی خوانده می‌شود، نه جدولی ساخته می‌شود و نه هکی ثبت می‌گردد.', 'hami-ai-assistant' ); ?>
			</p>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'haai_modules' ); ?>
				<input type="hidden" name="action" value="haai_modules_save">
				<div class="haai-mod-grid">
					<?php
					$registry = Haa_DB::registry();
					foreach ( Haa_Modules::describe_all() as $m ) :
						$slug    = $m['slug'];
						$on      = ! empty( $enabled[ $slug ] );
						$blocked = ! empty( $m['missing_woocommerce'] );
						$tables  = array();
						foreach ( $registry as $key => $owner ) {
							if ( $owner === $slug ) {
								$tables[] = Haa_DB::table( $key );
							}
						}
						?>
						<article class="haai-mod-card<?php echo $on ? ' is-on' : ''; ?><?php echo $blocked ? ' is-blocked' : ''; ?>">
							<div class="haai-mod-card__top">
								<span class="haai-mod-card__icon"><?php echo Haa_Admin::module_icon( $slug ); // phpcs:ignore WordPress.Security.EscapeOutput ?></span>
								<div class="haai-mod-card__names">
									<h2 class="haai-mod-card__title"><?php echo esc_html( $m['label'] ); ?></h2>
									<code class="haai-slug"><?php echo esc_html( $slug ); ?></code>
									<p class="haai-mod-card__desc"><?php echo esc_html( $m['description'] ); ?></p>
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

								<span class="haai-mod-card__tables">
									<?php
									if ( $tables ) {
										echo esc_html( implode( '، ', $tables ) );
									} else {
										esc_html_e( 'بدون جدول اختصاصی', 'hami-ai-assistant' );
									}
									?>
								</span>

								<label class="haai-switch haai-mod-card__switch"<?php echo $blocked ? ' title="' . esc_attr__( 'نیازمند ووکامرس', 'hami-ai-assistant' ) . '"' : ''; ?>>
									<input type="checkbox" name="modules[<?php echo esc_attr( $slug ); ?>]" value="1"
										aria-label="<?php echo esc_attr( sprintf( /* translators: %s: module name. */ __( 'فعال‌سازی %s', 'hami-ai-assistant' ), $m['label'] ) ); ?>"
										<?php checked( $on ); ?> <?php disabled( $blocked ); ?>>
									<span class="haai-switch__track"></span>
								</label>
							</div>
						</article>
					<?php endforeach; ?>
				</div>

				<?php if ( ! $woo ) : ?>
					<?php
					// Built from the catalogue so the list can never drift away
					// from the modules that actually carry 'woo' => true.
					$woo_labels = wp_list_pluck(
						array_filter(
							Haa_Modules::describe_all(),
							static function ( $m ) {
								return ! empty( $m['missing_woocommerce'] );
							}
						),
						'label'
					);
					?>
					<p class="notice notice-warning inline">
						<?php
						printf(
							/* translators: %s: comma-separated list of WooCommerce-only modules. */
							esc_html__( 'ووکامرس فعال نیست؛ ماژول‌های فروشگاهی (%s) قابل فعال‌سازی نیستند.', 'hami-ai-assistant' ),
							esc_html( implode( '، ', $woo_labels ) )
						);
						?>
					</p>
				<?php endif; ?>

				<p class="submit">
					<button type="submit" class="button button-primary"><?php esc_html_e( 'ذخیره ماژول‌ها', 'hami-ai-assistant' ); ?></button>
				</p>
			</form>
		</div>
		<?php
	}
}
