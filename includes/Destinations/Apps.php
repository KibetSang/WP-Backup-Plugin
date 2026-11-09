<?php
/**
 * The shared Google and Microsoft apps behind "Connect": registered once by the plugin's publisher
 * (docs/SHARED-APPS.md), so a site owner only signs in. They use the device sign-in flow (the site shows a short code,
 * the owner enters it at google.com/device or microsoft.com/devicelogin), which needs no redirect address and no relay
 * server: tokens go straight from Google or Microsoft to the site.
 *
 * A site can use other apps by defining BASTION_BACKUP_GOOGLE_CLIENT_ID / _SECRET or BASTION_BACKUP_ONEDRIVE_CLIENT_ID
 * in wp-config.php. Google's "TVs and Limited Input devices" clients come with a secret that Google does not treat as
 * confidential (it ships inside every copy of an installed app); Microsoft's public clients have none.
 *
 * @package BastionBackup
 */

namespace BastionBackup\Destinations;

defined( 'ABSPATH' ) || exit;

final class Apps {

	/** Filled in when the shared apps are registered (docs/SHARED-APPS.md). Empty: only "use your own app" is offered. */
	public const GOOGLE_CLIENT_ID     = '';
	public const GOOGLE_CLIENT_SECRET = '';
	public const ONEDRIVE_CLIENT_ID   = '';

	/** @return array{id:string,secret:string} */
	public static function google(): array {
		return array(
			'id'     => defined( 'BASTION_BACKUP_GOOGLE_CLIENT_ID' ) ? (string) BASTION_BACKUP_GOOGLE_CLIENT_ID : self::GOOGLE_CLIENT_ID,
			'secret' => defined( 'BASTION_BACKUP_GOOGLE_CLIENT_SECRET' ) ? (string) BASTION_BACKUP_GOOGLE_CLIENT_SECRET : self::GOOGLE_CLIENT_SECRET,
		);
	}

	/** @return array{id:string,secret:string} */
	public static function onedrive(): array {
		return array(
			'id'     => defined( 'BASTION_BACKUP_ONEDRIVE_CLIENT_ID' ) ? (string) BASTION_BACKUP_ONEDRIVE_CLIENT_ID : self::ONEDRIVE_CLIENT_ID,
			'secret' => '',
		);
	}
}
