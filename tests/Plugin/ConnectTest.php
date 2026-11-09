<?php

declare(strict_types=1);

namespace BastionBackup\Tests\Plugin;

use BastionBackup\Core\BackupException;
use BastionBackup\Destinations\GoogleDrive;
use BastionBackup\Destinations\OneDrive;
use BastionBackup\Tests\Support\FakeClouds;
use BastionBackup\Tests\Support\SiteCase;

/** "Connect": sign in at Google or Microsoft with a code; no app of the site owner's own. */
final class ConnectTest extends SiteCase {

	private FakeClouds $cloud;

	protected function setUp(): void {
		parent::setUp();
		$this->cloud         = new FakeClouds();
		$GLOBALS['bbk_http'] = $this->cloud;
	}

	/** Lets the next poll ask the provider straight away (the plugin otherwise waits the interval it was given). */
	private function due( string $id, int $user = 1 ): void {
		$k         = 'bbk_device_' . $id . '_' . $user;
		$t         = get_transient( $k );
		$t['next'] = 0;
		set_transient( $k, $t, 600 );
	}

	/** @return array<string, array{0: class-string, 1: string}> */
	public static function clouds(): array {
		return array( 'Google Drive' => array( GoogleDrive::class, 'https://www.google.com/device' ), 'OneDrive' => array( OneDrive::class, 'https://microsoft.com/devicelogin' ) );
	}

	#[\PHPUnit\Framework\Attributes\DataProvider( 'clouds' )]
	public function test_connect_shows_a_code_waits_for_the_person_then_keeps_the_tokens_encrypted( string $class, string $url ): void {
		$d = new $class();
		$this->assertTrue( $d->hasShared() );
		$this->assertFalse( $d->isConfigured() );
		$r = $d->beginDevice( 1 );
		$this->assertSame( 'ABCD-EFGH', $r['code'] );
		$this->assertSame( $url, $r['url'] );

		$this->assertSame( 'pending', $d->pollDevice( 1 ) );
		$this->assertSame( 'pending', $d->pollDevice( 1 ), 'asked again too soon: no request sent' );
		$sent = count( $this->cloud->seen );
		$this->assertSame( $sent, count( $this->cloud->seen ) );

		$this->cloud->device = 'allowed';
		$this->due( $d->id() );
		$this->assertSame( 'connected', $d->pollDevice( 1 ) );
		$this->assertTrue( $d->isConfigured() );
		$this->assertTrue( $d->usesShared() );
		$this->assertSame( 'owner@example.org', $d->account() );
		$this->assertStringNotContainsString( 'refresh-1', (string) get_option( \BastionBackup\Vault::OPTION ), 'tokens stored encrypted' );

		// Renewing the access token later uses the shared app it was issued to.
		\BastionBackup\Vault::set( 'dest_' . $d->id(), array_merge( \BastionBackup\Vault::get( 'dest_' . $d->id() ), array( 'expires' => 0 ) ) );
		$this->assertTrue( $d->test() );
		$this->assertSame( 'refresh_token', $this->cloud->lastTokenForm['grant_type'] );
		$this->assertSame( GoogleDrive::class === $class ? BASTION_BACKUP_GOOGLE_CLIENT_ID : BASTION_BACKUP_ONEDRIVE_CLIENT_ID, $this->cloud->lastTokenForm['client_id'] );
	}

	/** @return array<string, array{0: class-string, 1: string, 2: string}> */
	public static function redirects(): array {
		return array(
			'OneDrive' => array( OneDrive::class, 'https://login.microsoftonline.com/common/oauth2/v2.0/authorize', BASTION_BACKUP_ONEDRIVE_CLIENT_ID ),
			'Dropbox'  => array( \BastionBackup\Destinations\Dropbox::class, 'https://www.dropbox.com/oauth2/authorize', BASTION_BACKUP_DROPBOX_APP_KEY ),
		);
	}

	/** Press Connect → sign in at the provider → the shared return page → this site → connected. No secret anywhere. */
	#[\PHPUnit\Framework\Attributes\DataProvider( 'redirects' )]
	public function test_one_click_connect_signs_in_and_comes_back_through_the_shared_return_page( string $class, string $authorize, string $clientId ): void {
		$d   = new $class();
		$url = $d->beginConnect( 1, true );
		$this->assertStringStartsWith( $authorize . '?', $url );
		parse_str( (string) parse_url( $url, PHP_URL_QUERY ), $q );
		$this->assertSame( $clientId, $q['client_id'] );
		$this->assertSame( \BastionBackup\Destinations\Apps::RELAY_URL, $q['redirect_uri'] );
		$this->assertSame( 'S256', $q['code_challenge_method'] );
		$back = json_decode( base64_decode( strtr( $q['state'], '-_', '+/' ) ), true );
		$this->assertSame( rest_url( 'bastion-backup/v1/oauth' ), $back['r'], 'the return page is told where this site is' );

		// The provider → the return page → this site's callback with the code.
		$r = \BastionBackup\OAuth::callback( new \WP_REST_Request( array( 'state' => $q['state'], 'code' => 'the-code' ) ) );
		$this->assertStringContainsString( 'connected-' . $d->id(), $r->headers['Location'] );
		$this->assertTrue( $d->isConfigured() );
		$this->assertTrue( $d->usesShared() );
		$f = $this->cloud->lastTokenForm;
		$this->assertSame( array( 'authorization_code', $clientId, \BastionBackup\Destinations\Apps::RELAY_URL ), array( $f['grant_type'], $f['client_id'], $f['redirect_uri'] ) );
		$this->assertArrayNotHasKey( 'client_secret', $f, 'a public client: no secret, the PKCE verifier instead' );
		$this->assertNotEmpty( $f['code_verifier'] );
		if ( str_contains( $authorize, 'dropbox' ) ) {
			$this->assertSame( 'offline', $q['token_access_type'], 'Dropbox gives a refresh token only when asked' );
		}
	}

	public function test_the_code_belongs_to_the_administrator_who_asked_for_it(): void {
		$d = new GoogleDrive();
		$d->beginDevice( 1 );
		$this->cloud->device = 'allowed';
		$this->expectException( BackupException::class );
		$this->expectExceptionMessage( 'expired' );
		$d->pollDevice( 2 );
	}

	public function test_saying_no_at_google_is_reported_and_nothing_is_connected(): void {
		$d = new GoogleDrive();
		$d->beginDevice( 1 );
		$this->cloud->device = 'denied';
		$this->due( 'google' );
		try {
			$d->pollDevice( 1 );
			$this->fail( 'no error' );
		} catch ( BackupException $e ) {
			$this->assertStringContainsString( 'not allowed', $e->getMessage() );
		}
		$this->assertFalse( $d->isConnected() );
	}

	public function test_the_destinations_tab_offers_one_connect_button_and_keeps_own_apps_as_advanced(): void {
		$_GET = array( 'tab' => 'destinations' );
		ob_start();
		\BastionBackup\Admin::render();
		$html = (string) ob_get_clean();
		$_GET = array();
		$this->assertMatchesRegularExpression( '#data-bbk="device"><img class="bbk-brand" src="[^"]+/assets/brands/google-drive\.png"[^>]*>Connect Google Drive</button>#', $html );
		$this->assertMatchesRegularExpression( '#data-bbk="connect-shared"><img class="bbk-brand" src="[^"]+/assets/brands/onedrive\.png"[^>]*>Connect OneDrive</button>#', $html );
		$this->assertMatchesRegularExpression( '#data-bbk="connect-shared"><img class="bbk-brand" src="[^"]+/assets/brands/dropbox\.png"[^>]*>Connect Dropbox</button>#', $html );
		$this->assertStringContainsString( 'Advanced: use your own app instead', $html );
	}
}
