<?php

declare(strict_types=1);

/**
 * VSKR_product_categories (many-to-many memberships) regression tests.
 *
 * Covers:
 *  - multiple collections per product survive persistence
 *  - membership replace removes stale memberships (no leftovers)
 *  - frontend filtering resolves categories via memberships
 *  - shops without the migration/table keep working (fail-soft)
 *  - replaceCategoryMemberships owns NO transaction boundaries
 *    (the import transaction owner commits them)
 *  - category filter composes with price window and availability
 */

require __DIR__ . '/../../src/Money.php';
require __DIR__ . '/../../src/Cart.php';
require __DIR__ . '/../../src/View.php';
require __DIR__ . '/../../src/Database.php';
require __DIR__ . '/../../src/ProductRepository.php';
require __DIR__ . '/../../src/Import/ImportRepository.php';

use Versandkostenretter\Database;
use Versandkostenretter\ProductRepository;
use Versandkostenretter\Import\ImportRepository;

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

$tmp = sys_get_temp_dir() . '/vskr-memberships-' . bin2hex(random_bytes(4));
@mkdir($tmp, 0777, true);
$dbPath = $tmp . '/db.sqlite';

// Production-shaped minimal schema (SQLite for the test harness).
$pdo = new PDO('sqlite:' . $dbPath);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE VSKR_shops (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    slug TEXT NOT NULL UNIQUE,
    website_url TEXT NOT NULL,
    shipping_cost NUMERIC NOT NULL,
    free_shipping_threshold NUMERIC NOT NULL,
    active INTEGER NOT NULL DEFAULT 1
)');
$pdo->exec('CREATE TABLE VSKR_products (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    shop_id INTEGER NOT NULL,
    external_id TEXT NOT NULL,
    name TEXT NOT NULL,
    url TEXT NOT NULL,
    price NUMERIC NOT NULL,
    available INTEGER NOT NULL DEFAULT 1,
    category TEXT,
    image_url TEXT,
    last_seen_at TEXT,
    updated_at TEXT
)');
$pdo->exec('CREATE TABLE VSKR_product_categories (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    shop_id INTEGER NOT NULL,
    external_id TEXT NOT NULL,
    category TEXT NOT NULL,
    updated_at TEXT
)');
$pdo->exec('CREATE UNIQUE INDEX uq_vskr_prodcat ON VSKR_product_categories (shop_id, external_id, category)');
$pdo->exec("INSERT INTO VSKR_shops (id,name,slug,website_url,shipping_cost,free_shipping_threshold,active)
VALUES (1,'Lootforge','lootforge','https://lootforge.example',5.99,100.00,1)");

$ins = $pdo->prepare('INSERT INTO VSKR_products (shop_id,external_id,name,url,price,available,category)
VALUES (1,?,?,?,?,?,?)');
// Multi-membership products straddling the 5.00–7.00 window (cart 95.00):
$ins->execute(['E1', 'Multi A', 'https://s.example/e1', 5.50, 1, '']);            // in window, 2 collections
$ins->execute(['E2', 'Multi B', 'https://s.example/e2', 6.50, 1, '']);            // out of window, 2 collections
$ins->execute(['E3', 'Single C', 'https://s.example/e3', 5.20, 1, 'Farben Zubehör']); // in window, col category
$ins->execute(['E4', 'Unavail D', 'https://s.example/e4', 5.00, 0, '']);          // unavailable, in window
$ins->execute(['E5', 'Expensive E', 'https://s.example/e5', 35.00, 1, '']);       // expanded only

$repo = new ImportRepository($pdo);
$prodRepo = new ProductRepository(new Database(['db' => ['dsn' => 'sqlite:' . $dbPath, 'host' => 'u', 'name' => 'u', 'user' => 'u', 'pass' => 'u']]));

/* 1. Multiple collections per product survive persistence */
$n = $repo->replaceCategoryMemberships(1, [
    'E1' => ['Soulblight Gravelords', 'Age of Sigmar'],
    'E2' => ['Soulblight Gravelords', 'Games Workshop'],
    'E5' => ['Sales'],
]);
$check('multi-membership replace persists all rows', $n === 5, "inserted={$n}");

$map = $repo->categoryMemberships(1);
// Union map: membership ids + the column-category product (E3).
$check('membership map has all external ids (incl. column-only E3)', count($map) === 4, 'got ' . count($map));
$e1 = $map['E1'] ?? [];
sort($e1);
$check('product keeps BOTH collections', $e1 === ['Age of Sigmar', 'Soulblight Gravelords'],
    json_encode($e1));

/* 2. Stale memberships removed on replace */
$n2 = $repo->replaceCategoryMemberships(1, ['E1' => ['Only One Now']]);
$map2 = $repo->categoryMemberships(1);
$check('replace removes stale memberships (E2/E5 gone, E3 column remains)',
    count($map2) === 2 && !isset($map2['E2'], $map2['E5']) && isset($map2['E3']));
$check('replace updates remaining membership', ($map2['E1'] ?? []) === ['Only One Now']);

/* 3. Frontend filtering via memberships + composition with window/availability */
$repo->replaceCategoryMemberships(1, [
    'E1' => ['Pinsel'],
    'E2' => ['Pinsel'],
    'E5' => ['Pinsel'],
]);
// Default window 5.00–7.00 + category Pinsel: only E1 (5.50). E2 (6.50) IS in
// window — wait, 6.50 <= 7.00, so E2 qualifies too. E5 only in expanded.
$r = $prodRepo->eligibleProducts(1, 500, 24, 'Pinsel', 700,
    $repo->categoryMemberships(1));
$names = array_column($r, 'name');
sort($names);
$check('membership category + window composes (E1+E2)', $names === ['Multi A', 'Multi B'], json_encode($names));

$r = $prodRepo->eligibleProducts(1, 500, 24, 'Pinsel', null,
    $repo->categoryMemberships(1));
$names = array_column($r, 'name');
$check('membership category EXPANDED includes 35.00', in_array('Expensive E', $names, true));
$check('membership category EXPANDED excludes unavailable', !in_array('Unavail D', $names, true));

// Column-category fallback still works when memberships carry the value:
$r = $prodRepo->eligibleProducts(1, 500, 24, 'Farben Zubehör', 700,
    $repo->categoryMemberships(1));
$check('single-column category still filters (union fallback)',
    array_column($r, 'name') === ['Single C'], json_encode(array_column($r, 'name')));

$check('unknown category resolves to no products (safe)',
    $prodRepo->eligibleProducts(1, 500, 24, 'Gibts-Nicht', 700,
        $repo->categoryMemberships(1)) === []);

/* 4. No transaction ownership inside the helper */
$src = file_get_contents(__DIR__ . '/../../src/Import/ImportRepository.php');
$mStart = (int) strpos($src, 'public function replaceCategoryMemberships');
$mEnd = (int) strpos($src, 'public function', $mStart + 10);
$body = preg_replace(['#/\*.*?\*/#s', '/^\s*\/\/.*$/m'], '', substr($src, $mStart, $mEnd - $mStart));
$check('replaceCategoryMemberships owns no transaction boundaries',
    !preg_match('#(beginTransaction|commit\\(\\)|rollBack\\(\\))#', $body));

/* 5. Shops without the table keep working (fail-soft read) */
$bare = new PDO('sqlite:' . $tmp . '/bare.sqlite');
$bare->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$bare->exec('CREATE TABLE VSKR_shops (id INTEGER PRIMARY KEY, slug TEXT)');
$bareRepo = new ImportRepository($bare);
$check('missing memberships table reads fail-soft to []',
    $bareRepo->categoryMemberships(1) === []);

/* 6. Distinct categories union (memberships + column) */
$cats = array_column($prodRepo->visibleCategories(1, 500, 700, $repo->categoryMemberships(1)), 'name');
sort($cats);
$check('distinct categories include membership-only categories',
    in_array('Pinsel', $cats, true) && in_array('Farben Zubehör', $cats, true),
    json_encode($cats));

@unlink($dbPath);
@unlink($tmp . '/bare.sqlite');
@rmdir($tmp);

echo "\nMembership tests: {$checks} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
