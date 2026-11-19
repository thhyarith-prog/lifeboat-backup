<?php
/**
 * Resumable tar.gz archives.
 *
 * Archives are written as a sequence of independent gzip members (the gzip format allows
 * concatenation, so `tar -xzf`, 7-Zip, macOS Archive Utility etc. open them normally).
 * Each member is capped in size and its byte offset is recorded, so:
 *  - backups append, never rewrite (ZIP would rewrite the archive on every resume);
 *  - restores can resume at any member boundary without re-reading earlier data;
 *  - memory use stays flat regardless of site size.
 *
 * @package Lifeboat_Backup
 */

defined( 'ABSPATH' ) || exit;

class Lifeboat_Archive_Writer {

	const MEMBER_MAX = 33554432; // 32 MB uncompressed per gzip member.

	/** @var array Part state (by reference): file, members, raw. */
	private $part;

	/** @var resource|false */
	private $gz = false;

	private $level;

	private $member_bytes = 0;

	public function __construct( array &$part, $level ) {
		$this->part  =& $part;
		$this->level = max( 1, min( 9, (int) $level ) );
		if ( ! isset( $this->part['members'] ) ) {
			$this->part['members'] = array();
			$this->part['raw']     = 0;
		}
		$this->open_member();
	}

	private function open_member() {
		$file = $this->part['file'];
		clearstatcache( true, $file );
		$this->part['members'][] = is_file( $file ) ? filesize( $file ) : 0;
		$this->gz                = is_dir( dirname( $file ) ) ? @gzopen( $file, 'ab' . $this->level ) : false; // phpcs:ignore
		if ( ! $this->gz ) {
			throw new RuntimeException( 'Cannot write archive ' . basename( $file ) . ' (the backup folder is missing or not writable).' );
		}
		$this->member_bytes = 0;
	}

	public function write( $data ) {
		$len = strlen( $data );
		if ( 0 === $len ) {
			return;
		}
		if ( false === gzwrite( $this->gz, $data ) ) {
			throw new RuntimeException( 'Write failed (disk full?) for ' . basename( $this->part['file'] ) );
		}
		$this->member_bytes += $len;
		$this->part['raw']  += $len;
	}

	/** Start a new gzip member if the current one is large. Call only at a safe boundary. */
	public function maybe_rotate() {
		if ( $this->member_bytes >= self::MEMBER_MAX ) {
			gzclose( $this->gz );
			$this->open_member();
		}
	}

	public function close() {
		if ( $this->gz ) {
			gzclose( $this->gz );
			$this->gz = false;
		}
		clearstatcache( true, $this->part['file'] );
		$this->part['size'] = is_file( $this->part['file'] ) ? filesize( $this->part['file'] ) : 0;
	}

	/* ------------------------------------------------------------------ tar */

	public function tar_header( $name, $size, $mtime, $type = '0', $mode = 0644 ) {
		$name = ltrim( str_replace( '\\', '/', $name ), '/' );
		if ( strlen( $name ) > 100 ) {
			// GNU long name: a pseudo-entry carrying the full path.
			$this->write( self::raw_header( '././@LongLink', strlen( $name ) + 1, 0, 'L', 0644 ) );
			$this->write( $name . "\0" . str_repeat( "\0", self::pad( strlen( $name ) + 1 ) ) );
			$name = substr( $name, 0, 100 );
		}
		$this->write( self::raw_header( $name, $size, $mtime, $type, $mode ) );
	}

	public function tar_pad( $size ) {
		$p = self::pad( $size );
		if ( $p ) {
			$this->write( str_repeat( "\0", $p ) );
		}
	}

	public function tar_end() {
		$this->write( str_repeat( "\0", 1024 ) );
	}

	public static function pad( $size ) {
		return ( 512 - ( $size % 512 ) ) % 512;
	}

	private static function raw_header( $name, $size, $mtime, $type, $mode ) {
		$h  = str_pad( substr( $name, 0, 100 ), 100, "\0" );
		$h .= sprintf( '%07o', $mode ) . "\0";
		$h .= sprintf( '%07o', 0 ) . "\0";
		$h .= sprintf( '%07o', 0 ) . "\0";
		$h .= sprintf( '%011o', $size ) . "\0";
		$h .= sprintf( '%011o', max( 0, (int) $mtime ) ) . "\0";
		$h .= '        ';
		$h .= $type;
		$h .= str_repeat( "\0", 100 );
		$h .= "ustar\0" . '00';
		$h .= str_repeat( "\0", 32 + 32 + 8 + 8 + 155 + 12 );
		$sum = 0;
		for ( $i = 0; $i < 512; $i++ ) {
			$sum += ord( $h[ $i ] );
		}
		return substr_replace( $h, sprintf( '%06o', $sum ) . "\0 ", 148, 8 );
	}
}

/**
 * Reads one gzip member of an archive part and streams its decompressed bytes to a callback.
 */
class Lifeboat_Archive_Reader {

	public static function member_range( array $part, $index ) {
		$start = (int) $part['members'][ $index ];
		$total = isset( $part['plain_size'] ) ? (int) $part['plain_size'] : (int) $part['size']; // Offsets are in the unencrypted archive.
		$end   = isset( $part['members'][ $index + 1 ] ) ? (int) $part['members'][ $index + 1 ] : $total;
		return array( $start, $end );
	}

	/**
	 * Inflate one gzip member and pass the output to $sink.
	 *
	 * @param string $key Decryption key when the part is encrypted (see Lifeboat_Vault).
	 */
	public static function read_member( $file, $start, $end, callable $sink, $key = '' ) {
		if ( '' !== (string) $key ) {
			$r   = new Lifeboat_Vault_Reader( $file, $key );
			$ctx = inflate_init( ZLIB_ENCODING_GZIP );
			$pos = $start;
			try {
				while ( $pos < $end ) {
					$chunk = $r->read( $pos, min( 1048576, $end - $pos ) );
					if ( '' === $chunk ) {
						break;
					}
					$pos += strlen( $chunk );
					$out  = inflate_add( $ctx, $chunk, ZLIB_SYNC_FLUSH );
					if ( false === $out ) {
						throw new RuntimeException( 'Archive is corrupted: ' . basename( $file ) );
					}
					if ( '' !== $out ) {
						$sink( $out );
					}
				}
			} finally {
				$r->close();
			}
			return;
		}
		$fh = fopen( $file, 'rb' );
		if ( ! $fh ) {
			throw new RuntimeException( 'Cannot read ' . basename( $file ) );
		}
		fseek( $fh, $start );
		$ctx  = inflate_init( ZLIB_ENCODING_GZIP );
		$left = $end - $start;
		while ( $left > 0 && ! feof( $fh ) ) {
			$chunk = fread( $fh, min( 1048576, $left ) );
			if ( false === $chunk || '' === $chunk ) {
				break;
			}
			$left -= strlen( $chunk );
			$out   = inflate_add( $ctx, $chunk, ZLIB_SYNC_FLUSH );
			if ( false === $out ) {
				fclose( $fh );
				throw new RuntimeException( 'Archive is corrupted: ' . basename( $file ) );
			}
			if ( '' !== $out ) {
				$sink( $out );
			}
		}
		fclose( $fh );
	}
}

/**
 * Stateful tar extractor: survives being fed data across many requests.
 */
class Lifeboat_Tar_Extractor {

	/** @var array */
	private $st;

	private $root;

	/** @var callable|null fn(string $rel): bool — return false to skip a path. */
	private $filter;

	public $files = 0;

	public function __construct( array &$state, $root, $filter = null ) {
		$this->st     =& $state;
		$this->root   = rtrim( wp_normalize_path( $root ), '/' );
		$this->filter = $filter;
		$this->st    += array(
			'buf'   => '',
			'entry' => null,
			'long'  => '',
			'done'  => false,
		);
		if ( isset( $this->st['buf'] ) && is_string( $this->st['buf'] ) && 0 === strpos( $this->st['buf'], 'b64:' ) ) {
			$this->st['buf'] = base64_decode( substr( $this->st['buf'], 4 ) ); // phpcs:ignore
		}
	}

	/** Make state JSON-safe (pending header bytes are binary). */
	public function finish_slice() {
		if ( '' !== $this->st['buf'] ) {
			$this->st['buf'] = 'b64:' . base64_encode( $this->st['buf'] ); // phpcs:ignore
		}
	}

	public function feed( $data ) {
		$pos = 0;
		$len = strlen( $data );
		while ( $pos < $len && ! $this->st['done'] ) {
			$e =& $this->st['entry'];
			if ( $e ) {
				if ( $e['left'] > 0 ) {
					$take = min( $e['left'], $len - $pos );
					$this->write_entry( $e, substr( $data, $pos, $take ) );
					$e['left'] -= $take;
					$pos       += $take;
					continue;
				}
				if ( $e['pad'] > 0 ) {
					$take      = min( $e['pad'], $len - $pos );
					$e['pad'] -= $take;
					$pos      += $take;
					continue;
				}
				$this->end_entry( $e );
				$this->st['entry'] = null;
				unset( $e );
				continue;
			}
			unset( $e );
			$need             = 512 - strlen( $this->st['buf'] );
			$this->st['buf'] .= substr( $data, $pos, $need );
			$pos             += min( $need, $len - $pos );
			if ( strlen( $this->st['buf'] ) < 512 ) {
				break;
			}
			$header          = $this->st['buf'];
			$this->st['buf'] = '';
			$this->start_entry( $header );
		}
		// An entry that is complete (no data/pad left) is closed at the end of this call.
		if ( $this->st['entry'] && 0 === $this->st['entry']['left'] && 0 === $this->st['entry']['pad'] ) {
			$this->end_entry( $this->st['entry'] );
			$this->st['entry'] = null;
		}
	}

	private function start_entry( $h ) {
		if ( trim( $h, "\0" ) === '' ) {
			$this->st['done'] = true; // End-of-archive block.
			return;
		}
		$name   = rtrim( substr( $h, 0, 100 ), "\0" );
		$size   = octdec( trim( substr( $h, 124, 12 ), "\0 " ) );
		$mtime  = octdec( trim( substr( $h, 136, 12 ), "\0 " ) );
		$type   = $h[156];
		$prefix = rtrim( substr( $h, 345, 155 ), "\0" );
		if ( '' !== $prefix && 'ustar' === substr( $h, 257, 5 ) ) {
			$name = $prefix . '/' . $name;
		}
		if ( 'L' === $type ) {
			$this->st['entry'] = array( 'kind' => 'long', 'left' => $size, 'pad' => Lifeboat_Archive_Writer::pad( $size ), 'path' => '', 'mtime' => 0 );
			$this->st['long']  = '';
			return;
		}
		if ( '' !== $this->st['long'] ) {
			$name             = $this->st['long'];
			$this->st['long'] = '';
		}
		$path = $this->safe_path( $name );
		if ( '5' === $type ) {
			if ( $path ) {
				wp_mkdir_p( $path );
			}
			return;
		}
		$kind = ( '0' === $type || "\0" === $type ) && $path ? 'file' : 'skip';
		if ( 'file' === $kind ) {
			wp_mkdir_p( dirname( $path ) );
			$fh = @fopen( $path, 'wb' ); // phpcs:ignore
			if ( $fh ) {
				fclose( $fh );
			} else {
				$kind = 'skip';
			}
		}
		$this->st['entry'] = array( 'kind' => $kind, 'left' => $size, 'pad' => Lifeboat_Archive_Writer::pad( $size ), 'path' => $path, 'mtime' => $mtime );
	}

	private function write_entry( array $e, $bytes ) {
		if ( 'long' === $e['kind'] ) {
			$this->st['long'] .= $bytes;
		} elseif ( 'file' === $e['kind'] ) {
			file_put_contents( $e['path'], $bytes, FILE_APPEND ); // phpcs:ignore
		}
	}

	private function end_entry( array $e ) {
		if ( 'long' === $e['kind'] ) {
			$this->st['long'] = rtrim( $this->st['long'], "\0" );
		} elseif ( 'file' === $e['kind'] ) {
			if ( $e['mtime'] ) {
				@touch( $e['path'], $e['mtime'] ); // phpcs:ignore
			}
			$this->files++;
		}
	}

	/** Resolve an archive path inside the restore root; null for unsafe or filtered paths. */
	private function safe_path( $name ) {
		$name = ltrim( str_replace( '\\', '/', $name ), '/' );
		if ( '' === $name || preg_match( '~(^|/)\.\.(/|$)~', $name ) || preg_match( '~^[a-z]:~i', $name ) ) {
			return null;
		}
		if ( $this->filter && ! call_user_func( $this->filter, $name ) ) {
			return null;
		}
		return $this->root . '/' . $name;
	}
}
