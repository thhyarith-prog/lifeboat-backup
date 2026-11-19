<?php
/**
 * MEGA (native, end-to-end encrypted API).
 *
 * MEGA has no OAuth. Lifeboat signs in once (supporting MEGA two-factor codes), keeps only the
 * resulting session ID and master key (encrypted at rest) and forgets the password. Files are
 * encrypted locally with AES-128-CTR and authenticated with MEGA's chunked CBC-MAC before
 * upload, exactly like MEGA's own apps, so MEGA never sees the backup contents.
 *
 * @package Lifeboat_Backup
 */

defined( 'ABSPATH' ) || exit;

class Lifeboat_Mega extends Lifeboat_Destination {

	public $id = 'mega';

	const API = 'https://g.api.mega.co.nz/cs';

	private static $errors = array(
		-1  => 'internal error',
		-2  => 'invalid arguments',
		-3  => 'temporarily busy, try again',
		-4  => 'rate limited',
		-6  => 'too many concurrent connections',
		-9  => 'not found or wrong email/password',
		-11 => 'access denied',
		-13 => 'incomplete',
		-14 => 'decryption failed',
		-15 => 'session expired — reconnect MEGA',
		-16 => 'account blocked',
		-17 => 'storage quota exceeded',
		-18 => 'temporarily unavailable',
		-26 => 'two-factor code required',
	);

	public function label() {
		return 'MEGA';
	}

	public function fields() {
		return array(
			'email'    => array( __( 'MEGA email', 'lifeboat-backup' ), 'text' ),
			'password' => array( __( 'MEGA password', 'lifeboat-backup' ), 'password', __( 'Used once to sign in, never stored.', 'lifeboat-backup' ) ),
			'mfa'      => array( __( 'Two-factor code', 'lifeboat-backup' ), 'text', __( 'Only if your MEGA account uses two-factor authentication.', 'lifeboat-backup' ) ),
		);
	}

	public function setup_steps() {
		return array(
			__( 'Tip: create a separate free MEGA account just for backups, so this site never holds a session for your personal files.', 'lifeboat-backup' ),
			__( 'Enter the email and password and click Connect. Lifeboat keeps only an encrypted session; signing out of that session in MEGA disconnects it.', 'lifeboat-backup' ),
		);
	}

	public function is_connected() {
		return '' !== $this->secret( 'sid' ) && '' !== $this->secret( 'master_key' );
	}

	/* ===================================================== encoding helpers */

	public static function b64e( $bin ) {
		return rtrim( strtr( base64_encode( $bin ), '+/', '-_' ), '=' ); // phpcs:ignore
	}

	public static function b64d( $s ) {
		$s = strtr( (string) $s, '-_,', '+/' );
		return (string) base64_decode( $s . str_repeat( '=', ( 4 - strlen( $s ) % 4 ) % 4 ) ); // phpcs:ignore
	}

	public static function ecb_enc( $data, $key ) {
		return openssl_encrypt( $data, 'aes-128-ecb', $key, OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING );
	}

	public static function ecb_dec( $data, $key ) {
		return openssl_decrypt( $data, 'aes-128-ecb', $key, OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING );
	}

	private static function pad16( $s ) {
		$r = strlen( $s ) % 16;
		return $r ? $s . str_repeat( "\0", 16 - $r ) : $s;
	}

	public static function enc_attr( array $attr, $key ) {
		$plain = self::pad16( 'MEGA' . wp_json_encode( $attr ) );
		return self::b64e( openssl_encrypt( $plain, 'aes-128-cbc', $key, OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING, str_repeat( "\0", 16 ) ) );
	}

	public static function dec_attr( $b64, $key ) {
		$plain = openssl_decrypt( self::pad16( self::b64d( $b64 ) ), 'aes-128-cbc', $key, OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING, str_repeat( "\0", 16 ) );
		if ( false === $plain || 0 !== strpos( $plain, 'MEGA{' ) ) {
			return null;
		}
		$json = json_decode( rtrim( substr( $plain, 4 ), "\0" ), true );
		return is_array( $json ) ? $json : null;
	}

	/** Legacy (v1 account) password key derivation. */
	public static function prepare_key_v1( $password ) {
		$pw   = self::pad16( $password === '' ? "\0" : $password );
		$keys = str_split( $pw, 16 );
		$pkey = pack( 'N4', 0x93C467E3, 0x7DB0C7A4, 0xD1BE3F81, 0x0152CB56 );
		for ( $r = 0; $r < 65536; $r++ ) {
			foreach ( $keys as $k ) {
				$pkey = self::ecb_enc( $pkey, $k );
			}
		}
		return $pkey;
	}

	public static function user_hash_v1( $email, $pw_key ) {
		$s   = $email;
		$s  .= str_repeat( "\0", ( 4 - strlen( $s ) % 4 ) % 4 );
		$w   = array_values( unpack( 'N*', $s ) );
		$h   = array( 0, 0, 0, 0 );
		foreach ( $w as $i => $v ) {
			$h[ $i % 4 ] ^= $v;
		}
		$hb = pack( 'N4', $h[0], $h[1], $h[2], $h[3] );
		for ( $r = 0; $r < 16384; $r++ ) {
			$hb = self::ecb_enc( $hb, $pw_key );
		}
		$o = array_values( unpack( 'N4', $hb ) );
		return self::b64e( pack( 'N2', $o[0], $o[2] ) );
	}

	/** Split MEGA's MPI encoding (16-bit bit-length + big-endian bytes) into binary integers. */
	public static function mpis( $bin, $count ) {
		$out = array();
		$pos = 0;
		for ( $i = 0; $i < $count && $pos + 2 <= strlen( $bin ); $i++ ) {
			$bits  = ( ord( $bin[ $pos ] ) << 8 ) | ord( $bin[ $pos + 1 ] );
			$len   = (int) ceil( $bits / 8 );
			$out[] = substr( $bin, $pos + 2, $len );
			$pos  += 2 + $len;
		}
		return $out;
	}

	/** Modular exponentiation on big-endian binary integers (GMP, else BCMath). */
	public static function modpow( $base, $exp, $mod ) {
		if ( function_exists( 'gmp_powm' ) ) {
			$r = gmp_powm( gmp_import( $base ), gmp_import( $exp ), gmp_import( $mod ) );
			return gmp_export( $r );
		}
		if ( function_exists( 'bcpowmod' ) ) {
			$res = bcpowmod( self::bin2dec( $base ), self::bin2dec( $exp ), self::bin2dec( $mod ), 0 );
			return self::dec2bin( $res );
		}
		throw new Lifeboat_Remote_Exception( 'MEGA needs the PHP GMP or BCMath extension. Ask your host to enable one of them.' );
	}

	public static function mul( $a, $b ) {
		if ( function_exists( 'gmp_mul' ) ) {
			return gmp_export( gmp_mul( gmp_import( $a ), gmp_import( $b ) ) );
		}
		return self::dec2bin( bcmul( self::bin2dec( $a ), self::bin2dec( $b ), 0 ) );
	}

	private static function bin2dec( $bin ) {
		$n = '0';
		$l = strlen( $bin );
		for ( $i = 0; $i < $l; $i++ ) {
			$n = bcadd( bcmul( $n, '256', 0 ), (string) ord( $bin[ $i ] ), 0 );
		}
		return $n;
	}

	private static function dec2bin( $dec ) {
		$out = '';
		while ( bccomp( $dec, '0', 0 ) > 0 ) {
			$out = chr( (int) bcmod( $dec, '256' ) ) . $out;
			$dec = bcdiv( $dec, '256', 0 );
		}
		return $out;
	}

	/* ================================================================ API */

	private function req( array $cmd, $sid = null ) {
		$sid = null === $sid ? $this->secret( 'sid' ) : $sid;
		$url = self::API . '?id=' . wp_rand( 1, 2000000000 ) . ( $sid ? '&sid=' . rawurlencode( $sid ) : '' );
		for ( $attempt = 0; $attempt < 4; $attempt++ ) {
			$r = $this->http(
				'POST',
				$url,
				array(
					'headers' => array( 'Content-Type' => 'application/json' ),
					'body'    => wp_json_encode( array( $cmd ) ),
					'timeout' => 60,
				)
			);
			$j = $r['json'];
			if ( 200 !== $r['code'] ) {
				$this->fail( $r, 'API' );
			}
			$code = is_int( $j ) ? $j : ( is_array( $j ) && isset( $j[0] ) && is_int( $j[0] ) ? $j[0] : 0 );
			if ( -3 === $code || -4 === $code || -18 === $code ) {
				sleep( 1 + $attempt * 2 );
				continue;
			}
			if ( $code < 0 ) {
				$msg = isset( self::$errors[ $code ] ) ? self::$errors[ $code ] : 'error ' . $code;
				throw new Lifeboat_Remote_Exception( 'MEGA: ' . $msg, false );
			}
			return is_array( $j ) && isset( $j[0] ) ? $j[0] : $j;
		}
		throw new Lifeboat_Remote_Exception( 'MEGA is busy, will retry.', true );
	}

	/**
	 * Sign in and keep only the session.
	 */
	public function connect( $email, $password, $mfa = '' ) {
		$email = strtolower( trim( $email ) );
		$pre   = $this->req( array( 'a' => 'us0', 'user' => $email ), '' );
		if ( isset( $pre['v'] ) && 2 === (int) $pre['v'] ) {
			$derived = hash_pbkdf2( 'sha512', $password, self::b64d( $pre['s'] ), 100000, 32, true );
			$pw_key  = substr( $derived, 0, 16 );
			$uh      = self::b64e( substr( $derived, 16, 16 ) );
		} else {
			$pw_key = self::prepare_key_v1( $password );
			$uh     = self::user_hash_v1( $email, $pw_key );
		}
		$cmd = array( 'a' => 'us', 'user' => $email, 'uh' => $uh );
		if ( '' !== trim( (string) $mfa ) ) {
			$cmd['mfa'] = preg_replace( '/\D/', '', $mfa );
		}
		$res = $this->req( $cmd, '' );
		list( $sid, $master ) = self::session_from_login( $res, $pw_key );
		$this->set_secret( 'sid', $sid );
		$this->set_secret( 'master_key', self::b64e( $master ) );
		$this->set( array( 'email' => $email, 'folder_id' => '' ) );
	}

	/**
	 * Decode the login response: decrypt the master key with the password key, then the RSA
	 * private key with the master key, then the session ID with RSA.
	 *
	 * @return array [sid, master_key]
	 */
	public static function session_from_login( array $res, $pw_key ) {
		if ( empty( $res['k'] ) ) {
			throw new Lifeboat_Remote_Exception( 'MEGA: unexpected login response.' );
		}
		$master = self::ecb_dec( self::b64d( $res['k'] ), $pw_key );
		if ( ! empty( $res['csid'] ) ) {
			$privk = self::ecb_dec( self::pad16( self::b64d( $res['privk'] ) ), $master );
			list( $p, $q, $d ) = self::mpis( $privk, 4 );
			$c   = self::mpis( self::b64d( $res['csid'] ), 1 );
			$m   = self::modpow( $c[0], $d, self::mul( $p, $q ) );
			$sid = self::b64e( substr( $m, 0, 43 ) );
		} elseif ( ! empty( $res['tsid'] ) ) {
			$t = self::b64d( $res['tsid'] );
			if ( self::ecb_enc( substr( $t, 0, 16 ), $master ) !== substr( $t, -16 ) ) {
				throw new Lifeboat_Remote_Exception( 'MEGA: wrong password.' );
			}
			$sid = $res['tsid'];
		} else {
			throw new Lifeboat_Remote_Exception( 'MEGA: unexpected login response.' );
		}
		return array( $sid, $master );
	}

	private function master() {
		$m = self::b64d( $this->secret( 'master_key' ) );
		if ( 16 !== strlen( $m ) ) {
			throw new Lifeboat_Remote_Exception( 'MEGA: not connected.' );
		}
		return $m;
	}

	public function test() {
		$q = $this->req( array( 'a' => 'uq', 'strg' => 1, 'xfer' => 1 ) );
		$free = isset( $q['mstrg'] ) ? ' — ' . sprintf( __( '%s free', 'lifeboat-backup' ), Lifeboat_Util::bytes( (float) $q['mstrg'] - (float) $q['cstrg'] ) ) : '';
		return $this->get_value( 'email' ) . $free;
	}

	/** Find or create the backup folder at the top of the Cloud Drive. */
	private function folder() {
		$id = $this->get_value( 'folder_id' );
		if ( $id ) {
			return $id;
		}
		$name   = $this->folder_name() . ' (' . $this->site_folder() . ')';
		$master = $this->master();
		$tree   = $this->req( array( 'a' => 'f', 'c' => 1 ) );
		$root   = '';
		foreach ( (array) $tree['f'] as $n ) {
			if ( 2 === (int) $n['t'] ) {
				$root = $n['h'];
			}
		}
		foreach ( (array) $tree['f'] as $n ) {
			if ( 1 !== (int) $n['t'] || $n['p'] !== $root || empty( $n['k'] ) ) {
				continue;
			}
			$k = explode( ':', explode( '/', $n['k'] )[0] );
			if ( 2 !== count( $k ) ) {
				continue;
			}
			$key  = self::ecb_dec( self::b64d( $k[1] ), $master );
			$attr = self::dec_attr( $n['a'], $key );
			if ( $attr && isset( $attr['n'] ) && $attr['n'] === $name ) {
				$id = $n['h'];
				break;
			}
		}
		if ( ! $id ) {
			$key = random_bytes( 16 );
			$res = $this->req(
				array(
					'a' => 'p',
					't' => $root,
					'n' => array(
						array(
							'h' => 'xxxxxxxx',
							't' => 1,
							'a' => self::enc_attr( array( 'n' => $name ), $key ),
							'k' => self::b64e( self::ecb_enc( $key, $master ) ),
						),
					),
				)
			);
			$id  = $res['f'][0]['h'];
		}
		$this->set( array( 'folder_id' => $id ) );
		return $id;
	}

	/* ============================================================ upload */

	/** MEGA's MAC chunk boundaries: 128 KB, 256 KB … 1 MB, then 1 MB each. */
	public static function mac_chunk_len( $index ) {
		return $index < 8 ? 131072 * ( $index + 1 ) : 1048576;
	}

	public static function chunk_mac( $plain, $key, $nonce ) {
		$enc = openssl_encrypt( self::pad16( $plain ), 'aes-128-cbc', $key, OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING, $nonce . $nonce );
		return substr( $enc, -16 );
	}

	public static function ctr( $data, $key, $nonce, $offset ) {
		$iv = $nonce . pack( 'J', intdiv( $offset, 16 ) );
		return openssl_encrypt( $data, 'aes-128-ctr', $key, OPENSSL_RAW_DATA, $iv );
	}

	public static function meta_mac( array $macs, $key ) {
		$file_mac = str_repeat( "\0", 16 );
		foreach ( $macs as $mac ) {
			$file_mac = self::ecb_enc( $file_mac ^ $mac, $key );
		}
		$w = array_values( unpack( 'N4', $file_mac ) );
		return pack( 'N2', $w[0] ^ $w[1], $w[2] ^ $w[3] );
	}

	/** 32-byte node key: (key XOR [nonce, meta-MAC]) || nonce || meta-MAC. */
	public static function node_key( $key, $nonce, $meta ) {
		return ( $key ^ ( $nonce . $meta ) ) . $nonce . $meta;
	}

	public function upload_start( $local, $name, $size ) {
		$folder = $this->folder();
		$r      = $this->req( array( 'a' => 'u', 's' => $size ) );
		if ( empty( $r['p'] ) ) {
			throw new Lifeboat_Remote_Exception( 'MEGA did not return an upload URL.', true );
		}
		return array(
			'url'    => $r['p'],
			'offset' => 0,
			'ci'     => 0,
			'key'    => self::b64e( random_bytes( 16 ) ),
			'nonce'  => self::b64e( random_bytes( 8 ) ),
			'macs'   => array(),
			'name'   => $name,
			'folder' => $folder,
		);
	}

	public function upload_chunk( array &$s, $local, $size ) {
		$key   = self::b64d( $s['key'] );
		$nonce = self::b64d( $s['nonce'] );
		$start = (int) $s['offset'];
		$fh    = fopen( $local, 'rb' );
		fseek( $fh, $start );
		$enc    = '';
		$offset = $start;
		$macs   = array();
		while ( $offset < $size && strlen( $enc ) < $this->chunk_size() ) {
			$len    = min( self::mac_chunk_len( $s['ci'] ), $size - $offset );
			$plain  = (string) fread( $fh, $len );
			$macs[] = self::b64e( self::chunk_mac( $plain, $key, $nonce ) );
			$enc   .= self::ctr( $plain, $key, $nonce, $offset );
			$offset += strlen( $plain );
			$s['ci']++;
		}
		fclose( $fh );

		$r = $this->http(
			'POST',
			$s['url'] . '/' . $start,
			array(
				'headers' => array( 'Content-Type' => 'application/octet-stream' ),
				'body'    => $enc,
			)
		);
		$body = trim( $r['body'] );
		if ( 200 !== $r['code'] || preg_match( '/^-\d+$/', $body ) ) {
			$s['ci'] -= count( $macs );
			throw new Lifeboat_Remote_Exception( 'MEGA upload failed: ' . ( $body ? $body : 'HTTP ' . $r['code'] ), true, '-3' !== $body );
		}
		$s['macs']   = array_merge( $s['macs'], $macs );
		$s['offset'] = $offset;
		if ( $offset < $size ) {
			return false;
		}
		if ( '' === $body ) {
			throw new Lifeboat_Remote_Exception( 'MEGA did not confirm the upload.', true, true );
		}

		$meta = self::meta_mac( array_map( array( __CLASS__, 'b64d' ), $s['macs'] ), $key );
		$k32  = self::node_key( $key, $nonce, $meta );
		$res  = $this->req(
			array(
				'a' => 'p',
				't' => $s['folder'],
				'n' => array(
					array(
						'h' => $body,
						't' => 0,
						'a' => self::enc_attr( array( 'n' => $s['name'] ), $key ),
						'k' => self::b64e( self::ecb_enc( $k32, $this->master() ) ),
					),
				),
			)
		);
		// The file key is needed to download (decrypt) later; keep it encrypted in the history.
		$s['remote_id'] = $res['f'][0]['h'] . '|' . Lifeboat_Crypto::encrypt( self::b64e( $k32 ) );
		$s['macs']      = array();
		return true;
	}

	public function delete( $remote_id ) {
		$h = explode( '|', $remote_id )[0];
		try {
			$this->req( array( 'a' => 'd', 'n' => $h ) );
		} catch ( Lifeboat_Remote_Exception $e ) {
			if ( false === strpos( $e->getMessage(), 'not found' ) ) {
				throw $e;
			}
		}
	}

	protected function download_request( $remote_id ) {
		return array( '', array() );
	}

	public function download_chunk( array &$session, $remote_id, $local, $size ) {
		list( $h, $enc_key ) = array_pad( explode( '|', $remote_id, 2 ), 2, '' );
		$k32   = self::b64d( Lifeboat_Crypto::decrypt( $enc_key ) );
		if ( 32 !== strlen( $k32 ) ) {
			throw new Lifeboat_Remote_Exception( 'MEGA: missing file key for this backup.' );
		}
		$key   = substr( $k32, 0, 16 ) ^ substr( $k32, 16, 16 );
		$nonce = substr( $k32, 16, 8 );
		if ( empty( $session['url'] ) ) {
			$g                 = $this->req( array( 'a' => 'g', 'g' => 1, 'n' => $h ) );
			$session['url']    = $g['g'];
			$session['offset'] = isset( $session['offset'] ) ? $session['offset'] : 0;
			if ( 0 === (int) $session['offset'] ) {
				file_put_contents( $local, '' ); // phpcs:ignore
			}
		}
		$start = (int) $session['offset'];
		$end   = min( $size, $start + $this->chunk_size() ) - 1;
		$r     = $this->http( 'GET', $session['url'] . '/' . $start . '-' . $end );
		if ( 200 !== $r['code'] ) {
			$session['url'] = '';
			$this->fail( $r, __( 'Download', 'lifeboat-backup' ) );
		}
		file_put_contents( $local, self::ctr( $r['body'], $key, $nonce, $start ), FILE_APPEND ); // phpcs:ignore
		$session['offset'] = $start + strlen( $r['body'] );
		return $session['offset'] >= $size;
	}
}
