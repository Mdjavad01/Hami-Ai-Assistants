<?php
/**
 * News module (مرحله D4) — RSS/Atom feeds into original short articles.
 *
 * The rule that shapes this file: we publish OUR words about the story, never
 * their words. Only the title, the link and the publication time are taken from
 * the feed; the summary is written from the site's own context plus the headline
 * — and if quote_source is on, at most a short attributed excerpt is passed to
 * the model as context, which is still not pasted into the post.
 *
 * Re-running must not re-publish: every item is remembered by guid, and again by
 * a normalised title hash for feeds that mint a fresh guid when an item is edited.
 *
 * @package Hami_AI_Assistant
 */

if ( ! defined( 'ABSPATH' ) ) {
        exit;
}

final class Haa_Module_News implements Haa_Module_Interface {

        const TABLE = 'news_items';
        const CRON  = 'haai_news_tick';

        /** Hard ceiling on items parsed out of one feed. */
        const FEED_ITEMS = 40;

        /** @return string */
        public function slug() { return 'news'; }

        /** @return string */
        public function label() { return __( 'اخبار', 'hami-ai-assistant' ); }

        /** @return string */
        public function description() { return __( 'فیدهای RSS را می‌خواند و برای هر خبر یک خلاصه و تحلیل تازه می‌نویسد؛ منبع و لینک ذکر می‌شود و متن کپی نمی‌شود.', 'hami-ai-assistant' ); }

        /** @return bool */
        public function requires_woocommerce() { return Haa_Modules::requires_woocommerce( $this->slug() ); }

        /** @return void */
        public function register() {
                add_action( 'haai_settings_sections', array( $this, 'register_section' ), 10 );
                add_action( 'haai_settings_fields_news', array( $this, 'register_fields' ) );
                add_action( 'haai_admin_menus', array( $this, 'register_menu' ) );

                add_action( 'admin_post_haai_news_run', array( $this, 'handle_run' ) );
                add_action( 'admin_post_haai_news_status', array( $this, 'handle_status' ) );
                add_action( 'admin_post_haai_news_forget', array( $this, 'handle_forget' ) );

                add_action( 'haai_run_job_news_item', array( $this, 'run_job' ), 10, 2 );
                add_action( self::CRON, array( $this, 'cron_run' ) );
        }

        /** @return void */
        public function activate() {
                Haa_DB::create();
                if ( ! wp_next_scheduled( self::CRON ) ) {
                        wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', self::CRON );
                }
        }

        /** @return void */
        public function deactivate() {
                wp_clear_scheduled_hook( self::CRON );
        }

        /**
         * @param array $sections Sections so far.
         * @return array
         */
        public function register_section( $sections ) {
                $sections['news'] = __( 'اخبار', 'hami-ai-assistant' );
                return $sections;
        }

        /**
         * Field list, also read by Haa_Settings::collect_ui_keys() logic in tests.
         *
         * @param array $fields Fields so far.
         * @return array
         */
        public function register_fields( $fields ) {
                $fields[] = array( 'key' => 'news.enabled', 'type' => 'checkbox', 'label' => __( 'خواندن فیدها', 'hami-ai-assistant' ) );
                $fields[] = array( 'key' => 'news.feeds', 'type' => 'textarea', 'label' => __( 'آدرس فیدها', 'hami-ai-assistant' ), 'hint' => __( 'هر خط یک آدرس RSS یا Atom. حداکثر ۱۲ فید.', 'hami-ai-assistant' ) );
                $fields[] = array( 'key' => 'news.max_per_run', 'type' => 'number', 'label' => __( 'حداکثر خبر در هر اجرا', 'hami-ai-assistant' ), 'min' => 1, 'max' => 20 );
                $fields[] = array( 'key' => 'news.daily_cap', 'type' => 'number', 'label' => __( 'سقف مقاله‌ی خبری روزانه', 'hami-ai-assistant' ), 'min' => 0, 'max' => 50, 'hint' => __( '۰ = هیچ خبری نوشته نشود.', 'hami-ai-assistant' ) );
                $fields[] = array( 'key' => 'news.lookback_hours', 'type' => 'number', 'label' => __( 'فقط خبرهای چند ساعت اخیر', 'hami-ai-assistant' ), 'min' => 1, 'max' => 168 );
                $fields[] = array( 'key' => 'news.default_status', 'type' => 'select', 'label' => __( 'وضعیت پیش‌فرض', 'hami-ai-assistant' ), 'choices' => array( 'draft' => __( 'پیش‌نویس', 'hami-ai-assistant' ), 'pending' => __( 'در انتظار بررسی', 'hami-ai-assistant' ) ) );
                $fields[] = array( 'key' => 'news.default_category', 'type' => 'number', 'label' => __( 'دسته‌ی پیش‌فرض (۰ = بدون دسته)', 'hami-ai-assistant' ), 'min' => 0, 'max' => 999999 );
                $fields[] = array( 'key' => 'news.min_words', 'type' => 'number', 'label' => __( 'حداقل کلمه برای هر خبر', 'hami-ai-assistant' ), 'min' => 150, 'max' => 2000 );
                $fields[] = array( 'key' => 'news.quote_source', 'type' => 'checkbox', 'label' => __( 'چکیده‌ی منبع به عنوان زمینه به مدل داده شود', 'hami-ai-assistant' ), 'hint' => __( 'فقط برای فهم موضوع؛ متن منبع در مقاله نمی‌آید.', 'hami-ai-assistant' ) );
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
                        __( 'اخبار', 'hami-ai-assistant' ),
                        __( 'اخبار', 'hami-ai-assistant' ),
                        'manage_options',
                        Haa_Util::screen( 'news' ),
                        array( $this, 'render_screen' )
                );
        }

        /* ------------------------------------------------------------------
         * Config
         * ---------------------------------------------------------------- */

        /** @return array */
        private static function cfg() {
                $s = Haa_Settings::get();
                return isset( $s['news'] ) && is_array( $s['news'] ) ? $s['news'] : array();
        }

        /** @return bool */
        public static function enabled() {
                return ! empty( self::cfg()['enabled'] );
        }

        /** @return array<int,string> */
        public static function feeds() {
                $raw = (string) ( self::cfg()['feeds'] ?? '' );
                $out = array();
                foreach ( preg_split( '/\R+/u', $raw ) as $line ) {
                        $line = trim( $line );
                        if ( '' !== $line ) {
                                $out[] = $line;
                        }
                }
                return array_slice( $out, 0, 12 );
        }

        /* ------------------------------------------------------------------
         * Feed parsing (no external XML library, no full-text storage)
         * ---------------------------------------------------------------- */

        /**
         * Items from an RSS 2 or Atom document.
         *
         * Returns headline metadata only: title, link, guid, timestamp, and a short
         * excerpt used purely as model context. The excerpt is never written to a post.
         *
         * @param string $xml  Feed body.
         * @param string $feed Feed URL (for the record).
         * @return array<int,array{guid:string,title:string,link:string,published:string,excerpt:string,source:string}>
         */
        public static function parse_feed( $xml, $feed = '' ) {
                $xml = (string) $xml;
                if ( '' === trim( $xml ) ) {
                        return array();
                }

                $out = array();

                // RSS <item> and Atom <entry> share the same shape after this step.
                $blocks = array();
                if ( preg_match_all( '#<item\b[^>]*>(.*?)</item>#is', $xml, $m ) && $m[1] ) {
                        $blocks = $m[1];
                        $atom   = false;
                } elseif ( preg_match_all( '#<entry\b[^>]*>(.*?)</entry>#is', $xml, $m ) && $m[1] ) {
                        $blocks = $m[1];
                        $atom   = true;
                } else {
                        return array();
                }

                $channel_title = '';
                if ( preg_match( '#<title[^>]*>(.*?)</title>#is', $xml, $m ) ) {
                        $channel_title = trim( wp_strip_all_tags( self::unwrap( $m[1] ) ) );
                }

                foreach ( array_slice( $blocks, 0, self::FEED_ITEMS ) as $block ) {
                        $title = self::tag( $block, 'title' );
                        if ( '' === $title ) {
                                continue;
                        }

                        $link = self::atom_link( $block, $atom );
                        if ( '' === $link ) {
                                continue;
                        }

                        $guid = trim( self::tag_raw( $block, $atom ? 'id' : 'guid' ) );
                        if ( '' === $guid ) {
                                $guid = $link; // Feeds without a guid at least have a stable URL.
                        }

                        $when = $atom ? self::tag_raw( $block, 'updated' ) : self::tag_raw( $block, 'pubDate');
                        if ( '' === trim( (string) $when ) ) {
                                $when = self::tag_raw( $block, 'published' );
                        }
                        $ts = strtotime( (string) $when );

                        $out[] = array(
                                'guid'      => Haa_Util::cut( $guid, 190 ),
                                'title'     => Haa_Util::cut( $title, 255 ),
                                'link'      => Haa_Settings::sanitize_url( $link ),
                                'published' => $ts ? gmdate( 'Y-m-d H:i:s', $ts ) : '',
                                // Kept short on purpose: it is context for one prompt, not content.
                                'excerpt'   => Haa_Util::cut( self::tag( $block, $atom ? 'summary' : 'description' ), 500 ),
                                'source'    => Haa_Util::cut( '' !== $channel_title ? $channel_title : (string) wp_parse_url( $feed, PHP_URL_HOST ), 120 ),
                        );
                }

                return $out;
        }

        /**
         * First text value of a tag, CDATA unwrapped and tags removed.
         *
         * @param string $block One item/entry block.
         * @param string $name  Tag name.
         * @return string
         */
        private static function tag( $block, $name ) {
                return trim( wp_strip_all_tags( self::unwrap( self::tag_raw( $block, $name ) ) ) );
        }

        /** @return string Raw inner value of the first such tag. */
        private static function tag_raw( $block, $name ) {
                if ( preg_match( '#<' . preg_quote( (string) $name, '#' ) . '(?:\s[^>]*)?>(.*?)</' . preg_quote( (string) $name, '#' ) . '>#is', (string) $block, $m ) ) {
                        return (string) $m[1];
                }
                return '';
        }

        /** @return string Value with CDATA removed. */
        private static function unwrap( $value ) {
                $value = (string) $value;
                if ( preg_match( '#<!\[CDATA\[(.*?)\]\]>#s', $value, $m ) ) {
                        return (string) $m[1];
                }
                return $value;
        }

        /**
         * Atom carries the URL in an attribute: <link rel="alternate" href="…"/>.
         *
         * @return string
         */
        private static function atom_link( $block, $is_atom ) {
                if ( $is_atom ) {
                        if ( preg_match( '#<link[^>]*rel=["\']alternate["\'][^>]*href=["\']([^"\']+)["\']#i', (string) $block, $m ) ) {
                                return trim( (string) $m[1] );
                        }
                        if ( preg_match( '#<link[^>]*href=["\']([^"\']+)["\']#i', (string) $block, $m ) ) {
                                return trim( (string) $m[1] );
                        }
                        return '';
                }
                return trim( self::unwrap( self::tag_raw( $block, 'link' ) ) );
        }

        /* ------------------------------------------------------------------
         * Store / dedupe
         * ---------------------------------------------------------------- */

        /** @return string Normalised-title fingerprint, stable across a re-edited guid. */
        public static function title_hash( $title ) {
                $t = mb_strtolower( trim( wp_strip_all_tags( (string) $title ) ) );
                $t = preg_replace( '/\s+/u', ' ', (string) $t );
                return md5( (string) $t );
        }

        /**
         * Has this exact item been handled before?
         *
         * @param string $guid       Feed guid.
         * @param string $title      Headline.
         * @param string $title_hash Precomputed hash.
         * @return bool
         */
        public static function seen( $guid, $title, $title_hash ) {
                global $wpdb;
                $table = Haa_DB::table( self::TABLE );

                $existing = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $table WHERE guid = %s LIMIT 1", (string) $guid ) ); // phpcs:ignore WordPress.DB
                if ( $existing ) {
                        return true;
                }

                // A feed that regenerates its guid on every edit would otherwise re-publish
                // the same story forever, so the headline is a second lock.
                $by_title = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $table WHERE title_hash = %s LIMIT 1", (string) $title_hash ) ); // phpcs:ignore WordPress.DB
                return (bool) $by_title;
        }

        /**
         * Record an item. Idempotent by guid.
         *
         * @param array $item  Parsed item.
         * @param array $extra status/post_id overrides.
         * @return int Row id, 0 when it could not be stored.
         */
        public static function record( $item, $extra = array() ) {
                global $wpdb;
                $table = Haa_DB::table( self::TABLE );
                $now   = current_time( 'mysql' );

                $done = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
                        $table,
                        array_merge(
                                array(
                                        'feed'        => Haa_Util::cut( (string) ( $item['feed'] ?? '' ), 190 ),
                                        'guid'        => (string) $item['guid'],
                                        'title_hash'  => self::title_hash( (string) $item['title'] ),
                                        'title'       => (string) $item['title'],
                                        'link'        => (string) ( $item['link'] ?? '' ),
                                        'source_name' => (string) ( $item['source'] ?? '' ),
                                        'published'   => (string) ( $item['published'] ?? '' ),
                                        'status'      => 'new',
                                        'post_id'     => 0,
                                        'created'     => $now,
                                        'updated'     => $now,
                                ),
                                (array) $extra
                        ),
                        array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s' )
                );

                return $done ? (int) $wpdb->insert_id : 0;
        }

        /**
         * @param int $id     Row id.
         * @param array $values Whitelisted columns.
         * @return bool
         */
        public static function update_item( $id, $values ) {
                global $wpdb;
                $allowed = array( 'status' => '%s', 'post_id' => '%d', 'title' => '%s', 'link' => '%s' );
                $set     = array();
                $forms   = array();
                foreach ( $allowed as $key => $form ) {
                        if ( array_key_exists( $key, $values ) ) {
                                $set[ $key ]   = (int) '%d' === $form ? (int) $values[ $key ] : (string) $values[ $key ];
                                $forms[ $key ] = $form;
                        }
                }
                if ( ! $set ) {
                        return false;
                }
                $set['updated'] = current_time( 'mysql' );
                $forms['updated'] = '%s';

                $done = $wpdb->update( Haa_DB::table( self::TABLE ), $set, array( 'id' => (int) $id ), array_values( $forms ), array( '%d' ) );
                return false !== $done;
        }

        /**
         * @param string $status Optional filter.
         * @param int    $limit  Max rows.
         * @return array<int,array>
         */
        public static function items( $status = '', $limit = 30 ) {
                global $wpdb;
                $table = Haa_DB::table( self::TABLE );
                $limit = max( 1, min( 100, (int) $limit ) );

                if ( '' !== $status && in_array( $status, array( 'new', 'queued', 'done', 'skipped', 'failed' ), true ) ) {
                        $rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $table WHERE status = %s ORDER BY id DESC LIMIT %d", $status, $limit ), ARRAY_A ); // phpcs:ignore WordPress.DB
                } else {
                        $rows = $wpdb->get_results( "SELECT * FROM $table ORDER BY id DESC LIMIT " . $limit, ARRAY_A ); // phpcs:ignore WordPress.DB
                }

                return array_map(
                        function ( $r ) {
                                return array(
                                        'id'        => (int) $r['id'],
                                        'title'     => (string) $r['title'],
                                        'link'      => (string) $r['link'],
                                        'source'    => (string) $r['source_name'],
                                        'published' => (string) $r['published'],
                                        'status'    => (string) $r['status'],
                                        'post_id'   => (int) $r['post_id'],
                                        'feed'      => (string) $r['feed'],
                                );
                        },
                        (array) $rows
                );
        }

        /**
         * @return array<string,int> status => count
         */
        public static function counts() {
                global $wpdb;
                $table = Haa_DB::table( self::TABLE );
                $rows  = (array) $wpdb->get_results( "SELECT status, COUNT(*) AS n FROM $table GROUP BY status", ARRAY_A ); // phpcs:ignore WordPress.DB
                $out   = array();
                foreach ( $rows as $r ) {
                        $out[ (string) $r['status'] ] = (int) $r['n'];
                }
                return $out;
        }

        /* ------------------------------------------------------------------
         * The run
         * ---------------------------------------------------------------- */

        /**
         * Poll every feed, remember what is new, queue a job per new item.
         *
         * @param bool $force Ignore the "enabled" switch for a manual run.
         * @return array{fetched:int,fresh:int,queued:int,skipped:int,errors:array<int,string>}
         */
        public static function poll( $force = false ) {
                if ( ! $force && ! self::enabled() ) {
                        return array( 'fetched' => 0, 'fresh' => 0, 'queued' => 0, 'skipped' => 0, 'errors' => array( __( 'ماژول اخبار خاموش است.', 'hami-ai-assistant' ) ) );
                }

                $cfg     = self::cfg();
                $max     = max( 1, min( 20, (int) ( $cfg['max_per_run'] ?? 5 ) ) );
                $daily   = (int) ( $cfg['daily_cap'] ?? 3 );
                $window  = max( 1, (int) ( $cfg['lookback_hours'] ?? 48 ) ) * HOUR_IN_SECONDS;

                // daily_cap = 0 means "read the feeds but write nothing".
                if ( $daily <= 0 ) {
                        return array( 'fetched' => 0, 'fresh' => 0, 'queued' => 0, 'skipped' => 0, 'errors' => array( __( 'سقف مقاله‌ی خبری روزانه روی صفر است؛ چیزی نوشته نمی‌شود.', 'hami-ai-assistant' ) ) );
                }

                $written_today = self::posts_today();
                $room          = max( 0, $daily - $written_today );
                if ( ! $room ) {
                        return array( 'fetched' => 0, 'fresh' => 0, 'queued' => 0, 'skipped' => 0, 'errors' => array( __( 'سقف خبری امروز پر شده است.', 'hami-ai-assistant' ) ) );
                }

                $fetched = 0;
                $fresh   = 0;
                $queued  = 0;
                $skipped = 0;
                $errors  = array();

                foreach ( self::feeds() as $feed ) {
                        if ( $queued >= $room ) {
                                break;
                        }

                        $body = Haa_Research::get( $feed, array( 'accept' => 'application/rss+xml,application/atom+xml,application/xml,text/xml;q=0.9,*/*;q=0.4' ) );
                        if ( is_wp_error( $body ) ) {
                                // One dead feed must not stop the rest, and the admin gets to see why.
                                $errors[] = (string) ( wp_parse_url( $feed, PHP_URL_HOST ) ?: $feed ) . ': ' . $body->get_error_message();
                                continue;
                        }

                        $items = self::parse_feed( (string) $body, $feed );
                        if ( ! $items ) {
                                $errors[] = (string) ( wp_parse_url( $feed, PHP_URL_HOST ) ?: $feed ) . ': ' . __( 'فید خالی یا ناخوانا بود.', 'hami-ai-assistant' );
                                continue;
                        }

                        foreach ( $items as $item ) {
                                $fetched++;
                                if ( $queued >= $room ) {
                                        break;
                                }

                                $hash = self::title_hash( $item['title'] );
                                if ( self::seen( $item['guid'], $item['title'], $hash ) ) {
                                        $skipped++;
                                        continue;
                                }

                                // A feed can be years of archive; only the recent window is news.
                                if ( ! empty( $item['published'] ) && strtotime( $item['published'] ) < time() - $window ) {
                                        $skipped++;
                                        continue;
                                }

                                $id = self::record( array_merge( $item, array( 'feed' => $feed ) ) );
                                if ( ! $id ) {
                                        $skipped++;
                                        continue;
                                }
                                $fresh++;

                                $job = Haa_Queue::push(
                                        'news_item',
                                        array(
                                                'item_id' => $id,
                                                'title'   => (string) $item['title'],
                                                'link'    => (string) $item['link'],
                                                'source'  => (string) $item['source'],
                                                'excerpt' => (string) $item['excerpt'],
                                        ),
                                        array( 'module' => 'news', 'priority' => 5 )
                                );
                                if ( $job ) {
                                        self::update_item( $id, array( 'status' => 'queued' ) );
                                        $queued++;
                                } else {
                                        self::update_item( $id, array( 'status' => 'failed' ) );
                                        $errors[] = __( 'صف دریافت نکرد؛ بعداً دوباره امتحان کنید.', 'hami-ai-assistant' );
                                }
                        }
                }

                return array( 'fetched' => $fetched, 'fresh' => $fresh, 'queued' => $queued, 'skipped' => $skipped, 'errors' => array_slice( $errors, 0, 10 ) );
        }

        /**
         * News posts written since the start of the site's day.
         *
         * @return int
         */
        public static function posts_today() {
                global $wpdb;
                $table = Haa_DB::table( self::TABLE );
                $from  = Haa_Usage::site_day_start_utc();
                return (int) $wpdb->get_var(
                        $wpdb->prepare( "SELECT COUNT(*) FROM $table WHERE post_id > 0 AND created >= %s", $from ) // phpcs:ignore WordPress.DB
                );
        }

        /** @return void */
        public function cron_run() {
                if ( ! self::enabled() ) {
                        return;
                }
                $this->poll( false );
        }

        /* ------------------------------------------------------------------
         * Per-item generation
         * ---------------------------------------------------------------- */

        /**
         * Write one original news note.
         *
         * @param int   $job     Queue row id (unused, for signature parity).
         * @param array $payload Item payload.
         * @return void
         */
        public function run_job( $job, $payload ) {
                $title = trim( (string) ( $payload['title'] ?? '' ) );
                if ( '' === $title ) {
                        $GLOBALS['haai_job_result'] = new WP_Error( 'haai_news_title', __( 'خبر بی‌عنوان بود.', 'hami-ai-assistant' ) );
                        return;
                }

                $cfg   = self::cfg();
                $item  = (int) ( $payload['item_id'] ?? 0 );
                $min_w = max( 150, (int) ( $cfg['min_words'] ?? 350 ) );

                $system = __( 'خبرنگار و تحلیل‌گر محتوای فارسی. یک خبر را با کلمه‌های خودت گزارش می‌دهی و اثر آن را برای مخاطب این سایت توضیح می‌دهی.', 'hami-ai-assistant' ) . "\n"
                        . __( 'خروجی فقط HTML معتبر: یک <p> مقدمه، سپس <h2> و <p> برای دو یا سه بخش تحلیل. بدون h1، بدون کد فنس، بدون لینک به جز لینکی که پایین اضافه می‌کنیم.', 'hami-ai-assistant' ) . "\n"
                        . Haa_Content::style_suffix( array( 'topic' => $title, 'keywords' => $title ) ) . "\n"
                        . '- ' . sprintf( /* translators: %d: words. */ __( 'حداقل %d کلمه.', 'hami-ai-assistant' ), $min_w ) . "\n"
                        . '- ' . __( 'جمله‌های منبع را کپی یا تقریباً بازنویسی نکن؛ خروجی باید نوشته‌ی خودت باشد.', 'hami-ai-assistant' ) . "\n"
                        . '- ' . __( 'هیچ عدد، تاریخ، نام یا جزئیاتی که در خبر داده نشده اضافه نکن.', 'hami-ai-assistant' ) . "\n"
                        . Haa_Content::no_fabrication();

                $parts = array( Haa_Site_Profile::block( $title ) );
                $parts[] = __( 'تیتر خبر:', 'hami-ai-assistant' ) . ' ' . $title . "\n"
                        . __( 'منبع:', 'hami-ai-assistant' ) . ' ' . (string) ( $payload['source'] ?? '' ) . "\n"
                        . __( 'زمان انتشار خبر:', 'hami-ai-assistant' ) . ' ' . (string) ( $payload['published'] ?? '' );

                // Context only, never copied into the post, and off by default.
                if ( ! empty( $cfg['quote_source'] ) && ! empty( $payload['excerpt'] ) ) {
                        $parts[] = __( 'چکیده‌ی منبع (فقط برای فهم موضوع؛ بازنویسی نکن و نقل نکن):', 'hami-ai-assistant' ) . "\n"
                                . Haa_Util::cut( wp_strip_all_tags( (string) $payload['excerpt'] ), 600 );
                }

                $parts[] = __( 'حالا گزارش و تحلیل را بنویس.', 'hami-ai-assistant' );

                $body = Haa_Content::generate( $system, implode( "\n\n", array_filter( $parts ) ), array( 'module' => 'news', 'operation' => 'news' ) );
                if ( is_wp_error( $body ) ) {
                        if ( $item ) {
                                self::update_item( $item, array( 'status' => 'failed' ) );
                        }
                        $GLOBALS['haai_job_result'] = $body;
                        return;
                }

                $html = Haa_Content::to_html( (string) $body );
                if ( '' === trim( wp_strip_all_tags( $html ) ) ) {
                        if ( $item ) {
                                self::update_item( $item, array( 'status' => 'failed' ) );
                        }
                        $GLOBALS['haai_job_result'] = new WP_Error( 'haai_news_empty', __( 'مدل متن خالی برگرداند.', 'hami-ai-assistant' ) );
                        return;
                }

                // Attribution is mandatory: the reader must be able to reach the source.
                $link = Haa_Settings::sanitize_url( (string) ( $payload['link'] ?? '' ) );
                if ( '' !== $link ) {
                        $html .= "\n<p class=\"haai-src\">"
                                . sprintf(
                                        /* translators: %s: source name. */
                                        esc_html__( 'منبع خبر: %s', 'hami-ai-assistant' ),
                                        '<a href="' . esc_url( $link ) . '" rel="nofollow noopener" target="_blank">' . esc_html( (string) ( $payload['source'] ?? $link ) ) . '</a>'
                                )
                                . '</p>';
                }

                $status = in_array( (string) ( $cfg['default_status'] ?? 'draft' ), array( 'draft', 'pending' ), true ) ? (string) $cfg['default_status'] : 'draft';
                $cat    = (int) ( $cfg['default_category'] ?? 0 );

                $post_id = wp_insert_post(
                        array(
                                'post_title'   => Haa_Util::cut( $title, 180 ),
                                'post_content' => $html,
                                'post_status'  => $status,
                                'post_type'    => 'post',
                                'post_author'  => get_current_user_id(),
                                'post_category' => $cat ? array( $cat ) : array(),
                        ),
                        true
                );

                if ( is_wp_error( $post_id ) ) {
                        if ( $item ) {
                                self::update_item( $item, array( 'status' => 'failed' ) );
                        }
                        $GLOBALS['haai_job_result'] = $post_id;
                        return;
                }

                update_post_meta( (int) $post_id, '_haai_generated', '1' );
                update_post_meta( (int) $post_id, '_haai_news_source', $link );

                // A news note is not an SEO article; only title/description are worth setting.
                Haa_Seo::apply(
                        (int) $post_id,
                        array(
                                'html'            => $html,
                                'seo_title'       => Haa_Util::cut( $title, 60 ),
                                'seo_description' => Haa_Util::cut( wp_strip_all_tags( $html ), 160 ),
                        )
                );

                if ( $item ) {
                        self::update_item(
                                $item,
                                array(
                                        'status'  => 'done',
                                        'post_id' => (int) $post_id,
                                )
                        );
                }

                $GLOBALS['haai_job_result'] = array( 'post_id' => (int) $post_id, 'status' => $status );
        }

        /* ------------------------------------------------------------------
         * Screen
         * ---------------------------------------------------------------- */

        /** @return void */
        public function render_screen() {
                if ( ! current_user_can( 'manage_options' ) ) {
                        return;
                }

                $counts = self::counts();
                $items  = self::items( '', 25 );
                $feeds  = self::feeds();
                $cfg    = self::cfg();
                ?>
                <div class="haai-wrap">
                        <h1 class="haai-page-title"><?php esc_html_e( 'اخبار', 'hami-ai-assistant' ); ?></h1>
                        <p><a class="haai-badge" href="<?php echo esc_url( Haa_Util::screen_url( 'settings', array( 'tab' => 'news' ) ) ); ?>"><?php esc_html_e( 'تنظیمات این ماژول', 'hami-ai-assistant' ); ?></a></p>

                        <div class="haai-panel">
                                <h2 class="haai-panel__title">
                                        <?php esc_html_e( 'فیدها', 'hami-ai-assistant' ); ?>
                                        <?php if ( empty( $cfg['enabled'] ) ) : ?>
                                                <span class="haai-badge"><?php esc_html_e( 'خاموش', 'hami-ai-assistant' ); ?></span>
                                        <?php endif; ?>
                                </h2>

                                <p class="haai-panel__note">
                                        <?php esc_html_e( 'از فید فقط تیتر، لینک و زمان خوانده می‌شود. متن خبر در مقاله نمی‌آید؛ مقاله با کلمه‌های خود سایت نوشته می‌شود و منبع لینک می‌شود.', 'hami-ai-assistant' ); ?>
                                </p>

                                <?php if ( ! $feeds ) : ?>
                                        <div class="haai-alert haai-alert--warn">
                                                <?php
                                                printf(
                                                        /* translators: %s: settings URL. */
                                                        wp_kses_post( __( 'هنوز فیدی وارد نکرده‌اید؛ در <a href="%s">تنظیمات ← اخبار</a> آدرس‌ها را اضافه کنید.', 'hami-ai-assistant' ) ),
                                                        esc_url( Haa_Util::screen_url( 'settings', array( 'tab' => 'news' ) ) )
                                                );
                                                ?>
                                        </div>
                                <?php else : ?>
                                        <ul class="haai-diag__list">
                                                <?php foreach ( $feeds as $f ) : ?>
                                                        <li><?php echo esc_html( (string) ( wp_parse_url( $f, PHP_URL_HOST ) ?: $f ) ); ?></li>
                                                <?php endforeach; ?>
                                        </ul>

                                        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="haai-inline-form">
                                                <?php wp_nonce_field( 'haai_news' ); ?>
                                                <input type="hidden" name="action" value="haai_news_run" />
                                                <button type="submit" class="button button-primary"><?php esc_html_e( 'خواندن فیدها و صف کردن تازه‌ها', 'hami-ai-assistant' ); ?></button>
                                                <span class="haai-field__hint">
                                                        <?php
                                                        printf(
                                                                /* translators: 1: per run, 2: daily cap. */
                                                                esc_html__( 'حداکثر %1$d خبر در این اجرا؛ سقف امروز %2$d مقاله.', 'hami-ai-assistant' ),
                                                                (int) ( $cfg['max_per_run'] ?? 5 ),
                                                                (int) ( $cfg['daily_cap'] ?? 3 )
                                                        );
                                                        ?>
                                                </span>
                                        </form>
                                <?php endif; ?>
                        </div>

                        <div class="haai-panel">
                                <h2 class="haai-panel__title"><?php esc_html_e( 'خبرهای پردازش‌شده', 'hami-ai-assistant' ); ?></h2>

                                <div class="haai-plan__meta">
                                        <?php foreach ( $counts as $status => $n ) : ?>
                                                <span class="haai-badge"><?php echo esc_html( (string) $status . ': ' . (string) $n ); ?></span>
                                        <?php endforeach; ?>
                                        <?php if ( ! $counts ) : ?>
                                                <span class="haai-badge"><?php esc_html_e( 'چیزی ثبت نشده', 'hami-ai-assistant' ); ?></span>
                                        <?php endif; ?>
                                </div>

                                <?php if ( $items ) : ?>
                                        <table class="haai-table">
                                                <thead>
                                                        <tr>
                                                                <th><?php esc_html_e( 'تیتر', 'hami-ai-assistant' ); ?></th>
                                                                <th><?php esc_html_e( 'منبع', 'hami-ai-assistant' ); ?></th>
                                                                <th><?php esc_html_e( 'زمان', 'hami-ai-assistant' ); ?></th>
                                                                <th><?php esc_html_e( 'وضعیت', 'hami-ai-assistant' ); ?></th>
                                                                <th><?php esc_html_e( 'نوشته', 'hami-ai-assistant' ); ?></th>
                                                        </tr>
                                                </thead>
                                                <tbody>
                                                        <?php foreach ( $items as $it ) : ?>
                                                                <tr>
                                                                        <td>
                                                                                <?php if ( '' !== $it['link'] ) : ?>
                                                                                        <a href="<?php echo esc_url( $it['link'] ); ?>" rel="nofollow noopener" target="_blank"><?php echo esc_html( $it['title'] ); ?></a>
                                                                                <?php else : ?>
                                                                                        <?php echo esc_html( $it['title'] ); ?>
                                                                                <?php endif; ?>
                                                                        </td>
                                                                        <td><?php echo esc_html( $it['source'] ); ?></td>
                                                                        <td><?php echo esc_html( $it['published'] ); ?></td>
                                                                        <td><span class="haai-badge"><?php echo esc_html( $it['status'] ); ?></span></td>
                                                                        <td>
                                                                                <?php if ( $it['post_id'] ) : ?>
                                                                                        <a href="<?php echo esc_url( (string) get_edit_post_link( $it['post_id'] ) ); ?>"><?php echo esc_html( (string) $it['post_id'] ); ?></a>
                                                                                <?php else : ?>
                                                                                        —
                                                                                <?php endif; ?>
                                                                        </td>
                                                                </tr>
                                                        <?php endforeach; ?>
                                                </tbody>
                                        </table>
                                <?php else : ?>
                                        <div class="haai-empty">
                                                <span class="haai-empty__title"><?php esc_html_e( 'هنوز خبری خوانده نشده است.', 'hami-ai-assistant' ); ?></span>
                                        </div>
                                <?php endif; ?>
                        </div>
                </div>
                <?php
        }

        /* ------------------------------------------------------------------
         * Handlers
         * ---------------------------------------------------------------- */

        /** @return void */
        public function handle_run() {
                Haa_Util::guard_admin_post( 'haai_news' );

                $r = self::poll( true );

                $msg = sprintf(
                        /* translators: 1: items read, 2: fresh, 3: queued. */
                        __( '%1$d آیتم خوانده شد، %2$d تازه، %3$d به صف رفت.', 'hami-ai-assistant' ),
                        (int) $r['fetched'],
                        (int) $r['fresh'],
                        (int) $r['queued']
                );
                if ( $r['errors'] ) {
                        $msg .= ' ' . implode( ' | ', array_slice( $r['errors'], 0, 3 ) );
                }

                $this->back( $msg, 0 === (int) $r['fetched'] && (bool) $r['errors'] );
        }

        /**
         * Mark an item skipped or put it back to the queue.
         *
         * @return void
         */
        public function handle_status() {
                Haa_Util::guard_admin_post( 'haai_news' );

                $id     = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
                $status = isset( $_POST['status'] ) ? (string) wp_unslash( $_POST['status'] ) : '';

                if ( ! $id || ! in_array( $status, array( 'skipped', 'new' ), true ) ) {
                        $this->back( __( 'درخواست نامعتبر بود.', 'hami-ai-assistant' ), true );
                }

                self::update_item( $id, array( 'status' => $status ) );
                $this->back( __( 'وضعیت عوض شد.', 'hami-ai-assistant' ) );
        }

        /**
         * Forget the dedupe memory so a feed can be re-read from scratch.
         *
         * @return void
         */
        public function handle_forget() {
                Haa_Util::guard_admin_post( 'haai_news' );

                global $wpdb;
                $wpdb->query( 'TRUNCATE TABLE ' . Haa_DB::table( self::TABLE ) ); // phpcs:ignore WordPress.DB
                $this->back( __( 'حافظه‌ی خبرها پاک شد.', 'hami-ai-assistant' ) );
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
                                'news',
                                array(
                                        $error ? 'haai_err' : 'haai_msg' => rawurlencode( Haa_Util::cut( $message, 300 ) ),
                                )
                        )
                );
                exit;
        }
}
