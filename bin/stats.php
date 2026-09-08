<?php

declare(strict_types=1);

/**
 * stats.php — CLI-only diagnostic for the aggregate rescue counter.
 *
 * Usage (SSH on the host):
 *     php /versandkostenretter.de/httpdocs/bin/stats.php
 *
 * Output (aggregate data only, no credentials, no user data):
 *     outbound_product_clicks: 3
 *
 * Read-only. Never writes. HTTP requests get a bare 404 (bin/ is additionally
 * denied by .htaccess — proven by the HTTP exposure suite).
 *
 * Exit codes: 0 ok, 1 usage, 2 config/DB problem.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/src/Database.php';
require dirname(__DIR__) . '/src/StatsRepository.php';

use Versandkostenretter\Database;
use Versandkostenretter\StatsRepository;

$args = array_slice($argv, 1);
if (isset($args[0]) && ($args[0] === '--help' || $args[0] === '-h')) {
    fwrite(STDOUT, "Usage: php bin/stats.php\nShows the aggregate outbound click counter (read-only).\n");
    exit(0);
}

// Same config resolution as the app: OUTSIDE the document root first.
$home = dirname(__DIR__);                                   // .../httpdocs
$configCandidates = [
    dirname($home) . '/config/config.php',                  // /versandkostenretter.de/config/config.php
    $home . '/config/config.php',                           // local dev fallback (gitignored)
];
$configPath = null;
foreach ($configCandidates as $c) {
    if (is_file($c)) {
        $configPath = $c;
        break;
    }
}
if ($configPath === null) {
    fwrite(STDERR, "Error: database configuration not found (expected outside the document root).\n");
    exit(2);
}

// Open the DB ONCE and reuse the PDO for every statement below.
try {
    $pdo = Database::fromConfigFile($configPath)->pdo();
} catch (Throwable $e) {
    fwrite(STDERR, "Error: could not open database connection.\n");
    exit(2);
}

// Aggregate value only. A missing row is reported explicitly so an operator
// can tell "0 clicks" apart from "table/migration row missing".
try {
    $stmt = $pdo->prepare('SELECT stat_value FROM VSKR_stats WHERE stat_key = :key');
    $stmt->execute([':key' => StatsRepository::OUTBOUND_PRODUCT_CLICKS]);
    $row = $stmt->fetchColumn();

    if ($row === false) {
        fwrite(STDOUT, "outbound_product_clicks: (row missing — run migration 0005)\n");
    } else {
        fwrite(STDOUT, StatsRepository::OUTBOUND_PRODUCT_CLICKS . ': ' . (int) $row . "\n");
    }
} catch (Throwable $e) {
    // Privacy-safe: SQLSTATE + short message only, never credentials/DSN.
    fwrite(STDERR, sprintf(
        "Error: counter could not be read [%s]. See the application error log.\n",
        $e instanceof PDOException ? $e->getCode() : 'n/a'
    ));
    exit(2);
}

exit(0);
