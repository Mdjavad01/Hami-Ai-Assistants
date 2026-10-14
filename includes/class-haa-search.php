<?php
/**
 * Retrieval core: Persian-normalised BM25 plus optional embedding search.
 *
 * BM25 is always available and costs one query. Embeddings are opt-in because
 * they need a paid model and a table of vectors — when they are off, or when
 * the provider fails, everything falls back to BM25 transparently.
 *
 * @package Hami_AI_Assistant
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Haa_Search {

	/** @var string Rebuild cursor option (shared with v1). */
	const STATE_OPTION = 'hami_ai_kb_state';

	const K1            = 1.2;
	const B             = 0.75;
	const CANDIDATE_CAP = 800;
	const ALLOWED_EXT   = array( 'txt', 'md', 'docx' );

	/** @var array|null Cached KB config for this request. */
	private static $config = null;

	/* ==================================================================
	 * Text utilities
	 * =============================================================== */

	/**
	 * Persian / Arabic normalisation used at BOTH index and query time.
	 *
	 * @param string $text Raw text.
	 * @return string
	 */
	public static function normalize_text( $text ) {
		if ( ! is_string( $text ) || '' === $text ) {
			return '';
		}
		if ( ! mb_check_encoding( $text, 'UTF-8' ) ) {
			$text = mb_convert_encoding( $text, 'UTF-8', 'ISO-8859-1' );
		}

		// Unify the letter forms that differ between Persian and Arabic code points.
		$map = array(
			'ي' => 'ی',
			'ى' => 'ی',
			'ك' => 'ک',
			'ة' => 'ه',
			'ۀ' => 'ه',
			'ؤ' => 'و',
			'إ' => 'ا',
			'أ' => 'ا',
			'ٱ' => 'ا',
			'ـ' => '',
			"\xE2\x80\x8C" => ' ', // ZWNJ.
			"\xE2\x80\x8B" => ' ', // ZWSP.
			"\xE2\x80\x8D" => ' ', // ZWJ.
		);
		$text = strtr( $text, $map );

		// Persian, Arabic and Urdu digits to ASCII.
		$text = Haa_Util::to_ascii_digits( $text );

		// Remove diacritics and tatweel.
		$text = preg_replace( '/[\x{064B}-\x{0652}\x{0670}\x{0640}]/u', '', $text );

		// Anything that is not a letter or a digit becomes a space.
		$text = preg_replace( '/[^\p{L}\p{N}]+/u', ' ', $text );
		$text = trim( preg_replace( '/\s+/u', ' ', $text ) );

		return function_exists( 'mb_strtolower' ) ? mb_strtolower( $text, 'UTF-8' ) : strtolower( $text );
	}

	/**
	 * Persian + universal stop words.
	 *
	 * @return array<string,1>
	 */
	public static function stopwords() {
		static $list = null;
		if ( null !== $list ) {
			return $list;
		}
		$words = array(
			'و', 'در', 'به', 'از', 'که', 'این', 'را', 'با', 'است', 'برای', 'آن', 'یک', 'خود', 'تا',
			'کرد', 'بر', 'هم', 'نیز', 'گفت', 'می', 'شود', 'شده', 'بود', 'دارد', 'ما', 'دو', 'او',
			'اند', 'یا', 'اما', 'باید', 'هر', 'خیلی', 'هست', 'نبود', 'شما', 'آنها', 'بی', 'پس',
			'اگر', 'حال', 'کنند', 'کنید', 'مورد', 'شد', 'بین', 'یعنی', 'چه', 'چرا', 'کدام',
			'the', 'a', 'an', 'and', 'or', 'of', 'to', 'in', 'for', 'on', 'with', 'is', 'are',
			'was', 'were', 'be', 'been', 'it', 'this', 'that', 'as', 'at', 'by', 'from', 'www',
			'http', 'https', 'com', 'ir', 'html', 'php', 'net', 'org',
		);
		$list = array_fill_keys( $words, 1 );
		return $list;
	}

	/**
	 * @param string $text Text.
	 * @return array<int,string>
	 */
	public static function tokenize( $text ) {
		$norm = self::normalize_text( $text );
		if ( '' === $norm ) {
			return array();
		}
		$stops  = self::stopwords();
		$tokens = array();
		foreach ( explode( ' ', $norm ) as $token ) {
			if ( '' === $token || isset( $stops[ $token ] ) ) {
				continue;
			}
			if ( preg_match( '/^[\p{L}]{2,}$|^[\p{N}]{1,}$/u', $token ) ) {
				$tokens[ $token ] = 1;
			}
		}
		return array_keys( $tokens );
	}

	/* ==================================================================
	 * Scope + indexing
	 * =============================================================== */

	/**
	 * Knowledge-base settings, with safe defaults.
	 *
	 * @return array{enabled:bool,types:array,cats:array,words:int,overlap:int,max_bytes:int}
	 */
	public static function config() {
		if ( null !== self::$config ) {
			return self::$config;
		}
		$a       = Haa_Settings::get( 'advanced' );
		$types   = (array) $a['kb_post_types'];
		$types   = $types ? $types : array( 'post', 'page' );
		$cats    = array_filter( array_map( 'intval', (array) $a['kb_categories'] ) );

		self::$config = array(
			'enabled'   => ! empty( $a['kb_enabled'] ) && ! empty( $a['enable_rag'] ),
			'types'     => $types,
			'cats'      => $cats,
			'words'     => max( 300, min( 500, (int) $a['kb_chunk_words'] ) ),
			'overlap'   => max( 0, min( 50, (int) $a['kb_overlap_words'] ) ),
			'max_bytes' => max( 64, (int) $a['kb_max_file_kb'] ) * 1024,
		);
		return self::$config;
	}

	/**
	 * Is this post inside the configured index scope?
	 *
	 * @param int $post_id Post id.
	 * @return bool
	 */
	public static function in_scope( $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post || 'publish' !== $post->post_status ) {
			return false;
		}

		$cfg = self::config();

		if ( 'attachment' === $post->post_type ) {
			return in_array( self::file_ext( $post_id ), self::ALLOWED_EXT, true )
				&& self::file_size( $post_id ) <= $cfg['max_bytes'];
		}

		if ( ! in_array( $post->post_type, $cfg['types'], true ) ) {
			return false;
		}

		if ( $cfg['cats'] ) {
			$terms = wp_get_post_categories( $post_id );
			if ( ! array_intersect( $cfg['cats'], array_map( 'intval', $terms ) ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Replace every chunk belonging to one post or attachment.
	 *
	 * @param int $post_id Post id.
	 * @return int Chunks written.
	 */
	public static function index_post( $post_id ) {
		global $wpdb;

		$post_id = (int) $post_id;
		self::remove_post( $post_id );

		if ( ! self::in_scope( $post_id ) ) {
			return 0;
		}

		$source = self::build_source( $post_id );
		if ( '' === trim( $source['content'] ) ) {
			return 0;
		}

		$table  = Haa_DB::table( 'chunks' );
		$chunks = self::chunk_text( $source['content'] );
		$now    = current_time( 'mysql', true );
		$n      = 0;

		foreach ( $chunks as $index => $chunk ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->insert(
				$table,
				array(
					'post_id'      => $post_id,
					'source'       => substr( $source['source'], 0, 40 ),
					'title'        => Haa_Util::cut( $source['title'], 250 ),
					'url'          => substr( $source['url'], 0, 250 ),
					'chunk_index'  => $index,
					'content'      => $chunk,
					'content_norm' => self::normalize_text( $chunk ) . ' ' . self::normalize_text( $source['title'] ),
					'meta'         => wp_json_encode( $source['meta'] ),
					'date_updated' => $now,
				),
				array( '%d', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s' )
			);
			$n++;
		}
		return $n;
	}

	/**
	 * @param int $post_id Post id.
	 * @return int Rows removed.
	 */
	public static function remove_post( $post_id ) {
		global $wpdb;
		$post_id = (int) $post_id;

		// Vectors hang off chunk ids; drop them first so re-indexing the same
		// post never leaves stale embeddings pointing at dead chunks.
		if ( Haa_Settings::is_module_enabled( 'semantic-search' ) ) {
			$vectors = Haa_DB::table( 'vectors' );
			$chunks  = Haa_DB::table( 'chunks' );
			// phpcs:ignore WordPress.DB
			$wpdb->query( $wpdb->prepare( "DELETE v FROM $vectors v INNER JOIN $chunks c ON c.id = v.chunk_id WHERE c.post_id = %d", $post_id ) );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return (int) $wpdb->delete( Haa_DB::table( 'chunks' ), array( 'post_id' => $post_id ), array( '%d' ) );
	}

	/**
	 * @param int $post_id Post id.
	 * @return array{source:string,title:string,url:string,content:string,meta:array}
	 */
	public static function build_source( $post_id ) {
		$post = get_post( $post_id );
		$out  = array(
			'source'  => 'post',
			'title'   => $post ? get_the_title( $post ) : '',
			'url'     => $post ? (string) get_permalink( $post ) : '',
			'content' => '',
			'meta'    => array(),
		);

		if ( ! $post ) {
			return $out;
		}

		if ( 'attachment' === $post->post_type ) {
			$file = get_attached_file( $post_id );
			$out['source']  = 'file';
			$out['content'] = $file ? (string) self::read_document( $file ) : '';
			return $out;
		}

		if ( Haa_Util::woo() && 'product' === $post->post_type ) {
			$product = wc_get_product( $post_id );
			if ( $product ) {
				$lines   = array();
				$lines[] = 'نام محصول: ' . $product->get_name();
				if ( $product->get_short_description() ) {
					$lines[] = 'خلاصه: ' . Haa_Util::html_to_text( $product->get_short_description() );
				}
				if ( $product->get_description() ) {
					$lines[] = 'توضیحات: ' . Haa_Util::html_to_text( $product->get_description() );
				}
				if ( $product->get_price() ) {
					$lines[] = 'قیمت: ' . wc_price( $product->get_price() ) . ' ' . get_woocommerce_currency_symbol();
				}
				$stock = $product->get_stock_status();
				$lines[] = 'موجودی: ' . ( 'instock' === $stock ? 'موجود' : ( 'outofstock' === $stock ? 'ناموجود' : 'موجودی نامشخص' ) );
				$terms  = wp_get_post_terms( $post_id, 'product_cat', array( 'fields' => 'names' ) );
				if ( ! is_wp_error( $terms ) && $terms ) {
					$lines[] = 'دسته‌بندی: ' . implode( '، ', wp_list_pluck( $terms, 'name' ) );
				}
				$attrs = $product->get_attributes();
				foreach ( (array) $attrs as $attr ) {
					$name  = $attr instanceof WC_Product_Attribute ? $attr->get_name() : (string) $attr['name'];
					$values = $attr instanceof WC_Product_Attribute ? $attr->get_options() : (array) $attr['value'];
					$values = array_filter( array_map( 'strval', (array) $values ) );
					if ( $name && $values ) {
						$lines[] = $name . ': ' . implode( '، ', $values );
					}
				}
				$lines[] = 'لینک محصول: ' . get_permalink( $post_id );

				$out['source']  = 'product';
				$out['content'] = implode( "\n", $lines );
				$out['meta']    = array(
					'price' => (string) $product->get_price(),
					'sku'   => (string) $product->get_sku(),
				);
				return $out;
			}
		}

		$out['content'] = $out['title'] . "\n\n" . Haa_Util::html_to_text( $post->post_content );
		return $out;
	}

	/* ==================================================================
	 * Files
	 * =============================================================== */

	/**
	 * @param int $attachment_id Attachment id.
	 * @return string Lowercased extension, '' when unknown.
	 */
	public static function file_ext( $attachment_id ) {
		$file = get_attached_file( $attachment_id );
		return $file ? (string) strtolower( pathinfo( $file, PATHINFO_EXTENSION ) ) : '';
	}

	/**
	 * @param int $attachment_id Attachment id.
	 * @return int Bytes, PHP_INT_MAX when the file is missing.
	 */
	public static function file_size( $attachment_id ) {
		$file = get_attached_file( $attachment_id );
		return ( $file && is_readable( $file ) ) ? (int) filesize( $file ) : PHP_INT_MAX;
	}

	/**
	 * Read a supported document. PDF is deliberately unsupported: extracting it
	 * needs a heavy library that a 128 MB shared host cannot afford.
	 *
	 * @param string $file Path.
	 * @return string
	 */
	public static function read_document( $file ) {
		if ( ! is_readable( $file ) ) {
			return '';
		}
		$ext = (string) strtolower( pathinfo( $file, PATHINFO_EXTENSION ) );
		if ( ! in_array( $ext, self::ALLOWED_EXT, true ) ) {
			return '';
		}
		if ( 'docx' === $ext ) {
			return (string) self::read_docx( $file );
		}
		$raw = file_get_contents( $file );
		if ( false === $raw ) {
			return '';
		}
		// Strip UTF-8 BOM.
		if ( "\xEF\xBB\xBF" === substr( $raw, 0, 3 ) ) {
			$raw = substr( $raw, 3 );
		}
		return (string) $raw;
	}

	/**
	 * Minimal DOCX reader: unzip word/document.xml and strip the markup.
	 *
	 * @param string $file Path.
	 * @return string
	 */
	private static function read_docx( $file ) {
		if ( ! class_exists( 'ZipArchive' ) ) {
			return '';
		}
		$zip = new ZipArchive();
		if ( true !== $zip->open( $file ) ) {
			return '';
		}
		$xml = $zip->getFromName( 'word/document.xml' );
		$zip->close();
		if ( false === $xml ) {
			return '';
		}
		$xml = str_ireplace( array( '<w:tab/>', '<w:tab />' ), ' ', $xml );
		$xml = preg_replace( '#</w:p>|<w:br/>|<w:br />#i', "\n", $xml );
		$text = wp_strip_all_tags( $xml );
		$text = html_entity_decode( $text, ENT_QUOTES, 'UTF-8' );
		return trim( (string) preg_replace( "/\n{3,}/", "\n\n", $text ) );
	}

	/* ==================================================================
	 * Chunking
	 * =============================================================== */

	/**
	 * Split text into word-bounded chunks while keeping paragraph boundaries.
	 *
	 * @param string $text Text.
	 * @return array<int,string>
	 */
	public static function chunk_text( $text ) {
		$cfg    = self::config();
		$size   = $cfg['words'];
		$overlap = $cfg['overlap'];

		$text = trim( (string) $text );
		if ( '' === $text ) {
			return array();
		}

		$paragraphs = preg_split( '/\R+/u', $text );
		$chunks     = array();
		$current    = array();
		$count      = 0;

		foreach ( $paragraphs as $para ) {
			$para = trim( (string) $para );
			if ( '' === $para ) {
				continue;
			}
			$words = preg_split( '/\s+/u', $para, -1, PREG_SPLIT_NO_EMPTY );
			$words = is_array( $words ) ? $words : array();
			$wcount = count( $words );

			if ( $wcount > $size ) {
				// Oversized paragraph: flush what we have, then hard-split it.
				if ( $current ) {
					$chunks[] = implode( "\n\n", $current );
					$current  = array();
					$count    = 0;
				}
				foreach ( self::hard_split( $para, $size, $overlap ) as $piece ) {
					$chunks[] = $piece;
				}
				continue;
			}

			if ( $count + $wcount > $size && $current ) {
				$chunks[] = implode( "\n\n", $current );
				$carry    = $overlap > 0 ? self::tail_words( implode( ' ', $current ), $overlap ) : '';
				$current  = $carry ? array( $carry ) : array();
				$count    = $carry ? self::count_words( $carry ) : 0;
			}

			$current[] = $para;
			$count    += $wcount;
		}

		if ( $current ) {
			$chunks[] = implode( "\n\n", $current );
		}

		return array_values( array_filter( array_map( 'trim', $chunks ) ) );
	}

	/**
	 * @param string $text   Text.
	 * @param int    $size   Words per chunk.
	 * @param int    $over   Overlap words.
	 * @return array<int,string>
	 */
	private static function hard_split( $text, $size, $over ) {
		$words  = preg_split( '/\s+/u', trim( (string) $text ), -1, PREG_SPLIT_NO_EMPTY );
		$words  = is_array( $words ) ? $words : array();
		$out    = array();
		$buffer = array();

		foreach ( $words as $word ) {
			$buffer[] = $word;
			if ( count( $buffer ) >= $size ) {
				$out[]  = implode( ' ', $buffer );
				$carry  = $over > 0 ? array_slice( $buffer, -$over ) : array();
				$buffer = $carry;
			}
		}
		if ( $buffer && ( ! $out || count( $buffer ) > $over ) ) {
			$out[] = implode( ' ', $buffer );
		}
		return $out;
	}

	/**
	 * @param string $text Text.
	 * @param int    $n    Words.
	 * @return string
	 */
	private static function tail_words( $text, $n ) {
		$words = preg_split( '/\s+/u', trim( (string) $text ), -1, PREG_SPLIT_NO_EMPTY );
		$words = is_array( $words ) ? $words : array();
		return implode( ' ', array_slice( $words, -max( 1, (int) $n ) ) );
	}

	/**
	 * @param string $text Text.
	 * @return int
	 */
	private static function count_words( $text ) {
		$words = preg_split( '/\s+/u', trim( (string) $text ), -1, PREG_SPLIT_NO_EMPTY );
		return is_array( $words ) ? count( $words ) : 0;
	}

	/* ==================================================================
	 * Search
	 * =============================================================== */

	/**
	 * Hybrid (or BM25-only) retrieval.
	 *
	 * @param string $query     User query.
	 * @param int    $limit     Max hits.
	 * @param int    $max_chars Cumulative character budget.
	 * @return array<int,array{id:int,post_id:int,title:string,url:string,content:string,score:float}>
	 */
	public static function search( $query, $limit = 5, $max_chars = 1500 ) {
		$cfg = self::config();
		if ( ! $cfg['enabled'] ) {
			return array();
		}

		$tokens = self::tokenize( $query );
		if ( ! $tokens ) {
			return array();
		}

		$bm25 = self::bm25_search( $tokens );

		$use_vectors = ! empty( Haa_Settings::get( 'search.embeddings' ) )
			&& ! empty( Haa_Settings::get( 'search.hybrid' ) )
			&& Haa_Settings::is_module_enabled( 'semantic-search' );

		if ( $use_vectors ) {
			$vec = self::vector_search( $query, max( 20, $limit * 4 ) );
			if ( $vec ) {
				$bm25 = self::blend( $bm25, $vec );
			}
			// On any embedding failure we simply keep the BM25 list.
		}

		usort( $bm25, static function ( $a, $b ) {
			return $b['score'] <=> $a['score'];
		} );

		$out   = array();
		$chars = 0;
		foreach ( $bm25 as $hit ) {
			$len = mb_strlen( (string) $hit['content'] );
			if ( $chars + $len > $max_chars && $out ) {
				break;
			}
			$out[]   = $hit;
			$chars  += $len;
			if ( count( $out ) >= $limit ) {
				break;
			}
		}
		return $out;
	}

	/**
	 * SQL fragment: keep a chunk only while its post still exists, is
	 * published, has no password and sits in a configured (public) post type.
	 *
	 * The index already drops a post the moment it leaves "publish", but a
	 * row can survive a failed save, a direct DB edit or an old backup. This
	 * runs on EVERY public search as the query-time backstop, so nothing
	 * draft, private, scheduled, password-protected or deleted can ever come
	 * back out through the public endpoint.
	 *
	 * @param string $alias Chunk table alias.
	 * @return string INNER JOIN clause (with the conditions inlined).
	 */
	private static function published_only_sql( $alias = 'c' ) {
		global $wpdb;

		$types = (array) self::config()['types'];
		$types = $types ? $types : array( 'post', 'page' );
		$in    = array();
		foreach ( $types as $type ) {
			$in[] = $wpdb->prepare( '%s', (string) $type );
		}

		return " INNER JOIN {$wpdb->posts} p ON p.ID = {$alias}.post_id"
			. " AND p.post_status = 'publish'"
			. " AND p.post_password = ''"
			. ' AND p.post_type IN (' . implode( ',', $in ) . ') ';
	}

	/**
	 * BM25 over the normalised column, one OR-of-LIKE query.
	 *
	 * @param array<int,string> $tokens Query tokens.
	 * @return array<int,array>
	 */
	private static function bm25_search( $tokens ) {
		global $wpdb;

		$table   = Haa_DB::table( 'chunks' );
		$tokens  = array_slice( $tokens, 0, 12 );
		$likes   = array();
		$params  = array();
		foreach ( $tokens as $token ) {
			$likes[]   = 'c.content_norm LIKE %s';
			$params[]  = '%' . $wpdb->esc_like( $token ) . '%';
		}

		$sql = "SELECT c.id, c.post_id, c.title, c.url, c.content, c.content_norm FROM $table c"
			. self::published_only_sql( 'c' )
			. ' WHERE (' . implode( ' OR ', $likes ) . ') ORDER BY c.id DESC LIMIT ' . self::CANDIDATE_CAP;

		// phpcs:ignore WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A );
		if ( ! $rows ) {
			return array();
		}
		return self::bm25( $tokens, $rows );
	}

	/**
	 * Classic BM25 scoring.
	 *
	 * @param array<int,string> $tokens Tokens.
	 * @param array<int,array>  $rows   Candidate rows.
	 * @return array<int,array>
	 */
	private static function bm25( $tokens, $rows ) {
		$n     = count( $rows );
		$avgdl = 0;
		foreach ( $rows as $row ) {
			$avgdl += self::count_words( $row['content'] );
		}
		$avgdl = $n ? max( 1, $avgdl / $n ) : 1;

		// Document frequency per token.
		$df = array_fill_keys( $tokens, 0 );
		foreach ( $rows as $row ) {
			foreach ( $tokens as $token ) {
				if ( false !== mb_strpos( $row['content_norm'], $token ) ) {
					$df[ $token ]++;
				}
			}
		}

		$out = array();
		foreach ( $rows as $row ) {
			$len  = max( 1, self::count_words( $row['content'] ) );
			$score = 0.0;
			foreach ( $tokens as $token ) {
				if ( false === mb_strpos( $row['content_norm'], $token ) ) {
					continue;
				}
				$idf   = log( 1 + ( $n - $df[ $token ] + 0.5 ) / ( $df[ $token ] + 0.5 ) );
				$freq  = substr_count( $row['content_norm'], $token );
				$score += $idf * ( $freq * ( self::K1 + 1 ) ) / ( $freq + self::K1 * ( 1 - self::B + self::B * ( $len / $avgdl ) ) );
			}
			if ( $score > 0 ) {
				$out[] = array(
					'id'      => (int) $row['id'],
					'post_id' => (int) $row['post_id'],
					'title'   => (string) $row['title'],
					'url'     => (string) $row['url'],
					'content' => (string) $row['content'],
					'score'   => (float) $score,
				);
			}
		}
		return $out;
	}

	/* ==================================================================
	 * Embeddings (semantic-search module)
	 * =============================================================== */

	/**
	 * Cosine similarity over stored vectors. Bounded by search.chunk_cap.
	 *
	 * @param string $query Query text.
	 * @param int    $limit Max hits.
	 * @return array<int,array>
	 */
	private static function vector_search( $query, $limit ) {
		global $wpdb;

		$cap = max( 200, min( 20000, (int) Haa_Settings::get( 'search.chunk_cap', 2000 ) ) );
		$model = Haa_Settings::embedding_model();
		if ( '' === $model ) {
			return array();
		}

		$api = new Haa_API();
		$res = $api->embed( array( $query ) );
		if ( is_wp_error( $res ) || empty( $res[0] ) ) {
			return array();
		}
		$query_vec = $res[0];

		// The query vector is identical for every candidate: pay its squared
		// norm once instead of once per row.
		$query_sq = 0.0;
		foreach ( (array) $query_vec as $qv ) {
			$v    = (float) $qv;
			$query_sq += $v * $v;
		}

		$table = Haa_DB::table( 'vectors' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT v.chunk_id, v.vector, c.post_id, c.title, c.url, c.content FROM $table v
				 INNER JOIN " . Haa_DB::table( 'chunks' ) . " c ON c.id = v.chunk_id"
				 . self::published_only_sql( 'c' ) . "
				 WHERE v.model = %s ORDER BY v.id DESC LIMIT %d",
				$model,
				$cap
			),
			ARRAY_A
		);
		if ( ! $rows ) {
			return array();
		}

		$out = array();
		foreach ( $rows as $row ) {
			$vec = self::decode_vector( $row['vector'] );
			if ( ! $vec ) {
				continue;
			}
			$score = self::cosine( $query_vec, $vec, $query_sq );
			if ( $score <= 0 ) {
				continue;
			}
			$out[] = array(
				'id'      => (int) $row['chunk_id'],
				'post_id' => (int) $row['post_id'],
				'title'   => (string) $row['title'],
				'url'     => (string) $row['url'],
				'content' => (string) $row['content'],
				'score'   => (float) $score,
			);
		}

		usort( $out, static function ( $a, $b ) {
			return $b['score'] <=> $a['score'];
		} );
		return array_slice( $out, 0, max( 1, (int) $limit ) );
	}

	/**
	 * Normalize-then-blend BM25 and cosine scores.
	 *
	 * @param array $bm25 BM25 hits.
	 * @param array $vec  Cosine hits.
	 * @return array
	 */
	private static function blend( $bm25, $vec ) {
		// These are the SAME knobs the admin sees on the search screen
		// («وزن کلیدواژه» / «وزن معنایی»). Older internal keys bm25_weight /
		// vec_weight were never exposed and are gone.
		$w_b = (float) Haa_Settings::get( 'search.keyword_weight', 0.45 );
		$w_v = (float) Haa_Settings::get( 'search.semantic_weight', 0.55 );

		$max_b = 0.0;
		$max_v = 0.0;
		foreach ( $bm25 as $h ) {
			$max_b = max( $max_b, $h['score'] );
		}
		foreach ( $vec as $h ) {
			$max_v = max( $max_v, $h['score'] );
		}
		$max_b = $max_b > 0 ? $max_b : 1.0;
		$max_v = $max_v > 0 ? $max_v : 1.0;

		$merged = array();
		foreach ( $bm25 as $h ) {
			$merged[ $h['id'] ] = $h;
			$merged[ $h['id'] ]['score'] = ( $h['score'] / $max_b ) * $w_b;
		}
		foreach ( $vec as $h ) {
			$id    = $h['id'];
			$score = ( $h['score'] / $max_v ) * $w_v;
			if ( isset( $merged[ $id ] ) ) {
				$merged[ $id ]['score'] += $score;
			} else {
				$h['score'] = $score;
				$merged[ $id ] = $h;
			}
		}
		return array_values( $merged );
	}

	/**
	 * Store a vector compactly (base64 of little-endian float32).
	 *
	 * @param array $vector Numbers.
	 * @return string
	 */
	public static function encode_vector( $vector ) {
		$packed = '';
		foreach ( (array) $vector as $v ) {
			$pack = pack( 'g', (float) $v ); // Little-endian float32.
			if ( false !== $pack ) {
				$packed .= $pack;
			}
		}
		return base64_encode( $packed );
	}

	/**
	 * @param string $encoded Encoded vector.
	 * @return array<float>
	 */
	public static function decode_vector( $encoded ) {
		$raw = base64_decode( (string) $encoded, true );
		if ( false === $raw || '' === $raw ) {
			return array();
		}
		// Drop a trailing 1-3 bytes rather than let unpack() warn.
		$len = (int) ( strlen( $raw ) / 4 ) * 4;
		if ( $len < 4 ) {
			return array();
		}
		// One unpack() call for the whole buffer. The previous per-float loop
		// made ~192 calls per row, which dominated every hybrid search.
		$unpacked = unpack( 'g*', substr( $raw, 0, $len ) );
		if ( false === $unpacked || ! is_array( $unpacked ) ) {
			return array();
		}
		return array_values( $unpacked );
	}

	/**
	 * @param array      $a     Vector a.
	 * @param array      $b     Vector b.
	 * @param float|null $a_sq  Optional precomputed squared norm of $a. Vector
	 *                          search compares ONE query against every
	 *                          candidate, so passing this in skips the same
	 *                          norm work a few thousand times per request.
	 * @return float 0..1
	 */
	public static function cosine( $a, $b, $a_sq = null ) {
		$n = min( count( $a ), count( $b ) );
		if ( 0 === $n ) {
			return 0.0;
		}
		$dot = 0.0;
		$nb  = 0.0;
		if ( null === $a_sq ) {
			$a_sq = 0.0;
			for ( $i = 0; $i < $n; $i++ ) {
				$va   = (float) $a[ $i ];
				$a_sq += $va * $va;
			}
		}
		$a_sq = (float) $a_sq;
		for ( $i = 0; $i < $n; $i++ ) {
			$vb  = (float) $b[ $i ];
			$dot += (float) $a[ $i ] * $vb;
			$nb  += $vb * $vb;
		}
		if ( $a_sq <= 0.0 || $nb <= 0.0 ) {
			return 0.0;
		}
		return (float) ( $dot / ( sqrt( $a_sq ) * sqrt( $nb ) ) );
	}

	/**
	 * Persist a vector for a chunk.
	 *
	 * @param int    $chunk_id Chunk id.
	 * @param int    $post_id  Post id.
	 * @param string $model    Model id.
	 * @param array  $vector   Numbers.
	 */
	public static function save_vector( $chunk_id, $post_id, $model, $vector ) {
		global $wpdb;
		if ( ! $vector ) {
			return;
		}
		$table = Haa_DB::table( 'vectors' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->replace(
			$table,
			array(
				'chunk_id'  => (int) $chunk_id,
				'post_id'   => (int) $post_id,
				'model'     => substr( (string) $model, 0, 80 ),
				'dim'       => count( $vector ),
				'vector'    => self::encode_vector( $vector ),
				'created'   => current_time( 'mysql', true ),
			),
			array( '%d', '%d', '%s', '%d', '%s', '%s' )
		);
	}

	/**
	 * Embedding model actually usable right now, or '' when unavailable
	 * (module off, embeddings off, no adapter, or no model configured).
	 *
	 * @return string
	 */
	public static function vectors_model() {
		if ( ! Haa_Settings::is_module_enabled( 'semantic-search' ) ) {
			return '';
		}
		if ( ! Haa_Settings::get( 'search.embeddings' ) ) {
			return '';
		}
		if ( 'claude' === (string) Haa_Settings::get( 'provider.adapter', 'openai' ) ) {
			return ''; // Anthropic has no embedding model.
		}
		return (string) Haa_Settings::embedding_model();
	}

	/**
	 * Chunk ids that still need a vector for the current model.
	 *
	 * @param int $limit Max ids.
	 * @return array<int,int>
	 */
	public static function vectors_pending_ids( $limit = 50 ) {
		global $wpdb;
		$model = self::vectors_model();
		if ( '' === $model ) {
			return array();
		}
		$chunks = Haa_DB::table( 'chunks' );
		$vectors = Haa_DB::table( 'vectors' );
		$limit  = max( 1, min( 500, (int) $limit ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT c.id FROM $chunks c LEFT JOIN $vectors v ON v.chunk_id = c.id AND v.model = %s WHERE v.chunk_id IS NULL ORDER BY c.id ASC LIMIT %d",
				$model,
				$limit
			)
		);
		return array_map( 'intval', (array) $rows );
	}

	/**
	 * Embed a batch of chunks and persist the vectors via save_vector().
	 *
	 * @param array<int,int> $chunk_ids Chunk ids.
	 * @return int|WP_Error Vectors written, or the API error.
	 */
	public static function embed_chunk_ids( $chunk_ids ) {
		global $wpdb;
		$chunk_ids = array_filter( array_map( 'intval', (array) $chunk_ids ) );
		if ( ! $chunk_ids ) {
			return 0;
		}
		$model = self::vectors_model();
		if ( '' === $model ) {
			return 0;
		}

		$chunks  = Haa_DB::table( 'chunks' );
		$in      = implode( ',', array_fill( 0, count( $chunk_ids ), '%d' ) );
		// phpcs:ignore WordPress.DB.PreparedSQL,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id, post_id, content FROM $chunks WHERE id IN ($in)", $chunk_ids ), ARRAY_A );
		if ( ! $rows ) {
			return 0;
		}

		$texts = array();
		$ids   = array();
		foreach ( $rows as $row ) {
			$ids[]   = (int) $row['id'];
			$texts[] = (string) $row['content'];
		}

		$api  = new Haa_API();
		$res  = $api->embed( $texts, array( 'model' => $model ) );
		if ( is_wp_error( $res ) ) {
			return $res;
		}

		$saved = 0;
		foreach ( $res as $i => $vec ) {
			if ( empty( $vec ) || ! isset( $ids[ $i ] ) ) {
				continue;
			}
			self::save_vector( $ids[ $i ], (int) $rows[ $i ]['post_id'], $model, $vec );
			$saved++;
		}
		return $saved;
	}

	/**
	 * Embed every pending chunk for one post right after it was (re)indexed.
	 * This is the incremental path used on save_post — a single post is a
	 * handful of chunks, so it finishes inside one request.
	 *
	 * @param int $post_id Post id.
	 * @return int Vectors written.
	 */
	public static function embed_post_now( $post_id ) {
		global $wpdb;
		if ( '' === self::vectors_model() ) {
			return 0;
		}
		$chunks = Haa_DB::table( 'chunks' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$ids = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT id FROM $chunks WHERE post_id = %d", (int) $post_id ) ) );
		if ( ! $ids ) {
			return 0;
		}
		$res = self::embed_chunk_ids( $ids );
		return is_wp_error( $res ) ? 0 : (int) $res;
	}

	/* ==================================================================
	 * Rebuild (resumable, batched)
	 * =============================================================== */

	/** @return array{running:int,offset:int,total:int,indexed:int,phase:string,last_error:string,capped:int,notice:string} */
	public static function get_state() {
		$state = get_option( self::STATE_OPTION, array() );
		return wp_parse_args(
			is_array( $state ) ? $state : array(),
			array(
				'running'    => 0,
				'offset'     => 0,
				'total'      => 0,
				'indexed'    => 0,
				'phase'      => 'chunks', // chunks | vectors.
				'last_error' => '',
				'capped'     => 0, // 1 = stopped because search.max_vectors was reached.
				'notice'     => '', // Human-readable reason for the stop above.
			)
		);
	}

	/**
	 * @param array $state State.
	 */
	private static function save_state( $state ) {
		update_option( self::STATE_OPTION, $state, 'no' );
	}

	/** @return array */
	public static function rebuild_start() {
		$ids = self::all_source_ids();
		self::save_state(
			array(
				'running' => 1,
				'offset'  => 0,
				'total'   => count( $ids ),
				'indexed' => 0,
				'ids'     => $ids,
				'phase'   => 'chunks',
				'capped'  => 0,
				'notice'  => '',
			)
		);
		return self::rebuild_progress();
	}

	/**
	 * Index the next batch. Bails early on a time budget so a 30 s host limit
	 * is never hit; the cursor survives so the next tick continues.
	 *
	 * Two phases share one cursor:
	 *   - chunks:  build text chunks from posts (always runs)
	 *   - vectors: embed those chunks (only when embeddings are enabled)
	 * When the chunks phase ends and embeddings are available, the state
	 * flips to the vectors phase automatically instead of finishing.
	 *
	 * @param int $batch_size Items per call.
	 * @return array
	 */
	public static function rebuild_batch( $batch_size = 15 ) {
		$started = microtime( true );
		$state   = self::get_state();
		$ids     = isset( $state['ids'] ) && is_array( $state['ids'] ) ? $state['ids'] : array();

		if ( empty( $state['running'] ) || ! $ids ) {
			return self::rebuild_progress();
		}

		$batch_size = max( 1, min( 20, (int) $batch_size ) );
		$processed  = 0;

		if ( 'vectors' === ( $state['phase'] ?? 'chunks' ) ) {
			// ---- Phase 2: embed the pending chunks. ----
			while ( $state['offset'] < count( $ids ) ) {
				$slice   = array_slice( $ids, (int) $state['offset'], $batch_size );
				$res     = self::embed_chunk_ids( $slice );
				$errored = is_wp_error( $res );

				$state['offset'] += count( $slice );
				$state['indexed'] += count( $slice );
				$processed        += count( $slice );

				if ( $errored ) {
					// Stop the vector pass; BM25 stays fully functional.
					$state['running']    = 0;
					$state['last_error'] = $res->get_error_message();
					break;
				}
				if ( $processed >= $batch_size || ( microtime( true ) - $started ) > 12 ) {
					break;
				}
			}
			if ( $state['offset'] >= count( $ids ) ) {
				self::purge_orphans();
				Haa_DB::flush_cache();

				// The vector slice can be shorter than the total backlog when
				// search.max_vectors caps it. Never report a silent "100%"
				// in that case: stop and say exactly why.
				$state['running'] = 0;
				$leftover         = self::vectors_pending_ids( 1 );
				$state['capped']  = $leftover ? 1 : 0;
				$state['notice']  = $leftover
					? __( 'به سقف «حداکثر تعداد بردار» رسیدید؛ بخشی از تکه‌ها هنوز بردار ندارند. مقدار سقف را بیشتر کنید و دوباره ادامه دهید تا جست‌وجوی معنایی کامل شود.', 'hami-ai-assistant' )
					: '';
			}
			self::save_state( $state );
			return self::rebuild_progress();
		}

		// ---- Phase 1: build chunks. ----
		while ( $state['offset'] < count( $ids ) ) {
			$post_id = (int) $ids[ $state['offset'] ];
			self::index_post( $post_id );

			$state['offset']++;
			$state['indexed']++;
			$processed++;

			if ( $processed >= $batch_size || ( microtime( true ) - $started ) > 12 ) {
				break;
			}
		}

		if ( $state['offset'] >= count( $ids ) ) {
			self::purge_orphans();

			// Hand over to the vector phase when embeddings are wanted and
			// usable; otherwise the rebuild is done (BM25-only index).
			$pending = self::vectors_pending_ids( max( 1, (int) Haa_Settings::get( 'search.max_vectors', 3000 ) ) );
			if ( $pending ) {
				$state['phase']   = 'vectors';
				$state['ids']     = $pending;
				$state['total']   = count( $pending );
				$state['offset']  = 0;
				$state['indexed'] = 0;
				$state['capped']  = 0;
				$state['notice']  = '';
			} else {
				$state['running'] = 0;
				Haa_DB::flush_cache();
			}
		}

		self::save_state( $state );
		return self::rebuild_progress();
	}

	/** @return void */
	public static function reset() {
		global $wpdb;
		$chunks = Haa_DB::table( 'chunks' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->query( "TRUNCATE TABLE $chunks" ); // phpcs:ignore WordPress.DB
		if ( Haa_Settings::is_module_enabled( 'semantic-search' ) ) {
			$vectors = Haa_DB::table( 'vectors' );
			// phpcs:ignore WordPress.DB
			$wpdb->query( "TRUNCATE TABLE $vectors" );
		}
		self::rebuild_cancel();
		Haa_DB::flush_cache();
	}

	/** @return array */
	public static function rebuild_cancel() {
		$state = self::get_state();
		$state['running']    = 0;
		$state['ids']        = array();
		$state['phase']      = 'chunks';
		$state['last_error'] = '';
		$state['capped']     = 0;
		$state['notice']     = '';
		self::save_state( $state );
		return self::rebuild_progress();
	}

	/** @return array */
	public static function rebuild_progress() {
		$state = self::get_state();
		$total = max( 1, (int) $state['total'] );
		return array(
			'running'    => (int) $state['running'],
			'done'       => (int) $state['offset'] >= (int) $state['total'] && ! $state['running'],
			'offset'     => (int) $state['offset'],
			'total'      => (int) $state['total'],
			'indexed'    => (int) $state['indexed'],
			'percent'    => (int) min( 100, round( ( (int) $state['offset'] / $total ) * 100 ) ),
			'phase'      => (string) $state['phase'],
			'last_error' => (string) $state['last_error'],
			'capped'     => (int) $state['capped'],
			'notice'     => (string) $state['notice'],
			'stats'      => self::stats(),
		);
	}

	/** @return array{chunks:int,items:int,vectors:int} */
	public static function stats() {
		global $wpdb;
		$table = Haa_DB::table( 'chunks' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$row = $wpdb->get_row( "SELECT COUNT(*) AS chunks, COUNT(DISTINCT post_id) AS items FROM $table", ARRAY_A );

		$vectors = 0;
		if ( Haa_Settings::is_module_enabled( 'semantic-search' ) ) {
			$vt = Haa_DB::table( 'vectors' );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$vectors = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $vt" );
		}

		return array(
			'chunks'  => $row ? (int) $row['chunks'] : 0,
			'items'   => $row ? (int) $row['items'] : 0,
			'vectors' => $vectors,
		);
	}

	/** @return array<int,int> */
	public static function all_source_ids() {
		$cfg = self::config();
		$q   = new WP_Query(
			array(
				'post_type'      => $cfg['types'],
				'post_status'    => 'publish',
				'fields'         => 'ids',
				'posts_per_page' => -1,
				'orderby'        => 'ID',
				'order'          => 'ASC',
				'no_found_rows'  => true,
				'suppress_filters' => true,
			)
		);
		$ids = array_map( 'intval', $q->posts );

		// Index readable documents too.
		$files = get_posts(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'meta_query'     => array(
					'relation' => 'OR',
					array(
						'key'     => '_wp_attachment_mime_type',
						'value'   => 'text/plain',
						'compare' => '=',
					),
					array(
						'key'     => '_wp_attachment_mime_type',
						'value'   => 'wordprocessingml.document',
						'compare' => 'LIKE',
					),
				),
				'suppress_filters' => true,
			)
		);
		foreach ( $files as $fid ) {
			if ( in_array( self::file_ext( (int) $fid ), self::ALLOWED_EXT, true ) ) {
				$ids[] = (int) $fid;
			}
		}

		return array_values( array_unique( $ids ) );
	}

	/** @return void */
	public static function purge_orphans() {
		global $wpdb;
		$chunks = Haa_DB::table( 'chunks' );
		// phpcs:ignore WordPress.DB
		$wpdb->query( "DELETE c FROM $chunks c LEFT JOIN {$wpdb->posts} p ON p.ID = c.post_id WHERE p.ID IS NULL" );

		if ( Haa_Settings::is_module_enabled( 'semantic-search' ) ) {
			$vectors = Haa_DB::table( 'vectors' );
			// phpcs:ignore WordPress.DB
			$wpdb->query( "DELETE v FROM $vectors v LEFT JOIN $chunks c ON c.id = v.chunk_id WHERE c.id IS NULL" );
		}
	}
}
