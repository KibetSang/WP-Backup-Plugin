<?php
/**
 * Streaming, resumable, encrypted archive writer (.bbk format).
 *
 * File layout:
 *   "BBKARCH\x01" | u32 header_len | header JSON (public crypto params only)
 *   frame*        : u32 ciphertext_len | secretstream ciphertext
 * Each frame decrypts to: 1 flag byte (0 = raw, 1 = deflate) | payload.
 * Concatenated payloads form the record stream:
 *   type(1) | u32 meta_len | meta JSON | u64 data_len | data
 * The last frame carries TAG_FINAL, so truncation is always detected.
 *
 * Memory use is bounded by FRAME_PLAIN regardless of archive size (tested to 10 GB+).
 */

namespace BastionBackup\Core;

defined( 'ABSPATH' ) || defined( 'BBK_INSTALLER' ) || exit;

final class ArchiveWriter {

	public const MAGIC       = "BBKARCH\x01";
	public const FRAME_PLAIN = 2097152; // 2 MiB plaintext per frame.
	public const VERSION     = 1;

	/** @var resource */
	private $fh;
	private string $state;
	private string $ad;
	private string $buf      = '';
	private bool $compress   = true;
	private int $size;

	/** @param resource $fh */
	private function __construct( $fh, string $state, string $ad, int $size ) {
		$this->fh    = $fh;
		$this->state = $state;
		$this->ad    = $ad;
		$this->size  = $size;
	}

	/** New archive encrypted with a passphrase (kept for tools and tests; the plugin uses createForKey). */
	public static function create( string $path, string $passphrase, string $generator = '' ): self {
		return self::start( $path, Crypto::newArchiveKeys( $passphrase ), $generator );
	}

	/** New archive sealed to a backup key's public half: whoever holds only this can write it, never read it. */
	public static function createForKey( string $path, string $publicKey, string $generator = '' ): self {
		return self::start( $path, Crypto::newArchiveKeysForPublicKey( $publicKey ), $generator );
	}

	private static function start( string $path, array $keys, string $generator ): self {
		[ $state, $ssHeader ] = sodium_crypto_secretstream_xchacha20poly1305_init_push( $keys['data_key'] );
		Crypto::wipe( $keys['data_key'] );

		$hdr = $keys['header'] + array(
			'v'       => self::VERSION,
			'cipher'  => 'xchacha20poly1305-secretstream',
			'ss'      => base64_encode( $ssHeader ),
			'frame'   => self::FRAME_PLAIN,
			'created' => time(),
			'gen'     => substr( $generator, 0, 64 ),
		);
		$json = json_encode( $hdr, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR );

		$fh = @fopen( $path, 'xb' ); // Exclusive create: never clobber an existing file.
		if ( ! $fh ) {
			throw new BackupException( 'Cannot create archive file.' );
		}
		@chmod( $path, 0600 );
		$head = self::MAGIC . pack( 'N', strlen( $json ) ) . $json;
		self::writeAll( $fh, $head );
		return new self( $fh, $state, hash( 'sha256', $head, true ), strlen( $head ) );
	}

	/** Re-open after a previous request checkpointed with export(). */
	public static function resume( string $path, array $st ): self {
		$fh = @fopen( $path, 'r+b' );
		if ( ! $fh ) {
			throw new BackupException( 'Cannot reopen archive for resume.' );
		}
		$size = (int) $st['size'];
		// Discard anything written after the last checkpoint (e.g. a request killed mid-frame).
		ftruncate( $fh, $size );
		fseek( $fh, 0, SEEK_END );
		return new self( $fh, (string) base64_decode( $st['ss'] ), (string) base64_decode( $st['ad'] ), $size );
	}

	/** Checkpoint. Flushes buffered plaintext as a frame so nothing sensitive is persisted in state. */
	public function export(): array {
		$this->flush();
		return array(
			'ss'   => base64_encode( $this->state ),
			'ad'   => base64_encode( $this->ad ),
			'size' => $this->size,
		);
	}

	public function setCompress( bool $on ): void {
		$this->compress = $on;
	}

	public function size(): int {
		return $this->size + strlen( $this->buf );
	}

	public function record( string $type, array $meta, int $dataLen ): void {
		if ( isset( $meta['p'] ) && ! mb_check_encoding( (string) $meta['p'], 'UTF-8' ) ) {
			$meta['p64'] = base64_encode( (string) $meta['p'] ); // Non-UTF-8 filenames survive intact.
			unset( $meta['p'] );
		}
		$m = json_encode( $meta, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR );
		$this->write( $type[0] . pack( 'N', strlen( $m ) ) . $m . pack( 'J', $dataLen ) );
	}

	public function write( string $data ): void {
		$this->buf .= $data;
		if ( strlen( $this->buf ) >= self::FRAME_PLAIN ) {
			$off = 0;
			$len = strlen( $this->buf );
			while ( $len - $off >= self::FRAME_PLAIN ) {
				$this->emit( substr( $this->buf, $off, self::FRAME_PLAIN ), SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_MESSAGE );
				$off += self::FRAME_PLAIN;
			}
			$this->buf = (string) substr( $this->buf, $off );
		}
	}

	public function flush(): void {
		if ( '' !== $this->buf ) {
			$this->emit( $this->buf, SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_MESSAGE );
			$this->buf = '';
		}
		fflush( $this->fh );
	}

	/** Write end-of-archive marker and the FINAL frame. */
	public function finish(): void {
		$this->record( 'Z', array(), 0 );
		$this->emit( $this->buf, SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL );
		$this->buf = '';
		fflush( $this->fh );
		fclose( $this->fh );
		Crypto::wipe( $this->state );
	}

	public function close(): void {
		if ( is_resource( $this->fh ) ) {
			fclose( $this->fh );
		}
	}

	private function emit( string $plain, int $tag ): void {
		$flag    = "\0";
		$payload = $plain;
		if ( $this->compress && strlen( $plain ) > 256 ) {
			$z = gzdeflate( $plain, 6 );
			if ( false !== $z && strlen( $z ) < strlen( $plain ) * 0.97 ) {
				$flag    = "\1";
				$payload = $z;
			}
		}
		$ct = sodium_crypto_secretstream_xchacha20poly1305_push( $this->state, $flag . $payload, $this->ad, $tag );
		self::writeAll( $this->fh, pack( 'N', strlen( $ct ) ) . $ct );
		$this->size += 4 + strlen( $ct );
	}

	/** @param resource $fh */
	private static function writeAll( $fh, string $data ): void {
		$len = strlen( $data );
		$off = 0;
		while ( $off < $len ) {
			$n = fwrite( $fh, 0 === $off ? $data : substr( $data, $off ) );
			if ( false === $n || 0 === $n ) {
				throw new BackupException( 'Write failed — the disk may be full.' );
			}
			$off += $n;
		}
	}
}
