<?php
/**
 * Resumable directory walker. Produces a manifest list file (one line per entry):
 *   F\t<size>\t<rawurlencoded archive path>
 *   D\t0\t<rawurlencoded archive path>
 * Archive paths are "<rootKey>/<relative>" for extra roots, or plain relative for the main root.
 * Never follows symlinks (prevents escaping the WordPress directory and loops).
 */

namespace BastionBackup\Core;

defined( 'ABSPATH' ) || defined( 'BBK_INSTALLER' ) || exit;

final class FileScanner {

	/**
	 * @param array<string,string> $roots    rootKey => absolute dir ('' is the WordPress root).
	 * @param string[]             $excludes Archive-path prefixes or fnmatch patterns.
	 */
	public function __construct(
		private array $roots,
		private array $excludes = array(),
		private int $maxFileSize = 0,
	) {
		$this->excludes = array_values(
			array_filter( array_map( static fn( $x ) => trim( str_replace( '\\', '/', (string) $x ), '/ ' ), $excludes ) )
		);
	}

	public function initState(): array {
		$queue = array();
		foreach ( array_keys( $this->roots ) as $k ) {
			$queue[] = array( (string) $k, '' );
		}
		return array(
			'queue'   => $queue,
			'files'   => 0,
			'dirs'    => 0,
			'bytes'   => 0,
			'skipped' => 0,
		);
	}

	public function step( array &$st, string $listFile, float $deadline ): bool {
		$out = fopen( $listFile, 'ab' );
		if ( ! $out ) {
			throw new BackupException( 'Cannot write file list.' );
		}
		while ( $st['queue'] ) {
			if ( microtime( true ) > $deadline ) {
				break;
			}
			[ $key, $rel ] = array_pop( $st['queue'] );
			$base          = rtrim( $this->roots[ $key ], '/\\' );
			$abs           = '' === $rel ? $base : $base . '/' . $rel;
			$dh            = @opendir( $abs );
			if ( ! $dh ) {
				++$st['skipped'];
				continue;
			}
			while ( false !== ( $e = readdir( $dh ) ) ) {
				if ( '.' === $e || '..' === $e ) {
					continue;
				}
				$r    = '' === $rel ? $e : $rel . '/' . $e;
				$a    = $abs . '/' . $e;
				$arch = '' === $key ? $r : $key . '/' . $r;
				if ( $this->excluded( $arch ) ) {
					continue;
				}
				if ( is_link( $a ) ) {
					++$st['skipped'];
					continue;
				}
				if ( is_dir( $a ) ) {
					$st['queue'][] = array( $key, $r );
					continue;
				}
				if ( ! is_file( $a ) || ! is_readable( $a ) ) {
					++$st['skipped'];
					continue;
				}
				$size = (int) @filesize( $a );
				if ( $this->maxFileSize > 0 && $size > $this->maxFileSize ) {
					++$st['skipped'];
					continue;
				}
				fwrite( $out, "F\t" . $size . "\t" . rawurlencode( $arch ) . "\n" );
				++$st['files'];
				$st['bytes'] += $size;
			}
			closedir( $dh );
			if ( '' !== $rel ) {
				$arch = '' === $key ? $rel : $key . '/' . $rel;
				fwrite( $out, "D\t0\t" . rawurlencode( $arch ) . "\n" );
				++$st['dirs'];
			}
		}
		fclose( $out );
		return ! $st['queue'];
	}

	public function excluded( string $path ): bool {
		foreach ( $this->excludes as $x ) {
			if ( str_contains( $x, '*' ) || str_contains( $x, '?' ) ) {
				if ( fnmatch( $x, $path ) || fnmatch( $x, basename( $path ) ) ) {
					return true;
				}
			} elseif ( $path === $x || str_starts_with( $path, $x . '/' ) ) {
				return true;
			}
		}
		return false;
	}

	/** Parse one list line. @return array{0:string,1:int,2:string}|null */
	public static function parseLine( string $line ): ?array {
		$parts = explode( "\t", rtrim( $line, "\r\n" ), 3 );
		if ( 3 !== count( $parts ) ) {
			return null;
		}
		return array( $parts[0], (int) $parts[1], rawurldecode( $parts[2] ) );
	}
}
