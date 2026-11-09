<?php
/**
 * Protected on-disk storage for archives, job state, temp files and logs.
 *
 * Default location: wp-content/bastion-backups-<random>/ (unguessable name, plus
 * .htaccess / web.config / index.php deny rules). For maximum safety define
 * BASTION_BACKUP_STORAGE in wp-config.php pointing OUTSIDE the web root.
 * Archives are never served directly — downloads stream through an authenticated
 * admin endpoint.
 */

namespace BastionBackup;

use BastionBackup\Core\BackupException;

defined( 'ABSPATH' ) || exit;

final class Storage {

	public static function root(): string {
		if ( defined( 'BASTION_BACKUP_STORAGE' ) && '' !== (string) BASTION_BACKUP_STORAGE ) {
			return rtrim( (string) BASTION_BACKUP_STORAGE, '/\\' );
		}
		$suffix = (string) get_option( 'bastion_backup_dir_suffix', '' );
		if ( ! preg_match( '/^[a-f0-9]{16}$/', $suffix ) ) {
			$suffix = bin2hex( random_bytes( 8 ) );
			update_option( 'bastion_backup_dir_suffix', $suffix, false );
		}
		return WP_CONTENT_DIR . '/bastion-backups-' . $suffix;
	}

	public static function dir( string $sub = '' ): string {
		$d = self::root() . ( '' === $sub ? '' : '/' . $sub );
		if ( ! is_dir( $d ) ) {
			self::ensure();
		}
		return $d;
	}

	public static function ensure(): void {
		$root = self::root();
		foreach ( array( '', '/archives', '/jobs', '/tmp', '/logs' ) as $sub ) {
			$d = $root . $sub;
			if ( ! is_dir( $d ) && ! wp_mkdir_p( $d ) ) {
				throw new BackupException( 'Cannot create backup storage directory. Check permissions on wp-content.' );
			}
			@chmod( $d, 0700 );
			self::protect( $d );
		}
	}

	private static function protect( string $d ): void {
		$files = array(
			'.htaccess'  => "# Bastion Backup: deny all web access\n<IfModule mod_authz_core.c>\n\tRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n\tOrder allow,deny\n\tDeny from all\n</IfModule>\nOptions -Indexes\n",
			'web.config' => "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<configuration><system.webServer><authorization><deny users=\"*\" /></authorization><directoryBrowse enabled=\"false\" /></system.webServer></configuration>\n",
			'index.php'  => "<?php\nhttp_response_code(404);\nexit;\n",
			'index.html' => '',
		);
		foreach ( $files as $name => $body ) {
			if ( ! file_exists( "$d/$name" ) ) {
				@file_put_contents( "$d/$name", $body );
			}
		}
	}

	/** Relative path (from ABSPATH) of the storage dir, for backup exclusion. */
	public static function relativeToAbspath(): ?string {
		$abs  = wp_normalize_path( trailingslashit( ABSPATH ) );
		$root = wp_normalize_path( self::root() );
		return str_starts_with( $root, $abs ) ? substr( $root, strlen( $abs ) ) : null;
	}

	/** Only accept archive file names we generate (prevents path injection via requests). */
	public static function archivePath( string $name ): string {
		$name = basename( $name );
		if ( ! preg_match( '/^[A-Za-z0-9._-]+\.bbk$/', $name ) ) {
			throw new BackupException( 'Invalid archive name.' );
		}
		return self::dir( 'archives' ) . '/' . $name;
	}

	/** @return array<int,array{name:string,size:int,time:int}> newest first */
	public static function localArchives(): array {
		$out = array();
		foreach ( (array) glob( self::dir( 'archives' ) . '/*.bbk' ) as $f ) {
			$out[] = array(
				'name' => basename( $f ),
				'size' => (int) filesize( $f ),
				'time' => (int) filemtime( $f ),
			);
		}
		usort( $out, static fn( $a, $b ) => $b['time'] <=> $a['time'] );
		return $out;
	}

	public static function keyFileMaterial(): string {
		$f = self::root() . '/.vault-key.php';
		if ( ! is_file( $f ) ) {
			self::ensure();
			file_put_contents( $f, "<?php exit; ?>\n" . bin2hex( random_bytes( 32 ) ) );
			@chmod( $f, 0600 );
		}
		return (string) file_get_contents( $f );
	}

	public static function freeSpace(): ?float {
		$v = function_exists( 'disk_free_space' ) ? @disk_free_space( self::root() ) : false;
		return false === $v ? null : (float) $v;
	}

	public static function rrmdir( string $dir ): void {
		if ( ! is_dir( $dir ) || is_link( $dir ) ) {
			return;
		}
		$it = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ),
			\RecursiveIteratorIterator::CHILD_FIRST
		);
		foreach ( $it as $f ) {
			$f->isDir() && ! $f->isLink() ? @rmdir( $f->getPathname() ) : @unlink( $f->getPathname() );
		}
		@rmdir( $dir );
	}
}
