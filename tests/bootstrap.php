<?php
/**
 * Test bootstrap: the core (no WordPress) and, for the plugin tests, a small in-memory stand-in for the parts of
 * WordPress the plugin uses (tests/Support/wp-stubs.php), so jobs, destinations and the admin endpoints run end to end.
 */

declare(strict_types=1);

require __DIR__ . '/bootstrap-core.php';
require __DIR__ . '/Support/wp-stubs.php';
require __DIR__ . '/Support/SiteCase.php';
require __DIR__ . '/Support/FakeClouds.php';
