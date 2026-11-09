<?php
/**
 * Your own file server: SFTP (SSH) or FTPS (FTP over TLS), through PHP's curl. Plain FTP is not offered: it would send
 * the password unencrypted. SFTP pins the server's host key (its SHA-256 or MD5 fingerprint) so a server pretending to
 * be yours is refused; FTPS always checks the certificate.
 *
 * Uploads go to "<name>.part", appended to step by step (each step first asks the server how much it has), then
 * renamed when complete, so a half-uploaded backup is never mistaken for a whole one.
 *
 * @package BastionBackup
 */

namespace BastionBackup\Destinations;

use BastionBackup\Core\BackupException;
use BastionBackup\Settings;
use BastionBackup\Storage;
use BastionBackup\Vault;

defined( 'ABSPATH' ) || exit;

final class FileServer implements Destination {

	private const CHUNK = 8388608;

	public function id(): string {
		return 'fileserver';
	}

	public function label(): string {
		return 'File server (SFTP / FTPS)';
	}

	public function config(): array {
		return array_merge(
			array(
				'protocol'    => 'sftp',
				'host'        => '',
				'port'        => 0,
				'path'        => '',
				'username'    => '',
				'auth'        => 'password',
				'fingerprint' => '',
			),
			Settings::destination( $this->id() )
		);
	}

	private function secrets(): array {
		$s = Vault::get( 'dest_fileserver', array() );
		return is_array( $s ) ? $s : array();
	}

	/** Saves the connection. Empty secret fields keep what is saved. */
	public function save( array $in ): void {
		$protocol = in_array( $in['protocol'] ?? '', array( 'sftp', 'ftps', 'ftps-implicit' ), true ) ? $in['protocol'] : 'sftp';
		$host     = strtolower( trim( (string) ( $in['host'] ?? '' ) ) );
		if ( '' !== $host && ! preg_match( '/^[a-z0-9.-]{1,253}$|^\[[0-9a-f:]+\]$/', $host ) ) {
			throw new BackupException( 'Enter the server name or IP address only, without sftp:// or a path.' );
		}
		$path = trim( str_replace( '\\', '/', (string) ( $in['path'] ?? '' ) ) );
		if ( str_contains( $path, '..' ) || preg_match( '/[\x00-\x1f]/', $path ) ) {
			throw new BackupException( 'The folder cannot contain ".." or control characters.' );
		}
		$fp = trim( (string) ( $in['fingerprint'] ?? '' ) );
		if ( '' !== $fp && ! self::parseFingerprint( $fp ) ) {
			throw new BackupException( 'The host key fingerprint should look like SHA256:AbC…= (from ssh-keyscan) or 32 hexadecimal characters (MD5).' );
		}
		Settings::setDestination(
			$this->id(),
			array(
				'protocol'    => $protocol,
				'host'        => $host,
				'port'        => max( 0, min( 65535, (int) ( $in['port'] ?? 0 ) ) ),
				'path'        => trim( $path, '/' ) === '' ? '' : ( str_starts_with( $path, '/' ) ? '/' : '' ) . trim( $path, '/' ),
				'username'    => sanitize_text_field( (string) ( $in['username'] ?? '' ) ),
				'auth'        => 'key' === ( $in['auth'] ?? '' ) ? 'key' : 'password',
				'fingerprint' => $fp,
			)
		);
		$s = $this->secrets();
		foreach ( array( 'password', 'private_key', 'key_passphrase' ) as $k ) {
			if ( isset( $in[ $k ] ) && '' !== (string) $in[ $k ] ) {
				$s[ $k ] = (string) $in[ $k ];
			}
		}
		Vault::set( 'dest_fileserver', $s );
	}

	public function forget(): void {
		Settings::setDestination( $this->id(), array() );
		Vault::set( 'dest_fileserver', null );
	}

	public function hasSecret(): bool {
		$s = $this->secrets();
		return 'key' === $this->config()['auth'] ? ! empty( $s['private_key'] ) : ! empty( $s['password'] );
	}

	public function isConfigured(): bool {
		$c = $this->config();
		return '' !== $c['host'] && '' !== $c['username'] && $this->hasSecret() && ( 'sftp' !== $c['protocol'] || null !== self::parseFingerprint( (string) $c['fingerprint'] ) ) && function_exists( 'curl_init' );
	}

	/** @return array{0:string,1:string}|null [type sha256|md5, value] */
	public static function parseFingerprint( string $fp ): ?array {
		$fp = trim( $fp );
		if ( preg_match( '/^(?:SHA256:)?([A-Za-z0-9+\/]{43})=?$/', $fp, $m ) ) {
			return array( 'sha256', $m[1] );
		}
		$hex = strtolower( str_replace( array( ':', 'MD5', 'md5' ), '', $fp ) );
		return preg_match( '/^[0-9a-f]{32}$/', $hex ) ? array( 'md5', $hex ) : null;
	}

	private function scheme(): string {
		return 'sftp' === $this->config()['protocol'] ? 'sftp' : ( 'ftps-implicit' === $this->config()['protocol'] ? 'ftps' : 'ftp' );
	}

	/** The folder's URL, ending in "/". SFTP paths without a leading "/" are inside the login's home folder. */
	private function dirUrl(): string {
		$c    = $this->config();
		$port = (int) $c['port'] ?: ( 'sftp' === $c['protocol'] ? 22 : ( 'ftps-implicit' === $c['protocol'] ? 990 : 21 ) );
		$path = (string) $c['path'];
		$segs = array_map( 'rawurlencode', array_filter( explode( '/', $path ), 'strlen' ) );
		if ( 'sftp' === $c['protocol'] ) {
			$p = str_starts_with( $path, '/' ) ? '/' . implode( '/', $segs ) : '/~' . ( $segs ? '/' . implode( '/', $segs ) : '' );
		} else {
			$p = ( str_starts_with( $path, '/' ) ? '/%2F' : '/' ) . implode( '/', $segs ); // %2F: an absolute FTP path.
		}
		return $this->scheme() . '://' . $c['host'] . ':' . $port . rtrim( $p, '/' ) . '/';
	}

	/** A file's path as quote commands see it (SFTP: relative to home unless absolute). */
	private function quotePath( string $name ): string {
		$path = (string) $this->config()['path'];
		return ( '' === trim( $path, '/' ) ? '' : rtrim( $path, '/' ) . '/' ) . $name;
	}

	/**
	 * One curl transfer with the connection's security settings.
	 *
	 * @param array<int,mixed> $opts
	 * @return array{ok:bool, error:string, body:string, info:array}
	 */
	private function curl( string $url, array $opts ): array {
		if ( ! function_exists( 'curl_init' ) ) {
			throw new BackupException( 'This server\'s PHP has no curl extension, which the file server destination needs.' );
		}
		$c       = $this->config();
		$s       = $this->secrets();
		$keyFile = null;
		$h       = curl_init( $url );
		$base    = array(
			CURLOPT_USERNAME          => (string) $c['username'],
			CURLOPT_CONNECTTIMEOUT    => 20,
			CURLOPT_LOW_SPEED_LIMIT   => 1024,
			CURLOPT_LOW_SPEED_TIME    => 60,
			CURLOPT_NOSIGNAL          => true,
			CURLOPT_PROTOCOLS         => 'sftp' === $c['protocol'] ? CURLPROTO_SFTP : ( CURLPROTO_FTP | CURLPROTO_FTPS ),
			CURLOPT_REDIR_PROTOCOLS   => 0,
			CURLOPT_FOLLOWLOCATION    => false,
		);
		if ( 'sftp' === $c['protocol'] ) {
			$fp = self::parseFingerprint( (string) $c['fingerprint'] );
			if ( ! $fp ) {
				throw new BackupException( 'Enter the server\'s host key fingerprint, so a server pretending to be yours is refused.' );
			}
			if ( 'sha256' === $fp[0] ) {
				if ( ! defined( 'CURLOPT_SSH_HOST_PUBLIC_KEY_SHA256' ) ) {
					throw new BackupException( 'This server\'s PHP cannot check SHA-256 host keys; enter the MD5 fingerprint instead (ssh-keygen -l -E md5).' );
				}
				$base[ constant( 'CURLOPT_SSH_HOST_PUBLIC_KEY_SHA256' ) ] = $fp[1];
			} else {
				$base[ CURLOPT_SSH_HOST_PUBLIC_KEY_MD5 ] = $fp[1];
			}
			if ( 'key' === $c['auth'] ) {
				$keyFile = Storage::dir( 'tmp' ) . '/k-' . bin2hex( random_bytes( 8 ) );
				$fh      = fopen( $keyFile, 'xb' );
				if ( ! $fh ) {
					throw new BackupException( 'Cannot prepare the SSH key.' );
				}
				chmod( $keyFile, 0600 );
				fwrite( $fh, (string) ( $s['private_key'] ?? '' ) );
				fclose( $fh );
				$base[ CURLOPT_SSH_AUTH_TYPES ]      = CURLSSH_AUTH_PUBLICKEY;
				$base[ CURLOPT_SSH_PRIVATE_KEYFILE ] = $keyFile;
				if ( ! empty( $s['key_passphrase'] ) ) {
					$base[ CURLOPT_KEYPASSWD ] = (string) $s['key_passphrase'];
				}
			} else {
				$base[ CURLOPT_SSH_AUTH_TYPES ] = CURLSSH_AUTH_PASSWORD;
				$base[ CURLOPT_PASSWORD ]       = (string) ( $s['password'] ?? '' );
			}
		} else {
			$base[ CURLOPT_PASSWORD ]       = (string) ( $s['password'] ?? '' );
			$base[ CURLOPT_USE_SSL ]        = CURLUSESSL_ALL; // TLS for the login and the data, or nothing.
			$base[ CURLOPT_SSL_VERIFYPEER ] = true;
			$base[ CURLOPT_SSL_VERIFYHOST ] = 2;
			$base[ CURLOPT_FTP_SSL_CCC ]    = CURLFTPSSL_CCC_NONE;
		}
		curl_setopt_array( $h, $opts + $base );
		try {
			$body = curl_exec( $h );
			$err  = curl_errno( $h ) ? curl_error( $h ) : '';
			return array(
				'ok'    => '' === $err,
				'error' => $err,
				'body'  => is_string( $body ) ? $body : '',
				'info'  => (array) curl_getinfo( $h ),
			);
		} finally {
			if ( $keyFile ) {
				@unlink( $keyFile );
			}
		}
	}

	private function fail( string $what, array $r ): BackupException {
		return new BackupException( $what . ' on the file server failed: ' . $r['error'] );
	}

	/** The size of a remote file, or null if it is not there. */
	private function remoteSize( string $name ): ?int {
		$r = $this->curl( $this->dirUrl() . rawurlencode( $name ), array( CURLOPT_NOBODY => true, CURLOPT_RETURNTRANSFER => true ) );
		if ( ! $r['ok'] ) {
			return null;
		}
		$n = (int) ( $r['info']['download_content_length'] ?? -1 );
		return $n >= 0 ? $n : null;
	}

	public function test(): true|string {
		try {
			if ( ! $this->isConfigured() ) {
				return 'Enter the server, the login and its password or key' . ( 'sftp' === $this->config()['protocol'] ? ', and the host key fingerprint' : '' ) . '.';
			}
			// Prove the login can write, read the size back and delete, in the backup folder.
			$name  = '.bastion-test-' . bin2hex( random_bytes( 4 ) );
			$data  = 'Bastion Backup connection test';
			$state = array();
			$tmp   = Storage::dir( 'tmp' ) . '/' . $name;
			file_put_contents( $tmp, $data );
			try {
				$this->upload( $tmp, $name, $state, microtime( true ) + 30 );
			} finally {
				@unlink( $tmp );
			}
			if ( strlen( $data ) !== $this->remoteSize( $name ) ) {
				return 'The test file was written but could not be read back.';
			}
			$this->delete( $name );
			return true;
		} catch ( BackupException $e ) {
			return $e->getMessage();
		}
	}

	public function upload( string $localPath, string $remoteName, array &$state, float $deadline ): bool {
		$size = (int) filesize( $localPath );
		$part = $remoteName . '.part';
		if ( ! isset( $state['off'] ) ) {
			$state = array(
				'off'  => 0,
				'size' => $size,
			);
		} else {
			// What the server really has (a request may have been cut off mid-way).
			$state['off'] = min( $size, (int) ( $this->remoteSize( $part ) ?? 0 ) );
		}
		$fh = fopen( $localPath, 'rb' );
		if ( ! $fh ) {
			throw new BackupException( 'Cannot read the backup file.' );
		}
		try {
			while ( $state['off'] < $size ) {
				fseek( $fh, $state['off'] );
				$sent = 0;
				$r    = $this->curl(
					$this->dirUrl() . rawurlencode( $part ),
					array(
						CURLOPT_UPLOAD                  => true,
						CURLOPT_APPEND                  => $state['off'] > 0,
						CURLOPT_FTP_CREATE_MISSING_DIRS => CURLFTP_CREATE_DIR,
						CURLOPT_NEW_DIRECTORY_PERMS     => 0700,
						CURLOPT_NEW_FILE_PERMS          => 0600,
						CURLOPT_RETURNTRANSFER          => true,
						CURLOPT_READFUNCTION            => static function ( $ch, $in, int $max ) use ( $fh, &$sent, $deadline, $size, $state ): string {
							// Stop at the deadline: what was sent stays, the next step appends the rest.
							if ( microtime( true ) > $deadline || $state['off'] + $sent >= $size ) {
								return '';
							}
							$d     = (string) fread( $fh, (int) min( $max, self::CHUNK, $size - $state['off'] - $sent ) );
							$sent += strlen( $d );
							return $d;
						},
					)
				);
				if ( ! $r['ok'] ) {
					throw $this->fail( 'Uploading', $r );
				}
				$state['off'] += $sent;
				if ( microtime( true ) > $deadline && $state['off'] < $size ) {
					return false;
				}
			}
		} finally {
			fclose( $fh );
		}
		$have = $this->remoteSize( $part );
		if ( $have !== $size ) {
			$state['off'] = (int) $have; // Re-sync and carry on next step.
			if ( null === $have || $have > $size ) {
				$this->delete( $part );
				$state = array();
			}
			return false;
		}
		$sftp = 'sftp' === $this->scheme();
		$r    = $this->curl(
			$this->dirUrl(),
			array(
				CURLOPT_NOBODY         => true,
				CURLOPT_RETURNTRANSFER => true,
				CURLOPT_POSTQUOTE      => $sftp
					? array( 'rename "' . $this->quotePath( $part ) . '" "' . $this->quotePath( $remoteName ) . '"' )
					: array( 'RNFR ' . $part, 'RNTO ' . $remoteName ),
			)
		);
		if ( ! $r['ok'] ) {
			throw $this->fail( 'Finishing the upload', $r );
		}
		$state['id'] = $remoteName;
		return true;
	}

	public function list(): array {
		$r = $this->curl( $this->dirUrl(), array( CURLOPT_RETURNTRANSFER => true ) );
		if ( ! $r['ok'] ) {
			// A folder that does not exist yet simply holds no backups.
			if ( str_contains( strtolower( $r['error'] ), 'no such' ) || str_contains( $r['error'], '550' ) || str_contains( strtolower( $r['error'] ), 'not found' ) ) {
				return array();
			}
			throw $this->fail( 'Listing the folder', $r );
		}
		$out = array();
		foreach ( preg_split( '/\r\n|\n/', $r['body'] ) as $line ) {
			// "ls -l" style lines (SFTP and FTP LIST): size is the 5th field, the name is last.
			$f = preg_split( '/\s+/', trim( (string) $line ) );
			if ( count( $f ) < 9 ) {
				continue;
			}
			$name = (string) end( $f );
			if ( Registry::isOurs( $name ) ) {
				$out[] = array(
					'id'   => $name,
					'name' => $name,
					'size' => (int) $f[4],
					'time' => Registry::timeFromName( $name ),
				);
			}
		}
		usort( $out, static fn( $a, $b ) => $b['time'] <=> $a['time'] );
		return $out;
	}

	public function download( string $remoteId, string $localPath, array &$state, float $deadline ): bool {
		$name = basename( $remoteId );
		if ( ! Registry::isOurs( $name ) ) {
			throw new BackupException( 'Not a backup made by this plugin.' );
		}
		if ( ! isset( $state['size'] ) ) {
			$size = $this->remoteSize( $name );
			if ( null === $size ) {
				throw new BackupException( 'That backup is no longer on the file server.' );
			}
			$state = array(
				'size' => $size,
				'off'  => 0,
			);
		}
		$fh = fopen( $localPath, 'c+b' );
		if ( ! $fh ) {
			throw new BackupException( 'Cannot write the downloaded backup.' );
		}
		try {
			ftruncate( $fh, (int) $state['off'] );
			fseek( $fh, (int) $state['off'] );
			while ( $state['off'] < $state['size'] ) {
				$to = (int) min( $state['size'], $state['off'] + self::CHUNK ) - 1;
				$r  = $this->curl(
					$this->dirUrl() . rawurlencode( $name ),
					array(
						CURLOPT_RANGE         => $state['off'] . '-' . $to,
						CURLOPT_WRITEFUNCTION => static function ( $ch, string $data ) use ( $fh ): int {
							return (int) fwrite( $fh, $data );
						},
					)
				);
				if ( ! $r['ok'] ) {
					throw $this->fail( 'Downloading', $r );
				}
				$state['off'] = (int) ftell( $fh );
				if ( microtime( true ) > $deadline && $state['off'] < $state['size'] ) {
					return false;
				}
			}
			return true;
		} finally {
			fclose( $fh );
		}
	}

	public function delete( string $remoteId ): void {
		$name = basename( $remoteId );
		$sftp = 'sftp' === $this->scheme();
		$r    = $this->curl(
			$this->dirUrl(),
			array(
				CURLOPT_NOBODY         => true,
				CURLOPT_RETURNTRANSFER => true,
				CURLOPT_QUOTE          => $sftp ? array( 'rm "' . $this->quotePath( $name ) . '"' ) : array( 'DELE ' . $name ),
			)
		);
		if ( ! $r['ok'] ) {
			throw $this->fail( 'Deleting', $r );
		}
	}
}
