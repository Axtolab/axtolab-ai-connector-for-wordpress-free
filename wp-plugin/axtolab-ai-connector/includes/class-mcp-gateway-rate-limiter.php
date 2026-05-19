<?php
/**
 * MCP Gateway Rate Limiter
 *
 * Per-IP request throttling using WordPress transients.
 * Limits API requests to prevent abuse and accidental overload.
 *
 * @package WP_MCP_Gateway
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Axtolab_AI_Connector_Rate_Limiter
 */
class Axtolab_AI_Connector_Rate_Limiter {

	/**
	 * Maximum requests per window.
	 */
	const MAX_REQUESTS = 60;

	/**
	 * Window size in seconds.
	 */
	const WINDOW_SECONDS = 60;

	/**
	 * Transient prefix.
	 */
	const PREFIX = 'axtolab_ai_connector_rl_';

	/**
	 * Check if the current request is within rate limits.
	 *
	 * @return true|WP_Error True if allowed, WP_Error if rate limited.
	 */
	public static function check() {
		$ip  = self::get_client_ip();
		$key = self::PREFIX . md5( $ip );

		$data = get_transient( $key );

		if ( false === $data ) {
			set_transient(
				$key,
				array(
					'count' => 1,
					'start' => time(),
				),
				self::WINDOW_SECONDS
			);
			return true;
		}

		$count = isset( $data['count'] ) ? (int) $data['count'] : 0;

		if ( $count >= self::MAX_REQUESTS ) {
			return new WP_Error(
				'rate_limited',
				sprintf(
					/* translators: %d: max requests, %d: window in seconds */
					__( 'Rate limit exceeded. Maximum %1$d requests per %2$d seconds.', 'axtolab-ai-connector' ),
					self::MAX_REQUESTS,
					self::WINDOW_SECONDS
				),
				array(
					'status'      => 429,
					'retry_after' => self::WINDOW_SECONDS,
				)
			);
		}

		$data['count'] = $count + 1;
		set_transient( $key, $data, self::WINDOW_SECONDS );

		return true;
	}

	/**
	 * Get the client IP address.
	 *
	 * @return string
	 */
	private static function get_client_ip() {
		if ( ! empty( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
			$ips = explode( ',', sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) );
			return trim( $ips[0] );
		}

		if ( ! empty( $_SERVER['REMOTE_ADDR'] ) ) {
			return sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) );
		}

		return '127.0.0.1';
	}

	/**
	 * Add rate limit headers to a response.
	 *
	 * @param WP_REST_Response $response Response object.
	 * @return WP_REST_Response
	 */
	public static function add_headers( WP_REST_Response $response ) {
		$ip   = self::get_client_ip();
		$key  = self::PREFIX . md5( $ip );
		$data = get_transient( $key );

		$remaining = self::MAX_REQUESTS;
		if ( false !== $data && isset( $data['count'] ) ) {
			$remaining = max( 0, self::MAX_REQUESTS - (int) $data['count'] );
		}

		$response->header( 'X-RateLimit-Limit', (string) self::MAX_REQUESTS );
		$response->header( 'X-RateLimit-Remaining', (string) $remaining );

		return $response;
	}
}

if ( ! class_exists( 'MCP_Gateway_Rate_Limiter', false ) ) {
	class_alias( 'Axtolab_AI_Connector_Rate_Limiter', 'MCP_Gateway_Rate_Limiter' );
}
