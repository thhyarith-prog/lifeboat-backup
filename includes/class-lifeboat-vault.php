<?php
/**
 * Backup encryption: password-protected archives.
 *
 * Every archive part is encrypted with AES-256-GCM in 1 MiB chunks before it leaves the
 * server, so cloud providers (and anyone who gets into the cloud account) only ever see
 * ciphertext.
 *
 *  - The key comes from the user's password with PBKDF2-SHA256 and a random salt. The site
 *    keeps the derived key (itself encrypted with the WordPress salts) so scheduled backups
 *    run unattended. The password itself is never stored.
 *  - Each file gets its own key (HKDF from the master key and a random file id), and each
 *    chunk is authenticated with its index and a "last chunk" flag, so tampering, reordering
 *    and truncation are all detected.
 *  - Chunks have a fixed size, so restores decrypt any byte range directly (resumable,
 *    no temporary decrypted copy on disk).
 *
 * File layout (.enc):
 *   "LBX1" | iterations (uint32 BE) | salt (16) | file id (16) | chunk size (uint32 BE)
 *   then for each chunk: ciphertext | GCM tag (16)
 *   nonce = uint64 BE chunk index | uint32 BE last-chunk flag; header is the GCM AAD.
 *
 * @package Lifeboat_Backup
 */

defined( 'ABSPATH' ) || exit;

class Lifeboat_Vault {

	const OPTION   = 'lifeboat_encryption';
	const MAGIC    = 'LBX1';
	const HEADER   = 44;
	const CHUNK    = 1048576;
	const TAG      = 16;
	const ITER     = 600000;
	const MIN_PASS = 8;
	const SUFFIX   = '.enc';

	public static function available() {
		return function_exists( 'openssl_encrypt' ) && function_exists( 'hash_pbkdf2' ) && function_exists( 'hash_hkdf' )
			&& in_array( 'aes-256-gcm', array_map( 'strtolower', (array) openssl_get_cipher_methods() ), true );
	}

	/** @return array|null Stored settings: salt, iter, check, key (encrypted), since. */
	public static function config() {
		$c = get_option( self::OPTION );
		return is_array( $c ) && ! empty( $c['salt'] ) && ! empty( $c['check'] ) ? $c : null;
	}

	public static function is_on() {
		return (bool) self::config();
	}

	/** Public parameters recorded with each backup: enough to check a password, not to decrypt. */
	public static function params( $c = null ) {
		$c = null === $c ? self::config() : $c;
		if ( ! $c ) {
			return null;
		}
		return array(
			'cipher' => 'aes-256-gcm',
			'kdf'    => 'pbkdf2-sha256',
			'iter'   => (int) $c['iter'],
			'salt'   => $c['salt'],
			'check'  => $c['check'],
		);
	}

	/** The site's key, or '' when encryption is off or the key can't be unlocked (site moved). */
	public static function site_key() {
		$c = self::config();
		if ( ! $c || empty( $c['key'] ) ) {
			return '';
		}
		$k = Lifeboat_Crypto::decrypt( $c['key'] );
		return 32 === strlen( $k ) && hash_equals( $c['check'], self::check_value( $k ) ) ? $k : '';
	}

	/** Encryption is on, but the password must be entered again (e.g. after moving the site). */
	public static function needs_password() {
		return self::is_on() && '' === self::site_key();
	}

	public static function derive( $password, $salt_b64, $iter ) {
		$iter = max( 10000, min( 10000000, (int) $iter ) );
		return hash_pbkdf2( 'sha256', (string) $password, (string) base64_decode( (string) $salt_b64 ), $iter, 32, true ); // phpcs:ignore
	}

	public static function check_value( $key ) {
		return base64_encode( substr( hash_hmac( 'sha256', 'lifeboat-key-check', $key, true ), 0, 16 ) ); // phpcs:ignore
	}

	/** Turn encryption on, or change the password (new salt; older backups keep their old password). */
	public static function set_password( $password ) {
		$salt = base64_encode( random_bytes( 16 ) ); // phpcs:ignore
		$key  = self::derive( $password, $salt, self::ITER );
		update_option(
			self::OPTION,
			array(
				'salt'  => $salt,
				'iter'  => self::ITER,
				'check' => self::check_value( $key ),
				'key'   => Lifeboat_Crypto::encrypt( $key ),
				'since' => time(),
			),
			false
		);
	}

	/** True when $password is the current encryption password (always derives; never trusts the stored key). */
	public static function check_password( $password ) {
		$c = self::config();
		if ( ! $c || '' === (string) $password ) {
			return false;
		}
		return hash_equals( $c['check'], self::check_value( self::derive( $password, $c['salt'], $c['iter'] ) ) );
	}

	/** Re-enter the current password on a site whose stored key can no longer be unlocked. */
	public static function unlock( $password ) {
		$c = self::config();
		if ( ! $c ) {
			return false;
		}
		$key = self::derive( $password, $c['salt'], $c['iter'] );
		if ( ! hash_equals( $c['check'], self::check_value( $key ) ) ) {
			return false;
		}
		$c['key'] = Lifeboat_Crypto::encrypt( $key );
		update_option( self::OPTION, $c, false );
		return true;
	}

	public static function turn_off() {
		delete_option( self::OPTION );
	}

	/**
	 * Key for a backup's parameters: the site's own key if it matches, otherwise derived from
	 * $password. Returns '' when no key is available or the password is wrong.
	 */
	public static function key_for( array $params, $password = '' ) {
		if ( empty( $params['salt'] ) || empty( $params['check'] ) ) {
			return '';
		}
		$site = self::site_key();
		if ( $site && hash_equals( (string) $params['check'], self::check_value( $site ) ) ) {
			return $site;
		}
		if ( '' === (string) $password ) {
			return '';
		}
		$k = self::derive( $password, $params['salt'], isset( $params['iter'] ) ? $params['iter'] : self::ITER );
		return hash_equals( (string) $params['check'], self::check_value( $k ) ) ? $k : '';
	}

	/** True when this site can restore the backup without asking for a password. */
	public static function can_open( array $params ) {
		return '' !== self::key_for( $params );
	}

	/* ============================================================ format */

	public static function file_key( $key, $file_id ) {
		return hash_hkdf( 'sha256', $key, 32, 'lifeboat-file-v1', $file_id );
	}

	public static function nonce( $index, $last ) {
		return pack( 'NNN', (int) floor( $index / 4294967296 ), $index % 4294967296, $last ? 1 : 0 );
	}

	/** @return array iter, salt (base64), id (raw), chunk, header (raw). */
	public static function read_header( $fh ) {
		$h = fread( $fh, self::HEADER );
		if ( ! is_string( $h ) || strlen( $h ) !== self::HEADER || self::MAGIC !== substr( $h, 0, 4 ) ) {
			throw new RuntimeException( __( 'This file is not a Lifeboat encrypted archive.', 'lifeboat-backup' ) );
		}
		$iter  = unpack( 'N', substr( $h, 4, 4 ) );
		$chunk = unpack( 'N', substr( $h, 40, 4 ) );
		if ( $chunk[1] < 1024 || $chunk[1] > 67108864 ) {
			throw new RuntimeException( __( 'The encrypted archive header is damaged.', 'lifeboat-backup' ) );
		}
		return array(
			'iter'   => $iter[1],
			'salt'   => base64_encode( substr( $h, 8, 16 ) ), // phpcs:ignore
			'id'     => substr( $h, 24, 16 ),
			'chunk'  => $chunk[1],
			'header' => $h,
		);
	}

	/**
	 * Encrypt $src into $dst, a time slice at a time.
	 *
	 * @param array  $st     JSON-safe state, kept between slices.
	 * @param array  $params Backup parameters (iter, salt).
	 * @return bool True when $dst is complete.
	 */
	public static function encrypt_step( array &$st, $src, $dst, $key, array $params, $deadline, $easy = false ) {
		clearstatcache( true, $src );
		$size = @filesize( $src ); // phpcs:ignore
		if ( false === $size ) {
			throw new RuntimeException( sprintf( __( 'Cannot read %s for encryption.', 'lifeboat-backup' ), basename( $src ) ) );
		}
		$st += array( 'done' => 0, 'id' => '' );
		if ( '' === $st['id'] ) {
			$st['id']   = base64_encode( random_bytes( 16 ) ); // phpcs:ignore
			$st['done'] = 0;
		}
		$id     = base64_decode( $st['id'] ); // phpcs:ignore
		$header = self::MAGIC . pack( 'N', (int) $params['iter'] ) . base64_decode( $params['salt'] ) . $id . pack( 'N', self::CHUNK ); // phpcs:ignore
		$fkey   = self::file_key( $key, $id );

		$in  = fopen( $src, 'rb' );
		$out = fopen( $dst, 'c+b' );
		if ( ! $in || ! $out ) {
			throw new RuntimeException( sprintf( __( 'Cannot write the encrypted copy of %s. Check free disk space.', 'lifeboat-backup' ), basename( $src ) ) );
		}
		// Resume exactly after the last complete chunk (drops a half-written one).
		$index = intdiv( (int) $st['done'], self::CHUNK );
		$pos   = 0 === $index ? 0 : self::HEADER + $index * ( self::CHUNK + self::TAG );
		ftruncate( $out, $pos );
		fseek( $out, $pos );
		if ( 0 === $pos ) {
			fwrite( $out, $header );
		}
		fseek( $in, $index * self::CHUNK );
		$st['done'] = $index * self::CHUNK;
		$finished   = false;
		try {
			do { // At least one chunk per call, so every slice makes progress.
				$len  = (int) min( self::CHUNK, $size - $st['done'] );
				$last = $st['done'] + $len >= $size;
				$data = $len > 0 ? fread( $in, $len ) : '';
				if ( ! is_string( $data ) || strlen( $data ) !== $len ) {
					throw new RuntimeException( sprintf( __( '%s changed while it was being encrypted.', 'lifeboat-backup' ), basename( $src ) ) );
				}
				$tag = '';
				$ct  = openssl_encrypt( $data, 'aes-256-gcm', $fkey, OPENSSL_RAW_DATA, self::nonce( $index, $last ), $tag, $header, self::TAG );
				if ( false === $ct || false === fwrite( $out, $ct . $tag ) ) {
					throw new RuntimeException( __( 'Encryption failed. Check free disk space.', 'lifeboat-backup' ) );
				}
				$st['done'] += $len;
				$index++;
				Lifeboat_Runner::throttle( $len, $easy );
				if ( $last ) {
					$finished = true;
					break;
				}
			} while ( microtime( true ) < $deadline );
		} finally {
			fclose( $in );
			fclose( $out );
		}
		return $finished;
	}
}

/**
 * Random-access reader for an encrypted archive: returns plaintext for any byte range.
 */
class Lifeboat_Vault_Reader {

	private $fh;
	private $info;
	private $fkey;
	private $chunks;
	private $last_len;
	private $plain_size;
	private $cache_index = -1;
	private $cache       = '';

	public function __construct( $file, $key ) {
		$this->fh = @fopen( $file, 'rb' ); // phpcs:ignore
		if ( ! $this->fh ) {
			throw new RuntimeException( sprintf( __( 'Cannot read %s', 'lifeboat-backup' ), basename( $file ) ) );
		}
		$this->info = Lifeboat_Vault::read_header( $this->fh );
		$body       = filesize( $file ) - Lifeboat_Vault::HEADER;
		$stride     = $this->info['chunk'] + Lifeboat_Vault::TAG;
		$this->chunks   = max( 1, (int) ceil( $body / $stride ) );
		$this->last_len = $body - ( $this->chunks - 1 ) * $stride - Lifeboat_Vault::TAG;
		if ( $body < Lifeboat_Vault::TAG || $this->last_len < 0 ) {
			throw new RuntimeException( sprintf( __( '%s is incomplete or damaged.', 'lifeboat-backup' ), basename( $file ) ) );
		}
		$this->plain_size = ( $this->chunks - 1 ) * $this->info['chunk'] + $this->last_len;
		$this->fkey       = Lifeboat_Vault::file_key( $key, $this->info['id'] );
	}

	public function size() {
		return $this->plain_size;
	}

	private function chunk( $index ) {
		if ( $index === $this->cache_index ) {
			return $this->cache;
		}
		$last   = $index === $this->chunks - 1;
		$len    = $last ? $this->last_len : $this->info['chunk'];
		fseek( $this->fh, Lifeboat_Vault::HEADER + $index * ( $this->info['chunk'] + Lifeboat_Vault::TAG ) );
		$raw = $len + Lifeboat_Vault::TAG > 0 ? fread( $this->fh, $len + Lifeboat_Vault::TAG ) : '';
		if ( ! is_string( $raw ) || strlen( $raw ) !== $len + Lifeboat_Vault::TAG ) {
			throw new RuntimeException( __( 'The encrypted archive is truncated.', 'lifeboat-backup' ) );
		}
		$plain = openssl_decrypt( substr( $raw, 0, $len ), 'aes-256-gcm', $this->fkey, OPENSSL_RAW_DATA, Lifeboat_Vault::nonce( $index, $last ), substr( $raw, $len ), $this->info['header'] );
		if ( false === $plain ) {
			throw new RuntimeException( __( 'Could not decrypt the backup: wrong password, or the file is damaged.', 'lifeboat-backup' ) );
		}
		$this->cache_index = $index;
		$this->cache       = $plain;
		return $plain;
	}

	/** Plaintext bytes [$offset, $offset + $len). */
	public function read( $offset, $len ) {
		$out = '';
		while ( $len > 0 && $offset < $this->plain_size ) {
			$i     = intdiv( $offset, $this->info['chunk'] );
			$piece = substr( $this->chunk( $i ), $offset - $i * $this->info['chunk'], $len );
			if ( '' === $piece ) {
				break;
			}
			$out    .= $piece;
			$offset += strlen( $piece );
			$len    -= strlen( $piece );
		}
		return $out;
	}

	public function close() {
		if ( $this->fh ) {
			fclose( $this->fh );
			$this->fh = null;
		}
	}

	public function __destruct() {
		$this->close();
	}
}
