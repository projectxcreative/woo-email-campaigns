<?php
defined( 'ABSPATH' ) || exit;

/**
 * Turns a list of design blocks into email-safe HTML (tables + inline styles) and a plain-text part.
 *
 * Block types: heading, text, button, image, coupon, product, divider, spacer, signoff.
 */
class WHM_Renderer {

	const ALLOWED_TEXT_HTML = array(
		'p'      => array( 'style' => true ),
		'br'     => array(),
		'strong' => array(),
		'b'      => array(),
		'em'     => array(),
		'i'      => array(),
		'u'      => array(),
		'a'      => array(
			'href'   => true,
			'title'  => true,
			'target' => true,
		),
		'ul'     => array(),
		'ol'     => array(),
		'li'     => array(),
		'div'    => array(),
		'span'   => array(),
	);

	/**
	 * Sanitize blocks coming from the designer.
	 */
	public static function sanitize_blocks( $blocks ) {
		$out = array();
		foreach ( (array) $blocks as $b ) {
			if ( ! is_array( $b ) || empty( $b['type'] ) ) {
				continue;
			}
			$type  = sanitize_key( $b['type'] );
			$align = in_array( $b['align'] ?? '', array( 'left', 'center', 'right' ), true ) ? $b['align'] : 'left';
			switch ( $type ) {
				case 'heading':
					$out[] = array(
						'type'  => 'heading',
						'text'  => sanitize_text_field( $b['text'] ?? '' ),
						'size'  => in_array( $b['size'] ?? '', array( 'large', 'medium', 'small' ), true ) ? $b['size'] : 'large',
						'align' => $align,
					);
					break;
				case 'text':
					$out[] = array(
						'type'  => 'text',
						'html'  => self::clean_text_html( (string) ( $b['html'] ?? '' ) ),
						'align' => $align,
					);
					break;
				case 'button':
					$out[] = array(
						'type'  => 'button',
						'label' => sanitize_text_field( $b['label'] ?? '' ),
						'url'   => self::clean_url( $b['url'] ?? '' ),
						'align' => in_array( $b['align'] ?? '', array( 'left', 'center', 'right' ), true ) ? $b['align'] : 'center',
						'style' => in_array( $b['style'] ?? '', array( 'brand', 'outline', 'filled' ), true ) ? $b['style'] : 'brand',
					);
					break;
				case 'image':
					$out[] = array(
						'type'  => 'image',
						'id'    => absint( $b['id'] ?? 0 ),
						'url'   => esc_url_raw( $b['url'] ?? '' ),
						'alt'   => sanitize_text_field( $b['alt'] ?? '' ),
						'link'  => self::clean_url( $b['link'] ?? '' ),
						'width' => max( 10, min( 100, absint( $b['width'] ?? 100 ) ) ),
						'align' => in_array( $b['align'] ?? '', array( 'left', 'center', 'right' ), true ) ? $b['align'] : 'center',
					);
					break;
				case 'coupon':
					$out[] = array(
						'type'  => 'coupon',
						'title' => sanitize_text_field( $b['title'] ?? '' ),
						'code'  => strtoupper( sanitize_text_field( $b['code'] ?? '' ) ),
						'note'  => sanitize_text_field( $b['note'] ?? '' ),
					);
					break;
				case 'product':
					$out[] = array(
						'type'        => 'product',
						'product_id'  => absint( $b['product_id'] ?? 0 ),
						'button'      => sanitize_text_field( $b['button'] ?? 'Shop now' ),
						'show_price'  => ! empty( $b['show_price'] ),
					);
					break;
				case 'divider':
					$out[] = array( 'type' => 'divider' );
					break;
				case 'spacer':
					$out[] = array(
						'type'   => 'spacer',
						'height' => max( 4, min( 120, absint( $b['height'] ?? 24 ) ) ),
					);
					break;
				case 'signoff':
					$out[] = array(
						'type' => 'signoff',
						'text' => sanitize_textarea_field( $b['text'] ?? '' ),
					);
					break;
			}
		}
		return $out;
	}

	public static function clean_text_html( $html ) {
		$html = wp_kses( $html, self::ALLOWED_TEXT_HTML );
		// Strip any inline style except text-align on paragraphs.
		$html = preg_replace_callback(
			'/style="([^"]*)"/i',
			function ( $m ) {
				return preg_match( '/text-align:\s*(left|center|right)/i', $m[1], $a ) ? 'style="text-align:' . strtolower( $a[1] ) . '"' : '';
			},
			$html
		);
		return $html;
	}

	/**
	 * URLs may be a real URL or a merge tag such as {shop_url}.
	 */
	public static function clean_url( $url ) {
		$url = trim( (string) $url );
		if ( preg_match( '/^\{[a-z_]+(:\d+)?\}$/', $url ) ) {
			return $url;
		}
		return esc_url_raw( $url );
	}

	/**
	 * Merge tag values for a contact (or a sample contact for previews).
	 */
	public static function tags( $contact, $campaign_id = 0 ) {
		$first = $contact && $contact->first_name ? $contact->first_name : '';
		return array(
			'{first_name}'      => '' !== $first ? $first : 'there',
			'{last_name}'       => $contact ? (string) $contact->last_name : '',
			'{email}'           => $contact ? (string) $contact->email : '',
			'{shop_url}'        => function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'shop' ) : home_url( '/' ),
			'{site_url}'        => home_url( '/' ),
			'{account_url}'     => function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'myaccount' ) : home_url( '/' ),
			'{unsubscribe_url}' => WHM_Public::unsubscribe_url( $contact, $campaign_id ),
			'{year}'            => gmdate( 'Y' ),
		);
	}

	/**
	 * CSS font-family value: the chosen web font first, then safe system fonts.
	 */
	public static function font_stack() {
		$name  = preg_replace( '/[^A-Za-z0-9 \-]/', '', (string) WHM_Settings::get( 'font_family' ) );
		$stack = "'Helvetica Neue', Helvetica, Arial, sans-serif";
		return $name ? "'" . $name . "', " . $stack : $stack;
	}

	/**
	 * Google Fonts stylesheet link for the chosen font, or nothing.
	 */
	public static function font_link() {
		$name = preg_replace( '/[^A-Za-z0-9 \-]/', '', (string) WHM_Settings::get( 'font_family' ) );
		if ( ! $name ) {
			return '';
		}
		$url = 'https://fonts.googleapis.com/css2?family=' . str_replace( '%20', '+', rawurlencode( $name ) ) . ':wght@400;500;600&display=swap';
		return '<link href="' . esc_url( $url ) . '" rel="stylesheet">';
	}

	/**
	 * Resolve {product_url:123} style tags.
	 */
	public static function dynamic_tags( $text ) {
		return preg_replace_callback(
			'/\{product_url:(\d+)\}/',
			function ( $m ) {
				$url = get_permalink( (int) $m[1] );
				return $url ? $url : home_url( '/' );
			},
			(string) $text
		);
	}

	private static function merge_text( $text, array $tags ) {
		// Text context: tag values are escaped.
		$safe = array();
		foreach ( $tags as $k => $v ) {
			$safe[ $k ] = esc_html( $v );
		}
		return strtr( self::dynamic_tags( $text ), $safe );
	}

	private static function merge_url( $url, array $tags ) {
		return esc_url( strtr( self::dynamic_tags( $url ), $tags ) );
	}

	/**
	 * Full HTML document.
	 *
	 * @param array       $blocks
	 * @param array       $meta   subject, preheader
	 * @param object|null $contact
	 * @param int         $campaign_id
	 */
	public static function render( array $blocks, array $meta, $contact = null, $campaign_id = 0 ) {
		$s    = WHM_Settings::all();
		$tags = self::tags( $contact, $campaign_id );

		$font = self::font_stack();

		$body = '';
		foreach ( $blocks as $b ) {
			$body .= self::render_block( $b, $s, $tags, $font );
		}

		$preheader = self::merge_text( esc_html( (string) ( $meta['preheader'] ?? '' ) ), $tags );
		$subject   = esc_html( (string) ( $meta['subject'] ?? '' ) );
		$logo      = WHM_Settings::logo_url();
		$home      = esc_url( home_url( '/' ) );

		$header = $logo
			? '<a href="' . $home . '" style="text-decoration:none;"><img src="' . esc_url( $logo ) . '" width="' . (int) $s['logo_width'] . '" alt="' . esc_attr( $s['brand_name'] ) . '" style="display:block;margin:0 auto;width:' . (int) $s['logo_width'] . 'px;max-width:100%;height:auto;border:0;"></a>'
			: '<a href="' . $home . '" style="font-family:' . $font . ';font-size:26px;font-weight:600;color:' . esc_attr( $s['color_heading'] ) . ';text-decoration:none;">' . esc_html( $s['brand_name'] ) . '</a>';

		$address = nl2br( esc_html( $s['footer_address'] ) );
		$note    = $s['footer_note'] ? '<p style="margin:0 0 12px;">' . esc_html( $s['footer_note'] ) . '</p>' : '';
		$unsub   = esc_url( $tags['{unsubscribe_url}'] );
		$muted   = esc_attr( $s['color_muted'] );
		$why     = $contact && 'customer' === $contact->status
			? 'You\'re receiving this because you\'ve bought from us before.'
			: 'You\'re receiving this because you signed up for emails from us.';

		$page = esc_attr( $s['color_page'] );
		$card = esc_attr( $s['color_card'] );
		$text = esc_attr( $s['color_text'] );

		return '<!DOCTYPE html>
<html lang="en" xmlns="http://www.w3.org/1999/xhtml">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="x-apple-disable-message-reformatting">
<meta name="color-scheme" content="light">
<meta name="supported-color-schemes" content="light">
<title>' . $subject . '</title>
' . self::font_link() . '
<style>
body{margin:0;padding:0;background:' . $page . ';}
a{color:' . esc_attr( $s['color_accent'] ) . ';}
p{margin:0 0 16px;}
ul,ol{margin:0 0 16px;padding-left:22px;}
li{margin:0 0 6px;}
.whm-btn:hover{opacity:.9;}
@media (max-width:620px){
 .whm-card{width:100% !important;}
 .whm-pad{padding-left:24px !important;padding-right:24px !important;}
 .whm-product td{display:block !important;width:100% !important;}
}
</style>
</head>
<body style="margin:0;padding:0;background:' . $page . ';">
<div style="display:none;max-height:0;overflow:hidden;mso-hide:all;font-size:1px;line-height:1px;color:' . $page . ';">' . $preheader . str_repeat( '&#8204;&nbsp;', 40 ) . '</div>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:' . $page . ';">
<tr><td align="center" style="padding:32px 12px;">
<table role="presentation" class="whm-card" width="600" cellpadding="0" cellspacing="0" border="0" style="width:600px;max-width:600px;">
<tr><td align="center" style="padding:8px 0 28px;">' . $header . '</td></tr>
<tr><td class="whm-pad" style="background:' . $card . ';padding:40px 48px;font-family:' . $font . ';font-size:16px;line-height:1.6;color:' . $text . ';">' . $body . '</td></tr>
<tr><td class="whm-pad" align="center" style="padding:28px 48px 8px;font-family:' . $font . ';font-size:13px;line-height:1.6;color:' . $muted . ';">
' . $note . '<p style="margin:0 0 12px;">' . $address . '</p>
<p style="margin:0 0 12px;">' . esc_html( $why ) . '<br><a href="' . $unsub . '" style="color:' . $muted . ';text-decoration:underline;">Unsubscribe</a></p>
</td></tr>
</table>
</td></tr>
</table>
</body>
</html>';
	}

	private static function render_block( array $b, array $s, array $tags, $font ) {
		$ink     = esc_attr( $s['color_text'] );
		$heading = esc_attr( $s['color_heading'] );
		$accent  = esc_attr( $s['color_accent'] );
		$align   = esc_attr( $b['align'] ?? 'left' );

		switch ( $b['type'] ) {
			case 'heading':
				$sizes = array(
					'large'  => 30,
					'medium' => 22,
					'small'  => 18,
				);
				$size  = $sizes[ $b['size'] ] ?? 30;
				$tag   = 'large' === $b['size'] ? 'h1' : 'h2';
				$color = 'small' === $b['size'] ? $ink : $heading;
				return '<' . $tag . ' style="margin:0 0 16px;font-family:' . $font . ';font-size:' . $size . 'px;line-height:1.25;font-weight:500;color:' . $color . ';text-align:' . $align . ';">' . self::merge_text( esc_html( $b['text'] ), $tags ) . '</' . $tag . '>';

			case 'text':
				$html = self::merge_text( $b['html'], $tags );
				$html = self::style_links( $html, $accent );
				if ( ! preg_match( '/^\s*<(p|ul|ol|div)/i', $html ) ) {
					$html = '<p>' . $html . '</p>';
				}
				$html = preg_replace( '/<p(?![^>]*style=)/i', '<p style="margin:0 0 16px;"', $html );
				$html = preg_replace( '/<p style="text-align:(left|center|right)"/i', '<p style="margin:0 0 16px;text-align:$1"', $html );
				return '<div style="text-align:' . $align . ';">' . $html . '</div>';

			case 'button':
				if ( '' === $b['label'] ) {
					return '';
				}
				$style  = 'brand' === $b['style'] ? $s['button_style'] : $b['style'];
				$url    = self::merge_url( $b['url'] ? $b['url'] : '{shop_url}', $tags );
				$filled = 'filled' === $style;
				$bg     = $filled ? $accent : 'transparent';
				$color  = $filled ? '#ffffff' : $ink;
				return '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:8px 0 24px;"><tr><td align="' . esc_attr( $b['align'] ) . '">'
					. '<a class="whm-btn" href="' . $url . '" style="display:inline-block;background:' . $bg . ';border:1px solid ' . $accent . ';border-radius:3px;padding:15px 28px;font-family:' . $font . ';font-size:16px;font-weight:500;line-height:1.2;color:' . $color . ';text-decoration:none;">'
					. self::merge_text( esc_html( $b['label'] ), $tags ) . '</a></td></tr></table>';

			case 'image':
				$src = $b['id'] ? wp_get_attachment_image_url( $b['id'], 'large' ) : $b['url'];
				if ( ! $src ) {
					return '';
				}
				$w   = (int) round( 504 * $b['width'] / 100 );
				$img = '<img src="' . esc_url( $src ) . '" width="' . $w . '" alt="' . esc_attr( $b['alt'] ) . '" style="display:block;width:' . $w . 'px;max-width:100%;height:auto;border:0;' . ( 'center' === $b['align'] ? 'margin:0 auto;' : ( 'right' === $b['align'] ? 'margin-left:auto;' : '' ) ) . '">';
				if ( $b['link'] ) {
					$img = '<a href="' . self::merge_url( $b['link'], $tags ) . '">' . $img . '</a>';
				}
				return '<div style="margin:0 0 24px;text-align:' . esc_attr( $b['align'] ) . ';">' . $img . '</div>';

			case 'coupon':
				if ( '' === $b['code'] ) {
					return '';
				}
				$page = esc_attr( $s['color_page'] );
				return '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:8px 0 24px;"><tr><td align="center" style="background:' . $page . ';border:1px dashed ' . $accent . ';border-radius:8px;padding:24px;font-family:' . $font . ';">'
					. ( $b['title'] ? '<div style="font-size:16px;color:' . $ink . ';margin:0 0 8px;">' . self::merge_text( esc_html( $b['title'] ), $tags ) . '</div>' : '' )
					. '<div style="font-size:26px;font-weight:600;letter-spacing:3px;color:' . $heading . ';">' . esc_html( $b['code'] ) . '</div>'
					. ( $b['note'] ? '<div style="font-size:13px;color:' . esc_attr( $s['color_muted'] ) . ';margin-top:8px;">' . self::merge_text( esc_html( $b['note'] ), $tags ) . '</div>' : '' )
					. '</td></tr></table>';

			case 'product':
				$p = $b['product_id'] ? wc_get_product( $b['product_id'] ) : null;
				if ( ! $p ) {
					return '';
				}
				$img   = wp_get_attachment_image_url( $p->get_image_id(), 'woocommerce_thumbnail' );
				$price = $b['show_price'] ? wp_strip_all_tags( html_entity_decode( wc_price( $p->get_price() ), ENT_QUOTES, 'UTF-8' ) ) : '';
				if ( $b['show_price'] && $p->is_type( array( 'variable', 'variable-subscription' ) ) ) {
					$price = 'From ' . $price;
				}
				$link = esc_url( $p->get_permalink() );
				return '<table role="presentation" class="whm-product" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:8px 0 24px;border:1px solid rgba(29,29,26,.12);border-radius:8px;"><tr>'
					. ( $img ? '<td width="180" style="width:180px;padding:16px;vertical-align:middle;"><a href="' . $link . '"><img src="' . esc_url( $img ) . '" width="160" alt="' . esc_attr( $p->get_name() ) . '" style="display:block;width:160px;max-width:100%;height:auto;border:0;"></a></td>' : '' )
					. '<td style="padding:16px;vertical-align:middle;font-family:' . $font . ';">'
					. '<div style="font-size:19px;font-weight:500;color:' . $ink . ';margin:0 0 6px;">' . esc_html( $p->get_name() ) . '</div>'
					. ( $price ? '<div style="font-size:15px;color:' . $heading . ';margin:0 0 14px;">' . esc_html( $price ) . '</div>' : '' )
					. '<a href="' . $link . '" style="display:inline-block;border:1px solid ' . $accent . ';border-radius:3px;padding:10px 18px;font-size:14px;color:' . $ink . ';text-decoration:none;">' . esc_html( $b['button'] ) . '</a>'
					. '</td></tr></table>';

			case 'divider':
				return '<div style="border-top:1px solid rgba(29,29,26,.12);margin:24px 0;line-height:0;font-size:0;">&nbsp;</div>';

			case 'spacer':
				return '<div style="height:' . (int) $b['height'] . 'px;line-height:' . (int) $b['height'] . 'px;font-size:0;">&nbsp;</div>';

			case 'signoff':
				return '<p style="margin:8px 0 0;">' . nl2br( self::merge_text( esc_html( $b['text'] ), $tags ) ) . '</p>';
		}
		return '';
	}

	private static function style_links( $html, $accent ) {
		return preg_replace( '/<a\s/i', '<a style="color:' . $accent . ';text-decoration:underline;" ', $html );
	}

	/**
	 * Plain-text alternative.
	 */
	public static function render_text( array $blocks, array $meta, $contact = null, $campaign_id = 0 ) {
		$s     = WHM_Settings::all();
		$tags  = self::tags( $contact, $campaign_id );
		$lines = array();
		foreach ( $blocks as $b ) {
			switch ( $b['type'] ) {
				case 'heading':
					$lines[] = strtoupper( strtr( $b['text'], $tags ) );
					break;
				case 'text':
					$t = preg_replace( '/<a [^>]*href="([^"]+)"[^>]*>(.*?)<\/a>/i', '$2 ($1)', self::dynamic_tags( $b['html'] ) );
					$t = preg_replace( '/<li[^>]*>/i', '- ', $t );
					$t = preg_replace( '/<\/(p|li|ul|ol|div)>|<br\s*\/?>/i', "\n", $t );
					$lines[] = trim( html_entity_decode( wp_strip_all_tags( strtr( $t, $tags ) ), ENT_QUOTES, 'UTF-8' ) );
					break;
				case 'button':
					$lines[] = strtr( $b['label'], $tags ) . ': ' . strtr( self::dynamic_tags( $b['url'] ? $b['url'] : '{shop_url}' ), $tags );
					break;
				case 'coupon':
					$lines[] = trim( strtr( $b['title'], $tags ) . ' Code: ' . $b['code'] . ' ' . strtr( $b['note'], $tags ) );
					break;
				case 'product':
					$p = wc_get_product( $b['product_id'] );
					if ( $p ) {
						$lines[] = $p->get_name() . ': ' . $p->get_permalink();
					}
					break;
				case 'divider':
					$lines[] = '----';
					break;
				case 'signoff':
					$lines[] = strtr( $b['text'], $tags );
					break;
			}
		}
		$lines[] = '';
		$lines[] = '--';
		$lines[] = $s['footer_address'];
		$lines[] = 'Unsubscribe: ' . $tags['{unsubscribe_url}'];
		return implode( "\n\n", array_filter( $lines, fn( $l ) => null !== $l ) );
	}
}
