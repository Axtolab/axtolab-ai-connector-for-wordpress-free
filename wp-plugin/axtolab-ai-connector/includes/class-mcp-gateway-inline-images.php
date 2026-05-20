<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( class_exists( 'Axtolab_AI_Connector_Inline_Images', false ) ) {
	return;
}

final class Axtolab_AI_Connector_Inline_Images {
	public static function insert( WP_Post $post, array $args ) {
		$content  = $post->post_content;
		$media_id = intval( $args['media_id'] ?? 0 );
		if ( $media_id <= 0 ) {
			return new WP_Error( 'invalid_media_id', 'media_id is required.', array( 'status' => 400 ) );
		}

		$placement   = isset( $args['placement'] ) ? (string) $args['placement'] : 'end';
		$image_block = self::build_image_block( $media_id, $args );

		if ( has_blocks( $content ) && in_array( $placement, array( 'start', 'end' ), true ) ) {
			$blocks = parse_blocks( $content );
			if ( 'start' === $placement ) {
				array_unshift( $blocks, $image_block );
			} else {
				$blocks[] = $image_block;
			}
			return array( 'content' => serialize_blocks( $blocks ) );
		}

		// Flatsome shortcode mode
		if ( self::has_flatsome_images( $content ) || preg_match( '/\[section\s/', $content ) ) {
			$size      = isset( $args['size_slug'] ) ? ' image_size="' . esc_attr( (string) $args['size_slug'] ) . '"' : ' image_size="original"';
			$shortcode = '[ux_image id="' . $media_id . '"' . $size . ']';
			return array( 'content' => self::insert_html( $content, $shortcode, $placement, $args ) );
		}

		$image_html = self::build_image_html( $media_id, $args );
		if ( '' === $image_html ) {
			return new WP_Error( 'image_html_failed', 'Could not construct image HTML.', array( 'status' => 500 ) );
		}

		return array( 'content' => self::insert_html( $content, $image_html, $placement, $args ) );
	}

	public static function replace( WP_Post $post, array $args ) {
		$content        = $post->post_content;
		$new_media_id   = intval( $args['new_media_id'] ?? 0 );
		$match_media_id = intval( $args['match_media_id'] ?? 0 );
		$match_src      = isset( $args['match_src_substring'] ) ? (string) $args['match_src_substring'] : '';

		if ( $new_media_id <= 0 ) {
			return new WP_Error( 'invalid_new_media_id', 'new_media_id is required.', array( 'status' => 400 ) );
		}

		if ( $match_media_id <= 0 && '' === $match_src ) {
			return new WP_Error( 'inline_match_required', 'match_media_id or match_src_substring is required.', array( 'status' => 400 ) );
		}

		if ( has_blocks( $content ) ) {
			$blocks   = parse_blocks( $content );
			$replaced = false;
			$blocks   = self::replace_block_images_recursive( $blocks, $new_media_id, $match_media_id, $match_src, $args, $replaced );
			if ( $replaced ) {
				return array( 'content' => serialize_blocks( $blocks ) );
			}
		}

		$new_html = self::build_image_html( $new_media_id, $args );
		if ( '' === $new_html ) {
			return new WP_Error( 'image_html_failed', 'Could not construct replacement image HTML.', array( 'status' => 500 ) );
		}

		$html_result = self::replace_html_image( $content, $new_html, $match_media_id, $match_src );
		if ( $html_result !== $content ) {
			return array( 'content' => $html_result );
		}

		// Flatsome shortcode fallback
		if ( self::has_flatsome_images( $content ) ) {
			return array( 'content' => self::replace_flatsome_image( $content, $new_media_id, $match_media_id, $match_src, $args ) );
		}

		return array( 'content' => $content );
	}

	public static function remove( WP_Post $post, array $args ) {
		$content        = $post->post_content;
		$match_media_id = intval( $args['match_media_id'] ?? 0 );
		$match_src      = isset( $args['match_src_substring'] ) ? (string) $args['match_src_substring'] : '';

		if ( $match_media_id <= 0 && '' === $match_src ) {
			return new WP_Error( 'inline_match_required', 'match_media_id or match_src_substring is required.', array( 'status' => 400 ) );
		}

		if ( has_blocks( $content ) ) {
			$blocks  = parse_blocks( $content );
			$removed = false;
			$blocks  = self::remove_block_images_recursive( $blocks, $match_media_id, $match_src, $removed );
			if ( $removed ) {
				return array( 'content' => serialize_blocks( $blocks ) );
			}
		}

		$html_result = self::remove_html_image( $content, $match_media_id, $match_src );
		if ( $html_result !== $content ) {
			return array( 'content' => $html_result );
		}

		// Flatsome shortcode fallback
		if ( self::has_flatsome_images( $content ) ) {
			return array( 'content' => self::remove_flatsome_image( $content, $match_media_id, $match_src ) );
		}

		return array( 'content' => $content );
	}

	private static function build_image_block( int $media_id, array $args ): array {
		$image_src = wp_get_attachment_image_src( $media_id, $args['size_slug'] ?? 'full' );
		$src       = is_array( $image_src ) ? $image_src[0] : wp_get_attachment_url( $media_id );
		$alt       = isset( $args['alt_text'] ) && '' !== trim( (string) $args['alt_text'] ) ? (string) $args['alt_text'] : get_post_meta( $media_id, '_wp_attachment_image_alt', true );
		$caption   = isset( $args['caption'] ) ? (string) $args['caption'] : '';

		$attrs = array(
			'id'       => $media_id,
			'sizeSlug' => isset( $args['size_slug'] ) ? (string) $args['size_slug'] : 'full',
			'alt'      => $alt,
			'url'      => $src,
		);

		if ( ! empty( $args['align'] ) ) {
			$attrs['align'] = (string) $args['align'];
		}

		$inner_html  = '<figure class="wp-block-image">';
		$inner_html .= '<img src="' . esc_url( $src ) . '" alt="' . esc_attr( $alt ) . '" class="wp-image-' . intval( $media_id ) . '" />';
		if ( '' !== $caption ) {
			$inner_html .= '<figcaption>' . esc_html( $caption ) . '</figcaption>';
		}
		$inner_html .= '</figure>';

		return array(
			'blockName'    => 'core/image',
			'attrs'        => $attrs,
			'innerBlocks'  => array(),
			'innerHTML'    => $inner_html,
			'innerContent' => array( $inner_html ),
		);
	}

	private static function build_image_html( int $media_id, array $args ): string {
		$image_src = wp_get_attachment_image_src( $media_id, $args['size_slug'] ?? 'full' );
		if ( ! is_array( $image_src ) || empty( $image_src[0] ) ) {
			return '';
		}

		$src     = $image_src[0];
		$alt     = isset( $args['alt_text'] ) && '' !== trim( (string) $args['alt_text'] ) ? (string) $args['alt_text'] : get_post_meta( $media_id, '_wp_attachment_image_alt', true );
		$caption = isset( $args['caption'] ) ? (string) $args['caption'] : '';
		$align   = isset( $args['align'] ) ? sanitize_html_class( (string) $args['align'] ) : 'none';

		$html  = '<figure class="wp-block-image align' . esc_attr( $align ) . '">';
		$html .= '<img src="' . esc_url( $src ) . '" alt="' . esc_attr( $alt ) . '" class="wp-image-' . intval( $media_id ) . '" />';
		if ( '' !== $caption ) {
			$html .= '<figcaption>' . esc_html( $caption ) . '</figcaption>';
		}
		$html .= '</figure>';

		return $html;
	}

	private static function insert_html( string $content, string $image_html, string $placement, array $args ): string {
		if ( 'start' === $placement ) {
			return $image_html . "\n" . $content;
		}

		if ( 'end' === $placement ) {
			return $content . "\n" . $image_html;
		}

		if ( 'marker' === $placement && ! empty( $args['marker'] ) ) {
			$marker = (string) $args['marker'];
			if ( false !== strpos( $content, $marker ) ) {
				return str_replace( $marker, $image_html, $content );
			}
		}

		if ( in_array( $placement, array( 'before_heading', 'after_heading' ), true ) && ! empty( $args['heading_text'] ) ) {
			$heading = preg_quote( (string) $args['heading_text'], '/' );
			$pattern = '/(<h[1-6][^>]*>[^<]*' . $heading . '[^<]*<\/h[1-6]>)/i';
			if ( preg_match( $pattern, $content ) ) {
				if ( 'before_heading' === $placement ) {
					return preg_replace( $pattern, $image_html . "\n$1", $content, 1 ) ?: $content;
				}

				return preg_replace( $pattern, "$1\n" . $image_html, $content, 1 ) ?: $content;
			}
		}

		return $content . "\n" . $image_html;
	}

	private static function replace_block_images_recursive( array $blocks, int $new_media_id, int $match_media_id, string $match_src, array $args, bool &$replaced ): array {
		foreach ( $blocks as &$block ) {
			if ( $replaced ) {
				break;
			}

			if ( isset( $block['blockName'] ) && 'core/image' === $block['blockName'] ) {
				$current_id  = isset( $block['attrs']['id'] ) ? intval( $block['attrs']['id'] ) : 0;
				$current_src = isset( $block['attrs']['url'] ) ? (string) $block['attrs']['url'] : '';

				$matched = ( $match_media_id > 0 && $current_id === $match_media_id ) || ( '' !== $match_src && false !== strpos( $current_src, $match_src ) );
				if ( $matched ) {
					$block    = self::build_image_block( $new_media_id, $args );
					$replaced = true;
					break;
				}
			}

			if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
				$block['innerBlocks'] = self::replace_block_images_recursive( $block['innerBlocks'], $new_media_id, $match_media_id, $match_src, $args, $replaced );
			}
		}

		return $blocks;
	}

	private static function remove_block_images_recursive( array $blocks, int $match_media_id, string $match_src, bool &$removed ): array {
		$result = array();
		foreach ( $blocks as $block ) {
			$drop = false;
			if ( isset( $block['blockName'] ) && 'core/image' === $block['blockName'] ) {
				$current_id  = isset( $block['attrs']['id'] ) ? intval( $block['attrs']['id'] ) : 0;
				$current_src = isset( $block['attrs']['url'] ) ? (string) $block['attrs']['url'] : '';

				$drop = ( $match_media_id > 0 && $current_id === $match_media_id ) || ( '' !== $match_src && false !== strpos( $current_src, $match_src ) );
				if ( $drop ) {
					$removed = true;
				}
			}

			if ( $drop ) {
				continue;
			}

			if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
				$block['innerBlocks'] = self::remove_block_images_recursive( $block['innerBlocks'], $match_media_id, $match_src, $removed );
			}

			$result[] = $block;
		}

		return $result;
	}

	private static function replace_html_image( string $content, string $new_html, int $match_media_id, string $match_src ): string {
		if ( $match_media_id > 0 ) {
			$pattern = '/<figure[^>]*>\s*<img[^>]*class="[^"]*wp-image-' . $match_media_id . '[^"]*"[^>]*>.*?<\/figure>/is';
			$updated = preg_replace( $pattern, $new_html, $content, 1 );
			if ( is_string( $updated ) && $updated !== $content ) {
				return $updated;
			}
		}

		if ( '' !== $match_src ) {
			$pattern = '/<figure[^>]*>\s*<img[^>]*src="[^"]*' . preg_quote( $match_src, '/' ) . '[^"]*"[^>]*>.*?<\/figure>/is';
			$updated = preg_replace( $pattern, $new_html, $content, 1 );
			if ( is_string( $updated ) && $updated !== $content ) {
				return $updated;
			}
		}

		return $content;
	}

	private static function remove_html_image( string $content, int $match_media_id, string $match_src ): string {
		if ( $match_media_id > 0 ) {
			$pattern = '/<figure[^>]*>\s*<img[^>]*class="[^"]*wp-image-' . $match_media_id . '[^"]*"[^>]*>.*?<\/figure>\s*/is';
			$updated = preg_replace( $pattern, '', $content, 1 );
			if ( is_string( $updated ) ) {
				return $updated;
			}
		}

		if ( '' !== $match_src ) {
			$pattern = '/<figure[^>]*>\s*<img[^>]*src="[^"]*' . preg_quote( $match_src, '/' ) . '[^"]*"[^>]*>.*?<\/figure>\s*/is';
			$updated = preg_replace( $pattern, '', $content, 1 );
			if ( is_string( $updated ) ) {
				return $updated;
			}
		}

		return $content;
	}


	/**
	 * Check if content contains Flatsome shortcodes and handle accordingly.
	 * Called as a third fallback after Gutenberg blocks and HTML patterns.
	 */
	private static function has_flatsome_images( string $content ): bool {
		return (bool) preg_match( '/\[ux_image\s/', $content );
	}

	private static function replace_flatsome_image( string $content, int $new_media_id, int $match_media_id, string $match_src, array $args ): string {
		if ( $match_media_id > 0 ) {
			// Match [ux_image id="MEDIA_ID" ...] shortcodes
			$pattern = '/\[ux_image([^\]]*\bid=["\']?' . $match_media_id . '["\']?[^\]]*\]/';
			if ( preg_match( $pattern, $content ) ) {
				$size        = isset( $args['size_slug'] ) ? ' image_size="' . esc_attr( (string) $args['size_slug'] ) . '"' : '';
				$replacement = '[ux_image id="' . $new_media_id . '"' . $size . ']';
				return preg_replace( $pattern, $replacement, $content, 1 );
			}
		}
		return $content;
	}

	private static function remove_flatsome_image( string $content, int $match_media_id, string $match_src ): string {
		if ( $match_media_id > 0 ) {
			$pattern = '/\[ux_image([^\]]*\bid=["\']?' . $match_media_id . '["\']?[^\]]*\]\s*/';
			if ( preg_match( $pattern, $content ) ) {
				return preg_replace( $pattern, '', $content, 1 );
			}
		}
		return $content;
	}
}

if ( ! class_exists( 'MCP_Gateway_Inline_Images', false ) ) {
	class_alias( 'Axtolab_AI_Connector_Inline_Images', 'MCP_Gateway_Inline_Images' );
}
