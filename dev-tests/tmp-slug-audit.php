<?php
/**
 * Throwaway audit v2 — the central slug map is the source of truth.
 *
 * Checks:
 *  A. every 'haai-…' string literal used as a whole value is a mapped screen
 *  B. every page reference (page=…, 'page' => …, hidden input value) resolves
 *  C. no page target is built by concatenating 'haai-' with a variable
 *  D. every add_menu_page/add_submenu_page slug argument is mapped or map-built
 */

/** The plugin folder this audit scans: one level above dev-tests/. */
define( 'HAAI_AUDIT_ROOT', rtrim( dirname( __DIR__ ), '\\/' ) . '/' );
define( 'ABSPATH', __DIR__ . '/wp-stub/' ); // class-haa-util.php exits without it.
require_once HAAI_AUDIT_ROOT . 'includes/class-haa-util.php';

$map       = Haa_Util::screens();
$slugs     = array_values( $map );
$files     = array_merge(
	glob( HAAI_AUDIT_ROOT . 'includes/*.php' ),
	glob( HAAI_AUDIT_ROOT . 'modules/*.php' ),
	glob( HAAI_AUDIT_ROOT . 'templates/*.php' ),
	glob( HAAI_AUDIT_ROOT . '*.php' )
);
$problems  = array();
$checked   = 0;

foreach ( $files as $file ) {
	$base = basename( $file );
	if ( 'class-haa-util.php' === $base ) {
		continue; // The map itself.
	}
	$src    = file_get_contents( $file );
	$lines  = preg_split( '/\R/', $src );

	foreach ( $lines as $no => $line ) {
		$at = $file . ':' . ( $no + 1 );

		// Comments describe the old bug in prose; they are not code paths.
		// const/define lines are the one legitimate place a slug is spelled out.
		$trim = trim( $line );
		if ( '' === $trim
			|| 0 === strpos( $trim, '*' ) || 0 === strpos( $trim, '//' ) || 0 === strpos( $trim, '/*' )
			|| preg_match( '/^(const|define)\b/', $trim ) ) {
			continue;
		}

		// A. whole-literal 'haai-…' values.
		if ( preg_match_all( "/([\"\'])haai-([a-z0-9](?:[a-z0-9-]*[a-z0-9])?)\1/", $line, $m ) ) {
			foreach ( $m[2] as $slug ) {
				$literal = 'haai-' . $slug;
				// Only judge literals that look like a screen/page slug.
				if ( in_array( $literal, $slugs, true ) ) {
					$checked++;
					$problems[] = "$at: hard-coded screen slug '$literal' — use Haa_Util::screen()";
					continue;
				}
			}
		}

		// B. page references.
		preg_match_all( '/page=(haai-[a-z0-9-]+)/', $line, $a );
		preg_match_all( "/'page'\s*=>\s*'(haai-[a-z0-9-]+)'/", $line, $b );
		preg_match_all( '/name="page"\s+value="(haai-[a-z0-9-]+)"/', $line, $c );
		foreach ( array_merge( $a[1], $b[1], $c[1] ) as $ref ) {
			$checked++;
			if ( ! in_array( $ref, $slugs, true ) ) {
				$problems[] = "$at: DEAD TARGET '$ref' — no such screen is registered";
			}
		}

		// C. concatenated page targets — the shape that caused the bug.
		if ( preg_match( "/'haai-'\s*\.|page=haai-['\"]\s*\./", $line ) ) {
			$problems[] = "$at: builds a slug by concatenation — cannot be verified";
		}

		// D. menu registration must use the map. The slug is the argument that
		//    follows the capability, so text domains cannot be mistaken for it.
		if ( preg_match( '/add_(?:sub)?menu_page\(/', $line ) ) {
			$window = implode( ' ', array_slice( $lines, $no, 9 ) );
			if ( preg_match( "/'manage_[a-z_]+'\s*,\s*([^,]+?)(?:,|\n)/", $window, $m ) ) {
				$arg = trim( $m[1] );
				$checked++;
				if ( "'" === $arg[0] ) {
					$problems[] = "$at: menu registered with literal $arg — route it through the map";
				} elseif ( false === strpos( $arg, 'Haa_Util::screen(' ) && false === strpos( $arg, 'self::PAGE' ) ) {
					$problems[] = "$at: unexpected menu slug argument: $arg";
				}
			}
		}
	}
}

echo "=== SLUG MAP (" . count( $map ) . " screens) ===\n";
foreach ( $map as $key => $slug ) {
	echo '  ' . str_pad( $key, 16 ) . '=> ' . $slug . "\n";
}

echo "\n=== REFERENCES CHECKED: $checked ===\n";
if ( ! $problems ) {
	echo "  clean — every page reference resolves to a registered, mapped screen\n";
} else {
	foreach ( $problems as $p ) {
		echo '  ' . $p . "\n";
	}
}
echo "\nPROBLEMS: " . count( $problems ) . "\n";
