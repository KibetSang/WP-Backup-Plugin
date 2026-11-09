<?php

declare(strict_types=1);

namespace BastionBackup\Tests\Plugin;

use BastionBackup\Destinations\FileServer;
use BastionBackup\Destinations\GoogleDrive;
use BastionBackup\Destinations\OneDrive;
use BastionBackup\Destinations\Registry;
use BastionBackup\Jobs;
use BastionBackup\Settings;
use BastionBackup\Storage;
use BastionBackup\Tests\Support\FakeClouds;
use BastionBackup\Tests\Support\SiteCase;
use BastionBackup\Vault;

final class DestinationsTest extends SiteCase {

	private FakeClouds $cloud;

	protected function setUp(): void {
		parent::setUp();
		$this->cloud         = new FakeClouds();
		$GLOBALS['bbk_http'] = $this->cloud;
		foreach ( array( 'google', 'onedrive' ) as $id ) {
			Settings::setDestination( $id, array( 'client_id' => 'client-' . $id . '-123' ) );
			// Connected earlier; the access token has expired, so the first call must renew it.
			Vault::set( 'dest_' . $id, array( 'client_secret' => 'shh', 'refresh' => 'refresh-1', 'access' => 'stale', 'expires' => time() - 10 ) );
		}
		Vault::reset_cache();
	}

	/** @return array<string, array{0: class-string}> */
	public static function clouds(): array {
		return array( 'Google Drive' => array( GoogleDrive::class ), 'OneDrive' => array( OneDrive::class ) );
	}

	private function bigFile( string $name, int $size ): string {
		$p = Storage::dir( 'tmp' ) . '/' . $name;
		file_put_contents( $p, random_bytes( $size ) );
		return $p;
	}

	#[\PHPUnit\Framework\Attributes\DataProvider( 'clouds' )]
	public function test_a_large_upload_survives_lost_answers_and_busy_servers_and_resumes_byte_exact( string $class ): void {
		$d    = new $class();
		$name = 'bastion-old-example-20261109-120000-abcdef.bbk';
		$file = $this->bigFile( $name, 21 * 1048576 + 333 );
		$this->cloud->loseAnswer = 1;
		$this->cloud->fail503    = 1;
		$state = array();
		$calls = 0;
		while ( ! $d->upload( $file, $name, $state, microtime( true ) - 1 ) ) { // Deadline passed: one chunk per call, as across many requests.
			$state = json_decode( (string) json_encode( $state ), true ); // As saved between requests.
			if ( ++$calls > 50 ) {
				$this->fail( 'upload never finished' );
			}
		}
		$this->assertGreaterThanOrEqual( 2, $calls );
		$stored = array_values( array_filter( $this->cloud->files, static fn( $f ) => $f['name'] === $name ) );
		$this->assertCount( 1, $stored );
		$this->assertSame( md5_file( $file ), md5( $stored[0]['data'] ) );
		$this->assertSame( 1, $this->cloud->refreshes, 'the expired token was renewed once' );
		$this->assertSame( 'access-2', Vault::get( 'dest_' . $d->id() )['access'], 'and kept, encrypted' );

		// Download it back in ranges, across several requests.
		$id    = $state['id'];
		$back  = Storage::dir( 'tmp' ) . '/back.bbk';
		$ds    = array();
		$steps = 0;
		while ( ! $d->download( $id, $back, $ds, microtime( true ) - 1 ) ) {
			++$steps;
		}
		$this->assertGreaterThanOrEqual( 2, $steps );
		$this->assertFileEquals( $file, $back );
	}

	#[\PHPUnit\Framework\Attributes\DataProvider( 'clouds' )]
	public function test_listing_pages_through_and_shows_only_this_plugins_backups( string $class ): void {
		$d     = new $class();
		$names = array( 'bastion-old-example-20261101-010000-aaaaaa.bbk', 'bastion-old-example-20261103-010000-bbbbbb.bbk', 'bastion-old-example-20261102-010000-cccccc.bbk', 'holiday-photos.bbk' );
		foreach ( $names as $n ) {
			$st = array();
			$d->upload( $this->bigFile( $n, 1000 ), $n, $st, microtime( true ) + 5 );
		}
		$list = $d->list();
		$this->assertSame( array( $names[1], $names[2], $names[0] ), array_column( $list, 'name' ), 'newest first, three pages, the stranger left out' );
		$d->delete( $list[2]['id'] );
		$this->assertCount( 2, $d->list() );
	}

	public function test_a_revoked_connection_says_so(): void {
		$this->cloud->refresh = 'something-else';
		$r                    = ( new GoogleDrive() )->test();
		$this->assertIsString( $r );
		$this->assertStringContainsString( 'connect the account again', $r );
	}

	public function test_onedrive_upload_addresses_never_get_the_access_token(): void {
		$d    = new OneDrive();
		$name = 'bastion-old-example-20261109-120000-abcdef.bbk';
		$st   = array();
		$this->assertTrue( $d->upload( $this->bigFile( $name, 5000 ), $name, $st, microtime( true ) + 5 ) );
		// FakeClouds answers 401 if an upload URL is sent an Authorization header, so success proves it was not.
		$this->assertCount( 1, $this->cloud->files );
	}

	public function test_a_backup_is_copied_to_both_clouds_and_older_copies_there_are_pruned(): void {
		$this->makeKey();
		Settings::save( array_merge( Settings::get(), array( 'retain_remote' => 2, 'keep_local' => false, 'destinations' => array( 'google', 'onedrive' ) ) ) );
		$made = array();
		for ( $i = 0; $i < 3; $i++ ) {
			Jobs::start( 'backup', array( 'db' => true, 'files' => false, 'dests' => array( 'google', 'onedrive' ) ) );
			$s = $this->drive();
			$this->assertEmpty( $s['error'] ?? null, (string) ( $s['error'] ?? '' ) );
			$this->assertSame( array( 'local' => false, 'google' => true, 'onedrive' => true ), $s['result']['copies'] );
			$made[] = $s['result']['name'];
			sleep( 1 );
		}
		$this->assertSame( array( $made[2], $made[1] ), array_column( ( new GoogleDrive() )->list(), 'name' ) );
		$this->assertSame( array( $made[2], $made[1] ), array_column( ( new OneDrive() )->list(), 'name' ) );
		$this->assertSame( array(), Storage::localArchives(), 'kept elsewhere, so none on this server' );
	}

	public function test_one_destination_failing_does_not_stop_the_others(): void {
		$this->makeKey();
		Vault::set( 'dest_onedrive', array( 'client_secret' => 'shh', 'refresh' => 'revoked', 'access' => '', 'expires' => 0 ) );
		Jobs::start( 'backup', array( 'db' => true, 'files' => false, 'dests' => array( 'onedrive', 'google' ) ) );
		$s = $this->drive();
		$this->assertEmpty( $s['error'] ?? null );
		$this->assertSame( 'Backup complete, with warnings.', $s['message'] );
		$this->assertTrue( $s['result']['copies']['google'] );
		$this->assertArrayNotHasKey( 'onedrive', $s['result']['copies'] );
		$this->assertStringContainsString( 'Not copied to OneDrive', implode( ' ', $s['warnings'] ) );
	}

	public function test_file_server_settings_are_checked(): void {
		$fs = new FileServer();
		$this->assertSame( array( 'sha256', str_repeat( 'A', 43 ) ), FileServer::parseFingerprint( 'SHA256:' . str_repeat( 'A', 43 ) ) );
		$this->assertSame( 'md5', FileServer::parseFingerprint( 'MD5:aa:bb:cc:dd:ee:ff:00:11:22:33:44:55:66:77:88:99' )[0] );
		$this->assertNull( FileServer::parseFingerprint( 'yes trust it' ) );
		foreach ( array( array( 'host' => 'sftp://evil' ), array( 'host' => 'ok.example', 'path' => '../../etc' ), array( 'host' => 'ok.example', 'fingerprint' => 'nope' ) ) as $bad ) {
			try {
				$fs->save( $bad );
				$this->fail( 'accepted ' . json_encode( $bad ) );
			} catch ( \BastionBackup\Core\BackupException $e ) {
				$this->assertNotSame( '', $e->getMessage() );
			}
		}
		$fs->save( array( 'protocol' => 'sftp', 'host' => 'nas.example.org', 'username' => 'backup', 'password' => 'pw', 'path' => 'sites/a' ) );
		$this->assertFalse( $fs->isConfigured(), 'SFTP is not usable without the host key fingerprint' );
		$fs->save( array( 'protocol' => 'sftp', 'host' => 'nas.example.org', 'username' => 'backup', 'path' => 'sites/a', 'fingerprint' => 'SHA256:' . str_repeat( 'B', 43 ) ) );
		$this->assertTrue( $fs->isConfigured(), 'the saved password was kept' );
		$this->assertStringNotContainsString( 'pw', (string) json_encode( get_option( Settings::DEST_OPTION ) ), 'the password is not in the plain settings' );
	}

	public function test_only_this_plugins_names_count_as_backups(): void {
		$this->assertTrue( Registry::isOurs( 'bastion-old-example-20261109-120000-abcdef.bbk' ) );
		foreach ( array( '../bastion-x-20261109-120000-abcdef.bbk', 'bastion-x-20261109-120000-abcdef.bbk.part', 'wp-config.php', 'bastion-X-20261109-120000-abcdef.bbk' ) as $n ) {
			$this->assertFalse( Registry::isOurs( $n ), $n );
		}
	}
}
