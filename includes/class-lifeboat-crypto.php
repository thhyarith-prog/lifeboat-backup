<?php
/**
 * Encrypts cloud credentials (tokens, client secrets, MEGA keys) at rest.
 *
 * @package Lifeboat_Backup
 */

defined( 'ABSPATH' ) || exit;

class Lifeboat_Crypto {

	private static function key() {
		$material = defined( 'LIFEBOAT_ENCRYPTION_KEY' ) ? LIFEBOAT_ENCRYPTION_KEY : ( defined( 'AUTH_KEY' ) ? AUTH_KEY : '' ) . ( defined( 'SECURE_AUTH_SALT' ) ? SECURE_AUTH_SALT : '' );
		return hash( 'sha256', 'lifeboat|' . $material, true );
	}

	public static function encrypt( $plain ) {
		if ( '' === (string) $plain ) {
			return '';
		}
		if ( ! function_exists( 'openssl_encrypt' ) ) {
			return 'p:' . base64_encode( $plain ); // phpcs:ignore
		}
		$iv  = random_bytes( 12 );
		$tag = '';
		$ct  = openssl_encrypt( (string) $plain, 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, $iv, $tag );
		return 'g:' . base64_encode( $iv . $tag . $ct ); // phpcs:ignore
	}

	public static function decrypt( $stored ) {
		$stored = (string) $stored;
		if ( '' === $stored ) {
			return '';
		}
		if ( 0 === strpos( $stored, 'p:' ) ) {
			return (string) base64_decode( substr( $stored, 2 ) ); // phpcs:ignore
		}
		if ( 0 !== strpos( $stored, 'g:' ) ) {
			return '';
		}
		$raw = base64_decode( substr( $stored, 2 ), true ); // phpcs:ignore
		if ( false === $raw || strlen( $raw ) < 29 ) {
			return '';
		}
		$out = openssl_decrypt( substr( $raw, 28 ), 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, substr( $raw, 0, 12 ), substr( $raw, 12, 16 ) );
		return false === $out ? '' : $out;
	}
}
