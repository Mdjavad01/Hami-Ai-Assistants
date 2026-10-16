<?php
/**
 * Module: Chatbot — the floating assistant that answers from the site's own
 * content, captures leads and survives page caching.
 *
 * Frontend assets, the REST surface and the chat pipeline live in core (Haa_Chat,
 * Haa_REST, Haa_Search) so they never load for a site that switches this module
 * off. This file owns only the chatbot-specific settings section, the sessions
 * table and the "restart" / history-retention behaviour.
 *
 * @package Hami_AI_Assistant
 */

if ( ! defined( 'ABSPATH' ) ) {
        exit;
}

final class Haa_Module_Chatbot implements Haa_Module_Interface {

        /** @return string */
        public function slug() {
                return 'chatbot';
        }

        /** @return string */
        public function label() {
                return __( 'چت‌بات فروش', 'hami-ai-assistant' );
        }

        /** @return string */
        public function description() {
                return __( 'دستیار گفتگوی شناور که از محتوای سایت جواب می‌دهد، لید جمع می‌کند و بدون توجه به کش صفحات کار می‌کند.', 'hami-ai-assistant' );
        }

        /** @return bool */
        public function requires_woocommerce() {
                // Single source of truth: Haa_Modules::catalog_meta()[ 'woo' ].
                return Haa_Modules::requires_woocommerce( $this->slug() );
        }

        /** @return void */
        public function register() {
                add_action( 'haai_settings_sections', array( $this, 'register_section' ), 10 );
                add_action( 'haai_settings_fields_chat', array( $this, 'register_fields' ), 10 );
        }

        /** @return void */
        public function activate() {
                Haa_DB::create();
        }

        /** @return void */
        public function deactivate() {
                // Conversations are data: uninstall.php decides whether they go.
                delete_transient( 'haai_chat_preview' );
        }

        /**
         * Declares the "Chatbot" and "Conversations" tabs. Registered only when
         * the module is on, so a disabled chatbot leaves no orphan tab behind.
         *
         * @param array $sections Tabs collected so far.
         * @return array
         */
        public function register_section( $sections ) {
                $sections['chat']           = __( 'چت‌بات', 'hami-ai-assistant' );
                $sections['conversations']  = __( 'گفتگوها', 'hami-ai-assistant' );
                return $sections;
        }

        /**
         * Fields shown on the Chatbot tab.
         *
         * @param array $fields Fields collected so far.
         * @return array
         */
        public function register_fields( $fields ) {
                $fields[] = array(
                        'key'   => 'chat.system_prompt',
                        'type'  => 'textarea',
                        'label' => __( 'دستور سیستمی', 'hami-ai-assistant' ),
                        'hint'  => __( 'شخصیت و قوانین پاسخ‌دهی. کوتاه، فارسی و بدون ادعای ساختگی بنویسید.', 'hami-ai-assistant' ),
                );
                $fields[] = array(
                        'key'   => 'chat.max_history',
                        'type'  => 'number',
                        'label' => __( 'حداکثر نوبت‌های گفتگو', 'hami-ai-assistant' ),
                        'min'   => 0,
                        'max'   => 20,
                );
                $fields[] = array(
                        'key'   => 'chat.context_chunks',
                        'type'  => 'number',
                        'label' => __( 'تعداد بخش‌های اطلاعاتی', 'hami-ai-assistant' ),
                        'min'   => 1,
                        'max'   => 20,
                );
                $fields[] = array(
                        'key'   => 'chat.context_max_chars',
                        'type'  => 'number',
                        'label' => __( 'حداکثر کاراکتر اطلاعات', 'hami-ai-assistant' ),
                        'min'   => 200,
                        'max'   => 8000,
                );
                $fields[] = array(
                        'key'   => 'chat.suggested_questions',
                        'type'  => 'textarea',
                        'label' => __( 'پرسش‌های پیشنهادی', 'hami-ai-assistant' ),
                        'hint'  => __( 'هر سؤال در یک خط.', 'hami-ai-assistant' ),
                );
                $fields[] = array(
                        'key'   => 'chat.contact_whatsapp',
                        'type'  => 'text',
                        'label' => __( 'راه ارتباطی: واتساپ', 'hami-ai-assistant' ),
                        'hint'  => __( 'لینک کامل، مثل https://wa.me/989123456789 — وقتی پر باشد، بات برای «اتصال به پشتیبانی واتساپ» همین لینک را می‌دهد.', 'hami-ai-assistant' ),
                );
                $fields[] = array(
                        'key'   => 'chat.contact_telegram',
                        'type'  => 'text',
                        'label' => __( 'راه ارتباطی: تلگرام', 'hami-ai-assistant' ),
                        'hint'  => __( 'لینک کامل، مثل https://t.me/username', 'hami-ai-assistant' ),
                );
                $fields[] = array(
                        'key'   => 'chat.contact_phone',
                        'type'  => 'text',
                        'label' => __( 'راه ارتباطی: شماره تماس', 'hami-ai-assistant' ),
                        'hint'  => __( 'مثل 02112345678 یا 09121234567', 'hami-ai-assistant' ),
                );
                $fields[] = array(
                        'key'   => 'chat.contact_page',
                        'type'  => 'text',
                        'label' => __( 'راه ارتباطی: صفحه «تماس با ما»', 'hami-ai-assistant' ),
                        'hint'  => __( 'آدرس کامل صفحه تماس؛ با پر کردن این فیلد، بات لینک صفحه را در پاسخ می‌آورد.', 'hami-ai-assistant' ),
                );
                $fields[] = array(
                        'key'   => 'chat.contact_note',
                        'type'  => 'textarea',
                        'label' => __( 'نکات تکمیلی پشتیبانی', 'hami-ai-assistant' ),
                        'hint'  => __( 'اختیاری — مثل ساعات کاری یا آدرس؛ به پاسخ‌های راه ارتباطی اضافه می‌شود.', 'hami-ai-assistant' ),
                );
                $fields[] = array(
                        'key'   => 'chat.error_message',
                        'type'  => 'text',
                        'label' => __( 'پیام خطا', 'hami-ai-assistant' ),
                );
                $fields[] = array(
                        'key'   => 'chat.typing_indicator',
                        'type'  => 'checkbox',
                        'label' => __( 'نمایش نشانگر تایپ', 'hami-ai-assistant' ),
                        'hint'  => __( 'هنگام پردازش پاسخ، انیمیشن «در حال تایپ» نشان داده شود.', 'hami-ai-assistant' ),
                );
                $fields[] = array(
                        'key'   => 'chat.offline_enabled',
                        'type'  => 'checkbox',
                        'label' => __( 'حالت خارج از ساعات کاری', 'hami-ai-assistant' ),
                );
                $fields[] = array(
                        'key'   => 'chat.offline_start',
                        'type'  => 'time',
                        'label' => __( 'شروع ساعات کاری', 'hami-ai-assistant' ),
                );
                $fields[] = array(
                        'key'   => 'chat.offline_end',
                        'type'  => 'time',
                        'label' => __( 'پایان ساعات کاری', 'hami-ai-assistant' ),
                );
                $fields[] = array(
                        'key'   => 'chat.offline_message',
                        'type'  => 'text',
                        'label' => __( 'پیام خارج از ساعات کاری', 'hami-ai-assistant' ),
                );
                $fields[] = array(
                        'key'   => 'chat.save_history',
                        'type'  => 'checkbox',
                        'label' => __( 'ذخیره‌ی گفتگو', 'hami-ai-assistant' ),
                        'hint'  => __( 'هر گفتگو در یک ردیف جدول نشست‌ها ثبت می‌شود.', 'hami-ai-assistant' ),
                );
                $fields[] = array(
                        'key'   => 'chat.history_retention',
                        'type'  => 'number',
                        'label' => __( 'مدت نگهداری گفتگو (روز)', 'hami-ai-assistant' ),
                        'min'   => 1,
                        'max'   => 3650,
                );
                $fields[] = array(
                        'key'   => 'chat.lead_capture',
                        'type'  => 'checkbox',
                        'label' => __( 'دریافت نام و شماره در چت', 'hami-ai-assistant' ),
                        'hint'  => __( 'ماژول «باشگاه لید» باید فعال باشد.', 'hami-ai-assistant' ),
                );
                $fields[] = array(
                        'key'    => 'chat.lead_requirement',
                        'type'   => 'select',
                        'label'  => __( 'اجباری بودن', 'hami-ai-assistant' ),
                        'choices' => array(
                                'off'      => __( 'خاموش', 'hami-ai-assistant' ),
                                'optional' => __( 'اختیاری', 'hami-ai-assistant' ),
                                'required' => __( 'اجباری', 'hami-ai-assistant' ),
                        ),
                );
                $fields[] = array(
                        'key'   => 'chat.lead_name_label',
                        'type'  => 'text',
                        'label' => __( 'برچسب فیلد نام', 'hami-ai-assistant' ),
                );
                $fields[] = array(
                        'key'   => 'chat.lead_phone_label',
                        'type'  => 'text',
                        'label' => __( 'برچسب فیلد موبایل', 'hami-ai-assistant' ),
                );
                $fields[] = array(
                        'key'   => 'chat.lead_consent_text',
                        'type'  => 'text',
                        'label' => __( 'متن رضایت', 'hami-ai-assistant' ),
                );
                $fields[] = array(
                        'key'    => 'chat.tone',
                        'type'   => 'select',
                        'label'  => __( 'لحن پاسخ‌دهی', 'hami-ai-assistant' ),
                        'choices' => array(
                                'default'  => __( 'پیش‌فرض (طبق دستور سیستمی)', 'hami-ai-assistant' ),
                                'formal'   => __( 'رسمی و اداری', 'hami-ai-assistant' ),
                                'friendly' => __( 'صمیمی و دوستانه', 'hami-ai-assistant' ),
                                'playful'  => __( 'شوخ و پرانرژی', 'hami-ai-assistant' ),
                        ),
                        'hint'  => __( 'یک خط به دستور سیستمی اضافه می‌شود؛ متن اصلی را بازنویسی نمی‌کند.', 'hami-ai-assistant' ),
                );
                $fields[] = array(
                        'key'   => 'chat.page_context',
                        'type'  => 'checkbox',
                        'label' => __( 'آگاهی از صفحه‌ی فعلی', 'hami-ai-assistant' ),
                        'hint'  => __( 'بات بداند کاربر الان در کدام صفحه است (مثلاً صفحه‌ی محصول) و پاسخ را به همان صفحه وصل کند. بدون هیچ کوئری اضافه.', 'hami-ai-assistant' ),
                );
                $fields[] = array(
                        'key'   => 'chat.custom_knowledge',
                        'type'  => 'textarea',
                        'label' => __( 'دانش اختصاصی (اختیاری)', 'hami-ai-assistant' ),
                        'hint'  => __( 'هر چیزی که می‌خواهید بات بداند: شرایط گارانتی، روش ارسال، ساعات کاری، تخفیف‌ها… هر مورد در یک خط. فقط به پرامپت اضافه می‌شود — هیچ جدول یا کوئری‌ای نمی‌سازد.', 'hami-ai-assistant' ),
                );
                $fields[] = array(
                        'key'   => 'chat.handoff_enabled',
                        'type'  => 'checkbox',
                        'label' => __( 'گفتگوی زنده با پشتیبان', 'hami-ai-assistant' ),
                        'hint'  => __( 'بازدیدکننده با وارد کردن نام و شماره‌اش درخواست می‌دهد؛ شما از طریق بات بله یا تلگرام خبردار می‌شوید و با واتساپ یا تماس تلفنی به او پاسخ می‌دهید.', 'hami-ai-assistant' ),
                );
                $fields[] = array(
                        'key'   => 'chat.handoff_after',
                        'type'  => 'number',
                        'label' => __( 'پیشنهاد خودکار پس از چند پاسخ ناموفق', 'hami-ai-assistant' ),
                        'min'   => 0,
                        'max'   => 10,
                        'hint'  => __( '۰ یعنی هرگز خودکار پیشنهاد نده؛ بازدیدکننده فقط با دکمه‌ی «پشتیبان انسانی» درخواست بدهد.', 'hami-ai-assistant' ),
                );
                $fields[] = array(
                        'key'   => 'chat.notify_bale_token',
                        'type'  => 'text',
                        'label' => __( 'اعلان از بله — توکن بات', 'hami-ai-assistant' ),
                        'hint'  => __( 'از @BotFather بله بگیرید. مثال: 123456:ABC-DEF…', 'hami-ai-assistant' ),
                );
                $fields[] = array(
                        'key'   => 'chat.notify_bale_chat',
                        'type'  => 'text',
                        'label' => __( 'اعلان از بله — شناسه گفتگو', 'hami-ai-assistant' ),
                        'hint'  => __( 'چت‌آی‌دی خودتان یا گروه پشتیبانی (عددی). بات را یک بار استارت کنید و یک پیام به آن بدهید تا شناسه ثبت شود.', 'hami-ai-assistant' ),
                );
                $fields[] = array(
                        'key'   => 'chat.notify_tg_token',
                        'type'  => 'text',
                        'label' => __( 'اعلان از تلگرام — توکن بات (اختیاری)', 'hami-ai-assistant' ),
                        'hint'  => __( 'اگر سایت یا سرورتان به api.telegram.org دسترسی ندارد، فقط بله را پر کنید. برای سرورهای خارج از ایران کار می‌کند.', 'hami-ai-assistant' ),
                );
                $fields[] = array(
                        'key'   => 'chat.notify_tg_chat',
                        'type'  => 'text',
                        'label' => __( 'اعلان از تلگرام — شناسه گفتگو', 'hami-ai-assistant' ),
                );
                return $fields;
        }

        /* ------------------------------------------------------------------
         * Shared helpers used by Haa_Chat / Haa_REST when this module is on.
         * ---------------------------------------------------------------- */

        /**
         * Persist an anonymous or member conversation so it can be resumed.
         *
         * @param string $sid Session id (32 hex).
         * @param array  $turns Turns.
         * @param int    $user_id User id.
         */
        public static function store_session( $sid, $turns, $user_id = 0 ) {
                global $wpdb;
                if ( ! Haa_Settings::is_module_enabled( 'chatbot' ) ) {
                        return;
                }
                $table = Haa_DB::table( 'sessions' );
                $now   = current_time( 'mysql', true );
                $turns = array_slice( array_values( (array) $turns ), -40 );

                $row = $wpdb->get_row( $wpdb->prepare( "SELECT id FROM $table WHERE sid = %s", $sid ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                if ( $row ) {
                        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
                        $wpdb->update(
                                $table,
                                array(
                                        'messages'      => wp_json_encode( $turns ),
                                        'message_count' => count( $turns ),
                                        'updated'       => $now,
                                ),
                                array( 'id' => (int) $row['id'] ),
                                array( '%s', '%d', '%s' ),
                                array( '%d' )
                        );
                        return;
                }
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery
                $wpdb->insert(
                        $table,
                        array(
                                'sid'           => $sid,
                                'user_id'       => (int) $user_id,
                                'lead_id'       => 0,
                                'title'         => isset( $turns[0]['content'] ) ? Haa_Util::cut( (string) $turns[0]['content'], 160 ) : '',
                                'messages'      => wp_json_encode( $turns ),
                                'message_count' => count( $turns ),
                                'created'       => $now,
                                'updated'       => $now,
                        ),
                        array( '%s', '%d', '%d', '%s', '%s', '%d', '%s', '%s' )
                );
        }

        /**
         * Old conversations are deleted by the daily cleanup job
         * (Haa_DB::purge_old_sessions) — no module-side copy is needed.
         */
}
