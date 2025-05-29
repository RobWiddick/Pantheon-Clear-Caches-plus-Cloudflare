<?php
/**
 *  Plugin Name: 36 Total Cache + Cloudflare Purger (MU)
 *  Description: Clears WP, Pantheon, and Cloudflare cache on post save
 *  Author: Rob Widdick
 *  Author URI: https://36.agency
 *  Version: 1.0
 */

// Only run on LIVE Pantheon Environment
if (defined('PANTHEON_ENVIRONMENT') && PANTHEON_ENVIRONMENT === 'live') {
    define("CLOUDFLARE_CONFIG_FILE", $_SERVER['HOME'] . '/files/private/cloudflare_cache_config.json');

    // Check if config file exists in files/private/cloudflare_cache_config.json
    if(file_exists(CLOUDFLARE_CONFIG_FILE)) {
        // Add action to purge CF cache on any post save
        add_action( 'save_post', 'clear_cloudflare_cache', 10, 3 );

        /**
         * Clear WP cache and Cloudflare caches on page save
         *
         * @return void
         */
        function clear_cloudflare_cache( $post_id, $post, $update ) {
            // Bail if this is an autosave.
            if ( (defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE) || wp_is_post_autosave($id) ) {
                return;
            }

            // Check if it is a REST Request
            if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
                return;
            }

            // Bail if this is a revision.
            if ( wp_is_post_revision( $post_id ) ) {
                return;
            }

            // Prevent this function from running more than once per request.
            static $already_run = false;
            if ( $already_run ) {
                return;
            }
            $already_run = true;

            // Only run on the first invocation. Final check to make sure we don't run this multiple times.
            if ( did_action('save_post') > 1 ) {
                return;
            }

            // Purge WP caches
            wp_cache_flush();

            // Purge all Pantheon edge caches
            if (function_exists('pantheon_wp_clear_edge_all')) {
                pantheon_wp_clear_edge_all();
            }

            // Purge all Cloudflare caches for zone
            $config = json_decode(file_get_contents(CLOUDFLARE_CONFIG_FILE), true);
            if ( $config !== false ) {
                $payload = json_encode(['purge_everything' => true]);
                $ch = curl_init();
                curl_setopt($ch, CURLOPT_URL, 'https://api.cloudflare.com/client/v4/zones/' . $config['zone_id'] . '/purge_cache');
                curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: Bearer ' . $config['auth_token'] ]);
                curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
                curl_exec($ch);
                curl_close($ch);
            }
        }
    }
}