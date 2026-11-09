<?php
/**
 * Builds installer.php: the WordPress-independent core classes and installer/installer-template.php in one file, with
 * braced namespace blocks. Locked by a one-time access key shown once; only its password_hash() is in the file.
 *
 * @package BastionBackup
 */

namespace BastionBackup;

use BastionBackup\Core\BackupException;

defined( 'ABSPATH' ) || exit;

final class InstallerBuilder {

	private const CORE = array( 'BackupException', 'Crypto', 'ArchiveReader', 'ArchiveWriter', 'PathGuard', 'Restorer', 'SearchReplace', 'Db', 'DbExporter', 'DbImporter' );

	/** @return array{access:string} */
	public static function prepare(): array {
		$access = implode( '-', str_split( strtoupper( bin2hex( random_bytes( 10 ) ) ), 5 ) );
		$code   = self::build( password_hash( $access, PASSWORD_DEFAULT ) );
		foreach ( (array) glob( Storage::dir( 'tmp' ) . '/installer-*.php' ) as $old ) {
			unlink( $old );
		}
		$name = 'installer-' . bin2hex( random_bytes( 8 ) ) . '.php';
		$path = Storage::dir( 'tmp' ) . '/' . $name;
		if ( false === file_put_contents( $path, $code ) ) {
			throw new BackupException( 'Cannot write the installer.' );
		}
		chmod( $path, 0600 );
		update_option( 'bastion_backup_installer', array( 't' => time(), 'file' => $name ), false );
		return array( 'access' => $access );
	}

	/** The installer made by prepare(), for one download. */
	public static function built(): string {
		$o = get_option( 'bastion_backup_installer', null );
		$f = is_array( $o ) && preg_match( '/^installer-[a-f0-9]{16}\.php$/', (string) ( $o['file'] ?? '' ) ) ? Storage::dir( 'tmp' ) . '/' . $o['file'] : '';
		if ( '' === $f || ! is_file( $f ) || (int) $o['t'] < time() - HOUR_IN_SECONDS ) {
			throw new BackupException( 'Make the installer again (the download works once, within an hour).' );
		}
		return $f;
	}

	/** The installer's source, given the hash of its access key. Public for the tests. */
	public static function build( string $accessHash ): string {
		$out = "<?php\n/**\n * Bastion Backup installer " . BASTION_BACKUP_VERSION . ', made ' . gmdate( 'Y-m-d H:i' ) . " UTC.\n * Restores a .bbk backup on this server. Delete this file when finished (it tries to delete itself).\n */\n\n";
		$out .= "namespace {\n\tdefine( 'BBK_INSTALLER', true );\n\tdefine( 'BBK_ACCESS_HASH', " . var_export( $accessHash, true ) . " );\n\tdefine( 'BBK_VERSION', " . var_export( BASTION_BACKUP_VERSION, true ) . " );\n}\n\n";
		foreach ( self::CORE as $c ) {
			$src = (string) file_get_contents( BASTION_BACKUP_DIR . '/core/' . $c . '.php' );
			$src = (string) preg_replace( '/^<\?php\s*/', '', $src );
			$n   = 0;
			$src = (string) preg_replace( '/^namespace BastionBackup\\\\Core;\s*$/m', "namespace BastionBackup\\Core {", $src, 1, $n );
			if ( 1 !== $n ) {
				throw new BackupException( 'Cannot bundle core/' . $c . '.php.' );
			}
			$out .= $src . "\n}\n\n";
		}
		$tpl = (string) file_get_contents( BASTION_BACKUP_DIR . '/installer/installer-template.php' );
		$tpl = (string) preg_replace( '/^<\?php\s*/', '', $tpl );
		return $out . "namespace {\n" . $tpl . "\n}\n";
	}
}
