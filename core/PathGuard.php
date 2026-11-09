<?php
/**
 * Path safety for extraction: blocks traversal ("../"), absolute paths, NUL bytes,
 * drive letters and symlinked directories that escape the target root.
 */

namespace BastionBackup\Core;

defined( 'ABSPATH' ) || defined( 'BBK_INSTALLER' ) || exit;

final class PathGuard {

	public static function normalize( string $rel ): string {
		if ( str_contains( $rel, "\0" ) ) {
			throw new BackupException( 'Rejected path containing NUL byte.' );
		}
		$rel = str_replace( '\\', '/', $rel );
		if ( str_starts_with( $rel, '/' ) || preg_match( '#^[A-Za-z]:#', $rel ) ) {
			throw new BackupException( 'Rejected absolute path in archive: ' . $rel );
		}
		$out = array();
		foreach ( explode( '/', $rel ) as $part ) {
			if ( '' === $part || '.' === $part ) {
				continue;
			}
			if ( '..' === $part ) {
				throw new BackupException( 'Rejected path traversal in archive: ' . $rel );
			}
			$out[] = $part;
		}
		if ( ! $out ) {
			throw new BackupException( 'Rejected empty path in archive.' );
		}
		return implode( '/', $out );
	}

	/** Create $dir (recursively) and prove its real location is inside $root. */
	public static function ensureDirInside( string $root, string $dir ): void {
		if ( ! is_dir( $dir ) && ! @mkdir( $dir, 0755, true ) && ! is_dir( $dir ) ) {
			throw new BackupException( 'Cannot create directory: ' . $dir );
		}
		self::assertInside( $root, $dir );
	}

	public static function assertInside( string $root, string $path ): void {
		$realRoot = realpath( $root );
		$real     = realpath( $path );
		if ( false === $realRoot || false === $real ) {
			throw new BackupException( 'Cannot resolve path: ' . $path );
		}
		$realRoot = rtrim( str_replace( '\\', '/', $realRoot ), '/' ) . '/';
		$real     = rtrim( str_replace( '\\', '/', $real ), '/' ) . '/';
		if ( ! str_starts_with( $real, $realRoot ) ) {
			throw new BackupException( 'Blocked write outside the WordPress directory: ' . $path );
		}
	}
}
