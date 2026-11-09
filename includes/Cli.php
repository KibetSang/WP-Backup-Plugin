<?php
/**
 * WP-CLI: `wp bastion …`. The recommended way for very large sites: the same jobs, run in a loop with long steps.
 *
 * @package BastionBackup
 */

namespace BastionBackup;

use BastionBackup\Core\BackupException;
use BastionBackup\Core\Crypto;
use BastionBackup\Destinations\Registry;

defined( 'ABSPATH' ) || exit;

/**
 * Encrypted backups, checks and restores.
 */
final class Cli {

	/** Runs the current job to the end (or until it waits for confirmation). */
	private function drive(): array {
		$last = '';
		while ( true ) {
			$s = Jobs::run( microtime( true ) + 120 );
			$line = sprintf( '%3d%%  %s', (int) ( $s['percent'] ?? 0 ), (string) ( $s['message'] ?? '' ) );
			if ( $line !== $last ) {
				\WP_CLI::log( $line );
				$last = $line;
			}
			if ( ! empty( $s['finished'] ) || ! empty( $s['waiting'] ) || empty( $s['id'] ) ) {
				return $s;
			}
		}
	}

	private function end( array $s ): void {
		foreach ( (array) ( $s['warnings'] ?? array() ) as $w ) {
			\WP_CLI::warning( (string) $w );
		}
		if ( ! empty( $s['error'] ) ) {
			\WP_CLI::error( (string) $s['error'] );
		}
		\WP_CLI::success( (string) ( $s['message'] ?? 'Done.' ) );
	}

	/** The key from --key, or asked for without echoing it. */
	private function key( array $assoc ): string {
		$k = (string) ( $assoc['key'] ?? '' );
		if ( '' === $k ) {
			$k = getenv( 'BASTION_BACKUP_KEY' ) ?: '';
		}
		if ( '' === $k && function_exists( 'posix_isatty' ) && posix_isatty( STDIN ) ) {
			fwrite( STDERR, 'Backup key (BBK-…; what you type is shown — prefer BASTION_BACKUP_KEY): ' );
			$k = trim( (string) fgets( STDIN ) );
		}
		if ( '' === $k ) {
			\WP_CLI::error( 'Give the backup key with --key, the BASTION_BACKUP_KEY environment variable, or type it when asked.' );
		}
		try {
			Crypto::parseKey( $k );
		} catch ( BackupException $e ) {
			\WP_CLI::error( $e->getMessage() );
		}
		return $k;
	}

	/**
	 * Makes a backup now.
	 *
	 * ## OPTIONS
	 *
	 * [--db-only]
	 * : Only the database.
	 *
	 * [--files-only]
	 * : Only the files.
	 *
	 * [--no-upload]
	 * : Keep it on this server only.
	 *
	 * [--to=<destinations>]
	 * : Comma-separated: google, onedrive, fileserver. Default: those chosen in the settings.
	 *
	 * @param array<int,string>    $args
	 * @param array<string,string> $assoc
	 */
	public function backup( array $args, array $assoc ): void {
		$s     = Settings::get();
		$dests = isset( $assoc['to'] ) ? array_filter( array_map( 'trim', explode( ',', (string) $assoc['to'] ) ) ) : $s['destinations'];
		try {
			Jobs::start(
				'backup',
				array(
					'db'     => ! isset( $assoc['files-only'] ),
					'files'  => ! isset( $assoc['db-only'] ),
					'dests'  => isset( $assoc['no-upload'] ) ? array() : array_values( array_intersect( $dests, array_keys( Registry::classes() ) ) ),
					'reason' => 'cli',
				)
			);
			Audit::log( 'backup.started', 'WP-CLI' );
		} catch ( BackupException $e ) {
			\WP_CLI::error( $e->getMessage() );
		}
		$this->end( $this->drive() );
	}

	/**
	 * Checks every part of a backup with the key, writing nothing.
	 *
	 * ## OPTIONS
	 *
	 * <backup>
	 * : A backup's name on this server, or <destination>:<remote id> (see `wp bastion list --destination=…`).
	 *
	 * [--key=<key>]
	 * : The backup key. Better: the BASTION_BACKUP_KEY environment variable, or type it when asked.
	 *
	 * @param array<int,string>    $args
	 * @param array<string,string> $assoc
	 */
	public function verify( array $args, array $assoc ): void {
		$key = $this->key( $assoc );
		try {
			Jobs::start( 'restore', array( 'source' => $this->source( $args[0] ), 'verify_only' => true ), array( 'key' => Vault::seal( $key ) ) );
		} catch ( BackupException $e ) {
			\WP_CLI::error( $e->getMessage() );
		}
		$this->end( $this->drive() );
	}

	/**
	 * Restores a backup on this site.
	 *
	 * ## OPTIONS
	 *
	 * <backup>
	 * : A backup's name on this server, a path to a .bbk file, or <destination>:<remote id>.
	 *
	 * [--key=<key>]
	 * : The backup key (or BASTION_BACKUP_KEY, or type it when asked).
	 *
	 * [--db-only]
	 * : Only the database.
	 *
	 * [--files-only]
	 * : Only the files.
	 *
	 * [--yes]
	 * : Do not ask for confirmation.
	 *
	 * @param array<int,string>    $args
	 * @param array<string,string> $assoc
	 */
	public function restore( array $args, array $assoc ): void {
		$key = $this->key( $assoc );
		try {
			Jobs::start( 'restore', array( 'source' => $this->source( $args[0] ), 'verify_only' => false ), array( 'key' => Vault::seal( $key ) ) );
		} catch ( BackupException $e ) {
			\WP_CLI::error( $e->getMessage() );
		}
		$s = $this->drive();
		if ( ! empty( $s['finished'] ) ) {
			$this->end( $s );
			return;
		}
		$m = (array) ( $s['manifest'] ?? array() );
		\WP_CLI::log( sprintf( 'The backup is intact. Made %s for %s (WordPress %s).', gmdate( 'Y-m-d H:i', (int) ( $m['created'] ?? 0 ) ) . ' UTC', (string) ( $m['site_url'] ?? '?' ), (string) ( $m['wp'] ?? '?' ) ) );
		\WP_CLI::confirm( 'Restore it over this site now?', $assoc );
		Audit::log( 'restore.confirmed', 'WP-CLI' );
		Jobs::resume(
			array(
				'phase'   => 'prepare',
				'options' => array(
					'db'    => ! isset( $assoc['files-only'] ),
					'files' => ! isset( $assoc['db-only'] ) && ! ( defined( 'DISALLOW_FILE_MODS' ) && DISALLOW_FILE_MODS ),
				),
			)
		);
		$this->end( $this->drive() );
	}

	/**
	 * Lists backups.
	 *
	 * ## OPTIONS
	 *
	 * [--destination=<id>]
	 * : google, onedrive or fileserver. Default: this server.
	 *
	 * [--format=<format>]
	 * : table, csv, json or yaml.
	 * ---
	 * default: table
	 * ---
	 *
	 * @param array<int,string>    $args
	 * @param array<string,string> $assoc
	 */
	public function list( array $args, array $assoc ): void {
		try {
			$where = (string) ( $assoc['destination'] ?? 'local' );
			$items = 'local' === $where ? Storage::localArchives() : Registry::get( $where )->list();
		} catch ( BackupException $e ) {
			\WP_CLI::error( $e->getMessage() );
		}
		$rows = array_map(
			static fn( $i ) => array(
				'id'   => $i['id'] ?? $i['name'],
				'name' => $i['name'],
				'size' => size_format( (int) $i['size'] ),
				'made' => gmdate( 'Y-m-d H:i', (int) $i['time'] ) . ' UTC',
			),
			$items
		);
		\WP_CLI\Utils\format_items( (string) ( $assoc['format'] ?? 'table' ), $rows, array( 'id', 'name', 'size', 'made' ) );
	}

	/**
	 * Shows the current or last job.
	 */
	public function status(): void {
		$s = Jobs::status();
		if ( empty( $s['id'] ) ) {
			\WP_CLI::log( 'No job.' );
			return;
		}
		\WP_CLI::log( sprintf( '%s %s: %s (%d%%)%s', (string) $s['type'], (string) $s['id'], (string) ( $s['message'] ?? '' ), (int) ( $s['percent'] ?? 0 ), ! empty( $s['finished'] ) ? ', finished' : ( ! empty( $s['waiting'] ) ? ', waiting for confirmation' : '' ) ) );
	}

	/**
	 * Cancels the running job.
	 */
	public function cancel(): void {
		$s = Jobs::cancel();
		\WP_CLI::success( (string) ( $s['message'] ?? 'Cancelled.' ) );
	}

	/** @return array<string,string> */
	private function source( string $arg ): array {
		if ( preg_match( '/^(google|onedrive|fileserver):(.+)$/', $arg, $m ) ) {
			$items = Registry::get( $m[1] )->list();
			foreach ( $items as $i ) {
				if ( $i['id'] === $m[2] || $i['name'] === $m[2] ) {
					return array( 'kind' => 'remote', 'dest' => $m[1], 'id' => $i['id'], 'name' => $i['name'] );
				}
			}
			throw new BackupException( 'No such backup on ' . $m[1] . '.' );
		}
		if ( is_file( $arg ) && ! Registry::isOurs( $arg ) ) {
			// A file elsewhere on the server: copied into the protected storage first.
			$name = basename( $arg );
			if ( ! Registry::isOurs( $name ) ) {
				throw new BackupException( 'Not a Bastion Backup file name.' );
			}
			$to = Storage::archivePath( $name );
			if ( ! is_file( $to ) && ! copy( $arg, $to ) ) {
				throw new BackupException( 'Cannot copy the backup into the storage folder.' );
			}
			return array( 'kind' => 'local', 'name' => $name );
		}
		Storage::archivePath( $arg );
		return array( 'kind' => 'local', 'name' => basename( $arg ) );
	}
}
