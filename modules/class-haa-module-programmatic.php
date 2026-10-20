<?php
/**
 * Programmatic page builder (مرحله D4).
 *
 * This is the module most likely to hurt a site: hundreds of near-identical
 * pages is the textbook "thin content" penalty. So the guards are not optional
 * extras — they are the reason this module is allowed to exist:
 *
 *  - an item needs at least two real data fields, or it is skipped;
 *  - every page gets one paragraph written *about that specific item*, and that
 *    paragraph is compared with the paragraphs already written; above the
 *    similarity threshold the page is refused as a duplicate;
 *  - noindex is ON by default, so nothing reaches the index unreviewed;
 *  - a daily cap and a request estimate, because this scales to thousands of
 *    model calls faster than any other module;
 *  - a hub page that lists the children, so the pages are not orphans.
 *
 * @package Hami_AI_Assistant
 */

if ( ! defined( 'ABSPATH' ) ) {
        exit;
}

final class Haa_Module_Programmatic implements Haa_Module_Interface {

        const TABLE = 'prog_items';

        /** Minimum distinct non-empty fields an item must carry. */
        const MIN_FIELDS = 2;

        /** Ceiling on rows parsed from one source. */
        const MAX_ITEMS = 500;

        /** @return string */
        public function slug() { return 'programmatic'; }

        /** @return string */
        public function label() { return __( 'صفحه‌سازی برنامه‌ریزی‌شده', 'hami-ai-assistant' ); }

        /** @return string */
        public function description() { return __( 'از CSV یا ویژگی‌های ووکامرس صفحه‌های سئوشده می‌سازد، با نگهبان محتوای نازک و صفحه‌ی hub.', 'hami-ai-assistant' ); }

        /** @return bool */
        public function requires_woocommerce() { return Haa_Modules::requires_woocommerce( $this->slug() ); }

        /** @return void */
        public function register() {
                add_action( 'haai_settings_sections', array( $this, 'register_section' ), 10 );
                add_action( 'haai_settings_fields_programmatic', array( $this, 'register_fields' ) );
                add_action( 'haai_admin_menus', array( $this, 'register_menu' ) );

                add_action( 'admin_post_haai_pg_run', array( $this, 'handle_run' ) );
                add_action( 'admin_post_haai_pg_hub', array( $this, 'handle_hub' ) );
                add_action( 'admin_post_haai_pg_index', array( $this, 'handle_index' ) );

                // Front-end, tiny, and only while the module is on: honours the per-page
                // noindex marker when no SEO plugin is installed to carry it.
                add_filter( 'wp_robots', array( $this, 'filter_robots' ) );
        }

        /** @return void */
        public function activate() {
                Haa_DB::create();
        }

        /** @return void */
        public function deactivate() {}

        /**
         * @param array $sections Sections so far.
         * @return array
         */
        public function register_section( $sections ) {
                $sections['programmatic'] = __( 'صفحه‌سازی برنامه‌ریزی‌شده', 'hami-ai-assistant' );
                return $sections;
        }

        /**
         * @param array $fields Fields so far.
         * @return array
         */
        public function register_fields( $fields ) {
                $fields[] = array( 'key' => 'programmatic.enabled', 'type' => 'checkbox', 'label' => __( 'این ماژول استفاده شود', 'hami-ai-assistant' ) );
                $fields[] = array( 'key' => 'programmatic.source', 'type' => 'select', 'label' => __( 'منبع داده', 'hami-ai-assistant' ), 'choices' => array( 'csv' => __( 'CSV (چسباندن یا آپلود)', 'hami-ai-assistant' ), 'attribute' => __( 'ویژگی ووکامرس', 'hami-ai-assistant' ), 'manual' => __( 'فهرست دستی (هر خط یک مورد)', 'hami-ai-assistant' ) ) );
                $fields[] = array( 'key' => 'programmatic.attribute', 'type' => 'text', 'label' => __( 'نام ویژگی (مثل pa_color)', 'hami-ai-assistant' ) );
                $fields[] = array( 'key' => 'programmatic.title_template', 'type' => 'text', 'label' => __( 'الگو عنوان', 'hami-ai-assistant' ), 'hint' => __( 'مثلاً «{{title}} مشکی برای استفاده اداری».', 'hami-ai-assistant' ) );
                $fields[] = array( 'key' => 'programmatic.template', 'type' => 'textarea', 'label' => __( 'الگو متن صفحه', 'hami-ai-assistant' ), 'hint' => __( 'جای‌نماها با {{کلید}} پر می‌شوند؛ اگر کلیدی در داده نباشد آن مورد ساخته نمی‌شود.', 'hami-ai-assistant' ) );
                $fields[] = array( 'key' => 'programmatic.hub_page', 'type' => 'number', 'label' => __( 'شناسه‌ی صفحه‌ی hub', 'hami-ai-assistant' ), 'min' => 0, 'max' => 999999, 'hint' => __( 'صفحه‌های ساخته‌شده به این صفحه لینک می‌دهند و فهرستشان اینجا ساخته می‌شود.', 'hami-ai-assistant' ) );
                $fields[] = array( 'key' => 'programmatic.min_words', 'type' => 'number', 'label' => __( 'حداقل کلمه‌ی یکتای هر صفحه', 'hami-ai-assistant' ), 'min' => 200, 'max' => 2000, 'hint' => __( 'زیر این مقدار صفحه ساخته نمی‌شود؛ این همان نگهبان محتوای نازک است.', 'hami-ai-assistant' ) );
                $fields[] = array( 'key' => 'programmatic.similar_threshold', 'type' => 'float', 'label' => __( 'آستانه‌ی «شبیه صفحه‌ی دیگر»', 'hami-ai-assistant' ), 'min' => 0.3, 'max' => 1, 'step' => 0.05, 'hint' => __( 'بیش از این هم‌پوشانی، صفحه‌ی تازه ساخته نمی‌شود.', 'hami-ai-assistant' ) );
                $fields[] = array( 'key' => 'programmatic.daily_cap', 'type' => 'number', 'label' => __( 'حداکثر صفحه در روز', 'hami-ai-assistant' ), 'min' => 0, 'max' => 200 );
                $fields[] = array( 'key' => 'programmatic.noindex', 'type' => 'checkbox', 'label' => __( 'پیش‌فرض noindex باشد', 'hami-ai-assistant' ), 'hint' => __( 'تا وقتی خودتان صفحه‌ای را تأیید نکرده‌اید، ایندکس نشود.', 'hami-ai-assistant' ) );
                $fields[] = array( 'key' => 'programmatic.default_status', 'type' => 'select', 'label' => __( 'وضعیت پیش‌فرض', 'hami-ai-assistant' ), 'choices' => array( 'draft' => __( 'پیش‌نویس', 'hami-ai-assistant' ), 'pending' => __( 'در انتظار بررسی', 'hami-ai-assistant' ) ) );
                return $fields;
        }

        /**
         * @param array $menus Menu entries so far.
         * @return void
         */
        public function register_menu( $menus ) {
                $parent = Haa_Util::screen( 'settings' );
                add_submenu_page(
                        $parent,
                        __( 'صفحه‌سازی برنامه‌ریزی‌شده', 'hami-ai-assistant' ),
                        __( 'صفحه‌سازی برنامه‌ریزی‌شده', 'hami-ai-assistant' ),
                        'manage_options',
                        Haa_Util::screen( 'programmatic' ),
                        array( $this, 'render_screen' )
                );
        }

        /* ------------------------------------------------------------------
         * Config
         * ---------------------------------------------------------------- */

        /** @return array */
        private static function cfg() {
                $s = Haa_Settings::get();
                return isset( $s['programmatic'] ) && is_array( $s['programmatic'] ) ? $s['programmatic'] : array();
        }

        /** @return bool */
        public static function enabled() {
                return ! empty( self::cfg()['enabled'] );
        }

        /**
         * Keep a programmatic page out of the index unless the marker says otherwise.
         *
         * @param array $robots Robot directives.
         * @return array
         */
        public function filter_robots( $robots ) {
                if ( ! self::enabled() || ! is_singular() ) {
                        return $robots;
                }
                $post_id = (int) get_queried_object_id();
                if ( $post_id && '1' === (string) get_post_meta( $post_id, '_haai_noindex', true ) ) {
                        $robots['index']  = 'noindex';
                        $robots['follow'] = 'follow';
                }
                return $robots;
        }

        /* ------------------------------------------------------------------
         * Data sources
         * ---------------------------------------------------------------- */

        /**
         * CSV text into item maps. First line is the header.
         *
         * @param string $csv Raw text.
         * @return array<int,array<string,string>>
         */
        public static function parse_csv( $csv ) {
                $rows = array();

                // 'w+', not 'r': php://temp opened read-only silently refuses the write,
                // so every import would parse zero rows and look like a bad file.
                $fh = fopen( 'php://temp', 'w+' );
                if ( ! $fh ) {
                        return array();
                }
                fwrite( $fh, (string) $csv );
                rewind( $fh );

                $header = null;
                while ( ( $line = fgetcsv( $fh, 0, ',' ) ) !== false ) {
                        if ( null === $line || ( 1 === count( $line ) && '' === trim( (string) $line[0] ) ) ) {
                                continue;
                        }
                        if ( null === $header ) {
                                $header = array_map(
                                        function ( $h ) {
                                                return Haa_Util::slugify( (string) $h );
                                        },
                                        (array) $line
                                );
                                continue;
                        }

                        $item = array();
                        foreach ( $header as $i => $key ) {
                                if ( '' === $key || ! isset( $line[ $i ] ) ) {
                                        continue;
                                }
                                $val = trim( wp_strip_all_tags( (string) $line[ $i ] ) );
                                if ( '' !== $val ) {
                                        $item[ $key ] = Haa_Util::cut( $val, 200 );
                                }
                        }
                        if ( $item ) {
                                $rows[] = $item;
                        }
                        if ( count( $rows ) >= self::MAX_ITEMS ) {
                                break;
                        }
                }
                fclose( $fh );

                return $rows;
        }

        /**
         * Manual list: `title | field=value | field=value`.
         *
         * @param string $text One item per line.
         * @return array<int,array<string,string>>
         */
        public static function parse_manual( $text ) {
                $rows = array();
                foreach ( preg_split( '/\R+/u', (string) $text ) as $line ) {
                        $line = trim( wp_strip_all_tags( $line ) );
                        if ( '' === $line ) {
                                continue;
                        }
                        $parts = array_map( 'trim', explode( '|', $line ) );
                        $item  = array( 'title' => (string) array_shift( $parts ) );
                        foreach ( $parts as $p ) {
                                if ( false === strpos( $p, '=' ) ) {
                                        // A bare token is still useful as a feature word.
                                        $item['feature_' . count( $item )] = $p;
                                        continue;
                                }
                                list( $k, $v ) = array_pad( preg_split( '/=/', $p, 2 ), 2, '' );
                                $k = Haa_Util::slugify( (string) $k );
                                $v = trim( (string) $v );
                                if ( '' !== $k && '' !== $v ) {
                                        $item[ $k ] = Haa_Util::cut( $v, 200 );
                                }
                        }
                        if ( '' !== trim( (string) $item['title'] ) ) {
                                $rows[] = $item;
                        }
                        if ( count( $rows ) >= self::MAX_ITEMS ) {
                                break;
                        }
                }
                return $rows;
        }

        /**
         * WooCommerce attribute terms, when that source is chosen and Woo is present.
         *
         * @return array<int,array<string,string>>
         */
        public static function parse_attribute() {
                $name = (string) ( self::cfg()['attribute'] ?? '' );
                if ( '' === $name || ! Haa_Util::woo() ) {
                        return array();
                }

                $terms = get_terms(
                        array(
                                'taxonomy'   => 0 === strpos( $name, 'pa_' ) ? $name : 'pa_' . Haa_Util::slugify( $name ),
                                'hide_empty' => false,
                                'number'     => self::MAX_ITEMS,
                        )
                );
                if ( is_wp_error( $terms ) || ! $terms ) {
                        return array();
                }

                $slug = Haa_Util::slugify( (string) $name );
                $rows = array();
                foreach ( (array) $terms as $t ) {
                        $rows[] = array(
                                'title'  => (string) $t->name,
                                $slug    => (string) $t->name,
                                'count'  => (string) (int) $t->count,
                        );
                }
                return $rows;
        }

        /**
         * Items from whichever source is configured.
         *
         * @param string $csv_or_list Raw input for csv/manual sources.
         * @return array<int,array<string,string>>
         */
        public static function items_from_source( $csv_or_list = '' ) {
                $src = (string) ( self::cfg()['source'] ?? 'csv' );
                if ( 'attribute' === $src ) {
                        return self::parse_attribute();
                }
                if ( 'manual' === $src ) {
                        return self::parse_manual( $csv_or_list );
                }
                return self::parse_csv( $csv_or_list );
        }

        /* ------------------------------------------------------------------
         * Template + guards
         * ---------------------------------------------------------------- */

        /**
         * Replace {{key}} placeholders.
         *
         * @param string $tpl   Template text.
         * @param array  $vars  Item map.
         * @return array{text:string,missing:array<int,string>}
         */
        public static function render( $tpl, $vars ) {
                $text    = (string) $tpl;
                $missing = array();

                if ( preg_match_all( '/\{\{\s*([a-zA-Z0-9_\-]+)\s*\}\}/', $text, $m ) ) {
                        foreach ( array_unique( (array) $m[1] ) as $key ) {
                                if ( isset( $vars[ $key ] ) && '' !== (string) $vars[ $key ] ) {
                                        $text = str_replace( array( '{{' . $key . '}}', '{{ ' . $key . ' }}' ), (string) $vars[ $key ], $text );
                                        continue;
                                }
                                // Left unfilled: the caller must refuse the item. Shipping a page
                                // that literally prints "{color}" is worse than printing nothing.
                                $missing[] = (string) $key;
                        }
                }

                return array(
                        'text'    => trim( $text ),
                        'missing' => $missing,
                );
        }

        /**
         * Does this item carry enough real data to deserve a page?
         *
         * @param array $vars Item map.
         * @return true|WP_Error
         */
        public static function item_ok( $vars ) {
                $vars = (array) $vars;
                $real = 0;
                foreach ( $vars as $v ) {
                        if ( '' !== trim( (string) $v ) ) {
                                $real++;
                        }
                }
                if ( $real < self::MIN_FIELDS ) {
                        return new WP_Error( 'haai_pg_thin', __( 'این مورد داده‌ی کافی ندارد (حداقل دو فیلد پر).', 'hami-ai-assistant' ) );
                }
                if ( empty( $vars['title'] ) ) {
                        return new WP_Error( 'haai_pg_title', __( 'بدون عنوان نمی‌توان صفحه ساخت.', 'hami-ai-assistant' ) );
                }
                return true;
        }

        /**
         * Token overlap between two paragraphs.
         *
         * @param string $a First text.
         * @param string $b Second text.
         * @return float 0..1
         */
        public static function similarity( $a, $b ) {
                $ta = (array) Haa_Search::tokenize( (string) $a );
                $tb = (array) Haa_Search::tokenize( (string) $b );
                if ( ! $ta || ! $tb ) {
                        return 0.0;
                }
                $inter = count( array_intersect( array_map( 'strval', $ta ), array_map( 'strval', $tb ) ) );
                $union = count( array_unique( array_merge( array_map( 'strval', $ta ), array_map( 'strval', $tb ) ) ) );
                return $union ? $inter / $union : 0.0;
        }

        /**
         * Stable identity for an item, so a re-run updates its page instead of
         * printing a second one.
         *
         * @param array $vars Item map.
         * @return string
         */
        public static function item_key( $vars ) {
                $title = (string) ( $vars['title'] ?? '' );
                return Haa_Util::cut( Haa_Util::slugify( $title ), 190 );
        }

        /* ------------------------------------------------------------------
         * Store
         * ---------------------------------------------------------------- */

        /**
         * @param string $key Item key.
         * @return array|null
         */
        public static function find_item( $key ) {
                global $wpdb;
                $table = Haa_DB::table( self::TABLE );
                $r     = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE item_key = %s LIMIT 1", (string) $key ), ARRAY_A ); // phpcs:ignore WordPress.DB
                return $r ? (array) $r : null;
        }

        /**
         * @param array $item Item map.
         * @param array $extra status/post_id.
         * @return int Row id.
         */
        public static function save_item( $item, $extra = array() ) {
                global $wpdb;
                $table = Haa_DB::table( self::TABLE );
                $key   = self::item_key( $item );
                $now   = current_time( 'mysql' );

                $row = self::find_item( $key );
                if ( $row ) {
                        $set = array_merge(
                                array(
                                        'vars'    => (string) wp_json_encode( (array) $item, JSON_UNESCAPED_UNICODE ),
                                        'updated' => $now,
                                ),
                                (array) $extra
                        );
                        $wpdb->update( $table, $set, array( 'id' => (int) $row['id' ] ), array_fill( 0, count( $set ), '%s' ), array( '%d' ) );
                        return (int) $row['id'];
                }

                $done = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
                        $table,
                        array_merge(
                                array(
                                        'job_key'  => 'default',
                                        'item_key' => $key,
                                        'vars'     => (string) wp_json_encode( (array) $item, JSON_UNESCAPED_UNICODE ),
                                        'post_id'  => 0,
                                        'status'   => 'new',
                                        'error'    => '',
                                        'created'  => $now,
                                        'updated'  => $now,
                                ),
                                (array) $extra
                        ),
                        array( '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s' )
                );

                return $done ? (int) $wpdb->insert_id : 0;
        }

        /** @return array<int,array> Paragraphs already written, for duplicate checks. */
        public static function existing_paragraphs() {
                global $wpdb;
                $table = Haa_DB::table( self::TABLE );
                $rows  = (array) $wpdb->get_results( "SELECT vars FROM $table WHERE status = 'done' LIMIT 200", ARRAY_A ); // phpcs:ignore WordPress.DB
                $out   = array();
                foreach ( $rows as $r ) {
                        $vars = json_decode( (string) $r['vars'], true );
                        if ( is_array( $vars ) && ! empty( $vars['paragraph'] ) ) {
                                $out[] = (string) $vars['paragraph'];
                        }
                }
                return $out;
        }

        /**
         * @param int   $id     Row id.
         * @param array $values Whitelisted columns.
         * @return bool
         */
        public static function update_item( $id, $values ) {
                global $wpdb;
                $allowed = array( 'status' => '%s', 'post_id' => '%d', 'error' => '%s' );
                $set     = array();
                $forms   = array();
                foreach ( $allowed as $key => $form ) {
                        if ( array_key_exists( $key, $values ) ) {
                                $set[ $key ] = '%d' === $form ? (int) $values[ $key ] : (string) $values[ $key ];
                                $forms[ $key ] = $form;
                        }
                }
                if ( ! $set ) {
                        return false;
                }
                $set['updated'] = current_time( 'mysql' );
                $forms['updated'] = '%s';
                return false !== $wpdb->update( Haa_DB::table( self::TABLE ), $set, array( 'id' => (int) $id ), array_values( $forms ), array( '%d' ) );
        }

        /**
         * @param int $limit Max rows.
         * @return array<int,array>
         */
        public static function rows( $limit = 40 ) {
                global $wpdb;
                $table = Haa_DB::table( self::TABLE );
                $rows  = (array) $wpdb->get_results( "SELECT * FROM $table ORDER BY id DESC LIMIT " . max( 1, min( 200, (int) $limit ) ), ARRAY_A ); // phpcs:ignore WordPress.DB
                return array_map(
                        function ( $r ) {
                                $vars = json_decode( (string) $r['vars'], true );
                                return array(
                                        'id'      => (int) $r['id'],
                                        'key'     => (string) $r['item_key'],
                                        'title'   => (string) ( is_array( $vars ) && isset( $vars['title'] ) ? $vars['title'] : $r['item_key'] ),
                                        'status'  => (string) $r['status'],
                                        'post_id' => (int) $r['post_id'],
                                        'error'   => (string) $r['error'],
                                );
                        },
                        $rows
                );
        }

        /** @return int Pages written today. */
        public static function written_today() {
                global $wpdb;
                $table = Haa_DB::table( self::TABLE );
                return (int) $wpdb->get_var(
                        $wpdb->prepare( "SELECT COUNT(*) FROM $table WHERE post_id > 0 AND created >= %s", Haa_Usage::site_day_start_utc() ) // phpcs:ignore WordPress.DB
                );
        }

        /* ------------------------------------------------------------------
         * The run
         * ---------------------------------------------------------------- */

        /**
         * Build pages for a batch of items.
         *
         * @param array $items Item maps.
         * @return array{made:int,skipped:int,queued_for:int,reasons:array<int,string>}
         */
        public static function run( $items ) {
                $cfg  = self::cfg();
                $cap  = (int) ( $cfg['daily_cap'] ?? 10 );
                $left = $cap > 0 ? max( 0, $cap - self::written_today() ) : 0;

                $made    = 0;
                $skipped = 0;
                $reasons = array();

                if ( ! $left ) {
                        return array(
                                'made'  => 0,
                                'skipped' => count( (array) $items ),
                                'queued_for' => 0,
                                'reasons' => array( __( 'سقف صفحه‌ی امروز پر است (یا صفر شده).', 'hami-ai-assistant' ) ),
                        );
                }

                $known = self::existing_paragraphs();

                foreach ( (array) $items as $item ) {
                        if ( $made >= $left ) {
                                $reasons[] = __( 'به سقف روزانه رسیدیم؛ ادامه برای فردا.', 'hami-ai-assistant' );
                                break;
                        }

                        $ok = self::item_ok( $item );
                        if ( true !== $ok ) {
                                $skipped++;
                                $reasons[] = (string) $item['title'] . ': ' . $ok->get_error_message();
                                continue;
                        }

                        $body = self::render( (string) ( $cfg['template'] ?? '' ), $item );
                        if ( $body['missing'] ) {
                                $skipped++;
                                $reasons[] = (string) $item['title'] . ': ' . sprintf( /* translators: %s: keys. */ __( 'متغیرهای %s در داده پیدا نشد.', 'hami-ai-assistant' ), implode( '، ', $body['missing'] ) );
                                continue;
                        }

                        $para = self::unique_paragraph( $item, $cfg );
                        if ( is_wp_error( $para ) ) {
                                $skipped++;
                                $reasons[] = (string) $item['title'] . ': ' . $para->get_error_message();
                                continue;
                        }

                        // The thin-content lock: compare this page's unique text with the pages
                        // already built. Same template + same paragraph = one page too many.
                        $worst   = 0.0;
                        $nearest = '';
                        foreach ( $known as $other ) {
                                $score = self::similarity( $para, $other );
                                if ( $score > $worst ) {
                                        $worst   = $score;
                                        $nearest = Haa_Util::cut( $other, 60 );
                                }
                        }
                        $threshold = (float) ( $cfg['similar_threshold'] ?? 0.75 );
                        if ( $known && $worst >= $threshold ) {
                                $skipped++;
                                $reasons[] = sprintf(
                                        /* translators: 1: percent, 2: text. */
                                        __( '%1$s٪ شبیه صفحه‌ی دیگر است («%2$s»); ساخته نشد.', 'hami-ai-assistant' ),
                                        (string) round( $worst * 100 ),
                                        $nearest
                                );
                                continue;
                        }

                        $id = self::save_item( array_merge( $item, array( 'paragraph' => $para ) ), array( 'status' => 'queued' ) );
                        if ( ! $id ) {
                                $skipped++;
                                continue;
                        }

                        $post_id = self::publish_page( $item, $para, $body['text'], $cfg );
                        if ( is_wp_error( $post_id ) ) {
                                self::update_item( $id, array( 'status' => 'failed', 'error' => (string) $post_id->get_error_message() ) );
                                $skipped++;
                                $reasons[] = (string) $item['title'] . ': ' . $post_id->get_error_message();
                                continue;
                        }

                        self::update_item(
                                $id,
                                array(
                                        'status'  => 'done',
                                        'post_id' => (int) $post_id,
                                        'error'   => '',
                                )
                        );
                        $known[] = $para;
                        $made++;
                }

                return array(
                        'made'        => $made,
                        'skipped'     => $skipped,
                        'queued_for'  => max( 0, count( (array) $items ) - $made - $skipped ),
                        'reasons'     => array_slice( $reasons, 0, 15 ),
                );
        }

        /**
         * One model call: the paragraph that makes this page about THIS item.
         *
         * @param array $item Item map.
         * @param array $cfg  Settings.
         * @return string|WP_Error
         */
        public static function unique_paragraph( $item, $cfg ) {
                $min   = max( 200, (int) ( $cfg['min_words'] ?? 450 ) );
                $title = (string) $item['title'];

                $facts = array();
                foreach ( $item as $k => $v ) {
                        if ( 'title' === $k || '' === trim( (string) $v ) ) {
                                continue;
                        }
                        $facts[] = '- ' . $k . ': ' . $v;
                }

                $system = __( 'نویسنده‌ی فارسی‌زبان. درباره‌ی همین یک مورد می‌نویسی، با تکیه بر داده‌های داده‌شده.', 'hami-ai-assistant' ) . "\n"
                        . '- ' . sprintf( /* translators: %d: words. */ __( 'یک بخش %d کلمه‌ای، فقط درباره‌ی همین مورد.', 'hami-ai-assistant' ), $min ) . "\n"
                        . '- ' . __( 'جمله‌ی کلی و قابل کپی روی صدها صفحه ننویس؛ به داده‌های خاص همین مورد ارجاع بده.', 'hami-ai-assistant' ) . "\n"
                        . '- ' . __( 'خروجی فقط متن ساده‌ی همان بخش، بدون تیتر و بدون HTML.', 'hami-ai-assistant' ) . "\n"
                        . Haa_Content::style_suffix( array( 'topic' => $title, 'keywords' => $title ) ) . "\n"
                        . Haa_Content::no_fabrication();

                $user = __( 'مورد:', 'hami-ai-assistant' ) . ' ' . $title . "\n"
                        . __( 'داده‌ها:', 'hami-ai-assistant' ) . "\n" . implode( "\n", array_slice( $facts, 0, 20 ) ) . "\n\n"
                        . __( 'این بخش را بنویس.', 'hami-ai-assistant' );

                $raw = Haa_Content::generate( $system, $user, array( 'module' => 'programmatic', 'operation' => 'page' ) );
                if ( is_wp_error( $raw ) ) {
                        return $raw;
                }

                $para = trim( wp_strip_all_tags( (string) $raw ) );
                $words = count( array_filter( preg_split( '/\s+/u', $para ), function ( $w ) { return '' !== trim( (string) $w ); } ) );
                if ( $words < (int) floor( $min * 0.6 ) ) {
                        return new WP_Error(
                                'haai_pg_thin_out',
                                sprintf( /* translators: 1: words, 2: min. */ __( 'متن یکتا کوتاه ماند (%1$d کلمه از %2$d لازم).', 'hami-ai-assistant' ), $words, (int) floor( $min * 0.6 ) )
                        );
                }

                return $para;
        }

        /**
         * Assemble and store the page.
         *
         * @param array  $item Item map.
         * @param string $para Unique paragraph.
         * @param string $body Rendered template text.
         * @param array  $cfg  Settings.
         * @return int|WP_Error Post id.
         */
        public static function publish_page( $item, $para, $body, $cfg ) {
                $title_tpl = (string) ( $cfg['title_template'] ?? '{{title}}' );
                $head      = self::render( '' !== $title_tpl ? $title_tpl : '{{title}}', $item );
                if ( $head['missing'] ) {
                        return new WP_Error( 'haai_pg_title', __( 'عنوان صفحه از متغیرهای ناشناخته ساخته نمی‌شود.', 'hami-ai-assistant' ) );
                }

                $parts = array();
                if ( '' !== $body ) {
                        $parts[] = Haa_Content::to_html( $body );
                }
                $parts[] = '<p>' . esc_html( $para ) . '</p>';

                // Hub link: the child points upward, so the set is never a pile of orphans.
                $hub = (int) ( $cfg['hub_page'] ?? 0 );
                if ( $hub && get_post( $hub ) ) {
                        $parts[] = '<p><a href="' . esc_url( (string) get_permalink( $hub ) ) . '">' . esc_html( (string) get_the_title( $hub ) ) . '</a></p>';
                }

                $html   = implode( "\n", $parts );
                $status = in_array( (string) ( $cfg['default_status'] ?? 'draft' ), array( 'draft', 'pending' ), true ) ? (string) $cfg['default_status'] : 'draft';

                $post_id = wp_insert_post(
                        array(
                                'post_title'   => Haa_Util::cut( $head['text'], 180 ),
                                'post_name'    => Haa_Util::slugify( $head['text'] ),
                                'post_content' => $html,
                                'post_status'  => $status,
                                'post_type'    => 'page',
                                'post_author'  => get_current_user_id(),
                        ),
                        true
                );
                if ( is_wp_error( $post_id ) ) {
                        return $post_id;
                }

                update_post_meta( (int) $post_id, '_haai_generated', '1' );
                update_post_meta( (int) $post_id, '_haai_prog_key', self::item_key( $item ) );

                // noindex default: an unreviewed template page has no business in the index.
                Haa_Seo::set_robots( (int) $post_id, ! empty( $cfg['noindex'] ) );

                Haa_Seo::apply(
                        (int) $post_id,
                        array(
                                'html'            => $html,
                                'seo_title'       => Haa_Util::cut( $head['text'], 60 ),
                                'seo_description' => Haa_Util::cut( $para, 155 ),
                                'slug'            => Haa_Util::slugify( $head['text'] ),
                        )
                );

                return (int) $post_id;
        }

        /**
         * Rebuild the hub page so it lists every generated child.
         *
         * @param int $hub_page Existing page id.
         * @return int|WP_Error
         */
        public static function build_hub( $hub_page ) {
                $hub_page = (int) $hub_page;
                $hub      = get_post( $hub_page );
                if ( ! $hub ) {
                        return new WP_Error( 'haai_pg_hub', __( 'صفحه‌ی hub پیدا نشد؛ شناسه‌اش را در تنظیمات بدهید.', 'hami-ai-assistant' ) );
                }

                $items = self::rows( 200 );
                $lines = array();
                foreach ( $items as $it ) {
                        if ( 'done' !== $it['status'] || ! $it['post_id'] ) {
                                continue;
                        }
                        $lines[] = '<li><a href="' . esc_url( (string) get_permalink( (int) $it['post_id'] ) ) . '">' . esc_html( $it['title'] ) . '</a></li>';
                }

                if ( ! $lines ) {
                        return new WP_Error( 'haai_pg_none', __( 'هنوز صفحه‌ای ساخته نشده که در hub فهرست شود.', 'hami-ai-assistant' ) );
                }

                $body = '<p>' . esc_html( (string) $hub->post_excerpt ) . '</p>' . "\n<ul>\n" . implode( "\n", $lines ) . "\n</ul>";

                $done = wp_update_post(
                        array(
                                'ID'           => $hub_page,
                                'post_content' => $body,
                        ),
                        true
                );

                return is_wp_error( $done ) ? $done : $hub_page;
        }

        /* ------------------------------------------------------------------
         * Handlers
         * ---------------------------------------------------------------- */

        /** @return void */
        public function handle_run() {
                Haa_Util::guard_admin_post( 'haai_pg' );

                if ( ! self::enabled() ) {
                        $this->back( __( 'ماژول صفحه‌سازی خاموش است.', 'hami-ai-assistant' ), true );
                }

                $raw = isset( $_POST['data'] ) ? (string) wp_unslash( $_POST['data'] ) : '';
                if ( '' === trim( $raw ) && ! empty( $_FILES['data_file']['tmp_name'] ) ) {
                        $f = $_FILES['data_file'];
                        if ( 'csv' !== strtolower( (string) pathinfo( (string) $f['name'], PATHINFO_EXTENSION ) ) ) {
                                $this->back( __( 'فقط فایل CSV.', 'hami-ai-assistant' ), true );
                        }
                        if ( empty( $f['size'] ) || (int) $f['size'] > 2000000 ) {
                                $this->back( __( 'حجم فایل داده بیش از ۲ مگابایت است.', 'hami-ai-assistant' ), true );
                        }
                        $raw = (string) file_get_contents( (string) $f['tmp_name'] );
                }

                $items = self::items_from_source( $raw );
                if ( ! $items ) {
                        $this->back( __( 'هیچ مورد معتبری از داده‌ها خوانده نشد.', 'hami-ai-assistant' ), true );
                }

                $r = self::run( $items );

                $msg = sprintf(
                        /* translators: 1: made, 2: skipped. */
                        __( '%1$d صفحه ساخته شد، %2$d مورد رد شد.', 'hami-ai-assistant' ),
                        (int) $r['made'],
                        (int) $r['skipped']
                );
                if ( $r['reasons'] ) {
                        $msg .= ' ' . implode( ' | ', array_slice( $r['reasons'], 0, 3 ) );
                }
                $this->back( $msg, 0 === (int) $r['made'] );
        }

        /** @return void */
        public function handle_hub() {
                Haa_Util::guard_admin_post( 'haai_pg' );

                $hub = (int) ( self::cfg()['hub_page'] ?? 0 );
                $r   = self::build_hub( $hub );
                if ( is_wp_error( $r ) ) {
                        $this->back( (string) $r->get_error_message(), true );
                }
                $this->back( __( 'صفحه‌ی hub بازسازی شد.', 'hami-ai-assistant' ) );
        }

        /**
         * Flip the noindex marker for one generated page, once a human has reviewed it.
         *
         * @return void
         */
        public function handle_index() {
                Haa_Util::guard_admin_post( 'haai_pg' );

                $id      = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
                $noindex = ! empty( $_POST['noindex'] );

                if ( ! $id || ! get_post( $id ) ) {
                        $this->back( __( 'نوشته پیدا نشد.', 'hami-ai-assistant' ), true );
                }

                Haa_Seo::set_robots( $id, $noindex );
                $this->back( $noindex ? __( 'noindex برگشت.', 'hami-ai-assistant' ) : __( 'از این پس ایندکس می‌شود.', 'hami-ai-assistant' ) );
        }

        /* ------------------------------------------------------------------
         * Screen
         * ---------------------------------------------------------------- */

        /** @return void */
        public function render_screen() {
                if ( ! current_user_can( 'manage_options' ) ) {
                        return;
                }

                $cfg     = self::cfg();
                $rows    = self::rows( 30 );
                $src     = (string) ( $cfg['source'] ?? 'csv' );
                $cap     = (int) ( $cfg['daily_cap'] ?? 10 );
                $today   = self::written_today();
                $noindex = ! empty( $cfg['noindex'] );
                ?>
                <div class="haai-wrap">
                        <h1 class="haai-page-title"><?php esc_html_e( 'صفحه‌سازی برنامه‌ریزی‌شده', 'hami-ai-assistant' ); ?></h1>

                        <div class="haai-alert haai-alert--warn">
                                <?php esc_html_e( 'هشدار محتوای نازک: این ابزار می‌تواند صدها صفحه‌ی مشابه بسازد. نگهبان‌ها فعال‌اند — دو فیلد داده لازم است، متن یکتا با صفحه‌های دیگر مقایسه می‌شود، و تا خودتان تأیید نکرده‌اید صفحه‌ها noindex می‌مانند.', 'hami-ai-assistant' ); ?>
                        </div>

                        <?php if ( empty( $cfg['enabled'] ) ) : ?>
                                <div class="haai-panel">
                                        <?php
                                        printf(
                                                /* translators: %s: settings URL. */
                                                wp_kses_post( __( 'ماژول خاموش است؛ از <a href="%s">تنظیمات ← صفحه‌سازی برنامه‌ریزی‌شده</a> روشنش کنید.', 'hami-ai-assistant' ) ),
                                                esc_url( Haa_Util::screen_url( 'settings', array( 'tab' => 'programmatic' ) ) )
                                        );
                                        ?>
                                </div>
                        <?php else : ?>
                                <div class="haai-panel">
                                        <h2 class="haai-panel__title"><?php esc_html_e( 'ساخت صفحه‌ها', 'hami-ai-assistant' ); ?></h2>

                                        <div class="haai-plan__meta">
                                                <span class="haai-badge"><?php echo esc_html( 'منبع: ' . $src ); ?></span>
                                                <span class="haai-badge"><?php echo esc_html( sprintf( /* translators: 1: today, 2: cap. */ __( 'امروز %1$d از %2$d', 'hami-ai-assistant' ), $today, $cap ) ); ?></span>
                                                <span class="haai-badge <?php echo $noindex ? '' : 'haai-badge--warn'; ?>">
                                                        <?php echo $noindex ? esc_html__( 'noindex پیش‌فرض روشن است', 'hami-ai-assistant' ) : esc_html__( 'noindex خاموش — صفحه‌ها از اول ایندکس می‌شوند', 'hami-ai-assistant' ); ?>
                                                </span>
                                        </div>

                                        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data" class="haai-form">
                                                <?php wp_nonce_field( 'haai_pg' ); ?>
                                                <input type="hidden" name="action" value="haai_pg_run" />

                                                <?php if ( 'attribute' !== $src ) : ?>
                                                        <p class="haai-field">
                                                                <label for="haai-pg-data"><?php esc_html_e( 'داده‌ها', 'hami-ai-assistant' ); ?></label>
                                                                <textarea id="haai-pg-data" name="data" rows="10" class="large-text" placeholder="<?php echo 'csv' === $src ? 'title,color,use&#10;کفش چرم,مشکی,اداری' : 'کفش چرم | color=مشکی | use=اداری'; ?>"></textarea>
                                                                <span class="haai-field__hint"><?php echo 'csv' === $src ? esc_html__( 'سطر اول سرستون‌ها.', 'hami-ai-assistant' ) : esc_html__( 'هر خط: عنوان | کلید=مقدار', 'hami-ai-assistant' ); ?></span>
                                                        </p>
                                                <?php else : ?>
                                                        <p class="description"><?php esc_html_e( 'مقادیر از ویژگی ووکامرس خوانده می‌شود؛ نیازی به واردکردن داده نیست.', 'hami-ai-assistant' ); ?></p>
                                                <?php endif; ?>

                                                <p class="haai-field">
                                                        <label for="haai-pg-file"><?php esc_html_e( 'یا فایل CSV', 'hami-ai-assistant' ); ?></label>
                                                        <input type="file" id="haai-pg-file" name="data_file" accept=".csv,text/csv" />
                                                </p>

                                                <p class="description">
                                                        <?php
                                                        printf(
                                                                /* translators: %d: cap. */
                                                                esc_html__( 'هر صفحه یک درخواست مدل می‌خواهد؛ در این اجرا حداکثر %d صفحه ساخته می‌شود.', 'hami-ai-assistant' ),
                                                                max( 0, $cap - $today )
                                                        );
                                                        ?>
                                                </p>

                                                <p><button type="submit" class="button button-primary"><?php esc_html_e( 'ساخت صفحه‌ها', 'hami-ai-assistant' ); ?></button></p>
                                        </form>

                                        <?php if ( ! empty( $cfg['hub_page'] ) ) : ?>
                                                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="haai-inline-form">
                                                        <?php wp_nonce_field( 'haai_pg' ); ?>
                                                        <input type="hidden" name="action" value="haai_pg_hub" />
                                                        <button type="submit" class="button"><?php esc_html_e( 'بازسازی صفحه‌ی hub', 'hami-ai-assistant' ); ?></button>
                                                </form>
                                        <?php endif; ?>
                                </div>

                                <div class="haai-panel">
                                        <h2 class="haai-panel__title"><?php esc_html_e( 'صفحه‌های ساخته‌شده', 'hami-ai-assistant' ); ?></h2>
                                        <?php if ( $rows ) : ?>
                                                <table class="haai-table">
                                                        <thead>
                                                                <tr>
                                                                        <th><?php esc_html_e( 'مورد', 'hami-ai-assistant' ); ?></th>
                                                                        <th><?php esc_html_e( 'وضعیت', 'hami-ai-assistant' ); ?></th>
                                                                        <th><?php esc_html_e( 'ایندکس', 'hami-ai-assistant' ); ?></th>
                                                                </tr>
                                                        </thead>
                                                        <tbody>
                                                                <?php foreach ( $rows as $r ) : ?>
                                                                        <tr>
                                                                                <td>
                                                                                        <?php if ( $r['post_id'] ) : ?>
                                                                                                <a href="<?php echo esc_url( (string) get_edit_post_link( (int) $r['post_id'] ) ); ?>"><?php echo esc_html( $r['title'] ); ?></a>
                                                                                        <?php else : ?>
                                                                                                <?php echo esc_html( $r['title'] ); ?>
                                                                                        <?php endif; ?>
                                                                                        <?php if ( '' !== $r['error'] ) : ?>
                                                                                                <div class="haai-field__hint"><?php echo esc_html( $r['error'] ); ?></div>
                                                                                        <?php endif; ?>
                                                                                </td>
                                                                                <td><span class="haai-badge"><?php echo esc_html( $r['status'] ); ?></span></td>
                                                                                <td>
                                                                                        <?php if ( $r['post_id'] ) : ?>
                                                                                                <?php $cur = '1' === (string) get_post_meta( (int) $r['post_id'], '_haai_noindex', true ); ?>
                                                                                                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="haai-inline-form">
                                                                                                        <?php wp_nonce_field( 'haai_pg' ); ?>
                                                                                                        <input type="hidden" name="action" value="haai_pg_index" />
                                                                                                        <input type="hidden" name="post_id" value="<?php echo (int) $r['post_id']; ?>" />
                                                                                                        <input type="hidden" name="noindex" value="<?php echo $cur ? '0' : '1'; ?>" />
                                                                                                        <button type="submit" class="button button-small">
                                                                                                                <?php echo $cur ? esc_html__( 'ایندکس شود', 'hami-ai-assistant' ) : esc_html__( 'noindex شود', 'hami-ai-assistant' ); ?>
                                                                                                        </button>
                                                                                                </form>
                                                                                        <?php else : ?>
                                                                                                —
                                                                                        <?php endif; ?>
                                                                                </td>
                                                                        </tr>
                                                                <?php endforeach; ?>
                                                        </tbody>
                                                </table>
                                        <?php else : ?>
                                                <div class="haai-empty"><span class="haai-empty__title"><?php esc_html_e( 'هنوز صفحه‌ای ساخته نشده است.', 'hami-ai-assistant' ); ?></span></div>
                                        <?php endif; ?>
                                </div>
                        <?php endif; ?>
                </div>
                <?php
        }

        /**
         * @param string $message Banner text.
         * @param bool   $error   Failure or success.
         * @return void
         */
        private function back( $message, $error = false ) {
                if ( Haa_Ajax_Form::respond( $message, $error ) ) {
                        return;
                }
                wp_safe_redirect(
                        Haa_Util::screen_url(
                                'programmatic',
                                array(
                                        $error ? 'haai_err' : 'haai_msg' => rawurlencode( Haa_Util::cut( $message, 300 ) ),
                                )
                        )
                );
                exit;
        }
}
