<?php

declare(strict_types=1);

namespace BastionBackup\Tests\Plugin;

use BastionBackup\Core\ArchiveReader;
use BastionBackup\Core\Db;
use BastionBackup\Jobs;
use BastionBackup\Keys;
use BastionBackup\Settings;
use BastionBackup\Storage;
use BastionBackup\Vault;
use BastionBackup\Tests\Support\SiteCase;

/** Backup → check → damage the site → restore, through the real job engine, on MariaDB. */
final class BackupRestoreTest extends SiteCase {

	private function backup( array $params = array() ): array {
		Jobs::start( 'backup', $params + array( 'db' => true, 'files' => true, 'dests' => array(), 'reason' => 'test' ) );
		$s = $this->drive();
		$this->assertEmpty( $s['error'] ?? null, (string) ( $s['error'] ?? '' ) );
		return $s;
	}

	public function test_no_backup_without_a_key(): void {
		$this->expectExceptionMessage( 'no backup key' );
		Jobs::start( 'backup', array( 'db' => true, 'files' => true, 'dests' => array() ) );
		$s = $this->drive();
		throw new \BastionBackup\Core\BackupException( (string) $s['error'] );
	}

	public function test_the_key_is_shown_once_and_only_its_public_half_is_kept(): void {
		$k   = $this->makeKey();
		$raw = (string) $GLOBALS['wpdb']->get_var( "SELECT GROUP_CONCAT(option_value) FROM wp_options" );
		$this->assertStringNotContainsString( substr( $k['text'], 4, 20 ), $raw, 'the key text is stored nowhere' );
		$this->assertSame( $k['id'], Keys::current()['id'] );
	}

	public function test_a_full_backup_checks_out_and_restores_the_site_exactly(): void {
		$key        = $this->makeKey();
		$files      = $this->files();
		$posts      = $this->posts();
		$s          = $this->backup();
		$name       = (string) $s['result']['name'];
		$this->assertMatchesRegularExpression( '/^bastion-old-example-\d{8}-\d{6}-[a-f0-9]{6}\.bbk$/', $name );
		$this->assertSame( 100, $s['percent'] );
		$this->assertSame( $key['id'], ArchiveReader::readHeader( Storage::archivePath( $name ) )['kid'] );
		$this->assertFileDoesNotExist( Jobs::listFile( (string) $s['id'] ), 'working list removed' );
		$this->assertSame( array(), glob( Storage::dir( 'jobs' ) . '/*.json' ), 'job state (key material) removed' );
		$this->assertArrayNotHasKey( 'w', (array) get_option( Jobs::CURRENT ), 'the page never sees stream state' );

		// "Check with the key": everything read, nothing written.
		Jobs::start( 'restore', array( 'source' => array( 'kind' => 'local', 'name' => $name ), 'verify_only' => true ), array( 'key' => Vault::seal( $key['text'] ) ) );
		$c = $this->drive();
		$this->assertEmpty( $c['error'] ?? null, (string) ( $c['error'] ?? '' ) );
		$this->assertSame( 'The backup is complete and opens with this key.', $c['message'] );
		$this->assertSame( 5, $c['result']['files'], 'index.php, wp-config.php, a plugin, two uploads; not the cache' );

		// Damage the site after the backup; change this plugin's settings too.
		unlink( WP_CONTENT_DIR . '/uploads/2026/11/photo.jpg' );
		file_put_contents( ABSPATH . 'index.php', 'hacked' );
		Db::query( $this->db, 'DELETE FROM wp_posts WHERE ID > 100' );
		update_option( 'blogname', 'Changed' );
		$settings = array_merge( Settings::get(), array( 'retain_local' => 9 ) );
		Settings::save( $settings );
		$keyNow = Keys::current()['id'];

		Jobs::start( 'restore', array( 'source' => array( 'kind' => 'local', 'name' => $name ), 'verify_only' => false ), array( 'key' => Vault::seal( $key['text'] ) ) );
		$r = $this->drive();
		$this->assertTrue( $r['waiting'], 'stops for the administrator to confirm' );
		$this->assertSame( 'https://old.example', $r['manifest']['site_url'] );
		$this->assertSame( $posts === $this->posts(), false, 'nothing restored before confirming' );
		Jobs::resume( array( 'phase' => 'prepare', 'options' => array( 'db' => true, 'files' => true ) ) );
		$r = $this->drive();
		$this->assertEmpty( $r['error'] ?? null, (string) ( $r['error'] ?? '' ) );

		$this->assertSame( $files, $this->files(), 'files back exactly' );
		$this->assertSame( "<?php define('DB_NAME','live');", file_get_contents( ABSPATH . 'wp-config.php' ) );
		wp_cache_flush();
		$this->assertSame( $posts, $this->posts() );
		$this->assertSame( 'Test site', get_option( 'blogname' ) );
		$this->assertSame( 9, Settings::get()['retain_local'], 'the plugin\'s own settings survive the swap' );
		$this->assertSame( $keyNow, Keys::current()['id'] );
		$this->assertFalse( get_option( 'bastion_backup_maintenance' ), 'maintenance page off' );
		$this->assertContains( 'bastion-backup/bastion-backup.php', (array) get_option( 'active_plugins', array() ) );
		$this->assertSame( 0, Db::query( $this->db, "SHOW TABLES LIKE 'bbk%'" )->num_rows );
		$this->assertSame( 1, Db::query( $this->db, "SHOW TABLES LIKE 'other_app'" )->num_rows, 'tables of other apps untouched' );
	}

	public function test_the_wrong_key_changes_nothing(): void {
		$this->makeKey();
		$name  = (string) $this->backup( array( 'files' => false ) )['result']['name'];
		$other = \BastionBackup\Core\Crypto::newKeyPair();
		Db::query( $this->db, 'DELETE FROM wp_posts WHERE ID > 10' );
		$posts = $this->posts();
		Jobs::start( 'restore', array( 'source' => array( 'kind' => 'local', 'name' => $name ), 'verify_only' => false ), array( 'key' => Vault::seal( $other['text'] ) ) );
		$r = $this->drive();
		$this->assertStringContainsString( 'does not open this backup', (string) $r['error'] );
		$this->assertSame( $posts, $this->posts() );
	}

	public function test_moving_to_a_new_address(): void {
		$key  = $this->makeKey();
		$name = (string) $this->backup( array( 'files' => false ) )['result']['name'];
		// The same backup restored on a site at another address.
		update_option( 'siteurl', 'https://new.example.org' );
		update_option( 'home', 'https://new.example.org' );
		Jobs::start( 'restore', array( 'source' => array( 'kind' => 'local', 'name' => $name ), 'verify_only' => false ), array( 'key' => Vault::seal( $key['text'] ) ) );
		$this->drive();
		Jobs::resume( array( 'phase' => 'prepare', 'options' => array( 'db' => true, 'files' => false ) ) );
		$r = $this->drive();
		$this->assertEmpty( $r['error'] ?? null, (string) ( $r['error'] ?? '' ) );
		wp_cache_flush();
		$this->assertSame( 'https://new.example.org', get_option( 'siteurl' ) );
		$this->assertSame( 'See https://new.example.org/about', get_option( 'widget_text' )[2]['text'], 'serialized option rewritten' );
		$row = Db::query( $this->db, 'SELECT post_content, guid FROM wp_posts WHERE ID = 7' )->fetch_row();
		$this->assertStringContainsString( 'https://new.example.org/p/7', $row[0] );
		$this->assertSame( 'https://old.example/?p=7', $row[1], 'GUIDs kept' );
	}

	public function test_cancelling_removes_the_half_made_backup(): void {
		$this->makeKey();
		Jobs::start( 'backup', array( 'db' => true, 'files' => true, 'dests' => array() ) );
		Jobs::run( microtime( true ) + 0.001 );
		$this->assertCount( 1, glob( Storage::dir( 'archives' ) . '/*.bbk' ) );
		Jobs::cancel();
		$this->assertCount( 0, glob( Storage::dir( 'archives' ) . '/*.bbk' ) );
		$this->assertFalse( Jobs::running() );
	}

	public function test_only_one_job_at_a_time(): void {
		$this->makeKey();
		Jobs::start( 'backup', array( 'db' => true, 'files' => false, 'dests' => array() ) );
		$this->expectExceptionMessage( 'already running' );
		Jobs::start( 'backup', array( 'db' => true, 'files' => false, 'dests' => array() ) );
	}

	public function test_the_cache_folder_and_the_backups_themselves_are_left_out_and_retention_keeps_the_newest(): void {
		$key = $this->makeKey();
		Settings::save( array_merge( Settings::get(), array( 'retain_local' => 2 ) ) );
		$names = array();
		for ( $i = 0; $i < 3; $i++ ) {
			$names[] = (string) $this->backup()['result']['name'];
			sleep( 1 ); // Names carry the second.
		}
		$left = array_column( Storage::localArchives(), 'name' );
		$this->assertSame( array( $names[2], $names[1] ), $left );
		// The newest holds neither the cache nor the older backups.
		Jobs::start( 'restore', array( 'source' => array( 'kind' => 'local', 'name' => $names[2] ), 'verify_only' => true ), array( 'key' => Vault::seal( $key['text'] ) ) );
		$this->drive();
		$r     = \BastionBackup\Core\ArchiveReader::open( Storage::archivePath( $names[2] ), $key['text'] );
		$paths = array();
		while ( $rec = $r->next() ) {
			if ( 'F' === $rec['type'] ) {
				$paths[] = $rec['meta']['p'];
			}
		}
		$this->assertNotContains( 'wp-content/cache/page.html', $paths );
		$this->assertEmpty( array_filter( $paths, static fn( $p ) => str_contains( $p, 'bastion-backups-' ) ) );
		$this->assertContains( 'wp-content/uploads/2026/11/photo.jpg', $paths );
	}
}
