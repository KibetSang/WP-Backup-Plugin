<?php
/**
 * Encrypted storage for destination secrets (OAuth tokens, client secrets, file server passwords and keys).
 *
 * One libsodium secretbox blob in a non-autoloaded option. Its key comes from BASTION_BACKUP_SECRET in wp-config.php
 * (recommended), else the WordPress salts, else a random key file in the storage folder, so a copy of the database
 * alone never reveals them. The backup key's secret half is never stored at all (Keys).
 *
 * @package BastionBackup
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

	/** Seal/unseal short-lived values (the backup key a restore was given, inside its job state). */
	public static function seal( string $plain ): string {
		return Crypto::seal( $plain, Crypto::subkey( self::key(), 'job-state' ) );
	}

	public static function unseal( string $blob ): string {
		$v = Crypto::open( $blob, Crypto::subkey( self::key(), 'job-state' ) );
		if ( null === $v ) {
			throw new Core\BackupException( 'Cannot read the key saved with this job (did the WordPress salts change?). Start the restore again.' );
		}
		return $v;
	}
}
