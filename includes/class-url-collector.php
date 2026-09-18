<?php
/**
 * Works out which URLs are affected by a content change.
 *
 * @package CPCF
 */

namespace CPCF;

defined( 'ABSPATH' ) || exit;

/**
 * Builds "purge plans": lists of URLs and prefixes affected by a post, term, author or comment change.
 *
 * A plan is an array with:
 * - urls          Absolute URLs purged as single files.
 * - prefixes      "host/path/" prefixes (archives) purged with Cloudflare's purge-by-prefix.
 * - fallback_urls Paginated archive URLs used when prefix purging is disabled or fails, and for Pantheon.
 * - everything    Whether the whole zone should be purged instead.
 * - reasons       Human readable notes for the log.
 */
class URL_Collector {

	/**
	 * Settings.
	 *
	 * @var Settings
	 */
	private $settings;

	/**
	 * Constructor.
	 *
	 * @param Settings $settings Settings.
	 */
	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * An empty plan.
	 *
	 * @return array
	 */
	public function empty_plan() {
		return array(
			'urls'          => array(),
			'prefixes'      => array(),
			'fallback_urls' => array(),
			'everything'    => false,
			'reasons'       => array(),
		);
	}

	/**
	 * Merge two plans.
	 *
	 * @param array $a First plan.
	 * @param array $b Second plan.
	 * @return array
	 */
	public function merge_plans( array $a, array $b ) {
		$plan = $this->empty_plan();

		foreach ( array( 'urls', 'prefixes', 'fallback_urls', 'reasons' ) as $key ) {
			$plan[ $key ] = array_merge(
				isset( $a[ $key ] ) ? (array) $a[ $key ] : array(),
				isset( $b[ $key ] ) ? (array) $b[ $key ] : array()
			);
		}

		$plan['everything'] = ! empty( $a['everything'] ) || ! empty( $b['everything'] );

		return $this->normalize_plan( $plan );
	}

	/**
	 * Whether a post is publicly viewable right now.
	 *
	 * @param \WP_Post $post Post.
	 * @return bool
	 */
	public function is_public( \WP_Post $post ) {
		$type = get_post_type_object( $post->post_type );

		if ( ! $type || ! is_post_type_viewable( $type ) ) {
			return false;
		}

		if ( 'attachment' === $post->post_type ) {
			return 'inherit' === $post->post_status || in_array( $post->post_status, $this->public_stati(), true );
		}

		return in_array( $post->post_status, $this->public_stati(), true );
	}

	/**
	 * Public post statuses.
	 *
	 * @return string[]
	 */
	public function public_stati() {
		return array_values( get_post_stati( array( 'public' => true ) ) );
	}

	/**
	 * Capture the state of a post before it changes (old permalink, terms, title...).
	 *
	 * @param \WP_Post $post Post.
	 * @return array
	 */
	public function snapshot( \WP_Post $post ) {
		$snapshot = array(
			'permalink'     => '',
			'title'         => (string) $post->post_title,
			'slug'          => (string) $post->post_name,
			'status'        => (string) $post->post_status,
			'author'        => (int) $post->post_author,
			'term_urls'     => array(),
			'ancestor_urls' => array(),
		);

		if ( ! $this->is_public( $post ) ) {
			return $snapshot;
		}

		$snapshot['permalink'] = (string) get_permalink( $post );

		foreach ( $this->public_taxonomies( $post->post_type ) as $taxonomy ) {
			$terms = get_the_terms( $post, $taxonomy->name );

			if ( ! is_array( $terms ) ) {
				continue;
			}

			foreach ( $terms as $term ) {
				$link = get_term_link( $term, $taxonomy->name );

				if ( is_string( $link ) ) {
					$snapshot['term_urls'][] = $link;
				}
			}
		}

		if ( is_post_type_hierarchical( $post->post_type ) ) {
			foreach ( get_post_ancestors( $post ) as $ancestor_id ) {
				$snapshot['ancestor_urls'][] = (string) get_permalink( $ancestor_id );
			}
		}

		return $snapshot;
	}

	/**
	 * Build the purge plan for a changed post.
	 *
	 * @param \WP_Post $post    Post (current state).
	 * @param array    $context Context: old (snapshot), removed (bool), trigger (string).
	 * @return array Plan.
	 */
	public function plan_for_post( \WP_Post $post, array $context = array() ) {
		$context = wp_parse_args(
			$context,
			array(
				'old'     => null,
				'removed' => false,
				'trigger' => '',
			)
		);

		$settings      = $this->settings;
		$plan          = $this->empty_plan();
		$old           = is_array( $context['old'] ) ? $context['old'] : array();
		$is_public     = ! $context['removed'] && $this->is_public( $post );
		$is_attachment = ( 'attachment' === $post->post_type );
		$hierarchical  = is_post_type_hierarchical( $post->post_type );
		$permalink     = $is_public ? (string) get_permalink( $post ) : '';
		$home          = home_url( '/' );

		// The post itself (current and previous URL).
		if ( '' !== $permalink ) {
			$plan['urls'][] = $permalink;
		}

		if ( ! empty( $old['permalink'] ) ) {
			$plan['urls'][] = $old['permalink'];
		}

		if ( $is_attachment ) {
			$plan['urls'] = array_merge( $plan['urls'], $this->attachment_file_urls( $post ) );
		} elseif ( $settings->get( 'include_feeds' ) ) {
			if ( '' !== $permalink ) {
				$plan['urls'][] = get_post_comments_feed_link( $post->ID );
			}

			if ( ! empty( $old['permalink'] ) && untrailingslashit( $old['permalink'] ) !== untrailingslashit( $permalink ) ) {
				$plan['urls'][] = $this->feed_url_for( $old['permalink'] );
			}
		}

		// Front page and the blog index.
		if ( $settings->get( 'include_home' ) ) {
			/**
			 * Filter the post types that appear on the blog index (and therefore need its pagination purged).
			 *
			 * @param string[] $post_types Post type names.
			 */
			$home_types  = (array) apply_filters( 'cpcf_home_post_types', array( 'post' ) );
			$on_home     = in_array( $post->post_type, $home_types, true );
			$home_count  = $on_home ? $this->published_count( 'post' ) : 0;
			$posts_page  = (int) get_option( 'page_for_posts' );
			$show_static = ( 'page' === get_option( 'show_on_front' ) );

			$plan['urls'][] = $home;

			if ( $on_home ) {
				if ( $show_static && $posts_page > 0 ) {
					$this->add_archive( $plan, (string) get_permalink( $posts_page ), $home_count );
				} else {
					$this->add_archive( $plan, $home, $home_count );
				}
			}
		}

		// Post type archive.
		if ( $settings->get( 'include_post_type_archive' ) && ! $is_attachment ) {
			$archive = get_post_type_archive_link( $post->post_type );

			if ( is_string( $archive ) && untrailingslashit( $archive ) !== untrailingslashit( $home ) ) {
				$this->add_archive( $plan, $archive, $this->published_count( $post->post_type ) );

				if ( $settings->get( 'include_feeds' ) ) {
					$plan['urls'][] = get_post_type_archive_feed_link( $post->post_type );
				}
			}
		}

		// Taxonomy term archives (current terms and terms that were just removed).
		if ( $settings->get( 'include_terms' ) ) {
			foreach ( $this->public_taxonomies( $post->post_type ) as $taxonomy ) {
				$terms = get_the_terms( $post, $taxonomy->name );

				if ( ! is_array( $terms ) ) {
					continue;
				}

				foreach ( $terms as $term ) {
					$link = get_term_link( $term, $taxonomy->name );

					if ( ! is_string( $link ) ) {
						continue;
					}

					$this->add_archive( $plan, $link, (int) $term->count );

					if ( $settings->get( 'include_feeds' ) ) {
						$plan['urls'][] = get_term_feed_link( $term, $taxonomy->name );
					}
				}
			}

			if ( ! empty( $old['term_urls'] ) ) {
				foreach ( (array) $old['term_urls'] as $url ) {
					$this->add_archive( $plan, $url, null );
				}
			}
		}

		// Author archive.
		if ( $settings->get( 'include_author' ) && post_type_supports( $post->post_type, 'author' ) ) {
			$authors = array( (int) $post->post_author );

			if ( ! empty( $old['author'] ) ) {
				$authors[] = (int) $old['author'];
			}

			foreach ( array_unique( array_filter( $authors ) ) as $author_id ) {
				$this->add_archive( $plan, (string) get_author_posts_url( $author_id ), (int) count_user_posts( $author_id, $post->post_type, true ) );

				if ( $settings->get( 'include_feeds' ) ) {
					$plan['urls'][] = get_author_feed_link( $author_id );
				}
			}
		}

		// Date archives.
		if ( $settings->get( 'include_dates' ) && ! $is_attachment ) {
			/**
			 * Filter the post types that have date archives.
			 *
			 * @param string[] $post_types Post type names.
			 */
			$date_types = (array) apply_filters( 'cpcf_date_archive_post_types', array( 'post' ) );

			if ( in_array( $post->post_type, $date_types, true ) && ! empty( $post->post_date ) && '0000-00-00 00:00:00' !== $post->post_date ) {
				$year  = (int) get_the_date( 'Y', $post );
				$month = (int) get_the_date( 'm', $post );
				$day   = (int) get_the_date( 'd', $post );

				$this->add_archive( $plan, (string) get_day_link( $year, $month, $day ), null );
				$this->add_archive( $plan, (string) get_month_link( $year, $month ), null );
				$this->add_archive( $plan, (string) get_year_link( $year ), null );
			}
		}

		// Site feeds.
		if ( $settings->get( 'include_feeds' ) && ! $is_attachment ) {
			$plan['urls'][] = get_feed_link( 'rss2' );
			$plan['urls'][] = get_feed_link( 'atom' );
			$plan['urls'][] = get_feed_link( 'comments_rss2' );
		}

		// Parent pages (they often list their children).
		if ( $settings->get( 'include_ancestors' ) && $hierarchical ) {
			foreach ( get_post_ancestors( $post ) as $ancestor_id ) {
				$plan['urls'][] = (string) get_permalink( $ancestor_id );
			}

			if ( ! empty( $old['ancestor_urls'] ) ) {
				$plan['urls'] = array_merge( $plan['urls'], (array) $old['ancestor_urls'] );
			}
		}

		// Previous / next posts (their navigation links change).
		if ( $settings->get( 'include_adjacent' ) && ! $hierarchical && ! $is_attachment && $is_public ) {
			$plan['urls'] = array_merge( $plan['urls'], $this->adjacent_urls( $post ) );
		}

		// Content that references this post (blocks, shortcodes, links, custom fields).
		$lookup = (string) $settings->get( 'reference_lookup' );

		if ( 'none' !== $lookup ) {
			$references = $this->find_referencing_posts( $post, $old, $lookup );

			foreach ( $references['ids'] as $ref_id ) {
				$plan['urls'][] = (string) get_permalink( $ref_id );
			}

			if ( $references['truncated'] ) {
				$plan['everything'] = true;
				/* translators: %d: reference lookup limit */
				$plan['reasons'][] = sprintf( __( 'More than %d pages reference this content', 'cache-purge-control-for-cloudflare' ), (int) $settings->get( 'reference_limit' ) );
			}

			if ( $references['in_templates'] ) {
				$plan['everything'] = true;
				$plan['reasons'][]  = __( 'A block template, template part or navigation references this content', 'cache-purge-control-for-cloudflare' );
			}
		}

		// Pages that declared a dependency on this post type (meta box).
		foreach ( $this->dependent_post_ids( $post->post_type ) as $dependent_id ) {
			if ( (int) $dependent_id !== (int) $post->ID ) {
				$plan['urls'][] = (string) get_permalink( $dependent_id );
			}
		}

		// Extra URLs declared on the post itself.
		foreach ( Meta_Box::extra_urls( $post->ID ) as $entry ) {
			$this->add_user_entry( $plan, $entry );
		}

		// Dependency rules from the settings page.
		foreach ( $settings->rules() as $rule ) {
			if ( empty( $rule['post_type'] ) || $rule['post_type'] !== $post->post_type ) {
				continue;
			}

			foreach ( (array) $rule['urls'] as $entry ) {
				$this->add_user_entry( $plan, $entry );
			}

			if ( ! empty( $rule['everything'] ) ) {
				$plan['everything'] = true;
				/* translators: %s: post type name */
				$plan['reasons'][] = sprintf( __( 'A dependency rule for "%s" requires a full purge', 'cache-purge-control-for-cloudflare' ), $post->post_type );
			}
		}

		// URLs purged on every targeted purge.
		foreach ( $settings->always_purge_urls() as $entry ) {
			$this->add_user_entry( $plan, $entry );
		}

		// Escalate to a full purge when links to this post changed across the site.
		if ( $is_public && ! empty( $old['permalink'] ) && $settings->get( 'slug_change_everything' ) && untrailingslashit( $old['permalink'] ) !== untrailingslashit( $permalink ) ) {
			$plan['everything'] = true;
			$plan['reasons'][]  = __( 'The URL of the content changed', 'cache-purge-control-for-cloudflare' );
		}

		if ( ! empty( $old ) && $this->in_nav_menu( $post->ID ) ) {
			$menu_changed = ( ! $is_public && ! empty( $old['permalink'] ) )
				|| ( isset( $old['title'] ) && $old['title'] !== $post->post_title )
				|| ( isset( $old['slug'] ) && $old['slug'] !== $post->post_name );

			if ( $menu_changed ) {
				$plan['everything'] = true;
				$plan['reasons'][]  = __( 'The content is linked from a navigation menu', 'cache-purge-control-for-cloudflare' );
			}
		}

		/**
		 * Filter the purge plan for a post.
		 *
		 * @param array    $plan    Plan with urls, prefixes, fallback_urls, everything and reasons.
		 * @param \WP_Post $post    Post.
		 * @param array    $context Context passed by the caller.
		 */
		$plan = apply_filters( 'cpcf_post_purge_plan', $plan, $post, $context );

		return $this->normalize_plan( $plan );
	}

	/**
	 * Build the purge plan for a term change.
	 *
	 * @param array $term_data Term data: link, feed (optional), count, taxonomy.
	 * @return array Plan.
	 */
	public function plan_for_term( array $term_data ) {
		$plan = $this->empty_plan();

		if ( ! empty( $term_data['link'] ) ) {
			$this->add_archive( $plan, $term_data['link'], isset( $term_data['count'] ) ? (int) $term_data['count'] : null );
		}

		if ( ! empty( $term_data['feed'] ) ) {
			$plan['urls'][] = $term_data['feed'];
		}

		if ( $this->settings->get( 'include_home' ) ) {
			$plan['urls'][] = home_url( '/' );
		}

		foreach ( $this->settings->always_purge_urls() as $entry ) {
			$this->add_user_entry( $plan, $entry );
		}

		return $this->normalize_plan( $plan );
	}

	/**
	 * Build the purge plan for an author change.
	 *
	 * @param int $user_id User ID.
	 * @return array Plan.
	 */
	public function plan_for_author( $user_id ) {
		$plan = $this->empty_plan();

		$this->add_archive( $plan, (string) get_author_posts_url( $user_id ), (int) count_user_posts( $user_id, 'post', true ) );
		$plan['urls'][] = get_author_feed_link( $user_id );

		return $this->normalize_plan( $plan );
	}

	/**
	 * Build the purge plan for a comment change.
	 *
	 * @param \WP_Post $post Post the comment belongs to.
	 * @return array Plan.
	 */
	public function plan_for_comment( \WP_Post $post ) {
		$plan = $this->empty_plan();

		if ( $this->is_public( $post ) ) {
			$plan['urls'][] = (string) get_permalink( $post );
		}

		$plan['urls'][] = get_post_comments_feed_link( $post->ID );
		$plan['urls'][] = get_feed_link( 'comments_rss2' );

		return $this->normalize_plan( $plan );
	}

	/**
	 * Add an archive URL with its pagination (as a prefix and/or paginated URLs).
	 *
	 * @param array    $plan        Plan (by reference).
	 * @param string   $url         Archive URL.
	 * @param int|null $total_items Number of items in the archive, or null when unknown.
	 */
	private function add_archive( array &$plan, $url, $total_items ) {
		if ( ! is_string( $url ) || '' === $url ) {
			return;
		}

		$plan['urls'][] = $url;

		$pages = $this->pagination_count( $total_items );

		for ( $n = 2; $n <= $pages; $n++ ) {
			$plan['fallback_urls'][] = $this->paged_url( $url, $n );
		}

		if ( 'prefix' === $this->settings->get( 'archive_method' ) ) {
			$prefix = $this->prefix_for( $url );

			if ( '' !== $prefix ) {
				$plan['prefixes'][] = $prefix;
			}
		}
	}

	/**
	 * Add a user supplied URL/path entry (supports a trailing "*" wildcard for prefixes).
	 *
	 * @param array  $plan  Plan (by reference).
	 * @param string $entry URL, path or prefix ending in "*".
	 */
	private function add_user_entry( array &$plan, $entry ) {
		$entry = trim( (string) $entry );

		if ( '' === $entry ) {
			return;
		}

		if ( '*' === substr( $entry, -1 ) ) {
			$base = $this->resolve_url( rtrim( substr( $entry, 0, -1 ), '/' ) . '/' );

			if ( '' === $base ) {
				return;
			}

			$prefix = $this->prefix_for( $base );

			if ( '' !== $prefix ) {
				$plan['prefixes'][] = $prefix;
			}

			$plan['urls'][] = $base;

			return;
		}

		$url = $this->resolve_url( $entry );

		if ( '' !== $url ) {
			$plan['urls'][] = $url;
		}
	}

	/**
	 * Number of archive pages to purge for an item count (page 1 included).
	 *
	 * @param int|null $total_items Item count or null when unknown.
	 * @return int
	 */
	private function pagination_count( $total_items ) {
		$cap = (int) $this->settings->get( 'pagination_pages' );

		if ( $cap < 2 ) {
			return 1;
		}

		if ( null === $total_items ) {
			return $cap;
		}

		$per_page = max( 1, (int) get_option( 'posts_per_page' ) );

		// +1 covers a trailing page that may have just disappeared.
		return min( $cap, (int) ceil( max( 0, (int) $total_items ) / $per_page ) + 1 );
	}

	/**
	 * Build the URL of a paginated archive page.
	 *
	 * @param string $url  Archive URL.
	 * @param int    $page Page number.
	 * @return string
	 */
	public function paged_url( $url, $page ) {
		global $wp_rewrite;

		if ( $wp_rewrite instanceof \WP_Rewrite && $wp_rewrite->using_permalinks() && false === strpos( $url, '?' ) ) {
			return user_trailingslashit( trailingslashit( $url ) . $wp_rewrite->pagination_base . '/' . (int) $page, 'paged' );
		}

		return add_query_arg( 'paged', (int) $page, $url );
	}

	/**
	 * Convert an archive URL into a Cloudflare purge prefix ("host/path/").
	 *
	 * The site root is never returned as a prefix (that would purge everything); the home page's
	 * pagination is covered by the "host/page/" prefix instead.
	 *
	 * @param string $url URL.
	 * @return string Prefix or empty string when a prefix is not appropriate.
	 */
	public function prefix_for( $url ) {
		global $wp_rewrite;

		$parts = wp_parse_url( $url );

		if ( empty( $parts['host'] ) || ! empty( $parts['query'] ) ) {
			return '';
		}

		$path = isset( $parts['path'] ) ? $parts['path'] : '/';
		$path = trailingslashit( $path );
		$root = trailingslashit( (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH ) );

		if ( '' === $root ) {
			$root = '/';
		}

		if ( $path === $root ) {
			if ( $wp_rewrite instanceof \WP_Rewrite && $wp_rewrite->using_permalinks() ) {
				return strtolower( $parts['host'] ) . $root . $wp_rewrite->pagination_base . '/';
			}

			return '';
		}

		return strtolower( $parts['host'] ) . $path;
	}

	/**
	 * Public taxonomies attached to a post type.
	 *
	 * @param string $post_type Post type.
	 * @return \WP_Taxonomy[]
	 */
	private function public_taxonomies( $post_type ) {
		$taxonomies = array();

		foreach ( get_object_taxonomies( $post_type, 'objects' ) as $taxonomy ) {
			if ( $taxonomy->public && $taxonomy->publicly_queryable ) {
				$taxonomies[] = $taxonomy;
			}
		}

		return $taxonomies;
	}

	/**
	 * Number of published items of a post type.
	 *
	 * @param string $post_type Post type.
	 * @return int
	 */
	private function published_count( $post_type ) {
		$counts = wp_count_posts( $post_type );

		return ( is_object( $counts ) && isset( $counts->publish ) ) ? (int) $counts->publish : 0;
	}

	/**
	 * File URLs for an attachment (original and all generated sizes).
	 *
	 * @param \WP_Post $attachment Attachment.
	 * @return string[]
	 */
	public function attachment_file_urls( \WP_Post $attachment ) {
		$urls = array();
		$url  = wp_get_attachment_url( $attachment->ID );

		if ( ! $url ) {
			return $urls;
		}

		$urls[] = $url;
		$meta   = wp_get_attachment_metadata( $attachment->ID );
		$base   = untrailingslashit( dirname( $url ) );

		if ( is_array( $meta ) ) {
			if ( ! empty( $meta['sizes'] ) && is_array( $meta['sizes'] ) ) {
				foreach ( $meta['sizes'] as $size ) {
					if ( ! empty( $size['file'] ) ) {
						$urls[] = $base . '/' . $size['file'];
					}
				}
			}

			if ( ! empty( $meta['original_image'] ) ) {
				$urls[] = $base . '/' . $meta['original_image'];
			}
		}

		return $urls;
	}

	/**
	 * Permalinks of the previous and next post.
	 *
	 * @param \WP_Post $post Post.
	 * @return string[]
	 */
	private function adjacent_urls( \WP_Post $post ) {
		$urls     = array();
		$previous = isset( $GLOBALS['post'] ) ? $GLOBALS['post'] : null;

		$GLOBALS['post'] = $post; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Temporarily set for get_adjacent_post(); restored below.

		foreach ( array( true, false ) as $direction ) {
			$adjacent = get_adjacent_post( false, '', $direction );

			if ( $adjacent instanceof \WP_Post ) {
				$urls[] = (string) get_permalink( $adjacent );
			}
		}

		$GLOBALS['post'] = $previous; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Restoring the previous value.

		return $urls;
	}

	/**
	 * Post types whose content is scanned for references.
	 *
	 * @return string[]
	 */
	public function reference_post_types() {
		$types = array();

		foreach ( get_post_types( array( 'public' => true ), 'objects' ) as $type ) {
			if ( 'attachment' !== $type->name && is_post_type_viewable( $type ) ) {
				$types[] = $type->name;
			}
		}

		/**
		 * Filter the post types scanned for references to changed content.
		 *
		 * @param string[] $types Post type names.
		 */
		return array_values( array_unique( (array) apply_filters( 'cpcf_reference_post_types', $types ) ) );
	}

	/**
	 * Find published posts whose content or custom fields reference the given post.
	 *
	 * Looks for block attributes ("id":123, "ref":123, "postId":123, "mediaId":123), image classes
	 * (wp-image-123), shortcode attributes (id="123") and links to the permalink in post_content, and for
	 * IDs stored in post meta (plain, serialized arrays, JSON) such as ACF relationship fields and
	 * featured images.
	 *
	 * @param \WP_Post $post   Post being purged.
	 * @param array    $old    Snapshot with the old permalink.
	 * @param string   $lookup all|content.
	 * @return array{ids:int[],truncated:bool}
	 */
	public function find_referencing_posts( \WP_Post $post, array $old, $lookup ) {
		global $wpdb;

		$id    = (int) $post->ID;
		$limit = max( 1, (int) $this->settings->get( 'reference_limit' ) );
		$types = $this->reference_post_types();
		$found = array();

		if ( empty( $types ) ) {
			return array(
				'ids'       => array(),
				'truncated' => false,
			);
		}

		$patterns = array(
			// Block attributes (compact JSON): images, files, media-text, synced patterns, navigation links.
			'"id":' . $id . ',',
			'"id":' . $id . '}',
			'"ref":' . $id . ',',
			'"ref":' . $id . '}',
			'"postId":' . $id . ',',
			'"postId":' . $id . '}',
			'"mediaId":' . $id . ',',
			'"mediaId":' . $id . '}',
			'"ids":[' . $id . ']',
			'"ids":[' . $id . ',',
			// String IDs inside block JSON (ACF blocks store field values as strings).
			':"' . $id . '"',
			'["' . $id . '"',
			',"' . $id . '"',
			// Rendered HTML of image and gallery blocks / classic editor.
			'wp-image-' . $id . ' ',
			'wp-image-' . $id . '"',
			'wp-image-' . $id . "'",
			'data-id="' . $id . '"',
			'attachment_' . $id . '"',
			// Shortcodes.
			'id="' . $id . '"',
			"id='" . $id . "'",
			'id=' . $id . ']',
			'id=' . $id . ' ',
			'ids="' . $id . '"',
			'ids="' . $id . ',',
			',' . $id . '"',
		);

		// Blocks that list content of this type (Query Loop, Latest Posts).
		if ( 'attachment' !== $post->post_type && 'wp_block' !== $post->post_type ) {
			$patterns[] = '"postType":"' . $post->post_type . '"';

			if ( 'post' === $post->post_type ) {
				$patterns[] = '<!-- wp:latest-posts';
				$patterns[] = '<!-- wp:query ';
				$patterns[] = '<!-- wp:query {';
			}
		}

		// Links to the post (any scheme, absolute or relative): match on the path.
		$permalinks = array();

		if ( $this->is_public( $post ) ) {
			$permalinks[] = (string) get_permalink( $post );
		}

		if ( ! empty( $old['permalink'] ) ) {
			$permalinks[] = (string) $old['permalink'];
		}

		foreach ( array_unique( array_filter( $permalinks ) ) as $permalink ) {
			$path = (string) wp_parse_url( $permalink, PHP_URL_PATH );

			if ( '' === $path || '/' === $path ) {
				$patterns[] = trailingslashit( $permalink );
				continue;
			}

			$path = untrailingslashit( $path );

			foreach ( array( '"', "'", '#', '?', '<', ' ' ) as $terminator ) {
				$patterns[] = $path . '/' . $terminator;
				$patterns[] = $path . $terminator;
			}
		}

		/**
		 * Filter the post_content patterns used to find content referencing a post.
		 *
		 * @param string[] $patterns Literal substrings (LIKE patterns are built from them).
		 * @param \WP_Post $post     Post being purged.
		 */
		$patterns = (array) apply_filters( 'cpcf_reference_content_patterns', $patterns, $post );

		$type_placeholders = implode( ',', array_fill( 0, count( $types ), '%s' ) );
		$where             = array();
		$params            = array_merge( array( $id ), $types );

		foreach ( $patterns as $pattern ) {
			$where[]  = 'post_content LIKE %s';
			$params[] = '%' . $wpdb->esc_like( $pattern ) . '%';
		}

		if ( ! empty( $where ) ) {
			$params[] = $limit;

			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Purpose-built lookup; the placeholder list is built dynamically and every value goes through $wpdb->prepare().
			$ids = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT ID FROM {$wpdb->posts} WHERE post_status = 'publish' AND ID <> %d AND post_type IN ({$type_placeholders}) AND (" . implode( ' OR ', $where ) . ') LIMIT %d',
					$params
				)
			);
			// phpcs:enable

			$found = array_map( 'intval', (array) $ids );
		}

		if ( 'all' === $lookup && count( $found ) < $limit ) {
			$remaining = $limit - count( $found );
			$params    = array_merge(
				array( $id ),
				$types,
				array(
					(string) $id,
					'%' . $wpdb->esc_like( 's:' . strlen( (string) $id ) . ':"' . $id . '";' ) . '%',
					'%' . $wpdb->esc_like( 'i:' . $id . ';' ) . '%',
					'%' . $wpdb->esc_like( '"id":' . $id . ',' ) . '%',
					'%' . $wpdb->esc_like( '"id":' . $id . '}' ) . '%',
					$remaining,
				)
			);

			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Purpose-built lookup; the placeholder list is built dynamically and every value goes through $wpdb->prepare().
			$meta_ids = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT DISTINCT pm.post_id FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE p.post_status = 'publish' AND p.ID <> %d AND p.post_type IN ({$type_placeholders}) AND pm.meta_key NOT IN ('_edit_lock','_edit_last','_wp_old_slug','_wp_page_template','_menu_item_object_id') AND (pm.meta_value = %s OR pm.meta_value LIKE %s OR pm.meta_value LIKE %s OR pm.meta_value LIKE %s OR pm.meta_value LIKE %s) LIMIT %d",
					$params
				)
			);
			// phpcs:enable

			$found = array_merge( $found, array_map( 'intval', (array) $meta_ids ) );
		}

		$found = array_values( array_unique( array_filter( $found ) ) );

		return array(
			'ids'          => $found,
			'truncated'    => count( $found ) >= $limit,
			'in_templates' => $this->referenced_in_templates( $patterns, $id ),
		);
	}

	/**
	 * Whether a block template, template part or navigation (stored in the database) matches any pattern.
	 *
	 * @param string[] $patterns Literal substrings.
	 * @param int      $exclude  Post ID to exclude.
	 * @return bool
	 */
	private function referenced_in_templates( array $patterns, $exclude ) {
		global $wpdb;

		if ( empty( $patterns ) ) {
			return false;
		}

		$where  = array();
		$params = array( (int) $exclude );

		foreach ( $patterns as $pattern ) {
			$where[]  = 'post_content LIKE %s';
			$params[] = '%' . $wpdb->esc_like( $pattern ) . '%';
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Purpose-built lookup; the placeholder list is built dynamically and every value goes through $wpdb->prepare().
		$count = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status = 'publish' AND ID <> %d AND post_type IN ('wp_template','wp_template_part','wp_navigation') AND (" . implode( ' OR ', $where ) . ')',
				$params
			)
		);
		// phpcs:enable

		return (int) $count > 0;
	}

	/**
	 * Comments feed URL for a permalink (used for the previous URL of a post).
	 *
	 * @param string $permalink Permalink.
	 * @return string
	 */
	private function feed_url_for( $permalink ) {
		global $wp_rewrite;

		if ( $wp_rewrite instanceof \WP_Rewrite && $wp_rewrite->using_permalinks() && false === strpos( $permalink, '?' ) ) {
			return user_trailingslashit( trailingslashit( $permalink ) . 'feed', 'single_feed' );
		}

		return add_query_arg( 'feed', 'rss2', $permalink );
	}

	/**
	 * IDs of published posts that declared (via the meta box) that they depend on a post type.
	 *
	 * @param string $post_type Post type that changed.
	 * @return int[]
	 */
	public function dependent_post_ids( $post_type ) {
		$types = $this->reference_post_types();

		if ( empty( $types ) ) {
			return array();
		}

		$query = new \WP_Query(
			array(
				'post_type'              => $types,
				'post_status'            => 'publish',
				'posts_per_page'         => 100,
				'fields'                 => 'ids',
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
				'meta_query'             => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Bounded lookup on a plugin owned meta key.
					array(
						'key'   => Meta_Box::META_DEPENDS,
						'value' => $post_type,
					),
				),
			)
		);

		return array_map( 'intval', (array) $query->posts );
	}

	/**
	 * Whether a post is the target of a navigation menu item.
	 *
	 * @param int $post_id Post ID.
	 * @return bool
	 */
	public function in_nav_menu( $post_id ) {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Small lookup on menu item meta.
		$count = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->postmeta} obj INNER JOIN {$wpdb->postmeta} type ON type.post_id = obj.post_id AND type.meta_key = '_menu_item_type' AND type.meta_value = 'post_type' WHERE obj.meta_key = '_menu_item_object_id' AND obj.meta_value = %s",
				(string) (int) $post_id
			)
		);
		// phpcs:enable

		return (int) $count > 0;
	}

	/**
	 * Resolve a user supplied URL or path to an absolute URL.
	 *
	 * @param string $input URL or path.
	 * @return string Absolute URL or empty string.
	 */
	public function resolve_url( $input ) {
		$input = trim( (string) $input );

		if ( '' === $input ) {
			return '';
		}

		if ( preg_match( '#^https?://#i', $input ) ) {
			return esc_url_raw( $input );
		}

		if ( 0 === strpos( $input, '//' ) ) {
			return esc_url_raw( set_url_scheme( 'https:' . $input ) );
		}

		return esc_url_raw( home_url( '/' . ltrim( $input, '/' ) ) );
	}

	/**
	 * Clean a list of URLs: absolute http(s) only, unique.
	 *
	 * @param array $urls URLs.
	 * @return string[]
	 */
	public function normalize_urls( array $urls ) {
		$clean = array();

		foreach ( $urls as $url ) {
			if ( ! is_string( $url ) ) {
				continue;
			}

			$url = esc_url_raw( trim( $url ) );

			if ( '' === $url || ! preg_match( '#^https?://#i', $url ) ) {
				continue;
			}

			$clean[ $url ] = true;
		}

		return array_keys( $clean );
	}

	/**
	 * Clean a plan.
	 *
	 * @param array $plan Plan.
	 * @return array
	 */
	public function normalize_plan( array $plan ) {
		$plan = wp_parse_args( $plan, $this->empty_plan() );

		$plan['urls']          = $this->normalize_urls( (array) $plan['urls'] );
		$plan['fallback_urls'] = array_values( array_diff( $this->normalize_urls( (array) $plan['fallback_urls'] ), $plan['urls'] ) );
		$plan['prefixes']      = array_values( array_unique( array_filter( array_map( 'strval', (array) $plan['prefixes'] ) ) ) );
		$plan['reasons']       = array_values( array_unique( array_filter( array_map( 'strval', (array) $plan['reasons'] ) ) ) );
		$plan['everything']    = ! empty( $plan['everything'] );

		return $plan;
	}

	/**
	 * Add http:// and https:// variants of each URL.
	 *
	 * @param string[] $urls URLs.
	 * @return string[]
	 */
	public function scheme_variants( array $urls ) {
		$out = array();

		foreach ( $urls as $url ) {
			$out[] = set_url_scheme( $url, 'https' );
			$out[] = set_url_scheme( $url, 'http' );
		}

		return array_values( array_unique( $out ) );
	}

	/**
	 * Convert URLs on this site to paths for Pantheon's edge purge.
	 *
	 * @param string[] $urls URLs.
	 * @return string[]
	 */
	public function urls_to_paths( array $urls ) {
		$home_host = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
		$paths     = array();

		foreach ( $urls as $url ) {
			$parts = wp_parse_url( $url );

			if ( empty( $parts['host'] ) || strtolower( $parts['host'] ) !== $home_host ) {
				continue;
			}

			$path = isset( $parts['path'] ) && '' !== $parts['path'] ? $parts['path'] : '/';

			if ( ! empty( $parts['query'] ) ) {
				$path .= '?' . $parts['query'];
			}

			$paths[] = $path;
		}

		return array_values( array_unique( $paths ) );
	}
}
