<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( class_exists( 'Axtolab_AI_Connector_Response', false ) ) {
	return;
}

final class Axtolab_AI_Connector_Response {
	public static function success( $data, int $status = 200, ?string $audit_id = null ): WP_REST_Response {
		$payload = array(
			'success'  => true,
			'data'     => $data,
			'audit_id' => $audit_id,
		);

		return new WP_REST_Response( $payload, $status );
	}

	public static function error( string $code, string $message, int $http_status = 400, $details = null, bool $retryable = false ): WP_REST_Response {
		$payload = array(
			'success' => false,
			'error'   => array(
				'code'        => $code,
				'message'     => $message,
				'http_status' => $http_status,
				'details'     => $details,
				'retryable'   => $retryable,
			),
		);

		return new WP_REST_Response( $payload, $http_status );
	}

	/**
	 * Convert a WP_Error into a standard error response.
	 *
	 * @param WP_Error $error      The WordPress error object.
	 * @param int      $fallback   HTTP status to use if the error has no status.
	 * @return WP_REST_Response
	 */
	public static function from_wp_error( WP_Error $error, int $fallback = 400 ): WP_REST_Response {
		$data   = $error->get_error_data();
		$status = is_array( $data ) && isset( $data['status'] ) ? (int) $data['status'] : $fallback;

		return self::error(
			$error->get_error_code(),
			$error->get_error_message(),
			$status,
			$data
		);
	}
}

if ( ! class_exists( 'MCP_Gateway_Response', false ) ) {
	class_alias( 'Axtolab_AI_Connector_Response', 'MCP_Gateway_Response' );
}
