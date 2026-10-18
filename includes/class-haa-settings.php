<?php
/**
 * Settings service: one big array option (autoload = no) plus a tiny gate.
 *
 * Sections are per-namespace so a module only owns the keys it needs and the
 * admin can save one tab without touching the rest.
 *
 * @package Hami_AI_Assistant
 */

if ( ! defined( 'ABSPATH' ) ) {
        exit;
}

final class Haa_Settings {

        /** Posted when the admin keeps an existing secret. */
        const KEY_MASK = 'haai-unchanged';

        /** Tiny per-module gate: slugs => 1. autoload = yes. */
        const MODULES_OPTION = 'hami_ai_assistant_modules';

        /**
         * Single-version safety copy taken before reset()/import(). autoload = no.
         *
         * It stores the raw settings option exactly as saved — secrets included in
         * their encrypted form — so a snapshot can never leak a plaintext key, and
         * restoring it returns every field to what it was.
         */
        const SNAPSHOT_OPTION = 'hami_ai_assistant_snapshot';

        /**
         * Largest accepted import payload, in bytes.
         *
         * A full settings export is about 8 KB, so half a megabyte is already
         * generous; the cap is what stops a junk file from being parsed at all.
         */
        const IMPORT_MAX_BYTES = 524288;

        /** Word that must be typed to allow a reset. */
        const RESET_WORD = 'بازنشانی';

        /** @var array|null In-memory cache (one load per request). */
        private static $cache = null;

        /* ------------------------------------------------------------------
         * Defaults
         * ---------------------------------------------------------------- */

        /**
         * Every key used anywhere in the plugin must exist here, so exports stay
         * complete and call sites never need isset() dances.
         *
         * @return array
         */
        public static function defaults() {
                return array(
                        'general'   => array(
                                'enabled'            => 1,
                                'display_mode'       => 'both', // shortcode | floating | both.
                                'floating_position'  => 'right',
                                'floating_bottom'    => 24,
                                'floating_side'      => 24,
                                // '' means "not chosen yet": the mode is then derived from the
                                // two lists below, so installs that predate the radio keep
                                // behaving exactly as they did.
                                'pages_mode'         => '', // '' | all | include | exclude.
                                'show_on_pages'      => array(),
                                'exclude_pages'      => array(),
                                'special_pages'      => array(),
                                'show_on_post_types' => array(),
                                'show_roles'         => array(),
                                'show_devices'       => array( 'desktop', 'mobile' ),
                        ),

                        // Shared AI transport. Every module talks to this, never to each other.
                        'provider'  => array(
                                'provider'        => 'openai',
                                'adapter'         => 'openai',
                                'base_url'        => '',
                                'api_key'         => '',
                                'model'           => '',
                                'embedding_model' => '',
                                'image_model'     => '',
                                'temperature'     => 0.7,
                                'max_tokens'      => 2000,
                                'timeout'         => 60,
                                'retries'         => 1,
                                'allow_localhost' => 0,
                                'proxy_host'      => '',
                                'proxy_port'      => '',
                                'proxy_username'  => '',
                                'proxy_password'  => '',
                                // Backup sources for automatic failover. Each row:
                                // provider (preset slug or ''), base_url, api_key
                                // (encrypted), model, hash (stable row id for the
                                // masked-key round-trip).
                                'failovers'       => array(),
                                // Per-provider credential vault: one saved row per
                                // preset slug (gemini, xai, deepseek, …) holding that
                                // service's OWN api_key (encrypted), base_url and
                                // models. Switching «سرویس» swaps this row into the
                                // form, so each provider keeps its key until the admin
                                // changes it — filling Grok's key can never clobber
                                // Gemini's. Written by the server on every provider-tab
                                // save (see provider_vault_write), never posted directly.
                                'vault'           => array(),
                        ),

                        // Derived from Haa_Modules::catalog(): a new module can never be
                        // missing here (which used to switch it back off on every save).
                        'modules'   => self::module_defaults(),

                        'appearance' => array(
                                'primary_color'   => '#4f46e5',
                                'secondary_color' => '#eef2ff',
                                'text_color'      => '#1f2937',
                                'bg_color'        => '#ffffff',
                                'theme_mode'      => 'light',
                                'skin'            => 'standard', // standard | gradient | glass.
                                'dark_bg'         => '#171a23',
                                'dark_text'       => '#e9ebf3',
                                'dark_bubble'     => '#242a37',
                                'font'            => 'vazirmatn',
                                'font_size'       => 14,
                                'border_radius'   => 16,
                                'button_size'     => 56,
                                'custom_icon'     => 0,
                                'welcome_message' => 'سلام! من دستیار هوشمند این فروشگاهم. می‌تونم کمکت کنم بهترین محصول رو انتخاب کنی. بگو دنبال چی هستی؟',
                                'placeholder'     => 'سوالت رو بپرس…',
                                'custom_css'      => '',
                        ),

                        // Owned by the chatbot module.
                        'chat'      => array(
                                'system_prompt'       => 'تو یک مشاور فروش حرفه‌ای، صمیمی و متخصص برای این فروشگاه هستی. فقط بر اساس اطلاعات ارائه‌شده پاسخ بده. اگر اطلاعات کافی نداری، صادقانه بگو. هرگز مشخصات فنی یا ادعای ساختگی نساز. پاسخ‌ها را کوتاه و فارسی بنویس.',
                                'max_history'         => 6,
                                'typing_indicator'    => 1,
                                'suggested_questions' => "قیمت محصولات چقدره؟\nبهترین محصول برای هدیه چیست؟\nروش ارسال چطوره؟",
                                'error_message'       => 'خطایی رخ داد. لطفاً کمی بعد دوباره تلاش کنید.',
                                'context_max_chars'   => 2000,
                                'context_chunks'      => 5,
                                'offline_enabled'     => 0,
                                'offline_message'     => 'خارج از ساعات کاری هستیم. در ساعات کاری پاسخگوی شما هستیم.',
                                'offline_start'       => '09:00',
                                'offline_end'         => '18:00',
                                'save_history'        => 1,
                                'history_retention'   => 30,
                                'lead_capture'        => 0,
                                'lead_requirement'    => 'optional', // off | optional | required.
                                'lead_name_label'     => 'نام',
                                'lead_phone_label'    => 'شماره موبایل',
                                'lead_consent_text'   => 'با ثبت شماره موافقم تا پشتیبانی با من تماس بگیرد.',
                                // One-line tone preset appended to the system prompt:
                                // default = the admin's own prompt text is the whole story.
                                'tone'                => 'default', // default|formal|friendly|playful.
                                // Tell the bot which page the visitor is on (title comes
                                // from the visitor's browser — zero server queries).
                                'page_context'        => 1,
                                // Admin-typed extra knowledge, appended to the prompt.
                                // Settings-only: no table, no index, no query.
                                'custom_knowledge'    => '',
                                // Live human handoff: the visitor leaves name + mobile, the
                                // admin is pinged on Bale / Telegram and reaches the visitor
                                // on WhatsApp or by phone. Nothing here has an API cost.
                                'handoff_enabled'     => 1,
                                'handoff_after'       => 2, // Failed turns before auto-offer; 0 = never.
                                'notify_bale_token'   => '',
                                'notify_bale_chat'    => '',
                                'notify_tg_token'     => '',
                                'notify_tg_chat'      => '',
                                // The chatbot's tiny "knowledge base" for reaching a human:
                                // filled fields become both a system-prompt block and a
                                // deterministic settings-only reply. No table, no query.
                                'contact_whatsapp'    => '',
                                'contact_telegram'    => '',
                                'contact_instagram'   => '',
                                'contact_phone'       => '',
                                'contact_page'        => '',
                                'contact_note'        => '',
                        ),

                        // Owned by product-content / article-writer.
                        'content'   => array(
                                'default_language' => 'fa',
                                'default_tone'     => 'friendly', // friendly|professional|formal|excited|technical.
                                'default_length'   => 'medium',  // short|medium|long|xlong.
                                'default_audience' => 'عموم خریداران',
                                'enable_images'    => 0,
                                'image_size'       => '1024x1024',
                                'image_style'      => 'عکاسی محصول با پس‌زمینه ساده و نور طبیعی',
                                'image_quality'    => 'standard',
                                'internal_links'   => 1,
                                'link_count'       => 3,
                                // Price and stock go stale the moment the shop changes them, so a
                                // generated page must not repeat them unless the admin asks.
                                'include_price'    => 0,
                        ),

                        // Owned by article-writer.
                        'article'   => array(
                                'default_status'   => 'draft',
                                'default_author'   => 0,
                                'batch_size'       => 1,
                                // Daily ceilings for everything the article pipeline spends.
                                // 0 means "no cap", which is NOT the default: an unattended
                                // batch on a shared key is how people get a surprise bill.
                                'daily_token_cap'  => 200000,
                                'daily_article_cap' => 5,
                                // Jaccard/similarity score at which a new subject is treated as
                                // one the site already covers.
                                'dup_threshold'    => 0.7,
                                // How many subjects one planning run may ask for.
                                'plan_count'       => 6,

                                // D3 staged pipeline. 'review' is the default: a machine should
                                // never be the one deciding that a claim is publishable.
                                'publish_mode'     => 'review',
                                'target_words'     => 1600,
                                'max_sections'     => 8,
                                'inline_images'    => 0,
                                'faq_schema'       => 1,
                                'link_cap'         => 5,
                                'gate_word_ratio'  => 70,
                                'flag_claims'      => 1,

                                // D4 monthly refresh. Off by default: re-writing a published URL
                                // is a change to something already indexed.
                                'refresh_enabled'  => 0,
                                'refresh_days'     => 120,
                                'refresh_mode'     => 'suggest',
                        ),

                        // Owned by article-writer, but injected into every module that
                        // writes text. Disabled by default: it only takes effect once an
                        // admin has actually described the business.
                        'profile'   => array(
                                'enabled'    => 0,
                                'domain'     => '',
                                'audience'   => '',
                                'voice'      => '',
                                'offerings'  => '',
                                'glossary'   => '',
                                'forbidden'  => '',
                                'examples'   => '',
                                'key_pages'  => array(),
                                'max_chars'  => 1200,
                        ),

                        // Owned by article-writer. Off by default: outbound crawling is a
                        // decision, not a default.
                        'research'  => array(
                                'enabled'         => 0,
                                'competitors'     => '',
                                'user_agent'      => '',
                                'respect_robots'  => 1,
                                'delay_ms'        => 1200,
                                'timeout'         => 8,
                                'max_pages'       => 6,
                                'max_kb'          => 400,
                                'cache_hours'     => 72,
                                'fallback_ai'     => 1,
                                'suggest_enabled' => 1,
                                'serp_enabled'    => 0,
                                'serp_kind'       => 'serper',
                                'serp_base_url'   => '',
                                'serp_key'        => '',
                        ),

                        // Owned by the news module. Off by default; reading other people's
                        // feeds is a decision, and every summary costs a model call.
                        'news'    => array(
                                'enabled'          => 0,
                                'feeds'            => '',
                                'max_per_run'      => 5,
                                'daily_cap'        => 3,
                                'lookback_hours'   => 48,
                                'default_status'   => 'draft',
                                'default_category' => 0,
                                'min_words'        => 350,
                                'quote_source'     => 0,
                        ),

                        // Owned by the video-article module. Transcript-driven by design: we do
                        // not scrape YouTube, and captions are opt-in.
                        'video'   => array(
                                'enabled'          => 0,
                                'min_chars'        => 800,
                                'max_words'        => 1200,
                                'default_status'   => 'draft',
                                'default_category' => 0,
                                'fetch_captions'   => 0,
                        ),

                        // Owned by the programmatic module. noindex defaults ON: an unproven
                        // page should not be pushed into the index.
                        'programmatic' => array(
                                'enabled'          => 0,
                                'source'           => 'csv',
                                'attribute'        => '',
                                'template'         => '',
                                'title_template'   => '',
                                'min_words'        => 450,
                                'similar_threshold' => 0.75,
                                'daily_cap'        => 10,
                                'hub_page'         => 0,
                                'noindex'          => 1,
                                'default_status'   => 'draft',
                        ),

                        // Owned by autopilot.
                        'autopilot' => array(
                                'daily_token_cap'    => 300000,
                                'daily_post_cap'     => 3,
                                'lock_ttl'           => 900,
                                'retry_limit'        => 2,
                                // cron_key removed: no real-cron endpoint exists.
                                'default_status'     => 'draft',
                                // default_language removed: run_job reads content.default_language.
                                'default_tone'       => 'friendly',
                                'default_length'      => 'medium',
                                'enable_images'      => 0,
                                'default_category'   => 0,
                        ),

                        // Owned by leads.
                        'leads'     => array(
                                'retention_days'     => 365,
                                'stages'             => "new\ncontacted\nqualified\nwon\nlost",
                                'csv_enabled'        => 1,
                                'ai_summary'         => 1,
                                'score_phone'        => 15,
                                'score_message'      => 2,
                                'score_buying'       => 20,
                                'auto_create'        => 1,
                        ),

                        // Owned by discount.
                        'discount'  => array(
                                'enabled'            => 0,
                                'max_rounds'         => 3,
                                'global_max_percent' => 15,
                                'default_max_percent'=> 10,
                                'per_visitor'        => 3,
                                'rate_minutes'       => 60,
                                'coupon_expiry_days' => 7,
                                'min_cart'           => 0,
                                'max_uses'           => 1,
                        ),

                        // Owned by comments.
                        'comments'  => array(
                                'enabled'            => 1,
                                'author_name'        => 'پشتیبانی سایت',
                                'author_email'       => '',
                                'default_status'     => 'pending',
                                'generate_questions' => 1,
                                'generate_answers'   => 1,
                                'max_per_run'        => 5,
                        ),

                        // Owned by semantic-search (embeddings are opt-in).
                        'search'    => array(
                                'enabled'           => 0,
                                'limit'             => 10,
                                'min_score'         => 0.3,
                                'semantic_weight'   => 0.55,
                                'keyword_weight'    => 0.45,
                                'include_products'  => 1,
                                'include_posts'     => 1,
                                'include_pages'     => 1,
                                'embeddings'        => 0,
                                'hybrid'            => 1,
                                'max_vectors'       => 3000,
                                'chunk_cap'         => 2000,
                        ),

                        // Shared plumbing.
                        'advanced'  => array(
                                'rate_limit_count'   => 20,
                                'rate_limit_minutes' => 60,
                                'token_ttl_minutes'  => 30,
                                'max_message_chars'  => 4000,
                                'daily_request_cap'  => 500,
                                'daily_cap_message'  => 'به سقف روزانه‌ی چت این سایت رسیده‌اید. لطفاً فردا دوباره تلاش کنید.',
                                'daily_user_cap'     => 30,
                                'daily_user_cap_message' => 'به سقف روزانه‌ی گفتگوی شما رسیده‌اید. لطفاً فردا دوباره تلاش کنید.',
                                'log_conversations'  => 0,
                                'log_retention_days' => 30,
                                'guest_access'       => 0, // 0 everyone, 1 guests only, 2 members only.
                                'login_required_message' => 'برای استفاده از چت باید وارد شوید.',
                                'ip_header'          => '',
                                'enable_cache'       => 1,
                                'cache_ttl'          => 3600,
                                'enable_rag'         => 1,
                                'kb_enabled'         => 1,
                                'kb_post_types'      => array( 'post', 'page' ),
                                'kb_categories'      => array(),
                        'kb_chunk_words'     => 300,
                        'kb_overlap_words'   => 50,
                                'kb_max_file_kb'     => 2048,
                                'usage_retention'    => 90,
                        ),

                        // Site knowledge (tab «دانش سایت»): the rendered-page crawler.
                        // urls = pages the admin insists the bot must know; auto_crawl
                        // also picks up internal links found on the homepage; max_pages
                        // is the whole budget so a crawl stays light by construction.
                        // crawl_types = which WP content joins the queue (pages first —
                        // contact/about live there; products/posts are opt-in so a big
                        // shop stays light by default).
                        'knowledge' => array(
                                'urls'          => '',
                                'auto_crawl'    => 1,
                                'max_pages'     => 30,
                                'crawl_types'   => array( 'page' ),
                                // 2.1.23 — AEO toolkit: all on by default; each one is a
                                // bounded, cached job the admin can switch off.
                                'llms_enabled'  => 1,
                                'bots_enabled'  => 1,
                                'links_enabled' => 1,
                        ),
                );
        }

        /* ------------------------------------------------------------------
         * Read / write
         * ---------------------------------------------------------------- */

        /**
         * Write defaults on first activation.
         */
        public static function install() {
                if ( null === get_option( HAAI_OPTION, null ) ) {
                        add_option( HAAI_OPTION, self::defaults(), '', 'no' );
                }
                if ( null === get_option( HAAI_ACTIVE, null ) ) {
                        add_option( HAAI_ACTIVE, 1, '', 'yes' );
                }
                if ( null === get_option( self::MODULES_OPTION, null ) ) {
                        add_option( self::MODULES_OPTION, array( 'chatbot' => 1 ), '', 'yes' );
                }
                // Migrate legacy 'friendl' tone to 'friendly'.
                self::migrate_tone_typo();
                self::migrate_autopilot_status();
        }

        /**
         * One-shot fix for the 'friendl' -> 'friendly' typo. Idempotent.
         */
        private static function migrate_tone_typo() {
                $saved = get_option( HAAI_OPTION, null );
                if ( ! is_array( $saved ) ) {
                        return;
                }
                $fix = false;
                if ( isset( $saved['content']['default_tone'] ) && 'friendl' === $saved['content']['default_tone'] ) {
                        $saved['content']['default_tone'] = 'friendly';
                        $fix = true;
                }
                if ( isset( $saved['autopilot']['default_tone'] ) && 'friendl' === $saved['autopilot']['default_tone'] ) {
                        $saved['autopilot']['default_tone'] = 'friendly';
                        $fix = true;
                }
                if ( $fix ) {
                        update_option( HAAI_OPTION, $saved, 'no' );
                        self::flush_cache();
                }
        }

        /**
         * One-shot: demote legacy autopilot.default_status=publish → pending.
         * Auto-publish was removed from choices for safety; existing installs must
         * not silently keep publishing without review.
         *
         * @return void
         */
        private static function migrate_autopilot_status() {
                $saved = get_option( HAAI_OPTION, null );
                if ( ! is_array( $saved ) ) {
                        return;
                }
                if ( isset( $saved['autopilot']['default_status'] ) && 'publish' === $saved['autopilot']['default_status'] ) {
                        $saved['autopilot']['default_status'] = 'pending';
                        update_option( HAAI_OPTION, $saved, 'no' );
                        self::flush_cache();
                }
        }

        /**
         * Full merged array, or one dot-path value. Secrets come back ENCRYPTED;
         * use provider() for the decrypted copy.
         *
         * @param string|null $key     Dot path.
         * @param mixed       $default Fallback.
         * @return mixed
         */
        public static function get( $key = null, $default = null ) {
                if ( null === self::$cache ) {
                        $saved     = get_option( HAAI_OPTION, array() );
                        self::$cache = self::merge( self::defaults(), is_array( $saved ) ? $saved : array() );
                }
                if ( null === $key ) {
                        return self::$cache;
                }
                $segments = explode( '.', $key );
                $value    = self::$cache;
                foreach ( $segments as $segment ) {
                        if ( ! is_array( $value ) || ! array_key_exists( $segment, $value ) ) {
                                return $default;
                        }
                        $value = $value[ $segment ];
                }
                return $value;
        }

        /**
         * Forget the in-request cache (after a save).
         */
        public static function flush_cache() {
                self::$cache = null;
        }

        /**
         * Is the whole plugin switched on? Read from the autoloaded gate.
         *
         * @return bool
         */
        public static function is_active() {
                $flag = get_option( HAAI_ACTIVE, 0 );
                return ! empty( $flag );
        }

        /**
         * Enabled module slugs, read from the tiny autoloaded mirror.
         *
         * Only truthy entries are returned. The mirror is not always a clean
         * slug=>1 list: the v1 migrator writes the full 0/1 map, so the stored
         * option can hold switched-off flags. Returning those would make every
         * consumer that walks the keys believe all eight modules are live — which
         * is how a chatbot-only install ended up with dozens of fake problems in
         * diagnose().
         *
         * @return array<string,1>
         */
        public static function enabled_modules() {
                $raw = get_option( self::MODULES_OPTION, array() );
                return is_array( $raw ) ? array_filter( $raw ) : array();
        }

        /**
         * @param string $slug Module slug.
         * @return bool
         */
        public static function is_module_enabled( $slug ) {
                $all = self::enabled_modules();
                return ! empty( $all[ $slug ] );
        }

        /**
         * Every module slug, derived from the catalogue.
         *
         * This is the single source of truth for the `modules` section of the
         * defaults and for the sanitize map: a module added to
         * Haa_Modules::catalog() becomes settable, savable and diagnosable
         * without touching this file.
         *
         * @return array<int,string>
         */
        public static function module_slugs() {
                if ( class_exists( 'Haa_Modules' ) ) {
                        $slugs = array_keys( (array) Haa_Modules::catalog() );
                        if ( $slugs ) {
                                return array_values( $slugs );
                        }
                }
                // Only reachable when the catalogue file itself is unreadable: the
                // gate of the one module v1 had must still exist.
                return array( 'chatbot' );
        }

        /**
         * The `modules` section of the defaults: a fresh install starts with the
         * chatbot on and every other module off.
         *
         * @return array<string,int>
         */
        private static function module_defaults() {
                $out = array();
                foreach ( self::module_slugs() as $slug ) {
                        $out[ $slug ] = 'chatbot' === $slug ? 1 : 0;
                }
                return $out;
        }

        /**
         * Persist the module gate mirror, then keep the big option's `modules`
         * section in sync with it.
         *
         * @param array $enabled Slug => 1.
         */
        public static function set_enabled_modules( $enabled ) {
                $flags = array_filter( (array) $enabled );
                update_option( self::MODULES_OPTION, $flags, 'yes' );

                // Keep the big option's `modules` section truthful as well. reset() and
                // import() rebuild this mirror from that section, so a stale copy there
                // would silently switch modules back off after one of those operations.
                $stored = get_option( HAAI_OPTION, null );
                if ( is_array( $stored ) && $stored ) {
                        $map = array();
                        foreach ( self::module_slugs() as $slug ) {
                                $map[ $slug ] = empty( $flags[ $slug ] ) ? 0 : 1;
                        }
                        $stored['modules'] = $map;
                        update_option( HAAI_OPTION, $stored, 'no' );
                        self::flush_cache();
                }
        }

        /**
         * Save and sanitize one section (each admin tab is its own form).
         *
         * @param string $section Section slug.
         * @param array  $data    Raw POST data.
         * @return bool
         */
        public static function save_section( $section, $data ) {
                $all             = self::get();
                $clean           = self::sanitize_section( $section, is_array( $data ) ? $data : array() );

                // One rule, one list: the mode picks which list is live, and the other
                // is emptied rather than left behind as a ghost that still filters.
                if ( 'general' === $section ) {
                        $mode = isset( $clean['pages_mode'] ) ? $clean['pages_mode'] : '';
                        if ( 'all' === $mode || '' === $mode ) {
                                // '' arrives only when the admin chose nothing and both lists
                                // are empty; keep whatever derivation the old data implied.
                                if ( 'all' === $mode ) {
                                        $clean['show_on_pages'] = array();
                                        $clean['exclude_pages'] = array();
                                }
                        } elseif ( 'include' === $mode ) {
                                $clean['exclude_pages'] = array();
                        } elseif ( 'exclude' === $mode ) {
                                $clean['show_on_pages'] = array();
                        }
                }

                // The per-provider vault is rebuilt on every provider-tab save: the
                // active credentials migrate into their own slot, the posted
                // provider's row is refreshed, and a service switch can never copy
                // the previous provider's key onto the new one.
                if ( 'provider' === $section ) {
                        $clean = self::provider_vault_write( $clean, is_array( $data ) ? $data : array() );
                }

                $all[ $section ] = $clean;
                $ok              = self::persist( $all );

                if ( 'modules' === $section ) {
                        self::set_enabled_modules( self::module_flags_from_section( $clean ) );
                }
                return $ok;
        }

        /**
         * Turn a sanitized `modules` section into the slug => 1 gate map.
         *
         * @param array $section Sanitized section.
         * @return array
         */
        public static function module_flags_from_section( $section ) {
                $flags = array();
                foreach ( (array) $section as $slug => $on ) {
                        if ( ! empty( $on ) ) {
                                $flags[ $slug ] = 1;
                        }
                }
                return $flags;
        }

        /**
         * Replace everything (import / reset).
         *
         * @param array $data Full settings array.
         * @return bool
         */
        /**
         * Merge the incoming settings over what is already stored.
         *
         * A caller may hand us one section, or one key, and everything it did not
         * mention keeps its stored value. Merging onto the defaults instead — the
         * old behaviour — silently pushed every omitted field back to its default,
         * which is how a partial save wiped a live install's provider key.
         *
         * @param array $data Settings to write.
         * @return bool
         */
        public static function save_all( $data ) {
                $all = self::merge( self::stored_or_defaults(), is_array( $data ) ? $data : array() );
                $ok  = self::persist( $all );
                self::set_enabled_modules( self::module_flags_from_section( $all['modules'] ) );
                return $ok;
        }

        /**
         * The raw stored option, or the defaults on a fresh install.
         *
         * @return array
         */
        private static function stored_or_defaults() {
                $stored = get_option( HAAI_OPTION, null );
                return is_array( $stored ) ? $stored : self::defaults();
        }

        /**
         * @param array $all Settings.
         * @return bool
         */
        private static function persist( $all ) {
                if ( false === get_option( HAAI_OPTION, null ) ) {
                        add_option( HAAI_OPTION, $all, '', 'no' );
                } else {
                        update_option( HAAI_OPTION, $all, 'no' );
                }
                update_option( HAAI_ACTIVE, empty( $all['general']['enabled'] ) ? 0 : 1, 'yes' );
                self::flush_cache();
                return true;
        }

        /**
         * Back to defaults.
         *
         * The one path allowed to throw stored values away, so it writes the
         * defaults directly instead of going through the merge in save_all().
         */
        public static function reset() {
                self::snapshot();
                $all = self::defaults();
                $ok  = self::persist( $all );
                self::set_enabled_modules( self::module_flags_from_section( $all['modules'] ) );
                return $ok;
        }

        /* ------------------------------------------------------------------
         * Safety snapshot (before reset / import)
         * ---------------------------------------------------------------- */

        /**
         * Keep a single-version copy of the settings exactly as they are stored,
         * just before a destructive write throws them away.
         *
         * The snapshot is the raw option, so `provider.api_key` and
         * `provider.proxy_password` stay in their encrypted form — the copy never
         * holds a decryptable secret, and restoring returns them byte for byte.
         * The module mirror is captured too, so restore() brings switched-on
         * modules back as well as every field.
         *
         * @return bool Whether a snapshot was taken.
         */
        public static function snapshot() {
                $stored = get_option( HAAI_OPTION, null );
                if ( ! is_array( $stored ) || ! $stored ) {
                        // Nothing saved yet (a fresh install): there is no state to lose.
                        return false;
                }

                $snap = array(
                        'time'     => current_time( 'mysql' ),
                        'version'  => HAAI_VERSION,
                        'settings' => $stored,
                        'modules'  => get_option( self::MODULES_OPTION, array() ),
                );

                if ( false === get_option( self::SNAPSHOT_OPTION, null ) ) {
                        add_option( self::SNAPSHOT_OPTION, $snap, '', 'no' );
                } else {
                        update_option( self::SNAPSHOT_OPTION, $snap, 'no' );
                }
                return true;
        }

        /**
         * @return bool True when a restorable snapshot exists.
         */
        public static function has_snapshot() {
                $snap = get_option( self::SNAPSHOT_OPTION, null );
                return is_array( $snap ) && isset( $snap['settings'] ) && is_array( $snap['settings'] );
        }

        /**
         * Human-readable snapshot stamp for the restore UI.
         *
         * @return string Formatted time + version, or '' when there is no snapshot.
         */
        public static function snapshot_label() {
                if ( ! self::has_snapshot() ) {
                        return '';
                }
                $snap = get_option( self::SNAPSHOT_OPTION, null );
                $time = isset( $snap['time'] ) ? (string) $snap['time'] : '';
                $ver  = isset( $snap['version'] ) ? (string) $snap['version'] : '';
                /* translators: 1: date/time, 2: plugin version. */
                return trim( $time . ( '' !== $ver ? ' (v' . $ver . ')' : '' ) );
        }

        /**
         * Restore the last snapshot taken before reset()/import().
         *
         * @return bool Whether a snapshot was restored.
         */
        public static function restore_snapshot() {
                if ( ! self::has_snapshot() ) {
                        return false;
                }
                $snap = get_option( self::SNAPSHOT_OPTION, null );

                // Write the stored shape straight back — no merge, no sanitize, so the
                // encrypted secrets and every untouched field return exactly as they
                // were. The autoload gate is rebuilt by persist().
                self::persist( $snap['settings'] );

                if ( isset( $snap['modules'] ) ) {
                        self::set_enabled_modules( (array) $snap['modules'] );
                }
                self::flush_cache();
                return true;
        }

        /* ------------------------------------------------------------------
         * Provider resolution
         * ---------------------------------------------------------------- */

        /**
         * Decrypted, preset-resolved provider config. Server side only.
         *
         * @return array
         */
        public static function provider() {
                $p         = self::get( 'provider' );
                $presets   = Haa_API::presets();
                $slug      = isset( $p['provider'] ) ? $p['provider'] : 'openai';

                if ( isset( $presets[ $slug ] ) ) {
                        $preset = $presets[ $slug ];
                        if ( '' === $p['base_url'] ) {
                                $p['base_url'] = $preset['base_url'];
                        }
                        if ( '' === $p['model'] ) {
                                $p['model'] = $preset['model'];
                        }
                        $p['adapter'] = $preset['adapter'];
                } elseif ( ! isset( $p['adapter'] ) || ! isset( Haa_API::adapters()[ $p['adapter'] ] ) ) {
                        $p['adapter'] = 'openai';
                }

                $p['api_key']        = self::decrypt( $p['api_key'] );
                $p['proxy_password'] = self::decrypt( $p['proxy_password'] );

                // Failover rows become wire-ready candidates. Transport settings
                // (timeout, proxy, retries…) stay with the primary; Haa_API merges
                // them at call time, so only the endpoint, key, model and wire
                // format come from the row.
                $p['failovers'] = self::resolve_failover_rows( isset( $p['failovers'] ) ? $p['failovers'] : array() );

                return $p;
        }

        /**
         * Wire-ready provider config built from a just-posted (unsaved) provider
         * form — what the admin "test connection" and "fetch models" buttons use.
         *
         * Feeding `sanitize_section()` straight into Haa_API cannot work, because
         * that method returns the stored SHAPE of the data: secrets come back
         * RE-ENCRYPTED (so the bearer token would be ciphertext) and blank
         * base_url / model / adapter (a preset fills those only in provider()).
         * This resolves both, so testing before saving behaves exactly like the
         * live requests will.
         *
         * @param array $posted Raw `provider` section from the form.
         * @return array Decrypted, preset-resolved provider config.
         */
        public static function provider_from_post( $posted ) {
                $stored  = self::provider();
                $posted  = is_array( $posted ) ? $posted : array();
                $clean   = self::sanitize_section( 'provider', $posted );
                $cfg     = array_merge( $stored, $clean );

                // Resolve the preset against the provider the FORM selected, never the
                // one already stored: sanitize_section hands back blanks for anything a
                // preset owns, and the admin may be testing a different service than the
                // one that is saved. Rules mirror provider().
                $presets = Haa_API::presets();
                if ( isset( $presets[ $cfg['provider'] ] ) ) {
                        $preset        = $presets[ $cfg['provider'] ];
                        $cfg['base_url'] = '' === $cfg['base_url'] ? $preset['base_url'] : $cfg['base_url'];
                        $cfg['model']    = '' === $cfg['model'] ? $preset['model'] : $cfg['model'];
                        // A preset owns its wire format; the adapter select is only for
                        // custom providers. Without this, picking "Google Gemini" would
                        // still speak OpenAI to the Gemini URL.
                        $cfg['adapter']  = $preset['adapter'];
                } else {
                        if ( '' === $cfg['base_url'] ) {
                                $cfg['base_url'] = $stored['base_url'];
                        }
                        if ( '' === $cfg['model'] ) {
                                $cfg['model'] = $stored['model'];
                        }
                        if ( '' === $cfg['adapter'] || ! isset( Haa_API::adapters()[ $cfg['adapter'] ] ) ) {
                                $cfg['adapter'] = 'openai';
                        }
                }

                // Secrets must reach the wire in plaintext. An untouched (or cleared)
                // secret field keeps the stored one; a typed one is used as typed.
                foreach ( array( 'api_key', 'proxy_password' ) as $field ) {
                        $raw = isset( $posted[ $field ] ) ? (string) $posted[ $field ] : '';

                        if ( 'api_key' === $field && ( '' === $raw || self::KEY_MASK === $raw ) ) {
                                // The mask means "keep THIS service's key": when the form
                                // switched to a different provider, test against that
                                // provider's own vaulted key — never the previous service's.
                                $form_slug = isset( $cfg['provider'] ) && is_scalar( $cfg['provider'] ) ? (string) $cfg['provider'] : '';
                                if ( '' !== $form_slug && $form_slug !== (string) self::get( 'provider.provider', '' ) ) {
                                        $cfg['api_key'] = self::decrypt( (string) self::get( 'provider.vault.' . $form_slug . '.api_key', '' ) );
                                } else {
                                        $cfg['api_key'] = $stored['api_key'];
                                }
                                continue;
                        }

                        $cfg[ $field ] = ( '' === $raw || self::KEY_MASK === $raw )
                                ? $stored[ $field ]
                                : self::decrypt( $cfg[ $field ] );
                }

                // Failover rows arrive here in sanitized slug form (encrypted keys);
                // resolve them to the same wire shape provider() emits, so every
                // consumer of this config sees one canonical shape.
                $cfg['failovers'] = self::resolve_failover_rows( isset( $cfg['failovers'] ) ? $cfg['failovers'] : array() );

                return $cfg;
        }

        /**
         * Embedding model: the stored override, else a sensible per-adapter default.
         *
         * @return string Empty when embeddings are not usable with this provider.
         */
        public static function embedding_model() {
                $p = self::provider();
                if ( '' !== $p['embedding_model'] ) {
                        return $p['embedding_model'];
                }
                $defaults = array(
                        'openai'  => 'text-embedding-3-small',
                        'gemini'  => 'text-embedding-004',
                        'claude'  => '', // Anthropic has no embedding endpoint.
                );
                $adapter = $p['adapter'] ?? 'openai';
                return $defaults[ $adapter ] ?? '';
        }

        /**
         * Image model: stored override, else per-adapter default.
         *
         * @return string
         */
        public static function image_model() {
                $p = self::provider();
                if ( '' !== $p['image_model'] ) {
                        return $p['image_model'];
                }
                $defaults = array(
                        'openai'  => 'dall-e-3',
                        'gemini'  => 'gemini-2.0-flash-exp-image-generation',
                        'claude'  => '',
                );
                $adapter = $p['adapter'] ?? 'openai';
                return $defaults[ $adapter ] ?? '';
        }

        /* ------------------------------------------------------------------
         * Suggested questions
         * ---------------------------------------------------------------- */

        /**
         * @return array<int,string>
         */
        public static function suggested_questions() {
                $raw = (string) self::get( 'chat.suggested_questions', '' );
                $out = array();
                foreach ( preg_split( '/\R+/u', $raw ) as $line ) {
                        $line = trim( wp_strip_all_tags( $line ) );
                        if ( '' !== $line ) {
                                $out[] = $line;
                        }
                        if ( count( $out ) >= 6 ) {
                                break;
                        }
                }
                return $out;
        }

        /* ------------------------------------------------------------------
         * Sanitizing
         * ---------------------------------------------------------------- */

        /**
         * @param string $section Section slug.
         * @param array  $data    Raw data.
         * @return array
         */
        public static function sanitize_section( $section, $data ) {
                $defaults = self::defaults();
                $rules    = self::sanitize_map();
                $out      = isset( $defaults[ $section ] ) ? $defaults[ $section ] : array();

                foreach ( $rules as $dotted => $rule ) {
                        if ( 0 !== strpos( $dotted, $section . '.' ) ) {
                                continue;
                        }
                        $local    = substr( $dotted, strlen( $section ) + 1 );
                        $default  = self::path_get( $defaults, $dotted );
                        $raw      = self::path_get( $data, $local );

                        if ( self::KEY_MASK === $raw ) {
                                $stored = self::path_get( self::get(), $dotted );
                                $raw    = ( null !== $stored && '' !== $stored ) ? $stored : '';
                        }

                        self::path_set( $out, $local, call_user_func( $rule['cb'], $raw, $default, $rule ) );
                }
                return $out;
        }

        /**
         * Field map: dotted key => sanitizer rule.
         *
         * @return array
         */
        public static function sanitize_map() {
                $b = 'bool';
                $m = array(
                        // General.
                        'general.enabled'            => array( 'cb' => array( 'Haa_Settings', 'sanitize_bool' ) ),
                        'general.display_mode'       => array( 'cb' => array( 'Haa_Settings', 'sanitize_choice' ), 'choices' => array( 'shortcode', 'floating', 'both' ) ),
                        'general.floating_position'  => array( 'cb' => array( 'Haa_Settings', 'sanitize_choice' ), 'choices' => array( 'right', 'left' ) ),
                        'general.floating_bottom'    => array( 'cb' => array( 'Haa_Settings', 'sanitize_int' ), 'min' => 0, 'max' => 200 ),
                        'general.floating_side'      => array( 'cb' => array( 'Haa_Settings', 'sanitize_int' ), 'min' => 0, 'max' => 200 ),
                        'general.show_on_pages'      => array( 'cb' => array( 'Haa_Settings', 'sanitize_id_list' ) ),
                        'general.exclude_pages'      => array( 'cb' => array( 'Haa_Settings', 'sanitize_id_list' ) ),
                        'general.pages_mode'         => array( 'cb' => array( 'Haa_Settings', 'sanitize_choice' ), 'choices' => array( '', 'all', 'include', 'exclude' ) ),
                        'general.special_pages'      => array( 'cb' => array( 'Haa_Settings', 'sanitize_key_list' ) ),
                        'general.show_on_post_types' => array( 'cb' => array( 'Haa_Settings', 'sanitize_key_list' ) ),
                        'general.show_roles'         => array( 'cb' => array( 'Haa_Settings', 'sanitize_key_list' ) ),
                        'general.show_devices'       => array( 'cb' => array( 'Haa_Settings', 'sanitize_key_list' ) ),

                        // Provider.
                        'provider.provider'        => array( 'cb' => array( 'Haa_Settings', 'sanitize_text' ) ),
                        'provider.adapter'         => array( 'cb' => array( 'Haa_Settings', 'sanitize_choice' ), 'choices' => array( 'openai', 'gemini', 'claude' ) ),
                        'provider.base_url'        => array( 'cb' => array( 'Haa_Settings', 'sanitize_url' ) ),
                        'provider.api_key'         => array( 'cb' => array( 'Haa_Settings', 'sanitize_secret' ) ),
                        'provider.model'           => array( 'cb' => array( 'Haa_Settings', 'sanitize_model' ) ),
                        'provider.embedding_model' => array( 'cb' => array( 'Haa_Settings', 'sanitize_model' ) ),
                        'provider.image_model'     => array( 'cb' => array( 'Haa_Settings', 'sanitize_model' ) ),
                        'provider.temperature'     => array( 'cb' => array( 'Haa_Settings', 'sanitize_float' ), 'min' => 0, 'max' => 2 ),
                        'provider.max_tokens'      => array( 'cb' => array( 'Haa_Settings', 'sanitize_int' ), 'min' => 64, 'max' => 32000 ),
                        'provider.timeout'         => array( 'cb' => array( 'Haa_Settings', 'sanitize_int' ), 'min' => 5, 'max' => 300 ),
                        'provider.retries'         => array( 'cb' => array( 'Haa_Settings', 'sanitize_int' ), 'min' => 0, 'max' => 5 ),
                        'provider.proxy_host'      => array( 'cb' => array( 'Haa_Settings', 'sanitize_host' ) ),
                        'provider.proxy_port'      => array( 'cb' => array( 'Haa_Settings', 'sanitize_int' ), 'min' => 0, 'max' => 65535 ),
                        'provider.proxy_username'  => array( 'cb' => array( 'Haa_Settings', 'sanitize_text' ) ),
                        'provider.proxy_password'  => array( 'cb' => array( 'Haa_Settings', 'sanitize_secret' ) ),
                        'provider.allow_localhost' => array( 'cb' => array( 'Haa_Settings', 'sanitize_bool' ) ),
                        'provider.failovers'       => array( 'cb' => array( 'Haa_Settings', 'sanitize_failovers' ) ),
                        'provider.vault'           => array( 'cb' => array( 'Haa_Settings', 'sanitize_vault' ) ),

                        // Appearance.
                        'appearance.primary_color'   => array( 'cb' => array( 'Haa_Settings', 'sanitize_color' ) ),
                        'appearance.secondary_color' => array( 'cb' => array( 'Haa_Settings', 'sanitize_color' ) ),
                        'appearance.text_color'      => array( 'cb' => array( 'Haa_Settings', 'sanitize_color' ) ),
                        'appearance.bg_color'        => array( 'cb' => array( 'Haa_Settings', 'sanitize_color' ) ),
                        'appearance.theme_mode'      => array( 'cb' => array( 'Haa_Settings', 'sanitize_choice' ), 'choices' => array( 'light', 'dark', 'auto' ) ),
                        'appearance.skin'            => array( 'cb' => array( 'Haa_Settings', 'sanitize_choice' ), 'choices' => array( 'standard', 'gradient', 'glass' ) ),
                        'appearance.dark_bg'         => array( 'cb' => array( 'Haa_Settings', 'sanitize_color' ) ),
                        'appearance.dark_text'       => array( 'cb' => array( 'Haa_Settings', 'sanitize_color' ) ),
                        'appearance.dark_bubble'     => array( 'cb' => array( 'Haa_Settings', 'sanitize_color' ) ),
                        'appearance.font'            => array( 'cb' => array( 'Haa_Settings', 'sanitize_choice' ), 'choices' => array( 'system', 'vazirmatn', 'iransans', 'dana', 'yekan' ) ),
                        'appearance.font_size'       => array( 'cb' => array( 'Haa_Settings', 'sanitize_int' ), 'min' => 11, 'max' => 20 ),
                        'appearance.border_radius'   => array( 'cb' => array( 'Haa_Settings', 'sanitize_int' ), 'min' => 0, 'max' => 32 ),
                        'appearance.button_size'     => array( 'cb' => array( 'Haa_Settings', 'sanitize_int' ), 'min' => 40, 'max' => 80 ),
                        'appearance.custom_icon'     => array( 'cb' => array( 'Haa_Settings', 'sanitize_int' ), 'min' => 0, 'max' => 9999999 ),
                        'appearance.welcome_message' => array( 'cb' => array( 'Haa_Settings', 'sanitize_html' ) ),
                        'appearance.placeholder'     => array( 'cb' => array( 'Haa_Settings', 'sanitize_text' ) ),
                        'appearance.custom_css'      => array( 'cb' => array( 'Haa_Settings', 'sanitize_css' ) ),

                        // Chat (chatbot module).
                        'chat.system_prompt'       => array( 'cb' => array( 'Haa_Settings', 'sanitize_textarea' ) ),
                        'chat.max_history'         => array( 'cb' => array( 'Haa_Settings', 'sanitize_int' ), 'min' => 0, 'max' => 20 ),
                        'chat.typing_indicator'    => array( 'cb' => array( 'Haa_Settings', 'sanitize_bool' ) ),
                        'chat.suggested_questions' => array( 'cb' => array( 'Haa_Settings', 'sanitize_textarea' ) ),
                        'chat.error_message'       => array( 'cb' => array( 'Haa_Settings', 'sanitize_textarea' ) ),
                        'chat.context_max_chars'   => array( 'cb' => array( 'Haa_Settings', 'sanitize_int' ), 'min' => 200, 'max' => 12000 ),
                        'chat.context_chunks'      => array( 'cb' => array( 'Haa_Settings', 'sanitize_int' ), 'min' => 1, 'max' => 12 ),
                        'chat.offline_enabled'     => array( 'cb' => array( 'Haa_Settings', 'sanitize_bool' ) ),
                        'chat.offline_message'     => array( 'cb' => array( 'Haa_Settings', 'sanitize_textarea' ) ),
                        'chat.offline_start'       => array( 'cb' => array( 'Haa_Settings', 'sanitize_time' ) ),
                        'chat.offline_end'         => array( 'cb' => array( 'Haa_Settings', 'sanitize_time' ) ),
                        'chat.save_history'        => array( 'cb' => array( 'Haa_Settings', 'sanitize_bool' ) ),
                        'chat.history_retention'   => array( 'cb' => array( 'Haa_Settings', 'sanitize_int' ), 'min' => 1, 'max' => 365 ),
                        'chat.lead_capture'        => array( 'cb' => array( 'Haa_Settings', 'sanitize_bool' ) ),
                        'chat.lead_requirement'    => array( 'cb' => array( 'Haa_Settings', 'sanitize_choice' ), 'choices' => array( 'off', 'optional', 'required' ) ),
                        'chat.lead_name_label'     => array( 'cb' => array( 'Haa_Settings', 'sanitize_text' ) ),
                        'chat.lead_phone_label'    => array( 'cb' => array( 'Haa_Settings', 'sanitize_text' ) ),
                        'chat.lead_consent_text'   => array( 'cb' => array( 'Haa_Settings', 'sanitize_textarea' ) ),
                        'chat.contact_whatsapp'    => array( 'cb' => array( 'Haa_Settings', 'sanitize_url' ) ),
                        'chat.contact_telegram'    => array( 'cb' => array( 'Haa_Settings', 'sanitize_url' ) ),
                        'chat.contact_instagram'   => array( 'cb' => array( 'Haa_Settings', 'sanitize_url' ) ),
                        'chat.contact_phone'       => array( 'cb' => array( 'Haa_Settings', 'sanitize_text' ) ),
                        'chat.contact_page'        => array( 'cb' => array( 'Haa_Settings', 'sanitize_url' ) ),
                        'chat.contact_note'        => array( 'cb' => array( 'Haa_Settings', 'sanitize_textarea' ) ),

                        // Site knowledge crawler («دانش سایت»).
                        'knowledge.urls'           => array( 'cb' => array( 'Haa_Settings', 'sanitize_page_urls' ) ),
                        'knowledge.auto_crawl'     => array( 'cb' => array( 'Haa_Settings', 'sanitize_bool' ) ),
                        'knowledge.max_pages'      => array( 'cb' => array( 'Haa_Settings', 'sanitize_int' ), 'min' => 5, 'max' => 2500 ),
                        'knowledge.crawl_types'    => array( 'cb' => array( 'Haa_Settings', 'sanitize_crawl_types' ) ),
                        // 2.1.23 — AEO toolkit toggles.
                        'knowledge.llms_enabled'   => array( 'cb' => array( 'Haa_Settings', 'sanitize_bool' ) ),
                        'knowledge.bots_enabled'   => array( 'cb' => array( 'Haa_Settings', 'sanitize_bool' ) ),
                        'knowledge.links_enabled'  => array( 'cb' => array( 'Haa_Settings', 'sanitize_bool' ) ),
                        'chat.tone'                => array( 'cb' => array( 'Haa_Settings', 'sanitize_choice' ), 'choices' => array( 'default', 'formal', 'friendly', 'playful' ) ),
                        'chat.page_context'        => array( 'cb' => array( 'Haa_Settings', 'sanitize_bool' ) ),
                        'chat.custom_knowledge'    => array( 'cb' => array( 'Haa_Settings', 'sanitize_textarea' ) ),
                        'chat.handoff_enabled'     => array( 'cb' => array( 'Haa_Settings', 'sanitize_bool' ) ),
                        'chat.handoff_after'       => array( 'cb' => array( 'Haa_Settings', 'sanitize_int' ), 'min' => 0, 'max' => 10 ),
                        'chat.notify_bale_token'   => array( 'cb' => array( 'Haa_Settings', 'sanitize_text' ) ),
                        'chat.notify_bale_chat'    => array( 'cb' => array( 'Haa_Settings', 'sanitize_text' ) ),
                        'chat.notify_tg_token'     => array( 'cb' => array( 'Haa_Settings', 'sanitize_text' ) ),
                        'chat.notify_tg_chat'      => array( 'cb' => array( 'Haa_Settings', 'sanitize_text' ) ),

                        // Content core.
                        'content.default_language' => array( 'cb' => array( 'Haa_Settings', 'sanitize_choice' ), 'choices' => array( 'fa', 'en', 'ar', 'tr', 'de', 'fr', 'ru', 'es' ) ),
                        'content.default_tone'     => array( 'cb' => array( 'Haa_Settings', 'sanitize_choice' ), 'choices' => array( 'friendly', 'professional', 'formal', 'excited', 'technical' ) ),
                        'content.default_length'   => array( 'cb' => array( 'Haa_Settings', 'sanitize_choice' ), 'choices' => array( 'short', 'medium', 'long', 'xlong' ) ),
                        'content.default_audience' => array( 'cb' => array( 'Haa_Settings', 'sanitize_text' ) ),
                        'content.enable_images'    => array( 'cb' => array( 'Haa_Settings', 'sanitize_bool' ) ),
                        'content.image_size'       => array( 'cb' => array( 'Haa_Settings', 'sanitize_choice' ), 'choices' => array( '1024x1024', '1792x1024', '1024x1792', '512x512' ) ),
                        'content.image_style'      => array( 'cb' => array( 'Haa_Settings', 'sanitize_text' ) ),
                        'content.image_quality'    => array( 'cb' => array( 'Haa_Settings', 'sanitize_choice' ), 'choices' => array( 'standard', 'hd' ) ),
                        'content.internal_links'   => array( 'cb' => array( 'Haa_Settings', 'sanitize_bool' ) ),
                        'content.link_count'       => array( 'cb' => array( 'Haa_Settings', 'sanitize_int' ), 'min' => 0, 'max' => 10 ),
                        'content.include_price'    => array( 'cb' => array( 'Haa_Settings', 'sanitize_bool' ) ),

                        // Article writer.
                        'article.default_status' => array( 'cb' => array( 'Haa_Settings', 'sanitize_choice' ), 'choices' => array( 'draft', 'pending', 'publish' ) ),
                        'article.default_author' => array( 'cb' => array( 'Haa_Settings', 'sanitize_int' ), 'min' => 0, 'max' => 999999 ),
                        'article.batch_size'     => array( 'cb' => array( 'Haa_Settings', 'sanitize_int' ), 'min' => 1, 'max' => 20 ),
                        'article.daily_token_cap'   => array( 'cb' => array( 'Haa_Settings', 'sanitize_int' ), 'min' => 0, 'max' => 50000000 ),
                        'article.daily_article_cap' => array( 'cb' => array( 'Haa_Settings', 'sanitize_int' ), 'min' => 0, 'max' => 50 ),
                        'article.dup_threshold'  => array( 'cb' => array( 'Haa_Settings', 'sanitize_float' ), 'min' => 0.1, 'max' => 1 ),
                        'article.plan_count'     => array( 'cb' => array( 'Haa_Settings', 'sanitize_int' ), 'min' => 1, 'max' => 20 ),

                        // Staged pipeline (D3).
                        'article.publish_mode'    => array( 'cb' => array( 'Haa_Settings', 'sanitize_choice' ), 'choices' => array( 'draft', 'review', 'auto' ) ),
                        'article.target_words'    => array( 'cb' => array( 'Haa_Settings', 'sanitize_int' ), 'min' => 600, 'max' => 5000 ),
                        'article.max_sections'    => array( 'cb' => array( 'Haa_Settings', 'sanitize_int' ), 'min' => 3, 'max' => 16 ),
                        'article.inline_images'   => array( 'cb' => array( 'Haa_Settings', 'sanitize_int' ), 'min' => 0, 'max' => 3 ),
                        'article.faq_schema'      => array( 'cb' => array( 'Haa_Settings', 'sanitize_bool' ) ),
                        'article.link_cap'        => array( 'cb' => array( 'Haa_Settings', 'sanitize_int' ), 'min' => 0, 'max' => 16 ),
                        'article.gate_word_ratio' => array( 'cb' => array( 'Haa_Settings', 'sanitize_int' ), 'min' => 30, 'max' => 100 ),
                        'article.flag_claims'     => array( 'cb' => array( 'Haa_Settings', 'sanitize_bool' ) ),

                        // Monthly refresh (D4).
                        'article.refresh_enabled' => array( 'cb' => array( 'Haa_Settings', 'sanitize_bool' ) ),
                        'article.refresh_days'    => array( 'cb' => array( 'Haa_Settings', 'sanitize_int' ), 'min' => 30, 'max' => 365 ),
                        'article.refresh_mode'    => array( 'cb' => array( 'Haa_Settings', 'sanitize_choice' ), 'choices' => array( 'suggest', 'draft' ) ),

                        // News module.
                        'news.enabled'              => array( 'cb' => array( 'Haa_Settings', 'sanitize_bool' ) ),
                        'news.feeds'                => array( 'cb' => array( 'Haa_Settings', 'sanitize_url_lines' ) ),
                        'news.max_per_run'          => array( 'cb' => array( 'Haa_Settings', 'sanitize_int' ), 'min' => 1, 'max' => 20 ),
                        'news.daily_cap'            => array( 'cb' => array( 'Haa_Settings', 'sanitize_int' ), 'min' => 0, 'max' => 50 ),
                        'news.lookback_hours'       => array( 'cb' => array( 'Haa_Settings', 'sanitize_int' ), 'min' => 1, 'max' => 168 ),
                        'news.default_status'       => array( 'cb' => array( 'Haa_Settings', 'sanitize_choice' ), 'choices' => array( 'draft', 'pending' ) ),
                        'news.default_category'     => array( 'cb' => array( 'Haa_Settings', 'sanitize_int' ), 'min' => 0, 'max' => 999999 ),
                        'news.min_words'            => array( 'cb' => array( 'Haa_Settings', 'sanitize_int' ), 'min' => 150, 'max' => 2000 ),
                        'news.quote_source'         => array( 'cb' => array( 'Haa_Settings', 'sanitize_bool' ) ),

                        // Video-article module.
                        'video.enabled'             => array( 'cb' => array( 'Haa_Settings', 'sanitize_bool' ) ),
                        'video.min_chars'           => array( 'cb' => array( 'Haa_Settings', 'sanitize_int' ), 'min' => 200, 'max' => 20000 ),
                        'video.max_words'           => array( 'cb' => array( 'Haa_Settings', 'sanitize_int' ), 'min' => 300, 'max' => 3000 ),
                        'video.default_status'      => array( 'cb' => array( 'Haa_Settings', 'sanitize_choice' ), 'choices' => array( 'draft', 'pending' ) ),
                        'video.default_category'    => array( 'cb' => array( 'Haa_Settings', 'sanitize_int' ), 'min' => 0, 'max' => 999999 ),
                        'video.fetch_captions'      => array( 'cb' => array( 'Haa_Settings', 'sanitize_bool' ) ),

                        // Programmatic module. The three guards against thin content all have
                        // conservative defaults and none of them may be turned off by an 0.
                        'programmatic.enabled'          => array( 'cb' => array( 'Haa_Settings', 'sanitize_bool' ) ),
                        'programmatic.source'           => array( 'cb' => array( 'Haa_Settings', 'sanitize_choice' ), 'choices' => array( 'csv', 'attribute', 'manual' ) ),
                        'programmatic.attribute'        => array( 'cb' => array( 'Haa_Settings', 'sanitize_text' ) ),
                        'programmatic.template'         => array( 'cb' => array( 'Haa_Settings', 'sanitize_textarea' ) ),
                        'programmatic.title_template'   => array( 'cb' => array( 'Haa_Settings', 'sanitize_text' ) ),
                        'programmatic.min_words'        => array( 'cb' => array( 'Haa_Settings', 'sanitize_int' ), 'min' => 200, 'max' => 2000 ),
                        'programmatic.similar_threshold' => array( 'cb' => array( 'Haa_Settings', 'sanitize_float' ), 'min' => 0.3, 'max' => 1 ),
                        'programmatic.daily_cap'        => array( 'cb' => array( 'Haa_Settings', 'sanitize_int' ), 'min' => 0, 'max' => 200 ),
                        'programmatic.hub_page'         => array( 'cb' => array( 'Haa_Settings', 'sanitize_int' ), 'min' => 0, 'max' => 999999 ),
                        'programmatic.noindex'          => array( 'cb' => array( 'Haa_Settings', 'sanitize_bool' ) ),
                        'programmatic.default_status'   => array( 'cb' => array( 'Haa_Settings', 'sanitize_choice' ), 'choices' => array( 'draft', 'pending' ) ),

                        // Site profile. Free text the admin owns, so the only limit is
                        // length; nothing here is ever echoed to a visitor.
                        'profile.enabled'   => array( 'cb' => array( 'Haa_Settings', 'sanitize_bool' ) ),
                        'profile.domain'    => array( 'cb' => array( 'Haa_Settings', 'sanitize_text' ) ),
                        'profile.audience'  => array( 'cb' => array( 'Haa_Settings', 'sanitize_text' ) ),
                        'profile.voice'     => array( 'cb' => array( 'Haa_Settings', 'sanitize_text' ) ),
                        'profile.offerings' => array( 'cb' => array( 'Haa_Settings', 'sanitize_textarea' ) ),
                        'profile.glossary'  => array( 'cb' => array( 'Haa_Settings', 'sanitize_textarea' ) ),
                        'profile.forbidden' => array( 'cb' => array( 'Haa_Settings', 'sanitize_textarea' ) ),
                        'profile.examples'  => array( 'cb' => array( 'Haa_Settings', 'sanitize_textarea' ) ),
                        'profile.key_pages' => array( 'cb' => array( 'Haa_Settings', 'sanitize_id_list' ) ),
                        'profile.max_chars' => array( 'cb' => array( 'Haa_Settings', 'sanitize_int' ), 'min' => 200, 'max' => 4000 ),

                        // Competitor / SERP research. Every numeric bound here is a politeness
                        // or safety ceiling, so none of them may be set to zero by a stray paste.
                        'research.enabled'        => array( 'cb' => array( 'Haa_Settings', 'sanitize_bool' ) ),
                        'research.competitors'    => array( 'cb' => array( 'Haa_Settings', 'sanitize_url_list' ) ),
                        'research.user_agent'     => array( 'cb' => array( 'Haa_Settings', 'sanitize_text' ) ),
                        'research.respect_robots' => array( 'cb' => array( 'Haa_Settings', 'sanitize_bool' ) ),
                        'research.delay_ms'       => array( 'cb' => array( 'Haa_Settings', 'sanitize_int' ), 'min' => 200, 'max' => 10000 ),
                        'research.timeout'        => array( 'cb' => array( 'Haa_Settings', 'sanitize_int' ), 'min' => 2, 'max' => 30 ),
                        'research.max_pages'      => array( 'cb' => array( 'Haa_Settings', 'sanitize_int' ), 'min' => 1, 'max' => 20 ),
                        'research.max_kb'         => array( 'cb' => array( 'Haa_Settings', 'sanitize_int' ), 'min' => 40, 'max' => 2000 ),
                        'research.cache_hours'    => array( 'cb' => array( 'Haa_Settings', 'sanitize_int' ), 'min' => 1, 'max' => 720 ),
                        'research.serp_enabled'   => array( 'cb' => array( 'Haa_Settings', 'sanitize_bool' ) ),
                        'research.fallback_ai'    => array( 'cb' => array( 'Haa_Settings', 'sanitize_bool' ) ),
                        'research.suggest_enabled' => array( 'cb' => array( 'Haa_Settings', 'sanitize_bool' ) ),
                        'research.serp_kind'      => array( 'cb' => array( 'Haa_Settings', 'sanitize_choice' ), 'choices' => array( 'serper', 'brave', 'searxng' ) ),
                        'research.serp_base_url'  => array( 'cb' => array( 'Haa_Settings', 'sanitize_url' ) ),
                        'research.serp_key'       => array( 'cb' => array( 'Haa_Settings', 'sanitize_secret' ) ),

                        // Autopilot.
                        'autopilot.daily_token_cap'  => array( 'cb' => array( 'Haa_Settings', 'sanitize_int' ), 'min' => 0, 'max' => 10000000 ),
                        'autopilot.daily_post_cap'   => array( 'cb' => array( 'Haa_Settings', 'sanitize_int' ), 'min' => 1, 'max' => 20 ),
                        'autopilot.lock_ttl'         => array( 'cb' => array( 'Haa_Settings', 'sanitize_int' ), 'min' => 60, 'max' => 86400 ),
                        'autopilot.retry_limit'      => array( 'cb' => array( 'Haa_Settings', 'sanitize_int' ), 'min' => 0, 'max' => 5 ),
                        // autopilot.cron_key removed: no real-cron endpoint exists.
                        'autopilot.default_status'   => array( 'cb' => array( 'Haa_Settings', 'sanitize_choice' ), 'choices' => array( 'draft', 'pending' ) ),
                        // autopilot.default_language removed: run_job reads content.default_language instead.
                        'autopilot.default_tone'     => array( 'cb' => array( 'Haa_Settings', 'sanitize_choice' ), 'choices' => array( 'friendly', 'professional', 'formal', 'excited', 'technical' ) ),
                        'autopilot.default_length'   => array( 'cb' => array( 'Haa_Settings', 'sanitize_choice' ), 'choices' => array( 'short', 'medium', 'long', 'xlong' ) ),
                        'autopilot.enable_images'    => array( 'cb' => array( 'Haa_Settings', 'sanitize_bool' ) ),
                        'autopilot.default_category' => array( 'cb' => array( 'Haa_Settings', 'sanitize_int' ), 'min' => 0, 'max' => 999999 ),

                        // Leads.
                        'leads.retention_days' => array( 'cb' => array( 'Haa_Settings', 'sanitize_int' ), 'min' => 7, 'max' => 3650 ),
                        'leads.stages'         => array( 'cb' => array( 'Haa_Settings', 'sanitize_stages' ) ),
                        'leads.csv_enabled'    => array( 'cb' => array( 'Haa_Settings', 'sanitize_bool' ) ),
                        'leads.ai_summary'     => array( 'cb' => array( 'Haa_Settings', 'sanitize_bool' ) ),
                        'leads.score_phone'    => array( 'cb' => array( 'Haa_Settings', 'sanitize_int' ), 'min' => 0, 'max' => 100 ),
                        'leads.score_message'  => array( 'cb' => array( 'Haa_Settings', 'sanitize_int' ), 'min' => 0, 'max' => 100 ),
                        'leads.score_buying'   => array( 'cb' => array( 'Haa_Settings', 'sanitize_int' ), 'min' => 0, 'max' => 100 ),
                        'leads.auto_create'    => array( 'cb' => array( 'Haa_Settings', 'sanitize_bool' ) ),

                        // Discount.
                        'discount.enabled'             => array( 'cb' => array( 'Haa_Settings', 'sanitize_bool' ) ),
                        'discount.max_rounds'          => array( 'cb' => array( 'Haa_Settings', 'sanitize_int' ), 'min' => 1, 'max' => 10 ),
                        'discount.global_max_percent'  => array( 'cb' => array( 'Haa_Settings', 'sanitize_int' ), 'min' => 0, 'max' => 100 ),
                        'discount.default_max_percent' => array( 'cb' => array( 'Haa_Settings', 'sanitize_int' ), 'min' => 0, 'max' => 100 ),
                        'discount.per_visitor'         => array( 'cb' => array( 'Haa_Settings', 'sanitize_int' ), 'min' => 1, 'max' => 50 ),
                        'discount.rate_minutes'        => array( 'cb' => array( 'Haa_Settings', 'sanitize_int' ), 'min' => 1, 'max' => 1440 ),
                        'discount.coupon_expiry_days'  => array( 'cb' => array( 'Haa_Settings', 'sanitize_int' ), 'min' => 1, 'max' => 365 ),
                        'discount.min_cart'            => array( 'cb' => array( 'Haa_Settings', 'sanitize_int' ), 'min' => 0, 'max' => 100000000 ),
                        'discount.max_uses'            => array( 'cb' => array( 'Haa_Settings', 'sanitize_int' ), 'min' => 1, 'max' => 1000 ),

                        // Comments.
                        'comments.enabled'            => array( 'cb' => array( 'Haa_Settings', 'sanitize_bool' ) ),
                        'comments.author_name'        => array( 'cb' => array( 'Haa_Settings', 'sanitize_text' ) ),
                        'comments.author_email'       => array( 'cb' => array( 'Haa_Settings', 'sanitize_email' ) ),
                        'comments.default_status'     => array( 'cb' => array( 'Haa_Settings', 'sanitize_choice' ), 'choices' => array( 'pending', 'approved' ) ),
                        'comments.generate_questions' => array( 'cb' => array( 'Haa_Settings', 'sanitize_bool' ) ),
                        'comments.generate_answers'   => array( 'cb' => array( 'Haa_Settings', 'sanitize_bool' ) ),
                        'comments.max_per_run'        => array( 'cb' => array( 'Haa_Settings', 'sanitize_int' ), 'min' => 1, 'max' => 20 ),

                        // Semantic search.
                        'search.enabled'          => array( 'cb' => array( 'Haa_Settings', 'sanitize_bool' ) ),
                        'search.limit'            => array( 'cb' => array( 'Haa_Settings', 'sanitize_int' ), 'min' => 1, 'max' => 50 ),
                        'search.min_score'        => array( 'cb' => array( 'Haa_Settings', 'sanitize_float' ), 'min' => 0, 'max' => 10 ),
                        'search.semantic_weight'  => array( 'cb' => array( 'Haa_Settings', 'sanitize_float' ), 'min' => 0, 'max' => 1 ),
                        'search.keyword_weight'   => array( 'cb' => array( 'Haa_Settings', 'sanitize_float' ), 'min' => 0, 'max' => 1 ),
                        'search.include_products' => array( 'cb' => array( 'Haa_Settings', 'sanitize_bool' ) ),
                        'search.include_posts'    => array( 'cb' => array( 'Haa_Settings', 'sanitize_bool' ) ),
                        'search.include_pages'    => array( 'cb' => array( 'Haa_Settings', 'sanitize_bool' ) ),
                        'search.embeddings'       => array( 'cb' => array( 'Haa_Settings', 'sanitize_bool' ) ),
                        'search.hybrid'           => array( 'cb' => array( 'Haa_Settings', 'sanitize_bool' ) ),
                        'search.max_vectors'      => array( 'cb' => array( 'Haa_Settings', 'sanitize_int' ), 'min' => 100, 'max' => 100000 ),
                        'search.chunk_cap'        => array( 'cb' => array( 'Haa_Settings', 'sanitize_int' ), 'min' => 200, 'max' => 20000 ),

                        // Advanced / plumbing.
                        'advanced.rate_limit_count'        => array( 'cb' => array( 'Haa_Settings', 'sanitize_int' ), 'min' => 1, 'max' => 5000 ),
                        'advanced.rate_limit_minutes'      => array( 'cb' => array( 'Haa_Settings', 'sanitize_int' ), 'min' => 1, 'max' => 1440 ),
                        'advanced.token_ttl_minutes'       => array( 'cb' => array( 'Haa_Settings', 'sanitize_int' ), 'min' => 5, 'max' => 1440 ),
                        'advanced.max_message_chars'       => array( 'cb' => array( 'Haa_Settings', 'sanitize_int' ), 'min' => 200, 'max' => 8000 ),
                        'advanced.daily_request_cap'       => array( 'cb' => array( 'Haa_Settings', 'sanitize_int' ), 'min' => 0, 'max' => 1000000 ),
                        'advanced.daily_cap_message'       => array( 'cb' => array( 'Haa_Settings', 'sanitize_textarea' ) ),
                        'advanced.daily_user_cap'          => array( 'cb' => array( 'Haa_Settings', 'sanitize_int' ), 'min' => 0, 'max' => 1000000 ),
                        'advanced.daily_user_cap_message'  => array( 'cb' => array( 'Haa_Settings', 'sanitize_textarea' ) ),
                        'advanced.log_conversations'       => array( 'cb' => array( 'Haa_Settings', 'sanitize_bool' ) ),
                        'advanced.log_retention_days'      => array( 'cb' => array( 'Haa_Settings', 'sanitize_int' ), 'min' => 1, 'max' => 365 ),
                        'advanced.guest_access'            => array( 'cb' => array( 'Haa_Settings', 'sanitize_guest_access' ) ),
                        'advanced.login_required_message'  => array( 'cb' => array( 'Haa_Settings', 'sanitize_textarea' ) ),
                        'advanced.ip_header'               => array( 'cb' => array( 'Haa_Settings', 'sanitize_choice' ), 'choices' => array( '', 'CF-Connecting-IP', 'X-Forwarded-For', 'X-Real-IP', 'REMOTE_ADDR' ) ),
                        'advanced.enable_cache'            => array( 'cb' => array( 'Haa_Settings', 'sanitize_bool' ) ),
                        'advanced.cache_ttl'               => array( 'cb' => array( 'Haa_Settings', 'sanitize_int' ), 'min' => 60, 'max' => 604800 ),
                        'advanced.enable_rag'              => array( 'cb' => array( 'Haa_Settings', 'sanitize_bool' ) ),
                        'advanced.kb_enabled'              => array( 'cb' => array( 'Haa_Settings', 'sanitize_bool' ) ),
                        'advanced.kb_post_types'           => array( 'cb' => array( 'Haa_Settings', 'sanitize_key_list' ) ),
                        'advanced.kb_categories'           => array( 'cb' => array( 'Haa_Settings', 'sanitize_id_list' ) ),
                        'advanced.kb_chunk_words'          => array( 'cb' => array( 'Haa_Settings', 'sanitize_int' ), 'min' => 200, 'max' => 800 ),
                        'advanced.kb_overlap_words'        => array( 'cb' => array( 'Haa_Settings', 'sanitize_int' ), 'min' => 0, 'max' => 200 ),
                        'advanced.kb_max_file_kb'          => array( 'cb' => array( 'Haa_Settings', 'sanitize_int' ), 'min' => 64, 'max' => 10240 ),
                        'advanced.usage_retention'         => array( 'cb' => array( 'Haa_Settings', 'sanitize_int' ), 'min' => 7, 'max' => 3650 ),
                );

                // Module on/off flags: derived from the catalogue, never a hand list —
                // a slug missing here is dropped by sanitize_section() on every save.
                foreach ( self::module_slugs() as $slug ) {
                        $m[ 'modules.' . $slug ] = array( 'cb' => array( 'Haa_Settings', 'sanitize_bool' ) );
                }

                return $m;
        }

        /* ------------------------------------------------------------------
         * Array helpers
         * ---------------------------------------------------------------- */

        /**
         * @param array  $data   Nested array.
         * @param string $path   Dot path.
         * @return mixed|null
         */
        public static function path_get( $data, $path ) {
                $cursor = $data;
                foreach ( explode( '.', $path ) as $segment ) {
                        if ( ! is_array( $cursor ) || ! array_key_exists( $segment, $cursor ) ) {
                                return null;
                        }
                        $cursor = $cursor[ $segment ];
                }
                return $cursor;
        }

        /**
         * @param array  $data   Nested array (by reference).
         * @param string $path   Dot path.
         * @param mixed  $value  Value to store.
         */
        public static function path_set( &$data, $path, $value ) {
                $segments = explode( '.', $path );
                $cursor   =& $data;
                foreach ( $segments as $i => $segment ) {
                        if ( $i === count( $segments ) - 1 ) {
                                $cursor[ $segment ] = $value;
                                return;
                        }
                        if ( ! isset( $cursor[ $segment ] ) || ! is_array( $cursor[ $segment ] ) ) {
                                $cursor[ $segment ] = array();
                        }
                        $cursor =& $cursor[ $segment ];
                }
        }

        /**
         * Saved values win, missing keys are filled from defaults.
         *
         * @param array $defaults Defaults.
         * @param array $saved    Saved.
         * @return array
         */
        private static function merge( $defaults, $saved ) {
                foreach ( $defaults as $key => $value ) {
                        if ( ! array_key_exists( $key, $saved ) ) {
                                $saved[ $key ] = $value;
                                continue;
                        }
                        // Recurse only into associative sections (e.g. 'provider' => array()).
                        // Numeric lists (whitelists of post types, pages, stages) are replaced
                        // wholesale so an empty admin choice stays empty.
                        if ( is_array( $value ) && self::is_assoc( $value ) && is_array( $saved[ $key ] ) ) {
                                $saved[ $key ] = self::merge( $value, $saved[ $key ] );
                        } elseif ( is_array( $value ) && ! self::is_assoc( $value ) && ! is_array( $saved[ $key ] ) ) {
                                $saved[ $key ] = $value;
                        }
                }
                return $saved;
        }

        /**
         * @param array $array Array.
         * @return bool
         */
        private static function is_assoc( $array ) {
                if ( array() === $array ) {
                        return false;
                }
                return array_keys( $array ) !== range( 0, count( $array ) - 1 );
        }

        /* ------------------------------------------------------------------
         * Crypto
         * ---------------------------------------------------------------- */

        /**
         * @return string 32 raw bytes.
         */
        private static function key() {
                return substr( hash( 'sha256', wp_salt( 'haai_key' ), true ), 0, 32 );
        }

        /**
         * @param string $plain Plaintext secret.
         * @return string
         */
        public static function encrypt( $plain ) {
                if ( '' === $plain || self::KEY_MASK === $plain ) {
                        return $plain;
                }
                if ( 0 === strpos( $plain, 'haaienc:' ) ) {
                        return $plain;
                }
                if ( ! function_exists( 'openssl_encrypt' ) ) {
                        return 'haaienc:' . base64_encode( $plain );
                }
                $iv = openssl_random_pseudo_bytes( 16 );
                if ( false === $iv ) {
                        return 'haaienc:' . base64_encode( $plain );
                }
                $enc = openssl_encrypt( $plain, 'aes-256-cbc', self::key(), OPENSSL_RAW_DATA, $iv );
                if ( false === $enc ) {
                        return 'haaienc:' . base64_encode( $plain );
                }
                return 'haaienc:' . base64_encode( $iv . $enc );
        }

        /**
         * @param string $raw Stored value.
         * @return string
         */
        public static function decrypt( $raw ) {
                if ( '' === $raw ) {
                        return '';
                }
                if ( 0 !== strpos( $raw, 'haaienc:' ) ) {
                        return $raw; // Legacy plaintext from an unencrypted install.
                }
                $payload = base64_decode( substr( $raw, 8 ), true );
                if ( false === $payload || strlen( $payload ) < 17 ) {
                        return '';
                }
                if ( ! function_exists( 'openssl_decrypt' ) ) {
                        add_action(
                                'admin_notices',
                                function () {
                                        echo '<div class="notice notice-warning"><p>' . esc_html__( 'رمزنگاری در دسترس نیست، کلید API به صورت متنی ذخیره شده', 'hami-ai-assistant' ) . '</p></div>';
                                }
                        );
                        return base64_decode( substr( $raw, 8 ), true );
                }
                $out = openssl_decrypt(
                        substr( $payload, 16 ),
                        'aes-256-cbc',
                        self::key(),
                        OPENSSL_RAW_DATA,
                        substr( $payload, 0, 16 )
                );
                return false === $out ? '' : $out;
        }

        /**
         * Show only the last four characters of a secret.
         *
         * @param string $raw Stored (possibly encrypted) value.
         * @return string
         */
        public static function mask( $raw ) {
                $plain = self::decrypt( $raw );
                if ( '' === $plain ) {
                        return '';
                }
                if ( strlen( $plain ) <= 4 ) {
                        return str_repeat( '•', 8 );
                }
                return '••••' . substr( $plain, -4 );
        }

        /* ------------------------------------------------------------------
         * Sanitizers
         * ---------------------------------------------------------------- */

        /** @return bool */
        public static function sanitize_bool( $raw ) {
                return empty( $raw ) ? 0 : 1;
        }

        /** @return string */
        public static function sanitize_choice( $raw, $default, $rule ) {
                $raw = is_scalar( $raw ) ? (string) $raw : '';
                return in_array( $raw, $rule['choices'], true ) ? $raw : (string) $default;
        }

        /** @return int */
        public static function sanitize_int( $raw, $default, $rule ) {
                $min = isset( $rule['min'] ) ? (int) $rule['min'] : 0;
                $max = isset( $rule['max'] ) ? (int) $rule['max'] : PHP_INT_MAX;
                $v   = is_scalar( $raw ) ? (int) $raw : (int) $default;
                return max( $min, min( $max, $v ) );
        }

        /** @return float */
        public static function sanitize_float( $raw, $default, $rule ) {
                $min = isset( $rule['min'] ) ? (float) $rule['min'] : 0;
                $max = isset( $rule['max'] ) ? (float) $rule['max'] : PHP_FLOAT_MAX;
                $v   = is_scalar( $raw ) ? (float) $raw : (float) $default;
                return max( $min, min( $max, $v ) );
        }

        /** @return string */
        public static function sanitize_text( $raw ) {
                $raw = is_scalar( $raw ) ? (string) $raw : '';
                return sanitize_text_field( $raw );
        }

        /** @return string */
        public static function sanitize_model( $raw ) {
                $raw = is_scalar( $raw ) ? (string) $raw : '';
                $raw = sanitize_text_field( $raw );
                return preg_replace( '/[^A-Za-z0-9._\/:-]/', '', $raw );
        }

        /** @return string */
        public static function sanitize_textarea( $raw ) {
                $raw = is_scalar( $raw ) ? (string) $raw : '';
                return trim( wp_kses_post( $raw ) );
        }

        /** @return string */
        public static function sanitize_html( $raw ) {
                $raw = is_scalar( $raw ) ? (string) $raw : '';
                $allowed = '<p><br><strong><em><ul><ol><li><a><h2><h3><span>';
                return trim( wp_kses( $raw, $allowed ) );
        }

        /** @return string */
        public static function sanitize_email( $raw ) {
                $raw = is_scalar( $raw ) ? (string) $raw : '';
                return sanitize_email( $raw );
        }

        /** @return string */
        public static function sanitize_url( $raw, $default = '' ) {
                $raw = is_scalar( $raw ) ? trim( (string) $raw ) : '';
                if ( '' === $raw ) {
                        return (string) $default;
                }

                $url = esc_url_raw( $raw, array( 'http', 'https' ) );
                if ( '' === $url ) {
                        return '';
                }

                $parsed = parse_url( $url );
                if ( ! $parsed || empty( $parsed['host'] ) ) {
                        return '';
                }

                // Save-time validation is format-only. SSRF checks (private IP,
                // localhost) run at actual call time in Haa_API with a short cache.
                return $url;
        }

        /** @return string */
        public static function sanitize_host( $raw ) {
                $raw = is_scalar( $raw ) ? (string) $raw : '';
                return preg_replace( '/[^a-zA-Z0-9\.\-_:]/', '', $raw );
        }

        /** @return string */
        public static function sanitize_color( $raw, $default = '#000000' ) {
                $raw = is_scalar( $raw ) ? trim( (string) $raw ) : '';
                if ( preg_match( '/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', $raw ) ) {
                        return strtolower( $raw );
                }
                return (string) $default;
        }

        /** @return string */
        public static function sanitize_time( $raw, $default = '09:00' ) {
                $raw = is_scalar( $raw ) ? trim( (string) $raw ) : '';
                return preg_match( '/^([01]?\d|2[0-3]):[0-5]\d$/', $raw ) ? $raw : (string) $default;
        }

        /** @return array */
        public static function sanitize_id_list( $raw ) {
                if ( ! is_array( $raw ) ) {
                        return array();
                }
                $out = array();
                foreach ( $raw as $id ) {
                        $id = (int) $id;
                        if ( $id > 0 ) {
                                $out[] = $id;
                        }
                }
                return array_values( array_unique( array_slice( $out, 0, 500 ) ) );
        }

        /**
         * Newline-separated list of http(s) URLs, deduped by host, capped.
         *
         * Kept as a string (not an array) because the field is a textarea and
         * Haa_Research::competitors() splits it again. Only the scheme/host is
         * trusted here; the SSRF decision is made at request time in Haa_API,
         * because a domain that is public today can point at 127.0.0.1 tomorrow.
         *
         * @param mixed $raw Textarea value or array.
         * @return string
         */
        public static function sanitize_url_list( $raw ) {
                if ( ! is_array( $raw ) ) {
                        $raw = preg_split( '/[,\r\n]+/u', (string) $raw );
                }

                $out   = array();
                $hosts = array();
                foreach ( (array) $raw as $line ) {
                        $line = trim( (string) $line );
                        if ( '' === $line ) {
                                continue;
                        }
                        // Schemes we do not crawl are dropped rather than rewritten.
                        if ( preg_match( '#^(mailto|tel|sms|javascript|data|file|ftp|sftp|ssh|telnet|gopher|ws|wss):#i', $line ) ) {
                                continue;
                        }
                        if ( ! preg_match( '#^https?://#i', $line ) ) {
                                $line = 'https://' . ltrim( $line, '/' );
                        }
                        $url = self::sanitize_url( $line );
                        if ( '' === $url ) {
                                continue;
                        }
                        $host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
                        if ( '' === $host || isset( $hosts[ $host ] ) ) {
                                continue;
                        }
                        $hosts[ $host ] = true;
                        $out[]          = $host;
                        if ( count( $out ) >= 10 ) {
                                break;
                        }
                }

                return implode( "\n", $out );
        }

        /**
         * Newline-separated feed URLs, kept whole.
         *
         * Different from sanitize_url_list(): a feed address needs its path and
         * query (/feed/, ?type=rss), while a competitor host must be reduced to its
         * origin. Cap is 12 feeds, which is already more than any sane blog polls.
         *
         * @param mixed $raw Textarea value.
         * @return string
         */
        public static function sanitize_url_lines( $raw ) {
                if ( ! is_array( $raw ) ) {
                        $raw = preg_split( '/\R+/u', (string) $raw );
                }

                $out = array();
                foreach ( (array) $raw as $line ) {
                        $line = trim( (string) $line );
                        if ( '' === $line || preg_match( '#^(mailto|tel|sms|javascript|data|file|ftp|sftp|ssh|telnet|gopher|ws|wss):#i', $line ) ) {
                                continue;
                        }
                        if ( ! preg_match( '#^https?://#i', $line ) ) {
                                $line = 'https://' . ltrim( $line, '/' );
                        }
                        $url = self::sanitize_url( $line );
                        if ( '' === $url || in_array( $url, $out, true ) ) {
                                continue;
                        }
                        $out[] = $url;
                        if ( count( $out ) >= 12 ) {
                                break;
                        }
                }

                return implode( "\n", $out );
        }

        /**
         * Newline-separated PAGE URLs, kept whole (path included), deduped by
         * canonical form, capped at 50 pages.
         *
         * Different from sanitize_url_lines() (feeds: 12, query allowed) and
         * sanitize_url_list() (competitor HOSTS only): the knowledge crawler
         * needs exact page addresses like /contact-us/, and only http(s).
         * SSRF decisions stay at call time (Haa_Crawl::safe_url), same policy
         * as every other fetch in this plugin.
         *
         * @param mixed $raw Textarea value.
         * @return string
         */
        public static function sanitize_page_urls( $raw ) {
                if ( ! is_array( $raw ) ) {
                        $raw = preg_split( '/\R+/u', (string) $raw );
                }

                $out = array();
                foreach ( (array) $raw as $line ) {
                        $line = trim( (string) $line );
                        if ( '' === $line || preg_match( '#^(mailto|tel|sms|javascript|data|file|ftp|sftp|ssh|telnet|gopher|ws|wss):#i', $line ) ) {
                                continue;
                        }
                        if ( ! preg_match( '#^https?://#i', $line ) ) {
                                $line = 'https://' . ltrim( $line, '/' );
                        }
                        $url = self::sanitize_url( $line );
                        if ( '' === $url ) {
                                continue;
                        }
                        $key = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) ) . rtrim( (string) wp_parse_url( $url, PHP_URL_PATH ), '/' );
                        if ( isset( $out[ $key ] ) ) {
                                continue;
                        }
                        $out[ $key ] = $url;
                        if ( count( $out ) >= 50 ) {
                                break;
                        }
                }

                return implode( "\n", array_values( $out ) );
        }

        /**
         * Which WP content types the crawler may queue. Whitelist + a sane floor:
         * an empty selection would silently crawl nothing, so it degrades to
         * pages only (the lightweight default the «دانش سایت» tab documents).
         *
         * @param mixed $raw Posted value (array of slugs).
         * @return array<int,string>
         */
        public static function sanitize_crawl_types( $raw ) {
                $allowed = array( 'page', 'product', 'post' );
                $out     = array();
                foreach ( (array) $raw as $slug ) {
                        $slug = sanitize_key( (string) $slug );
                        if ( in_array( $slug, $allowed, true ) ) {
                                $out[] = $slug;
                        }
                }
                $out = array_values( array_unique( $out ) );
                return $out ? $out : array( 'page' );
        }

        /** @return array */
        public static function sanitize_key_list( $raw ) {
                if ( ! is_array( $raw ) ) {
                        return array();
                }
                $out = array();
                foreach ( $raw as $key ) {
                        $key = sanitize_key( (string) $key );
                        if ( '' !== $key ) {
                                $out[] = $key;
                        }
                }
                return array_values( array_unique( array_slice( $out, 0, 100 ) ) );
        }

        /** @return array<int,string> */
        public static function sanitize_stages( $raw, $default = '' ) {
                $raw    = is_scalar( $raw ) ? (string) $raw : '';
                $lines  = preg_split( '/\R+/u', $raw );
                $out    = array();
                foreach ( $lines as $line ) {
                        $slug = sanitize_key( trim( $line ) );
                        if ( '' !== $slug ) {
                                $out[] = $slug;
                        }
                        if ( count( $out ) >= 20 ) {
                                break;
                        }
                }
                return $out ? $out : array( 'new', 'contacted', 'qualified', 'won', 'lost' );
        }

        /** @return int */
        public static function sanitize_guest_access( $raw ) {
                $raw = is_scalar( $raw ) ? (int) $raw : 0;
                return in_array( $raw, array( 0, 1, 2 ), true ) ? $raw : 0;
        }

        /**
         * Strip anything that would break out of a <style> block.
         *
         * @return string
         */
        public static function sanitize_css( $raw ) {
                $raw = is_scalar( $raw ) ? (string) $raw : '';
                $raw = preg_replace( '#</?style#i', '', $raw );
                $raw = str_ireplace( array( 'expression(', 'javascript:', 'vbscript:', 'data:' ), '', $raw );
                return trim( $raw );
        }

        /** @return string */
        public static function sanitize_secret( $raw ) {
                $raw = is_scalar( $raw ) ? (string) $raw : '';
                $raw = preg_replace( '/[\x00-\x1F\x7F]/', '', $raw );
                if ( '' === $raw || self::KEY_MASK === $raw ) {
                        return $raw;
                }
                return self::encrypt( $raw );
        }

        /**
         * Failover sources: one saved row per backup provider, max five.
         *
         * A row survives only when it names a preset or carries a base URL.
         * Keys are stored encrypted; a masked (untouched) field reuses the
         * stored key of the SAME row, matched by the row's stable hash — so
         * deleting or reordering rows can never copy a key onto the wrong
         * source.
         *
         * @param mixed $raw Raw provider.failovers array from the form.
         * @return array<int,array{provider:string,base_url:string,api_key:string,model:string,hash:string}>
         */
        public static function sanitize_failovers( $raw ) {
                $stored  = (array) self::get( 'provider.failovers', array() );
                $presets = Haa_API::presets();
                $out     = array();
                $seen    = array();

                foreach ( (array) $raw as $row ) {
                        if ( ! is_array( $row ) || count( $out ) >= 5 ) {
                                continue;
                        }

                        $provider = self::sanitize_text( isset( $row['provider'] ) ? $row['provider'] : '' );
                        if ( '' !== $provider && ! isset( $presets[ $provider ] ) ) {
                                $provider = '';
                        }
                        $base  = self::sanitize_url( isset( $row['base_url'] ) ? $row['base_url'] : '' );
                        $model = self::sanitize_model( isset( $row['model'] ) ? $row['model'] : '' );

                        $raw_key = isset( $row['api_key'] ) && is_scalar( $row['api_key'] ) ? (string) $row['api_key'] : '';
                        $raw_key = preg_replace( '/[\x00-\x1F\x7F]/', '', $raw_key );

                        $hash = '';
                        if ( isset( $row['hash'] ) && is_scalar( $row['hash'] ) && 1 === preg_match( '/^[a-f0-9]{32}$/', (string) $row['hash'] ) ) {
                                $hash = (string) $row['hash'];
                        }

                        // A completely empty row is the untouched template — drop it.
                        if ( '' === $provider && '' === $base && '' === $model && '' === $raw_key ) {
                                continue;
                        }

                        if ( '' === $raw_key || self::KEY_MASK === $raw_key ) {
                                // Untouched field: keep the stored key of this exact row.
                                $key = '';
                                foreach ( $stored as $s ) {
                                        if ( '' !== $hash && isset( $s['hash'], $s['api_key'] ) && $s['hash'] === $hash && '' !== $s['api_key'] ) {
                                                $key = (string) $s['api_key'];
                                                break;
                                        }
                                }
                        } elseif ( 0 === strpos( $raw_key, 'haaienc:' ) ) {
                                // Already-encrypted value (the sanitizer re-ran over stored
                                // data): keep both the key and the row's identity intact.
                                $key = $raw_key;
                        } else {
                                $key  = self::encrypt( $raw_key );
                                $hash = ''; // A typed key is a new secret; mint a fresh id below.
                        }

                        if ( '' === $hash ) {
                                $hash = md5( uniqid( 'haai-fb', true ) );
                        }
                        if ( isset( $seen[ $hash ] ) ) {
                                continue;
                        }
                        $seen[ $hash ] = true;

                        $out[] = array(
                                'provider' => $provider,
                                'base_url' => $base,
                                'api_key'  => $key,
                                'model'    => $model,
                                'hash'     => $hash,
                        );
                }

                return $out;
        }

        /**
         * Per-provider credential vault: one saved row per preset slug.
         *
         * Each row is the credential set of exactly ONE service — api_key
         * (encrypted), base_url and the models the admin picked for it. Rows
         * only exist for known presets; an unknown or empty row is dropped.
         * Keys follow the same round-trip as the failover rows: a masked
         * (untouched) field keeps the stored key of the SAME slug, an already
         * encrypted `haaienc:` value passes through (import), a typed value is
         * encrypted.
         *
         * The settings FORM never posts this key — the section save rebuilds
         * the vault server-side (provider_vault_write). This sanitizer exists
         * for the import path, where a full settings file may carry the vault.
         *
         * @param mixed $raw Raw provider.vault array.
         * @return array<string,array{api_key:string,base_url:string,model:string,embedding_model:string,image_model:string}>
         */
        public static function sanitize_vault( $raw ) {
                $stored  = (array) self::get( 'provider.vault', array() );
                $presets = Haa_API::presets();
                $out     = array();

                foreach ( (array) $raw as $slug => $row ) {
                        $slug = sanitize_key( (string) $slug );
                        if ( '' === $slug || ! isset( $presets[ $slug ] ) || ! is_array( $row ) ) {
                                continue;
                        }

                        $key_raw = isset( $row['api_key'] ) && is_scalar( $row['api_key'] ) ? (string) $row['api_key'] : '';
                        $key_raw = preg_replace( '/[\x00-\x1F\x7F]/', '', $key_raw );

                        if ( '' === $key_raw || self::KEY_MASK === $key_raw ) {
                                // Untouched field: keep the stored key of this exact slug.
                                $key = isset( $stored[ $slug ]['api_key'] ) ? (string) $stored[ $slug ]['api_key'] : '';
                        } elseif ( 0 === strpos( $key_raw, 'haaienc:' ) ) {
                                $key = $key_raw; // Already encrypted (import round-trip).
                        } else {
                                $key = self::encrypt( $key_raw );
                        }

                        $out[ $slug ] = array(
                                'api_key'         => $key,
                                'base_url'        => self::sanitize_url( isset( $row['base_url'] ) && is_scalar( $row['base_url'] ) ? (string) $row['base_url'] : '' ),
                                'model'           => self::sanitize_model( isset( $row['model'] ) && is_scalar( $row['model'] ) ? (string) $row['model'] : '' ),
                                'embedding_model' => self::sanitize_model( isset( $row['embedding_model'] ) && is_scalar( $row['embedding_model'] ) ? (string) $row['embedding_model'] : '' ),
                                'image_model'     => self::sanitize_model( isset( $row['image_model'] ) && is_scalar( $row['image_model'] ) ? (string) $row['image_model'] : '' ),
                        );

                        // A row with nothing in it is not worth remembering.
                        if ( '' === $out[ $slug ]['api_key'] && '' === $out[ $slug ]['base_url'] && '' === $out[ $slug ]['model']
                                && '' === $out[ $slug ]['embedding_model'] && '' === $out[ $slug ]['image_model'] ) {
                                unset( $out[ $slug ] );
                        }
                }

                return $out;
        }

        /**
         * Rebuild the per-provider vault during a provider-tab save and decide
         * what the ACTIVE provider slot ends up holding.
         *
         * Three things happen, in order:
         *
         *  1. Migration — whatever the single active slot holds today belongs
         *     to the STORED provider, so before anything is replaced it gets a
         *     home in the vault under that provider's slug. An install that
         *     upgrades keeps its Gemini key when it later switches to Grok.
         *  2. Coherence — the form may still carry the PREVIOUS provider's
         *     field values (a «سرویس» change without JavaScript). Those values
         *     are never written onto the new provider: the vault row (or empty
         *     fields) wins, exactly like the key below.
         *  3. Write-through — the posted provider's own row is written from the
         *     form: a typed key becomes the new secret, an untouched (masked)
         *     field restores THAT provider's saved key — never the previous
         *     provider's — and with no saved key the slot starts clean instead
         *     of silently reusing the old service's credentials.
         *
         * @param array $clean Sanitized provider section.
         * @param array $raw   Raw posted provider array.
         * @return array Provider section with `vault` rebuilt (and `api_key` /
         *               base_url / models corrected when the service changed).
         */
        public static function provider_vault_write( $clean, $raw ) {
                $presets = Haa_API::presets();
                $slug    = isset( $clean['provider'] ) && is_scalar( $clean['provider'] ) ? (string) $clean['provider'] : '';

                // The form never posts the vault; start from what is stored so the
                // other providers' rows survive the section replace untouched.
                $vault = (array) self::get( 'provider.vault', array() );

                $stored_slug = (string) self::get( 'provider.provider', '' );
                $stored_key  = (string) self::get( 'provider.api_key', '' ); // Encrypted form.

                // (1) Migration: give the current active credentials their own row.
                if ( '' !== $stored_slug && isset( $presets[ $stored_slug ] ) && '' !== $stored_key ) {
                        $have = isset( $vault[ $stored_slug ]['api_key'] ) ? (string) $vault[ $stored_slug ]['api_key'] : '';
                        if ( '' === $have ) {
                                $vault[ $stored_slug ] = array(
                                        'api_key'         => $stored_key,
                                        'base_url'        => (string) self::get( 'provider.base_url', '' ),
                                        'model'           => (string) self::get( 'provider.model', '' ),
                                        'embedding_model' => (string) self::get( 'provider.embedding_model', '' ),
                                        'image_model'     => (string) self::get( 'provider.image_model', '' ),
                                );
                        }
                }

                if ( ! isset( $presets[ $slug ] ) ) {
                        $clean['vault'] = $vault;
                        return $clean;
                }

                $raw_key = isset( $raw['api_key'] ) && is_scalar( $raw['api_key'] ) ? (string) $raw['api_key'] : '';
                $raw_key = preg_replace( '/[\x00-\x1F\x7F]/', '', $raw_key );

                // (2) Coherence: does the form actually hold THIS provider's values?
                // JS stamps provider[vault_from] with the slug it swapped in; without
                // JS the stamp still names the previous provider, so its base_url /
                // models must not bleed onto the new service.
                $coherent = $slug === $stored_slug
                        || ( isset( $raw['vault_from'] ) && is_scalar( $raw['vault_from'] ) && (string) $raw['vault_from'] === $slug );

                if ( ! $coherent ) {
                        $row = isset( $vault[ $slug ] ) && is_array( $vault[ $slug ] ) ? $vault[ $slug ] : array();
                        $clean['base_url']        = (string) ( isset( $row['base_url'] ) ? $row['base_url'] : '' );
                        $clean['model']           = (string) ( isset( $row['model'] ) ? $row['model'] : '' );
                        $clean['embedding_model'] = (string) ( isset( $row['embedding_model'] ) ? $row['embedding_model'] : '' );
                        $clean['image_model']     = (string) ( isset( $row['image_model'] ) ? $row['image_model'] : '' );
                }

                // (3) Key resolution for the provider the form just saved.
                if ( '' !== $raw_key && self::KEY_MASK !== $raw_key ) {
                        // A typed key is the new secret of this provider.
                        $row_key = (string) $clean['api_key']; // sanitize_secret already encrypted it.
                } elseif ( $slug === $stored_slug || '' === $stored_slug ) {
                        // Untouched field on the SAME provider: the active key stays.
                        $row_key = $stored_key;
                } elseif ( isset( $vault[ $slug ]['api_key'] ) && '' !== (string) $vault[ $slug ]['api_key'] ) {
                        // Switched provider, untouched field: restore that provider's own key.
                        $row_key = (string) $vault[ $slug ]['api_key'];
                } else {
                        // Switched to a service with no saved key: start clean rather
                        // than reusing the previous provider's credentials.
                        $row_key = '';
                }
                $clean['api_key'] = $row_key;

                $vault[ $slug ] = array(
                        'api_key'         => $row_key,
                        'base_url'        => (string) ( isset( $clean['base_url'] ) ? $clean['base_url'] : '' ),
                        'model'           => (string) ( isset( $clean['model'] ) ? $clean['model'] : '' ),
                        'embedding_model' => (string) ( isset( $clean['embedding_model'] ) ? $clean['embedding_model'] : '' ),
                        'image_model'     => (string) ( isset( $clean['image_model'] ) ? $clean['image_model'] : '' ),
                );

                $clean['vault'] = $vault;
                return $clean;
        }

        /**
         * The vault as the admin JS may see it: which service has a key (shown
         * through the same mask placeholder) plus its plain base_url/models.
         * The plaintext key itself never leaves the server.
         *
         * @return array<string,array{has_key:bool,api_key:string,base_url:string,model:string,embedding_model:string,image_model:string}>
         */
        public static function vault_for_js() {
                $out = array();
                foreach ( (array) self::get( 'provider.vault', array() ) as $slug => $row ) {
                        if ( ! is_array( $row ) ) {
                                continue;
                        }
                        $key          = isset( $row['api_key'] ) ? (string) $row['api_key'] : '';
                        $out[ $slug ] = array(
                                'has_key'         => '' !== $key,
                                'api_key'         => '' !== $key ? self::KEY_MASK : '',
                                'base_url'        => isset( $row['base_url'] ) ? (string) $row['base_url'] : '',
                                'model'           => isset( $row['model'] ) ? (string) $row['model'] : '',
                                'embedding_model' => isset( $row['embedding_model'] ) ? (string) $row['embedding_model'] : '',
                                'image_model'     => isset( $row['image_model'] ) ? (string) $row['image_model'] : '',
                        );
                }
                return $out;
        }

        /**
         * Stored failover rows → wire-ready configs. Presets fill the blanks
         * exactly like provider() fills the primary; a row without any preset
         * and without a base URL is skipped; a custom row speaks the OpenAI
         * wire format, because that is what every relay speaks. Keys decrypt.
         *
         * @param array $rows Sanitized rows (slug form) or already-resolved rows.
         * @return array<int,array{adapter:string,base_url:string,api_key:string,model:string,hash:string}>
         */
        public static function resolve_failover_rows( $rows ) {
                $presets = Haa_API::presets();
                $out     = array();

                foreach ( (array) $rows as $row ) {
                        if ( ! is_array( $row ) ) {
                                continue;
                        }
                        $slug  = (string) ( $row['provider'] ?? '' );
                        $base  = (string) ( $row['base_url'] ?? '' );
                        $model = (string) ( $row['model'] ?? '' );

                        if ( '' !== $slug && isset( $presets[ $slug ] ) ) {
                                if ( '' === $base ) {
                                        $base = $presets[ $slug ]['base_url'];
                                }
                                if ( '' === $model ) {
                                        $model = $presets[ $slug ]['model'];
                                }
                                $adapter = $presets[ $slug ]['adapter'];
                        } else {
                                $adapter = (string) ( $row['adapter'] ?? 'openai' );
                                if ( ! isset( Haa_API::adapters()[ $adapter ] ) ) {
                                        $adapter = 'openai';
                                }
                        }

                        if ( '' === $base ) {
                                continue;
                        }

                        $out[] = array(
                                'adapter'  => $adapter,
                                'base_url' => $base,
                                'api_key'  => self::decrypt( (string) ( $row['api_key'] ?? '' ) ),
                                'model'    => $model,
                                'hash'     => (string) ( $row['hash'] ?? '' ),
                        );
                }

                return $out;
        }

        /* ------------------------------------------------------------------
         * Diagnostics
         * ---------------------------------------------------------------- */

        /**
         * Compare defaults, sanitize_map and UI field keys. Returns a list of
         * inconsistencies. Admin-only diagnostic; no data is modified.
         *
         * @return array<int,array{section:string,key:string,issue:string}>
         */
        public static function diagnose() {
                $issues  = array();
                $defaults = self::defaults();
                $rules    = self::sanitize_map();

                // Flatten defaults to dotted keys.
                $flat = array();
                foreach ( $defaults as $section => $values ) {
                        if ( ! is_array( $values ) ) {
                                continue;
                        }
                        foreach ( $values as $key => $v ) {
                                $flat[ $section . '.' . $key ] = $v;
                        }
                }

                // 1. Every default key must have a sanitize rule (except modules.* which are handled separately).
                foreach ( $flat as $dotted => $v ) {
                        if ( 0 === strpos( $dotted, 'modules.' ) ) {
                                continue;
                        }
                        if ( ! isset( $rules[ $dotted ] ) ) {
                                $issues[] = array(
                                        'section' => explode( '.', $dotted )[0],
                                        'key'     => $dotted,
                                        'issue'   => 'کلید در defaults هست ولی قاعده sanitize ندارد → هنگام ذخیره حذف می‌شود.',
                                );
                        }
                }

                // 2. Every sanitize rule must have a matching default.
                foreach ( $rules as $dotted => $rule ) {
                        if ( ! isset( $flat[ $dotted ] ) ) {
                                $issues[] = array(
                                        'section' => explode( '.', $dotted )[0],
                                        'key'     => $dotted,
                                        'issue'   => 'قاعده sanitize هست ولی کلید در defaults نیست → مقدار ذخیره‌شده خوانده نمی‌شود.',
                                );
                        }
                }

                // 3. Every module in the catalogue must be settable. A slug missing
                //    from the defaults or the sanitize map is silently switched off
                //    (or dropped) the next time any settings screen is saved.
                foreach ( self::module_slugs() as $slug ) {
                        $dotted = 'modules.' . $slug;
                        if ( ! isset( $flat[ $dotted ] ) ) {
                                $issues[] = array(
                                        'section' => 'modules',
                                        'key'     => $dotted,
                                        'issue'   => 'ماژول در catalog هست ولی کلیدش در defaults نیست → با ذخیره‌ی تنظیمات خاموش می‌شود.',
                                );
                        }
                        if ( ! isset( $rules[ $dotted ] ) ) {
                                $issues[] = array(
                                        'section' => 'modules',
                                        'key'     => $dotted,
                                        'issue'   => 'ماژول در catalog هست ولی قاعده‌ی sanitize ندارد → مقدارش ذخیره نمی‌شود.',
                                );
                        }
                }

                // 4. UI fields (collected from core + modules).
                $ui_keys = self::collect_ui_keys();
                foreach ( $ui_keys as $dotted ) {
                        if ( ! isset( $flat[ $dotted ] ) ) {
                                $issues[] = array(
                                        'section' => explode( '.', $dotted )[0],
                                        'key'     => $dotted,
                                        'issue'   => 'فیلد UI هست ولی کلید در defaults نیست.',
                                );
                        }
                        if ( ! isset( $rules[ $dotted ] ) ) {
                                $issues[] = array(
                                        'section' => explode( '.', $dotted )[0],
                                        'key'     => $dotted,
                                        'issue'   => 'فیلد UI هست ولی قاعده sanitize ندارد → مقدار ذخیره نمی‌شود.',
                                );
                        }
                }

                // 5. Defaults keys that have sanitize but no UI field (informational).
                //
                // A handful of keys are deliberately technical: exposing them in the
                // settings screen would only let an admin wedge the plugin. They are
                // listed here so diagnose() reports them as intentional instead of as
                // an oversight, and each carries the reason it has no field.
                $intentional_no_ui = self::intentional_no_ui();

                // collect_ui_keys() only walks the tabs of ENABLED modules, so the keys
                // of a module that is switched off legitimately have no field. Skipping
                // them keeps a chatbot-only install from reporting dozens of fake
                // problems on the dashboard.
                $module_owners = self::module_owned_sections();

                foreach ( $flat as $dotted => $v ) {
                        if ( 0 === strpos( $dotted, 'modules.' ) ) {
                                continue;
                        }
                        if ( isset( $intentional_no_ui[ $dotted ] ) ) {
                                continue; // Documented as "no UI, on purpose".
                        }

                        $section = explode( '.', $dotted )[0];
                        if ( isset( $module_owners[ $section ] ) ) {
                                // Asked per owner rather than by set comparison, so a mirror
                                // that stores off flags cannot make a dead section look live.
                                $any_on = false;
                                foreach ( $module_owners[ $section ] as $owner ) {
                                        if ( self::is_module_enabled( $owner ) ) {
                                                $any_on = true;
                                                break;
                                        }
                                }
                                if ( ! $any_on ) {
                                        continue; // Owning module(s) are off; their fields are not rendered by design.
                                }
                        }

                        if ( ! in_array( $dotted, $ui_keys, true ) && isset( $rules[ $dotted ] ) ) {
                                $issues[] = array(
                                        'section' => explode( '.', $dotted )[0],
                                        'key'     => $dotted,
                                        'issue'   => 'کلید در defaults و sanitize هست ولی فیلد UI ندارد → از طریق رابط کاربری قابل تنظیم نیست.',
                                );
                        }
                }

                // 6. Catalogue ↔ metadata consistency: every slug in catalog() must
                //    have an entry in catalog_meta() and vice-versa. A mismatch means
                //    the Modules screen will show a blank label/description or a
                //    module will silently disappear.
                $catalog_slugs = array_keys( Haa_Modules::catalog() );
                $meta_slugs    = array_keys( Haa_Modules::catalog_meta() );

                $missing_meta = array_diff( $catalog_slugs, $meta_slugs );
                foreach ( $missing_meta as $slug ) {
                        $issues[] = array(
                                'section' => 'modules',
                                'key'     => $slug,
                                'issue'   => 'ماژول در catalog() هست ولی ورودی catalog_meta() ندارد → برچسب/توضیح در صفحه ماژول‌ها خالی می‌شود.',
                        );
                }

                $missing_catalog = array_diff( $meta_slugs, $catalog_slugs );
                foreach ( $missing_catalog as $slug ) {
                        $issues[] = array(
                                'section' => 'modules',
                                'key'     => $slug,
                                'issue'   => 'ورودی catalog_meta() هست ولی ماژول در catalog() نیست → داده بی‌استفاده و ممکن است به ماژول حذف‌شده اشاره کند.',
                        );
                }

                // 7. The Modules screen renders describe_all(); a slug that never
                //    reaches it is invisible (and un-toggleable) in the admin, and a
                //    duplicate would render the same card twice.
                $described = array();
                foreach ( (array) Haa_Modules::describe_all() as $row ) {
                        if ( isset( $row['slug'] ) ) {
                                $described[] = (string) $row['slug'];
                        }
                }
                foreach ( array_diff( $catalog_slugs, $described ) as $slug ) {
                        $issues[] = array(
                                'section' => 'modules',
                                'key'     => $slug,
                                'issue'   => 'ماژول در catalog() هست ولی در فهرست صفحه‌ی ماژول‌ها رندر نمی‌شود → از رابط کاربری روشن/خاموش نمی‌شود.',
                        );
                }
                foreach ( array_unique( array_diff_assoc( $described, array_unique( $described ) ) ) as $slug ) {
                        $issues[] = array(
                                'section' => 'modules',
                                'key'     => $slug,
                                'issue'   => 'این ماژول بیش از یک بار در صفحه‌ی ماژول‌ها رندر می‌شود.',
                        );
                }

                return $issues;
        }

        /**
         * Settings sections whose fields are contributed by modules, mapped to the
         * module slug that actually RENDERS them. A section is only expected to have
         * UI while its renderer is switched on.
         *
         * Note the difference between owning a value and owning a field:
         * article-writer and autopilot both read content.default_tone, but the
         * content.* inputs are printed by product-content's tab alone. Listing
         * article-writer here as a co-owner made every content.* key look broken
         * whenever product-content was off and article-writer was on.
         *
         * general / provider / appearance / advanced are core sections and are
         * deliberately absent: they always render.
         *
         * @return array<string,array<int,string>> Section => owning module slugs.
         */
        public static function module_owned_sections() {
                return array(
                        'chat'      => array( 'chatbot' ),
                        'content'   => array( 'product-content' ),
                        'article'   => array( 'article-writer' ),
                        'profile'   => array( 'article-writer' ),
                        'research'  => array( 'article-writer' ),
                        'news'      => array( 'news' ),
                        'video'     => array( 'video-article' ),
                        'programmatic' => array( 'programmatic' ),
                        'autopilot' => array( 'autopilot' ),
                        'leads'     => array( 'leads' ),
                        'discount'  => array( 'discount' ),
                        'comments'  => array( 'comments' ),
                        'search'    => array( 'semantic-search' ),
                );
        }

        /**
         * Keys that intentionally have NO settings-screen field, each with the
         * reason. diagnose() skips these in the "defaults+sanitize but no UI"
         * check, so its output stays at zero while the decision is still written
         * down instead of silently hidden.
         *
         * @return array<string,string> Dotted key => reason (Persian).
         */
        public static function intentional_no_ui() {
                return array(
                        'autopilot.lock_ttl' => 'فنی: عمر میتکس (mutex) صف است. کوتاه‌شدن بیش از حد باعث اجرای همزمان دو نوبت و بلندشدنش باعث گیرکردن صف می‌شود؛ با کد پیش‌فرض ۹۰۰ ثانیه می‌ماند و فقط توسعه‌دهنده در صورت نیاز آن را فیلتر می‌کند.',
                        'provider.vault' => 'فنی: گاوصندوق کلید هر سرویس. فرم هرگز آن را پست نمی‌کند — هنگام هر ذخیره‌ی تب سرویس، سرور خودش ردیف سرویس فعال را بازسازی می‌کند (provider_vault_write) و سوییچ «سرویس» در جاوااسکریپت مقادیر هر ردیف را در فرم برمی‌گرداند؛ نمایش مستقیم آن فقط محل خطاست.',
                );
        }

        /**
         * Collect all UI field keys from core tabs and enabled module tabs.
         *
         * @return array<int,string> Dotted keys.
         */
        private static function collect_ui_keys() {
                $keys = array();

                // Core tabs (mirrors Haa_Admin::render_core_fields).
                $core = array(
                        'general'   => array( 'general.enabled', 'general.display_mode', 'general.floating_position', 'general.floating_bottom', 'general.floating_side', 'general.show_devices', 'general.show_roles', 'general.show_on_post_types', 'general.pages_mode', 'general.special_pages', 'general.show_on_pages', 'general.exclude_pages' ),
                        'provider'  => array( 'provider.provider', 'provider.api_key', 'provider.base_url', 'provider.model', 'provider.embedding_model', 'provider.image_model', 'provider.timeout', 'provider.max_tokens', 'provider.allow_localhost', 'provider.adapter', 'provider.temperature', 'provider.retries', 'provider.proxy_host', 'provider.proxy_port', 'provider.proxy_username', 'provider.proxy_password', 'provider.failovers' ),
                        'appearance'=> array( 'appearance.skin', 'appearance.primary_color', 'appearance.secondary_color', 'appearance.bg_color', 'appearance.text_color', 'appearance.theme_mode', 'appearance.dark_bg', 'appearance.dark_text', 'appearance.dark_bubble', 'appearance.font', 'appearance.font_size', 'appearance.border_radius', 'appearance.button_size', 'appearance.welcome_message', 'appearance.placeholder', 'appearance.custom_icon', 'appearance.custom_css' ),
                        'advanced'  => array( 'advanced.enable_rag', 'advanced.kb_enabled', 'advanced.kb_post_types', 'advanced.kb_chunk_words', 'advanced.kb_overlap_words', 'advanced.kb_categories', 'advanced.kb_max_file_kb', 'advanced.enable_cache', 'advanced.cache_ttl', 'advanced.rate_limit_count', 'advanced.rate_limit_minutes', 'advanced.token_ttl_minutes', 'advanced.max_message_chars', 'advanced.daily_request_cap', 'advanced.daily_cap_message', 'advanced.daily_user_cap', 'advanced.daily_user_cap_message', 'advanced.log_conversations', 'advanced.log_retention_days', 'advanced.usage_retention', 'advanced.guest_access', 'advanced.login_required_message', 'advanced.ip_header' ),
                        'knowledge' => array( 'knowledge.urls', 'knowledge.auto_crawl', 'knowledge.max_pages', 'knowledge.crawl_types', 'knowledge.llms_enabled', 'knowledge.bots_enabled', 'knowledge.links_enabled' ),
                );
                foreach ( $core as $tab_keys ) {
                        $keys = array_merge( $keys, $tab_keys );
                }

                // Module tabs.
                $module_fields = array(
                        'chatbot'          => array( 'chat.system_prompt', 'chat.max_history', 'chat.context_chunks', 'chat.context_max_chars', 'chat.suggested_questions', 'chat.error_message', 'chat.typing_indicator', 'chat.offline_enabled', 'chat.offline_start', 'chat.offline_end', 'chat.offline_message', 'chat.save_history', 'chat.history_retention', 'chat.lead_capture', 'chat.lead_requirement', 'chat.lead_name_label', 'chat.lead_phone_label', 'chat.lead_consent_text', 'chat.contact_whatsapp', 'chat.contact_telegram', 'chat.contact_instagram', 'chat.contact_phone', 'chat.contact_page', 'chat.contact_note', 'chat.tone', 'chat.page_context', 'chat.custom_knowledge', 'chat.handoff_enabled', 'chat.handoff_after', 'chat.notify_bale_token', 'chat.notify_bale_chat', 'chat.notify_tg_token', 'chat.notify_tg_chat' ),
                        'semantic-search'  => array( 'search.enabled', 'search.limit', 'search.min_score', 'search.include_products', 'search.include_posts', 'search.include_pages', 'search.embeddings', 'search.hybrid', 'search.semantic_weight', 'search.keyword_weight', 'search.chunk_cap', 'search.max_vectors' ),
                        'news'             => array( 'news.enabled', 'news.feeds', 'news.max_per_run', 'news.daily_cap', 'news.lookback_hours', 'news.default_status', 'news.default_category', 'news.min_words', 'news.quote_source' ),
                        'video-article'    => array( 'video.enabled', 'video.min_chars', 'video.max_words', 'video.default_status', 'video.default_category', 'video.fetch_captions' ),
                        'programmatic'     => array( 'programmatic.enabled', 'programmatic.source', 'programmatic.attribute', 'programmatic.template', 'programmatic.title_template', 'programmatic.min_words', 'programmatic.similar_threshold', 'programmatic.daily_cap', 'programmatic.hub_page', 'programmatic.noindex', 'programmatic.default_status' ),
                        'comments'         => array( 'comments.enabled', 'comments.author_name', 'comments.author_email', 'comments.default_status', 'comments.generate_questions', 'comments.generate_answers', 'comments.max_per_run' ),
                        'product-content'  => array( 'content.default_language', 'content.default_tone', 'content.default_length', 'content.default_audience', 'content.enable_images', 'content.image_size', 'content.image_style', 'content.image_quality', 'content.internal_links', 'content.link_count', 'content.include_price' ),
                        'article-writer'   => array( 'article.default_status', 'article.default_author', 'article.batch_size', 'article.daily_token_cap', 'article.daily_article_cap', 'article.dup_threshold', 'article.plan_count', 'article.publish_mode', 'article.target_words', 'article.max_sections', 'article.inline_images', 'article.faq_schema', 'article.link_cap', 'article.gate_word_ratio', 'article.flag_claims', 'article.refresh_enabled', 'article.refresh_days', 'article.refresh_mode', 'profile.enabled', 'profile.domain', 'profile.audience', 'profile.voice', 'profile.offerings', 'profile.glossary', 'profile.forbidden', 'profile.examples', 'profile.key_pages', 'profile.max_chars', 'research.enabled', 'research.competitors', 'research.user_agent', 'research.respect_robots', 'research.delay_ms', 'research.timeout', 'research.max_pages', 'research.max_kb', 'research.cache_hours', 'research.serp_enabled', 'research.serp_kind', 'research.serp_base_url', 'research.serp_key', 'research.fallback_ai', 'research.suggest_enabled' ),
                        'autopilot'        => array( 'autopilot.daily_token_cap', 'autopilot.daily_post_cap', 'autopilot.retry_limit', 'autopilot.default_status', 'autopilot.default_tone', 'autopilot.default_length', 'autopilot.enable_images', 'autopilot.default_category' ),
                        'leads'            => array( 'leads.retention_days', 'leads.stages', 'leads.csv_enabled', 'leads.ai_summary', 'leads.score_phone', 'leads.score_message', 'leads.score_buying', 'leads.auto_create' ),
                        'discount'         => array( 'discount.enabled', 'discount.max_rounds', 'discount.global_max_percent', 'discount.default_max_percent', 'discount.per_visitor', 'discount.rate_minutes', 'discount.coupon_expiry_days', 'discount.min_cart', 'discount.max_uses' ),
                );
                $enabled = self::enabled_modules();
                foreach ( $module_fields as $slug => $module_keys ) {
                        if ( ! empty( $enabled[ $slug ] ) ) {
                                $keys = array_merge( $keys, $module_keys );
                        }
                }

                return array_values( array_unique( $keys ) );
        }

        /* ------------------------------------------------------------------
         * Import / export
         * ---------------------------------------------------------------- */

        /**
         * Pretty JSON, secrets stripped so the file is safe to share.
         *
         * @return string
         */
        public static function export() {
                $all = self::get();
                $all['provider']['api_key']        = '';
                $all['provider']['proxy_password'] = '';
                // The per-provider vault holds real (encrypted) keys — an export file
                // is meant to be shareable, so like the primary key the vault keys
                // are stripped while the URL/model preferences survive.
                if ( isset( $all['provider']['vault'] ) && is_array( $all['provider']['vault'] ) ) {
                        foreach ( $all['provider']['vault'] as $slug => $row ) {
                                if ( is_array( $row ) ) {
                                        $all['provider']['vault'][ $slug ]['api_key'] = '';
                                }
                        }
                }
                $all['appearance']['custom_icon']  = (int) $all['appearance']['custom_icon'];

                return wp_json_encode(
                        array(
                                'plugin'    => 'hami-ai-assistant',
                                'version'   => HAAI_VERSION,
                                'exported'  => current_time( 'mysql' ),
                                'settings'  => $all,
                                'modules'   => self::enabled_modules(),
                        ),
                        JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                );
        }

        /**
         * Validate and sanitize an import payload without writing anything.
         *
         * A settings file is admin-authored but parsed on the server, so it is
         * treated as untrusted input: only dotted keys that exist in the sanitize
         * map survive, each through its own sanitizer, and everything else is
         * dropped rather than stored. The result doubles as the change summary the
         * confirm step shows, so the admin approves what will actually happen
         * instead of a filename.
         *
         * An empty secret in the file means «keep the current one», never «erase
         * it» — the key is simply left out of the accepted set.
         *
         * @param mixed $decoded Decoded JSON.
         * @return array{ok:bool,error:string,settings:array,modules:array,keys:int,sections:array<string,int>,changed:int,has_secrets:bool}
         */
        public static function validate_import( $decoded ) {
                $out = array(
                        'ok'          => false,
                        'error'       => '',
                        'settings'    => array(),
                        'modules'     => array(),
                        'keys'        => 0,
                        'sections'    => array(),
                        'changed'     => 0,
                        'has_secrets' => false,
                );

                if ( ! is_array( $decoded ) || ! $decoded ) {
                        $out['error'] = __( 'فایل JSON معتبر نیست یا شیء خالی است.', 'hami-ai-assistant' );
                        return $out;
                }

                $incoming = ( isset( $decoded['settings'] ) && is_array( $decoded['settings'] ) ) ? $decoded['settings'] : $decoded;
                if ( ! is_array( $incoming ) || ! $incoming ) {
                        $out['error'] = __( 'هیچ بخش تنظیماتی در فایل پیدا نشد.', 'hami-ai-assistant' );
                        return $out;
                }

                $rules = self::sanitize_map();
                $live  = self::get();
                $clean = array();
                $keys  = 0;

                foreach ( $incoming as $section => $values ) {
                        if ( ! is_array( $values ) ) {
                                continue; // a scalar where a section belongs: structure mismatch
                        }
                        foreach ( $values as $key => $value ) {
                                $dotted = $section . '.' . $key;
                                if ( ! isset( $rules[ $dotted ] ) ) {
                                        continue; // unknown key: dropped, never stored
                                }
                                $rule = $rules[ $dotted ];
                                if ( empty( $rule['cb'] ) || ! is_callable( $rule['cb'] ) ) {
                                        continue;
                                }
                                $default = self::path_get( self::defaults(), $dotted );
                                $fixed   = call_user_func( $rule['cb'], $value, $default, $rule );

                                // Secrets: blank in the file = keep the live one.
                                if ( 'provider.api_key' === $dotted || 'provider.proxy_password' === $dotted ) {
                                        if ( '' === (string) $fixed ) {
                                                continue;
                                        }
                                        $out['has_secrets'] = true;
                                }

                                self::path_set( $clean, $dotted, $fixed );
                                $keys++;
                                $out['sections'][ $section ] = isset( $out['sections'][ $section ] ) ? $out['sections'][ $section ] + 1 : 1;

                                // Does it actually differ from what the site has now?
                                $current = self::path_get( $live, $dotted );
                                $same    = $current === $fixed;
                                if ( ! $same && ( 'provider.api_key' === $dotted || 'provider.proxy_password' === $dotted ) ) {
                                        $same = self::decrypt( $current ) === self::decrypt( $fixed );
                                }
                                if ( ! $same ) {
                                        $out['changed']++;
                                }
                        }
                }

                if ( ! $keys ) {
                        $out['error'] = __( 'هیچ کلید شناخته‌شده‌ای در فایل نبود؛ فایل ممکن است از نسخه‌ی دیگری باشد.', 'hami-ai-assistant' );
                        return $out;
                }

                // Module flags: the wrapper's `modules` map wins, else the section.
                $flags_source = null;
                if ( isset( $decoded['modules'] ) && is_array( $decoded['modules'] ) ) {
                        $flags_source = $decoded['modules'];
                } elseif ( isset( $clean['modules'] ) && is_array( $clean['modules'] ) ) {
                        $flags_source = $clean['modules'];
                }
                if ( is_array( $flags_source ) ) {
                        $bool = array( 'Haa_Settings', 'sanitize_bool' );
                        foreach ( self::module_slugs() as $slug ) {
                                if ( array_key_exists( $slug, $flags_source ) && call_user_func( $bool, $flags_source[ $slug ], 0, array() ) ) {
                                        $out['modules'][ $slug ] = 1;
                                }
                        }
                }

                unset( $clean['modules'] ); // the gate is applied through set_enabled_modules()

                $out['settings'] = $clean;
                $out['keys']     = $keys;
                $out['ok']       = true;
                return $out;
        }

        /**
         * Import a settings JSON over the live settings.
         *
         * Keys present in the file replace the stored value; keys the file does
         * not mention keep whatever the site has now (save_all() merges over the
         * stored option). Only reset() throws values away.
         *
         * A file may only replace the API key / proxy password when the caller
         * says so explicitly — the import screen asks for that confirmation, and
         * an unattended caller (WP-CLI, a migrator) never gets it.
         *
         * @param string $json Import payload.
         * @param array  $args { allow_secrets:bool }.
         * @return true|WP_Error
         */
        public static function import( $json, $args = array() ) {
                $data = json_decode( wp_unslash( $json ), true );
                if ( ! is_array( $data ) ) {
                        return new WP_Error( 'haai_bad_json', __( 'فایل JSON معتبر نیست.', 'hami-ai-assistant' ) );
                }

                $checked = self::validate_import( $data );
                if ( empty( $checked['ok'] ) ) {
                        return new WP_Error( 'haai_bad_import', $checked['error'] );
                }

                // Keep a copy of the live settings before the merge overwrites them, so
                // a bad import can be undone from the advanced tab. Taken only once the
                // payload is known to be valid, so a rejected file leaves no snapshot.
                self::snapshot();

                $incoming = $checked['settings'];
                if ( empty( $args['allow_secrets'] ) ) {
                        unset( $incoming['provider']['api_key'], $incoming['provider']['proxy_password'] );
                }

                // A file exported without the secrets (or with them blanked) must not
                // erase the live ones. save_all() keeps stored keys anyway; this stays
                // as the explicit guarantee for an explicitly empty value.
                $live          = self::get();
                $keep_api_key  = $live['provider']['api_key'];
                $keep_proxy_pw = $live['provider']['proxy_password'];

                self::save_all( $incoming );

                $after = self::get();
                $fix   = false;
                if ( '' === $after['provider']['api_key'] && '' !== $keep_api_key ) {
                        $after['provider']['api_key'] = $keep_api_key;
                        $fix = true;
                }
                if ( '' === $after['provider']['proxy_password'] && '' !== $keep_proxy_pw ) {
                        $after['provider']['proxy_password'] = $keep_proxy_pw;
                        $fix = true;
                }
                if ( $fix ) {
                        update_option( HAAI_OPTION, $after, 'no' );
                        self::flush_cache();
                }

                if ( $checked['modules'] ) {
                        self::set_enabled_modules( $checked['modules'] );
                }

                return true;
        }
}
