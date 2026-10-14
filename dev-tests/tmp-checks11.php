<?php
/**
 * Regression suite: M10 — a partial settings write must never reset the rest.
 *
 * Covers: deep merge over the stored option, the nested-section case, the
 * checkbox/boolean case, reset() staying a full reset, import() semantics,
 * the migrator gate, and save_section() being untouched.
 *
 * Run: php dev-tests/tmp-checks11.php
 */
require __DIR__ . '/tmp-wp-stubs.php';

$fail = 0;
function ok( $label, $cond, $extra = '' ) {
	global $fail;
	if ( ! $cond ) { $fail++; }
	echo ( $cond ? '  ok   ' : '  FAIL ' ) . str_pad( $label, 56 ) . $extra . "\n";
}

/** Fresh store with a few non-default values in several sections. */
function seed_live() {
	$s = Haa_Settings::defaults();
	$s['provider']['api_key']       = 'SECRET-KEY-123';
	$s['provider']['model']         = 'live-model';
	$s['provider']['base_url']      = 'https://live.example/v1';
	$s['appearance']['primary_color'] = '#123456';
	$s['chat']['context_chunks']    = 9;
	$s['advanced']['enable_rag']    = 1;
	$s['advanced']['cache_ttl']     = 1234;
	$s['modules']['chatbot']        = 1;
	$s['modules']['product-content'] = 1;
	update_option( HAAI_OPTION, $s, 'no' );
	update_option( Haa_Settings::MODULES_OPTION, array( 'chatbot' => 1, 'product-content' => 1 ), 'yes' );
	Haa_Settings::flush_cache();
}

echo "=== A. merge() is deep for sections ===\n";
seed_live();
Haa_Settings::save_all( array( 'provider' => array( 'model' => 'patched-model' ) ) );
$live = Haa_Settings::get();
ok( 'sent key replaced', 'patched-model' === $live['provider']['model'], $live['provider']['model'] );
ok( 'other key in the same section kept', 'SECRET-KEY-123' === $live['provider']['api_key'] );
ok( 'base_url in the same section kept', 'https://live.example/v1' === $live['provider']['base_url'] );
ok( 'another section untouched', '#123456' === $live['appearance']['primary_color'], $live['appearance']['primary_color'] );
ok( 'a third section untouched', 9 === (int) $live['chat']['context_chunks'], (string) $live['chat']['context_chunks'] );

echo "\n=== B. the exact sks shape: modules only ===\n";
seed_live();
Haa_Settings::save_all( array( 'modules' => array( 'chatbot' => 1, 'product-content' => 1, 'news' => 1 ) ) );
$live = Haa_Settings::get();
ok( 'provider key survived a modules-only save', 'SECRET-KEY-123' === $live['provider']['api_key'] );
ok( 'appearance survived', '#123456' === $live['appearance']['primary_color'] );
ok( 'cache_ttl survived', 1234 === (int) $live['advanced']['cache_ttl'], (string) $live['advanced']['cache_ttl'] );
ok( 'the sent module is on', ! empty( Haa_Settings::enabled_modules()['news'] ) );
ok( 'a module not mentioned keeps its state', ! empty( Haa_Settings::enabled_modules()['chatbot'] ) );

echo "\n=== C. booleans: absent means \"keep\", never \"toggle\" ===\n";
seed_live();
Haa_Settings::save_all( array( 'chat' => array( 'context_chunks' => 7 ) ) );
ok( 'stored-true boolean stays true', ! empty( Haa_Settings::get( 'advanced.enable_rag' ) ) );
Haa_Settings::save_all( array( 'chat' => array( 'context_chunks' => 6 ) ) );
ok( 'and is still true after a second partial save', ! empty( Haa_Settings::get( 'advanced.enable_rag' ) ) );

// The form path is the one that resolves an unchecked box, because
// sanitize_section() rebuilds the whole section from the sanitize map.
Haa_Settings::save_section( 'advanced', array( 'cache_ttl' => 600 ) );
ok( 'form path turns the omitted checkbox off', empty( Haa_Settings::get( 'advanced.enable_rag' ) ) );
ok( 'form path keeps the field it did send', 600 === (int) Haa_Settings::get( 'advanced.cache_ttl' ) );

echo "\n=== D. reset() is still a full reset ===\n";
seed_live();
Haa_Settings::reset();
$live    = Haa_Settings::get();
$default = Haa_Settings::defaults();
$same    = true;
foreach ( array( 'provider', 'appearance', 'chat', 'advanced', 'general' ) as $section ) {
	if ( $live[ $section ] !== $default[ $section ] ) { $same = false; }
}
ok( 'every section equals the defaults', $same );
ok( 'the secret is gone after reset', '' === (string) $live['provider']['api_key'] );
ok( 'only the default module is on', array( 'chatbot' ) === array_keys( Haa_Settings::enabled_modules() ) );
$raw = get_option( HAAI_OPTION, array() );
ok( 'no leftover key in the stored option', array_keys( $raw ) === array_keys( $default ), implode( ',', array_diff( array_keys( $raw ), array_keys( $default ) ) ) );

echo "\n=== E. import(): present keys replace, absent keys stay ===\n";
seed_live();
$json = wp_json_encode( array( 'settings' => array( 'appearance' => array( 'primary_color' => '#abcdef' ) ) ) );
$res  = Haa_Settings::import( $json );
$live = Haa_Settings::get();
ok( 'import returns true', true === $res );
ok( 'imported key applied', '#abcdef' === $live['appearance']['primary_color'], $live['appearance']['primary_color'] );
ok( 'unmentioned section kept', 'SECRET-KEY-123' === $live['provider']['api_key'] );
ok( 'unmentioned key in the imported section kept', 9 === (int) $live['chat']['context_chunks'], (string) $live['chat']['context_chunks'] );

seed_live();
Haa_Settings::import( wp_json_encode( array( 'settings' => array( 'provider' => array( 'api_key' => '' ) ) ) ) );
ok( 'an explicitly empty key does not erase the live one', 'SECRET-KEY-123' === Haa_Settings::get( 'provider.api_key' ) );

echo "\n=== F. migrator gate ===\n";
// The harness has no database: keep the schema migrations out of the way so
// only the settings/module gate is under test.
update_option( 'haai_db_version', HAAI_DB_VERSION );

// (a) a live v2 install: the v1 option must not touch anything.
seed_live();
update_option( 'hami_ai_settings', array( 'provider' => array( 'api_key' => 'V1-KEY' ), 'general' => array( 'enabled' => 1 ) ), 'no' );
delete_option( Haa_Migrator::STATE_OPTION );
Haa_Migrator::maybe_migrate();
$live = Haa_Settings::get();
ok( 'v2 settings untouched by migration', 'SECRET-KEY-123' === $live['provider']['api_key'], $live['provider']['api_key'] );
ok( 'v2 modules untouched', ! empty( Haa_Settings::enabled_modules()['product-content'] ) );
ok( 'migration recorded as skipped', 'chatbot-skipped' === ( get_option( Haa_Migrator::STATE_OPTION )['from'] ?? '' ) );

// (b) a fresh install: v1 seeds the option once.
delete_option( HAAI_OPTION );
update_option( Haa_Settings::MODULES_OPTION, array(), 'yes' );
delete_option( Haa_Migrator::STATE_OPTION );
Haa_Migrator::maybe_migrate();
$live = Haa_Settings::get();
ok( 'fresh install seeded from v1', 'V1-KEY' === $live['provider']['api_key'], $live['provider']['api_key'] );
ok( 'fresh install starts with the chatbot only', array( 'chatbot' ) === array_keys( Haa_Settings::enabled_modules() ), implode( ',', array_keys( Haa_Settings::enabled_modules() ) ) );

echo "\n=== G. fresh install + partial save_all ===\n";
delete_option( HAAI_OPTION );
delete_option( Haa_Settings::MODULES_OPTION );
Haa_Settings::flush_cache();
Haa_Settings::save_all( array( 'provider' => array( 'model' => 'first-model' ) ) );
$live = Haa_Settings::get();
ok( 'option created without an existing one', is_array( get_option( HAAI_OPTION, null ) ) );
ok( 'the override landed', 'first-model' === $live['provider']['model'] );
ok( 'the rest of the structure is complete', 11 === count( (array) $live['modules'] ), (string) count( (array) $live['modules'] ) );

echo "\nFAILURES: $fail\n";
exit( $fail ? 1 : 0 );
