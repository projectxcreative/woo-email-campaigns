<?php
defined( 'ABSPATH' ) || exit;

/**
 * Segments: saved, combinable filters over contacts + their WooCommerce order history.
 *
 * Definition: { "match": "all"|"any", "rules": [ {type, value?, unit?}, ... ] }
 * Consent is always enforced on top of the rules: only contacts whose status may receive
 * marketing (see WHM_Settings::sendable_statuses) are ever counted or sent to.
 */
class WHM_Segments {

	/**
	 * Rule catalogue used by the admin UI.
	 */
	public static function rule_types() {
		return array(
			'purchased_within'     => array(
				'label' => 'Purchased in the last…',
				'input' => 'period',
			),
			'not_purchased_within' => array(
				'label' => 'Has bought before, but not in the last…',
				'input' => 'period',
			),
			'has_purchased'        => array(
				'label' => 'Has ever purchased (all-time customers)',
				'input' => 'none',
			),
			'never_purchased'      => array(
				'label' => 'Has never purchased (subscribers only)',
				'input' => 'none',
			),
			'order_count_min'      => array(
				'label' => 'Number of orders is at least…',
				'input' => 'number',
			),
			'order_count_max'      => array(
				'label' => 'Number of orders is at most…',
				'input' => 'number',
			),
			'total_spent_min'      => array(
				'label' => 'Total spent is at least…',
				'input' => 'money',
			),
			'first_purchase_within' => array(
				'label' => 'First order was in the last… (new customers)',
				'input' => 'period',
			),
			'bought_product'       => array(
				'label' => 'Has bought product…',
				'input' => 'products',
			),
			'not_bought_product'   => array(
				'label' => 'Has not bought product…',
				'input' => 'products',
			),
			'bought_category'      => array(
				'label' => 'Has bought from category…',
				'input' => 'categories',
			),
			'active_subscription'  => array(
				'label' => 'Has an active subscription (WooCommerce Subscriptions)',
				'input' => 'none',
			),
			'no_active_subscription' => array(
				'label' => 'Does not have an active subscription',
				'input' => 'none',
			),
			'joined_within'        => array(
				'label' => 'Joined the list in the last…',
				'input' => 'period',
			),
			'status_is'            => array(
				'label' => 'Consent status is…',
				'input' => 'status',
			),
		);
	}

	public static function get_definition( $segment_id ) {
		$raw = get_post_meta( $segment_id, '_whm_rules', true );
		$def = json_decode( (string) $raw, true );
		return self::sanitize_definition( is_array( $def ) ? $def : array() );
	}

	public static function sanitize_definition( $def ) {
		$out   = array(
			'match' => ( isset( $def['match'] ) && 'any' === $def['match'] ) ? 'any' : 'all',
			'rules' => array(),
		);
		$types = self::rule_types();
		foreach ( (array) ( $def['rules'] ?? array() ) as $r ) {
			$type = isset( $r['type'] ) ? sanitize_key( $r['type'] ) : '';
			if ( ! isset( $types[ $type ] ) ) {
				continue;
			}
			$rule = array( 'type' => $type );
			switch ( $types[ $type ]['input'] ) {
				case 'period':
					$rule['value'] = max( 1, absint( $r['value'] ?? 3 ) );
					$rule['unit']  = in_array( $r['unit'] ?? '', array( 'days', 'weeks', 'months', 'years' ), true ) ? $r['unit'] : 'months';
					break;
				case 'number':
					$rule['value'] = max( 0, absint( $r['value'] ?? 1 ) );
					break;
				case 'money':
					$rule['value'] = max( 0, round( (float) ( $r['value'] ?? 0 ), 2 ) );
					break;
				case 'products':
				case 'categories':
					$rule['value'] = array_values( array_filter( array_map( 'absint', (array) ( $r['value'] ?? array() ) ) ) );
					break;
				case 'status':
					$rule['value'] = in_array( $r['value'] ?? '', WHM_Contacts::STATUSES, true ) ? $r['value'] : 'subscribed';
					break;
			}
			$out['rules'][] = $rule;
		}
		return $out;
	}

	private static function since( $value, $unit ) {
		$ts = strtotime( '-' . absint( $value ) . ' ' . $unit, time() );
		return gmdate( 'Y-m-d H:i:s', $ts );
	}

	/**
	 * SQL condition for one rule, referencing alias c (contacts) and s (stats).
	 */
	private static function rule_sql( array $r ) {
		global $wpdb;
		switch ( $r['type'] ) {
			case 'purchased_within':
				return $wpdb->prepare( 'c.last_order >= %s', self::since( $r['value'], $r['unit'] ) );
			case 'not_purchased_within':
				return $wpdb->prepare( '(c.orders > 0 AND c.last_order < %s)', self::since( $r['value'], $r['unit'] ) );
			case 'first_purchase_within':
				return $wpdb->prepare( 'c.first_order >= %s', self::since( $r['value'], $r['unit'] ) );
			case 'has_purchased':
				return 'c.orders > 0';
			case 'never_purchased':
				return 'c.orders = 0';
			case 'order_count_min':
				return $wpdb->prepare( 'c.orders >= %d', $r['value'] );
			case 'order_count_max':
				return $wpdb->prepare( 'c.orders <= %d', $r['value'] );
			case 'total_spent_min':
				return $wpdb->prepare( 'c.spent >= %f', $r['value'] );
			case 'bought_product':
				return 'c.email IN (' . WHM_Orders::emails_bought_products_sql( self::expand_products( $r['value'] ) ) . ')';
			case 'not_bought_product':
				return 'c.email NOT IN (' . WHM_Orders::emails_bought_products_sql( self::expand_products( $r['value'] ) ) . ')';
			case 'bought_category':
				return 'c.email IN (' . WHM_Orders::emails_bought_products_sql( WHM_Orders::product_ids_in_categories( $r['value'] ) ) . ')';
			case 'active_subscription':
				return 'c.email IN (' . WHM_Orders::emails_active_subscription_sql() . ')';
			case 'no_active_subscription':
				return 'c.email NOT IN (' . WHM_Orders::emails_active_subscription_sql() . ')';
			case 'joined_within':
				return $wpdb->prepare( 'c.created_at >= %s', self::since( $r['value'], $r['unit'] ) );
			case 'status_is':
				return $wpdb->prepare( 'c.status = %s', $r['value'] );
		}
		return '1=1';
	}

	/**
	 * Include variations of any variable product chosen.
	 */
	private static function expand_products( array $ids ) {
		$out = array();
		foreach ( $ids as $id ) {
			$out[] = (int) $id;
			$kids  = get_children(
				array(
					'post_parent' => (int) $id,
					'post_type'   => 'product_variation',
					'fields'      => 'ids',
					'post_status' => 'any',
				)
			);
			$out   = array_merge( $out, array_map( 'intval', (array) $kids ) );
		}
		return array_unique( $out );
	}

	/**
	 * Derived table: every contact plus their purchase stats. Live WooCommerce orders are combined with
	 * history imported from a CSV (e.g. customers from a previous website): counts and spend are added,
	 * the latest/earliest dates win. Columns: all contact columns + orders, spent, last_order, first_order.
	 */
	public static function contacts_with_stats_sql() {
		$c     = WHM_Install::contacts_table();
		$stats = WHM_Orders::stats_sql();
		return "SELECT cc.*,
			(COALESCE(s.orders,0) + cc.imported_orders) AS orders,
			(COALESCE(s.spent,0) + cc.imported_spent) AS spent,
			NULLIF(GREATEST(COALESCE(s.last_order,'1000-01-01 00:00:00'), COALESCE(cc.imported_last_order,'1000-01-01 00:00:00')),'1000-01-01 00:00:00') AS last_order,
			NULLIF(LEAST(COALESCE(s.first_order,'9999-12-31 00:00:00'), COALESCE(cc.imported_first_order,'9999-12-31 00:00:00')),'9999-12-31 00:00:00') AS first_order
			FROM {$c} cc LEFT JOIN ({$stats}) s ON s.email = cc.email";
	}

	/**
	 * FROM … WHERE … for a definition, with consent enforced.
	 */
	public static function base_sql( array $def, $enforce_consent = true ) {
		global $wpdb;
		$def   = self::sanitize_definition( $def );
		$conds = array_map( array( __CLASS__, 'rule_sql' ), $def['rules'] );
		$rules = $conds ? '(' . implode( 'all' === $def['match'] ? ' AND ' : ' OR ', $conds ) . ')' : '1=1';

		$consent = '1=1';
		if ( $enforce_consent ) {
			$statuses = WHM_Settings::sendable_statuses();
			$consent  = 'c.status IN (' . implode( ',', array_map( fn( $s ) => $wpdb->prepare( '%s', $s ), $statuses ) ) . ')';
		}
		return 'FROM (' . self::contacts_with_stats_sql() . ") c WHERE {$consent} AND {$rules}";
	}

	public static function count( array $def ) {
		global $wpdb;
		return (int) $wpdb->get_var( 'SELECT COUNT(*) ' . self::base_sql( $def ) );
	}

	/**
	 * Count matching contacts ignoring consent, to show how many are held back.
	 */
	public static function count_all( array $def ) {
		global $wpdb;
		return (int) $wpdb->get_var( 'SELECT COUNT(*) ' . self::base_sql( $def, false ) );
	}

	public static function preview( array $def, $limit = 25 ) {
		global $wpdb;
		return $wpdb->get_results(
			'SELECT c.id, c.email, c.first_name, c.last_name, c.status, c.orders, c.last_order, c.spent '
			. self::base_sql( $def ) . ' ORDER BY c.last_order IS NULL, c.last_order DESC LIMIT ' . absint( $limit )
		);
	}

	/**
	 * SELECT used to fill a campaign's send queue.
	 */
	public static function queue_select_sql( array $def, $campaign_id ) {
		return 'SELECT ' . absint( $campaign_id ) . ', c.id, c.email, \'pending\' ' . self::base_sql( $def );
	}

	public static function describe( array $def ) {
		$types = self::rule_types();
		$parts = array();
		foreach ( $def['rules'] as $r ) {
			$label = rtrim( $types[ $r['type'] ]['label'], '…' );
			$label = preg_replace( '/\s*\((all-time customers|subscribers only|new customers|WooCommerce Subscriptions)\)$/', '', $label );
			switch ( $types[ $r['type'] ]['input'] ) {
				case 'period':
					$label .= ' ' . $r['value'] . ' ' . ( 1 === $r['value'] ? rtrim( $r['unit'], 's' ) : $r['unit'] );
					break;
				case 'number':
					$label .= ' ' . $r['value'];
					break;
				case 'money':
					$label .= ' ' . html_entity_decode( get_woocommerce_currency_symbol(), ENT_QUOTES, 'UTF-8' ) . number_format( $r['value'], 2 );
					break;
				case 'products':
					$label .= ' ' . implode( ', ', array_map( fn( $id ) => get_the_title( $id ), $r['value'] ) );
					break;
				case 'categories':
					$names = array();
					foreach ( $r['value'] as $t ) {
						$term = get_term( $t, 'product_cat' );
						if ( $term && ! is_wp_error( $term ) ) {
							$names[] = $term->name;
						}
					}
					$label .= ' ' . implode( ', ', $names );
					break;
				case 'status':
					$label .= ' ' . $r['value'];
					break;
			}
			$parts[] = $label;
		}
		if ( ! $parts ) {
			return 'Everyone who can be emailed';
		}
		return implode( 'all' === $def['match'] ? ' AND ' : ' OR ', $parts );
	}
}
