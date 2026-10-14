<?php
/**
 * Repeatable sample data for the HAAI real-WordPress test bench.
 *
 * Run:  php C:\xampp\wp-cli\wp-cli.phar eval-file dev-tests/wp-env-setup.php
 *       --path=C:\xampp\htdocs\haai-test
 *
 * Creates (after removing anything it made before):
 *   - a 3-level product category tree
 *   - about 30 products: simple, variable, with SKU, on sale, out of stock,
 *     drafts, and a price ladder for rank queries
 * Re-running is safe: every seeded object carries _haai_seed and is deleted first.
 */

if ( ! defined( 'ABSPATH' ) ) {
	fwrite( STDERR, "Run through WP-CLI: wp eval-file dev-tests/wp-env-setup.php\n" );
	exit( 1 );
}

if ( ! class_exists( 'WooCommerce' ) ) {
	fwrite( STDERR, "WooCommerce is not active.\n" );
	exit( 1 );
}

/**
 * Delete everything a previous run seeded, variations included.
 */
function haai_seed_wipe() {
	$ids = get_posts(
		array(
			'post_type'      => 'product',
			'post_status'    => 'any',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'meta_key'       => '_haai_seed',
			'meta_value'     => 1,
		)
	);
	foreach ( $ids as $id ) {
		$children = get_children(
			array(
				'post_parent' => (int) $id,
				'post_type'   => 'product_variation',
				'post_status' => 'any',
				'fields'      => 'ids',
			)
		);
		foreach ( $children as $child ) {
			wp_delete_post( (int) $child, true );
		}
		wp_delete_post( (int) $id, true );
	}
	// Terms are re-created empty every time; drop the seeded tree too.
	foreach ( array( 'کفش', 'کیف', 'ست هدیه' ) as $top ) {
		$t = get_term_by( 'name', $top, 'product_cat' );
		if ( $t ) {
			wp_delete_term( (int) $t->term_id, 'product_cat' );
		}
	}
	return count( $ids );
}

/**
 * Make sure a product_cat exists at the right depth.
 *
 * @param string $name Name.
 * @param int    $parent Parent term id.
 * @return int
 */
function haai_seed_term( $name, $parent = 0 ) {
	$existing = get_term_by( 'name', $name, 'product_cat' );
	if ( $existing && (int) $existing->parent === (int) $parent ) {
		return (int) $existing->term_id;
	}
	$res = wp_insert_term( $name, 'product_cat', array( 'parent' => (int) $parent ) );
	return is_wp_error( $res ) ? 0 : (int) $res['term_id'];
}

/**
 * A simple product with a SKU, price and stock state.
 *
 * @param array $args Name, sku, price, sale, stock, status, cats.
 * @return int Post id.
 */
function haai_seed_simple( $args ) {
	$product = new WC_Product_Simple();
	$product->set_name( $args['name'] );
	$product->set_sku( $args['sku'] );
	$product->set_regular_price( (string) $args['price'] );
	if ( ! empty( $args['sale'] ) ) {
		$product->set_sale_price( (string) $args['sale'] );
		$product->set_date_on_sale_from( strtotime( '-5 days' ) );
		$product->set_date_on_sale_to( strtotime( '+30 days' ) );
	}
	$product->set_status( isset( $args['status'] ) ? $args['status'] : 'publish' );
	$product->set_catalog_visibility( 'visible' );
	$product->set_manage_stock( true );
	$product->set_stock_quantity( isset( $args['qty'] ) ? $args['qty'] : 12 );
	$product->set_stock_status( isset( $args['stock'] ) ? $args['stock'] : 'instock' );
	$product->set_description( 'توضیح نمونه‌ی آزمایشی برای «' . $args['name'] . '». این متن برای ایندکس صفحه‌ی محصول ساخته شده است.' );
	$product->set_short_description( 'خلاصه‌ی کوتاه برای «' . $args['name'] . '».' );
	$id = $product->save();
	update_post_meta( $id, '_haai_seed', 1 );
	if ( ! empty( $args['cats'] ) ) {
		wp_set_object_terms( $id, array_map( 'intval', $args['cats'] ), 'product_cat' );
	}
	return (int) $id;
}

/**
 * A variable product with size and colour attributes plus four variations.
 *
 * @param array $args Name, sku, prices, stock, cats.
 * @return int Post id.
 */
function haai_seed_variable( $args ) {
	$product = new WC_Product_Variable();
	$product->set_name( $args['name'] );
	$product->set_sku( $args['sku'] );
	$product->set_status( 'publish' );
	$product->set_description( 'محصول متغیر نمونه: «' . $args['name'] . '» با اندازه‌های ۴۰ تا ۴۳.' );
	$product->set_short_description( 'خلاصه‌ی محصول متغیر «' . $args['name'] . '».' );

	$color = new WC_Product_Attribute();
	$color->set_id( 0 );
	$color->set_name( 'رنگ' );
	$color->set_options( array( 'مشکی', 'قهوه‌ای' ) );
	$color->set_visible( true );
	$color->set_variation( true );

	$size = new WC_Product_Attribute();
	$size->set_id( 0 );
	$size->set_name( 'اندازه' );
	$size->set_options( array( '۴۰', '۴۱', '۴۲', '۴۳' ) );
	$size->set_visible( true );
	$size->set_variation( true );

	$product->set_attributes( array( $color, $size ) );
	$id = $product->save();
	update_post_meta( $id, '_haai_seed', 1 );
	if ( ! empty( $args['cats'] ) ) {
		wp_set_object_terms( $id, array_map( 'intval', $args['cats'] ), 'product_cat' );
	}

	$n = 0;
	foreach ( array( 'مشکی', 'قهوه‌ای' ) as $col ) {
		foreach ( array( '۴۰', '۴۱' ) as $sz ) {
			$variation = new WC_Product_Variation();
			$variation->set_parent_id( $id );
			$variation->set_sku( $args['sku'] . '-' . $col . '-' . $sz );
			$variation->set_regular_price( (string) ( $args['price'] + ( $n * 25000 ) ) );
			$variation->set_manage_stock( true );
			$variation->set_stock_quantity( 3 + $n );
			$variation->set_stock_status( 'instock' );
			$variation->set_attributes( array( 'رنگ' => $col, 'اندازه' => $sz ) );
			$variation->save();
			$n++;
		}
	}
	WC_Product_Variable::sync( $id );
	return (int) $id;
}

$wiped = haai_seed_wipe();

/* ---- The 3-level tree ----
 * کفش > کفش مردانه > کفش چرم مردانه
 *      \ کفش زنانه   \ کفش تابستانی
 * کیف > کیف دوشی     > کیف چرم دوشی
 */
$shoes = haai_seed_term( 'کفش' );
$men   = haai_seed_term( 'کفش مردانه', $shoes );
$leather_men = haai_seed_term( 'کفش چرم مردانه', $men );
$summer = haai_seed_term( 'کفش تابستانی', $men );
$women = haai_seed_term( 'کفش زنانه', $shoes );
$bag   = haai_seed_term( 'کیف' );
$shoulder = haai_seed_term( 'کیف دوشی', $bag );
$leather_bag = haai_seed_term( 'کیف چرم دوشی', $shoulder );
$gift  = haai_seed_term( 'ست هدیه' );

$simple = array(
	array( 'name' => 'کفش چرم مردانه‌ی کلاسیک', 'sku' => 'HAAI-S-001', 'price' => 1890000, 'stock' => 'instock', 'qty' => 9,  'cats' => array( $leather_men ) ),
	array( 'name' => 'کفش چرم مردانه‌ی اداری', 'sku' => 'HAAI-S-002', 'price' => 2450000, 'sale' => 1990000, 'stock' => 'instock', 'qty' => 7, 'cats' => array( $leather_men ) ),
	array( 'name' => 'کفش پیاده‌روی سبک', 'sku' => 'HAAI-S-003', 'price' => 780000, 'stock' => 'instock', 'qty' => 25, 'cats' => array( $summer ) ),
	array( 'name' => 'کفش کتانی تابستانی', 'sku' => 'HAAI-S-004', 'price' => 495000, 'stock' => 'instock', 'qty' => 40, 'cats' => array( $summer ) ),
	array( 'name' => 'کفش زنانه‌ی پاشنه‌بلند', 'sku' => 'HAAI-S-005', 'price' => 1350000, 'stock' => 'instock', 'qty' => 15, 'cats' => array( $women ) ),
	array( 'name' => 'کفش زنانه‌ی تخت', 'sku' => 'HAAI-S-006', 'price' => 890000, 'stock' => 'outofstock', 'qty' => 0, 'cats' => array( $women ) ),
	array( 'name' => 'کیف دوشی چرم طبیعی', 'sku' => 'HAAI-S-007', 'price' => 2150000, 'stock' => 'instock', 'qty' => 6, 'cats' => array( $leather_bag ) ),
	array( 'name' => 'کیف دوشی روزمره', 'sku' => 'HAAI-S-008', 'price' => 650000, 'sale' => 520000, 'stock' => 'instock', 'qty' => 18, 'cats' => array( $shoulder ) ),
	array( 'name' => 'کیف پول چرم کوچک', 'sku' => 'HAAI-S-009', 'price' => 380000, 'stock' => 'instock', 'qty' => 30, 'cats' => array( $leather_bag ) ),
	array( 'name' => 'کفش اسنیکر کلاسیک', 'sku' => 'HAAI-S-010', 'price' => 1580000, 'stock' => 'instock', 'qty' => 11, 'cats' => array( $summer ) ),
	array( 'name' => 'کفش چرم نیمه‌بوت مشکی', 'sku' => 'HAAI-S-011', 'price' => 2250000, 'stock' => 'outofstock', 'qty' => 0, 'cats' => array( $leather_men ) ),
	array( 'name' => 'ست هدیه‌ی مراقبت از کفش', 'sku' => 'HAAI-S-012', 'price' => 450000, 'stock' => 'instock', 'qty' => 22, 'cats' => array( $gift ) ),
	array( 'name' => 'کفش ورزشی دویدن', 'sku' => 'HAAI-S-013', 'price' => 1980000, 'stock' => 'instock', 'qty' => 8, 'cats' => array( $summer ) ),
	array( 'name' => 'صندل چرم تابستانی', 'sku' => 'HAAI-S-014', 'price' => 690000, 'stock' => 'instock', 'qty' => 14, 'cats' => array( $summer ) ),
	array( 'name' => 'کفش رسمی مردانه‌ی مجلسی', 'sku' => 'HAAI-S-015', 'price' => 3200000, 'stock' => 'instock', 'qty' => 4, 'cats' => array( $leather_men ) ),
	array( 'name' => 'کفش اداری بدون بند', 'sku' => 'HAAI-S-016', 'price' => 2650000, 'stock' => 'outofstock', 'qty' => 0, 'cats' => array( $leather_men ) ),
	array( 'name' => 'کوله پشتی کوچک چرم', 'sku' => 'HAAI-S-017', 'price' => 1750000, 'stock' => 'instock', 'qty' => 10, 'cats' => array( $leather_bag ) ),
	array( 'name' => 'کیف laptopy ۱۵ اینچی', 'sku' => 'HAAI-S-018', 'price' => 1250000, 'sale' => 990000, 'stock' => 'instock', 'qty' => 16, 'cats' => array( $leather_bag ) ),
	array( 'name' => 'کفش خانه‌ی نرم', 'sku' => 'HAAI-S-019', 'price' => 320000, 'stock' => 'instock', 'qty' => 50, 'cats' => array( $gift ) ),
	array( 'name' => 'جوراب کفش چرم (بسته ۳ عددی)', 'sku' => 'HAAI-S-020', 'price' => 180000, 'stock' => 'instock', 'qty' => 60, 'cats' => array( $gift ) ),
);

$made_simple = 0;
foreach ( $simple as $args ) {
	haai_seed_simple( $args );
	$made_simple++;
}

/* Price ladder in the leaf category, for «زیر ۵۰۰ هزار تومان» style queries. */
$ladder = array( 150000, 260000, 340000, 430000, 480000, 520000, 950000, 1450000, 2450000, 4200000 );
$made_ladder = 0;
foreach ( $ladder as $i => $price ) {
	haai_seed_simple(
		array(
			'name'  => 'کفش چرم مردانه‌ی نردبان ' . ( $i + 1 ),
			'sku'   => 'HAAI-L-' . str_pad( (string) ( $i + 1 ), 3, '0', STR_PAD_LEFT ),
			'price' => $price,
			'stock' => 'instock',
			'qty'   => 5,
			'cats'  => array( $leather_men ),
		)
	);
	$made_ladder++;
}

/* Drafts: visible in the admin, never on the front end. */
$made_draft = 0;
foreach ( array( 'کفش پیش‌نویس آزمایشی A', 'کفش پیش‌نویس آزمایشی B' ) as $i => $name ) {
	haai_seed_simple(
		array(
			'name'   => $name,
			'sku'    => 'HAAI-D-' . ( $i + 1 ),
			'price'  => 990000,
			'status' => 'draft',
			'stock'  => 'instock',
			'cats'   => array( $men ),
		)
	);
	$made_draft++;
}

/* Variable products. */
$made_var = 0;
foreach ( array(
	array( 'name' => 'کفش چرم مردانه‌ی متغیر رسمی', 'sku' => 'HAAI-V-001', 'price' => 2100000, 'cats' => array( $leather_men ) ),
	array( 'name' => 'کفش ورزشی متغیر', 'sku' => 'HAAI-V-002', 'price' => 1650000, 'cats' => array( $summer ) ),
	array( 'name' => 'کیف چرم متغیر', 'sku' => 'HAAI-V-003', 'price' => 2350000, 'cats' => array( $leather_bag ) ),
) as $args ) {
	haai_seed_variable( $args );
	$made_var++;
}

$total = (int) wp_count_posts( 'product' )->publish + (int) wp_count_posts( 'product' )->draft;

echo "wiped previous run: {$wiped} products\n";
echo "simple created: {$made_simple}\n";
echo "ladder created: {$made_ladder}\n";
echo "drafts created: {$made_draft}\n";
echo "variable created: {$made_var} (with 4 variations each)\n";
echo "publish now: " . (int) wp_count_posts( 'product' )->publish . ", draft now: " . (int) wp_count_posts( 'product' )->draft . "\n";
echo "categories: کفش > کفش مردانه > کفش چرم مردانه / کفش تابستانی, کفش زنانه; کیف > کیف دوشی > کیف چرم دوشی; ست هدیه\n";
