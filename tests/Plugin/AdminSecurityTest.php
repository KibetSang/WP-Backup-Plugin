<?php

declare(strict_types=1);

namespace BastionBackup\Tests\Plugin;

use BastionBackup\Ajax;
use BastionBackup\Destinations\GoogleDrive;
use BastionBackup\Keys;
use BastionBackup\OAuth;
use BastionBackup\Settings;
use BastionBackup\Tests\Support\FakeClouds;
use BastionBackup\Tests\Support\SiteCase;
use BastionBackup\Vault;

final class AdminSecurityTest extends SiteCase {

	/** Calls an admin-ajax action as the browser would; returns [ok, data, status]. */
	private function ajax( string $action, array $post = array(), bool $nonce = true ): array {
		$_POST                         = $post + ( $nonce ? array( '_ajax_nonce' => 'nonce-bastion_backup' ) : array() );
		$GLOBALS['bbk_current_action'] = 'wp_ajax_bbk_' . $action;
		try {
			Ajax::handle();
		} catch ( \BbkTestJson $j ) {
			return array( $j->ok, $j->data, $j->status );
		} finally {
			$_POST = array();
		}
		$this->fail( 'no reply' );
	}

	public function test_without_the_capability_or_the_nonce_nothing_happens(): void {
		$GLOBALS['bbk_can'] = false;
		[ $ok, , $code ]    = $this->ajax( 'key', array( 'password' => 'correct-password' ) );
		$this->assertFalse( $ok );
		$this->assertSame( 403, $code );
		$GLOBALS['bbk_can'] = true;
		[ $ok, , $code ]    = $this->ajax( 'key', array( 'password' => 'correct-password' ), false );
		$this->assertFalse( $ok );
		$this->assertSame( 403, $code );
		$this->assertNull( Keys::current() );
	}

	public function test_the_key_needs_the_password_and_wrong_tries_lock_it(): void {
		for ( $i = 0; $i < 5; $i++ ) {
			[ $ok, $d ] = $this->ajax( 'key', array( 'password' => 'guess' . $i ) );
			$this->assertFalse( $ok );
			$this->assertSame( 'That is not your WordPress password.', $d['message'] );
		}
		[ $ok, $d ] = $this->ajax( 'key', array( 'password' => 'correct-password' ) );
		$this->assertFalse( $ok, 'locked even with the right password' );
		$this->assertStringContainsString( 'Too many', $d['message'] );
		delete_transient( 'bbk_reauth_1' );
		[ $ok, $d ] = $this->ajax( 'key', array( 'password' => 'correct-password' ) );
		$this->assertTrue( $ok );
		$this->assertStringStartsWith( 'BBK-', $d['text'] );
		$this->assertSame( Keys::current()['id'], $d['id'] );
	}

	public function test_a_restore_needs_restore_typed_and_the_password(): void {
		[ $ok, $d ] = $this->ajax( 'restore_confirm', array( 'password' => 'correct-password', 'confirm' => 'restore', 'db' => '1' ) );
		$this->assertFalse( $ok );
		$this->assertStringContainsString( 'Type RESTORE', $d['message'] );
		[ $ok, $d ] = $this->ajax( 'restore_confirm', array( 'password' => 'nope', 'confirm' => 'RESTORE', 'db' => '1' ) );
		$this->assertFalse( $ok );
		$this->assertStringContainsString( 'not your WordPress password', $d['message'] );
	}

	public function test_a_mistyped_key_is_caught_before_any_job_starts(): void {
		$this->makeKey();
		[ $ok, $d ] = $this->ajax( 'check', array( 'where' => 'local', 'id' => 'bastion-old-example-20261109-120000-abcdef.bbk', 'key' => 'BBK-AAAA-BBBB' ) );
		$this->assertFalse( $ok );
		$this->assertStringContainsString( 'not a backup key', $d['message'] );
		$this->assertFalse( \BastionBackup\Jobs::running() );
	}

	public function test_names_from_the_browser_cannot_reach_other_files(): void {
		foreach ( array( '../../wp-config.php', 'x.php', '/etc/passwd' ) as $bad ) {
			[ $ok ] = $this->ajax( 'delete', array( 'password' => 'correct-password', 'where' => 'local', 'id' => $bad ) );
			$this->assertFalse( $ok, $bad );
		}
		$this->assertFileExists( ABSPATH . 'wp-config.php' );
		Settings::setDestination( 'google', array( 'client_id' => 'client-google-123' ) );
		Vault::set( 'dest_google', array( 'client_secret' => 's', 'refresh' => 'refresh-1' ) );
		[ $ok, $d ] = $this->ajax( 'delete', array( 'password' => 'correct-password', 'where' => 'google', 'id' => 'some-id', 'name' => 'Important.docx' ) );
		$this->assertFalse( $ok );
		$this->assertStringContainsString( 'Only backups made by this plugin', $d['message'] );
	}

	public function test_downloads_need_the_nonce(): void {
		$_GET = array( 'name' => 'bastion-old-example-20261109-120000-abcdef.bbk' );
		try {
			Ajax::download();
			$this->fail( 'served without a nonce' );
		} catch ( \BbkTestDie $e ) {
			$this->assertSame( 'Not allowed.', $e->getMessage() );
		} finally {
			$_GET = array();
		}
	}

	public function test_secrets_saved_from_the_form_are_never_sent_back(): void {
		[ $ok ] = $this->ajax( 'dest_save', array( 'dest' => 'google', 'client_id' => 'abc-123.apps.googleusercontent.com', 'client_secret' => 'top-secret-value', 'folder' => '' ) );
		$this->assertTrue( $ok );
		$this->assertStringNotContainsString( 'top-secret-value', (string) json_encode( get_option( Settings::DEST_OPTION ) ) );
		$this->assertStringNotContainsString( 'top-secret-value', (string) get_option( Vault::OPTION ), 'stored encrypted' );
		$this->assertSame( 'top-secret-value', Vault::get( 'dest_google' )['client_secret'] );
		// An empty secret field keeps the saved one.
		$this->ajax( 'dest_save', array( 'dest' => 'google', 'client_id' => 'abc-123.apps.googleusercontent.com', 'client_secret' => '', 'folder' => '' ) );
		$this->assertSame( 'top-secret-value', Vault::get( 'dest_google' )['client_secret'] );
	}

	private function oauthState(): string {
		$GLOBALS['bbk_http'] = new FakeClouds();
		$d                   = new GoogleDrive();
		$d->saveApp( 'client-google-123', 'shh' );
		parse_str( (string) parse_url( $d->beginConnect( 1 ), PHP_URL_QUERY ), $q );
		$this->assertSame( 'https://www.googleapis.com/auth/drive.file', $q['scope'], 'least access: only files this plugin makes' );
		$this->assertSame( 'S256', $q['code_challenge_method'] );
		return (string) $q['state'];
	}

	public function test_the_oauth_return_must_come_back_to_the_same_administrator_once(): void {
		$state                 = $this->oauthState();
		$GLOBALS['bbk_cookie'] = 2; // Another signed-in user.
		$r                     = OAuth::callback( new \WP_REST_Request( array( 'state' => $state, 'code' => 'abc' ) ) );
		$this->assertStringContainsString( 'oauth-error', $r->headers['Location'] );
		$this->assertFalse( ( new GoogleDrive() )->isConnected() );
		$GLOBALS['bbk_cookie'] = 1;
		$r                     = OAuth::callback( new \WP_REST_Request( array( 'state' => $state, 'code' => 'abc' ) ) );
		$this->assertStringContainsString( 'oauth-error', $r->headers['Location'], 'the state was used up by the first try' );

		$state = $this->oauthState();
		$r     = OAuth::callback( new \WP_REST_Request( array( 'state' => $state, 'code' => 'abc' ) ) );
		$this->assertStringContainsString( 'connected-google', $r->headers['Location'] );
		$this->assertTrue( ( new GoogleDrive() )->isConnected() );
		$this->assertSame( 'owner@example.org', ( new GoogleDrive() )->account() );
	}
}
