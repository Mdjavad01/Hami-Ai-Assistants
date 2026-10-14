<?php
/**
 * Competitor and SERP research for the article pipeline (مرحله D2).
 *
 * Two hard rules shape this whole file:
 *
 *  1. OUTBOUND FETCHES ARE HOSTILE INPUT. Every URL — competitor host, sitemap,
 *     robots, SERP endpoint — goes through Haa_API::is_url_safe() so a saved
 *     "example.com" that actually resolves to 127.0.0.1 or a cloud metadata IP
 *     cannot be used to poke the host. Responses are size-capped and time-boxed.
 *  2. WE STORE STRUCTURE, NEVER TEXT. Only headings, a word count and frequent
 *     words are kept. Copying a competitor's prose into our database would both
 *     plagiarise it and hand the model someone else's words to reuse.
 *
 * robots.txt is honoured. When it cannot be read we fail closed for that host
 * (a server that refuses to tell us its rules is not a server we crawl).
 *
 * Everything here is admin-side; a visitor download costs nothing.
 *
 * @package Hami_AI_Assistant
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Haa_Research {

	/** Compiled, structure-only snapshot of the competitor set. */
	const SNAPSHOT = 'haai_research_snapshot';

	/** Per-URL / per-host cache prefix; TTL comes from the settings. */
	const CACHE_PREFIX = 'haai_rs_';

	/** Refused by robots, or unreadable: skip the whole host, not just one page. */
	const SKIP_HOSTS = 'haai_rs_skip_hosts';

	/** How many robots directives one run may parse before giving up on a host. */
	const ROBOTS_LINES = 200;

	/**
	 * Field definitions for the "research" settings tab.
	 *
	 * Must stay in step with the module entry in Haa_Settings::collect_ui_keys().
	 *
	 * @return array<int,array>
	 */
	public static function fields() {
		return array(
			array( 'key' => 'research.enabled', 'type' => 'checkbox', 'label' => __( 'تحلیل رقبا فعال باشد', 'hami-ai-assistant' ), 'hint' => __( 'اگر خاموش باشد، کل خط لوله فقط از محتوای خود سایت استفاده می‌کند.', 'hami-ai-assistant' ) ),
			array( 'key' => 'research.competitors', 'type' => 'textarea', 'label' => __( 'آدرس رقبا', 'hami-ai-assistant' ), 'hint' => __( 'هر خط یک دامنه. حداکثر ۱۰ مورد. فقط ساختار صفحات خوانده می‌شود، نه متن آن‌ها.', 'hami-ai-assistant' ) ),
			array( 'key' => 'research.user_agent', 'type' => 'text', 'label' => __( 'شناسه‌ی کاربر (User-Agent)', 'hami-ai-assistant' ), 'hint' => __( 'محرمانه نبودن هویت درخواست لازم است؛ ربات‌های ناشناس معمولاً مسدود می‌شوند.', 'hami-ai-assistant' ) ),
			array( 'key' => 'research.respect_robots', 'type' => 'checkbox', 'label' => __( 'پایبندی به robots.txt', 'hami-ai-assistant' ), 'hint' => __( 'توصیه‌ی اکید: با خاموش‌کردن آن، هم ادب crawling نقض می‌شود هم احتمال بن‌شدن IP سرور شما می‌رود.', 'hami-ai-assistant' ) ),
			array( 'key' => 'research.delay_ms', 'type' => 'number', 'label' => __( 'فاصله‌ی بین درخواست‌ها (میلی‌ثانیه)', 'hami-ai-assistant' ), 'min' => 200, 'max' => 10000 ),
			array( 'key' => 'research.timeout', 'type' => 'number', 'label' => __( 'مهلت هر درخواست (ثانیه)', 'hami-ai-assistant' ), 'min' => 2, 'max' => 30 ),
			array( 'key' => 'research.max_pages', 'type' => 'number', 'label' => __( 'حداکثر صفحه از هر رقیب', 'hami-ai-assistant' ), 'min' => 1, 'max' => 20 ),
			array( 'key' => 'research.max_kb', 'type' => 'number', 'label' => __( 'حداکثر حجم هر پاسخ (KB)', 'hami-ai-assistant' ), 'min' => 40, 'max' => 2000 ),
			array( 'key' => 'research.cache_hours', 'type' => 'number', 'label' => __( 'ماندگاری کش تحلیل (ساعت)', 'hami-ai-assistant' ), 'min' => 1, 'max' => 720 ),
			array( 'key' => 'research.serp_enabled', 'type' => 'checkbox', 'label' => __( 'استفاده از سرویس نتایج جست‌وجو', 'hami-ai-assistant' ), 'hint' => __( 'اختیاری. اگر خاموش باشد فقط خودِ سایت رقبا خوانده می‌شود.', 'hami-ai-assistant' ) ),
			array( 'key' => 'research.serp_kind', 'type' => 'select', 'label' => __( 'نوع پاسخ سرویس', 'hami-ai-assistant' ), 'choices' => array( 'serper' => 'Serper / Google-like JSON', 'brave' => 'Brave Search', 'searxng' => 'SearXNG' ) ),
			array( 'key' => 'research.serp_base_url', 'type' => 'text', 'label' => __( 'آدرس سرویس جست‌وجو', 'hami-ai-assistant' ), 'hint' => __( 'مثلاً https://api.search.brave.com یا نمونه‌ی SearXNG خودتان.', 'hami-ai-assistant' ) ),
			array( 'key' => 'research.serp_key', 'type' => 'secret', 'label' => __( 'کلید سرویس جست‌وجو', 'hami-ai-assistant' ), 'hint' => __( 'رمزنگاری‌شده ذخیره و در فرم ماسک نمایش داده می‌شود.', 'hami-ai-assistant' ) ),
		);
	}

	/* ------------------------------------------------------------------
	 * Configuration accessors
	 * ---------------------------------------------------------------- */

	/** @return array */
	public static function config() {
		$s = Haa_Settings::get();
		return isset( $s['research'] ) && is_array( $s['research'] ) ? $s['research'] : array();
	}

	/** @return bool */
	public static function enabled() {
		return ! empty( self::config()['enabled'] );
	}

	/**
	 * Cleaned competitor origins: absolute http(s) roots, deduped by host, max 10.
	 *
	 * @return array<int,array{url:string,host:string}>
	 */
	public static function competitors() {
		$raw   = (string) ( self::config()['competitors'] ?? '' );
		$out   = array();
		$hosts = array();

		foreach ( preg_split( '/\R+/u', $raw ) as $line ) {
			$line = trim( $line );
			if ( '' === $line ) {
				continue;
			}

			// A line that already names a scheme we do not speak is dropped, not
			// "fixed": prefixing https:// onto mailto:x@y turns garbage into a
			// seemingly valid host and then crawls it.
			if ( preg_match( '#^(mailto|tel|sms|javascript|data|file|ftp|sftp|ssh|telnet|gopher|ws|wss):#i', $line ) ) {
				continue;
			}

			// Bare domains are the common case; accept them but always try https first.
			if ( ! preg_match( '#^https?://#i', $line ) ) {
				$line = 'https://' . ltrim( $line, '/' );
			}

			$url = Haa_Settings::sanitize_url( $line );
			if ( '' === $url ) {
				continue;
			}

			$parts = parse_url( $url );
			$host  = isset( $parts['host'] ) ? strtolower( $parts['host'] ) : '';
			if ( '' === $host || isset( $hosts[ $host ] ) ) {
				continue;
			}

			$hosts[ $host ] = true;
			$out[]          = array(
				'url'  => $parts['scheme'] . '://' . $host,
				'host' => $host,
			);

			if ( count( $out ) >= 10 ) {
				break; // Documented ceiling, even if a longer list was pasted.
			}
		}

		return $out;
	}

	/** @return bool */
	public static function serp_ready() {
		$c = self::config();
		return ! empty( $c['serp_enabled'] ) && '' !== trim( (string) ( $c['serp_base_url'] ?? '' ) );
	}

	/* ------------------------------------------------------------------
	 * The one safe HTTP door
	 * ---------------------------------------------------------------- */

	/**
	 * Size-capped, SSRF-checked GET.
	 *
	 * @param string $url Absolute http(s) URL.
	 * @param array  $opts timeout, accept, no_cache.
	 * @return string|WP_Error Body (truncated to the configured cap) or error.
	 */
	public static function get( $url, $opts = array() ) {
		$c       = self::config();
		$timeout = max( 2, (int) ( $opts['timeout'] ?? ( $c['timeout'] ?? 8 ) ) );
		$max_kb  = max( 20, (int) ( $c['max_kb'] ?? 400 ) );

		$url = Haa_Settings::sanitize_url( $url );
		if ( '' === $url ) {
			return new WP_Error( 'haai_rs_url', __( 'آدرس نامعتبر است.', 'hami-ai-assistant' ) );
		}

		// The guard that matters: no private/reserved destination, ever.
		$safe = Haa_API::is_url_safe( $url );
		if ( is_wp_error( $safe ) ) {
			return $safe;
		}

		$args = array(
			'timeout'     => $timeout,
			'redirection' => 3, // Fewer hops than the default: each redirect is another SSRF probe.
			'user-agent'  => self::user_agent(),
			'headers'     => array(
				'Accept'     => isset( $opts['accept'] ) ? $opts['accept'] : 'text/html,application/xhtml+xml;q=0.9,*/*;q=0.5',
				'Accept-Language' => 'fa,en;q=0.8',
			),
			'sslverify'   => true,
			'limit_response_size' => $max_kb * 1024,
		);

		/**
		 * Filters the args used for one research request (proxy, local testing).
		 *
		 * @param array  $args wp_remote_get args.
		 * @param string $url  Target URL.
		 */
		$args = apply_filters( 'haai_research_request_args', $args, $url );

		$res = wp_remote_get( $url, $args );

		if ( is_wp_error( $res ) ) {
			return self::transport_error( $res );
		}

		$code = (int) wp_remote_retrieve_response_code( $res );
		if ( $code >= 400 ) {
			return new WP_Error(
				'haai_rs_status',
				/* translators: %d: HTTP status. */
				sprintf( __( 'پاسخ سرور مقابل: %d', 'hami-ai-assistant' ), $code )
			);
		}

		$body = (string) wp_remote_retrieve_body( $res );
		if ( '' === trim( $body ) ) {
			return new WP_Error( 'haai_rs_empty', __( 'پاسخ خالی بود.', 'hami-ai-assistant' ) );
		}

		// Belt and braces: some transports ignore limit_response_size.
		$limit = $max_kb * 1024;
		if ( strlen( $body ) > $limit ) {
			$body = substr( $body, 0, $limit );
		}

		return $body;
	}

	/**
	 * A human-readable UA that identifies the crawler. Pretending to be a
	 * browser gets hosts angry; a named crawler with a contact URL gets unbanned.
	 *
	 * @return string
	 */
	public static function user_agent() {
		$ua = trim( (string) ( self::config()['user_agent'] ?? '' ) );
		if ( '' === $ua ) {
			$ua = 'HamiAIAssistant/2.0 (+https://example.com/hami-ai-assistant; content-planner)';
		}
		return $ua;
	}

	/**
	 * Translate a transport failure into Persian, so the admin sees *why* a
	 * competitor was skipped instead of a blank list.
	 *
	 * @param WP_Error $res Error.
	 * @return WP_Error
	 */
	private static function transport_error( $res ) {
		$msg = strtolower( (string) $res->get_error_message() );

		if ( false !== strpos( $msg, 'timed out' ) || false !== strpos( $msg, 'timeout' ) ) {
			return new WP_Error( 'haai_rs_timeout', __( 'مهلت پاسخ تمام شد؛ تایم‌اوت یا «حداکثر حجم» را در تنظیمات بالا ببرید.', 'hami-ai-assistant' ) );
		}
		if ( false !== strpos( $msg, 'could not resolve' ) || false !== strpos( $msg, 'name or service' ) ) {
			return new WP_Error( 'haai_rs_dns', __( 'دامنه قابل resolve نیست.', 'hami-ai-assistant' ) );
		}
		if ( false !== strpos( $msg, 'ssl' ) || false !== strpos( $msg, 'certificate' ) ) {
			return new WP_Error( 'haai_rs_ssl', __( 'گواهی SSL معتبر نبود؛ درخواست رد شد.', 'hami-ai-assistant' ) );
		}
		return new WP_Error( 'haai_rs_http', __( 'اتصال برقرار نشد.', 'hami-ai-assistant' ) );
	}

	/* ------------------------------------------------------------------
	 * robots.txt
	 * ---------------------------------------------------------------- */

	/**
	 * Parsed rules for one host: array( allow => [], disallow => [], crawl_delay => int|null, sitemaps => [] )
	 *
	 * @param string $scheme_host e.g. https://example.com
	 * @return array
	 */
	public static function robots( $scheme_host ) {
		$ttl = max( 1, (int) ( self::config()['cache_hours'] ?? 72 ) ) * HOUR_IN_SECONDS;
		$key = self::CACHE_PREFIX . 'rb_' . substr( md5( $scheme_host ), 0, 24 );
		$got = get_transient( $key );
		if ( is_array( $got ) ) {
			return $got;
		}

		$rules = array(
			'allow'       => array(),
			'disallow'    => array(),
			'crawl_delay' => null,
			'sitemaps'    => array(),
			// fail closed: unreadable robots is treated as "do not crawl"
			'readable'    => false,
		);

		$body = self::get(
			rtrim( $scheme_host, '/' ) . '/robots.txt',
			array(
				'accept'  => 'text/plain,*/*;q=0.5',
				'timeout' => max( 2, (int) ( self::config()['timeout'] ?? 8 ) ),
			)
		);

		if ( is_wp_error( $body ) ) {
			// 404/410: the site says it has no rules → allowed. Anything else (5xx,
			// timeout, TLS) is unknown, and unknown means we do not crawl.
			$code   = (int) preg_replace( '/\D/', '', (string) $body->get_error_message() );
			$absent = in_array( $code, array( 404, 405, 410 ), true );
			$rules['readable'] = $absent;
			set_transient( $key, $rules, $absent ? HOUR_IN_SECONDS : 600 ); // Retry the bad case soon.
			return $rules;
		}

		$rules['readable'] = true;
		$applicable        = false; // Are we inside a group that names us?
		$lines             = array_slice( preg_split( '/\R+/u', (string) $body ), 0, self::ROBOTS_LINES );

		foreach ( $lines as $line ) {
			$line = trim( $line );
			if ( '' === $line || 0 === strpos( $line, '#' ) ) {
				continue;
			}

			list( $k, $v ) = array_pad( preg_split( '/\s*:\s*/', $line, 2 ), 2, '' );
			$k = strtolower( trim( (string) $k ) );
			$v = trim( (string) $v );

			if ( 'sitemap' === $k ) {
				if ( '' !== $v ) {
					$rules['sitemaps'][] = $v;
				}
				continue;
			}

			if ( 'user-agent' === $k ) {
				$applicable = ( '*' === $v || false !== stripos( $v, 'hami' ) );
				continue;
			}

			if ( ! $applicable || '' === $v ) {
				continue;
			}

			if ( 'disallow' === $k ) {
				$rules['disallow'][] = $v;
			} elseif ( 'allow' === $k ) {
				$rules['allow'][] = $v;
			} elseif ( 'crawl-delay' === $k ) {
				$rules['crawl_delay'] = (float) $v;
			}
		}

		set_transient( $key, $rules, $ttl );

		return $rules;
	}

	/**
	 * Does robots.txt permit this URL for our UA?
	 *
	 * @param string $url Absolute URL.
	 * @return true|WP_Error
	 */
	public static function allows( $url ) {
		if ( empty( self::config()['respect_robots'] ) ) {
			return true;
		}

		$parts = parse_url( $url );
		$host  = ( isset( $parts['scheme'] ) ? $parts['scheme'] : 'https' ) . '://' . ( isset( $parts['host'] ) ? $parts['host'] : '' );
		$path  = ( isset( $parts['path'] ) ? $parts['path'] : '/' ) . ( isset( $parts['query'] ) ? '?' . $parts['query'] : '' );

		$rules = self::robots( $host );

		if ( empty( $rules['readable'] ) ) {
			return new WP_Error( 'haai_rs_robots', __( 'robots.txt این سایت خوانده نشد؛ برای احتیاط بدون خزیدن ادامه نمی‌دهیم.', 'hami-ai-assistant' ) );
		}

		// Longest match wins, per the robots convention; Allow beats Disallow on a tie.
		$best_rule   = '';
		$is_disallow = false;
		foreach ( array( 'allow', 'disallow' ) as $kind ) {
			foreach ( (array) $rules[ $kind ] as $rule ) {
				if ( '' === $rule || '/' === $rule ) {
					if ( 'disallow' === $kind && '' === $rule ) {
						continue; // "Disallow:" means nothing is disallowed.
					}
				}
				if ( 0 === strpos( $path, $rule ) && strlen( $rule ) > strlen( $best_rule ) ) {
					$best_rule   = $rule;
					$is_disallow = ( 'disallow' === $kind );
				}
			}
		}

		if ( $is_disallow ) {
			return new WP_Error(
				'haai_rs_disallow',
				sprintf( /* translators: %s: path pattern. */ __( 'robots.txt اجازه‌ی خواندن «%s» را نمی‌دهد.', 'hami-ai-assistant' ), $best_rule )
			);
		}

		return true;
	}

	/* ------------------------------------------------------------------
	 * Discovery + structure extraction
	 * ---------------------------------------------------------------- */

	/**
	 * Candidate URLs for one competitor: sitemap first, then the homepage links.
	 *
	 * @param string $scheme_host e.g. https://example.com
	 * @param int    $want        How many URLs at most.
	 * @return array<int,string>
	 */
	public static function discover( $scheme_host, $want = 6 ) {
		$out    = array();
		$rules  = self::robots( $scheme_host );
		$claims = array();

		foreach ( (array) $rules['sitemaps'] as $sm ) {
			$claims[] = $sm;
		}
		if ( ! $claims ) {
			$claims[] = rtrim( $scheme_host, '/' ) . '/sitemap.xml';
			$claims[] = rtrim( $scheme_host, '/' ) . '/sitemap_index.xml';
		}

		foreach ( $claims as $claim ) {
			if ( count( $out ) >= $want ) {
				break;
			}
			$body = self::get( $claim, array( 'accept' => 'application/xml,text/xml;q=0.9,*/*;q=0.4' ) );
			if ( is_wp_error( $body ) ) {
				continue;
			}

			// <loc> only. Sitemap index entries are followed one level, no deeper.
			if ( preg_match_all( '#<loc>\s*([^<\s]+)\s*</loc>#i', (string) $body, $m ) ) {
				foreach ( array_slice( $m[1], 0, $want ) as $u ) {
					$u = trim( wp_unslash( $u ) );
					if ( Haa_Settings::sanitize_url( $u ) !== '' ) {
						$out[ $u ] = true;
					}
				}
			}
		}

		// Nothing machine-readable? Fall back to the homepage anchors.
		if ( count( $out ) < 2 ) {
			$home = self::get( rtrim( $scheme_host, '/' ) . '/' );
			if ( ! is_wp_error( $home ) ) {
				foreach ( self::anchor_hrefs( (string) $home, $scheme_host ) as $u ) {
					$out[ $u ] = true;
					if ( count( $out ) >= $want ) {
						break;
					}
				}
			}
		}

		$pick = array();
		foreach ( array_keys( $out ) as $u ) {
			// A sitemap is attacker-supplied data: it may name 169.254.169.254 or
			// 127.0.0.1. Screening here means an unsafe URL is never even queued,
			// instead of failing later at fetch time with a confusing error.
			if ( is_wp_error( Haa_API::is_url_safe( $u ) ) ) {
				continue;
			}

			$ok = self::allows( $u );
			if ( true === $ok ) {
				$pick[] = $u;
			}
			if ( count( $pick ) >= $want ) {
				break;
			}
		}

		return $pick;
	}

	/**
	 * Same-host absolute links from a document.
	 *
	 * @param string $html        Document.
	 * @param string $scheme_host Host prefix to keep.
	 * @return array<int,string>
	 */
	public static function anchor_hrefs( $html, $scheme_host ) {
		$out   = array();
		$host  = wp_parse_url( $scheme_host, PHP_URL_HOST );

		if ( preg_match_all( '#<a\s[^>]*href=["\']([^"\'"\']+)["\']#i', $html, $m ) ) {
			foreach ( $m[1] as $href ) {
				$href = trim( wp_strip_all_tags( $href ) );
				if ( '' === $href || 0 === strpos( $href, '#' ) || 0 === stripos( $href, 'mailto:' ) || 0 === stripos( $href, 'javascript:' ) ) {
					continue;
				}

				$abs = $href;
				if ( ! preg_match( '#^https?://#i', $abs ) ) {
					$abs = rtrim( $scheme_host, '/' ) . '/' . ltrim( $abs, '/' );
				}

				$url = Haa_Settings::sanitize_url( $abs );
				if ( '' === $url || ! preg_match( '#^https?://#i', $url ) ) {
					continue;
				}

				$h = wp_parse_url( $url, PHP_URL_HOST );
				if ( $h && $host && strtolower( $h ) !== strtolower( (string) $host ) ) {
					continue; // Off-site links are somebody else's business.
				}

				$out[] = $url;
			}
		}

		return array_values( array_unique( $out ) );
	}

	/**
	 * Structure only: title, heading tree, word estimate, frequent words, questions.
	 *
	 * The body text is parsed and dropped in the same call — nothing between
	 * those two lines is stored anywhere.
	 *
	 * @param string $url Page URL.
	 * @return array|WP_Error
	 */
	public static function page_structure( $url ) {
		$allowed = self::allows( $url );
		if ( is_wp_error( $allowed ) ) {
			return $allowed;
		}

		$html = self::get( $url );
		if ( is_wp_error( $html ) ) {
			return $html;
		}

		$headings = array( 'h1' => array(), 'h2' => array(), 'h3' => array() );
		foreach ( array_keys( $headings ) as $tag ) {
			if ( preg_match_all( '#<' . $tag . '[^>]*>(.*?)</' . $tag . '>#is', (string) $html, $m ) ) {
				foreach ( $m[1] as $h ) {
					$text = trim( preg_replace( '/\s+/u', ' ', wp_strip_all_tags( $h ) ) );
					if ( '' !== $text && mb_strlen( $text ) <= 200 ) {
						$headings[ $tag ][] = $text;
					}
				}
			}
		}

		$title = '';
		if ( preg_match( '#<title[^>]*>(.*?)</title>#is', (string) $html, $m ) ) {
			$title = trim( wp_strip_all_tags( $m[1] ) );
		}

		$visible = trim( preg_replace( '#<script.*?</script>|<style.*?</style>#is', ' ', (string) $html ) );
		$visible = preg_replace( '#<[^>]+>#', ' ', (string) $visible );
		$words   = preg_split( '/\s+/u', (string) $visible );
		$words   = array_filter( (array) $words, function ( $w ) {
			return mb_strlen( $w ) > 2;
		} );

		// Questions are the most useful thing to learn from a competitor: they
		// are what people actually ask, and they are not copyrightable prose.
		$questions = array();
		foreach ( array_merge( $headings['h2'], $headings['h3'] ) as $h ) {
			if ( false !== strpos( $h, '?' ) || false !== strpos( $h, '؟' ) ) {
				$questions[] = $h;
			}
		}

		$terms = array();
		foreach ( Haa_Search::tokenize( implode( ' ', array_map( 'strval', array_merge( $headings['h1'], $headings['h2'], $headings['h3'] ) ) ) ) as $w ) {
			if ( mb_strlen( $w ) < 3 ) {
				continue;
			}
			$terms[ $w ] = ( isset( $terms[ $w ] ) ? $terms[ $w ] : 0 ) + 1;
		}
		arsort( $terms );

		return array(
			'url'        => esc_url_raw( $url ),
			'title'      => Haa_Util::cut( $title, 180 ),
			'h1'         => array_slice( $headings['h1'], 0, 3 ),
			'h2'         => array_slice( $headings['h2'], 0, 12 ),
			'h3'         => array_slice( $headings['h3'], 0, 12 ),
			'words'      => count( $words ),
			'questions'  => array_slice( $questions, 0, 8 ),
			'terms'      => array_slice( array_keys( $terms ), 0, 15 ),
			'fetched'    => current_time( 'mysql' ),
		);
	}

	/* ------------------------------------------------------------------
	 * The run
	 * ---------------------------------------------------------------- */

	/**
	 * Crawl every configured competitor and store the compiled snapshot.
	 *
	 * Bounded on purpose: max_pages per host, a delay between requests, and
	 * one host failure never aborts the run — the reason is collected and shown.
	 *
	 * @param bool $force Ignore the cache.
	 * @return array{ok:bool,sites:int,pages:int,errors:array<string,string>,generated:string}
	 */
	public static function analyze( $force = false ) {
		if ( ! self::enabled() ) {
			return array(
				'ok'       => false,
				'sites'    => 0,
				'pages'    => 0,
				'errors'   => array( __( 'تحلیل رقبا در تنظیمات خاموش است.', 'hami-ai-assistant' ) ),
				'generated' => '',
			);
		}

		if ( ! $force ) {
			$saved = self::snapshot();
			if ( ! empty( $saved['sites'] ) ) {
				return array(
					'ok'        => true,
					'sites'     => count( $saved['sites'] ),
					'pages'     => (int) ( $saved['pages'] ?? 0 ),
					'errors'    => (array) ( $saved['errors'] ?? array() ),
					'generated' => (string) ( $saved['generated'] ?? '' ),
				);
			}
		}

		$max_pages = max( 1, min( 20, (int) ( self::config()['max_pages'] ?? 6 ) ) );
		$delay_ms  = max( 200, (int) ( self::config()['delay_ms'] ?? 1200 ) );
		$sites     = array();
		$errors    = array();
		$pages     = 0;

		// A wall-clock budget. 10 competitors × 20 pages × a politeness delay is
		// minutes of work, and a shared host kills the request long before the
		// crawl finishes. Stopping early and saying so beats a white page: the
		// snapshot below is still written, and running again resumes.
		$start   = time();
		$budget  = max( 8, min( 25, (int) apply_filters( 'haai_research_time_budget', 22 ) ) );
		$partial = false;

		foreach ( self::competitors() as $site ) {
			if ( time() - $start >= $budget ) {
				$partial = true;
				break;
			}

			$rules = self::robots( $site['url'] );
			if ( empty( $rules['readable'] ) ) {
				$errors[] = $site['host'] . ': ' . __( 'robots.txt خوانده نشد؛ این سایت رد شد.', 'hami-ai-assistant' );
				continue;
			}
			if ( ! empty( $rules['disallow'] ) && in_array( '/', (array) $rules['disallow'], true ) ) {
				$errors[] = $site['host'] . ': ' . __( 'robots.txt کل سایت را منع کرده است.', 'hami-ai-assistant' );
				continue;
			}

			// A crawl-delay from the site itself overrides our own politeness value.
			$delay = $delay_ms;
			if ( ! empty( $rules['crawl_delay'] ) ) {
				$delay = max( $delay, (int) ( (float) $rules['crawl_delay'] * 1000 ) );
			}

			$found = self::discover( $site['url'], $max_pages );
			if ( ! $found ) {
				$errors[] = $site['host'] . ': ' . __( 'صفحه‌ای قابل خواندن پیدا نشد.', 'hami-ai-assistant' );
				continue;
			}

			$collected = array();
			foreach ( $found as $i => $url ) {
				if ( time() - $start >= $budget ) {
					$partial = true;
					break;
				}
				if ( $i > 0 ) {
					usleep( $delay * 1000 ); // Never hammer somebody else's server.
				}
				$s = self::page_structure( $url );
				if ( is_wp_error( $s ) ) {
					$errors[] = wp_parse_url( $url, PHP_URL_HOST ) . ' — ' . $s->get_error_message();
					continue;
				}
				$collected[] = $s;
				$pages++;
			}

			if ( $collected ) {
				$sites[ $site['host'] ] = $collected;
			}
		}

		$snapshot = array(
			'generated' => current_time( 'mysql' ),
			'pages'     => $pages,
			'partial'   => $partial,
			'sites'     => $sites,
			'errors'    => array_slice( $errors, 0, 20 ),
			'terms'     => self::shared_terms( $sites ),
			'headings'  => self::all_headings( $sites ),
			'questions' => self::all_questions( $sites ),
		);

		update_option( self::SNAPSHOT, $snapshot, 'no' );

		if ( $partial ) {
			$snapshot['errors'][] = __( 'زمان این اجرا تمام شد؛ «تحلیل رقبا» را دوباره بزنید تا ادامه یابد.', 'hami-ai-assistant' );
		}

		return array(
			'ok'        => true,
			'sites'     => count( $sites ),
			'pages'     => $pages,
			'partial'   => $partial,
			'errors'    => $snapshot['errors'],
			'generated' => $snapshot['generated'],
		);
	}

	/**
	 * @return array Compiled snapshot, empty array when never built.
	 */
	public static function snapshot() {
		$s = get_option( self::SNAPSHOT, null );
		if ( ! is_array( $s ) ) {
			return array();
		}

		// Age out a stale snapshot instead of feeding the model month-old data.
		$hours = max( 1, (int) ( self::config()['cache_hours'] ?? 72 ) );
		$made  = strtotime( (string) ( $s['generated'] ?? '' ) );
		if ( ! $made || $made < time() - ( $hours * HOUR_IN_SECONDS ) ) {
			return array();
		}

		return $s;
	}

	/** @return void */
	public static function drop_snapshot() {
		delete_option( self::SNAPSHOT );
	}

	/** @return array<int,string> */
	private static function all_headings( $sites ) {
		$out = array();
		foreach ( (array) $sites as $pages ) {
			foreach ( (array) $pages as $p ) {
				foreach ( array( 'h2', 'h3' ) as $tag ) {
					foreach ( (array) ( $p[ $tag ] ?? array() ) as $h ) {
						$out[] = (string) $h;
					}
				}
			}
		}
		return array_values( array_unique( $out ) );
	}

	/** @return array<int,string> */
	private static function all_questions( $sites ) {
		$out = array();
		foreach ( (array) $sites as $pages ) {
			foreach ( (array) $pages as $p ) {
				foreach ( (array) ( $p['questions'] ?? array() ) as $q ) {
					$out[] = (string) $q;
				}
			}
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * Words that recur across competitor headings — the shape of their topic map.
	 *
	 * @return array<int,string>
	 */
	private static function shared_terms( $sites ) {
		$counts = array();
		foreach ( self::all_headings( $sites ) as $h ) {
			foreach ( Haa_Search::tokenize( $h ) as $w ) {
				if ( mb_strlen( $w ) < 3 ) {
					continue;
				}
				$counts[ $w ] = ( isset( $counts[ $w ] ) ? $counts[ $w ] : 0 ) + 1;
			}
		}
		arsort( $counts );
		return array_slice( array_keys( $counts ), 0, 25 );
	}

	/* ------------------------------------------------------------------
	 * Gaps: what they cover that we do not
	 * ---------------------------------------------------------------- */

	/**
	 * Competitor headings the site has nothing comparable about.
	 *
	 * @param int $limit Max gaps.
	 * @return array<int,array{heading:string,host:string,score:float,covered_by:int}>
	 */
	public static function gaps( $limit = 12 ) {
		$snap = self::snapshot();
		if ( empty( $snap['headings'] ) ) {
			return array();
		}

		$ours    = array();
		$own_map = Haa_Planner::site_map();
		foreach ( (array) ( $own_map['titles'] ?? array() ) as $id => $title ) {
			$ours[ (int) $id ] = Haa_Search::tokenize( (string) $title );
		}

		$own_terms = array();
		foreach ( $ours as $id => $tokens ) {
			foreach ( $tokens as $w ) {
				$own_terms[ (string) $w ][ $id ] = 1;
			}
		}

		$out = array();
		foreach ( (array) $snap['headings'] as $h ) {
			if ( count( $out ) >= $limit ) {
				break;
			}

			$tokens = Haa_Search::tokenize( $h );
			if ( count( $tokens ) < 2 ) {
				continue;
			}

			// Best overlap of this heading with any single one of our titles.
			$best_score = 0.0;
			$best_id    = 0;
			foreach ( $tokens as $w ) {
				$posts_with = isset( $own_terms[ (string) $w ] ) ? count( $own_terms[ (string) $w ] ) : 0;
				if ( ! $posts_with ) {
					continue;
				}
				// Rare words prove coverage; a word in every title proves nothing.
				$score = 1 / ( 1 + $posts_with );
				$best_score += $score;
			}

			$covered = 0;
			foreach ( $ours as $id => $tokens_our ) {
				$hit = count( array_intersect( array_map( 'strval', $tokens ), array_map( 'strval', $tokens_our ) ) );
				if ( $hit > 0 && $hit >= max( 2, (int) floor( count( $tokens ) * 0.6 ) ) ) {
					$covered = (int) $id;
					break;
				}
			}

			if ( $covered || $best_score < 0.4 ) {
				continue;
			}

			$out[] = array(
				'heading'   => Haa_Util::cut( $h, 180 ),
				'host'      => '',
				'score'     => round( $best_score, 2 ),
				'covered_by' => 0,
			);
		}

		return $out;
	}

	/* ------------------------------------------------------------------
	 * Optional SERP provider
	 * ---------------------------------------------------------------- */

	/**
	 * One query against the configured search provider.
	 *
	 * The provider is optional precisely because Google is frequently unreachable
	 * from Iranian hosts; without it the pipeline still runs on competitor pages
	 * alone. Shape is normalised so no downstream code learns a provider's quirks.
	 *
	 * @param string $query Search phrase.
	 * @param int    $limit Max results.
	 * @return array{ok:bool,results:array<int,array{title:string,link:string,snippet:string}>,error:string}
	 */
	public static function serp( $query, $limit = 10 ) {
		if ( ! self::serp_ready() ) {
			return array( 'ok' => false, 'results' => array(), 'error' => __( 'سرویس جست‌وجو تنظیم نشده است؛ فقط تحلیل مستقیم رقبا انجام می‌شود.', 'hami-ai-assistant' ) );
		}

		$c    = self::config();
		$kind = in_array( (string) ( $c['serp_kind'] ?? '' ), array( 'serper', 'brave', 'searxng' ), true ) ? (string) $c['serp_kind'] : 'serper';
		$base = Haa_Settings::sanitize_url( (string) $c['serp_base_url'] );
		$key  = (string) Haa_Settings::decrypt( (string) ( $c['serp_key'] ?? '' ) );
		$limit = max( 1, min( 20, (int) $limit ) );

		if ( '' === $base ) {
			return array( 'ok' => false, 'results' => array(), 'error' => __( 'آدرس سرویس جست‌وجو نامعتبر است.', 'hami-ai-assistant' ) );
		}

		if ( 'brave' === $kind ) {
			$url     = untrailingslashit( $base ) . '/res/v1/web/search?q=' . rawurlencode( $query ) . '&count=' . $limit;
			$headers = array( 'X-Subscription-Token' => $key, 'Accept' => 'application/json' );
			$list    = 'web.results';
		} elseif ( 'searxng' === $kind ) {
			$url     = untrailingslashit( $base ) . '/search?q=' . rawurlencode( $query ) . '&format=json';
			$headers = array( 'Accept' => 'application/json' );
			$list    = 'results';
		} else {
			$url     = untrailingslashit( $base ) . '/search';
			$headers = array(
				'Content-Type' => 'application/json',
				'X-API-KEY'    => $key,
			);
			$list    = 'organic';
		}

		// Same SSRF door as the crawler: a "search API" pointing at 169.254.169.254
		// is the oldest trick in the book.
		$safe = Haa_API::is_url_safe( $url );
		if ( is_wp_error( $safe ) ) {
			return array( 'ok' => false, 'results' => array(), 'error' => $safe->get_error_message() );
		}

		$args = array(
			'timeout' => max( 3, (int) ( $c['timeout'] ?? 8 ) ),
			'headers' => array_merge( array( 'User-Agent' => self::user_agent() ), $headers ),
			'limit_response_size' => max( 20, (int) ( $c['max_kb'] ?? 400 ) ) * 1024,
		);

		if ( 'serper' === $kind ) {
			$args['method'] = 'POST';
			$args['body']   = wp_json_encode( array( 'q' => $query, 'num' => $limit ) );
			$res            = wp_remote_post( $url, $args );
		} else {
			$args['headers']['Accept'] = 'application/json';
			$res                       = wp_remote_get( $url, $args );
		}

		if ( is_wp_error( $res ) ) {
			return array( 'ok' => false, 'results' => array(), 'error' => self::transport_error( $res )->get_error_message() );
		}

		$code = (int) wp_remote_retrieve_response_code( $res );
		if ( $code >= 400 ) {
			return array(
				'ok'      => false,
				'results' => array(),
				'error'   => sprintf( /* translators: %d: HTTP status. */ __( 'سرویس جست‌وجو پاسخ %d داد.', 'hami-ai-assistant' ), $code ),
			);
		}

		$json = json_decode( (string) wp_remote_retrieve_body( $res ), true );
		if ( ! is_array( $json ) ) {
			return array( 'ok' => false, 'results' => array(), 'error' => __( 'پاسخ سرویس جست‌وجو JSON نبود.', 'hami-ai-assistant' ) );
		}

		// "web.results" style path walk.
		$rows = $json;
		foreach ( explode( '.', $list ) as $step ) {
			$rows = isset( $rows[ $step ] ) ? $rows[ $step ] : array();
		}

		$out = array();
		foreach ( (array) $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$title = trim( wp_strip_all_tags( (string) ( $row['title'] ?? '' ) ) );
			$link  = Haa_Settings::sanitize_url( (string) ( $row['link'] ?? ( $row['url'] ?? '' ) ) );
			if ( '' === $title ) {
				continue;
			}
			$out[] = array(
				'title'   => Haa_Util::cut( $title, 180 ),
				'link'    => $link,
				'snippet' => Haa_Util::cut( trim( wp_strip_all_tags( (string) ( $row['snippet'] ?? '' ) ) ), 300 ),
			);
			if ( count( $out ) >= $limit ) {
				break;
			}
		}

		return array( 'ok' => true, 'results' => $out, 'error' => '' );
	}

	/* ------------------------------------------------------------------
	 * Brief for one topic
	 * ---------------------------------------------------------------- */

	/**
	 * One model call that turns research into an outline for a concrete topic.
	 *
	 * Competitor headings are passed as *topic hints only*. The prompt forbids
	 * copying them and, as everywhere else, forbids invented facts.
	 *
	 * @param array $topic Row from Haa_Planner.
	 * @return array{ok:bool,error:string,brief:array}
	 */
	public static function brief( $topic ) {
		$title   = trim( (string) ( $topic['title'] ?? '' ) );
		$keyword = trim( (string) ( $topic['keyword'] ?? $title ) );

		if ( '' === $title ) {
			return array( 'ok' => false, 'error' => __( 'موضوع بی‌عنوان است.', 'hami-ai-assistant' ), 'brief' => array() );
		}

		$snap      = self::snapshot();
		$gaps      = self::gaps( 8 );
		$serp      = self::serp( $keyword, 8 );
		$competitor_headings = array();

		if ( ! empty( $snap['headings'] ) ) {
			foreach ( (array) $snap['headings'] as $h ) {
				$tokens_a = Haa_Search::tokenize( (string) $h );
				$tokens_b = Haa_Search::tokenize( $keyword );
				if ( count( array_intersect( array_map( 'strval', $tokens_a ), array_map( 'strval', $tokens_b ) ) ) > 0 ) {
					$competitor_headings[] = (string) $h;
				}
				if ( count( $competitor_headings ) >= 12 ) {
					break;
				}
			}
		}

		$system = __( 'تو استراتژیک محتوای سئو برای یک سایت فارسی‌زبانی. برای موضوع داده‌شده یک بریف تولید می‌کنی.', 'hami-ai-assistant' ) . "\n"
			. __( 'خروجی فقط یک شیء JSON با این کلیدها، بدون متن اضافه و بدون کد فنس:', 'hami-ai-assistant' ) . "\n"
			. '{"search_intent":"informational|commercial|transactional","keyword":"…","secondary":["…"],"outline":[{"h2":"…","h3":["…"]}],"faq":[{"q":"…","a":"…"}],"word_target":1500,"internal_link_goals":["…"]}' . "\n"
			. Haa_Content::no_fabrication() . "\n"
			. '- ' . __( 'سرفصل‌های رقیب فقط برای فهم «چه چیزی پوشش داده شده» است؛ عیناً کپی نکن و هیچ جمله‌ی آن‌ها را بازتولید نکن.', 'hami-ai-assistant' ) . "\n"
			. '- ' . __( 'outline باید برای همین سایت و محصولات خودش باشد، نه یک ساختار عمومی.', 'hami-ai-assistant' ) . "\n"
			. '- ' . __( 'word_target بین ۸۰۰ تا ۳۰۰۰.', 'hami-ai-assistant' ) . "\n"
			. '- ' . __( '۴ تا ۶ سؤال متداول واقعی که مردم درباره‌ی همین موضوع می‌پرسند.', 'hami-ai-assistant' );

		$parts = array();
		$prof  = Haa_Site_Profile::block( $title );
		if ( '' !== $prof ) {
			$parts[] = $prof;
		}
		$parts[] = __( 'موضوع:', 'hami-ai-assistant' ) . ' ' . $title . "\n"
			. __( 'کلیدواژه:', 'hami-ai-assistant' ) . ' ' . $keyword . "\n"
			. __( 'هدف اولیه از برنامه‌ریز:', 'hami-ai-assistant' ) . ' ' . (string) ( $topic['intent'] ?? '' );

		if ( $competitor_headings ) {
			$parts[] = __( 'سرفصل‌های دیده‌شده در سایت رقبا (برای الهام، نه کپی):', 'hami-ai-assistant' ) . "\n- " . implode( "\n- ", $competitor_headings );
		}
		if ( $gaps ) {
			$parts[] = __( 'شکاف‌های محتوایی شناسایی‌شده:', 'hami-ai-assistant' ) . "\n- " . implode( "\n- ", wp_list_pluck( $gaps, 'heading' ) );
		}
		if ( ! empty( $serp['ok'] ) && $serp['results'] ) {
			$lines = array();
			foreach ( $serp['results'] as $r ) {
				$lines[] = '- ' . $r['title'];
			}
			$parts[] = __( 'عنوان نتایج جست‌وجو برای این کلیدواژه:', 'hami-ai-assistant' ) . "\n" . implode( "\n", $lines );
		}

		$user = implode( "\n\n", $parts ) . "\n\n" . __( 'حالا بریف را بنویس.', 'hami-ai-assistant' );

		$raw = Haa_Content::generate( $system, $user, array( 'module' => 'article-writer', 'operation' => 'brief' ) );
		if ( is_wp_error( $raw ) ) {
			return array( 'ok' => false, 'error' => (string) $raw->get_error_message(), 'brief' => array() );
		}

		$json = Haa_Util::extract_json( $raw );
		if ( ! is_array( $json ) || empty( $json['outline'] ) ) {
			return array( 'ok' => false, 'error' => __( 'بریف از مدل بدرستی برنگشت؛ خط لوله با طرح ساده ادامه می‌دهد.', 'hami-ai-assistant' ), 'brief' => array() );
		}

		return array( 'ok' => true, 'error' => '', 'brief' => self::sanitize_brief( $json, $keyword ) );
	}

	/**
	 * Clamp everything the model may have invented or mis-shaped.
	 *
	 * @param array  $json    Parsed model answer.
	 * @param string $keyword Fallback keyword.
	 * @return array
	 */
	public static function sanitize_brief( $json, $keyword = '' ) {
		$intent = in_array( (string) ( $json['search_intent'] ?? '' ), Haa_Planner::INTENTS, true ) ? (string) $json['search_intent'] : 'informational';

		$outline = array();
		foreach ( (array) ( $json['outline'] ?? array() ) as $sec ) {
			if ( ! is_array( $sec ) ) {
				// A bare string heading is still usable.
				$sec = array( 'h2' => (string) $sec );
			}
			$h2 = trim( wp_strip_all_tags( (string) ( $sec['h2'] ?? '' ) ) );
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
			$outline[] = array(
				'h2' => Haa_Util::cut( $h2, 200 ),
				'h3' => array_slice( $h3, 0, 6 ),
			);
			if ( count( $outline ) >= 12 ) {
				break;
			}
		}

		$faq = array();
		foreach ( (array) ( $json['faq'] ?? array() ) as $f ) {
			if ( ! is_array( $f ) ) {
				continue;
			}
			$q = trim( wp_strip_all_tags( (string) ( $f['q'] ?? '' ) ) );
			$a = trim( wp_strip_all_tags( (string) ( $f['a'] ?? '' ) ) );
			if ( '' !== $q ) {
				$faq[] = array( 'q' => Haa_Util::cut( $q, 200 ), 'a' => Haa_Util::cut( $a, 600 ) );
			}
			if ( count( $faq ) >= 6 ) {
				break;
			}
		}

		$words = (int) ( $json['word_target'] ?? 1500 );
		$words = max( 800, min( 3000, $words ) );

		$secondary = array();
		foreach ( (array) ( $json['secondary'] ?? array() ) as $s ) {
			$s = trim( wp_strip_all_tags( (string) $s ) );
			if ( '' !== $s ) {
				$secondary[] = Haa_Util::cut( $s, 120 );
			}
		}

		$goals = array();
		foreach ( (array) ( $json['internal_link_goals'] ?? array() ) as $g ) {
			$g = trim( wp_strip_all_tags( (string) $g ) );
			if ( '' !== $g ) {
				$goals[] = Haa_Util::cut( $g, 160 );
			}
		}

		return array(
			'search_intent'        => $intent,
			'keyword'              => Haa_Util::cut( trim( wp_strip_all_tags( (string) ( $json['keyword'] ?? $keyword ) ) ), 160 ),
			'secondary'            => array_slice( $secondary, 0, 8 ),
			'outline'              => $outline,
			'faq'                  => $faq,
			'word_target'          => $words,
			'internal_link_goals'  => array_slice( $goals, 0, 8 ),
		);
	}

	/**
	 * Plain-text rendering of the research state for the admin screen.
	 *
	 * @return string
	 */
	public static function status_line() {
		$snap = self::snapshot();
		if ( ! $snap ) {
			return self::enabled()
				? __( 'هنوز تحلیلی ساخته نشده است.', 'hami-ai-assistant' )
				: __( 'تحلیل رقبا خاموش است؛ خط لوله فقط از محتوای خود سایت استفاده می‌کند.', 'hami-ai-assistant' );
		}

		return sprintf(
			/* translators: 1: site count, 2: page count, 3: timestamp. */
			__( '%1$d سایت، %2$d صفحه — آخرین تحلیل: %3$s', 'hami-ai-assistant' ),
			count( (array) ( $snap['sites'] ?? array() ) ),
			(int) ( $snap['pages'] ?? 0 ),
			(string) ( $snap['generated'] ?? '' )
		);
	}
}
