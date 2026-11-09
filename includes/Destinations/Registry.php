<?php
/**
 * The destinations the plugin knows. Adding one (S3, Dropbox, WebDAV…) is a class implementing Destination and a line here.
 *
 * @package BastionBackup
 */

namespace BastionBackup\Destinations;

use BastionBackup\Core\BackupException;

defined( 'ABSPATH' ) || exit;

final class Registry {

	/** @return array<string, class-string<Destination>> */
	public static function classes(): array {
		return array(
			'google'     => GoogleDrive::class,
			'onedrive'   => OneDrive::class,
			'dropbox'    => Dropbox::class,
			'fileserver' => FileServer::class,
		);
	}

	public static function get( string $id ): Destination {
		$classes = self::classes();
		if ( ! isset( $classes[ $id ] ) ) {
			throw new BackupException( 'Unknown destination.' );
		}
		return new $classes[ $id ]();
	}

	/** @return array<string, Destination> */
	public static function all(): array {
		$out = array();
		foreach ( array_keys( self::classes() ) as $id ) {
			$out[ $id ] = self::get( $id );
		}
		return $out;
	}

	/** The plugin's own archive names only: retention never deletes anything else. */
	public static function isOurs( string $name ): bool {
		return (bool) preg_match( '/^bastion-[a-z0-9-]{1,60}-\d{8}-\d{6}-[a-f0-9]{6}\.bbk$/', $name );
	}

	/** The backup time from the name, for places that do not give one. */
	public static function timeFromName( string $name ): int {
		if ( preg_match( '/-(\d{8})-(\d{6})-[a-f0-9]{6}\.bbk$/', $name, $m ) ) {
			$t = \DateTimeImmutable::createFromFormat( 'YmdHis', $m[1] . $m[2], new \DateTimeZone( 'UTC' ) );
			return $t ? $t->getTimestamp() : 0;
		}
		return 0;
	}
}
