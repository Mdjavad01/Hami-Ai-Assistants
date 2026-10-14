<?php
/**
 * Monthly content refresh (مرحله D4).
 *
 * Old URLs are where SEO value leaks out: a link that now 404s, a price written
 * two years ago, an article that no longer points at the page that should have
 * been linked. This finds those things and proposes fixes.
 *
 * Two deliberate limits:
 *
 *  1. It only ever *proposes*. Nothing rewrites a published URL on a timer;
 *     applying a suggestion is a human click, and that click goes through
 *     wp_update_post so WordPress keeps a revision.
 *  2. post_modified is never touched by the scan itself. Claiming an article is
 *     "fresh" by bumping its date, without changing it, is the oldest trick in
 *     the book and search engines have learned to ignore it — so the date moves
 *     only when a change was actually applied.
 *
 * Outbound link checks are capped and go through the same SSRF door as the
 * crawler, and the whole scan is admin- or cron-side only: a visitor never pays
 * for it.
 *
 * @package Hami_AI_Assistant
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Haa_Refresh {

	const CRON  = 'haai_refresh_tick';
	const META  = '_haai_refresh';

	/** Links checked per post per run. */
	const LINKS_PER_POST = 25;

	/** Posts scanned in one run. */
	const POSTS_PER_RUN = 20;

	/** @return bool */
	public static function enabled() {
		return (bool) Haa_Settings::get( 'article.refresh_enabled', 0 ) && Haa_Settings::is_module_enabled( 'article-writer' );
	}

	/** @return int Age in days after which a post is worth a look. */
	public static function days() {
		return max( 30, min( 365, (int) Haa_Settings::get( 'article.refresh_days', 120 ) ) );
	}

	/* ------------------------------------------------------------------
	 * Selection
	 * ---------------------------------------------------------------- */

	/**
	 * Generated posts that have gone quiet for longer than the refresh window.
	 *
	 * @param int $limit Max posts.
	 * @return array<int,int> Post ids.
	 */
	public static function due( $limit = self::POSTS_PER_RUN ) {
		$args = array(
			'post_type'      => 'post',
			'post_status'    => array( 'publish', 'draft', 'pending' ),
			'posts_per_page' => max( 1, min( 100, (int) $limit ) ),
			'fields'         => 'ids',
			'meta_key'       => '_haai_generated',
			'meta_value'     => '1',
			// Oldest touched first: the stale ones are the point.
			'sort_order'     => 'ASC',
			'sort_by'        => 'modified',
			'no_found_rows'  => true,
		);

		$out = array();
		foreach ( (array) get_posts( $args ) as $id ) {
			$when = get_post_modified_time( 'U', true, (int) $id );
			if ( $when && $when < time() - ( self::days() * DAY_IN_SECONDS ) ) {
				$out[] = (int) $id;
			}
		}

		return $out;
	}

	/* ------------------------------------------------------------------
	 * One post
	 * ---------------------------------------------------------------- */

	/**
	 * Everything one scan can say about a post.
	 *
	 * @param int $post_id Post.
	 * @param bool $check_links Actually probe outbound links (off in a cron batch).
	 * @return array{broken:array,links:array,stale:array,age_days:int,checked:string}
	 */
	public static function inspect( $post_id, $check_links = true ) {
		$post = get_post( (int) $post_id );
		$out  = array(
			'broken'  => array(),
			'links'   => array(),
			'stale'   => array(),
			'age_days' => 0,
			'checked' => current_time( 'mysql' ),
		);
		if ( ! $post ) {
			return $out;
		}

		$modified = (int) get_post_modified_time( 'U', true, (int) $post_id );
		$out['age_days'] = $modified ? (int) floor( ( time() - $modified ) / DAY_IN_SECONDS ) : 0;

		$html = (string) $post->post_content;
		preg_match_all( '#<a\s[^>]*href=["\']([^"\']+)["\']#i', $html, $m );
		$urls = array_slice( array_values( array_unique( array_map( 'strval', (array) $m[1] ) ) ), 0, self::LINKS_PER_POST );

		$home = (string) home_url();

		foreach ( $urls as $url ) {
			if ( '' === trim( $url ) || 0 === strpos( $url, '#' ) || 0 === stripos( $url, 'mailto:' ) || 0 === stripos( $url, 'javascript:' ) ) {
				continue;
			}

			$internal = ( 0 === strpos( $url, $home ) && '' !== $home );

			if ( $internal ) {
				$id = url_to_postid( $url );
				if ( ! $id ) {
					$out['broken'][] = array(
						'url'    => $url,
						'reason' => __( 'لینک داخلی به هیچ نوشته‌ای نمی‌رسد.', 'hami-ai-assistant' ),
						'kind'   => 'internal',
					);
					continue;
				}
				$target = get_post( $id );
				if ( ! $target || in_array( (string) $target->post_status, array( 'trash', 'draft' ), true ) ) {
					$out['broken'][] = array(
						'url'    => $url,
						'reason' => __( 'نوشته‌ی مقصد حذف یا پیش‌نویس شده است.', 'hami-ai-assistant' ),
						'kind'   => 'internal',
					);
					continue;
				}
				// A live internal link that could point *better* elsewhere.
				$chance = self::link_chance( (string) $target->post_title, $url );
				if ( '' !== $chance ) {
					$out['links'][] = $chance;
				}
				continue;
			}

			if ( ! $check_links ) {
				continue;
			}

			$status = self::head( $url );
			if ( is_wp_error( $status ) ) {
				$out['broken'][] = array(
					'url'    => $url,
					'reason' => $status->get_error_message(),
					'kind'   => 'external',
				);
			} elseif ( (int) $status >= 400 ) {
				$out['broken'][] = array(
					'url'    => $url,
					/* translators: %d: HTTP status. */
					'reason' => sprintf( __( 'پاسخ %d داد.', 'hami-ai-assistant' ), (int) $status ),
					'kind'   => 'external',
				);
			}
		}

		$out['stale'] = self::staleness( $html, $post );

		return $out;
	}

	/**
	 * HEAD one URL through the safe door.
	 *
	 * @param string $url Target.
	 * @return int|WP_Error HTTP status or the failure reason.
	 */
	public static function head( $url ) {
		$clean = Haa_Settings::sanitize_url( $url );
		if ( '' === $clean ) {
			return new WP_Error( 'haai_rf_url', __( 'آدرس نامعتبر بود.', 'hami-ai-assistant' ) );
		}

		$safe = Haa_API::is_url_safe( $clean );
		if ( is_wp_error( $safe ) ) {
			return $safe;
		}

		$res = wp_remote_head(
			$clean,
			array(
				'timeout'    => max( 2, min( 15, (int) Haa_Settings::get( 'provider.timeout', 25 ) ) ),
				'redirection' => 2,
				'user-agent' => Haa_Research::user_agent(),
				'sslverify'  => true,
			)
		);
		if ( is_wp_error( $res ) ) {
			return new WP_Error( 'haai_rf_dead', __( 'سرور پاسخ نداد (احتمالاً بسته شده).', 'hami-ai-assistant' ) );
		}
		return (int) wp_remote_retrieve_response_code( $res );
	}

	/**
	 * Phrases that quietly age a post.
	 *
	 * @param string $html Post body.
	 * @param object $post Post row.
	 * @return array<int,array{text:string,why:string}>
	 */
	public static function staleness( $html, $post ) {
		$out  = array();
		$flat = wp_strip_all_tags( $html );

		$words = array(
			' امسال'      => __( '«امسال» بدون سال مشخص، سال دیگر بی‌معنی است.', 'hami-ai-assistant' ),
			'اخیراً'       => __( '«اخیراً» به زمان انتشار اشاره دارد، نه به امروز.', 'hami-ai-assistant' ),
			'جدیدترین'    => __( '«جدیدترین» با گذشت زمان غلط می‌شود.', 'hami-ai-assistant' ),
			'تا پایان'    => __( 'اشاره به مهلتی که تمام شده است.', 'hami-ai-assistant' ),
		);
		foreach ( $words as $needle => $why ) {
			$pos = mb_stripos( $flat, trim( $needle ) );
			if ( false === $pos ) {
				continue;
			}
			$out[] = array(
				'text' => Haa_Util::cut( mb_substr( $flat, max( 0, (int) $pos - 40 ), 140 ), 160 ),
				'why'  => (string) $why,
			);
		}

		// Explicit years older than the window read as "this page is old".
		$year  = (int) gmdate( 'Y' );
		$floor = $year - (int) ceil( self::days() / 365 ) - 1;
		if ( preg_match_all( '/\b(20\d{2})\b/', $flat, $m ) ) {
			foreach ( array_unique( (array) $m[1] ) as $y ) {
				if ( (int) $y < $floor ) {
					$out[] = array(
						'text' => (string) $y,
						'why'  => __( 'سال قدیمی در متن هست؛ بررسی کنید تاریخ‌ها هنوز درست باشند.', 'hami-ai-assistant' ),
					);
				}
			}
		}

		// A price written years ago is the most common wrong number on an old page.
		if ( false !== mb_strpos( $flat, 'تومان' ) && $out !== array() ) {
			$out[] = array(
				'text' => __( 'این صفحه قیمت دارد', 'hami-ai-assistant' ),
				'why'  => __( 'قیمت‌ها را با محصول امروز مقایسه کنید.', 'hami-ai-assistant' ),
			);
		}

		return array_slice( $out, 0, 8 );
	}

	/**
	 * Is there a better internal destination than the one currently used?
	 *
	 * Suggestion only — never rewritten in place.
	 *
	 * @param string $title Target title.
	 * @param string $url   Current link.
	 * @return string
	 */
	private static function link_chance( $title, $url ) {
		$hits = Haa_Search::search( (string) $title, 2, 200 );
		foreach ( (array) $hits as $h ) {
			$u = (string) ( $h['url'] ?? '' );
			if ( '' !== $u && $u !== $url ) {
				return sprintf(
					/* translators: 1: title, 2: url. */
					__( 'لینک «%1$s» شاید بهتر است به %2$s برود.', 'hami-ai-assistant' ),
					Haa_Util::cut( $title, 60 ),
					$u
				);
			}
		}
		return '';
	}

	/* ------------------------------------------------------------------
	 * Scan + store
	 * ---------------------------------------------------------------- */

	/**
	 * Scan the due posts and store what needs attention.
	 *
	 * @param bool $check_links Probe outbound URLs too (slow; manual runs only).
	 * @param int  $limit       Max posts.
	 * @return array{scanned:int,needing:int,errors:array<int,string>}
	 */
	public static function scan( $check_links = false, $limit = self::POSTS_PER_RUN ) {
		$ids     = self::due( $limit );
		$needing = 0;
		$errors  = array();

		foreach ( $ids as $id ) {
			$r = self::inspect( $id, $check_links );
			if ( ! $r['broken'] && ! $r['stale'] && ! $r['links'] ) {
				update_post_meta( $id, self::META, array('clean' => 1, 'checked' => $r['checked'], 'age_days' => $r['age_days']) );
				continue;
			}

			$report = array(
				'clean'    => 0,
				'checked'  => $r['checked'],
				'age_days' => $r['age_days'],
				'broken'   => $r['broken'],
				'stale'    => $r['stale'],
				'links'    => $r['links'],
			);
			update_post_meta( $id, self::META, $report );
			$needing++;
		}

		return array( 'scanned' => count( $ids ), 'needing' => $needing, 'errors' => $errors );
	}

	/**
	 * Posts whose last scan found something.
	 *
	 * @param int $limit Max rows.
	 * @return array<int,array{post:object,report:array}>
	 */
	public static function needing( $limit = 20 ) {
		$posts = (array) get_posts(
			array(
				'post_type'      => 'post',
				'post_status'    => array( 'publish', 'draft', 'pending' ),
				'posts_per_page' => max( 1, min( 50, (int) $limit ) ),
				'meta_key'       => self::META,
			)
		);

		$out = array();
		foreach ( $posts as $p ) {
			$report = (array) get_post_meta( (int) $p->ID, self::META, true );
			if ( empty( $report ) || ! empty( $report['clean'] ) ) {
				continue;
			}
			$out[] = array(
				'post'   => $p,
				'report' => $report,
			);
		}

		return $out;
	}

	/**
	 * Apply one approved fix: drop a dead link, keep the words around it.
	 *
	 * Only ever reached from a human click. post_modified moves because the
	 * content genuinely changed.
	 *
	 * @param int    $post_id Post.
	 * @param string $url     Link to remove.
	 * @return true|WP_Error
	 */
	public static function drop_link( $post_id, $url ) {
		$post = get_post( (int) $post_id );
		if ( ! $post ) {
			return new WP_Error( 'haai_rf_post', __( 'نوشته پیدا نشد.', 'hami-ai-assistant' ) );
		}

		$url = trim( (string) $url );
		if ( '' === $url || false === strpos( (string) $post->post_content, $url ) ) {
			return new WP_Error( 'haai_rf_none', __( 'این لینک در متن نیست.', 'hami-ai-assistant' ) );
		}

		// Unwrap the anchor, keep the label: removing the text too would silently
		// shorten the article.
		$pattern = '#<a\s[^>]*href=["\']' . preg_quote( $url, '#' ) . '["\'][^>]*>(.*?)</a>#is';
		$new     = preg_replace_callback(
			$pattern,
			function ( $m ) {
				return (string) $m[1];
			},
			(string) $post->post_content
		);

		if ( null === $new || $new === $post->post_content ) {
			return new WP_Error( 'haai_rf_fail', __( 'چیزی از متن کم نشد.', 'hami-ai-assistant' ) );
		}

		$done = wp_update_post(
			array(
				'ID'           => (int) $post_id,
				'post_content' => (string) $new,
			),
			true
		);
		if ( is_wp_error( $done ) ) {
			return $done;
		}

		// The scan report was about the old text; re-inspect now that it changed.
		self::inspect_again( (int) $post_id );

		return true;
	}

	/**
	 * Re-scan without link probing and store the fresher report.
	 *
	 * @param int $post_id Post.
	 * @return void
	 */
	public static function inspect_again( $post_id ) {
		$r = self::inspect( (int) $post_id, false );
		update_post_meta(
			(int) $post_id,
			self::META,
			array(
				'clean'    => ( $r['broken'] || $r['stale'] || $r['links'] ) ? 0 : 1,
				'checked'  => $r['checked'],
				'age_days' => $r['age_days'],
				'broken'   => $r['broken'],
				'stale'    => $r['stale'],
				'links'    => $r['links'],
			)
		);
	}

	/**
	 * Mark one broken link as accepted (a human decided it is fine).
	 *
	 * @param int    $post_id Post.
	 * @param string $url     Link.
	 * @return bool
	 */
	public static function dismiss( $post_id, $url ) {
		if ( ! get_post( (int) $post_id ) ) {
			return false;
		}

		$report = (array) get_post_meta( (int) $post_id, self::META, true );
		// get_post_meta() with $single returns '' when unset, and (array) '' is
		// [ '' ] — non-empty. Without this guard a missing report "dismissed" fine.
		if ( ! $report || ( 1 === count( $report ) && '' === reset( $report ) ) ) {
			return false;
		}

		$kept = array();
		foreach ( (array) ( $report['broken'] ?? array() ) as $row ) {
			if ( (string) ( $row['url'] ?? '' ) !== trim( (string) $url ) ) {
				$kept[] = $row;
			}
		}
		$report['broken'] = $kept;
		if ( ! $kept && empty( $report['stale'] ) && empty( $report['links'] ) ) {
			$report['clean'] = 1;
		}
		update_post_meta( (int) $post_id, self::META, $report );
		return true;
	}

	/* ------------------------------------------------------------------
	 * Cron
	 * ---------------------------------------------------------------- */

	/** @return void */
	public static function schedule() {
		if ( ! wp_next_scheduled( self::CRON ) ) {
			wp_schedule_event( time() + DAY_IN_SECONDS, 'monthly', self::CRON );
		}
	}

	/** @return void */
	public static function unschedule() {
		wp_clear_scheduled_hook( self::CRON );
	}

	/**
	 * Nightly/monthly tick: metadata-level checks only.
	 *
	 * Outbound HEAD probes are left for the manual run: an automated batch of
	 * them is exactly the kind of thing that gets a host rate-limited.
	 *
	 * @return void
	 */
	public static function cron_run() {
		if ( ! self::enabled() ) {
			return;
		}
		self::scan( false );
	}

	/**
	 * Human summary of one report.
	 *
	 * @param array $report Stored report.
	 * @return string
	 */
	public static function summary( $report ) {
		$bits = array();
		$n    = count( (array) ( $report['broken'] ?? array() ) );
		if ( $n ) {
			$bits[] = sprintf( /* translators: %d: count. */ __( '%d لینک مشکل‌دار', 'hami-ai-assistant' ), $n );
		}
		$n = count( (array) ( $report['stale'] ?? array() ) );
		if ( $n ) {
			$bits[] = sprintf( /* translators: %d: count. */ __( '%d نکته‌ی تازگی', 'hami-ai-assistant' ), $n );
		}
		$n = count( (array) ( $report['links'] ?? array() ) );
		if ( $n ) {
			$bits[] = sprintf( /* translators: %d: count. */ __( '%d پیشنهاد لینک', 'hami-ai-assistant' ), $n );
		}
		return $bits ? implode( ' · ', $bits ) : __( 'مشکلی پیدا نشد.', 'hami-ai-assistant' );
	}
}
