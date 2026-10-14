<?php
/**
 * Throwaway: reformat a flattened stylesheet into one-rule-per-line source.
 *
 * chatbot.css was committed as a single minified line, which makes it
 * unreviewable and makes every future edit a blind string surgery. This expands
 * it and proves the result is the same stylesheet: after whitespace and comments
 * are removed, the bytes must be identical to the input.
 */

$file = $argv[1] ?? '';
if ( '' === $file || ! is_readable( $file ) ) {
	echo "usage: php tmp-css-expand.php <file.css> [--write]\n";
	exit( 2 );
}

$src      = (string) file_get_contents( $file );
$write    = in_array( '--write', (array) $argv, true );
$original = $src;

/**
 * Strip all whitespace outside strings, plus comments: the equivalence key.
 *
 * @param string $css CSS.
 * @return string
 */
function css_key( $css ) {
	$css = preg_replace( '#/\*.*?\*/#s', '', (string) $css );
	$out = '';
	$in  = false;
	$q   = '';
	$len = strlen( (string) $css );
	for ( $i = 0; $i < $len; $i++ ) {
		$c = $css[ $i ];
		if ( $in ) {
			$out .= $c;
			if ( $c === $q && $css[ $i - 1 ] !== '\\' ) {
				$in = false;
			}
			continue;
		}
		if ( '"' === $c || '\'' === $c ) {
			$in = true;
			$q  = $c;
			$out .= $c;
			continue;
		}
		if ( ! ctype_space( $c ) ) {
			$out .= $c;
		}
	}
	return (string) $out;
}

/*
 * Split into top-level pieces, keeping at-rule nesting intact. Anything inside
 * @media stays grouped so the indentation reads correctly.
 */
function split_rules( $css ) {
	$out   = array();
	$buf   = '';
	$depth = 0;
	$len   = strlen( (string) $css );
	for ( $i = 0; $i < $len; $i++ ) {
		$c    = $css[ $i ];
		$buf .= $c;
		if ( '{' === $c ) {
			$depth++;
			continue;
		}
		if ( '}' === $c ) {
			$depth--;
			if ( 0 === $depth ) {
				$out[] = trim( $buf );
				$buf   = '';
			}
		}
	}
	if ( '' !== trim( $buf ) ) {
		$out[] = trim( $buf );
	}
	return $out;
}

/**
 * Family key for a rule: first class in the selector, truncated at the
 * element/modifier boundary. Used only to decide where to put a comment.
 *
 * @param string $rule CSS rule text.
 * @return string
 */
function family_of( $rule ) {
	if ( 0 === strpos( $rule, '@' ) ) {
		return '@';
	}
	if ( preg_match( '/\.([a-zA-Z][a-zA-Z0-9_-]*)/', $rule, $m ) ) {
		$c = (string) $m[1];
		$c = preg_replace( '/(--|__).*$/', '', (string) $c );
		return (string) $c;
	}
	return preg_match( '/^([a-zA-Z][a-zA-Z0-9-]*)\s*\{/', $rule, $m2 ) ? (string) $m2[1] : '#other';
}

$pieces = split_rules( $src );
$lines  = array();
$prev   = null;
$indent = '';

foreach ( $pieces as $piece ) {
	// At-rule with nested rules: header, children, closing brace.
	if ( preg_match( '/^@(media|supports|keyframes|font-face)[^{]*\{\s*(.*)\s*\}$/s', $piece, $m ) ) {
		$header   = trim( (string) $m[0] );
		$inner    = (string) $m[2];
		$atrule   = (string) $m[1];
		$preamble = preg_replace( '/\{\s*' . preg_quote( $inner, '/' ) . '\s*\}$/s', '', $header );
		$preamble = trim( (string) $preamble );

		$lines[] = $preamble . ' {';
		foreach ( split_rules( $inner ) as $sub ) {
			$fam = family_of( $sub );
			if ( '@' !== $fam && $fam !== $prev ) {
				$lines[] = '	/* .' . $fam . ' */';
				$prev    = $fam;
			}
			// Normalise the sub-rule onto one line with a space before the brace.
			$sub = (string) preg_replace( '/\s*\{\s*/', ' { ', (string) $sub );
			$sub = (string) preg_replace( '/\s*\}\s*$/', ' }', $sub );
			$sub = (string) preg_replace( '/;\s*/', '; ', $sub );
			$lines[] = '	' . trim( (string) preg_replace( '/\s+/', ' ', $sub ) );
		}
		$lines[] = '}';
		$lines[] = '';
		if ( 'keyframes' !== $atrule ) {
			$prev = null;
		}
		continue;
	}

	$one = (string) preg_replace( '/\s*\{\s*/', ' { ', (string) $piece );
	$one = (string) preg_replace( '/\s*\}\s*$/', ' }', $one );
	$one = (string) preg_replace( '/;\s*/', '; ', $one );
	$one = trim( (string) preg_replace( '/\s+/', ' ', $one ) );

	$fam = family_of( $one );
	if ( '@' !== $fam && $fam !== $prev ) {
		$lines[] = '';
		$lines[] = '/* .' . $fam . ' */';
		$prev    = $fam;
	}
	$lines[] = $one;
	$indent  = '';
}

$result = rtrim( implode( "\n", $lines ) ) . "\n" . $indent;

$before = css_key( $original );
$after  = css_key( $result );

printf( "input %d B  ->  output %d B\n", strlen( $original ), strlen( $result ) );
printf( "equivalent ignoring whitespace/comments: %s\n", $before === $after ? 'YES' : 'NO' );

if ( $before !== $after ) {
	// Point at the first divergence so the bug is fixable, not just visible.
	$n = min( strlen( $before ), strlen( $after ) );
	for ( $i = 0; $i < $n; $i++ ) {
		if ( $before[ $i ] !== $after[ $i ] ) {
			echo "  differs at $i:\n    was: " . substr( $before, max( 0, $i - 40 ), 90 ) . "\n    now: " . substr( $after, max( 0, $i - 40 ), 90 ) . "\n";
			break;
		}
	}
	echo "REFUSING TO WRITE\n";
	exit( 1 );
}

if ( $write ) {
	file_put_contents( $file, $result );
	echo "written\n";
} else {
	echo "dry run (pass --write)\n";
}
exit( 0 );
