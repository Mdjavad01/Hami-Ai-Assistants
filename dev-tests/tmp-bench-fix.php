<?php
/**
 * One-shot bench tidy-up: put the flags the test runners flip back to defaults.
 *
 * Usage: wp --path=C:\xampp\htdocs\haai-test eval-file dev-tests/tmp-bench-fix.php
 */
$s = (array) Haa_Settings::get();
$s['advanced']['enable_rag']      = 1;
$s['advanced']['kb_enabled']      = 1;
$s['advanced']['enable_cache']    = 1;
$s['content']['internal_links']   = 1;
$s['provider']['allow_localhost'] = 1; // the documented bench baseline
update_option( HAAI_OPTION, $s, 'no' );
Haa_Settings::flush_cache();

echo 'rag=' . (int) Haa_Settings::get( 'advanced.enable_rag' )
	. ' kb=' . (int) Haa_Settings::get( 'advanced.kb_enabled' )
	. ' cache=' . (int) Haa_Settings::get( 'advanced.enable_cache' )
	. ' ilinks=' . (int) Haa_Settings::get( 'content.internal_links' )
	. ' link_count=' . (int) Haa_Settings::get( 'content.link_count' )
	. ' min_score=' . var_export( Haa_Settings::get( 'search.min_score' ), true )
	. ' mods=' . wp_json_encode( array_keys( array_filter( Haa_Settings::enabled_modules() ) ) )
	. ' provider_base=' . var_export( Haa_Settings::get( 'provider.base_url' ), true )
	. ' allow_localhost=' . (int) Haa_Settings::get( 'provider.allow_localhost' )
	. ' currency=' . get_option( 'woocommerce_currency' )
	. ' diagnose=' . ( is_array( Haa_Settings::diagnose() ) ? count( Haa_Settings::diagnose() ) : -1 )
	. "\n";
