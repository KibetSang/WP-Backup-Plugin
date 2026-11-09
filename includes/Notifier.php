<?php
/**
 * Emails about backups, when the settings ask for them. They never hold keys, tokens or file contents.
 *
 * @package BastionBackup
 */

namespace BastionBackup;

defined( 'ABSPATH' ) || exit;

final class Notifier {

	private static function to(): string {
		$to = (string) Settings::get()['notify_email'];
		return is_email( $to ) ? $to : '';
	}

	public static function backupFinished( array $st ): void {
		$on = (string) Settings::get()['notify_on'];
		$ok = empty( $st['error'] ) && empty( $st['cancelled'] );
		if ( '' === self::to() || 'never' === $on || ( $ok && 'always' !== $on && empty( $st['warnings'] ) ) || ! empty( $st['cancelled'] ) ) {
			return;
		}
		$site = wp_parse_url( home_url(), PHP_URL_HOST );
		if ( $ok ) {
			$r    = (array) ( $st['result'] ?? array() );
			$subj = sprintf( '[%s] Backup complete%s', $site, empty( $st['warnings'] ) ? '' : ', with warnings' );
			$body = sprintf( "A backup of %s was made.\n\nFile: %s\nSize: %s\nKept on: %s\n", home_url(), $r['name'] ?? '', size_format( (int) ( $r['size'] ?? 0 ) ), implode( ', ', array_keys( array_filter( (array) ( $r['copies'] ?? array() ) ) ) ) );
			if ( ! empty( $st['warnings'] ) ) {
				$body .= "\nWarnings:\n- " . implode( "\n- ", array_slice( (array) $st['warnings'], 0, 20 ) ) . "\n";
			}
		} else {
			$subj = sprintf( '[%s] Backup failed', $site );
			$body = sprintf( "The backup of %s did not finish.\n\nReason: %s\n\nSee Bastion Backup → Logs for details.\n", home_url(), (string) $st['error'] );
		}
		wp_mail( self::to(), $subj, $body );
	}

	public static function scheduledSkipped( string $why ): void {
		if ( '' !== self::to() && 'never' !== Settings::get()['notify_on'] ) {
			wp_mail( self::to(), sprintf( '[%s] Scheduled backup not made', wp_parse_url( home_url(), PHP_URL_HOST ) ), "The scheduled backup of " . home_url() . " was not made.\n\nReason: $why\n" );
		}
	}
}
