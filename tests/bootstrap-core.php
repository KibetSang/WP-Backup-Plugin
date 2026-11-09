<?php
/**
 * Bootstrap for the core tests: no WordPress, exactly as the standalone installer loads core/.
 */

declare(strict_types=1);

error_reporting( E_ALL );
set_error_handler(
	static function ( int $no, string $msg, string $file, int $line ): bool {
		// Fail on every notice, warning and deprecation: nothing is silently tolerated.
		if ( ! ( error_reporting() & $no ) ) {
			return false;
		}
		throw new ErrorException( $msg, 0, $no, $file, $line );
	}
);

// BBK_SODIUM_COMPAT=<path to wp-includes/sodium_compat>, with PHP's sodium functions disabled, runs everything on
// WordPress's pure-PHP copy instead, as on hosts where the extension is off.
if ( getenv( 'BBK_SODIUM_COMPAT' ) ) {
	require_once rtrim( (string) getenv( 'BBK_SODIUM_COMPAT' ), '/' ) . '/autoload.php';
	// The extension is still loaded here (only its functions are disabled): make sodium_compat use pure PHP.
	ParagonIE_Sodium_Compat::$disableFallbackForUnitTests = true;
}

defined( 'BBK_INSTALLER' ) || define( 'BBK_INSTALLER', true );
foreach ( array( 'BackupException', 'Crypto', 'ArchiveWriter', 'ArchiveReader', 'PathGuard', 'FileScanner', 'FileArchiver', 'Restorer', 'SearchReplace', 'Db', 'DbExporter', 'DbImporter' ) as $c ) {
	require_once dirname( __DIR__ ) . '/core/' . $c . '.php';
}
require_once __DIR__ . '/Support/TempDir.php';
