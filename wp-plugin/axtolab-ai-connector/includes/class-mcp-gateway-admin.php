<?php
/**
 * MCP Gateway Admin
 *
 * Registers the admin menu page and renders all admin UI for the plugin,
 * including the "Connect AI Client" wizard, setup checklist, and per-
 * connection management. The plugin does not create WordPress users; admins
 * paste an Application Password (generated under WordPress's native
 * Profile > Application Passwords UI) into the connection wizard.
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
if ( class_exists( 'Axtolab_AI_Connector_Admin', false ) ) {
	return;
}

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
	 * AJAX action for revoking all known MCP connections.
	 *
	 * @var string
	 */
	const AJAX_REVOKE_ALL = 'axtolab_ai_connector_revoke_all_passwords';

	/**
	 * AJAX action for verifying a pasted Application Password against the
	 * site's own REST API. Step 3 of the connection wizard.
	 *
	 * @var string
	 */
	const AJAX_WIZARD_VERIFY = 'axtolab_ai_connector_wizard_verify';

	/**
	 * AJAX action for finalising the wizard and creating a connection.
	 *
	 * @var string
	 */
	const AJAX_WIZARD_CREATE = 'axtolab_ai_connector_wizard_create';

	/**
	 * AJAX action for toggling the Remote MCP feature.
	 *
	 * @var string
	 */
	const AJAX_TOGGLE_REMOTE = 'axtolab_ai_connector_toggle_remote';

	/**
	 * AJAX action for saving capability checkboxes.
	 *
	 * @var string
	 */
	const AJAX_SAVE_CAPABILITIES = 'axtolab_ai_connector_save_capabilities';

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
	 * AJAX action for updating per-connection sensitive-action consent.
	 *
	 * @var string
	 */
	const AJAX_UPDATE_CONNECTION_CONSENT = 'axtolab_ai_connector_update_connection_consent';

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
	 * AJAX action for saving per-action tool consent policy.
	 *
	 * @var string
	 */
	const AJAX_SAVE_TOOL_CONSENT_POLICY = 'axtolab_ai_connector_save_tool_consent_policy';

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

		// AJAX handlers — logged-in users only (nonce checked inside each handler).
		add_action( 'wp_ajax_' . self::AJAX_REVOKE_ALL, array( $this, 'ajax_revoke_all_passwords' ) );
		add_action( 'wp_ajax_' . self::AJAX_WIZARD_VERIFY, array( $this, 'ajax_wizard_verify' ) );
		add_action( 'wp_ajax_' . self::AJAX_WIZARD_CREATE, array( $this, 'ajax_wizard_create' ) );
		add_action( 'wp_ajax_' . self::AJAX_TOGGLE_REMOTE, array( $this, 'ajax_toggle_remote' ) );
		add_action( 'wp_ajax_' . self::AJAX_SAVE_CAPABILITIES, array( $this, 'ajax_save_capabilities' ) );
		add_action( 'wp_ajax_' . self::AJAX_REVOKE_OAUTH, array( $this, 'ajax_revoke_oauth' ) );
		add_action( 'wp_ajax_' . self::AJAX_SAVE_IMAGE_PROVIDERS, array( $this, 'ajax_save_image_providers' ) );
		add_action( 'wp_ajax_' . self::AJAX_TEST_IMAGE_PROVIDER, array( $this, 'ajax_test_image_provider' ) );
		add_action( 'wp_ajax_' . self::AJAX_RENAME_CONNECTION, array( $this, 'ajax_rename_connection' ) );
		add_action( 'wp_ajax_' . self::AJAX_REVOKE_CONNECTION, array( $this, 'ajax_revoke_connection' ) );
		add_action( 'wp_ajax_' . self::AJAX_UPDATE_CONNECTION_CAPS, array( $this, 'ajax_update_connection_caps' ) );
		add_action( 'wp_ajax_' . self::AJAX_UPDATE_CONNECTION_CONSENT, array( $this, 'ajax_update_connection_consent' ) );
		add_action( 'wp_ajax_' . self::AJAX_SAVE_REVIEW_EMAIL, array( $this, 'ajax_save_review_email' ) );
		add_action( 'wp_ajax_' . self::AJAX_UPDATE_CONNECTION_AUTHORS, array( $this, 'ajax_update_connection_authors' ) );
		add_action( 'wp_ajax_' . self::AJAX_TOGGLE_ADVANCED_WRITE, array( $this, 'ajax_toggle_advanced_write' ) );
		add_action( 'wp_ajax_' . self::AJAX_SAVE_TOOL_CONSENT_POLICY, array( $this, 'ajax_save_tool_consent_policy' ) );

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
		if ( ! empty( $settings['remote_mcp_enabled'] ) ) {
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
				<?php esc_html_e( 'Remote MCP for web-based AI clients (ChatGPT, Claude Web) is disabled. Enable it to let those clients connect to this site via OAuth.', 'axtolab-ai-connector' ); ?>
			</p>
			<p>
				<a class="button button-primary" href="<?php echo esc_url( $enable_url ); ?>"><?php esc_html_e( 'Enable Remote MCP', 'axtolab-ai-connector' ); ?></a>
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
			$settings                       = get_option( 'axtolab_ai_connector_settings', array() );
			$settings['remote_mcp_enabled'] = true;
			// Drop the legacy key if it lingered after the R6 collapse.
			unset( $settings['oauth_enabled'] );
			update_option( 'axtolab_ai_connector_settings', $settings );

			if ( function_exists( 'axtolab_ai_connector_write_oauth_htaccess_rules' ) ) {
				axtolab_ai_connector_write_oauth_htaccess_rules();
			}

			wp_safe_redirect(
				add_query_arg( 'axtolab_remote_mcp_enabled', '1', admin_url( 'admin.php?page=' . self::MENU_SLUG ) )
			);
			exit;
		}

		if ( 'dismiss' === $action ) {
			update_user_meta( get_current_user_id(), self::OAUTH_NOTICE_DISMISSED_META, 1 );
			wp_safe_redirect( admin_url( 'plugins.php' ) );
			exit;
		}
	}

	// ── Plugin-row Support / Docs links ──────────────────────────────────────

	/**
	 * Add a "Settings · Support" pair to the Plugins admin row for this
	 * plugin (left-side action links, next to Deactivate). Both come
	 * from Axtolab_AI_Connector_Support_Links so the support email + label stays
	 * consistent across the suite.
	 */
	public function add_plugin_action_links( $links ) {
		if ( ! class_exists( 'Axtolab_AI_Connector_Support_Links' ) ) {
			return $links;
		}
		$settings_url = admin_url( 'admin.php?page=' . self::MENU_SLUG );
		$rows         = Axtolab_AI_Connector_Support_Links::plugin_row_links( 'AI Connector', AXTOLAB_AI_CONNECTOR_VERSION, 'axtolab-ai-connector', $settings_url );
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
		if ( ! class_exists( 'Axtolab_AI_Connector_Support_Links' ) ) {
			return $links;
		}
		$rows = Axtolab_AI_Connector_Support_Links::plugin_row_links( 'AI Connector', AXTOLAB_AI_CONNECTOR_VERSION, 'axtolab-ai-connector' );
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
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading bundled plugin SVG asset (path is internal constant via plugin_dir_path, not user input); WP_Filesystem would add admin-init overhead with no benefit for a static plugin-owned file.
		$svg = file_get_contents( $svg_path );
		if ( $svg === false ) {
			$cached = 'dashicons-rest-api';
			return $cached;
		}
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Standard data: URI encoding of the inline admin-menu SVG icon (RFC 2397), not obfuscation.
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
		$parent_landing = 'toplevel_page_' . self::PARENT_MENU_SLUG;
		$ai_connector   = self::PARENT_MENU_SLUG . '_page_' . self::MENU_SLUG;
		$logs_page      = self::PARENT_MENU_SLUG . '_page_wp-mcp-gateway-logs';
		$is_settings    = in_array( $hook_suffix, array( $parent_landing, $ai_connector ), true );
		$is_logs        = ( $hook_suffix === $logs_page );
		if ( ! $is_settings && ! $is_logs ) {
			return;
		}

		// Inline styles — keeps the plugin dependency-free for CSS.
		wp_register_style( 'axtolab-ai-connector-admin', false, array(), AXTOLAB_AI_CONNECTOR_VERSION );
		wp_enqueue_style( 'axtolab-ai-connector-admin' );
		wp_add_inline_style( 'axtolab-ai-connector-admin', $this->get_inline_styles() );

		// Connection wizard stylesheet (lives under assets/ as required by
		// the round-6 refactor). Ships separately from the inline admin CSS
		// so it stays diffable and so the wizard's class names don't bleed
		// into other admin screens.
		if ( $is_settings ) {
			wp_enqueue_style(
				'axtolab-ai-connector-wizard',
				plugins_url( 'assets/connection-wizard.css', AXTOLAB_AI_CONNECTOR_FILE ),
				array(),
				AXTOLAB_AI_CONNECTOR_VERSION
			);
		}

		// Localized data for AJAX calls.
		wp_register_script( 'axtolab-ai-connector-admin', false, array( 'jquery' ), AXTOLAB_AI_CONNECTOR_VERSION, true );
		wp_enqueue_script( 'axtolab-ai-connector-admin' );
		wp_localize_script(
			'axtolab-ai-connector-admin',
			'axtolabAiConnector',
			array(
				'restBase'      => esc_url_raw( rest_url( 'axtolab-ai-connector/v1' ) ),
				'nonce'         => wp_create_nonce( 'wp_rest' ),
				'ajaxUrl'       => admin_url( 'admin-ajax.php' ),
				'ajaxNonce'     => wp_create_nonce( self::MENU_SLUG . '-ajax' ),
				'strings'       => array(
					'revoking'      => __( 'Revoking…', 'axtolab-ai-connector' ),
					'confirmRevoke' => __( 'Revoke all active connections? Connected AI clients will lose access until re-authorized.', 'axtolab-ai-connector' ),
					'verifying'     => __( 'Verifying…', 'axtolab-ai-connector' ),
					'creating'      => __( 'Creating…', 'axtolab-ai-connector' ),
				),
				'mcpbAvailable' => true,
				'actions'       => array(
					'saveImageProviders'      => self::AJAX_SAVE_IMAGE_PROVIDERS,
					'testImageProvider'       => self::AJAX_TEST_IMAGE_PROVIDER,
					'renameConnection'        => self::AJAX_RENAME_CONNECTION,
					'revokeConnection'        => self::AJAX_REVOKE_CONNECTION,
					'updateConnectionCaps'    => self::AJAX_UPDATE_CONNECTION_CAPS,
					'updateConnectionConsent' => self::AJAX_UPDATE_CONNECTION_CONSENT,
					'saveReviewEmail'         => self::AJAX_SAVE_REVIEW_EMAIL,
					'updateConnectionAuthors' => self::AJAX_UPDATE_CONNECTION_AUTHORS,
					'toggleAdvancedWrite'     => self::AJAX_TOGGLE_ADVANCED_WRITE,
					'saveToolConsentPolicy'   => self::AJAX_SAVE_TOOL_CONSENT_POLICY,
					'wizardVerify'            => self::AJAX_WIZARD_VERIFY,
					'wizardCreate'            => self::AJAX_WIZARD_CREATE,
				),
			)
		);
		if ( $is_settings ) {
			wp_add_inline_script( 'axtolab-ai-connector-admin', $this->get_inline_script() );
			wp_enqueue_script(
				'axtolab-ai-connector-wizard',
				plugins_url( 'assets/connection-wizard.js', AXTOLAB_AI_CONNECTOR_FILE ),
				array( 'jquery', 'axtolab-ai-connector-admin' ),
				AXTOLAB_AI_CONNECTOR_VERSION,
				true
			);
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
				if ( class_exists( 'Axtolab_AI_Connector_Support_Links' ) ) {
					Axtolab_AI_Connector_Support_Links::render_header_link( 'AI Connector', AXTOLAB_AI_CONNECTOR_VERSION );
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
			if ( class_exists( 'Axtolab_AI_Connector_Support_Links' ) ) {
				Axtolab_AI_Connector_Support_Links::render_footer( 'AI Connector', AXTOLAB_AI_CONNECTOR_VERSION, 'axtolab-ai-connector' );
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
		$filters      = array();
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
			if ( class_exists( 'Axtolab_AI_Connector_Support_Links' ) ) {
				Axtolab_AI_Connector_Support_Links::render_footer( 'AI Connector', AXTOLAB_AI_CONNECTOR_VERSION, 'axtolab-ai-connector' );
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

				<?php // 2. App Passwords supported. ?>
				<?php if ( class_exists( 'WP_Application_Passwords' ) && wp_is_application_passwords_available() ) : ?>
					<li class="status-ok">
						<span class="mcp-status-icon dashicons dashicons-yes-alt"></span>
						<span class="mcp-status-label"><?php esc_html_e( 'Application Passwords', 'axtolab-ai-connector' ); ?></span>
						<span class="mcp-status-detail"><?php esc_html_e( 'Available', 'axtolab-ai-connector' ); ?></span>
					</li>
				<?php else : ?>
					<li class="status-error">
						<span class="mcp-status-icon dashicons dashicons-dismiss"></span>
						<span class="mcp-status-label"><?php esc_html_e( 'Application Passwords', 'axtolab-ai-connector' ); ?></span>
						<span class="mcp-status-detail"><?php esc_html_e( 'Disabled — enable Application Passwords on this site to use the AI Connector.', 'axtolab-ai-connector' ); ?></span>
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
				// 4. OAuth Discovery (only when Remote MCP is enabled).
				// Uses the REST API metadata route — works on every host.
				$oauth_settings = get_option( 'axtolab_ai_connector_settings', array() );
				if ( ! empty( $oauth_settings['remote_mcp_enabled'] ) ) :
					$discovery_url    = rest_url( 'axtolab-ai-connector/v1/oauth/metadata/resource' );
					$discovery_result = wp_remote_get(
						$discovery_url,
						array(
							'timeout' => 5,
						)
					);
					$discovery_ok     = false;

					if ( ! is_wp_error( $discovery_result ) ) {
						$code         = wp_remote_retrieve_response_code( $discovery_result );
						$body         = wp_remote_retrieve_body( $discovery_result );
						$json         = json_decode( $body, true );
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
				if ( ! empty( $oauth_settings['remote_mcp_enabled'] ) ) :
					$wellknown_status = get_transient( 'axtolab_ai_connector_wellknown_status' );
					if ( false === $wellknown_status ) {
						$wellknown_url    = home_url( '/.well-known/oauth-protected-resource' );
						$wellknown_result = wp_remote_get(
							$wellknown_url,
							array(
								'timeout' => 5,
							)
						);
						$wellknown_ok     = false;
						if ( ! is_wp_error( $wellknown_result ) ) {
							$code         = wp_remote_retrieve_response_code( $wellknown_result );
							$body         = wp_remote_retrieve_body( $wellknown_result );
							$json         = json_decode( $body, true );
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
		unset( $status ); // Reserved for future per-status badges; intentionally unused right now.
		$hostname = wp_parse_url( home_url(), PHP_URL_HOST );
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
				<button type="button" class="mcp-tab" data-tab="connection-manager">
					<?php esc_html_e( 'Connection Manager', 'axtolab-ai-connector' ); ?>
				</button>
			</div>

			<?php // ── Tab 1: Quick Connect (Application Password wizard) ────────── ?>
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

				<p class="mcp-field-label"><?php esc_html_e( 'Step 2: Add a new connection', 'axtolab-ai-connector' ); ?></p>
				<p class="mcp-help-text">
					<?php esc_html_e( 'Each AI client connects with its own Application Password, created in your WordPress profile. The wizard below walks you through it; the resulting connection appears in the "Existing connections" card at the bottom of the page.', 'axtolab-ai-connector' ); ?>
				</p>
				<p>
					<button type="button" class="button button-primary" id="mcp-wizard-open-btn" aria-controls="mcp-wizard-panel" aria-expanded="false">
						<span aria-hidden="true">+ </span><?php esc_html_e( 'Add new connection', 'axtolab-ai-connector' ); ?>
					</button>
				</p>

				<?php $this->render_connection_wizard_panel(); ?>

			</div><!-- tab: quick-connect -->

			<?php // ── Tab 2: Web Clients (OAuth only) ── ?>
			<?php
			$remote_settings  = get_option( 'axtolab_ai_connector_settings', array() );
			$remote_enabled   = ! empty( $remote_settings['remote_mcp_enabled'] );
			$oauth_info       = Axtolab_AI_Connector_OAuth::get_token_info();
			$mcp_endpoint_url = rest_url( 'axtolab-ai-connector/v1/mcp' );

			// Capability definitions for rendering checkboxes — use shared class.
			$capability_defs = Axtolab_AI_Connector_Capabilities::group_labels();

			// Default: Standard preset (all except trash_restore).
			$default_caps = Axtolab_AI_Connector_MCP_Transport::DEFAULT_CAPABILITIES;
			$oauth_caps   = $remote_settings['oauth_capabilities'] ?? $default_caps;
			?>
			<div class="mcp-tab-content" data-tab="remote-access">

				<p class="mcp-help-text"><?php esc_html_e( 'Enable remote access for web-based AI clients like ChatGPT and Claude Web. Connects via OAuth 2.1 — no credentials leave your browser.', 'axtolab-ai-connector' ); ?></p>

				<div class="axtolab-callout">
					<p>
						<strong><?php esc_html_e( 'How permissions work', 'axtolab-ai-connector' ); ?></strong>
					</p>
					<ul style="margin:6px 0 0 18px; padding:0;">
						<li><?php esc_html_e( 'The OAuth token authenticates as the WordPress user who approved the request (typically the site admin, but could be a dedicated user you set up for AI access). That user\'s WordPress role sets the upper bound of what the AI can do at the per-object level (per-post / per-media / per-term).', 'axtolab-ai-connector' ); ?></li>
						<li><?php esc_html_e( 'Connection capabilities (set on the OAuth connection in the "Existing connections" card after you approve) — a further filter within that upper bound, deciding which AI tools the connection is allowed to call.', 'axtolab-ai-connector' ); ?></li>
						<li><?php esc_html_e( 'Both layers must allow an action for it to succeed; the connection capabilities can only restrict, never grant beyond what the user can already do.', 'axtolab-ai-connector' ); ?></li>
					</ul>
				</div>

				<p class="mcp-field-label"><?php esc_html_e( 'Enable Remote MCP for Web Clients', 'axtolab-ai-connector' ); ?></p>
				<p class="mcp-help-text">
					<?php esc_html_e( 'Allow web AI clients (ChatGPT, Claude Web, and other MCP-compatible clients) to connect to your site via OAuth 2.1. The MCP-over-HTTP transport endpoint is exposed once enabled.', 'axtolab-ai-connector' ); ?>
				</p>
				<label class="mcp-input-row">
					<input type="checkbox" id="mcp-toggle-remote" <?php checked( $remote_enabled ); ?> />
					<?php esc_html_e( 'Enable Remote MCP (via OAuth)', 'axtolab-ai-connector' ); ?>
				</label>
				<p id="mcp-toggle-remote-message" class="mcp-feedback" aria-live="polite"></p>

				<?php if ( $remote_enabled ) : ?>
					<p class="mcp-help-text" style="color: #00a32a; margin-top: 4px;">
						<span class="dashicons dashicons-yes-alt" aria-hidden="true"></span>
						<?php esc_html_e( 'Remote MCP enabled — web clients can connect via OAuth.', 'axtolab-ai-connector' ); ?>
					</p>

					<hr />

					<!-- ═══ Shared MCP Endpoint URL ═══ -->
					<p class="mcp-field-label"><?php esc_html_e( 'MCP Endpoint URL', 'axtolab-ai-connector' ); ?></p>
					<p class="mcp-help-text">
						<?php esc_html_e( 'All remote connections share this endpoint. Copy it into your MCP-compatible AI client. The client will redirect you here to approve OAuth access on first connect.', 'axtolab-ai-connector' ); ?>
					</p>
					<div class="mcp-copy-block">
						<pre class="mcp-code-block" id="mcp-endpoint-url"><?php echo esc_html( $mcp_endpoint_url ); ?></pre>
						<button type="button" class="button button-small mcp-copy-btn" data-target="mcp-endpoint-url">
							<?php esc_html_e( 'Copy', 'axtolab-ai-connector' ); ?>
						</button>
					</div>

					<div id="mcp-oauth-status" style="margin-top: 12px;">
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
							<li><?php esc_html_e( 'Enable Remote MCP (via OAuth) above.', 'axtolab-ai-connector' ); ?></li>
							<li><?php esc_html_e( 'Copy the MCP endpoint URL above.', 'axtolab-ai-connector' ); ?></li>
							<li><?php esc_html_e( 'In ChatGPT, go to Settings → Apps & Connectors → Create.', 'axtolab-ai-connector' ); ?></li>
							<li><?php esc_html_e( 'Paste the URL as the MCP Server URL.', 'axtolab-ai-connector' ); ?></li>
							<li><?php esc_html_e( 'Select "OAuth" as the authentication method.', 'axtolab-ai-connector' ); ?></li>
							<li><?php esc_html_e( 'Click Create — ChatGPT will discover the OAuth endpoints automatically.', 'axtolab-ai-connector' ); ?></li>
							<li><?php esc_html_e( 'When prompted, log into your WordPress admin and click Approve.', 'axtolab-ai-connector' ); ?></li>
							<li><?php esc_html_e( 'ChatGPT will now have access to your WordPress tools.', 'axtolab-ai-connector' ); ?></li>
						</ol>
					</details>
				<?php else : ?>
					<p class="mcp-help-text" style="margin-top: 4px;">
						<?php esc_html_e( 'Disabled — web clients cannot connect.', 'axtolab-ai-connector' ); ?>
					</p>
				<?php endif; ?>

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

				<?php // ── Tab 4: Connection Manager ─────────────────────────────────── ?>
				<div class="mcp-tab-content" data-tab="connection-manager">
					<?php $this->render_connected_clients_section(); ?>
				</div><!-- tab: connection-manager -->

		</div><!-- .mcp-gateway-connect -->
		<?php
	}

	/**
	 * Render the "Connection Manager" section with per-connection management.
	 *
	 * Shows a table of all active connections with rename/revoke actions,
	 * plus a "Revoke All" link as an emergency kill switch. The connection
	 * creation UI lives inside the Desktop AI Clients tab (App Password
	 * wizard) and the Web Clients tab (OAuth approval flow); this card is
	 * read + manage only.
	 *
	 * @return void
	 */
	private function render_connected_clients_section(): void {
		$connections  = Axtolab_AI_Connector_Connections::get_all_connections();
		$count        = count( $connections );
		$settings     = get_option( 'axtolab_ai_connector_settings', array() );
		$review_email = ! empty( $settings['review_notification_email'] )
		? $settings['review_notification_email']
		: '';
		?>
		<h3 id="mcp-connections-card"><?php esc_html_e( 'Connection Manager', 'axtolab-ai-connector' ); ?></h3>

		<p class="mcp-help-text" style="margin-top:-4px;">
			<?php esc_html_e( 'Manage each active MCP client in one place: revoke access, rename the connection, choose which tool families it may use, and set how sensitive actions should behave.', 'axtolab-ai-connector' ); ?>
		</p>

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
				<?php esc_html_e( 'No clients are connected yet. Add one from the Desktop AI Clients or Web Clients tab above.', 'axtolab-ai-connector' ); ?>
			</p>
		<?php else : ?>
			<table class="mcp-connections-table" id="mcp-connections-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Label', 'axtolab-ai-connector' ); ?></th>
						<th><?php esc_html_e( 'WP user', 'axtolab-ai-connector' ); ?></th>
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
								<?php if ( ! empty( $conn['needs_reauth'] ) ) : ?>
									<span style="color:#d63638;" title="<?php esc_attr_e( 'The underlying WordPress user no longer exists. Revoke this connection and create a new one.', 'axtolab-ai-connector' ); ?>">
										<?php esc_html_e( 'Needs re-auth', 'axtolab-ai-connector' ); ?>
									</span>
								<?php elseif ( ! empty( $conn['wp_user_login'] ) ) : ?>
									<code><?php echo esc_html( $conn['wp_user_login'] ); ?></code>
								<?php else : ?>
									&mdash;
								<?php endif; ?>
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
								<button type="button" class="button button-small mcp-conn-perms-btn"><?php esc_html_e( 'Tool Access & Behavior', 'axtolab-ai-connector' ); ?></button>
								<button type="button" class="button button-small mcp-conn-rename-btn">
									<?php esc_html_e( 'Rename', 'axtolab-ai-connector' ); ?>
								</button>
								<button type="button" class="button button-small mcp-conn-revoke-btn">
									<?php esc_html_e( 'Revoke', 'axtolab-ai-connector' ); ?>
								</button>
							</td>
						</tr>
						<tr class="mcp-connection-caps-row" data-id="<?php echo esc_attr( $conn['id'] ); ?>" style="display:none;">
							<td colspan="7">
								<div class="mcp-conn-caps-editor">
									<div class="mcp-conn-access-section">
										<h4 class="mcp-conn-section-title"><?php esc_html_e( 'Tool Access', 'axtolab-ai-connector' ); ?></h4>
										<p class="mcp-help-text"><?php esc_html_e( 'Tool Access decides which families of MCP tools this connection can reach. If a family is off, every action in that family is unavailable regardless of the behavior settings below.', 'axtolab-ai-connector' ); ?></p>
									</div>
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

									<?php $this->render_connection_behavior_section( $conn ); ?>

									<?php
									$wp_authors           = get_users(
										array(
											'role__in' => array( 'administrator', 'editor', 'author', 'contributor' ),
											'orderby'  => 'display_name',
											'order'    => 'ASC',
											'fields'   => array( 'ID', 'display_name' ),
										)
									);
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
	 * Render the inline "+ Add new connection" wizard panel.
	 *
	 * The panel is hidden until the toolbar button toggles it open. Steps are
	 * stacked inside; the small JS in {@see self::get_inline_script()} only
	 * handles open/close + step transitions and Verify / Create AJAX calls.
	 *
	 * @return void
	 */
	private function render_connection_wizard_panel(): void {
		$app_pwd_url   = admin_url( 'profile.php#application-passwords-section' );
		$client_types  = array(
			'claude_desktop' => __( 'Claude Desktop', 'axtolab-ai-connector' ),
			'cli'            => __( 'Claude Code / CLI', 'axtolab-ai-connector' ),
			'cowork'         => __( 'Cowork', 'axtolab-ai-connector' ),
			'chatgpt'        => __( 'ChatGPT', 'axtolab-ai-connector' ),
			'claude_web'     => __( 'Claude Web', 'axtolab-ai-connector' ),
			'other'          => __( 'Other MCP client', 'axtolab-ai-connector' ),
		);
		$cap_groups    = Axtolab_AI_Connector_Capabilities::group_labels();
		$preset_labels = Axtolab_AI_Connector_Capabilities::preset_labels();
		$default_caps  = Axtolab_AI_Connector_Capabilities::DEFAULT_PRESET;
		?>
		<div class="axtolab-wizard-panel" id="mcp-wizard-panel" role="region" aria-label="<?php esc_attr_e( 'Add a new MCP connection', 'axtolab-ai-connector' ); ?>" hidden>

			<h4 class="axtolab-wizard-title"><?php esc_html_e( 'Add a new MCP connection', 'axtolab-ai-connector' ); ?></h4>

			<div class="axtolab-callout">
				<p>
					<strong><?php esc_html_e( 'How permissions work', 'axtolab-ai-connector' ); ?></strong>
				</p>
				<ul style="margin:6px 0 0 18px; padding:0;">
					<li><?php esc_html_e( 'The Application Password you paste in Step 3 belongs to a WordPress user (your admin account or a dedicated user you set up). That user\'s WordPress role sets the upper bound of what the AI can do at the per-object level (per-post / per-media / per-term).', 'axtolab-ai-connector' ); ?></li>
					<li><?php esc_html_e( 'Connection capabilities (set in Step 4) — a further filter within that upper bound, deciding which AI tools the connection is allowed to call.', 'axtolab-ai-connector' ); ?></li>
					<li><?php esc_html_e( 'Both layers must allow an action for it to succeed; the connection capabilities can only restrict, never grant beyond what the user can already do.', 'axtolab-ai-connector' ); ?></li>
				</ul>
			</div>

			<?php // ── Step 1 — Who will the AI act as ─────────────────────────── ?>
			<section class="axtolab-wizard-step" data-step="1">
				<h5 class="axtolab-wizard-step-title"><?php esc_html_e( 'Step 1 — Who will the AI act as?', 'axtolab-ai-connector' ); ?></h5>
				<div class="axtolab-grid">
					<label class="axtolab-path-card">
						<input type="radio" name="mcp-wizard-path" value="admin" checked="checked" />
						<strong><?php esc_html_e( 'Your admin account', 'axtolab-ai-connector' ); ?></strong>
						<p><?php esc_html_e( 'Simple setup. The per-connection capabilities below are the only effective limit.', 'axtolab-ai-connector' ); ?></p>
					</label>
					<label class="axtolab-path-card">
						<input type="radio" name="mcp-wizard-path" value="dedicated" />
						<strong><?php esc_html_e( 'A dedicated WP user', 'axtolab-ai-connector' ); ?></strong>
						<p><?php esc_html_e( 'Recommended for production sites. Limits blast radius if the App Password leaks.', 'axtolab-ai-connector' ); ?></p>
						<p>
							<a href="<?php echo esc_url( admin_url( 'user-new.php' ) ); ?>" target="_blank" rel="noopener">
								<?php esc_html_e( 'How to create one →', 'axtolab-ai-connector' ); ?>
							</a>
						</p>
					</label>
				</div>
			</section>

			<?php // ── Step 2 — Create an Application Password ─────────────────── ?>
			<section class="axtolab-wizard-step" data-step="2">
				<h5 class="axtolab-wizard-step-title"><?php esc_html_e( 'Step 2 — Create an Application Password', 'axtolab-ai-connector' ); ?></h5>
				<ol class="axtolab-wizard-list">
					<li>
						<?php
						printf(
							/* translators: %s: HTML link to WordPress Application Passwords settings */
							wp_kses_post( __( 'Open %s in your WordPress profile (it opens in a new tab).', 'axtolab-ai-connector' ) ),
							'<a href="' . esc_url( $app_pwd_url ) . '" target="_blank" rel="noopener">' . esc_html__( 'Application Passwords settings ↗', 'axtolab-ai-connector' ) . '</a>'
						);
						?>
					</li>
					<li><?php esc_html_e( 'Create a new password named, for example, "Axtolab AI Connector — Claude Desktop".', 'axtolab-ai-connector' ); ?></li>
					<li><?php esc_html_e( 'Copy the generated password (WordPress shows it only once).', 'axtolab-ai-connector' ); ?></li>
				</ol>
			</section>

			<?php // ── Step 3 — Connect ────────────────────────────────────────── ?>
			<section class="axtolab-wizard-step" data-step="3">
				<h5 class="axtolab-wizard-step-title"><?php esc_html_e( 'Step 3 — Connect', 'axtolab-ai-connector' ); ?></h5>

				<p class="axtolab-wizard-field">
					<label for="mcp-wizard-label"><?php esc_html_e( 'Connection label', 'axtolab-ai-connector' ); ?></label>
					<input type="text" id="mcp-wizard-label" class="regular-text" maxlength="200" placeholder="<?php esc_attr_e( 'Claude Desktop', 'axtolab-ai-connector' ); ?>" />
				</p>

				<p class="axtolab-wizard-field">
					<label for="mcp-wizard-client-type"><?php esc_html_e( 'Client type', 'axtolab-ai-connector' ); ?></label>
					<select id="mcp-wizard-client-type">
						<?php foreach ( $client_types as $value => $label ) : ?>
							<option value="<?php echo esc_attr( $value ); ?>"><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
				</p>

				<p class="axtolab-wizard-field">
					<label for="mcp-wizard-app-password"><?php esc_html_e( 'Paste Application Password', 'axtolab-ai-connector' ); ?></label>
					<input type="text" id="mcp-wizard-app-password" class="regular-text" autocomplete="off" spellcheck="false" placeholder="xxxx xxxx xxxx xxxx xxxx xxxx" />
				</p>

				<p class="axtolab-wizard-field" data-path="dedicated" hidden>
					<label for="mcp-wizard-username"><?php esc_html_e( 'WordPress username (login)', 'axtolab-ai-connector' ); ?></label>
					<input type="text" id="mcp-wizard-username" class="regular-text" autocomplete="off" spellcheck="false" placeholder="<?php esc_attr_e( 'e.g. ai-editor', 'axtolab-ai-connector' ); ?>" />
					<span class="description"><?php esc_html_e( 'Required when the App Password belongs to a different WordPress user than the one you are logged in as.', 'axtolab-ai-connector' ); ?></span>
				</p>

				<p>
					<button type="button" class="button button-secondary" id="mcp-wizard-verify-btn">
						<?php esc_html_e( 'Verify', 'axtolab-ai-connector' ); ?>
					</button>
					<span id="mcp-wizard-verify-result" class="mcp-feedback" aria-live="polite"></span>
				</p>
			</section>

			<?php // ── Step 4 — Capabilities ───────────────────────────────────── ?>
			<section class="axtolab-wizard-step" data-step="4">
				<h5 class="axtolab-wizard-step-title"><?php esc_html_e( 'Step 4 — Capabilities', 'axtolab-ai-connector' ); ?></h5>
				<p>
					<label><?php esc_html_e( 'Preset:', 'axtolab-ai-connector' ); ?>
						<select id="mcp-wizard-preset">
							<?php foreach ( $preset_labels as $key => $label ) : ?>
								<option value="<?php echo esc_attr( $key ); ?>" <?php selected( 'standard', $key ); ?>><?php echo esc_html( $label ); ?></option>
							<?php endforeach; ?>
						</select>
					</label>
				</p>
				<div class="mcp-conn-caps-checkboxes" id="mcp-wizard-caps">
					<?php foreach ( $cap_groups as $cap_key => $cap_label ) : ?>
						<label class="mcp-conn-cap-label">
							<input type="checkbox"
								class="mcp-wizard-cap-checkbox"
								data-cap="<?php echo esc_attr( $cap_key ); ?>"
								<?php checked( in_array( $cap_key, $default_caps, true ) ); ?>
								<?php disabled( 'read' === $cap_key ); ?>
							/>
							<?php echo esc_html( $cap_label ); ?>
							<?php if ( 'read' === $cap_key ) : ?>
								<em class="mcp-conn-cap-note">(<?php esc_html_e( 'always on', 'axtolab-ai-connector' ); ?>)</em>
							<?php endif; ?>
						</label>
					<?php endforeach; ?>
				</div>
			</section>

			<?php // ── Footer buttons + token-display area ──────────────────────── ?>
			<div class="axtolab-wizard-footer" style="display:flex; justify-content:space-between; align-items:center; margin-top:18px; gap:10px;">
				<button type="button" class="button" id="mcp-wizard-cancel-btn">
					<?php esc_html_e( 'Cancel', 'axtolab-ai-connector' ); ?>
				</button>
				<button type="button" class="button button-primary" id="mcp-wizard-create-btn" disabled>
					<?php esc_html_e( 'Create connection', 'axtolab-ai-connector' ); ?>
				</button>
			</div>
			<p id="mcp-wizard-create-message" class="mcp-feedback" aria-live="polite"></p>

			<div class="axtolab-wizard-success" id="mcp-wizard-success" hidden>
				<h5><?php esc_html_e( 'Connection created.', 'axtolab-ai-connector' ); ?></h5>
				<p class="description">
					<?php esc_html_e( 'Copy the connection token below and paste it into your MCP client. It bundles the site URL, the WordPress user login, and the Application Password — treat it like a password.', 'axtolab-ai-connector' ); ?>
				</p>
				<div class="mcp-copy-block">
					<pre class="mcp-code-block" id="mcp-wizard-token"></pre>
					<button type="button" class="button button-small mcp-copy-btn" data-target="mcp-wizard-token">
						<?php esc_html_e( 'Copy', 'axtolab-ai-connector' ); ?>
					</button>
				</div>
				<p style="margin-top:10px;">
					<button type="button" class="button" id="mcp-wizard-done-btn">
						<?php esc_html_e( 'Done', 'axtolab-ai-connector' ); ?>
					</button>
				</p>
			</div>

		</div><!-- .axtolab-wizard-panel -->
		<?php
	}

	/**
	 * Render sensitive-action behavior controls for a single connection.
	 *
	 * @param array $conn Connection row.
	 * @return void
	 */
	private function render_connection_behavior_section( array $conn ): void {
		$connection_id  = isset( $conn['id'] ) ? (string) $conn['id'] : '';
		$capabilities   = isset( $conn['capabilities'] ) && is_array( $conn['capabilities'] ) ? $conn['capabilities'] : array();
		$capability_labels = Axtolab_AI_Connector_Capabilities::group_labels();
		$policy         = isset( $conn['tool_consent_policy'] ) && is_array( $conn['tool_consent_policy'] )
			? $conn['tool_consent_policy']
			: Axtolab_AI_Connector_Tool_Consent_Policy::exported_policy( $connection_id );
		?>
		<div class="mcp-conn-behavior-panel">
			<div class="mcp-conn-behavior-head">
				<div>
					<h4><?php esc_html_e( 'Sensitive-action behavior', 'axtolab-ai-connector' ); ?></h4>
					<p class="mcp-help-text"><?php esc_html_e( 'Behavior applies only after Tool Access allows the required family. If the required family is off, Tool Access wins and the action is unavailable.', 'axtolab-ai-connector' ); ?></p>
				</div>
				<span class="mcp-conn-behavior-saved"><?php esc_html_e( 'Saved', 'axtolab-ai-connector' ); ?></span>
			</div>
			<div class="mcp-tool-consent-list mcp-conn-behavior-list">
				<?php foreach ( self::tool_consent_actions() as $action => $meta ) : ?>
					<?php
					$tier           = isset( $policy[ $action ] ) ? $policy[ $action ] : 'ask';
					$required_cap   = isset( $meta['requires'] ) ? (string) $meta['requires'] : '';
					$required_label = isset( $capability_labels[ $required_cap ] ) ? $capability_labels[ $required_cap ] : $required_cap;
					$access_enabled = '' === $required_cap || in_array( $required_cap, $capabilities, true );
					?>
					<div class="mcp-tool-consent-row<?php echo $access_enabled ? '' : ' mcp-tool-consent-row-disabled'; ?>" data-tool-consent-row="<?php echo esc_attr( $action ); ?>" data-required-cap="<?php echo esc_attr( $required_cap ); ?>">
						<div class="mcp-tool-consent-copy">
							<strong><?php echo esc_html( $meta['label'] ); ?></strong>
							<span><?php echo esc_html( $meta['description'] ); ?></span>
							<span class="mcp-tool-consent-meta">
								<span class="mcp-tool-consent-requires">
									<?php
									printf(
										/* translators: %s: capability family label */
										esc_html__( 'Requires: %s', 'axtolab-ai-connector' ),
										esc_html( $required_label )
									);
									?>
								</span>
								<span class="mcp-tool-consent-access-note">
									<?php esc_html_e( 'Unavailable because Tool Access is off.', 'axtolab-ai-connector' ); ?>
								</span>
							</span>
						</div>
						<fieldset class="mcp-consent-segment" aria-label="<?php echo esc_attr( $meta['label'] ); ?>" <?php disabled( ! $access_enabled ); ?>>
							<?php $this->render_tool_consent_choice( $action, 'always', $tier, __( 'Always allow', 'axtolab-ai-connector' ), 'dashicons-yes-alt', $connection_id ); ?>
							<?php $this->render_tool_consent_choice( $action, 'ask', $tier, __( 'Ask first', 'axtolab-ai-connector' ), 'hand', $connection_id ); ?>
							<?php $this->render_tool_consent_choice( $action, 'disallow', $tier, __( 'Block', 'axtolab-ai-connector' ), 'dashicons-dismiss', $connection_id ); ?>
						</fieldset>
					</div>
				<?php endforeach; ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Render the visible connection permissions and tool consent policy card.
	 */
	private function render_tool_permissions_section(): void {
		$settings        = get_option( 'axtolab_ai_connector_settings', array() );
		$settings        = is_array( $settings ) ? $settings : array();
		$capability_defs = Axtolab_AI_Connector_Capabilities::group_labels();
		$default_caps    = Axtolab_AI_Connector_MCP_Transport::DEFAULT_CAPABILITIES;
		$oauth_caps      = isset( $settings['oauth_capabilities'] ) && is_array( $settings['oauth_capabilities'] ) ? $settings['oauth_capabilities'] : $default_caps;
		$consent_policy  = Axtolab_AI_Connector_Tool_Consent_Policy::exported_policy();
		?>
		<div class="mcp-gateway-card mcp-tool-permissions-card" id="mcp-tool-permissions">
			<h2><?php esc_html_e( 'Tool Permissions', 'axtolab-ai-connector' ); ?></h2>
			<p class="description" style="max-width: 820px;">
				<?php esc_html_e( 'Connection tool families decide which categories of tools a connection can use. Sensitive-action consent is a second gate that decides whether an allowed publish, delete, price, or image action runs automatically, asks first, or is blocked.', 'axtolab-ai-connector' ); ?>
			</p>

			<h3 class="mcp-permission-section-heading"><?php esc_html_e( 'Connection tool families', 'axtolab-ai-connector' ); ?></h3>
			<div class="mcp-permission-grid">
				<?php $this->render_connection_permission_card( 'oauth', __( 'OAuth connections', 'axtolab-ai-connector' ), $oauth_caps, $capability_defs ); ?>
			</div>

			<div class="mcp-tool-consent-panel">
				<div class="mcp-tool-consent-heading">
					<h3><?php esc_html_e( 'Sensitive-action consent', 'axtolab-ai-connector' ); ?></h3>
					<span class="mcp-tool-consent-saved" id="mcp-tool-consent-saved"><?php esc_html_e( 'Saved', 'axtolab-ai-connector' ); ?></span>
				</div>
				<p class="description">
					<?php esc_html_e( 'After a connection has the required tool family, choose what each sensitive action should do.', 'axtolab-ai-connector' ); ?>
				</p>
				<div class="mcp-tool-consent-list">
					<?php foreach ( self::tool_consent_actions() as $action => $meta ) : ?>
						<?php $tier = isset( $consent_policy[ $action ] ) ? $consent_policy[ $action ] : 'ask'; ?>
						<div class="mcp-tool-consent-row" data-tool-consent-row="<?php echo esc_attr( $action ); ?>">
							<div class="mcp-tool-consent-copy">
								<strong><?php echo esc_html( $meta['label'] ); ?></strong>
								<span><?php echo esc_html( $meta['description'] ); ?></span>
							</div>
							<fieldset class="mcp-consent-segment" aria-label="<?php echo esc_attr( $meta['label'] ); ?>">
								<?php $this->render_tool_consent_choice( $action, 'always', $tier, __( 'Always allow', 'axtolab-ai-connector' ), 'dashicons-yes-alt' ); ?>
								<?php $this->render_tool_consent_choice( $action, 'ask', $tier, __( 'Ask first', 'axtolab-ai-connector' ), 'hand' ); ?>
								<?php $this->render_tool_consent_choice( $action, 'disallow', $tier, __( 'Block', 'axtolab-ai-connector' ), 'dashicons-dismiss' ); ?>
							</fieldset>
						</div>
					<?php endforeach; ?>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Render one connection capability editor in the visible permissions card.
	 *
	 * @param string $connection Connection key.
	 * @param string $label      Human-readable label.
	 * @param array  $caps       Enabled capability groups.
	 * @param array  $defs       Capability label map.
	 */
	private function render_connection_permission_card( string $connection, string $label, array $caps, array $defs ): void {
		$preset_labels = Axtolab_AI_Connector_Capabilities::preset_labels();
		?>
		<div class="mcp-permission-box" data-permission-connection="<?php echo esc_attr( $connection ); ?>">
			<div class="mcp-permission-box-head">
				<h3><?php echo esc_html( $label ); ?></h3>
				<span class="mcp-permission-saved" id="mcp-visible-<?php echo esc_attr( $connection ); ?>-saved"><?php esc_html_e( 'Saved', 'axtolab-ai-connector' ); ?></span>
			</div>
			<select class="mcp-visible-cap-preset" data-connection="<?php echo esc_attr( $connection ); ?>">
				<?php foreach ( $preset_labels as $preset => $preset_label ) : ?>
					<option value="<?php echo esc_attr( $preset ); ?>"><?php echo esc_html( $preset_label ); ?></option>
				<?php endforeach; ?>
			</select>
			<div class="mcp-visible-cap-list">
				<?php foreach ( $defs as $cap_key => $cap_label ) : ?>
					<label class="mcp-visible-cap-label">
						<input type="checkbox"
							class="mcp-visible-cap-checkbox"
							data-connection="<?php echo esc_attr( $connection ); ?>"
							data-cap="<?php echo esc_attr( $cap_key ); ?>"
							<?php checked( in_array( $cap_key, $caps, true ) ); ?>
							<?php disabled( 'read' === $cap_key ); ?>
						/>
						<span><?php echo esc_html( $cap_label ); ?></span>
						<?php if ( 'read' === $cap_key ) : ?>
							<em><?php esc_html_e( 'always on', 'axtolab-ai-connector' ); ?></em>
						<?php endif; ?>
					</label>
				<?php endforeach; ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Render one consent radio option.
	 *
	 * @param string $action Action key.
	 * @param string $tier   Tier value.
	 * @param string $active Active tier.
	 * @param string $label  Accessible label.
	 * @param string $icon   Dashicon class or custom icon marker.
	 */
	private function render_tool_consent_choice( string $action, string $tier, string $active, string $label, string $icon, string $connection_id = '' ): void {
		$tooltip    = self::tool_consent_choice_tooltip( $tier );
		$input_class = $connection_id ? 'mcp-conn-consent-radio' : 'mcp-tool-consent-radio';
		$name        = $connection_id ? 'mcp_conn_consent_' . sanitize_html_class( $connection_id ) . '_' . $action : 'mcp_tool_consent_' . $action;
		?>
		<label
			class="mcp-consent-choice mcp-consent-choice-<?php echo esc_attr( $tier ); ?>"
			title="<?php echo esc_attr( $tooltip ); ?>"
			data-tooltip="<?php echo esc_attr( $tooltip ); ?>"
			aria-label="<?php echo esc_attr( $tooltip ); ?>"
		>
			<input type="radio"
				class="<?php echo esc_attr( $input_class ); ?>"
				name="<?php echo esc_attr( $name ); ?>"
				value="<?php echo esc_attr( $tier ); ?>"
				data-action="<?php echo esc_attr( $action ); ?>"
				<?php if ( $connection_id ) : ?>
					data-connection="<?php echo esc_attr( $connection_id ); ?>"
				<?php endif; ?>
				<?php checked( $active, $tier ); ?>
			/>
			<?php if ( 'hand' === $icon ) : ?>
				<?php $this->render_tool_consent_hand_icon(); ?>
			<?php else : ?>
				<span class="dashicons <?php echo esc_attr( $icon ); ?>" aria-hidden="true"></span>
			<?php endif; ?>
			<span class="screen-reader-text"><?php echo esc_html( $tooltip ); ?></span>
		</label>
		<?php
	}

	/**
	 * Tooltip copy for consent segment controls.
	 *
	 * @param string $tier Consent tier value.
	 * @return string
	 */
	private static function tool_consent_choice_tooltip( string $tier ): string {
		switch ( $tier ) {
			case 'always':
				return __( 'Always allow: run automatically after connection permissions pass.', 'axtolab-ai-connector' );
			case 'ask':
				return __( 'Ask first: pause and ask for approval before this action runs.', 'axtolab-ai-connector' );
			case 'disallow':
				return __( 'Block: never allow this action, even when the connection has the tool family.', 'axtolab-ai-connector' );
			default:
				return __( 'Choose the consent behavior for this sensitive action.', 'axtolab-ai-connector' );
		}
	}

	/**
	 * Render the Icons8 stop-hand icon used for "Ask first".
	 *
	 * @return void
	 */
	private function render_tool_consent_hand_icon(): void {
		?>
		<svg class="mcp-consent-svg-icon mcp-consent-hand" viewBox="0 0 50 50" focusable="false" aria-hidden="true">
			<path d="M25 0C22.5386 0 21 2.0762 21 4v.5957C20.3675 4.2374 19.6882 4 19 4c-1.9238 0-3.8715 1.5434-3.998 3.9473A1.0001 1.0001 0 0 0 15 8v12.6113C14.1969 19.734 13.2314 19 12 19c-1.1284 0-2.1014.6226-2.5273 1.4414C9.0467 21.2602 9 22.1419 9 23v10.918c0 4.396 1.3095 8.4105 3.8711 11.3457C15.4327 48.1989 19.2523 50 24 50h8c4.9455 0 9-4.0545 9-9V12c0-.7476-.0267-1.8799-.5508-2.9609C39.9251 7.9581 38.7095 7 37 7c-.7877 0-1.4412.2394-2 .5645V7c0-1.519-.253-2.745-.957-3.6602C33.339 2.4247 32.2 2 31 2c-.9094 0-1.6356.2894-2.2559.6797C28.2248 1.2342 26.8963 0 25 0zm0 2c1.3386 0 2 1.1238 2 2v18a1.0001 1.0001 0 0 0 2 0V6c0-.5333.1425-1.0584.416-1.3984C29.6896 4.2615 30.0819 4 31 4c.8 0 1.161.1757 1.457.5605C32.753 4.9454 33 5.719 33 7v17a1.0001 1.0001 0 0 0 2 0V12c0-.6524.0737-1.5189.3496-2.0879C35.6255 9.3431 35.9095 9 37 9s1.3745.3431 1.6504.9121C38.9263 10.4811 39 11.3476 39 12v29c0 3.8545-3.1455 7-7 7h-8c-4.2523 0-7.4327-1.5432-9.6211-4.0508C12.1905 41.4417 11 37.9169 11 33.918V23c0-.7359.099-1.354.2461-1.6367C11.3932 21.0806 11.4204 21 12 21c.5 0 1.3112.4989 1.9492 1.3496C14.5872 23.2003 15 24.3333 15 25v7a1.0001 1.0001 0 1 0 2 0V8.0488C17.0759 6.6564 18.1249 6 19 6c.4619 0 .9945.1781 1.3691.5039C20.7438 6.8297 21 7.2667 21 8v15a1.0001 1.0001 0 0 0 2 0V4c0-.8762.6614-2 2-2z" />
		</svg>
		<?php
	}

	/**
	 * Consent actions rendered in the admin settings UI.
	 *
	 * @return array<string,array{label:string,description:string,requires:string}>
	 */
	private static function tool_consent_actions(): array {
		return array(
			'publish_content'              => array(
				'label'       => __( 'Publish or schedule content', 'axtolab-ai-connector' ),
				'description' => __( 'Posts, pages, and other configured content types.', 'axtolab-ai-connector' ),
				'requires'    => 'publish',
			),
			'trash_content'                => array(
				'label'       => __( 'Move content to trash', 'axtolab-ai-connector' ),
				'description' => __( 'Soft-delete content while keeping it recoverable.', 'axtolab-ai-connector' ),
				'requires'    => 'trash_restore',
			),
			'delete_content'               => array(
				'label'       => __( 'Permanently delete content', 'axtolab-ai-connector' ),
				'description' => __( 'Requires the permanent delete gate as well as this consent policy.', 'axtolab-ai-connector' ),
				'requires'    => 'trash_restore',
			),
			'restore_content'              => array(
				'label'       => __( 'Restore trashed content', 'axtolab-ai-connector' ),
				'description' => __( 'Bring a trashed item back into the workflow.', 'axtolab-ai-connector' ),
				'requires'    => 'trash_restore',
			),
			'restore_revision'             => array(
				'label'       => __( 'Restore a revision', 'axtolab-ai-connector' ),
				'description' => __( 'Replace current content with a previous revision.', 'axtolab-ai-connector' ),
				'requires'    => 'trash_restore',
			),
			'woo_update_product_price'     => array(
				'label'       => __( 'Update WooCommerce prices', 'axtolab-ai-connector' ),
				'description' => __( 'Single-product regular or sale price changes.', 'axtolab-ai-connector' ),
				'requires'    => 'woocommerce',
			),
			'woo_bulk_update_prices'       => array(
				'label'       => __( 'Bulk update WooCommerce prices', 'axtolab-ai-connector' ),
				'description' => __( 'One consent decision covers the whole bulk call.', 'axtolab-ai-connector' ),
				'requires'    => 'woocommerce',
			),
			'woo_create_coupon'            => array(
				'label'       => __( 'Create WooCommerce coupons', 'axtolab-ai-connector' ),
				'description' => __( 'Discount codes, limits, and expiry settings.', 'axtolab-ai-connector' ),
				'requires'    => 'woocommerce',
			),
			'generate_image_in_context'    => array(
				'label'       => __( 'Generate contextual images', 'axtolab-ai-connector' ),
				'description' => __( 'AI-generated images tied to a post or brand context.', 'axtolab-ai-connector' ),
				'requires'    => 'image',
			),
			'batch_regenerate_post_images' => array(
				'label'       => __( 'Batch regenerate post images', 'axtolab-ai-connector' ),
				'description' => __( 'One consent decision covers all images in the batch.', 'axtolab-ai-connector' ),
				'requires'    => 'image',
			),
			'delete_brand_kit'             => array(
				'label'       => __( 'Delete brand kits', 'axtolab-ai-connector' ),
				'description' => __( 'Remove saved image-generation brand guidance.', 'axtolab-ai-connector' ),
				'requires'    => 'image',
			),
		);
	}

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
		$settings          = get_option( 'axtolab_ai_connector_settings', array() );
		$permalink_enabled = ! empty( $settings['permalink_writes_enabled'] );
		$options_enabled   = ! empty( $settings['options_writes_enabled'] );
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

		$key     = isset( $_POST['key'] ) ? sanitize_key( wp_unslash( (string) $_POST['key'] ) ) : '';
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

		wp_send_json_success(
			array(
				'key'     => $key,
				'enabled' => $enabled,
			)
		);
	}

	/**
	 * AJAX: save one tool consent action tier.
	 */
	public function ajax_save_tool_consent_policy(): void {
		check_ajax_referer( self::MENU_SLUG . '-ajax', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'axtolab-ai-connector' ) ), 403 );
		}

		$action = isset( $_POST['action_key'] ) ? sanitize_key( wp_unslash( (string) $_POST['action_key'] ) ) : '';
		$tier   = isset( $_POST['tier'] ) ? sanitize_key( wp_unslash( (string) $_POST['tier'] ) ) : '';

		if ( '' === $action || ! in_array( $tier, array( 'disallow', 'ask', 'always' ), true ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid consent setting.', 'axtolab-ai-connector' ) ), 400 );
		}

		$settings = get_option( 'axtolab_ai_connector_settings', array() );
		if ( ! is_array( $settings ) ) {
			$settings = array();
		}
		if ( empty( $settings['tool_consent_policy'] ) || ! is_array( $settings['tool_consent_policy'] ) ) {
			$settings['tool_consent_policy'] = array();
		}

		$settings['tool_consent_policy'][ $action ] = $tier;
		update_option( 'axtolab_ai_connector_settings', $settings );

		wp_send_json_success(
			array(
				'action_key' => $action,
				'tier'       => $tier,
				'policy'     => Axtolab_AI_Connector_Tool_Consent_Policy::exported_policy(),
			)
		);
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
	 *   `active_app_passwords`  int  — total number of active MCP connections
	 *                                  (App Password + OAuth)
	 *
	 * @return array{
	 *     active_app_passwords: int,
	 * }
	 */
	public function get_setup_status(): array {
		$connections = Axtolab_AI_Connector_Connections::get_all_connections();

		return array(
			'active_app_passwords' => count( $connections ),
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
	 * AJAX: Update per-connection sensitive-action consent.
	 *
	 * Expects POST params: connection_id, action_key, tier.
	 *
	 * @return void Sends JSON and exits.
	 */
	public function ajax_update_connection_consent(): void {
		check_ajax_referer( self::MENU_SLUG . '-ajax', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'axtolab-ai-connector' ) ), 403 );
		}

		$connection_id = isset( $_POST['connection_id'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['connection_id'] ) ) : '';
		$action        = isset( $_POST['action_key'] ) ? sanitize_key( wp_unslash( (string) $_POST['action_key'] ) ) : '';
		$tier          = isset( $_POST['tier'] ) ? sanitize_key( wp_unslash( (string) $_POST['tier'] ) ) : '';

		if ( '' === $connection_id || '' === $action || ! in_array( $tier, array( 'disallow', 'ask', 'always' ), true ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid behavior setting.', 'axtolab-ai-connector' ) ), 400 );
		}

		$result = Axtolab_AI_Connector_Connections::set_tool_consent_tier( $connection_id, $action, $tier );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ), 400 );
		}

		wp_send_json_success(
			array(
				'message'     => __( 'Behavior updated.', 'axtolab-ai-connector' ),
				'connection'  => $connection_id,
				'action_key'  => $action,
				'tier'        => $tier,
				'policy'      => Axtolab_AI_Connector_Tool_Consent_Policy::exported_policy( $connection_id ),
			)
		);
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
	 * AJAX: Verify a pasted Application Password against the site's own REST API.
	 *
	 * The wizard's "Verify" button calls this. We make a Basic-auth GET against
	 * `/wp-json/wp/v2/users/me` so WordPress's native Application Password
	 * verification path runs end-to-end (no parallel password store, no
	 * trust-on-paste shortcuts). On success we return the resolved user's id,
	 * login, display name, role labels, and a non-blocking warning when the
	 * underlying user has very limited capabilities (e.g. Subscriber).
	 *
	 * The plaintext App Password is read from $_POST and never persisted at
	 * this step — it is only passed back to WordPress over loopback.
	 *
	 * Nonce: `{MENU_SLUG}-ajax`.
	 *
	 * @return void Sends JSON and exits.
	 */

	/**
	 * Verify a normalised plaintext Application Password against a stored hash.
	 *
	 * Wraps WP_Application_Passwords::check_password() when present (WP 6.8+,
	 * which dispatches `$generic$` hashes through wp_verify_fast_hash() and
	 * legacy hashes through wp_check_password()), and falls back to manual
	 * dispatch for older WordPress versions that lack the static helper.
	 *
	 * Calling wp_check_password() alone is insufficient on WP 6.8+ because it
	 * does not recognise the new wp_fast_hash output format used for
	 * Application Passwords by default — every comparison returns false even
	 * when the password is valid.
	 *
	 * The caller MUST have already stripped non-alphanumerics from $password
	 * via preg_replace( '/[^a-z\d]/i', '', ... ) to match what WordPress's own
	 * wp_authenticate_application_password() does before hashing.
	 *
	 * @param string $password   Plaintext password, already normalised.
	 * @param string $hash       Stored hash from get_user_application_passwords().
	 * @param int    $wp_user_id The user the password belongs to (for legacy hasher).
	 * @return bool True on match.
	 */
	private static function verify_app_password_hash( string $password, string $hash, int $wp_user_id ): bool {
		if ( method_exists( 'WP_Application_Passwords', 'check_password' ) ) {
			return (bool) WP_Application_Passwords::check_password( $password, $hash );
		}
		// Older WordPress without the static helper: dispatch manually. Keep
		// this optional WP 6.8+ helper callable safe for our WP 6.2 minimum.
		$verify_fast_hash = 'wp_verify_fast_hash';
		if ( function_exists( $verify_fast_hash ) && 0 === strpos( $hash, '$generic$' ) ) {
			return (bool) call_user_func( $verify_fast_hash, $password, $hash );
		}
		return (bool) wp_check_password( $password, $hash, $wp_user_id );
	}

	public function ajax_wizard_verify(): void {
		check_ajax_referer( self::MENU_SLUG . '-ajax', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'axtolab-ai-connector' ) ), 403 );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce verified above. Application Passwords are space-separated alphanumeric strings; sanitize_text_field() would collapse the whitespace WordPress's own validator needs.
		$app_password = isset( $_POST['app_password'] ) ? trim( wp_unslash( (string) $_POST['app_password'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified above via check_ajax_referer.
		$username = isset( $_POST['username'] ) ? sanitize_user( wp_unslash( (string) $_POST['username'] ), true ) : '';

		if ( '' === $app_password ) {
			wp_send_json_error( array( 'message' => __( 'Paste the Application Password before verifying.', 'axtolab-ai-connector' ) ), 400 );
		}

		// Default to the currently logged-in admin's login when none provided.
		if ( '' === $username ) {
			$current_user = wp_get_current_user();
			$username     = $current_user instanceof WP_User ? $current_user->user_login : '';
		}

		if ( '' === $username ) {
			wp_send_json_error( array( 'message' => __( 'Enter the WordPress username the Application Password belongs to.', 'axtolab-ai-connector' ) ), 400 );
		}

		// Round-trip through the site's own REST API so we exercise the
		// standard wp_validate_application_password() code path.
		$url = rest_url( 'wp/v2/users/me' );
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Standard HTTP Basic Auth header construction, not obfuscation.
		$authorization = 'Basic ' . base64_encode( $username . ':' . $app_password );

		$response = wp_remote_get(
			$url,
			array(
				'timeout'   => 10,
				'sslverify' => false, // Loopback may be HTTP or self-signed.
				'headers'   => array(
					'Authorization' => $authorization,
					'Accept'        => 'application/json',
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			wp_send_json_error( array( 'message' => $response->get_error_message() ), 500 );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );
		$json = json_decode( $body, true );

		if ( 200 !== $code || ! is_array( $json ) || empty( $json['id'] ) ) {
			$message = is_array( $json ) && ! empty( $json['message'] )
				? (string) $json['message']
				: __( 'WordPress rejected the username + Application Password combination.', 'axtolab-ai-connector' );
			wp_send_json_error( array( 'message' => $message ), 401 );
		}

		$user_id = (int) $json['id'];
		$user    = get_user_by( 'id', $user_id );
		if ( ! $user instanceof WP_User ) {
			wp_send_json_error( array( 'message' => __( 'The resolved user could not be loaded.', 'axtolab-ai-connector' ) ), 500 );
		}

		// Check for App Password collisions against existing registered MCP connections.
		// Mirror WordPress core's app-password normalisation + verification —
		// see verify_app_password_hash() for why wp_check_password() alone is
		// not enough on WP 6.8+ (it cannot read "$generic$" fast hashes).
		$normalised_password = preg_replace( '/[^a-z\d]/i', '', $app_password );
		$collision           = null;
		$user_passwords      = class_exists( 'WP_Application_Passwords' )
			? WP_Application_Passwords::get_user_application_passwords( $user_id )
			: array();
		if ( is_array( $user_passwords ) ) {
			foreach ( $user_passwords as $pwd ) {
				if ( ! self::verify_app_password_hash( $normalised_password, $pwd['password'], $user_id ) ) {
					continue;
				}
				$existing = Axtolab_AI_Connector_Connections::get_by_uuid( $pwd['uuid'] );
				if ( $existing ) {
					$collision = array(
						'connection_id' => $pwd['uuid'],
						'label'         => isset( $existing['client_label'] ) ? $existing['client_label'] : '',
					);
				}
				break;
			}
		}

		$role_labels = array();
		global $wp_roles;
		foreach ( (array) $user->roles as $role_key ) {
			$role_labels[] = isset( $wp_roles->role_names[ $role_key ] )
				? translate_user_role( $wp_roles->role_names[ $role_key ] )
				: $role_key;
		}

		$warning = '';
		if ( ! user_can( $user, 'edit_posts' ) ) {
			$warning = __( 'This user has very limited WordPress capabilities — the AI may be blocked from most actions even when the connection allows them. Editor or higher is usually the right floor.', 'axtolab-ai-connector' );
		}

		wp_send_json_success(
			array(
				'user_id'      => $user_id,
				'user_login'   => $user->user_login,
				'user_display' => $user->display_name,
				'user_roles'   => $role_labels,
				'warning'      => $warning,
				'collision'    => $collision,
			)
		);
	}

	/**
	 * AJAX: Finalise the wizard and create the connection.
	 *
	 * Re-verifies the App Password against WordPress's REST API (so the
	 * plaintext is never trusted from session state), then records the
	 * connection in the registry with the resolved wp_user_id, capabilities,
	 * and client metadata. Returns a wmcp1_ connection token built from the
	 * same credentials so the admin can paste it into Claude Desktop / the
	 * MCP client.
	 *
	 * Nonce: `{MENU_SLUG}-ajax`.
	 *
	 * @return void Sends JSON and exits.
	 */
	public function ajax_wizard_create(): void {
		check_ajax_referer( self::MENU_SLUG . '-ajax', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'axtolab-ai-connector' ) ), 403 );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce verified above. Application Passwords are space-separated alphanumeric strings; sanitize_text_field() would collapse the whitespace WordPress's own validator needs.
		$app_password = isset( $_POST['app_password'] ) ? trim( wp_unslash( (string) $_POST['app_password'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified above via check_ajax_referer.
		$username = isset( $_POST['username'] ) ? sanitize_user( wp_unslash( (string) $_POST['username'] ), true ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified above via check_ajax_referer.
		$label = isset( $_POST['label'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['label'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified above via check_ajax_referer.
		$client_type = isset( $_POST['client_type'] ) ? sanitize_key( wp_unslash( (string) $_POST['client_type'] ) ) : 'other';
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified above via check_ajax_referer.
		$capabilities = isset( $_POST['capabilities'] ) ? array_map( 'sanitize_key', (array) wp_unslash( $_POST['capabilities'] ) ) : array();

		if ( '' === $app_password ) {
			wp_send_json_error( array( 'message' => __( 'Application Password is required.', 'axtolab-ai-connector' ) ), 400 );
		}

		if ( '' === $username ) {
			$current_user = wp_get_current_user();
			$username     = $current_user instanceof WP_User ? $current_user->user_login : '';
		}

		// Resolve the user via WordPress's own REST API + Application Password validation.
		$url = rest_url( 'wp/v2/users/me' );
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Standard HTTP Basic Auth header construction, not obfuscation.
		$authorization = 'Basic ' . base64_encode( $username . ':' . $app_password );

		$response = wp_remote_get(
			$url,
			array(
				'timeout'   => 10,
				'sslverify' => false,
				'headers'   => array(
					'Authorization' => $authorization,
					'Accept'        => 'application/json',
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			wp_send_json_error( array( 'message' => $response->get_error_message() ), 500 );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$json = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 200 !== $code || ! is_array( $json ) || empty( $json['id'] ) ) {
			wp_send_json_error( array( 'message' => __( 'WordPress rejected the username + Application Password combination.', 'axtolab-ai-connector' ) ), 401 );
		}

		$wp_user_id = (int) $json['id'];
		$user       = get_user_by( 'id', $wp_user_id );
		if ( ! $user instanceof WP_User ) {
			wp_send_json_error( array( 'message' => __( 'The resolved user could not be loaded.', 'axtolab-ai-connector' ) ), 500 );
		}

		// Locate the UUID of the App Password we just verified by hashing
		// against the user's stored App Passwords. WordPress does not return
		// the UUID over /users/me, so we walk the list here.
		if ( ! class_exists( 'WP_Application_Passwords' ) ) {
			wp_send_json_error( array( 'message' => __( 'Application Passwords are not available on this site.', 'axtolab-ai-connector' ) ), 500 );
		}

		// Mirror WordPress core's wp_authenticate_application_password() exactly:
		//   1. Strip non-alphanumerics from the pasted password (WP core does
		//      this so the spaced display form "abcd EFGH 1234 ZYXW" and the
		//      stripped form "abcdEFGH1234ZYXW" both validate).
		//   2. Verify via WP_Application_Passwords::check_password(), which
		//      dispatches to wp_verify_fast_hash() for WP 6.8+ "$generic$"
		//      hashes and to wp_check_password() for legacy phpass hashes.
		//      Calling wp_check_password() directly fails on the new fast-hash
		//      format because it does not recognise the "$generic$" prefix —
		//      which is why every comparison silently failed in 1.0.1 with
		//      "Could not match the Application Password to a stored record".
		$normalised_password = preg_replace( '/[^a-z\d]/i', '', $app_password );
		$matched_uuid        = '';
		$passwords           = WP_Application_Passwords::get_user_application_passwords( $wp_user_id );
		if ( is_array( $passwords ) ) {
			foreach ( $passwords as $pwd ) {
				if ( self::verify_app_password_hash( $normalised_password, $pwd['password'], $wp_user_id ) ) {
					$matched_uuid = $pwd['uuid'];
					break;
				}
			}
		}

		if ( '' === $matched_uuid ) {
			wp_send_json_error( array( 'message' => __( 'Could not match the Application Password to a stored record. Try again.', 'axtolab-ai-connector' ) ), 500 );
		}

		// Sanitise client type against the known set.
		$allowed_types = array( 'claude_desktop', 'cli', 'cowork', 'chatgpt', 'claude_web', 'other', 'unknown' );
		if ( ! in_array( $client_type, $allowed_types, true ) ) {
			$client_type = 'other';
		}

		if ( '' === $label ) {
			/* translators: %s: client type label */
			$label = sprintf( __( 'MCP Connection (%s)', 'axtolab-ai-connector' ), Axtolab_AI_Connector_Connections::client_type_label( $client_type ) );
		}

		Axtolab_AI_Connector_Connections::register_connection(
			$matched_uuid,
			$wp_user_id,
			array(
				'client_type'  => $client_type,
				'client_label' => $label,
				'auth_method'  => 'app_password',
			)
		);

		// Persist the caller's capability selection (defaults to Standard
		// preset when none supplied).
		if ( empty( $capabilities ) ) {
			$capabilities = Axtolab_AI_Connector_Capabilities::DEFAULT_PRESET;
		}
		Axtolab_AI_Connector_Connections::set_capabilities( $matched_uuid, $capabilities );

		// Build the connection token using the same credentials.
		$token = Axtolab_AI_Connector_Token_Auth::build_connection_token( $user->user_login, $app_password );
		if ( is_wp_error( $token ) ) {
			wp_send_json_error( array( 'message' => $token->get_error_message() ), 500 );
		}

		wp_send_json_success(
			array(
				'message'       => __( 'Connection created.', 'axtolab-ai-connector' ),
				'connection_id' => $matched_uuid,
				'token'         => $token,
				'wp_user_id'    => $wp_user_id,
				'wp_user_login' => $user->user_login,
			)
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

		$settings                       = get_option( 'axtolab_ai_connector_settings', array() );
		$settings['remote_mcp_enabled'] = $enabled;
		// Drop the legacy key if it lingered after the R6 collapse.
		unset( $settings['oauth_enabled'] );
		update_option( 'axtolab_ai_connector_settings', $settings );

		// Write .htaccess rules when enabling (for plugins already active before OAuth was added).
		if ( $enabled && function_exists( 'axtolab_ai_connector_write_oauth_htaccess_rules' ) ) {
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
		if ( 'oauth' !== $connection ) {
			wp_send_json_error( array( 'message' => __( 'Invalid connection type.', 'axtolab-ai-connector' ) ) );
		}

		// Parse capabilities array from POST.
		$raw_caps   = isset( $_POST['capabilities'] ) ? array_map( 'sanitize_text_field', (array) wp_unslash( $_POST['capabilities'] ) ) : array();
		$valid_caps = Axtolab_AI_Connector_Capabilities::all_groups();
		$caps       = array_values( array_intersect( $raw_caps, $valid_caps ) );

		// Ensure 'read' is always included.
		if ( ! in_array( 'read', $caps, true ) ) {
			$caps[] = 'read';
		}

		$settings                         = get_option( 'axtolab_ai_connector_settings', array() );
		$settings['oauth_capabilities']   = $caps;
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
.mcp-tabs { display: flex; flex-wrap: wrap; gap: 0; border-bottom: 1px solid #c3c4c7; margin-bottom: 16px; }
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

/* Visible tool permissions */
.mcp-tool-permissions-card { max-width: 1100px; }
.mcp-permission-section-heading {
	margin: 18px 0 10px;
	font-size: 14px;
}
.mcp-permission-grid {
	display: grid;
	grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
	gap: 16px;
}
.mcp-permission-box {
	border: 1px solid #dcdcde;
	border-radius: 6px;
	background: #fff;
	padding: 14px;
}
.mcp-permission-box-head,
.mcp-tool-consent-heading {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 12px;
	margin-bottom: 10px;
}
.mcp-permission-box h3,
.mcp-tool-consent-heading h3 {
	margin: 0;
	font-size: 14px;
}
.mcp-visible-cap-preset { width: 100%; max-width: 280px; margin-bottom: 12px; }
.mcp-visible-cap-list {
	display: grid;
	grid-template-columns: repeat(auto-fit, minmax(190px, 1fr));
	gap: 7px 14px;
}
.mcp-visible-cap-label {
	display: flex;
	align-items: center;
	gap: 6px;
	font-size: 13px;
}
.mcp-visible-cap-label em { color: #888; font-size: 12px; }
.mcp-permission-saved,
.mcp-tool-consent-saved {
	color: #00a32a;
	font-size: 13px;
	font-weight: 600;
	opacity: 0;
	transition: opacity 0.25s;
}
.mcp-permission-saved.visible,
.mcp-tool-consent-saved.visible { opacity: 1; }
.mcp-tool-consent-panel {
	margin-top: 18px;
	border-top: 1px solid #dcdcde;
	padding-top: 16px;
}
.mcp-tool-consent-list {
	display: grid;
	gap: 8px;
	margin-top: 12px;
}
.mcp-tool-consent-row {
	display: grid;
	grid-template-columns: minmax(240px, 1fr) auto;
	align-items: center;
	gap: 16px;
	padding: 10px 12px;
	border: 1px solid #e0e0e0;
	border-radius: 6px;
	background: #fff;
}
.mcp-tool-consent-copy strong {
	display: block;
	margin-bottom: 2px;
}
.mcp-tool-consent-copy span {
	color: #646970;
	font-size: 12px;
}
.mcp-consent-segment {
	display: inline-grid;
	grid-template-columns: repeat(3, 40px);
	gap: 4px;
	margin: 0;
	padding: 3px;
	border: 0;
	border-radius: 8px;
	background: #f0f0f1;
}
.mcp-consent-choice {
	position: relative;
	display: inline-flex;
	align-items: center;
	justify-content: center;
	width: 40px;
	height: 34px;
	border-radius: 6px;
	color: #646970;
	cursor: pointer;
}
.mcp-consent-choice input {
	position: absolute;
	opacity: 0;
	pointer-events: none;
}
.mcp-consent-choice .dashicons,
.mcp-consent-choice .mcp-consent-svg-icon {
	width: 20px;
	height: 20px;
	font-size: 20px;
	line-height: 20px;
	text-align: center;
}
.mcp-consent-choice .mcp-consent-svg-icon {
	display: block;
	fill: currentColor;
}
.mcp-consent-choice:focus-within {
	outline: 2px solid #2271b1;
	outline-offset: 2px;
}
.mcp-consent-choice::after {
	position: absolute;
	bottom: calc(100% + 8px);
	left: 50%;
	z-index: 1000;
	width: max-content;
	max-width: 260px;
	padding: 7px 9px;
	border-radius: 6px;
	background: #1d2327;
	color: #fff;
	font-size: 12px;
	font-weight: 400;
	line-height: 1.35;
	text-align: left;
	white-space: normal;
	box-shadow: 0 8px 20px rgba(0,0,0,0.18);
	content: attr(data-tooltip);
	opacity: 0;
	pointer-events: none;
	transform: translate(-50%, 4px);
	transition: opacity 0.12s ease, transform 0.12s ease;
}
.mcp-consent-choice-always::after {
	left: 0;
	transform: translateY(4px);
}
.mcp-consent-choice-disallow::after {
	right: 0;
	left: auto;
	transform: translateY(4px);
}
.mcp-consent-choice:hover::after,
.mcp-consent-choice:focus-within::after {
	opacity: 1;
	transform: translate(-50%, 0);
}
.mcp-consent-choice-always:hover::after,
.mcp-consent-choice-always:focus-within::after,
.mcp-consent-choice-disallow:hover::after,
.mcp-consent-choice-disallow:focus-within::after {
	transform: translateY(0);
}
.mcp-consent-choice:has(input:checked) {
	background: #646970;
	color: #fff;
	box-shadow: inset 0 0 0 1px rgba(255,255,255,0.22);
}
.mcp-consent-choice-always:has(input:checked) { background: #1f7a4d; }
.mcp-consent-choice-ask:has(input:checked) { background: #646970; }
.mcp-consent-choice-disallow:has(input:checked) { background: #8a2424; }
@media (max-width: 782px) {
	.mcp-tool-consent-row { grid-template-columns: 1fr; }
	.mcp-consent-segment { justify-self: start; }
}

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
.mcp-conn-caps-editor { max-width: 980px; }
.mcp-conn-access-section { margin-bottom: 10px; }
.mcp-conn-section-title { margin: 0 0 4px; font-size: 13px; }
.mcp-conn-caps-checkboxes { display: grid; grid-template-columns: 1fr 1fr; gap: 4px 16px; margin-bottom: 8px; }
.mcp-conn-cap-label { display: flex; align-items: center; gap: 4px; font-size: 13px; }
.mcp-conn-cap-note { color: #888; font-size: 12px; }
.mcp-conn-caps-saved { color: #00a32a; font-size: 13px; font-weight: 500; }
.mcp-conn-behavior-panel { margin-top: 16px; padding-top: 14px; border-top: 1px solid #e0e0e0; }
.mcp-conn-behavior-head { display: flex; align-items: flex-start; justify-content: space-between; gap: 12px; margin-bottom: 10px; }
.mcp-conn-behavior-head h4 { margin: 0 0 4px; font-size: 13px; }
.mcp-conn-behavior-head .mcp-help-text { margin: 0; }
.mcp-conn-behavior-saved { color: #00a32a; font-size: 13px; font-weight: 500; opacity: 0; transition: opacity 0.3s; }
.mcp-conn-behavior-list { max-width: 760px; }
.mcp-tool-consent-meta { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; margin-top: 4px; }
.mcp-tool-consent-requires {
	display: inline-flex;
	align-items: center;
	min-height: 18px;
	padding: 1px 7px;
	border-radius: 3px;
	background: #f0f6fc;
	color: #1d4f73;
	font-size: 11px;
	font-weight: 600;
}
.mcp-tool-consent-access-note { display: none; color: #8a2424; font-size: 12px; }
.mcp-tool-consent-row-disabled { background: #f6f7f7; }
.mcp-tool-consent-row-disabled .mcp-tool-consent-copy { color: #646970; }
.mcp-tool-consent-row-disabled .mcp-consent-segment { opacity: 0.42; }
.mcp-tool-consent-row-disabled .mcp-tool-consent-requires { background: #f0f0f1; color: #646970; }
.mcp-tool-consent-row-disabled .mcp-tool-consent-access-note { display: inline; }
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
        full_access:     ['read','create_edit','publish','trash_restore','media_manage','taxonomy','authors','seo','image','upload_portal','ai_actions','woocommerce'],
        standard:        ['read','create_edit','publish','media_manage','taxonomy','authors','seo','image','upload_portal','ai_actions','woocommerce'],
        draft_only:      ['read','create_edit','media_manage','taxonomy','seo','image','upload_portal','ai_actions'],
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

    function connSyncBehaviorAccess(connId) {
        var enabled = {};
        $('[data-connection="' + connId + '"].mcp-conn-cap-checkbox:checked').each(function() {
            enabled[$(this).data('cap')] = true;
        });

        $('.mcp-connection-caps-row[data-id="' + connId + '"] .mcp-tool-consent-row').each(function() {
            var $row = $(this);
            var required = $row.data('required-cap');
            var allowed = !required || !!enabled[required];
            $row.toggleClass('mcp-tool-consent-row-disabled', !allowed);
            $row.attr('aria-disabled', allowed ? 'false' : 'true');
            $row.find('.mcp-consent-segment').prop('disabled', !allowed);
            $row.find('.mcp-conn-consent-radio').prop('disabled', !allowed);
        });
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
        connSyncBehaviorAccess(connId);
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
        connUpdatePresetUI(connId);
        connSyncBehaviorAccess(connId);
        connAutoSave(connId);
    });

    $(document).on('change', '.mcp-conn-cap-checkbox', function() {
        var connId = $(this).data('connection');
        connUpdatePresetUI(connId);
        connSyncBehaviorAccess(connId);
        connAutoSave(connId);
    });

    var connConsentTimers = {};
    function connAutoSaveConsent(connId, actionKey, tier) {
        clearTimeout(connConsentTimers[connId + ':' + actionKey]);
        connConsentTimers[connId + ':' + actionKey] = setTimeout(function() {
            doAjax(
                cfg.actions.updateConnectionConsent,
                { connection_id: connId, action_key: actionKey, tier: tier },
                function() {
                    var $s = $('[data-id="' + connId + '"].mcp-connection-caps-row .mcp-conn-behavior-saved');
                    $s.css('opacity', 1);
                    setTimeout(function() { $s.css('opacity', 0); }, 1500);
                },
                function(msg) { alert(msg || 'Failed to save behavior.'); }
            );
        }, 250);
    }

    $(document).on('change', '.mcp-conn-consent-radio', function() {
        var $radio = $(this);
        if ($radio.prop('disabled') || $radio.closest('.mcp-tool-consent-row-disabled').length) return;
        connAutoSaveConsent($radio.data('connection'), $radio.data('action'), $radio.val());
    });

    // Init presets on load
    $('.mcp-connection-caps-row').each(function() {
        var connId = $(this).data('id');
        connUpdatePresetUI(connId);
        connSyncBehaviorAccess(connId);
    });

    // ── Tab switching ─────────────────────────────────────────────────────────
    $(document).on('click', '.mcp-tab', function () {
        var tab = $(this).data('tab');
        $('.mcp-tab').removeClass('mcp-tab-active');
        $(this).addClass('mcp-tab-active');
        $('.mcp-tab-content').removeClass('mcp-tab-content-active');
        $('.mcp-tab-content[data-tab="' + tab + '"]').addClass('mcp-tab-content-active');
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
    // Single source of truth for "Remote MCP for Web Clients (via OAuth)".
    // Reload after save so the conditional UI (endpoint URL, capabilities,
    // setup instructions) renders or hides without a manual refresh.
    $(document).on('change', '#mcp-toggle-remote', function () {
        var $checkbox = $(this);
        var $msg      = $('#mcp-toggle-remote-message');
        var enabled   = $checkbox.is(':checked') ? 1 : 0;

        $msg.text('Saving…').removeClass('is-success is-error');

        doAjax(
            'axtolab_ai_connector_toggle_remote',
            { enabled: enabled },
            function (data) {
                var label = data.enabled ? 'Remote MCP enabled.' : 'Remote MCP disabled.';
                $msg.text(label).removeClass('is-error').addClass('is-success');
                // Reload so the conditional sub-sections render.
                window.setTimeout(function () { window.location.reload(); }, 400);
            },
            function (errMsg) {
                // Revert the checkbox on failure.
                $checkbox.prop('checked', !$checkbox.is(':checked'));
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
        standard:        ['read', 'create_edit', 'publish', 'media_manage', 'taxonomy', 'authors', 'seo', 'image', 'upload_portal', 'ai_actions', 'woocommerce'],
        full_access:     ['read', 'create_edit', 'publish', 'trash_restore', 'media_manage', 'taxonomy', 'authors', 'seo', 'image', 'upload_portal', 'ai_actions', 'woocommerce'],
        draft_only:      ['read', 'create_edit', 'media_manage', 'taxonomy', 'seo', 'image', 'upload_portal', 'ai_actions'],
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

    function detectPresetForSelector(conn, selector) {
        var currentCaps = [];
        $('[data-connection="' + conn + '"].' + selector + ':checked').each(function () {
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

    function detectPreset(conn) {
        return detectPresetForSelector(conn, 'mcp-cap-checkbox');
    }

    function detectVisiblePreset(conn) {
        return detectPresetForSelector(conn, 'mcp-visible-cap-checkbox');
    }

    function updateCapUI(conn) {
        var preset = detectPreset(conn);
        $('[data-connection="' + conn + '"].mcp-cap-preset').val(preset);
        var $badge = $('#mcp-' + conn + '-cap-badge');
        $badge.text(capPresetLabels[preset])
              .removeClass(Object.values(capBadgeClasses).join(' '))
              .addClass(capBadgeClasses[preset] || capBadgeClasses.custom);
    }

    function updateVisibleCapUI(conn) {
        var preset = detectVisiblePreset(conn);
        $('[data-connection="' + conn + '"].mcp-visible-cap-preset').val(preset);
    }

    function readCaps(conn, selector) {
        var caps = [];
        $('[data-connection="' + conn + '"].' + selector + ':checked').each(function () {
            caps.push($(this).data('cap'));
        });
        if (caps.indexOf('read') === -1) caps.push('read');
        return caps;
    }

    function syncCaps(conn, caps) {
        ['mcp-cap-checkbox', 'mcp-visible-cap-checkbox'].forEach(function (selector) {
            $('[data-connection="' + conn + '"].' + selector).each(function () {
                var cap = $(this).data('cap');
                $(this).prop('checked', cap === 'read' || caps.indexOf(cap) !== -1);
            });
        });
        updateCapUI(conn);
        updateVisibleCapUI(conn);
    }

    function autoSaveCaps(conn) {
        clearTimeout(capSaveTimers[conn]);
        capSaveTimers[conn] = setTimeout(function () {
            var caps = readCaps(conn, 'mcp-cap-checkbox');

            doAjax(
                'axtolab_ai_connector_save_capabilities',
                { connection: conn, capabilities: caps },
                function () {
                    syncCaps(conn, caps);
                    var $saved = $('#mcp-' + conn + '-saved');
                    $saved.addClass('visible');
                    setTimeout(function () { $saved.removeClass('visible'); }, 1500);
                },
                function () {}
            );
        }, 500);
    }

    function autoSaveVisibleCaps(conn) {
        clearTimeout(capSaveTimers['visible-' + conn]);
        capSaveTimers['visible-' + conn] = setTimeout(function () {
            var caps = readCaps(conn, 'mcp-visible-cap-checkbox');

            doAjax(
                'axtolab_ai_connector_save_capabilities',
                { connection: conn, capabilities: caps },
                function () {
                    syncCaps(conn, caps);
                    var $saved = $('#mcp-visible-' + conn + '-saved');
                    $saved.addClass('visible');
                    setTimeout(function () { $saved.removeClass('visible'); }, 1500);
                },
                function (msg) { alert(msg || 'Failed to save permissions.'); }
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

    $(document).on('change', '.mcp-visible-cap-preset', function () {
        var conn   = $(this).data('connection');
        var preset = $(this).val();
        if (preset === 'custom') return;

        var caps = capPresets[preset] || [];
        $('[data-connection="' + conn + '"].mcp-visible-cap-checkbox').each(function () {
            var cap = $(this).data('cap');
            if (cap === 'read') return;
            $(this).prop('checked', caps.indexOf(cap) !== -1);
        });

        updateVisibleCapUI(conn);
        autoSaveVisibleCaps(conn);
    });

    $(document).on('change', '.mcp-visible-cap-checkbox', function () {
        var conn = $(this).data('connection');
        updateVisibleCapUI(conn);
        autoSaveVisibleCaps(conn);
    });

    $(document).on('change', '.mcp-tool-consent-radio', function () {
        var $input = $(this);
        doAjax(
            cfg.actions.saveToolConsentPolicy,
            {
                action_key: $input.data('action'),
                tier: $input.val()
            },
            function () {
                var $saved = $('#mcp-tool-consent-saved');
                $saved.addClass('visible');
                setTimeout(function () { $saved.removeClass('visible'); }, 1500);
            },
            function (msg) { alert(msg || 'Failed to save consent policy.'); }
        );
    });

    // Initialize badges on page load.
    ['oauth'].forEach(function (conn) {
        updateCapUI(conn);
        updateVisibleCapUI(conn);
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
