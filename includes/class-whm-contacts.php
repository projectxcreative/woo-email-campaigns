<?php
defined( 'ABSPATH' ) || exit;

/**
 * Contact list: everyone we hold an email for, with their consent status.
 *
 * Statuses:
 *  subscribed   – actively opted in (checkout tick, account toggle, import of opted-in list, resubscribe).
 *  customer     – bought from us but never opted in or out. Only emailed when soft opt-in is enabled.
 *  unsubscribed – opted out. Never emailed again unless they opt back in themselves.
 *  bounced      – address marked as bad by the admin.
 */
class WHM_Contacts {

	const STATUSES = array( 'subscribed', 'customer', 'unsubscribed', 'bounced' );

	public static function init() {
		add_action( 'whm_sync_customers', array( __CLASS__, 'sync_customers' ) );
		add_action( 'woocommerce_order_status_processing', array( __CLASS__, 'on_order_paid' ) );
		add_action( 'woocommerce_order_status_completed', array( __CLASS__, 'on_order_paid' ) );
	}

	public static function now() {
		return current_time( 'mysql', true );
	}

	public static function get( $id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . WHM_Install::contacts_table() . ' WHERE id = %d', $id ) );
	}

	public static function get_by_email( $email ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . WHM_Install::contacts_table() . ' WHERE email = %s', strtolower( trim( $email ) ) ) );
	}

	public static function get_by_token( $token ) {
		global $wpdb;
		if ( ! preg_match( '/^[a-zA-Z0-9]{32}$/', (string) $token ) ) {
			return null;
		}
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . WHM_Install::contacts_table() . ' WHERE token = %s', $token ) );
	}

	/**
	 * Insert a contact if the email is new. Returns the contact id.
	 */
	public static function ensure( $email, array $data = array() ) {
		global $wpdb;
		$email = strtolower( trim( (string) $email ) );
		if ( ! is_email( $email ) ) {
			return 0;
		}
		$existing = self::get_by_email( $email );
		if ( $existing ) {
			$update = array();
			foreach ( array( 'first_name', 'last_name' ) as $k ) {
				if ( '' === $existing->$k && ! empty( $data[ $k ] ) ) {
					$update[ $k ] = mb_substr( sanitize_text_field( $data[ $k ] ), 0, 100 );
				}
			}
			if ( ! (int) $existing->user_id && ! empty( $data['user_id'] ) ) {
				$update['user_id'] = absint( $data['user_id'] );
			}
			if ( $update ) {
				$update['updated_at'] = self::now();
				$wpdb->update( WHM_Install::contacts_table(), $update, array( 'id' => $existing->id ) );
			}
			return (int) $existing->id;
		}
		$status = isset( $data['status'] ) && in_array( $data['status'], self::STATUSES, true ) ? $data['status'] : 'customer';
		$row    = array(
			'email'      => $email,
			'first_name' => mb_substr( sanitize_text_field( $data['first_name'] ?? '' ), 0, 100 ),
			'last_name'  => mb_substr( sanitize_text_field( $data['last_name'] ?? '' ), 0, 100 ),
			'user_id'    => absint( $data['user_id'] ?? 0 ),
			'status'     => $status,
			'source'     => sanitize_key( $data['source'] ?? 'order' ),
			'token'      => wp_generate_password( 32, false, false ),
			'created_at' => self::now(),
			'updated_at' => self::now(),
		);
		if ( 'subscribed' === $status ) {
			$row['consent_text'] = $data['consent_text'] ?? '';
			$row['consent_at']   = self::now();
			$row['consent_ip']   = $data['consent_ip'] ?? '';
		}
		$ok = $wpdb->insert( WHM_Install::contacts_table(), $row );
		if ( ! $ok ) {
			// Lost a race with another insert: fetch the winner.
			$again = self::get_by_email( $email );
			return $again ? (int) $again->id : 0;
		}
		return (int) $wpdb->insert_id;
	}

	/**
	 * Record an explicit opt-in. This is the person's own action, so it also re-subscribes someone who left before.
	 */
	public static function subscribe( $email, array $data = array() ) {
		global $wpdb;
		$id = self::ensure( $email, $data );
		if ( ! $id ) {
			return 0;
		}
		$wpdb->update(
			WHM_Install::contacts_table(),
			array(
				'status'          => 'subscribed',
				'source'          => sanitize_key( $data['source'] ?? 'form' ),
				'consent_text'    => (string) ( $data['consent_text'] ?? '' ),
				'consent_at'      => self::now(),
				'consent_ip'      => (string) ( $data['consent_ip'] ?? '' ),
				'unsubscribed_at' => null,
				'updated_at'      => self::now(),
			),
			array( 'id' => $id )
		);
		return $id;
	}

	/**
	 * Keep evidence for the soft opt-in: the buyer was offered an opt-out and didn't take it.
	 * Status stays "customer"; only the record text and time are stored.
	 */
	public static function note_offered_opt_out( $id, $text ) {
		global $wpdb;
		return $wpdb->update(
			WHM_Install::contacts_table(),
			array(
				'consent_text' => (string) $text,
				'consent_at'   => self::now(),
				'consent_ip'   => self::ip(),
				'updated_at'   => self::now(),
			),
			array(
				'id'     => absint( $id ),
				'status' => 'customer',
			)
		);
	}

	public static function unsubscribe( $id, $campaign_id = 0 ) {
		global $wpdb;
		return $wpdb->update(
			WHM_Install::contacts_table(),
			array(
				'status'               => 'unsubscribed',
				'unsubscribed_at'      => self::now(),
				'unsubscribe_campaign' => absint( $campaign_id ),
				'updated_at'           => self::now(),
			),
			array( 'id' => absint( $id ) )
		);
	}

	public static function set_status( $id, $status ) {
		global $wpdb;
		if ( ! in_array( $status, self::STATUSES, true ) ) {
			return false;
		}
		if ( 'unsubscribed' === $status ) {
			return self::unsubscribe( $id );
		}
		return $wpdb->update(
			WHM_Install::contacts_table(),
			array(
				'status'     => $status,
				'updated_at' => self::now(),
			),
			array( 'id' => absint( $id ) )
		);
	}

	public static function delete( $id ) {
		global $wpdb;
		$wpdb->delete( WHM_Install::queue_table(), array( 'contact_id' => absint( $id ) ) );
		WHM_Tracking::forget_contact( $id );
		return $wpdb->delete( WHM_Install::contacts_table(), array( 'id' => absint( $id ) ) );
	}

	public static function ip() {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		return filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '';
	}

	/**
	 * When an order is paid, make sure the buyer is on the contact list (as a customer, never auto-subscribed).
	 */
	public static function on_order_paid( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}
		self::ensure(
			$order->get_billing_email(),
			array(
				'first_name' => $order->get_billing_first_name(),
				'last_name'  => $order->get_billing_last_name(),
				'user_id'    => $order->get_customer_id(),
				'source'     => 'order',
			)
		);
	}

	/**
	 * Backfill contacts from all paid orders, in chunks via Action Scheduler.
	 */
	public static function sync_customers( $after = 0 ) {
		$rows = WHM_Orders::customers_page( absint( $after ), 300 );
		$last = absint( $after );
		foreach ( $rows as $r ) {
			self::ensure(
				$r->email,
				array(
					'first_name' => (string) $r->first_name,
					'last_name'  => (string) $r->last_name,
					'user_id'    => (int) $r->user_id,
					'source'     => 'order',
				)
			);
			$last = (int) $r->order_id;
		}
		if ( count( $rows ) === 300 && function_exists( 'as_enqueue_async_action' ) ) {
			as_enqueue_async_action( 'whm_sync_customers', array( $last ), 'whm' );
		} else {
			update_option( 'whm_last_sync', time() );
		}
		return count( $rows );
	}

	public static function counts() {
		global $wpdb;
		$rows = $wpdb->get_results( 'SELECT status, COUNT(*) AS c FROM ' . WHM_Install::contacts_table() . ' GROUP BY status' );
		$out  = array_fill_keys( self::STATUSES, 0 );
		foreach ( $rows as $r ) {
			$out[ $r->status ] = (int) $r->c;
		}
		$out['all'] = array_sum( $out );
		return $out;
	}

	/**
	 * Paged contact list with order stats.
	 */
	public static function query( array $args ) {
		global $wpdb;
		$c      = WHM_Install::contacts_table();
		$where  = array( '1=1' );
		$params = array();
		if ( ! empty( $args['status'] ) && in_array( $args['status'], self::STATUSES, true ) ) {
			$where[]  = 'c.status = %s';
			$params[] = $args['status'];
		}
		if ( ! empty( $args['search'] ) ) {
			$like     = '%' . $wpdb->esc_like( $args['search'] ) . '%';
			$where[]  = '(c.email LIKE %s OR c.first_name LIKE %s OR c.last_name LIKE %s)';
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
		}
		$per   = max( 1, min( 200, (int) ( $args['per_page'] ?? 50 ) ) );
		$page  = max( 1, (int) ( $args['page'] ?? 1 ) );
		$where = implode( ' AND ', $where );

		$count_sql = "SELECT COUNT(*) FROM {$c} c WHERE {$where}";
		$total     = (int) ( $params ? $wpdb->get_var( $wpdb->prepare( $count_sql, $params ) ) : $wpdb->get_var( $count_sql ) );

		$sql   = 'SELECT c.* FROM (' . WHM_Segments::contacts_with_stats_sql() . ") c
			WHERE {$where} ORDER BY c.created_at DESC, c.id DESC LIMIT %d OFFSET %d";
		$all   = array_merge( $params, array( $per, ( $page - 1 ) * $per ) );
		$items = $wpdb->get_results( $wpdb->prepare( $sql, $all ) );

		return array(
			'items' => $items,
			'total' => $total,
			'pages' => (int) ceil( $total / $per ),
		);
	}

	/**
	 * Import a CSV. Columns are matched by header name: email (required), first_name, last_name or name.
	 * Optional purchase history columns (e.g. a WooCommerce "Customers" export from another site):
	 * orders, total spend, last active / last order, first order. These feed the segment filters.
	 * Rows with the same email are combined.
	 *
	 * $mode: 'subscribed' (they opted in) or 'customer' (existing customers, soft opt-in).
	 * People who unsubscribed are never re-subscribed by an import.
	 */
	public static function import_csv( $path, $mode, $note ) {
		global $wpdb;
		$result = array(
			'added'   => 0,
			'updated' => 0,
			'skipped' => 0,
			'invalid' => 0,
			'history' => 0,
			'no_buy'  => 0,
		);
		$fh     = fopen( $path, 'r' );
		if ( ! $fh ) {
			return new WP_Error( 'whm_csv', 'Could not read the uploaded file.' );
		}
		$header = fgetcsv( $fh, 0, ',', '"', '' );
		if ( ! $header ) {
			fclose( $fh );
			return new WP_Error( 'whm_csv', 'The file is empty.' );
		}
		$aliases = array(
			'email'       => array( 'email', 'email_address', 'e_mail' ),
			'first_name'  => array( 'first_name', 'firstname', 'first', 'forename' ),
			'last_name'   => array( 'last_name', 'lastname', 'last', 'surname' ),
			'name'        => array( 'name', 'full_name' ),
			'orders'      => array( 'orders', 'order_count', 'number_of_orders', 'total_orders' ),
			'spent'       => array( 'total_spend', 'total_spent', 'spend', 'money_spent', 'lifetime_value' ),
			'last_order'  => array( 'last_order', 'last_order_date', 'date_last_order', 'last_active', 'last_purchase' ),
			'first_order' => array( 'first_order', 'first_order_date', 'date_first_order', 'first_purchase' ),
		);
		$map     = array();
		foreach ( $header as $i => $h ) {
			$h = strtolower( trim( preg_replace( '/^\xEF\xBB\xBF/', '', (string) $h ) ) );
			$h = trim( preg_replace( '/[^a-z0-9]+/', '_', $h ), '_' );
			foreach ( $aliases as $field => $names ) {
				if ( ! isset( $map[ $field ] ) && in_array( $h, $names, true ) ) {
					$map[ $field ] = $i;
				}
			}
		}
		if ( ! isset( $map['email'] ) ) {
			fclose( $fh );
			return new WP_Error( 'whm_csv', 'No "email" column found in the first row.' );
		}
		$has_history = isset( $map['orders'] ) || isset( $map['spent'] ) || isset( $map['last_order'] );
		$col         = function ( $row, $field ) use ( $map ) {
			return isset( $map[ $field ] ) ? trim( (string) ( $row[ $map[ $field ] ] ?? '' ) ) : '';
		};
		$date        = function ( $v ) {
			$ts = $v ? strtotime( $v ) : false;
			return $ts ? gmdate( 'Y-m-d H:i:s', $ts ) : null;
		};

		// First pass: combine rows per email (a customer can appear as both a guest and an account).
		$people = array();
		while ( ( $row = fgetcsv( $fh, 0, ',', '"', '' ) ) !== false ) {
			$email = strtolower( $col( $row, 'email' ) );
			if ( ! is_email( $email ) ) {
				++$result['invalid'];
				continue;
			}
			$first = $col( $row, 'first_name' );
			$last  = $col( $row, 'last_name' );
			if ( '' === $first && '' !== $col( $row, 'name' ) ) {
				$parts = preg_split( '/\s+/', $col( $row, 'name' ), 2 );
				$first = $parts[0] ?? '';
				$last  = $parts[1] ?? '';
			}
			$p = $people[ $email ] ?? array(
				'first_name'  => '',
				'last_name'   => '',
				'orders'      => 0,
				'spent'       => 0.0,
				'last_order'  => null,
				'first_order' => null,
			);
			if ( '' === $p['first_name'] && '' !== $first ) {
				$p['first_name'] = $first;
				$p['last_name']  = $last;
			}
			$orders       = (int) preg_replace( '/[^0-9]/', '', $col( $row, 'orders' ) );
			$p['orders'] += $orders;
			$p['spent']  += (float) preg_replace( '/[^0-9.\-]/', '', $col( $row, 'spent' ) );
			if ( $orders > 0 || ! isset( $map['orders'] ) ) {
				$lo = $date( $col( $row, 'last_order' ) );
				if ( $lo && ( ! $p['last_order'] || $lo > $p['last_order'] ) ) {
					$p['last_order'] = $lo;
				}
				$fo = $date( $col( $row, 'first_order' ) );
				if ( $fo && ( ! $p['first_order'] || $fo < $p['first_order'] ) ) {
					$p['first_order'] = $fo;
				}
			}
			$people[ $email ] = $p;
		}
		fclose( $fh );

		$mode = 'subscribed' === $mode ? 'subscribed' : 'customer';
		foreach ( $people as $email => $p ) {
			$existing = self::get_by_email( $email );
			// Soft opt-in only covers people who actually bought. Account holders with no orders are left out.
			if ( 'customer' === $mode && isset( $map['orders'] ) && $p['orders'] < 1 && ! $existing ) {
				++$result['no_buy'];
				continue;
			}
			$data     = array(
				'first_name'   => $p['first_name'],
				'last_name'    => $p['last_name'],
				'source'       => 'import',
				'status'       => $mode,
				'consent_text' => $note,
				'consent_ip'   => '',
			);
			if ( ! $existing ) {
				$id = self::ensure( $email, $data );
				++$result['added'];
			} else {
				$id = (int) $existing->id;
				self::ensure( $email, $data );
				if ( in_array( $existing->status, array( 'unsubscribed', 'bounced' ), true ) ) {
					++$result['skipped'];
				} else {
					if ( 'subscribed' === $mode && 'subscribed' !== $existing->status ) {
						self::subscribe( $email, $data );
					}
					++$result['updated'];
				}
			}
			// Purchase history is stored even for people who unsubscribed, so reports stay accurate.
			if ( $id && $has_history ) {
				$wpdb->update(
					WHM_Install::contacts_table(),
					array(
						'imported_orders'      => (int) $p['orders'],
						'imported_spent'       => round( (float) $p['spent'], 4 ),
						'imported_last_order'  => $p['orders'] > 0 || ! isset( $map['orders'] ) ? $p['last_order'] : null,
						'imported_first_order' => $p['first_order'],
						'updated_at'           => self::now(),
					),
					array( 'id' => $id )
				);
				if ( $p['orders'] > 0 ) {
					++$result['history'];
				}
			}
		}
		return $result;
	}
}
