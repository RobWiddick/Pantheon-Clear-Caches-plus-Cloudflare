<?php
/**
 * Settings storage and sanitization.
 *
 * @package CPCF
 */

namespace CPCF;

defined( 'ABSPATH' ) || exit;

/**
 * Wraps the single options array used by the plugin.
 */
class Settings {

	/**
	 * Option name.
	 */
	const OPTION = 'cpcf_settings';

	/**
	 * Settings group used with the Settings API.
	 */
	const GROUP = 'cpcf_settings_group';

	/**
	 * Cached merged settings.
	 *
	 * @var array|null
	 */
	private $cache = null;

	/**
	 * Default values.
	 *
	 * Keep this free of translation calls: it runs before "init".
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			// Connection.
			'api_token'                 => '',
			'zone_id'                   => '',
			'zone_name'                 => '',

			// Behaviour.
			'enabled'                   => true,
			'production_only'           => true,
			'purge_mode'                => 'everything',
			'post_type_modes'           => array(),
			'slug_change_everything'    => true,
			'sitewide_purge'            => true,
			'term_purge'                => 'follow',
			'comment_purge'             => 'post',

			// Targeted scope.
			'include_home'              => true,
			'include_post_type_archive' => true,
			'include_terms'             => true,
			'include_author'            => true,
			'include_dates'             => true,
			'include_feeds'             => true,
			'include_ancestors'         => true,
			'include_adjacent'          => true,
			'reference_lookup'          => 'all',
			'reference_limit'           => 50,
			'archive_method'            => 'prefix',
			'pagination_pages'          => 3,
			'scheme_variants'           => true,
			'targeted_fallback_limit'   => 200,
			'always_purge_urls'         => '',
			'rules'                     => array(),

			// Other caches and UI.
			'flush_object_cache'        => false,
			'pantheon_purge'            => true,
			'admin_bar'                 => true,
			'log_enabled'               => true,
		);
	}

	/**
	 * Allowed values for select style settings.
	 *
	 * @return array
	 */
	public static function choices() {
		return array(
			'purge_mode'       => array( 'everything', 'targeted' ),
			'post_type_mode'   => array( 'default', 'everything', 'targeted', 'none' ),
			'term_purge'       => array( 'follow', 'everything', 'targeted', 'none' ),
			'comment_purge'    => array( 'post', 'follow', 'none' ),
			'reference_lookup' => array( 'all', 'content', 'none' ),
			'archive_method'   => array( 'prefix', 'urls' ),
		);
	}

	/**
	 * Get all settings merged with defaults.
	 *
	 * @return array
	 */
	public function all() {
		if ( null === $this->cache ) {
			$stored = get_option( self::OPTION, array() );

			if ( ! is_array( $stored ) ) {
				$stored = array();
			}

			$this->cache = array_merge( self::defaults(), $stored );
		}

		return $this->cache;
	}

	/**
	 * Get a single setting.
	 *
	 * @param string $key     Setting key.
	 * @param mixed  $fallback Value when the key is unknown.
	 * @return mixed
	 */
	public function get( $key, $fallback = null ) {
		$all = $this->all();

		return array_key_exists( $key, $all ) ? $all[ $key ] : $fallback;
	}

	/**
	 * Persist a set of values (merged into the existing option).
	 *
	 * @param array $values Values to store.
	 */
	public function update( array $values ) {
		$stored = get_option( self::OPTION, array() );

		if ( ! is_array( $stored ) ) {
			$stored = array();
		}

		update_option( self::OPTION, array_merge( $stored, $values ) );
		$this->cache = null;
	}

	/**
	 * Store defaults on activation without overwriting existing values.
	 */
	public function install_defaults() {
		$stored = get_option( self::OPTION, null );

		if ( ! is_array( $stored ) ) {
			add_option( self::OPTION, self::defaults() );
		} else {
			update_option( self::OPTION, array_merge( self::defaults(), $stored ) );
		}

		$this->cache = null;
	}

	/**
	 * Whether the API token comes from wp-config.php.
	 *
	 * @return bool
	 */
	public function token_from_constant() {
		return defined( 'CPCF_CLOUDFLARE_API_TOKEN' ) && '' !== (string) CPCF_CLOUDFLARE_API_TOKEN;
	}

	/**
	 * Whether the zone ID comes from wp-config.php.
	 *
	 * @return bool
	 */
	public function zone_from_constant() {
		return defined( 'CPCF_CLOUDFLARE_ZONE_ID' ) && '' !== (string) CPCF_CLOUDFLARE_ZONE_ID;
	}

	/**
	 * Get the Cloudflare API token (constant first, then the stored, encrypted option).
	 *
	 * @return string
	 */
	public function get_api_token() {
		if ( $this->token_from_constant() ) {
			return (string) CPCF_CLOUDFLARE_API_TOKEN;
		}

		return Secrets::decrypt( (string) $this->get( 'api_token' ) );
	}

	/**
	 * Whether a token is stored but can no longer be decrypted.
	 *
	 * @return bool
	 */
	public function token_unreadable() {
		return ! $this->token_from_constant() && Secrets::is_unreadable( (string) $this->get( 'api_token' ) );
	}

	/**
	 * Get the Cloudflare zone ID.
	 *
	 * @return string
	 */
	public function get_zone_id() {
		if ( $this->zone_from_constant() ) {
			return (string) CPCF_CLOUDFLARE_ZONE_ID;
		}

		return (string) $this->get( 'zone_id' );
	}

	/**
	 * Get the human readable zone name.
	 *
	 * @return string
	 */
	public function get_zone_name() {
		$name = (string) $this->get( 'zone_name' );

		if ( '' === $name && $this->zone_from_constant() ) {
			$name = (string) CPCF_CLOUDFLARE_ZONE_ID;
		}

		return $name;
	}

	/**
	 * Whether Cloudflare credentials are configured.
	 *
	 * @return bool
	 */
	public function is_connected() {
		return '' !== $this->get_api_token() && '' !== $this->get_zone_id();
	}

	/**
	 * Store the token and zone (used by the connection flow and the legacy import).
	 *
	 * @param string $token     API token (plain text).
	 * @param string $zone_id   Zone ID.
	 * @param string $zone_name Zone name.
	 */
	public function store_connection( $token, $zone_id, $zone_name = '' ) {
		$values = array(
			'zone_id'   => sanitize_text_field( $zone_id ),
			'zone_name' => sanitize_text_field( $zone_name ),
		);

		if ( null !== $token ) {
			$values['api_token'] = Secrets::encrypt( sanitize_text_field( $token ) );
		}

		$this->update( $values );
	}

	/**
	 * Remove stored credentials.
	 */
	public function disconnect() {
		$this->update(
			array(
				'api_token' => '',
				'zone_id'   => '',
				'zone_name' => '',
			)
		);
	}

	/**
	 * Resolve the purge mode for a post type.
	 *
	 * @param string $post_type Post type name.
	 * @return string everything|targeted|none
	 */
	public function mode_for_post_type( $post_type ) {
		$modes = $this->get( 'post_type_modes' );
		$mode  = ( is_array( $modes ) && isset( $modes[ $post_type ] ) ) ? $modes[ $post_type ] : 'default';

		if ( 'default' === $mode ) {
			$mode = ( 'attachment' === $post_type ) ? 'targeted' : (string) $this->get( 'purge_mode' );
		}

		if ( ! in_array( $mode, array( 'everything', 'targeted', 'none' ), true ) ) {
			$mode = 'everything';
		}

		/**
		 * Filter the purge mode used for a post type.
		 *
		 * @param string $mode      everything|targeted|none.
		 * @param string $post_type Post type name.
		 */
		return apply_filters( 'cpcf_post_type_purge_mode', $mode, $post_type );
	}

	/**
	 * Resolve the purge mode for a specific post (per-post override, then post type, then global).
	 *
	 * @param \WP_Post $post Post object.
	 * @return string everything|targeted|none
	 */
	public function mode_for_post( \WP_Post $post ) {
		$override = (string) get_post_meta( $post->ID, Meta_Box::META_MODE, true );

		if ( in_array( $override, array( 'everything', 'targeted', 'none' ), true ) ) {
			$mode = $override;
		} else {
			$mode = $this->mode_for_post_type( $post->post_type );
		}

		/**
		 * Filter the purge mode used for a specific post.
		 *
		 * @param string   $mode everything|targeted|none.
		 * @param \WP_Post $post Post object.
		 */
		return apply_filters( 'cpcf_post_purge_mode', $mode, $post );
	}

	/**
	 * Dependency rules.
	 *
	 * @return array[] Each rule has post_type (string), urls (string[]) and everything (bool).
	 */
	public function rules() {
		$rules = $this->get( 'rules' );

		return is_array( $rules ) ? $rules : array();
	}

	/**
	 * URLs that are purged with every targeted purge.
	 *
	 * @return string[]
	 */
	public function always_purge_urls() {
		return self::parse_url_list( (string) $this->get( 'always_purge_urls' ) );
	}

	/**
	 * Split a textarea value into a clean list of URLs/paths.
	 *
	 * @param string $text One entry per line.
	 * @return string[]
	 */
	public static function parse_url_list( $text ) {
		$lines = preg_split( '/[\r\n]+/', (string) $text );
		$urls  = array();

		foreach ( (array) $lines as $line ) {
			$line = trim( $line );

			if ( '' === $line || 0 === strpos( $line, '#' ) ) {
				continue;
			}

			$urls[] = $line;
		}

		return array_values( array_unique( $urls ) );
	}

	/**
	 * Sanitize the settings array submitted through the Settings API.
	 *
	 * @param mixed $input Raw input.
	 * @return array
	 */
	public function sanitize( $input ) {
		$current  = $this->all();
		$defaults = self::defaults();
		$choices  = self::choices();
		$input    = is_array( $input ) ? $input : array();
		$clean    = $current;

		// Connection: a blank token keeps the existing one; the constant always wins.
		if ( ! $this->token_from_constant() && isset( $input['api_token'] ) ) {
			$token = trim( sanitize_text_field( $input['api_token'] ) );

			if ( '' !== $token ) {
				$clean['api_token'] = Secrets::encrypt( $token );
			}
		}

		if ( ! $this->zone_from_constant() && isset( $input['zone'] ) ) {
			$zone = sanitize_text_field( $input['zone'] );

			if ( '' === $zone ) {
				$clean['zone_id']   = '';
				$clean['zone_name'] = '';
			} elseif ( preg_match( '/^([a-f0-9]{32})(?:\|(.*))?$/', $zone, $matches ) ) {
				$clean['zone_id']   = $matches[1];
				$clean['zone_name'] = isset( $matches[2] ) ? sanitize_text_field( $matches[2] ) : '';
			} else {
				add_settings_error( self::OPTION, 'cpcf_zone', __( 'The selected zone is not valid. Please verify your token and choose a zone again.', 'purge-pantheon-cloudflare-caches' ) );
			}
		}

		// Booleans: checkboxes that are absent from the submission are false.
		$booleans = array(
			'enabled',
			'production_only',
			'slug_change_everything',
			'sitewide_purge',
			'include_home',
			'include_post_type_archive',
			'include_terms',
			'include_author',
			'include_dates',
			'include_feeds',
			'include_ancestors',
			'include_adjacent',
			'scheme_variants',
			'flush_object_cache',
			'pantheon_purge',
			'admin_bar',
			'log_enabled',
		);

		foreach ( $booleans as $key ) {
			$clean[ $key ] = ! empty( $input[ $key ] );
		}

		// Selects.
		foreach ( array( 'purge_mode', 'term_purge', 'comment_purge', 'reference_lookup', 'archive_method' ) as $key ) {
			$value         = isset( $input[ $key ] ) ? sanitize_key( $input[ $key ] ) : $defaults[ $key ];
			$clean[ $key ] = in_array( $value, $choices[ $key ], true ) ? $value : $defaults[ $key ];
		}

		// Per post type modes.
		$modes = array();

		if ( isset( $input['post_type_modes'] ) && is_array( $input['post_type_modes'] ) ) {
			foreach ( $input['post_type_modes'] as $post_type => $mode ) {
				$post_type = sanitize_key( $post_type );
				$mode      = sanitize_key( $mode );

				if ( '' !== $post_type && in_array( $mode, $choices['post_type_mode'], true ) && 'default' !== $mode ) {
					$modes[ $post_type ] = $mode;
				}
			}
		}

		$clean['post_type_modes'] = $modes;

		// Numbers.
		$clean['reference_limit']         = isset( $input['reference_limit'] ) ? min( 500, max( 1, absint( $input['reference_limit'] ) ) ) : $defaults['reference_limit'];
		$clean['pagination_pages']        = isset( $input['pagination_pages'] ) ? min( 25, absint( $input['pagination_pages'] ) ) : $defaults['pagination_pages'];
		$clean['targeted_fallback_limit'] = isset( $input['targeted_fallback_limit'] ) ? min( 2000, absint( $input['targeted_fallback_limit'] ) ) : $defaults['targeted_fallback_limit'];

		// URL lists.
		$clean['always_purge_urls'] = isset( $input['always_purge_urls'] ) ? implode( "\n", self::parse_url_list( sanitize_textarea_field( $input['always_purge_urls'] ) ) ) : '';

		// Dependency rules.
		$rules = array();

		if ( isset( $input['rules'] ) && is_array( $input['rules'] ) ) {
			foreach ( $input['rules'] as $rule ) {
				if ( ! is_array( $rule ) ) {
					continue;
				}

				$post_type  = isset( $rule['post_type'] ) ? sanitize_key( $rule['post_type'] ) : '';
				$urls       = isset( $rule['urls'] ) ? self::parse_url_list( sanitize_textarea_field( $rule['urls'] ) ) : array();
				$everything = ! empty( $rule['everything'] );

				if ( '' === $post_type || ( empty( $urls ) && ! $everything ) ) {
					continue;
				}

				$rules[] = array(
					'post_type'  => $post_type,
					'urls'       => $urls,
					'everything' => $everything,
				);
			}
		}

		$clean['rules'] = $rules;

		$this->cache = null;

		return $clean;
	}
}
