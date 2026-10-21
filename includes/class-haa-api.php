<?php
/**
 * AI transport: three adapters, presets, retry with backoff, and the
 * /models endpoint used by the admin model pickers.
 *
 * Nothing else in the plugin is allowed to talk to a provider directly —
 * every module goes through Hami_Ai_API so proxy, timeout, retries and usage
 * accounting stay in one place.
 *
 * @package Hami_AI_Assistant
 */

if ( ! defined( 'ABSPATH' ) ) {
        exit;
}

/* ---------------------------------------------------------------------
 * Adapter contract
 * ------------------------------------------------------------------ */

/**
 * One adapter per wire format. Adapters never do HTTP; they only describe it.
 */
abstract class Haa_Adapter_Base {

        /** @var array Decrypted provider settings. */
        protected $cfg;

        /**
         * @param array $cfg Provider settings.
         */
        public function __construct( $cfg ) {
                $this->cfg = $cfg;
        }

        /** @return string Full request URL. */
        abstract public function endpoint();

        /** @return array<string,string> Headers, including the secret. */
        abstract public function headers();

        /**
         * @param array $messages Normalised turns (role = system|user|assistant).
         * @param array $opts     temperature, max_tokens, model.
         * @return string JSON body.
         */
        abstract public function body( $messages, $opts );

        /**
         * @param array $json Decoded response.
         * @return string|false Plain text, or false when nothing usable was found.
         */
        abstract public function extract( $json );

        /**
         * Did the provider stop because it ran out of output tokens?
         *
         * A reply that hit the ceiling is not a finished text: without this the
         * caller cannot tell a cut-off answer from a short one.
         *
         * @param array $json Decoded response.
         * @return bool
         */
        public function truncated( $json ) {
                return false;
        }

        /** @return bool Whether this wire format has a /models endpoint. */
        public function supports_models() {
                return false;
        }
}

/**
 * OpenAI-compatible /chat/completions. Used by OpenAI, OpenRouter, xAI,
 * DeepSeek, Qwen, Mistral, Groq, Ollama and every Persian relay.
 */
class Haa_Adapter_OpenAI extends Haa_Adapter_Base {

        public function endpoint() {
                return untrailingslashit( $this->cfg['base_url'] ) . '/chat/completions';
        }

        public function headers() {
                return array(
                        'Content-Type'  => 'application/json',
                        'Authorization' => 'Bearer ' . $this->cfg['api_key'],
                );
        }

        public function body( $messages, $opts ) {
                return wp_json_encode(
                        array(
                                'model'       => $opts['model'],
                                'messages'    => array_values( $messages ),
                                'temperature' => (float) $opts['temperature'],
                                'max_tokens'  => (int) $opts['max_tokens'],
                                'stream'      => false,
                        ),
                        JSON_INVALID_UTF8_SUBSTITUTE
                );
        }

        public function extract( $json ) {
                if ( isset( $json['choices'][0]['message']['content'] ) ) {
                        return trim( (string) $json['choices'][0]['message']['content'] );
                }
                if ( isset( $json['choices'][0]['text'] ) ) {
                        return trim( (string) $json['choices'][0]['text'] );
                }
                return false;
        }

        public function truncated( $json ) {
                return isset( $json['choices'][0]['finish_reason'] ) && 'length' === (string) $json['choices'][0]['finish_reason'];
        }

        public function supports_models() {
                return true;
        }
}

/**
 * Google Gemini — the system prompt travels in a dedicated block and the key
 * rides on the query string.
 */
class Haa_Adapter_Gemini extends Haa_Adapter_Base {

        public function endpoint() {
                return untrailingslashit( $this->cfg['base_url'] )
                        . '/models/' . rawurlencode( $this->cfg['model'] ?? '' ) . ':generateContent';
        }

        public function headers() {
                $headers = array( 'Content-Type' => 'application/json' );
                if ( ! empty( $this->cfg['api_key'] ) ) {
                        $headers['x-goog-api-key'] = (string) $this->cfg['api_key'];
                }
                return $headers;
        }

        public function body( $messages, $opts ) {
                $turns   = array();
                $system  = array();
                foreach ( (array) $messages as $m ) {
                        $role = isset( $m['role'] ) ? $m['role'] : 'user';
                        $text = isset( $m['content'] ) ? trim( (string) $m['content'] ) : '';
                        if ( 'system' === $role ) {
                                $system[] = $text;
                                continue;
                        }
                        if ( '' === $text ) {
                                continue; // Gemini rejects empty parts with a 400.
                        }
                        $wire = 'assistant' === $role ? 'model' : 'user';
                        // v1beta enforces strict user/model alternation — a session
                        // that survived failed turns carries two user messages back
                        // to back, and Google answers 400 "Please ensure that
                        // multiturn requests alternate between user and model".
                        // The admin probe (single ping, no history) stays green, so
                        // the bot looks dead while the provider tab says healthy.
                        // Consecutive same-role turns merge into one content as an
                        // extra part, which is exactly the semantics the visitor saw.
                        $last = count( $turns ) - 1;
                        if ( $last >= 0 && $turns[ $last ]['role'] === $wire ) {
                                $turns[ $last ]['parts'][] = array( 'text' => $text );
                        } else {
                                $turns[] = array(
                                        'role'  => $wire,
                                        'parts' => array( array( 'text' => $text ) ),
                                );
                        }
                }
                // Same contract on the first turn: it must speak as the user.
                while ( $turns && 'model' === $turns[0]['role'] ) {
                        array_shift( $turns );
                }

                $payload = array(
                        'contents'         => $turns,
                        'generationConfig' => array(
                                'temperature'     => (float) $opts['temperature'],
                                'maxOutputTokens' => (int) $opts['max_tokens'],
                        ),
                );
                if ( $system ) {
                        $payload['systemInstruction'] = array( 'parts' => array( array( 'text' => implode( "\n", $system ) ) ) );
                }
                // Google-Search grounding: the model may look the topic up live. This is
                // what makes AI-assisted competitor research possible on hosts that
                // cannot reach Google themselves (research.fallback_ai).
                if ( ! empty( $opts['grounding'] ) ) {
                        $payload['tools'] = array( array( 'google_search' => new stdClass() ) );
                }
                // A single invalid UTF-8 byte (a snippet cut mid-character) would
                // make json_encode return false — an empty body every time. The flag
                // substitutes the bad byte instead of losing the whole request.
                return wp_json_encode( $payload, JSON_INVALID_UTF8_SUBSTITUTE );
        }

        public function extract( $json ) {
                // Answers may arrive as several parts (and thinking models may put
                // a thought part first): parts[0]-only read either truncated the
                // reply or reported an empty one. Walk every part, skip thoughts.
                $parts = isset( $json['candidates'][0]['content']['parts'] ) && is_array( $json['candidates'][0]['content']['parts'] )
                        ? $json['candidates'][0]['content']['parts']
                        : array();
                $text  = '';
                foreach ( $parts as $part ) {
                        if ( empty( $part['thought'] ) && isset( $part['text'] ) ) {
                                $text .= (string) $part['text'];
                        }
                }
                $text = trim( $text );
                return '' === $text ? false : $text;
        }

        public function truncated( $json ) {
                return isset( $json['candidates'][0]['finishReason'] ) && 'MAX_TOKENS' === (string) $json['candidates'][0]['finishReason'];
        }

        public function supports_models() {
                return true;
        }
}

/**
 * Anthropic /messages — max_tokens is mandatory and the key is an x-api-key
 * header instead of an Authorization bearer.
 */
class Haa_Adapter_Claude extends Haa_Adapter_Base {

        public function endpoint() {
                return untrailingslashit( $this->cfg['base_url'] ) . '/messages';
        }

        public function headers() {
                return array(
                        'Content-Type'                        => 'application/json',
                        'x-api-key'                           => $this->cfg['api_key'],
                        'anthropic-version'                   => '2023-06-01',
                        'anthropic-dangerous-direct-browser-access' => 'true',
                );
        }

        public function body( $messages, $opts ) {
                $turns  = array();
                $system = array();
                foreach ( (array) $messages as $m ) {
                        $role = isset( $m['role'] ) ? $m['role'] : 'user';
                        $text = isset( $m['content'] ) ? trim( (string) $m['content'] ) : '';
                        if ( 'system' === $role ) {
                                $system[] = $text;
                                continue;
                        }
                        if ( '' === $text ) {
                                continue;
                        }
                        // Anthropic enforces alternation just like Gemini — merge
                        // consecutive same-role turns instead of shipping a 400.
                        $wire = 'assistant' === $role ? 'assistant' : 'user';
                        $last = count( $turns ) - 1;
                        if ( $last >= 0 && $turns[ $last ]['role'] === $wire ) {
                                $turns[ $last ]['content'] .= "\n" . $text;
                        } else {
                                $turns[] = array(
                                        'role'    => $wire,
                                        'content' => $text,
                                );
                        }
                }
                while ( $turns && 'assistant' === $turns[0]['role'] ) {
                        array_shift( $turns );
                }

                $payload = array(
                        'model'      => $opts['model'],
                        'messages'   => $turns,
                        'max_tokens' => (int) $opts['max_tokens'],
                );
                if ( isset( $opts['temperature'] ) && '' !== $opts['temperature'] && null !== $opts['temperature'] ) {
                        $payload['temperature'] = (float) $opts['temperature'];
                }
                if ( $system ) {
                        $payload['system'] = implode( "\n", $system );
                }
                return wp_json_encode( $payload, JSON_INVALID_UTF8_SUBSTITUTE );
        }

        public function extract( $json ) {
                // Thinking models ship a thinking block first; content[0] is not
                // always the text. Walk the blocks, join the text ones.
                if ( is_string( $json['content'] ?? null ) ) {
                        $text = trim( (string) $json['content'] );
                        return '' === $text ? false : $text;
                }
                $blocks = isset( $json['content'] ) && is_array( $json['content'] ) ? $json['content'] : array();
                $text   = '';
                foreach ( $blocks as $block ) {
                        if ( isset( $block['type'] ) && 'text' === $block['type'] && isset( $block['text'] ) ) {
                                $text .= (string) $block['text'];
                        }
                }
                $text = trim( $text );
                return '' === $text ? false : $text;
        }

        public function truncated( $json ) {
                return isset( $json['stop_reason'] ) && 'max_tokens' === (string) $json['stop_reason'];
        }

        public function supports_models() {
                return true;
        }
}

/* ---------------------------------------------------------------------
 * API facade
 * ------------------------------------------------------------------ */

final class Haa_API {

        /** Transient key for the cached /models response. */
        const MODELS_TRANSIENT = 'haai_models_';

        /** Seconds a source that just failed stays out of the rotation. */
        const FAILOVER_COOLDOWN = 300;

        /**
         * Seconds into a bench after which the source may be probed again.
         * A source benched seconds ago has had no time to heal — retrying it
         * with the full backoff budget is exactly how one provider outage
         * turned every visitor request into a slow PHP pile-up (the user's
         * "low speed, then the bot goes away" report). 60 s keeps recovery
         * fast while capping the wasted work to one probe per minute.
         */
        const BENCH_PROBE_AFTER = 60;

        /** Transient prefix for the per-source failure cooldown. */
        const COOLDOWN_PREFIX = 'haai_fb_';

        /** @var bool Did the last chat reply hit the provider's output ceiling? */
        private static $last_truncated = false;

        /**
         * Whether the reply of the last chat() call was cut off at max_tokens.
         *
         * @return bool
         */
        public static function last_truncated() {
                return self::$last_truncated;
        }

        /** @var array Decrypted, preset-resolved settings. */
        private $cfg;

        /**
         * @param array|null $cfg Optional override (admin "test" with unsaved values).
         */
        public function __construct( $cfg = null ) {
                $this->cfg = null === $cfg ? Haa_Settings::provider() : $cfg;
        }

        /**
         * Presets. Only three real wire formats exist; the rest are configuration
         * shortcuts. Every base_url stays editable so a relay / Worker / in-country
         * proxy can be used.
         *
         * @return array<string,array{label:string,adapter:string,base_url:string,model:string}>
         */
        public static function presets() {
                return array(
                        'openai'     => array(
                                'label'    => 'OpenAI',
                                'adapter'  => 'openai',
                                'base_url' => 'https://api.openai.com/v1',
                                'model'    => 'gpt-4o-mini',
                                'note'     => 'OpenAI معمولاً از سرورهای ایران در دسترس نیست — اگر تست اتصال خطای مهلت/قطع اتصال داد، پراکسی یا رله (مثل OpenRouter) لازم است.',
                        ),
                        'openrouter' => array(
                                'label'    => 'OpenRouter',
                                'adapter'  => 'openai',
                                'base_url' => 'https://openrouter.ai/api/v1',
                                'model'    => 'openai/gpt-4o-mini',
                        ),
                        // سرویس‌های واسط ایرانی که فرمت OpenAI دارند و ده‌ها مدل می‌دهند.
                        'irrelay'    => array(
                                'label'    => 'گپ‌جی‌پی‌تی / سرویس واسط (فرمت OpenAI)',
                                'adapter'  => 'openai',
                                'base_url' => 'https://api.irrelay.example/v1',
                                'model'    => 'gpt-4o-mini',
                        ),
                        'xai'        => array(
                                'label'    => 'xAI Grok',
                                'adapter'  => 'openai',
                                'base_url' => 'https://api.x.ai/v1',
                                'model'    => 'grok-2-latest',
                                'note'     => 'Grok (x.ai) معمولاً از سرورهای ایران در دسترس نیست (خود xAI هم ایران را سرویس نمی‌دهد) — پراکسی یا رله لازم است.',
                        ),
                        'deepseek'   => array(
                                'label'    => 'DeepSeek',
                                'adapter'  => 'openai',
                                'base_url' => 'https://api.deepseek.com/v1',
                                'model'    => 'deepseek-chat',
                        ),
                        'qwen'       => array(
                                'label'    => 'Qwen (DashScope)',
                                'adapter'  => 'openai',
                                'base_url' => 'https://dashscope-intl.aliyuncs.com/compatible-mode/v1',
                                'model'    => 'qwen-plus',
                        ),
                        'mistral'    => array(
                                'label'    => 'Mistral',
                                'adapter'  => 'openai',
                                'base_url' => 'https://api.mistral.ai/v1',
                                'model'    => 'mistral-small-latest',
                        ),
                        'groq'       => array(
                                'label'    => 'Groq',
                                'adapter'  => 'openai',
                                'base_url' => 'https://api.groq.com/openai/v1',
                                'model'    => 'llama-3.3-70b-versatile',
                        ),
                        'ollama'     => array(
                                'label'    => 'Ollama (لوکال)',
                                'adapter'  => 'openai',
                                'base_url' => 'http://127.0.0.1:11434/v1',
                                'model'    => 'llama3.2',
                        ),
                        'gemini'     => array(
                                'label'    => 'Google Gemini',
                                'adapter'  => 'gemini',
                                'base_url' => 'https://generativelanguage.googleapis.com/v1beta',
                                'model'    => 'gemini-2.5-flash',
                                'note'     => 'Gemini معمولاً از سرورهای ایران در دسترس نیست (خطای «مهلت اتصال» نشانه‌ی فیلتر بودن است، نه کندی) — پراکسی تنظیم کنید یا از رله‌ای که فرمت Gemini دارد استفاده کنید.',
                        ),
                        'claude'     => array(
                                'label'    => 'Anthropic Claude',
                                'adapter'  => 'claude',
                                'base_url' => 'https://api.anthropic.com/v1',
                                'model'    => 'claude-3-5-haiku-latest',
                                'note'     => 'Claude معمولاً از سرورهای ایران در دسترس نیست — پراکسی یا رله (مثل OpenRouter) لازم است.',
                        ),
                        'custom'     => array(
                                'label'    => 'سفارشی (Relay / Worker)',
                                'adapter'  => 'openai',
                                'base_url' => '',
                                'model'    => '',
                        ),
                );
        }

        /**
         * Adapter slugs => human labels.
         *
         * @return array<string,string>
         */
        public static function adapters() {
                return array(
                        'openai' => __( 'سازگار با OpenAI', 'hami-ai-assistant' ),
                        'gemini' => __( 'Google Gemini', 'hami-ai-assistant' ),
                        'claude' => __( 'Anthropic Claude', 'hami-ai-assistant' ),
                );
        }

        /**
         * Swap the configuration (admin test with unsaved values).
         *
         * @param array $cfg Provider settings.
         * @return Haa_API
         */
        public function with_config( $cfg ) {
                $this->cfg = $cfg;
                return $this;
        }

        /** @return array */
        public function config() {
                return $this->cfg;
        }

        /**
         * @return Haa_Adapter_Base
         */
        public function adapter() {
                $adapter = isset( $this->cfg['adapter'] ) ? $this->cfg['adapter'] : 'openai';
                switch ( $adapter ) {
                        case 'gemini':
                                return new Haa_Adapter_Gemini( $this->cfg );
                        case 'claude':
                                return new Haa_Adapter_Claude( $this->cfg );
                        default:
                                return new Haa_Adapter_OpenAI( $this->cfg );
                }
        }

        /* ------------------------------------------------------------------
         * Chat
         * ---------------------------------------------------------------- */

        /**
         * Defaults for one chat() call. 2.1.31 additions:
         *
         *  - `timeout`: a caller may cap the HTTP wait BELOW the configured
         *    «مهلت پاسخ» — the keyword panel promises ~one minute and must not
         *    spend it all on one hung provider. 0 = use the configured value.
         *  - `ignore_fresh_bench`: a deliberate retry (the keyword panel's
         *    no-grounding second call) must be able to probe a source its own
         *    first call just benched; without this the fresh-bench wall answers
         *    «همه منابع…» before the retry can ask the real question. Visitor
         *    traffic never sets it — the fail-fast stays theirs.
         *
         * @param array $cfg  Active source config.
         * @param array $opts Call options.
         * @return array
         */
        public static function chat_opts( $cfg, $opts ) {
                return wp_parse_args(
                        $opts,
                        array(
                                'model'              => $cfg['model'],
                                'temperature'        => $cfg['temperature'],
                                'max_tokens'         => $cfg['max_tokens'],
                                'module'             => 'core',
                                'retries'            => (int) $cfg['retries'],
                                'operation'          => 'chat',
                                'timeout'            => 0,
                                'ignore_fresh_bench' => false,
                        )
                );
        }

        /**
         * Send a conversation, with retry/backoff on transient failures and
         * automatic failover across the configured backup sources.
         *
         * Every source gets one attempt while a fallback exists — that is what
         * keeps the switch immediate — and the full retry/backoff budget only
         * applies to a lone primary. A source that fails is benched for
         * FAILOVER_COOLDOWN seconds, so later requests skip it instantly
         * instead of paying its timeout again.
         *
         * @param array $messages Turns.
         * @param array $opts     model, temperature, max_tokens, module, retries.
         * @return string|WP_Error
         */
        public function chat( $messages, $opts = array() ) {
                self::$last_truncated = false;

                $opts = self::chat_opts( $this->cfg, $opts );

                // The walk below rebinds $this->cfg to the source being tried, while
                // source_list() derives the whole candidate list from $this->cfg. Left
                // in place, a single failover would make that backup the "primary" for
                // every later call on this instance (image(), the next section, the next
                // article) and the real primary would never be tried again.
                $origin = $this->cfg;

                $started = microtime( true );
                $fresh_only = false;
                $candidates = $this->candidates( $fresh_only, ! empty( $opts['ignore_fresh_bench'] ) );

                // The friendly configuration errors survive the walk: with a sole
                // (unusable) candidate they surface exactly as before; with a
                // usable alternative the dead config was already filtered out.
                if ( 1 === count( $candidates ) ) {
                        if ( '' === ( $candidates[0]['api_key'] ?? '' ) && ! self::cfg_is_keyless( $candidates[0] ) ) {
                                return new WP_Error( 'haai_no_key', __( 'کلید API تنظیم نشده است. لطفاً در تب «ارائه‌دهنده AI» آن را وارد کنید.', 'hami-ai-assistant' ) );
                        }
                        if ( '' === ( $candidates[0]['base_url'] ?? '' ) ) {
                                return new WP_Error( 'haai_no_base', __( 'آدرس Base URL خالی است. یک ارائه‌دهنده انتخاب کنید یا آدرس رله را وارد کنید.', 'hami-ai-assistant' ) );
                        }
                }

                if ( $fresh_only ) {
                        // Every source was benched within the last minute. An HTTP
                        // attempt now would just re-pay a timeout/403 that has had no
                        // time to heal — the visitor gets the honest message instantly
                        // and the host stays calm. The probe window re-opens within
                        // BENCH_PROBE_AFTER seconds, so recovery is never far away.
                        // 2.1.31: the generic sentence must NOT overwrite a real
                        // diagnostic the last actual walk left behind — the provider
                        // tab and the keyword panel both read this transient, and
                        // «همه منابع…» tells nobody anything they can act on.
                        $prev_err = get_transient( self::LAST_CHAT_ERROR );
                        if ( ! is_array( $prev_err ) || 'haai_all_benched' === (string) ( $prev_err['code'] ?? '' ) ) {
                                self::remember_chat_error( 'haai_all_benched', __( 'همه منابع در حال خطا هستند؛ چند لحظه بعد دوباره تلاش کنید.', 'hami-ai-assistant' ), (string) $opts['model'] );
                        }
                        $this->log_chat( $opts, $messages, '', $started, 0, 'haai_all_benched' );
                        return new WP_Error( 'haai_all_benched', __( 'همه منابع در حال خطا هستند؛ چند لحظه بعد دوباره تلاش کنید.', 'hami-ai-assistant' ) );
                }

                // With a fallback configured, every source gets exactly one attempt
                // so the switch stays immediate; the admin's retry/backoff budget
                // only applies to a lone primary. A benched source on probe duty
                // never gets the backoff budget — it failed moments ago.
                // 2.1.31: an EXPLICIT retries=0 is now a hard zero — the keyword
                // panel set it to bound its wait, and the multi-source «one
                // attempt each» budget would silently double that wait.
                $want_retries = max( 0, (int) $opts['retries'] );
                $retries      = 0 === $want_retries
                        ? 0
                        : ( count( $candidates ) > 1 ? 1 : $want_retries );

                $last = null;

                foreach ( $candidates as $cfg ) {
                        $this->cfg = $cfg; // request_once, the proxy and the error mapper all read the active source.
                        $was_benched = self::cooldown_left( $cfg ) > 0;
                        $result    = $this->attempt( $messages, $opts, $was_benched ? 0 : $retries );

                        if ( ! is_wp_error( $result ) ) {
                                if ( $was_benched ) {
                                        self::uncool( $cfg ); // the probe succeeded — full health (and budget) right away.
                                }
                                self::clear_chat_error(); // a healthy answer invalidates the diagnostic.
                                $this->log_chat( $opts, $messages, $result, $started, 1, '' );
                                $this->cfg = $origin;
                                return $result;
                        }

                        $last = $result;
                        if ( ! $was_benched ) {
                                self::cool( $cfg );
                        }
                        // A benched probe that failed again must NOT restart the
                        // 5-minute clock — otherwise the "~N دقیقه تا بازگشت" badge
                        // lies and a recovered source could never come back.
                }

                $this->log_chat( $opts, $messages, '', $started, 0, $last ? $last->get_error_code() : 'unknown' );
                $this->cfg = $origin;
                if ( $last ) {
                        self::remember_chat_error( $last->get_error_code(), $last->get_error_message(), (string) $opts['model'] );
                }

                return $last;
        }

        /** Transient key of the last failed chat() walk (admin diagnostic). */
        const LAST_CHAT_ERROR = 'haai_last_chat_error';

        /**
         * Keep the real failure of the last chat walk for the provider tab.
         * The visitor only ever sees the generic sentence (BL-05), so without
         * this the true code/message existed only under WP_DEBUG — the admin
         * had no way to see WHY the bot was down while the probe was green.
         * Cleared by the next successful chat(); expires after a day.
         *
         * @param string $code Error code.
         * @param string $msg  Error message.
         * @return void
         */
        private static function remember_chat_error( $code, $msg, $model = '' ) {
                set_transient(
                        self::LAST_CHAT_ERROR,
                        array(
                                'code'  => substr( (string) $code, 0, 60 ),
                                'msg'   => mb_substr( wp_strip_all_tags( (string) $msg ), 0, 300 ),
                                'model' => (string) $model,
                                'at'    => time(),
                        ),
                        DAY_IN_SECONDS
                );
        }

        /** @return void */
        private static function clear_chat_error() {
                delete_transient( self::LAST_CHAT_ERROR );
        }

        /**
         * One candidate's retry loop (the old chat() body, per source).
         *
         * @param array $messages Turns.
         * @param array $opts     Options.
         * @param int   $retries  Attempts after the first.
         * @return string|WP_Error
         */
        private function attempt( $messages, $opts, $retries ) {
                $attempt = 0;
                $last    = null;
                $widened = false;

                while ( $attempt <= $retries ) {
                        $result = $this->request_once( $this->adapter(), $messages, $opts );
                        if ( ! is_wp_error( $result ) ) {
                                return $result;
                        }

                        $last = $result;

                        // 2.1.18 — one widen-and-retry when the reply died at
                        // MAX_TOKENS with ZERO visible text: thinking models burn
                        // maxOutputTokens on invisible thought parts, so the tiny
                        // admin probe (8 tokens, «ping») answers green while a real
                        // chat with the full persona + RAG context starves into
                        // «پاسخ‌دهنده‌ی هوشمند فعلاً در دسترس نیست» every time.
                        // Doubling the budget (capped at 8192) gives the visible
                        // answer room to exist; it does not consume the normal
                        // retry budget and never fires twice in one walk.
                        if ( ! $widened && self::$last_truncated && 'haai_empty' === $result->get_error_code()
                                && (int) $opts['max_tokens'] < 8192 ) {
                                $widened            = true;
                                $opts['max_tokens'] = min( 8192, max( 512, (int) $opts['max_tokens'] * 2 ) );
                                continue;
                        }

                        // Only transient classes are worth retrying on the SAME
                        // source; anything else (bad key, no credit) moves the
                        // walk to the next source instead.
                        $code = $result->get_error_code();
                        $transient = array( 'haai_timeout', 'haai_transport', 'haai_blocked', 'haai_http_429', 'haai_http_500', 'haai_http_502', 'haai_http_503', 'haai_http_504' );
                        if ( ! in_array( $code, $transient, true ) ) {
                                break;
                        }
                        $attempt++;
                        if ( $attempt <= $retries ) {
                                // 2s, 4s, 8s… capped at 15s.
                                sleep( min( 15, 2 ** $attempt ) );
                        }
                }

                return $last;
        }

        /**
         * One usage-log row per chat() call, success or final failure.
         *
         * @param array  $opts    Options.
         * @param array  $messages Turns.
         * @param string $result  Reply text ('' on failure).
         * @param float  $started microtime at walk start.
         * @param int    $ok      1 or 0.
         * @param string $err     Error code on failure.
         * @return void
         */
        private function log_chat( $opts, $messages, $result, $started, $ok, $err ) {
                Haa_Usage::log(
                        array(
                                'module'     => $opts['module'],
                                'model'      => $opts['model'],
                                'operation'  => $opts['operation'],
                                'tokens_in'  => $this->estimate_tokens( $messages ),
                                'tokens_out' => $ok ? $this->estimate_tokens( array( array( 'content' => $result ) ) ) : 0,
                                'latency_ms' => (int) round( ( microtime( true ) - $started ) * 1000 ),
                                'ok'         => $ok,
                                'error_code' => $err,
                        )
                );
        }

        /* ------------------------------------------------------------------
         * Failover: candidates + cooldown
         * ---------------------------------------------------------------- */

        /**
         * Sources to try, in order: the primary first, then each saved
         * failover row. Sources still inside their failure cooldown move to
         * the END of the line — they get one last chance when everything
         * healthy has failed, and the moment a healthy fallback exists the
         * switch is instant. A source with no key and no base URL is skipped
         * entirely while a usable alternative exists; when nothing is usable
         * the primary stays so its standard error messages surface.
         *
         * Sources benched within the last BENCH_PROBE_AFTER seconds are left
         * out entirely; when that leaves nothing, $fresh_only comes back true
         * and the caller fails fast — walking into an outage that started
         * seconds ago only piles up slow PHP workers on the host.
         *
         * @param bool $fresh_only  Set when every source is freshly benched.
         * @param bool $ignore_fresh 2.1.31 — a deliberate retry probes even
         *                           fresh-benched sources instead of failing fast.
         * @return array<int,array> Wire-ready config arrays.
         */
        private function candidates( &$fresh_only = false, $ignore_fresh = false ) {
                $ordered = $this->source_list();
                $usable  = array();
                $fresh   = 0;

                foreach ( $ordered as $cfg ) {
                        $no_key  = '' === ( $cfg['api_key'] ?? '' ) && ! self::cfg_is_keyless( $cfg );
                        $no_base = '' === ( $cfg['base_url'] ?? '' );
                        if ( ( $no_key || $no_base ) && count( $ordered ) > 1 ) {
                                continue;
                        }
                        // A source benched within the last minute gets no attempt at
                        // all while anything else is available: it just failed, a
                        // retry now would burn the visitor's time for nothing.
                        // 2.1.31: ignore_fresh marks a deliberate diagnostic retry —
                        // it may probe the fresh-benched source and pay the wait.
                        if ( ! $ignore_fresh && self::bench_fresh( $cfg ) ) {
                                $fresh++;
                                continue;
                        }
                        $usable[] = $cfg;
                }

                if ( ! $usable ) {
                        if ( $fresh > 0 ) {
                                // Every source is inside the fresh-bench window: signal the
                                // caller to fail fast instead of walking into a known outage.
                                $fresh_only = true;
                                return array();
                        }
                        $usable = array( $this->cfg );
                }

                return $usable;
        }

        /**
         * True while a source is inside its no-probe window: benched, and
         * benched so recently that another attempt cannot plausibly succeed.
         *
         * @param array $cfg Source config.
         * @return bool
         */
        private static function bench_fresh( $cfg ) {
                $left = self::cooldown_left( $cfg );
                return $left > 0 && $left > self::FAILOVER_COOLDOWN - self::BENCH_PROBE_AFTER;
        }

        /**
         * The primary plus every saved failover row. A source inside its
         * failure cooldown — primary included — is demoted to the back of the
         * line: healthy sources answer first, benched ones only get a last
         * chance after everything else failed. Insertion order (primary →
         * fallback rows) is the priority among healthy sources.
         *
         * @return array<int,array>
         */
        private function source_list() {
                $primary = $this->cfg;
                $primary['is_primary'] = true;
                $healthy = array();
                $cooled  = array();

                foreach ( array_merge( array( $primary ), (array) ( $primary['failovers'] ?? array() ) ) as $row ) {
                        if ( ! is_array( $row ) || '' === trim( (string) ( $row['base_url'] ?? '' ) ) ) {
                                continue;
                        }
                        if ( empty( $row['is_primary'] ) ) {
                                // Transport settings ride the primary; the row only
                                // overrides endpoint, key, model and wire format.
                                $cfg = $primary;
                                foreach ( array( 'adapter', 'base_url', 'api_key', 'model' ) as $k ) {
                                        if ( '' !== (string) ( $row[ $k ] ?? '' ) ) {
                                                $cfg[ $k ] = (string) $row[ $k ];
                                        }
                                }
                                $cfg['is_primary'] = false;
                        } else {
                                $cfg = $row;
                        }

                        if ( 0 === self::cooldown_left( $cfg ) ) {
                                $healthy[] = $cfg;
                        } else {
                                $cooled[] = $cfg;
                        }
                }

                return array_merge( $healthy, $cooled );
        }

        /** @param array $cfg Source config. @return string Transient key. */
        private static function cooldown_key( $cfg ) {
                return self::COOLDOWN_PREFIX . substr( md5( (string) ( $cfg['base_url'] ?? '' ) . '|' . (string) ( $cfg['model'] ?? '' ) ), 0, 32 );
        }

        /**
         * Bench a source that just failed.
         *
         * @param array $cfg Source config.
         * @return void
         */
        private static function cool( $cfg ) {
                set_transient( self::cooldown_key( $cfg ), time() + self::FAILOVER_COOLDOWN, self::FAILOVER_COOLDOWN );
        }

        /**
         * Return a source to full health the moment a probe succeeds — the
         * bench transient would otherwise keep demoting a recovered source
         * (and stripping its retry budget) for up to five more minutes.
         *
         * @param array $cfg Source config.
         * @return void
         */
        private static function uncool( $cfg ) {
                delete_transient( self::cooldown_key( $cfg ) );
        }

        /**
         * Seconds left on a source's cooldown, 0 when healthy. Public so the
         * provider tab can show which source is benched.
         *
         * @param array $cfg Source config (needs base_url at least).
         * @return int
         */
        public static function cooldown_left( $cfg ) {
                $until = get_transient( self::cooldown_key( $cfg ) );
                return is_numeric( $until ) ? max( 0, (int) $until - time() ) : 0;
        }

        /**
         * Single HTTP round-trip.
         *
         * @param Haa_Adapter_Base $adapter  Adapter.
         * @param array            $messages Turns.
         * @param array            $opts     Options.
         * @return string|WP_Error
         */
        private function request_once( $adapter, $messages, $opts ) {
                // Every attempt answers for itself: a retry that fails, or a reply served
                // from cache without a body to read, must not inherit the last verdict.
                self::$last_truncated = false;

                // 2.1.31: a caller-set cap (the keyword panel's bounded wait) wins
                // over the configured «مهلت پاسخ», clamped to a sane 10–90s window.
                $eff_timeout = (int) ( $opts['timeout'] ?? 0 ) > 0
                        ? max( 10, min( 90, (int) $opts['timeout'] ) )
                        : max( 5, (int) $this->cfg['timeout'] );

                $args = array(
                        'timeout'     => $eff_timeout,
                        'redirection' => 0,
                        'httpversion' => '1.1',
                        'headers'     => $adapter->headers(),
                        'body'        => $adapter->body( $messages, $opts ),
                        'data_format' => 'body',
                );
                if ( ! is_string( $args['body'] ) || '' === trim( $args['body'] ) ) {
                        // json_encode false on invalid UTF-8 or a payload that fell
                        // apart: shipping it would mean an opaque provider 400.
                        return new WP_Error( 'haai_body', __( 'بدنه‌ی درخواست ساخته نشد (معمولاً متن حاوی کاراکتر خراب است). دوباره تلاش کنید.', 'hami-ai-assistant' ) );
                }

                $url = $adapter->endpoint();

                // Runtime SSRF guard (cached per host for 5 min).
                $safe = self::is_url_safe( $url );
                if ( is_wp_error( $safe ) ) {
                        return $safe;
                }

                $args = $this->apply_proxy( $args, $url );
                $res  = wp_remote_post( $url, $args );

                if ( is_wp_error( $res ) ) {
                        return $this->map_transport_error( $res, $eff_timeout );
                }

                $code = (int) wp_remote_retrieve_response_code( $res );
                $raw  = wp_remote_retrieve_body( $res );
                $json = json_decode( $raw, true );

                if ( $code < 200 || $code >= 300 ) {
                        return $this->map_http_error( $code, is_array( $json ) ? $json : array(), $raw );
                }

                self::$last_truncated = $adapter->truncated( is_array( $json ) ? $json : array() );

                $reply = $adapter->extract( is_array( $json ) ? $json : array() );
                if ( ! is_string( $reply ) || '' === trim( $reply ) ) {
                        // Why was the 200 empty? Safety block, truncation, function
                        // call without text — name it, so the diagnostic row tells
                        // the truth instead of a shrug.
                        $detail = '';
                        if ( isset( $json['promptFeedback']['blockReason'] ) ) {
                                $detail = ' [block: ' . (string) $json['promptFeedback']['blockReason'] . ']';
                        } elseif ( isset( $json['candidates'][0]['finishReason'] ) && 'STOP' !== (string) $json['candidates'][0]['finishReason'] ) {
                                $detail = ' [finish: ' . (string) $json['candidates'][0]['finishReason'] . ']';
                        } elseif ( isset( $json['stop_reason'] ) && 'end_turn' !== (string) $json['stop_reason'] ) {
                                $detail = ' [finish: ' . (string) $json['stop_reason'] . ']';
                        }
                        return new WP_Error( 'haai_empty', __( 'پاسخ سرویس AI خالی یا بی‌ساختار بود. دوباره بزنید؛ اگر تکرار شد، «مدل چت» و تنظیمات ارائه‌دهنده را بررسی کنید.', 'hami-ai-assistant' ) . $detail );
                }
                return trim( $reply );
        }

        /* ------------------------------------------------------------------
         * Images
         * ---------------------------------------------------------------- */

        /**
         * Text-to-image. Only OpenAI-compatible and Gemini are supported.
         * Walks the failover sources too, skipping the ones that have no
         * image endpoint at all (Claude).
         *
         * @param string $prompt Prompt.
         * @param array  $opts   size, quality, model.
         * @return string|WP_Error URL of the generated image.
         */
        public function image( $prompt, $opts = array() ) {
                $opts = wp_parse_args(
                        $opts,
                        array(
                                'model'   => Haa_Settings::image_model(),
                                'size'    => Haa_Settings::get( 'content.image_size', '1024x1024' ),
                                'quality' => Haa_Settings::get( 'content.image_quality', 'standard' ),
                                'module'  => 'content',
                        )
                );

                $candidates = $this->candidates();
                if ( 1 === count( $candidates ) && '' === ( $candidates[0]['api_key'] ?? '' ) && ! self::cfg_is_keyless( $candidates[0] ) ) {
                        return new WP_Error( 'haai_no_key', __( 'کلید API برای تولید تصویر تنظیم نشده است.', 'hami-ai-assistant' ) );
                }

                // Same reason as chat(): the loop rebinds $this->cfg to each source.
                $origin  = $this->cfg;
                $started = microtime( true );
                $res     = null;

                foreach ( $candidates as $cfg ) {
                        if ( 'claude' === ( $cfg['adapter'] ?? 'openai' ) ) {
                                continue; // Anthropic has no image endpoint — never a "failure", just skip.
                        }
                        if ( self::bench_fresh( $cfg ) ) {
                                continue; // benched seconds ago — an attempt now is pure waste.
                        }
                        $this->cfg = $cfg;
                        $was_benched = self::cooldown_left( $cfg ) > 0;
                        $res       = 'gemini' === $cfg['adapter']
                                ? $this->image_gemini( $prompt, $opts )
                                : $this->image_openai( $prompt, $opts );
                        if ( ! is_wp_error( $res ) ) {
                                if ( $was_benched ) {
                                        self::uncool( $cfg );
                                }
                                break;
                        }
                        if ( ! $was_benched ) {
                                self::cool( $cfg ); // a failing probe must not restart the bench clock.
                        }
                }

                $this->cfg = $origin;

                if ( null === $res ) {
                        // Nothing was even attempted (everything freshly benched or
                        // Claude-only): report honestly instead of a fake success.
                        $res = new WP_Error( 'haai_all_benched', __( 'همه منابع در حال خطا هستند؛ چند لحظه بعد دوباره تلاش کنید.', 'hami-ai-assistant' ) );
                }

                $latency = (int) round( ( microtime( true ) - $started ) * 1000 );

                Haa_Usage::log(
                        array(
                                'module'     => $opts['module'],
                                'model'      => $opts['model'],
                                'operation'  => 'image',
                                'tokens_in'  => 0,
                                'tokens_out' => 0,
                                'latency_ms' => $latency,
                                'ok'         => is_wp_error( $res ) ? 0 : 1,
                                'error_code' => is_wp_error( $res ) ? $res->get_error_code() : '',
                        )
                );

                return $res;
        }

        /**
         * @param string $prompt Prompt.
         * @param array  $opts   Options.
         * @return string|WP_Error
         */
        private function image_openai( $prompt, $opts ) {
                $url = untrailingslashit( $this->cfg['base_url'] ) . '/images/generations';

                // Runtime SSRF guard.
                $safe = self::is_url_safe( $url );
                if ( is_wp_error( $safe ) ) {
                        return $safe;
                }

                $args = array(
                        'timeout' => max( 20, (int) $this->cfg['timeout'] ),
                        'headers' => array(
                                'Content-Type'  => 'application/json',
                                'Authorization' => 'Bearer ' . $this->cfg['api_key'],
                        ),
                        'body'    => wp_json_encode(
                                array(
                                        'model'   => $opts['model'],
                                        'prompt'  => $prompt,
                                        'n'       => 1,
                                        'size'    => $opts['size'],
                                        'quality' => $opts['quality'],
                                        'response_format' => 'url',
                                )
                        ),
                );
                $args = $this->apply_proxy( $args, $url );
                $res  = wp_remote_post( $url, $args );

                if ( is_wp_error( $res ) ) {
                        return $this->map_transport_error( $res );
                }
                $code = (int) wp_remote_retrieve_response_code( $res );
                $json = json_decode( wp_remote_retrieve_body( $res ), true );
                if ( $code < 200 || $code >= 300 ) {
                        return $this->map_http_error( $code, is_array( $json ) ? $json : array(), wp_remote_retrieve_body( $res ) );
                }
                if ( isset( $json['data'][0]['url'] ) ) {
                        return (string) $json['data'][0]['url'];
                }
                if ( isset( $json['data'][0]['b64_json'] ) ) {
                        return 'data:image/png;base64,' . $json['data'][0]['b64_json'];
                }
                return new WP_Error( 'haai_empty_image', __( 'تصویری در پاسخ سرویس نبود.', 'hami-ai-assistant' ) );
        }

        /**
         * @param string $prompt Prompt.
         * @param array  $opts   Options.
         * @return string|WP_Error
         */
        private function image_gemini( $prompt, $opts ) {
                $url = untrailingslashit( $this->cfg['base_url'] )
                        . '/models/' . rawurlencode( $opts['model'] ) . ':generateContent';
                $url = add_query_arg( 'key', rawurlencode( $this->cfg['api_key'] ), $url );

                // Runtime SSRF guard.
                $safe = self::is_url_safe( $url );
                if ( is_wp_error( $safe ) ) {
                        return $safe;
                }

                $args = array(
                        'timeout' => max( 20, (int) $this->cfg['timeout'] ),
                        'headers' => array( 'Content-Type' => 'application/json' ),
                        'body'    => wp_json_encode(
                                array(
                                        'contents'         => array( array( 'parts' => array( array( 'text' => $prompt ) ) ) ),
                                        'generationConfig' => array( 'responseModalities' => array( 'TEXT', 'IMAGE' ) ),
                                )
                        ),
                );
                $args = $this->apply_proxy( $args, $url );
                $res  = wp_remote_post( $url, $args );

                if ( is_wp_error( $res ) ) {
                        return $this->map_transport_error( $res );
                }
                $code = (int) wp_remote_retrieve_response_code( $res );
                $json = json_decode( wp_remote_retrieve_body( $res ), true );
                if ( $code < 200 || $code >= 300 ) {
                        return $this->map_http_error( $code, is_array( $json ) ? $json : array(), wp_remote_retrieve_body( $res ) );
                }

                $parts = isset( $json['candidates'][0]['content']['parts'] ) ? $json['candidates'][0]['content']['parts'] : array();
                foreach ( $parts as $part ) {
                        if ( isset( $part['inlineData']['data'] ) ) {
                                $mime = isset( $part['inlineData']['mimeType'] ) ? $part['inlineData']['mimeType'] : 'image/png';
                                return 'data:' . $mime . ';base64,' . $part['inlineData']['data'];
                        }
                }
                return new WP_Error( 'haai_empty_image', __( 'تصویری در پاسخ سرویس نبود.', 'hami-ai-assistant' ) );
        }

        /* ------------------------------------------------------------------
         * Embeddings
         * ---------------------------------------------------------------- */

        /**
         * Embed one or more strings.
         *
         * @param array<int,string> $texts Texts.
         * @param array             $opts  model.
         * @return array<int,array>|WP_Error List of vectors.
         */
        public function embed( $texts, $opts = array() ) {
                $opts = wp_parse_args(
                        $opts,
                        array(
                                'model'  => Haa_Settings::embedding_model(),
                                'module' => 'semantic-search',
                        )
                );

                if ( '' === $opts['model'] ) {
                        return new WP_Error( 'haai_no_embed_model', __( 'مدل Embedding برای این ارائه‌دهنده تعریف نشده است.', 'hami-ai-assistant' ) );
                }
                if ( '' === ( $this->cfg['api_key'] ?? '' ) && ! $this->is_keyless() ) {
                        return new WP_Error( 'haai_no_key', __( 'کلید API تنظیم نشده است.', 'hami-ai-assistant' ) );
                }

                $adapter = $this->cfg['adapter'] ?? 'openai';
                $started = microtime( true );

                if ( 'gemini' === $adapter ) {
                        $res = $this->embed_gemini( $texts, $opts );
                } elseif ( 'claude' === $adapter ) {
                        $res = new WP_Error( 'haai_no_embed', __( 'Anthropic مدل Embedding ندارد. جستجوی معنایی با BM25 اجرا می‌شود.', 'hami-ai-assistant' ) );
                } else {
                        $res = $this->embed_openai( $texts, $opts );
                }

                Haa_Usage::log(
                        array(
                                'module'     => $opts['module'],
                                'model'      => $opts['model'],
                                'operation'  => 'embed',
                                'tokens_in'  => $this->estimate_tokens( array_map( array( 'Haa_API', 'wrap' ), $texts ) ),
                                'tokens_out' => 0,
                                'latency_ms' => (int) round( ( microtime( true ) - $started ) * 1000 ),
                                'ok'         => is_wp_error( $res ) ? 0 : 1,
                                'error_code' => is_wp_error( $res ) ? $res->get_error_code() : '',
                        )
                );

                return $res;
        }

        /**
         * @param string $text Text.
         * @return array
         */
        public static function wrap( $text ) {
                return array( 'content' => (string) $text );
        }

        /**
         * @param array<int,string> $texts Texts.
         * @param array             $opts  Options.
         * @return array|WP_Error
         */
        private function embed_openai( $texts, $opts ) {
                $url = untrailingslashit( $this->cfg['base_url'] ) . '/embeddings';

                // Runtime SSRF guard.
                $safe = self::is_url_safe( $url );
                if ( is_wp_error( $safe ) ) {
                        return $safe;
                }

                $args = array(
                        'timeout' => max( 15, (int) $this->cfg['timeout'] ),
                        'headers' => array(
                                'Content-Type'  => 'application/json',
                                'Authorization' => 'Bearer ' . $this->cfg['api_key'],
                        ),
                        'body'    => wp_json_encode( array( 'model' => $opts['model'], 'input' => array_values( $texts ) ) ),
                );
                $args = $this->apply_proxy( $args, $url );
                $res  = wp_remote_post( $url, $args );

                if ( is_wp_error( $res ) ) {
                        return $this->map_transport_error( $res );
                }
                $code = (int) wp_remote_retrieve_response_code( $res );
                $json = json_decode( wp_remote_retrieve_body( $res ), true );
                if ( $code < 200 || $code >= 300 ) {
                        return $this->map_http_error( $code, is_array( $json ) ? $json : array(), wp_remote_retrieve_body( $res ) );
                }
                if ( empty( $json['data'] ) || ! is_array( $json['data'] ) ) {
                        return new WP_Error( 'haai_empty_embed', __( 'برداری در پاسخ نبود.', 'hami-ai-assistant' ) );
                }
                usort( $json['data'], static function ( $a, $b ) {
                        return ( $a['index'] ?? 0 ) <=> ( $b['index'] ?? 0 );
                } );
                $out = array();
                foreach ( $json['data'] as $row ) {
                        $out[] = isset( $row['embedding'] ) ? array_map( 'floatval', $row['embedding'] ) : array();
                }
                return $out;
        }

        /**
         * @param array<int,string> $texts Texts.
         * @param array             $opts  Options.
         * @return array|WP_Error
         */
        private function embed_gemini( $texts, $opts ) {
                $url = untrailingslashit( $this->cfg['base_url'] )
                        . '/models/' . rawurlencode( $opts['model'] ) . ':batchEmbedContents';
                $url = add_query_arg( 'key', rawurlencode( $this->cfg['api_key'] ), $url );

                // Runtime SSRF guard.
                $safe = self::is_url_safe( $url );
                if ( is_wp_error( $safe ) ) {
                        return $safe;
                }

                $requests = array();
                foreach ( $texts as $text ) {
                        $requests[] = array( 'model' => 'models/' . $opts['model'], 'content' => array( 'parts' => array( array( 'text' => (string) $text ) ) ) );
                }

                $args = array(
                        'timeout' => max( 15, (int) $this->cfg['timeout'] ),
                        'headers' => array( 'Content-Type' => 'application/json' ),
                        'body'    => wp_json_encode( array( 'requests' => $requests ) ),
                );
                $args = $this->apply_proxy( $args, $url );
                $res  = wp_remote_post( $url, $args );

                if ( is_wp_error( $res ) ) {
                        return $this->map_transport_error( $res );
                }
                $code = (int) wp_remote_retrieve_response_code( $res );
                $json = json_decode( wp_remote_retrieve_body( $res ), true );
                if ( $code < 200 || $code >= 300 ) {
                        return $this->map_http_error( $code, is_array( $json ) ? $json : array(), wp_remote_retrieve_body( $res ) );
                }
                if ( empty( $json['embeddings'] ) ) {
                        return new WP_Error( 'haai_empty_embed', __( 'برداری در پاسخ نبود.', 'hami-ai-assistant' ) );
                }
                $out = array();
                foreach ( $json['embeddings'] as $row ) {
                        $out[] = isset( $row['values'] ) ? array_map( 'floatval', $row['values'] ) : array();
                }
                return $out;
        }

        /* ------------------------------------------------------------------
         * Models
         * ---------------------------------------------------------------- */

        /**
         * Model list from the provider's /models endpoint, cached for one day.
         *
         * @param array|null $cfg Optional provider override.
         * @return array|WP_Error Array of model ids.
         */
        public function models( $cfg = null ) {
                if ( null !== $cfg ) {
                        $this->cfg = $cfg;
                }
                $adapter = $this->adapter();
                if ( ! $adapter->supports_models() ) {
                        return new WP_Error( 'haai_no_models', __( 'این ارائه‌دهنده فهرست مدل ندارد. مدل را دستی وارد کنید.', 'hami-ai-assistant' ) );
                }

                $cache_key = self::MODELS_TRANSIENT . substr( md5( ( $this->cfg['base_url'] ?? '' ) . '|' . ( $this->cfg['api_key'] ?? '' ) ), 0, 32 );
                $cached    = get_transient( $cache_key );
                if ( false !== $cached ) {
                        return $cached;
                }

                $base = untrailingslashit( $this->cfg['base_url'] ?? '' );
                if ( '' === $base ) {
                        return new WP_Error( 'haai_no_base', __( 'آدرس Base URL خالی است.', 'hami-ai-assistant' ) );
                }

                if ( 'gemini' === ( $this->cfg['adapter'] ?? '' ) ) {
                        // Header auth — same as the chat path. Through a relay
                        // (Worker) the key must never ride in the URL, where it
                        // would land in the relay's access logs.
                        $url = $base . '/models';
                        $headers = array( 'Content-Type' => 'application/json' );
                        if ( ! empty( $this->cfg['api_key'] ) ) {
                                $headers['x-goog-api-key'] = (string) $this->cfg['api_key'];
                        }
                } else {
                        $url     = $base . '/models';
                        $headers = $adapter->headers();
                }

                // Runtime SSRF guard.
                $safe = self::is_url_safe( $url );
                if ( is_wp_error( $safe ) ) {
                        return $safe;
                }

                $args = $this->apply_proxy(
                        array(
                                'timeout' => max( 10, (int) $this->cfg['timeout'] ),
                                'headers' => $headers,
                        ),
                        $url
                );
                $res = wp_remote_get( $url, $args );

                if ( is_wp_error( $res ) ) {
                        return $this->map_transport_error( $res );
                }
                $code = (int) wp_remote_retrieve_response_code( $res );
                $json = json_decode( wp_remote_retrieve_body( $res ), true );
                if ( $code < 200 || $code >= 300 ) {
                        return $this->map_http_error( $code, is_array( $json ) ? $json : array(), wp_remote_retrieve_body( $res ) );
                }

                $ids = array();

                // OpenAI-compatible providers answer {"data":[{"id":…}]}.
                if ( isset( $json['data'] ) && is_array( $json['data'] ) ) {
                        foreach ( $json['data'] as $row ) {
                                if ( ! empty( $row['id'] ) ) {
                                        $ids[] = (string) $row['id'];
                                }
                        }
                }

                // Gemini answers {"models":[{"name":"models/gemini-…"}]} — a different
                // envelope and a different field, so it used to parse as an empty list.
                if ( ! $ids && isset( $json['models'] ) && is_array( $json['models'] ) ) {
                        foreach ( $json['models'] as $row ) {
                                if ( empty( $row['name'] ) ) {
                                        continue;
                                }
                                $name = (string) $row['name'];
                                $ids[] = false !== strpos( $name, '/' ) ? substr( $name, strrpos( $name, '/' ) + 1 ) : $name;
                        }
                }

                $ids = array_values( array_unique( $ids ) );
                sort( $ids );

                set_transient( $cache_key, $ids, DAY_IN_SECONDS );
                return $ids;
        }

        /**
         * Drop the cached model list (after a provider change or the test button).
         */
        public static function flush_models_cache() {
                global $wpdb;
                // Transients live in options; delete the ones we know the prefix of.
                // phpcs:ignore WordPress.DB
                $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s AND option_name NOT LIKE %s", $wpdb->esc_like( self::MODELS_TRANSIENT ) . '%', '_transient_timeout_%' ) );
                delete_option( '_transient_timeout_' . self::MODELS_TRANSIENT );
        }

        /* ------------------------------------------------------------------
         * Connectivity probe
         * ---------------------------------------------------------------- */

        /**
         * Cheap probe behind the admin "تست اتصال" button.
         *
         * @param array|null $cfg Optional override.
         * @return array{ok:bool,message:string,latency:int}
         */
        public function test_connection( $cfg = null ) {
                if ( null !== $cfg ) {
                        $this->cfg = $cfg;
                }

                // A probe is diagnostic: waiting the full configured timeout (up to
                // 300s) on a filtered/unreachable endpoint is hostile UX. Cap the
                // probe at 90s — a chat "ping" that needs longer is broken anyway.
                $this->cfg['timeout'] = min( 90, max( 5, (int) $this->cfg['timeout'] ) );

                $started = microtime( true );
                $reply   = $this->chat(
                        array( array( 'role' => 'user', 'content' => 'ping' ) ),
                        array(
                                'max_tokens'  => 8,
                                'temperature' => 0,
                                'module'      => 'admin',
                                'retries'     => 0,
                        )
                );
                $latency = (int) round( ( microtime( true ) - $started ) * 1000 );

                if ( is_wp_error( $reply ) ) {
                        return array(
                                'ok'      => false,
                                'message' => $reply->get_error_message(),
                                'latency' => $latency,
                        );
                }

                return array(
                        'ok'      => true,
                        'message' => sprintf(
                                /* translators: 1: model name 2: latency in ms */
                                __( 'اتصال برقرار شد. مدل: %1$s — پاسخ در %2$d میلی‌ثانیه.', 'hami-ai-assistant' ),
                                $this->cfg['model'],
                                $latency
                        ),
                        'latency' => $latency,
                        'model'   => $this->cfg['model'],
                );
        }

        /* ------------------------------------------------------------------
         * Internals
         * ---------------------------------------------------------------- */

        /**
         * Local endpoints (Ollama on 127.0.0.1) need no key.
         *
         * @return bool
         */
        private function is_keyless() {
                return self::cfg_is_keyless( $this->cfg );
        }

        /**
         * Keyless check for ANY source config (the primary or a failover row).
         *
         * @param array $cfg Source config.
         * @return bool
         */
        private static function cfg_is_keyless( $cfg ) {
                $host = (string) ( $cfg['base_url'] ?? '' );
                // `[::1]` is how a local server is often written (and how our own test
                // harness reaches it); without it a keyless Ollama/LM Studio on IPv6
                // loopback is rejected with "کلید API تنظیم نشده".
                return (bool) preg_match( '#https?://(?:127\.0\.0\.1|\[::1\]|localhost)(?=[:/]|$)#i', $host );
        }

        /**
         * Runtime SSRF guard: block private/reserved IPs at call time (not save
         * time). Result is cached for 5 minutes per host to avoid repeated DNS.
         *
         * Everything that is not a plain public address is refused:
         *   - bracketed IPv6 (parse_url returns "[::1]", which used to match no
         *     rule and then "failed to resolve", so it was ALLOWED — the hole);
         *   - IPv4-mapped IPv6 (::ffff:127.0.0.1, ::ffff:169.254.169.254);
         *   - ULA fc00::/7 and link-local fe80::/10;
         *   - decimal / hex / octal spellings of an IPv4 (2130706433, 0x7f000001,
         *     0177.0.0.1) which cURL happily resolves;
         *   - any hostname that resolves to one of the above (A or AAAA).
         * Loopback and private ranges need the explicit allow_localhost option;
         * the metadata/link-local and "this network" ranges are refused always.
         *
         * @param string $url Full request URL.
         * @return true|WP_Error
         */
        public static function is_url_safe( $url ) {
                static $cache = array();

                $parsed = parse_url( (string) $url );
                if ( ! $parsed || empty( $parsed['host'] ) ) {
                        return new WP_Error( 'haai_ssrf', __( 'آدرس سرویس نامعتبر است.', 'hami-ai-assistant' ) );
                }

                $host = strtolower( trim( $parsed['host'] ) );

                // An IPv6 literal keeps its brackets through parse_url. Stripping them
                // is what makes every rule below see the address at all.
                if ( 0 === strpos( $host, '[' ) && ']' === substr( $host, -1 ) ) {
                        $host = substr( $host, 1, -1 );
                }
                // fe80::1%eth0 — the zone id is not part of the address.
                $zone = strpos( $host, '%' );
                if ( false !== $zone ) {
                        $host = substr( $host, 0, $zone );
                }
                if ( '' === $host ) {
                        return new WP_Error( 'haai_ssrf', __( 'آدرس سرویس نامعتبر است.', 'hami-ai-assistant' ) );
                }

                $allow_local = (bool) Haa_Settings::get( 'provider.allow_localhost', 0 );

                // The verdict depends on the option, so the cache key must too.
                $key = $host . '|' . ( $allow_local ? 1 : 0 );
                if ( isset( $cache[ $key ] ) && $cache[ $key ]['t'] > time() - 300 ) {
                        return $cache[ $key ]['ok'];
                }

                // Numeric spellings of an IPv4 address are addresses, not hostnames.
                $decoded = self::decode_numeric_host( $host );
                if ( true === $decoded ) {
                        // Digits/hex that we cannot turn into one clean quad: not a name
                        // anyone can look up either. Refused rather than trusted.
                        $ok = new WP_Error( 'haai_ssrf', __( 'آدرس سرویس به شکل قابل قبولی نوشته نشده است (نشانه‌ی عددی IP مجاز نیست).', 'hami-ai-assistant' ) );
                        $cache[ $key ] = array( 't' => time(), 'ok' => $ok );
                        return $ok;
                }
                if ( is_string( $decoded ) ) {
                        $host = $decoded;
                }

                if ( self::host_is_name( $host ) ) {
                        if ( self::is_loopback_name( $host ) ) {
                                $ok = $allow_local
                                        ? true
                                        : new WP_Error( 'haai_ssrf', __( 'آدرس محلی مجاز نیست. در صورت نیاز، گزینه «اجازه آدرس محلی» را در تنظیمات فعال کنید.', 'hami-ai-assistant' ) );
                                $cache[ $key ] = array( 't' => time(), 'ok' => $ok );
                                return $ok;
                        }
                        // Resolve every record we can find and judge the addresses. If DNS
                        // answers nothing, let the HTTP layer produce its own error.
                        $addrs = self::resolve_host( $host );
                        if ( ! $addrs ) {
                                $cache[ $key ] = array( 't' => time(), 'ok' => true );
                                return true;
                        }
                        foreach ( $addrs as $ip ) {
                                $ok = self::judge_ip( $ip, $allow_local );
                                if ( is_wp_error( $ok ) ) {
                                        $cache[ $key ] = array( 't' => time(), 'ok' => $ok );
                                        return $ok;
                                }
                        }
                        $cache[ $key ] = array( 't' => time(), 'ok' => true );
                        return true;
                }

                // Anything left is an IP literal (IPv4 or IPv6).
                $ok = self::judge_ip( $host, $allow_local );
                $cache[ $key ] = array( 't' => time(), 'ok' => $ok );
                return $ok;
        }

        /**
         * Is this host a plain DNS name (as opposed to an IP literal)?
         *
         * @param string $host Lowercased host.
         * @return bool
         */
        private static function host_is_name( $host ) {
                if ( filter_var( $host, FILTER_VALIDATE_IP ) ) {
                        return false;
                }
                return (bool) preg_match( '/^[a-z0-9._\-]+$/', $host );
        }

        /** @return bool */
        private static function is_loopback_name( $host ) {
                return 'localhost' === $host || 0 === substr_compare( $host, '.localhost', -10 )
                        || 0 === substr_compare( $host, '.local', -6 ) || 0 === substr_compare( $host, '.internal', -9 );
        }

        /**
         * A + AAAA records of a host.
         *
         * @param string $host Hostname.
         * @return array<int,string> Addresses (possibly empty).
         */
        private static function resolve_host( $host ) {
                $ips = array();
                $v4  = gethostbynamel( $host );
                if ( is_array( $v4 ) ) {
                        $ips = array_merge( $ips, $v4 );
                }
                if ( function_exists( 'dns_get_record' ) ) {
                        $aaaa = @dns_get_record( $host, DNS_AAAA );
                        if ( is_array( $aaaa ) ) {
                                foreach ( $aaaa as $rec ) {
                                        if ( ! empty( $rec['ipv6'] ) ) {
                                                $ips[] = $rec['ipv6'];
                                        }
                                }
                        }
                }
                return array_values( array_unique( $ips ) );
        }

        /**
         * Recognise the number forms of an IPv4 address.
         *
         * @param string $host Host as written.
         * @return string|bool|null dotted quad when decoded, true when it is a
         *                          numeric form we cannot decode cleanly, null
         *                          when it is not numeric at all.
         */
        private static function decode_numeric_host( $host ) {
                if ( ! preg_match( '/^(?:0x[0-9a-f]+|\d+)(?:\.(?:0x[0-9a-f]+|\d+)){0,3}$/i', $host, $m ) ) {
                        return null; // a normal name (or a genuine IPv4, handled elsewhere)
                }
                if ( filter_var( $host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) {
                        return null; // already a dotted quad
                }
                $parts = explode( '.', $host );
                $bytes = array();
                foreach ( $parts as $part ) {
                        if ( 0 === stripos( $part, '0x' ) ) {
                                $n = hexdec( substr( $part, 2 ) );
                        } elseif ( strlen( $part ) > 1 && '0' === $part[0] ) {
                                $n = octdec( $part ); // leading zero is octal to inet_aton
                        } else {
                                $n = (int) $part;
                        }
                        if ( $n < 0 || $n > 255 ) {
                                return true; // out of range: refuse rather than guess
                        }
                        $bytes[] = (int) $n;
                }
                if ( 1 === count( $bytes ) ) {
                        // 2130706433 / 0x7f000001 — a whole 32-bit address.
                        $full = $bytes[0];
                        if ( $full > 4294967295 ) {
                                return true;
                        }
                        return long2ip( $full );
                }
                // Short forms (a.b, a.b.c) put the remaining octets in the last part;
                // cURL accepts them, we do not: any of these can hide a private host.
                return true;
        }

        /**
         * Verdict for one IP literal, IPv4 or IPv6.
         *
         * @param string $ip          Address.
         * @param bool   $allow_local Dev mode.
         * @return true|WP_Error
         */
        private static function judge_ip( $ip, $allow_local ) {
                if ( self::ip_is_always_forbidden( $ip ) ) {
                        return new WP_Error( 'haai_ssrf', __( 'آدرس link-local (نقطه‌ی دسترسی metadata) همیشه مسدود است.', 'hami-ai-assistant' ) );
                }
                if ( $allow_local ) {
                        return true; // dev mode: loopback and LAN proxies are fine
                }
                if ( self::ip_is_private( $ip ) ) {
                        return new WP_Error( 'haai_ssrf', __( 'آدرس سرویس به بازه IP خصوصی اشاره دارد و مجاز نیست.', 'hami-ai-assistant' ) );
                }
                return true;
        }

        /**
         * "This network", the cloud metadata range, unspecified and multicast —
         * refused even when allow_localhost is on.
         *
         * @param string $ip Address.
         * @return bool
         */
        private static function ip_is_always_forbidden( $ip ) {
                if ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) {
                        return (bool) preg_match( '/^(169\.254\.|0\.)/', $ip );
                }
                $packed = self::ip_bytes( $ip );
                if ( null === $packed ) {
                        return true; // unparseable is never allowed through
                }
                if ( 4 === strlen( $packed ) ) {
                        return self::ip_is_always_forbidden( self::bytes_to_ipv4( $packed ) );
                }
                $b0 = ord( $packed[0] );
                $b1 = ord( $packed[1] );
                if ( '' === rtrim( substr( $packed, 0, 15 ), "\0" ) && 0 === ord( $packed[15] ) ) {
                        return true; // ::
                }
                if ( 0xff === $b0 ) {
                        return true; // ff00::/8 multicast
                }
                if ( 0xfe === ( $b0 & 0xfe ) && 0x80 === ( $b1 & 0xc0 ) ) {
                        return true; // fe80::/10 link-local
                }
                // ::ffff:169.254.169.254 and friends
                $mapped = self::ipv4_mapped( $packed );
                if ( '' !== $mapped ) {
                        return self::ip_is_always_forbidden( $mapped );
                }
                return false;
        }

        /**
         * Private/reserved in the RFC1918 + IPv6 sense.
         *
         * @param string $ip Address.
         * @return bool
         */
        private static function ip_is_private( $ip ) {
                if ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) {
                        return filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) === false;
                }
                $packed = self::ip_bytes( $ip );
                if ( null === $packed ) {
                        return true;
                }
                if ( 4 === strlen( $packed ) ) {
                        return self::ip_is_private( self::bytes_to_ipv4( $packed ) );
                }
                $mapped = self::ipv4_mapped( $packed );
                if ( '' !== $mapped ) {
                        return self::ip_is_private( $mapped );
                }
                if ( 0xc0 === ( ord( $packed[0] ) & 0xc0 ) ) {
                        return true; // fc00::/7 unique local
                }
                if ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) === false ) {
                        return true; // ::1 and anything PHP itself calls reserved
                }
                return false;
        }

        /**
         * Four raw bytes → dotted quad.
         *
         * @param string $packed 4-byte string.
         * @return string
         */
        private static function bytes_to_ipv4( $packed ) {
                return ord( $packed[0] ) . '.' . ord( $packed[1] ) . '.' . ord( $packed[2] ) . '.' . ord( $packed[3] );
        }

        /**
         * Binary form of an address, or null. IPv4 may arrive in IPv4 or IPv6
         * packed length depending on inet_pton's view, so both are accepted.
         *
         * @param string $ip Address.
         * @return string|null
         */
        private static function ip_bytes( $ip ) {
                if ( ! function_exists( 'inet_pton' ) ) {
                        // No sockets extension: judge IPv4 only, and refuse IPv6 outright
                        // rather than wave an unparseable address through.
                        if ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) {
                                $p = explode( '.', $ip );
                                return chr( (int) $p[0] ) . chr( (int) $p[1] ) . chr( (int) $p[2] ) . chr( (int) $p[3] );
                        }
                        return null;
                }
                $packed = @inet_pton( $ip );
                return ( false === $packed || '' === $packed ) ? null : $packed;
        }

        /**
         * The IPv4 inside ::ffff:a.b.c.d (IPv4-mapped IPv6), or ''.
         *
         * @param string $packed 16-byte IPv6 address.
         * @return string
         */
        private static function ipv4_mapped( $packed ) {
                if ( 16 !== strlen( $packed ) ) {
                        return '';
                }
                if ( 10 !== strspn( $packed, "\0", 0, 10 ) || "\xff" !== $packed[10] || "\xff" !== $packed[11] ) {
                        return '';
                }
                return ord( $packed[12] ) . '.' . ord( $packed[13] ) . '.' . ord( $packed[14] ) . '.' . ord( $packed[15] );
        }

        /** @var array{proxy:string, auth:string, match:string}|null Pending proxy for the next AI request — one-shot, URL-scoped. */
        private static $pending_proxy = null;

        /** @var bool Whether the http_api_curl hook is registered. */
        private static $proxy_hook_registered = false;

        /**
         * @param array $args wp_remote_* args.
         * @param string $url Endpoint URL the request is about to hit.
         * @return array
         */
        private function apply_proxy( $args, $url = '' ) {
                return self::proxy_args( $args, $this->cfg, $url );
        }

        /**
         * Apply the provider's proxy settings to ANY outbound AI request — not just
         * chat. Competitor research, SERP calls and Google Suggest all ride the
         * same relay, because on an Iranian host those destinations are blocked in
         * exactly the same way the AI endpoints are.
         *
         * HOW the proxy actually reaches cURL (the part that is easy to get
         * wrong): WordPress core IGNORES a 'proxy' key passed in wp_remote_* args
         * — WP_Http::request() builds the Requests options explicitly and the
         * only proxy core injects comes from the WP_PROXY_* constants via
         * WP_HTTP_Proxy. Requests' own transports consume proxies from Proxy
         * objects, which core only creates for those constants. The honest lever
         * left to a plugin is the http_api_curl action, fired with the real cURL
         * handle right before curl_exec: we stash the pending proxy here and
         * on_curl_handle() stamps CURLOPT_PROXY / CURLOPT_PROXYUSERPWD onto the
         * handle whose request URL matches. One-shot and URL-scoped, so no other
         * plugin's or WordPress's own HTTP traffic is ever routed through it.
         *
         * @param array     $args wp_remote_* args.
         * @param array|null $cfg  Provider config; null reads the stored settings.
         * @param string    $match_url Endpoint URL this request will hit.
         * @return array
         */
        public static function proxy_args( $args, $cfg = null, $match_url = '' ) {
                if ( null === $cfg ) {
                        $cfg = (array) Haa_Settings::get( 'provider', array() );
                }
                if ( empty( $cfg['proxy_host'] ) ) {
                        return $args;
                }

                $proxy_url = self::proxy_url( $cfg );
                if ( '' === $proxy_url ) {
                        return $args;
                }

                self::$pending_proxy = array(
                        'proxy' => $proxy_url,
                        'auth'  => ! empty( $cfg['proxy_username'] )
                                ? (string) $cfg['proxy_username'] . ':' . (string) ( $cfg['proxy_password'] ?? '' )
                                : '',
                        'match' => (string) $match_url,
                );

                if ( ! self::$proxy_hook_registered && function_exists( 'add_action' ) ) {
                        add_action( 'http_api_curl', array( __CLASS__, 'on_curl_handle' ), 10, 3 );
                        self::$proxy_hook_registered = true;
                }

                // The TLS handshake now terminates at the proxy hop; keep
                // verification relaxed, like every other proxy client.
                $args['sslverify'] = false;
                if ( ! isset( $args['headers'] ) ) {
                        $args['headers'] = array();
                }
                $args['headers']['Expect'] = '';
                return $args;
        }

        /**
         * Build the scheme-prefixed proxy string cURL understands. The scheme
         * may ride inside the host field (socks5://host, socks5h://host — the h
         * variant resolves DNS at the proxy, which matters when the host's own
         * resolver lies); plain hosts speak http. cURL switches the proxy type
         * from the prefix itself.
         *
         * @param array $cfg Provider config.
         * @return string scheme://host:port, or '' when unusable.
         */
        private static function proxy_url( $cfg ) {
                $raw = trim( (string) ( $cfg['proxy_host'] ?? '' ) );
                if ( '' === $raw ) {
                        return '';
                }
                $scheme = 'http';
                $host   = $raw;
                if ( preg_match( '#^([a-z][a-z0-9+.\-]*)://#i', $raw, $m ) ) {
                        $scheme = strtolower( $m[1] );
                        $host   = substr( $raw, strlen( $m[0] ) );
                }
                // A user:pass part must not ride inside the option (it leaks into
                // debug logs); cURL gets it via PROXYUSERPWD instead.
                if ( false !== strpos( $host, '@' ) ) {
                        $host = substr( (string) strrchr( $host, '@' ), 1 );
                }
                $host = trim( $host, '/' );
                if ( '' === $host ) {
                        return '';
                }
                $port = ! empty( $cfg['proxy_port'] ) ? (int) $cfg['proxy_port'] : 8080;
                return $scheme . '://' . $host . ':' . $port;
        }

        /**
         * http_api_curl callback: stamp the pending proxy onto the real cURL
         * handle right before curl_exec. Consumes the pending entry only when
         * the request URL matches what proxy_args() stashed — a nested HTTP
         * call from another plugin inside our window is left untouched.
         *
         * @param resource|\CurlHandle &$handle     cURL handle (by reference).
         * @param array                $parsed_args WP_Http args.
         * @param string               $url         Request URL.
         */
        public static function on_curl_handle( &$handle, $parsed_args = array(), $url = '' ) {
                $pending = self::$pending_proxy;
                if ( null === $pending ) {
                        return;
                }
                if ( '' !== $pending['match'] && (string) $url !== $pending['match'] ) {
                        return;
                }
                self::$pending_proxy = null; // consumed.

                $is_handle = is_resource( $handle )
                        || ( class_exists( 'CurlHandle' ) && $handle instanceof CurlHandle );
                if ( $is_handle ) {
                        curl_setopt_array( $handle, self::curl_proxy_options( $pending['proxy'], $pending['auth'] ) );
                }
        }

        /**
         * The raw cURL options for a proxy — pure, so the suite can assert them.
         *
         * @param string $proxy_url scheme://host:port.
         * @param string $auth      user:pass or ''.
         * @return array
         */
        public static function curl_proxy_options( $proxy_url, $auth = '' ) {
                $opts = array(
                        CURLOPT_PROXY => (string) $proxy_url,
                );
                if ( '' !== (string) $auth ) {
                        $opts[ CURLOPT_PROXYAUTH ]    = CURLAUTH_ANY;
                        $opts[ CURLOPT_PROXYUSERPWD ] = (string) $auth;
                }
                return $opts;
        }

        /**
         * @param WP_Error $res            Transport error.
         * @param int      $waited_seconds Effective timeout of THIS attempt when the
         *                                 caller capped it (2.1.31); 0 = configured value.
         * @return WP_Error
         */
        private function map_transport_error( $res, $waited_seconds = 0 ) {
                $msg       = $res->get_error_message();
                $has_proxy = ! empty( $this->cfg['proxy_host'] );

                $is_timeout = false !== stripos( $msg, 'timed out' )
                        || false !== stripos( $msg, 'timeout' )
                        || 'http_request_timeout' === $res->get_error_code();
                $is_dns     = false !== stripos( $msg, 'Could not resolve host' )
                        || false !== stripos( $msg, 'Name or service not known' )
                        || false !== stripos( $msg, 'cURL error 6' );
                $is_connect = false !== stripos( $msg, 'Failed to connect' )
                        || false !== stripos( $msg, 'cURL error 7' );

                // A broken proxy is a different fault from a blocked host, and the fix
                // is different too, so it gets its own message. cURL names the proxy
                // outright in some shapes (5/9); with a proxy configured, a connect or
                // timeout failure most often happened *at* the proxy, so the admin is
                // pointed there first. Not retryable on purpose, like the DNS case.
                if ( false !== stripos( $msg, 'proxy' )
                        || false !== stripos( $msg, 'cURL error 5' )
                        || false !== stripos( $msg, 'cURL error 9' )
                        || ( $has_proxy && ( $is_connect || $is_timeout || $is_dns ) ) ) {
                        return new WP_Error( 'haai_proxy', __( 'پراکسی تنظیم‌شده پاسخ نداد. آدرس و پورت پراکسی (و نام کاربری/رمز آن) را در تب «ارائه‌دهنده AI» بررسی کنید، یا موقتاً آن را خالی بگذارید و دوباره بزنید.', 'hami-ai-assistant' ) );
                }

                if ( $is_timeout ) {
                        // The seconds make "increase the timeout" actionable; the second
                        // sentence is honest about the case where increasing it cannot
                        // help — filtered endpoints (Google/OpenAI/Claude/xAI from
                        // Iranian IPs) hang silently until any timeout expires.
                        $waited = $waited_seconds > 0 ? (int) $waited_seconds : max( 5, (int) $this->cfg['timeout'] );
                        return new WP_Error( 'haai_timeout', sprintf(
                                /* translators: %d: seconds waited */
                                __( 'مهلت اتصال به سرویس AI تمام شد (%1$d ثانیه پاسخی نرسید). یک‌بار دیگر بزنید؛ اگر تکرار شد «مهلت پاسخ» را در تب «ارائه‌دهنده AI» بیشتر کنید. اگر باز هم نشد، احتمالاً این سرویس از سرور شما در دسترس نیست (Google، OpenAI، Claude و xAI از IP ایران معمولاً فیلتر هستند) — پراکسی را در همین تب تنظیم کنید یا Base URL را روی یک رله مثل OpenRouter بگذارید.', 'hami-ai-assistant' ),
                                $waited
                        ) );
                }
                if ( $is_dns ) {
                        return new WP_Error( 'haai_dns', __( 'دامنه سرویس AI پیدا نشد. احتمالاً هاست شما دسترسی مستقیم ندارد — Base URL رله یا پراکسی تنظیم کنید و دوباره بزنید.', 'hami-ai-assistant' ) );
                }
                if ( $is_connect ) {
                        return new WP_Error( 'haai_blocked', __( 'اتصال به سرویس برقرار نشد. این سرویس ممکن است در ایران مسدود باشد — از رله یا پراکسی استفاده کنید و دوباره بزنید.', 'hami-ai-assistant' ) );
                }
                if ( false !== stripos( $msg, 'SSL' ) || false !== stripos( $msg, 'certificate' ) ) {
                        return new WP_Error( 'haai_ssl', __( 'خطای گواهی SSL. در صورت استفاده از پراکسی داخلی، sslverify را بررسی کنید.', 'hami-ai-assistant' ) );
                }
                return new WP_Error( 'haai_transport', sprintf( /* translators: %s: technical detail */ __( 'خطای ارتباط با سرویس AI: %s', 'hami-ai-assistant' ), $msg ) );
        }

        /**
         * @param int   $code HTTP status.
         * @param array $json Decoded body.
         * @param string $raw Raw body.
         * @return WP_Error
         */
        private function map_http_error( $code, $json, $raw ) {
                $detail = '';
                if ( isset( $json['error']['message'] ) ) {
                        $detail = (string) $json['error']['message'];
                } elseif ( isset( $json['error']['status'] ) ) {
                        $detail = (string) $json['error']['status'];
                } elseif ( isset( $json['message'] ) ) {
                        $detail = (string) $json['message'];
                } elseif ( is_string( $raw ) && '' !== $raw ) {
                        $detail = wp_html_excerpt( $raw, 180 );
                }

                // Providers echo request fragments back in their error text — including
                // the key they just rejected. The Persian sentence is what the UI shows;
                // the raw text travels only masked and truncated, and never to a visitor.
                $detail = self::mask_secrets( $detail );

                switch ( $code ) {
                        case 401:
                                $msg = __( 'کلید API نامعتبر است یا مدل انتخابی در اکانت شما فعال نیست.', 'hami-ai-assistant' );
                                break;
                        case 403:
                                if ( self::looks_like_geoblock( $detail, (string) ( $this->cfg['base_url'] ?? '' ) ) ) {
                                        $msg = __( 'دسترسی مجاز نیست — به احتمال زیاد این سرویس درخواست‌های IP ایران را نمی‌پذیرد (تحریم/محدودیت جغرافیایی) و مشکل از کلید یا اکانت نیست. پراکسی را در تب «ارائه‌دهنده AI» تنظیم کنید یا Base URL را روی یک رله بگذارید (Cloudflare Worker، OpenRouter یا سرویس واسط) و دوباره «تست اتصال» بزنید.', 'hami-ai-assistant' );
                                } else {
                                        $msg = __( 'دسترسی مجاز نیست. سطح اکانت یا کلید را بررسی کنید.', 'hami-ai-assistant' );
                                }
                                break;
                        case 404:
                                $msg = __( 'آدرس Endpoint یا مدل پیدا نشد. Base URL و نام مدل را بررسی کنید.', 'hami-ai-assistant' );
                                break;
                        case 402:
                                $msg = __( 'اعتبار اکانت سرویس AI تمام شده است.', 'hami-ai-assistant' );
                                break;
                        case 408:
                        case 429:
                                // 2.1.35: Gemini's free-tier wall («You exceeded your
                                // current quota … check your plan and billing details»)
                                // is NOT a «wait a second» throttle — it is a
                                // per-minute/per-day quota ceiling whose fix is waiting
                                // in minutes or a billing change. The old line said
                                // «موقتاً محدود کرده است» and then drowned the panel in
                                // the raw English paragraph cut mid-word (the 2.1.34
                                // live report). The quota line says what to do and
                                // sheds the wall; plain throttles keep the old shape.
                                if ( self::looks_like_quota( $detail ) ) {
                                        $msg    = __( 'سهمیهٔ کلید مدل پر شده است (۴۲۹). چند دقیقهٔ دیگر دوباره بیازمایید؛ اگر تکرار شد، طرح اکانت را ارتقا دهید یا کلید/مدل دیگری تنظیم کنید.', 'hami-ai-assistant' );
                                        $detail = '';
                                } else {
                                        $msg = __( 'سرویس موقتاً درخواست‌ها را محدود کرده است. چند لحظه بعد دوباره تلاش کنید.', 'hami-ai-assistant' );
                                }
                                break;
                        case 504:
                                $msg = __( 'سرویس AI یا پراکسی میانی مهلت پاسخ را تمام نکرد (۵۰۴). دوباره بزنید؛ اگر تکرار شد «مهلت پاسخ» و تنظیمات ارائه‌دهنده یا پراکسی را بررسی کنید.', 'hami-ai-assistant' );
                                break;
                        case 502:
                        case 503:
                                $msg = __( 'سرویس AI موقتاً پاسخ نمی‌دهد (۵۰۲/۵۰۳). چند لحظه دیگر دوباره بزنید.', 'hami-ai-assistant' );
                                break;
                        default:
                                if ( $code >= 500 ) {
                                        $msg = __( 'سرویس AI موقتاً از دسترس خارج است. بعداً دوباره تلاش کنید.', 'hami-ai-assistant' );
                                } else {
                                        $msg = sprintf( /* translators: 1: HTTP code 2: detail */ __( 'خطای HTTP %1$d از سرویس AI. %2$s', 'hami-ai-assistant' ), $code, $detail );
                                }
                }

                return new WP_Error( 'haai_http_' . $code, $msg . ( $detail ? ' — ' . $detail : '' ), array( 'status' => $code ) );
        }

        /**
         * A 429 that is really a quota/billing ceiling, not a per-second throttle.
         *
         * Providers word the wall differently: Gemini says «You exceeded your
         * current quota … plan and billing details» with a RESOURCE_EXHAUSTED
         * status, OpenRouter counts «rate limit exceeded … per minute», plain
         * proxies say «quota». All of them need a wait in minutes or an
         * account change — a retry in a second cannot help, so the Persian
         * line must be the quota one, not the transient sentence.
         *
         * @param string $detail Masked provider detail.
         * @return bool
         */
        private static function looks_like_quota( $detail ) {
                $needles = array(
                        'exceeded your current quota', // Gemini free-tier wall.
                        'quota exceeded',
                        'resource_exhausted',          // Gemini status string.
                        'billing details',
                        'rate limit exceeded',
                        'ratelimit',
                        'per minute',                  // RPM/TPM ceilings.
                        'per day',
                );
                foreach ( $needles as $n ) {
                        if ( false !== stripos( (string) $detail, $n ) ) {
                                return true;
                        }
                }
                return false;
        }

        /**
         * Distinguish "the provider refuses this server's IP" (sanctions /
         * geo-block) from an account-level 403.
         *
         * Gemini answers a blocked region with Google's generic HTML wall
         * ("Your client does not have permission to get URL …") or with the
         * JSON "User location is not supported …"; xAI's whole 403 body is a
         * bare "Forbidden". The same keys work from a local dev machine (home
         * traffic usually rides a VPN), so the admin blames the key first —
         * spotting the shape lets the message say what actually helps: proxy
         * or relay. Known-blocked hosts + a one-word detail is the xAI case;
         * a real account error always names the project, key or model.
         *
         * @param string $detail   Masked provider detail (HTML stripped, 200 chars max).
         * @param string $base_url Active source base URL (host is what matters).
         * @return bool
         */
        private static function looks_like_geoblock( $detail, $base_url ) {
                $needles = array(
                        'does not have permission',      // Google HTML block page.
                        'location is not supported',     // Gemini JSON geo error.
                        'not available in your country', // Generic country wall.
                        'country not supported',
                        'unsupported country',
                        'unsupported_country',
                        'unsupported_region',
                );
                foreach ( $needles as $n ) {
                        if ( false !== stripos( $detail, $n ) ) {
                                return true;
                        }
                }

                $host = strtolower( (string) parse_url( (string) $base_url, PHP_URL_HOST ) );
                $geo_hosts = array(
                        'generativelanguage.googleapis.com',
                        'api.x.ai',
                        'api.openai.com',
                        'api.anthropic.com',
                );

                return in_array( $host, $geo_hosts, true ) && 'forbidden' === strtolower( trim( $detail ) );
        }

        /**
         * Mask anything that looks like a credential and cap the length.
         *
         * Deliberately over-broad: a false *** on a provider sentence costs
         * nothing, a leaked key does not.
         *
         * @param string $text Raw provider text.
         * @return string Safe text (max 200 chars).
         */
        public static function mask_secrets( $text ) {
                $text = preg_replace( '/<[^>]*>/', ' ', (string) $text );
                $text = html_entity_decode( $text, ENT_QUOTES, 'UTF-8' );
                $text = preg_replace( '/\s+/u', ' ', trim( $text ) );

                $patterns = array(
                        '/\b(?:sk|pk|st|key|token|secret|api)[-_][A-Za-z0-9_\-]{4,}/i',  // sk-proj-…, key-…
                        '/\bAIza[0-9A-Za-z_\-]{4,}/',                                    // Google API keys
                        '/\BBearer\s+[A-Za-z0-9._\-]{4,}/i',                             // auth headers echoed back
                        '/"[^"\n]{5,}"/',                                                // any quoted value
                        '/\b[a-f0-9]{24,}\b/i',                                          // long hex
                        '/\b[A-Za-z0-9+\/]{24,}={0,2}\b/',                               // long base64-ish run
                );
                $text = preg_replace( $patterns, '***', $text );

                return mb_substr( trim( (string) $text ), 0, 200 );
        }

        /**
         * Rough token estimate (~4 chars per token). Real usage arrives from the
         * provider in `usage` when it sends it; this is the fallback so the
         * dashboard never shows zeroes for providers that omit it.
         *
         * @param array $messages Turns.
         * @return int
         */
        public function estimate_tokens( $messages ) {
                $chars = 0;
                foreach ( (array) $messages as $m ) {
                        $chars += isset( $m['content'] ) ? mb_strlen( (string) $m['content'] ) : 0;
                }
                return (int) max( 1, round( $chars / 4 ) );
        }
}
