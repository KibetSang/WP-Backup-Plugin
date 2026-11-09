<?php
/**
 * Dev only: writes languages/bastion-backup.pot from the __(), esc_html__() and esc_attr__() calls in the plugin.
 * (wp i18n make-pot does the same where WP-CLI is available.)
 */

$root    = dirname( __DIR__ );
$strings = array();
$files   = array_merge( glob( $root . '/*.php' ), glob( $root . '/includes/*.php' ), glob( $root . '/includes/Destinations/*.php' ) );
foreach ( $files as $f ) {
	$src = (string) file_get_contents( $f );
	if ( preg_match_all( "/(?:__|esc_html__|esc_attr__)\(\s*'((?:[^'\\\\]|\\\\.)*)'\s*,\s*'bastion-backup'\s*\)/", $src, $m, PREG_OFFSET_CAPTURE ) ) {
		foreach ( $m[1] as $hit ) {
			$line = substr_count( substr( $src, 0, $hit[1] ), "\n" ) + 1;
			$str  = stripcslashes( $hit[0] );
			$strings[ $str ][] = substr( $f, strlen( $root ) + 1 ) . ':' . $line;
		}
	}
}
$esc = static fn( string $s ): string => addcslashes( $s, "\"\\\n" );
$out = "# Copyright (C) " . gmdate( 'Y' ) . " Wingustar Solutions Ltd\n# This file is distributed under the GPL-2.0-or-later.\nmsgid \"\"\nmsgstr \"\"\n\"Project-Id-Version: Bastion Backup 1.0.0\\n\"\n\"MIME-Version: 1.0\\n\"\n\"Content-Type: text/plain; charset=UTF-8\\n\"\n\"Content-Transfer-Encoding: 8bit\\n\"\n\"POT-Creation-Date: " . gmdate( 'Y-m-d H:i' ) . "+0000\\n\"\n\"X-Domain: bastion-backup\\n\"\n\n";
foreach ( $strings as $s => $refs ) {
	$out .= '#: ' . implode( ' ', array_slice( $refs, 0, 4 ) ) . "\n";
	if ( str_contains( $s, '%' ) ) {
		$out .= "#, php-format\n";
	}
	$out .= 'msgid "' . $esc( $s ) . "\"\nmsgstr \"\"\n\n";
}
@mkdir( $root . '/languages' );
file_put_contents( $root . '/languages/bastion-backup.pot', $out );
echo count( $strings ), " strings\n";
