<?php
/**
 * Schema and low-level table helpers.
 *
 * Every table is created only when the module that owns it is switched on, so
 * a chatbot-only install never carries seven unused tables.
 *
 * @package Hami_AI_Assistant
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Haa_DB {

	/** @var array<string,string> Short key => table name. */
	private static $names = array();

	/**
	 * Full table name for a short key.
	 *
	 * @param string $key Table key.
	 * @return string
	 */
	public static function table( $key ) {
		if ( ! isset( self::$names[ $key ] ) ) {
			global $wpdb;
			self::$names[ $key ] = $wpdb->prefix . 'haai_' . $key;
		}
		return self::$names[ $key ];
	}

	/**
	 * Which modules own a table. 'core' is always created.
	 *
	 * @return array<string,string> table key => owning module slug.
	 */
	public static function registry() {
		return array(
			'chunks'         => 'core',
			'cache'          => 'core',
			'logs'           => 'core',
			'rate'           => 'core',
			'usage'          => 'core',
			'queue'          => 'core',
			'sessions'       => 'chatbot',
			'vectors'        => 'semantic-search',
			'leads'          => 'leads',
			'lead_events'    => 'leads',
			'coupons'        => 'discount',
			'campaigns'      => 'autopilot',
			'comment_jobs'   => 'comments',
			'topics'         => 'article-writer',
			'news_items'     => 'news',
			'prog_items'     => 'programmatic',
		);
	}

	/**
	 * Tables that must exist right now (core + every enabled module).
	 *
	 * @return array<int,string>
	 */
	public static function required_tables() {
		$enabled = Haa_Settings::enabled_modules();
		$out     = array();
		foreach ( self::registry() as $key => $owner ) {
			if ( 'core' === $owner || ! empty( $enabled[ $owner ] ) ) {
				$out[] = $key;
			}
		}
		return $out;
	}

	/**
	 * Cheap safety net: re-run the guarded dbDelta after an upgrade or when a
	 * host has quietly dropped a table.
	 *
	 * Cost is one prefix-wide SHOW TABLES, never one query per table — and zero
	 * in visitor requests, which must not pay for schema bookkeeping (M9).
	 */
	public static function ensure() {
		if ( ! is_admin() && ! wp_doing_cron() ) {
			return;
		}

		$version = (string) get_option( 'haai_db_version', '0' );
		if ( HAAI_DB_VERSION === $version ) {
			// Still make sure a module switched on since the last request has
			// its tables (this costs one option read we already paid for).
			global $wpdb;
			$prefix  = $wpdb->prefix . 'haai';
			$present = array_map(
				'strval',
				(array) $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $prefix ) . '%' ) )
			);
			$missing = false;
			foreach ( self::required_tables() as $key ) {
				if ( ! in_array( self::table( $key ), $present, true ) ) {
					$missing = true;
					break;
				}
			}
			if ( ! $missing ) {
				return;
			}
		}
		self::create();
	}

	/**
	 * Create every required table (dbDelta) and bump the schema version.
	 */
	public static function create() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset = $wpdb->get_charset_collate();
		$defs    = self::definitions();
		$need    = self::required_tables();

		foreach ( $need as $key ) {
			if ( ! isset( $defs[ $key ] ) ) {
				continue;
			}
			dbDelta( 'CREATE TABLE ' . self::table( $key ) . ' ' . $defs[ $key ] . ' ' . $charset . ';' );
		}

		$old_version = (int) get_option( 'haai_db_version', '0' );
		if ( $old_version < 3 ) {
			self::migrate_to_v3();
		}
		if ( $old_version < 4 ) {
			self::migrate_to_v4();
		}

		update_option( 'haai_db_version', HAAI_DB_VERSION );
	}

	/**
	 * Migration: add missing indexes to existing tables (dbDelta misses some).
	 */
	private static function migrate_to_v3() {
		global $wpdb;

		$indexes = array(
			'logs'      => array( 'user_id' ),
			'leads'     => array( 'email', 'updated' ),
			'coupons'   => array( 'lead_id', 'expires' ),
			'sessions'  => array( 'updated' ),
			'queue'     => array( 'updated', 'job' ),
			'campaigns' => array( 'last_run' ),
			'chunks'    => array( 'date_updated' ),
		);

		foreach ( $indexes as $table_key => $columns ) {
			$table = self::table( $table_key );
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$existing = $wpdb->get_col( "SHOW INDEX FROM $table" );
			foreach ( $columns as $col ) {
				$index_name = $col;
				if ( in_array( $index_name, $existing, true ) ) {
					continue;
				}
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$wpdb->query( "ALTER TABLE $table ADD INDEX $index_name ($col)" );
			}
		}
	}

	/**
	 * Migration v4: add missing comment_jobs columns (idempotent).
	 *
	 * The original schema lacked comment_id, post_type, draft, author_name,
	 * author_email, comment_status and updated. dbDelta does not reliably add
	 * columns to an existing table, so we ALTER manually and only when missing.
	 */
	private static function migrate_to_v4() {
		global $wpdb;

		$table = self::table( 'comment_jobs' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
		if ( ! $exists ) {
			return;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$cols = $wpdb->get_col( "DESCRIBE $table" );
		$cols = array_map( 'strtolower', (array) $cols );

		$add = array(
			'comment_id'     => 'BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER id',
			'post_type'      => "VARCHAR(40) NOT NULL DEFAULT 'post' AFTER post_id",
			'draft'          => 'LONGTEXT NULL AFTER kind',
			'author_name'    => "VARCHAR(190) NOT NULL DEFAULT '' AFTER draft",
			'author_email'   => "VARCHAR(190) NOT NULL DEFAULT '' AFTER author_name",
			'comment_status' => "VARCHAR(20) NOT NULL DEFAULT 'pending' AFTER author_email",
			'updated'        => 'DATETIME NULL AFTER created',
		);

		foreach ( $add as $col => $definition ) {
			if ( in_array( $col, $cols, true ) ) {
				continue;
			}
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( "ALTER TABLE $table ADD COLUMN $col $definition" );
		}

		// Add comment_id index if missing.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$indexes = $wpdb->get_col( "SHOW INDEX FROM $table" );
		if ( ! in_array( 'comment_id', $indexes, true ) ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( "ALTER TABLE $table ADD INDEX comment_id (comment_id)" );
		}
	}

	/**
	 * CREATE TABLE bodies (no prefix, no charset).
	 *
	 * @return array<string,string>
	 */
	private static function definitions() {
		$d = array();

		// Knowledge-base chunks.
		$d['chunks'] = "(
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			post_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			source VARCHAR(40) NOT NULL DEFAULT 'post',
			title VARCHAR(255) NOT NULL DEFAULT '',
			url VARCHAR(255) NOT NULL DEFAULT '',
			chunk_index SMALLINT UNSIGNED NOT NULL DEFAULT 0,
			content LONGTEXT NOT NULL,
			content_norm LONGTEXT NOT NULL,
			meta LONGTEXT NULL,
			date_updated DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY post_id (post_id),
			KEY source (source(20)),
			KEY date_updated (date_updated)
		)";

		// Embedding vectors (semantic-search module). float16-ish payload.
		$d['vectors'] = "(
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			chunk_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			post_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			model VARCHAR(80) NOT NULL DEFAULT '',
			dim SMALLINT UNSIGNED NOT NULL DEFAULT 0,
			vector LONGTEXT NOT NULL,
			created DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY chunk_model (chunk_id, model(40)),
			KEY post_id (post_id)
		)";

		// Chat response cache.
		$d['cache'] = "(
			cache_key CHAR(64) NOT NULL,
			response LONGTEXT NOT NULL,
			expires DATETIME NOT NULL,
			created DATETIME NOT NULL,
			PRIMARY KEY  (cache_key),
			KEY expires (expires)
		)";

		// Conversation logs.
		$d['logs'] = "(
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			session_id VARCHAR(64) NOT NULL DEFAULT '',
			user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			ip VARCHAR(100) NOT NULL DEFAULT '',
			role VARCHAR(40) NOT NULL DEFAULT 'guest',
			message LONGTEXT NOT NULL,
			response LONGTEXT NOT NULL,
			provider VARCHAR(40) NOT NULL DEFAULT '',
			model VARCHAR(80) NOT NULL DEFAULT '',
			status VARCHAR(20) NOT NULL DEFAULT 'ok',
			tokens INT UNSIGNED NOT NULL DEFAULT 0,
			created DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY session_id (session_id(20)),
			KEY created (created),
			KEY user_id (user_id)
		)";

		// Fixed-window rate limiting.
		$d['rate'] = "(
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			identifier VARCHAR(64) NOT NULL DEFAULT '',
			bucket INT UNSIGNED NOT NULL DEFAULT 0,
			hits INT UNSIGNED NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			UNIQUE KEY identifier (identifier)
		)";

		// AI usage log — powers the dashboard.
		$d['usage'] = "(
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			module VARCHAR(40) NOT NULL DEFAULT '',
			model VARCHAR(120) NOT NULL DEFAULT '',
			operation VARCHAR(30) NOT NULL DEFAULT 'chat',
			tokens_in INT UNSIGNED NOT NULL DEFAULT 0,
			tokens_out INT UNSIGNED NOT NULL DEFAULT 0,
			latency_ms INT UNSIGNED NOT NULL DEFAULT 0,
			ok TINYINT(1) NOT NULL DEFAULT 1,
			error_code VARCHAR(60) NOT NULL DEFAULT '',
			user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			created DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY created (created),
			KEY module (module(20)),
			KEY model (model(40))
		)";

		// Generic background job queue (autopilot, comments, embeddings, images).
		$d['queue'] = "(
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			job VARCHAR(60) NOT NULL DEFAULT '',
			module VARCHAR(40) NOT NULL DEFAULT '',
			payload LONGTEXT NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'pending',
			priority INT NOT NULL DEFAULT 10,
			attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
			max_attempts TINYINT UNSIGNED NOT NULL DEFAULT 3,
			locked_until DATETIME NULL,
			last_error TEXT NULL,
			created DATETIME NOT NULL,
			updated DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY status_priority (status, priority),
			KEY locked_until (locked_until),
			KEY module (module(20)),
			KEY updated (updated),
			KEY job (job(30))
		)";

		// Saved chat conversations (chatbot module).
		$d['sessions'] = "(
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			sid VARCHAR(64) NOT NULL DEFAULT '',
			user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			lead_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			title VARCHAR(190) NOT NULL DEFAULT '',
			messages LONGTEXT NOT NULL,
			message_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
			created DATETIME NOT NULL,
			updated DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY sid (sid),
			KEY user_id (user_id),
			KEY lead_id (lead_id),
			KEY updated (updated)
		)";

		// Leads.
		$d['leads'] = "(
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			name VARCHAR(190) NOT NULL DEFAULT '',
			phone VARCHAR(40) NOT NULL DEFAULT '',
			email VARCHAR(190) NOT NULL DEFAULT '',
			lead_type VARCHAR(40) NOT NULL DEFAULT 'chat',
			related_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			related_title VARCHAR(190) NOT NULL DEFAULT '',
			source VARCHAR(40) NOT NULL DEFAULT 'chatbot',
			stage VARCHAR(40) NOT NULL DEFAULT 'new',
			temperature VARCHAR(20) NOT NULL DEFAULT 'cold',
			score SMALLINT UNSIGNED NOT NULL DEFAULT 0,
			summary TEXT NULL,
			summary_label VARCHAR(60) NOT NULL DEFAULT '',
			user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			owner_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			ip VARCHAR(100) NOT NULL DEFAULT '',
			next_followup DATETIME NULL,
			last_activity DATETIME NULL,
			consent_text TEXT NULL,
			consent_at DATETIME NULL,
			created DATETIME NOT NULL,
			updated DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY phone (phone(20)),
			KEY stage (stage(20)),
			KEY score (score),
			KEY created (created),
			KEY owner_id (owner_id),
			KEY email (email(40)),
			KEY updated (updated)
		)";

		$d['lead_events'] = "(
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			lead_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			event_type VARCHAR(40) NOT NULL DEFAULT 'note',
			score_delta SMALLINT NOT NULL DEFAULT 0,
			content TEXT NULL,
			user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			created DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY lead_id (lead_id),
			KEY created (created)
		)";

		// Coupons issued by the discount module.
		$d['coupons'] = "(
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			code VARCHAR(60) NOT NULL DEFAULT '',
			percent SMALLINT UNSIGNED NOT NULL DEFAULT 0,
			product_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			category_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			lead_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			identifier VARCHAR(64) NOT NULL DEFAULT '',
			uses_allowed SMALLINT UNSIGNED NOT NULL DEFAULT 1,
			uses_used SMALLINT UNSIGNED NOT NULL DEFAULT 0,
			expires DATETIME NOT NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'active',
			created DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY code (code),
			KEY identifier (identifier(20)),
			KEY status (status(20)),
			KEY lead_id (lead_id),
			KEY expires (expires)
		)";

		// Proposed subjects awaiting a human decision (article-writer module).
		// Titles are stored, not hashes, because the admin edits them; the
		// keyword column is prefixed-index sized so MySQL can use it.
		$d['topics'] = "(
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			title VARCHAR(255) NOT NULL DEFAULT '',
			keyword VARCHAR(160) NOT NULL DEFAULT '',
			secondary VARCHAR(255) NOT NULL DEFAULT '',
			intent VARCHAR(20) NOT NULL DEFAULT 'informational',
			priority TINYINT UNSIGNED NOT NULL DEFAULT 5,
			reason TEXT NULL,
			source VARCHAR(20) NOT NULL DEFAULT 'manual',
			status VARCHAR(20) NOT NULL DEFAULT 'suggested',
			post_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			job_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			tokens_est INT UNSIGNED NOT NULL DEFAULT 0,
			brief LONGTEXT NULL,
			stage VARCHAR(20) NOT NULL DEFAULT '',
			notes LONGTEXT NULL,
			error TEXT NULL,
			created DATETIME NOT NULL,
			updated DATETIME NULL,
			PRIMARY KEY  (id),
			KEY status (status),
			KEY keyword (keyword(100)),
			KEY post_id (post_id),
			KEY updated (updated)
		)";

		// News: one row per feed item ever seen. The guid stops the same story
		// being rewritten every hour; title_hash catches feeds that mint a fresh
		// guid for an edited item.
		$d['news_items'] = "(
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			feed VARCHAR(190) NOT NULL DEFAULT '',
			guid VARCHAR(190) NOT NULL DEFAULT '',
			title_hash CHAR(32) NOT NULL DEFAULT '',
			title VARCHAR(255) NOT NULL DEFAULT '',
			link VARCHAR(600) NOT NULL DEFAULT '',
			source_name VARCHAR(120) NOT NULL DEFAULT '',
			published DATETIME NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'new',
			post_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			created DATETIME NOT NULL,
			updated DATETIME NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY guid (guid(150)),
			KEY title_hash (title_hash),
			KEY status (status),
			KEY post_id (post_id)
		)";

		// Programmatic pages: one row per data item, so a re-run updates the page it
		// already made instead of printing a second copy of the same subject.
		$d['prog_items'] = "(
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			job_key VARCHAR(64) NOT NULL DEFAULT '',
			item_key VARCHAR(190) NOT NULL DEFAULT '',
			vars LONGTEXT NULL,
			post_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			status VARCHAR(20) NOT NULL DEFAULT 'new',
			error TEXT NULL,
			created DATETIME NOT NULL,
			updated DATETIME NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY item (job_key, item_key(150)),
			KEY post_id (post_id),
			KEY status (status)
		)";

		// Autopilot campaigns.
		$d['campaigns'] = "(
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			name VARCHAR(190) NOT NULL DEFAULT '',
			topic VARCHAR(190) NOT NULL DEFAULT '',
			audience VARCHAR(190) NOT NULL DEFAULT '',
			goal VARCHAR(190) NOT NULL DEFAULT '',
			keywords TEXT NULL,
			tone VARCHAR(40) NOT NULL DEFAULT 'friendly',
			language VARCHAR(10) NOT NULL DEFAULT 'fa',
			length_slug VARCHAR(20) NOT NULL DEFAULT 'medium',
			category_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			status_out VARCHAR(20) NOT NULL DEFAULT 'draft',
			with_image TINYINT(1) NOT NULL DEFAULT 0,
			frequency VARCHAR(20) NOT NULL DEFAULT 'daily',
			run_at VARCHAR(20) NOT NULL DEFAULT '06:00',
			per_run SMALLINT UNSIGNED NOT NULL DEFAULT 1,
			posts_done INT UNSIGNED NOT NULL DEFAULT 0,
			tokens_used INT UNSIGNED NOT NULL DEFAULT 0,
			last_run DATETIME NULL,
			next_run DATETIME NULL,
			state TEXT NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'active',
			created DATETIME NOT NULL,
			updated DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY status (status(20)),
			KEY next_run (next_run),
			KEY last_run (last_run)
		)";

		// Queued comment jobs (comments module).
		$d['comment_jobs'] = "(
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			comment_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			post_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			post_type VARCHAR(40) NOT NULL DEFAULT 'post',
			kind VARCHAR(30) NOT NULL DEFAULT 'question',
			draft LONGTEXT NULL,
			author_name VARCHAR(190) NOT NULL DEFAULT '',
			author_email VARCHAR(190) NOT NULL DEFAULT '',
			comment_status VARCHAR(20) NOT NULL DEFAULT 'pending',
			status VARCHAR(20) NOT NULL DEFAULT 'pending',
			created DATETIME NOT NULL,
			updated DATETIME NULL,
			PRIMARY KEY  (id),
			KEY comment_id (comment_id),
			KEY post_status (post_id, status),
			KEY status (status(20))
		)";

		return $d;
	}

	/* ------------------------------------------------------------------
	 * Generic helpers
	 * ---------------------------------------------------------------- */

	/**
	 * Delete expired cache rows. Limited to 1000 rows per call so a large
	 * table cannot blow the 30-second WP-Cron budget.
	 *
	 * @return int Rows removed.
	 */
	public static function clear_expired_cache() {
		global $wpdb;
		$table = self::table( 'cache' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$n = (int) $wpdb->query( $wpdb->prepare( "DELETE FROM $table WHERE expires < %s LIMIT 1000", gmdate( 'Y-m-d H:i:s' ) ) );
		return $n;
	}

	/**
	 * @param int $days Retention window.
	 * @return int
	 */
	public static function clear_old_logs( $days = 30 ) {
		global $wpdb;
		$table  = self::table( 'logs' );
		$before = gmdate( 'Y-m-d H:i:s', time() - ( max( 1, (int) $days ) * DAY_IN_SECONDS ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->query( $wpdb->prepare( "DELETE FROM $table WHERE created < %s LIMIT 1000", $before ) );
	}

	/**
	 * @param int $days Retention window.
	 * @return int
	 */
	public static function clear_old_usage( $days = 90 ) {
		global $wpdb;
		$table  = self::table( 'usage' );
		$before = gmdate( 'Y-m-d H:i:s', time() - ( max( 1, (int) $days ) * DAY_IN_SECONDS ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->query( $wpdb->prepare( "DELETE FROM $table WHERE created < %s LIMIT 1000", $before ) );
	}

	/**
	 * @param int $minutes Window length.
	 * @return int
	 */
	public static function clear_stale_rate( $minutes = 60 ) {
		global $wpdb;
		$table = self::table( 'rate' );
		$limit = (int) floor( time() / 60 ) - max( 1, (int) $minutes );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->query( $wpdb->prepare( "DELETE FROM $table WHERE bucket < %d LIMIT 1000", $limit ) );
	}

	/**
	 * Full cleanup: logs, usage, cache, rate buckets, expired coupons.
	 * Runs from the daily cron (haai_daily_cleanup) only. Each DELETE is
	 * limited to 1000 rows to stay inside the WP-Cron time budget; the
	 * next cron run picks up any remaining rows. A transient lock prevents
	 * concurrent runs if cron fires twice.
	 */
	public static function cleanup_all() {
		// Simple lock: skip if a cleanup ran in the last hour.
		if ( get_transient( 'haai_cleanup_lock' ) ) {
			return;
		}
		set_transient( 'haai_cleanup_lock', 1, HOUR_IN_SECONDS );

		$s      = Haa_Settings::get();
		$days   = (int) $s['advanced']['log_retention_days'];
		$rate   = (int) $s['advanced']['rate_limit_minutes'];
		$usage  = (int) $s['advanced']['usage_retention'];

		self::clear_old_logs( $days );
		self::clear_old_usage( $usage );
		self::clear_expired_cache();
		self::clear_stale_rate( $rate );

		if ( Haa_Settings::is_module_enabled( 'discount' ) ) {
			self::expire_old_coupons();
		}
		if ( Haa_Settings::is_module_enabled( 'leads' ) ) {
			self::purge_old_leads( (int) $s['leads']['retention_days'] );
		}
		if ( Haa_Settings::is_module_enabled( 'chatbot' ) ) {
			self::purge_old_sessions( (int) $s['chat']['history_retention'] );
		}

		// Finished and failed jobs are history; keep a week for the dashboard.
		Haa_Queue::purge( 7 );
	}

	/**
	 * Mark coupons past their expiry as expired. Limited to 500 rows.
	 *
	 * @return int
	 */
	public static function expire_old_coupons() {
		global $wpdb;
		$table = self::table( 'coupons' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->query( $wpdb->prepare( "UPDATE $table SET status='expired' WHERE status='active' AND expires < %s LIMIT 500", gmdate( 'Y-m-d H:i:s' ) ) );
	}

	/**
	 * @param int $days Retention.
	 * @return int
	 */
	public static function purge_old_leads( $days = 365 ) {
		if ( ! Haa_Settings::is_module_enabled( 'leads' ) ) {
			return 0;
		}
		global $wpdb;
		$table  = self::table( 'leads' );
		$before = gmdate( 'Y-m-d H:i:s', time() - ( max( 7, (int) $days ) * DAY_IN_SECONDS ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( $wpdb->prepare( "DELETE FROM " . self::table( 'lead_events' ) . " WHERE lead_id IN (SELECT id FROM $table WHERE updated < %s AND stage NOT IN ('won') LIMIT 500)", $before ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$n = (int) $wpdb->query( $wpdb->prepare( "DELETE FROM $table WHERE updated < %s AND stage NOT IN ('won') LIMIT 500", $before ) );
		return $n;
	}

	/**
	 * @param int $days Retention.
	 * @return int
	 */
	public static function purge_old_sessions( $days = 30 ) {
		global $wpdb;
		$table  = self::table( 'sessions' );
		$before = gmdate( 'Y-m-d H:i:s', time() - ( max( 1, (int) $days ) * DAY_IN_SECONDS ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->query( $wpdb->prepare( "DELETE FROM $table WHERE updated < %s LIMIT 1000", $before ) );
	}

	/* ------------------------------------------------------------------
	 * Cache
	 * ---------------------------------------------------------------- */

	/**
	 * @param string $key Cache key.
	 * @return string|null
	 */
	public static function get_cached( $key ) {
		global $wpdb;
		$table = self::table( 'cache' );
		$row   = $wpdb->get_row(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->prepare( "SELECT response FROM $table WHERE cache_key = %s AND expires > %s LIMIT 1", $key, gmdate( 'Y-m-d H:i:s' ) ),
			ARRAY_A
		);
		return $row ? (string) $row['response'] : null;
	}

	/**
	 * @param string $key      Cache key.
	 * @param string $response Payload.
	 * @param int    $ttl      Seconds.
	 */
	public static function set_cached( $key, $response, $ttl = 3600 ) {
		global $wpdb;
		$table = self::table( 'cache' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->replace(
			$table,
			array(
				'cache_key' => substr( $key, 0, 64 ),
				'response'  => $response,
				'expires'   => gmdate( 'Y-m-d H:i:s', time() + max( 60, (int) $ttl ) ),
				'created'   => current_time( 'mysql', true ),
			),
			array( '%s', '%s', '%s', '%s' )
		);
	}

	/**
	 * Wipe the whole chat answer cache.
	 */
	public static function flush_cache() {
		global $wpdb;
		$table = self::table( 'cache' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "TRUNCATE TABLE $table" );
	}

	/* ------------------------------------------------------------------
	 * Rate limiting
	 * ---------------------------------------------------------------- */

	/**
	 * Increment the hit counter for the current fixed window.
	 *
	 * @param string $identifier Identity.
	 * @param int    $bucket     Window bucket.
	 * @return int
	 */
	public static function bump_rate( $identifier, $bucket ) {
		global $wpdb;
		$table      = self::table( 'rate' );
		$identifier = substr( (string) $identifier, 0, 64 );
		$bucket     = (int) $bucket;

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO $table (identifier, bucket, hits) VALUES (%s, %d, 1)
				 ON DUPLICATE KEY UPDATE hits = IF(bucket = %d, hits + 1, 1), bucket = %d",
				$identifier,
				$bucket,
				$bucket,
				$bucket
			)
		);

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT hits FROM $table WHERE identifier = %s", $identifier ) );
	}
}
