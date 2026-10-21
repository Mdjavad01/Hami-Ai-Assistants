<?php
/**
 * Module: Product Content — SEO description, title, slug, FAQ and image for
 * a WooCommerce product, generated in one click and saved as a draft you can
 * review before anything goes live.
 *
 * @package Hami_AI_Assistant
 */

if ( ! defined( 'ABSPATH' ) ) {
        exit;
}

final class Haa_Module_Product_Content implements Haa_Module_Interface {

        /** Transient holding the last generated brief per product. */
        const PREVIEW = 'haai_pc_preview_';

        /**
         * Short per-product lock around the manual generate click (P3).
         *
         * A double click, a refresh-and-resubmit or two browser tabs aimed at the
         * same product would otherwise pay for the model twice. The lock is only a
         * guard for a concurrent run; it is released the moment the run ends, and
         * the TTL only matters if PHP dies mid-request.
         */
        const GEN_LOCK = 'haai_pc_gen_';

        /** Seconds a generate lock lives if the request never finishes. */
        const GEN_LOCK_TTL = 120;

        /** Marker that content was just applied (P4), making «اعمال» idempotent. */
        const APPLIED = 'haai_pc_applied_';

        /** How long a finished apply still counts as «just applied». */
        const APPLIED_TTL = 600;

        /** @return string */
        public function slug() {
                return 'product-content';
        }

        /** @return string */
        public function label() {
                return __( 'محتوای محصول', 'hami-ai-assistant' );
        }

        /** @return string */
        public function description() {
                return __( 'توضیح سئو، عنوان، اسلگ، سؤالات متداول و تصویر محصول — با یک کلیک و قبل از انتشار.', 'hami-ai-assistant' );
        }

        /** @return bool */
        public function requires_woocommerce() {
                // Single source of truth: Haa_Modules::catalog_meta()[ 'woo' ]. Product
                // content reads the product_cat tree and WC products directly, so it is
                // genuinely WooCommerce-only and stays dormant when WooCommerce is off.
                return Haa_Modules::requires_woocommerce( $this->slug() );
        }

        /** @return void */
        public function register() {
                add_action( 'haai_settings_sections', array( $this, 'register_section' ) );
                add_action( 'haai_settings_fields_content', array( $this, 'register_fields' ) );
                add_action( 'haai_admin_menus', array( $this, 'register_menu' ) );
                add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
                add_action( 'admin_post_haai_pc_generate', array( $this, 'handle_generate' ) );
                add_action( 'admin_post_haai_pc_batch', array( $this, 'handle_batch' ) );
                add_action( 'admin_post_haai_pc_apply', array( $this, 'handle_apply' ) );
                add_action( 'admin_post_haai_pc_image', array( $this, 'handle_image' ) );
                add_action( 'admin_post_haai_pc_dismiss', array( $this, 'handle_dismiss' ) );
                add_action( 'wp_ajax_haai_pc_categories', array( $this, 'ajax_categories' ) );
                add_action( 'wp_ajax_haai_pc_products', array( $this, 'ajax_products' ) );
                add_action( 'wp_ajax_haai_pc_product_ids', array( $this, 'ajax_product_ids' ) );
                add_action( 'haai_run_job_pc_content', array( $this, 'run_content_job' ), 10, 2 );

                // A taxonomy edit makes the cached tree wrong before its TTL ends.
                add_action( 'edited_term_taxonomy', array( __CLASS__, 'flush_category_cache' ) );
        }

        /**
         * Picker script, on this module's screen only.
         *
         * An offline module registers nothing at all, and even when it is on the
         * file is never asked for outside its own page — a site that does not use
         * product content downloads no JavaScript for it.
         *
         * @param string $hook Current admin page hook.
         * @return void
         */
        public function assets( $hook ) {
                $screen = get_current_screen();
                if ( ! $screen || false === strpos( (string) $screen->id, Haa_Util::screen( 'product-content' ) ) ) {
                        return;
                }

                wp_enqueue_script(
                        'haai-product-picker',
                        HAAI_URL . haa_asset_path( 'assets/js/product-picker.js' ),
                        array(),
                        HAAI_VERSION,
                        true
                );
                wp_localize_script(
                        'haai-product-picker',
                        'haaiPicker',
                        array(
                                'ajax'    => admin_url( 'admin-ajax.php' ),
                                'nonce'   => wp_create_nonce( 'haai_admin' ),
                                'screen'  => Haa_Util::screen_url( 'product-content' ),
                                'cap'     => self::select_cap(),
                                'per'     => self::PER_MIN,
                                'perPage' => array( self::PER_MIN, 30, 50 ),
                                'images'  => (bool) Haa_Settings::get( 'content.enable_images' ),
                                'i18n'    => array(
                                        'loading'    => __( 'در حال بارگذاری…', 'hami-ai-assistant' ),
                                        'empty'      => __( 'محصولی با این فیلترها پیدا نشد.', 'hami-ai-assistant' ),
                                        'fail'       => __( 'درخواست ناموفق بود.', 'hami-ai-assistant' ),
                                        'selected'   => __( 'انتخاب شده', 'hami-ai-assistant' ),
                                        'confirmAll' => __( 'همه‌ی نتایج این فیلتر انتخاب شوند؟', 'hami-ai-assistant' ),
                                        'truncated'  => __( 'فهرست بزرگ‌تر از سقف انتخاب است؛ تنها بخشی از آن انتخاب شد.', 'hami-ai-assistant' ),
                                        'estimate'   => __( 'تخمین درخواست‌ها', 'hami-ai-assistant' ),
                                        'noCat'      => __( 'دسته‌ای برای نمایش نیست.', 'hami-ai-assistant' ),
                                        'generating' => __( 'در حال ساخت…', 'hami-ai-assistant' ),
                                        'applying'   => __( 'در حال اعمال…', 'hami-ai-assistant' ),
                                        'discard'    => __( 'ویرایش‌های دستی شما ذخیره نشده است. مطمئنید؟', 'hami-ai-assistant' ),
                                        'leavePage'  => __( 'ویرایش ذخیره‌نشده‌ای دارید.', 'hami-ai-assistant' ),
                                        'faqHeading' => __( 'سؤالات متداول', 'hami-ai-assistant' ),
                                ),
                        )
                );
        }

        /** @return void */
        public function activate() {
                // No tables of its own — everything it needs is core.
        }

        /** @return void */
        public function deactivate() {
                delete_transient( 'haai_pc_last_product' );
        }

        /**
         * @param array $sections Tabs so far.
         * @return array
         */
        public function register_section( $sections ) {
                $sections['content'] = __( 'محتوا و تصویر', 'hami-ai-assistant' );
                return $sections;
        }

        /**
         * @param array $fields Fields so far.
         * @return array
         */
        public function register_fields( $fields ) {
                $fields[] = array( 'key' => 'content.default_language', 'type' => 'select', 'label' => __( 'زبان پیش‌فرض', 'hami-ai-assistant' ), 'choices' => Haa_Util::languages() );
                $fields[] = array( 'key' => 'content.default_tone', 'type' => 'select', 'label' => __( 'لحن پیش‌فرض', 'hami-ai-assistant' ), 'choices' => Haa_Util::tones() );
                $fields[] = array( 'key' => 'content.default_length', 'type' => 'select', 'label' => __( 'طول پیش‌فرض', 'hami-ai-assistant' ), 'choices' => Haa_Util::lengths() );
                $fields[] = array( 'key' => 'content.default_audience', 'type' => 'text', 'label' => __( 'مخاطب پیش‌فرض', 'hami-ai-assistant' ) );
                $fields[] = array( 'key' => 'content.internal_links', 'type' => 'checkbox', 'label' => __( 'درج لینک داخلی', 'hami-ai-assistant' ) );
                $fields[] = array( 'key' => 'content.link_count', 'type' => 'number', 'label' => __( 'تعداد لینک داخلی', 'hami-ai-assistant' ), 'min' => 0, 'max' => 6 );
                $fields[] = array(
                        'key'     => 'content.link_types',
                        'type'    => 'chipbox',
                        'label'   => __( 'لینک به کدام مقصدها', 'hami-ai-assistant' ),
                        'choices' => array(
                                'product_cat' => __( 'دسته‌بندی محصولات', 'hami-ai-assistant' ),
                                'product'     => __( 'محصولات', 'hami-ai-assistant' ),
                                'post'        => __( 'نوشته‌ها (مقاله‌ها)', 'hami-ai-assistant' ),
                                'page'        => __( 'برگه‌ها', 'hami-ai-assistant' ),
                        ),
                        'hint'    => __( 'دسته‌ها اول انتخاب می‌شوند، بعد محصول و در آخر نوشته/برگه؛ هر چه تیک نزنید هرگز لینک نمی‌شود. برای فروشگاه، «دسته‌بندی + محصولات» معمولاً بهترین نتیجه را می‌دهد.', 'hami-ai-assistant' ),
                );
                $fields[] = array(
                        'key'     => 'content.include_price',
                        'type'    => 'checkbox',
                        'label'   => __( 'درج قیمت و موجودی در متن', 'hami-ai-assistant' ),
                        'hint'    => __( 'خاموش یعنی قیمت و موجودی نه به مدل داده می‌شود و نه در متن، سؤالات متداول یا عنوان سئو می‌آید. اگر روشن کنید این ارقام در تاریخ نوشته‌ی شما قفل می‌مانند و با تغییر قیمت فروشگاه، صفحه‌ی محصول قدیمی می‌شود — بهتر است شورتکد ووکامرس را خودتان در متن بگذارید.', 'hami-ai-assistant' ),
                );
                $fields[] = array( 'key' => 'content.enable_images', 'type' => 'checkbox', 'label' => __( 'تولید تصویر', 'hami-ai-assistant' ) );
                $fields[] = array( 'key' => 'content.image_size', 'type' => 'select', 'label' => __( 'اندازه تصویر', 'hami-ai-assistant' ), 'choices' => array( '1024x1024' => '1024×1024', '1792x1024' => '1792×1024', '1024x1792' => '1024×1792' ) );
                $fields[] = array( 'key' => 'content.image_quality', 'type' => 'select', 'label' => __( 'کیفیت تصویر', 'hami-ai-assistant' ), 'choices' => array( 'standard' => __( 'معمولی', 'hami-ai-assistant' ), 'hd' => __( 'بالا', 'hami-ai-assistant' ) ) );
                $fields[] = array( 'key' => 'content.image_style', 'type' => 'textarea', 'label' => __( 'سبک ثابت تصویر', 'hami-ai-assistant' ), 'hint' => __( 'به همه پرامپت‌ها اضافه می‌شود؛ مثلاً «نور طبیعی، پس‌زمینه سفید».', 'hami-ai-assistant' ) );
                return $fields;
        }

        /**
         * @param string $parent Slug of the parent menu.
         * @return void
         */
        public function register_menu( $parent ) {
                add_submenu_page(
                        $parent,
                        __( 'محتوای محصول', 'hami-ai-assistant' ),
                        __( 'محتوای محصول', 'hami-ai-assistant' ),
                        'manage_options',
                        Haa_Util::screen( 'product-content' ),
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

                $last = (int) get_transient( 'haai_pc_last_product' );
                $pid  = isset( $_GET['product'] ) ? absint( $_GET['product'] ) : $last;
                if ( ! $pid ) {
                        $recent = get_posts(
                                array(
                                        'post_type'      => 'product',
                                        'post_status'    => array( 'publish', 'draft', 'pending' ),
                                        'posts_per_page' => 1,
                                        'orderby'        => 'modified',
                                        'order'          => 'DESC',
                                        'fields'         => 'ids',
                                )
                        );
                        $pid = $recent ? (int) $recent[0] : 0;
                }

                $preview = $pid ? get_transient( self::PREVIEW . $pid ) : false;
                $preview = is_array( $preview ) ? $preview : null;
                ?>
                <div class="wrap haai-wrap haai-wrap--wide">
                        <?php Haa_Admin::page_header( __( 'محتوای محصول', 'hami-ai-assistant' ), 'content' ); ?>

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
                                <?php esc_html_e( 'محتوا ابتدا به‌صورت پیش‌نمایش ساخته می‌شود؛ چیزی تا زمانی که «اعمال» نزنید ذخیره یا منتشر نمی‌شود.', 'hami-ai-assistant' ); ?>
                        </p>

                        <?php $this->render_product_picker( $pid ); ?>

                        <?php if ( ! $pid ) : ?>
                        <p class="notice notice-warning inline"><?php esc_html_e( 'محصولی انتخاب نشده. از جست‌وجوی بالا استفاده کنید.', 'hami-ai-assistant' ); ?></p>
                        </div>
                        <?php return; ?>
                        <?php endif; ?>

                        <div class="haai-cards">
                                <div class="haai-card">
                                        <div class="haai-card__value"><?php echo esc_html( Haa_Util::cut( get_the_title( $pid ), 40 ) ); ?></div>
                                        <div class="haai-card__label">#<?php echo esc_html( $pid ); ?></div>
                                </div>
                                <div class="haai-card">
                                        <div class="haai-card__value"><?php echo esc_html( wp_count_posts( 'product' )->publish ?? 0 ); ?></div>
                                        <div class="haai-card__label"><?php esc_html_e( 'محصول منتشرشده', 'hami-ai-assistant' ); ?></div>
                                </div>
                                <div class="haai-card">
                                        <div class="haai-card__value"><?php echo esc_html( $preview ? __( 'آماده', 'hami-ai-assistant' ) : __( '—', 'hami-ai-assistant' ) ); ?></div>
                                        <div class="haai-card__label"><?php esc_html_e( 'پیش‌نمایش', 'hami-ai-assistant' ); ?></div>
                                </div>
                        </div>

                        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" data-haai-dirty-confirm>
                                <?php wp_nonce_field( 'haai_pc' ); ?>
                                <input type="hidden" name="action" value="haai_pc_generate">
                                <input type="hidden" name="product_id" value="<?php echo esc_attr( $pid ); ?>">

                                <div class="haai-field">
                                        <label class="haai-field__label"><?php esc_html_e( 'کلیدواژه‌ها', 'hami-ai-assistant' ); ?></label>
                                        <div class="haai-field__control">
                                                <input type="text" class="regular-text" name="keywords" value="" placeholder="<?php esc_attr_e( 'جداشده با کاما؛ مثلاً کیف چرم، هدیه مردانه', 'hami-ai-assistant' ); ?>">
                                        </div>
                                </div>
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
                                                        <?php foreach ( self::length_choices() as $slug => $label ) : ?>
                                                                <option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $slug, self::default_length() ); ?>><?php echo esc_html( $label ); ?></option>
                                                        <?php endforeach; ?>
                                                </select>
                                                <p class="description"><?php esc_html_e( 'برای محصول، داده‌ی کم = متن پرحشو. بلندترین گزینه حدود ۱۲۰۰ کلمه است؛ اگر داده‌ی محصول کمتر از هشت حقیقت باشد، متن کوتاه‌تر طبیعی است و افزونه حشو نمی‌سازد.', 'hami-ai-assistant' ); ?></p>
                                        </div>
                                </div>

                                <p class="submit">
                                        <button type="submit" class="button button-primary"><?php esc_html_e( 'ساخت پیش‌نمایش', 'hami-ai-assistant' ); ?></button>
                                </p>
                        </form>

                <?php if ( $preview ) : ?>
                        <?php $pv_notes = self::notes_of( $preview ); ?>
                        <h2 class="haai-pvtitle">
                                <?php esc_html_e( 'پیش‌نمایش', 'hami-ai-assistant' ); ?>
                                <span class="description"><?php esc_html_e( 'همه‌ی فیلدها قابل ویرایش‌اند؛ دقیقاً همین مقادیر اعمال می‌شوند.', 'hami-ai-assistant' ); ?></span>
                        </h2>

                        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="haai-pvform" data-haai-pvedit>
                                <?php wp_nonce_field( 'haai_pc' ); ?>
                                <input type="hidden" name="action" value="haai_pc_apply">
                                <input type="hidden" name="product_id" value="<?php echo esc_attr( $pid ); ?>">

                                <?php if ( $pv_notes ) : ?>
                                        <?php // بند «ب»: notes never become page text — they live in this panel. ?>
                                        <div class="haai-notes" data-haai-notes>
                                                <p class="haai-notes__title"><?php esc_html_e( 'یادداشت‌های بازبینی', 'hami-ai-assistant' ); ?>
                                                        <span class="haai-notes__count"><?php echo esc_html( (string) count( $pv_notes ) ); ?></span>
                                                </p>
                                                <p class="haai-notes__hint"><?php esc_html_e( 'این متن‌ها بخشی از صفحه‌ی محصول نمی‌شوند؛ می‌گویند کدام داده کم است. می‌توانید داده را کامل کنید، سؤال مربوط را حذف کنید، یا متن را بردارید.', 'hami-ai-assistant' ); ?></p>
                                                <ul class="haai-notes__list">
                                                        <?php foreach ( $pv_notes as $pv_note ) : ?>
                                                                <li class="haai-notes__item"><?php echo esc_html( $pv_note ); ?></li>
                                                        <?php endforeach; ?>
                                                </ul>
                                        </div>
                                <?php endif; ?>

                                <div class="haai-pvmeta">
                                        <div class="haai-field">
                                                <label class="haai-field__label" for="haai-pv-title"><?php esc_html_e( 'عنوان سئو', 'hami-ai-assistant' ); ?></label>
                                                <div class="haai-field__control">
                                                        <input type="text" id="haai-pv-title" class="large-text" name="seo_title" maxlength="80"
                                                                value="<?php echo esc_attr( $preview['seo_title'] ?? '' ); ?>"
                                                                data-haai-count="60" placeholder="<?php esc_attr_e( 'حداکثر ۶۰ کاراکتر پیشنهاد می‌شود', 'hami-ai-assistant' ); ?>">
                                                        <p class="description haai-count" data-haai-countfor="haai-pv-title"></p>
                                                </div>
                                        </div>
                                        <div class="haai-field">
                                                <label class="haai-field__label" for="haai-pv-desc"><?php esc_html_e( 'توضیح متا', 'hami-ai-assistant' ); ?></label>
                                                <div class="haai-field__control">
                                                        <textarea id="haai-pv-desc" name="seo_description" rows="3" class="large-text" maxlength="200"
                                                                data-haai-count="155"><?php echo esc_textarea( $preview['seo_description'] ?? '' ); ?></textarea>
                                                        <p class="description haai-count" data-haai-countfor="haai-pv-desc"></p>
                                                </div>
                                        </div>
                                        <div class="haai-field">
                                                <label class="haai-field__label" for="haai-pv-slug"><?php esc_html_e( 'اسلگ', 'hami-ai-assistant' ); ?></label>
                                                <div class="haai-field__control">
                                                        <input type="text" id="haai-pv-slug" class="regular-text" name="slug" dir="ltr"
                                                                value=""
                                                                placeholder="<?php echo esc_attr( $preview['slug'] ?? '' ); ?>">
                                                        <p class="description"><?php esc_html_e( 'خالی بماند = اسلگ فعلی محصول و آدرس صفحه حفظ می‌شود (بدون ریدایرکت). اسلگ پیشنهادی مدل در جای‌نگه‌دار نمایش داده شده؛ فقط اگر خودتان بنویسید آدرس عوض می‌شود. حروف لاتین، شماره و خط تیره.', 'hami-ai-assistant' ); ?></p>
                                                </div>
                                        </div>
                                </div>

                                <fieldset class="haai-field haai-field--bare">
                                        <legend class="haai-field__legend"><?php esc_html_e( 'بدنه متن', 'hami-ai-assistant' ); ?></legend>
                                        <?php
                                        // The honest length: words a reader counts, against what was asked.
                                        $pv_words  = isset( $preview['words'] ) ? (int) $preview['words'] : 0;
                                        $pv_target = isset( $preview['target_words'] ) ? (int) $preview['target_words'] : 0;
                                        ?>
                                        <?php if ( $pv_words > 0 ) : ?>
                                                <p class="description"><?php
                                                        printf(
                                                                /* translators: 1: words written, 2: target words */
                                                                esc_html__( '%1$d واژه نوشته شده (هدف: حدود %2$d کلمه). اگر کمتر از ۷۰٪ هدف باشد دلیلش در «یادداشت‌های بازبینی» آمده است.', 'hami-ai-assistant' ),
                                                                (int) $pv_words,
                                                                (int) $pv_target
                                                        );
                                                ?></p>
                                        <?php endif; ?>
                                        <?php if ( '' !== (string) ( $preview['keywords'] ?? '' ) ) : ?>
                                                <p class="description"><?php
                                                        printf(
                                                                /* translators: %s: focus keyword */
                                                                esc_html__( 'کلیدواژه‌ای که متن با آن نوشته شد: %s — اگر چیز دیگری می‌خواهید، عنوان سئو و توضیح متا را همین‌جا تغییر دهید.', 'hami-ai-assistant' ),
                                                                '<code>' . esc_html( (string) $preview['keywords'] ) . '</code>'
                                                        );
                                                ?></p>
                                        <?php endif; ?>
                                        <?php $preview_faq = array_values( (array) ( $preview['faq'] ?? array() ) ); ?>
                                        <div class="haai-pvedit">
                                                <div class="haai-pvedit__tabs" role="tablist" aria-label="<?php esc_attr_e( 'نمای بدنه متن', 'hami-ai-assistant' ); ?>">
                                                        <button type="button" class="button button-small is-active" data-haai-tab="view" role="tab"
                                                                id="haai-pvtab-view" aria-controls="haai-pvpane-view" aria-selected="true" tabindex="0"><?php esc_html_e( 'پیش‌نمایش', 'hami-ai-assistant' ); ?></button>
                                                        <button type="button" class="button button-small" data-haai-tab="src" role="tab"
                                                                id="haai-pvtab-src" aria-controls="haai-pvpane-src" aria-selected="false" tabindex="-1"><?php esc_html_e( 'ویرایش HTML', 'hami-ai-assistant' ); ?></button>
                                                        <span class="haai-count haai-count--inline" data-haai-bodycount></span>
                                                </div>
                                                <div class="haai-pvedit__pane is-active" data-haai-pane="view" id="haai-pvpane-view" role="tabpanel" aria-labelledby="haai-pvtab-view" tabindex="0">
                                                        <div class="haai-pv-frame" data-haai-pvview>
                                                                <div data-haai-pvbody><?php echo wp_kses_post( $preview['html'] ?? '' ); ?></div>
                                                                <?php if ( $preview_faq ) : ?>
                                                                        <?php // P6: the FAQ block is shown here exactly as Haa_Seo will append it. ?>
                                                                        <div class="haai-pv-faq" data-haai-pvfaq>
                                                                                <p class="haai-pv-faq__label"><?php esc_html_e( 'این بلوک هم به انتهای متن محصول اضافه می‌شود — همان چیزی که اینجا می‌بینید اعمال می‌شود.', 'hami-ai-assistant' ); ?></p>
                                                                                <div data-haai-pvfaqbody><?php echo Haa_Seo::faq_html( $preview_faq ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
                                                                        </div>
                                                                <?php endif; ?>
                                                        </div>
                                                </div>
                                                <div class="haai-pvedit__pane" data-haai-pane="src" id="haai-pvpane-src" role="tabpanel" aria-labelledby="haai-pvtab-src" tabindex="0" hidden>
                                                        <textarea name="html" rows="18" class="haai-pv-src" dir="ltr" spellcheck="false"
                                                                data-haai-pvsrc><?php echo esc_textarea( $preview['html'] ?? '' ); ?></textarea>
                                                </div>
                                        </div>
                                </fieldset>

                                <fieldset class="haai-field haai-field--bare">
                                        <legend class="haai-field__legend"><?php esc_html_e( 'سؤالات متداول', 'hami-ai-assistant' ); ?></legend>
                                        <?php
                                        // Marks the fieldset as posted: with every row deleted there is
                                        // no faq[] key left, and that must mean «no FAQ», not «leave the
                                        // model's FAQ alone».
                                        ?>
                                        <input type="hidden" name="faq_present" value="1">
                                        <div class="haai-faq" data-haai-faq>
                                                <?php
                                                $faq_rows = (array) ( $preview['faq'] ?? array() );
                                                if ( ! $faq_rows ) {
                                                        $faq_rows = array( array( 'q' => '', 'a' => '' ) );
                                                }
                                                foreach ( array_values( $faq_rows ) as $i => $f ) :
                                                        ?>
                                                        <div class="haai-faqrow" data-haai-faqrow>
                                                                <input type="text" name="faq[<?php echo (int) $i; ?>][q]" value="<?php echo esc_attr( (string) ( $f['q'] ?? '' ) ); ?>" placeholder="<?php esc_attr_e( 'سؤال', 'hami-ai-assistant' ); ?>">
                                                                <textarea name="faq[<?php echo (int) $i; ?>][a]" rows="2" placeholder="<?php esc_attr_e( 'پاسخ', 'hami-ai-assistant' ); ?>"><?php echo esc_textarea( (string) ( $f['a'] ?? '' ) ); ?></textarea>
                                                                <button type="button" class="button-link-delete" data-haai-faqdel><?php esc_html_e( 'حذف', 'hami-ai-assistant' ); ?></button>
                                                        </div>
                                                <?php endforeach; ?>
                                        </div>
                                        <p class="haai-faq__add">
                                                <button type="button" class="button button-small" data-haai-faqadd><?php esc_html_e( 'افزودن سؤال', 'hami-ai-assistant' ); ?></button>
                                        </p>
                                        <template data-haai-faqtpl>
                                                <div class="haai-faqrow" data-haai-faqrow>
                                                        <input type="text" name="faq[0][q]" value="" placeholder="<?php esc_attr_e( 'سؤال', 'hami-ai-assistant' ); ?>">
                                                        <textarea name="faq[0][a]" rows="2" placeholder="<?php esc_attr_e( 'پاسخ', 'hami-ai-assistant' ); ?>"></textarea>
                                                        <button type="button" class="button-link-delete" data-haai-faqdel><?php esc_html_e( 'حذف', 'hami-ai-assistant' ); ?></button>
                                                </div>
                                        </template>
                                </fieldset>

                                <div class="haai-actions haai-actions--apply">
                                        <?php if ( $pv_notes ) : ?>
                                                <label class="haai-checkbox haai-notes__check" for="haai-pv-notesseen">
                                                        <input type="checkbox" id="haai-pv-notesseen" name="notes_seen" value="1" data-haai-notesack>
                                                        <?php esc_html_e( 'یادداشت‌ها را دیدم', 'hami-ai-assistant' ); ?>
                                                </label>
                                                <?php // The button is gated by JS only; the server refuses without the tick anyway. ?>
                                        <?php endif; ?>
                                        <button type="submit" class="button button-primary"><?php esc_html_e( 'اعمال روی محصول', 'hami-ai-assistant' ); ?></button>
                                        <span class="description"><?php esc_html_e( 'پیش‌نمایش پس از اعمال حذف می‌شود.', 'hami-ai-assistant' ); ?></span>
                                </div>
                        </form>

                        <div class="haai-actions">
                                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                                        <?php wp_nonce_field( 'haai_pc' ); ?>
                                        <input type="hidden" name="action" value="haai_pc_image">
                                        <input type="hidden" name="product_id" value="<?php echo esc_attr( $pid ); ?>">
                                        <button type="submit" class="button" <?php disabled( empty( Haa_Settings::get( 'content.enable_images' ) ) ); ?>>
                                                <?php esc_html_e( 'تولید تصویر', 'hami-ai-assistant' ); ?>
                                        </button>
                                </form>
                                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" data-haai-dirty-confirm>
                                        <?php wp_nonce_field( 'haai_pc' ); ?>
                                        <input type="hidden" name="action" value="haai_pc_dismiss">
                                        <input type="hidden" name="product_id" value="<?php echo esc_attr( $pid ); ?>">
                                        <button type="submit" class="button-link-delete"><?php esc_html_e( 'حذف پیش‌نمایش', 'hami-ai-assistant' ); ?></button>
                                </form>
                        </div>
                <?php endif; ?>
                </div>
                <?php
        }

        /* ------------------------------------------------------------------
         * Product picker: categories, filters, rows, ids
         * ---------------------------------------------------------------- */

        /** Object-cache / transient key for the flattened product_cat tree. */
        const CAT_CACHE = 'haai_pc_cat_tree';

        /** Seconds the category tree may be stale. It changes only on taxonomy edits. */
        const CAT_TTL = 300;

        /** Default ceiling for "select all results of this filter". */
        const SELECT_CAP = 500;

        /** Bounds for one page of rows. */
        const PER_MIN = 20;
        const PER_MAX = 50;

        /**
         * How many ids a pre-resolved id set may hold before the filter is
         * considered too broad to page through.
         */
        const ID_POOL = 2000;

        /**
         * The full product_cat taxonomy as a flat parent => children list.
         *
         * Deliberately flat: the browser assembles the tree, so one payload carries
         * any depth and the JS can still show counts and partial selections.
         * Cached twice — a per-request object cache and a short transient — because
         * this screen asks for it on every filter change.
         *
         * @return array<int,array{id:int,parent:int,name:string,count:int}>
         */
        public static function category_tree() {
                $cached = wp_cache_get( self::CAT_CACHE, 'haai' );
                if ( is_array( $cached ) ) {
                        return $cached;
                }

                $saved = get_transient( self::CAT_CACHE );
                if ( is_array( $saved ) && $saved ) {
                        wp_cache_set( self::CAT_CACHE, $saved, 'haai', self::CAT_TTL );
                        return $saved;
                }

                $tree = array();
                if ( haa_is_woo() && taxonomy_exists( 'product_cat' ) ) {
                        $terms = get_terms( array(
                                'taxonomy'   => 'product_cat',
                                'hide_empty' => false,
                                'orderby'    => 'name',
                                'number'     => self::ID_POOL,
                        ) );
                        if ( $terms && ! is_wp_error( $terms ) ) {
                                foreach ( $terms as $t ) {
                                        $tree[] = array(
                                                'id'     => (int) $t->term_id,
                                                'parent' => (int) $t->parent,
                                                'name'   => (string) $t->name,
                                                'count'  => (int) $t->count,
                                        );
                                }
                        }
                }

                wp_cache_set( self::CAT_CACHE, $tree, 'haai', self::CAT_TTL );
                set_transient( self::CAT_CACHE, $tree, self::CAT_TTL );

                return $tree;
        }

        /**
         * Drop the cached tree, e.g. after a taxonomy change.
         *
         * @return void
         */
        public static function flush_category_cache() {
                delete_transient( self::CAT_CACHE );
                wp_cache_delete( self::CAT_CACHE, 'haai' );
        }

        /**
         * Term ids of a category and, when asked, all of its descendants.
         *
         * @param array<int> $cats     Chosen term ids.
         * @param bool       $children Include the whole subtree.
         * @return array<int>
         */
        public static function expand_cats( $cats, $children ) {
                $cats = self::id_list( $cats );
                if ( ! $cats || ! $children ) {
                        return $cats;
                }

                $by_parent = array();
                foreach ( self::category_tree() as $row ) {
                        $by_parent[ $row['parent'] ][] = $row['id'];
                }

                $out  = array();
                $seen = array();
                $walk = function ( $id ) use ( &$walk, &$out, &$seen, $by_parent ) {
                        if ( isset( $seen[ $id ] ) ) {
                                return; // A malformed loop in the taxonomy must not hang us.
                        }
                        $seen[ $id ] = true;
                        $out[ $id ]  = $id;
                        foreach ( (array) ( $by_parent[ $id ] ?? array() ) as $kid ) {
                                $walk( $kid );
                        }
                };
                foreach ( $cats as $id ) {
                        $walk( $id );
                }

                return array_values( $out );
        }

        /**
         * Cap on "select all results of this filter".
         *
         * @return int
         */
        public static function select_cap() {
                /**
                 * Filters how many product ids one "select all" may return.
                 *
                 * @param int $cap Default 500.
                 */
                return max( 1, (int) apply_filters( 'haai_pc_select_cap', self::SELECT_CAP ) );
        }

        /**
         * Clean a submitted list of term ids.
         *
         * Reindexed and positive-only on purpose: array_filter() keeps the original
         * keys, and a gapped list JSON-encodes as an object rather than an array —
         * and a negative term id is never valid, so it must not survive as one.
         *
         * @param mixed $raw Submitted value.
         * @return array<int>
         */
        public static function id_list( $raw ) {
                $out = array();
                foreach ( (array) $raw as $id ) {
                        $id = (int) $id;
                        if ( $id > 0 ) {
                                $out[ $id ] = $id;
                        }
                }
                return array_values( $out );
        }

        /**
         * Normalise the picker's request parameters.
         *
         * @param array $raw Raw request-ish array.
         * @return array
         */
        public static function parse_filters( $raw ) {
                $per = isset( $raw['per'] ) ? (int) $raw['per'] : self::PER_MIN;
                if ( $per < self::PER_MIN ) {
                        $per = self::PER_MIN;
                }
                if ( $per > self::PER_MAX ) {
                        $per = self::PER_MAX;
                }

                $status = isset( $raw['status'] ) ? (array) $raw['status'] : array();
                $status = array_values( array_intersect( $status, array( 'publish', 'draft', 'pending', 'private' ) ) );

                $types = isset( $raw['type'] ) ? (array) $raw['type'] : array();
                $types = array_values( array_intersect( array_map( 'sanitize_key', $types ), array( 'simple', 'variable', 'grouped', 'external' ) ) );

                $stock = isset( $raw['stock'] ) ? sanitize_key( $raw['stock'] ) : '';
                if ( ! in_array( $stock, array( 'instock', 'outofstock', 'onbackorder' ), true ) ) {
                        $stock = '';
                }

                $content = isset( $raw['content'] ) ? (array) $raw['content'] : array();
                $content = array_values( array_intersect( array_map( 'sanitize_key', $content ), array( 'no_excerpt', 'no_description', 'no_thumbnail' ) ) );

                return array(
                        'q'        => isset( $raw['q'] ) ? trim( (string) $raw['q'] ) : '',
                        'cats'     => isset( $raw['cat'] ) ? self::id_list( $raw['cat'] ) : array(),
                        'children' => ! empty( $raw['children'] ),
                        'status'   => $status,
                        'types'    => $types,
                        'stock'    => $stock,
                        'content'  => $content,
                        'page'     => max( 1, isset( $raw['page'] ) ? (int) $raw['page'] : 1 ),
                        'per'      => $per,
                );
        }

        /**
         * Ids whose SKU matches, through WooCommerce's lookup table when it exists.
         *
         * The lookup is a real indexed column; postmeta LIKE would scan every meta
         * row. Falling back to meta_query keeps this working on stores whose lookup
         * table was never populated.
         *
         * @param string $term Search text.
         * @return array<int>
         */
        public static function sku_ids( $term ) {
                global $wpdb;

                $term = trim( (string) $term );
                if ( '' === $term || ! haa_is_woo() ) {
                        return array();
                }

                $lookup  = $wpdb->prefix . 'wc_product_meta_lookup';
                $present = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $lookup ) ) ); // phpcs:ignore WordPress.DB

                if ( $present ) {
                        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
                        $rows = $wpdb->get_col(
                                $wpdb->prepare(
                                        "SELECT product_id FROM $lookup WHERE sku LIKE %s ORDER BY product_id LIMIT %d",
                                        '%' . $wpdb->esc_like( $term ) . '%',
                                        self::ID_POOL
                                )
                        );
                        return array_values( array_filter( array_map( 'intval', (array) $rows ) ) );
                }

                $ids = get_posts(
                        array(
                                'post_type'      => 'product',
                                'post_status'    => 'any',
                                'fields'         => 'ids',
                                'posts_per_page' => self::ID_POOL,
                                'no_found_rows'  => true,
                                'meta_query'     => array(
                                        array(
                                                'key'     => '_sku',
                                                'value'   => $term,
                                                'compare' => 'LIKE',
                                        ),
                                ),
                        )
                );

                return array_values( array_filter( array_map( 'intval', (array) $ids ) ) );
        }

        /**
         * Ids matching a content-state filter, resolved with one bounded query.
         *
         * post_excerpt / post_content are real columns, so WP_Query has no primitive
         * for "is empty" and a posts_where callback would have to be shared between
         * the row query and the count query. Resolving to an id pool once is simpler
         * and keeps every fragment of SQL whitelisted rather than assembled from
         * user input.
         *
         * @param array<int> $states no_excerpt | no_description | no_thumbnail.
         * @return array<int>
         */
        public static function content_state_ids( $states ) {
                global $wpdb;

                $states = array_values( array_intersect( (array) $states, array( 'no_excerpt', 'no_description', 'no_thumbnail' ) ) );
                if ( ! $states ) {
                        return array();
                }

                $posts  = $wpdb->posts;
                $where  = array( "$posts.post_type = 'product'" );
                $join   = '';

                if ( in_array( 'no_excerpt', $states, true ) ) {
                        $where[] = "( $posts.post_excerpt IS NULL OR $posts.post_excerpt = '' )";
                }
                if ( in_array( 'no_description', $states, true ) ) {
                        $where[] = "( $posts.post_content IS NULL OR TRIM($posts.post_content) = '' )";
                }
                if ( in_array( 'no_thumbnail', $states, true ) ) {
                        $meta    = $wpdb->postmeta;
                        $join    = " LEFT JOIN $meta ON $meta.post_id = $posts.ID AND $meta.meta_key = '_thumbnail_id'";
                        $where[] = "( $meta.meta_value IS NULL OR $meta.meta_value = '' OR $meta.meta_value = '0' )";
                }

                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
                $ids = $wpdb->get_col( 'SELECT ' . $posts . '.ID FROM ' . $posts . $join . ' WHERE ' . implode( ' AND ', $where ) . ' LIMIT ' . self::ID_POOL );

                return array_values( array_filter( array_map( 'intval', (array) $ids ) ) );
        }

        /**
         * WP_Query args for the current filters.
         *
         * Only parent products are listed: a variation is a separate post type
         * (product_variation), so it never appears here, and its parent still shows
         * as "variable" through the product_type taxonomy.
         *
         * @param array    $filters parse_filters() output.
         * @param int|null $limit   Hard cap on the result set (select-all mode).
         * @return array
         */
        public static function query_args( $filters, $limit = null ) {
                $args = array(
                        'post_type'      => 'product',
                        'post_status'    => $filters['status'] ? $filters['status'] : array( 'publish', 'draft', 'pending', 'private' ),
                        'orderby'        => 'title',
                        'order'          => 'ASC',
                        'posts_per_page' => null === $limit ? $filters['per'] : $limit,
                        'paged'          => $filters['page'],
                        'tax_query'      => array(),
                        'meta_query'     => array(),
                );

                $ids = null;

                // Search: title, SKU or numeric id — all three, ORed, resolved once.
                if ( '' !== $filters['q'] ) {
                        $found = self::sku_ids( $filters['q'] );
                        if ( ctype_digit( $filters['q'] ) ) {
                                $found[] = (int) $filters['q'];
                        } else {
                                $title = get_posts(
                                        array(
                                                'post_type'      => 'product',
                                                'post_status'    => 'any',
                                                'fields'         => 'ids',
                                                'posts_per_page' => self::ID_POOL,
                                                'no_found_rows'  => true,
                                                's'              => $filters['q'],
                                        )
                                );
                                $found = array_merge( $found, array_map( 'intval', (array) $title ) );
                        }
                        $ids = array_values( array_unique( array_filter( $found ) ) );
                        if ( ! $ids ) {
                                $ids = array( 0 ); // Nothing matched: page over an empty set.
                        }
                }

                // Content state.
                if ( $filters['content'] ) {
                        $state_ids = self::content_state_ids( $filters['content'] );
                        $ids       = null === $ids ? $state_ids : array_values( array_intersect( $ids, $state_ids ) );
                        if ( ! $ids ) {
                                $ids = array( 0 );
                        }
                }

                if ( null !== $ids ) {
                        $args['post__in'] = $ids;
                        // post__in + orderby title still pages; keep the pool order out of it.
                        unset( $args['paged'] );
                        $args['offset'] = ( $filters['page'] - 1 ) * $args['posts_per_page'];
                }

                $cats = self::expand_cats( $filters['cats'], $filters['children'] );
                if ( $cats ) {
                        $args['tax_query'][] = array(
                                'taxonomy' => 'product_cat',
                                'field'    => 'term_id',
                                'terms'    => $cats,
                        );
                }

                if ( $filters['types'] ) {
                        $args['tax_query'][] = array(
                                'taxonomy' => 'product_type',
                                'field'    => 'slug',
                                'terms'    => $filters['types'],
                        );
                }

                if ( '' !== $filters['stock'] ) {
                        $args['meta_query'][] = array(
                                'key'   => '_stock_status',
                                'value' => $filters['stock'],
                        );
                }

                if ( ! $args['tax_query'] ) {
                        unset( $args['tax_query'] );
                }
                if ( ! $args['meta_query'] ) {
                        unset( $args['meta_query'] );
                }

                return $args;
        }

        /**
         * AJAX: the category tree, for the JS to assemble.
         *
         * @return void
         */
        public function ajax_categories() {
                Haa_Util::guard_admin_ajax();
                wp_send_json( array( 'ok' => true, 'terms' => self::category_tree() ) );
        }

        /**
         * AJAX: one page of product rows with the columns the table shows.
         *
         * @return void
         */
        public function ajax_products() {
                Haa_Util::guard_admin_ajax();

                if ( ! haa_is_woo() ) {
                        wp_send_json( array( 'ok' => false, 'error' => __( 'WooCommerce در این سایت فعال نیست.', 'hami-ai-assistant' ) ) );
                        return;
                }

                $filters = self::parse_filters( isset( $_REQUEST ) ? wp_unslash( $_REQUEST ) : array() ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
                $rows    = self::query_products( $filters );

                wp_send_json( array_merge( array( 'ok' => true ), $rows ) );
        }

        /**
         * AJAX: only the ids matching the current filters, for "select all".
         *
         * Titles, prices and thumbnails are irrelevant here — a store with thousands
         * of products must not pay for them just to fill a Set in the browser.
         *
         * @return void
         */
        public function ajax_product_ids() {
                Haa_Util::guard_admin_ajax();

                if ( ! haa_is_woo() ) {
                        wp_send_json( array( 'ok' => false, 'error' => __( 'WooCommerce در این سایت فعال نیست.', 'hami-ai-assistant' ) ) );
                        return;
                }

                $filters = self::parse_filters( isset( $_REQUEST ) ? wp_unslash( $_REQUEST ) : array() ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
                $cap     = self::select_cap();

                $args                 = self::query_args( $filters, $cap );
                $args['fields']       = 'ids';
                $args['no_found_rows'] = true;

                $query = new WP_Query( $args );
                $ids   = array_values( array_filter( array_map( 'intval', (array) $query->posts ) ) );

                wp_send_json(
                        array(
                                'ok'      => true,
                                'ids'     => $ids,
                                'truncated' => count( $ids ) >= $cap,
                                'cap'     => $cap,
                        )
                );
        }

        /**
         * Run one page of the picker query and build its rows.
         *
         * Three bounded reads for the whole page: the ids, their SKU/price/thumbnail
         * columns from the lookup table, and their categories in one term query.
         *
         * @param array $filters parse_filters() output.
         * @return array{rows:array,total:int,page:int,pages:int,cap:int}
         */
        public static function query_products( $filters ) {
                $args  = self::query_args( $filters );
                $query = new WP_Query( $args );

                $ids = array_values( array_filter( array_map( 'intval', wp_list_pluck( (array) $query->posts, 'ID' ) ) ) );

                return array(
                        'rows'  => self::build_rows( $ids ),
                        'total' => (int) $query->found_posts,
                        'page'  => (int) $filters['page'],
                        'pages' => (int) $query->max_num_pages,
                        'cap'   => self::select_cap(),
                );
        }

        /**
         * Column data for a page of ids, in one bounded pass each.
         *
         * @param array<int> $ids Product ids, already ordered.
         * @return array<int,array>
         */
        public static function build_rows( $ids ) {
                global $wpdb;

                if ( ! $ids ) {
                        return array();
                }

                $lookup   = $wpdb->prefix . 'wc_product_meta_lookup';
                $present  = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $lookup ) ) ); // phpcs:ignore WordPress.DB
                $look     = array();

                if ( $present ) {
                        // WooCommerce 3.6 renamed the columns: price became min_price /
                        // max_price and thumb_id was dropped. Selecting the old names made
                        // this query fail silently and the price column came back empty.
                        $cols   = self::lookup_columns();
                        $select = array( 'product_id', 'sku' );
                        $price  = in_array( 'min_price', $cols, true )
                                ? 'min_price'
                                : ( in_array( 'price', $cols, true ) ? 'price' : '' );
                        if ( '' !== $price ) {
                                $select[] = $price;
                                if ( 'min_price' === $price && in_array( 'max_price', $cols, true ) ) {
                                        $select[] = 'max_price';
                                }
                        }
                        if ( in_array( 'thumb_id', $cols, true ) ) {
                                $select[] = 'thumb_id';
                        }
                        $in   = implode( ',', array_map( 'intval', $ids ) );
                        $rows = $wpdb->get_results( 'SELECT ' . implode( ', ', $select ) . " FROM $lookup WHERE product_id IN ($in)", ARRAY_A ); // phpcs:ignore WordPress.DB
                        foreach ( (array) $rows as $r ) {
                                $look[ (int) $r['product_id'] ] = $r;
                        }
                }

                $terms = wp_get_object_terms( $ids, array( 'product_cat', 'product_type' ), array( 'fields' => 'names' ) );
                unset( $terms ); // Prime the term cache once so the per-row reads below are cache hits.

                $out = array();
                foreach ( $ids as $id ) {
                        $cats  = array();
                        $type  = '';
                        $got   = get_the_terms( $id, 'product_cat' );
                        if ( $got && ! is_wp_error( $got ) ) {
                                foreach ( (array) $got as $t ) {
                                        $cats[] = (string) $t->name;
                                }
                        }
                        $pt = get_the_terms( $id, 'product_type' );
                        if ( $pt && ! is_wp_error( $pt ) ) {
                                $first = (array) $pt;
                                $type  = (string) $first[0]->name;
                        }

                $row = isset( $look[ $id ] ) ? $look[ $id ] : array();

                $out[] = array(
                        'id'        => $id,
                        'title'     => '' !== (string) get_the_title( $id ) ? get_the_title( $id ) : __( '(بدون عنوان)', 'hami-ai-assistant' ),
                        'desc'      => Haa_Util::cut( wp_strip_all_tags( (string) get_post_field( 'post_excerpt', $id ) ), 90 ),
                        'sku'       => isset( $row['sku'] ) ? (string) $row['sku'] : (string) get_post_meta( $id, '_sku', true ),
                        'price'     => self::price_label( $id, $row ),
                        'thumb'     => self::thumb_for( $id, $row ),
                                'cats'      => $cats,
                                'type'      => $type,
                                'stock'     => (string) get_post_meta( $id, '_stock_status', true ),
                                'status'    => (string) get_post_status( $id ),
                                'has_excerpt'   => '' !== trim( (string) get_post_field( 'post_excerpt', $id ) ),
                                'has_content'   => '' !== trim( (string) get_post_field( 'post_content', $id ) ),
                                'has_thumb'     => (bool) ( isset( $row['thumb_id'] ) && (int) $row['thumb_id'] ) || (bool) (int) get_post_thumbnail_id( $id ),
                                'edit'      => get_edit_post_link( $id ),
                        );
                }

                unset( $terms );

                return $out;
        }

        /**
         * Columns of the WooCommerce lookup table, probed once per request.
         *
         * @return array<int,string>
         */
        private static function lookup_columns() {
                static $cols = null;
                if ( null === $cols ) {
                        global $wpdb;
                        $cols = array_map( 'strval', (array) $wpdb->get_col( 'SHOW COLUMNS FROM ' . $wpdb->prefix . 'wc_product_meta_lookup' ) ); // phpcs:ignore WordPress.DB
                }
                return $cols;
        }

        /**
         * Readable price for one row: the lookup's min/max when it has them, the
         * legacy price column or _price meta otherwise, formatted by WooCommerce.
         *
         * @param int   $id  Product id.
         * @param array $row Lookup row, if any.
         * @return string
         */
        private static function price_label( $id, $row ) {
                $min = '';
                if ( isset( $row['min_price'] ) ) {
                        $min = (string) $row['min_price'];
                } elseif ( isset( $row['price'] ) ) {
                        $min = (string) $row['price'];
                } else {
                        $min = (string) get_post_meta( $id, '_price', true );
                }
                $min = trim( $min );
                if ( '' === $min || ! is_numeric( $min ) ) {
                        return '';
                }

                $max = isset( $row['max_price'] ) ? trim( (string) $row['max_price'] ) : '';
                if ( '' !== $max && is_numeric( $max ) && (float) $max > (float) $min ) {
                        return self::clean_price_text(
                                function_exists( 'wc_format_price_range' )
                                        ? (string) wc_format_price_range( $min, $max )
                                        : $min . ' – ' . $max
                        );
                }

                return self::clean_price_text( function_exists( 'wc_price' ) ? (string) wc_price( $min ) : $min );
        }

        /**
         * Price text must be plain, readable Persian before it reaches the JS
         * layer: currency plugins and themes may hand wc_price() back with HTML
         * entities (numeric &#x…; soup or &nbsp;), which — escaped once more by
         * the table renderer — would be printed literally in the cell.
         *
         * @param string $s Raw price markup/text.
         * @return string
         */
        private static function clean_price_text( $s ) {
                $s = html_entity_decode( (string) $s, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
                $s = wp_strip_all_tags( $s );
                $s = str_replace( array( "\xC2\xA0", "\xE2\x80\x87", "\xE2\x80\xAF" ), ' ', $s ); // NBSP variants.
                return trim( preg_replace( '/\s+/u', ' ', $s ) );
        }

        /**
         * 60px thumbnail, or '' when the product has none — the missing case is the
         * content-state signal the admin is filtering on, so it must stay visible.
         *
         * @param int   $id  Product id.
         * @param array $row Lookup row, if the table exists.
         * @return string
         */
        private static function thumb_for( $id, $row ) {
                $tid = isset( $row['thumb_id'] ) ? (int) $row['thumb_id'] : (int) get_post_thumbnail_id( $id );
                if ( ! $tid ) {
                        return '';
                }
                $url = wp_get_attachment_image_url( $tid, 'thumbnail' );
                return $url ? (string) $url : '';
        }

        /**
         * Estimated AI requests a batch will make.
         *
         * One content call per product, and up to two more for the repairs the length
         * pass may ask for (a continuation after a cut-off reply, one expansion of a
         * short text) — the admin sees the worst case before committing. Image
         * generation adds one more call per product.
         *
         * @param int  $count     Products in the batch.
         * @param bool $with_image Whether each product also asks for an image.
         * @return int
         */
        public static function estimate_requests( $count, $with_image = false ) {
                $count = max( 0, (int) $count );
                $per   = 3 + ( $with_image && Haa_Settings::get( 'content.enable_images' ) ? 1 : 0 );
                return $count * $per;
        }

        /* ------------------------------------------------------------------
         * Picker markup
         * ---------------------------------------------------------------- */

        /**
         * The picker shell: category tree, filter bar, product table, selection bar
         * and the batch form. All of it is an empty skeleton — the rows, the tree
         * and the counts arrive over AJAX, so a store with 20,000 products costs
         * this page exactly what a store with 20 costs.
         *
         * @param int $selected Product currently open on the screen (0 = none).
         */
        private function render_product_picker( $selected ) {
                $open = ( $selected && get_post( $selected ) ) ? get_the_title( $selected ) : '';
                $cap  = self::select_cap();
                ?>
                <div class="haai-prodpicker" data-haai-prodpicker data-cap="<?php echo (int) $cap; ?>" data-open="<?php echo (int) $selected; ?>">

                        <?php if ( $open ) : ?>
                        <p class="haai-prodpicker__current">
                                <span class="haai-chip haai-chip--on"><?php echo esc_html( $open . ' (#' . $selected . ')' ); ?></span>
                                <a class="button" href="<?php echo esc_url( Haa_Util::screen_url( 'product-content' ) ); ?>">
                                        <?php esc_html_e( 'بازگشت به فهرست', 'hami-ai-assistant' ); ?>
                                </a>
                        </p>
                        <?php endif; ?>

                        <div class="haai-picker2">
                                <aside class="haai-picker2__side">
                                        <input type="search" class="haai-tree__q" data-haai-tree-q
                                                placeholder="<?php esc_attr_e( 'جستجو در دسته‌ها…', 'hami-ai-assistant' ); ?>"
                                                aria-label="<?php esc_attr_e( 'جستجو در دسته‌ها', 'hami-ai-assistant' ); ?>">

                                        <label class="haai-check">
                                                <input type="checkbox" data-haai-tree-children value="1" checked>
                                                <?php esc_html_e( 'شامل زیردسته‌ها', 'hami-ai-assistant' ); ?>
                                        </label>

                                        <div class="haai-tree" data-haai-tree role="tree"
                                                aria-label="<?php esc_attr_e( 'درخت دسته‌های محصول', 'hami-ai-assistant' ); ?>">
                                                <p class="description"><?php esc_html_e( 'در حال بارگذاری دسته‌ها…', 'hami-ai-assistant' ); ?></p>
                                        </div>
                                </aside>

                                <div class="haai-picker2__main">
                                        <p class="haai-filterbar">
                                                <input type="search" data-haai-pq
                                                        placeholder="<?php esc_attr_e( 'عنوان، SKU یا شناسه‌ی محصول…', 'hami-ai-assistant' ); ?>"
                                                        aria-label="<?php esc_attr_e( 'جستجوی محصول', 'hami-ai-assistant' ); ?>">

                                                <span class="haai-dd" data-haai-dd>
                                                        <button type="button" class="button" data-haai-dd-btn aria-expanded="false" aria-haspopup="true">
                                                                <?php esc_html_e( 'فیلترها', 'hami-ai-assistant' ); ?>
                                                                <span class="haai-dd__n" data-haai-dd-count hidden></span>
                                                        </button>
                                                        <span class="haai-dd__panel" data-haai-dd-panel hidden>
                                                                <span class="haai-dd__group">
                                                                        <strong><?php esc_html_e( 'وضعیت انتشار', 'hami-ai-assistant' ); ?></strong>
                                                                        <select data-haai-pstatus multiple size="4" aria-label="<?php esc_attr_e( 'وضعیت انتشار', 'hami-ai-assistant' ); ?>">
                                                                                <option value="publish"><?php esc_html_e( 'منتشر', 'hami-ai-assistant' ); ?></option>
                                                                                <option value="draft"><?php esc_html_e( 'پیش‌نویس', 'hami-ai-assistant' ); ?></option>
                                                                                <option value="pending"><?php esc_html_e( 'در انتظار', 'hami-ai-assistant' ); ?></option>
                                                                                <option value="private"><?php esc_html_e( 'خصوصی', 'hami-ai-assistant' ); ?></option>
                                                                        </select>
                                                                </span>
                                                                <span class="haai-dd__group">
                                                                        <strong><?php esc_html_e( 'نوع محصول', 'hami-ai-assistant' ); ?></strong>
                                                                        <select data-haai-ptype aria-label="<?php esc_attr_e( 'نوع محصول', 'hami-ai-assistant' ); ?>">
                                                                                <option value=""><?php esc_html_e( 'همه نوع‌ها', 'hami-ai-assistant' ); ?></option>
                                                                                <option value="simple"><?php esc_html_e( 'ساده', 'hami-ai-assistant' ); ?></option>
                                                                                <option value="variable"><?php esc_html_e( 'متغیر', 'hami-ai-assistant' ); ?></option>
                                                                                <option value="grouped"><?php esc_html_e( 'گروهی', 'hami-ai-assistant' ); ?></option>
                                                                                <option value="external"><?php esc_html_e( 'خارجی', 'hami-ai-assistant' ); ?></option>
                                                                        </select>
                                                                </span>
                                                                <span class="haai-dd__group">
                                                                        <strong><?php esc_html_e( 'موجودی', 'hami-ai-assistant' ); ?></strong>
                                                                        <select data-haai-pstock aria-label="<?php esc_attr_e( 'موجودی', 'hami-ai-assistant' ); ?>">
                                                                                <option value=""><?php esc_html_e( 'همه موجودی‌ها', 'hami-ai-assistant' ); ?></option>
                                                                                <option value="instock"><?php esc_html_e( 'موجود', 'hami-ai-assistant' ); ?></option>
                                                                                <option value="outofstock"><?php esc_html_e( 'ناموجود', 'hami-ai-assistant' ); ?></option>
                                                                                <option value="onbackorder"><?php esc_html_e( 'سفارش پشت‌نوبت', 'hami-ai-assistant' ); ?></option>
                                                                        </select>
                                                                </span>
                                                                <span class="haai-dd__group">
                                                                        <strong><?php esc_html_e( 'وضعیت محتوا', 'hami-ai-assistant' ); ?></strong>
                                                                        <select data-haai-pcontent multiple size="3" aria-label="<?php esc_attr_e( 'وضعیت محتوا', 'hami-ai-assistant' ); ?>">
                                                                                <option value="no_excerpt"><?php esc_html_e( 'بدون توضیح کوتاه', 'hami-ai-assistant' ); ?></option>
                                                                                <option value="no_description"><?php esc_html_e( 'بدون توضیح کامل', 'hami-ai-assistant' ); ?></option>
                                                                                <option value="no_thumbnail"><?php esc_html_e( 'بدون تصویر شاخص', 'hami-ai-assistant' ); ?></option>
                                                                        </select>
                                                                </span>
                                                                <button type="button" class="button-link" data-haai-pclear><?php esc_html_e( 'پاک‌کردن فیلترها', 'hami-ai-assistant' ); ?></button>
                                                        </span>
                                                </span>

                                                <button type="button" class="button button-primary" data-haai-pgo><?php esc_html_e( 'اعمال فیلتر', 'hami-ai-assistant' ); ?></button>
                                        </p>

                                        <p class="haai-selectionbar" data-haai-selbar hidden>
                                                <span class="haai-selectionbar__count" data-haai-selcount role="status" aria-live="polite">0</span>
                                                <button type="button" class="button-link" data-haai-selall><?php esc_html_e( 'انتخاب همه‌ی نتایج این فیلتر', 'hami-ai-assistant' ); ?></button>
                                                <button type="button" class="button-link" data-haai-selclear><?php esc_html_e( 'پاک‌کردن انتخاب', 'hami-ai-assistant' ); ?></button>
                                        </p>

                                        <div class="haai-table-scroll">
                                                <table class="widefat striped haai-table haai-prodtable" data-haai-ptable>
                                                        <thead>
                                                                <tr>
                                                                        <th class="haai-col-check">
                                                                                <input type="checkbox" data-haai-pall aria-label="<?php esc_attr_e( 'انتخاب همه‌ی این صفحه', 'hami-ai-assistant' ); ?>">
                                                                        </th>
                                                                        <th><?php esc_html_e( 'تصویر', 'hami-ai-assistant' ); ?></th>
                                                                        <th><?php esc_html_e( 'عنوان', 'hami-ai-assistant' ); ?></th>
                                                                        <th><?php esc_html_e( 'SKU', 'hami-ai-assistant' ); ?></th>
                                                                        <th><?php esc_html_e( 'قیمت', 'hami-ai-assistant' ); ?></th>
                                                                        <th><?php esc_html_e( 'دسته‌ها', 'hami-ai-assistant' ); ?></th>
                                                                        <th><?php esc_html_e( 'محتوا', 'hami-ai-assistant' ); ?></th>
                                                                </tr>
                                                        </thead>
                                                        <tbody data-haai-pbody>
                                                                <tr><td colspan="7"><span class="description"><?php esc_html_e( 'در حال بارگذاری…', 'hami-ai-assistant' ); ?></span></td></tr>
                                                        </tbody>
                                                </table>
                                        </div>

                                        <p class="haai-pager" data-haai-ppager hidden>
                                                <button type="button" class="button" data-haai-pprev><?php esc_html_e( 'قبلی', 'hami-ai-assistant' ); ?></button>
                                                <span class="description" data-haai-pcounter role="status" aria-live="polite"></span>
                                                <button type="button" class="button" data-haai-pnext><?php esc_html_e( 'بعدی', 'hami-ai-assistant' ); ?></button>
                                                <label class="haai-pager__per">
                                                        <span><?php esc_html_e( 'تعداد در صفحه', 'hami-ai-assistant' ); ?></span>
                                                        <select data-haai-pper>
                                                                <?php foreach ( array( self::PER_MIN, 30, self::PER_MAX ) as $n ) : ?>
                                                                        <option value="<?php echo (int) $n; ?>" <?php selected( (int) $n, self::PER_MIN ); ?>><?php echo (int) $n; ?></option>
                                                                <?php endforeach; ?>
                                                        </select>
                                                </label>
                                                <span class="description" data-haai-pinfo aria-live="polite"></span>
                                        </p>
                                </div>
                        </div>

                        <?php $this->render_batch_form(); ?>
                </div>
                <?php
        }

        /**
         * Batch form. The selected ids are written into one hidden field by the
         * picker script — a JSON array, so no 500-field form posts.
         *
         * @return void
         */
        private function render_batch_form() {
                $cap  = self::select_cap();
                $perf = (int) apply_filters( 'haai_queue_batch_size', 3 );
                ?>
                <details class="haai-batch">
                        <summary><?php esc_html_e( 'پردازش دسته‌ای', 'hami-ai-assistant' ); ?></summary>
                        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" data-haai-batch-form>
                                <?php wp_nonce_field( 'haai_pc' ); ?>
                                <input type="hidden" name="action" value="haai_pc_batch">
                                <input type="hidden" name="selected" value="" data-haai-batch-ids>

                                <div class="haai-field">
                                        <label class="haai-field__label" for="haai-batch-keywords"><?php esc_html_e( 'کلیدواژه‌ها', 'hami-ai-assistant' ); ?></label>
                                        <div class="haai-field__control">
                                                <input type="text" class="regular-text" id="haai-batch-keywords" name="keywords" value=""
                                                        placeholder="<?php esc_attr_e( 'جداشده با کاما؛ برای همه‌ی محصولات همین متن', 'hami-ai-assistant' ); ?>">
                                        </div>
                                </div>
                                <div class="haai-field">
                                        <label class="haai-field__label" for="haai-batch-tone"><?php esc_html_e( 'لحن', 'hami-ai-assistant' ); ?></label>
                                        <div class="haai-field__control">
                                                <select id="haai-batch-tone" name="tone">
                                                        <?php foreach ( Haa_Util::tones() as $slug => $label ) : ?>
                                                                <option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $slug, Haa_Settings::get( 'content.default_tone' ) ); ?>><?php echo esc_html( $label ); ?></option>
                                                        <?php endforeach; ?>
                                                </select>
                                        </div>
                                </div>
                                <div class="haai-field">
                                        <label class="haai-field__label" for="haai-batch-length"><?php esc_html_e( 'طول', 'hami-ai-assistant' ); ?></label>
                                        <div class="haai-field__control">
                                                <select id="haai-batch-length" name="length">
                                                        <?php foreach ( self::length_choices() as $slug => $label ) : ?>
                                                                <option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $slug, self::default_length() ); ?>><?php echo esc_html( $label ); ?></option>
                                                        <?php endforeach; ?>
                                                </select>
                                                <p class="description"><?php esc_html_e( 'برای محصول، داده‌ی کم = متن پرحشو؛ سقف این فهرست حدود ۱۲۰۰ کلمه است.', 'hami-ai-assistant' ); ?></p>
                                        </div>
                                </div>
                                <?php if ( Haa_Settings::get( 'content.enable_images' ) ) : ?>
                                <div class="haai-field">
                                        <div class="haai-field__control">
                                                <label class="haai-checkbox"><input type="checkbox" name="with_image" value="1"> <?php esc_html_e( 'برای هر محصول تصویر هم بساز (هر محصول یک درخواست بیشتر)', 'hami-ai-assistant' ); ?></label>
                                        </div>
                                </div>
                                <?php endif; ?>

                                <p class="haai-batch__estimate description" data-haai-batch-estimate><?php
                                        printf(
                                                /* translators: 1: request cap per selection, 2: queue batch size. */
                                                esc_html__( 'سقف انتخاب هم‌زمان: %1$d محصول. صف در هر اجرا %2$d کار را انجام می‌دهد.', 'hami-ai-assistant' ),
                                                (int) $cap,
                                                (int) $perf
                                        );
                                ?></p>

                                <p class="haai-batch__actions">
                                        <button type="submit" class="button button-primary" data-haai-batch-submit disabled>
                                                <?php esc_html_e( 'افزودن انتخاب‌ها به صف', 'hami-ai-assistant' ); ?>
                                        </button>
                                </p>
                        </form>
                </details>
                <?php
        }

        /* ------------------------------------------------------------------
         * Handlers
         * ---------------------------------------------------------------- */

        /**
         * Length options offered for a product page.
         *
         * «خیلی بلند» stays out on purpose: 2 200 words belongs to an article. With
         * the handful of facts a product row carries, asking for that many words is
         * how you get filler, so the top of this list is about 1 200 words.
         *
         * @return array<string,string> slug => label.
         */
        public static function length_choices() {
                $out = array();
                // A product page cannot carry article-length texts: the deep/max tiers
                // (3 000 / 4 500 words) are article-writer-only.
                $blocked = array( 'xlong', 'deep', 'max' );
                foreach ( Haa_Util::lengths() as $slug => $label ) {
                        if ( in_array( $slug, $blocked, true ) ) {
                                continue;
                        }
                        $out[ $slug ] = ( 'long' === $slug ) ? __( 'بلند (~۱۲۰۰ کلمه)', 'hami-ai-assistant' ) : $label;
                }
                return $out;
        }

        /**
         * Clamp any length slug to what a product page can carry.
         *
         * «خیلی بلند» is a legitimate article default and its saved value is left
         * alone; a product form just starts one notch lower.
         *
         * @param string $slug Saved or posted slug.
         * @return string A slug in length_choices().
         */
        public static function length_slug( $slug ) {
                if ( in_array( (string) $slug, array( 'xlong', 'deep', 'max' ), true ) ) {
                        return 'long';
                }
                return isset( self::length_choices()[ $slug ] ) ? $slug : 'medium';
        }

        /**
         * The product form's default length (never «خیلی بلند»).
         *
         * @return string
         */
        public static function default_length() {
                return self::length_slug( (string) Haa_Settings::get( 'content.default_length', 'medium' ) );
        }

        /**
         * Word budget of a length slug, capped for products.
         *
         * @param string $slug Length slug.
         * @return int
         */
        public static function length_words( $slug ) {
                $slug = self::length_slug( $slug );
                // The product cap: 1 200 words, not the shared 1 400 or 2 200.
                return 'long' === $slug ? 1200 : (int) Haa_Util::length_words( $slug );
        }

        /**
         * One product brief: facts in, preview-ready content out.
         *
         * Shared by the single-product button and by every queued batch job, so a
         * batch can never produce something the manual path would not.
         *
         * @param int   $pid  Product id.
         * @param array $args tone, length, keywords.
         * @return array{ok:bool,error?:string,html?:string,…}
         */
        public static function generate_brief( $pid, $args = array() ) {
                $args = wp_parse_args(
                        $args,
                        array(
                                'tone'     => Haa_Settings::get( 'content.default_tone' ),
                                'length'   => Haa_Settings::get( 'content.default_length' ),
                                'keywords' => '',
                        )
                );

                // A length saved before this list existed (or by the article settings) is
                // clamped to what a product page can carry.
                $args['length'] = self::length_slug( (string) $args['length'] );
                $args['words']  = self::length_words( $args['length'] );

                if ( ! $pid || ! get_post( $pid ) ) {
                        return array(
                                'ok'    => false,
                                'error' => __( 'محصول پیدا نشد.', 'hami-ai-assistant' ),
                        );
                }

                $data_notes = array();
                $facts      = Haa_Content::product_facts( $pid, $data_notes );

                if ( '' === trim( (string) $args['keywords'] ) ) {
                        // بند «ح»: no keyword is not the same as no focus. Take the product name
                        // plus the category it does not already contain, and show it in the
                        // preview so the admin can overrule it with their own wording.
                        $args['keywords'] = self::derived_keyword( $facts );
                }

                $brief = Haa_Content::product_brief(
                        get_the_title( $pid ),
                        $facts,
                        array(
                                'tone'     => $args['tone'],
                                'length'   => $args['length'],
                                'words'    => $args['words'],
                                'keywords' => $args['keywords'],
                        ),
                        array( 'module' => 'product-content', 'operation' => 'product-content' )
                );

                if ( empty( $brief['ok'] ) ) {
                        return $brief;
                }

                $brief['keywords'] = (string) $args['keywords'];

                // Whatever the data itself is short about (demo description, unusable
                // attribute) joins the model's own notes; the panel shows both.
                $brief['notes'] = Haa_Content::merge_notes(
                        isset( $brief['notes'] ) && is_array( $brief['notes'] ) ? $brief['notes'] : array(),
                        (array) $data_notes
                );

                $links = Haa_Content::internal_links( $pid, $args['keywords'] );
                $label = __( 'مقاله‌های مرتبط', 'hami-ai-assistant' );
                if ( ! $links && ! empty( Haa_Settings::get( 'content.internal_links' ) ) && haa_is_woo() && function_exists( 'wc_get_products' ) ) {
                        // Nothing above the relevance floor in the index: offer siblings from
                        // the same category instead, and say what they are. An empty category
                        // means no block at all — never a filler link.
                        $cats  = wp_get_post_terms( $pid, 'product_cat', array( 'fields' => 'slugs' ) );
                        $label = __( 'محصولات مرتبط', 'hami-ai-assistant' );
                        if ( $cats && ! is_wp_error( $cats ) ) {
                                foreach ( wc_get_products(
                                        array(
                                                'status'   => 'publish',
                                                'limit'    => 3,
                                                'exclude'  => array( (int) $pid ),
                                                'category' => array_map( 'strval', (array) $cats ),
                                                'orderby'  => 'ID',
                                                'order'    => 'ASC',
                                        )
                                ) as $sib ) {
                                        $links[] = array(
                                                'id'    => (int) $sib->get_id(),
                                                'title' => (string) $sib->get_name(),
                                                'url'   => (string) get_permalink( $sib->get_id() ),
                                        );
                                }
                        }
                }

                if ( $links && ! empty( Haa_Settings::get( 'content.internal_links' ) ) ) {
                        $brief['html'] .= "\n\n<h2>" . esc_html( $label ) . "</h2>\n<ul>";
                        foreach ( $links as $l ) {
                                $brief['html'] .= '<li><a href="' . esc_url( $l['url'] ) . '">' . esc_html( $l['title'] ) . '</a></li>';
                        }
                        $brief['html'] .= "</ul>\n";
                }

                return $brief;
        }

        /** @return void */
        public function handle_generate() {
                Haa_Util::guard_admin_post( 'haai_pc' );

                $pid = isset( $_POST['product_id'] ) ? absint( $_POST['product_id'] ) : 0;

                // P3: one generation per product at a time. A second, concurrent click
                // is refused with a plain Persian notice instead of paying the model
                // twice for the same result.
                if ( ! self::acquire_generate_lock( $pid ) ) {
                        $this->back(
                                $pid,
                                __( 'ساخت پیش‌نمایش همین محصول هم‌اکنون در حال انجام است. چند لحظه صبر کنید و سپس دوباره بزنید.', 'hami-ai-assistant' )
                        );
                }

                // A single click can take tens of seconds; never let the proxy kill it.
                if ( function_exists( 'set_time_limit' ) ) {
                        @set_time_limit( 300 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
                }

                $brief = self::generate_brief(
                        $pid,
                        array(
                                'tone'     => isset( $_POST['tone'] ) ? sanitize_key( wp_unslash( $_POST['tone'] ) ) : '',
                                'length'   => isset( $_POST['length'] ) ? sanitize_key( wp_unslash( $_POST['length'] ) ) : '',
                                'keywords' => isset( $_POST['keywords'] ) ? sanitize_text_field( wp_unslash( $_POST['keywords'] ) ) : '',
                                // A browser request is behind a proxy that gives up around 30 s: the
                                // first call gets 18 of them, and only the rest fits a repair call.
                                'deadline' => 18,
                        )
                );

                // The run is over on every path below — free the lock before answering.
                self::release_generate_lock( $pid );

                if ( empty( $brief['ok'] ) ) {
                        $this->back( $pid, $brief['error'] ?? __( 'ساخت محتوا ناموفق بود.', 'hami-ai-assistant' ) );
                }

                set_transient( self::PREVIEW . $pid, $brief, HOUR_IN_SECONDS );
                set_transient( 'haai_pc_last_product', $pid, DAY_IN_SECONDS );

                $this->back( $pid );
        }

        /* ------------------------------------------------------------------
         * Generate lock (P3) and apply marker (P4)
         * ---------------------------------------------------------------- */

        /**
         * Is a generation already running for this product?
         *
         * @param int $pid Product id.
         * @return bool
         */
        public static function generate_locked( $pid ) {
                return (bool) $pid && false !== get_transient( self::GEN_LOCK . (int) $pid );
        }

        /**
         * Take the per-product generation lock.
         *
         * A product id of 0 cannot be locked meaningfully, so it is refused the
         * same way a concurrent run would be.
         *
         * @param int $pid Product id.
         * @return bool True when the caller may proceed.
         */
        public static function acquire_generate_lock( $pid ) {
                $pid = (int) $pid;
                if ( ! $pid ) {
                        return false;
                }
                if ( self::generate_locked( $pid ) ) {
                        return false;
                }
                set_transient( self::GEN_LOCK . $pid, time(), self::GEN_LOCK_TTL );
                return true;
        }

        /**
         * @param int $pid Product id.
         * @return void
         */
        public static function release_generate_lock( $pid ) {
                if ( (int) $pid ) {
                        delete_transient( self::GEN_LOCK . (int) $pid );
                }
        }

        /**
         * Remember that content was just written to this product (P4).
         *
         * @param int $pid Product id.
         * @return void
         */
        public static function mark_applied( $pid ) {
                if ( (int) $pid ) {
                        set_transient( self::APPLIED . (int) $pid, time(), self::APPLIED_TTL );
                }
        }

        /**
         * Was this product written to very recently?
         *
         * Used to answer a second «اعمال» (double click, back button, refresh) with
         * a calm success message instead of a red error for something that already
         * worked.
         *
         * @param int $pid Product id.
         * @return bool
         */
        public static function applied_recently( $pid ) {
                return (bool) $pid && false !== get_transient( self::APPLIED . (int) $pid );
        }

        /**
         * Queue one content job per selected product.
         *
         * Nothing is generated during this request: a batch of 500 products would
         * otherwise run 500 model calls behind one submit and die at the proxy
         * timeout. The queue drains them in bounded cron runs instead.
         *
         * @return void
         */
        public function handle_batch() {
                Haa_Util::guard_admin_post( 'haai_pc' );

                $raw      = isset( $_POST['selected'] ) ? wp_unslash( $_POST['selected'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
                $ids      = is_string( $raw ) ? json_decode( $raw, true ) : array();
                $ids      = array_values( array_unique( array_filter( array_map( 'absint', (array) $ids ) ) ) );
                $cap      = self::select_cap();
                $with_img = ! empty( $_POST['with_image'] ) && Haa_Settings::get( 'content.enable_images' );

                if ( ! $ids ) {
                        $this->back( 0, __( 'محصولی انتخاب نشده است.', 'hami-ai-assistant' ) );
                }
                if ( count( $ids ) > $cap ) {
                        $this->back( 0, sprintf( /* translators: %d: cap. */ __( 'سقف هر دسته %d محصول است.', 'hami-ai-assistant' ), $cap ) );
                }

                $args = array(
                        'tone'     => isset( $_POST['tone'] ) ? sanitize_key( wp_unslash( $_POST['tone'] ) ) : '',
                        'length'   => isset( $_POST['length'] ) ? sanitize_key( wp_unslash( $_POST['length'] ) ) : '',
                        'keywords' => isset( $_POST['keywords'] ) ? sanitize_text_field( wp_unslash( $_POST['keywords'] ) ) : '',
                );

                $queued = 0;
                foreach ( $ids as $pid ) {
                        $job = Haa_Queue::push(
                                'pc_content',
                                array_merge( $args, array(
                                        'product_id' => $pid,
                                        'with_image' => $with_img ? 1 : 0,
                                ) ),
                                array( 'module' => 'product-content' )
                        );
                        if ( $job ) {
                                $queued++;
                        }
                }

                $this->back(
                        0,
                        '',
                        sprintf(
                                /* translators: 1: queued products, 2: estimated model calls. */
                                __( '%1$d محصول در صف قرار گرفت؛ تخمین درخواست‌ها: %2$d.', 'hami-ai-assistant' ),
                                $queued,
                                self::estimate_requests( $queued, $with_img )
                        )
                );
        }

        /**
         * Queue listener: build one product brief and store it as its preview.
         *
         * It stops at the preview on purpose. Publishing is still the human decision
         * the single-product screen makes with «اعمال روی محصول».
         *
         * @param array $job     Queue row.
         * @param array $payload Decoded payload.
         * @return void
         */
        public function run_content_job( $job, $payload ) {
                $pid = isset( $payload['product_id'] ) ? absint( $payload['product_id'] ) : 0;

                // BL-06: the queue takes the same per-product lock as the manual click, so
                // one product can never be paid for twice from two paths. A refusal is a
                // retry (`max_attempts`), not a lost product.
                if ( ! self::acquire_generate_lock( $pid ) ) {
                        $GLOBALS['haai_job_result'] = new WP_Error(
                                'haai_pc_busy',
                                __( 'ساخت پیش‌نمایش همین محصول هم‌اکنون در حال انجام است؛ این کار کمی بعد دوباره امتحان می‌شود.', 'hami-ai-assistant' )
                        );
                        return;
                }

                $brief = self::generate_brief(
                        $pid,
                        array(
                                'tone'     => $payload['tone'] ?? '',
                                'length'   => $payload['length'] ?? '',
                                'keywords' => $payload['keywords'] ?? '',
                                // BL-12: a cron tick has no browser proxy waiting, but a shared host
                                // still cuts PHP off. Keep one job inside one full call plus slack,
                                // so a slow host never leaves the drain half-done.
                                'deadline' => 40,
                        )
                );

                if ( empty( $brief['ok'] ) ) {
                        self::release_generate_lock( $pid );
                        $GLOBALS['haai_job_result'] = new WP_Error( 'haai_pc_job', (string) ( $brief['error'] ?? __( 'ساخت محتوا ناموفق بود.', 'hami-ai-assistant' ) ) );
                        return;
                }

                set_transient( self::PREVIEW . $pid, $brief, HOUR_IN_SECONDS );

                if ( ! empty( $payload['with_image'] ) ) {
                        $image = self::make_image( $pid );
                        self::release_generate_lock( $pid );
                        if ( empty( $image['ok'] ) ) {
                                // The text is already stored; say which half failed.
                                $GLOBALS['haai_job_result'] = new WP_Error( 'haai_pc_image', (string) $image['error'] );
                        }
                        return;
                }

                self::release_generate_lock( $pid );
        }

        /**
         * Generate one product image and set it as the thumbnail.
         *
         * Shared by the manual button and the queue, so a batch image is prompted
         * from exactly the same facts as a hand-run one.
         *
         * @param int $pid Product id.
         * @return array{ok:bool,error?:string}
         */
        public static function make_image( $pid ) {
                if ( ! Haa_Settings::get( 'content.enable_images' ) ) {
                        return array(
                                'ok'    => false,
                                'error' => __( 'تولید تصویر در تنظیمات خاموش است.', 'hami-ai-assistant' ),
                        );
                }

                $facts   = Haa_Content::product_facts( $pid );
                $name    = $facts['نام'] ?? get_the_title( $pid );
                $cat     = $facts['دسته‌بندی'] ?? '';
                $prompt  = Haa_Images::product_prompt( $name, $cat );
                $preview = get_transient( self::PREVIEW . $pid );
                if ( is_array( $preview ) && ! empty( $preview['seo_description'] ) ) {
                        $prompt .= '. ' . Haa_Util::cut( wp_strip_all_tags( $preview['seo_description'] ), 160 );
                }

                $result = Haa_Images::generate(
                        $prompt,
                        array(
                                'module'  => 'product-content',
                                'post_id' => $pid,
                        )
                );

                if ( empty( $result['ok'] ) ) {
                        return array(
                                'ok'    => false,
                                'error' => (string) ( $result['error'] ?? __( 'تصویر ساخته نشد.', 'hami-ai-assistant' ) ),
                        );
                }

                set_post_thumbnail( $pid, (int) $result['attachment_id'] );

                return array( 'ok' => true );
        }

        /**
         * Fold the edited preview fields from the apply form over the stored brief.
         *
         * The screen posts seo_title / seo_description / slug / html / faq back;
         * anything missing keeps its model value, and anything empty is dropped so
         * «اعمال» never writes a blank over good content.
         *
         * @param array $preview Stored brief.
         * @return array Brief with the human's edits applied.
         */
        public static function merge_edits( $preview ) {
                if ( isset( $_POST['seo_title'] ) ) {
                        $title = trim( (string) sanitize_text_field( wp_unslash( $_POST['seo_title'] ) ) );
                        if ( '' !== $title ) {
                                $preview['seo_title'] = Haa_Util::cut( $title, 80 );
                        }
                }

                if ( isset( $_POST['seo_description'] ) ) {
                        $desc = trim( (string) sanitize_textarea_field( wp_unslash( $_POST['seo_description'] ) ) );
                        if ( '' !== $desc ) {
                                $preview['seo_description'] = Haa_Util::cut( $desc, 200 );
                        }
                }

                // An emptied slug field means "keep the product's current slug": the
                // brief's slug is cleared so Haa_Seo::write() skips post_name
                // entirely — the URL never changes, so no redirect is needed.
                if ( isset( $_POST['slug'] ) ) {
                        $slug = Haa_Util::slugify( (string) wp_unslash( $_POST['slug'] ) );
                        $preview['slug'] = $slug;
                }

                if ( isset( $_POST['html'] ) ) {
                        $html = (string) wp_unslash( $_POST['html'] );
                        if ( '' !== trim( $html ) ) {
                                $preview['html'] = $html;
                        }
                }

                // FAQ: the editor posts faq[i][q] / faq[i][a]. Builds before this one
                // posted faq[][q] / faq[][a], where PHP turns every field into its own
                // row — those are paired back up inside faq_from_post(). A posted
                // fieldset with no rows at all means «every question was removed».
                if ( isset( $_POST['faq'] ) || isset( $_POST['faq_present'] ) ) {
                        $raw_faq        = isset( $_POST['faq'] ) ? (array) wp_unslash( $_POST['faq'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
                        $preview['faq'] = self::faq_from_post( $raw_faq );
                }

                return self::clean_notes( $preview );
        }

        /**
         * A focus keyword for a product with none given: name, plus the category the
         * name does not already say.
         *
         * @param array $facts Facts from Haa_Content::product_facts().
         * @return string
         */
        public static function derived_keyword( $facts ) {
                $name = trim( (string) ( isset( $facts['نام'] ) ? $facts['نام'] : '' ) );
                $cat  = trim( (string) ( isset( $facts['دسته‌بندی'] ) ? $facts['دسته‌بندی'] : '' ) );
                // The deepest term reads better as a keyword than the whole path.
                $terms  = array_filter( array_map( 'trim', explode( '،', $cat ) ) );
                $cat    = $terms ? (string) end( $terms ) : '';
                $phrase = $name;
                if ( '' !== $cat && false === mb_stripos( $name, $cat, 0, 'UTF-8' ) ) {
                        $phrase = trim( $name . ' ' . $cat );
                }
                return (string) Haa_Util::cut( $phrase, 80 );
        }

        /**
         * The open review notes of a preview.
         *
         * @param array $preview Stored brief.
         * @return array<int,string>
         */
        public static function notes_of( $preview ) {
                $out = array();
                foreach ( (array) ( isset( $preview['notes'] ) ? $preview['notes'] : array() ) as $note ) {
                        $note = trim( (string) $note );
                        if ( '' !== $note ) {
                                $out[] = $note;
                        }
                }
                return $out;
        }

        /**
         * Move every note out of the fields that are about to be written.
         *
         * The posted HTML is the admin's own text, so a note found here was pasted
         * (or came from a preview built before notes were split). It is moved into the
         * note list rather than deleted: the reviewer still sees it, the page never
         * gets it.
         *
         * @param array $preview Brief with the human's edits folded in.
         * @return array Brief whose publishable fields are clean.
         */
        public static function clean_notes( $preview ) {
                // The rules live next to the note pattern, so every writer behaves the same.
                return Haa_Content::clean_notes( $preview );
        }

        /**
         * Turn posted FAQ rows into question/answer pairs.
         *
         * Accepts the indexed shape (faq[0][q] + faq[0][a]) and the legacy flat
         * shape (faq[][q] + faq[][a], one row per field) by pairing a lone
         * question with the next lone answer. A row with only one half — or two
         * empty halves — is dropped, and the list is capped at 12.
         *
         * @param array $raw Posted rows.
         * @return array<int,array{q:string,a:string}>
         */
        private static function faq_from_post( $raw ) {
                $rows    = array();
                $pending = null;

                foreach ( (array) $raw as $row ) {
                        if ( ! is_array( $row ) ) {
                                continue;
                        }
                        $q = isset( $row['q'] ) ? trim( (string) sanitize_text_field( $row['q'] ) ) : '';
                        $a = isset( $row['a'] ) ? trim( (string) sanitize_textarea_field( $row['a'] ) ) : '';

                        if ( '' !== $q && '' !== $a ) {
                                $rows[]  = array( 'q' => $q, 'a' => $a );
                                $pending = null;
                                continue;
                        }
                        if ( '' !== $q ) {
                                $pending = $q; // The matching answer row comes next in the flat shape.
                                continue;
                        }
                        if ( '' !== $a && null !== $pending ) {
                                $rows[]  = array( 'q' => $pending, 'a' => $a );
                                $pending = null;
                        }
                }

                return array_slice( $rows, 0, 12 );
        }

        /** @return void */
        public function handle_apply() {
                Haa_Util::guard_admin_post( 'haai_pc' );

                $pid     = isset( $_POST['product_id'] ) ? absint( $_POST['product_id'] ) : 0;
                $preview = $pid ? get_transient( self::PREVIEW . $pid ) : false;
                if ( ! is_array( $preview ) ) {
                        // P4: a second click, a refresh or the back button posts the apply
                        // form again after the preview was already consumed. That is not an
                        // error — the content is on the product — so say so calmly instead
                        // of shouting a red notice at a successful operation.
                        if ( self::applied_recently( $pid ) ) {
                                $this->back(
                                        $pid,
                                        '',
                                        __( 'این محتوا همین حالا روی محصول اعمال شده است؛ پیش‌نمایش دیگری باقی نمانده است.', 'hami-ai-assistant' )
                                );
                        }
                        $this->back( $pid, __( 'پیش‌نمایشی برای اعمال نیست. ابتدا پیش‌نمایش را بسازید.', 'hami-ai-assistant' ) );
                }

                // The tick covers the notes the admin was actually shown. A note discovered
                // only now (pasted into the HTML editor, or added by an edit) has to be
                // acknowledged on its own turn.
                $known_notes = count( self::notes_of( $preview ) );

                // The human edits win over the model — every preview field is editable
                // and whatever is posted here is what gets applied.
                $preview = self::merge_edits( $preview );

                // Store what the admin just typed back into the preview. Both refusals
                // below land on the screen again, and losing the edits (or the notes that
                // were only just discovered) would make the retry a re-typing job.
                set_transient( self::PREVIEW . $pid, $preview, HOUR_IN_SECONDS );

                // بند «ب»: open notes mean the page would go out with a question still
                // unanswered, so the reviewer has to confirm they read them.
                $open = self::notes_of( $preview );
                if ( $open && empty( $_POST['notes_seen'] ) ) {
                        $this->back(
                                $pid,
                                sprintf(
                                        /* translators: %d: number of open notes. */
                                        __( 'این پیش‌نمایش %d یادداشت بازبینی دارد. پیش از اعمال، یادداشت‌ها را بخوانید و تیک «یادداشت‌ها را دیدم» را بزنید. پیش‌نمایش هم حفظ شده است.', 'hami-ai-assistant' ),
                                        count( $open )
                                )
                        );
                }

                if ( count( $open ) > $known_notes ) {
                        $this->back(
                                $pid,
                                __( 'یک یادداشت تازه داخل متنِ ویرایش‌شده پیدا شد (شاید خودتان چسبانده باشید). آن را از متن برداشتیم و به «یادداشت‌های بازبینی» افزودیم؛ متن هنوز نوشته نشده — تیک را بزنید و دوباره اعمال کنید.', 'hami-ai-assistant' )
                        );
                }

                $applied = Haa_Seo::apply( $pid, $preview, true );

                if ( ! empty( $applied['blocked'] ) ) {
                        // A note survived every filter. Nothing was written and the preview is
                        // kept, so the admin can fix the text and apply again.
                        $this->back( $pid, __( 'محتوا اعمال نشد: هنوز یادداشت بازبینی داخل متن، سؤالات متداول یا توضیح متا هست. یادداشت را بردارید یا پیش‌نمایش را دوباره بسازید.', 'hami-ai-assistant' ) );
                }

                // A product's short description doubles as the meta description.
                if ( ! empty( $preview['seo_description'] ) && haa_is_woo() ) {
                        $p = wc_get_product( $pid );
                        if ( $p ) {
                                $p->set_short_description( Haa_Util::cut( wp_strip_all_tags( $preview['seo_description'] ), 500 ) );
                                $p->save();
                        }
                }

                delete_transient( self::PREVIEW . $pid );
                self::mark_applied( $pid );

                $this->back( $pid, '', $this->applied_message( $applied ) );
        }

        /** @return void */
        public function handle_image() {
                Haa_Util::guard_admin_post( 'haai_pc' );

                $pid = isset( $_POST['product_id'] ) ? absint( $_POST['product_id'] ) : 0;
                if ( ! $pid || ! get_post( $pid ) ) {
                        $this->back( 0, __( 'محصول پیدا نشد.', 'hami-ai-assistant' ) );
                }

                if ( function_exists( 'set_time_limit' ) ) {
                        @set_time_limit( 300 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
                }

                $image = self::make_image( $pid );
                if ( empty( $image['ok'] ) ) {
                        $this->back( $pid, $image['error'] );
                }

                $this->back( $pid, '', __( 'تصویر ساخته و به‌عنوان تصویر شاخص محصول تنظیم شد.', 'hami-ai-assistant' ) );
        }

        /** @return void */
        public function handle_dismiss() {
                Haa_Util::guard_admin_post( 'haai_pc' );
                $pid = isset( $_POST['product_id'] ) ? absint( $_POST['product_id'] ) : 0;
                if ( $pid ) {
                        delete_transient( self::PREVIEW . $pid );
                }
                $this->back( $pid );
        }

        /**
         * @param array $applied Flags from Haa_Seo::apply().
         * @return string
         */
        private function applied_message( $applied ) {
                $parts = array();
                if ( ! empty( $applied['body'] ) ) {
                        $parts[] = __( 'متن', 'hami-ai-assistant' );
                }
                if ( ! empty( $applied['title'] ) ) {
                        $parts[] = __( 'عنوان', 'hami-ai-assistant' );
                }
                if ( ! empty( $applied['slug'] ) ) {
                        $parts[] = __( 'اسلگ', 'hami-ai-assistant' );
                }
                if ( ! empty( $applied['yoast'] ) ) {
                        $parts[] = 'Yoast';
                }
                if ( ! empty( $applied['rank'] ) ) {
                        $parts[] = 'Rank Math';
                }
                if ( ! empty( $applied['aioseo'] ) ) {
                        $parts[] = 'AIOSEO';
                }
                if ( ! $parts ) {
                        return __( 'چیزی اعمال نشد.', 'hami-ai-assistant' );
                }
                return sprintf(
                        /* translators: %s: comma-separated list of what was written. */
                        __( 'اعمال شد: %s', 'hami-ai-assistant' ),
                        implode( '، ', $parts )
                );
        }

        /**
         * Return to the module screen after an action (POST → Redirect → GET).
         *
         * The target comes from the central slug map: this helper used to build
         * 'haai-' . $screen with short keys like 'product', which pointed at a
         * screen that was never registered and surfaced as a permission error
         * even though the work itself had finished.
         *
         * @param int    $pid   Product id.
         * @param string $error Error message ("" when none).
         * @param string $ok    Success message.
         */
        private function back( $pid = 0, $error = '', $ok = '' ) {
                $args = array();
                if ( $pid ) {
                        $args['product'] = $pid;
                }
                if ( '' !== $error ) {
                        $args['haai_err'] = rawurlencode( Haa_Util::cut( $error, 300 ) );
                }
                if ( '' !== $ok ) {
                        $args['haai_msg'] = rawurlencode( Haa_Util::cut( $ok, 300 ) );
                }
                if ( Haa_Ajax_Form::respond( '' !== $error ? $error : $ok, '' !== $error ) ) {
                        return;
                }
                wp_safe_redirect( Haa_Util::screen_url( 'product-content', $args ) );
                exit;
        }
}
