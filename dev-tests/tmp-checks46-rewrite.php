<?php
/**
 * Regression suite: rewrite of existing content (2.1.51).
 *
 * User's request: «قابلیت بازنویسی… انتقال بدیم بخش ویرایشگر خودشون… یه بخش
 * دیگه هم داخل صفحه AI… بررسی کنه، بازنویسی کنه و تایید شد آپدیت بشه».
 *
 * Locked here:
 *   - the module is in the catalogue, has metadata, and needs no WooCommerce;
 *   - the settings triple exists (defaults + sanitize map + UI keys);
 *   - the screen slug is registered, so screen_url() resolves;
 *   - the entry points are wired: meta box + row actions on post/page/product;
 *   - the safety contract: generate never writes, apply uses wp_update_post +
 *     wp_kses_post, and a preview that lost most of the article is refused.
 *
 * Run: php dev-tests/tmp-checks46-rewrite.php
 */
require __DIR__ . '/tmp-wp-stubs.php';

$fail = 0;
function ok( $label, $cond, $extra = '' ) {
        global $fail;
        if ( ! $cond ) { $fail++; }
        echo ( $cond ? '  ok   ' : '  FAIL ' ) . str_pad( $label, 62 ) . $extra . "\n";
}

$root = dirname( __DIR__ ) . '/';
$mod  = (string) file_get_contents( $root . 'modules/class-haa-module-rewrite.php' );

echo "=== A. catalogue + interface ===\n";
$catalog = Haa_Modules::catalog();
ok( 'rewrite is in the catalogue', isset( $catalog['rewrite'] ) && 'Haa_Module_Rewrite' === $catalog['rewrite'] );
$meta = Haa_Modules::catalog_meta();
ok( 'catalogue metadata exists', isset( $meta['rewrite']['label'], $meta['rewrite']['description'] ) );
ok( 'metadata does not require WooCommerce', isset( $meta['rewrite']['woo'] ) && false === $meta['rewrite']['woo'] );
ok( 'module implements the interface', in_array( 'Haa_Module_Interface', class_implements( 'Haa_Module_Rewrite' ), true ) );
$m = new Haa_Module_Rewrite();
ok( 'slug is rewrite', 'rewrite' === $m->slug() );
ok( 'label is human', '' !== (string) $m->label() );
ok( 'requires_woocommerce is false', false === $m->requires_woocommerce() );

echo "\n=== B. settings triple ===\n";
$defaults = Haa_Settings::defaults();
ok( 'defaults carry the rewrite section', isset( $defaults['rewrite'] ) && is_array( $defaults['rewrite'] ) );
ok( 'default post types are post+page', array( 'post', 'page' ) === $defaults['rewrite']['post_types'] );
$map = Haa_Settings::sanitize_map();
ok( 'sanitize map covers post_types', isset( $map['rewrite.post_types'] ) );
ok( 'sanitize map covers instructions', isset( $map['rewrite.instructions'] ) );
$ui = new ReflectionMethod( 'Haa_Settings', 'collect_ui_keys' );
$ui->setAccessible( true );
Haa_Settings::set_enabled_modules( array( 'rewrite' => 1 ) );
$keys = $ui->invoke( null );
ok( 'UI registry exposes post_types', in_array( 'rewrite.post_types', $keys, true ) );
ok( 'UI registry exposes instructions', in_array( 'rewrite.instructions', $keys, true ) );

echo "\n=== C. screen + wiring ===\n";
$screens = Haa_Util::screens();
ok( 'screen slug registered', isset( $screens['rewrite'] ) && 'haai-rewrite' === $screens['rewrite'] );
ok( 'meta box registered', false !== strpos( $mod, "add_meta_box(" ) );
ok( 'row actions on posts and pages', false !== strpos( $mod, "'post_row_actions'" ) && false !== strpos( $mod, "'page_row_actions'" ) );
ok( 'generate is an admin_post route', false !== strpos( $mod, "admin_post_haai_rw_generate" ) );
ok( 'apply is an admin_post route', false !== strpos( $mod, "admin_post_haai_rw_apply" ) );

echo "\n=== D. safety contract ===\n";
$gen = substr( $mod, (int) strpos( $mod, 'public function handle_generate' ), 6000 );
$app = substr( $mod, (int) strpos( $mod, 'public function handle_apply' ), 2600 );
ok( 'generate never writes the post', false === strpos( $gen, 'wp_update_post' ) );
ok( 'generate stores a transient preview', false !== strpos( $gen, 'set_transient(' ) );
ok( 'apply writes through wp_update_post', false !== strpos( $app, 'wp_update_post(' ) );
ok( 'apply sanitizes the submitted html', false !== strpos( $app, 'wp_kses_post(' ) );
ok( 'apply refuses a collapsed rewrite', false !== strpos( $app, '0.5' ) && false !== strpos( $app, 'ذخیره نشد' ) );
ok( 'revision marker meta is written', false !== strpos( $app, 'update_post_meta(' ) );
ok( 'the model call is bounded (45s, no retry)', false !== strpos( $mod, 'CALL_TIMEOUT' ) && false !== strpos( $mod, "'retries'    => 0" ) );
ok( 'a fatal still answers with JSON', false !== strpos( $mod, 'register_shutdown_function(' ) );
ok( 'the last failure is remembered for the panel', false !== strpos( $mod, 'remember_failure(' ) && false !== strpos( $mod, 'FAIL_KEY' ) );
ok( 'long bodies are chunked per section', false !== strpos( $mod, 'split_chunks(' ) && false !== strpos( $mod, 'CHUNK_WORDS' ) );
ok( 'one chunk is rewritten per request', false !== strpos( $mod, 'rewrite_chunk(' ) );
ok( 'progress rides back for the auto-chain', false !== strpos( $mod, "'finished' => false" ) && false !== strpos( $mod, "'progress'" ) );
ok( 'tiny fragments skip the model call', false !== strpos( $mod, '< 15 )' ) );
ok( 'the length floor scales with the chunk', false !== strpos( $mod, 'floor( $chunk_words / 3 )' ) );
ok( 'a rejected chunk shows the raw reply', false !== strpos( $mod, 'پاسخ خام' ) );
ok( 'strip_meta_markers array is unwrapped, never cast', false !== strpos( $mod, 'is_array( $stripped )' ) && false === strpos( $mod, '(string) Haa_Content::strip_meta_markers(' ) );

echo "\n=== E. helpers (reflection) ===\n";
$rc = new ReflectionClass( 'Haa_Module_Rewrite' );
$words = $rc->getMethod( 'words' );
$words->setAccessible( true );
ok( 'word counter ignores markup', 3 === $words->invoke( null, '<p>یک دو سه</p>' ) );
ok( 'word counter on empty html is zero', 0 === $words->invoke( null, '   ' ) );
ok( 'NBSP separates words, not glues them', 3 === $words->invoke( null, "یک\u{00A0}دو\u{00A0}سه" ) );
$target = $rc->getMethod( 'target_words' );
$target->setAccessible( true );
ok( 'target length keeps the current size', 1200 === $target->invoke( null, 1200 ) );
ok( 'target length has a floor', 200 <= (int) $target->invoke( null, 0 ) );
$key = $rc->getMethod( 'draft_key' );
$key->setAccessible( true );
ok( 'draft key is per post and per user', 0 === strpos( (string) $key->invoke( null, 77 ), 'haai_rw_draft_77_' ) );

echo "\n";
echo ( 0 === $fail ) ? "ALL PASS\n" : "FAILURES: $fail\n";
exit( 0 === $fail ? 0 : 1 );
