<?php
defined( 'ABSPATH' ) || exit;

/**
 * Tables, post types, starter content.
 */
class WHM_Install {

	const DB_VERSION = '2';

	public static function contacts_table() {
		global $wpdb;
		return $wpdb->prefix . 'whm_contacts';
	}

	public static function queue_table() {
		global $wpdb;
		return $wpdb->prefix . 'whm_queue';
	}

	public static function activate() {
		self::create_tables();
		self::register_post_types_now();
		self::seed();
		update_option( 'whm_db_version', self::DB_VERSION );
		// Pull existing WooCommerce customers into the contact list in the background.
		if ( function_exists( 'as_enqueue_async_action' ) ) {
			as_enqueue_async_action( 'whm_sync_customers', array( 0 ), 'whm' );
		}
	}

	public static function deactivate() {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( 'whm_process_batch', array(), 'whm' );
			as_unschedule_all_actions( 'whm_start_campaign', array(), 'whm' );
			as_unschedule_all_actions( 'whm_sync_customers', array(), 'whm' );
		}
	}

	public static function maybe_upgrade() {
		if ( get_option( 'whm_db_version' ) !== self::DB_VERSION ) {
			self::create_tables();
			update_option( 'whm_db_version', self::DB_VERSION );
		}
	}

	public static function create_tables() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate();
		$c       = self::contacts_table();
		$q       = self::queue_table();

		dbDelta(
			"CREATE TABLE {$c} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			email varchar(190) NOT NULL,
			first_name varchar(100) NOT NULL DEFAULT '',
			last_name varchar(100) NOT NULL DEFAULT '',
			user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			status varchar(20) NOT NULL DEFAULT 'customer',
			source varchar(40) NOT NULL DEFAULT '',
			consent_text text NULL,
			consent_at datetime NULL,
			consent_ip varchar(45) NOT NULL DEFAULT '',
			unsubscribed_at datetime NULL,
			unsubscribe_campaign bigint(20) unsigned NOT NULL DEFAULT 0,
			imported_orders int(10) unsigned NOT NULL DEFAULT 0,
			imported_spent decimal(19,4) NOT NULL DEFAULT 0,
			imported_first_order datetime NULL,
			imported_last_order datetime NULL,
			token char(32) NOT NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY email (email),
			UNIQUE KEY token (token),
			KEY status (status)
			) {$charset};"
		);

		dbDelta(
			"CREATE TABLE {$q} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			campaign_id bigint(20) unsigned NOT NULL,
			contact_id bigint(20) unsigned NOT NULL,
			email varchar(190) NOT NULL,
			status varchar(20) NOT NULL DEFAULT 'pending',
			attempts tinyint(3) unsigned NOT NULL DEFAULT 0,
			error varchar(255) NOT NULL DEFAULT '',
			sent_at datetime NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY campaign_contact (campaign_id,contact_id),
			KEY campaign_status (campaign_id,status)
			) {$charset};"
		);
	}

	public static function register_post_types() {
		add_action( 'init', array( __CLASS__, 'register_post_types_now' ) );
	}

	public static function register_post_types_now() {
		$common = array(
			'public'              => false,
			'show_ui'             => false,
			'show_in_rest'        => false,
			'exclude_from_search' => true,
			'publicly_queryable'  => false,
			'rewrite'             => false,
			'supports'            => array( 'title' ),
			'capability_type'     => 'post',
			'map_meta_cap'        => true,
		);
		register_post_type( 'whm_campaign', array_merge( $common, array( 'label' => 'Email campaigns' ) ) );
		register_post_type( 'whm_template', array_merge( $common, array( 'label' => 'Email templates' ) ) );
		register_post_type( 'whm_segment', array_merge( $common, array( 'label' => 'Customer segments' ) ) );
	}

	/**
	 * Starter segments and templates. Runs once.
	 */
	public static function seed() {
		if ( get_option( 'whm_seeded' ) ) {
			return;
		}

		$segments = array(
			'Purchased in the last 3 months' => array(
				'match' => 'all',
				'rules' => array( array( 'type' => 'purchased_within', 'value' => 3, 'unit' => 'months' ) ),
			),
			'Made multiple purchases'        => array(
				'match' => 'all',
				'rules' => array( array( 'type' => 'order_count_min', 'value' => 2 ) ),
			),
			'All-time customers'             => array(
				'match' => 'all',
				'rules' => array( array( 'type' => 'has_purchased' ) ),
			),
			'Lapsed: no order in 6 months'   => array(
				'match' => 'all',
				'rules' => array( array( 'type' => 'not_purchased_within', 'value' => 6, 'unit' => 'months' ) ),
			),
			'Everyone I can email'           => array(
				'match' => 'all',
				'rules' => array(),
			),
		);
		foreach ( $segments as $title => $def ) {
			$id = wp_insert_post(
				array(
					'post_type'   => 'whm_segment',
					'post_status' => 'publish',
					'post_title'  => $title,
				)
			);
			if ( $id && ! is_wp_error( $id ) ) {
				update_post_meta( $id, '_whm_rules', wp_slash( wp_json_encode( $def ) ) );
			}
		}

		require_once WHM_DIR . 'includes/starter-templates.php';
		foreach ( whm_starter_templates() as $tpl ) {
			$id = wp_insert_post(
				array(
					'post_type'   => 'whm_template',
					'post_status' => 'publish',
					'post_title'  => $tpl['name'],
				)
			);
			if ( $id && ! is_wp_error( $id ) ) {
				update_post_meta( $id, '_whm_subject', $tpl['subject'] );
				update_post_meta( $id, '_whm_preheader', $tpl['preheader'] );
				update_post_meta( $id, '_whm_blocks', wp_slash( wp_json_encode( $tpl['blocks'] ) ) );
			}
		}

		update_option( 'whm_seeded', 1 );
	}
}
