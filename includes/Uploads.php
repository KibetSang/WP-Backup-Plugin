<?php
/**
 * A backup file sent from the administrator's computer for restoring, in 8 MiB pieces, so a 10 GB file arrives
 * whatever the server's upload_max_filesize. Pieces must come in order; the file lives in the protected tmp folder.
 *
 * @package BastionBackup
 */

namespace BastionBackup;

use BastionBackup\Core\BackupException;

defined( 'ABSPATH' ) || exit;

final class Uploads {

	public const PIECE = 8388608;
	private const MAX  = 107374182400; // 100 GB.

	private static function base( string $id ): string {
		if ( ! preg_match( '/^[a-f0-9]{24}$/', $id ) ) {
			throw new BackupException( 'Invalid upload.' );
		}
		return Storage::dir( 'tmp' ) . '/up-' . $id;
	}

	public static function begin( string $name, int $size ): string {
		if ( ! preg_match( '/\.bbk$/i', $name ) ) {
			throw new BackupException( 'Choose a .bbk backup file.' );
		}
		if ( $size < 64 || $size > self::MAX ) {
			throw new BackupException( 'That file is not a backup of a size this plugin can take.' );
		}
		$free = Storage::freeSpace();
		if ( null !== $free && $free < $size + 67108864 ) {
			throw new BackupException( sprintf( 'Not enough disk space for the upload: it needs %s and %s is free.', size_format( $size ), size_format( (int) $free ) ) );
		}
		$id = bin2hex( random_bytes( 12 ) );
		file_put_contents( self::base( $id ) . '.json', (string) wp_json_encode( array( 'name' => sanitize_file_name( $name ), 'size' => $size, 'user' => get_current_user_id(), 'started' => time() ) ) );
		touch( self::base( $id ) . '.bbk' );
		chmod( self::base( $id ) . '.bbk', 0600 );
		return $id;
	}

	private static function meta( string $id ): array {
		$f = self::base( $id ) . '.json';
		$m = is_file( $f ) ? json_decode( (string) file_get_contents( $f ), true ) : null;
		if ( ! is_array( $m ) || (int) $m['user'] !== get_current_user_id() ) {
			throw new BackupException( 'That upload was not found. Start it again.' );
		}
		return $m;
	}

	/** Appends a piece at $offset; returns bytes received so far. A repeated piece (after a lost reply) is accepted. */
	public static function piece( string $id, int $offset, string $data ): int {
		$m    = self::meta( $id );
		$file = self::base( $id ) . '.bbk';
		clearstatcache( true, $file );
		$have = (int) filesize( $file );
		if ( $offset + strlen( $data ) <= $have ) {
			return $have; // Already here.
		}
		if ( $offset !== $have || strlen( $data ) > self::PIECE || $have + strlen( $data ) > (int) $m['size'] ) {
			throw new BackupException( 'The upload got out of step. Start it again.' );
		}
		if ( false === file_put_contents( $file, $data, FILE_APPEND | LOCK_EX ) ) {
			throw new BackupException( 'Write failed — the disk may be full.' );
		}
		return $have + strlen( $data );
	}

	/** The whole uploaded file, once every byte is in. */
	public static function assembled( string $id ): string {
		$m    = self::meta( $id );
		$file = self::base( $id ) . '.bbk';
		clearstatcache( true, $file );
		if ( (int) filesize( $file ) !== (int) $m['size'] ) {
			throw new BackupException( 'The upload is not complete.' );
		}
		unlink( self::base( $id ) . '.json' );
		return $file;
	}

	/** Removes uploads left unfinished for a day. */
	public static function sweep(): void {
		foreach ( (array) glob( Storage::dir( 'tmp' ) . '/up-*' ) as $f ) {
			if ( is_file( $f ) && filemtime( $f ) < time() - DAY_IN_SECONDS ) {
				unlink( $f );
			}
		}
	}
}
