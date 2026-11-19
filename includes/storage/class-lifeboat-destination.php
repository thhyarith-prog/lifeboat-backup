<?php
/**
 * Base class for remote storage destinations.
 *
 * Every destination uploads and downloads in resumable chunks: the session array is
 * JSON-safe and saved between background slices, so a 5 GB upload survives PHP timeouts.
 *
 * @package Lifeboat_Backup
 */

defined( 'ABSPATH' ) || exit;

class Lifeboat_Remote_Exception extends RuntimeException {
	/** @var bool Worth retrying (network hiccup, rate limit, 5xx). */
	public $retry;

	/** @var bool The upload session is gone and must be restarted from zero. */
	public $restart;

	public function __construct( $message, $retry = false, $restart = false ) {
		parent::__construct( $message );
		$this->retry   = $retry;
		$this->restart = $restart;
	}
}

abstract class Lifeboat_Destination {

	/** @var string */
	public $id;

	const CHUNK = 8388608; // 8 MiB: a multiple of every provider's required granularity.

	/** @var array|null */
	private static $registry;

	public static function registry() {
		if ( null === self::$registry ) {
			self::$registry = array();
			foreach ( array( 'Lifeboat_GDrive', 'Lifeboat_OneDrive', 'Lifeboat_Dropbox', 'Lifeboat_Box', 'Lifeboat_Mega' ) as $class ) {
				$d                         = new $class();
				self::$registry[ $d->id ] = $d;
			}
		}
		return self::$registry;
	}

	/** @return Lifeboat_Destination|null */
	public static function get( $id ) {
		$r = self::registry();
		return isset( $r[ $id ] ) ? $r[ $id ] : null;
	}

	abstract public function label();

	abstract public function is_connected();

	/** @return string Account description, e.g. "you@example.com — 3.1 GB free". */
	abstract public function test();

	/** @return array Session. */
	abstract public function upload_start( $local, $name, $size );

	/** @return bool True when the upload is complete ($session['remote_id'] is then set). */
	abstract public function upload_chunk( array &$session, $local, $size );

	abstract public function delete( $remote_id );

	/** @return array [url, headers] for a ranged GET of the remote file. */
	abstract protected function download_request( $remote_id );

	/** Fields shown on the storage screen: key => [label, type, help]. */
	public function fields() {
		return array();
	}

	public function setup_steps() {
		return array();
	}

	public function chunk_size() {
		return self::CHUNK;
	}

	/* ---------------------------------------------------------- config */

	protected function option() {
		return 'lifeboat_dest_' . $this->id;
	}

	public function config() {
		$c = get_option( $this->option(), array() );
		return is_array( $c ) ? $c : array();
	}

	public function set( array $values ) {
		update_option( $this->option(), array_merge( $this->config(), $values ), false );
	}

	public function get_value( $key, $default = '' ) {
		$c = $this->config();
		return isset( $c[ $key ] ) ? $c[ $key ] : $default;
	}

	public function secret( $key ) {
		return Lifeboat_Crypto::decrypt( $this->get_value( $key ) );
	}

	public function set_secret( $key, $value ) {
		$this->set( array( $key => Lifeboat_Crypto::encrypt( $value ) ) );
	}

	public function disconnect() {
		delete_option( $this->option() );
	}

	/** Remote folder name for this site's backups. */
	public function folder_name() {
		return 'Lifeboat Backups';
	}

	public function site_folder() {
		return Lifeboat_Util::site_slug();
	}

	/* ------------------------------------------------------------ HTTP */

	protected function url( $url ) {
		return apply_filters( 'lifeboat_endpoint', $url, $this->id );
	}

	/**
	 * @return array code, body, headers, json
	 */
	protected function http( $method, $url, array $args = array() ) {
		$args = array_merge(
			array(
				'method'      => $method,
				'timeout'     => 120,
				'redirection' => 0,
				'user-agent'  => 'LifeboatBackup/' . LIFEBOAT_VERSION . '; WordPress',
				'headers'     => array(),
			),
			$args
		);
		$res = wp_remote_request( $this->url( $url ), $args );
		if ( is_wp_error( $res ) ) {
			throw new Lifeboat_Remote_Exception( $this->label() . ': ' . $res->get_error_message(), true );
		}
		$code = (int) wp_remote_retrieve_response_code( $res );
		$body = (string) wp_remote_retrieve_body( $res );
		$out  = array(
			'code'    => $code,
			'body'    => $body,
			'headers' => wp_remote_retrieve_headers( $res ),
			'json'    => json_decode( $body, true ),
		);
		return $out;
	}

	/** Throw a meaningful error for a failed response. */
	protected function fail( array $r, $context ) {
		$msg = '';
		if ( is_array( $r['json'] ) ) {
			$j   = $r['json'];
			$msg = isset( $j['error']['message'] ) ? $j['error']['message']
				: ( isset( $j['error_description'] ) ? $j['error_description']
				: ( isset( $j['error_summary'] ) ? $j['error_summary']
				: ( isset( $j['message'] ) ? $j['message']
				: ( isset( $j['error'] ) && is_string( $j['error'] ) ? $j['error'] : '' ) ) ) );
		}
		if ( '' === $msg ) {
			$msg = substr( wp_strip_all_tags( $r['body'] ), 0, 200 );
		}
		$retry = 429 === $r['code'] || $r['code'] >= 500;
		throw new Lifeboat_Remote_Exception( sprintf( '%s: %s failed (HTTP %d) %s', $this->label(), $context, $r['code'], $msg ), $retry, 404 === $r['code'] || 410 === $r['code'] );
	}

	protected static function read_chunk( $local, $offset, $length ) {
		$fh = fopen( $local, 'rb' );
		if ( ! $fh ) {
			throw new Lifeboat_Remote_Exception( 'Cannot read ' . basename( $local ) );
		}
		fseek( $fh, $offset );
		$data = (string) fread( $fh, $length );
		fclose( $fh );
		return $data;
	}

	/* --------------------------------------------------------- download */

	/**
	 * Download the next range of a remote file, appending to $local.
	 *
	 * @return bool True when complete.
	 */
	public function download_chunk( array &$session, $remote_id, $local, $size ) {
		if ( empty( $session['url'] ) ) {
			list( $session['url'], $session['headers'] ) = $this->download_request( $remote_id );
			$session['offset']                           = 0;
			file_put_contents( $local, '' ); // phpcs:ignore
		}
		$start = (int) $session['offset'];
		if ( $start >= $size ) {
			return true;
		}
		$end = min( $size, $start + $this->chunk_size() ) - 1;
		$r   = $this->http( 'GET', $session['url'], array( 'headers' => array_merge( (array) $session['headers'], array( 'Range' => "bytes={$start}-{$end}" ) ), 'redirection' => 5 ) );
		if ( 200 !== $r['code'] && 206 !== $r['code'] ) {
			$session['url'] = ''; // Signed URLs expire: ask for a fresh one next time.
			$this->fail( $r, 'Download' );
		}
		$body = 200 === $r['code'] && $start > 0 ? substr( $r['body'], $start, $end - $start + 1 ) : $r['body'];
		file_put_contents( $local, $body, FILE_APPEND ); // phpcs:ignore
		$session['offset'] = $start + strlen( $body );
		return $session['offset'] >= $size;
	}
}
