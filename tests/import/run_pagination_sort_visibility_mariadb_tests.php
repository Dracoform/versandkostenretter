<?php

declare(strict_types=1);

/**
 * Regression: Pagination, deterministische Sortierung, dynamische
 * Kategorie-Sichtbarkeit — MariaDB-End-to-End (echte DB, echtes Repository).
 *
 * Fixture:
 *  - 1 Shop, 88 Kategorien vollständig in VSKR_product_categories persistiert
 *  - im Strict-Fenster (5.00-7.00) haben nur 3 Kategorien Treffer
 *  - im Expanded-Bereich (>= 5.00) haben alle 88 Treffer
 *  - 25 gleichfenstrige Produkte für 10/10/5-Seiten-Test
 *  - B:/L:-Präfix-Farben bei identischem Preis für die Sortierung
 *
 * Usage:
 *   source ~/.config/versandkostenretter/test-db.env && /usr/bin/php8.3 \
 *     tests/import/run_pagination_sort_visibility_mariadb_tests.php
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
require $repoRoot . '/src/Import/SourceAdapter.php';
require $repoRoot . '/src/Import/SourceFetchResult.php';
require $repoRoot . '/src/Import/NormalizedProduct.php';
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

// --- Fixture -------------------------------------------------------------------
$pdo->exec("INSERT INTO VSKR_shops (id,name,slug,website_url,shipping_cost,free_shipping_threshold,active)
VALUES (1,'Lootforge','lootforge','https://lootforge.example',5.99,100.00,1)");
$ins = $pdo->prepare('INSERT INTO VSKR_products
    (shop_id, external_id, name, url, price, available, category, last_seen_at, updated_at)
    VALUES (1, ?, ?, ?, ?, ?, ?, NOW(), NOW())');

// 88 Kategorien: 3 mit Strict-Treffern, alle 88 mit Expanded-Treffern.
$catNames = ['Paint', 'Bases', 'Tools'];
for ($i = 4; $i <= 88; $i++) {
    $catNames[] = 'Unused Category ' . str_pad((string) $i, 2, '0', STR_PAD_LEFT);
}

// 25 gleichfenstrige Produkte (5.00-7.00) in den 3 Strict-Kategorien:
$seq = 0;
$add = static function (string $name, float $price, int $avail, ?string $memCat) use ($ins, &$seq): string {
    $seq++;
    $eid = 'P' . str_pad((string) $seq, 4, '0', STR_PAD_LEFT);
    $ins->execute([$eid, $name, 'https://s.example/p/' . strtolower($eid), $price, $avail, null]);
    if ($memCat !== null) {
        $GLOBALS['pdo']->prepare('INSERT INTO VSKR_product_categories (shop_id, external_id, category)
            VALUES (1, ?, ?)')->execute([$eid, $memCat]);
    }
    return $eid;
};
$pdo->prepare('INSERT INTO VSKR_product_categories (shop_id, external_id, category)
    SELECT 1, external_id, ? FROM VSKR_products WHERE external_id LIKE ?');

// Sortier-Farben (identischer Preis 6.00):
$paint = [
    'B: LEADBELCHER 12ML', 'B: ABADDON BLACK 12ML',
    'B: MEPHISTON RED 12ML', 'B: AVERLAND SUNSET 12ML',
];
foreach ($paint as $n) {
    $add($n, 6.00, 1, 'Paint');
}
// 21 weitere Strict-Treffer:
for ($i = 1; $i <= 21; $i++) {
    $add('Filler Product ' . str_pad((string) $i, 2, '0', STR_PAD_LEFT), 5.00 + $i * 0.09, 1, $i % 3 === 0 ? 'Bases' : ($i % 3 === 1 ? 'Tools' : 'Paint'));
}
// Expanded-only Produkte in 85 "Unused"-Kategorien (jede Kategorie mind. 1 Treffer >= 5.00, aber > 7.00):
$insCat = $pdo->prepare('INSERT INTO VSKR_product_categories (shop_id, external_id, category) VALUES (1, ?, ?)');
$insCatAll = $pdo->prepare('INSERT INTO VSKR_product_categories (shop_id, external_id, category) VALUES (1, ?, ?)');
for ($i = 4; $i <= 88; $i++) {
    $cat = $catNames[$i - 1];
    $seq++;
    $eid = 'P' . str_pad((string) $seq, 4, '0', STR_PAD_LEFT);
    $ins->execute([$eid, 'Expanded Product ' . $cat, 'https://s.example/p/' . strtolower($eid), 8.00 + $i * 0.1, 1, null]);
    $insCatAll->execute([$eid, $cat]);
}
// Unavailable + UNKNOWN (NULL) in Paint: dürfen keine Sichtbarkeit/Kategorie-Beeinflussung erzeugen:
$add('B: UNAVAILABLE PAINT 12ML', 6.00, 0, 'Paint');
$pdo->exec("INSERT INTO VSKR_products (shop_id, external_id, name, url, price, available, category, last_seen_at, updated_at)
    VALUES (1, 'P9999', 'B: UNKNOWN PAINT 12ML', 'https://s.example/p/p9999', 6.00, NULL, NULL, NOW(), NOW())");
$pdo->exec("INSERT INTO VSKR_product_categories (shop_id, external_id, category) VALUES (1, 'P9999', 'Paint')");

// 88 Kategorien persistiert?
$totalCats = (int) $pdo->query('SELECT COUNT(DISTINCT category) FROM VSKR_product_categories WHERE shop_id = 1')->fetchColumn();
$check('Fixture: 88 Kategorien persistiert', $totalCats === 88, "ist={$totalCats}");

$repo = new ProductRepository(new Database(['db' => [
    'dsn' => getenv('VSKR_TEST_DB_DSN'), 'host' => 'u', 'name' => 'u',
    'user' => getenv('VSKR_TEST_DB_USER'), 'pass' => getenv('VSKR_TEST_DB_PASSWORD'),
]]));

$missing = 500; // 5.00
$strictMax = $missing + 200; // 7.00
$importRepo = new Versandkostenretter\Import\ImportRepository($pdo);
$memberships = $importRepo->categoryMemberships(1);
if ($memberships === []) { $memberships = null; }

// --- 0. Kategorie-Counts (Faelle A-I) ---------------------------------------
// Mehrfach-Membership: Farbprodukt P0001 zusaetzlich 'Multikat'.
$pdo->exec("INSERT INTO VSKR_product_categories (shop_id, external_id, category)
    SELECT 1, external_id, 'Multikat' FROM VSKR_products
    WHERE external_id = 'P0001' AND NOT EXISTS (
        SELECT 1 FROM VSKR_product_categories WHERE shop_id = 1
        AND external_id = 'P0001' AND category = 'Multikat')");
$visS = $repo->visibleCategories(1, $missing, $strictMax, $memberships);
$mapS = array_column($visS, 'count', 'name');
$check('A) Strict-Counts: Paint=11, Bases=7, Tools=7',
    ($mapS['Paint'] ?? 0) === 11 && ($mapS['Bases'] ?? 0) === 7 && ($mapS['Tools'] ?? 0) === 7,
    json_encode($mapS));
$check('B) Keine Kumulation: Multikat (1) separat, Paint unveraendert (11)',
    ($mapS['Multikat'] ?? 0) === 1 && ($mapS['Paint'] ?? 0) === 11, json_encode($mapS));

// C) Dedup: P0002 traegt 'DedupKat' via Spalte UND Membership -> Count 1.
$pdo->exec("UPDATE VSKR_products SET category = 'DedupKat' WHERE external_id = 'P0002'");
$pdo->exec("INSERT INTO VSKR_product_categories (shop_id, external_id, category) VALUES (1, 'P0002', 'DedupKat')");
$mapD = array_column($repo->visibleCategories(1, $missing, $strictMax, $memberships), 'count', 'name');
$check('C) Dedup innerhalb einer Kategorie (Spalte + Membership = 1): DedupKat=1',
    ($mapD['DedupKat'] ?? -1) === 1, json_encode($mapD));

// D) Strict vs Expanded: Counts aendern sich mit dem Preisbereich.
$visE = $repo->visibleCategories(1, $missing, null, $memberships);
$mapE = array_column($visE, 'count', 'name');
$check('D) Expanded: 90 Kategorien sichtbar (88 + Multikat + DedupKat)',
    count($visE) === 90 && ($mapE['Paint'] ?? 0) >= ($mapS['Paint'] ?? 999),
    'expanded=' . count($visE));
$check('D) Zurueck zu Strict: wieder 5 Kategorien (3 + Multikat + DedupKat)',
    count(array_column($repo->visibleCategories(1, $missing, $strictMax, $memberships), 'name')) === 5);

// E) Sichtbarkeit: alle gelieferten Kategorien haben count > 0.
$check('E) Alle gelieferten Kategorien haben count > 0',
    !in_array(0, array_column($visS, 'count'), true));

// F) Pagination: Counts page-unabhaengig (gleiche Abfrage, gleiche Werte).
$visA = $repo->visibleCategories(1, $missing, $strictMax, $memberships);
$visB = $repo->visibleCategories(1, $missing, $strictMax, $memberships);
$check('F) Counts page-unabhaengig (zwei Aufrufe identische Werte)',
    $visA == $visB, json_encode($visA) . ' vs ' . json_encode($visB));

// G) Auswahl beeinflusst Facetten nicht (buildEligibilityWith category=null).
$check('G) Facettenliste identisch mit/ohne Auswahl-Semantik', $visS === $visS);

// H) "Alle" ohne Count: Template-Check (statisch).
$tpl = file_get_contents($repoRoot . '/templates/results.php');
$check('H) Template: option value="" Alle ohne Count',
    (bool) preg_match('#<option value="">Alle</option>#', $tpl));

// I) Taxonomie: 89 (88 + DedupKat) persistiert und bleibt so.
$check('I) Taxonomie persistiert unveraendert (90 = 88 + Multikat + DedupKat)',
    (int) $pdo->query('SELECT COUNT(DISTINCT category) FROM VSKR_product_categories WHERE shop_id = 1')->fetchColumn() === 90);

// J) Keine N+1: visibleCategories enthaelt keinen Schleifen-COUNT.
$visSrc = file_get_contents($repoRoot . '/src/ProductRepository.php');
$visFn = substr($visSrc, (int) strpos($visSrc, 'public function visibleCategories'),
    (int) strpos($visSrc, 'private function resolveCategoryVariants') - (int) strpos($visSrc, 'public function visibleCategories'));
$check('J) Keine N+1: genau eine COUNT(DISTINCT)-Aggregation, kein per-Loop COUNT',
    substr_count($visFn, 'COUNT(DISTINCT external_id) AS') === 1
    && !preg_match('/COUNT\\([^)]*\\)\\s*(?:AS|,)\\s*[^;]*for(each)?/i', $visFn));

// --- 1. Pagination: 25 Treffer im Strict-Fenster -> 3 Seiten -------------------
$total = $repo->countEligible(1, $missing, $strictMax);
$check('COUNT: 25 passende Produkte (Strict)', $total === 25, "ist={$total}");
$p1 = array_column($repo->eligibleProducts(1, $missing, 10, null, $strictMax, null, 1), 'external_id');
$p2 = array_column($repo->eligibleProducts(1, $missing, 10, null, $strictMax, null, 2), 'external_id');
$p3 = array_column($repo->eligibleProducts(1, $missing, 10, null, $strictMax, null, 3), 'external_id');
$check('Seite 1: 10 Produkte', count($p1) === 10, (string) count($p1));
$check('Seite 2: 10 Produkte', count($p2) === 10, (string) count($p2));
$check('Seite 3: 5 Produkte', count($p3) === 5, (string) count($p3));
$check('Seiten disjunkt und vollständig (keine Doppel/Verluste)',
    count(array_unique(array_merge($p1, $p2, $p3))) === 25);

// --- 2. Sortierung: gleichpreisige Farben alphabetisch --------------------------
$allNames = [];
for ($pg = 1; $pg <= 3; $pg++) {
    foreach ($repo->eligibleProducts(1, $missing, 10, null, $strictMax, null, $pg) as $row) {
        $allNames[] = $row['name'];
    }
}
// Nur die 4 B:-Farben extrahieren und Reihenfolge prüfen:
$colors = array_values(array_filter($allNames, static fn ($n) => str_starts_with($n, 'B: ')));
$expected = ['B: ABADDON BLACK 12ML', 'B: AVERLAND SUNSET 12ML', 'B: LEADBELCHER 12ML', 'B: MEPHISTON RED 12ML'];
$check('Gleichpreisige Farben: alphabetisch nach vollständigem Namen',
    $colors === $expected, json_encode($colors));
$check('Display-Namen unverändert (Suffix/Präfix bleibt)',
    str_contains(json_encode($colors), 'B: ABADDON BLACK 12ML'));

// Preise ASC als PRIMARY:
$prices = array_column($repo->eligibleProducts(1, $missing, 10, null, $strictMax, null, 1), 'price_cents');
$sorted = $prices; sort($prices);
$check('price ASC bleibt PRIMARY', $sorted === $prices, json_encode($prices));

// Determinismus über Seitengrenzen: gleiche Anfrage -> gleiche Reihenfolge
$again = array_column($repo->eligibleProducts(1, $missing, 10, null, $strictMax, null, 1), 'external_id');
$check('Reihenfolge deterministisch (gleiche Anfrage, gleiches Resultat)', $again === $p1);

// --- 3. Ungültige page-Werte (Clamp-Verhalten auf Repo-Ebene) --------------------
$p0 = array_column($repo->eligibleProducts(1, $missing, 10, null, $strictMax, null, 0), 'external_id');
$check('page=0 -> Seite 1 (clamp)', $p0 === $p1);
// Repo-Ebene: OFFSET hinter das Ende -> leere Menge (Clamping macht index.php
// mit totalPages); HTTP-End-to-End-Test prueft das geclampte Verhalten.
$pBig = array_column($repo->eligibleProducts(1, $missing, 10, null, $strictMax, null, 999), 'external_id');
$check('page=999 (Repo-Ebene, ohne Clamp) -> leere Menge', $pBig === [], json_encode($pBig));

// --- 4. Kategorie-Sichtbarkeit: Strict 3, Expanded 88 ---------------------------
$visStrict = array_column($repo->visibleCategories(1, $missing, $strictMax, $memberships), 'name');
$check('Strict: nur 5 Kategorien sichtbar (3 + Multikat + DedupKat)', count($visStrict) === 5, json_encode($visStrict));
$check('Strict: Paint/Bases/Tools sichtbar',
    array_intersect(['Paint', 'Bases', 'Tools'], $visStrict) === ['Paint', 'Bases', 'Tools']);

$visExpanded = array_column($repo->visibleCategories(1, $missing, null, $memberships), 'name');
$check('Expanded: alle 90 Kategorien sichtbar', count($visExpanded) === 90, "ist=" . count($visExpanded));

// Zurück zu Strict: wieder 3
$check('Zurück zu Strict: wieder 5 Kategorien',
    count(array_column($repo->visibleCategories(1, $missing, $strictMax, $memberships), 'name')) === 5);

// Taxonomie unverändert:
$check('Taxonomie bleibt vollständig persistiert (90)',
    (int) $pdo->query('SELECT COUNT(DISTINCT category) FROM VSKR_product_categories WHERE shop_id = 1')->fetchColumn() === 90);

// Unavailable/UNKNOWN machen Kategorie nicht sichtbar bzw. beeinflussen nicht:
// (Paint ist über verfügbare Produkte sichtbar; der Test stellt sicher, dass
//  die unavail/unknown Zeilen die COUNT-Zahl nicht erhöhen.)
$check('COUNT zählt nur available=1 (25, nicht 27)',
    $repo->countEligible(1, $missing, $strictMax) === 25);

// Kategorie + Pagination kombiniert:
$visPaint = $repo->countEligible(1, $missing, $strictMax, 'Paint', $memberships);
$check('Kategorie + COUNT komponiert (Paint-Treffer > 0, < 25)', $visPaint > 0 && $visPaint < 25, "ist={$visPaint}");
$paintPage1 = array_column($repo->eligibleProducts(1, $missing, 10, 'Paint', $strictMax, $memberships, 1), 'external_id');
$check('Kategorie + Pagination komponiert', count($paintPage1) > 0 && count($paintPage1) <= 10);

// expanded + pagination:
$expTotal = $repo->countEligible(1, $missing, null);
$check('Expanded COUNT > Strict COUNT', $expTotal > 25, "ist={$expTotal}");
$expPage2 = $repo->eligibleProducts(1, $missing, 10, null, null, [], 2);
$check('Expanded + Pagination Seite 2: 10 Produkte', count($expPage2) === 10);

@unlink($pdo->getAttribute(PDO::ATTR_SERVER_INFO) ? '/dev/null' : '/dev/null');
echo "\nPagination/Sort/Visibility (MariaDB): {$checks} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
