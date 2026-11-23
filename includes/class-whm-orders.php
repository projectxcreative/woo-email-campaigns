<?php
defined( 'ABSPATH' ) || exit;

/**
 * SQL fragments over WooCommerce orders, for both HPOS and legacy post storage.
 * All dates are GMT.
 */
class WHM_Orders {

	public static function hpos() {
		return class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' )
			&& \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
	}

	/**
	 * Paid order statuses, prefixed (wc-processing, wc-completed, ...).
	 */
	public static function paid_statuses() {
		$statuses = function_exists( 'wc_get_is_paid_statuses' ) ? wc_get_is_paid_statuses() : array( 'processing', 'completed' );
		$statuses = apply_filters( 'whm_paid_order_statuses', $statuses );
		return array_map(
			function ( $s ) {
				return 'wc-' . preg_replace( '/^wc-/', '', $s );
			},
			$statuses
		);
	}

	private static function in_list( array $values ) {
		global $wpdb;
		return implode( ',', array_map( fn( $v ) => $wpdb->prepare( '%s', $v ), $values ) );
	}

	/**
	 * Derived table: one row per paid order with email, date and total.
	 * Columns: order_id, email, date_gmt, total.
	 */
	public static function orders_sql() {
		global $wpdb;
		$statuses = self::in_list( self::paid_statuses() );
		if ( self::hpos() ) {
			return "SELECT o.id AS order_id, LOWER(o.billing_email) AS email, o.date_created_gmt AS date_gmt, o.total_amount AS total
				FROM {$wpdb->prefix}wc_orders o
				WHERE o.type = 'shop_order' AND o.status IN ({$statuses}) AND o.billing_email <> ''";
		}
		return "SELECT p.ID AS order_id, LOWER(em.meta_value) AS email, p.post_date_gmt AS date_gmt, CAST(tot.meta_value AS DECIMAL(19,4)) AS total
			FROM {$wpdb->posts} p
			INNER JOIN {$wpdb->postmeta} em ON em.post_id = p.ID AND em.meta_key = '_billing_email'
			LEFT JOIN {$wpdb->postmeta} tot ON tot.post_id = p.ID AND tot.meta_key = '_order_total'
			WHERE p.post_type = 'shop_order' AND p.post_status IN ({$statuses}) AND em.meta_value <> ''";
	}

	/**
	 * Derived table: one row per customer email with aggregates.
	 * Columns: email, orders, first_order, last_order, spent.
	 */
	public static function stats_sql() {
		return 'SELECT email, COUNT(*) AS orders, MIN(date_gmt) AS first_order, MAX(date_gmt) AS last_order, SUM(total) AS spent FROM (' . self::orders_sql() . ') whm_o GROUP BY email';
	}

	/**
	 * Emails of customers who bought any of the given product or variation IDs.
	 */
	public static function emails_bought_products_sql( array $product_ids ) {
		global $wpdb;
		$ids = implode( ',', array_map( 'absint', $product_ids ) );
		if ( '' === $ids ) {
			$ids = '0';
		}
		return 'SELECT DISTINCT whm_o.email FROM (' . self::orders_sql() . ") whm_o
			INNER JOIN {$wpdb->prefix}woocommerce_order_items oi ON oi.order_id = whm_o.order_id AND oi.order_item_type = 'line_item'
			INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta im ON im.order_item_id = oi.order_item_id AND im.meta_key IN ('_product_id','_variation_id')
			WHERE im.meta_value IN ({$ids})";
	}

	/**
	 * Emails of customers with an order that used the given coupon code (case-insensitive).
	 * Paid orders plus on-hold ones (e.g. bank transfer), since WooCommerce counts those as used.
	 */
	public static function emails_used_coupon_sql( $code ) {
		global $wpdb;
		$code = strtolower( trim( (string) $code ) );
		if ( '' === $code ) {
			return "SELECT ''";
		}
		$statuses = array_unique( array_merge( self::paid_statuses(), array( 'wc-on-hold' ) ) );
		$in       = self::in_list( $statuses );
		if ( self::hpos() ) {
			$orders = "SELECT o.id AS order_id, LOWER(o.billing_email) AS email FROM {$wpdb->prefix}wc_orders o
				WHERE o.type = 'shop_order' AND o.status IN ({$in}) AND o.billing_email <> ''";
		} else {
			$orders = "SELECT p.ID AS order_id, LOWER(em.meta_value) AS email FROM {$wpdb->posts} p
				INNER JOIN {$wpdb->postmeta} em ON em.post_id = p.ID AND em.meta_key = '_billing_email'
				WHERE p.post_type = 'shop_order' AND p.post_status IN ({$in}) AND em.meta_value <> ''";
		}
		return 'SELECT DISTINCT whm_co.email FROM (' . $orders . ") whm_co
			INNER JOIN {$wpdb->prefix}woocommerce_order_items oi ON oi.order_id = whm_co.order_id AND oi.order_item_type = 'coupon'
			WHERE LOWER(oi.order_item_name) = " . $wpdb->prepare( '%s', $code );
	}

	/**
	 * Product IDs in the given product categories (children included).
	 */
	public static function product_ids_in_categories( array $term_ids ) {
		$all = array();
		foreach ( array_map( 'absint', $term_ids ) as $t ) {
			if ( ! $t ) {
				continue;
			}
			$all[] = $t;
			$kids  = get_term_children( $t, 'product_cat' );
			if ( ! is_wp_error( $kids ) ) {
				$all = array_merge( $all, $kids );
			}
		}
		if ( ! $all ) {
			return array();
		}
		return get_posts(
			array(
				'post_type'      => 'product',
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'tax_query'      => array(
					array(
						'taxonomy' => 'product_cat',
						'field'    => 'term_id',
						'terms'    => array_unique( $all ),
					),
				),
			)
		);
	}

	/**
	 * Emails with an active WooCommerce Subscriptions subscription.
	 */
	public static function emails_active_subscription_sql() {
		global $wpdb;
		if ( self::hpos() ) {
			return "SELECT DISTINCT LOWER(billing_email) FROM {$wpdb->prefix}wc_orders WHERE type = 'shop_subscription' AND status IN ('wc-active','wc-pending-cancel') AND billing_email <> ''";
		}
		return "SELECT DISTINCT LOWER(em.meta_value) FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} em ON em.post_id = p.ID AND em.meta_key = '_billing_email'
			WHERE p.post_type = 'shop_subscription' AND p.post_status IN ('wc-active','wc-pending-cancel')";
	}

	/**
	 * Customers (email + names) for syncing into the contact list, paged.
	 */
	public static function customers_page( $after_order_id, $limit ) {
		global $wpdb;
		$statuses = self::in_list( self::paid_statuses() );
		if ( self::hpos() ) {
			return $wpdb->get_results(
				$wpdb->prepare(
					"SELECT o.id AS order_id, o.billing_email AS email, o.customer_id AS user_id, a.first_name, a.last_name
					FROM {$wpdb->prefix}wc_orders o
					LEFT JOIN {$wpdb->prefix}wc_order_addresses a ON a.order_id = o.id AND a.address_type = 'billing'
					WHERE o.type = 'shop_order' AND o.status IN ({$statuses}) AND o.billing_email <> '' AND o.id > %d
					ORDER BY o.id ASC LIMIT %d",
					$after_order_id,
					$limit
				)
			);
		}
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT p.ID AS order_id, em.meta_value AS email, COALESCE(cu.meta_value,0) AS user_id, fn.meta_value AS first_name, ln.meta_value AS last_name
				FROM {$wpdb->posts} p
				INNER JOIN {$wpdb->postmeta} em ON em.post_id = p.ID AND em.meta_key = '_billing_email'
				LEFT JOIN {$wpdb->postmeta} cu ON cu.post_id = p.ID AND cu.meta_key = '_customer_user'
				LEFT JOIN {$wpdb->postmeta} fn ON fn.post_id = p.ID AND fn.meta_key = '_billing_first_name'
				LEFT JOIN {$wpdb->postmeta} ln ON ln.post_id = p.ID AND ln.meta_key = '_billing_last_name'
				WHERE p.post_type = 'shop_order' AND p.post_status IN ({$statuses}) AND em.meta_value <> '' AND p.ID > %d
				ORDER BY p.ID ASC LIMIT %d",
				$after_order_id,
				$limit
			)
		);
	}
}
