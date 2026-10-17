<?php
/**
 * Regression suite: per-provider credential vault (v2.1.13).
 *
 * The user's report: «الان مثلا جمینای و با گراک عوض کنم، کل api بهم
 * میخوره با اینکه دو تا پرووایدر مختلفن» — one shared api_key slot meant
 * switching Gemini→Grok either kept Gemini's key under Grok's label (the
 * untouched mask round-trip) or destroyed it the moment Grok's key was
 * typed. Locked here:
 *
 *   1. upgrade migration: the active credentials get a vault row under
 *      their own slug on the first provider-tab save;
 *   2. switching services with an untouched (masked) key can never copy
 *      the previous provider's key onto the new one — the slot starts
 *      clean until the new key is saved;
 *   3. typing the new service's key stores it under THAT service's slug
 *      and leaves the old rows byte-identical;
 *   4. switching back restores the original key (the headline promise:
 *      each provider keeps its own API until the admin changes it);
 *   5. no-JS stale forms (no vault_from stamp) never write the previous
 *      service's base_url/models onto the new one;
 *   6. "test connection" against a freshly switched service uses THAT
 *      service's vaulted key (provider_from_post), never the old one's;
 *   7. sanitize_vault: typed→encrypted, mask→same-slug stored key,
 *      haaienc: passthrough, unknown slugs and empty rows dropped;
 *   8. vault_for_js: has_key + mask only, plaintext never leaves;
 *   9. export strips vault keys; the import path keeps haaienc: rows;
 *  10. same-provider re-save with the mask keeps the active key
 *      (the pre-vault round-trip must not regress).
 *
 * Run: php dev-tests/tmp-checks24-vault.php
 */
$GLOBALS['haai_use_real_api'] = true; // the REAL Haa_API — the fake carries only 3 presets
require __DIR__ . '/tmp-wp-stubs.php';

$fail = 0;
function ok( $label, $cond, $extra = '' ) {
        global $fail;
        if ( ! $cond ) { $fail++; }
        echo ( $cond ? '  ok   ' : '  FAIL ' ) . str_pad( $label, 58 ) . $extra . "\n";
}

/** Fresh install state: one provider fully configured (the user's Gemini). */
$seed = function ( $slug, $key, $model, $base = '' ) {
        $GLOBALS['opts'] = array();
        Haa_Settings::flush_cache();
        Haa_Settings::save_section( 'provider', array(
                'provider' => $slug,
                'api_key'  => $key,
                'base_url' => $base,
                'model'    => $model,
                'vault_from' => $slug, // JS present: the form holds this service's values.
        ) );
};

/* ------------------------------------------------------------------ */
echo "=== A. Upgrade migration: the active key gets its own vault row ===\n";

$seed( 'gemini', 'gemini-secret-123', 'gemini-2.5-flash' );
$vault = Haa_Settings::get( 'provider.vault' );
ok( 'typed key saved under its own slug', isset( $vault['gemini']['api_key'] ) && '' !== $vault['gemini']['api_key'] );
ok( 'vault key is encrypted', 0 === strpos( (string) $vault['gemini']['api_key'], 'haaienc:' ) );
ok( 'vault key decrypts to the typed key', 'gemini-secret-123' === Haa_Settings::decrypt( (string) $vault['gemini']['api_key'] ) );
ok( 'active key equals the vault key', Haa_Settings::get( 'provider.api_key' ) === $vault['gemini']['api_key'] );
ok( 'model rides the vault row', 'gemini-2.5-flash' === (string) $vault['gemini']['model'] );

/* ------------------------------------------------------------------ */
echo "=== B. Same-provider re-save with the untouched mask keeps everything ===\n";

Haa_Settings::save_section( 'provider', array(
        'provider' => 'gemini',
        'api_key'  => Haa_Settings::KEY_MASK,
        'model'    => 'gemini-2.5-flash',
        'vault_from' => 'gemini',
) );
ok( 'active key survives the masked re-save', 'gemini-secret-123' === Haa_Settings::decrypt( (string) Haa_Settings::get( 'provider.api_key' ) ) );
ok( 'vault row still holds the same key', 'gemini-secret-123' === Haa_Settings::decrypt( (string) Haa_Settings::get( 'provider.vault.gemini.api_key' ) ) );

/* ------------------------------------------------------------------ */
echo "=== C. Switch gemini→xai with the mask: clean slot, old key safe ===\n";

// JS swapped the fields in (preset defaults for xai, no key yet).
Haa_Settings::save_section( 'provider', array(
        'provider' => 'xai',
        'api_key'  => Haa_Settings::KEY_MASK,
        'base_url' => 'https://api.x.ai/v1',
        'model'    => 'grok-2-latest',
        'vault_from' => 'xai',
) );
ok( 'switched provider starts with NO key (old bug: gemini key reused)', '' === (string) Haa_Settings::get( 'provider.api_key' ) );
ok( 'gemini vault row untouched', 'gemini-secret-123' === Haa_Settings::decrypt( (string) Haa_Settings::get( 'provider.vault.gemini.api_key' ) ) );
ok( 'xai row exists with urls/models', 'https://api.x.ai/v1' === (string) Haa_Settings::get( 'provider.vault.xai.base_url' ) && 'grok-2-latest' === (string) Haa_Settings::get( 'provider.vault.xai.model' ) );
ok( 'active provider is xai', 'xai' === (string) Haa_Settings::get( 'provider.provider' ) );

/* ------------------------------------------------------------------ */
echo "=== D. Typing the xai key stores it under xai only ===\n";

Haa_Settings::save_section( 'provider', array(
        'provider' => 'xai',
        'api_key'  => 'xai-secret-789',
        'base_url' => 'https://api.x.ai/v1',
        'model'    => 'grok-2-latest',
        'vault_from' => 'xai',
) );
ok( 'active key is the typed xai key', 'xai-secret-789' === Haa_Settings::decrypt( (string) Haa_Settings::get( 'provider.api_key' ) ) );
ok( 'xai vault row holds the typed key', 'xai-secret-789' === Haa_Settings::decrypt( (string) Haa_Settings::get( 'provider.vault.xai.api_key' ) ) );
ok( 'gemini vault row STILL holds its own key', 'gemini-secret-123' === Haa_Settings::decrypt( (string) Haa_Settings::get( 'provider.vault.gemini.api_key' ) ) );

/* ------------------------------------------------------------------ */
echo "=== E. Switch back xai→gemini: the original key comes home ===\n";

Haa_Settings::save_section( 'provider', array(
        'provider' => 'gemini',
        'api_key'  => Haa_Settings::KEY_MASK,
        'base_url' => '',
        'model'    => 'gemini-2.5-flash',
        'vault_from' => 'xai',
) );
$cfg = Haa_Settings::provider();
ok( 'active provider back to gemini', 'gemini' === (string) $cfg['provider'] );
ok( 'active key is the ORIGINAL gemini key (headline promise)', 'gemini-secret-123' === $cfg['api_key'] );
ok( 'preset fills the gemini base_url', 'https://generativelanguage.googleapis.com/v1beta' === $cfg['base_url'] );
ok( 'xai row still vaulted for the next visit', 'xai-secret-789' === Haa_Settings::decrypt( (string) Haa_Settings::get( 'provider.vault.xai.api_key' ) ) );

/* ------------------------------------------------------------------ */
echo "=== F. No-JS stale form: previous service's urls/models never bleed ===\n";

// Simulate a page where JS never ran: the form still carries gemini's
// base_url/model while the select was switched to deepseek by hand.
Haa_Settings::save_section( 'provider', array(
        'provider' => 'deepseek',
        'api_key'  => Haa_Settings::KEY_MASK,
        'base_url' => 'https://generativelanguage.googleapis.com/v1beta', // stale!
        'model'    => 'gemini-2.5-flash',                                  // stale!
        // no vault_from stamp → server knows the form is stale.
) );
ok( 'stale gemini base_url NOT stored for deepseek', '' === (string) Haa_Settings::get( 'provider.base_url' ) );
ok( 'stale gemini model NOT stored for deepseek', '' === (string) Haa_Settings::get( 'provider.model' ) );
ok( 'deepseek starts with no key', '' === (string) Haa_Settings::get( 'provider.api_key' ) );
ok( 'gemini vault row survives the stale save', 'gemini-secret-123' === Haa_Settings::decrypt( (string) Haa_Settings::get( 'provider.vault.gemini.api_key' ) ) );

/* ------------------------------------------------------------------ */
echo "=== G. provider_from_post: the probe tests the RIGHT service's key ===\n";

// Back to a two-key state: save a deepseek key, then probe xai by mask.
Haa_Settings::save_section( 'provider', array(
        'provider' => 'deepseek',
        'api_key'  => 'deepseek-secret-555',
        'vault_from' => 'deepseek',
) );
$posted = array(
        'provider' => 'xai',
        'api_key'  => Haa_Settings::KEY_MASK, // untouched after the JS swap
        'base_url' => 'https://api.x.ai/v1',
        'model'    => 'grok-2-latest',
);
$cfg = Haa_Settings::provider_from_post( $posted );
ok( 'probe of switched service uses ITS vaulted key', 'xai-secret-789' === $cfg['api_key'], 'got ' . strlen( (string) $cfg['api_key'] ) . ' chars' );

$posted_typed = array(
        'provider' => 'xai',
        'api_key'  => 'fresh-typed-999',
        'base_url' => 'https://api.x.ai/v1',
        'model'    => 'grok-2-latest',
);
$cfg = Haa_Settings::provider_from_post( $posted_typed );
ok( 'probe with a typed key uses the typed key', 'fresh-typed-999' === $cfg['api_key'] );

$posted_same = array(
        'provider' => 'deepseek',
        'api_key'  => Haa_Settings::KEY_MASK,
);
$cfg = Haa_Settings::provider_from_post( $posted_same );
ok( 'probe of the stored service keeps its active key', 'deepseek-secret-555' === $cfg['api_key'] );

/* ------------------------------------------------------------------ */
echo "=== H. sanitize_vault: keys, slugs, shapes ===\n";

$GLOBALS['opts'] = array();
Haa_Settings::flush_cache();
$enc        = Haa_Settings::encrypt( 'plain-key-1' );
$clean_v    = Haa_Settings::sanitize_vault( array(
        'gemini'   => array( 'api_key' => 'brand-new-key', 'base_url' => 'https://x.example/v1', 'model' => 'm-1' ),
        'xai'      => array( 'api_key' => Haa_Settings::KEY_MASK, 'model' => 'grok' ),
        'deepseek' => array( 'api_key' => $enc, 'model' => 'deepseek-chat' ),
        'evil slug' => array( 'api_key' => 'nope' ),
        'groq'     => array( 'api_key' => '', 'base_url' => '', 'model' => '' ), // empty → dropped
) );
ok( 'typed key encrypted', 0 === strpos( (string) $clean_v['gemini']['api_key'], 'haaienc:' ) && 'brand-new-key' === Haa_Settings::decrypt( (string) $clean_v['gemini']['api_key'] ) );
ok( 'url/model ride the row', 'https://x.example/v1' === $clean_v['gemini']['base_url'] && 'm-1' === $clean_v['gemini']['model'] );
ok( 'unknown slug dropped', ! isset( $clean_v['evil slug'] ) && ! isset( $clean_v['evilslug'] ) );
ok( 'empty row dropped', ! isset( $clean_v['groq'] ) );

// Mask round-trip against a STORED vault (needs the option in place).
$GLOBALS['opts'][ HAAI_OPTION ]['provider']['vault'] = array(
        'xai' => array( 'api_key' => $enc, 'base_url' => '', 'model' => 'grok', 'embedding_model' => '', 'image_model' => '' ),
);
Haa_Settings::flush_cache();
$clean_v = Haa_Settings::sanitize_vault( array(
        'xai' => array( 'api_key' => Haa_Settings::KEY_MASK, 'model' => 'grok-2-latest' ),
) );
ok( 'masked key keeps the SAME slug stored key', $enc === $clean_v['xai']['api_key'] );
ok( 'other row fields still sanitize', 'grok-2-latest' === $clean_v['xai']['model'] );

/* ------------------------------------------------------------------ */
echo "=== I. vault_for_js: mask only, plaintext never leaves ===\n";

$GLOBALS['opts'][ HAAI_OPTION ]['provider']['vault'] = array(
        'gemini' => array( 'api_key' => Haa_Settings::encrypt( 'gemini-secret-123' ), 'base_url' => '', 'model' => 'gemini-2.5-flash', 'embedding_model' => '', 'image_model' => '' ),
        'xai'    => array( 'api_key' => '', 'base_url' => 'https://api.x.ai/v1', 'model' => '', 'embedding_model' => '', 'image_model' => '' ),
);
Haa_Settings::flush_cache();
$js = Haa_Settings::vault_for_js();
ok( 'keyed row: has_key true + mask value', true === $js['gemini']['has_key'] && Haa_Settings::KEY_MASK === $js['gemini']['api_key'] );
ok( 'no plaintext anywhere in the payload', false === strpos( wp_json_encode( $js ), 'gemini-secret-123' ) );
ok( 'keyless row with urls still listed', false === $js['xai']['has_key'] && 'https://api.x.ai/v1' === $js['xai']['base_url'] );

/* ------------------------------------------------------------------ */
echo "=== J. Export / import round-trip ===\n";

$json = Haa_Settings::export();
ok( 'export carries no vault plaintext', false === strpos( (string) $json, 'gemini-secret-123' ) );
$decoded = json_decode( (string) $json, true );
ok( 'export keeps the vault row urls/models', isset( $decoded['settings']['provider']['vault']['gemini']['model'] ) && 'gemini-2.5-flash' === $decoded['settings']['provider']['vault']['gemini']['model'] );

// A foreign import carrying its own haaienc: rows must pass validation.
$import = array(
        'settings' => array(
                'provider' => array(
                        'provider' => 'xai',
                        'vault'    => array(
                                'xai' => array( 'api_key' => $enc, 'base_url' => '', 'model' => 'grok-2-latest', 'embedding_model' => '', 'image_model' => '' ),
                        ),
                ),
        ),
);
$checked = Haa_Settings::validate_import( $import );
ok( 'import accepts provider.vault', ! empty( $checked['ok'] ) );
ok( 'import keeps the haaienc key byte-for-byte', isset( $checked['settings']['provider']['vault']['xai']['api_key'] ) && $enc === $checked['settings']['provider']['vault']['xai']['api_key'] );

/* ------------------------------------------------------------------ */
echo "=== K. Runtime config still resolves after the whole dance ===\n";

Haa_Settings::save_section( 'provider', array(
        'provider' => 'gemini',
        'api_key'  => Haa_Settings::KEY_MASK,
        'vault_from' => 'deepseek',
) );
$cfg = Haa_Settings::provider();
ok( 'failovers key still present in provider()', array_key_exists( 'failovers', $cfg ) );
ok( 'vault row rides the stored section', is_array( Haa_Settings::get( 'provider.vault' ) ) && isset( Haa_Settings::get( 'provider.vault' )['gemini'] ) );
ok( 'adapter resolves from the preset', 'gemini' === $cfg['adapter'] );

echo "\n" . ( 0 === $fail ? "ALL CHECKS PASSED" : $fail . ' CHECKS FAILED' ) . "\n";
exit( 0 === $fail ? 0 : 1 );
