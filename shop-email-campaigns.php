<?php
/**
 * Plugin Name:       Shop Email Campaigns
 * Description:       Design branded marketing emails, build customer segments from WooCommerce order history, save templates and send in batches through wp_mail. Includes unsubscribe handling and consent records for UK GDPR / PECR.
 * Version:           1.3.0
 * Author:            Project X Creative
 * Author URI:        https://projectxcreative.com
 * Requires at least: 6.4
 * Requires PHP:      8.0
 * Requires Plugins:  woocommerce
 * Text Domain:       shop-email-campaigns
 * License:           GPL-2.0-or-later
 */

defined( 'ABSPATH' ) || exit;

define( 'WHM_VERSION', '1.3.0' );
define( 'WHM_FILE', __FILE__ );
define( 'WHM_DIR', plugin_dir_path( __FILE__ ) );
define( 'WHM_URL', plugin_dir_url( __FILE__ ) );

require_once WHM_DIR . 'includes/class-whm-install.php';
require_once WHM_DIR . 'includes/class-whm-settings.php';
require_once WHM_DIR . 'includes/class-whm-orders.php';
require_once WHM_DIR . 'includes/class-whm-contacts.php';
require_once WHM_DIR . 'includes/class-whm-segments.php';
require_once WHM_DIR . 'includes/class-whm-renderer.php';
require_once WHM_DIR . 'includes/class-whm-sender.php';
require_once WHM_DIR . 'includes/class-whm-tracking.php';
require_once WHM_DIR . 'includes/class-whm-public.php';
require_once WHM_DIR . 'includes/class-whm-consent.php';
require_once WHM_DIR . 'includes/class-whm-privacy.php';
require_once WHM_DIR . 'includes/class-whm-admin.php';

register_activation_hook( __FILE__, array( 'WHM_Install', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'WHM_Install', 'deactivate' ) );

add_action( 'plugins_loaded', 'whm_boot', 20 );

/**
 * Boot the plugin once WooCommerce is available.
 */
function whm_boot() {
	if ( ! class_exists( 'WooCommerce' ) ) {
		add_action(
			'admin_notices',
			function () {
				echo '<div class="notice notice-error"><p>Shop Email Campaigns needs WooCommerce to be active.</p></div>';
			}
		);
		return;
	}
	WHM_Install::maybe_upgrade();
	WHM_Install::register_post_types();
	WHM_Contacts::init();
	WHM_Sender::init();
	WHM_Tracking::init();
	WHM_Public::init();
	WHM_Consent::init();
	WHM_Privacy::init();
	if ( is_admin() ) {
		WHM_Admin::init();
	}
}
