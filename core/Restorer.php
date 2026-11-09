<?php
/**
 * Single-pass, resumable archive processor used for verify and restore.
 *
 * Modes:
 *  - verify:  authenticate + decrypt every frame, write nothing.
 *  - restore: load SQL into temp tables (via DbImporter) and extract files through
 *             a caller-supplied path mapper (which decides root + skip rules).
 *
 * Files are written to "<target>.bbk-part" and renamed into place when complete,
 * so a crash never leaves a half-written PHP file live.
 */

namespace BastionBackup\Core;

defined( 'ABSPATH' ) || defined( 'BBK_INSTALLER' ) || exit;

final class Restorer {

	private const CHUNK = 1048576;

	/** @var callable|null fn(string $archivePath): ?array{root:string, rel:string} */
	private $mapper;

	public function __construct(
		private string $archive,
		private string $passphrase,
		private string $mode = 'verify',
		private ?DbImporter $db = null,
		?callable $mapper = null,
		private bool $doDb = true,
		private bool $doFiles = true,
	) {
		$this->mapper = $mapper;
	}

	public static function initState(): array {
		return array(
			'reader'   => null,
			'file'     => null,
			'manifest' => null,
			'index'    => null,
			'tables'   => array(),
			'files'    => 0,
			'skipped'  => 0,
			'stmts'    => 0,
			'pos'      => 0,
			'size'     => 0,
			'done'     => false,
		);
	}

	/** Read just the manifest record (first record) — used to preview before restore. */
	public static function manifest( string $archive, string $passphrase ): array {
		$r = ArchiveReader::open( $archive, $passphrase );
		try {
			$rec = $r->next();
			if ( ! $rec || 'S' !== $rec['type'] ) {
				throw new BackupException( 'Archive has no manifest.' );
			}
			return $rec['meta'];
		} finally {
			$r->close();
		}
	}

	public function step( array &$st, float $deadline ): bool {
		$r = ArchiveReader::open( $this->archive, $this->passphrase, $st['reader'] );
		try {
			if ( $st['file'] && ! $this->continueFile( $r, $st, $deadline ) ) {
				return false;
			}
			while ( microtime( true ) < $deadline ) {
				$rec = $r->next();
				if ( null === $rec ) {
					$st['done'] = true;
					break;
				}
				$this->handle( $r, $rec, $st );
				if ( $st['file'] && ! $this->continueFile( $r, $st, $deadline ) ) {
					return false;
				}
			}
			return $st['done'];
		} finally {
			[ $st['pos'], $st['size'] ] = $r->progress();
			$st['reader']               = $r->export();
			$r->close();
		}
	}

	private function handle( ArchiveReader $r, array $rec, array &$st ): void {
		$verify = 'verify' === $this->mode;
		switch ( $rec['type'] ) {
			case 'S':
				$st['manifest'] = $rec['meta'];
				break;
			case 'X':
				$st['index'] = $rec['meta'];
				break;
			case 'T':
				$sql = $r->readAll();
				$t   = (string) ( $rec['meta']['t'] ?? '' );
				if ( '' === $t ) {
					throw new BackupException( 'Corrupt archive: table record without name.' );
				}
				if ( ! $verify && $this->doDb ) {
					$this->db->createTable( $t, $sql );
				}
				$st['tables'][] = $t;
				break;
			case 'Q':
				$sql = $r->readAll();
				if ( ! $verify && $this->doDb ) {
					$this->db->insert( (string) $rec['meta']['t'], $sql );
				}
				++$st['stmts'];
				break;
			case 'D':
				if ( ! $verify && $this->doFiles ) {
					$t = $this->target( (string) $rec['meta']['p'] );
					if ( $t ) {
						PathGuard::ensureDirInside( $t['root'], $t['root'] . '/' . $t['rel'] );
					}
				}
				break;
			case 'F':
				$st['file'] = array(
					'p'    => (string) $rec['meta']['p'],
					'm'    => (int) ( $rec['meta']['m'] ?? 0 ),
					'mode' => (int) ( $rec['meta']['mode'] ?? 0644 ),
					'w'    => 0,
					'part' => null,
					'dest' => null,
				);
				if ( ! $verify && $this->doFiles ) {
					$t = $this->target( $st['file']['p'] );
					if ( $t ) {
						$dest = $t['root'] . '/' . $t['rel'];
						PathGuard::ensureDirInside( $t['root'], dirname( $dest ) );
						$st['file']['dest'] = $dest;
						$st['file']['part'] = $dest . '.bbk-part';
						@unlink( $st['file']['part'] );
					} else {
						++$st['skipped'];
					}
				}
				break;
			default:
				// Unknown record types are skipped for forward compatibility.
				break;
		}
	}

	private function continueFile( ArchiveReader $r, array &$st, float $deadline ): bool {
		$f  = &$st['file'];
		$fh = null;
		if ( $f['part'] ) {
			$fh = fopen( $f['part'], 'c+b' );
			if ( ! $fh ) {
				throw new BackupException( 'Cannot write ' . $f['p'] );
			}
			ftruncate( $fh, $f['w'] ); // Resume exactly at the last checkpoint.
			fseek( $fh, $f['w'] );
		}
		while ( $r->left() > 0 ) {
			$chunk = $r->read( self::CHUNK );
			if ( $fh && false === fwrite( $fh, $chunk ) ) {
				throw new BackupException( 'Write failed (disk full?) for ' . $f['p'] );
			}
			$f['w'] += strlen( $chunk );
			if ( $r->left() > 0 && microtime( true ) > $deadline ) {
				if ( $fh ) {
					fclose( $fh );
				}
				return false;
			}
		}
		if ( $fh ) {
			fclose( $fh );
			if ( ! @rename( $f['part'], $f['dest'] ) ) {
				@unlink( $f['part'] );
				throw new BackupException( 'Cannot move file into place: ' . $f['p'] );
			}
			@chmod( $f['dest'], ( $f['mode'] & 0777 ) ?: 0644 );
			if ( $f['m'] > 0 ) {
				@touch( $f['dest'], $f['m'] );
			}
			++$st['files'];
		}
		$st['file'] = null;
		return true;
	}

	private function target( string $archivePath ): ?array {
		if ( ! $this->mapper ) {
			return null;
		}
		$t = ( $this->mapper )( $archivePath );
		if ( ! $t ) {
			return null;
		}
		$t['root'] = rtrim( $t['root'], '/\\' );
		$t['rel']  = PathGuard::normalize( $t['rel'] );
		return $t;
	}
}
