<?php
/**
 * Regression suite: Batch A2 — the import path must validate, sanitize and
 * refuse to take a stranger's API key.
 *
 * These checks describe the WRONG behaviour of the old code, so they fail
 * before the fix for the right reason (real wrong state, not a missing method):
 *   - save_all() merged whatever the file carried, so unknown keys and unknown
 *     sections were written into the option and stayed there;
 *   - no sanitizer ran on import, so out-of-range or wrong-typed values were
 *     stored verbatim;
 *   - provider.api_key in the file replaced the live key with no confirmation.
 *
 * Run: php dev-tests/tmp-checks15.php
 */
require __DIR__ . '/tmp-wp-stubs.php';

$fail = 0;
function ok( $label, $cond, $extra = '' ) {
	global $fail;
	if ( ! $cond ) { $fail++; }
	echo ( $cond ? '  ok   ' : '  FAIL ' ) . str_pad( $label, 58 ) . $extra . "\n";
}

function seed_live() {
	$s = Haa_Settings::defaults();
	$s['provider']['api_key']  = Haa_Settings::encrypt( 'sk-LIVE-key-1' );
	$s['provider']['model']    = 'live-model';
	$s['provider']['timeout']  = 60;
	$s['general']['enabled']   = 1;
	update_option( HAAI_OPTION, $s, 'no' );
	update_option( Haa_Settings::MODULES_OPTION, array( 'chatbot' => 1 ), 'yes' );
	Haa_Settings::flush_cache();
}

echo "=== A. unknown keys and sections must not reach the option ===\n";
seed_live();
$json = wp_json_encode(
	array(
		'settings' => array(
			'general'    => array( 'enabled' => 1, 'zzz_injected' => 'boo' ),
			'notasection' => array( 'anything' => 1 ),
		),
	)
);
$res = Haa_Settings::import( $json );
$raw = get_option( HAAI_OPTION, array() );
ok( 'import of a known key succeeded', true === $res );
ok( 'unknown key inside a known section is dropped', ! isset( $raw['general']['zzz_injected'] ) );
ok( 'unknown section is dropped', ! isset( $raw['notasection'] ) );
ok( 'the known key still applied', 1 === (int) $raw['general']['enabled'] );

echo "\n=== B. values go through their sanitizer ===\n";
seed_live();
Haa_Settings::import(
	wp_json_encode(
		array(
			'settings' => array(
				'provider' => array( 'timeout' => 999999, 'model' => "bad\r\nmodel<script>" ),
			),
		)
	)
);
$live = Haa_Settings::get();
ok( 'out-of-range int is clamped to the rule', (int) $live['provider']['timeout'] <= 300 && (int) $live['provider']['timeout'] >= 5, (string) $live['provider']['timeout'] );
ok( 'model string is sanitized', false === strpos( (string) $live['provider']['model'], '<' ), $live['provider']['model'] );

echo "\n=== C. a key in the file needs an explicit allow ===\n";
seed_live();
$before = Haa_Settings::get( 'provider.api_key' );
Haa_Settings::import(
	wp_json_encode( array( 'settings' => array( 'provider' => array( 'api_key' => 'sk-FOREIGN-key-2' ) ) ) )
);
$after = Haa_Settings::get( 'provider.api_key' );
ok( 'without allow_secrets the live key survives', Haa_Settings::decrypt( $after ) === 'sk-LIVE-key-1', Haa_Settings::decrypt( $after ) );
ok( 'the stored key bytes did not change', $after === $before );

seed_live();
Haa_Settings::import(
	wp_json_encode( array( 'settings' => array( 'provider' => array( 'api_key' => 'sk-FOREIGN-key-2' ) ) ) ),
	array( 'allow_secrets' => true )
);
ok( 'with allow_secrets the file key is applied (encrypted)', 'sk-FOREIGN-key-2' === Haa_Settings::decrypt( Haa_Settings::get( 'provider.api_key' ) ) );
ok( 'and it is not stored in the clear', 0 === strpos( (string) Haa_Settings::get( 'provider.api_key' ), 'haaienc:' ) );

echo "\n=== D. blank secret in the file keeps the live one ===\n";
seed_live();
Haa_Settings::import( wp_json_encode( array( 'settings' => array( 'provider' => array( 'api_key' => '' ) ) ) ) );
ok( 'empty api_key does not erase the stored key', 'sk-LIVE-key-1' === Haa_Settings::decrypt( Haa_Settings::get( 'provider.api_key' ) ) );

echo "\n=== E. validate_import() is a pure, useful summary ===\n";
seed_live();
$v = Haa_Settings::validate_import(
	array(
		'settings' => array(
			'provider'   => array( 'model' => 'other-model', 'timeout' => 60 ),
			'appearance' => array( 'primary_color' => '#123456' ),
		),
		'modules'  => array( 'chatbot' => 1, 'news' => 1, 'leads' => 0 ),
	)
);
ok( 'accepted', ! empty( $v['ok'] ) );
ok( 'counts only the keys it kept (3)', 3 === (int) $v['keys'], (string) $v['keys'] );
ok( 'timeout equal to the live value is not a change', 2 === (int) $v['changed'], (string) $v['changed'] );
ok( 'per-section counts', 2 === (int) $v['sections']['provider'] && 1 === (int) $v['sections']['appearance'] );
ok( 'module map keeps only the on flags', array( 'chatbot', 'news' ) === array_keys( $v['modules'] ), implode( ',', array_keys( $v['modules'] ) ) );
ok( 'no secrets in this file', empty( $v['has_secrets'] ) );
ok( 'nothing was written by validating', 'live-model' === Haa_Settings::get( 'provider.model' ) );

$v2 = Haa_Settings::validate_import( array( 'settings' => array( 'provider' => array( 'api_key' => 'sk-x' ) ) ) );
ok( 'a file with a key is flagged', ! empty( $v2['has_secrets'] ) );

echo "\n=== F. rejected payloads ===\n";
$bad1 = Haa_Settings::validate_import( 'not an array' );
ok( 'scalar payload rejected', empty( $bad1['ok'] ) && '' !== $bad1['error'] );
$bad2 = Haa_Settings::validate_import( array( 'settings' => array( 'zz' => array( 'yy' => 1 ) ) ) );
ok( 'file with only unknown keys rejected', empty( $bad2['ok'] ) );
$bad3 = Haa_Settings::validate_import( array( 'settings' => 'oops' ) );
ok( 'structure mismatch rejected', empty( $bad3['ok'] ) );
$err  = Haa_Settings::import( wp_json_encode( array( 'settings' => array( 'zz' => array( 'yy' => 1 ) ) ) ) );
ok( 'import() returns WP_Error for it', $err instanceof WP_Error );
ok( 'and left the settings alone', 'live-model' === Haa_Settings::get( 'provider.model' ) );

echo "\n=== G. caps and the reset word are declared once ===\n";
ok( 'import size cap is sane', Haa_Settings::IMPORT_MAX_BYTES >= 65536 && Haa_Settings::IMPORT_MAX_BYTES <= 2097152, (string) Haa_Settings::IMPORT_MAX_BYTES );
ok( 'reset word is non-empty Persian text', '' !== Haa_Settings::RESET_WORD && preg_match( '/[\x{0600}-\x{06FF}]/u', Haa_Settings::RESET_WORD ) );

echo "\nFAILURES: $fail\n";
exit( $fail ? 1 : 0 );
