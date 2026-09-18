<?php
/**
 * Plugin Name:       Cache Purge Control for Cloudflare
 * Plugin URI:        https://github.com/RobWiddick/Pantheon-Clear-Caches-plus-Cloudflare
 * Description:       Purges your Cloudflare zone cache (and Pantheon edge cache) when content changes. Purge everything, or only the URLs an edit actually affects.
 * Version:           2.0.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            36creative
 * Author URI:        https://36.agency
 * License:           MIT
 * License URI:       https://opensource.org/licenses/MIT
 * Text Domain:       pantheon-clear-caches-plus-cloudflare
 * Domain Path:       /languages
 *
 * @package CPCF
 */

defined( 'ABSPATH' ) || exit;

define( 'CPCF_VERSION', '2.0.0' );
define( 'CPCF_PLUGIN_FILE', __FILE__ );
define( 'CPCF_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'CPCF_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'CPCF_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

/**
 * Autoload plugin classes from the includes directory.
 *
 * Maps CPCF\Foo_Bar to includes/class-foo-bar.php.
 */
spl_autoload_register(
	static function ( $class_name ) {
		if ( 0 !== strpos( $class_name, 'CPCF\\' ) ) {
			return;
		}

		$relative = strtolower( str_replace( '_', '-', substr( $class_name, 5 ) ) );
		$file     = CPCF_PLUGIN_DIR . 'includes/class-' . $relative . '.php';

		if ( is_readable( $file ) ) {
			require_once $file;
		}
	}
);

require_once CPCF_PLUGIN_DIR . 'includes/functions.php';

register_activation_hook( __FILE__, array( 'CPCF\\Plugin', 'activate' ) );

add_action( 'plugins_loaded', array( 'CPCF\\Plugin', 'instance' ) );
