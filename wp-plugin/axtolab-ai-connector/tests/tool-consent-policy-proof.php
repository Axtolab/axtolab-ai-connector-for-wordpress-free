<?php
/**
 * Standalone proof for the tool consent policy class.
 *
 * Run with: php wp-plugin/wp-mcp-gateway/tests/tool-consent-policy-proof.php
 *
 * @package WP_MCP_Gateway
 */

define( 'ABSPATH', __DIR__ . '/' );

$GLOBALS['tool_consent_test_options'] = array(
	'mcp_gateway_settings'           => array(),
	'axtolab_ai_connector_settings'  => array(),
);
$GLOBALS['tool_consent_connection_policy'] = array();

function get_option( $key, $default = false ) {
	return array_key_exists( $key, $GLOBALS['tool_consent_test_options'] ) ? $GLOBALS['tool_consent_test_options'][ $key ] : $default;
}

function update_option( $key, $value, $autoload = null ) {
	$GLOBALS['tool_consent_test_options'][ $key ] = $value;
	return true;
}

function apply_filters( $hook, $value ) {
	return $value;
}

function sanitize_key( $key ) {
	$key = strtolower( (string) $key );
	return preg_replace( '/[^a-z0-9_:-]/', '_', $key );
}

function wp_json_encode( $value ) {
	return json_encode( $value );
}

function wp_generate_password() {
	return 'preview-secret';
}

function tool_consent_assert( $condition, $message ) {
	if ( ! $condition ) {
		fwrite( STDERR, "FAIL: {$message}\n" );
		exit( 1 );
	}
}

class Axtolab_AI_Connector_Connections {
	public static function get_tool_consent_policy( $connection_id ) {
		return isset( $GLOBALS['tool_consent_connection_policy'][ $connection_id ] )
			? $GLOBALS['tool_consent_connection_policy'][ $connection_id ]
			: array();
	}
}

require_once dirname( __DIR__ ) . '/includes/class-mcp-gateway-config.php';
require_once dirname( __DIR__ ) . '/includes/class-mcp-gateway-tool-consent-policy.php';

$publish = Axtolab_AI_Connector_Tool_Consent_Policy::context_for_tool(
	'wp_publish_content',
	array( 'id' => 12, 'content_type' => 'post' )
);
tool_consent_assert( 'ask' === $publish['tier'], 'publish_content should default to ask.' );
tool_consent_assert( 'post:12:publish' === $publish['key'], 'publish_content should use the legacy confirmation key.' );

$GLOBALS['tool_consent_test_options']['mcp_gateway_settings'] = array(
	'tool_consent_policy' => array(
		'publish_content' => 'always',
		'trash_content'   => 'disallow',
	),
);
$GLOBALS['tool_consent_test_options']['axtolab_ai_connector_settings'] = $GLOBALS['tool_consent_test_options']['mcp_gateway_settings'];

$always = Axtolab_AI_Connector_Tool_Consent_Policy::context_for_tool(
	'wp_publish_content',
	array( 'id' => 12, 'content_type' => 'post' )
);
tool_consent_assert( 'always' === $always['tier'], 'Config should allow publish_content to be flipped to always.' );

$disallow = Axtolab_AI_Connector_Tool_Consent_Policy::context_for_tool(
	'wp_trash_content',
	array( 'id' => 12, 'content_type' => 'post' )
);
tool_consent_assert( 'disallow' === $disallow['tier'], 'Config should allow trash_content to be flipped to disallow.' );

$GLOBALS['tool_consent_connection_policy']['conn-one'] = array(
	'publish_content' => 'ask',
);

$connection_override = Axtolab_AI_Connector_Tool_Consent_Policy::context_for_tool(
	'wp_publish_content',
	array( 'id' => 12, 'content_type' => 'post' ),
	'conn-one'
);
tool_consent_assert( 'ask' === $connection_override['tier'], 'Connection consent override should win over the site default.' );

$connection_inherited = Axtolab_AI_Connector_Tool_Consent_Policy::context_for_tool(
	'wp_trash_content',
	array( 'id' => 12, 'content_type' => 'post' ),
	'conn-one'
);
tool_consent_assert( 'disallow' === $connection_inherited['tier'], 'Connection policy should inherit site default when no override is set.' );

$unknown = Axtolab_AI_Connector_Tool_Consent_Policy::context_for_tool(
	'wp_vendor_bulk_delete_products',
	array( 'product_ids' => array( 1, 2, 3 ) )
);
tool_consent_assert( 'ask' === $unknown['tier'], 'Unknown destructive-looking tools should fail safe to ask.' );

$read = Axtolab_AI_Connector_Tool_Consent_Policy::context_for_tool(
	'wp_list_content_types',
	array()
);
tool_consent_assert( 'always' === $read['tier'], 'Unmapped low-risk reads should default to always.' );

echo "tool consent policy proof passed\n";
