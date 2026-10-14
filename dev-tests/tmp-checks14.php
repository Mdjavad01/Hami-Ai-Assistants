<?php
/**
 * Regression suite: Batch C1 — the per-product generate lock (P3) and the
 * apply marker that makes «اعمال» idempotent (P4).
 *
 * These two are NEW mechanisms, so per the run's proof rule the real wrong
 * behaviour (two model calls for one double click; a red error for an apply
 * that already worked) is demonstrated in the browser by
 * dev-tests/pc-gen-e2e.cjs before/after. This suite pins the semantics of the
 * seams that the handlers use, deterministically and without a browser:
 *
 *   - a second concurrent run for the same product is refused;
 *   - the lock is released when the run ends, so a later click is allowed;
 *   - product id 0 can never take the lock (nothing to key on);
 *   - the applied marker answers inside its window and expires after it.
 *
 * Run: php dev-tests/tmp-checks14.php
 */
require __DIR__ . '/tmp-wp-stubs.php';

$fail = 0;
function ok( $label, $cond, $extra = '' ) {
	global $fail;
	if ( ! $cond ) { $fail++; }
	echo ( $cond ? '  ok   ' : '  FAIL ' ) . str_pad( $label, 58 ) . $extra . "\n";
}

$M = 'Haa_Module_Product_Content';
$lock_key    = constant( $M . '::GEN_LOCK' ) . 123;
$applied_key = constant( $M . '::APPLIED' ) . 555;

echo "=== A. lock: one run at a time per product ===\n";
delete_transient( $lock_key );
ok( 'a free product reports unlocked', false === $M::generate_locked( 123 ) );
ok( 'first run may proceed', true === $M::acquire_generate_lock( 123 ) );
ok( 'the product is now locked', true === $M::generate_locked( 123 ) );
ok( 'a second, concurrent run is refused', false === $M::acquire_generate_lock( 123 ) );
ok( 'refusing did not extend the lock by accident', true === $M::generate_locked( 123 ) );
$M::release_generate_lock( 123 );
ok( 'after the run ends the lock is gone', false === $M::generate_locked( 123 ) );
ok( 'a later click may run again', true === $M::acquire_generate_lock( 123 ) );
$M::release_generate_lock( 123 );

echo "\n=== B. lock is per product, not global ===\n";
$M::acquire_generate_lock( 123 );
ok( 'another product is unaffected', true === $M::acquire_generate_lock( 124 ) );
$M::release_generate_lock( 123 );
$M::release_generate_lock( 124 );

echo "\n=== C. no product id, no lock ===\n";
ok( 'id 0 cannot take the lock', false === $M::acquire_generate_lock( 0 ) );
ok( 'id 0 never reports locked', false === $M::generate_locked( 0 ) );
$M::release_generate_lock( 0 );
ok( 'releasing id 0 is harmless', false === $M::generate_locked( 0 ) );

echo "\n=== D. lock really is a transient (same store the handlers use) ===\n";
$M::acquire_generate_lock( 123 );
ok( 'the transient exists while held', false !== get_transient( $lock_key ) );
$M::release_generate_lock( 123 );
ok( 'and gone once released', false === get_transient( $lock_key ) );

echo "\n=== E. apply marker (P4) ===\n";
delete_transient( $applied_key );
ok( 'a product never applied is not «just applied»', false === $M::applied_recently( 555 ) );
$M::mark_applied( 555 );
ok( 'right after apply it counts as just applied', true === $M::applied_recently( 555 ) );
ok( 'another product is unaffected', false === $M::applied_recently( 556 ) );
// Expire it the way the TTL would: the stub store answers to delete.
delete_transient( $applied_key );
ok( 'after the window closes it is no longer «just applied»', false === $M::applied_recently( 555 ) );
$M::mark_applied( 0 );
ok( 'marking product 0 stores nothing', false === $M::applied_recently( 0 ) );

echo "\n=== F. TTLs are sane (lock short, marker long) ===\n";
ok( 'lock TTL is a minute or two', constant( $M . '::GEN_LOCK_TTL' ) >= 60 && constant( $M . '::GEN_LOCK_TTL' ) <= 300, (string) constant( $M . '::GEN_LOCK_TTL' ) );
ok( 'applied window is minutes, not hours', constant( $M . '::APPLIED_TTL' ) >= 300 && constant( $M . '::APPLIED_TTL' ) <= 3600, (string) constant( $M . '::APPLIED_TTL' ) );

echo "\nFAILURES: $fail\n";
exit( $fail ? 1 : 0 );
