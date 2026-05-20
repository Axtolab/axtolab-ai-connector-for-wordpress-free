<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Axtolab_AI_Connector_REST', false ) ) :
final class Axtolab_AI_Connector_REST {
	private const NS = 'axtolab-ai-connector/v1';

	public static function bootstrap(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	public static function register_routes(): void {
		register_rest_route(
			self::NS,
			'/site-info',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'handle_site_info' ),
				'permission_callback' => array( __CLASS__, 'permission_authenticated' ),
			)
		);

		register_rest_route(
			self::NS,
			'/health-check',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'handle_health_check' ),
				'permission_callback' => array( __CLASS__, 'permission_authenticated' ),
			)
		);

		// Alias of /health-check. Some MCP clients and external probes
		// look for /ping by convention; this keeps the contract minimal
		// without splitting the implementation.
		register_rest_route(
			self::NS,
			'/ping',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'handle_health_check' ),
				'permission_callback' => array( __CLASS__, 'permission_authenticated' ),
			)
		);

		register_rest_route(
			self::NS,
			'/my-capabilities',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'handle_my_capabilities' ),
				'permission_callback' => array( __CLASS__, 'permission_authenticated' ),
			)
		);

		register_rest_route(
			self::NS,
			'/changelog',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'handle_list_changelog' ),
				'permission_callback' => array( __CLASS__, 'permission_view_changelog' ),
			)
		);

		register_rest_route(
			self::NS,
			'/changelog/(?P<id>\d+)',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'handle_get_change' ),
				'permission_callback' => array( __CLASS__, 'permission_view_changelog' ),
			)
		);

		register_rest_route(
			self::NS,
			'/changelog/(?P<id>\d+)/rollback',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'handle_rollback_change' ),
				'permission_callback' => array( __CLASS__, 'permission_admin' ),
			)
		);

		register_rest_route(
			self::NS,
			'/changelog/(?P<id>\d+)/redo',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'handle_redo_change' ),
				'permission_callback' => array( __CLASS__, 'permission_admin' ),
			)
		);

		register_rest_route(
			self::NS,
			'/changelog/session/(?P<session_id>[A-Za-z0-9_-]+)/rollback',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'handle_rollback_session' ),
				'permission_callback' => array( __CLASS__, 'permission_admin' ),
			)
		);

		register_rest_route(
			self::NS,
			'/content-types',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'handle_content_types' ),
				'permission_callback' => array( __CLASS__, 'permission_authenticated' ),
			)
		);

		register_rest_route(
			self::NS,
			'/content',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( __CLASS__, 'handle_find_content' ),
					'permission_callback' => array( __CLASS__, 'permission_authenticated' ),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( __CLASS__, 'handle_create_content' ),
					'permission_callback' => array( __CLASS__, 'permission_authenticated' ),
				),
			)
		);

		register_rest_route(
			self::NS,
			'/content/(?P<id>\d+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( __CLASS__, 'handle_get_content' ),
					'permission_callback' => array( __CLASS__, 'permission_read_post' ),
				),
				array(
					'methods'             => 'PATCH',
					'callback'            => array( __CLASS__, 'handle_update_content' ),
					'permission_callback' => array( __CLASS__, 'permission_edit_post' ),
				),
			)
		);

		register_rest_route(
			self::NS,
			'/content/(?P<id>\d+)/publish',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'handle_publish_content' ),
				'permission_callback' => array( __CLASS__, 'permission_publish_post' ),
			)
		);

		register_rest_route(
			self::NS,
			'/content/(?P<id>\d+)/trash',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'handle_trash_content' ),
				'permission_callback' => array( __CLASS__, 'permission_delete_post' ),
			)
		);

		register_rest_route(
			self::NS,
			'/content/(?P<id>\d+)/restore',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'handle_restore_content' ),
				'permission_callback' => array( __CLASS__, 'permission_delete_post' ),
			)
		);

		register_rest_route(
			self::NS,
			'/content/(?P<id>\d+)/revisions',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'handle_list_revisions' ),
				'permission_callback' => array( __CLASS__, 'permission_read_post' ),
			)
		);

		register_rest_route(
			self::NS,
			'/content/(?P<id>\d+)/revisions/(?P<revision_id>\d+)/restore',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'handle_restore_revision' ),
				'permission_callback' => array( __CLASS__, 'permission_edit_post' ),
			)
		);

		register_rest_route(
			self::NS,
			'/authors',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'handle_list_authors' ),
				'permission_callback' => array( __CLASS__, 'permission_authenticated' ),
			)
		);

		register_rest_route(
			self::NS,
			'/content/(?P<id>\d+)/author',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'handle_assign_author' ),
				'permission_callback' => array( __CLASS__, 'permission_assign_author' ),
			)
		);

		register_rest_route(
			self::NS,
			'/taxonomies/(?P<taxonomy>[a-zA-Z0-9_\-]+)/terms',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( __CLASS__, 'handle_list_terms' ),
					'permission_callback' => array( __CLASS__, 'permission_authenticated' ),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( __CLASS__, 'handle_create_term' ),
					'permission_callback' => array( __CLASS__, 'permission_manage_terms' ),
				),
			)
		);

		register_rest_route(
			self::NS,
			'/content/(?P<id>\d+)/terms',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'handle_assign_terms' ),
				'permission_callback' => array( __CLASS__, 'permission_edit_post' ),
			)
		);

		register_rest_route(
			self::NS,
			'/taxonomies/(?P<taxonomy>[a-zA-Z0-9_\-]+)/terms/(?P<term_id>\d+)',
			array(
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( __CLASS__, 'handle_update_term' ),
					'permission_callback' => array( __CLASS__, 'permission_edit_term' ),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( __CLASS__, 'handle_delete_term' ),
					'permission_callback' => array( __CLASS__, 'permission_delete_term' ),
				),
			)
		);

		register_rest_route(
			self::NS,
			'/users',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'handle_list_users' ),
				'permission_callback' => array( __CLASS__, 'permission_list_users' ),
			)
		);

		register_rest_route(
			self::NS,
			'/users/(?P<id>\d+)',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'handle_get_user' ),
				'permission_callback' => array( __CLASS__, 'permission_list_users' ),
			)
		);

		register_rest_route(
			self::NS,
			'/media',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( __CLASS__, 'handle_search_media' ),
					'permission_callback' => array( __CLASS__, 'permission_authenticated' ),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( __CLASS__, 'handle_upload_media' ),
					'permission_callback' => array( __CLASS__, 'permission_upload_files' ),
				),
			)
		);

		register_rest_route(
			self::NS,
			'/media/(?P<id>\d+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( __CLASS__, 'handle_get_media' ),
					'permission_callback' => array( __CLASS__, 'permission_get_media' ),
				),
				array(
					'methods'             => 'PATCH',
					'callback'            => array( __CLASS__, 'handle_update_media' ),
					'permission_callback' => array( __CLASS__, 'permission_edit_media' ),
				),
			)
		);

		register_rest_route(
			self::NS,
			'/media/from-url',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'handle_upload_media_from_url' ),
				'permission_callback' => array( __CLASS__, 'permission_upload_files' ),
			)
		);

		register_rest_route(
			self::NS,
			'/content/(?P<id>\d+)/clone',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'handle_clone_content' ),
				'permission_callback' => array( __CLASS__, 'permission_read_post' ),
			)
		);

		register_rest_route(
			self::NS,
			'/content/(?P<id>\d+)/featured-image',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'handle_set_featured_image' ),
				'permission_callback' => array( __CLASS__, 'permission_edit_post' ),
			)
		);

		register_rest_route(
			self::NS,
			'/content/(?P<id>\d+)/inline-image/insert',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'handle_insert_inline_image' ),
				'permission_callback' => array( __CLASS__, 'permission_edit_post' ),
			)
		);

		register_rest_route(
			self::NS,
			'/content/(?P<id>\d+)/inline-image/replace',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'handle_replace_inline_image' ),
				'permission_callback' => array( __CLASS__, 'permission_edit_post' ),
			)
		);

		register_rest_route(
			self::NS,
			'/content/(?P<id>\d+)/inline-image/remove',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'handle_remove_inline_image' ),
				'permission_callback' => array( __CLASS__, 'permission_edit_post' ),
			)
		);

		register_rest_route(
			self::NS,
			'/yoast/analysis/(?P<id>\d+)',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'handle_yoast_analysis' ),
				'permission_callback' => array( __CLASS__, 'permission_read_post' ),
			)
		);

		register_rest_route(
			self::NS,
			'/yoast/metadata/(?P<id>\d+)',
			array(
				'methods'             => 'PATCH',
				'callback'            => array( __CLASS__, 'handle_update_yoast_metadata' ),
				'permission_callback' => array( __CLASS__, 'permission_edit_post' ),
			)
		);

		register_rest_route(
			self::NS,
			'/yoast/head/(?P<id>\d+)',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'handle_yoast_head' ),
				'permission_callback' => array( __CLASS__, 'permission_read_post' ),
			)
		);

		register_rest_route(
			self::NS,
			'/content/(?P<id>\d+)/preview-link',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'handle_preview_link' ),
				'permission_callback' => array( __CLASS__, 'permission_edit_post' ),
			)
		);

		// ── Post Meta / Custom Fields ───────────────────────────────────────────

		register_rest_route(
			self::NS,
			'/content/(?P<id>\d+)/meta',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( __CLASS__, 'handle_get_post_meta' ),
					'permission_callback' => array( __CLASS__, 'permission_read_post' ),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( __CLASS__, 'handle_update_post_meta' ),
					'permission_callback' => array( __CLASS__, 'permission_edit_post' ),
				),
			)
		);

		register_rest_route(
			self::NS,
			'/content/(?P<id>\d+)/meta/(?P<meta_key>[a-zA-Z0-9_\-]+)',
			array(
				'methods'             => WP_REST_Server::DELETABLE,
				'callback'            => array( __CLASS__, 'handle_delete_post_meta' ),
				'permission_callback' => array( __CLASS__, 'permission_edit_post' ),
			)
		);

		// ── Comments ────────────────────────────────────────────────────────────

		register_rest_route(
			self::NS,
			'/comments',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( __CLASS__, 'handle_list_comments' ),
					'permission_callback' => array( __CLASS__, 'permission_moderate_comments' ),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( __CLASS__, 'handle_create_comment' ),
					'permission_callback' => array( __CLASS__, 'permission_moderate_comments' ),
				),
			)
		);

		register_rest_route(
			self::NS,
			'/comments/(?P<id>\d+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( __CLASS__, 'handle_get_comment' ),
					'permission_callback' => array( __CLASS__, 'permission_moderate_comments' ),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( __CLASS__, 'handle_delete_comment' ),
					'permission_callback' => array( __CLASS__, 'permission_moderate_comments' ),
				),
			)
		);

		register_rest_route(
			self::NS,
			'/comments/(?P<id>\d+)/moderate',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'handle_moderate_comment' ),
				'permission_callback' => array( __CLASS__, 'permission_moderate_comments' ),
			)
		);

		// ── Audit Log ───────────────────────────────────────────────────────────
		// Permission: authenticated (editor+) so AI agents can review their own
		// activity via wp_get_audit_log. The richer admin-side view lives in
		// the WP-Admin Audit Log page (which performs its own admin check).

		register_rest_route(
			self::NS,
			'/audit-log',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'handle_get_audit_log' ),
				'permission_callback' => array( __CLASS__, 'permission_view_audit' ),
			)
		);

		// ── Image Providers ──────────────────────────────────────────────────────

		register_rest_route(
			self::NS,
			'/image/generate',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'handle_generate_image' ),
				'permission_callback' => array( __CLASS__, 'permission_upload_files' ),
			)
		);

		register_rest_route(
			self::NS,
			'/image/stock/search',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'handle_search_stock_photos' ),
				'permission_callback' => array( __CLASS__, 'permission_authenticated' ),
			)
		);

		register_rest_route(
			self::NS,
			'/image/stock/import',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'handle_import_stock_photo' ),
				'permission_callback' => array( __CLASS__, 'permission_upload_files' ),
			)
		);

		register_rest_route(
			self::NS,
			'/image/providers',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'handle_list_image_providers' ),
				'permission_callback' => array( __CLASS__, 'permission_authenticated' ),
			)
		);

		register_rest_route(
			self::NS,
			'/image/(?P<id>\d+)/confirm',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'handle_confirm_image' ),
				'permission_callback' => array( __CLASS__, 'permission_edit_media' ),
			)
		);

		// ── Upload Portal ──────────────────────────────────────────────────────

		register_rest_route(
			self::NS,
			'/upload/session',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'handle_create_upload_session' ),
				'permission_callback' => array( __CLASS__, 'permission_upload_files' ),
			)
		);

		register_rest_route(
			self::NS,
			'/upload/session/(?P<id>[a-f0-9\-]{36})',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'handle_get_upload_session' ),
				'permission_callback' => array( __CLASS__, 'permission_authenticated' ),
			)
		);

		register_rest_route(
			self::NS,
			'/upload/file',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'handle_upload_file' ),
				'permission_callback' => '__return_true', // Public — validated by session token + nonce.
			)
		);

		register_rest_route(
			self::NS,
			'/upload/portal',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'handle_upload_portal' ),
				'permission_callback' => '__return_true', // Public — validated by session token.
			)
		);

		// ── Connection capabilities ──────────────────────────────────────────
		register_rest_route(
			self::NS,
			'/connection/capabilities',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'handle_connection_capabilities' ),
				'permission_callback' => array( __CLASS__, 'permission_authenticated' ),
			)
		);

		// ── Review notification ──────────────────────────────────────────────
		register_rest_route(
			self::NS,
			'/content/(?P<id>[\d]+)/request-review',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'handle_request_review' ),
				'permission_callback' => array( __CLASS__, 'permission_edit_post' ),
				'args'                => array(
					'id'   => array(
						'required'          => true,
						'validate_callback' => function ( $v ) {
							return is_numeric( $v ) && (int) $v > 0; },
						'sanitize_callback' => 'absint',
					),
					'note' => array(
						'required'          => false,
						'sanitize_callback' => 'sanitize_textarea_field',
					),
				),
			)
		);

		// ── Extension download (.mcpb) ──────────────────────────────────────
		register_rest_route(
			self::NS,
			'/extension/download',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'handle_extension_download' ),
				'permission_callback' => array( __CLASS__, 'permission_admin' ),
			)
		);

		// ── WordPress Abilities API bridge (WP 6.9+) ────────────────────────
		// Exposes abilities registered via the official WP Abilities API
		// (added in WP 6.9, Nov 2025) as callable MCP tools through a
		// generic dispatcher: list -> discover, invoke -> execute. Safer
		// than dynamic per-ability tool registration because each ability
		// goes through WP core's own permission_callback before running.
		register_rest_route(
			self::NS,
			'/abilities',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'handle_list_abilities' ),
				'permission_callback' => array( __CLASS__, 'permission_authenticated' ),
			)
		);
		register_rest_route(
			self::NS,
			'/abilities/(?P<name>[a-zA-Z0-9_\-/]+)/execute',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'handle_invoke_ability' ),
				'permission_callback' => array( __CLASS__, 'permission_authenticated' ),
			)
		);

		// ── Theme Appearance (read-only in this package) ────────────────────
		// Free package exposes theme reads only. Theme writes (custom CSS,
		// theme_mods updates) are out of scope per WordPress.org guidelines
		// against plugins that save arbitrary CSS/JS/PHP.
		register_rest_route(
			self::NS,
			'/theme',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'handle_get_active_theme' ),
				'permission_callback' => array( __CLASS__, 'permission_authenticated' ),
			)
		);
		register_rest_route(
			self::NS,
			'/theme/mods',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'handle_get_theme_mods' ),
				'permission_callback' => array( __CLASS__, 'permission_authenticated' ),
			)
		);
		register_rest_route(
			self::NS,
			'/theme/custom-css',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'handle_get_custom_css' ),
				'permission_callback' => array( __CLASS__, 'permission_authenticated' ),
			)
		);

		// ── Navigation Menus (list / CRUD / reorder) ────────────────────────
		// Reads (list, get) require authenticated access. Writes (create,
		// update, delete, reorder) require the `edit_theme_options`
		// capability via the dedicated permission callback so service-
		// account users without the cap get a clear 403 before the handler
		// runs.
		register_rest_route(
			self::NS,
			'/menus',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'handle_list_menus' ),
				'permission_callback' => array( __CLASS__, 'permission_authenticated' ),
			)
		);
		register_rest_route(
			self::NS,
			'/menus/(?P<id_or_slug>[\w\-]+)',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'handle_get_menu' ),
				'permission_callback' => array( __CLASS__, 'permission_authenticated' ),
			)
		);
		register_rest_route(
			self::NS,
			'/menus/(?P<id>\d+)/items',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'handle_create_menu_item' ),
				'permission_callback' => array( __CLASS__, 'permission_edit_theme_options' ),
			)
		);
		register_rest_route(
			self::NS,
			'/menu-items/(?P<item_id>\d+)',
			array(
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( __CLASS__, 'handle_update_menu_item' ),
					'permission_callback' => array( __CLASS__, 'permission_edit_theme_options' ),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( __CLASS__, 'handle_delete_menu_item' ),
					'permission_callback' => array( __CLASS__, 'permission_edit_theme_options' ),
				),
			)
		);
		register_rest_route(
			self::NS,
			'/menus/(?P<id>\d+)/reorder',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'handle_reorder_menu_items' ),
				'permission_callback' => array( __CLASS__, 'permission_edit_theme_options' ),
			)
		);

		// ── Generic SEO meta (auto-detects Yoast / Rank Math / AIOSEO) ──────
		// Provider-neutral SEO read/write. The active SEO plugin is detected
		// via Axtolab_AI_Connector_SEO_Adapter::active_plugin(); the adapter routes
		// the standardized field names (title / description / focus_keyphrase
		// / noindex / nofollow / og_* / twitter_*) to the right postmeta
		// keys for whichever plugin is installed. Falls back to the legacy
		// Yoast-specific tools if you need direct access to provider-
		// specific behavior.
		register_rest_route(
			self::NS,
			'/seo/(?P<id>\d+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( __CLASS__, 'handle_get_seo_meta' ),
					'permission_callback' => array( __CLASS__, 'permission_read_post' ),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( __CLASS__, 'handle_update_seo_meta' ),
					'permission_callback' => array( __CLASS__, 'permission_edit_post' ),
				),
			)
		);

		// ── Options API (allowlisted read/write of WordPress options) ───────
		// Three-gate write security identical to permalink_structure:
		// 1. Admin toggle: axtolab_ai_connector_settings['options_writes_enabled']
		// 2. WordPress capability: manage_options (via permission_admin)
		// 3. Runtime allowlist: axtolab_ai_connector_writable_options filter +
		// hard denylist for sensitive keys (siteurl/home/license_*/etc).
		// Reads use authenticated permission with automatic sensitive-key
		// redaction (api_key/secret/password/token/license/salt patterns
		// replaced with [REDACTED] before leaving the server).
		register_rest_route(
			self::NS,
			'/options/(?P<key>[a-zA-Z0-9_\-]+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( __CLASS__, 'handle_get_option' ),
					'permission_callback' => array( __CLASS__, 'permission_admin' ),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( __CLASS__, 'handle_update_option' ),
					'permission_callback' => array( __CLASS__, 'permission_admin' ),
				),
			)
		);

		// ── Term Meta (taxonomy term meta — Yoast/Rank Math/AIOSEO term SEO) ─
		register_rest_route(
			self::NS,
			'/terms/(?P<term_id>\d+)/meta',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( __CLASS__, 'handle_get_term_meta' ),
					'permission_callback' => array( __CLASS__, 'permission_authenticated' ),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( __CLASS__, 'handle_update_term_meta' ),
					'permission_callback' => array( __CLASS__, 'permission_edit_term' ),
				),
			)
		);
		register_rest_route(
			self::NS,
			'/terms/(?P<term_id>\d+)/meta/(?P<meta_key>[a-zA-Z0-9_\-]+)',
			array(
				'methods'             => WP_REST_Server::DELETABLE,
				'callback'            => array( __CLASS__, 'handle_delete_term_meta' ),
				'permission_callback' => array( __CLASS__, 'permission_edit_term' ),
			)
		);

		// ── Plugins & Themes inventory ──────────────────────────────────────
		// Read-only metadata (name, version, status, author, description) for
		// installed plugins and themes. The inventory leaks the active
		// security-software footprint, so we gate behind `manage_options`
		// rather than the broad authenticated check — matches WP core's
		// own `/wp/v2/plugins` permission model.
		register_rest_route(
			self::NS,
			'/plugins',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'handle_list_plugins' ),
				'permission_callback' => array( __CLASS__, 'permission_admin' ),
			)
		);

		register_rest_route(
			self::NS,
			'/themes',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'handle_list_themes' ),
				'permission_callback' => array( __CLASS__, 'permission_admin' ),
			)
		);

		// ── Permalink structure ─────────────────────────────────────────────
		// GET and write both require `manage_options`. The permalink
		// structure is a site-wide setting and is gated identically to the
		// `/options/{key}` endpoint above. Writes additionally require an
		// explicit admin toggle in axtolab_ai_connector_settings
		// (`permalink_writes_enabled`, off by default).
		register_rest_route(
			self::NS,
			'/permalink-structure',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( __CLASS__, 'handle_get_permalink_structure' ),
					'permission_callback' => array( __CLASS__, 'permission_admin' ),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( __CLASS__, 'handle_update_permalink_structure' ),
					'permission_callback' => array( __CLASS__, 'permission_admin' ),
					'args'                => array(
						'structure' => array(
							'required'          => true,
							'sanitize_callback' => 'sanitize_text_field',
						),
					),
				),
			)
		);
	}

	public static function permission_authenticated() {
		$rate_check = Axtolab_AI_Connector_Rate_Limiter::check();
		if ( is_wp_error( $rate_check ) ) {
			return $rate_check;
		}

		if ( ! is_user_logged_in() ) {
			return new WP_Error( 'unauthorized', 'Authentication required.', array( 'status' => 401 ) );
		}
		if ( ! current_user_can( 'edit_posts' ) ) {
			return new WP_Error( 'forbidden', 'Insufficient capabilities.', array( 'status' => 403 ) );
		}

		$multisite_allowed = Axtolab_AI_Connector_Free_Gates::check_multisite_allowed();
		if ( is_wp_error( $multisite_allowed ) ) {
			return $multisite_allowed;
		}

		return true;
	}

	public static function permission_admin() {
		$rate_check = Axtolab_AI_Connector_Rate_Limiter::check();
		if ( is_wp_error( $rate_check ) ) {
			return $rate_check;
		}
		if ( ! is_user_logged_in() ) {
			return new WP_Error( 'unauthorized', 'Authentication required.', array( 'status' => 401 ) );
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_Error( 'forbidden', 'Administrator access required.', array( 'status' => 403 ) );
		}
		$multisite_allowed = Axtolab_AI_Connector_Free_Gates::check_multisite_allowed();
		if ( is_wp_error( $multisite_allowed ) ) {
			return $multisite_allowed;
		}
		return true;
	}

	/**
	 * Permission callback for user-lookup routes (/users/{id}).
	 *
	 * Requires the WordPress `list_users` capability — the standard cap for
	 * user-directory style operations. Granted to the service-account role on
	 * activation; administrators have it by default.
	 */
	public static function permission_list_users() {
		$rate_check = Axtolab_AI_Connector_Rate_Limiter::check();
		if ( is_wp_error( $rate_check ) ) {
			return $rate_check;
		}
		if ( ! is_user_logged_in() ) {
			return new WP_Error( 'unauthorized', 'Authentication required.', array( 'status' => 401 ) );
		}
		if ( ! current_user_can( 'list_users' ) ) {
			return new WP_Error( 'forbidden', 'User-lookup requires the list_users capability.', array( 'status' => 403 ) );
		}
		$multisite_allowed = Axtolab_AI_Connector_Free_Gates::check_multisite_allowed();
		if ( is_wp_error( $multisite_allowed ) ) {
			return $multisite_allowed;
		}
		return true;
	}

	/**
	 * Permission callback for the changelog endpoint (/changelog).
	 *
	 * Requires the custom `axtolab_ai_connector_view_changelog` capability
	 * which exposes connection/session metadata. Granted to the service-account
	 * role on activation; administrators have it by default.
	 */
	public static function permission_view_changelog() {
		$rate_check = Axtolab_AI_Connector_Rate_Limiter::check();
		if ( is_wp_error( $rate_check ) ) {
			return $rate_check;
		}
		if ( ! is_user_logged_in() ) {
			return new WP_Error( 'unauthorized', 'Authentication required.', array( 'status' => 401 ) );
		}
		if ( ! current_user_can( 'axtolab_ai_connector_view_changelog' ) && ! current_user_can( 'manage_options' ) ) {
			return new WP_Error( 'forbidden', 'Viewing the changelog requires the axtolab_ai_connector_view_changelog capability.', array( 'status' => 403 ) );
		}
		$multisite_allowed = Axtolab_AI_Connector_Free_Gates::check_multisite_allowed();
		if ( is_wp_error( $multisite_allowed ) ) {
			return $multisite_allowed;
		}
		return true;
	}

	/**
	 * Permission callback for the audit-log endpoint (/audit-log).
	 *
	 * Requires the custom `axtolab_ai_connector_view_audit` capability which
	 * exposes connection metadata. Granted to the service-account role on
	 * activation; administrators have it by default.
	 */
	public static function permission_view_audit() {
		$rate_check = Axtolab_AI_Connector_Rate_Limiter::check();
		if ( is_wp_error( $rate_check ) ) {
			return $rate_check;
		}
		if ( ! is_user_logged_in() ) {
			return new WP_Error( 'unauthorized', 'Authentication required.', array( 'status' => 401 ) );
		}
		if ( ! current_user_can( 'axtolab_ai_connector_view_audit' ) && ! current_user_can( 'manage_options' ) ) {
			return new WP_Error( 'forbidden', 'Viewing the audit log requires the axtolab_ai_connector_view_audit capability.', array( 'status' => 403 ) );
		}
		$multisite_allowed = Axtolab_AI_Connector_Free_Gates::check_multisite_allowed();
		if ( is_wp_error( $multisite_allowed ) ) {
			return $multisite_allowed;
		}
		return true;
	}

	// ── Per-object / per-capability permission callbacks ─────────────────────
	//
	// Each helper preserves the standard prelude that `permission_authenticated`
	// uses (rate-limit → logged-in → cap check → multisite gate) and then runs
	// a per-object `current_user_can()` against the specific resource the
	// request targets. WP.org review feedback (round 4) required us to replace
	// the broad `edit_posts` check on object-scoped routes with object-aware
	// capability checks so a user with `edit_posts` cannot, for example, edit
	// a post they are not allowed to touch under capability filters such as
	// `map_meta_cap`.

	/**
	 * Permission callback for routes that read a single post object (by `id`).
	 *
	 * Capability: `read_post` on `$request['id']`.
	 *
	 * @param WP_REST_Request $request The REST request.
	 * @return true|WP_Error
	 */
	public static function permission_read_post( $request ) {
		$rate_check = Axtolab_AI_Connector_Rate_Limiter::check();
		if ( is_wp_error( $rate_check ) ) {
			return $rate_check;
		}
		if ( ! is_user_logged_in() ) {
			return new WP_Error( 'unauthorized', 'Authentication required.', array( 'status' => 401 ) );
		}
		$post_id = (int) $request['id'];
		if ( $post_id <= 0 || ! current_user_can( 'read_post', $post_id ) ) {
			return new WP_Error( 'forbidden', 'Insufficient capabilities to read this post.', array( 'status' => 403 ) );
		}
		$multisite_allowed = Axtolab_AI_Connector_Free_Gates::check_multisite_allowed();
		if ( is_wp_error( $multisite_allowed ) ) {
			return $multisite_allowed;
		}
		return true;
	}

	/**
	 * Permission callback for routes that edit a single post object (by `id`).
	 *
	 * Capability: `edit_post` on `$request['id']`.
	 *
	 * @param WP_REST_Request $request The REST request.
	 * @return true|WP_Error
	 */
	public static function permission_edit_post( $request ) {
		$rate_check = Axtolab_AI_Connector_Rate_Limiter::check();
		if ( is_wp_error( $rate_check ) ) {
			return $rate_check;
		}
		if ( ! is_user_logged_in() ) {
			return new WP_Error( 'unauthorized', 'Authentication required.', array( 'status' => 401 ) );
		}
		$post_id = (int) $request['id'];
		if ( $post_id <= 0 || ! current_user_can( 'edit_post', $post_id ) ) {
			return new WP_Error( 'forbidden', 'Insufficient capabilities to edit this post.', array( 'status' => 403 ) );
		}
		$multisite_allowed = Axtolab_AI_Connector_Free_Gates::check_multisite_allowed();
		if ( is_wp_error( $multisite_allowed ) ) {
			return $multisite_allowed;
		}
		return true;
	}

	/**
	 * Permission callback for routes that publish a single post object (by `id`).
	 *
	 * Capability: `publish_post` on `$request['id']`.
	 *
	 * @param WP_REST_Request $request The REST request.
	 * @return true|WP_Error
	 */
	public static function permission_publish_post( $request ) {
		$rate_check = Axtolab_AI_Connector_Rate_Limiter::check();
		if ( is_wp_error( $rate_check ) ) {
			return $rate_check;
		}
		if ( ! is_user_logged_in() ) {
			return new WP_Error( 'unauthorized', 'Authentication required.', array( 'status' => 401 ) );
		}
		$post_id = (int) $request['id'];
		if ( $post_id <= 0 || ! current_user_can( 'publish_post', $post_id ) ) {
			return new WP_Error( 'forbidden', 'Insufficient capabilities to publish this post.', array( 'status' => 403 ) );
		}
		$multisite_allowed = Axtolab_AI_Connector_Free_Gates::check_multisite_allowed();
		if ( is_wp_error( $multisite_allowed ) ) {
			return $multisite_allowed;
		}
		return true;
	}

	/**
	 * Permission callback for routes that delete (trash/untrash) a single post.
	 *
	 * Capability: `delete_post` on `$request['id']`.
	 *
	 * @param WP_REST_Request $request The REST request.
	 * @return true|WP_Error
	 */
	public static function permission_delete_post( $request ) {
		$rate_check = Axtolab_AI_Connector_Rate_Limiter::check();
		if ( is_wp_error( $rate_check ) ) {
			return $rate_check;
		}
		if ( ! is_user_logged_in() ) {
			return new WP_Error( 'unauthorized', 'Authentication required.', array( 'status' => 401 ) );
		}
		$post_id = (int) $request['id'];
		if ( $post_id <= 0 || ! current_user_can( 'delete_post', $post_id ) ) {
			return new WP_Error( 'forbidden', 'Insufficient capabilities to delete this post.', array( 'status' => 403 ) );
		}
		$multisite_allowed = Axtolab_AI_Connector_Free_Gates::check_multisite_allowed();
		if ( is_wp_error( $multisite_allowed ) ) {
			return $multisite_allowed;
		}
		return true;
	}

	/**
	 * Permission callback for routes that change a post's author.
	 *
	 * Capability: `edit_others_posts` (changing author requires this on top of
	 * `edit_post` because you may be re-assigning to a user other than self).
	 *
	 * @param WP_REST_Request $request The REST request.
	 * @return true|WP_Error
	 */
	public static function permission_assign_author( $request ) {
		$rate_check = Axtolab_AI_Connector_Rate_Limiter::check();
		if ( is_wp_error( $rate_check ) ) {
			return $rate_check;
		}
		if ( ! is_user_logged_in() ) {
			return new WP_Error( 'unauthorized', 'Authentication required.', array( 'status' => 401 ) );
		}
		$post_id = (int) $request['id'];
		if ( $post_id <= 0 || ! current_user_can( 'edit_post', $post_id ) ) {
			return new WP_Error( 'forbidden', 'Insufficient capabilities to edit this post.', array( 'status' => 403 ) );
		}
		if ( ! current_user_can( 'edit_others_posts' ) ) {
			return new WP_Error( 'forbidden', 'Reassigning the author requires the edit_others_posts capability.', array( 'status' => 403 ) );
		}
		$multisite_allowed = Axtolab_AI_Connector_Free_Gates::check_multisite_allowed();
		if ( is_wp_error( $multisite_allowed ) ) {
			return $multisite_allowed;
		}
		return true;
	}

	/**
	 * Permission callback for routes that read a single media attachment.
	 *
	 * Capability: `read_post` on the attachment ID (attachments are a CPT).
	 *
	 * @param WP_REST_Request $request The REST request.
	 * @return true|WP_Error
	 */
	public static function permission_get_media( $request ) {
		$rate_check = Axtolab_AI_Connector_Rate_Limiter::check();
		if ( is_wp_error( $rate_check ) ) {
			return $rate_check;
		}
		if ( ! is_user_logged_in() ) {
			return new WP_Error( 'unauthorized', 'Authentication required.', array( 'status' => 401 ) );
		}
		$attachment_id = (int) $request['id'];
		if ( $attachment_id <= 0 || ! current_user_can( 'read_post', $attachment_id ) ) {
			return new WP_Error( 'forbidden', 'Insufficient capabilities to read this attachment.', array( 'status' => 403 ) );
		}
		$multisite_allowed = Axtolab_AI_Connector_Free_Gates::check_multisite_allowed();
		if ( is_wp_error( $multisite_allowed ) ) {
			return $multisite_allowed;
		}
		return true;
	}

	/**
	 * Permission callback for routes that edit a single media attachment.
	 *
	 * Capability: `edit_post` on the attachment ID (attachments are a CPT).
	 *
	 * @param WP_REST_Request $request The REST request.
	 * @return true|WP_Error
	 */
	public static function permission_edit_media( $request ) {
		$rate_check = Axtolab_AI_Connector_Rate_Limiter::check();
		if ( is_wp_error( $rate_check ) ) {
			return $rate_check;
		}
		if ( ! is_user_logged_in() ) {
			return new WP_Error( 'unauthorized', 'Authentication required.', array( 'status' => 401 ) );
		}
		$attachment_id = (int) $request['id'];
		if ( $attachment_id <= 0 || ! current_user_can( 'edit_post', $attachment_id ) ) {
			return new WP_Error( 'forbidden', 'Insufficient capabilities to edit this attachment.', array( 'status' => 403 ) );
		}
		$multisite_allowed = Axtolab_AI_Connector_Free_Gates::check_multisite_allowed();
		if ( is_wp_error( $multisite_allowed ) ) {
			return $multisite_allowed;
		}
		return true;
	}

	/**
	 * Permission callback for routes that upload new files / create attachments.
	 *
	 * Capability: `upload_files`.
	 *
	 * @return true|WP_Error
	 */
	public static function permission_upload_files() {
		$rate_check = Axtolab_AI_Connector_Rate_Limiter::check();
		if ( is_wp_error( $rate_check ) ) {
			return $rate_check;
		}
		if ( ! is_user_logged_in() ) {
			return new WP_Error( 'unauthorized', 'Authentication required.', array( 'status' => 401 ) );
		}
		if ( ! current_user_can( 'upload_files' ) ) {
			return new WP_Error( 'forbidden', 'Uploading files requires the upload_files capability.', array( 'status' => 403 ) );
		}
		$multisite_allowed = Axtolab_AI_Connector_Free_Gates::check_multisite_allowed();
		if ( is_wp_error( $multisite_allowed ) ) {
			return $multisite_allowed;
		}
		return true;
	}

	/**
	 * Permission callback for routes that create new taxonomy terms.
	 *
	 * Capability: `manage_terms` on the requested taxonomy. We resolve the
	 * taxonomy from `$request['taxonomy']` and consult its registered caps
	 * (defaults to `manage_categories` for built-in taxonomies).
	 *
	 * @param WP_REST_Request $request The REST request.
	 * @return true|WP_Error
	 */
	public static function permission_manage_terms( $request ) {
		$rate_check = Axtolab_AI_Connector_Rate_Limiter::check();
		if ( is_wp_error( $rate_check ) ) {
			return $rate_check;
		}
		if ( ! is_user_logged_in() ) {
			return new WP_Error( 'unauthorized', 'Authentication required.', array( 'status' => 401 ) );
		}
		$taxonomy = sanitize_key( (string) $request['taxonomy'] );
		$tax_obj  = $taxonomy ? get_taxonomy( $taxonomy ) : null;
		$cap      = $tax_obj && isset( $tax_obj->cap->manage_terms ) ? $tax_obj->cap->manage_terms : 'manage_categories';
		if ( ! current_user_can( $cap ) ) {
			return new WP_Error( 'forbidden', 'Insufficient capabilities to manage terms for this taxonomy.', array( 'status' => 403 ) );
		}
		$multisite_allowed = Axtolab_AI_Connector_Free_Gates::check_multisite_allowed();
		if ( is_wp_error( $multisite_allowed ) ) {
			return $multisite_allowed;
		}
		return true;
	}

	/**
	 * Permission callback for routes that edit a single taxonomy term.
	 *
	 * Capability: `edit_term` on the requested term ID. The term ID is read
	 * from either `$request['term_id']` (taxonomy URL pattern) or
	 * `$request['id']` (legacy alias).
	 *
	 * @param WP_REST_Request $request The REST request.
	 * @return true|WP_Error
	 */
	public static function permission_edit_term( $request ) {
		$rate_check = Axtolab_AI_Connector_Rate_Limiter::check();
		if ( is_wp_error( $rate_check ) ) {
			return $rate_check;
		}
		if ( ! is_user_logged_in() ) {
			return new WP_Error( 'unauthorized', 'Authentication required.', array( 'status' => 401 ) );
		}
		$term_id = isset( $request['term_id'] ) ? (int) $request['term_id'] : (int) ( $request['id'] ?? 0 );
		if ( $term_id <= 0 || ! current_user_can( 'edit_term', $term_id ) ) {
			return new WP_Error( 'forbidden', 'Insufficient capabilities to edit this term.', array( 'status' => 403 ) );
		}
		$multisite_allowed = Axtolab_AI_Connector_Free_Gates::check_multisite_allowed();
		if ( is_wp_error( $multisite_allowed ) ) {
			return $multisite_allowed;
		}
		return true;
	}

	/**
	 * Permission callback for routes that delete a single taxonomy term.
	 *
	 * Capability: `delete_term` on the requested term ID.
	 *
	 * @param WP_REST_Request $request The REST request.
	 * @return true|WP_Error
	 */
	public static function permission_delete_term( $request ) {
		$rate_check = Axtolab_AI_Connector_Rate_Limiter::check();
		if ( is_wp_error( $rate_check ) ) {
			return $rate_check;
		}
		if ( ! is_user_logged_in() ) {
			return new WP_Error( 'unauthorized', 'Authentication required.', array( 'status' => 401 ) );
		}
		$term_id = isset( $request['term_id'] ) ? (int) $request['term_id'] : (int) ( $request['id'] ?? 0 );
		if ( $term_id <= 0 || ! current_user_can( 'delete_term', $term_id ) ) {
			return new WP_Error( 'forbidden', 'Insufficient capabilities to delete this term.', array( 'status' => 403 ) );
		}
		$multisite_allowed = Axtolab_AI_Connector_Free_Gates::check_multisite_allowed();
		if ( is_wp_error( $multisite_allowed ) ) {
			return $multisite_allowed;
		}
		return true;
	}

	/**
	 * Permission callback for comment-moderation routes.
	 *
	 * Capability: `moderate_comments`.
	 *
	 * @return true|WP_Error
	 */
	public static function permission_moderate_comments() {
		$rate_check = Axtolab_AI_Connector_Rate_Limiter::check();
		if ( is_wp_error( $rate_check ) ) {
			return $rate_check;
		}
		if ( ! is_user_logged_in() ) {
			return new WP_Error( 'unauthorized', 'Authentication required.', array( 'status' => 401 ) );
		}
		if ( ! current_user_can( 'moderate_comments' ) ) {
			return new WP_Error( 'forbidden', 'Moderating comments requires the moderate_comments capability.', array( 'status' => 403 ) );
		}
		$multisite_allowed = Axtolab_AI_Connector_Free_Gates::check_multisite_allowed();
		if ( is_wp_error( $multisite_allowed ) ) {
			return $multisite_allowed;
		}
		return true;
	}

	/**
	 * Permission callback for routes that write theme appearance / menu state.
	 *
	 * Capability: `edit_theme_options` — the standard cap for menu editing,
	 * widgets, customizer, theme mods.
	 *
	 * @return true|WP_Error
	 */
	public static function permission_edit_theme_options() {
		$rate_check = Axtolab_AI_Connector_Rate_Limiter::check();
		if ( is_wp_error( $rate_check ) ) {
			return $rate_check;
		}
		if ( ! is_user_logged_in() ) {
			return new WP_Error( 'unauthorized', 'Authentication required.', array( 'status' => 401 ) );
		}
		if ( ! current_user_can( 'edit_theme_options' ) ) {
			return new WP_Error( 'forbidden', 'Editing theme appearance requires the edit_theme_options capability.', array( 'status' => 403 ) );
		}
		$multisite_allowed = Axtolab_AI_Connector_Free_Gates::check_multisite_allowed();
		if ( is_wp_error( $multisite_allowed ) ) {
			return $multisite_allowed;
		}
		return true;
	}

	// ── Server-side capability enforcement ───────────────────────────────────

	/**
	 * Whether the connection ID fallback has already been attempted this request.
	 *
	 * @var bool
	 */
	private static $fallback_attempted = false;

	/**
	 * Cached result of the fallback resolution (null = unresolved).
	 *
	 * @var string|null
	 */
	private static $fallback_connection_id = null;

	/**
	 * Resolve the connection ID from the Authorization header as a fallback.
	 *
	 * The application_password_did_authenticate hook may not fire if a
	 * security plugin (e.g. Wordfence) intercepts the auth flow. This
	 * fallback reads the Basic Auth header and matches the password
	 * against the service account's stored Application Passwords.
	 *
	 * Result is cached per-request to avoid repeated password hashing.
	 *
	 * @return string|null The connection UUID, or null if unresolvable.
	 */
	private static function resolve_connection_id_fallback() {
		if ( self::$fallback_attempted ) {
			return self::$fallback_connection_id;
		}
		self::$fallback_attempted = true;

		$service_user_id = (int) get_option( 'axtolab_ai_connector_service_user_id', 0 );
		if ( ! $service_user_id ) {
			return null;
		}

		$current_user_id = get_current_user_id();
		if ( $current_user_id !== $service_user_id ) {
			return null;
		}

		// Read the Authorization header.
		$auth_header = '';
		if ( isset( $_SERVER['HTTP_AUTHORIZATION'] ) ) {
			$auth_header = sanitize_text_field( wp_unslash( (string) $_SERVER['HTTP_AUTHORIZATION'] ) );
		} elseif ( isset( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ) ) {
			$auth_header = sanitize_text_field( wp_unslash( (string) $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ) );
		}

		if ( empty( $auth_header ) || 0 !== stripos( $auth_header, 'Basic ' ) ) {
			return null;
		}

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		$decoded = base64_decode( substr( $auth_header, 6 ) );
		if ( false === $decoded || false === strpos( $decoded, ':' ) ) {
			return null;
		}

		$parts    = explode( ':', $decoded, 2 );
		$password = $parts[1];

		if ( ! class_exists( 'WP_Application_Passwords' ) ) {
			return null;
		}

		$passwords = WP_Application_Passwords::get_user_application_passwords( $service_user_id );
		if ( ! is_array( $passwords ) ) {
			return null;
		}

		foreach ( $passwords as $item ) {
			if ( wp_check_password( $password, $item['password'], $service_user_id ) ) {
				self::$fallback_connection_id = $item['uuid'];
				Axtolab_AI_Connector_Connections::set_current_connection_id( $item['uuid'] );
				return $item['uuid'];
			}
		}

		return null;
	}

	/**
	 * Get the effective connection ID for the current request.
	 *
	 * Tries the auth hook first, then falls back to header matching.
	 *
	 * @return string|null
	 */
	private static function get_effective_connection_id() {
		$connection_id = Axtolab_AI_Connector_Connections::get_current_connection_id();
		if ( $connection_id ) {
			return $connection_id;
		}
		return self::resolve_connection_id_fallback();
	}

	/**
	 * Check whether the current connection has permission to use a tool.
	 *
	 * Returns null if allowed, or a WP_REST_Response (403) if denied.
	 * Call at the top of each non-read REST handler.
	 *
	 * @param string $tool_name The MCP tool name (e.g. 'wp_publish_content').
	 * @return WP_REST_Response|null Null if allowed, error response if denied.
	 */
	private static function require_tool_capability( $tool_name ) {
		$connection_id = self::get_effective_connection_id();

		if ( ! $connection_id ) {
			// No MCP connection context — this is a direct wp-admin / cookie-auth
			// call (e.g. the Logs & Roll Back UI clicking Undo on a changelog
			// row). The per-connection capability gate doesn't apply here;
			// administrators get the full toolset because they are already
			// gated by WordPress's manage_options capability. Lower-privilege
			// users keep the restrictive DEFAULT_PRESET as a safety net.
			if ( current_user_can( 'manage_options' ) ) {
				return null;
			}
			$capabilities = Axtolab_AI_Connector_Capabilities::DEFAULT_PRESET;
		} else {
			$capabilities = Axtolab_AI_Connector_Connections::get_capabilities( $connection_id );
		}

		$allowed_tools = Axtolab_AI_Connector_Capabilities::tools_for( $capabilities );

		if ( ! in_array( $tool_name, $allowed_tools, true ) ) {
			return Axtolab_AI_Connector_Response::error(
				'capability_denied',
				sprintf(
					'This connection does not have permission to use %s. Update permissions in MCP Gateway → Connections.',
					$tool_name
				),
				403
			);
		}

		return null;
	}

	/**
	 * Check whether the current connection is allowed to use a specific author.
	 *
	 * Returns null if allowed, or a WP_REST_Response (403) if denied.
	 *
	 * @param int $author_id The author user ID to check.
	 * @return WP_REST_Response|null Null if allowed, error response if denied.
	 */
	private static function require_allowed_author( $author_id ) {
		$connection_id = self::get_effective_connection_id();
		if ( ! $connection_id ) {
			return null; // No connection context — no restriction.
		}

		$allowed_authors = Axtolab_AI_Connector_Connections::get_allowed_authors( $connection_id );
		if ( null === $allowed_authors ) {
			return null; // No restriction configured.
		}

		if ( ! in_array( (int) $author_id, $allowed_authors, true ) ) {
			return Axtolab_AI_Connector_Response::error(
				'author_restricted',
				sprintf(
					'This connection is not permitted to use author ID %d. Allowed authors: %s.',
					$author_id,
					implode( ', ', $allowed_authors )
				),
				403
			);
		}

		return null;
	}

	public static function handle_site_info( WP_REST_Request $request ): WP_REST_Response {
		$config = Axtolab_AI_Connector_Config::get();

		// Gather active theme context
		$theme      = wp_get_theme();
		$theme_name = $theme->get( 'Name' );
		$theme_slug = get_stylesheet();

		// Resolve parent theme (relevant when a child theme is active)
		$parent_theme      = null;
		$parent_theme_name = '';
		$parent_theme_slug = '';
		if ( $theme->parent() ) {
			$parent_theme      = $theme->parent();
			$parent_theme_name = $parent_theme->get( 'Name' );
			$parent_theme_slug = $parent_theme->get_stylesheet();
		}

		// Detect title rendering approach based on theme support
		$title_rendered_as = current_theme_supports( 'title-tag' ) ? 'title-tag (managed by WordPress)' : 'hardcoded in template';

		// Detect featured image support
		$featured_image_display = current_theme_supports( 'post-thumbnails' ) ? 'supported' : 'not supported by theme';

		// Detect content width
		global $content_width;
		$cw = isset( $content_width ) && $content_width > 0 ? intval( $content_width ) : null;

		// Combine child + parent names/slugs for detection so child themes don't hide the parent builder
		$detect_name = $theme_name . ' ' . $parent_theme_name;
		$detect_slug = $theme_slug . ' ' . $parent_theme_slug;

		// Build theme-specific notes
		$notes = array();
		if ( false !== stripos( $detect_slug, 'flatsome' ) || false !== stripos( $detect_name, 'Flatsome' ) ) {
			$notes[] = 'Flatsome/UX Builder theme detected: page layout uses UX Builder shortcodes. Use wp_find_content + wp_get_content to learn shortcode patterns from existing pages before authoring new ones.';
			$notes[] = 'Page builder content is stored as shortcode markup — always clone an existing page with wp_clone_content as a starting point for new pages.';
		} elseif ( false !== stripos( $detect_slug, 'divi' ) || false !== stripos( $detect_name, 'Divi' ) ) {
			$notes[] = 'Divi theme detected: page builder content uses [et_pb_*] shortcodes. Use wp_find_content + wp_get_content to learn shortcode patterns from existing pages.';
		} elseif ( false !== stripos( $detect_slug, 'elementor' ) || false !== stripos( $detect_name, 'Elementor' ) ) {
			$notes[] = 'Elementor theme detected: most layout is stored as post meta, not in post_content. Standard content editing may have limited visual impact.';
		}

		$theme_context = array(
			'name'                   => $theme_name,
			'slug'                   => $theme_slug,
			'version'                => $theme->get( 'Version' ),
			'parent_theme'           => $parent_theme ? array(
				'name'    => $parent_theme_name,
				'slug'    => $parent_theme_slug,
				'version' => $parent_theme->get( 'Version' ),
			) : null,
			'title_rendered_as'      => $title_rendered_as,
			'featured_image_display' => $featured_image_display,
			'content_width_px'       => $cw,
			'notes'                  => $notes,
		);

		$data = array(
			'site_name'             => get_bloginfo( 'name' ),
			'url'                   => home_url( '/' ),
			'timezone'              => wp_timezone_string(),
			'allowed_content_types' => array_values( $config['allowed_content_types'] ),
			'allowed_taxonomies'    => array_values( $config['allowed_taxonomies'] ),
			'solutions_root_slug'   => $config['solutions_root_slug'],
			'yoast_allowed_paths'   => array_values( $config['yoast_allowed_paths'] ),
			'theme'                 => $theme_context,
		);

		return Axtolab_AI_Connector_Response::success( $data, 200, self::audit_id() );
	}

	public static function handle_health_check( WP_REST_Request $request ): WP_REST_Response {
		$user = wp_get_current_user();
		update_option( 'axtolab_ai_connector_last_health_check', time(), false );
		return Axtolab_AI_Connector_Response::success(
			array(
				'status'         => 'connected',
				'site_url'       => home_url( '/' ),
				'rest_url'       => rest_url( self::NS ),
				'user'           => array(
					'id'    => $user->ID,
					'login' => $user->user_login,
					'name'  => $user->display_name,
					'role'  => implode( ', ', $user->roles ),
				),
				'plugin_version' => defined( 'AXTOLAB_AI_CONNECTOR_VERSION' ) ? AXTOLAB_AI_CONNECTOR_VERSION : 'unknown',
				'timestamp'      => gmdate( 'c' ),
			),
			200,
			self::audit_id()
		);
	}

	/**
	 * GET /my-capabilities — return the calling connection's capability
	 * groups, the matching named preset (if any), and the resolved tool
	 * list. Lets AI agents plan work without trial-and-error.
	 *
	 * Capability filtering only applies to the remote MCP transport
	 * (`/mcp` endpoint, OAuth/bearer auth). When the request reaches this
	 * REST endpoint via Application Password Basic auth — the typical
	 * local mcp-server setup — there is no per-connection filter, so we
	 * report `full_access` with a clarifying note.
	 *
	 * @return WP_REST_Response
	 */
	public static function handle_my_capabilities() {
		$auth_method = self::detect_caller_auth_method();
		$settings    = get_option( 'axtolab_ai_connector_settings', array() );

		if ( 'oauth' === $auth_method ) {
			$capabilities = isset( $settings['oauth_capabilities'] )
				? (array) $settings['oauth_capabilities']
				: Axtolab_AI_Connector_Capabilities::DEFAULT_PRESET;
			$note         = 'OAuth connection. Capability groups can be changed by an administrator under WordPress → Axtolab → AI Connector → Connections.';
		} elseif ( 'bearer' === $auth_method ) {
			$capabilities = isset( $settings['bearer_capabilities'] )
				? (array) $settings['bearer_capabilities']
				: Axtolab_AI_Connector_Capabilities::DEFAULT_PRESET;
			$note         = 'Bearer-token connection. Capability groups can be changed by an administrator under WordPress → Axtolab → AI Connector → Connections.';
		} else {
			// Application Password / direct REST: no per-connection filter.
			$capabilities = Axtolab_AI_Connector_Capabilities::PRESETS['full_access'];
			$note         = 'Application Password auth has no per-connection capability filter; all tools are reachable subject to add-on gates and WordPress role/capability checks.';
		}

		if ( ! in_array( 'read', $capabilities, true ) ) {
			$capabilities[] = 'read';
		}

		$preset       = Axtolab_AI_Connector_Capabilities::detect_preset( $capabilities );
		$labels       = Axtolab_AI_Connector_Capabilities::preset_labels();
		$preset_label = isset( $labels[ $preset ] ) ? $labels[ $preset ] : 'Custom';

		return Axtolab_AI_Connector_Response::success(
			array(
				'auth_method'       => $auth_method,
				'preset'            => $preset,
				'preset_label'      => $preset_label,
				'capability_groups' => array_values( $capabilities ),
				'tools'             => Axtolab_AI_Connector_Capabilities::tools_for( $capabilities ),
				'note'              => $note,
			),
			200,
			self::audit_id()
		);
	}

	/**
	 * Best-effort detection of the auth method used for the current REST
	 * request. Distinguishes Application Password (Basic), OAuth bearer,
	 * and standalone bearer tokens.
	 *
	 * @return string One of 'app_password', 'oauth', 'bearer', 'unknown'.
	 */
	private static function detect_caller_auth_method() {
		$header = isset( $_SERVER['HTTP_AUTHORIZATION'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['HTTP_AUTHORIZATION'] ) ) : '';
		if ( '' === $header && function_exists( 'getallheaders' ) ) {
			$all = getallheaders();
			if ( is_array( $all ) ) {
				foreach ( $all as $k => $v ) {
					if ( 0 === strcasecmp( $k, 'Authorization' ) ) {
						$header = (string) $v;
						break;
					}
				}
			}
		}

		if ( '' === $header ) {
			return 'unknown';
		}

		if ( 0 === stripos( $header, 'Basic ' ) ) {
			return 'app_password';
		}

		if ( 0 === stripos( $header, 'Bearer ' ) ) {
			$token = trim( substr( $header, 7 ) );
			if ( '' === $token ) {
				return 'unknown';
			}
			if ( class_exists( 'Axtolab_AI_Connector_OAuth' )
				&& method_exists( 'Axtolab_AI_Connector_OAuth', 'verify_access_token' )
				&& Axtolab_AI_Connector_OAuth::verify_access_token( $token ) ) {
				return 'oauth';
			}
			return 'bearer';
		}

		return 'unknown';
	}

	/**
	 * GET /changelog — list recorded changes with filters.
	 *
	 * Query params (all optional): per_page, offset, session_id,
	 * target_type, target_id, tool_name, source, action, status
	 * (rolled_back|pending), since (ISO 8601 / mysql datetime).
	 *
	 * Snapshots are NOT returned in list mode (keeps payloads sane);
	 * fetch a single change via GET /changelog/{id} for full diffs.
	 *
	 * @return WP_REST_Response
	 */
	public static function handle_list_changelog( WP_REST_Request $request ) {
		$args = array();
		foreach ( array( 'session_id', 'target_type', 'target_id', 'tool_name', 'source', 'action', 'status', 'since' ) as $key ) {
			$value = $request->get_param( $key );
			if ( null !== $value && '' !== $value ) {
				$args[ $key ] = (string) $value;
			}
		}

		$per_page = (int) $request->get_param( 'per_page' );
		$offset   = (int) $request->get_param( 'offset' );
		if ( $per_page > 0 ) {
			$args['per_page'] = $per_page;
		}
		if ( $offset > 0 ) {
			$args['offset'] = $offset;
		}

		$result = Axtolab_AI_Connector_Changelog::query( $args );

		return Axtolab_AI_Connector_Response::success(
			array(
				'count'    => count( $result['items'] ),
				'total'    => (int) $result['total'],
				'items'    => $result['items'],
				'per_page' => isset( $args['per_page'] ) ? $args['per_page'] : 50,
				'offset'   => isset( $args['offset'] ) ? $args['offset'] : 0,
			),
			200,
			self::audit_id()
		);
	}

	/**
	 * GET /changelog/{id} — fetch a single changelog entry including
	 * before / after snapshots.
	 *
	 * @return WP_REST_Response
	 */
	public static function handle_get_change( WP_REST_Request $request ) {
		$id     = (int) $request->get_param( 'id' );
		$change = Axtolab_AI_Connector_Changelog::get( $id );
		if ( ! $change ) {
			return Axtolab_AI_Connector_Response::error( 'changelog_not_found', 'Changelog entry not found.', 404 );
		}

		return Axtolab_AI_Connector_Response::success( $change, 200, self::audit_id() );
	}

	/**
	 * POST /changelog/{id}/rollback — undo a recorded change.
	 *
	 * Two-step flow:
	 *   1. Caller invokes without confirmation_token; we issue one
	 *      and return a description of what the rollback will do.
	 *   2. Caller re-invokes with the confirmation_token; we consume
	 *      it and execute the rollback.
	 *
	 * Concurrent-edit guard: if the target post was modified after
	 * the change being rolled back was recorded, we 409 unless the
	 * caller passes `allow_concurrent_edit_override=true`.
	 *
	 * Currently supports `target_type=post` only. Other target
	 * types land in Step 6 (options, term meta, post meta, menus,
	 * theme mods) and return 501 here until then.
	 *
	 * @return WP_REST_Response
	 */
	public static function handle_rollback_change( WP_REST_Request $request ) {
		$denied = self::require_tool_capability( 'wp_rollback_change' );
		if ( $denied ) {
			return $denied; }

		$change_id = (int) $request->get_param( 'id' );
		$change    = Axtolab_AI_Connector_Changelog::get( $change_id );
		if ( ! $change ) {
			return Axtolab_AI_Connector_Response::error( 'changelog_not_found', 'Changelog entry not found.', 404 );
		}

		if ( ! empty( $change['rolled_back_at'] ) ) {
			return Axtolab_AI_Connector_Response::error(
				'already_rolled_back',
				'Change #' . $change_id . ' is already rolled back. Use wp_redo_change to re-apply.',
				400
			);
		}

		$token            = (string) ( $request->get_param( 'confirmation_token' ) ?: '' );
		$allow_concurrent = (bool) $request->get_param( 'allow_concurrent_edit_override' );

		// Two-step: issue token if not provided.
		if ( '' === $token ) {
			$issued = Axtolab_AI_Connector_Confirmation::issue(
				'rollback_change',
				'change:' . $change_id,
				array(
					'change_id' => $change_id,
					'tool_name' => $change['tool_name'],
					'action'    => $change['action'],
					'target'    => $change['target_type'] . '#' . $change['target_id'],
				)
			);

			$payload = array(
				'requires_confirmation' => true,
				'confirmation_token'    => $issued['confirmation_token'],
				'confirmation_payload'  => $issued['confirmation_payload'],
				'change'                => $change,
				'description'           => self::describe_rollback( $change ),
				'next'                  => 'Re-call this tool with confirmation_token in the body to execute. Token expires in 5 minutes. Pass allow_concurrent_edit_override=true to override the concurrent-edit guard if the target was modified after the captured change.',
			);

			return Axtolab_AI_Connector_Response::success( $payload, 200, self::audit_id() );
		}

		try {
			Axtolab_AI_Connector_Confirmation::consume( $token, 'rollback_change', 'change:' . $change_id );
		} catch ( Exception $e ) {
			return Axtolab_AI_Connector_Response::error( 'invalid_confirmation', $e->getMessage(), 400 );
		}

		switch ( $change['target_type'] ) {
			case 'post':
				return self::execute_post_rollback( $change, $allow_concurrent );
			case 'option':
				return self::execute_option_rollback( $change );
			case 'post_meta':
				return self::execute_post_meta_rollback( $change );
			case 'term_meta':
				return self::execute_term_meta_rollback( $change );
			case 'menu':
				return self::execute_menu_rollback( $change );
			case 'term':
				return self::execute_term_rollback( $change );
			default:
				return Axtolab_AI_Connector_Response::error(
					'rollback_not_supported',
					'Rollback for target_type "' . $change['target_type'] . '" is not yet supported.',
					501
				);
		}
	}

	/**
	 * POST /changelog/session/{session_id}/rollback — undo every
	 * pending (not rolled-back) change in a given session, in
	 * LIFO order (newest first). Continues past per-change failures
	 * and returns a per-change status array.
	 *
	 * Two-step confirmation: empty body returns count + dry-run
	 * description, body with token executes.
	 *
	 * @return WP_REST_Response
	 */
	public static function handle_rollback_session( WP_REST_Request $request ) {
		$denied = self::require_tool_capability( 'wp_rollback_change' );
		if ( $denied ) {
			return $denied; }

		$session_id = (string) $request->get_param( 'session_id' );
		if ( '' === $session_id ) {
			return Axtolab_AI_Connector_Response::error( 'missing_session_id', 'session_id is required.', 400 );
		}

		$result = Axtolab_AI_Connector_Changelog::query(
			array(
				'session_id' => $session_id,
				'status'     => 'pending',
				'per_page'   => 200,
			)
		);
		$items  = isset( $result['items'] ) ? $result['items'] : array();

		if ( empty( $items ) ) {
			return Axtolab_AI_Connector_Response::success(
				array(
					'session_id' => $session_id,
					'count'      => 0,
					'message'    => 'No pending changes in this session to roll back.',
					'results'    => array(),
				),
				200,
				self::audit_id()
			);
		}

		// LIFO: items already returned ORDER BY id DESC.
		$token            = (string) ( $request->get_param( 'confirmation_token' ) ?: '' );
		$allow_concurrent = (bool) $request->get_param( 'allow_concurrent_edit_override' );

		if ( '' === $token ) {
			$descriptions = array();
			foreach ( $items as $it ) {
				$full           = Axtolab_AI_Connector_Changelog::get( (int) $it['id'] );
				$descriptions[] = array(
					'id'          => (int) $it['id'],
					'description' => $full ? self::describe_rollback( $full ) : 'Change #' . (int) $it['id'],
				);
			}
			$issued = Axtolab_AI_Connector_Confirmation::issue(
				'rollback_session',
				'session:' . $session_id,
				array(
					'session_id' => $session_id,
					'count'      => count( $items ),
				)
			);
			return Axtolab_AI_Connector_Response::success(
				array(
					'requires_confirmation' => true,
					'confirmation_token'    => $issued['confirmation_token'],
					'confirmation_payload'  => $issued['confirmation_payload'],
					'session_id'            => $session_id,
					'count'                 => count( $items ),
					'plan'                  => $descriptions,
					'next'                  => 'Re-call this tool with confirmation_token to execute. Rollbacks run in LIFO order. Per-change concurrent-edit guards still apply unless allow_concurrent_edit_override=true.',
				),
				200,
				self::audit_id()
			);
		}

		try {
			Axtolab_AI_Connector_Confirmation::consume( $token, 'rollback_session', 'session:' . $session_id );
		} catch ( Exception $e ) {
			return Axtolab_AI_Connector_Response::error( 'invalid_confirmation', $e->getMessage(), 400 );
		}

		$results   = array();
		$succeeded = 0;
		$failed    = 0;

		// Within a session rollback, each subsequent change in the
		// chain mutates the same target as the previous one — that's
		// the whole point. The per-change concurrent-edit guard would
		// see those chain mutations as "external edits" and refuse.
		// The user has already given session-level confirmation, so
		// the override is implicit for chain rollbacks.
		$chain_override = true;

		foreach ( $items as $it ) {
			$full = Axtolab_AI_Connector_Changelog::get( (int) $it['id'] );
			if ( ! $full ) {
				$results[] = array(
					'id'      => (int) $it['id'],
					'success' => false,
					'error'   => 'changelog_not_found',
				);
				++$failed;
				continue;
			}
			// If a previous rollback in this loop already affected
			// this row (shouldn't happen, but defensive), skip.
			if ( ! empty( $full['rolled_back_at'] ) ) {
				$results[] = array(
					'id'      => (int) $it['id'],
					'success' => false,
					'error'   => 'already_rolled_back',
				);
				continue;
			}
			$dispatch = self::dispatch_rollback_target( $full, $chain_override );
			if ( $dispatch instanceof WP_REST_Response ) {
				$body = $dispatch->get_data();
				if ( ! empty( $body['success'] ) ) {
					$results[] = array(
						'id'      => (int) $it['id'],
						'success' => true,
						'message' => isset( $body['data']['message'] ) ? $body['data']['message'] : '',
					);
					++$succeeded;
				} else {
					$results[] = array(
						'id'        => (int) $it['id'],
						'success'   => false,
						'error'     => isset( $body['error']['code'] ) ? $body['error']['code'] : 'unknown',
						'error_msg' => isset( $body['error']['message'] ) ? $body['error']['message'] : '',
					);
					++$failed;
				}
			} else {
				$results[] = array(
					'id'      => (int) $it['id'],
					'success' => false,
					'error'   => 'no_response',
				);
				++$failed;
			}
		}

		return Axtolab_AI_Connector_Response::success(
			array(
				'session_id' => $session_id,
				'count'      => count( $items ),
				'succeeded'  => $succeeded,
				'failed'     => $failed,
				'results'    => $results,
			),
			200,
			self::audit_id()
		);
	}

	/**
	 * Per-change rollback dispatcher used by handle_rollback_session.
	 * Mirrors the switch in handle_rollback_change but skips the
	 * confirmation-token round-trip (the session-level token covers
	 * all child rollbacks).
	 *
	 * @param array $change
	 * @param bool  $allow_concurrent
	 * @return WP_REST_Response
	 */
	private static function dispatch_rollback_target( array $change, $allow_concurrent ) {
		switch ( $change['target_type'] ) {
			case 'post':
				return self::execute_post_rollback( $change, $allow_concurrent );
			case 'option':
				return self::execute_option_rollback( $change );
			case 'post_meta':
				return self::execute_post_meta_rollback( $change );
			case 'term_meta':
				return self::execute_term_meta_rollback( $change );
			case 'menu':
				return self::execute_menu_rollback( $change );
			case 'term':
				return self::execute_term_rollback( $change );
			default:
				return Axtolab_AI_Connector_Response::error(
					'rollback_not_supported',
					'Rollback for target_type "' . $change['target_type'] . '" is not yet supported.',
					501
				);
		}
	}

	/**
	 * POST /changelog/{id}/redo — re-apply a rolled-back change by
	 * restoring the original's `after` snapshot. Same two-step
	 * confirmation flow as rollback.
	 *
	 * @return WP_REST_Response
	 */
	public static function handle_redo_change( WP_REST_Request $request ) {
		$denied = self::require_tool_capability( 'wp_redo_change' );
		if ( $denied ) {
			return $denied; }

		$change_id = (int) $request->get_param( 'id' );
		$change    = Axtolab_AI_Connector_Changelog::get( $change_id );
		if ( ! $change ) {
			return Axtolab_AI_Connector_Response::error( 'changelog_not_found', 'Changelog entry not found.', 404 );
		}

		if ( empty( $change['rolled_back_at'] ) ) {
			return Axtolab_AI_Connector_Response::error(
				'not_rolled_back',
				'Change #' . $change_id . ' has not been rolled back, so there is nothing to redo.',
				400
			);
		}

		if ( empty( $change['after'] ) ) {
			return Axtolab_AI_Connector_Response::error(
				'no_after_snapshot',
				'Change #' . $change_id . ' has no `after` snapshot to redo.',
				400
			);
		}

		$token = (string) ( $request->get_param( 'confirmation_token' ) ?: '' );

		if ( '' === $token ) {
			$issued = Axtolab_AI_Connector_Confirmation::issue(
				'redo_change',
				'change:' . $change_id,
				array(
					'change_id' => $change_id,
					'tool_name' => $change['tool_name'],
					'action'    => $change['action'],
					'target'    => $change['target_type'] . '#' . $change['target_id'],
				)
			);

			return Axtolab_AI_Connector_Response::success(
				array(
					'requires_confirmation' => true,
					'confirmation_token'    => $issued['confirmation_token'],
					'confirmation_payload'  => $issued['confirmation_payload'],
					'change'                => $change,
					'description'           => 'Re-apply ' . self::describe_rollback( $change ) . ' (i.e. restore the post-change state captured originally).',
					'next'                  => 'Re-call this tool with the confirmation_token to execute. Token expires in 5 minutes.',
				),
				200,
				self::audit_id()
			);
		}

		try {
			Axtolab_AI_Connector_Confirmation::consume( $token, 'redo_change', 'change:' . $change_id );
		} catch ( Exception $e ) {
			return Axtolab_AI_Connector_Response::error( 'invalid_confirmation', $e->getMessage(), 400 );
		}

		// Synthesise a "rollback-like" change where the role of
		// before/after is swapped — restoring `after` is the redo.
		$synthetic = array(
			'id'          => $change['id'],
			'target_type' => $change['target_type'],
			'target_id'   => $change['target_id'],
			'action'      => $change['action'],
			'tool_name'   => $change['tool_name'],
			'before'      => $change['after'],
			'after'       => $change['before'],
		);

		$result = self::dispatch_restore( $synthetic, $change['target_type'] );
		if ( is_wp_error( $result ) ) {
			return self::from_wp_error( $result );
		}

		// Record this redo in its own changelog entry, linked back
		// to the original via redo_of_change_id, and clear the
		// original's rolled_back_at marker.
		$rid = Axtolab_AI_Connector_Changelog::record(
			array(
				'target_type'       => $change['target_type'],
				'target_id'         => $change['target_id'],
				'action'            => $change['action'],
				'tool_name'         => 'wp_redo_change',
				'before'            => $change['before'],
				'after'             => $change['after'],
				'session_id'        => self::current_mcp_session_id(),
				'redo_of_change_id' => (int) $change['id'],
				'note'              => 'Redo of change #' . $change['id'],
			)
		);

		Axtolab_AI_Connector_Changelog::clear_rolled_back( (int) $change['id'] );

		return Axtolab_AI_Connector_Response::success(
			array(
				'redone_change_id' => (int) $change['id'],
				'redo_change_id'   => $rid ? (int) $rid : null,
				'target_type'      => $change['target_type'],
				'target_id'        => $change['target_id'],
				'message'          => 'Change #' . $change['id'] . ' re-applied successfully.',
			),
			200,
			self::audit_id()
		);
	}

	/**
	 * Restore a snapshot for a given target type — used by the
	 * redo flow to share the per-type restore logic without going
	 * through the full rollback handler (which records its own
	 * changelog row + marks-rolled-back).
	 *
	 * @param array  $change       Synthetic change row with `before` set
	 *                             to the snapshot to restore.
	 * @param string $target_type
	 * @return true|WP_Error
	 */
	private static function dispatch_restore( array $change, $target_type ) {
		$snap = isset( $change['before'] ) && is_array( $change['before'] ) ? $change['before'] : null;
		if ( ! $snap ) {
			return new WP_Error( 'no_snapshot', 'No snapshot to restore.' );
		}
		switch ( $target_type ) {
			case 'post':
				$r = Axtolab_AI_Connector_Snapshots::restore_post( $snap );
				return is_wp_error( $r ) ? $r : true;
			case 'option':
				Axtolab_AI_Connector_Snapshots::restore_option( $snap );
				return true;
			case 'post_meta':
				Axtolab_AI_Connector_Snapshots::restore_post_meta( $snap );
				return true;
			case 'term_meta':
				Axtolab_AI_Connector_Snapshots::restore_term_meta( $snap );
				return true;
			case 'menu':
				$r = Axtolab_AI_Connector_Snapshots::restore_menu( $snap );
				return is_wp_error( $r ) ? $r : true;
			case 'term':
				$r = Axtolab_AI_Connector_Snapshots::restore_term( $snap );
				return is_wp_error( $r ) ? $r : true;
			default:
				return new WP_Error( 'unsupported_target', 'Unsupported target_type: ' . $target_type );
		}
	}

	/**
	 * Roll back a single option change. The `before` snapshot
	 * captures the value (or "did not exist"); we restore it.
	 */
	private static function execute_option_rollback( array $change ) {
		$before = isset( $change['before'] ) && is_array( $change['before'] ) ? $change['before'] : null;
		if ( ! $before ) {
			return Axtolab_AI_Connector_Response::error( 'no_before_snapshot', 'Change has no before snapshot.', 400 );
		}
		$pre = Axtolab_AI_Connector_Snapshots::capture_option( (string) $change['target_id'] );
		Axtolab_AI_Connector_Snapshots::restore_option( $before );
		$post = Axtolab_AI_Connector_Snapshots::capture_option( (string) $change['target_id'] );

		$rid = Axtolab_AI_Connector_Changelog::record(
			array(
				'target_type' => 'option',
				'target_id'   => (string) $change['target_id'],
				'action'      => Axtolab_AI_Connector_Changelog::ACTION_UPDATE,
				'tool_name'   => 'wp_rollback_change',
				'before'      => $pre,
				'after'       => $post,
				'session_id'  => self::current_mcp_session_id(),
				'note'        => 'Rollback of change #' . $change['id'],
			)
		);
		Axtolab_AI_Connector_Changelog::mark_rolled_back( (int) $change['id'], $rid ? (int) $rid : 0 );

		return Axtolab_AI_Connector_Response::success(
			array(
				'rolled_back_change_id' => (int) $change['id'],
				'rollback_change_id'    => $rid ? (int) $rid : null,
				'target_type'           => 'option',
				'target_id'             => $change['target_id'],
				'message'               => 'Option "' . $change['target_id'] . '" reverted.',
			),
			200,
			self::audit_id()
		);
	}

	/**
	 * Roll back a post_meta change. target_id format: "{post_id}:{meta_key}".
	 */
	private static function execute_post_meta_rollback( array $change ) {
		$before = isset( $change['before'] ) && is_array( $change['before'] ) ? $change['before'] : null;
		if ( ! $before || empty( $before['post_id'] ) ) {
			return Axtolab_AI_Connector_Response::error( 'no_before_snapshot', 'Change has no usable before snapshot.', 400 );
		}
		$pre = Axtolab_AI_Connector_Snapshots::capture_post_meta( (int) $before['post_id'], (string) $before['key'] );
		Axtolab_AI_Connector_Snapshots::restore_post_meta( $before );
		$post = Axtolab_AI_Connector_Snapshots::capture_post_meta( (int) $before['post_id'], (string) $before['key'] );

		$rid = Axtolab_AI_Connector_Changelog::record(
			array(
				'target_type' => 'post_meta',
				'target_id'   => (string) $change['target_id'],
				'action'      => Axtolab_AI_Connector_Changelog::ACTION_UPDATE,
				'tool_name'   => 'wp_rollback_change',
				'before'      => $pre,
				'after'       => $post,
				'session_id'  => self::current_mcp_session_id(),
				'note'        => 'Rollback of change #' . $change['id'],
			)
		);
		Axtolab_AI_Connector_Changelog::mark_rolled_back( (int) $change['id'], $rid ? (int) $rid : 0 );

		return Axtolab_AI_Connector_Response::success(
			array(
				'rolled_back_change_id' => (int) $change['id'],
				'rollback_change_id'    => $rid ? (int) $rid : null,
				'target_type'           => 'post_meta',
				'target_id'             => $change['target_id'],
				'message'               => 'post_meta on post #' . (int) $before['post_id'] . ' key "' . $before['key'] . '" reverted.',
			),
			200,
			self::audit_id()
		);
	}

	private static function execute_term_meta_rollback( array $change ) {
		$before = isset( $change['before'] ) && is_array( $change['before'] ) ? $change['before'] : null;
		if ( ! $before || empty( $before['term_id'] ) ) {
			return Axtolab_AI_Connector_Response::error( 'no_before_snapshot', 'Change has no usable before snapshot.', 400 );
		}
		$pre = Axtolab_AI_Connector_Snapshots::capture_term_meta( (int) $before['term_id'], (string) $before['key'] );
		Axtolab_AI_Connector_Snapshots::restore_term_meta( $before );
		$post = Axtolab_AI_Connector_Snapshots::capture_term_meta( (int) $before['term_id'], (string) $before['key'] );

		$rid = Axtolab_AI_Connector_Changelog::record(
			array(
				'target_type' => 'term_meta',
				'target_id'   => (string) $change['target_id'],
				'action'      => Axtolab_AI_Connector_Changelog::ACTION_UPDATE,
				'tool_name'   => 'wp_rollback_change',
				'before'      => $pre,
				'after'       => $post,
				'session_id'  => self::current_mcp_session_id(),
				'note'        => 'Rollback of change #' . $change['id'],
			)
		);
		Axtolab_AI_Connector_Changelog::mark_rolled_back( (int) $change['id'], $rid ? (int) $rid : 0 );

		return Axtolab_AI_Connector_Response::success(
			array(
				'rolled_back_change_id' => (int) $change['id'],
				'rollback_change_id'    => $rid ? (int) $rid : null,
				'target_type'           => 'term_meta',
				'target_id'             => $change['target_id'],
				'message'               => 'term_meta on term #' . (int) $before['term_id'] . ' key "' . $before['key'] . '" reverted.',
			),
			200,
			self::audit_id()
		);
	}

	private static function execute_term_rollback( array $change ) {
		$action   = $change['action'];
		$taxonomy = '';
		$tid      = 0;
		// target_id format: "{taxonomy}:{term_id}".
		if ( false !== strpos( (string) $change['target_id'], ':' ) ) {
			list( $taxonomy, $tid_str ) = explode( ':', (string) $change['target_id'], 2 );
			$tid                        = (int) $tid_str;
		}

		if ( 'create' === $action ) {
			// Rollback of a create = delete the term.
			if ( ! $taxonomy || ! $tid ) {
				return Axtolab_AI_Connector_Response::error( 'snapshot_invalid', 'Could not parse target_id.', 400 );
			}
			$pre = Axtolab_AI_Connector_Snapshots::capture_term( $tid, $taxonomy );
			$res = wp_delete_term( $tid, $taxonomy );
			if ( is_wp_error( $res ) ) {
				return self::from_wp_error( $res );
			}
			$rid = Axtolab_AI_Connector_Changelog::record(
				array(
					'target_type' => 'term',
					'target_id'   => $taxonomy . ':' . $tid,
					'action'      => Axtolab_AI_Connector_Changelog::ACTION_DELETE,
					'tool_name'   => 'wp_rollback_change',
					'before'      => $pre,
					'after'       => null,
					'session_id'  => self::current_mcp_session_id(),
					'note'        => 'Rollback of change #' . $change['id'],
				)
			);
			Axtolab_AI_Connector_Changelog::mark_rolled_back( (int) $change['id'], $rid ? (int) $rid : 0 );
			return Axtolab_AI_Connector_Response::success(
				array(
					'rolled_back_change_id' => (int) $change['id'],
					'rollback_change_id'    => $rid ? (int) $rid : null,
					'target_type'           => 'term',
					'target_id'             => $taxonomy . ':' . $tid,
					'message'               => 'Term ' . $taxonomy . '#' . $tid . ' deleted.',
				),
				200,
				self::audit_id()
			);
		}

		// update or delete -> restore the before snapshot.
		$before = isset( $change['before'] ) && is_array( $change['before'] ) ? $change['before'] : null;
		if ( ! $before ) {
			return Axtolab_AI_Connector_Response::error( 'no_before_snapshot', 'Change has no before snapshot.', 400 );
		}
		$pre    = $tid ? Axtolab_AI_Connector_Snapshots::capture_term( $tid, $taxonomy ) : null;
		$result = Axtolab_AI_Connector_Snapshots::restore_term( $before );
		if ( is_wp_error( $result ) ) {
			return self::from_wp_error( $result );
		}
		$post = Axtolab_AI_Connector_Snapshots::capture_term( (int) $result, $before['taxonomy'] );
		$rid  = Axtolab_AI_Connector_Changelog::record(
			array(
				'target_type' => 'term',
				'target_id'   => $before['taxonomy'] . ':' . (int) $result,
				'action'      => Axtolab_AI_Connector_Changelog::ACTION_UPDATE,
				'tool_name'   => 'wp_rollback_change',
				'before'      => $pre,
				'after'       => $post,
				'session_id'  => self::current_mcp_session_id(),
				'note'        => 'Rollback of change #' . $change['id'],
			)
		);
		Axtolab_AI_Connector_Changelog::mark_rolled_back( (int) $change['id'], $rid ? (int) $rid : 0 );

		return Axtolab_AI_Connector_Response::success(
			array(
				'rolled_back_change_id' => (int) $change['id'],
				'rollback_change_id'    => $rid ? (int) $rid : null,
				'target_type'           => 'term',
				'target_id'             => $before['taxonomy'] . ':' . (int) $result,
				'message'               => 'Term restored.',
			),
			200,
			self::audit_id()
		);
	}

	private static function execute_menu_rollback( array $change ) {
		$before = isset( $change['before'] ) && is_array( $change['before'] ) ? $change['before'] : null;
		if ( ! $before || empty( $before['menu_id'] ) ) {
			return Axtolab_AI_Connector_Response::error( 'no_before_snapshot', 'Change has no usable before snapshot.', 400 );
		}
		$pre    = Axtolab_AI_Connector_Snapshots::capture_menu( (int) $before['menu_id'] );
		$result = Axtolab_AI_Connector_Snapshots::restore_menu( $before );
		if ( is_wp_error( $result ) ) {
			return self::from_wp_error( $result );
		}
		$post = Axtolab_AI_Connector_Snapshots::capture_menu( (int) $before['menu_id'] );

		$rid = Axtolab_AI_Connector_Changelog::record(
			array(
				'target_type' => 'menu',
				'target_id'   => (string) $before['menu_id'],
				'action'      => Axtolab_AI_Connector_Changelog::ACTION_UPDATE,
				'tool_name'   => 'wp_rollback_change',
				'before'      => $pre,
				'after'       => $post,
				'session_id'  => self::current_mcp_session_id(),
				'note'        => 'Rollback of change #' . $change['id'],
			)
		);
		Axtolab_AI_Connector_Changelog::mark_rolled_back( (int) $change['id'], $rid ? (int) $rid : 0 );

		return Axtolab_AI_Connector_Response::success(
			array(
				'rolled_back_change_id' => (int) $change['id'],
				'rollback_change_id'    => $rid ? (int) $rid : null,
				'target_type'           => 'menu',
				'target_id'             => $before['menu_id'],
				'message'               => 'Menu #' . (int) $before['menu_id'] . ' restored.',
			),
			200,
			self::audit_id()
		);
	}

	/**
	 * Execute the actual undo for a post-typed change.
	 *
	 * @param array $change                     Hydrated changelog row including snapshots.
	 * @param bool  $allow_concurrent_override
	 * @return WP_REST_Response
	 */
	private static function execute_post_rollback( array $change, $allow_concurrent_override ) {
		$action  = $change['action'];
		$post_id = (int) $change['target_id'];

		$current = get_post( $post_id );

		// Concurrent-edit guard. We consider an edit concurrent if the
		// post's current modified_gmt is later than the snapshot's
		// "after" modified_gmt — meaning someone (or the AI itself)
		// touched the post after our recorded change.
		$after_modified = isset( $change['after']['post']['post_modified_gmt'] )
			? (string) $change['after']['post']['post_modified_gmt']
			: '';

		if ( $current instanceof WP_Post && $after_modified !== '' && ! $allow_concurrent_override ) {
			if ( $current->post_modified_gmt > $after_modified ) {
				return Axtolab_AI_Connector_Response::error(
					'concurrent_edit',
					'Post #' . $post_id . ' was modified at ' . $current->post_modified_gmt . ' which is after the change being rolled back (recorded at ' . $after_modified . '). Re-run with allow_concurrent_edit_override=true to force the rollback.',
					409
				);
			}
		}

		$pre_rollback = Axtolab_AI_Connector_Snapshots::capture_post( $post_id );

		if ( 'create' === $action ) {
			// Undoing a create = delete the post.
			if ( ! ( $current instanceof WP_Post ) ) {
				return Axtolab_AI_Connector_Response::error( 'already_deleted', 'Post #' . $post_id . ' no longer exists.', 410 );
			}
			$deleted = wp_delete_post( $post_id, true );
			if ( ! $deleted ) {
				return Axtolab_AI_Connector_Response::error( 'delete_failed', 'Could not delete post.', 500 );
			}
			$rollback_action = Axtolab_AI_Connector_Changelog::ACTION_DELETE;
		} else {
			// All other actions: restore the `before` snapshot.
			$before = isset( $change['before'] ) && is_array( $change['before'] ) ? $change['before'] : null;
			if ( ! $before ) {
				return Axtolab_AI_Connector_Response::error(
					'no_before_snapshot',
					'Change #' . $change['id'] . ' has no `before` snapshot to restore from.',
					400
				);
			}
			$restored = Axtolab_AI_Connector_Snapshots::restore_post( $before );
			if ( is_wp_error( $restored ) ) {
				return self::from_wp_error( $restored );
			}
			$rollback_action = Axtolab_AI_Connector_Changelog::ACTION_UPDATE;
		}

		$post_rollback = Axtolab_AI_Connector_Snapshots::capture_post( $post_id );

		// Record the rollback as its own changelog entry, linked to
		// the original via rollback_change_id on the original row.
		$rollback_id = Axtolab_AI_Connector_Changelog::record(
			array(
				'target_type' => 'post',
				'target_id'   => (string) $post_id,
				'action'      => $rollback_action,
				'tool_name'   => 'wp_rollback_change',
				'before'      => $pre_rollback,
				'after'       => $post_rollback,
				'session_id'  => self::current_mcp_session_id(),
				'note'        => 'Rollback of change #' . $change['id'],
			)
		);

		if ( $rollback_id ) {
			Axtolab_AI_Connector_Changelog::mark_rolled_back( (int) $change['id'], (int) $rollback_id );
		} else {
			// Best-effort still mark even if the rollback row insert failed.
			Axtolab_AI_Connector_Changelog::mark_rolled_back( (int) $change['id'], 0 );
		}

		return Axtolab_AI_Connector_Response::success(
			array(
				'rolled_back_change_id' => (int) $change['id'],
				'rollback_change_id'    => $rollback_id ? (int) $rollback_id : null,
				'target_type'           => 'post',
				'target_id'             => $post_id,
				'message'               => 'Change #' . $change['id'] . ' rolled back successfully.',
			),
			200,
			self::audit_id()
		);
	}

	/**
	 * Human-readable summary of what a rollback will do, included
	 * in the issue-token response so the AI can show the user.
	 *
	 * @param array $change Hydrated changelog row.
	 * @return string
	 */
	private static function describe_rollback( array $change ) {
		$target = $change['target_type'] . ' #' . $change['target_id'];
		$action = $change['action'];

		if ( 'create' === $action ) {
			return 'Permanently delete ' . $target . ' (it was originally created by ' . $change['tool_name'] . ').';
		}
		if ( 'trash' === $action ) {
			return 'Restore ' . $target . ' from trash.';
		}
		if ( 'restore' === $action ) {
			return 'Move ' . $target . ' back to trash.';
		}
		if ( 'publish' === $action ) {
			$prior = isset( $change['before']['post']['post_status'] )
				? (string) $change['before']['post']['post_status']
				: 'draft';
			return 'Revert ' . $target . ' to status "' . $prior . '" (un-publish).';
		}
		if ( 'update' === $action ) {
			return 'Revert ' . $target . ' to its prior state (title, content, meta, terms, featured image).';
		}
		return 'Revert change #' . $change['id'] . ' on ' . $target . '.';
	}

	public static function handle_content_types( WP_REST_Request $request ): WP_REST_Response {
		$config  = Axtolab_AI_Connector_Config::get();
		$allowed = isset( $config['allowed_content_types'] ) ? (array) $config['allowed_content_types'] : array();

		// When the allowlist is wildcard `*`, expand to the actual list of
		// registered public post types so AI clients see what's available
		// rather than the literal `*`. Returns the same shape either way.
		if ( in_array( '*', $allowed, true ) ) {
			$public_types = array_keys( get_post_types( array( 'public' => true ) ) );
			return Axtolab_AI_Connector_Response::success( array_values( $public_types ), 200, self::audit_id() );
		}

		return Axtolab_AI_Connector_Response::success( array_values( $allowed ), 200, self::audit_id() );
	}

	public static function handle_find_content( WP_REST_Request $request ): WP_REST_Response {
		$config        = Axtolab_AI_Connector_Config::get();
		$content_type  = $request->get_param( 'content_type' );
		$allowed_types = array_map( 'strval', (array) $config['allowed_content_types'] );

		if ( is_string( $content_type ) && '' !== $content_type ) {
			$allowed_check = Axtolab_AI_Connector_Policy::assert_allowed_content_type( $content_type );
			if ( is_wp_error( $allowed_check ) ) {
				return self::from_wp_error( $allowed_check );
			}
			$post_types = array( $content_type );
		} else {
			$post_types = $allowed_types;
		}

		$args = array(
			'post_type'      => $post_types,
			'post_status'    => $request->get_param( 'status' ) ?: array( 'draft', 'pending', 'future', 'publish', 'private', 'trash' ),
			's'              => $request->get_param( 'search' ) ?: '',
			'author'         => $request->get_param( 'author' ) ? intval( $request->get_param( 'author' ) ) : 0,
			'paged'          => max( 1, intval( $request->get_param( 'page' ) ?: 1 ) ),
			'posts_per_page' => max( 1, min( 100, intval( $request->get_param( 'per_page' ) ?: 20 ) ) ),
		);

		$parent_taxonomy  = (string) $request->get_param( 'parent_taxonomy' );
		$parent_term_slug = (string) $request->get_param( 'parent_term_slug' );
		$solutions_only   = rest_sanitize_boolean( $request->get_param( 'solutions_only' ) );
		if ( '' === $parent_taxonomy ) {
			$parent_taxonomy = 'featured_item_category';
		}

		if ( $solutions_only && '' === $parent_term_slug ) {
			$parent_term_slug = (string) $config['solutions_root_slug'];
		}

		if ( '' !== $parent_term_slug ) {
			$taxonomy_allowed = Axtolab_AI_Connector_Policy::assert_allowed_taxonomy( $parent_taxonomy );
			if ( is_wp_error( $taxonomy_allowed ) ) {
				return self::from_wp_error( $taxonomy_allowed );
			}

			$term = get_term_by( 'slug', $parent_term_slug, $parent_taxonomy );
			if ( ! $term instanceof WP_Term ) {
				return Axtolab_AI_Connector_Response::error( 'parent_term_not_found', 'Parent term slug not found.', 404 );
			}

			$term_ids = array( intval( $term->term_id ) );
			$children = get_term_children( $term->term_id, $parent_taxonomy );
			if ( ! is_wp_error( $children ) ) {
				$term_ids = array_merge( $term_ids, array_map( 'intval', $children ) );
			}

			$args['tax_query'] = array(
				array(
					'taxonomy'         => $parent_taxonomy,
					'field'            => 'term_id',
					'terms'            => array_values( array_unique( $term_ids ) ),
					'include_children' => true,
				),
			);
		}

		$query = new WP_Query( $args );
		$items = array();
		foreach ( $query->posts as $post ) {
			$items[] = Axtolab_AI_Connector_Policy::to_content_record( $post );
		}

		return Axtolab_AI_Connector_Response::success( $items, 200, self::audit_id() );
	}

	public static function handle_get_content( WP_REST_Request $request ): WP_REST_Response {
		$post = self::get_post_from_request( $request );
		if ( is_wp_error( $post ) ) {
			return self::from_wp_error( $post );
		}

		$can_edit = Axtolab_AI_Connector_Policy::can_edit_content( $post->ID, $post->post_type );
		if ( is_wp_error( $can_edit ) ) {
			return self::from_wp_error( $can_edit );
		}

		return Axtolab_AI_Connector_Response::success( Axtolab_AI_Connector_Policy::to_content_record( $post ), 200, self::audit_id() );
	}

	public static function handle_create_content( WP_REST_Request $request ): WP_REST_Response {
		$denied = self::require_tool_capability( 'wp_create_draft' );
		if ( $denied ) {
			return $denied; }

		$content_type = (string) $request->get_param( 'content_type' );
		$allowed      = Axtolab_AI_Connector_Policy::assert_allowed_content_type( $content_type );
		if ( is_wp_error( $allowed ) ) {
			return self::from_wp_error( $allowed );
		}

		$post_type_obj = get_post_type_object( $content_type );
		if ( ! $post_type_obj ) {
			return Axtolab_AI_Connector_Response::error( 'invalid_post_type', 'Invalid post type.', 400 );
		}

		$edit_cap = $post_type_obj->cap->edit_posts ?? 'edit_posts';
		if ( ! current_user_can( $edit_cap ) ) {
			return Axtolab_AI_Connector_Response::error( 'forbidden_edit', 'Current user cannot create this content type.', 403 );
		}

		$author = intval( $request->get_param( 'author' ) ?: get_current_user_id() );

		// Server-side author restriction.
		$author_denied = self::require_allowed_author( $author );
		if ( $author_denied ) {
			return $author_denied; }

		$author_check = Axtolab_AI_Connector_Policy::assert_allowed_author( $author );
		if ( is_wp_error( $author_check ) ) {
			return self::from_wp_error( $author_check );
		}

		$postarr = array(
			'post_type'    => $content_type,
			'post_status'  => 'draft',
			'post_title'   => (string) $request->get_param( 'title' ),
			'post_content' => (string) $request->get_param( 'content' ),
			'post_excerpt' => (string) $request->get_param( 'excerpt' ),
			'post_name'    => (string) $request->get_param( 'slug' ),
			'post_author'  => $author,
		);

		if ( $request->get_param( 'date' ) ) {
			$postarr['post_date_gmt'] = (string) $request->get_param( 'date' );
		}

		$post_id = wp_insert_post( wp_slash( $postarr ), true );
		if ( is_wp_error( $post_id ) ) {
			return self::from_wp_error( $post_id );
		}

		$set_terms = self::apply_terms( $post_id, (array) $request->get_param( 'terms' ) );
		if ( is_wp_error( $set_terms ) ) {
			return self::from_wp_error( $set_terms );
		}

		$set_yoast = self::apply_yoast_meta( $post_id, (array) $request->get_param( 'yoast_meta' ) );
		if ( is_wp_error( $set_yoast ) ) {
			return self::from_wp_error( $set_yoast );
		}

		$post = get_post( $post_id );
		if ( ! $post instanceof WP_Post ) {
			return Axtolab_AI_Connector_Response::error( 'create_failed', 'Post created but lookup failed.', 500 );
		}

		self::record_post_change( $post_id, Axtolab_AI_Connector_Changelog::ACTION_CREATE, 'wp_create_draft', null );

		return Axtolab_AI_Connector_Response::success( Axtolab_AI_Connector_Policy::to_content_record( $post ), 201, self::audit_id() );
	}

	public static function handle_clone_content( WP_REST_Request $request ): WP_REST_Response {
		$denied = self::require_tool_capability( 'wp_clone_content' );
		if ( $denied ) {
			return $denied; }

		$post = self::get_post_from_request( $request );
		if ( is_wp_error( $post ) ) {
			return self::from_wp_error( $post );
		}

		$content_type = $post->post_type;
		$allowed      = Axtolab_AI_Connector_Policy::assert_allowed_content_type( $content_type );
		if ( is_wp_error( $allowed ) ) {
			return self::from_wp_error( $allowed );
		}

		$new_title = (string) $request->get_param( 'title' );
		if ( empty( $new_title ) ) {
			$new_title = $post->post_title . ' (Copy)';
		}

		$new_post = array(
			'post_type'    => $post->post_type,
			'post_title'   => $new_title,
			'post_content' => $post->post_content,
			'post_excerpt' => $post->post_excerpt,
			'post_status'  => 'draft',
			'post_author'  => get_current_user_id(),
		);

		$new_id = wp_insert_post( wp_slash( $new_post ), true );
		if ( is_wp_error( $new_id ) ) {
			return self::from_wp_error( $new_id );
		}

		// Copy taxonomies
		$taxonomies = get_object_taxonomies( $content_type );
		foreach ( $taxonomies as $taxonomy ) {
			$terms = wp_get_object_terms( $post->ID, $taxonomy, array( 'fields' => 'ids' ) );
			if ( ! is_wp_error( $terms ) && ! empty( $terms ) ) {
				wp_set_object_terms( $new_id, $terms, $taxonomy );
			}
		}

		// Copy featured image
		$thumbnail_id = get_post_thumbnail_id( $post->ID );
		if ( $thumbnail_id ) {
			set_post_thumbnail( $new_id, $thumbnail_id );
		}

		// Copy Yoast meta (if applicable)
		$yoast_keys = array( '_yoast_wpseo_title', '_yoast_wpseo_metadesc', '_yoast_wpseo_focuskw' );
		foreach ( $yoast_keys as $key ) {
			$value = get_post_meta( $post->ID, $key, true );
			if ( '' !== $value ) {
				update_post_meta( $new_id, $key, $value );
			}
		}

		$new_post_obj = get_post( $new_id );

		self::record_post_change( $new_id, Axtolab_AI_Connector_Changelog::ACTION_CREATE, 'wp_clone_content', null );

		return Axtolab_AI_Connector_Response::success( Axtolab_AI_Connector_Policy::to_content_record( $new_post_obj ), 201, self::audit_id() );
	}

	public static function handle_update_content( WP_REST_Request $request ): WP_REST_Response {
		$denied = self::require_tool_capability( 'wp_update_content' );
		if ( $denied ) {
			return $denied; }

		$post = self::get_post_from_request( $request );
		if ( is_wp_error( $post ) ) {
			return self::from_wp_error( $post );
		}

		$can_edit = Axtolab_AI_Connector_Policy::can_edit_content( $post->ID, $post->post_type );
		if ( is_wp_error( $can_edit ) ) {
			return self::from_wp_error( $can_edit );
		}

		$before_snapshot = Axtolab_AI_Connector_Snapshots::capture_post( $post->ID );

		$patch = $request->get_param( 'patch' );
		if ( ! is_array( $patch ) ) {
			return Axtolab_AI_Connector_Response::error( 'invalid_patch', 'patch object is required.', 400 );
		}

		$allowed_patch = Axtolab_AI_Connector_Policy::assert_patch_fields( $patch );
		if ( is_wp_error( $allowed_patch ) ) {
			return self::from_wp_error( $allowed_patch );
		}

		if ( array_key_exists( 'date', $patch ) ) {
			$date_allowed = Axtolab_AI_Connector_Free_Gates::check_update_date_allowed( (string) $patch['date'], $post );
			if ( is_wp_error( $date_allowed ) ) {
				return self::from_wp_error( $date_allowed );
			}
		}

		if ( array_key_exists( 'status', $patch ) ) {
			$status_allowed = Axtolab_AI_Connector_Free_Gates::check_update_status_allowed( (string) $patch['status'] );
			if ( is_wp_error( $status_allowed ) ) {
				return self::from_wp_error( $status_allowed );
			}
		}

		$postarr = array( 'ID' => $post->ID );

		if ( array_key_exists( 'title', $patch ) ) {
			$postarr['post_title'] = (string) $patch['title'];
		}
		if ( array_key_exists( 'content', $patch ) ) {
			$postarr['post_content'] = (string) $patch['content'];
		}
		if ( array_key_exists( 'excerpt', $patch ) ) {
			$postarr['post_excerpt'] = (string) $patch['excerpt'];
		}
		if ( array_key_exists( 'slug', $patch ) ) {
			$postarr['post_name'] = (string) $patch['slug'];
		}
		if ( array_key_exists( 'status', $patch ) ) {
			$postarr['post_status'] = (string) $patch['status'];
		}
		if ( array_key_exists( 'date', $patch ) ) {
			$postarr['post_date_gmt'] = (string) $patch['date'];
		}
		if ( array_key_exists( 'author', $patch ) ) {
			$author_id = intval( $patch['author'] );
			$author_ok = Axtolab_AI_Connector_Policy::assert_allowed_author( $author_id );
			if ( is_wp_error( $author_ok ) ) {
				return self::from_wp_error( $author_ok );
			}
			$postarr['post_author'] = $author_id;
		}

		$result = wp_update_post( wp_slash( $postarr ), true );
		if ( is_wp_error( $result ) ) {
			return self::from_wp_error( $result );
		}

		if ( array_key_exists( 'featured_media', $patch ) ) {
			$media_id = intval( $patch['featured_media'] );
			if ( $media_id > 0 ) {
				set_post_thumbnail( $post->ID, $media_id );
			} else {
				delete_post_thumbnail( $post->ID );
			}
		}

		$updated = get_post( $post->ID );
		if ( ! $updated instanceof WP_Post ) {
			return Axtolab_AI_Connector_Response::error( 'update_failed', 'Updated post lookup failed.', 500 );
		}

		self::record_post_change( $post->ID, Axtolab_AI_Connector_Changelog::ACTION_UPDATE, 'wp_update_content', $before_snapshot );

		return Axtolab_AI_Connector_Response::success( Axtolab_AI_Connector_Policy::to_content_record( $updated ), 200, self::audit_id() );
	}

	public static function handle_publish_content( WP_REST_Request $request ): WP_REST_Response {
		$denied = self::require_tool_capability( 'wp_publish_content' );
		if ( $denied ) {
			return $denied; }

		$post = self::get_post_from_request( $request );
		if ( is_wp_error( $post ) ) {
			return self::from_wp_error( $post );
		}

		$can_publish = Axtolab_AI_Connector_Policy::can_publish_content( $post->post_type );
		if ( is_wp_error( $can_publish ) ) {
			return self::from_wp_error( $can_publish );
		}

		$publish_reservation = Axtolab_AI_Connector_Free_Gates::reserve_publish_request( $request, $post );
		if ( is_wp_error( $publish_reservation ) ) {
			return self::from_wp_error( $publish_reservation );
		}

		$before_snapshot = Axtolab_AI_Connector_Snapshots::capture_post( $post->ID );

		$postarr = array( 'ID' => $post->ID );
		$date    = (string) $request->get_param( 'date' );
		if ( '' !== $date ) {
			$postarr['post_date_gmt'] = $date;
			$postarr['post_status']   = 'future';
		} else {
			$postarr['post_status'] = 'publish';
		}

		$result = wp_update_post( wp_slash( $postarr ), true );
		if ( is_wp_error( $result ) ) {
			Axtolab_AI_Connector_Free_Gates::release_publish_reservation( $publish_reservation );
			return self::from_wp_error( $result );
		}

		$updated           = get_post( $post->ID );
		$publish_completed = Axtolab_AI_Connector_Free_Gates::confirm_publish_completed( $publish_reservation, $updated, array( 'future', 'publish' ) );
		if ( is_wp_error( $publish_completed ) ) {
			return self::from_wp_error( $publish_completed );
		}

		self::record_post_change( $post->ID, Axtolab_AI_Connector_Changelog::ACTION_PUBLISH, 'wp_publish_content', $before_snapshot );

		return Axtolab_AI_Connector_Response::success( Axtolab_AI_Connector_Policy::to_content_record( $updated ), 200, self::audit_id() );
	}

	public static function handle_trash_content( WP_REST_Request $request ): WP_REST_Response {
		$denied = self::require_tool_capability( 'wp_trash_content' );
		if ( $denied ) {
			return $denied; }

		$post = self::get_post_from_request( $request );
		if ( is_wp_error( $post ) ) {
			return self::from_wp_error( $post );
		}

		$can_delete = Axtolab_AI_Connector_Policy::can_delete_content( $post->ID );
		if ( is_wp_error( $can_delete ) ) {
			return self::from_wp_error( $can_delete );
		}

		$before_snapshot = Axtolab_AI_Connector_Snapshots::capture_post( $post->ID );

		$result = wp_trash_post( $post->ID );
		if ( false === $result ) {
			return Axtolab_AI_Connector_Response::error( 'trash_failed', 'Could not move post to trash.', 500 );
		}

		$updated = get_post( $post->ID );

		self::record_post_change( $post->ID, Axtolab_AI_Connector_Changelog::ACTION_TRASH, 'wp_trash_content', $before_snapshot );

		return Axtolab_AI_Connector_Response::success( Axtolab_AI_Connector_Policy::to_content_record( $updated ), 200, self::audit_id() );
	}

	public static function handle_restore_content( WP_REST_Request $request ): WP_REST_Response {
		$denied = self::require_tool_capability( 'wp_restore_content' );
		if ( $denied ) {
			return $denied; }

		$post = self::get_post_from_request( $request, true );
		if ( is_wp_error( $post ) ) {
			return self::from_wp_error( $post );
		}

		$can_delete = Axtolab_AI_Connector_Policy::can_delete_content( $post->ID );
		if ( is_wp_error( $can_delete ) ) {
			return self::from_wp_error( $can_delete );
		}

		$before_snapshot = Axtolab_AI_Connector_Snapshots::capture_post( $post->ID );

		$result = wp_untrash_post( $post->ID );
		if ( ! $result ) {
			return Axtolab_AI_Connector_Response::error( 'restore_failed', 'Could not restore post from trash.', 500 );
		}

		$updated = get_post( $post->ID );

		self::record_post_change( $post->ID, Axtolab_AI_Connector_Changelog::ACTION_RESTORE, 'wp_restore_content', $before_snapshot );

		return Axtolab_AI_Connector_Response::success( Axtolab_AI_Connector_Policy::to_content_record( $updated ), 200, self::audit_id() );
	}

	public static function handle_list_revisions( WP_REST_Request $request ): WP_REST_Response {
		$post = self::get_post_from_request( $request );
		if ( is_wp_error( $post ) ) {
			return self::from_wp_error( $post );
		}

		$can_edit = Axtolab_AI_Connector_Policy::can_edit_content( $post->ID, $post->post_type );
		if ( is_wp_error( $can_edit ) ) {
			return self::from_wp_error( $can_edit );
		}

		$revisions = wp_get_post_revisions( $post->ID );
		$data      = array();
		foreach ( $revisions as $revision ) {
			$data[] = array(
				'id'       => intval( $revision->ID ),
				'parent'   => intval( $revision->post_parent ),
				'author'   => intval( $revision->post_author ),
				'date'     => $revision->post_date_gmt,
				'modified' => $revision->post_modified_gmt,
				'title'    => $revision->post_title,
			);
		}

		return Axtolab_AI_Connector_Response::success( $data, 200, self::audit_id() );
	}

	public static function handle_restore_revision( WP_REST_Request $request ): WP_REST_Response {
		$denied = self::require_tool_capability( 'wp_restore_revision' );
		if ( $denied ) {
			return $denied; }

		$post = self::get_post_from_request( $request );
		if ( is_wp_error( $post ) ) {
			return self::from_wp_error( $post );
		}

		$can_edit = Axtolab_AI_Connector_Policy::can_edit_content( $post->ID, $post->post_type );
		if ( is_wp_error( $can_edit ) ) {
			return self::from_wp_error( $can_edit );
		}

		$before_snapshot = Axtolab_AI_Connector_Snapshots::capture_post( $post->ID );

		$revision_id = intval( $request->get_param( 'revision_id' ) );
		$result      = wp_restore_post_revision( $revision_id );
		if ( ! $result ) {
			return Axtolab_AI_Connector_Response::error( 'restore_revision_failed', 'Could not restore revision.', 500 );
		}

		$updated = get_post( $post->ID );

		self::record_post_change( $post->ID, Axtolab_AI_Connector_Changelog::ACTION_UPDATE, 'wp_restore_revision', $before_snapshot );

		return Axtolab_AI_Connector_Response::success( Axtolab_AI_Connector_Policy::to_content_record( $updated ), 200, self::audit_id() );
	}

	/**
	 * GET /users — list users with filters. Read-only; user CRUD
	 * is intentionally not in core (security-sensitive; lives in
	 * the User Management add-on).
	 *
	 * Query params: search, role, per_page (max 100), offset.
	 *
	 * @return WP_REST_Response
	 */
	public static function handle_list_users( WP_REST_Request $request ) {
		$args   = array(
			'fields' => 'all_with_meta',
			'number' => min( 100, max( 1, (int) ( $request->get_param( 'per_page' ) ?: 20 ) ) ),
			'offset' => max( 0, (int) $request->get_param( 'offset' ) ),
		);
		$search = (string) $request->get_param( 'search' );
		if ( '' !== $search ) {
			$args['search']         = '*' . $search . '*';
			$args['search_columns'] = array( 'user_login', 'user_email', 'display_name', 'user_nicename' );
		}
		$role = (string) $request->get_param( 'role' );
		if ( '' !== $role ) {
			$args['role'] = $role;
		}

		$users = get_users( $args );
		$data  = array();
		foreach ( $users as $u ) {
			$data[] = self::format_user_row( $u );
		}

		// Total count for pagination.
		$total_args = $args;
		unset( $total_args['number'], $total_args['offset'], $total_args['fields'] );
		$total_args['count_total'] = true;
		$total_args['fields']      = 'ID';
		$total                     = (int) count( get_users( $total_args ) );

		return Axtolab_AI_Connector_Response::success(
			array(
				'count'    => count( $data ),
				'total'    => $total,
				'per_page' => $args['number'],
				'offset'   => $args['offset'],
				'users'    => $data,
			),
			200,
			self::audit_id()
		);
	}

	/**
	 * GET /users/{id} — fetch a single user.
	 *
	 * @return WP_REST_Response
	 */
	public static function handle_get_user( WP_REST_Request $request ) {
		$user = get_user_by( 'id', (int) $request->get_param( 'id' ) );
		if ( ! $user ) {
			return Axtolab_AI_Connector_Response::error( 'not_found', 'User not found.', 404 );
		}
		return Axtolab_AI_Connector_Response::success( self::format_user_row( $user ), 200, self::audit_id() );
	}

	/**
	 * Normalise a WP_User into the API row shape. Email is included
	 * because the request is admin-gated upstream by edit_posts +
	 * the AI connection's capability set; sites needing tighter
	 * control can wire a filter (axtolab_ai_connector_user_row) below.
	 *
	 * @param WP_User $u
	 * @return array
	 */
	private static function format_user_row( $u ) {
		$row = array(
			'id'           => (int) $u->ID,
			'username'     => $u->user_login,
			'display_name' => $u->display_name,
			'email'        => $u->user_email,
			'roles'        => array_values( (array) $u->roles ),
			'registered'   => $u->user_registered,
			'url'          => $u->user_url,
			'description'  => get_user_meta( $u->ID, 'description', true ),
		);
		return apply_filters( 'axtolab_ai_connector_user_row', $row, $u );
	}

	public static function handle_list_authors( WP_REST_Request $request ): WP_REST_Response {
		$config             = Axtolab_AI_Connector_Config::get();
		$allowed_author_ids = array_map( 'intval', (array) $config['allowed_author_ids'] );

		$args = array(
			'who'    => 'authors',
			'fields' => array( 'ID', 'display_name', 'user_nicename', 'user_email' ),
		);
		if ( ! empty( $allowed_author_ids ) ) {
			$args['include'] = $allowed_author_ids;
		}

		$users = get_users( $args );
		$data  = array_map(
			static function ( $user ) {
				return array(
					'id'    => intval( $user->ID ),
					'name'  => $user->display_name,
					'slug'  => $user->user_nicename,
					'email' => $user->user_email,
				);
			},
			$users
		);

		return Axtolab_AI_Connector_Response::success( $data, 200, self::audit_id() );
	}

	public static function handle_assign_author( WP_REST_Request $request ): WP_REST_Response {
		$denied = self::require_tool_capability( 'wp_assign_author' );
		if ( $denied ) {
			return $denied; }

		$post = self::get_post_from_request( $request );
		if ( is_wp_error( $post ) ) {
			return self::from_wp_error( $post );
		}

		$author_id = intval( $request->get_param( 'author_id' ) );

		// Server-side author restriction.
		$author_denied = self::require_allowed_author( $author_id );
		if ( $author_denied ) {
			return $author_denied; }

		$author_allowed = Axtolab_AI_Connector_Policy::assert_allowed_author( $author_id );
		if ( is_wp_error( $author_allowed ) ) {
			return self::from_wp_error( $author_allowed );
		}

		if ( ! current_user_can( 'edit_others_posts' ) ) {
			return Axtolab_AI_Connector_Response::error( 'forbidden_assign_author', 'Current user cannot assign authors.', 403 );
		}

		$result = wp_update_post(
			array(
				'ID'          => $post->ID,
				'post_author' => $author_id,
			),
			true
		);

		if ( is_wp_error( $result ) ) {
			return self::from_wp_error( $result );
		}

		$updated = get_post( $post->ID );
		return Axtolab_AI_Connector_Response::success( Axtolab_AI_Connector_Policy::to_content_record( $updated ), 200, self::audit_id() );
	}

	public static function handle_list_terms( WP_REST_Request $request ): WP_REST_Response {
		$taxonomy = (string) $request->get_param( 'taxonomy' );
		$allowed  = Axtolab_AI_Connector_Policy::assert_allowed_taxonomy( $taxonomy );
		if ( is_wp_error( $allowed ) ) {
			return self::from_wp_error( $allowed );
		}

		$args = array(
			'taxonomy'   => $taxonomy,
			'hide_empty' => false,
			'search'     => (string) $request->get_param( 'search' ),
			'number'     => max( 1, min( 100, intval( $request->get_param( 'per_page' ) ?: 50 ) ) ),
		);

		if ( null !== $request->get_param( 'parent' ) ) {
			$args['parent'] = intval( $request->get_param( 'parent' ) );
		}

		$terms = get_terms( $args );
		if ( is_wp_error( $terms ) ) {
			return self::from_wp_error( $terms );
		}

		$data = array_map(
			static function ( WP_Term $term ) {
				return array(
					'id'       => intval( $term->term_id ),
					'taxonomy' => $term->taxonomy,
					'name'     => $term->name,
					'slug'     => $term->slug,
					'parent'   => intval( $term->parent ),
				);
			},
			$terms
		);

		return Axtolab_AI_Connector_Response::success( $data, 200, self::audit_id() );
	}

	public static function handle_create_term( WP_REST_Request $request ): WP_REST_Response {
		$denied = self::require_tool_capability( 'wp_create_term' );
		if ( $denied ) {
			return $denied; }

		$config = Axtolab_AI_Connector_Config::get();
		if ( empty( $config['allow_term_creation'] ) ) {
			return Axtolab_AI_Connector_Response::error( 'term_creation_disabled', 'Term creation is disabled by policy.', 403 );
		}

		$taxonomy = (string) $request->get_param( 'taxonomy' );
		$allowed  = Axtolab_AI_Connector_Policy::assert_allowed_taxonomy( $taxonomy );
		if ( is_wp_error( $allowed ) ) {
			return self::from_wp_error( $allowed );
		}

		if ( ! current_user_can( 'manage_categories' ) ) {
			return Axtolab_AI_Connector_Response::error( 'forbidden_create_term', 'Current user cannot create terms.', 403 );
		}

		$result = wp_insert_term(
			(string) $request->get_param( 'name' ),
			$taxonomy,
			array(
				'slug'        => (string) $request->get_param( 'slug' ),
				'parent'      => intval( $request->get_param( 'parent' ) ?: 0 ),
				'description' => (string) $request->get_param( 'description' ),
			)
		);

		if ( is_wp_error( $result ) ) {
			return self::from_wp_error( $result );
		}

		$term = get_term( intval( $result['term_id'] ), $taxonomy );
		if ( ! $term instanceof WP_Term ) {
			return Axtolab_AI_Connector_Response::error( 'term_lookup_failed', 'Created term lookup failed.', 500 );
		}

		$after = Axtolab_AI_Connector_Snapshots::capture_term( (int) $term->term_id, $taxonomy );
		self::record_change( 'term', $taxonomy . ':' . $term->term_id, Axtolab_AI_Connector_Changelog::ACTION_CREATE, 'wp_create_term', null, $after );

		$data = array(
			'id'       => intval( $term->term_id ),
			'taxonomy' => $term->taxonomy,
			'name'     => $term->name,
			'slug'     => $term->slug,
			'parent'   => intval( $term->parent ),
		);

		return Axtolab_AI_Connector_Response::success( $data, 201, self::audit_id() );
	}

	/**
	 * PATCH /taxonomies/{taxonomy}/terms/{term_id} — update term
	 * fields (name, slug, description, parent). Captured for rollback.
	 */
	public static function handle_update_term( WP_REST_Request $request ) {
		$denied = self::require_tool_capability( 'wp_update_term' );
		if ( $denied ) {
			return $denied; }

		$taxonomy = (string) $request->get_param( 'taxonomy' );
		$allowed  = Axtolab_AI_Connector_Policy::assert_allowed_taxonomy( $taxonomy );
		if ( is_wp_error( $allowed ) ) {
			return self::from_wp_error( $allowed );
		}

		if ( ! current_user_can( 'manage_categories' ) ) {
			return Axtolab_AI_Connector_Response::error( 'forbidden_update_term', 'Current user cannot update terms.', 403 );
		}

		$term_id = (int) $request->get_param( 'term_id' );
		$term    = get_term( $term_id, $taxonomy );
		if ( ! $term || is_wp_error( $term ) ) {
			return Axtolab_AI_Connector_Response::error( 'not_found', 'Term not found.', 404 );
		}

		$body = $request->get_json_params();
		if ( ! is_array( $body ) ) {
			$body = array(); }

		$args = array();
		if ( array_key_exists( 'name', $body ) ) {
			$args['name'] = (string) $body['name']; }
		if ( array_key_exists( 'slug', $body ) ) {
			$args['slug'] = (string) $body['slug']; }
		if ( array_key_exists( 'description', $body ) ) {
			$args['description'] = (string) $body['description']; }
		if ( array_key_exists( 'parent', $body ) ) {
			$args['parent'] = (int) $body['parent']; }

		if ( empty( $args ) ) {
			return Axtolab_AI_Connector_Response::error( 'missing_fields', 'Provide at least one of: name, slug, description, parent.', 400 );
		}

		$before = Axtolab_AI_Connector_Snapshots::capture_term( $term_id, $taxonomy );

		$result = wp_update_term( $term_id, $taxonomy, $args );
		if ( is_wp_error( $result ) ) {
			return self::from_wp_error( $result );
		}

		$after = Axtolab_AI_Connector_Snapshots::capture_term( $term_id, $taxonomy );
		self::record_change( 'term', $taxonomy . ':' . $term_id, Axtolab_AI_Connector_Changelog::ACTION_UPDATE, 'wp_update_term', $before, $after );

		$updated = get_term( $term_id, $taxonomy );
		return Axtolab_AI_Connector_Response::success(
			array(
				'id'          => (int) $updated->term_id,
				'taxonomy'    => $updated->taxonomy,
				'name'        => $updated->name,
				'slug'        => $updated->slug,
				'description' => $updated->description,
				'parent'      => (int) $updated->parent,
			),
			200,
			self::audit_id()
		);
	}

	/**
	 * DELETE /taxonomies/{taxonomy}/terms/{term_id} — delete a term.
	 * Posts using the term remain (as in standard WP behaviour).
	 * Captured for rollback (recreates the term, but the new term
	 * may receive a different term_id).
	 */
	public static function handle_delete_term( WP_REST_Request $request ) {
		$denied = self::require_tool_capability( 'wp_delete_term' );
		if ( $denied ) {
			return $denied; }

		$taxonomy = (string) $request->get_param( 'taxonomy' );
		$allowed  = Axtolab_AI_Connector_Policy::assert_allowed_taxonomy( $taxonomy );
		if ( is_wp_error( $allowed ) ) {
			return self::from_wp_error( $allowed );
		}

		if ( ! current_user_can( 'manage_categories' ) ) {
			return Axtolab_AI_Connector_Response::error( 'forbidden_delete_term', 'Current user cannot delete terms.', 403 );
		}

		$term_id = (int) $request->get_param( 'term_id' );
		$term    = get_term( $term_id, $taxonomy );
		if ( ! $term || is_wp_error( $term ) ) {
			return Axtolab_AI_Connector_Response::error( 'not_found', 'Term not found.', 404 );
		}

		$before = Axtolab_AI_Connector_Snapshots::capture_term( $term_id, $taxonomy );

		$result = wp_delete_term( $term_id, $taxonomy );
		if ( is_wp_error( $result ) ) {
			return self::from_wp_error( $result );
		}
		if ( false === $result ) {
			return Axtolab_AI_Connector_Response::error( 'delete_failed', 'Term delete failed.', 500 );
		}

		self::record_change( 'term', $taxonomy . ':' . $term_id, Axtolab_AI_Connector_Changelog::ACTION_DELETE, 'wp_delete_term', $before, null );

		return Axtolab_AI_Connector_Response::success(
			array(
				'taxonomy' => $taxonomy,
				'term_id'  => $term_id,
				'deleted'  => true,
			),
			200,
			self::audit_id()
		);
	}

	public static function handle_assign_terms( WP_REST_Request $request ): WP_REST_Response {
		$denied = self::require_tool_capability( 'wp_assign_terms' );
		if ( $denied ) {
			return $denied; }

		$post = self::get_post_from_request( $request );
		if ( is_wp_error( $post ) ) {
			return self::from_wp_error( $post );
		}

		$can_edit = Axtolab_AI_Connector_Policy::can_edit_content( $post->ID, $post->post_type );
		if ( is_wp_error( $can_edit ) ) {
			return self::from_wp_error( $can_edit );
		}

		$terms = $request->get_param( 'terms' );
		if ( ! is_array( $terms ) ) {
			return Axtolab_AI_Connector_Response::error( 'invalid_terms', 'terms object is required.', 400 );
		}

		foreach ( $terms as $taxonomy => $term_ids ) {
			$allowed = Axtolab_AI_Connector_Policy::assert_allowed_taxonomy( (string) $taxonomy );
			if ( is_wp_error( $allowed ) ) {
				return self::from_wp_error( $allowed );
			}

			$result = wp_set_object_terms( $post->ID, array_map( 'intval', (array) $term_ids ), (string) $taxonomy, false );
			if ( is_wp_error( $result ) ) {
				return self::from_wp_error( $result );
			}
		}

		$updated = get_post( $post->ID );
		return Axtolab_AI_Connector_Response::success( Axtolab_AI_Connector_Policy::to_content_record( $updated ), 200, self::audit_id() );
	}

	public static function handle_upload_media( WP_REST_Request $request ): WP_REST_Response {
		$denied = self::require_tool_capability( 'wp_upload_media_from_url' );
		if ( $denied ) {
			return $denied; }

		/*
		 * NOTE: Base64 media uploads require adequate PHP limits.
		 * Ensure these PHP settings accommodate your max upload size:
		 *   post_max_size        >= 16M (for 10MB images after base64 overhead)
		 *   upload_max_filesize  >= 16M
		 *   memory_limit         >= 128M
		 * Also check web server limits (e.g., nginx client_max_body_size).
		 */
		$can_upload = Axtolab_AI_Connector_Policy::can_upload_media();
		if ( is_wp_error( $can_upload ) ) {
			return self::from_wp_error( $can_upload );
		}

		$filename  = sanitize_file_name( (string) $request->get_param( 'filename' ) );
		$mime_type = (string) $request->get_param( 'mime_type' );
		$base64    = (string) $request->get_param( 'bytes_base64' );
		$alt_text  = (string) $request->get_param( 'alt_text' );

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Decoding the base64-encoded media bytes the MCP client supplied via the upload-media tool contract; not obfuscation.
		$decoded = base64_decode( $base64, true );
		if ( false === $decoded ) {
			return Axtolab_AI_Connector_Response::error( 'invalid_media_base64', 'bytes_base64 must be valid base64.', 400 );
		}

		$policy_ok = Axtolab_AI_Connector_Policy::assert_allowed_media( $mime_type, strlen( $decoded ), $alt_text );
		if ( is_wp_error( $policy_ok ) ) {
			return self::from_wp_error( $policy_ok );
		}

		$upload = wp_upload_bits( $filename, null, $decoded );
		if ( ! empty( $upload['error'] ) ) {
			return Axtolab_AI_Connector_Response::error( 'upload_failed', 'Media upload failed.', 500, $upload['error'] );
		}

		$attachment = array(
			'post_mime_type' => $mime_type,
			'post_title'     => (string) $request->get_param( 'title' ) ?: pathinfo( $filename, PATHINFO_FILENAME ),
			'post_content'   => (string) $request->get_param( 'description' ),
			'post_excerpt'   => (string) $request->get_param( 'caption' ),
			'post_status'    => 'inherit',
		);

		$attachment_id = wp_insert_attachment( $attachment, $upload['file'] );
		if ( is_wp_error( $attachment_id ) ) {
			return self::from_wp_error( $attachment_id );
		}

		require_once ABSPATH . 'wp-admin/includes/image.php';
		$metadata = wp_generate_attachment_metadata( $attachment_id, $upload['file'] );
		wp_update_attachment_metadata( $attachment_id, $metadata );

		if ( '' !== trim( $alt_text ) ) {
			update_post_meta( $attachment_id, '_wp_attachment_image_alt', $alt_text );
		}

		$data = self::to_media_record( $attachment_id );
		return Axtolab_AI_Connector_Response::success( $data, 201, self::audit_id() );
	}

	public static function handle_upload_media_from_url( WP_REST_Request $request ): WP_REST_Response {
		$denied = self::require_tool_capability( 'wp_upload_media_from_url' );
		if ( $denied ) {
			return $denied; }

		$can_upload = Axtolab_AI_Connector_Policy::can_upload_media();
		if ( is_wp_error( $can_upload ) ) {
			return self::from_wp_error( $can_upload );
		}

		$url = esc_url_raw( (string) $request->get_param( 'url' ) );
		if ( empty( $url ) ) {
			return Axtolab_AI_Connector_Response::error( 'missing_url', 'url parameter is required.', 400 );
		}

		$alt_text    = (string) $request->get_param( 'alt_text' );
		$title       = (string) $request->get_param( 'title' );
		$caption     = (string) $request->get_param( 'caption' );
		$description = (string) $request->get_param( 'description' );

		// Download to temp file
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$tmp = download_url( $url, 30 );
		if ( is_wp_error( $tmp ) ) {
			return Axtolab_AI_Connector_Response::error( 'download_failed', 'Could not download from URL.', 400, $tmp->get_error_message() );
		}

		$filename = basename( wp_parse_url( $url, PHP_URL_PATH ) );
		if ( empty( $filename ) ) {
			$filename = 'uploaded-media';
		}

		$file_array = array(
			'name'     => sanitize_file_name( $filename ),
			'tmp_name' => $tmp,
		);

		// Check mime type against policy
		$mime          = wp_check_filetype( $filename );
		$mime_type     = $mime['type'] ?: 'application/octet-stream';
			$filesize  = filesize( $tmp );
			$policy_ok = Axtolab_AI_Connector_Policy::assert_allowed_media( $mime_type, $filesize, $alt_text );
		if ( is_wp_error( $policy_ok ) ) {
			wp_delete_file( $tmp );
			return self::from_wp_error( $policy_ok );
		}

			$attachment_id = media_handle_sideload( $file_array, 0, $title ?: null );
		if ( is_wp_error( $attachment_id ) ) {
			wp_delete_file( $tmp );
			return self::from_wp_error( $attachment_id );
		}

		if ( '' !== trim( $alt_text ) ) {
			update_post_meta( $attachment_id, '_wp_attachment_image_alt', $alt_text );
		}
		if ( '' !== trim( $caption ) ) {
			wp_update_post(
				array(
					'ID'           => $attachment_id,
					'post_excerpt' => $caption,
				)
			);
		}
		if ( '' !== trim( $description ) ) {
			wp_update_post(
				array(
					'ID'           => $attachment_id,
					'post_content' => $description,
				)
			);
		}

		$data = self::to_media_record( $attachment_id );
		return Axtolab_AI_Connector_Response::success( $data, 201, self::audit_id() );
	}

	public static function handle_search_media( WP_REST_Request $request ): WP_REST_Response {
		$args = array(
			'post_type'      => 'attachment',
			'post_status'    => 'inherit',
			'posts_per_page' => max( 1, min( 100, intval( $request->get_param( 'per_page' ) ?: 20 ) ) ),
			'paged'          => max( 1, intval( $request->get_param( 'page' ) ?: 1 ) ),
			's'              => (string) $request->get_param( 'search' ),
		);

		if ( $request->get_param( 'mime_type' ) ) {
			$args['post_mime_type'] = (string) $request->get_param( 'mime_type' );
		}

		$query = new WP_Query( $args );
		$data  = array_map( array( __CLASS__, 'to_media_record' ), wp_list_pluck( $query->posts, 'ID' ) );
		return Axtolab_AI_Connector_Response::success( $data, 200, self::audit_id() );
	}

	public static function handle_get_media( WP_REST_Request $request ): WP_REST_Response {
		$media_id = intval( $request->get_param( 'id' ) );
		$media    = get_post( $media_id );
		if ( ! $media instanceof WP_Post || 'attachment' !== $media->post_type ) {
			return Axtolab_AI_Connector_Response::error( 'media_not_found', 'Attachment not found.', 404 );
		}

		return Axtolab_AI_Connector_Response::success( self::to_media_record( $media_id ), 200, self::audit_id() );
	}

	public static function handle_update_media( WP_REST_Request $request ): WP_REST_Response {
		$denied = self::require_tool_capability( 'wp_update_media' );
		if ( $denied ) {
			return $denied; }

		$media_id = intval( $request->get_param( 'id' ) );
		$media    = get_post( $media_id );
		if ( ! $media instanceof WP_Post || 'attachment' !== $media->post_type ) {
			return Axtolab_AI_Connector_Response::error( 'media_not_found', 'Attachment not found.', 404 );
		}

		if ( ! current_user_can( 'edit_post', $media_id ) ) {
			return Axtolab_AI_Connector_Response::error( 'forbidden_edit_media', 'Current user cannot edit this media.', 403 );
		}

		if ( null !== $request->get_param( 'alt_text' ) ) {
			update_post_meta( $media_id, '_wp_attachment_image_alt', (string) $request->get_param( 'alt_text' ) );
		}

		$postarr = array( 'ID' => $media_id );
		if ( null !== $request->get_param( 'caption' ) ) {
			$postarr['post_excerpt'] = (string) $request->get_param( 'caption' );
		}
		if ( null !== $request->get_param( 'description' ) ) {
			$postarr['post_content'] = (string) $request->get_param( 'description' );
		}
		if ( null !== $request->get_param( 'title' ) ) {
			$postarr['post_title'] = (string) $request->get_param( 'title' );
		}

		if ( count( $postarr ) > 1 ) {
			$result = wp_update_post( wp_slash( $postarr ), true );
			if ( is_wp_error( $result ) ) {
				return self::from_wp_error( $result );
			}
		}

		return Axtolab_AI_Connector_Response::success( self::to_media_record( $media_id ), 200, self::audit_id() );
	}

	public static function handle_set_featured_image( WP_REST_Request $request ): WP_REST_Response {
		$denied = self::require_tool_capability( 'wp_set_featured_image' );
		if ( $denied ) {
			return $denied; }

		$post = self::get_post_from_request( $request );
		if ( is_wp_error( $post ) ) {
			return self::from_wp_error( $post );
		}

		$can_edit = Axtolab_AI_Connector_Policy::can_edit_content( $post->ID, $post->post_type );
		if ( is_wp_error( $can_edit ) ) {
			return self::from_wp_error( $can_edit );
		}

		$media_id = $request->get_param( 'media_id' );
		if ( null === $media_id || '' === $media_id ) {
			delete_post_thumbnail( $post->ID );
		} else {
			$media_id = intval( $media_id );
			if ( $media_id > 0 ) {
				set_post_thumbnail( $post->ID, $media_id );
			} else {
				delete_post_thumbnail( $post->ID );
			}
		}

		$updated = get_post( $post->ID );
		return Axtolab_AI_Connector_Response::success( Axtolab_AI_Connector_Policy::to_content_record( $updated ), 200, self::audit_id() );
	}

	public static function handle_insert_inline_image( WP_REST_Request $request ): WP_REST_Response {
		$denied = self::require_tool_capability( 'wp_insert_inline_image' );
		if ( $denied ) {
			return $denied; }

		$post = self::get_post_from_request( $request );
		if ( is_wp_error( $post ) ) {
			return self::from_wp_error( $post );
		}

		$can_edit = Axtolab_AI_Connector_Policy::can_edit_content( $post->ID, $post->post_type );
		if ( is_wp_error( $can_edit ) ) {
			return self::from_wp_error( $can_edit );
		}

		$result = Axtolab_AI_Connector_Inline_Images::insert( $post, $request->get_params() );
		if ( is_wp_error( $result ) ) {
			return self::from_wp_error( $result );
		}

		$updated_id = wp_update_post(
			array(
				'ID'           => $post->ID,
				'post_content' => $result['content'],
			),
			true
		);

		if ( is_wp_error( $updated_id ) ) {
			return self::from_wp_error( $updated_id );
		}

		$updated = get_post( $post->ID );
		return Axtolab_AI_Connector_Response::success( Axtolab_AI_Connector_Policy::to_content_record( $updated ), 200, self::audit_id() );
	}

	public static function handle_replace_inline_image( WP_REST_Request $request ): WP_REST_Response {
		$denied = self::require_tool_capability( 'wp_replace_inline_image' );
		if ( $denied ) {
			return $denied; }

		$post = self::get_post_from_request( $request );
		if ( is_wp_error( $post ) ) {
			return self::from_wp_error( $post );
		}

		$can_edit = Axtolab_AI_Connector_Policy::can_edit_content( $post->ID, $post->post_type );
		if ( is_wp_error( $can_edit ) ) {
			return self::from_wp_error( $can_edit );
		}

		$result = Axtolab_AI_Connector_Inline_Images::replace( $post, $request->get_params() );
		if ( is_wp_error( $result ) ) {
			return self::from_wp_error( $result );
		}

		$updated_id = wp_update_post(
			array(
				'ID'           => $post->ID,
				'post_content' => $result['content'],
			),
			true
		);

		if ( is_wp_error( $updated_id ) ) {
			return self::from_wp_error( $updated_id );
		}

		$updated = get_post( $post->ID );
		return Axtolab_AI_Connector_Response::success( Axtolab_AI_Connector_Policy::to_content_record( $updated ), 200, self::audit_id() );
	}

	public static function handle_remove_inline_image( WP_REST_Request $request ): WP_REST_Response {
		$denied = self::require_tool_capability( 'wp_remove_inline_image' );
		if ( $denied ) {
			return $denied; }

		$post = self::get_post_from_request( $request );
		if ( is_wp_error( $post ) ) {
			return self::from_wp_error( $post );
		}

		$can_edit = Axtolab_AI_Connector_Policy::can_edit_content( $post->ID, $post->post_type );
		if ( is_wp_error( $can_edit ) ) {
			return self::from_wp_error( $can_edit );
		}

		$result = Axtolab_AI_Connector_Inline_Images::remove( $post, $request->get_params() );
		if ( is_wp_error( $result ) ) {
			return self::from_wp_error( $result );
		}

		$updated_id = wp_update_post(
			array(
				'ID'           => $post->ID,
				'post_content' => $result['content'],
			),
			true
		);

		if ( is_wp_error( $updated_id ) ) {
			return self::from_wp_error( $updated_id );
		}

		$updated = get_post( $post->ID );
		return Axtolab_AI_Connector_Response::success( Axtolab_AI_Connector_Policy::to_content_record( $updated ), 200, self::audit_id() );
	}

	public static function handle_yoast_analysis( WP_REST_Request $request ): WP_REST_Response {
		$post = self::get_post_from_request( $request );
		if ( is_wp_error( $post ) ) {
			return self::from_wp_error( $post );
		}

		// Per-post permission check: route-level `permission_authenticated` only
		// confirms `edit_posts`, which is too broad for arbitrary post IDs.
		// Each request must demonstrate edit permission for THIS specific post.
		if ( ! current_user_can( 'edit_post', $post->ID ) ) {
			return Axtolab_AI_Connector_Response::error(
				'forbidden',
				'You do not have permission to read analysis for this post.',
				403,
				self::audit_id()
			);
		}

		$url      = get_permalink( $post );
		$analysis = array(
			'post_id'     => $post->ID,
			'url'         => $url,
			'readability' => self::safe_rest_call( '/yoast/v1/readability_scores', array( 'url' => $url ) ),
			'seo'         => self::safe_rest_call( '/yoast/v1/seo_scores', array( 'url' => $url ) ),
		);

		return Axtolab_AI_Connector_Response::success( $analysis, 200, self::audit_id() );
	}

	public static function handle_update_yoast_metadata( WP_REST_Request $request ): WP_REST_Response {
		$denied = self::require_tool_capability( 'wp_update_yoast_metadata' );
		if ( $denied ) {
			return $denied; }

		$post = self::get_post_from_request( $request );
		if ( is_wp_error( $post ) ) {
			return self::from_wp_error( $post );
		}

		$meta = $request->get_param( 'yoast_meta' );
		if ( ! is_array( $meta ) ) {
			return Axtolab_AI_Connector_Response::error( 'invalid_yoast_meta', 'yoast_meta object is required.', 400 );
		}

		$allowed = Axtolab_AI_Connector_Policy::assert_allowed_yoast_meta_keys( $meta );
		if ( is_wp_error( $allowed ) ) {
			return self::from_wp_error( $allowed );
		}

		foreach ( $meta as $meta_key => $meta_value ) {
			update_post_meta( $post->ID, sanitize_key( (string) $meta_key ), is_scalar( $meta_value ) ? (string) $meta_value : wp_json_encode( $meta_value ) );
		}

		$updated              = get_post( $post->ID );
		$record               = Axtolab_AI_Connector_Policy::to_content_record( $updated );
		$record['yoast_meta'] = $meta;

		return Axtolab_AI_Connector_Response::success( $record, 200, self::audit_id() );
	}

	public static function handle_yoast_head( WP_REST_Request $request ): WP_REST_Response {
		$post = self::get_post_from_request( $request );
		if ( is_wp_error( $post ) ) {
			return self::from_wp_error( $post );
		}

		$url  = get_permalink( $post );
		$head = self::safe_rest_call( '/yoast/v1/get_head', array( 'url' => $url ) );

		return Axtolab_AI_Connector_Response::success(
			array(
				'post_id' => $post->ID,
				'url'     => $url,
				'head'    => $head,
			),
			200,
			self::audit_id()
		);
	}

	public static function handle_preview_link( WP_REST_Request $request ): WP_REST_Response {
		$post = self::get_post_from_request( $request, true );
		if ( is_wp_error( $post ) ) {
			return self::from_wp_error( $post );
		}

		$can_edit = Axtolab_AI_Connector_Policy::can_edit_content( $post->ID, $post->post_type );
		if ( is_wp_error( $can_edit ) ) {
			return self::from_wp_error( $can_edit );
		}

		return Axtolab_AI_Connector_Response::success( Axtolab_AI_Connector_Preview::build_signed_preview_url( $post->ID ), 200, self::audit_id() );
	}

	// ── Image Provider handlers ──────────────────────────────────────────────

	public static function handle_generate_image( WP_REST_Request $request ): WP_REST_Response {
		$generation_allowed = Axtolab_AI_Connector_Free_Gates::check_image_generation_allowed();
		if ( is_wp_error( $generation_allowed ) ) {
			return self::from_wp_error( $generation_allowed );
		}

		$denied = self::require_tool_capability( 'wp_generate_image' );
		if ( $denied ) {
			return $denied; }

		$prompt       = sanitize_text_field( $request->get_param( 'prompt' ) ?? '' );
		$provider     = $request->get_param( 'provider' );
		$aspect_ratio = $request->get_param( 'aspect_ratio' );
		$quality      = $request->get_param( 'quality' );

		if ( '' === $prompt ) {
			return Axtolab_AI_Connector_Response::error( 'missing_prompt', 'A prompt is required.', 400 );
		}

		$result = Axtolab_AI_Connector_Image_Providers::generate_image(
			$prompt,
			array(
				'provider'     => $provider,
				'aspect_ratio' => $aspect_ratio,
				'quality'      => $quality,
			)
		);

		if ( is_wp_error( $result ) ) {
			return self::from_wp_error( $result );
		}

		return Axtolab_AI_Connector_Response::success( $result, 201, self::audit_id() );
	}

	public static function handle_search_stock_photos( WP_REST_Request $request ): WP_REST_Response {
		$denied = self::require_tool_capability( 'wp_search_stock_photos' );
		if ( $denied ) {
			return $denied; }

		$query       = sanitize_text_field( $request->get_param( 'query' ) ?? '' );
		$provider    = $request->get_param( 'provider' );
		$orientation = $request->get_param( 'orientation' );
		$per_page    = $request->get_param( 'per_page' );

		if ( '' === $query ) {
			return Axtolab_AI_Connector_Response::error( 'missing_query', 'A search query is required.', 400 );
		}

		$result = Axtolab_AI_Connector_Image_Providers::search_stock_photos(
			$query,
			array(
				'provider'    => $provider,
				'orientation' => $orientation,
				'per_page'    => $per_page,
			)
		);

		if ( is_wp_error( $result ) ) {
			return self::from_wp_error( $result );
		}

		return Axtolab_AI_Connector_Response::success( $result, 200, self::audit_id() );
	}

	public static function handle_import_stock_photo( WP_REST_Request $request ): WP_REST_Response {
		$denied = self::require_tool_capability( 'wp_import_stock_photo' );
		if ( $denied ) {
			return $denied; }

		$provider    = sanitize_text_field( $request->get_param( 'provider' ) ?? '' );
		$provider_id = sanitize_text_field( $request->get_param( 'provider_id' ) ?? '' );
		$alt_text    = $request->get_param( 'alt_text' );

		if ( '' === $provider || '' === $provider_id ) {
			return Axtolab_AI_Connector_Response::error( 'missing_params', 'provider and provider_id are required.', 400 );
		}

		$result = Axtolab_AI_Connector_Image_Providers::import_stock_photo(
			$provider,
			$provider_id,
			array(
				'alt_text' => $alt_text,
			)
		);

		if ( is_wp_error( $result ) ) {
			return self::from_wp_error( $result );
		}

		return Axtolab_AI_Connector_Response::success( $result, 201, self::audit_id() );
	}

	public static function handle_list_image_providers( WP_REST_Request $request ): WP_REST_Response {
		$denied = self::require_tool_capability( 'wp_list_image_providers' );
		if ( $denied ) {
			return $denied; }

		$generation = Axtolab_AI_Connector_Image_Providers::get_enabled_providers( 'generation' );
		$stock      = Axtolab_AI_Connector_Image_Providers::get_enabled_providers( 'stock' );

		return Axtolab_AI_Connector_Response::success(
			array(
				'generation_providers' => $generation,
				'stock_providers'      => $stock,
			),
			200,
			self::audit_id()
		);
	}

	public static function handle_confirm_image( WP_REST_Request $request ): WP_REST_Response {
		$denied = self::require_tool_capability( 'wp_confirm_image' );
		if ( $denied ) {
			return $denied; }

		$media_id = intval( $request->get_param( 'id' ) );
		$result   = Axtolab_AI_Connector_Image_Providers::confirm_image( $media_id );

		if ( ! $result ) {
			return Axtolab_AI_Connector_Response::error( 'confirm_failed', 'Image not found or not in pending state.', 404 );
		}

		return Axtolab_AI_Connector_Response::success(
			array(
				'media_id' => $media_id,
				'status'   => 'confirmed',
			),
			200,
			self::audit_id()
		);
	}

	// ── Upload Portal Handlers ─────────────────────────────────────────────

	public static function handle_create_upload_session( WP_REST_Request $request ): WP_REST_Response {
		$denied = self::require_tool_capability( 'wp_create_upload_session' );
		if ( $denied ) {
			return $denied; }

		$ip_binding = $request->get_param( 'ip_binding' );
		$client_ip  = $ip_binding ? self::get_client_ip() : null;

		$result = Axtolab_AI_Connector_Upload_Portal::create_session( get_current_user_id(), $client_ip );

		if ( is_wp_error( $result ) ) {
			return self::from_wp_error( $result );
		}

		return Axtolab_AI_Connector_Response::success( $result, 201, self::audit_id() );
	}

	public static function handle_get_upload_session( WP_REST_Request $request ): WP_REST_Response {
		$denied = self::require_tool_capability( 'wp_get_upload_session' );
		if ( $denied ) {
			return $denied; }

		$session_id = sanitize_text_field( $request->get_param( 'id' ) );

		$result = Axtolab_AI_Connector_Upload_Portal::get_session_uploads( $session_id, get_current_user_id() );

		if ( is_wp_error( $result ) ) {
			return self::from_wp_error( $result );
		}

		return Axtolab_AI_Connector_Response::success( $result, 200, self::audit_id() );
	}

	public static function handle_upload_file( WP_REST_Request $request ): WP_REST_Response {
		$token = sanitize_text_field( $request->get_param( 'token' ) );

		// Validate WordPress nonce.
		$token_hash = hash( 'sha256', $token );
		$nonce      = $request->get_param( '_wpnonce' );
		if ( ! $nonce || ! wp_verify_nonce( $nonce, Axtolab_AI_Connector_Upload_Portal::NONCE_ACTION . '_' . $token_hash ) ) {
			return new WP_REST_Response(
				array(
					'success' => false,
					'error'   => 'Invalid or expired security token.',
				),
				403
			);
		}

		// Get uploaded file.
		$files = $request->get_file_params();
		if ( empty( $files['file'] ) ) {
			return new WP_REST_Response(
				array(
					'success' => false,
					'error'   => 'No file uploaded.',
				),
				400
			);
		}

		$result = Axtolab_AI_Connector_Upload_Portal::handle_upload( $token, $files['file'], self::get_client_ip() );

		if ( is_wp_error( $result ) ) {
			$error_data = $result->get_error_data();
			$status     = is_array( $error_data ) && isset( $error_data['status'] ) ? (int) $error_data['status'] : 400;
			return new WP_REST_Response(
				array(
					'success' => false,
					'error'   => $result->get_error_message(),
				),
				$status
			);
		}

		return new WP_REST_Response(
			array(
				'success' => true,
				'data'    => $result,
			),
			200
		);
	}

	public static function handle_upload_portal( WP_REST_Request $request ) {
		$token = sanitize_text_field( $request->get_param( 'token' ) ?? '' );
		// render_upload_page outputs HTML and calls die().
		Axtolab_AI_Connector_Upload_Portal::render_upload_page( $token );
	}

	/**
	 * Return the capability groups and allowed tools for the current connection.
	 */
	public static function handle_connection_capabilities( WP_REST_Request $request ): WP_REST_Response {
		$connection_id = Axtolab_AI_Connector_Connections::get_current_connection_id();

		if ( ! $connection_id ) {
			// Cannot determine connection (e.g., basic auth without app password UUID).
			// Return default capabilities for backward compatibility.
			return Axtolab_AI_Connector_Response::success(
				array(
					'connection_id'      => null,
					'capabilities'       => Axtolab_AI_Connector_Capabilities::DEFAULT_PRESET,
					'allowed_tools'      => array_values( Axtolab_AI_Connector_Capabilities::tools_for( Axtolab_AI_Connector_Capabilities::DEFAULT_PRESET ) ),
					'allowed_author_ids' => null,
				)
			);
		}

		$capabilities       = Axtolab_AI_Connector_Connections::get_capabilities( $connection_id );
		$allowed_tools      = Axtolab_AI_Connector_Capabilities::tools_for( $capabilities );
		$allowed_author_ids = Axtolab_AI_Connector_Connections::get_allowed_authors( $connection_id );

		return Axtolab_AI_Connector_Response::success(
			array(
				'connection_id'      => $connection_id,
				'capabilities'       => array_values( $capabilities ),
				'allowed_tools'      => array_values( $allowed_tools ),
				'allowed_author_ids' => $allowed_author_ids,
			)
		);
	}

	/**
	 * Send a review-request email notification for a draft post.
	 *
	 * POST /content/{id}/request-review
	 * Body: { note?: string }
	 *
	 * Sends an email to the configured review_notification_email (falling back
	 * to the site admin email) so an editor knows the draft is ready to review.
	 */
	public static function handle_request_review( WP_REST_Request $request ): WP_REST_Response {
		$denied = self::require_tool_capability( 'wp_request_review' );
		if ( $denied ) {
			return $denied; }

		$post_id = (int) $request->get_param( 'id' );
		$note    = sanitize_textarea_field( (string) $request->get_param( 'note' ) );

		$post = get_post( $post_id );

		if ( ! $post ) {
			return Axtolab_AI_Connector_Response::error( 'not_found', 'Post not found.', 404 );
		}

		// Determine recipient email.
		$settings = get_option( 'axtolab_ai_connector_settings', array() );
		$to       = ! empty( $settings['review_notification_email'] )
			? sanitize_email( $settings['review_notification_email'] )
			: get_option( 'admin_email' );

		if ( ! is_email( $to ) ) {
			return Axtolab_AI_Connector_Response::error( 'invalid_email', 'Review notification email is not configured.', 500 );
		}

		$edit_url   = admin_url( 'post.php?post=' . $post_id . '&action=edit' );
		$site_name  = get_bloginfo( 'name' );
		$post_title = get_the_title( $post );

		$subject = sprintf(
			/* translators: 1: site name, 2: post title */
			__( '[%1$s] Draft ready for review: "%2$s"', 'axtolab-ai-connector' ),
			$site_name,
			$post_title
		);

		$body = sprintf(
			/* translators: %s: post title */
			__( 'A draft is ready for your review: "%s"', 'axtolab-ai-connector' ),
			$post_title
		) . "\n\n";

		if ( $note ) {
			$body .= __( 'Note from contributor:', 'axtolab-ai-connector' ) . "\n" . $note . "\n\n";
		}

		$body .= __( 'Edit link:', 'axtolab-ai-connector' ) . "\n" . $edit_url . "\n\n";
		$body .= '— ' . __( 'WP MCP Gateway', 'axtolab-ai-connector' );

		$sent = wp_mail( $to, $subject, $body );

		if ( ! $sent ) {
			return Axtolab_AI_Connector_Response::error( 'email_failed', 'Failed to send review notification email.', 500 );
		}

		return Axtolab_AI_Connector_Response::success(
			array(
				'sent_to'    => $to,
				'post_id'    => $post_id,
				'post_title' => $post_title,
			)
		);
	}

	private static function get_client_ip() {
		// Only trust X-Forwarded-For if the request comes from a known proxy.
		$trusted_proxies = apply_filters( 'axtolab_ai_connector_trusted_proxies', array() );

		if ( ! empty( $trusted_proxies )
			&& ! empty( $_SERVER['REMOTE_ADDR'] )
			&& in_array( $_SERVER['REMOTE_ADDR'], $trusted_proxies, true )
			&& ! empty( $_SERVER['HTTP_X_FORWARDED_FOR'] )
		) {
			$ips = explode( ',', sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) );
			return trim( $ips[0] );
		}

		return ! empty( $_SERVER['REMOTE_ADDR'] )
			? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) )
			: '';
	}

	private static function get_post_from_request( WP_REST_Request $request, bool $allow_trash = false ) {
		$id = intval( $request->get_param( 'id' ) );
		if ( $id <= 0 ) {
			return new WP_Error( 'invalid_id', 'Invalid content id.', array( 'status' => 400 ) );
		}

		$post = get_post( $id );
		if ( ! $post instanceof WP_Post ) {
			return new WP_Error( 'content_not_found', 'Content not found.', array( 'status' => 404 ) );
		}

		$content_type = (string) $request->get_param( 'content_type' );
		if ( '' !== $content_type && $post->post_type !== $content_type ) {
			return new WP_Error( 'content_type_mismatch', 'content_type does not match target post.', array( 'status' => 400 ) );
		}

		$allowed = Axtolab_AI_Connector_Policy::assert_allowed_content_type( $post->post_type );
		if ( is_wp_error( $allowed ) ) {
			return $allowed;
		}

		if ( ! $allow_trash && 'trash' === $post->post_status ) {
			return new WP_Error( 'content_trashed', 'Content is in trash.', array( 'status' => 400 ) );
		}

		return $post;
	}

	private static function apply_terms( int $post_id, array $terms ) {
		if ( empty( $terms ) ) {
			return true;
		}

		foreach ( $terms as $taxonomy => $term_ids ) {
			$allowed = Axtolab_AI_Connector_Policy::assert_allowed_taxonomy( (string) $taxonomy );
			if ( is_wp_error( $allowed ) ) {
				return $allowed;
			}

			$result = wp_set_object_terms( $post_id, array_map( 'intval', (array) $term_ids ), (string) $taxonomy, false );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}

		return true;
	}

	private static function apply_yoast_meta( int $post_id, array $meta ) {
		if ( empty( $meta ) ) {
			return true;
		}

		$allowed = Axtolab_AI_Connector_Policy::assert_allowed_yoast_meta_keys( $meta );
		if ( is_wp_error( $allowed ) ) {
			return $allowed;
		}

		foreach ( $meta as $meta_key => $meta_value ) {
			update_post_meta( $post_id, sanitize_key( (string) $meta_key ), is_scalar( $meta_value ) ? (string) $meta_value : wp_json_encode( $meta_value ) );
		}

		return true;
	}

	private static function to_media_record( int $media_id ): array {
		$post      = get_post( $media_id );
		$metadata  = wp_get_attachment_metadata( $media_id );
		$width     = is_array( $metadata ) && isset( $metadata['width'] ) ? intval( $metadata['width'] ) : 0;
		$height    = is_array( $metadata ) && isset( $metadata['height'] ) ? intval( $metadata['height'] ) : 0;
		$thumbnail = wp_get_attachment_image_src( $media_id, 'medium' );

		return array(
			'id'            => $media_id,
			'title'         => ( $post instanceof WP_Post ) ? $post->post_title : '',
			'source_url'    => wp_get_attachment_url( $media_id ),
			'thumbnail_url' => is_array( $thumbnail ) ? $thumbnail[0] : '',
			'mime_type'     => ( $post instanceof WP_Post ) ? $post->post_mime_type : '',
			'width'         => $width,
			'height'        => $height,
			'alt_text'      => get_post_meta( $media_id, '_wp_attachment_image_alt', true ),
			'caption'       => ( $post instanceof WP_Post ) ? $post->post_excerpt : '',
			'description'   => ( $post instanceof WP_Post ) ? $post->post_content : '',
			'date'          => ( $post instanceof WP_Post ) ? $post->post_date_gmt : '',
		);
	}

	private static function safe_rest_call( string $path, array $params ) {
		if ( ! function_exists( 'rest_do_request' ) ) {
			return null;
		}

		$request = new WP_REST_Request( 'GET', $path );
		foreach ( $params as $key => $value ) {
			$request->set_param( (string) $key, $value );
		}
		$response = rest_do_request( $request );
		if ( $response->is_error() ) {
			return null;
		}

		return $response->get_data();
	}

	private static function from_wp_error( WP_Error $error ): WP_REST_Response {
		$status = intval( $error->get_error_data( $error->get_error_code() )['status'] ?? 400 );
		return Axtolab_AI_Connector_Response::error(
			$error->get_error_code(),
			$error->get_error_message(),
			$status,
			$error->get_error_data()
		);
	}

	private static function audit_id(): string {
		return wp_generate_uuid4();
	}

	/**
	 * Best-effort current MCP session id. Set by the JSON-RPC
	 * transport via the `Mcp-Session-Id` header; absent for direct
	 * Application Password REST calls.
	 *
	 * @return string
	 */
	private static function current_mcp_session_id(): string {
		if ( ! empty( $_SERVER['HTTP_MCP_SESSION_ID'] ) ) {
			return sanitize_text_field( wp_unslash( (string) $_SERVER['HTTP_MCP_SESSION_ID'] ) );
		}
		return '';
	}

	/**
	 * Record a post-mutation in the changelog. Fail-soft: any error
	 * here must not break the underlying tool. Caller passes the
	 * already-captured `before` snapshot (or null for create); we
	 * capture `after` here so the snapshots stay close in time.
	 *
	 * @param int        $post_id
	 * @param string     $action     One of Axtolab_AI_Connector_Changelog::ACTION_*.
	 * @param string     $tool_name
	 * @param array|null $before     Snapshot from Axtolab_AI_Connector_Snapshots::capture_post().
	 * @return void
	 */
	private static function record_post_change( $post_id, $action, $tool_name, $before = null ) {
		if ( ! class_exists( 'Axtolab_AI_Connector_Changelog' ) || ! Axtolab_AI_Connector_Changelog::is_enabled() ) {
			return;
		}
		try {
			$after = Axtolab_AI_Connector_Snapshots::capture_post( (int) $post_id );
			Axtolab_AI_Connector_Changelog::record(
				array(
					'target_type' => 'post',
					'target_id'   => (string) (int) $post_id,
					'action'      => $action,
					'tool_name'   => $tool_name,
					'before'      => $before,
					'after'       => $after,
					'session_id'  => self::current_mcp_session_id(),
				)
			);
		} catch ( Exception $e ) {
			// Capture-side failure must not break the tool. Audit log
			// already records the call itself.
			return;
		}
	}

	/**
	 * Generic changelog recorder for non-post target types. Caller
	 * is responsible for capturing the before snapshot and passing
	 * a freshly-captured after. Fail-soft.
	 *
	 * @param string     $target_type
	 * @param string|int $target_id
	 * @param string     $action
	 * @param string     $tool_name
	 * @param array|null $before
	 * @param array|null $after
	 * @return void
	 */
	private static function record_change( $target_type, $target_id, $action, $tool_name, $before, $after ) {
		if ( ! class_exists( 'Axtolab_AI_Connector_Changelog' ) || ! Axtolab_AI_Connector_Changelog::is_enabled() ) {
			return;
		}
		try {
			Axtolab_AI_Connector_Changelog::record(
				array(
					'target_type' => (string) $target_type,
					'target_id'   => (string) $target_id,
					'action'      => (string) $action,
					'tool_name'   => (string) $tool_name,
					'before'      => $before,
					'after'       => $after,
					'session_id'  => self::current_mcp_session_id(),
				)
			);
		} catch ( Exception $e ) {
			return;
		}
	}

	// ── Post Meta / Custom Fields ───────────────────────────────────────────

	/**
	 * Get all meta for a post, or a single key.
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response|WP_Error
	 */
	public static function handle_get_post_meta( WP_REST_Request $request ) {
		$post = get_post( (int) $request->get_param( 'id' ) );
		if ( ! $post ) {
			return Axtolab_AI_Connector_Response::error( 'not_found', 'Post not found.', 404, self::audit_id() );
		}

		$key = $request->get_param( 'key' );
		if ( $key ) {
			$value = get_post_meta( $post->ID, sanitize_key( $key ), true );
			return Axtolab_AI_Connector_Response::success(
				array(
					'post_id' => $post->ID,
					'key'     => $key,
					'value'   => $value,
				),
				200,
				self::audit_id()
			);
		}

		$all_meta = get_post_meta( $post->ID );
		$filtered = array();
		$skip     = array( '_edit_lock', '_edit_last', '_encloseme', '_pingme' );

		foreach ( $all_meta as $meta_key => $meta_values ) {
			if ( in_array( $meta_key, $skip, true ) ) {
				continue;
			}
			$filtered[ $meta_key ] = count( $meta_values ) === 1 ? $meta_values[0] : $meta_values;
		}

		return Axtolab_AI_Connector_Response::success(
			array(
				'post_id' => $post->ID,
				'meta'    => $filtered,
			),
			200,
			self::audit_id()
		);
	}

	/**
	 * Update post meta (single or multiple keys).
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response|WP_Error
	 */
	public static function handle_update_post_meta( WP_REST_Request $request ) {
		$post = get_post( (int) $request->get_param( 'id' ) );
		if ( ! $post ) {
			return Axtolab_AI_Connector_Response::error( 'not_found', 'Post not found.', 404, self::audit_id() );
		}

		$meta = $request->get_json_params();
		unset( $meta['id'] );

		if ( empty( $meta ) ) {
			return Axtolab_AI_Connector_Response::error( 'missing_meta', 'Provide meta key-value pairs in the request body.', 400, self::audit_id() );
		}

		$updated = array();
		foreach ( $meta as $meta_key => $meta_value ) {
			$safe_key = sanitize_key( (string) $meta_key );
			if ( empty( $safe_key ) ) {
				continue;
			}
			$safe_value = is_scalar( $meta_value ) ? (string) $meta_value : wp_json_encode( $meta_value );

			$before = Axtolab_AI_Connector_Snapshots::capture_post_meta( $post->ID, $safe_key );
			update_post_meta( $post->ID, $safe_key, $safe_value );
			$after = Axtolab_AI_Connector_Snapshots::capture_post_meta( $post->ID, $safe_key );
			self::record_change(
				'post_meta',
				$post->ID . ':' . $safe_key,
				Axtolab_AI_Connector_Changelog::ACTION_UPDATE,
				'wp_update_post_meta',
				$before,
				$after
			);

			$updated[ $safe_key ] = $safe_value;
		}

		self::audit( 'update_post_meta', 'success', implode( ', ', array_keys( $updated ) ), $post->ID );
		return Axtolab_AI_Connector_Response::success(
			array(
				'post_id' => $post->ID,
				'updated' => $updated,
			),
			200,
			self::audit_id()
		);
	}

	/**
	 * Delete a single post meta key.
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response|WP_Error
	 */
	public static function handle_delete_post_meta( WP_REST_Request $request ) {
		$post = get_post( (int) $request->get_param( 'id' ) );
		if ( ! $post ) {
			return Axtolab_AI_Connector_Response::error( 'not_found', 'Post not found.', 404, self::audit_id() );
		}

		$key     = sanitize_key( (string) $request->get_param( 'meta_key' ) );
		$before  = Axtolab_AI_Connector_Snapshots::capture_post_meta( $post->ID, $key );
		$deleted = delete_post_meta( $post->ID, $key );
		$after   = Axtolab_AI_Connector_Snapshots::capture_post_meta( $post->ID, $key );
		self::record_change(
			'post_meta',
			$post->ID . ':' . $key,
			Axtolab_AI_Connector_Changelog::ACTION_DELETE,
			'wp_delete_post_meta',
			$before,
			$after
		);

		return Axtolab_AI_Connector_Response::success(
			array(
				'post_id' => $post->ID,
				'key'     => $key,
				'deleted' => $deleted,
			),
			200,
			self::audit_id()
		);
	}

	// ── Comments ────────────────────────────────────────────────────────────

	/**
	 * Format a comment for API output.
	 *
	 * @param WP_Comment $comment
	 * @return array
	 */
	private static function format_comment( WP_Comment $comment ) {
		return array(
			'id'      => (int) $comment->comment_ID,
			'post_id' => (int) $comment->comment_post_ID,
			'author'  => $comment->comment_author,
			'date'    => $comment->comment_date,
			'content' => $comment->comment_content,
			'status'  => wp_get_comment_status( $comment ),
			'parent'  => (int) $comment->comment_parent,
			'type'    => $comment->comment_type ?: 'comment',
		);
	}

	/**
	 * List comments, optionally filtered by post or status.
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response|WP_Error
	 */
	public static function handle_list_comments( WP_REST_Request $request ) {
		$args = array(
			'number'  => min( (int) ( $request->get_param( 'per_page' ) ?: 20 ), 100 ),
			'offset'  => max( (int) $request->get_param( 'offset' ), 0 ),
			'orderby' => 'comment_date',
			'order'   => 'DESC',
		);

		$post_id = $request->get_param( 'post_id' );
		if ( $post_id ) {
			$args['post_id'] = (int) $post_id;
		}

		$status = $request->get_param( 'status' );
		if ( $status && in_array( $status, array( 'approve', 'hold', 'spam', 'trash', 'all' ), true ) ) {
			$args['status'] = $status;
		} else {
			$args['status'] = 'all';
		}

		$comments = get_comments( $args );
		$items    = array_map( array( __CLASS__, 'format_comment' ), $comments );

		return Axtolab_AI_Connector_Response::success( $items, 200, self::audit_id() );
	}

	/**
	 * Get a single comment.
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response|WP_Error
	 */
	public static function handle_get_comment( WP_REST_Request $request ) {
		$comment = get_comment( (int) $request->get_param( 'id' ) );
		if ( ! $comment ) {
			return Axtolab_AI_Connector_Response::error( 'not_found', 'Comment not found.', 404, self::audit_id() );
		}

		return Axtolab_AI_Connector_Response::success( self::format_comment( $comment ), 200, self::audit_id() );
	}

	/**
	 * Create a comment on a post.
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response|WP_Error
	 */
	public static function handle_create_comment( WP_REST_Request $request ) {
		$post_id = (int) $request->get_param( 'post_id' );
		$post    = get_post( $post_id );
		if ( ! $post ) {
			return Axtolab_AI_Connector_Response::error( 'not_found', 'Post not found.', 404, self::audit_id() );
		}

		$content = (string) $request->get_param( 'content' );
		if ( empty( trim( $content ) ) ) {
			return Axtolab_AI_Connector_Response::error( 'missing_content', 'Comment content is required.', 400, self::audit_id() );
		}

		$user       = wp_get_current_user();
		$comment_id = wp_insert_comment(
			array(
				'comment_post_ID'      => $post_id,
				'comment_content'      => wp_kses_post( $content ),
				'comment_author'       => $request->get_param( 'author' ) ?: $user->display_name,
				'comment_author_email' => $user->user_email,
				'comment_parent'       => (int) $request->get_param( 'parent' ),
				'comment_approved'     => current_user_can( 'moderate_comments' ) ? 1 : 0,
				'user_id'              => $user->ID,
			)
		);

		if ( ! $comment_id ) {
			return Axtolab_AI_Connector_Response::error( 'create_failed', 'Failed to create comment.', 500, self::audit_id() );
		}

		$comment = get_comment( $comment_id );
		self::audit( 'create_comment', 'success', 'Comment on post ' . $post_id, $post_id );
		return Axtolab_AI_Connector_Response::success( self::format_comment( $comment ), 201, self::audit_id() );
	}

	/**
	 * Delete a comment.
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response|WP_Error
	 */
	public static function handle_delete_comment( WP_REST_Request $request ) {
		$comment = get_comment( (int) $request->get_param( 'id' ) );
		if ( ! $comment ) {
			return Axtolab_AI_Connector_Response::error( 'not_found', 'Comment not found.', 404, self::audit_id() );
		}

		$deleted = wp_delete_comment( $comment->comment_ID, true );

		return Axtolab_AI_Connector_Response::success(
			array(
				'id'      => (int) $comment->comment_ID,
				'deleted' => $deleted,
			),
			200,
			self::audit_id()
		);
	}

	/**
	 * Moderate a comment (approve, hold, spam, trash).
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response|WP_Error
	 */
	public static function handle_moderate_comment( WP_REST_Request $request ) {
		if ( ! current_user_can( 'moderate_comments' ) ) {
			return Axtolab_AI_Connector_Response::error( 'forbidden', 'Comment moderation requires moderate_comments capability.', 403, self::audit_id() );
		}

		$comment = get_comment( (int) $request->get_param( 'id' ) );
		if ( ! $comment ) {
			return Axtolab_AI_Connector_Response::error( 'not_found', 'Comment not found.', 404, self::audit_id() );
		}

		$action = (string) $request->get_param( 'action' );
		$valid  = array( 'approve', 'hold', 'spam', 'trash' );
		if ( ! in_array( $action, $valid, true ) ) {
			return Axtolab_AI_Connector_Response::error( 'invalid_action', 'Action must be one of: ' . implode( ', ', $valid ), 400, self::audit_id() );
		}

		$status_map = array(
			'approve' => 'approve',
			'hold'    => 'hold',
			'spam'    => 'spam',
			'trash'   => 'trash',
		);

		wp_set_comment_status( $comment->comment_ID, $status_map[ $action ] );
		$updated = get_comment( $comment->comment_ID );
		self::audit( 'moderate_comment', 'success', $action . ' comment ' . $comment->comment_ID );
		return Axtolab_AI_Connector_Response::success( self::format_comment( $updated ), 200, self::audit_id() );
	}

	// ── Audit Log ───────────────────────────────────────────────────────────

	/**
	 * Query the audit log.
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response
	 */
	public static function handle_get_audit_log( WP_REST_Request $request ) {
		$entries = Axtolab_AI_Connector_Audit_Log::query(
			array(
				'per_page'      => $request->get_param( 'per_page' ) ?: 50,
				'offset'        => $request->get_param( 'offset' ) ?: 0,
				'tool_name'     => $request->get_param( 'tool_name' ) ?: '',
				'connection_id' => $request->get_param( 'connection_id' ) ?: '',
				'outcome'       => $request->get_param( 'outcome' ) ?: '',
			)
		);

		return Axtolab_AI_Connector_Response::success(
			array(
				'entries' => $entries,
				'total'   => Axtolab_AI_Connector_Audit_Log::count(),
			),
			200,
			self::audit_id()
		);
	}

	/**
	 * Log an API action to the audit trail.
	 *
	 * @param string   $tool_name Tool name.
	 * @param string   $outcome   'success' or 'error'.
	 * @param string   $detail    Optional detail.
	 * @param int|null $post_id   Related post ID.
	 * @return void
	 */
	private static function audit( $tool_name, $outcome = 'success', $detail = '', $post_id = null ) {
		Axtolab_AI_Connector_Audit_Log::log( $tool_name, $outcome, $detail, $post_id );
	}

	// ── Extension Bundle ────────────────────────────────────────────────────

	/**
	 * Redirect the legacy extension-download route to the published .mcpb
	 * bundle. The bundle is no longer shipped inside the plugin zip; it
	 * lives as a GitHub Release asset (see AXTOLAB_AI_CONNECTOR_MCPB_URL).
	 *
	 * Kept for backward compatibility with any existing admin links or
	 * bookmarks that still hit /extension/download.
	 *
	 * @param WP_REST_Request $request
	 * @return void Sends a 302 redirect and exits.
	 */
	public static function handle_extension_download( WP_REST_Request $request ) {
		unset( $request );

		/** This filter is documented in axtolab-ai-connector.php. */
		$url = apply_filters( 'axtolab_ai_connector_mcpb_download_url', AXTOLAB_AI_CONNECTOR_MCPB_URL );

		// Not wp_safe_redirect — that restricts to same-host redirects, but
		// the bundle is intentionally hosted on github.com. The URL comes
		// from a plugin-defined constant (or a manage_options-filtered
		// override), not user input, so an external redirect is safe.
		wp_redirect( esc_url_raw( $url ), 302, 'Axtolab AI Connector' ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- See block comment above.
		exit;
	}

	// =========================================================================
	// Permalink structure
	// =========================================================================

	/**
	 * GET /permalink-structure — read current WordPress permalink configuration.
	 *
	 * Authenticated users can read; gating is on writes only.
	 *
	 * @return WP_REST_Response
	 */
	public static function handle_get_permalink_structure() {
		$structure = (string) get_option( 'permalink_structure', '' );

		return Axtolab_AI_Connector_Response::success(
			array(
				'structure'     => $structure,
				'type'          => self::permalink_type_label( $structure ),
				'category_base' => (string) get_option( 'category_base', '' ),
				'tag_base'      => (string) get_option( 'tag_base', '' ),
				'home_url'      => home_url(),
			),
			200,
			self::audit_id()
		);
	}

	/**
	 * POST /permalink-structure — update the WordPress permalink structure.
	 *
	 * Triple-gated:
	 *   1. Admin toggle: `permalink_writes_enabled` in axtolab_ai_connector_settings
	 *      (off by default — admin must explicitly opt in via plugin settings)
	 *   2. WordPress capability: manage_options (enforced by permission_admin)
	 *   3. Input validation: structure must be empty (plain) or contain at
	 *      least one recognized rewrite tag
	 *
	 * Flushes rewrite rules after update so the change takes effect immediately.
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response
	 */
	public static function handle_update_permalink_structure( WP_REST_Request $request ) {
		$settings = get_option( 'axtolab_ai_connector_settings', array() );
		if ( ! is_array( $settings ) || empty( $settings['permalink_writes_enabled'] ) ) {
			return Axtolab_AI_Connector_Response::error(
				'permalink_writes_disabled',
				'Permalink structure writes are disabled. An administrator must enable them in MCP Gateway settings before AI agents can change permalinks.',
				403,
				self::audit_id()
			);
		}

		$structure = (string) $request->get_param( 'structure' );

		if ( '' !== $structure ) {
			$valid_tags    = array( '%year%', '%monthnum%', '%day%', '%hour%', '%minute%', '%second%', '%post_id%', '%postname%', '%category%', '%author%' );
			$has_valid_tag = false;
			foreach ( $valid_tags as $tag ) {
				if ( false !== strpos( $structure, $tag ) ) {
					$has_valid_tag = true;
					break;
				}
			}
			if ( ! $has_valid_tag ) {
				return Axtolab_AI_Connector_Response::error(
					'permalink_invalid_structure',
					'Permalink structure must be empty (plain) or contain at least one recognized rewrite tag (%year%, %postname%, %post_id%, etc.).',
					400,
					self::audit_id()
				);
			}
		}

		update_option( 'permalink_structure', $structure );

		// Flush rewrite rules so the new structure takes effect immediately.
		if ( ! function_exists( 'flush_rewrite_rules' ) ) {
			require_once ABSPATH . 'wp-admin/includes/misc.php';
		}
		flush_rewrite_rules( false );

		return Axtolab_AI_Connector_Response::success(
			array(
				'structure' => $structure,
				'type'      => self::permalink_type_label( $structure ),
				'flushed'   => true,
			),
			200,
			self::audit_id()
		);
	}

	// =========================================================================
	// WordPress Abilities API bridge (WP 6.9+)
	// =========================================================================

	/**
	 * Whether the WP Abilities API is available on this site.
	 *
	 * @return bool
	 */
	private static function abilities_available() {
		return function_exists( 'wp_get_abilities' ) && function_exists( 'wp_get_ability' );
	}

	/**
	 * GET /abilities — list registered abilities with their schemas.
	 *
	 * @return WP_REST_Response
	 */
	public static function handle_list_abilities() {
		if ( ! self::abilities_available() ) {
			return Axtolab_AI_Connector_Response::success(
				array(
					'available' => false,
					'reason'    => 'WordPress Abilities API requires WP 6.9 or later. Upgrade WordPress or install a polyfill plugin.',
					'abilities' => array(),
				),
				200,
				self::audit_id()
			);
		}

		$out       = array();
		$abilities = function_exists( 'wp_get_abilities' ) ? wp_get_abilities() : array();
		foreach ( $abilities as $ability ) {
			// Each ability exposes name(), label(), description(),
			// input_schema(), output_schema() per the WP 6.9 API.
			$entry = array(
				'name'          => method_exists( $ability, 'get_name' ) ? $ability->get_name() : ( property_exists( $ability, 'name' ) ? $ability->name : '' ),
				'label'         => method_exists( $ability, 'get_label' ) ? $ability->get_label() : '',
				'description'   => method_exists( $ability, 'get_description' ) ? $ability->get_description() : '',
				'input_schema'  => method_exists( $ability, 'get_input_schema' ) ? $ability->get_input_schema() : null,
				'output_schema' => method_exists( $ability, 'get_output_schema' ) ? $ability->get_output_schema() : null,
			);
			$out[] = $entry;
		}

		return Axtolab_AI_Connector_Response::success(
			array(
				'available' => true,
				'count'     => count( $out ),
				'abilities' => $out,
			),
			200,
			self::audit_id()
		);
	}

	/**
	 * POST /abilities/{name}/execute — invoke a registered ability.
	 *
	 * Each ability runs through WP core's own permission_callback in
	 * `$ability->execute()`, so we don't add a separate cap check here —
	 * the ability itself decides who can call it.
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response
	 */
	public static function handle_invoke_ability( WP_REST_Request $request ) {
		if ( ! self::abilities_available() ) {
			return Axtolab_AI_Connector_Response::error( 'abilities_unavailable', 'WP Abilities API not available on this site (requires WP 6.9+).', 501, self::audit_id() );
		}

		$name = (string) $request->get_param( 'name' );
		if ( '' === $name ) {
			return Axtolab_AI_Connector_Response::error( 'invalid_name', 'Ability name required.', 400, self::audit_id() );
		}

		$ability = function_exists( 'wp_get_ability' ) ? wp_get_ability( $name ) : null;
		if ( ! $ability ) {
			return Axtolab_AI_Connector_Response::error( 'not_found', sprintf( 'Ability "%s" is not registered.', $name ), 404, self::audit_id() );
		}

		$args   = $request->get_json_params();
		$args   = is_array( $args ) ? $args : array();
		$result = method_exists( $ability, 'execute' ) ? $ability->execute( $args ) : null;

		if ( is_wp_error( $result ) ) {
			return self::from_wp_error( $result );
		}

		self::audit( 'invoke_ability', 'success', $name );
		return Axtolab_AI_Connector_Response::success(
			array(
				'name'   => $name,
				'result' => $result,
			),
			200,
			self::audit_id()
		);
	}

	// =========================================================================
	// Theme Appearance (active theme, theme mods, Custom CSS)
	// =========================================================================

	public static function handle_get_active_theme() {
		$theme  = wp_get_theme();
		$parent = $theme->parent();
		return Axtolab_AI_Connector_Response::success(
			array(
				'name'        => (string) $theme->get( 'Name' ),
				'stylesheet'  => $theme->get_stylesheet(),
				'template'    => $theme->get_template(),
				'version'     => (string) $theme->get( 'Version' ),
				'author'      => wp_strip_all_tags( (string) $theme->get( 'Author' ) ),
				'theme_uri'   => (string) $theme->get( 'ThemeURI' ),
				'description' => wp_strip_all_tags( (string) $theme->get( 'Description' ) ),
				'parent'      => $parent ? (string) $parent->get_stylesheet() : null,
				'parent_name' => $parent ? (string) $parent->get( 'Name' ) : null,
			),
			200,
			self::audit_id()
		);
	}

	public static function handle_get_theme_mods() {
		$mods = (array) get_theme_mods();
		// Theme mods rarely contain secrets, but redact any sensitive keys
		// just in case (e.g. some themes store API keys here).
		$out = array();
		foreach ( $mods as $key => $value ) {
			if ( self::is_sensitive_option_key( $key ) ) {
				$out[ $key ] = '[REDACTED]';
			} else {
				$out[ $key ] = self::redact_sensitive_in_value( $value );
			}
		}
		return Axtolab_AI_Connector_Response::success(
			array(
				'theme' => get_stylesheet(),
				'count' => count( $out ),
				'mods'  => $out,
			),
			200,
			self::audit_id()
		);
	}

	public static function handle_get_custom_css() {
		$css = wp_get_custom_css();
		return Axtolab_AI_Connector_Response::success(
			array(
				'theme' => get_stylesheet(),
				'css'   => (string) $css,
				'bytes' => strlen( (string) $css ),
			),
			200,
			self::audit_id()
		);
	}

	// =========================================================================
	// Navigation Menus (list / CRUD / reorder)
	// =========================================================================

	/**
	 * Require edit_theme_options for menu writes; return a structured 403
	 * if the connection's user doesn't have the capability.
	 *
	 * @return true|WP_REST_Response
	 */
	private static function require_menu_write_cap() {
		if ( current_user_can( 'edit_theme_options' ) ) {
			return true;
		}
		return Axtolab_AI_Connector_Response::error(
			'forbidden_menu_write',
			'Menu modifications require the edit_theme_options capability. The connected service account does not have it. Grant it (or assign the connection to an administrator user) before retrying.',
			403,
			self::audit_id()
		);
	}

	/**
	 * Format a nav menu item post into a flat dict suitable for AI consumption.
	 *
	 * @param object $item WP_Post or already-formatted nav menu item.
	 * @return array
	 */
	private static function format_menu_item( $item ) {
		// `wp_get_nav_menu_items` returns objects with extra fields hydrated:
		// title, url, type, object, object_id, target, classes, xfn, attr_title,
		// description, menu_order, menu_item_parent.
		return array(
			'id'          => (int) $item->ID,
			'title'       => isset( $item->title ) ? (string) $item->title : '',
			'url'         => isset( $item->url ) ? (string) $item->url : '',
			'type'        => isset( $item->type ) ? (string) $item->type : '',
			'object'      => isset( $item->object ) ? (string) $item->object : '',
			'object_id'   => isset( $item->object_id ) ? (int) $item->object_id : 0,
			'parent'      => isset( $item->menu_item_parent ) ? (int) $item->menu_item_parent : 0,
			'menu_order'  => isset( $item->menu_order ) ? (int) $item->menu_order : 0,
			'target'      => isset( $item->target ) ? (string) $item->target : '',
			'classes'     => isset( $item->classes ) ? (array) $item->classes : array(),
			'attr_title'  => isset( $item->attr_title ) ? (string) $item->attr_title : '',
			'description' => isset( $item->description ) ? (string) $item->description : '',
			'xfn'         => isset( $item->xfn ) ? (string) $item->xfn : '',
		);
	}

	public static function handle_list_menus() {
		$menus = wp_get_nav_menus();
		$out   = array();
		foreach ( $menus as $menu ) {
			$out[] = array(
				'id'    => (int) $menu->term_id,
				'name'  => (string) $menu->name,
				'slug'  => (string) $menu->slug,
				'count' => (int) $menu->count,
			);
		}
		return Axtolab_AI_Connector_Response::success(
			array(
				'count' => count( $out ),
				'menus' => $out,
			),
			200,
			self::audit_id()
		);
	}

	public static function handle_get_menu( WP_REST_Request $request ) {
		$id_or_slug = (string) $request->get_param( 'id_or_slug' );
		$menu       = wp_get_nav_menu_object( is_numeric( $id_or_slug ) ? (int) $id_or_slug : $id_or_slug );
		if ( ! $menu ) {
			return Axtolab_AI_Connector_Response::error( 'not_found', 'Menu not found.', 404, self::audit_id() );
		}
		$items = wp_get_nav_menu_items( $menu->term_id );
		$items = is_array( $items ) ? array_map( array( __CLASS__, 'format_menu_item' ), $items ) : array();

		return Axtolab_AI_Connector_Response::success(
			array(
				'id'    => (int) $menu->term_id,
				'name'  => (string) $menu->name,
				'slug'  => (string) $menu->slug,
				'count' => count( $items ),
				'items' => $items,
			),
			200,
			self::audit_id()
		);
	}

	public static function handle_create_menu_item( WP_REST_Request $request ) {
		$cap = self::require_menu_write_cap();
		if ( true !== $cap ) {
			return $cap;
		}

		$menu_id = (int) $request->get_param( 'id' );
		$menu    = wp_get_nav_menu_object( $menu_id );
		if ( ! $menu ) {
			return Axtolab_AI_Connector_Response::error( 'not_found', 'Menu not found.', 404, self::audit_id() );
		}

		$body = $request->get_json_params();
		if ( ! is_array( $body ) ) {
			$body = array();
		}

		$args = array(
			'menu-item-title'       => isset( $body['title'] ) ? sanitize_text_field( (string) $body['title'] ) : '',
			'menu-item-url'         => isset( $body['url'] ) ? esc_url_raw( (string) $body['url'] ) : '',
			'menu-item-type'        => isset( $body['type'] ) ? sanitize_key( (string) $body['type'] ) : 'custom',
			'menu-item-object'      => isset( $body['object'] ) ? sanitize_key( (string) $body['object'] ) : '',
			'menu-item-object-id'   => isset( $body['object_id'] ) ? (int) $body['object_id'] : 0,
			'menu-item-parent-id'   => isset( $body['parent'] ) ? (int) $body['parent'] : 0,
			'menu-item-status'      => 'publish',
			'menu-item-target'      => isset( $body['target'] ) ? sanitize_key( (string) $body['target'] ) : '',
			'menu-item-attr-title'  => isset( $body['attr_title'] ) ? sanitize_text_field( (string) $body['attr_title'] ) : '',
			'menu-item-classes'     => isset( $body['classes'] ) ? ( is_array( $body['classes'] ) ? implode( ' ', array_map( 'sanitize_html_class', $body['classes'] ) ) : sanitize_text_field( (string) $body['classes'] ) ) : '',
			'menu-item-description' => isset( $body['description'] ) ? sanitize_text_field( (string) $body['description'] ) : '',
			'menu-item-xfn'         => isset( $body['xfn'] ) ? sanitize_text_field( (string) $body['xfn'] ) : '',
		);

		$before_menu = Axtolab_AI_Connector_Snapshots::capture_menu( $menu_id );

		$item_id = wp_update_nav_menu_item( $menu_id, 0, $args );
		if ( is_wp_error( $item_id ) ) {
			return self::from_wp_error( $item_id );
		}

		$after_menu = Axtolab_AI_Connector_Snapshots::capture_menu( $menu_id );
		self::record_change(
			'menu',
			(string) $menu_id,
			Axtolab_AI_Connector_Changelog::ACTION_UPDATE,
			'wp_create_menu_item',
			$before_menu,
			$after_menu
		);

		self::audit( 'create_menu_item', 'success', '', (int) $item_id );
		$item = get_post( $item_id );
		$nav  = wp_setup_nav_menu_item( $item );
		return Axtolab_AI_Connector_Response::success( self::format_menu_item( $nav ), 201, self::audit_id() );
	}

	public static function handle_update_menu_item( WP_REST_Request $request ) {
		$cap = self::require_menu_write_cap();
		if ( true !== $cap ) {
			return $cap;
		}

		$item_id = (int) $request->get_param( 'item_id' );
		$item    = get_post( $item_id );
		if ( ! $item || 'nav_menu_item' !== $item->post_type ) {
			return Axtolab_AI_Connector_Response::error( 'not_found', 'Menu item not found.', 404, self::audit_id() );
		}
		$menu_terms = wp_get_object_terms( $item_id, 'nav_menu' );
		$menu_id    = ( ! is_wp_error( $menu_terms ) && ! empty( $menu_terms ) ) ? (int) $menu_terms[0]->term_id : 0;

		$body = $request->get_json_params();
		if ( ! is_array( $body ) ) {
			$body = array();
		}

		// Read current values via wp_setup_nav_menu_item, then overlay updates.
		$current = wp_setup_nav_menu_item( $item );

		$args = array(
			'menu-item-title'       => isset( $body['title'] ) ? sanitize_text_field( (string) $body['title'] ) : (string) $current->title,
			'menu-item-url'         => isset( $body['url'] ) ? esc_url_raw( (string) $body['url'] ) : (string) $current->url,
			'menu-item-type'        => isset( $body['type'] ) ? sanitize_key( (string) $body['type'] ) : (string) $current->type,
			'menu-item-object'      => isset( $body['object'] ) ? sanitize_key( (string) $body['object'] ) : (string) $current->object,
			'menu-item-object-id'   => isset( $body['object_id'] ) ? (int) $body['object_id'] : (int) $current->object_id,
			'menu-item-parent-id'   => isset( $body['parent'] ) ? (int) $body['parent'] : (int) $current->menu_item_parent,
			'menu-item-position'    => isset( $body['menu_order'] ) ? (int) $body['menu_order'] : (int) $current->menu_order,
			'menu-item-status'      => 'publish',
			'menu-item-target'      => isset( $body['target'] ) ? sanitize_key( (string) $body['target'] ) : (string) $current->target,
			'menu-item-attr-title'  => isset( $body['attr_title'] ) ? sanitize_text_field( (string) $body['attr_title'] ) : (string) $current->attr_title,
			'menu-item-classes'     => isset( $body['classes'] ) ? ( is_array( $body['classes'] ) ? implode( ' ', array_map( 'sanitize_html_class', $body['classes'] ) ) : sanitize_text_field( (string) $body['classes'] ) ) : implode( ' ', (array) $current->classes ),
			'menu-item-description' => isset( $body['description'] ) ? sanitize_text_field( (string) $body['description'] ) : (string) $current->description,
			'menu-item-xfn'         => isset( $body['xfn'] ) ? sanitize_text_field( (string) $body['xfn'] ) : (string) $current->xfn,
		);

		$before_menu = $menu_id ? Axtolab_AI_Connector_Snapshots::capture_menu( $menu_id ) : null;

		$result = wp_update_nav_menu_item( $menu_id, $item_id, $args );
		if ( is_wp_error( $result ) ) {
			return self::from_wp_error( $result );
		}

		if ( $menu_id ) {
			$after_menu = Axtolab_AI_Connector_Snapshots::capture_menu( $menu_id );
			self::record_change(
				'menu',
				(string) $menu_id,
				Axtolab_AI_Connector_Changelog::ACTION_UPDATE,
				'wp_update_menu_item',
				$before_menu,
				$after_menu
			);
		}

		self::audit( 'update_menu_item', 'success', '', $item_id );
		$updated = wp_setup_nav_menu_item( get_post( $item_id ) );
		return Axtolab_AI_Connector_Response::success( self::format_menu_item( $updated ), 200, self::audit_id() );
	}

	public static function handle_delete_menu_item( WP_REST_Request $request ) {
		$cap = self::require_menu_write_cap();
		if ( true !== $cap ) {
			return $cap;
		}

		$item_id = (int) $request->get_param( 'item_id' );
		$item    = get_post( $item_id );
		if ( ! $item || 'nav_menu_item' !== $item->post_type ) {
			return Axtolab_AI_Connector_Response::error( 'not_found', 'Menu item not found.', 404, self::audit_id() );
		}

		$menu_terms  = wp_get_object_terms( $item_id, 'nav_menu' );
		$menu_id     = ( ! is_wp_error( $menu_terms ) && ! empty( $menu_terms ) ) ? (int) $menu_terms[0]->term_id : 0;
		$before_menu = $menu_id ? Axtolab_AI_Connector_Snapshots::capture_menu( $menu_id ) : null;

		wp_delete_post( $item_id, true );

		if ( $menu_id ) {
			$after_menu = Axtolab_AI_Connector_Snapshots::capture_menu( $menu_id );
			self::record_change(
				'menu',
				(string) $menu_id,
				Axtolab_AI_Connector_Changelog::ACTION_DELETE,
				'wp_delete_menu_item',
				$before_menu,
				$after_menu
			);
		}

		self::audit( 'delete_menu_item', 'success', '', $item_id );
		return Axtolab_AI_Connector_Response::success(
			array(
				'id'      => $item_id,
				'deleted' => true,
			),
			200,
			self::audit_id()
		);
	}

	public static function handle_reorder_menu_items( WP_REST_Request $request ) {
		$cap = self::require_menu_write_cap();
		if ( true !== $cap ) {
			return $cap;
		}

		$menu_id = (int) $request->get_param( 'id' );
		$menu    = wp_get_nav_menu_object( $menu_id );
		if ( ! $menu ) {
			return Axtolab_AI_Connector_Response::error( 'not_found', 'Menu not found.', 404, self::audit_id() );
		}

		$body  = $request->get_json_params();
		$order = ( is_array( $body ) && isset( $body['order'] ) && is_array( $body['order'] ) ) ? $body['order'] : array();
		if ( empty( $order ) ) {
			return Axtolab_AI_Connector_Response::error(
				'missing_order',
				'Provide an `order` array of { item_id, menu_order, parent? } objects.',
				400,
				self::audit_id()
			);
		}

		$before_menu = Axtolab_AI_Connector_Snapshots::capture_menu( $menu_id );

		$updated_count = 0;
		foreach ( $order as $entry ) {
			if ( ! is_array( $entry ) || empty( $entry['item_id'] ) ) {
				continue;
			}
			$item_id = (int) $entry['item_id'];
			$item    = get_post( $item_id );
			if ( ! $item || 'nav_menu_item' !== $item->post_type ) {
				continue;
			}

			$update_args = array( 'ID' => $item_id );
			if ( isset( $entry['menu_order'] ) ) {
				$update_args['menu_order'] = (int) $entry['menu_order'];
			}
			wp_update_post( $update_args );

			if ( isset( $entry['parent'] ) ) {
				update_post_meta( $item_id, '_menu_item_menu_item_parent', (string) (int) $entry['parent'] );
			}
			++$updated_count;
		}

		$after_menu = Axtolab_AI_Connector_Snapshots::capture_menu( $menu_id );
		self::record_change(
			'menu',
			(string) $menu_id,
			Axtolab_AI_Connector_Changelog::ACTION_UPDATE,
			'wp_reorder_menu_items',
			$before_menu,
			$after_menu
		);

		self::audit( 'reorder_menu_items', 'success', '', $menu_id );
		// Re-read the menu items so the response reflects new ordering.
		$items = wp_get_nav_menu_items( $menu_id );
		$items = is_array( $items ) ? array_map( array( __CLASS__, 'format_menu_item' ), $items ) : array();
		return Axtolab_AI_Connector_Response::success(
			array(
				'menu_id'       => $menu_id,
				'updated_count' => $updated_count,
				'items'         => $items,
			),
			200,
			self::audit_id()
		);
	}

	// =========================================================================
	// Generic SEO meta (auto-detects Yoast / Rank Math / AIOSEO)
	// =========================================================================

	/**
	 * GET /seo/{id} — read SEO meta in provider-neutral form.
	 *
	 * Returns: { post_id, plugin, fields: { title, description,
	 * focus_keyphrase, noindex, nofollow, og_title, og_description, og_image,
	 * twitter_title, twitter_description, twitter_image } }
	 *
	 * `plugin` field reports which SEO plugin (`yoast` / `rank_math` /
	 * `aioseo` / null) was detected. Unsupported plugins return all-empty
	 * fields with a null plugin so clients can react gracefully.
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response
	 */
	public static function handle_get_seo_meta( WP_REST_Request $request ) {
		$post = self::get_post_from_request( $request );
		if ( is_wp_error( $post ) ) {
			return self::from_wp_error( $post );
		}

		$result = Axtolab_AI_Connector_SEO_Adapter::get_meta( $post->ID );
		return Axtolab_AI_Connector_Response::success(
			array(
				'post_id' => $post->ID,
				'plugin'  => $result['plugin'],
				'fields'  => $result['fields'],
			),
			200,
			self::audit_id()
		);
	}

	/**
	 * POST /seo/{id} — write SEO meta in provider-neutral form. Body should
	 * be a `fields` object with any subset of the standardized field names.
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response
	 */
	public static function handle_update_seo_meta( WP_REST_Request $request ) {
		$post = self::get_post_from_request( $request );
		if ( is_wp_error( $post ) ) {
			return self::from_wp_error( $post );
		}

		$body   = $request->get_json_params();
		$fields = ( is_array( $body ) && isset( $body['fields'] ) && is_array( $body['fields'] ) )
			? $body['fields']
			: ( is_array( $body ) ? $body : array() );
		unset( $fields['id'] );

		if ( empty( $fields ) ) {
			return Axtolab_AI_Connector_Response::error( 'missing_fields', 'Provide SEO fields in the request body (either as `{fields: {...}}` or as a flat object).', 400, self::audit_id() );
		}

		$result = Axtolab_AI_Connector_SEO_Adapter::update_meta( $post->ID, $fields );
		self::audit( 'update_seo_meta', 'success', implode( ',', array_keys( $fields ) ), $post->ID );

		return Axtolab_AI_Connector_Response::success(
			array(
				'post_id' => $post->ID,
				'plugin'  => $result['plugin'],
				'written' => $result['written'],
			),
			200,
			self::audit_id()
		);
	}

	// =========================================================================
	// Options API (allowlisted read/write of WordPress options)
	// =========================================================================

	/**
	 * Hard denylist of WP option keys that AI agents must NEVER write to,
	 * regardless of admin toggle or allowlist filter. Any option matching
	 * an exact key OR a substring pattern in this list rejects with
	 * `option_protected`.
	 *
	 * @return array{exact:array<string>, patterns:array<string>}
	 */
	private static function option_hard_denylist() {
		return array(
			'exact'    => array(
				'siteurl',
				'home',                   // would break the site URL routing
				'admin_email',
				'new_admin_email',    // ownership change
				'auth_key',
				'auth_salt',
				'logged_in_key',
				'logged_in_salt',
				'nonce_key',
				'nonce_salt',
				'secure_auth_key',
				'secure_auth_salt',
				'active_plugins',
				'template',
				'stylesheet', // structural integrity
				'axtolab_ai_connector_settings',              // can't let AI flip its own gates
			),
			'patterns' => array(
				'_secret',
				'_token',
				'_password',
				'license_key',
				'license_data',
				'_license_',
				'_api_key',
				'api_secret',
				'_credentials',
				'_oauth_',
				'_pwd_',
				'_passphrase',
			),
		);
	}

	/**
	 * Whether a key matches the sensitive-data redaction pattern. Used on
	 * READS to redact stored secrets before they leave the server (api_key
	 * etc. — admin can still see them in WP admin; AI agents cannot).
	 *
	 * @param string $key
	 * @return bool
	 */
	private static function is_sensitive_option_key( $key ) {
		$patterns = array( 'api_key', 'secret', 'token', 'password', 'license', 'salt', '_pwd', 'passphrase', 'credentials' );
		$lower    = strtolower( (string) $key );
		foreach ( $patterns as $needle ) {
			if ( false !== strpos( $lower, $needle ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Recursively redact sensitive keys in a value. Scalars matching are
	 * not redacted (we redact based on KEY, not value). Arrays/objects:
	 * recursively walk and replace matching keys with [REDACTED].
	 *
	 * @param mixed $value
	 * @return mixed
	 */
	private static function redact_sensitive_in_value( $value ) {
		if ( is_array( $value ) ) {
			$out = array();
			foreach ( $value as $k => $v ) {
				if ( is_string( $k ) && self::is_sensitive_option_key( $k ) ) {
					$out[ $k ] = '[REDACTED]';
				} else {
					$out[ $k ] = self::redact_sensitive_in_value( $v );
				}
			}
			return $out;
		}
		return $value;
	}

	/**
	 * GET /options/{key} — read a single WP option with sensitive-key redaction.
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response
	 */
	public static function handle_get_option( WP_REST_Request $request ) {
		$key = sanitize_key( (string) $request->get_param( 'key' ) );
		if ( '' === $key ) {
			return Axtolab_AI_Connector_Response::error( 'invalid_key', 'Option key required.', 400, self::audit_id() );
		}

		$value = get_option( $key, null );
		if ( null === $value ) {
			return Axtolab_AI_Connector_Response::error( 'not_found', 'Option not found.', 404, self::audit_id() );
		}

		// Redact: if the key itself is sensitive OR if a nested array key matches.
		if ( self::is_sensitive_option_key( $key ) ) {
			$value = '[REDACTED]';
		} else {
			$value = self::redact_sensitive_in_value( $value );
		}

		return Axtolab_AI_Connector_Response::success(
			array(
				'key'   => $key,
				'value' => $value,
			),
			200,
			self::audit_id()
		);
	}

	/**
	 * POST /options/{key} — update a WP option with three-gate security:
	 *
	 *   1. axtolab_ai_connector_settings['options_writes_enabled'] must be truthy
	 *   2. manage_options capability (via permission_admin upstream)
	 *   3. Key must be in the runtime allowlist (filterable via
	 *      `axtolab_ai_connector_writable_options`) AND not in the hard denylist
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response
	 */
	public static function handle_update_option( WP_REST_Request $request ) {
		$settings = get_option( 'axtolab_ai_connector_settings', array() );
		if ( ! is_array( $settings ) || empty( $settings['options_writes_enabled'] ) ) {
			return Axtolab_AI_Connector_Response::error(
				'options_writes_disabled',
				'WordPress option writes are disabled. An administrator must enable them in MCP Gateway settings before AI agents can write options.',
				403,
				self::audit_id()
			);
		}

		$key = sanitize_key( (string) $request->get_param( 'key' ) );
		if ( '' === $key ) {
			return Axtolab_AI_Connector_Response::error( 'invalid_key', 'Option key required.', 400, self::audit_id() );
		}

		// Hard denylist — overrides everything.
		$denylist = self::option_hard_denylist();
		if ( in_array( $key, $denylist['exact'], true ) ) {
			return Axtolab_AI_Connector_Response::error(
				'option_protected',
				sprintf( 'Option `%s` is protected from AI writes (hard denylist; cannot be unblocked).', $key ),
				403,
				self::audit_id()
			);
		}
		$key_lower = strtolower( $key );
		foreach ( $denylist['patterns'] as $pattern ) {
			if ( false !== strpos( $key_lower, $pattern ) ) {
				return Axtolab_AI_Connector_Response::error(
					'option_protected',
					sprintf( 'Option `%s` matches a protected pattern (`%s`) and cannot be written by AI agents.', $key, $pattern ),
					403,
					self::audit_id()
				);
			}
		}

		// Allowlist — built-in defaults, extensible via filter.
		$default_allowlist = array( 'blogname', 'blogdescription', 'posts_per_page', 'date_format', 'time_format', 'start_of_week' );
		$allowlist         = apply_filters( 'axtolab_ai_connector_writable_options', $default_allowlist );
		if ( ! is_array( $allowlist ) ) {
			$allowlist = $default_allowlist;
		}
		if ( ! in_array( $key, $allowlist, true ) ) {
			return Axtolab_AI_Connector_Response::error(
				'option_not_in_allowlist',
				sprintf(
					'Option `%s` is not in the writable allowlist. Plugin authors can opt their settings in via the `axtolab_ai_connector_writable_options` filter. Default allowlist: %s.',
					$key,
					implode( ', ', $default_allowlist )
				),
				403,
				self::audit_id()
			);
		}

		$body  = $request->get_json_params();
		$value = is_array( $body ) && array_key_exists( 'value', $body ) ? $body['value'] : null;
		if ( null === $value ) {
			return Axtolab_AI_Connector_Response::error( 'missing_value', 'Provide `value` in the JSON body.', 400, self::audit_id() );
		}

		$before = Axtolab_AI_Connector_Snapshots::capture_option( $key );
		update_option( $key, $value );
		$after = Axtolab_AI_Connector_Snapshots::capture_option( $key );
		self::record_change( 'option', $key, Axtolab_AI_Connector_Changelog::ACTION_UPDATE, 'wp_update_option', $before, $after );

		self::audit( 'update_option', 'success', $key );

		return Axtolab_AI_Connector_Response::success(
			array(
				'key'   => $key,
				'value' => $value,
			),
			200,
			self::audit_id()
		);
	}

	// =========================================================================
	// Term Meta (taxonomy term meta CRUD)
	// =========================================================================

	/**
	 * GET /terms/{term_id}/meta — read taxonomy term meta.
	 *
	 * Optional query param `key` returns a single meta key's value; without
	 * it returns all meta as an associative map (single-value entries
	 * collapsed to scalars).
	 *
	 * Most common use case: read/write Yoast SEO, Rank Math, or AIOSEO
	 * term-level metadata (focus keyphrase, SEO title, meta description for
	 * categories/tags/custom-taxonomy-terms).
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response
	 */
	public static function handle_get_term_meta( WP_REST_Request $request ) {
		$term_id = (int) $request->get_param( 'term_id' );
		$term    = get_term( $term_id );
		if ( ! $term || is_wp_error( $term ) ) {
			return Axtolab_AI_Connector_Response::error( 'not_found', 'Term not found.', 404, self::audit_id() );
		}

		$key = $request->get_param( 'key' );
		if ( $key ) {
			$value = get_term_meta( $term_id, sanitize_key( (string) $key ), true );
			return Axtolab_AI_Connector_Response::success(
				array(
					'term_id' => $term_id,
					'key'     => (string) $key,
					'value'   => $value,
				),
				200,
				self::audit_id()
			);
		}

		$all_meta = get_term_meta( $term_id );
		$filtered = array();
		foreach ( $all_meta as $meta_key => $meta_values ) {
			$filtered[ $meta_key ] = count( $meta_values ) === 1 ? $meta_values[0] : $meta_values;
		}

		return Axtolab_AI_Connector_Response::success(
			array(
				'term_id'  => $term_id,
				'taxonomy' => $term->taxonomy,
				'meta'     => $filtered,
			),
			200,
			self::audit_id()
		);
	}

	/**
	 * POST /terms/{term_id}/meta — update term meta (single or multiple keys).
	 *
	 * Body: object of meta_key => meta_value pairs. Scalar values stored
	 * as-is; non-scalar (arrays/objects) JSON-encoded.
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response
	 */
	public static function handle_update_term_meta( WP_REST_Request $request ) {
		$term_id = (int) $request->get_param( 'term_id' );
		$term    = get_term( $term_id );
		if ( ! $term || is_wp_error( $term ) ) {
			return Axtolab_AI_Connector_Response::error( 'not_found', 'Term not found.', 404, self::audit_id() );
		}

		$meta = $request->get_json_params();
		unset( $meta['term_id'] );

		if ( empty( $meta ) ) {
			return Axtolab_AI_Connector_Response::error( 'missing_meta', 'Provide meta key-value pairs in the request body.', 400, self::audit_id() );
		}

		$updated = array();
		foreach ( $meta as $meta_key => $meta_value ) {
			$safe_key = sanitize_key( (string) $meta_key );
			if ( empty( $safe_key ) ) {
				continue;
			}
			$safe_value = is_scalar( $meta_value ) ? (string) $meta_value : wp_json_encode( $meta_value );

			$before = Axtolab_AI_Connector_Snapshots::capture_term_meta( $term_id, $safe_key );
			update_term_meta( $term_id, $safe_key, $safe_value );
			$after = Axtolab_AI_Connector_Snapshots::capture_term_meta( $term_id, $safe_key );
			self::record_change(
				'term_meta',
				$term_id . ':' . $safe_key,
				Axtolab_AI_Connector_Changelog::ACTION_UPDATE,
				'wp_update_term_meta',
				$before,
				$after
			);

			$updated[ $safe_key ] = $safe_value;
		}

		self::audit( 'update_term_meta', 'success', implode( ', ', array_keys( $updated ) ), $term_id );
		return Axtolab_AI_Connector_Response::success(
			array(
				'term_id'  => $term_id,
				'taxonomy' => $term->taxonomy,
				'updated'  => $updated,
			),
			200,
			self::audit_id()
		);
	}

	/**
	 * DELETE /terms/{term_id}/meta/{meta_key} — delete a single term meta key.
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response
	 */
	public static function handle_delete_term_meta( WP_REST_Request $request ) {
		$term_id = (int) $request->get_param( 'term_id' );
		$term    = get_term( $term_id );
		if ( ! $term || is_wp_error( $term ) ) {
			return Axtolab_AI_Connector_Response::error( 'not_found', 'Term not found.', 404, self::audit_id() );
		}

		$key     = sanitize_key( (string) $request->get_param( 'meta_key' ) );
		$before  = Axtolab_AI_Connector_Snapshots::capture_term_meta( $term_id, $key );
		$deleted = delete_term_meta( $term_id, $key );
		$after   = Axtolab_AI_Connector_Snapshots::capture_term_meta( $term_id, $key );
		self::record_change(
			'term_meta',
			$term_id . ':' . $key,
			Axtolab_AI_Connector_Changelog::ACTION_DELETE,
			'wp_delete_term_meta',
			$before,
			$after
		);

		self::audit( 'delete_term_meta', 'success', $key, $term_id );
		return Axtolab_AI_Connector_Response::success(
			array(
				'term_id' => $term_id,
				'key'     => $key,
				'deleted' => (bool) $deleted,
			),
			200,
			self::audit_id()
		);
	}

	// =========================================================================
	// Plugins & Themes inventory (read-only)
	// =========================================================================

	/**
	 * GET /plugins — list all installed plugins with metadata + active status.
	 *
	 * Returns: array of { slug, file, name, version, description, author,
	 * author_uri, plugin_uri, requires_wp, requires_php, network, active }.
	 *
	 * @return WP_REST_Response
	 */
	public static function handle_list_plugins() {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$installed = get_plugins();
		$active    = (array) get_option( 'active_plugins', array() );
		$network   = is_multisite() ? array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ) : array();

		$out = array();
		foreach ( $installed as $file => $data ) {
			$slug = dirname( $file );
			if ( '.' === $slug ) {
				$slug = basename( $file, '.php' );
			}
			$out[] = array(
				'slug'         => $slug,
				'file'         => $file,
				'name'         => isset( $data['Name'] ) ? (string) $data['Name'] : '',
				'version'      => isset( $data['Version'] ) ? (string) $data['Version'] : '',
				'description'  => isset( $data['Description'] ) ? wp_strip_all_tags( (string) $data['Description'] ) : '',
				'author'       => isset( $data['Author'] ) ? wp_strip_all_tags( (string) $data['Author'] ) : '',
				'author_uri'   => isset( $data['AuthorURI'] ) ? (string) $data['AuthorURI'] : '',
				'plugin_uri'   => isset( $data['PluginURI'] ) ? (string) $data['PluginURI'] : '',
				'requires_wp'  => isset( $data['RequiresWP'] ) ? (string) $data['RequiresWP'] : '',
				'requires_php' => isset( $data['RequiresPHP'] ) ? (string) $data['RequiresPHP'] : '',
				'network'      => in_array( $file, $network, true ),
				'active'       => in_array( $file, $active, true ) || in_array( $file, $network, true ),
			);
		}

		return Axtolab_AI_Connector_Response::success(
			array(
				'count'   => count( $out ),
				'plugins' => $out,
			),
			200,
			self::audit_id()
		);
	}

	/**
	 * GET /themes — list all installed themes with metadata + active flag.
	 *
	 * Returns: array of { stylesheet, name, version, description, author,
	 * theme_uri, requires_wp, requires_php, parent, active }.
	 *
	 * @return WP_REST_Response
	 */
	public static function handle_list_themes() {
		$installed   = wp_get_themes();
		$active_slug = get_option( 'stylesheet' );

		$out = array();
		foreach ( $installed as $stylesheet => $theme ) {
			$parent = $theme->parent();
			$out[]  = array(
				'stylesheet'   => (string) $stylesheet,
				'name'         => (string) $theme->get( 'Name' ),
				'version'      => (string) $theme->get( 'Version' ),
				'description'  => wp_strip_all_tags( (string) $theme->get( 'Description' ) ),
				'author'       => wp_strip_all_tags( (string) $theme->get( 'Author' ) ),
				'theme_uri'    => (string) $theme->get( 'ThemeURI' ),
				'requires_wp'  => (string) $theme->get( 'RequiresWP' ),
				'requires_php' => (string) $theme->get( 'RequiresPHP' ),
				'parent'       => $parent ? (string) $parent->get_stylesheet() : null,
				'active'       => $stylesheet === $active_slug,
			);
		}

		return Axtolab_AI_Connector_Response::success(
			array(
				'count'  => count( $out ),
				'themes' => $out,
			),
			200,
			self::audit_id()
		);
	}

	/**
	 * Map a permalink_structure string to a human-readable label.
	 *
	 * Matches WordPress's built-in named structures; anything else returns
	 * 'custom'.
	 *
	 * @param string $structure
	 * @return string
	 */
	private static function permalink_type_label( $structure ) {
		if ( '' === $structure ) {
			return 'plain';
		}
		switch ( $structure ) {
			case '/%year%/%monthnum%/%day%/%postname%/':
				return 'day_and_name';
			case '/%year%/%monthnum%/%postname%/':
				return 'month_and_name';
			case '/archives/%post_id%':
				return 'numeric';
			case '/%postname%/':
				return 'post_name';
		}
		return 'custom';
	}
}
endif;

if ( ! class_exists( 'MCP_Gateway_REST', false ) ) {
	class_alias( 'Axtolab_AI_Connector_REST', 'MCP_Gateway_REST' );
}
