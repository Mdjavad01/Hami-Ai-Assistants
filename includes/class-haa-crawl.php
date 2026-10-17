<?php
/**
 * Site knowledge crawler — fetches rendered pages, stores them as knowledge
 * chunks and distils the shop's contact facts (phone, Instagram, Telegram,
 * WhatsApp, e-mail, contact-page link) into one always-findable chunk.
 *
 * Why a crawler at all: the knowledge base is built from wp_posts (plus the
 * Elementor meta pass), so anything that lives in widgets, menus, the footer
 * or a page-builder's rendered markup never reaches the bot — which is exactly
 * where "شماره تماس / آدرس اینستاگرام" copy lives on real shops. Fetching the
 * rendered HTML closes that gap with one bounded HTTP request per page.
 *
 * Lightweight by design:
 *   - resumable, batched state machine (2 pages per tick, 12 s budget) — the
 *     same contract as the KB rebuild, driven from the dashboard;
 *   - one GET per page, HTML-only, body capped, short timeouts, no recursion
 *     beyond the links discovered on the homepage;
 *   - everything lands in the EXISTING chunks table with negative synthetic
 *     post ids, so retrieval, purge and (optional) embeddings need zero new
 *     tables and zero new query paths.
 *
 * @package Hami_AI_Assistant
 */

if ( ! defined( 'ABSPATH' ) ) {
        exit;
}

final class Haa_Crawl {

        const STATE_OPTION    = 'hami_ai_crawl_state';
        const CONTACT_POST_ID = -1;      // Fixed slot for the contact-facts chunk.
        const BODY_CAP        = 524288;  // 512 KB of HTML is plenty for one page.
        const MIN_TEXT        = 80;      // Below this a fetched page is junk.
        const MAX_URLS        = 100;     // Hard ceiling regardless of settings.
        const MAX_CHUNKS      = 400;     // Total crawl chunks a site may hold.

        /** @var array|null Cached crawl config for this request. */
        private static $config = null;

        /* ==================================================================
         * Config
         * =============================================================== */

        /**
         * Crawl scope from the «دانش سایت» tab.
         *
         * @return array{pages:int,urls:array<int,string>,discover:bool,types:array<int,string>}
         */
        public static function config() {
                if ( null !== self::$config ) {
                        return self::$config;
                }
                $urls = array();
                foreach ( preg_split( '/\R+/u', (string) Haa_Settings::get( 'knowledge.urls', '' ) ) as $line ) {
                        $url = self::safe_url( (string) $line );
                        if ( $url ) {
                                $urls[] = $url;
                        }
                }
                // Types the admin ticked; 'product' needs WooCommerce and silently
                // drops on a non-shop install (no CPT, no harm).
                $types = array_intersect( (array) Haa_Settings::get( 'knowledge.crawl_types', array( 'page' ) ), array( 'page', 'product', 'post' ) );
                self::$config = array(
                        'pages'    => max( 5, min( 100, (int) Haa_Settings::get( 'knowledge.max_pages', 30 ) ) ),
                        'urls'     => array_values( array_unique( $urls ) ),
                        'discover' => ! empty( Haa_Settings::get( 'knowledge.auto_crawl', 1 ) ),
                        'types'    => array_values( $types ? $types : array( 'page' ) ),
                );
                return self::$config;
        }

        /**
         * Call-time URL guard (mirrors Haa_API's policy: format checks at save
         * time, IP-literal / localhost checks at call time, because a public
         * domain can point at 127.0.0.1 tomorrow).
         *
         * @param string $raw Raw URL.
         * @return string Absolute URL or ''.
         */
        public static function safe_url( $raw ) {
                $raw = is_scalar( $raw ) ? trim( (string) $raw ) : '';
                if ( '' === $raw || strlen( $raw ) > 500 ) {
                        return '';
                }
                if ( ! preg_match( '#^https?://#i', $raw ) ) {
                        if ( preg_match( '#^(mailto|tel|sms|javascript|data|file|ftp|ssh|ws|wss):#i', $raw ) ) {
                                return '';
                        }
                        $raw = 'https://' . ltrim( $raw, '/' );
                }
                $url = esc_url_raw( $raw, array( 'http', 'https' ) );
                if ( '' === $url ) {
                        return '';
                }
                $host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
                if ( '' === $host ) {
                        return '';
                }
                $allow_local = ! empty( Haa_Settings::get( 'provider.allow_localhost' ) );
                if ( ! $allow_local && preg_match( '/^(localhost|127\.|10\.|192\.168\.|169\.254\.|0\.|\[::1\]|\[fc|\[fd|\[fe80)/i', $host ) ) {
                        return '';
                }
                if ( ! $allow_local && preg_match( '/\.(local|localhost|internal)$/i', $host ) ) {
                        return '';
                }
                return $url;
        }

        /**
         * Deterministic negative post id for a crawled URL. Same URL → same slot,
         * so re-crawling REPLACES its rows instead of duplicating them.
         *
         * @param string $url Absolute URL.
         * @return int Negative id in [-268435457, -1).
         */
        public static function slot_id( $url ) {
                $n = (int) hexdec( substr( md5( self::canon_url( $url ) ), 0, 7 ) );
                return -( $n % 268435456 + 2 );
        }

        /**
         * Normalise a URL for comparison: lowercase host, no fragment, no trailing
         * slash difference, drop the usual WordPress no-op query args.
         *
         * @param string $url URL.
         * @return string
         */
        public static function canon_url( $url ) {
                $url = (string) $url;
                $p   = wp_parse_url( $url );
                if ( empty( $p['host'] ) ) {
                        return strtolower( rtrim( $url, '/' ) );
                }
                $scheme = strtolower( (string) ( $p['scheme'] ?? 'https' ) );
                $host   = strtolower( (string) $p['host'] );
                $path   = rtrim( (string) ( $p['path'] ?? '/' ), '/' );
                if ( '' === $path ) {
                        $path = '';
                }
                $query = (string) ( $p['query'] ?? '' );
                if ( '' !== $query ) {
                        parse_str( $query, $q );
                        foreach ( array( 'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content', 'fbclid', 'gclid', 'replytocom' ) as $noise ) {
                                unset( $q[ $noise ] );
                        }
                        $query = http_build_query( $q );
                }
                return $scheme . '://' . $host . $path . ( '' !== $query ? '?' . $query : '' );
        }

        /* ==================================================================
         * Crawl targets
         * =============================================================== */

        /**
         * Published counts per crawlable type — labels in the «دانش سایت» tab and
         * the dashboard scope preview («۳۲ برگه + ۹۵ محصول») so the admin sees
         * the SCALE of what is about to be queued BEFORE pressing the button.
         * wp_count_posts is a cached single-row read per type — dashboard-safe.
         *
         * @return array{page:int,post:int,product:int}
         */
        public static function type_counts() {
                $out = array( 'page' => 0, 'post' => 0, 'product' => 0 );
                foreach ( array_keys( $out ) as $type ) {
                        if ( 'product' === $type && ! haa_is_woo() ) {
                                continue;
                        }
                        $c = wp_count_posts( $type );
                        $out[ $type ] = ( $c && isset( $c->publish ) ) ? (int) $c->publish : 0;
                }
                return $out;
        }

        /**
         * Assemble the target list, PRIORITY-ORDERED (this is the «اولویت‌بندی»
         * the admin asked for on heavy sites):
         *
         *   0. homepage           — always #0: link discovery reads its HTML;
         *   1. admin URLs         — deliberate, asked-for knowledge first;
         *   2. pages (opt-in)     — contact/about live here, oldest first so
         *                           long-lived pages never lose their slot;
         *   3. products (opt-in)  — newest first (a shop bot must know today's
         *                           catalogue, not last year's);
         *   4. posts (opt-in)     — newest first.
         *
         * Each step only consumes what budget (max_pages) remains, so a big site
         * fills the queue with the pages that answer real questions and leaves
         * the long tail out.
         *
         * @return array<int,string>
         */
        public static function targets() {
                $cfg   = self::config();
                $cap   = min( self::MAX_URLS, $cfg['pages'] );
                $types = (array) ( $cfg['types'] ?? array( 'page' ) );

                $urls = array();
                $seen = array();
                $add  = function ( $url ) use ( &$urls, &$seen, $cap ) {
                        $url = self::safe_url( (string) $url );
                        if ( ! $url ) {
                                return false;
                        }
                        $key = self::canon_url( $url );
                        if ( isset( $seen[ $key ] ) || count( $urls ) >= $cap ) {
                                return false;
                        }
                        $seen[ $key ] = true;
                        $urls[]       = $url;
                        return true;
                };

                // 0) Homepage — also feeds link discovery from offset 0.
                $add( home_url( '/' ) );

                // 1) Admin-entered URLs win right after the homepage (they are deliberate).
                foreach ( $cfg['urls'] as $u ) {
                        if ( count( $urls ) >= $cap ) {
                                break;
                        }
                        $add( $u );
                }

                // 2-4) Ticked content types, each bounded by the remaining budget.
                foreach ( array( 'page', 'product', 'post' ) as $type ) {
                        if ( ! in_array( $type, $types, true ) || count( $urls ) >= $cap ) {
                                continue;
                        }
                        $ids = get_posts(
                                array(
                                        'post_type'        => $type,
                                        'post_status'      => 'publish',
                                        'post_password'    => '',
                                        'posts_per_page'   => min( 100, $cap - count( $urls ) ),
                                        'orderby'          => 'date',
                                        'order'            => 'page' === $type ? 'ASC' : 'DESC',
                                        'fields'           => 'ids',
                                        'no_found_rows'    => true,
                                        'suppress_filters' => true,
                                )
                        );
                        foreach ( (array) $ids as $pid ) {
                                if ( count( $urls ) >= $cap ) {
                                        break;
                                }
                                $add( (string) get_permalink( (int) $pid ) );
                        }
                }

                return $urls;
        }

        /**
         * Resolve a possibly-relative href against a base URL (nav menus and
         * hand-written pages routinely emit "?post=…" or "/about" links).
         *
         * @param string $href Raw href.
         * @param string $base Absolute URL of the page the href was found on.
         * @return string Absolute URL or ''.
         */
        public static function abs_url( $href, $base ) {
                $href = trim( (string) $href );
                if ( '' === $href ) {
                        return '';
                }
                if ( preg_match( '#^https?://#i', $href ) ) {
                        return $href;
                }
                if ( 0 === strpos( $href, '//' ) ) {
                        return 'https:' . $href;
                }
                $p    = wp_parse_url( (string) $base );
                $host = (string) ( $p['host'] ?? '' );
                if ( '' === $host ) {
                        return '';
                }
                $scheme = (string) ( $p['scheme'] ?? 'https' );
                if ( 0 === strpos( $href, '?' ) ) {
                        return $scheme . '://' . $host . (string) ( $p['path'] ?? '/' ) . $href;
                }
                if ( 0 === strpos( $href, '/' ) ) {
                        return $scheme . '://' . $host . $href;
                }
                return $scheme . '://' . $host . '/' . $href;
        }

        /**
         * Internal links from a fetched homepage (nav/menus/footer) — same host,
         * http(s) only, no assets/feeds/admin, deduped against what we already
         * crawl and capped by the remaining budget.
         *
         * @param string $html      Homepage HTML.
         * @param array  $existing  Already-queued URLs (canonical keys).
         * @param int    $remaining How many more may be queued.
         * @return array<int,string>
         */
        public static function links_from_home( $html, $existing, $remaining ) {
                $links = array();
                if ( $remaining <= 0 || '' === (string) $html ) {
                        return $links;
                }

                $home_host = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
                if ( '' === $home_host ) {
                        return $links;
                }

                if ( ! preg_match_all( '/<a[^>]+href=["\']([^"\'#]+)["\']/iu', (string) $html, $m ) ) {
                        return $links;
                }

                // Utility/commerce URLs are NEVER knowledge: carts and checkouts are
                // per-customer screens, add-to-cart links are actions, attachments and
                // pagination are filler — on a heavy shop these would otherwise eat
                // the whole budget without teaching the bot anything.
                $skip = '#(/\.(?:css|js|png|jpe?g|gif|svg|webp|ico|woff2?|ttf|pdf|zip|xml|json)$|/wp-admin|/wp-login|/wp-json|/feed/?$|\?p=|\?page_id=|\?attachment_id=|\?add-to-cart=|/comment|/cart/?$|/checkout|/my-account|/page/\d+|mailto:|tel:)#i';
                $seen = array();
                foreach ( $m[1] as $href ) {
                        if ( count( $links ) >= $remaining ) {
                                break;
                        }
                        $url = self::safe_url( self::abs_url( html_entity_decode( (string) $href, ENT_QUOTES, 'UTF-8' ), home_url( '/' ) ) );
                        if ( ! $url || preg_match( $skip, $url ) ) {
                                continue;
                        }
                        if ( strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) ) !== $home_host ) {
                                continue;
                        }
                        $key = self::canon_url( $url );
                        if ( isset( $existing[ $key ] ) || isset( $seen[ $key ] ) ) {
                                continue;
                        }
                        $seen[ $key ] = 1;
                        $links[]      = $url;
                }
                return $links;
        }

        /* ==================================================================
         * State machine (mirrors Haa_Search's rebuild)
         * =============================================================== */

        /** @return array */
        public static function get_state() {
                $state = get_option( self::STATE_OPTION, array() );
                return wp_parse_args(
                        is_array( $state ) ? $state : array(),
                        array(
                                'running'    => 0,
                                'offset'     => 0,
                                'total'      => 0,
                                'pages'      => 0,   // Successfully fetched pages.
                                'chunks'     => 0,   // Chunk rows written so far.
                                'urls'       => array(),
                                'keys'       => array(), // Canonical keys of queued URLs.
                                'facts'      => array(), // Accumulated contact-fact lines.
                                'last_error' => '',
                                'finished_at' => 0,
                                // Live heartbeat: what the crawler was last reading and
                                // when — the dashboard shows it between batches so the
                                // progress bar can never look frozen on a slow host.
                                'current'    => '',
                                'current_at' => 0,
                                // Timing accumulator for the ETA (ms spent per page).
                                'ms_sum'     => 0,
                                'ms_n'       => 0,
                                'started_at' => 0,
                        )
                );
        }

        /** @param array $state State. */
        private static function save_state( $state ) {
                update_option( self::STATE_OPTION, $state, 'no' );
        }

        /**
         * Start (or RESUME) a crawl. Never runs on the visitor path and never
         * when RAG is disabled (the rows would simply never be searched).
         *
         * A crawl that is still marked running is RESUMED as-is: the dashboard's
         * «ادامهِ خزش» button must never throw away hours of a heavy site's
         * progress (2.1.14 reset the offset on every click — fixed here). Only
         * $reset=true (the explicit «شروع از نو» button) rebuilds the queue.
         *
         * @param bool $reset True = discard progress and rebuild the queue.
         * @return array|WP_Error Progress on success.
         */
        public static function start( $reset = false ) {
                if ( ! Haa_Search::config()['enabled'] ) {
                        return new WP_Error( 'haai_crawl_rag_off', __( 'ابتدا «پایگاه دانش (RAG)» را در تب پیشرفته روشن کنید.', 'hami-ai-assistant' ) );
                }

                $state = self::get_state();
                if ( ! $reset && ! empty( $state['running'] ) && (int) $state['offset'] > 0 && (int) $state['total'] > 0 ) {
                        // Resume: keep the cursor exactly where it stopped.
                        return self::progress();
                }

                $urls = self::targets();
                if ( ! $urls ) {
                        return new WP_Error( 'haai_crawl_no_urls', __( 'هیچ صفحه‌ای برای خزش پیدا نشد.', 'hami-ai-assistant' ) );
                }

                // Crawl rows are replaced per-URL; a stale contact slot from an older
                // run is refreshed by the first batch anyway.
                self::save_state(
                        array(
                                'running'     => 1,
                                'offset'      => 0,
                                'total'       => count( $urls ),
                                'pages'       => 0,
                                'chunks'      => 0,
                                'urls'        => $urls,
                                'keys'        => array_fill_keys( array_map( array( __CLASS__, 'canon_url' ), $urls ), 1 ),
                                'facts'       => array(),
                                'last_error'  => '',
                                'finished_at' => 0,
                                'current'     => '',
                                'current_at'  => 0,
                                'ms_sum'      => 0,
                                'ms_n'        => 0,
                                'started_at'  => time(),
                        )
                );
                return self::progress();
        }

        /**
         * Fetch + index the next pages. Two pages per tick with a hard time
         * budget, so a 30 s host limit is never hit and the dashboard ticker can
         * drive the whole crawl with one AJAX call per batch.
         *
         * Every page gets a HEARTBEAT write (current URL + timestamp) BEFORE its
         * fetch, plus a running timing average — so between batches the dashboard
         * can say exactly what is being read and how long is left, and a batch
         * that dies mid-fetch leaves an honest breadcrumb instead of silence.
         *
         * @param int $batch_size Pages per call.
         * @return array Progress.
         */
        public static function batch( $batch_size = 2 ) {
                $started = microtime( true );
                $state   = self::get_state();
                $urls    = (array) ( $state['urls'] ?? array() );

                if ( empty( $state['running'] ) || ! $urls ) {
                        if ( ! empty( $state['running'] ) ) {
                                $state['running'] = 0;
                                self::save_state( $state );
                        }
                        return self::progress();
                }

                $batch_size = max( 1, min( 4, (int) $batch_size ) );
                $processed  = 0;

                while ( $state['offset'] < count( $urls ) ) {
                        $url = (string) $urls[ $state['offset'] ];

                        // Heartbeat FIRST — a fetch stuck at the server timeout still
                        // left a trail the dashboard can show («در حال خواندن: …»). 
                        $state['current']    = $url;
                        $state['current_at'] = time();
                        self::save_state( $state );

                        $t0   = microtime( true );
                        $html = self::fetch( $url );

                        if ( is_wp_error( $html ) ) {
                                // One unreachable page must not poison the run — move on quietly.
                                $state['last_error'] = '';
                        } else {
                                $stored = self::store_page( $url, $html );
                                $state['pages']  += $stored['pages'];
                                $state['chunks'] += $stored['chunks'];
                                self::collect_facts( $state, $url, $html );

                                // Homepage: discover internal links while budget remains.
                                if ( 0 === (int) $state['offset'] && self::config()['discover'] ) {
                                        $remaining = min( self::MAX_URLS, self::config()['pages'] ) - count( $urls );
                                        $more      = self::links_from_home( $html, (array) ( $state['keys'] ?? array() ), $remaining );
                                        if ( $more ) {
                                                $urls              = array_merge( $urls, $more );
                                                $state['urls']     = $urls;
                                                $state['keys']     = array_merge( (array) ( $state['keys'] ?? array() ), array_fill_keys( array_map( array( __CLASS__, 'canon_url' ), $more ), 1 ) );
                                                $state['total']    = count( $urls );
                                        }
                                }
                        }

                        $state['offset']++;
                        $processed++;
                        $state['ms_sum'] += (int) round( ( microtime( true ) - $t0 ) * 1000 );
                        $state['ms_n']++;

                        if ( $processed >= $batch_size || ( microtime( true ) - $started ) > 10 ) {
                                break;
                        }
                }

                self::write_contact_chunk( $state );

                if ( $state['offset'] >= count( $urls ) ) {
                        $state['running']     = 0;
                        $state['finished_at'] = time();
                }
                self::save_state( $state );
                return self::progress();
        }

        /** @return array Progress summary for the dashboard ticker. */
        public static function progress() {
                $state = self::get_state();
                $total = max( 1, (int) $state['total'] );
                $left  = max( 0, (int) $state['total'] - (int) $state['offset'] );

                // ETA from the running per-page average (worst-case floor 2 s/page
                // so the very first ticks already show a plausible number).
                $avg_ms = ( (int) $state['ms_n'] > 0 ) ? ( (int) $state['ms_sum'] / (int) $state['ms_n'] ) : 3000.0;
                $eta_s  = (int) round( $left * max( 2000.0, $avg_ms ) / 1000 );

                return array(
                        'running'     => (int) $state['running'],
                        'done'        => (int) $state['offset'] >= (int) $state['total'] && ! $state['running'],
                        'offset'      => (int) $state['offset'],
                        'total'       => (int) $state['total'],
                        'pages_left'  => $left,
                        'pages'       => (int) $state['pages'],
                        'chunks'      => (int) $state['chunks'],
                        'percent'     => (int) min( 100, round( ( (int) $state['offset'] / $total ) * 100 ) ),
                        'current'     => (string) ( $state['current'] ?? '' ),
                        'current_at'  => (int) ( $state['current_at'] ?? 0 ),
                        'eta_s'       => $eta_s,
                        'elapsed_s'   => ! empty( $state['started_at'] ) ? max( 0, time() - (int) $state['started_at'] ) : 0,
                        'last_error'  => (string) $state['last_error'],
                        'finished_at' => (int) $state['finished_at'],
                        'stats'       => self::stats(),
                );
        }

        /** @return array Progress summary (alias kept for symmetry with rebuild). */
        public static function rebuild_progress() {
                return self::progress();
        }

        /** @return array Cancel a running crawl (progress already made is kept). */
        public static function cancel() {
                $state                = self::get_state();
                $state['running']     = 0;
                $state['finished_at'] = time();
                self::save_state( $state );
                return self::progress();
        }

        /** @return array Drop every crawl-owned chunk (manual wipe). */
        public static function wipe() {
                global $wpdb;
                $table = Haa_DB::table( 'chunks' );
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery
                $wpdb->query( "DELETE FROM $table WHERE post_id < 0" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

                if ( Haa_Settings::is_module_enabled( 'semantic-search' ) ) {
                        $vectors = Haa_DB::table( 'vectors' );
                        $chunks  = Haa_DB::table( 'chunks' );
                        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
                        $wpdb->query( "DELETE v FROM $vectors v LEFT JOIN $chunks c ON c.id = v.chunk_id WHERE c.id IS NULL" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                }

                Haa_DB::flush_cache();
                $state = self::get_state();
                $state['running']     = 0;
                $state['pages']       = 0;
                $state['chunks']      = 0;
                $state['finished_at'] = 0;
                self::save_state( $state );
                return self::progress();
        }

        /** @return array{chunks:int,pages:int} Crawl-owned rows in the KB. */
        public static function stats() {
                global $wpdb;
                $table = Haa_DB::table( 'chunks' );
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery
                $row = $wpdb->get_row( "SELECT COUNT(*) AS chunks, COUNT(DISTINCT post_id) AS pages FROM $table WHERE post_id < 0", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                return array(
                        'chunks' => $row ? (int) $row['chunks'] : 0,
                        'pages'  => $row ? (int) $row['pages'] : 0,
                );
        }

        /* ==================================================================
         * Fetch + store
         * =============================================================== */

        /**
         * One bounded GET. HTML-only (the plugin indexes text, and skipping
         * PDFs/images/JSON keeps both the bandwidth and the parsing light).
         *
         * @param string $url Absolute URL.
         * @return string|WP_Error HTML body (capped) or an error.
         */
        public static function fetch( $url ) {
                $res = wp_remote_get(
                        $url,
                        array(
                                'timeout'     => 6,
                                'redirection' => 2,
                                'decompress'  => true,
                                'limit_response_size' => self::BODY_CAP,
                                'user-agent'  => 'HamiAI-Crawler (+ ' . home_url( '/' ) . ')',
                        )
                );
                if ( is_wp_error( $res ) ) {
                        return $res;
                }
                $code = (int) wp_remote_retrieve_response_code( $res );
                if ( $code < 200 || $code >= 300 ) {
                        return new WP_Error( 'haai_crawl_http', 'HTTP ' . $code );
                }
                $type = strtolower( (string) wp_remote_retrieve_header( $res, 'content-type' ) );
                if ( '' !== $type && false === strpos( $type, 'text/html' ) && false === strpos( $type, 'application/xhtml' ) ) {
                        return new WP_Error( 'haai_crawl_type', 'not html: ' . strtok( $type, ';' ) );
                }
                $body = (string) wp_remote_retrieve_body( $res );
                if ( strlen( $body ) > self::BODY_CAP ) {
                        $body = substr( $body, 0, self::BODY_CAP );
                }
                return $body;
        }

        /**
         * Turn fetched HTML into clean text + title and write the chunk rows
         * (replace-in-place for the URL's slot).
         *
         * @param string $url  Source URL.
         * @param string $html HTML.
         * @return array{pages:int,chunks:int}
         */
        public static function store_page( $url, $html ) {
                global $wpdb;

                $text  = self::html_text( $html, $title );
                if ( mb_strlen( $text ) < self::MIN_TEXT ) {
                        return array( 'pages' => 0, 'chunks' => 0 );
                }

                $post_id = self::slot_id( $url );
                $table   = Haa_DB::table( 'chunks' );

                // Vectors hang off chunk ids; drop them first, exactly like remove_post().
                if ( Haa_Settings::is_module_enabled( 'semantic-search' ) ) {
                        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
                        $wpdb->query( $wpdb->prepare( "DELETE v FROM " . Haa_DB::table( 'vectors' ) . " v INNER JOIN $table c ON c.id = v.chunk_id WHERE c.post_id = %d", $post_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                }
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery
                $wpdb->delete( $table, array( 'post_id' => $post_id ), array( '%d' ) );

                $chunks = Haa_Search::chunk_text( $text );
                // Keep the whole crawl lean: cap per page AND globally.
                $budget = self::MAX_CHUNKS - (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table WHERE post_id < 0 AND post_id <> " . (int) $post_id ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
                if ( $budget <= 0 ) {
                        return array( 'pages' => 0, 'chunks' => 0 );
                }
                $chunks = array_slice( $chunks, 0, max( 1, min( 8, $budget ) ) );

                $now = current_time( 'mysql', true );
                $n   = 0;
                foreach ( $chunks as $index => $chunk ) {
                        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
                        $wpdb->insert(
                                $table,
                                array(
                                        'post_id'      => $post_id,
                                        'source'       => 'crawl',
                                        'title'        => Haa_Util::cut( (string) $title, 250 ),
                                        'url'          => substr( (string) $url, 0, 250 ),
                                        'chunk_index'  => $index,
                                        'content'      => $chunk,
                                        'content_norm' => Haa_Search::normalize_text( $chunk ) . ' ' . Haa_Search::normalize_text( (string) $title ),
                                        'meta'         => wp_json_encode( array( 'crawler' => 1 ) ),
                                        'date_updated' => $now,
                                ),
                                array( '%d', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s' )
                        );
                        $n++;
                }

                Haa_DB::flush_cache();
                return array( 'pages' => 1, 'chunks' => $n );
        }

        /**
         * HTML → text: drop script/style/comments/noscript first, pull <title>,
         * then reuse the shared stripper so spacing matches the rest of the KB.
         *
         * @param string $html  Raw HTML.
         * @param string $title Receives the page title.
         * @return string
         */
        public static function html_text( $html, &$title = null ) {
                $html  = (string) $html;
                $title = '';
                if ( preg_match( '/<title[^>]*>(.*?)<\/title>/isu', $html, $m ) ) {
                        $title = Haa_Util::cut( trim( html_entity_decode( wp_strip_all_tags( $m[1] ), ENT_QUOTES, 'UTF-8' ) ), 120 );
                }
                $html = preg_replace( '#<(script|style|noscript|svg|iframe|form|template)\b[^>]*>.*?</\1>#isu', ' ', $html );
                $html = preg_replace( '#<!--.*?-->#su', ' ', $html );
                return (string) Haa_Util::html_to_text( $html );
        }

        /* ==================================================================
         * Contact facts
         * =============================================================== */

        /**
         * Pull every contact signal out of a fetched page and merge it into the
         * run's fact lines. Persian AND Latin digits are handled, and the anchor
         * that points at the contact page is remembered so the bot can finally
         * give its exact link.
         *
         * @param array  $state Run state (facts array is mutated).
         * @param string $url  Page URL.
         * @param string $html Page HTML.
         * @return void
         */
        public static function collect_facts( &$state, $url, $html ) {
                $html = (string) $html;
                $text = self::html_text( $html, $t );
                // Persian/Arabic digits → Latin so one set of regexes covers both.
                $text = strtr( $text, array( '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9', '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9' ) );
                $flat = preg_replace( '/[\s\x{200C}]+/u', ' ', $text );

                $found = array();

                // Phones: Iranian mobiles + landlines (with optional separators).
                if ( preg_match_all( '/(?:\+98|0098|98|0)?9\d{9}\b/u', $flat, $m ) ) {
                        $found[] = array( 'phone', $m[0] );
                }
                if ( preg_match_all( '/\b0\d{2,3}[- ]?\d{8}\b/u', $flat, $m ) ) {
                        $found[] = array( 'phone', $m[0] );
                }

                // E-mail (never an asset path).
                if ( preg_match_all( '/\b[A-Za-z0-9._%+-]+@[A-Za-z0-9-]+(?:\.[A-Za-z0-9-]+)*\.[A-Za-z]{2,}\b/u', $flat, $m ) ) {
                        $found[] = array( 'email', array_values( array_filter( $m[0], function ( $e ) {
                                return ! preg_match( '/\.(png|jpe?g|gif|webp|svg|css|js)$/i', $e );
                        } ) ) );
                }

                // Socials / messengers — from links AND bare text.
                foreach ( array(
                        array( 'instagram', '#(?:https?://)?(?:www\.)?instagram\.com/([A-Za-z0-9_.]{2,30})#iu', 'https://instagram.com/$1' ),
                        array( 'telegram',  '#(?:https?://)?(?:t\.me|telegram\.me)/([A-Za-z0-9_]{3,32})#iu', 'https://t.me/$1' ),
                        array( 'whatsapp',  '#(?:https?://)?(?:wa\.me/|whatsapp\.com/send\?phone=)(\d{6,20})#iu', 'https://wa.me/$1' ),
                        array( 'aparat',    '#(?:https?://)?(?:www\.)?aparat\.com/([A-Za-z0-9_.-]{2,40})#iu', 'https://aparat.com/$1' ),
                ) as $spec ) {
                        if ( preg_match_all( $spec[1], $html . "\n" . $flat, $m ) ) {
                                $vals = array();
                                foreach ( $m[1] as $handle ) {
                                        $vals[] = str_replace( '$1', (string) $handle, $spec[2] );
                                }
                                $found[] = array( $spec[0], $vals );
                        }
                }

                // The contact-page link, from anchors whose text or href says so. Relative
                // hrefs are resolved against the page they were found on.
                $home_host = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
                if ( preg_match_all( '/<a[^>]+href=["\']([^"\'#]+)["\'][^>]*>(.*?)<\/a>/isu', $html, $m, PREG_SET_ORDER ) ) {
                        foreach ( $m as $a ) {
                                $label = wp_strip_all_tags( (string) $a[2] );
                                $href  = html_entity_decode( (string) $a[1], ENT_QUOTES, 'UTF-8' );
                                if ( preg_match( '/تماس|ارتباط با ما|contact/iu', $label . ' ' . $href ) ) {
                                        $link = self::safe_url( self::abs_url( $href, $url ) );
                                        if ( $link && ( '' === $home_host || strtolower( (string) wp_parse_url( $link, PHP_URL_HOST ) ) === $home_host ) ) {
                                                $state['facts'][] = 'contact_page:' . self::canon_url( $link );
                                                break;
                                        }
                                }
                        }
                }

                foreach ( $found as $pair ) {
                        foreach ( array_slice( array_values( array_unique( (array) $pair[1] ) ), 0, 4 ) as $val ) {
                                // Phones are stored separator-free so «۰۱۳-۳۳۲۲۱۱۰۰» and
                                // «01333221100» land on the same normalized value.
                                if ( 'phone' === $pair[0] ) {
                                        $val = preg_replace( '/[^0-9+]/', '', (string) $val );
                                }
                                if ( '' !== $val ) {
                                        $state['facts'][] = $pair[0] . ':' . $val;
                                }
                        }
                }
        }

        /**
         * Merge fact lines into one compact, always-findable chunk (fixed slot
         * CONTACT_POST_ID) — this is the row that makes «شماره تماس؟ اینستاگرام؟»
         * answerable even when no post on the site contains them.
         *
         * @param array $state Run state.
         * @return int Rows written (0 or 1).
         */
        public static function write_contact_chunk( $state ) {
                global $wpdb;

                $phones = array();
                $emails = array();
                $social = array(
                        'instagram' => array(),
                        'telegram'  => array(),
                        'whatsapp'  => array(),
                        'aparat'    => array(),
                );
                $contact_page = '';

                foreach ( (array) ( $state['facts'] ?? array() ) as $line ) {
                        $line = trim( (string) $line );
                        $pos  = strpos( $line, ':' );
                        if ( false === $pos ) {
                                continue;
                        }
                        $kind = substr( $line, 0, $pos );
                        $val  = substr( $line, $pos + 1 );
                        if ( '' === $val ) {
                                continue;
                        }
                        if ( 'phone' === $kind ) {
                                $phones[] = $val;
                        } elseif ( 'email' === $kind ) {
                                $emails[] = $val;
                        } elseif ( 'contact_page' === $kind ) {
                                $contact_page = $val;
                        } elseif ( isset( $social[ $kind ] ) ) {
                                $social[ $kind ][] = $val;
                        }
                }

                $phones = array_slice( array_values( array_unique( $phones ) ), 0, 6 );
                $emails = array_slice( array_values( array_unique( $emails ) ), 0, 3 );
                if ( ! $phones && ! $emails && '' === $contact_page && ! array_filter( $social ) ) {
                        return 0;
                }

                $lines = array( 'شماره تماس و راه‌های ارتباطی رسمی این فروشگاه:' );
                if ( $phones ) {
                        $lines[] = 'تلفن: ' . implode( ' ، ', $phones );
                }
                if ( $emails ) {
                        $lines[] = 'ایمیل: ' . implode( ' ، ', $emails );
                }
                foreach ( array( 'instagram' => 'اینستاگرام', 'telegram' => 'تلگرام', 'whatsapp' => 'واتساپ', 'aparat' => 'آپارات' ) as $key => $label ) {
                        if ( $social[ $key ] ) {
                                $lines[] = $label . ': ' . implode( ' ، ', array_slice( array_values( array_unique( $social[ $key ] ) ), 0, 3 ) );
                        }
                }
                if ( '' !== $contact_page ) {
                        $lines[] = 'صفحه تماس با ما: ' . $contact_page;
                }
                $lines[] = 'هنگام معرفی راه‌های ارتباطی، همین مقادیر را عیناً بنویس.';

                $content = implode( "\n", $lines );
                $title   = 'راه‌های ارتباطی و شماره تماس سایت';
                $table   = Haa_DB::table( 'chunks' );

                if ( Haa_Settings::is_module_enabled( 'semantic-search' ) ) {
                        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
                        $wpdb->query( $wpdb->prepare( "DELETE v FROM " . Haa_DB::table( 'vectors' ) . " v INNER JOIN $table c ON c.id = v.chunk_id WHERE c.post_id = %d", self::CONTACT_POST_ID ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                }
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery
                $wpdb->delete( $table, array( 'post_id' => self::CONTACT_POST_ID ), array( '%d' ) );

                // phpcs:ignore WordPress.DB.DirectDatabaseQuery
                $wpdb->insert(
                        $table,
                        array(
                                'post_id'      => self::CONTACT_POST_ID,
                                'source'       => 'contact',
                                'title'        => 'راه‌های ارتباطی و شماره تماس سایت',
                                'url'          => substr( home_url( '/' ), 0, 250 ),
                                'chunk_index'  => 0,
                                'content'      => $content,
                                'content_norm' => Haa_Search::normalize_text( $content ),
                                'meta'         => wp_json_encode( array( 'crawler' => 1 ) ),
                                'date_updated' => current_time( 'mysql', true ),
                        ),
                        array( '%d', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s' )
                );

                Haa_DB::flush_cache();
                return 1;
        }

        /* ==================================================================
         * Admin plumbing
         * =============================================================== */

        /** @return void Registers the admin-post + AJAX handlers (admin only). */
        public static function hooks() {
                add_action( 'admin_post_haai_crawl_start', array( __CLASS__, 'handle_start' ) );
                add_action( 'admin_post_haai_crawl_wipe', array( __CLASS__, 'handle_wipe' ) );
                add_action( 'wp_ajax_haai_crawl_tick', array( __CLASS__, 'ajax_tick' ) );
                add_action( 'wp_ajax_haai_crawl_stop', array( __CLASS__, 'ajax_stop' ) );
        }

        /** @return void */
        public static function handle_start() {
                Haa_Util::guard_admin_post( 'haai_admin' );
                // «شروع از نو» posts the reset flag; plain «ادامهِ خزش» resumes.
                $reset = ! empty( $_POST['haai_crawl_reset'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- guard_admin_post verified the nonce.
                $res   = self::start( $reset );
                $msg   = is_wp_error( $res )
                        ? $res->get_error_message()
                        : __( 'خزش صفحات شروع شد؛ نوار پیشرفت آن را دنبال می‌کند.', 'hami-ai-assistant' );
                wp_safe_redirect( add_query_arg( 'haai_msg', rawurlencode( $msg ), wp_get_referer() ? wp_get_referer() : admin_url( 'admin.php?page=hami-ai-assistant' ) ) );
                exit;
        }

        /** @return void */
        public static function handle_wipe() {
                Haa_Util::guard_admin_post( 'haai_admin' );
                self::wipe();
                wp_safe_redirect( add_query_arg( 'haai_msg', rawurlencode( __( 'تکه‌های خزش‌شده پاک شد.', 'hami-ai-assistant' ) ), wp_get_referer() ? wp_get_referer() : admin_url( 'admin.php?page=hami-ai-assistant' ) ) );
                exit;
        }

        /** @return void One batch per AJAX call (dashboard ticker). */
        public static function ajax_tick() {
                Haa_Util::guard_admin_ajax();
                $progress = self::batch( 2 );
                wp_send_json( array( 'ok' => true, 'progress' => $progress ) );
        }

        /** @return void */
        public static function ajax_stop() {
                Haa_Util::guard_admin_ajax();
                wp_send_json( array( 'ok' => true, 'progress' => self::cancel() ) );
        }
}
