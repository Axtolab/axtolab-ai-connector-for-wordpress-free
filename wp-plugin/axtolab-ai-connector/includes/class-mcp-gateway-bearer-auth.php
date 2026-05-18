<?php
/**
 * Bearer Token management for Remote MCP access.
 *
 * Generates, verifies, and revokes bearer tokens used by remote MCP clients
 * (ChatGPT, Claude.ai, etc.) to authenticate against the Streamable HTTP endpoint.
 *
 * Tokens are stored as HMAC-SHA256 hashes — the raw token is shown once and never persisted.
 *
 * @package WP_MCP_Gateway
 * @since   0.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Axtolab_AI_Connector_Bearer_Auth {

	/**
	 * Generate a new bearer token.
	 *
	 * Returns the raw token (show to user ONCE) and stores the HMAC hash.
	 *
	 * @return string|WP_Error The raw bearer token, or a gate error.
	 */
	public static function generate_token() {
		$multisite_allowed = Axtolab_AI_Connector_Free_Gates::check_multisite_allowed();
		if ( is_wp_error( $multisite_allowed ) ) {
			return $multisite_allowed;
		}

		$raw_bytes = random_bytes( 32 );
		$token     = rtrim( strtr( base64_encode( $raw_bytes ), '+/', '-_' ), '=' );

		$hash = hash_hmac( 'sha256', $token, wp_salt( 'auth' ) );

		$settings                                = get_option( 'axtolab_ai_connector_settings', array() );
		$settings['remote_bearer_token_hash']    = $hash;
		$settings['remote_bearer_token_created'] = current_time( 'mysql' );
		$settings['remote_bearer_token_prefix']  = substr( $token, 0, 8 );
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
	 * Revoke the current bearer token.
	 */
	public static function revoke_token(): void {
		$settings = get_option( 'axtolab_ai_connector_settings', array() );
		unset( $settings['remote_bearer_token_hash'] );
		unset( $settings['remote_bearer_token_created'] );
		unset( $settings['remote_bearer_token_prefix'] );
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
	 * @return array{exists: bool, prefix: string, created_at: string}
	 */
	public static function get_token_info(): array {
		$settings = get_option( 'axtolab_ai_connector_settings', array() );

		return array(
			'exists'     => ! empty( $settings['remote_bearer_token_hash'] ),
			'prefix'     => $settings['remote_bearer_token_prefix'] ?? '',
			'created_at' => $settings['remote_bearer_token_created'] ?? '',
		);
	}

}

if ( ! class_exists( 'MCP_Gateway_Bearer_Auth', false ) ) {
	class_alias( 'Axtolab_AI_Connector_Bearer_Auth', 'MCP_Gateway_Bearer_Auth' );
}
