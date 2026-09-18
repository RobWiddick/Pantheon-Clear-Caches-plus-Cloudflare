<?php
/**
 * Main plugin bootstrap.
 *
 * @package CPCF
 */

namespace CPCF;

defined( 'ABSPATH' ) || exit;

/**
 * Wires the plugin components together.
 */
final class Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var Plugin|null
	 */
	private static $instance = null;

	/**
	 * Settings component.
	 *
	 * @var Settings
	 */
	public $settings;

	/**
	 * Purge log component.
	 *
	 * @var Log
	 */
	public $log;

	/**
	 * Purge orchestrator.
	 *
	 * @var Purger
	 */
	public $purger;

	/**
	 * WordPress event hooks.
	 *
	 * @var Hooks
	 */
	public $hooks;

	/**
	 * Admin UI.
	 *
	 * @var Admin
	 */
	public $admin;

	/**
	 * Per-post meta box.
	 *
	 * @var Meta_Box
	 */
	public $meta_box;

	/**
	 * Get (and lazily create) the plugin instance.
	 *
	 * @return Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Set up components.
	 */
	private function __construct() {
		$this->settings = new Settings();
		$this->log      = new Log( $this->settings );
		$this->purger   = new Purger( $this->settings, $this->log );
		$this->hooks    = new Hooks( $this->settings, $this->purger );
		$this->admin    = new Admin( $this->settings, $this->purger, $this->log );
		$this->meta_box = new Meta_Box( $this->settings );

		$this->hooks->register();
		$this->admin->register();
		$this->meta_box->register();
	}

	/**
	 * Get a Cloudflare API client using the configured token.
	 *
	 * @return Cloudflare_API
	 */
	public function api() {
		return new Cloudflare_API( $this->settings->get_api_token() );
	}

	/**
	 * Activation callback: store default settings so the options page is fully populated.
	 */
	public static function activate() {
		$settings = new Settings();
		$settings->install_defaults();
	}
}
