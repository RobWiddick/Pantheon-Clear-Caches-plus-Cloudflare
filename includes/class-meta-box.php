<?php
/**
 * Per-post purge settings meta box.
 *
 * @package CPCF
 */

namespace CPCF;

defined( 'ABSPATH' ) || exit;

/**
 * Lets editors control purging for an individual post or page.
 */
class Meta_Box {

	/**
	 * Meta key: per-post purge mode override.
	 */
	const META_MODE = '_cpcf_purge_mode';

	/**
	 * Meta key: extra URLs to purge when the post changes (newline separated).
	 */
	const META_EXTRA_URLS = '_cpcf_extra_urls';

	/**
	 * Meta key: post types this post depends on (one meta row per post type).
	 */
	const META_DEPENDS = '_cpcf_depends_on';

	/**
	 * Nonce action.
	 */
	const NONCE = 'cpcf_meta_box';

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
	 * Register hooks.
	 */
	public function register() {
		add_action( 'add_meta_boxes', array( $this, 'add_meta_box' ) );
		add_action( 'save_post', array( $this, 'save' ) );
	}

	/**
	 * Post types that get the meta box.
	 *
	 * @return string[]
	 */
	public static function post_types() {
		$types = array();

		foreach ( get_post_types( array( 'public' => true ), 'objects' ) as $type ) {
			if ( 'attachment' !== $type->name && is_post_type_viewable( $type ) ) {
				$types[] = $type->name;
			}
		}

		/**
		 * Filter the post types that show the per-post purge meta box.
		 *
		 * @param string[] $types Post type names.
		 */
		return (array) apply_filters( 'cpcf_meta_box_post_types', $types );
	}

	/**
	 * Extra URLs declared on a post.
	 *
	 * @param int $post_id Post ID.
	 * @return string[]
	 */
	public static function extra_urls( $post_id ) {
		return Settings::parse_url_list( (string) get_post_meta( $post_id, self::META_EXTRA_URLS, true ) );
	}

	/**
	 * Register the meta box.
	 *
	 * @param string $post_type Post type.
	 */
	public function add_meta_box( $post_type ) {
		if ( ! in_array( $post_type, self::post_types(), true ) || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		add_meta_box(
			'cpcf-purge-control',
			__( 'Cache Purge Control', 'pantheon-clear-caches-plus-cloudflare' ),
			array( $this, 'render' ),
			$post_type,
			'side',
			'low'
		);
	}

	/**
	 * Render the meta box.
	 *
	 * @param \WP_Post $post Post.
	 */
	public function render( $post ) {
		$mode       = (string) get_post_meta( $post->ID, self::META_MODE, true );
		$extra_urls = (string) get_post_meta( $post->ID, self::META_EXTRA_URLS, true );
		$depends    = array_map( 'strval', (array) get_post_meta( $post->ID, self::META_DEPENDS, false ) );
		$type_mode  = $this->settings->mode_for_post_type( $post->post_type );
		$labels     = array(
			'everything' => __( 'Purge everything', 'pantheon-clear-caches-plus-cloudflare' ),
			'targeted'   => __( 'Purge affected URLs only', 'pantheon-clear-caches-plus-cloudflare' ),
			'none'       => __( 'Do not purge', 'pantheon-clear-caches-plus-cloudflare' ),
		);

		wp_nonce_field( self::NONCE, self::NONCE );
		?>
		<p>
			<label for="cpcf-purge-mode"><strong><?php esc_html_e( 'When this is saved', 'pantheon-clear-caches-plus-cloudflare' ); ?></strong></label><br />
			<select name="cpcf_purge_mode" id="cpcf-purge-mode" class="widefat">
				<option value="">
				<?php
					/* translators: %s: the purge mode inherited from the post type settings */
					echo esc_html( sprintf( __( 'Use post type setting (%s)', 'pantheon-clear-caches-plus-cloudflare' ), isset( $labels[ $type_mode ] ) ? $labels[ $type_mode ] : $type_mode ) );
				?>
				</option>
				<?php foreach ( $labels as $value => $label ) : ?>
					<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $mode, $value ); ?>><?php echo esc_html( $label ); ?></option>
				<?php endforeach; ?>
			</select>
		</p>
		<p>
			<label for="cpcf-extra-urls"><strong><?php esc_html_e( 'Also purge these URLs', 'pantheon-clear-caches-plus-cloudflare' ); ?></strong></label><br />
			<textarea name="cpcf_extra_urls" id="cpcf-extra-urls" class="widefat" rows="3" placeholder="/about/&#10;/team/*"><?php echo esc_textarea( $extra_urls ); ?></textarea>
			<span class="description"><?php esc_html_e( 'One per line. Paths are relative to the site. End a path with * to purge everything under it.', 'pantheon-clear-caches-plus-cloudflare' ); ?></span>
		</p>
		<p>
			<strong><?php esc_html_e( 'Purge this when any of these change', 'pantheon-clear-caches-plus-cloudflare' ); ?></strong><br />
			<?php foreach ( get_post_types( array( 'public' => true ), 'objects' ) as $type ) : ?>
				<label style="display:block;">
					<input type="checkbox" name="cpcf_depends_on[]" value="<?php echo esc_attr( $type->name ); ?>" <?php checked( in_array( $type->name, $depends, true ) ); ?> />
					<?php echo esc_html( $type->labels->name ); ?>
				</label>
			<?php endforeach; ?>
			<span class="description"><?php esc_html_e( 'Use this for pages that list or embed other content, such as a team page built from "People" entries.', 'pantheon-clear-caches-plus-cloudflare' ); ?></span>
		</p>
		<?php
	}

	/**
	 * Save the meta box values.
	 *
	 * @param int $post_id Post ID.
	 */
	public function save( $post_id ) {
		if ( ! isset( $_POST[ self::NONCE ] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ self::NONCE ] ) ), self::NONCE ) ) {
			return;
		}

		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || wp_is_post_revision( $post_id ) ) {
			return;
		}

		if ( ! current_user_can( 'manage_options' ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$mode = isset( $_POST['cpcf_purge_mode'] ) ? sanitize_key( wp_unslash( $_POST['cpcf_purge_mode'] ) ) : '';

		if ( in_array( $mode, array( 'everything', 'targeted', 'none' ), true ) ) {
			update_post_meta( $post_id, self::META_MODE, $mode );
		} else {
			delete_post_meta( $post_id, self::META_MODE );
		}

		$extra = isset( $_POST['cpcf_extra_urls'] ) ? sanitize_textarea_field( wp_unslash( $_POST['cpcf_extra_urls'] ) ) : '';
		$extra = implode( "\n", Settings::parse_url_list( $extra ) );

		if ( '' !== $extra ) {
			update_post_meta( $post_id, self::META_EXTRA_URLS, $extra );
		} else {
			delete_post_meta( $post_id, self::META_EXTRA_URLS );
		}

		$depends = array();

		if ( isset( $_POST['cpcf_depends_on'] ) && is_array( $_POST['cpcf_depends_on'] ) ) {
			foreach ( wp_unslash( $_POST['cpcf_depends_on'] ) as $type ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Each value is sanitized with sanitize_key() below.
				$type = sanitize_key( $type );

				if ( '' !== $type && post_type_exists( $type ) ) {
					$depends[] = $type;
				}
			}
		}

		delete_post_meta( $post_id, self::META_DEPENDS );

		foreach ( array_unique( $depends ) as $type ) {
			add_post_meta( $post_id, self::META_DEPENDS, $type );
		}
	}
}
