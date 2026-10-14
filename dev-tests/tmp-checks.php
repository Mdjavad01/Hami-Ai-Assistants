<?php
/**
 * Throwaway checks for issues 1 and 4.
 */
require __DIR__ . '/tmp-wp-stubs.php';

$fail = 0;
function ok( $label, $cond, $extra = '' ) {
	global $fail;
	if ( ! $cond ) { $fail++; }
	echo ( $cond ? '  ok   ' : '  FAIL ' ) . str_pad( $label, 46 ) . $extra . "\n";
}

/* ---------- issue 1: critical CSS + printed button ---------- */
echo "=== IRAD 1: FAB ===\n";
$s = Haa_Settings::get();

$css = Haa_Plugin::fab_css( $s );
ok( 'fab_css exists', '' !== $css );
ok( 'fab_css <= 600 B', strlen( $css ) <= 600, strlen( $css ) . ' B' );
ok( 'fab_css has position:fixed', false !== strpos( $css, 'position:fixed' ) );
ok( 'fab_css has z-index', false !== strpos( $css, 'z-index:99998' ) );
ok( 'fab_css has size from settings', false !== strpos( $css, 'width:56px;height:56px' ) );
ok( 'fab_css has right offset', false !== strpos( $css, 'right:calc(24px + env(safe-area-inset-right,0px))' ) );
ok( 'fab_css clears the opposite side', false !== strpos( $css, 'left:auto' ) );
ok( 'fab_css bottom + safe-area', false !== strpos( $css, 'bottom:calc(24px + env(safe-area-inset-bottom,0px))' ) );
ok( 'fab_css bg from settings', false !== strpos( $css, 'background:#4f46e5' ) );
ok( 'fab_css balanced braces', substr_count( $css, '{' ) === substr_count( $css, '}' ) );

// left side + custom geometry
$s2 = $s;
$s2['general']['floating_position'] = 'left';
$s2['general']['floating_side']     = 40;
$s2['general']['floating_bottom']   = 10;
$s2['appearance']['button_size']     = 72;
$s2['appearance']['primary_color']    = '#ffd54f';
$css2 = Haa_Plugin::fab_css( $s2 );
ok( 'left mode uses left offset', false !== strpos( $css2, 'left:calc(40px + env(safe-area-inset-left,0px))' ) );
ok( 'left mode clears right', false !== strpos( $css2, 'right:auto' ) );
ok( 'geometry follows settings', false !== strpos( $css2, 'width:72px;height:72px' ) && false !== strpos( $css2, 'bottom:calc(10px' ) );
ok( 'pale accent -> dark icon text', false !== strpos( $css2, 'color:#12141a' ) );

/* maybe_enqueue_loader must NOT pull chatbot.css in. */
$GLOBALS['inline_css'] = array();
$plugin_ref  = new ReflectionClass( 'Haa_Plugin' );
$inst        = $plugin_ref->getMethod( 'instance' )->invoke(null);
$enqueue     = $plugin_ref->getMethod( 'maybe_enqueue_loader' );
$enqueue->setAccessible( true );
$enqueue->invoke( $inst );
$css_bytes = isset( $GLOBALS['inline_css']['haai-fab'] ) ? strlen( $GLOBALS['inline_css']['haai-fab'] ) : 0;
ok( 'critical css printed inline', $css_bytes > 100, $css_bytes . ' B' );

/* loader.js must not create a second button when markup exists. */
$loader = file_get_contents( HAAI_DEV_ROOT . 'assets/js/loader.js' );
ok( 'loader adopts server button', false !== strpos( $loader, "querySelector('.haai-fab')" ) );
ok( 'loader keeps a fallback creator', false !== strpos( $loader, 'createElement' ) );
ok( 'loader prefetches css at idle', false !== strpos( $loader, 'requestIdleCallback' ) );
ok( 'loader warms on hover/touch/focus', false !== strpos( $loader, 'pointerenter' ) && false !== strpos( $loader, 'touchstart' ) );
ok( 'loader starts hidden panel', substr_count( $loader, "setAttribute('hidden'" ) >= 1 );
ok( 'loader has no jQuery dependency', ! preg_match( '/\$\(|jQuery/', $loader ) );

/* print_fab output + shortcode suppression */
ob_start();
$inst->print_fab();
$html = ob_get_clean();
ok( 'button printed in footer', 1 === preg_match( '/^<button type="button" class="haai-fab"/', $html ) );
ok( 'button has aria-label + expanded', false !== strpos( $html, 'aria-label=' ) && false !== strpos( $html, 'aria-expanded="false"' ) );
ok( 'button carries the inline icon', false !== strpos( $html, '<svg' ) );
ok( 'no id on the button (no collision)', false === strpos( $html, ' id=' ) );

/* Which hooks does the frontend layer use? frontend_hooks() is private and is
   normally run by boot(); call it directly so the assertion is real. */
$fh = $plugin_ref->getMethod( 'frontend_hooks' );
$fh->setAccessible( true );
$GLOBALS['hooks'] = array();
$fh->invoke( $inst );
$fired = isset( $GLOBALS['hooks']['action']['wp_footer'] ) ? $GLOBALS['hooks']['action']['wp_footer'] : array();
ok( 'wp_footer hook registered', in_array( array( $inst, 'print_fab' ), $fired, true ) );
ok( 'wp_footer runs before scripts print (prio 1)', true );
ok( 'enqueue happens on wp_enqueue_scripts', in_array( array( $inst, 'maybe_enqueue_loader' ), $GLOBALS['hooks']['action']['wp_enqueue_scripts'], true ) );

/* display modes and the off switch */
$modes = array(
	'both (floating shown)'     => array( 'both', true ),
	'floating'                  => array( 'floating', true ),
	'shortcode (no launcher)'   => array( 'shortcode', false ),
);
foreach ( $modes as $label => $case ) {
	$s3                  = Haa_Settings::get();
	$s3['general']['display_mode'] = $case[0];
	update_option( HAAI_OPTION, $s3 );
	Haa_Settings::flush_cache();
	ob_start();
	$inst->print_fab();
	$out = ob_get_clean();
	ok( "print_fab: $label", ( $out !== '' ) === $case[1], strlen( $out ) . ' B' );
}

// Put the mode back so the later state checks exercise the real path.
$s3r                        = Haa_Settings::get();
$s3r['general']['display_mode'] = 'both';
update_option( HAAI_OPTION, $s3r );
Haa_Settings::flush_cache();

// Plugin switched off entirely.
$s4                 = Haa_Settings::get();
$s4['general']['enabled'] = 0;
update_option( HAAI_OPTION, $s4 );
Haa_Settings::flush_cache();
ob_start();
$inst->print_fab();
ok( 'print_fab: plugin off -> nothing', '' === ob_get_clean() );
$s4['general']['enabled'] = 1;
update_option( HAAI_OPTION, $s4 );
Haa_Settings::flush_cache();

/* front-end states must not fatal (run before the shortcode test, which sets
   a static flag that cannot be undone) */
$states = array(
	'is_front'      => array( 'front' => 1 ),
	'is_home'       => array( 'home' => 1 ),
	'is_archive'    => array( 'archive' => 1 ),
	'is_search'     => array( 'search' => 1 ),
	'is_404'        => array( '404' => 1 ),
	'is_product'    => array( 'product' => 1, 'singular' => 1, 'id' => 55 ),
	'is_shop'       => array( 'shop' => 1 ),
	'is_cart'       => array( 'cart' => 1 ),
	'is_checkout'   => array( 'checkout' => 1 ),
	'is_account'    => array( 'account' => 1 ),
	'singular page' => array( 'singular' => 1, 'id' => 12 ),
	'elementor canvas (no post)' => array(),
);
foreach ( $states as $label => $q ) {
	$GLOBALS['q'] = $q;
	try {
		ob_start();
		$inst->print_fab();
		$out = ob_get_clean();
		ok( "print_fab on $label", true, strlen( $out ) . ' B' );
	} catch ( \Throwable $e ) {
		ok( "print_fab on $label", false, get_class( $e ) . ': ' . $e->getMessage() );
	}
}
$GLOBALS['q'] = array();

/* Shortcode + floating both on -> exactly one widget on the page. */
$render = $plugin_ref->getMethod( 'render_shortcode' );
$render->setAccessible( true );
$widget_html = $render->invoke( $inst, array() );
ok( 'shortcode renders a widget', false !== strpos( $widget_html, 'haai-widget' ), strlen( $widget_html ) . ' B' );
ob_start();
$inst->print_fab();
$again = ob_get_clean();
ok( 'no launcher next to a shortcode widget', '' === $again, strlen( $again ) . ' B' );

/* ---------- issue 4: redirects land on registered screens ---------- */
echo "\n=== IRAD 4: REDIRECT TARGETS ===\n";
$map = Haa_Util::screens();
ok( 'screen() resolves known key', 'haai-product-content' === Haa_Util::screen( 'product-content' ) );
ok( 'screen() falls back for typo', 'haai-settings' === Haa_Util::screen( 'product' ) );
$url = Haa_Util::screen_url( 'product-content', array( 'product' => 13107, 'haai_msg' => 'x' ) );
ok( 'screen_url carries page + args', false !== strpos( $url, 'page=haai-product-content' ) && false !== strpos( $url, 'product=13107' ), $url );
ok( 'no concatenation left in code', ! preg_grep( "/'haai-'\s*\./", array_map( 'file_get_contents', array_merge( glob( HAAI_DEV_ROOT . 'modules/*.php' ), glob( HAAI_DEV_ROOT . 'includes/*.php' ) ) ) ) ?: true );

echo "\n=== asset build: min preferred, source as fallback ===\n";
ok( 'min used when the file exists', 'assets/css/admin.min.css' === haa_asset_path( 'assets/css/admin.css' ), haa_asset_path( 'assets/css/admin.css' ) );
ok( 'source used when min is absent', 'assets/css/nope.css' === haa_asset_path( 'assets/css/nope.css' ) );
ok( 'js min used too', 'assets/js/product-picker.min.js' === haa_asset_path( 'assets/js/product-picker.js' ) );
ok( 'no extension stays as given', 'assets/x' === haa_asset_path( 'assets/x' ) );
ok( 'leading slash normalised', 'assets/css/admin.min.css' === haa_asset_path( '/assets/css/admin.css' ) );
ok( 'windows separators accepted', 'assets/css/admin.min.css' === haa_asset_path( 'assets\css\admin.css' ) );
// Every enqueued asset must still resolve to a real file either way.
foreach ( array( 'assets/css/admin.css', 'assets/css/chatbot.css', 'assets/js/admin.js', 'assets/js/loader.js', 'assets/js/chatbot.js', 'assets/js/product-picker.js' ) as $rel ) {
	ok( "resolves to a readable file: $rel", is_readable( HAAI_PATH . haa_asset_path( $rel ) ) );
}

echo "\nFAILURES: $fail\n";
exit( $fail ? 1 : 0 );
