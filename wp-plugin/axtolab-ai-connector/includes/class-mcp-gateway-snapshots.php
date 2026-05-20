<?php
/**
 * MCP Gateway Snapshots
 *
 * Per-target-type capture/restore primitives for the changelog.
 * Each `capture_*()` method returns a normalised array suitable for
 * JSON storage; the matching `restore_*()` method writes it back.
 *
 * Adding a new target type means: add a capture method, a restore
 * method, and (optionally) extend the rollback dispatcher in REST.
 *
 * @package WP_MCP_Gateway
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Axtolab_AI_Connector_Snapshots', false ) ) :
class Axtolab_AI_Connector_Snapshots {

	/**
	 * Capture a post: core fields + post_meta + taxonomy term IDs +
	 * featured image. Returns null when the post does not exist —
	 * callers can use that to record a `create` (no before) or
	 * `delete` (no after) action.
	 *
	 * @param int $post_id
	 * @return array|null
	 */
	public static function capture_post( $post_id ) {
		$post = get_post( (int) $post_id );
		if ( ! $post instanceof WP_Post ) {
			return null;
		}

		$fields = array(
			'ID'                => (int) $post->ID,
			'post_title'        => $post->post_title,
			'post_content'      => $post->post_content,
			'post_excerpt'      => $post->post_excerpt,
			'post_status'       => $post->post_status,
			'post_name'         => $post->post_name,
			'post_author'       => (int) $post->post_author,
			'post_date_gmt'     => $post->post_date_gmt,
			'post_modified_gmt' => $post->post_modified_gmt,
			'post_type'         => $post->post_type,
			'post_parent'       => (int) $post->post_parent,
			'menu_order'        => (int) $post->menu_order,
			'comment_status'    => $post->comment_status,
			'ping_status'       => $post->ping_status,
		);

		$meta_raw = get_post_meta( $post->ID );
		$meta     = array();
		if ( is_array( $meta_raw ) ) {
			foreach ( $meta_raw as $key => $values ) {
				// WP returns serialized values as strings; preserve raw.
				$meta[ $key ] = array_map(
					static function ( $v ) {
						$un = maybe_unserialize( $v );
						return $un;
					},
					(array) $values
				);
			}
		}

		$terms      = array();
		$taxonomies = get_object_taxonomies( $post->post_type );
		foreach ( $taxonomies as $taxonomy ) {
			$tids = wp_get_object_terms( $post->ID, $taxonomy, array( 'fields' => 'ids' ) );
			if ( is_wp_error( $tids ) ) {
				continue;
			}
			$terms[ $taxonomy ] = array_map( 'intval', $tids );
		}

		$thumbnail_id = (int) get_post_thumbnail_id( $post->ID );

		return array(
			'post'         => $fields,
			'meta'         => $meta,
			'terms'        => $terms,
			'thumbnail_id' => $thumbnail_id ? $thumbnail_id : null,
		);
	}

	/**
	 * Restore a post snapshot. Updates core fields, replaces meta
	 * (keys present in the snapshot — does not delete keys added
	 * since the snapshot, see retain_extra_meta arg), restores
	 * terms exactly, and resets/clears the featured image.
	 *
	 * Concurrent-edit guard: the caller is expected to compare
	 * post_modified_gmt against the snapshot before invoking this
	 * helper. We don't check here because the caller knows whether
	 * the user requested override.
	 *
	 * @param array $snapshot Output of capture_post().
	 * @return int|WP_Error Post ID on success, WP_Error on failure.
	 */
	public static function restore_post( array $snapshot ) {
		if ( empty( $snapshot['post'] ) || ! is_array( $snapshot['post'] ) ) {
			return new WP_Error( 'snapshot_invalid', 'Post snapshot has no `post` field.' );
		}

		$post = $snapshot['post'];
		if ( empty( $post['ID'] ) ) {
			return new WP_Error( 'snapshot_invalid', 'Post snapshot is missing ID.' );
		}

		$existing = get_post( (int) $post['ID'] );
		if ( ! $existing instanceof WP_Post ) {
			// Recreating a deleted post — wp_insert_post with the
			// snapshot's ID. WP supports this via `import_id`.
			$insert = $post;
			unset( $insert['ID'] );
			$insert['import_id']         = (int) $post['ID'];
			$insert['post_modified']     = current_time( 'mysql' );
			$insert['post_modified_gmt'] = current_time( 'mysql', true );

			$new_id = wp_insert_post( wp_slash( $insert ), true );
			if ( is_wp_error( $new_id ) ) {
				return $new_id;
			}
		} else {
			$update       = $post;
			$update['ID'] = (int) $post['ID'];
			$result       = wp_update_post( wp_slash( $update ), true );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}

		$post_id = (int) $post['ID'];

		// Replace meta with snapshot values.
		if ( ! empty( $snapshot['meta'] ) && is_array( $snapshot['meta'] ) ) {
			foreach ( $snapshot['meta'] as $key => $values ) {
				delete_post_meta( $post_id, $key );
				foreach ( (array) $values as $value ) {
					add_post_meta( $post_id, $key, wp_slash( $value ) );
				}
			}
		}

		// Restore taxonomy terms (replace, don't append).
		if ( ! empty( $snapshot['terms'] ) && is_array( $snapshot['terms'] ) ) {
			foreach ( $snapshot['terms'] as $taxonomy => $tids ) {
				wp_set_object_terms( $post_id, array_map( 'intval', (array) $tids ), $taxonomy, false );
			}
		}

		// Featured image.
		if ( ! empty( $snapshot['thumbnail_id'] ) ) {
			set_post_thumbnail( $post_id, (int) $snapshot['thumbnail_id'] );
		} else {
			delete_post_thumbnail( $post_id );
		}

		return $post_id;
	}

	// =========================================================================
	// Option (single key)
	// =========================================================================

	/**
	 * Capture a single option's current value plus an "exists" marker
	 * so a delete/restore round-trip is possible.
	 *
	 * @param string $name Option name.
	 * @return array {value, existed: bool}
	 */
	public static function capture_option( $name ) {
		$sentinel = '__axtolab_ai_connector_option_missing__';
		$value    = get_option( (string) $name, $sentinel );
		$existed  = ( $sentinel !== $value );
		return array(
			'name'    => (string) $name,
			'value'   => $existed ? $value : null,
			'existed' => $existed,
		);
	}

	/**
	 * Restore a single option from a snapshot. If `existed` is false,
	 * the option is deleted (representing the original "not set" state).
	 *
	 * @param array $snapshot
	 * @return bool
	 */
	public static function restore_option( array $snapshot ) {
		if ( empty( $snapshot['name'] ) ) {
			return false;
		}
		$name = (string) $snapshot['name'];
		if ( empty( $snapshot['existed'] ) ) {
			delete_option( $name );
			return true;
		}
		return (bool) update_option( $name, $snapshot['value'] );
	}

	// =========================================================================
	// Post meta (single key)
	// =========================================================================

	/**
	 * Capture all values for a single post_meta key on a post. Meta
	 * keys can have multiple values; we preserve the order.
	 *
	 * @param int    $post_id
	 * @param string $meta_key
	 * @return array {post_id, key, values: array}
	 */
	public static function capture_post_meta( $post_id, $meta_key ) {
		$values = get_post_meta( (int) $post_id, (string) $meta_key, false );
		if ( ! is_array( $values ) ) {
			$values = array();
		}
		return array(
			'post_id' => (int) $post_id,
			'key'     => (string) $meta_key,
			'values'  => array_values( $values ),
		);
	}

	/**
	 * Restore post_meta for a key from a snapshot. Replaces all
	 * values for that key on the post.
	 *
	 * @param array $snapshot
	 * @return bool
	 */
	public static function restore_post_meta( array $snapshot ) {
		if ( empty( $snapshot['post_id'] ) || empty( $snapshot['key'] ) ) {
			return false;
		}
		$post_id = (int) $snapshot['post_id'];
		$key     = (string) $snapshot['key'];
		$values  = isset( $snapshot['values'] ) && is_array( $snapshot['values'] ) ? $snapshot['values'] : array();

		delete_post_meta( $post_id, $key );
		foreach ( $values as $value ) {
			add_post_meta( $post_id, $key, wp_slash( $value ) );
		}
		return true;
	}

	// =========================================================================
	// Term (whole term row + meta)
	// =========================================================================

	/**
	 * Capture a term: core fields + all meta. Returns null when the
	 * term does not exist (so a delete can record `before` only).
	 *
	 * @param int    $term_id
	 * @param string $taxonomy
	 * @return array|null
	 */
	public static function capture_term( $term_id, $taxonomy ) {
		$term = get_term( (int) $term_id, (string) $taxonomy );
		if ( ! $term || is_wp_error( $term ) ) {
			return null;
		}

		$meta_raw = get_term_meta( (int) $term_id );
		$meta     = array();
		if ( is_array( $meta_raw ) ) {
			foreach ( $meta_raw as $k => $vals ) {
				$meta[ $k ] = array_map(
					static function ( $v ) {
						return maybe_unserialize( $v ); },
					(array) $vals
				);
			}
		}

		return array(
			'term_id'     => (int) $term->term_id,
			'taxonomy'    => (string) $term->taxonomy,
			'name'        => (string) $term->name,
			'slug'        => (string) $term->slug,
			'description' => (string) $term->description,
			'parent'      => (int) $term->parent,
			'meta'        => $meta,
		);
	}

	/**
	 * Restore a term from a snapshot — recreates if missing
	 * (preserving the term_id via direct insert), or updates in place.
	 * Replaces all meta with the snapshot's values.
	 *
	 * @param array $snapshot
	 * @return int|WP_Error term_id on success
	 */
	public static function restore_term( array $snapshot ) {
		if ( empty( $snapshot['taxonomy'] ) ) {
			return new WP_Error( 'snapshot_invalid', 'Term snapshot missing taxonomy.' );
		}
		$tax    = (string) $snapshot['taxonomy'];
		$tid    = (int) ( $snapshot['term_id'] ?? 0 );
		$exists = $tid ? get_term( $tid, $tax ) : null;

		if ( ! $exists || is_wp_error( $exists ) ) {
			// Recreate. wp_insert_term doesn't honour a forced ID, so
			// we accept that the new term may get a different ID and
			// rebind any references would be a v0.next concern.
			$result = wp_insert_term(
				(string) $snapshot['name'],
				$tax,
				array(
					'slug'        => (string) ( $snapshot['slug'] ?? '' ),
					'description' => (string) ( $snapshot['description'] ?? '' ),
					'parent'      => (int) ( $snapshot['parent'] ?? 0 ),
				)
			);
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			$tid = (int) $result['term_id'];
		} else {
			$result = wp_update_term(
				$tid,
				$tax,
				array(
					'name'        => (string) $snapshot['name'],
					'slug'        => (string) ( $snapshot['slug'] ?? '' ),
					'description' => (string) ( $snapshot['description'] ?? '' ),
					'parent'      => (int) ( $snapshot['parent'] ?? 0 ),
				)
			);
			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}

		if ( ! empty( $snapshot['meta'] ) && is_array( $snapshot['meta'] ) ) {
			foreach ( $snapshot['meta'] as $key => $values ) {
				delete_term_meta( $tid, $key );
				foreach ( (array) $values as $value ) {
					add_term_meta( $tid, $key, wp_slash( $value ) );
				}
			}
		}

		return $tid;
	}

	// =========================================================================
	// Term meta (single key)
	// =========================================================================

	/**
	 * Capture all values for a single term_meta key on a term.
	 *
	 * @param int    $term_id
	 * @param string $meta_key
	 * @return array {term_id, key, values}
	 */
	public static function capture_term_meta( $term_id, $meta_key ) {
		$values = get_term_meta( (int) $term_id, (string) $meta_key, false );
		if ( ! is_array( $values ) ) {
			$values = array();
		}
		return array(
			'term_id' => (int) $term_id,
			'key'     => (string) $meta_key,
			'values'  => array_values( $values ),
		);
	}

	/**
	 * Restore term_meta for a key from a snapshot.
	 *
	 * @param array $snapshot
	 * @return bool
	 */
	public static function restore_term_meta( array $snapshot ) {
		if ( empty( $snapshot['term_id'] ) || empty( $snapshot['key'] ) ) {
			return false;
		}
		$term_id = (int) $snapshot['term_id'];
		$key     = (string) $snapshot['key'];
		$values  = isset( $snapshot['values'] ) && is_array( $snapshot['values'] ) ? $snapshot['values'] : array();

		delete_term_meta( $term_id, $key );
		foreach ( $values as $value ) {
			add_term_meta( $term_id, $key, wp_slash( $value ) );
		}
		return true;
	}

	// =========================================================================
	// Theme mod (single key)
	// =========================================================================

	/**
	 * Capture a single theme mod value. `existed=false` means the
	 * mod was unset and the theme's fallback was in effect.
	 *
	 * @param string $name
	 * @return array {name, value, existed}
	 */
	public static function capture_theme_mod( $name ) {
		$sentinel = '__axtolab_ai_connector_themod_missing__';
		$mods     = get_theme_mods();
		$existed  = is_array( $mods ) && array_key_exists( (string) $name, $mods );
		return array(
			'name'    => (string) $name,
			'value'   => $existed ? $mods[ $name ] : null,
			'existed' => $existed,
			'theme'   => get_stylesheet(),
		);
	}

	/**
	 * Restore a theme mod. If `existed=false`, removes the mod so
	 * the theme falls back to its default.
	 *
	 * @param array $snapshot
	 * @return bool
	 */
	public static function restore_theme_mod( array $snapshot ) {
		if ( empty( $snapshot['name'] ) ) {
			return false;
		}
		$name = (string) $snapshot['name'];
		if ( empty( $snapshot['existed'] ) ) {
			remove_theme_mod( $name );
			return true;
		}
		set_theme_mod( $name, $snapshot['value'] );
		return true;
	}

	// =========================================================================
	// Menu (full menu including items)
	// =========================================================================

	/**
	 * Capture an entire nav menu: term row + ordered list of items
	 * with all their fields needed to rebuild via wp_update_nav_menu_item.
	 *
	 * @param int $menu_id
	 * @return array|null
	 */
	public static function capture_menu( $menu_id ) {
		$term = get_term( (int) $menu_id, 'nav_menu' );
		if ( ! $term || is_wp_error( $term ) ) {
			return null;
		}

		$items = wp_get_nav_menu_items( (int) $menu_id, array( 'update_post_term_cache' => false ) );
		if ( ! is_array( $items ) ) {
			$items = array();
		}

		$rows = array();
		foreach ( $items as $item ) {
			$rows[] = array(
				'db_id'            => (int) $item->db_id,
				'menu_item_parent' => (int) $item->menu_item_parent,
				'object_id'        => (int) $item->object_id,
				'object'           => (string) $item->object,
				'type'             => (string) $item->type,
				'title'            => (string) $item->title,
				'url'              => (string) $item->url,
				'description'      => (string) $item->description,
				'attr_title'       => (string) $item->attr_title,
				'target'           => (string) $item->target,
				'classes'          => is_array( $item->classes ) ? array_values( $item->classes ) : array(),
				'xfn'              => (string) $item->xfn,
				'menu_order'       => (int) $item->menu_order,
				'status'           => 'publish',
			);
		}

		return array(
			'menu_id'   => (int) $menu_id,
			'menu_name' => (string) $term->name,
			'menu_slug' => (string) $term->slug,
			'items'     => $rows,
		);
	}

	/**
	 * Restore an entire menu's items. Strategy: delete every current
	 * menu item, then recreate from the snapshot in two passes
	 * (assigning parents in the second pass since IDs change).
	 *
	 * @param array $snapshot
	 * @return true|WP_Error
	 */
	public static function restore_menu( array $snapshot ) {
		if ( empty( $snapshot['menu_id'] ) ) {
			return new WP_Error( 'snapshot_invalid', 'Menu snapshot missing menu_id.' );
		}
		$menu_id = (int) $snapshot['menu_id'];

		$current = wp_get_nav_menu_items( $menu_id, array( 'update_post_term_cache' => false ) );
		if ( is_array( $current ) ) {
			foreach ( $current as $item ) {
				wp_delete_post( (int) $item->db_id, true );
			}
		}

		$rows = isset( $snapshot['items'] ) && is_array( $snapshot['items'] ) ? $snapshot['items'] : array();

		// Pass 1: insert items, capturing old_db_id -> new_db_id.
		$id_map = array();
		foreach ( $rows as $row ) {
			$args   = array(
				'menu-item-object-id'   => (int) $row['object_id'],
				'menu-item-object'      => (string) $row['object'],
				'menu-item-type'        => (string) $row['type'],
				'menu-item-title'       => (string) $row['title'],
				'menu-item-url'         => (string) $row['url'],
				'menu-item-description' => (string) $row['description'],
				'menu-item-attr-title'  => (string) $row['attr_title'],
				'menu-item-target'      => (string) $row['target'],
				'menu-item-classes'     => implode( ' ', (array) $row['classes'] ),
				'menu-item-xfn'         => (string) $row['xfn'],
				'menu-item-position'    => (int) $row['menu_order'],
				'menu-item-status'      => (string) $row['status'],
				'menu-item-parent-id'   => 0, // will fix in pass 2
			);
			$new_id = wp_update_nav_menu_item( $menu_id, 0, $args );
			if ( ! is_wp_error( $new_id ) ) {
				$id_map[ (int) $row['db_id'] ] = (int) $new_id;
			}
		}

		// Pass 2: fix up parents now we have the id_map.
		foreach ( $rows as $row ) {
			$old_id     = (int) $row['db_id'];
			$old_parent = (int) $row['menu_item_parent'];
			if ( ! $old_parent ) {
				continue;
			}
			if ( ! isset( $id_map[ $old_id ] ) || ! isset( $id_map[ $old_parent ] ) ) {
				continue;
			}
			update_post_meta( $id_map[ $old_id ], '_menu_item_menu_item_parent', (string) $id_map[ $old_parent ] );
		}

		return true;
	}
}
endif;

if ( ! class_exists( 'MCP_Gateway_Snapshots', false ) ) {
	class_alias( 'Axtolab_AI_Connector_Snapshots', 'MCP_Gateway_Snapshots' );
}
