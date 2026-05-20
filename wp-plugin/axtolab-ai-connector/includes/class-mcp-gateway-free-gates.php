<?php
/**
 * Extension seams for optional add-on modules.
 *
 * The AI Connector is 100% free with no restrictions. This class provides
 * filter-based seams that add-on plugins hook into to extend functionality.
 *
 * Current extension seams:
 * - AI image generation (Google Imagen, OpenAI) — included in the free core when
 *   a site owner supplies their own provider API key.
 * - WordPress Multisite support — included in the free core.
 * - Permissions & Audit — `axtolab_ai_connector_permissions_active` filter
 * - AI Governance — `axtolab_ai_connector_governance_active` filter
 * - WooCommerce AI — `axtolab_ai_connector_woocommerce_active` filter
 *
 * @package WP_MCP_Gateway
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Free-core extension controls.
 */
if ( ! class_exists( 'Axtolab_AI_Connector_Free_Gates', false ) ) :
final class Axtolab_AI_Connector_Free_Gates {
	/**
	 * Whether this install may use AI Connector on WordPress multisite.
	 *
	 * Multisite is fully supported in the free core (no add-on required).
	 * The filter is retained so site owners or future security add-ons
	 * can opt out — by default, returns true.
	 *
	 * @return bool
	 */
	public static function is_multisite_allowed(): bool {
		return (bool) apply_filters( 'axtolab_ai_connector_multisite_allowed', true );
	}

	/**
	 * Enforce the multisite gate. Returns true unless an explicit
	 * filter opt-out is in effect.
	 *
	 * @return true|WP_Error
	 */
	public static function check_multisite_allowed() {
		if ( self::is_multisite_allowed() ) {
			return true;
		}

		return new WP_Error(
			'multisite_disabled',
			__( 'AI Connector is disabled on this multisite install by filter.', 'axtolab-ai-connector' ),
			array( 'status' => 403 )
		);
	}

	/**
	 * Whether AI image generation is available.
	 *
	 * AI generation is included in the free core. The filter is retained so
	 * site owners or separate extension plugins can disable generation if a
	 * particular environment requires it.
	 *
	 * @return bool
	 */
	public static function is_image_generation_allowed(): bool {
		return (bool) apply_filters( 'axtolab_ai_connector_image_generation_allowed', true );
	}

	/**
	 * Enforce the AI image generation gate.
	 *
	 * @return true|WP_Error
	 */
	public static function check_image_generation_allowed() {
		if ( self::is_image_generation_allowed() ) {
			return true;
		}

		return new WP_Error(
			'image_generation_disabled',
			__( 'AI image generation has been disabled on this site by an administrator or extension plugin. Stock photos and media uploads are still available.', 'axtolab-ai-connector' ),
			array(
				'status' => 403,
			)
		);
	}

	/**
	 * Publishing is always allowed.
	 *
	 * @return true
	 */
	public static function check_publishing_allowed() {
		return true;
	}

	/**
	 * Scheduling is always allowed.
	 *
	 * @param string $date Requested publish date.
	 * @return true
	 */
	public static function check_scheduling_allowed( string $date ) {
		unset( $date );
		return true;
	}

	/**
	 * Date edits are always allowed.
	 *
	 * @param string  $date Requested date.
	 * @param WP_Post $post Existing post.
	 * @return true
	 */
	public static function check_update_date_allowed( string $date, WP_Post $post ) {
		unset( $date, $post );
		return true;
	}

	/**
	 * Status updates are always allowed.
	 *
	 * @param string $status Requested status.
	 * @return true
	 */
	public static function check_update_status_allowed( string $status ) {
		unset( $status );
		return true;
	}

	/**
	 * Future-dated drafts can always be published.
	 *
	 * @param WP_Post $post Existing post.
	 * @return true
	 */
	public static function check_existing_future_date_allowed( WP_Post $post ) {
		unset( $post );
		return true;
	}

	/**
	 * Reserve a publish request. Always succeeds.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @param WP_Post         $post    Post being published.
	 * @return array<string,mixed>
	 */
	public static function reserve_publish_request( WP_REST_Request $request, WP_Post $post ) {
		unset( $request, $post );
		return array( 'reserved' => false );
	}

	/**
	 * Confirm a publish request completed.
	 *
	 * @param mixed        $reservation      Reservation token.
	 * @param mixed        $updated          Updated post lookup result.
	 * @param string|array $expected_status Expected final status or statuses.
	 * @return true|WP_Error
	 */
	public static function confirm_publish_completed( $reservation, $updated, $expected_status = 'publish' ) {
		unset( $reservation );
		if ( ! $updated instanceof WP_Post ) {
			return new WP_Error( 'update_failed', __( 'Updated post lookup failed.', 'axtolab-ai-connector' ), array( 'status' => 500 ) );
		}

		$expected_statuses = array_map( 'strval', (array) $expected_status );
		if ( ! in_array( $updated->post_status, $expected_statuses, true ) ) {
			return new WP_Error(
				'publish_not_completed',
				__( 'Publish request did not complete. The post was left in an unexpected status by WordPress hooks or filters.', 'axtolab-ai-connector' ),
				array(
					'expected_status' => implode( ',', $expected_statuses ),
					'post_status'     => $updated->post_status,
					'status'          => 409,
				)
			);
		}

		return true;
	}

	/**
	 * Release a publish reservation. No-op.
	 *
	 * @param mixed $reservation Reservation token.
	 * @return void
	 */
	public static function release_publish_reservation( $reservation ): void {
		unset( $reservation );
	}
}
endif;

if ( ! class_exists( 'MCP_Gateway_Free_Gates', false ) ) {
	class_alias( 'Axtolab_AI_Connector_Free_Gates', 'MCP_Gateway_Free_Gates' );
}
