<?php
/**
 * Regression test — بند «الف» گام ۲: what really enters the product prompt.
 *
 * Real WooCommerce on the bench. The fixture reproduces the live site byte-for-byte
 * (`_product_attributes` keyed as `pa_%d8%ac…` with term-id options, an IRR price
 * whose symbol arrives as an HTML entity, and a lorem-ipsum long description), so
 * every check below fails on the pre-fix code and must pass after it.
 *
 * Run: php C:\xampp\wp-cli\wp-cli.phar --path=C:\xampp\htdocs\haai-test eval-file dev-tests/wp-pc-input-check.php
 *
 * The fixture product is created and deleted inside this run; nothing is left behind.
 */
$GLOBALS['fail'] = 0;
$GLOBALS['pc_fixtures'] = array();

/**
 * Delete fixtures and put the shop back, even if the run dies half-way.
 *
 * @return void
 */
function pc_shutdown() {
	foreach ( $GLOBALS['pc_fixtures'] as $id ) {
		if ( get_post( (int) $id ) ) {
			wp_delete_post( (int) $id, true );
		}
	}
	if ( isset( $GLOBALS['pc_currency_before'] ) ) {
		update_option( 'woocommerce_currency', $GLOBALS['pc_currency_before'] );
	}
	if ( isset( $GLOBALS['pc_settings_before'] ) ) {
		update_option( HAAI_OPTION, $GLOBALS['pc_settings_before'], 'no' );
		Haa_Settings::flush_cache();
	}
}
register_shutdown_function( 'pc_shutdown' );

// A crashed earlier run must not decide what this one sees.
foreach ( get_posts( array( 'post_type' => 'product', 'post_status' => 'any', 'posts_per_page' => 20, 'fields' => 'ids', 's' => 'تست الف' ) ) as $stale ) {
	wp_delete_post( (int) $stale, true );
}

/**
 * @param string $label Check name.
 * @param bool   $cond  Result.
 * @param string $extra Evidence.
 * @return void
 */
function ok( $label, $cond, $extra = '' ) {
	if ( ! $cond ) {
		$GLOBALS['fail']++;
	}
	echo ( $cond ? '  ok   ' : '  FAIL ' ) . str_pad( $label, 54 ) . $extra . "\n";
}

/* ------------------------------------------------------------------
 * Fixture
 * ---------------------------------------------------------------- */

$currency_before = get_option( 'woocommerce_currency' );
$GLOBALS['pc_currency_before'] = $currency_before;
update_option( 'woocommerce_currency', 'IRR' );

// Sections B and E are about price *formatting*, so the shop is opted into price
// facts here; section G proves the gate itself.
$GLOBALS['pc_settings_before'] = get_option( HAAI_OPTION );
$pre = (array) Haa_Settings::get();
$pre['content']['include_price'] = 1;
update_option( HAAI_OPTION, $pre, 'no' );
Haa_Settings::flush_cache();

if ( ! taxonomy_exists( 'pa_جنس' ) ) {
	$created = wc_create_attribute(
		array(
			'name'   => 'جنس',
			'type'   => 'select',
			'format' => 'term',
			'usage'  => 'global',
		)
	);
	ok( 'fixture: global attribute «جنس» created', ! is_wp_error( $created ) );
}
$term = term_exists( 'پلی استر', 'pa_جنس' );
if ( ! $term ) {
	$term = wp_insert_term( 'پلی استر', 'pa_جنس' );
}
$term_id = is_array( $term ) ? (int) $term['term_id'] : (int) $term;

/**
 * Build one fixture product.
 *
 * @param string $name    Product name.
 * @param string $desc    Long description.
 * @param string $short   Short description.
 * @param array  $attr_meta Raw _product_attributes array.
 * @param array  $terms   taxonomy => term ids.
 * @return int
 */
function pc_fixture( $name, $desc, $short, $attr_meta, $terms ) {
	$p = new WC_Product_Simple();
	$p->set_name( $name );
	$p->set_status( 'publish' );
	$p->set_price( '4152720' );
	$p->set_regular_price( '5848414' );
	$p->set_description( $desc );
	$p->set_short_description( $short );
	$id = (int) $p->save();
	foreach ( $terms as $tax => $ids ) {
		wp_set_object_terms( $id, $ids, $tax );
	}
	update_post_meta( $id, '_product_attributes', $attr_meta );
	wp_update_post(
		array(
			'ID'           => $id,
			'post_content' => $desc,
			'post_excerpt' => $short,
		)
	);
	wc_delete_product_transients( $id );
	$GLOBALS['pc_fixtures'][] = $id;
	return $id;
}

// The live shape: encoded meta key, decoded `name`, values only as term relations.
$encoded = 'pa_' . rawurlencode( 'جنس' );
$rich    = pc_fixture(
	'تست الف — پروب ورودی',
	'لورم ایپسوم متن ساختگی با تولید سادگی نامفهوم از صنعت چاپ و با استفاده از طراحان گرافیک است. چاپگرها و متون بلکه روزنامه و مجله در ستون و سطرآنچنان که لازم است.',
	'جنس : پلی استر',
	array(
		$encoded => array(
			'name'         => 'pa_جنس',
			'value'        => '',
			'position'     => 0,
			'is_visible'   => 1,
			'is_variation' => 0,
			'is_taxonomy'  => 1,
		),
	),
	array( 'pa_جنس' => array( $term_id ) )
);

// A product with honest, long data — the over-filtering control.
$plain_desc = 'کیف دوشی چرم طبیعی با آستر پارچه‌ای، بنده‌ی تنظیم‌شونده و جیب داخلی برای موبایل. دوخت دور تا دور کیف دو ردیف است و کفه‌ی کیف ضخیم‌تر از بدنه ساخته شده تا شکل خود را نگه دارد.';
$clean      = pc_fixture( 'تست الف — کیف چرم', $plain_desc, 'چرم طبیعی، آستر پارچه‌ای', array(), array() );

$facts    = Haa_Content::product_facts( $rich );
$notes    = null;
$facts_n  = Haa_Content::product_facts( $rich, $notes );
$clean_f  = Haa_Content::product_facts( $clean );
// Key AND value: a bare term id is only visible next to the key it belongs to.
$blob = implode( "\n", array_map(
	function ( $k, $v ) {
		return $k . ': ' . $v;
	},
	array_keys( $facts ),
	$facts
) );

/* ------------------------------------------------------------------
 * A. attribute keys and values
 * ---------------------------------------------------------------- */
echo "=== A. attributes arrive human, never as ids ===\n";
ok( 'facts readable (evidence)', true, '[' . implode( ' | ', array_map(
	function ( $k, $v ) {
		return $k . '=' . str_replace( "\n", ' ', $v );
	},
	array_keys( $facts ),
	$facts
) ) . ']' );
ok( 'no fact value is a bare term id', ! array_filter( $facts, function ( $v ) { return (bool) preg_match( '/^\d+$/u', trim( (string) $v ) ); } ), wp_json_encode( array_keys( array_filter( $facts, function ( $v ) { return (bool) preg_match( '/^\d+$/u', trim( (string) $v ) ); } ) ), JSON_UNESCAPED_UNICODE ) );
ok( 'no machine key leaks into the facts', ! array_filter( array_keys( $facts ), function ( $k ) { return false !== strpos( (string) $k, 'pa_' ) || false !== strpos( (string) $k, '%' ); } ), wp_json_encode( array_keys( $facts ), JSON_UNESCAPED_UNICODE ) );
$label = '';
$value = '';
foreach ( $facts as $k => $v ) {
	if ( false !== mb_strpos( $k, 'جنس', 0, 'UTF-8' ) ) {
		$label = $k;
		$value = $v;
	}
}
ok( 'a fact labelled «جنس» exists', '' !== $label && 0 !== mb_strpos( $label, 'pa_', 0, 'UTF-8' ), $label );
ok( 'its value is the term name, not the id', false !== mb_strpos( $value, 'پلی استر', 0, 'UTF-8' ), $value );

if ( ! method_exists( 'Haa_Content', 'attribute_label' ) ) {
	ok( 'Haa_Content::attribute_label() exists', false, 'method missing' );
	ok( 'Haa_Content::attribute_values() exists', false, 'method missing' );
} else {
	ok( 'encoded key decodes to a human label', 'جنس' === Haa_Content::attribute_label( $encoded ), var_export( Haa_Content::attribute_label( $encoded ), true ) );
	ok( 'latin key keeps WooCommerce label', '' !== Haa_Content::attribute_label( 'pa_color' ), var_export( Haa_Content::attribute_label( 'pa_color' ), true ) );
	ok( 'double prefix is stripped', 'جنس' === Haa_Content::attribute_label( 'attribute_pa_' . rawurlencode( 'جنس' ) ) );
	ok( 'garbage key yields no label', '' === Haa_Content::attribute_label( 'pa_%zz' ) && '' === Haa_Content::attribute_label( '%d8' ) );
}
if ( ! method_exists( 'Haa_Content', 'attribute_values' ) ) {
	ok( 'Haa_Content::attribute_values() exists', false, 'method missing' );
} else {
	ok( 'unresolvable term id is dropped, never printed', array() === Haa_Content::attribute_values( 'pa_%d9%86%d8%a7%d9%85%d9%88%d8%ac%d9%88%d8%af', array( 999999 ) ) );
	ok( 'plain text options survive', array( 'مشکی', 'قهوه‌ای' ) === Haa_Content::attribute_values( 'رنگ', array( 'مشکی', 'قهوه‌ای' ) ) );
}

/* ------------------------------------------------------------------
 * B. money
 * ---------------------------------------------------------------- */
echo "\n=== B. price text is real text ===\n";
$symbol = html_entity_decode( get_woocommerce_currency_symbol(), ENT_QUOTES, 'UTF-8' );
ok( 'no HTML entity anywhere in the facts', false === strpos( $blob, '&#' ), ( false === strpos( $blob, '&#' ) ? '' : mb_substr( $blob, strpos( $blob, '&#' ), 24 ) ) );
ok( 'price fact carries the decoded currency unit', '' !== $symbol && false !== mb_strpos( (string) ( $facts['قیمت'] ?? '' ), $symbol, 0, 'UTF-8' ), (string) ( $facts['قیمت'] ?? '' ) );
ok( 'price fact keeps the amount', (bool) preg_match( '/[\d٠-٩۰-۹][\d٬,.\x{066C}٠-٩۰-۹]{5,}/u', (string) ( $facts['قیمت'] ?? '' ) ), (string) ( $facts['قیمت'] ?? '' ) );
ok( 'regular price is formatted like the price', '' !== (string) ( $facts['قیمت معمول'] ?? '' ) && false !== mb_strpos( (string) ( $facts['قیمت معمول'] ?? '' ), $symbol, 0, 'UTF-8' ) && false === strpos( (string) ( $facts['قیمت معمول'] ?? '' ), '&#' ), (string) ( $facts['قیمت معمول'] ?? '' ) );

/* ------------------------------------------------------------------
 * C. demo prose
 * ---------------------------------------------------------------- */
echo "\n=== C. demo / filler description never reaches the model ===\n";
ok( 'lorem «توضیح کامل» is not sent', ! isset( $facts_n['توضیح کامل'] ) || false === mb_stripos( $facts_n['توضیح کامل'], 'لورم', 0, 'UTF-8' ) );
ok( 'a review note says why', is_array( $notes ) && (bool) array_filter( $notes, function ( $n ) { return false !== mb_strpos( (string) $n, 'توضیح کامل', 0, 'UTF-8' ); } ), wp_json_encode( $notes, JSON_UNESCAPED_UNICODE ) );
ok( 'product_facts() takes a notes out-param', is_array( $notes ), 'by-ref second arg' );
ok( 'honest long description is still sent', isset( $clean_f['توضیح کامل'] ) && mb_strlen( $clean_f['توضیح کامل'], 'UTF-8' ) > 60, (string) ( $clean_f['توضیح کامل'] ?? '' ) );
$clean_notes = null;
Haa_Content::product_facts( $clean, $clean_notes );
ok( 'no note is invented for a good product', is_array( $clean_notes ) && ! count( $clean_notes ), wp_json_encode( $clean_notes, JSON_UNESCAPED_UNICODE ) );
ok( 'short description of the rich product survives', isset( $facts_n['توضیح کوتاه'] ) && false !== mb_strpos( $facts_n['توضیح کوتاه'], 'پلی استر', 0, 'UTF-8' ), (string) ( $facts_n['توضیح کوتاه'] ?? '' ) );

/* ------------------------------------------------------------------
 * D. the prompt that actually leaves the site
 * ---------------------------------------------------------------- */
echo "\n=== D. the outbound prompt ===\n";
$settings_before = get_option( HAAI_OPTION );
$GLOBALS['pc_settings_before'] = $settings_before;
$GLOBALS['pc_body']   = '';
$GLOBALS['pc_calls']  = 0;
$GLOBALS['pc_replies'] = array();
add_filter(
	'pre_http_request',
	function ( $preempt, $args ) {
		$GLOBALS['pc_body']  = (string) $args['body'];
		$GLOBALS['pc_calls'] = (int) $GLOBALS['pc_calls'] + 1;
		$GLOBALS['pc_sent_last'] = json_decode( (string) $args['body'], true );

		// A scripted reply per call (section H), or the canned one for the rest.
		if ( $GLOBALS['pc_replies'] ) {
			$next = array_shift( $GLOBALS['pc_replies'] );
		} else {
			$next = array( 'html' => '<p>خروجی آزمایشی</p>' );
		}
		$content = isset( $next['raw'] ) ? (string) $next['raw'] : wp_json_encode(
			array(
				'html'            => isset( $next['html'] ) ? $next['html'] : '',
				'seo_title'       => isset( $next['seo_title'] ) ? $next['seo_title'] : 'ت',
				'seo_description' => 'د',
				'slug'            => 's',
				'excerpt'         => 'ه',
				'faq'             => isset( $next['faq'] ) ? $next['faq'] : array(),
				'notes'           => isset( $next['notes'] ) ? $next['notes'] : array(),
			),
			JSON_UNESCAPED_UNICODE
		);
		return array(
			'headers'  => array(),
			'body'     => isset( $next['body'] ) ? (string) $next['body'] : wp_json_encode(
				array(
					'choices' => array(
						array(
							'message'       => array( 'role' => 'assistant', 'content' => $content ),
							'finish_reason' => isset( $next['finish'] ) ? $next['finish'] : 'stop',
						),
					),
				),
				JSON_UNESCAPED_UNICODE
			),
			'response' => array( 'code' => isset( $next['code'] ) ? (int) $next['code'] : 200, 'message' => 'OK' ),
			'cookies'  => array(),
		);
	},
	10,
	2
);

$s            = (array) Haa_Settings::get();
$s['provider']['base_url'] = 'https://8.8.8.8/v1';
$s['provider']['api_key']  = 'capture-only';
$s['provider']['model']    = 'capture-model';
$s['provider']['retries']  = 0;
update_option( HAAI_OPTION, $s, 'no' );
Haa_Settings::flush_cache();

$brief = Haa_Module_Product_Content::generate_brief( $rich, array( 'tone' => 'friendly', 'length' => 'xlong', 'keywords' => '' ) );

update_option( HAAI_OPTION, $settings_before, 'no' );
Haa_Settings::flush_cache();

ok( 'chain: brief produced', ! empty( $brief['ok'] ), (string) ( $brief['error'] ?? '' ) );
$sent  = json_decode( (string) $GLOBALS['pc_body'], true );
$user  = (string) ( $sent['messages'][1]['content'] ?? '' );
$sys   = (string) ( $sent['messages'][0]['content'] ?? '' );
ok( 'prompt was captured', '' !== $user );
ok( 'prompt has no HTML entity', false === strpos( $user . $sys, '&#' ) );
ok( 'prompt has no lorem description', false === mb_strpos( $user, 'لورم', 0, 'UTF-8' ) );
ok( 'prompt has no machine attribute key', false === strpos( $user, 'pa_' ) );
ok( 'prompt keeps the resolved feature', false !== mb_strpos( $user, 'پلی استر', 0, 'UTF-8' ) );
$pc_notes = isset( $brief['notes'] ) ? (array) $brief['notes'] : array();
ok( 'preview carries the data note about the description', (bool) array_filter( $pc_notes, function ( $n ) { return false !== mb_strpos( (string) $n, 'توضیح کامل', 0, 'UTF-8' ); } ), wp_json_encode( $pc_notes, JSON_UNESCAPED_UNICODE ) );
ok( 'bench settings restored', get_option( HAAI_OPTION ) === $settings_before );

/* ------------------------------------------------------------------
 * E. currencies: rial/toman and a plugin-filtered symbol
 * ---------------------------------------------------------------- */
echo "\n=== E. currency units ===\n";

update_option( 'woocommerce_currency', 'IRT' );
$symbol_raw = get_woocommerce_currency_symbol();
$irt_price  = Haa_Content::price_text( '4152720' );
ok( 'IRT without a filter still has no entity in the facts', false === strpos( $irt_price, '&#' ), $symbol_raw );
ok( 'IRT without a filter reads «تومان» as a word', false !== mb_strpos( $irt_price, 'تومان', 0, 'UTF-8' ), $irt_price );
ok( 'IRT without a filter keeps a unit and the amount', (bool) preg_match( '/[4-9][\d\x{066C}٠-٩,.]{5,}/u', $irt_price ) && mb_strlen( $irt_price, 'UTF-8' ) > 9, $irt_price );

// Like the Persian WooCommerce plugins: they rewrite the symbol through this filter.
$toman_filter = function ( $symbol, $currency ) {
	return 'IRR' === $currency ? 'تومان' : $symbol;
};
add_filter( 'woocommerce_currency_symbol', $toman_filter, 20, 2 );
$toman_price = Haa_Content::price_text( '4152720' );
ok( 'filtered symbol (تومان) reaches the price text', false !== mb_strpos( $toman_price, 'تومان', 0, 'UTF-8' ), $toman_price );
ok( 'filtered price has no entity and no bidi mark', false === strpos( $toman_price, '&#' ) && false === strpos( $toman_price, "\xe2\x80\xae" ) && false === strpos( $toman_price, "\xe2\x80\x8e" ), $toman_price );
ok( 'filtered price keeps the amount', (bool) preg_match( '/[4-9][\d\x{066C}٠-٩,.]{5,}/u', $toman_price ), $toman_price );
ok( 'unit and amount stay separate words', (bool) preg_match( '/تومان\s+[\d\x{066C}٠-٩,.]{5,}/u', $toman_price ), $toman_price );

// Persian numerals: WooCommerce wraps the amount in <bdi> with a real separator.
// Nothing in the cleanup may push a space inside the number or drop the separator.
$fa_html  = '<span class="woocommerce-Price-amount amount"><bdi>۴٬۱۵۲٬۷۲۰٫۵۰&nbsp;<span class="woocommerce-Price-currencySymbol">تومان</span></bdi></span>';
ok( 'persian digits survive plain_text', '۴٬۱۵۲٬۷۲۰٫۵۰ تومان' === Haa_Content::plain_text( $fa_html ), Haa_Content::plain_text( $fa_html ) );
$fa_locale = function ( $locale ) {
	return 'fa_IR';
};
add_filter( 'locale', $fa_locale );
$fa_price = Haa_Content::price_text( '4152720' );
remove_filter( 'locale', $fa_locale );
ok( 'a price never gets a space inside its own digits', ! preg_match( '/[\d۰-۹][\s\x{00A0}]+[\d۰-۹]/u', $fa_price ), $fa_price );
ok( 'a price keeps its thousands separator', (bool) preg_match( '/[\d۰-۹][,.\x{060C}\x{066C}،][\d۰-۹]/u', $fa_price ), $fa_price );
$norm_fa = str_replace( array( '۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹' ), array( '0', '1', '2', '3', '4', '5', '6', '7', '8', '9' ), $fa_price );
ok( 'no digit of the amount is lost', false !== strpos( preg_replace( '/[^\d]/u', '', $norm_fa ), '4152720' ), $fa_price );
$facts_toman = Haa_Content::product_facts( $rich );
ok( 'the fact list uses the filtered unit too', false !== mb_strpos( (string) ( $facts_toman['قیمت'] ?? '' ), 'تومان', 0, 'UTF-8' ), (string) ( $facts_toman['قیمت'] ?? '' ) );
remove_filter( 'woocommerce_currency_symbol', $toman_filter, 20 );

update_option( 'woocommerce_currency', 'AED' );
ok( 'an other currency keeps its own unit', '' !== Haa_Content::price_text( '100' ) && false === strpos( Haa_Content::price_text( '100' ), '&#' ), Haa_Content::price_text( '100' ) );
update_option( 'woocommerce_currency', 'IRR' );

/* ------------------------------------------------------------------
 * F. the other attribute shapes
 * ---------------------------------------------------------------- */
echo "\n=== F. custom, multi-valued and variable attributes ===\n";

// «pa_جنس» is registered (created above); a brand-new global attribute is not
// visible to taxonomy_exists() inside the same request, so the multi-valued case
// reuses it with a second term.
$color_ids = array();
foreach ( array( 'پلی استر', 'کرکی' ) as $cname ) {
	$t = term_exists( $cname, 'pa_جنس' );
	if ( ! $t ) {
		$t = wp_insert_term( $cname, 'pa_جنس' );
	}
	ok( "fixture: term «{$cname}» in pa_جنس", ! is_wp_error( $t ) && is_array( $t ) || is_numeric( $t ), is_wp_error( $t ) ? $t->get_error_message() : '' );
	$color_ids[] = is_array( $t ) ? (int) $t['term_id'] : (int) $t;
}

$multi = new WC_Product_Variable();
$multi->set_name( 'تست الف — کفش متغیر' );
$multi->set_status( 'publish' );
$multi->set_description( 'کفش روزمره‌ی چرم با آستر پارچه‌ای و کفه‌ی لاستیکی. زیره دوزی‌شده است و کفش جیب داخلی برای بند اضافه دارد. سایزهای ۴۰ تا ۴۳ موجود است.' );

// 1) multi-valued global (taxonomy) attribute, keyed the way Woo stores it.
$a_color = new WC_Product_Attribute();
$a_color->set_name( 'pa_جنس' );
$a_color->set_options( $color_ids );
$a_color->set_visible( true );
$a_color->set_variation( false );

// 2) a local (non-taxonomy) attribute with a Persian name.
$a_local = new WC_Product_Attribute();
$a_local->set_name( 'جنس آستر' );
$a_local->set_options( array( 'کرکی', 'فومی' ) );
$a_local->set_visible( true );
$a_local->set_variation( false );

// 3) a local multi-valued attribute: the shape the admin sees in the box.
$a_colors = new WC_Product_Attribute();
$a_colors->set_name( 'رنگ' );
$a_colors->set_options( array( 'قرمز', 'آبی' ) );
$a_colors->set_visible( true );
$a_colors->set_variation( false );

$multi->set_attributes( array( $a_color, $a_local, $a_colors ) );
$mid = (int) $multi->save();
$GLOBALS['pc_fixtures'][] = $mid;
wp_set_object_terms( $mid, $color_ids, 'pa_جنس' );

$enc = 'attribute_' . rawurlencode( 'pa_جنس' );
$meta = get_post_meta( $mid, '_product_attributes', true );
if ( is_array( $meta ) ) {
	// Re-key one attribute the way the live shop stored it: `attribute_` + percent-encoded.
	$rekeyed = array();
	foreach ( $meta as $k => $v ) {
		$rekeyed[ ( 'pa_جنس' === $k ? $enc : $k ) ] = $v;
	}
	update_post_meta( $mid, '_product_attributes', $rekeyed );
}
wc_delete_product_transients( $mid );

$mf    = null;
$facts_m = Haa_Content::product_facts( $mid, $mf );
ok( 'variable fixture attributes dumped', true, '[' . implode( ' | ', array_map(
	function ( $k, $v ) {
		return $k . '=' . $v;
	},
	array_keys( $facts_m ),
	$facts_m
) ) . ']' );
ok( 'encoded `attribute_` key resolves to a label', ! array_filter( array_keys( $facts_m ), function ( $k ) {
	return false !== strpos( (string) $k, 'attribute_' ) || false !== strpos( (string) $k, '%' ) || false !== strpos( (string) $k, 'pa_' );
} ) );
// The key the live shop stores, handed to the resolver directly: `attribute_` +
// percent-encoded Persian taxonomy. Deterministic proof, independent of how this
// WooCommerce version chooses to normalise the meta when reading it back.
$enc_key = 'attribute_pa_' . rawurlencode( 'جنس' );
ok( 'attribute_ + percent-encoded key gets a human label', 'جنس' === Haa_Content::attribute_label( $enc_key ), (string) Haa_Content::attribute_label( $enc_key ) );
ok( 'attribute_ + percent-encoded key resolves term names', array( 'پلی استر' ) === Haa_Content::attribute_values( $enc_key, array( $term_id ) ), wp_json_encode( Haa_Content::attribute_values( $enc_key, array( $term_id ) ), JSON_UNESCAPED_UNICODE ) );
echo '  probe raw keys of the variable fixture: ' . wp_json_encode( array_keys( wc_get_product( $mid )->get_attributes() ), JSON_UNESCAPED_UNICODE ) . " (meta key was {$enc})\n";
$multi_fact = isset( $facts_m['جنس'] ) ? (string) $facts_m['جنس'] : '';
$local_fact = isset( $facts_m['جنس آستر'] ) ? (string) $facts_m['جنس آستر'] : '';
ok( 'multi-valued attribute lists both term names', false !== mb_strpos( $multi_fact, 'پلی استر', 0, 'UTF-8' ) && false !== mb_strpos( $multi_fact, 'کرکی', 0, 'UTF-8' ), $multi_fact );
ok( 'multi-valued attribute lists no id', ! preg_match( '/\d{2,}/u', $multi_fact ), $multi_fact );
ok( 'local (non-taxonomy) attribute survives with its name', false !== mb_strpos( $local_fact, 'کرکی', 0, 'UTF-8' ) && false !== mb_strpos( $local_fact, 'فومی', 0, 'UTF-8' ), $local_fact );
ok( 'local attribute has its own decoded label', '' !== $local_fact );
ok( 'a local multi-valued attribute reads as «قرمز، آبی»', isset( $facts_m['رنگ'] ) && 'قرمز، آبی' === str_replace( '، ', '، ', (string) $facts_m['رنگ'] ), (string) ( $facts_m['رنگ'] ?? '(missing)' ) );
ok( 'no note for a well-described variable product', is_array( $mf ) && ! count( $mf ), wp_json_encode( $mf, JSON_UNESCAPED_UNICODE ) );

/* ------------------------------------------------------------------
 * G. بند «د»: price, sale price and stock are opt-in
 * ---------------------------------------------------------------- */
echo "\n=== G. price/stock stay out unless asked ===\n";
// Keep the mock transport alive for this section too: without a key the call
// never reaches the filter and every prompt check would pass vacuously.
$g_setup = function ( $include_price ) {
	$s = (array) Haa_Settings::get();
	$s['provider']['base_url']      = 'https://8.8.8.8/v1';
	$s['provider']['api_key']       = 'capture-only';
	$s['provider']['model']         = 'capture-model';
	$s['provider']['retries']       = 0;
	$s['content']['include_price']  = $include_price;
	update_option( HAAI_OPTION, $s, 'no' );
	Haa_Settings::flush_cache();
};
$g_setup( 0 );

$facts_off  = Haa_Content::product_facts( $rich );
ok( 'no price or stock in the facts by default', ! isset( $facts_off['قیمت'] ) && ! isset( $facts_off['قیمت معمول'] ) && ! isset( $facts_off['موجودی'] ), wp_json_encode( array_keys( $facts_off ), JSON_UNESCAPED_UNICODE ) );
ok( 'the readable data still goes through', isset( $facts_off['نام'] ) && isset( $facts_off['توضیح کوتاه'] ), wp_json_encode( array_keys( $facts_off ), JSON_UNESCAPED_UNICODE ) );

$GLOBALS['pc_body'] = '';
Haa_Content::product_brief( 'تست الف — پروب ورودی', $facts_off, array( 'length' => 'short' ), array( 'module' => 'product-content' ) );
$g_sent = json_decode( (string) $GLOBALS['pc_body'], true );
$g_sys  = (string) ( $g_sent['messages'][0]['content'] ?? '' );
$g_user = (string) ( $g_sent['messages'][1]['content'] ?? '' );
ok( 'the off prompt was really captured', '' !== $g_sys );
ok( 'the prompt forbids price, stock and sale wording', false !== mb_strpos( $g_sys, 'کاهش قابل توجه', 0, 'UTF-8' ) );
ok( 'no number reaches the prompt', false === strpos( $g_user, '4152720' ) && false === strpos( $g_user, '5848414' ) && false === mb_strpos( $g_user, 'موجودی', 0, 'UTF-8' ) );

$g_setup( 1 );
$GLOBALS['pc_body'] = '';
$facts_on = Haa_Content::product_facts( $rich );
Haa_Content::product_brief( 'تست الف — پروب ورودی', $facts_on, array( 'length' => 'short' ), array( 'module' => 'product-content' ) );
$g_sent2 = json_decode( (string) $GLOBALS['pc_body'], true );
$g_sys2  = (string) ( $g_sent2['messages'][0]['content'] ?? '' );
ok( 'opting in brings the price back', isset( $facts_on['قیمت'] ) && isset( $facts_on['موجودی'] ), wp_json_encode( array_keys( $facts_on ), JSON_UNESCAPED_UNICODE ) );
ok( 'the on prompt was really captured', '' !== $g_sys2 );
ok( 'opting in drops the prohibition', false === mb_strpos( $g_sys2, 'کاهش قابل توجه', 0, 'UTF-8' ) );
ok( 'opting in puts the price in the prompt', false !== mb_strpos( (string) ( $g_sent2['messages'][1]['content'] ?? '' ), 'قیمت', 0, 'UTF-8' ) );

$defs = (array) Haa_Settings::defaults();
ok( 'the key has a default and it is off', array_key_exists( 'include_price', (array) $defs['content'] ) && 0 === (int) $defs['content']['include_price'] );
ok( 'the key has a sanitize rule', isset( Haa_Settings::sanitize_map()['content.include_price'] ) );
$dbg  = Haa_Settings::diagnose();
ok( 'diagnose is clean with the new key', is_array( $dbg ) && 0 === count( $dbg ), wp_json_encode( $dbg, JSON_UNESCAPED_UNICODE ) );

/* ------------------------------------------------------------------
 * H. بند «هـ»: words in the prompt, truncation seen, one repair only
 * ---------------------------------------------------------------- */
echo "\n=== H. length, truncation and the one repair call ===\n";

// Guards so a run against the pre-fix code reports failures instead of dying on
// the first missing method (the point of a before/after number is that it prints).
$has_len  = method_exists( 'Haa_Content', 'count_words' ) && method_exists( 'Haa_Content', 'target_words' );
$has_list = method_exists( 'Haa_Module_Product_Content', 'length_choices' );

ok( 'count_words counts text, not tags', $has_len && 3 === Haa_Content::count_words( '<p>یک دو</p><ul><li>سه</li></ul>' ), $has_len ? (string) Haa_Content::count_words( '<p>یک دو</p><ul><li>سه</li></ul>' ) : 'count_words missing' );

$cfg    = array( 'base_url' => 'https://x.test/v1', 'api_key' => 'k', 'model' => 'm', 'temperature' => 0.7, 'max_tokens' => 2000, 'retries' => 0, 'adapter' => 'openai' );
$openai = new Haa_Adapter_OpenAI( $cfg );
$gemini = new Haa_Adapter_Gemini( $cfg );
$claude = new Haa_Adapter_Claude( $cfg );
$has_tr = method_exists( $openai, 'truncated' );
ok( 'openai: finish_reason=length is truncation', $has_tr && true === (bool) $openai->truncated( array( 'choices' => array( array( 'finish_reason' => 'length' ) ) ) ), $has_tr ? '' : 'truncated() missing' );
ok( 'openai: finish_reason=stop is whole', $has_tr && false === (bool) $openai->truncated( array( 'choices' => array( array( 'finish_reason' => 'stop' ) ) ) ) );
ok( 'gemini: finishReason=MAX_TOKENS is truncation', $has_tr && true === (bool) $gemini->truncated( array( 'candidates' => array( array( 'finishReason' => 'MAX_TOKENS' ) ) ) ) );
ok( 'gemini: STOP is whole', $has_tr && false === (bool) $gemini->truncated( array( 'candidates' => array( array( 'finishReason' => 'STOP' ) ) ) ) );
ok( 'claude: stop_reason=max_tokens is truncation', $has_tr && true === (bool) $claude->truncated( array( 'stop_reason' => 'max_tokens' ) ) );
ok( 'claude: end_turn is whole', $has_tr && false === (bool) $claude->truncated( array( 'stop_reason' => 'end_turn' ) ) );

$style = Haa_Content::style_suffix( array( 'length' => 'long', 'words' => 1200, 'topic' => 'کیف' ) );
ok( 'the prompt names the unit and the ±۱۵٪ band', false !== mb_strpos( $style, 'کلمه', 0, 'UTF-8' ) && false !== strpos( $style, '1020' ) && false !== strpos( $style, '1380' ), preg_replace( '/\s+/u', ' ', $style ) );

ok( 'the product list has no 2 200-word option', $has_list && ! isset( Haa_Module_Product_Content::length_choices()['xlong'] ), $has_list ? wp_json_encode( array_keys( Haa_Module_Product_Content::length_choices() ) ) : 'length_choices missing' );
ok( 'its longest option is 1200 words', $has_list && 1200 === Haa_Module_Product_Content::length_words( 'long' ) );
ok( 'an unknown slug falls back to the middle', $has_list && 800 === Haa_Module_Product_Content::length_words( 'nope' ) );
ok( 'the label says ۱۲۰۰', $has_list && false !== mb_strpos( (string) Haa_Module_Product_Content::length_choices()['long'], '۱۲۰۰', 0, 'UTF-8' ), $has_list ? (string) Haa_Module_Product_Content::length_choices()['long'] : '' );

$many  = array(
	'نام'    => 'کیف دوشی چرم',
	'دسته'   => 'کیف',
	'جنس'    => 'چرم طبیعی',
	'آستر'   => 'پارچه',
	'بند'    => 'تنظیم‌شونده',
	'دوخت'   => 'دو ردیف',
	'کفه'    => 'ضخیم',
	'جیب'    => 'داخلی برای موبایل',
	'ابعاد'  => '۳۰ در ۲۰',
);
$few = array( 'نام' => 'کیف دوشی چرم', 'دسته' => 'کیف', 'جنس' => 'چرم طبیعی', 'آستر' => 'پارچه' );

// A cut-off reply: one continuation, said out loud, nothing invented.
$GLOBALS['pc_replies'] = array(
	array( 'html' => '<p>بند نخست کیف دوشی چرم است.</p>', 'finish' => 'length' ),
	array( 'raw'  => '<p>بند دوم هم با بند تنظیم‌شونده و دوخت دو ردیف ادامه می‌یابد.</p>', 'finish' => 'stop' ),
);
$GLOBALS['pc_calls']   = 0;
$GLOBALS['pc_bodies']  = array();
$cut = Haa_Content::product_brief( 'کیف دوشی چرم', $many, array( 'length' => 'short', 'words' => 8 ), array( 'module' => 'product-content' ) );
ok( 'a cut-off reply is marked truncated', ! empty( $cut['truncated'] ), wp_json_encode( array( 'truncated' => isset( $cut['truncated'] ) ? $cut['truncated'] : null, 'words' => isset( $cut['words'] ) ? $cut['words'] : null ) ) );
ok( 'exactly one continuation was asked for', 2 === (int) $GLOBALS['pc_calls'], (string) $GLOBALS['pc_calls'] );
ok( 'the continuation was appended', false !== mb_strpos( (string) $cut['html'], 'بند دوم', 0, 'UTF-8' ), (string) $cut['html'] );
ok( 'the cut is reported in the notes', (bool) array_filter( (array) $cut['notes'], function ( $n ) { return false !== mb_strpos( (string) $n, 'سقف توکن', 0, 'UTF-8' ); } ), wp_json_encode( (array) $cut['notes'], JSON_UNESCAPED_UNICODE ) );

// Short but whole, with data to expand: exactly one expansion, and the instruction says so.
$GLOBALS['pc_replies'] = array(
	array( 'html' => '<p>بند نخست کیف دوشی چرم است.</p>', 'finish' => 'stop' ),
	array( 'raw'  => '<p>کفه‌ی ضخیم و جیب داخلی موبایل را نگه می‌دارند.</p>', 'finish' => 'stop' ),
);
$GLOBALS['pc_calls']   = 0;
$GLOBALS['pc_body']    = '';
$thin = Haa_Content::product_brief( 'کیف دوشی چرم', $many, array( 'length' => 'long', 'words' => 400 ), array( 'module' => 'product-content' ) );
$expand_prompt = (string) ( $GLOBALS['pc_sent_last']['messages'][0]['content'] ?? '' );
ok( 'one expansion call was made', 2 === (int) $GLOBALS['pc_calls'], (string) $GLOBALS['pc_calls'] );
ok( 'the expansion prompt forbids new claims', false !== mb_strpos( $expand_prompt, 'همین اطلاعات', 0, 'UTF-8' ) || false !== mb_strpos( $expand_prompt, 'نساز', 0, 'UTF-8' ), preg_replace( '/\s+/u', ' ', mb_substr( $expand_prompt, 0, 120 ) ) );
ok( 'the expanded text kept both parts', false !== mb_strpos( (string) $thin['html'], 'کفه‌ی ضخیم', 0, 'UTF-8' ) && false !== mb_strpos( (string) $thin['html'], 'بند نخست', 0, 'UTF-8' ), (string) $thin['html'] );
ok( 'words and target are reported for the UI', isset( $thin['words'] ) && (int) $thin['words'] > 0 && 400 === (int) ( $thin['target_words'] ?? 0 ), wp_json_encode( array( 'words' => $thin['words'] ?? null, 'target' => $thin['target_words'] ?? null ) ) );

// Same short reply, thin data: no extra call, and the reason is written down.
$GLOBALS['pc_replies'] = array( array( 'html' => '<p>بند نخست کیف دوشی چرم است.</p>', 'finish' => 'stop' ) );
$GLOBALS['pc_calls']   = 0;
$poor = Haa_Content::product_brief( 'کیف دوشی چرم', $few, array( 'length' => 'long', 'words' => 400 ), array( 'module' => 'product-content' ) );
ok( 'no repair call when the data is thin', 1 === (int) $GLOBALS['pc_calls'], (string) $GLOBALS['pc_calls'] );
ok( 'the reason is in the notes', (bool) array_filter( (array) $poor['notes'], function ( $n ) { return false !== mb_strpos( (string) $n, 'داده‌ی محصول کم است', 0, 'UTF-8' ); } ), wp_json_encode( (array) $poor['notes'], JSON_UNESCAPED_UNICODE ) );

$GLOBALS['pc_replies'] = array();

/* ------------------------------------------------------------------
 * I. the four follow-up checks: time budget, batch estimate,
 *    the xlong default and a truncation flag that never goes stale
 * ---------------------------------------------------------------- */
echo "\n=== I. time budget, estimate, xlong default, flag reset ===\n";

// 1) A spent budget: keep what we have, say so, and do not pay for a second call.
$GLOBALS['pc_replies'] = array(
	array( 'html' => '<p>بند نخست کیف دوشی چرم است.</p>', 'finish' => 'length' ),
	array( 'raw'  => '<p>این ادامه هرگز درخواست نباید شود.</p>' ),
);
$GLOBALS['pc_calls']   = 0;
$late = Haa_Content::product_brief( 'کیف دوشی چرم', $many, array( 'length' => 'short', 'words' => 8, 'deadline' => 0.000001 ), array( 'module' => 'product-content' ) );
ok( 'a spent budget costs no extra call', 1 === (int) $GLOBALS['pc_calls'], (string) $GLOBALS['pc_calls'] );
ok( 'the budget note is shown', (bool) array_filter( (array) $late['notes'], function ( $n ) { return false !== mb_strpos( (string) $n, 'به‌خاطر محدودیت زمان', 0, 'UTF-8' ); } ), wp_json_encode( (array) $late['notes'], JSON_UNESCAPED_UNICODE ) );
ok( 'the text it did get is kept', false !== mb_strpos( (string) $late['html'], 'بند نخست', 0, 'UTF-8' ), (string) $late['html'] );
$GLOBALS['pc_replies'] = array(
	array( 'html' => '<p>بند نخست کیف دوشی چرم است.</p>', 'finish' => 'length' ),
	array( 'raw'  => '<p>بند دوم با همین داده‌ها ادامه می‌یابد.</p>' ),
);
$GLOBALS['pc_calls']   = 0;
Haa_Content::product_brief( 'کیف دوشی چرم', $many, array( 'length' => 'short', 'words' => 8 ), array( 'module' => 'product-content' ) );
ok( 'the queue path has no budget and still repairs', 2 === (int) $GLOBALS['pc_calls'], (string) $GLOBALS['pc_calls'] );

// A failed repair call must not fail the brief (the queue has no daily token cap of
// its own; what it must never lose is the text it already has).
$GLOBALS['pc_replies'] = array(
	array( 'html' => '<p>بند نخست کیف دوشی چرم است.</p>', 'finish' => 'length' ),
	array( 'code' => 500, 'body' => '{"error":{"message":"down"}}' ),
);
$GLOBALS['pc_calls']   = 0;
$broken = Haa_Content::product_brief( 'کیف دوشی چرم', $many, array( 'length' => 'short', 'words' => 8 ), array( 'module' => 'product-content' ) );
ok( 'a failed repair does not fail the brief', ! empty( $broken['ok'] ) && '' !== (string) $broken['html'], (string) ( $broken['error'] ?? '' ) );
ok( 'and it is reported honestly', (bool) array_filter( (array) $broken['notes'], function ( $n ) { return false !== mb_strpos( (string) $n, 'نصفه', 0, 'UTF-8' ) || false !== mb_strpos( (string) $n, 'سقف توکن', 0, 'UTF-8' ); } ), wp_json_encode( (array) $broken['notes'], JSON_UNESCAPED_UNICODE ) );

// 2) The batch estimate counts the worst case: three text calls per product.
$four = Haa_Module_Product_Content::estimate_requests( 4 );
ok( 'estimate is three calls per product', 12 === $four, (string) $four );

// 3) A saved «خیلی بلند» (the article default) never reaches the product form.
$has_slug = method_exists( 'Haa_Module_Product_Content', 'length_slug' );
$s9 = (array) Haa_Settings::get();
$s9['content']['default_length'] = 'xlong';
update_option( HAAI_OPTION, $s9, 'no' );
Haa_Settings::flush_cache();
ok( 'the product form falls one notch down', $has_slug && 'long' === Haa_Module_Product_Content::default_length(), $has_slug ? '' : 'length_slug missing' );
ok( 'and asks for 1200 words', $has_slug && 1200 === Haa_Module_Product_Content::length_words( 'xlong' ) && 1200 === Haa_Module_Product_Content::length_words( 'long' ) );
ok( 'a junk slug still falls back to medium', $has_slug && 'medium' === Haa_Module_Product_Content::length_slug( 'nope' ) );
ok( 'the article default is untouched', 2200 === (int) Haa_Util::length_words( 'xlong' ) );
$GLOBALS['pc_replies'] = array( array( 'html' => '<p>بند نخست.</p>' ) );
$GLOBALS['pc_body']    = '';
Haa_Module_Product_Content::generate_brief( $rich, array( 'tone' => 'friendly', 'length' => 'xlong', 'keywords' => '' ) );
$b9 = json_decode( (string) $GLOBALS['pc_body'], true );
ok( 'the brief built from xlong says 1200', false !== mb_strpos( (string) ( $b9['messages'][0]['content'] ?? '' ), 'حدود 1200 کلمه', 0, 'UTF-8' ), preg_replace( '/\s+/u', ' ', (string) ( $b9['messages'][0]['content'] ?? '' ) ) );
ok( 'the saved value itself is left alone', 'xlong' === (string) Haa_Settings::get( 'content.default_length' ) );

// 4) The truncation flag answers for the call that just happened, nothing else.
$GLOBALS['pc_replies'] = array( array( 'html' => '<p>بند نخست.</p>', 'finish' => 'length' ) );
// The spent budget keeps this to one call, so the flag below is that call's own.
Haa_Content::product_brief( 'کیف', $many, array( 'length' => 'short', 'words' => 8, 'deadline' => 0.000001 ), array( 'module' => 'product-content' ) );
ok( 'a cut reply sets the flag', true === Haa_API::last_truncated() );
$GLOBALS['pc_replies'] = array( array( 'body' => '{"error":{"message":"down"}}', 'code' => 500 ) );
$failed = Haa_Content::product_brief( 'کیف', $many, array( 'length' => 'short', 'words' => 8 ), array( 'module' => 'product-content' ) );
ok( 'a failed call leaves no stale flag', false === Haa_API::last_truncated() && empty( $failed['ok'] ), (string) ( $failed['error'] ?? '' ) );
$GLOBALS['pc_replies'] = array( array( 'body' => '{}' ) );
Haa_Content::product_brief( 'کیف', $many, array( 'length' => 'short', 'words' => 8 ), array( 'module' => 'product-content' ) );
ok( 'an empty or cached-shaped body leaves it false too', false === Haa_API::last_truncated() );
$GLOBALS['pc_replies'] = array();

/* ------------------------------------------------------------------
 * J. بندهای «و/ز/ح/ط»: the FAQ filter, the tone samples, the derived keyword
 * ---------------------------------------------------------------- */
echo "\n=== J. faq filter, tone examples, focus keyword ===\n";

$has_j = method_exists( 'Haa_Content', 'faq_unpublishable' ) && method_exists( 'Haa_Content', 'tone_examples' ) && method_exists( 'Haa_Module_Product_Content', 'derived_keyword' );
if ( ! $has_j ) {
	// Prove what the code before بند «و/ز/ح/ط» did, instead of dying on the first
	// new method: the fixtures and settings go back through the shutdown handler.
	ok( 'the faq/tone/keyword layer exists', false, 'methods missing' );
	$GLOBALS['pc_replies'] = array( array( 'html' => '<p>بند نمونه.</p>', 'faq' => array( array( 'q' => 'قیمت این محصول چند تومان است؟', 'a' => 'احتمالاً ارزان است.' ) ) ) );
	$old = Haa_Content::product_brief( 'کیف', $many, array( 'length' => 'short', 'words' => 8 ), array( 'module' => 'product-content' ) );
	ok( 'LEGACY: a price question was published', 1 === count( (array) $old['faq'] ), wp_json_encode( (array) $old['faq'], JSON_UNESCAPED_UNICODE ) );
	ok( 'LEGACY: a hedged answer was published', isset( $old['faq'][0]['a'] ) && false !== mb_strpos( (string) $old['faq'][0]['a'], 'احتمالاً', 0, 'UTF-8' ) );
	$old_style = Haa_Content::style_suffix( array( 'tone' => 'friendly', 'length' => 'medium', 'topic' => 'کیف' ) );
	ok( 'LEGACY: no sample sentences for the tone', false === mb_strpos( $old_style, '«', 0, 'UTF-8' ), preg_replace( '/\s+/u', ' ', $old_style ) );
	ok( 'LEGACY: no Persian writing rules', false === mb_strpos( $old_style, 'نیم‌فاصله', 0, 'UTF-8' ) );
	$GLOBALS['pc_replies'] = array();
	echo "\nFAILURES: " . $GLOBALS['fail'] . "\n";
	return;
}

// The filter itself, on plain strings.
ok( 'filter drops a price question', Haa_Content::faq_unpublishable( 'قیمت این کیف چقدر است؟', 'چرم طبیعی است.' ) );
ok( 'filter drops a stock question', Haa_Content::faq_unpublishable( 'آیا این محصول موجود است؟', 'بله.' ) );
ok( 'filter drops a hedged answer', Haa_Content::faq_unpublishable( 'جنس آن چیست؟', 'احتمالاً چرم طبیعی است.' ) );
ok( 'filter keeps a real question', ! Haa_Content::faq_unpublishable( 'آستر کیف از چیست؟', 'آستر آن پارچه‌ای است.' ) );

// …and it runs on generated output: only the honest row survives.
$GLOBALS['pc_replies'] = array(
	array(
		'html' => '<p>کیف از چرم طبیعی با آستر پارچه‌ای دوخته شده است.</p>',
		'faq'  => array(
			array( 'q' => 'قیمت این محصول چقدر است؟', 'a' => 'به فروشگاه مراجعه کنید.' ),
			array( 'q' => 'آیا موجود است؟', 'a' => 'بله، موجود است.' ),
			array( 'q' => 'آستر کیف از چیست؟', 'a' => 'احتمالاً پارچه است.' ),
			array( 'q' => 'دوخت کیف چند ردیف است؟', 'a' => 'دو ردیف.' ),
		),
	),
);
$faq_brief = Haa_Content::product_brief( 'کیف دوشی چرم', $many, array( 'length' => 'short', 'words' => 8 ), array( 'module' => 'product-content' ) );
ok( 'only the publishable question is left', 1 === count( $faq_brief['faq'] ) && 'دوخت کیف چند ردیف است؟' === $faq_brief['faq'][0]['q'], wp_json_encode( $faq_brief['faq'], JSON_UNESCAPED_UNICODE ) );
ok( 'each drop is reported', 3 === count( array_filter( (array) $faq_brief['notes'], function ( $n ) { return false !== mb_strpos( (string) $n, 'سؤال متداول حذف شد', 0, 'UTF-8' ); } ) ), wp_json_encode( (array) $faq_brief['notes'], JSON_UNESCAPED_UNICODE ) );

// Every tone carries its own sample sentences and the style rules.
$seen_examples = array();
foreach ( array_keys( Haa_Util::tones() ) as $tone ) {
	$style_i = Haa_Content::style_suffix( array( 'tone' => $tone, 'length' => 'medium', 'topic' => 'کیف' ) );
	$example = Haa_Content::tone_examples( $tone );
	ok( "tone $tone ships an example", '' !== $example && false !== mb_strpos( $style_i, $example, 0, 'UTF-8' ) );
	$seen_examples[ $example ] = true;
}
ok( 'the five tones are five different samples', 5 === count( $seen_examples ), (string) count( $seen_examples ) );
$style_any = Haa_Content::style_suffix( array( 'tone' => 'friendly', 'length' => 'medium', 'keywords' => 'کیف چرم', 'topic' => 'کیف' ) );
ok( 'the prompt bans translation-ese and says half-space', false !== mb_strpos( $style_any, 'ترجمه‌نما', 0, 'UTF-8' ) && false !== mb_strpos( $style_any, 'نیم‌فاصله', 0, 'UTF-8' ) );
ok( 'the keyword must reach title, meta and first paragraph', false !== mb_strpos( $style_any, 'پاراگراف اول', 0, 'UTF-8' ) );

// The anti-guessing rule and the category/name contradiction go into the prompt.
$b_sent = Haa_Content::product_brief( 'روغن اصل Syrup', array( 'نام' => 'روغن اصل Syrup', 'دسته‌بندی' => 'انواع ماسک' ), array( 'length' => 'short', 'words' => 8 ), array( 'module' => 'product-content' ) );
$j_sent  = json_decode( (string) $GLOBALS['pc_body'], true );
$sys_j   = (string) ( $j_sent['messages'][0]['content'] ?? '' );
ok( 'the prompt forbids guessing the use from the name', false !== mb_strpos( $sys_j, 'از روی نامش حدس نزن', 0, 'UTF-8' ), preg_replace( '/\s+/u', ' ', mb_substr( $sys_j, 0, 200 ) ) );
ok( 'and sends the contradiction to notes', false !== mb_strpos( $sys_j, 'نمی‌خواند', 0, 'UTF-8' ) );
ok( 'and forbids building a story out of a vague category', false !== mb_strpos( $sys_j, 'داستان نساز', 0, 'UTF-8' ) );

// A product with no keyword gets a focus keyword from name + category.
$k_full = Haa_Module_Product_Content::derived_keyword( array( 'نام' => 'روغن اصل Syrup', 'دسته‌بندی' => 'ماسک مو' ) );
ok( 'derived keyword is name + category', 'روغن اصل Syrup ماسک مو' === $k_full, $k_full );
$k_dup  = Haa_Module_Product_Content::derived_keyword( array( 'نام' => 'کفش چرم مردانه', 'دسته‌بندی' => 'مردانه، کفش چرم' ) );
ok( 'a category already in the name is not repeated', 'کفش چرم مردانه' === $k_dup, $k_dup );
$GLOBALS['pc_replies'] = array( array( 'html' => '<p>بند نمونه.</p>' ) );
$GLOBALS['pc_body']    = '';
$k_brief = Haa_Module_Product_Content::generate_brief( $rich, array( 'tone' => 'friendly', 'length' => 'short', 'keywords' => '' ) );
$j_body  = json_decode( (string) $GLOBALS['pc_body'], true );
$j_sys   = (string) ( $j_body['messages'][0]['content'] ?? '' );
ok( 'the generated prompt carries the derived keyword', false !== mb_strpos( $j_sys, 'کلیدواژه', 0, 'UTF-8' ) && false !== mb_strpos( $j_sys, (string) $k_brief['keywords'], 0, 'UTF-8' ), (string) ( $k_brief['keywords'] ?? '' ) );
ok( 'the preview keeps it for the reviewer', '' !== (string) ( $k_brief['keywords'] ?? '' ), (string) ( $k_brief['keywords'] ?? '' ) );
$GLOBALS['pc_replies'] = array();

/* ------------------------------------------------------------------
 * K. provider error text never carries a credential (BL-05) + keyless IPv6 (BL-07)
 * ---------------------------------------------------------------- */
echo "\n=== K. error detail is masked, truncated and never reaches a visitor ===\n";

// These run on the old code too — that is the point: before the fix they must
// FAIL, because the provider text (with a key in it) reached the message and
// the visitor screen.
$k_settings_before = get_option( HAAI_OPTION );
$k_s = (array) Haa_Settings::get();
$k_s['provider']['base_url'] = 'https://8.8.8.8/v1';
$k_s['provider']['api_key']  = 'capture-only';
$k_s['provider']['model']    = 'capture-model';
$k_s['provider']['retries']  = 0;
update_option( HAAI_OPTION, $k_s, 'no' );
Haa_Settings::flush_cache();

foreach ( array( 401, 403, 429, 500 ) as $k_code ) {
	$GLOBALS['pc_replies'] = array(
		array(
			'code' => $k_code,
			'body' => wp_json_encode( array( 'error' => array( 'message' => 'Secret sk-live-ZZZ1234567890yyyAAA is invalid for model capture-model' ) ) ),
		),
	);
	$GLOBALS['pc_body'] = '';
	$k_brief = Haa_Content::product_brief( 'محصول آزمایشی', array( 'نام' => 'محصول آزمایشی' ), array( 'length' => 'short', 'words' => 8 ), array( 'module' => 'product-content' ) );
	$k_err   = (string) ( $k_brief['error'] ?? '' );
	ok( $k_code . ': brief failed', empty( $k_brief['ok'] ) && '' !== $k_err, $k_err );
	ok( $k_code . ': no credential in the message', false === strpos( $k_err, 'ZZZ1234567890' ) && false === strpos( $k_err, 'sk-live' ), $k_err );
	ok( $k_code . ': message stays short', mb_strlen( $k_err ) <= 260, (string) mb_strlen( $k_err ) );
	ok( $k_code . ': Persian sentence first', (bool) preg_match( '/[\x{0600}-\x{06FF}]/u', $k_err ), $k_err );
}

// The visitor-facing chat path must show one Persian sentence, nothing else.
$GLOBALS['pc_replies'] = array(
	array(
		'code' => 401,
		'body' => wp_json_encode( array( 'error' => array( 'message' => 'Incorrect API key provided: sk-live-ZZZ1234567890' ) ) ),
	),
);
$k_chat = Haa_Chat::ask( 'سلام', array(), 'pc-k-probe-' . wp_generate_password( 8, false ) );
$k_cerr = (string) ( $k_chat['error'] ?? '' );
ok( 'chat answer produced', is_array( $k_chat ) );
ok( 'visitor sees no provider text', false === strpos( $k_cerr, 'sk-live' ) && false === strpos( $k_cerr, 'Incorrect' ), $k_cerr );
ok( 'visitor message is Persian and generic', '' === $k_cerr || (bool) preg_match( '/در دسترس نیست/', $k_cerr ), $k_cerr );

// BL-07: an IPv6 loopback base URL is still keyless.
$k_s['provider']['api_key']  = '';
$k_s['provider']['base_url'] = 'http://[::1]:11434/v1';
update_option( HAAI_OPTION, $k_s, 'no' );
Haa_Settings::flush_cache();
$GLOBALS['pc_replies'] = array( array( 'html' => '<p>خروجی محلی</p>' ) );
$GLOBALS['pc_body']    = '';
$GLOBALS['pc_calls']   = 0;
$k_local = Haa_Content::product_brief( 'محصول آزمایشی', array( 'نام' => 'محصول آزمایشی' ), array( 'length' => 'short', 'words' => 8 ), array( 'module' => 'product-content' ) );
ok( 'ipv6 loopback needs no key', ! empty( $k_local['ok'] ) && (int) $GLOBALS['pc_calls'] > 0, (string) ( $k_local['error'] ?? '' ) );

// …but a host that merely contains the word is not local.
$k_s['provider']['base_url'] = 'http://localhost.attacker.example/v1';
update_option( HAAI_OPTION, $k_s, 'no' );
Haa_Settings::flush_cache();
$GLOBALS['pc_replies'] = array();
$GLOBALS['pc_calls']   = 0;
$k_evil = Haa_Content::product_brief( 'محصول آزمایشی', array( 'نام' => 'محصول آزمایشی' ), array( 'length' => 'short', 'words' => 8 ), array( 'module' => 'product-content' ) );
ok( 'lookalike host is not keyless', false !== mb_strpos( (string) ( $k_evil['error'] ?? '' ), 'کلید API', 0, 'UTF-8' ) && 0 === (int) $GLOBALS['pc_calls'], (string) ( $k_evil['error'] ?? '' ) );

update_option( HAAI_OPTION, $k_settings_before, 'no' );
Haa_Settings::flush_cache();
$GLOBALS['pc_replies'] = array();
ok( 'bench settings restored after K', get_option( HAAI_OPTION ) === $k_settings_before );

/* ------------------------------------------------------------------
 * L. hedging that survives the prompt is reported, not hidden (BL-17)
 * ---------------------------------------------------------------- */
echo "\n=== L. vague phrases in the body become a reviewer note ===\n";

$lv = method_exists( 'Haa_Content', 'vague_hits' ) ? Haa_Content::vague_hits( '<p>این محصول احتمالاً چرم است و به نظر می‌رسد بادوام باشد.</p>' ) : array();
ok( 'vague_hits finds the hedges', count( $lv ) >= 2, wp_json_encode( $lv, JSON_UNESCAPED_UNICODE ) );
ok( 'a plain body has no vague hits', method_exists( 'Haa_Content', 'vague_hits' ) && array() === Haa_Content::vague_hits( '<p>جنس بدنه چرم طبیعی است.</p>' ) );

$l_settings_before = get_option( HAAI_OPTION );
$l_s = (array) Haa_Settings::get();
$l_s['provider']['base_url'] = 'https://8.8.8.8/v1';
$l_s['provider']['api_key']  = 'capture-only';
$l_s['provider']['model']    = 'capture-model';
$l_s['provider']['retries']  = 0;
update_option( HAAI_OPTION, $l_s, 'no' );
Haa_Settings::flush_cache();

$GLOBALS['pc_replies'] = array(
	array(
		'html' => '<p>این ماسک احتمالاً برای همه مناسب است و به نظر می‌رسد سبک باشد.</p>',
		'faq'  => array(),
	),
);
$l_brief = Haa_Content::product_brief( 'ماسک پارچه‌ای', array( 'نام' => 'ماسک پارچه‌ای', 'دسته‌بندی' => 'ماسک' ), array( 'length' => 'short', 'words' => 8 ), array( 'module' => 'product-content' ) );
$l_notes = (array) ( $l_brief['notes'] ?? array() );
ok( 'hedging still reaches the body (model disobeys)', false !== mb_strpos( (string) ( $l_brief['html'] ?? '' ), 'احتمالاً', 0, 'UTF-8' ), preg_replace( '/\s+/u', ' ', mb_substr( (string) ( $l_brief['html'] ?? '' ), 0, 90 ) ) );
ok( 'and the reviewer is told about it', (bool) array_filter( $l_notes, function ( $n ) {
	return false !== mb_strpos( (string) $n, 'عبارت مبهم', 0, 'UTF-8' );
} ), wp_json_encode( $l_notes, JSON_UNESCAPED_UNICODE ) );

$GLOBALS['pc_replies'] = array( array( 'html' => '<p>جنس بدنه چرم طبیعی و آستر آن پارچه‌ای است.</p>', 'faq' => array() ) );
$l_clean = Haa_Content::product_brief( 'کیف چرم', array( 'نام' => 'کیف چرم' ), array( 'length' => 'short', 'words' => 8 ), array( 'module' => 'product-content' ) );
ok( 'a clean body gets no such note', ! array_filter( (array) ( $l_clean['notes'] ?? array() ), function ( $n ) {
	return false !== mb_strpos( (string) $n, 'عبارت مبهم', 0, 'UTF-8' );
} ), wp_json_encode( (array) ( $l_clean['notes'] ?? array() ), JSON_UNESCAPED_UNICODE ) );

update_option( HAAI_OPTION, $l_settings_before, 'no' );
Haa_Settings::flush_cache();
$GLOBALS['pc_replies'] = array();
ok( 'bench settings restored after L', get_option( HAAI_OPTION ) === $l_settings_before );

if ( ! method_exists( 'Haa_API', 'mask_secrets' ) ) {
	echo "  SKIP mask_secrets() unit checks (does not exist yet — legacy run)\n";
} else {
	$leaky = 'Incorrect API key provided: sk-proj-AbCdEf1234567890GhIjKlMnOp. Request id: 01Ab8f12cd34ef56ab78cd90ef123456ab78';
	$m     = Haa_API::mask_secrets( $leaky );
	ok( 'openai-style key is masked', false === strpos( $m, 'AbCdEf1234567890' ), $m );
	ok( 'the masked text still says what failed', false !== strpos( $m, 'Incorrect API key' ), $m );
	ok( 'google key is masked', false === strpos( Haa_API::mask_secrets( 'invalid key AIzaSyD-9nKx1234567890abcdefGHIJKL' ), '9nKx1234567890' ) );
	ok( 'bearer token is masked', false === strpos( Haa_API::mask_secrets( 'Authorization: Bearer eyJhbGciOiJSUzI1NiIsImth' ), 'eyJhbGciOiJSUzI1NiIs' ) );
	ok( 'long hex is masked', false === strpos( Haa_API::mask_secrets( 'trace 8f12cd34ef56ab78cd90ef123456ab78 done' ), '8f12cd34ef56ab78cd90' ) );
	ok( 'detail is capped at 200 chars', mb_strlen( Haa_API::mask_secrets( str_repeat('x', 900) ) ) <= 200 );
	ok( 'an ordinary Persian sentence survives', 'مدل انتخابی فعال نیست' === Haa_API::mask_secrets( 'مدل انتخابی فعال نیست' ), Haa_API::mask_secrets( 'مدل انتخابی فعال نیست' ) );
}

/* ------------------------------------------------------------------
 * Teardown
 * ---------------------------------------------------------------- */
wp_delete_post( $rich, true );
wp_delete_post( $clean, true );
wp_delete_post( $mid, true );
update_option( 'woocommerce_currency', $currency_before );
ok( 'teardown: no fixture product left', ! get_post( $rich ) && ! get_post( $clean ) && ! get_post( $mid ) );
ok( 'teardown: currency restored', get_option( 'woocommerce_currency' ) === $currency_before, (string) get_option( 'woocommerce_currency' ) );
echo "\nFAILURES: " . $GLOBALS['fail'] . "\n";
