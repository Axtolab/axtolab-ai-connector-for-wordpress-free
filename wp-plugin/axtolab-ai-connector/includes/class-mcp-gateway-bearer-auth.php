<?php
/**
 * Bearer Token management for Remote MCP access.
 *
 * Generates, verifies, and revokes bearer tokens used by remote MCP clients
 * (ChatGPT, Claude.ai, etc.) to authenticate against the Streamable HTTP endpoint.
 *
 * Tokens are stored as HMAC-SHA256 hashes — the raw token is shown once and never persisted.
 *
 * Each token is bound to the WordPress user that was logged in when the
 * token was generated. That user must continue to exist with the underlying
 * WP capabilities required by any tool the client calls; the per-connection
 * capability set in {@see Axtolab_AI_Connector_Connections} narrows further.
 *
 * @package WP_MCP_Gateway
 * @since   0.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( class_exists( 'Axtolab_AI_Connector_Bearer_Auth', false ) ) {
	return;
}

class Axtolab_AI_Connector_Bearer_Auth {

	/**
	 * Generate a new bearer token.
	 *
	 * Returns the raw token (show to user ONCE) and stores the HMAC hash plus
	 * the wp_user_id of the admin that generated it.
	 *
	 * @return string|WP_Error The raw bearer token, or a gate error.
	 */
	public static function generate_token() {
		$multisite_allowed = Axtolab_AI_Connector_Free_Gates::check_multisite_allowed();
		if ( is_wp_error( $multisite_allowed ) ) {
			return $multisite_allowed;
		}

		$current_user_id = get_current_user_id();
		if ( $current_user_id <= 0 ) {
			return new WP_Error(
				'no_current_user',
				__( 'A WordPress administrator must be logged in to generate a Bearer token.', 'axtolab-ai-connector' )
			);
		}

		$raw_bytes = random_bytes( 32 );
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Base64url encoding of random bytes for a URL-safe opaque bearer token, not obfuscation.
		$token = rtrim( strtr( base64_encode( $raw_bytes ), '+/', '-_' ), '=' );

		$hash = hash_hmac( 'sha256', $token, wp_salt( 'auth' ) );

		$settings                                = get_option( 'axtolab_ai_connector_settings', array() );
		$settings['remote_bearer_token_hash']    = $hash;
		$settings['remote_bearer_token_created'] = current_time( 'mysql' );
		$settings['remote_bearer_token_prefix']  = substr( $token, 0, 8 );
		$settings['remote_bearer_token_user_id'] = $current_user_id;
		update_option( 'axtolab_ai_connector_settings', $settings );

		return $token;
	}

	/**
	 * Verify a bearer token against stored hash.
	 *
	 * @param string $token The raw bearer token to verify.
	 * @return bool True if valid.
	 */
	public static function verify_token( string $token ): bool {
		$settings    = get_option( 'axtolab_ai_connector_settings', array() );
		$stored_hash = $settings['remote_bearer_token_hash'] ?? '';

		if ( empty( $stored_hash ) ) {
			return false;
		}

		$provided_hash = hash_hmac( 'sha256', $token, wp_salt( 'auth' ) );

		return hash_equals( $stored_hash, $provided_hash );
	}

	/**
	 * Return the WordPress user ID the current Bearer token authenticates as.
	 *
	 * @return int 0 when no token is configured.
	 */
	public static function get_token_user_id(): int {
		$settings = get_option( 'axtolab_ai_connector_settings', array() );
		return isset( $settings['remote_bearer_token_user_id'] ) ? (int) $settings['remote_bearer_token_user_id'] : 0;
	}

	/**
	 * Revoke the current bearer token.
	 *
	 * @return void
	 */
	public static function revoke_token(): void {
		$settings = get_option( 'axtolab_ai_connector_settings', array() );
		unset( $settings['remote_bearer_token_hash'] );
		unset( $settings['remote_bearer_token_created'] );
		unset( $settings['remote_bearer_token_prefix'] );
		unset( $settings['remote_bearer_token_user_id'] );
		update_option( 'axtolab_ai_connector_settings', $settings );
	}

	/**
	 * Check if a bearer token exists.
	 *
	 * @return bool
	 */
	public static function has_token(): bool {
		$settings = get_option( 'axtolab_ai_connector_settings', array() );

		return ! empty( $settings['remote_bearer_token_hash'] );
	}

	/**
	 * Get token metadata for admin UI display.
	 *
	 * @return array{exists: bool, prefix: string, created_at: string, user_id: int}
	 */
	public static function get_token_info(): array {
		$settings = get_option( 'axtolab_ai_connector_settings', array() );

		return array(
			'exists'     => ! empty( $settings['remote_bearer_token_hash'] ),
			'prefix'     => $settings['remote_bearer_token_prefix'] ?? '',
			'created_at' => $settings['remote_bearer_token_created'] ?? '',
			'user_id'    => isset( $settings['remote_bearer_token_user_id'] ) ? (int) $settings['remote_bearer_token_user_id'] : 0,
		);
	}
}

if ( ! class_exists( 'MCP_Gateway_Bearer_Auth', false ) ) {
	class_alias( 'Axtolab_AI_Connector_Bearer_Auth', 'MCP_Gateway_Bearer_Auth' );
}
