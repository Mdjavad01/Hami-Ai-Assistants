<?php
/**
 * Staged article pipeline (مرحله D3).
 *
 * Why it is staged: one 2 000-word Persian article asked for in a single
 * request comes back as a wall of filler that repeats itself and drifts off the
 * brief. Brief → outline → one section at a time → merge → meta → FAQ keeps each
 * call small and each part reviewable. It also makes the run *resumable*: the
 * state is written after every section, so a timeout or a failed call at section
 * 4 of 8 restarts at section 4 rather than paying for 1–3 again.
 *
 * What it does NOT do: publish on its own. A model cannot verify its own
 * numbers, so "auto" publish means "all gates green", and a flagged claim makes
 * a gate red. Everything else lands as a draft or as pending review.
 *
 * Admin-side only. No visitor ever pays for this.
 *
 * @package Hami_AI_Assistant
 */

if ( ! defined( 'ABSPATH' ) ) {
        exit;
}

final class Haa_Pipeline {

        /** Topic stages, in order. */
        const STAGES = array( 'planned', 'briefed', 'outlining', 'writing', 'assembling', 'checking', 'review', 'published', 'failed' );

        /** Output-token ceiling for one section call (Persian words ≈ 2.2 tokens each). */
        const SECTION_MAX_TOKENS = 3000;

        /** Output-token ceiling for the meta/FAQ pass. */
        const META_MAX_TOKENS = 2500;

        /** How much of the previous section is carried into the next prompt. */
        const CARRY_CHARS = 900;

        /** Cap on how many link targets we score per article. */
        const LINK_POOL = 16;

        /**
         * Cost of one article, in requests and tokens, deliberately over-estimated.
         *
         * @param array $art Article settings.
         * @return array{calls:int,tokens:int,images:int}
         */
        public static function cost_of( $art ) {
                $words    = max( 600, (int) ( $art['target_words'] ?? 1600 ) );
                $sections = max( 3, (int) ( $art['max_sections'] ?? 8 ) );
                $sections = min( $sections, (int) ceil( $words / 250 ) );

                // outline + sections + merge/meta. FAQ comes out of the meta call.
                $calls = 1 + $sections + 1;
                $tokens = $calls * 1800 + $words * 2;

                return array(
                        'calls'  => $calls,
                        'tokens' => $tokens,
                        'images' => max( 0, (int) ( $art['inline_images'] ?? 0 ) ) + 1,
                );
        }

        /* ------------------------------------------------------------------
         * Run
         * ---------------------------------------------------------------- */

        /**
         * Write one topic all the way through, or resume where it stopped.
         *
         * @param int   $topic_id Planner row.
         * @param array $payload  Queue payload (may carry publish_at, author, cat).
         * @return int|WP_Error  Post ID, or the reason it cannot proceed.
         */
        public static function run( $topic_id, $payload = array() ) {
                $topic = Haa_Planner::row( $topic_id );
                if ( ! $topic ) {
                        return new WP_Error( 'haai_pipe_topic', __( 'موضوع در برنامه‌ریز پیدا نشد.', 'hami-ai-assistant' ) );
                }
                if ( 'published' === (string) $topic['status'] && (int) $topic['post_id'] > 0 ) {
                        return (int) $topic['post_id']; // Idempotent: a retried job must not write twice.
                }

                $art   = (array) Haa_Settings::get( 'article', array() );
                $state = self::state_get( $topic );

                // 1. Outline — reuse the brief if one exists, costing nothing.
                if ( empty( $state['outline'] ) ) {
                        $outline = self::outline( $topic, $payload );
                        if ( is_wp_error( $outline ) ) {
                                self::fail( $topic_id, $outline );
                                return $outline;
                        }
                        $state['outline'] = $outline;
                        self::state_save( $topic_id, $state, 'outlining' );
                }

                // 2. Sections, one call each, skipped when already written.
                $have = isset( $state['sections'] ) && is_array( $state['sections'] ) ? $state['sections'] : array();
                foreach ( $state['outline'] as $i => $sec ) {
                        if ( isset( $have[ $i ] ) && '' !== trim( (string) $have[ $i ]['body'] ) ) {
                                continue; // Resume point.
                        }
                        $body = self::write_section( $topic, $sec, $have, $art, $payload );
                        if ( is_wp_error( $body ) ) {
                                self::state_save( $topic_id, array_merge( $state, array( 'sections' => $have ) ), 'writing' );
                                self::fail( $topic_id, $body );
                                return $body;
                        }
                        $have[ $i ] = array(
                                'h2'   => (string) $sec['h2'],
                                'body' => $body,
                        );
                        // Written after every section, not at the end: this is what makes a
                        // crash at section 5 recoverable instead of expensive.
                        self::state_save( $topic_id, array_merge( $state, array( 'sections' => $have ) ), 'writing' );
                }
                $state['sections'] = $have;

                // 3. Merge + SEO meta + FAQ in one small call.
                $merged = self::assemble( $topic, $state );
                $meta   = self::write_meta( $topic, $merged, $state, $payload );
                if ( is_wp_error( $meta ) ) {
                        self::fail( $topic_id, $meta );
                        return $meta;
                }

                $html = self::inject_faq( $merged, (array) ( $meta['faq'] ?? array() ) );

                // 4. Internal links, by relevance, only to documents that exist.
                $html = self::apply_internal_links( $html, $topic, $art );

                // 5. Claims are marked, never removed.
                $flags = self::flag_claims( $html, $topic, $art );

                // 6. Images. A failed image must not lose the text that already exists.
                $imgs = self::maybe_image( $topic, $meta, $payload, $art );

                // 7. Quality gates decide what status the post may get.
                $gates = self::gates(
                        array(
                                'html'    => $html,
                                'meta'    => $meta,
                                'topic'   => $topic,
                                'flags'   => $flags,
                                'state'   => $state,
                                'art'     => $art,
                                'has_img' => $imgs['thumb'],
                        )
                );

                // 8. Write the post, then publish per mode.
                $post_id = self::store( $topic, $meta, $html, $flags, $gates, $imgs, $payload );
                if ( is_wp_error( $post_id ) ) {
                        self::fail( $topic_id, $post_id );
                        return $post_id;
                }

                $status = self::settle( $post_id, $meta, $gates, $payload, $art );

                Haa_Planner::update(
                        $topic_id,
                        array(
                                'post_id' => (int) $post_id,
                                'status'  => 'publish' === $status ? 'published' : 'approved',
                                'error'   => '',
                        )
                );
                self::state_save( $topic_id, $state, 'published' === $status ? 'published' : 'review' );

                if ( 'published' === $status ) {
                        Haa_Planner::mark_published( $topic_id, (int) $post_id );
                }

                // Backlinks are a proposal for a human, never an edit to somebody else's post.
                self::suggest_backlinks( (int) $post_id, $topic, $state );

                return (int) $post_id;
        }

        /* ------------------------------------------------------------------
         * Stage: outline
         * ---------------------------------------------------------------- */

        /**
         * Outline for one topic: reuse the researched brief when present.
         *
         * @param array $topic   Planner row.
         * @param array $payload Queue payload.
         * @return array|WP_Error List of sections.
         */
        public static function outline( $topic, $payload = array() ) {
                $brief = (array) ( $topic['brief'] ?? array() );
                if ( ! empty( $brief['outline'] ) ) {
                        return self::clamp_outline( $brief['outline'], $payload );
                }

                $art  = (array) Haa_Settings::get( 'article', array() );
                $want = max( 3, min( 16, (int) ( $art['max_sections'] ?? 8 ) ) );

                $system = __( 'برنامه‌ریز محتوای سئو برای یک سایت فارسی‌زبان. برای موضوع داده‌شده فقط طرح مقاله را می‌نویسی؛ متن نمی‌نویسی.', 'hami-ai-assistant' ) . "\n"
                        . __( 'خروجی فقط JSON: {"sections":[{"h2":"…","h3":["…"],"focus":"…"}]}', 'hami-ai-assistant' ) . "\n"
                        . '- ' . sprintf( /* translators: %d: count. */ __( 'دقیقاً %d بخش؛ هر بخش یک موضوع مستقل.', 'hami-ai-assistant' ), $want ) . "\n"
                        . '- ' . __( 'بخش‌ها تکراری نباشند و با هم جمعاً کل موضوع را پوشش دهند.', 'hami-ai-assistant' ) . "\n"
                        . '- ' . __( 'از خود نساز: عدد، آمار، نام یا منبع در طرح نیاری.', 'hami-ai-assistant' ) . "\n"
                        . Haa_Content::no_fabrication();

                $parts = array( Haa_Site_Profile::block( (string) $topic['title'] ) );
                $parts[] = __( 'موضوع:', 'hami-ai-assistant' ) . ' ' . $topic['title'] . "\n"
                        . __( 'کلیدواژه:', 'hami-ai-assistant' ) . ' ' . $topic['keyword'] . "\n"
                        . __( 'هدف خواننده از این صفحه:', 'hami-ai-assistant' ) . ' ' . Haa_Planner::intent_label( (string) $topic['intent'] );
                $goals = (array) ( $brief['internal_link_goals'] ?? array() );
                if ( $goals ) {
                        $parts[] = __( 'صفحه‌هایی که خوب است به آن‌ها برسیم:', 'hami-ai-assistant' ) . ' ' . implode( '، ', $goals );
                }

                $user = implode( "\n\n", array_filter( $parts ) ) . "\n\n" . __( 'طرح را بنویس.', 'hami-ai-assistant' );

                $raw = Haa_Content::generate(
                        $system,
                        $user,
                        array(
                                'module'    => 'article-writer',
                                'operation' => 'outline',
                        )
                );
                if ( is_wp_error( $raw ) ) {
                        return $raw;
                }

                $json = Haa_Util::extract_json( (string) $raw );
                $secs = isset( $json['sections'] ) ? (array) $json['sections'] : $json;
                if ( ! is_array( $secs ) || ! $secs ) {
                        return new WP_Error( 'haai_pipe_outline', __( 'طرح از مدل برنگشت؛ بعداً دوباره امتحان کنید.', 'hami-ai-assistant' ) );
                }

                return self::clamp_outline( $secs, $payload );
        }

        /**
         * Shape-check the outline and cap its size (each section costs a call).
         *
         * @param array $secs    Candidate sections.
         * @param array $payload May carry its own max_sections.
         * @return array
         */
        private static function clamp_outline( $secs, $payload = array() ) {
                $art  = (array) Haa_Settings::get( 'article', array() );
                $want = max( 3, min( 16, (int) ( $payload['max_sections'] ?? ( $art['max_sections'] ?? 8 ) ) ) );

                $out = array();
                foreach ( (array) $secs as $sec ) {
                        if ( ! is_array( $sec ) ) {
                                $sec = array( 'h2' => (string) $sec );
                        }
                        $h2 = trim( wp_strip_all_tags( (string) ( $sec['h2'] ?? ( $sec['title'] ?? '' ) ) ) );
                        if ( '' === $h2 ) {
                                continue;
                        }
                        $h3 = array();
                        foreach ( (array) ( $sec['h3'] ?? array() ) as $sub ) {
                                $sub = trim( wp_strip_all_tags( (string) $sub ) );
                                if ( '' !== $sub ) {
                                        $h3[] = Haa_Util::cut( $sub, 180 );
                                }
                        }
                        $out[] = array(
                                'h2'    => Haa_Util::cut( $h2, 200 ),
                                'h3'    => array_slice( $h3, 0, 5 ),
                                'focus' => Haa_Util::cut( trim( wp_strip_all_tags( (string) ( $sec['focus'] ?? '' ) ) ), 300 ),
                        );
                        if ( count( $out ) >= $want ) {
                                break;
                        }
                }

                return $out;
        }

        /* ------------------------------------------------------------------
         * Stage: sections
         * ---------------------------------------------------------------- */

        /**
         * Write one section of the article.
         *
         * The previous section's tail is carried over so the prose stays coherent,
         * but only its tail — feeding the whole article back in every call would
         * multiply the cost of a long post.
         *
         * @param array $topic   Planner row.
         * @param array $sec     One outline section.
         * @param array $written Sections already finished.
         * @param array $art     Article settings.
         * @param array $payload Queue payload.
         * @return string|WP_Error
         */
        public static function write_section( $topic, $sec, $written, $art, $payload = array() ) {
                $target = self::section_words( $art );

                $system = __( 'نویسنده‌ی محتوای سئو به فارسی. فقط همین یک بخش را می‌نویسی؛ مقدمه، نتیجه‌گیری یا بخش دیگر را اضافه نکن.', 'hami-ai-assistant' ) . "\n"
                        . Haa_Content::style_suffix(
                                array(
                                        'tone'       => (string) ( $payload['tone'] ?? '' ),
                                        'length'     => '',
                                        'audience'   => (string) ( $payload['audience'] ?? '' ),
                                        'keywords'   => trim( (string) $topic['keyword'] . '، ' . implode( '، ', (array) ( ( $topic['brief']['secondary'] ?? array() ) ) ) ),
                                        'topic'      => (string) $sec['h2'],
                                        'defaults'   => array(
                                                'default_tone'     => (string) ( $art['default_tone'] ?? '' ),
                                                'default_audience' => (string) ( $art['default_audience'] ?? '' ),
                                        ),
                                )
                        ) . "\n"
                        . '- ' . sprintf( /* translators: %d: words. */ __( 'حدود %d کلمه.', 'hami-ai-assistant' ), $target ) . "\n"
                        . '- ' . __( 'با تیتر بخش شروع نکن؛ تیتر را خودمان می‌گذاریم.', 'hami-ai-assistant' ) . "\n"
                        . '- ' . __( 'هیجانی و کلیشه‌نویس نباشد؛ یک پاراگراف ۳ تا ۵ جمله.', 'hami-ai-assistant' ) . "\n"
                        . '- ' . __( 'ادعای تکراری با بخش‌های قبلی ننویس.', 'hami-ai-assistant' ) . "\n"
                        . Haa_Content::no_fabrication();

                $parts = array();
                $prev  = array_values( (array) $written );
                if ( $prev ) {
                        $last = (string) end( $prev )['body'];
                        if ( '' !== trim( $last ) ) {
                                $parts[] = __( 'آخرِ بخش قبلی (فقط برای پیوستگی؛ تکرارش نکن):', 'hami-ai-assistant' ) . "\n"
                                        . '«' . Haa_Util::cut( wp_strip_all_tags( $last ), self::CARRY_CHARS ) . '»';
                        }
                }
                $parts[] = __( 'موضوع مقاله:', 'hami-ai-assistant' ) . ' ' . $topic['title'];
                $parts[] = __( 'این بخش:', 'hami-ai-assistant' ) . ' ' . $sec['h2']
                        . ( ! empty( $sec['focus'] ) ? "\n" . __( 'زاویه:', 'hami-ai-assistant' ) . ' ' . $sec['focus'] : '' )
                        . ( ! empty( $sec['h3'] ) ? "\n" . __( 'زیربخش‌ها:', 'hami-ai-assistant' ) . ' ' . implode( '، ', (array) $sec['h3'] ) : '' );

                $body = Haa_Content::generate(
                        $system,
                        implode( "\n\n", $parts ),
                        array(
                                'module'     => 'article-writer',
                                'operation'  => 'section',
                                'max_tokens' => self::SECTION_MAX_TOKENS,
                        )
                );

                if ( is_wp_error( $body ) ) {
                        return $body;
                }

                $text = trim( (string) $body );
                // Strip a leading markdown/plain repeat of the section heading. Deliberately
                // not "#" as the delimiter: the pattern matches literal # characters.
                $text = preg_replace( '~^\s*(?:\#{1,3}\s*)?' . preg_quote( (string) $sec['h2'], '~' ) . '\s*\R~ui', '', $text );

                if ( '' === $text ) {
                        return new WP_Error( 'haai_pipe_empty', __( 'بخش خالی برگشت.', 'hami-ai-assistant' ) );
                }

                return $text;
        }

        /**
         * How many words one section should carry.
         *
         * @param array $art   Article settings.
         * @param int   $count Section count.
         * @return int
         */
        private static function section_words( $art ) {
                $words = max( 600, (int) ( $art['target_words'] ?? 1600 ) );
                $n     = max( 3, min( 16, (int) ( $art['max_sections'] ?? 8 ) ) );
                // Leave room for the intro, FAQ and conclusion, which are not sections.
                return max( 90, (int) floor( ( $words * 0.8 ) / $n ) );
        }

        /* ------------------------------------------------------------------
         * Stage: assemble + meta
         * ---------------------------------------------------------------- */

        /**
         * Merge the sections into a full HTML document.
         *
         * @param array $topic Planner row.
         * @param array $state Outline + sections.
         * @return string
         */
        public static function assemble( $topic, $state ) {
                $blocks = array();

                $intro = trim( (string) ( $state['intro'] ?? '' ) );
                if ( '' === $intro ) {
                        // A short, factual lede assembled from the topic itself. Writing the
                        // intro ourselves costs a call per article and risks a claim; the
                        // meta pass can rewrite it.
                        $intro = (string) $topic['title'] . '؛ ' . trim( (string) ( $topic['reason'] ?? '' ) );
                }
                $blocks[] = '<p>' . esc_html( $intro ) . '</p>';

                foreach ( (array) ( $state['sections'] ?? array() ) as $sec ) {
                        $h2   = trim( (string) ( $sec['h2'] ?? '' ) );
                        $body = trim( (string) ( $sec['body'] ?? '' ) );
                        if ( '' === $body ) {
                                continue;
                        }
                        if ( '' !== $h2 ) {
                                $blocks[] = '<h2>' . esc_html( $h2 ) . '</h2>';
                        }
                        $blocks[] = Haa_Content::to_html( $body );
                }

                return trim( implode( "\n", array_filter( $blocks ) ) );
        }

        /**
         * SEO title/description/slug/excerpt + FAQ, from the finished text.
         *
         * @param array $topic   Planner row.
         * @param string $html    Assembled body.
         * @param array  $state   Pipeline state.
         * @param array  $payload Queue payload.
         * @return array|WP_Error
         */
        public static function write_meta( $topic, $html, $state, $payload = array() ) {
                $art = (array) Haa_Settings::get( 'article', array() );

                $system = __( 'متن کامل مقاله را می‌گیری؛ فقط متاداده‌ی سئوی آن را می‌سازی و متن را بازنویسی نمی‌کنی.', 'hami-ai-assistant' ) . "\n"
                        . __( 'خروجی فقط JSON: {"seo_title":"…","seo_description":"…","slug":"…","excerpt":"…","intro":"…","faq":[{"q":"…","a":"…"}]}', 'hami-ai-assistant' ) . "\n"
                        . '- ' . __( 'seo_title حداکثر ۶۰ نویسه و شامل کلیدواژه.', 'hami-ai-assistant' ) . "\n"
                        . '- ' . __( 'seo_description حداکثر ۱۶۰ نویسه؛ تبلیغ نکن و عدد نساز.', 'hami-ai-assistant' ) . "\n"
                        . '- ' . __( 'slug لاتین، کوتاه، با خط تیره.', 'hami-ai-assistant' ) . "\n"
                        . '- ' . __( 'intro یک پاراگراف ۲ تا ۳ جمله‌ای برای بالای مقاله، فقط بر پایهٔ متن داده‌شده.', 'hami-ai-assistant' ) . "\n"
                        . '- ' . __( 'faq فقط سؤالی که پاسخ آن در متن هست؛ حداکثر ۴ مورد.', 'hami-ai-assistant' ) . "\n"
                        . Haa_Content::no_fabrication();

                $user = __( 'کلیدواژه:', 'hami-ai-assistant' ) . ' ' . $topic['keyword'] . "\n\n"
                        . Haa_Util::cut( wp_strip_all_tags( str_replace( array( '</p>', '</h2>' ), "\n", (string) $html ) ), 6000 );

                $raw = Haa_Content::generate(
                        $system,
                        $user,
                        array(
                                'module'     => 'article-writer',
                                'operation'  => 'meta',
                                'max_tokens' => self::META_MAX_TOKENS,
                        )
                );
                if ( is_wp_error( $raw ) ) {
                        return $raw;
                }

                $json = Haa_Util::extract_json( (string) $raw );
                if ( ! is_array( $json ) || empty( $json['seo_title'] ) ) {
                        // Fall back to something usable rather than throwing the article away:
                        // the text is already paid for.
                        $json = array();
                }

                $faq = array();
                foreach ( (array) ( $json['faq'] ?? array() ) as $f ) {
                        if ( ! is_array( $f ) ) {
                                continue;
                        }
                        $q = trim( wp_strip_all_tags( (string) ( $f['q'] ?? '' ) ) );
                        $a = trim( wp_strip_all_tags( (string) ( $f['a'] ?? '' ) ) );
                        if ( '' !== $q && '' !== $a ) {
                                $faq[] = array( 'q' => Haa_Util::cut( $q, 200 ), 'a' => Haa_Util::cut( $a, 600 ) );
                        }
                }

                $title = trim( wp_strip_all_tags( (string) ( $json['seo_title'] ?? $topic['title'] ) ) );

                return array(
                        'seo_title'       => Haa_Util::cut( $title, 60 ),
                        'title'           => Haa_Util::cut( '' !== $title ? $title : (string) $topic['title'], 180 ),
                        'seo_description' => Haa_Util::cut( trim( wp_strip_all_tags( (string) ( $json['seo_description'] ?? '' ) ) ), 160 ),
                        'slug'            => Haa_Util::slugify( (string) ( $json['slug'] ?? $title ) ),
                        'excerpt'         => Haa_Util::cut( trim( wp_strip_all_tags( (string) ( $json['excerpt'] ?? '' ) ) ), 280 ),
                        'intro'           => trim( wp_strip_all_tags( (string) ( $json['intro'] ?? '' ) ) ),
                        'faq'             => array_slice( $faq, 0, 4 ),
                        'word_target'     => (int) ( $art['target_words'] ?? 1600 ),
                );
        }

        /**
         * Append the FAQ block, and JSON-LD when enabled and non-empty.
         *
         * @param string $html Assembled body.
         * @param array  $faq  Q/A pairs.
         * @return string
         */
        public static function inject_faq( $html, $faq ) {
                if ( ! $faq ) {
                        return $html;
                }

                $blocks = array( '<h2>' . esc_html__( 'پرسش‌های متداول', 'hami-ai-assistant' ) . '</h2>' );
                foreach ( $faq as $f ) {
                        $blocks[] = '<h3>' . esc_html( (string) $f['q'] ) . '</h3>';
                        $blocks[] = '<p>' . esc_html( (string) $f['a'] ) . '</p>';
                }

                if ( ! empty( Haa_Settings::get( 'article.faq_schema', 1 ) ) ) {
                        $items = array();
                        foreach ( $faq as $f ) {
                                $items[] = array(
                                        '@type'          => 'Question',
                                        'name'           => (string) $f['q'],
                                        'acceptedAnswer' => array(
                                                '@type' => 'Answer',
                                                'text'  => (string) $f['a'],
                                        ),
                                );
                        }
                        $ld = array(
                                '@context'   => 'https://schema.org',
                                '@type'      => 'FAQPage',
                                'mainEntity' => $items,
                        );
                        // Escaped for the inline-JSON context: </ must not close the script tag.
                        $blocks[] = '<script type="application/ld+json">'
                                . str_replace( '</', '<\/', (string) wp_json_encode( $ld, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) )
                                . '</script>';
                }

                return $html . "\n" . implode( "\n", $blocks );
        }

        /* ------------------------------------------------------------------
         * Internal links
         * ---------------------------------------------------------------- */

        /**
         * Link to real site content, ranked by actual relevance.
         *
         * Rules the spec asks for and this enforces: only documents that exist, no
         * link to the article itself, a hard cap, varied anchors, and never a second
         * link to the same target.
         *
         * @param string $html  Article body.
         * @param array  $topic Planner row.
         * @param array  $art   Article settings.
         * @return string
         */
        public static function apply_internal_links( $html, $topic, $art ) {
                $cap = max( 0, (int) ( $art['link_cap'] ?? 5 ) );
                if ( ! $cap || ! Haa_Settings::get( 'content.internal_links', 1 ) ) {
                        return $html;
                }

                $targets = self::link_targets( $topic );
                if ( ! $targets ) {
                        return $html;
                }

                $used_urls    = array();
                $used_anchors = array();
                $links        = array();

                foreach ( $targets as $t ) {
                        if ( count( $links ) >= $cap ) {
                                break;
                        }
                        // The index rows call it post_id; the permalink fallback calls it id.
                        // Reading only one of the two silently dropped every fallback target.
                        $id  = (int) ( $t['post_id'] ?? ( $t['id'] ?? 0 ) );
                        $url = (string) ( $t['url'] ?? '' );
                        if ( ! $id || '' === $url || isset( $used_urls[ $url ] ) ) {
                                continue;
                        }
                        if ( $id === (int) $topic['post_id'] ) {
                                continue; // Never link the article to itself.
                        }

                        // Every candidate for this one target is tried, not only the first
                        // unused one: a target whose title does not appear in the body is
                        // still linkable through the keyword, and dropping it there would
                        // quietly lose a good internal link.
                        $placed = false;
                        foreach ( self::anchor_candidates( (string) ( $t['title'] ?? '' ), (string) $topic['keyword'] ) as $anchor ) {
                                if ( isset( $used_anchors[ $anchor ] ) ) {
                                        continue;
                                }
                                $needle = self::anchor_needle( $anchor, $html );
                                if ( '' === $needle ) {
                                        continue;
                                }
                                $html                = self::linkify( $html, $needle, $url );
                                $used_anchors[ $needle ] = true;
                                $used_urls[ $url ]     = true;
                                $links[]               = array( 'id' => $id, 'url' => $url, 'anchor' => $needle );
                                $placed                = true;
                                break;
                        }

                        if ( ! $placed ) {
                                continue; // No usable anchor: better no link than a forced one.
                        }
                }

                return $html;
        }

        /**
         * Ordered anchor phrases for one target, most descriptive first.
         *
         * Repeating the identical anchor on every link is exactly what search engines
         * treat as manipulation, so the forms rotate: full title → short title →
         * keyword → keyword with a neutral prefix.
         *
         * @param string $title   Target title.
         * @param string $keyword Article keyword.
         * @return array<int,string>
         */
        public static function anchor_candidates( $title, $keyword ) {
                $list = array();
                if ( '' !== $title ) {
                        $list[] = Haa_Util::cut( $title, 48 );
                        $short  = Haa_Util::cut( (string) preg_replace( '/[،,:–-].*$/u', '', (string) $title ), 32 );
                        $list[] = $short;
                        // Last word of the title is often the subject itself ("کفش چرم مردانه"
                        // → "مردانه" is useless, but "راهنمای خرید کفش کوهنوردی" → 2-word tail
                        // carries meaning), so keep a two-word tail rather than one word.
                        $tail = (array) preg_split( '/\s+/u', trim( (string) $title ) );
                        if ( count( $tail ) >= 2 ) {
                                $list[] = implode( ' ', array_slice( $tail, -2 ) );
                        }
                }
                if ( '' !== $keyword ) {
                        $list[] = $keyword;
                        $list[] = __( 'راهنمای ', 'hami-ai-assistant' ) . Haa_Util::cut( $keyword, 30 );
                        $list[] = __( 'جزئیات ', 'hami-ai-assistant' ) . Haa_Util::cut( $keyword, 30 );
                }

                $out = array();
                foreach ( $list as $one ) {
                        $one = trim( (string) $one );
                        if ( '' !== $one && ! in_array( $one, $out, true ) ) {
                                $out[] = $one;
                        }
                }

                return $out;
        }

        /**
         * Candidate link targets, ranked by the hybrid index when it is on and by a
         * plain relevance query when it is not.
         *
         * @param array $topic Planner row.
         * @return array
         */
        public static function link_targets( $topic ) {
                $queries = array_filter(
                        array(
                                (string) ( $topic['keyword'] ?? '' ),
                                (string) ( $topic['title'] ?? '' ),
                        )
                );

                $seen  = array();
                $found = array();
                foreach ( $queries as $q ) {
                        foreach ( Haa_Search::search( $q, self::LINK_POOL, 400 ) as $hit ) {
                                $id = (int) ( $hit['post_id'] ?? 0 );
                                if ( ! $id || isset( $seen[ $id ] ) ) {
                                        continue;
                                }
                                $seen[ $id ] = true;
                                $found[]     = $hit;
                        }
                }

                if ( ! $found ) {
                        // Index empty (semantic-search off, or a fresh install): fall back to
                        // the real-permalink suggestion helper.
                        $found = Haa_Content::internal_links( (int) ( $topic['post_id'] ?? 0 ), (string) ( $topic['keyword'] ?? '' ) );
                }

                // Category archives join the pool right after the two strongest hits:
                // an article about a product family should link to that family's hub
                // even when the index is full of single posts. Topic words decide.
                $cats = Haa_Content::category_link_hints(
                        trim( (string) ( $topic['keyword'] ?? '' ) . ' ' . (string) ( $topic['title'] ?? '' ) ),
                        2
                );
                if ( $cats ) {
                        array_splice( $found, min( 2, count( $found ) ), 0, $cats );
                }

                return $found;
        }

        /**
         * The first anchor phrase not used yet.
         *
         * @param string $title   Target title.
         * @param string $keyword Article keyword.
         * @param array  $used    Anchors already placed.
         * @return string
         */
        public static function pick_anchor( $title, $keyword, $used ) {
                foreach ( self::anchor_candidates( $title, $keyword ) as $one ) {
                        if ( ! isset( $used[ $one ] ) ) {
                                return $one;
                        }
                }
                return '';
        }

        /**
         * Find a safe place in the body to hang the anchor on.
         *
         * @param string $anchor Phrase to look for.
         * @param string $html   Body.
         * @return string The exact substring found, or '' when there is none.
         */
        private static function anchor_needle( $anchor, $html ) {
                $plain = wp_strip_all_tags( $html );
                // Prefer the anchor itself; else its first word, else nothing at all.
                foreach ( array( $anchor, preg_replace( '/\s.*$/u', '', $anchor ) ) as $try ) {
                        $try = trim( (string) $try );
                        if ( '' === $try ) {
                                continue;
                        }
                        if ( false !== mb_strpos( $plain, $try ) ) {
                                return $try;
                        }
                }
                return '';
        }

        /**
         * Wrap the first occurrence of $needle that is not already inside a link or
         * a heading, leaving the rest of the document untouched.
         *
         * @param string $html   Body.
         * @param string $needle Exact text to link.
         * @param string $url    Target URL.
         * @return string
         */
        private static function linkify( $html, $needle, $url ) {
                $parts = preg_split( '#(<a\b[^>]*>.*?</a>|<h[1-6][^>]*>.*?</h[1-6]>)#is', $html, -1, PREG_SPLIT_DELIM_CAPTURE );
                $link  = '<a href="' . esc_url( $url ) . '">' . esc_html( $needle ) . '</a>';

                foreach ( (array) $parts as $i => $seg ) {
                        if ( ! is_string( $seg ) || '' === $seg ) {
                                continue;
                        }
                        if ( 0 === strpos( $seg, '<a' ) || 0 === strpos( $seg, '<h' ) ) {
                                continue; // Skip existing links and headings.
                        }
                        $at = mb_strpos( $seg, $needle );
                        if ( false === $at ) {
                                continue;
                        }
                        $before = mb_substr( $seg, 0, $at );
                        $after  = mb_substr( $seg, $at + mb_strlen( $needle ) );
                        $parts[ $i ] = $before . $link . $after;
                        break; // One placement per link, then stop.
                }

                return implode( '', array_map( 'strval', (array) $parts ) );
        }

        /* ------------------------------------------------------------------
         * Claims
         * ---------------------------------------------------------------- */

        /**
         * Sentences that make a checkable claim.
         *
         * This is a flagging pass, not a truth pass. The plugin cannot verify a
         * statistic, and saying otherwise in the UI would be a lie — so the text
         * stays, and the human reviewing the draft sees exactly which sentences
         * assert a number, a date, a price or a superlative.
         *
         * @param string $html  Body.
         * @param array  $topic Planner row.
         * @param array  $art   Article settings.
         * @return array<int,array{claim:string,kind:string,supported:int}>
         */
        public static function flag_claims( $html, $topic, $art ) {
                if ( empty( $art['flag_claims'] ) ) {
                        return array();
                }

                // Patterns run against an ASCII-digit copy of the sentence: PCRE's \d does
                // not match ۰-۹ even in /u mode, and Persian text is full of them.
                $patterns = array(
                        'number'      => '/(?<!\d)\d{3,}(?![\d%])/',
                        'percent'     => '/\d{1,3}\s*(?:%|درصد)/u',
                        'price'       => '/\d[\d, ]*\s*(?:تومان|ریال|دلار|یورو|تومن)/u',
                        'date'        => '#(?:1[34][0-9][0-9]|20[0-2][0-9])(?:\s*(?:/|-|\.|سال)|\s*[۰-۹]{2})?#u',
                        'superlative' => '/بهترین|محبوب‌ترین|محبوب ترین|پرفروش‌ترین|رتبه\s*\d|تضمینی|قطعاً|بی‌رقیب|بدون رقیب|یکم/u',
                        'citation'    => '/مطابق\s*بر|طبق\s*گزارش|مطالعه|پژوهش|داده\s*های|سازمان|نظر\s*کارشناس/u',
                );

                // Block boundaries become newlines *before* stripping tags, otherwise
                // "</p><p>" glues two sentences into one and the flags lose their shape.
                $cut    = preg_replace( '#</(p|h[1-6]|li|div|blockquote)>#i', "\n", (string) $html );
                $sentences = preg_split( '/(?<=[.!?؟]\s)|\R{2,}|(?<=[.!?؟])\R/u', wp_strip_all_tags( $cut ) );
                $flags     = array();

                foreach ( (array) $sentences as $s ) {
                        $s = trim( (string) $s );
                        if ( '' === $s || mb_strlen( $s ) < 12 ) {
                                continue;
                        }

                        $probe = Haa_Util::to_ascii_digits( $s );

                        foreach ( $patterns as $kind => $re ) {
                                if ( ! preg_match( $re, $probe ) ) {
                                        continue;
                                }
                                $flags[] = array(
                                        'claim'     => Haa_Util::cut( $s, 220 ),
                                        'kind'      => (string) $kind,
                                        'supported' => self::supported_in_kb( $s ),
                                );
                                break;
                        }
                        if ( count( $flags ) >= 25 ) {
                                break;
                        }
                }

                return $flags;
        }

        /**
         * Does the site's own knowledge base back this sentence up?
         *
         * 1 = yes, 0 = nothing found, -1 = index unavailable. -1 must not be shown
         * as "false": absence of evidence is not evidence of absence.
         *
         * @param string $sentence Claim text.
         * @return int
         */
        private static function supported_in_kb( $sentence ) {
                $digits = preg_match_all( '/\d{2,}/', str_replace( array( '٬', ',', '،' ), '', self::to_latin_digits( $sentence ) ), $m ) ? (array) $m[0] : array();

                $hits = Haa_Search::search( Haa_Util::cut( $sentence, 120 ), 4, 1200 );
                if ( ! $hits ) {
                        return Haa_Settings::get( 'search.enabled', 0 ) ? 0 : -1;
                }

                if ( ! $digits ) {
                        return 1; // Qualitative claim, and the topic exists in our content.
                }
                foreach ( $hits as $h ) {
                        $body = self::to_latin_digits( (string) ( $h['content'] ?? '' ) );
                        foreach ( $digits as $d ) {
                                if ( false !== strpos( $body, (string) $d ) ) {
                                        return 1;
                                }
                        }
                }

                return 0;
        }

        /**
         * Persian/Arabic-Indic digits to ASCII, so "۱۴۰۴" matches "1404".
         *
         * Delegates to the one implementation the plugin already has.
         *
         * @param string $text Input.
         * @return string
         */
        public static function to_latin_digits( $text ) {
                return Haa_Util::to_ascii_digits( $text );
        }

        /* ------------------------------------------------------------------
         * Images
         * ---------------------------------------------------------------- */

        /**
         * Featured image plus the configured inline images.
         *
         * Text is never lost because a picture failed: the run continues and the
         * gate only reports that no image was produced.
         *
         * The HTML is returned, not stashed in an option — two runs in the same
         * queue tick would otherwise pick up each other's pictures.
         *
         * @param array  $topic   Planner row.
         * @param array  $meta    Meta pass result.
         * @param array  $payload Queue payload.
         * @param array  $art     Article settings.
         * @param int    $post_id Placeholder post id for attachment metadata.
         * @return array{thumb:int,inline:string}
         */
        private static function maybe_image( $topic, $meta, $payload, $art, $post_id = 0 ) {
                $none = array( 'thumb' => 0, 'inline' => '' );
                if ( empty( $payload['image'] ) && empty( $payload['with_image'] ) ) {
                        return $none;
                }

                $prompt = trim( (string) ( $meta['seo_title'] ?? $topic['title'] ) );
                $out    = $none;

                $made = Haa_Images::generate(
                        $prompt,
                        array(
                                'module'  => 'article-writer',
                                'size'    => (string) Haa_Settings::get( 'content.image_size', '1024x1024' ),
                                'style'   => (string) Haa_Settings::get( 'content.image_style', '' ),
                                'title'   => (string) $topic['title'],
                                'alt'     => (string) $topic['title'],
                                'post_id' => (int) $post_id,
                        )
                );
                if ( ! is_wp_error( $made ) && ! empty( $made['ok'] ) && ! empty( $made['attachment_id'] ) ) {
                        $out['thumb'] = (int) $made['attachment_id'];
                }

                // Inline images, hard-capped at 3; each one is a separate paid request.
                $extra = max( 0, min( 3, (int) ( $art['inline_images'] ?? 0 ) ) );
                for ( $i = 0; $i < $extra; $i++ ) {
                        $more = Haa_Images::generate(
                                $prompt . ' — ' . ( $i + 1 ),
                                array(
                                        'module'  => 'article-writer',
                                        'title'   => (string) $topic['title'],
                                        'alt'     => (string) $topic['title'],
                                        'post_id' => (int) $post_id,
                                )
                        );
                        if ( is_wp_error( $more ) || empty( $more['ok'] ) || empty( $more['attachment_id'] ) ) {
                                continue;
                        }
                        $img = wp_get_attachment_image( (int) $more['attachment_id'], 'large', false, array( 'loading' => 'lazy' ) );
                        if ( '' !== (string) $img ) {
                                $out['inline'] .= '<p>' . (string) $img . '</p>';
                        }
                }

                return $out;
        }

        /* ------------------------------------------------------------------
         * Gates
         * ---------------------------------------------------------------- */

        /**
         * Every check that decides whether this may go out unreviewed.
         *
         * @param array $ctx html/meta/topic/flags/state/art/has_img.
         * @return array<int,array{key:string,label:string,pass:bool,detail:string,hard:bool}>
         */
        public static function gates( $ctx ) {
                $art     = (array) $ctx['art'];
                $html    = (string) $ctx['html'];
                $meta    = (array) $ctx['meta'];
                $topic   = (array) $ctx['topic'];
                $flags   = (array) $ctx['flags'];
                $plain   = wp_strip_all_tags( $html );
                $words   = count( array_filter( preg_split( '/\s+/u', $plain ), function ( $w ) { return '' !== trim( (string) $w ); } ) );

                $target = max( 600, (int) ( $art['target_words'] ?? 1600 ) );
                $min    = (int) floor( $target * max( 30, (int) ( $art['gate_word_ratio'] ?? 70 ) ) / 100 );

                $out = array();

                $out[] = array(
                        'key'    => 'length',
                        'label'  => __( 'طول مقاله', 'hami-ai-assistant' ),
                        'pass'   => $words >= $min,
                        'detail' => sprintf( /* translators: 1: words, 2: minimum. */ __( '%1$d کلمه از %2$d حداقل', 'hami-ai-assistant' ), $words, $min ),
                        'hard'   => true,
                );

                $kw      = trim( (string) $topic['keyword'] );
                $in_head = ( '' !== $kw && ( false !== mb_stripos( (string) $meta['title'], $kw ) || false !== mb_stripos( Haa_Util::cut( $plain, 300 ), $kw ) ) );
                $out[]   = array(
                        'key'    => 'keyword',
                        'label'  => __( 'کلیدواژه در عنوان یا مقدمه', 'hami-ai-assistant' ),
                        'pass'   => ( '' === $kw ) || $in_head,
                        'detail' => $in_head || '' === $kw ? __( 'دیده شد', 'hami-ai-assistant' ) : __( 'نه در عنوان، نه در ۳۰۰ نویسه‌ی نخست', 'hami-ai-assistant' ),
                        'hard'   => true,
                );

                $empty = 0;
                foreach ( (array) ( $ctx['state']['sections'] ?? array() ) as $sec ) {
                        if ( '' === trim( (string) ( $sec['body'] ?? '' ) ) ) {
                                $empty++;
                        }
                }
                $out[] = array(
                        'key'    => 'sections',
                        'label'  => __( 'بخش‌های کامل', 'hami-ai-assistant' ),
                        'pass'   => 0 === $empty,
                        'detail' => $empty ? sprintf( /* translators: %d: count. */ __( '%d بخش خالی است', 'hami-ai-assistant' ), $empty ) : __( 'همه‌ی بخش‌ها متن دارند', 'hami-ai-assistant' ),
                        'hard'   => true,
                );

                $dup = Haa_Planner::competition( (string) $meta['title'], (string) $topic['keyword'] );
                $out[] = array(
                        'key'    => 'duplicate',
                        'label'  => __( 'تکرار با محتوای خود سایت', 'hami-ai-assistant' ),
                        'pass'   => empty( $dup['duplicate'] ),
                        'detail' => empty( $dup['duplicate'] )
                                ? __( 'شبیه هیچ محتوای موجود نیست', 'hami-ai-assistant' )
                                : sprintf( /* translators: 1: score, 2: title. */ __( 'شباهت %1$s با «%2$s»', 'hami-ai-assistant' ), (string) $dup['score'], (string) $dup['title'] ),
                        'hard'   => true,
                );

                $forbidden = self::forbidden_hits( $plain );
                $out[]     = array(
                        'key'    => 'claims',
                        'label'  => __( 'ادعای ممنوعه', 'hami-ai-assistant' ),
                        'pass'   => ! $forbidden,
                        'detail' => $forbidden
                                ? sprintf( /* translators: %s: phrases. */ __( 'این‌ها در متن هست: %s', 'hami-ai-assistant' ), implode( '، ', $forbidden ) )
                                : __( 'چیزی از فهرست ممنوعه پیدا نشد', 'hami-ai-assistant' ),
                        'hard'   => true,
                );

                // 0 means "looked in the index and found nothing" — that must hold a
                // claim back. -1 means there is no index to look in: absence of evidence
                // is not evidence of absence, so it stays informational only.
                $unbacked = array_values( array_filter( $flags, function ( $f ) { return 0 === (int) ( $f['supported'] ?? -1 ); } ) );
                $out[]    = array(
                        'key'    => 'facts',
                        'label'  => __( 'ادعاهای قابل بررسی', 'hami-ai-assistant' ),
                        'pass'   => ! $unbacked,
                        'detail' => ! $flags
                                ? __( 'متن ادعای عددی/تاریخی ندارد', 'hami-ai-assistant' )
                                : ( $unbacked
                                        ? sprintf( /* translators: %d: count. */ __( '%d ادعا بدون پشتوانه در پایگاه دانش — حذف نشده‌اند، باید دستی بررسی شوند', 'hami-ai-assistant' ), count( $unbacked ) )
                                        : __( 'همه در پایگاه دانش پشتیبان دارند یا پایگاه دانش فعال نیست', 'hami-ai-assistant' ) ),
                        'hard'   => true,
                );

                $similar = self::structure_similarity( $ctx['state'], $meta );
                $out[]   = array(
                        'key'    => 'structure',
                        'label'  => __( 'ساختار شبیه رقیب', 'hami-ai-assistant' ),
                        'pass'   => $similar < 0.6,
                        'detail' => sprintf( /* translators: %s: ratio. */ __( 'هم‌پوشانی سرفصل‌ها: %s', 'hami-ai-assistant' ), (string) round( $similar, 2 ) ),
                        // A soft gate: copying structure is worth a warning, and blocks
                        // auto-publish, but a human may still reasonably approve it.
                        'hard'   => false,
                );

                $out[] = array(
                        'key'    => 'image',
                        'label'  => __( 'تصویر', 'hami-ai-assistant' ),
                        'pass'   => true,
                        'detail' => empty( $ctx['has_img'] )
                                ? __( 'بدون تصویر شاخص (متن سالم است)', 'hami-ai-assistant' )
                                : __( 'تصویر شاخص ساخته شد', 'hami-ai-assistant' ),
                        'hard'   => false,
                );

                return $out;
        }

        /**
         * Phrases from the profile's forbidden list that appear in the text.
         *
         * @param string $plain Plain-text body.
         * @return array<int,string>
         */
        public static function forbidden_hits( $plain ) {
                $raw  = (string) Haa_Settings::get( 'profile.forbidden', '' );
                $hits = array();
                foreach ( preg_split( '/\R+/u', $raw ) as $line ) {
                        $line = trim( wp_strip_all_tags( $line ) );
                        $line = preg_replace( '/^([-*•]\s*)|(?:نباید|ممنوع|کلمات ممنوعه[:：]\s*)/u', '', $line );
                        $line = trim( (string) $line );
                        if ( '' === $line || mb_strlen( $line ) < 3 ) {
                                continue;
                        }
                        if ( false !== mb_stripos( $plain, $line ) ) {
                                $hits[] = $line;
                        }
                }
                return $hits;
        }

        /**
         * Overlap between our outline headings and the competitor snapshot.
         *
         * @param array $state Pipeline state.
         * @param array $meta  Meta pass.
         * @return float 0..1
         */
        private static function structure_similarity( $state, $meta ) {
                $snap = Haa_Research::snapshot();
                if ( empty( $snap['headings'] ) ) {
                        return 0.0;
                }

                $ours = array();
                foreach ( (array) ( $state['sections'] ?? array() ) as $sec ) {
                        $ours = array_merge( $ours, (array) Haa_Search::tokenize( (string) ( $sec['h2'] ?? '' ) ) );
                }
                if ( ! $ours ) {
                        return 0.0;
                }

                $best = 0.0;
                foreach ( (array) $snap['headings'] as $h ) {
                        $theirs = (array) Haa_Search::tokenize( (string) $h );
                        if ( ! $theirs ) {
                                continue;
                        }
                        $inter = count( array_intersect( array_map( 'strval', $ours ), array_map( 'strval', $theirs ) ) );
                        $union = count( array_unique( array_merge( array_map( 'strval', $ours ), array_map( 'strval', $theirs ) ) ) );
                        $score = $union ? $inter / $union : 0.0;
                        if ( $score > $best ) {
                                $best = $score;
                        }
                }

                return $best;
        }

        /**
         * @param array $gates Gate rows.
         * @return bool True when every hard gate passed.
         */
        public static function gates_clean( $gates ) {
                foreach ( (array) $gates as $g ) {
                        if ( ! empty( $g['hard'] ) && empty( $g['pass'] ) ) {
                                return false;
                        }
                }
                return true;
        }

        /* ------------------------------------------------------------------
         * Store + publish
         * ---------------------------------------------------------------- */

        /**
         * Create or reuse the draft post.
         *
         * @param array  $topic   Planner row.
         * @param array  $meta    Meta pass.
         * @param string $html    Body.
         * @param array  $flags   Claim flags.
         * @param array  $gates   Gate rows.
         * @param array  $imgs    array(thumb,inline) from maybe_image().
         * @param array  $payload Queue payload.
         * @return int|WP_Error
         */
        private static function store( $topic, $meta, $html, $flags, $gates, $imgs, $payload ) {
                if ( '' !== trim( (string) ( $imgs['inline'] ?? '' ) ) ) {
                        $html .= "\n" . $imgs['inline'];
                }

                $author = (int) ( $payload['author'] ?? Haa_Settings::get( 'article.default_author', 0 ) );
                if ( ! $author ) {
                        $author = get_current_user_id();
                }

                $cat = isset( $payload['category'] ) ? (int) $payload['category'] : 0;

                $prepared = array(
                        'post_title'    => (string) $meta['title'],
                        'post_name'     => (string) ( $meta['slug'] ?: Haa_Util::slugify( (string) $meta['title'] ) ),
                        'post_content'  => (string) $html,
                        'post_excerpt'  => (string) ( $meta['excerpt'] ?: Haa_Util::cut( wp_strip_all_tags( $html ), 160 ) ),
                        'post_status'   => 'draft',
                        'post_type'     => 'post',
                        'post_author'   => $author,
                        'post_category' => $cat ? array( $cat ) : array(),
                );

                $post_id = (int) ( $topic['post_id'] ?? 0 );
                if ( $post_id && get_post( $post_id ) ) {
                        // Re-running a topic updates its draft, and WP records a revision —
                        // the admin can always roll back to the earlier version.
                        $done = wp_update_post( array_merge( $prepared, array( 'ID' => $post_id ) ), true );
                } else {
                        $done = wp_insert_post( $prepared, true );
                }

                if ( is_wp_error( $done ) ) {
                        return $done;
                }

                $post_id = (int) $done;

                update_post_meta( $post_id, '_haai_generated', '1' );
                update_post_meta( $post_id, '_haai_topic_id', (int) $topic['id'] );
                update_post_meta( $post_id, '_haai_flags', array_slice( $flags, 0, 25 ) );
                update_post_meta( $post_id, '_haai_gates', $gates );

                if ( ! empty( $imgs['thumb'] ) ) {
                        set_post_thumbnail( $post_id, (int) $imgs['thumb'] );
                }

                Haa_Seo::apply(
                        $post_id,
                        array(
                                'html'           => $html,
                                'seo_title'      => (string) $meta['seo_title'],
                                'seo_description' => (string) $meta['seo_description'],
                                'slug'           => (string) $meta['slug'],
                                'excerpt'        => (string) $meta['excerpt'],
                        )
                );

                return $post_id;
        }

        /**
         * Apply the publish mode, demoting to pending review when anything failed.
         *
         * Public because this is the only place that turns a machine verdict into a
         * post status, and that decision needs to be testable on its own.
         *
         * @param int   $post_id Post.
         * @param array $meta    Meta pass.
         * @param array $gates   Gate rows.
         * @param array $payload Queue payload.
         * @param array $art     Article settings.
         * @return string Final post status.
         */
        public static function settle( $post_id, $meta, $gates, $payload, $art ) {
                $mode = (string) ( $payload['publish_mode'] ?? ( $art['publish_mode'] ?? 'review' ) );
                $clean = self::gates_clean( $gates );

                // Scheduling wins over mode: a future-dated post is a future-dated post.
                $when = trim( (string) ( $payload['publish_at'] ?? '' ) );
                if ( '' !== $when && $clean ) {
                        $ts = strtotime( $when );
                        if ( $ts && $ts > time() ) {
                                wp_update_post(
                                        array(
                                                'ID'          => $post_id,
                                                'post_status' => 'future',
                                                'post_date'   => gmdate( 'Y-m-d H:i:s', $ts ),
                                        )
                                );
                                return 'future';
                        }
                }

                if ( 'auto' === $mode ) {
                        if ( ! $clean ) {
                                // Not "failed": the article is good, only unverified. A human
                                // decides, and the gate list tells them which part.
                                wp_update_post( array( 'ID' => $post_id, 'post_status' => 'pending' ) );
                                return 'pending';
                        }
                        if ( ! self::room_for_auto() ) {
                                wp_update_post( array( 'ID' => $post_id, 'post_status' => 'pending' ) );
                                return 'pending';
                        }
                        wp_update_post( array( 'ID' => $post_id, 'post_status' => 'publish' ) );
                        return 'publish';
                }

                if ( 'draft' === $mode ) {
                        return 'draft';
                }

                wp_update_post( array( 'ID' => $post_id, 'post_status' => 'pending' ) );
                return 'pending';
        }

        /**
         * Daily article ceiling, counted across every module that writes posts.
         *
         * @return bool
         */
        private static function room_for_auto() {
                $cap = (int) Haa_Settings::get( 'article.daily_article_cap', 5 );
                if ( $cap <= 0 ) {
                        return true;
                }
                return Haa_Usage::generated_posts_today() < $cap;
        }

        /* ------------------------------------------------------------------
         * State persistence (resumability)
         * ---------------------------------------------------------------- */

        /**
         * @param array $topic Row.
         * @return array
         */
        public static function state_get( $topic ) {
                $n = (array) ( $topic['notes'] ?? array() );
                return array(
                        'outline'  => (array) ( $n['outline'] ?? array() ),
                        'sections' => isset( $n['sections'] ) && is_array( $n['sections'] ) ? $n['sections'] : array(),
                        'intro'    => (string) ( $n['intro'] ?? '' ),
                );
        }

        /**
         * Persist progress. Written after every stage on purpose.
         *
         * @param int    $topic_id Row.
         * @param array  $state    State.
         * @param string $stage    Stage name.
         * @return void
         */
        public static function state_save( $topic_id, $state, $stage ) {
                Haa_Planner::update(
                        $topic_id,
                        array(
                                'notes' => $state,
                                'stage' => $stage,
                        )
                );
        }

        /**
         * @param int        $topic_id Row.
         * @param WP_Error|string $err      Reason.
         * @return void
         */
        private static function fail( $topic_id, $err ) {
                Haa_Planner::update(
                        $topic_id,
                        array(
                                'stage' => 'failed',
                                'error' => is_wp_error( $err ) ? (string) $err->get_error_message() : (string) $err,
                        )
                );
        }

        /* ------------------------------------------------------------------
         * Review helpers
         * ---------------------------------------------------------------- */

        /**
         * Rewrite exactly one section, keeping the rest.
         *
         * @param int $topic_id Row.
         * @param int $index    Section index.
         * @return true|WP_Error
         */
        public static function regenerate_section( $topic_id, $index ) {
                $topic = Haa_Planner::row( $topic_id );
                if ( ! $topic ) {
                        return new WP_Error( 'haai_pipe_topic', __( 'موضوع پیدا نشد.', 'hami-ai-assistant' ) );
                }
                $state = self::state_get( $topic );
                $sec   = isset( $state['sections'][ $index ] ) ? $state['sections'][ $index ] : null;
                $out   = isset( $state['outline'][ $index ] ) ? $state['outline'][ $index ] : null;
                if ( ! $sec && ! $out ) {
                        return new WP_Error( 'haai_pipe_section', __( 'چنین بخشی وجود ندارد.', 'hami-ai-assistant' ) );
                }

                $art = (array) Haa_Settings::get( 'article', array() );
                $body = self::write_section(
                        $topic,
                        $out ? $out : array( 'h2' => (string) $sec['h2'], 'h3' => array(), 'focus' => '' ),
                        array(),
                        $art,
                        array()
                );
                if ( is_wp_error( $body ) ) {
                        return $body;
                }

                $state['sections'][ $index ] = array(
                        'h2'   => (string) ( $sec['h2'] ?? $out['h2'] ?? '' ),
                        'body' => $body,
                );
                self::state_save( $topic_id, $state, 'writing' );

                $html   = self::assemble( $topic, $state );
                $flags  = self::flag_claims( $html, $topic, $art );
                $post_id = (int) $topic['post_id'];
                if ( $post_id ) {
                        // wp_update_post creates a revision, so the old section is recoverable.
                        wp_update_post(
                                array(
                                        'ID'           => $post_id,
                                        'post_content' => $html,
                                )
                        );
                        update_post_meta( $post_id, '_haai_flags', $flags );
                }

                return true;
        }

        /**
         * Approve a reviewed draft into its final status.
         *
         * @param int    $post_id Post.
         * @param string $status  publish|draft|pending|future.
         * @param string $when    Optional scheduled time.
         * @return true|WP_Error
         */
        public static function approve( $post_id, $status = 'publish', $when = '' ) {
                $post = get_post( $post_id );
                if ( ! $post ) {
                        return new WP_Error( 'haai_pipe_post', __( 'نوشته پیدا نشد.', 'hami-ai-assistant' ) );
                }
                if ( ! in_array( (string) $status, array( 'publish', 'draft', 'pending', 'future' ), true ) ) {
                        return new WP_Error( 'haai_pipe_status', __( 'وضعیت نامعتبر است.', 'hami-ai-assistant' ) );
                }

                $args = array(
                        'ID'          => (int) $post_id,
                        'post_status' => (string) $status,
                );
                if ( 'future' === $status ) {
                        $ts = strtotime( (string) $when );
                        if ( ! $ts || $ts <= time() ) {
                                return new WP_Error( 'haai_pipe_when', __( 'زمان انتشار معتبر نیست.', 'hami-ai-assistant' ) );
                        }
                        $args['post_date'] = gmdate( 'Y-m-d H:i:s', $ts );
                }

                $done = wp_update_post( $args, true );
                if ( is_wp_error( $done ) ) {
                        return $done;
                }

                if ( 'publish' === $status ) {
                        $topic_id = (int) get_post_meta( $post_id, '_haai_topic_id', true );
                        if ( $topic_id ) {
                                Haa_Planner::update( $topic_id, array( 'status' => 'published', 'stage' => 'published' ) );
                                Haa_Planner::mark_published( $topic_id, (int) $post_id );
                        }
                }

                return true;
        }

        /**
         * Reject a draft back to the planner.
         *
         * @param int $post_id Post.
         * @return true|WP_Error
         */
        public static function reject( $post_id ) {
                $topic_id = (int) get_post_meta( $post_id, '_haai_topic_id', true );
                if ( ! $topic_id || ! Haa_Planner::row( $topic_id ) ) {
                        return new WP_Error( 'haai_pipe_topic', __( 'موضوع مرتبط پیدا نشد.', 'hami-ai-assistant' ) );
                }
                Haa_Planner::update( $topic_id, array( 'status' => 'rejected', 'stage' => 'failed', 'error' => __( 'توسط مدیر رد شد.', 'hami-ai-assistant' ) ) );
                wp_update_post( array( 'ID' => (int) $post_id, 'post_status' => 'draft' ) );
                return true;
        }

        /**
         * Rows waiting for a human, newest first.
         *
         * @return array<int,array>
         */
        public static function pending_review() {
                $out = array();
                foreach ( Haa_Planner::rows( '', 50 ) as $row ) {
                        if ( 'review' !== (string) $row['stage'] || ! (int) $row['post_id'] ) {
                                continue;
                        }
                        $post = get_post( (int) $row['post_id'] );
                        if ( $post ) {
                                $row['post'] = $post;
                                $out[]       = $row;
                        }
                }
                return $out;
        }

        /**
         * Backlinks worth adding to older posts — recorded, never applied.
         *
         * @param int   $post_id New post.
         * @param array $topic   Planner row.
         * @param array $state   Pipeline state.
         * @return void
         */
        public static function suggest_backlinks( $post_id, $topic, $state ) {
                $targets = array();
                foreach ( (array) ( $state['sections'] ?? array() ) as $sec ) {
                        foreach ( Haa_Search::search( (string) ( $sec['h2'] ?? '' ), 3, 200 ) as $hit ) {
                                $id = (int) ( $hit['post_id'] ?? 0 );
                                if ( $id && $id !== $post_id && ! isset( $targets[ $id ] ) ) {
                                        $targets[ $id ] = array(
                                                'post_id' => $id,
                                                'title'   => (string) ( $hit['title'] ?? '' ),
                                                'anchor'  => Haa_Util::cut( (string) ( $topic['keyword'] ?? $topic['title'] ), 60 ),
                                                'where'   => Haa_Util::cut( (string) ( $sec['h2'] ?? '' ), 80 ),
                                                'url'     => (string) get_permalink( $post_id ),
                                        );
                                }
                        }
                }
                update_post_meta( $post_id, '_haai_backlinks', array_slice( array_values( $targets ), 0, 8 ) );
        }

        /**
         * Apply one approved backlink suggestion to the older post.
         *
         * Only ever reached from a human click, so the plugin never rewrites an
         * existing published post on its own.
         *
         * @param int $old_post_id Post to add the link to.
         * @param int $new_post_id Post to link toward.
         * @return true|WP_Error
         */
        public static function apply_backlink( $old_post_id, $new_post_id ) {
                $old = get_post( (int) $old_post_id );
                if ( ! $old ) {
                        return new WP_Error( 'haai_pipe_old', __( 'نوشته‌ی مقصد پیدا نشد.', 'hami-ai-assistant' ) );
                }

                $already = (string) get_permalink( (int) $new_post_id );
                if ( false !== strpos( (string) $old->post_content, $already ) ) {
                        return new WP_Error( 'haai_pipe_dup', __( 'این لینک از قبل وجود دارد.', 'hami-ai-assistant' ) );
                }

                $topic = (string) get_the_title( (int) $new_post_id );
                $add   = "\n<p><a href=\"" . esc_url( $already ) . '\">' . esc_html( $topic ) . '</a></p>';

                // Updating through wp_update_post records a revision, so this is undoable.
                $done = wp_update_post(
                        array(
                                'ID'           => (int) $old_post_id,
                                'post_content' => (string) $old->post_content . $add,
                        ),
                        true
                );

                return is_wp_error( $done ) ? $done : true;
        }

        /**
         * Human summary of what the gates decided.
         *
         * @param array $gates Rows.
         * @return string
         */
        public static function gates_summary( $gates ) {
                $bad = array();
                foreach ( (array) $gates as $g ) {
                        if ( empty( $g['pass'] ) && ! empty( $g['hard'] ) ) {
                                $bad[] = (string) $g['label'];
                        }
                }
                if ( ! $bad ) {
                        return __( 'همه‌ی بررسی‌های سخت سبز است.', 'hami-ai-assistant' );
                }
                return sprintf( /* translators: %s: labels. */ __( 'رد شده از سوی: %s', 'hami-ai-assistant' ), implode( '، ', $bad ) );
        }
}
