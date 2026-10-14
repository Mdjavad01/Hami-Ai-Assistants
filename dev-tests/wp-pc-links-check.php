<?php
/**
 * Regression test — بند «ج»: related links must be related.
 *
 * The old code asked WordPress for `orderby=rand` posts/pages, so a product page
 * could be handed «المان تایم لاین», «صفحه 404» or «درباره ما» as a related
 * article. This runs on the real bench with the real index (Haa_Search) and the
 * real module block builder.
 *
 * Run: php C:\xampp\wp-cli\wp-cli.phar --path=C:\xampp\htdocs\haai-test eval-file dev-tests/wp-pc-links-check.php
 */
$GLOBALS['fail']      = 0;
$GLOBALS['pc_created'] = array();

/**
 * @param string $label Check name.
 * @param bool   $cond  Result.
 * @param string $extra Evidence.
 * @return void
 */
function lok( $label, $cond, $extra = '' ) {
	if ( ! $cond ) {
		$GLOBALS['fail']++;
	}
	echo ( $cond ? '  ok   ' : '  FAIL ' ) . str_pad( $label, 52 ) . $extra . "\n";
}

register_shutdown_function(
	function () {
		foreach ( $GLOBALS['pc_created'] as $id ) {
			if ( get_post( (int) $id ) ) {
				wp_delete_post( (int) $id, true );
			}
		}
		if ( isset( $GLOBALS['pc_settings'] ) ) {
			update_option( HAAI_OPTION, $GLOBALS['pc_settings'], 'no' );
			Haa_Settings::flush_cache();
		}
	}
);

/* ------------------------------------------------------------------
 * Setup: index what the site "has", then answer from the index
 * ---------------------------------------------------------------- */

// Sweep fixtures a crashed earlier run may have left.
foreach ( get_posts( array( 'post_type' => array( 'post', 'page', 'product' ), 'post_status' => 'any', 'posts_per_page' => 30, 'fields' => 'ids', 's' => 'لینک ج' ) ) as $stale ) {
	wp_delete_post( (int) $stale, true );
}

$GLOBALS['pc_settings'] = get_option( HAAI_OPTION );
$s = (array) Haa_Settings::get();
// The index scope must be on before Haa_Search caches its config.
$s['advanced']['kb_enabled']        = 1;
$s['advanced']['enable_rag']        = 1;
$s['advanced']['kb_post_types']     = array( 'post', 'page' );
$s['advanced']['kb_categories']     = array();
$s['content']['internal_links']     = 1;
$s['content']['link_count']         = 3;
$s['search']['min_score']           = 0.3;
update_option( HAAI_OPTION, $s, 'no' );
Haa_Settings::flush_cache();

/**
 * Publish something and put it in the index.
 *
 * @param string $type  post|page.
 * @param string $title Title.
 * @param string $body  Content.
 * @param string $slug  Optional explicit slug (BL-10 fixtures need one).
 * @return int
 */
function l_seed( $type, $title, $body, $slug = '' ) {
	$id = wp_insert_post(
		array(
			'post_type'    => $type,
			'post_title'   => $title,
			'post_name'    => '' !== $slug ? $slug : sanitize_title( $title ) . '-l' . substr( md5( $title ), 0, 5 ),
			'post_content' => $body,
			'post_status'  => 'publish',
		)
	);
	$id = (int) $id;
	if ( $id && ! is_wp_error( $id ) ) {
		$GLOBALS['pc_created'][] = $id;
		Haa_Search::index_post( $id );
	}
	return $id;
}

$BODY   = 'راهنمای انتخاب کفش چرم طبیعی برای پیاده‌روی؛ دوخت، کفه و سایز کفش چرم را بررسی می‌کنیم.';
$post_a = l_seed( 'post', 'راهنمای خرید کفش چرم — لینک ج', $BODY );
$page_b = l_seed( 'page', 'درباره ما — لینک ج', $BODY );
$page_c = l_seed( 'page', 'سبد خرید — لینک ج', $BODY );
$page_d = l_seed( 'page', 'تماس با ما — لینک ج', $BODY );
$page_e = l_seed( 'page', 'قوانین و مقررات — لینک ج', $BODY );
$page_f = l_seed( 'page', 'به‌زودی — لینک ج', $BODY );
// BL-10: real content whose slug merely *contains* a service word must stay linkable.
$page_g = l_seed( 'page', 'راهنمای کفش چرم و روغن آرگان', $BODY, 'about-argan-oil' );
$page_h = l_seed( 'page', 'ورودی و خروجی کفش چرم', $BODY, 'contact-form-doc' );
// BL-16: a theme-demo page carrying a phrase only in its BODY must not be offered
// as a related article. It is seeded with a phrase nothing else has, so in the
// `s=` fallback it is the only match — exactly how «صفحه اصلی - دمو اصلی» appeared
// on a real site with an empty index.
$page_i = l_seed( 'page', 'برگه‌ی نمونه‌ی قالب', 'این برگه فقط «زبرا دمو ۹۹۹» را در متن دارد، نه در تیتر.', 'dlf-template-landing' );
lok( 'fixtures indexed', $post_a > 0 && $page_b > 0 && $page_c > 0 && $page_d > 0 && $page_e > 0 && $page_f > 0 && $page_g > 0 && $page_h > 0 && $page_i > 0, "A=$post_a B=$page_b C=$page_c D=$page_d E=$page_e F=$page_f G=$page_g H=$page_h I=$page_i" );
$raw = Haa_Search::search( 'کفش چرم', 8, 20000 );
lok( 'index is reachable', count( $raw ) > 0 );
echo '  probe scores: ' . wp_json_encode( array_map(
	function ( $h ) {
		return $h['post_id'] . '=' . round( (float) $h['score'], 3 ) . '/' . get_post_type( $h['post_id'] );
	},
	$raw
), JSON_UNESCAPED_SLASHES ) . "\n";
echo '  probe tokens: ' . wp_json_encode( Haa_Search::tokenize( 'کفش چرم' ), JSON_UNESCAPED_UNICODE ) . "\n";

/* ------------------------------------------------------------------
 * A. internal_links()
 * ---------------------------------------------------------------- */
echo "\n=== A. links from the index, above the floor ===\n";
$ids = function ( $links ) {
	return array_map( 'intval', wp_list_pluck( (array) $links, 'id' ) );
};

// $page_b is the excluded post on purpose: the about page must never be the
// answer anyway, and the article has to survive.
$empty_hint = Haa_Content::internal_links( $page_b, '' );
lok( 'no keyword → no links at all', array() === $empty_hint, wp_json_encode( $empty_hint ) );

$hits = Haa_Content::internal_links( $page_b, 'کفش چرم' );
lok( 'a real keyword returns links', count( $hits ) >= 1, wp_json_encode( wp_list_pluck( (array) $hits, 'title' ), JSON_UNESCAPED_UNICODE ) );
lok( 'the matching article is offered', in_array( $post_a, $ids( $hits ), true ), wp_json_encode( $ids( $hits ) ) );
lok( 'the about page is never offered', ! in_array( $page_b, $ids( $hits ), true ) );
lok( 'the cart page is never offered', ! in_array( $page_c, $ids( $hits ), true ) );

// With room for six, every placeholder page still has to stay out. They all hold
// the same words, so only the blacklist can be doing the work here.
$wide_s   = (array) Haa_Settings::get();
$wide_s['content']['link_count'] = 6;
update_option( HAAI_OPTION, $wide_s, 'no' );
Haa_Settings::flush_cache();
$wide     = $ids( Haa_Content::internal_links( 0, 'کفش چرم' ) );
$blocked  = array_intersect( $wide, array( $page_b, $page_c, $page_d, $page_e, $page_f ) );
lok( 'about/cart/contact/terms/soon pages are all excluded', ! $blocked, 'offered=' . wp_json_encode( $wide ) . ' blocked=' . wp_json_encode( array_values( $blocked ) ) );
lok( 'the real article is offered with room to breathe', in_array( $post_a, $wide, true ), wp_json_encode( $wide ) );
lok( 'BL-10: a slug that merely contains about is not a service page', in_array( $page_g, $wide, true ), 'offered=' . wp_json_encode( $wide ) . ' g=' . $page_g );
lok( 'BL-10: «ورودی و خروجی» is content, not «ورود»', in_array( $page_h, $wide, true ), 'offered=' . wp_json_encode( $wide ) . ' h=' . $page_h );
lok( 'BL-16: a demo page matching only in its body is not offered', ! in_array( $page_i, $wide, true ), 'offered=' . wp_json_encode( $wide ) . ' i=' . $page_i );
// The decisive case is the `s=` fallback (what a site with an empty or low-scoring
// index uses — that is how sks behaved). Pushing the floor above every score sends
// internal_links() down that path.
$fb_s = (array) Haa_Settings::get();
$fb_s['search']['min_score'] = 0.99;
update_option( HAAI_OPTION, $fb_s, 'no' );
Haa_Settings::flush_cache();
$only_demo = $ids( Haa_Content::internal_links( 0, 'زبرا دمو ۹۹۹' ) );
lok( 'BL-16: in the fallback the body-only demo page is vetoed', ! in_array( $page_i, $only_demo, true ), 'offered=' . wp_json_encode( $only_demo ) );
lok( 'BL-16: the fallback still keeps a title match', in_array( $post_a, $ids( Haa_Content::internal_links( 0, 'کفش چرم' ) ), true ), 'offered=' . wp_json_encode( $ids( Haa_Content::internal_links( 0, 'کفش چرم' ) ) ) );
$fb_r = (array) Haa_Settings::get();
$fb_r['search']['min_score'] = 0.3;
update_option( HAAI_OPTION, $fb_r, 'no' );
Haa_Settings::flush_cache();
$back_s   = (array) Haa_Settings::get();
$back_s['content']['link_count'] = 3;
update_option( HAAI_OPTION, $back_s, 'no' );
Haa_Settings::flush_cache();
$bad = array_filter( $hits, function ( $l ) {
	$type = get_post_type_object( (string) get_post_type( (int) $l['id'] ) );
	return '' === (string) $l['url'] || 'publish' !== get_post_status( (int) $l['id'] ) || ! $type || empty( $type->public );
} );
lok( 'every link is public, published and real', ! $bad, wp_json_encode( array_keys( $bad ) ) );
lok( 'link_count is respected', count( $hits ) <= 3 );

$off = Haa_Content::internal_links( $page_b, 'تلسکوپ نجمی مریخ' );
lok( 'an unrelated keyword → no links', array() === $off, wp_json_encode( $ids( $off ) ) );

// The switch is read per call, so the block can be turned off without a cache trick.
$s2 = (array) Haa_Settings::get();
$s2['content']['internal_links'] = 0;
update_option( HAAI_OPTION, $s2, 'no' );
Haa_Settings::flush_cache();
lok( 'switch off → no links', array() === Haa_Content::internal_links( $page_b, 'کفش چرم' ) );
$s3 = (array) Haa_Settings::get();
$s3['content']['internal_links'] = 1;
update_option( HAAI_OPTION, $s3, 'no' );
Haa_Settings::flush_cache();
lok( 'switch back on → links again', count( Haa_Content::internal_links( $page_b, 'کفش چرم' ) ) >= 1 );

/* ------------------------------------------------------------------
 * B. the block the product page actually gets
 * ---------------------------------------------------------------- */
echo "\n=== B. the module block ===\n";
$cat_ids = array();
foreach ( array( 'کفش چرم', 'صندل', 'زونپلوس ج' ) as $cname ) {
	$t = term_exists( $cname, 'product_cat' );
	if ( ! $t ) {
		$t = wp_insert_term( $cname, 'product_cat' );
	}
	if ( ! is_wp_error( $t ) ) {
		$cat_ids[] = (int) ( is_array( $t ) ? $t['term_id'] : $t );
	}
}

/**
 * A product in a category, with a real description.
 *
 * @param string $name Name.
 * @param array  $cats Category ids.
 * @return int
 */
function l_product( $name, $cats ) {
	$p = new WC_Product_Simple();
	$p->set_name( $name . ' — لینک ج' );
	$p->set_status( 'publish' );
	$p->set_price( '900000' );
	$p->set_description( 'کفش چرم طبیعی با کفه لاستیکی برای پیاده‌روی؛ راهنمای انتخاب سایز کفش چرم در توضیحات آمده است.' );
	$p->set_short_description( 'چرم طبیعی' );
	$id = (int) $p->save();
	$GLOBALS['pc_created'][] = $id;
	if ( $cats ) {
		wp_set_object_terms( $id, $cats, 'product_cat' );
	}
	return $id;
}

$prod     = l_product( 'کفش چرم مردانه', $cat_ids );

// Two more products in a category the index knows nothing about: with no article
// to link, only same-category siblings are allowed to appear.
$zcat     = term_exists( 'زونپلوس ج', 'product_cat' );
$zcat_ids = array( is_array( $zcat ) ? (int) $zcat['term_id'] : 0 );
$zprod    = l_product( 'دستبند زونپلوس ج', $zcat_ids );
$zsib     = l_product( 'گردنبند زونپلوس ج', $zcat_ids );
$lonely   = l_product( 'پلاک زونپلوس ج', array() );

// The model is canned; nothing leaves the machine.
$GLOBALS['pc_mock'] = wp_json_encode(
	array(
		'html'            => '<p>متن نمونه‌ی مدل.</p>',
		'seo_title'       => 'عنوان نمونه',
		'seo_description' => 'متای نمونه',
		'slug'            => 'link-e-test',
		'excerpt'         => 'خ.',
		'faq'             => array(),
		'notes'           => array(),
	)
);
$GLOBALS['pc_sent'] = '';
add_filter(
	'pre_http_request',
	function ( $preempt, $args ) {
		$GLOBALS['pc_sent'] = (string) $args['body'];
		return array(
			'headers'  => array(),
			'body'     => wp_json_encode( array( 'choices' => array( array( 'message' => array( 'role' => 'assistant', 'content' => $GLOBALS['pc_mock'] ) ) ) ) ),
			'response' => array( 'code' => 200, 'message' => 'OK' ),
			'cookies'  => array(),
		);
	},
	10,
	2
);
$sc = (array) Haa_Settings::get();
$sc['provider']['base_url'] = 'https://8.8.8.8/v1';
$sc['provider']['api_key']  = 'capture-only';
$sc['provider']['model']    = 'capture-model';
$sc['provider']['retries']  = 0;
update_option( HAAI_OPTION, $sc, 'no' );
Haa_Settings::flush_cache();

// No keyword typed, and no article in the index for this category: only honest
// same-category siblings may show up — never a random page.
$b1 = Haa_Module_Product_Content::generate_brief( $zprod, array( 'tone' => 'friendly', 'length' => 'short', 'keywords' => '' ) );
$html1 = (string) ( $b1['html'] ?? '' );
lok( 'product brief built', ! empty( $b1['ok'] ), (string) ( $b1['error'] ?? '' ) );
lok( 'the block is labelled honestly', false === strpos( $html1, 'مقاله‌های مرتبط' ), mb_substr( $html1, 0, 90 ) );
lok( 'same-category siblings are linked', false !== mb_strpos( $html1, 'محصولات مرتبط', 0, 'UTF-8' ) && false !== mb_strpos( $html1, 'گردنبند زونپلوس ج', 0, 'UTF-8' ), preg_replace( '/\s+/u', ' ', mb_substr( $html1, strpos( $html1, 'محصولات' ) > 0 ? strpos( $html1, 'محصولات' ) - 5 : 0, 170 ) ) );
lok( 'no about/cart page slipped in', false === strpos( $html1, 'درباره ما —' ) && false === strpos( $html1, 'سبد خرید —' ) );
lok( 'the product never links itself', false === strpos( $html1, 'دستبند زونپلوس ج —' ) );
preg_match_all( '#<li><a href="[^"]*">([^<]*)</a></li>#u', $html1, $m );
lok( 'at most three siblings', count( $m[1] ) <= 3 && count( $m[1] ) >= 1, wp_json_encode( $m[1], JSON_UNESCAPED_UNICODE ) );

// An empty category and no textual match in the index: no block at all.
$b2 = Haa_Module_Product_Content::generate_brief( $lonely, array( 'tone' => 'friendly', 'length' => 'short', 'keywords' => '' ) );
$html2 = (string) ( $b2['html'] ?? '' );
lok( 'a product with no category gets no link block', false === strpos( $html2, '<h2>محصولات مرتبط' ) && false === strpos( $html2, '<h2>مقاله‌های مرتبط' ), preg_replace( '/\s+/u', ' ', mb_substr( $html2, 0, 90 ) ) );

// A product whose derived keyword does match an article: that article is offered.
$b3 = Haa_Module_Product_Content::generate_brief( $prod, array( 'tone' => 'friendly', 'length' => 'short', 'keywords' => 'کفش چرم' ) );
$html3 = (string) ( $b3['html'] ?? '' );
lok( 'with a keyword the article block is used', false !== mb_strpos( $html3, 'مقاله‌های مرتبط', 0, 'UTF-8' ) && false !== mb_strpos( $html3, 'راهنمای خرید کفش چرم', 0, 'UTF-8' ), preg_replace( '/\s+/u', ' ', mb_substr( $html3, mb_strpos( $html3, 'مقاله', 0, 'UTF-8' ) - 6, 170, 'UTF-8' ) ) );
lok( 'the article block still excludes the pages', false === strpos( $html3, 'درباره ما —' ) && false === strpos( $html3, 'سبد خرید —' ) );

/* ------------------------------------------------------------------
 * Result
 * ---------------------------------------------------------------- */
echo "\nFAILURES: " . $GLOBALS['fail'] . "\n";
