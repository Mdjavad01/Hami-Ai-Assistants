<?php
/**
 * Bench tool: inventory of every entry point the plugin registers at runtime.
 * Run: php wp-cli.phar eval-file dev-tests/wp-audit-entrypoints.php --path=C:\xampp\htdocs\haai-test
 */
$out = array();

// 1. AJAX + admin-post actions.
global $wp_filter;
foreach ( (array) $wp_filter as $tag => $hook ) {
	if ( 0 === strpos( $tag, 'wp_ajax_' ) || 0 === strpos( $tag, 'admin_post_' ) ) {
		$cbs = array();
		foreach ( (array) $hook->callbacks as $prio => $reg ) {
			foreach ( (array) $reg as $cb ) {
				$f = $cb['function'];
				if ( is_array( $f ) ) {
					$cbs[] = ( is_object( $f[0] ) ? get_class( $f[0] ) : (string) $f[0] ) . '::' . (string) $f[1];
				} elseif ( $f instanceof Closure ) {
					$cbs[] = 'closure';
				} else {
					$cbs[] = (string) $f;
				}
			}
		}
		$joined = implode( ' ', $cbs );
		if ( $joined && ( false !== stripos( $joined, 'haa' ) || false !== stripos( $joined, 'hami' ) ) ) {
			$out['actions'][] = array( 'tag' => $tag, 'cb' => implode( ',', array_unique( $cbs ) ) );
		}
	}
}

// 2. REST routes.
$server = rest_get_server();
$routes = array();
foreach ( (array) $server->get_routes() as $route => $handlers ) {
	if ( false !== stripos( $route, 'haa' ) || false !== stripos( $route, 'hami' ) ) {
		$row = array( 'route' => $route );
		foreach ( (array) $handlers as $h ) {
			if ( isset( $h['methods'] ) ) { $row['methods'] = implode( ',', array_keys( (array) $h['methods'] ) ); }
			if ( isset( $h['callback'] ) ) {
				$f = $h['callback'];
				$row['cb'] = is_array( $f ) && isset( $f[0] ) ? ( is_object( $f[0] ) ? get_class( $f[0] ) : (string) $f[0] ) . '::' . (string) $f[1] : ( is_string( $f ) ? $f : 'closure' );
			}
			if ( isset( $h['permission_callback'] ) ) {
				$p = $h['permission_callback'];
				$row['perm'] = is_array( $p ) && isset( $p[0] ) ? ( is_object( $p[0] ) ? get_class( $p[0] ) : (string) $p[0] ) . '::' . (string) $p[1] : ( is_string( $p ) ? $p : ( $p ? 'callable' : 'MISSING' ) );
			}
		}
		$routes[] = $row;
	}
}
$out['rest'] = $routes;

// 3. Shortcodes.
global $shortcode_tags;
foreach ( (array) $shortcode_tags as $tag => $cb ) {
	$name = is_array( $cb ) && isset( $cb[0] ) ? ( is_object( $cb[0] ) ? get_class( $cb[0] ) : (string) $cb[0] ) . '::' . (string) $cb[1] : ( is_string( $cb ) ? $cb : 'closure' );
	if ( false !== stripos( $name, 'haa' ) ) {
		$out['shortcodes'][] = array( 'tag' => $tag, 'cb' => $name );
	}
}

// 4. Cron.
$cron = _get_cron_array();
$seen = array();
foreach ( (array) $cron as $ts => $hooks ) {
	foreach ( array_keys( (array) $hooks ) as $hook ) {
		if ( false !== stripos( $hook, 'haa' ) ) {
			$seen[ $hook ] = gmdate( 'Y-m-d H:i:s', $ts );
		}
	}
}
$out['cron'] = $seen;

// 5. Admin menu.
global $menu, $submenu;
foreach ( (array) $menu as $m ) {
	if ( is_array( $m ) && isset( $m[2] ) && false !== stripos( (string) $m[2], 'haai' ) ) {
		$out['menu'][] = $m[2];
	}
}

echo wp_json_encode( $out, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT ) . "\n";
