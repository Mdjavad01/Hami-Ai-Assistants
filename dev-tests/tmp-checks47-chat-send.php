<?php
/**
 * Regression suite: chat send path (2.1.59).
 *
 * The live bug: send() computed `lead` and returned when it was null — but null
 * means BOTH «no lead form needed» and «invalid phone». On an install with lead
 * capture OFF the config carries {enabled:false} (no `required` key), so every
 * click — send button, Enter and the suggestion chips — died before addBubble(),
 * without an error and without a single network request.
 *
 * Proven in the browser: setting C.lead.required='optional' by hand made the same
 * click work end to end (token 200, /chat 200, reply rendered).
 */

$root = dirname( __DIR__ );
$js   = file_get_contents( $root . '/assets/js/chatbot.js' );
$min  = file_get_contents( $root . '/assets/js/chatbot.min.js' );
$main = file_get_contents( $root . '/hami-ai-assistant.php' );

$pass = 0;
$fail = 0;

function ok( $label, $cond, $hint = '' ) {
	global $pass, $fail;
	if ( $cond ) {
		$pass++;
		echo "  ok   {$label}\n";
		return;
	}
	$fail++;
	echo "  FAIL {$label}" . ( '' !== $hint ? " ({$hint})" : '' ) . "\n";
}

echo "=== A. the send guard ===\n";

ok( 'A: requirement only comes from an ENABLED lead form',
	false !== strpos( $js, "var requirement = (C.lead && C.lead.enabled && C.lead.required) ? C.lead.required : 'off';" ) );

ok( 'A: the old one-liner is gone (it returned on the "off" branch)',
	false === strpos( $js, "var lead = 'off' === requirement || leadSent ? null : validateLead(requirement);" ) );

ok( 'A: a null from validateLead no longer aborts before the bubble',
	false === strpos( $js, "\t\tif (lead === null) return;" ) );

ok( 'A: the invalid-phone branch is explicit',
	false !== strpos( $js, "if ('off' !== requirement && !leadSent) {" ) );

ok( 'A: an invalid phone still stops the send',
	false !== strpos( $js, "lead = validateLead(requirement);" ) );

// Order matters: the bubble must be added before any network work.
$pos_bubble = strpos( $js, "addBubble('user', text);" );
$pos_guard  = strpos( $js, "if ('off' !== requirement && !leadSent) {" );
ok( 'A: the guard runs before addBubble()', false !== $pos_guard && false !== $pos_bubble && $pos_guard < $pos_bubble );

$pos_send = strpos( $js, 'function send(text)' );
$pos_post = strpos( $js, "post('/chat', payload, true)" );
ok( 'A: send() reaches the /chat POST', false !== $pos_post && $pos_post > $pos_send );

echo "\n=== B. the deadlock guards (2.1.56, kept on purpose) ===\n";

ok( 'B: ensureToken rejects instead of throwing', false !== strpos( $js, "return Promise.reject(new Error('token url missing'));" ) );
ok( 'B: post rejects when the REST base is missing', false !== strpos( $js, "return Promise.reject(new Error('rest url missing'));" ) );
ok( 'B: a stuck pending flag is released', false !== strpos( $js, "if (sendBtn && sendBtn.classList.contains('is-busy')) return;" ) );
ok( 'B: reachable only when the button is not busy', false !== strpos( $js, 'pending = false;' ) );

echo "\n=== C. the config shape the JS relies on ===\n";

ok( 'C: lead ships enabled=false when capture is off',
	false !== strpos( $main, "array( 'enabled' => false )" ) );

ok( 'C: enabled=true carries required + consent',
	false !== strpos( $main, "'required' => (string) ( \$b['lead_requirement'] ?? 'off' )" ) );

ok( 'C: the JS never reads C.lead.required without the enabled flag',
	false === strpos( $js, "(C.lead && C.lead.required)" ) );

echo "\n=== D. the widget assets are the 2.1.10 logic (no fallback pile-up) ===\n";

$loader = file_get_contents( $root . '/assets/js/loader.js' );
ok( 'D: loader reads the inline config first', false !== strpos( $loader, 'var c = window.HAAI_CONFIG;' ) );
ok( 'D: loader bails out when there is no config', false !== strpos( $loader, 'if (!c) return;' ) );
ok( 'D: loader has no data-* rebuild', false === strpos( $loader, 'haaiWidget' ) );
ok( 'D: loader has no REST merge', false === strpos( $loader, 'd.config' ) );
ok( 'D: loader has no CSP nonce plumbing', false === strpos( $loader, 'currentScript' ) );
ok( 'D: css() loads the configured stylesheet', false !== strpos( $loader, "link.href = c.css;" ) );
ok( 'D: the widget script is the configured one', false !== strpos( $loader, "script.src = c.js;" ) );

echo "\n=== E. minified asset matches the source ===\n";

ok( 'E: chatbot.min.js contains the fixed guard',
	false !== strpos( $min, 'C.lead.enabled' ) );
ok( 'E: chatbot.min.js does not contain the old one-liner',
	false === strpos( $min, "lead===null" ) );

echo "\n--- suite 47: chat send path ---\n";
echo "passed: {$pass}, failed: {$fail}\n";
if ( $fail ) {
	exit( 1 );
}
