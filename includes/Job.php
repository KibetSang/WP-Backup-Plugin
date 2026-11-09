<?php
/**
 * One kind of job (backup or restore). step() does as much as fits before $deadline and keeps everything it needs in
 * $st, which Jobs saves between requests.
 *
 * @package BastionBackup
 */

namespace BastionBackup;

defined( 'ABSPATH' ) || exit;

interface Job {

	/** Sets $st['done'] when finished; $st['waiting'] when it needs the administrator (e.g. to confirm a restore). */
	public function step( array &$st, float $deadline, Logger $log ): void;

	/** Removes temporary files and tables after a cancel or a failure. */
	public function cleanup( array $st, Logger $log ): void;
}
