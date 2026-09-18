<?php
/**
 * Environment detection (Pantheon, WordPress environment type).
 *
 * @package CPCF
 */

namespace CPCF;

defined( 'ABSPATH' ) || exit;

/**
 * Helpers for figuring out where the plugin is running.
 */
class Environment {

	/**
	 * Whether the site runs on Pantheon.
	 *
	 * @return bool
	 */
	public static function is_pantheon() {
		return defined( 'PANTHEON_ENVIRONMENT' );
	}

	/**
	 * Current Pantheon environment name (dev, test, live, multidev name) or an empty string.
	 *
	 * @return string
	 */
	public static function pantheon_environment() {
		return self::is_pantheon() ? (string) PANTHEON_ENVIRONMENT : '';
	}

	/**
	 * Whether Pantheon's edge cache purge helpers are available (Pantheon Advanced Page Cache).
	 *
	 * @return bool
	 */
	public static function has_pantheon_purge() {
		return function_exists( 'pantheon_wp_clear_edge_all' ) || function_exists( 'pantheon_wp_clear_edge_paths' );
	}

	/**
	 * Whether this is a production environment where purges should run.
	 *
	 * On Pantheon only the "live" environment counts. Elsewhere the WordPress environment type is used
	 * (which defaults to "production" when WP_ENVIRONMENT_TYPE is not set).
	 *
	 * @return bool
	 */
	public static function is_production() {
		if ( self::is_pantheon() ) {
			$is_production = ( 'live' === self::pantheon_environment() );
		} else {
			$is_production = ( 'production' === wp_get_environment_type() );
		}

		/**
		 * Filter whether the current environment counts as production for automatic purges.
		 *
		 * @param bool $is_production Whether purges may run automatically.
		 */
		return (bool) apply_filters( 'cpcf_is_production', $is_production );
	}

	/**
	 * Human readable description of the detected environment.
	 *
	 * @return string
	 */
	public static function describe() {
		if ( self::is_pantheon() ) {
			/* translators: %s: Pantheon environment name */
			return sprintf( __( 'Pantheon environment: %s', 'cache-purge-control-for-cloudflare' ), self::pantheon_environment() );
		}

		/* translators: %s: WordPress environment type */
		return sprintf( __( 'WordPress environment type: %s', 'cache-purge-control-for-cloudflare' ), wp_get_environment_type() );
	}

	/**
	 * Path of the legacy config file used by the 1.x MU plugin on Pantheon, if it exists.
	 *
	 * @return string Empty string when not on Pantheon or the file is missing.
	 */
	public static function legacy_config_path() {
		if ( ! self::is_pantheon() || empty( $_SERVER['HOME'] ) ) {
			return '';
		}

		$home = sanitize_text_field( wp_unslash( $_SERVER['HOME'] ) );
		$path = rtrim( $home, '/' ) . '/files/private/cloudflare_cache_config.json';

		return ( is_readable( $path ) ) ? $path : '';
	}

	/**
	 * Read the legacy config file (zone_id and auth_token).
	 *
	 * @return array{zone_id:string,auth_token:string}|null
	 */
	public static function read_legacy_config() {
		$path = self::legacy_config_path();

		if ( '' === $path ) {
			return null;
		}

		$contents = file_get_contents( $path ); // phpcs:ignore WordPressVIPMinimum.Performance.FetchingRemoteData.FileGetContentsUnknown,WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local private file on Pantheon.
		$config   = json_decode( (string) $contents, true );

		if ( ! is_array( $config ) || empty( $config['zone_id'] ) || empty( $config['auth_token'] ) ) {
			return null;
		}

		return array(
			'zone_id'    => sanitize_text_field( $config['zone_id'] ),
			'auth_token' => sanitize_text_field( $config['auth_token'] ),
		);
	}
}
