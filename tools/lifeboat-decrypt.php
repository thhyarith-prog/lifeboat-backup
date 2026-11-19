<?php
/**
 * Lifeboat Backup: decrypt an encrypted backup file without WordPress.
 *
 * Usage (from a terminal, PHP 7.4+ with OpenSSL):
 *   php lifeboat-decrypt.php uploads.tar.gz.enc               writes uploads.tar.gz
 *   php lifeboat-decrypt.php database.sql.gz.enc out.sql.gz   writes out.sql.gz
 *
 * The password is asked for interactively (or read from the LIFEBOAT_PASSWORD environment
 * variable). The result is a normal .tar.gz / .sql.gz file.
 *
 * @package Lifeboat_Backup
 */

if ( 'cli' !== PHP_SAPI ) {
	exit; // Never runs from a web request.
}

const LBX_HEADER = 44;
const LBX_TAG    = 16;

function lbx_fail( $msg ) {
	fwrite( STDERR, 'Error: ' . $msg . PHP_EOL );
	exit( 1 );
}

function lbx_password() {
	$env = getenv( 'LIFEBOAT_PASSWORD' );
	if ( false !== $env && '' !== $env ) {
		return $env;
	}
	fwrite( STDERR, 'Encryption password: ' );
	$tty = function_exists( 'posix_isatty' ) ? posix_isatty( STDIN ) : true;
	if ( $tty && '\\' !== DIRECTORY_SEPARATOR ) {
		shell_exec( 'stty -echo' );
	}
	$p = rtrim( (string) fgets( STDIN ), "\r\n" );
	if ( $tty && '\\' !== DIRECTORY_SEPARATOR ) {
		shell_exec( 'stty echo' );
	}
	fwrite( STDERR, PHP_EOL );
	return $p;
}

if ( $argc < 2 || in_array( $argv[1], array( '-h', '--help' ), true ) ) {
	fwrite( STDERR, "Usage: php lifeboat-decrypt.php <file.enc> [output]\n" );
	exit( 2 );
}
if ( ! function_exists( 'openssl_decrypt' ) || ! function_exists( 'hash_hkdf' ) ) {
	lbx_fail( 'PHP needs the OpenSSL extension (PHP 7.1.2 or newer).' );
}

$src = $argv[1];
$dst = isset( $argv[2] ) ? $argv[2] : preg_replace( '/\.enc$/', '', $src );
if ( $dst === $src ) {
	$dst = $src . '.decrypted';
}
if ( file_exists( $dst ) ) {
	lbx_fail( "$dst already exists; choose another output name." );
}

$in = @fopen( $src, 'rb' );
if ( ! $in ) {
	lbx_fail( "Cannot open $src" );
}
$header = fread( $in, LBX_HEADER );
if ( strlen( $header ) !== LBX_HEADER || 'LBX1' !== substr( $header, 0, 4 ) ) {
	lbx_fail( 'Not a Lifeboat encrypted file.' );
}
$iter  = unpack( 'N', substr( $header, 4, 4 ) )[1];
$salt  = substr( $header, 8, 16 );
$id    = substr( $header, 24, 16 );
$chunk = unpack( 'N', substr( $header, 40, 4 ) )[1];
if ( $chunk < 1024 || $chunk > 67108864 || $iter < 10000 || $iter > 10000000 ) {
	lbx_fail( 'The file header is damaged.' );
}

$key    = hash_pbkdf2( 'sha256', lbx_password(), $salt, $iter, 32, true );
$fkey   = hash_hkdf( 'sha256', $key, 32, 'lifeboat-file-v1', $id );
$body   = filesize( $src ) - LBX_HEADER;
$stride = $chunk + LBX_TAG;
$count  = max( 1, (int) ceil( $body / $stride ) );
$last   = $body - ( $count - 1 ) * $stride - LBX_TAG;
if ( $body < LBX_TAG || $last < 0 ) {
	lbx_fail( 'The file is incomplete.' );
}

$out = fopen( $dst, 'xb' );
if ( ! $out ) {
	lbx_fail( "Cannot create $dst" );
}
for ( $i = 0; $i < $count; $i++ ) {
	$is_last = $i === $count - 1;
	$len     = $is_last ? $last : $chunk;
	$raw     = fread( $in, $len + LBX_TAG );
	$nonce   = pack( 'NNN', (int) floor( $i / 4294967296 ), $i % 4294967296, $is_last ? 1 : 0 );
	$plain   = openssl_decrypt( substr( $raw, 0, $len ), 'aes-256-gcm', $fkey, OPENSSL_RAW_DATA, $nonce, substr( $raw, $len ), $header );
	if ( false === $plain ) {
		fclose( $out );
		unlink( $dst );
		lbx_fail( 0 === $i ? 'Wrong password (or the file is damaged).' : 'The file is damaged at chunk ' . $i . '.' );
	}
	fwrite( $out, $plain );
}
fclose( $out );
fclose( $in );
fwrite( STDERR, "Decrypted to $dst" . PHP_EOL );
