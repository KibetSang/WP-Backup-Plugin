<?php
/**
 * Streaming, resumable reader for .bbk archives. Every byte is authenticated
 * before it is returned; tampering, reordering and truncation all throw.
 */

namespace BastionBackup\Core;

defined( 'ABSPATH' ) || defined( 'BBK_INSTALLER' ) || exit;

final class ArchiveReader {

	private const MAX_HEADER = 65536;
	private const MAX_FRAME  = 8388608;  // Reject frames above 8 MiB (writer emits ≤ 2 MiB).
	private const MAX_META   = 1048576;

	/** @var resource */
	private $fh;
	private string $state;
	private string $ad;
	private string $buf  = '';
	private bool $final  = false;
	private bool $ended  = false;
	private ?array $cur  = null;
	private int $fileSize;
	public array $header;

	/** @param resource $fh */
	private function __construct( $fh, array $header, string $ad ) {
		$this->fh     = $fh;
		$this->header = $header;
		$this->ad     = $ad;
	}

	/** Read only the public header (no passphrase needed). */
	public static function readHeader( string $path ): array {
		$fh = @fopen( $path, 'rb' );
		if ( ! $fh ) {
			throw new BackupException( 'Cannot open archive.' );
		}
		try {
			return self::parseHeader( $fh )[0];
		} finally {
			fclose( $fh );
		}
	}

	public static function open( string $path, string $passphrase, ?array $st = null ): self {
		Crypto::assertAvailable();
		$fh = @fopen( $path, 'rb' );
		if ( ! $fh ) {
			throw new BackupException( 'Cannot open archive.' );
		}
		[ $hdr, $ad ] = self::parseHeader( $fh );
		$r            = new self( $fh, $hdr, $ad );
		$r->fileSize  = (int) filesize( $path );
		if ( $st ) {
			$r->state = (string) base64_decode( $st['ss'] );
			$r->buf   = (string) base64_decode( $st['buf'] );
			$r->cur   = $st['cur'];
			$r->final = (bool) $st['final'];
			$r->ended = (bool) $st['ended'];
			fseek( $fh, (int) $st['pos'] );
		} else {
			$key      = Crypto::unwrapArchiveKey( $hdr, $passphrase );
			$ssHeader = base64_decode( (string) $hdr['ss'], true );
			if ( false === $ssHeader || Crypto::STREAM_HEADERBYTES !== strlen( $ssHeader ) ) {
				throw new BackupException( 'Malformed archive header.' );
			}
			$r->state = sodium_crypto_secretstream_xchacha20poly1305_init_pull( $ssHeader, $key );
			Crypto::wipe( $key );
		}
		return $r;
	}

	public function export(): array {
		return array(
			'ss'    => base64_encode( $this->state ),
			'buf'   => base64_encode( $this->buf ),
			'cur'   => $this->cur,
			'final' => $this->final,
			'ended' => $this->ended,
			'pos'   => ftell( $this->fh ),
		);
	}

	public function close(): void {
		if ( is_resource( $this->fh ) ) {
			fclose( $this->fh );
		}
	}

	/** @return array{0:int,1:int} [bytes consumed, total bytes] */
	public function progress(): array {
		return array( (int) ftell( $this->fh ), $this->fileSize );
	}

	/**
	 * Advance to the next record (skipping unread data of the current one).
	 * Returns null at the authenticated end of the archive.
	 *
	 * @return array{type:string, meta:array, len:int}|null
	 */
	public function next(): ?array {
		if ( $this->ended ) {
			return null;
		}
		while ( $this->cur && $this->cur['left'] > 0 ) {
			$this->read( 1048576 );
		}
		$this->need( 5 );
		$type = $this->buf[0];
		$mlen = unpack( 'N', substr( $this->buf, 1, 4 ) )[1];
		if ( $mlen > self::MAX_META ) {
			throw new BackupException( 'Corrupt archive: record metadata too large.' );
		}
		$this->need( 13 + $mlen );
		$meta = json_decode( substr( $this->buf, 5, $mlen ), true );
		$len  = unpack( 'J', substr( $this->buf, 5 + $mlen, 8 ) )[1];
		if ( ! is_array( $meta ) || $len < 0 ) {
			throw new BackupException( 'Corrupt archive: bad record header.' );
		}
		$this->buf = (string) substr( $this->buf, 13 + $mlen );
		if ( isset( $meta['p64'] ) ) {
			$meta['p'] = (string) base64_decode( (string) $meta['p64'] );
			unset( $meta['p64'] );
		}

		if ( 'Z' === $type ) {
			// End marker must sit in the FINAL frame with nothing after it.
			if ( ! $this->final || '' !== $this->buf || false !== fgetc( $this->fh ) ) {
				throw new BackupException( 'Corrupt archive: unexpected data after end marker.' );
			}
			$this->ended = true;
			$this->cur   = null;
			return null;
		}
		$this->cur = array(
			'type' => $type,
			'meta' => $meta,
			'len'  => $len,
			'left' => $len,
		);
		return array(
			'type' => $type,
			'meta' => $meta,
			'len'  => $len,
		);
	}

	/** Remaining unread bytes of the current record. */
	public function left(): int {
		return $this->cur ? (int) $this->cur['left'] : 0;
	}

	public function current(): ?array {
		return $this->cur;
	}

	public function read( int $max ): string {
		if ( ! $this->cur || $this->cur['left'] <= 0 ) {
			return '';
		}
		if ( '' === $this->buf ) {
			$this->need( 1 );
		}
		$n                  = min( $max, $this->cur['left'], strlen( $this->buf ) );
		$out                = substr( $this->buf, 0, $n );
		$this->buf          = (string) substr( $this->buf, $n );
		$this->cur['left'] -= $n;
		return $out;
	}

	/** Read the whole current record into memory (SQL statements etc.). */
	public function readAll( int $cap = 268435456 ): string {
		if ( $this->left() > $cap ) {
			throw new BackupException( 'Record too large to load into memory.' );
		}
		$out = '';
		while ( $this->left() > 0 ) {
			$out .= $this->read( 4194304 );
		}
		return $out;
	}

	private function need( int $n ): void {
		while ( strlen( $this->buf ) < $n ) {
			if ( ! $this->pullFrame() ) {
				throw new BackupException( 'Archive is truncated or incomplete.' );
			}
		}
	}

	private function pullFrame(): bool {
		if ( $this->final ) {
			return false;
		}
		$lenb = fread( $this->fh, 4 );
		if ( false === $lenb || strlen( $lenb ) < 4 ) {
			return false;
		}
		$len = unpack( 'N', $lenb )[1];
		if ( $len < 17 || $len > self::MAX_FRAME ) {
			throw new BackupException( 'Corrupt archive: invalid frame length.' );
		}
		$ct = '';
		while ( strlen( $ct ) < $len ) {
			$chunk = fread( $this->fh, $len - strlen( $ct ) );
			if ( false === $chunk || '' === $chunk ) {
				return false;
			}
			$ct .= $chunk;
		}
		$res = sodium_crypto_secretstream_xchacha20poly1305_pull( $this->state, $ct, $this->ad );
		if ( false === $res ) {
			throw new BackupException( 'Integrity check failed: the archive was modified or is corrupt.' );
		}
		[ $msg, $tag ] = $res;
		if ( '' === $msg ) {
			throw new BackupException( 'Corrupt archive: empty frame.' );
		}
		$payload = substr( $msg, 1 );
		if ( "\1" === $msg[0] ) {
			$payload = gzinflate( $payload, self::MAX_FRAME );
			if ( false === $payload ) {
				throw new BackupException( 'Corrupt archive: decompression failed.' );
			}
		}
		$this->buf .= $payload;
		if ( Crypto::TAG_FINAL === $tag ) {
			$this->final = true;
		}
		return true;
	}

	/**
	 * @param resource $fh
	 * @return array{0:array,1:string}
	 */
	private static function parseHeader( $fh ): array {
		$magic = fread( $fh, 8 );
		if ( ArchiveWriter::MAGIC !== $magic ) {
			throw new BackupException( 'Not a Bastion Backup archive (.bbk).' );
		}
		$lenb = fread( $fh, 4 );
		$len  = strlen( (string) $lenb ) === 4 ? unpack( 'N', $lenb )[1] : 0;
		if ( $len < 2 || $len > self::MAX_HEADER ) {
			throw new BackupException( 'Malformed archive header.' );
		}
		$json = fread( $fh, $len );
		$hdr  = json_decode( (string) $json, true );
		if ( ! is_array( $hdr ) || (int) ( $hdr['v'] ?? 0 ) !== ArchiveWriter::VERSION ) {
			throw new BackupException( 'Unsupported archive version.' );
		}
		return array( $hdr, hash( 'sha256', $magic . $lenb . $json, true ) );
	}
}
