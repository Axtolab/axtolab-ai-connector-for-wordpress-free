<?php
/**
 * MCP Gateway Token Auth
 *
 * Builds self-contained connection tokens that the MCP CLI (and the .mcpb
 * Claude Desktop bundle) can decode offline. The token bundles the site URL,
 * REST base URL, the WordPress user login the connection authenticates as,
 * and the Application Password the admin already created in their WordPress
 * profile. No HTTP round-trips from Claude's execution environment are
 * required to read the credentials.
 *
 * Token format: wmcp1_<base64-encoded JSON>
 *
 * Since the round-6 refactor the plugin never creates Application Passwords
 * itself — the admin creates them under their own (or a dedicated) WP user
 * profile and pastes them into the connection wizard. This builder simply
 * packages the credentials the admin supplied.
 *
 * @package WP_MCP_Gateway
 * @since   0.1.19
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Axtolab_AI_Connector_Token_Auth
 */
if ( class_exists( 'Axtolab_AI_Connector_Token_Auth', false ) ) {
	return;
}

class Axtolab_AI_Connector_Token_Auth {

	/**
	 * Token version — increment when the payload structure changes.
	 *
	 * @var int
	 */
	const TOKEN_VERSION = 1;

	/**
	 * Token prefix used to identify connection tokens.
	 *
	 * @var string
	 */
	const TOKEN_PREFIX = 'wmcp1_';

	/**
	 * Build a connection token from credentials the admin already created.
	 *
	 * The admin generates the Application Password via WordPress's native
	 * Profile > Application Passwords UI, then the connection wizard passes
	 * `(username, plaintext_app_password)` here. This method does not touch
	 * the WordPress password store.
	 *
	 * @param string $username           The WordPress user login the App Password belongs to.
	 * @param string $plaintext_password The plaintext Application Password (shown once at creation time).
	 * @return string|WP_Error The connection token string, or WP_Error on failure.
	 */
	public static function build_connection_token( $username, $plaintext_password ) {
		$multisite_allowed = Axtolab_AI_Connector_Free_Gates::check_multisite_allowed();
		if ( is_wp_error( $multisite_allowed ) ) {
			return $multisite_allowed;
		}

		if ( empty( $username ) || empty( $plaintext_password ) ) {
			return new WP_Error(
				'invalid_credentials',
				__( 'Username and Application Password are required to build a connection token.', 'axtolab-ai-connector' )
			);
		}

		$payload = array(
			'v'         => self::TOKEN_VERSION,
			'site_url'  => home_url(),
			'base_url'  => rest_url( 'axtolab-ai-connector/v1' ),
			'username'  => $username,
			'token'     => $plaintext_password,
			'site_name' => get_bloginfo( 'name' ),
		);

		$json = wp_json_encode( $payload );

		if ( false === $json ) {
			return new WP_Error(
				'token_encode_failed',
				__( 'Failed to encode connection token payload.', 'axtolab-ai-connector' )
			);
		}

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		return self::TOKEN_PREFIX . base64_encode( $json );
	}
}

if ( ! class_exists( 'MCP_Gateway_Token_Auth', false ) ) {
	class_alias( 'Axtolab_AI_Connector_Token_Auth', 'MCP_Gateway_Token_Auth' );
}
