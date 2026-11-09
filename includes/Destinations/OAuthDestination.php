<?php
/**
 * Shared OAuth 2 (authorization code + PKCE) for Google Drive and OneDrive. The site talks to the provider directly:
 * no relay server. The client secret and the tokens live only in the Vault, encrypted.
 *
 * @package BastionBackup
 */

namespace BastionBackup\Destinations;

use BastionBackup\Core\BackupException;
use BastionBackup\Http;
use BastionBackup\Settings;
use BastionBackup\Vault;

defined( 'ABSPATH' ) || exit;

abstract class OAuthDestination implements Destination {

	abstract protected function authorizeUrl(): string;

	abstract protected function tokenUrl(): string;

	abstract protected function scope(): string;

	/** The device sign-in endpoint ("Connect" without an app of your own). */
	abstract protected function deviceUrl(): string;

	/** @return array{id:string,secret:string} The shared app (Apps). */
	abstract protected function sharedApp(): array;

	/** @return array<string,string> */
	protected function extraAuthParams(): array {
		return array();
	}

	/** Non-secret settings (client ID, folder…). */
	public function config(): array {
		return Settings::destination( $this->id() );
	}

	/** @return array<string,mixed> client_secret, refresh, access, expires, account */
	protected function secrets(): array {
		$s = Vault::get( 'dest_' . $this->id(), array() );
		return is_array( $s ) ? $s : array();
	}

	protected function saveSecrets( array $s ): void {
		Vault::set( 'dest_' . $this->id(), $s );
	}

	public static function redirectUri(): string {
		return rest_url( 'bastion-backup/v1/oauth' );
	}

	public function hasApp(): bool {
		return '' !== (string) ( $this->config()['client_id'] ?? '' ) && '' !== (string) ( $this->secrets()['client_secret'] ?? '' );
	}

	public function isConnected(): bool {
		return '' !== (string) ( $this->secrets()['refresh'] ?? '' );
	}

	/** "Connect" with the shared app is offered when its ID is set. */
	public function hasShared(): bool {
		return '' !== $this->sharedApp()['id'];
	}

	/** Connected through the shared app ("Connect") rather than the site's own app. */
	public function usesShared(): bool {
		return 'shared' === ( $this->secrets()['app'] ?? '' );
	}

	/** @return array{id:string,secret:string} The app the tokens belong to: refreshes must use the same one. */
	protected function client(): array {
		if ( $this->usesShared() ) {
			return $this->sharedApp();
		}
		return array(
			'id'     => (string) ( $this->config()['client_id'] ?? '' ),
			'secret' => (string) ( $this->secrets()['client_secret'] ?? '' ),
		);
	}

	public function isConfigured(): bool {
		return $this->isConnected() && ( $this->usesShared() ? $this->hasShared() : $this->hasApp() );
	}

	public function account(): string {
		return (string) ( $this->secrets()['account'] ?? '' );
	}

	/** Saves the app's client ID (settings) and secret (Vault). An empty secret keeps the one saved. */
	public function saveApp( string $clientId, string $clientSecret ): void {
		Settings::setDestination( $this->id(), array_merge( $this->config(), array( 'client_id' => $clientId ) ) );
		if ( '' !== $clientSecret ) {
			$s                  = $this->secrets();
			$s['client_secret'] = $clientSecret;
			$this->saveSecrets( $s );
		}
	}

	/**
	 * "Connect" by signing in at the provider and coming straight back, through the shared app: for providers whose
	 * apps can be public clients (no secret; PKCE instead). The provider sends the browser to the shared return page
	 * (Apps::relay()), which only forwards it to this site's own callback.
	 */
	public function sharedRedirect(): bool {
		return false;
	}

	/**
	 * Step 1: the address to send the administrator to. The state and PKCE verifier are bound to them for 10 minutes.
	 * $shared: through the shared app and return page; otherwise the site's own app straight back to this site.
	 */
	public function beginConnect( int $userId, bool $shared = false ): string {
		if ( $shared ) {
			if ( ! $this->sharedRedirect() || '' === $this->sharedApp()['id'] ) {
				throw new BackupException( 'Connect with your own app below: the shared app is not set up in this copy of the plugin.' );
			}
			$clientId = $this->sharedApp()['id'];
			$redirect = Apps::relay();
			// The return page needs to know where to send the browser back: this site's callback, inside the state.
			$state = rtrim( strtr( base64_encode( (string) wp_json_encode( array( 'r' => self::redirectUri(), 'n' => bin2hex( random_bytes( 24 ) ) ) ) ), '+/', '-_' ), '=' );
		} else {
			if ( ! $this->hasApp() ) {
				throw new BackupException( 'Enter the client ID and client secret first.' );
			}
			$clientId = (string) $this->config()['client_id'];
			$redirect = self::redirectUri();
			$state    = bin2hex( random_bytes( 32 ) );
		}
		$verifier = rtrim( strtr( base64_encode( random_bytes( 48 ) ), '+/', '-_' ), '=' );
		set_transient(
			'bbk_oauth_' . hash( 'sha256', $state ),
			array(
				'user'     => $userId,
				'dest'     => $this->id(),
				'v'        => $verifier,
				'exp'      => time() + 600,
				'app'      => $shared ? 'shared' : 'own',
				'redirect' => $redirect,
			),
			600
		);
		$params = array_merge(
			array(
				'client_id'             => $clientId,
				'redirect_uri'          => $redirect,
				'response_type'         => 'code',
				'scope'                 => $this->scope(),
				'state'                 => $state,
				'code_challenge'        => rtrim( strtr( base64_encode( hash( 'sha256', $verifier, true ) ), '+/', '-_' ), '=' ),
				'code_challenge_method' => 'S256',
			),
			$this->extraAuthParams()
		);
		return $this->authorizeUrl() . '?' . http_build_query( array_filter( $params, static fn( $v ) => '' !== $v ), '', '&', PHP_QUERY_RFC3986 );
	}

	/** Step 2 (from the OAuth callback): swap the code for tokens, with the same app and return address as step 1. */
	public function finishConnect( string $code, string $verifier, string $app = 'own', string $redirect = '' ): void {
		$s      = $this->secrets();
		$client = 'shared' === $app ? $this->sharedApp() : array( 'id' => (string) $this->config()['client_id'], 'secret' => (string) ( $s['client_secret'] ?? '' ) );
		$args   = array(
			'grant_type'    => 'authorization_code',
			'code'          => $code,
			'client_id'     => $client['id'],
			'redirect_uri'  => '' !== $redirect ? $redirect : self::redirectUri(),
			'code_verifier' => $verifier,
		);
		if ( '' !== $client['secret'] ) {
			$args['client_secret'] = $client['secret'];
		}
		$res = Http::request( 'POST', $this->tokenUrl(), array( 'Content-Type' => 'application/x-www-form-urlencoded' ), http_build_query( $args, '', '&' ), 30, 2 );
		if ( 200 !== $res['code'] ) {
			throw Http::fail( 'Connecting the account', $res );
		}
		$t = Http::json( $res );
		if ( empty( $t['refresh_token'] ) ) {
			throw new BackupException( 'The provider did not give a refresh token, so backups could not run unattended. Remove the app\'s access in your account and connect again.' );
		}
		$s['app'] = 'shared' === $app ? 'shared' : 'own';
		$this->storeTokens( $s, $t );
	}

	/** Keeps new tokens (encrypted) and the account's name. */
	private function storeTokens( array $s, array $t ): void {
		$s['refresh'] = (string) $t['refresh_token'];
		$s['access']  = (string) ( $t['access_token'] ?? '' );
		$s['expires'] = time() + (int) ( $t['expires_in'] ?? 3000 );
		$this->saveSecrets( $s );
		$s['account'] = $this->whoAmI();
		$this->saveSecrets( $s );
	}

	/**
	 * "Connect", step 1: asks for a sign-in code. The administrator enters it at the provider's page and allows access;
	 * the device code stays on the server, bound to that administrator, for as long as the provider allows.
	 *
	 * @return array{code:string,url:string,interval:int,expires_in:int}
	 */
	public function beginDevice( int $userId ): array {
		$app = $this->sharedApp();
		if ( '' === $app['id'] || '' === $this->deviceUrl() ) {
			throw new BackupException( 'Connect with your own app below: the shared app is not set up in this copy of the plugin.' );
		}
		$res = Http::request( 'POST', $this->deviceUrl(), array( 'Content-Type' => 'application/x-www-form-urlencoded' ), http_build_query( array( 'client_id' => $app['id'], 'scope' => $this->scope() ), '', '&' ), 30, 2 );
		$d   = Http::json( $res );
		if ( 200 !== $res['code'] || empty( $d['device_code'] ) || empty( $d['user_code'] ) ) {
			throw Http::fail( 'Starting the sign-in with ' . $this->label(), $res );
		}
		$url = (string) ( $d['verification_url'] ?? $d['verification_uri'] ?? '' );
		if ( ! str_starts_with( $url, 'https://' ) ) {
			throw new BackupException( 'The provider gave an unexpected sign-in address.' );
		}
		$life = max( 60, min( 1800, (int) ( $d['expires_in'] ?? 900 ) ) );
		set_transient(
			'bbk_device_' . $this->id() . '_' . $userId,
			array(
				'dc'       => (string) $d['device_code'],
				'interval' => max( 2, (int) ( $d['interval'] ?? 5 ) ),
				'next'     => time(),
				'exp'      => time() + $life,
			),
			$life
		);
		return array(
			'code'       => (string) $d['user_code'],
			'url'        => $url,
			'interval'   => max( 2, (int) ( $d['interval'] ?? 5 ) ),
			'expires_in' => $life,
		);
	}

	/** "Connect", step 2, asked every few seconds: "pending" until the administrator allows access, then "connected". */
	public function pollDevice( int $userId ): string {
		$key = 'bbk_device_' . $this->id() . '_' . $userId;
		$t   = get_transient( $key );
		if ( ! is_array( $t ) || (int) $t['exp'] < time() ) {
			delete_transient( $key );
			throw new BackupException( 'The sign-in code has expired. Press Connect again.' );
		}
		if ( time() < (int) $t['next'] ) {
			return 'pending'; // Never faster than the provider asked.
		}
		$app  = $this->sharedApp();
		$args = array(
			'grant_type'  => 'urn:ietf:params:oauth:grant-type:device_code',
			'device_code' => (string) $t['dc'],
			'client_id'   => $app['id'],
		);
		if ( '' !== $app['secret'] ) {
			$args['client_secret'] = $app['secret'];
		}
		$res = Http::request( 'POST', $this->tokenUrl(), array( 'Content-Type' => 'application/x-www-form-urlencoded' ), http_build_query( $args, '', '&' ), 30, 2 );
		$d   = Http::json( $res );
		if ( 200 === $res['code'] && ! empty( $d['refresh_token'] ) ) {
			delete_transient( $key );
			$s        = $this->secrets();
			$s['app'] = 'shared';
			$this->storeTokens( $s, $d );
			return 'connected';
		}
		$err = (string) ( $d['error'] ?? '' );
		if ( 'authorization_pending' === $err || 'slow_down' === $err ) {
			$t['interval'] += 'slow_down' === $err ? 5 : 0;
			$t['next']      = time() + (int) $t['interval'];
			set_transient( $key, $t, max( 60, (int) $t['exp'] - time() ) );
			return 'pending';
		}
		delete_transient( $key );
		if ( in_array( $err, array( 'access_denied', 'authorization_declined' ), true ) ) {
			throw new BackupException( 'Access was not allowed, so ' . $this->label() . ' is not connected.' );
		}
		if ( 'expired_token' === $err ) {
			throw new BackupException( 'The sign-in code has expired. Press Connect again.' );
		}
		if ( 200 === $res['code'] ) {
			throw new BackupException( 'The provider did not give a refresh token, so backups could not run unattended.' );
		}
		throw Http::fail( 'Connecting ' . $this->label(), $res );
	}

	public function disconnect(): void {
		$s = $this->secrets();
		unset( $s['refresh'], $s['access'], $s['expires'], $s['account'], $s['app'] );
		$this->saveSecrets( $s );
		$c = $this->config();
		unset( $c['folder_id'] );
		Settings::setDestination( $this->id(), $c );
	}

	/** The signed-in account's name or address, shown in the settings. */
	abstract protected function whoAmI(): string;

	protected function accessToken( bool $force = false ): string {
		$s = $this->secrets();
		if ( ! $force && ! empty( $s['access'] ) && (int) ( $s['expires'] ?? 0 ) > time() + 120 ) {
			return (string) $s['access'];
		}
		if ( empty( $s['refresh'] ) ) {
			throw new BackupException( $this->label() . ' is not connected.' );
		}
		$res = Http::request(
			'POST',
			$this->tokenUrl(),
			array( 'Content-Type' => 'application/x-www-form-urlencoded' ),
			http_build_query(
				array(
					'grant_type'    => 'refresh_token',
					'refresh_token' => (string) $s['refresh'],
					'client_id'     => $this->client()['id'],
				) + ( '' !== $this->client()['secret'] ? array( 'client_secret' => $this->client()['secret'] ) : array() ),
				'',
				'&'
			),
			30,
			3
		);
		if ( 200 !== $res['code'] ) {
			throw Http::fail( 'Renewing access to ' . $this->label() . ' (connect the account again if it was revoked)', $res );
		}
		$t            = Http::json( $res );
		$s['access']  = (string) ( $t['access_token'] ?? '' );
		$s['expires'] = time() + (int) ( $t['expires_in'] ?? 3000 );
		if ( ! empty( $t['refresh_token'] ) ) {
			$s['refresh'] = (string) $t['refresh_token']; // Rotated: keep the new one.
		}
		$this->saveSecrets( $s );
		return $s['access'];
	}

	/**
	 * An API call with the access token; renews it once on 401.
	 *
	 * @param array<string,string> $headers
	 * @return array{code:int, headers:array<string,string>, body:string}
	 */
	protected function api( string $method, string $url, array $headers = array(), ?string $body = null, ?float $deadline = null, int $timeout = 60 ): array {
		$res = Http::request( $method, $url, array( 'Authorization' => 'Bearer ' . $this->accessToken() ) + $headers, $body, $timeout, 4, 0, $deadline );
		if ( 401 === $res['code'] ) {
			$res = Http::request( $method, $url, array( 'Authorization' => 'Bearer ' . $this->accessToken( true ) ) + $headers, $body, $timeout, 4, 0, $deadline );
		}
		return $res;
	}

	/** Ranged download into $localPath, resumable; $fetch($from, $to) returns the HTTP response for that range. */
	protected function rangedDownload( callable $fetch, int $size, string $localPath, array &$state, float $deadline ): bool {
		$state['off'] = (int) ( $state['off'] ?? 0 );
		$fh           = fopen( $localPath, 'c+b' );
		if ( ! $fh ) {
			throw new BackupException( 'Cannot write the downloaded backup.' );
		}
		try {
			ftruncate( $fh, $state['off'] );
			fseek( $fh, $state['off'] );
			while ( $state['off'] < $size ) {
				$to  = min( $size, $state['off'] + 8388608 ) - 1;
				$res = $fetch( $state['off'], $to );
				if ( 206 !== $res['code'] && ! ( 200 === $res['code'] && 0 === $state['off'] && strlen( $res['body'] ) === $size ) ) {
					throw Http::fail( 'Downloading from ' . $this->label(), $res );
				}
				if ( strlen( $res['body'] ) !== $to - $state['off'] + 1 && 200 !== $res['code'] ) {
					throw new BackupException( 'Download from ' . $this->label() . ' returned the wrong number of bytes.' );
				}
				if ( false === fwrite( $fh, $res['body'] ) ) {
					throw new BackupException( 'Write failed — the disk may be full.' );
				}
				$state['off'] += strlen( $res['body'] );
				if ( microtime( true ) > $deadline && $state['off'] < $size ) {
					return false;
				}
			}
			return true;
		} finally {
			fclose( $fh );
		}
	}

	/** Reads $len bytes of $path from $off. */
	protected static function chunk( string $path, int $off, int $len ): string {
		$fh = fopen( $path, 'rb' );
		if ( ! $fh ) {
			throw new BackupException( 'Cannot read the backup file.' );
		}
		fseek( $fh, $off );
		$data = (string) fread( $fh, $len );
		fclose( $fh );
		if ( strlen( $data ) !== $len ) {
			throw new BackupException( 'The backup file changed while it was being uploaded.' );
		}
		return $data;
	}

	/**
	 * A busy or lost answer (429, 5xx) mid-upload: resume next step, which first asks the provider what it has.
	 * Ten in a row and it gives up, so a dead service cannot keep a job going forever.
	 */
	protected static function transient( array $res, array &$state ): bool {
		if ( 429 !== $res['code'] && $res['code'] < 500 ) {
			$state['busy'] = 0;
			return false;
		}
		$state['busy'] = (int) ( $state['busy'] ?? 0 ) + 1;
		return $state['busy'] < 10;
	}

	/** "bytes=0-1234" → 1235 (the next byte to send), or 0. */
	protected static function nextFromRange( string $range ): int {
		return preg_match( '/bytes=\d+-(\d+)/', $range, $m ) ? (int) $m[1] + 1 : 0;
	}
}
