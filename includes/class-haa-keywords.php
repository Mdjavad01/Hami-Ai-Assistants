<?php
/**
 * Keyword research: real user input (Google Suggest + Bing Suggest), real site
 * data (zero-result searches, uploaded Search-Console CSV), real SERP titles
 * (when a SERP service is configured) and the model's own ideas — combined,
 * deduped, scored for page coverage, and ready to be pushed into the planner
 * as topics.
 *
 * Everything outbound goes through Haa_Research::get() (SSRF-checked,
 * size-capped) and through the provider proxy, because Google Suggest is as
 * unreachable from an Iranian host as Google itself — Bing, on the other
 * hand, usually answers directly, which is why both engines are queried.
 *
 * No search-volume number is ever produced here. The only metrics shown are
 * the ones the admin's own Search-Console export carried (impressions,
 * clicks, position) and the miss counters this site collected itself.
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

        /** Option holding the parsed Search-Console opportunities. */
        const GSC_OPT = 'haai_kw_gsc';

        /** Max Search-Console rows kept (impressions-sorted, oldest merged out). */
        const GSC_CAP = 500;

        /** Outbound suggest calls per research click (each ≤ SUGGEST_TIMEOUT s). */
        const SUGGEST_CALLS = 6;

        /** Per-call suggest timeout in seconds. */
        const SUGGEST_TIMEOUT = 6;

        /** Raw suggestion target before the engines may be left alone. */
        const SUGGEST_TARGET = 40;

        /** Google Suggest (firefox JSON) endpoint. */
        const GOOGLE_EP = 'https://suggestqueries.google.com/complete/search?client=firefox&hl=fa&q=';

        /** Bing Suggest OSJSON endpoint — no key, and reachable from Iran. */
        const BING_EP = 'https://api.bing.com/osjson.aspx?query=';

        /**
         * Deterministic long-tail templates for the last-resort source. When
         * Google Suggest is unreachable (no proxy), no SERP service is set, the
         * snapshot is empty and the model call fails, the panel still shows a
         * usable list built from the seed itself — labelled «پیشنهاد خودکار».
         */
        const LOCAL_TEMPLATES = array(
                'چگونه %s انتخاب کنیم',
                'بهترین %s',
                'قیمت %s',
                'خرید %s',
                'آموزش %s',
                '%s چیست',
                'راهنمای خرید %s',
                'مقایسه %s',
                'ارزان‌ترین %s',
                '%s اصل',
                'بهترین مارک %s',
                '%s با بهترین قیمت',
        );

        /**
         * Full research for one seed: combine every available source.
         *
         * @param string $seed Topic or keyword typed by the admin.
         * @return array{ok:bool,error:string,rows:array<int,array>}
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

                // 1) Google + Bing Suggest — real user typing, when reachable.
                if ( ! empty( Haa_Research::config()['suggest_enabled'] ) ) {
                        foreach ( self::suggest_multi( $seed ) as $k ) {
                                $rows[] = array(
                                        'keyword' => $k,
                                        'source'  => 'suggest',
                                        'intent'  => self::guess_intent( $k ),
                                        'note'    => '',
                                );
                        }
                }

                // 2) Site misses — what visitors searched and found nothing for.
                foreach ( Haa_Misses::matching( $seed, 12 ) as $m ) {
                        $rows[] = array(
                                'keyword' => (string) $m['phrase'],
                                'source'  => 'misses',
                                'intent'  => self::guess_intent( (string) $m['phrase'] ),
                                'note'    => sprintf(
                                        /* translators: %d: how many times this search ended with nothing. */
                                        __( '%d بار بی‌نتیجه', 'hami-ai-assistant' ),
                                        (int) $m['hits']
                                ),
                                'hits'    => (int) $m['hits'],
                        );
                }

                // 3) Search-Console opportunities related to the seed.
                foreach ( self::gsc_matching( $seed, 10 ) as $g ) {
                        $rows[] = array(
                                'keyword' => (string) $g['query'],
                                'source'  => 'gsc',
                                'intent'  => self::guess_intent( (string) $g['query'] ),
                                'note'    => sprintf(
                                        /* translators: 1: impressions, 2: clicks, 3: average position. */
                                        __( 'نمایش: %1$d | کلیک: %2$d | رتبه: %3$s', 'hami-ai-assistant' ),
                                        (int) $g['impressions'],
                                        (int) $g['clicks'],
                                        (string) $g['position']
                                ),
                        );
                }

                // 4) SERP titles — what actually ranks, when a SERP service exists.
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

                // 5) A previously built AI snapshot may already carry keywords.
                $snap = Haa_Research::snapshot();
                foreach ( (array) ( $snap['ai_keywords'] ?? array() ) as $k ) {
                        $rows[] = array(
                                'keyword' => (string) $k,
                                'source'  => 'snapshot',
                                'intent'  => self::guess_intent( (string) $k ),
                                'note'    => '',
                        );
                }

                // 6) The model's ideas — works on every adapter; grounded when Gemini.
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

                // 7) Site gaps — our own topics nobody covered yet.
                foreach ( Haa_Planner::rows( 'suggested', 10 ) as $row ) {
                        $rows[] = array(
                                'keyword' => (string) $row['title'],
                                'source'  => 'planner',
                                'intent'  => (string) $row['intent'],
                                'note'    => '',
                        );
                }

                $rows = self::dedupe( $rows, $seed );

                // Never an empty panel: when the live sources together produced
                // almost nothing, the seed itself expands into long-tail patterns.
                if ( count( $rows ) < 8 ) {
                        $rows = self::dedupe( array_merge( $rows, self::local_rows( $seed ) ), $seed );
                }

                $rows = self::decorate( $rows );

                return array(
                        'ok'    => true,
                        'error' => isset( $ai_error ) ? $ai_error : '',
                        'rows'  => $rows,
                );
        }

        /**
         * Seed × template long-tails, the fallback nobody needs a network for.
         *
         * @param string $seed Topic.
         * @return array<int,array{keyword:string,source:string,intent:string,note:string}>
         */
        private static function local_rows( $seed ) {
                $seed = trim( (string) $seed );
                $out  = array();
                if ( '' === $seed ) {
                        return $out;
                }
                foreach ( self::LOCAL_TEMPLATES as $tpl ) {
                        $k = trim( sprintf( $tpl, $seed ) );
                        if ( mb_strlen( $k ) >= 4 ) {
                                $out[] = array(
                                        'keyword' => $k,
                                        'source'  => 'local',
                                        'intent'  => self::guess_intent( $k ),
                                        'note'    => '',
                                );
                        }
                }
                return $out;
        }

        /**
         * Suggest across both engines under a strict call budget.
         *
         * Plan: Google for the bare seed, Bing for the bare seed, then the
         * Persian intent modifiers alternating engines until the raw pool is
         * big enough or SUGGEST_CALLS is spent. Bing first-class matters on
         * Iranian hosts: suggestqueries.google.com is usually blocked there,
         * api.bing.com usually is not, and neither needs a key.
         *
         * @param string $seed Topic.
         * @return array<int,string> Cleaned, unique suggestion strings.
         */
        public static function suggest_multi( $seed ) {
                $out   = array();
                $calls = 0;

                $fetch = static function ( $endpoint, $q ) use ( &$calls ) {
                        if ( $calls >= self::SUGGEST_CALLS ) {
                                return null;
                        }
                        $calls++;
                        $body = Haa_Research::get(
                                $endpoint . rawurlencode( $q ),
                                array( 'accept' => 'application/json', 'timeout' => self::SUGGEST_TIMEOUT )
                        );
                        if ( is_wp_error( $body ) ) {
                                return null;
                        }
                        $json = json_decode( (string) $body, true );
                        if ( ! is_array( $json ) ) {
                                return null;
                        }
                        $list = array();
                        foreach ( (array) ( $json[1] ?? array() ) as $s ) {
                                $s = trim( (string) $s );
                                if ( mb_strlen( $s ) >= 4 ) {
                                        $list[] = $s;
                                }
                        }
                        return $list;
                };

                $seed = trim( (string) $seed );
                if ( '' === $seed ) {
                        return $out;
                }

                // Both engines on the bare seed first — these are the highest-value rows.
                $got = $fetch( self::GOOGLE_EP, $seed );
                if ( is_array( $got ) ) {
                        $out = array_merge( $out, $got );
                }
                $got = $fetch( self::BING_EP, $seed );
                if ( is_array( $got ) ) {
                        $out = array_merge( $out, $got );
                }

                // Modifiers alternate engines; stop the moment the pool is rich.
                $i = 0;
                foreach ( self::MODIFIERS as $mod ) {
                        if ( count( $out ) >= self::SUGGEST_TARGET ) {
                                break;
                        }
                        $ep  = ( 0 === $i % 2 ) ? self::GOOGLE_EP : self::BING_EP;
                        $got = $fetch( $ep, $seed . ' ' . $mod );
                        $i++;
                        if ( is_array( $got ) ) {
                                $out = array_merge( $out, $got );
                        }
                }

                return array_values( array_unique( $out ) );
        }

        /**
         * Google-only suggest (kept for BC and targeted tests): the same
         * budgeted engine loop as suggest_multi(), limited to Google.
         *
         * @param string $seed Topic.
         * @return array<int,string>
         */
        public static function google_suggest( $seed ) {
                $out  = array();
                $seed = trim( (string) $seed );
                if ( '' === $seed ) {
                        return $out;
                }

                $body = Haa_Research::get(
                        self::GOOGLE_EP . rawurlencode( $seed ),
                        array( 'accept' => 'application/json', 'timeout' => self::SUGGEST_TIMEOUT )
                );
                if ( ! is_wp_error( $body ) ) {
                        $json = json_decode( (string) $body, true );
                        foreach ( (array) ( $json[1] ?? array() ) as $s ) {
                                $s = trim( (string) $s );
                                if ( mb_strlen( $s ) >= 4 ) {
                                        $out[] = $s;
                                }
                        }
                }

                foreach ( array( 'چگونه', 'بهترین' ) as $mod ) {
                        if ( count( $out ) >= 24 ) {
                                break;
                        }
                        $body = Haa_Research::get(
                                self::GOOGLE_EP . rawurlencode( $seed . ' ' . $mod ),
                                array( 'accept' => 'application/json', 'timeout' => self::SUGGEST_TIMEOUT )
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
         * Resilience: a grounded call that errors or comes back unreadable is
         * retried once without the tool, and an answer without keywords becomes
         * a WP_Error carrying a snippet — the panel must be able to say WHY the
         * list is short instead of silently showing three planner leftovers.
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

                $opts = array(
                        'module'     => 'article-writer',
                        'operation'  => 'keywords',
                        'max_tokens' => 1800,
                        'grounding'  => $ground,
                );

                $api = new Haa_API();
                $raw = $api->chat(
                        array(
                                array( 'role' => 'system', 'content' => $system ),
                                array( 'role' => 'user', 'content' => $user ),
                        ),
                        $opts
                );
                if ( is_wp_error( $raw ) && $ground ) {
                        // The search tool itself can be the failing part (tool
                        // timeouts, provider-side grounding limits). One plain retry
                        // costs one call and answers the question «is it the model
                        // or the tool?» in production.
                        $opts['grounding'] = false;
                        $raw               = $api->chat(
                                array(
                                        array( 'role' => 'system', 'content' => $system ),
                                        array( 'role' => 'user', 'content' => $user ),
                                ),
                                $opts
                        );
                }
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
                if ( ! $list ) {
                        return new WP_Error(
                                'haai_kw_parse',
                                sprintf(
                                        /* translators: %s: first bytes of the model answer. */
                                        __( 'پاسخ مدل کلیدواژه‌ای نداشت. نمونه پاسخ: %s', 'hami-ai-assistant' ),
                                        Haa_Util::cut( wp_strip_all_tags( (string) $raw ), 120 )
                                )
                        );
                }
                return array_values( array_unique( $list ) );
        }

        /**
         * Cheap intent guess from the words the keyword itself carries.
         *
         * Order matters: a phrase like «قیمت فرش در تهران» is both, and the
         * buying signal is the one worth acting on first. Local intent covers
         * the «کجا / نزدیک من» family the landing copy promises.
         *
         * @param string $kw Keyword.
         * @return string informational|commercial|transactional|local
         */
        public static function guess_intent( $kw ) {
                $text = Haa_Content::plain_text( (string) $kw );
                if ( preg_match( '/خرید|قیمت|ارزان|سفارش|تخفیف|فروش/u', $text ) ) {
                        return 'transactional';
                }
                if ( preg_match( '/نزدیک من|نمایندگی|آدرس|تلفن|شماره تماس|حضوری|تهران|مشهد|اصفهان|شیراز|تبریز|کرج|اهواز|قم|یزد|رشت|کرمان|کاشان/u', $text ) ) {
                        return 'local';
                }
                if ( preg_match( '/بهترین|مقایسه|بررسی|راهنمای|توصیه/u', $text ) ) {
                        return 'commercial';
                }
                return 'informational';
        }

        /**
         * Is this phrase shaped like a question an AI engine would answer
         * directly? Marks the «مناسب پاسخ هوش مصنوعی» badge in the panel.
         *
         * Two checks because normalisation eats the question mark: the raw
         * string may carry ؟/? anywhere, and the normalised text may carry a
         * Persian question word. Words like چرا/کدام are stopwords in the
         * tokenizer, so they are checked on the raw text, not as tokens.
         *
         * @param string $kw Keyword.
         * @return bool
         */
        public static function is_question( $kw ) {
                $raw = (string) $kw;
                if ( preg_match( '/[؟?]/u', $raw ) ) {
                        return true;
                }
                $norm = Haa_Search::normalize_text( $raw );
                return (bool) preg_match( '/(^|\s)(چگونه|چطور|چرا|چیست|کدام|آیا|فرق|معنی|یعنی|چند)(\s|$)/u', $norm );
        }

        /**
         * Page coverage for the rows: does this site already answer the phrase?
         *
         * Title-token overlap against published posts (the same math the
         * planner's competition check uses), computed in one pass — the post
         * list and their token sets are built once per research click, never
         * once per row. The knowledge-base index is deliberately NOT queried
         * per row: forty BM25 lookups per click would tax big catalogues for a
         * signal the admin only uses to eyeball the list.
         *
         * @param string $phrase Keyword phrase.
         * @param array<int,array{id:int,title:string,tokens:array<string,int>>} $posts Pre-tokenised published posts.
         * @return array{state:string,url:string,title:string,score:float} state: has|half|none.
         */
        public static function coverage_of( $phrase, array $posts ) {
                $tokens = array_fill_keys( Haa_Search::tokenize( (string) $phrase ), 1 );
                if ( ! $tokens ) {
                        return array( 'state' => 'none', 'url' => '', 'title' => '', 'score' => 0.0 );
                }

                $best = array( 'state' => 'none', 'url' => '', 'title' => '', 'score' => 0.0 );

                foreach ( $posts as $p ) {
                        $both = count( array_intersect_key( $tokens, $p['tokens'] ) );
                        if ( 0 === $both ) {
                                continue;
                        }
                        $all   = count( $tokens + $p['tokens'] );
                        $score = $all ? $both / $all : 0.0;
                        if ( $score > $best['score'] ) {
                                $best = array(
                                        'state' => 'none',
                                        'url'   => Haa_Util::pretty_url( get_permalink( (int) $p['id'] ) ),
                                        'title' => (string) $p['title'],
                                        'score' => round( $score, 3 ),
                                );
                        }
                }

                // دارد needs half the tokens shared (a «فرش نمدی چیست» article
                // answers «فرش نمدی چیست» at 0.6 — that IS coverage). The
                // planner's 0.7 dup_threshold means «same article», a much
                // stricter question than «does a page answer this».
                if ( $best['score'] >= 0.5 ) {
                        $best['state'] = 'has';
                } elseif ( $best['score'] >= 0.25 ) {
                        $best['state'] = 'half';
                }

                return $best;
        }

        /**
         * Add the per-row display fields the panel renders: the AI-answerable
         * flag and the page-coverage badge. Purely additive — unknown keys are
         * ignored by older JS.
         *
         * @param array $rows Deduped research rows.
         * @return array
         */
        private static function decorate( $rows ) {
                $posts = array();
                foreach ( Haa_Planner::published_articles() as $p ) {
                        $posts[] = array(
                                'id'     => (int) $p['id'],
                                'title'  => (string) $p['title'],
                                'tokens' => array_fill_keys( Haa_Search::tokenize( (string) $p['title'] ), 1 ),
                        );
                }

                foreach ( $rows as $i => $row ) {
                        $rows[ $i ]['ai'] = self::is_question( (string) $row['keyword'] );
                        if ( $posts ) {
                                $cov = self::coverage_of( (string) $row['keyword'], $posts );
                        } else {
                                $cov = array( 'state' => 'none', 'url' => '', 'title' => '', 'score' => 0.0 );
                        }
                        $rows[ $i ]['coverage'] = $cov;
                }

                return $rows;
        }

        /* ------------------------------------------------------------------
         * Search Console — file import, no API
         * ---------------------------------------------------------------- */

        /**
         * Parse an uploaded Search-Console performance export and merge it
         * into the stored opportunity list.
         *
         * GSC exports are UTF-16LE + tab-separated when downloaded as CSV from
         * the UI (locale-dependent headers, Persian digits in fa views); other
         * tools emit UTF-8 + comma. All shapes are handled: BOM sniffing, a
         * pure-PHP UTF-16 decoder (no mbstring requirement — see 2.1.24),
         * delimiter sniffing and bilingual header mapping.
         *
         * @param string $raw File contents.
         * @return array{added:int,rows:int}|WP_Error added = rows merged this call.
         */
        public static function import_gsc( $raw ) {
                $text = self::decode_csv_bytes( (string) $raw );
                if ( '' === trim( $text ) ) {
                        return new WP_Error( 'haai_kw_gsc_empty', __( 'فایل خالی است.', 'hami-ai-assistant' ) );
                }

                $lines = preg_split( '/\r\n|\r|\n/', $text );
                if ( ! is_array( $lines ) || count( $lines ) < 2 ) {
                        return new WP_Error( 'haai_kw_gsc_header', __( 'فایل حداقل یک سربرگ و یک ردیف باید داشته باشد.', 'hami-ai-assistant' ) );
                }

                $delim = ( substr_count( $lines[0], "\t" ) >= substr_count( $lines[0], ',' ) ) ? "\t" : ',';
                $head  = array_map( array( __CLASS__, 'csv_header_norm' ), self::parse_csv_line( array_shift( $lines ), $delim ) );

                $col = array( 'query' => -1, 'clicks' => -1, 'impressions' => -1, 'position' => -1 );
                foreach ( $head as $i => $h ) {
                        if ( '' === $h ) {
                                continue;
                        }
                        if ( -1 === $col['query'] && preg_match( '/quer|عبارت|پرسش|کوئری|جستجو|keyword/u', $h ) ) {
                                $col['query'] = $i;
                        } elseif ( -1 === $col['clicks'] && preg_match( '/click|کلیک/u', $h ) ) {
                                $col['clicks'] = $i;
                        } elseif ( -1 === $col['impressions'] && preg_match( '/impression|نمایش/u', $h ) ) {
                                $col['impressions'] = $i;
                        } elseif ( -1 === $col['position'] && preg_match( '/position|رتبه|موقعیت/u', $h ) ) {
                                $col['position'] = $i;
                        }
                }
                if ( -1 === $col['query'] ) {
                        return new WP_Error(
                                'haai_kw_gsc_query',
                                __( 'ستون «عبارت جست‌وجو» پیدا نشد؛ فایل خروجی گزارش «نتیجه‌ها → عبارت‌های جست‌وجو» را بدهید.', 'hami-ai-assistant' )
                        );
                }

                $parsed = array();
                foreach ( $lines as $line ) {
                        if ( '' === trim( $line ) ) {
                                continue;
                        }
                        $cells = self::parse_csv_line( $line, $delim );
                        $q     = trim( (string) ( $cells[ $col['query'] ] ?? '' ) );
                        if ( '' === $q || mb_strlen( $q ) > 160 ) {
                                continue;
                        }
                        $parsed[ Haa_Search::normalize_text( $q ) ] = array(
                                'query'       => Haa_Util::cut( $q, 120 ),
                                'clicks'      => -1 !== $col['clicks'] ? self::csv_int( $cells[ $col['clicks'] ] ?? 0 ) : 0,
                                'impressions' => -1 !== $col['impressions'] ? self::csv_int( $cells[ $col['impressions'] ] ?? 0 ) : 0,
                                'position'    => -1 !== $col['position'] ? self::csv_float( $cells[ $col['position'] ] ?? 0 ) : 0.0,
                        );
                }

                if ( ! $parsed ) {
                        return new WP_Error( 'haai_kw_gsc_rows', __( 'هیچ عبارت قابل استفاده‌ای در فایل نبود.', 'hami-ai-assistant' ) );
                }

                // Merge into the store: newest numbers win, deduped by normalised query.
                // The stored list is numeric-keyed, so it is re-keyed by the same
                // normalised query the new rows carry — otherwise a re-import
                // appends a second row for a query already stored.
                $store = array();
                foreach ( self::gsc_rows() as $prev ) {
                        $store[ Haa_Search::normalize_text( $prev['query'] ) ] = $prev;
                }
                foreach ( $parsed as $key => $row ) {
                        $store[ $key ] = $row;
                }
                usort( $store, static function ( $a, $b ) {
                        return ( $b['impressions'] <=> $a['impressions'] ) ?: strcmp( $a['query'], $b['query'] );
                } );
                $store = array_slice( array_values( $store ), 0, self::GSC_CAP );

                update_option( self::GSC_OPT, array( 'imported' => time(), 'rows' => $store ), false );

                return array( 'added' => count( $parsed ), 'rows' => count( $store ) );
        }

        /** @return array<int,array{query:string,clicks:int,impressions:int,position:float}> */
        public static function gsc_rows() {
                $opt  = get_option( self::GSC_OPT, array() );
                $rows = is_array( $opt ) ? (array) ( $opt['rows'] ?? array() ) : array();
                $out  = array();
                foreach ( $rows as $r ) {
                        if ( ! is_array( $r ) || '' === (string) ( $r['query'] ?? '' ) ) {
                                continue;
                        }
                        $out[] = array(
                                'query'       => (string) $r['query'],
                                'clicks'      => (int) ( $r['clicks'] ?? 0 ),
                                'impressions' => (int) ( $r['impressions'] ?? 0 ),
                                'position'    => (float) ( $r['position'] ?? 0 ),
                        );
                }
                return $out;
        }

        /**
         * Stored GSC opportunities related to a seed (token overlap, same rule
         * as the misses source).
         *
         * @param string $seed  Topic.
         * @param int    $limit Max rows.
         * @return array<int,array>
         */
        public static function gsc_matching( $seed, $limit = 10 ) {
                $seed_set = array_fill_keys( Haa_Search::tokenize( (string) $seed ), 1 );
                if ( ! $seed_set ) {
                        return array();
                }
                $out = array();
                foreach ( self::gsc_rows() as $row ) {
                        foreach ( Haa_Search::tokenize( $row['query'] ) as $tok ) {
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
         * BOM sniffing: GSC's CSV is UTF-16LE/BE, everything else is assumed
         * UTF-8 (an explicit UTF-8 BOM is stripped).
         *
         * @param string $raw Bytes.
         * @return string UTF-8 text.
         */
        private static function decode_csv_bytes( $raw ) {
                $head = substr( $raw, 0, 2 );
                if ( "\xFF\xFE" === $head || "\xFE\xFF" === $head ) {
                        return self::utf16_to_utf8( $raw, "\xFF\xFE" === $head );
                }
                if ( 0 === strpos( $raw, "\xEF\xBB\xBF" ) ) {
                        return (string) substr( $raw, 3 );
                }
                return (string) $raw;
        }

        /**
         * Pure-PHP UTF-16 → UTF-8 (BOM included in input). Written by hand so
         * a host without mbstring/iconv still imports GSC files — the same
         * rule that turned mb_stripos into stripos in 2.1.24.
         *
         * @param string $bytes Input including BOM.
         * @param bool   $le    Little-endian when true.
         * @return string
         */
        private static function utf16_to_utf8( $bytes, $le ) {
                $out = '';
                $len = strlen( $bytes );
                $hi  = $le ? 1 : 0; // Index of the high byte inside one code unit.
                $lo  = $le ? 0 : 1;

                for ( $i = 2; $i + 1 < $len; $i += 2 ) {
                        $unit = ( ord( $bytes[ $i + $hi ] ) << 8 ) | ord( $bytes[ $i + $lo ] );

                        // High surrogate: pair with the next unit for a real code point.
                        if ( $unit >= 0xD800 && $unit <= 0xDBFF && $i + 3 < $len ) {
                                $unit2 = ( ord( $bytes[ $i + 2 + $hi ] ) << 8 ) | ord( $bytes[ $i + 2 + $lo ] );
                                if ( $unit2 >= 0xDC00 && $unit2 <= 0xDFFF ) {
                                        $cp  = 0x10000 + ( ( $unit - 0xD800 ) << 10 ) + ( $unit2 - 0xDC00 );
                                        $i  += 2;
                                        $out .= self::utf8_encode_cp( $cp );
                                        continue;
                                }
                        }
                        if ( $unit >= 0xD800 && $unit <= 0xDFFF ) {
                                continue; // Lone surrogate: drop.
                        }
                        $out .= self::utf8_encode_cp( $unit );
                }
                return $out;
        }

        /** UTF-8 encode one code point. @param int $cp @return string */
        private static function utf8_encode_cp( $cp ) {
                if ( $cp < 0x80 ) {
                        return chr( $cp );
                }
                if ( $cp < 0x800 ) {
                        return chr( 0xC0 | ( $cp >> 6 ) ) . chr( 0x80 | ( $cp & 0x3F ) );
                }
                if ( $cp < 0x10000 ) {
                        return chr( 0xE0 | ( $cp >> 12 ) ) . chr( 0x80 | ( ( $cp >> 6 ) & 0x3F ) ) . chr( 0x80 | ( $cp & 0x3F ) );
                }
                return chr( 0xF0 | ( $cp >> 18 ) ) . chr( 0x80 | ( ( $cp >> 12 ) & 0x3F ) )
                        . chr( 0x80 | ( ( $cp >> 6 ) & 0x3F ) ) . chr( 0x80 | ( $cp & 0x3F ) );
        }

        /**
         * One CSV line → cells. Handles the double-quote escape ("" inside a
         * quoted cell) for comma files; GSC tab files are unquoted in practice.
         *
         * @param string $line  One line.
         * @param string $delim Delimiter.
         * @return array<int,string>
         */
        private static function parse_csv_line( $line, $delim ) {
                $cells  = array();
                $cur    = '';
                $in     = false;
                $len    = strlen( $line );
                for ( $i = 0; $i < $len; $i++ ) {
                        $ch = $line[ $i ];
                        if ( '"' === $ch ) {
                                if ( $in && $i + 1 < $len && '"' === $line[ $i + 1 ] ) {
                                        $cur .= '"';
                                        $i++;
                                } else {
                                        $in = ! $in;
                                }
                                continue;
                        }
                        if ( $delim === $ch && ! $in ) {
                                $cells[] = $cur;
                                $cur     = '';
                                continue;
                        }
                        $cur .= $ch;
                }
                $cells[] = $cur;
                return $cells;
        }

        /** Header cell: trim, strip quotes, lowercase latin. @param string $c @return string */
        private static function csv_header_norm( $c ) {
                return strtolower( trim( trim( (string) $c, " \t\"'" ) ) );
        }

        /** Persian/Arabic digits, separators and % in the way: to int. @param string $v @return int */
        private static function csv_int( $v ) {
                $v = Haa_Util::to_ascii_digits( (string) $v );
                $v = preg_replace( '/[^0-9\-]/', '', $v );
                return (int) $v;
        }

        /** Same, to float (position 4.5 may come as ۴٫۵ or "4,5"). @param string $v @return float */
        private static function csv_float( $v ) {
                $v = Haa_Util::to_ascii_digits( (string) $v );
                $v = str_replace( array( '٫', ',' ), '.', $v );
                $v = preg_replace( '/[^0-9.\-]/', '', $v );
                return (float) $v;
        }

        /* ------------------------------------------------------------------
         * Excel-friendly exports (CSV with UTF-8 BOM, sent by Haa_Util)
         * ---------------------------------------------------------------- */

        /**
         * The content plan: planner rows with intent/priority/status.
         *
         * @param string $status Planner status filter ('' = all).
         * @return string CSV body (no BOM — send_csv adds it).
         */
        public static function csv_planner( $status = '' ) {
                $head = array( 'عبارت/موضوع', 'کلمه کلیدی', 'نیت', 'اولویت', 'وضعیت', 'دلیل' );
                $out  = array( implode( ',', array_map( 'Haa_Util::csv_cell', $head ) ) );

                foreach ( Haa_Planner::rows( $status, 200 ) as $r ) {
                        $out[] = implode(
                                ',',
                                array_map(
                                        'Haa_Util::csv_cell',
                                        array(
                                                (string) $r['title'],
                                                (string) $r['keyword'],
                                                Haa_Planner::intent_label( (string) $r['intent'] ),
                                                (int) $r['priority'],
                                                (string) $r['status'],
                                                (string) ( $r['reason'] ?? '' ),
                                        )
                                )
                        );
                }
                return implode( "\n", $out ) . "\n";
        }

        /**
         * The misses list — exactly what the landing copy promises to hand the
         * content team.
         *
         * @return string CSV body.
         */
        public static function csv_misses() {
                $head = array( 'عبارت', 'تعداد بی‌نتیجه', 'روزهای دیده‌شده', 'آخرین بار' );
                $out  = array( implode( ',', array_map( 'Haa_Util::csv_cell', $head ) ) );

                foreach ( Haa_Misses::top( 100, 90 ) as $m ) {
                        $out[] = implode(
                                ',',
                                array_map(
                                        'Haa_Util::csv_cell',
                                        array(
                                                (string) $m['phrase'],
                                                (int) $m['hits'],
                                                (int) $m['days'],
                                                (string) $m['last'],
                                        )
                                )
                        );
                }
                return implode( "\n", $out ) . "\n";
        }

        /**
         * The stored Search-Console rows with their real numbers.
         *
         * @return string CSV body.
         */
        public static function csv_gsc() {
                $head = array( 'عبارت', 'کلیک', 'نمایش', 'میانگین رتبه' );
                $out  = array( implode( ',', array_map( 'Haa_Util::csv_cell', $head ) ) );

                foreach ( self::gsc_rows() as $g ) {
                        $out[] = implode(
                                ',',
                                array_map(
                                        'Haa_Util::csv_cell',
                                        array(
                                                (string) $g['query'],
                                                (int) $g['clicks'],
                                                (int) $g['impressions'],
                                                (string) $g['position'],
                                        )
                                )
                        );
                }
                return implode( "\n", $out ) . "\n";
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

                // Source priority: suggest > misses > gsc > serp > snapshot > ai > local > planner.
                $rank = array(
                        'suggest'  => 0,
                        'misses'   => 1,
                        'gsc'      => 2,
                        'serp'     => 3,
                        'snapshot' => 4,
                        'ai'       => 5,
                        'local'    => 6,
                        'planner'  => 7,
                );
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
