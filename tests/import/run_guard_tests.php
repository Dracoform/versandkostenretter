<?php

declare(strict_types=1);

/**
 * VSKR-only SQL guard regression tests.
 *
 * Usage: php tests/import/run_guard_tests.php
 *
 * Proves:
 *  1. the REAL production migrations 0002/0003 pass validation (the original
 *     false positives "the"/"information_schema" from comments and the
 *     information_schema metadata probes)
 *  2. malicious statements against non-VSKR tables AND against
 *     information_schema stay rejected
 *  3. comments/string literals are never parsed as table names
 */

require __DIR__ . '/../../src/Database.php';

use Versandkostenretter\Database;

$pass = 0;
$fail = 0;
$check = function (string $name, bool $ok, string $detail = '') use (&$pass, &$fail): void {
    if ($ok) {
        $pass++;
        print("PASS  {$name}\n");
    } else {
        $fail++;
        print("FAIL  {$name}" . ($detail !== '' ? " — {$detail}" : '') . "\n");
    }
};

$migDir = dirname(__DIR__, 2) . '/database/migrations';

/* =============================================================
 * 1. REAL production migrations must pass validation
 * ============================================================= */
foreach (['0002_products_source_columns.sql', '0003_lootforge_shop.sql'] as $file) {
    $path = $migDir . '/' . $file;
    $check("real migration exists: {$file}", is_file($path));
    $sql = (string) file_get_contents($path);
    $v = Database::assertOnlyVskrTables($sql);
    $check("real migration passes guard: {$file}", $v === [], implode(', ', $v));
}

// Spot-check the exact false positives from the production report:
$sql0002 = (string) file_get_contents($migDir . '/0002_products_source_columns.sql');
$check('false positive "the" gone', !in_array('the', Database::assertOnlyVskrTables($sql0002), true));
$check('false positive "information_schema" gone', !in_array('information_schema', Database::assertOnlyVskrTables($sql0002), true));

/* =============================================================
 * 2. information_schema READS are allowed
 * ============================================================= */
$check('metadata SELECT allowed',
    Database::assertOnlyVskrTables(
        'SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_NAME = \'x\''
    ) === []);
$check('metadata SELECT with alias allowed',
    Database::assertOnlyVskrTables(
        'SELECT COUNT(*) FROM information_schema.STATISTICS s WHERE s.INDEX_NAME = \'uq\''
    ) === []);
$check('metadata SELECT joined with VSKR table allowed',
    Database::assertOnlyVskrTables(
        'SELECT * FROM VSKR_shops s JOIN information_schema.COLUMNS c ON c.TABLE_NAME = s.slug'
    ) === []);

/* =============================================================
 * 3. Malicious statements stay rejected
 * ============================================================= */
$malicious = [
    'DROP TABLE users;' => ['users'],
    'DROP TABLE IF EXISTS users;' => ['users'],
    'ALTER TABLE users ADD COLUMN x INT;' => ['users'],
    'INSERT INTO users (id) VALUES (1);' => ['users'],
    'UPDATE users SET x = 1;' => ['users'],
    'DELETE FROM users;' => ['users'],
    'TRUNCATE TABLE users;' => ['users'],
    'REPLACE INTO users (id) VALUES (1);' => ['users'],
    'CREATE TABLE users (id INT);' => ['users'],
    'CREATE INDEX idx ON users (id);' => ['users'],
    'DROP INDEX idx ON users;' => ['users'],
    'DROP TABLE information_schema.foo;' => ['information_schema'],
    'UPDATE information_schema.TABLES SET TABLE_NAME = 1;' => ['information_schema'],
    'DELETE FROM information_schema.COLUMNS;' => ['information_schema'],
    'INSERT INTO information_schema.SCHEMATA VALUES (1);' => ['information_schema'],
    'TRUNCATE TABLE information_schema.TABLES;' => ['information_schema'],
    'DROP TABLE otherdb.VSKR_shops;' => ['otherdb'],
    'SELECT * FROM secret_table;' => ['secret_table'],
    'SELECT * FROM otherdb.VSKR_shops;' => ['otherdb'],
];
foreach ($malicious as $sql => $expected) {
    $v = Database::assertOnlyVskrTables($sql);
    $hit = (bool) array_intersect(array_map('strtolower', $v), array_map('strtolower', $expected));
    $check("rejected: {$sql}", $hit, 'violations: ' . implode(',', $v));
}

/* =============================================================
 * 4. Comments and string literals are never parsed as tables
 * ============================================================= */
$clean = [
    '-- the quick brown fox DROP TABLE users',
    '# the users table is nice',
    '/* DROP TABLE users; DELETE FROM secrets; the information_schema */'
    . "\nSELECT 1 FROM VSKR_shops;",
    "SELECT 'DROP TABLE users' AS x FROM VSKR_shops;",
    'SELECT "the users" FROM VSKR_shops;',
    "SELECT 'it\\'s a string with \\' escapes' FROM VSKR_products;",
];
foreach ($clean as $sql) {
    $check('comments/strings ignored: ' . substr(preg_replace('/\s+/', ' ', $sql) ?? '', 0, 50),
        Database::assertOnlyVskrTables($sql) === []);
}

// A real table reference hidden inside an otherwise commented line is still caught:
$check('real reference outside comments still caught',
    Database::assertOnlyVskrTables("-- note\nDROP TABLE users;") === ['users']);

/* =============================================================
 * 5. Legit mixed migration still passes
 * ============================================================= */
$mixed = <<<SQL
-- idempotent VSKR migration, the usual preamble
SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_NAME = 'VSKR_shops');
SET @ddl := IF(@col = 0, 'ALTER TABLE VSKR_shops ADD COLUMN x INT', 'SELECT 1');
PREPARE vskr_mig FROM @ddl;
EXECUTE vskr_mig;
DEALLOCATE PREPARE vskr_mig;
UPDATE VSKR_products SET available = 0 WHERE shop_id = 1;
SQL;
$check('legit mixed migration (SET/IF/PREPARE/EXECUTE + metadata read) passes',
    Database::assertOnlyVskrTables($mixed) === [],
    implode(',', Database::assertOnlyVskrTables($mixed)));

// But a PREPARE whose payload was written by us cannot be statically validated —
// dynamic SQL from a variable is out of scope; the statement itself references
// no table, so it passes (documented limitation, SQL itself is operator-supplied).

print("\nGuard tests: {$pass} passed, {$fail} failed\n");
exit($fail === 0 ? 0 : 1);
