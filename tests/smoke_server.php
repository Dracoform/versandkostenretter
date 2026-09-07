<?php

declare(strict_types=1);

/**
 * Local smoke-test ONLY — never deployed, never committed.
 *
 * Overrides the Database factory to serve a SQLite copy of the VSKR_
 * schema so the full HTTP flow can be verified on a machine without
 * MySQL. The production code path is unchanged (Database::fromConfigFile
 * is only swapped out here, in this wrapper).
 */

namespace VskrSmoke;

// Static file passthrough for the PHP dev server, mirroring router.php:
// protected directories and sensitive files are NEVER served.
$uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$protectedDirs = ['src', 'templates', 'tests', 'database', 'config', 'assets-design', 'bin'];
$isProtected = false;
foreach ($protectedDirs as $dir) {
    if ($uri === '/' . $dir || str_starts_with($uri, '/' . $dir . '/')) {
        $isProtected = true;
        break;
    }
}
if ($isProtected || str_contains($uri, '..')
    || preg_match('#\.(sql|md|sh|ini|log|env|php)$#', $uri) && $uri !== '/index.php'
) {
    http_response_code(404);
    exit;
}
if ($uri !== '/' && is_file(__DIR__ . '/..' . $uri)
    && basename($uri) !== 'router.php' && !preg_match('#\.(php|sql|md)$#', $uri)
) {
    return false;
}

require __DIR__ . '/../src/Money.php';
require __DIR__ . '/../src/Cart.php';
require __DIR__ . '/../src/View.php';
require __DIR__ . '/../src/Assets.php';
require __DIR__ . '/../src/Database.php';
require __DIR__ . '/../src/ShopRepository.php';
require __DIR__ . '/../src/ProductRepository.php';
require __DIR__ . '/../src/OutboundLink.php';
require __DIR__ . '/../src/CategoryFilter.php';

use Versandkostenretter\Database;

// Build in-memory SQLite database with the VSKR_ schema + dev seed (converted).
$pdo = new \PDO('sqlite::memory:');
$pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
$pdo->exec('PRAGMA foreign_keys = ON');

// --- schema (SQLite dialect, VSKR_ names preserved) ---
$pdo->exec('
CREATE TABLE VSKR_shops (
    id INTEGER PRIMARY KEY,
    name TEXT NOT NULL,
    slug TEXT NOT NULL UNIQUE,
    website_url TEXT NOT NULL,
    shipping_cost NUMERIC NOT NULL,
    free_shipping_threshold NUMERIC NOT NULL,
    active INTEGER NOT NULL DEFAULT 1,
    affiliate_enabled INTEGER NOT NULL DEFAULT 0,
    affiliate_mode TEXT,
    affiliate_param TEXT,
    affiliate_value TEXT,
    affiliate_template TEXT,
    source_type TEXT,
    source_url TEXT,
    source_scope TEXT NOT NULL DEFAULT "default",
    product_images_enabled INTEGER NOT NULL DEFAULT 0,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE VSKR_products (
    id INTEGER PRIMARY KEY,
    shop_id INTEGER NOT NULL REFERENCES VSKR_shops(id),
    external_id TEXT,
    name TEXT NOT NULL,
    url TEXT NOT NULL,
    price NUMERIC NOT NULL,
    available INTEGER NOT NULL DEFAULT 1,
    category TEXT,
    image_url TEXT,
    source_type TEXT,
    source_scope TEXT NOT NULL DEFAULT "default",
    last_seen_at TEXT,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX ix_vskr_products_shop_avail_price ON VSKR_products (shop_id, available, price);
');

// --- seed (identical values to database/seed-development.sql) ---
$shops = [
    [9001, 'Games Island (TEST DATA)', 'games-island-test', 'https://example.com/games-island', '5.99', '150.00', 1],
    [9002, 'Modellbau Hinterhof (TEST DATA)', 'modellbau-test', 'https://example.com/modellbau', '6.49', '90.00', 1],
    [9099, 'Deaktivierter Testshop (TEST DATA)', 'inaktiv-test', 'https://example.com/inaktiv', '4.99', '50.00', 0],
];
$stmt = $pdo->prepare('INSERT INTO VSKR_shops (id,name,slug,website_url,shipping_cost,free_shipping_threshold,active,product_images_enabled) VALUES (?,?,?,?,?,?,?,0)');
foreach ($shops as $s) { $stmt->execute($s); }

$products = [
    [9001, 'TEST-0001', '[TEST] Kleines Würfelset', 'https://example.com/p/1', '24.49', 1, 'Zubehör', null],
    [9001, 'TEST-0002', '[TEST] Basisspiel (Ausstellung)', 'https://example.com/p/2', '24.66', 1, 'Brettspiel', null],
    [9001, 'TEST-0003', '[TEST] Erweiterung Alpha', 'https://example.com/p/3', '24.69', 1, 'Erweiterung', null],
    [9001, 'TEST-0004', '[TEST] Erweiterung Beta', 'https://example.com/p/4', '24.99', 1, 'Erweiterung', null],
    [9001, 'TEST-0005', '[TEST] Spielmatte Standard', 'https://example.com/p/5', '25.49', 1, 'Zubehör', null],
    [9001, 'TEST-0006', '[TEST] Ausverkauftes Sonderangebot', 'https://example.com/p/6', '24.70', 0, 'Sale', null],
    [9001, 'TEST-0007', '[TEST] Kartenschutzhüllen (50)', 'https://example.com/p/7', '4.99', 1, 'Zubehör', null],
    [9002, 'TEST-2001', '[TEST] Farben-Set (ANDERER SHOP)', 'https://example.com/m/1', '24.66', 1, 'Farben', null],
    [9002, 'TEST-2002', '[TEST] Kleinteile-Box (ANDERER SHOP)', 'https://example.com/m/2', '25.00', 1, 'Werkzeug', null],
];
$stmt = $pdo->prepare('INSERT INTO VSKR_products (shop_id,external_id,name,url,price,available,category,image_url,source_type,source_scope) VALUES (?,?,?,?,?,?,?,?,"shopify","test-scope")');
foreach ($products as $p) { $stmt->execute($p); }

// Patch: give the smoke Database a SQLite PDO.
$ref = new \ReflectionClass(Database::class);
$prop = $ref->getProperty('pdo');
$prop->setAccessible(true);

$config = ['app' => ['debug' => false, 'max_results' => 24]];
$db = new Database($config);
$prop->setValue($db, $pdo);

// This smoke router re-implements the tiny routing of public/index.php 1:1
// using the same src/ classes and the SQLite copy of the VSKR_ data, so the
// full HTTP flow is exercised locally without MySQL.

$_SERVER['REQUEST_METHOD'] = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$_SERVER['REQUEST_URI'] = $_SERVER['REQUEST_URI'] ?? '/';

$route = $_GET['page'] ?? '';

header('Content-Type: text/html; charset=utf-8');

if ($route === 'datenschutz' || $route === 'impressum') {
    $pageTitle = 'Versandkostenretter — ' . ($route === 'impressum' ? 'Impressum' : 'Datenschutz');
    require __DIR__ . '/../templates/' . $route . '.php';
    exit;
}
if ($route !== '' && $route !== null) {
    http_response_code(404);
    $pageTitle = 'Seite nicht gefunden';
    require __DIR__ . '/../templates/404.php';
    exit;
}

// --- home / results (same logic as public/index.php) ---
$shopRepo = new \Versandkostenretter\ShopRepository($db);
$shops = $shopRepo->activeShops();
$errors = [];
$results = null;
$shop = null;
$cartCents = null;

if (isset($_GET['shop']) || isset($_GET['cart'])) {
    $shopSlug = trim((string) ($_GET['shop'] ?? ''));
    $cartCents = \Versandkostenretter\Money::parseToCents((string) ($_GET['cart'] ?? ''));
    if ($shopSlug === '' || !preg_match('/^[a-z0-9-]+$/i', $shopSlug)) {
        $errors[] = 'Unbekannter Shop. Bitte wähle aus der Liste.';
    } else {
        $shop = $shopRepo->findBySlug($shopSlug);
        if ($shop === null) { $errors[] = 'Unbekannter Shop. Bitte wähle aus der Liste.'; }
    }
    if ($cartCents === null) { $errors[] = 'Bitte gib deinen aktuellen Warenkorbwert ein.'; }
    if ($shop !== null && $cartCents !== null && $errors === []) {
        $missing = \Versandkostenretter\Cart::missingCents($cartCents, $shop['free_shipping_threshold_cents']);
        if (\Versandkostenretter\Cart::thresholdReached($cartCents, $shop['free_shipping_threshold_cents'])) {
            $results = ['free_reached' => true, 'products' => [], 'missing_cents' => 0];
        } else {
            $repo = new \Versandkostenretter\ProductRepository($db);
            $requestedCategory = isset($_GET['category']) ? trim((string) $_GET['category']) : '';
            if ($requestedCategory !== '') { $requestedCategory = function_exists('mb_substr') ? mb_substr($requestedCategory, 0, 190) : substr($requestedCategory, 0, 190); }
            $allProducts = $repo->eligibleProducts($shop['id'], $missing, 24);
            $total = $repo->countEligible($shop['id'], $missing);
            $categories = \Versandkostenretter\CategoryFilter::distinct($allProducts);
            if (\Versandkostenretter\CategoryFilter::isUsable($categories, $requestedCategory)) {
                $selectedCategory = $requestedCategory;
                $products = $repo->eligibleProducts($shop['id'], $missing, 24, $selectedCategory);
            } else {
                $selectedCategory = null;
                $products = $allProducts;
            }
            $results = [
                'free_reached' => false,
                'products' => $products,
                'total' => $total,
                'missing_cents' => $missing,
            ];
        }
    }
}

$isSearch = isset($_GET['shop']) || isset($_GET['cart']);
$pageTitle = $isSearch ? 'Versandkostenretter — Ergebnisse' : 'Versandkostenretter — Rette deinen Warenkorb!';
require __DIR__ . '/../templates/' . ($isSearch ? 'results.php' : 'home.php');
