<?php
/**
 * Google Drive (resumable uploads, drive.file scope: Lifeboat only sees files it created).
 *
 * @package Lifeboat_Backup
 */

defined( 'ABSPATH' ) || exit;

class Lifeboat_GDrive extends Lifeboat_OAuth_Destination {

	public $id = 'gdrive';

	const API    = 'https://www.googleapis.com/drive/v3';
	const UPLOAD = 'https://www.googleapis.com/upload/drive/v3/files';

	public function relay_name() {
		return 'google';
	}

	public function label() {
		return 'Google Drive';
	}

	protected function auth_endpoint() {
		return 'https://accounts.google.com/o/oauth2/v2/auth';
	}

	protected function token_endpoint() {
		return 'https://oauth2.googleapis.com/token';
	}

	protected function scope() {
		return 'https://www.googleapis.com/auth/drive.file';
	}

	protected function extra_auth_params() {
		return array(
			'access_type' => 'offline',
			'prompt'      => 'consent',
		);
	}

	public function console_url() {
		return 'https://console.cloud.google.com/apis/credentials';
	}

	public function setup_steps() {
		return array(
			__( 'Open Google Cloud Console, create a project, and enable the "Google Drive API".', 'lifeboat-backup' ),
			__( 'Configure the OAuth consent screen (External), add yourself as a test user, then publish it so tokens do not expire after 7 days.', 'lifeboat-backup' ),
			__( 'Create Credentials → OAuth client ID → Web application, and add the redirect URI below.', 'lifeboat-backup' ),
			__( 'Paste the client ID and secret here, save, then click Connect.', 'lifeboat-backup' ),
		);
	}

	public function test() {
		$r = $this->api( 'GET', self::API . '/about?fields=user(emailAddress),storageQuota' );
		$j = $r['json'];
		$free = isset( $j['storageQuota']['limit'] ) ? (float) $j['storageQuota']['limit'] - (float) $j['storageQuota']['usage'] : null;
		return trim( ( isset( $j['user']['emailAddress'] ) ? $j['user']['emailAddress'] : '' ) . ( null !== $free ? ' — ' . sprintf( __( '%s free', 'lifeboat-backup' ), Lifeboat_Util::bytes( $free ) ) : '' ) );
	}

	private function find_or_create_folder( $name, $parent ) {
		$q = sprintf( "name = '%s' and mimeType = 'application/vnd.google-apps.folder' and trashed = false and '%s' in parents", str_replace( array( '\\', "'" ), array( '\\\\', "\\'" ), $name ), $parent );
		$r = $this->api( 'GET', self::API . '/files?' . http_build_query( array( 'q' => $q, 'fields' => 'files(id)', 'spaces' => 'drive' ) ) );
		if ( ! empty( $r['json']['files'][0]['id'] ) ) {
			return $r['json']['files'][0]['id'];
		}
		$r = $this->api(
			'POST',
			self::API . '/files?fields=id',
			array(
				'name'     => $name,
				'mimeType' => 'application/vnd.google-apps.folder',
				'parents'  => array( $parent ),
			)
		);
		return $r['json']['id'];
	}

	private function folder() {
		$id = $this->get_value( 'folder_id' );
		if ( ! $id ) {
			$top = $this->find_or_create_folder( $this->folder_name(), 'root' );
			$id  = $this->find_or_create_folder( $this->site_folder(), $top );
			$this->set( array( 'folder_id' => $id ) );
		}
		return $id;
	}

	public function upload_start( $local, $name, $size ) {
		$r = $this->api(
			'POST',
			self::UPLOAD . '?uploadType=resumable&fields=id',
			array(
				'name'    => $name,
				'parents' => array( $this->folder() ),
			),
			array(
				'Content-Type'            => 'application/json; charset=UTF-8',
				'X-Upload-Content-Type'   => 'application/octet-stream',
				'X-Upload-Content-Length' => (string) $size,
			)
		);
		$url = isset( $r['headers']['location'] ) ? $r['headers']['location'] : '';
		if ( ! $url ) {
			throw new Lifeboat_Remote_Exception( 'Google Drive did not return an upload URL.', true );
		}
		return array(
			'url'    => $url,
			'offset' => 0,
		);
	}

	public function upload_chunk( array &$s, $local, $size ) {
		$start = (int) $s['offset'];
		$data  = self::read_chunk( $local, $start, $this->chunk_size() );
		$end   = $start + strlen( $data ) - 1;
		$r     = $this->http(
			'PUT',
			$s['url'],
			array(
				'headers' => $this->auth(
					array(
						'Content-Type'  => 'application/octet-stream',
						'Content-Range' => $size > 0 ? "bytes {$start}-{$end}/{$size}" : 'bytes */0',
					)
				),
				'body'    => $data,
			)
		);
		if ( 308 === $r['code'] ) {
			$range       = isset( $r['headers']['range'] ) ? $r['headers']['range'] : '';
			$s['offset'] = preg_match( '/bytes=0-(\d+)/', $range, $m ) ? (int) $m[1] + 1 : 0;
			return false;
		}
		if ( 200 === $r['code'] || 201 === $r['code'] ) {
			$s['remote_id'] = $r['json']['id'];
			return true;
		}
		$this->fail( $r, __( 'Upload', 'lifeboat-backup' ) );
	}

	public function delete( $remote_id ) {
		$this->api( 'DELETE', self::API . '/files/' . rawurlencode( $remote_id ), null, array(), array( 200, 204, 404 ) );
	}

	protected function download_request( $remote_id ) {
		return array( $this->url( self::API . '/files/' . rawurlencode( $remote_id ) . '?alt=media' ), $this->auth() );
	}
}
