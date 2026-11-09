<?php

declare(strict_types=1);

namespace BastionBackup\Tests\Core;

use BastionBackup\Core\ArchiveWriter;
use BastionBackup\Core\BackupException;
use BastionBackup\Core\Crypto;
use BastionBackup\Core\Db;
use BastionBackup\Core\DbExporter;
use BastionBackup\Core\DbImporter;
use BastionBackup\Core\Restorer;
use BastionBackup\Core\SearchReplace;
use BastionBackup\Tests\Support\TempDir;
use PHPUnit\Framework\TestCase;

/** Export → archive → import into temporary tables → search/replace → atomic swap, on a real MySQL/MariaDB. */
final class DatabaseTest extends TestCase {

	use TempDir;

	private \mysqli $db;

	protected function setUp(): void {
		try {
			$this->db = Db::connect( (string) getenv( 'BBK_TEST_DB_HOST' ), (string) getenv( 'BBK_TEST_DB_USER' ), (string) getenv( 'BBK_TEST_DB_PASS' ), (string) getenv( 'BBK_TEST_DB_NAME' ) );
		} catch ( BackupException $e ) {
			$this->markTestSkipped( 'No test database: ' . $e->getMessage() );
		}
		$this->makeTemp();
		$res = Db::query( $this->db, 'SHOW TABLES' );
		while ( $r = $res->fetch_row() ) {
			Db::query( $this->db, 'DROP TABLE ' . Db::ident( (string) $r[0] ) );
		}
	}

	protected function tearDown(): void {
		$this->removeTemp();
	}

	private function q( string $sql ): void {
		Db::query( $this->db, $sql );
	}

	private function seedSite( string $p ): void {
		$this->q( "CREATE TABLE `{$p}options` (option_id bigint unsigned NOT NULL AUTO_INCREMENT, option_name varchar(191) NOT NULL, option_value longtext NOT NULL, autoload varchar(20) NOT NULL DEFAULT 'yes', PRIMARY KEY (option_id), UNIQUE KEY option_name (option_name)) DEFAULT CHARSET=utf8mb4" );
		$this->q( "CREATE TABLE `{$p}usermeta` (umeta_id bigint unsigned NOT NULL AUTO_INCREMENT, user_id bigint unsigned NOT NULL DEFAULT 0, meta_key varchar(255), meta_value longtext, PRIMARY KEY (umeta_id)) DEFAULT CHARSET=utf8mb4" );
		$this->q( "CREATE TABLE `{$p}posts` (ID bigint unsigned NOT NULL AUTO_INCREMENT, post_content longtext NOT NULL, guid varchar(255) NOT NULL DEFAULT '', PRIMARY KEY (ID)) DEFAULT CHARSET=utf8mb4" );
		// Awkward columns: binary, BIT, a generated column, zero in an auto-increment column, emoji, NULLs.
		$this->q( "CREATE TABLE `{$p}odd` (id int NOT NULL AUTO_INCREMENT, b blob, bits bit(9), price decimal(10,2), label varchar(50) CHARACTER SET utf8mb4, dbl int GENERATED ALWAYS AS (id * 2) VIRTUAL, PRIMARY KEY (id)) DEFAULT CHARSET=utf8mb4" );
		// No primary key at all.
		$this->q( "CREATE TABLE `{$p}nokey` (k varchar(20), v text) DEFAULT CHARSET=utf8mb4" );
		$roles = serialize( array( 'administrator' => array( 'name' => 'Administrator', 'capabilities' => array( 'manage_options' => true ) ) ) );
		$widget = serialize( array( 2 => array( 'title' => 'Links', 'text' => '<a href="http://old.test/about">About</a>' ) ) );
		$this->q( "INSERT INTO `{$p}options` (option_name, option_value) VALUES ('siteurl','http://old.test'),('home','http://old.test'),('{$p}user_roles','" . $this->db->real_escape_string( $roles ) . "'),('widget_text','" . $this->db->real_escape_string( $widget ) . "')" );
		$this->q( "INSERT INTO `{$p}usermeta` (user_id, meta_key, meta_value) VALUES (1,'{$p}capabilities','a:1:{s:13:\"administrator\";b:1;}'),(1,'{$p}user_level','10'),(1,'nickname','admin')" );
		$this->q( "INSERT INTO `{$p}posts` (post_content, guid) VALUES ('See http://old.test/page and /var/www/old/img.png 😀','http://old.test/?p=1')" );
		$this->q( 'SET SESSION sql_mode = \'NO_AUTO_VALUE_ON_ZERO\'' );
		$this->q( "INSERT INTO `{$p}odd` (id, b, bits, price, label) VALUES (0, 0x00FF10, b'101010101', 12.50, 'zero row'),(1, NULL, NULL, NULL, NULL),(2, '', b'0', -3.10, 'naïve 👩🏽‍🔬')" );
		$rows = array();
		for ( $i = 0; $i < 3000; $i++ ) {
			$rows[] = "('k{$i}', '" . str_repeat( 'v', $i % 200 ) . "')";
		}
		$this->q( "INSERT INTO `{$p}nokey` VALUES " . implode( ',', $rows ) );
	}

	/** @return array<string,string> table => checksum of every row as text */
	private function snapshot( string $p ): array {
		$out = array();
		foreach ( array( 'options', 'usermeta', 'posts', 'odd', 'nokey' ) as $t ) {
			$res = Db::query( $this->db, 'SELECT * FROM ' . Db::ident( $p . $t ) );
			$all = array();
			while ( $r = $res->fetch_row() ) {
				$all[] = json_encode( array_map( static fn( $v ) => null === $v ? null : bin2hex( (string) $v ), $r ) );
			}
			sort( $all );
			$out[ $t ] = md5( implode( "\n", $all ) ) . ':' . count( $all );
		}
		return $out;
	}

	private function export( string $prefix, string $arch, string $public ): void {
		$w  = ArchiveWriter::createForKey( $arch, $public );
		$st = $w->export();
		$w->close();
		$ex = new DbExporter( $this->db );
		$es = DbExporter::initState( $ex->listTables( $prefix, false ) );
		do {
			$w    = ArchiveWriter::resume( $arch, $st );
			$done = $ex->step( $es, $w, microtime( true ) + 0.002 ); // Tiny steps: resume between batches.
			$st   = $w->export();
			$w->close();
		} while ( ! $done );
		$w = ArchiveWriter::resume( $arch, $st );
		$w->finish();
	}

	private function import( string $arch, string $text, string $src, string $dst, array $pairs ): DbImporter {
		$imp = new DbImporter( $this->db, $src, $dst );
		$imp->session();
		$rs = Restorer::initState();
		$r  = new Restorer( $arch, $text, 'restore', $imp, null, true, false );
		while ( ! $r->step( $rs, microtime( true ) + 0.01 ) ) {
			// Resumable.
		}
		$rep = $imp->replaceInit( $rs['tables'] );
		while ( ! $imp->replaceStep( $rep, SearchReplace::expand( $pairs ), microtime( true ) + 0.01 ) ) {
			// Resumable.
		}
		$old = $imp->swap( $rs['tables'] );
		$imp->dropTables( $old );
		return $imp;
	}

	public function test_every_kind_of_column_comes_back_exactly_on_the_same_site(): void {
		$this->seedSite( 'wp_' );
		$before = $this->snapshot( 'wp_' );
		$key    = Crypto::newKeyPair();
		$arch   = $this->tmp . '/db.bbk';
		$this->export( 'wp_', $arch, $key['public'] );
		// The live site changes after the backup; the restore must bring back the backed-up state exactly.
		$this->q( "UPDATE wp_options SET option_value = 'changed' WHERE option_name = 'home'" );
		$this->q( 'DELETE FROM wp_nokey LIMIT 10' );
		$this->import( $arch, $key['text'], 'wp_', 'wp_', array() );
		$this->assertSame( $before, $this->snapshot( 'wp_' ) );
		$res = Db::query( $this->db, "SHOW TABLES LIKE 'bbk%'" );
		$this->assertSame( 0, $res->num_rows, 'no temporary or displaced tables left behind' );
		$res = Db::query( $this->db, 'SELECT dbl FROM wp_odd WHERE id = 2' );
		$this->assertSame( '4', $res->fetch_row()[0], 'generated column computed again' );
	}

	public function test_moving_to_a_new_address_and_table_prefix(): void {
		$this->seedSite( 'wp_' );
		$key  = Crypto::newKeyPair();
		$arch = $this->tmp . '/db.bbk';
		$this->export( 'wp_', $arch, $key['public'] );
		$this->import( $arch, $key['text'], 'wp_', 'nx_', array( 'http://old.test' => 'https://new.example', '/var/www/old' => '/srv/new' ) );

		$opt = static fn( \mysqli $db, string $n ): ?string => Db::query( $db, "SELECT option_value FROM nx_options WHERE option_name = '" . $db->real_escape_string( $n ) . "'" )->fetch_row()[0] ?? null;
		$this->assertSame( 'https://new.example', $opt( $this->db, 'siteurl' ) );
		$this->assertSame( 'https://new.example', $opt( $this->db, 'home' ) );
		$this->assertNotNull( $opt( $this->db, 'nx_user_roles' ), 'user_roles renamed for the new prefix' );
		$this->assertNull( $opt( $this->db, 'wp_user_roles' ) );
		$widget = unserialize( (string) $opt( $this->db, 'widget_text' ) );
		$this->assertSame( '<a href="https://new.example/about">About</a>', $widget[2]['text'], 'serialized widget rewritten with the right length' );
		$keys = array_column( Db::query( $this->db, 'SELECT meta_key FROM nx_usermeta ORDER BY umeta_id' )->fetch_all(), 0 );
		$this->assertSame( array( 'nx_capabilities', 'nx_user_level', 'nickname' ), $keys );
		$post = Db::query( $this->db, 'SELECT post_content, guid FROM nx_posts' )->fetch_row();
		$this->assertSame( 'See https://new.example/page and /srv/new/img.png 😀', $post[0] );
		$this->assertSame( 'http://old.test/?p=1', $post[1], 'GUIDs are never rewritten' );
		$this->assertSame( 3000, (int) Db::query( $this->db, 'SELECT COUNT(*) FROM nx_nokey' )->fetch_row()[0] );
	}

	public function test_the_importer_refuses_any_other_sql(): void {
		$imp = new DbImporter( $this->db, 'wp_', 'wp_' );
		foreach ( array( 'DROP TABLE wp_options', 'CREATE TABLE `wp_users` (id int)', 'INSERT INTO `wp_users` VALUES (1)', "CREATE TABLE `__BBK_T__x` (id int)" ) as $sql ) {
			try {
				str_starts_with( $sql, 'INSERT' ) ? $imp->insert( 'wp_x', $sql ) : $imp->createTable( 'wp_x', $sql );
				$this->fail( 'accepted: ' . $sql );
			} catch ( BackupException $e ) {
				$this->assertStringContainsString( 'Rejected', $e->getMessage() );
			}
		}
	}

	public function test_a_failure_before_the_swap_leaves_the_live_tables_untouched(): void {
		$this->seedSite( 'wp_' );
		$before = $this->snapshot( 'wp_' );
		$imp    = new DbImporter( $this->db, 'wp_', 'wp_' );
		$imp->createTable( 'wp_options', 'CREATE TABLE `__BBK_T__` (x int)' );
		try {
			$imp->swap( array( 'wp_options', 'wp_posts' ) ); // wp_posts has no temporary table.
			$this->fail( 'swapped with a table missing' );
		} catch ( BackupException $e ) {
			$this->assertStringContainsString( 'aborting before swap', $e->getMessage() );
		}
		$imp->dropTemp( array( 'wp_options', 'wp_posts' ) );
		$this->assertSame( $before, $this->snapshot( 'wp_' ) );
	}
}
