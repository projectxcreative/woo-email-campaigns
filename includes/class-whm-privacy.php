<?php
defined( 'ABSPATH' ) || exit;

/**
 * Hooks into WordPress's built-in personal data export and erasure tools (Tools → Export/Erase Personal Data).
 */
class WHM_Privacy {

	public static function init() {
		add_filter( 'wp_privacy_personal_data_exporters', array( __CLASS__, 'register_exporter' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( __CLASS__, 'register_eraser' ) );
		add_action( 'admin_init', array( __CLASS__, 'policy_text' ) );
	}

	public static function register_exporter( $exporters ) {
		$exporters['shop-email-campaigns'] = array(
			'exporter_friendly_name' => 'Email marketing',
			'callback'               => array( __CLASS__, 'export' ),
		);
		return $exporters;
	}

	public static function register_eraser( $erasers ) {
		$erasers['shop-email-campaigns'] = array(
			'eraser_friendly_name' => 'Email marketing',
			'callback'             => array( __CLASS__, 'erase' ),
		);
		return $erasers;
	}

	public static function export( $email ) {
		$c = WHM_Contacts::get_by_email( $email );
		if ( ! $c ) {
			return array(
				'data' => array(),
				'done' => true,
			);
		}
		return array(
			'data' => array(
				array(
					'group_id'    => 'shop-email-campaigns',
					'group_label' => 'Email marketing',
					'item_id'     => 'whm-contact-' . $c->id,
					'data'        => array(
						array( 'name' => 'Email', 'value' => $c->email ),
						array( 'name' => 'Name', 'value' => trim( $c->first_name . ' ' . $c->last_name ) ),
						array( 'name' => 'Marketing status', 'value' => $c->status ),
						array( 'name' => 'Source', 'value' => $c->source ),
						array( 'name' => 'Consent given', 'value' => (string) $c->consent_at ),
						array( 'name' => 'Consent wording', 'value' => (string) $c->consent_text ),
						array( 'name' => 'Consent IP', 'value' => $c->consent_ip ),
						array( 'name' => 'Unsubscribed', 'value' => (string) $c->unsubscribed_at ),
					),
				),
			),
			'done' => true,
		);
	}

	public static function erase( $email ) {
		$c = WHM_Contacts::get_by_email( $email );
		if ( $c ) {
			WHM_Contacts::delete( $c->id );
		}
		return array(
			'items_removed'  => (bool) $c,
			'items_retained' => false,
			'messages'       => array(),
			'done'           => true,
		);
	}

	public static function policy_text() {
		if ( function_exists( 'wp_add_privacy_policy_content' ) ) {
			wp_add_privacy_policy_content(
				'Shop Email Campaigns',
				'<p>If you buy from us or sign up to our emails, we store your email address, name, and a record of when and how you agreed to hear from us. We use this to send occasional news and offers. Every email has an unsubscribe link, and you can ask us to delete your details at any time.</p>'
			);
		}
	}
}
