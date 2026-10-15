<?php
/**
 * Keyword research: real user input (Google Suggest), real SERP titles (when a
 * SERP service is configured) and the model's own ideas — combined, deduped and
 * scored, ready to be pushed into the planner as topics.
 *
 * Everything outbound goes through Haa_Research::get() (SSRF-checked,
 * size-capped) and through the provider proxy, because Google Suggest is as
 * unreachable from an Iranian host as Google itself.
 *
 * @package Hami_AI_Assistant
 */

if ( ! defined( 'ABSPATH' ) ) {
        exit;
}

/**
 * Keyword research engine for the article-writer screen.
 */
final class Haa_Keywords {

        /** Persian intent modifiers used for the suggest expansion. */
        const MODIFIERS = array( 'چگونه', 'بهترین', 'قیمت', 'آموزش', 'چیست', 'خرید', 'راهنمای', 'مقایسه', 'ارزان', 'مراحل' );

        /**
         * Full research for one seed: combine every available source.
         *
         * @param string $seed Topic or keyword typed by the admin.
         * @return array{ok:bool,error:string,rows:array<int,array{keyword:string,source:string,intent:string,note:string}>}
         */
        public static function research( $seed ) {
                $seed = trim( sanitize_text_field( (string) $seed ) );
                if ( mb_strlen( $seed ) < 3 ) {
                        return array(
                                'ok'    => false,
                                'error' => __( 'موضوع را بنویس (حداقل ۳ نویسه).', 'hami-ai-assistant' ),
                                'rows'  => array(),
                        );
                }

                $rows = array();

                // 1) Google Suggest — real user typing, when reachable.
                if ( ! empty( Haa_Research::config()['suggest_enabled'] ) ) {
                        foreach ( self::google_suggest( $seed ) as $k ) {
                                $rows[] = array(
                                        'keyword' => $k,
                                        'source'  => 'suggest',
                                        'intent'  => self::guess_intent( $k ),
                                        'note'    => '',
                                );
                        }
                }

                // 2) SERP titles — what actually ranks, when a SERP service exists.
                $serp = Haa_Research::serp( $seed, 10 );
                if ( ! empty( $serp['ok'] ) && ! empty( $serp['results'] ) ) {
                        foreach ( (array) $serp['results'] as $r ) {
                                $title = trim( wp_strip_all_tags( (string) ( $r['title'] ?? '' ) ) );
                                if ( '' === $title ) {
                                        continue;
                                }
                                $rows[] = array(
                                        'keyword' => $title,
                                        'source'  => 'serp',
                                        'intent'  => 'commercial',
                                        'note'    => (string) ( $r['url'] ?? '' ),
                                );
                        }
                }

                // 3) A previously built AI snapshot may already carry keywords.
                $snap = Haa_Research::snapshot();
                foreach ( (array) ( $snap['ai_keywords'] ?? array() ) as $k ) {
                        $rows[] = array(
                                'keyword' => (string) $k,
                                'source'  => 'snapshot',
                                'intent'  => self::guess_intent( (string) $k ),
                                'note'    => '',
                        );
                }

                // 4) The model's ideas — works on every adapter; grounded when Gemini.
                $ai = self::ai_ideas( $seed );
                if ( is_wp_error( $ai ) ) {
                        $ai_error = $ai->get_error_message();
                } else {
                        foreach ( $ai as $k ) {
                                $rows[] = array(
                                        'keyword' => $k,
                                        'source'  => 'ai',
                                        'intent'  => self::guess_intent( $k ),
                                        'note'    => '',
                                );
                        }
                        $ai_error = '';
                }

                // 5) Site gaps — our own topics nobody covered yet.
                foreach ( Haa_Planner::rows( 'suggested', 10 ) as $row ) {
                        $rows[] = array(
                                'keyword' => (string) $row['title'],
                                'source'  => 'planner',
                                'intent'  => (string) $row['intent'],
                                'note'    => '',
                        );
                }

                $rows = self::dedupe( $rows, $seed );

                return array(
                        'ok'    => true,
                        'error' => isset( $ai_error ) ? $ai_error : '',
                        'rows'  => $rows,
                );
        }

        /**
         * Google Suggest (firefox JSON endpoint) for the seed and seed+modifier.
         *
         * @param string $seed Topic.
         * @return array<int,string> Cleaned suggestion strings.
         */
        public static function google_suggest( $seed ) {
                $out = array();
                $url = 'https://suggestqueries.google.com/complete/search?client=firefox&hl=fa&q=' . rawurlencode( (string) $seed );

                $body = Haa_Research::get( $url, array( 'accept' => 'application/json', 'timeout' => 6 ) );
                if ( ! is_wp_error( $body ) ) {
                        $json = json_decode( (string) $body, true );
                        foreach ( (array) ( $json[1] ?? array() ) as $s ) {
                                $s = trim( (string) $s );
                                if ( mb_strlen( $s ) >= 4 ) {
                                        $out[] = $s;
                                }
                        }
                }

                // Alphabet soup with two high-value Persian modifiers, budget permitting.
                foreach ( array( 'چگونه', 'بهترین' ) as $mod ) {
                        if ( count( $out ) >= 24 ) {
                                break;
                        }
                        $body = Haa_Research::get(
                                'https://suggestqueries.google.com/complete/search?client=firefox&hl=fa&q=' . rawurlencode( $seed . ' ' . $mod ),
                                array( 'accept' => 'application/json', 'timeout' => 6 )
                        );
                        if ( is_wp_error( $body ) ) {
                                continue;
                        }
                        $json = json_decode( (string) $body, true );
                        foreach ( (array) ( $json[1] ?? array() ) as $s ) {
                                $s = trim( (string) $s );
                                if ( mb_strlen( $s ) >= 4 ) {
                                        $out[] = $s;
                                }
                        }
                }

                return array_values( array_unique( $out ) );
        }

        /**
         * The model's keyword ideas. Grounded on Gemini (real search), plain
         * knowledge elsewhere.
         *
         * @param string $seed Topic.
         * @return array<int,string>|WP_Error
         */
        public static function ai_ideas( $seed ) {
                $ground = Haa_Research::ai_ready();

                $system = __( 'پژوهشگر کلمه کلیدی فارسی. برای موضوع داده‌شده کلیدواژه‌های واقعی جستجوی کاربران ایرانی را می‌دهی.', 'hami-ai-assistant' ) . "\n"
                        . __( 'خروجی فقط JSON: {"keywords":[{"keyword":"…","intent":"informational|commercial|transactional"}]}', 'hami-ai-assistant' ) . "\n"
                        . '- ' . __( '۱۵ تا ۲۰ کلیدواژه: ترکیب کلیدواژه اصلی، دم‌بلندها (چگونه/بهترین/قیمت/آموزش/خرید)، پرسش‌های واقعی مردم و کلیدواژه‌های هم‌خانواده.', 'hami-ai-assistant' ) . "\n"
                        . '- ' . __( 'هر کلیدواژه بین ۲ تا ۷ واژه؛ برند رقیب نساز و چیزی که کسی جستجو نمی‌کند پیشنهاد نده.', 'hami-ai-assistant' )
                        . ( $ground ? "\n" . __( '- ابزار جستجوی گوگل در اختیار داری؛ پیشنهادها را با سرچ واقعی بساز، نه از حفظ.', 'hami-ai-assistant' ) : '' );

                $user = __( 'موضوع:', 'hami-ai-assistant' ) . ' ' . $seed . "\n"
                        . __( 'حالا کلیدواژه‌ها را بده.', 'hami-ai-assistant' );

                $raw = ( new Haa_API() )->chat(
                        array(
                                array( 'role' => 'system', 'content' => $system ),
                                array( 'role' => 'user', 'content' => $user ),
                        ),
                        array(
                                'module'     => 'article-writer',
                                'operation'  => 'keywords',
                                'max_tokens' => 1800,
                                'grounding'  => $ground,
                        )
                );
                if ( is_wp_error( $raw ) ) {
                        return $raw;
                }

                $json = Haa_Util::extract_json( (string) $raw );
                $list = array();
                foreach ( (array) ( $json['keywords'] ?? array() ) as $row ) {
                        if ( is_array( $row ) ) {
                                $k = trim( sanitize_text_field( (string) ( $row['keyword'] ?? '' ) ) );
                        } else {
                                $k = trim( sanitize_text_field( (string) $row ) );
                        }
                        if ( mb_strlen( $k ) >= 4 ) {
                                $list[] = $k;
                        }
                }
                return array_values( array_unique( $list ) );
        }

        /**
         * Cheap intent guess from the words the keyword itself carries.
         *
         * @param string $kw Keyword.
         * @return string informational|commercial|transactional
         */
        public static function guess_intent( $kw ) {
                $text = Haa_Content::plain_text( (string) $kw );
                if ( preg_match( '/خرید|قیمت|ارزان|سفارش|تخفیف|فروش/u', $text ) ) {
                        return 'transactional';
                }
                if ( preg_match( '/بهترین|مقایسه|بررسی|راهنمای|توصیه/u', $text ) ) {
                        return 'commercial';
                }
                return 'informational';
        }

        /**
         * Dedupe rows against each other (normalized Persian) and against the seed.
         *
         * @param array  $rows Candidate rows.
         * @param string $seed The typed seed.
         * @return array<int,array>
         */
        private static function dedupe( $rows, $seed ) {
                $seen = array( Haa_Search::normalize_text( (string) $seed ) => true );
                $out  = array();

                // Source priority: suggest > serp > snapshot > ai > planner.
                $rank = array( 'suggest' => 0, 'serp' => 1, 'snapshot' => 2, 'ai' => 3, 'planner' => 4 );
                usort(
                        $rows,
                        static function ( $a, $b ) use ( $rank ) {
                                $ra = isset( $rank[ $a['source'] ] ) ? $rank[ $a['source'] ] : 9;
                                $rb = isset( $rank[ $b['source'] ] ) ? $rank[ $b['source'] ] : 9;
                                return $ra <=> $rb;
                        }
                );

                foreach ( $rows as $row ) {
                        $key = Haa_Search::normalize_text( (string) $row['keyword'] );
                        if ( '' === $key || isset( $seen[ $key ] ) ) {
                                continue;
                        }
                        $seen[ $key ] = true;
                        $row['keyword'] = Haa_Util::cut( (string) $row['keyword'], 160 );
                        $out[]          = $row;
                        if ( count( $out ) >= 40 ) {
                                break;
                        }
                }

                return $out;
        }
}
