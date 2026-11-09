<?php
/**
 * Deleting the plugin: settings, the Vault (destination secrets), the key's public half, scheduled events and working
 * files go. Backups stay unless "also delete the backups kept on this server" was chosen.
 *
 * @package BastionBackup
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$bbk_settings = get_option( 'bastion_backup_settings', array() );
$bbk_suffix   = (string) get_option( 'bastion_backup_dir_suffix', '' );
$bbk_root     = defined( 'BASTION_BACKUP_STORAGE' ) && '' !== (string) BASTION_BACKUP_STORAGE ? rtrim( (string) BASTION_BACKUP_STORAGE, '/\\' ) : ( preg_match( '/^[a-f0-9]{16}$/', $bbk_suffix ) ? WP_CONTENT_DIR . '/bastion-backups-' . $bbk_suffix : '' );

foreach ( array( 'bastion_backup_settings', 'bastion_backup_destinations', 'bastion_backup_secrets', 'bastion_backup_key', 'bastion_backup_current_job', 'bastion_backup_maintenance', 'bastion_backup_audit', 'bastion_backup_installer' ) as $bbk_opt ) {
	delete_option( $bbk_opt );
	delete_site_option( $bbk_opt );
}
foreach ( array( 'bastion_backup_scheduled', 'bastion_backup_tick', 'bastion_backup_sweep' ) as $bbk_hook ) {
	wp_unschedule_hook( $bbk_hook );
}
global $wpdb;
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_bbk\\_%' OR option_name LIKE '\\_transient\\_timeout\\_bbk\\_%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

/** Removes a folder's files, never following links. */
$bbk_rm = static function ( string $dir, bool $self ) use ( &$bbk_rm ): void {
	if ( '' === $dir || ! is_dir( $dir ) || is_link( $dir ) ) {
		return;
	}
	foreach ( (array) scandir( $dir ) as $e ) {
		if ( '.' === $e || '..' === $e ) {
			continue;
		}
		$p = $dir . '/' . $e;
		is_dir( $p ) && ! is_link( $p ) ? $bbk_rm( $p, true ) : @unlink( $p );
	}
	if ( $self ) {
		@rmdir( $dir );
	}
};

if ( '' !== $bbk_root ) {
	foreach ( array( 'jobs', 'tmp', 'logs' ) as $bbk_sub ) {
		$bbk_rm( $bbk_root . '/' . $bbk_sub, true );
	}
	@unlink( $bbk_root . '/.vault-key.php' );
	if ( is_array( $bbk_settings ) && ! empty( $bbk_settings['delete_on_uninstall'] ) ) {
		$bbk_rm( $bbk_root, ! defined( 'BASTION_BACKUP_STORAGE' ) );
	}
}
