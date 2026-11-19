<?php
/**
 * Shared OAuth 2.0 authorization-code flow (Google, Microsoft, Dropbox, Box).
 *
 * Two ways to connect:
 *  - Relay (default when a relay address is configured): one click. The publisher's sign-in
 *    relay holds the single registered app; the site gets its tokens through an encrypted,
 *    verifier-protected hand-off and renews them through the relay. No setup per site.
 *  - Own app (advanced): the site owner registers an app and pastes its client ID and secret.
 *    The redirect URI is wp-admin/admin-post.php with no query string, which every provider
 *    accepts (Microsoft rejects query strings for personal accounts).
 *
 * @package Lifeboat_Backup
 */

defined( 'ABSPATH' ) || exit;

abstract class Lifeboat_OAuth_Destination extends Lifeboat_Destination {

	abstract protected function auth_endpoint();

	abstract protected function token_endpoint();

	abstract protected function scope();

	protected function extra_auth_params() {
		return array();
	}

	public function console_url() {
		return '';
	}

	public static function redirect_uri() {
		return admin_url( 'admin-post.php' );
	}

	public function fields() {
		return array(
			'client_id'     => array( __( 'Client ID', 'lifeboat-backup' ), 'text' ),
			'client_secret' => array( __( 'Client secret', 'lifeboat-backup' ), 'password' ),
		);
	}

	public function disconnect() {
		parent::disconnect();
		@unlink( $this->journal_file() ); // phpcs:ignore
	}

	public function has_app() {
		return '' !== $this->get_value( 'client_id' ) && '' !== $this->secret( 'client_secret' );
	}

	public function is_connected() {
		return '' !== $this->secret( 'refresh_token' );
	}

	/* ------------------------------------------------------------- relay */

	/** Provider name used by the relay. */
	abstract public function relay_name();

	/** Relay address: a per-site setting, else the publisher default (LIFEBOAT_RELAY_URL). */
	public static function relay_base() {
		$url = (string) Lifeboat_Settings::get( 'relay_url' );
		if ( '' === $url && defined( 'LIFEBOAT_RELAY_URL' ) ) {
			$url = (string) LIFEBOAT_RELAY_URL;
		}
		return untrailingslashit( (string) apply_filters( 'lifeboat_relay_url', $url ) );
	}

	/** Providers the relay has an app for (cached; assumed available if the relay is unreachable). */
	public static function relay_providers() {
		$base = self::relay_base();
		if ( '' === $base ) {
			return array();
		}
		$key    = 'lifeboat_relay_' . md5( $base );
		$cached = get_transient( $key );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		$res  = wp_remote_get( $base . '/', array( 'timeout' => 8 ) );
		$data = is_wp_error( $res ) ? null : json_decode( wp_remote_retrieve_body( $res ), true );
		if ( is_array( $data ) && isset( $data['providers'] ) ) {
			set_transient( $key, (array) $data['providers'], 12 * HOUR_IN_SECONDS );
			return (array) $data['providers'];
		}
		$all = array( 'google', 'microsoft', 'dropbox', 'box' );
		set_transient( $key, $all, 5 * MINUTE_IN_SECONDS );
		return $all;
	}

	/** True when this destination can connect with one click through the relay. */
	public function relay_available() {
		return in_array( $this->relay_name(), self::relay_providers(), true );
	}

	public function via_relay() {
		return 'relay' === $this->get_value( 'via' );
	}

	private static function b64url( $bin ) {
		return rtrim( strtr( base64_encode( $bin ), '+/', '-_' ), '=' ); // phpcs:ignore
	}

	private function relay_pending_key() {
		return 'lifeboat_relay_pending_' . $this->id . '_' . get_current_user_id();
	}

	/** Start a one-click connection through the relay. */
	public function relay_auth_url() {
		$base     = self::relay_base();
		$verifier = self::b64url( random_bytes( 32 ) );
		set_transient( $this->relay_pending_key(), array( 'verifier' => $verifier, 'relay' => $base ), 20 * MINUTE_IN_SECONDS );
		$args = array(
			'return'    => self::redirect_uri(),
			'state'     => 'lifeboat.' . $this->id . '.' . wp_create_nonce( 'lifeboat_oauth_' . $this->id ),
			'challenge' => self::b64url( hash( 'sha256', $verifier, true ) ),
		);
		return $base . '/' . $this->relay_name() . '/start?' . http_build_query( $args, '', '&', PHP_QUERY_RFC3986 );
	}

	/** Collect the tokens the relay sealed for us, using the verifier only this server knows. */
	public function handle_relay_callback( $blob ) {
		$pending = get_transient( $this->relay_pending_key() );
		if ( ! is_array( $pending ) || empty( $pending['verifier'] ) ) {
			throw new Lifeboat_Remote_Exception( $this->label() . ': ' . __( 'the sign-in took too long. Please click Connect again.', 'lifeboat-backup' ) );
		}
		$r = $this->http(
			'POST',
			$pending['relay'] . '/' . $this->relay_name() . '/claim',
			array(
				'timeout' => 30,
				'headers' => array( 'Content-Type' => 'application/json' ),
				'body'    => wp_json_encode( array( 'blob' => $blob, 'verifier' => $pending['verifier'] ) ),
			)
		);
		delete_transient( $this->relay_pending_key() );
		if ( 200 !== $r['code'] || empty( $r['json']['access_token'] ) ) {
			$this->fail( $r, __( 'Sign-in', 'lifeboat-backup' ) );
		}
		if ( empty( $r['json']['refresh_token'] ) ) {
			throw new Lifeboat_Remote_Exception( $this->label() . ': ' . __( 'no long-term access was granted, so backups could not run unattended. Remove Lifeboat\'s access in your account settings and connect again.', 'lifeboat-backup' ) );
		}
		$this->disconnect(); // Drop any previous own-app setup for this destination.
		$this->store_tokens( $r['json'] );
		$this->set(
			array(
				'via'       => 'relay',
				'relay'     => $pending['relay'],
				'folder_id' => '',
			)
		);
	}

	public function auth_url() {
		$state = 'lifeboat.' . $this->id . '.' . wp_create_nonce( 'lifeboat_oauth_' . $this->id );
		$args  = array_merge(
			array(
				'client_id'     => $this->get_value( 'client_id' ),
				'redirect_uri'  => self::redirect_uri(),
				'response_type' => 'code',
				'state'         => $state,
			),
			$this->scope() ? array( 'scope' => $this->scope() ) : array(),
			$this->extra_auth_params()
		);
		return $this->url( $this->auth_endpoint() ) . '?' . http_build_query( $args, '', '&', PHP_QUERY_RFC3986 );
	}

	public function handle_callback( $code ) {
		$tokens = $this->token_request(
			array(
				'grant_type'   => 'authorization_code',
				'code'         => $code,
				'redirect_uri' => self::redirect_uri(),
			)
		);
		if ( empty( $tokens['refresh_token'] ) ) {
			throw new Lifeboat_Remote_Exception( $this->label() . ': ' . __( 'the provider did not return a refresh token, so backups could not run unattended. Remove the app\'s access in your account settings and connect again.', 'lifeboat-backup' ) );
		}
		$this->store_tokens( $tokens );
		$this->set( array( 'folder_id' => '' ) );
	}

	private function token_request( array $params ) {
		if ( $this->via_relay() && 'refresh_token' === $params['grant_type'] ) {
			// The relay adds the app secret, which this site never sees.
			$r = $this->http(
				'POST',
				untrailingslashit( $this->get_value( 'relay' ) ) . '/' . $this->relay_name() . '/refresh',
				array(
					'timeout' => 30,
					'headers' => array( 'Content-Type' => 'application/json' ),
					'body'    => wp_json_encode( array( 'refresh_token' => $params['refresh_token'] ) ),
				)
			);
			if ( 200 !== $r['code'] || empty( $r['json']['access_token'] ) ) {
				$this->fail( $r, __( 'Authorization', 'lifeboat-backup' ) );
			}
			return $r['json'];
		}
		$r = $this->http(
			'POST',
			$this->token_endpoint(),
			array(
				'timeout' => 30,
				'body'    => array_merge(
					$params,
					array(
						'client_id'     => $this->get_value( 'client_id' ),
						'client_secret' => $this->secret( 'client_secret' ),
					)
				),
			)
		);
		if ( 200 !== $r['code'] || empty( $r['json']['access_token'] ) ) {
			$this->fail( $r, __( 'Authorization', 'lifeboat-backup' ) );
		}
		return $r['json'];
	}

	/** Save all token fields in one write, so no other process can see a half-updated set. */
	private function store_tokens( array $t ) {
		$values = array(
			'access_token' => Lifeboat_Crypto::encrypt( $t['access_token'] ),
			'expires'      => time() + ( isset( $t['expires_in'] ) ? (int) $t['expires_in'] : 3600 ),
		);
		if ( ! empty( $t['refresh_token'] ) ) {
			$values['refresh_token'] = Lifeboat_Crypto::encrypt( $t['refresh_token'] ); // Box and Microsoft rotate it.
			// Journal the newest refresh token in a file too: if the database write is ever lost,
			// a rotated token would otherwise be gone for good and the account disconnected.
			Lifeboat_Util::write_json( $this->journal_file(), array( 'refresh_token' => $values['refresh_token'], 'time' => time() ) );
		}
		$this->set( $values );
	}

	private function journal_file() {
		return Lifeboat_Util::backup_dir() . '/.token-' . $this->id . '.json';
	}

	/** Newest refresh token from the journal, if it differs from $known. */
	private function journal_token( $known ) {
		$j  = Lifeboat_Util::read_json( $this->journal_file() );
		$rt = $j && ! empty( $j['refresh_token'] ) ? Lifeboat_Crypto::decrypt( $j['refresh_token'] ) : '';
		return '' !== $rt && $rt !== $known ? $rt : '';
	}

	/** Re-read this destination's settings from the database, bypassing the per-request cache. */
	private function reload() {
		wp_cache_delete( $this->option(), 'options' );
		wp_cache_delete( 'alloptions', 'options' );
		wp_cache_delete( 'notoptions', 'options' );
	}

	private function token_valid() {
		return (int) $this->get_value( 'expires', 0 ) > time() + 60 && '' !== $this->secret( 'access_token' );
	}

	/**
	 * Return a valid access token, refreshing it if needed.
	 *
	 * Concurrency-safe: providers that rotate refresh tokens (Box, Microsoft) revoke the old one
	 * on first use, so two PHP processes refreshing at once would permanently disconnect the
	 * account. Refreshes are serialised with a file lock, and the token is re-read from the
	 * database after taking the lock in case another process already refreshed it.
	 */
	protected function access_token() {
		if ( $this->token_valid() ) {
			return $this->secret( 'access_token' );
		}
		$fh = @fopen( Lifeboat_Util::backup_dir() . '/.refresh-' . $this->id . '.lock', 'c' ); // phpcs:ignore
		if ( $fh ) {
			flock( $fh, LOCK_EX );
		}
		try {
			$this->reload();
			if ( $this->token_valid() ) {
				return $this->secret( 'access_token' ); // Another process refreshed it while we waited.
			}
			$tried = array();
			$e     = null;
			for ( $attempt = 0; $attempt < 3; $attempt++ ) {
				$refresh = $this->secret( 'refresh_token' );
				if ( in_array( $refresh, $tried, true ) ) {
					$refresh = $this->journal_token( $refresh );
				}
				if ( '' === $refresh || in_array( $refresh, $tried, true ) ) {
					break;
				}
				$tried[] = $refresh;
				try {
					$this->store_tokens(
						$this->token_request(
							array(
								'grant_type'    => 'refresh_token',
								'refresh_token' => $refresh,
							)
						)
					);
					return $this->secret( 'access_token' );
				} catch ( Lifeboat_Remote_Exception $ex ) {
					$e = $ex;
					// A newer token may have been stored meanwhile (database or journal): retry with it.
					$this->reload();
					if ( $this->token_valid() ) {
						return $this->secret( 'access_token' );
					}
				}
			}
			if ( '' === $this->secret( 'refresh_token' ) ) {
				throw new Lifeboat_Remote_Exception( $this->label() . ': ' . __( 'not connected.', 'lifeboat-backup' ) );
			}
			throw $e ? $e : new Lifeboat_Remote_Exception( $this->label() . ': ' . __( 'could not refresh access.', 'lifeboat-backup' ), true );
		} finally {
			if ( $fh ) {
				flock( $fh, LOCK_UN );
				fclose( $fh );
			}
		}
	}

	protected function auth( array $headers = array() ) {
		return array_merge( array( 'Authorization' => 'Bearer ' . $this->access_token() ), $headers );
	}

	/** JSON API call with authorization. */
	protected function api( $method, $url, $body = null, array $headers = array(), $ok = array( 200, 201, 204 ) ) {
		$args = array( 'headers' => $this->auth( $headers ) );
		if ( null !== $body ) {
			$args['body']                    = is_string( $body ) ? $body : wp_json_encode( $body );
			$args['headers']['Content-Type'] = isset( $headers['Content-Type'] ) ? $headers['Content-Type'] : 'application/json';
		}
		$r = $this->http( $method, $url, $args );
		if ( ! in_array( $r['code'], (array) $ok, true ) ) {
			$this->fail( $r, $method . ' ' . wp_parse_url( $url, PHP_URL_PATH ) );
		}
		return $r;
	}
}
