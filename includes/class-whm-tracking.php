<?php
defined( 'ABSPATH' ) || exit;

/**
 * Campaign reporting: click tracking, Google Analytics (UTM) tags, delivery and bounce events
 * from SMTP2GO, and the numbers for the campaign report.
 *
 * No open tracking: open pixels are unreliable (Apple Mail Privacy Protection, image blocking)
 * and the ICO treats them as needing consent under PECR.
 */
class WHM_Tracking {

	/** Days after a click in which an order counts towards the campaign. */
	const ATTRIBUTION_DAYS = 7;

	/** Days after sending in which a bounce or complaint is matched to the campaign. */
	const MATCH_DAYS = 14;

	/** Soft bounces in a row before a contact is marked bounced. */
	const SOFT_BOUNCE_LIMIT = 3;

	public static function init() {
		add_action( 'template_redirect', array( __CLASS__, 'handle_click' ), 0 );
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	public static function events_table() {
		global $wpdb;
		return $wpdb->prefix . 'whm_events';
	}

	/* ------------------------------------------------------------------ Links */

	private static function sign( $campaign_id, $token, $url ) {
		return substr( hash_hmac( 'sha256', (int) $campaign_id . '|' . $token . '|' . $url, wp_salt( 'auth' ) ), 0, 20 );
	}

	private static function b64( $s ) {
		return rtrim( strtr( base64_encode( $s ), '+/', '-_' ), '=' );
	}

	private static function unb64( $s ) {
		return (string) base64_decode( strtr( $s, '-_', '+/' ) );
	}

	/**
	 * Whether a URL should be tracked: http(s) only, never our own unsubscribe or click links.
	 */
	private static function trackable( $url ) {
		if ( ! preg_match( '#^https?://#i', $url ) ) {
			return false;
		}
		$q = (string) wp_parse_url( $url, PHP_URL_QUERY );
		return ! preg_match( '/(^|&)whm=/', $q );
	}

	/**
	 * Add Google Analytics campaign tags to links that point at this site.
	 */
	public static function add_utm( $url, $campaign_id ) {
		if ( ! WHM_Settings::get( 'utm_tags' ) ) {
			return $url;
		}
		$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		$home = strtolower( (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST ) );
		if ( ! $host || preg_replace( '/^www\./', '', $host ) !== preg_replace( '/^www\./', '', $home ) ) {
			return $url;
		}
		if ( false !== stripos( $url, 'utm_source=' ) ) {
			return $url;
		}
		$name = sanitize_title( get_the_title( $campaign_id ) );
		$frag = '';
		if ( false !== strpos( $url, '#' ) ) {
			list( $url, $frag ) = explode( '#', $url, 2 );
			$frag = '#' . $frag;
		}
		$url = add_query_arg(
			array(
				'utm_source'   => 'newsletter',
				'utm_medium'   => 'email',
				'utm_campaign' => $name ? $name : 'campaign-' . (int) $campaign_id,
			),
			$url
		);
		return $url . $frag;
	}

	/**
	 * The tracked version of a link for one recipient.
	 */
	public static function link( $url, $contact, $campaign_id ) {
		if ( ! self::trackable( $url ) ) {
			return $url;
		}
		$url = self::add_utm( $url, $campaign_id );
		if ( ! WHM_Settings::get( 'click_tracking' ) ) {
			return $url;
		}
		return add_query_arg(
			array(
				'whm' => 'go',
				'm'   => (int) $campaign_id,
				't'   => $contact->token,
				'u'   => self::b64( $url ),
				's'   => self::sign( $campaign_id, $contact->token, $url ),
			),
			home_url( '/' )
		);
	}

	/**
	 * Rewrite every link in a campaign email for one recipient. Test sends and previews are left alone.
	 */
	public static function apply_html( $html, $contact, $campaign_id ) {
		if ( ! $campaign_id || ! $contact || empty( $contact->token ) ) {
			return $html;
		}
		return preg_replace_callback(
			'/(<a\b[^>]*?\bhref=")([^"]+)(")/i',
			function ( $m ) use ( $contact, $campaign_id ) {
				$url = html_entity_decode( $m[2], ENT_QUOTES, 'UTF-8' );
				$new = self::link( $url, $contact, $campaign_id );
				return $new === $url ? $m[0] : $m[1] . esc_url( $new ) . $m[3];
			},
			$html
		);
	}

	public static function apply_text( $text, $contact, $campaign_id ) {
		if ( ! $campaign_id || ! $contact || empty( $contact->token ) ) {
			return $text;
		}
		return preg_replace_callback(
			'#https?://[^\s<>()"]+#i',
			function ( $m ) use ( $contact, $campaign_id ) {
				return self::link( $m[0], $contact, $campaign_id );
			},
			$text
		);
	}

	/**
	 * Click endpoint: ?whm=go. Logs the click and forwards to the real link.
	 */
	public static function handle_click() {
		if ( empty( $_GET['whm'] ) || 'go' !== $_GET['whm'] ) { // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}
		nocache_headers();
		header( 'X-Robots-Tag: noindex, nofollow' );
		$campaign = isset( $_GET['m'] ) ? absint( $_GET['m'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification
		$token    = isset( $_GET['t'] ) ? sanitize_text_field( wp_unslash( $_GET['t'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$url      = isset( $_GET['u'] ) ? self::unb64( sanitize_text_field( wp_unslash( $_GET['u'] ) ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$sig      = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification

		if ( ! $url || ! preg_match( '#^https?://#i', $url ) || ! hash_equals( self::sign( $campaign, $token, $url ), $sig ) ) {
			wp_safe_redirect( home_url( '/' ) );
			exit;
		}

		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : 'GET';
		if ( 'GET' === $method ) {
			$contact = WHM_Contacts::get_by_token( $token );
			if ( $contact ) {
				self::log( $campaign, $contact->id, 'click', self::clean_url( $url ) );
			}
		}
		// The link was signed when the email was sent, so it may point to another site.
		wp_redirect( $url, 302, 'Shop Email Campaigns' ); // phpcs:ignore WordPress.Security.SafeRedirect
		exit;
	}

	/**
	 * Link as shown in the report: without the UTM tags.
	 */
	private static function clean_url( $url ) {
		return remove_query_arg( array( 'utm_source', 'utm_medium', 'utm_campaign' ), $url );
	}

	public static function log( $campaign_id, $contact_id, $type, $url = '', $detail = '' ) {
		global $wpdb;
		$wpdb->insert(
			self::events_table(),
			array(
				'campaign_id' => (int) $campaign_id,
				'contact_id'  => (int) $contact_id,
				'type'        => substr( $type, 0, 20 ),
				'url'         => substr( (string) $url, 0, 500 ),
				'detail'      => substr( (string) $detail, 0, 255 ),
				'created_at'  => current_time( 'mysql', true ),
			)
		);
		delete_transient( 'whm_report_' . (int) $campaign_id );
	}

	/* ------------------------------------------------------------------ SMTP2GO webhook */

	public static function webhook_key() {
		$key = get_option( 'whm_webhook_key' );
		if ( ! $key ) {
			$key = wp_generate_password( 32, false );
			update_option( 'whm_webhook_key', $key, false );
		}
		return $key;
	}

	public static function webhook_url() {
		return add_query_arg( 'key', self::webhook_key(), rest_url( 'whm/v1/smtp2go' ) );
	}

	public static function routes() {
		register_rest_route(
			'whm/v1',
			'/smtp2go',
			array(
				'methods'             => array( 'POST', 'GET' ),
				'callback'            => array( __CLASS__, 'webhook' ),
				'permission_callback' => '__return_true', // Checked against the secret key in the callback.
			)
		);
	}

	public static function webhook( WP_REST_Request $request ) {
		$key = (string) $request->get_param( 'key' );
		if ( ! hash_equals( self::webhook_key(), $key ) ) {
			return new WP_REST_Response( array( 'ok' => false ), 403 );
		}
		if ( 'GET' === $request->get_method() ) {
			return new WP_REST_Response( array( 'ok' => true ), 200 ); // Lets SMTP2GO's "test" ping succeed.
		}
		$body = $request->get_json_params();
		if ( ! $body ) {
			$body = $request->get_body_params();
		}
		if ( ! is_array( $body ) ) {
			$body = array();
		}
		// One event per request, or a list of events.
		$events = isset( $body[0] ) && is_array( $body[0] ) ? $body : array( $body );
		$done   = 0;
		foreach ( array_slice( $events, 0, 500 ) as $e ) {
			$done += self::process_event( (array) $e ) ? 1 : 0;
		}
		return new WP_REST_Response(
			array(
				'ok'        => true,
				'processed' => $done,
			),
			200
		);
	}

	private static function field( array $e, array $names ) {
		foreach ( $names as $n ) {
			if ( isset( $e[ $n ] ) && is_scalar( $e[ $n ] ) && '' !== (string) $e[ $n ] ) {
				return trim( (string) $e[ $n ] );
			}
		}
		return '';
	}

	/**
	 * Handle one SMTP2GO event (delivered, bounce, spam, reject, unsubscribe). Returns true if it was used.
	 */
	public static function process_event( array $e ) {
		global $wpdb;
		$type  = str_replace( array( '-', ' ' ), '_', strtolower( self::field( $e, array( 'event', 'event_type', 'type' ) ) ) );
		$email = sanitize_email( self::field( $e, array( 'rcpt', 'recipient', 'email', 'to' ) ) );
		if ( ! $type || ! $email ) {
			return false;
		}
		// One SMTP2GO account can send for several websites. Only act on mail sent from this shop's domain,
		// so another site's bounce or complaint never changes a contact here.
		if ( ! self::from_this_site( self::field( $e, array( 'sender', 'from', 'from_address', 'mail_from' ) ) ) ) {
			return false;
		}
		$contact = WHM_Contacts::get_by_email( $email );
		if ( ! $contact ) {
			return false;
		}
		$reason = sanitize_text_field( self::field( $e, array( 'context', 'reason', 'bounce_reason', 'smtp_response', 'message' ) ) );

		// Match the event to the most recent campaign email this person was sent.
		$q   = WHM_Install::queue_table();
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, campaign_id, delivery FROM {$q} WHERE contact_id = %d AND status = 'sent' AND sent_at >= %s ORDER BY sent_at DESC, id DESC LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL
				$contact->id,
				gmdate( 'Y-m-d H:i:s', time() - self::MATCH_DAYS * DAY_IN_SECONDS )
			)
		);
		$campaign = $row ? (int) $row->campaign_id : 0;
		$set      = function ( $state ) use ( $wpdb, $q, $row, $reason ) {
			if ( $row ) {
				$wpdb->update(
					$q,
					array(
						'delivery'      => $state,
						'delivery_note' => substr( $reason, 0, 255 ),
						'delivery_at'   => current_time( 'mysql', true ),
					),
					array( 'id' => $row->id )
				);
			}
		};

		switch ( $type ) {
			case 'delivered':
				if ( $row && '' === (string) $row->delivery ) {
					$set( 'delivered' );
				}
				break;

			case 'bounce':
			case 'bounced':
			case 'hard_bounce':
			case 'soft_bounce':
				$kind = strtolower( self::field( $e, array( 'bounce', 'bounce_type', 'bounce_class' ) ) );
				$hard = 'soft_bounce' !== $type && ( 'hard_bounce' === $type || '' === $kind || false !== strpos( $kind, 'hard' ) );
				$set( $hard ? 'hard_bounce' : 'soft_bounce' );
				self::log( $campaign, $contact->id, $hard ? 'hard_bounce' : 'soft_bounce', '', $reason );
				if ( $hard ) {
					self::mark_bounced( $contact );
				} elseif ( self::recent_soft_bounces( $contact->id ) >= self::SOFT_BOUNCE_LIMIT ) {
					self::mark_bounced( $contact );
				}
				break;

			case 'spam':
			case 'spam_complaint':
			case 'complaint':
				$set( 'spam' );
				self::log( $campaign, $contact->id, 'spam', '', $reason );
				if ( 'unsubscribed' !== $contact->status ) {
					WHM_Contacts::unsubscribe( $contact->id, $campaign );
				}
				break;

			case 'reject':
			case 'rejected':
				$set( 'rejected' );
				self::log( $campaign, $contact->id, 'rejected', '', $reason );
				break;

			case 'unsubscribe':
			case 'unsubscribed':
				if ( 'unsubscribed' !== $contact->status ) {
					WHM_Contacts::unsubscribe( $contact->id, $campaign );
				}
				break;

			default:
				return false;
		}
		delete_transient( 'whm_report_' . $campaign );
		return true;
	}

	/**
	 * Whether a sender address belongs to this shop: same domain as the From email in Settings (or a
	 * subdomain of it), plus any extra domains from the `whm_webhook_sender_domains` filter.
	 * Events with no sender are ignored too, since they can't be checked.
	 */
	public static function from_this_site( $sender ) {
		if ( preg_match( '/<([^>]+)>/', (string) $sender, $m ) ) {
			$sender = $m[1];
		}
		$sender = strtolower( trim( (string) $sender ) );
		$at     = strrpos( $sender, '@' );
		if ( false === $at ) {
			return false;
		}
		$domain  = substr( $sender, $at + 1 );
		$from    = strtolower( (string) WHM_Settings::get( 'from_email' ) );
		$allowed = array();
		if ( false !== strrpos( $from, '@' ) ) {
			$allowed[] = substr( $from, strrpos( $from, '@' ) + 1 );
		}
		$allowed = array_filter( (array) apply_filters( 'whm_webhook_sender_domains', $allowed ) );
		foreach ( $allowed as $d ) {
			$d = strtolower( ltrim( (string) $d, '@.' ) );
			if ( $domain === $d || str_ends_with( $domain, '.' . $d ) ) {
				return true;
			}
		}
		return false;
	}

	private static function mark_bounced( $contact ) {
		// Someone who unsubscribed stays unsubscribed; either way they won't be emailed.
		if ( ! in_array( $contact->status, array( 'unsubscribed', 'bounced' ), true ) ) {
			WHM_Contacts::set_status( $contact->id, 'bounced' );
		}
	}

	/**
	 * Soft bounces since the contact's last delivered campaign email.
	 */
	private static function recent_soft_bounces( $contact_id ) {
		global $wpdb;
		$q    = WHM_Install::queue_table();
		$last = $wpdb->get_var( $wpdb->prepare( "SELECT MAX(delivery_at) FROM {$q} WHERE contact_id = %d AND delivery = 'delivered'", $contact_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL
		$t    = self::events_table();
		return (int) $wpdb->get_var(
			$last
				? $wpdb->prepare( "SELECT COUNT(*) FROM {$t} WHERE contact_id = %d AND type = 'soft_bounce' AND created_at > %s", $contact_id, $last ) // phpcs:ignore WordPress.DB.PreparedSQL
				: $wpdb->prepare( "SELECT COUNT(*) FROM {$t} WHERE contact_id = %d AND type = 'soft_bounce'", $contact_id ) // phpcs:ignore WordPress.DB.PreparedSQL
		);
	}

	/* ------------------------------------------------------------------ Report */

	/**
	 * Short summary for the Campaigns list.
	 */
	public static function summary( $campaign_id ) {
		global $wpdb;
		$t = self::events_table();
		$q = WHM_Install::queue_table();
		return array(
			'clickers' => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(DISTINCT contact_id) FROM {$t} WHERE campaign_id = %d AND type = 'click'", $campaign_id ) ), // phpcs:ignore WordPress.DB.PreparedSQL
			'bounced'  => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$q} WHERE campaign_id = %d AND delivery IN ('hard_bounce','soft_bounce')", $campaign_id ) ), // phpcs:ignore WordPress.DB.PreparedSQL
		);
	}

	/**
	 * Everything the report page shows. Cached for 10 minutes; any new event clears it.
	 */
	public static function report( $campaign_id ) {
		$campaign_id = (int) $campaign_id;
		$cached      = get_transient( 'whm_report_' . $campaign_id );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		global $wpdb;
		$t = self::events_table();
		$q = WHM_Install::queue_table();
		$c = WHM_Install::contacts_table();

		$r          = WHM_Sender::stats( $campaign_id );
		$r['total'] = (int) get_post_meta( $campaign_id, '_whm_recipients', true );

		$r['delivery'] = array(
			'delivered'   => 0,
			'hard_bounce' => 0,
			'soft_bounce' => 0,
			'spam'        => 0,
			'rejected'    => 0,
		);
		foreach ( $wpdb->get_results( $wpdb->prepare( "SELECT delivery, COUNT(*) AS n FROM {$q} WHERE campaign_id = %d AND delivery <> '' GROUP BY delivery", $campaign_id ) ) as $d ) { // phpcs:ignore WordPress.DB.PreparedSQL
			$r['delivery'][ $d->delivery ] = (int) $d->n;
		}
		// Emails with a later bounce were still accepted first, so count every reported outcome as "known".
		$r['delivery_known'] = array_sum( $r['delivery'] ) > 0;

		$r['clicks']   = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$t} WHERE campaign_id = %d AND type = 'click'", $campaign_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL
		$r['clickers'] = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(DISTINCT contact_id) FROM {$t} WHERE campaign_id = %d AND type = 'click'", $campaign_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL
		$r['links']    = $wpdb->get_results( $wpdb->prepare( "SELECT url, COUNT(*) AS clicks, COUNT(DISTINCT contact_id) AS people FROM {$t} WHERE campaign_id = %d AND type = 'click' GROUP BY url ORDER BY people DESC, clicks DESC LIMIT 50", $campaign_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL

		$r['problems'] = $wpdb->get_results( $wpdb->prepare( "SELECT x.email, x.delivery, x.delivery_note, x.delivery_at, k.status AS contact_status FROM {$q} x LEFT JOIN {$c} k ON k.id = x.contact_id WHERE x.campaign_id = %d AND x.delivery IN ('hard_bounce','soft_bounce','spam','rejected') ORDER BY x.delivery_at DESC LIMIT 200", $campaign_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL
		$r['failed_rows'] = $wpdb->get_results( $wpdb->prepare( "SELECT email, error FROM {$q} WHERE campaign_id = %d AND status = 'failed' ORDER BY id LIMIT 200", $campaign_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL

		// Orders placed by people who clicked, within ATTRIBUTION_DAYS of their first click.
		$r['orders']  = 0;
		$r['revenue'] = 0.0;
		$clickers     = $wpdb->get_results( $wpdb->prepare( "SELECT k.email, MIN(e.created_at) AS first_click FROM {$t} e JOIN {$c} k ON k.id = e.contact_id WHERE e.campaign_id = %d AND e.type = 'click' GROUP BY k.email", $campaign_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL
		$statuses     = array_map( fn( $s ) => preg_replace( '/^wc-/', '', $s ), WHM_Orders::paid_statuses() );
		foreach ( $clickers as $k ) {
			$from   = strtotime( $k->first_click . ' UTC' );
			$orders = wc_get_orders(
				array(
					'billing_email' => $k->email,
					'status'        => $statuses,
					'date_created'  => $from . '...' . ( $from + self::ATTRIBUTION_DAYS * DAY_IN_SECONDS ),
					'limit'         => -1,
					'type'          => 'shop_order',
				)
			);
			foreach ( $orders as $o ) {
				++$r['orders'];
				$r['revenue'] += (float) $o->get_total() - (float) $o->get_total_refunded();
			}
		}

		set_transient( 'whm_report_' . $campaign_id, $r, 10 * MINUTE_IN_SECONDS );
		return $r;
	}

	/**
	 * Remove a contact's tracking history (privacy erasure / contact deleted).
	 */
	public static function forget_contact( $contact_id ) {
		global $wpdb;
		$wpdb->delete( self::events_table(), array( 'contact_id' => absint( $contact_id ) ) );
	}
}
