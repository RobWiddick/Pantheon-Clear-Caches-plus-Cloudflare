<?php
/**
 * Admin UI: settings page, AJAX, admin bar, notices.
 *
 * @package CPCF
 */

namespace CPCF;

defined( 'ABSPATH' ) || exit;

/**
 * Everything the site owner sees in wp-admin.
 */
class Admin {

	/**
	 * Settings page slug.
	 */
	const PAGE = 'pantheon-clear-caches-plus-cloudflare';

	/**
	 * Capability required to manage the plugin.
	 */
	const CAPABILITY = 'manage_options';

	/**
	 * Nonce action for AJAX and admin bar links.
	 */
	const NONCE = 'cpcf_admin';

	/**
	 * Settings.
	 *
	 * @var Settings
	 */
	private $settings;

	/**
	 * Purger.
	 *
	 * @var Purger
	 */
	private $purger;

	/**
	 * Log.
	 *
	 * @var Log
	 */
	private $log;

	/**
	 * Constructor.
	 *
	 * @param Settings $settings Settings.
	 * @param Purger   $purger   Purger.
	 * @param Log      $log      Log.
	 */
	public function __construct( Settings $settings, Purger $purger, Log $log ) {
		$this->settings = $settings;
		$this->purger   = $purger;
		$this->log      = $log;
	}

	/**
	 * Register hooks.
	 */
	public function register() {
		add_action( 'admin_bar_menu', array( $this, 'admin_bar' ), 100 );

		if ( ! is_admin() ) {
			return;
		}

		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
		add_action( 'admin_notices', array( $this, 'notices' ) );
		add_filter( 'plugin_action_links_' . CPCF_PLUGIN_BASENAME, array( $this, 'action_links' ) );

		add_action( 'wp_ajax_cpcf_verify_token', array( $this, 'ajax_verify_token' ) );

		add_action( 'admin_post_cpcf_purge', array( $this, 'handle_purge' ) );
		add_action( 'admin_post_cpcf_disconnect', array( $this, 'handle_disconnect' ) );
		add_action( 'admin_post_cpcf_test_connection', array( $this, 'handle_test_connection' ) );
		add_action( 'admin_post_cpcf_import_legacy', array( $this, 'handle_import_legacy' ) );
		add_action( 'admin_post_cpcf_clear_log', array( $this, 'handle_clear_log' ) );
	}

	/**
	 * Settings page URL.
	 *
	 * @param array $args Extra query arguments.
	 * @return string
	 */
	public function page_url( array $args = array() ) {
		return add_query_arg( array_merge( array( 'page' => self::PAGE ), $args ), admin_url( 'options-general.php' ) );
	}

	/**
	 * Nonce protected admin-post URL.
	 *
	 * @param string $action Action name (without the cpcf_ prefix).
	 * @param array  $args   Extra query arguments.
	 * @return string
	 */
	public function action_url( $action, array $args = array() ) {
		$url = add_query_arg( array_merge( array( 'action' => 'cpcf_' . $action ), $args ), admin_url( 'admin-post.php' ) );

		return wp_nonce_url( $url, 'cpcf_' . $action );
	}

	/**
	 * Add the settings page.
	 */
	public function menu() {
		add_options_page(
			__( 'Cache Purge Control for Cloudflare', 'pantheon-clear-caches-plus-cloudflare' ),
			__( 'Cloudflare Purge', 'pantheon-clear-caches-plus-cloudflare' ),
			self::CAPABILITY,
			self::PAGE,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Register the option with the Settings API.
	 */
	public function register_settings() {
		register_setting(
			Settings::GROUP,
			Settings::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this->settings, 'sanitize' ),
				'default'           => Settings::defaults(),
			)
		);
	}

	/**
	 * Settings link on the Plugins screen.
	 *
	 * @param array $links Links.
	 * @return array
	 */
	public function action_links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( $this->page_url() ) . '">' . esc_html__( 'Settings', 'pantheon-clear-caches-plus-cloudflare' ) . '</a>' );

		return $links;
	}

	/**
	 * Enqueue assets on the settings page.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function assets( $hook ) {
		if ( 'settings_page_' . self::PAGE !== $hook ) {
			return;
		}

		wp_enqueue_style( 'cpcf-admin', CPCF_PLUGIN_URL . 'assets/admin.css', array(), CPCF_VERSION );
		wp_enqueue_script( 'cpcf-admin', CPCF_PLUGIN_URL . 'assets/admin.js', array(), CPCF_VERSION, true );

		wp_localize_script(
			'cpcf-admin',
			'cpcfAdmin',
			array(
				'ajaxUrl'  => admin_url( 'admin-ajax.php' ),
				'nonce'    => wp_create_nonce( self::NONCE ),
				'zoneId'   => $this->settings->get_zone_id(),
				'siteHost' => (string) wp_parse_url( home_url(), PHP_URL_HOST ),
				'i18n'     => array(
					'verifying'    => __( 'Verifying token…', 'pantheon-clear-caches-plus-cloudflare' ),
					'selectZone'   => __( '— Select a zone —', 'pantheon-clear-caches-plus-cloudflare' ),
					'noZones'      => __( 'The token is valid but no zones were returned. Make sure it includes Zone:Read for the zone you want to purge.', 'pantheon-clear-caches-plus-cloudflare' ),
					'requestError' => __( 'The request failed. Please try again.', 'pantheon-clear-caches-plus-cloudflare' ),
					'saveReminder' => __( 'Choose a zone and click "Save Changes" to finish connecting.', 'pantheon-clear-caches-plus-cloudflare' ),
					'remove'       => __( 'Remove', 'pantheon-clear-caches-plus-cloudflare' ),
				),
			)
		);
	}

	/**
	 * Admin notices (not connected, legacy import, action feedback).
	 */
	public function notices() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}

		$screen    = get_current_screen();
		$screen_id = $screen ? $screen->id : '';
		$is_ours   = ( 'settings_page_' . self::PAGE === $screen_id );

		// Feedback from admin-post actions.
		$notice = get_transient( 'cpcf_notice_' . get_current_user_id() );

		if ( is_array( $notice ) && ! empty( $notice['message'] ) ) {
			delete_transient( 'cpcf_notice_' . get_current_user_id() );
			$type = in_array( $notice['type'], array( 'success', 'error', 'warning', 'info' ), true ) ? $notice['type'] : 'info';

			printf(
				'<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
				esc_attr( $type ),
				wp_kses(
					$notice['message'],
					array(
						'strong' => array(),
						'code'   => array(),
						'a'      => array( 'href' => array() ),
						'br'     => array(),
					)
				)
			);
		}

		if ( ! $is_ours && ! in_array( $screen_id, array( 'plugins', 'dashboard' ), true ) ) {
			return;
		}

		if ( ! $this->settings->is_connected() && ! $is_ours ) {
			printf(
				'<div class="notice notice-warning"><p>%1$s <a href="%2$s">%3$s</a></p></div>',
				esc_html__( 'Cache Purge Control for Cloudflare is not connected to Cloudflare yet.', 'pantheon-clear-caches-plus-cloudflare' ),
				esc_url( $this->page_url() ),
				esc_html__( 'Connect now', 'pantheon-clear-caches-plus-cloudflare' )
			);
		}

		if ( $this->settings->token_unreadable() ) {
			printf(
				'<div class="notice notice-error"><p>%1$s <a href="%2$s">%3$s</a></p></div>',
				esc_html__( 'The stored Cloudflare API token could not be decrypted (the site security keys probably changed). Please enter the token again.', 'pantheon-clear-caches-plus-cloudflare' ),
				esc_url( $this->page_url() ),
				esc_html__( 'Open settings', 'pantheon-clear-caches-plus-cloudflare' )
			);
		}

		if ( ! $this->settings->is_connected() && '' !== Environment::legacy_config_path() ) {
			printf(
				'<div class="notice notice-info"><p>%1$s <a class="button button-secondary" href="%2$s">%3$s</a></p></div>',
				esc_html__( 'A Cloudflare configuration file from the previous version of this plugin was found in files/private.', 'pantheon-clear-caches-plus-cloudflare' ),
				esc_url( $this->action_url( 'import_legacy' ) ),
				esc_html__( 'Import zone and token', 'pantheon-clear-caches-plus-cloudflare' )
			);
		}
	}

	/**
	 * Store a one-time notice for the current user.
	 *
	 * @param string $message Message (limited HTML allowed).
	 * @param string $type    success|error|warning|info.
	 */
	private function set_notice( $message, $type = 'success' ) {
		set_transient(
			'cpcf_notice_' . get_current_user_id(),
			array(
				'message' => $message,
				'type'    => $type,
			),
			120
		);
	}

	/**
	 * Redirect back to the settings page (or the referring page) after an action.
	 *
	 * @param string $fallback_tab Tab to open on the settings page.
	 */
	private function redirect_back( $fallback_tab = '' ) {
		$referer = wp_get_referer();
		$target  = $referer ? $referer : $this->page_url();

		if ( '' !== $fallback_tab && false !== strpos( $target, 'page=' . self::PAGE ) ) {
			$target = $this->page_url( array( 'tab' => $fallback_tab ) );
		}

		wp_safe_redirect( $target );
		exit;
	}

	/**
	 * Admin bar purge menu.
	 *
	 * @param \WP_Admin_Bar $wp_admin_bar Admin bar.
	 */
	public function admin_bar( $wp_admin_bar ) {
		if ( ! current_user_can( self::CAPABILITY ) || ! $this->settings->get( 'admin_bar' ) ) {
			return;
		}

		$connected = $this->settings->is_connected();

		$wp_admin_bar->add_node(
			array(
				'id'    => 'cpcf',
				'title' => '<span class="ab-icon dashicons dashicons-cloud" style="top:2px;"></span>' . esc_html__( 'Cloudflare Purge', 'pantheon-clear-caches-plus-cloudflare' ),
				'href'  => $this->page_url(),
			)
		);

		if ( $connected ) {
			$wp_admin_bar->add_node(
				array(
					'parent' => 'cpcf',
					'id'     => 'cpcf-everything',
					'title'  => esc_html__( 'Purge everything', 'pantheon-clear-caches-plus-cloudflare' ),
					'href'   => $this->action_url( 'purge', array( 'type' => 'everything' ) ),
				)
			);

			if ( ! is_admin() ) {
				$post = is_singular() ? get_queried_object() : null;

				if ( $post instanceof \WP_Post ) {
					$wp_admin_bar->add_node(
						array(
							'parent' => 'cpcf',
							'id'     => 'cpcf-post',
							'title'  => esc_html__( 'Purge this page and related URLs', 'pantheon-clear-caches-plus-cloudflare' ),
							'href'   => $this->action_url(
								'purge',
								array(
									'type'    => 'post',
									'post_id' => $post->ID,
								)
							),
						)
					);
				}

				$current = $this->current_url();

				if ( '' !== $current ) {
					$wp_admin_bar->add_node(
						array(
							'parent' => 'cpcf',
							'id'     => 'cpcf-url',
							'title'  => esc_html__( 'Purge this URL', 'pantheon-clear-caches-plus-cloudflare' ),
							'href'   => $this->action_url(
								'purge',
								array(
									'type' => 'url',
									'url'  => rawurlencode( $current ),
								)
							),
						)
					);
				}
			}
		}

		$wp_admin_bar->add_node(
			array(
				'parent' => 'cpcf',
				'id'     => 'cpcf-settings',
				'title'  => $connected ? esc_html__( 'Settings', 'pantheon-clear-caches-plus-cloudflare' ) : esc_html__( 'Connect to Cloudflare', 'pantheon-clear-caches-plus-cloudflare' ),
				'href'   => $this->page_url(),
			)
		);
	}

	/**
	 * The URL of the current front-end request.
	 *
	 * @return string
	 */
	private function current_url() {
		if ( empty( $_SERVER['HTTP_HOST'] ) || empty( $_SERVER['REQUEST_URI'] ) ) {
			return '';
		}

		$host = sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) );
		$uri  = sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) );

		return esc_url_raw( ( is_ssl() ? 'https://' : 'http://' ) . $host . $uri );
	}

	/**
	 * AJAX: verify a token and list its zones.
	 */
	public function ajax_verify_token() {
		check_ajax_referer( self::NONCE, 'nonce' );

		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_send_json_error( array( 'message' => __( 'You are not allowed to do that.', 'pantheon-clear-caches-plus-cloudflare' ) ), 403 );
		}

		$token = isset( $_POST['token'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['token'] ) ) ) : '';

		if ( '' === $token ) {
			$token = $this->settings->get_api_token();
		}

		if ( '' === $token ) {
			wp_send_json_error( array( 'message' => __( 'Enter an API token first.', 'pantheon-clear-caches-plus-cloudflare' ) ) );
		}

		$api    = new Cloudflare_API( $token );
		$verify = $api->verify_token();

		if ( is_wp_error( $verify ) ) {
			wp_send_json_error( array( 'message' => $verify->get_error_message() ) );
		}

		$zones = $api->list_zones();

		if ( is_wp_error( $zones ) ) {
			wp_send_json_success(
				array(
					'zones'   => array(),
					/* translators: %s: error message */
					'message' => sprintf( __( 'The token is active, but zones could not be listed: %s Make sure the token includes the Zone:Read permission.', 'pantheon-clear-caches-plus-cloudflare' ), $zones->get_error_message() ),
				)
			);
		}

		wp_send_json_success(
			array(
				'zones'   => $zones,
				/* translators: %d: number of zones */
				'message' => sprintf( _n( 'Token is active. %d zone is available.', 'Token is active. %d zones are available.', count( $zones ), 'pantheon-clear-caches-plus-cloudflare' ), count( $zones ) ),
				'expires' => isset( $verify['expires_on'] ) ? sanitize_text_field( $verify['expires_on'] ) : '',
			)
		);
	}

	/**
	 * Manual purge (settings page tools and admin bar).
	 */
	public function handle_purge() {
		check_admin_referer( 'cpcf_purge' );

		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'pantheon-clear-caches-plus-cloudflare' ) );
		}

		$type = isset( $_REQUEST['type'] ) ? sanitize_key( wp_unslash( $_REQUEST['type'] ) ) : 'everything';
		$user = wp_get_current_user();
		/* translators: %s: user display name */
		$reason  = sprintf( __( 'Manual purge by %s', 'pantheon-clear-caches-plus-cloudflare' ), $user->display_name );
		$results = null;
		$label   = '';

		switch ( $type ) {
			case 'urls':
				$raw  = isset( $_POST['urls'] ) ? sanitize_textarea_field( wp_unslash( $_POST['urls'] ) ) : '';
				$urls = array();

				foreach ( Settings::parse_url_list( $raw ) as $entry ) {
					$url = $this->purger->collector()->resolve_url( $entry );

					if ( '' !== $url ) {
						$urls[] = $url;
					}
				}

				if ( empty( $urls ) ) {
					$this->set_notice( __( 'Enter at least one URL to purge.', 'pantheon-clear-caches-plus-cloudflare' ), 'error' );
					$this->redirect_back( 'tools' );
				}

				$results = $this->purger->execute( false, $urls, array( $reason ), 'manual' );
				/* translators: %d: number of URLs */
				$label = sprintf( _n( '%d URL purged.', '%d URLs purged.', count( $urls ), 'pantheon-clear-caches-plus-cloudflare' ), count( $urls ) );
				break;

			case 'url':
				$url = isset( $_GET['url'] ) ? esc_url_raw( rawurldecode( sanitize_text_field( wp_unslash( $_GET['url'] ) ) ) ) : '';

				if ( '' === $url ) {
					$this->set_notice( __( 'No URL was given.', 'pantheon-clear-caches-plus-cloudflare' ), 'error' );
					$this->redirect_back();
				}

				$results = $this->purger->execute( false, array( $url ), array( $reason ), 'manual' );
				/* translators: %s: URL */
				$label = sprintf( __( 'Purged %s.', 'pantheon-clear-caches-plus-cloudflare' ), '<code>' . esc_html( $url ) . '</code>' );
				break;

			case 'post':
				$post = isset( $_GET['post_id'] ) ? get_post( absint( wp_unslash( $_GET['post_id'] ) ) ) : null;

				if ( ! $post instanceof \WP_Post ) {
					$this->set_notice( __( 'The post could not be found.', 'pantheon-clear-caches-plus-cloudflare' ), 'error' );
					$this->redirect_back();
				}

				$plan              = $this->purger->collector()->plan_for_post( $post, array( 'trigger' => $reason ) );
				$plan['reasons'][] = $reason;
				$results           = $this->purger->execute_plan( $plan, 'manual' );
				/* translators: 1: post title, 2: number of URLs */
				$label = sprintf( __( 'Purged "%1$s" and %2$d related URLs.', 'pantheon-clear-caches-plus-cloudflare' ), esc_html( $post->post_title ), count( $plan['urls'] ) );
				break;

			default:
				$results = $this->purger->execute( true, array(), array( $reason ), 'manual' );
				$label   = __( 'The entire Cloudflare cache for this zone was purged.', 'pantheon-clear-caches-plus-cloudflare' );
		}

		$this->notice_from_results( $results, $label );
		$this->redirect_back( 'tools' );
	}

	/**
	 * Turn purge results into a notice.
	 *
	 * @param array  $results Results.
	 * @param string $label   Success label.
	 */
	private function notice_from_results( $results, $label ) {
		$cloudflare = isset( $results['cloudflare'] ) ? $results['cloudflare'] : null;

		if ( is_wp_error( $cloudflare ) ) {
			/* translators: %s: error message */
			$this->set_notice( sprintf( __( 'Cloudflare purge failed: %s', 'pantheon-clear-caches-plus-cloudflare' ), esc_html( $cloudflare->get_error_message() ) ), 'error' );

			return;
		}

		$extras = array();

		if ( isset( $results['pantheon'] ) && true === $results['pantheon'] ) {
			$extras[] = __( 'Pantheon edge cache cleared.', 'pantheon-clear-caches-plus-cloudflare' );
		}

		if ( ! empty( $results['object_cache'] ) ) {
			$extras[] = __( 'Object cache flushed.', 'pantheon-clear-caches-plus-cloudflare' );
		}

		$this->set_notice( trim( $label . ' ' . implode( ' ', $extras ) ), 'success' );
	}

	/**
	 * Forget the stored credentials.
	 */
	public function handle_disconnect() {
		check_admin_referer( 'cpcf_disconnect' );

		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'pantheon-clear-caches-plus-cloudflare' ) );
		}

		$this->settings->disconnect();
		$this->set_notice( __( 'Disconnected from Cloudflare. The stored token was removed.', 'pantheon-clear-caches-plus-cloudflare' ), 'info' );

		wp_safe_redirect( $this->page_url() );
		exit;
	}

	/**
	 * Verify the stored token and zone.
	 */
	public function handle_test_connection() {
		check_admin_referer( 'cpcf_test_connection' );

		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'pantheon-clear-caches-plus-cloudflare' ) );
		}

		$api    = new Cloudflare_API( $this->settings->get_api_token() );
		$verify = $api->verify_token();

		if ( is_wp_error( $verify ) ) {
			/* translators: %s: error message */
			$this->set_notice( sprintf( __( 'Token check failed: %s', 'pantheon-clear-caches-plus-cloudflare' ), esc_html( $verify->get_error_message() ) ), 'error' );
			$this->redirect_back();
		}

		$zone = $api->get_zone( $this->settings->get_zone_id() );

		if ( is_wp_error( $zone ) ) {
			/* translators: %s: error message */
			$this->set_notice( sprintf( __( 'The token is active but the zone could not be read: %s', 'pantheon-clear-caches-plus-cloudflare' ), esc_html( $zone->get_error_message() ) ), 'error' );
			$this->redirect_back();
		}

		if ( ! empty( $zone['name'] ) && $zone['name'] !== $this->settings->get_zone_name() && ! $this->settings->zone_from_constant() ) {
			$this->settings->update( array( 'zone_name' => sanitize_text_field( $zone['name'] ) ) );
		}

		$this->set_notice(
			sprintf(
				/* translators: 1: zone name, 2: zone status, 3: plan name */
				__( 'Connection OK. Zone %1$s is %2$s (%3$s).', 'pantheon-clear-caches-plus-cloudflare' ),
				'<strong>' . esc_html( isset( $zone['name'] ) ? $zone['name'] : $this->settings->get_zone_id() ) . '</strong>',
				esc_html( isset( $zone['status'] ) ? $zone['status'] : '' ),
				esc_html( isset( $zone['plan']['name'] ) ? $zone['plan']['name'] : '' )
			),
			'success'
		);

		$this->redirect_back();
	}

	/**
	 * Import credentials from the 1.x MU plugin config file on Pantheon.
	 */
	public function handle_import_legacy() {
		check_admin_referer( 'cpcf_import_legacy' );

		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'pantheon-clear-caches-plus-cloudflare' ) );
		}

		$config = Environment::read_legacy_config();

		if ( null === $config ) {
			$this->set_notice( __( 'The legacy configuration file could not be read.', 'pantheon-clear-caches-plus-cloudflare' ), 'error' );
			wp_safe_redirect( $this->page_url() );
			exit;
		}

		$api       = new Cloudflare_API( $config['auth_token'] );
		$zone      = $api->get_zone( $config['zone_id'] );
		$zone_name = ( ! is_wp_error( $zone ) && ! empty( $zone['name'] ) ) ? $zone['name'] : '';

		$this->settings->store_connection( $config['auth_token'], $config['zone_id'], $zone_name );

		if ( is_wp_error( $zone ) ) {
			/* translators: %s: error message */
			$this->set_notice( sprintf( __( 'Imported, but the zone could not be verified: %s', 'pantheon-clear-caches-plus-cloudflare' ), esc_html( $zone->get_error_message() ) ), 'warning' );
		} else {
			$this->set_notice( __( 'Imported the zone and token from the legacy configuration file. You can now delete that file.', 'pantheon-clear-caches-plus-cloudflare' ), 'success' );
		}

		wp_safe_redirect( $this->page_url() );
		exit;
	}

	/**
	 * Clear the purge log.
	 */
	public function handle_clear_log() {
		check_admin_referer( 'cpcf_clear_log' );

		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'pantheon-clear-caches-plus-cloudflare' ) );
		}

		$this->log->clear();
		$this->set_notice( __( 'Purge log cleared.', 'pantheon-clear-caches-plus-cloudflare' ), 'info' );

		wp_safe_redirect( $this->page_url( array( 'tab' => 'tools' ) ) );
		exit;
	}

	/**
	 * Render the settings page.
	 */
	public function render_page() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}

		$settings   = $this->settings;
		$log        = $this->log;
		$admin      = $this;
		$active_tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'connection'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only UI state.

		if ( ! in_array( $active_tab, array( 'connection', 'behavior', 'scope', 'rules', 'tools' ), true ) ) {
			$active_tab = 'connection';
		}

		include CPCF_PLUGIN_DIR . 'includes/views/settings-page.php';
	}

	/**
	 * Output a checkbox row.
	 *
	 * @param string $key         Setting key.
	 * @param string $label       Row label.
	 * @param string $description Description.
	 * @param bool   $disabled    Whether the control is disabled.
	 */
	public function checkbox( $key, $label, $description = '', $disabled = false ) {
		?>
		<tr>
			<th scope="row"><?php echo esc_html( $label ); ?></th>
			<td>
				<label>
					<input type="checkbox" name="<?php echo esc_attr( Settings::OPTION . '[' . $key . ']' ); ?>" value="1" <?php checked( (bool) $this->settings->get( $key ) ); ?> <?php disabled( $disabled ); ?> />
					<?php echo esc_html( $description ); ?>
				</label>
			</td>
		</tr>
		<?php
	}

	/**
	 * Output a select row.
	 *
	 * @param string $key         Setting key.
	 * @param string $label       Row label.
	 * @param array  $options     value => label.
	 * @param string $description Description.
	 */
	public function select( $key, $label, array $options, $description = '' ) {
		$current = (string) $this->settings->get( $key );
		?>
		<tr>
			<th scope="row"><label for="cpcf-<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></label></th>
			<td>
				<select name="<?php echo esc_attr( Settings::OPTION . '[' . $key . ']' ); ?>" id="cpcf-<?php echo esc_attr( $key ); ?>">
					<?php foreach ( $options as $value => $option_label ) : ?>
						<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $current, (string) $value ); ?>><?php echo esc_html( $option_label ); ?></option>
					<?php endforeach; ?>
				</select>
				<?php if ( '' !== $description ) : ?>
					<p class="description"><?php echo esc_html( $description ); ?></p>
				<?php endif; ?>
			</td>
		</tr>
		<?php
	}

	/**
	 * Output a number row.
	 *
	 * @param string $key         Setting key.
	 * @param string $label       Row label.
	 * @param int    $min         Minimum.
	 * @param int    $max         Maximum.
	 * @param string $description Description.
	 */
	public function number( $key, $label, $min, $max, $description = '' ) {
		?>
		<tr>
			<th scope="row"><label for="cpcf-<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></label></th>
			<td>
				<input type="number" class="small-text" name="<?php echo esc_attr( Settings::OPTION . '[' . $key . ']' ); ?>" id="cpcf-<?php echo esc_attr( $key ); ?>" value="<?php echo esc_attr( (int) $this->settings->get( $key ) ); ?>" min="<?php echo esc_attr( $min ); ?>" max="<?php echo esc_attr( $max ); ?>" />
				<?php if ( '' !== $description ) : ?>
					<p class="description"><?php echo esc_html( $description ); ?></p>
				<?php endif; ?>
			</td>
		</tr>
		<?php
	}

	/**
	 * Post types shown in the per-post-type table.
	 *
	 * @return \WP_Post_Type[]
	 */
	public function purgeable_post_types() {
		$types = array();

		foreach ( get_post_types( array( 'public' => true ), 'objects' ) as $type ) {
			if ( is_post_type_viewable( $type ) ) {
				$types[] = $type;
			}
		}

		return $types;
	}

	/**
	 * Describe a log entry's target results.
	 *
	 * @param array $entry Log entry.
	 * @return string
	 */
	public function describe_targets( array $entry ) {
		$parts   = array();
		$targets = isset( $entry['targets'] ) ? (array) $entry['targets'] : array();
		$labels  = array(
			'cloudflare'   => __( 'Cloudflare', 'pantheon-clear-caches-plus-cloudflare' ),
			'pantheon'     => __( 'Pantheon', 'pantheon-clear-caches-plus-cloudflare' ),
			'object_cache' => __( 'Object cache', 'pantheon-clear-caches-plus-cloudflare' ),
		);

		foreach ( $labels as $key => $label ) {
			$status = isset( $targets[ $key ] ) ? $targets[ $key ] : 'skipped';

			if ( 'skipped' === $status ) {
				continue;
			}

			$parts[] = sprintf( '<span class="cpcf-badge cpcf-badge-%1$s">%2$s</span>', esc_attr( $status ), esc_html( $label ) );
		}

		return implode( ' ', $parts );
	}
}
