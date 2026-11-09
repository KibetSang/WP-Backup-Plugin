<?php
/**
 * Who started each backup, restore, key change and deletion, when and from where. Kept in a non-autoloaded option
 * (the newest 500 lines), shown on the Logs tab. Never holds secrets.
 *
 * @package BastionBackup
 */

namespace BastionBackup;

defined( 'ABSPATH' ) || exit;

final class Audit {

	public const OPTION = 'bastion_backup_audit';

	public static function log( string $what, string $detail = '' ): void {
		$user  = wp_get_current_user();
		$lines = get_option( self::OPTION, array() );
		$lines = is_array( $lines ) ? $lines : array();
		array_unshift(
			$lines,
			array(
				't'    => time(),
				'who'  => $user && $user->ID ? (string) $user->user_login : ( defined( 'WP_CLI' ) && WP_CLI ? 'WP-CLI' : 'schedule' ),
				'ip'   => self::ip(),
				'what' => substr( $what, 0, 60 ),
				'info' => substr( $detail, 0, 300 ),
			)
		);
		update_option( self::OPTION, array_slice( $lines, 0, 500 ), false );
	}

	/** @return list<array{t:int,who:string,ip:string,what:string,info:string}> */
	public static function all(): array {
		$lines = get_option( self::OPTION, array() );
		return is_array( $lines ) ? array_values( $lines ) : array();
	}

	/** The connecting address only; forwarded headers can be forged, so they are not trusted here. */
	private static function ip(): string {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['REMOTE_ADDR'] ) ) : '';
		return false !== filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '';
	}
}
