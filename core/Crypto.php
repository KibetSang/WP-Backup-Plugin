<?php
/**
 * Cryptographic primitives (libsodium only — no hand-rolled crypto).
 *
 * Archive key hierarchy:
 *   passphrase --Argon2id(salt, ops, mem)--> KEK (32 bytes)
 *   random data key (32 bytes) --secretbox(KEK)--> wrapped key stored in archive header
 *   data key --> XChaCha20-Poly1305 secretstream (authenticated, ordered, truncation-proof)
 */

namespace BastionBackup\Core;

defined( 'ABSPATH' ) || defined( 'BBK_INSTALLER' ) || exit;

final class Crypto {

	public const MIN_PASSPHRASE = 12;

	/** Upper bounds accepted from an archive header (prevents KDF-based DoS from a crafted file). */
	private const MAX_OPS = 10;
	private const MAX_MEM = 1073741824; // 1 GiB.

	/** Every libsodium function the plugin uses (the passphrase mode also needs sodium_crypto_pwhash). */
	public const NEEDED = array(
		'sodium_crypto_secretstream_xchacha20poly1305_keygen', 'sodium_crypto_secretstream_xchacha20poly1305_init_push',
		'sodium_crypto_secretstream_xchacha20poly1305_push', 'sodium_crypto_secretstream_xchacha20poly1305_init_pull',
		'sodium_crypto_secretstream_xchacha20poly1305_pull', 'sodium_crypto_box_keypair', 'sodium_crypto_box_secretkey',
		'sodium_crypto_box_publickey', 'sodium_crypto_box_publickey_from_secretkey', 'sodium_crypto_box_keypair_from_secretkey_and_publickey',
		'sodium_crypto_box_seal', 'sodium_crypto_box_seal_open', 'sodium_crypto_secretbox', 'sodium_crypto_secretbox_open',
		'sodium_crypto_generichash',
	);

	/** The functions that are missing: none when PHP's sodium extension or WordPress's built-in sodium_compat is there. */
	public static function missing(): array {
		return array_values( array_filter( self::NEEDED, static fn( string $f ): bool => ! function_exists( $f ) ) );
	}

	/** True with PHP's own sodium extension (fast); false when running on WordPress's pure-PHP sodium_compat (slower). */
	public static function native(): bool {
		return extension_loaded( 'sodium' ) && ! ( new \ReflectionFunction( 'sodium_crypto_secretstream_xchacha20poly1305_push' ) )->isUserDefined();
	}

	public static function assertAvailable(): void {
		if ( self::missing() ) {
			throw new BackupException( 'Encryption is not available: PHP\'s sodium extension is off and WordPress\'s built-in copy of it was not found.' );
		}
	}

	/** Wipes a secret from memory. With sodium_compat (which cannot wipe memory) it is overwritten and emptied instead. */
	public static function wipe( string &$secret ): void {
		try {
			sodium_memzero( $secret );
		} catch ( \Throwable $e ) {
			$secret = str_repeat( "\0", strlen( $secret ) );
			$secret = '';
		}
	}

	public static function assertPassphrase( string $passphrase ): void {
		if ( strlen( $passphrase ) < self::MIN_PASSPHRASE ) {
			throw new BackupException( 'Encryption passphrase must be at least ' . self::MIN_PASSPHRASE . ' characters.' );
		}
	}

	/**
	 * Create a fresh data key and wrap it with a passphrase-derived KEK.
	 *
	 * @return array{data_key:string, header:array<string,mixed>}
	 */
	public static function newArchiveKeys( string $passphrase ): array {
		self::assertAvailable();
		self::assertPassphrase( $passphrase );
		$salt  = random_bytes( SODIUM_CRYPTO_PWHASH_SALTBYTES );
		$ops   = SODIUM_CRYPTO_PWHASH_OPSLIMIT_INTERACTIVE;
		$mem   = SODIUM_CRYPTO_PWHASH_MEMLIMIT_INTERACTIVE; // 64 MiB: safe on shared hosting.
		$kek   = self::kek( $passphrase, $salt, $ops, $mem );
		$key   = sodium_crypto_secretstream_xchacha20poly1305_keygen();
		$nonce = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$wrap  = sodium_crypto_secretbox( $key, $nonce, $kek );
		self::wipe( $kek );
		return array(
			'data_key' => $key,
			'header'   => array(
				'kdf'  => 'argon2id13',
				'ops'  => $ops,
				'mem'  => $mem,
				'salt' => base64_encode( $salt ),
				'wn'   => base64_encode( $nonce ),
				'wk'   => base64_encode( $wrap ),
			),
		);
	}

	/**
	 * Recover the data key from an archive header: with the backup key (BBK-…) for archives sealed to a
	 * key, or the passphrase for passphrase archives. Throws when it does not open the archive.
	 */
	public static function unwrapArchiveKey( array $hdr, string $passphrase ): string {
		self::assertAvailable();
		if ( 'x25519-seal' === ( $hdr['kdf'] ?? '' ) ) {
			return self::unsealArchiveKey( $hdr, $passphrase );
		}
		if ( ( $hdr['kdf'] ?? '' ) !== 'argon2id13' ) {
			throw new BackupException( 'Unsupported key derivation function in archive.' );
		}
		$ops = (int) ( $hdr['ops'] ?? 0 );
		$mem = (int) ( $hdr['mem'] ?? 0 );
		if ( $ops < 1 || $ops > self::MAX_OPS || $mem < 8192 || $mem > self::MAX_MEM ) {
			throw new BackupException( 'Archive header has out-of-range KDF parameters.' );
		}
		$salt  = self::b64( $hdr['salt'] ?? '', SODIUM_CRYPTO_PWHASH_SALTBYTES );
		$nonce = self::b64( $hdr['wn'] ?? '', SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$wrap  = self::b64( $hdr['wk'] ?? '' );
		$kek   = self::kek( $passphrase, $salt, $ops, $mem );
		$key   = sodium_crypto_secretbox_open( $wrap, $nonce, $kek );
		self::wipe( $kek );
		if ( false === $key ) {
			throw new BackupException( 'Wrong passphrase, or the archive header has been tampered with.' );
		}
		return $key;
	}

	/*
	 * ---------------------------------------------------------------------------------------------
	 * Backup key (the default and only mode the plugin uses): the server keeps only the public half.
	 * It can make backups but never open them. The secret half is shown once to the administrator as
	 * "BBK-" + 13 groups of 4 (base32 of the 32-byte X25519 secret) + a 4-character check group, so a
	 * typing mistake is caught before anything is tried.
	 * ---------------------------------------------------------------------------------------------
	 */

	private const B32 = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

	/**
	 * Make a new backup key pair.
	 *
	 * @return array{text:string, public:string, id:string}
	 */
	public static function newKeyPair(): array {
		self::assertAvailable();
		$pair   = sodium_crypto_box_keypair();
		$secret = sodium_crypto_box_secretkey( $pair );
		$public = sodium_crypto_box_publickey( $pair );
		$text   = self::formatKey( $secret );
		self::wipe( $secret );
		self::wipe( $pair );
		return array(
			'text'   => $text,
			'public' => $public,
			'id'     => self::keyId( $public ),
		);
	}

	public static function formatKey( string $secret ): string {
		$body = self::base32( $secret );
		return 'BBK-' . implode( '-', str_split( $body, 4 ) ) . '-' . self::check( $secret );
	}

	/** The 32-byte secret from the key as typed or pasted. Case, spaces, line breaks and dashes do not matter. */
	public static function parseKey( string $text ): string {
		$t = strtoupper( (string) preg_replace( '/[\s-]+/', '', $text ) );
		if ( str_starts_with( $t, 'BBK' ) && 59 === strlen( $t ) ) {
			$t = substr( $t, 3 );
		}
		if ( ! preg_match( '/^[A-Z2-7]{56}$/', $t ) ) {
			throw new BackupException( 'That is not a backup key. A backup key starts with BBK- and has 14 groups of 4 letters and digits.' );
		}
		$secret = self::unbase32( substr( $t, 0, 52 ) );
		if ( null === $secret || ! hash_equals( self::check( $secret ), substr( $t, 52 ) ) ) {
			throw new BackupException( 'That backup key has a mistake in it. Check each group against your copy.' );
		}
		return $secret;
	}

	/** A short name for a key, printed on every backup made with it. */
	public static function keyId( string $public ): string {
		return implode( '-', str_split( strtoupper( substr( hash( 'sha256', $public ), 0, 12 ) ), 4 ) );
	}

	/**
	 * Fresh data key, sealed to the backup key's public half.
	 *
	 * @return array{data_key:string, header:array<string,mixed>}
	 */
	public static function newArchiveKeysForPublicKey( string $public ): array {
		self::assertAvailable();
		if ( SODIUM_CRYPTO_BOX_PUBLICKEYBYTES !== strlen( $public ) ) {
			throw new BackupException( 'The backup key on this site is damaged. Make a new backup key.' );
		}
		$key = sodium_crypto_secretstream_xchacha20poly1305_keygen();
		return array(
			'data_key' => $key,
			'header'   => array(
				'kdf' => 'x25519-seal',
				'kid' => self::keyId( $public ),
				'wk'  => base64_encode( sodium_crypto_box_seal( $key, $public ) ),
			),
		);
	}

	private static function unsealArchiveKey( array $hdr, string $keyText ): string {
		$secret = self::parseKey( $keyText );
		$public = sodium_crypto_box_publickey_from_secretkey( $secret );
		$id     = self::keyId( $public );
		if ( ! hash_equals( (string) ( $hdr['kid'] ?? '' ), $id ) ) {
			self::wipe( $secret );
			throw new BackupException( sprintf( 'This key does not open this backup. The backup was made with key %s; the key given is %s.', (string) ( $hdr['kid'] ?? '?' ), $id ) );
		}
		$pair = sodium_crypto_box_keypair_from_secretkey_and_publickey( $secret, $public );
		$key  = sodium_crypto_box_seal_open( self::b64( (string) ( $hdr['wk'] ?? '' ) ), $pair );
		self::wipe( $secret );
		self::wipe( $pair );
		if ( false === $key || SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_KEYBYTES !== strlen( $key ) ) {
			throw new BackupException( 'The backup header is damaged or has been tampered with.' );
		}
		return $key;
	}

	private static function check( string $secret ): string {
		return substr( self::base32( hash( 'sha256', 'bbk-key-check' . $secret, true ) ), 0, 4 );
	}

	private static function base32( string $bin ): string {
		$bits = '';
		foreach ( str_split( $bin ) as $c ) {
			$bits .= str_pad( decbin( ord( $c ) ), 8, '0', STR_PAD_LEFT );
		}
		$bits = str_pad( $bits, (int) ceil( strlen( $bits ) / 5 ) * 5, '0' );
		$out  = '';
		foreach ( str_split( $bits, 5 ) as $five ) {
			$out .= self::B32[ bindec( $five ) ];
		}
		return $out;
	}

	private static function unbase32( string $text ): ?string {
		$bits = '';
		foreach ( str_split( $text ) as $c ) {
			$bits .= str_pad( decbin( (int) strpos( self::B32, $c ) ), 5, '0', STR_PAD_LEFT );
		}
		if ( '0000' !== substr( $bits, 256 ) ) {
			return null;
		}
		$out = '';
		foreach ( str_split( substr( $bits, 0, 256 ), 8 ) as $byte ) {
			$out .= chr( bindec( $byte ) );
		}
		return $out;
	}

	/** Symmetric seal for small secrets (vault, job state). Returns printable string. */
	public static function seal( string $plain, string $key ): string {
		$nonce = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		return 'bbv1:' . base64_encode( $nonce . sodium_crypto_secretbox( $plain, $nonce, $key ) );
	}

	public static function open( string $blob, string $key ): ?string {
		if ( ! str_starts_with( $blob, 'bbv1:' ) ) {
			return null;
		}
		$raw = base64_decode( substr( $blob, 5 ), true );
		if ( false === $raw || strlen( $raw ) < SODIUM_CRYPTO_SECRETBOX_NONCEBYTES + SODIUM_CRYPTO_SECRETBOX_MACBYTES ) {
			return null;
		}
		$plain = sodium_crypto_secretbox_open(
			substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ),
			substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ),
			$key
		);
		return false === $plain ? null : $plain;
	}

	/** Derive a 32-byte subkey from arbitrary input material (domain separated). */
	public static function subkey( string $material, string $context ): string {
		return sodium_crypto_generichash( $context . "\0" . $material, '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES );
	}

	public static function randomToken( int $bytes = 24 ): string {
		return rtrim( strtr( base64_encode( random_bytes( $bytes ) ), '+/', '-_' ), '=' );
	}

	private static function kek( string $passphrase, string $salt, int $ops, int $mem ): string {
		return sodium_crypto_pwhash(
			SODIUM_CRYPTO_SECRETBOX_KEYBYTES,
			$passphrase,
			$salt,
			$ops,
			$mem,
			SODIUM_CRYPTO_PWHASH_ALG_ARGON2ID13
		);
	}

	private static function b64( string $v, int $len = 0 ): string {
		$raw = base64_decode( $v, true );
		if ( false === $raw || ( $len && strlen( $raw ) !== $len ) ) {
			throw new BackupException( 'Malformed archive header.' );
		}
		return $raw;
	}
}
