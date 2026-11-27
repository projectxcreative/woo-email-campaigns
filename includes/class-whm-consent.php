<?php
defined( 'ABSPATH' ) || exit;

/**
 * Opt-in collection: checkout tick box (block and classic checkout) and a My Account toggle.
 * Both are off by default and are switched on in Settings.
 *
 * The checkout box is either an opt-in (tick to subscribe) or, for the PECR soft opt-in, an
 * opt-out (tick to refuse). The latest checkout choice always wins:
 * - opt-in: a tick subscribes; an empty box unsubscribes anyone already on the list.
 * - opt-out: a tick unsubscribes; an empty box leaves a new buyer as "customer", with a note
 *   that they were offered the choice, and leaves existing subscribers as they are.
 */
class WHM_Consent {

	const FIELD_ID     = 'shop-email-campaigns/marketing-opt-in';
	const OPT_OUT_ID   = 'shop-email-campaigns/marketing-opt-out';

	public static function init() {
		$s = WHM_Settings::all();
		if ( $s['checkout_opt_in'] ) {
			// Block checkout (WooCommerce Additional Checkout Fields API).
			add_action( 'woocommerce_init', array( __CLASS__, 'register_block_field' ) );
			add_action( 'woocommerce_store_api_checkout_order_processed', array( __CLASS__, 'from_block_checkout' ) );
			// Classic checkout.
			add_action( 'woocommerce_review_order_before_submit', array( __CLASS__, 'classic_field' ) );
			add_action( 'woocommerce_checkout_order_processed', array( __CLASS__, 'from_classic_checkout' ), 10, 3 );
		}
		if ( $s['account_opt_in'] ) {
			add_action( 'woocommerce_edit_account_form', array( __CLASS__, 'account_field' ) );
			add_action( 'woocommerce_save_account_details', array( __CLASS__, 'account_save' ) );
		}
	}

	public static function label() {
		return (string) WHM_Settings::get( 'opt_in_label' );
	}

	public static function is_opt_out() {
		return 'opt_out' === WHM_Settings::get( 'checkout_mode' );
	}

	/** Wording of the checkout box in the current mode. */
	public static function checkout_label() {
		return self::is_opt_out() ? (string) WHM_Settings::get( 'opt_out_label' ) : self::label();
	}

	private static function field_id() {
		return self::is_opt_out() ? self::OPT_OUT_ID : self::FIELD_ID;
	}

	public static function register_block_field() {
		if ( ! function_exists( 'woocommerce_register_additional_checkout_field' ) ) {
			return;
		}
		woocommerce_register_additional_checkout_field(
			array(
				'id'       => self::field_id(),
				'label'    => self::checkout_label(),
				'location' => 'order',
				'type'     => 'checkbox',
				'optional' => true,
			)
		);
	}

	public static function from_block_checkout( $order ) {
		if ( ! $order instanceof WC_Order ) {
			return;
		}
		$value = $order->get_meta( '_wc_other/' . self::field_id() );
		self::handle( $order, $value && 'false' !== $value && '0' !== $value );
	}

	public static function classic_field() {
		echo '<p class="form-row whm-opt-in"><label class="woocommerce-form__label woocommerce-form__label-for-checkbox checkbox">'
			. '<input type="checkbox" class="woocommerce-form__input woocommerce-form__input-checkbox input-checkbox" name="whm_opt_in" value="1"> '
			. '<span>' . esc_html( self::checkout_label() ) . '</span></label></p>';
	}

	public static function from_classic_checkout( $order_id, $posted, $order ) {
		if ( $order instanceof WC_Order ) {
			self::handle( $order, ! empty( $_POST['whm_opt_in'] ) ); // phpcs:ignore WordPress.Security.NonceVerification -- WooCommerce verified the checkout nonce.
		}
	}

	/**
	 * Act on the checkout box. $ticked means the box was ticked, whatever the mode.
	 */
	private static function handle( WC_Order $order, $ticked ) {
		if ( ! self::is_opt_out() ) {
			if ( $ticked ) {
				self::record( $order );
				return;
			}
			// Left the opt-in box empty: their latest choice is "no", so take them off the list.
			$contact = WHM_Contacts::get_by_email( $order->get_billing_email() );
			if ( $contact && in_array( $contact->status, array( 'subscribed', 'customer' ), true ) ) {
				WHM_Contacts::unsubscribe( $contact->id );
				$order->add_order_note( 'Customer left the marketing box unticked at checkout, so they were taken off the email list.' );
			}
			return;
		}
		$id = WHM_Contacts::ensure(
			$order->get_billing_email(),
			array(
				'first_name' => $order->get_billing_first_name(),
				'last_name'  => $order->get_billing_last_name(),
				'user_id'    => $order->get_customer_id(),
				'source'     => 'order',
			)
		);
		if ( ! $id ) {
			return;
		}
		$contact = WHM_Contacts::get( $id );
		if ( $ticked ) {
			if ( $contact && 'unsubscribed' !== $contact->status ) {
				WHM_Contacts::unsubscribe( $id );
			}
			$order->add_order_note( 'Customer ticked "no marketing emails" at checkout.' );
		} elseif ( $contact && 'customer' === $contact->status ) {
			WHM_Contacts::note_offered_opt_out( $id, 'Offered opt-out at checkout and left it unticked (order #' . $order->get_order_number() . '): "' . self::checkout_label() . '"' );
		}
	}

	private static function record( WC_Order $order ) {
		WHM_Contacts::subscribe(
			$order->get_billing_email(),
			array(
				'first_name'   => $order->get_billing_first_name(),
				'last_name'    => $order->get_billing_last_name(),
				'user_id'      => $order->get_customer_id(),
				'source'       => 'checkout',
				'consent_text' => 'Ticked at checkout (order #' . $order->get_order_number() . '): "' . self::label() . '"',
				'consent_ip'   => WHM_Contacts::ip(),
			)
		);
	}

	public static function account_field() {
		$user    = wp_get_current_user();
		$contact = WHM_Contacts::get_by_email( $user->user_email );
		$checked = $contact && 'subscribed' === $contact->status;
		wp_nonce_field( 'whm_account', 'whm_account_nonce' );
		echo '<fieldset><legend>Email preferences</legend><p class="form-row"><label class="woocommerce-form__label woocommerce-form__label-for-checkbox">'
			. '<input type="checkbox" class="woocommerce-form__input-checkbox" name="whm_account_opt_in" value="1"' . checked( $checked, true, false ) . '> '
			. '<span>' . esc_html( self::label() ) . '</span></label></p></fieldset>';
	}

	public static function account_save( $user_id ) {
		if ( ! isset( $_POST['whm_account_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['whm_account_nonce'] ) ), 'whm_account' ) ) {
			return;
		}
		$user    = get_userdata( $user_id );
		$contact = WHM_Contacts::get_by_email( $user->user_email );
		$want    = ! empty( $_POST['whm_account_opt_in'] );
		if ( $want && ( ! $contact || 'subscribed' !== $contact->status ) ) {
			WHM_Contacts::subscribe(
				$user->user_email,
				array(
					'first_name'   => $user->first_name,
					'last_name'    => $user->last_name,
					'user_id'      => $user_id,
					'source'       => 'account',
					'consent_text' => 'Ticked in My Account: "' . self::label() . '"',
					'consent_ip'   => WHM_Contacts::ip(),
				)
			);
		} elseif ( ! $want && $contact && in_array( $contact->status, array( 'subscribed', 'customer' ), true ) ) {
			WHM_Contacts::unsubscribe( $contact->id );
		}
	}
}
