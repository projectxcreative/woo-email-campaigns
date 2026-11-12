<?php
defined( 'ABSPATH' ) || exit;

/**
 * Starter templates created on first activation. Add your own with the `whm_starter_templates` filter.
 * Links use merge tags ({shop_url}, {site_url}, {product_url:ID}) so they follow the site if its domain changes.
 */
function whm_starter_templates() {
	$signoff = WHM_Settings::signoff();

	$templates = array(
		array(
			'name'      => 'Newsletter',
			'subject'   => 'News from us',
			'preheader' => 'What we\'ve been up to, and what\'s new in the shop.',
			'blocks'    => array(
				array( 'type' => 'heading', 'text' => 'Hello from the team', 'size' => 'large', 'align' => 'left' ),
				array( 'type' => 'text', 'align' => 'left', 'html' => '<p>Hi {first_name},</p><p>Here\'s a quick round-up of what\'s been happening.</p>' ),
				array( 'type' => 'heading', 'text' => 'What\'s new', 'size' => 'medium', 'align' => 'left' ),
				array( 'type' => 'text', 'align' => 'left', 'html' => '<ul><li>First bit of news</li><li>Second bit of news</li><li>Third bit of news</li></ul>' ),
				array( 'type' => 'button', 'label' => 'Visit the shop', 'url' => '{shop_url}', 'align' => 'center', 'style' => 'brand' ),
				array( 'type' => 'signoff', 'text' => $signoff ),
			),
		),
		array(
			'name'      => 'Discount offer',
			'subject'   => 'A little something for you, {first_name}',
			'preheader' => 'Take 10% off your next order.',
			'blocks'    => array(
				array( 'type' => 'heading', 'text' => 'Thanks for shopping with us', 'size' => 'large', 'align' => 'center' ),
				array( 'type' => 'text', 'align' => 'center', 'html' => '<p>Hi {first_name}, as a thank you here\'s <strong>10% off</strong> your next order.</p>' ),
				array( 'type' => 'coupon', 'title' => 'Use code', 'code' => 'THANKYOU10', 'note' => 'Valid until [DATE]' ),
				array( 'type' => 'button', 'label' => 'Shop now', 'url' => '{shop_url}', 'align' => 'center', 'style' => 'brand' ),
				array( 'type' => 'signoff', 'text' => $signoff ),
			),
		),
		array(
			'name'      => 'Product spotlight',
			'subject'   => 'New in: take a look',
			'preheader' => 'Something new has just arrived.',
			'blocks'    => array(
				array( 'type' => 'heading', 'text' => 'Just arrived', 'size' => 'large', 'align' => 'center' ),
				array( 'type' => 'text', 'align' => 'center', 'html' => '<p>Hi {first_name}, we think you\'ll like this one.</p>' ),
				array( 'type' => 'product', 'product_id' => 0, 'button' => 'Shop now', 'show_price' => true ),
				array( 'type' => 'button', 'label' => 'See everything', 'url' => '{shop_url}', 'align' => 'center', 'style' => 'brand' ),
				array( 'type' => 'signoff', 'text' => $signoff ),
			),
		),
		array(
			'name'      => 'We miss you',
			'subject'   => 'It\'s been a while, {first_name}',
			'preheader' => 'Here\'s what you\'ve missed.',
			'blocks'    => array(
				array( 'type' => 'heading', 'text' => 'We\'ve missed you', 'size' => 'large', 'align' => 'left' ),
				array( 'type' => 'text', 'align' => 'left', 'html' => '<p>Hi {first_name},</p><p>It\'s been a little while since your last order, so we wanted to say hello and show you what\'s new.</p>' ),
				array( 'type' => 'button', 'label' => 'Have a look', 'url' => '{shop_url}', 'align' => 'center', 'style' => 'brand' ),
				array( 'type' => 'signoff', 'text' => $signoff ),
			),
		),
		array(
			'name'      => 'Blank email',
			'subject'   => '',
			'preheader' => '',
			'blocks'    => array(
				array( 'type' => 'heading', 'text' => 'Your headline', 'size' => 'large', 'align' => 'left' ),
				array( 'type' => 'text', 'align' => 'left', 'html' => '<p>Hi {first_name},</p><p>Start writing here.</p>' ),
				array( 'type' => 'signoff', 'text' => $signoff ),
			),
		),
	);

	return apply_filters( 'whm_starter_templates', $templates );
}
