<?php
/**
 * Site Profile — what the AI is told about this particular business.
 *
 * Without this the model writes generic Persian prose for a generic shop. With
 * it, every generation call knows the field, the audience, the brand voice, the
 * real products, the vocabulary the customers use, and — just as important —
 * the claims the site is not allowed to make.
 *
 * Stored as plain text in the big settings array (autoload = no), so it costs
 * nothing on a page view. An admin-built draft goes into its own option first
 * and is only copied into the live profile when a human approves it: a machine
 * suggestion must never become a fact about the business by itself.
 *
 * @package Hami_AI_Assistant
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Haa_Site_Profile {

	/** Pending, not-yet-approved draft. Never autoloaded. */
	const DRAFT_OPTION = 'haai_profile_draft';

	/** Keys a draft may fill, and what each one is for. */
	const TEXT_KEYS = array( 'domain', 'audience', 'voice', 'offerings', 'glossary', 'forbidden', 'examples' );

	/**
	 * Field definitions.
	 *
	 * The article-writer module renders these, and Haa_Settings::collect_ui_keys()
	 * mirrors the same key list for diagnose(); the two must stay equal.
	 *
	 * @return array<int,array>
	 */
	public static function fields() {
		return array(
			array( 'key' => 'profile.enabled', 'type' => 'checkbox', 'label' => __( 'استفاده از حافظه‌ی سایت در تولید محتوا', 'hami-ai-assistant' ), 'hint' => __( 'اگر خاموش باشد، هیچ بخشی از این پروفایل به مدل فرستاده نمی‌شود.', 'hami-ai-assistant' ) ),
			array( 'key' => 'profile.domain', 'type' => 'text', 'label' => __( 'حوزه‌ی فعالیت', 'hami-ai-assistant' ), 'hint' => __( 'یک خط: کسب‌وکار شما دقیقاً چه کاره است.', 'hami-ai-assistant' ) ),
			array( 'key' => 'profile.audience', 'type' => 'text', 'label' => __( 'مخاطب', 'hami-ai-assistant' ), 'hint' => __( 'کسی که می‌خواند: نقش، دغدغه، سطح دانش.', 'hami-ai-assistant' ) ),
			array( 'key' => 'profile.voice', 'type' => 'text', 'label' => __( 'لحن برند', 'hami-ai-assistant' ), 'hint' => __( 'مثلاً «صمیمی ولی کارشناسی؛ از صغیر کبری پرهیز کن».', 'hami-ai-assistant' ) ),
			array( 'key' => 'profile.offerings', 'type' => 'textarea', 'label' => __( 'محصولات و خدمات اصلی', 'hami-ai-assistant' ), 'hint' => __( 'هر خط یک مورد. فقط چیزهایی که واقعاً می‌فروشید.', 'hami-ai-assistant' ) ),
			array( 'key' => 'profile.glossary', 'type' => 'textarea', 'label' => __( 'واژه‌نامه‌ی اصطلاحات', 'hami-ai-assistant' ), 'hint' => __( 'اصطلاح‌هایی که مشتری‌های شما به کار می‌برند؛ مدل همان‌ها را یاد می‌گیرد.', 'hami-ai-assistant' ) ),
			array( 'key' => 'profile.forbidden', 'type' => 'textarea', 'label' => __( 'ادعاهای ممنوع', 'hami-ai-assistant' ), 'hint' => __( 'هر خط یک ادعا که هرگز نباید در متن بیاید (مثلاً «ارزان‌ترین ایران»).', 'hami-ai-assistant' ) ),
			array( 'key' => 'profile.examples', 'type' => 'textarea', 'label' => __( 'مثال‌های خوب', 'hami-ai-assistant' ), 'hint' => __( 'چند تیتر یا پاراگراف که سبک مطلوب شماست.', 'hami-ai-assistant' ) ),
			array( 'key' => 'profile.key_pages', 'type' => 'pagepicker', 'label' => __( 'صفحات کلیدی برای لینک‌سازی', 'hami-ai-assistant' ), 'hint' => __( 'مقاله‌های تازه به این صفحه‌ها لینک می‌دهند، نه به هر چیزی.', 'hami-ai-assistant' ) ),
			array( 'key' => 'profile.max_chars', 'type' => 'number', 'label' => __( 'سقف کاراکتر پروفایل در هر پرامپت', 'hami-ai-assistant' ), 'min' => 200, 'max' => 4000, 'hint' => __( 'نسخه‌ی کامل ذخیره می‌شود؛ آنچه ارسال می‌شود گزیده و مرتبط است.', 'hami-ai-assistant' ) ),
		);
	}

	/**
	 * The saved profile, always complete (missing keys come back empty).
	 *
	 * @return array
	 */
	public static function get() {
		$s = Haa_Settings::get();
		return isset( $s['profile'] ) && is_array( $s['profile'] ) ? $s['profile'] : array();
	}

	/** @return bool */
	public static function enabled() {
		return ! empty( self::get()['enabled'] );
	}

	/**
	 * Is there anything worth sending at all?
	 *
	 * @return bool
	 */
	public static function has_content() {
		foreach ( self::TEXT_KEYS as $key ) {
			if ( '' !== trim( (string) ( self::get()[ $key ] ?? '' ) ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * The block injected into generation prompts.
	 *
	 * Relevance, not volume: the always-on parts are domain, audience, voice and
	 * the forbidden-claims list, while offerings and glossary contribute only
	 * the lines that share a word with the subject being written. Everything is
	 * then cut at the configured ceiling. This is what keeps a long profile from
	 * silently inflating every single request.
	 *
	 * @param string $topic What is being written (article topic, product name…).
	 * @return string Empty when the profile is off, empty, or irrelevant.
	 */
	public static function block( $topic = '' ) {
		if ( ! self::enabled() || ! self::has_content() ) {
			return '';
		}

		$p     = self::get();
		$cap   = max( 200, (int) ( $p['max_chars'] ?? 1200 ) );
		$lines = array();

		$always = array(
			array( __( 'حوزه', 'hami-ai-assistant' ), 'domain' ),
			array( __( 'مخاطب', 'hami-ai-assistant' ), 'audience' ),
			array( __( 'لحن برند', 'hami-ai-assistant' ), 'voice' ),
		);
		foreach ( $always as $item ) {
			$val = trim( (string) ( $p[ $item[1] ] ?? '' ) );
			if ( '' !== $val ) {
				$lines[] = $item[0] . ': ' . $val;
			}
		}

		// Only the lines that actually speak to this subject.
		$topic_words = self::words( $topic );
		foreach ( array( 'offerings', 'glossary', 'examples' ) as $key ) {
			$kept = self::relevant_lines( (string) ( $p[ $key ] ?? '' ), $topic_words );
			if ( $kept ) {
				$label = 'offerings' === $key
					? __( 'محصول/خدمت مرتبط', 'hami-ai-assistant' )
					: ( 'glossary' === $key
						? __( 'واژگان رایج مشتری', 'hami-ai-assistant' )
						: __( 'نمونه‌ی سبک مطلوب', 'hami-ai-assistant' ) );
				$lines[] = $label . ': ' . implode( ' | ', $kept );
			}
		}

		$forbidden = self::relevant_lines( (string) ( $p['forbidden'] ?? '' ), $topic_words, true );
		if ( $forbidden ) {
			$lines[] = __( 'ادعاهای ممنوع (هرگز ننویس)', 'hami-ai-assistant' ) . ': ' . implode( ' | ', $forbidden );
		}

		$pages = self::key_pages();
		if ( $pages ) {
			$lines[] = __( 'صفحات کلیدی برای لینک داخلی', 'hami-ai-assistant' ) . ': ' . implode( ' | ', $pages );
		}

		if ( ! $lines ) {
			return '';
		}

		$text = implode( "\n", $lines );

		return Haa_Util::cut( $text, $cap );
	}

	/**
	 * Meaningful words of a phrase, for the relevance test.
	 *
	 * @param string $text Phrase.
	 * @return array<string,int>
	 */
	private static function words( $text ) {
		$out = array();
		foreach ( Haa_Search::tokenize( (string) $text ) as $word ) {
			if ( mb_strlen( $word ) < 3 ) {
				continue; // Fragments match everything and buy nothing.
			}
			$out[ $word ] = 1;
		}
		return $out;
	}

	/**
	 * Keep the stored lines that share a word with the subject.
	 *
	 * With no subject (or no overlap) every line is kept, up to four: a profile
	 * that says nothing about the topic is still better than no profile, and the
	 * character cap does the budgeting.
	 *
	 * @param string        $raw     Newline-separated stored lines.
	 * @param array<string,int> $topic_words Words of the subject.
	 * @param bool          $always  Keep everything when nothing matches.
	 * @return array<int,string>
	 */
	private static function relevant_lines( $raw, $topic_words, $always = false ) {
		$all = array();
		foreach ( preg_split( '/\R/u', (string) $raw ) as $line ) {
			$line = trim( $line );
			if ( '' !== $line ) {
				$all[] = $line;
			}
		}
		if ( ! $all ) {
			return array();
		}

		$hits = array();
		if ( $topic_words ) {
			foreach ( $all as $line ) {
				foreach ( self::words( $line ) as $word => $one ) {
					if ( isset( $topic_words[ $word ] ) ) {
						$hits[] = $line;
						break;
					}
				}
			}
		}

		if ( ! $hits && ( $always || ! $topic_words ) ) {
			$hits = $all;
		}

		return array_slice( array_values( array_unique( $hits ) ), 0, 4 );
	}

	/**
	 * Titles of the key pages, for internal-link instructions.
	 *
	 * @return array<int,string>
	 */
	public static function key_pages() {
		$out = array();
		foreach ( (array) ( self::get()['key_pages'] ?? array() ) as $id ) {
			$id  = (int) $id;
			$post = $id ? get_post( $id ) : null;
			if ( $post ) {
				$out[] = get_the_title( $id ) . ' (' . get_permalink( $id ) . ')';
			}
		}
		return array_slice( $out, 0, 8 );
	}

	/* ------------------------------------------------------------------
	 * Drafts
	 * ---------------------------------------------------------------- */

	/**
	 * Save a draft for human review.
	 *
	 * @param array $values Key => text.
	 * @return void
	 */
	public static function draft_save( $values ) {
		$clean = array( 'created' => current_time( 'mysql' ) );
		foreach ( self::TEXT_KEYS as $key ) {
			if ( isset( $values[ $key ] ) ) {
				$clean[ $key ] = sanitize_textarea_field( $values[ $key ] );
			}
		}
		update_option( self::DRAFT_OPTION, $clean, 'no' );
	}

	/**
	 * @return array Empty when there is no draft.
	 */
	public static function draft_get() {
		$d = get_option( self::DRAFT_OPTION, null );
		return is_array( $d ) ? $d : array();
	}

	/** @return void */
	public static function draft_discard() {
		delete_option( self::DRAFT_OPTION );
	}

	/**
	 * Copy an approved draft into the live profile.
	 *
	 * Only the text keys move: `enabled` stays exactly as the admin left it, so
	 * approving a draft can never switch the profile on by itself.
	 *
	 * @return int How many fields were filled.
	 */
	public static function draft_approve() {
		$draft = self::draft_get();
		if ( ! $draft ) {
			return 0;
		}

		$all   = Haa_Settings::get();
		$doing = 0;
		foreach ( self::TEXT_KEYS as $key ) {
			$val = trim( (string) ( $draft[ $key ] ?? '' ) );
			if ( '' !== $val ) {
				$all['profile'][ $key ] = $val;
				$doing++;
			}
		}
		update_option( HAAI_OPTION, $all, 'no' );
		Haa_Settings::flush_cache();
		self::draft_discard();

		return $doing;
	}

	/* ------------------------------------------------------------------
	 * Auto-build, from what the site already holds
	 * ---------------------------------------------------------------- */

	/**
	 * Draft a profile by sampling the site: indexed content, categories,
	 * products and the words they use.
	 *
	 * No model call and no token spend — this only shows the admin what the
	 * site already says about itself, so they can correct it. Anything the site
	 * genuinely does not state (audience, brand voice) is left blank on purpose
	 * rather than guessed: a guessed audience would be injected into every
	 * later prompt as if it were known.
	 *
	 * @return array The draft, also stored for approval.
	 */
	public static function build_draft() {
		$titles  = self::sample_titles();
		$terms   = self::top_terms( $titles );
		$cats    = self::category_names();
		$product = self::product_names();

		$domain = get_bloginfo( 'name' );
		if ( $cats ) {
			$domain .= ' — ' . implode( '، ', array_slice( $cats, 0, 6 ) );
		}

		$draft = array(
			'domain'    => $domain,
			'audience'  => '',
			'voice'     => '',
			'offerings' => implode( "\n", $product ? $product : array_slice( $titles, 0, 12 ) ),
			'glossary'  => implode( "\n", array_slice( $terms, 0, 14 ) ),
			// Conservative defaults: the two claims AI writing drifts into most
			// often, offered for removal rather than addition.
			'forbidden' => implode(
				"\n",
				array(
					__( 'ارزان‌ترین / بهترین در ایران', 'hami-ai-assistant' ),
					__( 'تضمین نتیجه، درمان یا بازگشت سرمایه', 'hami-ai-assistant' ),
					__( 'هر عدد، آمار یا نرخ بدون منبع', 'hami-ai-assistant' ),
				)
			),
			'examples'  => implode( "\n", array_slice( $titles, 0, 5 ) ),
		);

		self::draft_save( $draft );

		return $draft;
	}

	/**
	 * Recent indexed titles — the knowledge base we already have.
	 *
	 * @return array<int,string>
	 */
	private static function sample_titles() {
		global $wpdb;

		$table = Haa_DB::table( 'chunks' );
		$rows  = $wpdb->get_col( "SELECT DISTINCT title FROM $table WHERE title <> '' ORDER BY id DESC LIMIT 60" ); // phpcs:ignore WordPress.DB

		$out = array();
		foreach ( (array) $rows as $title ) {
			$title = trim( wp_strip_all_tags( (string) $title ) );
			if ( '' !== $title ) {
				$out[] = $title;
			}
		}
		return $out;
	}

	/** @return array<int,string> */
	private static function top_terms( $titles ) {
		$counts = array();
		foreach ( (array) $titles as $title ) {
			foreach ( Haa_Search::tokenize( $title ) as $word ) {
				if ( mb_strlen( $word ) < 4 ) {
					continue;
				}
				$counts[ $word ] = ( isset( $counts[ $word ] ) ? $counts[ $word ] : 0 ) + 1;
			}
		}
		arsort( $counts );

		$out = array();
		foreach ( array_keys( $counts ) as $word ) {
			if ( count( $out ) >= 14 ) {
				break;
			}
			$out[] = $word;
		}
		return $out;
	}

	/** @return array<int,string> */
	private static function category_names() {
		$out = array();
		foreach ( (array) get_categories( array( 'hide_empty' => true, 'number' => 12, 'orderby' => 'count' ) ) as $cat ) {
			$out[] = (string) $cat->name;
		}
		return $out;
	}

	/** @return array<int,string> */
	private static function product_names() {
		if ( ! haa_is_woo() ) {
			return array();
		}
		$out = array();
		foreach ( (array) get_posts(
			array(
				'post_type'      => 'product',
				'post_status'    => 'publish',
				'posts_per_page' => 25,
				'orderby'        => 'title',
				'order'          => 'ASC',
				'fields'         => 'ids',
			)
		) as $id ) {
			$out[] = get_the_title( $id );
		}
		return $out;
	}
}
