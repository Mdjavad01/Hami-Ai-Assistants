<?php
/**
 * Video → article module (مرحله D4).
 *
 * Built around one practical fact: pulling captions out of YouTube is unreliable
 * (endpoints change, hosts in Iran are blocked, and much of it is against the
 * platform's terms). So the *main* path is a transcript the admin supplies —
 * exported from YouTube Studio, from the editor, or any SRT/VTT file — and the
 * network fetch is an opt-in best effort that says plainly when it fails.
 *
 * The output is an original article organised around what was said, with the
 * video cited. A transcript is not "content the model may reuse": dumping it into
 * the post would just republish somebody's speech with bad punctuation.
 *
 * @package Hami_AI_Assistant
 */

if ( ! defined( 'ABSPATH' ) ) {
        exit;
}

final class Haa_Module_Video_Article implements Haa_Module_Interface {

        /** Transcript characters handed to the model in one pass. */
        const CONTEXT_CHARS = 14000;

        /** Upload ceiling for a transcript file. */
        const MAX_UPLOAD = 2000000;

        /** @return string */
        public function slug() { return 'video-article'; }

        /** @return string */
        public function label() { return __( 'ویدیو به مقاله', 'hami-ai-assistant' ); }

        /** @return string */
        public function description() { return __( 'از ترنسکریپت SRT/VTT یک مقاله‌ی ساختارمند می‌سازد و ویدیو را ارجاع می‌دهد.', 'hami-ai-assistant' ); }

        /** @return bool */
        public function requires_woocommerce() { return Haa_Modules::requires_woocommerce( $this->slug() ); }

        /** @return void */
        public function register() {
                add_action( 'haai_settings_sections', array( $this, 'register_section' ), 10 );
                add_action( 'haai_settings_fields_video', array( $this, 'register_fields' ) );
                add_action( 'haai_admin_menus', array( $this, 'register_menu' ) );

                add_action( 'admin_post_haai_video_make', array( $this, 'handle_make' ) );
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
                $sections['video'] = __( 'ویدیو به مقاله', 'hami-ai-assistant' );
                return $sections;
        }

        /**
         * @param array $fields Fields so far.
         * @return array
         */
        public function register_fields( $fields ) {
                $fields[] = array( 'key' => 'video.enabled', 'type' => 'checkbox', 'label' => __( 'این ماژول استفاده شود', 'hami-ai-assistant' ) );
                $fields[] = array( 'key' => 'video.min_chars', 'type' => 'number', 'label' => __( 'حداقل طول ترنسکریپت (نویسه)', 'hami-ai-assistant' ), 'min' => 200, 'max' => 20000, 'hint' => __( 'زیر این مقدار، مقاله از یک ویدیوی کوتاه ساخته نمی‌شود.', 'hami-ai-assistant' ) );
                $fields[] = array( 'key' => 'video.max_words', 'type' => 'number', 'label' => __( 'طول هدف مقاله (کلمه)', 'hami-ai-assistant' ), 'min' => 300, 'max' => 3000 );
                $fields[] = array( 'key' => 'video.default_status', 'type' => 'select', 'label' => __( 'وضعیت پیش‌فرض', 'hami-ai-assistant' ), 'choices' => array( 'draft' => __( 'پیش‌نویس', 'hami-ai-assistant' ), 'pending' => __( 'در انتظار بررسی', 'hami-ai-assistant' ) ) );
                $fields[] = array( 'key' => 'video.default_category', 'type' => 'number', 'label' => __( 'دسته‌ی پیش‌فرض (۰ = بدون دسته)', 'hami-ai-assistant' ), 'min' => 0, 'max' => 999999 );
                $fields[] = array( 'key' => 'video.fetch_captions', 'type' => 'checkbox', 'label' => __( 'تلاش برای گرفتن خودکار زیرنویس', 'hami-ai-assistant' ), 'hint' => __( 'اختیاری و بی‌ثبات. اگر کار نکند پیام روشن می‌گیرید؛ راه مطمئن، آپلود فایل ترنسکریپت است.', 'hami-ai-assistant' ) );
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
                        __( 'ویدیو به مقاله', 'hami-ai-assistant' ),
                        __( 'ویدیو به مقاله', 'hami-ai-assistant' ),
                        'manage_options',
                        Haa_Util::screen( 'video-article' ),
                        array( $this, 'render_screen' )
                );
        }

        /* ------------------------------------------------------------------
         * Config
         * ---------------------------------------------------------------- */

        /** @return array */
        private static function cfg() {
                $s = Haa_Settings::get();
                return isset( $s['video'] ) && is_array( $s['video'] ) ? $s['video'] : array();
        }

        /** @return bool */
        public static function enabled() {
                return ! empty( self::cfg()['enabled'] );
        }

        /* ------------------------------------------------------------------
         * Transcript parsing
         * ---------------------------------------------------------------- */

        /**
         * SRT or VTT into flowing text.
         *
         * Drops the counter lines, the timecodes, VTT's header/NOTE/STYLE blocks and
         * the inline <c>/<v> markup; then folds the stutter that auto-captions produce
         * (the same words repeating as the caption is rewritten).
         *
         * @param string $raw File contents.
         * @return string
         */
        public static function parse_transcript( $raw ) {
                $text = (string) $raw;
                if ( '' === trim( $text ) ) {
                        return '';
                }

                // Strip a UTF-8 BOM and normalise line endings.
                $text = preg_replace( '/^\xEF\xBB\xBF/', '', $text );
                $text = str_replace( array( "\r\n", "\r" ), "\n", $text );

                // VTT headers and cue timings.
                $text = (string) preg_replace( '/^WEBVTT.*$/mi', '', $text );
                $text = (string) preg_replace( '/^.*?-->.*$/mi', '', $text );

                // VTT blocks that are metadata, not speech: NOTE / STYLE / REGION / CUE.
                // Cut block-by-block on blank lines — a lazy .*? with $ under /m stops at
                // the first line end and leaves the comment text behind as "speech".
                $blocks = preg_split( '/\n\s*\n/', $text );
                $keep   = array();
                foreach ( (array) $blocks as $block ) {
                        if ( preg_match( '/^\s*(?:NOTE|STYLE|REGION|CUE)\b/i', (string) $block ) ) {
                                continue;
                        }
                        $keep[] = (string) $block;
                }
                $text = implode( "\n\n", $keep );

                // SRT counter lines on their own line.
                $text = (string) preg_replace( '/^\d{1,6}\s*$/m', '', $text );

                // Inline markup SRT/VTT allow: <c.xx>, <v Speaker>, </i>, {\an8}…
                $text = (string) preg_replace( '#</?(?:c|v|rb|rt|rp|i|b|u)(?:\.[^>\s]*)?(?:\s[^>]*)?>#i', '', $text );
                $text = (string) preg_replace( '/\{\\\[^}]*\}/', '', $text );
                $text = html_entity_decode( $text, ENT_QUOTES, 'UTF-8' );

                $lines = preg_split( '/\n+/', (string) $text );
                $out   = array();
                $last  = '';
                foreach ( (array) $lines as $line ) {
                        $line = trim( preg_replace( '/\s+/u', ' ', (string) $line ) );
                        if ( '' === $line ) {
                                continue;
                        }
                        // Auto-caption stutter: this line is contained in the previous one.
                        if ( '' !== $last ) {
                                if ( 0 === strcasecmp( $line, $last ) ) {
                                        continue;
                                }
                                $tail = mb_substr( $last, -mb_strlen( $line ) );
                                if ( mb_strlen( $line ) >= 3 && 0 === strcasecmp( $line, $tail ) ) {
                                        continue;
                                }
                        }
                        $out[] = $line;
                        $last  = $line;
                }

                return trim( implode( ' ', $out ) );
        }

        /**
         * Is this a plausible YouTube/Vimeo URL we are willing to cite?
         *
         * @param string $url Candidate.
         * @return string Sanitised URL or ''.
         */
        public static function video_url( $url ) {
                $clean = Haa_Settings::sanitize_url( (string) $url );
                if ( '' === $clean ) {
                        return '';
                }
                $host = (string) wp_parse_url( $clean, PHP_URL_HOST );
                $ok   = array( 'youtube.com', 'www.youtube.com', 'youtu.be', 'm.youtube.com', 'youtube-nocookie.com', 'vimeo.com', 'player.vimeo.com' );
                foreach ( $ok as $allowed ) {
                        if ( 0 === strcasecmp( $host, $allowed ) ) {
                                return $clean;
                        }
                }
                return '';
        }

        /**
         * Best-effort public caption fetch. Opt-in, and honest when it fails.
         *
         * @param string $url Video URL.
         * @return string|WP_Error Transcript text or the reason it could not be had.
         */
        public static function fetch_captions( $url ) {
                if ( empty( self::cfg()['fetch_captions'] ) ) {
                        return new WP_Error( 'haai_video_off', __( 'گرفتن خودکار زیرنویس در تنظیمات خاموش است. فایل ترنسکریپت را خودتان بفرستید.', 'hami-ai-assistant' ) );
                }

                $video = self::video_url( $url );
                if ( '' === $video ) {
                        return new WP_Error( 'haai_video_url', __( 'آدرس ویدیو شناخته‌شده نیست.', 'hami-ai-assistant' ) );
                }

                // No hard-coded track endpoint: those change without notice and are often
                // blocked. Ask the page for a <track src> and follow only that, through the
                // same SSRF-checked door the crawler uses.
                $html = Haa_Research::get( $video );
                if ( is_wp_error( $html ) ) {
                        return new WP_Error( 'haai_video_page', __( 'صفحه‌ی ویدیو گرفته نشد: ', 'hami-ai-assistant' ) . $html->get_error_message() );
                }

                if ( ! preg_match( '#<track[^>]*src=["\']([^"\']+)["\']#i', (string) $html, $m ) ) {
                        return new WP_Error( 'haai_video_notrack', __( 'زیرنویس عمومی پیدا نشد؛ راه مطمئن، آپلود فایل است.', 'hami-ai-assistant' ) );
                }

                $track = Haa_Settings::sanitize_url( html_entity_decode( (string) $m[1], ENT_QUOTES, 'UTF-8' ) );
                if ( '' === $track ) {
                        return new WP_Error( 'haai_video_badtrack', __( 'آدرس زیرنویس نامعتبر بود.', 'hami-ai-assistant' ) );
                }

                $body = Haa_Research::get( $track, array( 'accept' => 'text/vtt,text/plain;q=0.9,*/*;q=0.4' ) );
                if ( is_wp_error( $body ) ) {
                        return new WP_Error( 'haai_video_fetch', __( 'زیرنویس گرفته نشد: ', 'hami-ai-assistant' ) . $body->get_error_message() );
                }

                $text = self::parse_transcript( (string) $body );
                if ( '' === $text ) {
                        return new WP_Error( 'haai_video_empty', __( 'زیرنویس خالی بود.', 'hami-ai-assistant' ) );
                }

                return $text;
        }

        /* ------------------------------------------------------------------
         * Generation
         * ---------------------------------------------------------------- */

        /**
         * Turn a transcript into a structured draft article.
         *
         * Two calls: the piece itself, then SEO meta. The transcript is clipped to a
         * fixed budget so a four-hour lecture cannot silently double the invoice.
         *
         * @param array $args title, url, transcript.
         * @return array|WP_Error Post id, status, and what was skipped.
         */
        public static function write( $args ) {
                $cfg       = self::cfg();
                $title     = trim( wp_strip_all_tags( (string) ( $args['title'] ?? '' ) ) );
                $transcript = self::parse_transcript( (string) ( $args['transcript'] ?? '' ) );
                $url       = self::video_url( (string) ( $args['url'] ?? '' ) );

                if ( '' === $title ) {
                        return new WP_Error( 'haai_video_title', __( 'عنوان ویدیو را بنویسید.', 'hami-ai-assistant' ) );
                }

                $min = max( 200, (int) ( $cfg['min_chars'] ?? 800 ) );
                if ( mb_strlen( $transcript ) < $min ) {
                        return new WP_Error(
                                'haai_video_short',
                                sprintf( /* translators: 1: have, 2: need. */ __( 'ترنسکریپت کوتاه است (%1$d نویسه؛ حداقل %2$d). فایل SRT/VTT کامل را بفرستید.', 'hami-ai-assistant' ), mb_strlen( $transcript ), $min )
                        );
                }

                $target = max( 300, min( 3000, (int) ( $cfg['max_words'] ?? 1200 ) ) );

                $system = __( 'نویسنده‌ی محتوای فارسی. از متن گفت‌وگوی یک ویدیو، یک مقاله‌ی مرتب و خوانا می‌سازی.', 'hami-ai-assistant' ) . "\n"
                        . __( 'خروجی فقط HTML معتبر: یک <p> مقدمه، سپس برای هر موضوع یک <h2> با <p> (و در صورت نیاز <h3> و <ul>)، و یک جمع‌بندی کوتاه. بدون h1، بدون کد فنس.', 'hami-ai-assistant' ) . "\n"
                        . Haa_Content::style_suffix( array( 'topic' => $title, 'keywords' => $title ) ) . "\n"
                        . '- ' . sprintf( /* translators: %d: words. */ __( 'حدود %d کلمه.', 'hami-ai-assistant' ), $target ) . "\n"
                        . '- ' . __( 'گفت‌وگوی محاوره‌ای را به نوشتار درست برگردان؛ ولی حرف‌های اضافه و تکرار را دور بریز.', 'hami-ai-assistant' ) . "\n"
                        . '- ' . __( 'ساختار: موضوع‌محور، نه زمان‌محور. «دقیقه‌ی دوم» و «ادامه‌ی صحبت گوینده» ننویس.', 'hami-ai-assistant' ) . "\n"
                        . '- ' . __( 'ترنسکریپت را عیناً کپی نکن؛ بازنویسی و خلاصه‌سازی با کلمه‌های خودت باشد.', 'hami-ai-assistant' ) . "\n"
                        . Haa_Content::no_fabrication();

                $user = __( 'عنوان ویدیو:', 'hami-ai-assistant' ) . ' ' . $title . "\n\n"
                        . __( 'متن گفت‌وگو:', 'hami-ai-assistant' ) . "\n"
                        . Haa_Util::cut( $transcript, self::CONTEXT_CHARS )
                        . "\n\n" . __( 'حالا مقاله را بنویس.', 'hami-ai-assistant' );

                $body = Haa_Content::generate( $system, $user, array( 'module' => 'video-article', 'operation' => 'video' ) );
                if ( is_wp_error( $body ) ) {
                        return $body;
                }

                $html = Haa_Content::to_html( (string) $body );
                if ( '' === trim( wp_strip_all_tags( $html ) ) ) {
                        return new WP_Error( 'haai_video_empty', __( 'مدل متن خالی برگرداند.', 'hami-ai-assistant' ) );
                }

                // Citation, always, when we know where the video is.
                if ( '' !== $url ) {
                        $html .= "\n<p class=\"haai-src\">"
                                . sprintf(
                                        /* translators: %s: video link. */
                                        esc_html__( 'ویدیوی اصلی: %s', 'hami-ai-assistant' ),
                                        '<a href="' . esc_url( $url ) . '" rel="nofollow noopener" target="_blank">' . esc_html( $title ) . '</a>'
                                )
                                . '</p>';
                }

                $meta = Haa_Pipeline::write_meta(
                        array( 'title' => $title, 'keyword' => $title, 'id' => 0, 'post_id' => 0 ),
                        $html,
                        array(),
                        array()
                );
                if ( is_wp_error( $meta ) ) {
                        $meta = array(
                                'title' => $title, 'seo_title' => Haa_Util::cut( $title, 60 ), 'seo_description' => '',
                                'slug' => Haa_Util::slugify( $title ), 'excerpt' => '', 'faq' => array(), 'intro' => '',
                        );
                }

                $status = in_array( (string) ( $cfg['default_status'] ?? 'draft' ), array( 'draft', 'pending' ), true ) ? (string) $cfg['default_status'] : 'draft';
                $cat    = (int) ( $cfg['default_category'] ?? 0 );

                $post_id = wp_insert_post(
                        array(
                                'post_title'    => (string) $meta['title'],
                                'post_name'     => (string) ( $meta['slug'] ?: Haa_Util::slugify( $title ) ),
                                'post_content'  => $html,
                                'post_excerpt'  => (string) ( $meta['excerpt'] ?: Haa_Util::cut( wp_strip_all_tags( $html ), 160 ) ),
                                'post_status'   => $status,
                                'post_type'     => 'post',
                                'post_author'   => get_current_user_id(),
                                'post_category' => $cat ? array( $cat ) : array(),
                        ),
                        true
                );
                if ( is_wp_error( $post_id ) ) {
                        return $post_id;
                }

                update_post_meta( (int) $post_id, '_haai_generated', '1' );
                update_post_meta( (int) $post_id, '_haai_video_source', '' !== $url ? $url : (string) ( $args['url'] ?? '' ) );
                update_post_meta( (int) $post_id, '_haai_gates', array() );

                Haa_Seo::apply(
                        (int) $post_id,
                        array(
                                'html'            => $html,
                                'seo_title'       => (string) $meta['seo_title'],
                                'seo_description' => (string) $meta['seo_description'],
                                'slug'            => (string) $meta['slug'],
                                'excerpt'         => (string) $meta['excerpt'],
                        )
                );

                // Reuse the pipeline's flagging so a video claim gets the same review as an
                // article claim; nothing is deleted, only surfaced.
                $flags = Haa_Pipeline::flag_claims( $html, array( 'title' => $title, 'keyword' => $title ), (array) Haa_Settings::get( 'article', array() ) );
                if ( $flags ) {
                        update_post_meta( (int) $post_id, '_haai_flags', $flags );
                }

                return array(
                        'post_id' => (int) $post_id,
                        'status'  => $status,
                        'flags'   => count( $flags ),
                        'chars'   => mb_strlen( $transcript ),
                );
        }

        /* ------------------------------------------------------------------
         * Handler
         * ---------------------------------------------------------------- */

        /** @return void */
        public function handle_make() {
                Haa_Util::guard_admin_post( 'haai_video' );

                if ( ! self::enabled() ) {
                        $this->back( __( 'ماژول «ویدیو به مقاله» خاموش است.', 'hami-ai-assistant' ), true );
                }

                $title = isset( $_POST['title'] ) ? (string) wp_unslash( $_POST['title'] ) : '';
                $url   = isset( $_POST['url'] ) ? (string) wp_unslash( $_POST['url'] ) : '';

                $transcript = isset( $_POST['transcript'] ) ? (string) wp_unslash( $_POST['transcript'] ) : '';

                // An uploaded file wins over the textarea, and is parsed then forgotten —
                // no transcript is left lying around on disk.
                if ( ! empty( $_FILES['transcript_file']['tmp_name'] ) && is_uploaded_file( (string) $_FILES['transcript_file']['tmp_name'] ) ) {
                        $loaded = self::read_upload( $_FILES['transcript_file'] );
                        if ( is_wp_error( $loaded ) ) {
                                $this->back( (string) $loaded->get_error_message(), true );
                        }
                        $transcript = (string) $loaded;
                }

                if ( '' === trim( $transcript ) ) {
                        $this->back( __( 'ترنسکریپت خالی است؛ فایل SRT/VTT را بفرستید یا متن را بچسبانید.', 'hami-ai-assistant' ), true );
                }

                $cap = Haa_Planner::estimate( 1 );
                if ( '' !== $cap['blocked'] ) {
                        $this->back( $cap['blocked'], true );
                }

                $r = self::write(
                        array(
                                'title'      => $title,
                                'url'        => $url,
                                'transcript' => $transcript,
                        )
                );
                if ( is_wp_error( $r ) ) {
                        $this->back( (string) $r->get_error_message(), true );
                }

                $msg = sprintf(
                        /* translators: 1: post id, 2: flags. */
                        __( 'مقاله از ویدیو ساخته شد (نوشته‌ی %1$d). %2$d ادعا برای بررسی علامت خورد.', 'hami-ai-assistant' ),
                        (int) $r['post_id'],
                        (int) $r['flags']
                );
                if ( 'draft' !== $r['status'] ) {
                        $msg .= ' ' . __( 'در فهرست «بازبینی» ببینید.', 'hami-ai-assistant' );
                }

                $this->back( $msg );
        }

        /**
         * Validate and read an uploaded transcript.
         *
         * Extension whitelist + size cap + a text/plain sniff: a transcript is never
         * executed, but there is no reason to accept a .php named .srt either.
         *
         * @param array $file One $_FILES entry.
         * @return string|WP_Error
         */
        public static function read_upload( $file ) {
                $name = strtolower( (string) ( $file['name'] ?? '' ) );
                $ext  = (string) pathinfo( $name, PATHINFO_EXTENSION );

                if ( ! in_array( $ext, array( 'srt', 'vtt', 'txt' ), true ) ) {
                        return new WP_Error( 'haai_video_type', __( 'فقط فایل SRT، VTT یا TXT.', 'hami-ai-assistant' ) );
                }
                if ( empty( $file['size'] ) || (int) $file['size'] > self::MAX_UPLOAD ) {
                        return new WP_Error( 'haai_video_size', __( 'حجم فایل ترنسکریپت بیش از ۲ مگابایت است.', 'hami-ai-assistant' ) );
                }
                if ( ! empty( $file['error'] ) ) {
                        return new WP_Error( 'haai_video_upload', __( 'آپلود کامل نشد.', 'hami-ai-assistant' ) );
                }

                $raw = file_get_contents( (string) $file['tmp_name'] );
                if ( false === $raw || '' === trim( (string) $raw ) ) {
                        return new WP_Error( 'haai_video_read', __( 'فایل خوانده نشد.', 'hami-ai-assistant' ) );
                }

                // Binary uploads disguised as subtitles.
                if ( preg_match( '/[\x00-\x08\x0E-\x1F]/', (string) $raw ) ) {
                        return new WP_Error( 'haai_video_binary', __( 'فایل متنی نیست.', 'hami-ai-assistant' ) );
                }

                return (string) $raw;
        }

        /* ------------------------------------------------------------------
         * Screen
         * ---------------------------------------------------------------- */

        /** @return void */
        public function render_screen() {
                if ( ! current_user_can( 'manage_options' ) ) {
                        return;
                }
                $cfg = self::cfg();
                ?>
                <div class="haai-wrap">
                        <h1 class="haai-page-title"><?php esc_html_e( 'ویدیو به مقاله', 'hami-ai-assistant' ); ?></h1>

                        <div class="haai-panel">
                                <h2 class="haai-panel__title"><?php esc_html_e( 'ساخت مقاله از ترنسکریپت', 'hami-ai-assistant' ); ?></h2>
                                <p class="haai-panel__note">
                                        <?php esc_html_e( 'راه مطمئن: از YouTube Studio یا هر ویرایشگر، فایل زیرنویس را بگیرید و اینجا بفرستید. گرفتن خودکار زیرنویس اغلب مسدود یا بی‌ثبات است.', 'hami-ai-assistant' ); ?>
                                </p>

                                <?php if ( empty( $cfg['enabled'] ) ) : ?>
                                        <div class="haai-alert haai-alert--warn">
                                                <?php
                                                printf(
                                                        /* translators: %s: settings URL. */
                                                        wp_kses_post( __( 'این ماژول خاموش است؛ از <a href="%s">تنظیمات ← ویدیو به مقاله</a> روشنش کنید.', 'hami-ai-assistant' ) ),
                                                        esc_url( Haa_Util::screen_url( 'settings', array( 'tab' => 'video' ) ) )
                                                );
                                                ?>
                                        </div>
                                <?php else : ?>
                                        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data" class="haai-form">
                                                <?php wp_nonce_field( 'haai_video' ); ?>
                                                <input type="hidden" name="action" value="haai_video_make" />

                                                <p class="haai-field">
                                                        <label for="haai-v-title"><?php esc_html_e( 'عنوان ویدیو', 'hami-ai-assistant' ); ?></label>
                                                        <input type="text" id="haai-v-title" name="title" class="regular-text" required />
                                                </p>

                                                <p class="haai-field">
                                                        <label for="haai-v-url"><?php esc_html_e( 'لینک ویدیو (اختیاری، برای ارجاع)', 'hami-ai-assistant' ); ?></label>
                                                        <input type="url" id="haai-v-url" name="url" class="regular-text" placeholder="https://www.youtube.com/watch?v=…" />
                                                </p>

                                                <p class="haai-field">
                                                        <label for="haai-v-file"><?php esc_html_e( 'فایل ترنسکریپت', 'hami-ai-assistant' ); ?></label>
                                                        <input type="file" id="haai-v-file" name="transcript_file" accept=".srt,.vtt,.txt" />
                                                </p>

                                                <p class="haai-field">
                                                        <label for="haai-v-text"><?php esc_html_e( 'یا متن ترنسکریپت را بچسبانید', 'hami-ai-assistant' ); ?></label>
                                                        <textarea id="haai-v-text" name="transcript" rows="12" class="large-text"></textarea>
                                                        <span class="haai-field__hint">
                                                                <?php
                                                                printf(
                                                                        /* translators: %d: characters. */
                                                                        esc_html__( 'حداقل %d نویسه پس از پاک‌سازی لازم است.', 'hami-ai-assistant' ),
                                                                        (int) ( $cfg['min_chars'] ?? 800 )
                                                                );
                                                                ?>
                                                        </span>
                                                </p>

                                                <p class="description">
                                                        <?php
                                                        printf(
                                                                /* translators: %d: words. */
                                                                esc_html__( 'دو درخواست مدل لازم است (متن + متاداده). هدف حدود %d کلمه.', 'hami-ai-assistant' ),
                                                                (int) ( $cfg['max_words'] ?? 1200 )
                                                        );
                                                        ?>
                                                </p>

                                                <p>
                                                        <button type="submit" class="button button-primary"><?php esc_html_e( 'مقاله را بساز', 'hami-ai-assistant' ); ?></button>
                                                </p>
                                        </form>
                                <?php endif; ?>
                        </div>
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
                                'video-article',
                                array(
                                        $error ? 'haai_err' : 'haai_msg' => rawurlencode( Haa_Util::cut( $message, 300 ) ),
                                )
                        )
                );
                exit;
        }
}
