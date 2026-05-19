<?php
/**
 * MCP Gateway Connections
 *
 * Core class for connection management. Provides a unified view of all
 * MCP client connections (Application Passwords + OAuth tokens) with
 * label management, last-active tracking, and per-connection revoke.
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
class Axtolab_AI_Connector_Connections {

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
	 * Tracks the connection ID of the currently authenticated request.
	 *
	 * @var string|null
	 */
	private static $current_connection_id = null;

	// ── Read operations ──────────────────────────────────────────────────────

	/**
	 * Get all active connections across all auth methods.
	 *
	 * Returns a unified array sorted by last_active (most recent first).
	 *
	 * @return array[] Each entry: {
	 *     id: string,          // unique identifier (app password UUID or 'oauth_token')
	 *     label: string,       // user-editable display name
	 *     client_type: string, // claude_desktop, cowork, chatgpt, claude_web, cli, unknown
	 *     auth_method: string, // app_password, oauth
	 *     created: int,        // Unix timestamp
	 *     last_active: int,    // Unix timestamp (0 if never)
	 *     last_ip: string,     // IP address or empty
	 * }
	 */
	public static function get_all_connections() {
		if ( null !== self::$connections_cache ) {
			return self::$connections_cache;
		}

		$connections     = array();
		$service_user_id = (int) get_option( 'axtolab_ai_connector_service_user_id', 0 );

		// 1. Application Passwords for the service account.
		if ( $service_user_id && class_exists( 'WP_Application_Passwords' ) ) {
			$passwords = WP_Application_Passwords::get_user_application_passwords( $service_user_id );

			if ( is_array( $passwords ) ) {
				foreach ( $passwords as $pwd ) {
					$uuid        = $pwd['uuid'];
					$meta        = get_option( self::META_PREFIX . $uuid, array() );
					$last_active = (int) get_option( self::LAST_ACTIVE_PREFIX . $uuid, 0 );

					// Fall back to WordPress core's last_used (date only, as Unix timestamp).
					if ( ! $last_active && ! empty( $pwd['last_used'] ) ) {
						$last_active = (int) $pwd['last_used'];
					}

					$connections[] = array(
						'id'              => $uuid,
						'label'           => ! empty( $meta['client_label'] ) ? $meta['client_label'] : $pwd['name'],
						'client_type'     => ! empty( $meta['client_type'] ) ? $meta['client_type'] : 'unknown',
						'auth_method'     => 'app_password',
						'created'         => isset( $pwd['created'] ) ? (int) $pwd['created'] : 0,
						'last_active'     => $last_active,
						'last_ip'         => ! empty( $pwd['last_ip'] ) ? $pwd['last_ip'] : '',
						'capabilities'    => self::get_capabilities( $uuid ),
						'allowed_authors' => self::get_allowed_authors( $uuid ),
					);
				}
			}
		}

		// 2. OAuth token (at most one active at a time).
		$settings = get_option( 'axtolab_ai_connector_settings', array() );

		if ( ! empty( $settings['oauth_access_token_hash'] ) ) {
			$expires = isset( $settings['oauth_access_token_expires'] ) ? (int) $settings['oauth_access_token_expires'] : 0;
			$active  = time() < $expires;

			if ( $active ) {
				$oauth_id    = self::OAUTH_CONNECTION_ID;
				$meta        = get_option( self::META_PREFIX . $oauth_id, array() );
				$last_active = (int) get_option( self::LAST_ACTIVE_PREFIX . $oauth_id, 0 );
				$created_str = isset( $settings['oauth_access_token_created'] ) ? $settings['oauth_access_token_created'] : '';
				$created_ts  = $created_str ? strtotime( $created_str ) : 0;
				$client_name = isset( $settings['oauth_client_name'] ) ? $settings['oauth_client_name'] : 'MCP Client';

				// Auto-detect client type from client_name.
				$client_type = 'unknown';
				if ( false !== stripos( $client_name, 'chatgpt' ) ) {
					$client_type = 'chatgpt';
				} elseif ( false !== stripos( $client_name, 'claude' ) ) {
					$client_type = 'claude_web';
				}

				$connections[] = array(
					'id'              => $oauth_id,
					'label'           => ! empty( $meta['client_label'] ) ? $meta['client_label'] : $client_name,
					'client_type'     => ! empty( $meta['client_type'] ) ? $meta['client_type'] : $client_type,
					'auth_method'     => 'oauth',
					'created'         => $created_ts ? (int) $created_ts : 0,
					'last_active'     => $last_active,
					'last_ip'         => '',
					'capabilities'    => self::get_capabilities( self::OAUTH_CONNECTION_ID ),
					'allowed_authors' => self::get_allowed_authors( self::OAUTH_CONNECTION_ID ),
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

		if ( self::OAUTH_CONNECTION_ID === $connection_id ) {
			// Update stored meta for OAuth connection.
			$meta                 = get_option( self::META_PREFIX . $connection_id, array() );
			$meta['client_label'] = $new_label;
			update_option( self::META_PREFIX . $connection_id, $meta, false );
			self::invalidate_cache();
			return $new_label;
		}

		// App password — update the WP core name field.
		$service_user_id = (int) get_option( 'axtolab_ai_connector_service_user_id', 0 );

		if ( ! $service_user_id || ! class_exists( 'WP_Application_Passwords' ) ) {
			return new WP_Error( 'not_found', __( 'Connection not found.', 'axtolab-ai-connector' ) );
		}

		$result = WP_Application_Passwords::update_application_password(
			$service_user_id,
			$connection_id,
			array( 'name' => $new_label )
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// Also update our metadata store so the label is preserved.
		$meta                 = get_option( self::META_PREFIX . $connection_id, array() );
		$meta['client_label'] = $new_label;
		update_option( self::META_PREFIX . $connection_id, $meta, false );

		self::invalidate_cache();

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

		if ( self::OAUTH_CONNECTION_ID === $connection_id ) {
			Axtolab_AI_Connector_OAuth::revoke_token();
			self::cleanup_meta( $connection_id );
			self::invalidate_cache();
			return true;
		}

		// App password — delete via WP core.
		$service_user_id = (int) get_option( 'axtolab_ai_connector_service_user_id', 0 );

		if ( ! $service_user_id || ! class_exists( 'WP_Application_Passwords' ) ) {
			return new WP_Error( 'not_found', __( 'Connection not found.', 'axtolab-ai-connector' ) );
		}

		$result = WP_Application_Passwords::delete_application_password(
			$service_user_id,
			$connection_id
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		self::cleanup_meta( $connection_id );
		self::invalidate_cache();

		return true;
	}

	/**
	 * Revoke all connections (emergency kill switch).
	 *
	 * @return int Number of connections revoked.
	 */
	public static function revoke_all() {
		$count           = 0;
		$service_user_id = (int) get_option( 'axtolab_ai_connector_service_user_id', 0 );

		// Delete all app passwords.
		if ( $service_user_id && class_exists( 'WP_Application_Passwords' ) ) {
			$passwords = WP_Application_Passwords::get_user_application_passwords( $service_user_id );

			if ( is_array( $passwords ) ) {
				foreach ( $passwords as $pwd ) {
					self::cleanup_meta( $pwd['uuid'] );
					++$count;
				}
				WP_Application_Passwords::delete_all_application_passwords( $service_user_id );
			}
		}

		// Revoke OAuth token if active.
		if ( Axtolab_AI_Connector_OAuth::has_active_token() ) {
			Axtolab_AI_Connector_OAuth::revoke_token();
			self::cleanup_meta( self::OAUTH_CONNECTION_ID );
			++$count;
		}

		self::invalidate_cache();

		return $count;
	}

	// ── Last-active tracking ─────────────────────────────────────────────────

	/**
	 * Update last_active timestamp for a connection.
	 *
	 * Throttled to once per minute per connection to avoid DB spam.
	 *
	 * @param string $connection_id The app password UUID or 'oauth_token'.
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
	 */
	public static function on_app_password_auth( $user, $item ) {
		if ( empty( $item['uuid'] ) ) {
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
	 * Register metadata for a new connection.
	 *
	 * Called during connection-token consumption or OAuth token issuance.
	 *
	 * @param string $connection_id The app password UUID or token ID.
	 * @param array  $meta          { client_type: string, client_label: string }
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
		self::invalidate_cache();
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
	 */
	private static function cleanup_meta( $connection_id ) {
		delete_option( self::META_PREFIX . $connection_id );
		delete_option( self::LAST_ACTIVE_PREFIX . $connection_id );
		delete_option( self::CAPABILITIES_PREFIX . $connection_id );
		delete_option( self::ALLOWED_AUTHORS_PREFIX . $connection_id );
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
