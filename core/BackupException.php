<?php
/**
 * Core exception type. The core/ directory has no WordPress dependency so the
 * standalone installer can reuse it verbatim.
 */

namespace BastionBackup\Core;

defined( 'ABSPATH' ) || defined( 'BBK_INSTALLER' ) || exit;

class BackupException extends \RuntimeException {
}
