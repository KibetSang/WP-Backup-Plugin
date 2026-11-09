<?php

declare(strict_types=1);

namespace BastionBackup\Tests\Support;

use BastionBackup\Core\Db;
use BastionBackup\Jobs;
use BastionBackup\Keys;
use BastionBackup\Settings;
use BastionBackup\Storage;
use PHPUnit\Framework\TestCase;

/** A small WordPress-shaped site (files + tables) for the plugin tests, rebuilt before each test. */
abstract class SiteCase extends TestCase {

	protected \mysqli $db;

	protected function setUp(): void {
		try {
			$this->db = Db::connect( DB_HOST, DB_USER, DB_PASSWORD, DB_NAME );
		} catch ( \Throwable $e ) {
			$this->markTestSkipped( 'No test database.' );
		}
		$res = Db::query( $this->db, 'SHOW TABLES' );
		while ( $r = $res->fetch_row() ) {
			Db::query( $this->db, 'DROP TABLE ' . Db::ident( (string) $r[0] ) );
		}
		$GLOBALS['wpdb']->db = null;
		bbk_test_reset_options_table();
		$this->wipe( ABSPATH );
		mkdir( WP_CONTENT_DIR . '/uploads/2026/11', 0777, true );
		mkdir( WP_CONTENT_DIR . '/plugins/hello', 0777, true );
		mkdir( WP_CONTENT_DIR . '/cache', 0777, true );
		file_put_contents( ABSPATH . 'index.php', '<?php // front' );
		file_put_contents( ABSPATH . 'wp-config.php', "<?php define('DB_NAME','live');" );
		file_put_contents( WP_CONTENT_DIR . '/plugins/hello/hello.php', '<?php // plugin' );
		file_put_contents( WP_CONTENT_DIR . '/uploads/2026/11/photo.jpg', random_bytes( 3 * 1048576 + 7 ) );
		file_put_contents( WP_CONTENT_DIR . '/uploads/2026/11/notes.txt', str_repeat( 'notes ', 200000 ) );
		file_put_contents( WP_CONTENT_DIR . '/cache/page.html', 'cached' );

		update_option( 'siteurl', 'https://old.example' );
		update_option( 'home', 'https://old.example' );
		update_option( 'blogname', 'Test site' );
		update_option( 'widget_text', serialize( array( 2 => array( 'text' => 'See https://old.example/about' ) ) ) );
		Db::query( $this->db, "CREATE TABLE wp_posts (ID bigint unsigned NOT NULL AUTO_INCREMENT, post_content longtext NOT NULL, guid varchar(255) NOT NULL DEFAULT '', PRIMARY KEY (ID)) DEFAULT CHARSET=utf8mb4" );
		$rows = array();
		for ( $i = 1; $i <= 2500; $i++ ) {
			$rows[] = "('Post $i on https://old.example/p/$i " . str_repeat( 'x', $i % 300 ) . "', 'https://old.example/?p=$i')";
		}
		Db::query( $this->db, 'INSERT INTO wp_posts (post_content, guid) VALUES ' . implode( ',', $rows ) );
		Db::query( $this->db, 'CREATE TABLE other_app (id int PRIMARY KEY)' ); // Not WordPress's: left out by default.

		Settings::save( array_merge( Settings::defaults(), array( 'step_seconds' => 5 ) ) );
		Storage::ensure();
		bbk_test_login();
		$GLOBALS['bbk_http']     = null;
		$GLOBALS['bbk_http_log'] = array();
		$GLOBALS['bbk_mail']     = array();
	}

	protected function wipe( string $dir ): void {
		if ( ! is_dir( $dir ) ) {
			return;
		}
		$it = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ), \RecursiveIteratorIterator::CHILD_FIRST );
		foreach ( $it as $f ) {
			( $f->isDir() && ! $f->isLink() ) ? rmdir( $f->getPathname() ) : unlink( $f->getPathname() );
		}
	}

	/** Runs the current job in short steps until it finishes or waits. */
	protected function drive( float $step = 0.05, int $max = 5000 ): array {
		for ( $i = 0; $i < $max; $i++ ) {
			$s = Jobs::run( microtime( true ) + $step );
			if ( ! empty( $s['finished'] ) || ! empty( $s['waiting'] ) ) {
				return $s;
			}
		}
		$this->fail( 'The job did not finish: ' . json_encode( Jobs::status() ) );
	}

	protected function makeKey(): array {
		return Keys::make( 'admin' );
	}

	/** Every file under the site (except the plugin's storage) with its checksum. */
	protected function files(): array {
		$out  = array();
		$skip = wp_normalize_path( Storage::root() );
		$it   = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( ABSPATH, \FilesystemIterator::SKIP_DOTS ) );
		foreach ( $it as $f ) {
			$p = wp_normalize_path( $f->getPathname() );
			if ( ! str_starts_with( $p, $skip ) ) {
				$out[ substr( $p, strlen( ABSPATH ) ) ] = md5_file( $p );
			}
		}
		ksort( $out );
		return $out;
	}

	protected function posts(): string {
		$r = Db::query( $this->db, 'SELECT ID, post_content, guid FROM wp_posts ORDER BY ID' );
		return md5( json_encode( $r->fetch_all() ) );
	}
}
