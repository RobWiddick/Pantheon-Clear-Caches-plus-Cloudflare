<?php
/**
 * WordPress event hooks that trigger purges.
 *
 * @package CPCF
 */

namespace CPCF;

defined( 'ABSPATH' ) || exit;

/**
 * Listens to content changes and feeds them to the purger.
 */
class Hooks {

	/**
	 * Post types whose changes affect every page of the site.
	 *
	 * @var string[]
	 */
	const SITEWIDE_POST_TYPES = array( 'wp_navigation', 'wp_template', 'wp_template_part', 'wp_global_styles', 'custom_css' );

	/**
	 * Settings.
	 *
	 * @var Settings
	 */
	private $settings;

	/**
	 * Purger.
	 *
	 * @var Purger
	 */
	private $purger;

	/**
	 * Term snapshots captured before an edit/delete: "taxonomy:term_id" => data.
	 *
	 * @var array
	 */
	private $term_snapshots = array();

	/**
	 * Whether the user profile form was submitted in this request.
	 *
	 * @var bool
	 */
	private $profile_form_submitted = false;

	/**
	 * Constructor.
	 *
	 * @param Settings $settings Settings.
	 * @param Purger   $purger   Purger.
	 */
	public function __construct( Settings $settings, Purger $purger ) {
		$this->settings = $settings;
		$this->purger   = $purger;
	}

	/**
	 * Register hooks.
	 */
	public function register() {
		// Posts (fires after terms and meta are saved, in both the classic and the REST/Gutenberg path).
		add_action( 'wp_after_insert_post', array( $this, 'on_after_insert_post' ), 20, 4 );
		add_action( 'set_object_terms', array( $this, 'on_set_object_terms' ), 10, 6 );
		add_action( 'before_delete_post', array( $this, 'on_before_delete_post' ), 10, 2 );

		// Attachments.
		add_action( 'attachment_updated', array( $this, 'on_attachment_updated' ), 10, 3 );
		add_filter( 'wp_update_attachment_metadata', array( $this, 'on_attachment_metadata' ), 10, 2 );
		add_action( 'delete_attachment', array( $this, 'on_delete_attachment' ), 10, 2 );

		// Comments.
		add_action( 'wp_update_comment_count', array( $this, 'on_comment_count' ) );
		add_action( 'edit_comment', array( $this, 'on_edit_comment' ) );

		// Terms.
		add_action( 'edit_terms', array( $this, 'capture_term' ), 10, 2 );
		add_action( 'edited_term', array( $this, 'on_edited_term' ), 10, 3 );
		add_action( 'pre_delete_term', array( $this, 'capture_term' ), 10, 2 );
		add_action( 'delete_term', array( $this, 'on_delete_term' ), 10, 4 );

		// Users.
		add_action( 'personal_options_update', array( $this, 'flag_profile_form' ) );
		add_action( 'edit_user_profile_update', array( $this, 'flag_profile_form' ) );
		add_action( 'profile_update', array( $this, 'on_profile_update' ), 10, 2 );
		add_action( 'deleted_user', array( $this, 'on_deleted_user' ) );

		// Site-wide changes.
		add_action( 'wp_update_nav_menu', array( $this, 'on_nav_menu' ) );
		add_action( 'customize_save_after', array( $this, 'on_customizer' ) );
		add_action( 'switch_theme', array( $this, 'on_switch_theme' ) );
		add_action( 'permalink_structure_changed', array( $this, 'on_permalinks' ) );
		add_action( 'updated_option', array( $this, 'on_updated_option' ) );
		add_filter( 'widget_update_callback', array( $this, 'on_widget_update' ) );
		add_action( 'upgrader_process_complete', array( $this, 'on_upgrade' ), 10, 2 );
		add_action( 'activated_plugin', array( $this, 'on_plugin_toggle' ) );
		add_action( 'deactivated_plugin', array( $this, 'on_plugin_toggle' ) );
		add_action( 'acf/save_post', array( $this, 'on_acf_save' ), 20 );
	}

	/**
	 * Post saved (classic editor, block editor, REST, WP-CLI, cron publishing).
	 *
	 * @param int           $post_id     Post ID.
	 * @param \WP_Post      $post        Post after the change.
	 * @param bool          $update      Whether this is an update.
	 * @param \WP_Post|null $post_before Post before the change (null for new posts).
	 */
	public function on_after_insert_post( $post_id, $post, $update, $post_before ) {
		if ( ! $post instanceof \WP_Post || wp_is_post_revision( $post ) || wp_is_post_autosave( $post ) ) {
			return;
		}

		if ( 'auto-draft' === $post->post_status || 'attachment' === $post->post_type ) {
			return;
		}

		if ( in_array( $post->post_type, $this->sitewide_post_types(), true ) ) {
			if ( $this->sitewide_post_changed( $post, $post_before ) ) {
				$this->sitewide( $this->post_reason( $post, $post_before ) );
			}

			return;
		}

		$collector = $this->purger->collector();

		if ( 'wp_block' === $post->post_type ) {
			// Synced patterns are not viewable themselves; every post that embeds one is affected.
			$is_public  = ( 'publish' === $post->post_status );
			$was_public = ( $post_before instanceof \WP_Post && 'publish' === $post_before->post_status );
		} else {
			$is_public  = $collector->is_public( $post );
			$was_public = ( $post_before instanceof \WP_Post && $collector->is_public( $post_before ) );
		}

		if ( ! $is_public && ! $was_public ) {
			return;
		}

		if ( ! $this->purger->automatic_purges_allowed( 'post' ) ) {
			return;
		}

		$context = array(
			'old'        => $was_public ? $collector->snapshot( $post_before ) : null,
			'trigger'    => $this->post_reason( $post, $post_before ),
			'old_status' => $post_before instanceof \WP_Post ? $post_before->post_status : '',
			'new_status' => $post->post_status,
		);

		$this->purger->queue_post( $post->ID, $context );
	}

	/**
	 * Whether a save of a site-wide post type (templates, navigation, global styles) changed anything visible.
	 *
	 * WordPress creates the "Global Styles" post lazily the first time the editor opens, and the block
	 * editor re-saves templates without changes; neither should purge the whole site.
	 *
	 * @param \WP_Post      $post        Post after the save.
	 * @param \WP_Post|null $post_before Post before the save.
	 * @return bool
	 */
	private function sitewide_post_changed( \WP_Post $post, $post_before ) {
		if ( ! $post_before instanceof \WP_Post ) {
			// New template parts and navigations only matter once something references them; new global styles are empty.
			return 'publish' === $post->post_status && ! in_array( $post->post_type, array( 'wp_global_styles', 'wp_navigation', 'wp_template_part' ), true );
		}

		if ( 'publish' !== $post->post_status && 'publish' !== $post_before->post_status ) {
			return false;
		}

		return $post->post_content !== $post_before->post_content
			|| $post->post_status !== $post_before->post_status
			|| $post->post_title !== $post_before->post_title
			|| $post->post_name !== $post_before->post_name;
	}

	/**
	 * Terms assigned to an object; remember archives of terms that were removed.
	 *
	 * @param int    $object_id  Object ID.
	 * @param array  $terms      Terms.
	 * @param array  $tt_ids     New term taxonomy IDs.
	 * @param string $taxonomy   Taxonomy.
	 * @param bool   $append     Whether terms were appended.
	 * @param array  $old_tt_ids Previous term taxonomy IDs.
	 */
	public function on_set_object_terms( $object_id, $terms, $tt_ids, $taxonomy, $append, $old_tt_ids ) {
		if ( $append ) {
			return;
		}

		$removed = array_diff( array_map( 'intval', (array) $old_tt_ids ), array_map( 'intval', (array) $tt_ids ) );

		if ( empty( $removed ) ) {
			return;
		}

		$tax = get_taxonomy( $taxonomy );

		if ( ! $tax || ! $tax->public || ! $tax->publicly_queryable ) {
			return;
		}

		$post = get_post( $object_id );

		if ( ! $post instanceof \WP_Post || ! is_post_type_viewable( $post->post_type ) ) {
			return;
		}

		$urls = array();

		foreach ( $removed as $tt_id ) {
			$term = get_term_by( 'term_taxonomy_id', $tt_id, $taxonomy );

			if ( $term instanceof \WP_Term ) {
				$link = get_term_link( $term, $taxonomy );

				if ( is_string( $link ) ) {
					$urls[] = $link;
				}
			}
		}

		if ( ! empty( $urls ) && $this->purger->automatic_purges_allowed( 'post' ) ) {
			$this->purger->add_old_term_urls( (int) $object_id, $urls );
		}
	}

	/**
	 * Post about to be permanently deleted (terms are still attached at this point).
	 *
	 * @param int      $post_id Post ID.
	 * @param \WP_Post $post    Post.
	 */
	public function on_before_delete_post( $post_id, $post ) {
		if ( ! $post instanceof \WP_Post || 'attachment' === $post->post_type ) {
			return;
		}

		if ( in_array( $post->post_type, $this->sitewide_post_types(), true ) ) {
			if ( 'publish' === $post->post_status ) {
				$this->sitewide( $this->post_reason( $post, null, 'deleted' ) );
			}

			return;
		}

		$collector = $this->purger->collector();
		$is_public = ( 'wp_block' === $post->post_type ) ? ( 'publish' === $post->post_status ) : $collector->is_public( $post );

		if ( ! $is_public || ! $this->purger->automatic_purges_allowed( 'post' ) ) {
			return;
		}

		$context = array(
			'old'     => $collector->snapshot( $post ),
			'removed' => true,
			'trigger' => $this->post_reason( $post, null, 'deleted' ),
		);

		// Compute now: the post (and its term relationships) will be gone by shutdown.
		$mode = $this->settings->mode_for_post( $post );

		if ( 'none' === $mode ) {
			return;
		}

		if ( 'everything' === $mode ) {
			$this->purger->request_everything( $context['trigger'] );

			return;
		}

		$this->purger->request_plan( $collector->plan_for_post( $post, $context ), $context['trigger'] );
	}

	/**
	 * Attachment details updated (title, alt text, caption...).
	 *
	 * @param int      $post_id     Attachment ID.
	 * @param \WP_Post $post_after  Attachment after.
	 * @param \WP_Post $post_before Attachment before.
	 */
	public function on_attachment_updated( $post_id, $post_after, $post_before ) {
		if ( ! $this->purger->automatic_purges_allowed( 'attachment' ) ) {
			return;
		}

		$this->purger->queue_post(
			(int) $post_id,
			array(
				'old'     => $post_before instanceof \WP_Post ? $this->purger->collector()->snapshot( $post_before ) : null,
				'trigger' => $this->post_reason( $post_after instanceof \WP_Post ? $post_after : get_post( $post_id ), $post_before instanceof \WP_Post ? $post_before : null ),
			)
		);
	}

	/**
	 * Attachment files regenerated or edited (image editor, regenerate thumbnails).
	 *
	 * @param array $data    Attachment metadata.
	 * @param int   $post_id Attachment ID.
	 * @return array Unchanged metadata.
	 */
	public function on_attachment_metadata( $data, $post_id ) {
		$post = get_post( $post_id );

		if ( $post instanceof \WP_Post && $this->purger->automatic_purges_allowed( 'attachment' ) ) {
			$this->purger->queue_post( (int) $post_id, array( 'trigger' => $this->post_reason( $post ) ) );
		}

		return $data;
	}

	/**
	 * Attachment about to be deleted.
	 *
	 * @param int      $post_id Attachment ID.
	 * @param \WP_Post $post    Attachment.
	 */
	public function on_delete_attachment( $post_id, $post ) {
		if ( ! $post instanceof \WP_Post || ! $this->purger->automatic_purges_allowed( 'attachment' ) ) {
			return;
		}

		$mode = $this->settings->mode_for_post( $post );

		if ( 'none' === $mode ) {
			return;
		}

		$reason = $this->post_reason( $post, null, 'deleted' );

		if ( 'everything' === $mode ) {
			$this->purger->request_everything( $reason );

			return;
		}

		$collector = $this->purger->collector();

		$this->purger->request_plan(
			$collector->plan_for_post(
				$post,
				array(
					'old'     => $collector->snapshot( $post ),
					'removed' => true,
					'trigger' => $reason,
				)
			),
			$reason
		);
	}

	/**
	 * Approved comment count changed (new, approved, unapproved, trashed, deleted comments).
	 *
	 * @param int $post_id Post ID.
	 */
	public function on_comment_count( $post_id ) {
		$this->comment_changed( (int) $post_id );
	}

	/**
	 * Comment edited.
	 *
	 * @param int $comment_id Comment ID.
	 */
	public function on_edit_comment( $comment_id ) {
		$comment = get_comment( $comment_id );

		if ( $comment && in_array( (string) $comment->comment_approved, array( '1', 'approve', 'approved' ), true ) ) {
			$this->comment_changed( (int) $comment->comment_post_ID );
		}
	}

	/**
	 * Handle a comment change on a post.
	 *
	 * @param int $post_id Post ID.
	 */
	private function comment_changed( $post_id ) {
		$setting = (string) $this->settings->get( 'comment_purge' );

		if ( 'none' === $setting || ! $this->purger->automatic_purges_allowed( 'comment' ) ) {
			return;
		}

		$post = get_post( $post_id );

		if ( ! $post instanceof \WP_Post || ! $this->purger->collector()->is_public( $post ) ) {
			return;
		}

		/* translators: %s: post title */
		$reason = sprintf( __( 'Comments changed on "%s"', 'cache-purge-control-for-cloudflare' ), $post->post_title );

		if ( 'follow' === $setting ) {
			$this->purger->queue_post( $post->ID, array( 'trigger' => $reason ) );

			return;
		}

		$this->purger->request_plan( $this->purger->collector()->plan_for_comment( $post ), $reason );
	}

	/**
	 * Capture a term before it is edited or deleted.
	 *
	 * @param int    $term_id  Term ID.
	 * @param string $taxonomy Taxonomy.
	 */
	public function capture_term( $term_id, $taxonomy ) {
		$key = $taxonomy . ':' . (int) $term_id;

		if ( isset( $this->term_snapshots[ $key ] ) ) {
			return;
		}

		$term = get_term( $term_id, $taxonomy );

		if ( ! $term instanceof \WP_Term ) {
			return;
		}

		$link = get_term_link( $term, $taxonomy );

		$this->term_snapshots[ $key ] = array(
			'name'     => $term->name,
			'slug'     => $term->slug,
			'count'    => (int) $term->count,
			'link'     => is_string( $link ) ? $link : '',
			'feed'     => get_term_feed_link( $term, $taxonomy ),
			'taxonomy' => $taxonomy,
		);
	}

	/**
	 * Term edited.
	 *
	 * @param int    $term_id  Term ID.
	 * @param int    $tt_id    Term taxonomy ID.
	 * @param string $taxonomy Taxonomy.
	 */
	public function on_edited_term( $term_id, $tt_id, $taxonomy ) {
		$term = get_term( $term_id, $taxonomy );

		if ( ! $term instanceof \WP_Term ) {
			return;
		}

		$link = get_term_link( $term, $taxonomy );

		$this->term_changed(
			$taxonomy,
			array(
				'name'     => $term->name,
				'slug'     => $term->slug,
				'count'    => (int) $term->count,
				'link'     => is_string( $link ) ? $link : '',
				'feed'     => get_term_feed_link( $term, $taxonomy ),
				'taxonomy' => $taxonomy,
			),
			isset( $this->term_snapshots[ $taxonomy . ':' . (int) $term_id ] ) ? $this->term_snapshots[ $taxonomy . ':' . (int) $term_id ] : null,
			'edited'
		);
	}

	/**
	 * Term deleted.
	 *
	 * @param int      $term         Term ID.
	 * @param int      $tt_id        Term taxonomy ID.
	 * @param string   $taxonomy     Taxonomy.
	 * @param \WP_Term $deleted_term Deleted term object.
	 */
	public function on_delete_term( $term, $tt_id, $taxonomy, $deleted_term ) {
		$key      = $taxonomy . ':' . (int) $term;
		$snapshot = isset( $this->term_snapshots[ $key ] ) ? $this->term_snapshots[ $key ] : null;

		if ( null === $snapshot && $deleted_term instanceof \WP_Term ) {
			$snapshot = array(
				'name'     => $deleted_term->name,
				'slug'     => $deleted_term->slug,
				'count'    => (int) $deleted_term->count,
				'link'     => '',
				'feed'     => '',
				'taxonomy' => $taxonomy,
			);
		}

		if ( null === $snapshot ) {
			return;
		}

		$this->term_changed( $taxonomy, null, $snapshot, 'deleted' );
	}

	/**
	 * Handle a term edit or deletion according to the term purge setting.
	 *
	 * @param string     $taxonomy Taxonomy.
	 * @param array|null $current  Current term data (null when deleted).
	 * @param array|null $old      Term data before the change.
	 * @param string     $action   edited|deleted.
	 */
	private function term_changed( $taxonomy, $current, $old, $action ) {
		$tax = get_taxonomy( $taxonomy );

		if ( ! $tax || ! $tax->public || ! $tax->publicly_queryable ) {
			return;
		}

		if ( ! $this->purger->automatic_purges_allowed( 'term' ) ) {
			return;
		}

		$setting = (string) $this->settings->get( 'term_purge' );

		if ( 'follow' === $setting ) {
			$setting = (string) $this->settings->get( 'purge_mode' );
		}

		if ( 'none' === $setting ) {
			return;
		}

		$name = ! empty( $current['name'] ) ? $current['name'] : ( ! empty( $old['name'] ) ? $old['name'] : '' );

		if ( 'deleted' === $action ) {
			/* translators: 1: taxonomy label, 2: term name */
			$reason = sprintf( __( '%1$s "%2$s" was deleted', 'cache-purge-control-for-cloudflare' ), $tax->labels->singular_name, $name );
		} else {
			/* translators: 1: taxonomy label, 2: term name */
			$reason = sprintf( __( '%1$s "%2$s" was edited', 'cache-purge-control-for-cloudflare' ), $tax->labels->singular_name, $name );
		}

		$slug_changed = ( is_array( $current ) && is_array( $old ) && isset( $current['slug'], $old['slug'] ) && $current['slug'] !== $old['slug'] );

		if ( 'everything' === $setting || ( $slug_changed && $this->settings->get( 'slug_change_everything' ) ) ) {
			$this->purger->request_everything( $reason );

			return;
		}

		$collector = $this->purger->collector();
		$plan      = $collector->empty_plan();

		if ( is_array( $old ) ) {
			$plan = $collector->merge_plans( $plan, $collector->plan_for_term( $old ) );
		}

		if ( is_array( $current ) ) {
			$plan = $collector->merge_plans( $plan, $collector->plan_for_term( $current ) );
		}

		$this->purger->request_plan( $plan, $reason );
	}

	/**
	 * The profile form (profile.php / user-edit.php) is being saved.
	 */
	public function flag_profile_form() {
		$this->profile_form_submitted = true;
	}

	/**
	 * User profile updated: author archives and by-lines change.
	 *
	 * The block editor saves user preferences through the same code path, so only react when fields that
	 * appear on the site changed, or when the profile form itself was submitted (bio, name fields).
	 *
	 * @param int           $user_id       User ID.
	 * @param \WP_User|null $old_user_data User before the update.
	 */
	public function on_profile_update( $user_id, $old_user_data = null ) {
		$changed = $this->profile_form_submitted;

		if ( ! $changed && $old_user_data instanceof \WP_User ) {
			$user = get_userdata( (int) $user_id );

			if ( $user instanceof \WP_User ) {
				foreach ( array( 'display_name', 'user_nicename', 'user_url', 'user_email' ) as $field ) {
					if ( (string) $user->$field !== (string) $old_user_data->$field ) {
						$changed = true;
						break;
					}
				}
			}
		} elseif ( ! $changed ) {
			$changed = true;
		}

		if ( $changed ) {
			$this->author_changed( (int) $user_id );
		}
	}

	/**
	 * User deleted: their archive disappears and their posts are reassigned.
	 *
	 * @param int $user_id User ID.
	 */
	public function on_deleted_user( $user_id ) {
		$this->author_changed( (int) $user_id );
	}

	/**
	 * Purge for an author whose public details changed.
	 *
	 * @param int $user_id User ID.
	 */
	private function author_changed( $user_id ) {
		if ( ! $this->purger->automatic_purges_allowed( 'user' ) ) {
			return;
		}

		if ( (int) count_user_posts( $user_id, array_values( get_post_types( array( 'public' => true ) ) ), true ) < 1 ) {
			return;
		}

		$user = get_userdata( $user_id );
		/* translators: %s: user display name */
		$reason = sprintf( __( 'Author "%s" updated', 'cache-purge-control-for-cloudflare' ), $user ? $user->display_name : '#' . $user_id );

		if ( 'everything' === $this->settings->get( 'purge_mode' ) ) {
			$this->purger->request_everything( $reason );

			return;
		}

		$this->purger->request_plan( $this->purger->collector()->plan_for_author( $user_id ), $reason );
	}

	/**
	 * Navigation menu saved.
	 */
	public function on_nav_menu() {
		$this->sitewide( __( 'Navigation menu updated', 'cache-purge-control-for-cloudflare' ) );
	}

	/**
	 * Customizer settings published.
	 */
	public function on_customizer() {
		$this->sitewide( __( 'Customizer settings published', 'cache-purge-control-for-cloudflare' ) );
	}

	/**
	 * Theme switched.
	 */
	public function on_switch_theme() {
		$this->sitewide( __( 'Theme switched', 'cache-purge-control-for-cloudflare' ) );
	}

	/**
	 * Permalink structure changed.
	 */
	public function on_permalinks() {
		$this->sitewide( __( 'Permalink structure changed', 'cache-purge-control-for-cloudflare' ) );
	}

	/**
	 * Widget saved.
	 *
	 * @param array $instance New instance.
	 * @return array Unchanged instance.
	 */
	public function on_widget_update( $instance ) {
		$this->sitewide( __( 'Widget updated', 'cache-purge-control-for-cloudflare' ) );

		return $instance;
	}

	/**
	 * Core, plugin or theme updated.
	 *
	 * @param \WP_Upgrader $upgrader Upgrader.
	 * @param array        $options  Options (type, action).
	 */
	public function on_upgrade( $upgrader, $options ) {
		$type = isset( $options['type'] ) ? (string) $options['type'] : '';

		if ( in_array( $type, array( 'core', 'plugin', 'theme' ), true ) ) {
			/* translators: %s: update type (core, plugin, theme) */
			$this->sitewide( sprintf( __( 'Update installed (%s)', 'cache-purge-control-for-cloudflare' ), $type ) );
		}
	}

	/**
	 * Plugin activated or deactivated.
	 *
	 * @param string $plugin Plugin basename.
	 */
	public function on_plugin_toggle( $plugin ) {
		if ( CPCF_PLUGIN_BASENAME === $plugin ) {
			return;
		}

		/* translators: %s: plugin file name */
		$this->sitewide( sprintf( __( 'Plugin activated or deactivated (%s)', 'cache-purge-control-for-cloudflare' ), $plugin ) );
	}

	/**
	 * Option updated: purge everything for settings that affect every page.
	 *
	 * @param string $option Option name.
	 */
	public function on_updated_option( $option ) {
		$watched = array(
			'blogname',
			'blogdescription',
			'show_on_front',
			'page_on_front',
			'page_for_posts',
			'posts_per_page',
			'posts_per_rss',
			'rss_use_excerpt',
			'permalink_structure',
			'category_base',
			'tag_base',
			'sidebars_widgets',
			'site_icon',
			'site_logo',
			'stylesheet',
			'template',
			'timezone_string',
			'gmt_offset',
			'date_format',
			'time_format',
			'start_of_week',
			'WPLANG',
			'sticky_posts',
			'blog_public',
			'default_comment_status',
			'thread_comments',
			'comments_per_page',
			'page_comments',
			'comment_order',
		);

		/**
		 * Filter the options that trigger a full purge when they change.
		 *
		 * @param string[] $watched Option names.
		 */
		$watched = (array) apply_filters( 'cpcf_sitewide_options', $watched );

		$prefixes = array( 'widget_', 'theme_mods_', 'options_' );

		$matches = in_array( $option, $watched, true );

		if ( ! $matches ) {
			foreach ( $prefixes as $prefix ) {
				if ( 0 === strpos( $option, $prefix ) ) {
					$matches = true;
					break;
				}
			}
		}

		if ( $matches ) {
			/* translators: %s: option name */
			$this->sitewide( sprintf( __( 'Setting "%s" changed', 'cache-purge-control-for-cloudflare' ), $option ) );
		}
	}

	/**
	 * ACF saved fields for something that is not a post (options pages).
	 *
	 * @param int|string $post_id ACF post ID (numeric for posts, "options" or "option" for options pages, etc.).
	 */
	public function on_acf_save( $post_id ) {
		if ( is_numeric( $post_id ) ) {
			return;
		}

		$post_id = (string) $post_id;

		if ( 0 === strpos( $post_id, 'option' ) ) {
			$this->sitewide( __( 'ACF options page saved', 'cache-purge-control-for-cloudflare' ) );
		}
	}

	/**
	 * Request a full purge for a site-wide change (when enabled).
	 *
	 * @param string $reason Reason.
	 */
	private function sitewide( $reason ) {
		if ( ! $this->settings->get( 'sitewide_purge' ) || ! $this->purger->automatic_purges_allowed( 'sitewide' ) ) {
			return;
		}

		$this->purger->request_everything( $reason );
	}

	/**
	 * Post types whose changes are site-wide.
	 *
	 * @return string[]
	 */
	private function sitewide_post_types() {
		/**
		 * Filter the post types whose changes trigger a full purge.
		 *
		 * @param string[] $post_types Post type names.
		 */
		return (array) apply_filters( 'cpcf_sitewide_post_types', self::SITEWIDE_POST_TYPES );
	}

	/**
	 * Build a human readable reason for a post change.
	 *
	 * @param \WP_Post|null $post        Post.
	 * @param \WP_Post|null $post_before Post before (optional).
	 * @param string        $action      Optional action override (e.g. "deleted").
	 * @return string
	 */
	private function post_reason( $post, $post_before = null, $action = '' ) {
		if ( ! $post instanceof \WP_Post ) {
			return __( 'Content changed', 'cache-purge-control-for-cloudflare' );
		}

		$type  = get_post_type_object( $post->post_type );
		$label = ( $type && ! empty( $type->labels->singular_name ) ) ? $type->labels->singular_name : $post->post_type;
		$title = '' !== $post->post_title ? $post->post_title : '#' . $post->ID;

		if ( '' === $action ) {
			if ( ! $post_before instanceof \WP_Post ) {
				$action = 'published';
			} elseif ( $post_before->post_status !== $post->post_status ) {
				$action = ( 'publish' === $post->post_status ) ? 'published' : ( 'trash' === $post->post_status ? 'trashed' : 'unpublished' );
			} else {
				$action = 'updated';
			}
		}

		switch ( $action ) {
			case 'published':
				/* translators: 1: post type label, 2: post title */
				return sprintf( __( '%1$s "%2$s" published', 'cache-purge-control-for-cloudflare' ), $label, $title );
			case 'trashed':
				/* translators: 1: post type label, 2: post title */
				return sprintf( __( '%1$s "%2$s" trashed', 'cache-purge-control-for-cloudflare' ), $label, $title );
			case 'unpublished':
				/* translators: 1: post type label, 2: post title */
				return sprintf( __( '%1$s "%2$s" unpublished', 'cache-purge-control-for-cloudflare' ), $label, $title );
			case 'deleted':
				/* translators: 1: post type label, 2: post title */
				return sprintf( __( '%1$s "%2$s" deleted', 'cache-purge-control-for-cloudflare' ), $label, $title );
			default:
				/* translators: 1: post type label, 2: post title */
				return sprintf( __( '%1$s "%2$s" updated', 'cache-purge-control-for-cloudflare' ), $label, $title );
		}
	}
}
