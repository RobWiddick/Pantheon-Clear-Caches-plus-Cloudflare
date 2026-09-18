<?php
/**
 * Recent purge log.
 *
 * @package CPCF
 */

namespace CPCF;

defined( 'ABSPATH' ) || exit;

/**
 * Stores the most recent purge operations in a non-autoloaded option.
 */
class Log {

	/**
	 * Option name.
	 */
	const OPTION = 'cpcf_log';

	/**
	 * Maximum entries kept.
	 */
	const MAX_ENTRIES = 50;

	/**
	 * Maximum URLs stored per entry.
	 */
	const MAX_URLS_PER_ENTRY = 40;

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
	 * Whether logging is enabled.
	 *
	 * @return bool
	 */
	public function enabled() {
		return (bool) $this->settings->get( 'log_enabled' );
	}

	/**
	 * Add a log entry.
	 *
	 * @param array $entry Entry data.
	 */
	public function add( array $entry ) {
		if ( ! $this->enabled() ) {
			return;
		}

		$entry = wp_parse_args(
			$entry,
			array(
				'time'       => time(),
				'source'     => 'automatic',
				'everything' => false,
				'urls'       => array(),
				'prefixes'   => array(),
				'url_count'  => 0,
				'reasons'    => array(),
				'targets'    => array(),
				'success'    => true,
				'message'    => '',
			)
		);

		$entry['urls']     = array_slice( array_map( 'esc_url_raw', (array) $entry['urls'] ), 0, self::MAX_URLS_PER_ENTRY );
		$entry['prefixes'] = array_slice( array_map( 'sanitize_text_field', (array) $entry['prefixes'] ), 0, self::MAX_URLS_PER_ENTRY );
		$entry['reasons']  = array_slice( array_map( 'sanitize_text_field', array_unique( (array) $entry['reasons'] ) ), 0, 10 );
		$entry['message']  = sanitize_text_field( (string) $entry['message'] );

		$entries = $this->entries();
		array_unshift( $entries, $entry );

		update_option( self::OPTION, array_slice( $entries, 0, self::MAX_ENTRIES ), false );
	}

	/**
	 * Get log entries, newest first.
	 *
	 * @return array[]
	 */
	public function entries() {
		$entries = get_option( self::OPTION, array() );

		return is_array( $entries ) ? $entries : array();
	}

	/**
	 * Remove all log entries.
	 */
	public function clear() {
		delete_option( self::OPTION );
	}
}
