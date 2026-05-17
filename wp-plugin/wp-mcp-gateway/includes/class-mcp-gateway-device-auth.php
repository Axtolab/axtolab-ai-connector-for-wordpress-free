<?php
/**
 * MCP Gateway Device Auth
 *
 * Implements the OAuth 2.0 Device Authorization Grant (RFC 8628) pattern.
 * Registers three public/protected REST routes under `axtolab-ai-connector/v1/device/`
 * that allow the MCP CLI to authenticate to WordPress without exposing
 * credentials in config files.
 *
 * Route summary:
 *   POST /device/code      — public; starts the flow; rate-limited per IP
 *   GET  /device/token     — public; polled by CLI until approved or expired
 *   POST /device/authorize — requires manage_options; admin enters user_code
 *
 * @package WP_MCP_Gateway
 * @since   0.1.12
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Axtolab_AI_Connector_Device_Auth
 *
 * Registers and handles the device-authorization REST endpoints.
 */
class Axtolab_AI_Connector_Device_Auth {

	/**
	 * REST namespace (shared with Axtolab_AI_Connector_REST).
	 *
	 * @var string
	 */
	const NAMESPACE = 'axtolab-ai-connector/v1';

	/**
	 * Transient prefix for device codes.
	 *
	 * @var string
	 */
	const TRANSIENT_DEVICE = 'axtolab_ai_connector_device_';

	/**
	 * Transient prefix for user-code → device-code reverse lookup.
	 *
	 * @var string
	 */
	const TRANSIENT_USERCODE = 'axtolab_ai_connector_usercode_';

	/**
	 * Transient prefix for per-IP rate-limit counters.
	 *
	 * @var string
	 */
	const TRANSIENT_RATELIMIT = 'axtolab_ai_connector_ratelimit_';

	/**
	 * Device-code / user-code TTL in seconds (15 minutes).
	 *
	 * @var int
	 */
	const CODE_TTL = 900;

	/**
	 * Rate-limit window in seconds (1 hour).
	 *
	 * @var int
	 */
	const RATE_LIMIT_WINDOW = 3600;

	/**
	 * Maximum device-code requests per IP per rate-limit window.
	 *
	 * @var int
	 */
	const RATE_LIMIT_MAX = 10;

	/**
	 * Recommended polling interval in seconds returned to the client.
	 *
	 * @var int
	 */
	const POLL_INTERVAL = 5;

	/**
	 * Register REST routes.
	 *
	 * Called via `rest_api_init`.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		// POST /device/code — public; starts the flow.
		register_rest_route(
			self::NAMESPACE,
			'/device/code',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle_device_code' ),
				'permission_callback' => '__return_true',
			)
		);

		// GET /device/token — public; polled by CLI.
		register_rest_route(
			self::NAMESPACE,
			'/device/token',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'handle_device_token' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'device_code' => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);

		// POST /device/authorize — requires manage_options.
		register_rest_route(
			self::NAMESPACE,
			'/device/authorize',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle_device_authorize' ),
				'permission_callback' => array( $this, 'require_manage_options' ),
			)
		);
	}

	// ── Permission callbacks ───────────────────────────────────────────────────

	/**
	 * Require the current user to hold the `manage_options` capability.
	 *
	 * Used as the `permission_callback` for the /device/authorize route.
	 *
	 * @return bool|WP_Error True on success, WP_Error on failure.
	 */
	public function require_manage_options() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'You do not have permission to authorize devices.', 'axtolab-ai-connector' ),
				array( 'status' => 403 )
			);
		}

		$multisite_allowed = Axtolab_AI_Connector_Free_Gates::check_multisite_allowed();
		if ( is_wp_error( $multisite_allowed ) ) {
			return $multisite_allowed;
		}

		return true;
	}

	// ── Route handlers ────────────────────────────────────────────────────────

	/**
	 * POST /device/code
	 *
	 * Generates a device_code / user_code pair, stores them as transients, and
	 * returns the data the CLI needs to display to the user and start polling.
	 *
	 * Rate-limited to {@see RATE_LIMIT_MAX} requests per IP per hour.
	 * Requires HTTPS unless WP_DEBUG is true.
	 *
	 * @param  WP_REST_Request $request Incoming REST request.
	 * @return WP_REST_Response
	 */
	public function handle_device_code( WP_REST_Request $request ): WP_REST_Response {
		$multisite_allowed = Axtolab_AI_Connector_Free_Gates::check_multisite_allowed();
		if ( is_wp_error( $multisite_allowed ) ) {
			return Axtolab_AI_Connector_Response::from_wp_error( $multisite_allowed, 403 );
		}

		// Enforce HTTPS in production.
		if ( ! $this->is_https_request() && ! ( defined( 'WP_DEBUG' ) && WP_DEBUG ) ) {
			return Axtolab_AI_Connector_Response::error(
				'https_required',
				__( 'Device authorization requires an HTTPS connection.', 'axtolab-ai-connector' ),
				400
			);
		}

		// Rate-limit by IP.
		$ip      = $this->get_client_ip();
		$rl_key  = self::TRANSIENT_RATELIMIT . md5( $ip );
		$rl_count = (int) get_transient( $rl_key );

		if ( $rl_count >= self::RATE_LIMIT_MAX ) {
			return Axtolab_AI_Connector_Response::error(
				'rate_limit_exceeded',
				__( 'Too many device code requests. Please wait before trying again.', 'axtolab-ai-connector' ),
				429
			);
		}

		// Increment rate-limit counter (set fresh TTL on first hit, preserve on subsequent).
		if ( 0 === $rl_count ) {
			set_transient( $rl_key, 1, self::RATE_LIMIT_WINDOW );
		} else {
			// get_transient does not tell us remaining TTL, so we just increment
			// the stored value without resetting the expiry window.
			set_transient( $rl_key, $rl_count + 1, self::RATE_LIMIT_WINDOW );
		}

		// Generate codes.
		try {
			$device_code = bin2hex( random_bytes( 16 ) ); // 32 hex chars.
			$user_code   = $this->generate_user_code();
		} catch ( Exception $e ) {
			return Axtolab_AI_Connector_Response::error(
				'random_bytes_failed',
				__( 'Could not generate a secure device code. Please try again.', 'axtolab-ai-connector' ),
				500
			);
		}

		// Accept optional client_label from the MCP CLI.
		$client_label = '';
		$params       = $request->get_json_params();
		if ( ! empty( $params['client_label'] ) ) {
			$client_label = sanitize_text_field( $params['client_label'] );
		}

		// Build device-code transient payload.
		$device_data = array(
			'status'       => 'pending',
			'user_code'    => $user_code,
			'created'      => time(),
			'client_label' => $client_label,
		);

		// Store both transients with the same TTL.
		set_transient( self::TRANSIENT_DEVICE . $device_code, $device_data, self::CODE_TTL );
		set_transient( self::TRANSIENT_USERCODE . $user_code, $device_code, self::CODE_TTL );

		// Build verification URL — points to the plugin admin page.
		$verification_url = add_query_arg(
			'page',
			'wp-mcp-gateway',
			admin_url( 'admin.php' )
		);

		return $this->with_no_store(
			Axtolab_AI_Connector_Response::success(
				array(
					'device_code'      => $device_code,
					'user_code'        => $user_code,
					'verification_url' => $verification_url,
					'expires_in'       => self::CODE_TTL,
					'interval'         => self::POLL_INTERVAL,
				)
			)
		);
	}

	/**
	 * GET /device/token
	 *
	 * Polled by the CLI. Returns current status of the device authorization.
	 * On first approved response, transients are deleted (one-time use).
	 *
	 * @param  WP_REST_Request $request Incoming REST request.
	 * @return WP_REST_Response
	 */
	public function handle_device_token( WP_REST_Request $request ): WP_REST_Response {
		$multisite_allowed = Axtolab_AI_Connector_Free_Gates::check_multisite_allowed();
		if ( is_wp_error( $multisite_allowed ) ) {
			return Axtolab_AI_Connector_Response::from_wp_error( $multisite_allowed, 403 );
		}

		$device_code = sanitize_text_field( $request->get_param( 'device_code' ) );

		if ( empty( $device_code ) ) {
			return Axtolab_AI_Connector_Response::error(
				'missing_device_code',
				__( 'The device_code parameter is required.', 'axtolab-ai-connector' ),
				400
			);
		}

		$device_data = get_transient( self::TRANSIENT_DEVICE . $device_code );

		if ( false === $device_data ) {
			// Transient has expired or never existed.
			return $this->with_no_store( new WP_REST_Response( array( 'status' => 'not_found' ), 404 ) );
		}

		$status = $device_data['status'] ?? 'pending';

		if ( 'pending' === $status ) {
			return $this->with_no_store( new WP_REST_Response( array( 'status' => 'pending' ), 200 ) );
		}

		if ( 'approved' === $status ) {
			// Build the one-time response payload.
			$response_data = array(
				'status'            => 'approved',
				'token'             => $device_data['token'] ?? '',
				'wp_plugin_base_url' => $device_data['wp_plugin_base_url'] ?? rest_url( self::NAMESPACE ),
				'service_user'      => $device_data['service_user'] ?? 'axtolab-connector-service',
				'site_name'         => $device_data['site_name'] ?? get_bloginfo( 'name' ),
			);

			// Delete transients — credentials are one-time use.
			$user_code = $device_data['user_code'] ?? '';
			delete_transient( self::TRANSIENT_DEVICE . $device_code );
			if ( ! empty( $user_code ) ) {
				delete_transient( self::TRANSIENT_USERCODE . $user_code );
			}

			return $this->with_no_store( new WP_REST_Response( $response_data, 200 ) );
		}

		// Unknown status — treat as expired / not found.
		return $this->with_no_store( new WP_REST_Response( array( 'status' => 'not_found' ), 404 ) );
	}

	/**
	 * POST /device/authorize
	 *
	 * Called by the WordPress admin after entering the user_code. Creates an
	 * Application Password for the `axtolab-connector-service` account and marks
	 * the device code approved so the next poll returns the token.
	 *
	 * Requires `manage_options` (enforced by permission_callback).
	 *
	 * @param  WP_REST_Request $request Incoming REST request.
	 * @return WP_REST_Response
	 */
	public function handle_device_authorize( WP_REST_Request $request ): WP_REST_Response {
		$multisite_allowed = Axtolab_AI_Connector_Free_Gates::check_multisite_allowed();
		if ( is_wp_error( $multisite_allowed ) ) {
			return Axtolab_AI_Connector_Response::from_wp_error( $multisite_allowed, 403 );
		}

		// Accept JSON body or form-encoded body.
		$params    = $request->get_json_params();
		$user_code = isset( $params['user_code'] ) ? $params['user_code'] : $request->get_param( 'user_code' );

		if ( empty( $user_code ) ) {
			return Axtolab_AI_Connector_Response::error(
				'missing_user_code',
				__( 'The user_code field is required.', 'axtolab-ai-connector' ),
				400
			);
		}

		// Normalise: strip spaces/dashes, uppercase, then reformat XXXX-XXXX.
		$user_code = strtoupper( preg_replace( '/[^A-Z0-9]/i', '', $user_code ) );
		if ( 8 !== strlen( $user_code ) ) {
			return Axtolab_AI_Connector_Response::error(
				'invalid_user_code',
				__( 'The user code format is invalid. Expected XXXX-XXXX.', 'axtolab-ai-connector' ),
				400
			);
		}
		$user_code_formatted = substr( $user_code, 0, 4 ) . '-' . substr( $user_code, 4, 4 );

		// Reverse lookup: user_code → device_code.
		$device_code = get_transient( self::TRANSIENT_USERCODE . $user_code_formatted );

		if ( false === $device_code ) {
			return Axtolab_AI_Connector_Response::error(
				'invalid_user_code',
				__( 'That code was not found or has expired. Please start the connection again.', 'axtolab-ai-connector' ),
				404
			);
		}

		// Fetch the device-code transient and verify it is still pending.
		$device_data = get_transient( self::TRANSIENT_DEVICE . $device_code );

		if ( false === $device_data || 'pending' !== ( $device_data['status'] ?? '' ) ) {
			return Axtolab_AI_Connector_Response::error(
				'invalid_state',
				__( 'This device code is no longer valid. Please start the connection again.', 'axtolab-ai-connector' ),
				400
			);
		}

		// Retrieve the service account user ID.
		$service_user_id = (int) get_option( 'axtolab_ai_connector_service_user_id', 0 );

		if ( ! $service_user_id || ! get_user_by( 'id', $service_user_id ) ) {
			return Axtolab_AI_Connector_Response::error(
				'service_account_missing',
				__( 'The MCP Gateway service account does not exist. Please deactivate and reactivate the plugin.', 'axtolab-ai-connector' ),
				500
			);
		}

		// Ensure the Application Passwords feature is available (WP 5.6+).
		if ( ! class_exists( 'WP_Application_Passwords' ) ) {
			return Axtolab_AI_Connector_Response::error(
				'app_passwords_unavailable',
				__( 'Application Passwords are not available on this site.', 'axtolab-ai-connector' ),
				500
			);
		}

		// Use client_label from device code request if available, otherwise fallback.
		$client_label = ! empty( $device_data['client_label'] ) ? $device_data['client_label'] : '';
		$label        = $client_label ? $client_label : sprintf(
			/* translators: %s: ISO date and time */
			__( 'MCP Gateway — %s', 'axtolab-ai-connector' ),
			gmdate( 'Y-m-d H:i' )
		);

		// Create the Application Password for the service account.
		$result = WP_Application_Passwords::create_new_application_password(
			$service_user_id,
			array( 'name' => $label )
		);

		if ( is_wp_error( $result ) ) {
			return Axtolab_AI_Connector_Response::from_wp_error( $result, 500 );
		}

		// $result[0] is the plaintext password (only available at creation time).
		// $result[1] is the password record with uuid, name, etc.
		$plaintext_password = $result[0];
		$password_record    = $result[1];

		// Register connection metadata for the Connection Manager.
		if ( ! empty( $password_record['uuid'] ) ) {
			// Detect client type from the label.
			$client_type = 'cli';
			if ( false !== stripos( $client_label, 'Claude Desktop' ) ) {
				$client_type = 'claude_desktop';
			} elseif ( false !== stripos( $client_label, 'Cowork' ) ) {
				$client_type = 'cowork';
			}

			Axtolab_AI_Connector_Connections::register_meta(
				$password_record['uuid'],
				array(
					'client_type'  => $client_type,
					'client_label' => $label,
				)
			);
		}

		// Update the device-code transient to approved with credentials.
		$approved_data = array(
			'status'            => 'approved',
			'token'             => $plaintext_password,
			'service_user'      => 'axtolab-connector-service',
			'wp_plugin_base_url' => rest_url( self::NAMESPACE ),
			'site_name'         => get_bloginfo( 'name' ),
			'user_code'         => $user_code_formatted, // kept so /device/token can delete it.
		);

		// Preserve remaining TTL: transient was set with CODE_TTL; we update
		// in-place using the same key. The remaining time is unknown so we
		// reset to CODE_TTL — the window is generous enough for the poll.
		set_transient( self::TRANSIENT_DEVICE . $device_code, $approved_data, self::CODE_TTL );

		// Delete the user-code reverse lookup — it is no longer needed.
		delete_transient( self::TRANSIENT_USERCODE . $user_code_formatted );

		return $this->with_no_store(
			Axtolab_AI_Connector_Response::success(
				array(
					'success' => true,
					'message' => __( 'Claude has been authorized. You can close this tab.', 'axtolab-ai-connector' ),
				)
			)
		);
	}

	// ── Helpers ───────────────────────────────────────────────────────────────

	/**
	 * Mark device-flow responses as uncacheable.
	 *
	 * Poll responses move from pending to approved for the same URL, so hosts
	 * and page caches must not reuse an earlier pending response.
	 *
	 * @param WP_REST_Response $response Response object to annotate.
	 * @return WP_REST_Response
	 */
	private function with_no_store( WP_REST_Response $response ): WP_REST_Response {
		$response->header( 'Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0' );
		$response->header( 'Pragma', 'no-cache' );
		$response->header( 'Expires', 'Wed, 11 Jan 1984 05:00:00 GMT' );

		return $response;
	}

	/**
	 * Generate a human-readable user code in XXXX-XXXX format.
	 *
	 * Uses uppercase letters and digits, then formats as two groups of 4
	 * separated by a hyphen. Characters that are visually ambiguous (O, 0, I,
	 * 1, L) are excluded to reduce transcription errors.
	 *
	 * @throws Exception If random_bytes() fails (should only happen on broken OS).
	 * @return string 9-character code, e.g. "ABCD-EF23".
	 */
	private function generate_user_code(): string {
		// Unambiguous uppercase alphanumeric alphabet.
		$alphabet = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
		$alphabet_length = strlen( $alphabet );
		$code     = '';

		// Generate 8 characters of output.
		while ( strlen( $code ) < 8 ) {
			// Generate random bytes and use rejection sampling to avoid modulo bias.
			$byte = ord( random_bytes( 1 ) );
			if ( $byte < $alphabet_length * (int) floor( 256 / $alphabet_length ) ) {
				$code .= $alphabet[ $byte % $alphabet_length ];
			}
		}

		return substr( $code, 0, 4 ) . '-' . substr( $code, 4, 4 );
	}

	/**
	 * Determine whether the current request arrived over HTTPS.
	 *
	 * Checks both the server port/protocol and common reverse-proxy headers.
	 *
	 * @return bool
	 */
	private function is_https_request(): bool {
		if ( is_ssl() ) {
			return true;
		}

		// Honour trusted reverse-proxy headers.
		$forwarded_proto = isset( $_SERVER['HTTP_X_FORWARDED_PROTO'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['HTTP_X_FORWARDED_PROTO'] ) ) : '';
		if ( 'https' === strtolower( $forwarded_proto ) ) {
			return true;
		}

		$forwarded_ssl = isset( $_SERVER['HTTP_X_FORWARDED_SSL'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['HTTP_X_FORWARDED_SSL'] ) ) : '';
		if ( 'on' === strtolower( $forwarded_ssl ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Retrieve the best available client IP address.
	 *
	 * Prefers `REMOTE_ADDR` as the authoritative source; falls back to
	 * forwarded headers only when REMOTE_ADDR is a known private/localhost
	 * address (i.e. the site sits behind a trusted proxy).
	 *
	 * @return string Sanitized IP address, or '0.0.0.0' if undetermined.
	 */
	private function get_client_ip(): string {
		$remote_addr = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['REMOTE_ADDR'] ) ) : '';

		// If REMOTE_ADDR is a public address, use it directly.
		if ( $remote_addr && ! $this->is_private_ip( $remote_addr ) ) {
			return sanitize_text_field( $remote_addr );
		}

		// Behind a proxy — check forwarded headers in priority order.
		$forwarded_for = isset( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) : '';
		if ( $forwarded_for ) {
			// The header may contain a comma-separated list; the first entry is the origin.
			$ip = trim( explode( ',', $forwarded_for )[0] );
			if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
				return sanitize_text_field( $ip );
			}
		}

		$real_ip = isset( $_SERVER['HTTP_X_REAL_IP'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['HTTP_X_REAL_IP'] ) ) : '';
		if ( $real_ip && filter_var( $real_ip, FILTER_VALIDATE_IP ) ) {
			return sanitize_text_field( $real_ip );
		}

		return $remote_addr ? sanitize_text_field( $remote_addr ) : '0.0.0.0';
	}

	/**
	 * Check whether an IP address is in a private/reserved range.
	 *
	 * @param  string $ip IPv4 or IPv6 address.
	 * @return bool
	 */
	private function is_private_ip( string $ip ): bool {
		return false === filter_var(
			$ip,
			FILTER_VALIDATE_IP,
			FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
		);
	}
}

if ( ! class_exists( 'MCP_Gateway_Device_Auth', false ) ) {
	class_alias( 'Axtolab_AI_Connector_Device_Auth', 'MCP_Gateway_Device_Auth' );
}
