<?php
/**
 * WooCommerce guardrail settings for AI-driven writes.
 *
 * @package WP_MCP_Gateway
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( class_exists( 'Axtolab_AI_Connector_WooCommerce_Guardrails', false ) ) {
	return;
}

/**
 * Applies configurable safety caps to WooCommerce writes.
 */
final class Axtolab_AI_Connector_WooCommerce_Guardrails {
	const OPTION_KEY = 'axtolab_ai_connector_woo_guardrails';

	/**
	 * Register guardrail filters.
	 *
	 * @return void
	 */
	public static function bootstrap(): void {
		add_filter( 'axtolab_ai_connector_woo_price_change_allowed', array( __CLASS__, 'check_price_change' ), 10, 3 );
		add_filter( 'axtolab_ai_connector_woo_coupon_allowed', array( __CLASS__, 'check_coupon' ), 10, 1 );

		// Back-compat for the paid add-on until it defers its basic routes.
		add_filter( 'axtolab_woo_mcp_price_change_allowed', array( __CLASS__, 'check_price_change' ), 10, 3 );
		add_filter( 'axtolab_woo_mcp_coupon_allowed', array( __CLASS__, 'check_coupon' ), 10, 1 );
	}

	/**
	 * Default guardrail settings.
	 *
	 * @return array
	 */
	public static function defaults(): array {
		return array(
			'max_price_change_pct'              => 20.0,
			'max_coupon_discount_pct'           => 50.0,
			'max_refund_per_session'            => 200.0,
			'block_zero_price_writes'           => true,
			'block_no_min_spend_global_coupons' => true,
		);
	}

	/**
	 * Current guardrail settings.
	 *
	 * @return array
	 */
	public static function settings(): array {
		$saved = get_option( self::OPTION_KEY, array() );
		return is_array( $saved ) ? array_merge( self::defaults(), $saved ) : self::defaults();
	}

	/**
	 * Persist guardrail settings from an admin form submit.
	 *
	 * @param array $raw Raw form values.
	 * @return void
	 */
	public static function save_settings( array $raw ): void {
		$normalised = array(
			'max_price_change_pct'              => max( 0.0, min( 100.0, (float) ( isset( $raw['max_price_change_pct'] ) ? $raw['max_price_change_pct'] : 20.0 ) ) ),
			'max_coupon_discount_pct'           => max( 0.0, min( 100.0, (float) ( isset( $raw['max_coupon_discount_pct'] ) ? $raw['max_coupon_discount_pct'] : 50.0 ) ) ),
			'max_refund_per_session'            => max( 0.0, (float) ( isset( $raw['max_refund_per_session'] ) ? $raw['max_refund_per_session'] : 200.0 ) ),
			'block_zero_price_writes'           => ! empty( $raw['block_zero_price_writes'] ),
			'block_no_min_spend_global_coupons' => ! empty( $raw['block_no_min_spend_global_coupons'] ),
		);

		update_option( self::OPTION_KEY, $normalised, false );
	}

	/**
	 * Check whether a product price change passes the configured safety caps.
	 *
	 * @param bool       $allowed   Existing filter decision.
	 * @param float|null $old_price Previous regular price, or null when unknown.
	 * @param float      $new_price Proposed new price.
	 * @return true|WP_Error
	 */
	public static function check_price_change( $allowed, $old_price, $new_price ) {
		if ( true !== $allowed ) {
			$allowed = true;
		}

		$cfg       = self::settings();
		$new_price = (float) $new_price;

		if ( ! empty( $cfg['block_zero_price_writes'] ) && $new_price <= 0 ) {
			return new WP_Error(
				'guardrail_zero_price',
				__( 'Guardrail: setting a WooCommerce price to 0 or negative is blocked. Adjust WooCommerce guardrails in AI Connector settings to allow this.', 'axtolab-ai-connector' ),
				array( 'status' => 422 )
			);
		}

		if ( null !== $old_price && (float) $old_price > 0 ) {
			$pct_change = abs( ( $new_price - (float) $old_price ) / (float) $old_price ) * 100.0;
			if ( $pct_change > (float) $cfg['max_price_change_pct'] ) {
				return new WP_Error(
					'guardrail_price_change_too_large',
					sprintf(
						/* translators: 1: attempted percentage change, 2: configured cap. */
						__( 'Guardrail: price change of %1$.1f%% exceeds the configured cap of %2$.1f%%. Adjust the WooCommerce guardrail cap or split the change into smaller increments.', 'axtolab-ai-connector' ),
						$pct_change,
						(float) $cfg['max_price_change_pct']
					),
					array(
						'status'     => 422,
						'old_price'  => $old_price,
						'new_price'  => $new_price,
						'pct_change' => $pct_change,
					)
				);
			}
		}

		return true;
	}

	/**
	 * Check whether a coupon configuration passes sanity gates.
	 *
	 * @param mixed $args Coupon args.
	 * @return true|WP_Error
	 */
	public static function check_coupon( $args ) {
		if ( ! is_array( $args ) ) {
			$args = array();
		}

		$cfg    = self::settings();
		$type   = (string) ( isset( $args['discount_type'] ) ? $args['discount_type'] : 'fixed_cart' );
		$amount = (float) ( isset( $args['amount'] ) ? $args['amount'] : 0 );
		$min    = (float) ( isset( $args['minimum_amount'] ) ? $args['minimum_amount'] : 0 );

		if ( in_array( $type, array( 'percent', 'percent_product' ), true ) && $amount > (float) $cfg['max_coupon_discount_pct'] ) {
			return new WP_Error(
				'guardrail_coupon_too_aggressive',
				sprintf(
						/* translators: 1: attempted coupon percent, 2: configured cap. */
					__( 'Guardrail: %1$.1f%% coupon exceeds the configured max discount of %2$.1f%%.', 'axtolab-ai-connector' ),
					$amount,
					(float) $cfg['max_coupon_discount_pct']
				),
				array( 'status' => 422 )
			);
		}

		if (
			! empty( $cfg['block_no_min_spend_global_coupons'] )
			&& $min <= 0
			&& empty( $args['product_ids'] )
			&& empty( $args['product_categories'] )
		) {
			return new WP_Error(
				'guardrail_coupon_no_minimum',
				__( 'Guardrail: a global coupon without a minimum spend is blocked. Add minimum_amount or restrict the coupon to products/categories.', 'axtolab-ai-connector' ),
				array( 'status' => 422 )
			);
		}

		return true;
	}
}

if ( ! class_exists( 'MCP_Gateway_WooCommerce_Guardrails', false ) ) {
	class_alias( 'Axtolab_AI_Connector_WooCommerce_Guardrails', 'MCP_Gateway_WooCommerce_Guardrails' );
}
