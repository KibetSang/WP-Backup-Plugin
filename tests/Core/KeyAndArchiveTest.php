<?php

declare(strict_types=1);

namespace BastionBackup\Tests\Core;

use BastionBackup\Core\ArchiveReader;
use BastionBackup\Core\ArchiveWriter;
use BastionBackup\Core\BackupException;
use BastionBackup\Core\Crypto;
use BastionBackup\Core\FileArchiver;
use BastionBackup\Core\FileScanner;
use BastionBackup\Core\Restorer;
use BastionBackup\Tests\Support\TempDir;
use PHPUnit\Framework\TestCase;

final class KeyAndArchiveTest extends TestCase {

	use TempDir;

	protected function setUp(): void {
		$this->makeTemp();
	}

	protected function tearDown(): void {
		$this->removeTemp();
	}

	public function test_the_key_reads_back_however_it_is_typed_and_a_mistake_is_caught(): void {
		$k = Crypto::newKeyPair();
		$this->assertMatchesRegularExpression( '/^BBK(-[A-Z2-7]{4}){14}$/', $k['text'] );
		$secret = Crypto::parseKey( $k['text'] );
		$this->assertSame( $k['public'], sodium_crypto_box_publickey_from_secretkey( $secret ) );
		// Lower case, spaces, line breaks, no prefix.
		$messy = strtolower( str_replace( '-', " \n", substr( $k['text'], 4 ) ) );
		$this->assertSame( $secret, Crypto::parseKey( $messy ) );
		// One character changed anywhere is caught by the check group.
		$bad    = $k['text'];
		$i      = 9;
		$bad[$i] = 'A' === $bad[$i] ? 'B' : 'A';
		$this->expectException( BackupException::class );
		$this->expectExceptionMessage( 'mistake' );
		Crypto::parseKey( $bad );
	}

	/** Files of every kind, with the backup cut into many short steps, come back byte for byte. */
	public function test_a_site_sealed_to_the_key_restores_byte_for_byte_with_steps_cut_mid_file(): void {
		$src = $this->tmp . '/src';
		mkdir( $src . '/wp-content/uploads/2026', 0777, true );
		mkdir( $src . '/empty-dir', 0777, true );
		file_put_contents( $src . '/index.php', '<?php echo 1;' );
		file_put_contents( $src . '/wp-content/uploads/2026/big.bin', random_bytes( 5 * 1048576 + 321 ) ); // > 2 frames.
		file_put_contents( $src . '/wp-content/text.txt', str_repeat( 'compressible line ', 300000 ) );
		file_put_contents( $src . '/wp-content/uploads/photo.jpg', random_bytes( 300000 ) );
		file_put_contents( $src . '/wp-content/uploads/skip.log', 'x' );
		// A name that is not UTF-8 (allowed on Linux hosts; macOS refuses to create one, so it is left out there).
		$latin = "caf\xe9.txt";
		$hasLatin = false !== @file_put_contents( $src . '/' . $latin, 'latin-1 name' );
		symlink( '/etc/hosts', $src . '/link-out' );

		$key  = Crypto::newKeyPair();
		$arch = $this->tmp . '/site.bbk';
		$w    = ArchiveWriter::createForKey( $arch, $key['public'], 'test' );
		$w->record( 'S', array( 'site_url' => 'https://old.example' ), 0 );
		$st = $w->export();
		$w->close();

		$scan  = new FileScanner( array( '' => $src ), array( '*.log' ) );
		$ss    = $scan->initState();
		$list  = $this->tmp . '/list';
		while ( ! $scan->step( $ss, $list, microtime( true ) + 0.001 ) ) {
			// Many tiny steps.
		}
		$this->assertSame( 1, $ss['skipped'], 'the symlink is skipped' );

		$fa    = new FileArchiver( array( '' => $src ) );
		$fs    = FileArchiver::initState();
		$steps = 0;
		do {
			$w    = ArchiveWriter::resume( $arch, $st );
			$done = $fa->step( $fs, $list, $w, microtime( true ) + 0.005 );
			$st   = $w->export();
			$w->close();
			++$steps;
		} while ( ! $done );
		$this->assertGreaterThan( 3, $steps, 'the big file was split across steps' );
		$w = ArchiveWriter::resume( $arch, $st );
		$w->record( 'X', array( 'files' => $fs['files'] ), 0 );
		$w->finish();

		// The server's public half cannot open it; only the key text can.
		$hdr = ArchiveReader::readHeader( $arch );
		$this->assertSame( 'x25519-seal', $hdr['kdf'] );
		$this->assertSame( $key['id'], $hdr['kid'] );

		$dst = $this->tmp . '/dst';
		mkdir( $dst );
		$rs = Restorer::initState();
		$r  = new Restorer( $arch, $key['text'], 'restore', null, static fn( string $p ): array => array( 'root' => $dst, 'rel' => $p ), false, true );
		$n  = 0;
		while ( ! $r->step( $rs, microtime( true ) + 0.005 ) ) {
			++$n;
		}
		$this->assertGreaterThan( 1, $n, 'the restore resumed mid-file too' );
		$this->assertSame( 'https://old.example', $rs['manifest']['site_url'] );
		foreach ( array_merge( array( 'index.php', 'wp-content/uploads/2026/big.bin', 'wp-content/text.txt', 'wp-content/uploads/photo.jpg' ), $hasLatin ? array( $latin ) : array() ) as $f ) {
			$this->assertFileEquals( $src . '/' . $f, $dst . '/' . $f, $f );
		}
		$this->assertDirectoryExists( $dst . '/empty-dir' );
		$this->assertFileDoesNotExist( $dst . '/link-out' );
		$this->assertFileDoesNotExist( $dst . '/wp-content/uploads/skip.log' );
		$this->assertSame( array(), glob( $dst . '/**/*.bbk-part' ) ?: array(), 'no half-written files left' );
	}

	public function test_another_key_or_a_mistyped_key_opens_nothing(): void {
		$key   = Crypto::newKeyPair();
		$other = Crypto::newKeyPair();
		$arch  = $this->smallArchive( $key['public'] );
		try {
			Restorer::manifest( $arch, $other['text'] );
			$this->fail( 'another key opened the backup' );
		} catch ( BackupException $e ) {
			$this->assertStringContainsString( 'does not open this backup', $e->getMessage() );
			$this->assertStringContainsString( $key['id'], $e->getMessage() );
		}
		$this->expectException( BackupException::class );
		Restorer::manifest( $arch, 'not a key at all' );
	}

	public function test_a_swapped_sealed_key_in_the_header_is_refused(): void {
		$key  = Crypto::newKeyPair();
		$arch = $this->smallArchive( $key['public'] );
		// Replace the sealed data key with one sealed for a different archive: the header binding must fail.
		$other = $this->smallArchive( $key['public'], 'b.bbk' );
		$a     = ArchiveReader::readHeader( $arch );
		$b     = ArchiveReader::readHeader( $other );
		$raw   = (string) file_get_contents( $arch );
		$raw   = str_replace( $a['wk'], $b['wk'], $raw );
		file_put_contents( $arch, $raw );
		$this->expectException( BackupException::class );
		$s = Restorer::initState();
		$v = new Restorer( $arch, $key['text'] );
		while ( ! $v->step( $s, microtime( true ) + 5 ) ) {
			// Verify everything.
		}
	}

	/** @return array<string, array{0: callable(string): void, 1: string}> */
	public static function damage(): array {
		return array(
			'one byte flipped' => array(
				static function ( string $f ): void {
					$h = fopen( $f, 'r+b' );
					fseek( $h, (int) ( filesize( $f ) / 2 ) );
					$c = fread( $h, 1 );
					fseek( $h, (int) ( filesize( $f ) / 2 ) );
					fwrite( $h, chr( ord( (string) $c ) ^ 1 ) );
					fclose( $h );
				},
				'Integrity check failed',
			),
			'cut short' => array(
				static function ( string $f ): void {
					$h = fopen( $f, 'r+b' );
					ftruncate( $h, (int) filesize( $f ) - 50 );
					fclose( $h );
				},
				'truncated',
			),
			'bytes added after the end' => array(
				static fn( string $f ) => file_put_contents( $f, 'extra', FILE_APPEND ),
				'',
			),
			'last frame removed' => array(
				static function ( string $f ): void {
					// Walk the frames and drop the final one: a clean cut on a frame boundary.
					$h = fopen( $f, 'r+b' );
					fseek( $h, 8 );
					$hl = unpack( 'N', (string) fread( $h, 4 ) )[1];
					fseek( $h, 12 + $hl );
					$last = 0;
					while ( ! feof( $h ) ) {
						$pos = ftell( $h );
						$lb  = fread( $h, 4 );
						if ( 4 !== strlen( (string) $lb ) ) {
							break;
						}
						$last = $pos;
						fseek( $h, unpack( 'N', $lb )[1], SEEK_CUR );
					}
					ftruncate( $h, $last );
					fclose( $h );
				},
				'truncated',
			),
		);
	}

	/** @dataProvider damage */
	#[\PHPUnit\Framework\Attributes\DataProvider( 'damage' )]
	public function test_a_damaged_archive_never_restores_anything( callable $harm, string $says ): void {
		$key  = Crypto::newKeyPair();
		$arch = $this->smallArchive( $key['public'], 'a.bbk', 3 * 1048576 );
		$harm( $arch );
		$dst = $this->tmp . '/dst';
		mkdir( $dst );
		try {
			$s = Restorer::initState();
			$v = new Restorer( $arch, $key['text'] );
			while ( ! $v->step( $s, microtime( true ) + 5 ) ) {
				// Verify only.
			}
			$this->fail( 'damage went unnoticed' );
		} catch ( BackupException $e ) {
			if ( '' !== $says ) {
				$this->assertStringContainsString( $says, $e->getMessage() );
			}
		}
		$this->assertSame( array( '.', '..' ), scandir( $dst ) );
	}

	public function test_paths_that_leave_the_site_are_refused(): void {
		$key = Crypto::newKeyPair();
		foreach ( array( '../../etc/x', '/etc/passwd', 'C:/Windows/x', "a\0b", 'wp-content/../../x' ) as $bad ) {
			$arch = $this->tmp . '/evil-' . md5( $bad ) . '.bbk';
			$w    = ArchiveWriter::createForKey( $arch, $key['public'] );
			$w->record( 'F', array( 'p' => $bad ), 1 );
			$w->write( 'a' );
			$w->finish();
			$dst = $this->tmp . '/d-' . md5( $bad );
			mkdir( $dst );
			try {
				$s = Restorer::initState();
				$r = new Restorer( $arch, $key['text'], 'restore', null, static fn( string $p ): array => array( 'root' => $dst, 'rel' => $p ), false, true );
				while ( ! $r->step( $s, microtime( true ) + 5 ) ) {
					// Restore.
				}
				$this->fail( 'accepted ' . $bad );
			} catch ( BackupException $e ) {
				$this->assertStringContainsString( 'Rejected', $e->getMessage() );
			}
		}
	}

	public function test_a_symlinked_folder_inside_the_target_cannot_lead_outside(): void {
		$key     = Crypto::newKeyPair();
		$outside = $this->tmp . '/outside';
		$dst     = $this->tmp . '/dst';
		mkdir( $outside );
		mkdir( $dst );
		symlink( $outside, $dst . '/uploads' );
		$arch = $this->tmp . '/a.bbk';
		$w    = ArchiveWriter::createForKey( $arch, $key['public'] );
		$w->record( 'F', array( 'p' => 'uploads/shell.php' ), 4 );
		$w->write( 'evil' );
		$w->finish();
		try {
			$s = Restorer::initState();
			$r = new Restorer( $arch, $key['text'], 'restore', null, static fn( string $p ): array => array( 'root' => $dst, 'rel' => $p ), false, true );
			while ( ! $r->step( $s, microtime( true ) + 5 ) ) {
				// Restore.
			}
			$this->fail( 'wrote through the symlink' );
		} catch ( BackupException $e ) {
			$this->assertStringContainsString( 'outside', $e->getMessage() );
		}
		$this->assertFileDoesNotExist( $outside . '/shell.php' );
	}

	public function test_crafted_headers_that_would_exhaust_the_server_are_refused(): void {
		$arch = $this->tmp . '/x.bbk';
		$json = (string) json_encode( array( 'v' => 1, 'kdf' => 'argon2id13', 'ops' => 99, 'mem' => 1 << 40, 'salt' => base64_encode( random_bytes( 16 ) ), 'wn' => base64_encode( random_bytes( 24 ) ), 'wk' => 'AA==', 'ss' => 'AA==' ) );
		file_put_contents( $arch, ArchiveWriter::MAGIC . pack( 'N', strlen( $json ) ) . $json );
		try {
			ArchiveReader::open( $arch, 'some passphrase here' );
			$this->fail( 'accepted out-of-range KDF' );
		} catch ( BackupException $e ) {
			$this->assertStringContainsString( 'out-of-range', $e->getMessage() );
		}
		file_put_contents( $arch, ArchiveWriter::MAGIC . pack( 'N', 70000 ) . str_repeat( ' ', 70000 ) );
		$this->expectException( BackupException::class );
		ArchiveReader::readHeader( $arch );
	}

	public function test_the_passphrase_mode_still_round_trips(): void {
		if ( ! Crypto::native() ) {
			$this->markTestSkipped( 'Passphrase archives need Argon2, which WordPress\'s pure-PHP sodium_compat does not offer (the plugin itself uses the backup key).' );
		}
		$arch = $this->tmp . '/p.bbk';
		$w    = ArchiveWriter::create( $arch, 'correct horse battery staple' );
		$w->record( 'S', array( 'a' => 1 ), 0 );
		$w->finish();
		$this->assertSame( array( 'a' => 1 ), Restorer::manifest( $arch, 'correct horse battery staple' ) );
		$this->expectException( BackupException::class );
		Restorer::manifest( $arch, 'wrong horse battery staple' );
	}

	private function smallArchive( string $public, string $name = 'a.bbk', int $bytes = 1000 ): string {
		$arch = $this->tmp . '/' . $name;
		$w    = ArchiveWriter::createForKey( $arch, $public );
		$w->record( 'S', array( 'site_url' => 'https://x.example' ), 0 );
		$w->setCompress( false );
		$w->record( 'F', array( 'p' => 'f.bin' ), $bytes );
		$w->write( random_bytes( $bytes ) );
		$w->finish();
		return $arch;
	}
}
