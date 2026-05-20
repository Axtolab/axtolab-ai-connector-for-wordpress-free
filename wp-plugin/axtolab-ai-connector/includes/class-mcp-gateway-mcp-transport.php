<?php
/**
 * Streamable HTTP MCP Transport.
 *
 * Registers a single endpoint at /axtolab-ai-connector/v1/mcp that speaks the MCP
 * JSON-RPC protocol over HTTPS with Bearer token authentication. Remote MCP
 * clients (ChatGPT, Claude.ai, etc.) connect here instead of using a local
 * stdio transport.
 *
 * All tool calls are dispatched to the existing REST handlers in
 * Axtolab_AI_Connector_REST via rest_do_request(), so 100% of validation, policy
 * enforcement, and response formatting is reused.
 *
 * @package WP_MCP_Gateway
 * @since   0.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Axtolab_AI_Connector_MCP_Transport', false ) ) :
class Axtolab_AI_Connector_MCP_Transport {

	private const NS = 'axtolab-ai-connector/v1';

	/**
	 * MCP protocol versions this server speaks, most recent first. The
	 * `initialize` handler echoes back whichever version the client requested
	 * if it's in this list, otherwise falls back to the latest (index 0).
	 *
	 * Why negotiate rather than hardcode a single version: Claude Desktop and
	 * Claude Web silently reject the entire tool list when the server's
	 * protocolVersion is older than the client's. ChatGPT is more lenient.
	 * Royal MCP shipped a one-line fix for the same bug 2026-05; the broader
	 * fix is to negotiate so future client bumps don't break us again.
	 *
	 * @var string[]
	 */
	private const SUPPORTED_PROTOCOL_VERSIONS = array(
		'2025-11-25',
		'2025-06-18',
		'2025-03-26',
	);

	/**
	 * Rate limit: max requests per window.
	 */
	private const RATE_LIMIT_MAX = 100;

	/**
	 * Rate limit window in seconds.
	 */
	private const RATE_LIMIT_WINDOW = 60;

	/**
	 * Map of capability group key => array of tool names.
	 * 'read' is always included for all connections.
	 *
	 * @deprecated Use Axtolab_AI_Connector_Capabilities::GROUPS instead.
	 *             Kept here temporarily; do not add new entries.
	 */
	private const CAPABILITY_TOOLS = array(
		'read'          => array(
			'wp_getting_started',
			'wp_site_info',
			'wp_list_content_types',
			'wp_find_content',
			'wp_get_content',
			'wp_list_revisions',
			'wp_list_authors',
			'wp_list_terms',
			'wp_search_media',
			'wp_get_media',
			'wp_get_yoast_analysis',
			'wp_get_yoast_head_preview',
			'wp_get_preview_link',
		),
		'create_edit'   => array(
			'wp_create_draft',
			'wp_update_content',
			'wp_clone_content',
			'wp_request_review',
		),
		'publish'       => array(
			'wp_publish_content',
		),
		'trash_restore' => array(
			'wp_trash_content',
			'wp_restore_content',
			'wp_restore_revision',
		),
		'media_manage'  => array(
			'wp_upload_media_from_url',
			'wp_update_media',
			'wp_set_featured_image',
			'wp_insert_inline_image',
			'wp_replace_inline_image',
			'wp_remove_inline_image',
		),
		'taxonomy'      => array(
			'wp_create_term',
			'wp_assign_terms',
		),
		'authors'       => array(
			'wp_assign_author',
		),
		'seo'           => array(
			'wp_update_yoast_metadata',
		),
		'image'         => array(
			'wp_generate_image',
			'wp_search_stock_photos',
			'wp_import_stock_photo',
			'wp_list_image_providers',
			'wp_confirm_image',
		),
		'upload_portal' => array(
			'wp_create_upload_session',
			'wp_get_upload_session',
		),
	);

	/**
	 * Default capabilities for new connections: "Standard" preset.
	 * Everything except trash_restore.
	 */
	public const DEFAULT_CAPABILITIES = array(
		'read',
		'create_edit',
		'publish',
		'media_manage',
		'taxonomy',
		'authors',
		'seo',
		'image',
		'upload_portal',
	);

	/**
	 * The auth type of the current request: 'bearer' or 'oauth'.
	 * Set during permission_callback, used by tool filtering.
	 */
	private static $current_auth_type = null;

	/**
	 * Bootstrap the transport — call from plugins_loaded or rest_api_init.
	 */
	public static function bootstrap(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
		add_filter( 'rest_pre_serve_request', array( __CLASS__, 'add_cors_headers' ), 10, 4 );
		add_filter( 'rest_post_dispatch', array( __CLASS__, 'add_www_authenticate_header' ), 10, 3 );
	}

	/**
	 * Register the /mcp endpoint for POST, GET, DELETE, and OPTIONS.
	 */
	public static function register_routes(): void {
		register_rest_route(
			self::NS,
			'/mcp',
			array(
				array(
					'methods'             => array( 'POST', 'GET', 'DELETE' ),
					'callback'            => array( __CLASS__, 'handle_request' ),
					'permission_callback' => array( __CLASS__, 'check_bearer_auth' ),
				),
			)
		);

		// OPTIONS preflight — public, no auth.
		register_rest_route(
			self::NS,
			'/mcp',
			array(
				'methods'             => 'OPTIONS',
				'callback'            => function () {
					$response = new WP_REST_Response( null, 204 );
					$response->header( 'Access-Control-Allow-Origin', '*' );
					$response->header( 'Access-Control-Allow-Methods', 'GET, POST, DELETE, OPTIONS' );
					$response->header( 'Access-Control-Allow-Headers', 'Authorization, Content-Type, Mcp-Session-Id, Accept' );
					$response->header( 'Access-Control-Expose-Headers', 'Mcp-Session-Id' );
					return $response;
				},
				'permission_callback' => '__return_true',
				'show_in_index'       => false,
			)
		);
	}

	// =========================================================================
	// Authentication
	// =========================================================================

	/**
	 * Verify Bearer token and set the current user to the service account.
	 *
	 * @param WP_REST_Request $request The incoming request.
	 * @return bool|WP_Error
	 */
	public static function check_bearer_auth( WP_REST_Request $request ) {
		$auth_header           = $request->get_header( 'authorization' );
		$resource_metadata_url = rest_url( 'axtolab-ai-connector/v1/oauth/metadata/resource' );

		if ( ! $auth_header || ! preg_match( '/Bearer\s+(.+)/i', $auth_header, $matches ) ) {
			return new WP_Error(
				'unauthorized',
				'Bearer token required.',
				array(
					'status'  => 401,
					'headers' => array(
						'WWW-Authenticate' => 'Bearer resource_metadata="' . $resource_metadata_url . '"',
					),
				)
			);
		}

		$provided_token = trim( $matches[1] );

		// Rate limit per token hash prefix.
		$rate_key = 'axtolab_ai_connector_rate_' . substr( md5( $provided_token ), 0, 16 );
		$count    = (int) get_transient( $rate_key );
		if ( $count >= self::RATE_LIMIT_MAX ) {
			return new WP_Error( 'rate_limited', 'Too many requests. Try again later.', array( 'status' => 429 ) );
		}
		set_transient( $rate_key, $count + 1, self::RATE_LIMIT_WINDOW );

		// Try bearer token first.
		if ( Axtolab_AI_Connector_Bearer_Auth::verify_token( $provided_token ) ) {
			$service_user_id = (int) get_option( 'axtolab_ai_connector_service_user_id', 0 );
			if ( ! $service_user_id || ! get_user_by( 'id', $service_user_id ) ) {
				return new WP_Error( 'server_error', 'Service account not found.', array( 'status' => 500 ) );
			}
			wp_set_current_user( $service_user_id );
			self::$current_auth_type = 'bearer';

			$multisite_allowed = Axtolab_AI_Connector_Free_Gates::check_multisite_allowed();
			if ( is_wp_error( $multisite_allowed ) ) {
				return $multisite_allowed;
			}

			return true;
		}

		// Try OAuth token as fallback.
		if ( Axtolab_AI_Connector_OAuth::verify_access_token( $provided_token ) ) {
			$service_user_id = (int) get_option( 'axtolab_ai_connector_service_user_id', 0 );
			if ( ! $service_user_id || ! get_user_by( 'id', $service_user_id ) ) {
				return new WP_Error( 'server_error', 'Service account not found.', array( 'status' => 500 ) );
			}
			wp_set_current_user( $service_user_id );
			self::$current_auth_type = 'oauth';

			$multisite_allowed = Axtolab_AI_Connector_Free_Gates::check_multisite_allowed();
			if ( is_wp_error( $multisite_allowed ) ) {
				return $multisite_allowed;
			}

			// Update last_active for the OAuth connection.
			// The application_password_did_authenticate hook only fires for
			// app-password auth, so OAuth connections need an explicit touch().
			if ( class_exists( 'Axtolab_AI_Connector_Connections' ) ) {
				Axtolab_AI_Connector_Connections::touch( Axtolab_AI_Connector_Connections::OAUTH_CONNECTION_ID );
			}

			return true;
		}

		return new WP_Error(
			'unauthorized',
			'Invalid token.',
			array(
				'status'  => 401,
				'headers' => array(
					'WWW-Authenticate' => 'Bearer resource_metadata="' . $resource_metadata_url . '"',
				),
			)
		);
	}

	// =========================================================================
	// Request routing
	// =========================================================================

	/**
	 * Main entry point — route by HTTP method.
	 *
	 * @param WP_REST_Request $request The incoming request.
	 * @return WP_REST_Response
	 */
	public static function handle_request( WP_REST_Request $request ): WP_REST_Response {
		switch ( $request->get_method() ) {
			case 'POST':
				return self::handle_post( $request );
			case 'DELETE':
				return self::handle_delete( $request );
			default:
				// GET — SSE not yet supported.
				$response = new WP_REST_Response(
					array( 'error' => 'SSE streams not yet supported. Use POST for request/response.' ),
					405
				);
				return $response;
		}
	}

	// =========================================================================
	// POST — JSON-RPC request/response
	// =========================================================================

	/**
	 * Handle a JSON-RPC POST request.
	 *
	 * @param WP_REST_Request $request The incoming request.
	 * @return WP_REST_Response
	 */
	private static function handle_post( WP_REST_Request $request ): WP_REST_Response {
		$raw_body = $request->get_body();

		if ( empty( $raw_body ) ) {
			return self::rpc_response( self::rpc_error( null, -32700, 'Empty body' ), 400 );
		}

		$data = json_decode( $raw_body, true );
		if ( json_last_error() !== JSON_ERROR_NONE ) {
			return self::rpc_response( self::rpc_error( null, -32700, 'Invalid JSON' ), 400 );
		}

		$id     = $data['id'] ?? null;
		$method = $data['method'] ?? null;

		// Session management.
		$session_id = $request->get_header( 'mcp-session-id' );
		if ( 'initialize' === $method || empty( $session_id ) ) {
			$session_id = wp_generate_uuid4();
		}

		switch ( $method ) {
			case 'initialize':
				$result = self::handle_initialize( $id, $session_id, isset( $data['params'] ) && is_array( $data['params'] ) ? $data['params'] : array() );
				break;

			case 'tools/list':
				$result = self::handle_tools_list( $id );
				break;

			case 'tools/call':
				$result = self::handle_tools_call( $data, $id );
				break;

			case 'notifications/initialized':
			case 'notifications/cancelled':
				$response = new WP_REST_Response( null, 204 );
				$response->header( 'Mcp-Session-Id', $session_id );
				return $response;

			default:
				if ( null === $id ) {
					// Unknown notification — acknowledge silently.
					$response = new WP_REST_Response( null, 204 );
					$response->header( 'Mcp-Session-Id', $session_id );
					return $response;
				}
				$result = self::rpc_error( $id, -32601, "Method not found: {$method}" );
		}

		return self::rpc_response( $result, 200, $session_id );
	}

	// =========================================================================
	// DELETE — session termination
	// =========================================================================

	/**
	 * Handle DELETE (session termination).
	 *
	 * @param WP_REST_Request $request The incoming request.
	 * @return WP_REST_Response
	 */
	private static function handle_delete( WP_REST_Request $request ): WP_REST_Response {
		$session_id = $request->get_header( 'mcp-session-id' );

		if ( empty( $session_id ) ) {
			return new WP_REST_Response( array( 'error' => 'Mcp-Session-Id required.' ), 400 );
		}

		return new WP_REST_Response( null, 204 );
	}

	// =========================================================================
	// MCP protocol handlers
	// =========================================================================

	/**
	 * Handle 'initialize'.
	 *
	 * Negotiates the protocol version: echoes back the client's requested
	 * `protocolVersion` if we support it, otherwise responds with our latest
	 * supported version. Claude Desktop / Claude Web reject the tool list
	 * when this is older than what the client sent.
	 *
	 * @param mixed               $id         JSON-RPC request id.
	 * @param string              $session_id Session id assigned for this client.
	 * @param array<string,mixed> $params    The `initialize` request params.
	 * @return array<string,mixed>
	 */
	private static function handle_initialize( $id, string $session_id, array $params = array() ): array {
		$client_version = isset( $params['protocolVersion'] ) ? (string) $params['protocolVersion'] : '';
		$negotiated     = in_array( $client_version, self::SUPPORTED_PROTOCOL_VERSIONS, true )
			? $client_version
			: self::SUPPORTED_PROTOCOL_VERSIONS[0];

		return array(
			'jsonrpc' => '2.0',
			'id'      => $id,
			'result'  => array(
				'protocolVersion' => $negotiated,
				'serverInfo'      => array(
					'name'    => 'Axtolab AI Connector - ' . get_bloginfo( 'name' ),
					'version' => defined( 'AXTOLAB_AI_CONNECTOR_VERSION' ) ? AXTOLAB_AI_CONNECTOR_VERSION : '0.2.0',
				),
				'capabilities'    => array(
					'tools' => new stdClass(),
				),
			),
		);
	}

	/**
	 * Handle 'tools/list'.
	 */
	private static function handle_tools_list( $id ): array {
		$all_tools = self::get_tool_definitions();
		$allowed   = self::get_allowed_tools();
		$filtered  = array_values(
			array_filter(
				$all_tools,
				function ( $tool ) use ( $allowed ) {
					return in_array( $tool['name'], $allowed, true );
				}
			)
		);

		// Add securitySchemes when OAuth is enabled — tells ChatGPT every tool requires OAuth.
		$settings = get_option( 'axtolab_ai_connector_settings', array() );
		if ( ! empty( $settings['oauth_enabled'] ) ) {
			$security_schemes = array(
				array(
					'type'   => 'oauth2',
					'scopes' => array( 'mcp:read', 'mcp:write' ),
				),
			);
			foreach ( $filtered as &$tool ) {
				$tool['securitySchemes'] = $security_schemes;
			}
			unset( $tool );
		}

		return array(
			'jsonrpc' => '2.0',
			'id'      => $id,
			'result'  => array(
				'tools' => $filtered,
			),
		);
	}

	/**
	 * Handle 'tools/call'.
	 */
	private static function handle_tools_call( array $data, $id ): array {
		$params    = $data['params'] ?? array();
		$tool_name = $params['name'] ?? '';
		$arguments = $params['arguments'] ?? array();

		// Check capability-based access before dispatching.
		if ( ! self::is_tool_allowed( $tool_name ) ) {
			return array(
				'jsonrpc' => '2.0',
				'id'      => $id,
				'result'  => array(
					'content' => array(
						array(
							'type' => 'text',
							'text' => 'Authentication required: insufficient permissions for this tool.',
						),
					),
					'_meta'   => array(
						'mcp/www_authenticate' => array(
							'Bearer resource_metadata="' . rest_url( 'axtolab-ai-connector/v1/oauth/metadata/resource' ) . '", error="insufficient_scope", error_description="OAuth authentication required to use this tool"',
						),
					),
					'isError' => true,
				),
			);
		}

		try {
			$result = self::dispatch_tool( $tool_name, $arguments );

			return array(
				'jsonrpc' => '2.0',
				'id'      => $id,
				'result'  => array(
					'content' => array(
						array(
							'type' => 'text',
							'text' => is_string( $result ) ? $result : wp_json_encode( $result, JSON_PRETTY_PRINT ),
						),
					),
				),
			);
		} catch ( Exception $e ) {
			return array(
				'jsonrpc' => '2.0',
				'id'      => $id,
				'result'  => array(
					'content' => array(
						array(
							'type' => 'text',
							'text' => wp_json_encode(
								array(
									'success' => false,
									'error'   => array(
										'code'    => $e->getCode(),
										'message' => $e->getMessage(),
									),
								),
								JSON_PRETTY_PRINT
							),
						),
					),
					'isError' => true,
				),
			);
		}
	}

	// =========================================================================
	// Tool dispatch — maps tool names to internal REST requests
	// =========================================================================

	/**
	 * Dispatch a tool call to the appropriate internal REST endpoint.
	 *
	 * @param string $tool_name The MCP tool name.
	 * @param array  $args      The tool arguments.
	 * @return mixed The response data.
	 * @throws Exception On dispatch or execution failure.
	 */
	private static function dispatch_tool( string $tool_name, array $args ) {
		// Handle destructive tools with confirmation flow.
		$destructive_tools = array(
			'wp_publish_content'  => array(
				'action'  => 'publish_content',
				'key_tpl' => '{ct}:{id}:publish',
			),
			'wp_trash_content'    => array(
				'action'  => 'trash_content',
				'key_tpl' => '{ct}:{id}:trash',
			),
			'wp_restore_content'  => array(
				'action'  => 'restore_content',
				'key_tpl' => '{ct}:{id}:restore',
			),
			'wp_restore_revision' => array(
				'action'  => 'restore_revision',
				'key_tpl' => '{ct}:{id}:revision:{rev}',
			),
		);

		if ( isset( $destructive_tools[ $tool_name ] ) ) {
			$conf = $destructive_tools[ $tool_name ];
			$ct   = $args['content_type'] ?? 'post';
			$id   = intval( $args['id'] ?? 0 );
			$rev  = intval( $args['revision_id'] ?? 0 );
			$key  = str_replace( array( '{ct}', '{id}', '{rev}' ), array( $ct, $id, $rev ), $conf['key_tpl'] );

			if ( empty( $args['confirmation_token'] ) ) {
				return Axtolab_AI_Connector_Confirmation::issue( $conf['action'], $key, $args );
			}

			Axtolab_AI_Connector_Confirmation::consume( $args['confirmation_token'], $conf['action'], $key );
		}

		$request = self::build_rest_request( $tool_name, $args );

		$response = rest_do_request( $request );

		if ( $response->is_error() ) {
			$error      = $response->as_error();
			$error_data = $error->get_error_data();
			$status     = is_array( $error_data ) && isset( $error_data['status'] ) ? (int) $error_data['status'] : 500;
			throw new Exception( esc_html( $error->get_error_message() ), absint( $status ) );
		}

		return $response->get_data();
	}

	/**
	 * Build an internal WP_REST_Request from tool name and arguments.
	 *
	 * @param string $tool_name The MCP tool name.
	 * @param array  $args      The tool arguments.
	 * @return WP_REST_Request
	 * @throws Exception If the tool name is unknown.
	 */
	private static function build_rest_request( string $tool_name, array $args ): WP_REST_Request {
		$ns = '/' . self::NS;

		switch ( $tool_name ) {
			// -- Site & Discovery --

			case 'wp_getting_started':
			case 'wp_site_info':
				return new WP_REST_Request( 'GET', $ns . '/site-info' );

			case 'wp_list_content_types':
				return new WP_REST_Request( 'GET', $ns . '/content-types' );

			// -- Content --

			case 'wp_find_content':
				$request = new WP_REST_Request( 'GET', $ns . '/content' );
				self::set_params(
					$request,
					$args,
					array(
						'content_type',
						'search',
						'status',
						'author',
						'page',
						'per_page',
						'parent_term_slug',
						'parent_taxonomy',
						'solutions_only',
					)
				);
				return $request;

			case 'wp_get_content':
				$request = new WP_REST_Request( 'GET', $ns . '/content/' . intval( $args['id'] ?? 0 ) );
				$request->set_param( 'content_type', $args['content_type'] ?? 'post' );
				return $request;

			case 'wp_create_draft':
				$request = new WP_REST_Request( 'POST', $ns . '/content' );
				self::set_params(
					$request,
					$args,
					array(
						'content_type',
						'title',
						'content',
						'excerpt',
						'slug',
						'content_format',
						'author',
						'date',
					)
				);
				if ( isset( $args['terms'] ) ) {
					$request->set_param( 'terms', $args['terms'] );
				}
				if ( isset( $args['yoast_meta'] ) ) {
					$request->set_param( 'yoast_meta', $args['yoast_meta'] );
				}
				return $request;

			case 'wp_update_content':
				$request = new WP_REST_Request( 'PATCH', $ns . '/content/' . intval( $args['id'] ?? 0 ) );
				$request->set_param( 'content_type', $args['content_type'] ?? 'post' );
				if ( isset( $args['patch'] ) ) {
					$request->set_body( wp_json_encode( array( 'patch' => $args['patch'] ) ) );
					$request->set_header( 'content-type', 'application/json' );
				}
				if ( isset( $args['content_format'] ) ) {
					$request->set_param( 'content_format', $args['content_format'] );
				}
				return $request;

			case 'wp_publish_content':
				$request = new WP_REST_Request( 'POST', $ns . '/content/' . intval( $args['id'] ?? 0 ) . '/publish' );
				$request->set_param( 'content_type', $args['content_type'] ?? 'post' );
				if ( isset( $args['date'] ) ) {
					$request->set_param( 'date', $args['date'] );
				}
				return $request;

			case 'wp_trash_content':
				$request = new WP_REST_Request( 'POST', $ns . '/content/' . intval( $args['id'] ?? 0 ) . '/trash' );
				$request->set_param( 'content_type', $args['content_type'] ?? 'post' );
				return $request;

			case 'wp_restore_content':
				$request = new WP_REST_Request( 'POST', $ns . '/content/' . intval( $args['id'] ?? 0 ) . '/restore' );
				$request->set_param( 'content_type', $args['content_type'] ?? 'post' );
				return $request;

			case 'wp_clone_content':
				$request = new WP_REST_Request( 'POST', $ns . '/content/' . intval( $args['id'] ?? 0 ) . '/clone' );
				$request->set_param( 'content_type', $args['content_type'] ?? 'post' );
				if ( isset( $args['title'] ) ) {
					$request->set_param( 'title', $args['title'] );
				}
				return $request;

			// -- Revisions --

			case 'wp_list_revisions':
				$request = new WP_REST_Request( 'GET', $ns . '/content/' . intval( $args['id'] ?? 0 ) . '/revisions' );
				$request->set_param( 'content_type', $args['content_type'] ?? 'post' );
				return $request;

			case 'wp_restore_revision':
				$id      = intval( $args['id'] ?? 0 );
				$rev_id  = intval( $args['revision_id'] ?? 0 );
				$request = new WP_REST_Request( 'POST', $ns . '/content/' . $id . '/revisions/' . $rev_id . '/restore' );
				$request->set_param( 'content_type', $args['content_type'] ?? 'post' );
				return $request;

			// -- Authors --

			case 'wp_list_authors':
				return new WP_REST_Request( 'GET', $ns . '/authors' );

			case 'wp_assign_author':
				$request = new WP_REST_Request( 'POST', $ns . '/content/' . intval( $args['id'] ?? 0 ) . '/author' );
				$request->set_param( 'content_type', $args['content_type'] ?? 'post' );
				$request->set_param( 'author_id', intval( $args['author_id'] ?? 0 ) );
				return $request;

			// -- Taxonomies --

			case 'wp_list_terms':
				$taxonomy = sanitize_key( $args['taxonomy'] ?? 'category' );
				$request  = new WP_REST_Request( 'GET', $ns . '/taxonomies/' . $taxonomy . '/terms' );
				self::set_params( $request, $args, array( 'search', 'parent', 'page', 'per_page' ) );
				return $request;

			case 'wp_create_term':
				$taxonomy = sanitize_key( $args['taxonomy'] ?? 'category' );
				$request  = new WP_REST_Request( 'POST', $ns . '/taxonomies/' . $taxonomy . '/terms' );
				self::set_params( $request, $args, array( 'name', 'slug', 'parent', 'description' ) );
				return $request;

			case 'wp_assign_terms':
				$request = new WP_REST_Request( 'POST', $ns . '/content/' . intval( $args['id'] ?? 0 ) . '/terms' );
				$request->set_param( 'content_type', $args['content_type'] ?? 'post' );
				if ( isset( $args['terms'] ) ) {
					$request->set_body( wp_json_encode( array( 'terms' => $args['terms'] ) ) );
					$request->set_header( 'content-type', 'application/json' );
				}
				return $request;

			// -- Media --

			case 'wp_search_media':
				$request = new WP_REST_Request( 'GET', $ns . '/media' );
				self::set_params( $request, $args, array( 'search', 'per_page', 'page', 'mime_type' ) );
				return $request;

			case 'wp_get_media':
				return new WP_REST_Request( 'GET', $ns . '/media/' . intval( $args['id'] ?? 0 ) );

			case 'wp_update_media':
				$request = new WP_REST_Request( 'PATCH', $ns . '/media/' . intval( $args['id'] ?? 0 ) );
				self::set_params( $request, $args, array( 'alt_text', 'caption', 'description', 'title' ) );
				return $request;

			case 'wp_upload_media_from_url':
				$request = new WP_REST_Request( 'POST', $ns . '/media/from-url' );
				self::set_params( $request, $args, array( 'url', 'alt_text', 'title', 'caption', 'description' ) );
				return $request;

			case 'wp_set_featured_image':
				$request = new WP_REST_Request( 'POST', $ns . '/content/' . intval( $args['id'] ?? 0 ) . '/featured-image' );
				$request->set_param( 'content_type', $args['content_type'] ?? 'post' );
				$request->set_param( 'media_id', $args['media_id'] ?? null );
				return $request;

			// -- Inline Images --

			case 'wp_insert_inline_image':
				$request = new WP_REST_Request( 'POST', $ns . '/content/' . intval( $args['id'] ?? 0 ) . '/inline-image/insert' );
				self::set_params(
					$request,
					$args,
					array(
						'content_type',
						'media_id',
						'placement',
						'heading_text',
						'marker',
						'align',
						'size_slug',
						'caption',
						'alt_text',
					)
				);
				return $request;

			case 'wp_replace_inline_image':
				$request = new WP_REST_Request( 'POST', $ns . '/content/' . intval( $args['id'] ?? 0 ) . '/inline-image/replace' );
				self::set_params(
					$request,
					$args,
					array(
						'content_type',
						'new_media_id',
						'match_media_id',
						'match_src_substring',
						'alt_text',
						'caption',
					)
				);
				return $request;

			case 'wp_remove_inline_image':
				$request = new WP_REST_Request( 'POST', $ns . '/content/' . intval( $args['id'] ?? 0 ) . '/inline-image/remove' );
				self::set_params(
					$request,
					$args,
					array(
						'content_type',
						'match_media_id',
						'match_src_substring',
					)
				);
				return $request;

			// -- Yoast SEO --

			case 'wp_get_yoast_analysis':
				$request = new WP_REST_Request( 'GET', $ns . '/yoast/analysis/' . intval( $args['id'] ?? 0 ) );
				$request->set_param( 'content_type', $args['content_type'] ?? 'post' );
				return $request;

			case 'wp_update_yoast_metadata':
				$request = new WP_REST_Request( 'PATCH', $ns . '/yoast/metadata/' . intval( $args['id'] ?? 0 ) );
				$request->set_param( 'content_type', $args['content_type'] ?? 'post' );
				if ( isset( $args['yoast_meta'] ) ) {
					$request->set_body( wp_json_encode( array( 'yoast_meta' => $args['yoast_meta'] ) ) );
					$request->set_header( 'content-type', 'application/json' );
				}
				return $request;

			case 'wp_get_yoast_head_preview':
				$request = new WP_REST_Request( 'GET', $ns . '/yoast/head/' . intval( $args['id'] ?? 0 ) );
				$request->set_param( 'content_type', $args['content_type'] ?? 'post' );
				return $request;

			// -- Preview --

			case 'wp_get_preview_link':
				$request = new WP_REST_Request( 'POST', $ns . '/content/' . intval( $args['id'] ?? 0 ) . '/preview-link' );
				$request->set_param( 'content_type', $args['content_type'] ?? 'post' );
				return $request;

			// -- Image Providers --

			case 'wp_generate_image':
				$request = new WP_REST_Request( 'POST', $ns . '/image/generate' );
				self::set_params( $request, $args, array( 'prompt', 'provider', 'aspect_ratio', 'quality' ) );
				return $request;

			case 'wp_search_stock_photos':
				$request = new WP_REST_Request( 'GET', $ns . '/image/stock/search' );
				self::set_params( $request, $args, array( 'query', 'provider', 'orientation', 'per_page' ) );
				return $request;

			case 'wp_import_stock_photo':
				$request = new WP_REST_Request( 'POST', $ns . '/image/stock/import' );
				self::set_params( $request, $args, array( 'provider', 'provider_id', 'alt_text' ) );
				return $request;

			case 'wp_list_image_providers':
				return new WP_REST_Request( 'GET', $ns . '/image/providers' );

			case 'wp_confirm_image':
				return new WP_REST_Request( 'POST', $ns . '/image/' . intval( $args['media_id'] ?? 0 ) . '/confirm' );

			// -- Upload Portal --

			case 'wp_create_upload_session':
				$request = new WP_REST_Request( 'POST', $ns . '/upload/session' );
				if ( isset( $args['ip_binding'] ) ) {
					$request->set_param( 'ip_binding', $args['ip_binding'] );
				}
				return $request;

			case 'wp_get_upload_session':
				return new WP_REST_Request( 'GET', $ns . '/upload/session/' . sanitize_text_field( $args['session_id'] ?? '' ) );

			default:
				throw new Exception( esc_html( sprintf( 'Unknown tool: %s', sanitize_key( $tool_name ) ) ), -32601 );
		}
	}

	// =========================================================================
	// Tool definitions — exposed via tools/list
	// =========================================================================

	/**
	 * Get all tool definitions for the remote MCP transport.
	 *
	 * Excludes local-only tools (wp_find_media_file, wp_upload_media_from_path).
	 *
	 * @return array[]
	 */
	private static function get_tool_definitions(): array {
		$ct_enum = array(
			'type'        => 'string',
			'description' => 'WordPress content type (e.g. post, page).',
		);

		$tools = array();

		// -- Site & Discovery --

		$tools[] = array(
			'name'        => 'wp_getting_started',
			'description' => 'Get site context, theme info, and editorial workflow guide. CALL THIS FIRST.',
			'inputSchema' => array(
				'type'       => 'object',
				'properties' => new stdClass(),
			),
			'annotations' => array( 'readOnlyHint' => true ),
		);

		$tools[] = array(
			'name'        => 'wp_site_info',
			'description' => 'Get site and capability metadata.',
			'inputSchema' => array(
				'type'       => 'object',
				'properties' => new stdClass(),
			),
			'annotations' => array( 'readOnlyHint' => true ),
		);

		$tools[] = array(
			'name'        => 'wp_list_content_types',
			'description' => 'List content types allowed for this site.',
			'inputSchema' => array(
				'type'       => 'object',
				'properties' => new stdClass(),
			),
			'annotations' => array( 'readOnlyHint' => true ),
		);

		$tools[] = array(
			'name'        => 'wp_find_content',
			'description' => 'Search and list content items across allowed content types.',
			'inputSchema' => array(
				'type'       => 'object',
				'properties' => array(
					'content_type' => $ct_enum,
					'search'       => array( 'type' => 'string' ),
					'status'       => array( 'type' => 'string' ),
					'author'       => array( 'type' => 'integer' ),
					'per_page'     => array(
						'type'    => 'integer',
						'maximum' => 100,
					),
					'page'         => array( 'type' => 'integer' ),
				),
			),
			'annotations' => array( 'readOnlyHint' => true ),
		);

		// -- Content CRUD --

		$tools[] = array(
			'name'        => 'wp_get_content',
			'description' => 'Get a single content item with full edit context.',
			'inputSchema' => array(
				'type'       => 'object',
				'properties' => array(
					'id'           => array( 'type' => 'integer' ),
					'content_type' => $ct_enum,
				),
				'required'   => array( 'id', 'content_type' ),
			),
			'annotations' => array( 'readOnlyHint' => true ),
		);

		$tools[] = array(
			'name'        => 'wp_create_draft',
			'description' => 'Create a new draft content item.',
			'inputSchema' => array(
				'type'       => 'object',
				'properties' => array(
					'content_type'   => $ct_enum,
					'title'          => array( 'type' => 'string' ),
					'content'        => array( 'type' => 'string' ),
					'excerpt'        => array( 'type' => 'string' ),
					'slug'           => array( 'type' => 'string' ),
					'content_format' => array(
						'type' => 'string',
						'enum' => array( 'html', 'markdown', 'auto' ),
					),
					'author'         => array( 'type' => 'integer' ),
					'date'           => array( 'type' => 'string' ),
				),
				'required'   => array( 'content_type', 'title' ),
			),
		);

		$tools[] = array(
			'name'        => 'wp_update_content',
			'description' => 'Patch an existing content item.',
			'inputSchema' => array(
				'type'       => 'object',
				'properties' => array(
					'id'             => array( 'type' => 'integer' ),
					'content_type'   => $ct_enum,
					'patch'          => array( 'type' => 'object' ),
					'content_format' => array(
						'type' => 'string',
						'enum' => array( 'html', 'markdown', 'auto' ),
					),
				),
				'required'   => array( 'id', 'content_type', 'patch' ),
			),
		);

		$tools[] = array(
			'name'        => 'wp_publish_content',
			'description' => 'Publish or schedule content. Requires confirmation_token.',
			'inputSchema' => array(
				'type'       => 'object',
				'properties' => array(
					'id'                 => array( 'type' => 'integer' ),
					'content_type'       => $ct_enum,
					'date'               => array(
						'type'        => 'string',
						'description' => 'ISO 8601 UTC date for scheduling.',
					),
					'confirmation_token' => array( 'type' => 'string' ),
				),
				'required'   => array( 'id', 'content_type' ),
			),
			'annotations' => array( 'destructiveHint' => true ),
		);

		$tools[] = array(
			'name'        => 'wp_trash_content',
			'description' => 'Move content to trash. Requires confirmation_token.',
			'inputSchema' => array(
				'type'       => 'object',
				'properties' => array(
					'id'                 => array( 'type' => 'integer' ),
					'content_type'       => $ct_enum,
					'confirmation_token' => array( 'type' => 'string' ),
				),
				'required'   => array( 'id', 'content_type' ),
			),
			'annotations' => array( 'destructiveHint' => true ),
		);

		$tools[] = array(
			'name'        => 'wp_restore_content',
			'description' => 'Restore content from trash. Requires confirmation_token.',
			'inputSchema' => array(
				'type'       => 'object',
				'properties' => array(
					'id'                 => array( 'type' => 'integer' ),
					'content_type'       => $ct_enum,
					'confirmation_token' => array( 'type' => 'string' ),
				),
				'required'   => array( 'id', 'content_type' ),
			),
			'annotations' => array( 'destructiveHint' => true ),
		);

		$tools[] = array(
			'name'        => 'wp_clone_content',
			'description' => 'Clone an existing post or page as a new draft.',
			'inputSchema' => array(
				'type'       => 'object',
				'properties' => array(
					'id'           => array( 'type' => 'integer' ),
					'content_type' => $ct_enum,
					'title'        => array( 'type' => 'string' ),
				),
				'required'   => array( 'id', 'content_type' ),
			),
		);

		// -- Revisions --

		$tools[] = array(
			'name'        => 'wp_list_revisions',
			'description' => 'List revisions for a content item.',
			'inputSchema' => array(
				'type'       => 'object',
				'properties' => array(
					'id'           => array( 'type' => 'integer' ),
					'content_type' => $ct_enum,
				),
				'required'   => array( 'id', 'content_type' ),
			),
			'annotations' => array( 'readOnlyHint' => true ),
		);

		$tools[] = array(
			'name'        => 'wp_restore_revision',
			'description' => 'Restore a specific revision. Requires confirmation_token.',
			'inputSchema' => array(
				'type'       => 'object',
				'properties' => array(
					'id'                 => array( 'type' => 'integer' ),
					'revision_id'        => array( 'type' => 'integer' ),
					'content_type'       => $ct_enum,
					'confirmation_token' => array( 'type' => 'string' ),
				),
				'required'   => array( 'id', 'revision_id', 'content_type' ),
			),
			'annotations' => array( 'destructiveHint' => true ),
		);

		// -- Authors --

		$tools[] = array(
			'name'        => 'wp_list_authors',
			'description' => 'List allowlisted authors.',
			'inputSchema' => array(
				'type'       => 'object',
				'properties' => new stdClass(),
			),
			'annotations' => array( 'readOnlyHint' => true ),
		);

		$tools[] = array(
			'name'        => 'wp_assign_author',
			'description' => 'Assign an author to content.',
			'inputSchema' => array(
				'type'       => 'object',
				'properties' => array(
					'id'           => array( 'type' => 'integer' ),
					'content_type' => $ct_enum,
					'author_id'    => array( 'type' => 'integer' ),
				),
				'required'   => array( 'id', 'content_type', 'author_id' ),
			),
		);

		// -- Taxonomies --

		$tools[] = array(
			'name'        => 'wp_list_terms',
			'description' => 'List terms for a taxonomy.',
			'inputSchema' => array(
				'type'       => 'object',
				'properties' => array(
					'taxonomy' => array( 'type' => 'string' ),
					'search'   => array( 'type' => 'string' ),
					'parent'   => array( 'type' => 'integer' ),
					'per_page' => array(
						'type'    => 'integer',
						'maximum' => 100,
					),
					'page'     => array( 'type' => 'integer' ),
				),
				'required'   => array( 'taxonomy' ),
			),
			'annotations' => array( 'readOnlyHint' => true ),
		);

		$tools[] = array(
			'name'        => 'wp_create_term',
			'description' => 'Create a term in a taxonomy.',
			'inputSchema' => array(
				'type'       => 'object',
				'properties' => array(
					'taxonomy'    => array( 'type' => 'string' ),
					'name'        => array( 'type' => 'string' ),
					'slug'        => array( 'type' => 'string' ),
					'parent'      => array( 'type' => 'integer' ),
					'description' => array( 'type' => 'string' ),
				),
				'required'   => array( 'taxonomy', 'name' ),
			),
		);

		$tools[] = array(
			'name'        => 'wp_assign_terms',
			'description' => 'Assign term IDs to a content item.',
			'inputSchema' => array(
				'type'       => 'object',
				'properties' => array(
					'id'           => array( 'type' => 'integer' ),
					'content_type' => $ct_enum,
					'terms'        => array(
						'type'        => 'object',
						'description' => 'Map of taxonomy slug to array of term IDs.',
					),
				),
				'required'   => array( 'id', 'content_type', 'terms' ),
			),
		);

		// -- Media --

		$tools[] = array(
			'name'        => 'wp_search_media',
			'description' => 'Search the media library.',
			'inputSchema' => array(
				'type'       => 'object',
				'properties' => array(
					'search'    => array( 'type' => 'string' ),
					'per_page'  => array(
						'type'    => 'integer',
						'maximum' => 100,
					),
					'page'      => array( 'type' => 'integer' ),
					'mime_type' => array( 'type' => 'string' ),
				),
			),
			'annotations' => array( 'readOnlyHint' => true ),
		);

		$tools[] = array(
			'name'        => 'wp_get_media',
			'description' => 'Get a single media item by ID with full metadata.',
			'inputSchema' => array(
				'type'       => 'object',
				'properties' => array(
					'id' => array( 'type' => 'integer' ),
				),
				'required'   => array( 'id' ),
			),
			'annotations' => array( 'readOnlyHint' => true ),
		);

		$tools[] = array(
			'name'        => 'wp_update_media',
			'description' => 'Update media metadata (alt text, caption, description, title).',
			'inputSchema' => array(
				'type'       => 'object',
				'properties' => array(
					'id'          => array( 'type' => 'integer' ),
					'alt_text'    => array( 'type' => 'string' ),
					'caption'     => array( 'type' => 'string' ),
					'description' => array( 'type' => 'string' ),
					'title'       => array( 'type' => 'string' ),
				),
				'required'   => array( 'id' ),
			),
		);

		$tools[] = array(
			'name'        => 'wp_upload_media_from_url',
			'description' => 'Upload media to WordPress by fetching from a URL.',
			'inputSchema' => array(
				'type'       => 'object',
				'properties' => array(
					'url'         => array(
						'type'   => 'string',
						'format' => 'uri',
					),
					'alt_text'    => array( 'type' => 'string' ),
					'title'       => array( 'type' => 'string' ),
					'caption'     => array( 'type' => 'string' ),
					'description' => array( 'type' => 'string' ),
				),
				'required'   => array( 'url' ),
			),
		);

		$tools[] = array(
			'name'        => 'wp_set_featured_image',
			'description' => 'Set or remove a featured image on content.',
			'inputSchema' => array(
				'type'       => 'object',
				'properties' => array(
					'id'           => array( 'type' => 'integer' ),
					'content_type' => $ct_enum,
					'media_id'     => array( 'type' => array( 'integer', 'null' ) ),
				),
				'required'   => array( 'id', 'content_type' ),
			),
		);

		// -- Inline Images --

		$tools[] = array(
			'name'        => 'wp_insert_inline_image',
			'description' => 'Insert an inline image into content.',
			'inputSchema' => array(
				'type'       => 'object',
				'properties' => array(
					'id'           => array( 'type' => 'integer' ),
					'content_type' => $ct_enum,
					'media_id'     => array( 'type' => 'integer' ),
					'placement'    => array(
						'type' => 'string',
						'enum' => array( 'start', 'end', 'before_heading', 'after_heading', 'marker' ),
					),
					'heading_text' => array( 'type' => 'string' ),
					'marker'       => array( 'type' => 'string' ),
					'align'        => array(
						'type' => 'string',
						'enum' => array( 'none', 'left', 'center', 'right', 'wide', 'full' ),
					),
					'size_slug'    => array( 'type' => 'string' ),
					'caption'      => array( 'type' => 'string' ),
					'alt_text'     => array( 'type' => 'string' ),
				),
				'required'   => array( 'id', 'content_type', 'media_id', 'placement' ),
			),
		);

		$tools[] = array(
			'name'        => 'wp_replace_inline_image',
			'description' => 'Replace an inline image reference in content.',
			'inputSchema' => array(
				'type'       => 'object',
				'properties' => array(
					'id'                  => array( 'type' => 'integer' ),
					'content_type'        => $ct_enum,
					'new_media_id'        => array( 'type' => 'integer' ),
					'match_media_id'      => array( 'type' => 'integer' ),
					'match_src_substring' => array( 'type' => 'string' ),
					'alt_text'            => array( 'type' => 'string' ),
					'caption'             => array( 'type' => 'string' ),
				),
				'required'   => array( 'id', 'content_type', 'new_media_id' ),
			),
		);

		$tools[] = array(
			'name'        => 'wp_remove_inline_image',
			'description' => 'Remove an inline image from content.',
			'inputSchema' => array(
				'type'       => 'object',
				'properties' => array(
					'id'                  => array( 'type' => 'integer' ),
					'content_type'        => $ct_enum,
					'match_media_id'      => array( 'type' => 'integer' ),
					'match_src_substring' => array( 'type' => 'string' ),
				),
				'required'   => array( 'id', 'content_type' ),
			),
		);

		// -- Yoast SEO --

		$tools[] = array(
			'name'        => 'wp_get_yoast_analysis',
			'description' => 'Get Yoast readability and SEO analysis.',
			'inputSchema' => array(
				'type'       => 'object',
				'properties' => array(
					'id'           => array( 'type' => 'integer' ),
					'content_type' => $ct_enum,
				),
				'required'   => array( 'id', 'content_type' ),
			),
			'annotations' => array( 'readOnlyHint' => true ),
		);

		$tools[] = array(
			'name'        => 'wp_update_yoast_metadata',
			'description' => 'Update Yoast SEO metadata fields.',
			'inputSchema' => array(
				'type'       => 'object',
				'properties' => array(
					'id'           => array( 'type' => 'integer' ),
					'content_type' => $ct_enum,
					'yoast_meta'   => array( 'type' => 'object' ),
				),
				'required'   => array( 'id', 'content_type', 'yoast_meta' ),
			),
		);

		$tools[] = array(
			'name'        => 'wp_get_yoast_head_preview',
			'description' => 'Get Yoast head/meta preview payload.',
			'inputSchema' => array(
				'type'       => 'object',
				'properties' => array(
					'id'           => array( 'type' => 'integer' ),
					'content_type' => $ct_enum,
				),
				'required'   => array( 'id', 'content_type' ),
			),
			'annotations' => array( 'readOnlyHint' => true ),
		);

		// -- Preview --

		$tools[] = array(
			'name'        => 'wp_get_preview_link',
			'description' => 'Get WordPress preview and signed shareable preview URLs.',
			'inputSchema' => array(
				'type'       => 'object',
				'properties' => array(
					'id'           => array( 'type' => 'integer' ),
					'content_type' => $ct_enum,
				),
				'required'   => array( 'id', 'content_type' ),
			),
			'annotations' => array( 'readOnlyHint' => true ),
		);

		// -- Image Providers --

		$tools[] = array(
			'name'        => 'wp_generate_image',
			'description' => 'Generate an image using AI (Google Imagen or OpenAI) and save it to the WordPress media library. The image is generated server-side — no image data passes through the conversation. Returns the media ID and URL so you can show the preview inline and then use wp_set_featured_image or wp_insert_inline_image.',
			'inputSchema' => array(
				'type'       => 'object',
				'properties' => array(
					'prompt'       => array(
						'type'        => 'string',
						'description' => 'Detailed description of the image to generate. Be specific about style, composition, lighting, and subject.',
					),
					'provider'     => array(
						'type'        => 'string',
						'enum'        => array( 'google_imagen', 'openai' ),
						'description' => 'Which provider to use. Defaults to first enabled.',
					),
					'aspect_ratio' => array(
						'type'        => 'string',
						'enum'        => array( '1:1', '16:9', '9:16', '4:3', '3:4' ),
						'description' => 'Aspect ratio. Default: 16:9.',
					),
					'quality'      => array(
						'type'        => 'string',
						'enum'        => array( 'low', 'medium', 'high' ),
						'description' => 'Quality tier (affects cost for OpenAI). Default: medium.',
					),
				),
				'required'   => array( 'prompt' ),
			),
		);

		$tools[] = array(
			'name'        => 'wp_search_stock_photos',
			'description' => 'Search for free stock photos from Unsplash or Pexels. Returns preview URLs that you can show inline to the user. Once the user picks one, call wp_import_stock_photo to save it to the media library.',
			'inputSchema' => array(
				'type'       => 'object',
				'properties' => array(
					'query'       => array(
						'type'        => 'string',
						'description' => 'Search terms for finding relevant photos.',
					),
					'provider'    => array(
						'type'        => 'string',
						'enum'        => array( 'unsplash', 'pexels' ),
						'description' => 'Which stock provider. Defaults to first enabled.',
					),
					'orientation' => array(
						'type'        => 'string',
						'enum'        => array( 'landscape', 'portrait', 'square' ),
						'description' => 'Photo orientation filter.',
					),
					'per_page'    => array(
						'type'        => 'integer',
						'minimum'     => 1,
						'maximum'     => 10,
						'description' => 'Number of results (1-10). Default: 5.',
					),
				),
				'required'   => array( 'query' ),
			),
			'annotations' => array( 'readOnlyHint' => true ),
		);

		$tools[] = array(
			'name'        => 'wp_import_stock_photo',
			'description' => 'Import a stock photo from Unsplash or Pexels into the WordPress media library. Use provider and provider_id from wp_search_stock_photos results. Handles download tracking and attribution automatically.',
			'inputSchema' => array(
				'type'       => 'object',
				'properties' => array(
					'provider'    => array(
						'type'        => 'string',
						'enum'        => array( 'unsplash', 'pexels' ),
						'description' => 'The stock photo provider.',
					),
					'provider_id' => array(
						'type'        => 'string',
						'description' => 'The photo ID from the search results.',
					),
					'alt_text'    => array(
						'type'        => 'string',
						'description' => 'Override alt text (otherwise uses photo description).',
					),
				),
				'required'   => array( 'provider', 'provider_id' ),
			),
		);

		$tools[] = array(
			'name'        => 'wp_list_image_providers',
			'description' => 'List which image generation and stock photo providers are enabled on this WordPress site.',
			'inputSchema' => array(
				'type'       => 'object',
				'properties' => (object) array(),
			),
			'annotations' => array( 'readOnlyHint' => true ),
		);

		$tools[] = array(
			'name'        => 'wp_confirm_image',
			'description' => 'Confirm a generated image that the user has approved. This prevents auto-cleanup after 24 hours. Call this after the user approves a generated image.',
			'inputSchema' => array(
				'type'       => 'object',
				'properties' => array(
					'media_id' => array(
						'type'        => 'integer',
						'description' => 'The media ID of the generated image to confirm.',
					),
				),
				'required'   => array( 'media_id' ),
			),
		);

		// -- Upload Portal --

		$tools[] = array(
			'name'        => 'wp_create_upload_session',
			'description' => 'Create a temporary upload portal link for the user to drag-and-drop files into the WordPress media library. The link expires in 15 minutes. Present the URL to the user as a clickable link. After the user uploads files and clicks Done, call wp_get_upload_session to retrieve the uploaded media IDs.',
			'inputSchema' => array(
				'type'       => 'object',
				'properties' => array(
					'ip_binding' => array(
						'type'        => 'boolean',
						'description' => 'Lock the session to the creator\'s IP address for extra security.',
					),
				),
			),
		);

		$tools[] = array(
			'name'        => 'wp_get_upload_session',
			'description' => 'Check the status of an upload session and retrieve uploaded file details (media IDs, URLs, filenames). Call this after the user confirms they have finished uploading via the portal.',
			'inputSchema' => array(
				'type'       => 'object',
				'properties' => array(
					'session_id' => array(
						'type'        => 'string',
						'description' => 'The session ID returned by wp_create_upload_session.',
					),
				),
				'required'   => array( 'session_id' ),
			),
		);

		return $tools;
	}

	// =========================================================================
	// Capability filtering
	// =========================================================================

	/**
	 * Get the allowed tool names for the current connection.
	 *
	 * @return array List of allowed tool names.
	 */
	private static function get_allowed_tools(): array {
		$auth_type = self::$current_auth_type ?? 'bearer';
		$settings  = get_option( 'axtolab_ai_connector_settings', array() );

		if ( 'oauth' === $auth_type ) {
			$capabilities = $settings['oauth_capabilities'] ?? Axtolab_AI_Connector_Capabilities::DEFAULT_PRESET;
		} else {
			$capabilities = $settings['bearer_capabilities'] ?? Axtolab_AI_Connector_Capabilities::DEFAULT_PRESET;
		}

		if ( ! in_array( 'read', $capabilities, true ) ) {
			$capabilities[] = 'read';
		}

		return Axtolab_AI_Connector_Capabilities::tools_for( $capabilities );
	}

	/**
	 * Check if a specific tool is allowed for the current connection.
	 *
	 * @param string $tool_name The tool name to check.
	 * @return bool
	 */
	private static function is_tool_allowed( string $tool_name ): bool {
		return in_array( $tool_name, self::get_allowed_tools(), true );
	}

	// =========================================================================
	// Helpers
	// =========================================================================

	/**
	 * Copy named parameters from tool args to a REST request.
	 */
	private static function set_params( WP_REST_Request $request, array $args, array $keys ): void {
		foreach ( $keys as $key ) {
			if ( isset( $args[ $key ] ) ) {
				$request->set_param( $key, $args[ $key ] );
			}
		}
	}

	/**
	 * Build a JSON-RPC error structure.
	 */
	private static function rpc_error( $id, int $code, string $message ): array {
		return array(
			'jsonrpc' => '2.0',
			'id'      => $id,
			'error'   => array(
				'code'    => $code,
				'message' => $message,
			),
		);
	}

	/**
	 * Build a WP_REST_Response with JSON-RPC payload and session header.
	 */
	private static function rpc_response( array $payload, int $status = 200, string $session_id = '' ): WP_REST_Response {
		$response = new WP_REST_Response( $payload, $status );
		$response->header( 'Content-Type', 'application/json' );

		if ( $session_id ) {
			$response->header( 'Mcp-Session-Id', $session_id );
		}

		return $response;
	}

	/**
	 * Add CORS headers to MCP endpoint responses.
	 *
	 * @param bool             $served  Whether the request has been served.
	 * @param WP_REST_Response $result  The response.
	 * @param WP_REST_Request  $request The request.
	 * @param WP_REST_Server   $server  The REST server.
	 * @return bool
	 */
	public static function add_cors_headers( bool $served, $result, WP_REST_Request $request, WP_REST_Server $server ): bool {
		if ( strpos( $request->get_route(), '/' . self::NS . '/mcp' ) === 0 ) {
			header( 'Access-Control-Allow-Origin: *' );
			header( 'Access-Control-Allow-Methods: GET, POST, DELETE, OPTIONS' );
			header( 'Access-Control-Allow-Headers: Authorization, Content-Type, Mcp-Session-Id, Accept' );
			header( 'Access-Control-Expose-Headers: Mcp-Session-Id' );
		}

		return $served;
	}

	/**
	 * Add WWW-Authenticate header to 401 responses on the /mcp endpoint.
	 *
	 * This enables OAuth discovery — ChatGPT reads this header to find
	 * the protected resource metadata endpoint and start the OAuth flow.
	 *
	 * @param WP_REST_Response $response The response object.
	 * @param WP_REST_Server   $server   The REST server.
	 * @param WP_REST_Request  $request  The request object.
	 * @return WP_REST_Response
	 */
	public static function add_www_authenticate_header( WP_REST_Response $response, WP_REST_Server $server, WP_REST_Request $request ): WP_REST_Response {
		if ( 401 === $response->get_status() && strpos( $request->get_route(), '/' . self::NS . '/mcp' ) === 0 ) {
			$data                  = $response->get_data();
			$resource_metadata_url = rest_url( 'axtolab-ai-connector/v1/oauth/metadata/resource' );

			// Set the HTTP header from the WP_Error data.
			if ( is_array( $data ) && isset( $data['data']['headers']['WWW-Authenticate'] ) ) {
				$response->header( 'WWW-Authenticate', $data['data']['headers']['WWW-Authenticate'] );
			}

			// Also inject _meta into the JSON body so ChatGPT gets both signals.
			if ( is_array( $data ) ) {
				$data['_meta'] = array(
					'mcp/www_authenticate' => array(
						'Bearer resource_metadata="' . $resource_metadata_url . '", error="invalid_token", error_description="Bearer token required or invalid"',
					),
				);
				$response->set_data( $data );
			}
		}
		return $response;
	}
}
endif;

if ( ! class_exists( 'MCP_Gateway_MCP_Transport', false ) ) {
	class_alias( 'Axtolab_AI_Connector_MCP_Transport', 'MCP_Gateway_MCP_Transport' );
}
