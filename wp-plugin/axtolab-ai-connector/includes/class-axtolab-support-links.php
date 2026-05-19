<?php
/**
 * Axtolab_AI_Connector_Support_Links — shared support-link helper for the
 * Axtolab plugin suite.
 *
 * Lives in the AI Connector core so every Axtolab add-on (AI Assistant,
 * Store Manager, Image Generation, AI Agent for WC, Spend Governance)
 * calls into the same helper for support email URLs, WP.org forum
 * links, docs links, and ready-to-render markup. One commit changes the
 * support email everywhere; one commit changes the support-link copy.
 *
 * Add-on plugins should defensive-check `class_exists` before calling —
 * even though their `Requires Plugins: axtolab-ai-connector` header keeps the
 * connector active, a misconfigured install (forced wp-cli activate that
 * bypassed the dependency check) shouldn't surface a fatal.
 *
 * Historical name `Axtolab_Support_Links` is kept as a `class_alias` at the
 * bottom of this file for back-compat with already-shipped add-ons.
 *
 * @package WP_MCP_Gateway
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Axtolab_AI_Connector_Support_Links {

	/**
	 * The single source of truth for the support inbox.
	 *
	 * Filterable so a self-hosted Axtolab install or an enterprise
	 * white-label can route to a different inbox without forking.
	 */
	public static function support_email(): string {
		return (string) apply_filters( 'axtolab_ai_connector_support_email', 'support@axtolab.com' );
	}

	/**
	 * Build a `mailto:` URL with a pre-filled subject so support emails
	 * self-categorise. Body deliberately not pre-filled — privacy risk
	 * via email-client copy-paste behaviour, and structured diagnostics
	 * are the diagnostic-bundle button's job (v1.3.0).
	 *
	 * @param string $plugin_label Human-readable plugin name e.g. "AI Connector".
	 * @param string $version      Version string e.g. "1.0.0". Optional.
	 * @return string mailto: URL ready to drop into an href.
	 */
	public static function email_url( string $plugin_label, string $version = '' ): string {
		$subject = 'Support: ' . $plugin_label;
		if ( '' !== $version ) {
			$subject .= ' v' . $version;
		}
		return 'mailto:' . rawurlencode( self::support_email() )
			. '?subject=' . rawurlencode( $subject );
	}

	/**
	 * Build a `mailto:` URL specifically for feature requests. Same
	 * inbox as support, different subject prefix (so triage can sort
	 * suggestions away from issues), and a short body template that
	 * prompts the merchant for the right details.
	 *
	 * Body is intentionally minimal — long mailto: URLs get truncated
	 * by some email clients (Outlook caps around 2 KB). We give them
	 * a structure to fill in, not a wall of pre-filled text.
	 *
	 * @param string $plugin_label Human-readable plugin name.
	 * @param string $version      Plugin version string.
	 */
	public static function feature_request_url( string $plugin_label, string $version = '' ): string {
		$subject = 'Feature request: ' . $plugin_label;
		if ( '' !== $version ) {
			$subject .= ' v' . $version;
		}

		$body = "Hi Axtolab,\n\n"
			. "I'd like to suggest a feature for {$plugin_label}.\n\n"
			. "What I'd like to do:\n\n\n"
			. "Why it matters / what I'm doing today instead:\n\n\n"
			. "How I'm using {$plugin_label} (site type, scale, other plugins):\n\n\n"
			. "Thanks,\n"
			. '— Your name';

		return 'mailto:' . rawurlencode( self::support_email() )
			. '?subject=' . rawurlencode( $subject )
			. '&body=' . rawurlencode( $body );
	}

	/**
	 * WP.org plugin support forum URL for a given plugin slug.
	 * Returns empty string for plugins not yet on WP.org.
	 *
	 * @param string $plugin_slug e.g. 'axtolab-ai-connector'.
	 */
	public static function wp_forum_url( string $plugin_slug ): string {
		$wporg_slug = self::wporg_slug( $plugin_slug );

		// Whitelist the plugins actually published on WP.org so we don't
		// link to 404 pages for plugins still in pre-launch.
		$on_wporg = (array) apply_filters(
			'axtolab_ai_connector_wp_forum_published_slugs',
			array(
				'axtolab-ai-connector',
			)
		);
		if ( ! in_array( $wporg_slug, $on_wporg, true ) ) {
			return '';
		}
		return 'https://wordpress.org/support/plugin/' . $wporg_slug . '/';
	}

	/**
	 * Map legacy/internal slugs to public WordPress.org slugs.
	 */
	private static function wporg_slug( string $plugin_slug ): string {
		return $plugin_slug;
	}

	/**
	 * Docs URL for a given plugin slug. Maps technical slugs to the
	 * human-friendly docs slugs axtolab.com uses, then assembles the
	 * URL. Filterable so a per-plugin override (e.g. linking a specific
	 * page rather than the doc landing) is a single config change.
	 */
	public static function docs_url( string $plugin_slug ): string {
		$docs_slug_map = array(
			'axtolab-ai-connector'        => 'ai-connector',
			'axtolab-ai-assistant'        => 'ai-assistant',
			'axtolab-woocommerce-ai'      => 'ai-store-manager',
			'axtolab-image-generation'    => 'ai-image-generation',
			'axtolab-woocommerce-mcp'     => 'ai-agent-for-woocommerce',
			'axtolab-ai-spend-governance' => 'ai-spend-governance',
		);
		$docs_slug     = $docs_slug_map[ $plugin_slug ] ?? $plugin_slug;
		$default       = 'https://axtolab.com/docs/' . $docs_slug;
		return (string) apply_filters( 'axtolab_ai_connector_docs_url', $default, $plugin_slug );
	}

	/**
	 * Render a "Need help?" footer suitable for a settings page.
	 * Outputs the markup directly; returns nothing.
	 *
	 * @param string $plugin_label Human-readable plugin name.
	 * @param string $version      Plugin version string.
	 * @param string $plugin_slug  Plugin slug (for forum/docs URLs).
	 */
	public static function render_footer( string $plugin_label, string $version, string $plugin_slug ): void {
		$email_url           = self::email_url( $plugin_label, $version );
		$feature_request_url = self::feature_request_url( $plugin_label, $version );
		$forum_url           = self::wp_forum_url( $plugin_slug );
		$docs_url            = self::docs_url( $plugin_slug );
		?>
		<div class="axtolab-support-footer" style="margin-top: 24px; padding: 14px 16px; background: #f6f7f7; border-radius: 4px; font-size: 13px; color: #50575e;">
			<strong><?php esc_html_e( 'Need help?', 'axtolab-ai-connector' ); ?></strong>
			<a href="<?php echo esc_url( $email_url ); ?>" style="margin: 0 6px;">
				<?php echo esc_html( self::support_email() ); ?>
			</a>
			<?php if ( '' !== $forum_url ) : ?>
				<span style="color:#c3c4c7;">·</span>
				<a href="<?php echo esc_url( $forum_url ); ?>" target="_blank" rel="noopener" style="margin: 0 6px;">
					<?php esc_html_e( 'WordPress.org support forum', 'axtolab-ai-connector' ); ?>
				</a>
			<?php endif; ?>
			<span style="color:#c3c4c7;">·</span>
			<a href="<?php echo esc_url( $docs_url ); ?>" target="_blank" rel="noopener" style="margin: 0 6px;">
				<?php esc_html_e( 'Docs', 'axtolab-ai-connector' ); ?>
			</a>
			<span style="color:#c3c4c7;">·</span>
			<a href="<?php echo esc_url( $feature_request_url ); ?>" style="margin: 0 6px;">
				<?php esc_html_e( 'Suggest a feature', 'axtolab-ai-connector' ); ?>
			</a>
		</div>
		<?php
	}

	/**
	 * Render a small "Need help?" link suitable for a settings-page
	 * header (next to / under the H1). Tighter than render_footer.
	 */
	public static function render_header_link( string $plugin_label, string $version ): void {
		$email_url = self::email_url( $plugin_label, $version );
		?>
		<span class="axtolab-support-header-link" style="float: right; font-size: 13px; margin-top: 6px;">
			<a href="<?php echo esc_url( $email_url ); ?>"><?php esc_html_e( 'Need help?', 'axtolab-ai-connector' ); ?></a>
		</span>
		<?php
	}

	/**
	 * Inline "Contact support" anchor for use inside admin notice copy.
	 * Returns the HTML rather than echoing so callers can drop it into
	 * existing translatable strings via printf / wp_kses_post.
	 */
	public static function inline_contact_link( string $plugin_label, string $version, string $link_text = '' ): string {
		$email_url = self::email_url( $plugin_label, $version );
		$text      = '' === $link_text ? __( 'contact support', 'axtolab-ai-connector' ) : $link_text;
		return sprintf(
			'<a href="%s">%s</a>',
			esc_url( $email_url ),
			esc_html( $text )
		);
	}

	/**
	 * Return an array of action/meta links suitable for:
	 *   add_filter( 'plugin_action_links_' . $basename, ... )
	 *   add_filter( 'plugin_row_meta', ... )
	 *
	 * Add-ons call this from their bootstrap to register the same
	 * "Settings · Support" / "Support · Docs" rows their merchants
	 * expect across the suite.
	 *
	 * @param string $plugin_label e.g. "AI Connector".
	 * @param string $version      e.g. "1.0.0".
	 * @param string $plugin_slug  e.g. "axtolab-ai-connector".
	 * @param string $settings_url Optional admin URL for "Settings" link.
	 * @return array{action: array<string,string>, meta: array<string,string>}
	 */
	public static function plugin_row_links( string $plugin_label, string $version, string $plugin_slug, string $settings_url = '' ): array {
		$action = array();
		if ( '' !== $settings_url ) {
			$action['settings'] = sprintf(
				'<a href="%s">%s</a>',
				esc_url( $settings_url ),
				esc_html__( 'Settings', 'axtolab-ai-connector' )
			);
		}
		$action['support'] = sprintf(
			'<a href="%s">%s</a>',
			esc_url( self::email_url( $plugin_label, $version ) ),
			esc_html__( 'Support', 'axtolab-ai-connector' )
		);

		$meta            = array();
		$meta['support'] = sprintf(
			'<a href="%s">%s</a>',
			esc_url( self::email_url( $plugin_label, $version ) ),
			esc_html__( 'Email support', 'axtolab-ai-connector' )
		);
		$forum_url       = self::wp_forum_url( $plugin_slug );
		if ( '' !== $forum_url ) {
			$meta['forum'] = sprintf(
				'<a href="%s" target="_blank" rel="noopener">%s</a>',
				esc_url( $forum_url ),
				esc_html__( 'WordPress.org forum', 'axtolab-ai-connector' )
			);
		}
		$meta['docs']            = sprintf(
			'<a href="%s" target="_blank" rel="noopener">%s</a>',
			esc_url( self::docs_url( $plugin_slug ) ),
			esc_html__( 'Docs', 'axtolab-ai-connector' )
		);
		$meta['feature_request'] = sprintf(
			'<a href="%s">%s</a>',
			esc_url( self::feature_request_url( $plugin_label, $version ) ),
			esc_html__( 'Suggest a feature', 'axtolab-ai-connector' )
		);

		return array(
			'action' => $action,
			'meta'   => $meta,
		);
	}
}

// Back-compat alias for already-shipped add-on plugins that reference the
// pre-rename class name. Safe to keep indefinitely — `class_alias` registers
// a synonym without duplicating the implementation.
if ( ! class_exists( 'Axtolab_Support_Links', false ) ) {
	class_alias( 'Axtolab_AI_Connector_Support_Links', 'Axtolab_Support_Links' );
}
