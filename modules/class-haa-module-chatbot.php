<?php
/**
 * Module: Chatbot — the floating assistant that answers from the site's own
 * content, captures leads and survives page caching.
 *
 * Frontend assets, the REST surface and the chat pipeline live in core (Haa_Chat,
 * Haa_REST, Haa_Search) so they never load for a site that switches this module
 * off. This file owns only the chatbot-specific settings section, the sessions
 * table and the "restart" / history-retention behaviour.
 *
 * @package Hami_AI_Assistant
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Haa_Module_Chatbot implements Haa_Module_Interface {

	/** @return string */
	public function slug() {
		return 'chatbot';
	}

	/** @return string */
	public function label() {
		return __( 'چت‌بات فروش', 'hami-ai-assistant' );
	}

	/** @return string */
	public function description() {
		return __( 'دستیار گفتگوی شناور که از محتوای سایت جواب می‌دهد، لید جمع می‌کند و بدون توجه به کش صفحات کار می‌کند.', 'hami-ai-assistant' );
	}

	/** @return bool */
	public function requires_woocommerce() {
		// Single source of truth: Haa_Modules::catalog_meta()[ 'woo' ].
		return Haa_Modules::requires_woocommerce( $this->slug() );
	}

	/** @return void */
	public function register() {
		add_action( 'haai_settings_sections', array( $this, 'register_section' ), 10 );
		add_action( 'haai_settings_fields_chat', array( $this, 'register_fields' ), 10 );
	}

	/** @return void */
	public function activate() {
		Haa_DB::create();
	}

	/** @return void */
	public function deactivate() {
		// Conversations are data: uninstall.php decides whether they go.
		delete_transient( 'haai_chat_preview' );
	}

	/**
	 * Declares the "Chatbot" tab. Registered only when the module is on, so a
	 * disabled chatbot leaves no orphan tab behind.
	 *
	 * @param array $sections Tabs collected so far.
	 * @return array
	 */
	public function register_section( $sections ) {
		$sections['chat'] = __( 'چت‌بات', 'hami-ai-assistant' );
		return $sections;
	}

	/**
	 * Fields shown on the Chatbot tab.
	 *
	 * @param array $fields Fields collected so far.
	 * @return array
	 */
	public function register_fields( $fields ) {
		$fields[] = array(
			'key'   => 'chat.system_prompt',
			'type'  => 'textarea',
			'label' => __( 'دستور سیستمی', 'hami-ai-assistant' ),
			'hint'  => __( 'شخصیت و قوانین پاسخ‌دهی. کوتاه، فارسی و بدون ادعای ساختگی بنویسید.', 'hami-ai-assistant' ),
		);
		$fields[] = array(
			'key'   => 'chat.max_history',
			'type'  => 'number',
			'label' => __( 'حداکثر نوبت‌های گفتگو', 'hami-ai-assistant' ),
			'min'   => 0,
			'max'   => 20,
		);
		$fields[] = array(
			'key'   => 'chat.context_chunks',
			'type'  => 'number',
			'label' => __( 'تعداد بخش‌های اطلاعاتی', 'hami-ai-assistant' ),
			'min'   => 1,
			'max'   => 20,
		);
		$fields[] = array(
			'key'   => 'chat.context_max_chars',
			'type'  => 'number',
			'label' => __( 'حداکثر کاراکتر اطلاعات', 'hami-ai-assistant' ),
			'min'   => 200,
			'max'   => 8000,
		);
		$fields[] = array(
			'key'   => 'chat.suggested_questions',
			'type'  => 'textarea',
			'label' => __( 'پرسش‌های پیشنهادی', 'hami-ai-assistant' ),
			'hint'  => __( 'هر سؤال در یک خط.', 'hami-ai-assistant' ),
		);
		$fields[] = array(
			'key'   => 'chat.error_message',
			'type'  => 'text',
			'label' => __( 'پیام خطا', 'hami-ai-assistant' ),
		);
		$fields[] = array(
			'key'   => 'chat.typing_indicator',
			'type'  => 'checkbox',
			'label' => __( 'نمایش نشانگر تایپ', 'hami-ai-assistant' ),
			'hint'  => __( 'هنگام پردازش پاسخ، انیمیشن «در حال تایپ» نشان داده شود.', 'hami-ai-assistant' ),
		);
		$fields[] = array(
			'key'   => 'chat.offline_enabled',
			'type'  => 'checkbox',
			'label' => __( 'حالت خارج از ساعات کاری', 'hami-ai-assistant' ),
		);
		$fields[] = array(
			'key'   => 'chat.offline_start',
			'type'  => 'time',
			'label' => __( 'شروع ساعات کاری', 'hami-ai-assistant' ),
		);
		$fields[] = array(
			'key'   => 'chat.offline_end',
			'type'  => 'time',
			'label' => __( 'پایان ساعات کاری', 'hami-ai-assistant' ),
		);
		$fields[] = array(
			'key'   => 'chat.offline_message',
			'type'  => 'text',
			'label' => __( 'پیام خارج از ساعات کاری', 'hami-ai-assistant' ),
		);
		$fields[] = array(
			'key'   => 'chat.save_history',
			'type'  => 'checkbox',
			'label' => __( 'ذخیره‌ی گفتگو', 'hami-ai-assistant' ),
			'hint'  => __( 'هر گفتگو در یک ردیف جدول نشست‌ها ثبت می‌شود.', 'hami-ai-assistant' ),
		);
		$fields[] = array(
			'key'   => 'chat.history_retention',
			'type'  => 'number',
			'label' => __( 'مدت نگهداری گفتگو (روز)', 'hami-ai-assistant' ),
			'min'   => 1,
			'max'   => 3650,
		);
		$fields[] = array(
			'key'   => 'chat.lead_capture',
			'type'  => 'checkbox',
			'label' => __( 'دریافت نام و شماره در چت', 'hami-ai-assistant' ),
			'hint'  => __( 'ماژول «باشگاه لید» باید فعال باشد.', 'hami-ai-assistant' ),
		);
		$fields[] = array(
			'key'    => 'chat.lead_requirement',
			'type'   => 'select',
			'label'  => __( 'اجباری بودن', 'hami-ai-assistant' ),
			'choices' => array(
				'off'      => __( 'خاموش', 'hami-ai-assistant' ),
				'optional' => __( 'اختیاری', 'hami-ai-assistant' ),
				'required' => __( 'اجباری', 'hami-ai-assistant' ),
			),
		);
		$fields[] = array(
			'key'   => 'chat.lead_name_label',
			'type'  => 'text',
			'label' => __( 'برچسب فیلد نام', 'hami-ai-assistant' ),
		);
		$fields[] = array(
			'key'   => 'chat.lead_phone_label',
			'type'  => 'text',
			'label' => __( 'برچسب فیلد موبایل', 'hami-ai-assistant' ),
		);
		$fields[] = array(
			'key'   => 'chat.lead_consent_text',
			'type'  => 'text',
			'label' => __( 'متن رضایت', 'hami-ai-assistant' ),
		);
		return $fields;
	}

	/* ------------------------------------------------------------------
	 * Shared helpers used by Haa_Chat / Haa_REST when this module is on.
	 * ---------------------------------------------------------------- */

	/**
	 * Persist an anonymous or member conversation so it can be resumed.
	 *
	 * @param string $sid Session id (32 hex).
	 * @param array  $turns Turns.
	 * @param int    $user_id User id.
	 */
	public static function store_session( $sid, $turns, $user_id = 0 ) {
		global $wpdb;
		if ( ! Haa_Settings::is_module_enabled( 'chatbot' ) ) {
			return;
		}
		$table = Haa_DB::table( 'sessions' );
		$now   = current_time( 'mysql', true );
		$turns = array_slice( array_values( (array) $turns ), -40 );

		$row = $wpdb->get_row( $wpdb->prepare( "SELECT id FROM $table WHERE sid = %s", $sid ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( $row ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->update(
				$table,
				array(
					'messages'      => wp_json_encode( $turns ),
					'message_count' => count( $turns ),
					'updated'       => $now,
				),
				array( 'id' => (int) $row['id'] ),
				array( '%s', '%d', '%s' ),
				array( '%d' )
			);
			return;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->insert(
			$table,
			array(
				'sid'           => $sid,
				'user_id'       => (int) $user_id,
				'lead_id'       => 0,
				'title'         => isset( $turns[0]['content'] ) ? Haa_Util::cut( (string) $turns[0]['content'], 160 ) : '',
				'messages'      => wp_json_encode( $turns ),
				'message_count' => count( $turns ),
				'created'       => $now,
				'updated'       => $now,
			),
			array( '%s', '%d', '%d', '%s', '%s', '%d', '%s', '%s' )
		);
	}

	/**
	 * Old conversations are deleted by the daily cleanup job.
	 *
	 * @param int $days Retention.
	 */
	public static function purge_sessions( $days ) {
		global $wpdb;
		$table = Haa_DB::table( 'sessions' );
		$days  = max( 1, (int) $days );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM $table WHERE updated < DATE_SUB(NOW(), INTERVAL %d DAY)",
				$days
			)
		);
	}
}
