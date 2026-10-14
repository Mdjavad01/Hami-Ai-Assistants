<?php
/**
 * گام ۳ — سنجش کیفیت خروجی «محتوای محصول» روی محصولات واقعی.
 *
 * Only builds previews. It never calls handle_apply / Haa_Seo::apply, so no post
 * content on the site is modified; what it does write is the one-hour preview
 * transient and the usage rows the model call itself produces.
 *
 * Run on sks (the only site with a real provider key):
 *   php C:\xampp\wp-cli\wp-cli.phar --path=C:\xampp\htdocs\sks eval-file <this file> [ids csv]
 */
$GLOBALS['fail'] = 0;

/** One line of the report. */
function say( $text ) {
	echo $text . "\n";
}

/** How many real facts does this product actually have? */
function fact_profile( $pid ) {
	$notes = array();
	$facts = Haa_Content::product_facts( $pid, $notes );
	$long  = isset( $facts['توضیح کامل'] ) ? mb_strlen( $facts['توضیح کامل'], 'UTF-8' ) : 0;
	return array(
		'id'     => (int) $pid,
		'name'   => (string) ( $facts['نام'] ?? get_the_title( $pid ) ),
		'cat'    => (string) ( $facts['دسته‌بندی'] ?? '' ),
		'facts'  => count( $facts ),
		'long'   => (int) $long,
		'notes'  => count( (array) $notes ),
		'price'  => isset( $facts['قیمت'] ) ? 'yes' : 'no',
		'blob'    => implode( ' | ', array_map( function ( $k, $v ) {
			return $k . '=' . Haa_Util::cut( $v, 40 );
		}, array_keys( $facts ), array_values( $facts ) ) ),
	);
}

$ids = array();
if ( isset( $args[0] ) && '' !== trim( (string) $args[0] ) ) {
	$ids = array_filter( array_map( 'absint', explode( ',', $args[0] ) ) );
}
if ( ! $ids ) {
	// Rank the catalogue by how much real data each product carries.
	$all = get_posts( array( 'post_type' => 'product', 'post_status' => 'publish', 'numberposts' => 400, 'fields' => 'ids' ) );
	$rows = array();
	foreach ( $all as $pid ) {
		$r = fact_profile( $pid );
		if ( $r['facts'] >= 4 ) {
			$rows[] = $r;
		}
	}
	usort( $rows, function ( $a, $b ) {
		return ( $b['facts'] * 1000 + $b['long'] ) - ( $a['facts'] * 1000 + $a['long'] );
	} );
	say( 'catalog products=' . count( $all ) . ' with >=4 facts=' . count( $rows ) );
	foreach ( array_slice( $rows, 0, 8 ) as $r ) {
		say( sprintf( '  rich  #%d facts=%d long=%d price=%s cat=%s name=%s', $r['id'], $r['facts'], $r['long'], $r['price'], $r['cat'], $r['name'] ) );
	}
	$thin = array();
	foreach ( $all as $pid ) {
		$r = fact_profile( $pid );
		if ( $r['facts'] <= 3 && $r['long'] < 80 ) {
			$thin[] = $r;
			if ( count( $thin ) >= 3 ) {
				break;
			}
		}
	}
	foreach ( $thin as $r ) {
		say( sprintf( '  thin  #%d facts=%d long=%d name=%s', $r['id'], $r['facts'], $r['long'], $r['name'] ) );
	}
	$ids = array_slice( array_merge(
		array_column( array_slice( $rows, 0, 3 ), 'id' ),
		array_column( $thin, 'id' ),
		array( 13111 ) // the گام ۱ defect sample
	), 0, 6 );
}

$ids = array_values( array_unique( array_filter( $ids, 'get_post' ) ) );
$gap = isset( $args[1] ) ? max( 0, (int) $args[1] ) : 0;
say( "\nrunning on ids: " . implode( ',', $ids ) . '  gap=' . $gap . 's' );

foreach ( $ids as $pid ) {
	if ( $gap > 0 ) {
		sleep( $gap );   // Groq free tier caps output tokens per minute, not per day.
	}
	$before = fact_profile( $pid );
	$t0     = microtime( true );
	$brief  = Haa_Module_Product_Content::generate_brief(
		$pid,
		array(
			'tone'     => (string) Haa_Settings::get( 'content.default_tone', 'friendly' ),
			'length'   => 'long',
			'keywords' => '',
		)
	);
	// A 429 on a minute-window quota is worth one retry, not a wrong conclusion.
	$retried = '';
	if ( empty( $brief['ok'] ) && ( false !== mb_strpos( (string) ( $brief['error'] ?? '' ), 'محدود', 0, 'UTF-8' ) || false !== strpos( (string) ( $brief['error'] ?? '' ), 'too large' ) ) ) {
		$retried = ' (after one retry)';
		sleep( 70 );
		$brief = Haa_Module_Product_Content::generate_brief(
			$pid,
			array(
				'tone'     => (string) Haa_Settings::get( 'content.default_tone', 'friendly' ),
				'length'   => 'long',
				'keywords' => '',
			)
		);
	}
	$dt = round( microtime( true ) - $t0, 1 );
	if ( empty( $brief['ok'] ) ) {
		say( "\n#{$pid} FAILED in {$dt}s: " . (string) ( $brief['error'] ?? '' ) );
		continue;
	}
	$html  = (string) ( $brief['html'] ?? '' );
	$text  = Haa_Content::plain_text( Haa_Util::html_to_text( $html ) );
	$faq   = (array) ( $brief['faq'] ?? array() );
	$notes = (array) ( $brief['notes'] ?? array() );
	$leak  = false !== strpos( $html, '[نیاز' ) || false !== strpos( $text, '[نیاز' );
	// Show *where* a banned word appears: «موجود» in Persian prose usually means
	// "present", not "in stock", and a bare yes/no would be a false alarm.
	$hit_ctx = function ( $pattern ) use ( $text ) {
		if ( ! preg_match_all( '/' . $pattern . '/u', $text, $mm, PREG_OFFSET_CAPTURE ) ) {
			return 'no';
		}
		$out = array();
		foreach ( array_slice( $mm[0], 0, 3 ) as $m ) {
			$start = max( 0, (int) $m[1] - 28 );
			$out[]   = '…' . trim( mb_substr( $text, $start, mb_strlen( $m[0], 'UTF-8' ) + 56, 'UTF-8' ) ) . '…';
		}
		return implode( ' ⟸ ', $out );
	};
	$price = $hit_ctx( 'قیمت|تومان|ریال|موجودی|تخفیف' );
	$hed   = $hit_ctx( 'احتمالاً|به نظر می‌رسد|حدساً|تا حد زیادی|معمولاً' );
	$block = '';
	if ( preg_match( '/<h2>(مقاله‌های مرتبط|محصولات مرتبط)<\/h2>/u', $html, $mm ) ) {
		$block = $mm[1];
	}
	preg_match_all( '/<li><a href="[^"]*">([^<]*)<\/a><\/li>/u', $html, $ml );
	say( "\n#{$pid} «{$before['name']}» ({$dt}s, facts={$before['facts']}){$retried}" );
	say( sprintf(
		'  words=%d target=%d truncated=%s faq=%d notes=%d keywords=%s seo_title=%s',
		(int) ( $brief['words'] ?? 0 ),
		(int) ( $brief['target_words'] ?? 0 ),
		! empty( $brief['truncated'] ) ? 'yes' : 'no',
		count( $faq ),
		count( $notes ),
		wp_json_encode( $brief['keywords'] ?? null, JSON_UNESCAPED_UNICODE ),
		Haa_Util::cut( (string) ( $brief['seo_title'] ?? '' ), 60 )
	) );
	say( '  note-leak-in-body=' . ( $leak ? 'YES (BAD)' : 'no' ) );
	say( '  price-words-in-body: ' . $price );
	say( '  hedging-in-body:     ' . $hed );
	say( '  related block=' . ( '' === $block ? 'none' : $block ) . ( '' !== $block ? ' → ' . implode( ' / ', array_slice( $ml[1], 0, 3 ) ) : '' ) );
	foreach ( array_slice( $notes, 0, 6 ) as $n ) {
		say( '  • ' . Haa_Util::cut( (string) $n, 150 ) );
	}
	foreach ( array_slice( $faq, 0, 3 ) as $row ) {
		say( '  ? ' . Haa_Util::cut( (string) ( $row['q'] ?? '' ), 90 ) );
	}
	say( '  first 220 chars: ' . Haa_Util::cut( $text, 220 ) );
}

say( "\nprovider used: " . Haa_Settings::get( 'provider.model' ) . ' @ ' . Haa_Settings::get( 'provider.base_url' ) );
say( 'include_price=' . (int) Haa_Settings::get( 'content.include_price', 0 ) . ' internal_links=' . (int) Haa_Settings::get( 'content.internal_links', 0 ) . ' min_score=' . (float) Haa_Settings::get( 'search.min_score', 0 ) );
say( 'indexed chunks=' . (int) $GLOBALS['wpdb']->get_var( 'SELECT COUNT(*) FROM ' . Haa_DB::table( 'chunks' ) ) . ' vectors=' . (int) $GLOBALS['wpdb']->get_var( 'SELECT COUNT(*) FROM ' . Haa_DB::table( 'vectors' ) ) );
