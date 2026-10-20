<?php
/**
 * Module: Autopilot — scheduled campaigns that keep publishing on their own.
 *
 * A campaign is a repeating brief: topic, audience, tone, cadence. The five
 * minute cron looks for anything due, pushes one queued job per article and
 * respects the daily token and post caps so a misconfigured campaign cannot
 * drain the budget overnight.
 *
 * @package Hami_AI_Assistant
 */

if ( ! defined( 'ABSPATH' ) ) {
        exit;
}

final class Haa_Module_Autopilot implements Haa_Module_Interface {

        /** @return string */
        public function slug() {
                return 'autopilot';
        }

        /** @return string */
        public function label() {
                return __( 'اتوپایلوت', 'hami-ai-assistant' );
        }

        /** @return string */
        public function description() {
                return __( 'کمپین‌های زمان‌بندی‌شده که خودشان مقاله می‌سازند و منتشر می‌کنند؛ با سقف روزانه برای توکن و تعداد پست.', 'hami-ai-assistant' );
        }

        /** @return bool */
        public function requires_woocommerce() {
                // Single source of truth: Haa_Modules::catalog_meta()[ 'woo' ].
                return Haa_Modules::requires_woocommerce( $this->slug() );
        }

        /** @return void */
        public function register() {
                add_action( 'haai_settings_sections', array( $this, 'register_section' ), 10 );
                add_action( 'haai_settings_fields_autopilot', array( $this, 'register_fields' ) );
                add_action( 'haai_admin_menus', array( $this, 'register_menu' ) );
                add_action( 'admin_post_haai_ap_save', array( $this, 'handle_save' ) );
                add_action( 'admin_post_haai_ap_toggle', array( $this, 'handle_toggle' ) );
                add_action( 'admin_post_haai_ap_delete', array( $this, 'handle_delete' ) );
                add_action( 'haai_autopilot_tick', array( $this, 'tick' ) );
                add_action( 'haai_run_job_ap_article', array( $this, 'run_job' ), 10, 2 );
        }

        /** @return void */
        public function activate() {
                Haa_DB::create();
                if ( ! wp_next_scheduled( 'haai_autopilot_tick' ) ) {
                        wp_schedule_event( time() + MINUTE_IN_SECONDS * 5, 'haai_five_minutes', 'haai_autopilot_tick' );
                }
        }

        /** @return void */
        public function deactivate() {
                wp_clear_scheduled_hook( 'haai_autopilot_tick' );
        }

        /**
         * @param array $sections Tabs so far.
         * @return array
         */
        public function register_section( $sections ) {
                $sections['autopilot'] = __( 'اتوپایلوت', 'hami-ai-assistant' );
                return $sections;
        }

        /**
         * @param array $fields Fields so far.
         * @return array
         */
        public function register_fields( $fields ) {
                $fields[] = array( 'key' => 'autopilot.daily_token_cap', 'type' => 'number', 'label' => __( 'سقف توکن روزانه', 'hami-ai-assistant' ), 'min' => 0, 'max' => 10000000 );
                $fields[] = array( 'key' => 'autopilot.daily_post_cap', 'type' => 'number', 'label' => __( 'سقف پست روزانه', 'hami-ai-assistant' ), 'min' => 0, 'max' => 100 );
                $fields[] = array( 'key' => 'autopilot.retry_limit', 'type' => 'number', 'label' => __( 'تلاش مجدد', 'hami-ai-assistant' ), 'min' => 0, 'max' => 5 );
                $fields[] = array( 'key' => 'autopilot.default_status', 'type' => 'select', 'label' => __( 'وضعیت پیش‌فرض', 'hami-ai-assistant' ), 'choices' => array( 'draft' => __( 'پیش‌نویس', 'hami-ai-assistant' ), 'pending' => __( 'در انتظار بررسی', 'hami-ai-assistant' ) ) );
                $fields[] = array( 'key' => 'autopilot.default_tone', 'type' => 'select', 'label' => __( 'لحن پیش‌فرض', 'hami-ai-assistant' ), 'choices' => Haa_Util::tones() );
                $fields[] = array( 'key' => 'autopilot.default_length', 'type' => 'select', 'label' => __( 'طول پیش‌فرض', 'hami-ai-assistant' ), 'choices' => Haa_Util::lengths() );
                $fields[] = array( 'key' => 'autopilot.enable_images', 'type' => 'checkbox', 'label' => __( 'تصویر شاخص', 'hami-ai-assistant' ) );
                $fields[] = array( 'key' => 'autopilot.default_category', 'type' => 'number', 'label' => __( 'دسته پیش‌فرض (شناسه)', 'hami-ai-assistant' ), 'min' => 0, 'max' => 999999 );
                return $fields;
        }

        /**
         * @param string $parent Parent slug.
         * @return void
         */
        public function register_menu( $parent ) {
                add_submenu_page(
                        $parent,
                        __( 'اتوپایلوت', 'hami-ai-assistant' ),
                        __( 'اتوپایلوت', 'hami-ai-assistant' ),
                        'manage_options',
                        Haa_Util::screen( 'autopilot' ),
                        array( $this, 'render_screen' )
                );
        }

        /** @return void */
        public function render_screen() {
                if ( ! current_user_can( 'manage_options' ) ) {
                        return;
                }

                global $wpdb;
                $table = Haa_DB::table( 'campaigns' );
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                $rows = $wpdb->get_results( "SELECT * FROM $table ORDER BY id DESC", ARRAY_A );

                $editing = null;
                if ( isset( $_GET['edit'] ) ) {
                        $edit_id = absint( $_GET['edit'] );
                        foreach ( (array) $rows as $r ) {
                                if ( (int) $r['id'] === $edit_id ) {
                                        $editing = $r;
                                        break;
                                }
                        }
                }
                ?>
                <div class="wrap haai-wrap">
                        <?php Haa_Admin::page_header( __( 'اتوپایلوت', 'hami-ai-assistant' ) ); ?>

                        <?php
                        $err = isset( $_GET['haai_err'] ) ? sanitize_text_field( wp_unslash( $_GET['haai_err'] ) ) : '';
                        $msg = isset( $_GET['haai_msg'] ) ? sanitize_text_field( wp_unslash( $_GET['haai_msg'] ) ) : '';
                        if ( '' !== $err ) {
                                printf( '<div class="notice notice-error inline"><p>%s</p></div>', esc_html( $err ) );
                        }
                        if ( '' !== $msg ) {
                                printf( '<div class="notice notice-success inline"><p>%s</p></div>', esc_html( $msg ) );
                        }
                        $posts_today = (int) $wpdb->get_var(
                                $wpdb->prepare(
                                        "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'post' AND post_status IN ('draft','pending','publish') AND post_date >= %s",
                                        gmdate( 'Y-m-d 00:00:00' )
                                )
                        );
                        $token_cap = (int) Haa_Settings::get( 'autopilot.daily_token_cap', 300000 );
                        $post_cap  = (int) Haa_Settings::get( 'autopilot.daily_post_cap', 3 );
                        $tokens    = Haa_Usage::tokens_today();
                        ?>

                        <div class="haai-cards">
                                <div class="haai-card">
                                        <div class="haai-card__value"><?php echo esc_html( number_format_i18n( $tokens ) . ' / ' . number_format_i18n( $token_cap ) ); ?></div>
                                        <div class="haai-card__label"><?php esc_html_e( 'توکن امروز', 'hami-ai-assistant' ); ?></div>
                                </div>
                                <div class="haai-card">
                                        <div class="haai-card__value"><?php echo esc_html( number_format_i18n( $posts_today ) . ' / ' . number_format_i18n( $post_cap ) ); ?></div>
                                        <div class="haai-card__label"><?php esc_html_e( 'پست امروز', 'hami-ai-assistant' ); ?></div>
                                </div>
                                <div class="haai-card">
                                        <div class="haai-card__value"><?php echo esc_html( number_format_i18n( Haa_Queue::pending( 'autopilot' ) ) ); ?></div>
                                        <div class="haai-card__label"><?php esc_html_e( 'در صف', 'hami-ai-assistant' ); ?></div>
                                </div>
                        </div>

                        <h2><?php echo $editing ? esc_html__( 'ویرایش کمپین', 'hami-ai-assistant' ) : esc_html__( 'کمپین جدید', 'hami-ai-assistant' ); ?></h2>

                        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                                <?php wp_nonce_field( 'haai_ap' ); ?>
                                <input type="hidden" name="action" value="haai_ap_save">
                                <input type="hidden" name="id" value="<?php echo esc_attr( $editing ? $editing['id'] : 0 ); ?>">

                                <div class="haai-field">
                                        <label class="haai-field__label"><?php esc_html_e( 'نام کمپین', 'hami-ai-assistant' ); ?></label>
                                        <div class="haai-field__control"><input type="text" class="regular-text" name="name" required value="<?php echo esc_attr( $editing ? $editing['name'] : '' ); ?>"></div>
                                </div>
                                <div class="haai-field">
                                        <label class="haai-field__label"><?php esc_html_e( 'موضوع (هر اجرا یک مقاله)', 'hami-ai-assistant' ); ?></label>
                                        <div class="haai-field__control"><input type="text" class="regular-text" name="topic" required value="<?php echo esc_attr( $editing ? $editing['topic'] : '' ); ?>"></div>
                                </div>
                                <div class="haai-field">
                                        <label class="haai-field__label"><?php esc_html_e( 'مخاطب', 'hami-ai-assistant' ); ?></label>
                                        <div class="haai-field__control"><input type="text" class="regular-text" name="audience" value="<?php echo esc_attr( $editing ? $editing['audience'] : '' ); ?>"></div>
                                </div>
                                <div class="haai-field">
                                        <label class="haai-field__label"><?php esc_html_e( 'هدف', 'hami-ai-assistant' ); ?></label>
                                        <div class="haai-field__control"><input type="text" class="regular-text" name="goal" value="<?php echo esc_attr( $editing ? $editing['goal'] : '' ); ?>"></div>
                                </div>
                                <div class="haai-field">
                                        <label class="haai-field__label"><?php esc_html_e( 'کلیدواژه‌ها', 'hami-ai-assistant' ); ?></label>
                                        <div class="haai-field__control"><input type="text" class="regular-text" name="keywords" value="<?php echo esc_attr( $editing ? $editing['keywords'] : '' ); ?>"></div>
                                </div>
                                <div class="haai-field">
                                        <label class="haai-field__label"><?php esc_html_e( 'تناوب', 'hami-ai-assistant' ); ?></label>
                                        <div class="haai-field__control">
                                                <select name="frequency">
                                                        <?php
                                                        $freq = $editing ? $editing['frequency'] : 'daily';
                                                        foreach ( array( 'hourly', 'twicedaily', 'daily', 'weekly' ) as $f ) {
                                                                printf(
                                                                        '<option value="%s" %s>%s</option>',
                                                                        esc_attr( $f ),
                                                                        selected( $f, $freq, false ),
                                                                        esc_html( $f )
                                                                );
                                                        }
                                                        ?>
                                                </select>
                                        </div>
                                </div>
                                <div class="haai-field">
                                        <label class="haai-field__label"><?php esc_html_e( 'ساعت اجرا', 'hami-ai-assistant' ); ?></label>
                                        <div class="haai-field__control"><input type="time" name="run_at" value="<?php echo esc_attr( $editing ? $editing['run_at'] : '06:00' ); ?>"></div>
                                </div>
                                <div class="haai-field">
                                        <label class="haai-field__label"><?php esc_html_e( 'تعداد در هر اجرا', 'hami-ai-assistant' ); ?></label>
                                        <div class="haai-field__control"><input type="number" name="per_run" min="1" max="5" value="<?php echo esc_attr( $editing ? $editing['per_run'] : 1 ); ?>"></div>
                                </div>
                                <div class="haai-field">
                                        <label class="haai-field__label"><?php esc_html_e( 'وضعیت خروجی', 'hami-ai-assistant' ); ?></label>
                                        <div class="haai-field__control">
                                                <select name="status_out">
                                                        <?php
                                                        $out_status = $editing ? $editing['status_out'] : (string) Haa_Settings::get( 'autopilot.default_status' );
                                                        foreach ( array( 'draft' => __( 'پیش‌نویس', 'hami-ai-assistant' ), 'pending' => __( 'در انتظار بررسی', 'hami-ai-assistant' ) ) as $k => $l ) {
                                                                printf( '<option value="%s" %s>%s</option>', esc_attr( $k ), selected( $k, $out_status, false ), esc_html( $l ) );
                                                        }
                                                        ?>
                                                </select>
                                        </div>
                                </div>
                                <div class="haai-field">
                                        <label class="haai-field__label"><?php esc_html_e( 'تصویر شاخص', 'hami-ai-assistant' ); ?></label>
                                        <div class="haai-field__control">
                                                <label class="haai-checkbox">
                                                        <input type="checkbox" name="with_image" value="1" <?php checked( $editing ? ! empty( $editing['with_image'] ) : ! empty( Haa_Settings::get( 'autopilot.enable_images' ) ) ); ?>>
                                                        <?php esc_html_e( 'تصویر بساز', 'hami-ai-assistant' ); ?>
                                                </label>
                                        </div>
                                </div>

                                <p class="submit">
                                        <button type="submit" class="button button-primary"><?php esc_html_e( 'ذخیره کمپین', 'hami-ai-assistant' ); ?></button>
                                        <?php if ( $editing ) : ?>
                                                <a class="button" href="<?php echo esc_url( Haa_Util::screen_url( 'autopilot' ) ); ?>"><?php esc_html_e( 'انصراف', 'hami-ai-assistant' ); ?></a>
                                        <?php endif; ?>
                                </p>
                        </form>

                        <h2><?php esc_html_e( 'کمپین‌ها', 'hami-ai-assistant' ); ?></h2>
                        <table class="widefat striped haai-table">
                                <thead>
                                        <tr>
                                                <th><?php esc_html_e( 'نام', 'hami-ai-assistant' ); ?></th>
                                                <th><?php esc_html_e( 'موضوع', 'hami-ai-assistant' ); ?></th>
                                                <th><?php esc_html_e( 'تناوب', 'hami-ai-assistant' ); ?></th>
                                                <th><?php esc_html_e( 'ساخته‌شده', 'hami-ai-assistant' ); ?></th>
                                                <th><?php esc_html_e( 'اجرا بعدی', 'hami-ai-assistant' ); ?></th>
                                                <th><?php esc_html_e( 'وضعیت', 'hami-ai-assistant' ); ?></th>
                                                <th></th>
                                        </tr>
                                </thead>
                                <tbody>
                                        <?php if ( empty( $rows ) ) : ?>
                                                <tr><td colspan="7">
                                                        <div class="haai-empty">
                                                                <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 4l1.9 4.1L18 10l-4.1 1.9L12 16l-1.9-4.1L6 10l4.1-1.9z"/><path d="M18 15l.8 1.9L20.7 18l-1.9.8L18 20.7l-.8-1.9L15.3 18l1.9-.8z"/></svg>
                                                                <span class="haai-empty__title"><?php esc_html_e( 'کمپینی نیست.', 'hami-ai-assistant' ); ?></span>
                                                                <p class="haai-empty__hint"><?php esc_html_e( 'یک کمپین بساز تا مقاله‌ها سر ساعت تعیین‌شده خودشان نوشته و منتشر شوند.', 'hami-ai-assistant' ); ?></p>
                                                        </div>
                                                </td></tr>
                                        <?php else : ?>
                                                <?php foreach ( $rows as $r ) : ?>
                                                        <tr>
                                                                <td><strong><?php echo esc_html( $r['name'] ); ?></strong></td>
                                                                <td><?php echo esc_html( Haa_Util::cut( $r['topic'], 60 ) ); ?></td>
                                                                <td><?php echo esc_html( $r['frequency'] ); ?> @ <?php echo esc_html( $r['run_at'] ); ?></td>
                                                                <td><?php echo esc_html( number_format_i18n( (int) $r['posts_done'] ) ); ?></td>
                                                                <td><?php echo esc_html( $r['next_run'] ? mysql2date( 'Y-m-d H:i', $r['next_run'] ) : '—' ); ?></td>
                                                                <td>
                                                                        <span class="haai-badge <?php echo 'active' === $r['status'] ? 'haai-badge--on' : ''; ?>">
                                                                                <?php echo esc_html( $r['status'] ); ?>
                                                                        </span>
                                                                </td>
                                                                <td>
                                                                        <a href="<?php echo esc_url( Haa_Util::screen_url( 'autopilot', array( 'edit' => $r['id'] ) ) ); ?>"><?php esc_html_e( 'ویرایش', 'hami-ai-assistant' ); ?></a>
                                                                        |
                                                                        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="haai-inline">
                                                                                <?php wp_nonce_field( 'haai_ap' ); ?>
                                                                                <input type="hidden" name="action" value="haai_ap_toggle">
                                                                                <input type="hidden" name="id" value="<?php echo esc_attr( $r['id'] ); ?>">
                                                                                <button type="submit" class="button-link"><?php echo 'active' === $r['status'] ? esc_html__( 'توقف', 'hami-ai-assistant' ) : esc_html__( 'فعال‌سازی', 'hami-ai-assistant' ); ?></button>
                                                                        </form>
                                                                        |
                                                                        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="haai-inline" data-haai-confirm>
                                                                                <?php wp_nonce_field( 'haai_ap' ); ?>
                                                                                <input type="hidden" name="action" value="haai_ap_delete">
                                                                                <input type="hidden" name="id" value="<?php echo esc_attr( $r['id'] ); ?>">
                                                                                <button type="submit" class="button-link-delete"><?php esc_html_e( 'حذف', 'hami-ai-assistant' ); ?></button>
                                                                        </form>
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
        public function handle_save() {
                Haa_Util::guard_admin_post( 'haai_ap' );

                $id       = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
                $name     = Haa_Util::cut( sanitize_text_field( isset( $_POST['name'] ) ? wp_unslash( $_POST['name'] ) : '' ), 180 );
                $topic    = Haa_Util::cut( sanitize_text_field( isset( $_POST['topic'] ) ? wp_unslash( $_POST['topic'] ) : '' ), 180 );
                $audience = Haa_Util::cut( sanitize_text_field( isset( $_POST['audience'] ) ? wp_unslash( $_POST['audience'] ) : '' ), 180 );
                $goal     = Haa_Util::cut( sanitize_text_field( isset( $_POST['goal'] ) ? wp_unslash( $_POST['goal'] ) : '' ), 180 );
                $keywords = Haa_Util::cut( sanitize_text_field( isset( $_POST['keywords'] ) ? wp_unslash( $_POST['keywords'] ) : '' ), 300 );
                $freq     = isset( $_POST['frequency'] ) ? sanitize_key( wp_unslash( $_POST['frequency'] ) ) : 'daily';
                $run_at   = isset( $_POST['run_at'] ) ? preg_replace( '/[^0-9:]/', '', (string) wp_unslash( $_POST['run_at'] ) ) : '06:00';
                $per_run  = isset( $_POST['per_run'] ) ? min( 5, max( 1, absint( $_POST['per_run'] ) ) ) : 1;
                $status   = isset( $_POST['status_out'] ) ? sanitize_key( wp_unslash( $_POST['status_out'] ) ) : 'draft';
                $with_img = ! empty( $_POST['with_image'] ) ? 1 : 0;

                if ( '' === $name || '' === $topic ) {
                        $this->back( __( 'نام و موضوع الزامی است.', 'hami-ai-assistant' ) );
                }
                if ( ! in_array( $freq, array( 'hourly', 'twicedaily', 'daily', 'weekly' ), true ) ) {
                        $freq = 'daily';
                }
                if ( ! in_array( $status, array( 'draft', 'pending' ), true ) ) {
                        $status = 'draft';
                }
                if ( ! preg_match( '/^\d{1,2}:\d{2}$/', $run_at ) ) {
                        $run_at = '06:00';
                }

                global $wpdb;
                $table = Haa_DB::table( 'campaigns' );
                $now   = current_time( 'mysql', true );

                $data = array(
                        'name'         => $name,
                        'topic'        => $topic,
                        'audience'     => $audience,
                        'goal'         => $goal,
                        'keywords'     => $keywords,
                        'tone'         => (string) Haa_Settings::get( 'autopilot.default_tone', 'friendly' ),
                        'language'     => (string) Haa_Settings::get( 'content.default_language', 'fa' ),
                        'length_slug'  => (string) Haa_Settings::get( 'autopilot.default_length', 'medium' ),
                        'category_id'  => (int) Haa_Settings::get( 'autopilot.default_category', 0 ),
                        'status_out'   => $status,
                        'with_image'   => $with_img,
                        'frequency'    => $freq,
                        'run_at'       => $run_at,
                        'per_run'      => $per_run,
                        'next_run'     => self::compute_next_run( $freq, $run_at ),
                        'updated'      => $now,
                );

                if ( $id ) {
                        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
                        $wpdb->update( $table, $data, array( 'id' => $id ), array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%d', '%s', '%s', '%d', '%s', '%s' ), array( '%d' ) );
                } else {
                        $data['status']   = 'active';
                        $data['created']  = $now;
                        $data['posts_done'] = 0;
                        $data['tokens_used'] = 0;
                        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
                        $wpdb->insert( $table, $data, array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%d', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%d', '%d' ) );
                }
                $this->back( '' );
        }

        /** @return void */
        public function handle_toggle() {
                Haa_Util::guard_admin_post( 'haai_ap' );
                $id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
                if ( ! $id ) {
                        $this->back( __( 'کمپین پیدا نشد.', 'hami-ai-assistant' ) );
                }
                global $wpdb;
                $table = Haa_DB::table( 'campaigns' );
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery
                $row = $wpdb->get_row( $wpdb->prepare( "SELECT status FROM $table WHERE id = %d", $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                if ( ! $row ) {
                        $this->back( __( 'کمپین پیدا نشد.', 'hami-ai-assistant' ) );
                }
                $new = 'active' === $row['status'] ? 'paused' : 'active';
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery
                $wpdb->update( $table, array( 'status' => $new ), array( 'id' => $id ), array( '%s' ), array( '%d' ) );
                $this->back( '' );
        }

        /** @return void */
        public function handle_delete() {
                Haa_Util::guard_admin_post( 'haai_ap' );
                $id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
                if ( $id ) {
                        global $wpdb;
                        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
                        $wpdb->delete( Haa_DB::table( 'campaigns' ), array( 'id' => $id ), array( '%d' ) );
                }
                $this->back( '' );
        }

        /* ------------------------------------------------------------------
         * Cron
         * ---------------------------------------------------------------- */

        /**
         * Look for due campaigns and queue their articles. Never generates here —
         * this runs inside WP-Cron which is already near the timeout.
         */
        public function tick() {
                if ( ! Haa_Settings::is_module_enabled( 'autopilot' ) ) {
                        return;
                }

                // Token cap is a hard stop for the whole day.
                $token_cap = (int) Haa_Settings::get( 'autopilot.daily_token_cap', 300000 );
                if ( $token_cap > 0 && Haa_Usage::tokens_today() >= $token_cap ) {
                        return;
                }
                $post_cap = (int) Haa_Settings::get( 'autopilot.daily_post_cap', 3 );
                if ( $post_cap > 0 && Haa_Usage::autopilot_posts_today() >= $post_cap ) {
                        return;
                }

                // Concurrency lock: two overlapping WP-Cron requests must not both
                // enqueue jobs for the same campaigns.
                if ( get_transient( 'haai_autopilot_lock' ) ) {
                        return;
                }
                set_transient( 'haai_autopilot_lock', 1, 5 * MINUTE_IN_SECONDS );

                global $wpdb;
                $table = Haa_DB::table( 'campaigns' );
                $now   = current_time( 'mysql', true );

                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                $due = $wpdb->get_results(
                        $wpdb->prepare( "SELECT * FROM $table WHERE status = 'active' AND (next_run IS NULL OR next_run <= %s) ORDER BY next_run ASC LIMIT 5", $now ),
                        ARRAY_A
                );

                foreach ( (array) $due as $c ) {
                        $per_run = max( 1, (int) $c['per_run'] );
                        for ( $i = 0; $i < $per_run; $i++ ) {
                                Haa_Queue::push(
                                        'ap_article',
                                        array( 'campaign_id' => (int) $c['id'] ),
                                        array( 'module' => 'autopilot', 'max_attempts' => max( 0, (int) Haa_Settings::get( 'autopilot.retry_limit', 2 ) ) )
                                );
                        }
                        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
                        $wpdb->update(
                                $table,
                                array(
                                        'last_run' => $now,
                                        'next_run' => self::compute_next_run( $c['frequency'], $c['run_at'] ),
                                        'updated'  => $now,
                                ),
                                array( 'id' => (int) $c['id'] ),
                                array( '%s', '%s', '%s' ),
                                array( '%d' )
                        );
                }

                delete_transient( 'haai_autopilot_lock' );
        }

        /**
         * @param array $job     Queue row.
         * @param array $payload Decoded payload.
         */
        public function run_job( $job, $payload ) {
                $campaign_id = (int) ( $payload['campaign_id'] ?? 0 );
                global $wpdb;
                $table = Haa_DB::table( 'campaigns' );
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery
                $campaign = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE id = %d", $campaign_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                if ( ! $campaign || 'active' !== $campaign['status'] ) {
                        $GLOBALS['haai_job_result'] = new WP_Error( 'haai_ap_missing', __( 'کمپین ناموجود یا متوقف است.', 'hami-ai-assistant' ) );
                        return;
                }

                // Respect the daily caps again — cron may have queued several jobs.
                $token_cap = (int) Haa_Settings::get( 'autopilot.daily_token_cap', 300000 );
                if ( $token_cap > 0 && Haa_Usage::tokens_today() >= $token_cap ) {
                        $GLOBALS['haai_job_result'] = new WP_Error( 'haai_ap_token_cap', __( 'سقف توکن روزانه پر است.', 'hami-ai-assistant' ) );
                        return;
                }
                $post_cap = (int) Haa_Settings::get( 'autopilot.daily_post_cap', 3 );
                if ( $post_cap > 0 && Haa_Usage::autopilot_posts_today() >= $post_cap ) {
                        $GLOBALS['haai_job_result'] = new WP_Error( 'haai_ap_post_cap', __( 'سقف پست روزانه پر است.', 'hami-ai-assistant' ) );
                        return;
                }

                if ( function_exists( 'set_time_limit' ) ) {
                        @set_time_limit( 300 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
                }

                // Vary the topic a little each run so a weekly campaign does not
                // produce the same headline eight times.
                $topic = (string) $campaign['topic'];
                if ( ! empty( $campaign['keywords'] ) ) {
                        $keywords = array_filter( array_map( 'trim', explode( ',', (string) $campaign['keywords'] ) ) );
                        if ( $keywords ) {
                                $pick = $keywords[ array_rand( $keywords ) ];
                                $topic .= ' — ' . $pick;
                        }
                }

                $style = Haa_Content::style_suffix(
                        array(
                                'language' => $campaign['language'],
                                'tone'     => $campaign['tone'],
                                'length'   => $campaign['length_slug'],
                                'audience' => $campaign['audience'],
                                'keywords' => $campaign['keywords'],
                        )
                );

                $system = __( 'تو یک نویسنده محتوای سئو هستی. خروجی را فقط HTML معتبر برگردان؛ بدون h1، بدون کد فنس.', 'hami-ai-assistant' ) . "\n" . $style;
                $user   = __( 'موضوع:', 'hami-ai-assistant' ) . ' ' . $topic;
                if ( ! empty( $campaign['goal'] ) ) {
                        $user .= "\n" . __( 'هدف محتوا:', 'hami-ai-assistant' ) . ' ' . $campaign['goal'];
                }

                $raw = Haa_Content::generate( $system, $user, array( 'module' => 'autopilot', 'operation' => 'autopilot' ) );
                if ( is_wp_error( $raw ) ) {
                        $GLOBALS['haai_job_result'] = $raw;
                        return;
                }

                $html = Haa_Content::to_html( $raw );
                if ( '' === trim( wp_strip_all_tags( $html ) ) ) {
                        $GLOBALS['haai_job_result'] = new WP_Error( 'haai_ap_empty', __( 'مدل متنی برنگرداند.', 'hami-ai-assistant' ) );
                        return;
                }

                $title = $topic;
                if ( preg_match( '/<h2[^>]*>(.*?)<\/h2>/i', $html, $m ) ) {
                        $candidate = trim( wp_strip_all_tags( $m[1] ) );
                        if ( '' !== $candidate ) {
                                $title = $candidate;
                        }
                }

                $author = (int) Haa_Settings::get( 'article.default_author', 0 );
                if ( ! $author || ! get_userdata( $author ) ) {
                        $author = get_current_user_id();
                }

                $raw_status = (string) $campaign['status_out'];
                if ( 'publish' === $raw_status ) {
                        $raw_status = 'pending';
                }
                if ( ! in_array( $raw_status, array( 'draft', 'pending' ), true ) ) {
                        $raw_status = 'draft';
                }
                $post_id = wp_insert_post(
                        array(
                                'post_title'   => Haa_Util::cut( $title, 200 ),
                                'post_content' => wp_kses( $html, Haa_Util::article_kses_allowed() ),
                                'post_status'  => $raw_status,
                                'post_type'    => 'post',
                                'post_author'  => $author ? $author : 1,
                        ),
                        true
                );

                if ( is_wp_error( $post_id ) ) {
                        $GLOBALS['haai_job_result'] = $post_id;
                        return;
                }

                update_post_meta( (int) $post_id, '_haai_generated', '1' );
                update_post_meta( (int) $post_id, '_haai_campaign', $campaign_id );

                if ( (int) $campaign['category_id'] ) {
                        wp_set_post_categories( (int) $post_id, array( (int) $campaign['category_id'] ) );
                }

                if ( ! empty( $campaign['with_image'] ) && ! empty( Haa_Settings::get( 'autopilot.enable_images' ) ) ) {
                        $result = Haa_Images::generate(
                                Haa_Images::product_prompt( $title, '' ),
                                array( 'module' => 'autopilot', 'post_id' => (int) $post_id )
                        );
                        if ( ! empty( $result['ok'] ) ) {
                                set_post_thumbnail( (int) $post_id, (int) $result['attachment_id'] );
                        }
                }

                // phpcs:ignore WordPress.DB.DirectDatabaseQuery
                $wpdb->query( $wpdb->prepare( "UPDATE $table SET posts_done = posts_done + 1 WHERE id = %d", $campaign_id ) );
        }

        /**
         * Compute the next run time for a campaign.
         *
         * Timezone contract: the returned datetime is in UTC (matching how
         * `last_run` / `next_run` are stored). The admin enters HH:MM in the
         * site's timezone; we convert that local wall-clock time to the next
         * UTC occurrence.
         *
         * @param string $freq  hourly|twicedaily|daily|weekly|monthly.
         * @param string $run_at HH:MM in the site's timezone.
         * @return string MySQL datetime in UTC.
         */
        public static function compute_next_run( $freq, $run_at ) {
                $site_tz = wp_timezone();
                $utc     = new DateTimeZone( 'UTC' );

                // Parse HH:MM (default 06:00 if malformed).
                $parts = explode( ':', (string) $run_at );
                $hour  = isset( $parts[0] ) ? max( 0, min( 23, (int) $parts[0] ) ) : 6;
                $min   = isset( $parts[1] ) ? max( 0, min( 59, (int) $parts[1] ) ) : 0;

                // "Now" in the site's timezone (wall clock).
                $now_local = new DateTimeImmutable( 'now', $site_tz );

                // Today in the site's timezone, at the target HH:MM.
                $candidate = $now_local->setDate(
                        (int) $now_local->format( 'Y' ),
                        (int) $now_local->format( 'n' ),
                        (int) $now_local->format( 'j' )
                )->setTime( $hour, $min, 0 );

                // Advance based on frequency until the candidate is strictly in the future.
                switch ( $freq ) {
                        case 'hourly':
                                // Next occurrence of this minute-of-hour, ~1 hour out.
                                $candidate = $now_local->modify( '+1 hour' )->setTime(
                                        (int) $now_local->format( 'i' ) === $min
                                                ? (int) $now_local->format( 'G' )
                                                : $hour,
                                        $min,
                                        0
                                );
                                break;
                        case 'twicedaily':
                                if ( $candidate->getTimestamp() <= $now_local->getTimestamp() ) {
                                        $candidate = $candidate->modify( '+12 hours' );
                                }
                                break;
                        case 'weekly':
                                // Same HH:MM, next week (7 days).
                                if ( $candidate->getTimestamp() <= $now_local->getTimestamp() ) {
                                        $candidate = $candidate->modify( '+7 days' );
                                }
                                break;
                        case 'monthly':
                                // Same HH:MM, next month. PHP's modify('+1 month') overflows
                                // short months (Jan 31 → Mar 3 instead of Feb 28), so we
                                // clamp the day to the last day of the target month.
                                if ( $candidate->getTimestamp() <= $now_local->getTimestamp() ) {
                                        $target = $candidate->modify( 'first day of next month' );
                                        // Clamp day to the last day of the target month.
                                        $last_day = (int) $target->format( 't' );
                                        $want_day = min( (int) $candidate->format( 'j' ), $last_day );
                                        $candidate = $target->setDate(
                                                (int) $target->format( 'Y' ),
                                                (int) $target->format( 'n' ),
                                                $want_day
                                        )->setTime( $hour, $min, 0 );
                                }
                                break;
                        default: // daily
                                if ( $candidate->getTimestamp() <= $now_local->getTimestamp() ) {
                                        $candidate = $candidate->modify( '+1 day' );
                                }
                                break;
                }

                // Convert the final local wall-clock time to UTC for storage.
                return $candidate->setTimezone( $utc )->format( 'Y-m-d H:i:s' );
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
                wp_safe_redirect( Haa_Util::screen_url( 'autopilot', $args ) );
                exit;
        }
}
