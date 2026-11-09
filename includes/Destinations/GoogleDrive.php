<?php
/**
 * Google Drive, with the least access Google offers (drive.file: only files this plugin made). Resumable upload
 * sessions in 8 MiB chunks (a multiple of 256 KiB), ranged downloads.
 *
 * @package BastionBackup
 */

namespace BastionBackup\Destinations;

use BastionBackup\Core\BackupException;
use BastionBackup\Http;
use BastionBackup\Settings;

defined( 'ABSPATH' ) || exit;

final class GoogleDrive extends OAuthDestination {

	private const CHUNK = 8388608;
	private const API   = 'https://www.googleapis.com/drive/v3';

	public function id(): string {
		return 'google';
	}

	public function label(): string {
		return 'Google Drive';
	}

	protected function authorizeUrl(): string {
		return 'https://accounts.google.com/o/oauth2/v2/auth';
	}

	protected function tokenUrl(): string {
		return 'https://oauth2.googleapis.com/token';
	}

	protected function scope(): string {
		return 'https://www.googleapis.com/auth/drive.file';
	}

	protected function extraAuthParams(): array {
		// offline + consent: Google gives a refresh token, so backups run unattended.
		return array(
			'access_type' => 'offline',
			'prompt'      => 'consent',
		);
	}

	protected function whoAmI(): string {
		$res = $this->api( 'GET', self::API . '/about?fields=user(emailAddress)' );
		return 200 === $res['code'] ? (string) ( Http::json( $res )['user']['emailAddress'] ?? '' ) : '';
	}

	public function folderName(): string {
		$n = trim( (string) ( $this->config()['folder'] ?? '' ) );
		return '' !== $n ? $n : 'Bastion Backup - ' . Settings::siteSlug();
	}

	/** The backup folder's ID, found or made once and remembered. */
	private function folderId(): string {
		$c = $this->config();
		if ( ! empty( $c['folder_id'] ) && ( $c['folder_for'] ?? '' ) === $this->folderName() ) {
			return (string) $c['folder_id'];
		}
		$name = $this->folderName();
		$q    = "name = '" . str_replace( array( '\\', "'" ), array( '\\\\', "\\'" ), $name ) . "' and mimeType = 'application/vnd.google-apps.folder' and trashed = false";
		$res  = $this->api( 'GET', self::API . '/files?' . http_build_query( array( 'q' => $q, 'fields' => 'files(id)', 'pageSize' => 1 ), '', '&' ) );
		if ( 200 !== $res['code'] ) {
			throw Http::fail( 'Finding the Google Drive folder', $res );
		}
		$id = (string) ( Http::json( $res )['files'][0]['id'] ?? '' );
		if ( '' === $id ) {
			$res = $this->api(
				'POST',
				self::API . '/files?fields=id',
				array( 'Content-Type' => 'application/json; charset=UTF-8' ),
				(string) wp_json_encode( array( 'name' => $name, 'mimeType' => 'application/vnd.google-apps.folder' ) )
			);
			if ( 200 !== $res['code'] ) {
				throw Http::fail( 'Making the Google Drive folder', $res );
			}
			$id = (string) ( Http::json( $res )['id'] ?? '' );
		}
		Settings::setDestination( $this->id(), array_merge( $c, array( 'folder_id' => $id, 'folder_for' => $name ) ) );
		return $id;
	}

	public function test(): true|string {
		try {
			if ( ! $this->isConfigured() ) {
				return 'Not connected yet.';
			}
			$this->accessToken( true );
			$this->folderId();
			return true;
		} catch ( BackupException $e ) {
			return $e->getMessage();
		}
	}

	public function upload( string $localPath, string $remoteName, array &$state, float $deadline ): bool {
		$size = (int) filesize( $localPath );
		if ( empty( $state['session'] ) ) {
			$res = $this->api(
				'POST',
				'https://www.googleapis.com/upload/drive/v3/files?uploadType=resumable&fields=id',
				array(
					'Content-Type'            => 'application/json; charset=UTF-8',
					'X-Upload-Content-Type'   => 'application/octet-stream',
					'X-Upload-Content-Length' => (string) $size,
				),
				(string) wp_json_encode( array( 'name' => $remoteName, 'parents' => array( $this->folderId() ) ) )
			);
			if ( 200 !== $res['code'] || empty( $res['headers']['location'] ) ) {
				throw Http::fail( 'Starting the Google Drive upload', $res );
			}
			$state = array(
				'session' => $res['headers']['location'],
				'off'     => 0,
				'size'    => $size,
			);
		} else {
			// Resuming: ask Google how much it has, in case the last answer was lost.
			$res = $this->put( $state['session'], 'bytes */' . $size, '', $deadline );
			if ( $this->finished( $res, $state ) ) {
				return true;
			}
			if ( 404 === $res['code'] || 410 === $res['code'] ) {
				$state = array(); // The session expired (they last a week): start again.
				return $this->upload( $localPath, $remoteName, $state, $deadline );
			}
			if ( 308 !== $res['code'] ) {
				throw Http::fail( 'Resuming the Google Drive upload', $res );
			}
			$state['off'] = self::nextFromRange( $res['headers']['range'] ?? '' );
		}
		while ( $state['off'] < $size ) {
			$len = (int) min( self::CHUNK, $size - $state['off'] );
			$res = $this->put( $state['session'], sprintf( 'bytes %d-%d/%d', $state['off'], $state['off'] + $len - 1, $size ), self::chunk( $localPath, $state['off'], $len ), $deadline );
			if ( $this->finished( $res, $state ) ) {
				return true;
			}
			if ( 308 !== $res['code'] ) {
				throw Http::fail( 'Uploading to Google Drive', $res );
			}
			$state['off'] = self::nextFromRange( $res['headers']['range'] ?? '' );
			if ( microtime( true ) > $deadline ) {
				return false;
			}
		}
		return false;
	}

	private function finished( array $res, array &$state ): bool {
		if ( 200 === $res['code'] || 201 === $res['code'] ) {
			$state['id']  = (string) ( Http::json( $res )['id'] ?? '' );
			$state['off'] = $state['size'];
			return true;
		}
		return false;
	}

	/** A chunk to the upload session (its URL is itself the credential, so it is never logged). */
	private function put( string $session, string $range, string $body, float $deadline ): array {
		return Http::request(
			'PUT',
			$session,
			array(
				'Authorization'  => 'Bearer ' . $this->accessToken(),
				'Content-Range'  => $range,
				'Content-Length' => (string) strlen( $body ),
			),
			$body,
			300,
			4,
			0,
			$deadline
		);
	}

	public function list(): array {
		$out   = array();
		$token = '';
		$q     = "'" . $this->folderId() . "' in parents and trashed = false";
		do {
			$args = array(
				'q'        => $q,
				'fields'   => 'nextPageToken,files(id,name,size,createdTime)',
				'pageSize' => 200,
				'orderBy'  => 'createdTime desc',
			);
			if ( '' !== $token ) {
				$args['pageToken'] = $token;
			}
			$res = $this->api( 'GET', self::API . '/files?' . http_build_query( $args, '', '&' ) );
			if ( 200 !== $res['code'] ) {
				throw Http::fail( 'Listing Google Drive', $res );
			}
			$d = Http::json( $res );
			foreach ( (array) ( $d['files'] ?? array() ) as $f ) {
				if ( Registry::isOurs( (string) ( $f['name'] ?? '' ) ) ) {
					$out[] = array(
						'id'   => (string) $f['id'],
						'name' => (string) $f['name'],
						'size' => (int) ( $f['size'] ?? 0 ),
						'time' => (int) strtotime( (string) ( $f['createdTime'] ?? '' ) ) ?: Registry::timeFromName( (string) $f['name'] ),
					);
				}
			}
			$token = (string) ( $d['nextPageToken'] ?? '' );
		} while ( '' !== $token );
		usort( $out, static fn( $a, $b ) => $b['time'] <=> $a['time'] );
		return $out;
	}

	public function download( string $remoteId, string $localPath, array &$state, float $deadline ): bool {
		$id = rawurlencode( $remoteId );
		if ( ! isset( $state['size'] ) ) {
			$res = $this->api( 'GET', self::API . '/files/' . $id . '?fields=size,name' );
			if ( 200 !== $res['code'] ) {
				throw Http::fail( 'Finding the backup on Google Drive', $res );
			}
			$state['size'] = (int) ( Http::json( $res )['size'] ?? 0 );
		}
		return $this->rangedDownload(
			fn( int $from, int $to ): array => $this->api( 'GET', self::API . '/files/' . $id . '?alt=media', array( 'Range' => "bytes=$from-$to" ), null, $deadline, 300 ),
			(int) $state['size'],
			$localPath,
			$state,
			$deadline
		);
	}

	public function delete( string $remoteId ): void {
		$res = $this->api( 'DELETE', self::API . '/files/' . rawurlencode( $remoteId ) );
		if ( 204 !== $res['code'] && 404 !== $res['code'] ) {
			throw Http::fail( 'Deleting from Google Drive', $res );
		}
	}
}
