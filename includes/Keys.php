<?php
/**
 * The backup key, as in the Biosafety system: the site keeps only its public half, so it can make backups but never
 * open them. The secret half ("BBK-…") is shown once to the administrator who makes it and is needed to check or
 * restore any backup made with it.
 *
 * @package BastionBackup
 */

namespace BastionBackup;

use BastionBackup\Core\BackupException;
use BastionBackup\Core\Crypto;

defined( 'ABSPATH' ) || exit;

final class Keys {

	public const OPTION = 'bastion_backup_key';

	/** @return array{public:string, id:string, made_at:int, made_by:string, previous:list<array{id:string,until:int}>}|null */
	public static function current(): ?array {
		$k = get_option( self::OPTION, null );
		if ( ! is_array( $k ) || empty( $k['public'] ) ) {
			return null;
		}
		$pub = base64_decode( (string) $k['public'], true );
		if ( false === $pub || SODIUM_CRYPTO_BOX_PUBLICKEYBYTES !== strlen( $pub ) || ! hash_equals( (string) $k['id'], Crypto::keyId( $pub ) ) ) {
			return null; // Altered or damaged: treated as missing, so no backup is sealed to an unknown key.
		}
		return array(
			'public'   => $pub,
			'id'       => (string) $k['id'],
			'made_at'  => (int) ( $k['made_at'] ?? 0 ),
			'made_by'  => (string) ( $k['made_by'] ?? '' ),
			'previous' => array_values( (array) ( $k['previous'] ?? array() ) ),
		);
	}

	/**
	 * Makes a new key. Returns the secret half as text, to show once; nothing of it is stored.
	 *
	 * @return array{text:string, id:string}
	 */
	public static function make( string $by ): array {
		$old  = self::current();
		$pair = Crypto::newKeyPair();
		$prev = $old ? array_merge( array( array( 'id' => $old['id'], 'until' => time() ) ), $old['previous'] ) : array();
		update_option(
			self::OPTION,
			array(
				'public'   => base64_encode( $pair['public'] ),
				'id'       => $pair['id'],
				'made_at'  => time(),
				'made_by'  => $by,
				'previous' => array_slice( $prev, 0, 10 ),
			),
			false
		);
		return array(
			'text' => $pair['text'],
			'id'   => $pair['id'],
		);
	}

	public static function require(): array {
		$k = self::current();
		if ( ! $k ) {
			throw new BackupException( 'There is no backup key yet, so no backup can be made. Make the backup key first (Bastion Backup → Backups).' );
		}
		return $k;
	}
}
