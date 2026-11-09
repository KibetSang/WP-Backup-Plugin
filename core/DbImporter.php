<?php
/**
 * Imports archive SQL into temporary tables, runs search/replace on them, then swaps
 * them in with one atomic RENAME TABLE. If anything fails before the swap the live
 * site is untouched.
 *
 * Only statements the exporter produces are accepted (CREATE TABLE / INSERT INTO the
 * placeholder table), and mysqli::query() never runs multiple statements.
 */

namespace BastionBackup\Core;

defined( 'ABSPATH' ) || defined( 'BBK_INSTALLER' ) || exit;

final class DbImporter {

	public function __construct(
		private \mysqli $db,
		private string $srcPrefix,
		private string $dstPrefix,
	) {
		if ( ! preg_match( '/^[A-Za-z0-9_]+$/', $dstPrefix ) ) {
			throw new BackupException( 'Invalid table prefix.' );
		}
	}

	public function session(): void {
		Db::query( $this->db, "SET SESSION sql_mode = 'NO_AUTO_VALUE_ON_ZERO'" );
		Db::query( $this->db, 'SET SESSION foreign_key_checks = 0' );
		Db::query( $this->db, 'SET SESSION unique_checks = 0' );
	}

	public function finalName( string $src ): string {
		if ( $this->srcPrefix !== $this->dstPrefix && str_starts_with( $src, $this->srcPrefix ) ) {
			return $this->dstPrefix . substr( $src, strlen( $this->srcPrefix ) );
		}
		return $src;
	}

	public static function tmpName( string $final ): string {
		return substr( 'bbkt' . substr( md5( $final ), 0, 6 ) . '_' . $final, 0, 64 );
	}

	public static function oldName( string $final ): string {
		return substr( 'bbko' . substr( md5( $final ), 0, 6 ) . '_' . $final, 0, 64 );
	}

	public function createTable( string $src, string $sql ): void {
		$head = 'CREATE TABLE `' . DbExporter::PH . '`';
		if ( ! str_starts_with( $sql, $head ) ) {
			throw new BackupException( 'Rejected unexpected SQL in archive.' );
		}
		$tmp = self::tmpName( $this->finalName( $src ) );
		Db::query( $this->db, 'DROP TABLE IF EXISTS ' . Db::ident( $tmp ) );
		$sql = 'CREATE TABLE ' . Db::ident( $tmp ) . substr( $sql, strlen( $head ) );
		try {
			Db::query( $this->db, $sql );
		} catch ( BackupException $e ) {
			if ( 1273 !== $e->getCode() ) { // Unknown collation (e.g. MySQL 8 dump → MariaDB).
				throw $e;
			}
			$sql = str_replace( array( 'utf8mb4_0900_ai_ci', 'utf8mb4_0900_as_cs', 'utf8mb4_0900_bin' ), array( 'utf8mb4_unicode_520_ci', 'utf8mb4_unicode_520_ci', 'utf8mb4_bin' ), $sql );
			Db::query( $this->db, $sql );
		}
	}

	public function insert( string $src, string $sql ): void {
		$head = 'INSERT INTO `' . DbExporter::PH . '`';
		if ( ! str_starts_with( $sql, $head ) ) {
			throw new BackupException( 'Rejected unexpected SQL in archive.' );
		}
		Db::query( $this->db, 'INSERT INTO ' . Db::ident( self::tmpName( $this->finalName( $src ) ) ) . substr( $sql, strlen( $head ) ) );
	}

	/**
	 * Resumable serialization-safe search/replace over the temporary tables.
	 *
	 * @param string[] $srcTables
	 */
	public function replaceInit( array $srcTables ): array {
		return array(
			'tables' => array_values( $srcTables ),
			'i'      => 0,
			'last'   => null,
			'info'   => null,
			'rows'   => 0,
		);
	}

	public function replaceStep( array &$st, array $pairs, float $deadline ): bool {
		if ( ! $pairs ) {
			return true;
		}
		while ( $st['i'] < count( $st['tables'] ) ) {
			$final = $this->finalName( $st['tables'][ $st['i'] ] );
			$tmp   = self::tmpName( $final );
			if ( null === $st['info'] ) {
				$st['info'] = $this->replaceInfo( $tmp, $final );
				$st['last'] = null;
			}
			$info = $st['info'];
			if ( ! $info['pk'] || ! $info['cols'] ) {
				$st['i']++;
				$st['info'] = null;
				continue;
			}
			$like = array();
			foreach ( $info['cols'] as $c ) {
				foreach ( array_keys( $pairs ) as $from ) {
					$like[] = Db::ident( $c ) . " LIKE '%" . $this->db->real_escape_string( addcslashes( (string) $from, '%_\\' ) ) . "%'";
				}
			}
			$sql = 'SELECT ' . Db::ident( $info['pk'] ) . ',' . implode( ',', array_map( array( Db::class, 'ident' ), $info['cols'] ) )
				. ' FROM ' . Db::ident( $tmp ) . ' WHERE (' . implode( ' OR ', $like ) . ')';
			if ( null !== $st['last'] ) {
				$sql .= ' AND ' . Db::ident( $info['pk'] ) . " > '" . $this->db->real_escape_string( (string) $st['last'] ) . "'";
			}
			$sql .= ' ORDER BY ' . Db::ident( $info['pk'] ) . ' LIMIT 200';
			$res  = Db::query( $this->db, $sql );
			$rows = $res->fetch_all( MYSQLI_NUM );
			$res->free();
			foreach ( $rows as $row ) {
				$id  = array_shift( $row );
				$set = array();
				foreach ( $info['cols'] as $i => $c ) {
					$new = SearchReplace::run( $row[ $i ], $pairs );
					if ( $new !== $row[ $i ] ) {
						$set[] = Db::ident( $c ) . " = '" . $this->db->real_escape_string( (string) $new ) . "'";
					}
				}
				if ( $set ) {
					Db::query( $this->db, 'UPDATE ' . Db::ident( $tmp ) . ' SET ' . implode( ',', $set ) . ' WHERE ' . Db::ident( $info['pk'] ) . " = '" . $this->db->real_escape_string( (string) $id ) . "'" );
					++$st['rows'];
				}
				$st['last'] = $id;
			}
			if ( count( $rows ) < 200 ) {
				$st['i']++;
				$st['info'] = null;
			}
			if ( microtime( true ) > $deadline ) {
				return $st['i'] >= count( $st['tables'] );
			}
		}
		return true;
	}

	private function replaceInfo( string $tmp, string $final ): array {
		$cols = array();
		$pk   = array();
		$res  = Db::query( $this->db, 'SHOW COLUMNS FROM ' . Db::ident( $tmp ) );
		while ( $c = $res->fetch_assoc() ) {
			if ( 'PRI' === $c['Key'] ) {
				$pk[] = $c['Field'];
			}
			$isText = (bool) preg_match( '/^(var)?char|text|json|enum|set/i', (string) $c['Type'] );
			$isGen  = str_contains( strtoupper( (string) $c['Extra'] ), 'GENERATED' );
			// WordPress guidance: never rewrite post GUIDs.
			$isGuid = 'guid' === $c['Field'] && str_ends_with( $final, 'posts' );
			if ( $isText && ! $isGen && ! $isGuid && 'PRI' !== $c['Key'] ) {
				$cols[] = $c['Field'];
			}
		}
		$res->free();
		return array(
			'pk'   => 1 === count( $pk ) ? $pk[0] : null,
			'cols' => $cols,
		);
	}

	/**
	 * Atomically swap temp tables into place. Returns names of displaced tables to drop.
	 *
	 * @param string[] $srcTables
	 * @return string[]
	 */
	public function swap( array $srcTables ): array {
		$renames = array();
		$old     = array();
		foreach ( $srcTables as $src ) {
			$final = $this->finalName( $src );
			$tmp   = self::tmpName( $final );
			if ( ! Db::tableExists( $this->db, $tmp ) ) {
				throw new BackupException( 'Temporary table missing for ' . $final . '; aborting before swap.' );
			}
			if ( Db::tableExists( $this->db, $final ) ) {
				$o = self::oldName( $final );
				Db::query( $this->db, 'DROP TABLE IF EXISTS ' . Db::ident( $o ) );
				$renames[] = Db::ident( $final ) . ' TO ' . Db::ident( $o );
				$old[]     = $o;
			}
			$renames[] = Db::ident( $tmp ) . ' TO ' . Db::ident( $final );
		}
		if ( $renames ) {
			Db::query( $this->db, 'RENAME TABLE ' . implode( ', ', $renames ) );
		}
		$this->fixPrefix();
		return $old;
	}

	/** Rename prefix-bound option/usermeta keys when the table prefix changed. */
	private function fixPrefix(): void {
		if ( $this->srcPrefix === $this->dstPrefix ) {
			return;
		}
		$src  = $this->db->real_escape_string( $this->srcPrefix );
		$dst  = $this->db->real_escape_string( $this->dstPrefix );
		$like = $this->db->real_escape_string( addcslashes( $this->srcPrefix, '%_\\' ) );
		$len  = strlen( $this->srcPrefix ) + 1;
		$opt  = Db::ident( $this->dstPrefix . 'options' );
		$um   = Db::ident( $this->dstPrefix . 'usermeta' );
		if ( Db::tableExists( $this->db, $this->dstPrefix . 'options' ) ) {
			Db::query( $this->db, "UPDATE $opt SET option_name = CONCAT('$dst', SUBSTRING(option_name, $len)) WHERE option_name = '{$src}user_roles'" );
		}
		if ( Db::tableExists( $this->db, $this->dstPrefix . 'usermeta' ) ) {
			Db::query( $this->db, "UPDATE $um SET meta_key = CONCAT('$dst', SUBSTRING(meta_key, $len)) WHERE meta_key LIKE '{$like}%'" );
		}
	}

	public function dropTables( array $tables ): void {
		foreach ( $tables as $t ) {
			Db::query( $this->db, 'DROP TABLE IF EXISTS ' . Db::ident( $t ) );
		}
	}

	/** Remove leftover temp tables from an aborted restore. */
	public function dropTemp( array $srcTables ): void {
		foreach ( $srcTables as $src ) {
			Db::query( $this->db, 'DROP TABLE IF EXISTS ' . Db::ident( self::tmpName( $this->finalName( $src ) ) ) );
		}
	}
}
