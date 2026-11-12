<?php
defined( 'ABSPATH' ) || exit;

/**
 * Campaign sending: fills a queue from the segment, then sends in batches with Action Scheduler.
 *
 * Campaign statuses: draft, scheduled, sending, paused, sent, cancelled.
 */
class WHM_Sender {

	private static $last_error = '';

	public static function init() {
		add_action( 'whm_start_campaign', array( __CLASS__, 'start_now' ) );
		add_action( 'whm_process_batch', array( __CLASS__, 'process_batch' ) );
		add_action( 'wp_mail_failed', array( __CLASS__, 'capture_error' ) );
	}

	public static function capture_error( $wp_error ) {
		if ( is_wp_error( $wp_error ) ) {
			self::$last_error = mb_substr( $wp_error->get_error_message(), 0, 250 );
		}
	}

	public static function status( $campaign_id ) {
		$s = get_post_meta( $campaign_id, '_whm_status', true );
		return $s ? $s : 'draft';
	}

	public static function campaign_data( $campaign_id ) {
		$blocks = json_decode( (string) get_post_meta( $campaign_id, '_whm_blocks', true ), true );
		return array(
			'subject'   => (string) get_post_meta( $campaign_id, '_whm_subject', true ),
			'preheader' => (string) get_post_meta( $campaign_id, '_whm_preheader', true ),
			'blocks'    => WHM_Renderer::sanitize_blocks( is_array( $blocks ) ? $blocks : array() ),
			'segment'   => (int) get_post_meta( $campaign_id, '_whm_segment', true ),
		);
	}

	/**
	 * Check a campaign can be sent. Returns true or WP_Error.
	 */
	public static function can_send( $campaign_id ) {
		if ( ! WHM_Settings::get( 'live_sending' ) ) {
			return new WP_Error( 'whm_off', 'Live sending is switched off in Email Marketing → Settings. Test emails still work.' );
		}
		$data = self::campaign_data( $campaign_id );
		if ( '' === trim( $data['subject'] ) ) {
			return new WP_Error( 'whm_subject', 'Add a subject line first.' );
		}
		if ( ! $data['blocks'] ) {
			return new WP_Error( 'whm_blocks', 'The email has no content yet.' );
		}
		if ( ! $data['segment'] || 'whm_segment' !== get_post_type( $data['segment'] ) ) {
			return new WP_Error( 'whm_segment', 'Choose who to send to.' );
		}
		if ( ! in_array( self::status( $campaign_id ), array( 'draft', 'scheduled' ), true ) ) {
			return new WP_Error( 'whm_state', 'This campaign has already been sent or is sending.' );
		}
		return true;
	}

	/**
	 * Send now, or schedule for a GMT timestamp.
	 */
	public static function launch( $campaign_id, $when_ts = 0 ) {
		$ok = self::can_send( $campaign_id );
		if ( is_wp_error( $ok ) ) {
			return $ok;
		}
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( 'whm_start_campaign', array( (int) $campaign_id ), 'whm' );
		}
		if ( $when_ts > time() + 60 ) {
			update_post_meta( $campaign_id, '_whm_status', 'scheduled' );
			update_post_meta( $campaign_id, '_whm_send_at', (int) $when_ts );
			as_schedule_single_action( (int) $when_ts, 'whm_start_campaign', array( (int) $campaign_id ), 'whm' );
			return 'scheduled';
		}
		return self::start_now( $campaign_id );
	}

	/**
	 * Freeze the audience into the queue and kick off the first batch.
	 */
	public static function start_now( $campaign_id ) {
		global $wpdb;
		$campaign_id = (int) $campaign_id;
		$ok          = self::can_send( $campaign_id );
		if ( is_wp_error( $ok ) ) {
			update_post_meta( $campaign_id, '_whm_last_error', $ok->get_error_message() );
			if ( 'scheduled' === self::status( $campaign_id ) ) {
				update_post_meta( $campaign_id, '_whm_status', 'draft' );
			}
			return $ok;
		}
		$data = self::campaign_data( $campaign_id );
		$def  = WHM_Segments::get_definition( $data['segment'] );
		$q    = WHM_Install::queue_table();

		// Lock the status first so a double click can't start two sends.
		update_post_meta( $campaign_id, '_whm_status', 'sending' );
		$wpdb->query( "INSERT IGNORE INTO {$q} (campaign_id, contact_id, email, status) " . WHM_Segments::queue_select_sql( $def, $campaign_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL

		$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$q} WHERE campaign_id = %d", $campaign_id ) );
		update_post_meta( $campaign_id, '_whm_recipients', $total );
		update_post_meta( $campaign_id, '_whm_started', time() );
		update_post_meta( $campaign_id, '_whm_segment_label', get_the_title( $data['segment'] ) );

		if ( ! $total ) {
			update_post_meta( $campaign_id, '_whm_status', 'sent' );
			update_post_meta( $campaign_id, '_whm_finished', time() );
			return 'sent';
		}
		as_enqueue_async_action( 'whm_process_batch', array( $campaign_id ), 'whm' );
		return 'sending';
	}

	public static function process_batch( $campaign_id ) {
		global $wpdb;
		$campaign_id = (int) $campaign_id;
		if ( 'sending' !== self::status( $campaign_id ) ) {
			return;
		}
		$lock = 'whm_lock_' . $campaign_id;
		if ( get_transient( $lock ) ) {
			// Another batch is running; try again shortly.
			as_schedule_single_action( time() + 30, 'whm_process_batch', array( $campaign_id ), 'whm' );
			return;
		}
		set_transient( $lock, 1, 5 * MINUTE_IN_SECONDS );

		$q     = WHM_Install::queue_table();
		$size  = (int) WHM_Settings::get( 'batch_size' );
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$q} WHERE campaign_id = %d AND status = 'pending' ORDER BY id ASC LIMIT %d", $campaign_id, $size ) );
		$data  = self::campaign_data( $campaign_id );
		$allow = WHM_Settings::sendable_statuses();
		$start = time();

		foreach ( $rows as $row ) {
			if ( 'sending' !== self::status( $campaign_id ) ) {
				break; // Paused or cancelled mid-batch.
			}
			wp_cache_delete( $campaign_id, 'post_meta' );
			$contact = WHM_Contacts::get( $row->contact_id );
			if ( ! $contact || ! in_array( $contact->status, $allow, true ) ) {
				$wpdb->update( $q, array( 'status' => 'skipped', 'error' => 'No longer subscribed' ), array( 'id' => $row->id ) );
				continue;
			}
			$sent = self::send_one( $data, $contact, $campaign_id );
			if ( $sent ) {
				$wpdb->update(
					$q,
					array(
						'status'  => 'sent',
						'sent_at' => current_time( 'mysql', true ),
					),
					array( 'id' => $row->id )
				);
			} else {
				$attempts = (int) $row->attempts + 1;
				$wpdb->update(
					$q,
					array(
						'status'   => $attempts >= 3 ? 'failed' : 'pending',
						'attempts' => $attempts,
						'error'    => self::$last_error ? self::$last_error : 'wp_mail returned false',
					),
					array( 'id' => $row->id )
				);
			}
			// Stay well inside PHP's time limit; the rest goes in the next batch.
			if ( time() - $start > 20 ) {
				break;
			}
		}

		delete_transient( $lock );

		$pending = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$q} WHERE campaign_id = %d AND status = 'pending'", $campaign_id ) );
		if ( $pending && 'sending' === self::status( $campaign_id ) ) {
			as_schedule_single_action( time() + (int) WHM_Settings::get( 'batch_interval' ), 'whm_process_batch', array( $campaign_id ), 'whm' );
		} elseif ( ! $pending && 'sending' === self::status( $campaign_id ) ) {
			update_post_meta( $campaign_id, '_whm_status', 'sent' );
			update_post_meta( $campaign_id, '_whm_finished', time() );
		}
	}

	public static function headers( $contact, $campaign_id ) {
		$s       = WHM_Settings::all();
		$headers = array( 'Content-Type: text/html; charset=UTF-8' );
		if ( $s['from_email'] ) {
			$headers[] = 'From: ' . self::encode_name( $s['from_name'] ) . ' <' . $s['from_email'] . '>';
		}
		if ( $s['reply_to'] ) {
			$headers[] = 'Reply-To: ' . $s['reply_to'];
		}
		if ( $contact && ! empty( $contact->token ) ) {
			$headers[] = 'List-Unsubscribe: <' . WHM_Public::unsubscribe_url( $contact, $campaign_id ) . '>';
			$headers[] = 'List-Unsubscribe-Post: List-Unsubscribe=One-Click';
		}
		$headers[] = 'X-WHM-Campaign: ' . (int) $campaign_id;
		return $headers;
	}

	private static function encode_name( $name ) {
		$name = str_replace( array( "\r", "\n", '"' ), '', (string) $name );
		return '"' . $name . '"';
	}

	/**
	 * Render and send one email. Returns bool.
	 */
	public static function send_one( array $data, $contact, $campaign_id, $subject_prefix = '' ) {
		self::$last_error = '';
		$tags    = WHM_Renderer::tags( $contact, $campaign_id );
		$subject = $subject_prefix . strtr( $data['subject'], $tags );
		$html    = WHM_Renderer::render( $data['blocks'], $data, $contact, $campaign_id );
		$text    = WHM_Renderer::render_text( $data['blocks'], $data, $contact, $campaign_id );

		$alt = function ( $phpmailer ) use ( $text ) {
			$phpmailer->AltBody = $text; // phpcs:ignore WordPress.NamingConventions
		};
		add_action( 'phpmailer_init', $alt );
		$ok = wp_mail( $contact->email, $subject, $html, self::headers( $contact, $campaign_id ) );
		remove_action( 'phpmailer_init', $alt );
		return (bool) $ok;
	}

	/**
	 * Test send to one or more addresses. Works even when live sending is off.
	 */
	public static function send_test( array $data, array $emails ) {
		$results = array();
		foreach ( array_slice( $emails, 0, 5 ) as $email ) {
			$email   = sanitize_email( $email );
			if ( ! is_email( $email ) ) {
				continue;
			}
			$contact = WHM_Contacts::get_by_email( $email );
			if ( ! $contact ) {
				$contact = (object) array(
					'email'      => $email,
					'first_name' => 'Alex',
					'last_name'  => '',
					'status'     => 'subscribed',
					'token'      => '',
				);
			}
			$results[ $email ] = self::send_one( $data, $contact, 0, '[Test] ' ) ? 'sent' : ( self::$last_error ? self::$last_error : 'failed' );
		}
		return $results;
	}

	public static function pause( $campaign_id ) {
		if ( 'sending' === self::status( $campaign_id ) ) {
			update_post_meta( $campaign_id, '_whm_status', 'paused' );
			as_unschedule_all_actions( 'whm_process_batch', array( (int) $campaign_id ), 'whm' );
		}
	}

	public static function resume( $campaign_id ) {
		if ( 'paused' === self::status( $campaign_id ) ) {
			if ( ! WHM_Settings::get( 'live_sending' ) ) {
				return new WP_Error( 'whm_off', 'Live sending is switched off in Settings.' );
			}
			update_post_meta( $campaign_id, '_whm_status', 'sending' );
			as_enqueue_async_action( 'whm_process_batch', array( (int) $campaign_id ), 'whm' );
		}
		return true;
	}

	public static function cancel( $campaign_id ) {
		global $wpdb;
		$status = self::status( $campaign_id );
		if ( in_array( $status, array( 'scheduled', 'sending', 'paused' ), true ) ) {
			as_unschedule_all_actions( 'whm_start_campaign', array( (int) $campaign_id ), 'whm' );
			as_unschedule_all_actions( 'whm_process_batch', array( (int) $campaign_id ), 'whm' );
			if ( 'scheduled' === $status ) {
				update_post_meta( $campaign_id, '_whm_status', 'draft' );
				delete_post_meta( $campaign_id, '_whm_send_at' );
				return;
			}
			$wpdb->update( WHM_Install::queue_table(), array( 'status' => 'cancelled' ), array( 'campaign_id' => (int) $campaign_id, 'status' => 'pending' ) );
			update_post_meta( $campaign_id, '_whm_status', 'cancelled' );
			update_post_meta( $campaign_id, '_whm_finished', time() );
		}
	}

	public static function stats( $campaign_id ) {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT status, COUNT(*) AS c FROM ' . WHM_Install::queue_table() . ' WHERE campaign_id = %d GROUP BY status', $campaign_id ) );
		$out  = array(
			'pending'   => 0,
			'sent'      => 0,
			'failed'    => 0,
			'skipped'   => 0,
			'cancelled' => 0,
		);
		foreach ( $rows as $r ) {
			$out[ $r->status ] = (int) $r->c;
		}
		global $wpdb;
		$out['unsubscribed'] = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . WHM_Install::contacts_table() . ' WHERE unsubscribe_campaign = %d', $campaign_id ) );
		return $out;
	}
}
