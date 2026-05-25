<?php
// phpcs:ignoreFile -- Standalone no-secret proof with WordPress stubs; not shipped in release zips.
/**
 * No-secret proof for free-core AI image generation and the optional opt-out gate.
 *
 * Run with: php wp-plugin/axtolab-ai-connector/tests/free-image-generation-gate-proof.php
 *
 * This proves the WordPress.org free package exposes BYOK AI image generation
 * by default, and that the retained filter can still opt a site out without
 * breaking normal media/stock-photo workflows.
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

	public function get_error_message() {
		return $this->message;
	}

	public function get_error_data( $code = '' ) {
		unset( $code );
		return $this->data;
	}
}

class WP_REST_Request {
	private $params = array();

	public function __construct( $method = 'GET', $route = '' ) {
		unset( $method, $route );
	}

	public function set_param( $key, $value ) {
		$this->params[ $key ] = $value;
	}

	public function get_param( $key ) {
		return $this->params[ $key ] ?? null;
	}
}

class WP_REST_Response {
	private $data;
	private $status;

	public function __construct( $data = null, $status = 200 ) {
		$this->data   = $data;
		$this->status = $status;
	}

	public function get_data() {
		return $this->data;
	}

	public function get_status() {
		return $this->status;
	}
}

class Axtolab_AI_Connector_Connections {
	public static function get_current_connection_id() {
		return null;
	}

	public static function set_current_connection_id( $connection_id ) {
		unset( $connection_id );
	}

	public static function get_capabilities( $connection_id ) {
		unset( $connection_id );
		return Axtolab_AI_Connector_Capabilities::DEFAULT_PRESET;
	}
}

$GLOBALS['image_gate_test_filters'] = array(
	'axtolab_ai_connector_image_generation_allowed'  => true,
);
$GLOBALS['image_gate_test_options'] = array(
	'axtolab_ai_connector_image_providers' => array(
		'google_imagen' => array( 'enabled' => true, 'api_key' => 'encrypted-google-key', 'model' => 'imagen-4.0-generate-001' ),
		'openai'        => array( 'enabled' => true, 'api_key' => 'encrypted-openai-key', 'model' => 'gpt-image-1', 'quality' => 'high' ),
		'unsplash'      => array( 'enabled' => true, 'access_key' => 'encrypted-unsplash-key' ),
		'pexels'        => array( 'enabled' => true, 'api_key' => 'encrypted-pexels-key' ),
	),
	'axtolab_ai_connector_settings'        => array(
		'oauth_capabilities' => array( 'read', 'create_edit', 'publish', 'media_manage', 'taxonomy', 'authors', 'seo', 'image', 'upload_portal' ),
	),
);

function apply_filters( $hook, $value ) {
	return array_key_exists( $hook, $GLOBALS['image_gate_test_filters'] ) ? $GLOBALS['image_gate_test_filters'][ $hook ] : $value;
}

function get_option( $key, $default = false ) {
	return array_key_exists( $key, $GLOBALS['image_gate_test_options'] ) ? $GLOBALS['image_gate_test_options'][ $key ] : $default;
}

function is_wp_error( $value ) {
	return $value instanceof WP_Error;
}

function __( $text, $domain = 'default' ) {
	unset( $domain );
	return $text;
}

function wp_salt( $scheme = 'auth' ) {
	unset( $scheme );
	return 'test-salt-for-no-secret-proof';
}

function get_current_user_id() {
	return 0;
}

function rest_url( $path = '' ) {
	return 'https://example.test/wp-json/' . ltrim( $path, '/' );
}

function wp_json_encode( $data, $flags = 0 ) {
	return json_encode( $data, $flags );
}

require_once __DIR__ . '/../includes/class-mcp-gateway-free-gates.php';
require_once __DIR__ . '/../includes/class-mcp-gateway-capabilities.php';
require_once __DIR__ . '/../includes/class-mcp-gateway-image-providers.php';
require_once __DIR__ . '/../includes/class-mcp-gateway-response.php';
require_once __DIR__ . '/../includes/class-mcp-gateway-rest.php';
require_once __DIR__ . '/../includes/class-mcp-gateway-mcp-transport.php';

function image_gate_assert( $condition, $message ) {
	if ( ! $condition ) {
		fwrite( STDERR, 'FAIL: ' . $message . PHP_EOL );
		exit( 1 );
	}
}

function image_gate_assert_error( $value, $code, $message ) {
	image_gate_assert( $value instanceof WP_Error, $message . ' should return WP_Error.' );
	image_gate_assert( $code === $value->get_error_code(), $message . ' should return ' . $code . '.' );
}

// Free core exposes BYOK AI image generation by default.
image_gate_assert( true === Axtolab_AI_Connector_Free_Gates::check_image_generation_allowed(), 'Free core should allow BYOK AI image generation by default.' );
image_gate_assert( array( 'google_imagen', 'openai' ) === Axtolab_AI_Connector_Image_Providers::get_enabled_providers( 'generation' ), 'Free provider listing should expose configured generation providers.' );

// MCP-visible capabilities include wp_generate_image; normal media and stock
// photo paths remain available under the existing image/media capability groups.
$tools = Axtolab_AI_Connector_Capabilities::tools_for( Axtolab_AI_Connector_Capabilities::DEFAULT_PRESET );
image_gate_assert( in_array( 'wp_generate_image', $tools, true ), 'Free MCP tools should expose wp_generate_image.' );
image_gate_assert( in_array( 'wp_search_stock_photos', $tools, true ), 'Free MCP tools should preserve stock photo search.' );
image_gate_assert( in_array( 'wp_import_stock_photo', $tools, true ), 'Free MCP tools should preserve stock photo import.' );
image_gate_assert( in_array( 'wp_upload_media_from_url', $tools, true ), 'Free MCP tools should preserve normal media upload.' );
image_gate_assert( in_array( 'wp_search_media', $tools, true ), 'Free MCP tools should preserve normal media search.' );

$capabilities_response = Axtolab_AI_Connector_REST::handle_connection_capabilities( new WP_REST_Request( 'GET', '/axtolab-ai-connector/v1/connection/capabilities' ) );
$capabilities_payload  = $capabilities_response->get_data();
$capability_tools      = $capabilities_payload['data']['allowed_tools'];
image_gate_assert( in_array( 'wp_generate_image', $capability_tools, true ), 'Connection capabilities should expose wp_generate_image in Free.' );
image_gate_assert( in_array( 'wp_search_media', $capability_tools, true ), 'Connection capabilities should preserve normal media search.' );
image_gate_assert( in_array( 'wp_search_stock_photos', $capability_tools, true ), 'Connection capabilities should preserve stock photo search.' );
image_gate_assert( array( 'unsplash', 'pexels' ) === Axtolab_AI_Connector_Image_Providers::get_enabled_providers( 'stock' ), 'Free provider listing should preserve enabled stock providers.' );

$build_rest_request = new ReflectionMethod( 'Axtolab_AI_Connector_MCP_Transport', 'build_rest_request' );
@$build_rest_request->setAccessible( true );

$media_upload_request = $build_rest_request->invoke(
	null,
	'wp_upload_media_from_url',
	array(
		'url'         => 'https://example.test/image.jpg',
		'alt_text'    => 'Accessible alt text',
		'mime_type'   => 'application/x-php',
		'post_status' => 'publish',
		'provider'    => 'openai',
		'api_key'     => 'must-not-pass-through',
	)
);
image_gate_assert( 'https://example.test/image.jpg' === $media_upload_request->get_param( 'url' ), 'MCP media upload dispatcher should preserve the canonical url param.' );
image_gate_assert( null === $media_upload_request->get_param( 'mime_type' ), 'MCP media upload dispatcher should not pass caller-supplied MIME bypass params.' );
image_gate_assert( null === $media_upload_request->get_param( 'post_status' ), 'MCP media upload dispatcher should not pass post-status bypass params.' );
image_gate_assert( null === $media_upload_request->get_param( 'provider' ), 'MCP media upload dispatcher should not pass image-provider params.' );
image_gate_assert( null === $media_upload_request->get_param( 'api_key' ), 'MCP media upload dispatcher should not pass secret/provider-key params.' );

$image_tool_request = $build_rest_request->invoke(
	null,
	'wp_generate_image',
	array(
		'prompt'      => 'A product hero image',
		'provider'    => 'openai',
		'api_key'     => 'must-not-pass-through',
		'url'         => 'https://example.test/not-a-generation-input.jpg',
		'mime_type'   => 'image/svg+xml',
		'post_status' => 'publish',
	)
);
image_gate_assert( 'A product hero image' === $image_tool_request->get_param( 'prompt' ), 'MCP image generation dispatcher should preserve the canonical prompt param.' );
image_gate_assert( 'openai' === $image_tool_request->get_param( 'provider' ), 'MCP image generation dispatcher should preserve the canonical provider param.' );
image_gate_assert( null === $image_tool_request->get_param( 'api_key' ), 'MCP image generation dispatcher should not pass secret/provider-key params.' );
image_gate_assert( null === $image_tool_request->get_param( 'url' ), 'MCP image generation dispatcher should not pass media-url alias params.' );
image_gate_assert( null === $image_tool_request->get_param( 'mime_type' ), 'MCP image generation dispatcher should not pass MIME alias params.' );
image_gate_assert( null === $image_tool_request->get_param( 'post_status' ), 'MCP image generation dispatcher should not pass publish-status alias params.' );

// MCP tools/list exposes wp_generate_image in the free core.
$tools_list          = new ReflectionMethod( 'Axtolab_AI_Connector_MCP_Transport', 'handle_tools_list' );
@$tools_list->setAccessible( true );
$tools_list_response = $tools_list->invoke( null, 'proof-tools-list' );
$mcp_tool_names      = array_map(
	static function ( $tool ) {
		return $tool['name'];
	},
	$tools_list_response['result']['tools']
);
image_gate_assert( in_array( 'wp_generate_image', $mcp_tool_names, true ), 'MCP tools/list should expose wp_generate_image in Free.' );
image_gate_assert( in_array( 'wp_search_stock_photos', $mcp_tool_names, true ), 'MCP tools/list should preserve stock photo search.' );
image_gate_assert( in_array( 'wp_upload_media_from_url', $mcp_tool_names, true ), 'MCP tools/list should preserve normal media upload.' );

// An explicit opt-out filter still hides/rejects generation without affecting
// stock-photo or normal media workflows.
$GLOBALS['image_gate_test_filters']['axtolab_ai_connector_image_generation_allowed'] = false;
image_gate_assert_error(
	Axtolab_AI_Connector_Free_Gates::check_image_generation_allowed(),
	'image_generation_disabled',
	'Opt-out AI image-generation gate'
);
image_gate_assert( array() === Axtolab_AI_Connector_Image_Providers::get_enabled_providers( 'generation' ), 'Opt-out provider listing must hide generation providers.' );

$opt_out_tools = Axtolab_AI_Connector_Capabilities::tools_for( Axtolab_AI_Connector_Capabilities::DEFAULT_PRESET );
image_gate_assert( ! in_array( 'wp_generate_image', $opt_out_tools, true ), 'Opt-out MCP tools must not expose wp_generate_image.' );
image_gate_assert( in_array( 'wp_search_stock_photos', $opt_out_tools, true ), 'Opt-out should preserve stock photo search.' );

// Direct connector REST cannot bypass the opt-out gate, even with modified params.
$rest_request = new WP_REST_Request( 'POST', '/axtolab-ai-connector/v1/image/generate' );
$rest_request->set_param( 'prompt', 'A product hero image' );
$rest_request->set_param( 'provider', 'openai' );
$rest_request->set_param( 'api_key', 'must-not-pass-through' );
$rest_request->set_param( 'post_status', 'publish' );
$rest_response = Axtolab_AI_Connector_REST::handle_generate_image( $rest_request );
image_gate_assert( 403 === $rest_response->get_status(), 'Direct REST image generation attempt should be forbidden after opt-out.' );
image_gate_assert( 'image_generation_disabled' === $rest_response->get_data()['error']['code'], 'Direct REST generation attempt should return the opt-out image-generation gate error.' );

// MCP tools/list hides wp_generate_image and MCP tools/call rejects direct calls
// after opt-out.
$opt_out_tools_list_response = $tools_list->invoke( null, 'proof-tools-list-opt-out' );
$opt_out_mcp_tool_names      = array_map(
	static function ( $tool ) {
		return $tool['name'];
	},
	$opt_out_tools_list_response['result']['tools']
);
image_gate_assert( ! in_array( 'wp_generate_image', $opt_out_mcp_tool_names, true ), 'MCP tools/list must not expose wp_generate_image after opt-out.' );
image_gate_assert( in_array( 'wp_search_stock_photos', $opt_out_mcp_tool_names, true ), 'MCP tools/list should preserve stock photo search after opt-out.' );
image_gate_assert( in_array( 'wp_upload_media_from_url', $opt_out_mcp_tool_names, true ), 'MCP tools/list should preserve normal media upload after opt-out.' );

$tools_call          = new ReflectionMethod( 'Axtolab_AI_Connector_MCP_Transport', 'handle_tools_call' );
@$tools_call->setAccessible( true );
$tools_call_response = $tools_call->invoke(
	null,
	array(
		'params' => array(
			'name'      => 'wp_generate_image',
			'arguments' => array(
				'prompt'   => 'A product hero image',
				'provider' => 'openai',
			),
		),
	),
	'proof-tools-call'
);
image_gate_assert( true === $tools_call_response['result']['isError'], 'MCP tools/call must reject wp_generate_image after opt-out.' );

echo "free image-generation BYOK proof ok\n";
