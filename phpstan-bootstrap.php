<?php

declare(strict_types=1);

/**
 * PHPStan-only constant declarations. Never executed at real runtime — the
 * actual values come from club-competition-plugin.php (SCS_*, via $wpdb) and
 * wp-config.php (DB_*), neither of which PHPStan can run since they depend on
 * live WordPress state the stubs only declare, not implement. Values here are
 * placeholders; only their existence/type matters for analysis.
 */
define('SCS_TABLE_PREFIX', 'wp_scs_');
define('SCS_PLUGIN_PATH', __DIR__ . '/');
define('SCS_VERSION', '0.0.0');
define('DB_HOST', 'localhost');
define('DB_NAME', 'wordpress');
define('DB_USER', 'wordpress');
define('DB_PASSWORD', '');
