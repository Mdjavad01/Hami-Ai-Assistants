<?php
/**
 * Fired when the plugin is deleted from the Plugins screen.
 * Removes every option, table, cron event and plugin-owned upload.
 *
 * The plugin's own classes are NOT loaded here (WordPress only includes this
 * file), so everything is self-contained.
 *
 * @package Hami_AI_Assistant
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

/* -------------------------------------------------------------------------
 * 1. Options
 * ---------------------------------------------------------------------- */

// Read the settings FIRST: the avatar id lives there and cannot be re-read
// after the option is gone (this block used to run in the reverse order, so
// the uploaded icon was never actually removed).
$settings = get_option( 'hami_ai_assistant', array() );

delete_option( 'hami_ai_assistant' );        // Big settings array (autoload = no).
delete_option( 'hami_ai_assistant_on' );     // Tiny on/off gate (autoload = yes).
delete_option( 'hami_ai_assistant_modules' );// Module toggles (autoload = yes).
delete_option( 'hami_ai_assistant_snapshot' );// Pre-reset/import copy (autoload = no).
delete_option( 'haai_db_version' );          // dbDelta guard.
delete_option( 'haai_migrated' );            // v1 -> v2 migration flag.
delete_option( 'haai_queue_lock' );          // Queue mutex.
delete_option( 'hami_ai_kb_state' );         // Rebuild cursor (legacy name kept).
delete_option( 'hami_ai_crawl_state' );      // Site-knowledge crawler cursor.
delete_option( 'haai_profile_draft' );       // Unapproved site-profile draft.
delete_option( 'haai_kw_gsc' );              // Stored Search-Console opportunities.

// Legacy / non-prefixed leftovers, if any.
delete_option( 'hami_ai_settings' );
delete_option( 'hami_ai_settings_active' );
delete_option( 'hami_ai_chatbot_settings' );

/* -------------------------------------------------------------------------
 * 2. Cron
 * ---------------------------------------------------------------------- */

wp_clear_scheduled_hook( 'haai_daily_cleanup' );
wp_clear_scheduled_hook( 'haai_autopilot_tick' );
wp_clear_scheduled_hook( 'haai_aw_tick' ); // Article-writer five-minute batch.
wp_clear_scheduled_hook( 'haai_queue_tick' ); // Core queue drain.

/* -------------------------------------------------------------------------
 * 3. Plugin-owned uploads
 *
 * Only media created by us is removed, and only when it has no parent post —
 * media that belongs to real content is never touched.
 * ---------------------------------------------------------------------- */

// Chat avatar (captured above, before the option was deleted).
if ( is_array( $settings ) && ! empty( $settings['appearance']['custom_icon'] ) ) {
	$attachment_id = (int) $settings['appearance']['custom_icon'];
	if ( $attachment_id && function_exists( 'wp_delete_attachment' ) ) {
		$attachment = get_post( $attachment_id );
		if ( $attachment && 'attachment' === $attachment->post_type && 0 === (int) $attachment->post_parent ) {
			wp_delete_attachment( $attachment_id, true );
		}
	}
}

// AI-generated product images (marked with _haai_generated and no parent).
// Batched: the parent filter lives IN the query, so every returned row is
// deleted on that pass and the loop always terminates — a large library
// never has to fit in memory at once.
if ( function_exists( 'wp_delete_attachment' ) ) {
	global $wpdb;
	for ( $pass = 0; $pass < 1000; $pass++ ) {
		// phpcs:ignore WordPress.DB
		$ai_images = $wpdb->get_col(
			"SELECT p.ID FROM {$wpdb->posts} p
			INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID
			WHERE p.post_type = 'attachment' AND p.post_parent = 0
				AND m.meta_key = '_haai_generated' AND m.meta_value = '1'
			LIMIT 200"
		);
		if ( ! $ai_images ) {
			break;
		}
		foreach ( (array) $ai_images as $img_id ) {
			wp_delete_attachment( (int) $img_id, true );
		}
	}
}

/* -------------------------------------------------------------------------
 * 4. Custom tables
 *
 * Built from the live prefix (including custom prefixes) without relying on
 * the plugin autoloader.
 * ---------------------------------------------------------------------- */

global $wpdb;

if ( isset( $wpdb->prefix ) && '' !== $wpdb->prefix ) {
	$tables = array(
		'chunks',
		'cache',
		'logs',
		'rate',
		'usage',
		'queue',
		'sessions',
		'vectors',
		'leads',
		'lead_events',
		'coupons',
		'campaigns',
		'comment_jobs',
		'topics',
		'search_misses',
		'news_items',
		'prog_items',
	);
	foreach ( $tables as $t ) {
		// phpcs:ignore WordPress.DB
		$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . 'haai_' . $t ); // phpcs:ignore WordPress.DB
	}
}

/* -------------------------------------------------------------------------
 * 5. Transients / object cache
 * ---------------------------------------------------------------------- */

if ( function_exists( 'delete_transient' ) ) {
	delete_transient( 'haai_autopilot_lock' );
	delete_transient( 'haai_import_pending' );  // Half-finished import confirm (A2).
	// Model-list transients are keyed by provider slug; clear every one we
	// may have written without knowing the slugs.
	global $wpdb;
	if ( isset( $wpdb->prefix ) && '' !== $wpdb->prefix ) {
		// phpcs:ignore WordPress.DB
		$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_haai_models_%' OR option_name LIKE '_transient_timeout_haai_models_%'" ); // phpcs:ignore WordPress.DB
	}
}

wp_cache_flush();
