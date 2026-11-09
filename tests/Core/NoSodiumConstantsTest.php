<?php

declare(strict_types=1);

namespace BastionBackup\Tests\Core;

use BastionBackup\Core\Crypto;
use PHPUnit\Framework\TestCase;

/**
 * WordPress's built-in sodium_compat does not always declare the SODIUM_* constants, so the plugin must never use them
 * (it uses Crypto's own copies). This caught a real failure on a host without the sodium extension.
 */
final class NoSodiumConstantsTest extends TestCase {

	public function test_no_file_uses_a_sodium_constant_directly(): void {
		$root = dirname( __DIR__, 2 );
		$hits = array();
		foreach ( array_merge( glob( $root . '/core/*.php' ), glob( $root . '/includes/*.php' ), glob( $root . '/includes/Destinations/*.php' ), array( $root . '/installer/installer-template.php', $root . '/bastion-backup.php' ) ) as $f ) {
			foreach ( file( $f ) as $n => $line ) {
				if ( preg_match( '/\bSODIUM_(?!LIBRARY_VERSION\b)[A-Z0-9_]+\b/', $line ) && ! str_contains( $line, 'SODIUM_* constants' ) ) {
					$hits[] = basename( $f ) . ':' . ( $n + 1 );
				}
			}
		}
		$this->assertSame( array(), $hits );
	}

	/** Crypto's copies match the library's values (here the extension is loaded, so both can be compared). */
	public function test_the_copies_match_libsodium(): void {
		if ( ! extension_loaded( 'sodium' ) ) {
			$this->markTestSkipped( 'No sodium extension to compare with.' );
		}
		$this->assertSame( SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_MESSAGE, Crypto::TAG_MESSAGE );
		$this->assertSame( SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL, Crypto::TAG_FINAL );
		$this->assertSame( SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES, Crypto::STREAM_HEADERBYTES );
		$this->assertSame( SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_KEYBYTES, Crypto::STREAM_KEYBYTES );
		$this->assertSame( SODIUM_CRYPTO_BOX_PUBLICKEYBYTES, Crypto::BOX_PUBLICKEYBYTES );
		$this->assertSame( SODIUM_CRYPTO_SECRETBOX_KEYBYTES, Crypto::SECRETBOX_KEYBYTES );
		$this->assertSame( SODIUM_CRYPTO_SECRETBOX_MACBYTES, Crypto::SECRETBOX_MACBYTES );
		$this->assertSame( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES, Crypto::SECRETBOX_NONCEBYTES );
		$this->assertSame( SODIUM_CRYPTO_PWHASH_SALTBYTES, Crypto::PWHASH_SALTBYTES );
		$this->assertSame( SODIUM_CRYPTO_PWHASH_OPSLIMIT_INTERACTIVE, Crypto::PWHASH_OPSLIMIT_INTERACTIVE );
		$this->assertSame( SODIUM_CRYPTO_PWHASH_MEMLIMIT_INTERACTIVE, Crypto::PWHASH_MEMLIMIT_INTERACTIVE );
		$this->assertSame( SODIUM_CRYPTO_PWHASH_ALG_ARGON2ID13, Crypto::PWHASH_ALG_ARGON2ID13 );
	}
}
