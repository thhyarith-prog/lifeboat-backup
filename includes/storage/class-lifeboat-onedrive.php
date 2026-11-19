<?php
/**
 * Microsoft OneDrive (personal and business) through Microsoft Graph upload sessions.
 *
 * @package Lifeboat_Backup
 */

defined( 'ABSPATH' ) || exit;

class Lifeboat_OneDrive extends Lifeboat_OAuth_Destination {

	public $id = 'onedrive';

	const GRAPH = 'https://graph.microsoft.com/v1.0';

	public function relay_name() {
		return 'microsoft';
	}

	public function label() {
		return 'OneDrive';
	}

	protected function auth_endpoint() {
		return 'https://login.microsoftonline.com/common/oauth2/v2.0/authorize';
	}

	protected function token_endpoint() {
		return 'https://login.microsoftonline.com/common/oauth2/v2.0/token';
	}

	protected function scope() {
		return 'offline_access Files.ReadWrite User.Read';
	}

	public function console_url() {
		return 'https://portal.azure.com/#view/Microsoft_AAD_RegisteredApps/ApplicationsListBlade';
	}

	public function setup_steps() {
		return array(
			__( 'Open the Azure portal → App registrations → New registration.', 'lifeboat-backup' ),
			__( 'Supported account types: "Accounts in any organizational directory and personal Microsoft accounts". Redirect URI: platform "Web" with the URI below.', 'lifeboat-backup' ),
			__( 'Under Certificates & secrets create a client secret (copy the Value, not the ID). Note its expiry date.', 'lifeboat-backup' ),
			__( 'Paste the Application (client) ID and the secret value here, save, then click Connect.', 'lifeboat-backup' ),
		);
	}

	/** Graph requires chunks in multiples of 320 KiB. */
	public function chunk_size() {
		return 327680 * 32; // 10 MiB.
	}

	public function test() {
		$r = $this->api( 'GET', self::GRAPH . '/me/drive?$select=owner,quota' );
		$j = $r['json'];
		$who  = isset( $j['owner']['user']['displayName'] ) ? $j['owner']['user']['displayName'] : '';
		$free = isset( $j['quota']['remaining'] ) ? ' — ' . sprintf( __( '%s free', 'lifeboat-backup' ), Lifeboat_Util::bytes( $j['quota']['remaining'] ) ) : '';
		return trim( $who . $free );
	}

	private function item_path( $name ) {
		$segments = array( $this->folder_name(), $this->site_folder(), $name );
		return implode( '/', array_map( 'rawurlencode', $segments ) );
	}

	public function upload_start( $local, $name, $size ) {
		$r = $this->api(
			'POST',
			self::GRAPH . '/me/drive/root:/' . $this->item_path( $name ) . ':/createUploadSession',
			array( 'item' => array( '@microsoft.graph.conflictBehavior' => 'replace' ) )
		);
		if ( empty( $r['json']['uploadUrl'] ) ) {
			throw new Lifeboat_Remote_Exception( 'OneDrive did not return an upload URL.', true );
		}
		return array(
			'url'    => $r['json']['uploadUrl'],
			'offset' => 0,
		);
	}

	public function upload_chunk( array &$s, $local, $size ) {
		$start = (int) $s['offset'];
		$data  = self::read_chunk( $local, $start, $this->chunk_size() );
		$end   = $start + strlen( $data ) - 1;
		// The upload URL is pre-authenticated: sending a token is an error.
		$r = $this->http(
			'PUT',
			$s['url'],
			array(
				'headers' => array( 'Content-Range' => "bytes {$start}-{$end}/{$size}" ),
				'body'    => $data,
			)
		);
		if ( 202 === $r['code'] ) {
			$next        = isset( $r['json']['nextExpectedRanges'][0] ) ? (int) $r['json']['nextExpectedRanges'][0] : $end + 1;
			$s['offset'] = $next;
			return false;
		}
		if ( 200 === $r['code'] || 201 === $r['code'] ) {
			$s['remote_id'] = $r['json']['id'];
			return true;
		}
		$this->fail( $r, __( 'Upload', 'lifeboat-backup' ) );
	}

	public function delete( $remote_id ) {
		$this->api( 'DELETE', self::GRAPH . '/me/drive/items/' . rawurlencode( $remote_id ), null, array(), array( 204, 404 ) );
	}

	protected function download_request( $remote_id ) {
		$r   = $this->api( 'GET', self::GRAPH . '/me/drive/items/' . rawurlencode( $remote_id ) . '?select=id,@microsoft.graph.downloadUrl' );
		$url = isset( $r['json']['@microsoft.graph.downloadUrl'] ) ? $r['json']['@microsoft.graph.downloadUrl'] : '';
		if ( ! $url ) {
			throw new Lifeboat_Remote_Exception( 'OneDrive did not return a download URL.' );
		}
		return array( $url, array() );
	}
}
