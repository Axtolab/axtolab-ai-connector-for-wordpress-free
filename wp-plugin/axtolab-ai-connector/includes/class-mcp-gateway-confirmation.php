<?php
/**
 * PHP-side confirmation token service for Remote MCP transport.
 *
 * Mirrors the TypeScript ConfirmationService behaviour using WordPress transients.
 * Tokens are single-use, time-limited, and bound to a specific action + key.
 *
 * @package WP_MCP_Gateway
 * @since   0.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Axtolab_AI_Connector_Confirmation', false ) ) :
class Axtolab_AI_Connector_Confirmation {

	/**
	 * Token time-to-live in seconds (5 minutes).
	 *
	 * @var int
	 */
	private static $ttl = 300;

	/**
	 * Issue a confirmation token.
	 *
	 * @param string $action  The action being confirmed (e.g. "publish_content").
	 * @param string $key     Unique key binding the token to a specific resource (e.g. "post:123:publish").
	 * @param array  $input   The original tool input for reference.
	 * @return array Confirmation payload including the token.
	 */
	public static function issue( string $action, string $key, array $input ): array {
		$token   = wp_generate_uuid4();
		$payload = array(
			'action'     => $action,
			'key'        => $key,
			'input'      => $input,
			'issued_at'  => gmdate( 'c' ),
			'expires_at' => gmdate( 'c', time() + self::$ttl ),
		);

		set_transient( 'axtolab_ai_connector_confirm_' . $token, $payload, self::$ttl );

		return array(
			'requires_confirmation' => true,
			'confirmation_token'    => $token,
			'confirmation_payload'  => $payload,
		);
	}

	/**
	 * Consume (validate + delete) a confirmation token.
	 *
	 * @param string $token           The token to consume.
	 * @param string $expected_action The expected action string.
	 * @param string $expected_key    The expected resource key.
	 * @return array The stored payload.
	 * @throws Exception If the token is invalid, expired, or mismatched.
	 */
	public static function consume( string $token, string $expected_action, string $expected_key ): array {
		$payload = get_transient( 'axtolab_ai_connector_confirm_' . $token );

		if ( false === $payload ) {
			throw new Exception( 'Confirmation token not found or expired.', 400 );
		}

		if ( $payload['action'] !== $expected_action || $payload['key'] !== $expected_key ) {
			throw new Exception( 'Confirmation token does not match this action.', 400 );
		}

		// Single-use — delete immediately.
		delete_transient( 'axtolab_ai_connector_confirm_' . $token );

		return $payload;
	}
}
endif;

if ( ! class_exists( 'MCP_Gateway_Confirmation', false ) ) {
	class_alias( 'Axtolab_AI_Connector_Confirmation', 'MCP_Gateway_Confirmation' );
}
