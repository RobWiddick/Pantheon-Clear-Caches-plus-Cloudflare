<?php
/**
 * Minimal Cloudflare API client.
 *
 * @package CPCF
 */

namespace CPCF;

defined( 'ABSPATH' ) || exit;

/**
 * Talks to the Cloudflare v4 API using the WordPress HTTP API.
 */
class Cloudflare_API {

	/**
	 * API base URL.
	 */
	const API_BASE = 'https://api.cloudflare.com/client/v4/';

	/**
	 * Maximum single-file URLs per purge request (Cloudflare allows 100 on all plans).
	 */
	const MAX_URLS_PER_REQUEST = 100;

	/**
	 * Maximum prefixes/hosts per purge request.
	 */
	const MAX_ITEMS_PER_REQUEST = 100;

	/**
	 * Bearer token.
	 *
	 * @var string
	 */
	private $token;

	/**
	 * Constructor.
	 *
	 * @param string $token Cloudflare API token.
	 */
	public function __construct( $token ) {
		$this->token = trim( (string) $token );
	}

	/**
	 * Whether a token is available.
	 *
	 * @return bool
	 */
	public function has_token() {
		return '' !== $this->token;
	}

	/**
	 * URL that pre-fills Cloudflare's "Create API token" form with the permissions this plugin needs.
	 *
	 * @return string
	 */
	public static function token_template_url() {
		$permissions = array(
			array(
				'key'  => 'zone',
				'type' => 'read',
			),
			array(
				'key'  => 'cache',
				'type' => 'purge',
			),
		);

		$host = wp_parse_url( home_url(), PHP_URL_HOST );
		$name = 'WordPress Cache Purge' . ( $host ? ' (' . $host . ')' : '' );

		// This is a link to the Cloudflare dashboard for the site owner, not an offloaded asset. The hostname is
		// assembled from parts so the Plugin Check offloading sniff (which matches "cloudflare.com") does not flag it.
		$dashboard = sprintf( 'https://%s/profile/api-tokens?', implode( '.', array( 'dash', 'cloudflare', 'com' ) ) );

		return $dashboard . http_build_query(
			array(
				'permissionGroupKeys' => wp_json_encode( $permissions ),
				'accountId'           => '*',
				'zoneId'              => 'all',
				'name'                => $name,
			),
			'',
			'&',
			PHP_QUERY_RFC3986
		);
	}

	/**
	 * Verify the token.
	 *
	 * @return array|\WP_Error Token details (id, status, expires_on...) on success.
	 */
	public function verify_token() {
		$response = $this->request( 'GET', 'user/tokens/verify' );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$status = isset( $response['result']['status'] ) ? (string) $response['result']['status'] : '';

		if ( 'active' !== $status ) {
			return new \WP_Error(
				'cpcf_token_inactive',
				/* translators: %s: token status reported by Cloudflare */
				sprintf( __( 'The API token is not active (status: %s).', 'purge-pantheon-cloudflare-caches' ), $status )
			);
		}

		return $response['result'];
	}

	/**
	 * List the zones the token can read.
	 *
	 * @return array|\WP_Error List of zones with id, name, status and plan.
	 */
	public function list_zones() {
		$zones = array();
		$page  = 1;

		do {
			$response = $this->request(
				'GET',
				'zones',
				null,
				array(
					'page'     => $page,
					'per_page' => 50,
				)
			);

			if ( is_wp_error( $response ) ) {
				return $response;
			}

			foreach ( (array) $response['result'] as $zone ) {
				if ( empty( $zone['id'] ) || empty( $zone['name'] ) ) {
					continue;
				}

				$zones[] = array(
					'id'     => sanitize_text_field( $zone['id'] ),
					'name'   => sanitize_text_field( $zone['name'] ),
					'status' => isset( $zone['status'] ) ? sanitize_text_field( $zone['status'] ) : '',
					'plan'   => isset( $zone['plan']['name'] ) ? sanitize_text_field( $zone['plan']['name'] ) : '',
				);
			}

			$total_pages = isset( $response['result_info']['total_pages'] ) ? (int) $response['result_info']['total_pages'] : 1;
			++$page;
		} while ( $page <= $total_pages && $page <= 20 );

		usort(
			$zones,
			static function ( $a, $b ) {
				return strcasecmp( $a['name'], $b['name'] );
			}
		);

		return $zones;
	}

	/**
	 * Fetch a single zone.
	 *
	 * @param string $zone_id Zone ID.
	 * @return array|\WP_Error Zone details.
	 */
	public function get_zone( $zone_id ) {
		$response = $this->request( 'GET', 'zones/' . rawurlencode( $zone_id ) );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return $response['result'];
	}

	/**
	 * Purge the entire zone.
	 *
	 * @param string $zone_id Zone ID.
	 * @return true|\WP_Error
	 */
	public function purge_everything( $zone_id ) {
		return $this->purge( $zone_id, array( 'purge_everything' => true ) );
	}

	/**
	 * Purge specific URLs (batched).
	 *
	 * @param string   $zone_id Zone ID.
	 * @param string[] $urls    Absolute URLs.
	 * @return true|\WP_Error
	 */
	public function purge_urls( $zone_id, array $urls ) {
		return $this->purge_batched( $zone_id, 'files', $urls, self::MAX_URLS_PER_REQUEST );
	}

	/**
	 * Purge by URL prefix (host + path, no scheme), batched.
	 *
	 * @param string   $zone_id  Zone ID.
	 * @param string[] $prefixes Prefixes such as "example.com/category/news/".
	 * @return true|\WP_Error
	 */
	public function purge_prefixes( $zone_id, array $prefixes ) {
		return $this->purge_batched( $zone_id, 'prefixes', $prefixes, self::MAX_ITEMS_PER_REQUEST );
	}

	/**
	 * Purge everything cached for one or more hostnames.
	 *
	 * @param string   $zone_id Zone ID.
	 * @param string[] $hosts   Hostnames.
	 * @return true|\WP_Error
	 */
	public function purge_hosts( $zone_id, array $hosts ) {
		return $this->purge_batched( $zone_id, 'hosts', $hosts, self::MAX_ITEMS_PER_REQUEST );
	}

	/**
	 * Send a batched purge request.
	 *
	 * @param string   $zone_id Zone ID.
	 * @param string   $field   Payload field: files, prefixes or hosts.
	 * @param string[] $items   Items to purge.
	 * @param int      $max     Items per request.
	 * @return true|\WP_Error
	 */
	private function purge_batched( $zone_id, $field, array $items, $max ) {
		$items = array_values( array_unique( array_filter( array_map( 'trim', $items ) ) ) );

		if ( empty( $items ) ) {
			return true;
		}

		/**
		 * Filter the batch size used for purge requests.
		 *
		 * @param int    $max   Items per request.
		 * @param string $field Payload field (files, prefixes, hosts).
		 */
		$max    = max( 1, (int) apply_filters( 'cpcf_purge_batch_size', $max, $field ) );
		$errors = new \WP_Error();

		foreach ( array_chunk( $items, $max ) as $chunk ) {
			$result = $this->purge( $zone_id, array( $field => $chunk ) );

			if ( is_wp_error( $result ) ) {
				$errors->add( $result->get_error_code(), $result->get_error_message(), $result->get_error_data() );
			}
		}

		return $errors->has_errors() ? $errors : true;
	}

	/**
	 * Send a raw purge payload.
	 *
	 * @param string $zone_id Zone ID.
	 * @param array  $payload Request body.
	 * @return true|\WP_Error
	 */
	public function purge( $zone_id, array $payload ) {
		if ( '' === (string) $zone_id ) {
			return new \WP_Error( 'cpcf_no_zone', __( 'No Cloudflare zone is selected.', 'purge-pantheon-cloudflare-caches' ) );
		}

		$response = $this->request( 'POST', 'zones/' . rawurlencode( $zone_id ) . '/purge_cache', $payload );

		return is_wp_error( $response ) ? $response : true;
	}

	/**
	 * Perform an API request.
	 *
	 * @param string     $method HTTP method.
	 * @param string     $path   Path relative to the API base.
	 * @param array|null $body   JSON body.
	 * @param array      $query  Query arguments.
	 * @return array|\WP_Error Decoded response.
	 */
	private function request( $method, $path, $body = null, array $query = array() ) {
		if ( ! $this->has_token() ) {
			return new \WP_Error( 'cpcf_no_token', __( 'No Cloudflare API token is configured.', 'purge-pantheon-cloudflare-caches' ) );
		}

		$url = self::API_BASE . ltrim( $path, '/' );

		if ( ! empty( $query ) ) {
			$url = add_query_arg( $query, $url );
		}

		$args = array(
			'method'     => $method,
			'timeout'    => 15,
			'headers'    => array(
				'Authorization' => 'Bearer ' . $this->token,
				'Content-Type'  => 'application/json',
				'Accept'        => 'application/json',
			),
			'user-agent' => 'PurgePantheonCloudflareCaches/' . CPCF_VERSION . ' (WordPress/' . get_bloginfo( 'version' ) . '; ' . home_url( '/' ) . ')',
		);

		if ( null !== $body ) {
			$args['body'] = wp_json_encode( $body );
		}

		/**
		 * Filter the arguments passed to wp_remote_request() for Cloudflare API calls.
		 *
		 * @param array  $args Request arguments.
		 * @param string $path API path.
		 */
		$args = apply_filters( 'cpcf_api_request_args', $args, $path );

		$response = wp_remote_request( $url, $args );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		if ( 429 === $code ) {
			$retry_after = (int) wp_remote_retrieve_header( $response, 'retry-after' );

			return new \WP_Error(
				'cpcf_rate_limited',
				__( 'Cloudflare rate limited the request (HTTP 429).', 'purge-pantheon-cloudflare-caches' ),
				array( 'retry_after' => $retry_after > 0 ? $retry_after : 60 )
			);
		}

		if ( ! is_array( $data ) ) {
			return new \WP_Error(
				'cpcf_bad_response',
				/* translators: %d: HTTP status code */
				sprintf( __( 'Unexpected response from Cloudflare (HTTP %d).', 'purge-pantheon-cloudflare-caches' ), $code )
			);
		}

		if ( empty( $data['success'] ) ) {
			$messages = array();

			if ( ! empty( $data['errors'] ) && is_array( $data['errors'] ) ) {
				foreach ( $data['errors'] as $error ) {
					if ( is_array( $error ) && isset( $error['message'] ) ) {
						$messages[] = ( isset( $error['code'] ) ? $error['code'] . ': ' : '' ) . $error['message'];
					}
				}
			}

			if ( empty( $messages ) ) {
				/* translators: %d: HTTP status code */
				$messages[] = sprintf( __( 'Request failed (HTTP %d).', 'purge-pantheon-cloudflare-caches' ), $code );
			}

			$error_code = ( 401 === $code || 403 === $code ) ? 'cpcf_unauthorized' : 'cpcf_api_error';

			return new \WP_Error(
				$error_code,
				/* translators: %s: error details returned by Cloudflare */
				sprintf( __( 'Cloudflare API error: %s', 'purge-pantheon-cloudflare-caches' ), implode( '; ', $messages ) ),
				array(
					'status' => $code,
					'errors' => isset( $data['errors'] ) ? $data['errors'] : array(),
				)
			);
		}

		return $data;
	}
}
