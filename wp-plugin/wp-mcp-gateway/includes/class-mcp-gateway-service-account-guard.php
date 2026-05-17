<?php
/**
 * Service account REST namespace guard.
 *
 * The generated Application Password credentials for the MCP Gateway service
 * account are real WordPress credentials. This guard ensures those credentials
 * can only be used through this plugin's supported REST namespace, preventing
 * direct core REST calls from bypassing connector policy/entitlement gates.
 *
 * @package WP_MCP_Gateway
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Restrict service-account REST traffic to the plugin namespace.
 */
final class Axtolab_AI_Connector_Service_Account_Guard {
	/**
	 * Supported connector REST namespace.
	 *
	 * @var string
	 */
	private const REST_NAMESPACE = '/axtolab-ai-connector/v1';

	/**
	 * Register guard hooks.
	 *
	 * @return void
	 */
	public static function bootstrap(): void {
		add_filter( 'rest_request_before_callbacks', array( __CLASS__, 'guard_rest_request' ), 1, 3 );
	}

	/**
	 * Fail closed when the generated service account calls unsupported REST routes.
	 *
	 * @param mixed           $response Existing pre-callback response.
	 * @param array           $handler  Route handler.
	 * @param WP_REST_Request $request  REST request.
	 * @return mixed Existing response when allowed, WP_Error when blocked.
	 */
	public static function guard_rest_request( $response, $handler, $request ) {
		unset( $handler );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		if ( ! self::is_current_user_service_account() ) {
			return $response;
		}

		$route = '';
		if ( is_object( $request ) && method_exists( $request, 'get_route' ) ) {
			$route = (string) $request->get_route();
		}

		if ( self::is_allowed_route( $route ) ) {
			return $response;
		}

		return new WP_Error(
			'axtolab_ai_connector_route_forbidden',
			__( 'The MCP Gateway service account may only access AI Connector REST endpoints.', 'axtolab-ai-connector' ),
			array( 'status' => 403 )
		);
	}

	/**
	 * Determine whether the current request is running as the generated service account.
	 *
	 * @return bool
	 */
	private static function is_current_user_service_account(): bool {
		$service_user_id = (int) get_option( 'axtolab_ai_connector_service_user_id', 0 );
		if ( $service_user_id <= 0 ) {
			return false;
		}

		return get_current_user_id() === $service_user_id;
	}

	/**
	 * Whether a route belongs to the supported connector namespace.
	 *
	 * @param string $route REST route, for example `/axtolab-ai-connector/v1/site-info`.
	 * @return bool
	 */
	public static function is_allowed_route( string $route ): bool {
		$route = '/' . ltrim( $route, '/' );

		return self::REST_NAMESPACE === $route || 0 === strpos( $route, self::REST_NAMESPACE . '/' );
	}
}

if ( ! class_exists( 'MCP_Gateway_Service_Account_Guard', false ) ) {
	class_alias( 'Axtolab_AI_Connector_Service_Account_Guard', 'MCP_Gateway_Service_Account_Guard' );
}
