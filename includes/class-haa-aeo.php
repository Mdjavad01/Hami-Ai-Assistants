<?php
/**
 * AEO + site-health toolkit (2.1.23): the backlog's SEO/AEO group, built lean.
 *
 * - FAQPage JSON-LD for posts/products whose FAQ came from the generator
 *   (meta `_haai_faq`, written by Haa_Seo::write) or still lives in the body
 *   under the «سؤالات متداول» heading.
 * - /llms.txt: one cached text file that introduces the shop (name, pages,
 *   contact channels, categories, recent content) to AI crawlers.
 * - AI-bot counting: GPTBot / PerplexityBot / ClaudeBot … page views, kept
 *   for 30 days, reported in the new «گزارش‌ها» screen.
 * - Nightly broken-link checker: a tiny cursor walks published content and
 *   HEAD-checks a bounded number of links per night (never a full sweep in
 *   one request), findings land in the reports screen.
 * - Index health checks + product SEO scores, both rendered by
 *   Haa_Admin_Reports and driven through one guarded AJAX endpoint.
 *
 * Everything here is optional and defaults to on; the three toggles live in
 * تنظیمات ← دانش سایت (knowledge.llms_enabled / bots_enabled / links_enabled).
 *
 * @package Hami_AI_Assistant
 */

if ( ! defined( 'ABSPATH' ) ) {
        exit;
}

final class Haa_Aeo {

        const OPT_LLMS   = 'haai_llms_txt';     // ['at' => ts, 'text' => string].
        const OPT_BOT    = 'haai_aibots_day';   // ['day' => Y-m-d, 'counts' => [name => n]].
        const OPT_BOTLOG = 'haai_aibots_log';   // Oldest-first list of daily rows, max 30.
        const OPT_LINKS  = 'haai_links_broken'; // ['map' => [key => finding], 'cycle_at' => ts].
        const OPT_LSTATE = 'haai_links_state';  // ['pid' => cursor, 'cycle_at' => ts].
        const OPT_SCORES = 'haai_pscores';      // ['cursor' => id, 'scores' => [pid => row], 'cycle_at' => ts].
        const OPT_RVER   = 'haai_rewrite_ver';  // One /llms.txt flush per release.

        const CRON_HOOK          = 'haai_nightly_links';
        const LINKS_CAP          = 300; // Findings kept, oldest dropped.
        const SCORES_MAX         = 40;  // Products scored per batch.
        const LINKS_PER_NIGHT    = 10;  // HEAD checks per cron fire (≤ 50 s worst case).
        const LINKS_PER_MANUAL   = 6;   // HEAD checks per manual AJAX press (≤ 30 s).
        const LINKS_POSTS_PER_RUN = 20; // Posts walked per run regardless of links.
        const SCORE_PASS         = 70;  // Below this the product joins «بهینه‌سازی گروهی».
        const FAQ_MAX            = 10;

        /* ==================================================================
         * Boot
         * =============================================================== */

        /**
         * Register everything (called once from Haa_Plugin::boot).
         *
         * @return void
         */
        public static function hooks() {
                // /llms.txt — the rule registration is context-free; serving happens on
                // the front-end only (template_redirect never fires in the admin).
                add_action( 'init', array( __CLASS__, 'register_rewrite' ), 20 );
                add_filter( 'query_vars', array( __CLASS__, 'query_vars' ) );
                add_action( 'template_redirect', array( __CLASS__, 'maybe_serve_llms' ) );

                // FAQPage JSON-LD, next to Haa_Seo's Article schema (priority 20).
                add_action( 'wp_head', array( __CLASS__, 'print_faq_schema' ), 21 );

                // Nightly link checker + the reports screen's single AJAX endpoint.
                add_action( self::CRON_HOOK, array( __CLASS__, 'nightly_links' ) );
                add_action( 'wp_ajax_haai_reports_action', array( __CLASS__, 'ajax_reports' ) );

                // AI-bot counting costs a UA string-compare per front-end request and
                // one option write per actual bot hit — zero cost for human traffic.
                if ( ! is_admin() && ! wp_doing_cron() ) {
                        self::track_bot();
                }

                // Upgrade installs get the /llms.txt rule without a manual re-save of
                // the permalinks: one flush per release, admin requests only.
                if ( is_admin() && (string) get_option( self::OPT_RVER ) !== (string) HAAI_VERSION ) {
                        update_option( self::OPT_RVER, (string) HAAI_VERSION, true );
                        flush_rewrite_rules( false );
                }
        }

        /* ==================================================================
         * llms.txt
         * =============================================================== */

        /**
         * Rewrite rule ^llms\.txt$ → index.php?haai_llms=1.
         *
         * @return void
         */
        public static function register_rewrite() {
                add_rewrite_rule( '^llms\.txt$', 'index.php?haai_llms=1', 'top' );
        }

        /**
         * @param array $vars Query vars.
         * @return array
         */
        public static function query_vars( $vars ) {
                $vars[] = 'haai_llms';
                return $vars;
        }

        /**
         * Serve the cached file. The toggle off → honest 404 (a cached rule must
         * never leak a stale file after the admin switches the feature off).
         *
         * @return void
         */
        public static function maybe_serve_llms() {
                if ( ! (int) get_query_var( 'haai_llms' ) ) {
                        return;
                }
                if ( ! Haa_Settings::get( 'knowledge.llms_enabled', 1 ) ) {
                        status_header( 404 );
                        nocache_headers();
                        exit;
                }
                header( 'Content-Type: text/plain; charset=utf-8' );
                echo self::llms_text(); // phpcs:ignore WordPress.Security.EscapeOutput -- plain text endpoint.
                exit;
        }

        /**
         * Public URL of the file (reports screen link).
         *
         * @return string
         */
        public static function llms_url() {
                return home_url( '/llms.txt' );
        }

        /**
         * Cached text; rebuilds at most once a day or when a crawl finishes.
         *
         * @return string
         */
        public static function llms_text() {
                $cache = get_option( self::OPT_LLMS );
                if ( is_array( $cache ) && isset( $cache['text'], $cache['at'] ) && '' !== (string) $cache['text']
                        && ( time() - (int) $cache['at'] ) < DAY_IN_SECONDS ) {
                        return (string) $cache['text'];
                }
                $text = self::build_llms();
                update_option( self::OPT_LLMS, array( 'at' => time(), 'text' => $text ), false );
                return $text;
        }

        /**
         * Called at the end of a crawl: the shop description changed, so the next
         * request rebuilds. Cheaper than rebuilding inline — the crawl request
         * already did its work and no visitor is waiting on this file.
         *
         * @return void
         */
        public static function mark_llms_stale() {
                delete_option( self::OPT_LLMS );
        }

        /**
         * Build the file. A handful of bounded reads: the contact chunk crawled
         * from the site's own pages, product categories, ten recent posts and ten
         * recent products. Every block degrades silently when its source is empty,
         * so a fresh install still produces a valid file.
         *
         * @return string
         */
        public static function build_llms() {
                global $wpdb;

                $lines   = array();
                $site    = trim( (string) get_bloginfo( 'name' ) );
                $lines[] = '# ' . ( '' !== $site ? $site : home_url() );
                $desc    = trim( (string) get_bloginfo( 'description' ) );
                if ( '' !== $desc ) {
                        $lines[] = '> ' . $desc;
                }
                $lines[] = '';
                $lines[] = 'Home: ' . home_url( '/' );
                $lines[] = 'Language: fa';
                $lines[] = '';

                // Key pages: the three a visitor (or an AI agent) asks for first.
                $lines[] = '## صفحات کلیدی';
                $lines[] = '- [صفحه اصلی](' . home_url( '/' ) . ')';
                if ( class_exists( 'Haa_Crawl' ) ) {
                        $contact = (string) Haa_Crawl::find_contact_page_url();
                        if ( '' !== $contact ) {
                                $lines[] = '- [تماس با ما](' . $contact . ')';
                        }
                }
                if ( function_exists( 'wc_get_page_id' ) ) {
                        $shop = (int) wc_get_page_id( 'shop' );
                        if ( $shop > 0 && get_post( $shop ) ) {
                                $lines[] = '- [فروشگاه](' . (string) get_permalink( $shop ) . ')';
                        }
                }

                // Contact channels — the same chunk the chatbot answers from, so the
                // file never contradicts what the assistant says.
                $chunk = '';
                if ( class_exists( 'Haa_DB' ) ) {
                        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
                        $chunk = (string) $wpdb->get_var( "SELECT content FROM " . Haa_DB::table( 'chunks' ) . " WHERE post_id = -1 AND source = 'contact' LIMIT 1" );
                }
                if ( '' !== trim( $chunk ) ) {
                        $lines[] = '';
                        $lines[] = '## راه‌های ارتباطی';
                        foreach ( preg_split( '/\r\n|\r|\n/', trim( $chunk ) ) as $row ) {
                                $row = trim( (string) $row );
                                if ( '' !== $row ) {
                                        $lines[] = '- ' . $row;
                                }
                        }
                }

                // Product categories (name, link, size).
                if ( function_exists( 'wc_get_product' ) || class_exists( 'WooCommerce' ) ) {
                        $cats = get_terms( array( 'taxonomy' => 'product_cat', 'hide_empty' => false, 'number' => 30 ) );
                        if ( ! is_wp_error( $cats ) && is_array( $cats ) && $cats ) {
                                $lines[] = '';
                                $lines[] = '## دسته‌بندی محصولات';
                                foreach ( $cats as $cat ) {
                                        $link = get_term_link( $cat );
                                        if ( is_wp_error( $link ) || '' === (string) $link ) {
                                                continue;
                                        }
                                        $size    = isset( $cat->count ) && (int) $cat->count > 0 ? ' (' . (int) $cat->count . ' محصول)' : '';
                                        $lines[] = '- [' . (string) $cat->name . '](' . (string) $link . ')' . $size;
                                }
                        }
                }

                // Ten most recent articles with a one-line summary.
                $posts = get_posts( array( 'post_type' => 'post', 'post_status' => 'publish', 'numberposts' => 10, 'orderby' => 'date', 'order' => 'DESC' ) );
                if ( $posts ) {
                        $lines[] = '';
                        $lines[] = '## مقاله‌های اخیر';
                        foreach ( (array) $posts as $p ) {
                                $pid  = is_object( $p ) ? (int) $p->ID : (int) $p;
                                $url  = (string) get_permalink( $pid );
                                $text = trim( (string) get_post_field( 'post_excerpt', $pid ) );
                                if ( '' === $text ) {
                                        $text = self::excerpt_of( (string) get_post_field( 'post_content', $pid ), 120 );
                                }
                                $lines[] = '- [' . (string) get_the_title( $pid ) . '](' . $url . ')' . ( '' !== $text ? ': ' . $text : '' );
                        }
                }

                // Ten newest products with a price when WooCommerce can read one.
                if ( function_exists( 'wc_get_product' ) ) {
                        $products = get_posts( array( 'post_type' => 'product', 'post_status' => 'publish', 'numberposts' => 10, 'orderby' => 'date', 'order' => 'DESC' ) );
                        if ( $products ) {
                                $lines[] = '';
                                $lines[] = '## محصولات نمونه';
                                foreach ( (array) $products as $p ) {
                                        $pid = is_object( $p ) ? (int) $p->ID : (int) $p;
                                        $url = (string) get_permalink( $pid );
                                        $row = '- [' . (string) get_the_title( $pid ) . '](' . $url . ')';
                                        $prod = wc_get_product( $pid );
                                        if ( $prod && method_exists( $prod, 'get_price' ) && '' !== (string) $prod->get_price() ) {
                                                $row .= ': قیمت ' . (string) $prod->get_price() . ' تومان';
                                        }
                                        $lines[] = $row;
                                }
                        }
                }

                return implode( "\n", $lines ) . "\n";
        }

        /* ==================================================================
         * FAQPage JSON-LD
         * =============================================================== */

        /**
         * FAQ pairs for one post: the generator's meta first, the body's
         * «سؤالات متداول» section as the fallback for content written before
         * 2.1.23. Max ten questions — Google's own cap for a rich result.
         *
         * @param int    $post_id Post id.
         * @param string $content Post content (read once by the caller when known).
         * @return array<int,array{q:string,a:string}>
         */
        public static function faq_pairs( $post_id, $content = '' ) {
                $pairs = array();
                $meta  = get_post_meta( (int) $post_id, '_haai_faq', true );
                foreach ( (array) $meta as $row ) {
                        $q = trim( (string) ( is_array( $row ) ? ( $row['q'] ?? '' ) : '' ) );
                        $a = trim( (string) ( is_array( $row ) ? ( $row['a'] ?? '' ) : '' ) );
                        if ( '' !== $q && '' !== $a ) {
                                $pairs[] = array( 'q' => $q, 'a' => $a );
                        }
                }
                if ( $pairs ) {
                        return array_slice( $pairs, 0, self::FAQ_MAX );
                }
                if ( '' === (string) $content ) {
                        $content = (string) get_post_field( 'post_content', (int) $post_id );
                }
                return self::faq_pairs_from_content( $content );
        }

        /**
         * Parse the FAQ block the generator appends to the body
         * (H2 «…متداول» → H3 question + P answer pairs).
         *
         * @param string $content Raw post_content.
         * @return array<int,array{q:string,a:string}>
         */
        public static function faq_pairs_from_content( $content ) {
                $pairs   = array();
                $content = (string) $content;
                if ( '' === $content ) {
                        return $pairs;
                }
                // Byte offsets are consistent with strlen/substr even under /u.
                if ( ! preg_match( '#<h2[^>]*>[^<]*(?:سؤالات|سوالات|پرسش)[^<]*متداول[^<]*</h2>#u', $content, $hm, PREG_OFFSET_CAPTURE ) ) {
                        return $pairs;
                }
                $start = (int) $hm[0][1] + strlen( (string) $hm[0][0] );
                $seg   = substr( $content, $start );
                if ( preg_match( '#<h2#i', $seg, $em, PREG_OFFSET_CAPTURE ) ) {
                        $seg = substr( $seg, 0, (int) $em[0][1] );
                }
                if ( ! preg_match_all( '#<h3[^>]*>(.*?)</h3>\s*<p[^>]*>(.*?)</p>#is', $seg, $m, PREG_SET_ORDER ) ) {
                        return $pairs;
                }
                foreach ( $m as $row ) {
                        $q = self::plain( $row[1] );
                        $a = self::plain( $row[2] );
                        if ( '' !== $q && '' !== $a ) {
                                $pairs[] = array( 'q' => $q, 'a' => $a );
                        }
                        if ( count( $pairs ) >= self::FAQ_MAX ) {
                                break;
                        }
                }
                return $pairs;
        }

        /**
         * Print FAQPage JSON-LD on singular post/product pages.
         *
         * Skipped when the page's FAQ is owned by an SEO plugin block (Yoast /
         * Rank Math emit their own schema there) or when fewer than two usable
         * pairs exist — Google requires at least two questions for the rich
         * result, and a one-question schema adds nothing.
         *
         * @return void
         */
        public static function print_faq_schema() {
                if ( ! is_singular( array( 'post', 'product' ) ) ) {
                        return;
                }
                $post_id = (int) get_queried_object_id();
                if ( ! $post_id ) {
                        return;
                }
                $content = (string) get_post_field( 'post_content', $post_id );
                foreach ( array( 'wp-block-yoast-faq', 'rank-math-faq-block', 'schema-faq' ) as $marker ) {
                        if ( false !== stripos( $content, $marker ) ) {
                                return; // Another emitter owns this page's FAQ schema.
                        }
                }
                $pairs = self::faq_pairs( $post_id, $content );
                if ( count( $pairs ) < 2 ) {
                        return;
                }
                $main = array();
                foreach ( $pairs as $pair ) {
                        $main[] = array(
                                '@type'          => 'Question',
                                'name'           => $pair['q'],
                                'acceptedAnswer' => array(
                                        '@type' => 'Answer',
                                        'text'  => $pair['a'],
                                ),
                        );
                }
                $schema = array(
                        '@context'   => 'https://schema.org',
                        '@type'      => 'FAQPage',
                        'mainEntity' => $main,
                );
                echo '<script type="application/ld+json">' . str_replace( '</', '<\/', (string) wp_json_encode( $schema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) ) . '</script>' . "\n";
        }

        /* ==================================================================
         * Shared text helpers
         * =============================================================== */

        /**
         * Tag-free, entity-free, whitespace-tight text.
         *
         * @param string $html Raw fragment.
         * @return string
         */
        private static function plain( $html ) {
                $text = wp_strip_all_tags( (string) html_entity_decode( (string) $html, ENT_QUOTES, 'UTF-8' ) );
                return trim( (string) preg_replace( '/\s+/u', ' ', $text ) );
        }

        /**
         * First $words-ish excerpt of a body (used by llms.txt).
         *
         * @param string $content Raw content.
         * @param int    $chars   Character cap.
         * @return string
         */
        private static function excerpt_of( $content, $chars ) {
                $text = self::plain( $content );
                if ( '' === $text ) {
                        return '';
                }
                return mb_strlen( $text ) > $chars ? mb_substr( $text, 0, $chars - 1 ) . '…' : $text;
        }

        /* ==================================================================
         * AI-bot counting
         * =============================================================== */

        /**
         * The AI agents worth counting, needle => display name. Deliberately
         * substring matches: vendors change UA wording between releases.
         *
         * @return array<string,string>
         */
        public static function bots() {
                return array(
                        'GPTBot'             => 'GPTBot (OpenAI خزنده)',
                        'OAI-SearchBot'      => 'OAI-SearchBot (جستجوی ChatGPT)',
                        'ChatGPT-User'       => 'ChatGPT-User (کاربر ChatGPT)',
                        'PerplexityBot'      => 'PerplexityBot',
                        'Perplexity-User'    => 'Perplexity-User',
                        'ClaudeBot'          => 'ClaudeBot (Anthropic)',
                        'Claude-User'        => 'Claude-User',
                        'Claude-Web'         => 'Claude-Web',
                        'Google-Extended'    => 'Google-Extended (Gemini)',
                        'Applebot-Extended'  => 'Applebot-Extended',
                        'Bytespider'         => 'Bytespider (TikTok)',
                        'Amazonbot'          => 'Amazonbot',
                        'meta-externalagent' => 'meta-externalagent (Meta)',
                );
        }

        /**
         * One strike per request, only for real bots. Zero DB cost for humans.
         *
         * @return void
         */
        public static function track_bot() {
                if ( ! Haa_Settings::get( 'knowledge.bots_enabled', 1 ) ) {
                        return;
                }
                $ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? (string) $_SERVER['HTTP_USER_AGENT'] : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
                if ( '' === $ua ) {
                        return;
                }
                foreach ( self::bots() as $needle => $name ) {
                        if ( false !== stripos( $ua, $needle ) ) {
                                self::bump_bot( $name );
                                return;
                        }
                }
        }

        /**
         * Roll the daily bucket at midnight, keep 30 days of history.
         *
         * @param string $name Bot display name.
         * @return void
         */
        private static function bump_bot( $name ) {
                $day   = gmdate( 'Y-m-d' );
                $today = get_option( self::OPT_BOT );
                if ( ! is_array( $today ) || ! isset( $today['day'], $today['counts'] ) ) {
                        $today = array( 'day' => $day, 'counts' => array() );
                }
                if ( (string) $today['day'] !== $day ) {
                        $log   = (array) get_option( self::OPT_BOTLOG, array() );
                        $log[] = array( 'day' => (string) $today['day'], 'counts' => (array) $today['counts'] );
                        if ( count( $log ) > 30 ) {
                                $log = array_slice( $log, -30 );
                        }
                        update_option( self::OPT_BOTLOG, $log, false );
                        $today = array( 'day' => $day, 'counts' => array() );
                }
                $counts             = (array) $today['counts'];
                $counts[ $name ]    = (int) ( $counts[ $name ] ?? 0 ) + 1;
                $today['counts']    = $counts;
                update_option( self::OPT_BOT, $today, false );
        }

        /**
         * Report rows: today first, then the stored history, newest first.
         *
         * @return array<int,array{day:string,counts:array<string,int>}>
         */
        public static function bot_rows() {
                $rows = array();
                $today = get_option( self::OPT_BOT );
                if ( is_array( $today ) && isset( $today['day'], $today['counts'] ) && (array) $today['counts'] ) {
                        $rows[] = array( 'day' => (string) $today['day'], 'counts' => (array) $today['counts'] );
                }
                $log = (array) get_option( self::OPT_BOTLOG, array() );
                for ( $i = count( $log ) - 1; $i >= 0; $i-- ) {
                        $row    = (array) $log[ $i ];
                        $rows[] = array( 'day' => (string) ( $row['day'] ?? '' ), 'counts' => (array) ( $row['counts'] ?? array() ) );
                }
                return array_slice( $rows, 0, 15 );
        }

        /* ==================================================================
         * Broken-link checker
         * =============================================================== */

        /**
         * Cron fire: one bounded batch. The toggle off → drop the event too, so
         * an abandoned schedule never wakes the site again.
         *
         * @return void
         */
        public static function nightly_links() {
                if ( ! Haa_Settings::get( 'knowledge.links_enabled', 1 ) ) {
                        wp_clear_scheduled_hook( self::CRON_HOOK );
                        return;
                }
                self::scan_links_batch( self::LINKS_PER_NIGHT, self::LINKS_POSTS_PER_RUN );
        }

        /**
         * hrefs inside one post body. Only real http(s) targets: fragments,
         * mailto/tel/javascript and protocol-relative junk are noise.
         *
         * @param string $content Raw post_content.
         * @return array<int,string>
         */
        public static function extract_hrefs( $content ) {
                $out = array();
                if ( ! preg_match_all( '#<a[^>]*href=(["\'])([^"\']+)\1#i', (string) $content, $m ) ) {
                        return $out;
                }
                foreach ( (array) $m[2] as $url ) {
                        $url = trim( (string) $url );
                        if ( '' === $url ) {
                                continue;
                        }
                        if ( ! preg_match( '#^https?://#i', $url ) ) {
                                continue;
                        }
                        $out[ $url ] = true;
                }
                return array_keys( $out );
        }

        /**
         * Walk a cursor over published posts and HEAD-check a bounded number of
         * links. Findings are keyed md5(url|pid) so a re-scan that finds the link
         * healthy removes its own entry — the list self-cleans, never duplicates.
         *
         * Only 404 / 410 / 5xx and transport errors are "broken": 403/405/429 are
         * bot-hostile but healthy pages, and flagging them would fill the report
         * with false alarms behind Cloudflare.
         *
         * @param int $max_checks HEAD requests this run.
         * @param int $max_posts  Posts walked this run.
         * @return array{checked:int,new:int,broken:int,scanned:int,complete:bool}
         */
        public static function scan_links_batch( $max_checks, $max_posts ) {
                global $wpdb;

                $max_checks = max( 1, (int) $max_checks );
                $max_posts  = max( 1, (int) $max_posts );

                $state = get_option( self::OPT_LSTATE );
                if ( ! is_array( $state ) || ! isset( $state['pid'] ) ) {
                        $state = array( 'pid' => 0, 'cycle_at' => 0 );
                }
                $state['pid'] = (int) $state['pid'];
                $finds        = self::links_findings();

                $types = array( 'post', 'page' );
                if ( function_exists( 'haa_is_woo' ) && haa_is_woo() ) {
                        $types[] = 'product';
                }
                // 2.1.24 — wpdb::prepare() never adds quotes around %s: without the
                // literal quotes below the live query was `IN (post, page)` → a MySQL
                // syntax error the stub harness tolerated but every real site errored.
                $in = implode( ', ', array_fill( 0, count( $types ), "'%s'" ) );

                // phpcs:ignore WordPress.DB.DirectDatabaseQuery
                $ids = $wpdb->get_col( $wpdb->prepare(
                        "SELECT ID FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_type IN ({$in}) AND ID > %d AND post_content LIKE '%href=%' ORDER BY ID ASC LIMIT %d",
                        array_merge( $types, array( $state['pid'], $max_posts ) )
                ) );
                if ( ! is_array( $ids ) ) {
                        $ids = array();
                }
                // Cursor past the tail of the published content: roll over and start
                // the next cycle from the beginning (a rolling re-scan keeps the list
                // fresh; the last full-cycle date stamps when the tail is reached).
                if ( ! $ids ) {
                        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
                        $ids = $wpdb->get_col( $wpdb->prepare(
                                "SELECT ID FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_type IN ({$in}) AND ID > %d AND post_content LIKE '%href=%' ORDER BY ID ASC LIMIT %d",
                                array_merge( $types, array( 0, $max_posts ) )
                        ) );
                        if ( ! is_array( $ids ) ) {
                                $ids = array();
                        }
                        if ( ! $ids ) {
                                // Nothing published carries links at all: an honest empty cycle.
                                $state['pid']      = 0;
                                $state['cycle_at'] = time();
                                update_option( self::OPT_LSTATE, $state, false );
                                return array( 'checked' => 0, 'new' => 0, 'broken' => count( $finds ), 'scanned' => 0, 'complete' => true );
                        }
                }

                $checked = 0;
                $added   = 0;
                $scanned = 0;
                $last    = 0;

                foreach ( $ids as $pid ) {
                        if ( $checked >= $max_checks ) {
                                break;
                        }
                        $pid   = (int) $pid;
                        $urls  = self::extract_hrefs( (string) get_post_field( 'post_content', $pid ) );
                        $title = (string) get_the_title( $pid );

                        // The cursor only passes a post that actually had its links checked
                        // (or had none): a post whose budget ran out is re-picked next run.
                        foreach ( $urls as $url ) {
                                if ( $checked >= $max_checks ) {
                                        break;
                                }
                                $checked++;
                                $res = wp_remote_head( $url, array(
                                        'timeout'    => 5,
                                        'redirection' => 3,
                                        'user-agent' => 'HamiAI-LinkCheck (+ ' . home_url( '/' ) . ')',
                                ) );
                                $bad = null;
                                if ( is_wp_error( $res ) ) {
                                        $bad = array( 'code' => 0, 'err' => (string) $res->get_error_message() );
                                } else {
                                        $code = (int) wp_remote_retrieve_response_code( $res );
                                        if ( 404 === $code || 410 === $code || $code >= 500 ) {
                                                $bad = array( 'code' => $code, 'err' => '' );
                                        }
                                }
                                $key = md5( $url . '|' . $pid );
                                if ( $bad ) {
                                        $finds[ $key ] = array(
                                                'url'   => $url,
                                                'pid'   => $pid,
                                                'title' => $title,
                                                'code'  => $bad['code'],
                                                'err'   => $bad['err'],
                                                'at'    => time(),
                                        );
                                        $added++;
                                } else {
                                        unset( $finds[ $key ] );
                                }
                        }
                        $scanned++;
                        $last = $pid;
                }

                if ( count( $finds ) > self::LINKS_CAP ) {
                        uasort( $finds, function ( $x, $y ) {
                                return (int) $x['at'] <=> (int) $y['at'];
                        } );
                        $finds = array_slice( $finds, -self::LINKS_CAP, null, true );
                }

                $complete = false;
                if ( $ids && $last >= (int) max( $ids ) && count( $ids ) < $max_posts ) {
                        // Cursor reached the tail of the published content: one cycle done.
                        $complete          = true;
                        $state['cycle_at'] = time();
                        $state['pid']      = 0;
                } else {
                        $state['pid'] = $last;
                }

                update_option( self::OPT_LINKS, array( 'map' => $finds, 'cycle_at' => (int) $state['cycle_at'] ), false );
                update_option( self::OPT_LSTATE, $state, false );

                return array(
                        'checked'  => $checked,
                        'new'      => $added,
                        'broken'   => count( $finds ),
                        'scanned'  => $scanned,
                        'complete' => $complete,
                );
        }

        /**
         * Findings, newest first.
         *
         * @return array<string,array>
         */
        public static function links_findings() {
                $opt  = get_option( self::OPT_LINKS );
                $finds = is_array( $opt ) && isset( $opt['map'] ) && is_array( $opt['map'] ) ? $opt['map'] : array();
                uasort( $finds, function ( $x, $y ) {
                        return (int) $y['at'] <=> (int) $x['at'];
                } );
                return $finds;
        }

        /**
         * When did the last full pass finish (0 = never).
         *
         * @return int
         */
        public static function links_cycle_at() {
                $opt = get_option( self::OPT_LSTATE );
                return is_array( $opt ) ? (int) ( $opt['cycle_at'] ?? 0 ) : 0;
        }

        /**
         * Forget every finding (manual button).
         *
         * @return void
         */
        public static function links_clear() {
                delete_option( self::OPT_LINKS );
        }

        /* ==================================================================
         * Index health checks
         * =============================================================== */

        /**
         * Cheap, self-explanatory checks — no self-HTTP requests, one meta query.
         * Every row carries the fix in plain Persian so the reports screen can
         * render it without extra copy.
         *
         * @return array<int,array{label:string,ok:bool,note:string,info?:bool}>
         */
        public static function health_checks() {
                $checks = array();

                $ok = '1' === (string) get_option( 'blog_public', '1' );
                $checks[] = array(
                        'label' => __( 'اجازه‌ی نمایه‌شدن به موتورهای جستجو', 'hami-ai-assistant' ),
                        'ok'    => $ok,
                        'note'  => $ok
                                ? __( 'وردپرس سایت را از موتورهای جستجو پنهان نکرده است.', 'hami-ai-assistant' )
                                : __( 'تنظیمات ← خواندن ← «دسترسی موتورهای جستجو» فعال نشده است؛ همین یک گزینه کل سایت را از گوگل پنهان می‌کند.', 'hami-ai-assistant' ),
                );

                $ok = '' !== (string) get_option( 'permalink_structure', '' );
                $checks[] = array(
                        'label' => __( 'پیوندهای یکتا', 'hami-ai-assistant' ),
                        'ok'    => $ok,
                        'note'  => $ok
                                ? __( 'آدرس‌ها شکل معنادار دارند.', 'hami-ai-assistant' )
                                : __( 'پیوندهای یکتا روی حالت ساده (؟p=123) است؛ از تنظیمات ← پیوندهای یکتا یک ساختار معنادار انتخاب کنید.', 'hami-ai-assistant' ),
                );

                $ok = 0 === strpos( (string) home_url(), 'https://' );
                $checks[] = array(
                        'label' => __( 'HTTPS', 'hami-ai-assistant' ),
                        'ok'    => $ok,
                        'note'  => $ok
                                ? __( 'آدرس سایت امن است.', 'hami-ai-assistant' )
                                : __( 'آدرس سایت http است؛ موتورها و مرورگرها به سایت بدون SSL اعتماد نمی‌کنند.', 'hami-ai-assistant' ),
                );

                if ( function_exists( 'wp_sitemaps_get_server' ) ) {
                        $server = wp_sitemaps_get_server();
                        $ok     = is_object( $server ) && method_exists( $server, 'sitemaps_enabled' ) && $server->sitemaps_enabled();
                        $checks[] = array(
                                'label' => __( 'نقشه‌ی سایت XML', 'hami-ai-assistant' ),
                                'ok'    => $ok,
                                'note'  => $ok
                                        ? sprintf( /* translators: %s: sitemap URL. */ __( 'نقشه‌ی سایت وردپرس فعال است: %s', 'hami-ai-assistant' ), home_url( '/wp-sitemap.xml' ) )
                                        : __( 'نقشه‌ی سایت وردپرس غیرفعال است (یا یک افزونه آن را خاموش کرده)؛ نشانی سایت را به سرچ کنسول ندهید و دستی معرفی کنید.', 'hami-ai-assistant' ),
                        );
                } else {
                        $checks[] = array(
                                'label' => __( 'نقشه‌ی سایت XML', 'hami-ai-assistant' ),
                                'ok'    => false,
                                'note'  => __( 'این نسخه‌ی وردپرس نقشه‌ی سایت داخلی ندارد (۵.۵ به بعد لازم است).', 'hami-ai-assistant' ),
                        );
                }

                $seo = self::seo_plugin_name();
                $checks[] = array(
                        'label' => __( 'افزونه‌ی سئو', 'hami-ai-assistant' ),
                        'ok'    => '' !== $seo,
                        'info'  => true,
                        'note'  => '' !== $seo
                                ? sprintf( /* translators: %s: plugin name. */ __( '%s فعال است؛ تیتر و توضیح متا را همان افزونه مدیریت می‌کند.', 'hami-ai-assistant' ), $seo )
                                : __( 'افزونه‌ی سئویی نصب نیست؛ محتوای تولیدی هامی عنوان سئو و توضیح را در خود نوشته و فیلدهای استاندارد وردپرس ذخیره می‌کند.', 'hami-ai-assistant' ),
                );

                $kb = Haa_Search::stats();
                $ok = (int) $kb['items'] > 0;
                $checks[] = array(
                        'label' => __( 'پایگاه دانش چت‌بات', 'hami-ai-assistant' ),
                        'ok'    => $ok,
                        'note'  => $ok
                                ? sprintf( /* translators: 1: indexed posts, 2: chunks. */ __( '%1$s صفحه / %2$s تکه ایندکس شده است.', 'hami-ai-assistant' ), number_format_i18n( (int) $kb['items'] ), number_format_i18n( (int) $kb['chunks'] ) )
                                : __( 'هنوز چیزی ایندکس نشده؛ از داشبورد، «کرال و به‌روزرسانی دانش سایت» را بزنید.', 'hami-ai-assistant' ),
                );

                $ok = (bool) Haa_Settings::get( 'knowledge.llms_enabled', 1 );
                $checks[] = array(
                        'label' => __( 'فایل llms.txt برای ربات‌های هوش مصنوعی', 'hami-ai-assistant' ),
                        'ok'    => $ok,
                        'note'  => $ok
                                ? sprintf( /* translators: %s: URL. */ __( 'فعال است: %s', 'hami-ai-assistant' ), self::llms_url() )
                                : __( 'خاموش است؛ در تنظیمات ← دانش سایت روشنش کنید تا ChatGPT و Perplexity سایت را بهتر معرفی‌شده ببینند.', 'hami-ai-assistant' ),
                );

                $tagline = trim( (string) get_bloginfo( 'description' ) );
                $ok      = '' !== $tagline && 'Just another WordPress site' !== $tagline;
                $checks[] = array(
                        'label' => __( 'معرفی کوتاه سایت', 'hami-ai-assistant' ),
                        'ok'    => $ok,
                        'note'  => $ok
                                ? sprintf( /* translators: %s: tagline. */ __( 'معرفی فعلی: «%s»', 'hami-ai-assistant' ), $tagline )
                                : __( 'شعار سایت در تنظیمات ← همگانی خالی یا پیش‌فرض است؛ همین یک خط در نتایج جستجو و معرفی ربات‌ها دیده می‌شود.', 'hami-ai-assistant' ),
                );

                $hidden = get_posts( array( 'post_type' => array( 'post', 'page', 'product' ), 'post_status' => 'any', 'meta_key' => '_haai_noindex', 'meta_value' => '1', 'numberposts' => 200, 'fields' => 'ids' ) );
                $checks[] = array(
                        'label' => __( 'صفحات تولیدیِ کنارگذاشته از ایندکس', 'hami-ai-assistant' ),
                        'ok'    => true,
                        'info'  => true,
                        'note'  => sprintf( /* translators: %d: count. */ __( '%d صفحه (معمولاً صفحات الگوی برنامه‌ای) عمداً noindex هستند؛ خطرنی نیستند مگر تعدادشان غیرعادی شود.', 'hami-ai-assistant' ), count( (array) $hidden ) ),
                );

                return $checks;
        }

        /**
         * Name of the active SEO plugin, or '' when none.
         *
         * @return string
         */
        public static function seo_plugin_name() {
                if ( defined( 'WPSEO_VERSION' ) ) {
                        return 'Yoast SEO';
                }
                if ( class_exists( 'RankMath' ) ) {
                        return 'Rank Math';
                }
                if ( defined( 'AIOSEO_VERSION' ) || function_exists( 'aioseo' ) ) {
                        return 'All in One SEO';
                }
                return '';
        }

        /* ==================================================================
         * Product SEO scores
         * =============================================================== */

        /**
         * Normalized score state.
         *
         * @return array{cursor:int,scores:array<int,array>,cycle_at:int,at:int}
         */
        private static function scores_state() {
                $state = get_option( self::OPT_SCORES );
                if ( ! is_array( $state ) || ! isset( $state['cursor'], $state['scores'] ) || ! is_array( $state['scores'] ) ) {
                        $state = array( 'cursor' => 0, 'scores' => array(), 'cycle_at' => 0, 'at' => 0 );
                }
                $state['cursor']   = (int) $state['cursor'];
                $state['cycle_at'] = (int) ( $state['cycle_at'] ?? 0 );
                $state['at']       = (int) ( $state['at'] ?? 0 );
                return $state;
        }

        /**
         * Score one product: seven yes/no checks, weights sum to 100, a noindex
         * marker costs 10. The issues array IS the report — every miss carries
         * its fix in Persian.
         *
         * @param int $pid Product id.
         * @return array{score:int,issues:array<int,string>}
         */
        public static function score_of( $pid ) {
                $pid    = (int) $pid;
                $issues = array();
                $score  = 0;

                $title = trim( (string) get_the_title( $pid ) );
                $len   = function_exists( 'mb_strlen' ) ? mb_strlen( $title ) : strlen( $title );
                if ( $len >= 15 && $len <= 70 ) {
                        $score += 14;
                } else {
                        $issues[] = sprintf( /* translators: %d: length. */ __( 'طول عنوان %d نویسه است؛ بازه‌ی درست ۱۵ تا ۷۰', 'hami-ai-assistant' ), $len );
                }

                if ( '' !== trim( (string) get_post_field( 'post_excerpt', $pid ) ) ) {
                        $score += 10;
                } else {
                        $issues[] = __( 'توضیح کوتاه (خلاصه‌ی محصول) ندارد', 'hami-ai-assistant' );
                }

                $body = self::plain( (string) get_post_field( 'post_content', $pid ) );
                $blen = function_exists( 'mb_strlen' ) ? mb_strlen( $body ) : strlen( $body );
                if ( $blen >= 300 ) {
                        $score += 20;
                } else {
                        $issues[] = __( 'متن محصول کوتاه است (زیر ۳۰۰ نویسه)', 'hami-ai-assistant' );
                }

                if ( (int) get_post_thumbnail_id( $pid ) > 0 ) {
                        $score += 14;
                } else {
                        $issues[] = __( 'تصویر شاخص ندارد', 'hami-ai-assistant' );
                }

                $cats = get_the_terms( $pid, 'product_cat' );
                if ( $cats && ! is_wp_error( $cats ) ) {
                        $score += 12;
                } else {
                        $issues[] = __( 'دسته‌بندی ندارد', 'hami-ai-assistant' );
                }

                $tags = get_the_terms( $pid, 'product_tag' );
                $attr = get_post_meta( $pid, '_product_attributes', true );
                if ( ( $tags && ! is_wp_error( $tags ) ) || ( is_array( $attr ) && $attr ) ) {
                        $score += 10;
                } else {
                        $issues[] = __( 'نه برچسب دارد نه ویژگی', 'hami-ai-assistant' );
                }

                $content = (string) get_post_field( 'post_content', $pid );
                $faq     = get_post_meta( $pid, '_haai_faq', true );
                // 2.1.24 — stripos (core) instead of mb_stripos: WordPress polyfills
                // mb_strlen/mb_substr only, so a host without the mbstring extension
                // fatals right here. Byte-wise matching is exact for UTF-8 needles.
                if ( ( is_array( $faq ) && $faq ) || false !== stripos( $content, 'متداول' ) ) {
                        $score += 20;
                } else {
                        $issues[] = __( 'پرسش‌های متداول ندارد', 'hami-ai-assistant' );
                }

                if ( '1' === (string) get_post_meta( $pid, '_haai_noindex', true ) ) {
                        $score   -= 10;
                        $issues[] = __( 'از نمای موتورهای جستجو کنار گذاشته شده (noindex)', 'hami-ai-assistant' );
                }

                return array( 'score' => max( 0, min( 100, $score ) ), 'issues' => $issues );
        }

        /**
         * Score the next batch of products (ID cursor, wraps into a cycle).
         *
         * @param int $limit Products this batch.
         * @return array{ok:bool,done?:int,scored?:int,complete?:bool,error?:string}
         */
        public static function score_batch( $limit = self::SCORES_MAX ) {
                global $wpdb;
                if ( ! function_exists( 'haa_is_woo' ) || ! haa_is_woo() ) {
                        return array( 'ok' => false, 'error' => __( 'WooCommerce در این سایت فعال نیست.', 'hami-ai-assistant' ) );
                }
                $limit = max( 1, (int) $limit );
                $state = self::scores_state();

                // phpcs:ignore WordPress.DB.DirectDatabaseQuery
                $ids = $wpdb->get_col( $wpdb->prepare(
                        "SELECT ID FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_type = 'product' AND ID > %d ORDER BY ID ASC LIMIT %d",
                        $state['cursor'],
                        $limit
                ) );
                if ( ! is_array( $ids ) || ! $ids ) {
                        // Cursor past the tail: the cycle is complete, next batch restarts.
                        $state['cycle_at'] = time();
                        $state['cursor']   = 0;
                        update_option( self::OPT_SCORES, $state, false );
                        return array( 'ok' => true, 'done' => 0, 'scored' => count( $state['scores'] ), 'complete' => true );
                }

                foreach ( $ids as $pid ) {
                        $pid                       = (int) $pid;
                        $state['scores'][ $pid ]   = self::score_of( $pid );
                }

                $complete = count( $ids ) < $limit; // Short batch = the tail was reached.
                if ( $complete ) {
                        $state['cycle_at'] = time();
                        $state['cursor']   = 0;
                } else {
                        $state['cursor'] = (int) max( $ids );
                }
                $state['at'] = time();
                update_option( self::OPT_SCORES, $state, false );

                return array( 'ok' => true, 'done' => count( $ids ), 'scored' => count( $state['scores'] ), 'complete' => $complete );
        }

        /**
         * Distribution + the twenty weakest products (still-published only).
         *
         * @return array{scored:int,good:int,mid:int,low:int,worst:array,cycle_at:int,at:int}
         */
        public static function scores_summary() {
                $state = self::scores_state();
                $good  = 0;
                $mid   = 0;
                $low   = 0;
                foreach ( $state['scores'] as $row ) {
                        $s = (int) ( $row['score'] ?? 0 );
                        if ( $s >= 80 ) {
                                $good++;
                        } elseif ( $s >= self::SCORE_PASS ) {
                                $mid++;
                        } else {
                                $low++;
                        }
                }
                $scores = $state['scores'];
                uasort( $scores, function ( $x, $y ) {
                        return (int) $x['score'] <=> (int) $y['score'];
                } );
                $worst = array();
                foreach ( $scores as $pid => $row ) {
                        if ( count( $worst ) >= 20 ) {
                                break;
                        }
                        if ( ! get_post( (int) $pid ) ) {
                                continue; // Deleted since the scan; it will leave on the next cycle.
                        }
                        $worst[] = array(
                                'pid'    => (int) $pid,
                                'title'  => (string) get_the_title( (int) $pid ),
                                'score'  => (int) ( $row['score'] ?? 0 ),
                                'issues' => (array) ( $row['issues'] ?? array() ),
                        );
                }
                return array(
                        'scored'   => count( $state['scores'] ),
                        'good'     => $good,
                        'mid'      => $mid,
                        'low'      => $low,
                        'worst'    => $worst,
                        'cycle_at' => $state['cycle_at'],
                        'at'       => $state['at'],
                );
        }

        /**
         * Wipe the cached scores (manual).
         *
         * @return void
         */
        public static function scores_reset() {
                delete_option( self::OPT_SCORES );
        }

        /**
         * Queue «محتوای محصول» jobs for every scored product below the pass mark.
         *
         * @return array{ok:bool,queued?:int,remaining?:int,cap?:int,error?:string}
         */
        public static function optimize_worst() {
                if ( ! function_exists( 'haa_is_woo' ) || ! haa_is_woo() ) {
                        return array( 'ok' => false, 'error' => __( 'WooCommerce در این سایت فعال نیست.', 'hami-ai-assistant' ) );
                }
                $state = self::scores_state();
                if ( ! $state['scores'] ) {
                        return array( 'ok' => false, 'error' => __( 'هنوز امتیازی محاسبه نشده؛ اول دکمه‌ی «محاسبه‌ی امتیازها» را بزنید.', 'hami-ai-assistant' ) );
                }
                $ids = array();
                foreach ( $state['scores'] as $pid => $row ) {
                        if ( (int) ( $row['score'] ?? 0 ) < self::SCORE_PASS && get_post( (int) $pid ) ) {
                                $ids[] = (int) $pid;
                        }
                }
                if ( ! $ids ) {
                        return array( 'ok' => false, 'error' => __( 'هیچ محصولی زیر آستانه‌ی امتیاز نیست.', 'hami-ai-assistant' ) );
                }
                sort( $ids );
                $cap  = (int) Haa_Module_Product_Content::select_cap();
                $take = array_slice( $ids, 0, $cap );
                $queued = 0;
                foreach ( $take as $pid ) {
                        $job = Haa_Queue::push(
                                'pc_content',
                                array( 'product_id' => $pid, 'tone' => '', 'length' => '', 'keywords' => '', 'with_image' => 0 ),
                                array( 'module' => 'product-content' )
                        );
                        if ( $job ) {
                                $queued++;
                        }
                }
                return array(
                        'ok'        => true,
                        'queued'    => $queued,
                        'remaining' => max( 0, count( $ids ) - $queued ),
                        'cap'       => $cap,
                );
        }

        /* ==================================================================
         * Reports AJAX — one guarded endpoint for the reports screen
         * =============================================================== */

        /**
         * POST do = scan_links | clear_links | score_batch | reset_scores |
         *            optimize_worst | rebuild_llms
         *
         * @return void
         */
        public static function ajax_reports() {
                Haa_Util::guard_admin_ajax();
                $do = isset( $_POST['do'] ) ? sanitize_key( wp_unslash( $_POST['do'] ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput

                if ( 'scan_links' === $do ) {
                        wp_send_json( array( 'ok' => true, 'res' => self::scan_links_batch( self::LINKS_PER_MANUAL, self::LINKS_POSTS_PER_RUN ) ) );
                }
                if ( 'clear_links' === $do ) {
                        self::links_clear();
                        wp_send_json( array( 'ok' => true ) );
                }
                if ( 'score_batch' === $do ) {
                        wp_send_json( array( 'ok' => true, 'res' => self::score_batch() ) );
                }
                if ( 'reset_scores' === $do ) {
                        self::scores_reset();
                        wp_send_json( array( 'ok' => true ) );
                }
                if ( 'optimize_worst' === $do ) {
                        wp_send_json( self::optimize_worst() );
                }
                if ( 'rebuild_llms' === $do ) {
                        self::mark_llms_stale();
                        $text = self::llms_text();
                        wp_send_json( array( 'ok' => true, 'chars' => function_exists( 'mb_strlen' ) ? mb_strlen( $text ) : strlen( $text ), 'url' => self::llms_url() ) );
                }

                wp_send_json( array( 'ok' => false, 'error' => __( 'درخواست ناشناخته است.', 'hami-ai-assistant' ) ) );
        }
}
