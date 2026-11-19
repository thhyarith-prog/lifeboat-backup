<?php
/**
 * Dropbox (upload sessions; works with "App folder" or "Full Dropbox" apps).
 *
 * @package Lifeboat_Backup
 */

defined( 'ABSPATH' ) || exit;

class Lifeboat_Dropbox extends Lifeboat_OAuth_Destination {

	public $id = 'dropbox';

	const API     = 'https://api.dropboxapi.com/2';
	const CONTENT = 'https://content.dropboxapi.com/2';

	public function relay_name() {
		return 'dropbox';
	}

	public function label() {
		return 'Dropbox';
	}

	protected function auth_endpoint() {
		return 'https://www.dropbox.com/oauth2/authorize';
	}

	protected function token_endpoint() {
		return 'https://api.dropboxapi.com/oauth2/token';
	}

	protected function scope() {
		return 'account_info.read files.metadata.read files.content.write files.content.read';
	}

	protected function extra_auth_params() {
		return array( 'token_access_type' => 'offline' );
	}

	public function console_url() {
		return 'https://www.dropbox.com/developers/apps/create';
	}

	public function setup_steps() {
		return array(
			__( 'Create an app in the Dropbox App Console: "Scoped access", and "App folder" (recommended) or "Full Dropbox".', 'lifeboat-backup' ),
			__( 'On the Permissions tab enable files.content.write, files.content.read, files.metadata.read and account_info.read, then Submit.', 'lifeboat-backup' ),
			__( 'On the Settings tab add the redirect URI below.', 'lifeboat-backup' ),
			__( 'Paste the App key (client ID) and App secret here, save, then click Connect.', 'lifeboat-backup' ),
		);
	}

	public function test() {
		$r     = $this->api( 'POST', self::API . '/users/get_current_account', 'null' );
		$email = isset( $r['json']['email'] ) ? $r['json']['email'] : '';
		$u     = $this->api( 'POST', self::API . '/users/get_space_usage', 'null' );
		$alloc = isset( $u['json']['allocation']['allocated'] ) ? (float) $u['json']['allocation']['allocated'] : 0;
		$free  = $alloc ? ' — ' . sprintf( __( '%s free', 'lifeboat-backup' ), Lifeboat_Util::bytes( $alloc - (float) $u['json']['used'] ) ) : '';
		return $email . $free;
	}

	private function path( $name ) {
		return '/' . $this->folder_name() . '/' . $this->site_folder() . '/' . $name;
	}

	/** Dropbox-API-Arg must be ASCII JSON (json_encode escapes non-ASCII by default). */
	private static function arg( array $a ) {
		return json_encode( $a, JSON_UNESCAPED_SLASHES ); // phpcs:ignore
	}

	private function content_call( $endpoint, array $arg, $data ) {
		return $this->http(
			'POST',
			self::CONTENT . $endpoint,
			array(
				'headers' => $this->auth(
					array(
						'Content-Type'     => 'application/octet-stream',
						'Dropbox-API-Arg'  => self::arg( $arg ),
					)
				),
				'body'    => $data,
			)
		);
	}

	public function upload_start( $local, $name, $size ) {
		return array(
			'path'       => $this->path( $name ),
			'session_id' => '',
			'offset'     => 0,
		);
	}

	public function upload_chunk( array &$s, $local, $size ) {
		$start = (int) $s['offset'];
		$data  = self::read_chunk( $local, $start, $this->chunk_size() );
		$last  = $start + strlen( $data ) >= $size;
		$commit = array(
			'path'       => $s['path'],
			'mode'       => 'overwrite',
			'autorename' => false,
			'mute'       => true,
		);

		if ( '' === $s['session_id'] && $last ) {
			$r = $this->content_call( '/files/upload', $commit, $data ); // Small file: single request.
		} elseif ( '' === $s['session_id'] ) {
			$r = $this->content_call( '/files/upload_session/start', array( 'close' => false ), $data );
			if ( 200 === $r['code'] ) {
				$s['session_id'] = $r['json']['session_id'];
				$s['offset']     = $start + strlen( $data );
				return false;
			}
		} else {
			$cursor = array(
				'session_id' => $s['session_id'],
				'offset'     => $start,
			);
			$r      = $last
				? $this->content_call( '/files/upload_session/finish', array( 'cursor' => $cursor, 'commit' => $commit ), $data )
				: $this->content_call( '/files/upload_session/append_v2', array( 'cursor' => $cursor, 'close' => false ), $data );
			if ( ! $last && 200 === $r['code'] ) {
				$s['offset'] = $start + strlen( $data );
				return false;
			}
		}

		if ( 200 === $r['code'] && isset( $r['json']['id'] ) ) {
			$s['remote_id'] = $r['json']['id'];
			return true;
		}
		// Dropbox tells us where it really is after a lost response.
		if ( 409 === $r['code'] && isset( $r['json']['error']['correct_offset'] ) ) {
			$s['offset'] = (int) $r['json']['error']['correct_offset'];
			return false;
		}
		if ( 409 === $r['code'] && isset( $r['json']['error_summary'] ) && false !== strpos( $r['json']['error_summary'], 'not_found' ) ) {
			throw new Lifeboat_Remote_Exception( 'Dropbox upload session expired.', true, true );
		}
		$this->fail( $r, __( 'Upload', 'lifeboat-backup' ) );
	}

	public function delete( $remote_id ) {
		$r = $this->http(
			'POST',
			self::API . '/files/delete_v2',
			array(
				'headers' => $this->auth( array( 'Content-Type' => 'application/json' ) ),
				'body'    => wp_json_encode( array( 'path' => $remote_id ) ),
			)
		);
		if ( 200 !== $r['code'] && ! ( 409 === $r['code'] && false !== strpos( (string) $r['body'], 'not_found' ) ) ) {
			$this->fail( $r, __( 'Delete', 'lifeboat-backup' ) );
		}
	}

	protected function download_request( $remote_id ) {
		return array(
			$this->url( self::CONTENT . '/files/download' ),
			$this->auth( array( 'Dropbox-API-Arg' => self::arg( array( 'path' => $remote_id ) ) ) ),
		);
	}

	/** Dropbox content downloads are POST requests. */
	public function download_chunk( array &$session, $remote_id, $local, $size ) {
		if ( ! isset( $session['offset'] ) ) {
			$session['offset'] = 0;
			file_put_contents( $local, '' ); // phpcs:ignore
		}
		$start = (int) $session['offset'];
		$end   = min( $size, $start + $this->chunk_size() ) - 1;
		list( $url, $headers ) = $this->download_request( $remote_id );
		$r = $this->http( 'POST', $url, array( 'headers' => array_merge( $headers, array( 'Range' => "bytes={$start}-{$end}" ) ) ) );
		if ( 200 !== $r['code'] && 206 !== $r['code'] ) {
			$this->fail( $r, __( 'Download', 'lifeboat-backup' ) );
		}
		$body = 200 === $r['code'] && $start > 0 ? substr( $r['body'], $start, $end - $start + 1 ) : $r['body'];
		file_put_contents( $local, $body, FILE_APPEND ); // phpcs:ignore
		$session['offset'] = $start + strlen( $body );
		return $session['offset'] >= $size;
	}
}
