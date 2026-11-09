<?php
/**
 * A place backups are copied to besides this server. Uploads and downloads are resumable: one call does as much as
 * fits before $deadline and keeps its position in $state, which the job saves between requests.
 *
 * @package BastionBackup
 */

namespace BastionBackup\Destinations;

defined( 'ABSPATH' ) || exit;

interface Destination {

	public function id(): string;

	public function label(): string;

	/** Everything needed to upload is set (and, for Google Drive and OneDrive, the account is connected). */
	public function isConfigured(): bool;

	/** @return true|string True, or what is wrong, in words for the person setting it up. */
	public function test(): true|string;

	/** Returns true when the whole file is there. $state persists between requests. */
	public function upload( string $localPath, string $remoteName, array &$state, float $deadline ): bool;

	/** @return list<array{id:string,name:string,size:int,time:int}> Backups this site put there, newest first. */
	public function list(): array;

	public function download( string $remoteId, string $localPath, array &$state, float $deadline ): bool;

	public function delete( string $remoteId ): void;
}
