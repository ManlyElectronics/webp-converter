<?php
/**
 * WebP conversion engine.
 *
 * Handles converting image files to WebP and updating WordPress metadata.
 *
 * @package ManlyWebpConverter
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Manly_WebP_Converter
 *
 * Core conversion logic for transforming images to WebP format.
 *
 * @since 1.0.0
 */
class Manly_WebP_Converter {

	/**
	 * WebP quality setting (0-100).
	 *
	 * @since 1.0.0
	 * @var int
	 */
	private $quality;

	/**
	 * Whether to keep original files after conversion.
	 *
	 * @since 1.0.0
	 * @var bool
	 */
	private $keep_originals;

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 * @param int  $quality        WebP quality 0-100.
	 * @param bool $keep_originals Whether to keep original files.
	 */
	public function __construct( $quality = 80, $keep_originals = true ) {
		$this->quality        = absint( $quality );
		$this->keep_originals = (bool) $keep_originals;
	}

	/**
	 * Get attachment IDs for a specific product.
	 *
	 * @since 1.0.0
	 * @param int $product_id WooCommerce product ID.
	 * @return array Array of attachment IDs.
	 */
	public function get_product_attachment_ids( $product_id ) {
		$ids = array();

		$thumbnail_id = get_post_meta( $product_id, '_thumbnail_id', true );
		if ( $thumbnail_id ) {
			$ids[] = absint( $thumbnail_id );
		}

		$gallery = get_post_meta( $product_id, '_product_image_gallery', true );
		if ( $gallery ) {
			$gallery_ids = array_map( 'absint', explode( ',', $gallery ) );
			$ids         = array_merge( $ids, $gallery_ids );
		}

		return array_unique( array_filter( $ids ) );
	}

	/**
	 * Get non-WebP attachment IDs referenced by a post, page, or product.
	 *
	 * Checks featured image, WooCommerce product gallery, wp-image-{id} classes
	 * in post content, and falls back to resolving <img src> URLs for images that
	 * were inserted without a class (classic editor, manually edited HTML, etc.).
	 *
	 * @since 1.0.0
	 * @param int $post_id Post/page/product ID.
	 * @return array Non-WebP attachment IDs.
	 */
	public function get_post_attachment_ids( $post_id ) {
		$post_id   = absint( $post_id );
		$ids       = array();
		$stale_ids = array(); // WebP in DB but old URL still referenced in content.

		// Featured image.
		$thumb = get_post_thumbnail_id( $post_id );
		if ( $thumb ) {
			$ids[] = absint( $thumb );
		}

		// WooCommerce product gallery.
		$gallery = get_post_meta( $post_id, '_product_image_gallery', true );
		if ( $gallery ) {
			$ids = array_merge( $ids, array_map( 'absint', explode( ',', $gallery ) ) );
		}

		$post = get_post( $post_id );
		if ( $post && ! empty( $post->post_content ) ) {
			$content = $post->post_content;

			// Primary: block editor writes class="wp-image-{id}" on every <img>.
			// Match the whole <img> tag so we can also inspect the src extension
			// to detect stale URLs (post content still references old .png/.jpg
			// even though the attachment was already converted to .webp).
			if ( preg_match_all( '/<img\b[^>]*class=["\'][^"\']*wp-image-(\d+)[^"\']*["\'][^>]*>/i', $content, $img_matches, PREG_SET_ORDER ) ) {
				foreach ( $img_matches as $m ) {
					$aid = absint( $m[1] );
					if ( ! $aid ) {
						continue;
					}
					$src_match = '';
					if ( preg_match( '/\bsrc=["\']([^"\']+)["\']/i', $m[0], $sm ) ) {
						$src_match = $sm[1];
					}
					if ( $src_match
						&& preg_match( '/\.(?:jpe?g|png|gif)(?:\?.*)?$/i', $src_match )
						&& 'image/webp' === get_post_mime_type( $aid ) ) {
						$stale_ids[] = $aid;
					} else {
						$ids[] = $aid;
					}
				}
			}

			// Fallback: resolve <img src> URLs for images that lack the class
			// (classic editor inserts, manually pasted HTML, some third-party blocks).
			if ( preg_match_all( '/<img[^>]+src=["\']([^"\']+)["\']/', $content, $src_matches ) ) {
				$upload_dir  = wp_upload_dir();
				$uploads_url = trailingslashit( $upload_dir['baseurl'] );

				foreach ( $src_matches[1] as $src ) {
					// Only process URLs that point to our own uploads directory.
					if ( false === strpos( $src, $uploads_url ) ) {
						continue;
					}
					$attachment_id = attachment_url_to_postid( $src );
					if ( ! $attachment_id ) {
						// Strip size suffix (e.g. -300x225) and try the full-size URL.
						$clean = preg_replace( '/-\d+x\d+(\.[a-z]+)$/i', '$1', $src );
						if ( $clean !== $src ) {
							$attachment_id = attachment_url_to_postid( $clean );
						}
					}
					if ( $attachment_id ) {
						$aid = absint( $attachment_id );
						// If the URL has a non-WebP extension but the attachment is now
						// stored as WebP, the content reference was never updated.
						// Track these separately so the page still shows up.
						if ( preg_match( '/\.(?:jpe?g|png|gif)(\?.*)?$/i', $src )
							&& 'image/webp' === get_post_mime_type( $aid ) ) {
							$stale_ids[] = $aid;
						} else {
							$ids[] = $aid;
						}
					}
				}
			}
		}

		$ids       = array_unique( array_filter( $ids ) );
		$stale_ids = array_unique( array_filter( $stale_ids ) );

		// Keep only non-WebP attachments from direct lookups.
		$converted = array_values(
			array_filter(
				$ids,
				function ( $id ) {
					$mime = get_post_mime_type( $id );
					return in_array( $mime, array( 'image/jpeg', 'image/png', 'image/gif' ), true );
				}
			)
		);

		// Also include stale-URL attachments (content URL not updated after conversion).
		return array_values( array_unique( array_merge( $converted, $stale_ids ) ) );
	}

	/**
	 * Get recent posts/pages/products that still have non-WebP images.
	 *
	 * @since 1.0.0
	 * @param int $limit Maximum number of results to return.
	 * @return array Each item: ['id', 'title', 'type', 'count'].
	 */
	public function get_recent_posts_with_non_webp( $limit = 10 ) {
		$post_types = array( 'post', 'page' );
		if ( class_exists( 'WooCommerce' ) ) {
			$post_types[] = 'product';
		}

		$results      = array();
		$batch_size   = 50;
		$max_scanned  = 2000; // Safety cap: stop scanning after this many posts.
		$scanned      = 0;
		$paged        = 1;
		$result_count = 0;

		while ( $result_count < $limit && $scanned < $max_scanned ) {
			$query = new WP_Query(
				array(
					'post_type'      => $post_types,
					'post_status'    => array( 'publish', 'draft', 'private' ),
					'posts_per_page' => $batch_size,
					'paged'          => $paged,
					'orderby'        => 'date',
					'order'          => 'DESC',
					'fields'         => 'ids',
					'no_found_rows'  => true,
					'cache_results'  => false,
				)
			);

			if ( empty( $query->posts ) ) {
				break;
			}

			foreach ( $query->posts as $pid ) {
				++$scanned;
				$attachment_ids = $this->get_post_attachment_ids( $pid );
				if ( empty( $attachment_ids ) ) {
					continue;
				}
				$post         = get_post( $pid );
				$results[]    = array(
					'id'    => $pid,
					'title' => get_the_title( $pid ),
					'type'  => $post ? $post->post_type : '',
					'count' => count( $attachment_ids ),
				);
				$result_count = count( $results );
				if ( $result_count >= $limit ) {
					break 2;
				}
			}

			++$paged;
		}

		return $results;
	}

	/**
	 * Get all non-WebP image attachment IDs in the media library.
	 *
	 * @since 1.0.0
	 * @return array Array of attachment IDs.
	 */
	public function get_all_image_attachment_ids() {
		$ids = get_posts(
			array(
				'post_type'      => 'attachment',
				'post_mime_type' => array( 'image/jpeg', 'image/png', 'image/gif' ),
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'orderby'        => 'ID',
				'order'          => 'ASC',
				'post_status'    => 'any',
			)
		);

		return array_map( 'absint', $ids );
	}

	/**
	 * Convert a single attachment and all its sizes to WebP.
	 *
	 * @since 1.0.0
	 * @param int $attachment_id  WordPress attachment ID.
	 * @param int $hint_post_id   Optional. Post/page/product ID being converted in
	 *                            context of — used as a guaranteed lookup target in
	 *                            update_content_references() so we never miss the
	 *                            page the user actually selected in the UI.
	 * @return array Result with 'success', 'message', 'files_converted', 'bytes_saved'.
	 */
	public function convert_attachment( $attachment_id, $hint_post_id = 0 ) {
		$hint_post_id  = absint( $hint_post_id );
		$attachment_id = absint( $attachment_id );

		// Prevent concurrent processing of the same attachment.
		$lock_key = 'manly_webp_converter_lock_' . $attachment_id;
		if ( get_transient( $lock_key ) ) {
			return $this->result( false, 'Attachment is already being processed.' );
		}
		set_transient( $lock_key, true, 120 );

		$post = get_post( $attachment_id );

		if ( ! $post || 'attachment' !== $post->post_type ) {
			delete_transient( $lock_key );
			return $this->result( false, 'Invalid attachment ID.' );
		}

		$mime = $post->post_mime_type;

		// Attachment already converted but content may still have the old URL.
		// Re-run the content reference update using derived old→new URL mapping.
		if ( 'image/webp' === $mime && $hint_post_id > 0 ) {
			$meta = wp_get_attachment_metadata( $attachment_id );
			if ( $meta && ! empty( $meta['file'] ) ) {
				$ud       = wp_upload_dir();
				$base_url = trailingslashit( $ud['baseurl'] );
				$file_dir = trailingslashit( dirname( $meta['file'] ) );
				$refs     = array();
				foreach ( array( 'jpg', 'jpeg', 'png', 'gif' ) as $ext ) {
					$old_main = preg_replace( '/\.webp$/i', '.' . $ext, $meta['file'] );
					if ( $old_main !== $meta['file'] ) {
						$refs[ $base_url . $old_main ] = $base_url . $meta['file'];
					}
					if ( ! empty( $meta['sizes'] ) ) {
						foreach ( $meta['sizes'] as $size ) {
							$old_size = preg_replace( '/\.webp$/i', '.' . $ext, $size['file'] );
							if ( $old_size !== $size['file'] ) {
								$refs[ $base_url . $file_dir . $old_size ] = $base_url . $file_dir . $size['file'];
							}
						}
					}
				}
				if ( ! empty( $refs ) ) {
					$this->update_content_references( $refs, $attachment_id, $hint_post_id );
				}
			}
			delete_transient( $lock_key );
			return $this->result( true, 'Content references updated (was already WebP).', 0, 0 );
		}

		if ( ! in_array( $mime, array( 'image/jpeg', 'image/png', 'image/gif' ), true ) ) {
			delete_transient( $lock_key );
			return $this->result( false, 'Already WebP or unsupported format: ' . $mime );
		}

		$metadata = wp_get_attachment_metadata( $attachment_id );
		if ( ! $metadata || empty( $metadata['file'] ) ) {
			delete_transient( $lock_key );
			return $this->result( false, 'No metadata found for attachment.' );
		}

		$upload_dir = wp_upload_dir();
		$base_dir   = trailingslashit( $upload_dir['basedir'] );
		$base_url   = trailingslashit( $upload_dir['baseurl'] );
		$file_dir   = trailingslashit( dirname( $metadata['file'] ) );
		$full_path  = $base_dir . $metadata['file'];

		if ( ! file_exists( $full_path ) ) {
			delete_transient( $lock_key );
			return $this->result( false, 'Original file not found: ' . $metadata['file'] );
		}

		// Check disk space: need at least the original file size free.
		$disk_free = disk_free_space( $base_dir );
		if ( false !== $disk_free && $disk_free < filesize( $full_path ) * 2 ) {
			delete_transient( $lock_key );
			return $this->result( false, 'Insufficient disk space (' . size_format( $disk_free ) . ' free).' );
		}

		// Check memory: GD needs ~5 bytes per pixel for truecolor + alpha.
		$memory_check = $this->check_memory_for_image( $full_path );
		if ( ! $memory_check['ok'] ) {
			delete_transient( $lock_key );
			return $this->result( false, $memory_check['message'] );
		}

		$files_converted  = 0;
		$bytes_saved      = 0;
		$old_files        = array();
		$url_replacements = array();

		// Convert the full-size original.
		$main_result = $this->convert_file( $full_path, $mime );
		if ( ! $main_result['success'] ) {
			delete_transient( $lock_key );
			return $this->result( false, 'Failed to convert main file: ' . $main_result['message'] );
		}

		$old_main_file = $metadata['file'];
		$new_main_file = $this->swap_extension( $metadata['file'], 'webp' );

		$old_files[] = $full_path;
		++$files_converted;
		$bytes_saved += $main_result['bytes_saved'];

		// Build URL replacement for main file.
		$old_url                      = $base_url . $old_main_file;
		$new_url                      = $base_url . $new_main_file;
		$url_replacements[ $old_url ] = $new_url;

		// Update metadata for main file.
		$metadata['file'] = $new_main_file;

		// Convert each registered size.
		if ( ! empty( $metadata['sizes'] ) && is_array( $metadata['sizes'] ) ) {
			foreach ( $metadata['sizes'] as $size_name => &$size_data ) {
				$size_path = $base_dir . $file_dir . $size_data['file'];

				if ( ! file_exists( $size_path ) ) {
					continue;
				}

				$size_result = $this->convert_file( $size_path, $mime );
				if ( $size_result['success'] ) {
					$old_size_url  = $base_url . $file_dir . $size_data['file'];
					$new_size_file = $this->swap_extension( $size_data['file'], 'webp' );
					$new_size_url  = $base_url . $file_dir . $new_size_file;

					$url_replacements[ $old_size_url ] = $new_size_url;
					$old_files[]                       = $size_path;

					$size_data['file']      = $new_size_file;
					$size_data['mime-type'] = 'image/webp';

					++$files_converted;
					$bytes_saved += $size_result['bytes_saved'];
				}
			}
			unset( $size_data );
		}

		// Update attachment metadata.
		$meta_updated = wp_update_attachment_metadata( $attachment_id, $metadata );

		if ( false === $meta_updated ) {
			// Rollback: delete created WebP files.
			$this->rollback_webp_files( $old_files );
			delete_transient( $lock_key );
			return $this->result( false, 'Failed to update attachment metadata. Rolled back.' );
		}

		// Update _wp_attached_file.
		update_post_meta( $attachment_id, '_wp_attached_file', $new_main_file );

		// Update the attachment post record.
		// Preserve or generate a title from the parent product name + MPN.
		$title = $post->post_title;
		if ( empty( $title ) ) {
			$title = $this->generate_attachment_title( $attachment_id, $old_main_file );
		}

		$post_updated = wp_update_post(
			array(
				'ID'             => $attachment_id,
				'post_title'     => $title,
				'post_mime_type' => 'image/webp',
			)
		);

		if ( is_wp_error( $post_updated ) ) {
			// Rollback: restore original metadata.
			wp_update_attachment_metadata( $attachment_id, wp_get_attachment_metadata( $attachment_id ) );
			update_post_meta( $attachment_id, '_wp_attached_file', $old_main_file );
			$this->rollback_webp_files( $old_files );
			delete_transient( $lock_key );
			return $this->result( false, 'Failed to update attachment post. Rolled back.' );
		}

		// Update content references to old image URLs.
		$this->update_content_references( $url_replacements, $attachment_id, $hint_post_id );

		// Delete originals if not keeping them.
		if ( ! $this->keep_originals ) {
			foreach ( $old_files as $old_file ) {
				if ( file_exists( $old_file ) ) {
					wp_delete_file( $old_file );
				}
			}
		}

		delete_transient( $lock_key );

		return $this->result(
			true,
			sprintf( 'Converted %d files, saved %s.', $files_converted, size_format( $bytes_saved ) ),
			$files_converted,
			$bytes_saved
		);
	}

	/**
	 * Convert a single image file to WebP.
	 *
	 * @since 1.0.0
	 * @param string $file_path Absolute path to the image file.
	 * @param string $mime      MIME type of the source image.
	 * @return array Result with 'success', 'message', 'bytes_saved'.
	 */
	private function convert_file( $file_path, $mime ) {
		$original_size = filesize( $file_path );
		$webp_path     = $this->swap_extension( $file_path, 'webp' );

		switch ( $mime ) {
			case 'image/jpeg':
				$image = imagecreatefromjpeg( $file_path );
				break;
			case 'image/png':
				$image = imagecreatefrompng( $file_path );
				break;
			case 'image/gif':
				$image = imagecreatefromgif( $file_path );
				break;
			default:
				return array(
					'success'     => false,
					'message'     => 'Unsupported mime type.',
					'bytes_saved' => 0,
				);
		}

		if ( ! $image ) {
			return array(
				'success'     => false,
				'message'     => 'Failed to load image.',
				'bytes_saved' => 0,
			);
		}

		// Preserve transparency for PNG/GIF.
		if ( 'image/png' === $mime || 'image/gif' === $mime ) {
			imagepalettetotruecolor( $image );
			imagealphablending( $image, true );
			imagesavealpha( $image, true );
		}

		$converted = imagewebp( $image, $webp_path, $this->quality );
		unset( $image );

		if ( ! $converted || ! file_exists( $webp_path ) ) {
			return array(
				'success'     => false,
				'message'     => 'imagewebp() failed.',
				'bytes_saved' => 0,
			);
		}

		$new_size    = filesize( $webp_path );
		$bytes_saved = $original_size - $new_size;

		return array(
			'success'     => true,
			'message'     => 'OK',
			'bytes_saved' => $bytes_saved,
		);
	}

	/**
	 * Update image URL references in post content and excerpts.
	 *
	 * Uses direct WP relationships to find posts — avoids WP_Query URL/path
	 * searches which are unreliable for long hyphenated filenames.
	 *
	 * @since 1.0.0
	 * @param array $replacements   Associative array of old_url => new_url.
	 * @param int   $attachment_id  The attachment that was converted.
	 * @param int   $hint_post_id   Optional. Post ID known from the UI context.
	 * @return void
	 */
	private function update_content_references( $replacements, $attachment_id, $hint_post_id = 0 ) {
		if ( empty( $replacements ) || ! $attachment_id ) {
			return;
		}

		// Start with the hint if provided — this is the post the user explicitly
		// selected in the UI, so it is always correct.
		$post_ids = $hint_post_id > 0 ? array( absint( $hint_post_id ) ) : array();

		// 1. Attachment's own post_parent — set when the image was uploaded
		// while a post was open in the editor.
		$attachment_post = get_post( $attachment_id );
		if ( $attachment_post && $attachment_post->post_parent ) {
			$post_ids[] = absint( $attachment_post->post_parent );
		}

		// 2. Posts that use this attachment as their featured image.
		$thumb_posts = get_posts(
			array(
				'post_type'      => 'any',
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'meta_key'       => '_thumbnail_id', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'     => $attachment_id,  // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			)
		);
		$post_ids    = array_merge( $post_ids, (array) $thumb_posts );

		// 3. WooCommerce products whose gallery contains this attachment.
		$gallery_posts = get_posts(
			array(
				'post_type'      => 'product',
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						'key'     => '_product_image_gallery',
						'value'   => $attachment_id,
						'compare' => 'LIKE',
					),
				),
			)
		);
		$post_ids      = array_merge( $post_ids, (array) $gallery_posts );

		// 4. Posts whose content embeds this image via the block-editor class
		// wp-image-{id}. 'sentence' => true makes WP_Query treat the whole
		// string as one phrase (LIKE '%wp-image-27851%') — short, no path
		// characters, and uniquely identifies the attachment in block content.
		$block_query = new WP_Query(
			array(
				'post_type'      => array( 'post', 'page', 'product' ),
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'cache_results'  => false,
				's'              => 'wp-image-' . $attachment_id,
				'sentence'       => true,
				'orderby'        => 'none',
			)
		);
		$post_ids    = array_merge( $post_ids, (array) $block_query->posts );

		$post_ids = array_unique( array_filter( array_map( 'absint', $post_ids ) ) );

		if ( empty( $post_ids ) ) {
			return;
		}

		// Processing is sequential (one attachment at a time from the JS side),
		// so no per-post locking is needed.
		foreach ( $post_ids as $pid ) {
			$the_post = get_post( $pid );
			if ( ! $the_post ) {
				continue;
			}

			$content = $the_post->post_content;
			$excerpt = $the_post->post_excerpt;
			$changed = false;

			foreach ( $replacements as $old_url => $new_url ) {
				$old_url_safe = esc_url_raw( $old_url );
				$new_url_safe = esc_url_raw( $new_url );

				if ( false !== strpos( $content, $old_url_safe ) ) {
					$content = str_replace( $old_url_safe, $new_url_safe, $content );
					$changed = true;
				}
				if ( false !== strpos( $excerpt, $old_url_safe ) ) {
					$excerpt = str_replace( $old_url_safe, $new_url_safe, $excerpt );
					$changed = true;
				}

				// Also replace path-only references (content stored without domain).
				$old_path = wp_parse_url( $old_url_safe, PHP_URL_PATH );
				$new_path = wp_parse_url( $new_url_safe, PHP_URL_PATH );
				if ( $old_path && $new_path && $old_path !== $new_path ) {
					if ( false !== strpos( $content, $old_path ) ) {
						$content = str_replace( $old_path, $new_path, $content );
						$changed = true;
					}
					if ( false !== strpos( $excerpt, $old_path ) ) {
						$excerpt = str_replace( $old_path, $new_path, $excerpt );
						$changed = true;
					}
				}
			}

			if ( $changed ) {
				wp_update_post(
					array(
						'ID'           => $pid,
						'post_content' => $content,
						'post_excerpt' => $excerpt,
					)
				);
			}
		}
	}

	/**
	 * Swap file extension in a path or filename.
	 *
	 * @since 1.0.0
	 * @param string $path      File path or filename.
	 * @param string $new_ext   New extension without dot.
	 * @return string Path with swapped extension.
	 */
	private function swap_extension( $path, $new_ext ) {
		$info = pathinfo( $path );
		if ( ! empty( $info['dirname'] ) && '.' !== $info['dirname'] ) {
			return $info['dirname'] . '/' . $info['filename'] . '.' . $new_ext;
		}
		return $info['filename'] . '.' . $new_ext;
	}

	/**
	 * Check if enough memory is available to load an image with GD.
	 *
	 * GD truecolor images use ~5 bytes per pixel (RGBA + overhead).
	 *
	 * @since 1.0.0
	 * @param string $file_path Absolute path to image file.
	 * @return array Array with 'ok' (bool) and 'message' (string).
	 */
	private function check_memory_for_image( $file_path ) {
		$image_info = getimagesize( $file_path );
		if ( ! $image_info ) {
			return array(
				'ok'      => false,
				'message' => 'Cannot read image dimensions.',
			);
		}

		$width  = $image_info[0];
		$height = $image_info[1];

		// ~5 bytes per pixel for truecolor RGBA, times 1.8 safety margin.
		$needed = $width * $height * 5 * 1.8;

		$memory_limit = $this->get_memory_limit_bytes();
		$memory_used  = memory_get_usage( true );
		$available    = $memory_limit - $memory_used;

		if ( $needed > $available ) {
			return array(
				'ok'      => false,
				'message' => sprintf(
					'Image %dx%d needs ~%s memory, only %s available.',
					$width,
					$height,
					size_format( $needed ),
					size_format( $available )
				),
			);
		}

		return array(
			'ok'      => true,
			'message' => '',
		);
	}

	/**
	 * Generate an attachment title from the parent product name and MPN.
	 *
	 * Falls back to a cleaned-up version of the filename.
	 *
	 * @since 1.0.0
	 * @param int    $attachment_id Attachment post ID.
	 * @param string $file_path     Original file path (relative to uploads).
	 * @return string Generated title.
	 */
	private function generate_attachment_title( $attachment_id, $file_path ) {
		// Find the product that uses this attachment.
		$product_id = $this->find_parent_product( $attachment_id );

		if ( $product_id && class_exists( 'WooCommerce' ) ) {
			$product_name = get_the_title( $product_id );
			$mpn          = '';

			$mpn_terms = wp_get_post_terms( $product_id, 'pa_mpn', array( 'fields' => 'names' ) );
			if ( ! is_wp_error( $mpn_terms ) && ! empty( $mpn_terms ) ) {
				$mpn = $mpn_terms[0];
			}

			if ( $product_name && $mpn ) {
				return $product_name . ' ' . $mpn;
			}
			if ( $product_name ) {
				return $product_name;
			}
		}

		// Fallback: clean up the filename.
		$title = pathinfo( $file_path, PATHINFO_FILENAME );
		$title = str_replace( array( '-', '_' ), ' ', $title );
		return $title;
	}

	/**
	 * Find the WooCommerce product that uses an attachment as thumbnail or gallery image.
	 *
	 * @since 1.0.0
	 * @param int $attachment_id Attachment post ID.
	 * @return int|false Product ID or false if not found.
	 */
	private function find_parent_product( $attachment_id ) {
		$attachment_id = absint( $attachment_id );

		// Attachments imported through product edit screens usually retain parent relation.
		$parent_id = (int) wp_get_post_parent_id( $attachment_id );
		if ( $parent_id > 0 && 'product' === get_post_type( $parent_id ) ) {
			return $parent_id;
		}

		return false;
	}

	/**
	 * Get PHP memory limit in bytes.
	 *
	 * @since 1.0.0
	 * @return int Memory limit in bytes.
	 */
	private function get_memory_limit_bytes() {
		$limit = ini_get( 'memory_limit' );
		if ( '-1' === $limit ) {
			return PHP_INT_MAX;
		}

		$value = (int) $limit;
		$unit  = strtolower( substr( $limit, -1 ) );

		switch ( $unit ) {
			case 'g':
				$value *= 1024;
				// Fall through.
			case 'm':
				$value *= 1024;
				// Fall through.
			case 'k':
				$value *= 1024;
				break;
		}

		return $value;
	}

	/**
	 * Delete WebP files created during a failed conversion.
	 *
	 * @since 1.0.0
	 * @param array $original_files Array of original file paths.
	 * @return void
	 */
	private function rollback_webp_files( $original_files ) {
		foreach ( $original_files as $original ) {
			$webp_file = $this->swap_extension( $original, 'webp' );
			if ( file_exists( $webp_file ) ) {
				wp_delete_file( $webp_file );
			}
		}
	}

	/**
	 * Build a standard result array.
	 *
	 * @since 1.0.0
	 * @param bool   $success         Whether the operation succeeded.
	 * @param string $message         Human-readable message.
	 * @param int    $files_converted Number of files converted.
	 * @param int    $bytes_saved     Bytes saved.
	 * @return array
	 */
	private function result( $success, $message, $files_converted = 0, $bytes_saved = 0 ) {
		return array(
			'success'         => $success,
			'message'         => $message,
			'files_converted' => $files_converted,
			'bytes_saved'     => $bytes_saved,
		);
	}
}
