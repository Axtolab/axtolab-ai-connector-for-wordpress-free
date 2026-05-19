<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Axtolab_AI_Connector_Policy {
	/**
	 * Validate a content type against the allowlist.
	 *
	 * Supports a wildcard `*` in `allowed_content_types`: when present,
	 * accepts ANY post type registered with `public => true`. Lets sites
	 * opt into permissive-mode for custom post types (WooCommerce
	 * `product`, EDD `download`, custom CPTs, etc.) without listing each
	 * one explicitly. Default config remains strict (post / page /
	 * featured_item) — admins opt in by adding `*` to the allowlist.
	 *
	 * @param string $content_type
	 * @return true|WP_Error
	 */
	public static function assert_allowed_content_type( string $content_type ) {
		$config  = Axtolab_AI_Connector_Config::get();
		$allowed = isset( $config['allowed_content_types'] ) ? (array) $config['allowed_content_types'] : array();

		// Wildcard: accept any registered public post type.
		if ( in_array( '*', $allowed, true ) ) {
			$public_types = get_post_types( array( 'public' => true ) );
			if ( isset( $public_types[ $content_type ] ) ) {
				return true;
			}
			return new WP_Error(
				'disallowed_content_type',
				sprintf( 'Content type "%s" is not a registered public post type on this site.', $content_type ),
				array( 'status' => 403 )
			);
		}

		if ( ! in_array( $content_type, $allowed, true ) ) {
			return new WP_Error(
				'disallowed_content_type',
				sprintf( 'Content type "%s" is not in the AI Connector allowlist. Add it via the plugin settings, or set the allowlist to ["*"] to accept any registered public post type.', $content_type ),
				array( 'status' => 403 )
			);
		}

		return true;
	}

	public static function assert_allowed_taxonomy( string $taxonomy ) {
		$config = Axtolab_AI_Connector_Config::get();
		if ( ! in_array( $taxonomy, $config['allowed_taxonomies'], true ) ) {
			return new WP_Error( 'disallowed_taxonomy', 'Taxonomy is not allowed.', array( 'status' => 403 ) );
		}

		return true;
	}

	public static function assert_allowed_author( int $author_id ) {
		$config             = Axtolab_AI_Connector_Config::get();
		$allowed_author_ids = array_map( 'intval', (array) $config['allowed_author_ids'] );
		if ( empty( $allowed_author_ids ) ) {
			return true;
		}

		if ( ! in_array( $author_id, $allowed_author_ids, true ) ) {
			return new WP_Error( 'disallowed_author', 'Author is not allowlisted.', array( 'status' => 403 ) );
		}

		return true;
	}

	public static function assert_allowed_yoast_meta_keys( array $meta ) {
		$config  = Axtolab_AI_Connector_Config::get();
		$allowed = array_map( 'strval', (array) $config['yoast_allowed_meta_keys'] );

		foreach ( array_keys( $meta ) as $key ) {
			if ( ! in_array( $key, $allowed, true ) ) {
				return new WP_Error(
					'disallowed_yoast_meta_key',
					'Yoast meta key is not allowed.',
					array(
						'status' => 403,
						'key'    => $key,
					)
				);
			}
		}

		return true;
	}

	public static function assert_allowed_media( string $mime_type, int $size_bytes, string $alt_text = '' ) {
		$config             = Axtolab_AI_Connector_Config::get();
		$allowed_mime_types = array_map( 'strval', (array) $config['media_allowed_mime_types'] );

		if ( ! in_array( $mime_type, $allowed_mime_types, true ) ) {
			return new WP_Error( 'disallowed_media_mime', 'Media MIME type is not allowed.', array( 'status' => 403 ) );
		}

		$max_size_mb    = max( 1, intval( $config['media_max_size_mb'] ) );
		$max_size_bytes = $max_size_mb * 1024 * 1024;
		if ( $size_bytes > $max_size_bytes ) {
			return new WP_Error( 'media_size_exceeded', 'Media exceeds configured size limit.', array( 'status' => 400 ) );
		}

		if ( ! empty( $config['media_require_alt_text'] ) && '' === trim( $alt_text ) ) {
			return new WP_Error( 'missing_alt_text', 'Alt text is required by policy.', array( 'status' => 400 ) );
		}

		return true;
	}

	public static function can_edit_content( int $post_id, string $post_type ) {
		$post_type_object = get_post_type_object( $post_type );
		if ( ! $post_type_object ) {
			return new WP_Error( 'invalid_post_type', 'Unknown post type.', array( 'status' => 400 ) );
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return new WP_Error( 'forbidden_edit', 'Current user cannot edit this content.', array( 'status' => 403 ) );
		}

		return true;
	}

	public static function can_publish_content( string $post_type ) {
		$post_type_object = get_post_type_object( $post_type );
		if ( ! $post_type_object ) {
			return new WP_Error( 'invalid_post_type', 'Unknown post type.', array( 'status' => 400 ) );
		}

		$capability = $post_type_object->cap->publish_posts ?? 'publish_posts';
		if ( ! current_user_can( $capability ) ) {
			return new WP_Error( 'forbidden_publish', 'Current user cannot publish this content type.', array( 'status' => 403 ) );
		}

		return true;
	}

	public static function can_delete_content( int $post_id ) {
		if ( ! current_user_can( 'delete_post', $post_id ) ) {
			return new WP_Error( 'forbidden_delete', 'Current user cannot delete this content.', array( 'status' => 403 ) );
		}

		return true;
	}

	public static function can_upload_media() {
		if ( ! current_user_can( 'upload_files' ) ) {
			return new WP_Error( 'forbidden_upload', 'Current user cannot upload media.', array( 'status' => 403 ) );
		}

		return true;
	}

	public static function assert_patch_fields( array $patch ) {
		$allowed = array(
			'title',
			'content',
			'excerpt',
			'slug',
			'featured_media',
			'author',
			'date',
		);

		foreach ( array_keys( $patch ) as $key ) {
			if ( ! in_array( $key, $allowed, true ) ) {
				return new WP_Error(
					'disallowed_patch_field',
					'Patch field is not allowed.',
					array(
						'status' => 400,
						'field'  => $key,
					)
				);
			}
		}

		return true;
	}

	public static function to_content_record( WP_Post $post ): array {
		$content_type = $post->post_type;
		$terms        = array();
		$taxonomies   = get_object_taxonomies( $content_type, 'names' );
		foreach ( $taxonomies as $taxonomy ) {
			$term_ids = wp_get_object_terms( $post->ID, $taxonomy, array( 'fields' => 'ids' ) );
			if ( ! is_wp_error( $term_ids ) ) {
				$terms[ $taxonomy ] = array_map( 'intval', $term_ids );
			}
		}

		$record = array(
			'id'             => intval( $post->ID ),
			'content_type'   => $content_type,
			'status'         => $post->post_status,
			'title'          => get_the_title( $post ),
			'slug'           => $post->post_name,
			'excerpt'        => $post->post_excerpt,
			'content'        => $post->post_content,
			'author'         => intval( $post->post_author ),
			'date'           => $post->post_date_gmt,
			'modified'       => $post->post_modified_gmt,
			'featured_media' => intval( get_post_thumbnail_id( $post ) ),
			'terms'          => $terms,
			'admin_edit_url' => admin_url( 'post.php?post=' . $post->ID . '&action=edit' ),
			'preview_url'    => get_preview_post_link( $post ),
			'public_url'     => ( 'publish' === $post->post_status ) ? get_permalink( $post ) : '',
		);

		return $record;
	}
}

if ( ! class_exists( 'MCP_Gateway_Policy', false ) ) {
	class_alias( 'Axtolab_AI_Connector_Policy', 'MCP_Gateway_Policy' );
}
