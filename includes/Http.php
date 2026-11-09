<?php
/**
 * Thin wrapper around the WordPress HTTP API: HTTPS only, certificates always checked, bounded retries with backoff
 * that honours Retry-After (never sleeping past the step's deadline). Never logs tokens, keys or signed URLs.
 *
 * @package BastionBackup
 */

namespace BastionBackup;

use BastionBackup\Core\BackupException;

defined( 'ABSPATH' ) || exit;

final class Http {

	/**
	 * @param array<string,string> $headers
	 * @return array{code:int, headers:array<string,string>, body:string}
	 */
	public static function request( string $method, string $url, array $headers = array(), ?string $body = null, int $timeout = 120, int $tries = 4, int $redirects = 0, ?float $deadline = null ): array {
		if ( ! str_starts_with( $url, 'https://' ) ) {
			throw new BackupException( 'Refusing a request that is not over HTTPS.' );
		}
		$args = array(
			'method'      => $method,
			'headers'     => $headers,
			'timeout'     => $timeout,
			'sslverify'   => true,
			'redirection' => $redirects,
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
				if ( $attempt >= $tries || ! self::pause( 2 ** $attempt, $deadline ) ) {
					throw new BackupException( 'Network error: ' . $res->get_error_message() );
				}
				continue;
			}
			$code = (int) wp_remote_retrieve_response_code( $res );
			if ( ( 429 === $code || $code >= 500 ) && $attempt < $tries ) {
				$retry = (int) wp_remote_retrieve_header( $res, 'retry-after' );
				if ( self::pause( max( 1, $retry ?: 2 ** $attempt ), $deadline ) ) {
					continue;
				}
			}
			$hdrs = array();
			$raw  = wp_remote_retrieve_headers( $res );
			foreach ( ( is_object( $raw ) && method_exists( $raw, 'getAll' ) ? $raw->getAll() : (array) $raw ) as $k => $v ) {
				$hdrs[ strtolower( (string) $k ) ] = is_array( $v ) ? (string) end( $v ) : (string) $v;
			}
			return array(
				'code'    => $code,
				'headers' => $hdrs,
				'body'    => (string) wp_remote_retrieve_body( $res ),
			);
		}
	}

	/** Waits before a retry, unless that would run past the deadline (then the job simply resumes next step). */
	private static function pause( int $seconds, ?float $deadline ): bool {
		$seconds = min( 30, $seconds );
		if ( null !== $deadline && microtime( true ) + $seconds > $deadline ) {
			return false;
		}
		sleep( $seconds );
		return true;
	}

	/** @param array{body:string} $res */
	public static function json( array $res ): array {
		$d = json_decode( $res['body'], true );
		return is_array( $d ) ? $d : array();
	}

	/** @param array{code:int, body:string} $res */
	public static function fail( string $what, array $res ): BackupException {
		$d   = json_decode( $res['body'], true );
		$msg = is_array( $d ) ? (string) ( $d['error_description'] ?? ( $d['error']['message'] ?? ( is_string( $d['error'] ?? null ) ? $d['error'] : '' ) ) ) : '';
		if ( '' === $msg ) {
			$msg = substr( (string) preg_replace( '/\s+/', ' ', wp_strip_all_tags( $res['body'] ) ), 0, 200 );
		}
		return new BackupException( sprintf( '%s failed (HTTP %d)%s', $what, $res['code'], '' !== $msg ? ': ' . $msg : '' ) );
	}
}
