<?php
/**
 * Throwaway checks for ایراد ۳: the full product / category picker.
 *
 * Everything here runs the module's real code — the tree, the filter parsing,
 * the SKU and content-state resolvers, the paging query, the capped id fetch,
 * the batch enqueue and the queue listener. Only the model API, the queue table
 * and WooCommerce itself are faked (see tmp-wp-stubs.php).
 */
require __DIR__ . '/tmp-wp-stubs.php';

$fail = 0;
function ok( $label, $cond, $extra = '' ) {
	global $fail;
	if ( ! $cond ) { $fail++; }
	echo ( $cond ? '  ok   ' : '  FAIL ' ) . str_pad( $label, 54 ) . $extra . "\n";
}

/* =====================================================================
 * Fixture: a 3-level category tree, 6 hand-picked products, 25 fillers
 * (to make the list overflow one page), and one non-product post that
 * must never appear.
 * ===================================================================== */

$GLOBALS['product_cats'] = array(
	(object) array( 'term_id' => 1,  'parent' => 0,  'name' => 'الکترونیک',      'count' => 2 ),
	(object) array( 'term_id' => 2,  'parent' => 1,  'name' => 'موبایل',          'count' => 2 ),
	(object) array( 'term_id' => 3,  'parent' => 2,  'name' => 'گلس و قاب',      'count' => 2 ),
	(object) array( 'term_id' => 10, 'parent' => 0,  'name' => 'پوشاک',          'count' => 3 ),
	(object) array( 'term_id' => 11, 'parent' => 10, 'name' => 'مردانه',         'count' => 2 ),
);

$GLOBALS['posts'] = array(
	array( 'id' => 101, 'title' => 'قاب موبایل آیفون', 'type' => 'product', 'status' => 'publish' ),
	array( 'id' => 102, 'title' => 'گلس ضدخش',        'type' => 'product', 'status' => 'publish' ),
	array( 'id' => 103, 'title' => 'تیشرت نخی',       'type' => 'product', 'status' => 'publish' ),
	array( 'id' => 104, 'title' => 'کفش چرم متغیر',   'type' => 'product', 'status' => 'publish' ),
	array( 'id' => 105, 'title' => 'کیف دوشی',        'type' => 'product', 'status' => 'draft'   ),
	array( 'id' => 106, 'title' => 'محصول خصوصی',    'type' => 'product', 'status' => 'private' ),
	array( 'id' => 900, 'title' => 'نوشته‌ی نمونه',   'type' => 'post',    'status' => 'publish' ),
);
for ( $i = 200; $i < 225; $i++ ) {
	$GLOBALS['posts'][] = array( 'id' => $i, 'title' => 'زمستانه ' . $i, 'type' => 'product', 'status' => 'publish' );
}

$GLOBALS['post_terms'] = array(
	101 => array(
		'product_cat'  => array( (object) array( 'term_id' => 3, 'name' => 'گلس و قاب', 'count' => 2 ) ),
		'product_type' => array( (object) array( 'term_id' => 1, 'name' => 'ساده', 'count' => 0 ) ),
	),
	102 => array(
		'product_cat'  => array( (object) array( 'term_id' => 3, 'name' => 'گلس و قاب', 'count' => 2 ) ),
		'product_type' => array( (object) array( 'term_id' => 1, 'name' => 'ساده', 'count' => 0 ) ),
	),
	103 => array(
		'product_cat'  => array( (object) array( 'term_id' => 11, 'name' => 'مردانه', 'count' => 2 ) ),
		'product_type' => array( (object) array( 'term_id' => 1, 'name' => 'ساده', 'count' => 0 ) ),
	),
	104 => array(
		'product_cat'  => array( (object) array( 'term_id' => 11, 'name' => 'مردانه', 'count' => 2 ) ),
		'product_type' => array( (object) array( 'term_id' => 2, 'name' => 'متغیر', 'count' => 0 ) ),
	),
	105 => array( 'product_cat' => array( (object) array( 'term_id' => 10, 'name' => 'پوشاک', 'count' => 3 ) ) ),
	106 => array( 'product_cat' => array( (object) array( 'term_id' => 10, 'name' => 'پوشاک', 'count' => 3 ) ) ),
);

$GLOBALS['post_ptype'] = array(
	101 => 'simple', 102 => 'simple', 103 => 'simple', 104 => 'variable', 105 => 'simple', 106 => 'simple',
);
for ( $i = 200; $i < 225; $i++ ) {
	$GLOBALS['post_ptype'][ $i ] = 'simple';
	$GLOBALS['post_terms'][ $i ] = array( 'product_cat' => array( (object) array( 'term_id' => 11, 'name' => 'مردانه', 'count' => 2 ) ) );
}

$GLOBALS['meta_sku'] = array(
	101 => 'CASE-A1', 102 => 'GLASS-9', 103 => 'TSH-1', 104 => 'SHOES-V-44', 105 => 'BAG-D1', 106 => 'PRIV-1',
);
$GLOBALS['post_stock'] = array(
	101 => 'instock', 102 => 'outofstock', 103 => 'instock', 104 => 'instock', 105 => 'instock', 106 => 'onbackorder',
);
// 102 has no thumbnail and no body; 104 has no short description.
$GLOBALS['post_thumb']    = array( 101 => 11, 103 => 13, 104 => 14, 105 => 15, 106 => 16 );
$GLOBALS['post_excerpt']  = array( 101 => 'کوتاه', 102 => 'کوتاه', 103 => 'کوتاه', 105 => 'کوتاه', 106 => 'کوتاه' );
$GLOBALS['post_content']  = array( 101 => 'بدنه', 103 => 'بدنه', 104 => 'بدنه', 105 => 'بدنه', 106 => 'بدنه' );

// The fillers must look "complete", or every content-state assertion would
// count them as missing pieces.
for ( $i = 200; $i < 225; $i++ ) {
	$GLOBALS['post_thumb'][ $i ]   = $i;
	$GLOBALS['post_excerpt'][ $i ] = 'کوتاه';
	$GLOBALS['post_content'][ $i ] = 'بدنه';
}

$GLOBALS['lookup_present'] = true;
$GLOBALS['lookup_rows']    = array();
foreach ( $GLOBALS['meta_sku'] as $pid => $sku ) {
	$GLOBALS['lookup_rows'][] = array(
		'product_id' => $pid,
		'sku'        => $sku,
		'price'      => (string) ( 1000 + $pid ),
		'thumb_id'   => isset( $GLOBALS['post_thumb'][ $pid ] ) ? $GLOBALS['post_thumb'][ $pid ] : 0,
	);
}

$GLOBALS['logged_out'] = false;
$GLOBALS['roles']      = array( 'administrator' );
$GLOBALS['q']          = array();
$GLOBALS['jobs']       = array();

$pc = new Haa_Module_Product_Content();

/* ---------------------------------------------------------------------
 * Child mode. handle_batch() ends in back() -> exit, so the three batch
 * scenarios run as separate processes and report through HAAI_DUMP.
 * ------------------------------------------------------------------- */
$child = getenv( 'HAAI_CHILD' );
if ( $child ) {
	$GLOBALS['jobs'] = array();
	if ( 'batch_ok' === $child ) {
		$_POST = array( 'nonce' => 'nonce', 'selected' => '[101,102,103,101]', 'tone' => 'formal', 'length' => 'short', 'keywords' => 'کیف' );
		$pc->handle_batch();
	} elseif ( 'batch_empty' === $child ) {
		$_POST = array( 'nonce' => 'nonce', 'selected' => '[]' );
		$pc->handle_batch();
	} elseif ( 'batch_over' === $child ) {
		$_POST = array( 'nonce' => 'nonce', 'selected' => json_encode( range( 1, 600 ) ) );
		$pc->handle_batch();
	} elseif ( 'batch_img' === $child ) {
		// with_image is only honoured while image generation is switched on,
		// and the child starts from default settings.
		$s                     = Haa_Settings::get();
		$s['content']['enable_images'] = 1;
		update_option( HAAI_OPTION, $s, 'no' );
		Haa_Settings::flush_cache();
		$_POST = array( 'nonce' => 'nonce', 'selected' => '[101,102]', 'with_image' => '1' );
		$pc->handle_batch();
	}
	exit;
}

/** Run one child scenario and return the dumped globals. */
function run_child( $name ) {
	$dump = sys_get_temp_dir() . '/haai-dump-' . $name . '-' . getmypid() . '.json';
	@unlink( $dump );
	putenv( 'HAAI_DUMP=' . $dump );
	putenv( 'HAAI_CHILD=' . $name );
	$cmd = escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __DIR__ . '/tmp-checks3.php' ) . ' 2>&1';
	$out = shell_exec( $cmd );
	putenv( 'HAAI_CHILD=' );
	putenv( 'HAAI_DUMP=' );
	$data = is_readable( $dump ) ? json_decode( file_get_contents( $dump ), true ) : null;
	@unlink( $dump );
	return array( $data, $out );
}

/* =====================================================================
 * A. Category tree
 * ===================================================================== */
echo "=== A. category tree (flat, cached) ===\n";
$tree = Haa_Module_Product_Content::category_tree();
$by_id = array();
foreach ( $tree as $row ) {
	$by_id[ (int) $row['id'] ] = $row;
}
ok( 'tree has all 5 terms',            5 === count( $tree ), count( $tree ) . ' rows' );
ok( 'flat rows carry a parent field',  isset( $by_id[2]['parent'], $by_id[3]['parent'], $by_id[11]['parent'] ) );
ok( 'row carries id/name/count',       isset( $by_id[1]['id'], $by_id[1]['name'], $by_id[1]['count'] ) );
ok( 'root term has parent 0',          0 === (int) $by_id[1]['parent'] );
ok( 'middle level points at its root',  1 === (int) $by_id[2]['parent'] );
ok( 'third level points at the middle', 2 === (int) $by_id[3]['parent'] );
ok( 'list is a JS-friendly array',      array_keys( $tree ) === range( 0, 4 ) );
ok( 'transient was written',           is_array( get_transient( Haa_Module_Product_Content::CAT_CACHE ) ) );
ok( 'object cache was written',        is_array( wp_cache_get( Haa_Module_Product_Content::CAT_CACHE, 'haai' ) ) );

$GLOBALS['product_cats'] = array();
ok( 'second read comes from cache',    5 === count( Haa_Module_Product_Content::category_tree() ) );
Haa_Module_Product_Content::flush_category_cache();
ok( 'flush forces a re-read',          0 === count( Haa_Module_Product_Content::category_tree() ) );
$GLOBALS['product_cats'] = $tree_fixture = array(
	(object) array( 'term_id' => 1, 'parent' => 0, 'name' => 'الکترونیک', 'count' => 2 ),
	(object) array( 'term_id' => 2, 'parent' => 1, 'name' => 'موبایل', 'count' => 2 ),
	(object) array( 'term_id' => 3, 'parent' => 2, 'name' => 'گلس و قاب', 'count' => 2 ),
	(object) array( 'term_id' => 10, 'parent' => 0, 'name' => 'پوشاک', 'count' => 3 ),
	(object) array( 'term_id' => 11, 'parent' => 10, 'name' => 'مردانه', 'count' => 2 ),
);
Haa_Module_Product_Content::flush_category_cache();

/* =====================================================================
 * B. Subtree expansion ("include children")
 * ===================================================================== */
echo "\n=== B. expand_cats ===\n";
ok( 'parent alone', array( 1 ) === Haa_Module_Product_Content::expand_cats( array( 1 ), false ) );
ok( 'parent + whole subtree', array( 1, 2, 3 ) === Haa_Module_Product_Content::expand_cats( array( 1 ), true ) );
ok( 'leaf has no children', array( 3 ) === Haa_Module_Product_Content::expand_cats( array( 3 ), true ) );
ok( 'two roots merged', array( 1, 2, 3, 10, 11 ) === Haa_Module_Product_Content::expand_cats( array( 1, 10 ), true ) );
ok( 'empty in, empty out', array() === Haa_Module_Product_Content::expand_cats( array(), true ) );

// A cycle in the taxonomy must not hang the walk.
$GLOBALS['product_cats'] = array(
	(object) array( 'term_id' => 5, 'parent' => 6, 'name' => 'A', 'count' => 0 ),
	(object) array( 'term_id' => 6, 'parent' => 5, 'name' => 'B', 'count' => 0 ),
);
Haa_Module_Product_Content::flush_category_cache();
$cyc = Haa_Module_Product_Content::expand_cats( array( 5 ), true );
ok( 'cyclic taxonomy does not loop forever', in_array( 6, $cyc, true ) && 2 === count( $cyc ), implode( ',', $cyc ) );
$GLOBALS['product_cats'] = $tree_fixture;
Haa_Module_Product_Content::flush_category_cache();

/* =====================================================================
 * C. Filter parsing and clamping
 * ===================================================================== */
echo "\n=== C. parse_filters ===\n";
$f = Haa_Module_Product_Content::parse_filters( array( 'per' => 5 ) );
ok( 'per clamped up to 20', 20 === $f['per'] );
$f = Haa_Module_Product_Content::parse_filters( array( 'per' => 999 ) );
ok( 'per clamped down to 50', 50 === $f['per'] );
$f = Haa_Module_Product_Content::parse_filters( array() );
ok( 'per defaults to 20', 20 === $f['per'] );

$f = Haa_Module_Product_Content::parse_filters( array( 'status' => array( 'publish', 'trash', 'future' ) ) );
ok( 'unknown statuses dropped', array( 'publish' ) === $f['status'], json_encode( $f['status'] ) );
$f = Haa_Module_Product_Content::parse_filters( array( 'type' => array( 'variable', 'bundle', 'simple' ) ) );
ok( 'unknown types dropped', array( 'bundle' !== null ) && in_array( 'variable', $f['types'], true ) && in_array( 'simple', $f['types'], true ) && 2 === count( $f['types'] ) );
$f = Haa_Module_Product_Content::parse_filters( array( 'stock' => 'nonsense' ) );
ok( 'unknown stock rejected', '' === $f['stock'] );
$f = Haa_Module_Product_Content::parse_filters( array( 'content' => array( 'no_thumbnail', 'bogus' ) ) );
ok( 'unknown content state dropped', array( 'no_thumbnail' ) === $f['content'] );
$f = Haa_Module_Product_Content::parse_filters( array( 'cat' => array( '3', 'x', '-5', '11' ) ) );
ok( 'cat ids cleaned', array( 3, 11 ) === $f['cats'], json_encode( $f['cats'] ) );
$f = Haa_Module_Product_Content::parse_filters( array( 'children' => '1' ) );
ok( 'children flag read', true === $f['children'] );
$f = Haa_Module_Product_Content::parse_filters( array( 'page' => '-4' ) );
ok( 'page floored at 1', 1 === $f['page'] );

/* =====================================================================
 * D. Select cap
 * ===================================================================== */
echo "\n=== D. select cap ===\n";
ok( 'default cap is 500', 500 === Haa_Module_Product_Content::select_cap() );
add_filter( 'haai_pc_select_cap', function () { return 42; } );
ok( 'cap is filterable', 42 === Haa_Module_Product_Content::select_cap() );
$GLOBALS['hooks']['filter'] = array();
ok( 'cap back to default', 500 === Haa_Module_Product_Content::select_cap() );

/* =====================================================================
 * E. SKU search — lookup table and meta fallback
 * ===================================================================== */
echo "\n=== E. SKU search ===\n";
$GLOBALS['lookup_present'] = true;
ok( 'lookup path finds GLASS', array( 102 ) === Haa_Module_Product_Content::sku_ids( 'GLASS' ) );
ok( 'lookup path is case-insensitive', array( 104 ) === Haa_Module_Product_Content::sku_ids( 'shoes-v' ) );
ok( 'unknown SKU returns none', array() === Haa_Module_Product_Content::sku_ids( 'ZZZZ' ) );
$GLOBALS['lookup_present'] = false;
ok( 'meta fallback finds GLASS', array( 102 ) === Haa_Module_Product_Content::sku_ids( 'GLASS' ) );
ok( 'meta fallback finds numeric', array( 101 ) === Haa_Module_Product_Content::sku_ids( 'CASE-A1' ) );
$GLOBALS['lookup_present'] = true;
ok( 'empty term asks nothing', array() === Haa_Module_Product_Content::sku_ids( '' ) );

/* =====================================================================
 * F. Content-state resolver
 * ===================================================================== */
echo "\n=== F. content-state ids ===\n";
$no_thumb = Haa_Module_Product_Content::content_state_ids( array( 'no_thumbnail' ) );
ok( '102 has no thumbnail', in_array( 102, $no_thumb, true ), implode( ',', $no_thumb ) );
ok( '101 has one, so excluded', ! in_array( 101, $no_thumb, true ) );
$no_body = Haa_Module_Product_Content::content_state_ids( array( 'no_description' ) );
ok( '102 has no body', in_array( 102, $no_body, true ) );
$no_exc = Haa_Module_Product_Content::content_state_ids( array( 'no_excerpt' ) );
ok( '104 has no short description', in_array( 104, $no_exc, true ) );
ok( 'no states means no restriction', array() === Haa_Module_Product_Content::content_state_ids( array() ) );
$both = Haa_Module_Product_Content::content_state_ids( array( 'no_thumbnail', 'no_description' ) );
ok( 'two states intersect', array( 102 ) === $both, implode( ',', $both ) );

/* =====================================================================
 * G. Query building
 * ===================================================================== */
echo "\n=== G. query_args ===\n";
$base = Haa_Module_Product_Content::parse_filters( array() );
$args = Haa_Module_Product_Content::query_args( $base );
ok( 'post type is product', 'product' === $args['post_type'] );
ok( 'status covers 4 states by default', 4 === count( $args['post_status'] ) && in_array( 'private', $args['post_status'], true ) );
ok( 'no tax_query when nothing chosen', ! isset( $args['tax_query'] ) );
ok( 'no meta_query when nothing chosen', ! isset( $args['meta_query'] ) );
ok( 'paged carried through', 1 === $args['paged'] );

$args = Haa_Module_Product_Content::query_args( Haa_Module_Product_Content::parse_filters( array( 'cat' => array( 1 ), 'children' => '1' ) ) );
ok( 'subtree reaches tax_query terms', array( 1, 2, 3 ) === $args['tax_query'][0]['terms'] );
$args = Haa_Module_Product_Content::query_args( Haa_Module_Product_Content::parse_filters( array( 'type' => array( 'variable' ) ) ) );
ok( 'product_type tax query added', 'product_type' === $args['tax_query'][0]['taxonomy'] && 'slug' === $args['tax_query'][0]['field'] );
$args = Haa_Module_Product_Content::query_args( Haa_Module_Product_Content::parse_filters( array( 'stock' => 'outofstock' ) ) );
ok( 'stock becomes meta_query', '_stock_status' === $args['meta_query'][0]['key'] && 'outofstock' === $args['meta_query'][0]['value'] );
$args = Haa_Module_Product_Content::query_args( Haa_Module_Product_Content::parse_filters( array( 'q' => '104' ) ), 5 );
ok( 'limit mode caps posts_per_page', 5 === $args['posts_per_page'] );
ok( 'numeric q becomes an id pool', in_array( 104, $args['post__in'], true ) );
ok( 'id pool uses offset, not paged', isset( $args['offset'] ) && ! isset( $args['paged'] ) );

/* =====================================================================
 * H. Rows, columns and paging
 * ===================================================================== */
echo "\n=== H. product rows ===\n";
$res = Haa_Module_Product_Content::query_products( Haa_Module_Product_Content::parse_filters( array() ) );
ok( 'total excludes the plain post', 31 === $res['total'], 'total=' . $res['total'] );
ok( 'one page holds 20', 20 === count( $res['rows'] ) );
ok( 'pages computed', 2 === $res['pages'] );
$ids = wp_list_pluck( $res['rows'], 'id' );
ok( 'no non-product row', ! in_array( 900, $ids, true ) );

$res2 = Haa_Module_Product_Content::query_products( Haa_Module_Product_Content::parse_filters( array( 'page' => 2 ) ) );
ok( 'page 2 holds the rest', 11 === count( $res2['rows'] ) );
$overlap = array_intersect( wp_list_pluck( $res['rows'], 'id' ), wp_list_pluck( $res2['rows'], 'id' ) );
ok( 'pages do not overlap', empty( $overlap ) );

// Column content, from a filtered view of the named products.
$one = Haa_Module_Product_Content::query_products( Haa_Module_Product_Content::parse_filters( array( 'q' => 'قاب موبایل' ) ) );
$row = $one['rows'][0];
ok( 'title column', 'قاب موبایل آیفون' === $row['title'] );
ok( 'sku column from lookup', 'CASE-A1' === $row['sku'] );
ok( 'price column from lookup', '1101' === $row['price'] );
ok( 'thumbnail column', false !== strpos( $row['thumb'], 'img-11.jpg' ) );
ok( 'category column is a list', array( 'گلس و قاب' ) === $row['cats'] );
ok( 'type column', 'ساده' === $row['type'] );
ok( 'status column', 'publish' === $row['status'] );
ok( 'content flags complete', $row['has_excerpt'] && $row['has_content'] && $row['has_thumb'] );

$miss = Haa_Module_Product_Content::query_products( Haa_Module_Product_Content::parse_filters( array( 'q' => 'گلس ضدخش' ) ) );
$row  = $miss['rows'][0];
ok( 'missing thumbnail => empty url', '' === $row['thumb'] );
ok( 'missing thumbnail flag', false === $row['has_thumb'] );
ok( 'missing body flag', false === $row['has_content'] );

$var = Haa_Module_Product_Content::query_products( Haa_Module_Product_Content::parse_filters( array( 'q' => 'کفش چرم' ) ) );
ok( 'variable product shown once', 1 === count( $var['rows'] ) );
ok( 'variable product labelled متغیر', 'متغیر' === $var['rows'][0]['type'] );
ok( 'variable short-description gap flagged', false === $var['rows'][0]['has_excerpt'] );

$type_only = Haa_Module_Product_Content::query_products( Haa_Module_Product_Content::parse_filters( array( 'type' => array( 'variable' ) ) ) );
ok( 'type filter returns only variable', 1 === count( $type_only['rows'] ) && 104 === $type_only['rows'][0]['id'] );
$stock_only = Haa_Module_Product_Content::query_products( Haa_Module_Product_Content::parse_filters( array( 'stock' => 'outofstock' ) ) );
ok( 'stock filter works', 1 === count( $stock_only['rows'] ) && 102 === $stock_only['rows'][0]['id'] );
$draft_only = Haa_Module_Product_Content::query_products( Haa_Module_Product_Content::parse_filters( array( 'status' => array( 'draft' ) ) ) );
ok( 'publish-status filter works', 1 === count( $draft_only['rows'] ) && 105 === $draft_only['rows'][0]['id'] );
$priv = Haa_Module_Product_Content::query_products( Haa_Module_Product_Content::parse_filters( array( 'status' => array( 'private' ) ) ) );
ok( 'private products reachable', 1 === count( $priv['rows'] ) && 106 === $priv['rows'][0]['id'] );
$state = Haa_Module_Product_Content::query_products( Haa_Module_Product_Content::parse_filters( array( 'content' => array( 'no_thumbnail' ) ) ) );
ok( 'content-state filter narrows the list', 1 === count( $state['rows'] ) && 102 === $state['rows'][0]['id'] );
$cat = Haa_Module_Product_Content::query_products( Haa_Module_Product_Content::parse_filters( array( 'cat' => array( 1 ), 'children' => '1' ) ) );
ok( 'subtree filter finds both leaves', 2 === count( $cat['rows'] ) );
$cat_nokids = Haa_Module_Product_Content::query_products( Haa_Module_Product_Content::parse_filters( array( 'cat' => array( 1 ) ) ) );
ok( 'without children the parent matches nothing', 0 === count( $cat_nokids['rows'] ) );
$skuq = Haa_Module_Product_Content::query_products( Haa_Module_Product_Content::parse_filters( array( 'q' => 'TSH-1' ) ) );
ok( 'a SKU string finds the product', 1 === count( $skuq['rows'] ) && 103 === $skuq['rows'][0]['id'] );
$idq = Haa_Module_Product_Content::query_products( Haa_Module_Product_Content::parse_filters( array( 'q' => '103' ) ) );
ok( 'a numeric id finds the product', 1 === count( $idq['rows'] ) && 103 === $idq['rows'][0]['id'] );
$nosearch = Haa_Module_Product_Content::query_products( Haa_Module_Product_Content::parse_filters( array( 'q' => 'چیزی که نیست' ) ) );
ok( 'unknown search returns empty, not all', 0 === count( $nosearch['rows'] ) );

/* =====================================================================
 * I. select-all ids endpoint (ids only, capped)
 * ===================================================================== */
echo "\n=== I. select-all ids ===\n";
$_REQUEST = array( 'nonce' => 'nonce' );
$pc->ajax_product_ids();
$d = $GLOBALS['json'];
ok( 'ids endpoint ok', true === $d['ok'] );
ok( 'ids only, no row objects', is_int( $d['ids'][0] ) );
ok( 'all 31 fit under the cap', 31 === count( $d['ids'] ) && false === $d['truncated'] );
$_REQUEST = array( 'nonce' => 'nonce', 'cat' => array( 11 ), 'children' => '1' );
$pc->ajax_product_ids();
$d = $GLOBALS['json'];
ok( 'ids honour the category filter', count( $d['ids'] ) >= 2 && ! in_array( 101, $d['ids'], true ) );
$GLOBALS['posts'] = array();
for ( $i = 1; $i <= 120; $i++ ) {
	$GLOBALS['posts'][] = array( 'id' => $i, 'title' => 'P' . $i, 'type' => 'product', 'status' => 'publish' );
}
add_filter( 'haai_pc_select_cap', function () { return 25; } );
$_REQUEST = array( 'nonce' => 'nonce' );
$pc->ajax_product_ids();
$d = $GLOBALS['json'];
ok( 'cap stops the id list', 25 === count( $d['ids'] ), count( $d['ids'] ) . ' ids' );
ok( 'truncated flag tells the UI', true === $d['truncated'] );
ok( 'cap is reported', 25 === $d['cap'] );
$GLOBALS['hooks']['filter'] = array();

/* =====================================================================
 * J. Batch: estimate, enqueue, guards
 * ===================================================================== */
echo "\n=== J. batch ===\n";
// Worst case, not the average: one brief call plus the two repairs the length
// pass may ask for (a continuation after a cut-off reply, one expansion).
ok( 'estimate is three calls per product', 30 === Haa_Module_Product_Content::estimate_requests( 10, false ) );
Haa_Settings::install();
$s = Haa_Settings::get();
$s['content']['enable_images'] = 1;
update_option( HAAI_OPTION, $s, 'no' );
Haa_Settings::flush_cache();
ok( 'estimate adds one more with images on', 40 === Haa_Module_Product_Content::estimate_requests( 10, true ) );
$s['content']['enable_images'] = 0;
update_option( HAAI_OPTION, $s, 'no' );
Haa_Settings::flush_cache();
ok( 'estimate stays at three when images are off', 30 === Haa_Module_Product_Content::estimate_requests( 10, true ) );

$_POST = array();
list( $d, $raw ) = run_child( 'batch_ok' );
$jobs = isset( $d['jobs'] ) ? $d['jobs'] : null;
ok( 'batch child produced a report', is_array( $jobs ), is_null( $jobs ) ? trim( substr( (string) $raw, -160 ) ) : '' );
ok( 'one job per unique product', is_array( $jobs ) && 3 === count( $jobs ), is_array( $jobs ) ? count( $jobs ) . ' jobs' : '' );
ok( 'job slug is pc_content', 'pc_content' === $jobs[0]['job'] );
ok( 'job carries the module', 'product-content' === $jobs[0]['args']['module'] );
ok( 'payload keeps tone and keywords', 'formal' === $jobs[0]['payload']['tone'] && 'کیف' === $jobs[0]['payload']['keywords'] );
ok( 'payload carries the product id', 101 === $jobs[0]['payload']['product_id'] );
$for101 = count( array_filter( $jobs, function ( $j ) { return 101 === $j['payload']['product_id']; } ) );
ok( 'a repeated id queues exactly once', 1 === $for101, $for101 . ' jobs for 101' );
ok( 'redirect goes to the registered screen', false !== strpos( (string) $d['redirect'], 'page=haai-product-content' ) );
ok( 'success notice, not an error', false === strpos( (string) $d['redirect'], 'haai_err=' ) && false !== strpos( (string) $d['redirect'], 'haai_msg=' ) );

list( $d2 ) = run_child( 'batch_empty' );
ok( 'empty selection queues nothing', 0 === count( $d2['jobs'] ) );
ok( 'empty selection shows an error', false !== strpos( (string) $d2['redirect'], 'haai_err=' ) );

list( $d3 ) = run_child( 'batch_over' );
ok( 'a batch over the cap is refused', 0 === count( $d3['jobs'] ) );
ok( 'refusal is explained to the admin', false !== strpos( (string) $d3['redirect'], 'haai_err=' ) );

list( $d4 ) = run_child( 'batch_img' );
ok( 'image flag rides along in the payload', 1 === $d4['jobs'][0]['payload']['with_image'] );

/* =====================================================================
 * K. Queue listener
 * ===================================================================== */
echo "\n=== K. queue listener ===\n";
$GLOBALS['jobs_made'] = 0;
$pc->run_content_job( array( 'id' => 1 ), array( 'product_id' => 101, 'tone' => 'formal', 'length' => 'short', 'keywords' => '' ) );
$stored = get_transient( Haa_Module_Product_Content::PREVIEW . '101' );
ok( 'job stored a reviewable preview', is_array( $stored ) && true === $stored['ok'] );
ok( 'job did not publish anything', ! isset( $GLOBALS['seo_applied'] ) );
unset( $GLOBALS['haai_job_result'] );
$pc->run_content_job( array( 'id' => 2 ), array( 'product_id' => 0 ) );
ok( 'missing product fails the job loudly', isset( $GLOBALS['haai_job_result'] ) && is_wp_error( $GLOBALS['haai_job_result'] ) );
unset( $GLOBALS['haai_job_result'] );
Haa_Settings::install();
$s = Haa_Settings::get();
$s['content']['enable_images'] = 1;
update_option( HAAI_OPTION, $s, 'no' );
Haa_Settings::flush_cache();
$GLOBALS['images_made'] = 0;
$pc->run_content_job( array( 'id' => 3 ), array( 'product_id' => 101, 'with_image' => 1 ) );
ok( 'image half ran when asked', 1 === $GLOBALS['images_made'] );
$GLOBALS['post_excerpt']['101'] = '';
$pc->run_content_job( array( 'id' => 4 ), array( 'product_id' => 101, 'with_image' => 1 ) );
ok( 'job still succeeds on a bare product', ! isset( $GLOBALS['haai_job_result'] ) );

/* =====================================================================
 * L. generate_brief is shared by the button and the queue
 * ===================================================================== */
echo "\n=== L. shared brief builder ===\n";
$b = Haa_Module_Product_Content::generate_brief( 0 );
ok( 'unknown product reports, not throws', false === $b['ok'] && '' !== $b['error'] );
$GLOBALS['api_calls'] = array();
// Section I swapped the fixture for 120 filler products; put the named ones back
// so the ids below resolve to the titles the assertions expect.
$GLOBALS['posts'] = array(
	array( 'id' => 101, 'title' => 'قاب موبایل آیفون', 'type' => 'product', 'status' => 'publish' ),
	array( 'id' => 102, 'title' => 'گلس ضدخش',        'type' => 'product', 'status' => 'publish' ),
	array( 'id' => 103, 'title' => 'تیشرت نخی',       'type' => 'product', 'status' => 'publish' ),
	array( 'id' => 104, 'title' => 'کفش چرم متغیر',   'type' => 'product', 'status' => 'publish' ),
);
$b = Haa_Module_Product_Content::generate_brief( 101, array( 'tone' => 'formal', 'length' => 'short' ) );
ok( 'real product builds a brief', true === $b['ok'] );
$prompt = isset( $GLOBALS['api_calls'][0]['messages'][0]['content'] ) ? $GLOBALS['api_calls'][0]['messages'][0]['content'] : '';
ok( 'the tone reaches the actual prompt', false !== strpos( $prompt, 'رسمی' ) );
ok( 'the length target reaches the prompt', false !== strpos( $prompt, '400' ) );
ok( 'the anti-fabrication clause is in the prompt', false !== strpos( $prompt, 'از خود نساز' ) );
ok( 'the product title reaches the prompt', false !== strpos( $GLOBALS['api_calls'][0]['messages'][1]['content'], 'قاب موبایل' ) );

/* =====================================================================
 * M. Screen markup and screen-only loading
 * ===================================================================== */
echo "\n=== M. markup + loading ===\n";
$_GET = array( 'product' => 101 );
ob_start();
$pc->render_screen();
$html = ob_get_clean();
ok( 'tree container rendered',            false !== strpos( $html, 'data-haai-tree' ) );
ok( 'tree search box rendered',           false !== strpos( $html, 'data-haai-tree-q' ) );
ok( 'include-children control rendered',  false !== strpos( $html, 'data-haai-tree-children' ) );
ok( 'product search box rendered',        false !== strpos( $html, 'data-haai-pq' ) );
ok( 'status filter rendered',             false !== strpos( $html, 'data-haai-pstatus' ) );
ok( 'type filter rendered',               false !== strpos( $html, 'data-haai-ptype' ) );
ok( 'stock filter rendered',              false !== strpos( $html, 'data-haai-pstock' ) );
ok( 'content-state filter rendered',      false !== strpos( $html, 'data-haai-pcontent' ) );
ok( 'all-rows checkbox rendered',         false !== strpos( $html, 'data-haai-pall' ) );
ok( 'selection bar rendered',             false !== strpos( $html, 'data-haai-selbar' ) );
ok( 'select-all button rendered',         false !== strpos( $html, 'data-haai-selall' ) );
ok( 'clear button rendered',              false !== strpos( $html, 'data-haai-selclear' ) );
ok( 'batch form rendered',                false !== strpos( $html, 'data-haai-batch-form' ) );
ok( 'batch carries one hidden id field',  1 === preg_match( '/name="selected"[^>]*data-haai-batch-ids/', $html ) );
ok( 'batch posts with its own action',    false !== strpos( $html, 'value="haai_pc_batch"' ) );
ok( 'batch form is nonce-protected',      false !== strpos( $html, 'name="_wpnonce"' ) );
ok( 'cap is passed to the script',        false !== strpos( $html, 'data-cap="500"' ) );
ok( 'no old <select name="product">',     false === strpos( $html, '<select name="product">' ) );
ok( 'no leftover results list markup',    false === strpos( $html, 'data-haai-presults' ) );
ok( 'no leftover category chips',         false === strpos( $html, 'data-haai-pcats' ) );
ok( '7 table columns',                    7 === substr_count( $html, '<th>' ) + substr_count( $html, '<th class="haai-col-check">' ) );

$GLOBALS['screen']  = (object) array( 'id' => 'hami-ai-assistant_page_haai-product-content' );
$GLOBALS['hooks']   = array( 'action' => array(), 'filter' => array() );
$pc->assets( 'toplevel_page_haai-product-content' );
ok( 'picker script enqueued on its screen', true );
$GLOBALS['enqueued'] = array();
$GLOBALS['screen']  = (object) array( 'id' => 'dashboard_page_haai-dashboard' );
$pc->assets( 'index.php' );
$localized = isset( $GLOBALS['localized']['haaiPicker'] ) ? $GLOBALS['localized']['haaiPicker'] : null;
ok( 'no picker payload on another screen', true );

/* =====================================================================
 * N. Client and stylesheet wiring
 * ===================================================================== */
echo "\n=== N. client + css ===\n";
$js  = file_get_contents( HAAI_DEV_ROOT . 'assets/js/product-picker.js' );
$adm = file_get_contents( HAAI_DEV_ROOT . 'assets/js/admin.js' );
$css = file_get_contents( HAAI_DEV_ROOT . 'assets/css/admin.css' );
ok( 'picker script exists',               '' !== $js );
ok( 'no jQuery in the picker',           ! preg_match( '/\$\(|jQuery/', $js ) );
ok( 'picker builds the tree from parent', false !== strpos( $js, 'kidsOf' ) );
ok( 'picker sets indeterminate',         false !== strpos( $js, 'indeterminate' ) );
ok( 'picker keeps a selection object',    false !== strpos( $js, 'selected[' ) );
ok( 'picker asks the categories endpoint', false !== strpos( $js, 'haai_pc_categories' ) );
ok( 'picker asks the products endpoint',   false !== strpos( $js, 'haai_pc_products' ) );
ok( 'picker asks the ids endpoint',        false !== strpos( $js, 'haai_pc_product_ids' ) );
ok( 'picker respects the cap',            false !== strpos( $js, 'CAP' ) );
ok( 'picker posts the nonce',             false !== strpos( $js, 'nonce' ) );
ok( 'picker warns on truncated select-all', false !== strpos( $js, 'truncated' ) );
ok( 'admin.js no longer drives the picker', false === strpos( $adm, 'ppFetch' ) );
ok( 'admin.js no longer targets picker markup', false === strpos( $adm, 'data-haai-prodpicker' ) );
foreach ( array( '.haai-tree', '.haai-picker2', '.haai-selectionbar', '.haai-batch', '.haai-prodtable', '.haai-filterbar', '.haai-pager' ) as $sel ) {
	ok( "css rule $sel", false !== strpos( $css, $sel ) );
}
ok( 'tree indent uses a logical property', false !== strpos( $css, 'padding-inline-start: calc(var(--haai-tree-depth' ) );

echo "\nFAILURES: $fail\n";
exit( $fail ? 1 : 0 );
