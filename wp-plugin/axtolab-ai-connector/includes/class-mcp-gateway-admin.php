<?php
/**
 * MCP Gateway Admin
 *
 * Registers the admin menu page and renders all admin UI for the plugin,
 * including the "Connect AI Client" connection-token section, setup
 * checklist, and service-account management.
 *
 * @package WP_MCP_Gateway
 * @since   0.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Axtolab_AI_Connector_Admin
 *
 * Handles all wp-admin integration: menu, settings page, AJAX handlers.
 */
class Axtolab_AI_Connector_Admin {

	/**
	 * Slug used for the admin menu page.
	 *
	 * @var string
	 */
	const MENU_SLUG = 'axtolab-ai-connector';

	/**
	 * Top-level "Axtolab" brand menu slug. Add-on plugins (governance, WC AI PM, etc.)
	 * register their submenus against this slug so the whole suite mounts under one
	 * sidebar parent. Stable contract — do not rename.
	 *
	 * @var string
	 */
	const PARENT_MENU_SLUG = 'axtolab';

	/**
	 * AJAX action for revoking all service-account app passwords.
	 *
	 * @var string
	 */
	const AJAX_REVOKE_ALL = 'axtolab_ai_connector_revoke_all_passwords';

	/**
	 * AJAX action for recreating the service account.
	 *
	 * @var string
	 */
	const AJAX_RECREATE_SERVICE_ACCOUNT = 'axtolab_ai_connector_recreate_service_account';

	/**
	 * AJAX action for generating a connection token.
	 *
	 * @var string
	 */
	const AJAX_GENERATE_TOKEN = 'axtolab_ai_connector_generate_token';

	/**
	 * AJAX action for toggling the Remote MCP feature.
	 *
	 * @var string
	 */
	const AJAX_TOGGLE_REMOTE = 'axtolab_ai_connector_toggle_remote';

	/**
	 * AJAX action for generating a bearer token.
	 *
	 * @var string
	 */
	const AJAX_GENERATE_BEARER = 'axtolab_ai_connector_generate_bearer';

	/**
	 * AJAX action for revoking the bearer token.
	 *
	 * @var string
	 */
	const AJAX_REVOKE_BEARER = 'axtolab_ai_connector_revoke_bearer';

	/**
	 * AJAX action for saving capability checkboxes.
	 *
	 * @var string
	 */
	const AJAX_SAVE_CAPABILITIES = 'axtolab_ai_connector_save_capabilities';

	/**
	 * AJAX action for toggling OAuth.
	 *
	 * @var string
	 */
	const AJAX_TOGGLE_OAUTH = 'axtolab_ai_connector_toggle_oauth';

	/**
	 * AJAX action for revoking the OAuth token.
	 *
	 * @var string
	 */
	const AJAX_REVOKE_OAUTH = 'axtolab_ai_connector_revoke_oauth';

	/**
	 * AJAX action for saving image provider settings.
	 *
	 * @var string
	 */
	const AJAX_SAVE_IMAGE_PROVIDERS = 'axtolab_ai_connector_save_image_providers';

	/**
	 * AJAX action for testing an image provider connection.
	 *
	 * @var string
	 */
	const AJAX_TEST_IMAGE_PROVIDER = 'axtolab_ai_connector_test_image_provider';

	/**
	 * AJAX action for renaming a connection.
	 *
	 * @var string
	 */
	const AJAX_RENAME_CONNECTION = 'axtolab_ai_connector_rename_connection';

	/**
	 * AJAX action for revoking a single connection.
	 *
	 * @var string
	 */
	const AJAX_REVOKE_CONNECTION = 'axtolab_ai_connector_revoke_connection';

	/**
	 * AJAX action for updating per-connection capability groups.
	 *
	 * @var string
	 */
	const AJAX_UPDATE_CONNECTION_CAPS = 'axtolab_ai_connector_update_connection_caps';

	/**
	 * AJAX action for saving the review notification email.
	 *
	 * @var string
	 */
	const AJAX_SAVE_REVIEW_EMAIL = 'axtolab_ai_connector_save_review_email';

	/**
	 * AJAX action for updating per-connection allowed author IDs.
	 *
	 * @var string
	 */
	const AJAX_UPDATE_CONNECTION_AUTHORS = 'axtolab_ai_connector_update_connection_authors';

	/**
	 * AJAX action for toggling advanced write gates (permalink_writes_enabled,
	 * options_writes_enabled). Both default OFF; admins flip them on for
	 * specific automation flows and flip them back off when done.
	 *
	 * @var string
	 */
	const AJAX_TOGGLE_ADVANCED_WRITE = 'axtolab_ai_connector_toggle_advanced_write';

	/**
	 * Query-string key used by the first-run OAuth enable notice.
	 *
	 * @var string
	 */
	const OAUTH_NOTICE_QUERY_KEY = 'axtolab_oauth_notice';

	/**
	 * Nonce action used by the first-run OAuth enable notice.
	 *
	 * @var string
	 */
	const OAUTH_NOTICE_NONCE_ACTION = 'axtolab_ai_connector_oauth_notice';

	/**
	 * User-meta key recording per-user dismissal of the OAuth enable notice.
	 *
	 * @var string
	 */
	const OAUTH_NOTICE_DISMISSED_META = 'axtolab_ai_connector_oauth_notice_dismissed';

	/**
	 * admin-post.php action name for the explicit "Create service user"
	 * consent click. WP.org plugin review forbids creating WordPress users
	 * silently on activation, so the service account is materialised only
	 * after an administrator clicks this consent button.
	 *
	 * @var string
	 */
	const SERVICE_ACCOUNT_CREATE_ACTION = 'axtolab_ai_connector_create_service_account';

	/**
	 * admin-post.php action name for dismissing the service-account consent
	 * notice without creating the user. Sets a per-user dismissal flag so the
	 * notice stops appearing for the admin who clicked Dismiss.
	 *
	 * @var string
	 */
	const SERVICE_ACCOUNT_DISMISS_ACTION = 'axtolab_ai_connector_dismiss_service_notice';

	/**
	 * Nonce action used by both consent-notice buttons (Create / Dismiss).
	 *
	 * @var string
	 */
	const SERVICE_ACCOUNT_NONCE_ACTION = 'axtolab_ai_connector_service_account_notice';

	/**
	 * User-meta key recording per-user dismissal of the service-account
	 * consent notice. Stored per-user so other admins on the same site still
	 * see the notice until they either create the user or dismiss it
	 * themselves.
	 *
	 * @var string
	 */
	const SERVICE_ACCOUNT_NOTICE_DISMISSED_META = 'axtolab_ai_connector_service_account_notice_dismissed';

	/**
	 * Bind all WordPress hooks.
	 *
	 * @return void
	 */
	public function init(): void {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'admin_notices', array( $this, 'render_oauth_enable_notice' ) );
		add_action( 'admin_init', array( $this, 'maybe_handle_oauth_notice_action' ) );

		// Service-account consent notice + admin-post handlers (WP.org review:
		// no silent user creation on activation; admin must explicitly opt in).
		add_action( 'admin_notices', array( $this, 'render_service_account_consent_notice' ) );
		add_action( 'admin_post_' . self::SERVICE_ACCOUNT_CREATE_ACTION, array( $this, 'handle_service_account_create' ) );
		add_action( 'admin_post_' . self::SERVICE_ACCOUNT_DISMISS_ACTION, array( $this, 'handle_service_account_dismiss' ) );

		// AJAX handlers — logged-in users only (nonce checked inside each handler).
		add_action( 'wp_ajax_' . self::AJAX_REVOKE_ALL, array( $this, 'ajax_revoke_all_passwords' ) );
		add_action( 'wp_ajax_' . self::AJAX_RECREATE_SERVICE_ACCOUNT, array( $this, 'ajax_recreate_service_account' ) );
		add_action( 'wp_ajax_' . self::AJAX_GENERATE_TOKEN, array( $this, 'ajax_generate_token' ) );
		add_action( 'wp_ajax_' . self::AJAX_TOGGLE_REMOTE, array( $this, 'ajax_toggle_remote' ) );
		add_action( 'wp_ajax_' . self::AJAX_GENERATE_BEARER, array( $this, 'ajax_generate_bearer' ) );
		add_action( 'wp_ajax_' . self::AJAX_REVOKE_BEARER, array( $this, 'ajax_revoke_bearer' ) );
		add_action( 'wp_ajax_' . self::AJAX_SAVE_CAPABILITIES, array( $this, 'ajax_save_capabilities' ) );
		add_action( 'wp_ajax_' . self::AJAX_TOGGLE_OAUTH, array( $this, 'ajax_toggle_oauth' ) );
		add_action( 'wp_ajax_' . self::AJAX_REVOKE_OAUTH, array( $this, 'ajax_revoke_oauth' ) );
		add_action( 'wp_ajax_' . self::AJAX_SAVE_IMAGE_PROVIDERS, array( $this, 'ajax_save_image_providers' ) );
		add_action( 'wp_ajax_' . self::AJAX_TEST_IMAGE_PROVIDER, array( $this, 'ajax_test_image_provider' ) );
		add_action( 'wp_ajax_' . self::AJAX_RENAME_CONNECTION, array( $this, 'ajax_rename_connection' ) );
		add_action( 'wp_ajax_' . self::AJAX_REVOKE_CONNECTION, array( $this, 'ajax_revoke_connection' ) );
		add_action( 'wp_ajax_' . self::AJAX_UPDATE_CONNECTION_CAPS, array( $this, 'ajax_update_connection_caps' ) );
		add_action( 'wp_ajax_' . self::AJAX_SAVE_REVIEW_EMAIL, array( $this, 'ajax_save_review_email' ) );
		add_action( 'wp_ajax_' . self::AJAX_UPDATE_CONNECTION_AUTHORS, array( $this, 'ajax_update_connection_authors' ) );
		add_action( 'wp_ajax_' . self::AJAX_TOGGLE_ADVANCED_WRITE, array( $this, 'ajax_toggle_advanced_write' ) );

		// Plugin-row Support / Docs links — visible on the Plugins admin
		// page next to Activate / Deactivate / Settings. Same shared
		// helper add-ons use, so support copy stays consistent.
		add_filter( 'plugin_action_links_' . plugin_basename( AXTOLAB_AI_CONNECTOR_FILE ), array( $this, 'add_plugin_action_links' ) );
		add_filter( 'plugin_row_meta', array( $this, 'add_plugin_row_meta' ), 10, 2 );

	}

	// ── First-run OAuth enable notice ────────────────────────────────────────

	/**
	 * Render the OAuth enable nudge on the Plugins admin page when the
	 * gateway is active but OAuth has not been turned on yet. OAuth is
	 * disabled by default so the plugin ships with the smallest possible
	 * unauthenticated surface; the notice keeps the toggle one click away
	 * for admins who do want web-client connectors (ChatGPT, Claude Web).
	 *
	 * @return void
	 */
	public function render_oauth_enable_notice(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( null === $screen || 'plugins' !== $screen->id ) {
			return;
		}

		$settings = get_option( 'axtolab_ai_connector_settings', array() );
		if ( ! empty( $settings['oauth_enabled'] ) ) {
			return;
		}

		if ( get_user_meta( get_current_user_id(), self::OAUTH_NOTICE_DISMISSED_META, true ) ) {
			return;
		}

		$settings_url = admin_url( 'admin.php?page=' . self::MENU_SLUG );
		$enable_url   = wp_nonce_url(
			add_query_arg( self::OAUTH_NOTICE_QUERY_KEY, 'enable', $settings_url ),
			self::OAUTH_NOTICE_NONCE_ACTION
		);
		$dismiss_url  = wp_nonce_url(
			add_query_arg( self::OAUTH_NOTICE_QUERY_KEY, 'dismiss', admin_url( 'plugins.php' ) ),
			self::OAUTH_NOTICE_NONCE_ACTION
		);
		?>
		<div class="notice notice-info">
			<p>
				<strong><?php esc_html_e( 'Axtolab AI Connector:', 'axtolab-ai-connector' ); ?></strong>
				<?php esc_html_e( 'OAuth for web-based AI clients (ChatGPT, Claude Web) is disabled. Enable it to let those clients connect to this site.', 'axtolab-ai-connector' ); ?>
			</p>
			<p>
				<a class="button button-primary" href="<?php echo esc_url( $enable_url ); ?>"><?php esc_html_e( 'Enable OAuth', 'axtolab-ai-connector' ); ?></a>
				<a class="button" href="<?php echo esc_url( $dismiss_url ); ?>"><?php esc_html_e( 'Dismiss', 'axtolab-ai-connector' ); ?></a>
			</p>
		</div>
		<?php
	}

	/**
	 * Handle the Enable / Dismiss click-throughs from the OAuth enable notice.
	 *
	 * Bound to `admin_init` so it can issue a redirect before any output. Both
	 * actions are nonce-protected and gated on `manage_options`.
	 *
	 * @return void
	 */
	public function maybe_handle_oauth_notice_action(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- The check_admin_referer() call below verifies the nonce; this read is just for branching.
		if ( ! isset( $_GET[ self::OAUTH_NOTICE_QUERY_KEY ] ) ) {
			return;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		check_admin_referer( self::OAUTH_NOTICE_NONCE_ACTION );

		$action = sanitize_key( wp_unslash( $_GET[ self::OAUTH_NOTICE_QUERY_KEY ] ) );

		if ( 'enable' === $action ) {
			$settings                  = get_option( 'axtolab_ai_connector_settings', array() );
			$settings['oauth_enabled'] = true;
			update_option( 'axtolab_ai_connector_settings', $settings );

			if ( function_exists( 'axtolab_ai_connector_write_oauth_htaccess_rules' ) ) {
				axtolab_ai_connector_write_oauth_htaccess_rules();
			}

			wp_safe_redirect(
				add_query_arg( 'axtolab_oauth_enabled', '1', admin_url( 'admin.php?page=' . self::MENU_SLUG ) )
			);
			exit;
		}

		if ( 'dismiss' === $action ) {
			update_user_meta( get_current_user_id(), self::OAUTH_NOTICE_DISMISSED_META, 1 );
			wp_safe_redirect( admin_url( 'plugins.php' ) );
			exit;
		}
	}

	// ── Service-account consent notice ───────────────────────────────────────
	//
	// WP.org plugin review (round 3) flagged automatic creation of a
	// WordPress user on plugin activation as a security risk: a plugin must
	// not create user accounts on a site without explicit administrator
	// consent. We keep the dedicated service-account architecture (so AI
	// edits are attributed to a minimal-permission identity that's
	// independent of any human admin) but defer creation until the admin
	// explicitly clicks "Create service user" on the AI Connector page.

	/**
	 * Whether the deferred service account still needs to be created.
	 *
	 * Returns true when the stored service-user option is missing, or when
	 * the stored ID no longer resolves to a real WP user (e.g. someone
	 * deleted it manually). Used to gate both the consent notice and
	 * defensive UI fall-backs.
	 *
	 * @return bool
	 */
	public static function service_account_needs_creation(): bool {
		$service_user_id = (int) get_option( 'axtolab_ai_connector_service_user_id', 0 );
		if ( $service_user_id <= 0 ) {
			return true;
		}
		$user = get_user_by( 'id', $service_user_id );
		return ! ( $user instanceof WP_User );
	}

	/**
	 * Render the admin notice that asks the administrator for explicit
	 * consent to create the AI Connector service user + role.
	 *
	 * Shown only when:
	 *
	 *   - the viewer can manage_options;
	 *   - the current admin screen is an AI Connector / Axtolab page;
	 *   - the service user does NOT yet exist;
	 *   - the current admin hasn't dismissed the notice for themselves.
	 *
	 * The notice has two buttons:
	 *
	 *   - "Create service user" — POSTs to admin-post.php with a nonce and
	 *     manage_options gate, which calls the shared idempotent
	 *     {@see axtolab_ai_connector_ensure_service_account()} helper.
	 *   - "Dismiss"             — POSTs to admin-post.php with a nonce, sets a
	 *     per-user meta flag so the notice stops re-appearing for that user.
	 *
	 * @return void
	 */
	public function render_service_account_consent_notice(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( null === $screen ) {
			return;
		}

		// Limit the notice to AI Connector / Axtolab admin pages so we don't
		// pollute unrelated wp-admin screens. Hook formats:
		//   toplevel_page_axtolab          (parent menu direct nav)
		//   axtolab_page_axtolab-ai-connector       (AI Connector submenu)
		//   axtolab_page_axtolab-ai-connector-logs  (Logs & Roll Back submenu)
		$allowed_screens = array(
			'toplevel_page_' . self::PARENT_MENU_SLUG,
			self::PARENT_MENU_SLUG . '_page_' . self::MENU_SLUG,
			self::PARENT_MENU_SLUG . '_page_' . self::MENU_SLUG . '-logs',
		);
		if ( ! in_array( $screen->id, $allowed_screens, true ) ) {
			return;
		}

		if ( ! self::service_account_needs_creation() ) {
			return;
		}

		if ( get_user_meta( get_current_user_id(), self::SERVICE_ACCOUNT_NOTICE_DISMISSED_META, true ) ) {
			return;
		}

		$create_url = admin_url( 'admin-post.php' );
		?>
		<div class="notice notice-info">
			<p>
				<strong><?php esc_html_e( 'Axtolab AI Connector — one-time setup:', 'axtolab-ai-connector' ); ?></strong>
				<?php esc_html_e( 'AI tool calls run as a dedicated service account so changes are auditable and never tied to your personal admin user.', 'axtolab-ai-connector' ); ?>
			</p>
			<p>
				<?php
				printf(
					/* translators: 1: WP user login the plugin will create, 2: name of the WP role the plugin will create */
					esc_html__( 'Click %1$s to create a WordPress user named %2$s with a custom %3$s role limited to editing posts, pages, media, and categories. The user has no admin / settings / user-management permissions. You can remove it any time by uninstalling the plugin.', 'axtolab-ai-connector' ),
					'<strong>' . esc_html__( 'Create service user', 'axtolab-ai-connector' ) . '</strong>',
					'<code>axtolab-connector-service</code>',
					'<code>axtolab_ai_connector_editor</code>'
				);
				?>
			</p>
			<p>
				<form method="post" action="<?php echo esc_url( $create_url ); ?>" style="display:inline;">
					<input type="hidden" name="action" value="<?php echo esc_attr( self::SERVICE_ACCOUNT_CREATE_ACTION ); ?>" />
					<?php wp_nonce_field( self::SERVICE_ACCOUNT_NONCE_ACTION ); ?>
					<button type="submit" class="button button-primary">
						<?php esc_html_e( 'Create service user', 'axtolab-ai-connector' ); ?>
					</button>
				</form>
				<form method="post" action="<?php echo esc_url( $create_url ); ?>" style="display:inline; margin-left:6px;">
					<input type="hidden" name="action" value="<?php echo esc_attr( self::SERVICE_ACCOUNT_DISMISS_ACTION ); ?>" />
					<?php wp_nonce_field( self::SERVICE_ACCOUNT_NONCE_ACTION ); ?>
					<button type="submit" class="button button-secondary">
						<?php esc_html_e( 'Dismiss', 'axtolab-ai-connector' ); ?>
					</button>
				</form>
			</p>
			<p class="description">
				<?php esc_html_e( 'If you dismiss this, you can still create the service user later from the "Service Account" row in the Setup Status panel below.', 'axtolab-ai-connector' ); ?>
			</p>
		</div>
		<?php
	}

	/**
	 * Handle the "Create service user" consent click.
	 *
	 * POST → admin-post.php, nonce-protected, manage_options gated. Calls the
	 * shared idempotent ensure-helper, then redirects back to the AI
	 * Connector settings page with a status query arg.
	 *
	 * @return void
	 */
	public function handle_service_account_create(): void {
		check_admin_referer( self::SERVICE_ACCOUNT_NONCE_ACTION );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die(
				esc_html__( 'You do not have permission to perform this action.', 'axtolab-ai-connector' ),
				esc_html__( 'Permission denied', 'axtolab-ai-connector' ),
				array( 'response' => 403 )
			);
		}

		$result   = axtolab_ai_connector_ensure_service_account();
		$redirect = admin_url( 'admin.php?page=' . self::MENU_SLUG );

		if ( is_wp_error( $result ) ) {
			$redirect = add_query_arg(
				array(
					'axtolab_service_account' => 'error',
					'axtolab_error'           => rawurlencode( $result->get_error_code() ),
				),
				$redirect
			);
		} else {
			$redirect = add_query_arg( array( 'axtolab_service_account' => 'created' ), $redirect );
		}

		wp_safe_redirect( $redirect );
		exit;
	}

	/**
	 * Handle the "Dismiss" click on the service-account consent notice.
	 *
	 * POST → admin-post.php, nonce-protected, manage_options gated. Sets a
	 * per-user meta flag so the notice stops appearing for the current admin
	 * (other admins on the same site will still see it until they make their
	 * own choice).
	 *
	 * @return void
	 */
	public function handle_service_account_dismiss(): void {
		check_admin_referer( self::SERVICE_ACCOUNT_NONCE_ACTION );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die(
				esc_html__( 'You do not have permission to perform this action.', 'axtolab-ai-connector' ),
				esc_html__( 'Permission denied', 'axtolab-ai-connector' ),
				array( 'response' => 403 )
			);
		}

		update_user_meta( get_current_user_id(), self::SERVICE_ACCOUNT_NOTICE_DISMISSED_META, 1 );

		$redirect = add_query_arg(
			array( 'axtolab_service_account' => 'dismissed' ),
			admin_url( 'admin.php?page=' . self::MENU_SLUG )
		);
		wp_safe_redirect( $redirect );
		exit;
	}

	// ── Plugin-row Support / Docs links ──────────────────────────────────────

	/**
	 * Add a "Settings · Support" pair to the Plugins admin row for this
	 * plugin (left-side action links, next to Deactivate). Both come
	 * from Axtolab_Support_Links so the support email + label stays
	 * consistent across the suite.
	 */
	public function add_plugin_action_links( $links ) {
		if ( ! class_exists( 'Axtolab_Support_Links' ) ) {
			return $links;
		}
		$settings_url = admin_url( 'admin.php?page=' . self::MENU_SLUG );
		$rows         = Axtolab_Support_Links::plugin_row_links( 'AI Connector', AXTOLAB_AI_CONNECTOR_VERSION, 'axtolab-ai-connector', $settings_url );
		// WP merges $links with our additions — prepend ours so they
		// appear before Deactivate / Activate.
		return array_merge( $rows['action'], $links );
	}

	/**
	 * Add a "Support · WP.org forum · Docs" trio to the Plugins admin
	 * row meta (right-side, under "Visit plugin site"). Filtered for
	 * this plugin only via the $file argument.
	 */
	public function add_plugin_row_meta( $links, $file ) {
		if ( $file !== plugin_basename( AXTOLAB_AI_CONNECTOR_FILE ) ) {
			return $links;
		}
		if ( ! class_exists( 'Axtolab_Support_Links' ) ) {
			return $links;
		}
		$rows = Axtolab_Support_Links::plugin_row_links( 'AI Connector', AXTOLAB_AI_CONNECTOR_VERSION, 'axtolab-ai-connector' );
		return array_merge( $links, $rows['meta'] );
	}

	// ── Menu & assets ─────────────────────────────────────────────────────────

	/**
	 * Register the plugin admin menu page.
	 *
	 * @return void
	 */
	public function register_menu(): void {
		// Top-level "Axtolab" brand parent. Same callback as AI Connector so direct
		// navigation to ?page=axtolab works as well as the nested submenu URL.
		add_menu_page(
			__( 'Axtolab', 'axtolab-ai-connector' ),
			__( 'Axtolab', 'axtolab-ai-connector' ),
			'manage_options',
			self::PARENT_MENU_SLUG,
			array( $this, 'render_settings_page' ),
			self::menu_icon(),
			80
		);

		// First submenu — AI Connector at its existing slug so all existing
		// URLs (?page=axtolab-ai-connector), nonces, hook suffixes rooted at
		// MENU_SLUG keep working.
		add_submenu_page(
			self::PARENT_MENU_SLUG,
			__( 'AI Connector', 'axtolab-ai-connector' ),
			__( 'AI Connector', 'axtolab-ai-connector' ),
			'manage_options',
			self::MENU_SLUG,
			array( $this, 'render_settings_page' )
		);

		// Phase 5 — Logs & Roll Back. AI-driven changes captured in the
		// changelog table, with one-click undo + redo.
		add_submenu_page(
			self::PARENT_MENU_SLUG,
			__( 'Logs & Roll Back', 'axtolab-ai-connector' ),
			__( 'Logs & Roll Back', 'axtolab-ai-connector' ),
			'edit_posts',
			'axtolab-ai-connector-logs',
			array( $this, 'render_logs_page' )
		);

		// Remove the auto-created duplicate submenu that mirrors the parent label.
		remove_submenu_page( self::PARENT_MENU_SLUG, self::PARENT_MENU_SLUG );
	}

	/**
	 * Return a base64 data URI for the Axtolab brand mark used as the menu icon.
	 *
	 * Falls back to a dashicon if the SVG asset is missing.
	 *
	 * @return string
	 */
	private static function menu_icon(): string {
		static $cached = null;
		if ( $cached !== null ) {
			return $cached;
		}
		$svg_path = plugin_dir_path( AXTOLAB_AI_CONNECTOR_FILE ) . 'assets/axtolab-mark.svg';
		if ( ! is_readable( $svg_path ) ) {
			$cached = 'dashicons-rest-api';
			return $cached;
		}
		$svg = file_get_contents( $svg_path );
		if ( $svg === false ) {
			$cached = 'dashicons-rest-api';
			return $cached;
		}
		$cached = 'data:image/svg+xml;base64,' . base64_encode( $svg );
		return $cached;
	}

	/**
	 * Enqueue admin styles and inline script data for the settings page.
	 *
	 * @param  string $hook_suffix The current admin page hook suffix.
	 * @return void
	 */
	public function enqueue_assets( string $hook_suffix ): void {
		// Load on either the Axtolab parent landing, the AI Connector submenu, or Logs.
		// Hook formats: 'toplevel_page_axtolab' (parent direct nav) and
		// '{parent}_page_{submenu_slug}' = 'axtolab_page_wp-mcp-gateway' (nested page).
		$parent_landing  = 'toplevel_page_' . self::PARENT_MENU_SLUG;
		$ai_connector    = self::PARENT_MENU_SLUG . '_page_' . self::MENU_SLUG;
		$logs_page       = self::PARENT_MENU_SLUG . '_page_wp-mcp-gateway-logs';
		$is_settings     = in_array( $hook_suffix, array( $parent_landing, $ai_connector ), true );
		$is_logs         = ( $hook_suffix === $logs_page );
		if ( ! $is_settings && ! $is_logs ) {
			return;
		}

		// Inline styles — keeps the plugin dependency-free for CSS.
		wp_register_style( 'axtolab-ai-connector-admin', false, array(), AXTOLAB_AI_CONNECTOR_VERSION );
		wp_enqueue_style( 'axtolab-ai-connector-admin' );
		wp_add_inline_style( 'axtolab-ai-connector-admin', $this->get_inline_styles() );

		// Localized data for AJAX calls.
		wp_register_script( 'axtolab-ai-connector-admin', false, array( 'jquery' ), AXTOLAB_AI_CONNECTOR_VERSION, true );
		wp_enqueue_script( 'axtolab-ai-connector-admin' );
		wp_localize_script(
			'axtolab-ai-connector-admin',
			'axtolabAiConnector',
			array(
				'restBase'       => esc_url_raw( rest_url( 'axtolab-ai-connector/v1' ) ),
				'nonce'          => wp_create_nonce( 'wp_rest' ),
				'ajaxUrl'        => admin_url( 'admin-ajax.php' ),
				'ajaxNonce'      => wp_create_nonce( self::MENU_SLUG . '-ajax' ),
				'strings'        => array(
					'revoking'     => __( 'Revoking…', 'axtolab-ai-connector' ),
					'recreating'   => __( 'Recreating…', 'axtolab-ai-connector' ),
					'confirmRevoke' => __( 'Revoke all active connections? Connected AI clients will lose access until re-authorized.', 'axtolab-ai-connector' ),
				),
				'mcpbAvailable'  => true,
				'actions'        => array(
					'saveImageProviders'       => self::AJAX_SAVE_IMAGE_PROVIDERS,
					'testImageProvider'        => self::AJAX_TEST_IMAGE_PROVIDER,
					'renameConnection'         => self::AJAX_RENAME_CONNECTION,
					'revokeConnection'         => self::AJAX_REVOKE_CONNECTION,
					'updateConnectionCaps'     => self::AJAX_UPDATE_CONNECTION_CAPS,
					'saveReviewEmail'          => self::AJAX_SAVE_REVIEW_EMAIL,
					'updateConnectionAuthors'  => self::AJAX_UPDATE_CONNECTION_AUTHORS,
					'toggleAdvancedWrite'      => self::AJAX_TOGGLE_ADVANCED_WRITE,
				),
			)
		);
		if ( $is_settings ) {
			wp_add_inline_script( 'axtolab-ai-connector-admin', $this->get_inline_script() );
		}
		if ( $is_logs ) {
			wp_add_inline_script( 'axtolab-ai-connector-admin', $this->get_logs_inline_script() );
		}
	}

	// ── Settings page ─────────────────────────────────────────────────────────

	/**
	 * Render the full settings / dashboard page.
	 *
	 * @return void
	 */
	public function render_settings_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'axtolab-ai-connector' ) );
		}

		$status = $this->get_setup_status();
		?>
		<div class="wrap mcp-gateway-wrap">
			<h1>
				<?php esc_html_e( 'Axtolab AI Connector', 'axtolab-ai-connector' ); ?>
				<?php
				if ( class_exists( 'Axtolab_Support_Links' ) ) {
					Axtolab_Support_Links::render_header_link( 'AI Connector', AXTOLAB_AI_CONNECTOR_VERSION );
				}
				?>
			</h1>
			<p class="mcp-gateway-tagline">
				<?php esc_html_e( 'Connects Claude, ChatGPT, and AI agents to your WordPress site, safely.', 'axtolab-ai-connector' ); ?>
			</p>

			<div class="mcp-gateway-columns">

				<?php $this->render_setup_checklist( $status ); ?>

				<?php $this->render_connect_claude_section( $status ); ?>

			</div><!-- .mcp-gateway-columns -->

			<?php $this->render_advanced_writes_section(); ?>

			<?php $this->render_info_section(); ?>

			<?php
			if ( class_exists( 'Axtolab_Support_Links' ) ) {
				Axtolab_Support_Links::render_footer( 'AI Connector', AXTOLAB_AI_CONNECTOR_VERSION, 'axtolab-ai-connector' );
			}
			?>

		</div><!-- .wrap -->
		<?php
	}

	/**
	 * Phase 5 — Logs & Roll Back admin page.
	 *
	 * Filterable table of recorded AI changes with Undo / Redo /
	 * View detail buttons and session-level rollback.
	 *
	 * @return void
	 */
	public function render_logs_page(): void {
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'axtolab-ai-connector' ) );
		}

		// Read display filters from the query string; this is a read-only admin view.
		// Capability check already enforced above (edit_posts). Filter params are
		// sanitized below and only used to scope SELECT queries — no writes occur
		// from this read-side handler, so no WordPress nonce is required.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$query_params = wp_unslash( $_GET );
		$filters = array();
		foreach ( array( 'session_id', 'target_type', 'target_id', 'tool_name', 'source', 'action', 'status' ) as $f ) {
			if ( ! empty( $query_params[ $f ] ) ) {
				$filters[ $f ] = sanitize_text_field( (string) $query_params[ $f ] );
			}
		}
		$filters['per_page'] = isset( $query_params['per_page'] ) ? min( 200, max( 1, (int) $query_params['per_page'] ) ) : 50;
		$filters['offset']   = isset( $query_params['offset'] ) ? max( 0, (int) $query_params['offset'] ) : 0;

		$result = Axtolab_AI_Connector_Changelog::query( $filters );
		$items  = isset( $result['items'] ) ? $result['items'] : array();
		$total  = isset( $result['total'] ) ? (int) $result['total'] : 0;

		?>
		<div class="wrap mcp-gateway-wrap">
			<h1><?php esc_html_e( 'Logs & Roll Back', 'axtolab-ai-connector' ); ?></h1>
			<p class="mcp-gateway-tagline">
				<?php esc_html_e( 'Every AI-driven change captured in the changelog. Undo individual changes or whole sessions; redo what you reverted.', 'axtolab-ai-connector' ); ?>
			</p>

			<form method="get" style="margin: 1em 0; padding: 0.75em; background: #fff; border: 1px solid #c3c4c7;">
				<input type="hidden" name="page" value="wp-mcp-gateway-logs" />
				<label><?php esc_html_e( 'Status', 'axtolab-ai-connector' ); ?>
					<select name="status">
						<option value=""><?php esc_html_e( 'All', 'axtolab-ai-connector' ); ?></option>
						<option value="pending" <?php selected( ( $filters['status'] ?? '' ), 'pending' ); ?>><?php esc_html_e( 'Pending (in effect)', 'axtolab-ai-connector' ); ?></option>
						<option value="rolled_back" <?php selected( ( $filters['status'] ?? '' ), 'rolled_back' ); ?>><?php esc_html_e( 'Rolled back', 'axtolab-ai-connector' ); ?></option>
					</select>
				</label>
				<label style="margin-left: 1em;"><?php esc_html_e( 'Target type', 'axtolab-ai-connector' ); ?>
					<input type="text" name="target_type" value="<?php echo esc_attr( $filters['target_type'] ?? '' ); ?>" placeholder="post / option / menu / ..." />
				</label>
				<label style="margin-left: 1em;"><?php esc_html_e( 'Tool', 'axtolab-ai-connector' ); ?>
					<input type="text" name="tool_name" value="<?php echo esc_attr( $filters['tool_name'] ?? '' ); ?>" placeholder="wp_update_content" />
				</label>
				<label style="margin-left: 1em;"><?php esc_html_e( 'Session', 'axtolab-ai-connector' ); ?>
					<input type="text" name="session_id" value="<?php echo esc_attr( $filters['session_id'] ?? '' ); ?>" />
				</label>
				<button type="submit" class="button"><?php esc_html_e( 'Filter', 'axtolab-ai-connector' ); ?></button>
				<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=axtolab-ai-connector-logs' ) ); ?>"><?php esc_html_e( 'Reset', 'axtolab-ai-connector' ); ?></a>
				<?php if ( ! empty( $filters['session_id'] ) ) : ?>
					<button type="button" class="button button-secondary" data-mcp-rollback-session="<?php echo esc_attr( $filters['session_id'] ); ?>" style="margin-left: 1em;">
						<?php esc_html_e( 'Roll back entire session', 'axtolab-ai-connector' ); ?>
					</button>
				<?php endif; ?>
			</form>

			<p>
				<?php
				/* translators: 1: items shown, 2: total */
				echo esc_html( sprintf( __( 'Showing %1$d of %2$d entries.', 'axtolab-ai-connector' ), count( $items ), $total ) );
				?>
			</p>

			<table class="wp-list-table widefat striped">
				<thead>
					<tr>
						<th style="width: 60px;">ID</th>
						<th style="width: 160px;"><?php esc_html_e( 'When', 'axtolab-ai-connector' ); ?></th>
						<th><?php esc_html_e( 'Tool', 'axtolab-ai-connector' ); ?></th>
						<th><?php esc_html_e( 'Action', 'axtolab-ai-connector' ); ?></th>
						<th><?php esc_html_e( 'Target', 'axtolab-ai-connector' ); ?></th>
						<th><?php esc_html_e( 'Session', 'axtolab-ai-connector' ); ?></th>
						<th><?php esc_html_e( 'Status', 'axtolab-ai-connector' ); ?></th>
						<th style="width: 240px;"><?php esc_html_e( 'Actions', 'axtolab-ai-connector' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $items ) ) : ?>
						<tr><td colspan="8"><?php esc_html_e( 'No changes recorded yet. Mutations made by AI tools will appear here.', 'axtolab-ai-connector' ); ?></td></tr>
					<?php endif; ?>
					<?php foreach ( $items as $row ) : ?>
						<?php
						$id          = (int) $row['id'];
						$rolled_back = ! empty( $row['rolled_back_at'] );
						$is_redo     = ! empty( $row['redo_of_change_id'] );
						?>
						<tr data-mcp-row="<?php echo esc_attr( $id ); ?>">
							<td>#<?php echo esc_html( $id ); ?></td>
							<td><?php echo esc_html( $row['created_at'] ); ?></td>
							<td><code><?php echo esc_html( $row['tool_name'] ); ?></code></td>
							<td><?php echo esc_html( $row['action'] ); ?></td>
							<td>
								<?php echo esc_html( $row['target_type'] ); ?>
								<?php if ( '' !== $row['target_id'] ) : ?>
									<code>#<?php echo esc_html( $row['target_id'] ); ?></code>
								<?php endif; ?>
							</td>
							<td>
								<?php if ( '' !== $row['session_id'] ) : ?>
									<code style="font-size: 11px;"><?php echo esc_html( substr( $row['session_id'], 0, 8 ) . '…' ); ?></code>
								<?php else : ?>
									<span style="color: #999;">—</span>
								<?php endif; ?>
							</td>
							<td>
								<?php if ( $rolled_back ) : ?>
									<span style="color: #b32d2e;"><?php esc_html_e( 'Rolled back', 'axtolab-ai-connector' ); ?></span>
								<?php elseif ( $is_redo ) : ?>
									<span style="color: #00669b;"><?php esc_html_e( 'Redo', 'axtolab-ai-connector' ); ?></span>
								<?php else : ?>
									<span style="color: #00855e;"><?php esc_html_e( 'In effect', 'axtolab-ai-connector' ); ?></span>
								<?php endif; ?>
							</td>
							<td>
								<button type="button" class="button button-small" data-mcp-detail="<?php echo esc_attr( $id ); ?>"><?php esc_html_e( 'View', 'axtolab-ai-connector' ); ?></button>
								<?php if ( ! $rolled_back ) : ?>
									<button type="button" class="button button-small" data-mcp-undo="<?php echo esc_attr( $id ); ?>"><?php esc_html_e( 'Undo', 'axtolab-ai-connector' ); ?></button>
								<?php else : ?>
									<button type="button" class="button button-small" data-mcp-redo="<?php echo esc_attr( $id ); ?>"><?php esc_html_e( 'Redo', 'axtolab-ai-connector' ); ?></button>
								<?php endif; ?>
							</td>
						</tr>
						<tr data-mcp-detail-row="<?php echo esc_attr( $id ); ?>" style="display:none;">
							<td colspan="8" style="background:#f6f7f7; padding: 1em;">
								<div data-mcp-detail-body="<?php echo esc_attr( $id ); ?>">
									<em><?php esc_html_e( 'Loading…', 'axtolab-ai-connector' ); ?></em>
								</div>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>

			<?php
			// Pagination
			$per_page = (int) $filters['per_page'];
			$offset   = (int) $filters['offset'];
			$prev     = max( 0, $offset - $per_page );
			$next     = $offset + $per_page;
			$base_url = remove_query_arg( array( 'offset' ) );
			?>
			<p style="margin-top: 1em;">
				<?php if ( $offset > 0 ) : ?>
					<a class="button" href="<?php echo esc_url( add_query_arg( 'offset', $prev, $base_url ) ); ?>">‹ <?php esc_html_e( 'Previous', 'axtolab-ai-connector' ); ?></a>
				<?php endif; ?>
				<?php if ( $next < $total ) : ?>
					<a class="button" href="<?php echo esc_url( add_query_arg( 'offset', $next, $base_url ) ); ?>"><?php esc_html_e( 'Next', 'axtolab-ai-connector' ); ?> ›</a>
				<?php endif; ?>
			</p>

			<?php
			if ( class_exists( 'Axtolab_Support_Links' ) ) {
				Axtolab_Support_Links::render_footer( 'AI Connector', AXTOLAB_AI_CONNECTOR_VERSION, 'axtolab-ai-connector' );
			}
			?>

			</div>
			<?php
		}

	/**
	 * Return JavaScript for the Logs & Roll Back admin page.
	 *
	 * @return string
	 */
	private function get_logs_inline_script(): string {
		return <<<'JS'
(function () {
    var cfg = window.axtolabAiConnector || {};
    var REST = cfg.restBase || '';
    var NONCE = cfg.nonce || '';

    function escapeHtml(s) {
        return String(s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }

    function api(path, opts) {
        opts = opts || {};
        opts.headers = Object.assign({
            'Content-Type': 'application/json',
            'X-WP-Nonce': NONCE
        }, opts.headers || {});
        opts.credentials = 'same-origin';
        return fetch(REST + path, opts).then(function (r) { return r.json(); });
    }

    document.addEventListener('click', function (event) {
        var btn = event.target.closest('[data-mcp-detail], [data-mcp-undo], [data-mcp-redo], [data-mcp-rollback-session]');
        if (!btn) return;

        var detailId = btn.getAttribute('data-mcp-detail');
        var undoId = btn.getAttribute('data-mcp-undo');
        var redoId = btn.getAttribute('data-mcp-redo');
        var sessionId = btn.getAttribute('data-mcp-rollback-session');

        if (detailId) {
            var detailRow = document.querySelector('tr[data-mcp-detail-row="' + detailId + '"]');
            var body = document.querySelector('[data-mcp-detail-body="' + detailId + '"]');
            if (!detailRow || !body) return;
            if (detailRow.style.display === 'none') {
                detailRow.style.display = '';
                api('/changelog/' + detailId).then(function (resp) {
                    if (resp.success) {
                        var d = resp.data;
                        body.innerHTML =
                            '<div style="display:grid; grid-template-columns:1fr 1fr; gap:1em;">' +
                            '<div><strong>Before</strong><pre style="background:#fff;border:1px solid #ccd0d4;padding:8px;max-height:400px;overflow:auto;">' + escapeHtml(JSON.stringify(d.before, null, 2)) + '</pre></div>' +
                            '<div><strong>After</strong><pre style="background:#fff;border:1px solid #ccd0d4;padding:8px;max-height:400px;overflow:auto;">' + escapeHtml(JSON.stringify(d.after, null, 2)) + '</pre></div>' +
                            '</div>' +
                            (d.note ? '<p style="margin-top:0.5em;"><em>' + escapeHtml(d.note) + '</em></p>' : '');
                    } else {
                        body.textContent = 'Error: ' + (resp.error && resp.error.message);
                    }
                });
            } else {
                detailRow.style.display = 'none';
            }
            return;
        }

        if (undoId || redoId) {
            var id = undoId || redoId;
            var route = undoId ? '/rollback' : '/redo';
            var label = undoId ? 'Rollback' : 'Redo';
            btn.disabled = true;
            api('/changelog/' + id + route, { method: 'POST', body: '{}' }).then(function (resp) {
                if (!resp.success) {
                    alert(label + ' failed: ' + (resp.error && resp.error.message));
                    btn.disabled = false;
                    return null;
                }
                if (!confirm(resp.data.description + '\n\nProceed?')) {
                    btn.disabled = false;
                    return null;
                }
                return api('/changelog/' + id + route, {
                    method: 'POST',
                    body: JSON.stringify({ confirmation_token: resp.data.confirmation_token })
                });
            }).then(function (resp) {
                if (!resp) return;
                if (resp.success) {
                    window.location.reload();
                } else {
                    alert(label + ' failed: ' + (resp.error && resp.error.message));
                    btn.disabled = false;
                }
            });
            return;
        }

        if (sessionId) {
            btn.disabled = true;
            api('/changelog/session/' + encodeURIComponent(sessionId) + '/rollback', { method: 'POST', body: '{}' }).then(function (resp) {
                if (!resp.success) {
                    alert('Failed: ' + (resp.error && resp.error.message));
                    btn.disabled = false;
                    return null;
                }
                var d = resp.data;
                if (d.count === 0) {
                    alert('No pending changes in this session.');
                    btn.disabled = false;
                    return null;
                }
                var msg = 'Roll back ' + d.count + ' change(s) in this session?\n\n';
                (d.plan || []).slice(0, 10).forEach(function (p) { msg += '#' + p.id + ': ' + p.description + '\n'; });
                if (d.plan && d.plan.length > 10) msg += '... and ' + (d.plan.length - 10) + ' more';
                if (!confirm(msg)) {
                    btn.disabled = false;
                    return null;
                }
                return api('/changelog/session/' + encodeURIComponent(sessionId) + '/rollback', {
                    method: 'POST',
                    body: JSON.stringify({ confirmation_token: d.confirmation_token })
                });
            }).then(function (resp) {
                if (!resp) return;
                if (resp.success) {
                    alert('Session rollback: ' + resp.data.succeeded + ' succeeded, ' + resp.data.failed + ' failed.');
                    window.location.reload();
                } else {
                    alert('Failed: ' + (resp.error && resp.error.message));
                    btn.disabled = false;
                }
            });
        }
    });
})();
JS;
	}

	/**
	 * Render the setup checklist card.
	 *
	 * Shows three status items:
	 *   1. Plugin Active
	 *   2. Service Account (auto-created / missing)
	 *   3. AI Client Connected (active app passwords / waiting)
	 *
	 * @param  array $status Result of {@see get_setup_status()}.
	 * @return void
	 */
	private function render_setup_checklist( array $status ): void {
		?>
		<div class="mcp-gateway-card mcp-gateway-checklist">
			<h2><?php esc_html_e( 'Setup Status', 'axtolab-ai-connector' ); ?></h2>

			<ul class="mcp-gateway-status-list">

				<?php // 1. Plugin Active — always green once we're rendering. ?>
				<li class="status-ok">
					<span class="mcp-status-icon dashicons dashicons-yes-alt"></span>
					<span class="mcp-status-label"><?php esc_html_e( 'Plugin Active', 'axtolab-ai-connector' ); ?></span>
					<span class="mcp-status-detail">v<?php echo esc_html( AXTOLAB_AI_CONNECTOR_VERSION ); ?></span>
				</li>

				<?php // 2. Service Account. ?>
				<?php if ( $status['service_account_exists'] && $status['service_account_role'] ) : ?>
					<li class="status-ok">
						<span class="mcp-status-icon dashicons dashicons-yes-alt"></span>
						<span class="mcp-status-label"><?php esc_html_e( 'Service Account', 'axtolab-ai-connector' ); ?></span>
						<span class="mcp-status-detail"><?php esc_html_e( 'axtolab-connector-service', 'axtolab-ai-connector' ); ?></span>
					</li>
				<?php elseif ( $status['service_account_exists'] && ! $status['service_account_role'] ) : ?>
					<li class="status-warn">
						<span class="mcp-status-icon dashicons dashicons-warning"></span>
						<span class="mcp-status-label"><?php esc_html_e( 'Service Account', 'axtolab-ai-connector' ); ?></span>
						<span class="mcp-status-detail"><?php esc_html_e( 'Wrong role assigned', 'axtolab-ai-connector' ); ?></span>
						<button type="button" class="button button-small mcp-ajax-btn"
							data-action="<?php echo esc_attr( self::AJAX_RECREATE_SERVICE_ACCOUNT ); ?>"
							data-loading="<?php esc_attr_e( 'Recreating…', 'axtolab-ai-connector' ); ?>">
							<?php esc_html_e( 'Fix', 'axtolab-ai-connector' ); ?>
						</button>
					</li>
				<?php else : ?>
					<li class="status-error">
						<span class="mcp-status-icon dashicons dashicons-dismiss"></span>
						<span class="mcp-status-label"><?php esc_html_e( 'Service Account', 'axtolab-ai-connector' ); ?></span>
						<span class="mcp-status-detail"><?php esc_html_e( 'Missing', 'axtolab-ai-connector' ); ?></span>
						<button type="button" class="button button-small mcp-ajax-btn"
							data-action="<?php echo esc_attr( self::AJAX_RECREATE_SERVICE_ACCOUNT ); ?>"
							data-loading="<?php esc_attr_e( 'Recreating…', 'axtolab-ai-connector' ); ?>">
							<?php esc_html_e( 'Recreate', 'axtolab-ai-connector' ); ?>
						</button>
					</li>
				<?php endif; ?>

				<?php // 3. AI Client Connected. ?>
				<?php if ( $status['active_app_passwords'] > 0 ) : ?>
					<li class="status-ok">
						<span class="mcp-status-icon dashicons dashicons-yes-alt"></span>
						<span class="mcp-status-label"><?php esc_html_e( 'AI Client Connected', 'axtolab-ai-connector' ); ?></span>
						<span class="mcp-status-detail">
							<?php
							printf(
								/* translators: %d: number of active connections */
								esc_html( _n( '%d active connection', '%d active connections', $status['active_app_passwords'], 'axtolab-ai-connector' ) ),
								(int) $status['active_app_passwords']
							);
							?>
						</span>
					</li>
				<?php else : ?>
					<li class="status-warn">
						<span class="mcp-status-icon dashicons dashicons-clock"></span>
						<span class="mcp-status-label"><?php esc_html_e( 'AI Client Connected', 'axtolab-ai-connector' ); ?></span>
						<span class="mcp-status-detail"><?php esc_html_e( 'Waiting for authorization', 'axtolab-ai-connector' ); ?></span>
					</li>
				<?php endif; ?>

				<?php
				// 4. OAuth Discovery (only when OAuth is enabled).
				// Uses the REST API metadata route — works on every host.
				$oauth_settings = get_option( 'axtolab_ai_connector_settings', array() );
				if ( ! empty( $oauth_settings['oauth_enabled'] ) ) :
					$discovery_url    = rest_url( 'axtolab-ai-connector/v1/oauth/metadata/resource' );
					$discovery_result = wp_remote_get( $discovery_url, array(
						'timeout'   => 5,
						'sslverify' => false,
					) );
					$discovery_ok     = false;

					if ( ! is_wp_error( $discovery_result ) ) {
						$code = wp_remote_retrieve_response_code( $discovery_result );
						$body = wp_remote_retrieve_body( $discovery_result );
						$json = json_decode( $body, true );
						$discovery_ok = ( 200 === $code && is_array( $json ) && ! empty( $json['resource'] ) );
					}

					if ( $discovery_ok ) :
				?>
					<li class="status-ok">
						<span class="mcp-status-icon dashicons dashicons-yes-alt"></span>
						<span class="mcp-status-label"><?php esc_html_e( 'OAuth Discovery', 'axtolab-ai-connector' ); ?></span>
						<span class="mcp-status-detail"><?php esc_html_e( 'Endpoints reachable', 'axtolab-ai-connector' ); ?></span>
					</li>
				<?php else : ?>
					<li class="status-warn">
						<span class="mcp-status-icon dashicons dashicons-warning"></span>
						<span class="mcp-status-label"><?php esc_html_e( 'OAuth Discovery', 'axtolab-ai-connector' ); ?></span>
						<span class="mcp-status-detail"><?php esc_html_e( 'Endpoints not reachable — check REST API accessibility', 'axtolab-ai-connector' ); ?></span>
					</li>
				<?php
					endif;
				endif;

				// 5. Host-root .well-known discovery (RFC 8414/9728 standard paths).
				// MCP clients work fine without this via the WWW-Authenticate
				// resource-metadata flow, but strict OAuth tooling probes these
				// host-root paths. On managed hosts where nginx fronts Apache, the
				// .htaccess rewrite never sees the request — needs nginx-level
				// config. Cached for 6 hours to avoid an outbound HTTP probe on
				// every admin page load.
				if ( ! empty( $oauth_settings['oauth_enabled'] ) ) :
					$wellknown_status = get_transient( 'axtolab_ai_connector_wellknown_status' );
					if ( false === $wellknown_status ) {
						$wellknown_url    = home_url( '/.well-known/oauth-protected-resource' );
						$wellknown_result = wp_remote_get( $wellknown_url, array(
							'timeout'   => 5,
							'sslverify' => false,
						) );
						$wellknown_ok = false;
						if ( ! is_wp_error( $wellknown_result ) ) {
							$code = wp_remote_retrieve_response_code( $wellknown_result );
							$body = wp_remote_retrieve_body( $wellknown_result );
							$json = json_decode( $body, true );
							$wellknown_ok = ( 200 === $code && is_array( $json ) && ! empty( $json['resource'] ) );
						}
						$wellknown_status = $wellknown_ok ? 'ok' : 'blocked';
						set_transient( 'axtolab_ai_connector_wellknown_status', $wellknown_status, 6 * HOUR_IN_SECONDS );
					}
					if ( 'ok' === $wellknown_status ) :
				?>
					<li class="status-ok">
						<span class="mcp-status-icon dashicons dashicons-yes-alt"></span>
						<span class="mcp-status-label"><?php esc_html_e( 'Host-root .well-known discovery', 'axtolab-ai-connector' ); ?></span>
						<span class="mcp-status-detail"><?php esc_html_e( 'RFC 8414/9728 standard paths reachable', 'axtolab-ai-connector' ); ?></span>
					</li>
				<?php else : ?>
					<li class="status-warn">
						<span class="mcp-status-icon dashicons dashicons-info"></span>
						<span class="mcp-status-label"><?php esc_html_e( 'Host-root .well-known discovery', 'axtolab-ai-connector' ); ?></span>
						<span class="mcp-status-detail">
							<?php esc_html_e( 'Blocked by your web server (likely nginx). MCP clients still work via the resource-metadata flow — this is non-blocking. For full RFC 8414/9728 compliance, ask your host to forward /.well-known/oauth-* paths to PHP. See the FAQ in the plugin readme for an nginx config snippet.', 'axtolab-ai-connector' ); ?>
						</span>
					</li>
				<?php
					endif;
				endif;
				?>

			</ul><!-- .mcp-gateway-status-list -->
		</div><!-- .mcp-gateway-checklist -->
		<?php
	}

	/**
	 * Render the "Connect AI Client" card with the connection-token flow and management tools.
	 *
	 * @param  array $status Result of {@see get_setup_status()}.
	 * @return void
	 */
	private function render_connect_claude_section( array $status ): void {
		$service_user_id  = (int) get_option( 'axtolab_ai_connector_service_user_id', 0 );
		$app_pwd_count    = $status['active_app_passwords'];
		$hostname         = wp_parse_url( home_url(), PHP_URL_HOST );
		?>
		<div class="mcp-gateway-card mcp-gateway-connect">
			<h2><?php esc_html_e( 'Connect AI Client', 'axtolab-ai-connector' ); ?></h2>

			<?php // ── Tab navigation ─────────────────────────────────────────────── ?>
			<div class="mcp-tabs">
				<button type="button" class="mcp-tab mcp-tab-active" data-tab="quick-connect">
					<?php esc_html_e( 'Desktop AI Clients', 'axtolab-ai-connector' ); ?>
					<span class="mcp-tab-badge"><?php esc_html_e( 'Recommended', 'axtolab-ai-connector' ); ?></span>
				</button>
				<button type="button" class="mcp-tab" data-tab="remote-access">
					<?php esc_html_e( 'Web Clients (ChatGPT, Claude Web)', 'axtolab-ai-connector' ); ?>
				</button>
				<button type="button" class="mcp-tab" data-tab="image-providers">
					<?php esc_html_e( 'Image Providers', 'axtolab-ai-connector' ); ?>
				</button>
			</div>

			<?php // ── Tab 1: Quick Connect (token-based, no HTTP needed) ─────────── ?>
			<div class="mcp-tab-content mcp-tab-content-active" data-tab="quick-connect">

				<p class="mcp-help-text"><?php esc_html_e( 'Set up Claude Desktop, Claude Code, or compatible local AI clients for the best experience — full filesystem access and local image uploads.', 'axtolab-ai-connector' ); ?></p>

				<?php
				/**
				 * Filter the URL the "Download Extension (.mcpb)" button links to.
				 *
				 * @param string $url Default: published GitHub Release asset.
				 */
				$mcpb_download_url = apply_filters( 'axtolab_ai_connector_mcpb_download_url', AXTOLAB_AI_CONNECTOR_MCPB_URL );
				?>

				<p class="mcp-field-label"><?php esc_html_e( 'Step 1: Install the AI Connector extension', 'axtolab-ai-connector' ); ?></p>
				<p class="mcp-help-text">
					<?php esc_html_e( 'Download the Claude Desktop bundle and drag it into Claude Desktop\'s Extensions panel.', 'axtolab-ai-connector' ); ?>
				</p>
				<p>
					<a href="<?php echo esc_url( $mcpb_download_url ); ?>" class="button button-secondary" id="mcp-download-mcpb-btn" target="_blank" rel="noopener noreferrer">
						<?php esc_html_e( 'Download Extension (.mcpb)', 'axtolab-ai-connector' ); ?>
					</a>
				</p>

				<p class="mcp-field-label"><?php esc_html_e( 'Step 2: Generate a connection token', 'axtolab-ai-connector' ); ?></p>
				<p class="mcp-help-text">
					<?php esc_html_e( 'This creates a one-time token with the credentials your AI client needs to connect to your site.', 'axtolab-ai-connector' ); ?>
				</p>
				<p>
					<button type="button" id="mcp-generate-token-btn" class="button button-primary">
						<?php esc_html_e( 'Generate Connection Token', 'axtolab-ai-connector' ); ?>
					</button>
				</p>
				<p id="mcp-token-message" class="mcp-feedback" aria-live="polite"></p>

				<div id="mcp-token-result" style="display:none;">
					<p class="mcp-field-label"><?php esc_html_e( 'Step 3: Paste the token into the extension settings', 'axtolab-ai-connector' ); ?></p>
					<p class="mcp-help-text">
						<?php esc_html_e( 'In Claude Desktop → Settings → Extensions → Axtolab AI Connector, paste the token.', 'axtolab-ai-connector' ); ?>
					</p>
					<div class="mcp-copy-block">
						<pre class="mcp-code-block" id="mcp-token-prompt"></pre>
						<button type="button" class="button button-small mcp-copy-btn" data-target="mcp-token-prompt">
							<?php esc_html_e( 'Copy', 'axtolab-ai-connector' ); ?>
						</button>
					</div>
					<p class="mcp-help-text">
						<?php esc_html_e( 'Treat this token like a password — do not share it.', 'axtolab-ai-connector' ); ?>
					</p>
				</div>

			</div><!-- tab: quick-connect -->

			<?php // ── Tab 2: Remote Access (bearer token + OAuth) ── ?>
			<?php
			$remote_settings   = get_option( 'axtolab_ai_connector_settings', array() );
			$remote_enabled    = ! empty( $remote_settings['remote_mcp_enabled'] );
			$bearer_info       = Axtolab_AI_Connector_Bearer_Auth::get_token_info();
			$oauth_enabled     = ! empty( $remote_settings['oauth_enabled'] );
			$oauth_info        = Axtolab_AI_Connector_OAuth::get_token_info();
			$mcp_endpoint_url  = rest_url( 'axtolab-ai-connector/v1/mcp' );

			// Capability definitions for rendering checkboxes — use shared class.
			$capability_defs = Axtolab_AI_Connector_Capabilities::group_labels();

			// Default: Standard preset (all except trash_restore).
			$default_caps = Axtolab_AI_Connector_MCP_Transport::DEFAULT_CAPABILITIES;
			$bearer_caps  = $remote_settings['bearer_capabilities'] ?? $default_caps;
			$oauth_caps   = $remote_settings['oauth_capabilities'] ?? $default_caps;
			?>
			<div class="mcp-tab-content" data-tab="remote-access">

				<p class="mcp-help-text"><?php esc_html_e( 'Enable remote access for web-based AI clients like ChatGPT and Claude Web. Connects via OAuth 2.1 — no credentials leave your browser.', 'axtolab-ai-connector' ); ?></p>

				<p class="mcp-field-label"><?php esc_html_e( 'Enable Remote AI Client Access', 'axtolab-ai-connector' ); ?></p>
				<p class="mcp-help-text">
					<?php esc_html_e( 'Allow remote AI clients to connect to your site via the Streamable HTTP MCP endpoint.', 'axtolab-ai-connector' ); ?>
				</p>
				<label class="mcp-input-row">
					<input type="checkbox" id="mcp-toggle-remote" <?php checked( $remote_enabled ); ?> />
					<?php esc_html_e( 'Enable Remote AI Access', 'axtolab-ai-connector' ); ?>
				</label>
				<p id="mcp-toggle-remote-message" class="mcp-feedback" aria-live="polite"></p>

				<hr />

				<!-- ═══ Shared MCP Endpoint URL ═══ -->
				<p class="mcp-field-label"><?php esc_html_e( 'MCP Endpoint URL', 'axtolab-ai-connector' ); ?></p>
				<p class="mcp-help-text">
					<?php esc_html_e( 'All remote connections share this endpoint. Copy it into your MCP-compatible AI client.', 'axtolab-ai-connector' ); ?>
				</p>
				<div class="mcp-copy-block">
					<pre class="mcp-code-block" id="mcp-endpoint-url"><?php echo esc_html( $mcp_endpoint_url ); ?></pre>
					<button type="button" class="button button-small mcp-copy-btn" data-target="mcp-endpoint-url">
						<?php esc_html_e( 'Copy', 'axtolab-ai-connector' ); ?>
					</button>
				</div>

				<hr />

				<!-- ═══ OAuth (primary connection method) ═══ -->
				<h3><?php esc_html_e( 'OAuth Connection (Recommended)', 'axtolab-ai-connector' ); ?></h3>
				<p class="mcp-help-text">
					<?php esc_html_e( 'The standard, secure authentication method. ChatGPT, Claude Web, and other MCP-compatible clients handle the entire OAuth 2.1 flow automatically — you just approve the connection once.', 'axtolab-ai-connector' ); ?>
				</p>

				<label class="mcp-input-row">
					<input type="checkbox" id="mcp-toggle-oauth" <?php checked( $oauth_enabled ); ?> />
					<?php esc_html_e( 'Enable OAuth', 'axtolab-ai-connector' ); ?>
				</label>
				<p id="mcp-oauth-toggle-message" class="mcp-feedback" aria-live="polite"></p>

				<div id="mcp-oauth-status" style="margin-top: 8px;">
					<?php if ( $oauth_info['active'] ) : ?>
							<p class="mcp-help-text" style="color: #00a32a;">
								<?php
								printf(
									/* translators: 1: OAuth client name, 2: token expiration date/time. */
									esc_html__( 'Connected — client: %1$s — expires: %2$s', 'axtolab-ai-connector' ),
									esc_html( $oauth_info['client_name'] ),
									esc_html( $oauth_info['expires_at'] )
							);
							?>
						</p>
						<button type="button" id="mcp-revoke-oauth-btn" class="button button-secondary" style="color: #d63638;">
							<?php esc_html_e( 'Revoke OAuth Token', 'axtolab-ai-connector' ); ?>
						</button>
					<?php else : ?>
						<p class="mcp-help-text">
							<?php esc_html_e( 'No active OAuth connection. Your MCP-compatible AI client will prompt for authorization when it first connects.', 'axtolab-ai-connector' ); ?>
						</p>
					<?php endif; ?>
				</div>

				<!-- OAuth capabilities — collapsible -->
				<details class="mcp-cap-details" id="mcp-oauth-cap-details">
					<summary>
						<?php esc_html_e( 'Capabilities', 'axtolab-ai-connector' ); ?>
						<span class="mcp-cap-badge" id="mcp-oauth-cap-badge"></span>
						<span class="mcp-cap-saved" id="mcp-oauth-saved"><?php esc_html_e( 'Saved!', 'axtolab-ai-connector' ); ?></span>
					</summary>
					<div class="mcp-cap-inner">
						<p class="mcp-help-text" style="margin-top:0;">
							<?php esc_html_e( 'Control what this connection is allowed to do. Changes save automatically.', 'axtolab-ai-connector' ); ?>
						</p>
						<div style="margin-bottom: 10px;">
							<select class="mcp-cap-preset" data-connection="oauth">
								<option value="standard"><?php esc_html_e( 'Standard (Recommended)', 'axtolab-ai-connector' ); ?></option>
								<option value="full_access"><?php esc_html_e( 'Full Access', 'axtolab-ai-connector' ); ?></option>
								<option value="draft_only"><?php esc_html_e( 'Draft Only (No Publish)', 'axtolab-ai-connector' ); ?></option>
								<option value="content_manager"><?php esc_html_e( 'Content Manager', 'axtolab-ai-connector' ); ?></option>
								<option value="read_only"><?php esc_html_e( 'Read Only', 'axtolab-ai-connector' ); ?></option>
								<option value="custom"><?php esc_html_e( 'Custom', 'axtolab-ai-connector' ); ?></option>
							</select>
						</div>
						<?php foreach ( $capability_defs as $cap_key => $cap_label ) : ?>
							<label style="display: block; margin-bottom: 4px;">
								<input type="checkbox"
									class="mcp-cap-checkbox"
									data-connection="oauth"
									data-cap="<?php echo esc_attr( $cap_key ); ?>"
									<?php checked( in_array( $cap_key, $oauth_caps, true ) ); ?>
									<?php disabled( $cap_key === 'read' ); ?>
								/>
								<?php echo esc_html( $cap_label ); ?>
								<?php if ( $cap_key === 'read' ) : ?>
									<em style="color: #888;"><?php esc_html_e( '(always on)', 'axtolab-ai-connector' ); ?></em>
								<?php endif; ?>
								</label>
						<?php endforeach; ?>
					</div>
				</details>

				<!-- OAuth Setup Instructions -->
				<details style="margin-top: 12px;">
					<summary style="cursor: pointer; font-weight: 500;">
						<?php esc_html_e( 'Setup Instructions', 'axtolab-ai-connector' ); ?>
					</summary>
					<ol class="mcp-help-text" style="margin-top: 8px;">
						<li><?php esc_html_e( 'Enable Remote AI Client Access AND OAuth above.', 'axtolab-ai-connector' ); ?></li>
						<li><?php esc_html_e( 'Copy the MCP endpoint URL above.', 'axtolab-ai-connector' ); ?></li>
						<li><?php esc_html_e( 'In ChatGPT, go to Settings → Apps & Connectors → Create.', 'axtolab-ai-connector' ); ?></li>
						<li><?php esc_html_e( 'Paste the URL as the MCP Server URL.', 'axtolab-ai-connector' ); ?></li>
						<li><?php esc_html_e( 'Select "OAuth" as the authentication method.', 'axtolab-ai-connector' ); ?></li>
						<li><?php esc_html_e( 'Click Create — ChatGPT will discover the OAuth endpoints automatically.', 'axtolab-ai-connector' ); ?></li>
						<li><?php esc_html_e( 'When prompted, log into your WordPress admin and click Approve.', 'axtolab-ai-connector' ); ?></li>
						<li><?php esc_html_e( 'ChatGPT will now have access to your WordPress tools.', 'axtolab-ai-connector' ); ?></li>
					</ol>
				</details>

				<hr />

				<!-- ═══ Alternative Connection Methods (collapsed) ═══ -->
				<details class="mcp-cap-details">
					<summary style="font-size: 14px; font-weight: 600;">
						<?php esc_html_e( 'Alternative Connection Methods', 'axtolab-ai-connector' ); ?>
					</summary>
					<div class="mcp-cap-inner">
						<p class="mcp-help-text" style="margin-top: 0;">
							<?php esc_html_e( 'Use these methods if OAuth is not available for your MCP-compatible AI client.', 'axtolab-ai-connector' ); ?>
						</p>

						<!-- ── Bearer Token ── -->
						<h4><?php esc_html_e( 'Bearer Token', 'axtolab-ai-connector' ); ?></h4>
						<p class="mcp-help-text">
							<?php esc_html_e( 'For MCP-compatible AI clients that support Authorization headers (Claude.ai, generic clients).', 'axtolab-ai-connector' ); ?>
						</p>

						<p class="mcp-field-label"><?php esc_html_e( 'Token', 'axtolab-ai-connector' ); ?></p>
						<div id="mcp-bearer-status">
							<?php if ( $bearer_info['exists'] ) : ?>
									<p class="mcp-help-text">
										<?php
										printf(
											/* translators: 1: token prefix, 2: token creation date/time. */
											esc_html__( 'Active — prefix: %1$s… — created: %2$s', 'axtolab-ai-connector' ),
											esc_html( $bearer_info['prefix'] ),
											esc_html( $bearer_info['created_at'] )
									);
									?>
								</p>
							<?php else : ?>
								<p class="mcp-help-text"><?php esc_html_e( 'No bearer token active.', 'axtolab-ai-connector' ); ?></p>
							<?php endif; ?>
						</div>

						<p>
							<button type="button" id="mcp-generate-bearer-btn" class="button button-primary">
								<?php esc_html_e( 'Generate New Token', 'axtolab-ai-connector' ); ?>
							</button>
							<?php if ( $bearer_info['exists'] ) : ?>
								<button type="button" id="mcp-revoke-bearer-btn" class="button button-secondary">
									<?php esc_html_e( 'Revoke Token', 'axtolab-ai-connector' ); ?>
								</button>
							<?php endif; ?>
						</p>
						<p id="mcp-bearer-message" class="mcp-feedback" aria-live="polite"></p>

						<div id="mcp-bearer-token-result" style="display:none;">
							<p class="mcp-field-label" style="color:#dc3232;">
								<?php esc_html_e( 'Copy this token now. It will not be shown again.', 'axtolab-ai-connector' ); ?>
							</p>
							<div class="mcp-copy-block">
								<pre class="mcp-code-block" id="mcp-bearer-token-value"></pre>
								<button type="button" class="button button-small mcp-copy-btn" data-target="mcp-bearer-token-value">
									<?php esc_html_e( 'Copy', 'axtolab-ai-connector' ); ?>
								</button>
							</div>
						</div>

						<!-- Bearer capabilities — collapsible -->
						<details class="mcp-cap-details" id="mcp-bearer-cap-details">
							<summary>
								<?php esc_html_e( 'Capabilities', 'axtolab-ai-connector' ); ?>
								<span class="mcp-cap-badge" id="mcp-bearer-cap-badge"></span>
								<span class="mcp-cap-saved" id="mcp-bearer-saved"><?php esc_html_e( 'Saved!', 'axtolab-ai-connector' ); ?></span>
							</summary>
							<div class="mcp-cap-inner">
								<p class="mcp-help-text" style="margin-top:0;">
									<?php esc_html_e( 'Control what this connection is allowed to do. Changes save automatically.', 'axtolab-ai-connector' ); ?>
								</p>
								<div style="margin-bottom: 10px;">
									<select class="mcp-cap-preset" data-connection="bearer">
										<option value="standard"><?php esc_html_e( 'Standard (Recommended)', 'axtolab-ai-connector' ); ?></option>
										<option value="full_access"><?php esc_html_e( 'Full Access', 'axtolab-ai-connector' ); ?></option>
										<option value="draft_only"><?php esc_html_e( 'Draft Only (No Publish)', 'axtolab-ai-connector' ); ?></option>
										<option value="content_manager"><?php esc_html_e( 'Content Manager', 'axtolab-ai-connector' ); ?></option>
										<option value="read_only"><?php esc_html_e( 'Read Only', 'axtolab-ai-connector' ); ?></option>
										<option value="custom"><?php esc_html_e( 'Custom', 'axtolab-ai-connector' ); ?></option>
									</select>
								</div>
								<?php foreach ( $capability_defs as $cap_key => $cap_label ) : ?>
									<label style="display: block; margin-bottom: 4px;">
										<input type="checkbox"
											class="mcp-cap-checkbox"
											data-connection="bearer"
											data-cap="<?php echo esc_attr( $cap_key ); ?>"
											<?php checked( in_array( $cap_key, $bearer_caps, true ) ); ?>
											<?php disabled( $cap_key === 'read' ); ?>
										/>
										<?php echo esc_html( $cap_label ); ?>
										<?php if ( $cap_key === 'read' ) : ?>
											<em style="color: #888;"><?php esc_html_e( '(always on)', 'axtolab-ai-connector' ); ?></em>
										<?php endif; ?>
									</label>
								<?php endforeach; ?>
							</div>
						</details>

					</div>
				</details>

			</div><!-- tab: remote-access -->

			<?php // ── Tab 4: Image Providers ─────────────────────────────────────── ?>
			<?php
			$img_settings             = Axtolab_AI_Connector_Image_Providers::get_settings();
			$image_generation_allowed = Axtolab_AI_Connector_Free_Gates::is_image_generation_allowed();
			?>
				<div class="mcp-tab-content" data-tab="image-providers">

					<p class="mcp-help-text">
						<?php esc_html_e( 'Configure stock photo and AI image providers. Provider API keys are supplied by the site owner and stored encrypted.', 'axtolab-ai-connector' ); ?>
					</p>

				<form id="mcp-image-providers-form">

					<!-- ═══ Stock Providers ═══ -->
					<h3><?php esc_html_e( 'Stock Photo Providers', 'axtolab-ai-connector' ); ?></h3>

					<!-- Google Imagen -->
					<div class="mcp-provider-block" style="border:1px solid #dcdcde; border-radius:4px; padding:12px 14px; margin-bottom:12px; background:#f9f9f9;">
						<label style="display:block; font-weight:600; margin-bottom:6px;">
							<input type="checkbox" name="google_imagen_enabled" <?php checked( ! empty( $img_settings['google_imagen']['enabled'] ) && $image_generation_allowed ); ?><?php disabled( ! $image_generation_allowed ); ?> />
							<?php esc_html_e( 'Google Imagen', 'axtolab-ai-connector' ); ?>
							</label>
							<p class="mcp-help-text" style="margin-top:0;">
								<?php esc_html_e( 'Generate images with a Google AI Studio API key.', 'axtolab-ai-connector' ); ?>
								<a href="https://aistudio.google.com/apikey" target="_blank"><?php esc_html_e( 'Get API Key', 'axtolab-ai-connector' ); ?></a>
							</p>
						<label style="display:block; margin-bottom:4px; font-size:13px;"><?php esc_html_e( 'API Key', 'axtolab-ai-connector' ); ?></label>
						<input type="password" name="google_imagen_api_key" class="regular-text mcp-api-key-input" placeholder="<?php echo ! empty( $img_settings['google_imagen']['api_key'] ) ? '••••••••' : ''; ?>" autocomplete="off"<?php disabled( ! $image_generation_allowed ); ?> />
						<?php if ( ! empty( $img_settings['google_imagen']['api_key'] ) ) : ?>
							<a href="#" class="mcp-clear-key-btn" data-target="google_imagen_api_key" style="font-size:12px; margin-left:6px;"><?php esc_html_e( 'Clear key', 'axtolab-ai-connector' ); ?></a>
						<?php endif; ?>
						<label style="display:block; margin-top:6px; margin-bottom:4px; font-size:13px;"><?php esc_html_e( 'Model', 'axtolab-ai-connector' ); ?></label>
						<select name="google_imagen_model"<?php disabled( ! $image_generation_allowed ); ?>>
							<option value="imagen-3.0-generate-002" <?php selected( $img_settings['google_imagen']['model'] ?? '', 'imagen-3.0-generate-002' ); ?>>imagen-3.0-generate-002</option>
							<option value="imagen-4.0-generate-001" <?php selected( $img_settings['google_imagen']['model'] ?? '', 'imagen-4.0-generate-001' ); ?>>imagen-4.0-generate-001</option>
							<option value="imagen-4.0-fast-generate-001" <?php selected( $img_settings['google_imagen']['model'] ?? '', 'imagen-4.0-fast-generate-001' ); ?>>imagen-4.0-fast-generate-001</option>
						</select>
						<button type="button" class="button button-small mcp-test-provider-btn" data-provider="google_imagen" style="margin-top:8px;"<?php disabled( ! $image_generation_allowed ); ?>>
							<?php esc_html_e( 'Test Connection', 'axtolab-ai-connector' ); ?>
						</button>
						<span class="mcp-test-result" data-provider="google_imagen"></span>
					</div>

					<!-- Unsplash -->
					<div class="mcp-provider-block" style="border:1px solid #dcdcde; border-radius:4px; padding:12px 14px; margin-bottom:12px; background:#f9f9f9;">
						<label style="display:block; font-weight:600; margin-bottom:6px;">
							<input type="checkbox" name="unsplash_enabled" <?php checked( ! empty( $img_settings['unsplash']['enabled'] ) ); ?> />
							<?php esc_html_e( 'Unsplash (Stock Photos)', 'axtolab-ai-connector' ); ?>
						</label>
						<p class="mcp-help-text" style="margin-top:0;">
							<?php esc_html_e( 'Free with attribution.', 'axtolab-ai-connector' ); ?>
							<a href="https://unsplash.com/developers" target="_blank"><?php esc_html_e( 'Get Access Key', 'axtolab-ai-connector' ); ?></a>
						</p>
						<label style="display:block; margin-bottom:4px; font-size:13px;"><?php esc_html_e( 'Access Key', 'axtolab-ai-connector' ); ?></label>
						<input type="password" name="unsplash_access_key" class="regular-text mcp-api-key-input" placeholder="<?php echo ! empty( $img_settings['unsplash']['access_key'] ) ? '••••••••' : ''; ?>" autocomplete="off" />
						<?php if ( ! empty( $img_settings['unsplash']['access_key'] ) ) : ?>
							<a href="#" class="mcp-clear-key-btn" data-target="unsplash_access_key" style="font-size:12px; margin-left:6px;"><?php esc_html_e( 'Clear key', 'axtolab-ai-connector' ); ?></a>
						<?php endif; ?>
						<button type="button" class="button button-small mcp-test-provider-btn" data-provider="unsplash" style="margin-top:8px;">
							<?php esc_html_e( 'Test Connection', 'axtolab-ai-connector' ); ?>
						</button>
						<span class="mcp-test-result" data-provider="unsplash"></span>
					</div>

					<!-- Pexels -->
					<div class="mcp-provider-block" style="border:1px solid #dcdcde; border-radius:4px; padding:12px 14px; margin-bottom:12px; background:#f9f9f9;">
						<label style="display:block; font-weight:600; margin-bottom:6px;">
							<input type="checkbox" name="pexels_enabled" <?php checked( ! empty( $img_settings['pexels']['enabled'] ) ); ?> />
							<?php esc_html_e( 'Pexels (Stock Photos)', 'axtolab-ai-connector' ); ?>
						</label>
						<p class="mcp-help-text" style="margin-top:0;">
							<?php esc_html_e( 'Free with attribution.', 'axtolab-ai-connector' ); ?>
							<a href="https://www.pexels.com/api/" target="_blank"><?php esc_html_e( 'Get API Key', 'axtolab-ai-connector' ); ?></a>
						</p>
						<label style="display:block; margin-bottom:4px; font-size:13px;"><?php esc_html_e( 'API Key', 'axtolab-ai-connector' ); ?></label>
						<input type="password" name="pexels_api_key" class="regular-text mcp-api-key-input" placeholder="<?php echo ! empty( $img_settings['pexels']['api_key'] ) ? '••••••••' : ''; ?>" autocomplete="off" />
						<?php if ( ! empty( $img_settings['pexels']['api_key'] ) ) : ?>
							<a href="#" class="mcp-clear-key-btn" data-target="pexels_api_key" style="font-size:12px; margin-left:6px;"><?php esc_html_e( 'Clear key', 'axtolab-ai-connector' ); ?></a>
						<?php endif; ?>
						<button type="button" class="button button-small mcp-test-provider-btn" data-provider="pexels" style="margin-top:8px;">
							<?php esc_html_e( 'Test Connection', 'axtolab-ai-connector' ); ?>
						</button>
						<span class="mcp-test-result" data-provider="pexels"></span>
					</div>

					<hr />

						<!-- ═══ AI Generation Providers ═══ -->
						<h3><?php esc_html_e( 'AI Generation Providers', 'axtolab-ai-connector' ); ?></h3>

					<!-- OpenAI GPT Image -->
					<div class="mcp-provider-block" style="border:1px solid #dcdcde; border-radius:4px; padding:12px 14px; margin-bottom:12px; background:#f9f9f9;">
						<label style="display:block; font-weight:600; margin-bottom:6px;">
							<input type="checkbox" name="openai_enabled" <?php checked( ! empty( $img_settings['openai']['enabled'] ) && $image_generation_allowed ); ?><?php disabled( ! $image_generation_allowed ); ?> />
							<?php esc_html_e( 'OpenAI GPT Image', 'axtolab-ai-connector' ); ?>
							</label>
							<p class="mcp-help-text" style="margin-top:0;">
								<?php esc_html_e( 'Generate images with an OpenAI API key.', 'axtolab-ai-connector' ); ?>
								<a href="https://platform.openai.com/api-keys" target="_blank"><?php esc_html_e( 'Get API Key', 'axtolab-ai-connector' ); ?></a>
							</p>
						<label style="display:block; margin-bottom:4px; font-size:13px;"><?php esc_html_e( 'API Key', 'axtolab-ai-connector' ); ?></label>
						<input type="password" name="openai_api_key" class="regular-text mcp-api-key-input" placeholder="<?php echo ! empty( $img_settings['openai']['api_key'] ) ? '••••••••' : ''; ?>" autocomplete="off"<?php disabled( ! $image_generation_allowed ); ?> />
						<?php if ( ! empty( $img_settings['openai']['api_key'] ) ) : ?>
							<a href="#" class="mcp-clear-key-btn" data-target="openai_api_key" style="font-size:12px; margin-left:6px;"><?php esc_html_e( 'Clear key', 'axtolab-ai-connector' ); ?></a>
						<?php endif; ?>
						<label style="display:block; margin-top:6px; margin-bottom:4px; font-size:13px;"><?php esc_html_e( 'Model', 'axtolab-ai-connector' ); ?></label>
						<select name="openai_model"<?php disabled( ! $image_generation_allowed ); ?>>
							<option value="gpt-image-1" <?php selected( $img_settings['openai']['model'] ?? '', 'gpt-image-1' ); ?>>gpt-image-1</option>
							<option value="gpt-image-1-mini" <?php selected( $img_settings['openai']['model'] ?? '', 'gpt-image-1-mini' ); ?>>gpt-image-1-mini</option>
							<option value="gpt-image-1.5" <?php selected( $img_settings['openai']['model'] ?? '', 'gpt-image-1.5' ); ?>>gpt-image-1.5</option>
						</select>
						<label style="display:block; margin-top:6px; margin-bottom:4px; font-size:13px;"><?php esc_html_e( 'Quality', 'axtolab-ai-connector' ); ?></label>
						<select name="openai_quality"<?php disabled( ! $image_generation_allowed ); ?>>
							<option value="low" <?php selected( $img_settings['openai']['quality'] ?? '', 'low' ); ?>><?php esc_html_e( 'Low', 'axtolab-ai-connector' ); ?></option>
							<option value="medium" <?php selected( $img_settings['openai']['quality'] ?? '', 'medium' ); ?>><?php esc_html_e( 'Medium (Default)', 'axtolab-ai-connector' ); ?></option>
							<option value="high" <?php selected( $img_settings['openai']['quality'] ?? '', 'high' ); ?>><?php esc_html_e( 'High', 'axtolab-ai-connector' ); ?></option>
						</select>
						<button type="button" class="button button-small mcp-test-provider-btn" data-provider="openai" style="margin-top:8px;"<?php disabled( ! $image_generation_allowed ); ?>>
							<?php esc_html_e( 'Test Connection', 'axtolab-ai-connector' ); ?>
						</button>
						<span class="mcp-test-result" data-provider="openai"></span>
					</div>

					<p>
						<button type="button" id="mcp-save-image-providers-btn" class="button button-primary">
							<?php esc_html_e( 'Save Settings', 'axtolab-ai-connector' ); ?>
						</button>
					</p>
					<p id="mcp-image-providers-message" class="mcp-feedback" aria-live="polite"></p>
				</form>

				</div><!-- tab: image-providers -->

				<hr />

			<?php $this->render_connected_clients_section(); ?>

		</div><!-- .mcp-gateway-connect -->
		<?php
	}

	/**
	 * Render the "Connected Clients" section with per-connection management.
	 *
	 * Shows a table of all active connections with rename/revoke actions,
	 * plus a "Revoke All" link as an emergency kill switch.
	 *
	 * @return void
	 */
		private function render_connected_clients_section(): void {
		$connections   = Axtolab_AI_Connector_Connections::get_all_connections();
		$count         = count( $connections );
		$settings      = get_option( 'axtolab_ai_connector_settings', array() );
		$review_email  = ! empty( $settings['review_notification_email'] )
			? $settings['review_notification_email']
			: '';
		?>
		<h3><?php esc_html_e( 'Connected Clients', 'axtolab-ai-connector' ); ?></h3>

		<div class="mcp-review-email-row" style="margin-bottom: 16px; display: flex; align-items: center; gap: 8px; flex-wrap: wrap;">
			<label for="mcp-review-email" style="font-weight: 500; white-space: nowrap;">
				<?php esc_html_e( 'Review notification email:', 'axtolab-ai-connector' ); ?>
			</label>
			<input type="email" id="mcp-review-email" class="regular-text"
				value="<?php echo esc_attr( $review_email ); ?>"
				placeholder="<?php echo esc_attr( get_option( 'admin_email' ) ); ?>"
				style="max-width: 280px;" />
			<button type="button" id="mcp-save-review-email-btn" class="button button-small">
				<?php esc_html_e( 'Save', 'axtolab-ai-connector' ); ?>
			</button>
			<span id="mcp-review-email-saved" style="color:#00a32a; font-size:13px; display:none;">
				<?php esc_html_e( 'Saved', 'axtolab-ai-connector' ); ?>
			</span>
			<p class="mcp-help-text" style="width:100%; margin:2px 0 0;">
				<?php esc_html_e( 'Where to send "ready for review" emails from Draft Only connections. Defaults to the WordPress admin email if left blank.', 'axtolab-ai-connector' ); ?>
			</p>
		</div>

		<?php if ( 0 === $count ) : ?>
			<p class="mcp-help-text">
				<?php esc_html_e( 'No clients are currently connected. Use the setup tabs above to connect your first client.', 'axtolab-ai-connector' ); ?>
			</p>
		<?php else : ?>
			<table class="mcp-connections-table" id="mcp-connections-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Label', 'axtolab-ai-connector' ); ?></th>
						<th><?php esc_html_e( 'Client Type', 'axtolab-ai-connector' ); ?></th>
						<th><?php esc_html_e( 'Auth Method', 'axtolab-ai-connector' ); ?></th>
						<th><?php esc_html_e( 'Created', 'axtolab-ai-connector' ); ?></th>
						<th><?php esc_html_e( 'Last Active', 'axtolab-ai-connector' ); ?></th>
						<th><?php esc_html_e( 'Actions', 'axtolab-ai-connector' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $connections as $conn ) : ?>
						<tr class="mcp-connection-row" data-id="<?php echo esc_attr( $conn['id'] ); ?>">
							<td class="mcp-conn-label" title="<?php echo esc_attr( $conn['label'] ); ?>">
								<span class="mcp-conn-label-text"><?php echo esc_html( $conn['label'] ); ?></span>
								<input type="text" class="mcp-conn-label-input" value="<?php echo esc_attr( $conn['label'] ); ?>" maxlength="200" style="display:none;" />
							</td>
							<td>
								<span class="mcp-conn-type-badge mcp-conn-type-<?php echo esc_attr( $conn['client_type'] ); ?>">
									<?php echo esc_html( Axtolab_AI_Connector_Connections::client_type_label( $conn['client_type'] ) ); ?>
								</span>
							</td>
							<td><?php echo esc_html( Axtolab_AI_Connector_Connections::auth_method_label( $conn['auth_method'] ) ); ?></td>
							<td><?php echo $conn['created'] ? esc_html( gmdate( 'M j', $conn['created'] ) ) : '&mdash;'; ?></td>
							<td><?php echo esc_html( Axtolab_AI_Connector_Connections::relative_time( $conn['last_active'] ) ); ?></td>
							<td class="mcp-conn-actions">
								<button type="button" class="button button-small mcp-conn-perms-btn">Permissions</button>
								<button type="button" class="button button-small mcp-conn-rename-btn">
									<?php esc_html_e( 'Rename', 'axtolab-ai-connector' ); ?>
								</button>
								<button type="button" class="button button-small mcp-conn-revoke-btn">
									<?php esc_html_e( 'Revoke', 'axtolab-ai-connector' ); ?>
								</button>
							</td>
						</tr>
						<tr class="mcp-connection-caps-row" data-id="<?php echo esc_attr( $conn['id'] ); ?>" style="display:none;">
							<td colspan="6">
								<div class="mcp-conn-caps-editor">
									<div style="margin-bottom: 8px;">
										<label><?php esc_html_e( 'Preset:', 'axtolab-ai-connector' ); ?>
										<select class="mcp-conn-cap-preset" data-connection="<?php echo esc_attr( $conn['id'] ); ?>">
											<?php foreach ( Axtolab_AI_Connector_Capabilities::preset_labels() as $key => $label ) : ?>
												<option value="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></option>
											<?php endforeach; ?>
										</select>
										</label>
									</div>
									<div class="mcp-conn-caps-checkboxes">
										<?php foreach ( Axtolab_AI_Connector_Capabilities::group_labels() as $cap_key => $cap_label ) : ?>
											<label class="mcp-conn-cap-label">
												<input type="checkbox"
													class="mcp-conn-cap-checkbox"
													data-connection="<?php echo esc_attr( $conn['id'] ); ?>"
													data-cap="<?php echo esc_attr( $cap_key ); ?>"
													<?php checked( in_array( $cap_key, $conn['capabilities'], true ) ); ?>
													<?php disabled( 'read' === $cap_key ); ?>
												/>
												<?php echo esc_html( $cap_label ); ?>
												<?php if ( 'read' === $cap_key ) : ?>
													<em class="mcp-conn-cap-note"><?php esc_html_e( '(always on)', 'axtolab-ai-connector' ); ?></em>
												<?php endif; ?>
											</label>
										<?php endforeach; ?>
									</div>
									<span class="mcp-conn-caps-saved" style="opacity:0; transition: opacity 0.3s;"><?php esc_html_e( 'Saved', 'axtolab-ai-connector' ); ?></span>

									<?php
									$wp_authors           = get_users( array(
										'role__in' => array( 'administrator', 'editor', 'author', 'contributor' ),
										'orderby'  => 'display_name',
										'order'    => 'ASC',
										'fields'   => array( 'ID', 'display_name' ),
									) );
									$conn_allowed_authors = isset( $conn['allowed_authors'] ) ? $conn['allowed_authors'] : null;
									?>
									<div style="margin-top:12px; border-top:1px solid #e0e0e0; padding-top:10px;">
										<p style="margin:0 0 6px; font-weight:500; font-size:13px;">
											<?php esc_html_e( 'Allowed Authors', 'axtolab-ai-connector' ); ?>
											<em class="mcp-conn-cap-note" style="font-weight:400;">
												&mdash; <?php esc_html_e( 'leave all unchecked to allow any author', 'axtolab-ai-connector' ); ?>
											</em>
										</p>
										<div class="mcp-conn-caps-checkboxes">
											<?php foreach ( $wp_authors as $wp_user ) : ?>
												<label class="mcp-conn-cap-label">
													<input type="checkbox"
														class="mcp-conn-author-checkbox"
														data-connection="<?php echo esc_attr( $conn['id'] ); ?>"
														data-author-id="<?php echo esc_attr( $wp_user->ID ); ?>"
														<?php checked( is_array( $conn_allowed_authors ) && in_array( (int) $wp_user->ID, $conn_allowed_authors, true ) ); ?>
													/>
													<?php echo esc_html( $wp_user->display_name ); ?>
												</label>
											<?php endforeach; ?>
										</div>
									</div>
								</div>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>

			<p class="mcp-connections-footer">
				<span class="mcp-conn-count" id="mcp-conn-count">
					<?php
					printf(
							/* translators: %d: number of active connections */
							esc_html( _n( '%d active connection', '%d active connections', $count, 'axtolab-ai-connector' ) ),
							(int) $count
						);
						?>
					</span>
				&mdash;
				<a href="#" id="mcp-revoke-all-link" class="mcp-danger-link">
					<?php esc_html_e( 'Revoke All Connections', 'axtolab-ai-connector' ); ?>
				</a>
			</p>
			<p id="mcp-revoke-message" class="mcp-feedback" aria-live="polite"></p>
		<?php endif; ?>
		<?php
	}

	/**
	 * Render the informational "How it works" section.
	 *
	 * @return void
	 */
	/**
	 * Advanced write gates: permalink_writes_enabled + options_writes_enabled.
	 *
	 * Both are off by default. The plugin's REST handlers refuse the relevant
	 * writes (POST /permalink-structure, POST /options/{key}) unless the
	 * corresponding gate is on, even for admins. Surfaced here so admins
	 * can flip the gate on for a specific automation, run it, and flip it
	 * back off — no DB-poking required.
	 */
	private function render_advanced_writes_section(): void {
		$settings           = get_option( 'axtolab_ai_connector_settings', array() );
		$permalink_enabled  = ! empty( $settings['permalink_writes_enabled'] );
		$options_enabled    = ! empty( $settings['options_writes_enabled'] );
		?>
		<div class="mcp-gateway-card mcp-gateway-advanced-writes">
			<h2><?php esc_html_e( 'Advanced Write Gates', 'axtolab-ai-connector' ); ?></h2>
			<p class="description" style="max-width: 760px;">
				<?php esc_html_e( 'AI agents can read site-wide settings by default but cannot change permalinks or arbitrary options unless you explicitly enable it here. Turn a gate on only while running a specific automation, then turn it off again.', 'axtolab-ai-connector' ); ?>
			</p>

			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Permalink writes', 'axtolab-ai-connector' ); ?></th>
					<td>
						<label class="mcp-gateway-toggle">
							<input type="checkbox"
								id="mcp-gateway-permalink-writes"
								data-mcp-advanced-write="permalink_writes_enabled"
								<?php checked( $permalink_enabled ); ?> />
							<span><?php esc_html_e( 'Allow AI agents to update the WordPress permalink structure', 'axtolab-ai-connector' ); ?></span>
						</label>
						<p class="description"><?php esc_html_e( 'Off by default. Required for tools like wp_update_permalink_structure. Capability + admin-toggle + allowlist all gate the write.', 'axtolab-ai-connector' ); ?></p>
						<span class="mcp-gateway-toggle-status" data-for="permalink_writes_enabled" style="margin-left:6px; color:#666; font-size:13px;"></span>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Options API writes', 'axtolab-ai-connector' ); ?></th>
					<td>
						<label class="mcp-gateway-toggle">
							<input type="checkbox"
								id="mcp-gateway-options-writes"
								data-mcp-advanced-write="options_writes_enabled"
								<?php checked( $options_enabled ); ?> />
							<span><?php esc_html_e( 'Allow AI agents to update WordPress options on the writable allowlist', 'axtolab-ai-connector' ); ?></span>
						</label>
						<p class="description"><?php esc_html_e( 'Off by default. Sensitive keys (siteurl, home, license_*, secrets) remain blocked even when this is on.', 'axtolab-ai-connector' ); ?></p>
						<span class="mcp-gateway-toggle-status" data-for="options_writes_enabled" style="margin-left:6px; color:#666; font-size:13px;"></span>
					</td>
				</tr>
			</table>
		</div>
		<?php
	}

	/**
	 * AJAX handler for `AJAX_TOGGLE_ADVANCED_WRITE`. Persists either
	 * `permalink_writes_enabled` or `options_writes_enabled` on the
	 * axtolab_ai_connector_settings option. Refuses any other key.
	 */
	public function ajax_toggle_advanced_write(): void {
		check_ajax_referer( self::MENU_SLUG . '-ajax', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'axtolab-ai-connector' ) ), 403 );
		}

		$key = isset( $_POST['key'] ) ? sanitize_key( wp_unslash( (string) $_POST['key'] ) ) : '';
		$allowed = array( 'permalink_writes_enabled', 'options_writes_enabled' );
		if ( ! in_array( $key, $allowed, true ) ) {
			wp_send_json_error( array( 'message' => __( 'Unknown setting.', 'axtolab-ai-connector' ) ), 400 );
		}

		$enabled  = ! empty( $_POST['enabled'] ) && '0' !== sanitize_text_field( wp_unslash( (string) $_POST['enabled'] ) );
		$settings = get_option( 'axtolab_ai_connector_settings', array() );
		if ( ! is_array( $settings ) ) {
			$settings = array();
		}
		$settings[ $key ] = $enabled;
		update_option( 'axtolab_ai_connector_settings', $settings );

		wp_send_json_success( array(
			'key'     => $key,
			'enabled' => $enabled,
		) );
	}

	private function render_info_section(): void {
		?>
		<div class="mcp-gateway-card mcp-gateway-info">
			<h2><?php esc_html_e( 'How It Works', 'axtolab-ai-connector' ); ?></h2>
			<h3><?php esc_html_e( 'Desktop AI Clients (Recommended)', 'axtolab-ai-connector' ); ?></h3>
			<ol>
				<li><?php esc_html_e( 'Click "Generate Connection Token" to create a one-time token.', 'axtolab-ai-connector' ); ?></li>
				<li><?php esc_html_e( 'Paste the token into the Axtolab AI Connector extension settings in Claude Desktop or another compatible client.', 'axtolab-ai-connector' ); ?></li>
				<li><?php esc_html_e( 'The client saves the connection locally — restart it and you\'re ready to go!', 'axtolab-ai-connector' ); ?></li>
			</ol>
			<p>
				<?php
				printf(
					/* translators: %s: REST API base URL */
					esc_html__( 'REST API base: %s', 'axtolab-ai-connector' ),
					'<code>' . esc_html( rest_url( 'axtolab-ai-connector/v1' ) ) . '</code>'
				);
				?>
			</p>
		</div>
		<?php
	}

	// ── Status helper ─────────────────────────────────────────────────────────

	/**
	 * Collect the current plugin setup status.
	 *
	 * Returns an associative array with the following keys:
	 *
	 *   `service_account_exists`  bool  — option + user record both present
	 *   `service_account_role`    bool  — user has the axtolab_ai_connector_editor role
	 *   `active_app_passwords`    int   — number of application passwords on the service account
	 *
	 * @return array{
	 *     service_account_exists: bool,
	 *     service_account_role:   bool,
	 *     active_app_passwords:   int,
	 * }
	 */
	public function get_setup_status(): array {
		$service_user_id = (int) get_option( 'axtolab_ai_connector_service_user_id', 0 );
		$service_user    = $service_user_id ? get_user_by( 'id', $service_user_id ) : false;

		$account_exists = ( $service_user instanceof WP_User );
		$account_role   = false;

		if ( $account_exists ) {
			$account_role = in_array( 'axtolab_ai_connector_editor', (array) $service_user->roles, true );
		}

		// Count all connections (app passwords + OAuth).
		$connections = Axtolab_AI_Connector_Connections::get_all_connections();

		return array(
			'service_account_exists' => $account_exists,
			'service_account_role'   => $account_role,
			'active_app_passwords'   => count( $connections ),
		);
	}

	// ── AJAX handlers ─────────────────────────────────────────────────────────

	/**
	 * AJAX: Revoke all connections (app passwords + OAuth tokens).
	 *
	 * Nonce: `{MENU_SLUG}-ajax`.
	 *
	 * @return void Sends JSON and exits.
	 */
	public function ajax_revoke_all_passwords(): void {
		check_ajax_referer( self::MENU_SLUG . '-ajax', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error(
				array( 'message' => __( 'Permission denied.', 'axtolab-ai-connector' ) ),
				403
			);
		}

		$count = Axtolab_AI_Connector_Connections::revoke_all();

		wp_send_json_success(
			array(
				'message' => sprintf(
					/* translators: %d: number of connections revoked */
					_n( '%d connection revoked.', '%d connections revoked.', $count, 'axtolab-ai-connector' ),
					$count
				),
				'count'   => 0,
			)
		);
	}

	/**
	 * AJAX: Rename a single connection.
	 *
	 * Expects POST params: connection_id, new_label.
	 * Nonce: `{MENU_SLUG}-ajax`.
	 *
	 * @return void Sends JSON and exits.
	 */
	public function ajax_rename_connection(): void {
		check_ajax_referer( self::MENU_SLUG . '-ajax', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error(
				array( 'message' => __( 'Permission denied.', 'axtolab-ai-connector' ) ),
				403
			);
		}

		$connection_id = isset( $_POST['connection_id'] ) ? sanitize_text_field( wp_unslash( $_POST['connection_id'] ) ) : '';
		$new_label     = isset( $_POST['new_label'] ) ? sanitize_text_field( wp_unslash( $_POST['new_label'] ) ) : '';

		if ( empty( $connection_id ) ) {
			wp_send_json_error(
				array( 'message' => __( 'Missing connection ID.', 'axtolab-ai-connector' ) ),
				400
			);
		}

		$stored_label = Axtolab_AI_Connector_Connections::rename( $connection_id, $new_label );

		if ( is_wp_error( $stored_label ) ) {
			wp_send_json_error(
				array( 'message' => $stored_label->get_error_message() ),
				400
			);
		}

		wp_send_json_success(
			array(
				'message' => __( 'Connection renamed.', 'axtolab-ai-connector' ),
				'label'   => $stored_label,
			)
		);
	}

	/**
	 * AJAX: Revoke a single connection.
	 *
	 * Expects POST param: connection_id.
	 * Nonce: `{MENU_SLUG}-ajax`.
	 *
	 * @return void Sends JSON and exits.
	 */
	public function ajax_revoke_connection(): void {
		check_ajax_referer( self::MENU_SLUG . '-ajax', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error(
				array( 'message' => __( 'Permission denied.', 'axtolab-ai-connector' ) ),
				403
			);
		}

		$connection_id = isset( $_POST['connection_id'] ) ? sanitize_text_field( wp_unslash( $_POST['connection_id'] ) ) : '';

		if ( empty( $connection_id ) ) {
			wp_send_json_error(
				array( 'message' => __( 'Missing connection ID.', 'axtolab-ai-connector' ) ),
				400
			);
		}

		$result = Axtolab_AI_Connector_Connections::revoke( $connection_id );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error(
				array( 'message' => $result->get_error_message() ),
				500
			);
		}

		$remaining = count( Axtolab_AI_Connector_Connections::get_all_connections() );

		wp_send_json_success(
			array(
				'message' => __( 'Connection revoked.', 'axtolab-ai-connector' ),
				'count'   => $remaining,
			)
		);
	}

	/**
	 * AJAX: Update per-connection capability groups.
	 *
	 * Expects POST params: connection_id, capabilities[].
	 * Nonce: `{MENU_SLUG}-ajax`.
	 *
	 * @return void Sends JSON and exits.
	 */
	public function ajax_update_connection_caps(): void {
		check_ajax_referer( self::MENU_SLUG . '-ajax', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'axtolab-ai-connector' ) ), 403 );
		}
		$connection_id = isset( $_POST['connection_id'] ) ? sanitize_text_field( wp_unslash( $_POST['connection_id'] ) ) : '';
		$capabilities  = isset( $_POST['capabilities'] ) ? array_map( 'sanitize_key', (array) $_POST['capabilities'] ) : array();
		if ( empty( $connection_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Missing connection ID.', 'axtolab-ai-connector' ) ), 400 );
		}
		$result = Axtolab_AI_Connector_Connections::set_capabilities( $connection_id, $capabilities );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ), 400 );
		}
		wp_send_json_success( array( 'message' => __( 'Permissions updated.', 'axtolab-ai-connector' ) ) );
	}

	/**
	 * AJAX: Save the review notification email address.
	 *
	 * Expects POST param: email (string).
	 * Nonce: `{MENU_SLUG}-ajax`.
	 *
	 * @return void Sends JSON and exits.
	 */
	public function ajax_save_review_email(): void {
		check_ajax_referer( self::MENU_SLUG . '-ajax', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'axtolab-ai-connector' ) ), 403 );
		}
		$email    = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
		$settings = get_option( 'axtolab_ai_connector_settings', array() );

		if ( '' === $email ) {
			// Empty = revert to WordPress admin email default.
			unset( $settings['review_notification_email'] );
		} else {
			if ( ! is_email( $email ) ) {
				wp_send_json_error( array( 'message' => __( 'Invalid email address.', 'axtolab-ai-connector' ) ), 400 );
			}
			$settings['review_notification_email'] = $email;
		}

		update_option( 'axtolab_ai_connector_settings', $settings );
		wp_send_json_success( array( 'message' => __( 'Review email saved.', 'axtolab-ai-connector' ) ) );
	}

	/**
	 * AJAX: Update per-connection allowed author IDs.
	 *
	 * Expects POST params: connection_id, author_ids[] (int, may be empty).
	 * Nonce: `{MENU_SLUG}-ajax`.
	 *
	 * @return void Sends JSON and exits.
	 */
	public function ajax_update_connection_authors(): void {
		check_ajax_referer( self::MENU_SLUG . '-ajax', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'axtolab-ai-connector' ) ), 403 );
		}
		$connection_id = isset( $_POST['connection_id'] ) ? sanitize_text_field( wp_unslash( $_POST['connection_id'] ) ) : '';
		$author_ids    = isset( $_POST['author_ids'] ) ? array_map( 'absint', (array) $_POST['author_ids'] ) : array();
		if ( empty( $connection_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Missing connection ID.', 'axtolab-ai-connector' ) ), 400 );
		}
		$result = Axtolab_AI_Connector_Connections::set_allowed_authors( $connection_id, $author_ids );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ), 400 );
		}
		wp_send_json_success( array( 'message' => __( 'Author restriction updated.', 'axtolab-ai-connector' ) ) );
	}

	/**
	 * AJAX: Generate a connection token.
	 *
	 * Creates an Application Password and encodes all credentials into a
	 * self-contained token that can be decoded offline by the MCP CLI.
	 *
	 * Nonce: `{MENU_SLUG}-ajax`.
	 *
	 * @return void Sends JSON and exits.
	 */
	public function ajax_generate_token(): void {
		check_ajax_referer( self::MENU_SLUG . '-ajax', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error(
				array( 'message' => __( 'Permission denied.', 'axtolab-ai-connector' ) ),
				403
			);
		}

		$token = Axtolab_AI_Connector_Token_Auth::generate_connection_token();

		if ( is_wp_error( $token ) ) {
			$error_data = $token->get_error_data();
			$status     = is_array( $error_data ) && isset( $error_data['status'] ) ? (int) $error_data['status'] : 500;
			wp_send_json_error(
				array( 'message' => $token->get_error_message() ),
				$status
			);
		}

		wp_send_json_success(
			array( 'token' => $token )
		);
	}

	/**
	 * AJAX: Recreate the service account and role.
	 *
	 * Runs the same idempotent provisioning logic as the activation hook.
	 *
	 * Nonce: `{MENU_SLUG}-ajax`.
	 *
	 * @return void Sends JSON and exits.
	 */
	public function ajax_recreate_service_account(): void {
		check_ajax_referer( self::MENU_SLUG . '-ajax', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error(
				array( 'message' => __( 'Permission denied.', 'axtolab-ai-connector' ) ),
				403
			);
		}

		$result = axtolab_ai_connector_provision_service_account();

		if ( is_wp_error( $result ) ) {
			wp_send_json_error(
				array( 'message' => $result->get_error_message() ),
				500
			);
		}

		wp_send_json_success(
			array( 'message' => __( 'Service account recreated successfully.', 'axtolab-ai-connector' ) )
		);
	}

	// ── Remote Access AJAX handlers ──────────────────────────────────────────────

	/**
	 * AJAX: Toggle the Remote MCP feature on or off.
	 *
	 * Nonce: `{MENU_SLUG}-ajax`.
	 *
	 * @return void Sends JSON and exits.
	 */
	public function ajax_toggle_remote(): void {
		check_ajax_referer( self::MENU_SLUG . '-ajax', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'axtolab-ai-connector' ) ), 403 );
		}

		$enabled = ! empty( $_POST['enabled'] ) && '0' !== sanitize_text_field( wp_unslash( (string) $_POST['enabled'] ) );
		if ( $enabled ) {
			$multisite_allowed = Axtolab_AI_Connector_Free_Gates::check_multisite_allowed();
			if ( is_wp_error( $multisite_allowed ) ) {
				wp_send_json_error( array( 'message' => $multisite_allowed->get_error_message() ), 403 );
			}
		}

		$settings = get_option( 'axtolab_ai_connector_settings', array() );
		$settings['remote_mcp_enabled'] = $enabled;
		update_option( 'axtolab_ai_connector_settings', $settings );

		wp_send_json_success( array( 'enabled' => $enabled ) );
	}

	/**
	 * AJAX: Generate a new bearer token for Remote MCP access.
	 *
	 * The raw token is returned once and never stored in plaintext.
	 *
	 * Nonce: `{MENU_SLUG}-ajax`.
	 *
	 * @return void Sends JSON and exits.
	 */
	public function ajax_generate_bearer(): void {
		check_ajax_referer( self::MENU_SLUG . '-ajax', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'axtolab-ai-connector' ) ), 403 );
		}

		$token = Axtolab_AI_Connector_Bearer_Auth::generate_token();
		if ( is_wp_error( $token ) ) {
			wp_send_json_error( array( 'message' => $token->get_error_message() ), 403 );
		}

		$info = Axtolab_AI_Connector_Bearer_Auth::get_token_info();

		wp_send_json_success( array( 'token' => $token, 'info' => $info ) );
	}

	/**
	 * AJAX: Revoke the current bearer token for Remote MCP access.
	 *
	 * Nonce: `{MENU_SLUG}-ajax`.
	 *
	 * @return void Sends JSON and exits.
	 */
	public function ajax_revoke_bearer(): void {
		check_ajax_referer( self::MENU_SLUG . '-ajax', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'axtolab-ai-connector' ) ), 403 );
		}

		Axtolab_AI_Connector_Bearer_Auth::revoke_token();

		wp_send_json_success( array( 'message' => __( 'Bearer token revoked.', 'axtolab-ai-connector' ) ) );
	}

	/**
	 * AJAX: Toggle OAuth on or off.
	 *
	 * Nonce: `{MENU_SLUG}-ajax`.
	 *
	 * @return void Sends JSON and exits.
	 */
	public function ajax_toggle_oauth(): void {
		check_ajax_referer( self::MENU_SLUG . '-ajax', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'axtolab-ai-connector' ) ), 403 );
		}

		$enabled  = ! empty( $_POST['enabled'] );
		$settings = get_option( 'axtolab_ai_connector_settings', array() );
		$settings['oauth_enabled'] = $enabled;
		update_option( 'axtolab_ai_connector_settings', $settings );

		// Write .htaccess rules when enabling (for plugins already active before OAuth was added).
		if ( $enabled ) {
			axtolab_ai_connector_write_oauth_htaccess_rules();
		}

		wp_send_json_success( array( 'enabled' => $enabled ) );
	}

	/**
	 * AJAX: Revoke the OAuth access token.
	 *
	 * Nonce: `{MENU_SLUG}-ajax`.
	 *
	 * @return void Sends JSON and exits.
	 */
	public function ajax_revoke_oauth(): void {
		check_ajax_referer( self::MENU_SLUG . '-ajax', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'axtolab-ai-connector' ) ), 403 );
		}

		Axtolab_AI_Connector_OAuth::revoke_token();

		wp_send_json_success( array( 'message' => __( 'OAuth token revoked.', 'axtolab-ai-connector' ) ) );
	}

	/**
	 * AJAX: Save capability checkboxes for a connection.
	 *
	 * Nonce: `{MENU_SLUG}-ajax`.
	 *
	 * @return void Sends JSON and exits.
	 */
	public function ajax_save_capabilities(): void {
		check_ajax_referer( self::MENU_SLUG . '-ajax', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'axtolab-ai-connector' ) ), 403 );
		}

		$connection = isset( $_POST['connection'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['connection'] ) ) : '';
		if ( ! in_array( $connection, array( 'bearer', 'oauth' ), true ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid connection type.', 'axtolab-ai-connector' ) ) );
		}

		// Parse capabilities array from POST.
		$raw_caps   = isset( $_POST['capabilities'] ) ? array_map( 'sanitize_text_field', (array) wp_unslash( $_POST['capabilities'] ) ) : array();
		$valid_caps = array( 'read', 'create_edit', 'publish', 'trash_restore', 'media_manage', 'taxonomy', 'authors', 'seo', 'image' );
		$caps       = array_values( array_intersect( $raw_caps, $valid_caps ) );

		// Ensure 'read' is always included.
		if ( ! in_array( 'read', $caps, true ) ) {
			$caps[] = 'read';
		}

		if ( 'oauth' === $connection ) {
			$settings_key = 'oauth_capabilities';
		} else {
			$settings_key = 'bearer_capabilities';
		}
		$settings     = get_option( 'axtolab_ai_connector_settings', array() );
		$settings[ $settings_key ] = $caps;
		update_option( 'axtolab_ai_connector_settings', $settings );

		wp_send_json_success( array( 'capabilities' => $caps ) );
	}

	// ── Image Providers AJAX handlers ────────────────────────────────────────

	/**
	 * AJAX: Save image provider settings.
	 *
	 * Nonce: `{MENU_SLUG}-ajax`.
	 *
	 * @return void Sends JSON and exits.
	 */
	public function ajax_save_image_providers(): void {
		check_ajax_referer( self::MENU_SLUG . '-ajax', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'axtolab-ai-connector' ) ), 403 );
		}

		$image_generation_allowed = Axtolab_AI_Connector_Free_Gates::is_image_generation_allowed();
		$current_providers        = Axtolab_AI_Connector_Image_Providers::get_settings();

		$providers = array(
			'google_imagen' => $image_generation_allowed ? array(
				'enabled' => ! empty( $_POST['google_imagen_enabled'] ),
				'api_key' => sanitize_text_field( wp_unslash( $_POST['google_imagen_api_key'] ?? '' ) ),
				'model'   => sanitize_text_field( wp_unslash( $_POST['google_imagen_model'] ?? 'imagen-3.0-generate-002' ) ),
			) : array(
				'enabled' => ! empty( $current_providers['google_imagen']['enabled'] ),
			),
			'unsplash'      => array(
				'enabled'    => ! empty( $_POST['unsplash_enabled'] ),
				'access_key' => sanitize_text_field( wp_unslash( $_POST['unsplash_access_key'] ?? '' ) ),
			),
			'pexels'        => array(
				'enabled' => ! empty( $_POST['pexels_enabled'] ),
				'api_key' => sanitize_text_field( wp_unslash( $_POST['pexels_api_key'] ?? '' ) ),
			),
			'openai'        => $image_generation_allowed ? array(
				'enabled' => ! empty( $_POST['openai_enabled'] ),
				'api_key' => sanitize_text_field( wp_unslash( $_POST['openai_api_key'] ?? '' ) ),
				'model'   => sanitize_text_field( wp_unslash( $_POST['openai_model'] ?? 'gpt-image-1' ) ),
				'quality' => sanitize_text_field( wp_unslash( $_POST['openai_quality'] ?? 'medium' ) ),
			) : array(
				'enabled' => ! empty( $current_providers['openai']['enabled'] ),
			),
		);

		$result = Axtolab_AI_Connector_Image_Providers::save_settings( $providers );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ), 500 );
		}

		wp_send_json_success( array( 'message' => __( 'Image provider settings saved.', 'axtolab-ai-connector' ) ) );
	}

	/**
	 * AJAX: Test an image provider connection.
	 *
	 * Nonce: `{MENU_SLUG}-ajax`.
	 *
	 * @return void Sends JSON and exits.
	 */
	public function ajax_test_image_provider(): void {
		check_ajax_referer( self::MENU_SLUG . '-ajax', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'axtolab-ai-connector' ) ), 403 );
		}

		$provider = sanitize_text_field( wp_unslash( $_POST['provider'] ?? '' ) );
		$api_key  = sanitize_text_field( wp_unslash( $_POST['api_key'] ?? '' ) );

		if ( empty( $provider ) ) {
			wp_send_json_error( array( 'message' => __( 'No provider specified.', 'axtolab-ai-connector' ) ) );
		}

			if ( in_array( $provider, array( 'google_imagen', 'openai' ), true ) && ! Axtolab_AI_Connector_Free_Gates::is_image_generation_allowed() ) {
				wp_send_json_error( array( 'message' => __( 'AI image generation is disabled on this site.', 'axtolab-ai-connector' ) ), 403 );
			}

		if ( empty( $api_key ) ) {
			$result = Axtolab_AI_Connector_Image_Providers::test_connection( $provider );
		} else {
			$result = Axtolab_AI_Connector_Image_Providers::test_connection_with_key( $provider, $api_key );
		}

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success( array( 'message' => __( 'Connection successful!', 'axtolab-ai-connector' ) ) );
	}

	// ── Inline assets ─────────────────────────────────────────────────────────

	/**
	 * Return the inline CSS string for the admin page.
	 *
	 * Keeping styles inline avoids registering an extra HTTP request for a
	 * plugin with a minimal admin UI.
	 *
	 * @return string
	 */
	private function get_inline_styles(): string {
		return '
.mcp-gateway-wrap { max-width: 1100px; }
.mcp-gateway-tagline { font-size: 14px; color: #666; margin-bottom: 24px; }
.mcp-gateway-columns { display: flex; gap: 24px; flex-wrap: wrap; align-items: flex-start; }
.mcp-gateway-card {
	background: #fff;
	border: 1px solid #c3c4c7;
	border-radius: 4px;
	padding: 24px;
	margin-bottom: 24px;
}
.mcp-gateway-checklist { flex: 0 0 300px; }
.mcp-gateway-connect   { flex: 1 1 400px; }
.mcp-gateway-info      { clear: both; }
.mcp-gateway-card h2   { margin-top: 0; font-size: 16px; }
.mcp-gateway-card h3   { font-size: 14px; margin-bottom: 8px; }

/* Status list */
.mcp-gateway-status-list { list-style: none; margin: 0; padding: 0; }
.mcp-gateway-status-list li {
	display: flex;
	align-items: center;
	gap: 8px;
	padding: 10px 0;
	border-bottom: 1px solid #f0f0f1;
	font-size: 13px;
}
.mcp-gateway-status-list li:last-child { border-bottom: none; }
.mcp-status-icon { font-size: 18px; flex-shrink: 0; }
.mcp-status-label { font-weight: 600; flex: 1; }
.mcp-status-detail { color: #666; font-size: 12px; }
.status-ok   .mcp-status-icon { color: #46b450; }
.status-warn .mcp-status-icon { color: #f0b849; }
.status-error .mcp-status-icon { color: #dc3232; }

/* Connect AI Client form */
.mcp-field-label { display: block; font-weight: 600; margin-bottom: 6px; font-size: 13px; }
.mcp-input-row { display: flex; gap: 8px; align-items: center; margin-bottom: 8px; }
.mcp-feedback { min-height: 20px; font-size: 13px; margin: 4px 0 0; }
.mcp-feedback.is-success { color: #46b450; }
.mcp-feedback.is-error   { color: #dc3232; }

.mcp-copy-block { position: relative; margin-bottom: 16px; }
.mcp-copy-btn { position: absolute; top: 8px; right: 8px; }
.mcp-code-block {
	background: #1d2327;
	color: #a8c7fa;
	padding: 10px 14px;
	border-radius: 3px;
	font-size: 13px;
	margin: 0 0 16px;
	user-select: all;
	white-space: pre-wrap;
	word-break: break-word;
}

/* Tabs */
.mcp-tabs { display: flex; gap: 0; border-bottom: 1px solid #c3c4c7; margin-bottom: 16px; }
.mcp-tab {
	background: none; border: none; border-bottom: 2px solid transparent;
	padding: 8px 16px; font-size: 13px; font-weight: 600; color: #50575e;
	cursor: pointer; margin-bottom: -1px;
}
.mcp-tab:hover { color: #1d2327; }
.mcp-tab-badge {
    font-size: 10px;
    background: #2271b1;
    color: #fff;
    padding: 1px 6px;
    border-radius: 3px;
    margin-left: 6px;
    vertical-align: middle;
    font-weight: 400;
}
.mcp-tab.mcp-tab-active { color: #2271b1; border-bottom-color: #2271b1; }
.mcp-tab-content { display: none; }
.mcp-tab-content.mcp-tab-content-active { display: block; }

/* Help text */
.mcp-help-text { font-size: 12px; color: #666; margin: 4px 0 12px; }

/* Info table */
.mcp-info-table { border-collapse: collapse; width: 100%; font-size: 13px; margin-bottom: 12px; }
.mcp-info-table th { text-align: left; padding: 6px 12px 6px 0; width: 160px; color: #555; font-weight: 600; }
.mcp-info-table td { padding: 6px 0; }
.mcp-info-table tr { border-bottom: 1px solid #f0f0f1; }
.mcp-info-table tr:last-child { border-bottom: none; }

/* Capability sections */
.mcp-cap-details {
    margin-top: 12px;
    border: 1px solid #dcdcde;
    border-radius: 4px;
    background: #f9f9f9;
}
.mcp-cap-details summary {
    cursor: pointer;
    padding: 10px 14px;
    font-weight: 500;
    user-select: none;
    display: flex;
    align-items: center;
    gap: 8px;
}
.mcp-cap-details summary:hover {
    background: #f0f0f1;
}
.mcp-cap-details[open] summary {
    border-bottom: 1px solid #dcdcde;
}
.mcp-cap-details .mcp-cap-inner {
    padding: 12px 14px;
}
.mcp-cap-badge {
    display: inline-block;
    padding: 2px 8px;
    border-radius: 3px;
    font-size: 12px;
    font-weight: 600;
    background: #dcdcde;
    color: #50575e;
}
.mcp-cap-badge--standard { background: #d4edda; color: #155724; }
.mcp-cap-badge--full     { background: #cce5ff; color: #004085; }
.mcp-cap-badge--creator  { background: #fff3cd; color: #856404; }
.mcp-cap-badge--readonly { background: #f8d7da; color: #721c24; }
.mcp-cap-badge--custom   { background: #e2e3e5; color: #383d41; }
.mcp-cap-saved {
    color: #00a32a;
    font-size: 13px;
    margin-left: 8px;
    opacity: 0;
    transition: opacity 0.3s;
}
.mcp-cap-saved.visible { opacity: 1; }

/* Connections table */
.mcp-connections-table { border-collapse: collapse; width: 100%; font-size: 13px; margin-bottom: 8px; }
.mcp-connections-table th {
	text-align: left; padding: 8px 10px; font-weight: 600; color: #1d2327;
	border-bottom: 2px solid #c3c4c7; font-size: 12px; text-transform: uppercase; letter-spacing: 0.3px;
}
.mcp-connections-table td { padding: 10px; border-bottom: 1px solid #f0f0f1; vertical-align: middle; }
.mcp-connections-table tbody tr:hover { background: #f9f9f9; }
.mcp-connections-table tbody tr.mcp-conn-fading { opacity: 0; transition: opacity 0.4s ease; }
.mcp-conn-label { max-width: 260px; }
.mcp-conn-label-text { display: inline-block; max-width: 240px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; font-weight: 500; }
.mcp-conn-label-input { width: 220px; font-size: 13px; padding: 3px 6px; }
.mcp-conn-type-badge {
	display: inline-block; padding: 2px 8px; border-radius: 3px;
	font-size: 11px; font-weight: 600; background: #e2e3e5; color: #383d41;
}
.mcp-conn-type-claude_desktop { background: #d4edda; color: #155724; }
.mcp-conn-type-cowork         { background: #cce5ff; color: #004085; }
.mcp-conn-type-chatgpt        { background: #fff3cd; color: #856404; }
.mcp-conn-type-claude_web     { background: #d1ecf1; color: #0c5460; }
.mcp-conn-type-cli            { background: #e2e3e5; color: #383d41; }
.mcp-conn-actions { white-space: nowrap; }
.mcp-conn-actions .button { margin-right: 4px; }
.mcp-conn-actions .mcp-conn-revoke-btn { color: #d63638; border-color: #d63638; }
.mcp-conn-actions .mcp-conn-revoke-btn:hover { background: #d63638; color: #fff; }
.mcp-conn-actions .mcp-conn-save-btn { color: #00a32a; border-color: #00a32a; }
.mcp-conn-actions .mcp-conn-cancel-btn { color: #666; }
.mcp-connections-footer { font-size: 12px; color: #666; margin-top: 4px; }
.mcp-conn-count { font-weight: 500; }
.mcp-danger-link { color: #d63638; text-decoration: none; }
.mcp-danger-link:hover { color: #a02020; text-decoration: underline; }
.mcp-connection-caps-row td { padding: 12px 16px; background: #f9f9f9; border-bottom: 1px solid #e0e0e0; }
.mcp-conn-caps-editor { max-width: 420px; }
.mcp-conn-caps-checkboxes { display: grid; grid-template-columns: 1fr 1fr; gap: 4px 16px; margin-bottom: 8px; }
.mcp-conn-cap-label { display: flex; align-items: center; gap: 4px; font-size: 13px; }
.mcp-conn-cap-note { color: #888; font-size: 12px; }
.mcp-conn-caps-saved { color: #00a32a; font-size: 13px; font-weight: 500; }
.mcp-conn-perms-btn { margin-right: 4px; }
';
	}

	/**
	 * Return the inline JavaScript string for the admin page.
	 *
	 * Handles:
	 *   - Revoke All button: calls wp-ajax → deletes all app passwords
	 *   - Recreate button(s): calls wp-ajax → provisions service account
	 *
	 * @return string
	 */
	private function get_inline_script(): string {
		return <<<'JS'
(function ($) {
    'use strict';

    var cfg = window.axtolabAiConnector || {};

    // ── Shared AJAX helper ────────────────────────────────────────────────────
    function doAjax(action, extraData, successCb, errorCb) {
        var data = $.extend({ action: action, nonce: cfg.ajaxNonce }, extraData);
        $.post(cfg.ajaxUrl, data)
            .done(function (res) {
                if (res && res.success) {
                    if (successCb) successCb(res.data);
                } else {
                    var msg = (res && res.data && res.data.message) ? res.data.message : 'An error occurred.';
                    if (errorCb) errorCb(msg);
                }
            })
            .fail(function () {
                if (errorCb) errorCb('Request failed. Please try again.');
            });
    }

    // ══════════════════════════════════════════════════════════════════════════
    // Connection Manager — Rename, Revoke, Revoke All
    // ══════════════════════════════════════════════════════════════════════════

    // ── Rename: click Rename → inline input ───────────────────────────────
    $(document).on('click', '.mcp-conn-rename-btn', function () {
        var $row    = $(this).closest('.mcp-connection-row');
        var $text   = $row.find('.mcp-conn-label-text');
        var $input  = $row.find('.mcp-conn-label-input');
        var $actions = $row.find('.mcp-conn-actions');

        $text.hide();
        $input.val($text.text().trim()).show().focus().select();

        // Replace action buttons with Save/Cancel.
        $actions.data('original-html', $actions.html());
        $actions.html(
            '<button type="button" class="button button-small mcp-conn-save-btn">Save</button> ' +
            '<button type="button" class="button button-small mcp-conn-cancel-btn">Cancel</button>'
        );
    });

    // ── Rename: Save ──────────────────────────────────────────────────────
    $(document).on('click', '.mcp-conn-save-btn', function () {
        var $row    = $(this).closest('.mcp-connection-row');
        var connId  = $row.data('id');
        var $text   = $row.find('.mcp-conn-label-text');
        var $input  = $row.find('.mcp-conn-label-input');
        var $actions = $row.find('.mcp-conn-actions');
        var newLabel = $input.val().trim();

        if (!newLabel) {
            $input.css('border-color', '#d63638').focus();
            return;
        }

        var $saveBtn = $row.find('.mcp-conn-save-btn');
        $saveBtn.prop('disabled', true).text('Saving…');

        doAjax(
            cfg.actions.renameConnection,
            { connection_id: connId, new_label: newLabel },
            function (data) {
                $text.text(data.label || newLabel).attr('title', data.label || newLabel);
                $input.hide();
                $text.show();
                $actions.html($actions.data('original-html'));
            },
            function (errMsg) {
                $saveBtn.prop('disabled', false).text('Save');
                $input.css('border-color', '#d63638');
                alert(errMsg);
            }
        );
    });

    // ── Rename: Cancel ────────────────────────────────────────────────────
    $(document).on('click', '.mcp-conn-cancel-btn', function () {
        var $row    = $(this).closest('.mcp-connection-row');
        var $text   = $row.find('.mcp-conn-label-text');
        var $input  = $row.find('.mcp-conn-label-input');
        var $actions = $row.find('.mcp-conn-actions');

        $input.hide().css('border-color', '');
        $text.show();
        $actions.html($actions.data('original-html'));
    });

    // ── Rename: Enter key saves, Escape cancels ──────────────────────────
    $(document).on('keydown', '.mcp-conn-label-input', function (e) {
        if (e.key === 'Enter') {
            e.preventDefault();
            $(this).closest('.mcp-connection-row').find('.mcp-conn-save-btn').trigger('click');
        } else if (e.key === 'Escape') {
            $(this).closest('.mcp-connection-row').find('.mcp-conn-cancel-btn').trigger('click');
        }
    });

    // ── Revoke single connection ──────────────────────────────────────────
    $(document).on('click', '.mcp-conn-revoke-btn', function () {
        var $row   = $(this).closest('.mcp-connection-row');
        var connId = $row.data('id');
        var label  = $row.find('.mcp-conn-label-text').text().trim();

        if (!window.confirm('Revoke access for "' + label + '"? This client will need to reconnect.')) return;

        var $btn = $(this);
        $btn.prop('disabled', true).text('Revoking…');

        doAjax(
            cfg.actions.revokeConnection,
            { connection_id: connId },
            function (data) {
                $row.addClass('mcp-conn-fading');
                setTimeout(function () {
                    $row.remove();
                    // Update count or show empty state.
                    var remaining = $('#mcp-connections-table tbody tr').length;
                    if (remaining === 0) {
                        window.location.reload();
                    } else {
                        $('#mcp-conn-count').text(remaining + ' active connection' + (remaining === 1 ? '' : 's'));
                    }
                }, 400);
            },
            function (errMsg) {
                $btn.prop('disabled', false).text('Revoke');
                alert(errMsg);
            }
        );
    });

    // ── Per-connection author restriction ─────────────────────────────────
    var connAuthorTimers = {};

    function connGetCheckedAuthors(connId) {
        var ids = [];
        $('[data-connection="' + connId + '"].mcp-conn-author-checkbox:checked').each(function() {
            ids.push(parseInt($(this).data('author-id'), 10));
        });
        return ids;
    }

    function connAutoSaveAuthors(connId) {
        clearTimeout(connAuthorTimers[connId]);
        connAuthorTimers[connId] = setTimeout(function() {
            doAjax(
                cfg.actions.updateConnectionAuthors,
                { connection_id: connId, author_ids: connGetCheckedAuthors(connId) },
                function() {
                    var $s = $('[data-id="' + connId + '"].mcp-connection-caps-row .mcp-conn-caps-saved');
                    $s.css('opacity', 1);
                    setTimeout(function() { $s.css('opacity', 0); }, 1500);
                },
                function() {}
            );
        }, 500);
    }

    $(document).on('change', '.mcp-conn-author-checkbox', function() {
        connAutoSaveAuthors($(this).data('connection'));
    });

    // ── Review notification email ─────────────────────────────────────────
    $(document).on('click', '#mcp-save-review-email-btn', function() {
        var email = $('#mcp-review-email').val().trim();
        doAjax(
            cfg.actions.saveReviewEmail,
            { email: email },
            function() {
                var $s = $('#mcp-review-email-saved');
                $s.show();
                setTimeout(function() { $s.fadeOut(); }, 2000);
            },
            function(msg) { alert(msg || 'Failed to save email.'); }
        );
    });

    // ── Revoke All link ───────────────────────────────────────────────────
    $(document).on('click', '#mcp-revoke-all-link', function (e) {
        e.preventDefault();
        if (!window.confirm(cfg.strings.confirmRevoke)) return;

        var $link = $(this);
        var $msg  = $('#mcp-revoke-message');

        $link.text('Revoking…');
        $msg.text('').removeClass('is-success is-error');

        doAjax(
            'axtolab_ai_connector_revoke_all_passwords',
            {},
            function (data) {
                $msg.text(data.message || 'All connections revoked.').removeClass('is-error').addClass('is-success');
                setTimeout(function () { window.location.reload(); }, 1500);
            },
            function (errMsg) {
                $link.text('Revoke All Connections');
                $msg.text(errMsg).removeClass('is-success').addClass('is-error');
            }
        );
    });

    // ── Per-connection permissions ─────────────────────────────────────────
    var connCapPresets = {
        full_access:     ['read','create_edit','publish','trash_restore','media_manage','taxonomy','authors','seo','image','upload_portal'],
        standard:        ['read','create_edit','publish','media_manage','taxonomy','authors','seo','image','upload_portal'],
        draft_only:      ['read','create_edit','media_manage','taxonomy','seo','image','upload_portal'],
        read_only:       ['read'],
        content_manager: ['read','create_edit','publish','media_manage','taxonomy','authors','seo'],
        media_manager:   ['read','media_manage'],
        seo_specialist:  ['read','seo']
    };
    var connCapTimers = {};

    function connGetChecked(connId) {
        var caps = [];
        $('[data-connection="' + connId + '"].mcp-conn-cap-checkbox:checked').each(function() {
            caps.push($(this).data('cap'));
        });
        return caps;
    }

    function connDetectPreset(connId) {
        var current = connGetChecked(connId).sort();
        var matched = 'custom';
        $.each(connCapPresets, function(name, caps) {
            var sorted = caps.slice().sort();
            if (sorted.length === current.length && sorted.every(function(c, i) { return c === current[i]; })) {
                matched = name;
                return false;
            }
        });
        return matched;
    }

    function connUpdatePresetUI(connId) {
        $('[data-connection="' + connId + '"].mcp-conn-cap-preset').val(connDetectPreset(connId));
    }

    function connAutoSave(connId) {
        clearTimeout(connCapTimers[connId]);
        connCapTimers[connId] = setTimeout(function() {
            doAjax(
                cfg.actions.updateConnectionCaps,
                { connection_id: connId, capabilities: connGetChecked(connId) },
                function() {
                    var $s = $('[data-id="' + connId + '"].mcp-connection-caps-row .mcp-conn-caps-saved');
                    $s.css('opacity', 1);
                    setTimeout(function() { $s.css('opacity', 0); }, 1500);
                },
                function() {}
            );
        }, 500);
    }

    $(document).on('click', '.mcp-conn-perms-btn', function() {
        var $row = $(this).closest('.mcp-connection-row');
        var connId = $row.data('id');
        $('.mcp-connection-caps-row[data-id="' + connId + '"]').toggle();
    });

    $(document).on('change', '.mcp-conn-cap-preset', function() {
        var connId = $(this).data('connection');
        var preset = $(this).val();
        if (preset === 'custom') return;
        var caps = connCapPresets[preset] || [];
        $('[data-connection="' + connId + '"].mcp-conn-cap-checkbox').each(function() {
            if ($(this).data('cap') === 'read') return;
            $(this).prop('checked', caps.indexOf($(this).data('cap')) !== -1);
        });
        connAutoSave(connId);
    });

    $(document).on('change', '.mcp-conn-cap-checkbox', function() {
        var connId = $(this).data('connection');
        connUpdatePresetUI(connId);
        connAutoSave(connId);
    });

    // Init presets on load
    $('.mcp-connection-caps-row').each(function() {
        connUpdatePresetUI($(this).data('id'));
    });

    // ── Tab switching ─────────────────────────────────────────────────────────
    $(document).on('click', '.mcp-tab', function () {
        var tab = $(this).data('tab');
        $('.mcp-tab').removeClass('mcp-tab-active');
        $(this).addClass('mcp-tab-active');
        $('.mcp-tab-content').removeClass('mcp-tab-content-active');
        $('.mcp-tab-content[data-tab="' + tab + '"]').addClass('mcp-tab-content-active');
    });

    // ── Generate Token button ────────────────────────────────────────────────
    $(document).on('click', '#mcp-generate-token-btn', function () {
        var $btn = $(this);
        var $msg = $('#mcp-token-message');
        var $result = $('#mcp-token-result');

        $btn.prop('disabled', true).text('Generating…');
        $msg.text('').removeClass('is-success is-error');
        $result.hide();

        doAjax(
            'axtolab_ai_connector_generate_token',
            {},
			function (data) {
				$btn.prop('disabled', false).text('Generate Connection Token');
				var prompt;
				if (cfg.mcpbAvailable) {
					prompt = data.token;
					$msg.text('Token generated! Paste it into the Axtolab AI Connector extension settings in Claude Desktop.').removeClass('is-error').addClass('is-success');
				} else {
					prompt = data.token;
					$msg.text('Token generated! Copy the token below and paste it into your compatible AI client.').removeClass('is-error').addClass('is-success');
				}
				$('#mcp-token-prompt').text(prompt);
				$result.show();
			},
            function (errMsg) {
                $btn.prop('disabled', false).text('Generate Connection Token');
                $msg.text(errMsg).removeClass('is-success').addClass('is-error');
            }
        );
    });

    // ── Copy button ──────────────────────────────────────────────────────────
    $(document).on('click', '.mcp-copy-btn', function () {
        var $btn = $(this);
        var targetId = $btn.data('target');
        var text = document.getElementById(targetId) ? document.getElementById(targetId).textContent : '';
        navigator.clipboard.writeText(text).then(function () {
            var original = $btn.text();
            $btn.text('Copied!');
            setTimeout(function () { $btn.text(original); }, 2000);
        });
    });

    // ── Generic AJAX buttons (recreate, fix role, etc.) ──────────────────────
    $(document).on('click', '.mcp-ajax-btn', function () {
        var $btn    = $(this);
        var action  = $btn.data('action');
        var loading = $btn.data('loading') || 'Working…';

        if (!action) return;

        var originalText = $btn.text();
        $btn.prop('disabled', true).text(loading);

        doAjax(
            action,
            {},
            function (data) {
                $btn.prop('disabled', false).text(originalText);
                // Brief reload to refresh all status indicators.
                setTimeout(function () { window.location.reload(); }, 1500);
            },
            function (errMsg) {
                $btn.prop('disabled', false).text(originalText);
                alert(errMsg);
            }
        );
    });

    // ── Toggle Remote MCP checkbox ───────────────────────────────────────────
    $(document).on('change', '#mcp-toggle-remote', function () {
        var $checkbox = $(this);
        var $msg      = $('#mcp-toggle-remote-message');
        var enabled   = $checkbox.is(':checked') ? 1 : 0;

        $msg.text('Saving…').removeClass('is-success is-error');

        doAjax(
            'axtolab_ai_connector_toggle_remote',
            { enabled: enabled },
            function (data) {
                var label = data.enabled ? 'Remote AI access enabled.' : 'Remote AI access disabled.';
                $msg.text(label).removeClass('is-error').addClass('is-success');
            },
            function (errMsg) {
                // Revert the checkbox on failure.
                $checkbox.prop('checked', !$checkbox.is(':checked'));
                $msg.text(errMsg).removeClass('is-success').addClass('is-error');
            }
        );
    });

    // ── Generate Bearer Token button ─────────────────────────────────────────
    $(document).on('click', '#mcp-generate-bearer-btn', function () {
        var $btn    = $(this);
        var $msg    = $('#mcp-bearer-message');
        var $result = $('#mcp-bearer-token-result');

        $btn.prop('disabled', true).text('Generating…');
        $msg.text('').removeClass('is-success is-error');
        $result.hide();

        doAjax(
            'axtolab_ai_connector_generate_bearer',
            {},
            function (data) {
                $btn.prop('disabled', false).text('Generate New Token');
                $('#mcp-bearer-token-value').text(data.token);
                $result.show();
                // Update status display.
                var info = data.info;
                var statusHtml = '<p class="mcp-help-text">Active — prefix: ' +
                    $('<span>').text(info.prefix).html() +
                    '… — created: ' +
                    $('<span>').text(info.created_at).html() +
                    '</p>';
                $('#mcp-bearer-status').html(statusHtml);
                // Show Revoke button if it was hidden.
                if (!$('#mcp-revoke-bearer-btn').length) {
                    $btn.after(' <button type="button" id="mcp-revoke-bearer-btn" class="button button-secondary">Revoke Token</button>');
                }
                $msg.text('Token generated! Copy it now — it will not be shown again.').removeClass('is-error').addClass('is-success');
            },
            function (errMsg) {
                $btn.prop('disabled', false).text('Generate New Token');
                $msg.text(errMsg).removeClass('is-success').addClass('is-error');
            }
        );
    });

    // ── Revoke Bearer Token button ───────────────────────────────────────────
    $(document).on('click', '#mcp-revoke-bearer-btn', function () {
        if (!window.confirm('Revoke the bearer token? Remote clients will lose access immediately.')) return;

        var $btn    = $(this);
        var $msg    = $('#mcp-bearer-message');
        var $result = $('#mcp-bearer-token-result');

        $btn.prop('disabled', true).text('Revoking…');
        $msg.text('').removeClass('is-success is-error');

        doAjax(
            'axtolab_ai_connector_revoke_bearer',
            {},
            function (data) {
                $btn.remove();
                $result.hide();
                $('#mcp-bearer-status').html('<p class="mcp-help-text">No bearer token active.</p>');
                $msg.text(data.message || 'Bearer token revoked.').removeClass('is-error').addClass('is-success');
            },
            function (errMsg) {
                $btn.prop('disabled', false).text('Revoke Token');
                $msg.text(errMsg).removeClass('is-success').addClass('is-error');
            }
        );
    });

    // ── OAuth Toggle ─────────────────────────────────────────────────────────
    $(document).on('change', '#mcp-toggle-oauth', function () {
        var $cb  = $(this);
        var $msg = $('#mcp-oauth-toggle-message');
        var enabled = $cb.is(':checked') ? 1 : 0;

        $msg.text('Saving…').removeClass('is-success is-error');

        doAjax(
            'axtolab_ai_connector_toggle_oauth',
            { enabled: enabled },
            function (data) {
                var label = data.enabled ? 'OAuth enabled.' : 'OAuth disabled.';
                $msg.text(label).removeClass('is-error').addClass('is-success');
            },
            function (errMsg) {
                $cb.prop('checked', !$cb.is(':checked'));
                $msg.text(errMsg).removeClass('is-success').addClass('is-error');
            }
        );
    });

    // ── Revoke OAuth Token ───────────────────────────────────────────────────
    $(document).on('click', '#mcp-revoke-oauth-btn', function () {
        if (!window.confirm('Revoke OAuth token? ChatGPT will need to re-authorize.')) return;

        doAjax(
            'axtolab_ai_connector_revoke_oauth',
            {},
            function () {
                window.location.reload();
            },
            function (errMsg) {
                alert(errMsg);
            }
        );
    });

    // ══════════════════════════════════════════════════════════════════════════
    // Capability Management (auto-save, presets, badges)
    // ══════════════════════════════════════════════════════════════════════════

    var capPresets = {
        standard:        ['read', 'create_edit', 'publish', 'media_manage', 'taxonomy', 'authors', 'seo', 'image', 'upload_portal'],
        full_access:     ['read', 'create_edit', 'publish', 'trash_restore', 'media_manage', 'taxonomy', 'authors', 'seo', 'image', 'upload_portal'],
        draft_only:      ['read', 'create_edit', 'media_manage', 'taxonomy', 'seo', 'image', 'upload_portal'],
        content_manager: ['read', 'create_edit', 'publish', 'media_manage', 'taxonomy', 'authors', 'seo'],
        media_manager:   ['read', 'media_manage'],
        seo_specialist:  ['read', 'seo'],
        read_only:       ['read'],
    };

    var capPresetLabels = {
        standard:        'Standard',
        full_access:     'Full Access',
        draft_only:      'Draft Only',
        content_manager: 'Content Manager',
        media_manager:   'Media Manager',
        seo_specialist:  'SEO Specialist',
        read_only:       'Read Only',
        custom:          'Custom',
    };

    var capBadgeClasses = {
        standard:        'mcp-cap-badge--standard',
        full_access:     'mcp-cap-badge--full',
        draft_only:      'mcp-cap-badge--draft',
        content_manager: 'mcp-cap-badge--creator',
        media_manager:   'mcp-cap-badge--creator',
        seo_specialist:  'mcp-cap-badge--standard',
        read_only:       'mcp-cap-badge--readonly',
        custom:          'mcp-cap-badge--custom',
    };

    var capSaveTimers = {};

    function detectPreset(conn) {
        var currentCaps = [];
        $('[data-connection="' + conn + '"].mcp-cap-checkbox:checked').each(function () {
            currentCaps.push($(this).data('cap'));
        });
        currentCaps.sort();

        var matched = 'custom';
        $.each(capPresets, function (name, caps) {
            var sorted = caps.slice().sort();
            if (sorted.length === currentCaps.length &&
                sorted.every(function (c, i) { return c === currentCaps[i]; })) {
                matched = name;
                return false;
            }
        });
        return matched;
    }

    function updateCapUI(conn) {
        var preset = detectPreset(conn);
        $('[data-connection="' + conn + '"].mcp-cap-preset').val(preset);
        var $badge = $('#mcp-' + conn + '-cap-badge');
        $badge.text(capPresetLabels[preset])
              .removeClass(Object.values(capBadgeClasses).join(' '))
              .addClass(capBadgeClasses[preset] || capBadgeClasses.custom);
    }

    function autoSaveCaps(conn) {
        clearTimeout(capSaveTimers[conn]);
        capSaveTimers[conn] = setTimeout(function () {
            var caps = [];
            $('[data-connection="' + conn + '"].mcp-cap-checkbox:checked').each(function () {
                caps.push($(this).data('cap'));
            });

            doAjax(
                'axtolab_ai_connector_save_capabilities',
                { connection: conn, capabilities: caps },
                function () {
                    var $saved = $('#mcp-' + conn + '-saved');
                    $saved.addClass('visible');
                    setTimeout(function () { $saved.removeClass('visible'); }, 1500);
                },
                function () {}
            );
        }, 500);
    }

    // Preset dropdown change.
    $(document).on('change', '.mcp-cap-preset', function () {
        var conn   = $(this).data('connection');
        var preset = $(this).val();
        if (preset === 'custom') return;

        var caps = capPresets[preset] || [];
        $('[data-connection="' + conn + '"].mcp-cap-checkbox').each(function () {
            var cap = $(this).data('cap');
            if (cap === 'read') return;
            $(this).prop('checked', caps.indexOf(cap) !== -1);
        });

        updateCapUI(conn);
        autoSaveCaps(conn);
    });

    // Checkbox change.
    $(document).on('change', '.mcp-cap-checkbox', function () {
        var conn = $(this).data('connection');
        updateCapUI(conn);
        autoSaveCaps(conn);
    });

    // Initialize badges on page load.
    ['bearer', 'oauth'].forEach(function (conn) {
        updateCapUI(conn);
    });

    // ══════════════════════════════════════════════════════════════════════════
    // Image Providers — Save & Test
    // ══════════════════════════════════════════════════════════════════════════

    $(document).on('click', '.mcp-clear-key-btn', function (e) {
        e.preventDefault();
        var target = $(this).data('target');
        var $input = $('[name="' + target + '"]');
        $input.val('__CLEAR__').attr('type', 'text').prop('placeholder', '').css('color', '#dc3232');
        $(this).replaceWith('<span style="font-size:12px; color:#dc3232; margin-left:6px;">Will be cleared on save</span>');
    });

    $(document).on('click', '#mcp-save-image-providers-btn', function () {
        var $btn = $(this);
        var $msg = $('#mcp-image-providers-message');
        var $form = $('#mcp-image-providers-form');

        $btn.prop('disabled', true).text('Saving…');
        $msg.text('').removeClass('is-success is-error');

        var formData = {
            action: cfg.actions.saveImageProviders,
            nonce: cfg.ajaxNonce,
            google_imagen_enabled: $form.find('[name="google_imagen_enabled"]').is(':checked') ? 1 : 0,
            google_imagen_api_key: $form.find('[name="google_imagen_api_key"]').val(),
            google_imagen_model: $form.find('[name="google_imagen_model"]').val(),
            unsplash_enabled: $form.find('[name="unsplash_enabled"]').is(':checked') ? 1 : 0,
            unsplash_access_key: $form.find('[name="unsplash_access_key"]').val(),
            pexels_enabled: $form.find('[name="pexels_enabled"]').is(':checked') ? 1 : 0,
            pexels_api_key: $form.find('[name="pexels_api_key"]').val(),
            openai_enabled: $form.find('[name="openai_enabled"]').is(':checked') ? 1 : 0,
            openai_api_key: $form.find('[name="openai_api_key"]').val(),
            openai_model: $form.find('[name="openai_model"]').val(),
            openai_quality: $form.find('[name="openai_quality"]').val()
        };

        $.post(cfg.ajaxUrl, formData)
            .done(function (res) {
                $btn.prop('disabled', false).text('Save Settings');
                if (res && res.success) {
                    $msg.text(res.data.message || 'Settings saved.').removeClass('is-error').addClass('is-success');
                    // Clear password fields after successful save (they show placeholder dots).
                    $form.find('input[type="password"]').val('');
                } else {
                    var errMsg = (res && res.data && res.data.message) ? res.data.message : 'An error occurred.';
                    $msg.text(errMsg).removeClass('is-success').addClass('is-error');
                }
            })
            .fail(function () {
                $btn.prop('disabled', false).text('Save Settings');
                $msg.text('Request failed. Please try again.').removeClass('is-success').addClass('is-error');
            });
    });

    $(document).on('click', '.mcp-test-provider-btn', function () {
        var $btn = $(this);
        var provider = $btn.data('provider');
        var $result = $('.mcp-test-result[data-provider="' + provider + '"]');

        var keyFieldMap = {
            google_imagen: 'google_imagen_api_key',
            openai: 'openai_api_key',
            unsplash: 'unsplash_access_key',
            pexels: 'pexels_api_key'
        };
        var apiKey = $('input[name="' + keyFieldMap[provider] + '"]').val() || '';

        $btn.prop('disabled', true).text('Testing…');
        $result.text('').css('color', '');

        doAjax(
            cfg.actions.testImageProvider,
            { provider: provider, api_key: apiKey },
            function (data) {
                $btn.prop('disabled', false).text('Test Connection');
                $result.text(' ' + (data.message || 'OK')).css('color', '#46b450');
            },
            function (errMsg) {
                $btn.prop('disabled', false).text('Test Connection');
                $result.text(' ' + errMsg).css('color', '#dc3232');
            }
        );
    });

    // ── Advanced Write Gates ────────────────────────────────────────────────
    $(document).on('change', 'input[data-mcp-advanced-write]', function () {
        var input = this;
        var key = input.getAttribute('data-mcp-advanced-write');
        var status = document.querySelector('.mcp-gateway-toggle-status[data-for="' + key + '"]');
        if (status) { status.textContent = '… saving'; }
        input.disabled = true;

        var body = new URLSearchParams();
        body.append('action', cfg.actions.toggleAdvancedWrite);
        body.append('nonce', cfg.ajaxNonce);
        body.append('key', key);
        body.append('enabled', input.checked ? '1' : '0');

        fetch(cfg.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body })
            .then(function (r) { return r.json(); })
            .then(function (resp) {
                input.disabled = false;
                if (!status) return;
                if (resp && resp.success) {
                    status.textContent = input.checked ? 'enabled' : 'disabled';
                    status.style.color = input.checked ? '#2D7066' : '#666';
                } else {
                    status.textContent = (resp && resp.data && resp.data.message) || 'Save failed.';
                    status.style.color = '#b32d2e';
                    input.checked = !input.checked;
                }
            })
            .catch(function () {
                input.disabled = false;
                if (status) {
                    status.textContent = 'Network error.';
                    status.style.color = '#b32d2e';
                }
                input.checked = !input.checked;
            });
    });

}(jQuery));
JS;
	}
}

if ( ! class_exists( 'MCP_Gateway_Admin', false ) ) {
	class_alias( 'Axtolab_AI_Connector_Admin', 'MCP_Gateway_Admin' );
}
