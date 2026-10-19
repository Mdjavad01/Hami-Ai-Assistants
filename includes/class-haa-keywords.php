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

        /** Hard cap on outbound suggest calls per research click (each ≤ SUGGEST_TIMEOUT s). */
        const SUGGEST_CALLS = 12;

        /** Per-call suggest timeout in seconds. */
        const SUGGEST_TIMEOUT = 6;

        /** Wall-clock seconds the whole sweep may spend before it returns whatever it has —
         *  the hang-guard that makes the bigger call budget safe (a frozen engine can burn
         *  at most ~3 timeouts before the sweep gives up and hands over the pool). */
        const SUGGEST_TIME = 20;

        /** Raw unique pool that ends the modifier phase early. */
        const SUGGEST_TARGET = 40;

        /** Raw unique pool that ends the alphabet phase early. */
        const ALPHA_TARGET = 64;

        /** Modifier-phase share of the call budget; the alphabet phase gets the rest. */
        const MODIFIER_CALLS = 4;

        /**
         * The Persian alphabet, for the classic alphabet-soup expansion:
         * seed + one letter surfaces autocomplete long-tails no fixed modifier
         * list can guess — city names, brands, materials, sizes. The start
         * letter rotates per seed (crc32) so different topics walk different
         * parts of the alphabet without any stored state.
         */
        const ALPHABET = array( 'ا', 'ب', 'پ', 'ت', 'ث', 'ج', 'چ', 'ح', 'خ', 'د', 'ذ', 'ر', 'ز', 'ژ', 'س', 'ش', 'ص', 'ض', 'ط', 'ظ', 'ع', 'غ', 'ف', 'ق', 'ک', 'گ', 'ل', 'م', 'ن', 'و', 'ه', 'ی' );

        /** Google Suggest (firefox JSON) endpoint. */
        const GOOGLE_EP = 'https://suggestqueries.google.com/complete/search?client=firefox&hl=fa&q=';

        /** Bing Suggest OSJSON endpoint — no key, and reachable from Iran. */
        const BING_EP = 'https://api.bing.com/osjson.aspx?query=';

        /** Ecosia Suggest endpoint — the same Bing-backed data without the key,
         *  and unlike api.bing.com it does not geo-block Iranian hosts (the exact
         *  production failure 2.1.29 exposed: BOTH Bing and Google dead at once). */
        const ECOSIA_EP = 'https://ac.ecosia.org/autocomplete?type=list&q=';

        /** Persian Wikipedia OpenSearch — real article titles, no key, reachable
         *  from Iran; a suggestion family the query engines cannot cover. */
        const WIKI_EP = 'https://fa.wikipedia.org/w/api.php?action=opensearch&limit=10&namespace=0&format=json&search=';

        /**
         * 2.1.32 — hard wall-clock ceiling for ONE research() call. The live
         * 2.1.31 report («اولین بار: Failed to fetch»، تلاش‌های دوم/سوم سالم)
         * showed the browser-side connection dying before ANY response: the
         * suggest sweep (≤20s) + wiki + the model layer (25s per source) could
         * stack past the host's ~60s proxy limit on a cold run. Every network
         * stage now checks the remaining budget, and the model stage gets
         * whatever is left (clamped 12–25s) or is skipped with an honest
         * diag line. The 2nd/3rd clicks succeeded because the network was warm
         * and the dead providers were benched — with the budget those clicks
         * also get the cached pools, so they are near-instant.
         */
        const RESEARCH_BUDGET = 40;

        /** Seconds the per-seed suggest+wiki pool stays cached (retry-friendly). */
        const SRC_CACHE_TTL = 300;

        /** Seconds the per-seed model ideas stay cached — the expensive stage. */
        const AI_CACHE_TTL = 900;

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

        /** @param string $seed Topic. @return string Transient key of the per-seed suggest+wiki pool. */
        public static function src_cache_key( $seed ) {
                return 'haai_kw_src_' . md5( 'v3|' . (string) $seed );
        }

        /** @param string $seed Topic. @return string Transient key of the per-seed model ideas. */
        public static function ai_cache_key( $seed ) {
                return 'haai_kw_ai_' . md5( 'v3|' . (string) $seed );
        }

        /**
         * Full research for one seed: combine every available source.
         *
         * @param string   $seed Topic or keyword typed by the admin.
         * @param int|null $t0   Test seam: forced start timestamp (microtime) so
         *                       suites can exercise the exhausted-budget paths
         *                       deterministically without sleeping.
         * @return array{ok:bool,error:string,rows:array<int,array>}
         */
        public static function research( $seed, $t0 = null ) {
                $seed = trim( sanitize_text_field( (string) $seed ) );
                if ( mb_strlen( $seed ) < 3 ) {
                        return array(
                                'ok'    => false,
                                'error' => __( 'موضوع را بنویس (حداقل ۳ نویسه).', 'hami-ai-assistant' ),
                                'rows'  => array(),
                        );
                }

                $rows         = array();
                $suggest_errs = array();
                $suggest_n    = 0;
                $wiki_err     = '';
                $wiki_n       = 0;
                $misses_n     = 0;
                $gsc_n        = 0;
                $serp_diag    = '';
                $ai_n         = 0;
                $from_cache   = false;
                $budget_skip  = false;

                // 2.1.32: ONE wall-clock ceiling for the whole request (see
                // RESEARCH_BUDGET) — every network stage asks «how much is
                // left?» before spending the host's patience.
                if ( null === $t0 ) {
                        $t0 = microtime( true );
                }
                $budget = self::RESEARCH_BUDGET;
                $left   = static function () use ( $t0, $budget ) {
                        return $budget - ( microtime( true ) - (float) $t0 );
                };

                // 1) Bing + Ecosia + Google Suggest — real user typing, when
                //    reachable. $suggest_errs remembers WHY each engine failed, so
                //    the panel can answer «why is the list short?» honestly — the
                //    2.1.29 live report showed a generic model-layer message
                //    standing in for a network outage. Deep silence helps nobody.
                //    2.1.32: the pool is cached per seed for a few minutes, so the
                //    click AFTER a connection death (or a plain re-click) skips
                //    the whole sweep — the «second try works» pattern, made
                //    instant. The cache is written BEFORE the model stage, so a
                //    request the host kills later still leaves its sources
                //    behind for the retry. A dead sweep (0 rows) is never
                //    cached — the next click retries the network.
                //    2.1.35: $engine_of maps each phrase to the engine that
                //    delivered it; suggest rows carry «ساجست گوگل/بینگ/اکوسیا»
                //    in their note, so the Excel توضیح column is no longer
                //    empty for the whole list (2.1.34 live sheet).
                $engine_of = array();
                if ( ! empty( Haa_Research::config()['suggest_enabled'] ) ) {
                        $cached = get_transient( self::src_cache_key( $seed ) );
                        if ( is_array( $cached ) && isset( $cached['rows'], $cached['wiki'] ) ) {
                                $from_cache   = true;
                                $suggest_keys = (array) $cached['rows'];
                                $wiki_titles  = (array) $cached['wiki'];
                                $suggest_errs = (array) ( $cached['errs'] ?? array() );
                                $wiki_err     = (string) ( $cached['wiki_err'] ?? '' );
                                $engine_of    = (array) ( $cached['engines'] ?? array() );
                        } elseif ( $left() > 2 ) {
                                $suggest_keys = self::suggest_multi( $seed, $suggest_errs, $engine_of );

                                // Persian Wikipedia titles — a different suggestion family
                                // (what people actually look up), still no key, still
                                // reachable from Iranian hosts. The one extra call bows
                                // to the budget too.
                                $wiki_titles = ( $left() > 2 )
                                        ? self::wiki_titles( $seed, $wiki_err )
                                        : array();

                                if ( $suggest_keys || $wiki_titles ) {
                                        set_transient( self::src_cache_key( $seed ), array(
                                                'rows'     => $suggest_keys,
                                                'wiki'     => $wiki_titles,
                                                'errs'     => $suggest_errs,
                                                'wiki_err' => $wiki_err,
                                                'engines'  => $engine_of,
                                        ), self::SRC_CACHE_TTL );
                                }
                        } else {
                                $budget_skip  = true;
                                $suggest_keys = array();
                                $wiki_titles  = array();
                        }

                        foreach ( $suggest_keys as $k ) {
                                $suggest_n++;
                                // 2.1.35: the CSV توضیح says which engine delivered
                                // the phrase — the panel hides this behind the shared
                                // «ساجست کاربران» badge, the sheet can afford the
                                // granularity.
                                $ek      = Haa_Search::normalize_text( (string) $k );
                                $note_sk = isset( $engine_of[ $ek ] ) && '' !== (string) $engine_of[ $ek ]
                                        ? sprintf( __( 'ساجست %s', 'hami-ai-assistant' ), (string) $engine_of[ $ek ] )
                                        : '';
                                $rows[] = array(
                                        'keyword' => $k,
                                        'source'  => 'suggest',
                                        'intent'  => self::guess_intent( $k ),
                                        'note'    => $note_sk,
                                );
                        }

                        foreach ( $wiki_titles as $t ) {
                                $wiki_n++;
                                $rows[] = array(
                                        'keyword' => $t,
                                        'source'  => 'wiki',
                                        'intent'  => self::guess_intent( $t ),
                                        'note'    => __( 'عنوان ویکی‌پدیا', 'hami-ai-assistant' ),
                                );
                        }
                }

                // 2) Site misses — what visitors searched and found nothing for.
                foreach ( Haa_Misses::matching( $seed, 12 ) as $m ) {
                        $misses_n++;
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
                        $gsc_n++;
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
                //    2.1.32: a configured-but-slow SERP also bows to the budget.
                $serp = array();
                if ( Haa_Research::serp_ready() && $left() <= 8 ) {
                        $serp_diag = __( 'SERP: رد شد — سقف زمانی کل درخواست پر شده بود.', 'hami-ai-assistant' );
                } else {
                        $serp = Haa_Research::serp( $seed, 10 );
                }
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
                        $serp_diag = sprintf( __( 'SERP: %d عنوان', 'hami-ai-assistant' ), count( (array) $serp['results'] ) );
                } elseif ( ! empty( $serp['error'] ) && Haa_Research::serp_ready() ) {
                        // Only when a service IS configured — an unconfigured SERP is
                        // already covered by the panel copy («در صورت اتصال سرویس»).
                        $serp_diag = __( 'SERP: ', 'hami-ai-assistant' ) . (string) $serp['error'];
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
                //    2.1.32: ideas are cached per seed (the expensive stage), and
                //    the call only gets the budget that is actually left, clamped
                //    12–25s — a cold host can no longer stack the full suggest
                //    sweep AND a full model wait past its proxy limit. When less
                //    than 12s remains the stage is skipped with an honest diag.
                $ai_cached = get_transient( self::ai_cache_key( $seed ) );
                $ai_error  = '';
                $ai_err    = null; // 2.1.35: the object survives for model_diag_text().
                if ( is_array( $ai_cached ) ) {
                        foreach ( $ai_cached as $k ) {
                                $ai_n++;
                                $rows[] = array(
                                        'keyword' => (string) $k,
                                        'source'  => 'ai',
                                        'intent'  => self::guess_intent( (string) $k ),
                                        'note'    => '',
                                );
                        }
                } else {
                        $ai_left = (int) ceil( $left() );
                        if ( $ai_left < 12 ) {
                                $ai_error = sprintf(
                                        __( 'رد شد — سقف زمانی کل درخواست (%1$d ثانیه) پر شده بود؛ دوباره تلاش کنید، تلاش بعدی از نتایج ذخیره‌شده سریع‌تر است.', 'hami-ai-assistant' ),
                                        self::RESEARCH_BUDGET
                                );
                        } else {
                                $ai = self::ai_ideas( $seed, max( 12, min( 25, $ai_left ) ) );
                                if ( is_wp_error( $ai ) ) {
                                        $ai_err   = $ai;
                                        $ai_error = $ai->get_error_message();
                                } else {
                                        set_transient( self::ai_cache_key( $seed ), array_values( (array) $ai ), self::AI_CACHE_TTL );
                                        foreach ( $ai as $k ) {
                                                $ai_n++;
                                                $rows[] = array(
                                                        'keyword' => $k,
                                                        'source'  => 'ai',
                                                        'intent'  => self::guess_intent( $k ),
                                                        'note'    => '',
                                                );
                                        }
                                }
                        }
                }

                // 7) Site gaps — our own topics nobody covered yet. The display
                //    list is capped at 10 rows, but 2.1.33 counts the planner's
                //    UNCAPPED status histogram: a capped loop reported «10» for
                //    a 25-topic queue — and «0» felt like a bug to the admin
                //    who had just queued selections (the 2.1.32 live report).
                foreach ( Haa_Planner::rows( 'suggested', 10 ) as $row ) {
                        $rows[] = array(
                                'keyword' => (string) $row['title'],
                                'source'  => 'planner',
                                'intent'  => (string) $row['intent'],
                                'note'    => '',
                        );
                }
                $planner_n = (int) ( Haa_Planner::counts()['suggested'] ?? 0 );

                $rows = self::dedupe( $rows, $seed );

                // Never an empty panel: when the live sources together produced
                // almost nothing, the seed itself expands into long-tail patterns.
                if ( count( $rows ) < 8 ) {
                        $rows = self::dedupe( array_merge( $rows, self::local_rows( $seed ) ), $seed );
                }

                $rows = self::decorate( $rows );

                // Per-source status lines: the panel must ALWAYS say which sources
                // delivered and which failed — and, for the suggest engines, what
                // the admin can do about it (configure the provider proxy).
                $diag = array();

                if ( ! empty( Haa_Research::config()['suggest_enabled'] ) ) {
                        if ( $budget_skip ) {
                                $diag[] = sprintf(
                                        __( 'ساجست/ویکی‌پدیا: رد شد — سقف زمانی کل درخواست (%1$d ثانیه) پر شده بود؛ دوباره تلاش کنید، تلاش بعدی سریع‌تر است.', 'hami-ai-assistant' ),
                                        self::RESEARCH_BUDGET
                                );
                        } else {
                                $line = sprintf( __( 'ساجست (بینگ/اکوسیا/گوگل): %d عبارت', 'hami-ai-assistant' ), $suggest_n );
                                if ( $from_cache ) {
                                        $line .= __( ' (کش)', 'hami-ai-assistant' );
                                }
                                $errs = array();
                                foreach ( $suggest_errs as $label => $msg ) {
                                        $errs[] = $label . ': ' . $msg;
                                }
                                if ( $errs ) {
                                        $line .= ' — ' . implode( '؛ ', $errs );
                                        if ( 0 === $suggest_n ) {
                                                $line .= ' — ' . __( 'برای دسترسی به موتورهای ساجست، پروکسی خروجی را در تب «ارائه‌دهنده AI» تنظیم کنید و دوباره بیازمایید.', 'hami-ai-assistant' );
                                        }
                                }
                                $diag[] = $line;

                                if ( $wiki_n > 0 ) {
                                        $diag[] = sprintf( __( 'ویکی‌پدیا: %d عنوان', 'hami-ai-assistant' ), $wiki_n ) . ( $from_cache ? __( ' (کش)', 'hami-ai-assistant' ) : '' );
                                } elseif ( '' !== $wiki_err ) {
                                        $diag[] = __( 'ویکی‌پدیا: ', 'hami-ai-assistant' ) . $wiki_err;
                                }
                        }
                }

                $diag[] = sprintf( __( 'بی‌نتیجه‌های همین سایت: %d', 'hami-ai-assistant' ), $misses_n );
                $diag[] = $gsc_n > 0
                        ? sprintf( __( 'سرچ‌کنسول: %d عبارت', 'hami-ai-assistant' ), $gsc_n )
                        : __( 'سرچ‌کنسول: فایل هنوز ایمپورت نشده', 'hami-ai-assistant' );

                if ( '' !== $serp_diag ) {
                        $diag[] = $serp_diag;
                }

                // 2.1.35: the «مدل:» line finally routes through model_diag_text()
                // — the 2.1.31 translation existed but was never wired, so an
                // all-benched provider walk surfaced the raw «همه منابع…» here.
                $diag[] = '' !== $ai_error
                        ? __( 'مدل: ', 'hami-ai-assistant' ) . ( is_wp_error( $ai_err ) ? self::model_diag_text( $ai_err ) : $ai_error )
                        : sprintf( __( 'مدل: %d ایده', 'hami-ai-assistant' ), $ai_n );

                // 2.1.33: the zero state teaches the loop instead of looking
                // like a bug — tick rows, press the button, the NEXT search
                // counts them under the «برنامه‌ریز» badge.
                if ( $planner_n > 0 ) {
                        $diag[] = sprintf( __( 'برنامه‌ریز: %d موضوع پیشنهادی', 'hami-ai-assistant' ), $planner_n );
                } else {
                        $diag[] = __( 'برنامه‌ریز: 0 موضوع پیشنهادی — از فهرست بالا تیک بزنید و «افزودن انتخاب‌شده‌ها به برنامه‌ریز» را بزنید؛ در جست‌وجوی بعدی همان‌ها با نشان «برنامه‌ریز» اینجا شمرده می‌شوند.', 'hami-ai-assistant' );
                }

                // 2.1.34: keep exactly what this call DELIVERED in an option, so
                // the «خروجی اکسل: نتایج جست‌وجو» button can hand the admin the
                // list they SAW. The planner export is legitimately empty until
                // topics are queued — an empty sheet read as a broken export
                // (2.1.33 live report). Stored without autoload; the early
                // return for a too-short seed never reaches this.
                update_option(
                        'haai_kw_last_research',
                        array(
                                'seed' => $seed,
                                'time' => time(),
                                'rows' => $rows,
                        ),
                        false
                );

                return array(
                        'ok'    => true,
                        // 2.1.31: the panel headline is for a truly empty panel only.
                        // A model failure NEXT TO delivered suggest/wiki/local rows is
                        // already named on its own «مدل:» diag line — repeating it as
                        // «یادداشت: همه منابع در حال خطا…» above a full list lied
                        // about the sources (the 2.1.30 live report).
                        'error' => empty( $rows ) && '' !== $ai_error
                                ? ( is_wp_error( $ai_err ) ? self::model_diag_text( $ai_err ) : $ai_error )
                                : '',
                        'rows'  => $rows,
                        'diag'  => $diag,
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
         * Suggest across THREE no-key engines under a strict call + wall-clock
         * budget.
         *
         * Plan: every engine on the bare seed first (highest-value rows), then
         * the Persian intent modifiers, then the alphabet-soup expansion over
         * ALPHABET — engines cycling بینگ ← اکوسیا ← گوگل, because on Iranian
         * hosts api.bing.com often geo-blocks (the 2.1.29 live report), Ecosia
         * serves the same Bing-backed data without the block, and
         * suggestqueries.google.com usually needs the provider proxy. The pool
         * counts UNIQUE suggestions only, so an engine echoing the same rows can
         * never fake an early exit. Every phase stops the moment the budget
         * (SUGGEST_CALLS calls or SUGGEST_TIME seconds) or its pool target is
         * reached.
         *
         * @param string $seed Topic.
         * @param array  $errs OUT: first error per engine (label => message) for
         *                     the panel diagnostics; a later success in the same
         *                     sweep drops the stale entry.
         * @param array  $engine_of OUT: normalised phrase => first engine label
         *                     (بینگ/اکوسیا/گوگل) that delivered it — the CSV
         *                     توضیح column's provenance (2.1.35).
         * @return array<int,string> Cleaned, unique suggestion strings.
         */
        public static function suggest_multi( $seed, &$errs = array(), &$engine_of = array() ) {
                $seed = trim( (string) $seed );
                if ( '' === $seed ) {
                        return array();
                }

                $out   = array();
                $seen  = array();
                $calls = 0;
                $t0    = microtime( true );

                // One bounded outbound call; null = budget spent / unreachable / unreadable.
                $call = static function ( $label, $endpoint, $q ) use ( &$calls, &$errs, $t0 ) {
                        if ( $calls >= self::SUGGEST_CALLS || ( microtime( true ) - $t0 ) >= self::SUGGEST_TIME ) {
                                return null;
                        }
                        $calls++;
                        $body = Haa_Research::get(
                                $endpoint . rawurlencode( $q ),
                                array( 'accept' => 'application/json', 'timeout' => self::SUGGEST_TIMEOUT )
                        );
                        if ( is_wp_error( $body ) ) {
                                if ( ! isset( $errs[ $label ] ) ) {
                                        $errs[ $label ] = $body->get_error_message();
                                }
                                return null;
                        }
                        $json = json_decode( (string) $body, true );
                        if ( ! is_array( $json ) ) {
                                if ( ! isset( $errs[ $label ] ) ) {
                                        $errs[ $label ] = __( 'پاسخ قابل خواندن نبود.', 'hami-ai-assistant' );
                                }
                                return null;
                        }
                        unset( $errs[ $label ] ); // the engine answered — drop any stale error.
                        $list = array();
                        foreach ( (array) ( $json[1] ?? array() ) as $s ) {
                                $s = trim( (string) $s );
                                if ( mb_strlen( $s ) >= 4 ) {
                                        $list[] = $s;
                                }
                        }
                        return $list;
                };

                // Merge a response into the pool, counting UNIQUE rows only and
                // remembering the FIRST engine that delivered each phrase — the
                // call order already prefers بینگ, and a later engine echoing
                // the same row must not steal the attribution.
                $add = static function ( $list, $label ) use ( &$out, &$seen, &$engine_of ) {
                        if ( ! is_array( $list ) ) {
                                return;
                        }
                        foreach ( $list as $s ) {
                                $key = Haa_Search::normalize_text( (string) $s );
                                if ( '' === $key || isset( $seen[ $key ] ) ) {
                                        continue;
                                }
                                $seen[ $key ]      = true;
                                $out[]             = (string) $s;
                                $engine_of[ $key ] = (string) $label;
                        }
                };

                $labels = array_keys( self::engines() );
                $eps    = array_values( self::engines() );
                $n_eng  = count( $eps );

                // 1) Every engine on the bare seed — the highest-value rows.
                for ( $e = 0; $e < $n_eng; $e++ ) {
                        $add( $call( $labels[ $e ], $eps[ $e ], $seed ), $labels[ $e ] );
                }

                // 2) Persian intent modifiers, engines cycling, until the pool
                //    is rich or the modifier share of the budget is spent.
                $i = 0;
                foreach ( self::MODIFIERS as $mod ) {
                        if ( count( $out ) >= self::SUGGEST_TARGET || $i >= self::MODIFIER_CALLS ) {
                                break;
                        }
                        $e = $i % $n_eng;
                        $add( $call( $labels[ $e ], $eps[ $e ], $seed . ' ' . $mod ), $labels[ $e ] );
                        $i++;
                }

                // 3) Alphabet soup: seed + one Persian letter, engines cycling
                //    (Bing first — the historically reachable one). The start
                //    letter rotates per seed, so each topic walks a different
                //    slice of the alphabet; repeated clicks on one topic stay
                //    deterministic.
                $n      = count( self::ALPHABET );
                $offset = $n > 0 ? ( abs( crc32( $seed ) ) % $n ) : 0;
                for ( $j = 0; $j < $n; $j++ ) {
                        if ( count( $out ) >= self::ALPHA_TARGET ) {
                                break;
                        }
                        $letter = self::ALPHABET[ ( $offset + $j ) % $n ];
                        $e      = $j % $n_eng;
                        $add( $call( $labels[ $e ], $eps[ $e ], $seed . ' ' . $letter ), $labels[ $e ] );
                }

                return array_values( $out );
        }

        /**
         * The suggest engines in call order. Bing first (the historically
         * reachable one), then Ecosia — same Bing-backed data, no Iranian
         * geo-block — then Google (usually needs the provider proxy from an
         * Iranian host). Labels feed the panel diagnostics.
         *
         * @return array<string,string> label => endpoint prefix.
         */
        private static function engines() {
                return array(
                        'بینگ'   => self::BING_EP,
                        'اکوسیا' => self::ECOSIA_EP,
                        'گوگل'   => self::GOOGLE_EP,
                );
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
         * Persian Wikipedia OpenSearch titles for the seed — one call, no key,
         * reachable from Iran. Returns article titles people actually look up:
         * a suggestion family the query engines cannot cover.
         *
         * @param string $seed Topic.
         * @param string $err  OUT: readable failure message, '' on success.
         * @return array<int,string>
         */
        public static function wiki_titles( $seed, &$err = '' ) {
                $out  = array();
                $err  = '';
                $seed = trim( (string) $seed );
                if ( '' === $seed ) {
                        return $out;
                }

                $body = Haa_Research::get(
                        self::WIKI_EP . rawurlencode( $seed ),
                        array( 'accept' => 'application/json', 'timeout' => self::SUGGEST_TIMEOUT )
                );
                if ( is_wp_error( $body ) ) {
                        $err = $body->get_error_message();
                        return $out;
                }

                $json = json_decode( (string) $body, true );
                if ( ! is_array( $json ) ) {
                        $err = __( 'پاسخ قابل خواندن نبود.', 'hami-ai-assistant' );
                        return $out;
                }

                foreach ( (array) ( $json[1] ?? array() ) as $t ) {
                        $t = trim( wp_strip_all_tags( (string) $t ) );
                        if ( mb_strlen( $t ) >= 4 ) {
                                $out[] = $t;
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
         * @param string $seed    Topic.
         * @param int    $timeout Model wait in seconds; research() narrows it to
         *                       the budget actually left (12–25). Default 25 —
         *                       the 2.1.31 cap that keeps a hung provider from
         *                       eating the panel's whole promised minute.
         * @return array<int,string>|WP_Error
         */
        public static function ai_ideas( $seed, $timeout = 25 ) {
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
                        // A lone failing primary must not double the panel's wait
                        // with backoff retries; the suggest sweep already spent
                        // its budget and the panel must come back soon. 2.1.31:
                        // retries=0 is honoured as a hard zero even when failover
                        // rows are configured.
                        'retries'    => 0,
                        // 2.1.31: cap this panel's model wait at 25s regardless of
                        // the configured «مهلت پاسخ» — a hung provider must not eat
                        // the whole promised minute before the panel can speak.
                        // 2.1.32: research() narrows this to the budget actually
                        // left in the request (12–25s) instead of always the max.
                        'timeout'    => max( 10, (int) $timeout ),
                );

                $messages = array(
                        array( 'role' => 'system', 'content' => $system ),
                        array( 'role' => 'user', 'content' => $user ),
                );

                $api = new Haa_API();
                $raw = $api->chat( $messages, $opts );

                if ( is_wp_error( $raw ) && $ground
                        && ! in_array( $raw->get_error_code(), array( 'haai_all_benched', 'haai_no_key', 'haai_no_base' ), true ) ) {
                        // The search tool itself can be the failing part (tool
                        // timeouts, provider-side grounding limits). One plain retry
                        // costs one call and answers the question «is it the model
                        // or the tool?» in production.
                        // 2.1.31: the grounded call just benched its source — without
                        // ignore_fresh_bench this retry bounced off the fresh-bench
                        // wall and the panel got the generic «همه منابع…» instead of
                        // a diagnosis. And when the retry fails too, the FIRST error
                        // is the honest one (the retry's all-benched is our own wall).
                        $first_err                  = $raw;
                        $opts['grounding']          = false;
                        $opts['ignore_fresh_bench'] = true;
                        $second                     = $api->chat( $messages, $opts );
                        $raw                        = is_wp_error( $second ) ? $first_err : $second;
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
         * The model line of the panel diag, translated into keywords context.
         *
         * The chat layer's all-benched sentence says «همه منابع» — true for the
         * PROVIDER walk, but inside this panel «منابع» reads as the keyword
         * sources (which usually answered), so the sentence is re-phrased here,
         * and the real reason of the last failed walk is appended while it is
         * fresh enough to be true. The 2.1.30 live report showed the raw
         * sentence standing in for a diagnosis.
         *
         * @param WP_Error $err The ai_ideas() failure.
         * @return string
         */
        public static function model_diag_text( $err ) {
                if ( 'haai_all_benched' !== $err->get_error_code() ) {
                        return (string) $err->get_error_message();
                }

                $line = __( 'ارائه‌دهنده‌های مدل موقتاً خطا داده‌اند؛ چند دقیقه بعد دوباره تلاش کنید', 'hami-ai-assistant' );

                $real = get_transient( Haa_API::LAST_CHAT_ERROR );
                if ( is_array( $real )
                        && '' !== (string) ( $real['msg'] ?? '' )
                        && false === strpos( (string) $real['msg'], 'همه منابع' )
                        && ( time() - (int) ( $real['at'] ?? 0 ) ) <= 15 * MINUTE_IN_SECONDS ) {
                        $cause = trim( Haa_Util::cut( (string) $real['msg'], 160 ) );
                        if ( '' !== $cause && '.' !== mb_substr( $cause, -1 ) ) {
                                $cause .= '.';
                        }
                        return $line . ' — ' . sprintf(
                                /* translators: %s: the real reason the last provider walk failed. */
                                __( 'آخرین علت واقعی: %s', 'hami-ai-assistant' ),
                                $cause
                        );
                }

                return $line . '.';
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
                // 2.1.35: the city list grew — the 2.1.34 live sheet classified
                // «فرش دستبافت اردبیل» as informational because اردبیل (and most
                // provincial capitals) were missing from this line.
                if ( preg_match( '/نزدیک من|نمایندگی|آدرس|تلفن|شماره تماس|حضوری|تهران|مشهد|اصفهان|شیراز|تبریز|کرج|اهواز|قم|یزد|رشت|کرمان|کاشان|اردبیل|ارومیه|قزوین|همدان|سنندج|کرمانشاه|زنجان|گرگان|ساری|بابل|اراک|زاهدان|بندرعباس|بوشهر|بیرجند|سمنان|شهرکرد|یاسوج|بجنورد|خرم‌آباد/u', $text ) ) {
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
         * The last delivered research panel (2.1.34).
         *
         * research() stores the exact rows it rendered, so the Excel export
         * can hand the admin the list they SAW — the planner CSV stays empty
         * until topics are queued, which read as a useless export (2.1.33
         * live report).
         *
         * @return array|'' array{seed:string,time:int,rows:array} or '' when nothing stored yet.
         */
        public static function last_research() {
                $last = get_option( 'haai_kw_last_research', '' );
                return ( is_array( $last ) && ! empty( $last['rows'] ) ) ? $last : '';
        }

        /**
         * The last research panel as an Excel-friendly CSV.
         *
         * Column words mirror the panel itself (the admin.js SRC/INT/COV
         * labels), so the file reads like the screen it came from. The
         * handler gates the nothing-stored-yet case with a Persian
         * explanation — an empty download would repeat the same confusion.
         *
         * @return string CSV body.
         */
        public static function csv_research() {
                $head = array( 'عبارت', 'منبع', 'نیت', 'مناسب پاسخ هوش مصنوعی', 'پوشش همین سایت', 'توضیح' );
                $out  = array( implode( ',', array_map( 'Haa_Util::csv_cell', $head ) ) );

                $last = self::last_research();
                if ( ! $last ) {
                        return implode( "\n", $out ) . "\n";
                }

                // Same words the panel shows for each source/coverage state.
                $src = array(
                        'suggest'  => __( 'ساجست کاربران', 'hami-ai-assistant' ),
                        'wiki'     => __( 'ویکی‌پدیا', 'hami-ai-assistant' ),
                        'serp'     => __( 'نتایج جست‌وجو', 'hami-ai-assistant' ),
                        'snapshot' => __( 'تحلیل رقبا', 'hami-ai-assistant' ),
                        'ai'       => __( 'مدل', 'hami-ai-assistant' ),
                        'local'    => __( 'پیشنهاد خودکار', 'hami-ai-assistant' ),
                        'planner'  => __( 'برنامه‌ریز', 'hami-ai-assistant' ),
                        'misses'   => __( 'بی‌نتیجه', 'hami-ai-assistant' ),
                        'gsc'      => __( 'سرچ‌کنسول', 'hami-ai-assistant' ),
                );
                $cov = array(
                        'has'  => __( 'جواب دارد', 'hami-ai-assistant' ),
                        'half' => __( 'نیمه', 'hami-ai-assistant' ),
                        'none' => __( 'بی‌جواب', 'hami-ai-assistant' ),
                );

                foreach ( (array) $last['rows'] as $r ) {
                        $state  = (string) ( $r['coverage']['state'] ?? 'none' );
                        $out[] = implode(
                                ',',
                                array_map(
                                        'Haa_Util::csv_cell',
                                        array(
                                                (string) $r['keyword'],
                                                (string) ( $src[ (string) $r['source'] ] ?? (string) $r['source'] ),
                                                Haa_Planner::intent_label( (string) $r['intent'] ),
                                                ! empty( $r['ai'] ) ? __( 'بله', 'hami-ai-assistant' ) : __( '—', 'hami-ai-assistant' ),
                                                (string) ( $cov[ $state ] ?? $state ),
                                                (string) ( $r['note'] ?? '' ),
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

                // Source priority: suggest > misses > gsc > serp > wiki > snapshot > ai > local > planner.
                $rank = array(
                        'suggest'  => 0,
                        'misses'   => 1,
                        'gsc'      => 2,
                        'serp'     => 3,
                        'wiki'     => 4,
                        'snapshot' => 5,
                        'ai'       => 6,
                        'local'    => 7,
                        'planner'  => 8,
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
