<?php
/**
 * Settings that are not secret. Secrets (OAuth tokens, client secrets, server passwords) live in the Vault; the backup
 * key's secret half lives nowhere on the server.
 *
 * @package BastionBackup
 */

namespace BastionBackup;

defined( 'ABSPATH' ) || exit;

final class Settings {

	public const OPTION      = 'bastion_backup_settings';
	public const DEST_OPTION = 'bastion_backup_destinations';
	public const SCHEDULES   = array( 'none', 'hourly', 'twicedaily', 'daily', 'weekly' );

	public static function defaults(): array {
		return array(
			'schedule'        => 'none',
			'schedule_time'   => '01:30',
			'backup_db'       => true,
			'backup_files'    => true,
			'all_tables'      => false,
			'exclude_tables'  => array(),
			'destinations'    => array(),
			'keep_local'      => true,
			'retain_local'    => 3,
			'retain_remote'   => 7,
			'excludes'        => array(
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
			'max_file_mb'     => 0,
			'step_seconds'    => 20,
			'notify_email'    => '',
			'notify_on'       => 'failure',
			'delete_on_uninstall' => false,
		);
	}

	public static function get(): array {
		$s = get_option( self::OPTION, array() );
		return array_merge( self::defaults(), is_array( $s ) ? $s : array() );
	}

	public static function sanitize( array $in ): array {
		$d   = self::defaults();
		$cur = self::get();
		$out = array(
			'schedule'       => in_array( $in['schedule'] ?? '', self::SCHEDULES, true ) ? $in['schedule'] : 'none',
			'schedule_time'  => preg_match( '/^([01]\d|2[0-3]):[0-5]\d$/', (string) ( $in['schedule_time'] ?? '' ) ) ? (string) $in['schedule_time'] : $d['schedule_time'],
			'backup_db'      => ! empty( $in['backup_db'] ),
			'backup_files'   => ! empty( $in['backup_files'] ),
			'all_tables'     => ! empty( $in['all_tables'] ),
			'keep_local'     => ! empty( $in['keep_local'] ),
			'retain_local'   => max( 1, min( 100, (int) ( $in['retain_local'] ?? $d['retain_local'] ) ) ),
			'retain_remote'  => max( 1, min( 365, (int) ( $in['retain_remote'] ?? $d['retain_remote'] ) ) ),
			'max_file_mb'    => max( 0, min( 1048576, (int) ( $in['max_file_mb'] ?? 0 ) ) ),
			'step_seconds'   => max( 5, min( 120, (int) ( $in['step_seconds'] ?? 20 ) ) ),
			'notify_email'   => sanitize_email( (string) ( $in['notify_email'] ?? '' ) ),
			'notify_on'      => in_array( $in['notify_on'] ?? '', array( 'failure', 'always', 'never' ), true ) ? $in['notify_on'] : 'failure',
			'destinations'   => array_values( array_intersect( array_map( 'strval', (array) ( $in['destinations'] ?? array() ) ), array_keys( Destinations\Registry::classes() ) ) ),
			'delete_on_uninstall' => ! empty( $in['delete_on_uninstall'] ),
		);
		if ( ! $out['backup_db'] && ! $out['backup_files'] ) {
			$out['backup_db']    = true; // A backup of nothing is not a backup.
			$out['backup_files'] = true;
		}
		$out['excludes']       = self::lines( $in['excludes'] ?? $cur['excludes'] );
		$out['exclude_tables'] = array_values( array_filter( self::lines( $in['exclude_tables'] ?? array() ), static fn( $t ) => (bool) preg_match( '/^[A-Za-z0-9_$]{1,64}$/', $t ) ) );
		return $out;
	}

	/** @return string[] */
	private static function lines( mixed $v ): array {
		$list = is_array( $v ) ? $v : preg_split( '/\r\n|\r|\n/', (string) $v );
		return array_values( array_unique( array_filter( array_map( static fn( $x ) => trim( sanitize_text_field( (string) $x ), " /\\" ), (array) $list ) ) ) );
	}

	public static function save( array $in ): void {
		update_option( self::OPTION, self::sanitize( $in ), false );
	}

	/** Non-secret settings of one destination. */
	public static function destination( string $id ): array {
		$all = get_option( self::DEST_OPTION, array() );
		return is_array( $all ) && is_array( $all[ $id ] ?? null ) ? $all[ $id ] : array();
	}

	public static function setDestination( string $id, array $cfg ): void {
		$all        = get_option( self::DEST_OPTION, array() );
		$all        = is_array( $all ) ? $all : array();
		$all[ $id ] = $cfg;
		update_option( self::DEST_OPTION, $all, false );
	}

	/** "example-com" from the site address, for archive and folder names. */
	public static function siteSlug(): string {
		$host = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		$path = trim( (string) wp_parse_url( home_url(), PHP_URL_PATH ), '/' );
		$slug = strtolower( (string) preg_replace( '/[^A-Za-z0-9]+/', '-', $host . ( '' !== $path ? '-' . $path : '' ) ) );
		return substr( trim( $slug, '-' ), 0, 40 ) ?: 'site';
	}
}
