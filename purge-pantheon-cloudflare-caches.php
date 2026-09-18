<?php
/**
 * Plugin Name:       Purge Pantheon + Cloudflare Caches
 * Plugin URI:        https://github.com/RobWiddick/purge-pantheon-cloudflare-caches
 * Description:       Purges your Cloudflare zone cache (and Pantheon edge cache) when content changes. Purge everything, or only the URLs an edit actually affects.
 * Version:           2.0.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            36creative
 * Author URI:        https://36.agency
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       purge-pantheon-cloudflare-caches
 * Domain Path:       /languages
 *
 * @package CPCF
 */

/*
 * Purge Pantheon + Cloudflare Caches
 * Copyright (C) 2025-2026 36creative
 *
 * This program is free software; you can redistribute it and/or modify it under the terms of the
 * GNU General Public License as published by the Free Software Foundation; either version 2 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful, but WITHOUT ANY WARRANTY; without
 * even the implied warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the GNU General
 * Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along with this program; if not,
 * see https://www.gnu.org/licenses/gpl-2.0.html.
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
