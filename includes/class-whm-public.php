<?php
defined( 'ABSPATH' ) || exit;

/**
 * Public unsubscribe page and one-click unsubscribe endpoint (RFC 8058).
 */
class WHM_Public {

	public static function init() {
		add_action( 'template_redirect', array( __CLASS__, 'handle' ), 1 );
	}

	public static function unsubscribe_url( $contact, $campaign_id = 0 ) {
		if ( ! $contact || empty( $contact->token ) ) {
			return add_query_arg( 'whm', 'unsubscribe', home_url( '/' ) );
		}
		return add_query_arg(
			array(
				'whm' => 'unsubscribe',
				't'   => $contact->token,
				'm'   => absint( $campaign_id ),
			),
			home_url( '/' )
		);
	}

	public static function handle() {
		if ( empty( $_GET['whm'] ) || 'unsubscribe' !== $_GET['whm'] ) { // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}
		nocache_headers();
		$token    = isset( $_GET['t'] ) ? sanitize_text_field( wp_unslash( $_GET['t'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$campaign = isset( $_GET['m'] ) ? absint( $_GET['m'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification
		$contact  = WHM_Contacts::get_by_token( $token );
		$method   = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : 'GET';

		// One-click unsubscribe from the mail client (List-Unsubscribe-Post). No page, just a 200.
		if ( 'POST' === $method && isset( $_POST['List-Unsubscribe'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			if ( $contact ) {
				WHM_Contacts::unsubscribe( $contact->id, $campaign );
			}
			status_header( 200 );
			header( 'Content-Type: text/plain; charset=utf-8' );
			echo 'Unsubscribed';
			exit;
		}

		$state = 'confirm';
		if ( ! $contact ) {
			$state = 'invalid';
		} elseif ( 'POST' === $method ) {
			// The token in the URL is the authorisation; a nonce would break for logged-out visitors with cached pages.
			$action = isset( $_POST['whm_action'] ) ? sanitize_key( $_POST['whm_action'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
			if ( 'unsubscribe' === $action ) {
				WHM_Contacts::unsubscribe( $contact->id, $campaign );
				$state = 'done';
			} elseif ( 'resubscribe' === $action ) {
				WHM_Contacts::subscribe(
					$contact->email,
					array(
						'source'       => 'resubscribe',
						'consent_text' => 'Clicked "Keep me subscribed" on the unsubscribe page.',
						'consent_ip'   => WHM_Contacts::ip(),
					)
				);
				$state = 'resubscribed';
			}
		} elseif ( 'unsubscribed' === $contact->status ) {
			$state = 'done';
		}

		self::page( $state, $contact );
		exit;
	}

	private static function page( $state, $contact ) {
		$s     = WHM_Settings::all();
		$logo  = WHM_Settings::logo_url();
		$font  = WHM_Renderer::font_stack();
		$email = $contact ? $contact->email : '';

		switch ( $state ) {
			case 'invalid':
				$title = 'This link has expired';
				$body  = '<p>We couldn\'t find your details from this link. If you\'d like to stop hearing from us, just reply to any of our emails and we\'ll sort it.</p>';
				break;
			case 'done':
				$title = 'You\'ve been unsubscribed';
				$body  = '<p><strong>' . esc_html( $email ) . '</strong> won\'t get any more marketing emails from us. You\'ll still get emails about orders you place.</p>'
					. '<p>Changed your mind?</p>'
					. '<form method="post"><input type="hidden" name="whm_action" value="resubscribe"><button type="submit" class="b">Keep me subscribed</button></form>';
				break;
			case 'resubscribed':
				$title = 'Lovely, you\'re back on the list';
				$body  = '<p>We\'ll keep <strong>' . esc_html( $email ) . '</strong> posted with news and offers.</p>';
				break;
			default:
				$title = 'Unsubscribe from our emails?';
				$body  = '<p>Click below and we\'ll stop sending marketing emails to <strong>' . esc_html( $email ) . '</strong>.</p>'
					. '<form method="post"><input type="hidden" name="whm_action" value="unsubscribe"><button type="submit" class="b">Unsubscribe</button></form>';
		}

		status_header( 'invalid' === $state ? 404 : 200 );
		header( 'Content-Type: text/html; charset=utf-8' );
		header( 'X-Robots-Tag: noindex, nofollow' );
		echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>' . esc_html( $title . ' | ' . $s['brand_name'] ) . '</title>'
			. WHM_Renderer::font_link()
			. '<style>body{margin:0;background:' . esc_attr( $s['color_page'] ) . ';color:' . esc_attr( $s['color_text'] ) . ';font-family:' . $font . ';font-size:17px;line-height:1.6}'
			. '.w{max-width:520px;margin:0 auto;padding:56px 20px;text-align:center}.c{background:' . esc_attr( $s['color_card'] ) . ';padding:40px 32px;border-radius:8px;border:1px solid rgba(29,29,26,.12)}'
			. 'h1{font-weight:500;font-size:28px;line-height:1.25;color:' . esc_attr( $s['color_heading'] ) . ';margin:0 0 16px}'
			. '.b{font:inherit;cursor:pointer;background:transparent;border:1px solid ' . esc_attr( $s['color_accent'] ) . ';color:' . esc_attr( $s['color_text'] ) . ';border-radius:3px;padding:15px 24px;margin-top:8px}'
			. '.b:hover,.b:focus{background:' . esc_attr( $s['color_accent'] ) . ';color:#fff}a{color:' . esc_attr( $s['color_text'] ) . '}img{max-width:100%;height:auto}</style></head><body><div class="w">'
			. ( $logo ? '<p><a href="' . esc_url( home_url( '/' ) ) . '"><img src="' . esc_url( $logo ) . '" width="' . (int) $s['logo_width'] . '" alt="' . esc_attr( $s['brand_name'] ) . '"></a></p>' : '' )
			. '<div class="c"><h1>' . esc_html( $title ) . '</h1>' . $body . '</div>'
			. '<p><a href="' . esc_url( home_url( '/' ) ) . '">Back to the shop</a></p></div></body></html>';
	}
}
