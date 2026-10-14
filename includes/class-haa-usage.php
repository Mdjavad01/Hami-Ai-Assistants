<?php
/**
 * AI usage accounting. One lightweight row per provider call; the dashboard
 * reads aggregates, never the raw conversation.
 *
 * @package Hami_AI_Assistant
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Haa_Usage {

	/**
	 * Write one usage row. Never throws — accounting must not break a request.
	 *
	 * @param array $data module, model, operation, tokens_in, tokens_out, latency_ms, ok, error_code.
	 */
	public static function log( $data ) {
		global $wpdb;

		$defaults = array(
			'module'     => 'core',
			'model'      => '',
			'operation'  => 'chat',
			'tokens_in'  => 0,
			'tokens_out' => 0,
			'latency_ms' => 0,
			'ok'         => 1,
			'error_code' => '',
			'user_id'    => get_current_user_id(),
		);
		$row = array_intersect_key( array_merge( $defaults, (array) $data ), $defaults );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->insert(
			Haa_DB::table( 'usage' ),
			array(
				'module'     => substr( (string) $row['module'], 0, 40 ),
				'model'      => substr( (string) $row['model'], 0, 120 ),
				'operation'  => substr( (string) $row['operation'], 0, 30 ),
				'tokens_in'  => max( 0, (int) $row['tokens_in'] ),
				'tokens_out' => max( 0, (int) $row['tokens_out'] ),
				'latency_ms' => max( 0, (int) $row['latency_ms'] ),
				'ok'         => empty( $row['ok'] ) ? 0 : 1,
				'error_code' => substr( (string) $row['error_code'], 0, 60 ),
				'user_id'    => (int) $row['user_id'],
				'created'    => current_time( 'mysql', true ),
			),
			array( '%s', '%s', '%s', '%d', '%d', '%d', '%d', '%s', '%d', '%s' )
		);
	}

	/**
	 * Aggregate for the dashboard.
	 *
	 * @param int $days Window length.
	 * @return array{calls:int,tokens:int,tokens_in:int,tokens_out:int,errors:int,by_day:array,by_module:array,by_model:array}
	 */
	public static function summary( $days = 30 ) {
		global $wpdb;
		$table  = Haa_DB::table( 'usage' );
		$since  = gmdate( 'Y-m-d H:i:s', time() - ( max( 1, (int) $days ) * DAY_IN_SECONDS ) );
		$out    = array(
			'calls'      => 0,
			'tokens'     => 0,
			'tokens_in'  => 0,
			'tokens_out' => 0,
			'errors'     => 0,
			'by_day'     => array(),
			'by_module'  => array(),
			'by_model'   => array(),
		);

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$totals = $wpdb->get_row( $wpdb->prepare( "SELECT COUNT(*) AS calls, COALESCE(SUM(tokens_in),0) AS tin, COALESCE(SUM(tokens_out),0) AS tout, COALESCE(SUM(ok),0) AS okn FROM $table WHERE created >= %s", $since ), ARRAY_A );
		if ( $totals ) {
			$out['calls']      = (int) $totals['calls'];
			$out['tokens_in']  = (int) $totals['tin'];
			$out['tokens_out'] = (int) $totals['tout'];
			$out['tokens']     = (int) $totals['tin'] + (int) $totals['tout'];
			$out['errors']     = max( 0, (int) $totals['calls'] - (int) $totals['okn'] );
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$days_rows = $wpdb->get_results( $wpdb->prepare( "SELECT DATE(created) AS d, COUNT(*) AS c, COALESCE(SUM(tokens_in+tokens_out),0) AS t FROM $table WHERE created >= %s GROUP BY DATE(created) ORDER BY d ASC LIMIT 60", $since ), ARRAY_A );
		foreach ( (array) $days_rows as $r ) {
			$out['by_day'][ $r['d'] ] = array( 'calls' => (int) $r['c'], 'tokens' => (int) $r['t'] );
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$mod_rows = $wpdb->get_results( $wpdb->prepare( "SELECT module, COUNT(*) AS c, COALESCE(SUM(tokens_in+tokens_out),0) AS t FROM $table WHERE created >= %s GROUP BY module ORDER BY c DESC LIMIT 20", $since ), ARRAY_A );
		foreach ( (array) $mod_rows as $r ) {
			$out['by_module'][ $r['module'] ] = array( 'calls' => (int) $r['c'], 'tokens' => (int) $r['t'] );
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$model_rows = $wpdb->get_results( $wpdb->prepare( "SELECT model, COUNT(*) AS c, COALESCE(SUM(tokens_in+tokens_out),0) AS t FROM $table WHERE created >= %s GROUP BY model ORDER BY c DESC LIMIT 20", $since ), ARRAY_A );
		foreach ( (array) $model_rows as $r ) {
			$out['by_model'][ $r['model'] ] = array( 'calls' => (int) $r['c'], 'tokens' => (int) $r['t'] );
		}

		return $out;
	}

	/**
	 * Today's totals for the dashboard cards: all calls, chat messages only,
	 * and total tokens — one query.
	 *
	 * "Today" is the site-local day, converted to UTC.
	 *
	 * @return array{calls:int,messages:int,tokens:int}
	 */
	public static function today() {
		global $wpdb;
		$table = Haa_DB::table( 'usage' );
		$since = self::site_day_start_utc();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT COUNT(*) AS calls,
					COALESCE(SUM(tokens_in+tokens_out),0) AS tokens,
					COALESCE(SUM(CASE WHEN operation = 'chat' THEN 1 ELSE 0 END),0) AS messages
				FROM $table WHERE created >= %s",
				$since
			),
			ARRAY_A
		);

		return array(
			'calls'    => $row ? (int) $row['calls'] : 0,
			'messages' => $row ? (int) $row['messages'] : 0,
			'tokens'   => $row ? (int) $row['tokens'] : 0,
		);
	}

	/**
	 * Tokens burned today — used by the autopilot daily cap.
	 *
	 * Timezone contract: `created` is stored as UTC via current_time('mysql', true).
	 * "Today" means the current day as seen in the site's timezone, converted
	 * to a UTC boundary. Using gmdate('Y-m-d 00:00:00') would mean UTC midnight
	 * which drifts against the site timezone (e.g. 03:30 Tehran).
	 *
	 * @return int
	 */
	public static function tokens_today() {
		global $wpdb;
		$table = Haa_DB::table( 'usage' );
		$since = self::site_day_start_utc();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(tokens_in+tokens_out),0) FROM $table WHERE created >= %s", $since ) );
	}

	/**
	 * Posts created today by the autopilot.
	 *
	 * "Today" is the site-local day, converted to UTC (same contract as
	 * tokens_today()).
	 *
	 * @return int
	 */
	public static function autopilot_posts_today() {
		global $wpdb;
		$table = Haa_DB::table( 'campaigns' );
		if ( ! Haa_Settings::is_module_enabled( 'autopilot' ) ) {
			return 0;
		}
		$since = self::site_day_start_utc();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(posts_done),0) FROM $table WHERE last_run >= %s", $since ) );
	}

	/**
	 * Posts the plugin itself wrote today, whatever the module that wrote them.
	 *
	 * The article planner's daily cap has to count drafts from the manual
	 * article screen, the planner queue and autopilot together; counting only its
	 * own table would let each of them spend the allowance again.
	 *
	 * @return int
	 */
	public static function generated_posts_today() {
		global $wpdb;

		$posts = $wpdb->posts;
		$meta  = $wpdb->postmeta;
		$since = self::site_day_start_utc();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM $posts p INNER JOIN $meta m ON m.post_id = p.ID AND m.meta_key = '_haai_generated' AND m.meta_value = '1' WHERE p.post_type = 'post' AND p.post_date_gmt >= %s",
				$since
			)
		);
	}

	/**
	 * UTC datetime for midnight of the current site-local day.
	 *
	 * This is the single source of truth for "today" in every daily-cap
	 * check. The site timezone comes from wp_timezone() (Settings → General).
	 *
	 * @return string MySQL datetime in UTC.
	 */
	public static function site_day_start_utc() {
		$site_tz  = wp_timezone();
		$midnight = new DateTimeImmutable( 'today', $site_tz );
		return $midnight->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
	}

	/**
	 * Seconds until the next site-local midnight (the day boundary used by
	 * every daily-cap window). Always at least 60 so a transient never gets
	 * a zero or negative lifetime.
	 *
	 * @return int
	 */
	public static function seconds_until_site_day_end() {
		$site_tz  = wp_timezone();
		$next_day = new DateTimeImmutable( 'tomorrow', $site_tz );
		$end_utc  = $next_day->setTimezone( new DateTimeZone( 'UTC' ) );
		return max( 60, $end_utc->getTimestamp() - time() + 60 );
	}

	/**
	 * Stable per-day marker for "today" in the site timezone. Used as part of
	 * transient keys so a daily counter can never be read across a day
	 * boundary, even if its TTL were somehow stretched.
	 *
	 * @return string Y-m-d
	 */
	public static function site_day_key() {
		$site_tz = wp_timezone();
		return ( new DateTimeImmutable( 'today', $site_tz ) )->format( 'Y-m-d' );
	}
}
