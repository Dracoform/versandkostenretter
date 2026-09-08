<?php

declare(strict_types=1);

/**
 * MariaDB integration test: Shopify collection memberships end-to-end.
 *
 * Exercises the REAL persistence path with production semantics:
 *   fixture adapter (SourceFetchResult with memberships)
 *     -> SourceAdapterRegistry -> ImportOrchestrator -> ImportRepository
 *     -> MariaDB -> VSKR_product_categories (asserted against MariaDB itself)
 *
 * The repository/orchestrator/database are NOT mocked — only the HTTP layer
 * is simulated by a fixture adapter, which is the correct seam.
 *
 * ENVIRONMENT (this host):
 *   source ~/.config/versandkostenretter/test-db.env
 *   (provides VSKR_TEST_DB_DSN / VSKR_TEST_DB_USER / VSKR_TEST_DB_PASSWORD)
 *
 *   MUST be run with /usr/bin/php8.3 — the only PHP on this host with
 *   PDO + pdo_mysql. The static ~/.local/bin/php build has NO PDO.
 *
 * The versandkostenretter_test database is DISPOSABLE: this test drops and
 * recreates all VSKR_ tables from database/schema.sql. Credentials stay in
 * ~/.config/ and must NEVER be committed or printed.
 *
 * Usage:
 *   source ~/.config/versandkostenretter/test-db.env && /usr/bin/php8.3 \
 *     tests/import/run_membership_mariadb_tests.php
 *
 * Skips cleanly when the VSKR_TEST_DB_* environment is unavailable.
 */

if (getenv('VSKR_TEST_DB_DSN') === false
    || getenv('VSKR_TEST_DB_USER') === false
    || getenv('VSKR_TEST_DB_PASSWORD') === false) {
    echo "SKIP  MariaDB membership integration: VSKR_TEST_DB_* not set.\n";
    echo "      Load them with: source ~/.config/versandkostenretter/test-db.env\n";
    echo "      and run with /usr/bin/php8.3 (needs PDO + pdo_mysql).\n";
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
require $repoRoot . '/src/Import/SourceAdapterRegistry.php';
require $repoRoot . '/src/Import/SourceFetchResult.php';
require $repoRoot . '/src/Import/NormalizedProduct.php';
require $repoRoot . '/src/Import/ImportRepository.php';
require $repoRoot . '/src/Import/ImportOrchestrator.php';

use Versandkostenretter\Import\ImportOrchestrator;
use Versandkostenretter\Import\ImportRepository;
use Versandkostenretter\Import\NormalizedProduct;
use Versandkostenretter\Import\SourceAdapter;
use Versandkostenretter\Import\SourceAdapterRegistry;
use Versandkostenretter\Import\SourceFetchResult;

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

// --- Connect (never print credentials) --------------------------------------
try {
    $pdo = new PDO(getenv('VSKR_TEST_DB_DSN'), getenv('VSKR_TEST_DB_USER'), getenv('VSKR_TEST_DB_PASSWORD'), [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
} catch (PDOException $e) {
    echo "SKIP  MariaDB membership integration: cannot connect ({$e->getCode()}).\n";
    exit(0);
}
$pdo->exec('SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci');

// --- Reset the disposable DB from the REAL schema ----------------------------
$stmts = static function (string $sql) use ($pdo): void {
    // Strip '--' comment lines BEFORE splitting: prose comments may contain
    // semicolons that would otherwise split statements mid-way.
    $clean = implode("\n", array_filter(
        explode("\n", $sql),
        static fn (string $l): bool => !str_starts_with(ltrim($l), '--')
    ));
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

$check('MariaDB ' . $pdo->getAttribute(PDO::ATTR_SERVER_VERSION)
    . ': VSKR schema reset incl. VSKR_product_categories',
    (bool) $pdo->query("SHOW TABLES LIKE 'VSKR_product_categories'")->fetchColumn());

// --- Fixture shop + catalogue -------------------------------------------------
$pdo->exec("INSERT INTO VSKR_shops (id,name,slug,website_url,shipping_cost,free_shipping_threshold,active,source_type)
VALUES (1,'Lootforge','lootforge','https://lootforge.example',5.99,100.00,1,'shopify')");
$ins = $pdo->prepare("INSERT INTO VSKR_products
    (shop_id, external_id, name, url, price, available, category, source_type, source_scope, last_seen_at, updated_at)
    VALUES (1, ?, ?, ?, ?, ?, ?, 'shopify', 'complete', NOW(), NOW())");
foreach ([
    ['978001', 'Product 978001', 'https://lootforge.example/products/soulblight-gravelords-age-of-sigma', 24.66, 1],
    ['978002', 'Product 978002', 'https://lootforge.example/products/redgrasgames-hydration-paper', 6.50, 1],
    ['978003', 'Product 978003', 'https://lootforge.example/products/unavailable-product', 4.99, 0],
] as [$eid, $name, $url, $price, $avail]) {
    $ins->execute([$eid, $name, $url, $price, $avail, '']);
}

$mkProduct = static fn (string $eid, string $handle, float $price, int $avail): NormalizedProduct =>
    new NormalizedProduct(
        externalId: $eid,
        name: 'Product ' . $eid,
        canonicalUrl: 'https://lootforge.example/products/' . $handle,
        priceCents: (int) round($price * 100),
        availabilityState: $avail === 1 ? 'available' : 'unavailable',
        category: null,
        imageUrl: null,
        sourceUpdatedAt: null,
    );

$fullMap = [
    '978001' => ['Soulblight Gravelords', 'Age of Sigmar'],
    '978002' => ['Age of Sigmar'],
];

/** Fixture adapter returning a configurable SourceFetchResult (HTTP seam). */
$mkAdapter = static function (SourceFetchResult $result) use ($mkProduct): SourceAdapter {
    return new class ($result, $mkProduct) implements SourceAdapter {
        public function __construct(
            private SourceFetchResult $result,
            private Closure $mkProduct,
        ) {}

        public function type(): string
        {
            return 'shopify'; // same type as production so upsert scope matches the seeded rows
        }

        public function fetchAll(array $shop): SourceFetchResult
        {
            // Keep products fresh so the orchestrator's upsert sees valid rows.
            $products = [
                ($this->mkProduct)('978001', 'soulblight-gravelords-age-of-sigma', 24.66, 1),
                ($this->mkProduct)('978002', 'redgrasgames-hydration-paper', 6.50, 1),
                ($this->mkProduct)('978003', 'unavailable-product', 4.99, 0),
            ];
            return new SourceFetchResult(
                $products,
                [],
                complete: $this->result->complete,
                requests: $this->result->requests,
                categoryMemberships: $this->result->categoryMemberships,
                enrichmentWarnings: $this->result->enrichmentWarnings,
            );
        }
    };
};

$repo = static fn (): ImportRepository => new ImportRepository($pdo);
$mkOrchestrator = static function (SourceFetchResult $result) use ($repo, $mkAdapter): ImportOrchestrator {
    $orchestrator = new ImportOrchestrator(
        new SourceAdapterRegistry($mkAdapter($result)),
        $repo()
    );
    return $orchestrator;
};

$shop = ['id' => 1, 'name' => 'Lootforge', 'slug' => 'lootforge', 'source_type' => 'shopify', 'source_scope' => 'complete'];

// --- 1. Successful enrichment end-to-end --------------------------------------
$result = $mkOrchestrator(new SourceFetchResult([], [], complete: true, requests: 99,
    categoryMemberships: $fullMap))->import($shop);
$check('orchestrator ran complete import (stale marking active)',
    ($result['stale_marked'] ?? false) === true && ($result['errors'] ?? 1) === 0,
    json_encode(array_diff_key($result, ['skipped_items' => 1, 'mapped' => 1])));
$check('orchestrator reports 3 persisted membership rows',
    ($result['memberships'] ?? 0) === 3, 'memberships=' . ($result['memberships'] ?? 'none'));

$count = (int) $pdo->query('SELECT COUNT(*) FROM VSKR_product_categories')->fetchColumn();
$check('MariaDB: non-zero membership rows persisted', $count === 3, "count={$count}");

$row = $pdo->query("SELECT category FROM VSKR_product_categories
    WHERE shop_id = 1 AND external_id = '978001' ORDER BY category")->fetchAll(PDO::FETCH_COLUMN);
$check('MariaDB: product 978001 carries BOTH collections (multi-membership)',
    $row === ['Age of Sigmar', 'Soulblight Gravelords'], json_encode($row));

$row2 = $pdo->query("SELECT category FROM VSKR_product_categories
    WHERE shop_id = 1 AND external_id = '978002'")->fetchAll(PDO::FETCH_COLUMN);
$check('MariaDB: product 978002 carries its single collection', $row2 === ['Age of Sigmar'], json_encode($row2));

// --- 2. Second import is idempotent --------------------------------------------
$result2 = $mkOrchestrator(new SourceFetchResult([], [], complete: true, requests: 99,
    categoryMemberships: $fullMap))->import($shop);
$count2 = (int) $pdo->query('SELECT COUNT(*) FROM VSKR_product_categories')->fetchColumn();
$check('MariaDB: second import idempotent (no duplicate rows)', $count2 === $count, "count={$count2}");
$check('MariaDB: second import replaced (not skipped)', isset($result2['memberships']));

// --- 3. Stale membership synchronization ----------------------------------------
$mkOrchestrator(new SourceFetchResult([], [], complete: true, requests: 99,
    categoryMemberships: ['978001' => ['Age of Sigmar']]))->import($shop);
$row3 = $pdo->query("SELECT category FROM VSKR_product_categories
    WHERE shop_id = 1 AND external_id = '978001'")->fetchAll(PDO::FETCH_COLUMN);
$check('MariaDB: removed collection does not remain stale', $row3 === ['Age of Sigmar'], json_encode($row3));
$count3 = (int) $pdo->query('SELECT COUNT(*) FROM VSKR_product_categories')->fetchColumn();
$check('MariaDB: stale 978002 membership removed (authoritative replace)', $count3 === 1, "count={$count3}");

// --- 4. Failed enrichment must NOT destroy known-good memberships ----------------
$result4 = $mkOrchestrator(new SourceFetchResult([], [], complete: true, requests: 97,
    categoryMemberships: null,
    enrichmentWarnings: ['collection discovery failed: invalid /collections.json response']))->import($shop);
$count4 = (int) $pdo->query('SELECT COUNT(*) FROM VSKR_product_categories')->fetchColumn();
$check('MariaDB: failed enrichment KEEPS known-good memberships', $count4 === 1, "count={$count4}");
$check('MariaDB: failed enrichment reported (not silent success)',
    ($result4['memberships_skipped'] ?? false) === true && ($result4['enrichment_warnings'] ?? []) !== []);

// --- 5. Normal product importing remains correct ----------------------------------
$prodCount = (int) $pdo->query(
    "SELECT COUNT(*) FROM VSKR_products WHERE shop_id = 1 AND available = 1"
)->fetchColumn();
$check('MariaDB: normal product import unaffected (2 available products)', $prodCount === 2, "count={$prodCount}");
$staleCount = (int) $pdo->query(
    "SELECT COUNT(*) FROM VSKR_products WHERE shop_id = 1 AND external_id = '978003' AND available = 0"
)->fetchColumn();
$check('MariaDB: unavailable product untouched by stale marking (in seen set)', $staleCount === 1);

// --- 6. Transaction behavior: membership replace participates in the import txn --
$pdo->beginTransaction();
$pdo->exec('DELETE FROM VSKR_product_categories WHERE shop_id = 1');
try {
    $repo()->replaceCategoryMemberships(1, ['978001' => ['Transient']]);
    throw new RuntimeException('simulated upstream failure inside transaction');
} catch (RuntimeException $e) {
    // expected control-flow simulation
} finally {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
}
$count5 = (int) $pdo->query('SELECT COUNT(*) FROM VSKR_product_categories')->fetchColumn();
$check('MariaDB: rollback of import transaction restored known-good memberships',
    $count5 === 1, "count={$count5}");

echo "\nMariaDB membership integration: {$checks} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
