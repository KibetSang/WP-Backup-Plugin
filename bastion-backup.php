<?php
/**
 * Plugin Name:       Bastion Backup
 * Description:       Encrypted backup, migration and restore. The whole site and database, in resumable steps to 10 GB and more, sealed to a backup key this site cannot open, and copied to Google Drive, OneDrive or your own SFTP/FTPS server.
 * Version:           1.0.0
 * Requires at least: 6.4
 * Tested up to:      7.1
 * Requires PHP:      8.1
 * Author:            Kibet Sang
 * Author URI:        https://github.com/KibetSang
 * Plugin URI:        https://github.com/KibetSang/WP-Backup-Plugin
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
	// Stay inactive and say exactly what is missing and how to fix it, instead of failing.
	$bbk_notice = static function (): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$old = version_compare( PHP_VERSION, '8.1', '<' );
		$ini = (string) php_ini_loaded_file();
		echo '<div class="notice notice-error"><p><strong>' . esc_html__( 'Bastion Backup is not running yet.', 'bastion-backup' ) . '</strong> ';
		if ( $old ) {
			/* translators: %s: PHP version */
			echo esc_html( sprintf( __( 'This site runs PHP %s; it needs PHP 8.1 or newer. In your hosting panel choose PHP 8.1 or later for this site (cPanel: Select PHP Version or MultiPHP Manager; Plesk: PHP Settings).', 'bastion-backup' ), PHP_VERSION ) );
		} else {
			/* translators: %s: PHP version */
			echo esc_html( sprintf( __( 'This site runs PHP %s without the sodium extension, which encrypts the backups. It comes with PHP but is switched off here:', 'bastion-backup' ), PHP_VERSION ) ) . '</p><ul style="list-style:disc;margin-left:2em">';
			echo '<li>' . esc_html__( 'cPanel: Select PHP Version → Extensions → tick sodium (or ask the host to enable "php-sodium").', 'bastion-backup' ) . '</li>';
			echo '<li>' . esc_html__( 'XAMPP, WAMP or your own server: in php.ini change ;extension=sodium to extension=sodium, then restart Apache.', 'bastion-backup' ) . '</li>';
			if ( '' !== $ini ) {
				/* translators: %s: path to php.ini */
				echo '<li>' . esc_html( sprintf( __( 'This site\'s php.ini: %s', 'bastion-backup' ), $ini ) ) . '</li>';
			}
			echo '</ul><p>';
		}
		echo esc_html__( 'Then reload this page.', 'bastion-backup' ) . '</p></div>';
	};
	add_action( 'admin_notices', $bbk_notice );
	add_action( 'network_admin_notices', $bbk_notice );
	// The menu is still there, so the plugin can be found: its page says what to fix.
	add_action(
		is_multisite() ? 'network_admin_menu' : 'admin_menu',
		static function () use ( $bbk_notice ): void {
			add_menu_page(
				__( 'Bastion Backup', 'bastion-backup' ),
				__( 'Bastion Backup', 'bastion-backup' ),
				is_multisite() ? 'manage_network_options' : 'manage_options',
				'bastion-backup',
				static function (): void {
					echo '<div class="wrap"><h1>' . esc_html__( 'Bastion Backup', 'bastion-backup' ) . '</h1>';
					echo '<p>' . esc_html__( 'Backups start working as soon as the server has what is listed above.', 'bastion-backup' ) . '</p></div>';
				},
				'dashicons-shield',
				80
			);
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
