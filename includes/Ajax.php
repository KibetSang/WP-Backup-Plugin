<?php
/**
 * The admin page's requests (admin-ajax). Every one checks the capability and the nonce; anything that changes the
 * key, deletes, restores or makes the installer also asks for the administrator's WordPress password again (five
 * wrong tries lock it for 15 minutes). Downloads stream through admin-post, never from a public address.
 *
 * @package BastionBackup
 */

namespace BastionBackup;

use BastionBackup\Core\BackupException;
use BastionBackup\Core\Crypto;
use BastionBackup\Destinations\FileServer;
use BastionBackup\Destinations\OAuthDestination;
use BastionBackup\Destinations\Registry;

defined( 'ABSPATH' ) || exit;

final class Ajax {

	private const ACTIONS = array(
		'tick', 'backup', 'cancel', 'dismiss', 'key', 'list', 'delete', 'check', 'restore_check', 'restore_confirm',
		'upload_begin', 'upload_piece', 'settings', 'dest_save', 'dest_connect', 'dest_disconnect', 'dest_test', 'installer',
	);

	public static function init(): void {
		foreach ( self::ACTIONS as $a ) {
			add_action( 'wp_ajax_bbk_' . $a, array( self::class, 'handle' ) );
		}
	}

	/** One entry point: capability, nonce, then the action; errors come back as JSON with a message. */
	public static function handle(): void {
		$action = substr( (string) current_action(), strlen( 'wp_ajax_bbk_' ) );
		if ( ! current_user_can( Plugin::cap() ) ) {
			wp_send_json_error( array( 'message' => __( 'You are not allowed to manage backups.', 'bastion-backup' ) ), 403 );
		}
		if ( ! check_ajax_referer( 'bastion_backup', '_ajax_nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'This page has expired. Reload it and try again.', 'bastion-backup' ) ), 403 );
		}
		try {
			$method = 'do' . str_replace( ' ', '', ucwords( str_replace( '_', ' ', $action ) ) );
			wp_send_json_success( self::$method() );
		} catch ( BackupException $e ) {
			wp_send_json_error( array( 'message' => $e->getMessage() ), 400 );
		}
	}

	private static function in( string $k, string $default = '' ): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in handle().
		return isset( $_POST[ $k ] ) && ! is_array( $_POST[ $k ] ) ? (string) wp_unslash( $_POST[ $k ] ) : $default;
	}

	/** @return string[] */
	private static function inList( string $k ): array {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in handle().
		return isset( $_POST[ $k ] ) && is_array( $_POST[ $k ] ) ? array_map( 'strval', wp_unslash( $_POST[ $k ] ) ) : array();
	}

	/** Asks for the WordPress password again before anything that cannot be undone. */
	public static function reauth( string $password ): void {
		$u   = wp_get_current_user();
		$key = 'bbk_reauth_' . $u->ID;
		$n   = (int) get_transient( $key );
		if ( $n >= 5 ) {
			throw new BackupException( __( 'Too many wrong passwords. Wait 15 minutes and try again.', 'bastion-backup' ) );
		}
		if ( '' === $password || ! wp_check_password( $password, $u->user_pass, $u->ID ) ) {
			set_transient( $key, $n + 1, 15 * MINUTE_IN_SECONDS );
			Audit::log( 'reauth.failed' );
			throw new BackupException( __( 'That is not your WordPress password.', 'bastion-backup' ) );
		}
		delete_transient( $key );
	}

	private static function doTick(): array {
		$d = Jobs::deadline();
		return Jobs::run( min( $d, microtime( true ) + 15 ) );
	}

	private static function doBackup(): array {
		Keys::require();
		$dests = array_values( array_intersect( self::inList( 'dests' ), array_keys( Registry::classes() ) ) );
		$db    = '1' === self::in( 'db' );
		$files = '1' === self::in( 'files' );
		if ( ! $db && ! $files ) {
			throw new BackupException( __( 'Choose the database, the files or both.', 'bastion-backup' ) );
		}
		Audit::log( 'backup.started', ( $db ? 'database' : '' ) . ( $db && $files ? ' + ' : '' ) . ( $files ? 'files' : '' ) . ( $dests ? ' → ' . implode( ', ', $dests ) : '' ) );
		return Jobs::start( 'backup', array( 'db' => $db, 'files' => $files, 'dests' => $dests, 'reason' => 'manual' ) );
	}

	private static function doCancel(): array {
		return Jobs::cancel();
	}

	private static function doDismiss(): array {
		Jobs::dismiss();
		return Jobs::status();
	}

	private static function doKey(): array {
		self::reauth( self::in( 'password' ) );
		if ( Jobs::running() ) {
			throw new BackupException( __( 'Wait for the running job to finish before changing the key.', 'bastion-backup' ) );
		}
		$replacing = (bool) Keys::current();
		$k         = Keys::make( wp_get_current_user()->user_login );
		Audit::log( $replacing ? 'key.replaced' : 'key.made', $k['id'] );
		return $k;
	}

	private static function doList(): array {
		$where = self::in( 'where', 'local' );
		if ( 'local' === $where ) {
			$out = array();
			foreach ( Storage::localArchives() as $a ) {
				try {
					$h = Core\ArchiveReader::readHeader( Storage::archivePath( $a['name'] ) );
				} catch ( BackupException $e ) {
					$h = array();
				}
				$out[] = $a + array(
					'id'  => $a['name'],
					'kid' => (string) ( $h['kid'] ?? '' ),
				);
			}
			return array( 'items' => $out );
		}
		$dest = Registry::get( $where );
		if ( ! $dest->isConfigured() ) {
			return array( 'items' => array() );
		}
		return array( 'items' => $dest->list() );
	}

	private static function doDelete(): array {
		self::reauth( self::in( 'password' ) );
		$where = self::in( 'where' );
		$id    = self::in( 'id' );
		if ( 'local' === $where ) {
			$f = Storage::archivePath( $id );
			if ( is_file( $f ) ) {
				unlink( $f );
			}
		} else {
			$dest = Registry::get( $where );
			$name = self::in( 'name' );
			if ( ! Registry::isOurs( $name ) ) {
				throw new BackupException( __( 'Only backups made by this plugin can be deleted here.', 'bastion-backup' ) );
			}
			$dest->delete( $id );
		}
		Audit::log( 'backup.deleted', $where . ': ' . ( self::in( 'name' ) ?: $id ) );
		return array( 'ok' => true );
	}

	/** @return array{kind:string,name?:string,dest?:string,id?:string,upload?:string} */
	private static function source(): array {
		$where = self::in( 'where' );
		if ( 'upload' === $where ) {
			return array( 'kind' => 'upload', 'upload' => self::in( 'upload' ), 'name' => self::in( 'name' ) );
		}
		if ( 'local' === $where ) {
			Storage::archivePath( self::in( 'id' ) ); // Validates the name.
			return array( 'kind' => 'local', 'name' => self::in( 'id' ) );
		}
		Registry::get( $where );
		return array( 'kind' => 'remote', 'dest' => $where, 'id' => self::in( 'id' ), 'name' => self::in( 'name' ) );
	}

	private static function sealedKey(): string {
		$k = self::in( 'key' );
		Crypto::parseKey( $k ); // A typo is caught here, before any work.
		return Vault::seal( $k );
	}

	private static function doCheck(): array {
		$params = array( 'source' => self::source(), 'verify_only' => true );
		// The key goes into the job's state file only, sealed; never into what the page reads.
		return Jobs::start( 'restore', $params, array( 'key' => self::sealedKey() ) );
	}

	private static function doRestoreCheck(): array {
		$params = array( 'source' => self::source(), 'verify_only' => false );
		return Jobs::start( 'restore', $params, array( 'key' => self::sealedKey() ) );
	}

	private static function doRestoreConfirm(): array {
		if ( 'RESTORE' !== self::in( 'confirm' ) ) {
			throw new BackupException( __( 'Type RESTORE, in capitals, to confirm.', 'bastion-backup' ) );
		}
		self::reauth( self::in( 'password' ) );
		$db    = '1' === self::in( 'db' );
		$files = '1' === self::in( 'files' ) && ! ( defined( 'DISALLOW_FILE_MODS' ) && DISALLOW_FILE_MODS );
		if ( ! $db && ! $files ) {
			throw new BackupException( __( 'Choose the database, the files or both.', 'bastion-backup' ) );
		}
		$cur = Jobs::current();
		if ( ! $cur || 'restore' !== $cur['type'] || 'ready' !== $cur['phase'] ) {
			throw new BackupException( __( 'Check the backup first.', 'bastion-backup' ) );
		}
		return Jobs::resume(
			array(
				'phase'   => 'prepare',
				'options' => array( 'db' => $db, 'files' => $files ),
				'message' => __( 'Restoring…', 'bastion-backup' ),
			)
		);
	}

	private static function doUploadBegin(): array {
		return array( 'id' => Uploads::begin( self::in( 'name' ), (int) self::in( 'size' ) ) );
	}

	private static function doUploadPiece(): array {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in handle().
		$f = $_FILES['piece'] ?? null;
		if ( ! is_array( $f ) || UPLOAD_ERR_OK !== (int) $f['error'] || ! is_uploaded_file( (string) $f['tmp_name'] ) ) {
			throw new BackupException( __( 'A piece of the upload did not arrive. It will be sent again.', 'bastion-backup' ) );
		}
		$data = (string) file_get_contents( (string) $f['tmp_name'] );
		return array( 'have' => Uploads::piece( self::in( 'id' ), (int) self::in( 'offset' ), $data ) );
	}

	private static function doSettings(): array {
		$in  = array();
		$cur = Settings::get();
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in handle().
		$post    = wp_unslash( $_POST );
		$section = self::in( 'section' );
		$fields  = 'schedule' === $section
			? array( 'schedule', 'schedule_time', 'backup_db', 'backup_files', 'destinations', 'retain_local', 'retain_remote', 'keep_local' )
			: array( 'excludes', 'max_file_mb', 'all_tables', 'exclude_tables', 'step_seconds', 'notify_email', 'notify_on', 'delete_on_uninstall' );
		foreach ( $fields as $f ) {
			$in[ $f ] = $post[ $f ] ?? ( is_bool( $cur[ $f ] ) ? false : ( is_array( $cur[ $f ] ) ? array() : '' ) );
		}
		Settings::save( array_merge( $cur, $in ) );
		Audit::log( 'settings.saved', $section );
		return array( 'message' => __( 'Saved.', 'bastion-backup' ) );
	}

	private static function dest(): Destinations\Destination {
		return Registry::get( self::in( 'dest' ) );
	}

	private static function doDestSave(): array {
		$d = self::dest();
		if ( $d instanceof OAuthDestination ) {
			$cid = trim( self::in( 'client_id' ) );
			if ( '' !== $cid && ! preg_match( '/^[A-Za-z0-9._-]{8,200}$/', $cid ) ) {
				throw new BackupException( __( 'That does not look like a client ID.', 'bastion-backup' ) );
			}
			$d->saveApp( $cid, trim( self::in( 'client_secret' ) ) );
			$c = Settings::destination( $d->id() );
			$c['folder'] = sanitize_text_field( self::in( 'folder' ) );
			if ( 'onedrive' === $d->id() ) {
				$c['tenant'] = sanitize_text_field( self::in( 'tenant' ) );
			}
			Settings::setDestination( $d->id(), $c );
		} elseif ( $d instanceof FileServer ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in handle().
			$d->save( array_map( static fn( $v ) => is_string( $v ) ? $v : '', wp_unslash( $_POST ) ) );
		}
		Audit::log( 'destination.saved', $d->label() );
		return array( 'message' => __( 'Saved.', 'bastion-backup' ), 'configured' => $d->isConfigured() );
	}

	private static function doDestConnect(): array {
		$d = self::dest();
		if ( ! $d instanceof OAuthDestination ) {
			throw new BackupException( 'Not an account destination.' );
		}
		return array( 'url' => $d->beginConnect( get_current_user_id() ) );
	}

	private static function doDestDisconnect(): array {
		$d = self::dest();
		if ( $d instanceof OAuthDestination ) {
			$d->disconnect();
		} elseif ( $d instanceof FileServer ) {
			$d->forget();
		}
		$s                 = Settings::get();
		$s['destinations'] = array_values( array_diff( (array) $s['destinations'], array( $d->id() ) ) );
		Settings::save( $s );
		Audit::log( 'destination.removed', $d->label() );
		return array( 'message' => __( 'Removed. Backups already there are left as they are.', 'bastion-backup' ) );
	}

	private static function doDestTest(): array {
		$r = self::dest()->test();
		if ( true !== $r ) {
			throw new BackupException( $r );
		}
		return array( 'message' => __( 'Working: a test file was written and deleted.', 'bastion-backup' ) );
	}

	private static function doInstaller(): array {
		self::reauth( self::in( 'password' ) );
		$made = InstallerBuilder::prepare();
		Audit::log( 'installer.made' );
		return array(
			'access' => $made['access'],
			'url'    => wp_nonce_url( admin_url( 'admin-post.php?action=bbk_installer' ), 'bbk_installer' ),
		);
	}

	/** admin-post: a local backup, streamed (never served from a public address). */
	public static function download(): void {
		if ( ! current_user_can( Plugin::cap() ) || ! check_admin_referer( 'bbk_download' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'bastion-backup' ), 403 );
		}
		try {
			$name = isset( $_GET['name'] ) ? sanitize_file_name( wp_unslash( (string) $_GET['name'] ) ) : '';
			$file = Storage::archivePath( $name );
		} catch ( BackupException $e ) {
			wp_die( esc_html( $e->getMessage() ), 400 );
		}
		if ( ! is_file( $file ) ) {
			wp_die( esc_html__( 'That backup is no longer on this server.', 'bastion-backup' ), 404 );
		}
		Audit::log( 'backup.downloaded', $name );
		self::stream( $file, $name, 'application/octet-stream' );
	}

	public static function installerDownload(): void {
		if ( ! current_user_can( Plugin::cap() ) || ! check_admin_referer( 'bbk_installer' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'bastion-backup' ), 403 );
		}
		try {
			$file = InstallerBuilder::built();
		} catch ( BackupException $e ) {
			wp_die( esc_html( $e->getMessage() ), 400 );
		}
		self::stream( $file, 'installer.php', 'application/octet-stream', true );
	}

	public static function logDownload(): void {
		if ( ! current_user_can( Plugin::cap() ) || ! check_admin_referer( 'bbk_log' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'bastion-backup' ), 403 );
		}
		$job  = isset( $_GET['job'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['job'] ) ) : '';
		$file = preg_match( '/^\d{8}-\d{6}-[a-f0-9]{6}$/', $job ) ? ( new Logger( $job ) )->path() : '';
		if ( '' === $file || ! is_file( $file ) ) {
			wp_die( esc_html__( 'No such log.', 'bastion-backup' ), 404 );
		}
		self::stream( $file, 'bastion-backup-' . $job . '.log', 'text/plain' );
	}

	private static function stream( string $file, string $name, string $type, bool $deleteAfter = false ): never {
		nocache_headers();
		header( 'Content-Type: ' . $type );
		header( 'Content-Disposition: attachment; filename="' . str_replace( '"', '', $name ) . '"' );
		header( 'Content-Length: ' . filesize( $file ) );
		header( 'X-Content-Type-Options: nosniff' );
		while ( ob_get_level() ) {
			ob_end_clean();
		}
		@set_time_limit( 0 );
		$fh = fopen( $file, 'rb' );
		while ( $fh && ! feof( $fh ) ) {
			echo fread( $fh, 1048576 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- binary file.
			flush();
		}
		if ( $fh ) {
			fclose( $fh );
		}
		if ( $deleteAfter ) {
			unlink( $file );
		}
		exit;
	}
}
