<?php
defined( 'ABSPATH' ) || exit;

/**
 * Plugin settings: brand, sender, sending pace and consent options.
 */
class WHM_Settings {

	const OPTION = 'whm_settings';

	private static $cache = null;

	/**
	 * Generic defaults drawn from the site itself. Every value can be changed in Settings,
	 * and the whole set can be filtered with `whm_default_settings` (e.g. by a brand preset).
	 */
	public static function defaults() {
		$name    = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		$address = array_filter(
			array(
				$name,
				get_option( 'woocommerce_store_address' ),
				get_option( 'woocommerce_store_address_2' ),
				get_option( 'woocommerce_store_city' ),
				get_option( 'woocommerce_store_postcode' ),
			)
		);
		$from    = get_option( 'woocommerce_email_from_address' );

		return apply_filters(
			'whm_default_settings',
			array(
				// Brand.
				'brand_name'      => $name,
				'logo_id'         => (int) get_theme_mod( 'custom_logo' ),
				'logo_width'      => 180,
				'color_page'      => '#F6F5F2',
				'color_card'      => '#FFFFFF',
				'color_text'      => '#1F1F1F',
				'color_heading'   => '#1F1F1F',
				'color_accent'    => '#2F6F5E',
				'color_muted'     => '#6B6B6B',
				'font_family'     => '',
				'button_style'    => 'filled', // outline|filled.
				'footer_address'  => implode( "\n", $address ),
				'footer_note'     => '',
				'signoff'         => 'The ' . $name . ' team',
				// Sender.
				'from_name'       => $name,
				'from_email'      => $from ? $from : (string) get_option( 'admin_email' ),
				'reply_to'        => '',
				// Sending.
				'live_sending'    => 0, // Master switch: campaigns cannot go out until this is on.
				'batch_size'      => 40,
				'batch_interval'  => 60,
				// Reporting.
				'click_tracking'  => 1,
				'utm_tags'        => 1,
				// Consent.
				'soft_opt_in'     => 0, // Allow emailing customers who have not opted in or out (PECR soft opt-in).
				'checkout_opt_in' => 0,
				'opt_in_label'    => 'Send me occasional news and offers by email. You can unsubscribe at any time.',
				'account_opt_in'  => 0,
			)
		);
	}

	public static function all() {
		if ( null === self::$cache ) {
			$saved       = get_option( self::OPTION, array() );
			self::$cache = wp_parse_args( is_array( $saved ) ? $saved : array(), self::defaults() );
		}
		return self::$cache;
	}

	public static function signoff() {
		return (string) self::get( 'signoff' );
	}

	public static function get( $key ) {
		$all = self::all();
		return $all[ $key ] ?? null;
	}

	public static function save( array $input ) {
		$current = self::all();
		$clean   = $current;

		$text_keys = array( 'brand_name', 'font_family', 'from_name', 'opt_in_label', 'footer_note', 'signoff' );
		foreach ( $text_keys as $k ) {
			if ( isset( $input[ $k ] ) ) {
				$clean[ $k ] = sanitize_text_field( wp_unslash( $input[ $k ] ) );
			}
		}
		if ( isset( $input['footer_address'] ) ) {
			$clean['footer_address'] = sanitize_textarea_field( wp_unslash( $input['footer_address'] ) );
		}
		foreach ( array( 'from_email', 'reply_to' ) as $k ) {
			if ( isset( $input[ $k ] ) ) {
				$clean[ $k ] = sanitize_email( wp_unslash( $input[ $k ] ) );
			}
		}
		foreach ( array( 'color_page', 'color_card', 'color_text', 'color_heading', 'color_accent', 'color_muted' ) as $k ) {
			if ( isset( $input[ $k ] ) ) {
				$hex = sanitize_hex_color( wp_unslash( $input[ $k ] ) );
				if ( $hex ) {
					$clean[ $k ] = $hex;
				}
			}
		}
		if ( isset( $input['logo_id'] ) ) {
			$clean['logo_id'] = absint( $input['logo_id'] );
		}
		if ( isset( $input['logo_width'] ) ) {
			$clean['logo_width'] = max( 60, min( 560, absint( $input['logo_width'] ) ) );
		}
		if ( isset( $input['button_style'] ) ) {
			$clean['button_style'] = 'filled' === $input['button_style'] ? 'filled' : 'outline';
		}
		if ( isset( $input['batch_size'] ) ) {
			$clean['batch_size'] = max( 1, min( 500, absint( $input['batch_size'] ) ) );
		}
		if ( isset( $input['batch_interval'] ) ) {
			$clean['batch_interval'] = max( 30, min( 3600, absint( $input['batch_interval'] ) ) );
		}
		// Tick boxes: an unticked box is missing from a submitted form, so only switch one off when
		// the whole Settings form was sent (or the key is given), never on a partial save.
		$full_form = ! empty( $input['_form'] );
		foreach ( array( 'live_sending', 'soft_opt_in', 'checkout_opt_in', 'account_opt_in', 'click_tracking', 'utm_tags' ) as $k ) {
			if ( $full_form || array_key_exists( $k, $input ) ) {
				$clean[ $k ] = empty( $input[ $k ] ) ? 0 : 1;
			}
		}

		update_option( self::OPTION, $clean );
		self::$cache = null;
		return $clean;
	}

	public static function logo_url() {
		$id = (int) self::get( 'logo_id' );
		return $id ? (string) wp_get_attachment_url( $id ) : '';
	}

	/**
	 * Statuses that may receive marketing email.
	 */
	public static function sendable_statuses() {
		$s = array( 'subscribed' );
		if ( self::get( 'soft_opt_in' ) ) {
			$s[] = 'customer';
		}
		return $s;
	}
}
