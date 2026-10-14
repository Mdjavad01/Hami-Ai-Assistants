<?php
/**
 * Image generation: prompt, provider call, attachment.
 *
 * Every image is stored as a real media attachment (not a URL blob) so the
 * site keeps working if the provider changes, and so the normal WP image
 * pipeline (srcset, thumbnails, WebP plugins) applies.
 *
 * @package Hami_AI_Assistant
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Haa_Images {

	/**
	 * Generate one image and attach it to a post.
	 *
	 * @param string $prompt    What to draw.
	 * @param array  $opts      size, quality, module, post_id.
	 * @return array{ok:bool,error:?string,attachment_id:int,url:string}
	 */
	public static function generate( $prompt, $opts = array() ) {
		$opts = wp_parse_args(
			$opts,
			array(
				'size'     => Haa_Settings::get( 'content.image_size', '1024x1024' ),
				'quality'  => Haa_Settings::get( 'content.image_quality', 'standard' ),
				'module'   => 'content',
				'post_id'  => 0,
				'model'    => Haa_Settings::image_model(),
			)
		);

		$prompt = trim( (string) $prompt );
		if ( '' === $prompt ) {
			return self::fail( __( 'پرامپت تصویر خالی است.', 'hami-ai-assistant' ) );
		}

		// The admin can append a standing style sentence so a whole catalogue
		// looks consistent without repeating it in every prompt.
		$style = trim( (string) Haa_Settings::get( 'content.image_style', '' ) );
		if ( '' !== $style ) {
			$prompt .= '. ' . $style;
		}

		$api  = new Haa_API();
		$resp = $api->image(
			$prompt,
			array(
				'size'    => $opts['size'],
				'quality' => $opts['quality'],
				'model'   => $opts['model'],
				'module'  => $opts['module'],
			)
		);

		if ( is_wp_error( $resp ) ) {
			return self::fail( $resp->get_error_message() );
		}

		$resp = trim( (string) $resp );
		if ( '' === $resp ) {
			return self::fail( __( 'سرویس تصویر چیزی برنگرداند.', 'hami-ai-assistant' ) );
		}

		// The adapter returns either a remote URL or a data: URI.
		$b64  = '';
		$url  = '';
		$mime = 'image/png';
		if ( 0 === strpos( $resp, 'data:' ) ) {
			if ( ! preg_match( '#^data:([\w/+.-]+);base64,(.+)$#s', $resp, $m ) ) {
				return self::fail( __( 'پاسخ تصویر قابل خواندن نبود.', 'hami-ai-assistant' ) );
			}
			$mime = $m[1];
			$b64  = $m[2];
		} else {
			$url = $resp;
		}

		$attachment_id = self::store_attachment( $prompt, $b64, $url, $mime, (int) $opts['post_id'] );
		if ( is_wp_error( $attachment_id ) ) {
			return self::fail( $attachment_id->get_error_message() );
		}

		return array(
			'ok'            => true,
			'error'         => null,
			'attachment_id' => $attachment_id,
			'url'           => (string) wp_get_attachment_url( $attachment_id ),
		);
	}

	/**
	 * Turn the provider response into a media-library attachment.
	 *
	 * A remote URL is downloaded (and the temporary file removed); a base64
	 * payload is written straight to disk. Both paths end in
	 * `wp_insert_attachment`, so EXIF/orientation and every registered
	 * thumbnail size are handled by WordPress.
	 *
	 * @param string $prompt  Alt text source.
	 * @param string $b64     Raw base64 (no data: prefix).
	 * @param string $url     Remote URL when the provider returned one.
	 * @param string $mime    Mime type.
	 * @param int    $post_id Parent post.
	 * @return int|WP_Error Attachment id.
	 */
	private static function store_attachment( $prompt, $b64, $url, $mime, $post_id ) {
		require_once ABSPATH . 'wp-admin/includes/image.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';

		$ext = 'image/jpeg' === $mime ? 'jpg' : ( 'image/webp' === $mime ? 'webp' : 'png' );

		if ( '' !== $b64 ) {
			$bin = base64_decode( $b64, true );
			if ( false === $bin ) {
				return new WP_Error( 'haai_image_decode', __( 'پاسخ تصویر قابل خواندن نبود.', 'hami-ai-assistant' ) );
			}
			$tmp = wp_tempnam( 'haai-image.' . $ext );
			if ( ! $tmp ) {
				return new WP_Error( 'haai_image_temp', __( 'امکان نوشتن فایل موقت نیست.', 'hami-ai-assistant' ) );
			}
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			if ( false === file_put_contents( $tmp, $bin ) ) {
				@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
				return new WP_Error( 'haai_image_temp', __( 'امکان نوشتن فایل موقت نیست.', 'hami-ai-assistant' ) );
			}
		} else {
			$tmp = download_url( $url, 60 );
			if ( is_wp_error( $tmp ) ) {
				return $tmp;
			}
		}

		$filename = Haa_Util::slugify( Haa_Util::cut( wp_strip_all_tags( $prompt ), 60 ) );
		$filename = ( '' !== $filename ? $filename : 'haai-image' ) . '-' . gmdate( 'Ymd-His' ) . '.' . $ext;

		// Move the temp file into the uploads tree FIRST and read back its
		// final path: wp_insert_attachment() never copies files, and unlinking
		// the temp afterwards used to leave a broken, parentless attachment.
		$uploaded = wp_handle_sideload(
			array(
				'name'     => $filename,
				'tmp_name' => $tmp,
			),
			array( 'test_form' => false )
		);

		if ( isset( $uploaded['error'] ) ) {
			if ( is_file( $tmp ) ) {
				@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			}
			return new WP_Error( 'haai_image_sideload', (string) $uploaded['error'] );
		}

		$attachment = array(
			'post_mime_type' => $mime,
			'guid'           => $uploaded['url'],
			'post_title'     => Haa_Util::cut( wp_strip_all_tags( $prompt ), 160 ),
			'post_content'   => '',
			'post_status'    => 'inherit',
			'post_parent'    => $post_id,
		);

		$attach_id = wp_insert_attachment( $attachment, $uploaded['file'], $post_id, true );
		if ( is_wp_error( $attach_id ) ) {
			wp_delete_file( $uploaded['file'] );
			return $attach_id;
		}

		$meta = wp_generate_attachment_metadata( $attach_id, $uploaded['file'] );
		if ( $meta ) {
			wp_update_attachment_metadata( $attach_id, $meta );
		}

		// Update the alt text so the image is not blank for screen readers.
		update_post_meta( $attach_id, '_wp_attachment_image_alt', Haa_Util::cut( wp_strip_all_tags( $prompt ), 250 ) );

		return (int) $attach_id;
	}

	/**
	 * @param string $message Error.
	 * @return array
	 */
	private static function fail( $message ) {
		return array(
			'ok'            => false,
			'error'         => $message,
			'attachment_id' => 0,
			'url'           => '',
		);
	}

	/**
	 * Prompt used when the caller has nothing better: product name + category
	 * is enough for a usable catalogue shot.
	 *
	 * @param string $name     Product name.
	 * @param string $category Category.
	 * @return string
	 */
	public static function product_prompt( $name, $category = '' ) {
		$parts = array(
			sprintf( 'عکس حرفه‌ای محصول «%s»', $name ),
		);
		if ( '' !== trim( $category ) ) {
			$parts[] = sprintf( 'دسته‌بندی: %s', $category );
		}
		$parts[] = __( 'نور طبیعی، پس‌زمینه ساده، بدون متن و بدون لوگوی برندهای دیگر', 'hami-ai-assistant' );
		return implode( '، ', $parts );
	}
}
