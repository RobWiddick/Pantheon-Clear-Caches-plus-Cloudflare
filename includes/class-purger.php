<?php
/**
 * Purge orchestration.
 *
 * @package CPCF
 */

namespace CPCF;

defined( 'ABSPATH' ) || exit;

/**
 * Collects purge requests during a request and executes them once at shutdown.
 */
class Purger {

	/**
	 * Cron hook used to retry rate limited purges.
	 */
	const RETRY_HOOK = 'cpcf_retry_purge';

	/**
	 * Option that stores pending retry jobs.
	 */
	const RETRY_OPTION = 'cpcf_retry_jobs';

	/**
	 * Settings.
	 *
	 * @var Settings
	 */
	private $settings;

	/**
	 * Log.
	 *
	 * @var Log
	 */
	private $log;

	/**
	 * URL collector.
	 *
	 * @var URL_Collector
	 */
	private $collector;

	/**
	 * Posts queued for purging in this request: post ID => context.
	 *
	 * @var array
	 */
	private $queued_posts = array();

	/**
	 * Plan accumulated from non-post triggers.
	 *
	 * @var array
	 */
	private $plan;

	/**
	 * Whether the shutdown flush is scheduled.
	 *
	 * @var bool
	 */
	private $scheduled = false;

	/**
	 * Re-entrancy guard.
	 *
	 * @var bool
	 */
	private $flushing = false;

	/**
	 * Constructor.
	 *
	 * @param Settings $settings Settings.
	 * @param Log      $log      Log.
	 */
	public function __construct( Settings $settings, Log $log ) {
		$this->settings  = $settings;
		$this->log       = $log;
		$this->collector = new URL_Collector( $settings );
		$this->plan      = $this->collector->empty_plan();

		add_action( self::RETRY_HOOK, array( $this, 'run_retry_job' ) );
	}

	/**
	 * URL collector.
	 *
	 * @return URL_Collector
	 */
	public function collector() {
		return $this->collector;
	}

	/**
	 * Whether automatic purges may run right now (master switch and environment check).
	 *
	 * @param string $context What triggered the check (for the filter).
	 * @return bool
	 */
	public function automatic_purges_allowed( $context = '' ) {
		$allowed = (bool) $this->settings->get( 'enabled' );

		if ( $allowed && $this->settings->get( 'production_only' ) && ! Environment::is_production() ) {
			$allowed = false;
		}

		/**
		 * Filter whether automatic purges may run.
		 *
		 * @param bool   $allowed Whether purges may run.
		 * @param string $context Trigger context.
		 */
		return (bool) apply_filters( 'cpcf_automatic_purges_allowed', $allowed, $context );
	}

	/**
	 * Queue a post for purging at the end of the request (URLs are computed then, once all data is saved).
	 *
	 * @param int   $post_id Post ID.
	 * @param array $context Context: old (snapshot), trigger, removed.
	 */
	public function queue_post( $post_id, array $context = array() ) {
		$post_id = (int) $post_id;

		if ( isset( $this->queued_posts[ $post_id ] ) ) {
			// Keep the earliest snapshot; it reflects the state before the first change in this request.
			$existing = $this->queued_posts[ $post_id ];

			if ( empty( $existing['old'] ) && ! empty( $context['old'] ) ) {
				$existing['old'] = $context['old'];
			}

			if ( ! empty( $context['removed'] ) ) {
				$existing['removed'] = true;
			}

			$this->queued_posts[ $post_id ] = $existing;
		} else {
			$this->queued_posts[ $post_id ] = $context;
		}

		$this->schedule();
	}

	/**
	 * Add removed term archive URLs to a queued post's context.
	 *
	 * @param int      $post_id Post ID.
	 * @param string[] $urls    Term archive URLs.
	 */
	public function add_old_term_urls( $post_id, array $urls ) {
		$post_id = (int) $post_id;

		if ( ! isset( $this->queued_posts[ $post_id ] ) ) {
			$this->queued_posts[ $post_id ] = array();
			$this->schedule();
		}

		if ( empty( $this->queued_posts[ $post_id ]['old'] ) || ! is_array( $this->queued_posts[ $post_id ]['old'] ) ) {
			$this->queued_posts[ $post_id ]['old'] = array();
		}

		$existing = isset( $this->queued_posts[ $post_id ]['old']['term_urls'] ) ? (array) $this->queued_posts[ $post_id ]['old']['term_urls'] : array();

		$this->queued_posts[ $post_id ]['old']['term_urls'] = array_merge( $existing, $urls );
	}

	/**
	 * Request a full purge at the end of the request.
	 *
	 * @param string $reason Reason for the log.
	 */
	public function request_everything( $reason ) {
		$this->plan['everything'] = true;
		$this->plan['reasons'][]  = (string) $reason;
		$this->schedule();
	}

	/**
	 * Request a plan (URLs/prefixes) to be purged at the end of the request.
	 *
	 * @param array  $plan   Plan from the collector.
	 * @param string $reason Reason for the log.
	 */
	public function request_plan( array $plan, $reason = '' ) {
		if ( '' !== $reason ) {
			$plan['reasons'][] = (string) $reason;
		}

		$this->plan = $this->collector->merge_plans( $this->plan, $plan );
		$this->schedule();
	}

	/**
	 * Request a list of URLs to be purged at the end of the request.
	 *
	 * @param string[] $urls   Absolute URLs.
	 * @param string   $reason Reason for the log.
	 */
	public function request_urls( array $urls, $reason = '' ) {
		$plan         = $this->collector->empty_plan();
		$plan['urls'] = $urls;

		$this->request_plan( $plan, $reason );
	}

	/**
	 * Purge a post right away (used for deletions, where the data is about to disappear, and the public API).
	 *
	 * @param \WP_Post $post    Post.
	 * @param array    $context Context.
	 * @param string   $source  Source label for the log.
	 * @return array|null Results, or null when nothing was purged.
	 */
	public function purge_post_now( \WP_Post $post, array $context = array(), $source = 'automatic' ) {
		$plan = $this->plan_for_queued_post( $post, $context );

		if ( null === $plan ) {
			return null;
		}

		return $this->execute_plan( $plan, $source );
	}

	/**
	 * Resolve the plan for a post according to its purge mode.
	 *
	 * @param \WP_Post $post    Post.
	 * @param array    $context Context.
	 * @return array|null Plan or null when the mode is "none".
	 */
	private function plan_for_queued_post( \WP_Post $post, array $context ) {
		$mode = $this->settings->mode_for_post( $post );

		if ( 'none' === $mode ) {
			return null;
		}

		$type  = get_post_type_object( $post->post_type );
		$label = ( $type && ! empty( $type->labels->singular_name ) ) ? $type->labels->singular_name : $post->post_type;
		$title = '' !== $post->post_title ? $post->post_title : '#' . $post->ID;

		if ( ! empty( $context['trigger'] ) ) {
			$reason = (string) $context['trigger'];
		} else {
			/* translators: 1: post type label, 2: post title */
			$reason = sprintf( __( '%1$s "%2$s" changed', 'cache-purge-control-for-cloudflare' ), $label, $title );
		}

		if ( 'everything' === $mode ) {
			$plan               = $this->collector->empty_plan();
			$plan['everything'] = true;
			$plan['reasons'][]  = $reason;

			return $plan;
		}

		$plan              = $this->collector->plan_for_post( $post, $context );
		$plan['reasons'][] = $reason;

		return $plan;
	}

	/**
	 * Schedule the shutdown flush once.
	 */
	private function schedule() {
		if ( ! $this->scheduled ) {
			add_action( 'shutdown', array( $this, 'flush' ), 5 );
			$this->scheduled = true;
		}
	}

	/**
	 * Execute all queued purges.
	 */
	public function flush() {
		if ( $this->flushing ) {
			return;
		}

		$this->flushing = true;

		$plan  = $this->plan;
		$posts = $this->queued_posts;

		$this->plan         = $this->collector->empty_plan();
		$this->queued_posts = array();
		$this->scheduled    = false;

		foreach ( $posts as $post_id => $context ) {
			$post = get_post( $post_id );

			if ( ! $post instanceof \WP_Post ) {
				continue;
			}

			$post_plan = $this->plan_for_queued_post( $post, is_array( $context ) ? $context : array() );

			if ( null !== $post_plan ) {
				$plan = $this->collector->merge_plans( $plan, $post_plan );
			}
		}

		if ( $plan['everything'] || ! empty( $plan['urls'] ) || ! empty( $plan['prefixes'] ) ) {
			$this->execute_plan( $plan, 'automatic' );
		}

		$this->flushing = false;
	}

	/**
	 * Execute a plan: decide between a full purge and a targeted purge, then run it.
	 *
	 * @param array  $plan   Plan.
	 * @param string $source Source label.
	 * @return array Results.
	 */
	public function execute_plan( array $plan, $source = 'automatic' ) {
		$plan  = $this->collector->normalize_plan( $plan );
		$limit = (int) $this->settings->get( 'targeted_fallback_limit' );

		if ( ! $plan['everything'] && $limit > 0 && count( $plan['urls'] ) > $limit ) {
			$plan['everything'] = true;
			/* translators: 1: number of affected URLs, 2: configured limit */
			$plan['reasons'][] = sprintf( __( '%1$d affected URLs exceeded the limit of %2$d', 'cache-purge-control-for-cloudflare' ), count( $plan['urls'] ), $limit );
		}

		return $this->execute( $plan['everything'], $plan['urls'], $plan['reasons'], $source, $plan['prefixes'], $plan['fallback_urls'] );
	}

	/**
	 * Run a purge against every configured cache layer.
	 *
	 * @param bool     $everything    Purge the whole zone.
	 * @param string[] $urls          URLs to purge (ignored when purging everything).
	 * @param string[] $reasons       Reasons for the log.
	 * @param string   $source        Source label (automatic, manual, api, retry).
	 * @param string[] $prefixes      Archive prefixes to purge (Cloudflare purge-by-prefix).
	 * @param string[] $fallback_urls Paginated archive URLs used when prefixes cannot be purged.
	 * @return array Results keyed by cloudflare, pantheon and object_cache.
	 */
	public function execute( $everything, array $urls, array $reasons = array(), $source = 'manual', array $prefixes = array(), array $fallback_urls = array() ) {
		$everything    = (bool) $everything;
		$urls          = $everything ? array() : $this->collector->normalize_urls( $urls );
		$fallback_urls = $everything ? array() : $this->collector->normalize_urls( $fallback_urls );
		$prefixes      = $everything ? array() : array_values( array_unique( array_filter( $prefixes ) ) );

		/**
		 * Filter the URLs about to be purged.
		 *
		 * @param string[] $urls       URLs.
		 * @param bool     $everything Whether the whole zone is purged instead.
		 * @param string[] $reasons    Reasons.
		 * @param string   $source     Source label.
		 */
		$urls = (array) apply_filters( 'cpcf_purge_urls', $urls, $everything, $reasons, $source );

		/**
		 * Filter the prefixes about to be purged.
		 *
		 * @param string[] $prefixes   Prefixes ("host/path/").
		 * @param bool     $everything Whether the whole zone is purged instead.
		 * @param string   $source     Source label.
		 */
		$prefixes = (array) apply_filters( 'cpcf_purge_prefixes', $prefixes, $everything, $source );

		$results = array(
			'cloudflare'   => null,
			'pantheon'     => null,
			'object_cache' => null,
		);

		/**
		 * Fires before a purge runs.
		 *
		 * @param bool     $everything Whether the whole zone is purged.
		 * @param string[] $urls       URLs.
		 * @param string[] $reasons    Reasons.
		 * @param string   $source     Source label.
		 */
		do_action( 'cpcf_before_purge', $everything, $urls, $reasons, $source );

		// WordPress object cache.
		if ( $this->settings->get( 'flush_object_cache' ) ) {
			wp_cache_flush();
			$results['object_cache'] = true;
		}

		// Pantheon Advanced Page Cache.
		if ( $this->settings->get( 'pantheon_purge' ) && Environment::has_pantheon_purge() ) {
			$results['pantheon'] = $this->purge_pantheon( $everything, array_merge( $urls, $fallback_urls ) );
		}

		// Cloudflare.
		$cloudflare_urls = $urls;

		if ( ! $everything && $this->settings->get( 'scheme_variants' ) ) {
			$cloudflare_urls = $this->collector->scheme_variants( $cloudflare_urls );
		}

		$results['cloudflare'] = $this->purge_cloudflare( $everything, $cloudflare_urls, $prefixes, $fallback_urls );

		$success = ! is_wp_error( $results['cloudflare'] );
		$message = $success ? '' : $results['cloudflare']->get_error_message();

		if ( ! $success && 'cpcf_rate_limited' === $results['cloudflare']->get_error_code() && 'retry' !== $source ) {
			$data  = $results['cloudflare']->get_error_data();
			$delay = ( is_array( $data ) && ! empty( $data['retry_after'] ) ) ? (int) $data['retry_after'] : 60;

			$this->schedule_retry( $everything, $urls, $prefixes, $fallback_urls, $reasons, $delay );
			/* translators: %d: seconds */
			$message .= ' ' . sprintf( __( 'A retry is scheduled in %d seconds.', 'cache-purge-control-for-cloudflare' ), $delay );
		}

		$this->log->add(
			array(
				'source'     => $source,
				'everything' => $everything,
				'urls'       => $urls,
				'prefixes'   => $prefixes,
				'url_count'  => count( $urls ),
				'reasons'    => $reasons,
				'targets'    => array(
					'cloudflare'   => is_wp_error( $results['cloudflare'] ) ? 'error' : ( null === $results['cloudflare'] ? 'skipped' : 'ok' ),
					'pantheon'     => is_wp_error( $results['pantheon'] ) ? 'error' : ( null === $results['pantheon'] ? 'skipped' : 'ok' ),
					'object_cache' => $results['object_cache'] ? 'ok' : 'skipped',
				),
				'success'    => $success,
				'message'    => $message,
			)
		);

		/**
		 * Fires after a purge ran.
		 *
		 * @param bool     $everything Whether the whole zone was purged.
		 * @param string[] $urls       URLs.
		 * @param string[] $reasons    Reasons.
		 * @param string   $source     Source label.
		 * @param array    $results    Results keyed by cache layer.
		 */
		do_action( 'cpcf_after_purge', $everything, $urls, $reasons, $source, $results );

		return $results;
	}

	/**
	 * Purge Pantheon's edge cache.
	 *
	 * @param bool     $everything Whether to purge everything.
	 * @param string[] $urls       URLs to convert into paths.
	 * @return true|\WP_Error|null Null when no function was available for the requested operation.
	 */
	private function purge_pantheon( $everything, array $urls ) {
		if ( $everything ) {
			if ( function_exists( 'pantheon_wp_clear_edge_all' ) ) {
				$result = pantheon_wp_clear_edge_all();

				return ( false === $result ) ? new \WP_Error( 'cpcf_pantheon', __( 'Pantheon edge purge failed.', 'cache-purge-control-for-cloudflare' ) ) : true;
			}

			return null;
		}

		if ( ! function_exists( 'pantheon_wp_clear_edge_paths' ) ) {
			return null;
		}

		$paths = $this->collector->urls_to_paths( $urls );

		if ( empty( $paths ) ) {
			return null;
		}

		$result = pantheon_wp_clear_edge_paths( $paths );

		return ( false === $result ) ? new \WP_Error( 'cpcf_pantheon', __( 'Pantheon edge purge failed.', 'cache-purge-control-for-cloudflare' ) ) : true;
	}

	/**
	 * Purge Cloudflare.
	 *
	 * @param bool     $everything    Whether to purge everything.
	 * @param string[] $urls          URLs.
	 * @param string[] $prefixes      Prefixes.
	 * @param string[] $fallback_urls Paginated URLs used when prefix purging fails.
	 * @return true|\WP_Error
	 */
	private function purge_cloudflare( $everything, array $urls, array $prefixes, array $fallback_urls ) {
		$token   = $this->settings->get_api_token();
		$zone_id = $this->settings->get_zone_id();

		if ( '' === $token || '' === $zone_id ) {
			return new \WP_Error( 'cpcf_not_connected', __( 'Cloudflare is not connected. Add an API token and choose a zone in the plugin settings.', 'cache-purge-control-for-cloudflare' ) );
		}

		$api = new Cloudflare_API( $token );

		if ( $everything ) {
			return $api->purge_everything( $zone_id );
		}

		$errors = new \WP_Error();

		if ( ! empty( $urls ) ) {
			$result = $api->purge_urls( $zone_id, $urls );

			if ( is_wp_error( $result ) ) {
				$errors->add( $result->get_error_code(), $result->get_error_message(), $result->get_error_data() );
			}
		}

		if ( ! empty( $prefixes ) ) {
			$result = $api->purge_prefixes( $zone_id, $prefixes );

			if ( is_wp_error( $result ) ) {
				if ( 'cpcf_rate_limited' === $result->get_error_code() ) {
					$errors->add( $result->get_error_code(), $result->get_error_message(), $result->get_error_data() );
				} elseif ( ! empty( $fallback_urls ) ) {
					// Prefix purging is unavailable for this zone/plan: purge the paginated URLs instead.
					$fallback = $this->settings->get( 'scheme_variants' ) ? $this->collector->scheme_variants( $fallback_urls ) : $fallback_urls;
					$result   = $api->purge_urls( $zone_id, $fallback );

					if ( is_wp_error( $result ) ) {
						$errors->add( $result->get_error_code(), $result->get_error_message(), $result->get_error_data() );
					}
				} else {
					$errors->add( $result->get_error_code(), $result->get_error_message(), $result->get_error_data() );
				}
			}
		} elseif ( ! empty( $fallback_urls ) ) {
			$fallback = $this->settings->get( 'scheme_variants' ) ? $this->collector->scheme_variants( $fallback_urls ) : $fallback_urls;
			$result   = $api->purge_urls( $zone_id, $fallback );

			if ( is_wp_error( $result ) ) {
				$errors->add( $result->get_error_code(), $result->get_error_message(), $result->get_error_data() );
			}
		}

		if ( $errors->has_errors() ) {
			$codes = $errors->get_error_codes();

			return new \WP_Error(
				in_array( 'cpcf_rate_limited', $codes, true ) ? 'cpcf_rate_limited' : $codes[0],
				implode( ' ', array_unique( $errors->get_error_messages() ) ),
				$errors->get_error_data( in_array( 'cpcf_rate_limited', $codes, true ) ? 'cpcf_rate_limited' : $codes[0] )
			);
		}

		return true;
	}

	/**
	 * Schedule a retry of a rate limited purge via WP-Cron.
	 *
	 * @param bool     $everything    Whether to purge everything.
	 * @param string[] $urls          URLs.
	 * @param string[] $prefixes      Prefixes.
	 * @param string[] $fallback_urls Paginated URLs.
	 * @param string[] $reasons       Reasons.
	 * @param int      $delay         Seconds to wait.
	 */
	private function schedule_retry( $everything, array $urls, array $prefixes, array $fallback_urls, array $reasons, $delay ) {
		$jobs = get_option( self::RETRY_OPTION, array() );

		if ( ! is_array( $jobs ) ) {
			$jobs = array();
		}

		$job_id = wp_generate_password( 12, false );

		$jobs[ $job_id ] = array(
			'everything'    => (bool) $everything,
			'urls'          => array_slice( $urls, 0, 1000 ),
			'prefixes'      => array_slice( $prefixes, 0, 200 ),
			'fallback_urls' => array_slice( $fallback_urls, 0, 500 ),
			'reasons'       => array_slice( $reasons, 0, 10 ),
			'created'       => time(),
		);

		// Keep the option small.
		$jobs = array_slice( $jobs, -10, 10, true );

		update_option( self::RETRY_OPTION, $jobs, false );
		wp_schedule_single_event( time() + max( 30, (int) $delay ), self::RETRY_HOOK, array( $job_id ) );
	}

	/**
	 * Cron callback: run a retry job.
	 *
	 * @param string $job_id Job ID.
	 */
	public function run_retry_job( $job_id ) {
		$jobs = get_option( self::RETRY_OPTION, array() );

		if ( ! is_array( $jobs ) || empty( $jobs[ $job_id ] ) ) {
			return;
		}

		$job = $jobs[ $job_id ];
		unset( $jobs[ $job_id ] );

		if ( empty( $jobs ) ) {
			delete_option( self::RETRY_OPTION );
		} else {
			update_option( self::RETRY_OPTION, $jobs, false );
		}

		$reasons   = (array) $job['reasons'];
		$reasons[] = __( 'Retry after rate limit', 'cache-purge-control-for-cloudflare' );

		$this->execute( ! empty( $job['everything'] ), (array) $job['urls'], $reasons, 'retry', (array) $job['prefixes'], (array) $job['fallback_urls'] );
	}
}
