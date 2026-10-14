<?php
/**
 * SEO helpers: title, meta description, slug, FAQ block and internal links.
 *
 * Writes only what WordPress understands natively (title / excerpt / slug /
 * post content) plus an FAQ block. When Yoast, Rank Math or AIOSEO is active
 * the meta fields are mirrored into their storage so the admin does not have
 * to copy anything by hand — but nothing breaks when no SEO plugin exists.
 *
 * @package Hami_AI_Assistant
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Haa_Seo {

	/**
	 * Register frontend hooks (called once from Haa_Plugin::boot).
	 *
	 * @return void
	 */
	public static function hooks() {
		add_filter( 'wp_robots', array( __CLASS__, 'filter_robots' ), 10, 1 );
		add_action( 'wp_head', array( __CLASS__, 'print_article_schema' ), 20 );
	}

	/**
	 * Generic wp_robots handler.
	 *
	 * Keeps any post with `_haai_noindex=1` out of the index even when no SEO
	 * plugin is installed. Search result pages are also noindexed (thin content).
	 * The programmatic module's own filter stays as a fallback for older installs.
	 *
	 * @param array $robots Robots directives.
	 * @return array
	 */
	public static function filter_robots( $robots ) {
		if ( is_search() ) {
			$robots['noindex'] = true;
			return $robots;
		}
		if ( ! is_singular() ) {
			return $robots;
		}
		$post_id = (int) get_queried_object_id();
		if ( $post_id && '1' === (string) get_post_meta( $post_id, '_haai_noindex', true ) ) {
			$robots['noindex'] = true;
			// Backward compat: some code checks `index` key.
			if ( isset( $robots['index'] ) ) {
				$robots['index'] = 'noindex';
			}
		}
		return $robots;
	}

	/**
	 * Print Article JSON-LD for AI-generated posts.
	 *
	 * Outputs only for singular `post` with `_haai_generated=1` and only when
	 * no SEO plugin (Yoast/Rank Math/AIOSEO) is active — those already emit
	 * Article. Keeps rich-result eligibility on lean installs.
	 *
	 * @return void
	 */
	public static function print_article_schema() {
		if ( ! is_singular( 'post' ) ) {
			return;
		}
		if ( defined( 'WPSEO_VERSION' ) || class_exists( 'RankMath' ) || defined( 'AIOSEO_VERSION' ) || function_exists( 'aioseo' ) ) {
			return;
		}
		$post_id = (int) get_queried_object_id();
		if ( ! $post_id || '1' !== (string) get_post_meta( $post_id, '_haai_generated', true ) ) {
			return;
		}
		$post = get_post( $post_id );
		if ( ! $post ) {
			return;
		}
		$author_name = get_the_author_meta( 'display_name', (int) $post->post_author );
		if ( '' === trim( (string) $author_name ) ) {
			$author_name = get_bloginfo( 'name' );
		}
		$published = get_the_date( 'c', $post );
		$modified  = get_post_modified_time( 'c', true, $post );
		if ( '' === $published ) {
			$published = gmdate( 'c', strtotime( (string) $post->post_date_gmt ) );
		}
		if ( '' === $modified ) {
			$modified = $published;
		}
		$desc = trim( (string) $post->post_excerpt );
		if ( '' === $desc ) {
			$desc = (string) get_post_meta( $post_id, '_yoast_wpseo_metadesc', true );
		}
		if ( '' === $desc ) {
			$desc = wp_trim_words( wp_strip_all_tags( (string) $post->post_content ), 30, '' );
		}
		$desc = Haa_Util::cut( $desc, 300 );
		$image = '';
		if ( has_post_thumbnail( $post_id ) ) {
			$image = (string) get_the_post_thumbnail_url( $post_id, 'full' );
		}
		$schema = array(
			'@context'         => 'https://schema.org',
			'@type'            => 'Article',
			'headline'         => Haa_Util::cut( (string) get_the_title( $post_id ), 200 ),
			'datePublished'    => $published,
			'dateModified'     => $modified,
			'author'           => array(
				'@type' => 'Person',
				'name'  => $author_name,
			),
			'publisher'        => array(
				'@type' => 'Organization',
				'name'  => (string) get_bloginfo( 'name' ),
			),
			'description'      => $desc,
			'mainEntityOfPage' => array(
				'@type' => 'WebPage',
				'@id'   => (string) get_permalink( $post_id ),
			),
		);
		if ( '' !== $image ) {
			$schema['image'] = $image;
		}
		echo '<script type="application/ld+json">' . str_replace( '</', '<\/', (string) wp_json_encode( $schema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) ) . '</script>' . "\n";
	}

	/**
	 * Apply a content brief to a post.
	 *
	 * @param int   $post_id Post id.
	 * @param array $brief   html, seo_title, seo_description, slug, faq, excerpt, notes.
	 * @param bool  $strict  Refuse the whole write when a note is still inside.
	 * @return array<string,mixed>
	 */
	public static function apply( $post_id, $brief, $strict = false ) {
		$post_id = (int) $post_id;
		$note_meta = false;
		$applied = array(
			'title'  => false,
			'excerpt'=> false,
			'slug'   => false,
			'body'   => false,
			'faq'    => false,
			'yoast'  => false,
			'rank'   => false,
			'aioseo' => false,
			'blocked'=> '',
		);
		if ( ! $post_id || ! get_post( $post_id ) ) {
			return $applied;
		}

		// The last line of defence. Notes are for the reviewer, not for the
		// customer: an admin may have pasted one into the HTML editor, a preview
		// built before notes were split may still sit in a transient, or another
		// module may hand over raw model text.
		//
		// Strict (the reviewed product screen) writes nothing at all, so a human
		// decides. Non-strict (the article/news/programmatic writers, which have
		// no reviewer in the loop at this moment) moves the notes into
		// `_haai_review_notes` and writes the clean text — the page never gets a
		// note, and no prose is silently dropped.
		$stuck = self::notes_in( $brief );
		if ( $stuck ) {
			if ( $strict ) {
				$applied['blocked'] = 'notes';
				return $applied;
			}
			$brief = Haa_Content::clean_notes( $brief );
			$note_meta = true;
		}
		$applied = self::write( $post_id, $brief, $applied );

		if ( $note_meta ) {
			update_post_meta( $post_id, '_haai_review_notes', (array) ( $brief['notes'] ?? array() ) );
		}

		return $applied;
	}

	/**
	 * The actual field writes, split out so the note guard stays readable.
	 *
	 * @param int   $post_id  Post id.
	 * @param array $brief    Clean brief.
	 * @param array $applied  Result flags so far.
	 * @return array
	 */
	private static function write( $post_id, $brief, $applied ) {
		$title = trim( (string) ( $brief['seo_title'] ?? '' ) );
		if ( '' !== $title ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$applied['title'] = (bool) wp_update_post(
				array(
					'ID'         => $post_id,
					'post_title' => Haa_Util::cut( $title, 200 ),
				),
				true
			);
			if ( is_wp_error( $applied['title'] ) ) {
				$applied['title'] = false;
			}
		}

		$excerpt = trim( (string) ( $brief['seo_description'] ?? '' ) );
		if ( '' === $excerpt ) {
			$excerpt = trim( (string) ( $brief['excerpt'] ?? '' ) );
		}
		if ( '' !== $excerpt ) {
			$ok = wp_update_post(
				array(
					'ID'           => $post_id,
					'post_excerpt' => Haa_Util::cut( $excerpt, 500 ),
				),
				true
			);
			$applied['excerpt'] = ! is_wp_error( $ok );
		}

		$slug = trim( (string) ( $brief['slug'] ?? '' ) );
		if ( '' !== $slug ) {
			$slug = Haa_Util::unique_slug( Haa_Util::slugify( $slug ), $post_id );
			$ok   = wp_update_post(
				array(
					'ID'        => $post_id,
					'post_name' => $slug,
				),
				true
			);
			$applied['slug'] = ! is_wp_error( $ok );
		}

		$html = trim( (string) ( $brief['html'] ?? '' ) );
		$faq  = (array) ( $brief['faq'] ?? array() );
		if ( '' !== $html || $faq ) {
			$body = '' !== $html ? $html : '';
			if ( $faq ) {
				$body .= self::faq_html( $faq );
			}
			$ok = wp_update_post(
				array(
					'ID'           => $post_id,
					'post_content' => wp_kses( $body, Haa_Util::article_kses_allowed() ),
				),
				true
			);
			$applied['body'] = ! is_wp_error( $ok );
			$applied['faq']  = (bool) $faq;
		}

		$applied['yoast']  = self::write_yoast( $post_id, $title, $excerpt );
		$applied['rank']   = self::write_rank_math( $post_id, $title, $excerpt );
		$applied['aioseo'] = self::write_aioseo( $post_id, $title, $excerpt );

		return $applied;
	}

	/**
	 * Notes still stuck in a brief that is about to be written.
	 *
	 * @param array $brief html, faq, seo_title, seo_description, excerpt.
	 * @return array<int,string> Note texts found (empty when the brief is clean).
	 */
	public static function notes_in( $brief ) {
		$found = array();
		$scan  = array(
			(string) ( $brief['html'] ?? '' ),
			(string) ( $brief['seo_title'] ?? '' ),
			(string) ( $brief['seo_description'] ?? '' ),
			(string) ( $brief['excerpt'] ?? '' ),
		);
		foreach ( (array) ( $brief['faq'] ?? array() ) as $row ) {
			$scan[] = (string) ( $row['q'] ?? '' );
			$scan[] = (string) ( $row['a'] ?? '' );
		}
		foreach ( $scan as $text ) {
			$parts = Haa_Content::split_notes( $text );
			foreach ( $parts[1] as $note ) {
				$found[] = $note;
			}
		}
		return array_values( array_unique( $found ) );
	}

	/**
	 * FAQ as plain HTML (not a block) so it survives kses and any theme.
	 *
	 * @param array $faq List of {q,a}.
	 * @return string
	 */
	public static function faq_html( $faq ) {
		if ( ! $faq ) {
			return '';
		}
		$out = "\n\n<h2>" . esc_html__( 'سؤالات متداول', 'hami-ai-assistant' ) . "</h2>\n";
		foreach ( $faq as $item ) {
			$q = trim( (string) ( $item['q'] ?? '' ) );
			$a = trim( (string) ( $item['a'] ?? '' ) );
			if ( '' === $q || '' === $a ) {
				continue;
			}
			$out .= '<h3>' . esc_html( $q ) . "</h3>\n";
			$out .= '<p>' . esc_html( wp_strip_all_tags( $a ) ) . "</p>\n";
		}
		return $out;
	}

	/* ------------------------------------------------------------------
	 * SEO plugin mirrors. Each one is a no-op when the plugin is absent.
	 * ---------------------------------------------------------------- */

	/**
	 * @param int    $post_id Post.
	 * @param string $title   Focus title.
	 * @param string $desc    Meta description.
	 * @return bool
	 */
	private static function write_yoast( $post_id, $title, $desc ) {
		if ( ! defined( 'WPSEO_VERSION' ) ) {
			return false;
		}
		$meta = array();
		if ( '' !== $title ) {
			$meta['_yoast_wpseo_title'] = $title;
		}
		if ( '' !== $desc ) {
			$meta['_yoast_wpseo_metadesc'] = Haa_Util::cut( $desc, 155 );
		}
		foreach ( $meta as $k => $v ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			if ( false === update_post_meta( $post_id, $k, $v ) ) {
				return false;
			}
		}
		return (bool) $meta;
	}

	/**
	 * @param int    $post_id Post.
	 * @param string $title   Focus title.
	 * @param string $desc    Meta description.
	 * @return bool
	 */
	private static function write_rank_math( $post_id, $title, $desc ) {
		if ( ! class_exists( 'RankMath' ) ) {
			return false;
		}
		$meta = array();
		if ( '' !== $title ) {
			$meta['rank_math_title'] = $title;
		}
		if ( '' !== $desc ) {
			$meta['rank_math_description'] = Haa_Util::cut( $desc, 155 );
		}
		foreach ( $meta as $k => $v ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			if ( false === update_post_meta( $post_id, $k, $v ) ) {
				return false;
			}
		}
		return (bool) $meta;
	}

	/**
	 * @param int    $post_id Post.
	 * @param string $title   Focus title.
	 * @param string $desc    Meta description.
	 * @return bool
	 */
	private static function write_aioseo( $post_id, $title, $desc ) {
		if ( ! defined( 'AIOSEO_VERSION' ) && ! function_exists( 'aioseo' ) ) {
			return false;
		}
		$meta = array();
		if ( '' !== $title ) {
			$meta['_aioseo_title'] = $title;
		}
		if ( '' !== $desc ) {
			$meta['_aioseo_description'] = Haa_Util::cut( $desc, 155 );
		}
		foreach ( $meta as $k => $v ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			if ( false === update_post_meta( $post_id, $k, $v ) ) {
				return false;
			}
		}
		return (bool) $meta;
	}

	/**
	 * Mark a post noindex — in the SEO plugin's own fields when one is installed,
	 * and always in our own marker so the setting survives switching plugins.
	 *
	 * Programmatic pages default to this: an unproven template page should not be
	 * pushed into the index on the day it is written.
	 *
	 * @param int  $post_id Post.
	 * @param bool $noindex True to keep it out of the index.
	 * @return array<string,bool> Which surfaces were written.
	 */
	public static function set_robots( $post_id, $noindex ) {
		$post_id = (int) $post_id;
		$out     = array( 'marker' => false, 'yoast' => false, 'rank' => false, 'aioseo' => false );
		if ( ! $post_id ) {
			return $out;
		}

		$out['marker'] = false !== update_post_meta( $post_id, '_haai_noindex', $noindex ? '1' : '0' );

		if ( defined( 'WPSEO_VERSION' ) ) {
			$out['yoast'] = false !== update_post_meta( $post_id, '_yoast_wpseo_meta-robots-noindex', $noindex ? '1' : '-1' );
		}
		if ( class_exists( 'RankMath' ) ) {
			$out['rank'] = false !== update_post_meta( $post_id, 'rank_math_robots', $noindex ? array( 'noindex' ) : array( 'index' ) );
		}
		if ( defined( 'AIOSEO_VERSION' ) || function_exists( 'aioseo' ) ) {
			$out['aioseo'] = false !== update_post_meta( $post_id, '_aio_index_noindex', $noindex ? '1' : '0' );
		}

		return $out;
	}
}
