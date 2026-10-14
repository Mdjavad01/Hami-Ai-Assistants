<?php
/**
 * Read-only: dump the exact fact array that goes into the product prompt.
 *
 * Run on sks. Writes a JSON file inside dev-tests/ only; nothing is written to
 * the site, so the captured prompt can be rebuilt on the bench afterwards.
 *
 * Usage: wp --path=C:\xampp\htdocs\sks eval-file dev-tests/tmp-pc-facts-dump.php 13111
 */
$pid   = isset( $args[0] ) ? (int) $args[0] : 13111;
$facts = Haa_Content::product_facts( $pid );
$out   = array(
	'pid'        => $pid,
	'title'      => get_the_title( $pid ),
	'facts'      => $facts,
	'max_tokens' => (int) Haa_Settings::get( 'provider.max_tokens', 2000 ),
	'timeout'    => (int) Haa_Settings::get( 'provider.timeout', 60 ),
	'model'      => (string) Haa_Settings::get( 'provider.model' ),
	'provider'   => (string) Haa_Settings::get( 'provider.provider' ),
);

$file = dirname( __DIR__ ) . '/dev-tests/pc-facts-' . $pid . '.json';
file_put_contents( $file, wp_json_encode( $out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) ); // phpcs:ignore
echo 'wrote ' . $file . ' (' . filesize( $file ) . " bytes)\n";
echo 'fact keys: ' . implode( ', ', array_keys( $facts ) ) . "\n";
