<?php
/**
 * MCP Gateway Connections
 *
 * Core class for connection management. Provides a unified view of all
 * MCP client connections (Application Passwords + OAuth tokens) with
 * label management, last-active tracking, and per-connection revoke.
 *
 * Connection storage shape (since the round-6 refactor that removed the
 * single shared service account):
 *
 *   option `axtolab_ai_connector_connections` = array<string, array{
 *       wp_user_id:    int,    // WordPress user the App Password belongs to
 *       client_type:   string, // claude_desktop | cowork | chatgpt | …
 *       client_label:  string, // display name (user-editable)
 *       auth_method:   string, // app_password | oauth
 *       registered_at: int,    // Unix timestamp
 *   }>
 *
 * The key is the App Password UUID, or {@see self::OAUTH_CONNECTION_ID} for
 * the single OAuth token. The legacy per-UUID `META_PREFIX` /
 * `LAST_ACTIVE_PREFIX` / `CAPABILITIES_PREFIX` / `ALLOWED_AUTHORS_PREFIX`
 * options stay in place — only the connection-set membership moves to the
 * registry option.
 *
 * @package WP_MCP_Gateway
 * @since   0.1.30
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Axtolab_AI_Connector_Connections
 *
 * Static methods for listing, renaming, revoking, and tracking connections.
 */
if ( class_exists( 'Axtolab_AI_Connector_Connections', false ) ) {
	return;
}

class Axtolab_AI_Connector_Connections {

	/**
	 * Option name for the connections registry array.
	 *
	 * @var string
	 */
	const REGISTRY_OPTION = 'axtolab_ai_connector_connections';

	/**
	 * Option prefix for connection metadata.
	 *
	 * @var string
	 */
	const META_PREFIX = '_axtolab_ai_connector_connection_meta_';

	/**
	 * Option prefix for last-active timestamps.
	 *
	 * @var string
	 */
	const LAST_ACTIVE_PREFIX = '_axtolab_ai_connector_last_active_';

	/**
	 * Transient prefix for throttling touch() writes.
	 *
	 * @var string
	 */
	const THROTTLE_PREFIX = '_axtolab_ai_connector_touch_throttle_';

	/**
	 * Minimum interval between DB writes for touch(), in seconds.
	 *
	 * @var int
	 */
	const TOUCH_THROTTLE = 60;

	/**
	 * Static cache for get_all_connections() to avoid redundant DB queries
	 * within the same request (e.g. admin page renders table + status check).
	 *
	 * @var array[]|null
	 */
	private static $connections_cache = null;

	/**
	 * Fixed connection ID used for the single OAuth token.
	 *
	 * @var string
	 */
	const OAUTH_CONNECTION_ID = 'oauth_token';

	/**
	 * Option prefix for per-connection capability sets.
	 *
	 * @var string
	 */
	const CAPABILITIES_PREFIX = '_axtolab_ai_connector_connection_caps_';

	/**
	 * Option prefix for per-connection allowed author ID lists.
	 *
	 * @var string
	 */
	const ALLOWED_AUTHORS_PREFIX = '_axtolab_ai_connector_connection_authors_';

	/**
	 * Option prefix for per-connection sensitive-action consent overrides.
	 *
	 * @var string
	 */
	const CONSENT_POLICY_PREFIX = '_axtolab_ai_connector_connection_consent_';

	/**
	 * Tracks the connection ID of the currently authenticated request.
	 *
	 * @var string|null
	 */
	private static $current_connection_id = null;

	// ── Registry helpers ─────────────────────────────────────────────────────

	/**
	 * Get the raw registry array.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function get_registry() {
		$registry = get_option( self::REGISTRY_OPTION, array() );
		return is_array( $registry ) ? $registry : array();
	}

	/**
	 * Replace the registry option.
	 *
	 * @param array $registry The new registry array.
	 * @return void
	 */
	private static function save_registry( array $registry ) {
		update_option( self::REGISTRY_OPTION, $registry, false );
		self::invalidate_cache();
	}

	/**
	 * Record a newly-created connection in the registry.
	 *
	 * @param string $connection_id App Password UUID or self::OAUTH_CONNECTION_ID.
	 * @param int    $wp_user_id    WordPress user ID the connection authenticates as.
	 * @param array  $meta          Metadata: { client_type: string, client_label: string, auth_method: string }.
	 * @return void
	 */
	public static function register_connection( $connection_id, $wp_user_id, $meta = array() ) {
		if ( ! self::is_valid_connection_id( $connection_id ) ) {
			return;
		}
		$registry                   = self::get_registry();
		$registry[ $connection_id ] = array(
			'wp_user_id'    => (int) $wp_user_id,
			'client_type'   => isset( $meta['client_type'] ) ? sanitize_text_field( $meta['client_type'] ) : 'unknown',
			'client_label'  => isset( $meta['client_label'] ) ? sanitize_text_field( $meta['client_label'] ) : '',
			'auth_method'   => isset( $meta['auth_method'] ) ? sanitize_text_field( $meta['auth_method'] ) : 'app_password',
			'registered_at' => time(),
		);
		self::save_registry( $registry );

		// Mirror to the legacy META_PREFIX option so existing code paths
		// (admin renderer, OAuth flow) that read it directly keep working
		// without a parallel migration.
		update_option(
			self::META_PREFIX . $connection_id,
			array(
				'client_type'   => $registry[ $connection_id ]['client_type'],
				'client_label'  => $registry[ $connection_id ]['client_label'],
				'registered_at' => $registry[ $connection_id ]['registered_at'],
			),
			false
		);
	}

	/**
	 * Look up the WordPress user ID for a connection.
	 *
	 * @param string $connection_id App Password UUID or self::OAUTH_CONNECTION_ID.
	 * @return int|null User ID, or null if the connection is unknown or has no wp_user_id.
	 */
	public static function get_wp_user_id( $connection_id ) {
		$registry = self::get_registry();
		if ( ! isset( $registry[ $connection_id ] ) ) {
			return null;
		}
		$id = isset( $registry[ $connection_id ]['wp_user_id'] ) ? (int) $registry[ $connection_id ]['wp_user_id'] : 0;
		return $id > 0 ? $id : null;
	}

	/**
	 * Return a single connection by ID, or null.
	 *
	 * @param string $connection_id App Password UUID or self::OAUTH_CONNECTION_ID.
	 * @return array|null
	 */
	public static function get_connection( $connection_id ) {
		$all = self::get_all_connections();
		foreach ( $all as $conn ) {
			if ( $conn['id'] === $connection_id ) {
				return $conn;
			}
		}
		return null;
	}

	/**
	 * Resolve an App Password UUID to the registered connection (if any).
	 *
	 * Used by the Basic-auth fallback in the REST layer when the
	 * application_password_did_authenticate hook does not fire.
	 *
	 * @param string $uuid Application Password UUID.
	 * @return array|null Registry row including 'id' key, or null.
	 */
	public static function get_by_uuid( $uuid ) {
		$registry = self::get_registry();
		if ( ! isset( $registry[ $uuid ] ) ) {
			return null;
		}
		$row       = $registry[ $uuid ];
		$row['id'] = $uuid;
		return $row;
	}

	// ── Read operations ──────────────────────────────────────────────────────

	/**
	 * Get all active connections across all auth methods.
	 *
	 * Returns a unified array sorted by last_active (most recent first).
	 *
	 * @return array[] Each entry: {
	 *     id: string,          // unique identifier (app password UUID or 'oauth_token')
	 *     wp_user_id: int,     // WordPress user ID (the App Password owner; 0 for needs-reauth)
	 *     wp_user_login: string,
	 *     label: string,       // user-editable display name
	 *     client_type: string, // claude_desktop, cowork, chatgpt, claude_web, cli, unknown
	 *     auth_method: string, // app_password, oauth
	 *     created: int,        // Unix timestamp
	 *     last_active: int,    // Unix timestamp (0 if never)
	 *     last_ip: string,     // IP address or empty
	 *     needs_reauth: bool,  // wp_user_id is missing/orphaned/zero
	 * }
	 */
	public static function get_all_connections() {
		if ( null !== self::$connections_cache ) {
			return self::$connections_cache;
		}

		$connections = array();
		$registry    = self::get_registry();

		// 1. App Password connections from the registry.
		foreach ( $registry as $id => $row ) {
			if ( self::OAUTH_CONNECTION_ID === $id ) {
				continue; // Handled below from oauth settings (authoritative for expiry).
			}

			$wp_user_id   = isset( $row['wp_user_id'] ) ? (int) $row['wp_user_id'] : 0;
			$wp_user      = $wp_user_id ? get_user_by( 'id', $wp_user_id ) : false;
			$needs_reauth = ! ( $wp_user instanceof WP_User );

			$last_active = (int) get_option( self::LAST_ACTIVE_PREFIX . $id, 0 );
			$created     = isset( $row['registered_at'] ) ? (int) $row['registered_at'] : 0;
			$last_ip     = '';

			// Pull live data from the App Password record when the user still exists.
			if ( $wp_user instanceof WP_User && class_exists( 'WP_Application_Passwords' ) ) {
				$passwords = WP_Application_Passwords::get_user_application_passwords( $wp_user_id );
				if ( is_array( $passwords ) ) {
					foreach ( $passwords as $pwd ) {
						if ( $pwd['uuid'] === $id ) {
							if ( ! $last_active && ! empty( $pwd['last_used'] ) ) {
								$last_active = (int) $pwd['last_used'];
							}
							if ( ! $created && isset( $pwd['created'] ) ) {
								$created = (int) $pwd['created'];
							}
							if ( ! empty( $pwd['last_ip'] ) ) {
								$last_ip = $pwd['last_ip'];
							}
							break;
						}
					}
				}
			}

			$connections[] = array(
				'id'              => $id,
				'wp_user_id'      => $wp_user_id,
				'wp_user_login'   => $wp_user instanceof WP_User ? $wp_user->user_login : '',
				'wp_user_display' => $wp_user instanceof WP_User ? $wp_user->display_name : '',
				'label'           => ! empty( $row['client_label'] ) ? $row['client_label'] : __( 'MCP Connection', 'axtolab-ai-connector' ),
				'client_type'     => ! empty( $row['client_type'] ) ? $row['client_type'] : 'unknown',
				'auth_method'     => ! empty( $row['auth_method'] ) ? $row['auth_method'] : 'app_password',
				'created'         => $created,
				'last_active'     => $last_active,
				'last_ip'         => $last_ip,
				'capabilities'    => self::get_capabilities( $id ),
				'allowed_authors' => self::get_allowed_authors( $id ),
				'tool_consent_policy' => Axtolab_AI_Connector_Tool_Consent_Policy::exported_policy( $id ),
				'needs_reauth'    => $needs_reauth,
			);
		}

		// 2. OAuth token (at most one active at a time).
		$settings = get_option( 'axtolab_ai_connector_settings', array() );

		if ( ! empty( $settings['oauth_access_token_hash'] ) ) {
			$expires = isset( $settings['oauth_access_token_expires'] ) ? (int) $settings['oauth_access_token_expires'] : 0;
			$active  = time() < $expires;

			if ( $active ) {
				$oauth_id    = self::OAUTH_CONNECTION_ID;
				$row         = isset( $registry[ $oauth_id ] ) ? $registry[ $oauth_id ] : array();
				$last_active = (int) get_option( self::LAST_ACTIVE_PREFIX . $oauth_id, 0 );
				$created_str = isset( $settings['oauth_access_token_created'] ) ? $settings['oauth_access_token_created'] : '';
				$created_ts  = $created_str ? strtotime( $created_str ) : 0;
				$client_name = isset( $settings['oauth_client_name'] ) ? $settings['oauth_client_name'] : 'MCP Client';

				$wp_user_id   = isset( $row['wp_user_id'] ) ? (int) $row['wp_user_id'] : 0;
				$wp_user      = $wp_user_id ? get_user_by( 'id', $wp_user_id ) : false;
				$needs_reauth = ! ( $wp_user instanceof WP_User );

				// Auto-detect client type from client_name when not yet stored.
				$client_type = ! empty( $row['client_type'] ) ? $row['client_type'] : 'unknown';
				if ( 'unknown' === $client_type ) {
					if ( false !== stripos( $client_name, 'chatgpt' ) ) {
						$client_type = 'chatgpt';
					} elseif ( false !== stripos( $client_name, 'claude' ) ) {
						$client_type = 'claude_web';
					}
				}

				$connections[] = array(
					'id'              => $oauth_id,
					'wp_user_id'      => $wp_user_id,
					'wp_user_login'   => $wp_user instanceof WP_User ? $wp_user->user_login : '',
					'wp_user_display' => $wp_user instanceof WP_User ? $wp_user->display_name : '',
					'label'           => ! empty( $row['client_label'] ) ? $row['client_label'] : $client_name,
					'client_type'     => $client_type,
					'auth_method'     => 'oauth',
					'created'         => $created_ts ? (int) $created_ts : 0,
					'last_active'     => $last_active,
					'last_ip'         => '',
					'capabilities'    => self::get_capabilities( self::OAUTH_CONNECTION_ID ),
					'allowed_authors' => self::get_allowed_authors( self::OAUTH_CONNECTION_ID ),
					'tool_consent_policy' => Axtolab_AI_Connector_Tool_Consent_Policy::exported_policy( self::OAUTH_CONNECTION_ID ),
					'needs_reauth'    => $needs_reauth,
				);
			}
		}

		// Sort by last_active descending (most recent first).
		usort(
			$connections,
			function ( $a, $b ) {
				return $b['last_active'] - $a['last_active'];
			}
		);

		self::$connections_cache = $connections;

		return $connections;
	}

	/**
	 * Invalidate the connections cache.
	 *
	 * Call after any write operation that changes the connections list
	 * (rename, revoke, register_meta, etc.).
	 *
	 * @return void
	 */
	public static function invalidate_cache() {
		self::$connections_cache = null;
	}

	// ── Write operations ─────────────────────────────────────────────────────

	/**
	 * Rename a connection.
	 *
	 * @param string $connection_id The app password UUID or 'oauth_token'.
	 * @param string $new_label     The new display name.
	 * @return string|WP_Error The stored label (may be truncated) on success, or WP_Error.
	 */
	public static function rename( $connection_id, $new_label ) {
		if ( ! self::is_valid_connection_id( $connection_id ) ) {
			return new WP_Error( 'invalid_id', __( 'Invalid connection ID.', 'axtolab-ai-connector' ) );
		}

		$new_label = sanitize_text_field( trim( $new_label ) );

		if ( empty( $new_label ) ) {
			return new WP_Error( 'empty_label', __( 'Label cannot be empty.', 'axtolab-ai-connector' ) );
		}

		// Truncate to a reasonable length.
		if ( mb_strlen( $new_label ) > 200 ) {
			$new_label = mb_substr( $new_label, 0, 200 );
		}

		$registry = self::get_registry();

		if ( self::OAUTH_CONNECTION_ID === $connection_id ) {
			if ( ! isset( $registry[ $connection_id ] ) ) {
				$registry[ $connection_id ] = array();
			}
			$registry[ $connection_id ]['client_label'] = $new_label;
			self::save_registry( $registry );

			// Mirror to legacy META_PREFIX store.
			$meta                 = get_option( self::META_PREFIX . $connection_id, array() );
			$meta['client_label'] = $new_label;
			update_option( self::META_PREFIX . $connection_id, $meta, false );
			return $new_label;
		}

		if ( ! isset( $registry[ $connection_id ] ) ) {
			return new WP_Error( 'not_found', __( 'Connection not found.', 'axtolab-ai-connector' ) );
		}

		$wp_user_id = (int) $registry[ $connection_id ]['wp_user_id'];

		// Update the WordPress core App Password "name" field too when the
		// owning user still exists, so it stays in sync under that user's
		// profile.
		if ( $wp_user_id && class_exists( 'WP_Application_Passwords' ) && get_user_by( 'id', $wp_user_id ) ) {
			$result = WP_Application_Passwords::update_application_password(
				$wp_user_id,
				$connection_id,
				array( 'name' => $new_label )
			);
			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}

		$registry[ $connection_id ]['client_label'] = $new_label;
		self::save_registry( $registry );

		// Mirror to legacy META_PREFIX store.
		$meta                 = get_option( self::META_PREFIX . $connection_id, array() );
		$meta['client_label'] = $new_label;
		update_option( self::META_PREFIX . $connection_id, $meta, false );

		return $new_label;
	}

	/**
	 * Revoke a single connection.
	 *
	 * @param string $connection_id The app password UUID or 'oauth_token'.
	 * @return true|WP_Error
	 */
	public static function revoke( $connection_id ) {
		if ( ! self::is_valid_connection_id( $connection_id ) ) {
			return new WP_Error( 'invalid_id', __( 'Invalid connection ID.', 'axtolab-ai-connector' ) );
		}

		$registry = self::get_registry();

		if ( self::OAUTH_CONNECTION_ID === $connection_id ) {
			Axtolab_AI_Connector_OAuth::revoke_token();
			unset( $registry[ $connection_id ] );
			self::save_registry( $registry );
			self::cleanup_meta( $connection_id );
			return true;
		}

		if ( isset( $registry[ $connection_id ] ) ) {
			$wp_user_id = (int) $registry[ $connection_id ]['wp_user_id'];

			// Best-effort delete the underlying App Password from WordPress.
			if ( $wp_user_id && class_exists( 'WP_Application_Passwords' ) && get_user_by( 'id', $wp_user_id ) ) {
				WP_Application_Passwords::delete_application_password( $wp_user_id, $connection_id );
			}

			unset( $registry[ $connection_id ] );
			self::save_registry( $registry );
		}

		self::cleanup_meta( $connection_id );

		return true;
	}

	/**
	 * Revoke all connections (emergency kill switch).
	 *
	 * @return int Number of connections revoked.
	 */
	public static function revoke_all() {
		$count    = 0;
		$registry = self::get_registry();

		foreach ( $registry as $id => $row ) {
			if ( self::OAUTH_CONNECTION_ID === $id ) {
				continue;
			}
			$wp_user_id = isset( $row['wp_user_id'] ) ? (int) $row['wp_user_id'] : 0;
			if ( $wp_user_id && class_exists( 'WP_Application_Passwords' ) && get_user_by( 'id', $wp_user_id ) ) {
				WP_Application_Passwords::delete_application_password( $wp_user_id, $id );
			}
			self::cleanup_meta( $id );
			++$count;
		}

		// Revoke OAuth token if active.
		if ( Axtolab_AI_Connector_OAuth::has_active_token() ) {
			Axtolab_AI_Connector_OAuth::revoke_token();
			self::cleanup_meta( self::OAUTH_CONNECTION_ID );
			++$count;
		}

		// Wipe the registry entirely.
		self::save_registry( array() );

		return $count;
	}

	// ── Last-active tracking ─────────────────────────────────────────────────

	/**
	 * Update last_active timestamp for a connection.
	 *
	 * Throttled to once per minute per connection to avoid DB spam.
	 *
	 * @param string $connection_id The app password UUID or 'oauth_token'.
	 * @return void
	 */
	public static function touch( $connection_id ) {
		if ( ! self::is_valid_connection_id( $connection_id ) ) {
			return;
		}

		// Check throttle — skip if touched within the last 60 seconds.
		$throttle_key = self::THROTTLE_PREFIX . $connection_id;

		if ( false !== get_transient( $throttle_key ) ) {
			return;
		}

		// Set throttle transient.
		set_transient( $throttle_key, 1, self::TOUCH_THROTTLE );

		// Update last-active timestamp.
		update_option( self::LAST_ACTIVE_PREFIX . $connection_id, time(), false );
	}

	/**
	 * Handle the application_password_did_authenticate action.
	 *
	 * Called by WordPress after successful Application Password auth.
	 * Extracts the connection UUID and calls touch().
	 *
	 * @param WP_User $user The authenticated user.
	 * @param array   $item The application password record.
	 * @return void
	 */
	public static function on_app_password_auth( $user, $item ) {
		if ( empty( $item['uuid'] ) ) {
			return;
		}

		// Only mark the request as belonging to a known MCP connection if the
		// App Password UUID is in our registry. App Passwords created by users
		// for unrelated tools (REST API clients, mobile apps, etc.) must not
		// be silently treated as MCP traffic.
		$registry = self::get_registry();
		if ( ! isset( $registry[ $item['uuid'] ] ) ) {
			return;
		}

		self::$current_connection_id = $item['uuid'];
		self::touch( $item['uuid'] );
	}

	/**
	 * Return the connection ID of the currently authenticated request.
	 *
	 * Set by on_app_password_auth() during the WordPress auth hook.
	 * Returns null when no Application Password auth has occurred yet.
	 *
	 * @return string|null
	 */
	public static function get_current_connection_id() {
		return self::$current_connection_id;
	}

	/**
	 * Manually set the current connection ID.
	 *
	 * Used as a fallback when the application_password_did_authenticate
	 * hook does not fire (e.g. security plugin interference).
	 *
	 * @param string $id The connection UUID.
	 * @return void
	 */
	public static function set_current_connection_id( $id ) {
		self::$current_connection_id = $id;
	}

	// ── Per-connection capabilities ──────────────────────────────────────────

	/**
	 * Get the capability set for a connection.
	 *
	 * Falls back to Axtolab_AI_Connector_Capabilities::DEFAULT_PRESET when no value
	 * has been saved yet. Always ensures 'read' is present.
	 *
	 * @param string $connection_id The app password UUID or 'oauth_token'.
	 * @return array List of capability group keys.
	 */
	public static function get_capabilities( $connection_id ) {
		$caps = get_option( self::CAPABILITIES_PREFIX . $connection_id, null );
		if ( null === $caps ) {
			return Axtolab_AI_Connector_Capabilities::DEFAULT_PRESET;
		}
		if ( ! in_array( 'read', $caps, true ) ) {
			$caps[] = 'read';
		}
		return $caps;
	}

	/**
	 * Persist the capability set for a connection.
	 *
	 * Silently strips unknown groups and always enforces 'read'.
	 *
	 * @param string   $connection_id The app password UUID or 'oauth_token'.
	 * @param string[] $capabilities  List of capability group keys.
	 * @return true|WP_Error True on success, WP_Error on invalid connection ID.
	 */
	public static function set_capabilities( $connection_id, $capabilities ) {
		if ( ! self::is_valid_connection_id( $connection_id ) ) {
			return new WP_Error( 'invalid_id', 'Invalid connection ID.' );
		}
		$valid_groups = Axtolab_AI_Connector_Capabilities::all_groups();
		$capabilities = array_values( array_intersect( $capabilities, $valid_groups ) );
		if ( ! in_array( 'read', $capabilities, true ) ) {
			$capabilities[] = 'read';
		}
		update_option( self::CAPABILITIES_PREFIX . $connection_id, $capabilities, false );
		self::invalidate_cache();
		return true;
	}

	// ── Per-connection sensitive-action consent ──────────────────────────────

	/**
	 * Get saved consent overrides for a connection.
	 *
	 * The returned map contains only connection-specific overrides. The final
	 * resolved policy is built by Axtolab_AI_Connector_Tool_Consent_Policy.
	 *
	 * @param string $connection_id The app password UUID or 'oauth_token'.
	 * @return array<string,string>
	 */
	public static function get_tool_consent_policy( $connection_id ) {
		$stored = get_option( self::CONSENT_POLICY_PREFIX . $connection_id, array() );
		if ( ! is_array( $stored ) ) {
			return array();
		}

		$clean = array();
		foreach ( $stored as $action => $tier ) {
			$action = sanitize_key( (string) $action );
			if ( '' === $action ) {
				continue;
			}
			$clean[ $action ] = Axtolab_AI_Connector_Tool_Consent_Policy::normalize_tier( (string) $tier );
		}
		return $clean;
	}

	/**
	 * Save one consent action override for a connection.
	 *
	 * @param string $connection_id The app password UUID or 'oauth_token'.
	 * @param string $action        Consent action key.
	 * @param string $tier          Consent tier.
	 * @return true|WP_Error
	 */
	public static function set_tool_consent_tier( $connection_id, $action, $tier ) {
		if ( ! self::is_valid_connection_id( $connection_id ) ) {
			return new WP_Error( 'invalid_id', 'Invalid connection ID.' );
		}

		$action = sanitize_key( (string) $action );
		if ( '' === $action ) {
			return new WP_Error( 'invalid_action', 'Invalid consent action.' );
		}

		$policy            = self::get_tool_consent_policy( $connection_id );
		$policy[ $action ] = Axtolab_AI_Connector_Tool_Consent_Policy::normalize_tier( (string) $tier );
		update_option( self::CONSENT_POLICY_PREFIX . $connection_id, $policy, false );
		self::invalidate_cache();
		return true;
	}

	// ── Per-connection author allowlist ──────────────────────────────────────

	/**
	 * Get the allowed author IDs for a connection.
	 *
	 * Returns null when no restriction is set (any author allowed).
	 * Returns an array of int user IDs when a restriction is configured.
	 *
	 * @param string $connection_id The app password UUID or 'oauth_token'.
	 * @return int[]|null
	 */
	public static function get_allowed_authors( $connection_id ) {
		$stored = get_option( self::ALLOWED_AUTHORS_PREFIX . $connection_id, null );
		if ( null === $stored || ! is_array( $stored ) || empty( $stored ) ) {
			return null; // No restriction.
		}
		return array_map( 'intval', $stored );
	}

	/**
	 * Persist the allowed author IDs for a connection.
	 *
	 * Pass an empty array to remove the restriction (any author allowed).
	 *
	 * @param string $connection_id The app password UUID or 'oauth_token'.
	 * @param int[]  $author_ids    WordPress user IDs to allow. Empty = no restriction.
	 * @return true|WP_Error
	 */
	public static function set_allowed_authors( $connection_id, $author_ids ) {
		if ( ! self::is_valid_connection_id( $connection_id ) ) {
			return new WP_Error( 'invalid_id', 'Invalid connection ID.' );
		}
		$author_ids = array_values( array_map( 'absint', (array) $author_ids ) );
		$author_ids = array_filter( $author_ids ); // Remove zeros.
		$author_ids = array_values( $author_ids );

		if ( empty( $author_ids ) ) {
			delete_option( self::ALLOWED_AUTHORS_PREFIX . $connection_id );
		} else {
			update_option( self::ALLOWED_AUTHORS_PREFIX . $connection_id, $author_ids, false );
		}
		self::invalidate_cache();
		return true;
	}

	// ── Connection metadata ──────────────────────────────────────────────────

	/**
	 * Register / update metadata for an existing connection.
	 *
	 * Kept for back-compat with the OAuth flow which calls this to attach a
	 * client name to the already-registered OAuth connection. Most callers
	 * should use {@see self::register_connection()} instead, which records
	 * the owning wp_user_id at the same time.
	 *
	 * @param string $connection_id The app password UUID or token ID.
	 * @param array  $meta          Metadata: { client_type: string, client_label: string }.
	 * @return void
	 */
	public static function register_meta( $connection_id, $meta ) {
		if ( ! self::is_valid_connection_id( $connection_id ) ) {
			return;
		}

		$stored = array(
			'client_type'   => isset( $meta['client_type'] ) ? sanitize_text_field( $meta['client_type'] ) : 'unknown',
			'client_label'  => isset( $meta['client_label'] ) ? sanitize_text_field( $meta['client_label'] ) : '',
			'registered_at' => time(),
		);

		update_option( self::META_PREFIX . $connection_id, $stored, false );

		// Mirror into the registry. The wp_user_id is left untouched if a row
		// already exists (it must be set explicitly via register_connection()).
		$registry = self::get_registry();
		if ( ! isset( $registry[ $connection_id ] ) ) {
			$registry[ $connection_id ] = array(
				'wp_user_id' => 0,
			);
		}
		$registry[ $connection_id ]['client_type']   = $stored['client_type'];
		$registry[ $connection_id ]['client_label']  = $stored['client_label'];
		$registry[ $connection_id ]['registered_at'] = $stored['registered_at'];
		if ( empty( $registry[ $connection_id ]['auth_method'] ) ) {
			$registry[ $connection_id ]['auth_method'] = self::OAUTH_CONNECTION_ID === $connection_id ? 'oauth' : 'app_password';
		}
		self::save_registry( $registry );
	}

	// ── Display helpers ──────────────────────────────────────────────────────

	/**
	 * Format a Unix timestamp as a human-friendly relative time string.
	 *
	 * @param int $timestamp Unix timestamp.
	 * @return string
	 */
	public static function relative_time( $timestamp ) {
		if ( ! $timestamp ) {
			return __( 'Never', 'axtolab-ai-connector' );
		}

		$diff = time() - (int) $timestamp;

		if ( $diff < 60 ) {
			return __( 'Just now', 'axtolab-ai-connector' );
		}

		if ( $diff < 3600 ) {
			$minutes = (int) floor( $diff / 60 );
			/* translators: %d: number of minutes */
			return sprintf( _n( '%d min ago', '%d min ago', $minutes, 'axtolab-ai-connector' ), $minutes );
		}

		if ( $diff < 86400 ) {
			$hours = (int) floor( $diff / 3600 );
			/* translators: %d: number of hours */
			return sprintf( _n( '%d hour ago', '%d hours ago', $hours, 'axtolab-ai-connector' ), $hours );
		}

		if ( $diff < 604800 ) {
			$days = (int) floor( $diff / 86400 );
			/* translators: %d: number of days */
			return sprintf( _n( '%d day ago', '%d days ago', $days, 'axtolab-ai-connector' ), $days );
		}

		// Over 7 days — show the date using the site's timezone.
		return wp_date( 'M j, Y', (int) $timestamp );
	}

	/**
	 * Get a human-readable label for an auth method.
	 *
	 * @param string $auth_method The auth method key.
	 * @return string
	 */
	public static function auth_method_label( $auth_method ) {
		$labels = array(
			'app_password' => __( 'App Password', 'axtolab-ai-connector' ),
			'oauth'        => __( 'OAuth', 'axtolab-ai-connector' ),
		);

		return isset( $labels[ $auth_method ] ) ? $labels[ $auth_method ] : $auth_method;
	}

	/**
	 * Get a human-readable label for a client type.
	 *
	 * @param string $client_type The client type key.
	 * @return string
	 */
	public static function client_type_label( $client_type ) {
		$labels = array(
			'claude_desktop' => __( 'Claude Desktop', 'axtolab-ai-connector' ),
			'cowork'         => __( 'Cowork', 'axtolab-ai-connector' ),
			'chatgpt'        => __( 'ChatGPT', 'axtolab-ai-connector' ),
			'claude_web'     => __( 'Claude Web', 'axtolab-ai-connector' ),
			'cli'            => __( 'CLI', 'axtolab-ai-connector' ),
			'other'          => __( 'Other', 'axtolab-ai-connector' ),
			'unknown'        => __( 'Unknown', 'axtolab-ai-connector' ),
		);

		return isset( $labels[ $client_type ] ) ? $labels[ $client_type ] : $client_type;
	}

	// ── Internal helpers ─────────────────────────────────────────────────────

	/**
	 * Validate that a connection ID is a known format.
	 *
	 * Accepts 'oauth_token' or a valid UUID (v4). This prevents arbitrary
	 * strings from being used as WordPress option-name suffixes.
	 *
	 * @param string $connection_id The connection identifier to validate.
	 * @return bool True if the format is valid.
	 */
	public static function is_valid_connection_id( $connection_id ) {
		if ( self::OAUTH_CONNECTION_ID === $connection_id ) {
			return true;
		}

		// WordPress UUIDs are v4 format.
		if ( function_exists( 'wp_is_uuid' ) ) {
			return wp_is_uuid( $connection_id );
		}

		// Fallback regex for older WP installs.
		return (bool) preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $connection_id );
	}

	/**
	 * Remove stored metadata and last-active data for a connection.
	 *
	 * @param string $connection_id The connection identifier.
	 * @return void
	 */
	private static function cleanup_meta( $connection_id ) {
		delete_option( self::META_PREFIX . $connection_id );
		delete_option( self::LAST_ACTIVE_PREFIX . $connection_id );
		delete_option( self::CAPABILITIES_PREFIX . $connection_id );
		delete_option( self::ALLOWED_AUTHORS_PREFIX . $connection_id );
		delete_option( self::CONSENT_POLICY_PREFIX . $connection_id );
		delete_transient( self::THROTTLE_PREFIX . $connection_id );
	}

	/**
	 * Cleanup connections inactive for longer than the configured expiry period.
	 * Runs via wp_cron daily.
	 *
	 * @return int Number of connections expired.
	 */
	public static function cleanup_expired_connections() {
		$settings    = get_option( 'axtolab_ai_connector_settings', array() );
		$expiry_days = isset( $settings['token_expiry_days'] ) ? (int) $settings['token_expiry_days'] : 90;

		if ( $expiry_days <= 0 ) {
			return 0; // Auto-expiry disabled.
		}

		$connections = self::get_all_connections();
		$threshold   = time() - ( $expiry_days * DAY_IN_SECONDS );
		$count       = 0;

		foreach ( $connections as $conn ) {
			// Skip never-used connections (may be newly created).
			if ( 0 === $conn['last_active'] ) {
				continue;
			}
			// Skip recently active connections.
			if ( $conn['last_active'] >= $threshold ) {
				continue;
			}
			$result = self::revoke( $conn['id'] );
			if ( true === $result ) {
				++$count;
			}
		}

		if ( $count > 0 ) {
			do_action(
				'axtolab_ai_connector_debug_log',
				sprintf(
					'[WP MCP Gateway] Auto-expired %d connection(s) inactive for >%d days.',
					$count,
					$expiry_days
				)
			);
		}

		return $count;
	}
}

if ( ! class_exists( 'MCP_Gateway_Connections', false ) ) {
	class_alias( 'Axtolab_AI_Connector_Connections', 'MCP_Gateway_Connections' );
}
