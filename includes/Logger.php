<?php
/**
 * Per-job log file. Never pass secrets to this logger.
 */

namespace BastionBackup;

defined( 'ABSPATH' ) || exit;

final class Logger {

	public function __construct( private string $jobId ) {
	}

	public function path(): string {
		return Storage::dir( 'logs' ) . '/' . preg_replace( '/[^a-z0-9-]/', '', $this->jobId ) . '.log';
	}

	public function info( string $msg ): void {
		$this->line( 'INFO', $msg );
	}

	public function warn( string $msg ): void {
		$this->line( 'WARN', $msg );
	}

	public function error( string $msg ): void {
		$this->line( 'ERROR', $msg );
	}

	private function line( string $lvl, string $msg ): void {
		$msg = str_replace( array( "\r", "\n" ), ' ', $msg );
		@file_put_contents( $this->path(), gmdate( 'Y-m-d H:i:s' ) . " [$lvl] $msg\n", FILE_APPEND | LOCK_EX );
	}

	public function tail( int $lines = 40 ): array {
		$f = $this->path();
		if ( ! is_file( $f ) ) {
			return array();
		}
		$size = filesize( $f );
		$fh   = fopen( $f, 'rb' );
		fseek( $fh, max( 0, $size - 16384 ) );
		$data = (string) stream_get_contents( $fh );
		fclose( $fh );
		return array_slice( explode( "\n", trim( $data ) ), -$lines );
	}
}
