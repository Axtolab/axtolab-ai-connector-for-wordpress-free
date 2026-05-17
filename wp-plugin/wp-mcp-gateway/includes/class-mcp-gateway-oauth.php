<?php
/**
 * Self-embedded OAuth 2.1 Authorization Server for MCP.
 *
 * Implements the MCP authorization spec for web-based MCP clients (ChatGPT, Claude Web, etc.):
 * - Protected Resource Metadata (RFC 9728)
 * - Authorization Server Metadata (RFC 8414)
 * - Dynamic Client Registration (RFC 7591)
 * - Authorization Code + PKCE (S256)
 * - Token endpoint
 *
 * @package WP_MCP_Gateway
 * @since   0.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Axtolab_AI_Connector_OAuth {

	private const NS = 'axtolab-ai-connector/v1';

	/**
	 * Allowed redirect URIs for OAuth clients.
	 */
	private const ALLOWED_REDIRECT_URIS = array(
		// ChatGPT
		'https://chatgpt.com/connector_platform_oauth_redirect',
		'https://platform.openai.com/apps-manage/oauth',
		// Claude web
		'https://claude.ai/api/mcp/auth_callback',
		'https://claude.com/api/mcp/auth_callback',
	);

	/**
	 * Access token expiry in seconds (24 hours).
	 */
	private const TOKEN_EXPIRY = 86400;

	/**
	 * Refresh token expiry in seconds (7 days).
	 */
	private const REFRESH_TOKEN_EXPIRY = 604800;

	/**
	 * Authorization code expiry in seconds (10 minutes).
	 */
	private const CODE_EXPIRY = 600;

	/**
	 * Client registration expiry in seconds (30 days).
	 */
	private const CLIENT_REGISTRATION_EXPIRY = 2592000;

	/**
	 * Max DCR registrations per hour per IP.
	 */
	private const DCR_RATE_LIMIT = 10;

	/**
	 * Bootstrap OAuth — call from plugins_loaded.
	 */
	public static function bootstrap(): void {
		// Early request interception via init (priority 1):
		// - .well-known discovery endpoints (best-effort for hosts where it works)
		// - Authorization endpoint (must run outside REST API so cookie auth works)
		add_action( 'init', array( __CLASS__, 'handle_early_requests' ), 1 );

		// REST API endpoints for OAuth flow (register, token, metadata).
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	// =========================================================================
	// Early Request Interception (init priority 1)
	// =========================================================================

	/**
	 * Handle requests that must be intercepted before the REST API dispatcher.
	 *
	 * Fires on init (priority 1). Catches:
	 * - .well-known discovery endpoints (best-effort for Apache/LiteSpeed hosts)
	 * - Authorization endpoint — must run outside REST API so WordPress cookie
	 *   authentication works after wp-login.php redirect (REST API requires a
	 *   nonce for cookie auth, which the redirect doesn't carry).
	 */
	public static function handle_early_requests(): void {
		$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( (string) $_SERVER['REQUEST_URI'] ) ) : '';

		// Strip query string for matching.
		$path = wp_parse_url( $request_uri, PHP_URL_PATH );
		if ( ! $path ) {
			return;
		}

		// Handle subdirectory installs: strip the home path prefix.
		$home_path = wp_parse_url( home_url(), PHP_URL_PATH );
		if ( $home_path && '/' !== $home_path ) {
			$path = preg_replace( '#^' . preg_quote( $home_path, '#' ) . '#', '', $path );
		}

		$path = '/' . ltrim( $path, '/' );

		// ── .well-known discovery (best-effort) ──────────────────────────────
		$rest_base = rest_url( self::NS );
		$mcp_url   = rest_url( self::NS . '/mcp' );

		if ( '/.well-known/oauth-protected-resource' === $path ) {
			header( 'Content-Type: application/json' );
			header( 'Access-Control-Allow-Origin: *' );
			echo wp_json_encode( array(
				'resource'               => $mcp_url,
				'authorization_servers'  => array( $rest_base ),
				'scopes_supported'       => array( 'mcp:read', 'mcp:write' ),
				'resource_documentation' => home_url(),
			) );
			exit;
		}

		if ( '/.well-known/oauth-authorization-server' === $path ) {
			header( 'Content-Type: application/json' );
			header( 'Access-Control-Allow-Origin: *' );
			echo wp_json_encode( array(
				'issuer'                                => $rest_base,
				'authorization_endpoint'                => $rest_base . '/oauth/authorize',
				'token_endpoint'                        => $rest_base . '/oauth/token',
				'registration_endpoint'                 => $rest_base . '/oauth/register',
				'code_challenge_methods_supported'      => array( 'S256' ),
				'response_types_supported'              => array( 'code' ),
				'grant_types_supported'                 => array( 'authorization_code', 'refresh_token' ),
				'token_endpoint_auth_methods_supported' => array( 'none' ),
				'scopes_supported'                      => array( 'mcp:read', 'mcp:write' ),
			) );
			exit;
		}

		// ── Authorization endpoint (intercepted before REST API) ─────────────
		// The URL stays at /wp-json/axtolab-ai-connector/v1/oauth/authorize so
		// discovery metadata doesn't need to change. We just handle it here
		// instead of via register_rest_route() so cookie auth works.
		$authorize_path = '/wp-json/' . self::NS . '/oauth/authorize';
		if ( $path === $authorize_path ) {
			$request_method = isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_key( wp_unslash( (string) $_SERVER['REQUEST_METHOD'] ) ) : 'get';
			if ( 'post' === $request_method ) {
				self::handle_authorize_post_direct();
			} else {
				self::handle_authorize_get_direct();
			}
			exit;
		}
	}

	// =========================================================================
	// REST API Routes
	// =========================================================================

	/**
	 * Register OAuth REST routes.
	 */
	public static function register_routes(): void {
		// Public by design: OAuth Dynamic Client Registration must be reachable
		// before a client has a WordPress login or bearer token. The callback
		// validates redirect URIs against the allowlist/loopback rules before
		// persisting a short-lived client registration.
		register_rest_route( self::NS, '/oauth/register', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'handle_register' ),
			'permission_callback' => '__return_true',
		) );

		// Note: /oauth/authorize is handled via init hook (handle_early_requests)
		// so WordPress cookie auth works after wp-login.php redirect.

		// Public by OAuth protocol: clients exchange single-use authorization
		// codes and refresh tokens here. The callback verifies client_id,
		// redirect_uri, PKCE verifier, resource, code expiry, and token hashes.
		register_rest_route( self::NS, '/oauth/token', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'handle_token' ),
			'permission_callback' => '__return_true',
		) );

		// Public by OAuth protocol: discovery metadata contains endpoint URLs
		// and supported methods only. It exposes no private site data.
		// These REST routes are also a reliable fallback for hosts where
		// host-root .well-known is blocked by Nginx or other reverse proxies.
		register_rest_route( self::NS, '/oauth/metadata/resource', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'rest_protected_resource_metadata' ),
			'permission_callback' => '__return_true',
		) );

		register_rest_route( self::NS, '/oauth/metadata/server', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'rest_authorization_server_metadata' ),
			'permission_callback' => '__return_true',
		) );

		// RFC 8414 discovery path under the REST namespace.
		// ChatGPT constructs this as {issuer}/.well-known/oauth-authorization-server
		// Since issuer = rest_url( NS ), this resolves to /wp-json/axtolab-ai-connector/v1/.well-known/...
		register_rest_route( self::NS, '/\.well-known/oauth-authorization-server', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'rest_authorization_server_metadata' ),
			'permission_callback' => '__return_true',
		) );

		// OpenID Connect Discovery path. Claude Web's custom-connector flow
		// probes {issuer}/.well-known/openid-configuration for OAuth/OIDC
		// metadata before attempting Dynamic Client Registration. Without this
		// route Claude can't discover our registration_endpoint, falls back to
		// host-root /register, gets 404, and shows "Couldn't reach the MCP
		// server". We serve the same metadata doc with a few OIDC-required
		// stub fields added by rest_authorization_server_metadata().
		register_rest_route( self::NS, '/\.well-known/openid-configuration', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'rest_authorization_server_metadata' ),
			'permission_callback' => '__return_true',
		) );
	}

	/**
	 * REST callback: Protected Resource Metadata (RFC 9728).
	 *
	 * @return WP_REST_Response
	 */
	public static function rest_protected_resource_metadata(): WP_REST_Response {
		$response = new WP_REST_Response( array(
			'resource'               => rest_url( self::NS . '/mcp' ),
			'authorization_servers'  => array( rest_url( self::NS ) ),
			'scopes_supported'       => array( 'mcp:read', 'mcp:write' ),
			'resource_documentation' => home_url(),
		) );

		$response->header( 'Access-Control-Allow-Origin', '*' );
		$response->header( 'Cache-Control', 'no-store' );

		return $response;
	}

	/**
	 * REST callback: Authorization Server Metadata.
	 *
	 * Serves a single document at both the RFC 8414 OAuth path
	 * (`oauth-authorization-server`) and the OpenID Connect Discovery path
	 * (`openid-configuration`). Real-world clients diverge: ChatGPT probes
	 * the OAuth path; Claude Web probes the OIDC path. Returning the same
	 * doc at both keeps every MCP client happy.
	 *
	 * The OIDC-required `subject_types_supported` and
	 * `id_token_signing_alg_values_supported` are present as stubs even
	 * though we don't issue ID tokens — they make the doc parse as valid
	 * OIDC discovery. Clients that actually request an ID token would fail
	 * elsewhere, but no MCP client does.
	 *
	 * @return WP_REST_Response
	 */
	public static function rest_authorization_server_metadata(): WP_REST_Response {
		$rest_base = rest_url( self::NS );

		$response = new WP_REST_Response( array(
			'issuer'                                => $rest_base,
			'authorization_endpoint'                => $rest_base . '/oauth/authorize',
			'token_endpoint'                        => $rest_base . '/oauth/token',
			'registration_endpoint'                 => $rest_base . '/oauth/register',
			'code_challenge_methods_supported'      => array( 'S256' ),
			'response_types_supported'              => array( 'code' ),
			'grant_types_supported'                 => array( 'authorization_code', 'refresh_token' ),
			'token_endpoint_auth_methods_supported' => array( 'none' ),
			'scopes_supported'                      => array( 'mcp:read', 'mcp:write' ),
			// OIDC discovery stubs — required for the doc to parse as valid
			// OIDC discovery. We do not issue ID tokens; these are present
			// only so OIDC-aware clients (Claude Web) accept the doc and
			// proceed to Dynamic Client Registration.
			'subject_types_supported'                  => array( 'public' ),
			'id_token_signing_alg_values_supported'    => array( 'RS256' ),
		) );

		$response->header( 'Access-Control-Allow-Origin', '*' );
		$response->header( 'Cache-Control', 'no-store' );

		return $response;
	}

	// =========================================================================
	// Dynamic Client Registration (RFC 7591)
	// =========================================================================

	/**
	 * Handle POST /oauth/register — Dynamic Client Registration.
	 *
	 * @param WP_REST_Request $request The incoming request.
	 * @return WP_REST_Response
	 */
	public static function handle_register( WP_REST_Request $request ): WP_REST_Response {
		// Rate limit by IP.
		$ip       = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['REMOTE_ADDR'] ) ) : 'unknown';
		$rate_key = 'mcp_gw_dcr_' . md5( $ip );
		$count    = (int) get_transient( $rate_key );
		if ( $count >= self::DCR_RATE_LIMIT ) {
			return new WP_REST_Response(
				array( 'error' => 'too_many_requests', 'error_description' => 'Rate limit exceeded.' ),
				429
			);
		}
		set_transient( $rate_key, $count + 1, 3600 );

		$body = $request->get_json_params();

		// Validate redirect_uris.
		$redirect_uris = $body['redirect_uris'] ?? array();
		if ( empty( $redirect_uris ) ) {
			return new WP_REST_Response(
				array( 'error' => 'invalid_client_metadata', 'error_description' => 'redirect_uris required.' ),
				400
			);
		}

		foreach ( $redirect_uris as $uri ) {
			if ( ! self::is_redirect_uri_allowed( $uri ) ) {
				return new WP_REST_Response(
					array( 'error' => 'invalid_redirect_uri', 'error_description' => 'Redirect URI not allowed.' ),
					400
				);
			}
		}

		// Generate client_id.
		$client_id = 'mcp_gw_cid_' . wp_generate_uuid4();

		// Store client registration in transient (30 days).
		$client_data = array(
			'client_id'                  => $client_id,
			'client_name'                => sanitize_text_field( $body['client_name'] ?? 'MCP Client' ),
			'redirect_uris'              => $redirect_uris,
			'grant_types'                => array( 'authorization_code' ),
			'response_types'             => array( 'code' ),
			'token_endpoint_auth_method' => 'none',
			'client_id_issued_at'        => time(),
		);

		set_transient( 'mcp_gw_oauth_client_' . $client_id, $client_data, self::CLIENT_REGISTRATION_EXPIRY );

		$response = new WP_REST_Response( $client_data, 201 );
		$response->header( 'Cache-Control', 'no-store' );
		return $response;
	}

	// =========================================================================
	// Authorization Endpoint (handled via init, NOT REST API)
	// =========================================================================

	/**
	 * Handle GET /oauth/authorize — Show consent screen.
	 *
	 * Runs at init priority 1, before the REST API dispatcher. This ensures
	 * WordPress cookie authentication works after wp-login.php redirect
	 * (the REST API would require a nonce which the redirect doesn't carry).
	 *
	 * @return void Outputs HTML and exits.
	 */
	public static function handle_authorize_get_direct(): void {
		// OAuth 2.1 /authorize endpoint per RFC 6749 § 4.1.1: invoked by external
		// clients via redirect with query parameters. CSRF protection is provided
		// by the OAuth `state` parameter (echoed back in the callback redirect),
		// not by WordPress nonces. All query params below are sanitized; the
		// authorization flow itself requires admin login + explicit approval.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$query_params          = wp_unslash( $_GET );
		$response_type         = sanitize_text_field( $query_params['response_type'] ?? '' );
		$client_id             = sanitize_text_field( $query_params['client_id'] ?? '' );
		$redirect_uri          = esc_url_raw( $query_params['redirect_uri'] ?? '' );
		$code_challenge        = sanitize_text_field( $query_params['code_challenge'] ?? '' );
		$code_challenge_method = sanitize_text_field( $query_params['code_challenge_method'] ?? '' );
		$state                 = sanitize_text_field( $query_params['state'] ?? '' );
		$resource              = esc_url_raw( $query_params['resource'] ?? '' );
		$scope                 = sanitize_text_field( $query_params['scope'] ?? 'mcp:read mcp:write' );

		// Validate response_type.
		if ( 'code' !== $response_type ) {
			wp_die( 'Invalid response_type. Must be "code".', 'OAuth Error', array( 'response' => 400 ) );
		}

		// Validate client_id exists.
		$client_data = get_transient( 'mcp_gw_oauth_client_' . $client_id );
		if ( ! $client_data ) {
			wp_die( 'Unknown client_id. Client may need to re-register.', 'OAuth Error', array( 'response' => 400 ) );
		}

		// Validate redirect_uri.
		if ( ! in_array( $redirect_uri, $client_data['redirect_uris'], true ) ) {
			wp_die( 'Redirect URI does not match client registration.', 'OAuth Error', array( 'response' => 400 ) );
		}

		// Validate PKCE.
		if ( 'S256' !== $code_challenge_method || empty( $code_challenge ) ) {
			self::redirect_with_error( $redirect_uri, 'invalid_request', 'PKCE S256 required.', $state );
		}

		// Validate resource parameter (if provided) matches our MCP endpoint.
		if ( ! empty( $resource ) ) {
			$expected_resource = rest_url( self::NS . '/mcp' );
			if ( $resource !== $expected_resource ) {
				self::redirect_with_error( $redirect_uri, 'invalid_resource', 'Resource does not match this server.', $state );
			}
		}

		// Require WordPress admin login.
		// At init time, cookie auth works without nonce — this is why we
		// handle this outside the REST API.
		if ( ! is_user_logged_in() ) {
			$allowed_params = array( 'response_type', 'client_id', 'redirect_uri', 'code_challenge', 'code_challenge_method', 'state', 'resource', 'scope' );
			$filtered_get   = array_intersect_key( $query_params, array_flip( $allowed_params ) );
			$current_url    = rest_url( self::NS . '/oauth/authorize' ) . '?' . http_build_query( $filtered_get );
			wp_safe_redirect( wp_login_url( $current_url ) );
			exit;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			self::redirect_with_error( $redirect_uri, 'access_denied', 'Admin access required.', $state );
		}

		// Render consent page.
		$client_name = esc_html( $client_data['client_name'] );
		$site_name   = esc_html( get_bloginfo( 'name' ) );
		$nonce       = wp_create_nonce( 'mcp_gw_oauth_authorize' );
		$form_action = rest_url( self::NS . '/oauth/authorize' );

		// Build the approval form (outputs HTML directly).
		header( 'Content-Type: text/html; charset=utf-8' );

		echo '<!DOCTYPE html><html><head>';
		echo '<meta charset="utf-8">';
		echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
		echo '<title>' . esc_html__( 'Authorize Connection', 'axtolab-ai-connector' ) . '</title>';
		self::print_authorize_styles();
		echo '</head><body>';
		echo '<div class="card">';
		echo '<h1>' . esc_html__( 'Authorize Connection', 'axtolab-ai-connector' ) . '</h1>';
		echo '<p><span class="client-name">' . esc_html( $client_name ) . '</span> ';
		echo esc_html__( 'wants to connect to', 'axtolab-ai-connector' ) . ' <strong>' . esc_html( $site_name ) . '</strong> ';
		echo esc_html__( 'and access WordPress MCP tools.', 'axtolab-ai-connector' ) . '</p>';

		echo '<div class="scopes"><strong>' . esc_html__( 'Permissions:', 'axtolab-ai-connector' ) . '</strong>';
		echo '<ul>';
		echo '<li>' . esc_html__( 'Read content, media, and site information', 'axtolab-ai-connector' ) . '</li>';
		echo '<li>' . esc_html__( 'Create, edit, and publish content', 'axtolab-ai-connector' ) . '</li>';
		echo '<li>' . esc_html__( 'Manage media, taxonomies, and SEO metadata', 'axtolab-ai-connector' ) . '</li>';
		echo '</ul>';
		echo '<p class="site-info">' . esc_html__( 'Specific capabilities are controlled in the Remote Access settings.', 'axtolab-ai-connector' ) . '</p>';
		echo '</div>';

		// Hidden form with all OAuth params.
		echo '<form method="post" action="' . esc_url( $form_action ) . '">';
		echo '<input type="hidden" name="_wpnonce" value="' . esc_attr( $nonce ) . '">';
		echo '<input type="hidden" name="client_id" value="' . esc_attr( $client_id ) . '">';
		echo '<input type="hidden" name="redirect_uri" value="' . esc_attr( $redirect_uri ) . '">';
		echo '<input type="hidden" name="code_challenge" value="' . esc_attr( $code_challenge ) . '">';
		echo '<input type="hidden" name="code_challenge_method" value="' . esc_attr( $code_challenge_method ) . '">';
		echo '<input type="hidden" name="state" value="' . esc_attr( $state ) . '">';
		echo '<input type="hidden" name="resource" value="' . esc_attr( $resource ) . '">';
		echo '<input type="hidden" name="scope" value="' . esc_attr( $scope ) . '">';

		echo '<div class="actions">';
		echo '<button type="submit" name="action" value="approve" class="btn btn-primary">';
		echo esc_html__( 'Approve', 'axtolab-ai-connector' ) . '</button>';
		echo '<button type="submit" name="action" value="deny" class="btn btn-secondary">';
		echo esc_html__( 'Deny', 'axtolab-ai-connector' ) . '</button>';
		echo '</div>';
		echo '</form>';

		echo '</div>'; // .card
		echo '</body></html>';
		exit;
	}

	/**
	 * Print consent page styles through WordPress' style API.
	 *
	 * @return void
	 */
	private static function print_authorize_styles(): void {
		wp_register_style( 'axtolab-ai-connector-oauth-authorize', false, array(), AXTOLAB_AI_CONNECTOR_VERSION );
		wp_enqueue_style( 'axtolab-ai-connector-oauth-authorize' );
		wp_add_inline_style(
			'axtolab-ai-connector-oauth-authorize',
			'body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; max-width: 480px; margin: 60px auto; padding: 20px; background: #f0f0f1; }' .
			'.card { background: white; border-radius: 8px; padding: 32px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); }' .
			'h1 { font-size: 20px; margin: 0 0 16px; }' .
			'.client-name { font-weight: 600; color: #1d2327; }' .
			'.scopes { margin: 16px 0; padding: 12px; background: #f9f9f9; border-radius: 4px; }' .
			'.scopes li { margin: 4px 0; }' .
			'.actions { margin-top: 24px; display: flex; gap: 12px; }' .
			'.btn { padding: 10px 24px; border-radius: 4px; font-size: 14px; cursor: pointer; border: 1px solid #ccc; }' .
			'.btn-primary { background: #2271b1; color: white; border-color: #2271b1; }' .
			'.btn-primary:hover { background: #135e96; }' .
			'.btn-secondary { background: white; }' .
			'.btn-secondary:hover { background: #f0f0f1; }' .
			'.site-info { color: #646970; font-size: 13px; margin-top: 16px; }'
		);
		wp_print_styles( 'axtolab-ai-connector-oauth-authorize' );
	}

	/**
	 * Handle POST /oauth/authorize — Process approval/denial.
	 *
	 * Runs at init priority 1, before the REST API dispatcher.
	 *
	 * @return void Redirects and exits.
	 */
	public static function handle_authorize_post_direct(): void {
		// Verify WordPress nonce.
		$post_params = wp_unslash( $_POST );
		if ( ! wp_verify_nonce( sanitize_text_field( $post_params['_wpnonce'] ?? '' ), 'mcp_gw_oauth_authorize' ) ) {
			wp_die( 'Invalid nonce.', 'OAuth Error', array( 'response' => 403 ) );
		}

		if ( ! is_user_logged_in() || ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Unauthorized.', 'OAuth Error', array( 'response' => 403 ) );
		}

		$action       = sanitize_text_field( $post_params['action'] ?? '' );
		$client_id    = sanitize_text_field( $post_params['client_id'] ?? '' );
		$redirect_uri = esc_url_raw( $post_params['redirect_uri'] ?? '' );
		$state        = sanitize_text_field( $post_params['state'] ?? '' );

		// Re-validate client.
		$client_data = get_transient( 'mcp_gw_oauth_client_' . $client_id );
		if ( ! $client_data || ! in_array( $redirect_uri, $client_data['redirect_uris'], true ) ) {
			wp_die( 'Invalid client or redirect URI.', 'OAuth Error', array( 'response' => 400 ) );
		}

		if ( 'deny' === $action ) {
			self::redirect_with_error( $redirect_uri, 'access_denied', 'User denied authorization.', $state );
		}

		// Generate authorization code.
		$code = rtrim( strtr( base64_encode( random_bytes( 32 ) ), '+/', '-_' ), '=' );

		// Store code with all context (10-minute TTL, single-use).
		set_transient( 'mcp_gw_oauth_code_' . $code, array(
			'client_id'      => $client_id,
			'redirect_uri'   => $redirect_uri,
			'code_challenge' => sanitize_text_field( $post_params['code_challenge'] ?? '' ),
			'scope'          => sanitize_text_field( $post_params['scope'] ?? '' ),
			'resource'       => esc_url_raw( $post_params['resource'] ?? '' ),
			'created_at'     => time(),
		), self::CODE_EXPIRY );

		// Redirect back to ChatGPT with auth code.
		$redirect = add_query_arg( array(
			'code'  => $code,
			'state' => $state,
		), $redirect_uri );

		self::safe_client_redirect( $redirect );
	}

	// =========================================================================
	// Token Endpoint
	// =========================================================================

	/**
	 * Handle POST /oauth/token — Exchange code for access token.
	 *
	 * @param WP_REST_Request $request The incoming request.
	 * @return WP_REST_Response
	 */
	public static function handle_token( WP_REST_Request $request ): WP_REST_Response {
		$grant_type = $request->get_param( 'grant_type' );

		// Handle refresh token grant.
		if ( 'refresh_token' === $grant_type ) {
			return self::handle_refresh_token( $request );
		}

		if ( 'authorization_code' !== $grant_type ) {
			return self::token_error( 'unsupported_grant_type', 'Only authorization_code and refresh_token are supported.' );
		}

		// Parse form-encoded or JSON body.
		$code          = $request->get_param( 'code' );
		$client_id     = $request->get_param( 'client_id' );
		$redirect_uri  = $request->get_param( 'redirect_uri' );
		$code_verifier = $request->get_param( 'code_verifier' );
		$resource      = $request->get_param( 'resource' );

		if ( empty( $code ) || empty( $client_id ) || empty( $redirect_uri ) || empty( $code_verifier ) ) {
			return self::token_error( 'invalid_request', 'Missing required parameters.' );
		}

		// Retrieve and delete auth code (single-use).
		$code_data = get_transient( 'mcp_gw_oauth_code_' . $code );
		delete_transient( 'mcp_gw_oauth_code_' . $code );

		if ( ! $code_data ) {
			return self::token_error( 'invalid_grant', 'Authorization code expired or already used.' );
		}

		// Validate client_id and redirect_uri match.
		if ( $code_data['client_id'] !== $client_id ) {
			return self::token_error( 'invalid_grant', 'Client ID mismatch.' );
		}
		if ( $code_data['redirect_uri'] !== $redirect_uri ) {
			return self::token_error( 'invalid_grant', 'Redirect URI mismatch.' );
		}

		// Verify PKCE: base64url(sha256(code_verifier)) must equal stored code_challenge.
		$expected_challenge = rtrim( strtr( base64_encode( hash( 'sha256', $code_verifier, true ) ), '+/', '-_' ), '=' );
		if ( ! hash_equals( $code_data['code_challenge'], $expected_challenge ) ) {
			return self::token_error( 'invalid_grant', 'PKCE verification failed.' );
		}

		// Validate resource parameter matches stored code (if both present).
		$stored_resource = $code_data['resource'] ?? '';
		if ( ! empty( $resource ) && ! empty( $stored_resource ) && $resource !== $stored_resource ) {
			return self::token_error( 'invalid_grant', 'Resource mismatch.' );
		}

		// Generate access token.
		$raw_token = 'mcp_gw_oat_' . rtrim( strtr( base64_encode( random_bytes( 32 ) ), '+/', '-_' ), '=' );
		$hash      = hash_hmac( 'sha256', $raw_token, wp_salt( 'auth' ) );

		// Generate refresh token.
		$refresh_token = 'mcp_gw_ort_' . rtrim( strtr( base64_encode( random_bytes( 32 ) ), '+/', '-_' ), '=' );
		$refresh_hash  = hash_hmac( 'sha256', $refresh_token, wp_salt( 'auth' ) );

		// Store token hashes.
		$settings = get_option( 'axtolab_ai_connector_settings', array() );
		$settings['oauth_access_token_hash']     = $hash;
		$settings['oauth_access_token_prefix']   = substr( $raw_token, 0, 20 );
		$settings['oauth_access_token_created']  = current_time( 'mysql' );
		$settings['oauth_access_token_expires']  = time() + self::TOKEN_EXPIRY;
		$settings['oauth_access_token_resource'] = $stored_resource ?: rest_url( self::NS . '/mcp' );
		$settings['oauth_refresh_token_hash']    = $refresh_hash;
		$settings['oauth_refresh_token_expires'] = time() + self::REFRESH_TOKEN_EXPIRY;

		// Store client name from registration.
		$client_data = get_transient( 'mcp_gw_oauth_client_' . $client_id );
		$client_name = $client_data ? $client_data['client_name'] : 'MCP Client';
		$settings['oauth_client_name'] = $client_name;

		update_option( 'axtolab_ai_connector_settings', $settings );

		// Register connection metadata for the Connection Manager.
		$client_type = 'unknown';
		if ( false !== stripos( $client_name, 'chatgpt' ) ) {
			$client_type = 'chatgpt';
		} elseif ( false !== stripos( $client_name, 'claude' ) ) {
			$client_type = 'claude_web';
		}

		Axtolab_AI_Connector_Connections::register_meta(
			Axtolab_AI_Connector_Connections::OAUTH_CONNECTION_ID,
			array(
				'client_type'  => $client_type,
				'client_label' => $client_name,
			)
		);

		$response = new WP_REST_Response( array(
			'access_token'  => $raw_token,
			'token_type'    => 'Bearer',
			'expires_in'    => self::TOKEN_EXPIRY,
			'refresh_token' => $refresh_token,
			'scope'         => $code_data['scope'] ?? 'mcp:read mcp:write',
		), 200 );

		$response->header( 'Cache-Control', 'no-store' );
		$response->header( 'Pragma', 'no-cache' );

		return $response;
	}

	/**
	 * Handle refresh_token grant — rotate access + refresh tokens.
	 *
	 * @param WP_REST_Request $request The incoming request.
	 * @return WP_REST_Response
	 */
	private static function handle_refresh_token( WP_REST_Request $request ): WP_REST_Response {
		$refresh_token = $request->get_param( 'refresh_token' );
		if ( empty( $refresh_token ) ) {
			return self::token_error( 'invalid_request', 'refresh_token required.' );
		}

		// Verify refresh token prefix.
		if ( strpos( $refresh_token, 'mcp_gw_ort_' ) !== 0 ) {
			return self::token_error( 'invalid_grant', 'Invalid refresh token.' );
		}

		$settings    = get_option( 'axtolab_ai_connector_settings', array() );
		$stored_hash = $settings['oauth_refresh_token_hash'] ?? '';
		$expires     = $settings['oauth_refresh_token_expires'] ?? 0;

		if ( empty( $stored_hash ) || time() > $expires ) {
			return self::token_error( 'invalid_grant', 'Refresh token expired.' );
		}

		$provided_hash = hash_hmac( 'sha256', $refresh_token, wp_salt( 'auth' ) );
		if ( ! hash_equals( $stored_hash, $provided_hash ) ) {
			return self::token_error( 'invalid_grant', 'Invalid refresh token.' );
		}

		// Rotate: generate new access token + new refresh token.
		$new_access  = 'mcp_gw_oat_' . rtrim( strtr( base64_encode( random_bytes( 32 ) ), '+/', '-_' ), '=' );
		$new_refresh = 'mcp_gw_ort_' . rtrim( strtr( base64_encode( random_bytes( 32 ) ), '+/', '-_' ), '=' );

		$settings['oauth_access_token_hash']     = hash_hmac( 'sha256', $new_access, wp_salt( 'auth' ) );
		$settings['oauth_access_token_prefix']   = substr( $new_access, 0, 20 );
		$settings['oauth_access_token_created']  = current_time( 'mysql' );
		$settings['oauth_access_token_expires']  = time() + self::TOKEN_EXPIRY;
		$settings['oauth_refresh_token_hash']    = hash_hmac( 'sha256', $new_refresh, wp_salt( 'auth' ) );
		$settings['oauth_refresh_token_expires'] = time() + self::REFRESH_TOKEN_EXPIRY;

		update_option( 'axtolab_ai_connector_settings', $settings );

		$response = new WP_REST_Response( array(
			'access_token'  => $new_access,
			'token_type'    => 'Bearer',
			'expires_in'    => self::TOKEN_EXPIRY,
			'refresh_token' => $new_refresh,
			'scope'         => 'mcp:read mcp:write',
		), 200 );

		$response->header( 'Cache-Control', 'no-store' );
		$response->header( 'Pragma', 'no-cache' );

		return $response;
	}

	// =========================================================================
	// Token verification (called from MCP transport)
	// =========================================================================

	/**
	 * Verify an OAuth access token.
	 *
	 * @param string $token Raw token from Authorization header.
	 * @return bool
	 */
	public static function verify_access_token( string $token ): bool {
		// Only verify tokens with the OAuth prefix.
		if ( strpos( $token, 'mcp_gw_oat_' ) !== 0 ) {
			return false;
		}

		$settings    = get_option( 'axtolab_ai_connector_settings', array() );
		$stored_hash = $settings['oauth_access_token_hash'] ?? '';
		$expires     = $settings['oauth_access_token_expires'] ?? 0;

		if ( empty( $stored_hash ) ) {
			return false;
		}

		// Check expiry.
		if ( time() > $expires ) {
			return false;
		}

		$provided_hash = hash_hmac( 'sha256', $token, wp_salt( 'auth' ) );
		return hash_equals( $stored_hash, $provided_hash );
	}

	/**
	 * Check if an active OAuth token exists.
	 *
	 * @return bool
	 */
	public static function has_active_token(): bool {
		$settings = get_option( 'axtolab_ai_connector_settings', array() );
		$hash     = $settings['oauth_access_token_hash'] ?? '';
		$expires  = $settings['oauth_access_token_expires'] ?? 0;

		return ! empty( $hash ) && time() < $expires;
	}

	/**
	 * Get OAuth token info for admin display.
	 *
	 * @return array
	 */
	public static function get_token_info(): array {
		$settings = get_option( 'axtolab_ai_connector_settings', array() );
		$expires  = $settings['oauth_access_token_expires'] ?? 0;
		$active   = ! empty( $settings['oauth_access_token_hash'] ) && time() < $expires;

		return array(
			'active'      => $active,
			'prefix'      => $settings['oauth_access_token_prefix'] ?? '',
			'created_at'  => $settings['oauth_access_token_created'] ?? '',
			'expires_at'  => $expires ? gmdate( 'Y-m-d H:i', $expires ) : '',
			'client_name' => $settings['oauth_client_name'] ?? '',
		);
	}

	/**
	 * Revoke the OAuth access token.
	 */
	public static function revoke_token(): void {
		$settings = get_option( 'axtolab_ai_connector_settings', array() );
		unset( $settings['oauth_access_token_hash'] );
		unset( $settings['oauth_access_token_prefix'] );
		unset( $settings['oauth_access_token_created'] );
		unset( $settings['oauth_access_token_expires'] );
		unset( $settings['oauth_access_token_resource'] );
		unset( $settings['oauth_refresh_token_hash'] );
		unset( $settings['oauth_refresh_token_expires'] );
		unset( $settings['oauth_client_name'] );
		update_option( 'axtolab_ai_connector_settings', $settings );
	}

	// =========================================================================
	// Helpers
	// =========================================================================

	/**
	 * Check if a redirect URI is in the allowlist.
	 *
	 * Supports explicit allowlist entries plus localhost/127.0.0.1 for
	 * desktop MCP clients (Claude Desktop, Claude Code).
	 *
	 * @param string $uri The redirect URI to check.
	 * @return bool
	 */
	private static function is_redirect_uri_allowed( string $uri ): bool {
		if ( in_array( $uri, self::ALLOWED_REDIRECT_URIS, true ) ) {
			return true;
		}

		// Allow localhost/loopback for desktop MCP clients.
		$parsed = wp_parse_url( $uri );
		if ( $parsed && isset( $parsed['scheme'], $parsed['host'] ) ) {
			if ( 'http' === $parsed['scheme'] && in_array( $parsed['host'], array( 'localhost', '127.0.0.1' ), true ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Redirect to client with an OAuth error.
	 *
	 * @param string $redirect_uri The client redirect URI.
	 * @param string $error        OAuth error code.
	 * @param string $description  Human-readable error description.
	 * @param string $state        The original state parameter.
	 */
	private static function redirect_with_error( string $redirect_uri, string $error, string $description, string $state ): void {
		$redirect = add_query_arg( array(
			'error'             => $error,
			'error_description' => $description,
			'state'             => $state,
		), $redirect_uri );

		self::safe_client_redirect( $redirect );
	}

	/**
	 * Redirect to a pre-validated OAuth client redirect URI.
	 *
	 * OAuth client callbacks can legitimately be off-site. Callers must
	 * validate the redirect URI against the registered client before
	 * reaching this helper.
	 */
	private static function safe_client_redirect( string $redirect ): void {
		$host       = wp_parse_url( $redirect, PHP_URL_HOST );
		$allow_host = static function ( array $hosts ) use ( $host ): array {
			if ( $host && ! in_array( $host, $hosts, true ) ) {
				$hosts[] = $host;
			}
			return $hosts;
		};
		add_filter( 'allowed_redirect_hosts', $allow_host );
		wp_safe_redirect( $redirect );
		remove_filter( 'allowed_redirect_hosts', $allow_host );
		exit;
	}

	/**
	 * Return a token endpoint error response.
	 *
	 * @param string $error       OAuth error code.
	 * @param string $description Human-readable description.
	 * @param int    $status      HTTP status code.
	 * @return WP_REST_Response
	 */
	private static function token_error( string $error, string $description, int $status = 400 ): WP_REST_Response {
		return new WP_REST_Response( array(
			'error'             => $error,
			'error_description' => $description,
		), $status );
	}
}

if ( ! class_exists( 'MCP_Gateway_OAuth', false ) ) {
	class_alias( 'Axtolab_AI_Connector_OAuth', 'MCP_Gateway_OAuth' );
}
