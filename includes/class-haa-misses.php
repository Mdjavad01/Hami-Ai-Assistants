<?php
/**
 * Search misses: the phrases real visitors typed that this site had no answer
 * for. This is the «جست‌وجوهای بی‌نتیجه» source of the keyword panel and, per
 * the landing copy, the one dataset no external tool can ever have.
 *
 * Privacy is the feature, not an afterthought:
 *
 *  - the ONLY columns are the phrase, the day and a hit counter;
 *  - no IP, no user id, no session, no user agent, ever;
 *  - phrases are truncated to 120 characters and stored normalised-enough
 *    (trimmed, tags stripped) so a pasted wall of text cannot bloat rows;
 *  - noise control: anything shorter than MIN_CHARS or with no letter/digit
 *    in it («سلام», «؟») is dropped instead of polluting the list;
 *  - rows are pruned by the daily cleanup job after MISSES_RETENTION days.
 *
 * Three entry points log into this table:
 *  1. the public [haai_search] endpoint returning zero hits (Haa_Admin);
 *  2. the chatbot's retrieval finding no knowledge-base context (Haa_Chat);
 *  3. WordPress native ?s= searches that found zero posts (template_redirect).
 *
 * Everything else (reading, matching, pruning) is admin-side; a visitor only
 * ever pays for the one upsert above.
 *
 * @package Hami_AI_Assistant
 */

if ( ! defined( 'ABSPATH' ) ) {
        exit;
}

final class Haa_Misses {

        /** Haa_DB::registry() key for the misses table. */
        const TABLE = 'search_misses';

        /** Days a miss stays in the table before the daily prune removes it. */
        const RETENTION_DAYS = 180;

        /** A phrase shorter than this is noise (a single 2–4 char word). */
        const MIN_CHARS = 5;

        /** Hard ceiling on the stored phrase length (VARCHAR(120) upstream). */
        const MAX_CHARS = 120;

        /** Site timezone day key for aggregation (DATE column upstream). */
        const DAY_FMT = 'Y-m-d';

        /**
         * Wire the only visitor-facing hook: native WordPress searches that
         * came back empty. The other two loggers call log() from their own
         * request paths and need no hook of their own.
         *
         * @return void
         */
        public static function hooks() {
                add_action( 'template_redirect', array( __CLASS__, 'maybe_log_native_search' ) );
        }

        /**
         * ?s=... found nothing → this exact phrase is a content hole.
         *
         * Runs after the main query is resolved, so found_posts is final here.
         * Admin/AJAX/cron contexts are excluded even if something fakes a
         * search query there.
         *
         * @return void
         */
        public static function maybe_log_native_search() {
                if ( is_admin() || wp_doing_ajax() || wp_doing_cron() ) {
                        return;
                }
                if ( ! is_search() || ! is_main_query() ) {
                        return;
                }
                $q = trim( (string) get_search_query( false ) );
                if ( '' === $q ) {
                        return;
                }
                global $wp_query;
                if ( isset( $wp_query->found_posts ) && (int) $wp_query->found_posts > 0 ) {
                        return;
                }
                self::log( $q );
        }

        /**
         * Record one miss (or bump the counter for phrase+day). Idempotent
         * per phrase per day: the counter is what the UI shows as «N بار».
         *
         * Single atomic upsert — on real MySQL the UNIQUE key (phrase, day)
         * turns the second insert of the same phrase/day into an increment,
         * so two parallel requests can at worst race to the same total.
         *
         * @param string $phrase Raw visitor input.
         * @return bool Whether a row was written.
         */
        public static function log( $phrase ) {
                global $wpdb;

                $phrase = self::clean( $phrase );
                if ( '' === $phrase ) {
                        return false;
                }

                $table = Haa_DB::table( self::TABLE );
                $day   = gmdate( self::DAY_FMT, time() );
                $now   = gmdate( 'Y-m-d H:i:s', time() );

                // phpcs:ignore WordPress.DB -- prepared, atomic upsert.
                return (bool) $wpdb->query(
                        $wpdb->prepare(
                                "INSERT INTO {$table} (phrase, day, hits, last) VALUES (%s, %s, 1, %s)
                                 ON DUPLICATE KEY UPDATE hits = hits + 1, last = %s",
                                $phrase,
                                $day,
                                $now,
                                $now
                        )
                );
        }

        /**
         * Clean + gate a phrase before it may become a row.
         *
         * @param string $raw Raw input.
         * @return string Empty string means «do not store».
         */
        private static function clean( $raw ) {
                $raw = trim( wp_strip_all_tags( (string) $raw ) );
                if ( '' === $raw || mb_strlen( $raw ) < self::MIN_CHARS ) {
                        return '';
                }
                if ( mb_strlen( $raw ) > self::MAX_CHARS ) {
                        $raw = mb_substr( $raw, 0, self::MAX_CHARS );
                }
                // At least one letter or digit somewhere — «---» or «؟؟؟» is noise.
                if ( ! preg_match( '/[\p{L}\p{N}]/u', $raw ) ) {
                        return '';
                }
                return $raw;
        }

        /**
         * Recent misses aggregated per phrase, most-hit first.
         *
         * The SQL stays deliberately flat (no GROUP BY): fetch the window's
         * rows capped at 5000 and aggregate in PHP. Same numbers, and the
         * query is trivially indexable with the (day) key.
         *
         * @param int $limit Max phrases to return.
         * @param int $days  Window in days (0 = today only).
         * @return array<int,array{phrase:string,hits:int,days:int,last:string}>
         */
        public static function top( $limit = 30, $days = 30 ) {
                global $wpdb;

                $limit = max( 1, min( 100, (int) $limit ) );
                $days  = max( 0, min( (int) self::RETENTION_DAYS, (int) $days ) );

                $table = Haa_DB::table( self::TABLE );
                $since = gmdate( self::DAY_FMT, time() - $days * DAY_IN_SECONDS );

                // phpcs:ignore WordPress.DB -- prepared read over an indexed column.
                $rows = $wpdb->get_results(
                        $wpdb->prepare(
                                "SELECT phrase, hits, day, last FROM {$table} WHERE day >= %s ORDER BY hits DESC LIMIT 5000",
                                $since
                        ),
                        ARRAY_A
                );

                $agg = array();
                foreach ( (array) $rows as $r ) {
                        $p = (string) ( $r['phrase'] ?? '' );
                        if ( '' === $p ) {
                                continue;
                        }
                        if ( ! isset( $agg[ $p ] ) ) {
                                $agg[ $p ] = array(
                                        'phrase' => $p,
                                        'hits'   => 0,
                                        'days'   => 0,
                                        'last'   => '',
                                );
                        }
                        $agg[ $p ]['hits'] += (int) ( $r['hits'] ?? 0 );
                        $agg[ $p ]['days'] += 1;
                        if ( (string) ( $r['last'] ?? '' ) > $agg[ $p ]['last'] ) {
                                $agg[ $p ]['last'] = (string) ( $r['last'] ?? '' );
                        }
                }

                usort( $agg, static function ( $a, $b ) {
                        return ( $b['hits'] <=> $a['hits'] ) ?: strcmp( $a['phrase'], $b['phrase'] );
                } );

                return array_slice( array_values( $agg ), 0, $limit );
        }

        /**
         * Misses related to a seed topic: token overlap between the seed and
         * the stored phrase. «فرش نمدی» belongs to the seed «فرش» but not to
         * «کفش رانینگ». One shared non-stopword token is enough — these are
         * real typed phrases, not articles, so precision beats recall.
         *
         * @param string $seed  Topic the admin typed.
         * @param int    $limit Max phrases.
         * @return array<int,array{phrase:string,hits:int,days:int,last:string}>
         */
        public static function matching( $seed, $limit = 12 ) {
                $seed_tokens = Haa_Search::tokenize( (string) $seed );
                if ( ! $seed_tokens ) {
                        return array();
                }
                $seed_set = array_fill_keys( $seed_tokens, 1 );

                $out = array();
                foreach ( self::top( 100, 60 ) as $row ) {
                        foreach ( Haa_Search::tokenize( $row['phrase'] ) as $tok ) {
                                if ( isset( $seed_set[ $tok ] ) ) {
                                        $out[] = $row;
                                        break;
                                }
                        }
                        if ( count( $out ) >= $limit ) {
                                break;
                        }
                }
                return $out;
        }

        /**
         * Daily cleanup: drop misses older than the retention window.
         *
         * @return int Rows removed (0 when the stub can't count them).
         */
        public static function prune() {
                global $wpdb;
                $table = Haa_DB::table( self::TABLE );
                $cut   = gmdate( self::DAY_FMT, time() - self::RETENTION_DAYS * DAY_IN_SECONDS );
                // phpcs:ignore WordPress.DB -- prepared delete on the day key.
                return (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE day < %s", $cut ) );
        }
}
