<?php
/**
 * Minimal mysqli helper shared by the plugin and the standalone installer.
 */

namespace BastionBackup\Core;

defined( 'ABSPATH' ) || defined( 'BBK_INSTALLER' ) || exit;

final class Db {

	/** Connect using WordPress-style DB_HOST ("host", "host:port", "host:/socket", "[::1]:3306"). */
	public static function connect( string $host, string $user, string $pass, string $name ): \mysqli {
		mysqli_report( MYSQLI_REPORT_OFF );
		$port   = null;
		$socket = null;
		if ( preg_match( '/^\[(.+)\](?::(\d+))?$/', $host, $m ) ) {
			$host = $m[1];
			$port = isset( $m[2] ) ? (int) $m[2] : null;
		} elseif ( substr_count( $host, ':' ) === 1 ) {
			[ $h, $rest ] = explode( ':', $host, 2 );
			$host         = '' === $h ? 'localhost' : $h;
			if ( ctype_digit( $rest ) ) {
				$port = (int) $rest;
			} else {
				$socket = $rest;
			}
		}
		$db = mysqli_init();
		if ( ! $db ) {
			throw new BackupException( 'mysqli_init failed.' );
		}
		$db->options( MYSQLI_OPT_CONNECT_TIMEOUT, 15 );
		if ( ! @$db->real_connect( $host, $user, $pass, $name, $port, $socket ) ) {
			throw new BackupException( 'Database connection failed: ' . mysqli_connect_error() );
		}
		$db->set_charset( 'utf8mb4' );
		return $db;
	}

	public static function query( \mysqli $db, string $sql ): \mysqli_result|bool {
		$res = $db->query( $sql );
		if ( false === $res ) {
			throw new BackupException( 'SQL error ' . $db->errno . ': ' . $db->error, $db->errno );
		}
		return $res;
	}

	public static function ident( string $name ): string {
		return '`' . str_replace( '`', '``', $name ) . '`';
	}

	public static function tableExists( \mysqli $db, string $table ): bool {
		$res = self::query( $db, "SHOW TABLES LIKE '" . $db->real_escape_string( addcslashes( $table, '%_\\' ) ) . "'" );
		$ok  = $res instanceof \mysqli_result && $res->num_rows > 0;
		if ( $res instanceof \mysqli_result ) {
			$res->free();
		}
		return $ok;
	}
}
