<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Thin helper that returns native WordPress preview URLs.
 *
 * The previous version of this class implemented HMAC-signed shareable preview
 * URLs that temporarily authenticated the viewer as the post author via
 * `wp_set_current_user()`. That mechanism was removed from this WordPress.org
 * package because WP.org's review team flagged the user-impersonation pattern
 * as a security concern (any unauthenticated request with the right query
 * params during the transient window could obtain author context).
 *
 * In this package, `wp_get_preview_link` returns the standard WordPress
 * preview URL (`?preview=true&p={id}`). That URL only works when the viewer
 * is already logged in to wp-admin in the same browser session and has
 * permission to edit the post — i.e., the standard WordPress preview flow,
 * which is well-tested and respects WordPress's own permission model.
 *
 * Shareable signed preview URLs (preview without requiring the viewer to be
 * logged in to wp-admin) are available in the Axtolab AI Connector Core
 * build distributed separately at axtolab.com, where the implementation has
 * been re-architected to avoid `wp_set_current_user` entirely.
 */
if ( ! class_exists( 'Axtolab_AI_Connector_Preview', false ) ) :
final class Axtolab_AI_Connector_Preview {

	/**
	 * No-op bootstrap kept so existing wiring in the main plugin file can call it.
	 */
	public static function bootstrap(): void {
		// Intentionally empty. No template_redirect or init hooks needed —
		// this package does not intercept frontend requests for preview.
	}

	/**
	 * Build a preview-link response for a given post.
	 *
	 * Returns the standard WordPress preview URL via `get_preview_post_link()`.
	 * The viewer must be logged in to wp-admin in the same browser to use it.
	 *
	 * @param int $post_id The post to preview.
	 * @return array { post_id, wp_preview_url, expires_at:null }
	 */
	public static function build_signed_preview_url( int $post_id ): array {
		return array(
			'post_id'        => $post_id,
			'wp_preview_url' => get_preview_post_link( $post_id ),
			'expires_at'     => null,
		);
	}
}
endif;

if ( ! class_exists( 'MCP_Gateway_Preview', false ) ) {
	class_alias( 'Axtolab_AI_Connector_Preview', 'MCP_Gateway_Preview' );
}
