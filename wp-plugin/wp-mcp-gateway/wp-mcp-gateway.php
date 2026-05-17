<?php
/**
 * Plugin Name:       Axtolab AI Connector
 * Description:       Let AI agents safely read, draft, edit, and publish WordPress content. Connects Claude, ChatGPT, and AI agents via MCP. Device-authorization flow included — no manual credential copying required.
 * Version:           1.0.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Axtolab
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       axtolab-ai-connector
 *
 * @package WP_MCP_Gateway
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// ── Constants ─────────────────────────────────────────────────────────────────

define( 'AXTOLAB_AI_CONNECTOR_VERSION', '1.0.0' );
define( 'AXTOLAB_AI_CONNECTOR_FILE', __FILE__ );
define( 'AXTOLAB_AI_CONNECTOR_DIR', plugin_dir_path( __FILE__ ) );
define( 'AXTOLAB_AI_CONNECTOR_URL', plugin_dir_url( __FILE__ ) );

// Public contract for the Axtolab plugin suite: every Axtolab add-on plugin
// registers its admin pages as submenus under this top-level slug. Defined here
// (in the AI Connector core) so add-on plugins can detect and gracefully fall
// back when this plugin isn't active.
if ( ! defined( 'AXTOLAB_ADMIN_PARENT_SLUG' ) ) {
	define( 'AXTOLAB_ADMIN_PARENT_SLUG', 'axtolab' );
}

// ── Includes ──────────────────────────────────────────────────────────────────

require_once AXTOLAB_AI_CONNECTOR_DIR . 'includes/class-mcp-gateway-response.php';
require_once AXTOLAB_AI_CONNECTOR_DIR . 'includes/class-mcp-gateway-config.php';
require_once AXTOLAB_AI_CONNECTOR_DIR . 'includes/class-mcp-gateway-policy.php';
require_once AXTOLAB_AI_CONNECTOR_DIR . 'includes/class-mcp-gateway-seo-adapter.php';
require_once AXTOLAB_AI_CONNECTOR_DIR . 'includes/class-mcp-gateway-free-gates.php';
require_once AXTOLAB_AI_CONNECTOR_DIR . 'includes/class-mcp-gateway-preview.php';
require_once AXTOLAB_AI_CONNECTOR_DIR . 'includes/class-mcp-gateway-inline-images.php';
require_once AXTOLAB_AI_CONNECTOR_DIR . 'includes/class-mcp-gateway-rest.php';
require_once AXTOLAB_AI_CONNECTOR_DIR . 'includes/class-mcp-gateway-device-auth.php';
require_once AXTOLAB_AI_CONNECTOR_DIR . 'includes/class-mcp-gateway-token-auth.php';
require_once AXTOLAB_AI_CONNECTOR_DIR . 'includes/class-mcp-gateway-bearer-auth.php';
require_once AXTOLAB_AI_CONNECTOR_DIR . 'includes/class-mcp-gateway-confirmation.php';
require_once AXTOLAB_AI_CONNECTOR_DIR . 'includes/class-mcp-gateway-capabilities.php';
require_once AXTOLAB_AI_CONNECTOR_DIR . 'includes/class-mcp-gateway-service-account-guard.php';
require_once AXTOLAB_AI_CONNECTOR_DIR . 'includes/class-mcp-gateway-mcp-transport.php';
require_once AXTOLAB_AI_CONNECTOR_DIR . 'includes/class-mcp-gateway-oauth.php';
require_once AXTOLAB_AI_CONNECTOR_DIR . 'includes/class-mcp-gateway-image-providers.php';
require_once AXTOLAB_AI_CONNECTOR_DIR . 'includes/class-mcp-gateway-upload-portal.php';
require_once AXTOLAB_AI_CONNECTOR_DIR . 'includes/class-mcp-gateway-connections.php';
require_once AXTOLAB_AI_CONNECTOR_DIR . 'includes/class-mcp-gateway-audit-log.php';
require_once AXTOLAB_AI_CONNECTOR_DIR . 'includes/class-mcp-gateway-changelog.php';
require_once AXTOLAB_AI_CONNECTOR_DIR . 'includes/class-mcp-gateway-snapshots.php';
require_once AXTOLAB_AI_CONNECTOR_DIR . 'includes/class-mcp-gateway-rate-limiter.php';
require_once AXTOLAB_AI_CONNECTOR_DIR . 'includes/class-axtolab-support-links.php';
require_once AXTOLAB_AI_CONNECTOR_DIR . 'includes/class-mcp-gateway-admin.php';

// ── Authorization header passthrough (CGI/FastCGI fix) ───────────────────────
//
// On CGI/FastCGI servers (LiteSpeed, some Apache configs), PHP never sees the
// Authorization header in $_SERVER. This block reads it via getallheaders()
// (available in PHP 7.3+ for all SAPIs) and populates $_SERVER so that
// WordPress Application Passwords, Bearer auth, and OAuth can work.
//
// This runs at file-load time (before any hooks) to ensure it's available
// when WordPress calls wp_validate_application_password() during rest_api_init.

if ( ! isset( $_SERVER['HTTP_AUTHORIZATION'] ) ) {
	// Check REDIRECT_HTTP_AUTHORIZATION (set by .htaccess RewriteRule).
	if ( isset( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ) ) {
		$_SERVER['HTTP_AUTHORIZATION'] = sanitize_text_field( wp_unslash( (string) $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ) );
	} elseif ( function_exists( 'getallheaders' ) ) {
		// Fallback: read directly from the SAPI (works on LiteSpeed LSAPI, FPM, etc.).
		$axtolab_ai_connector_headers = getallheaders();
		// getallheaders() may return case-insensitive keys depending on SAPI.
		foreach ( $axtolab_ai_connector_headers as $axtolab_ai_connector_name => $axtolab_ai_connector_value ) {
			if ( strcasecmp( $axtolab_ai_connector_name, 'Authorization' ) === 0 ) {
				$_SERVER['HTTP_AUTHORIZATION'] = sanitize_text_field( wp_unslash( (string) $axtolab_ai_connector_value ) );
				break;
			}
		}
	}
}

// ── .htaccess helpers for OAuth .well-known discovery ─────────────────────────

/**
 * Write .htaccess rules to route .well-known/oauth-* requests through WordPress.
 *
 * Uses insert_with_markers() to add rules for Apache/LiteSpeed servers.
 * Safe to call multiple times — idempotent.
 *
 * @return void
 */
function axtolab_ai_connector_write_oauth_htaccess_rules(): void {
	if ( ! function_exists( 'get_home_path' ) ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
	}
	if ( ! function_exists( 'insert_with_markers' ) ) {
		require_once ABSPATH . 'wp-admin/includes/misc.php';
	}

	$htaccess_file = get_home_path() . '.htaccess';
	if ( wp_is_writable( $htaccess_file ) ) {
		$rules = array(
			'RewriteEngine On',
			'# Pass Authorization header to PHP (required for CGI/FastCGI on LiteSpeed/Apache)',
			'RewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]',
			'RewriteRule ^\.well-known/oauth-protected-resource$ /index.php [L]',
			'RewriteRule ^\.well-known/oauth-authorization-server$ /index.php [L]',
		);
		insert_with_markers( $htaccess_file, 'Axtolab AI Connector OAuth', $rules );
	}
}

/**
 * Ensure the OAuth .htaccess marker block is present, writing it if not.
 *
 * Defensive self-heal: the activation hook only fires on a fresh activate, so
 * plugin updates that don't trigger reactivation (e.g. `wp plugin install
 * --force` against an already-active install, or a one-click update that
 * preserves activation state on some hosts) can leave the marker block
 * missing. Security/optimization plugins or manual edits can also strip it.
 *
 * Also performs one-time legacy cleanup: pre-rename installs (before May
 * 2026) used a 'WP MCP Gateway OAuth' marker. We strip that block so users
 * upgrading don't end up with duplicate rewrite rules.
 *
 * Idempotent — safe to call repeatedly. No-op when the file is unreadable
 * (i.e. on hosts that don't use .htaccess).
 *
 * @return void
 */
function axtolab_ai_connector_ensure_oauth_htaccess_rules(): void {
	if ( ! function_exists( 'get_home_path' ) ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
	}
	if ( ! function_exists( 'insert_with_markers' ) ) {
		require_once ABSPATH . 'wp-admin/includes/misc.php';
	}

	$htaccess_file = get_home_path() . '.htaccess';
	if ( ! is_readable( $htaccess_file ) ) {
		return;
	}
	$contents = @file_get_contents( $htaccess_file );
	if ( false === $contents ) {
		return;
	}

	$has_legacy_marker  = false !== strpos( $contents, '# BEGIN WP MCP Gateway OAuth' );
	$has_current_marker = false !== strpos( $contents, '# BEGIN Axtolab AI Connector OAuth' );

	if ( ! $has_legacy_marker && $has_current_marker ) {
		return; // Nothing to do.
	}

	if ( $has_legacy_marker && wp_is_writable( $htaccess_file ) ) {
		// Remove the pre-rename block so we don't double-up after the new write.
		insert_with_markers( $htaccess_file, 'WP MCP Gateway OAuth', array() );
	}

	if ( ! $has_current_marker ) {
		axtolab_ai_connector_write_oauth_htaccess_rules();
	}
}

/**
 * Remove .htaccess rules added by axtolab_ai_connector_write_oauth_htaccess_rules().
 *
 * @return void
 */
function axtolab_ai_connector_remove_oauth_htaccess_rules(): void {
	if ( ! function_exists( 'get_home_path' ) ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
	}
	if ( ! function_exists( 'insert_with_markers' ) ) {
		require_once ABSPATH . 'wp-admin/includes/misc.php';
	}

	$htaccess_file = get_home_path() . '.htaccess';
	if ( wp_is_writable( $htaccess_file ) ) {
		insert_with_markers( $htaccess_file, 'Axtolab AI Connector OAuth', array() );
	}
}

// ── Activation hook ───────────────────────────────────────────────────────────

register_activation_hook( __FILE__, 'axtolab_ai_connector_activate' );

/**
 * Plugin activation callback.
 *
 * Creates the `axtolab_ai_connector_editor` role and the `axtolab-connector-service` user.
 * Writes .htaccess rules for OAuth .well-known discovery (Apache/LiteSpeed).
 * Both operations are idempotent — safe to run multiple times (e.g. reactivation).
 *
 * @return void
 */
function axtolab_ai_connector_activate(): void {
	$multisite_allowed = Axtolab_AI_Connector_Free_Gates::check_multisite_allowed();
	if ( is_wp_error( $multisite_allowed ) ) {
		set_transient(
			'axtolab_ai_connector_activation_error',
			$multisite_allowed->get_error_message(),
			60
		);
	}

	axtolab_ai_connector_provision_role();
	axtolab_ai_connector_grant_admin_caps();

	Axtolab_AI_Connector_Audit_Log::create_table();
	Axtolab_AI_Connector_Changelog::create_table();

	$result = axtolab_ai_connector_provision_service_account();

	if ( is_wp_error( $result ) ) {
		// Surface the error to the admin via a transient (shown on next page load).
		set_transient(
			'axtolab_ai_connector_activation_error',
			$result->get_error_message(),
			60
		);
	}

	// Write .htaccess rules for OAuth .well-known discovery endpoints.
	axtolab_ai_connector_write_oauth_htaccess_rules();

	// Schedule cleanup cron for pending generated images.
	if ( ! wp_next_scheduled( Axtolab_AI_Connector_Image_Providers::CLEANUP_HOOK ) ) {
		wp_schedule_event( time(), 'daily', Axtolab_AI_Connector_Image_Providers::CLEANUP_HOOK );
	}

	// Schedule daily cleanup of expired connections.
	if ( ! wp_next_scheduled( 'axtolab_ai_connector_cleanup_expired' ) ) {
		wp_schedule_event( time(), 'daily', 'axtolab_ai_connector_cleanup_expired' );
	}

	// Schedule daily prune of the changelog (Phase 5 retention).
	if ( ! wp_next_scheduled( 'axtolab_ai_connector_prune_changelog' ) ) {
		wp_schedule_event( time(), 'daily', 'axtolab_ai_connector_prune_changelog' );
	}

	// Flush rewrite rules so REST routes are available immediately.
	flush_rewrite_rules();
}

// ── Deactivation hook ────────────────────────────────────────────────────────

register_deactivation_hook( __FILE__, 'axtolab_ai_connector_deactivate' );

/**
 * Plugin deactivation callback.
 *
 * Removes .htaccess rules for OAuth .well-known discovery.
 *
 * @return void
 */
function axtolab_ai_connector_deactivate(): void {
	axtolab_ai_connector_remove_oauth_htaccess_rules();
	wp_clear_scheduled_hook( Axtolab_AI_Connector_Image_Providers::CLEANUP_HOOK );
	wp_clear_scheduled_hook( 'axtolab_ai_connector_cleanup_expired' );
	wp_clear_scheduled_hook( 'axtolab_ai_connector_prune_changelog' );
	flush_rewrite_rules();
}

// Wire the prune cron handler. Runs daily once activation has scheduled it.
add_action( 'axtolab_ai_connector_prune_changelog', array( 'Axtolab_AI_Connector_Changelog', 'prune' ) );

// ── Uninstall hook ────────────────────────────────────────────────────────────

register_uninstall_hook( __FILE__, 'axtolab_ai_connector_uninstall' );

/**
 * Plugin uninstall callback.
 *
 * Removes everything the plugin created:
 *   - The `axtolab-connector-service` user (posts reassigned to user ID 1).
 *   - The `axtolab_ai_connector_editor` role.
 *   - All plugin options.
 *   - All `axtolab_ai_connector_*` transients (device codes, user codes, rate limits).
 *
 * @return void
 */
function axtolab_ai_connector_uninstall(): void {
	global $wpdb;

	// ── Delete service account user ───────────────────────────────────────────
	$service_user_id = (int) get_option( 'axtolab_ai_connector_service_user_id', 0 );

	if ( $service_user_id && get_user_by( 'id', $service_user_id ) ) {
		// Require the user-management functions.
		if ( ! function_exists( 'wp_delete_user' ) ) {
			require_once ABSPATH . 'wp-admin/includes/user.php';
		}
		// Reassign any content to the default admin (user ID 1).
		wp_delete_user( $service_user_id, 1 );
	}

	// ── Remove role ───────────────────────────────────────────────────────────
	remove_role( 'axtolab_ai_connector_editor' );

	// ── Strip plugin caps from administrator role ─────────────────────────────
	$admin = get_role( 'administrator' );
	if ( $admin instanceof WP_Role ) {
		$admin->remove_cap( 'axtolab_ai_connector_view_changelog' );
		$admin->remove_cap( 'axtolab_ai_connector_view_audit' );
	}

	// ── Delete plugin options ─────────────────────────────────────────────────
	delete_option( 'axtolab_ai_connector_service_user_id' );
	delete_option( 'axtolab_ai_connector_htaccess_version' );

	// Clean up legacy ChatGPT No-Auth URL settings from the main settings array.
	$settings = get_option( 'axtolab_ai_connector_settings', array() );
	unset( $settings['chatgpt_path_token_hash'] );
	unset( $settings['chatgpt_path_token_created'] );
	unset( $settings['chatgpt_path_token_prefix'] );
	unset( $settings['chatgpt_noauth_enabled'] );
	unset( $settings['chatgpt_capabilities'] );
	if ( ! empty( $settings ) ) {
		update_option( 'axtolab_ai_connector_settings', $settings );
	}

	// ── Delete all plugin transients ──────────────────────────────────────────
	// WordPress stores transients in the options table as _transient_{key},
	// so a direct SQL LIKE query is the only reliable way to bulk-delete them
	// without knowing each individual key.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s",
			'_transient_axtolab_ai_connector_%',
			'_transient_timeout_axtolab_ai_connector_%',
			'_transient_mcp_gw_%',
			'_transient_timeout_mcp_gw_%',
			'_transient_mcp_gw_oauth_%',
			'_transient_mcp_gw_dcr_%',
			// Upload portal transients.
			'_transient__mcp_upload_session_%',
			'_transient_timeout__mcp_upload_session_%',
			'_transient__mcp_upload_sid_%',
			'_transient_timeout__mcp_upload_sid_%'
		)
	);

	// Upload portal rate-limit transients (_transient_mcp_upload_rate_*).
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
			'_transient_mcp_upload_rate_%',
			'_transient_timeout_mcp_upload_rate_%'
		)
	);

	// Connection manager metadata and last-active options.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s",
			'_mcp_connection_meta_%',
			'_mcp_last_active_%',
			'_mcp_connection_caps_%',
			'_transient__mcp_touch_throttle_%',
			'_transient_timeout__mcp_touch_throttle_%'
		)
	);

	// Remove .htaccess rules for OAuth .well-known discovery.
	axtolab_ai_connector_remove_oauth_htaccess_rules();

	flush_rewrite_rules();
}

// ── Shared provisioning helpers ───────────────────────────────────────────────

/**
 * Create (or verify) the `axtolab_ai_connector_editor` role.
 *
 * Idempotent: if the role already exists its capabilities are refreshed.
 *
 * @return WP_Role The role object.
 */
function axtolab_ai_connector_provision_role(): WP_Role {
	$capabilities = array(
		// Core access.
		'read'                                       => true,
		// Posts.
		'edit_posts'                                 => true,
		'edit_published_posts'                       => true,
		'edit_others_posts'                          => true,
		'publish_posts'                              => true,
		// Pages.
		'edit_pages'                                 => true,
		'edit_published_pages'                       => true,
		'edit_others_pages'                          => true,
		'publish_pages'                              => true,
		// Media.
		'upload_files'                               => true,
		// Taxonomy.
		'manage_categories'                          => true,
		// User directory (granted so /users/{id} lookups work for the
		// service account; required by `permission_list_users`).
		'list_users'                                 => true,
		// Custom plugin caps — gate /changelog and /audit-log routes
		// without requiring the service account to be administrator.
		'axtolab_ai_connector_view_changelog'        => true,
		'axtolab_ai_connector_view_audit'            => true,
	);

	$existing = get_role( 'axtolab_ai_connector_editor' );

	if ( $existing instanceof WP_Role ) {
		// Role exists — refresh capabilities in case they changed between versions.
		foreach ( $capabilities as $cap => $grant ) {
			$existing->add_cap( $cap, $grant );
		}
		return $existing;
	}

	$role = add_role( 'axtolab_ai_connector_editor', __( 'Axtolab AI Connector Editor', 'axtolab-ai-connector' ), $capabilities );

	// add_role() returns null if the role already exists (race condition guard).
	return $role instanceof WP_Role ? $role : get_role( 'axtolab_ai_connector_editor' );
}

/**
 * Grant the plugin's custom view-changelog / view-audit capabilities to the
 * administrator role so site admins can view audit/changelog data in wp-admin.
 *
 * Idempotent — safe to call on every activation.
 *
 * @return void
 */
function axtolab_ai_connector_grant_admin_caps(): void {
	$admin = get_role( 'administrator' );
	if ( ! $admin instanceof WP_Role ) {
		return;
	}
	$admin->add_cap( 'axtolab_ai_connector_view_changelog' );
	$admin->add_cap( 'axtolab_ai_connector_view_audit' );
}

/**
 * Create (or verify) the `axtolab-connector-service` WordPress user.
 *
 * Idempotent: if the user already exists (by login or by stored option), the
 * function ensures it has the correct role and returns without creating a
 * duplicate.
 *
 * @return int|WP_Error User ID on success, WP_Error on failure.
 */
function axtolab_ai_connector_provision_service_account() {
	// Ensure the role exists before assigning it.
	axtolab_ai_connector_provision_role();

	$service_login = 'axtolab-connector-service';

	// Check stored option first.
	$stored_id = (int) get_option( 'axtolab_ai_connector_service_user_id', 0 );

	if ( $stored_id ) {
		$user = get_user_by( 'id', $stored_id );
		if ( $user instanceof WP_User ) {
			// User exists — make sure the role is correct.
			if ( ! in_array( 'axtolab_ai_connector_editor', (array) $user->roles, true ) ) {
				$user->set_role( 'axtolab_ai_connector_editor' );
			}
			return $stored_id;
		}
	}

	// Check by login name (user may exist without the option).
	$existing = get_user_by( 'login', $service_login );

	if ( $existing instanceof WP_User ) {
		update_option( 'axtolab_ai_connector_service_user_id', $existing->ID );

		if ( ! in_array( 'axtolab_ai_connector_editor', (array) $existing->roles, true ) ) {
			$existing->set_role( 'axtolab_ai_connector_editor' );
		}
		return $existing->ID;
	}

	// Create the user.
	$user_id = wp_create_user(
		$service_login,
		wp_generate_password( 32, true, true ),
		sanitize_email( $service_login . '@' . wp_parse_url( home_url(), PHP_URL_HOST ) )
	);

	if ( is_wp_error( $user_id ) ) {
		return $user_id;
	}

	$user = new WP_User( $user_id );
	$user->set_role( 'axtolab_ai_connector_editor' );

	// Prevent the service account from logging in via the normal login form —
	// it authenticates exclusively through Application Passwords.
	update_user_meta( $user_id, 'axtolab_ai_connector_service_account', true );

	update_option( 'axtolab_ai_connector_service_user_id', $user_id );

	return $user_id;
}

// ── Bootstrap ─────────────────────────────────────────────────────────────────

/**
 * Main plugin bootstrap.
 *
 * Instantiates and wires up all plugin components. Called on `plugins_loaded`
 * so that all WordPress functions are available.
 *
 * @return void
 */
function axtolab_ai_connector_bootstrap(): void {
	// Hook image cleanup cron.
	add_action( Axtolab_AI_Connector_Image_Providers::CLEANUP_HOOK, array( 'Axtolab_AI_Connector_Image_Providers', 'cleanup_pending_images' ) );

	// Track last-active time for Application Password connections.
	add_action( 'application_password_did_authenticate', array( 'Axtolab_AI_Connector_Connections', 'on_app_password_auth' ), 10, 2 );

	// Defensively sanitize SVG uploads through ANY path. WordPress disallows
	// SVG by default, but the moment another plugin (or the host) flips
	// that on, an unsanitized SVG upload becomes a stored-XSS vector. We
	// strip <script>, animation tags, on* handlers and javascript:/data:
	// hrefs before WP moves the file into the uploads dir. Priority 1 so
	// we run before any other prefilter.
	add_filter( 'wp_handle_upload_prefilter', array( 'Axtolab_AI_Connector_Upload_Portal', 'sanitize_uploaded_svg_filter' ), 1 );
	add_filter( 'wp_handle_sideload_prefilter', array( 'Axtolab_AI_Connector_Upload_Portal', 'sanitize_uploaded_svg_filter' ), 1 );

	// Prevent generated service-account credentials from bypassing connector policy via core REST routes.
	Axtolab_AI_Connector_Service_Account_Guard::bootstrap();

	// Cleanup expired connections daily.
	add_action( 'axtolab_ai_connector_cleanup_expired', array( 'Axtolab_AI_Connector_Connections', 'cleanup_expired_connections' ) );

	// Boot signed preview links (template_redirect + init hooks).
	Axtolab_AI_Connector_Preview::bootstrap();

	// Register REST routes.
	add_action(
		'rest_api_init',
		static function () {
			$rest = new Axtolab_AI_Connector_REST();
			$rest->register_routes();

			$device_auth = new Axtolab_AI_Connector_Device_Auth();
			$device_auth->register_routes();
		}
	);

	// Bypass WordPress cookie authentication for public upload portal endpoints.
	// Without this, logged-in users get rest_cookie_invalid_nonce (403) because
	// the browser sends auth cookies but the page doesn't include X-WP-Nonce.
	// These endpoints use their own session-token auth — cookies are not needed.
	add_filter(
		'rest_authentication_errors',
		static function ( $result ) {
			if ( null !== $result ) {
				// Another auth handler already decided — don't override.
				return $result;
			}

			$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
			$rest_prefix = '/' . trim( rest_get_url_prefix(), '/' ) . '/';

			// Match only our exact public upload endpoints under the REST prefix.
			$upload_file   = $rest_prefix . 'axtolab-ai-connector/v1/upload/file';
			$upload_portal = $rest_prefix . 'axtolab-ai-connector/v1/upload/portal';
			$path          = wp_parse_url( $request_uri, PHP_URL_PATH );

			if ( $path === $upload_file || $path === $upload_portal ) {
				// Return true = "authentication passed, but no user set".
				// This prevents the cookie nonce check from running.
				return true;
			}

			return $result;
		},
		99
	);

	// Remote MCP Transport (Streamable HTTP).
	// Enable if bearer token or OAuth is active.
	$settings       = get_option( 'axtolab_ai_connector_settings', array() );
	$bearer_active  = ! empty( $settings['remote_mcp_enabled'] ) && Axtolab_AI_Connector_Bearer_Auth::has_token();
	$oauth_active   = ! empty( $settings['oauth_enabled'] );

	if ( $bearer_active || $oauth_active ) {
		Axtolab_AI_Connector_MCP_Transport::bootstrap();
	}

	// OAuth discovery and endpoints (enabled separately from transport).
	if ( $oauth_active ) {
		Axtolab_AI_Connector_OAuth::bootstrap();
	}

	// Upgrade .htaccess rules for existing installations.
	// The HTTP_AUTHORIZATION passthrough was added in 0.1.33; this ensures
	// sites that activated on an earlier version get the updated rules.
	if ( is_admin() ) {
		$htaccess_version = get_option( 'axtolab_ai_connector_htaccess_version', '' );
		if ( version_compare( $htaccess_version, '0.1.33', '<' ) ) {
			axtolab_ai_connector_write_oauth_htaccess_rules();
			update_option( 'axtolab_ai_connector_htaccess_version', AXTOLAB_AI_CONNECTOR_VERSION, true );
		}
	}

	// Self-heal: ensure the OAuth marker block exists in .htaccess. Catches
	// the case where a plugin update via `wp plugin install --force` against
	// an already-active install skipped the activation hook and never wrote
	// the rules. Also handles legacy-marker cleanup after the brand rename.
	// Throttled to once per hour via transient so we don't read .htaccess on
	// every admin page load.
	if ( is_admin() && false === get_transient( 'axtolab_ai_connector_htaccess_check' ) ) {
		axtolab_ai_connector_ensure_oauth_htaccess_rules();
		set_transient( 'axtolab_ai_connector_htaccess_check', 1, HOUR_IN_SECONDS );
	}

	// Boot admin UI (only in the admin context to save frontend overhead).
	if ( is_admin() ) {
		$admin = new Axtolab_AI_Connector_Admin();
		$admin->init();

		// Display any activation error stored as a transient. Each error
		// notice carries an inline "contact support" link via the shared
		// Axtolab_Support_Links helper so a stuck merchant can reach us
		// with one click.
		add_action(
			'admin_notices',
			static function () {
				$support = '';
				if ( class_exists( 'Axtolab_Support_Links' ) ) {
					$support = ' ' . Axtolab_Support_Links::inline_contact_link( 'AI Connector', AXTOLAB_AI_CONNECTOR_VERSION, __( 'If this persists, contact support', 'axtolab-ai-connector' ) ) . '.';
				}

				$error = get_transient( 'axtolab_ai_connector_activation_error' );
				if ( $error ) {
					delete_transient( 'axtolab_ai_connector_activation_error' );
					printf(
						'<div class="notice notice-error"><p><strong>Axtolab AI Connector:</strong> %s%s</p></div>',
						esc_html( $error ),
						wp_kses( $support, array( 'a' => array( 'href' => true ) ) )
					);
				}

				$multisite_allowed = Axtolab_AI_Connector_Free_Gates::check_multisite_allowed();
				if ( is_wp_error( $multisite_allowed ) ) {
					printf(
						'<div class="notice notice-error"><p><strong>Axtolab AI Connector:</strong> %s%s</p></div>',
						esc_html( $multisite_allowed->get_error_message() ),
						wp_kses( $support, array( 'a' => array( 'href' => true ) ) )
					);
				}
			}
		);
	}
}

add_action( 'plugins_loaded', 'axtolab_ai_connector_bootstrap' );
