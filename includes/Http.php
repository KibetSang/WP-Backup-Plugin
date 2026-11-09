<?php
/**
 * Thin wrapper around the WordPress HTTP API with TLS verification forced on,
 * retries with exponential backoff for 429/5xx and transient network errors.
 */

namespace BastionBackup;

use BastionBackup\Core\BackupException;

defined( 'ABSPATH' ) || exit;

final class Http {

	/** @return array{code:int, headers:array<string,string>, body:string} */
	public static function request( string $method, string $url, array $headers = array(), ?string $body = null, int $timeout = 120, int $tries = 4 ): array {
		if ( ! str_starts_with( $url, 'https://' ) ) {
			throw new BackupException( 'Refusing non-HTTPS request.' );
		}
		$args = array(
			'method'      => $method,
			'headers'     => $headers,
			'timeout'     => $timeout,
			'sslverify'   => true,
			'redirection' => 3,
			'user-agent'  => 'BastionBackup/' . BASTION_BACKUP_VERSION . '; WordPress',
		);
		if ( null !== $body ) {
			$args['body'] = $body;
		}
		$attempt = 0;
		while ( true ) {
			++$attempt;
			$res = wp_remote_request( $url, $args );
			if ( is_wp_error( $res ) ) {
				if ( $attempt >= $tries ) {
					throw new BackupException( 'HTTP error: ' . $res->get_error_message() );
				}
				sleep( min( 30, 2 ** $attempt ) );
				continue;
			}
			$code = (int) wp_remote_retrieve_response_code( $res );
			if ( ( 429 === $code || $code >= 500 ) && $attempt < $tries ) {
				$retry = (int) wp_remote_retrieve_header( $res, 'retry-after' );
				sleep( max( 1, min( 30, $retry ?: 2 ** $attempt ) ) );
				continue;
			}
			$hdrs = array();
			foreach ( (array) wp_remote_retrieve_headers( $res )->getAll() as $k => $v ) {
				$hdrs[ strtolower( (string) $k ) ] = is_array( $v ) ? (string) end( $v ) : (string) $v;
			}
			return array(
				'code'    => $code,
				'headers' => $hdrs,
				'body'    => (string) wp_remote_retrieve_body( $res ),
			);
		}
	}

	public static function json( array $res ): array {
		$d = json_decode( $res['body'], true );
		return is_array( $d ) ? $d : array();
	}

	public static function fail( string $what, array $res ): BackupException {
		$msg = substr( preg_replace( '/\s+/', ' ', wp_strip_all_tags( $res['body'] ) ), 0, 300 );
		return new BackupException( sprintf( '%s failed (HTTP %d): %s', $what, $res['code'], $msg ) );
	}
}
