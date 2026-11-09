<?php
/**
 * Encrypted secret storage (passphrase, OAuth tokens, S3 keys).
 *
 * Secrets are stored as a single libsodium secretbox blob in wp_options. The key is
 * derived from BASTION_BACKUP_SECRET (if defined in wp-config.php — recommended) or
 * from the WordPress auth salts. A database dump alone therefore never reveals them.
 *
 * Strongest setup: define BASTION_BACKUP_PASSPHRASE in wp-config.php, so the archive
 * passphrase never touches the database at all.
 */

namespace BastionBackup;

use BastionBackup\Core\Crypto;

defined( 'ABSPATH' ) || exit;

final class Vault {

	public const OPTION = 'bastion_backup_secrets';

	private static ?array $cache = null;

	public static function key(): string {
		if ( defined( 'BASTION_BACKUP_SECRET' ) && strlen( (string) BASTION_BACKUP_SECRET ) >= 32 ) {
			$material = (string) BASTION_BACKUP_SECRET;
		} else {
			$material = '';
			foreach ( array( 'AUTH_KEY', 'SECURE_AUTH_KEY', 'LOGGED_IN_KEY', 'NONCE_KEY', 'AUTH_SALT' ) as $c ) {
				$material .= defined( $c ) ? (string) constant( $c ) : '';
			}
			if ( strlen( $material ) < 64 ) {
				// Salts missing/weak: fall back to a random key file outside the options table.
				$material = Storage::keyFileMaterial();
			}
		}
		return Crypto::subkey( $material, 'bastion-backup-vault-v1' );
	}

	public static function all(): array {
		if ( null !== self::$cache ) {
			return self::$cache;
		}
		$blob  = (string) get_option( self::OPTION, '' );
		$plain = '' === $blob ? null : Crypto::open( $blob, self::key() );
		$data  = $plain ? json_decode( $plain, true ) : array();
		return self::$cache = is_array( $data ) ? $data : array();
	}

	public static function get( string $key, mixed $default = null ): mixed {
		return self::all()[ $key ] ?? $default;
	}

	public static function set( string $key, mixed $value ): void {
		$data = self::all();
		if ( null === $value ) {
			unset( $data[ $key ] );
		} else {
			$data[ $key ] = $value;
		}
		self::$cache = $data;
		update_option( self::OPTION, Crypto::seal( (string) wp_json_encode( $data ), self::key() ), false );
	}

	public static function reset_cache(): void {
		self::$cache = null;
	}

	/** Returns [passphrase, source] where source is 'constant' | 'vault' | ''. */
	public static function passphrase(): array {
		if ( defined( 'BASTION_BACKUP_PASSPHRASE' ) && '' !== (string) BASTION_BACKUP_PASSPHRASE ) {
			return array( (string) BASTION_BACKUP_PASSPHRASE, 'constant' );
		}
		$p = (string) self::get( 'passphrase', '' );
		return array( $p, '' === $p ? '' : 'vault' );
	}

	/** Seal/unseal short-lived values (e.g. a restore passphrase inside job state). */
	public static function seal( string $plain ): string {
		return Crypto::seal( $plain, Crypto::subkey( self::key(), 'job-state' ) );
	}

	public static function unseal( string $blob ): string {
		$v = Crypto::open( $blob, Crypto::subkey( self::key(), 'job-state' ) );
		if ( null === $v ) {
			throw new Core\BackupException( 'Cannot decrypt job secret (did the WordPress salts change?).' );
		}
		return $v;
	}
}
