<?php
/**
 * Regression suite: 2.1.8 live handoff seams, deterministically and without a
 * browser:
 *
 *   - the phone gate accepts only Iranian mobiles (any digits, 98XXXXXXXXXX);
 *   - a valid request lands one role='handoff' row in the logs table;
 *   - the admin notification goes to the configured Bale/TG bot with the
 *     visitor's phone and the wa.me link;
 *   - the new chat keys exist in defaults + sanitize map + UI registry
 *     (the settings triple contract).
 *
 * Run: php dev-tests/tmp-checks16-handoff.php
 */
require __DIR__ . '/tmp-wp-stubs.php';

$fail = 0;
function ok( $label, $cond, $extra = '' ) {
        global $fail;
        if ( ! $cond ) { $fail++; }
        echo ( $cond ? '  ok   ' : '  FAIL ' ) . str_pad( $label, 58 ) . $extra . "\n";
}

/* Bale answers 200; Telegram is not configured. */
$GLOBALS['http_map'] = array( 'tapi.bale.ai' => array( 'response' => array( 'code' => 200 ) ) );
$GLOBALS['http_log'] = array();

/* Admin configured the Bale bot + a support WhatsApp link. */
Haa_Settings::save_section(
        'chat',
        array(
                'notify_bale_token'  => 'TESTTOKEN:123',
                'notify_bale_chat'   => '987654321',
                'contact_whatsapp'   => 'https://wa.me/989000000000',
                'handoff_enabled'    => 1,
        )
);

$rc      = new ReflectionClass( 'Haa_Chat' );
$handoff = $rc->getMethod( 'handoff' );
$handoff->setAccessible( true );

function handoff_rows() {
        global $wpdb;
        $out = array();
        foreach ( (array) $GLOBALS['db'] as $bucket ) {
                foreach ( (array) $bucket as $row ) {
                        if ( isset( $row['role'] ) && 'handoff' === $row['role'] ) {
                                $out[] = $row;
                        }
                }
        }
        return $out;
}

echo "=== A. settings triple contract for the new chat keys ===\n";
$settings = Haa_Settings::get();
foreach ( array( 'handoff_enabled', 'handoff_after', 'notify_bale_token', 'notify_bale_chat', 'notify_tg_token', 'notify_tg_chat', 'tone', 'page_context', 'custom_knowledge' ) as $k ) {
        ok( "default chat.$k exists", array_key_exists( $k, $settings['chat'] ) );
}
$map_rc = new ReflectionMethod( 'Haa_Settings', 'sanitize_map' );
$map_rc->setAccessible( true );
$map = $map_rc->invoke( null );
foreach ( array( 'chat.handoff_enabled', 'chat.handoff_after', 'chat.notify_bale_token', 'chat.notify_bale_chat', 'chat.notify_tg_token', 'chat.notify_tg_chat', 'chat.tone', 'chat.page_context', 'chat.custom_knowledge' ) as $k ) {
        ok( "sanitize map has $k", isset( $map[ $k ] ) );
}
$ui_rc = new ReflectionMethod( 'Haa_Settings', 'collect_ui_keys' );
$ui_rc->setAccessible( true );
$ui = $ui_rc->invoke( null );
foreach ( array( 'chat.handoff_enabled', 'chat.tone', 'chat.page_context', 'chat.custom_knowledge' ) as $k ) {
        ok( "UI registry carries $k", in_array( $k, (array) $ui, true ) );
}

echo "=== B. phone gate for handoff ===\n";
$bad = $handoff->invoke( null, array( 'name' => 'علی', 'phone' => '12345', 'note' => '' ), 'sid-a', '', '' );
ok( 'short/garbage phone is refused', empty( $bad['ok'] ) );
$bad2 = $handoff->invoke( null, array( 'name' => '', 'phone' => '09121234567', 'note' => '' ), 'sid-a', '', '' );
ok( 'empty name is refused', empty( $bad2['ok'] ) );
$good = $handoff->invoke( null, array( 'name' => 'علی', 'phone' => '۰۹۱۲ ۱۲۳ ۴۵۶۷', 'note' => 'تست' ), 'sid-a', 'https://example.test/product/x', 'سلام' );
ok( 'Persian digits + spaces normalize and pass', ! empty( $good['ok'] ) );

echo "=== C. storage + notification ===\n";
$rows = handoff_rows();
ok( 'exactly one handoff row stored', 1 === count( $rows ), count( $rows ) . ' row(s)' );
$row = $rows ? $rows[0] : array( 'message' => '' );
ok( 'row carries local phone 09121234567', false !== strpos( (string) $row['message'], '09121234567' ) );
ok( 'row keeps the last chat message', 'سلام' === (string) $row['response'] );
ok( 'Bale bot was called', false !== strpos( (string) $GLOBALS['http_log'][0]['url'], 'tapi.bale.ai/bot' ), '(' . count( $GLOBALS['http_log'] ) . ' http call(s))' );
$body = json_decode( (string) $GLOBALS['http_log'][0]['args']['body'], true );
ok( 'notification text has name + wa.me link', false !== strpos( (string) $body['text'], 'علی' ) && false !== strpos( (string) $body['text'], 'https://wa.me/989121234567' ) );
ok( 'handoff reports notified=true', true === $good['notified'] );

$again = $handoff->invoke( null, array( 'name' => 'مریم', 'phone' => '09351112233', 'note' => '' ), 'sid-b', '', '' );
ok( 'second request stored too', 2 === count( handoff_rows() ) );
ok( 'wa link mirrors contact_whatsapp', ! empty( $good['wa'] ) );

echo "\nFAILURES: $fail\n";
exit( $fail ? 1 : 0 );
