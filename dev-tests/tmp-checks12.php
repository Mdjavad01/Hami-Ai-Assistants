<?php
/**
 * Regression suite: Batch A — a single-version safety snapshot before
 * reset()/import(), restorable from the advanced tab, secrets never in clear.
 *
 * The snapshot stores the RAW settings option, so provider.api_key and
 * provider.proxy_password are carried in their encrypted form. This suite
 * proves (a) the snapshot exists after each destructive path, (b) it holds no
 * plaintext secret, (c) restoring returns every field and the module mirror.
 *
 * Before the fix these checks fail hard: Haa_Settings::snapshot() /
 * has_snapshot() / restore_snapshot() do not exist yet.
 *
 * Run: php dev-tests/tmp-checks12.php
 */
require __DIR__ . '/tmp-wp-stubs.php';

$fail = 0;
function ok( $label, $cond, $extra = '' ) {
	global $fail;
	if ( ! $cond ) { $fail++; }
	echo ( $cond ? '  ok   ' : '  FAIL ' ) . str_pad( $label, 58 ) . $extra . "\n";
}

/** Live install with a genuinely ENCRYPTED secret and non-default fields. */
function seed_encrypted() {
	delete_option( Haa_Settings::SNAPSHOT_OPTION );
	$plain_key = 'sk-LIVE-9f3a2b';
	$plain_pw  = 'proxy-pw-77';
	$s = Haa_Settings::defaults();
	$s['provider']['api_key']       = Haa_Settings::encrypt( $plain_key );
	$s['provider']['proxy_password']= Haa_Settings::encrypt( $plain_pw );
	$s['provider']['model']         = 'live-model';
	$s['provider']['base_url']      = 'https://live.example/v1';
	$s['appearance']['primary_color'] = '#123456';
	$s['advanced']['cache_ttl']     = 1234;
	$s['general']['enabled']        = 1;
	update_option( HAAI_OPTION, $s, 'no' );
	update_option( Haa_Settings::MODULES_OPTION, array( 'chatbot' => 1, 'product-content' => 1 ), 'yes' );
	Haa_Settings::flush_cache();
	return array( $plain_key, $plain_pw );
}

echo "=== A. no snapshot before any destructive op ===\n";
list( $plain_key, $plain_pw ) = seed_encrypted();
ok( 'a live install starts with no snapshot', ! Haa_Settings::has_snapshot() );
ok( 'snapshot_label() is empty with nothing stored', '' === Haa_Settings::snapshot_label() );

echo "\n=== B. reset() takes a snapshot, keeps the secret encrypted ===\n";
$before = get_option( HAAI_OPTION );
$enc_key_before = $before['provider']['api_key'];
$enc_pw_before  = $before['provider']['proxy_password'];
Haa_Settings::reset();
ok( 'reset() left the settings at defaults', '' === (string) Haa_Settings::get( 'provider.api_key' ) );
ok( 'reset() switched extra modules off', ! isset( Haa_Settings::enabled_modules()['product-content'] ) );
ok( 'a snapshot now exists', Haa_Settings::has_snapshot() );
$snap = get_option( Haa_Settings::SNAPSHOT_OPTION );
ok( 'snapshot has a time label', ! empty( $snap['time'] ) );
ok( 'snapshot carries the module mirror', isset( $snap['modules']['product-content'] ) );
$sk = $snap['settings']['provider']['api_key'];
ok( 'snapshot secret is still ciphertext', 0 === strpos( $sk, 'haaienc:' ), substr( (string) $sk, 0, 10 ) );
ok( 'snapshot secret is NOT the plaintext', $sk !== $plain_key );
ok( 'snapshot blob does not contain the plaintext key', false === strpos( wp_json_encode( $snap ), $plain_key ) );
ok( 'snapshot blob does not contain the plaintext proxy pw', false === strpos( wp_json_encode( $snap ), $plain_pw ) );
ok( 'snapshot secret decrypts back to the original', Haa_Settings::decrypt( $sk ) === $plain_key );

echo "\n=== C. restore_snapshot() returns every field, byte for byte ===\n";
$ok = Haa_Settings::restore_snapshot();
$after = get_option( HAAI_OPTION );
ok( 'restore reports success', true === $ok );
ok( 'encrypted api_key returned identical', $after['provider']['api_key'] === $enc_key_before );
ok( 'encrypted proxy_password returned identical', $after['provider']['proxy_password'] === $enc_pw_before );
ok( 'model returned', 'live-model' === $after['provider']['model'] );
ok( 'base_url returned', 'https://live.example/v1' === $after['provider']['base_url'] );
ok( 'appearance returned', '#123456' === $after['appearance']['primary_color'] );
ok( 'cache_ttl returned', 1234 === (int) $after['advanced']['cache_ttl'] );
ok( 'gate enabled returned', ! empty( $after['general']['enabled'] ) );
ok( 'the extra module is switched back on', ! empty( Haa_Settings::enabled_modules()['product-content'] ) );
ok( 'the decrypted key is usable again', $plain_key === Haa_Settings::provider()['api_key'] );

echo "\n=== D. import() takes a snapshot; a bad file does not ===\n";
list( $plain_key, $plain_pw ) = seed_encrypted();
delete_option( Haa_Settings::SNAPSHOT_OPTION );
$json = wp_json_encode( array( 'settings' => array( 'provider' => array( 'model' => 'imported-model' ) ) ) );
$res  = Haa_Settings::import( $json );
ok( 'import succeeded', true === $res );
ok( 'import applied its key', 'imported-model' === Haa_Settings::get( 'provider.model' ) );
ok( 'import snapshotted the pre-import state', Haa_Settings::has_snapshot() );
$snap = get_option( Haa_Settings::SNAPSHOT_OPTION );
ok( 'the snapshot still holds the old model', 'live-model' === $snap['settings']['provider']['model'] );
Haa_Settings::restore_snapshot();
ok( 'restore rewinds the import', 'live-model' === Haa_Settings::get( 'provider.model' ) );

delete_option( Haa_Settings::SNAPSHOT_OPTION );
$err = Haa_Settings::import( '{not json' );
ok( 'invalid JSON returns an error', $err instanceof WP_Error );
ok( 'a rejected file leaves no snapshot', ! Haa_Settings::has_snapshot() );

echo "\n=== E. restore without a snapshot is a safe no-op ===\n";
list( $plain_key, $plain_pw ) = seed_encrypted();
delete_option( Haa_Settings::SNAPSHOT_OPTION );
ok( 'nothing to restore returns false', false === Haa_Settings::restore_snapshot() );
ok( 'the live settings were left alone', 'live-model' === Haa_Settings::get( 'provider.model' ) );

echo "\n=== F. fresh install: reset() snapshots nothing it never had ===\n";
delete_option( HAAI_OPTION );
delete_option( Haa_Settings::MODULES_OPTION );
delete_option( Haa_Settings::SNAPSHOT_OPTION );
Haa_Settings::flush_cache();
ok( 'snapshot() on a virgin install returns false', false === Haa_Settings::snapshot() );
ok( 'no snapshot was created', ! Haa_Settings::has_snapshot() );

echo "\nFAILURES: $fail\n";
exit( $fail ? 1 : 0 );
