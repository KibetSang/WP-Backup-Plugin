<?php
/**
 * Restore (and "check a backup"):
 *   acquire (local | download from a destination | browser upload) → verify (every byte authenticated, nothing
 *   written) → ready: waits for the administrator to confirm with their WordPress password and "RESTORE" →
 *   prepare (remember this plugin's settings; maintenance on) → restore (database into temporary tables, files to
 *   "*.bbk-part" then renamed) → replace (addresses, if they changed) → swap (one RENAME TABLE) and finish in the
 *   same request → done.
 * A damaged, cut-short or wrong-key backup stops at "verify", before anything on the site changes.
 *
 * @package BastionBackup
 */

namespace BastionBackup;

use BastionBackup\Core\BackupException;
use BastionBackup\Core\DbImporter;
use BastionBackup\Core\Restorer;
use BastionBackup\Core\SearchReplace;
use BastionBackup\Destinations\Registry;

defined( 'ABSPATH' ) || exit;

final class RestoreJob implements Job {

	/** Options this plugin puts back after the database is swapped, so its settings and connections survive. */
	private const OWN = array( Settings::OPTION, Settings::DEST_OPTION, Vault::OPTION, Keys::OPTION, 'bastion_backup_dir_suffix', Audit::OPTION, 'bastion_backup_installer' );

	public function step( array &$st, float $deadline, Logger $log ): void {
		while ( empty( $st['done'] ) && empty( $st['waiting'] ) && microtime( true ) < $deadline ) {
			switch ( $st['phase'] ) {
				case 'init':
					$this->init( $st, $log );
					break;
				case 'acquire':
					$this->acquire( $st, $deadline, $log );
					break;
				case 'verify':
					$this->verify( $st, $deadline, $log );
					break;
				case 'prepare':
					$this->prepare( $st, $log );
					break;
				case 'restore':
					$this->restore( $st, $deadline, $log );
					break;
				case 'replace':
					$this->replace( $st, $deadline, $log );
					break;
				case 'swap':
					$this->swap( $st, $log );
					break;
				default:
					throw new BackupException( 'Unknown restore phase.' );
			}
		}
	}

	private function key( array $st ): string {
		return Vault::unseal( (string) $st['key'] );
	}

	private function init( array &$st, Logger $log ): void {
		$src = (array) $st['params']['source'];
		if ( 'local' === $src['kind'] ) {
			$st['file'] = Storage::archivePath( (string) $src['name'] );
			if ( ! is_file( $st['file'] ) ) {
				throw new BackupException( 'That backup is no longer on this server.' );
			}
			$st['phase'] = 'verify';
		} elseif ( 'upload' === $src['kind'] ) {
			$st['file']  = Uploads::assembled( (string) $src['upload'] );
			$st['temp']  = true;
			$st['phase'] = 'verify';
		} elseif ( 'remote' === $src['kind'] ) {
			if ( ! Registry::isOurs( (string) $src['name'] ) ) {
				throw new BackupException( 'Not a backup made by this plugin.' );
			}
			$st['file']  = Storage::dir( 'tmp' ) . '/dl-' . $st['id'] . '.bbk';
			$st['temp']  = true;
			$st['dl']    = array();
			$st['phase'] = 'acquire';
		} else {
			throw new BackupException( 'Unknown backup source.' );
		}
		$log->info( 'Source: ' . $src['kind'] . ' ' . ( $src['name'] ?? '' ) );
	}

	private function acquire( array &$st, float $deadline, Logger $log ): void {
		$src  = (array) $st['params']['source'];
		$dest = Registry::get( (string) $src['dest'] );
		$done = $dest->download( (string) $src['id'], (string) $st['file'], $st['dl'], $deadline );
		$size = max( 1, (int) ( $st['dl']['size'] ?? 1 ) );
		$st['bytes_total'] = $size;
		$st['bytes_done']  = (int) ( $st['dl']['off'] ?? 0 );
		$st['percent']     = (int) min( 30, 30 * $st['bytes_done'] / $size );
		$st['message']     = sprintf( 'Downloading from %s: %s of %s', $dest->label(), size_format( $st['bytes_done'] ), size_format( $size ) );
		if ( $done ) {
			$log->info( 'Downloaded ' . size_format( (int) filesize( (string) $st['file'] ) ) . ' from ' . $dest->label() . '.' );
			$st['phase'] = 'verify';
		}
	}

	private function verify( array &$st, float $deadline, Logger $log ): void {
		if ( ! isset( $st['vs'] ) ) {
			$st['vs'] = Restorer::initState();
		}
		$r    = new Restorer( (string) $st['file'], $this->key( $st ), 'verify' );
		$done = $r->step( $st['vs'], $deadline );
		$vs   = $st['vs'];
		$base = ! empty( $st['params']['verify_only'] ) ? 0 : 30;
		$span = ! empty( $st['params']['verify_only'] ) ? 100 : 20;
		$st['percent'] = (int) min( 99, $base + $span * ( $vs['size'] ? $vs['pos'] / $vs['size'] : 0 ) );
		$st['message'] = sprintf( 'Checking every part of the backup with the key: %s of %s', size_format( (int) $vs['pos'] ), size_format( (int) $vs['size'] ) );
		if ( ! $done ) {
			return;
		}
		if ( empty( $vs['manifest'] ) ) {
			throw new BackupException( 'The backup has no description of the site it came from.' );
		}
		$m              = (array) $vs['manifest'];
		$st['manifest'] = array_intersect_key( $m, array_flip( array( 'site_url', 'home_url', 'table_prefix', 'wp', 'php', 'db', 'multisite', 'plugin', 'components', 'created', 'by', 'kid' ) ) );
		$st['result']   = array(
			'files'  => (int) ( $vs['index']['files'] ?? 0 ),
			'tables' => count( (array) $vs['tables'] ),
			'rows'   => (int) ( $vs['index']['rows'] ?? 0 ),
			'size'   => (int) $vs['size'],
		);
		$st['manifest_full'] = $m;
		$st['tables']        = (array) $vs['tables'];
		unset( $st['vs'] );
		$log->info( sprintf( 'Backup intact: %d files, %d tables. Made %s for %s.', $st['result']['files'], $st['result']['tables'], gmdate( 'Y-m-d H:i', (int) ( $m['created'] ?? 0 ) ) . ' UTC', (string) ( $m['site_url'] ?? '?' ) ) );
		if ( ! empty( $st['params']['verify_only'] ) ) {
			Audit::log( 'backup.checked', (string) ( $st['params']['source']['name'] ?? '' ) . ': intact' );
			$this->cleanup( $st, $log );
			$st['message'] = 'The backup is complete and opens with this key.';
			$st['done']    = true;
			return;
		}
		if ( ! empty( $m['multisite'] ) || is_multisite() ) {
			if ( empty( $m['multisite'] ) !== ! is_multisite() || untrailingslashit( (string) $m['site_url'] ) !== untrailingslashit( site_url() ) ) {
				throw new BackupException( 'A multisite network can be restored only to the same network at the same address.' );
			}
		}
		$st['percent'] = 50;
		$st['phase']   = 'ready';
		$st['waiting'] = true;
		$st['message'] = 'The backup is intact. Check what it holds and confirm the restore.';
	}

	private function prepare( array &$st, Logger $log ): void {
		global $wpdb;
		$opts = (array) $st['options'];
		if ( ! empty( $opts['files'] ) && defined( 'DISALLOW_FILE_MODS' ) && DISALLOW_FILE_MODS ) {
			throw new BackupException( 'Files cannot be restored here: DISALLOW_FILE_MODS is set in wp-config.php. Restore the database only, or use the installer.' );
		}
		$own = array();
		foreach ( self::OWN as $o ) {
			$v = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $o ) );
			if ( null !== $v ) {
				$own[ $o ] = base64_encode( (string) $v ); // Raw, exactly as stored.
			}
		}
		$st['own']  = $own;
		$st['urls'] = array(
			'site_url' => site_url(),
			'home_url' => home_url(),
			'abspath'  => wp_normalize_path( untrailingslashit( ABSPATH ) ),
			'content'  => wp_normalize_path( WP_CONTENT_DIR ),
		);
		update_option( 'bastion_backup_maintenance', time() + 3600, false );
		Audit::log( 'restore.started', (string) ( $st['params']['source']['name'] ?? '' ) . ( empty( $opts['db'] ) ? ' (files only)' : ( empty( $opts['files'] ) ? ' (database only)' : '' ) ) );
		$log->info( 'Visitors now see the maintenance page.' );
		$st['rs']    = Restorer::initState();
		$st['phase'] = 'restore';
	}

	private function importer( array $st, \mysqli $db ): DbImporter {
		global $table_prefix;
		return new DbImporter( $db, (string) ( $st['manifest_full']['table_prefix'] ?? $table_prefix ), (string) $table_prefix );
	}

	/** Where each file of the backup goes, or null to leave it (wp-config.php, this plugin, its storage). */
	private function mapper(): callable {
		$abs      = wp_normalize_path( untrailingslashit( ABSPATH ) );
		$content  = wp_normalize_path( WP_CONTENT_DIR );
		$protect  = array( wp_normalize_path( BASTION_BACKUP_DIR ), wp_normalize_path( Storage::root() ) );
		$guard    = static function ( string $full ) use ( $protect ): bool {
			foreach ( $protect as $p ) {
				if ( $full === $p || str_starts_with( $full, $p . '/' ) ) {
					return false;
				}
			}
			return true;
		};
		return static function ( string $p ) use ( $abs, $content, $guard ): ?array {
			if ( '@wpconfig' === $p ) {
				return null; // Never overwritten from the dashboard: it holds this site's database login.
			}
			if ( str_starts_with( $p, '@content/' ) ) {
				$rel = substr( $p, 9 );
				return $guard( $content . '/' . $rel ) ? array( 'root' => $content, 'rel' => $rel ) : null;
			}
			if ( str_starts_with( $p, '@' ) || 'wp-config.php' === $p ) {
				return null;
			}
			return $guard( $abs . '/' . $p ) ? array( 'root' => $abs, 'rel' => $p ) : null;
		};
	}

	private function restore( array &$st, float $deadline, Logger $log ): void {
		$opts = (array) $st['options'];
		$db   = BackupJob::connect();
		try {
			$imp = $this->importer( $st, $db );
			$imp->session();
			$r    = new Restorer( (string) $st['file'], $this->key( $st ), 'restore', $imp, $this->mapper(), ! empty( $opts['db'] ), ! empty( $opts['files'] ) );
			$done = $r->step( $st['rs'], $deadline );
		} finally {
			$db->close();
		}
		$rs            = $st['rs'];
		$st['percent'] = (int) min( 90, 50 + 40 * ( $rs['size'] ? $rs['pos'] / $rs['size'] : 0 ) );
		$st['message'] = sprintf( 'Restoring: %s files, %d tables so far', number_format_i18n( (int) $rs['files'] ), count( (array) $rs['tables'] ) );
		if ( ! $done ) {
			return;
		}
		$log->info( sprintf( 'Restored %d files (%d left as they are) and loaded %d tables.', $rs['files'], $rs['skipped'], empty( $opts['db'] ) ? 0 : count( (array) $rs['tables'] ) ) );
		unset( $st['rs'] );
		$st['phase'] = empty( $opts['db'] ) ? 'swap' : 'replace';
		$st['rep']   = null;
	}

	/** @return array<string,string> */
	private function pairs( array $st ): array {
		$m     = (array) $st['manifest_full'];
		$u     = (array) $st['urls'];
		$pairs = array();
		foreach ( array( 'site_url' => 'site_url', 'home_url' => 'home_url', 'abspath' => 'abspath', 'content_dir' => 'content' ) as $from => $to ) {
			$a = untrailingslashit( (string) ( $m[ $from ] ?? '' ) );
			$b = untrailingslashit( (string) ( $u[ $to ] ?? '' ) );
			if ( '' !== $a && '' !== $b && $a !== $b ) {
				$pairs[ $a ] = $b;
			}
		}
		return SearchReplace::expand( $pairs );
	}

	private function replace( array &$st, float $deadline, Logger $log ): void {
		$pairs = $this->pairs( $st );
		if ( ! $pairs ) {
			$st['phase'] = 'swap';
			return;
		}
		$db = BackupJob::connect();
		try {
			$imp = $this->importer( $st, $db );
			if ( null === $st['rep'] ) {
				$st['rep'] = $imp->replaceInit( (array) $st['tables'] );
				$log->info( 'Changing the site\'s address and folders in the database: ' . implode( ', ', array_map( static fn( $a, $b ) => "$a → $b", array_keys( $pairs ), $pairs ) ) );
			}
			$done = $imp->replaceStep( $st['rep'], $pairs, $deadline );
		} finally {
			$db->close();
		}
		$st['percent'] = 92;
		$st['message'] = 'Updating addresses in the restored database…';
		if ( $done ) {
			$log->info( 'Addresses updated in ' . (int) $st['rep']['rows'] . ' rows.' );
			$st['phase'] = 'swap';
		}
	}

	private function swap( array &$st, Logger $log ): void {
		global $wpdb;
		$opts = (array) $st['options'];
		if ( ! empty( $opts['db'] ) ) {
			$db = BackupJob::connect();
			try {
				$imp = $this->importer( $st, $db );
				$old = $imp->swap( (array) $st['tables'] );
				$imp->dropTables( $old );
			} finally {
				$db->close();
			}
			$log->info( 'Database swapped in.' );
			// The same request: put back this plugin's own settings, keep it active, and clear caches.
			wp_cache_flush();
			foreach ( (array) $st['own'] as $name => $raw ) {
				$wpdb->query( $wpdb->prepare( "INSERT INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no') ON DUPLICATE KEY UPDATE option_value = VALUES(option_value)", $name, (string) base64_decode( (string) $raw ) ) );
			}
			wp_cache_flush();
			$active = (array) get_option( 'active_plugins', array() );
			$me     = plugin_basename( BASTION_BACKUP_FILE );
			if ( ! in_array( $me, $active, true ) ) {
				$active[] = $me;
				update_option( 'active_plugins', $active );
			}
			delete_option( 'rewrite_rules' );
		}
		delete_option( 'bastion_backup_maintenance' );
		$this->cleanup( $st, $log );
		Audit::log( 'restore.finished', (string) ( $st['params']['source']['name'] ?? '' ) );
		$st['percent'] = 100;
		$st['message'] = empty( $opts['db'] ) ? 'Restore complete.' : 'Restore complete. You may need to sign in again.';
		$st['done']    = true;
	}

	public function cleanup( array $st, Logger $log ): void {
		if ( ! empty( $st['temp'] ) && ! empty( $st['file'] ) && is_file( (string) $st['file'] ) ) {
			unlink( (string) $st['file'] );
		}
		if ( in_array( $st['phase'] ?? '', array( 'restore', 'replace' ), true ) && ! empty( $st['tables'] ) && empty( $st['done'] ) ) {
			// Stopped before the swap: the live tables were never touched. Remove the temporary ones.
			try {
				$db = BackupJob::connect();
				$this->importer( $st, $db )->dropTemp( (array) $st['tables'] );
				$db->close();
				$log->info( 'Removed the temporary tables; the site\'s own database was not changed.' );
			} catch ( BackupException $e ) {
				$log->warn( 'Could not remove temporary tables: ' . $e->getMessage() );
			}
		}
		if ( in_array( $st['phase'] ?? '', array( 'prepare', 'restore', 'replace', 'swap' ), true ) ) {
			delete_option( 'bastion_backup_maintenance' );
		}
	}
}
