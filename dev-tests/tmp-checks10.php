<?php
/**
 * Regression suite: the module catalogue is the single source of truth.
 *
 * Guards the bug where `news`, `video-article` and `programmatic` were absent
 * from the settings defaults and the sanitize map, so saving any settings
 * screen silently switched them off (and diagnose() never said a word).
 *
 * Run: php dev-tests/tmp-checks10.php
 */
require __DIR__ . '/tmp-wp-stubs.php';

$fail = 0;
function ok( $label, $cond, $extra = '' ) {
	global $fail;
	if ( ! $cond ) { $fail++; }
	echo ( $cond ? '  ok   ' : '  FAIL ' ) . str_pad( $label, 50 ) . $extra . "\n";
}

$slugs    = Haa_Settings::module_slugs();
$defaults = Haa_Settings::defaults();
$rules    = Haa_Settings::sanitize_map();
$all_on   = array();
foreach ( $slugs as $slug ) {
	$all_on[ $slug ] = 1;
}

echo "=== A. catalogue is the source of truth ===\n";
ok( 'catalog slugs reach the settings layer', count( $slugs ) >= 11, (string) count( $slugs ) );
foreach ( array( 'news', 'video-article', 'programmatic' ) as $slug ) {
	ok( "slug present: $slug", in_array( $slug, $slugs, true ) );
}

$missing_defaults = array();
$missing_rules    = array();
foreach ( $slugs as $slug ) {
	if ( ! array_key_exists( $slug, (array) $defaults['modules'] ) ) {
		$missing_defaults[] = $slug;
	}
	if ( ! isset( $rules[ 'modules.' . $slug ] ) ) {
		$missing_rules[] = $slug;
	}
}
ok( 'every slug has a default', array() === $missing_defaults, implode( ',', $missing_defaults ) );
ok( 'every slug has a sanitize rule', array() === $missing_rules, implode( ',', $missing_rules ) );

echo "\n=== B. the real save paths keep all modules ===\n";

// Path 1: the Modules screen posts modules[slug]; Haa_Admin_Modules::save()
// hands the flags to set_enabled_modules().
Haa_Settings::set_enabled_modules( $all_on );
$mirror = Haa_Settings::enabled_modules();
ok( 'mirror keeps every slug', count( $mirror ) === count( $slugs ), count( $mirror ) . '/' . count( $slugs ) );

$stored  = get_option( HAAI_OPTION, array() );
$section = isset( $stored['modules'] ) ? (array) $stored['modules'] : array();
ok( 'stored section keeps every slug', count( $section ) === count( $slugs ), count( $section ) . '/' . count( $slugs ) );
$still_on = array_filter( $section );
ok( 'no module was switched off', count( $still_on ) === count( $slugs ), count( $still_on ) . '/' . count( $slugs ) );

// Path 2: a settings tab posts section[key] and goes through
// Haa_Settings::save_section() -> sanitize_section().
$clean = Haa_Settings::sanitize_section( 'modules', $all_on );
ok( 'sanitize_section keeps every slug', count( $clean ) === count( $slugs ), count( $clean ) . '/' . count( $slugs ) );

Haa_Settings::save_all( array( 'modules' => $all_on ) );
$stored  = get_option( HAAI_OPTION, array() );
$section = isset( $stored['modules'] ) ? array_filter( (array) $stored['modules'] ) : array();
ok( 'save_all keeps every slug on', count( $section ) === count( $slugs ), count( $section ) . '/' . count( $slugs ) );

// reset() rebuilds from the defaults, so a missing slug would vanish here too.
Haa_Settings::reset();
$after_reset = Haa_Settings::enabled_modules();
ok( 'reset leaves exactly the default module', array( 'chatbot' ) === array_keys( $after_reset ), implode( ',', array_keys( $after_reset ) ) );

echo "\n=== C. diagnose() covers the catalogue ===\n";
Haa_Settings::set_enabled_modules( $all_on );
$module_issues = array();
foreach ( Haa_Settings::diagnose() as $issue ) {
	if ( 0 === strpos( (string) $issue['key'], 'modules.' ) ) {
		$module_issues[] = $issue['key'] . ': ' . $issue['issue'];
	}
}
ok( 'no module issue reported', array() === $module_issues, implode( ' | ', $module_issues ) );

// The Modules screen renders describe_all(); every catalogue slug must reach it.
$described = array();
foreach ( (array) Haa_Modules::describe_all() as $row ) {
	if ( isset( $row['slug'] ) ) {
		$described[] = (string) $row['slug'];
	}
}
ok( 'the Modules screen lists every slug', array() === array_diff( $slugs, $described ), implode( ',', array_diff( $slugs, $described ) ) );
ok( 'no slug is rendered twice', count( $described ) === count( array_unique( $described ) ) );

echo "\n=== D. defaults are off for every module but the chatbot ===\n";
$default_on = array();
foreach ( (array) $defaults['modules'] as $slug => $value ) {
	if ( $value ) {
		$default_on[] = $slug;
	}
}
ok( 'only the chatbot is on by default', array( 'chatbot' ) === $default_on, implode( ',', $default_on ) );
foreach ( array( 'news', 'video-article', 'programmatic' ) as $slug ) {
	ok( "default off: $slug", 0 === (int) ( $defaults['modules'][ $slug ] ?? -1 ) );
}

echo "\n=== E. upgrade from an option that predates the three new modules ===\n";
$legacy = array(
	'chatbot'         => 1,
	'product-content' => 1,
	'article-writer'  => 0,
	'autopilot'       => 0,
	'leads'           => 0,
	'discount'        => 0,
	'comments'        => 0,
	'semantic-search' => 0,
);
$stored            = get_option( HAAI_OPTION, array() );
$stored['modules'] = $legacy;
update_option( HAAI_OPTION, $stored, 'no' );
update_option( Haa_Settings::MODULES_OPTION, array( 'chatbot' => 1, 'product-content' => 1 ), 'yes' );
Haa_Settings::flush_cache();

$read = Haa_Settings::get( 'modules' );
ok( 'reading fills the three new slugs', count( (array) $read ) === count( $slugs ), count( (array) $read ) . '/' . count( $slugs ) );
$new_on = array();
foreach ( array( 'news', 'video-article', 'programmatic' ) as $slug ) {
	if ( ! empty( $read[ $slug ] ) ) {
		$new_on[] = $slug;
	}
}
ok( 'the three new modules stay off after reading', array() === $new_on, implode( ',', $new_on ) );

// A normal admin save posts the whole settings array back.
Haa_Settings::save_all( Haa_Settings::get() );
$after = Haa_Settings::enabled_modules();
ok( 'upgrade keeps exactly the modules that were on', array( 'chatbot', 'product-content' ) === array_keys( $after ), implode( ',', array_keys( $after ) ) );
$section = array_filter( (array) ( get_option( HAAI_OPTION, array() )['modules'] ?? array() ) );
ok( 'stored section mirrors it', array( 'chatbot', 'product-content' ) === array_keys( $section ), implode( ',', array_keys( $section ) ) );

echo "\nFAILURES: $fail\n";
exit( $fail ? 1 : 0 );
