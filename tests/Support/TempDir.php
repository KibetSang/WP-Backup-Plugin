<?php

declare(strict_types=1);

namespace BastionBackup\Tests\Support;

/** A throwaway folder per test, removed afterwards. */
trait TempDir {

	protected string $tmp = '';

	protected function makeTemp(): string {
		$this->tmp = sys_get_temp_dir() . '/bbk-test-' . bin2hex( random_bytes( 5 ) );
		mkdir( $this->tmp, 0700, true );
		return $this->tmp;
	}

	protected function removeTemp(): void {
		if ( '' === $this->tmp || ! is_dir( $this->tmp ) ) {
			return;
		}
		$it = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $this->tmp, \FilesystemIterator::SKIP_DOTS ), \RecursiveIteratorIterator::CHILD_FIRST );
		foreach ( $it as $f ) {
			( $f->isDir() && ! $f->isLink() ) ? rmdir( $f->getPathname() ) : unlink( $f->getPathname() );
		}
		rmdir( $this->tmp );
	}
}
