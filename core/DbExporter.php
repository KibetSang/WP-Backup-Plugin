<?php
/**
 * Resumable database exporter. Writes one 'T' record (CREATE TABLE) per table and
 * 'Q' records (multi-row INSERT, ≤ ~1 MiB each). Table names are replaced with a
 * placeholder so the importer can load into temporary tables and swap atomically.
 * Uses keyset pagination on integer primary keys — fast even on multi-GB tables.
 */

namespace BastionBackup\Core;

defined( 'ABSPATH' ) || defined( 'BBK_INSTALLER' ) || exit;

final class DbExporter {

	public const PH          = '__BBK_T__';
	private const MAX_STMT   = 1048576;
	private const BATCH_MEM  = 8388608; // Target bytes per SELECT batch.
	private const NUMERIC    = array( MYSQLI_TYPE_TINY, MYSQLI_TYPE_SHORT, MYSQLI_TYPE_LONG, MYSQLI_TYPE_LONGLONG, MYSQLI_TYPE_INT24, MYSQLI_TYPE_FLOAT, MYSQLI_TYPE_DOUBLE, MYSQLI_TYPE_DECIMAL, MYSQLI_TYPE_NEWDECIMAL, MYSQLI_TYPE_YEAR );

	public function __construct( private \mysqli $db ) {
	}

	/** @return string[] */
	public function listTables( string $prefix, bool $all, array $exclude = array() ): array {
		$res    = Db::query( $this->db, "SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'" );
		$tables = array();
		while ( $row = $res->fetch_row() ) {
			$t = (string) $row[0];
			if ( ( $all || str_starts_with( $t, $prefix ) ) && ! in_array( $t, $exclude, true ) ) {
				$tables[] = $t;
			}
		}
		$res->free();
		sort( $tables );
		return $tables;
	}

	public static function initState( array $tables ): array {
		return array(
			'tables' => array_values( $tables ),
			'i'      => 0,
			'phase'  => 'create',
			'rows'   => 0,
		);
	}

	public function step( array &$st, ArchiveWriter $w, float $deadline ): bool {
		Db::query( $this->db, "SET SESSION sql_mode = ''" );
		while ( $st['i'] < count( $st['tables'] ) ) {
			$t = $st['tables'][ $st['i'] ];
			if ( 'create' === $st['phase'] ) {
				$this->writeCreate( $t, $w );
				$st['cur']   = $this->tableInfo( $t );
				$st['phase'] = 'rows';
			}
			if ( ! $this->dumpRows( $t, $st, $w, $deadline ) ) {
				return false;
			}
			++$st['i'];
			$st['phase'] = 'create';
			unset( $st['cur'] );
			if ( microtime( true ) > $deadline ) {
				return $st['i'] >= count( $st['tables'] );
			}
		}
		return true;
	}

	private function writeCreate( string $t, ArchiveWriter $w ): void {
		$res = Db::query( $this->db, 'SHOW CREATE TABLE ' . Db::ident( $t ) );
		$row = $res->fetch_row();
		$res->free();
		$sql = preg_replace( '/^CREATE TABLE `(?:[^`]|``)*`/', 'CREATE TABLE `' . self::PH . '`', (string) $row[1], 1, $n );
		if ( 1 !== $n ) {
			throw new BackupException( 'Unexpected CREATE TABLE format for ' . $t );
		}
		$w->record( 'T', array( 't' => $t ), strlen( $sql ) );
		$w->write( $sql );
	}

	private function tableInfo( string $t ): array {
		$cols = array();
		$bits = array();
		$res  = Db::query( $this->db, 'SHOW COLUMNS FROM ' . Db::ident( $t ) );
		while ( $c = $res->fetch_assoc() ) {
			if ( ! str_contains( strtoupper( (string) $c['Extra'] ), 'GENERATED' ) ) {
				$cols[] = $c['Field'];
				// BIT values come back from the driver as raw bytes or as decimal text depending on the client
				// library; reading them as numbers (col+0) makes the dump the same everywhere.
				if ( str_starts_with( strtolower( (string) $c['Type'] ), 'bit' ) ) {
					$bits[] = $c['Field'];
				}
			}
		}
		$res->free();

		$pk   = null;
		$keys = array();
		$res  = Db::query( $this->db, 'SHOW KEYS FROM ' . Db::ident( $t ) . " WHERE Key_name = 'PRIMARY'" );
		while ( $k = $res->fetch_assoc() ) {
			$keys[] = $k['Column_name'];
		}
		$res->free();
		if ( 1 === count( $keys ) ) {
			$res = Db::query( $this->db, 'SHOW COLUMNS FROM ' . Db::ident( $t ) . " LIKE '" . $this->db->real_escape_string( addcslashes( $keys[0], '%_\\' ) ) . "'" );
			$c   = $res->fetch_assoc();
			$res->free();
			if ( $c && preg_match( '/int/i', (string) $c['Type'] ) && in_array( $keys[0], $cols, true ) ) {
				$pk = $keys[0];
			}
		}

		$avg = 1024;
		$res = Db::query( $this->db, "SHOW TABLE STATUS LIKE '" . $this->db->real_escape_string( addcslashes( $t, '%_\\' ) ) . "'" );
		if ( $s = $res->fetch_assoc() ) {
			$avg = max( 64, (int) $s['Avg_row_length'] );
		}
		$res->free();

		return array(
			'cols'   => $cols,
			'bits'   => $bits,
			'pk'     => $pk,
			'last'   => null,
			'offset' => 0,
			'batch'  => max( 10, min( 5000, intdiv( self::BATCH_MEM, $avg ) ) ),
		);
	}

	private function dumpRows( string $t, array &$st, ArchiveWriter $w, float $deadline ): bool {
		$info = &$st['cur'];
		if ( ! $info['cols'] ) {
			return true;
		}
		$colSql = implode( ',', array_map( array( Db::class, 'ident' ), $info['cols'] ) );
		$bits   = $info['bits'] ?? array();
		$selSql = implode( ',', array_map( static fn( string $c ): string => in_array( $c, $bits, true ) ? '(' . Db::ident( $c ) . '+0) AS ' . Db::ident( $c ) : Db::ident( $c ), $info['cols'] ) );
		$head   = 'INSERT INTO `' . self::PH . '` (' . $colSql . ') VALUES ';
		$pkIdx  = null === $info['pk'] ? null : array_search( $info['pk'], $info['cols'], true );

		while ( true ) {
			$sql = 'SELECT ' . $selSql . ' FROM ' . Db::ident( $t );
			if ( null !== $info['pk'] ) {
				if ( null !== $info['last'] ) {
					$sql .= ' WHERE ' . Db::ident( $info['pk'] ) . " > '" . $this->db->real_escape_string( (string) $info['last'] ) . "'";
				}
				$sql .= ' ORDER BY ' . Db::ident( $info['pk'] ) . ' LIMIT ' . (int) $info['batch'];
			} else {
				$sql .= ' LIMIT ' . (int) $info['offset'] . ', ' . (int) $info['batch'];
			}
			$res    = Db::query( $this->db, $sql );
			$fields = $res->fetch_fields();
			$n      = 0;
			$vals   = array();
			$size   = 0;
			while ( $row = $res->fetch_row() ) {
				++$n;
				$enc = array();
				foreach ( $row as $i => $v ) {
					$enc[] = $this->literal( $v, $fields[ $i ] );
				}
				$tuple  = '(' . implode( ',', $enc ) . ')';
				$vals[] = $tuple;
				$size  += strlen( $tuple ) + 1;
				if ( null !== $pkIdx ) {
					$info['last'] = $row[ $pkIdx ];
				}
				if ( $size >= self::MAX_STMT ) {
					$this->emit( $t, $head, $vals, $w );
					$vals = array();
					$size = 0;
				}
			}
			$res->free();
			if ( $vals ) {
				$this->emit( $t, $head, $vals, $w );
			}
			$info['offset'] += $n;
			$st['rows']     += $n;
			if ( $n < $info['batch'] ) {
				return true;
			}
			if ( microtime( true ) > $deadline ) {
				return false;
			}
		}
	}

	private function emit( string $t, string $head, array $vals, ArchiveWriter $w ): void {
		$sql = $head . implode( ',', $vals );
		$w->record( 'Q', array( 't' => $t ), strlen( $sql ) );
		$w->write( $sql );
	}

	private function literal( ?string $v, object $f ): string {
		if ( null === $v ) {
			return 'NULL';
		}
		if ( in_array( $f->type, self::NUMERIC, true ) && is_numeric( $v ) ) {
			return $v;
		}
		$binTypes = array( MYSQLI_TYPE_BLOB, MYSQLI_TYPE_TINY_BLOB, MYSQLI_TYPE_MEDIUM_BLOB, MYSQLI_TYPE_LONG_BLOB, MYSQLI_TYPE_STRING, MYSQLI_TYPE_VAR_STRING, MYSQLI_TYPE_GEOMETRY );
		if ( MYSQLI_TYPE_BIT === $f->type || ( 63 === (int) $f->charsetnr && in_array( $f->type, $binTypes, true ) ) ) {
			return '' === $v ? "''" : '0x' . bin2hex( $v );
		}
		return "'" . $this->db->real_escape_string( $v ) . "'";
	}
}
