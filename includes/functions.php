<?php
/**
 * Public helper functions for developers.
 *
 * @package CPCF
 */

defined( 'ABSPATH' ) || exit;

/**
 * Get the main plugin instance.
 *
 * @return \CPCF\Plugin
 */
function cpcf() {
	return \CPCF\Plugin::instance();
}

/**
 * Purge the entire Cloudflare zone (and Pantheon edge / object cache when enabled) immediately.
 *
 * @param string $reason Human readable reason recorded in the purge log.
 * @return array Results keyed by target (cloudflare, pantheon, object_cache).
 */
function cpcf_purge_everything( $reason = '' ) {
	if ( '' === $reason ) {
		$reason = __( 'Purge everything requested by code', 'pantheon-clear-caches-plus-cloudflare' );
	}

	return cpcf()->purger->execute( true, array(), array( $reason ), 'api' );
}

/**
 * Purge a list of URLs immediately.
 *
 * Relative paths (e.g. "/about/") are resolved against the home URL.
 *
 * @param string[] $urls   URLs or paths to purge.
 * @param string   $reason Human readable reason recorded in the purge log.
 * @return array Results keyed by target (cloudflare, pantheon, object_cache).
 */
function cpcf_purge_urls( array $urls, $reason = '' ) {
	if ( '' === $reason ) {
		$reason = __( 'URL purge requested by code', 'pantheon-clear-caches-plus-cloudflare' );
	}

	$collector = cpcf()->purger->collector();
	$resolved  = array();

	foreach ( $urls as $url ) {
		$resolved[] = $collector->resolve_url( $url );
	}

	return cpcf()->purger->execute( false, $resolved, array( $reason ), 'api' );
}

/**
 * Purge everything affected by a post, using the configured purge mode for its post type.
 *
 * @param int|\WP_Post $post   Post ID or object.
 * @param string       $reason Human readable reason recorded in the purge log.
 * @return array|null Results keyed by target, or null when the post could not be found or its mode is "none".
 */
function cpcf_purge_post( $post, $reason = '' ) {
	$post = get_post( $post );

	if ( ! $post instanceof WP_Post ) {
		return null;
	}

	if ( '' === $reason ) {
		/* translators: %s: post title */
		$reason = sprintf( __( 'Purge requested by code for "%s"', 'pantheon-clear-caches-plus-cloudflare' ), $post->post_title );
	}

	return cpcf()->purger->purge_post_now( $post, array( 'trigger' => $reason ), 'api' );
}
