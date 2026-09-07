<?php

/**
 * Example configuration — copy to config/config.php and fill in real values.
 *
 * config/config.php is gitignored and must live OUTSIDE the public web root.
 * On Plesk the document root points at public/, so config/ is not reachable
 * via HTTP.
 *
 * NEVER commit real credentials. NEVER place real credentials in public/.
 */

return [
    'db' => [
        'host' => 'localhost',
        'port' => 3306,
        'name' => 'DATABASE_NAME', // TODO(operator): real database name
        'user' => 'DATABASE_USER', // TODO(operator): real database user
        'pass' => 'DATABASE_PASS', // TODO(operator): real password
        'charset' => 'utf8mb4',
    ],
    'app' => [
        // Show detailed errors ONLY in development. Production must be false.
        'debug' => false,
        // Maximum number of product results per search.
        'max_results' => 24,
    ],
];
