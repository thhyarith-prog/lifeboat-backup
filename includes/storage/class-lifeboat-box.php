<?php
/**
 * Box (simple upload below 20 MB, chunked upload sessions with SHA-1 digests above).
 *
 * @package Lifeboat_Backup
 */

defined( 'ABSPATH' ) || exit;

class Lifeboat_Box extends Lifeboat_OAuth_Destination {

	public $id = 'box';

	const API    = 'https://api.box.com/2.0';
	const UPLOAD = 'https://upload.box.com/api/2.0';
	const CHUNKED_MIN = 20971520; // Box requires >= 20 MB for upload sessions.

	public function relay_name() {
		return 'box';
	}

	public function label() {
		return 'Box';
	}

	protected function auth_endpoint() {
		return 'https://account.box.com/api/oauth2/authorize';
	}

	protected function token_endpoint() {
		return 'https://api.box.com/oauth2/token';
	}

	protected function scope() {
		return '';
	}

	public function console_url() {
		return 'https://app.box.com/developers/console';
	}

	public function setup_steps() {
		return array(
			__( 'In the Box Developer Console create a new app → Custom App → "User Authentication (OAuth 2.0)".', 'lifeboat-backup' ),
			__( 'On the Configuration tab add the redirect URI below and tick "Write all files and folders stored in Box".', 'lifeboat-backup' ),
			__( 'Paste the Client ID and Client Secret here, save, then click Connect.', 'lifeboat-backup' ),
		);
	}

	public function test() {
		$r    = $this->api( 'GET', self::API . '/users/me?fields=login,space_amount,space_used' );
		$j    = $r['json'];
		$free = isset( $j['space_amount'] ) && $j['space_amount'] > 0 ? ' — ' . sprintf( __( '%s free', 'lifeboat-backup' ), Lifeboat_Util::bytes( $j['space_amount'] - $j['space_used'] ) ) : '';
		return ( isset( $j['login'] ) ? $j['login'] : '' ) . $free;
	}

	private function find_or_create_folder( $name, $parent ) {
		$r = $this->api(
			'POST',
			self::API . '/folders?fields=id',
			array(
				'name'   => $name,
				'parent' => array( 'id' => (string) $parent ),
			),
			array(),
			array( 201, 409 )
		);
		if ( 201 === $r['code'] ) {
			return $r['json']['id'];
		}
		if ( isset( $r['json']['context_info']['conflicts'][0]['id'] ) ) {
			return $r['json']['context_info']['conflicts'][0]['id'];
		}
		$this->fail( $r, __( 'Create folder', 'lifeboat-backup' ) );
	}

	private function folder() {
		$id = $this->get_value( 'folder_id' );
		if ( ! $id ) {
			$top = $this->find_or_create_folder( $this->folder_name(), '0' );
			$id  = $this->find_or_create_folder( $this->site_folder(), $top );
			$this->set( array( 'folder_id' => $id ) );
		}
		return $id;
	}

	public function upload_start( $local, $name, $size ) {
		$folder = $this->folder();
		if ( $size < self::CHUNKED_MIN ) {
			return array(
				'mode'   => 'simple',
				'name'   => $name,
				'folder' => $folder,
				'offset' => 0,
			);
		}
		$r = $this->api(
			'POST',
			self::UPLOAD . '/files/upload_sessions',
			array(
				'folder_id' => (string) $folder,
				'file_size' => $size,
				'file_name' => $name,
			)
		);
		return array(
			'mode'      => 'chunked',
			'id'        => $r['json']['id'],
			'part_size' => (int) $r['json']['part_size'],
			'parts'     => array(),
			'offset'    => 0,
			'digest'    => base64_encode( sha1_file( $local, true ) ), // phpcs:ignore
			'name'      => $name,
			'folder'    => $folder,
		);
	}

	public function upload_chunk( array &$s, $local, $size ) {
		return 'simple' === $s['mode'] ? $this->upload_simple( $s, $local ) : $this->upload_part( $s, $local, $size );
	}

	private function upload_simple( array &$s, $local ) {
		$boundary = 'lifeboat' . wp_generate_password( 16, false );
		$attrs    = wp_json_encode(
			array(
				'name'   => $s['name'],
				'parent' => array( 'id' => (string) $s['folder'] ),
			)
		);
		$body     = "--{$boundary}\r\nContent-Disposition: form-data; name=\"attributes\"\r\n\r\n{$attrs}\r\n"
			. "--{$boundary}\r\nContent-Disposition: form-data; name=\"file\"; filename=\"" . str_replace( '"', '', $s['name'] ) . "\"\r\nContent-Type: application/octet-stream\r\n\r\n"
			. file_get_contents( $local ) . "\r\n--{$boundary}--\r\n"; // phpcs:ignore
		$r = $this->http(
			'POST',
			self::UPLOAD . '/files/content',
			array(
				'headers' => $this->auth( array( 'Content-Type' => 'multipart/form-data; boundary=' . $boundary ) ),
				'body'    => $body,
			)
		);
		if ( 201 === $r['code'] && isset( $r['json']['entries'][0]['id'] ) ) {
			$s['remote_id'] = $r['json']['entries'][0]['id'];
			return true;
		}
		// A retried request whose first attempt actually succeeded.
		if ( 409 === $r['code'] && isset( $r['json']['context_info']['conflicts']['id'] ) ) {
			$s['remote_id'] = $r['json']['context_info']['conflicts']['id'];
			return true;
		}
		$this->fail( $r, __( 'Upload', 'lifeboat-backup' ) );
	}

	private function upload_part( array &$s, $local, $size ) {
		if ( $s['offset'] < $size ) {
			$start = (int) $s['offset'];
			$data  = self::read_chunk( $local, $start, $s['part_size'] );
			$end   = $start + strlen( $data ) - 1;
			$r     = $this->http(
				'PUT',
				self::UPLOAD . '/files/upload_sessions/' . rawurlencode( $s['id'] ),
				array(
					'headers' => $this->auth(
						array(
							'Content-Type'  => 'application/octet-stream',
							'Digest'        => 'sha=' . base64_encode( sha1( $data, true ) ), // phpcs:ignore
							'Content-Range' => "bytes {$start}-{$end}/{$size}",
						)
					),
					'body'    => $data,
				)
			);
			if ( 200 !== $r['code'] || empty( $r['json']['part'] ) ) {
				$this->fail( $r, __( 'Upload part', 'lifeboat-backup' ) );
			}
			$s['parts'][] = $r['json']['part'];
			$s['offset']  = $end + 1;
			if ( $s['offset'] < $size ) {
				return false;
			}
		}
		$r = $this->http(
			'POST',
			self::UPLOAD . '/files/upload_sessions/' . rawurlencode( $s['id'] ) . '/commit',
			array(
				'headers' => $this->auth(
					array(
						'Content-Type' => 'application/json',
						'Digest'       => 'sha=' . $s['digest'],
					)
				),
				'body'    => wp_json_encode( array( 'parts' => $s['parts'] ) ),
			)
		);
		if ( 202 === $r['code'] ) {
			return false; // Box is still assembling the parts; try again shortly.
		}
		if ( 201 === $r['code'] && isset( $r['json']['entries'][0]['id'] ) ) {
			$s['remote_id'] = $r['json']['entries'][0]['id'];
			return true;
		}
		$this->fail( $r, __( 'Commit upload', 'lifeboat-backup' ) );
	}

	public function delete( $remote_id ) {
		$this->api( 'DELETE', self::API . '/files/' . rawurlencode( $remote_id ), null, array(), array( 204, 404 ) );
	}

	protected function download_request( $remote_id ) {
		return array( $this->url( self::API . '/files/' . rawurlencode( $remote_id ) . '/content' ), $this->auth() );
	}
}
