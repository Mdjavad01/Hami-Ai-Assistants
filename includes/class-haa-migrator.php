<?php
/**
 * One-shot migration from Hami-Ai-Chatbot v1.
 *
 * Runs on activation and again on `init` (so an update that never re-activated
 * still migrates). Nothing is deleted; the old option and tables stay put until
 * the admin removes v1 or runs uninstall.php on it.
 *
 * @package Hami_AI_Assistant
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Haa_Migrator {

	const STATE_OPTION = 'haai_migrated';

	/**
	 * Migrate once, keyed by the stored source version.
	 */
	public static function maybe_migrate() {
		$done = get_option( self::STATE_OPTION, null );
		if ( is_array( $done ) && isset( $done['v'] ) && version_compare( (string) $done['v'], HAAI_VERSION, '>=' ) ) {
			return;
		}

		$migrated = array( 'v' => HAAI_VERSION, 'from' => '' );

		// 1. Settings: v1 is only a seed for a fresh install. A site that
		//    already has its own v2 option keeps it untouched — overwriting it
		//    with the v1 mapping would reset everything the admin saved.
		$old    = get_option( 'hami_ai_settings', null );
		$seeded = false;
		if ( is_array( $old ) ) {
			if ( null === get_option( HAAI_OPTION, null ) ) {
				add_option( HAAI_OPTION, self::map_settings( $old ), '', 'no' );
				$seeded           = true;
				$migrated['from'] = 'chatbot';
			} else {
				// v2 already owns the settings: nothing is written.
				$migrated['from'] = 'chatbot-skipped';
			}
		}

		// 2. Module flags: v1 only ever had the chatbot, so a seeded install
		//    starts with exactly that; an existing v2 install keeps its own.
		if ( $seeded ) {
			update_option( Haa_Settings::MODULES_OPTION, array( 'chatbot' => 1 ), 'yes' );
		}

		// 3. Tables: copy v1 rows into the v2 schema, then let v1 drop its own.
		self::copy_tables();

		// 4. Cron: v1's hook name is retired.
		wp_clear_scheduled_hook( 'hami_ai_daily_cleanup' );
		if ( ! wp_next_scheduled( 'haai_daily_cleanup' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'haai_daily_cleanup' );
		}

		// 5. The tiny on/off gate.
		if ( null === get_option( HAAI_ACTIVE, null ) ) {
			$active = get_option( 'hami_ai_settings_active', 1 );
			add_option( HAAI_ACTIVE, empty( $active ) ? 0 : 1, '', 'yes' );
		}

		Haa_Settings::flush_cache();
		update_option( self::STATE_OPTION, $migrated, 'no' );
	}

	/**
	 * Translate the v1 section layout into the v2 one.
	 *
	 * @param array $old v1 settings.
	 * @return array
	 */
	public static function map_settings( $old ) {
		$new = Haa_Settings::defaults();

		// general is almost identical; keep it verbatim.
		if ( isset( $old['general'] ) && is_array( $old['general'] ) ) {
			$new['general'] = array_merge( $new['general'], $old['general'] );
		}

		// provider: same keys, plus the model-type slots v2 added.
		if ( isset( $old['provider'] ) && is_array( $old['provider'] ) ) {
			$new['provider'] = array_merge( $new['provider'], $old['provider'] );
		}

		if ( isset( $old['appearance'] ) && is_array( $old['appearance'] ) ) {
			$new['appearance'] = array_merge( $new['appearance'], $old['appearance'] );
		}

		// behavior -> chat (most keys move across unchanged).
		if ( isset( $old['behavior'] ) && is_array( $old['behavior'] ) ) {
			foreach ( $old['behavior'] as $key => $value ) {
				$new['chat'][ $key ] = $value;
			}
			// v2-only chat keys keep their defaults.
		}

		// advanced -> advanced (same namespace).
		if ( isset( $old['advanced'] ) && is_array( $old['advanced'] ) ) {
			$new['advanced'] = array_merge( $new['advanced'], $old['advanced'] );
		}

		// v1 had no modules section, but the chatbot was implicitly on.
		$new['modules']['chatbot'] = 1;

		return $new;
	}

	/**
	 * Copy v1 rows into the v2 tables where the schemas line up.
	 */
	private static function copy_tables() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		// Make sure the v2 tables exist before we copy into them.
		Haa_DB::create();

		$map = array(
			'hami_chunks' => 'chunks',
			'hami_cache'  => 'cache',
			'hami_logs'   => 'logs',
			'hami_rate'   => 'rate',
		);

		foreach ( $map as $old_suffix => $new_key ) {
			$old_table = $wpdb->prefix . $old_suffix;
			$new_table = Haa_DB::table( $new_key );

			// phpcs:ignore WordPress.DB
			if ( ! $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $old_table ) ) ) ) {
				continue;
			}
			// phpcs:ignore WordPress.DB
			if ( ! $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $new_table ) ) ) ) {
				continue;
			}

			// phpcs:ignore WordPress.DB
			$existing = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $new_table" );
			if ( $existing > 0 ) {
				continue; // Already have data; do not double it.
			}

			// Same column layout, so a straight INSERT…SELECT is safe.
			// phpcs:ignore WordPress.DB
			$wpdb->query( "INSERT IGNORE INTO $new_table SELECT * FROM $old_table" );
		}

		// The knowledge-base rebuild cursor moves across unchanged.
		$kb_state = get_option( 'hami_ai_kb_state', null );
		if ( is_array( $kb_state ) ) {
			update_option( 'hami_ai_kb_state', $kb_state, 'no' );
		}
	}

	/**
	 * Did we already convert a v1 install?
	 *
	 * @return bool
	 */
	public static function did_migrate() {
		$done = get_option( self::STATE_OPTION, null );
		return is_array( $done ) && ! empty( $done['from'] );
	}

	/**
	 * Which v1 artifact names still exist (for the dashboard notice).
	 *
	 * @return array<int,string>
	 */
	public static function leftovers() {
		global $wpdb;
		$out = array();
		if ( get_option( 'hami_ai_settings', null ) !== null ) {
			$out[] = 'hami_ai_settings';
		}
		foreach ( array( 'hami_chunks', 'hami_cache', 'hami_logs', 'hami_rate' ) as $suffix ) {
			$table = $wpdb->prefix . $suffix;
			// phpcs:ignore WordPress.DB
			if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) ) {
				$out[] = $table;
			}
		}
		return $out;
	}
}
