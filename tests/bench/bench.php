<?php
/**
 * The 10 GB benchmark (PROMPT §5): a synthetic site of one 4 GB video, five 1 GB archives, 50,000 small files and a
 * 1 GB database is backed up, checked and restored with memory_limit=128M, in 20-second steps (each step a separate
 * Jobs::run, as separate requests would be). Then every file and every row is compared.
 *
 * Run: php -d memory_limit=128M tests/bench/bench.php   (needs the test MariaDB; writes ~25 GB to the temp folder)
 */

declare(strict_types=1);

putenv( 'BBK_TEST_WP_DB=bench' ); // Its own database, so tests running meanwhile cannot disturb it.
require __DIR__ . '/../bootstrap-core.php';
require __DIR__ . '/../Support/wp-stubs.php';

use BastionBackup\Core\Db;
use BastionBackup\Jobs;
use BastionBackup\Keys;
use BastionBackup\Settings;
use BastionBackup\Storage;
use BastionBackup\Vault;

$t0  = microtime( true );
$log = static function ( string $m ) use ( &$t0 ): void {
	printf( "[%7.1fs] %s (peak memory %.1f MB)\n", microtime( true ) - $t0, $m, memory_get_peak_usage( true ) / 1048576 );
};
$db = Db::connect( DB_HOST, DB_USER, DB_PASSWORD, DB_NAME );
$r  = Db::query( $db, 'SHOW TABLES' );
while ( $x = $r->fetch_row() ) {
	Db::query( $db, 'DROP TABLE ' . Db::ident( (string) $x[0] ) );
}
bbk_test_reset_options_table();
update_option( 'siteurl', 'https://big.example' );
update_option( 'home', 'https://big.example' );
bbk_test_login();
Settings::save( array_merge( Settings::defaults(), array( 'step_seconds' => 20 ) ) );
Storage::ensure();

// Files: a 4 GB "video", five 1 GB archives (already compressed, as media is), 50,000 small files.
$block = random_bytes( 1048576 );
$big   = static function ( string $p, int $mb ) use ( $block ): void {
	$h = fopen( $p, 'wb' );
	for ( $i = 0; $i < $mb; $i++ ) {
		fwrite( $h, $block ^ str_repeat( chr( $i % 251 ), 1048576 ) );
	}
	fclose( $h );
};
@mkdir( WP_CONTENT_DIR . '/uploads/video', 0777, true );
$big( WP_CONTENT_DIR . '/uploads/video/film.mp4', 4096 );
for ( $i = 1; $i <= 5; $i++ ) {
	$big( WP_CONTENT_DIR . "/uploads/archive-$i.zip", 1024 );
}
for ( $d = 0; $d < 100; $d++ ) {
	@mkdir( WP_CONTENT_DIR . "/uploads/small/$d", 0777, true );
	for ( $f = 0; $f < 500; $f++ ) {
		file_put_contents( WP_CONTENT_DIR . "/uploads/small/$d/f$f.txt", str_repeat( "line $d $f https://big.example/\n", 1 + ( $f % 40 ) ) );
	}
}
$log( 'Files made' );

// Database: about 1 GB in wp_posts (1 KB rows), doubled with INSERT … SELECT.
Db::query( $db, "CREATE TABLE wp_posts (ID bigint unsigned NOT NULL AUTO_INCREMENT, post_content longtext NOT NULL, guid varchar(255) NOT NULL DEFAULT '', PRIMARY KEY (ID)) DEFAULT CHARSET=utf8mb4" );
Db::query( $db, "INSERT INTO wp_posts (post_content, guid) VALUES (REPEAT('Post body about https://big.example/page ', 25), 'https://big.example/?p=1')" );
while ( (int) Db::query( $db, 'SELECT COUNT(*) FROM wp_posts' )->fetch_row()[0] < 1000000 ) {
	Db::query( $db, 'INSERT INTO wp_posts (post_content, guid) SELECT post_content, guid FROM wp_posts LIMIT 500000' );
}
$dbBefore = Db::query( $db, 'CHECKSUM TABLE wp_posts' )->fetch_row()[1];
$log( 'Database made: ' . Db::query( $db, 'SELECT COUNT(*) FROM wp_posts' )->fetch_row()[0] . ' rows' );

$sum = static function (): array {
	$out = array();
	$it  = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( WP_CONTENT_DIR . '/uploads', FilesystemIterator::SKIP_DOTS ) );
	foreach ( $it as $f ) {
		$out[ $f->getPathname() ] = hash_file( 'xxh128', $f->getPathname() );
	}
	ksort( $out );
	return $out;
};
$before = $sum();
$log( 'Checksums taken: ' . count( $before ) . ' files' );

$key   = Keys::make( 'bench' );
$drive = static function () use ( $log ): array {
	$n = 0;
	while ( true ) {
		$s = Jobs::run( microtime( true ) + 20 );
		++$n;
		if ( ! empty( $s['finished'] ) || ! empty( $s['waiting'] ) ) {
			$log( sprintf( '%s after %d steps: %s', $s['type'], $n, $s['error'] ?? $s['message'] ) );
			return $s;
		}
	}
};

Jobs::start( 'backup', array( 'db' => true, 'files' => true, 'dests' => array() ) );
$b    = $drive();
$name = (string) $b['result']['name'];
$log( 'Backup size ' . round( $b['result']['size'] / 1073741824, 2 ) . ' GB' );

Jobs::start( 'restore', array( 'source' => array( 'kind' => 'local', 'name' => $name ), 'verify_only' => true ), array( 'key' => Vault::seal( $key['text'] ) ) );
$drive();

// Change the site, then restore.
unlink( WP_CONTENT_DIR . '/uploads/video/film.mp4' );
file_put_contents( WP_CONTENT_DIR . '/uploads/small/7/f7.txt', 'changed' );
Db::query( $db, 'DELETE FROM wp_posts WHERE ID % 3 = 0' );
Jobs::start( 'restore', array( 'source' => array( 'kind' => 'local', 'name' => $name ), 'verify_only' => false ), array( 'key' => Vault::seal( $key['text'] ) ) );
$drive();
Jobs::resume( array( 'phase' => 'prepare', 'options' => array( 'db' => true, 'files' => true ) ) );
$drive();

$after = $sum();
$log( 'Files identical: ' . ( $before === $after ? 'YES' : 'NO (' . count( array_diff_assoc( $before, $after ) ) . ' differ)' ) );
$log( 'Database identical: ' . ( $dbBefore === Db::query( $db, 'CHECKSUM TABLE wp_posts' )->fetch_row()[1] ? 'YES' : 'NO' ) );
