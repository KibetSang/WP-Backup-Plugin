<?php
/**
 * What the site's own PHP has (not what the hosting panel says): its version, which build it is (cPanel EasyApache,
 * CloudLinux's PHP Selector, Plesk, other), its ini files and its encryption, with the fix that applies to that build.
 *
 * @package BastionBackup
 */

namespace BastionBackup;

use BastionBackup\Core\Crypto;

defined( 'ABSPATH' ) || exit;

final class ServerCheck {

	/** @return array{kind:string, short:string, ini:string, scanned:string[]} */
	public static function php(): array {
		$ini     = (string) php_ini_loaded_file();
		$scanned = array_values( array_filter( array_map( 'trim', explode( ',', (string) php_ini_scanned_files() ) ) ) );
		$short   = PHP_MAJOR_VERSION . PHP_MINOR_VERSION;
		$where   = $ini . ' ' . implode( ' ', $scanned ) . ' ' . PHP_BINARY;
		$kind    = 'other';
		if ( str_contains( $where, '/opt/cpanel/ea-php' ) ) {
			$kind = 'easyapache';
		} elseif ( str_contains( $where, '/opt/alt/php' ) ) {
			$kind = 'cloudlinux';
		} elseif ( str_contains( $where, '/opt/plesk/php' ) ) {
			$kind = 'plesk';
		}
		return array(
			'kind'    => $kind,
			'short'   => $short,
			'ini'     => $ini,
			'scanned' => $scanned,
		);
	}

	/** How to turn the sodium extension on for exactly this build of PHP. */
	public static function sodiumAdvice(): string {
		$p = self::php();
		switch ( $p['kind'] ) {
			case 'easyapache':
				return sprintf(
					/* translators: 1: PHP version, 2: package name, 3: version */
					__( 'This site runs cPanel\'s EasyApache PHP %1$s (ea-php%3$s). There, sodium is a separate package, %2$s, which only the host can install (WHM → EasyApache 4 → Customize → PHP Extensions). Ticking sodium under "Select PHP Version" does not change ea-php%3$s: that page sets CloudLinux\'s own PHP, which this site is not using. Either ask the host to install %2$s, or in MultiPHP Manager set this domain to the version the PHP Selector controls (often shown as "inherit" or the alt-php build) and tick sodium there.', 'bastion-backup' ),
					PHP_VERSION,
					'ea-php' . $p['short'] . '-php-sodium',
					$p['short']
				);
			case 'cloudlinux':
				return __( 'This site runs CloudLinux\'s PHP: in cPanel open Select PHP Version → Extensions, tick sodium and save.', 'bastion-backup' );
			case 'plesk':
				return __( 'This site runs Plesk\'s PHP: ask the host to enable the sodium extension for this PHP version (Tools & Settings → PHP Settings).', 'bastion-backup' );
			default:
				return '' !== $p['ini']
					/* translators: %s: php.ini path */
					? sprintf( __( 'In %s change ;extension=sodium to extension=sodium (or install your system\'s php-sodium package), then restart the web server.', 'bastion-backup' ), $p['ini'] )
					: __( 'Ask the host to enable PHP\'s sodium extension for this site.', 'bastion-backup' );
		}
	}

	/** @return list<array{0:string,1:string,2:string}> label, value, state (ok|warn|bad|'') */
	public static function rows(): array {
		$p      = self::php();
		$native = Crypto::native();
		$kinds  = array(
			'easyapache' => 'cPanel EasyApache (ea-php' . $p['short'] . ')',
			'cloudlinux' => 'CloudLinux PHP Selector (alt-php' . $p['short'] . ')',
			'plesk'      => 'Plesk PHP',
			'other'      => __( 'Other', 'bastion-backup' ),
		);
		$sodiumIni = array_filter( $p['scanned'], static fn( string $f ): bool => str_contains( strtolower( basename( $f ) ), 'sodium' ) );
		return array(
			array( __( 'PHP version', 'bastion-backup' ), PHP_VERSION . ' (' . PHP_SAPI . ')', version_compare( PHP_VERSION, '8.1', '>=' ) ? 'ok' : 'bad' ),
			array( __( 'PHP build', 'bastion-backup' ), $kinds[ $p['kind'] ], '' ),
			array( __( 'php.ini in use', 'bastion-backup' ), '' !== $p['ini'] ? $p['ini'] : __( 'none', 'bastion-backup' ), '' ),
			array( __( 'Extra .ini files', 'bastion-backup' ), $p['scanned'] ? sprintf( /* translators: 1: count, 2: names */ __( '%1$d loaded%2$s', 'bastion-backup' ), count( $p['scanned'] ), $sodiumIni ? ', ' . __( 'including', 'bastion-backup' ) . ' ' . implode( ', ', array_map( 'basename', $sodiumIni ) ) : ', ' . __( 'none for sodium', 'bastion-backup' ) ) : __( 'none', 'bastion-backup' ), '' ),
			array( __( 'sodium extension', 'bastion-backup' ), extension_loaded( 'sodium' ) ? __( 'loaded', 'bastion-backup' ) . ( defined( 'SODIUM_LIBRARY_VERSION' ) ? ' (libsodium ' . SODIUM_LIBRARY_VERSION . ')' : '' ) : __( 'not loaded', 'bastion-backup' ), extension_loaded( 'sodium' ) ? 'ok' : 'warn' ),
			array( __( 'Encryption used', 'bastion-backup' ), $native ? __( 'PHP sodium extension (fast)', 'bastion-backup' ) : ( Crypto::missing() ? __( 'none available', 'bastion-backup' ) : __( 'WordPress built-in sodium_compat (about 1,000 times slower)', 'bastion-backup' ) ), $native ? 'ok' : ( Crypto::missing() ? 'bad' : 'warn' ) ),
			array( __( 'Memory limit / time limit', 'bastion-backup' ), (string) ini_get( 'memory_limit' ) . ' / ' . (int) ini_get( 'max_execution_time' ) . ' s', '' ),
			array( __( 'curl (file server)', 'bastion-backup' ), function_exists( 'curl_init' ) ? __( 'available', 'bastion-backup' ) : __( 'missing', 'bastion-backup' ), function_exists( 'curl_init' ) ? 'ok' : 'warn' ),
		);
	}
}
