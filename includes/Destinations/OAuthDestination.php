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

	public function isConfigured(): bool {
		return $this->hasApp() && $this->isConnected();
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

	/** Step 1: the address to send the administrator to. The state and PKCE verifier are bound to them for 10 minutes. */
	public function beginConnect( int $userId ): string {
		if ( ! $this->hasApp() ) {
			throw new BackupException( 'Enter the client ID and client secret first.' );
		}
		$state    = bin2hex( random_bytes( 32 ) );
		$verifier = rtrim( strtr( base64_encode( random_bytes( 48 ) ), '+/', '-_' ), '=' );
		set_transient(
			'bbk_oauth_' . hash( 'sha256', $state ),
			array(
				'user' => $userId,
				'dest' => $this->id(),
				'v'    => $verifier,
				'exp'  => time() + 600,
			),
			600
		);
		$params = array_merge(
			array(
				'client_id'             => (string) $this->config()['client_id'],
				'redirect_uri'          => self::redirectUri(),
				'response_type'         => 'code',
				'scope'                 => $this->scope(),
				'state'                 => $state,
				'code_challenge'        => rtrim( strtr( base64_encode( hash( 'sha256', $verifier, true ) ), '+/', '-_' ), '=' ),
				'code_challenge_method' => 'S256',
			),
			$this->extraAuthParams()
		);
		return $this->authorizeUrl() . '?' . http_build_query( $params, '', '&', PHP_QUERY_RFC3986 );
	}

	/** Step 2 (from the OAuth callback): swap the code for tokens. */
	public function finishConnect( string $code, string $verifier ): void {
		$s   = $this->secrets();
		$res = Http::request(
			'POST',
			$this->tokenUrl(),
			array( 'Content-Type' => 'application/x-www-form-urlencoded' ),
			http_build_query(
				array(
					'grant_type'    => 'authorization_code',
					'code'          => $code,
					'client_id'     => (string) $this->config()['client_id'],
					'client_secret' => (string) ( $s['client_secret'] ?? '' ),
					'redirect_uri'  => self::redirectUri(),
					'code_verifier' => $verifier,
				),
				'',
				'&'
			),
			30,
			2
		);
		if ( 200 !== $res['code'] ) {
			throw Http::fail( 'Connecting the account', $res );
		}
		$t = Http::json( $res );
		if ( empty( $t['refresh_token'] ) ) {
			throw new BackupException( 'The provider did not give a refresh token, so backups could not run unattended. Remove the app\'s access in your account and connect again.' );
		}
		$s['refresh'] = (string) $t['refresh_token'];
		$s['access']  = (string) ( $t['access_token'] ?? '' );
		$s['expires'] = time() + (int) ( $t['expires_in'] ?? 3000 );
		$this->saveSecrets( $s );
		$s['account'] = $this->whoAmI();
		$this->saveSecrets( $s );
	}

	public function disconnect(): void {
		$s = $this->secrets();
		unset( $s['refresh'], $s['access'], $s['expires'], $s['account'] );
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
					'client_id'     => (string) ( $this->config()['client_id'] ?? '' ),
					'client_secret' => (string) ( $s['client_secret'] ?? '' ),
				),
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
