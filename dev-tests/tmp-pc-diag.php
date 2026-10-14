<?php
/**
 * Read-only diagnostic for the product-content sample (sks product 13111).
 *
 * Prints the stored preview transient exactly as the admin screen saw it, the
 * facts that went into the prompt, and counts (words / bracketed notes / FAQ /
 * related block). Writes nothing to the site.
 *
 * Usage: php C:\xampp\wp-cli\wp-cli.phar --path=C:\xampp\htdocs\sks eval-file dev-tests/tmp-pc-diag.php [pid]
 */
$pid = isset( $args[0] ) ? (int) $args[0] : 13111;

/**
 * Persian-aware word count.
 *
 * @param string $text HTML or plain text.
 * @return int
 */
function pc_words( $text ) {
	$words = preg_split( '/\s+/u', trim( (string) wp_strip_all_tags( $text ) ), -1, PREG_SPLIT_NO_EMPTY );
	return is_array( $words ) ? count( $words ) : 0;
}

echo '=== product ' . $pid . ' : ' . get_the_title( $pid ) . " ===\n";

$preview = get_transient( 'haai_pc_preview_' . $pid );
if ( ! is_array( $preview ) ) {
	echo "preview transient: ABSENT\n";
} else {
	echo "preview transient: PRESENT\n";
	echo 'keys          : ' . implode( ', ', array_keys( $preview ) ) . "\n";
	echo 'html words    : ' . pc_words( $preview['html'] ?? '' ) . "\n";
	echo 'faq count     : ' . count( (array) ( $preview['faq'] ?? array() ) ) . "\n";
	echo 'seo_title     : ' . ( $preview['seo_title'] ?? '' ) . "\n";
	echo 'slug          : ' . ( $preview['slug'] ?? '' ) . "\n";
	echo 'excerpt words : ' . pc_words( $preview['excerpt'] ?? '' ) . "\n";

	$all = (string) ( $preview['html'] ?? '' );
	foreach ( (array) ( $preview['faq'] ?? array() ) as $row ) {
		$all .= ' ' . ( $row['q'] ?? '' ) . ' ' . ( $row['a'] ?? '' );
	}
	$all .= ' ' . ( $preview['seo_title'] ?? '' ) . ' ' . ( $preview['seo_description'] ?? '' ) . ' ' . ( $preview['excerpt'] ?? '' );

	preg_match_all( '/[\[٪]?[^\[\]]*نیاز[^\[\]]*\]/u', $all, $notes );
	echo 'bracket notes : ' . count( $notes[0] ) . "\n";
	foreach ( array_slice( $notes[0], 0, 12 ) as $n ) {
		echo '  * ' . mb_substr( trim( $n ), 0, 120 ) . "\n";
	}
	echo 'total words (html+faq+meta): ' . pc_words( $all ) . "\n";
	echo "----- full html -----\n";
	echo $preview['html'] ?? '';
	echo "\n----- faq -----\n";
	echo wp_json_encode( $preview['faq'] ?? array(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) . "\n";
}

echo "\n=== product_facts( pid ) ===\n";
foreach ( Haa_Content::product_facts( $pid ) as $k => $v ) {
	echo '- ' . $k . ': ' . str_replace( "\n", ' ', $v ) . "\n";
}

echo "\n=== live provider/content settings (no secrets) ===\n";
$s = Haa_Settings::get();
echo 'provider=' . $s['provider']['provider'] . ' model=' . $s['provider']['model'] . ' max_tokens=' . $s['provider']['max_tokens'] . ' timeout=' . $s['provider']['timeout'] . ' temperature=' . $s['provider']['temperature'] . ' retries=' . $s['provider']['retries'] . "\n";
echo 'content.internal_links=' . var_export( $s['content']['internal_links'], true ) . ' link_count=' . var_export( $s['content']['link_count'], true ) . "\n";
echo 'content.default_tone=' . var_export( $s['content']['default_tone'], true ) . ' default_length=' . var_export( $s['content']['default_length'], true ) . " site_profile_set=" . ( (string) ( $s['profile']['notes'] ?? '' ) !== '' || (string) ( $s['profile']['about'] ?? '' ) !== '' ? 'yes' : 'no' ) . "\n";

echo "\n=== internal_links() result for this product ===\n";
$links = Haa_Content::internal_links( $pid, '' );
foreach ( $links as $l ) {
	echo '- [' . get_post_type( $l['id'] ) . '/' . get_post_status( $l['id'] ) . '] ' . $l['title'] . ' -> ' . $l['url'] . "\n";
}
echo 'count=' . count( $links ) . "\n";
