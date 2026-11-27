<?php
defined( 'ABSPATH' ) || exit;

/**
 * Admin screens: Campaigns, Designer, Templates, Segments, Contacts, Settings.
 */
class WHM_Admin {

	const CAP = 'manage_woocommerce';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );

		foreach ( array( 'save_settings', 'import', 'sync', 'contact', 'add_contact', 'campaign_action', 'template_action', 'segment_action', 'save_segment' ) as $a ) {
			add_action( 'admin_post_whm_' . $a, array( __CLASS__, 'post_' . $a ) );
		}
		foreach ( array( 'save', 'preview', 'test', 'send', 'audience', 'products', 'segment_preview' ) as $a ) {
			add_action( 'wp_ajax_whm_' . $a, array( __CLASS__, 'ajax_' . $a ) );
		}
	}

	/* ------------------------------------------------------------------ Menu & assets */

	public static function menu() {
		add_menu_page( 'Email Marketing', 'Email Marketing', self::CAP, 'whm', array( __CLASS__, 'page_campaigns' ), 'dashicons-email-alt', 56 );
		add_submenu_page( 'whm', 'Campaigns', 'Campaigns', self::CAP, 'whm', array( __CLASS__, 'page_campaigns' ) );
		add_submenu_page( 'whm', 'Design email', 'New campaign', self::CAP, 'whm-edit', array( __CLASS__, 'page_designer' ) );
		add_submenu_page( 'whm', 'Campaign reports', 'Reports', self::CAP, 'whm-reports', array( __CLASS__, 'page_reports' ) );
		add_submenu_page( 'whm', 'Templates', 'Templates', self::CAP, 'whm-templates', array( __CLASS__, 'page_templates' ) );
		add_submenu_page( 'whm', 'Segments', 'Segments', self::CAP, 'whm-segments', array( __CLASS__, 'page_segments' ) );
		add_submenu_page( 'whm', 'Contacts', 'Contacts', self::CAP, 'whm-contacts', array( __CLASS__, 'page_contacts' ) );
		add_submenu_page( 'whm', 'Email settings', 'Settings', self::CAP, 'whm-settings', array( __CLASS__, 'page_settings' ) );
	}

	public static function assets( $hook ) {
		if ( false === strpos( (string) $hook, 'whm' ) ) {
			return;
		}
		wp_enqueue_style( 'whm-admin', WHM_URL . 'assets/admin.css', array(), WHM_VERSION );
		$page = isset( $_GET['page'] ) ? sanitize_key( $_GET['page'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		if ( 'whm-edit' === $page ) {
			wp_enqueue_media();
			wp_enqueue_script( 'whm-designer', WHM_URL . 'assets/designer.js', array( 'jquery' ), WHM_VERSION, true );
		}
		if ( 'whm-segments' === $page ) {
			wp_enqueue_script( 'whm-segments', WHM_URL . 'assets/segments.js', array( 'jquery' ), WHM_VERSION, true );
		}
		if ( 'whm-settings' === $page ) {
			wp_enqueue_media();
			wp_enqueue_style( 'wp-color-picker' );
			wp_enqueue_script( 'whm-settings', WHM_URL . 'assets/settings.js', array( 'jquery', 'wp-color-picker' ), WHM_VERSION, true );
		}
	}

	private static function check( $nonce_action ) {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( 'Sorry, you are not allowed to do that.' );
		}
		check_admin_referer( $nonce_action );
	}

	private static function ajax_check() {
		if ( ! current_user_can( self::CAP ) || ! check_ajax_referer( 'whm_ajax', 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => 'Your session has expired. Reload the page and try again.' ), 403 );
		}
	}

	private static function url( $page, array $args = array() ) {
		return add_query_arg( array_merge( array( 'page' => $page ), $args ), admin_url( 'admin.php' ) );
	}

	private static function notice() {
		$msg = isset( $_GET['whm_msg'] ) ? sanitize_text_field( wp_unslash( $_GET['whm_msg'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$err = isset( $_GET['whm_err'] ) ? sanitize_text_field( wp_unslash( $_GET['whm_err'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		if ( $msg ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $msg ) . '</p></div>';
		}
		if ( $err ) {
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html( $err ) . '</p></div>';
		}
		if ( ! WHM_Settings::get( 'live_sending' ) ) {
			echo '<div class="notice notice-warning whm-live-off"><p><strong>Live sending is off.</strong> You can design emails and send tests, but campaigns won\'t go out to customers until you switch it on in <a href="' . esc_url( self::url( 'whm-settings' ) ) . '">Settings</a>.</p></div>';
		}
	}

	private static function header( $title, $action_html = '' ) {
		echo '<div class="wrap whm-wrap"><h1 class="wp-heading-inline">' . esc_html( $title ) . '</h1>' . $action_html . '<hr class="wp-header-end">'; // phpcs:ignore WordPress.Security.EscapeOutput
		self::notice();
	}

	private static function status_badge( $status ) {
		$labels = array(
			'draft'        => 'Draft',
			'scheduled'    => 'Scheduled',
			'sending'      => 'Sending',
			'paused'       => 'Paused',
			'sent'         => 'Sent',
			'cancelled'    => 'Cancelled',
			'subscribed'   => 'Subscribed',
			'customer'     => 'Customer (soft opt-in)',
			'unsubscribed' => 'Unsubscribed',
			'bounced'      => 'Bounced',
		);
		return '<span class="whm-badge whm-badge-' . esc_attr( $status ) . '">' . esc_html( $labels[ $status ] ?? $status ) . '</span>';
	}

	private static function fmt_time( $ts ) {
		return $ts ? esc_html( wp_date( 'j M Y, H:i', (int) $ts ) ) : '';
	}

	/* ------------------------------------------------------------------ Campaigns */

	public static function page_campaigns() {
		$new = '<a href="' . esc_url( self::url( 'whm-edit' ) ) . '" class="page-title-action">New campaign</a>';
		self::header( 'Email campaigns', $new );

		$posts = get_posts(
			array(
				'post_type'      => 'whm_campaign',
				'post_status'    => 'any',
				'posts_per_page' => 100,
				'orderby'        => 'modified',
				'order'          => 'DESC',
			)
		);
		if ( ! $posts ) {
			echo '<div class="whm-empty"><h2>No campaigns yet</h2><p>Start from one of your templates, or design an email from scratch.</p>'
				. '<p><a class="button button-primary" href="' . esc_url( self::url( 'whm-templates' ) ) . '">Choose a template</a> '
				. '<a class="button" href="' . esc_url( self::url( 'whm-edit' ) ) . '">Start from scratch</a></p></div></div>';
			return;
		}
		echo '<table class="widefat striped whm-table"><thead><tr><th>Campaign</th><th>Status</th><th>Audience</th><th>Progress</th><th>Last change</th><th></th></tr></thead><tbody>';
		foreach ( $posts as $p ) {
			$status  = WHM_Sender::status( $p->ID );
			$stats   = WHM_Sender::stats( $p->ID );
			$seg     = (int) get_post_meta( $p->ID, '_whm_segment', true );
			$seg_lbl = get_post_meta( $p->ID, '_whm_segment_label', true );
			$seg_lbl = $seg_lbl ? $seg_lbl : ( $seg ? get_the_title( $seg ) : '—' );
			$total   = (int) get_post_meta( $p->ID, '_whm_recipients', true );
			$edit    = self::url( 'whm-edit', array( 'campaign' => $p->ID ) );

			$progress = '';
			if ( $total ) {
				$progress = sprintf( '%d of %d sent', $stats['sent'], $total );
				if ( $stats['failed'] ) {
					$progress .= sprintf( ', %d failed', $stats['failed'] );
				}
				if ( $stats['unsubscribed'] ) {
					$progress .= sprintf( ', %d unsubscribed', $stats['unsubscribed'] );
				}
				$until = WHM_Sender::throttled_until( $p->ID );
				if ( $until ) {
					$progress .= '<br><span class="whm-hint">Paused by the mail service\'s sending limit. Trying again ' . self::fmt_time( $until ) . '.</span>';
				}
				$sum = WHM_Tracking::summary( $p->ID );
				if ( $sum['clickers'] ) {
					$progress .= sprintf( ', %d clicked', $sum['clickers'] );
				}
				if ( $sum['bounced'] ) {
					$progress .= sprintf( ', %d bounced', $sum['bounced'] );
				}
			} elseif ( 'scheduled' === $status ) {
				$progress = 'Goes out ' . self::fmt_time( get_post_meta( $p->ID, '_whm_send_at', true ) );
			}
			$err = get_post_meta( $p->ID, '_whm_last_error', true );
			if ( $err && in_array( $status, array( 'draft' ), true ) ) {
				$progress .= '<br><span class="whm-error">' . esc_html( $err ) . '</span>';
			}

			$actions = array();
			$actions[] = '<a href="' . esc_url( $edit ) . '">' . ( in_array( $status, array( 'draft', 'scheduled' ), true ) ? 'Edit' : 'View' ) . '</a>';
			if ( $total ) {
				$actions[] = '<a href="' . esc_url( self::url( 'whm-reports', array( 'campaign' => $p->ID ) ) ) . '">Report</a>';
			}
			$acts = self::campaign_actions_for( $status );
			if ( $stats['failed'] && in_array( $status, array( 'sending', 'paused', 'sent' ), true ) ) {
				$acts = array( 'retry' => sprintf( 'Retry failed (%d)', $stats['failed'] ) ) + $acts;
			}
			foreach ( $acts as $act => $label ) {
				$url       = wp_nonce_url( admin_url( 'admin-post.php?action=whm_campaign_action&do=' . $act . '&id=' . $p->ID ), 'whm_campaign_' . $p->ID );
				$confirm   = in_array( $act, array( 'delete', 'cancel' ), true ) ? ' onclick="return confirm(\'Are you sure?\');"' : '';
				if ( 'retry' === $act ) {
					$confirm = ' onclick="return confirm(' . esc_attr( wp_json_encode( sprintf( 'Re-send to %d %s who failed? People who already got this email won\'t get it again.', $stats['failed'], 1 === $stats['failed'] ? 'person' : 'people' ) ) ) . ');"';
				}
				$actions[] = '<a href="' . esc_url( $url ) . '"' . $confirm . '>' . esc_html( $label ) . '</a>';
			}

			echo '<tr><td><strong><a href="' . esc_url( $edit ) . '">' . esc_html( $p->post_title ? $p->post_title : '(untitled)' ) . '</a></strong><br><span class="description">' . esc_html( get_post_meta( $p->ID, '_whm_subject', true ) ) . '</span></td>'
				. '<td>' . self::status_badge( $status ) . '</td>' // phpcs:ignore WordPress.Security.EscapeOutput
				. '<td>' . esc_html( $seg_lbl ) . '</td>'
				. '<td>' . $progress . '</td>' // phpcs:ignore WordPress.Security.EscapeOutput
				. '<td>' . esc_html( get_the_modified_date( 'j M Y, H:i', $p ) ) . '</td>'
				. '<td class="whm-row-actions">' . implode( ' | ', $actions ) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput
		}
		echo '</tbody></table></div>';
	}

	private static function campaign_actions_for( $status ) {
		$a = array( 'duplicate' => 'Duplicate' );
		if ( 'sending' === $status ) {
			$a['pause'] = 'Pause';
		}
		if ( 'paused' === $status ) {
			$a['resume'] = 'Resume';
		}
		if ( in_array( $status, array( 'scheduled', 'sending', 'paused' ), true ) ) {
			$a['cancel'] = 'scheduled' === $status ? 'Unschedule' : 'Cancel';
		}
		if ( in_array( $status, array( 'draft', 'sent', 'cancelled' ), true ) ) {
			$a['delete'] = 'Delete';
		}
		return $a;
	}

	public static function post_campaign_action() {
		$id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
		self::check( 'whm_campaign_' . $id );
		$do  = isset( $_GET['do'] ) ? sanitize_key( $_GET['do'] ) : '';
		$msg = '';
		$err = '';
		if ( 'whm_campaign' !== get_post_type( $id ) ) {
			wp_die( 'Campaign not found.' );
		}
		switch ( $do ) {
			case 'duplicate':
				$new = self::copy_design( $id, 'whm_campaign', get_the_title( $id ) . ' (copy)' );
				update_post_meta( $new, '_whm_segment', (int) get_post_meta( $id, '_whm_segment', true ) );
				wp_safe_redirect( self::url( 'whm-edit', array( 'campaign' => $new ) ) );
				exit;
			case 'pause':
				WHM_Sender::pause( $id );
				$msg = 'Campaign paused.';
				break;
			case 'resume':
				$r = WHM_Sender::resume( $id );
				is_wp_error( $r ) ? $err = $r->get_error_message() : $msg = 'Sending resumed.';
				break;
			case 'cancel':
				WHM_Sender::cancel( $id );
				$msg = 'Campaign stopped.';
				break;
			case 'retry':
				$only = null;
				if ( isset( $_POST['only_error'] ) && '__all__' !== $_POST['only_error'] ) { // phpcs:ignore WordPress.Security.NonceVerification -- checked in self::check().
					$only = sanitize_text_field( wp_unslash( $_POST['only_error'] ) ); // phpcs:ignore WordPress.Security.NonceVerification
				}
				$r = WHM_Sender::retry_failed( $id, $only );
				if ( is_wp_error( $r ) ) {
					$err = $r->get_error_message();
				} else {
					$msg = sprintf( '%d %s back in the queue and will be sent shortly.', $r['requeued'], 1 === $r['requeued'] ? 'person is' : 'people are' );
					if ( $r['skipped'] ) {
						$msg .= sprintf( ' %d skipped because they have since unsubscribed or bounced.', $r['skipped'] );
					}
				}
				if ( isset( $_REQUEST['back'] ) && 'report' === $_REQUEST['back'] ) { // phpcs:ignore WordPress.Security.NonceVerification
					wp_safe_redirect( self::url( 'whm-reports', array_filter( array( 'campaign' => $id, 'whm_msg' => $msg, 'whm_err' => $err ) ) ) );
					exit;
				}
				break;
			case 'delete':
				if ( in_array( WHM_Sender::status( $id ), array( 'draft', 'sent', 'cancelled' ), true ) ) {
					wp_delete_post( $id, true );
					$msg = 'Campaign deleted.';
				}
				break;
		}
		wp_safe_redirect( self::url( 'whm', array_filter( array( 'whm_msg' => $msg, 'whm_err' => $err ) ) ) );
		exit;
	}

	/**
	 * Copy subject, preheader and blocks from a campaign or template into a new post.
	 */
	private static function copy_design( $from_id, $to_type, $title ) {
		$new = wp_insert_post(
			array(
				'post_type'   => $to_type,
				'post_status' => 'publish',
				'post_title'  => $title,
			)
		);
		foreach ( array( '_whm_subject', '_whm_preheader', '_whm_blocks' ) as $k ) {
			update_post_meta( $new, $k, wp_slash( (string) get_post_meta( $from_id, $k, true ) ) );
		}
		if ( 'whm_campaign' === $to_type ) {
			update_post_meta( $new, '_whm_status', 'draft' );
		}
		return $new;
	}

	/* ------------------------------------------------------------------ Designer */

	public static function page_designer() {
		$campaign_id = isset( $_GET['campaign'] ) ? absint( $_GET['campaign'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification
		$template_id = isset( $_GET['template'] ) ? absint( $_GET['template'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification
		$from_tpl    = isset( $_GET['from_template'] ) ? absint( $_GET['from_template'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification

		$mode = ( $template_id || ! empty( $_GET['new_template'] ) ) ? 'template' : 'campaign'; // phpcs:ignore WordPress.Security.NonceVerification
		$id   = $template_id ? $template_id : $campaign_id;
		if ( $id && get_post_type( $id ) !== ( 'template' === $mode ? 'whm_template' : 'whm_campaign' ) ) {
			wp_die( 'Not found.' );
		}

		$source = $id ? $id : $from_tpl;
		$blocks = $source ? json_decode( (string) get_post_meta( $source, '_whm_blocks', true ), true ) : null;
		if ( ! is_array( $blocks ) ) {
			$blocks = array(
				array( 'type' => 'heading', 'text' => 'Your headline', 'size' => 'large', 'align' => 'left' ),
				array( 'type' => 'text', 'html' => '<p>Hi {first_name},</p><p>Start writing here.</p>', 'align' => 'left' ),
				array( 'type' => 'signoff', 'text' => WHM_Settings::signoff() ),
			);
		}
		$status   = 'campaign' === $mode && $id ? WHM_Sender::status( $id ) : 'draft';
		$readonly = ! in_array( $status, array( 'draft', 'scheduled' ), true );

		$segments = array();
		foreach ( get_posts( array( 'post_type' => 'whm_segment', 'post_status' => 'publish', 'posts_per_page' => -1, 'orderby' => 'title', 'order' => 'ASC' ) ) as $sg ) {
			$segments[] = array(
				'id'    => $sg->ID,
				'title' => $sg->post_title,
			);
		}
		$send_at = $id ? (int) get_post_meta( $id, '_whm_send_at', true ) : 0;

		$data = array(
			'ajax'        => admin_url( 'admin-ajax.php' ),
			'nonce'       => wp_create_nonce( 'whm_ajax' ),
			'mode'        => $mode,
			'id'          => $id,
			'title'       => $id ? get_the_title( $id ) : ( $from_tpl ? get_the_title( $from_tpl ) : '' ),
			'subject'     => $source ? (string) get_post_meta( $source, '_whm_subject', true ) : '',
			'preheader'   => $source ? (string) get_post_meta( $source, '_whm_preheader', true ) : '',
			'segment'     => 'campaign' === $mode && $id ? (int) get_post_meta( $id, '_whm_segment', true ) : 0,
			'segments'    => $segments,
			'blocks'      => WHM_Renderer::sanitize_blocks( $blocks ),
			'status'      => $status,
			'readonly'    => $readonly,
			'liveSending' => (bool) WHM_Settings::get( 'live_sending' ),
			'sendAt'      => $send_at ? wp_date( 'Y-m-d\TH:i', $send_at ) : '',
			'testEmail'   => wp_get_current_user()->user_email,
			'signoff'     => WHM_Settings::signoff(),
			'urls'        => array(
				'campaigns' => self::url( 'whm' ),
				'templates' => self::url( 'whm-templates' ),
				'segments'  => self::url( 'whm-segments' ),
				'settings'  => self::url( 'whm-settings' ),
				'edit'      => self::url( 'whm-edit' ),
			),
			'brand'       => array(
				'accent' => WHM_Settings::get( 'color_accent' ),
			),
		);

		$title = 'template' === $mode ? ( $id ? 'Edit template' : 'New template' ) : ( $id ? 'Edit campaign' : 'New campaign' );
		self::header( $title );
		echo '<script>window.WHM = ' . wp_json_encode( $data ) . ';</script>';
		echo '<div id="whm-designer" class="whm-designer" aria-live="polite"><p>Loading the designer…</p></div></div>';
	}

	private static function read_design_from_request() {
		$blocks = isset( $_POST['blocks'] ) ? json_decode( wp_unslash( $_POST['blocks'] ), true ) : array(); // phpcs:ignore WordPress.Security.NonceVerification, WordPress.Security.ValidatedSanitizedInput
		return array(
			'title'     => isset( $_POST['title'] ) ? sanitize_text_field( wp_unslash( $_POST['title'] ) ) : '', // phpcs:ignore WordPress.Security.NonceVerification
			'subject'   => isset( $_POST['subject'] ) ? sanitize_text_field( wp_unslash( $_POST['subject'] ) ) : '', // phpcs:ignore WordPress.Security.NonceVerification
			'preheader' => isset( $_POST['preheader'] ) ? sanitize_text_field( wp_unslash( $_POST['preheader'] ) ) : '', // phpcs:ignore WordPress.Security.NonceVerification
			'segment'   => isset( $_POST['segment'] ) ? absint( $_POST['segment'] ) : 0, // phpcs:ignore WordPress.Security.NonceVerification
			'blocks'    => WHM_Renderer::sanitize_blocks( is_array( $blocks ) ? $blocks : array() ),
		);
	}

	/**
	 * Save a campaign or template. Returns the post id.
	 */
	private static function save_design( $mode, $id, array $d ) {
		$type = 'template' === $mode ? 'whm_template' : 'whm_campaign';
		if ( $id ) {
			if ( get_post_type( $id ) !== $type ) {
				return new WP_Error( 'whm_nf', 'Not found.' );
			}
			if ( 'whm_campaign' === $type && ! in_array( WHM_Sender::status( $id ), array( 'draft', 'scheduled' ), true ) ) {
				return new WP_Error( 'whm_locked', 'This campaign has been sent, so it can no longer be changed. Duplicate it to make a new version.' );
			}
			wp_update_post(
				array(
					'ID'         => $id,
					'post_title' => $d['title'] ? $d['title'] : ( $d['subject'] ? $d['subject'] : 'Untitled email' ),
				)
			);
		} else {
			$id = wp_insert_post(
				array(
					'post_type'   => $type,
					'post_status' => 'publish',
					'post_title'  => $d['title'] ? $d['title'] : ( $d['subject'] ? $d['subject'] : 'Untitled email' ),
				)
			);
			if ( is_wp_error( $id ) ) {
				return $id;
			}
			if ( 'whm_campaign' === $type ) {
				update_post_meta( $id, '_whm_status', 'draft' );
			}
		}
		update_post_meta( $id, '_whm_subject', $d['subject'] );
		update_post_meta( $id, '_whm_preheader', $d['preheader'] );
		update_post_meta( $id, '_whm_blocks', wp_slash( wp_json_encode( $d['blocks'] ) ) );
		if ( 'whm_campaign' === $type ) {
			update_post_meta( $id, '_whm_segment', $d['segment'] );
			delete_post_meta( $id, '_whm_last_error' );
		}
		return (int) $id;
	}

	public static function ajax_save() {
		self::ajax_check();
		$mode = isset( $_POST['mode'] ) && 'template' === $_POST['mode'] ? 'template' : 'campaign';
		$id   = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		$d    = self::read_design_from_request();

		// "Save as template" from the campaign designer always creates a new template.
		if ( ! empty( $_POST['as_template'] ) ) {
			$d['title'] = isset( $_POST['template_name'] ) ? sanitize_text_field( wp_unslash( $_POST['template_name'] ) ) : $d['title'];
			$new        = self::save_design( 'template', 0, $d );
			is_wp_error( $new ) ? wp_send_json_error( array( 'message' => $new->get_error_message() ) ) : wp_send_json_success( array( 'template' => $new, 'message' => 'Saved as a template.' ) );
		}

		$saved = self::save_design( $mode, $id, $d );
		if ( is_wp_error( $saved ) ) {
			wp_send_json_error( array( 'message' => $saved->get_error_message() ) );
		}
		wp_send_json_success(
			array(
				'id'      => $saved,
				'message' => 'Saved.',
				'editUrl' => self::url( 'whm-edit', array( 'template' === $mode ? 'template' : 'campaign' => $saved ) ),
			)
		);
	}

	public static function ajax_preview() {
		self::ajax_check();
		$d       = self::read_design_from_request();
		$contact = (object) array(
			'email'      => 'alex@example.com',
			'first_name' => 'Alex',
			'last_name'  => 'Example',
			'status'     => 'subscribed',
			'token'      => '',
		);
		wp_send_json_success(
			array(
				'html'    => WHM_Renderer::render( $d['blocks'], $d, $contact, 0 ),
				'subject' => strtr( $d['subject'], WHM_Renderer::tags( $contact ) ),
			)
		);
	}

	public static function ajax_test() {
		self::ajax_check();
		$d      = self::read_design_from_request();
		$emails = isset( $_POST['emails'] ) ? array_filter( array_map( 'trim', explode( ',', sanitize_text_field( wp_unslash( $_POST['emails'] ) ) ) ) ) : array();
		if ( ! $emails ) {
			wp_send_json_error( array( 'message' => 'Enter an email address for the test.' ) );
		}
		if ( '' === trim( $d['subject'] ) ) {
			wp_send_json_error( array( 'message' => 'Add a subject line first.' ) );
		}
		$res = WHM_Sender::send_test( $d, $emails );
		if ( ! $res ) {
			wp_send_json_error( array( 'message' => 'No valid email addresses.' ) );
		}
		$failed = array_filter( $res, fn( $r ) => 'sent' !== $r );
		if ( $failed ) {
			wp_send_json_error( array( 'message' => 'Test failed: ' . implode( '; ', array_map( fn( $e, $r ) => $e . ' (' . $r . ')', array_keys( $failed ), $failed ) ) ) );
		}
		wp_send_json_success( array( 'message' => 'Test sent to ' . implode( ', ', array_keys( $res ) ) . '.' ) );
	}

	public static function ajax_audience() {
		self::ajax_check();
		$seg = isset( $_POST['segment'] ) ? absint( $_POST['segment'] ) : 0;
		if ( ! $seg || 'whm_segment' !== get_post_type( $seg ) ) {
			wp_send_json_success( array( 'count' => 0, 'held' => 0, 'describe' => '' ) );
		}
		$def   = WHM_Segments::get_definition( $seg );
		$count = WHM_Segments::count( $def );
		wp_send_json_success(
			array(
				'count'    => $count,
				'held'     => max( 0, WHM_Segments::count_all( $def ) - $count ),
				'describe' => WHM_Segments::describe( $def ),
			)
		);
	}

	public static function ajax_send() {
		self::ajax_check();
		$id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		$d  = self::read_design_from_request();
		$id = self::save_design( 'campaign', $id, $d );
		if ( is_wp_error( $id ) ) {
			wp_send_json_error( array( 'message' => $id->get_error_message() ) );
		}
		$when = 0;
		if ( ! empty( $_POST['send_at'] ) ) {
			try {
				$dt   = new DateTime( sanitize_text_field( wp_unslash( $_POST['send_at'] ) ), wp_timezone() );
				$when = $dt->getTimestamp();
			} catch ( Exception $e ) {
				wp_send_json_error( array( 'message' => 'That send time doesn\'t look right.' ) );
			}
			if ( $when < time() ) {
				wp_send_json_error( array( 'message' => 'Pick a time in the future, or send now.' ) );
			}
		}
		$r = WHM_Sender::launch( $id, $when );
		if ( is_wp_error( $r ) ) {
			wp_send_json_error( array( 'message' => $r->get_error_message(), 'id' => $id ) );
		}
		$msg = 'scheduled' === $r ? 'Scheduled for ' . wp_date( 'j M Y, H:i', $when ) . '.' : 'Sending has started. Emails go out in batches; you can follow progress on the Campaigns page.';
		wp_send_json_success(
			array(
				'message'  => $msg,
				'id'       => $id,
				'redirect' => self::url( 'whm', array( 'whm_msg' => $msg ) ),
			)
		);
	}

	public static function ajax_products() {
		self::ajax_check();
		$term = isset( $_GET['q'] ) ? sanitize_text_field( wp_unslash( $_GET['q'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$ids  = isset( $_GET['ids'] ) ? array_filter( array_map( 'absint', explode( ',', sanitize_text_field( wp_unslash( $_GET['ids'] ) ) ) ) ) : array(); // phpcs:ignore WordPress.Security.NonceVerification
		$args = array(
			'post_type'      => 'product',
			'post_status'    => 'publish',
			'posts_per_page' => 20,
			'orderby'        => 'title',
			'order'          => 'ASC',
		);
		if ( $ids ) {
			$args['post__in'] = $ids;
		} elseif ( $term ) {
			$args['s'] = $term;
		}
		$out = array();
		foreach ( get_posts( $args ) as $p ) {
			$out[] = array(
				'id'    => $p->ID,
				'title' => $p->post_title,
			);
		}
		wp_send_json_success( $out );
	}

	/* ------------------------------------------------------------------ Templates */

	public static function page_templates() {
		$new = '<a href="' . esc_url( self::url( 'whm-edit', array( 'template' => 0, 'new_template' => 1 ) ) ) . '" class="page-title-action">New template</a>';
		self::header( 'Email templates', $new );
		echo '<p class="description">Templates are saved designs. Use one to start a campaign; the template itself stays as it is.</p>';
		$posts = get_posts(
			array(
				'post_type'      => 'whm_template',
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'orderby'        => 'title',
				'order'          => 'ASC',
			)
		);
		echo '<div class="whm-cards">';
		foreach ( $posts as $p ) {
			$use  = wp_nonce_url( admin_url( 'admin-post.php?action=whm_template_action&do=use&id=' . $p->ID ), 'whm_template_' . $p->ID );
			$dup  = wp_nonce_url( admin_url( 'admin-post.php?action=whm_template_action&do=duplicate&id=' . $p->ID ), 'whm_template_' . $p->ID );
			$del  = wp_nonce_url( admin_url( 'admin-post.php?action=whm_template_action&do=delete&id=' . $p->ID ), 'whm_template_' . $p->ID );
			$edit = self::url( 'whm-edit', array( 'template' => $p->ID ) );
			echo '<div class="whm-card"><h3>' . esc_html( $p->post_title ) . '</h3><p class="description">' . esc_html( get_post_meta( $p->ID, '_whm_subject', true ) ) . '</p>'
				. '<p><a class="button button-primary" href="' . esc_url( $use ) . '">Use for a campaign</a> <a class="button" href="' . esc_url( $edit ) . '">Edit</a></p>'
				. '<p class="whm-card-links"><a href="' . esc_url( $dup ) . '">Duplicate</a> | <a class="whm-danger" href="' . esc_url( $del ) . '" onclick="return confirm(\'Delete this template?\');">Delete</a></p></div>';
		}
		echo '</div></div>';
	}

	public static function post_template_action() {
		$id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
		self::check( 'whm_template_' . $id );
		if ( 'whm_template' !== get_post_type( $id ) ) {
			wp_die( 'Template not found.' );
		}
		$do = isset( $_GET['do'] ) ? sanitize_key( $_GET['do'] ) : '';
		if ( 'use' === $do ) {
			$new = self::copy_design( $id, 'whm_campaign', get_the_title( $id ) );
			wp_safe_redirect( self::url( 'whm-edit', array( 'campaign' => $new ) ) );
			exit;
		}
		if ( 'duplicate' === $do ) {
			$new = self::copy_design( $id, 'whm_template', get_the_title( $id ) . ' (copy)' );
			wp_safe_redirect( self::url( 'whm-edit', array( 'template' => $new ) ) );
			exit;
		}
		if ( 'delete' === $do ) {
			wp_delete_post( $id, true );
		}
		wp_safe_redirect( self::url( 'whm-templates', array( 'whm_msg' => 'Template deleted.' ) ) );
		exit;
	}

	/* ------------------------------------------------------------------ Segments */

	public static function page_segments() {
		$seg_id = isset( $_GET['segment'] ) ? absint( $_GET['segment'] ) : -1; // phpcs:ignore WordPress.Security.NonceVerification
		if ( $seg_id >= 0 ) {
			self::segment_editor( $seg_id );
			return;
		}
		$new = '<a href="' . esc_url( self::url( 'whm-segments', array( 'segment' => 0 ) ) ) . '" class="page-title-action">New segment</a>';
		self::header( 'Customer segments', $new );
		echo '<p class="description">A segment is a saved filter over your customers and subscribers. Counts only include people you\'re allowed to email.</p>';
		$posts = get_posts(
			array(
				'post_type'      => 'whm_segment',
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'orderby'        => 'title',
				'order'          => 'ASC',
			)
		);
		echo '<table class="widefat striped whm-table"><thead><tr><th>Segment</th><th>Rules</th><th>Can email</th><th></th></tr></thead><tbody>';
		foreach ( $posts as $p ) {
			$def  = WHM_Segments::get_definition( $p->ID );
			$edit = self::url( 'whm-segments', array( 'segment' => $p->ID ) );
			$del  = wp_nonce_url( admin_url( 'admin-post.php?action=whm_segment_action&do=delete&id=' . $p->ID ), 'whm_segment_' . $p->ID );
			echo '<tr><td><strong><a href="' . esc_url( $edit ) . '">' . esc_html( $p->post_title ) . '</a></strong></td><td>' . esc_html( WHM_Segments::describe( $def ) ) . '</td>'
				. '<td>' . (int) WHM_Segments::count( $def ) . '</td>'
				. '<td><a href="' . esc_url( $edit ) . '">Edit</a> | <a class="whm-danger" href="' . esc_url( $del ) . '" onclick="return confirm(\'Delete this segment?\');">Delete</a></td></tr>';
		}
		echo '</tbody></table></div>';
	}

	/**
	 * Campaigns that have started sending, for the "was sent campaign" segment filter.
	 */
	private static function sent_campaign_choices() {
		$out = array();
		foreach ( get_posts(
			array(
				'post_type'      => 'whm_campaign',
				'post_status'    => 'any',
				'posts_per_page' => 200,
				'orderby'        => 'date',
				'order'          => 'DESC',
			)
		) as $p ) {
			$status = get_post_meta( $p->ID, '_whm_status', true );
			if ( ! in_array( $status, array( 'sending', 'sent', 'paused', 'cancelled' ), true ) ) {
				continue;
			}
			$out[] = array(
				'id'    => $p->ID,
				'title' => ( $p->post_title ? $p->post_title : '(untitled)' ) . ' (' . $status . ')',
			);
		}
		return $out;
	}

	private static function segment_editor( $seg_id ) {
		if ( $seg_id && 'whm_segment' !== get_post_type( $seg_id ) ) {
			wp_die( 'Segment not found.' );
		}
		$def = $seg_id ? WHM_Segments::get_definition( $seg_id ) : array(
			'match' => 'all',
			'rules' => array( array( 'type' => 'purchased_within', 'value' => 3, 'unit' => 'months' ) ),
		);
		$cats = array();
		foreach ( get_terms( array( 'taxonomy' => 'product_cat', 'hide_empty' => false ) ) as $t ) {
			$cats[] = array(
				'id'    => $t->term_id,
				'title' => $t->name,
			);
		}
		$data = array(
			'ajax'  => admin_url( 'admin-ajax.php' ),
			'nonce' => wp_create_nonce( 'whm_ajax' ),
			'types' => WHM_Segments::rule_types(),
			'def'   => $def,
			'cats'  => $cats,
			'currency' => html_entity_decode( get_woocommerce_currency_symbol(), ENT_QUOTES, 'UTF-8' ),
			'campaigns' => self::sent_campaign_choices(),
		);
		self::header( $seg_id ? 'Edit segment' : 'New segment' );
		echo '<script>window.WHMSEG = ' . wp_json_encode( $data ) . ';</script>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" id="whm-seg-form" class="whm-seg">';
		wp_nonce_field( 'whm_save_segment' );
		echo '<input type="hidden" name="action" value="whm_save_segment"><input type="hidden" name="id" value="' . (int) $seg_id . '"><input type="hidden" name="def" id="whm-seg-def" value="">';
		echo '<p><label for="whm-seg-title"><strong>Segment name</strong></label><br><input type="text" class="regular-text" id="whm-seg-title" name="title" required value="' . esc_attr( $seg_id ? get_the_title( $seg_id ) : '' ) . '" placeholder="e.g. Regulars who haven\'t subscribed"></p>';
		echo '<div class="whm-seg-builder"><div id="whm-seg-rules"></div></div>';
		echo '<div class="whm-seg-result"><h3>Who matches</h3><div id="whm-seg-count" aria-live="polite">…</div><table class="widefat striped" id="whm-seg-preview"><thead><tr><th>Email</th><th>Name</th><th>Status</th><th>Orders</th><th>Last order</th><th>Spent</th></tr></thead><tbody></tbody></table></div>';
		echo '<p class="submit"><button type="submit" class="button button-primary">Save segment</button> <a class="button" href="' . esc_url( self::url( 'whm-segments' ) ) . '">Back</a></p></form></div>';
	}

	public static function ajax_segment_preview() {
		self::ajax_check();
		$def   = isset( $_POST['def'] ) ? json_decode( wp_unslash( $_POST['def'] ), true ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$def   = WHM_Segments::sanitize_definition( is_array( $def ) ? $def : array() );
		$count = WHM_Segments::count( $def );
		$rows  = array();
		foreach ( WHM_Segments::preview( $def, 25 ) as $r ) {
			$rows[] = array(
				'email'  => $r->email,
				'name'   => trim( $r->first_name . ' ' . $r->last_name ),
				'status' => $r->status,
				'orders' => (int) $r->orders,
				'last'   => $r->last_order ? wp_date( 'j M Y', strtotime( $r->last_order . ' UTC' ) ) : '—',
				'spent'  => html_entity_decode( wp_strip_all_tags( wc_price( $r->spent ) ), ENT_QUOTES, 'UTF-8' ),
			);
		}
		wp_send_json_success(
			array(
				'count'    => $count,
				'held'     => max( 0, WHM_Segments::count_all( $def ) - $count ),
				'rows'     => $rows,
				'describe' => WHM_Segments::describe( $def ),
				'softOptIn' => (bool) WHM_Settings::get( 'soft_opt_in' ),
			)
		);
	}

	public static function post_save_segment() {
		self::check( 'whm_save_segment' );
		$id    = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		$title = isset( $_POST['title'] ) ? sanitize_text_field( wp_unslash( $_POST['title'] ) ) : 'Untitled segment';
		$def   = isset( $_POST['def'] ) ? json_decode( wp_unslash( $_POST['def'] ), true ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$def   = WHM_Segments::sanitize_definition( is_array( $def ) ? $def : array() );
		if ( $id && 'whm_segment' === get_post_type( $id ) ) {
			wp_update_post( array( 'ID' => $id, 'post_title' => $title ) );
		} else {
			$id = wp_insert_post( array( 'post_type' => 'whm_segment', 'post_status' => 'publish', 'post_title' => $title ) );
		}
		update_post_meta( $id, '_whm_rules', wp_slash( wp_json_encode( $def ) ) );
		wp_safe_redirect( self::url( 'whm-segments', array( 'whm_msg' => 'Segment saved.' ) ) );
		exit;
	}

	public static function post_segment_action() {
		$id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
		self::check( 'whm_segment_' . $id );
		if ( 'whm_segment' === get_post_type( $id ) && isset( $_GET['do'] ) && 'delete' === $_GET['do'] ) {
			wp_delete_post( $id, true );
		}
		wp_safe_redirect( self::url( 'whm-segments', array( 'whm_msg' => 'Segment deleted.' ) ) );
		exit;
	}

	/* ------------------------------------------------------------------ Contacts */

	public static function page_contacts() {
		self::header( 'Contacts' );
		$status = isset( $_GET['status'] ) ? sanitize_key( $_GET['status'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$paged  = isset( $_GET['paged'] ) ? absint( $_GET['paged'] ) : 1; // phpcs:ignore WordPress.Security.NonceVerification
		$counts = WHM_Contacts::counts();
		$res    = WHM_Contacts::query(
			array(
				'status'   => $status,
				'search'   => $search,
				'page'     => $paged,
				'per_page' => 50,
			)
		);

		$links = array( '' => 'All' ) + array(
			'subscribed'   => 'Subscribed',
			'customer'     => 'Customers (soft opt-in)',
			'unsubscribed' => 'Unsubscribed',
			'bounced'      => 'Bounced',
		);
		echo '<ul class="subsubsub">';
		$out = array();
		foreach ( $links as $k => $label ) {
			$n     = '' === $k ? $counts['all'] : $counts[ $k ];
			$out[] = '<li><a href="' . esc_url( self::url( 'whm-contacts', array_filter( array( 'status' => $k ) ) ) ) . '"' . ( $status === $k ? ' class="current"' : '' ) . '>' . esc_html( $label ) . ' <span class="count">(' . (int) $n . ')</span></a>';
		}
		echo implode( ' |</li>', $out ) . '</li></ul>'; // phpcs:ignore WordPress.Security.EscapeOutput

		echo '<form method="get" class="whm-search"><input type="hidden" name="page" value="whm-contacts">' . ( $status ? '<input type="hidden" name="status" value="' . esc_attr( $status ) . '">' : '' )
			. '<label class="screen-reader-text" for="whm-s">Search contacts</label><input type="search" id="whm-s" name="s" value="' . esc_attr( $search ) . '" placeholder="Search name or email"> <button class="button">Search</button></form>';

		echo '<table class="widefat striped whm-table"><thead><tr><th>Email</th><th>Name</th><th>Status</th><th>Orders</th><th>Last order</th><th>Spent</th><th>Consent record</th><th></th></tr></thead><tbody>';
		if ( ! $res['items'] ) {
			echo '<tr><td colspan="8">No contacts found.</td></tr>';
		}
		foreach ( $res['items'] as $c ) {
			$acts = array();
			if ( 'unsubscribed' !== $c->status ) {
				$acts['unsubscribe'] = 'Unsubscribe';
			}
			if ( 'bounced' !== $c->status ) {
				$acts['bounced'] = 'Mark bounced';
			}
			$acts['delete'] = 'Delete';
			$links          = array();
			foreach ( $acts as $a => $label ) {
				$u       = wp_nonce_url( admin_url( 'admin-post.php?action=whm_contact&do=' . $a . '&id=' . $c->id ), 'whm_contact_' . $c->id );
				$links[] = '<a href="' . esc_url( $u ) . '"' . ( 'delete' === $a ? ' class="whm-danger" onclick="return confirm(\'Delete this contact and their consent record?\');"' : '' ) . '>' . esc_html( $label ) . '</a>';
			}
			$consent = $c->consent_at ? esc_html( wp_date( 'j M Y', strtotime( $c->consent_at . ' UTC' ) ) . ' · ' . $c->source ) . ( $c->consent_text ? '<br><span class="description">' . esc_html( $c->consent_text ) . '</span>' : '' ) : esc_html( $c->source );
			if ( $c->unsubscribed_at ) {
				$consent .= '<br><span class="description">Unsubscribed ' . esc_html( wp_date( 'j M Y', strtotime( $c->unsubscribed_at . ' UTC' ) ) ) . '</span>';
			}
			echo '<tr><td>' . esc_html( $c->email ) . '</td><td>' . esc_html( trim( $c->first_name . ' ' . $c->last_name ) ) . '</td><td>' . self::status_badge( $c->status ) . '</td>' // phpcs:ignore WordPress.Security.EscapeOutput
				. '<td>' . (int) $c->orders . '</td><td>' . ( $c->last_order ? esc_html( wp_date( 'j M Y', strtotime( $c->last_order . ' UTC' ) ) ) : '—' ) . '</td><td>' . wp_kses_post( wc_price( $c->spent ) ) . '</td>'
				. '<td class="whm-consent">' . $consent . '</td><td class="whm-row-actions">' . implode( ' | ', $links ) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput
		}
		echo '</tbody></table>';

		if ( $res['pages'] > 1 ) {
			echo '<div class="tablenav"><div class="tablenav-pages">' . paginate_links( // phpcs:ignore WordPress.Security.EscapeOutput
				array(
					'base'    => add_query_arg( 'paged', '%#%' ),
					'format'  => '',
					'current' => $paged,
					'total'   => $res['pages'],
				)
			) . '</div></div>';
		}

		$last_sync = (int) get_option( 'whm_last_sync' );
		echo '<div class="whm-cards whm-tools">';

		echo '<div class="whm-card"><h3>Add someone who opted in</h3><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'whm_add_contact' );
		echo '<input type="hidden" name="action" value="whm_add_contact">'
			. '<p><label>Email<br><input type="email" name="email" required class="regular-text"></label></p>'
			. '<p><label>First name<br><input type="text" name="first_name" class="regular-text"></label></p>'
			. '<p><label>How did they agree?<br><input type="text" name="note" class="regular-text" placeholder="e.g. Signed up on paper at the café, 12 Nov"></label></p>'
			. '<p><button class="button button-primary">Add as subscribed</button></p></form></div>';

		echo '<div class="whm-card"><h3>Import a list (CSV)</h3><form method="post" enctype="multipart/form-data" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'whm_import' );
		echo '<input type="hidden" name="action" value="whm_import">'
			. '<p>Needs an <code>email</code> column. <code>first_name</code>, <code>last_name</code> or <code>name</code> are used if present.</p>'
			. '<p class="description">Moving from another shop? A WooCommerce Customers export (Analytics → Customers → Download) works as-is: its Orders, Total Spend and Last Active columns are saved as purchase history, so segments like "2+ orders" include it.</p>'
			. '<p><input type="file" name="csv" accept=".csv,text/csv" required></p>'
			. '<fieldset><legend><strong>These people…</strong></legend>'
			. '<label><input type="radio" name="mode" value="subscribed" checked> agreed to receive marketing emails</label><br>'
			. '<label><input type="radio" name="mode" value="customer"> are past customers who were not asked (soft opt-in)</label></fieldset>'
			. '<p><label>Where the list came from<br><input type="text" name="note" required class="regular-text" placeholder="e.g. Old website newsletter sign-ups"></label></p>'
			. '<p class="description">Anyone who has already unsubscribed stays unsubscribed.</p>'
			. '<p><button class="button button-primary">Import</button></p></form></div>';

		echo '<div class="whm-card"><h3>WooCommerce customers</h3><p>Everyone who places a paid order is added automatically as a customer. Run this to pull in past orders.</p>'
			. ( $last_sync ? '<p class="description">Last full sync: ' . self::fmt_time( $last_sync ) . '</p>' : '' )
			. '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'whm_sync' );
		echo '<input type="hidden" name="action" value="whm_sync"><button class="button">Sync customers now</button></form></div>';

		echo '</div></div>';
	}

	public static function post_contact() {
		$id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
		self::check( 'whm_contact_' . $id );
		$do = isset( $_GET['do'] ) ? sanitize_key( $_GET['do'] ) : '';
		if ( 'unsubscribe' === $do ) {
			WHM_Contacts::unsubscribe( $id );
		} elseif ( 'bounced' === $do ) {
			WHM_Contacts::set_status( $id, 'bounced' );
		} elseif ( 'delete' === $do ) {
			WHM_Contacts::delete( $id );
		}
		wp_safe_redirect( wp_get_referer() ? wp_get_referer() : self::url( 'whm-contacts' ) );
		exit;
	}

	public static function post_add_contact() {
		self::check( 'whm_add_contact' );
		$email = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
		$note  = isset( $_POST['note'] ) ? sanitize_text_field( wp_unslash( $_POST['note'] ) ) : '';
		if ( ! is_email( $email ) ) {
			wp_safe_redirect( self::url( 'whm-contacts', array( 'whm_err' => 'That email address doesn\'t look right.' ) ) );
			exit;
		}
		WHM_Contacts::subscribe(
			$email,
			array(
				'first_name'   => isset( $_POST['first_name'] ) ? sanitize_text_field( wp_unslash( $_POST['first_name'] ) ) : '',
				'source'       => 'admin',
				'consent_text' => 'Added by ' . wp_get_current_user()->display_name . ( $note ? ': ' . $note : '' ),
			)
		);
		wp_safe_redirect( self::url( 'whm-contacts', array( 'whm_msg' => $email . ' added as subscribed.' ) ) );
		exit;
	}

	public static function post_import() {
		self::check( 'whm_import' );
		if ( empty( $_FILES['csv']['tmp_name'] ) || ! is_uploaded_file( $_FILES['csv']['tmp_name'] ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			wp_safe_redirect( self::url( 'whm-contacts', array( 'whm_err' => 'Choose a CSV file to import.' ) ) );
			exit;
		}
		$mode = isset( $_POST['mode'] ) && 'customer' === $_POST['mode'] ? 'customer' : 'subscribed';
		$note = isset( $_POST['note'] ) ? sanitize_text_field( wp_unslash( $_POST['note'] ) ) : '';
		$note = 'Imported ' . wp_date( 'j M Y' ) . ' by ' . wp_get_current_user()->display_name . ( $note ? ': ' . $note : '' );
		$r    = WHM_Contacts::import_csv( $_FILES['csv']['tmp_name'], $mode, $note ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		if ( is_wp_error( $r ) ) {
			wp_safe_redirect( self::url( 'whm-contacts', array( 'whm_err' => $r->get_error_message() ) ) );
			exit;
		}
		$msg = sprintf( 'Import done: %d added, %d updated, %d left alone because they had unsubscribed, %d invalid rows.', $r['added'], $r['updated'], $r['skipped'], $r['invalid'] );
		if ( $r['history'] ) {
			$msg .= sprintf( ' Purchase history saved for %d people.', $r['history'] );
		}
		if ( $r['no_buy'] ) {
			$msg .= sprintf( ' %d had never placed an order, so they weren\'t added as customers.', $r['no_buy'] );
		}
		wp_safe_redirect( self::url( 'whm-contacts', array( 'whm_msg' => $msg ) ) );
		exit;
	}

	public static function post_sync() {
		self::check( 'whm_sync' );
		$n   = WHM_Contacts::sync_customers( 0 );
		$msg = 300 === $n ? 'Sync started. Large order histories finish in the background over the next few minutes.' : 'Customers synced from WooCommerce orders.';
		wp_safe_redirect( self::url( 'whm-contacts', array( 'whm_msg' => $msg ) ) );
		exit;
	}

	/* ------------------------------------------------------------------ Settings */

	/* ------------------------------------------------------------------ Reports */

	private static function pct( $n, $of ) {
		return $of ? round( 100 * $n / $of, 1 ) . '%' : '–';
	}

	private static function money( $v ) {
		return wp_strip_all_tags( html_entity_decode( wc_price( $v ), ENT_QUOTES, 'UTF-8' ) );
	}

	public static function page_reports() {
		$id = isset( $_GET['campaign'] ) ? absint( $_GET['campaign'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification
		if ( $id && 'whm_campaign' === get_post_type( $id ) ) {
			self::report_detail( $id );
			return;
		}
		self::header( 'Campaign reports' );
		$posts = get_posts(
			array(
				'post_type'      => 'whm_campaign',
				'post_status'    => 'any',
				'posts_per_page' => 100,
				'meta_key'       => '_whm_started', // phpcs:ignore WordPress.DB.SlowDBQuery
				'orderby'        => 'meta_value_num',
				'order'          => 'DESC',
			)
		);
		if ( ! $posts ) {
			echo '<div class="whm-empty"><h2>No reports yet</h2><p>Once a campaign has been sent, its results show here: deliveries, bounces, unsubscribes, clicks and the orders that followed.</p></div></div>';
			return;
		}
		echo '<table class="widefat striped whm-table"><thead><tr><th>Campaign</th><th>Sent</th><th>Delivered</th><th>Bounced</th><th>Unsubscribed</th><th>Clicked</th><th>Orders</th><th>Revenue</th></tr></thead><tbody>';
		foreach ( $posts as $p ) {
			$r       = WHM_Tracking::report( $p->ID );
			$bounced = $r['delivery']['hard_bounce'] + $r['delivery']['soft_bounce'];
			echo '<tr><td><strong><a href="' . esc_url( self::url( 'whm-reports', array( 'campaign' => $p->ID ) ) ) . '">' . esc_html( $p->post_title ? $p->post_title : '(untitled)' ) . '</a></strong><br><span class="description">' . self::fmt_time( get_post_meta( $p->ID, '_whm_started', true ) ) . '</span></td>' // phpcs:ignore WordPress.Security.EscapeOutput
				. '<td>' . (int) $r['sent'] . '</td>'
				. '<td>' . ( $r['delivery_known'] ? (int) $r['delivery']['delivered'] : '–' ) . '</td>'
				. '<td>' . ( $r['delivery_known'] ? $bounced . ' <span class="description">(' . esc_html( self::pct( $bounced, $r['sent'] ) ) . ')</span>' : '–' ) . '</td>'
				. '<td>' . (int) $r['unsubscribed'] . ' <span class="description">(' . esc_html( self::pct( $r['unsubscribed'], $r['sent'] ) ) . ')</span></td>'
				. '<td>' . (int) $r['clickers'] . ' <span class="description">(' . esc_html( self::pct( $r['clickers'], $r['sent'] ) ) . ')</span></td>'
				. '<td>' . (int) $r['orders'] . '</td>'
				. '<td>' . esc_html( self::money( $r['revenue'] ) ) . '</td></tr>';
		}
		echo '</tbody></table>';
		echo '<p class="description">Delivered and bounced need the SMTP2GO webhook (see <a href="' . esc_url( self::url( 'whm-settings' ) ) . '">Settings → Reporting</a>). Orders and revenue count orders placed by people who clicked, within ' . (int) WHM_Tracking::ATTRIBUTION_DAYS . ' days of their first click.</p></div>';
	}

	private static function report_detail( $id ) {
		$back = '<a href="' . esc_url( self::url( 'whm-reports' ) ) . '" class="page-title-action">All reports</a>';
		self::header( 'Report: ' . get_the_title( $id ), $back );
		$r       = WHM_Tracking::report( $id );
		$sent    = (int) $r['sent'];
		$bounced = $r['delivery']['hard_bounce'] + $r['delivery']['soft_bounce'];
		$seg     = get_post_meta( $id, '_whm_segment_label', true );

		echo '<p class="whm-report-meta"><strong>' . esc_html( get_post_meta( $id, '_whm_subject', true ) ) . '</strong><br>'
			. 'Sent ' . self::fmt_time( get_post_meta( $id, '_whm_started', true ) ) . ( $seg ? ' to “' . esc_html( $seg ) . '”' : '' ) . ' · ' . self::status_badge( WHM_Sender::status( $id ) ) // phpcs:ignore WordPress.Security.EscapeOutput
			. ' · <a href="' . esc_url( self::url( 'whm-edit', array( 'campaign' => $id ) ) ) . '">View email</a></p>';

		$until = WHM_Sender::throttled_until( $id );
		if ( $until ) {
			$t = get_post_meta( $id, '_whm_throttled', true );
			echo '<div class="whm-warn"><strong>Waiting for the mail service.</strong> It refused more emails because of a sending limit, so sending has paused and will try again ' . self::fmt_time( $until ) . '. Nobody is marked as failed while this happens.' // phpcs:ignore WordPress.Security.EscapeOutput
				. ( ! empty( $t['error'] ) ? '<br><span class="description">' . esc_html( $t['error'] ) . '</span>' : '' ) . '</div>';
		}
		$failed_groups = WHM_Sender::failed_errors( $id );
		if ( $failed_groups && in_array( WHM_Sender::status( $id ), array( 'sending', 'paused', 'sent' ), true ) ) {
			$n      = (int) $r['failed'];
			$action = admin_url( 'admin-post.php?action=whm_campaign_action&do=retry&back=report&id=' . $id );
			$ask    = sprintf( 'Re-send to the selected people who failed? Anyone who already got this email won\'t get it again.' );
			echo '<form method="post" action="' . esc_url( $action ) . '" class="whm-retry" onsubmit="return confirm(' . esc_attr( wp_json_encode( $ask ) ) . ');">';
			wp_nonce_field( 'whm_campaign_' . $id );
			echo '<strong>' . sprintf( '%d %s failed to send.', $n, 1 === $n ? 'email' : 'emails' ) . '</strong> '; // phpcs:ignore WordPress.Security.EscapeOutput
			echo '<label class="screen-reader-text" for="whm-only-error">Which failures</label><select name="only_error" id="whm-only-error">';
			echo '<option value="__all__">All ' . (int) $n . ' failed</option>';
			foreach ( $failed_groups as $g ) {
				$label = '' === $g->error ? '(no error message)' : $g->error;
				echo '<option value="' . esc_attr( $g->error ) . '">' . (int) $g->n . ' with: ' . esc_html( mb_strimwidth( $label, 0, 90, '…' ) ) . '</option>';
			}
			echo '</select> ';
			submit_button( 'Retry failed', 'primary', 'submit', false );
			echo '<p class="description">They go back in the queue for a fresh three tries. Anyone who has since unsubscribed or bounced is skipped.</p></form>';
		}

		$tile = function ( $label, $value, $sub = '' ) {
			echo '<div class="whm-tile"><div class="whm-tile-label">' . esc_html( $label ) . '</div><div class="whm-tile-value">' . esc_html( $value ) . '</div>' . ( $sub ? '<div class="whm-tile-sub">' . esc_html( $sub ) . '</div>' : '' ) . '</div>';
		};
		echo '<div class="whm-tiles">';
		$tile( 'Sent', number_format_i18n( $sent ), $r['total'] && $r['total'] !== $sent ? sprintf( 'of %s in the audience', number_format_i18n( $r['total'] ) ) : '' );
		if ( $r['delivery_known'] ) {
			$tile( 'Delivered', number_format_i18n( $r['delivery']['delivered'] ), self::pct( $r['delivery']['delivered'], $sent ) . ' of sent' );
			$tile( 'Bounced', number_format_i18n( $bounced ), sprintf( '%s · %d hard, %d soft', self::pct( $bounced, $sent ), $r['delivery']['hard_bounce'], $r['delivery']['soft_bounce'] ) );
			$tile( 'Spam complaints', number_format_i18n( $r['delivery']['spam'] ), self::pct( $r['delivery']['spam'], $sent ) );
		} else {
			$tile( 'Delivered', '–', 'Connect SMTP2GO in Settings' );
			$tile( 'Bounced', '–', 'Connect SMTP2GO in Settings' );
		}
		$tile( 'Unsubscribed', number_format_i18n( $r['unsubscribed'] ), self::pct( $r['unsubscribed'], $sent ) );
		$tile( 'Clicked', number_format_i18n( $r['clickers'] ), sprintf( '%s of sent · %s clicks in total', self::pct( $r['clickers'], $sent ), number_format_i18n( $r['clicks'] ) ) );
		$tile( 'Orders after clicking', number_format_i18n( $r['orders'] ), self::money( $r['revenue'] ) . ' within ' . WHM_Tracking::ATTRIBUTION_DAYS . ' days' );
		if ( $r['failed'] || $r['skipped'] ) {
			$tile( 'Not sent', number_format_i18n( $r['failed'] + $r['skipped'] ), sprintf( '%d failed, %d skipped (unsubscribed before their turn)', $r['failed'], $r['skipped'] ) );
		}
		echo '</div>';

		echo '<h2>Links clicked</h2>';
		if ( $r['links'] ) {
			echo '<table class="widefat striped whm-table"><thead><tr><th>Link</th><th>People</th><th>Clicks</th></tr></thead><tbody>';
			foreach ( $r['links'] as $l ) {
				echo '<tr><td class="whm-url"><a href="' . esc_url( $l->url ) . '" target="_blank" rel="noopener">' . esc_html( preg_replace( '#^https?://#', '', $l->url ) ) . '</a></td><td>' . (int) $l->people . '</td><td>' . (int) $l->clicks . '</td></tr>';
			}
			echo '</tbody></table>';
		} else {
			echo '<p>No clicks yet.' . ( WHM_Settings::get( 'click_tracking' ) ? '' : ' Click tracking is switched off in Settings.' ) . '</p>';
		}

		echo '<h2>Bounces and complaints</h2>';
		if ( $r['problems'] ) {
			$labels = array(
				'hard_bounce' => 'Hard bounce',
				'soft_bounce' => 'Soft bounce',
				'spam'        => 'Spam complaint',
				'rejected'    => 'Rejected by SMTP2GO',
			);
			echo '<table class="widefat striped whm-table"><thead><tr><th>Email</th><th>What happened</th><th>Reason</th><th>When</th><th>Contact now</th></tr></thead><tbody>';
			foreach ( $r['problems'] as $b ) {
				echo '<tr><td>' . esc_html( $b->email ) . '</td><td>' . esc_html( $labels[ $b->delivery ] ?? $b->delivery ) . '</td><td class="whm-consent">' . esc_html( $b->delivery_note ) . '</td><td>' . esc_html( $b->delivery_at ? wp_date( 'j M Y, H:i', strtotime( $b->delivery_at . ' UTC' ) ) : '' ) . '</td><td>' . ( $b->contact_status ? self::status_badge( $b->contact_status ) : '' ) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput
			}
			echo '</tbody></table>';
		} else {
			echo '<p>' . ( $r['delivery_known'] ? 'None. Every email that SMTP2GO reported on was delivered.' : 'Nothing reported yet. Bounces show here once the SMTP2GO webhook is set up in Settings → Reporting.' ) . '</p>';
		}

		if ( $r['failed_rows'] ) {
			echo '<h2>Failed to send</h2><p class="description">These never left the site, usually because the mail service refused them. They were tried three times.</p>';
			echo '<table class="widefat striped whm-table"><thead><tr><th>Email</th><th>Error</th></tr></thead><tbody>';
			foreach ( $r['failed_rows'] as $f ) {
				echo '<tr><td>' . esc_html( $f->email ) . '</td><td>' . esc_html( $f->error ) . '</td></tr>';
			}
			echo '</tbody></table>';
		}

		echo '<p class="description whm-tools">Some email security filters (common in work email) check links automatically, which can add a few clicks. Hard bounces and spam complaints stop future emails to that address straight away; three soft bounces in a row do the same. Figures refresh every few minutes.</p></div>';
	}

	public static function page_settings() {
		self::header( 'Email settings' );
		$s    = WHM_Settings::all();
		$logo = WHM_Settings::logo_url();

		$field = function ( $key, $label, $type = 'text', $help = '' ) use ( $s ) {
			echo '<tr><th scope="row"><label for="whm-' . esc_attr( $key ) . '">' . esc_html( $label ) . '</label></th><td>';
			if ( 'textarea' === $type ) {
				echo '<textarea id="whm-' . esc_attr( $key ) . '" name="whm[' . esc_attr( $key ) . ']" rows="3" class="large-text">' . esc_textarea( $s[ $key ] ) . '</textarea>';
			} elseif ( 'color' === $type ) {
				echo '<input type="text" class="whm-color" id="whm-' . esc_attr( $key ) . '" name="whm[' . esc_attr( $key ) . ']" value="' . esc_attr( $s[ $key ] ) . '" data-default-color="' . esc_attr( WHM_Settings::defaults()[ $key ] ) . '">';
			} elseif ( 'checkbox' === $type ) {
				echo '<label><input type="checkbox" id="whm-' . esc_attr( $key ) . '" name="whm[' . esc_attr( $key ) . ']" value="1"' . checked( ! empty( $s[ $key ] ), true, false ) . '> ' . esc_html( $help ) . '</label>';
				$help = '';
			} else {
				echo '<input type="' . esc_attr( $type ) . '" class="regular-text" id="whm-' . esc_attr( $key ) . '" name="whm[' . esc_attr( $key ) . ']" value="' . esc_attr( $s[ $key ] ) . '">';
			}
			if ( $help ) {
				echo '<p class="description">' . wp_kses_post( $help ) . '</p>';
			}
			echo '</td></tr>';
		};

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="whm-settings">';
		wp_nonce_field( 'whm_save_settings' );
		echo '<input type="hidden" name="action" value="whm_save_settings"><input type="hidden" name="whm[_form]" value="1">';

		echo '<h2>Sending</h2><table class="form-table" role="presentation">';
		$field( 'live_sending', 'Live sending', 'checkbox', 'Allow campaigns to be sent to customers. Leave off while you\'re setting up; test emails work either way.' );
		$field( 'from_name', 'From name' );
		$field( 'from_email', 'From email', 'email', 'Use an address on a domain verified in SMTP2GO, or emails may be rejected.' );
		$field( 'reply_to', 'Reply-to email', 'email', 'Where replies go. Leave blank to use the from email.' );
		$field( 'batch_size', 'Emails per batch', 'number', 'How many emails go out in each batch. Keep this within your SMTP2GO plan\'s hourly limit.' );
		$field( 'batch_interval', 'Seconds between batches', 'number' );
		echo '</table>';

		echo '<h2>Brand</h2><table class="form-table" role="presentation">';
		echo '<tr><th scope="row">Logo</th><td><div class="whm-logo-preview">' . ( $logo ? '<img src="' . esc_url( $logo ) . '" alt="">' : '' ) . '</div>'
			. '<input type="hidden" id="whm-logo_id" name="whm[logo_id]" value="' . (int) $s['logo_id'] . '"> <button type="button" class="button" id="whm-logo-pick">Choose logo</button> <button type="button" class="button-link whm-danger" id="whm-logo-clear">Remove</button>'
			. '<p class="description">Use a PNG or JPG. Many email apps, including Gmail and Outlook, don\'t show SVG images.</p></td></tr>';
		$field( 'logo_width', 'Logo width (px)', 'number' );
		$field( 'brand_name', 'Brand name', 'text', 'Shown if there\'s no logo, and as the logo\'s alt text.' );
		$field( 'font_family', 'Font', 'text', 'Optional Google Font name, e.g. "Lora". Apple Mail and iOS show it; Gmail and Outlook fall back to Helvetica/Arial.' );
		$field( 'color_page', 'Background', 'color' );
		$field( 'color_card', 'Email body', 'color' );
		$field( 'color_text', 'Text', 'color' );
		$field( 'color_heading', 'Headings', 'color' );
		$field( 'color_accent', 'Accent (buttons, links)', 'color' );
		$field( 'color_muted', 'Footer text', 'color' );
		echo '<tr><th scope="row">Button style</th><td><label><input type="radio" name="whm[button_style]" value="outline"' . checked( $s['button_style'], 'outline', false ) . '> Outlined</label><br>'
			. '<label><input type="radio" name="whm[button_style]" value="filled"' . checked( $s['button_style'], 'filled', false ) . '> Filled</label></td></tr>';
		$field( 'signoff', 'Default sign-off', 'text', 'Used when you add a sign-off block.' );
		$field( 'footer_note', 'Footer line' );
		$field( 'footer_address', 'Footer address', 'textarea', 'Marketing emails must show who they\'re from and a postal address.' );
		echo '</table>';

		echo '<h2>Consent (UK GDPR and PECR)</h2>';
		echo '<p class="description whm-legal">Under PECR you can email marketing to people who <strong>opted in</strong>. You can also email <strong>existing customers</strong> about similar products under the "soft opt-in", but only if they were given a clear chance to opt out when they bought, and every email lets them opt out. Customers who were never offered that choice (for example, from an old website) are safest treated as not opted in. This isn\'t legal advice.</p>';
		echo '<table class="form-table" role="presentation">';
		$field( 'soft_opt_in', 'Soft opt-in', 'checkbox', 'Also email customers who haven\'t opted in or out (status "Customer").' );
		$field( 'checkout_opt_in', 'Checkout sign-up', 'checkbox', 'Show an unticked "keep me posted" box at checkout.' );
		$field( 'account_opt_in', 'My Account sign-up', 'checkbox', 'Let customers opt in or out from My Account → Account details.' );
		$field( 'opt_in_label', 'Sign-up wording', 'text', 'This exact wording is stored with each person\'s consent record.' );
		echo '<tr><th scope="row">Checkout box type</th><td>'
			. '<label><input type="radio" name="whm[checkout_mode]" value="opt_in"' . checked( $s['checkout_mode'], 'opt_in', false ) . '> Opt-in: customers tick to sign up (uses the sign-up wording)</label><br>'
			. '<label><input type="radio" name="whm[checkout_mode]" value="opt_out"' . checked( $s['checkout_mode'], 'opt_out', false ) . '> Opt-out: customers tick to say no (uses the opt-out wording)</label>'
			. '<p class="description">With soft opt-in on, use opt-out: buyers who leave it unticked become "Customer (soft opt-in)" and a tick unsubscribes them. With an opt-in box, emailing people who left it unticked goes against the choice they just made.</p></td></tr>';
		$field( 'opt_out_label', 'Opt-out wording', 'text', 'Shown at checkout when the box type is opt-out.' );
		echo '</table>';

		echo '<h2>Reporting</h2><table class="form-table" role="presentation">';
		$field( 'click_tracking', 'Click tracking', 'checkbox', 'Count who clicks which links. Links go through your site for a moment before opening, so they still work if you turn this off later.' );
		$field( 'utm_tags', 'Google Analytics tags', 'checkbox', 'Add utm_source=newsletter, utm_medium=email and the campaign name to links to your shop, so Google Analytics shows visits and sales from each email.' );
		echo '<tr><th scope="row"><label for="whm-webhook">Bounces from SMTP2GO</label></th><td>'
			. '<input type="text" readonly class="large-text code" id="whm-webhook" value="' . esc_attr( WHM_Tracking::webhook_url() ) . '" onclick="this.select();">'
			. '<p class="description">In SMTP2GO go to <strong>Settings → Webhooks → Add webhook</strong>, paste this address, choose the events <strong>Delivered, Bounce, Spam, Reject</strong> and <strong>Unsubscribe</strong>, and save. Hard bounces then mark the contact as Bounced, spam complaints unsubscribe them, and each campaign report shows deliveries and bounces. If your SMTP2GO account also sends for other websites, that\'s fine: only emails sent from your From email\'s domain are used, and everything else is ignored.</p><p class="description">Keep this address private: it contains a secret key.</p>'
			. '</td></tr>';
		echo '</table>';

		submit_button( 'Save settings' );
		echo '</form></div>';
	}

	public static function post_save_settings() {
		self::check( 'whm_save_settings' );
		$in = isset( $_POST['whm'] ) && is_array( $_POST['whm'] ) ? $_POST['whm'] : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- sanitised in WHM_Settings::save().
		WHM_Settings::save( $in );
		wp_safe_redirect( self::url( 'whm-settings', array( 'whm_msg' => 'Settings saved.' ) ) );
		exit;
	}
}
