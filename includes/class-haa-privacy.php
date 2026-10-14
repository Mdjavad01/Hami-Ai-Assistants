<?php
/**
 * GDPR privacy tools: data export and erasure for leads, lead events and chat logs.
 *
 * @package Hami_AI_Assistant
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Haa_Privacy {

	/**
	 * Register WordPress privacy hooks.
	 */
	public static function hooks() {
		add_filter( 'wp_personal_data_exporters', array( __CLASS__, 'register_exporters' ) );
		add_filter( 'wp_personal_data_erasers', array( __CLASS__, 'register_erasers' ) );
	}

	/**
	 * @param array $exporters Existing exporters.
	 * @return array
	 */
	public static function register_exporters( $exporters ) {
		$exporters['hami-leads'] = array(
			'exporter_friendly_name' => __( 'Hami AI Leads', 'hami-ai-assistant' ),
			'callback'               => array( __CLASS__, 'export_leads' ),
		);
		$exporters['hami-chat-logs'] = array(
			'exporter_friendly_name' => __( 'Hami AI Chat Logs', 'hami-ai-assistant' ),
			'callback'               => array( __CLASS__, 'export_chat_logs' ),
		);
		return $exporters;
	}

	/**
	 * @param array $erasers Existing erasers.
	 * @return array
	 */
	public static function register_erasers( $erasers ) {
		$erasers['hami-leads'] = array(
			'eraser_friendly_name' => __( 'Hami AI Leads', 'hami-ai-assistant' ),
			'callback'             => array( __CLASS__, 'erase_leads' ),
		);
		$erasers['hami-chat-logs'] = array(
			'eraser_friendly_name' => __( 'Hami AI Chat Logs', 'hami-ai-assistant' ),
			'callback'             => array( __CLASS__, 'erase_chat_logs' ),
		);
		return $erasers;
	}

	/**
	 * Paged exporter: WordPress calls this with an increasing $page until we
	 * report done, so a large lead table is never built in one array.
	 *
	 * @param string $email_address User email.
	 * @param int    $page          1-based page number.
	 * @return array
	 */
	public static function export_leads( $email_address, $page = 1 ) {
		global $wpdb;

		$leads_table = Haa_DB::table( 'leads' );
		$events_table = Haa_DB::table( 'lead_events' );

		$limit  = 50;
		$offset = ( max( 1, (int) $page ) - 1 ) * $limit;

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$leads = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM $leads_table WHERE email = %s OR phone = %s ORDER BY id ASC LIMIT $limit OFFSET $offset",
				$email_address,
				$email_address
			),
			ARRAY_A
		);

		$export_items = array();

		foreach ( $leads as $lead ) {
			$group_id = 'hami-leads';
			$group_label = __( 'Hami AI Leads', 'hami-ai-assistant' );

			$data = array(
				array( 'name' => __( 'Name', 'hami-ai-assistant' ), 'value' => $lead['name'] ),
				array( 'name' => __( 'Phone', 'hami-ai-assistant' ), 'value' => $lead['phone'] ),
				array( 'name' => __( 'Email', 'hami-ai-assistant' ), 'value' => $lead['email'] ),
				array( 'name' => __( 'Source', 'hami-ai-assistant' ), 'value' => $lead['source'] ),
				array( 'name' => __( 'Stage', 'hami-ai-assistant' ), 'value' => $lead['stage'] ),
				array( 'name' => __( 'Score', 'hami-ai-assistant' ), 'value' => $lead['score'] ),
				array( 'name' => __( 'Consent', 'hami-ai-assistant' ), 'value' => $lead['consent_text'] ),
				array( 'name' => __( 'Created', 'hami-ai-assistant' ), 'value' => $lead['created'] ),
			);

			$export_items[] = array(
				'group_id'    => $group_id,
				'group_label' => $group_label,
				'item_id'     => 'lead-' . $lead['id'],
				'data'        => $data,
			);

			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$events = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM $events_table WHERE lead_id = %d ORDER BY id ASC LIMIT 100",
					$lead['id']
				),
				ARRAY_A
			);

			foreach ( $events as $event ) {
				$event_data = array(
					array( 'name' => __( 'Event Type', 'hami-ai-assistant' ), 'value' => $event['event_type'] ),
					array( 'name' => __( 'Content', 'hami-ai-assistant' ), 'value' => $event['content'] ),
					array( 'name' => __( 'Created', 'hami-ai-assistant' ), 'value' => $event['created'] ),
				);

				$export_items[] = array(
					'group_id'    => $group_id,
					'group_label' => $group_label,
					'item_id'     => 'lead-event-' . $event['id'],
					'data'        => $event_data,
				);
			}
		}

		return array(
			'data' => $export_items,
			'done' => count( $leads ) < $limit,
		);
	}

	/**
	 * Paged exporter (see export_leads()).
	 *
	 * @param string $email_address User email.
	 * @param int    $page          1-based page number.
	 * @return array
	 */
	public static function export_chat_logs( $email_address, $page = 1 ) {
		global $wpdb;

		$logs_table = Haa_DB::table( 'logs' );

		$limit  = 50;
		$offset = ( max( 1, (int) $page ) - 1 ) * $limit;

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$logs = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM $logs_table WHERE user_id IN (SELECT ID FROM {$wpdb->users} WHERE user_email = %s) ORDER BY id ASC LIMIT $limit OFFSET $offset",
				$email_address
			),
			ARRAY_A
		);

		$export_items = array();

		foreach ( $logs as $log ) {
			$data = array(
				array( 'name' => __( 'Message', 'hami-ai-assistant' ), 'value' => $log['message'] ),
				array( 'name' => __( 'Role', 'hami-ai-assistant' ), 'value' => $log['role'] ),
				array( 'name' => __( 'Created', 'hami-ai-assistant' ), 'value' => $log['created'] ),
			);

			$export_items[] = array(
				'group_id'    => 'hami-chat-logs',
				'group_label' => __( 'Hami AI Chat Logs', 'hami-ai-assistant' ),
				'item_id'     => 'chat-log-' . $log['id'],
				'data'        => $data,
			);
		}

		return array(
			'data' => $export_items,
			'done' => count( $logs ) < $limit,
		);
	}

	/**
	 * @param string $email_address User email.
	 * @return array
	 */
	public static function erase_leads( $email_address ) {
		global $wpdb;

		$leads_table = Haa_DB::table( 'leads' );
		$events_table = Haa_DB::table( 'lead_events' );

		$items_removed = 0;

		// Delete in bounded batches. Every selected row is removed on each
		// pass, so the next pass naturally sees the next ones (no offset
		// drift) and a huge table never has to fit in memory at once. The
		// pass cap is a safety valve against a pathological delete failure.
		for ( $pass = 0; $pass < 200; $pass++ ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$lead_ids = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT id FROM $leads_table WHERE email = %s OR phone = %s LIMIT 500",
					$email_address,
					$email_address
				)
			);
			if ( empty( $lead_ids ) ) {
				break;
			}

			$lead_ids_int = array_map( 'intval', $lead_ids );
			$placeholders = implode( ',', array_fill( 0, count( $lead_ids_int ), '%d' ) );

			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query(
				$wpdb->prepare(
					"DELETE FROM $events_table WHERE lead_id IN ($placeholders)",
					...$lead_ids_int
				)
			);

			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$deleted = $wpdb->query(
				$wpdb->prepare(
					"DELETE FROM $leads_table WHERE id IN ($placeholders)",
					...$lead_ids_int
				)
			);

			$items_removed += (int) $deleted;
		}

		return array(
			'items_removed'  => $items_removed,
			'items_retained' => 0,
			'messages'       => array(),
			'done'           => true,
		);
	}

	/**
	 * @param string $email_address User email.
	 * @return array
	 */
	public static function erase_chat_logs( $email_address ) {
		global $wpdb;

		$logs_table = Haa_DB::table( 'logs' );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$user_id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->users} WHERE user_email = %s",
				$email_address
			)
		);

		$items_removed = 0;
		$items_retained = 0;
		$messages = array();

		if ( $user_id ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$deleted = $wpdb->query(
				$wpdb->prepare(
					"DELETE FROM $logs_table WHERE user_id = %d",
					$user_id
				)
			);

			$items_removed = (int) $deleted;
		}

		return array(
			'items_removed'  => $items_removed,
			'items_retained' => $items_retained,
			'messages'       => $messages,
			'done'           => true,
		);
	}
}
