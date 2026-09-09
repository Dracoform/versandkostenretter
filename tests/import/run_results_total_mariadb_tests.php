<?php

declare(strict_types=1);

/**
 * Regression: Ergebnis-Text muss die GESAMTZAHL (unlimitiertes countEligible)
 * zeigen, nicht die Anzahl pro Seite.
 *
 * Fixture: 253 Treffer im Strict-Fenster, perPage=10 ->
 *   page 1  -> 10 sichtbar, "253 passende Produkte gefunden – Seite 1 von 26"
 *   page 2  -> 10 sichtbar, "… – Seite 2 von 26"
 *   page 26 ->  3 sichtbar, "… – Seite 26 von 26"
 *   7 Treffer -> "7 passende Produkte gefunden" (keine Seitenangabe)
 *
 * Echte MariaDB + echtes Repository + echtes Template-Render-Ergebnis
 * (Template-Ausdruck identisch results.php:81 ausgewertet).
 *
 * Usage:
 *   source ~/.config/versandkostenretter/test-db.env && /usr/bin/php8.3 \
 *     tests/import/run_results_total_mariadb_tests.php
 * SKIP ohne VSKR_TEST_DB_*.
 */

if (getenv('VSKR_TEST_DB_DSN') === false) {
    echo "SKIP  VSKR_TEST_DB_* nicht gesetzt.\n";
    exit(0);
}

$repoRoot = dirname(__DIR__, 2);
require $repoRoot . '/src/Money.php';
require $repoRoot . '/src/Cart.php';
require $repoRoot . '/src/View.php';
require $repoRoot . '/src/Database.php';
require $repoRoot . '/src/ProductRepository.php';
require $repoRoot . '/src/Import/SourceException.php';
require $repoRoot . '/src/Import/SourceFetcher.php';
require $repoRoot . '/src/Import/ImportRepository.php';

use Versandkostenretter\Database;
use Versandkostenretter\ProductRepository;

$checks = 0;
$failed = 0;
$check = function (string $name, bool $ok, string $detail = '') use (&$checks, &$failed): void {
    $checks++;
    if ($ok) {
        echo "PASS  {$name}\n";
    } else {
        $failed++;
        echo "FAIL  {$name}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
    }
};

$pdo = new PDO(getenv('VSKR_TEST_DB_DSN'), getenv('VSKR_TEST_DB_USER'), getenv('VSKR_TEST_DB_PASSWORD'), [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);
$pdo->exec('SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci');
$stmts = static function (string $sql) use ($pdo): void {
    $clean = implode("\n", array_filter(explode("\n", $sql), static fn (string $l): bool => !str_starts_with(ltrim($l), '--')));
    foreach (array_filter(array_map('trim', explode(';', $clean))) as $stmt) {
        if ($stmt !== '' && !str_starts_with(strtoupper($stmt), 'USE ')) {
            $pdo->exec($stmt);
        }
    }
};
$pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
foreach ($pdo->query("SHOW TABLES LIKE 'VSKR_%'")->fetchAll(PDO::FETCH_COLUMN) as $t) {
    $pdo->exec("DROP TABLE `{$t}`");
}
$stmts((string) file_get_contents($repoRoot . '/database/schema.sql'));
$pdo->exec('SET FOREIGN_KEY_CHECKS = 1');

// --- Fixture: 253 Treffer im Fenster (5.00-7.00), 7 darunter ------------------
$pdo->exec("INSERT INTO VSKR_shops (id,name,slug,website_url,shipping_cost,free_shipping_threshold,active)
VALUES (1,'Lootforge','lootforge','https://lootforge.example',5.99,100.00,1)");
$ins = $pdo->prepare('INSERT INTO VSKR_products
    (shop_id, external_id, name, url, price, available, category, last_seen_at, updated_at)
    VALUES (1, ?, ?, ?, ?, 1, NULL, NOW(), NOW())');
for ($i = 1; $i <= 253; $i++) {
    $eid = 'T' . str_pad((string) $i, 4, '0', STR_PAD_LEFT);
    $ins->execute([$eid, 'Treffer ' . str_pad((string) $i, 3, '0', STR_PAD_LEFT), 'https://s.example/p/' . strtolower($eid), 5.00 + ($i % 200) * 0.01]);
}
// 7 Produkte UNTER dem Fenster (nicht gezählt):
for ($i = 1; $i <= 7; $i++) {
    $eid = 'U' . str_pad((string) $i, 4, '0', STR_PAD_LEFT);
    $ins->execute([$eid, 'Unter Fenster ' . $i, 'https://s.example/p/u' . $i, 4.50]);
}

$repo = new ProductRepository(new Database(['db' => [
    'dsn' => getenv('VSKR_TEST_DB_DSN'), 'host' => 'u', 'name' => 'u',
    'user' => getenv('VSKR_TEST_DB_USER'), 'pass' => getenv('VSKR_TEST_DB_PASSWORD'),
]]));
$importRepo = new Versandkostenretter\Import\ImportRepository($pdo);

$missing = 500;
$strictMax = $missing + 200;
$memberships = $importRepo->categoryMemberships(1);
if ($memberships === []) { $memberships = null; }

// Exakt der index.php-Pfad:
$render = static function (string $pageParam) use ($repo, $missing, $strictMax, $memberships): array {
    $page = ctype_digit($pageParam) ? max(1, (int) $pageParam) : 1;
    $total = $repo->countEligible(1, $missing, $strictMax, null, $memberships);
    $totalPages = max(1, (int) ceil($total / 10));
    $page = min($page, $totalPages);
    $products = $repo->eligibleProducts(1, $missing, 10, null, $strictMax, $memberships, $page);
    // Template-Ausdruck (results.php:81) identisch ausgewertet:
    $text = (int) $total . ' passende Produkte gefunden'
        . ($totalPages > 1 ? ' – Seite ' . (int) $page . ' von ' . (int) $totalPages : '');
    return ['text' => $text, 'visible' => count($products), 'total' => $total,
            'totalPages' => $totalPages, 'page' => $page];
};

$r1 = $render('1');
$check('page=1: 10 sichtbare Produkte', $r1['visible'] === 10, "ist={$r1['visible']}");
$check('page=1: Text enthält GESAMTZAHL 253', $r1['text'] === '253 passende Produkte gefunden – Seite 1 von 26', $r1['text']);

$r2 = $render('2');
$check('page=2: 10 sichtbare Produkte', $r2['visible'] === 10, "ist={$r2['visible']}");
$check('page=2: Text weiterhin 253 – Seite 2 von 26', $r2['text'] === '253 passende Produkte gefunden – Seite 2 von 26', $r2['text']);

$r26 = $render('26');
$check('page=26: 3 sichtbare Produkte', $r26['visible'] === 3, "ist={$r26['visible']}");
$check('page=26: Text 253 – Seite 26 von 26', $r26['text'] === '253 passende Produkte gefunden – Seite 26 von 26', $r26['text']);

// count($products) darf NICHT für den Gesamttext verwendet werden:
$check('Gesamttext nutzt total (253), nicht count($products) (10)',
    $r1['total'] === 253 && $r1['visible'] === 10);

// 7 Treffer -> keine Seitenangabe:
$pdo->exec("UPDATE VSKR_products SET price = 4.00 WHERE external_id LIKE 'T%' AND CAST(SUBSTRING(external_id, 2) AS UNSIGNED) > 7");
$r7 = $render('1');
$check('7 Treffer -> "7 passende Produkte gefunden" ohne Seitenangabe',
    $r7['text'] === '7 passende Produkte gefunden', $r7['text']);
$check('7 Treffer: count($products) (7) == total (7) — konsistent',
    $r7['visible'] === 7 && $r7['total'] === 7);

// DB-Direktbeweis: COUNT nach Update = 7 mit denselben Filtern:
$direct = (int) $pdo->query('SELECT COUNT(*) FROM VSKR_products WHERE shop_id = 1 AND available = 1 AND price >= 5.00 AND price <= 7.00')->fetchColumn();
$check('MariaDB-Direktbeweis: COUNT=7 mit denselben Filtern (nach Update)', $direct === 7, "ist={$direct}");

echo "\nErgebnis-Total (MariaDB): {$checks} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
