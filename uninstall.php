<?php
/**
 * Remove all plugin data on uninstall.
 *
 * @package CPCF
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

/**
 * Delete the plugin's options, transients, cron events and post meta for the current site.
 */
function cpcf_uninstall_site() {
	delete_option( 'cpcf_settings' );
	delete_option( 'cpcf_log' );
	delete_option( 'cpcf_retry_jobs' );

	wp_clear_scheduled_hook( 'cpcf_retry_purge' );

	delete_post_meta_by_key( '_cpcf_purge_mode' );
	delete_post_meta_by_key( '_cpcf_extra_urls' );
	delete_post_meta_by_key( '_cpcf_depends_on' );

	global $wpdb;

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One-off cleanup of transients on uninstall.
	$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_cpcf\\_notice\\_%' OR option_name LIKE '\\_transient\\_timeout\\_cpcf\\_notice\\_%'" );
}

if ( is_multisite() ) {
	$cpcf_site_ids = get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	);

	foreach ( $cpcf_site_ids as $cpcf_site_id ) {
		switch_to_blog( $cpcf_site_id );
		cpcf_uninstall_site();
		restore_current_blog();
	}
} else {
	cpcf_uninstall_site();
}
