<?php
/**
 * Bench tool (report-only): 2000-chunk BM25 + EXPLAIN, correct schema.
 * Run: php wp-cli.phar eval-file dev-tests/wp-audit-measure2.php --path=C:\xampp\htdocs\haai-test
 */
global $wpdb;
$table = $wpdb->prefix . 'haai_chunks';

$post_id = wp_insert_post( array(
	'post_title'   => 'بنچ ۲۰۰۰ تکه',
	'post_content' => 'تست اندازه‌گیری جستجو.',
	'post_status'  => 'publish',
	'post_type'    => 'post',
) );

$t0 = microtime( true );
for ( $i = 0; $i < 2000; $i++ ) {
	$wpdb->insert(
		$table,
		array(
			'post_id'       => $post_id,
			'source'        => 'bench',
			'title'         => 'بنچ',
			'url'           => 'http://local.test/bench',
			'chunk_index'   => $i,
			'content'       => 'کفش چرم مردانه‌ی بنچ شماره‌ی ' . $i . ' ' . str_repeat( 'واژه ', 40 ),
			'content_norm'  => 'کفش چرم مردانه‌ی بنچ شماره‌ی ' . $i . ' ' . str_repeat( 'واژه ', 40 ),
			'date_updated'  => current_time( 'mysql', true ),
		),
		array( '%d', '%s', '%s', '%s', '%d', '%s', '%s', '%s' )
	);
}
echo '2000 chunks inserted in ' . round( ( microtime( true ) - $t0 ) * 1000 ) . " ms\n";

$wpdb->save_queries = true;
$GLOBALS['wpdb']->queries = array();
$t1   = microtime( true );
$hits = Haa_Search::search( 'کفش چرم', 5, 1500 );
$ms   = round( ( microtime( true ) - $t1 ) * 1000 );
echo 'search hits=' . count( $hits ) . " in {$ms} ms with 2000 chunks\n";

$hot = array();
foreach ( (array) $wpdb->queries as $q ) {
	if ( false !== stripos( $q[0], 'haai_chunks' ) ) {
		$hot[] = $q[0];
	}
}
echo 'hot queries: ' . count( $hot ) . "\n";
if ( $hot ) {
	$sql = preg_replace( '/\s+/', ' ', (string) $hot[0] );
	echo mb_substr( $sql, 0, 500 ) . "\n";
	$explain = $wpdb->get_results( 'EXPLAIN ' . $sql, ARRAY_A );
	echo 'EXPLAIN: ' . wp_json_encode( $explain, JSON_UNESCAPED_UNICODE ) . "\n";
}
$idx = $wpdb->get_results( "SHOW INDEX FROM $table", ARRAY_A );
$names = array();
foreach ( (array) $idx as $r ) {
	$names[] = $r['Key_name'] . ' (' . $r['Column_name'] . ')' . ( '0' === (string) $r['Non_unique'] ? ' UNIQUE' : '' );
}
echo 'indexes: ' . implode( ', ', array_unique( $names ) ) . "\n";

$wpdb->delete( $table, array( 'post_id' => $post_id ) );
wp_delete_post( $post_id, true );
echo "bench cleaned\n";
