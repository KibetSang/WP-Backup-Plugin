<?php

declare(strict_types=1);

namespace BastionBackup\Tests\Plugin;

use BastionBackup\Core\BackupException;
use BastionBackup\Jobs;
use BastionBackup\Storage;
use BastionBackup\Tests\Support\SiteCase;
use BastionBackup\Vault;

/** Backups kept in a folder of the owner's choosing on this server, e.g. beside public_html. */
final class LocalFolderTest extends SiteCase {

	private string $outside = '';

	protected function tearDown(): void {
		if ( '' !== $this->outside ) {
			$this->wipe( $this->outside );
			@rmdir( $this->outside );
		}
	}

	public function test_backups_move_to_a_folder_outside_the_website_and_new_ones_go_there(): void {
		$key = $this->makeKey();
		Jobs::start( 'backup', array( 'db' => true, 'files' => false, 'dests' => array() ) );
		$first = (string) $this->drive()['result']['name'];
		$this->assertTrue( Storage::publicPath() );

		$this->outside = sys_get_temp_dir() . '/bbk-outside-' . bin2hex( random_bytes( 4 ) );
		$r             = Storage::move( $this->outside . '/backups' );
		$this->assertSame( 1, $r['moved'] );
		$this->assertFalse( Storage::publicPath(), 'now outside the website' );
		$this->assertFileExists( $this->outside . '/backups/archives/' . $first );
		$this->assertFileExists( $this->outside . '/backups/.htaccess', 'still protected' );

		sleep( 1 );
		Jobs::start( 'backup', array( 'db' => true, 'files' => true, 'dests' => array() ) );
		$s = $this->drive();
		$this->assertEmpty( $s['error'] ?? null, (string) ( $s['error'] ?? '' ) );
		$this->assertFileExists( $this->outside . '/backups/archives/' . $s['result']['name'] );
		$this->assertCount( 2, Storage::localArchives() );

		// The chosen folder survives a restore of an older database.
		Jobs::start( 'restore', array( 'source' => array( 'kind' => 'local', 'name' => $first ), 'verify_only' => false ), array( 'key' => Vault::seal( $key['text'] ) ) );
		$this->drive();
		\BastionBackup\Jobs::resume( array( 'phase' => 'prepare', 'options' => array( 'db' => true, 'files' => false ) ) );
		$this->assertEmpty( $this->drive()['error'] ?? null );
		wp_cache_flush();
		$this->assertStringStartsWith( realpath( $this->outside ), Storage::root() );
	}

	public function test_wordpress_folders_and_folders_holding_the_site_are_refused(): void {
		foreach ( array( untrailingslashit( ABSPATH ), ABSPATH . 'wp-admin', ABSPATH . 'wp-includes/x', dirname( untrailingslashit( ABSPATH ) ), 'relative/path', '/' ) as $bad ) {
			try {
				Storage::move( $bad );
				$this->fail( 'accepted ' . $bad );
			} catch ( BackupException $e ) {
				$this->assertNotSame( '', $e->getMessage() );
			}
		}
	}
}
