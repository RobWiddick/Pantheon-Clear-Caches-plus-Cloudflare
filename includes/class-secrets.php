<?php
/**
 * Lightweight at-rest encryption for the stored API token.
 *
 * @package CPCF
 */

namespace CPCF;

defined( 'ABSPATH' ) || exit;

/**
 * Encrypts the Cloudflare API token before it is written to the options table.
 *
 * The key is derived from the site's AUTH salt, so a database dump alone does not expose the token.
 * If the salts change the token must simply be re-entered.
 */
class Secrets {

	/**
	 * Marker prefix identifying an encrypted value.
	 */
	const PREFIX = 'cpcf1:';

	/**
	 * Cipher used for encryption.
	 */
	const CIPHER = 'aes-256-cbc';

	/**
	 * Encrypt a plain text value.
	 *
	 * @param string $plain Plain text.
	 * @return string Encrypted value (or plain text when OpenSSL is unavailable).
	 */
	public static function encrypt( $plain ) {
		$plain = (string) $plain;

		if ( '' === $plain || ! self::available() ) {
			return $plain;
		}

		$iv     = random_bytes( 16 );
		$cipher = openssl_encrypt( $plain, self::CIPHER, self::key(), OPENSSL_RAW_DATA, $iv );

		if ( false === $cipher ) {
			return $plain;
		}

		return self::PREFIX . base64_encode( $iv . $cipher ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Binary-safe storage, not obfuscation.
	}

	/**
	 * Decrypt a stored value.
	 *
	 * @param string $stored Stored value.
	 * @return string Plain text, or an empty string when the value cannot be decrypted.
	 */
	public static function decrypt( $stored ) {
		$stored = (string) $stored;

		if ( '' === $stored || 0 !== strpos( $stored, self::PREFIX ) ) {
			return $stored;
		}

		if ( ! self::available() ) {
			return '';
		}

		$raw = base64_decode( substr( $stored, strlen( self::PREFIX ) ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Binary-safe storage, not obfuscation.

		if ( false === $raw || strlen( $raw ) <= 16 ) {
			return '';
		}

		$plain = openssl_decrypt( substr( $raw, 16 ), self::CIPHER, self::key(), OPENSSL_RAW_DATA, substr( $raw, 0, 16 ) );

		return false === $plain ? '' : $plain;
	}

	/**
	 * Whether a stored value is encrypted but can no longer be decrypted (e.g. the salts changed).
	 *
	 * @param string $stored Stored value.
	 * @return bool
	 */
	public static function is_unreadable( $stored ) {
		$stored = (string) $stored;

		return '' !== $stored && 0 === strpos( $stored, self::PREFIX ) && '' === self::decrypt( $stored );
	}

	/**
	 * Whether OpenSSL is available.
	 *
	 * @return bool
	 */
	private static function available() {
		return function_exists( 'openssl_encrypt' ) && function_exists( 'openssl_decrypt' ) && in_array( self::CIPHER, openssl_get_cipher_methods(), true );
	}

	/**
	 * Derive the encryption key from the site salts.
	 *
	 * @return string 32 byte key.
	 */
	private static function key() {
		return hash( 'sha256', wp_salt( 'auth' ) . '|cpcf-token', true );
	}
}
