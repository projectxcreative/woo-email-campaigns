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
		$stats = WHM_Orders::stats_sql();

		$count_sql = "SELECT COUNT(*) FROM {$c} c WHERE {$where}";
		$total     = (int) ( $params ? $wpdb->get_var( $wpdb->prepare( $count_sql, $params ) ) : $wpdb->get_var( $count_sql ) );

		$sql   = "SELECT c.*, COALESCE(s.orders,0) AS orders, s.last_order, COALESCE(s.spent,0) AS spent
			FROM {$c} c LEFT JOIN ({$stats}) s ON s.email = c.email
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
	 * Import a CSV. Columns are matched by header name: email (required), first_name, last_name.
	 * $mode: 'subscribed' (they opted in) or 'customer' (existing customers, soft opt-in).
	 * People who unsubscribed are never changed by an import.
	 */
	public static function import_csv( $path, $mode, $note ) {
		$result = array(
			'added'   => 0,
			'updated' => 0,
			'skipped' => 0,
			'invalid' => 0,
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
		$map = array();
		foreach ( $header as $i => $h ) {
			$h = strtolower( trim( preg_replace( '/^\xEF\xBB\xBF/', '', (string) $h ) ) );
			$h = str_replace( array( ' ', '-' ), '_', $h );
			if ( in_array( $h, array( 'email', 'email_address', 'e_mail' ), true ) ) {
				$map['email'] = $i;
			} elseif ( in_array( $h, array( 'first_name', 'firstname', 'first', 'forename' ), true ) ) {
				$map['first_name'] = $i;
			} elseif ( in_array( $h, array( 'last_name', 'lastname', 'last', 'surname' ), true ) ) {
				$map['last_name'] = $i;
			} elseif ( 'name' === $h && ! isset( $map['first_name'] ) ) {
				$map['name'] = $i;
			}
		}
		if ( ! isset( $map['email'] ) ) {
			fclose( $fh );
			return new WP_Error( 'whm_csv', 'No "email" column found in the first row.' );
		}
		$mode = 'subscribed' === $mode ? 'subscribed' : 'customer';
		while ( ( $row = fgetcsv( $fh, 0, ',', '"', '' ) ) !== false ) {
			$email = strtolower( trim( (string) ( $row[ $map['email'] ] ?? '' ) ) );
			if ( ! is_email( $email ) ) {
				++$result['invalid'];
				continue;
			}
			$first = isset( $map['first_name'] ) ? (string) ( $row[ $map['first_name'] ] ?? '' ) : '';
			$last  = isset( $map['last_name'] ) ? (string) ( $row[ $map['last_name'] ] ?? '' ) : '';
			if ( isset( $map['name'] ) ) {
				$parts = preg_split( '/\s+/', trim( (string) ( $row[ $map['name'] ] ?? '' ) ), 2 );
				$first = $parts[0] ?? '';
				$last  = $parts[1] ?? '';
			}
			$existing = self::get_by_email( $email );
			$data     = array(
				'first_name'   => $first,
				'last_name'    => $last,
				'source'       => 'import',
				'status'       => $mode,
				'consent_text' => $note,
				'consent_ip'   => '',
			);
			if ( ! $existing ) {
				self::ensure( $email, $data );
				++$result['added'];
				continue;
			}
			if ( in_array( $existing->status, array( 'unsubscribed', 'bounced' ), true ) ) {
				++$result['skipped'];
				continue;
			}
			self::ensure( $email, $data );
			if ( 'subscribed' === $mode && 'subscribed' !== $existing->status ) {
				self::subscribe( $email, $data );
			}
			++$result['updated'];
		}
		fclose( $fh );
		return $result;
	}
}
