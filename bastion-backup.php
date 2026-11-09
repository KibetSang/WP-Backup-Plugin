<?php
/**
 * Plugin Name:       Bastion Backup
 * Description:       Encrypted backup, migration and restore. The whole site and database, in resumable steps to 10 GB and more, sealed to a backup key this site cannot open, and copied to Google Drive, OneDrive or your own SFTP/FTPS server.
 * Version:           1.0.0
 * Requires at least: 6.4
 * Tested up to:      7.1
 * Requires PHP:      8.1
 * Author:            Wingustar Solutions Ltd
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       bastion-backup
 * Domain Path:       /languages
 * Network:           true
 *
 * @package BastionBackup
 */

defined( 'ABSPATH' ) || exit;

define( 'BASTION_BACKUP_VERSION', '1.0.0' );
define( 'BASTION_BACKUP_FILE', __FILE__ );
define( 'BASTION_BACKUP_DIR', __DIR__ );

if ( version_compare( PHP_VERSION, '8.1', '<' ) || ! extension_loaded( 'sodium' ) ) {
	add_action(
		'admin_notices',
		static function () {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'Bastion Backup requires PHP 8.1+ with the sodium extension.', 'bastion-backup' ) . '</p></div>';
		}
	);
	return;
}

spl_autoload_register(
	static function ( string $class ): void {
		$prefix = 'BastionBackup\\';
		if ( ! str_starts_with( $class, $prefix ) ) {
			return;
		}
		$rel = substr( $class, strlen( $prefix ) );
		if ( str_starts_with( $rel, 'Core\\' ) ) {
			$file = __DIR__ . '/core/' . substr( $rel, 5 ) . '.php';
		} else {
			$file = __DIR__ . '/includes/' . str_replace( '\\', '/', $rel ) . '.php';
		}
		if ( preg_match( '#^[A-Za-z0-9_/\\\\.-]+$#', $file ) && is_file( $file ) ) {
			require_once $file;
		}
	}
);

register_activation_hook( __FILE__, array( \BastionBackup\Plugin::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( \BastionBackup\Plugin::class, 'deactivate' ) );

add_action( 'plugins_loaded', array( \BastionBackup\Plugin::class, 'boot' ) );
