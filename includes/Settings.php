<?php
/**
 * Non-secret settings (secrets live in Vault).
 */

namespace BastionBackup;

defined( 'ABSPATH' ) || exit;

final class Settings {

	public const OPTION = 'bastion_backup_settings';

	public static function defaults(): array {
		return array(
			'schedule'         => 'none',
			'backup_db'        => true,
			'backup_files'     => true,
			'all_tables'       => false,
			'destinations'     => array(),
			'keep_local'       => true,
			'retain_local'     => 3,
			'retain_remote'    => 7,
			'excludes'         => array(
				'wp-content/cache',
				'wp-content/upgrade',
				'wp-content/upgrade-temp-backup',
				'wp-content/updraft',
				'wp-content/ai1wm-backups',
				'wp-content/backups-dup-lite',
				'wp-content/backups-dup-pro',
				'wp-snapshots',
				'wp-content/wflogs',
				'*.bbk-part',
				'.maintenance',
				'error_log',
				'node_modules',
			),
			'max_file_mb'      => 0,
			'step_seconds'     => 20,
			'notify_email'     => '',
			'verify_before'    => true,
		);
	}

	public static function get(): array {
		$s = get_option( self::OPTION, array() );
		return array_merge( self::defaults(), is_array( $s ) ? $s : array() );
	}

	public static function sanitize( array $in ): array {
		$d         = self::defaults();
		$schedules = array_merge( array( 'none' ), array_keys( wp_get_schedules() ) );
		$out       = array(
			'schedule'      => in_array( $in['schedule'] ?? '', $schedules, true ) ? $in['schedule'] : 'none',
			'backup_db'     => ! empty( $in['backup_db'] ),
			'backup_files'  => ! empty( $in['backup_files'] ),
			'all_tables'    => ! empty( $in['all_tables'] ),
			'keep_local'    => ! empty( $in['keep_local'] ),
			'verify_before' => ! empty( $in['verify_before'] ),
			'retain_local'  => max( 1, min( 100, (int) ( $in['retain_local'] ?? $d['retain_local'] ) ) ),
			'retain_remote' => max( 1, min( 365, (int) ( $in['retain_remote'] ?? $d['retain_remote'] ) ) ),
			'max_file_mb'   => max( 0, (int) ( $in['max_file_mb'] ?? 0 ) ),
			'step_seconds'  => max( 5, min( 120, (int) ( $in['step_seconds'] ?? 20 ) ) ),
			'notify_email'  => sanitize_email( (string) ( $in['notify_email'] ?? '' ) ),
			'destinations'  => array_values( array_intersect( (array) ( $in['destinations'] ?? array() ), array_keys( Destinations\Registry::classes() ) ) ),
		);
		$ex = is_array( $in['excludes'] ?? null ) ? $in['excludes'] : preg_split( '/\r\n|\r|\n/', (string) ( $in['excludes'] ?? '' ) );
		$out['excludes'] = array_values( array_unique( array_filter( array_map( static fn( $x ) => trim( sanitize_text_field( $x ), " /" ), $ex ) ) ) );
		return $out;
	}

	public static function save( array $in ): void {
		update_option( self::OPTION, self::sanitize( $in ), false );
	}
}
