<?php
/**
 * CSS class audit — the contract between markup and stylesheets.
 *
 * Reports:
 *   1. UNDEFINED  class written in markup/JS but styled in the wrong (or no) sheet → must be 0
 *   2. MISPLACED  widget class styled only by admin.css, or admin class only by chatbot.css → must be 0
 *   3. UNUSED     selector defined but never used                                   → advisory, cleanup list
 *
 * Extraction is deliberately narrow: only `class="…"` attributes, PHP/JS variables
 * whose name contains "class", and JS classList / className / querySelector calls.
 * Screen slugs (haai-settings), script handles (haai-loader), element ids and CSS
 * custom properties (--haai-primary) all share the haai- prefix but are not
 * classes; a wider scan reports them as missing and the noise hides the real ones.
 *
 * WordPress' own classes are declared in $host_classes: this plugin ships no CSS
 * for them on purpose, and pretending otherwise would force dead rules.
 *
 * Usage:
 *   php dev-tests/tmp-css-audit.php [--quiet-unused] [--self-test] [--json]
 * Exit 0 only when UNDEFINED and MISPLACED are both empty.
 */

if ( 'cli' !== PHP_SAPI ) {
	echo "CLI only\n";
	exit( 1 );
}

$root = dirname( __DIR__ ) . '/';

/** Classes supplied by WordPress admin or the theme, intentionally not ours to style. */
$host_classes = array(
	'wrap', 'nav-tab', 'nav-tab-wrapper', 'nav-tab-active', 'notice', 'notice-success', 'notice-error',
	'notice-warning', 'notice-info', 'notice-alt', 'is-dismissible', 'inline', 'large-text', 'regular-text',
	'small-text', 'code', 'screen-reader-text', 'button', 'button-primary', 'button-secondary',
	'button-link', 'button-link-delete', 'button-small', 'tablenav', 'tablenav-pages', 'striped',
	'alignleft', 'alignright', 'field', 'description', 'form-table', 'closed', 'active', 'inactive',
	'spinner', 'has-spinner', 'page-title-action', 'misc-publishing-actions', 'dashicons',
	'widefat', 'current', 'submit', 'regular', 'large', 'small', 'nav', 'tabs', 'postbox', 'inside',
	'hndle', 'handlediv', 'toggle-arrow', 'closed', 'spinner',
);

/**
 * Class tokens written by a document.
 *
 * @param string $text File contents.
 * @return array<string,bool> token => true
 */
function extract_classes( $text, $allow_single = array() ) {
	$text  = (string) $text;
	$found = array();

	$add = static function ( $chunk ) use ( &$found ) {
		// Keep the content of PHP holes: a class attribute like
		//   class="haai-x [php echo $ok ? 'is-on' : 'is-off' ]"
		// really does emit is-on/is-off, and throwing the hole away would report
		// those selectors as dead CSS. Only delimiters, operators and variables go.
		$chunk = (string) preg_replace( '/<\?php|<\?=|\?>|[<>=;"\'`$]|{{[^}]*}}/s', ' ', $chunk );
		$chunk = (string) preg_replace( '/\b(echo|print)\b/', ' ', $chunk );
		foreach ( preg_split( '/\s+/', trim( $chunk ) ) as $tok ) {
			if ( '' === $tok || ! preg_match( '/^[a-zA-Z][a-zA-Z0-9_-]*$/', $tok ) ) {
				continue;
			}
			$found[ $tok ] = true;
		}
	};

	// A selector string with no dotted class is an element/attribute selector
	// (`querySelector('img')`): taking its words as classes invents findings.
	$add_selector = static function ( $chunk ) use ( &$found ) {
		if ( preg_match_all( '#\.([a-zA-Z][a-zA-Z0-9_-]*)#', (string) $chunk, $ms ) ) {
			foreach ( (array) $ms[1] as $tok ) {
				$found[ $tok ] = true;
			}
		}
	};

	// 1. class="a b c" in any flavour of quote, PHP echoes inside it allowed.
	if ( preg_match_all( '/\bclass\s*=\s*(["\'])(.*?)\1/is', $text, $m ) ) {
		foreach ( (array) $m[2] as $chunk ) {
			$add( (string) $chunk );
		}
	}

	// 2. PHP/JS variables that hold a class list: $classes = '...', $cls = "...".
	if ( preg_match_all( '/[$][a-zA-Z_]*class[a-zA-Z_]*\s*=\s*[\'"]([^\'"]*)[\'"]/i', $text, $m2 ) ) {
		foreach ( (array) $m2[1] as $chunk ) {
			$add( (string) $chunk );
		}
	}
	if ( preg_match_all( '/\b(?:cls|klass|classes)\s*=\s*[\'"]([^\'"]*)[\'"]/i', $text, $m3 ) ) {
		foreach ( (array) $m3[1] as $chunk ) {
			$add( (string) $chunk );
		}
	}

	// 3. JS DOM APIs. Each alternative is its own regex: one mega-alternation with
	// nested groups is easy to unbalance and fails silently.
	$js_class = array(
		'/classList\s*\.\s*(?:add|remove|toggle)\s*\(\s*([^)]*)\)/s',
		'/className\s*=\s*([\'"])([^\'"]*)\1/s',
		'/\bclass(?:Name)?\s*:\s*([\'"])([^\'"]*)\1/s',
	);
	foreach ( $js_class as $re ) {
		if ( preg_match_all( $re, $text, $mj ) ) {
			foreach ( (array) $mj[ count( $mj ) - 1 ] as $chunk ) {
				$add( (string) $chunk );
			}
		}
	}
	$js_selector = array(
		'/querySelector(?:All)?\s*\(\s*([\'"])([^\'"]*)\1/s',
		'/closest\s*\(\s*([\'"])([^\'"]*)\1/s',
		'/matches\s*\(\s*([\'"])([^\'"]*)\1/s',
	);
	foreach ( $js_selector as $re ) {
		if ( preg_match_all( $re, $text, $mj ) ) {
			foreach ( (array) $mj[ count( $mj ) - 1 ] as $chunk ) {
				$add_selector( (string) $chunk );
			}
		}
	}

	// Classes assembled at runtime (`'haai-dot--' . $tone`, `'haai-msg--' + side`)
	// cannot be recovered by static scanning: any regex clever enough to find them
	// in one file will mis-pair quotes in another and start inventing or dropping
	// names. They are declared instead, with a marker comment in the stylesheet,
	// and the marker is verified below so the list cannot rot.

	// Only real class names survive: ours are always hyphenated, the single-word
	// ones are WordPress' own (passed in via $allow_single). Everything else
	// (`classList`, `contains`, `img`, `hidden`) is grammar, not markup.
	$kept = array();
	foreach ( array_keys( $found ) as $c ) {
		if ( false !== strpos( (string) $c, '-' ) || in_array( $c, (array) $allow_single, true ) ) {
			$kept[ $c ] = true;
		}
	}

	return $kept;
}

/**
 * Class selectors defined by a stylesheet.
 *
 * @param string $text CSS contents.
 * @return array<string,bool>
 */
function extract_defined( $text ) {
	$text = (string) preg_replace( '#/\*.*?\*/#s', ' ', (string) $text );
	// url(...) and @font-face sources contain dots that are not classes (admin.min.css).
	$text = (string) preg_replace( '#url\([^)]*\)#i', ' ', $text );
	$out  = array();
	// Compound selectors must be seen: `body.haai-theme-dark` and `li.is-fail` both
	// define a class, so the char before the dot may be a letter. Only a preceding
	// dash is rejected, which is a CSS custom property (--haai-x), not a selector.
	if ( preg_match_all( '#(?<![-])\.([a-zA-Z][a-zA-Z0-9_-]*)#', $text, $m ) ) {
		foreach ( (array) $m[1] as $c ) {
			$out[ $c ] = true;
		}
	}
	return $out;
}

/**
 * Classes a stylesheet declares as runtime-assembled, e.g.
 * `@built-by-concatenation: is-pass is-warn haai-dot--danger`
 *
 * @param string $text CSS contents.
 * @return array<int,string>
 */
function extract_concat_marker( $text ) {
	if ( ! preg_match( '/@built-by-concatenation:([^\r\n]*)/', (string) $text, $m ) ) {
		return array();
	}
	return array_values( array_filter( (array) preg_split( '/\s+/', trim( (string) $m[1] ) ) ) );
}

/* ---- Self-test: prove the extractors actually extract --------------------- */
if ( in_array( '--self-test', $_SERVER['argv'], true ) ) {
	$doc = '<div id="haai-not-a-class" class="haai-widget__box haai-mod <?php echo $dynamic; ?> haai-dot--'
		. '"' . '>x</div><input class="large-text" id="haai-v-title" />'
		. '<span class="haai-row <?php echo $ok ? \'is-on\' : \'is-off\'; ?>">y</span>'
		. '<script>el.classList.add("haai-js-picked","haai-js-two");'
		. ' el.className = "haai-js-third"; root.querySelector(".haai-tree__row");'
		. ' node.closest( ".haai-pick-closest" );</script>'
		. '<?php $classes = "haai-php-var-list is-on"; ?>';
	$css  = ':root { --haai-primary: #123 } .haai-widget__box { color: red } .haai-unused-one { color: blue }'
		. ' body.haai-theme-dark .haai-wrap { color: red } li.is-fail { color: blue } a{background:url(admin.min.css)}';
	$seen = array_keys( extract_classes( $doc ) );
	$def  = extract_defined( $css );
	$checks = array(
		'literal class found'          => in_array( 'haai-widget__box', $seen, true ),
		'dynamic php hole ignored'    => ! in_array( 'echo', $seen, true ) && ! in_array( '$dynamic', $seen, true ),
		'id attribute not a class'    => ! in_array( 'haai-not-a-class', $seen, true ) && ! in_array( 'haai-v-title', $seen, true ),
		'classList.add (both args)'   => in_array( 'haai-js-picked', $seen, true ) && in_array( 'haai-js-two', $seen, true ),
		'className assignment'         => in_array( 'haai-js-third', $seen, true ),
		'querySelector class'          => in_array( 'haai-tree__row', $seen, true ),
		'closest class'                => in_array( 'haai-pick-closest', $seen, true ),
		'php $classes variable'        => in_array( 'haai-php-var-list', $seen, true ) && in_array( 'is-on', $seen, true ),
		'ternary class inside hole'    => in_array( 'is-on', $seen, true ) && in_array( 'is-off', $seen, true ),
		'single-word junk dropped'     => ! in_array( 'img', $seen, true ) && ! in_array( 'contains', $seen, true ),
		'css selector parsed'          => isset( $def['haai-widget__box'] ),
		'compound body.class parsed'   => isset( $def['haai-theme-dark'] ) && isset( $def['haai-wrap'] ),
		'compound li.class parsed'     => isset( $def['is-fail'] ),
		'css custom property ignored'  => ! isset( $def['haai-primary'] ),
		'filename in url() ignored'    => ! isset( $def['min'] ) && ! isset( $def['css'] ),
		'unrelated css not invented'   => ! isset( $def['haai-nope'] ),
	);
	$bad = 0;
	foreach ( $checks as $name => $pass ) {
		echo ( $pass ? '  ok   ' : '  FAIL ' ) . str_pad( (string) $name, 32 ) . "\n";
		$bad += $pass ? 0 : 1;
	}
	echo "\nSELFTEST FAILURES: $bad\n";
	exit( $bad ? 1 : 0 );
}

/* ---- Sources ------------------------------------------------------------- */

$widget_sources = array(
	$root . 'templates/chat-widget.php',
	$root . 'assets/js/chatbot.js',
	$root . 'assets/js/loader.js',
);

// hami-ai-assistant.php prints both widget markup and the inline FAB CSS, and the
// fab classes are intentionally inlined rather than shipped in a stylesheet.
$admin_sources = array_merge(
	glob( $root . 'includes/*.php' ),
	glob( $root . 'modules/*.php' ),
	array( $root . 'hami-ai-assistant.php' )
);
$admin_js = array(
	$root . 'assets/js/admin.js',
	$root . 'assets/js/product-picker.js',
);

$admin_css  = extract_defined( (string) file_get_contents( $root . 'assets/css/admin.css' ) );
$widget_css = extract_defined( (string) file_get_contents( $root . 'assets/css/chatbot.css' ) );

/** Inline classes the FAB stylesheet defines inside PHP (heredoc), not in a .css file. */
$inline_css = extract_defined( (string) file_get_contents( $root . 'hami-ai-assistant.php' ) );

$admin_used  = array();
$widget_used = array();

foreach ( array_merge( $admin_sources, $admin_js ) as $f ) {
	foreach ( array_keys( extract_classes( (string) file_get_contents( $f ), $host_classes ) ) as $c ) {
		$admin_used[ $c ] = basename( (string) $f );
	}
}
foreach ( $widget_sources as $f ) {
	foreach ( array_keys( extract_classes( (string) file_get_contents( $f ), $host_classes ) ) as $c ) {
		$widget_used[ $c ] = basename( (string) $f );
	}
}

$host = function ( $c ) use ( $host_classes ) {
	return in_array( $c, $host_classes, true );
};
// Concatenated prefixes like 'haai-dot--' . $kind cannot be resolved statically.
$partial = function ( $c ) {
	return 0 === substr_compare( (string) $c, '--', -2 );
};

$undefined_admin = array();
foreach ( $admin_used as $c => $f ) {
	if ( $host( $c ) || $partial( $c ) ) {
		continue;
	}
	if ( ! isset( $admin_css[ $c ] ) && ! isset( $inline_css[ $c ] ) ) {
		$undefined_admin[ $c ] = $f;
	}
}

$undefined_widget = array();
foreach ( $widget_used as $c => $f ) {
	if ( $host( $c ) || $partial( $c ) ) {
		continue;
	}
	if ( ! isset( $widget_css[ $c ] ) && ! isset( $inline_css[ $c ] ) ) {
		$undefined_widget[ $c ] = $f;
	}
}

/* Widget markup styled only by admin.css (or admin markup only by chatbot.css). */
$misplaced = array();
foreach ( $widget_used as $c => $f ) {
	if ( $host( $c ) || isset( $widget_css[ $c ] ) || isset( $inline_css[ $c ] ) ) {
		continue;
	}
	if ( isset( $admin_css[ $c ] ) ) {
		$misplaced[ $c ] = 'widget markup, styled only by admin.css';
	}
}
foreach ( $admin_used as $c => $f ) {
	if ( $host( $c ) || isset( $admin_css[ $c ] ) || isset( $inline_css[ $c ] ) ) {
		continue;
	}
	if ( isset( $widget_css[ $c ] ) ) {
		$misplaced[ $c ] = 'admin markup, styled only by chatbot.css';
	}
}

$admin_concat  = extract_concat_marker( (string) file_get_contents( $root . 'assets/css/admin.css' ) );
$widget_concat = extract_concat_marker( (string) file_get_contents( $root . 'assets/css/chatbot.css' ) );

foreach ( $admin_concat as $c ) {
	$admin_used[ $c ] = '@built-by-concatenation';
}
foreach ( $widget_concat as $c ) {
	$widget_used[ $c ] = '@built-by-concatenation';
}

// A marker naming a class this sheet does not actually style is a lie, and lies
// in an allowlist are how rot hides: treat it as a problem, not a note.
$bad_markers = array();
foreach ( $admin_concat as $c ) {
	if ( ! isset( $admin_css[ $c ] ) ) {
		$bad_markers[] = 'admin.css marker names undefined .' . $c;
	}
}
foreach ( $widget_concat as $c ) {
	if ( ! isset( $widget_css[ $c ] ) ) {
		$bad_markers[] = 'chatbot.css marker names undefined .' . $c;
	}
}

$unused_admin  = array();
$unused_widget = array();
// Only our own namespaces can be called dead: a rule for .widefat or .current is
// WordPress' API surface, and this scanner cannot prove the theme never emits it.
$mine = function ( $c ) {
	return 0 === strpos( (string) $c, 'haai-' ) || 0 === strpos( (string) $c, 'is-' ) || 0 === strpos( (string) $c, 'has-' );
};
foreach ( array_keys( $admin_css ) as $c ) {
	if ( $mine( $c ) && ! isset( $admin_used[ $c ] ) && ! isset( $widget_used[ $c ] ) ) {
		$unused_admin[] = $c;
	}
}
foreach ( array_keys( $widget_css ) as $c ) {
	if ( $mine( $c ) && ! isset( $widget_used[ $c ] ) && ! isset( $admin_used[ $c ] ) ) {
		$unused_widget[] = $c;
	}
}

ksort( $undefined_admin );
ksort( $undefined_widget );

if ( in_array( '--json', $_SERVER['argv'], true ) ) {
	echo (string) json_encode(
		array(
			'undefined_admin'  => $undefined_admin,
			'undefined_widget' => $undefined_widget,
			'misplaced'        => $misplaced,
			'unused_admin'     => $unused_admin,
			'unused_widget'    => $unused_widget,
		),
		JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
	) . "\n";
} else {
	printf(
		"admin : %d classes used | %d defined\nwidget: %d classes used | %d defined\n",
		count( $admin_used ),
		count( $admin_css ),
		count( $widget_used ),
		count( $widget_css )
	);
	if ( $undefined_admin ) {
		echo "\nUNDEFINED in admin.css (" . count( $undefined_admin ) . "):\n";
		foreach ( $undefined_admin as $c => $f ) {
			echo '  ' . str_pad( (string) $c, 34 ) . $f . "\n";
		}
	}
	if ( $undefined_widget ) {
		echo "\nUNDEFINED in chatbot.css (" . count( $undefined_widget ) . "):\n";
		foreach ( $undefined_widget as $c => $f ) {
			echo '  ' . str_pad( (string) $c, 34 ) . $f . "\n";
		}
	}
	if ( $misplaced ) {
		echo "\nMISPLACED (" . count( $misplaced ) . "):\n";
		foreach ( $misplaced as $c => $why ) {
			echo '  ' . str_pad( (string) $c, 34 ) . $why . "\n";
		}
	}
	if ( $bad_markers ) {
		echo "\nBAD MARKERS (" . count( $bad_markers ) . "):\n  " . implode( "\n  ", $bad_markers ) . "\n";
	}
	if ( ! in_array( '--quiet-unused', $_SERVER['argv'], true ) ) {
		if ( $unused_admin ) {
			echo "\nUNUSED admin selectors (" . count( $unused_admin ) . "):\n  " . implode( "\n  ", $unused_admin ) . "\n";
		}
		if ( $unused_widget ) {
			echo "\nUNUSED widget selectors (" . count( $unused_widget ) . "):\n  " . implode( "\n  ", $unused_widget ) . "\n";
		}
	}
}

$problems = count( $undefined_admin ) + count( $undefined_widget ) + count( $misplaced ) + count( $bad_markers );
echo "\nPROBLEMS: $problems | unused: " . ( count( $unused_admin ) + count( $unused_widget ) ) . "\n";
exit( $problems ? 1 : 0 );
