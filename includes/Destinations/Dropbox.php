<?php
/**
 * Dropbox, limited to the app's own folder (an "App folder" app: Apps/<app name>). Upload sessions in 8 MiB pieces
 * (start → append → finish), resumed at the offset Dropbox reports; ranged downloads.
 *
 * @package BastionBackup
 */

namespace BastionBackup\Destinations;

use BastionBackup\Core\BackupException;
use BastionBackup\Http;
use BastionBackup\Settings;

defined( 'ABSPATH' ) || exit;

final class Dropbox extends OAuthDestination {

	private const CHUNK   = 8388608;
	private const API     = 'https://api.dropboxapi.com/2';
	private const CONTENT = 'https://content.dropboxapi.com/2';

	public function id(): string {
		return 'dropbox';
	}

	public function label(): string {
		return 'Dropbox';
	}

	protected function authorizeUrl(): string {
		return 'https://www.dropbox.com/oauth2/authorize';
	}

	protected function tokenUrl(): string {
		return 'https://api.dropboxapi.com/oauth2/token';
	}

	protected function scope(): string {
		return 'account_info.read files.metadata.read files.content.read files.content.write';
	}

	protected function deviceUrl(): string {
		return ''; // Dropbox has no code sign-in; it uses sign in and come back.
	}

	protected function sharedApp(): array {
		return Apps::dropbox();
	}

	public function sharedRedirect(): bool {
		return true;
	}

	protected function extraAuthParams(): array {
		return array( 'token_access_type' => 'offline' ); // A refresh token, so backups run unattended.
	}

	/** An RPC call (JSON in, JSON out). */
	private function rpc( string $path, ?array $args, ?float $deadline = null ): array {
		return $this->api( 'POST', self::API . $path, null === $args ? array() : array( 'Content-Type' => 'application/json' ), null === $args ? null : (string) wp_json_encode( $args ), $deadline );
	}

	/** A content call: the arguments go in the Dropbox-API-Arg header (ASCII-escaped JSON), data in the body. */
	private function content( string $path, array $args, string $body, ?float $deadline, array $extra = array() ): array {
		return $this->api( 'POST', self::CONTENT . $path, array( 'Dropbox-API-Arg' => (string) json_encode( $args, JSON_UNESCAPED_SLASHES ), 'Content-Type' => 'application/octet-stream' ) + $extra, $body, $deadline, 300 );
	}

	protected function whoAmI(): string {
		$res = $this->rpc( '/users/get_current_account', null );
		return 200 === $res['code'] ? (string) ( Http::json( $res )['email'] ?? '' ) : '';
	}

	public function folderName(): string {
		$n = trim( (string) ( $this->config()['folder'] ?? '' ), " /\\" );
		return (string) preg_replace( '/[^A-Za-z0-9 ._-]/', '-', '' !== $n ? $n : Settings::siteSlug() );
	}

	private function path( string $name = '' ): string {
		return '/' . $this->folderName() . ( '' !== $name ? '/' . $name : '' );
	}

	public function test(): true|string {
		try {
			if ( ! $this->isConfigured() ) {
				return 'Not connected yet.';
			}
			$this->accessToken( true );
			$res = $this->rpc( '/users/get_current_account', null );
			return 200 === $res['code'] ? true : Http::fail( 'Opening Dropbox', $res )->getMessage();
		} catch ( BackupException $e ) {
			return $e->getMessage();
		}
	}

	public function upload( string $localPath, string $remoteName, array &$state, float $deadline ): bool {
		$size = (int) filesize( $localPath );
		if ( empty( $state['session'] ) ) {
			$len = (int) min( self::CHUNK, $size );
			$res = $this->content( '/files/upload_session/start', array( 'close' => false ), self::chunk( $localPath, 0, $len ), $deadline );
			if ( self::transient( $res, $state ) ) {
				return false;
			}
			if ( 200 !== $res['code'] || empty( Http::json( $res )['session_id'] ) ) {
				throw Http::fail( 'Starting the Dropbox upload', $res );
			}
			$state = array(
				'session' => (string) Http::json( $res )['session_id'],
				'off'     => $len,
				'size'    => $size,
			);
		}
		while ( true ) {
			$cursor = array( 'session_id' => (string) $state['session'], 'offset' => (int) $state['off'] );
			$len    = (int) min( self::CHUNK, $size - $state['off'] );
			if ( $state['off'] + $len >= $size ) {
				// The last piece (possibly empty) and the commit together.
				$res = $this->content(
					'/files/upload_session/finish',
					array(
						'cursor' => $cursor,
						'commit' => array( 'path' => $this->path( $remoteName ), 'mode' => 'overwrite', 'autorename' => false, 'mute' => true ),
					),
					$len > 0 ? self::chunk( $localPath, (int) $state['off'], $len ) : '',
					$deadline
				);
				if ( 200 === $res['code'] ) {
					$state['id']  = (string) ( Http::json( $res )['id'] ?? '' );
					$state['off'] = $size;
					return true;
				}
			} else {
				$res = $this->content( '/files/upload_session/append_v2', array( 'cursor' => $cursor, 'close' => false ), self::chunk( $localPath, (int) $state['off'], $len ), $deadline );
				if ( 200 === $res['code'] ) {
					$state['off'] += $len;
					$state['busy'] = 0;
					if ( microtime( true ) > $deadline ) {
						return false;
					}
					continue;
				}
			}
			// Out of step (an answer was lost): Dropbox says where it is.
			$err = Http::json( $res );
			if ( isset( $err['error']['correct_offset'] ) ) {
				$state['off'] = (int) $err['error']['correct_offset'];
				continue;
			}
			if ( str_contains( (string) ( $err['error_summary'] ?? '' ), 'not_found' ) ) {
				$state = array(); // The session expired (they last a week): start again.
				return false;
			}
			if ( self::transient( $res, $state ) ) {
				return false;
			}
			throw Http::fail( 'Uploading to Dropbox', $res );
		}
	}

	public function list(): array {
		$out = array();
		$res = $this->rpc( '/files/list_folder', array( 'path' => $this->path(), 'limit' => 500 ) );
		while ( true ) {
			if ( 409 === $res['code'] && str_contains( $res['body'], 'not_found' ) ) {
				return array(); // No folder yet: nothing uploaded.
			}
			if ( 200 !== $res['code'] ) {
				throw Http::fail( 'Listing Dropbox', $res );
			}
			$d = Http::json( $res );
			foreach ( (array) ( $d['entries'] ?? array() ) as $f ) {
				if ( 'file' === ( $f['.tag'] ?? '' ) && Registry::isOurs( (string) ( $f['name'] ?? '' ) ) ) {
					$out[] = array(
						'id'   => (string) $f['id'],
						'name' => (string) $f['name'],
						'size' => (int) ( $f['size'] ?? 0 ),
						'time' => Registry::timeFromName( (string) $f['name'] ) ?: (int) strtotime( (string) ( $f['server_modified'] ?? '' ) ),
					);
				}
			}
			if ( empty( $d['has_more'] ) ) {
				break;
			}
			$res = $this->rpc( '/files/list_folder/continue', array( 'cursor' => (string) $d['cursor'] ) );
		}
		usort( $out, static fn( $a, $b ) => $b['time'] <=> $a['time'] );
		return $out;
	}

	public function download( string $remoteId, string $localPath, array &$state, float $deadline ): bool {
		if ( ! isset( $state['size'] ) ) {
			$res = $this->rpc( '/files/get_metadata', array( 'path' => $remoteId ) );
			if ( 200 !== $res['code'] ) {
				throw Http::fail( 'Finding the backup on Dropbox', $res );
			}
			$state['size'] = (int) ( Http::json( $res )['size'] ?? 0 );
		}
		return $this->rangedDownload(
			fn( int $from, int $to ): array => $this->api( 'POST', self::CONTENT . '/files/download', array( 'Dropbox-API-Arg' => (string) json_encode( array( 'path' => $remoteId ) ), 'Range' => "bytes=$from-$to" ), '', $deadline, 300 ),
			(int) $state['size'],
			$localPath,
			$state,
			$deadline
		);
	}

	public function delete( string $remoteId ): void {
		$res = $this->rpc( '/files/delete_v2', array( 'path' => $remoteId ) );
		if ( 200 !== $res['code'] && ! ( 409 === $res['code'] && str_contains( $res['body'], 'not_found' ) ) ) {
			throw Http::fail( 'Deleting from Dropbox', $res );
		}
	}
}
