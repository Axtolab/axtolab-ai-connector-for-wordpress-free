<?php
/**
 * Tool consent policy for MCP tool dispatch.
 *
 * Centralises the per-action DISALLOW / ASK / ALWAYS tiers used by the
 * transport before a tool reaches WordPress or an add-on dispatcher.
 *
 * @package WP_MCP_Gateway
 * @since   1.0.11
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( class_exists( 'Axtolab_AI_Connector_Tool_Consent_Policy', false ) ) {
	return;
}

class Axtolab_AI_Connector_Tool_Consent_Policy {
	const TIER_DISALLOW = 'disallow';
	const TIER_ASK      = 'ask';
	const TIER_ALWAYS   = 'always';

	/**
	 * Actions that should ask before execution on a fresh install.
	 *
	 * @return array<string,string>
	 */
	public static function default_policy(): array {
		return array(
			'publish_content'              => self::TIER_ASK,
			'trash_content'                => self::TIER_ASK,
			'delete_content'               => self::TIER_ASK,
			'restore_content'              => self::TIER_ASK,
			'restore_revision'             => self::TIER_ASK,
			'woo_update_product_price'     => self::TIER_ASK,
			'woo_bulk_update_prices'       => self::TIER_ASK,
			'woo_create_coupon'            => self::TIER_ASK,
			'generate_image_in_context'    => self::TIER_ASK,
			'batch_regenerate_post_images' => self::TIER_ASK,
			'delete_brand_kit'             => self::TIER_ASK,
			'delete_term'                  => self::TIER_ASK,
			'delete_post_meta'             => self::TIER_ASK,
			'delete_comment'               => self::TIER_ASK,
			'delete_menu_item'             => self::TIER_ASK,
			'delete_term_meta'             => self::TIER_ASK,
			'create_draft'                 => self::TIER_ALWAYS,
			'update_content'               => self::TIER_ALWAYS,
			'update_media'                 => self::TIER_ALWAYS,
			'set_featured_image'           => self::TIER_ALWAYS,
		);
	}

	/**
	 * Return the resolved action policy: defaults plus saved overrides.
	 *
	 * @return array<string,string>
	 */
	public static function policy( ?string $connection_id = null ): array {
		$config = Axtolab_AI_Connector_Config::get();
		$saved  = isset( $config['tool_consent_policy'] ) && is_array( $config['tool_consent_policy'] )
			? $config['tool_consent_policy']
			: array();

		$policy = array_merge(
			self::sanitize_policy_map( self::default_policy() ),
			self::sanitize_policy_map( $saved )
		);

		/**
		 * Let add-ons register consent defaults for their own MCP mutations.
		 *
		 * Values must be one of: disallow, ask, always.
		 *
		 * @param array<string,string> $policy Resolved action => tier map.
		 * @param array<string,mixed>  $config Connector config.
		 */
		$policy = apply_filters( 'axtolab_ai_connector_tool_consent_policy', $policy, $config );

		if ( $connection_id && class_exists( 'Axtolab_AI_Connector_Connections', false ) ) {
			$policy = array_merge(
				self::sanitize_policy_map( is_array( $policy ) ? $policy : array() ),
				Axtolab_AI_Connector_Connections::get_tool_consent_policy( $connection_id )
			);
		}

		return self::sanitize_policy_map( is_array( $policy ) ? $policy : array() );
	}

	/**
	 * Resolve the consent context for a tool call.
	 *
	 * @param string $tool_name MCP tool name.
	 * @param array  $args      Tool arguments.
	 * @return array{tool_name:string,action:string,key:string,tier:string}
	 */
	public static function context_for_tool( string $tool_name, array $args, ?string $connection_id = null ): array {
		$action = self::action_for_tool( $tool_name );
		$policy = self::policy( $connection_id );
		$tier   = isset( $policy[ $action ] )
			? $policy[ $action ]
			: ( self::looks_destructive( $action, $tool_name ) ? self::TIER_ASK : self::TIER_ALWAYS );

		return array(
			'tool_name' => $tool_name,
			'action'    => $action,
			'key'       => self::confirmation_key( $action, $tool_name, $args ),
			'tier'      => self::normalize_tier( $tier ),
		);
	}

	/**
	 * Convert a MCP tool name to the coarse consent action key.
	 *
	 * @param string $tool_name MCP tool name.
	 * @return string
	 */
	public static function action_for_tool( string $tool_name ): string {
		$map = array(
			'wp_publish_content'              => 'publish_content',
			'wp_trash_content'                => 'trash_content',
			'wp_delete_content'               => 'delete_content',
			'wp_restore_content'              => 'restore_content',
			'wp_restore_revision'             => 'restore_revision',
			'wp_woo_update_product_price'     => 'woo_update_product_price',
			'wp_woo_bulk_update_prices'       => 'woo_bulk_update_prices',
			'wp_woo_create_coupon'            => 'woo_create_coupon',
			'wp_generate_image_in_context'    => 'generate_image_in_context',
			'wp_batch_regenerate_post_images' => 'batch_regenerate_post_images',
			'wp_delete_brand_kit'             => 'delete_brand_kit',
			'wp_delete_term'                  => 'delete_term',
			'wp_delete_post_meta'             => 'delete_post_meta',
			'wp_delete_comment'               => 'delete_comment',
			'wp_delete_menu_item'             => 'delete_menu_item',
			'wp_delete_term_meta'             => 'delete_term_meta',
			'wp_create_draft'                 => 'create_draft',
			'wp_update_content'               => 'update_content',
			'wp_update_media'                 => 'update_media',
			'wp_set_featured_image'           => 'set_featured_image',
		);

		if ( isset( $map[ $tool_name ] ) ) {
			return $map[ $tool_name ];
		}

		return 0 === strpos( $tool_name, 'wp_' ) ? substr( $tool_name, 3 ) : $tool_name;
	}

	/**
	 * Determine whether an unmapped action should fail safe to ASK.
	 *
	 * @param string $action    Consent action key.
	 * @param string $tool_name MCP tool name.
	 * @return bool
	 */
	public static function looks_destructive( string $action, string $tool_name ): bool {
		$needle = strtolower( $action . ' ' . $tool_name );
		foreach ( array( 'delete', 'trash', 'restore', 'publish', 'coupon', 'price', 'bulk', 'batch' ) as $marker ) {
			if ( false !== strpos( $needle, $marker ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Build the token binding key for a tool call.
	 *
	 * @param string $action    Consent action key.
	 * @param string $tool_name MCP tool name.
	 * @param array  $args      Tool arguments.
	 * @return string
	 */
	public static function confirmation_key( string $action, string $tool_name, array $args ): string {
		$ct  = isset( $args['content_type'] ) ? sanitize_key( (string) $args['content_type'] ) : 'post';
		$id  = intval( $args['id'] ?? $args['post_id'] ?? $args['product_id'] ?? $args['media_id'] ?? 0 );
		$rev = intval( $args['revision_id'] ?? 0 );

		switch ( $action ) {
			case 'publish_content':
				return $ct . ':' . $id . ':publish';
			case 'trash_content':
				return $ct . ':' . $id . ':trash';
			case 'delete_content':
				return $ct . ':' . $id . ':delete';
			case 'restore_content':
				return $ct . ':' . $id . ':restore';
			case 'restore_revision':
				return $ct . ':' . $id . ':revision:' . $rev;
			case 'woo_update_product_price':
				return 'woo-product:' . $id . ':price';
			case 'woo_bulk_update_prices':
				return 'woo-bulk-price:' . self::stable_args_hash( $args );
			case 'woo_create_coupon':
				return 'woo-coupon:' . sanitize_key( (string) ( $args['code'] ?? 'new' ) );
			case 'generate_image_in_context':
				return 'image-context:' . $id . ':' . self::stable_args_hash( $args );
			case 'batch_regenerate_post_images':
				return 'image-batch:' . self::stable_args_hash( $args );
			case 'delete_term':
				return sanitize_key( (string) ( $args['taxonomy'] ?? 'term' ) ) . ':' . intval( $args['term_id'] ?? 0 ) . ':delete';
			case 'delete_post_meta':
				return 'post:' . $id . ':meta:' . sanitize_key( (string) ( $args['key'] ?? $args['meta_key'] ?? '' ) ) . ':delete';
			case 'delete_comment':
				return 'comment:' . $id . ':delete';
			case 'delete_menu_item':
				return 'menu-item:' . $id . ':delete';
			case 'delete_term_meta':
				return 'term:' . intval( $args['term_id'] ?? 0 ) . ':meta:' . sanitize_key( (string) ( $args['key'] ?? $args['meta_key'] ?? '' ) ) . ':delete';
			case 'delete_brand_kit':
				return 'brand-kit:' . $id . ':delete';
			default:
				return $action . ':' . self::stable_args_hash( $args );
		}
	}

	/**
	 * Persisted policy map suitable for REST responses.
	 *
	 * @return array<string,string>
	 */
	public static function exported_policy( ?string $connection_id = null ): array {
		return self::policy( $connection_id );
	}

	/**
	 * Sanitize an action => tier map.
	 *
	 * @param array $policy Raw policy map.
	 * @return array<string,string>
	 */
	private static function sanitize_policy_map( array $policy ): array {
		$clean = array();
		foreach ( $policy as $action => $tier ) {
			$action = sanitize_key( (string) $action );
			if ( '' === $action ) {
				continue;
			}
			$clean[ $action ] = self::normalize_tier( (string) $tier );
		}
		return $clean;
	}

	/**
	 * Normalize a tier string.
	 *
	 * @param string $tier Raw tier.
	 * @return string
	 */
	public static function normalize_tier( string $tier ): string {
		$tier = strtolower( sanitize_key( $tier ) );
		return in_array( $tier, array( self::TIER_DISALLOW, self::TIER_ASK, self::TIER_ALWAYS ), true )
			? $tier
			: self::TIER_ASK;
	}

	/**
	 * Stable hash for a tool input with transient confirmation data removed.
	 *
	 * @param array $args Tool arguments.
	 * @return string
	 */
	private static function stable_args_hash( array $args ): string {
		unset( $args['confirmation_token'] );
		ksort( $args );
		return substr( md5( wp_json_encode( $args ) ), 0, 16 );
	}
}

if ( ! class_exists( 'MCP_Gateway_Tool_Consent_Policy', false ) ) {
	class_alias( 'Axtolab_AI_Connector_Tool_Consent_Policy', 'MCP_Gateway_Tool_Consent_Policy' );
}
