<?php
/**
 * Public REST surface. Designed for page-cached sites: no nonce lives in the
 * HTML, a short-lived HMAC token is issued by /token and pinned to an HttpOnly
 * cookie, and /chat verifies it alongside a honeypot and a rate limit.
 *
 * @package Hami_AI_Assistant
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Haa_REST {

	const COOKIE      = 'haai_sid';
	const TOKEN_TTL   = 1800; // Fallback only; advanced.token_ttl_minutes wins.
	const COOKIE_TTL  = 172800;
	const MAX_MESSAGE = 4000; // Fallback only; advanced.max_message_chars wins.
	const DAILY_STATE = 'haai_daily_cap';
	const DAILY_USER_STATE = 'haai_daily_user_cap';

	/** @return void */
	public static function hooks() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	/** @return void */
	public static function register_routes() {
		register_rest_route(
			HAAI_REST_NS,
			'/token',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'get_token' ),
				'permission_callback' => array( __CLASS__, 'is_chat_allowed' ),
			)
		);

		register_rest_route(
			HAAI_REST_NS,
			'/widget',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'get_widget' ),
				'permission_callback' => array( __CLASS__, 'is_chat_allowed' ),
			)
		);

		register_rest_route(
			HAAI_REST_NS,
			'/chat',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'post_chat' ),
				'permission_callback' => array( __CLASS__, 'is_chat_allowed' ),
			)
		);
	}

	/**
	 * Shared visibility + login gate for every public route.
	 *
	 * @return true|WP_Error
	 */
	public static function is_chat_allowed( $request = null ) {
		$s = Haa_Settings::get();

		// A REST request has no queried object. When the caller says which
		// page it is rendering on, the singular show/exclude rules apply to
		// it too — otherwise an excluded page could still fetch the widget.
		$url = $request instanceof WP_REST_Request ? (string) $request->get_param( 'url' ) : '';

		if ( ! Haa_Plugin::should_display( $s, $url ) ) {
			return new WP_Error( 'haai_hidden', __( 'دستیار در این صفحه غیرفعال است.', 'hami-ai-assistant' ), array( 'status' => 403 ) );
		}

		$mode = (int) $s['advanced']['guest_access'];
		if ( 1 === $mode && is_user_logged_in() ) {
			return new WP_Error( 'haai_guests_only', __( 'این چت فقط برای مهمان‌هاست.', 'hami-ai-assistant' ), array( 'status' => 403 ) );
		}
		if ( 2 === $mode && ! is_user_logged_in() ) {
			return new WP_Error(
				'haai_login_required',
				(string) $s['advanced']['login_required_message'],
				array( 'status' => 401 )
			);
		}
		return true;
	}

	/**
	 * Mint (or re-issue) the caller's chat token.
	 *
	 * The token is pinned to a hash of the visitor's User-Agent + network
	 * prefix (IPv4 /24, IPv6 /48) so it cannot be replayed from another
	 * browser or another network, while staying valid across the mobile-IP
	 * rotations an Iranian visitor goes through in one session, and it
	 * carries a short TTL (advanced.token_ttl_minutes, 30–60 min by
	 * default). Within one validity window the token may be renewed exactly
	 * ONCE; after that the same token is returned until the window expires.
	 *
	 * @return WP_REST_Response
	 */
	public static function get_token() {
		$sid   = self::ensure_session();
		$now   = time();
		$ttl   = self::token_ttl();
		$next  = self::next_token_state( $now, $ttl );

		return rest_ensure_response(
			array(
				'token'   => self::sign( $sid, $next['exp'], $next['renewed'] ),
				'expires' => max( 0, $next['exp'] - $now ),
			)
		);
	}

	/**
	 * The renewal state machine. One renewal per validity window:
	 *   - no/expired state  -> fresh window (renewed = 0)
	 *   - window, renewed=0  -> the single renewal (renewed = 1, new exp)
	 *   - window, renewed=1  -> same token, no extension
	 *
	 * @param int $now Current unix time.
	 * @param int $ttl Window length in seconds.
	 * @return array{exp:int,renewed:int}
	 */
	private static function next_token_state( $now, $ttl ) {
		$state = get_transient( self::token_state_key() );

		if ( ! is_array( $state ) || (int) ( $state['exp'] ?? 0 ) < $now ) {
			$exp     = $now + $ttl;
			$renewed = 0;
			set_transient( self::token_state_key(), array( 'exp' => $exp, 'renewed' => 0 ), $ttl );
			return array( 'exp' => $exp, 'renewed' => 0 );
		}

		if ( 0 === (int) ( $state['renewed'] ?? 0 ) ) {
			$exp     = $now + $ttl;
			$renewed = 1;
			set_transient( self::token_state_key(), array( 'exp' => $exp, 'renewed' => 1 ), $ttl );
			return array( 'exp' => $exp, 'renewed' => 1 );
		}

		return array( 'exp' => (int) $state['exp'], 'renewed' => 1 );
	}

	/** @return int Token lifetime in seconds. */
	private static function token_ttl() {
		$minutes = (int) Haa_Settings::get( 'advanced.token_ttl_minutes', 30 );
		return max( 300, min( 86400, $minutes * 60 ) );
	}

	/** @return int Max message length in characters. */
	private static function max_message() {
		$chars = (int) Haa_Settings::get( 'advanced.max_message_chars', self::MAX_MESSAGE );
		return max( 200, min( 8000, $chars ) );
	}

	/**
	 * Transient key for the renewal state. Keyed by the UA + network-prefix
	 * binding, not by the cookie, so rotating the cookie cannot reset the
	 * renewal budget.
	 *
	 * @return string
	 */
	private static function token_state_key() {
		return 'haai_tok_' . self::binding_hash();
	}

	/**
	 * Stable binding of a token to the issuing browser: User-Agent + the
	 * visitor's network prefix (IPv4 /24, IPv6 /48) instead of the full IP.
	 *
	 * The full IP is deliberately NOT used: Iranian visitors on mobile
	 * rotate through many addresses inside one session, and a dynamic
	 * broadband IP may be shared by a whole CGNAT pool. Pinning to the
	 * network prefix keeps the token valid across those rotations while
	 * still making it unusable from a different network.
	 *
	 * @return string 16 hex chars.
	 */
	private static function binding_hash() {
		$ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? (string) wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) : '';
		return substr( sha1( self::network_prefix() . '|' . $ua ), 0, 16 );
	}

	/**
	 * Network prefix of the real client IP: IPv4 /24, IPv6 /48.
	 *
	 * Returns '0' when the IP cannot be parsed, so the binding still
	 * resolves to something stable instead of fataling.
	 *
	 * @return string
	 */
	private static function network_prefix() {
		$ip = self::client_ip();
		$binary = @inet_pton( $ip );
		if ( false === $binary || '' === $binary ) {
			return '0';
		}

		// IPv6 (16 bytes) — keep the first 48 bits = 3 hextets.
		if ( 16 === strlen( $binary ) ) {
			return '48:' . bin2hex( substr( $binary, 0, 6 ) );
		}

		// IPv4 (4 bytes) — keep the first 24 bits = 3 octets.
		if ( 4 === strlen( $binary ) ) {
			$parts = explode( '.', $ip );
			if ( 4 !== count( $parts ) ) {
				return '0';
			}
			return '24:' . $parts[0] . '.' . $parts[1] . '.' . $parts[2];
		}

		return '0';
	}

	/** @return WP_REST_Response */
	public static function get_widget() {
		ob_start();
		haa_render_widget(
			array(
				'preview' => false,
				'inline'  => false,
			)
		);
		$html = ob_get_clean();

		return rest_ensure_response(
			array(
				'ok'   => true,
				'html' => $html,
				'css'  => haa_inline_css( Haa_Settings::get() ),
			)
		);
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function post_chat( WP_REST_Request $request ) {
		// Honeypot: a field a human never sees and a form-filling bot always does.
		$hp = (string) $request->get_param( 'hp' );
		if ( '' !== trim( $hp ) ) {
			return new WP_Error( 'haai_spam', __( 'درخواست نامعتبر بود.', 'hami-ai-assistant' ), array( 'status' => 400 ) );
		}

		$token = (string) $request->get_param( 'token' );
		if ( '' === $token ) {
			$token = (string) $request->get_header( 'x_hami_token' );
		}
		if ( ! self::verify_token( $token ) ) {
			return new WP_Error( 'haai_bad_token', __( 'توکن چت منقضی شده است. صفحه را تازه‌سازی کنید.', 'hami-ai-assistant' ), array( 'status' => 403 ) );
		}

		$identifier = self::identifier();

		// Whole-site daily budget: protects the owner's API bill no matter
		// how many visitors (or how many rate-limit identities) exist.
		$daily = self::daily_cap_status();
		if ( is_wp_error( $daily ) ) {
			return $daily;
		}

		// Per-identity daily budget: one IP or one account cannot spend the
		// whole site's day on its own.
		$daily_user = self::daily_user_cap_status( $identifier );
		if ( is_wp_error( $daily_user ) ) {
			return $daily_user;
		}

		$limited    = Haa_Chat::rate_limit_status( $identifier );
		if ( is_wp_error( $limited ) ) {
			$limited->add_data( array( 'status' => 429 ) );
			return $limited;
		}

		$message = trim( (string) $request->get_param( 'message' ) );
		if ( '' === $message ) {
			return new WP_Error( 'haai_empty_message', __( 'پیام خالی است.', 'hami-ai-assistant' ), array( 'status' => 400 ) );
		}
		$max_message = self::max_message();
		if ( mb_strlen( $message ) > $max_message ) {
			$message = Haa_Util::cut( $message, $max_message );
		}

		$history = self::sanitize_history( (array) $request->get_param( 'history' ) );
		$extra   = self::sanitize_lead_fields( $request );

		$result = Haa_Chat::ask( $message, $history, $identifier, $extra );

		if ( empty( $result['ok'] ) ) {
			return new WP_Error(
				'haai_chat_failed',
				$result['error'] ? $result['error'] : (string) Haa_Settings::get( 'chat.error_message' ),
				array( 'status' => 502 )
			);
		}

		if ( ! empty( Haa_Settings::get( 'chat.save_history' ) ) && Haa_Settings::is_module_enabled( 'chatbot' ) ) {
			self::persist_session( $identifier, $history, $message, (string) $result['reply'] );
		}

		return rest_ensure_response(
			array(
				'ok'        => true,
				'reply'     => sanitize_textarea_field( (string) $result['reply'] ),
				'cached'    => (bool) $result['cached'],
				'offline'   => (bool) $result['offline'],
				'suggested' => array_values( array_slice( array_map( 'sanitize_text_field', (array) ( isset( $result['suggested'] ) ? $result['suggested'] : array() ) ), 0, 8 ) ),
			)
		);
	}

	/* ------------------------------------------------------------------
	 * Daily budgets: whole site + per visitor identity
	 * ---------------------------------------------------------------- */

	/**
	 * The day boundary for BOTH daily budgets: midnight in the site
	 * timezone (Haa_Usage::site_day_start_utc), never raw UTC.
	 *
	 * @return string Y-m-d in the site timezone.
	 */
	private static function daily_window() {
		return Haa_Usage::site_day_key();
	}

	/**
	 * Count one chat request against the whole-site daily budget and report
	 * whether the budget is still available.
	 *
	 * The counter lives in a transient whose key carries the site-local day
	 * and whose TTL is the time left until site-local midnight, so the
	 * window always self-expires and needs no cron cleanup.
	 *
	 * @return true|WP_Error
	 */
	private static function daily_cap_status() {
		$cap = (int) Haa_Settings::get( 'advanced.daily_request_cap', 500 );
		if ( $cap <= 0 ) {
			return true; // Unlimited.
		}

		$key  = self::DAILY_STATE . '_' . self::daily_window();
		$hits = (int) get_transient( $key );
		if ( $hits >= $cap ) {
			return new WP_Error(
				'haai_daily_cap',
				(string) Haa_Settings::get( 'advanced.daily_cap_message', __( 'به سقف روزانه‌ی چت این سایت رسیده‌اید. لطفاً فردا دوباره تلاش کنید.', 'hami-ai-assistant' ) ),
				array( 'status' => 429 )
			);
		}

		set_transient( $key, $hits + 1, Haa_Usage::seconds_until_site_day_end() );

		return true;
	}

	/**
	 * Per-identity daily budget: one IP (guest) or one logged-in account
	 * cannot consume the whole site's budget on its own. Independent from
	 * the short-window rate limit — this one spans the whole site day.
	 *
	 * 0 in advanced.daily_user_cap means unlimited.
	 *
	 * @param string $identifier Rate-limit identity.
	 * @return true|WP_Error
	 */
	private static function daily_user_cap_status( $identifier ) {
		$cap = (int) Haa_Settings::get( 'advanced.daily_user_cap', 30 );
		if ( $cap <= 0 ) {
			return true; // Unlimited.
		}

		$key  = self::DAILY_USER_STATE . '_' . self::daily_window() . '_' . md5( (string) $identifier );
		$hits = (int) get_transient( $key );
		if ( $hits >= $cap ) {
			return new WP_Error(
				'haai_daily_user_cap',
				(string) Haa_Settings::get( 'advanced.daily_user_cap_message', __( 'به سقف روزانه‌ی گفتگوی شما رسیده‌اید. لطفاً فردا دوباره تلاش کنید.', 'hami-ai-assistant' ) ),
				array( 'status' => 429 )
			);
		}

		set_transient( $key, $hits + 1, Haa_Usage::seconds_until_site_day_end() );

		return true;
	}

	/* ------------------------------------------------------------------
	 * Lead capture inside the chat
	 * ---------------------------------------------------------------- */

	/**
	 * Name + Iranian mobile, only when the chatbot module asks for them and the
	 * visitor explicitly typed them. Nothing is stored without consent text.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array{lead_name:string,lead_phone:string,consent:string}
	 */
	private static function sanitize_lead_fields( WP_REST_Request $request ) {
		$out = array(
			'lead_name'  => '',
			'lead_phone' => '',
			'consent'    => '',
		);

		if ( ! Haa_Settings::is_module_enabled( 'leads' ) ) {
			return $out;
		}
		if ( 'off' === (string) Haa_Settings::get( 'chat.lead_requirement', 'optional' ) ) {
			return $out;
		}

		$name  = sanitize_text_field( (string) $request->get_param( 'lead_name' ) );
		$phone = Haa_Util::normalize_ir_mobile( (string) $request->get_param( 'lead_phone' ) );
		$consent = (string) $request->get_param( 'lead_consent' );

		$out['lead_name']  = Haa_Util::cut( $name, 120 );
		$out['lead_phone'] = $phone;
		$out['consent']    = trim( wp_kses_post( $consent ) );

		return $out;
	}

	/**
	 * Append to (or create) the stored conversation for this visitor.
	 *
	 * @param string $identifier Identity.
	 * @param array  $history    Prior turns.
	 * @param string $message    Just-sent message.
	 * @param string $reply      Just-received reply.
	 */
	private static function persist_session( $identifier, $history, $message, $reply ) {
		global $wpdb;
		$table = Haa_DB::table( 'sessions' );
		$sid   = substr( md5( (string) $identifier ), 0, 64 );
		$now   = current_time( 'mysql', true );

		$turns  = array_values( (array) $history );
		$turns[] = array( 'role' => 'user', 'content' => $message );
		$turns[] = array( 'role' => 'assistant', 'content' => $reply );

		// Cap the stored transcript so one row cannot grow without bound.
		$turns = array_slice( $turns, -40 );

		$row = $wpdb->get_row( $wpdb->prepare( "SELECT id, title FROM $table WHERE sid = %s", $sid ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( $row ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->update(
				$table,
				array(
					'messages'     => wp_json_encode( $turns ),
					'message_count' => count( $turns ),
					'updated'      => $now,
				),
				array( 'id' => (int) $row['id'] ),
				array( '%s', '%d', '%s' ),
				array( '%d' )
			);
			return;
		}

		$title = Haa_Util::cut( $message, 160 );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->insert(
			$table,
			array(
				'sid'           => $sid,
				'user_id'       => get_current_user_id(),
				'lead_id'       => 0,
				'title'         => $title,
				'messages'      => wp_json_encode( $turns ),
				'message_count' => count( $turns ),
				'created'       => $now,
				'updated'       => $now,
			),
			array( '%s', '%d', '%d', '%s', '%s', '%d', '%s', '%s' )
		);
	}

	/* ------------------------------------------------------------------
	 * Token / session
	 * ---------------------------------------------------------------- */

	/**
	 * Read or mint the visitor's session cookie.
	 *
	 * @return string 32 hex chars.
	 */
	public static function ensure_session() {
		$sid = isset( $_COOKIE[ self::COOKIE ] ) ? (string) wp_unslash( $_COOKIE[ self::COOKIE ] ) : '';
		if ( preg_match( '/^[a-f0-9]{32}$/', $sid ) ) {
			return $sid;
		}

		// Strictly [a-f0-9]{32}: verify_token()'s regex demands it, and
		// wp_generate_password() may emit uppercase letters — every token it
		// signed would then be rejected as invalid on the very next request.
		$sid = bin2hex( random_bytes( 16 ) );

		if ( ! headers_sent() ) {
			setcookie(
				self::COOKIE,
				$sid,
				time() + self::COOKIE_TTL,
				COOKIEPATH ? COOKIEPATH : '/',
				COOKIE_DOMAIN,
				is_ssl(),
				true
			);
		}
		$_COOKIE[ self::COOKIE ] = $sid;

		return $sid;
	}

	/**
	 * @param string $sid     Session id.
	 * @param int    $exp     Unix expiry.
	 * @param int    $renewed 0 = first issue, 1 = already renewed once.
	 * @return string
	 */
	private static function sign( $sid, $exp, $renewed = 0 ) {
		$uid     = get_current_user_id();
		$binding = self::binding_hash();
		$raw     = $sid . '|' . $uid . '|' . (int) $exp . '|' . $binding . '|' . (int) $renewed;
		return base64_encode( $raw . '|' . self::mac( $raw ) );
	}

	/**
	 * @param string $token Token.
	 * @return bool
	 */
	private static function verify_token( $token ) {
		$raw = base64_decode( (string) $token, true );
		if ( false === $raw ) {
			return false;
		}
		$parts = explode( '|', $raw );
		if ( 6 !== count( $parts ) ) {
			return false;
		}
		list( $sid, $uid, $exp, $binding, $renewed, $sig ) = $parts;

		if ( ! preg_match( '/^[a-f0-9]{32}$/', $sid ) ) {
			return false;
		}
		if ( (int) $exp < time() ) {
			return false;
		}
		if ( ! in_array( (string) $renewed, array( '0', '1' ), true ) ) {
			return false;
		}
		if ( ! hash_equals( self::mac( $sid . '|' . $uid . '|' . $exp . '|' . $binding . '|' . $renewed ), $sig ) ) {
			return false;
		}

		// The token must have been minted for THIS browser (IP + UA).
		if ( ! hash_equals( self::binding_hash(), $binding ) ) {
			return false;
		}

		// The cookie must still match and the user must be the same.
		$cookie = isset( $_COOKIE[ self::COOKIE ] ) ? (string) wp_unslash( $_COOKIE[ self::COOKIE ] ) : '';
		if ( ! hash_equals( $sid, $cookie ) ) {
			return false;
		}
		return (int) $uid === get_current_user_id();
	}

	/**
	 * Public wrapper around verify_token() for the shared endpoints that are
	 * not REST routes (admin-ajax) but want to accept the same short-lived,
	 * IP+UA-bound token as their light nonce.
	 *
	 * @param string $token Token from the request.
	 * @return bool
	 */
	public static function token_is_valid( $token ) {
		return '' !== (string) $token && self::verify_token( (string) $token );
	}

	/**
	 * @param string $raw Signed payload.
	 * @return string
	 */
	private static function mac( $raw ) {
		return hash_hmac( 'sha256', $raw, wp_hash( 'haai-token', 'nonce' ) );
	}

	/**
	 * Stable identity for rate limiting: the user id when logged in, otherwise
	 * a hash of the REAL client IP only. The session cookie is deliberately
	 * excluded — an attacker can rotate it per request, which would reset the
	 * limit every time. Only the trusted IP (REMOTE_ADDR or the admin-picked
	 * CDN header) and the logged-in account count.
	 *
	 * @return string
	 */
	public static function identifier() {
		$uid = get_current_user_id();
		if ( $uid ) {
			return 'u' . $uid;
		}
		return 'ip' . substr( md5( self::client_ip() ), 0, 32 );
	}

	/**
	 * Real client IP. Iranian hosts sit behind Cloudflare or a local CDN, so
	 * the admin picks which header to trust.
	 *
	 * For multi-value headers (e.g. X-Forwarded-For: "1.2.3.4, 5.6.7.8"),
	 * the string is split by comma, each part is trimmed and validated with
	 * filter_var, and the first PUBLIC IP is returned. If no public IP is
	 * found, falls back to REMOTE_ADDR (or '0.0.0.0' as last resort).
	 *
	 * @return string
	 */
	public static function client_ip() {
		$header = (string) Haa_Settings::get( 'advanced.ip_header', '' );
		if ( '' !== $header && isset( $_SERVER[ 'HTTP_' . strtoupper( str_replace( '-', '_', $header ) ) ] ) ) {
			$raw = (string) wp_unslash( $_SERVER[ 'HTTP_' . strtoupper( str_replace( '-', '_', $header ) ) ] );
			// Split multi-value header, trim each part, pick first public IP.
			$parts = explode( ',', $raw );
			foreach ( $parts as $part ) {
				$candidate = trim( $part );
				if ( '' === $candidate ) {
					continue;
				}
				// Must be a valid IP and not private/reserved.
				if ( filter_var( $candidate, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
					return $candidate;
				}
			}
			// No public IP found in header — fall through to REMOTE_ADDR.
		}
		if ( ! empty( $_SERVER['REMOTE_ADDR'] ) ) {
			$candidate = (string) wp_unslash( $_SERVER['REMOTE_ADDR'] );
			if ( filter_var( $candidate, FILTER_VALIDATE_IP ) ) {
				return $candidate;
			}
		}
		return '0.0.0.0';
	}

	/**
	 * @param array $history Raw history from the browser.
	 * @return array
	 */
	private static function sanitize_history( $history ) {
		$max  = (int) Haa_Settings::get( 'chat.max_history', 6 );
		if ( $max <= 0 || ! $history ) {
			return array();
		}
		$history = array_slice( (array) $history, -$max );
		$out     = array();
		foreach ( $history as $turn ) {
			if ( ! is_array( $turn ) ) {
				continue;
			}
			$role = isset( $turn['role'] ) ? (string) $turn['role'] : '';
			$text = isset( $turn['content'] ) ? trim( (string) $turn['content'] ) : '';
			if ( ! in_array( $role, array( 'user', 'assistant' ), true ) || '' === $text ) {
				continue;
			}
			$out[] = array(
				'role'    => $role,
				'content' => Haa_Util::cut( $text, 2000 ),
			);
		}
		return $out;
	}
}
