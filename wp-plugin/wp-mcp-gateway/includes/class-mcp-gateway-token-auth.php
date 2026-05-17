<?php
/**
 * MCP Gateway Token Auth
 *
 * Generates self-contained connection tokens that can be decoded offline
 * by the MCP CLI. This allows non-technical users to connect Claude without
 * requiring any HTTP round-trips from Claude's execution environment.
 *
 * Token format: wmcp1_<base64-encoded JSON>
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
	 * Generate a connection token containing all credentials needed
	 * to configure the MCP server.
	 *
	 * Creates an Application Password for the service account, then
	 * base64-encodes the full credential payload with the wmcp1_ prefix.
	 *
	 * @return string|WP_Error The connection token string, or WP_Error on failure.
	 */
	public static function generate_connection_token() {
		$multisite_allowed = Axtolab_AI_Connector_Free_Gates::check_multisite_allowed();
		if ( is_wp_error( $multisite_allowed ) ) {
			return $multisite_allowed;
		}

		// Validate service account exists.
		$service_user_id = (int) get_option( 'axtolab_ai_connector_service_user_id', 0 );

		if ( ! $service_user_id || ! get_user_by( 'id', $service_user_id ) ) {
			return new WP_Error(
				'service_account_missing',
				__( 'The MCP Gateway service account does not exist. Please deactivate and reactivate the plugin.', 'axtolab-ai-connector' )
			);
		}

		// Ensure Application Passwords are available (WP 5.6+).
		if ( ! class_exists( 'WP_Application_Passwords' ) ) {
			return new WP_Error(
				'app_passwords_unavailable',
				__( 'Application Passwords are not available on this site.', 'axtolab-ai-connector' )
			);
		}

		// Create the Application Password.
		$label = sprintf(
			/* translators: %s: ISO date and time */
			__( 'MCP Gateway — %s', 'axtolab-ai-connector' ),
			gmdate( 'Y-m-d H:i' )
		);

		$result = WP_Application_Passwords::create_new_application_password(
			$service_user_id,
			array( 'name' => $label )
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// $result[0] is the plaintext password (only available at creation time).
		// $result[1] is the password record array (includes 'uuid').
		$plaintext_password = $result[0];
		$password_record    = $result[1];

		// Register connection metadata so the Connection Manager can display
		// the client type and label. Without this, token-generated connections
		// would show as "Unknown" in the admin UI.
		if ( class_exists( 'Axtolab_AI_Connector_Connections' ) && ! empty( $password_record['uuid'] ) ) {
			Axtolab_AI_Connector_Connections::register_meta(
				$password_record['uuid'],
				array(
					'client_type'  => 'cli',
					'client_label' => $label,
				)
			);
		}

		// Build the token payload.
		$payload = array(
			'v'         => self::TOKEN_VERSION,
			'site_url'  => home_url(),
			'base_url'  => rest_url( 'axtolab-ai-connector/v1' ),
			'username'  => 'axtolab-connector-service',
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
