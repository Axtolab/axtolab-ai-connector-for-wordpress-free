<?php
/**
 * WooCommerce REST routes for AI Connector.
 *
 * @package WP_MCP_Gateway
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( class_exists( 'Axtolab_AI_Connector_WooCommerce_REST', false ) ) {
	return;
}

// phpcs:disable Squiz.Commenting.FunctionComment.Missing -- Route handlers are grouped by route name.

/**
 * Registers and handles basic WooCommerce read/write REST endpoints.
 */
final class Axtolab_AI_Connector_WooCommerce_REST {
	const NS          = 'wp-mcp-gateway/v1';
	const TEXT_DOMAIN = 'axtolab-ai-connector';

	public static function bootstrap(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	public static function register_routes(): void {
		register_rest_route(
			self::NS,
			'/woo/products',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'list_products' ),
				'permission_callback' => array( __CLASS__, 'permission_read_products' ),
			)
		);

		register_rest_route(
			self::NS,
			'/woo/products/(?P<id>\d+)',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'get_product' ),
				'permission_callback' => array( __CLASS__, 'permission_read_products' ),
			)
		);

		register_rest_route(
			self::NS,
			'/woo/products/(?P<id>\d+)/price',
			array(
				'methods'             => WP_REST_Server::EDITABLE,
				'callback'            => array( __CLASS__, 'update_price' ),
				'permission_callback' => array( __CLASS__, 'permission_edit_products' ),
			)
		);

		register_rest_route(
			self::NS,
			'/woo/products/bulk-price',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'bulk_update_prices' ),
				'permission_callback' => array( __CLASS__, 'permission_edit_products' ),
			)
		);

		register_rest_route(
			self::NS,
			'/woo/orders',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'list_orders' ),
				'permission_callback' => array( __CLASS__, 'permission_read_orders' ),
			)
		);

		register_rest_route(
			self::NS,
			'/woo/orders/(?P<id>\d+)',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'get_order' ),
				'permission_callback' => array( __CLASS__, 'permission_read_orders' ),
			)
		);

		register_rest_route(
			self::NS,
			'/woo/coupons',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'create_coupon' ),
				'permission_callback' => array( __CLASS__, 'permission_edit_coupons' ),
			)
		);
	}

	public static function permission_read_products() {
		return self::permission_any_woo_cap( array( 'manage_woocommerce', 'edit_products', 'read_private_products' ) );
	}

	public static function permission_edit_products() {
		return self::permission_any_woo_cap( array( 'manage_woocommerce', 'edit_products', 'edit_published_products' ) );
	}

	public static function permission_read_orders() {
		return self::permission_any_woo_cap( array( 'manage_woocommerce', 'edit_shop_orders', 'read_private_shop_orders' ) );
	}

	public static function permission_edit_coupons() {
		return self::permission_any_woo_cap( array( 'manage_woocommerce', 'edit_shop_coupons', 'publish_shop_coupons' ) );
	}

	private static function permission_any_woo_cap( array $caps ) {
		if ( ! is_user_logged_in() ) {
			return new WP_Error( 'unauthorized', __( 'Authentication required.', 'axtolab-ai-connector' ), array( 'status' => 401 ) );
		}

		foreach ( $caps as $cap ) {
			if ( current_user_can( $cap ) ) {
				return true;
			}
		}

		return new WP_Error( 'forbidden', __( 'Insufficient WooCommerce capabilities.', 'axtolab-ai-connector' ), array( 'status' => 403 ) );
	}

	private static function require_woo() {
		if ( ! function_exists( 'wc_get_product' ) || ! function_exists( 'wc_get_orders' ) || ! class_exists( 'WC_Coupon' ) ) {
			return new WP_Error( 'woocommerce_missing', __( 'WooCommerce is not active.', 'axtolab-ai-connector' ), array( 'status' => 503 ) );
		}

		return null;
	}

	public static function list_products( WP_REST_Request $request ): WP_REST_Response {
		$err = self::require_woo();
		if ( $err ) {
			return self::from_wp_error( $err );
		}

		$per_page = $request->get_param( 'per_page' );
		$page     = $request->get_param( 'page' );
		$status   = $request->get_param( 'status' );
		$search   = $request->get_param( 'search' );

		$args = array(
			'post_type'      => 'product',
			'posts_per_page' => min( 100, max( 1, (int) ( $per_page ? $per_page : 20 ) ) ),
			'paged'          => max( 1, (int) ( $page ? $page : 1 ) ),
			'post_status'    => $status ? $status : 'publish',
			's'              => $search ? $search : '',
		);

		$query = new WP_Query( $args );
		$rows  = array();
		foreach ( $query->posts as $post ) {
			$rows[] = self::format_product( wc_get_product( $post->ID ) );
		}

		return Axtolab_AI_Connector_Response::success(
			array(
				'count'    => count( $rows ),
				'total'    => (int) $query->found_posts,
				'per_page' => $args['posts_per_page'],
				'page'     => $args['paged'],
				'products' => $rows,
			),
			200,
			self::audit_id()
		);
	}

	public static function get_product( WP_REST_Request $request ): WP_REST_Response {
		$err = self::require_woo();
		if ( $err ) {
			return self::from_wp_error( $err );
		}

		$product = wc_get_product( (int) $request->get_param( 'id' ) );
		if ( ! $product ) {
			return Axtolab_AI_Connector_Response::error( 'not_found', __( 'Product not found.', 'axtolab-ai-connector' ), 404, self::audit_id() );
		}

		return Axtolab_AI_Connector_Response::success( self::format_product( $product, true ), 200, self::audit_id() );
	}

	public static function list_orders( WP_REST_Request $request ): WP_REST_Response {
		$err = self::require_woo();
		if ( $err ) {
			return self::from_wp_error( $err );
		}

		$status   = $request->get_param( 'status' );
		$per_page = $request->get_param( 'per_page' );
		$page     = $request->get_param( 'page' );
		$status   = (string) ( $status ? $status : 'any' );
		$args     = array(
			'limit'  => min( 100, max( 1, (int) ( $per_page ? $per_page : 20 ) ) ),
			'page'   => max( 1, (int) ( $page ? $page : 1 ) ),
			'type'   => 'shop_order',
			'status' => 'any' === $status ? array_keys( wc_get_order_statuses() ) : $status,
		);

		$orders = wc_get_orders( $args );
		$rows   = array();
		foreach ( $orders as $order ) {
			if ( $order instanceof WC_Order ) {
				$rows[] = self::format_order( $order );
			}
		}

		return Axtolab_AI_Connector_Response::success(
			array(
				'count'  => count( $rows ),
				'orders' => $rows,
			),
			200,
			self::audit_id()
		);
	}

	public static function get_order( WP_REST_Request $request ): WP_REST_Response {
		$err = self::require_woo();
		if ( $err ) {
			return self::from_wp_error( $err );
		}

		$order = wc_get_order( (int) $request->get_param( 'id' ) );
		if ( ! $order || ! ( $order instanceof WC_Order ) ) {
			return Axtolab_AI_Connector_Response::error( 'not_found', __( 'Order not found.', 'axtolab-ai-connector' ), 404, self::audit_id() );
		}

		return Axtolab_AI_Connector_Response::success( self::format_order( $order, true ), 200, self::audit_id() );
	}

	public static function update_price( WP_REST_Request $request ): WP_REST_Response {
		$err = self::require_woo();
		if ( $err ) {
			return self::from_wp_error( $err );
		}

		$product = wc_get_product( (int) $request->get_param( 'id' ) );
		if ( ! $product ) {
			return Axtolab_AI_Connector_Response::error( 'not_found', __( 'Product not found.', 'axtolab-ai-connector' ), 404, self::audit_id() );
		}

		$body          = self::json_body( $request );
		$regular_price = array_key_exists( 'regular_price', $body ) ? (float) $body['regular_price'] : null;
		$sale_price    = array_key_exists( 'sale_price', $body ) ? (float) $body['sale_price'] : null;

		if ( null === $regular_price && null === $sale_price ) {
			return Axtolab_AI_Connector_Response::error( 'missing_price', __( 'Provide regular_price or sale_price.', 'axtolab-ai-connector' ), 400, self::audit_id() );
		}

		$old_regular = (float) $product->get_regular_price();
		if ( null !== $regular_price ) {
			$check = apply_filters( 'axtolab_ai_connector_woo_price_change_allowed', true, $old_regular, $regular_price );
			if ( is_wp_error( $check ) ) {
				return self::from_wp_error( $check );
			}
		}

		$before = self::format_product( $product, true );

		if ( null !== $regular_price ) {
			$product->set_regular_price( (string) $regular_price );
		}
		if ( null !== $sale_price ) {
			$product->set_sale_price( $sale_price > 0 ? (string) $sale_price : '' );
		}
		$product->save();

		$after     = self::format_product( wc_get_product( $product->get_id() ), true );
		$change_id = self::record_woo_change(
			array(
				'target_type' => 'woo_product',
				'target_id'   => (string) $product->get_id(),
				'action'      => Axtolab_AI_Connector_Changelog::ACTION_UPDATE,
				'tool_name'   => 'wp_woo_update_product_price',
				'before'      => $before,
				'after'       => $after,
				'note'        => 'WooCommerce price update for product #' . $product->get_id(),
			)
		);
		if ( is_wp_error( $change_id ) ) {
			self::restore_product_snapshot( $before );
			return self::from_wp_error( $change_id );
		}

		$after['change_id'] = (int) $change_id;
		return Axtolab_AI_Connector_Response::success( $after, 200, self::audit_id() );
	}

	public static function bulk_update_prices( WP_REST_Request $request ): WP_REST_Response {
		$err = self::require_woo();
		if ( $err ) {
			return self::from_wp_error( $err );
		}

		$body   = self::json_body( $request );
		$ids    = isset( $body['product_ids'] ) && is_array( $body['product_ids'] ) ? array_map( 'intval', $body['product_ids'] ) : array();
		$pct    = array_key_exists( 'percent_change', $body ) ? (float) $body['percent_change'] : null;
		$set_to = array_key_exists( 'set_to', $body ) ? (float) $body['set_to'] : null;

		if ( empty( $ids ) ) {
			return Axtolab_AI_Connector_Response::error( 'missing_ids', __( 'product_ids array is required.', 'axtolab-ai-connector' ), 400, self::audit_id() );
		}
		if ( count( $ids ) > 100 ) {
			return Axtolab_AI_Connector_Response::error( 'too_many', __( 'Bulk batch capped at 100 products.', 'axtolab-ai-connector' ), 400, self::audit_id() );
		}
		if ( null === $pct && null === $set_to ) {
			return Axtolab_AI_Connector_Response::error( 'missing_op', __( 'Provide percent_change or set_to.', 'axtolab-ai-connector' ), 400, self::audit_id() );
		}

		$results   = array();
		$succeeded = 0;
		$failed    = 0;

		foreach ( $ids as $pid ) {
			$product = wc_get_product( $pid );
			if ( ! $product ) {
				$results[] = array(
					'product_id' => $pid,
					'success'    => false,
					'error'      => 'not_found',
				);
				++$failed;
				continue;
			}

			$old = (float) $product->get_regular_price();
			$new = null !== $pct ? round( $old * ( 1 + $pct / 100.0 ), 2 ) : $set_to;

			$check = apply_filters( 'axtolab_ai_connector_woo_price_change_allowed', true, $old, $new );
			if ( is_wp_error( $check ) ) {
				$results[] = array(
					'product_id' => $pid,
					'success'    => false,
					'error'      => $check->get_error_code(),
					'message'    => $check->get_error_message(),
				);
				++$failed;
				continue;
			}

			$before = self::format_product( $product, true );
			$product->set_regular_price( (string) $new );
			$product->save();
			$after = self::format_product( wc_get_product( $pid ), true );

			$change_id = self::record_woo_change(
				array(
					'target_type' => 'woo_product',
					'target_id'   => (string) $pid,
					'action'      => Axtolab_AI_Connector_Changelog::ACTION_UPDATE,
					'tool_name'   => 'wp_woo_bulk_update_prices',
					'before'      => $before,
					'after'       => $after,
					'note'        => 'WooCommerce bulk price update for product #' . $pid,
				)
			);

			if ( is_wp_error( $change_id ) ) {
				self::restore_product_snapshot( $before );
				$results[] = array(
					'product_id' => $pid,
					'success'    => false,
					'error'      => $change_id->get_error_code(),
					'message'    => $change_id->get_error_message(),
				);
				++$failed;
				continue;
			}

			$results[] = array(
				'product_id' => $pid,
				'success'    => true,
				'old_price'  => $old,
				'new_price'  => $new,
				'change_id'  => (int) $change_id,
			);
			++$succeeded;
		}

		return Axtolab_AI_Connector_Response::success(
			array(
				'count'     => count( $ids ),
				'succeeded' => $succeeded,
				'failed'    => $failed,
				'results'   => $results,
			),
			200,
			self::audit_id()
		);
	}

	public static function create_coupon( WP_REST_Request $request ): WP_REST_Response {
		$err = self::require_woo();
		if ( $err ) {
			return self::from_wp_error( $err );
		}

		$body = self::json_body( $request );
		if ( empty( $body['code'] ) ) {
			return Axtolab_AI_Connector_Response::error( 'missing_code', __( 'Coupon code is required.', 'axtolab-ai-connector' ), 400, self::audit_id() );
		}

		$body['product_ids']        = self::int_list( isset( $body['product_ids'] ) ? $body['product_ids'] : array() );
		$body['product_categories'] = self::int_list( isset( $body['product_categories'] ) ? $body['product_categories'] : array() );

		$check = apply_filters( 'axtolab_ai_connector_woo_coupon_allowed', $body );
		if ( is_wp_error( $check ) ) {
			return self::from_wp_error( $check );
		}

		$coupon = new WC_Coupon();
		$coupon->set_code( sanitize_text_field( (string) $body['code'] ) );
		$coupon->set_discount_type( (string) ( isset( $body['discount_type'] ) ? $body['discount_type'] : 'fixed_cart' ) );
		$coupon->set_amount( (string) ( isset( $body['amount'] ) ? $body['amount'] : 0 ) );
		if ( isset( $body['minimum_amount'] ) ) {
			$coupon->set_minimum_amount( (string) $body['minimum_amount'] );
		}
		if ( isset( $body['expires_at'] ) ) {
			$coupon->set_date_expires( (string) $body['expires_at'] );
		}
		if ( isset( $body['usage_limit'] ) ) {
			$coupon->set_usage_limit( (int) $body['usage_limit'] );
		}
		if ( ! empty( $body['product_ids'] ) ) {
			$coupon->set_product_ids( $body['product_ids'] );
		}
		if ( ! empty( $body['product_categories'] ) ) {
			$coupon->set_product_categories( $body['product_categories'] );
		}

		$coupon->save();
		$after = self::format_coupon( $coupon );

		$change_id = self::record_woo_change(
			array(
				'target_type' => 'woo_coupon',
				'target_id'   => (string) $coupon->get_id(),
				'action'      => Axtolab_AI_Connector_Changelog::ACTION_CREATE,
				'tool_name'   => 'wp_woo_create_coupon',
				'before'      => null,
				'after'       => $after,
				'note'        => 'WooCommerce coupon ' . $coupon->get_code() . ' created',
			)
		);
		if ( is_wp_error( $change_id ) ) {
			$coupon->delete( true );
			return self::from_wp_error( $change_id );
		}

		$after['change_id'] = (int) $change_id;
		return Axtolab_AI_Connector_Response::success( $after, 201, self::audit_id() );
	}

	/**
	 * Execute rollback for WooCommerce changelog target types.
	 *
	 * @param array $change Hydrated changelog row.
	 * @return WP_REST_Response
	 */
	public static function execute_rollback( array $change ): WP_REST_Response {
		$err = self::require_woo();
		if ( $err ) {
			return self::from_wp_error( $err );
		}

		switch ( $change['target_type'] ) {
			case 'woo_product':
				return self::execute_product_rollback( $change );
			case 'woo_coupon':
				return self::execute_coupon_rollback( $change );
			default:
				return Axtolab_AI_Connector_Response::error(
					'rollback_not_supported',
					'Rollback for target_type "' . $change['target_type'] . '" is not supported by WooCommerce rollback.',
					501,
					self::audit_id()
				);
		}
	}

	/**
	 * Restore a WooCommerce snapshot for redo flows.
	 *
	 * @param array  $snapshot    Product or coupon snapshot.
	 * @param string $target_type Changelog target type.
	 * @return true|WP_Error
	 */
	public static function restore_snapshot( array $snapshot, $target_type ) {
		$err = self::require_woo();
		if ( $err ) {
			return $err;
		}

		if ( 'woo_product' === $target_type ) {
			return self::restore_product_snapshot( $snapshot );
		}
		if ( 'woo_coupon' === $target_type ) {
			return self::restore_coupon_snapshot( $snapshot );
		}

		return new WP_Error( 'unsupported_target', 'Unsupported WooCommerce target_type: ' . $target_type, array( 'status' => 501 ) );
	}

	private static function execute_product_rollback( array $change ): WP_REST_Response {
		$before = isset( $change['before'] ) && is_array( $change['before'] ) ? $change['before'] : null;
		if ( ! $before ) {
			return Axtolab_AI_Connector_Response::error( 'no_before_snapshot', 'Change has no product snapshot to restore.', 400, self::audit_id() );
		}

		$product = wc_get_product( (int) $change['target_id'] );
		if ( ! $product ) {
			return Axtolab_AI_Connector_Response::error( 'not_found', 'Product not found.', 404, self::audit_id() );
		}

		$pre      = self::format_product( $product, true );
		$restored = self::restore_product_snapshot( $before );
		if ( is_wp_error( $restored ) ) {
			return self::from_wp_error( $restored );
		}
		$post = self::format_product( wc_get_product( (int) $change['target_id'] ), true );

		$rollback_id = self::record_woo_change(
			array(
				'target_type' => 'woo_product',
				'target_id'   => (string) $change['target_id'],
				'action'      => Axtolab_AI_Connector_Changelog::ACTION_UPDATE,
				'tool_name'   => 'wp_rollback_change',
				'before'      => $pre,
				'after'       => $post,
				'note'        => 'Rollback of change #' . $change['id'],
			)
		);

		if ( $rollback_id && ! is_wp_error( $rollback_id ) ) {
			Axtolab_AI_Connector_Changelog::mark_rolled_back( (int) $change['id'], (int) $rollback_id );
		}

		return Axtolab_AI_Connector_Response::success(
			array(
				'rolled_back_change_id' => (int) $change['id'],
				'rollback_change_id'    => $rollback_id && ! is_wp_error( $rollback_id ) ? (int) $rollback_id : null,
				'target_type'           => 'woo_product',
				'target_id'             => (int) $change['target_id'],
				'message'               => 'WooCommerce product change #' . $change['id'] . ' rolled back successfully.',
			),
			200,
			self::audit_id()
		);
	}

	private static function execute_coupon_rollback( array $change ): WP_REST_Response {
		$coupon = new WC_Coupon( (int) $change['target_id'] );
		if ( ! $coupon->get_id() ) {
			return Axtolab_AI_Connector_Response::error( 'not_found', 'Coupon not found.', 404, self::audit_id() );
		}

		$pre = self::format_coupon( $coupon );

		if ( Axtolab_AI_Connector_Changelog::ACTION_CREATE === $change['action'] || 'create' === $change['action'] ) {
			$coupon->delete( true );
			$post            = null;
			$rollback_action = Axtolab_AI_Connector_Changelog::ACTION_DELETE;
		} else {
			$before = isset( $change['before'] ) && is_array( $change['before'] ) ? $change['before'] : null;
			if ( ! $before ) {
				return Axtolab_AI_Connector_Response::error( 'no_before_snapshot', 'Change has no coupon snapshot to restore.', 400, self::audit_id() );
			}
			$restored = self::restore_coupon_snapshot( $before );
			if ( is_wp_error( $restored ) ) {
				return self::from_wp_error( $restored );
			}
			$post            = self::format_coupon( new WC_Coupon( (int) $change['target_id'] ) );
			$rollback_action = Axtolab_AI_Connector_Changelog::ACTION_UPDATE;
		}

		$rollback_id = self::record_woo_change(
			array(
				'target_type' => 'woo_coupon',
				'target_id'   => (string) $change['target_id'],
				'action'      => $rollback_action,
				'tool_name'   => 'wp_rollback_change',
				'before'      => $pre,
				'after'       => $post,
				'note'        => 'Rollback of change #' . $change['id'],
			)
		);

		if ( $rollback_id && ! is_wp_error( $rollback_id ) ) {
			Axtolab_AI_Connector_Changelog::mark_rolled_back( (int) $change['id'], (int) $rollback_id );
		}

		return Axtolab_AI_Connector_Response::success(
			array(
				'rolled_back_change_id' => (int) $change['id'],
				'rollback_change_id'    => $rollback_id && ! is_wp_error( $rollback_id ) ? (int) $rollback_id : null,
				'target_type'           => 'woo_coupon',
				'target_id'             => (int) $change['target_id'],
				'message'               => 'WooCommerce coupon change #' . $change['id'] . ' rolled back successfully.',
			),
			200,
			self::audit_id()
		);
	}

	private static function restore_product_snapshot( array $snapshot ) {
		$product = isset( $snapshot['id'] ) ? wc_get_product( (int) $snapshot['id'] ) : null;
		if ( ! $product ) {
			return new WP_Error( 'not_found', 'Product not found.', array( 'status' => 404 ) );
		}

		if ( array_key_exists( 'regular_price', $snapshot ) ) {
			$product->set_regular_price( (string) (float) $snapshot['regular_price'] );
		}
		if ( array_key_exists( 'sale_price', $snapshot ) ) {
			$sale = (float) $snapshot['sale_price'];
			$product->set_sale_price( $sale > 0 ? (string) $sale : '' );
		}
		$product->save();

		return true;
	}

	private static function restore_coupon_snapshot( array $snapshot ) {
		$coupon = null;
		if ( ! empty( $snapshot['id'] ) ) {
			$coupon = new WC_Coupon( (int) $snapshot['id'] );
		}
		if ( ! $coupon || ! $coupon->get_id() ) {
			$coupon = new WC_Coupon();
		}

		if ( empty( $snapshot['code'] ) ) {
			return new WP_Error( 'snapshot_invalid', 'Coupon snapshot is missing code.', array( 'status' => 400 ) );
		}

		$coupon->set_code( sanitize_text_field( (string) $snapshot['code'] ) );
		$coupon->set_discount_type( (string) ( isset( $snapshot['discount_type'] ) ? $snapshot['discount_type'] : 'fixed_cart' ) );
		$coupon->set_amount( (string) ( isset( $snapshot['amount'] ) ? $snapshot['amount'] : 0 ) );
		$coupon->set_minimum_amount( isset( $snapshot['minimum_amount'] ) ? (string) $snapshot['minimum_amount'] : '' );
		$coupon->set_usage_limit( isset( $snapshot['usage_limit'] ) && null !== $snapshot['usage_limit'] ? (int) $snapshot['usage_limit'] : 0 );
		$coupon->set_product_ids( self::int_list( isset( $snapshot['product_ids'] ) ? $snapshot['product_ids'] : array() ) );
		$coupon->set_product_categories( self::int_list( isset( $snapshot['product_categories'] ) ? $snapshot['product_categories'] : array() ) );
		if ( ! empty( $snapshot['date_expires'] ) ) {
			$coupon->set_date_expires( (string) $snapshot['date_expires'] );
		} else {
			$coupon->set_date_expires( null );
		}
		$coupon->save();

		return true;
	}

	private static function format_product( $product, $detail = false ) {
		if ( ! $product ) {
			return null;
		}

		$row = array(
			'id'             => $product->get_id(),
			'name'           => $product->get_name(),
			'slug'           => $product->get_slug(),
			'type'           => $product->get_type(),
			'status'         => $product->get_status(),
			'regular_price'  => (float) $product->get_regular_price(),
			'sale_price'     => (float) $product->get_sale_price(),
			'price'          => (float) $product->get_price(),
			'on_sale'        => $product->is_on_sale(),
			'stock_status'   => $product->get_stock_status(),
			'stock_quantity' => $product->get_stock_quantity(),
			'sku'            => $product->get_sku(),
		);

		if ( $detail ) {
			$row['description']       = $product->get_description();
			$row['short_description'] = $product->get_short_description();
			$row['categories']        = wp_get_post_terms( $product->get_id(), 'product_cat', array( 'fields' => 'names' ) );
			$row['tags']              = wp_get_post_terms( $product->get_id(), 'product_tag', array( 'fields' => 'names' ) );
			if ( $product->is_type( 'variable' ) ) {
				$row['variations'] = array();
				foreach ( $product->get_children() as $vid ) {
					$variation = wc_get_product( $vid );
					if ( $variation ) {
						$row['variations'][] = array(
							'id'             => $variation->get_id(),
							'attributes'     => $variation->get_attributes(),
							'regular_price'  => (float) $variation->get_regular_price(),
							'sale_price'     => (float) $variation->get_sale_price(),
							'stock_quantity' => $variation->get_stock_quantity(),
							'sku'            => $variation->get_sku(),
						);
					}
				}
			}
		}

		return $row;
	}

	private static function format_order( WC_Order $order, $detail = false ): array {
		$row = array(
			'id'           => $order->get_id(),
			'status'       => $order->get_status(),
			'total'        => (float) $order->get_total(),
			'currency'     => $order->get_currency(),
			'date_created' => $order->get_date_created() ? $order->get_date_created()->date( 'c' ) : null,
			'customer_id'  => $order->get_customer_id(),
		);

		if ( $detail ) {
			$row['billing']    = $order->get_address( 'billing' );
			$row['shipping']   = $order->get_address( 'shipping' );
			$row['line_items'] = array();
			foreach ( $order->get_items() as $item ) {
				$row['line_items'][] = array(
					'name'       => $item->get_name(),
					'product_id' => $item->get_product_id(),
					'quantity'   => $item->get_quantity(),
					'subtotal'   => (float) $item->get_subtotal(),
					'total'      => (float) $item->get_total(),
				);
			}
		}

		return $row;
	}

	private static function format_coupon( WC_Coupon $coupon ): array {
		return array(
			'id'                 => $coupon->get_id(),
			'code'               => $coupon->get_code(),
			'discount_type'      => $coupon->get_discount_type(),
			'amount'             => (float) $coupon->get_amount(),
			'minimum_amount'     => (float) $coupon->get_minimum_amount(),
			'usage_limit'        => $coupon->get_usage_limit(),
			'date_expires'       => $coupon->get_date_expires() ? $coupon->get_date_expires()->date( 'c' ) : null,
			'product_ids'        => array_map( 'intval', (array) $coupon->get_product_ids() ),
			'product_categories' => array_map( 'intval', (array) $coupon->get_product_categories() ),
		);
	}

	private static function record_woo_change( array $args ) {
		if ( ! class_exists( 'Axtolab_AI_Connector_Changelog' ) || ! Axtolab_AI_Connector_Changelog::is_enabled() ) {
			return new WP_Error( 'changelog_unavailable', 'Roll Back capture is unavailable; WooCommerce write was not confirmed successful.', array( 'status' => 500 ) );
		}

		$args['session_id'] = isset( $args['session_id'] ) ? $args['session_id'] : self::current_mcp_session_id();
		$record_id          = Axtolab_AI_Connector_Changelog::record( $args );
		if ( ! $record_id ) {
			return new WP_Error( 'changelog_record_failed', 'Roll Back capture failed; WooCommerce write was not confirmed successful.', array( 'status' => 500 ) );
		}

		return (int) $record_id;
	}

	private static function json_body( WP_REST_Request $request ): array {
		$body = $request->get_json_params();
		return is_array( $body ) ? $body : array();
	}

	private static function int_list( $value ): array {
		if ( ! is_array( $value ) ) {
			return array();
		}

		$ints = array();
		foreach ( $value as $item ) {
			$id = absint( $item );
			if ( $id > 0 ) {
				$ints[] = $id;
			}
		}

		return array_values( array_unique( $ints ) );
	}

	private static function from_wp_error( WP_Error $error ): WP_REST_Response {
		$data   = $error->get_error_data( $error->get_error_code() );
		$status = is_array( $data ) && isset( $data['status'] ) ? (int) $data['status'] : 400;

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

	private static function current_mcp_session_id(): string {
		if ( ! empty( $_SERVER['HTTP_MCP_SESSION_ID'] ) ) {
			return sanitize_text_field( wp_unslash( (string) $_SERVER['HTTP_MCP_SESSION_ID'] ) );
		}
		return '';
	}
}

if ( ! class_exists( 'MCP_Gateway_WooCommerce_REST', false ) ) {
	class_alias( 'Axtolab_AI_Connector_WooCommerce_REST', 'MCP_Gateway_WooCommerce_REST' );
}
