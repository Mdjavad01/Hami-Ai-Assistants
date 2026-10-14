<?php
/**
 * Probe: can the bench reproduce the sks attribute shape (encoded key + term id)?
 *
 * Builds a throwaway product whose `_product_attributes` meta is written exactly
 * the way the live site stored it, then dumps what WooCommerce hands back and
 * what Haa_Content::product_facts() puts in the prompt.
 */
$tax_slug = 'pa_' . rawurlencode( 'جنس' ); // the shape sks stored: percent-encoded key.
$plain    = 'pa_جنس';

update_option( 'woocommerce_currency', 'IRR' );

// A global attribute for «جنس» if it does not exist yet.
if ( ! taxonomy_exists( 'pa_جنس' ) ) {
	$created = wc_create_attribute(
		array(
			'name'   => 'جنس',
			'type'   => 'select',
			'format' => 'term',
			'usage'  => 'global',
		)
	);
	echo 'create attribute: ' . ( is_wp_error( $created ) ? $created->get_error_message() : var_export( $created, true ) ) . "\n";
	if ( ! is_wp_error( $created ) && is_numeric( $created ) && function_exists( 'wc_get_attribute' ) ) {
		$ao = wc_get_attribute( (int) $created );
		echo '  created slug=' . ( $ao ? $ao->slug : '?' ) . "\n";
	}
}
echo "taxonomy_exists(pa_جنس)=" . var_export( taxonomy_exists( 'pa_جنس' ), true ) . "\n";

$term = term_exists( 'پلی استر', 'pa_جنس' );
if ( ! $term ) {
	$term = wp_insert_term( 'پلی استر', 'pa_جنس' );
}
$term_id = is_array( $term ) ? (int) $term['term_id'] : (int) $term;
echo 'term_id=' . $term_id . " taxonomy_exists({$tax_slug})=" . var_export( taxonomy_exists( $tax_slug ), true ) . "\n";

$p = new WC_Product_Simple();
$p->set_name( ' پروب ورودی الف' );
$p->set_status( 'publish' );
$p->set_price( '4152720' );
$p->set_regular_price( '5848414' );
$p->set_description( 'لورم ایپسوم متن ساختگی با تولید سادگی نامفهوم از صنعت چاپ و با استفاده از طراحان گرافیک است. چاپگرها و متون بلکه روزنامه و مجله در ستون و سطرآنچنان که لازم است.' );
$p->set_short_description( 'جنس : پلی استر' );
$id = $p->save();
wp_set_object_terms( $id, array( $term_id ), 'pa_جنس' );

// Rewrite the meta to the encoded key, byte-for-byte like the live store
// (meta key percent-encoded, `name` decoded, values coming from term relations).
update_post_meta(
	$id,
	'_product_attributes',
	array(
		$tax_slug => array(
			'name'        => 'pa_جنس',
			'value'       => '',
			'position'    => 0,
			'is_visible'  => 1,
			'is_variation' => 0,
			'is_taxonomy' => 1,
		),
	)
);
wc_delete_product_transients( $id );

$prod = wc_get_product( $id );
echo '--- get_attributes() ---' . "\n";
foreach ( $prod->get_attributes() as $k => $a ) {
	echo '  key=' . $k . ' | label=' . wc_attribute_label( $k, $prod ) . ' | taxonomy_exists=' . var_export( taxonomy_exists( (string) $k ), true ) . ' | options=' . wp_json_encode( $a->get_options() ) . " | is_taxonomy=" . var_export( $a->is_taxonomy(), true ) . "\n";
}
echo '--- product_facts() today ---' . "\n";
foreach ( Haa_Content::product_facts( $id ) as $k => $v ) {
	echo '  ' . $k . ' => ' . str_replace( "\n", ' ', mb_substr( $v, 0, 60 ) ) . "\n";
}
echo 'currency=' . get_woocommerce_currency() . ' symbol=' . get_woocommerce_currency_symbol() . "\n";
echo 'fixture_product=' . $id . "\n";
