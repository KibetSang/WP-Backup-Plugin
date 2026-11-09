<?php

declare(strict_types=1);

namespace BastionBackup\Tests\Plugin;

use BastionBackup\Core\Db;
use BastionBackup\InstallerBuilder;
use BastionBackup\Jobs;
use BastionBackup\Storage;
use BastionBackup\Tests\Support\SiteCase;

/** installer.php on a "new server": PHP's built-in web server, an empty folder, an empty database. */
final class InstallerTest extends SiteCase {

	private string $dir = '';
	/** @var resource|null */
	private $server = null;
	private int $port = 0;
	private string $jar = '';

	protected function tearDown(): void {
		if ( is_resource( $this->server ) ) {
			proc_terminate( $this->server );
			proc_close( $this->server );
		}
		if ( '' !== $this->dir ) {
			$this->wipe( $this->dir );
			@rmdir( $this->dir );
		}
		@unlink( $this->jar );
	}

	/** @return array{0:int,1:string} */
	private function http( string $method, array $post = array() ): array {
		$h = curl_init( 'http://127.0.0.1:' . $this->port . '/installer.php' );
		curl_setopt_array( $h, array( CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $this->jar, CURLOPT_COOKIEFILE => $this->jar, CURLOPT_TIMEOUT => 120 ) );
		if ( 'POST' === $method ) {
			curl_setopt( $h, CURLOPT_POSTFIELDS, $post );
		}
		$body = (string) curl_exec( $h );
		return array( (int) curl_getinfo( $h, CURLINFO_RESPONSE_CODE ), $body );
	}

	private function post( array $fields ): array {
		[ , $body ] = $this->http( 'POST', $fields + array( 'csrf' => $this->csrf ) );
		$d          = json_decode( $body, true );
		$this->assertIsArray( $d, 'not JSON: ' . substr( $body, 0, 300 ) );
		return $d;
	}

	private string $csrf = '';

	public function test_the_installer_restores_onto_a_new_server_with_a_new_address_database_and_prefix(): void {
		$key = $this->makeKey();
		Jobs::start( 'backup', array( 'db' => true, 'files' => true, 'dests' => array() ) );
		$s = $this->drive();
		$this->assertEmpty( $s['error'] ?? null );
		$name = (string) $s['result']['name'];

		// The new server.
		$this->dir = sys_get_temp_dir() . '/bbk-new-' . bin2hex( random_bytes( 4 ) );
		mkdir( $this->dir );
		$access = 'ABCDE-12345-FGHIJ-67890';
		$code   = InstallerBuilder::build( password_hash( $access, PASSWORD_DEFAULT ) );
		file_put_contents( $this->dir . '/installer.php', $code );
		copy( Storage::archivePath( $name ), $this->dir . '/' . $name );
		exec( 'php -l ' . escapeshellarg( $this->dir . '/installer.php' ), $o, $rc );
		$this->assertSame( 0, $rc, implode( "\n", $o ) );

		$fresh = Db::connect( DB_HOST, DB_USER, DB_PASSWORD, 'wp2' );
		$res   = Db::query( $fresh, 'SHOW TABLES' );
		while ( $r = $res->fetch_row() ) {
			Db::query( $fresh, 'DROP TABLE ' . Db::ident( (string) $r[0] ) );
		}

		$this->port   = random_int( 20000, 40000 );
		$this->jar    = tempnam( sys_get_temp_dir(), 'bbkjar' );
		$this->server = proc_open( array( PHP_BINARY, '-S', '127.0.0.1:' . $this->port, '-t', $this->dir ), array( array( 'pipe', 'r' ), array( 'file', '/dev/null', 'w' ), array( 'file', '/dev/null', 'w' ) ), $pipes );
		for ( $i = 0; $i < 50 && ! @fsockopen( '127.0.0.1', $this->port ); $i++ ) {
			usleep( 100000 );
		}

		[ $code, $page ] = $this->http( 'GET' );
		$this->assertSame( 200, $code );
		$this->assertStringContainsString( 'not using HTTPS', $page, 'warns without HTTPS' );
		$this->assertMatchesRegularExpression( '/const csrf="([a-f0-9]{32})"/', $page );
		preg_match( '/const csrf="([a-f0-9]{32})"/', $page, $m );
		$this->csrf = $m[1];

		$this->assertFalse( $this->post( array( 'action' => 'start', 'archive' => $name, 'key' => $key['text'] ) )['ok'], 'nothing before the access key' );
		$this->assertStringContainsString( 'not the access key', $this->post( array( 'action' => 'login', 'access' => 'WRONG' ) )['error'] );
		$this->assertTrue( $this->post( array( 'action' => 'login', 'access' => strtolower( $access ) ) )['ok'] );
		[ , $page ] = $this->http( 'GET' );
		preg_match( '/const csrf="([a-f0-9]{32})"/', $page, $m );
		$this->csrf = $m[1];
		preg_match( '/id="url" value="([^"]+)"/', $page, $u );
		$this->assertSame( 'http://127.0.0.1:' . $this->port, $u[1], 'the new address is found by itself' );

		$db = array( 'db_host' => DB_HOST, 'db_name' => 'wp2', 'db_user' => DB_USER, 'db_pass' => DB_PASSWORD );
		$this->assertTrue( $this->post( array( 'action' => 'dbtest' ) + $db )['ok'] );
		$this->assertTrue( $this->post( array( 'action' => 'start', 'archive' => $name, 'key' => $key['text'] ) )['ok'] );
		$step = fn( array $extra = array() ) => $this->post( array( 'action' => 'step', 'key' => $key['text'], 'url' => $u[1], 'prefix' => 'nw_' ) + $db + $extra );
		do {
			$r = $step();
			$this->assertTrue( $r['ok'], (string) ( $r['error'] ?? '' ) );
		} while ( 'ready' !== $r['phase'] );
		$this->assertSame( 'https://old.example', $r['manifest']['site_url'] );
		$this->assertSame( 0, (int) Db::query( $fresh, 'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()' )->fetch_row()[0], 'checking wrote nothing' );
		$this->assertStringContainsString( 'Type RESTORE', (string) $step( array( 'confirm' => 'yes' ) )['error'] );
		$r = $step( array( 'confirm' => 'RESTORE' ) );
		while ( 'done' !== $r['phase'] ) {
			$this->assertTrue( $r['ok'], (string) ( $r['error'] ?? '' ) );
			$r = $step();
		}

		$this->assertFileEquals( WP_CONTENT_DIR . '/uploads/2026/11/photo.jpg', $this->dir . '/wp-content/uploads/2026/11/photo.jpg' );
		$cfg = (string) file_get_contents( $this->dir . '/wp-config.php' );
		$this->assertStringContainsString( "define( 'DB_NAME', 'wp2' )", $cfg );
		$this->assertStringContainsString( "define( 'DB_PASSWORD', " . var_export( DB_PASSWORD, true ) . ' )', $cfg );
		$this->assertStringContainsString( "\$table_prefix = 'nw_';", $cfg );
		$this->assertStringNotContainsString( 'live_user', $cfg, 'the old database login is gone, even in double quotes' );
		$this->assertStringNotContainsString( "it\\'s secret", $cfg );
		$this->assertStringContainsString( "require_once ABSPATH . 'wp-settings.php';", $cfg );
		exec( 'php -l ' . escapeshellarg( $this->dir . '/wp-config.php' ), $lint, $lrc );
		$this->assertSame( 0, $lrc, 'wp-config.php is still valid PHP' );
		$siteurl = Db::query( $fresh, "SELECT option_value FROM nw_options WHERE option_name = 'siteurl'" )->fetch_row()[0];
		$this->assertSame( $u[1], $siteurl );
		$this->assertSame( 2500, (int) Db::query( $fresh, 'SELECT COUNT(*) FROM nw_posts' )->fetch_row()[0] );
		$this->assertStringContainsString( $u[1] . '/p/9', (string) Db::query( $fresh, 'SELECT post_content FROM nw_posts WHERE ID = 9' )->fetch_row()[0] );

		$f = $this->post( array( 'action' => 'finish', 'delete_archive' => '1' ) );
		$this->assertSame( array(), $f['left'] );
		$this->assertFileDoesNotExist( $this->dir . '/installer.php' );
		$this->assertFileDoesNotExist( $this->dir . '/' . $name );
		$this->assertSame( array(), glob( $this->dir . '/.bbk-work-*' ) );
	}

	public function test_the_template_in_the_plugin_folder_does_nothing_when_opened(): void {
		exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( dirname( __DIR__, 2 ) . '/installer/installer-template.php' ), $out, $rc );
		$this->assertSame( '', implode( '', $out ) );
	}

	public function test_ten_wrong_access_keys_lock_it(): void {
		$this->dir = sys_get_temp_dir() . '/bbk-new-' . bin2hex( random_bytes( 4 ) );
		mkdir( $this->dir );
		file_put_contents( $this->dir . '/installer.php', InstallerBuilder::build( password_hash( 'RIGHT-KEY', PASSWORD_DEFAULT ) ) );
		$this->port   = random_int( 40001, 60000 );
		$this->jar    = tempnam( sys_get_temp_dir(), 'bbkjar' );
		$this->server = proc_open( array( PHP_BINARY, '-S', '127.0.0.1:' . $this->port, '-t', $this->dir ), array( array( 'pipe', 'r' ), array( 'file', '/dev/null', 'w' ), array( 'file', '/dev/null', 'w' ) ), $pipes );
		for ( $i = 0; $i < 50 && ! @fsockopen( '127.0.0.1', $this->port ); $i++ ) {
			usleep( 100000 );
		}
		[ , $page ] = $this->http( 'GET' );
		preg_match( '/const csrf="([a-f0-9]{32})"/', $page, $m );
		$this->csrf = $m[1];
		for ( $i = 0; $i < 10; $i++ ) {
			$this->post( array( 'action' => 'login', 'access' => 'guess' . $i ) );
		}
		$this->assertStringContainsString( 'Locked', (string) $this->post( array( 'action' => 'login', 'access' => 'RIGHT-KEY' ) )['error'] );
	}
}
