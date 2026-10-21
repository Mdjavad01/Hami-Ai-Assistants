<?php
/**
 * Module: Discount — a bounded negotiation that ends in a real WooCommerce
 * coupon.
 *
 * The bot may only go as far as the admin allows: a global ceiling, a default
 * ceiling, a number of rounds and a per-visitor rate limit. Every issued code
 * is written to our own table *and* created as a native WC coupon, so checkout
 * works even if this plugin is later deactivated.
 *
 * @package Hami_AI_Assistant
 */

if ( ! defined( 'ABSPATH' ) ) {
        exit;
}

final class Haa_Module_Discount implements Haa_Module_Interface {

        /** @return string */
        public function slug() {
                return 'discount';
        }

        /** @return string */
        public function label() {
                return __( 'مذاکره‌گر تخفیف', 'hami-ai-assistant' );
        }

        /** @return string */
        public function description() {
                return __( 'در چت چانه می‌زند و در صورت توافق یک کد تخفیف واقعی ووکامرس صادر می‌کند؛ همه چیز در سقفی که خودتان تعیین کرده‌اید.', 'hami-ai-assistant' );
        }

        /** @return bool */
        public function requires_woocommerce() {
                // Single source of truth: Haa_Modules::catalog_meta()[ 'woo' ]. The
                // negotiator issues real WC_Coupon objects, so it is WooCommerce-only.
                return Haa_Modules::requires_woocommerce( $this->slug() );
        }

        /** @return void */
        public function register() {
                add_action( 'haai_settings_sections', array( $this, 'register_section' ), 10 );
                add_action( 'haai_settings_fields_discount', array( $this, 'register_fields' ) );
                add_action( 'haai_admin_menus', array( $this, 'register_menu' ) );
                add_action( 'admin_post_haai_disc_issue', array( $this, 'handle_issue' ) );
                add_action( 'admin_post_haai_disc_revoke', array( $this, 'handle_revoke' ) );
                // The chat pipeline asks this module before it gives up on a haggler.
                add_filter( 'haai_chat_context', array( $this, 'chat_context' ), 10, 2 );
                add_action( 'woocommerce_coupon_redeemed', array( $this, 'on_redeem' ), 10, 3 );
        }

        /** @return void */
        public function activate() {
                Haa_DB::create();
        }

        /** @return void */
        public function deactivate() {
                // Coupons already live in WooCommerce; ours are just a ledger.
        }

        /**
         * @param array $sections Tabs so far.
         * @return array
         */
        public function register_section( $sections ) {
                $sections['discount'] = __( 'تخفیف', 'hami-ai-assistant' );
                return $sections;
        }

        /**
         * @param array $fields Fields so far.
         * @return array
         */
        public function register_fields( $fields ) {
                $fields[] = array( 'key' => 'discount.enabled', 'type' => 'checkbox', 'label' => __( 'فعال', 'hami-ai-assistant' ) );
                $fields[] = array( 'key' => 'discount.max_rounds', 'type' => 'number', 'label' => __( 'حداکثر دور چانه‌زنی', 'hami-ai-assistant' ), 'min' => 1, 'max' => 10 );
                $fields[] = array( 'key' => 'discount.global_max_percent', 'type' => 'number', 'label' => __( 'سقف مطلق تخفیف (٪)', 'hami-ai-assistant' ), 'min' => 0, 'max' => 90 );
                $fields[] = array( 'key' => 'discount.default_max_percent', 'type' => 'number', 'label' => __( 'سقف پیش‌فرض (٪)', 'hami-ai-assistant' ), 'min' => 0, 'max' => 90 );
                $fields[] = array( 'key' => 'discount.per_visitor', 'type' => 'number', 'label' => __( 'کد در هر بازه برای هر بازدیدکننده', 'hami-ai-assistant' ), 'min' => 1, 'max' => 20 );
                $fields[] = array( 'key' => 'discount.rate_minutes', 'type' => 'number', 'label' => __( 'بازه (دقیقه)', 'hami-ai-assistant' ), 'min' => 1, 'max' => 1440 );
                $fields[] = array( 'key' => 'discount.coupon_expiry_days', 'type' => 'number', 'label' => __( 'اعتبار کد (روز)', 'hami-ai-assistant' ), 'min' => 1, 'max' => 365 );
                $fields[] = array( 'key' => 'discount.min_cart', 'type' => 'number', 'label' => __( 'حداقل مبلغ سبد (۰ = بدون حد)', 'hami-ai-assistant' ), 'min' => 0, 'max' => 99999999 );
                $fields[] = array( 'key' => 'discount.max_uses', 'type' => 'number', 'label' => __( 'دفعات مجاز هر کد', 'hami-ai-assistant' ), 'min' => 1, 'max' => 100 );
                return $fields;
        }

        /**
         * @param string $parent Parent slug.
         * @return void
         */
        public function register_menu( $parent ) {
                add_submenu_page(
                        $parent,
                        __( 'تخفیف‌ها', 'hami-ai-assistant' ),
                        __( 'تخفیف‌ها', 'hami-ai-assistant' ),
                        'manage_options',
                        Haa_Util::screen( 'discount' ),
                        array( $this, 'render_screen' )
                );
        }

        /* ------------------------------------------------------------------
         * Chat integration
         * ---------------------------------------------------------------- */

        /**
         * Give the chatbot the facts it needs to haggle honestly: how far it may
         * go and whether this visitor has already had their share.
         *
         * @param array  $context Context built so far.
         * @param string $message The user message.
         * @return array
         */
        public function chat_context( $context, $message ) {
                if ( ! Haa_Settings::is_module_enabled( 'discount' ) || empty( Haa_Settings::get( 'discount.enabled' ) ) ) {
                        return $context;
                }
                $norm = Haa_Search::normalize_text( $message );

                // Only inject discount context when the visitor is actually
                // negotiating or asking for a discount — not when they merely
                // ask about the price. Keywords are tuned for Persian haggling.
                $negotiation_patterns = array(
                        'تخفیف',            // discount
                        'تخفيف',            // discount (Arabic yeh variant)
                        'ارزان',            // cheap / cheaper
                        'ارزون',            // cheap (colloquial)
                        'گرون',             // expensive
                        'گرانبها',          // costly
                        'کد تخفیف',         // discount code
                        'کم کن',            // reduce it
                        'کمتر',             // less
                        'تخفیف بده',        // give discount
                        'تخفیف میدی',       // will you give discount
                        'تخفیف دارید',      // do you have discount
                        'تخفیف داره',       // does it have discount
                        'چانه',             // haggle
                        'discount',
                        '%\s*off',          // "10% off"
                        'تخفیف\s*خورده',   // discounted
                );

                $matched = false;
                foreach ( $negotiation_patterns as $pattern ) {
                        if ( preg_match( '/' . $pattern . '/u', $norm ) ) {
                                $matched = true;
                                break;
                        }
                }
                if ( ! $matched ) {
                        return $context;
                }

                $identifier = Haa_REST::identifier();
                $allowed    = self::allowed_percent( $identifier );

                $context['discount'] = array(
                        'allowed_percent' => $allowed,
                        'remaining_round' => max( 0, (int) Haa_Settings::get( 'discount.max_rounds', 3 ) - self::rounds_used( $identifier ) ),
                        'per_visitor'     => max( 0, (int) Haa_Settings::get( 'discount.per_visitor', 1 ) - self::rounds_used( $identifier ) ),
                        'expiry_days'     => (int) Haa_Settings::get( 'discount.coupon_expiry_days', 7 ),
                        'min_cart'        => (int) Haa_Settings::get( 'discount.min_cart', 0 ),
                );
                return $context;
        }

        /**
         * How many percent this visitor may still be offered.
         *
         * @param string $identifier Visitor identity.
         * @return int 0 when nothing more may be given.
         */
        public static function allowed_percent( $identifier ) {
                $hard = (int) Haa_Settings::get( 'discount.global_max_percent', 15 );
                $soft = (int) Haa_Settings::get( 'discount.default_max_percent', 10 );
                $cap  = min( $hard, $soft );

                global $wpdb;
                $table = Haa_DB::table( 'coupons' );
                $mins  = max( 1, (int) Haa_Settings::get( 'discount.rate_minutes', 60 ) );
                $since = gmdate( 'Y-m-d H:i:s', time() - $mins * 60 );

                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                $used = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(MAX(percent),0) FROM $table WHERE identifier = %s AND created >= %s", $identifier, $since ) );
                if ( $used >= $cap ) {
                        return 0;
                }
                return min( $cap - $used, $soft );
        }

        /**
         * @param string $identifier Visitor identity.
         * @return int
         */
        public static function rounds_used( $identifier ) {
                global $wpdb;
                $table = Haa_DB::table( 'coupons' );
                $mins  = max( 1, (int) Haa_Settings::get( 'discount.rate_minutes', 60 ) );
                $since = gmdate( 'Y-m-d H:i:s', time() - $mins * 60 );
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $table WHERE identifier = %s AND created >= %s", $identifier, $since ) );
        }

        /**
         * Issue a code. Called from the chat pipeline when the bot and the visitor
         * agree on a number that is still inside the allowed window.
         *
         * @param int    $percent     Agreed percent.
         * @param string $identifier  Visitor identity.
         * @param int    $product_id  Optional product to scope the code to.
         * @return array{ok:bool,error:?string,code:string,percent:int,expires:string}
         */
        public static function issue( $percent, $identifier, $product_id = 0 ) {
                $percent = (int) $percent;
                $allowed = self::allowed_percent( $identifier );

                if ( $percent <= 0 ) {
                        return self::issue_fail( __( 'درصد تخفیف نامعتبر است.', 'hami-ai-assistant' ) );
                }
                if ( $percent > $allowed ) {
                        return self::issue_fail(
                                sprintf(
                                        /* translators: %d: maximum percent. */
                                        __( 'بیشتر از %d درصد ممکن نیست.', 'hami-ai-assistant' ),
                                        $allowed
                                )
                        );
                }

                // Per-visitor limit: how many coupons this visitor may receive
                // within the rate window (discount.rate_minutes).
                $per_visitor = (int) Haa_Settings::get( 'discount.per_visitor', 1 );
                if ( $per_visitor > 0 && self::rounds_used( $identifier ) >= $per_visitor ) {
                        return self::issue_fail( __( 'سقف کد تخفیف برای شما پر شده است. لطفاً بعداً دوباره تلاش کنید.', 'hami-ai-assistant' ) );
                }

                $expires_in = max( 1, (int) Haa_Settings::get( 'discount.coupon_expiry_days', 7 ) );
                $expires    = gmdate( 'Y-m-d H:i:s', time() + $expires_in * DAY_IN_SECONDS );
                $code       = self::make_code( $percent );

                global $wpdb;
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery
                $wpdb->insert(
                        Haa_DB::table( 'coupons' ),
                        array(
                                'code'         => $code,
                                'percent'      => $percent,
                                'product_id'   => (int) $product_id,
                                'category_id'  => 0,
                                'lead_id'      => 0,
                                'identifier'   => substr( (string) $identifier, 0, 64 ),
                                'uses_allowed' => max( 1, (int) Haa_Settings::get( 'discount.max_uses', 1 ) ),
                                'uses_used'    => 0,
                                'expires'      => $expires,
                                'status'       => 'active',
                                'created'      => current_time( 'mysql', true ),
                        ),
                        array( '%s', '%d', '%d', '%d', '%d', '%s', '%d', '%d', '%s', '%s', '%s' )
                );

                self::create_wc_coupon( $code, $percent, $product_id, $expires, (int) $wpdb->insert_id );

                return array(
                        'ok'      => true,
                        'error'   => null,
                        'code'    => $code,
                        'percent' => $percent,
                        'expires' => $expires,
                );
        }

        /**
         * @param string $message Error.
         * @return array
         */
        private static function issue_fail( $message ) {
                return array(
                        'ok'      => false,
                        'error'   => $message,
                        'code'    => '',
                        'percent' => 0,
                        'expires' => '',
                );
        }

        /**
         * A real WC coupon so the discount works at checkout with or without us.
         *
         * @param string $code       Code.
         * @param int    $percent    Percent.
         * @param int    $product_id Scope (0 = whole cart).
         * @param string $expires    GMT datetime.
         * @param int    $ledger_id  Our row id, stored as meta.
         */
        private static function create_wc_coupon( $code, $percent, $product_id, $expires, $ledger_id ) {
                if ( ! haa_is_woo() ) {
                        return;
                }
                // WC_Discounts / WC_Coupon live only when WooCommerce is loaded.
                if ( ! class_exists( 'WC_Coupon' ) ) {
                        return;
                }

                $coupon = new WC_Coupon();
                $coupon->set_code( $code );
                $coupon->set_discount_type( 'percent' );
                $coupon->set_amount( (float) $percent );
                $coupon->set_individual_use( true );
                $coupon->set_usage_limit( max( 1, (int) Haa_Settings::get( 'discount.max_uses', 1 ) ) );
                $coupon->set_date_expires( $expires );
                $coupon->set_description(
                        sprintf(
                                /* translators: 1: percent. */
                                __( 'تخفیف %1$d٪ صادره توسط دستیار هوشمند', 'hami-ai-assistant' ),
                                (int) $percent
                        )
                );

                $min_cart = (int) Haa_Settings::get( 'discount.min_cart', 0 );
                if ( $min_cart > 0 ) {
                        $coupon->set_minimum_amount( (string) $min_cart );
                }

                if ( $product_id ) {
                        $coupon->set_product_ids( array( (int) $product_id ) );
                }

                // Ledger id so a redeemed code can be matched back to our row.
                $coupon->save();
                update_post_meta( (int) $coupon->get_id(), '_haai_ledger_id', (int) $ledger_id );
        }

        /**
         * @param int $percent Percent.
         * @return string
         */
        private static function make_code( $percent ) {
                $prefix = 'HAAI' . (int) $percent . '-';
                for ( $i = 0; $i < 8; $i++ ) {
                        $code = $prefix . strtoupper( wp_generate_password( 8, false, false ) );
                        global $wpdb;
                        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
                        $exists = $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . Haa_DB::table( 'coupons' ) . ' WHERE code = %s', $code ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                        if ( ! $exists ) {
                                return $code;
                        }
                }
                return $prefix . substr( md5( (string) microtime( true ) ), 0, 8 );
        }

        /**
         * @param int      $coupon_id  WC coupon id.
         * @param int      $discounted Discounted amount.
         * @param WC_Coupon $coupon    Coupon object.
         */
        public function on_redeem( $coupon_id, $discounted = 0, $coupon = null ) {
                if ( ! $coupon instanceof WC_Coupon ) {
                        return;
                }
                $ledger = (int) get_post_meta( (int) $coupon->get_id(), '_haai_ledger_id', true );
                if ( ! $ledger ) {
                        return;
                }
                global $wpdb;
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery
                $wpdb->query( $wpdb->prepare( 'UPDATE ' . Haa_DB::table( 'coupons' ) . ' SET uses_used = uses_used + 1 WHERE id = %d', $ledger ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        }

        /* ------------------------------------------------------------------
         * Screen
         * ---------------------------------------------------------------- */

        /** @return void */
        public function render_screen() {
                if ( ! current_user_can( 'manage_options' ) ) {
                        return;
                }

                global $wpdb;
                $table = Haa_DB::table( 'coupons' );
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                $rows = $wpdb->get_results( "SELECT * FROM $table ORDER BY id DESC LIMIT 200", ARRAY_A );
                ?>
                <div class="wrap haai-wrap">
                        <?php Haa_Admin::page_header( __( 'تخفیف‌ها', 'hami-ai-assistant' ), 'discount' ); ?>

                        <?php
                        $err = isset( $_GET['haai_err'] ) ? sanitize_text_field( wp_unslash( $_GET['haai_err'] ) ) : '';
                        $msg = isset( $_GET['haai_msg'] ) ? sanitize_text_field( wp_unslash( $_GET['haai_msg'] ) ) : '';
                        if ( '' !== $err ) {
                                printf( '<div class="notice notice-error inline"><p>%s</p></div>', esc_html( $err ) );
                        }
                        if ( '' !== $msg ) {
                                printf( '<div class="notice notice-success inline"><p>%s</p></div>', esc_html( $msg ) );
                        }
                        ?>

                        <p class="description">
                                <?php
                                printf(
                                        /* translators: 1: global max percent. */
                                        esc_html__( 'سقف مطلق تخفیف: ٪%1$d — هر کد به‌صورت واقعی در ووکامرس هم ساخته می‌شود.', 'hami-ai-assistant' ),
                                        (int) Haa_Settings::get( 'discount.global_max_percent', 15 )
                                );
                                ?>
                        </p>

                        <table class="widefat striped haai-table">
                                <thead>
                                        <tr>
                                                <th><?php esc_html_e( 'کد', 'hami-ai-assistant' ); ?></th>
                                                <th><?php esc_html_e( 'درصد', 'hami-ai-assistant' ); ?></th>
                                                <th><?php esc_html_e( 'مصرف', 'hami-ai-assistant' ); ?></th>
                                                <th><?php esc_html_e( 'انقضا', 'hami-ai-assistant' ); ?></th>
                                                <th><?php esc_html_e( 'وضعیت', 'hami-ai-assistant' ); ?></th>
                                                <th><?php esc_html_e( 'صادرشده', 'hami-ai-assistant' ); ?></th>
                                                <th></th>
                                        </tr>
                                </thead>
                                <tbody>
                                        <?php if ( empty( $rows ) ) : ?>
                                                <tr><td colspan="7">
                                                        <div class="haai-empty">
                                                                <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 18 18 6"/><circle cx="8" cy="8" r="2"/><circle cx="16" cy="16" r="2"/></svg>
                                                                <span class="haai-empty__title"><?php esc_html_e( 'کدی صادر نشده است.', 'hami-ai-assistant' ); ?></span>
                                                                <p class="haai-empty__hint"><?php esc_html_e( 'وقتی مذاکره در چت به توافق برسد، کد واقعی همین‌جا (و در ووکامرس) ساخته می‌شود.', 'hami-ai-assistant' ); ?></p>
                                                        </div>
                                                </td></tr>
                                        <?php else : ?>
                                                <?php foreach ( $rows as $r ) : ?>
                                                        <tr>
                                                                <td><code><?php echo esc_html( $r['code'] ); ?></code></td>
                                                                <td><?php echo esc_html( $r['percent'] ); ?>٪</td>
                                                                <td><?php echo esc_html( $r['uses_used'] . ' / ' . $r['uses_allowed'] ); ?></td>
                                                                <td><?php echo esc_html( mysql2date( 'Y-m-d', $r['expires'] ) ); ?></td>
                                                                <td>
                                                                        <span class="haai-badge <?php echo 'active' === $r['status'] ? 'haai-badge--on' : ''; ?>">
                                                                                <?php echo esc_html( $r['status'] ); ?>
                                                                        </span>
                                                                </td>
                                                                <td><?php echo esc_html( mysql2date( 'Y-m-d H:i', $r['created'] ) ); ?></td>
                                                                <td>
                                                                        <?php if ( 'active' === $r['status'] ) : ?>
                                                                                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="haai-inline" data-haai-confirm>
                                                                                        <?php wp_nonce_field( 'haai_disc' ); ?>
                                                                                        <input type="hidden" name="action" value="haai_disc_revoke">
                                                                                        <input type="hidden" name="coupon_id" value="<?php echo esc_attr( $r['id'] ); ?>">
                                                                                        <button type="submit" class="button-link-delete"><?php esc_html_e( 'ابطال', 'hami-ai-assistant' ); ?></button>
                                                                                </form>
                                                                        <?php endif; ?>
                                                                </td>
                                                        </tr>
                                                <?php endforeach; ?>
                                        <?php endif; ?>
                                </tbody>
                        </table>

                        <h2><?php esc_html_e( 'صدور دستی', 'hami-ai-assistant' ); ?></h2>
                        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="haai-filter">
                                <?php wp_nonce_field( 'haai_disc' ); ?>
                                <input type="hidden" name="action" value="haai_disc_issue">
                                <input type="number" name="percent" min="1" max="<?php echo esc_attr( (int) Haa_Settings::get( 'discount.global_max_percent', 15 ) ); ?>" value="5" required class="small-text" aria-label="<?php esc_attr_e( 'درصد تخفیف', 'hami-ai-assistant' ); ?>">
                                <input type="number" name="product_id" min="0" placeholder="<?php esc_attr_e( 'شناسه محصول', 'hami-ai-assistant' ); ?>" class="haai-note" aria-label="<?php esc_attr_e( 'شناسه محصول', 'hami-ai-assistant' ); ?>">
                                <button type="submit" class="button button-primary"><?php esc_html_e( 'صدور کد', 'hami-ai-assistant' ); ?></button>
                                <p class="description"><?php esc_html_e( 'اگر شناسه محصول را خالی بگذارید (۰)، تخفیف روی کل سبد اعمال می‌شود.', 'hami-ai-assistant' ); ?></p>
                        </form>
                </div>
                <?php
        }

        /** @return void */
        public function handle_issue() {
                Haa_Util::guard_admin_post( 'haai_disc' );
                $percent    = isset( $_POST['percent'] ) ? absint( $_POST['percent'] ) : 0;
                $product_id = isset( $_POST['product_id'] ) ? absint( $_POST['product_id'] ) : 0;

                $result = self::issue( $percent, 'admin:' . get_current_user_id(), $product_id );
                if ( empty( $result['ok'] ) ) {
                        $this->back( $result['error'] );
                }
                $this->back(
                        sprintf(
                                /* translators: 1: code. 2: percent. */
                                __( 'کد %1$s به میزان ٪%2$d صادر شد.', 'hami-ai-assistant' ),
                                $result['code'],
                                $result['percent']
                        )
                );
        }

        /** @return void */
        public function handle_revoke() {
                Haa_Util::guard_admin_post( 'haai_disc' );
                $id = isset( $_POST['coupon_id'] ) ? absint( $_POST['coupon_id'] ) : 0;
                if ( ! $id ) {
                        $this->back( __( 'کد پیدا نشد.', 'hami-ai-assistant' ) );
                }

                global $wpdb;
                $table = Haa_DB::table( 'coupons' );
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery
                $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE id = %d", $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                if ( ! $row ) {
                        $this->back( __( 'کد پیدا نشد.', 'hami-ai-assistant' ) );
                }

                // phpcs:ignore WordPress.DB.DirectDatabaseQuery
                $wpdb->update( $table, array( 'status' => 'revoked' ), array( 'id' => $id ), array( '%s' ), array( '%d' ) );

                if ( haa_is_woo() && class_exists( 'WC_Coupon' ) ) {
                        $coupon = new WC_Coupon( $row['code'] );
                        if ( $coupon->get_id() ) {
                                $coupon->set_status( 'trash' );
                                $coupon->save();
                        }
                }
                $this->back( '' );
        }

        /** @return void */
        private function back( $message = '' ) {
                if ( Haa_Ajax_Form::respond( '' !== $message ? $message : __( 'انجام شد.', 'hami-ai-assistant' ), false ) ) {
                        return;
                }
                $args = array();
                if ( '' !== $message ) {
                        $args['haai_msg'] = rawurlencode( Haa_Util::cut( $message, 300 ) );
                }
                wp_safe_redirect( Haa_Util::screen_url( 'discount', $args ) );
                exit;
        }
}
