<?php
/**
 * Tools → Site Health: sodium, storage not reachable from the web, a backup key, the age of the last backup, and
 * whether scheduled work runs.
 *
 * @package BastionBackup
 */

namespace BastionBackup;

defined( 'ABSPATH' ) || exit;

final class SiteHealth {

	/** @param array<string,array<string,mixed>> $tests */
	public static function tests( array $tests ): array {
		$tests['direct']['bastion_backup_sodium']  = array( 'label' => __( 'Backup encryption', 'bastion-backup' ), 'test' => array( self::class, 'sodium' ) );
		$tests['direct']['bastion_backup_key']     = array( 'label' => __( 'Backup key', 'bastion-backup' ), 'test' => array( self::class, 'key' ) );
		$tests['direct']['bastion_backup_recent']  = array( 'label' => __( 'Recent backup', 'bastion-backup' ), 'test' => array( self::class, 'recent' ) );
		$tests['direct']['bastion_backup_cron']    = array( 'label' => __( 'Scheduled backups run', 'bastion-backup' ), 'test' => array( self::class, 'cron' ) );
		$tests['async']['bastion_backup_storage']  = array( 'label' => __( 'Backups not reachable from the web', 'bastion-backup' ), 'test' => array( self::class, 'storage' ), 'has_rest' => false, 'async_direct_test' => array( self::class, 'storage' ) );
		return $tests;
	}

	private static function result( string $test, string $status, string $label, string $desc, string $action = '' ): array {
		return array(
			'label'       => $label,
			'status'      => $status,
			'badge'       => array( 'label' => __( 'Backups', 'bastion-backup' ), 'color' => 'good' === $status ? 'blue' : ( 'critical' === $status ? 'red' : 'orange' ) ),
			'description' => '<p>' . esc_html( $desc ) . '</p>',
			'actions'     => $action ? sprintf( '<p><a href="%s">%s</a></p>', esc_url( Admin::url( 'backups' ) ), esc_html( $action ) ) : '',
			'test'        => $test,
		);
	}

	public static function sodium(): array {
		if ( \BastionBackup\Core\Crypto::missing() ) {
			return self::result( 'bastion_backup_sodium', 'critical', __( 'Backups cannot be encrypted', 'bastion-backup' ), __( 'Ask the host to enable PHP\'s sodium extension.', 'bastion-backup' ) );
		}
		return \BastionBackup\Core\Crypto::native()
			? self::result( 'bastion_backup_sodium', 'good', __( 'Backups are encrypted at full speed', 'bastion-backup' ), __( 'PHP\'s sodium extension is loaded.', 'bastion-backup' ) )
			/* translators: %s: PHP version */
			: self::result( 'bastion_backup_sodium', 'recommended', __( 'Backups are encrypted slowly', 'bastion-backup' ), sprintf( __( 'PHP %s here has no sodium extension, so WordPress\'s built-in copy is used (about 1,000 times slower). Turn on the sodium extension for this PHP version.', 'bastion-backup' ), PHP_VERSION ) );
	}

	public static function key(): array {
		return Keys::current()
			? self::result( 'bastion_backup_key', 'good', __( 'A backup key is set', 'bastion-backup' ), __( 'Backups are encrypted to a key this site cannot open.', 'bastion-backup' ) )
			: self::result( 'bastion_backup_key', 'critical', __( 'No backup key: no backups are made', 'bastion-backup' ), __( 'Make the backup key in Bastion Backup.', 'bastion-backup' ), __( 'Make the backup key', 'bastion-backup' ) );
	}

	public static function recent(): array {
		$all  = Storage::localArchives();
		$last = $all ? (int) $all[0]['time'] : 0;
		$cur  = Jobs::current();
		if ( $cur && 'backup' === $cur['type'] && ! empty( $cur['finished'] ) && empty( $cur['error'] ) ) {
			$last = max( $last, (int) $cur['finished'] );
		}
		if ( $last > time() - 8 * DAY_IN_SECONDS ) {
			return self::result( 'bastion_backup_recent', 'good', __( 'There is a recent backup', 'bastion-backup' ), sprintf( /* translators: %s: time ago */ __( 'The last backup was made %s ago.', 'bastion-backup' ), human_time_diff( $last ) ) );
		}
		return self::result( 'bastion_backup_recent', 'recommended', __( 'No backup in the last week', 'bastion-backup' ), $last ? sprintf( /* translators: %s: time ago */ __( 'The last backup was made %s ago.', 'bastion-backup' ), human_time_diff( $last ) ) : __( 'No backup has been made yet.', 'bastion-backup' ), __( 'Back up now', 'bastion-backup' ) );
	}

	public static function cron(): array {
		if ( 'none' === Settings::get()['schedule'] ) {
			return self::result( 'bastion_backup_cron', 'recommended', __( 'Backups are not scheduled', 'bastion-backup' ), __( 'Backups are made only when someone presses Back up now.', 'bastion-backup' ) );
		}
		$next = wp_next_scheduled( Plugin::CRON_SCHEDULED );
		if ( ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) || ! $next || $next < time() - HOUR_IN_SECONDS ) {
			return self::result( 'bastion_backup_cron', 'critical', __( 'Scheduled backups may not run', 'bastion-backup' ), __( 'WordPress\'s scheduler looks stopped. If DISABLE_WP_CRON is set, the host must call wp-cron.php every few minutes.', 'bastion-backup' ) );
		}
		return self::result( 'bastion_backup_cron', 'good', __( 'Scheduled backups run', 'bastion-backup' ), sprintf( /* translators: %s: time */ __( 'The next one starts in %s.', 'bastion-backup' ), human_time_diff( $next ) ) );
	}

	/** Puts a harmless file in the storage folder and asks for it over HTTP: 403 or 404 means it is protected. */
	public static function storage(): array {
		if ( ! Storage::publicPath() ) {
			return self::result( 'bastion_backup_storage', 'good', __( 'Backups are outside the website', 'bastion-backup' ), __( 'The storage folder is not inside the website\'s folder.', 'bastion-backup' ) );
		}
		$name = 'canary-' . bin2hex( random_bytes( 6 ) ) . '.txt';
		$file = Storage::dir( 'archives' ) . '/' . $name;
		file_put_contents( $file, 'bastion' );
		$url  = trailingslashit( site_url() ) . Storage::relativeToAbspath() . '/archives/' . $name;
		$res  = wp_remote_get( $url, array( 'timeout' => 10, 'sslverify' => apply_filters( 'https_local_ssl_verify', false ), 'redirection' => 0 ) );
		unlink( $file );
		$code = is_wp_error( $res ) ? 0 : (int) wp_remote_retrieve_response_code( $res );
		$body = is_wp_error( $res ) ? '' : (string) wp_remote_retrieve_body( $res );
		if ( 200 === $code && 'bastion' === trim( $body ) ) {
			return self::result( 'bastion_backup_storage', 'critical', __( 'Backups can be downloaded from the web', 'bastion-backup' ), __( 'The web server ignores the deny rules (e.g. Nginx without them). Define BASTION_BACKUP_STORAGE in wp-config.php as a folder outside the website, or add a deny rule for the folder.', 'bastion-backup' ) );
		}
		return self::result( 'bastion_backup_storage', 'good', __( 'Backups cannot be downloaded from the web', 'bastion-backup' ), __( 'A test file in the storage folder could not be fetched over HTTP.', 'bastion-backup' ) );
	}
}
