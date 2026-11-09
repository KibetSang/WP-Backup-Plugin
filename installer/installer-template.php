<?php
/**
 * Bastion Backup installer (the part after the bundled core classes). Inert when requested directly from the plugin
 * folder: without BBK_INSTALLER, defined only in a built installer.php, it answers 404.
 *
 * Disaster recovery and moving to a new server, like Duplicator's installer.php:
 *   access key → choose the .bbk in this folder → backup key + new database → Check (every byte, nothing written)
 *   → Restore (files, database into temporary tables, new address, one RENAME TABLE swap, wp-config.php) → delete
 *   itself, its working folder and (if asked) the backup file.
 * The backup key and the database password stay in the browser and come with each step; only the archive reader's
 * position is kept between steps, in a random working folder with deny rules, deleted at the end.
 *
 * @package BastionBackup
 */

if ( ! defined( 'BBK_INSTALLER' ) || ! defined( 'BBK_ACCESS_HASH' ) ) {
	http_response_code( 404 );
	exit;
}

error_reporting( E_ALL );
ini_set( 'display_errors', '0' );

final class BbkInstaller {

	private const MAX_FAILS = 10;

	private string $dir;
	private string $work;

	public function __construct() {
		$this->dir  = rtrim( str_replace( '\\', '/', __DIR__ ), '/' );
		$this->work = $this->dir . '/.bbk-work-' . substr( hash( 'sha256', BBK_ACCESS_HASH . __FILE__ ), 0, 20 );
	}

	public function run(): void {
		header( 'X-Frame-Options: DENY' );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'Referrer-Policy: no-referrer' );
		header( 'Cache-Control: no-store' );
		header( "Content-Security-Policy: default-src 'none'; script-src 'unsafe-inline'; style-src 'unsafe-inline'; connect-src 'self'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'" );
		session_name( 'bbkinst' );
		session_set_cookie_params( array( 'lifetime' => 0, 'path' => '/', 'secure' => $this->https(), 'httponly' => true, 'samesite' => 'Strict' ) );
		session_start();
		if ( empty( $_SESSION['csrf'] ) ) {
			$_SESSION['csrf'] = bin2hex( random_bytes( 16 ) );
		}
		if ( 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? 'GET' ) ) {
			$this->post();
			return;
		}
		$this->page();
	}

	private function https(): bool {
		return ( ! empty( $_SERVER['HTTPS'] ) && 'off' !== strtolower( (string) $_SERVER['HTTPS'] ) ) || 443 === (int) ( $_SERVER['SERVER_PORT'] ?? 0 );
	}

	private function prepareWork(): void {
		if ( ! is_dir( $this->work ) && ! mkdir( $this->work, 0700, true ) ) {
			throw new \BastionBackup\Core\BackupException( 'Cannot make the working folder: check that this folder is writable.' );
		}
		foreach ( array(
			'.htaccess'  => "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nOrder allow,deny\nDeny from all\n</IfModule>\n",
			'web.config' => '<?xml version="1.0"?><configuration><system.webServer><authorization><deny users="*" /></authorization></system.webServer></configuration>',
			'index.php'  => "<?php http_response_code(404);\n",
		) as $f => $c ) {
			if ( ! is_file( $this->work . '/' . $f ) ) {
				file_put_contents( $this->work . '/' . $f, $c );
			}
		}
	}

	private function fails(): int {
		$f = $this->work . '/.fails';
		return is_file( $f ) ? (int) file_get_contents( $f ) : 0;
	}

	private function json( array $d, int $code = 200 ): void {
		http_response_code( $code );
		header( 'Content-Type: application/json' );
		echo json_encode( $d, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
	}

	private function in( string $k ): string {
		return isset( $_POST[ $k ] ) && is_string( $_POST[ $k ] ) ? $_POST[ $k ] : '';
	}

	private function post(): void {
		try {
			if ( ! hash_equals( (string) $_SESSION['csrf'], $this->in( 'csrf' ) ) ) {
				throw new \BastionBackup\Core\BackupException( 'This page has expired. Reload it.' );
			}
			$this->prepareWork();
			$a = $this->in( 'action' );
			if ( 'login' === $a ) {
				$this->login();
				return;
			}
			if ( empty( $_SESSION['ok'] ) ) {
				throw new \BastionBackup\Core\BackupException( 'Enter the access key first.' );
			}
			match ( $a ) {
				'dbtest' => $this->json( array( 'ok' => true, 'message' => $this->dbTest() ) ),
				'start'  => $this->json( $this->start() ),
				'step'   => $this->json( $this->step() ),
				'finish' => $this->json( $this->finish() ),
				default  => throw new \BastionBackup\Core\BackupException( 'Unknown action.' ),
			};
		} catch ( \Throwable $e ) {
			$this->json( array( 'ok' => false, 'error' => $e instanceof \BastionBackup\Core\BackupException ? $e->getMessage() : 'Unexpected error: ' . $e->getMessage() ), 400 );
		}
	}

	private function login(): void {
		if ( $this->fails() >= self::MAX_FAILS ) {
			throw new \BastionBackup\Core\BackupException( 'Locked after too many wrong access keys. Upload a new installer.' );
		}
		$given = strtoupper( trim( $this->in( 'access' ) ) );
		if ( ! password_verify( $given, BBK_ACCESS_HASH ) ) {
			file_put_contents( $this->work . '/.fails', (string) ( $this->fails() + 1 ) );
			usleep( 800000 );
			throw new \BastionBackup\Core\BackupException( sprintf( 'That is not the access key. %d tries left.', max( 0, self::MAX_FAILS - $this->fails() ) ) );
		}
		session_regenerate_id( true );
		$_SESSION['ok'] = true;
		$this->json( array( 'ok' => true ) );
	}

	/** @return string[] .bbk files next to the installer. */
	private function archives(): array {
		$out = array();
		foreach ( (array) glob( $this->dir . '/*.bbk' ) as $f ) {
			$out[] = basename( (string) $f );
		}
		rsort( $out );
		return $out;
	}

	private function archive( string $name ): string {
		if ( ! in_array( $name, $this->archives(), true ) ) {
			throw new \BastionBackup\Core\BackupException( 'Choose a backup file from this folder.' );
		}
		return $this->dir . '/' . $name;
	}

	private function db(): \mysqli {
		return \BastionBackup\Core\Db::connect( $this->in( 'db_host' ) ?: 'localhost', $this->in( 'db_user' ), $this->in( 'db_pass' ), $this->in( 'db_name' ) );
	}

	private function dbTest(): string {
		$db = $this->db();
		$t  = 'bbk_test_' . bin2hex( random_bytes( 3 ) );
		\BastionBackup\Core\Db::query( $db, 'CREATE TABLE ' . \BastionBackup\Core\Db::ident( $t ) . ' (id int)' );
		\BastionBackup\Core\Db::query( $db, 'DROP TABLE ' . \BastionBackup\Core\Db::ident( $t ) );
		$n   = (int) \BastionBackup\Core\Db::query( $db, 'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()' )->fetch_row()[0];
		$ver = $db->server_info;
		$db->close();
		return sprintf( 'Connected to %s. The user can make tables. The database has %d tables now.', $ver, $n );
	}

	private function stateFile(): string {
		return $this->work . '/job.json';
	}

	private function load(): array {
		$d = is_file( $this->stateFile() ) ? json_decode( (string) file_get_contents( $this->stateFile() ), true ) : null;
		if ( ! is_array( $d ) ) {
			throw new \BastionBackup\Core\BackupException( 'Start again: press Check.' );
		}
		return $d;
	}

	private function save( array $st ): void {
		file_put_contents( $this->stateFile() . '.tmp', json_encode( $st ), LOCK_EX );
		chmod( $this->stateFile() . '.tmp', 0600 );
		rename( $this->stateFile() . '.tmp', $this->stateFile() );
	}

	private function newUrl(): string {
		$u = trim( $this->in( 'url' ) );
		if ( ! preg_match( '#^https?://[^\s/?\#]+(/[^\s?\#]*)?$#', $u ) ) {
			throw new \BastionBackup\Core\BackupException( 'Enter the new site address, like https://www.example.org' );
		}
		return rtrim( $u, '/' );
	}

	private function start(): array {
		$file = $this->archive( $this->in( 'archive' ) );
		\BastionBackup\Core\Crypto::parseKey( $this->in( 'key' ) );
		$st = array(
			'archive' => basename( $file ),
			'phase'   => 'verify',
			'vs'      => \BastionBackup\Core\Restorer::initState(),
			'started' => time(),
		);
		$this->save( $st );
		return array( 'ok' => true, 'phase' => 'verify', 'percent' => 0, 'message' => 'Checking the backup…' );
	}

	private function deadline(): float {
		$max = (int) ini_get( 'max_execution_time' );
		return microtime( true ) + ( $max > 0 ? max( 3, min( 20, $max * 0.6 ) ) : 20 );
	}

	private function step(): array {
		@set_time_limit( 0 );
		$st       = $this->load();
		$file     = $this->archive( (string) $st['archive'] );
		$key      = $this->in( 'key' );
		$deadline = $this->deadline();
		switch ( $st['phase'] ) {
			case 'verify':
				$r    = new \BastionBackup\Core\Restorer( $file, $key, 'verify' );
				$done = $r->step( $st['vs'], $deadline );
				$vs   = $st['vs'];
				if ( ! $done ) {
					$this->save( $st );
					return array( 'ok' => true, 'phase' => 'verify', 'percent' => (int) ( 100 * $vs['pos'] / max( 1, $vs['size'] ) ), 'message' => 'Checking every part of the backup…' );
				}
				$m                = (array) $vs['manifest'];
				$st['manifest']   = $m;
				$st['tables']     = (array) $vs['tables'];
				$st['phase']      = 'ready';
				$st['summary']    = array( 'files' => (int) ( $vs['index']['files'] ?? 0 ), 'tables' => count( (array) $vs['tables'] ) );
				unset( $st['vs'] );
				$this->save( $st );
				return array(
					'ok'       => true,
					'phase'    => 'ready',
					'percent'  => 100,
					'message'  => 'The backup is intact.',
					'manifest' => array_intersect_key( $m, array_flip( array( 'site_url', 'home_url', 'table_prefix', 'wp', 'php', 'multisite', 'created', 'kid' ) ) ),
					'summary'  => $st['summary'],
				);
			case 'ready':
				if ( 'RESTORE' !== $this->in( 'confirm' ) ) {
					throw new \BastionBackup\Core\BackupException( 'Type RESTORE to confirm.' );
				}
				if ( ! empty( $st['manifest']['multisite'] ) ) {
					throw new \BastionBackup\Core\BackupException( 'A multisite network can be restored only at its own address, from its dashboard. This installer does not move networks.' );
				}
				$prefix = $this->in( 'prefix' ) ?: (string) ( $st['manifest']['table_prefix'] ?? 'wp_' );
				if ( ! preg_match( '/^[A-Za-z0-9_]{1,20}$/', $prefix ) ) {
					throw new \BastionBackup\Core\BackupException( 'The table prefix may hold letters, digits and _ only.' );
				}
				$db = $this->db();
				$db->close();
				$st['prefix'] = $prefix;
				$st['url']    = $this->newUrl();
				$st['rs']     = \BastionBackup\Core\Restorer::initState();
				$st['phase']  = 'restore';
				$this->save( $st );
				return array( 'ok' => true, 'phase' => 'restore', 'percent' => 0, 'message' => 'Restoring…' );
			case 'restore':
				$db  = $this->db();
				$imp = $this->importer( $st, $db );
				$imp->session();
				$r    = new \BastionBackup\Core\Restorer( $file, $key, 'restore', $imp, $this->mapper( $st ), true, true );
				$done = $r->step( $st['rs'], $deadline );
				$db->close();
				$rs = $st['rs'];
				if ( $done ) {
					$st['phase'] = 'replace';
					$st['rep']   = null;
					$st['files'] = (int) $rs['files'];
					unset( $st['rs'] );
				}
				$this->save( $st );
				return array( 'ok' => true, 'phase' => 'restore', 'percent' => (int) ( 90 * $rs['pos'] / max( 1, $rs['size'] ) ), 'message' => sprintf( 'Restoring: %d files, %d tables so far', $rs['files'], count( (array) $rs['tables'] ) ) );
			case 'replace':
				$db    = $this->db();
				$imp   = $this->importer( $st, $db );
				$pairs = $this->pairs( $st );
				if ( null === $st['rep'] ) {
					$st['rep'] = $imp->replaceInit( (array) $st['tables'] );
				}
				$done = $imp->replaceStep( $st['rep'], $pairs, $deadline );
				$db->close();
				if ( $done ) {
					$st['phase'] = 'swap';
				}
				$this->save( $st );
				return array( 'ok' => true, 'phase' => 'replace', 'percent' => 93, 'message' => 'Changing the address in the database…' );
			case 'swap':
				$db  = $this->db();
				$imp = $this->importer( $st, $db );
				$imp->dropTables( $imp->swap( (array) $st['tables'] ) );
				$db->close();
				$note        = $this->writeConfig( $st );
				$st['phase'] = 'done';
				$st['note']  = $note;
				$this->save( $st );
				return array( 'ok' => true, 'phase' => 'done', 'percent' => 100, 'message' => 'Restored.', 'note' => $note, 'url' => $st['url'] );
			default:
				return array( 'ok' => true, 'phase' => 'done', 'percent' => 100, 'message' => 'Restored.', 'url' => $st['url'] ?? '' );
		}
	}

	private function importer( array $st, \mysqli $db ): \BastionBackup\Core\DbImporter {
		return new \BastionBackup\Core\DbImporter( $db, (string) ( $st['manifest']['table_prefix'] ?? 'wp_' ), (string) $st['prefix'] );
	}

	/** Where each file goes: into this folder; never over the installer, the backup or the working folder. */
	private function mapper( array $st ): callable {
		$dir   = $this->dir;
		$keep  = array( basename( __FILE__ ), (string) $st['archive'], basename( $this->work ) );
		return static function ( string $p ) use ( $dir, $keep ): ?array {
			if ( '@wpconfig' === $p ) {
				return array( 'root' => $dir, 'rel' => 'wp-config.php' );
			}
			if ( str_starts_with( $p, '@content/' ) ) {
				return array( 'root' => $dir, 'rel' => 'wp-content/' . substr( $p, 9 ) );
			}
			if ( str_starts_with( $p, '@' ) ) {
				return null;
			}
			$first = explode( '/', str_replace( '\\', '/', $p ), 2 )[0];
			return in_array( $first, $keep, true ) ? null : array( 'root' => $dir, 'rel' => $p );
		};
	}

	/** @return array<string,string> */
	private function pairs( array $st ): array {
		$m       = (array) $st['manifest'];
		$oldSite = rtrim( (string) ( $m['site_url'] ?? '' ), '/' );
		$oldHome = rtrim( (string) ( $m['home_url'] ?? $oldSite ), '/' );
		$newSite = (string) $st['url'];
		// WordPress in a sub-folder of the home address keeps the same shape.
		$newHome = $oldHome !== $oldSite && str_starts_with( $oldSite, $oldHome . '/' ) ? substr( $newSite, 0, strlen( $newSite ) - ( strlen( $oldSite ) - strlen( $oldHome ) ) ) : $newSite;
		$pairs   = array(
			$oldSite                                         => $newSite,
			$oldHome                                         => $newHome,
			rtrim( (string) ( $m['abspath'] ?? '' ), '/' )   => $this->dir,
			rtrim( (string) ( $m['content_dir'] ?? '' ), '/' ) => $this->dir . '/wp-content',
		);
		// Address variants: https/http and protocol-relative.
		foreach ( array( $oldSite => $newSite, $oldHome => $newHome ) as $a => $b ) {
			$pairs[ (string) preg_replace( '#^https?:#', '', $a ) ] = (string) preg_replace( '#^https?:#', '', $b );
		}
		return \BastionBackup\Core\SearchReplace::expand( array_filter( $pairs, static fn( $v, $k ) => '' !== (string) $k && '' !== $v, ARRAY_FILTER_USE_BOTH ) );
	}

	/** wp-config.php from the backup with the new database login, or a new one with fresh salts. */
	private function writeConfig( array $st ): string {
		$f  = $this->dir . '/wp-config.php';
		$db = array(
			'DB_NAME'     => $this->in( 'db_name' ),
			'DB_USER'     => $this->in( 'db_user' ),
			'DB_PASSWORD' => $this->in( 'db_pass' ),
			'DB_HOST'     => $this->in( 'db_host' ) ?: 'localhost',
		);
		$note = '';
		if ( is_file( $f ) ) {
			$c = (string) file_get_contents( $f );
			foreach ( $db + array( 'WP_HOME' => null, 'WP_SITEURL' => null ) as $k => $v ) {
				if ( null === $v ) {
					$v = (string) $st['url'];
				}
				$re = '/define\(\s*([\'"])' . $k . '\1\s*,\s*(?:\'(?:[^\'\\\\]|\\\\.)*\'|"(?:[^"\\\\]|\\\\.)*")\s*\)/';
				$n  = 0;
				$c  = (string) preg_replace_callback( $re, static fn() => "define( '" . $k . "', " . var_export( $v, true ) . ' )', $c, 1, $n );
				if ( 0 === $n && isset( $db[ $k ] ) ) {
					$c = (string) preg_replace( '/^<\?php\s*/', "<?php\ndefine( '" . $k . "', " . var_export( $v, true ) . " );\n", $c, 1 );
				}
			}
			$n = 0;
			$c = (string) preg_replace( '/\$table_prefix\s*=\s*([\'"])[^\'"]*\1\s*;/', '$table_prefix = ' . var_export( (string) $st['prefix'], true ) . ';', $c, 1, $n );
			if ( 0 === $n ) {
				// No prefix line: WordPress would look for wp_ tables. Add it at the top.
				$c = (string) preg_replace( '/^<\?php\s*/', "<?php\n\$table_prefix = " . var_export( (string) $st['prefix'], true ) . ";\n", $c, 1 );
			}
			if ( str_contains( $c, 'BASTION_BACKUP_STORAGE' ) ) {
				$note = 'wp-config.php sets BASTION_BACKUP_STORAGE: check that the folder exists on this server.';
			}
		} else {
			$salt = static fn(): string => var_export( substr( strtr( base64_encode( random_bytes( 64 ) ), '+/', '-_' ), 0, 64 ), true );
			$c    = "<?php\n";
			foreach ( $db as $k => $v ) {
				$c .= "define( '$k', " . var_export( $v, true ) . " );\n";
			}
			$c .= "define( 'DB_CHARSET', 'utf8mb4' );\ndefine( 'DB_COLLATE', '' );\n";
			foreach ( array( 'AUTH_KEY', 'SECURE_AUTH_KEY', 'LOGGED_IN_KEY', 'NONCE_KEY', 'AUTH_SALT', 'SECURE_AUTH_SALT', 'LOGGED_IN_SALT', 'NONCE_SALT' ) as $k ) {
				$c .= "define( '$k', " . $salt() . " );\n";
			}
			$c   .= '$table_prefix = ' . var_export( (string) $st['prefix'], true ) . ";\ndefine( 'WP_DEBUG', false );\nif ( ! defined( 'ABSPATH' ) ) {\n\tdefine( 'ABSPATH', __DIR__ . '/' );\n}\nrequire_once ABSPATH . 'wp-settings.php';\n";
			$note = 'The backup had no wp-config.php, so a new one was made with fresh security keys: everyone signs in again.';
		}
		if ( false === file_put_contents( $f . '.bbk-part', $c ) || ! rename( $f . '.bbk-part', $f ) ) {
			throw new \BastionBackup\Core\BackupException( 'Cannot write wp-config.php.' );
		}
		@chmod( $f, 0640 );
		return $note;
	}

	private function finish(): array {
		$st      = is_file( $this->stateFile() ) ? (array) json_decode( (string) file_get_contents( $this->stateFile() ), true ) : array();
		$left    = array();
		if ( '1' === $this->in( 'delete_archive' ) && ! empty( $st['archive'] ) && in_array( (string) $st['archive'], $this->archives(), true ) && ! @unlink( $this->dir . '/' . $st['archive'] ) ) {
			$left[] = (string) $st['archive'];
		}
		foreach ( array_reverse( (array) glob( $this->work . '/{,.}*', GLOB_BRACE ) ) as $x ) {
			if ( is_file( (string) $x ) ) {
				@unlink( (string) $x );
			}
		}
		if ( is_dir( $this->work ) && ! @rmdir( $this->work ) ) {
			$left[] = basename( $this->work );
		}
		if ( ! @unlink( __FILE__ ) ) {
			$left[] = basename( __FILE__ );
		}
		$_SESSION = array();
		session_destroy();
		return array( 'ok' => true, 'left' => $left );
	}

	private function page(): void {
		header( 'Content-Type: text/html; charset=utf-8' );
		$h        = static fn( string $s ): string => htmlspecialchars( $s, ENT_QUOTES, 'UTF-8' );
		$ok       = ! empty( $_SESSION['ok'] );
		$scheme   = $this->https() ? 'https' : 'http';
		$host     = preg_replace( '/[^A-Za-z0-9.:\[\]-]/', '', (string) ( $_SERVER['HTTP_HOST'] ?? 'localhost' ) );
		$path     = rtrim( str_replace( '\\', '/', dirname( (string) ( $_SERVER['SCRIPT_NAME'] ?? '/' ) ) ), '/' );
		$url      = $scheme . '://' . $host . $path;
		$archives = $ok ? $this->archives() : array();
		$locked   = is_dir( $this->work ) && $this->fails() >= self::MAX_FAILS;
		?><!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow">
<title>Bastion Backup installer</title>
<style>
:root{--g:#2271b1;--r:#b32d2e;--ok:#00a32a;--line:#c3c4c7;--muted:#50575e}
*{box-sizing:border-box}body{margin:0;background:#f0f0f1;color:#1d2327;font:14px/1.5 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Oxygen-Sans,Ubuntu,Cantarell,"Helvetica Neue",sans-serif}
main{max-width:760px;margin:32px auto;padding:0 16px}h1{font-size:23px;font-weight:400;margin:0 0 4px}.lead{color:var(--muted);margin:0 0 16px}
section{background:#fff;border:1px solid var(--line);margin:0 0 16px;padding:16px}h2{font-size:15px;margin:0 0 10px}label{display:block;font-weight:600;margin:8px 0 4px}
input,select,textarea{width:100%;padding:6px 8px;border:1px solid #8c8f94;border-radius:4px;font:inherit}textarea{font-family:Consolas,Monaco,monospace}
.row{display:grid;grid-template-columns:1fr 1fr;gap:0 12px}@media(max-width:600px){.row{grid-template-columns:1fr}}
button{background:var(--g);color:#fff;border:1px solid var(--g);border-radius:3px;padding:6px 14px;font:inherit;cursor:pointer;margin-top:12px}button.alt{background:#f6f7f7;color:var(--g)}button.danger{background:var(--r);border-color:var(--r)}button:disabled{opacity:.5;cursor:default}
.notice{border-left:4px solid var(--g);background:#fff;padding:8px 12px;margin:0 0 16px;box-shadow:0 1px 1px rgba(0,0,0,.04)}.notice.err{border-color:var(--r)}.notice.ok{border-color:var(--ok)}.notice.warn{border-color:#dba617}
progress{width:100%;height:16px}table{border-collapse:collapse;width:100%}th,td{text-align:left;padding:4px 8px;border-bottom:1px solid #f0f0f1}th{width:170px}.hint{color:var(--muted);font-size:13px;margin:4px 0 0}
</style></head><body><main>
<h1>Bastion Backup installer</h1>
<p class="lead">Restores a backup into this folder: <code><?php echo $h( $this->dir ); ?></code></p>
<?php if ( ! $this->https() ) : ?><div class="notice warn">This page is not using HTTPS, so the keys and the database password you type here could be read on the way. Turn on HTTPS for this address first if you can.</div><?php endif; ?>
<div id="msg" role="alert"></div>
<?php if ( $locked ) : ?>
<section><h2>Locked</h2><p>Too many wrong access keys were tried. Delete this installer.php and upload a new one.</p></section>
<?php elseif ( ! $ok ) : ?>
<section><h2>Access key</h2><form id="login"><label for="access">The installer's access key (shown when installer.php was made)</label><input id="access" autocomplete="off" required autofocus><button>Open</button></form></section>
<?php else : ?>
<section><h2>1. The backup</h2>
<?php if ( ! $archives ) : ?><p>No .bbk file in this folder yet. Upload the backup next to installer.php, then reload this page.</p><?php else : ?>
<label for="archive">Backup file</label><select id="archive"><?php foreach ( $archives as $a ) : ?><option><?php echo $h( $a ); ?></option><?php endforeach; ?></select>
<label for="key">Backup key (BBK-…)</label><textarea id="key" rows="2" autocomplete="off" spellcheck="false" required></textarea>
<p class="hint">Kept in this page only and sent with each step; it is never saved on this server.</p><?php endif; ?></section>
<section><h2>2. The new database</h2>
<div class="row"><div><label for="db_host">Host</label><input id="db_host" value="localhost"></div><div><label for="db_name">Database name</label><input id="db_name" autocomplete="off"></div>
<div><label for="db_user">User</label><input id="db_user" autocomplete="off"></div><div><label for="db_pass">Password</label><input id="db_pass" type="password" autocomplete="new-password"></div></div>
<button class="alt" type="button" id="dbtest">Test connection</button> <span id="dbres" class="hint"></span></section>
<section><h2>3. Check, then restore</h2>
<button type="button" id="check"<?php echo $archives ? '' : ' disabled'; ?>>Check the backup</button>
<div id="ready" hidden><table id="manifest"></table>
<div class="row"><div><label for="url">New site address</label><input id="url" value="<?php echo $h( $url ); ?>"></div><div><label for="prefix">Table prefix</label><input id="prefix"></div></div>
<label for="confirm">Type RESTORE to confirm</label><input id="confirm" autocomplete="off">
<p class="hint">Files in this folder with the same names are replaced. Tables with the same names in the database are replaced.</p>
<button type="button" class="danger" id="restore">Restore</button></div>
<div id="prog" hidden><progress id="bar" max="100" value="0"></progress><p id="pmsg" aria-live="polite"></p></div></section>
<section id="done" hidden><h2>Finished</h2><p id="donemsg"></p><label style="font-weight:400"><input type="checkbox" id="delarch" style="width:auto" checked> Also delete the backup file from this folder</label><button type="button" id="cleanup">Delete the installer now</button></section>
<?php endif; ?>
</main>
<script>
(function(){
const csrf=<?php echo json_encode( (string) $_SESSION['csrf'] ); ?>;const $=s=>document.querySelector(s);
const msg=(t,c)=>{$('#msg').innerHTML=t?'<div class="notice '+(c||'err')+'"></div>':'';if(t)$('#msg .notice').textContent=t;};
async function post(d){const f=new FormData();f.append('csrf',csrf);for(const k in d)f.append(k,d[k]);const r=await fetch(location.pathname,{method:'POST',body:f,credentials:'same-origin'});let j;try{j=await r.json();}catch(e){throw new Error('The server stopped the request (HTTP '+r.status+'). Press the button again: it carries on where it stopped.');}if(!j.ok)throw new Error(j.error||'Failed.');return j;}
const lf=$('#login');if(lf)lf.onsubmit=async e=>{e.preventDefault();try{await post({action:'login',access:$('#access').value});location.reload();}catch(x){msg(x.message);}};
const db=()=>({db_host:$('#db_host').value,db_name:$('#db_name').value,db_user:$('#db_user').value,db_pass:$('#db_pass').value});
const t=$('#dbtest');if(t)t.onclick=async()=>{$('#dbres').textContent='…';try{$('#dbres').textContent=(await post(Object.assign({action:'dbtest'},db()))).message;}catch(x){$('#dbres').textContent=x.message;}};
async function loop(extra){$('#prog').hidden=false;let tries=0;while(true){let r;try{r=await post(Object.assign({action:'step',key:$('#key').value,url:$('#url').value,prefix:$('#prefix').value},db(),extra||{}));tries=0;}catch(x){if(/stopped the request/.test(x.message)&&++tries<6){await new Promise(z=>setTimeout(z,2000));continue;}throw x;}extra=null;$('#bar').value=r.percent;$('#pmsg').textContent=r.message;if(r.phase==='ready'||r.phase==='done')return r;}}
const c=$('#check');if(c)c.onclick=async()=>{msg('');c.disabled=true;try{await post({action:'start',archive:$('#archive').value,key:$('#key').value});const r=await loop();const m=r.manifest||{};$('#manifest').innerHTML='';[['Made',m.created?new Date(m.created*1000).toLocaleString():''],['From',m.site_url],['WordPress / PHP',(m.wp||'?')+' / '+(m.php||'?')],['Holds',r.summary.tables+' tables, '+r.summary.files+' files'],['Key',m.kid]].forEach(([k,v])=>{const tr=document.createElement('tr');tr.innerHTML='<th></th><td></td>';tr.children[0].textContent=k;tr.children[1].textContent=v||'';$('#manifest').appendChild(tr);});$('#prefix').value=m.table_prefix||'wp_';$('#ready').hidden=false;msg('The backup is intact and opens with this key.','ok');}catch(x){msg(x.message);}finally{c.disabled=false;}};
const rb=$('#restore');if(rb)rb.onclick=async()=>{msg('');rb.disabled=true;try{const r=await loop({confirm:$('#confirm').value});$('#done').hidden=false;$('#donemsg').textContent='The site is restored at '+r.url+'. '+(r.note||'')+' Sign in at '+r.url+'/wp-admin/ after deleting the installer.';msg('Restored.','ok');}catch(x){msg(x.message);rb.disabled=false;}};
const cl=$('#cleanup');if(cl)cl.onclick=async()=>{try{const r=await post({action:'finish',delete_archive:$('#delarch').checked?'1':''});document.querySelector('main').innerHTML=r.left.length?'<div class="notice err"><p><b>Delete these by hand now:</b> '+r.left.map(x=>x.replace(/[&<>]/g,'')).join(', ')+'. Anyone who finds the installer could try to use it.</p></div>':'<div class="notice ok"><p>The installer has deleted itself. <a href="./wp-admin/">Sign in</a>.</p></div>';}catch(x){msg(x.message);}};
})();
</script></body></html>
		<?php
	}
}

( new BbkInstaller() )->run();
