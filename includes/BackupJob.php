<?php
/**
 * Backup: init (archive + manifest) → db → scan → files → finalize → upload (each destination) → retention → done.
 * Every phase is resumable; memory stays near 32 MiB whatever the site's size.
 *
 * @package BastionBackup
 */

namespace BastionBackup;

use BastionBackup\Core\ArchiveWriter;
use BastionBackup\Core\BackupException;
use BastionBackup\Core\Db;
use BastionBackup\Core\DbExporter;
use BastionBackup\Core\FileArchiver;
use BastionBackup\Core\FileScanner;
use BastionBackup\Destinations\Registry;

defined( 'ABSPATH' ) || exit;

final class BackupJob implements Job {

	/** Share of the progress bar for each phase. */
	private const WEIGHT = array(
		'init'     => array( 0, 1 ),
		'db'       => array( 1, 15 ),
		'scan'     => array( 16, 4 ),
		'files'    => array( 20, 55 ),
		'finalize' => array( 75, 1 ),
		'upload'   => array( 76, 22 ),
		'retain'   => array( 98, 2 ),
	);

	public function step( array &$st, float $deadline, Logger $log ): void {
		$p = $st['params'];
		while ( empty( $st['done'] ) && microtime( true ) < $deadline ) {
			switch ( $st['phase'] ) {
				case 'init':
					$this->init( $st, $log );
					break;
				case 'db':
					$this->db( $st, $deadline, $log );
					break;
				case 'scan':
					$this->scan( $st, $deadline, $log );
					break;
				case 'files':
					$this->files( $st, $deadline, $log );
					break;
				case 'finalize':
					$this->finalize( $st, $log );
					break;
				case 'upload':
					$this->upload( $st, $deadline, $log );
					break;
				case 'retain':
					$this->retain( $st, $log );
					break;
				default:
					throw new BackupException( 'Unknown backup phase.' );
			}
		}
		unset( $p );
	}

	private function path( array $st ): string {
		return Storage::archivePath( (string) $st['archive'] );
	}

	private function progress( array &$st, float $within, string $message ): void {
		[ $start, $span ] = self::WEIGHT[ $st['phase'] ] ?? array( 0, 0 );
		$st['percent']    = (int) min( 99, $start + $span * max( 0, min( 1, $within ) ) );
		$st['message']    = $message;
	}

	private function init( array &$st, Logger $log ): void {
		global $wp_version, $table_prefix;
		$key  = Keys::require();
		$name = sprintf( 'bastion-%s-%s-%s.bbk', Settings::siteSlug(), gmdate( 'Ymd-His' ), bin2hex( random_bytes( 3 ) ) );
		$path = Storage::archivePath( $name );
		$w    = ArchiveWriter::createForKey( $path, $key['public'], 'Bastion Backup ' . BASTION_BACKUP_VERSION );
		$p    = $st['params'];
		$w->record(
			'S',
			array(
				'site_url'     => site_url(),
				'home_url'     => home_url(),
				'abspath'      => wp_normalize_path( untrailingslashit( ABSPATH ) ),
				'content_dir'  => wp_normalize_path( WP_CONTENT_DIR ),
				'table_prefix' => (string) $table_prefix,
				'wp'           => (string) $wp_version,
				'php'          => PHP_VERSION,
				'db'           => (string) $GLOBALS['wpdb']->db_version(),
				'multisite'    => is_multisite(),
				'plugin'       => BASTION_BACKUP_VERSION,
				'components'   => array( 'db' => ! empty( $p['db'] ), 'files' => ! empty( $p['files'] ) ),
				'created'      => time(),
				'by'           => (string) $st['by'],
				'kid'          => $key['id'],
			),
			0
		);
		$st['archive']   = $name;
		$st['kid']       = $key['id'];
		$st['w']         = $w->export();
		$w->close();
		$st['phase']     = ! empty( $p['db'] ) ? 'db' : ( ! empty( $p['files'] ) ? 'scan' : 'finalize' );
		$st['totals']    = array( 'tables' => 0, 'rows' => 0, 'files' => 0, 'bytes' => 0 );
		$log->info( 'Backup file ' . $name . ', sealed to key ' . $key['id'] . '.' );
		$this->progress( $st, 1, 'Starting the backup…' );
	}

	public static function connect(): \mysqli {
		return Db::connect( (string) DB_HOST, (string) DB_USER, (string) DB_PASSWORD, (string) DB_NAME );
	}

	private function db( array &$st, float $deadline, Logger $log ): void {
		global $table_prefix;
		$db = self::connect();
		try {
			$ex = new DbExporter( $db );
			if ( ! isset( $st['dbs'] ) ) {
				$s      = Settings::get();
				$tables = $ex->listTables( (string) $table_prefix, (bool) $s['all_tables'], (array) $s['exclude_tables'] );
				if ( ! $tables ) {
					throw new BackupException( 'No database tables found with the prefix ' . $table_prefix . '.' );
				}
				$st['dbs'] = DbExporter::initState( $tables );
				$log->info( 'Database: ' . count( $tables ) . ' tables.' );
			}
			$w    = ArchiveWriter::resume( $this->path( $st ), $st['w'] );
			$done = $ex->step( $st['dbs'], $w, $deadline );
			$st['w'] = $w->export();
			$w->close();
			$n = count( $st['dbs']['tables'] );
			$this->progress( $st, $n ? $st['dbs']['i'] / $n : 1, sprintf( 'Database: table %d of %d, %s rows', min( $n, $st['dbs']['i'] + 1 ), $n, number_format_i18n( (int) $st['dbs']['rows'] ) ) );
			if ( $done ) {
				$st['totals']['tables'] = $n;
				$st['totals']['rows']   = (int) $st['dbs']['rows'];
				$log->info( sprintf( 'Database done: %d tables, %d rows.', $n, $st['totals']['rows'] ) );
				unset( $st['dbs'] );
				$st['phase'] = ! empty( $st['params']['files'] ) ? 'scan' : 'finalize';
			}
		} finally {
			$db->close();
		}
	}

	/** The folders to back up, found from WordPress itself; the administrator is never asked for paths. */
	public static function roots(): array {
		$roots = array( '' => untrailingslashit( ABSPATH ) );
		$abs   = wp_normalize_path( trailingslashit( ABSPATH ) );
		$cont  = wp_normalize_path( WP_CONTENT_DIR );
		if ( ! str_starts_with( $cont . '/', $abs ) ) {
			$roots['@content'] = WP_CONTENT_DIR; // wp-content kept outside the WordPress folder.
		}
		return $roots;
	}

	/** wp-config.php one level above the WordPress folder (a common hardening), or null. */
	public static function configAbove(): ?string {
		$f = dirname( untrailingslashit( ABSPATH ) ) . '/wp-config.php';
		return ! is_file( ABSPATH . 'wp-config.php' ) && is_file( $f ) && ! is_file( dirname( untrailingslashit( ABSPATH ) ) . '/wp-settings.php' ) ? $f : null;
	}

	private function excludes(): array {
		$s  = Settings::get();
		$ex = (array) $s['excludes'];
		// The plugin's own storage, wherever it is.
		$abs  = wp_normalize_path( trailingslashit( ABSPATH ) );
		$cont = wp_normalize_path( trailingslashit( WP_CONTENT_DIR ) );
		$root = wp_normalize_path( Storage::root() );
		if ( str_starts_with( $root, $abs ) ) {
			$ex[] = substr( $root, strlen( $abs ) );
		}
		if ( str_starts_with( $root, $cont ) && ! str_starts_with( $cont, $abs ) ) {
			$ex[] = '@content/' . substr( $root, strlen( $cont ) );
		}
		// When wp-content is outside, it is backed up once, under @content, not again through a path inside ABSPATH.
		return $ex;
	}

	private function scan( array &$st, float $deadline, Logger $log ): void {
		$s    = Settings::get();
		$list = Jobs::listFile( (string) $st['id'] );
		$sc   = new FileScanner( self::roots(), $this->excludes(), (int) $s['max_file_mb'] * 1048576 );
		if ( ! isset( $st['scan'] ) ) {
			$st['scan'] = $sc->initState();
			if ( is_file( $list ) ) {
				unlink( $list );
			}
		}
		$done = $sc->step( $st['scan'], $list, $deadline );
		$this->progress( $st, $done ? 1 : 0.5, sprintf( 'Finding files: %s so far', number_format_i18n( (int) $st['scan']['files'] ) ) );
		if ( ! $done ) {
			return;
		}
		$cfg = self::configAbove();
		if ( $cfg && is_readable( $cfg ) ) {
			file_put_contents( $list, "F\t" . filesize( $cfg ) . "\t" . rawurlencode( '@wpconfig' ) . "\n", FILE_APPEND );
			++$st['scan']['files'];
			$st['scan']['bytes'] += (int) filesize( $cfg );
		}
		$log->info( sprintf( 'Found %d files (%s), %d folders; %d skipped (links, unreadable or too large).', $st['scan']['files'], size_format( (int) $st['scan']['bytes'] ), $st['scan']['dirs'], $st['scan']['skipped'] ) );
		// Disk space: the archive can be as large as the files (already-compressed media does not shrink).
		$need = (int) $st['scan']['bytes'] + 67108864;
		$free = Storage::freeSpace();
		if ( null !== $free && $free < $need ) {
			throw new BackupException( sprintf( 'Not enough disk space: the backup needs about %s and %s is free. Free some space, exclude large folders, or keep fewer backups on this server.', size_format( $need ), size_format( (int) $free ) ) );
		}
		$st['bytes_total'] = (int) $st['scan']['bytes'];
		$st['bytes_done']  = 0;
		$st['fa']          = FileArchiver::initState();
		$st['phase']       = 'files';
	}

	private function files( array &$st, float $deadline, Logger $log ): void {
		$roots = self::roots();
		$cfg   = self::configAbove();
		if ( $cfg ) {
			$roots['@wpconfig'] = $cfg;
		}
		$fa = new FileArchiver( $roots );
		$w  = ArchiveWriter::resume( $this->path( $st ), $st['w'] );
		try {
			$done = $fa->step(
				$st['fa'],
				Jobs::listFile( (string) $st['id'] ),
				$w,
				$deadline,
				static function ( string $msg ) use ( &$st, $log ): void {
					$log->warn( $msg );
					$st['warnings'][] = $msg;
				}
			);
			$st['w'] = $w->export();
		} finally {
			$w->close();
		}
		$total            = max( 1, (int) $st['bytes_total'] );
		$st['bytes_done'] = (int) $st['fa']['bytes'];
		$this->progress( $st, $st['bytes_done'] / $total, sprintf( 'Files: %s of %s', size_format( $st['bytes_done'] ), size_format( $total ) ) );
		if ( $done ) {
			$st['totals']['files'] = (int) $st['fa']['files'];
			$st['totals']['bytes'] = (int) $st['fa']['bytes'];
			$st['phase']           = 'finalize';
		}
	}

	private function finalize( array &$st, Logger $log ): void {
		$w = ArchiveWriter::resume( $this->path( $st ), $st['w'] );
		$w->record( 'X', $st['totals'] + array( 'dirs' => (int) ( $st['scan']['dirs'] ?? 0 ) ), 0 );
		$w->finish();
		unset( $st['w'], $st['fa'], $st['scan'] );
		$list = Jobs::listFile( (string) $st['id'] );
		if ( is_file( $list ) ) {
			unlink( $list );
		}
		$size = (int) filesize( $this->path( $st ) );
		$log->info( 'Backup file complete: ' . size_format( $size ) . '.' );
		Audit::log( 'backup.made', $st['archive'] . ' (' . size_format( $size ) . ')' );
		$st['result'] = array(
			'name'   => $st['archive'],
			'size'   => $size,
			'kid'    => $st['kid'],
			'totals' => $st['totals'],
			'copies' => array( 'local' => true ),
		);
		$dests          = array_values( array_filter( (array) ( $st['params']['dests'] ?? array() ), static fn( $d ) => isset( Registry::classes()[ $d ] ) ) );
		$st['up']       = array( 'list' => $dests, 'i' => 0, 'state' => array(), 'tries' => 0, 'ok' => array() );
		$st['phase']    = $dests ? 'upload' : 'retain';
		$this->progress( $st, 1, 'Backup file complete.' );
	}

	private function upload( array &$st, float $deadline, Logger $log ): void {
		$up = &$st['up'];
		if ( $up['i'] >= count( $up['list'] ) ) {
			$st['phase'] = 'retain';
			return;
		}
		$id   = (string) $up['list'][ $up['i'] ];
		$dest = Registry::get( $id );
		$next = static function () use ( &$up ): void {
			++$up['i'];
			$up['state'] = array();
			$up['tries'] = 0;
		};
		if ( ! $dest->isConfigured() ) {
			$msg = $dest->label() . ' is not set up, so the backup was not copied there.';
			$log->warn( $msg );
			$st['warnings'][] = $msg;
			$next();
			return;
		}
		$size = (int) filesize( $this->path( $st ) );
		try {
			$done = $dest->upload( $this->path( $st ), (string) $st['archive'], $up['state'], $deadline );
		} catch ( BackupException $e ) {
			++$up['tries'];
			$log->warn( $dest->label() . ': ' . $e->getMessage() . ( $up['tries'] < 3 ? ' (will try again)' : '' ) );
			if ( $up['tries'] >= 3 ) {
				$st['warnings'][] = 'Not copied to ' . $dest->label() . ': ' . $e->getMessage();
				$next();
			}
			return;
		}
		$within = ( $up['i'] + ( $done ? 1 : (int) ( $up['state']['off'] ?? 0 ) / max( 1, $size ) ) ) / max( 1, count( $up['list'] ) );
		$this->progress( $st, $within, sprintf( 'Copying to %s: %s of %s', $dest->label(), size_format( $done ? $size : (int) ( $up['state']['off'] ?? 0 ) ), size_format( $size ) ) );
		if ( $done ) {
			$log->info( 'Copied to ' . $dest->label() . '.' );
			$up['ok'][]                          = $id;
			$st['result']['copies'][ $id ]       = true;
			$next();
		}
	}

	private function retain( array &$st, Logger $log ): void {
		$s = Settings::get();
		// Copies elsewhere first: only the plugin's own backup names are ever deleted.
		foreach ( (array) ( $st['up']['ok'] ?? array() ) as $id ) {
			try {
				$dest = Registry::get( (string) $id );
				foreach ( array_slice( $dest->list(), (int) $s['retain_remote'] ) as $old ) {
					if ( Registry::isOurs( $old['name'] ) ) {
						$dest->delete( $old['id'] );
						$log->info( 'Deleted an older backup from ' . $dest->label() . ': ' . $old['name'] );
					}
				}
			} catch ( BackupException $e ) {
				$log->warn( 'Keeping the newest ' . $s['retain_remote'] . ' on ' . $id . ': ' . $e->getMessage() );
			}
		}
		$keep = (int) $s['retain_local'];
		if ( ! $s['keep_local'] && ! empty( $st['up']['ok'] ) ) {
			// Kept elsewhere: this server keeps none, so its disk does not fill.
			$keep = 0;
		}
		foreach ( array_slice( Storage::localArchives(), $keep ) as $old ) {
			if ( Registry::isOurs( $old['name'] ) ) {
				unlink( Storage::archivePath( $old['name'] ) );
				$log->info( 'Deleted an older backup from this server: ' . $old['name'] );
				if ( $old['name'] === $st['archive'] ) {
					$st['result']['copies']['local'] = false;
				}
			}
		}
		$st['message'] = empty( $st['warnings'] ) ? 'Backup complete.' : 'Backup complete, with warnings.';
		$st['percent'] = 100;
		$st['done']    = true;
	}

	public function cleanup( array $st, Logger $log ): void {
		// An unfinished archive is useless; remove it so it is never mistaken for a backup.
		if ( ! empty( $st['archive'] ) && empty( $st['result'] ) ) {
			$f = Storage::archivePath( (string) $st['archive'] );
			if ( is_file( $f ) ) {
				unlink( $f );
				$log->info( 'Removed the unfinished backup file.' );
			}
		}
	}
}
