<?php
/**
 * Shared capability group definitions.
 *
 * Extracted from Axtolab_AI_Connector_MCP_Transport so that capability groups can be
 * referenced by the REST handler, the connections manager, and the admin UI
 * without coupling to the transport class.
 *
 * @package WP_MCP_Gateway
 * @since   0.1.35
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( class_exists( 'Axtolab_AI_Connector_Capabilities', false ) ) {
	return;
}

class Axtolab_AI_Connector_Capabilities {

	/**
	 * Map of capability group key => array of tool names.
	 * 'read' is always included for all connections.
	 */
	const GROUPS = array(
		'read'          => array(
			'wp_getting_started',
			'wp_site_info',
			'wp_list_content_types',
			'wp_find_content',
			'wp_get_content',
			'wp_list_revisions',
			'wp_list_authors',
			'wp_list_terms',
			'wp_search_media',
			'wp_get_media',
			'wp_get_yoast_analysis',
			'wp_get_yoast_head_preview',
			'wp_get_preview_link',
			'wp_get_post_meta',
			'wp_list_comments',
			'wp_get_comment',
			'wp_get_changelog',
			'wp_get_change',
			'wp_get_my_capabilities',
			'wp_list_users',
			'wp_get_user',
			'wp_get_audit_log',
		),
		'create_edit'   => array(
			'wp_create_draft',
			'wp_update_content',
			'wp_clone_content',
			'wp_request_review',
			'wp_update_post_meta',
			'wp_delete_post_meta',
			'wp_create_comment',
		),
		'publish'       => array(
			'wp_publish_content',
		),
		'trash_restore' => array(
			'wp_trash_content',
			'wp_restore_content',
			'wp_restore_revision',
			'wp_delete_comment',
			'wp_moderate_comment',
			'wp_rollback_change',
			'wp_redo_change',
			'wp_rollback_session',
		),
		'media_manage'  => array(
			'wp_upload_media_from_url',
			'wp_update_media',
			'wp_set_featured_image',
			'wp_insert_inline_image',
			'wp_replace_inline_image',
			'wp_remove_inline_image',
		),
		'taxonomy'      => array(
			'wp_create_term',
			'wp_update_term',
			'wp_delete_term',
			'wp_assign_terms',
		),
		'authors'       => array(
			'wp_assign_author',
		),
		'seo'           => array(
			'wp_update_yoast_metadata',
		),
		'image'         => array(
			'wp_generate_image',
			'wp_search_stock_photos',
			'wp_import_stock_photo',
			'wp_list_image_providers',
			'wp_confirm_image',
		),
		'upload_portal' => array(
			'wp_create_upload_session',
			'wp_get_upload_session',
		),
		'woocommerce'   => array(
			'wp_woo_list_products',
			'wp_woo_get_product',
			'wp_woo_update_product_price',
			'wp_woo_bulk_update_prices',
			'wp_woo_list_orders',
			'wp_woo_get_order',
			'wp_woo_create_coupon',
		),
	);

	/**
	 * Default preset: all except trash_restore.
	 */
	const DEFAULT_PRESET = array(
		'read',
		'create_edit',
		'publish',
		'media_manage',
		'taxonomy',
		'authors',
		'seo',
		'image',
		'upload_portal',
		'woocommerce',
	);

	/**
	 * Named presets for the admin UI.
	 */
	const PRESETS = array(
		'full_access'     => array( 'read', 'create_edit', 'publish', 'trash_restore', 'media_manage', 'taxonomy', 'authors', 'seo', 'image', 'upload_portal', 'woocommerce' ),
		'standard'        => array( 'read', 'create_edit', 'publish', 'media_manage', 'taxonomy', 'authors', 'seo', 'image', 'upload_portal', 'woocommerce' ),
		'draft_only'      => array( 'read', 'create_edit', 'media_manage', 'taxonomy', 'seo', 'image', 'upload_portal' ),
		'read_only'       => array( 'read' ),
		'content_manager' => array( 'read', 'create_edit', 'publish', 'media_manage', 'taxonomy', 'authors', 'seo' ),
		'media_manager'   => array( 'read', 'media_manage' ),
		'seo_specialist'  => array( 'read', 'seo' ),
	);

	/**
	 * Human-readable labels for each group.
	 *
	 * @return array<string, string>
	 */
	public static function group_labels() {
		return array(
			'read'          => __( 'Read Content & Media', 'axtolab-ai-connector' ),
			'create_edit'   => __( 'Create & Edit Drafts', 'axtolab-ai-connector' ),
			'publish'       => __( 'Publish & Schedule', 'axtolab-ai-connector' ),
			'trash_restore' => __( 'Trash & Restore', 'axtolab-ai-connector' ),
			'media_manage'  => __( 'Upload & Manage Media', 'axtolab-ai-connector' ),
			'taxonomy'      => __( 'Manage Taxonomies', 'axtolab-ai-connector' ),
			'authors'       => __( 'Assign Authors', 'axtolab-ai-connector' ),
			'seo'           => __( 'SEO Management (Yoast)', 'axtolab-ai-connector' ),
			'image'         => __( 'Image Generation & Stock Photos', 'axtolab-ai-connector' ),
			'upload_portal' => __( 'Upload Portal', 'axtolab-ai-connector' ),
			'woocommerce'   => __( 'WooCommerce', 'axtolab-ai-connector' ),
		);
	}

	/**
	 * Given a list of capability group keys, return the flat list of allowed tool names.
	 *
	 * @param array $capabilities List of group keys.
	 * @return array Unique tool names.
	 */
	public static function tools_for( $capabilities ) {
		$tools = array();
		foreach ( $capabilities as $group ) {
			if ( isset( self::GROUPS[ $group ] ) ) {
				$tools = array_merge( $tools, self::GROUPS[ $group ] );
			}
		}

		$tools = array_unique( $tools );
		if ( class_exists( 'Axtolab_AI_Connector_Free_Gates' ) && ! Axtolab_AI_Connector_Free_Gates::is_image_generation_allowed() ) {
			$tools = array_values( array_diff( $tools, array( 'wp_generate_image' ) ) );
		}

		return $tools;
	}

	/**
	 * Return all known group keys.
	 *
	 * @return array
	 */
	public static function all_groups() {
		return array_keys( self::GROUPS );
	}

	/**
	 * Human-readable preset labels for the admin UI.
	 *
	 * @return array<string, string>
	 */
	public static function preset_labels() {
		return array(
			'full_access'     => __( 'Full Access', 'axtolab-ai-connector' ),
			'standard'        => __( 'Standard (Recommended)', 'axtolab-ai-connector' ),
			'draft_only'      => __( 'Draft Only (No Publish)', 'axtolab-ai-connector' ),
			'read_only'       => __( 'Read Only', 'axtolab-ai-connector' ),
			'content_manager' => __( 'Content Manager', 'axtolab-ai-connector' ),
			'media_manager'   => __( 'Media Manager', 'axtolab-ai-connector' ),
			'seo_specialist'  => __( 'SEO Specialist', 'axtolab-ai-connector' ),
			'custom'          => __( 'Custom', 'axtolab-ai-connector' ),
		);
	}

	/**
	 * Detect which named preset (if any) a given capability list matches.
	 * Returns 'custom' if no preset matches exactly.
	 *
	 * @param array $capabilities List of group keys.
	 * @return string Preset slug, or 'custom'.
	 */
	public static function detect_preset( $capabilities ) {
		$current = array_values( array_unique( (array) $capabilities ) );
		sort( $current );
		foreach ( self::PRESETS as $slug => $caps ) {
			$candidate = array_values( array_unique( $caps ) );
			sort( $candidate );
			if ( $candidate === $current ) {
				return $slug;
			}
		}
		return 'custom';
	}
}

if ( ! class_exists( 'MCP_Gateway_Capabilities', false ) ) {
	class_alias( 'Axtolab_AI_Connector_Capabilities', 'MCP_Gateway_Capabilities' );
}
