<?php
/**
 * Serialization-safe search/replace for site migration (domain / path changes).
 * unserialize() is restricted to stdClass so a crafted row can never instantiate
 * arbitrary classes (PHP object injection). Values containing other objects are
 * left untouched rather than risk corrupting them.
 */

namespace BastionBackup\Core;

defined( 'ABSPATH' ) || defined( 'BBK_INSTALLER' ) || exit;

final class SearchReplace {

	/**
	 * Expand from=>to pairs with JSON-escaped variants and sort longest first.
	 *
	 * @param array<string,string> $pairs
	 * @return array<string,string>
	 */
	public static function expand( array $pairs ): array {
		$out = array();
		foreach ( $pairs as $from => $to ) {
			$from = (string) $from;
			$to   = (string) $to;
			if ( '' === $from || $from === $to ) {
				continue;
			}
			$out[ $from ] = $to;
			$jf           = str_replace( '/', '\\/', $from );
			if ( $jf !== $from ) {
				$out[ $jf ] = str_replace( '/', '\\/', $to );
			}
		}
		uksort( $out, static fn( $a, $b ) => strlen( (string) $b ) <=> strlen( (string) $a ) );
		return $out;
	}

	public static function run( mixed $value, array $pairs ): mixed {
		if ( ! is_string( $value ) || '' === $value || ! self::containsAny( $value, $pairs ) ) {
			return $value;
		}
		if ( self::isSerialized( $value ) ) {
			$data = @unserialize( $value, array( 'allowed_classes' => array( 'stdClass' ) ) );
			if ( false !== $data || 'b:0;' === $value ) {
				$ok  = true;
				$new = self::walk( $data, $pairs, $ok );
				return $ok ? serialize( $new ) : $value;
			}
		}
		return str_replace( array_keys( $pairs ), array_values( $pairs ), $value );
	}

	private static function walk( mixed $data, array $pairs, bool &$ok ): mixed {
		if ( is_string( $data ) ) {
			return self::run( $data, $pairs ); // Handles double-serialized values.
		}
		if ( is_array( $data ) ) {
			foreach ( $data as $k => $v ) {
				$data[ $k ] = self::walk( $v, $pairs, $ok );
			}
			return $data;
		}
		if ( $data instanceof \stdClass ) {
			foreach ( get_object_vars( $data ) as $k => $v ) {
				$data->$k = self::walk( $v, $pairs, $ok );
			}
			return $data;
		}
		if ( is_object( $data ) ) {
			$ok = false; // __PHP_Incomplete_Class: cannot be modified safely.
		}
		return $data;
	}

	public static function containsAny( string $v, array $pairs ): bool {
		foreach ( $pairs as $from => $_ ) {
			if ( str_contains( $v, (string) $from ) ) {
				return true;
			}
		}
		return false;
	}

	public static function isSerialized( string $v ): bool {
		$v = trim( $v );
		if ( 'N;' === $v ) {
			return true;
		}
		if ( strlen( $v ) < 4 || ':' !== $v[1] ) {
			return false;
		}
		$last = substr( $v, -1 );
		if ( ';' !== $last && '}' !== $last ) {
			return false;
		}
		return (bool) preg_match( '/^[aOsbidC]:/', $v );
	}
}
