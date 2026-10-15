<?php
/**
 * Module: Article Writer — long-form posts generated in batches.
 *
 * A batch is a list of topics. Each topic becomes one queued job so a
 * 20-article request never runs in a single PHP request (which shared hosts
 * would kill at 30s). One job per cron tick keeps memory flat.
 *
 * @package Hami_AI_Assistant
 */

if ( ! defined( 'ABSPATH' ) ) {
        exit;
}

final class Haa_Module_Article_Writer implements Haa_Module_Interface {

        /** @return string */
        public function slug() {
                return 'article-writer';
        }

        /** @return string */
        public function label() {
                return __( 'مقاله‌نویس', 'hami-ai-assistant' );
        }

        /** @return string */
        public function description() {
                return __( 'مقاله‌های سئوشده را در صف می‌گذارد و یکی‌یکی به‌صورت پیش‌نویس می‌سازد؛ هر مقاله می‌تواند تصویر شاخص هم داشته باشد.', 'hami-ai-assistant' );
        }

        /** @return bool */
        public function requires_woocommerce() {
                // Single source of truth: Haa_Modules::catalog_meta()[ 'woo' ].
                return Haa_Modules::requires_woocommerce( $this->slug() );
        }

        /** @return void */
        public function register() {
                add_action( 'haai_settings_sections', array( $this, 'register_section' ), 10 );
                add_action( 'haai_settings_fields_article', array( $this, 'register_fields' ) );
                add_action( 'haai_settings_fields_profile', array( $this, 'register_profile_fields' ) );
                add_action( 'haai_settings_fields_research', array( $this, 'register_research_fields' ) );
                add_action( 'haai_admin_menus', array( $this, 'register_menu' ) );
                add_action( 'admin_post_haai_aw_queue', array( $this, 'handle_queue' ) );
                add_action( 'admin_post_haai_aw_purge', array( $this, 'handle_purge' ) );
                add_action( 'haai_run_job_aw_article', array( $this, 'run_job' ), 10, 2 );

                // Site profile drafts (D1).
                add_action( 'admin_post_haai_profile_build', array( $this, 'handle_profile_build' ) );
                add_action( 'admin_post_haai_profile_approve', array( $this, 'handle_profile_approve' ) );
                add_action( 'admin_post_haai_profile_discard', array( $this, 'handle_profile_discard' ) );

                // Topic planner (D1).
                add_action( 'admin_post_haai_plan_gaps', array( $this, 'handle_plan_gaps' ) );
                add_action( 'admin_post_haai_plan_model', array( $this, 'handle_plan_model' ) );
                add_action( 'admin_post_haai_plan_queue', array( $this, 'handle_plan_queue' ) );
                add_action( 'admin_post_haai_plan_status', array( $this, 'handle_plan_status' ) );
                add_action( 'admin_post_haai_plan_delete', array( $this, 'handle_plan_delete' ) );

                // Competitor / SERP research (D2).
                add_action( 'admin_post_haai_rs_analyze', array( $this, 'handle_research_analyze' ) );
                add_action( 'admin_post_haai_rs_drop', array( $this, 'handle_research_drop' ) );
                add_action( 'admin_post_haai_rs_brief', array( $this, 'handle_research_brief' ) );
                add_action( 'admin_post_haai_rs_gap', array( $this, 'handle_research_gap' ) );

                // Keyword research panel: AJAX search + queue into the planner.
                add_action( 'wp_ajax_haai_kw_research', array( $this, 'ajax_kw_research' ) );
                add_action( 'admin_post_haai_kw_queue', array( $this, 'handle_kw_queue' ) );

                // Review / publish decisions (D3).
                add_action( 'admin_post_haai_pipe_approve', array( $this, 'handle_pipe_approve' ) );
                add_action( 'admin_post_haai_pipe_reject', array( $this, 'handle_pipe_reject' ) );
                add_action( 'admin_post_haai_pipe_section', array( $this, 'handle_pipe_section' ) );
                add_action( 'admin_post_haai_pipe_backlink', array( $this, 'handle_pipe_backlink' ) );

                // Monthly refresh (D4). The cron exists only while the setting is on, and
                // toggling the setting is what (un)schedules it — never a front-end check.
                add_action( Haa_Refresh::CRON, array( $this, 'cron_refresh' ) );
                add_action( 'update_option_' . HAAI_OPTION, array( $this, 'on_settings_saved' ), 10, 2 );
                add_action( 'admin_post_haai_rf_scan', array( $this, 'handle_refresh_scan' ) );
                add_action( 'admin_post_haai_rf_drop', array( $this, 'handle_refresh_drop' ) );
                add_action( 'admin_post_haai_rf_dismiss', array( $this, 'handle_refresh_dismiss' ) );
        }

        /**
         * Keep the refresh cron in step with article.refresh_enabled.
         *
         * @param array $old Previous value.
         * @param array $new New value.
         * @return void
         */
        public function on_settings_saved( $old, $new ) {
                $was = isset( $old['article']['refresh_enabled'] ) ? (int) $old['article']['refresh_enabled'] : 0;
                $is  = isset( $new['article']['refresh_enabled'] ) ? (int) $new['article']['refresh_enabled'] : 0;
                if ( $is && ! $was ) {
                        Haa_Refresh::schedule();
                } elseif ( ! $is && $was ) {
                        Haa_Refresh::unschedule();
                }
        }

        /** @return void */
        public function cron_refresh() {
                Haa_Refresh::cron_run();
        }

        /** @return void */
        public function activate() {
                // The topics table belongs to this module, so switching it on is what
                // creates the table — and switching it off leaves the rows alone.
                Haa_DB::create();
                if ( ! wp_next_scheduled( 'haai_aw_tick' ) ) {
                        wp_schedule_event( time() + MINUTE_IN_SECONDS, 'haai_five_minutes', 'haai_aw_tick' );
                }
                // Conditional: the monthly scan only exists if the admin asked for it.
                if ( Haa_Refresh::enabled() ) {
                        Haa_Refresh::schedule();
                }
        }

        /** @return void */
        public function deactivate() {
                wp_clear_scheduled_hook( 'haai_aw_tick' );
                Haa_Refresh::unschedule();
        }

        /**
         * Declares the "Article writer" and "Site profile" tabs. The queue itself is
         * drained centrally by Haa_Queue::hooks() on the haai_aw_tick /
         * haai_queue_tick cron events.
         *
         * @param array $sections Tabs so far.
         * @return array
         */
        public function register_section( $sections ) {
                $sections['article']  = __( 'مقاله‌نویس', 'hami-ai-assistant' );
                $sections['profile']  = __( 'حافظه‌ی سایت', 'hami-ai-assistant' );
                $sections['research'] = __( 'تحلیل رقبا', 'hami-ai-assistant' );
                return $sections;
        }

        /**
         * @param array $fields Fields so far.
         * @return array
         */
        public function register_fields( $fields ) {
                $fields[] = array( 'key' => 'article.default_status', 'type' => 'select', 'label' => __( 'وضعیت پیش‌فرض', 'hami-ai-assistant' ), 'choices' => array( 'draft' => __( 'پیش‌نویس', 'hami-ai-assistant' ), 'pending' => __( 'در انتظار بررسی', 'hami-ai-assistant' ) ) );
                $fields[] = array( 'key' => 'article.default_author', 'type' => 'number', 'label' => __( 'شناسه نویسنده (۰ = شما)', 'hami-ai-assistant' ), 'min' => 0, 'max' => 999999 );
                $fields[] = array( 'key' => 'article.batch_size', 'type' => 'number', 'label' => __( 'حجم دسته (هر کلیک)', 'hami-ai-assistant' ), 'min' => 1, 'max' => 20 );
                $fields[] = array( 'key' => 'article.daily_token_cap', 'type' => 'number', 'label' => __( 'سقف توکن روزانه‌ی مقاله', 'hami-ai-assistant' ), 'min' => 0, 'max' => 50000000, 'hint' => __( '۰ = بدون سقف. شامل همه‌ی مسیرهای نوشتن مقاله، نه فقط این صفحه.', 'hami-ai-assistant' ) );
                $fields[] = array( 'key' => 'article.daily_article_cap', 'type' => 'number', 'label' => __( 'سقف مقاله‌ی روزانه', 'hami-ai-assistant' ), 'min' => 0, 'max' => 50, 'hint' => __( '۰ = بدون سقف.', 'hami-ai-assistant' ) );
                $fields[] = array( 'key' => 'article.dup_threshold', 'type' => 'float', 'label' => __( 'آستانه‌ی «تکراری است»', 'hami-ai-assistant' ), 'min' => 0.1, 'max' => 1, 'step' => 0.05, 'hint' => __( 'شباهت موضوع تازه با محتوای خود سایت؛ پایین‌تر یعنی سخت‌گیرتر.', 'hami-ai-assistant' ) );
                $fields[] = array( 'key' => 'article.plan_count', 'type' => 'number', 'label' => __( 'تعداد موضوع در هر برنامه‌ریزی', 'hami-ai-assistant' ), 'min' => 1, 'max' => 20 );

                // Staged pipeline (D3).
                $fields[] = array( 'key' => 'article.publish_mode', 'type' => 'select', 'label' => __( 'حالت انتشار مقاله', 'hami-ai-assistant' ), 'choices' => array( 'draft' => __( 'پیش‌نویس', 'hami-ai-assistant' ), 'review' => __( 'نیازمند تأیید', 'hami-ai-assistant' ), 'auto' => __( 'انتشار خودکار فقط با قبول همه‌ی بررسی‌ها', 'hami-ai-assistant' ) ), 'hint' => __( 'اگر هر بررسی قرمز شود، مقاله به «نیازمند تأیید» برمی‌گردد؛ خودکار یعنی ریسک‌پذیر.', 'hami-ai-assistant' ) );
                $fields[] = array( 'key' => 'article.target_words', 'type' => 'number', 'label' => __( 'طول هدف مقاله (کلمه)', 'hami-ai-assistant' ), 'min' => 600, 'max' => 5000, 'hint' => __( 'بخش‌به‌بخش نوشته می‌شود؛ هر ~۴۵۰ کلمه تقریباً یک درخواست اضافه است. مقاله‌ی جامع یعنی ۳۰۰۰ تا ۴۵۰۰.', 'hami-ai-assistant' ) );
                $fields[] = array( 'key' => 'article.max_sections', 'type' => 'number', 'label' => __( 'حداکثر بخش‌های هر مقاله', 'hami-ai-assistant' ), 'min' => 3, 'max' => 16 );
                $fields[] = array( 'key' => 'article.inline_images', 'type' => 'number', 'label' => __( 'تصویر داخل مقاله (۰ تا ۳)', 'hami-ai-assistant' ), 'min' => 0, 'max' => 3, 'hint' => __( 'هر تصویر یک درخواست جدا به سرویس تصویر است.', 'hami-ai-assistant' ) );
                $fields[] = array( 'key' => 'article.faq_schema', 'type' => 'checkbox', 'label' => __( 'افزودن اسکیمای FAQ (JSON-LD)', 'hami-ai-assistant' ) );
                $fields[] = array( 'key' => 'article.link_cap', 'type' => 'number', 'label' => __( 'حداکثر لینک داخلی', 'hami-ai-assistant' ), 'min' => 0, 'max' => 12 );
                $fields[] = array( 'key' => 'article.gate_word_ratio', 'type' => 'number', 'label' => __( 'حداقل درصد محقق‌شده از طول هدف', 'hami-ai-assistant' ), 'min' => 30, 'max' => 100, 'hint' => __( 'کمتر از این، مقاله درِ کیفیت را رد نمی‌کند.', 'hami-ai-assistant' ) );
                $fields[] = array( 'key' => 'article.flag_claims', 'type' => 'checkbox', 'label' => __( 'علامت‌گذاری ادعاهای قابل‌بررسی', 'hami-ai-assistant' ), 'hint' => __( 'عدد، تاریخ، نام و آمار در بازبینی نشان داده می‌شود؛ هرچه‌باشد حذف نمی‌شود.', 'hami-ai-assistant' ) );

                // Monthly refresh (D4).
                $fields[] = array( 'key' => 'article.refresh_enabled', 'type' => 'checkbox', 'label' => __( 'بازنگری ماهانه‌ی مقاله‌های قدیمی', 'hami-ai-assistant' ), 'hint' => __( 'فقط پیشنهاد می‌دهد؛ چیزی خودکار بازنویسی نمی‌شود.', 'hami-ai-assistant' ) );
                $fields[] = array( 'key' => 'article.refresh_days', 'type' => 'number', 'label' => __( 'چند بی‌توجهی، بازنگری لازم است؟ (روز)', 'hami-ai-assistant' ), 'min' => 30, 'max' => 365 );
                $fields[] = array( 'key' => 'article.refresh_mode', 'type' => 'select', 'label' => __( 'حالت بازنگری', 'hami-ai-assistant' ), 'choices' => array( 'suggest' => __( 'فقط پیشنهاد (پیش‌فرض)', 'hami-ai-assistant' ), 'draft' => __( 'پیشنهاد + ساخت پیش‌نویس تغییر', 'hami-ai-assistant' ) ) );
                return $fields;
        }

        /**
         * The profile tab is defined by its fields, so the settings screen and
         * Haa_Settings::collect_ui_keys() can never drift apart.
         *
         * @param array $fields Fields so far.
         * @return array
         */
        public function register_profile_fields( $fields ) {
                return array_merge( $fields, Haa_Site_Profile::fields() );
        }

        /**
         * Research tab fields, sourced from the service itself so the settings
         * screen and Haa_Settings::collect_ui_keys() cannot drift apart.
         *
         * @param array $fields Fields so far.
         * @return array
         */
        public function register_research_fields( $fields ) {
                return array_merge( $fields, Haa_Research::fields() );
        }

        /**
         * @param string $parent Parent menu slug.
         * @return void
         */
        public function register_menu( $parent ) {
                add_submenu_page(
                        $parent,
                        __( 'مقاله‌نویس', 'hami-ai-assistant' ),
                        __( 'مقاله‌نویس', 'hami-ai-assistant' ),
                        'manage_options',
                        Haa_Util::screen( 'article-writer' ),
                        array( $this, 'render_screen' )
                );
        }

        /** @return void */
        public function render_screen() {
                if ( ! current_user_can( 'manage_options' ) ) {
                        return;
                }
                $queue  = Haa_Queue::stats();
                $recent = get_posts(
                        array(
                                'post_type'      => 'post',
                                'post_status'    => array( 'draft', 'pending', 'publish' ),
                                'posts_per_page' => 8,
                                'meta_key'       => '_haai_generated',
                                'meta_value'     => '1',
                                'orderby'        => 'date',
                                'order'          => 'DESC',
                        )
                );
                ?>
                <div class="wrap haai-wrap">
                        <?php Haa_Admin::page_header( __( 'مقاله‌نویس', 'hami-ai-assistant' ) ); ?>

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

                        <div class="haai-cards">
                                <div class="haai-card">
                                        <div class="haai-card__value"><?php echo esc_html( number_format_i18n( (int) $queue['pending'] ) ); ?></div>
                                        <div class="haai-card__label"><?php esc_html_e( 'در صف', 'hami-ai-assistant' ); ?></div>
                                </div>
                                <div class="haai-card">
                                        <div class="haai-card__value"><?php echo esc_html( number_format_i18n( (int) $queue['running'] ) ); ?></div>
                                        <div class="haai-card__label"><?php esc_html_e( 'در حال اجرا', 'hami-ai-assistant' ); ?></div>
                                </div>
                                <div class="haai-card">
                                        <div class="haai-card__value"><?php echo esc_html( number_format_i18n( (int) $queue['failed'] ) ); ?></div>
                                        <div class="haai-card__label"><?php esc_html_e( 'ناموفق', 'hami-ai-assistant' ); ?></div>
                                </div>
                                <div class="haai-card">
                                        <div class="haai-card__value"><?php echo esc_html( number_format_i18n( (int) $queue['done'] ) ); ?></div>
                                        <div class="haai-card__label"><?php esc_html_e( 'انجام‌شده', 'hami-ai-assistant' ); ?></div>
                                </div>
                        </div>

                        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                                <?php wp_nonce_field( 'haai_aw' ); ?>
                                <input type="hidden" name="action" value="haai_aw_queue">

                                <fieldset class="haai-field haai-field--bare">
                                        <legend class="haai-field__legend"><?php esc_html_e( 'موضوع‌ها (هر خط یک مقاله)', 'hami-ai-assistant' ); ?></legend>
                                        <textarea name="topics" rows="8" class="large-text code" required
                                                placeholder="<?php esc_attr_e( "بهترین کوله پشتی برای دانشجویان\nراهنمای خرید لپ‌تاپ در ۱۴۰۴", 'hami-ai-assistant' ); ?>"></textarea>
                                        <p class="description">
                                                <?php esc_html_e( 'هر موضوع یک مقاله می‌سازد. پیش‌نویس‌ها در بخش «نوشته‌ها» منتظر شما می‌مانند.', 'hami-ai-assistant' ); ?>
                                        </p>
                                </fieldset>

                                <div class="haai-field">
                                        <label class="haai-field__label"><?php esc_html_e( 'لحن', 'hami-ai-assistant' ); ?></label>
                                        <div class="haai-field__control">
                                                <select name="tone">
                                                        <?php foreach ( Haa_Util::tones() as $slug => $label ) : ?>
                                                                <option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $slug, Haa_Settings::get( 'content.default_tone' ) ); ?>><?php echo esc_html( $label ); ?></option>
                                                        <?php endforeach; ?>
                                                </select>
                                        </div>
                                </div>

                                <div class="haai-field">
                                        <label class="haai-field__label"><?php esc_html_e( 'طول', 'hami-ai-assistant' ); ?></label>
                                        <div class="haai-field__control">
                                                <select name="length">
                                                        <?php foreach ( Haa_Util::lengths() as $slug => $label ) : ?>
                                                                <option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $slug, Haa_Settings::get( 'content.default_length' ) ); ?>><?php echo esc_html( $label ); ?></option>
                                                        <?php endforeach; ?>
                                                </select>
                                        </div>
                                </div>

                                <div class="haai-field">
                                        <label class="haai-field__label"><?php esc_html_e( 'دسته‌بندی', 'hami-ai-assistant' ); ?></label>
                                        <div class="haai-field__control"><?php $this->category_select(); ?></div>
                                </div>

                                <div class="haai-field">
                                        <label class="haai-field__label"><?php esc_html_e( 'تصویر شاخص', 'hami-ai-assistant' ); ?></label>
                                        <div class="haai-field__control">
                                                <label class="haai-checkbox">
                                                        <input type="checkbox" name="with_image" value="1" <?php checked( ! empty( Haa_Settings::get( 'content.enable_images' ) ) ); ?>>
                                                        <?php esc_html_e( 'برای هر مقاله یک تصویر بساز', 'hami-ai-assistant' ); ?>
                                                </label>
                                        </div>
                                </div>

                                <p class="submit">
                                        <button type="submit" class="button button-primary"><?php esc_html_e( 'افزودن به صف', 'hami-ai-assistant' ); ?></button>
                                </p>
                        </form>

                        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="haai-actions" data-haai-confirm>
                                <?php wp_nonce_field( 'haai_aw' ); ?>
                                <input type="hidden" name="action" value="haai_aw_purge">
                                <button type="submit" class="button-link-delete"><?php esc_html_e( 'پاک کردن کارهای ناموفق', 'hami-ai-assistant' ); ?></button>
                        </form>

                        <h2><?php esc_html_e( 'آخرین مقاله‌های ساخته‌شده', 'hami-ai-assistant' ); ?></h2>
                        <?php if ( $recent ) : ?>
                                <table class="widefat striped haai-table">
                                        <thead>
                                                <tr>
                                                        <th><?php esc_html_e( 'عنوان', 'hami-ai-assistant' ); ?></th>
                                                        <th><?php esc_html_e( 'وضعیت', 'hami-ai-assistant' ); ?></th>
                                                        <th><?php esc_html_e( 'تاریخ', 'hami-ai-assistant' ); ?></th>
                                                        <th></th>
                                                </tr>
                                        </thead>
                                        <tbody>
                                                <?php foreach ( $recent as $p ) : ?>
                                                        <tr>
                                                                <td><?php echo esc_html( $p->post_title ); ?></td>
                                                                <td><?php echo esc_html( translate_user_role( get_post_status_object( $p->post_status )->label ?? $p->post_status ) ); ?></td>
                                                                <td><?php echo esc_html( get_the_date( '', $p ) ); ?></td>
                                                                <td>
                                                                        <a href="<?php echo esc_url( get_edit_post_link( $p->ID ) ); ?>"><?php esc_html_e( 'ویرایش', 'hami-ai-assistant' ); ?></a>
                                                                </td>
                                                        </tr>
                                                <?php endforeach; ?>
                                        </tbody>
                                </table>
                        <?php else : ?>
                                <div class="haai-empty">
                                        <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M7 3h7l4 4v14H7z"/><path d="M10 10h6M10 14h6"/></svg>
                                        <span class="haai-empty__title"><?php esc_html_e( 'هنوز مقاله‌ای ساخته نشده است.', 'hami-ai-assistant' ); ?></span>
                                        <p class="haai-empty__hint"><?php esc_html_e( 'بالا چند موضوع بنویسید و «افزودن به صف» را بزنید؛ صف در cron پردازش می‌شود.', 'hami-ai-assistant' ); ?></p>
                                </div>
                        <?php endif; ?>
                        <?php $this->render_keywords_panel(); ?>
                        <?php $this->render_research_panel(); ?>
                        <?php $this->render_review_panel(); ?>
                        <?php $this->render_refresh_panel(); ?>
                        <?php $this->render_planner_panel(); ?>
                        <?php $this->render_profile_panel(); ?>
                </div>
                <?php
        }

        /**
         * Site profile: its state, the pending draft, and exactly what a prompt
         * would carry.
         *
         * The editable fields live on the settings tab; this panel only does the
         * things that need a POST of their own — build a draft, approve it, throw it
         * away — because a draft must never be saved by the same form that edits the
         * live profile.
         *
         * @return void
         */
        private function render_profile_panel() {
                $on      = Haa_Site_Profile::enabled();
                $has     = Haa_Site_Profile::has_content();
                $draft   = Haa_Site_Profile::draft_get();
                $preview = Haa_Site_Profile::block( '' );

                $labels = array(
                        'domain'    => __( 'حوزه‌ی فعالیت', 'hami-ai-assistant' ),
                        'audience'  => __( 'مخاطب', 'hami-ai-assistant' ),
                        'voice'     => __( 'لحن برند', 'hami-ai-assistant' ),
                        'offerings' => __( 'محصولات و خدمات', 'hami-ai-assistant' ),
                        'glossary'  => __( 'واژه‌نامه', 'hami-ai-assistant' ),
                        'forbidden' => __( 'ادعاهای ممنوع', 'hami-ai-assistant' ),
                        'examples'  => __( 'مثال‌های خوب', 'hami-ai-assistant' ),
                );
                ?>
                <h2><?php esc_html_e( 'حافظه‌ی سایت', 'hami-ai-assistant' ); ?></h2>

                <div class="haai-cards">
                        <div class="haai-card haai-card--health <?php echo $on ? 'haai-card--ok' : 'haai-card--warn'; ?>">
                                <div class="haai-card__value">
                                        <span class="haai-dot <?php echo $on ? 'haai-dot--ok' : 'haai-dot--warn'; ?>" aria-hidden="true"></span>
                                        <?php echo $on ? esc_html__( 'روشن', 'hami-ai-assistant' ) : esc_html__( 'خاموش', 'hami-ai-assistant' ); ?>
                                </div>
                                <div class="haai-card__label"><?php esc_html_e( 'استفاده در پرامپت', 'hami-ai-assistant' ); ?></div>
                        </div>
                        <div class="haai-card">
                                <div class="haai-card__value"><?php echo esc_html( $has ? __( 'پرشده', 'hami-ai-assistant' ) : __( 'خالی', 'hami-ai-assistant' ) ); ?></div>
                                <div class="haai-card__label"><?php esc_html_e( 'اطلاعات ثبت‌شده', 'hami-ai-assistant' ); ?></div>
                        </div>
                        <div class="haai-card">
                                <div class="haai-card__value"><?php echo esc_html( number_format_i18n( mb_strlen( $preview ) ) ); ?></div>
                                <div class="haai-card__label"><?php esc_html_e( 'کاراکتر ارسالی به مدل', 'hami-ai-assistant' ); ?></div>
                        </div>
                </div>

                <p class="description">
                        <?php
                        printf(
                                /* translators: %s: settings URL. */
                                wp_kses_post( __( 'فیلدها در تب «<a href="%s">حافظه‌ی سایت</a>» ویرایش می‌شوند. این‌جا فقط پیش‌نویس خودکار ساخته و تأیید می‌شود.', 'hami-ai-assistant' ) ),
                                esc_url( Haa_Util::screen_url( 'settings', array( 'tab' => 'profile' ) ) )
                        );
                        ?>
                </p>

                <?php if ( $draft ) : ?>
                        <div class="haai-panel">
                                <h3 class="haai-group__title"><?php esc_html_e( 'پیش‌نویس ساخته‌شده از خودِ سایت — هنوز ذخیره نشده', 'hami-ai-assistant' ); ?></h3>
                                <?php foreach ( $labels as $key => $label ) : ?>
                                        <?php $val = trim( (string) ( $draft[ $key ] ?? '' ) ); ?>
                                        <?php if ( '' !== $val ) : ?>
                                                <div class="haai-field">
                                                        <span class="haai-field__label"><?php echo esc_html( $label ); ?></span>
                                                        <div class="haai-field__control"><p class="description"><?php echo nl2br( esc_html( $val ) ); ?></p></div>
                                                </div>
                                        <?php endif; ?>
                                <?php endforeach; ?>
                                <p class="haai-batch__actions">
                                        <?php if ( '' === trim( (string) ( $draft['domain'] ?? '' ) ) && '' === trim( (string) ( $draft['offerings'] ?? '' ) ) ) : ?>
                                                <span class="description"><?php esc_html_e( 'چیزی روی سایت پیدا نشد که از آن پیش‌نویس ساخته شود.', 'hami-ai-assistant' ); ?></span>
                                        <?php else : ?>
                                                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="haai-inline">
                                                        <?php wp_nonce_field( 'haai_aw' ); ?>
                                                        <input type="hidden" name="action" value="haai_profile_approve">
                                                        <button type="submit" class="button button-primary"><?php esc_html_e( 'تأیید و جایگزینی در پروفایل', 'hami-ai-assistant' ); ?></button>
                                                </form>
                                        <?php endif; ?>
                                        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="haai-inline">
                                                <?php wp_nonce_field( 'haai_aw' ); ?>
                                                <input type="hidden" name="action" value="haai_profile_discard">
                                                <button type="submit" class="button-link-delete"><?php esc_html_e( 'حذف پیش‌نویس', 'hami-ai-assistant' ); ?></button>
                                        </form>
                                </p>
                        </div>
                <?php else : ?>
                        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                                <?php wp_nonce_field( 'haai_aw' ); ?>
                                <input type="hidden" name="action" value="haai_profile_build">
                                <p>
                                        <button type="submit" class="button"><?php esc_html_e( 'ساخت خودکار پیش‌نویس از محتوای سایت', 'hami-ai-assistant' ); ?></button>
                                        <span class="description"><?php esc_html_e( 'بدون درخواست به مدل و بدون هزینه؛ فقط چیزی که ایندکس سایت همین حالا می‌گوید.', 'hami-ai-assistant' ); ?></span>
                                </p>
                        </form>
                <?php endif; ?>

                <?php if ( '' !== $preview ) : ?>
                        <details class="haai-batch">
                                <summary><?php esc_html_e( 'همین چیزی که الان به مدل فرستاده می‌شود', 'hami-ai-assistant' ); ?></summary>
                                <pre class="haai-profile-preview"><?php echo esc_html( $preview ); ?></pre>
                        </details>
                <?php endif; ?>
                <?php
        }

        /**
         * D3 review screen: one card per waiting article.
         *
         * Gates and flagged claims are shown, not hidden behind a badge, because the
         * whole point of this stage is that a human sees *what* the machine could not
         * verify before it goes public. Nothing here is deleted automatically.
         *
         * @return void
         */
        private function render_review_panel() {
                $rows = Haa_Pipeline::pending_review();
                if ( ! $rows ) {
                        return;
                }
                ?>
                <div class="haai-panel haai-panel--review">
                        <h2 class="haai-panel__title">
                                <?php esc_html_e( 'بازبینی مقاله‌های ساخته‌شده', 'hami-ai-assistant' ); ?>
                                <span class="haai-badge haai-badge--accent"><?php echo esc_html( (string) count( $rows ) ); ?></span>
                        </h2>
                        <p class="haai-panel__note">
                                <?php esc_html_e( 'این مقاله‌ها نوشته شده‌اند ولی منتشر نشده‌اند. بررسی‌های سبز یعنی ماشین چیزی را نقض ندیده؛ معنای «درست بودن» نمی‌دهد.', 'hami-ai-assistant' ); ?>
                        </p>

                        <?php foreach ( $rows as $row ) : ?>
                                <?php
                                $post    = $row['post'];
                                $gates   = (array) get_post_meta( (int) $post->ID, '_haai_gates', true );
                                $flags   = (array) get_post_meta( (int) $post->ID, '_haai_flags', true );
                                $links   = (array) get_post_meta( (int) $post->ID, '_haai_backlinks', true );
                                $clean   = Haa_Pipeline::gates_clean( $gates );
                                $state   = Haa_Pipeline::state_get( $row );
                                $edit    = (string) get_edit_post_link( (int) $post->ID );
                                ?>
                                <div class="haai-review <?php echo $clean ? 'is-clean' : 'is-blocked'; ?>">
                                        <h3 class="haai-review__title">
                                                <?php echo esc_html( (string) $post->post_title ); ?>
                                                <span class="haai-badge <?php echo $clean ? 'haai-badge--ok' : 'haai-badge--warn'; ?>">
                                                        <?php echo $clean ? esc_html__( 'بررسی‌ها سبز', 'hami-ai-assistant' ) : esc_html__( 'نیازمند بررسی انسانی', 'hami-ai-assistant' ); ?>
                                                </span>
                                        </h3>

                                        <?php
                                        // The text itself, not only a link to the editor: approving an
                                        // article you cannot read is no approval. Re-filtered on output
                                        // even though it was filtered on save, and clipped so a 3 000-word
                                        // post does not make this screen unusable.
                                        $body_raw   = (string) $post->post_content;
                                        $body_plain = trim( wp_strip_all_tags( $body_raw ) );
                                        $words      = count( array_filter( preg_split( '/\s+/u', $body_plain ), function ( $w ) {
                                                return '' !== trim( (string) $w );
                                        } ) );
                                        ?>
                                        <details class="haai-review__body">
                                                <summary>
                                                        <?php
                                                        echo esc_html(
                                                                sprintf(
                                                                        /* translators: 1: word count, 2: character count. */
                                                                        __( 'متن مقاله — %1$s کلمه (%2$s نویسه)', 'hami-ai-assistant' ),
                                                                        number_format_i18n( $words ),
                                                                        number_format_i18n( mb_strlen( $body_plain ) )
                                                                )
                                                        );
                                                        ?>
                                                </summary>
                                                <div class="haai-review__prose"><?php echo wp_kses( Haa_Util::cut( $body_raw, 6000 ), Haa_Util::article_kses_allowed() ); ?></div>
                                                <?php if ( mb_strlen( $body_raw ) > 6000 ) : ?>
                                                        <p class="haai-field__hint"><?php esc_html_e( 'ادامه در ویرایشگر وردپرس.', 'hami-ai-assistant' ); ?></p>
                                                <?php endif; ?>
                                        </details>

                                        <?php if ( $gates ) : ?>
                                                <?php
                                                /*
                                                 * Three states, each with its own word. Colour is a reinforcement:
                                                 * a red-green colour-blind admin reads the identical verdict, and a
                                                 * soft failure says «هشدار» rather than pretending to be a block.
                                                 */
                                                $gate_states = array(
                                                        'pass' => array( 'is-pass', 'haai-badge--ok', __( 'قبول', 'hami-ai-assistant' ) ),
                                                        'warn' => array( 'is-warn', 'haai-badge--warn', __( 'هشدار', 'hami-ai-assistant' ) ),
                                                        'fail' => array( 'is-fail', 'haai-badge--danger', __( 'رد', 'hami-ai-assistant' ) ),
                                                );
                                                ?>
                                                <ul class="haai-gates">
                                                        <?php foreach ( $gates as $g ) : ?>
                                                                <?php
                                                                $passed = ! empty( $g['pass'] );
                                                                $state  = $passed ? 'pass' : ( empty( $g['hard'] ) ? 'warn' : 'fail' );
                                                                ?>
                                                                <li class="<?php echo esc_attr( $gate_states[ $state ][0] ); ?>">
                                                                        <span class="haai-badge <?php echo esc_attr( $gate_states[ $state ][1] ); ?>"><?php echo esc_html( $gate_states[ $state ][2] ); ?></span>
                                                                        <strong><?php echo esc_html( (string) ( $g['label'] ?? '' ) ); ?></strong>
                                                                        <span><?php echo esc_html( (string) ( $g['detail'] ?? '' ) ); ?></span>
                                                                        <?php if ( ! $passed && empty( $g['hard'] ) ) : ?>
                                                                                <em><?php esc_html_e( 'مانع انتشار خودکار نیست', 'hami-ai-assistant' ); ?></em>
                                                                        <?php endif; ?>
                                                                </li>
                                                        <?php endforeach; ?>
                                                </ul>
                                        <?php endif; ?>

                                        <?php if ( $flags ) : ?>
                                                <details class="haai-review__flags" <?php echo $flags ? ' open' : ''; ?>>
                                                        <summary>
                                                                <?php echo esc_html( sprintf( /* translators: %d: count. */ __( '%d ادعای قابل بررسی (حذف نشده‌اند)', 'hami-ai-assistant' ), count( $flags ) ) ); ?>
                                                        </summary>
                                                        <ul>
                                                                <?php foreach ( $flags as $f ) : ?>
                                                                        <li>
                                                                                <span class="haai-flag-kind"><?php echo esc_html( (string) ( $f['kind'] ?? '' ) ); ?></span>
                                                                                <?php echo esc_html( (string) ( $f['claim'] ?? '' ) ); ?>
                                                                                <em>
                                                                                        <?php
                                                                                        $sup = (int) ( $f['supported'] ?? -1 );
                                                                                        echo 1 === $sup
                                                                                                ? esc_html__( 'پشتوانه در سایت', 'hami-ai-assistant' )
                                                                                                : ( 0 === $sup ? esc_html__( 'بی‌پشتوانه', 'hami-ai-assistant' ) : esc_html__( 'پایگاه دانش فعال نیست', 'hami-ai-assistant' ) );
                                                                                        ?>
                                                                                </em>
                                                                        </li>
                                                                <?php endforeach; ?>
                                                        </ul>
                                                </details>
                                        <?php endif; ?>

                                        <?php if ( ! empty( $state['sections'] ) ) : ?>
                                                <div class="haai-review__sections">
                                                        <span class="haai-field__hint"><?php esc_html_e( 'بازنویسی یک بخش:', 'hami-ai-assistant' ); ?></span>
                                                        <?php foreach ( (array) $state['sections'] as $i => $sec ) : ?>
                                                                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="haai-inline-form">
                                                                        <?php wp_nonce_field( 'haai_aw' ); ?>
                                                                        <input type="hidden" name="action" value="haai_pipe_section" />
                                                                        <input type="hidden" name="topic_id" value="<?php echo (int) $row['id']; ?>" />
                                                                        <input type="hidden" name="index" value="<?php echo (int) $i; ?>" />
                                                                        <button type="submit" class="button button-small"><?php echo esc_html( Haa_Util::cut( (string) ( $sec['h2'] ?? ( 'بخش ' . ( $i + 1 ) ) ), 34 ) ); ?></button>
                                                                </form>
                                                        <?php endforeach; ?>
                                                </div>
                                        <?php endif; ?>

                                        <?php if ( $links ) : ?>
                                                <div class="haai-review__backlinks">
                                                        <span class="haai-field__hint"><?php esc_html_e( 'پیشنهاد لینک برگشتی از نوشته‌های قدیمی (فقط با کلیک شما اعمال می‌شود):', 'hami-ai-assistant' ); ?></span>
                                                        <?php foreach ( $links as $bl ) : ?>
                                                                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="haai-inline-form">
                                                                        <?php wp_nonce_field( 'haai_aw' ); ?>
                                                                        <input type="hidden" name="action" value="haai_pipe_backlink" />
                                                                        <input type="hidden" name="old_id" value="<?php echo (int) ( $bl['post_id'] ?? 0 ); ?>" />
                                                                        <input type="hidden" name="new_id" value="<?php echo (int) $post->ID; ?>" />
                                                                        <button type="submit" class="button button-small"><?php echo esc_html( (string) ( $bl['title'] ?? '' ) ); ?></button>
                                                                </form>
                                                        <?php endforeach; ?>
                                                </div>
                                        <?php endif; ?>

                                        <div class="haai-review__actions">
                                                <?php if ( $edit ) : ?>
                                                        <a class="button" href="<?php echo esc_url( $edit ); ?>"><?php esc_html_e( 'ویرایش در وردپرس', 'hami-ai-assistant' ); ?></a>
                                                <?php endif; ?>

                                                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="haai-inline-form">
                                                        <?php wp_nonce_field( 'haai_aw' ); ?>
                                                        <input type="hidden" name="action" value="haai_pipe_approve" />
                                                        <input type="hidden" name="post_id" value="<?php echo (int) $post->ID; ?>" />
                                                        <input type="hidden" name="status" value="publish" />
                                                        <button type="submit" class="button button-primary"><?php esc_html_e( 'انتشار', 'hami-ai-assistant' ); ?></button>
                                                </form>

                                                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="haai-inline-form haai-review__schedule">
                                                        <?php wp_nonce_field( 'haai_aw' ); ?>
                                                        <input type="hidden" name="action" value="haai_pipe_approve" />
                                                        <input type="hidden" name="post_id" value="<?php echo (int) $post->ID; ?>" />
                                                        <input type="hidden" name="status" value="future" />
                                                        <label>
                                                                <?php esc_html_e( 'زمان:', 'hami-ai-assistant' ); ?>
                                                                <input type="datetime-local" name="publish_at" />
                                                        </label>
                                                        <button type="submit" class="button"><?php esc_html_e( 'زمان‌بندی', 'hami-ai-assistant' ); ?></button>
                                                </form>

                                                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="haai-inline-form">
                                                        <?php wp_nonce_field( 'haai_aw' ); ?>
                                                        <input type="hidden" name="action" value="haai_pipe_reject" />
                                                        <input type="hidden" name="post_id" value="<?php echo (int) $post->ID; ?>" />
                                                        <button type="submit" class="button button-link-delete"><?php esc_html_e( 'رد', 'hami-ai-assistant' ); ?></button>
                                                </form>
                                        </div>
                                </div>
                        <?php endforeach; ?>
                </div>
                <?php
        }

        /**
         * D4 panel: what the monthly scan found, and the human actions on it.
         *
         * Nothing here is applied automatically — a stale date on a published URL is
         * a worse problem than a missing one.
         *
         * @return void
         */
        private function render_refresh_panel() {
                if ( ! Haa_Refresh::enabled() ) {
                        return;
                }

                $rows = Haa_Refresh::needing( 12 );
                ?>
                <div class="haai-panel haai-panel--refresh">
                        <h2 class="haai-panel__title">
                                <?php esc_html_e( 'بازنگری ماهانه', 'hami-ai-assistant' ); ?>
                                <span class="haai-badge">
                                        <?php echo esc_html( sprintf( /* translators: %d: days. */ __( 'پس از %d روز', 'hami-ai-assistant' ), Haa_Refresh::days() ) ); ?>
                                </span>
                        </h2>
                        <p class="haai-panel__note">
                                <?php esc_html_e( 'این فهرست پیشنهاد است. هیچ نوشته‌ای خودکار بازنویسی نمی‌شود و تاریخ «ویرایش» جز با یک تغییر واقعی عوض نمی‌شود.', 'hami-ai-assistant' ); ?>
                        </p>

                        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="haai-inline-form">
                                <?php wp_nonce_field( 'haai_aw' ); ?>
                                <input type="hidden" name="action" value="haai_rf_scan" />
                                <label>
                                        <input type="checkbox" name="check_links" value="1" />
                                        <?php esc_html_e( 'لینک‌های بیرونی هم بررسی شود (کند است)', 'hami-ai-assistant' ); ?>
                                </label>
                                <button type="submit" class="button"><?php esc_html_e( 'بررسی دوباره', 'hami-ai-assistant' ); ?></button>
                        </form>

                        <?php if ( ! $rows ) : ?>
                                <p class="haai-empty__hint"><?php esc_html_e( 'چیزی برای بازنگری نیست.', 'hami-ai-assistant' ); ?></p>
                        <?php else : ?>
                                <?php foreach ( $rows as $r ) : ?>
                                        <?php
                                        $post   = $r['post'];
                                        $report = $r['report'];
                                        ?>
                                        <div class="haai-review">
                                                <h3 class="haai-review__title">
                                                        <a href="<?php echo esc_url( (string) get_edit_post_link( (int) $post->ID ) ); ?>"><?php echo esc_html( (string) $post->post_title ); ?></a>
                                                        <span class="haai-badge">
                                                                <?php echo esc_html( sprintf( /* translators: %d: days. */ __( '%d روز از آخرین تغییر', 'hami-ai-assistant' ), (int) ( $report['age_days'] ?? 0 ) ) ); ?>
                                                        </span>
                                                        <span class="haai-badge haai-badge--accent"><?php echo esc_html( Haa_Refresh::summary( $report ) ); ?></span>
                                                </h3>

                                                <?php if ( ! empty( $report['broken'] ) ) : ?>
                                                        <ul class="haai-gates">
                                                                <?php foreach ( (array) $report['broken'] as $b ) : ?>
                                                                        <li class="is-fail">
                                                                                <span class="haai-badge haai-badge--danger"><?php esc_html_e( 'خراب', 'hami-ai-assistant' ); ?></span>
                                                                                <strong><?php echo esc_html( Haa_Util::cut( (string) ( $b['url'] ?? '' ), 70 ) ); ?></strong>
                                                                                <span><?php echo esc_html( (string) ( $b['reason'] ?? '' ) ); ?></span>
                                                                                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="haai-inline-form">
                                                                                        <?php wp_nonce_field( 'haai_aw' ); ?>
                                                                                        <input type="hidden" name="action" value="haai_rf_drop" />
                                                                                        <input type="hidden" name="post_id" value="<?php echo (int) $post->ID; ?>" />
                                                                                        <input type="hidden" name="url" value="<?php echo esc_attr( (string) ( $b['url'] ?? '' ) ); ?>" />
                                                                                        <button type="submit" class="button button-small"><?php esc_html_e( 'برداشتن لینک', 'hami-ai-assistant' ); ?></button>
                                                                                </form>
                                                                                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="haai-inline-form">
                                                                                        <?php wp_nonce_field( 'haai_aw' ); ?>
                                                                                        <input type="hidden" name="action" value="haai_rf_dismiss" />
                                                                                        <input type="hidden" name="post_id" value="<?php echo (int) $post->ID; ?>" />
                                                                                        <input type="hidden" name="url" value="<?php echo esc_attr( (string) ( $b['url'] ?? '' ) ); ?>" />
                                                                                        <button type="submit" class="button button-link-delete button-small"><?php esc_html_e( 'درست است', 'hami-ai-assistant' ); ?></button>
                                                                                </form>
                                                                        </li>
                                                                <?php endforeach; ?>
                                                        </ul>
                                                <?php endif; ?>

                                                <?php if ( ! empty( $report['stale'] ) ) : ?>
                                                        <details class="haai-plan__errors">
                                                                <summary><?php echo esc_html( sprintf( /* translators: %d: count. */ __( '%d نکته درباره‌ی تازگی محتوا', 'hami-ai-assistant' ), count( (array) $report['stale'] ) ) ); ?></summary>
                                                                <ul>
                                                                        <?php foreach ( (array) $report['stale'] as $s ) : ?>
                                                                                <li><span><?php echo esc_html( (string) ( $s['text'] ?? '' ) ); ?></span> — <?php echo esc_html( (string) ( $s['why'] ?? '' ) ); ?></li>
                                                                        <?php endforeach; ?>
                                                                </ul>
                                                        </details>
                                                <?php endif; ?>

                                                <?php if ( ! empty( $report['links'] ) ) : ?>
                                                        <ul class="haai-diag__list">
                                                                <?php foreach ( (array) $report['links'] as $l ) : ?>
                                                                        <li><?php echo esc_html( (string) $l ); ?></li>
                                                                <?php endforeach; ?>
                                                        </ul>
                                                <?php endif; ?>
                                        </div>
                                <?php endforeach; ?>
                        <?php endif; ?>
                </div>
                <?php
        }

        /**
         * D2 panel: competitor crawl state, refusals, and the gap picker.
         *
         * Deliberately shows *why* a host was skipped. A silently short list looks
         * like a bug to the admin and trains them to distrust every number here.
         *
         * @return void
         */
        /**
         * Keyword research panel: type a topic, get real user suggestions
         * (Google Suggest), SERP titles when a service is connected, and model
         * ideas — combined and deduped. Tick the ones worth writing and they
         * become suggested planner topics.
         *
         * @return void
         */
        private function render_keywords_panel() {
                ?>
                <div class="haai-panel haai-panel--keywords" id="haai-kw">
                        <h2 class="haai-panel__title">
                                <?php esc_html_e( 'پیشنهاد کلمه کلیدی', 'hami-ai-assistant' ); ?>
                                <span class="haai-badge haai-badge--accent"><?php esc_html_e( 'جدید', 'hami-ai-assistant' ); ?></span>
                        </h2>
                        <p class="haai-panel__note">
                                <?php esc_html_e( 'موضوع را بنویسید: پیشنهادهای واقعی تایپ کاربران گوگل (در صورت دسترسی)، عنوان نتایج جست‌وجو (در صورت اتصال سرویس SERP) و ایده‌های مدل با هم ترکیب و بدون تکرار نمایش داده می‌شوند. تیک بزنید تا به برنامه‌ریز اضافه شود.', 'hami-ai-assistant' ); ?>
                        </p>
                        <div class="haai-kw__bar">
                                <input type="text" id="haai-kw-seed" class="haai-kw__input" placeholder="<?php esc_attr_e( 'مثلاً: روغن آرگان برای مو', 'hami-ai-assistant' ); ?>" />
                                <button type="button" class="button button-primary" id="haai-kw-run"><?php esc_html_e( 'پیدا کن', 'hami-ai-assistant' ); ?></button>
                        </div>
                        <div id="haai-kw-status" class="haai-kw__status" hidden></div>
                        <form id="haai-kw-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" hidden>
                                <?php wp_nonce_field( 'haai_aw' ); ?>
                                <input type="hidden" name="action" value="haai_kw_queue" />
                                <div id="haai-kw-rows" class="haai-kw__rows"></div>
                                <p class="submit">
                                        <button type="submit" class="button button-primary"><?php esc_html_e( 'افزودن انتخاب‌شده‌ها به برنامه‌ریز', 'hami-ai-assistant' ); ?></button>
                                </p>
                        </form>
                </div>
                <?php
        }

        private function render_research_panel() {
                $enabled = Haa_Research::enabled();
                $sites   = Haa_Research::competitors();
                $snap    = Haa_Research::snapshot();
                $gaps    = Haa_Research::gaps( 10 );
                $serp    = Haa_Research::serp_ready();
                ?>
                <div class="haai-panel haai-panel--research">
                        <h2 class="haai-panel__title">
                                <?php esc_html_e( 'تحلیل رقبا', 'hami-ai-assistant' ); ?>
                                <?php if ( ! $enabled ) : ?>
                                        <span class="haai-badge"><?php esc_html_e( 'خاموش', 'hami-ai-assistant' ); ?></span>
                                <?php elseif ( $snap ) : ?>
                                        <span class="haai-badge haai-badge--ok"><?php esc_html_e( 'آماده', 'hami-ai-assistant' ); ?></span>
                                <?php else : ?>
                                        <span class="haai-badge haai-badge--accent"><?php esc_html_e( 'ساخته نشده', 'hami-ai-assistant' ); ?></span>
                                <?php endif; ?>
                        </h2>

                        <p class="haai-panel__note">
                                <?php esc_html_e( 'از صفحات رقبا فقط ساختار خوانده می‌شود: عنوان صفحه، سرفصل‌ها، تعداد کلمه و واژه‌های پرکاربرد. متن صفحه جایی ذخیره یا به مدل داده نمی‌شود.', 'hami-ai-assistant' ); ?>
                        </p>

                        <?php if ( ! $enabled ) : ?>
                                <div class="haai-alert haai-alert--info">
                                        <?php
                                        printf(
                                                /* translators: %s: settings URL. */
                                                wp_kses_post( __( 'برای روشن‌کردن، به <a href="%s">تنظیمات ← تحلیل رقبا</a> بروید.', 'hami-ai-assistant' ) ),
                                                esc_url( Haa_Util::screen_url( 'settings', array( 'tab' => 'research' ) ) )
                                        );
                                        ?>
                                </div>
                        <?php else : ?>
                                <div class="haai-plan__meta">
                                        <span><?php echo esc_html( Haa_Research::status_line() ); ?></span>
                                        <span>
                                                <?php
                                                printf(
                                                        /* translators: %d: competitor count. */
                                                        esc_html__( 'رقبای تعریف‌شده: %d', 'hami-ai-assistant' ),
                                                        (int) count( $sites )
                                                );
                                                ?>
                                        </span>
                                        <span class="<?php echo $serp ? 'haai-badge haai-badge--ok' : 'haai-badge'; ?>">
                                                <?php echo $serp ? esc_html__( 'سرویس جست‌وجو متصل', 'hami-ai-assistant' ) : esc_html__( 'سرویس جست‌وجو تنظیم نشده (اختیاری)', 'hami-ai-assistant' ); ?>
                                        </span>
                                </div>

                                <div class="haai-plan__actions">
                                        <?php if ( $sites ) : ?>
                                                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="haai-inline-form">
                                                        <?php wp_nonce_field( 'haai_aw' ); ?>
                                                        <input type="hidden" name="action" value="haai_rs_analyze" />
                                                        <button type="submit" class="button button-primary">
                                                                <?php echo $snap ? esc_html__( 'تحلیل دوباره', 'hami-ai-assistant' ) : esc_html__( 'ساخت تحلیل', 'hami-ai-assistant' ); ?>
                                                        </button>
                                                        <span class="haai-field__hint"><?php esc_html_e( 'درخواست‌ها با فاصله‌ی زمانی و تا سقف مشخص انجام می‌شود؛ اگر زمان اجرا تمام شود، دوباره بزنید تا ادامه یابد.', 'hami-ai-assistant' ); ?></span>
                                                </form>
                                        <?php endif; ?>
                                        <?php if ( $snap ) : ?>
                                                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="haai-inline-form">
                                                        <?php wp_nonce_field( 'haai_aw' ); ?>
                                                        <input type="hidden" name="action" value="haai_rs_drop" />
                                                        <button type="submit" class="button button-link-delete"><?php esc_html_e( 'پاک‌کردن نتایج', 'hami-ai-assistant' ); ?></button>
                                                </form>
                                        <?php endif; ?>
                                </div>

                                <?php if ( ! $sites ) : ?>
                                        <div class="haai-alert haai-alert--warn">
                                                <?php esc_html_e( 'هنوز هیچ آدرس معتبری وارد نکرده‌اید؛ هر خط یک دامنه.', 'hami-ai-assistant' ); ?>
                                        </div>
                                <?php endif; ?>

                                <?php if ( ! empty( $snap['errors'] ) ) : ?>
                                        <details class="haai-plan__errors" open>
                                                <summary><?php echo esc_html( sprintf( /* translators: %d: count. */ __( '%d مورد رد یا خطا', 'hami-ai-assistant' ), count( $snap['errors'] ) ) ); ?></summary>
                                                <ul class="haai-diag__list">
                                                        <?php foreach ( (array) $snap['errors'] as $err ) : ?>
                                                                <li><?php echo esc_html( (string) $err ); ?></li>
                                                        <?php endforeach; ?>
                                                </ul>
                                        </details>
                                <?php endif; ?>

                                <?php if ( $gaps ) : ?>
                                        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="haai-plan-form">
                                                <?php wp_nonce_field( 'haai_aw' ); ?>
                                                <input type="hidden" name="action" value="haai_rs_gap" />
                                                <h3 class="haai-plan__subtitle"><?php esc_html_e( 'شکاف‌های محتوایی', 'hami-ai-assistant' ); ?></h3>
                                                <p class="haai-field__hint"><?php esc_html_e( 'سرفصل‌هایی که رقبا دارند و شما درباره‌ی آن‌ها هیچ محتوایی ندارید. انتخاب کنید تا به برنامه‌ریز اضافه شود.', 'hami-ai-assistant' ); ?></p>
                                                <ul class="haai-plan__list">
                                                        <?php foreach ( $gaps as $g ) : ?>
                                                                <li class="haai-plantable">
                                                                        <label>
                                                                                <input type="checkbox" name="gaps[]" value="<?php echo esc_attr( $g['heading'] ); ?>" />
                                                                                <span><?php echo esc_html( $g['heading'] ); ?></span>
                                                                        </label>
                                                                </li>
                                                        <?php endforeach; ?>
                                                </ul>
                                                <button type="submit" class="button"><?php esc_html_e( 'افزودن انتخاب‌ها به برنامه‌ریز', 'hami-ai-assistant' ); ?></button>
                                        </form>
                                <?php elseif ( $snap && ! empty( $snap['headings'] ) ) : ?>
                                        <p class="haai-empty__hint"><?php esc_html_e( 'شکاف تازه‌ای پیدا نشد؛ یا رقبا موضوعات مشابه شما را پوشش داده‌اند یا تحلیل هنوز ساخته نشده است.', 'hami-ai-assistant' ); ?></p>
                                <?php endif; ?>
                        <?php endif; ?>
                </div>
                <?php
        }

        /**
         * Topic planner: what the site covers, what it misses, and the list of
         * proposed subjects a human approves before anything is queued.
         *
         * The table is deliberately outside the queue form and each checkbox names
         * its form with the HTML `form` attribute — a form inside a form is invalid
         * and browsers repair it in ways that silently drop selections.
         *
         * @return void
         */
        private function render_planner_panel() {
                $map      = Haa_Planner::site_map();
                $gaps     = Haa_Planner::gaps();
                $counts   = Haa_Planner::counts();
                $rows     = Haa_Planner::rows( '', 50 );
                $plan     = Haa_Planner::estimate( max( 1, (int) ( $counts['suggested'] + $counts['approved'] ) ) );
                $tokens   = (int) Haa_Usage::tokens_today();
                $capped   = array_filter( $rows, function ( $r ) {
                        return in_array( $r['status'], array( 'suggested', 'approved' ), true );
                } );

                // Row actions and the queue need different markup, so split once.
                $rows = array_values( $rows );
                ?>
                <div class="haai-panel haai-panel--plan">
                <h2 class="haai-panel__title"><?php esc_html_e( 'برنامه‌ریز موضوع', 'hami-ai-assistant' ); ?></h2>

                <div class="haai-cards">
                        <div class="haai-card">
                                <div class="haai-card__value"><?php echo esc_html( number_format_i18n( (int) $map['posts'] ) ); ?></div>
                                <div class="haai-card__label"><?php esc_html_e( 'مطلب در ایندکس', 'hami-ai-assistant' ); ?></div>
                        </div>
                        <div class="haai-card">
                                <div class="haai-card__value"><?php echo esc_html( number_format_i18n( count( $map['intents'] ) ) ); ?></div>
                                <div class="haai-card__label"><?php esc_html_e( 'خوشه‌ی محتوایی', 'hami-ai-assistant' ); ?></div>
                        </div>
                        <div class="haai-card <?php echo $gaps ? 'haai-card--warn' : 'haai-card--ok'; ?>">
                                <div class="haai-card__value"><?php echo esc_html( number_format_i18n( count( $gaps ) ) ); ?></div>
                                <div class="haai-card__label"><?php esc_html_e( 'خوشه بدون مقاله', 'hami-ai-assistant' ); ?></div>
                        </div>
                        <div class="haai-card">
                                <div class="haai-card__value"><?php echo esc_html( number_format_i18n( $tokens ) . ' / ' . number_format_i18n( (int) $plan['cap'] ) ); ?></div>
                                <div class="haai-card__label"><?php esc_html_e( 'توکن امروز', 'hami-ai-assistant' ); ?></div>
                        </div>
                </div>

                <p class="haai-filterbar">
                        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="haai-inline">
                                <?php wp_nonce_field( 'haai_aw' ); ?>
                                <input type="hidden" name="action" value="haai_plan_gaps">
                                <button type="submit" class="button"><?php esc_html_e( 'ساخت موضوع از خوشه‌های خالی (رایگان)', 'hami-ai-assistant' ); ?></button>
                        </form>
                        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="haai-inline">
                                <?php wp_nonce_field( 'haai_aw' ); ?>
                                <input type="hidden" name="action" value="haai_plan_model">
                                <button type="submit" class="button" <?php disabled( '' !== $plan['blocked'] ); ?>>
                                        <?php esc_html_e( 'پیشنهاد موضوع با مدل (۱ درخواست)', 'hami-ai-assistant' ); ?>
                                </button>
                        </form>
                </p>

                <?php if ( '' !== $plan['blocked'] ) : ?>
                        <p class="haai-diag-clean haai-diag-clean--warn"><?php echo esc_html( $plan['blocked'] ); ?></p>
                <?php endif; ?>

                <?php if ( ! $rows ) : ?>
                        <div class="haai-empty">
                                <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9 11l3 3 8-8"/><path d="M20 12v7a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h9"/></svg>
                                <span class="haai-empty__title"><?php esc_html_e( 'هنوز موضوعی در فهرست نیست.', 'hami-ai-assistant' ); ?></span>
                                <p class="haai-empty__hint"><?php esc_html_e( 'دکمه‌ی بالا را بزنید تا از خوشه‌های بدون مقاله موضوع ساخته شود؛ این کار به مدل کاری ندارد و رایگان است.', 'hami-ai-assistant' ); ?></p>
                        </div>
                <?php else : ?>
                        <div class="haai-table-scroll">
                                <table class="widefat striped haai-table haai-plantable">
                                        <thead>
                                                <tr>
                                                        <th class="haai-col-check"></th>
                                                        <th><?php esc_html_e( 'موضوع', 'hami-ai-assistant' ); ?></th>
                                                        <th><?php esc_html_e( 'کلیدواژه', 'hami-ai-assistant' ); ?></th>
                                                        <th><?php esc_html_e( 'هدف', 'hami-ai-assistant' ); ?></th>
                                                        <th><?php esc_html_e( 'اولویت', 'hami-ai-assistant' ); ?></th>
                                                        <th><?php esc_html_e( 'چرا', 'hami-ai-assistant' ); ?></th>
                                                        <th><?php esc_html_e( 'وضعیت', 'hami-ai-assistant' ); ?></th>
                                                        <th></th>
                                                </tr>
                                        </thead>
                                        <tbody>
                                                <?php foreach ( $rows as $r ) : ?>
                                                        <?php
                                                        $choosable = in_array( $r['status'], array( 'suggested', 'approved' ), true );
                                                        $comp      = $choosable ? Haa_Planner::competition( $r['title'], $r['keyword'] ) : array( 'duplicate' => false, 'title' => '', 'score' => 0 );
                                                        ?>
                                                        <tr>
                                                                <td class="haai-col-check">
                                                                        <?php if ( $choosable ) : ?>
                                                                                <input type="checkbox" name="ids[]" value="<?php echo (int) $r['id']; ?>" form="haai-plan-queue" aria-label="<?php echo esc_attr( $r['title'] ); ?>">
                                                                        <?php endif; ?>
                                                                </td>
                                                                <td>
                                                                        <?php echo esc_html( $r['title'] ); ?>
                                                                        <?php if ( ! empty( $comp['duplicate'] ) ) : ?>
                                                                                <span class="haai-badge haai-badge--warn" title="<?php echo esc_attr( $comp['title'] ); ?>">
                                                                                        <?php esc_html_e( 'شبیه محتوای موجود', 'hami-ai-assistant' ); ?>
                                                                                </span>
                                                                        <?php endif; ?>
                                                                </td>
                                                                <td><code><?php echo esc_html( $r['keyword'] ); ?></code></td>
                                                                <td><?php echo esc_html( Haa_Planner::intent_label( $r['intent'] ) ); ?></td>
                                                                <td><?php echo (int) $r['priority']; ?></td>
                                                                <td class="haai-plan-reason"><?php echo esc_html( $r['reason'] ); ?></td>
                                                                <td>
                                                                        <?php
                                                                        $badge = array(
                                                                                'suggested' => '',
                                                                                'approved'  => 'haai-badge--on',
                                                                                'queued'    => 'haai-badge--accent',
                                                                                'rejected'  => 'haai-badge--warn',
                                                                                'published' => 'haai-badge--on',
                                                                        );
                                                                        $st_labels = array(
                                                                                'suggested' => __( 'پیشنهاد', 'hami-ai-assistant' ),
                                                                                'approved'  => __( 'تأییدشده', 'hami-ai-assistant' ),
                                                                                'queued'    => __( 'در صف', 'hami-ai-assistant' ),
                                                                                'rejected'  => __( 'ردشده', 'hami-ai-assistant' ),
                                                                                'published' => __( 'منتشرشده', 'hami-ai-assistant' ),
                                                                        );
                                                                        ?>
                                                                        <span class="haai-badge <?php echo esc_attr( $badge[ $r['status'] ] ?? '' ); ?>"><?php echo esc_html( $st_labels[ $r['status'] ] ?? $r['status'] ); ?></span>
                                                                </td>
                                                                <td class="haai-plan-actions">
                                                                        <?php if ( 'suggested' === $r['status'] || 'rejected' === $r['status'] ) : ?>
                                                                                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="haai-inline">
                                                                                        <?php wp_nonce_field( 'haai_aw' ); ?>
                                                                                        <input type="hidden" name="action" value="haai_plan_status">
                                                                                        <input type="hidden" name="id" value="<?php echo (int) $r['id']; ?>">
                                                                                        <input type="hidden" name="status" value="approved">
                                                                                        <button type="submit" class="button button-small"><?php esc_html_e( 'تأیید', 'hami-ai-assistant' ); ?></button>
                                                                                </form>
                                                                        <?php endif; ?>
                                                                        <?php if ( 'approved' === $r['status'] ) : ?>
                                                                                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="haai-inline">
                                                                                        <?php wp_nonce_field( 'haai_aw' ); ?>
                                                                                        <input type="hidden" name="action" value="haai_plan_status">
                                                                                        <input type="hidden" name="id" value="<?php echo (int) $r['id']; ?>">
                                                                                        <input type="hidden" name="status" value="suggested">
                                                                                        <button type="submit" class="button button-small"><?php esc_html_e( 'به فهرست', 'hami-ai-assistant' ); ?></button>
                                                                                </form>
                                                                        <?php endif; ?>
                                                                        <?php if ( 'rejected' !== $r['status'] && in_array( $r['status'], array( 'suggested', 'approved' ), true ) ) : ?>
                                                                                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="haai-inline">
                                                                                        <?php wp_nonce_field( 'haai_aw' ); ?>
                                                                                        <input type="hidden" name="action" value="haai_plan_status">
                                                                                        <input type="hidden" name="id" value="<?php echo (int) $r['id']; ?>">
                                                                                        <input type="hidden" name="status" value="rejected">
                                                                                        <button type="submit" class="button button-small"><?php esc_html_e( 'رد', 'hami-ai-assistant' ); ?></button>
                                                                                </form>
                                                                        <?php endif; ?>
                                                                        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="haai-inline" data-haai-confirm>
                                                                                <?php wp_nonce_field( 'haai_aw' ); ?>
                                                                                <input type="hidden" name="action" value="haai_plan_delete">
                                                                                <input type="hidden" name="id" value="<?php echo (int) $r['id']; ?>">
                                                                                <button type="submit" class="button-link-delete"><?php esc_html_e( 'حذف', 'hami-ai-assistant' ); ?></button>
                                                                        </form>
                                                                </td>
                                                        </tr>
                                                <?php endforeach; ?>
                                        </tbody>
                                </table>
                        </div>

                        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="haai-plan-queue" data-haai-plan-form data-per-article="<?php echo (int) Haa_Planner::estimate_tokens( 1 ); ?>">
                                <?php wp_nonce_field( 'haai_aw' ); ?>
                                <input type="hidden" name="action" value="haai_plan_queue">

                                <p class="haai-filterbar">
                                        <label class="haai-check">
                                                <span><?php esc_html_e( 'لحن', 'hami-ai-assistant' ); ?></span>
                                                <select name="tone">
                                                        <?php foreach ( Haa_Util::tones() as $slug => $label ) : ?>
                                                                <option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $slug, Haa_Settings::get( 'content.default_tone' ) ); ?>><?php echo esc_html( $label ); ?></option>
                                                        <?php endforeach; ?>
                                                </select>
                                        </label>
                                        <label class="haai-check">
                                                <span><?php esc_html_e( 'طول', 'hami-ai-assistant' ); ?></span>
                                                <select name="length">
                                                        <?php foreach ( Haa_Util::lengths() as $slug => $label ) : ?>
                                                                <option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $slug, Haa_Settings::get( 'content.default_length' ) ); ?>><?php echo esc_html( $label ); ?></option>
                                                        <?php endforeach; ?>
                                                </select>
                                        </label>
                                        <label class="haai-check">
                                                <span><?php esc_html_e( 'دسته', 'hami-ai-assistant' ); ?></span>
                                                <?php $this->category_select(); ?>
                                        </label>
                                        <?php if ( Haa_Settings::get( 'content.enable_images' ) ) : ?>
                                                <label class="haai-checkbox">
                                                        <input type="checkbox" name="with_image" value="1" data-haai-plan-image>
                                                        <?php esc_html_e( 'تصویر هم بساز (دو برابر درخواست)', 'hami-ai-assistant' ); ?>
                                                </label>
                                        <?php endif; ?>
                                </p>

                                <p class="description" data-haai-plan-estimate>
                                        <?php
                                        printf(
                                                /* translators: 1: selectable rows, 2: requests, 3: tokens. */
                                                esc_html__( 'انتخاب کنید: تا %1$d موضوع آماده. تخمین برای همه‌شان: %2$d درخواست و %3$s توکن.', 'hami-ai-assistant' ),
                                                count( $capped ),
                                                (int) Haa_Planner::estimate_requests( max( 1, count( $capped ) ), Haa_Settings::get( 'content.enable_images' ) ),
                                                number_format_i18n( (int) Haa_Planner::estimate_tokens( max( 1, count( $capped ) ) ) )
                                        );
                                        ?>
                                </p>

                                <p>
                                        <button type="submit" class="button button-primary" data-haai-plan-submit disabled><?php esc_html_e( 'انتخاب‌شده‌ها را به صف بده', 'hami-ai-assistant' ); ?></button>
                                </p>
                        </form>
                <?php endif; ?>
                </div><!-- .haai-panel--plan -->
                <?php
        }

        /** @return void */
        private function category_select() {
                $cats     = get_categories( array( 'hide_empty' => false, 'orderby' => 'name' ) );
                $selected = (int) Haa_Settings::get( 'autopilot.default_category', 0 );
                echo '<select name="category"><option value="0">' . esc_html__( 'بدون دسته', 'hami-ai-assistant' ) . '</option>';
                foreach ( $cats as $c ) {
                        printf(
                                '<option value="%d" %s>%s</option>',
                                (int) $c->term_id,
                                selected( (int) $c->term_id, $selected, false ),
                                esc_html( $c->name )
                        );
                }
                echo '</select>';
        }

        /* ------------------------------------------------------------------
         * Handlers
         * ---------------------------------------------------------------- */

        /** @return void */
        public function handle_queue() {
                Haa_Util::guard_admin_post( 'haai_aw' );

                $batch_size = max( 1, min( 20, (int) Haa_Settings::get( 'article.batch_size', 5 ) ) );

                $topics_raw = isset( $_POST['topics'] ) ? (string) wp_unslash( $_POST['topics'] ) : '';
                $topics     = array();
                foreach ( preg_split( '/\R+/u', $topics_raw ) as $line ) {
                        $line = trim( wp_strip_all_tags( $line ) );
                        if ( '' !== $line ) {
                                $topics[] = Haa_Util::cut( $line, 200 );
                        }
                        if ( count( $topics ) >= $batch_size ) {
                                break;
                        }
                }
                if ( ! $topics ) {
                        $this->back( __( 'هیچ موضوعی وارد نشد.', 'hami-ai-assistant' ) );
                }

                $tone     = isset( $_POST['tone'] ) ? sanitize_key( wp_unslash( $_POST['tone'] ) ) : '';
                $length   = isset( $_POST['length'] ) ? sanitize_key( wp_unslash( $_POST['length'] ) ) : '';
                $category = isset( $_POST['category'] ) ? absint( $_POST['category'] ) : 0;
                $with_img = ! empty( $_POST['with_image'] );

                $queued = 0;
                foreach ( $topics as $topic ) {
                        Haa_Queue::push(
                                'aw_article',
                                array(
                                        'topic'    => $topic,
                                        'tone'     => $tone,
                                        'length'   => $length,
                                        'category' => $category,
                                        'image'    => $with_img,
                                ),
                                array( 'module' => 'article-writer', 'max_attempts' => 2 )
                        );
                        $queued++;
                }

                $this->back(
                        sprintf(
                                /* translators: %d: number of articles queued. */
                                __( '%d مقاله به صف اضافه شد. هر چند دقیقه یکی ساخته می‌شود.', 'hami-ai-assistant' ),
                                $queued
                        )
                );
        }

        /** @return void */
        public function handle_purge() {
                Haa_Util::guard_admin_post( 'haai_aw' );
                Haa_Queue::purge_failed();
                $this->back( __( 'کارهای ناموفق پاک شدند.', 'hami-ai-assistant' ) );
        }

        /**
         * One queued article. Called from the queue, never from a page load.
         *
         * @param array $job     Queue row.
         * @param array $payload Decoded payload.
         */
        public function run_job( $job, $payload ) {
                // Topics that came from the planner go through the staged pipeline
                // (outline → sections → meta → gates → review). The manual "quick topics"
                // box has no planner row and keeps its original single-call behaviour,
                // so nothing that worked before changes.
                $topic_id = isset( $payload['topic_id'] ) ? (int) $payload['topic_id'] : 0;
                if ( $topic_id && Haa_Planner::row( $topic_id ) ) {
                        $done = Haa_Pipeline::run( $topic_id, $payload );
                        $GLOBALS['haai_job_result'] = is_wp_error( $done )
                                ? $done
                                : array(
                                        'post_id' => (int) $done,
                                        'status'  => (string) get_post_status( (int) $done ),
                                );
                        return;
                }

                $topic = trim( (string) ( $payload['topic'] ?? '' ) );
                if ( '' === $topic ) {
                        $GLOBALS['haai_job_result'] = new WP_Error( 'haai_aw_empty', __( 'موضوع خالی بود.', 'hami-ai-assistant' ) );
                        return;
                }

                if ( function_exists( 'set_time_limit' ) ) {
                        @set_time_limit( 300 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
                }

                $style = Haa_Content::style_suffix(
                        array(
                                'tone'    => $payload['tone'] ?? '',
                                'length'  => $payload['length'] ?? '',
                                // Lets the site profile send only the lines that match this
                                // article instead of the whole profile every time.
                                'topic'   => $topic . ' ' . (string) ( $payload['keyword'] ?? '' ),
                                'keywords' => (string) ( $payload['keyword'] ?? '' ),
                        )
                );

                $links = Haa_Content::internal_links( 0, $topic );

                $system = __( 'تو یک نویسنده محتوای سئو برای وبلاگ یک فروشگاه اینترنتی ایرانی هستی.', 'hami-ai-assistant' ) . "\n"
                        . __( 'خروجی را فقط و فقط به‌صورت HTML معتبر برگردان؛ بدون تگ h1، بدون کد فنس، بدون توضیح اضافه.', 'hami-ai-assistant' ) . "\n"
                        . __( 'ساختار: یک بند مقدمه، سپس h2 برای هر بخش، فهرست بولت وقتی لازم است، و یک جمع‌بندی.', 'hami-ai-assistant' ) . "\n"
                        . $style;

                $user = __( 'موضوع مقاله:', 'hami-ai-assistant' ) . ' ' . $topic . "\n";
                if ( ! empty( $payload['keyword'] ) ) {
                        $user .= __( 'کلیدواژه‌ی اصلی:', 'hami-ai-assistant' ) . ' ' . $payload['keyword'] . "\n";
                }
                if ( ! empty( $payload['intent'] ) ) {
                        $user .= __( 'هدف جستجو:', 'hami-ai-assistant' ) . ' ' . Haa_Planner::intent_label( (string) $payload['intent'] ) . "\n";
                }
                if ( $links ) {
                        $user .= __( 'لینک‌های داخلی واقعی سایت (حتماً چندتایشان را طبیعی در متن بیاور):', 'hami-ai-assistant' ) . "\n";
                        foreach ( $links as $l ) {
                                $user .= '- ' . $l['title'] . ': ' . $l['url'] . "\n";
                        }
                }

                $raw = Haa_Content::generate( $system, $user, array( 'module' => 'article-writer', 'operation' => 'article', 'max_tokens' => 3800 ) );
                if ( is_wp_error( $raw ) ) {
                        $GLOBALS['haai_job_result'] = $raw;
                        return;
                }

                // The model hit its output ceiling: one continuation pass, exactly like the
                // product length repair, so a long brief is not left half-written.
                if ( Haa_API::last_truncated() ) {
                        $more = Haa_Content::generate(
                                $system,
                                $user . "\n\n" . __( 'متن نوشته‌شده تا اینجا (فقط ادامه‌ی طبیعی همان متن را بده؛ چیزی که آمده را تکرار نکن):', 'hami-ai-assistant' ) . "\n«" . Haa_Util::cut( wp_strip_all_tags( (string) $raw ), 1600 ) . "»",
                                array( 'module' => 'article-writer', 'operation' => 'article_continue', 'max_tokens' => 3200 )
                        );
                        if ( ! is_wp_error( $more ) && '' !== trim( (string) $more ) ) {
                                $raw .= "\n\n" . $more;
                        }
                }

                $html = Haa_Content::to_html( $raw );
                if ( '' === trim( wp_strip_all_tags( $html ) ) ) {
                        $GLOBALS['haai_job_result'] = new WP_Error( 'haai_aw_empty_body', __( 'مدل متنی برنگرداند.', 'hami-ai-assistant' ) );
                        return;
                }

                // Pull an H2 as the title when the model did not lead with one.
                $title = $topic;
                if ( preg_match( '/<h2[^>]*>(.*?)<\/h2>/i', $html, $m ) ) {
                        $candidate = trim( wp_strip_all_tags( $m[1] ) );
                        if ( '' !== $candidate ) {
                                $title = $candidate;
                                $html  = preg_replace( '/<h2[^>]*>' . preg_quote( $m[1], '/' ) . '<\/h2>/i', '', $html, 1 );
                        }
                }

                $author = (int) Haa_Settings::get( 'article.default_author', 0 );
                if ( ! $author || ! get_userdata( $author ) ) {
                        $author = get_current_user_id();
                }

                $post_id = wp_insert_post(
                        array(
                                'post_title'   => Haa_Util::cut( $title, 200 ),
                                'post_content' => wp_kses( $html, Haa_Util::article_kses_allowed() ),
                                'post_status'  => (string) Haa_Settings::get( 'article.default_status', 'draft' ),
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

                // Tell the planner this subject is written, so the next planning run
                // stops proposing it and the topical map is rebuilt.
                if ( ! empty( $payload['topic_id'] ) ) {
                        Haa_Planner::mark_published( (int) $payload['topic_id'], (int) $post_id );
                }

                $category = (int) ( $payload['category'] ?? 0 );
                if ( $category ) {
                        wp_set_post_categories( (int) $post_id, array( $category ) );
                }

                if ( ( ! empty( $payload['image'] ) || ! empty( $payload['with_image'] ) ) && ! empty( Haa_Settings::get( 'content.enable_images' ) ) ) {
                        $result = Haa_Images::generate(
                                Haa_Images::product_prompt( $title, '' ),
                                array(
                                        'module'  => 'article-writer',
                                        'post_id' => (int) $post_id,
                                )
                        );
                        if ( ! empty( $result['ok'] ) ) {
                                set_post_thumbnail( (int) $post_id, (int) $result['attachment_id'] );
                        }
                        // An image failure must not fail the whole article.
                }
        }

        /* ------------------------------------------------------------------
         * D1: site profile drafts
         * ---------------------------------------------------------------- */

        /** @return void */
        public function handle_profile_build() {
                Haa_Util::guard_admin_post( 'haai_aw' );

                Haa_Site_Profile::build_draft();

                $this->back( __( 'پیش‌نویس حافظه‌ی سایت از محتوای خود سایت ساخته شد. هنوز ذخیره نشده — بررسیش کنید و تأیید کنید.', 'hami-ai-assistant' ) );
        }

        /** @return void */
        public function handle_profile_approve() {
                Haa_Util::guard_admin_post( 'haai_aw' );

                $done = Haa_Site_Profile::draft_approve();
                if ( ! $done ) {
                        $this->back( __( 'پیش‌نویسی برای تأیید نبود.', 'hami-ai-assistant' ), true );
                }

                $this->back(
                        sprintf( /* translators: %d: field count. */ __( '%d فیلد حافظه‌ی سایت جایگزین شد.', 'hami-ai-assistant' ), $done )
                );
        }

        /** @return void */
        public function handle_profile_discard() {
                Haa_Util::guard_admin_post( 'haai_aw' );
                Haa_Site_Profile::draft_discard();
                $this->back( __( 'پیش‌نویس حذف شد.', 'hami-ai-assistant' ) );
        }

        /* ------------------------------------------------------------------
         * D1: topic planner
         * ---------------------------------------------------------------- */

        /** @return void */
        public function handle_plan_gaps() {
                Haa_Util::guard_admin_post( 'haai_aw' );

                $count = max( 1, min( 20, (int) Haa_Settings::get( 'article.plan_count', 6 ) ) );
                $r     = Haa_Planner::propose_from_gaps( $count );

                if ( ! $r['added'] ) {
                        $this->back( $r['reason'] ? $r['reason'] : __( 'موضوع تازه‌ای از خوشه‌های خالی ساخته نشد.', 'hami-ai-assistant' ), true );
                }

                $this->back(
                        sprintf(
                                /* translators: 1: added, 2: skipped. */
                                __( '%1$d موضوع از خوشه‌های بدون مقاله ساخته شد (%2$d مورد تکراری یا پوششیافته رد شد). این فهرست هیچ هزینه‌ای نداشت.', 'hami-ai-assistant' ),
                                (int) $r['added'],
                                (int) $r['skipped']
                        )
                );
        }

        /** @return void */
        public function handle_plan_model() {
                Haa_Util::guard_admin_post( 'haai_aw' );

                $count = max( 1, min( 20, (int) Haa_Settings::get( 'article.plan_count', 6 ) ) );

                // One planning call, checked against the daily ceiling before it runs.
                $plan = Haa_Planner::estimate( 1 );
                if ( '' !== $plan['blocked'] ) {
                        $this->back( $plan['blocked'], true );
                }

                $r = Haa_Planner::propose_with_model( $count );
                if ( empty( $r['ok'] ) ) {
                        $this->back(
                                sprintf(
                                        /* translators: %s: reason. */
                                        __( 'برنامه‌ریزی با مدل انجام نشد: %s', 'hami-ai-assistant' ),
                                        (string) $r['error']
                                ),
                                true
                        );
                }

                $this->back(
                        sprintf(
                                /* translators: 1: added, 2: skipped. */
                                __( 'مدل %1$d موضوع پیشنهاد داد (%2$d مورد تکراری، بی‌اعتبار یا فاقد عنوان رد شد).', 'hami-ai-assistant' ),
                                (int) $r['added'],
                                (int) $r['skipped']
                        )
                );
        }

        /** @return void */
        public function handle_plan_queue() {
                Haa_Util::guard_admin_post( 'haai_aw' );

                $ids  = isset( $_POST['ids'] ) ? (array) wp_unslash( $_POST['ids'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
                $args = array(
                        'tone'     => isset( $_POST['tone'] ) ? sanitize_key( wp_unslash( $_POST['tone'] ) ) : '',
                        'length'   => isset( $_POST['length'] ) ? sanitize_key( wp_unslash( $_POST['length'] ) ) : '',
                        'category' => isset( $_POST['category'] ) ? absint( $_POST['category'] ) : 0,
                        'with_image' => ! empty( $_POST['with_image'] ),
                );

                $r = Haa_Planner::queue( $ids, $args );

                if ( ! $r['queued'] ) {
                        $this->back( '' !== $r['reason'] ? $r['reason'] : __( 'چیزی صف نشد.', 'hami-ai-assistant' ), true );
                }

                $this->back(
                        sprintf(
                                /* translators: 1: queued, 2: requests, 3: estimated tokens. */
                                __( '%1$d مقاله در صف رفت؛ تخمین %2$d درخواست و %3$d توکن. %4$s', 'hami-ai-assistant' ),
                                (int) $r['queued'],
                                (int) $r['estimate']['requests'],
                                number_format_i18n( (int) $r['estimate']['tokens'] ),
                                '' !== $r['reason'] ? $r['reason'] : ''
                        )
                );
        }

        /** @return void */
        public function handle_plan_status() {
                Haa_Util::guard_admin_post( 'haai_aw' );

                $id     = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
                $status = isset( $_POST['status'] ) ? sanitize_key( wp_unslash( $_POST['status'] ) ) : '';

                if ( ! $id || ! $status || ! Haa_Planner::row( $id ) ) {
                        $this->back( __( 'موضوع پیدا نشد.', 'hami-ai-assistant' ), true );
                }

                // Only a human decision may move a row; publishing happens from the job.
                $allowed = array( 'suggested', 'approved', 'rejected' );
                if ( ! in_array( $status, $allowed, true ) ) {
                        $this->back( __( 'وضعیت نامعتبر است.', 'hami-ai-assistant' ), true );
                }

                Haa_Planner::update( $id, array( 'status' => $status ) );

                $labels = array(
                        'suggested' => __( 'به فهرست برگشت.', 'hami-ai-assistant' ),
                        'approved'  => __( 'تأیید شد؛ حالا می‌توانید به صف بفرستید.', 'hami-ai-assistant' ),
                        'rejected'  => __( 'رد شد.', 'hami-ai-assistant' ),
                );
                $this->back( $labels[ $status ] );
        }

        /** @return void */
        public function handle_plan_delete() {
                Haa_Util::guard_admin_post( 'haai_aw' );

                $id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
                if ( ! $id || ! Haa_Planner::row( $id ) ) {
                        $this->back( __( 'موضوع پیدا نشد.', 'hami-ai-assistant' ), true );
                }

                Haa_Planner::delete( $id );
                $this->back( __( 'حذف شد.', 'hami-ai-assistant' ) );
        }

        /* ------------------------------------------------------------------
         * D2: competitor / SERP research
         * ---------------------------------------------------------------- */

        /** @return void */
        public function handle_research_analyze() {
                Haa_Util::guard_admin_post( 'haai_aw' );

                if ( ! Haa_Research::enabled() ) {
                        $this->back( __( 'ابتدا «تحلیل رقبا» را در تب تنظیمات «تحلیل رقبا» روشن کنید.', 'hami-ai-assistant' ), true );
                }
                if ( ! Haa_Research::competitors() ) {
                        $this->back( __( 'هیچ آدرس معتبری در فهرست رقبا نیست.', 'hami-ai-assistant' ), true );
                }

                // Crawling other people's servers is the one thing that must never run
                // from a page view or a front-end request.
                if ( function_exists( 'set_time_limit' ) ) {
                        @set_time_limit( 60 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
                }

                $r = Haa_Research::analyze( true );
                if ( empty( $r['ok'] ) ) {
                        $this->back( implode( ' ', (array) $r['errors'] ), true );
                }

                $msg = sprintf(
                        /* translators: 1: sites, 2: pages. */
                        __( 'تحلیل رقبا: %1$d سایت، %2$d صفحه.', 'hami-ai-assistant' ),
                        (int) $r['sites'],
                        (int) $r['pages']
                );
                if ( ! empty( $r['partial'] ) ) {
                        $msg .= ' ' . __( 'ناتمام ماند — دوباره بزنید تا ادامه یابد.', 'hami-ai-assistant' );
                }
                if ( ! empty( $r['errors'] ) ) {
                        $msg .= ' ' . sprintf( /* translators: %d: count. */ __( '%d مورد رد/خطا داشت.', 'hami-ai-assistant' ), count( $r['errors'] ) );
                }

                $this->back( $msg, empty( $r['sites'] ) );
        }

        /** @return void */
        public function handle_research_drop() {
                Haa_Util::guard_admin_post( 'haai_aw' );
                Haa_Research::drop_snapshot();
                $this->back( __( 'نتایج تحلیل رقبا پاک شد.', 'hami-ai-assistant' ) );
        }

        /**
         * AJAX: run keyword research for the planner panel.
         *
         * Combines Google Suggest, the optional SERP service, the AI snapshot and
         * one model call into a deduped list; the admin ticks rows and queues them.
         *
         * @return void Sends JSON.
         */
        public function ajax_kw_research() {
                Haa_Util::guard_admin_ajax();

                if ( function_exists( 'set_time_limit' ) ) {
                        @set_time_limit( 90 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
                }

                $seed = isset( $_POST['seed'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['seed'] ) ) : '';

                // Nothing in here may ever answer with a bare HTML fatal page: the
                // panel parses JSON, so any Throwable becomes a readable JSON error.
                try {
                        $r = Haa_Keywords::research( $seed );
                } catch ( Throwable $t ) {
                        $r = array(
                                'ok'    => false,
                                'error' => sprintf(
                                        /* translators: 1: error message, 2: file:line. */
                                        __( 'خطای داخلی: %1$s (%2$s)', 'hami-ai-assistant' ),
                                        $t->getMessage(),
                                        basename( $t->getFile() ) . ':' . $t->getLine()
                                ),
                                'rows'  => array(),
                        );
                }

                wp_send_json( is_array( $r ) ? $r : array( 'ok' => false, 'error' => '', 'rows' => array() ) );
        }

        /**
         * POST: turn selected keywords into suggested planner topics.
         *
         * @return void
         */
        public function handle_kw_queue() {
                Haa_Util::guard_admin_post( 'haai_aw' );

                $selected = isset( $_POST['kw'] ) ? (array) wp_unslash( $_POST['kw'] ) : array();
                $selected = array_filter( array_map( 'sanitize_text_field', $selected ), 'strlen' );
                if ( ! $selected ) {
                        $this->back( __( 'هیچ کلیدواژه‌ای انتخاب نشده بود.', 'hami-ai-assistant' ), true );
                }

                $added = 0;
                foreach ( array_slice( array_values( $selected ), 0, 30 ) as $kw ) {
                        $id = Haa_Planner::insert(
                                array(
                                        'title'    => $kw,
                                        'keyword'  => $kw,
                                        'intent'   => Haa_Keywords::guess_intent( $kw ),
                                        'reason'   => __( 'از پنل پیشنهاد کلمه کلیدی.', 'hami-ai-assistant' ),
                                        'source'   => 'keyword',
                                        'priority' => 5,
                                )
                        );
                        if ( $id ) {
                                $added++;
                        }
                }

                Haa_Planner::flush_map();
                $this->back( sprintf( __( '%1$d موضوع از %2$d کلیدواژه به برنامه‌ریز اضافه شد.', 'hami-ai-assistant' ), $added, count( $selected ) ) );
        }

        /**
         * Build one article brief for a planned topic.
         *
         * Costs exactly one model call, so the daily ceiling is consulted before it
         * is spent — the same rule as every other paid step in this pipeline.
         *
         * @return void
         */
        public function handle_research_brief() {
                Haa_Util::guard_admin_post( 'haai_aw' );

                $id  = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
                $row = $id ? Haa_Planner::row( $id ) : null;
                if ( ! $row ) {
                        $this->back( __( 'موضوع پیدا نشد.', 'hami-ai-assistant' ), true );
                }

                $plan = Haa_Planner::estimate( 1 );
                if ( '' !== $plan['blocked'] ) {
                        $this->back( $plan['blocked'], true );
                }

                $r = Haa_Research::brief( $row );
                if ( empty( $r['ok'] ) ) {
                        $this->back( '' !== $r['error'] ? $r['error'] : __( 'بریف ساخته نشد.', 'hami-ai-assistant' ), true );
                }

                Haa_Planner::update(
                        $id,
                        array(
                                'brief' => $r['brief'],
                                'stage' => 'briefed',
                                'error' => '',
                        )
                );

                $this->back(
                        sprintf(
                                /* translators: 1: section count, 2: word target. */
                                __( 'بریف ساخته شد: %1$d بخش، هدف %2$s کلمه.', 'hami-ai-assistant' ),
                                count( (array) $r['brief']['outline'] ),
                                number_format_i18n( (int) $r['brief']['word_target'] )
                        )
                );
        }

        /**
         * Turn selected competitor gaps into planned topics.
         *
         * Only the *heading text* is reused — a heading states what a subject is,
         * which is not copyrightable; the body of their page is never stored or
         * repurposed. Free (no model call).
         *
         * @return void
         */
        public function handle_research_gap() {
                Haa_Util::guard_admin_post( 'haai_aw' );

                $in = array();
                if ( isset( $_POST['gaps'] ) && is_array( $_POST['gaps'] ) ) {
                        // The panel posts checkboxes: gaps[] = heading strings.
                        foreach ( (array) wp_unslash( $_POST['gaps'] ) as $one ) {
                                $in[] = trim( (string) $one );
                        }
                } elseif ( isset( $_POST['gaps'] ) ) {
                        $in = array_map( 'trim', preg_split( '/\R+/u', (string) wp_unslash( $_POST['gaps'] ) ) );
                }
                $in = array_values( array_filter( $in ) );
                if ( ! $in ) {
                        $this->back( __( 'چیزی انتخاب نشده است.', 'hami-ai-assistant' ), true );
                }

                $rows = array();
                foreach ( array_slice( $in, 0, 20 ) as $heading ) {
                        $rows[] = array(
                                'title'    => $heading,
                                'keyword'  => $heading,
                                'intent'   => 'informational',
                                'priority' => 6,
                                'reason'   => __( 'رقبا این سرفصل را پوشش داده‌اند و ما نه.', 'hami-ai-assistant' ),
                                'source'   => 'gap',
                        );
                }

                $res = Haa_Planner::propose_from_rows( $rows );
                $this->back(
                        sprintf( /* translators: 1: added, 2: skipped. */ __( '%1$d شکاف به برنامه‌ریز اضافه شد (%2$d تکراری).', 'hami-ai-assistant' ), $res['added'], $res['skipped'] )
                );
        }

        /* ------------------------------------------------------------------
         * D3: review decisions
         * ---------------------------------------------------------------- */

        /**
         * Approve: publish now, publish later, or leave as draft.
         *
         * @return void
         */
        public function handle_pipe_approve() {
                Haa_Util::guard_admin_post( 'haai_aw' );

                $post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
                $to      = isset( $_POST['status'] ) ? (string) wp_unslash( $_POST['status'] ) : 'publish';
                $when    = isset( $_POST['publish_at'] ) ? (string) wp_unslash( $_POST['publish_at'] ) : '';

                $done = Haa_Pipeline::approve( $post_id, $to, $when );
                if ( is_wp_error( $done ) ) {
                        $this->back( (string) $done->get_error_message(), true );
                }

                $this->back(
                        'future' === $to
                                ? __( 'برای انتشار زمان‌بندی شد.', 'hami-ai-assistant' )
                                : __( 'منتشر شد.', 'hami-ai-assistant' )
                );
        }

        /** @return void */
        public function handle_pipe_reject() {
                Haa_Util::guard_admin_post( 'haai_aw' );

                $post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
                $done    = Haa_Pipeline::reject( $post_id );
                if ( is_wp_error( $done ) ) {
                        $this->back( (string) $done->get_error_message(), true );
                }
                $this->back( __( 'رد شد و به برنامه‌ریز برگشت.', 'hami-ai-assistant' ) );
        }

        /**
         * Rewrite one section of a draft, keeping the rest.
         *
         * Costs a model call, so the daily ceiling is consulted first.
         *
         * @return void
         */
        public function handle_pipe_section() {
                Haa_Util::guard_admin_post( 'haai_aw' );

                $id    = isset( $_POST['topic_id'] ) ? absint( $_POST['topic_id'] ) : 0;
                $index = isset( $_POST['index'] ) ? (int) $_POST['index'] : -1;

                $plan = Haa_Planner::estimate( 1 );
                if ( '' !== $plan['blocked'] ) {
                        $this->back( $plan['blocked'], true );
                }

                $done = Haa_Pipeline::regenerate_section( $id, $index );
                if ( is_wp_error( $done ) ) {
                        $this->back( (string) $done->get_error_message(), true );
                }
                $this->back( __( 'آن بخش بازنویسی شد.', 'hami-ai-assistant' ) );
        }

        /**
         * Apply one approved backlink to the older post.
         *
         * @return void
         */
        public function handle_pipe_backlink() {
                Haa_Util::guard_admin_post( 'haai_aw' );

                $old = isset( $_POST['old_id'] ) ? absint( $_POST['old_id'] ) : 0;
                $new = isset( $_POST['new_id'] ) ? absint( $_POST['new_id'] ) : 0;

                $done = Haa_Pipeline::apply_backlink( $old, $new );
                if ( is_wp_error( $done ) ) {
                        $this->back( (string) $done->get_error_message(), true );
                }
                $this->back( __( 'لینک برگشتی به نوشته‌ی قبلی اضافه شد.', 'hami-ai-assistant' ) );
        }

        /* ------------------------------------------------------------------
         * D4: monthly refresh
         * ---------------------------------------------------------------- */

        /**
         * Run the scan now.
         *
         * Outbound link probing is opt-in per click: a batch of HEAD requests to
         * other people's servers is not something a cron should do unattended.
         *
         * @return void
         */
        public function handle_refresh_scan() {
                Haa_Util::guard_admin_post( 'haai_aw' );

                $links = ! empty( $_POST['check_links'] );

                if ( function_exists( 'set_time_limit' ) ) {
                        @set_time_limit( 120 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
                }

                $r = Haa_Refresh::scan( $links );
                $this->back(
                        sprintf(
                                /* translators: 1: scanned, 2: needing. */
                                __( '%1$d نوشته بررسی شد؛ %2$d مورد نیازمند توجه است.', 'hami-ai-assistant' ),
                                (int) $r['scanned'],
                                (int) $r['needing']
                        ),
                        0 === (int) $r['scanned']
                );
        }

        /** @return void */
        public function handle_refresh_drop() {
                Haa_Util::guard_admin_post( 'haai_aw' );

                $id  = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
                $url = isset( $_POST['url'] ) ? (string) wp_unslash( $_POST['url'] ) : '';

                $done = Haa_Refresh::drop_link( $id, $url );
                if ( is_wp_error( $done ) ) {
                        $this->back( (string) $done->get_error_message(), true );
                }
                $this->back( __( 'لینک برداشته شد و متن دست‌نخورده ماند.', 'hami-ai-assistant' ) );
        }

        /** @return void */
        public function handle_refresh_dismiss() {
                Haa_Util::guard_admin_post( 'haai_aw' );

                $id  = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
                $url = isset( $_POST['url'] ) ? (string) wp_unslash( $_POST['url'] ) : '';

                if ( ! Haa_Refresh::dismiss( $id, $url ) ) {
                        $this->back( __( 'گزارشی برای این نوشته نبود.', 'hami-ai-assistant' ), true );
                }
                $this->back( __( 'این مورد کنار گذاشته شد.', 'hami-ai-assistant' ) );
        }

        /**
         * POST → Redirect → GET, with the message riding along in the URL.
         *
         * @param string $message Banner text.
         * @param bool   $error   Render it as a failure, not a success.
         * @return void
         */
        private function back( $message, $error = false ) {
                wp_safe_redirect(
                        Haa_Util::screen_url(
                                'article-writer',
                                array(
                                        $error ? 'haai_err' : 'haai_msg' => rawurlencode( Haa_Util::cut( $message, 300 ) ),
                                )
                        )
                );
                exit;
        }
}
