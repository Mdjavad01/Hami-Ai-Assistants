<?php
/**
 * Small helpers shared by several modules. Nothing here talks to the DB or AI.
 *
 * @package Hami_AI_Assistant
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Haa_Util {

	/* ------------------------------------------------------------------
	 * Admin screen slugs — one map, used by every menu registration, link
	 * and redirect in the plugin.
	 *
	 * The "شما اجازه دسترسی به این برگه را ندارید" report came from a
	 * redirect concatenating 'haai-' with a short key that was never
	 * registered: the product-content screen registers haai-product-content
	 * while its back() helper sent the user to the short form of that name.
	 * WP then fails the menu lookup, which reads as a permission error.
	 * ---------------------------------------------------------------- */

	/**
	 * Screen key => the menu slug actually registered with WordPress.
	 *
	 * @return array<string,string>
	 */
	public static function screens() {
		return array(
			'settings'        => 'haai-settings',
			'dashboard'       => 'haai-dashboard',
			'modules'         => 'haai-modules',
			'leads'           => 'haai-leads',
			'discount'        => 'haai-discount',
			'autopilot'       => 'haai-autopilot',
			'article-writer'  => 'haai-article-writer',
			'comments'        => 'haai-comments',
			'semantic-search' => 'haai-semantic-search',
			'product-content' => 'haai-product-content',
			'news'            => 'haai-news',
			'video-article'   => 'haai-video-article',
			'programmatic'    => 'haai-programmatic',
		);
	}

	/**
	 * Registered slug for a screen key. An unknown key falls back to the
	 * settings page, so a typo can never send an admin to a dead screen.
	 *
	 * @param string $key Screen key.
	 * @return string
	 */
	public static function screen( $key ) {
		$screens = self::screens();
		return isset( $screens[ $key ] ) ? $screens[ $key ] : $screens['settings'];
	}

	/**
	 * admin.php URL for a screen key plus extra query args.
	 *
	 * @param string $key  Screen key.
	 * @param array  $args Extra query args (no `page`).
	 * @return string
	 */
	public static function screen_url( $key, $args = array() ) {
		$args = array_merge( array( 'page' => self::screen( $key ) ), $args );
		return add_query_arg( $args, admin_url( 'admin.php' ) );
	}

	/**
	 * Iranian mobile number, normalised to 09XXXXXXXXX.
	 *
	 * Accepts 09…, +989…, 989…, 00989… and the unicode digits people paste.
	 *
	 * @param string $phone Raw input.
	 * @return string Normalised number, or '' when it is not a valid Iranian mobile.
	 */
	public static function normalize_ir_mobile( $phone ) {
		$phone = self::to_ascii_digits( (string) $phone );
		$phone = preg_replace( '/[\s\-\.\(\)]+/', '', $phone );
		$phone = ltrim( $phone, '+' );

		if ( 0 === strpos( $phone, '0098' ) ) {
			$phone = '98' . substr( $phone, 4 );
		} elseif ( 0 === strpos( $phone, '98' ) ) {
			// Already country-coded.
		} elseif ( 0 === strpos( $phone, '9' ) && 10 === strlen( $phone ) ) {
			$phone = '98' . $phone;
		}

		if ( ! preg_match( '/^989\d{9}$/', $phone ) ) {
			return '';
		}
		return '0' . substr( $phone, 2 );
	}

	/**
	 * Persian / Arabic-Indic digits to ASCII.
	 *
	 * @param string $text Input.
	 * @return string
	 */
	public static function to_ascii_digits( $text ) {
		$persian = array( '۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹' );
		$arabic  = array( '٠', '١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩' );
		$ascii   = array( '0', '1', '2', '3', '4', '5', '6', '7', '8', '9' );
		return str_replace( array_merge( $persian, $arabic ), $ascii, (string) $text );
	}

	/**
	 * UTF-8 safe substring.
	 *
	 * @param string $text   Text.
	 * @param int    $length Length.
	 * @return string
	 */
	public static function cut( $text, $length ) {
		$text = (string) $text;
		if ( function_exists( 'mb_substr' ) ) {
			return mb_substr( $text, 0, $length, 'UTF-8' );
		}
		if ( function_exists( 'mb_str_cut' ) ) {
			return mb_str_cut( $text, $length, 'UTF-8' );
		}
		return substr( $text, 0, $length );
	}

	/**
	 * Strip tags but keep paragraph breaks readable.
	 *
	 * @param string $html HTML.
	 * @return string
	 */
	public static function html_to_text( $html ) {
		$html = (string) $html;
		$html = preg_replace( '#<(br|/p|/div|/li|/h[1-6])[^>]*>#i', "\n", $html );
		$text = wp_strip_all_tags( $html );
		$text = html_entity_decode( $text, ENT_QUOTES, 'UTF-8' );
		$text = preg_replace( "/[ \t]+/", ' ', $text );
		$text = preg_replace( "/\n{3,}/", "\n\n", $text );
		return trim( $text );
	}

	/**
	 * WordPress-safe HTML: the exact tags a generated article is allowed to use.
	 *
	 * @return array<string,array<string,bool>>
	 */
	public static function article_kses_allowed() {
		$tags = array(
			'p'          => array(),
			'br'         => array(),
			'h1'         => array(),
			'h2'         => array(),
			'h3'         => array(),
			'h4'         => array(),
			'ul'         => array(),
			'ol'         => array(),
			'li'         => array(),
			'strong'     => array(),
			'em'         => array(),
			'b'          => array(),
			'i'          => array(),
			'a'          => array(
				'href'   => true,
				'title'  => true,
				'rel'    => true,
				'target' => true,
			),
			'blockquote' => array(),
			'table'      => array(),
			'thead'      => array(),
			'tbody'      => array(),
			'tr'         => array(),
			'th'         => array(),
			'td'         => array(),
			'figure'     => array(),
			'figcaption' => array(),
			'img'        => array(
				'src'    => true,
				'alt'    => true,
				'width'  => true,
				'height' => true,
				'loading' => true,
			),
			'span'       => array(),
			'small'      => array(),
		);
		return $tags;
	}

	/**
	 * Pull a JSON object out of a model reply, tolerating code fences and prose.
	 *
	 * @param string $text Model output.
	 * @return array Decoded array, or empty array.
	 */
	public static function extract_json( $text ) {
		$text = trim( (string) $text );

		// ```json ... ```
		if ( preg_match( '/```(?:json)?\s*(.+?)```/s', $text, $m ) ) {
			$text = trim( $m[1] );
		}

		$decoded = json_decode( $text, true );
		if ( is_array( $decoded ) ) {
			return $decoded;
		}

		// First balanced {...} in the reply.
		$start = strpos( $text, '{' );
		if ( false === $start ) {
			$start = strpos( $text, '[' );
			if ( false === $start ) {
				return array();
			}
			$end = strrpos( $text, ']' );
			if ( false === $end ) {
				return array();
			}
			$slice = substr( $text, $start, $end - $start + 1 );
		} else {
			$end = strrpos( $text, '}' );
			if ( false === $end ) {
				return array();
			}
			$slice = substr( $text, $start, $end - $start + 1 );
		}

		$decoded = json_decode( $slice, true );
		return is_array( $decoded ) ? $decoded : array();
	}

	/**
	 * Persian-aware slug. Falls back to sanitize_title for non-Persian input.
	 *
	 * @param string $text Input.
	 * @return string
	 */
	public static function slugify( $text ) {
		$text = trim( (string) $text );
		if ( '' === $text ) {
			return '';
		}
		$slug = sanitize_title( $text );
		if ( '' === $slug ) {
			// sanitize_title strips Persian; keep a readable transliteration-free
			// fallback so two different Persian titles do not collide.
			$slug = 'post-' . substr( md5( $text ), 0, 8 );
		}
		return substr( $slug, 0, 190 );
	}

	/**
	 * Make a slug unique against existing posts.
	 *
	 * @param string $slug     Desired slug.
	 * @param int    $ignore_id Post to exclude (when editing).
	 * @return string
	 */
	public static function unique_slug( $slug, $ignore_id = 0 ) {
		$base = $slug;
		$n    = 2;
		while ( true ) {
			global $wpdb;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$exists = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT ID FROM {$wpdb->posts} WHERE post_name = %s AND post_status NOT IN ('trash','auto-draft') " . ( $ignore_id ? 'AND ID <> %d' : '' ) . ' LIMIT 1',
					$ignore_id ? array( $slug, $ignore_id ) : array( $slug )
				)
			);
			if ( ! $exists ) {
				return $slug;
			}
			$slug = $base . '-' . $n;
			$n++;
			if ( $n > 50 ) {
				return $base . '-' . substr( md5( (string) time() ), 0, 6 );
			}
		}
	}

	/**
	 * Neutralise CSV injection: prefix a cell whose first character is
	 * =, +, -, @, Tab or CR with a single quote so spreadsheet software
	 * treats it as plain text instead of a formula.
	 *
	 * @param mixed $value Cell value.
	 * @return string
	 */
	public static function escape_csv_field( $value ) {
		$value = (string) $value;
		if ( '' === $value ) {
			return $value;
		}
		if ( preg_match( '/^[=+\-@\t\r]/', $value ) ) {
			$value = "'" . $value;
		}
		return $value;
	}

	/**
	 * CSV cell escaping that also neutralises CSV injection (a leading =, +, -
	 * or @ makes Excel execute the cell).
	 *
	 * @param mixed $value Cell value.
	 * @return string
	 */
	public static function csv_cell( $value ) {
		return self::escape_csv_field( $value );
	}

	/**
	 * Safe download headers + exit.
	 *
	 * @param string $filename Name.
	 * @param string $csv      Body.
	 */
	public static function send_csv( $filename, $csv ) {
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=' . $filename );
		header( 'Pragma: no-cache' );
		// UTF-8 BOM so Excel renders Persian correctly.
		echo "\xEF\xBB\xBF" . $csv; // phpcs:ignore WordPress.Security.EscapeOutput
		exit;
	}

	/**
	 * Is WooCommerce active (not merely installed)?
	 *
	 * @return bool
	 */
	public static function woo() {
		return class_exists( 'WooCommerce' ) && function_exists( 'WC' );
	}

	/**
	 * Languages offered by the content generator.
	 *
	 * @return array<string,string> code => label.
	 */
	public static function languages() {
		return array(
			'fa' => __( 'فارسی', 'hami-ai-assistant' ),
			'en' => __( 'انگلیسی', 'hami-ai-assistant' ),
			'ar' => __( 'عربی', 'hami-ai-assistant' ),
			'tr' => __( 'ترکی', 'hami-ai-assistant' ),
			'de' => __( 'آلمانی', 'hami-ai-assistant' ),
			'fr' => __( 'فرانسوی', 'hami-ai-assistant' ),
			'ru' => __( 'روسی', 'hami-ai-assistant' ),
			'es' => __( 'اسپانیایی', 'hami-ai-assistant' ),
		);
	}

	/**
	 * @return array<string,string> code => label.
	 */
	public static function tones() {
		return array(
			'friendly'     => __( 'صمیمی', 'hami-ai-assistant' ),
			'professional'=> __( 'حرفه‌ای', 'hami-ai-assistant' ),
			'formal'      => __( 'رسمی', 'hami-ai-assistant' ),
			'excited'     => __( 'هیجان‌انگیز', 'hami-ai-assistant' ),
			'technical'   => __( 'فنی', 'hami-ai-assistant' ),
		);
	}

	/**
	 * @return array<string,string> code => label.
	 */
	public static function lengths() {
		return array(
			'short'  => __( 'کوتاه (~۴۰۰ کلمه)', 'hami-ai-assistant' ),
			'medium' => __( 'متوسط (~۸۰۰ کلمه)', 'hami-ai-assistant' ),
			'long'   => __( 'بلند (~۱۴۰۰ کلمه)', 'hami-ai-assistant' ),
			'xlong'  => __( 'خیلی بلند (~۲۲۰۰ کلمه)', 'hami-ai-assistant' ),
		);
	}

	/**
	 * Word target for a length slug.
	 *
	 * @param string $slug Length slug.
	 * @return int
	 */
	public static function length_words( $slug ) {
		$map = array( 'short' => 400, 'medium' => 800, 'long' => 1400, 'xlong' => 2200 );
		return isset( $map[ $slug ] ) ? $map[ $slug ] : 800;
	}

	/**
	 * Nonce + capability check for an admin AJAX call.
	 *
	 * @return void Dies on failure.
	 */
	public static function guard_admin_ajax() {
		check_ajax_referer( 'haai_admin', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'دسترسی غیرمجاز.', 'hami-ai-assistant' ) ), 403 );
		}
	}

	/**
	 * Nonce + capability check for an admin-post call.
	 *
	 * @param string $action Nonce action name. Each module passes its own
	 *                       (e.g. 'haai_cm', 'haai_lead') so the nonce matches
	 *                       the form that generated it.
	 * @return void Dies on failure.
	 */
	public static function guard_admin_post( $action = 'haai_admin' ) {
		check_admin_referer( $action );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'دسترسی غیرمجاز.', 'hami-ai-assistant' ) );
		}
	}
}
