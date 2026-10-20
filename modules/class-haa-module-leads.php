<?php
/**
 * Module: Leads — a small CRM fed by the chatbot.
 *
 * A lead is created when a visitor gives a name and an Iranian mobile inside
 * the chat (or when an admin adds one by hand). Every action appends an event
 * and moves a score, and the score decides the "temperature" the sales team
 * sees first.
 *
 * @package Hami_AI_Assistant
 */

if ( ! defined( 'ABSPATH' ) ) {
        exit;
}

final class Haa_Module_Leads implements Haa_Module_Interface {

        /** @return string */
        public function slug() {
                return 'leads';
        }

        /** @return string */
        public function label() {
                return __( 'باشگاه لید', 'hami-ai-assistant' );
        }

        /** @return string */
        public function description() {
                return __( 'مشتری‌هایی که در چت شماره داده‌اند را جمع می‌کند، امتیاز و دما می‌دهد، یادداشت و پیگیری ثبت می‌کند و به CSV خروجی می‌دهد.', 'hami-ai-assistant' );
        }

        /** @return bool */
        public function requires_woocommerce() {
                // Single source of truth: Haa_Modules::catalog_meta()[ 'woo' ]. Leads
                // store phone/email/note rows in their own tables and never touch a
                // product, order or coupon, so they work with WooCommerce switched off.
                return Haa_Modules::requires_woocommerce( $this->slug() );
        }

        /** @return void */
        public function register() {
                add_action( 'haai_settings_sections', array( $this, 'register_section' ), 10 );
                add_action( 'haai_settings_fields_leads', array( $this, 'register_fields' ) );
                add_action( 'haai_admin_menus', array( $this, 'register_menu' ) );
                add_action( 'admin_post_haai_lead_stage', array( $this, 'handle_stage' ) );
                add_action( 'admin_post_haai_lead_note', array( $this, 'handle_note' ) );
                add_action( 'admin_post_haai_lead_delete', array( $this, 'handle_delete' ) );
                add_action( 'admin_post_haai_lead_csv', array( $this, 'handle_csv' ) );
                add_action( 'admin_post_haai_lead_summary', array( $this, 'handle_summary' ) );
                add_action( 'admin_post_haai_lead_add', array( $this, 'handle_add' ) );
                add_action( 'haai_run_job_leads_summary', array( $this, 'run_summary_job' ), 10, 2 );
        }

        /**
         * Queued AI summary. One row, one tiny call.
         *
         * @param array $job     Queue row.
         * @param array $payload Decoded payload.
         */
        public function run_summary_job( $job, $payload ) {
                $lead_id = (int) ( $payload['lead_id'] ?? 0 );
                if ( ! $lead_id ) {
                        $GLOBALS['haai_job_result'] = new WP_Error( 'haai_lead_missing', __( 'شناسه لید نبود.', 'hami-ai-assistant' ) );
                        return;
                }
                self::summarize( $lead_id );
        }

        /** @return void */
        public function activate() {
                Haa_DB::create();
        }

        /** @return void */
        public function deactivate() {
                // Leads are data; uninstall.php decides their fate.
        }

        /**
         * @param array $sections Tabs so far.
         * @return array
         */
        public function register_section( $sections ) {
                $sections['leads'] = __( 'لیدها', 'hami-ai-assistant' );
                return $sections;
        }

        /**
         * @param array $fields Fields so far.
         * @return array
         */
        public function register_fields( $fields ) {
                $fields[] = array( 'key' => 'leads.auto_create', 'type' => 'checkbox', 'label' => __( 'ساخت خودکار از چت', 'hami-ai-assistant' ) );
                $fields[] = array( 'key' => 'leads.retention_days', 'type' => 'number', 'label' => __( 'مدت نگهداری (روز)', 'hami-ai-assistant' ), 'min' => 30, 'max' => 3650 );
                $fields[] = array( 'key' => 'leads.stages', 'type' => 'textarea', 'label' => __( 'مراحل (هر خط یکی)', 'hami-ai-assistant' ) );
                $fields[] = array( 'key' => 'leads.score_phone', 'type' => 'number', 'label' => __( 'امتیاز دادن شماره', 'hami-ai-assistant' ), 'min' => 0, 'max' => 100 );
                $fields[] = array( 'key' => 'leads.score_message', 'type' => 'number', 'label' => __( 'امتیاز هر پیام', 'hami-ai-assistant' ), 'min' => 0, 'max' => 100 );
                $fields[] = array( 'key' => 'leads.score_buying', 'type' => 'number', 'label' => __( 'امتیاز نیت خرید', 'hami-ai-assistant' ), 'min' => 0, 'max' => 100 );
                $fields[] = array( 'key' => 'leads.ai_summary', 'type' => 'checkbox', 'label' => __( 'خلاصه هوشمند', 'hami-ai-assistant' ), 'hint' => __( 'یک جمله خلاصه از رفتار لید می‌سازد.', 'hami-ai-assistant' ) );
                $fields[] = array( 'key' => 'leads.csv_enabled', 'type' => 'checkbox', 'label' => __( 'خروجی CSV', 'hami-ai-assistant' ) );
                return $fields;
        }

        /**
         * @param string $parent Parent slug.
         * @return void
         */
        public function register_menu( $parent ) {
                add_submenu_page(
                        $parent,
                        __( 'لیدها', 'hami-ai-assistant' ),
                        __( 'لیدها', 'hami-ai-assistant' ),
                        'manage_options',
                        Haa_Util::screen( 'leads' ),
                        array( $this, 'render_screen' )
                );
        }

        /* ------------------------------------------------------------------
         * Capture (called from Haa_Chat)
         * ---------------------------------------------------------------- */

        /**
         * Store a lead the chatbot just collected. Idempotent per phone number:
         * the same visitor talking twice updates one row instead of spamming.
         *
         * @param array  $extra      lead_name, lead_phone, lead_consent.
         * @param string $message    The message that carried the details.
         * @param string $reply      What the bot answered.
         * @param string $identifier Visitor identity.
         * @return int Lead id (0 on failure).
         */
        public static function capture_from_chat( $extra, $message, $reply, $identifier ) {
                if ( ! Haa_Settings::is_module_enabled( 'leads' ) ) {
                        return 0;
                }
                if ( empty( Haa_Settings::get( 'leads.auto_create' ) ) ) {
                        return 0;
                }

                $phone = Haa_Util::normalize_ir_mobile( (string) ( $extra['lead_phone'] ?? '' ) );
                $name  = Haa_Util::cut( sanitize_text_field( (string) ( $extra['lead_name'] ?? '' ) ), 160 );
                if ( '' === $phone ) {
                        return 0;
                }

                global $wpdb;
                $table = Haa_DB::table( 'leads' );
                $now   = current_time( 'mysql', true );

                $existing = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE phone = %s ORDER BY id DESC LIMIT 1", $phone ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

                $score_delta = (int) Haa_Settings::get( 'leads.score_phone', 15 );
                $buying      = self::buying_intent( $message . ' ' . $reply );
                if ( $buying ) {
                        $score_delta += (int) Haa_Settings::get( 'leads.score_buying', 20 );
                }

                if ( $existing ) {
                        $new_score = min( 999, (int) $existing['score'] + $score_delta );
                        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
                        $wpdb->update(
                                $table,
                                array(
                                        'name'          => '' !== $name ? $name : $existing['name'],
                                        'score'         => $new_score,
                                        'temperature'   => self::temperature( $new_score ),
                                        'last_activity' => $now,
                                        'updated'       => $now,
                                ),
                                array( 'id' => (int) $existing['id'] ),
                                array( '%s', '%d', '%s', '%s', '%s' ),
                                array( '%d' )
                        );
                        self::log_event( (int) $existing['id'], 'chat', $score_delta, Haa_Util::cut( $message, 500 ) );
                        self::maybe_summarize( (int) $existing['id'], $message, $reply );
                        return (int) $existing['id'];
                }

                $consent = trim( (string) ( $extra['consent'] ?? '' ) );
                $consent = '' !== $consent ? $consent : (string) Haa_Settings::get( 'chat.lead_consent_text', '' );

                // phpcs:ignore WordPress.DB.DirectDatabaseQuery
                $wpdb->insert(
                        $table,
                        array(
                                'name'          => $name,
                                'phone'         => $phone,
                                'email'         => '',
                                'lead_type'     => 'chat',
                                'related_id'    => 0,
                                'related_title' => '',
                                'source'        => 'chatbot',
                                'stage'         => 'new',
                                'temperature'   => self::temperature( $score_delta ),
                                'score'         => $score_delta,
                                'summary'       => '',
                                'summary_label' => '',
                                'user_id'       => get_current_user_id(),
                                'owner_id'      => get_current_user_id(),
                                'ip'            => Haa_REST::client_ip(),
                                'last_activity' => $now,
                                'consent_text'  => $consent,
                                'consent_at'    => '' !== $consent ? $now : null,
                                'created'       => $now,
                                'updated'       => $now,
                        ),
                        array( '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%s', '%s', '%s', '%s' )
                );

                $lead_id = (int) $wpdb->insert_id;
                if ( ! $lead_id ) {
                        return 0;
                }

                self::log_event( $lead_id, 'created', $score_delta, Haa_Util::cut( $message, 500 ) );
                self::maybe_summarize( $lead_id, $message, $reply );

                // Link the saved conversation so the two can be opened together.
                if ( Haa_Settings::is_module_enabled( 'chatbot' ) ) {
                        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
                        $wpdb->update(
                                Haa_DB::table( 'sessions' ),
                                array( 'lead_id' => $lead_id ),
                                array( 'sid' => substr( (string) $identifier, 0, 64 ) ),
                                array( '%d' ),
                                array( '%s' )
                        );
                }

                return $lead_id;
        }

        /* ------------------------------------------------------------------
         * Screen
         * ---------------------------------------------------------------- */

        /** @return void */
        public function render_screen() {
                if ( ! current_user_can( 'manage_options' ) ) {
                        return;
                }

                $stage_filter = isset( $_GET['stage'] ) ? sanitize_key( wp_unslash( $_GET['stage'] ) ) : '';
                $search       = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
                $paged        = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
                $per_page     = 20;

                global $wpdb;
                $table = Haa_DB::table( 'leads' );

                $where  = array( '1=1' );
                $params = array();
                if ( '' !== $stage_filter ) {
                        $where[]  = 'stage = %s';
                        $params[] = $stage_filter;
                }
                if ( '' !== $search ) {
                        $where[]  = '(phone LIKE %s OR name LIKE %s)';
                        $like     = '%' . $wpdb->esc_like( $search ) . '%';
                        $params[] = $like;
                        $params[] = $like;
                }
                $where_sql = implode( ' AND ', $where );

                $count_sql = "SELECT COUNT(*) FROM $table WHERE $where_sql"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                $total     = $params ? (int) $wpdb->get_var( $wpdb->prepare( $count_sql, $params ) ) : (int) $wpdb->get_var( $count_sql ); // phpcs:ignore WordPress.DB.PreparedSQL

                $offset = ( $paged - 1 ) * $per_page;
                $list_sql = "SELECT * FROM $table WHERE $where_sql ORDER BY score DESC, id DESC LIMIT %d OFFSET %d"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                $params[] = $per_page;
                $params[] = $offset;
                $leads    = $wpdb->get_results( $wpdb->prepare( $list_sql, $params ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL

                $stages = self::stages();
                ?>
                <div class="wrap haai-wrap">
                        <?php Haa_Admin::page_header( __( 'لیدها', 'hami-ai-assistant' ) ); ?>

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
                                <?php foreach ( $stages as $stage ) : ?>
                                        <div class="haai-card">
                                                <div class="haai-card__value"><?php echo esc_html( number_format_i18n( self::count_by_stage( $stage ) ) ); ?></div>
                                                <div class="haai-card__label"><?php echo esc_html( $stage ); ?></div>
                                        </div>
                                <?php endforeach; ?>
                        </div>

                        <form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" class="haai-filter">
                                <input type="hidden" name="page" value="haai-leads">
                                <input type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'جستجوی نام یا شماره', 'hami-ai-assistant' ); ?>">
                                <select name="stage">
                                        <option value=""><?php esc_html_e( 'همه مراحل', 'hami-ai-assistant' ); ?></option>
                                        <?php foreach ( $stages as $stage ) : ?>
                                                <option value="<?php echo esc_attr( $stage ); ?>" <?php selected( $stage, $stage_filter ); ?>><?php echo esc_html( $stage ); ?></option>
                                        <?php endforeach; ?>
                                </select>
                                <button type="submit" class="button"><?php esc_html_e( 'فیلتر', 'hami-ai-assistant' ); ?></button>
                                <?php if ( ! empty( Haa_Settings::get( 'leads.csv_enabled' ) ) ) : ?>
                                        <a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=haai_lead_csv' ), 'haai_lead' ) ); ?>">
                                                <?php esc_html_e( 'خروجی CSV', 'hami-ai-assistant' ); ?>
                                        </a>
                                <?php endif; ?>
                        </form>

                        <table class="widefat striped haai-table">
                                <thead>
                                        <tr>
                                                <th><?php esc_html_e( 'نام', 'hami-ai-assistant' ); ?></th>
                                                <th><?php esc_html_e( 'موبایل', 'hami-ai-assistant' ); ?></th>
                                                <th><?php esc_html_e( 'دما', 'hami-ai-assistant' ); ?></th>
                                                <th><?php esc_html_e( 'امتیاز', 'hami-ai-assistant' ); ?></th>
                                                <th><?php esc_html_e( 'مرحله', 'hami-ai-assistant' ); ?></th>
                                                <th><?php esc_html_e( 'منبع', 'hami-ai-assistant' ); ?></th>
                                                <th><?php esc_html_e( 'آخرین فعالیت', 'hami-ai-assistant' ); ?></th>
                                                <th></th>
                                        </tr>
                                </thead>
                                <tbody>
                                        <?php if ( empty( $leads ) ) : ?>
                                                <tr><td colspan="8">
                                                        <div class="haai-empty">
                                                                <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="8" r="4"/><path d="M4 20a8 8 0 0 1 16 0"/></svg>
                                                                <span class="haai-empty__title"><?php esc_html_e( 'لیدی نیست.', 'hami-ai-assistant' ); ?></span>
                                                                <p class="haai-empty__hint"><?php esc_html_e( 'هر مهمانی که در چت شماره‌اش را وارد کند، اینجا با امتیاز و دما ظاهر می‌شود.', 'hami-ai-assistant' ); ?></p>
                                                        </div>
                                                </td></tr>
                                        <?php else : ?>
                                                <?php foreach ( $leads as $lead ) : ?>
                                                        <?php $this->render_row( $lead, $stages ); ?>
                                                <?php endforeach; ?>
                                        <?php endif; ?>
                                </tbody>
                        </table>

                        <?php if ( $total > $per_page ) : ?>
                                <div class="tablenav">
                                        <div class="tablenav-pages">
                                                <?php
                                                echo wp_kses_post(
                                                        paginate_links(
                                                                array(
                                                                        'total'   => (int) ceil( $total / $per_page ),
                                                                        'current' => $paged,
                                                                        'add_args' => array_filter(
                                                                                array(
                                                                                        'page'  => Haa_Util::screen( 'leads' ),
                                                                                        'stage' => $stage_filter,
                                                                                        's'     => $search,
                                                                                )
                                                                        ),
                                                                )
                                                        )
                                                );
                                                ?>
                                        </div>
                                </div>
                        <?php endif; ?>

                        <h2><?php esc_html_e( 'افزودن دستی', 'hami-ai-assistant' ); ?></h2>
                        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                                <?php wp_nonce_field( 'haai_lead' ); ?>
                                <input type="hidden" name="action" value="haai_lead_add">
                                <input type="text" name="name" placeholder="<?php esc_attr_e( 'نام', 'hami-ai-assistant' ); ?>" class="regular-text">
                                <input type="tel" name="phone" placeholder="<?php esc_attr_e( '۰۹۱۲…', 'hami-ai-assistant' ); ?>" class="regular-text" required>
                                <input type="email" name="email" placeholder="<?php esc_attr_e( 'ایمیل', 'hami-ai-assistant' ); ?>" class="regular-text">
                                <button type="submit" class="button button-primary"><?php esc_html_e( 'ثبت', 'hami-ai-assistant' ); ?></button>
                        </form>
                </div>
                <?php
        }

        /**
         * @param array $lead   Row.
         * @param array $stages Stage list.
         */
        private function render_row( $lead, $stages ) {
                $score = (int) $lead['score'];
                $temp  = (string) $lead['temperature'];
                ?>
                <tr>
                        <td>
                                <strong><?php echo esc_html( '' !== $lead['name'] ? $lead['name'] : '—' ); ?></strong>
                                <?php if ( ! empty( $lead['summary'] ) ) : ?>
                                        <div class="description"><?php echo esc_html( Haa_Util::cut( $lead['summary'], 200 ) ); ?></div>
                                <?php endif; ?>
                        </td>
                        <td><code><?php echo esc_html( $lead['phone'] ); ?></code></td>
                        <td>
                                <span class="haai-badge <?php echo 'hot' === $temp ? 'haai-badge--on' : ( 'warm' === $temp ? 'haai-badge--warn' : '' ); ?>">
                                        <?php echo esc_html( self::temperature_label( $temp ) ); ?>
                                </span>
                        </td>
                        <td><?php echo esc_html( $score ); ?></td>
                        <td>
                                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="haai-inline">
                                        <?php wp_nonce_field( 'haai_lead' ); ?>
                                        <input type="hidden" name="action" value="haai_lead_stage">
                                        <input type="hidden" name="lead_id" value="<?php echo esc_attr( $lead['id'] ); ?>">
                                        <select name="stage" onchange="this.form.submit()">
                                                <?php foreach ( $stages as $stage ) : ?>
                                                        <option value="<?php echo esc_attr( $stage ); ?>" <?php selected( $stage, $lead['stage'] ); ?>><?php echo esc_html( $stage ); ?></option>
                                                <?php endforeach; ?>
                                        </select>
                                </form>
                        </td>
                        <td><?php echo esc_html( $lead['source'] ); ?></td>
                        <td><?php echo esc_html( $lead['last_activity'] ? mysql2date( 'Y-m-d H:i', $lead['last_activity'] ) : '—' ); ?></td>
                        <td>
                                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="haai-inline">
                                        <?php wp_nonce_field( 'haai_lead' ); ?>
                                        <input type="hidden" name="action" value="haai_lead_note">
                                        <input type="hidden" name="lead_id" value="<?php echo esc_attr( $lead['id'] ); ?>">
                                        <input type="text" name="note" placeholder="<?php esc_attr_e( 'یادداشت…', 'hami-ai-assistant' ); ?>" class="haai-note">
                                        <button type="submit" class="button"><?php esc_html_e( 'ثبت', 'hami-ai-assistant' ); ?></button>
                                </form>
                                <?php if ( ! empty( Haa_Settings::get( 'leads.ai_summary' ) ) ) : ?>
                                        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="haai-inline">
                                                <?php wp_nonce_field( 'haai_lead' ); ?>
                                                <input type="hidden" name="action" value="haai_lead_summary">
                                                <input type="hidden" name="lead_id" value="<?php echo esc_attr( $lead['id'] ); ?>">
                                                <button type="submit" class="button"><?php esc_html_e( 'خلاصه', 'hami-ai-assistant' ); ?></button>
                                        </form>
                                <?php endif; ?>
                                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="haai-inline" data-haai-confirm>
                                        <?php wp_nonce_field( 'haai_lead' ); ?>
                                        <input type="hidden" name="action" value="haai_lead_delete">
                                        <input type="hidden" name="lead_id" value="<?php echo esc_attr( $lead['id'] ); ?>">
                                        <button type="submit" class="button-link-delete"><?php esc_html_e( 'حذف', 'hami-ai-assistant' ); ?></button>
                                </form>
                        </td>
                </tr>
                <?php
        }

        /* ------------------------------------------------------------------
         * Handlers
         * ---------------------------------------------------------------- */

        /** @return void */
        public function handle_stage() {
                Haa_Util::guard_admin_post( 'haai_lead' );
                $id    = isset( $_POST['lead_id'] ) ? absint( $_POST['lead_id'] ) : 0;
                $stage = isset( $_POST['stage'] ) ? sanitize_key( wp_unslash( $_POST['stage'] ) ) : '';
                if ( ! $id || ! in_array( $stage, self::stages(), true ) ) {
                        $this->back( __( 'درخواست نامعتبر بود.', 'hami-ai-assistant' ) );
                }
                self::update_stage( $id, $stage );
                $this->back( '' );
        }

        /** @return void */
        public function handle_note() {
                Haa_Util::guard_admin_post( 'haai_lead' );
                $id   = isset( $_POST['lead_id'] ) ? absint( $_POST['lead_id'] ) : 0;
                $note = isset( $_POST['note'] ) ? sanitize_textarea_field( wp_unslash( $_POST['note'] ) ) : '';
                if ( ! $id || '' === trim( $note ) ) {
                        $this->back( __( 'یادداشت خالی است.', 'hami-ai-assistant' ) );
                }
                self::log_event( $id, 'note', 0, Haa_Util::cut( $note, 2000 ) );
                self::touch( $id );
                $this->back( '' );
        }

        /** @return void */
        public function handle_delete() {
                Haa_Util::guard_admin_post( 'haai_lead' );
                $id = isset( $_POST['lead_id'] ) ? absint( $_POST['lead_id'] ) : 0;
                if ( ! $id ) {
                        $this->back( __( 'لید پیدا نشد.', 'hami-ai-assistant' ) );
                }
                global $wpdb;
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery
                $wpdb->delete( Haa_DB::table( 'leads' ), array( 'id' => $id ), array( '%d' ) );
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery
                $wpdb->delete( Haa_DB::table( 'lead_events' ), array( 'lead_id' => $id ), array( '%d' ) );
                $this->back( '' );
        }

        /** @return void */
        public function handle_add() {
                Haa_Util::guard_admin_post( 'haai_lead' );

                $phone = Haa_Util::normalize_ir_mobile( isset( $_POST['phone'] ) ? (string) wp_unslash( $_POST['phone'] ) : '' );
                $name  = Haa_Util::cut( sanitize_text_field( isset( $_POST['name'] ) ? wp_unslash( $_POST['name'] ) : '' ), 160 );
                $email = sanitize_email( isset( $_POST['email'] ) ? wp_unslash( $_POST['email'] ) : '' );

                if ( '' === $phone ) {
                        $this->back( __( 'شماره موبایل الزامی است.', 'hami-ai-assistant' ) );
                }

                global $wpdb;
                $table = Haa_DB::table( 'leads' );
                $now   = current_time( 'mysql', true );

                // phpcs:ignore WordPress.DB.DirectDatabaseQuery
                $wpdb->insert(
                        $table,
                        array(
                                'name'          => $name,
                                'phone'         => $phone,
                                'email'         => $email,
                                'lead_type'     => 'manual',
                                'source'        => 'admin',
                                'stage'         => 'new',
                                'temperature'   => 'cold',
                                'score'         => 0,
                                'user_id'       => get_current_user_id(),
                                'owner_id'      => get_current_user_id(),
                                'last_activity' => $now,
                                'created'       => $now,
                                'updated'       => $now,
                        ),
                        array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%s', '%s' )
                );
                $lead_id = (int) $wpdb->insert_id;
                if ( $lead_id ) {
                        self::log_event( $lead_id, 'created', 0, __( 'ثبت دستی توسط مدیر.', 'hami-ai-assistant' ) );
                }
                $this->back( '' );
        }

        /** @return void */
        public function handle_csv() {
                Haa_Util::guard_admin_post( 'haai_lead' );

                if ( empty( Haa_Settings::get( 'leads.csv_enabled' ) ) ) {
                        wp_die( __( 'خروجی CSV در تنظیمات خاموش است.', 'hami-ai-assistant' ) );
                }

                global $wpdb;
                $table = Haa_DB::table( 'leads' );
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                $leads = $wpdb->get_results( "SELECT * FROM $table ORDER BY id DESC LIMIT 5000", ARRAY_A );

                $rows = array(
                        array( 'id', 'name', 'phone', 'email', 'source', 'stage', 'temperature', 'score', 'consent', 'created', 'last_activity' ),
                );
                foreach ( (array) $leads as $l ) {
                        $rows[] = array(
                                $l['id'],
                                Haa_Util::escape_csv_field( $l['name'] ),
                                Haa_Util::escape_csv_field( $l['phone'] ),
                                Haa_Util::escape_csv_field( $l['email'] ),
                                Haa_Util::escape_csv_field( $l['source'] ),
                                Haa_Util::escape_csv_field( $l['stage'] ),
                                $l['temperature'],
                                $l['score'],
                                Haa_Util::escape_csv_field( (string) $l['consent_text'] ),
                                $l['created'],
                                Haa_Util::escape_csv_field( (string) $l['last_activity'] ),
                        );
                }

                Haa_Util::send_csv( 'hami-leads-' . gmdate( 'Y-m-d' ) . '.csv', $rows );
        }

        /** @return void */
        public function handle_summary() {
                Haa_Util::guard_admin_post( 'haai_lead' );
                $id = isset( $_POST['lead_id'] ) ? absint( $_POST['lead_id'] ) : 0;
                if ( ! $id ) {
                        $this->back( __( 'لید پیدا نشد.', 'hami-ai-assistant' ) );
                }
                self::summarize( $id );
                $this->back( '' );
        }

        /* ------------------------------------------------------------------
         * Internals
         * ---------------------------------------------------------------- */

        /**
         * @param int    $lead_id Lead.
         * @param string $stage   New stage.
         */
        public static function update_stage( $lead_id, $stage ) {
                global $wpdb;
                $table = Haa_DB::table( 'leads' );
                $now   = current_time( 'mysql', true );

                // phpcs:ignore WordPress.DB.DirectDatabaseQuery
                $wpdb->update(
                        $table,
                        array(
                                'stage'         => $stage,
                                'last_activity' => $now,
                                'updated'       => $now,
                        ),
                        array( 'id' => (int) $lead_id ),
                        array( '%s', '%s', '%s' ),
                        array( '%d' )
                );

                $bonus = in_array( $stage, array( 'qualified', 'won' ), true ) ? (int) Haa_Settings::get( 'leads.score_buying', 20 ) : 0;
                if ( $bonus ) {
                        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
                        $wpdb->query( $wpdb->prepare( "UPDATE $table SET score = LEAST(999, score + %d) WHERE id = %d", $bonus, $lead_id ) );
                }
                self::log_event( $lead_id, 'stage', $bonus, $stage );
        }

        /**
         * @param int    $lead_id Lead.
         * @param string $type    Event type.
         * @param int    $delta   Score delta.
         * @param string $content Body.
         */
        public static function log_event( $lead_id, $type, $delta = 0, $content = '' ) {
                global $wpdb;
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery
                $wpdb->insert(
                        Haa_DB::table( 'lead_events' ),
                        array(
                                'lead_id'     => (int) $lead_id,
                                'event_type'  => substr( (string) $type, 0, 40 ),
                                'score_delta' => (int) $delta,
                                'content'     => $content,
                                'user_id'     => get_current_user_id(),
                                'created'     => current_time( 'mysql', true ),
                        ),
                        array( '%d', '%s', '%d', '%s', '%d', '%s' )
                );
        }

        /**
         * @param int $lead_id Lead.
         */
        public static function touch( $lead_id ) {
                global $wpdb;
                $now = current_time( 'mysql', true );
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery
                $wpdb->update(
                        Haa_DB::table( 'leads' ),
                        array(
                                'last_activity' => $now,
                                'updated'       => $now,
                        ),
                        array( 'id' => (int) $lead_id ),
                        array( '%s', '%s' ),
                        array( '%d' )
                );
        }

        /**
         * @param string $text Message + reply.
         * @return bool
         */
        private static function buying_intent( $text ) {
                $norm = Haa_Search::normalize_text( $text );
                return (bool) preg_match( '/خرید|سفارش|پرداخت|قیمت|تخفیف|موجود|ارسال|کد تخفیف|سفارش میدم|میخوام|می‌خوام|buy|order|price/', $norm );
        }

        /**
         * @param int $score Score.
         * @return string cold|warm|hot
         */
        public static function temperature( $score ) {
                $score = (int) $score;
                if ( $score >= 60 ) {
                        return 'hot';
                }
                if ( $score >= 25 ) {
                        return 'warm';
                }
                return 'cold';
        }

        /**
         * @param string $temp cold|warm|hot.
         * @return string
         */
        public static function temperature_label( $temp ) {
                $map = array(
                        'hot'  => __( 'داغ', 'hami-ai-assistant' ),
                        'warm' => __( 'گرم', 'hami-ai-assistant' ),
                        'cold' => __( 'سرد', 'hami-ai-assistant' ),
                );
                return $map[ $temp ] ?? $temp;
        }

        /** @return array<int,string> */
        public static function stages() {
                $raw  = (string) Haa_Settings::get( 'leads.stages', "new\ncontacted\nqualified\nwon\nlost" );
                $out  = array();
                foreach ( preg_split( '/\R+/u', $raw ) as $line ) {
                        $line = trim( sanitize_key( $line ) );
                        if ( '' !== $line ) {
                                $out[] = $line;
                        }
                }
                return $out ? $out : array( 'new', 'contacted', 'qualified', 'won', 'lost' );
        }

        /**
         * @param string $stage Stage.
         * @return int
         */
        private static function count_by_stage( $stage ) {
                global $wpdb;
                $table = Haa_DB::table( 'leads' );
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $table WHERE stage = %s", $stage ) );
        }

        /**
         * Build the one-line AI summary when the feature is on and the conversation
         * is long enough to be worth a call.
         *
         * @param int    $lead_id Lead.
         * @param string $message Latest user message.
         * @param string $reply   Latest bot reply.
         */
        private static function maybe_summarize( $lead_id, $message, $reply ) {
                if ( empty( Haa_Settings::get( 'leads.ai_summary' ) ) ) {
                        return;
                }
                Haa_Queue::push(
                        'leads_summary',
                        array(
                                'lead_id' => (int) $lead_id,
                                'message' => Haa_Util::cut( $message, 1500 ),
                                'reply'   => Haa_Util::cut( $reply, 1500 ),
                        ),
                        array( 'module' => 'leads', 'max_attempts' => 1 )
                );
        }

        /**
         * Synchronous summary (used by the admin button and the queued job).
         *
         * @param int $lead_id Lead.
         */
        public static function summarize( $lead_id ) {
                if ( empty( Haa_Settings::get( 'leads.ai_summary' ) ) ) {
                        return;
                }
                global $wpdb;
                $table = Haa_DB::table( 'leads' );
                $lead  = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE id = %d", (int) $lead_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
                if ( ! $lead ) {
                        return;
                }

                $events = $wpdb->get_results( $wpdb->prepare( "SELECT event_type, content FROM " . Haa_DB::table( 'lead_events' ) . " WHERE lead_id = %d ORDER BY id DESC LIMIT 10", (int) $lead_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared

                $transcript = '';
                foreach ( (array) $events as $e ) {
                        if ( '' !== trim( (string) $e['content'] ) ) {
                                $transcript .= $e['event_type'] . ': ' . $e['content'] . "\n";
                        }
                }

                $api = new Haa_API();
                $out = $api->chat(
                        array(
                                array(
                                        'role'    => 'system',
                                        'content' => __( 'یک جمله فارسی و کوتاه درباره این مشتری بنویس: چه می‌خواهد، چه پرسیده و چه باید کرد. فقط همان یک جمله را برگردان.', 'hami-ai-assistant' ),
                                ),
                                array(
                                        'role'    => 'user',
                                        'content' => 'نام: ' . $lead['name'] . "\nشماره: " . $lead['phone'] . "\nمرحله: " . $lead['stage'] . "\nگفتگو:\n" . Haa_Util::cut( $transcript, 2500 ),
                                ),
                        ),
                        array( 'module' => 'leads', 'operation' => 'summary' )
                );

                if ( is_wp_error( $out ) ) {
                        return;
                }

                $summary = Haa_Util::cut( wp_strip_all_tags( $out ), 300 );
                $now     = current_time( 'mysql', true );
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery
                $wpdb->update(
                        $table,
                        array(
                                'summary'       => $summary,
                                'summary_label' => self::label_for( $summary ),
                                'updated'       => $now,
                        ),
                        array( 'id' => (int) $lead_id ),
                        array( '%s', '%s', '%s' ),
                        array( '%d' )
                );
        }

        /**
         * Short bucket shown next to the summary.
         *
         * @param string $summary Sentence.
         * @return string
         */
        private static function label_for( $summary ) {
                $norm = Haa_Search::normalize_text( $summary );
                if ( preg_match( '/خرید|سفارش|پرداخت/', $norm ) ) {
                        return __( 'قصد خرید', 'hami-ai-assistant' );
                }
                if ( preg_match( '/قیمت|ارزان|تخفیف/', $norm ) ) {
                        return __( 'حساس قیمت', 'hami-ai-assistant' );
                }
                if ( preg_match( '/ناموجود|موجودی|موجود/', $norm ) ) {
                        return __( 'در حال موجودی', 'hami-ai-assistant' );
                }
                return __( 'عمومی', 'hami-ai-assistant' );
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
                wp_safe_redirect( Haa_Util::screen_url( 'leads', $args ) );
                exit;
        }
}
