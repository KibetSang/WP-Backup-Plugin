<?php

declare(strict_types=1);

namespace BastionBackup\Tests\Support;

/**
 * Fake Google Drive and Microsoft Graph, following each one's resumable upload rules closely enough to catch mistakes:
 * Google answers 308 with "Range: bytes=0-N", OneDrive answers 202 with nextExpectedRanges; both check Content-Range,
 * refuse a chunk that does not start where they expect, serve ranged downloads, and page their listings.
 * Faults can be injected: a 503, or a chunk received but its answer lost.
 */
final class FakeClouds {

	/** @var array<string,array{name:string,data:string,parent:string,created:int}> */
	public array $files = array();
	/** @var array<string,array{name:string,size:int,data:string,parent:string}> */
	public array $sessions = array();
	public string $access = 'access-1';
	public string $refresh = 'refresh-1';
	public int $refreshes = 0;
	public int $fail503 = 0;
	public int $loseAnswer = 0;
	public int $pageSize = 2;
	public array $seen = array();
	/** Device sign-in: 'pending' until the person allows (or 'denied'); the last token request's form. */
	public string $device = 'pending';
	public array $lastTokenForm = array();

	public function __invoke( string $method, string $url, array $headers, string $body ): array {
		$h = array_change_key_case( $headers );
		$this->seen[] = $method . ' ' . preg_replace( '/\?.*/', '', $url );
		if ( str_ends_with( (string) parse_url( $url, PHP_URL_PATH ), '/device/code' ) || str_ends_with( (string) parse_url( $url, PHP_URL_PATH ), '/devicecode' ) ) {
			parse_str( $body, $p );
			$ms = str_contains( $url, 'microsoftonline' );
			return bbk_reply( 200, json_encode( array( 'device_code' => 'dev-' . $p['client_id'], 'user_code' => 'ABCD-EFGH', ( $ms ? 'verification_uri' : 'verification_url' ) => $ms ? 'https://microsoft.com/devicelogin' : 'https://www.google.com/device', 'expires_in' => 900, 'interval' => 5 ) ) );
		}
		if ( str_contains( $url, '/token' ) ) {
			parse_str( $body, $p );
			$this->lastTokenForm = $p;
			if ( 'urn:ietf:params:oauth:grant-type:device_code' === ( $p['grant_type'] ?? '' ) ) {
				if ( 'pending' === $this->device ) {
					return bbk_reply( str_contains( $url, 'microsoftonline' ) ? 400 : 428, '{"error":"authorization_pending"}' );
				}
				if ( 'denied' === $this->device ) {
					return bbk_reply( 400, str_contains( $url, 'microsoftonline' ) ? '{"error":"authorization_declined"}' : '{"error":"access_denied"}' );
				}
				return bbk_reply( 200, json_encode( array( 'access_token' => $this->access, 'refresh_token' => $this->refresh, 'expires_in' => 3600 ) ) );
			}
			if ( 'refresh_token' === ( $p['grant_type'] ?? '' ) ) {
				if ( ( $p['refresh_token'] ?? '' ) !== $this->refresh ) {
					return bbk_reply( 400, '{"error":"invalid_grant","error_description":"Token has been revoked."}' );
				}
				++$this->refreshes;
				$this->access = 'access-' . ( $this->refreshes + 1 );
				return bbk_reply( 200, json_encode( array( 'access_token' => $this->access, 'expires_in' => 3600 ) ) );
			}
			return bbk_reply( 200, json_encode( array( 'access_token' => $this->access, 'refresh_token' => $this->refresh, 'expires_in' => 3600 ) ) );
		}
		$isSession = isset( $this->sessions[ $url ] );
		if ( ! $isSession && ! str_contains( $url, 'download/' ) && ( $h['authorization'] ?? '' ) !== 'Bearer ' . $this->access ) {
			return bbk_reply( 401, '{"error":{"message":"Invalid credentials"}}' );
		}
		if ( $this->fail503 > 0 && 'PUT' === $method ) {
			--$this->fail503;
			return bbk_reply( 503, 'busy', array( 'retry-after' => '0' ) );
		}
		if ( str_contains( $url, 'dropboxapi.com' ) ) {
			return $this->dropbox( $url, $h, $body );
		}
		return str_contains( $url, 'graph.microsoft.com' ) || str_contains( $url, 'upload.onedrive' ) || str_contains( $url, 'download/od' ) ? $this->graph( $method, $url, $h, $body ) : $this->google( $method, $url, $h, $body );
	}

	private function id(): string {
		return bin2hex( random_bytes( 6 ) );
	}

	/** Applies a chunk to a session; returns the next expected byte, or null when the range is wrong. */
	private function chunk( string $url, array $h, string $body ): ?int {
		$s = &$this->sessions[ $url ];
		if ( -1 === $s['size'] && preg_match( '#/(\d+)$#', (string) ( $h['content-range'] ?? '' ), $t ) ) {
			$s['size'] = (int) $t[1];
		}
		if ( ! preg_match( '#^bytes (\d+)-(\d+)/(\d+)$#', (string) ( $h['content-range'] ?? '' ), $m ) || (int) $m[1] !== strlen( $s['data'] ) || (int) $m[2] - (int) $m[1] + 1 !== strlen( $body ) || (int) $m[3] !== $s['size'] ) {
			return null;
		}
		$s['data'] .= $body;
		return strlen( $s['data'] );
	}

	/** Dropbox: RPC calls with JSON bodies, content calls with a Dropbox-API-Arg header. */
	private function dropbox( string $url, array $h, string $body ): array {
		$path = (string) parse_url( $url, PHP_URL_PATH );
		$arg  = json_decode( (string) ( $h['dropbox-api-arg'] ?? 'null' ), true );
		$in   = json_decode( $body, true );
		$err  = static fn( string $tag, array $extra = array() ): array => bbk_reply( 409, json_encode( array( 'error_summary' => $tag . '/', 'error' => array( '.tag' => $tag ) + $extra ) ) );
		switch ( $path ) {
			case '/2/users/get_current_account':
				return bbk_reply( 200, '{"email":"owner@example.org"}' );
			case '/2/files/upload_session/start':
				$id                         = 'sess-' . $this->id();
				$this->sessions[ 'dbx:' . $id ] = array( 'name' => '', 'size' => -1, 'data' => $body, 'parent' => '' );
				return bbk_reply( 200, json_encode( array( 'session_id' => $id ) ) );
			case '/2/files/upload_session/append_v2':
			case '/2/files/upload_session/finish':
				$k = 'dbx:' . $arg['cursor']['session_id'];
				if ( ! isset( $this->sessions[ $k ] ) ) {
					return $err( 'not_found' );
				}
				if ( (int) $arg['cursor']['offset'] !== strlen( $this->sessions[ $k ]['data'] ) ) {
					return $err( 'incorrect_offset', array( 'correct_offset' => strlen( $this->sessions[ $k ]['data'] ) ) );
				}
				$this->sessions[ $k ]['data'] .= $body;
				if ( str_ends_with( $path, 'finish' ) ) {
					$id                 = 'id:' . $this->id();
					$this->files[ $id ] = array( 'name' => basename( $arg['commit']['path'] ), 'data' => $this->sessions[ $k ]['data'], 'parent' => dirname( $arg['commit']['path'] ), 'created' => time() );
					unset( $this->sessions[ $k ] );
					return bbk_reply( 200, json_encode( array( 'id' => $id, 'name' => basename( $arg['commit']['path'] ) ) ) );
				}
				if ( $this->loseAnswer > 0 ) {
					--$this->loseAnswer;
					return bbk_reply( 504, 'answer lost' );
				}
				return bbk_reply( 200, 'null' );
			case '/2/files/list_folder':
			case '/2/files/list_folder/continue':
				$folder = isset( $in['cursor'] ) ? explode( '|', (string) $in['cursor'] )[0] : (string) $in['path'];
				$from   = isset( $in['cursor'] ) ? (int) explode( '|', (string) $in['cursor'] )[1] : 0;
				$all    = array_values( array_filter( array_map( static fn( $id, $f ) => $f + array( 'id' => $id ), array_keys( $this->files ), $this->files ), static fn( $f ) => $f['parent'] === $folder ) );
				if ( ! $all && 0 === $from ) {
					return $err( 'path', array( 'path' => array( '.tag' => 'not_found' ) ) );
				}
				$page = array_slice( $all, $from, $this->pageSize );
				return bbk_reply( 200, json_encode( array( 'entries' => array_map( static fn( $f ) => array( '.tag' => 'file', 'id' => $f['id'], 'name' => $f['name'], 'size' => strlen( $f['data'] ), 'server_modified' => gmdate( 'c', $f['created'] ) ), $page ), 'has_more' => $from + $this->pageSize < count( $all ), 'cursor' => $folder . '|' . ( $from + $this->pageSize ) ) ) );
			case '/2/files/get_metadata':
				return isset( $this->files[ $in['path'] ] ) ? bbk_reply( 200, json_encode( array( 'size' => strlen( $this->files[ $in['path'] ]['data'] ) ) ) ) : $err( 'path' );
			case '/2/files/download':
				preg_match( '/bytes=(\d+)-(\d+)/', (string) $h['range'], $r );
				return bbk_reply( 206, substr( $this->files[ $arg['path'] ]['data'], (int) $r[1], (int) $r[2] - (int) $r[1] + 1 ) );
			case '/2/files/delete_v2':
				unset( $this->files[ $in['path'] ] );
				return bbk_reply( 200, '{}' );
		}
		return bbk_reply( 404, 'no route ' . $path );
	}

	private function google( string $method, string $url, array $h, string $body ): array {
		$path = (string) parse_url( $url, PHP_URL_PATH );
		parse_str( (string) parse_url( $url, PHP_URL_QUERY ), $q );
		if ( isset( $this->sessions[ $url ] ) ) {
			$s = $this->sessions[ $url ];
			if ( preg_match( '#^bytes \*/\d+$#', (string) ( $h['content-range'] ?? '' ) ) ) {
				return '' === $s['data'] ? bbk_reply( 308 ) : bbk_reply( 308, '', array( 'range' => 'bytes=0-' . ( strlen( $s['data'] ) - 1 ) ) );
			}
			$next = $this->chunk( $url, $h, $body );
			if ( null === $next ) {
				return bbk_reply( 400, 'bad range' );
			}
			if ( $next === $s['size'] ) {
				$id                 = $this->id();
				$this->files[ $id ] = array( 'name' => $s['name'], 'data' => $this->sessions[ $url ]['data'], 'parent' => $s['parent'], 'created' => time() );
				unset( $this->sessions[ $url ] );
				return bbk_reply( 200, json_encode( array( 'id' => $id ) ) );
			}
			if ( $this->loseAnswer > 0 ) {
				--$this->loseAnswer;
				return bbk_reply( 502, 'answer lost' );
			}
			return bbk_reply( 308, '', array( 'range' => 'bytes=0-' . ( $next - 1 ) ) );
		}
		if ( 'POST' === $method && str_ends_with( $path, '/upload/drive/v3/files' ) ) {
			$meta                    = json_decode( $body, true );
			$sess                    = 'https://www.googleapis.com/upload/drive/v3/files?upload_id=' . $this->id();
			$this->sessions[ $sess ] = array( 'name' => $meta['name'], 'size' => (int) $h['x-upload-content-length'], 'data' => '', 'parent' => $meta['parents'][0] );
			return bbk_reply( 200, '', array( 'location' => $sess ) );
		}
		if ( 'GET' === $method && str_ends_with( $path, '/about' ) ) {
			return bbk_reply( 200, '{"user":{"emailAddress":"owner@example.org"}}' );
		}
		if ( 'POST' === $method && str_ends_with( $path, '/drive/v3/files' ) ) {
			$meta               = json_decode( $body, true );
			$id                 = 'folder-' . $this->id();
			$this->files[ $id ] = array( 'name' => $meta['name'], 'data' => '', 'parent' => 'root', 'created' => time(), 'folder' => true );
			return bbk_reply( 200, json_encode( array( 'id' => $id ) ) );
		}
		if ( 'GET' === $method && str_ends_with( $path, '/drive/v3/files' ) ) {
			if ( preg_match( "/name = '(.+)' and mimeType/", (string) $q['q'], $m ) ) {
				foreach ( $this->files as $id => $f ) {
					if ( ! empty( $f['folder'] ) && $f['name'] === stripslashes( $m[1] ) ) {
						return bbk_reply( 200, json_encode( array( 'files' => array( array( 'id' => $id ) ) ) ) );
					}
				}
				return bbk_reply( 200, '{"files":[]}' );
			}
			preg_match( "/^'(.+)' in parents/", (string) $q['q'], $m );
			$all  = array_values( array_filter( array_map( static fn( $id, $f ) => $f + array( 'id' => $id ), array_keys( $this->files ), $this->files ), static fn( $f ) => $f['parent'] === $m[1] && empty( $f['folder'] ) ) );
			$from = (int) ( $q['pageToken'] ?? 0 );
			$page = array_slice( $all, $from, $this->pageSize );
			$out  = array( 'files' => array_map( static fn( $f ) => array( 'id' => $f['id'], 'name' => $f['name'], 'size' => (string) strlen( $f['data'] ), 'createdTime' => gmdate( 'c', $f['created'] ) ), $page ) );
			if ( $from + $this->pageSize < count( $all ) ) {
				$out['nextPageToken'] = (string) ( $from + $this->pageSize );
			}
			return bbk_reply( 200, json_encode( $out ) );
		}
		if ( preg_match( '#/drive/v3/files/([^/]+)$#', $path, $m ) ) {
			$f = $this->files[ $m[1] ] ?? null;
			if ( ! $f ) {
				return bbk_reply( 404 );
			}
			if ( 'DELETE' === $method ) {
				unset( $this->files[ $m[1] ] );
				return bbk_reply( 204 );
			}
			if ( 'media' === ( $q['alt'] ?? '' ) ) {
				preg_match( '/bytes=(\d+)-(\d+)/', (string) $h['range'], $r );
				return bbk_reply( 206, substr( $f['data'], (int) $r[1], (int) $r[2] - (int) $r[1] + 1 ) );
			}
			return bbk_reply( 200, json_encode( array( 'size' => (string) strlen( $f['data'] ), 'name' => $f['name'] ) ) );
		}
		return bbk_reply( 404, 'no route ' . $method . ' ' . $path );
	}

	private function graph( string $method, string $url, array $h, string $body ): array {
		$path = rawurldecode( (string) parse_url( $url, PHP_URL_PATH ) );
		if ( isset( $this->sessions[ $url ] ) ) {
			$s = $this->sessions[ $url ];
			if ( isset( $h['authorization'] ) ) {
				return bbk_reply( 401, 'upload URLs take no Authorization header' );
			}
			if ( 'GET' === $method ) {
				return bbk_reply( 200, json_encode( array( 'nextExpectedRanges' => array( strlen( $this->sessions[ $url ]['data'] ) . '-' ) ) ) );
			}
			$next = $this->chunk( $url, $h, $body );
			if ( null === $next ) {
				return bbk_reply( 416, 'bad range' );
			}
			$s = $this->sessions[ $url ];
			if ( $next === $s['size'] ) {
				$id                 = $this->id();
				$this->files[ $id ] = array( 'name' => $s['name'], 'data' => $this->sessions[ $url ]['data'], 'parent' => $s['parent'], 'created' => time() );
				unset( $this->sessions[ $url ] );
				return bbk_reply( 201, json_encode( array( 'id' => $id ) ) );
			}
			if ( $this->loseAnswer > 0 ) {
				--$this->loseAnswer;
				return bbk_reply( 504, 'answer lost' );
			}
			return bbk_reply( 202, json_encode( array( 'nextExpectedRanges' => array( $next . '-' ) ) ) );
		}
		if ( str_ends_with( $path, '/me' ) ) {
			return bbk_reply( 200, '{"mail":"owner@example.org"}' );
		}
		if ( preg_match( '#/me/drive/special/approot:/([^/]+)/([^/:]+):/createUploadSession$#', $path, $m ) ) {
			$sess                    = 'https://upload.onedrive.example/up/' . $this->id();
			$this->sessions[ $sess ] = array( 'name' => $m[2], 'size' => 0, 'data' => '', 'parent' => $m[1] );
			// Graph learns the size from the first chunk's Content-Range.
			$this->sessions[ $sess ]['size'] = -1;
			return bbk_reply( 200, json_encode( array( 'uploadUrl' => $sess ) ) );
		}
		if ( preg_match( '#/me/drive/special/approot:/([^/:]+):/children$#', $path, $m ) ) {
			$all  = array_values( array_filter( array_map( static fn( $id, $f ) => $f + array( 'id' => $id ), array_keys( $this->files ), $this->files ), static fn( $f ) => $f['parent'] === $m[1] ) );
			parse_str( (string) parse_url( $url, PHP_URL_QUERY ), $q );
			$from = (int) ( $q['skip'] ?? 0 );
			$out  = array( 'value' => array_map( static fn( $f ) => array( 'id' => $f['id'], 'name' => $f['name'], 'size' => strlen( $f['data'] ), 'createdDateTime' => gmdate( 'c', $f['created'] ) ), array_slice( $all, $from, $this->pageSize ) ) );
			if ( $from + $this->pageSize < count( $all ) ) {
				$out['@odata.nextLink'] = 'https://graph.microsoft.com/v1.0/me/drive/special/approot:/' . rawurlencode( $m[1] ) . ':/children?skip=' . ( $from + $this->pageSize );
			}
			return bbk_reply( 200, json_encode( $out ) );
		}
		if ( str_ends_with( $path, '/me/drive/special/approot' ) ) {
			return bbk_reply( 200, '{"id":"approot"}' );
		}
		if ( preg_match( '#/me/drive/items/([^/]+)$#', $path, $m ) ) {
			if ( ! isset( $this->files[ $m[1] ] ) ) {
				return bbk_reply( 404 );
			}
			if ( 'DELETE' === $method ) {
				unset( $this->files[ $m[1] ] );
				return bbk_reply( 204 );
			}
			return bbk_reply( 200, json_encode( array( 'id' => $m[1], 'size' => strlen( $this->files[ $m[1] ]['data'] ), '@microsoft.graph.downloadUrl' => 'https://public.example/download/od/' . $m[1] ) ) );
		}
		if ( preg_match( '#/download/od/(.+)$#', $path, $m ) ) {
			preg_match( '/bytes=(\d+)-(\d+)/', (string) $h['range'], $r );
			return bbk_reply( 206, substr( $this->files[ $m[1] ]['data'], (int) $r[1], (int) $r[2] - (int) $r[1] + 1 ) );
		}
		return bbk_reply( 404, 'no route ' . $method . ' ' . $path );
	}
}
