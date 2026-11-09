<?php
/**
 * Protected on-disk storage for archives, job state, temp files and logs.
 *
 * @package BastionBackup
 *
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

	public const DIR_OPTION = 'bastion_backup_storage_dir';

	public static function root(): string {
		if ( defined( 'BASTION_BACKUP_STORAGE' ) && '' !== (string) BASTION_BACKUP_STORAGE ) {
			return rtrim( (string) BASTION_BACKUP_STORAGE, '/\\' );
		}
		$chosen = (string) get_option( self::DIR_OPTION, '' );
		if ( '' !== $chosen ) {
			return $chosen; // Chosen under Settings → Where backups are kept.
		}
		return self::defaultRoot();
	}

	/** The automatic place: wp-content/bastion-backups-<16 random hex>. */
	public static function defaultRoot(): string {
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

	/** A folder next to the website's own folder (e.g. /home/account/bastion-backups beside public_html), when one exists. */
	public static function suggestedOutside(): string {
		return wp_normalize_path( dirname( untrailingslashit( ABSPATH ) ) ) . '/bastion-backups';
	}

	/**
	 * Moves backups to another folder on this server. The folder must be absolute, writable and not part of WordPress
	 * itself; outside the website is best. Backups already made are moved into it.
	 *
	 * @return array{root:string, moved:int, left:int}
	 */
	public static function move( string $to ): array {
		if ( defined( 'BASTION_BACKUP_STORAGE' ) && '' !== (string) BASTION_BACKUP_STORAGE ) {
			throw new BackupException( 'The folder is set by BASTION_BACKUP_STORAGE in wp-config.php; change it there.' );
		}
		$to = trim( $to );
		if ( '' === $to ) {
			$to = wp_normalize_path( self::defaultRoot() ); // Empty: back to the automatic folder.
		}
		$to = rtrim( wp_normalize_path( $to ), '/' );
		if ( '' === $to || preg_match( '#^[A-Za-z]:$#', $to ) || ! preg_match( '#^(/|[A-Za-z]:/)#', $to ) || str_contains( $to, '/../' ) || str_ends_with( $to, '/..' ) || preg_match( '/[\x00-\x1f]/', $to ) ) {
			throw new BackupException( 'Enter the full path of a folder, starting with /, for example ' . self::suggestedOutside() );
		}
		$abs = wp_normalize_path( untrailingslashit( ABSPATH ) );
		foreach ( array( $abs, $abs . '/wp-admin', $abs . '/wp-includes', wp_normalize_path( WP_CONTENT_DIR ), wp_normalize_path( WP_CONTENT_DIR ) . '/plugins', wp_normalize_path( WP_CONTENT_DIR ) . '/uploads' ) as $bad ) {
			if ( $to === $bad || str_starts_with( $abs . '/', $to . '/' ) || str_starts_with( $to . '/', $abs . '/wp-admin/' ) || str_starts_with( $to . '/', $abs . '/wp-includes/' ) ) {
				throw new BackupException( 'Choose a folder of its own, not a folder of WordPress itself or one that contains it.' );
			}
		}
		if ( ! is_dir( $to ) && ! wp_mkdir_p( $to ) ) {
			throw new BackupException( 'Cannot make that folder. Make it in cPanel\'s File Manager first, or choose another.' );
		}
		$real = realpath( $to );
		if ( false === $real || ! is_writable( $real ) ) {
			throw new BackupException( 'This site cannot write to that folder. Check its permissions in File Manager.' );
		}
		$old = wp_normalize_path( self::root() );
		$new = wp_normalize_path( $real );
		if ( $old === $new ) {
			return array( 'root' => $new, 'moved' => 0, 'left' => 0 );
		}
		if ( str_starts_with( $new . '/', $old . '/' ) ) {
			throw new BackupException( 'Choose a folder outside the current backup folder.' );
		}
		$list = self::localArchives();
		update_option( self::DIR_OPTION, $new === wp_normalize_path( self::defaultRoot() ) ? '' : $new, false );
		self::ensure();
		$moved = 0;
		$left  = 0;
		foreach ( $list as $a ) {
			$from = $old . '/archives/' . $a['name'];
			$dest = $new . '/archives/' . $a['name'];
			if ( is_file( $from ) && ! file_exists( $dest ) && @rename( $from, $dest ) ) {
				++$moved;
			} else {
				++$left;
			}
		}
		return array( 'root' => $new, 'moved' => $moved, 'left' => $left );
	}

	/** True when backups sit inside the website's folder (protected by deny rules, but outside is stronger). */
	public static function publicPath(): bool {
		return null !== self::relativeToAbspath();
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
			if ( ! Destinations\Registry::isOurs( basename( $f ) ) ) {
				continue;
			}
			$out[] = array(
				'name' => basename( $f ),
				'size' => (int) filesize( $f ),
				'time' => (int) filemtime( $f ),
			);
		}
		// Newest first, by the time in the name (file times change when copied between servers).
		usort( $out, static fn( $a, $b ) => strcmp( substr( $b['name'], -26 ), substr( $a['name'], -26 ) ) );
		foreach ( $out as &$o ) {
			$o['time'] = Destinations\Registry::timeFromName( $o['name'] ) ?: $o['time'];
		}
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
