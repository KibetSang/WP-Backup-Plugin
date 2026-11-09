<?php
/**
 * The OAuth redirect address (/wp-json/bastion-backup/v1/oauth) for Google Drive and OneDrive.
 *
 * The state is 32 random bytes, used once, valid for 10 minutes and bound to the administrator who started; the
 * browser that comes back must be signed in as that same person (checked from the login cookie), who must still be
 * allowed to manage backups.
 *
 * @package BastionBackup
 */

namespace BastionBackup;

use BastionBackup\Core\BackupException;
use BastionBackup\Destinations\OAuthDestination;
use BastionBackup\Destinations\Registry;

defined( 'ABSPATH' ) || exit;

final class OAuth {

	public static function registerRoutes(): void {
		register_rest_route(
			'bastion-backup/v1',
			'/oauth',
			array(
				'methods'             => 'GET',
				'callback'            => array( self::class, 'callback' ),
				'permission_callback' => '__return_true', // Checked inside: the state, the cookie's user and their capability.
			)
		);
	}

	public static function callback( \WP_REST_Request $req ): \WP_REST_Response {
		$back = Admin::url( 'destinations' );
		try {
			$state = (string) $req->get_param( 'state' );
			if ( ! preg_match( '/^[a-f0-9]{64}$/', $state ) ) {
				throw new BackupException( 'The connection link is not valid.' );
			}
			$key = 'bbk_oauth_' . hash( 'sha256', $state );
			$t   = get_transient( $key );
			delete_transient( $key ); // Single use, whatever happens next.
			if ( ! is_array( $t ) || (int) $t['exp'] < time() ) {
				throw new BackupException( 'The connection took too long or was already used. Press Connect again.' );
			}
			$cookieUser = (int) wp_validate_auth_cookie( '', 'logged_in' );
			if ( $cookieUser !== (int) $t['user'] || ! user_can( (int) $t['user'], Plugin::cap() ) ) {
				throw new BackupException( 'Finish connecting in the same browser, signed in as the administrator who pressed Connect.' );
			}
			if ( '' !== (string) $req->get_param( 'error' ) ) {
				throw new BackupException( 'The provider said: ' . sanitize_text_field( (string) ( $req->get_param( 'error_description' ) ?: $req->get_param( 'error' ) ) ) );
			}
			$dest = Registry::get( (string) $t['dest'] );
			if ( ! $dest instanceof OAuthDestination ) {
				throw new BackupException( 'Unknown destination.' );
			}
			$dest->finishConnect( (string) $req->get_param( 'code' ), (string) $t['v'] );
			wp_set_current_user( (int) $t['user'] );
			Audit::log( 'destination.connected', $dest->label() . ' ' . $dest->account() );
			$back = add_query_arg( 'bbk_notice', 'connected-' . $dest->id(), $back );
		} catch ( BackupException $e ) {
			set_transient( 'bbk_oauth_error', $e->getMessage(), 300 );
			$back = add_query_arg( 'bbk_notice', 'oauth-error', $back );
		}
		$res = new \WP_REST_Response( null, 302 );
		$res->header( 'Location', $back );
		$res->header( 'Cache-Control', 'no-store' );
		return $res;
	}
}
