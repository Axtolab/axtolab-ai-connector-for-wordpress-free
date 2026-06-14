<?php
/**
 * WooCommerce guardrail admin screen.
 *
 * @package WP_MCP_Gateway
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( class_exists( 'Axtolab_AI_Connector_WooCommerce_Admin', false ) ) {
	return;
}

/**
 * Registers and renders the WooCommerce guardrail settings page.
 */
final class Axtolab_AI_Connector_WooCommerce_Admin {
	const PAGE_SLUG = 'axtolab-ai-connector-woocommerce';

	/**
	 * Register admin hooks.
	 *
	 * @return void
	 */
	public static function bootstrap(): void {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ), 20 );
	}

	/**
	 * Add the guardrail submenu.
	 *
	 * @return void
	 */
	public static function register_menu(): void {
		$parent = defined( 'AXTOLAB_ADMIN_PARENT_SLUG' ) ? AXTOLAB_ADMIN_PARENT_SLUG : 'options-general.php';

		add_submenu_page(
			$parent,
			__( 'WooCommerce Guardrails', 'axtolab-ai-connector' ),
			__( 'WooCommerce Guardrails', 'axtolab-ai-connector' ),
			'manage_options',
			self::PAGE_SLUG,
			array( __CLASS__, 'render_page' )
		);
	}

	/**
	 * Render the guardrail settings page.
	 *
	 * @return void
	 */
	public static function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'axtolab-ai-connector' ) );
		}

		if ( isset( $_POST['axtolab_ai_connector_woo_guardrails_save'] ) && check_admin_referer( 'axtolab_ai_connector_woo_guardrails' ) ) {
			Axtolab_AI_Connector_WooCommerce_Guardrails::save_settings( wp_unslash( $_POST ) );
			echo '<div class="notice notice-success"><p>' . esc_html__( 'WooCommerce guardrails saved.', 'axtolab-ai-connector' ) . '</p></div>';
		}

		$guardrails = Axtolab_AI_Connector_WooCommerce_Guardrails::settings();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'WooCommerce Guardrails', 'axtolab-ai-connector' ); ?></h1>
			<p><?php esc_html_e( 'These caps apply to AI-driven WooCommerce product and coupon writes exposed by the AI Connector. Every successful write is captured in Logs & Roll Back.', 'axtolab-ai-connector' ); ?></p>

			<form method="post" style="background:#fff;padding:1em;border:1px solid #c3c4c7;max-width:760px;">
				<?php wp_nonce_field( 'axtolab_ai_connector_woo_guardrails' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Max price change per write', 'axtolab-ai-connector' ); ?></th>
						<td>
							<input type="number" step="0.5" min="0" max="100" name="max_price_change_pct" value="<?php echo esc_attr( $guardrails['max_price_change_pct'] ); ?>" />%
							<p class="description"><?php esc_html_e( 'Refuses product price writes where the new regular price differs from the old by more than this percentage. Default 20.', 'axtolab-ai-connector' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Max coupon discount', 'axtolab-ai-connector' ); ?></th>
						<td>
							<input type="number" step="0.5" min="0" max="100" name="max_coupon_discount_pct" value="<?php echo esc_attr( $guardrails['max_coupon_discount_pct'] ); ?>" />%
							<p class="description"><?php esc_html_e( 'Refuses percent coupons above this value. Default 50.', 'axtolab-ai-connector' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Max refund total per session', 'axtolab-ai-connector' ); ?></th>
						<td>
							$<input type="number" step="0.5" min="0" name="max_refund_per_session" value="<?php echo esc_attr( $guardrails['max_refund_per_session'] ); ?>" />
							<p class="description"><?php esc_html_e( 'Reserved for future refund tools. Kept visible so the WooCommerce safety surface stays in one place.', 'axtolab-ai-connector' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Block zero-price writes', 'axtolab-ai-connector' ); ?></th>
						<td><label><input type="checkbox" name="block_zero_price_writes" value="1" <?php checked( $guardrails['block_zero_price_writes'] ); ?> /> <?php esc_html_e( 'Refuse writes that set a regular price to 0 or below.', 'axtolab-ai-connector' ); ?></label></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Block global coupons without minimum spend', 'axtolab-ai-connector' ); ?></th>
						<td><label><input type="checkbox" name="block_no_min_spend_global_coupons" value="1" <?php checked( $guardrails['block_no_min_spend_global_coupons'] ); ?> /> <?php esc_html_e( 'Refuse coupons with no product/category restriction and no minimum_amount.', 'axtolab-ai-connector' ); ?></label></td>
					</tr>
				</table>
				<p>
					<button type="submit" name="axtolab_ai_connector_woo_guardrails_save" value="1" class="button button-primary"><?php esc_html_e( 'Save guardrails', 'axtolab-ai-connector' ); ?></button>
				</p>
			</form>
		</div>
		<?php
	}
}
