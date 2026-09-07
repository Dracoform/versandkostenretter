<?php

declare(strict_types=1);

/**
 * run-migration.php — CLI-only, operator-driven migration runner.
 *
 * Usage (SSH on the host):
 *     php /versandkostenretter.de/httpdocs/bin/run-migration.php <filename>
 * e.g.
 *     php bin/run-migration.php 0002_products_source_columns.sql
 *
 * Safety properties:
 *  - runs ONLY under PHP CLI (HTTP requests get a bare 404)
 *  - filename must be a plain basename; the file must live in
 *    database/migrations/ — no paths, no traversal, no symlinks outside
 *  - refuses to execute SQL that references non-VSKR_ tables
 *  - refuses non-.sql files
 *  - reads DB credentials from OUTSIDE the document root
 *    (/versandkostenretter.de/config/config.php), never prints them
 *  - statements are executed one at a time with explicit feedback; a failing
 *    statement aborts immediately (non-zero exit, SQLSTATE + short message)
 *  - no prompts: idempotent migrations are the operator's responsibility
 *    (all shipped migrations are written to be re-runnable)
 *
 * Exit codes: 0 success, 1 usage, 2 config/DB problem,
 *             3 unsafe/rejected migration, 4 SQL execution failure.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/src/Database.php';

use Versandkostenretter\Database;

const MIG_OK = 0;
const MIG_USAGE = 1;
const MIG_CONFIG = 2;
const MIG_REJECTED = 3;
const MIG_SQL = 4;

// ---------------------------------------------------------------- args
$args = array_slice($argv, 1);
$filename = null;
foreach ($args as $a) {
    if ($a === '--help' || $a === '-h') {
        fwrite(STDOUT, "Usage: php bin/run-migration.php <migration-filename.sql>\n"
            . "Runs a single VSKR_ migration from database/migrations/.\n");
        exit(MIG_OK);
    }
    if ($filename === null && $a !== '') {
        $filename = $a;
    }
}
if ($filename === null) {
    fwrite(STDERR, "Usage: php bin/run-migration.php <migration-filename.sql>\n");
    exit(MIG_USAGE);
}

// ---------------------------------------------------------------- path safety
// Plain basename only: no directories, no traversal, nothing outside
// database/migrations/.
if (str_contains($filename, '/')
    || str_contains($filename, '\\')
    || str_contains($filename, '..')
    || !preg_match('/^[A-Za-z0-9._-]+\.sql$/', $filename)) {
    fwrite(STDERR, "Rejected: filename must be a plain .sql basename (no paths).\n");
    exit(MIG_REJECTED);
}

$migrationDir = dirname(__DIR__) . '/database/migrations';
$migrationFile = $migrationDir . '/' . $filename;

// Resolve symlinks so a planted link cannot smuggle in a file from elsewhere.
$realFile = realpath($migrationFile);
$realDir = realpath($migrationDir);
if ($realFile === false || $realDir === false || !str_starts_with($realFile, $realDir . DIRECTORY_SEPARATOR)) {
    fwrite(STDERR, "Rejected: migration not found inside database/migrations/.\n");
    exit(MIG_REJECTED);
}
$sql = file_get_contents($realFile);
if ($sql === false || trim($sql) === '') {
    fwrite(STDERR, "Rejected: migration file is empty or unreadable.\n");
    exit(MIG_REJECTED);
}

// ---------------------------------------------------------------- VSKR guard
// Every table identifier mentioned as an object (FROM/JOIN/INTO/UPDATE/TABLE)
// must be VSKR_-prefixed. This catches accidents BEFORE anything executes.
$violations = Database::assertOnlyVskrTables($sql);
// Filter false positives: strings quoted inside comments would still match.
if ($violations !== []) {
    fwrite(STDERR, "Rejected: migration references non-VSKR_ table(s): "
        . implode(', ', array_unique($violations)) . "\n");
    exit(MIG_REJECTED);
}

// ---------------------------------------------------------------- config
$home = dirname(__DIR__);                                   // .../httpdocs
$configCandidates = [
    dirname($home) . '/config/config.php',                  // OUTSIDE docroot
    $home . '/config/config.php',                           // local dev fallback
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
    exit(MIG_CONFIG);
}
$config = require $configPath;
if (!is_array($config) || !isset($config['db']) || !is_array($config['db'])) {
    fwrite(STDERR, "Error: invalid configuration file.\n");
    exit(MIG_CONFIG);
}

try {
    $pdo = Database::fromConfigFile($configPath)->pdo();
} catch (Throwable $e) {
    fwrite(STDERR, "Error: could not open database connection.\n");
    exit(MIG_CONFIG);
}

// ---------------------------------------------------------------- execute
// Split on statement-ending semicolons at line ends, ignoring -- comments.
// Shipped migrations are simple statement-per-semicolon files; SET/PREPARE/
// EXECUTE blocks are separate statements and must run in order.
$lines = explode("\n", $sql);
$statements = [];
$buffer = [];
foreach ($lines as $line) {
    if (preg_match('/^\s*--/', $line)) {
        continue; // comment line
    }
    $buffer[] = $line;
    if (preg_match('/;\s*$/', $line)) {
        $stmt = trim(implode("\n", $buffer));
        $buffer = [];
        if ($stmt !== '' && $stmt !== ';') {
            $statements[] = rtrim($stmt, "; \t");
        }
    }
}
$tail = trim(implode("\n", $buffer));
if ($tail !== '' && $tail !== ';') {
    $statements[] = $tail;
}

if ($statements === []) {
    fwrite(STDERR, "Rejected: no executable statements found.\n");
    exit(MIG_REJECTED);
}

fwrite(STDOUT, "Running migration: {$filename}\n");
$index = 0;
foreach ($statements as $statement) {
    $index++;
    $label = strtoupper(preg_replace('/\s+/', ' ', substr(ltrim($statement), 0, 60)) ?? '');
    try {
        $pdo->exec($statement);
    } catch (PDOException $e) {
        // Print SQLSTATE + driver message; NEVER print the config/DSN.
        fwrite(STDERR, sprintf(
            "SQL error at statement %d (%s…): [%s] %s\n",
            $index,
            $label,
            $e->getCode(),
            $e->getMessage()
        ));
        exit(MIG_SQL);
    }
    $done = 'ok';
    if (str_starts_with($label, 'SELECT')) {
        // information_schema probes / no-op notes: fetch nothing, just report
        $done = 'query ok';
    }
    fwrite(STDOUT, sprintf("  [%02d] %s… %s\n", $index, $label, $done));
}
fwrite(STDOUT, "Migration {$filename} completed ({$index} statements).\n");
exit(MIG_OK);
