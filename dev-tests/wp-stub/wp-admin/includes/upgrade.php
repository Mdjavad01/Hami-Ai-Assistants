<?php
/**
 * Harness stand-in for wp-admin/includes/upgrade.php.
 *
 * ABSPATH points at dev-tests/wp-stub/, and Haa_DB::create() / the migrator
 * require this file before calling dbDelta(). There is no database behind the
 * stub, so dbDelta() is a no-op that reports "nothing changed" — the schema
 * work itself is verified on the real bench, not here.
 */
if ( ! function_exists( 'dbDelta' ) ) {
	function dbDelta( $queries = '', $execute = true ) {
		return array();
	}
}
