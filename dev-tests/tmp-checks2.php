<?php
/**
 * Throwaway checks for ایراد ۲ (page visibility rules) + a CSS/JS class audit.
 */
require __DIR__ . '/tmp-wp-stubs.php';

$fail = 0;
function ok( $label, $cond, $extra = '' ) {
	global $fail;
	if ( ! $cond ) { $fail++; }
	echo ( $cond ? '  ok   ' : '  FAIL ' ) . str_pad( $label, 52 ) . $extra . "\n";
}

/** True for a hand-written asset, false for a tools/minify.mjs build product. */
function is_source_asset( $path ) {
	return false === strpos( basename( $path ), '.min.' );
}

$GLOBALS['posts'] = array(
	array( 'id' => 2, 'title' => 'درباره ما', 'type' => 'page' ),
	array( 'id' => 7, 'title' => 'تماس با ما', 'type' => 'page', 'parent' => 2 ),
);

function scenario( $q, $roles, $logged_out, $settings_tweak = array() ) {
	$GLOBALS['q']     = $q;
	$GLOBALS['roles'] = $roles;
	$GLOBALS['logged_out'] = $logged_out;
	$s = Haa_Settings::get();
	foreach ( $settings_tweak as $k => $v ) {
		$p = explode( '.', $k );
		$s[ $p[0] ][ $p[1] ] = $v;
	}
	update_option( HAAI_OPTION, $s );
	Haa_Settings::flush_cache();
	return Haa_Plugin::should_display( Haa_Settings::get() );
}

echo "=== IRAD 2: pages_mode derivation ===\n";
$mk = function ( $in, $out ) {
	return array( 'general' => array( 'show_on_pages' => $in, 'exclude_pages' => $out, 'pages_mode' => '' ) );
};
$cases = array(
	'legacy: both lists -> exclude wins (as before)' => array( array( 5 ), array( 9 ), 'exclude' ),
	'legacy: include only'                          => array( array( 5 ), array(), 'include' ),
	'legacy: exclude only'                          => array( array(), array( 9 ), 'exclude' ),
	'legacy: neither'                               => array( array(), array(), 'all' ),
);
foreach ( $cases as $label => $c ) {
	$got = Haa_Plugin::pages_mode( array( 'general' => $mk( $c[0], $c[1] )['general'] ) );
	ok( $label, $got === $c[2], "got '$got'" );
}
ok(
	'explicit mode beats derivation',
	'all' === Haa_Plugin::pages_mode( array( 'general' => array( 'pages_mode' => 'all', 'exclude_pages' => array( 9 ) ) ) )
);

echo "\n=== IRAD 2: front-end evaluation ===\n";
$base = array( 'general.show_roles' => array(), 'general.special_pages' => array(), 'general.show_on_post_types' => array( 'post', 'page', 'product' ) );

// array_replace, not `+`: the plus operator keeps the LEFT value, so an
// override of a key already present in $base would silently do nothing.
$with = function ( $extra ) use ( $base ) { return array_replace( $base, $extra ); };

// Rule = all.
$t = $with( array( 'general.pages_mode' => 'all' ) );
ok( 'all: singular page', true === scenario( array( 'singular' => 1, 'id' => 12 ), array(), false, $t ) );
ok( 'all: front page', true === scenario( array( 'front' => 1 ), array(), false, $t ) );
ok( 'all: shop page', true === scenario( array( 'shop' => 1 ), array(), false, $t ) );
ok( 'all: 404', true === scenario( array( '404' => 1 ), array(), false, $t ) );

// Rule = include, one page + one special.
$t = $with( array(
	'general.pages_mode'    => 'include',
	'general.show_on_pages' => array( 12 ),
	'general.special_pages' => array( 'shop' ),
) );
ok( 'include: listed page shows', true === scenario( array( 'singular' => 1, 'id' => 12 ), array(), false, $t ) );
ok( 'include: other page hidden', false === scenario( array( 'singular' => 1, 'id' => 99 ), array(), false, $t ) );
ok( 'include: chosen special shows', true === scenario( array( 'shop' => 1 ), array(), false, $t ) );
ok( 'include: other special hidden', false === scenario( array( 'cart' => 1 ), array(), false, $t ) );
ok( 'include: front page hidden', false === scenario( array( 'front' => 1 ), array(), false, $t ) );

// Rule = exclude.
$t = $with( array(
	'general.pages_mode'    => 'exclude',
	'general.exclude_pages' => array( 12 ),
	'general.special_pages' => array( 'checkout' ),
) );
ok( 'exclude: listed page hidden', false === scenario( array( 'singular' => 1, 'id' => 12 ), array(), false, $t ) );
ok( 'exclude: other page shows', true === scenario( array( 'singular' => 1, 'id' => 7 ), array(), false, $t ) );
ok( 'exclude: chosen special hidden', false === scenario( array( 'checkout' => 1 ), array(), false, $t ) );
ok( 'exclude: other special shows', true === scenario( array( 'cart' => 1 ), array(), false, $t ) );

// Post types still gate singular documents only.
$t = $with( array( 'general.pages_mode' => 'all', 'general.show_on_post_types' => array( 'post' ) ) );
$GLOBALS['pt'] = array( 12 => 'page', 55 => 'product' );
ok( 'post-type: page excluded when only post chosen', false === scenario( array( 'singular' => 1, 'id' => 12 ), array(), false, $t ) );
ok( 'post-type: product excluded too', false === scenario( array( 'singular' => 1, 'id' => 55 ), array(), false, $t ) );
$GLOBALS['pt'] = array();

// Roles, including the guest pseudo-role.
$t = $with( array( 'general.pages_mode' => 'all', 'general.show_roles' => array( 'customer' ) ) );
ok( 'role: customer sees it', true === scenario( array( 'front' => 1 ), array( 'customer' ), false, $t ) );
ok( 'role: subscriber does not', false === scenario( array( 'front' => 1 ), array( 'subscriber' ), false, $t ) );
ok( 'role: logged-out guest does not', false === scenario( array( 'front' => 1 ), array(), true, $t ) );
$t = $with( array( 'general.pages_mode' => 'all', 'general.show_roles' => array( 'guest' ) ) );
ok( 'guest pseudo-role: logged out sees it', true === scenario( array( 'front' => 1 ), array(), true, $t ) );
ok( 'guest pseudo-role: member does not', false === scenario( array( 'front' => 1 ), array( 'customer' ), false, $t ) );

echo "\n=== IRAD 2: general tab markup ===\n";
$GLOBALS['q'] = array();
$GLOBALS['logged_out'] = false;
$GLOBALS['roles'] = array();
$s = Haa_Settings::get();
$s['general']['show_on_pages'] = array( 2, 7 );
$s['general']['exclude_pages'] = array();
$s['general']['special_pages'] = array( 'shop' );
$s['general']['show_roles'] = array( 'guest', 'customer' );
update_option( HAAI_OPTION, $s );
Haa_Settings::flush_cache();

$GLOBALS['hooks'] = array();
$_GET['tab']      = 'general';
$admin            = new Haa_Admin();
ob_start();
$admin->render_page();
$html = ob_get_clean();

foreach ( array( 'general[enabled]', 'general[display_mode]', 'general[floating_position]', 'general[floating_bottom]', 'general[floating_side]', 'general[show_devices][]', 'general[show_roles][]', 'general[show_on_post_types][]', 'general[pages_mode]', 'general[special_pages][]', 'general[show_on_pages][]' ) as $needle ) {
	ok( 'input name present: ' . $needle, false !== strpos( $html, 'name="' . $needle . '"' ) );
}
// An empty picker has nothing to carry, so no input at all — and clearing the
// last chip must still be able to save an empty list.
ok( 'empty picker renders no stale input', false === strpos( $html, 'name="general[exclude_pages][]"' ) );
ok( 'clearing the last chip saves empty', ( function () {
	Haa_Settings::save_section( 'general', array( 'enabled' => '1', 'pages_mode' => 'exclude', 'exclude_pages' => array() ) );
	$s = Haa_Settings::get();
	return array() === $s['general']['exclude_pages'];
} )() );
ok( 'one radio per rule', 3 === preg_match_all( '/name="general\[pages_mode\]"/', $html ) );
ok( 'derived radio is checked', 1 === preg_match_all( '/name="general\[pages_mode\]" value="[^"]+"\s+checked="checked"/', $html ) );
ok( 'guest chip rendered', false !== strpos( $html, 'value="guest"' ) );
ok( 'selected pages became chips with ids', preg_match_all( '/name="general\[show_on_pages\]\[\]" value="(2|7)"/', $html, $mm ) >= 2 );
ok( 'picker AJAX url + nonce present', false !== strpos( $html, 'action=haai_page_search' ) && false !== strpos( $html, 'nonce=' ) );
ok( 'no 200-item select left', false === strpos( $html, '<select multiple size="6"' ) );
ok( 'summary line rendered', false !== strpos( $html, 'haai-summary' ) && preg_match( '/نمایش در/u', $html ) );
ok( 'elementor internals hidden from post types', false === strpos( $html, 'value="e-slide"' ) && false === strpos( $html, 'value="wp_block"' ) );
ok( 'public types kept', ( false !== strpos( $html, 'value="product"' ) && false !== strpos( $html, 'value="page"' ) ) );
ok( 'each picker declares its rule', 2 === preg_match_all( '/data-haai-show="(include|exclude)"/', $html ) );
ok( 'special pages follow any list rule', false !== strpos( $html, 'data-haai-show="any"' ) );

echo "\n=== IRAD 2: saving clears the unused list ===\n";
Haa_Settings::save_section(
	'general',
	array(
		'enabled'       => '1',
		'pages_mode'    => 'include',
		'show_on_pages' => array( '2' ),
		'exclude_pages' => array( '7' ),
		'special_pages' => array( 'shop' ),
	)
);
$saved = Haa_Settings::get();
ok( 'include mode empties the exclude list', array() === $saved['general']['exclude_pages'], json_encode( $saved['general']['exclude_pages'] ) );
ok( 'include list kept', array( 2 ) === $saved['general']['show_on_pages'] );
Haa_Settings::save_section( 'general', array( 'enabled' => '1', 'pages_mode' => 'all', 'show_on_pages' => array( '2' ), 'exclude_pages' => array( '7' ) ) );
$saved = Haa_Settings::get();
ok( 'all mode empties both lists', array() === $saved['general']['show_on_pages'] && array() === $saved['general']['exclude_pages'] );

echo "\n=== class audit (CSS vs markup) ===\n";
$css   = file_get_contents( HAAI_DEV_ROOT . 'assets/css/admin.css' ) . file_get_contents( HAAI_DEV_ROOT . 'assets/css/chatbot.css' );
// Built *.min.* siblings carry the same class names; only sources are audited.
$jssrc = array_filter( glob( HAAI_DEV_ROOT . 'assets/js/*.js' ), 'is_source_asset' );
$js    = implode( '', array_map( 'file_get_contents', $jssrc ) ) . $html;
$php   = implode( '', array_map( 'file_get_contents', array_merge( glob( HAAI_DEV_ROOT . 'includes/*.php' ), glob( HAAI_DEV_ROOT . 'modules/*.php' ), glob( HAAI_DEV_ROOT . 'templates/*.php' ), glob( HAAI_DEV_ROOT . '*.php' ) ) ) );
$hay   = $php . $js;

preg_match_all( '/\.(haai|hai|hami)-[A-Za-z0-9_-]+/', $css, $m );
$defined = array_values( array_unique( $m[0] ) );
$typos   = preg_grep( '/^\.hai-|^\.hami-/', $defined );
echo '  suspicious selector typos in CSS: ' . ( $typos ? implode( ', ', $typos ) : 'none' ) . "\n";
if ( $typos ) { $fail++; }

$unused = array();
foreach ( $defined as $sel ) {
	$name = substr( $sel, 1 );
	if ( false === strpos( $hay, $name ) ) { $unused[] = $name; }
}
echo '  CSS classes with no reference in php/js (' . count( $unused ) . '): ' . ( $unused ? implode( ' ', $unused ) : 'none' ) . "\n";

// Markup classes that no CSS defines (only haai- ones, admin screens).
preg_match_all( '/class="[^"]*"/', $html, $cm );
$used = array();
foreach ( $cm[0] as $attr ) {
	foreach ( preg_split( '/\s+/', trim( str_replace( array( 'class="', '"' ), '', $attr ) ) ) as $c ) {
		if ( '' !== $c ) { $used[ $c ] = true; }
	}
}
$nostyle = array();
foreach ( array_keys( $used ) as $c ) {
	if ( false === strpos( $css, '.' . $c ) ) { $nostyle[] = $c; }
}
echo '  rendered classes with no CSS rule: ' . ( $nostyle ? implode( ' ', $nostyle ) : 'none' ) . "\n";

echo "\nFAILURES: $fail\n";
exit( $fail ? 1 : 0 );
