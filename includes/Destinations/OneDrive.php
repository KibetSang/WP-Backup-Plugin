<?php
/**
 * Microsoft OneDrive (personal or work), limited to the app's own folder (Files.ReadWrite.AppFolder). Upload sessions
 * in 10 MiB chunks (a multiple of 320 KiB), ranged downloads from the pre-signed download address.
 *
 * @package BastionBackup
 */

namespace BastionBackup\Destinations;

use BastionBackup\Core\BackupException;
use BastionBackup\Http;
use BastionBackup\Settings;

defined( 'ABSPATH' ) || exit;

final class OneDrive extends OAuthDestination {

	private const CHUNK = 10485760;
	private const API   = 'https://graph.microsoft.com/v1.0';

	public function id(): string {
		return 'onedrive';
	}

	public function label(): string {
		return 'OneDrive';
	}

	private function tenant(): string {
		$t = (string) ( $this->config()['tenant'] ?? '' );
		return preg_match( '/^[A-Za-z0-9.-]{1,64}$/', $t ) ? $t : 'common';
	}

	protected function authorizeUrl(): string {
		return 'https://login.microsoftonline.com/' . $this->tenant() . '/oauth2/v2.0/authorize';
	}

	protected function tokenUrl(): string {
		return 'https://login.microsoftonline.com/' . $this->tenant() . '/oauth2/v2.0/token';
	}

	protected function scope(): string {
		return 'offline_access Files.ReadWrite.AppFolder User.Read';
	}

	protected function extraAuthParams(): array {
		return array( 'response_mode' => 'query' );
	}

	protected function whoAmI(): string {
		$res = $this->api( 'GET', self::API . '/me?$select=userPrincipalName,mail' );
		$d   = Http::json( $res );
		return 200 === $res['code'] ? (string) ( $d['mail'] ?? $d['userPrincipalName'] ?? '' ) : '';
	}

	public function folderName(): string {
		$n = trim( (string) ( $this->config()['folder'] ?? '' ), " /\\" );
		$n = '' !== $n ? $n : Settings::siteSlug();
		return (string) preg_replace( '/[^A-Za-z0-9 ._-]/', '-', $n );
	}

	private function folderPath(): string {
		return self::API . '/me/drive/special/approot:/' . rawurlencode( $this->folderName() );
	}

	public function test(): true|string {
		try {
			if ( ! $this->isConfigured() ) {
				return 'Not connected yet.';
			}
			$this->accessToken( true );
			$res = $this->api( 'GET', self::API . '/me/drive/special/approot?$select=id' );
			return 200 === $res['code'] ? true : Http::fail( 'Opening the OneDrive app folder', $res )->getMessage();
		} catch ( BackupException $e ) {
			return $e->getMessage();
		}
	}

	public function upload( string $localPath, string $remoteName, array &$state, float $deadline ): bool {
		$size = (int) filesize( $localPath );
		if ( empty( $state['url'] ) ) {
			$res = $this->api(
				'POST',
				$this->folderPath() . '/' . rawurlencode( $remoteName ) . ':/createUploadSession',
				array( 'Content-Type' => 'application/json' ),
				(string) wp_json_encode( array( 'item' => array( '@microsoft.graph.conflictBehavior' => 'replace' ) ) )
			);
			if ( 200 !== $res['code'] || empty( Http::json( $res )['uploadUrl'] ) ) {
				throw Http::fail( 'Starting the OneDrive upload', $res );
			}
			$state = array(
				'url'  => (string) Http::json( $res )['uploadUrl'],
				'off'  => 0,
				'size' => $size,
			);
		} else {
			// Resuming: the session says which bytes it still needs.
			$res = Http::request( 'GET', $state['url'], array(), null, 60, 4, 0, $deadline );
			if ( 404 === $res['code'] ) {
				$state = array(); // Expired: start again.
				return $this->upload( $localPath, $remoteName, $state, $deadline );
			}
			if ( self::transient( $res, $state ) ) {
				return false;
			}
			if ( 200 !== $res['code'] ) {
				throw Http::fail( 'Resuming the OneDrive upload', $res );
			}
			$state['off'] = $this->nextExpected( $res, (int) $state['off'] );
		}
		while ( $state['off'] < $size ) {
			$len = (int) min( self::CHUNK, $size - $state['off'] );
			// The upload address carries its own credential: no Authorization header, and it is never logged.
			$res = Http::request(
				'PUT',
				$state['url'],
				array(
					'Content-Length' => (string) $len,
					'Content-Range'  => sprintf( 'bytes %d-%d/%d', $state['off'], $state['off'] + $len - 1, $size ),
				),
				self::chunk( $localPath, $state['off'], $len ),
				300,
				4,
				0,
				$deadline
			);
			if ( 200 === $res['code'] || 201 === $res['code'] ) {
				$state['id']  = (string) ( Http::json( $res )['id'] ?? '' );
				$state['off'] = $size;
				return true;
			}
			if ( self::transient( $res, $state ) ) {
				return false;
			}
			if ( 202 !== $res['code'] ) {
				throw Http::fail( 'Uploading to OneDrive', $res );
			}
			$state['off'] = $this->nextExpected( $res, $state['off'] + $len );
			if ( microtime( true ) > $deadline ) {
				return false;
			}
		}
		return false;
	}

	private function nextExpected( array $res, int $fallback ): int {
		$r = (array) ( Http::json( $res )['nextExpectedRanges'] ?? array() );
		return isset( $r[0] ) && preg_match( '/^(\d+)-/', (string) $r[0], $m ) ? (int) $m[1] : $fallback;
	}

	public function list(): array {
		$out = array();
		$url = $this->folderPath() . ':/children?$select=id,name,size,createdDateTime&$top=200';
		while ( '' !== $url ) {
			$res = $this->api( 'GET', $url );
			if ( 404 === $res['code'] ) {
				return array(); // No folder yet: nothing uploaded.
			}
			if ( 200 !== $res['code'] ) {
				throw Http::fail( 'Listing OneDrive', $res );
			}
			$d = Http::json( $res );
			foreach ( (array) ( $d['value'] ?? array() ) as $f ) {
				if ( Registry::isOurs( (string) ( $f['name'] ?? '' ) ) ) {
					$out[] = array(
						'id'   => (string) $f['id'],
						'name' => (string) $f['name'],
						'size' => (int) ( $f['size'] ?? 0 ),
						// When the backup was made (from its name), not when it was uploaded: retention keeps the newest backups.
						'time' => Registry::timeFromName( (string) $f['name'] ) ?: (int) strtotime( (string) ( $f['createdDateTime'] ?? '' ) ),
					);
				}
			}
			$next = (string) ( $d['@odata.nextLink'] ?? '' );
			$url  = str_starts_with( $next, self::API . '/' ) ? $next : '';
		}
		usort( $out, static fn( $a, $b ) => $b['time'] <=> $a['time'] );
		return $out;
	}

	public function download( string $remoteId, string $localPath, array &$state, float $deadline ): bool {
		if ( empty( $state['url'] ) || (int) ( $state['url_until'] ?? 0 ) < time() ) {
			$res = $this->api( 'GET', self::API . '/me/drive/items/' . rawurlencode( $remoteId ) . '?$select=id,size,@microsoft.graph.downloadUrl' );
			$d   = Http::json( $res );
			if ( 200 !== $res['code'] || empty( $d['@microsoft.graph.downloadUrl'] ) ) {
				throw Http::fail( 'Finding the backup on OneDrive', $res );
			}
			$state['url']       = (string) $d['@microsoft.graph.downloadUrl'];
			$state['url_until'] = time() + 1800; // Pre-signed addresses last about an hour.
			$state['size']      = (int) ( $d['size'] ?? 0 );
		}
		return $this->rangedDownload(
			static fn( int $from, int $to ): array => Http::request( 'GET', (string) $state['url'], array( 'Range' => "bytes=$from-$to" ), null, 300, 4, 0, $deadline ),
			(int) $state['size'],
			$localPath,
			$state,
			$deadline
		);
	}

	public function delete( string $remoteId ): void {
		$res = $this->api( 'DELETE', self::API . '/me/drive/items/' . rawurlencode( $remoteId ) );
		if ( 204 !== $res['code'] && 404 !== $res['code'] ) {
			throw Http::fail( 'Deleting from OneDrive', $res );
		}
	}
}
