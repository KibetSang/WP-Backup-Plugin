<?php
/**
 * Runs one job at a time in time-boxed, resumable steps, driven three ways: the admin page polling while open, WP-Cron
 * chaining itself (so it carries on when the tab is closed), and WP-CLI.
 *
 * The job's state is a JSON file (mode 0600) in the protected storage folder: it holds stream state, which is key
 * material, and is deleted when the job ends. A flock around each step means two drivers never step at once; a job
 * whose heartbeat is older than five minutes is simply picked up again by the next driver.
 *
 * @package BastionBackup
 */

namespace BastionBackup;

use BastionBackup\Core\BackupException;

defined( 'ABSPATH' ) || exit;

final class Jobs {

	public const CURRENT = 'bastion_backup_current_job';
	public const STALE   = 300;

	/** @return array<string,mixed>|null What the admin page shows. */
	public static function current(): ?array {
		$c = get_option( self::CURRENT, null );
		return is_array( $c ) && ! empty( $c['id'] ) ? $c : null;
	}

	public static function running(): bool {
		$c = self::current();
		return $c && empty( $c['finished'] );
	}

	/**
	 * Starts a job. Refuses while another is unfinished. $private goes into the state file only (e.g. the sealed
	 * restore key), never into what the page reads.
	 */
	public static function start( string $type, array $params, array $private = array() ): array {
		if ( self::running() ) {
			throw new BackupException( 'A backup or restore is already running. Wait for it to finish, or cancel it.' );
		}
		Storage::ensure();
		$id = gmdate( 'Ymd-His' ) . '-' . bin2hex( random_bytes( 3 ) );
		$st = array(
			'id'       => $id,
			'type'     => $type,
			'params'   => $params,
			'phase'    => 'init',
			'percent'  => 0,
			'message'  => 'Starting…',
			'warnings' => array(),
			'started'  => time(),
			'by'       => self::who(),
		) + $private;
		self::saveState( $id, $st );
		self::publish( $st );
		( new Logger( $id ) )->info( sprintf( '%s started by %s.', ucfirst( $type ), $st['by'] ) );
		self::chain( $id, 0 );
		return self::status();
	}

	private static function who(): string {
		$u = wp_get_current_user();
		if ( $u && $u->ID ) {
			return (string) $u->user_login;
		}
		return defined( 'WP_CLI' ) && WP_CLI ? 'WP-CLI' : 'schedule';
	}

	/** Seconds one step may run: the setting, but never more than 60 % of PHP's time limit. */
	public static function deadline(): float {
		$secs = (float) Settings::get()['step_seconds'];
		$max  = (int) ini_get( 'max_execution_time' );
		if ( $max > 0 ) {
			$secs = min( $secs, $max * 0.6 );
		}
		return microtime( true ) + max( 3.0, $secs );
	}

	/**
	 * Runs one step of the current job if no other request is doing so. Returns the status for the page.
	 */
	public static function run( ?float $deadline = null ): array {
		$cur = self::current();
		if ( ! $cur || ! empty( $cur['finished'] ) ) {
			return self::status();
		}
		$lock = fopen( Storage::dir( 'jobs' ) . '/.lock', 'c' );
		if ( ! $lock || ! flock( $lock, LOCK_EX | LOCK_NB ) ) {
			if ( $lock ) {
				fclose( $lock );
			}
			return self::status(); // Another request is stepping it right now.
		}
		$id       = (string) $cur['id'];
		$deadline = $deadline ?? self::deadline(); // Before set_time_limit(0), which would hide PHP's own limit.
		try {
			$st = self::loadState( $id );
			if ( null === $st ) {
				self::publish( array_merge( $cur, array( 'finished' => true, 'error' => 'The job\'s working files are gone; start it again.' ) ) );
				return self::status();
			}
			if ( ! empty( $st['waiting'] ) ) {
				self::publish( $st );
				return self::status();
			}
			@set_time_limit( 0 );
			wp_raise_memory_limit( 'admin' );
			$log = new Logger( $id );
			$job = self::job( (string) $st['type'] );
			try {
				$job->step( $st, $deadline, $log );
			} catch ( \Throwable $e ) {
				$msg = $e instanceof BackupException ? $e->getMessage() : 'Unexpected error: ' . $e->getMessage();
				$log->error( $msg );
				$st['error']   = $msg;
				$st['message'] = $msg;
				$job->cleanup( $st, $log );
				self::finish( $st, $log );
				return self::status();
			}
			$st['heartbeat'] = time();
			if ( ! empty( $st['done'] ) ) {
				self::finish( $st, $log );
			} else {
				self::saveState( $id, $st );
				self::publish( $st );
				if ( empty( $st['waiting'] ) ) {
					self::chain( $id, (int) ( $st['ticks'] ?? 0 ) + 1 );
				}
			}
			return self::status();
		} finally {
			flock( $lock, LOCK_UN );
			fclose( $lock );
		}
	}

	private static function finish( array $st, Logger $log ): void {
		$st['finished'] = time();
		if ( empty( $st['error'] ) && empty( $st['cancelled'] ) ) {
			$st['percent'] = 100;
		}
		$log->info( empty( $st['error'] ) ? ( $st['cancelled'] ?? false ? 'Cancelled.' : 'Finished.' ) : 'Stopped: ' . $st['error'] );
		self::deleteState( (string) $st['id'] );
		self::publish( $st );
		wp_clear_scheduled_hook( Plugin::CRON_TICK, array( (string) $st['id'], (int) ( $st['ticks'] ?? 0 ) + 1 ) );
		if ( 'backup' === $st['type'] ) {
			Notifier::backupFinished( $st );
		}
	}

	/** The next WP-Cron tick, with unique arguments so WordPress's ten-minute duplicate guard does not drop it. */
	private static function chain( string $id, int $n ): void {
		$st = self::loadState( $id );
		if ( $st ) {
			$st['ticks'] = $n;
			self::saveState( $id, $st );
		}
		wp_schedule_single_event( time(), Plugin::CRON_TICK, array( $id, $n ) );
		if ( ! ( defined( 'WP_CLI' ) && WP_CLI ) && ! ( defined( 'DOING_CRON' ) && DOING_CRON ) ) {
			spawn_cron();
		}
	}

	/** WP-Cron driver. */
	public static function cronTick( string $id = '', int $n = 0 ): void {
		$cur = self::current();
		if ( $cur && (string) $cur['id'] === $id ) {
			self::run();
		}
	}

	/** The scheduled backup (Schedule & Retention). Skipped, with a log line, when there is no key or a job is running. */
	public static function startScheduled(): void {
		$s = Settings::get();
		try {
			if ( self::running() ) {
				$c = self::current();
				if ( time() - (int) ( $c['heartbeat'] ?? $c['started'] ?? 0 ) < self::STALE ) {
					return;
				}
			}
			Keys::require();
			Audit::log( 'backup.scheduled' );
			self::start(
				'backup',
				array(
					'db'     => $s['backup_db'],
					'files'  => $s['backup_files'],
					'dests'  => $s['destinations'],
					'reason' => 'schedule',
				)
			);
		} catch ( BackupException $e ) {
			Notifier::scheduledSkipped( $e->getMessage() );
		}
	}

	public static function cancel(): array {
		$cur = self::current();
		if ( ! $cur || ! empty( $cur['finished'] ) ) {
			return self::status();
		}
		$id  = (string) $cur['id'];
		$log = new Logger( $id );
		$st  = self::loadState( $id ) ?? $cur;
		$st['cancelled'] = true;
		$st['message']   = 'Cancelled.';
		try {
			self::job( (string) $st['type'] )->cleanup( $st, $log );
		} catch ( \Throwable $e ) {
			$log->warn( 'Clean-up after cancel: ' . $e->getMessage() );
		}
		Audit::log( $st['type'] . '.cancelled', $id );
		self::finish( $st, $log );
		return self::status();
	}

	/** Moves a waiting job on (a restore that the administrator confirmed). */
	public static function resume( array $changes ): array {
		$cur = self::current();
		if ( ! $cur || ! empty( $cur['finished'] ) ) {
			throw new BackupException( 'There is no job waiting.' );
		}
		$st = self::loadState( (string) $cur['id'] );
		if ( ! $st || empty( $st['waiting'] ) ) {
			throw new BackupException( 'The job is not waiting for you.' );
		}
		$st            = array_merge( $st, $changes );
		$st['waiting'] = false;
		self::saveState( (string) $st['id'], $st );
		self::publish( $st );
		self::chain( (string) $st['id'], (int) ( $st['ticks'] ?? 0 ) + 1 );
		return self::status();
	}

	/** Clears a finished job from the page. */
	public static function dismiss(): void {
		if ( self::current() && ! self::running() ) {
			delete_option( self::CURRENT );
		}
	}

	/** @return array<string,mixed> {id,type,phase,percent,bytes_done,bytes_total,message,warnings,error,finished,waiting,result,log_tail} */
	public static function status(): array {
		$c = self::current();
		if ( ! $c ) {
			return array( 'id' => null );
		}
		$c['log_tail'] = ( new Logger( (string) $c['id'] ) )->tail( 25 );
		$c['stale']    = empty( $c['finished'] ) && empty( $c['waiting'] ) && time() - (int) ( $c['heartbeat'] ?? $c['started'] ) > self::STALE;
		return $c;
	}

	private static function job( string $type ): Job {
		return match ( $type ) {
			'backup'  => new BackupJob(),
			'restore' => new RestoreJob(),
			default   => throw new BackupException( 'Unknown job type.' ),
		};
	}

	/** What the page may see: never key material, paths of temporary files or the sealed restore key. */
	private static function publish( array $st ): void {
		$keep = array( 'id', 'type', 'phase', 'percent', 'bytes_done', 'bytes_total', 'message', 'warnings', 'error', 'finished', 'waiting', 'cancelled', 'started', 'heartbeat', 'by', 'result', 'manifest', 'params' );
		$pub  = array_intersect_key( $st, array_flip( $keep ) );
		if ( isset( $pub['params'] ) ) {
			$pub['params'] = array_intersect_key( (array) $pub['params'], array_flip( array( 'db', 'files', 'dests', 'reason', 'verify_only', 'source' ) ) );
		}
		$pub['warnings'] = array_slice( (array) ( $pub['warnings'] ?? array() ), -20 );
		update_option( self::CURRENT, $pub, false );
	}

	private static function stateFile( string $id ): string {
		if ( ! preg_match( '/^\d{8}-\d{6}-[a-f0-9]{6}$/', $id ) ) {
			throw new BackupException( 'Invalid job.' );
		}
		return Storage::dir( 'jobs' ) . '/' . $id . '.json';
	}

	public static function loadState( string $id ): ?array {
		$f = self::stateFile( $id );
		if ( ! is_file( $f ) ) {
			return null;
		}
		$d = json_decode( (string) file_get_contents( $f ), true );
		return is_array( $d ) ? $d : null;
	}

	public static function saveState( string $id, array $st ): void {
		$f   = self::stateFile( $id );
		$tmp = $f . '.tmp';
		if ( false === file_put_contents( $tmp, (string) wp_json_encode( $st ), LOCK_EX ) ) {
			throw new BackupException( 'Cannot save the job\'s progress — the disk may be full.' );
		}
		chmod( $tmp, 0600 );
		rename( $tmp, $f ); // Atomic: a killed request never leaves half a state file.
	}

	private static function deleteState( string $id ): void {
		$f = self::stateFile( $id );
		foreach ( array( $f, $f . '.tmp', Storage::dir( 'jobs' ) . '/' . $id . '.list' ) as $x ) {
			if ( is_file( $x ) ) {
				unlink( $x );
			}
		}
	}

	/** The file list a backup builds while scanning. */
	public static function listFile( string $id ): string {
		self::stateFile( $id );
		return Storage::dir( 'jobs' ) . '/' . $id . '.list';
	}
}
