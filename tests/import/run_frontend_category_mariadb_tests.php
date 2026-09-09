<?php

declare(strict_types=1);

/**
 * Frontend-Filterpfad-Trace + Reproduktion mit production-shaped MariaDB.
 *
 * Fixture: 1 Shop, 3 verfügbare Produkte im selben Preisfenster:
 *   A mit Memberships ['Soulblight Gravelords Age of Sigma', 'Age of Sigmar']
 *   B mit Membership  ['Warpaints Fanatic']
 *   C ohne Membership
 *
 * Verifiziert die 6 geforderten Fälle (vor UND nach Fix identisch lauffähig;
 * FAILs zeigen den Bug).
 *
 * Usage:
 *   source ~/.config/versandkostenretter/test-db.env && /usr/bin/php8.3 \
 *     tests/import/run_frontend_category_mariadb_tests.php
 * SKIP ohne VSKR_TEST_DB_*.
 */

if (getenv('VSKR_TEST_DB_DSN') === false) {
    echo "SKIP  MariaDB-Frontend-Kategorietest: VSKR_TEST_DB_* nicht gesetzt.\n";
    exit(0);
}

$repoRoot = dirname(__DIR__, 2);
require $repoRoot . '/src/Money.php';
require $repoRoot . '/src/Cart.php';
require $repoRoot . '/src/View.php';
require $repoRoot . '/src/Database.php';
require $repoRoot . '/src/ProductRepository.php';
require $repoRoot . '/src/CategoryFilter.php';
require $repoRoot . '/src/Import/SourceException.php';
require $repoRoot . '/src/Import/SourceFetcher.php';
require $repoRoot . '/src/Import/SourceAdapter.php';
require $repoRoot . '/src/Import/SourceAdapterRegistry.php';
require $repoRoot . '/src/Import/SourceFetchResult.php';
require $repoRoot . '/src/Import/NormalizedProduct.php';
require $repoRoot . '/src/Import/ImportRepository.php';
require $repoRoot . '/src/Import/ImportOrchestrator.php';

use Versandkostenretter\CategoryFilter;
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

// --- Verbindung + Schema-Reset ------------------------------------------------
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

// --- Fixture -------------------------------------------------------------------
$pdo->exec("INSERT INTO VSKR_shops (id,name,slug,website_url,shipping_cost,free_shipping_threshold,active)
VALUES (1,'Lootforge','lootforge','https://lootforge.example',5.99,100.00,1)");
$ins = $pdo->prepare('INSERT INTO VSKR_products
    (shop_id, external_id, name, url, price, available, category, last_seen_at, updated_at)
    VALUES (1, ?, ?, ?, ?, 1, NULL, NOW(), NOW())');
// Alle im selben Preisfenster (Warenkorb 95.00, Threshold 100.00 -> 5.00..7.00)
$ins->execute(['A1', 'Product A', 'https://s.example/p/a', 5.50]);
$ins->execute(['B1', 'Product B', 'https://s.example/p/b', 6.00]);
$ins->execute(['C1', 'Product C', 'https://s.example/p/c', 6.50]);

// Memberships direkt in die Tabelle (production-shaped, Migration 0008):
$pdo->exec("INSERT INTO VSKR_product_categories (shop_id, external_id, category) VALUES
    (1,'A1','Soulblight Gravelords Age of Sigma'),
    (1,'A1','Age of Sigmar'),
    (1,'B1','Warpaints Fanatic')");

// --- Frontend-Pfad exakt nachbilden (GET -> resolve -> query) ------------------
$prodRepo = new ProductRepository(new Versandkostenretter\Database(['db' => [
    'dsn' => getenv('VSKR_TEST_DB_DSN'),
    'host' => 'unused', 'name' => 'unused',
    'user' => getenv('VSKR_TEST_DB_USER'),
    'pass' => getenv('VSKR_TEST_DB_PASSWORD'),
]]));
$importRepo = new Versandkostenretter\Import\ImportRepository($pdo);

$missing = 500;            // 5.00
$maxPrice = $missing + 200; // Default-Fenster 5.00–7.00
$memberships = $importRepo->categoryMemberships(1);
if ($memberships === []) { $memberships = null; }

echo "== TRACE ==\n";
echo 'memberships geladen: ' . (is_array($memberships) ? count($memberships) . ' external_ids' : 'NULL') . "\n";

// SO WIE index.php ES AKTUELL MACHT: Dropdown-Basis OHNE Memberships:
$categoriesAsIndex = $prodRepo->distinctCategories(1);
echo 'distinctCategories wie index.php (ohne Memberships): ' . json_encode($categoriesAsIndex, JSON_UNESCAPED_UNICODE) . "\n";
// SO SOLL ES SEIN:
$categoriesCorrect = $prodRepo->distinctCategories(1, $memberships);
echo 'distinctCategories mit Memberships:                  ' . json_encode($categoriesCorrect, JSON_UNESCAPED_UNICODE) . "\n";

// Production-Beweis: mit der ALTEN Basis (ohne Memberships) resolved die
// Collection-Kategorie zu null -> Filter wirkungslos:
$oldResolved = CategoryFilter::resolve($categoriesAsIndex, 'Soulblight Gravelords Age of Sigma');
$check('ROOT-CAUSE-Beweis: alte Basis (ohne Memberships) resolved Soulblight zu null',
    $oldResolved === null, var_export($oldResolved, true));
// GEFIXTE Basis: resolve liefert den exakten gespeicherten Wert:
$fixedResolved = CategoryFilter::resolve($categoriesCorrect, 'Soulblight Gravelords Age of Sigma');
$check('Fix-Beweis: neue Basis (mit Memberships) resolves exakt',
    $fixedResolved === 'Soulblight Gravelords Age of Sigma', var_export($fixedResolved, true));

$trace = static function (string $label, string $get) use ($categoriesCorrect, $prodRepo, $memberships, $missing, $maxPrice): array {
    $resolved = CategoryFilter::resolve($categoriesCorrect, $get);
    echo "\n[{$label}] GET category=" . var_export($get, true)
        . ' -> resolved=' . var_export($resolved, true) . "\n";
    $rows = $prodRepo->eligibleProducts(1, $missing, 24, $resolved, $maxPrice, $memberships);
    $ids = array_column($rows, 'external_id');
    sort($ids);
    echo '    Ergebnismenge: ' . json_encode($ids) . "\n";
    return $ids;
};

echo "\n== CASES (gefixt: resolve-Basis mit Memberships) ==" . PHP_EOL;
$rAlle = $trace('1. Alle/ohne', '');
$rA = $trace('2. Soulblight', 'Soulblight Gravelords Age of Sigma');
$rB = $trace('3. Warpaints', 'Warpaints Fanatic');
$rInvalid = $trace('4. invalid', 'Gibts-Nicht-Kategorie');

$check('1. Alle: A, B, C erscheinen', $rAlle === ['A1', 'B1', 'C1'], json_encode($rAlle));
$check('2. category=Soulblight: NUR A', $rA === ['A1'], json_encode($rA));
$check('3. category=Warpaints: NUR B', $rB === ['B1'], json_encode($rB));
$check('4. invalid: fällt sicher auf Alle zurück (A,B,C), nicht auf fremde Kategorie',
    $rInvalid === ['A1', 'B1', 'C1'], json_encode($rInvalid));

// 5. selected category überlebt in URL/Form: statisch geprüft (GET->GET link).
$resultsTpl = (string) file_get_contents($repoRoot . '/templates/results.php');
$check('5. selected category bleibt in Form/URL erhalten (Template nutzt selectedCategory)',
    str_contains($resultsTpl, 'selectedCategory'));

// 6. Preisfenster + Kategorie komponieren (erweitertes Fenster → A + B + C bleibt, aber Kategorie schneidet):
$rA2 = (static function () use ($prodRepo, $categoriesCorrect, $memberships, $missing) {
    $resolved = CategoryFilter::resolve($categoriesCorrect, 'Soulblight Gravelords Age of Sigma');
    $rows = $prodRepo->eligibleProducts(1, $missing, 24, $resolved, $missing + 100, $memberships); // Fenster 5.00-6.00
    $ids = array_column($rows, 'external_id');
    sort($ids);
    return $ids;
})();
$check('6. Preisfenster UND Kategorie komponieren (Soulblight + 5.00–6.00 = A1)',
    $rA2 === ['A1'], json_encode($rA2));

echo "\nFrontend-Kategorie (MariaDB): {$checks} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
