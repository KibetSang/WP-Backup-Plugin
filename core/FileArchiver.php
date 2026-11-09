<?php
/**
 * Resumable step that streams files from a FileScanner list into an ArchiveWriter.
 * Large files are split across requests mid-file; memory stays at ~1 MiB.
 */

namespace BastionBackup\Core;

defined( 'ABSPATH' ) || defined( 'BBK_INSTALLER' ) || exit;

final class FileArchiver {

	private const CHUNK = 1048576;

	/** Extensions that are already compressed — skip deflate to save CPU. */
	private const INCOMPRESSIBLE = array( 'jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'heic', 'mp4', 'm4v', 'mov', 'webm', 'mkv', 'mp3', 'm4a', 'ogg', 'flac', 'zip', 'gz', 'tgz', 'bz2', 'xz', '7z', 'rar', 'zst', 'woff', 'woff2', 'pdf', 'bbk', 'wpress', 'daf' );

	/** @param array<string,string> $roots rootKey => absolute dir. */
	public function __construct( private array $roots ) {
	}

	public static function initState(): array {
		return array(
			'off'   => 0,      // Byte offset in list file.
			'cur'   => null,   // In-progress large file.
			'files' => 0,
			'bytes' => 0,
			'warn'  => 0,
		);
	}

	public function step( array &$st, string $listFile, ArchiveWriter $w, float $deadline, ?callable $warn = null ): bool {
		if ( $st['cur'] && ! $this->continueFile( $st, $w, $deadline, $warn ) ) {
			return false;
		}
		$lh = fopen( $listFile, 'rb' );
		if ( ! $lh ) {
			throw new BackupException( 'Cannot read file list.' );
		}
		fseek( $lh, $st['off'] );
		try {
			while ( microtime( true ) < $deadline ) {
				$line = fgets( $lh );
				if ( false === $line ) {
					return true;
				}
				$st['off'] = ftell( $lh );
				$e         = FileScanner::parseLine( $line );
				if ( ! $e ) {
					continue;
				}
				[ $type, $size, $path ] = $e;
				if ( 'D' === $type ) {
					$w->record( 'D', array( 'p' => $path ), 0 );
					continue;
				}
				$abs = $this->abs( $path );
				if ( ! is_file( $abs ) || is_link( $abs ) ) {
					if ( $warn ) {
						$warn( 'File vanished during backup, skipped: ' . $path );
					}
					continue;
				}
				clearstatcache( true, $abs );
				$size = (int) filesize( $abs );
				$w->setCompress( ! in_array( strtolower( pathinfo( $path, PATHINFO_EXTENSION ) ), self::INCOMPRESSIBLE, true ) );
				$w->record(
					'F',
					array(
						'p'    => $path,
						'm'    => (int) @filemtime( $abs ),
						'mode' => (int) @fileperms( $abs ) & 0777,
					),
					$size
				);
				$st['cur'] = array(
					'p'    => $path,
					'size' => $size,
					'done' => 0,
				);
				if ( ! $this->continueFile( $st, $w, $deadline, $warn ) ) {
					return false;
				}
			}
			return false;
		} finally {
			fclose( $lh );
		}
	}

	private function continueFile( array &$st, ArchiveWriter $w, float $deadline, ?callable $warn ): bool {
		$c  = &$st['cur'];
		$fh = @fopen( $this->abs( $c['p'] ), 'rb' );
		if ( $fh ) {
			fseek( $fh, $c['done'] );
		}
		while ( $c['done'] < $c['size'] ) {
			$want = (int) min( self::CHUNK, $c['size'] - $c['done'] );
			$data = $fh ? (string) fread( $fh, $want ) : '';
			if ( strlen( $data ) < $want ) {
				// File shrank or became unreadable mid-backup: pad to the declared size.
				$data = str_pad( $data, $want, "\0" );
				if ( $warn && empty( $c['warned'] ) ) {
					$warn( 'File changed while being backed up (padded): ' . $c['p'] );
					$c['warned'] = true;
					++$st['warn'];
				}
			}
			$w->write( $data );
			$c['done']   += $want;
			$st['bytes'] += $want;
			if ( $c['done'] < $c['size'] && microtime( true ) > $deadline ) {
				if ( $fh ) {
					fclose( $fh );
				}
				return false;
			}
		}
		if ( $fh ) {
			fclose( $fh );
		}
		++$st['files'];
		$st['cur'] = null;
		return true;
	}

	public function abs( string $archivePath ): string {
		foreach ( $this->roots as $key => $dir ) {
			if ( '' !== $key && ( $archivePath === $key || str_starts_with( $archivePath, $key . '/' ) ) ) {
				return rtrim( $dir, '/\\' ) . substr( $archivePath, strlen( $key ) );
			}
		}
		return rtrim( $this->roots[''], '/\\' ) . '/' . $archivePath;
	}
}
