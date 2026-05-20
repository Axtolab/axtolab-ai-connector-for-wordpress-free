<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( class_exists( 'Axtolab_AI_Connector_Config', false ) ) {
	return;
}

final class Axtolab_AI_Connector_Config {
	private const OPTION_KEY = 'axtolab_ai_connector_settings';

	public static function get(): array {
		$defaults = array(
			'allowed_content_types'        => array( 'post', 'page', 'featured_item' ),
			'allowed_taxonomies'           => array( 'category', 'post_tag', 'featured_item_category', 'featured_item_tag' ),
			'allowed_author_ids'           => array(),
			'solutions_root_slug'          => 'solutions',
			'allow_term_creation'          => true,
			'allow_permanent_delete'       => false,
			'yoast_allowed_paths'          => array( '/yoast/analysis', '/yoast/metadata', '/yoast/head' ),
			'yoast_allowed_meta_keys'      => array(
				'yoast_wpseo_title',
				'yoast_wpseo_metadesc',
				'yoast_wpseo_canonical',
				'yoast_wpseo_focuskw',
				'yoast_wpseo_opengraph-title',
				'yoast_wpseo_opengraph-description',
				'yoast_wpseo_twitter-title',
				'yoast_wpseo_twitter-description',
			),
			'media_allowed_mime_types'     => array( 'image/jpeg', 'image/png', 'image/webp', 'image/gif' ),
			'media_max_size_mb'            => 10,
			'media_require_alt_text'       => true,
			'media_allowed_import_domains' => array(),
			'remote_mcp_enabled'           => false,
		);

		$saved = get_option( self::OPTION_KEY, array() );
		if ( ! is_array( $saved ) ) {
			$saved = array();
		}

		$config = array_merge( $defaults, $saved );

		/**
		 * Filter configuration for environment-specific overrides.
		 */
		return apply_filters( 'axtolab_ai_connector_config', $config );
	}
}

if ( ! class_exists( 'MCP_Gateway_Config', false ) ) {
	class_alias( 'Axtolab_AI_Connector_Config', 'MCP_Gateway_Config' );
}
