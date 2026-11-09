<?php

declare(strict_types=1);

namespace BastionBackup\Tests\Plugin;

use BastionBackup\Admin;
use BastionBackup\Keys;
use BastionBackup\Tests\Support\SiteCase;

/** Every tab renders without a notice or warning, with and without a key. */
final class AdminRenderTest extends SiteCase {

	private function render( string $tab ): string {
		$_GET = array( 'tab' => $tab );
		ob_start();
		try {
			Admin::render();
		} finally {
			$html = (string) ob_get_clean();
			$_GET = array();
		}
		return $html;
	}

	public function test_every_tab_renders(): void {
		$expect = array(
			'backups'      => array( 'Make the backup key', 'No backup key yet', 'Back up now', 'Restoring' ),
			'restore'      => array( '1. Choose the backup', 'Type RESTORE to confirm' ),
			'destinations' => array( 'Google Drive', 'OneDrive', 'File server (SFTP / FTPS)', '/wp-json/bastion-backup/v1/oauth', 'Host key fingerprint' ),
			'schedule'     => array( 'Back up automatically', 'Keep on this server' ),
			'settings'     => array( 'Leave out', 'BASTION_BACKUP_STORAGE', 'Server check', 'sodium extension', 'Encryption used' ),
			'installer'    => array( 'Make installer.php', 'Disaster recovery in 5 steps' ),
			'logs'         => array( 'Who did what' ),
		);
		foreach ( $expect as $tab => $words ) {
			$html = $this->render( $tab );
			foreach ( $words as $w ) {
				$this->assertStringContainsString( $w, $html, "$tab: $w" );
			}
			$this->assertStringContainsString( 'nav-tab nav-tab-active" aria-current="page">', $html );
		}
		$this->assertStringContainsString( 'No backups are made until there is a backup key', $this->render( 'schedule' ) );
	}

	public function test_with_a_key_the_backups_tab_shows_it_and_offers_replacing(): void {
		$k    = Keys::make( 'admin' );
		$html = $this->render( 'backups' );
		$this->assertStringContainsString( $k['id'], $html );
		$this->assertStringContainsString( 'Replace the key', $html );
		$this->assertStringNotContainsString( base64_encode( Keys::current()['public'] ), $html, 'not even the public half is printed' );
	}

	public function test_without_the_capability_the_page_is_refused(): void {
		$GLOBALS['bbk_can'] = false;
		$this->expectException( \BbkTestDie::class );
		$this->render( 'backups' );
	}
}
