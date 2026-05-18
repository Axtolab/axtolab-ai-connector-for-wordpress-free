<?php
// phpcs:ignoreFile -- Standalone no-secret proof with WordPress stubs; not shipped in release zips.
/**
 * No-secret proof for the service-account REST namespace guard.
 *
 * Run with: php wp-plugin/axtolab-ai-connector/tests/service-account-namespace-guard-proof.php
 *
 * This is intentionally a small WordPress-function stub proof because the repo
 * does not currently ship a runnable PHP unit-test harness. It verifies the
 * guard's route decisions and fail-closed behavior without real credentials.
 *
 * @package WP_MCP_Gateway
 */

define( 'ABSPATH', __DIR__ . '/../' );

class WP_Error {
	public $code;
	public $message;
	public $data;

	public function __construct( $code, $message, $data = array() ) {
		$this->code    = $code;
		$this->message = $message;
		$this->data    = $data;
	}

	public function get_error_code() {
		return $this->code;
	}

	public function get_error_data() {
		return $this->data;
	}
}

class Guard_Test_Request {
	private $route;

	public function __construct( $route ) {
		$this->route = $route;
	}

	public function get_route() {
		return $this->route;
	}
}

$GLOBALS['guard_test_options']         = array( 'axtolab_ai_connector_service_user_id' => 42 );
$GLOBALS['guard_test_current_user_id'] = 0;

function get_option( $key, $default = false ) {
	return array_key_exists( $key, $GLOBALS['guard_test_options'] ) ? $GLOBALS['guard_test_options'][ $key ] : $default;
}

function get_current_user_id() {
	return $GLOBALS['guard_test_current_user_id'];
}

function is_wp_error( $value ) {
	return $value instanceof WP_Error;
}

function __( $text, $domain = 'default' ) {
	unset( $domain );
	return $text;
}

require_once __DIR__ . '/../includes/class-mcp-gateway-service-account-guard.php';

function guard_assert( $condition, $message ) {
	if ( ! $condition ) {
		fwrite( STDERR, 'FAIL: ' . $message . PHP_EOL );
		exit( 1 );
	}
}

function guard_check_route( $route ) {
	return Axtolab_AI_Connector_Service_Account_Guard::guard_rest_request( null, array(), new Guard_Test_Request( $route ) );
}

// Route classifier allows only the exact connector namespace or children.
guard_assert( Axtolab_AI_Connector_Service_Account_Guard::is_allowed_route( '/axtolab-ai-connector/v1' ), 'Exact connector namespace should be allowed.' );
guard_assert( Axtolab_AI_Connector_Service_Account_Guard::is_allowed_route( '/axtolab-ai-connector/v1/site-info' ), 'Connector child route should be allowed.' );
guard_assert( ! Axtolab_AI_Connector_Service_Account_Guard::is_allowed_route( '/axtolab-ai-connector/v10/site-info' ), 'Lookalike namespace must not be allowed.' );
guard_assert( ! Axtolab_AI_Connector_Service_Account_Guard::is_allowed_route( '/wp/v2/posts' ), 'Core REST namespace must not be allowed for service account.' );

// Normal users are unaffected, even on core REST routes.
$GLOBALS['guard_test_current_user_id'] = 7;
guard_assert( null === guard_check_route( '/wp/v2/posts' ), 'Non-service users should pass through core REST routes.' );

// The generated service account can still use supported connector routes.
$GLOBALS['guard_test_current_user_id'] = 42;
guard_assert( null === guard_check_route( '/axtolab-ai-connector/v1/site-info' ), 'Service account should pass connector REST route.' );
guard_assert( null === guard_check_route( '/axtolab-ai-connector/v1/mcp' ), 'Service account should pass MCP transport route.' );

// The generated service account is blocked from direct core REST bypass paths.
$blocked = guard_check_route( '/wp/v2/posts' );
guard_assert( $blocked instanceof WP_Error, 'Service account direct core REST route should be blocked.' );
guard_assert( 'axtolab_ai_connector_route_forbidden' === $blocked->get_error_code(), 'Blocked route should return the namespace guard error code.' );
guard_assert( 403 === $blocked->get_error_data()['status'], 'Blocked route should return HTTP 403.' );

echo "service account namespace guard proof ok\n";
