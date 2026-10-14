<?php
/**
 * Read-only probe: why attributes arrive as raw term ids in the prompt.
 *
 * Usage: wp --path=C:\xampp\htdocs\sks eval-file dev-tests/tmp-pc-attr-probe.php 13111
 */
$pid = isset( $args[0] ) ? (int) $args[0] : 13111;
$p   = wc_get_product( $pid );

echo 'product: ' . $pid . ' / ' . $p->get_name() . "\n";
foreach ( $p->get_attributes() as $name => $attr ) {
	$vals = method_exists( $attr, 'get_options' ) ? (array) $attr->get_options() : array();
	echo '- key=' . $name
		. ' visible=' . var_export( $attr->get_visible(), true )
		. ' taxonomy=' . var_export( taxonomy_exists( (string) $name ), true )
		. ' label=' . wc_attribute_label( $name, $p )
		. ' options=' . wp_json_encode( $vals ) . "\n";
	foreach ( $vals as $v ) {
		if ( is_numeric( $v ) && taxonomy_exists( (string) $name ) ) {
			$t = get_term( (int) $v, (string) $name );
			echo '    term(' . (int) $v . ') => ' . ( is_wp_error( $t ) ? 'WP_Error:' . $t->get_error_code() : ( $t ? $t->name : 'NULL' ) ) . "\n";
		}
	}
}

echo "\n— product taxonomies terms —\n";
foreach ( array( 'pa_جنس', 'pa_کشور' ) as $tax ) {
	echo $tax . ': taxonomy_exists=' . var_export( taxonomy_exists( $tax ), true ) . ' names=' . wp_json_encode( wp_get_post_terms( $pid, $tax, array( 'fields' => 'names' ) ) ) . "\n";
}
echo 'product tax table rows: ' . wp_json_encode( array_map(
	function ( $r ) {
		return (array) $r;
	},
	get_terms( array( 'taxonomy' => 'pa_جنس', 'hide_empty' => false, 'fields' => 'names', 'number' => 5 ) )
) ) . "\n";

echo "\n— facts that count as “real data” —\n";
$facts = Haa_Content::product_facts( $pid );
foreach ( $facts as $k => $v ) {
	$len = mb_strlen( (string) $v, 'UTF-8' );
	$lorem = ( false !== strpos( (string) $v, 'لورم' ) ) ? ' [LOREM]' : '';
	echo sprintf( '  %-14s len=%-5d%s', $k, $len, $lorem ) . "\n";
}
echo 'currency symbol raw: ' . get_woocommerce_currency_symbol() . "\n";
