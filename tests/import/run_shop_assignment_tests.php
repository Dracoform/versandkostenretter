<?php

declare(strict_types=1);

/**
 * PRODUCTION REPRODUCTION: shop-assignment / stale-marking regression.
 *
 * Simulates the exact production scenario with a production-shaped DB:
 *  - Lootforge shop (id 1), legacy source: single collection scope
 *  - 8 legacy collection-scoped rows (available = 1)
 *  - complete source run: 971 products (749 AVAILABLE / 222 UNAVAILABLE)
 *
 * Proves (after the REAL orchestrator + repository pipeline):
 *  - complete scope contains 971 rows
 *  - 749 remain AVAILABLE
 *  - 222 remain UNAVAILABLE
 *  - stale-marked count = 0 on the first complete import
 *  - legacy collection-scoped rows untouched (8, available = 1)
 *  - current-run rows never stale-marked
 *  - a genuinely missing product from a LATER complete run IS marked
 *  - partial/failed runs never perform stale marking
 *  - no rows under shop_id 0/NULL/another shop
 *  - frontend (available = 1, shop = lootforge) immediately sees 749+8 rows
 *
 * Exit 0 = pass, 1 = failure.
 */

require __DIR__ . '/../../src/Money.php';
require __DIR__ . '/../../src/Cart.php';
require __DIR__ . '/../../src/View.php';
require __DIR__ . '/../../src/Database.php';
require __DIR__ . '/../../src/Import/SourceException.php';
require __DIR__ . '/../../src/Import/SourceFetcher.php';
require __DIR__ . '/../../src/Import/SourceAdapter.php';
require __DIR__ . '/../../src/Import/SourceFetchResult.php';
require __DIR__ . '/../../src/Import/SourceCapabilities.php';
require __DIR__ . '/../../src/Import/AvailabilityChecker.php';
require __DIR__ . '/../../src/Import/SourceAdapterRegistry.php';
require __DIR__ . '/../../src/Import/NormalizedProduct.php';
require __DIR__ . '/../../src/Import/HttpClient.php';
require __DIR__ . '/../../src/Import/ShopifyMapper.php';
require __DIR__ . '/../../src/Import/ShopifyAdapter.php';
require __DIR__ . '/../../src/Import/ImportRepository.php';
require __DIR__ . '/../../src/Import/ImportOrchestrator.php';

use Versandkostenretter\Import\HttpClient;
use Versandkostenretter\Import\ImportOrchestrator;
use Versandkostenretter\Import\ImportRepository;
use Versandkostenretter\Import\NormalizedProduct;
use Versandkostenretter\Import\ShopifyAdapter;
use Versandkostenretter\Import\SourceException;
use Versandkostenretter\Import\SourceFetchResult;
use Versandkostenretter\Import\SourceFetcher;

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

/* =============================================================
 * Test doubles
 * ============================================================= */
class ScriptedHttp implements SourceFetcher
{
    /** @var list<array{body:string,complete:bool}> */
    private array $pages;
    private int $page = 0;
    public int $requests = 0;

    /** @param list<list<array<string,mixed>>> $pagesOfRawProducts */
    public function __construct(array $pagesOfRawProducts)
    {
        $this->pages = array_map(
            static fn (array $products): array => [
                'body' => json_encode(['products' => $products]),
                'complete' => true,
            ],
            $pagesOfRawProducts
        );
    }

    public function get(string $url): array
    {
        $this->requests++;
        if (isset($this->pages[$this->page])) {
            return [
                'code' => 200,
                'body' => $this->pages[$this->page++]['body'],
                'content_type' => 'application/json',
                'url' => $url,
            ];
        }
        return ['code' => 200, 'body' => '{"products":[]}', 'content_type' => 'application/json', 'url' => $url];
    }
}

// A scripted HTTP that fails on the SECOND request (partial pagination failure).
class FailOnSecondHttp extends ScriptedHttp
{
    public function get(string $url): array
    {
        if ($this->requests === 1) {
            $this->requests++;
            return ['code' => 200, 'body' => '{"products":[]}', 'content_type' => 'application/json', 'url' => $url];
        }
        throw new SourceException('simulated page 2 failure');
    }
}

/* =============================================================
 * Production-shaped DB
 * ============================================================= */
$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
// Pre-0006 production shape: available NOT NULL.
$pdo->exec('
CREATE TABLE VSKR_shops (
    id INTEGER PRIMARY KEY, name TEXT, slug TEXT UNIQUE, website_url TEXT,
    shipping_cost NUMERIC, free_shipping_threshold NUMERIC, active INTEGER DEFAULT 1,
    affiliate_enabled INTEGER DEFAULT 0, affiliate_mode TEXT, affiliate_param TEXT,
    affiliate_value TEXT, affiliate_template TEXT, source_type TEXT, source_url TEXT,
    source_scope TEXT DEFAULT "default", product_images_enabled INTEGER DEFAULT 0, updated_at TEXT
);
CREATE TABLE VSKR_products (
    id INTEGER PRIMARY KEY, shop_id INTEGER, external_id TEXT, name TEXT, url TEXT,
    price NUMERIC, available INTEGER DEFAULT 1, category TEXT, image_url TEXT,
    source_type TEXT, source_scope TEXT DEFAULT "default", last_seen_at TEXT,
    updated_at TEXT DEFAULT CURRENT_TIMESTAMP
);
');
$pdo->exec('INSERT INTO VSKR_shops (id,name,slug,website_url,shipping_cost,free_shipping_threshold,active,
    source_type,source_url,source_scope)
    VALUES (1,"Lootforge","lootforge","https://lootforge.de",5.99,100.00,1,
    "shopify","https://lootforge.de/collections/zubehor-furs-malen/products.json?limit=250","zubehor-furs-malen")');
$st = $pdo->prepare('INSERT INTO VSKR_products (shop_id,external_id,name,url,price,available,category,source_type,source_scope)
    VALUES (1,?,?,?,?,1,"Wet Palette","shopify","zubehor-furs-malen")');
foreach (range(1, 8) as $i) {
    $st->execute(["LEGACY-$i", "Legacy $i", "https://lootforge.de/products/legacy-$i", 10.00 + $i]);
}
$check('legacy setup: 8 collection-scoped rows available = 1',
    (int) $pdo->query('SELECT COUNT(*) FROM VSKR_products WHERE source_scope = "zubehor-furs-malen" AND available = 1')->fetchColumn() === 8);

// Apply 0007 semantics: source -> complete catalogue.
$pdo->exec('UPDATE VSKR_shops SET source_url = "https://lootforge.de/products.json?limit=250",
    source_scope = "complete" WHERE slug = "lootforge"');
$shop = $pdo->query('SELECT id, name, slug, source_type, source_url, source_scope FROM VSKR_shops WHERE slug = "lootforge"')
    ->fetch(PDO::FETCH_ASSOC);
$check('shop resolved: id=1 (Lootforge)', (int) $shop['id'] === 1, (string) $shop['id']);

/* =============================================================
 * Complete source: 971 products, 749 AVAILABLE / 222 UNAVAILABLE
 * ============================================================= */
$rawProducts = [];
$externalId = 100000;
for ($i = 0; $i < 749; $i++) {
    $externalId++;
    $rawProducts[] = ['id' => $externalId, 'title' => "Available $i", 'handle' => "av-$i",
        'variants' => [['price' => '9.99', 'available' => true]]];
}
for ($i = 0; $i < 222; $i++) {
    $externalId++;
    $rawProducts[] = ['id' => $externalId, 'title' => "Unavailable $i", 'handle' => "un-$i",
        'variants' => [['price' => '19.99', 'available' => false]]];
}
// pages of 250: 250, 250, 250, 221
$pages = array_chunk($rawProducts, 250);
$check('complete source: 4 pages (250/250/250/221)', count($pages) === 4, (string) count($pages));

$repo = new ImportRepository($pdo);
$orchestrator = new ImportOrchestrator(
    new \Versandkostenretter\Import\SourceAdapterRegistry(new ShopifyAdapter(new ScriptedHttp($pages))),
    $repo
);

/* =============================================================
 * FIRST COMPLETE IMPORT
 * ============================================================= */
$result = $orchestrator->import($shop);
$check('first complete import: fetched 971', $result['fetched'] === 971, (string) $result['fetched']);
$check('first complete import: inserted 971', $result['inserted'] === 971, (string) $result['inserted']);
$check('first complete import: complete = true', $result['complete'] === true);
$check('first complete import: stale-marked count = 0', $result['unavailable'] === 0,
    (string) $result['unavailable']);

// Every complete-scope row has the right shop_id / scope:
$check('all 971 rows under shop_id 1', (int) $pdo->query(
    'SELECT COUNT(*) FROM VSKR_products WHERE source_scope = "complete" AND shop_id = 1')->fetchColumn() === 971);
$check('no rows under shop_id 0/NULL',
    (int) $pdo->query('SELECT COUNT(*) FROM VSKR_products WHERE shop_id = 0 OR shop_id IS NULL')->fetchColumn() === 0);
$check('no rows under another shop id',
    (int) $pdo->query('SELECT COUNT(*) FROM VSKR_products WHERE shop_id <> 1')->fetchColumn() === 0);

// Availability integrity: 749 AVAILABLE, 222 UNAVAILABLE — nothing flipped.
$avail = (int) $pdo->query('SELECT COUNT(*) FROM VSKR_products WHERE source_scope = "complete" AND available = 1')->fetchColumn();
$unavail = (int) $pdo->query('SELECT COUNT(*) FROM VSKR_products WHERE source_scope = "complete" AND available = 0')->fetchColumn();
$check('complete scope: 749 remain AVAILABLE', $avail === 749, (string) $avail);
$check('complete scope: 222 remain UNAVAILABLE', $unavail === 222, (string) $unavail);

// Legacy rows untouched:
$legacy = $pdo->query('SELECT COUNT(*), SUM(available) FROM VSKR_products WHERE source_scope = "zubehor-furs-malen"')
    ->fetch(PDO::FETCH_NUM);
$check('legacy 8 rows untouched (count 8, all available = 1)',
    (int) $legacy[0] === 8 && (int) $legacy[1] === 8, json_encode($legacy));

// Frontend view (available = 1, shop 1): all 757 qualifying rows visible immediately.
$visible = (int) $pdo->query('SELECT COUNT(*) FROM VSKR_products WHERE shop_id = 1 AND available = 1')->fetchColumn();
$check('frontend immediately sees 757 rows (749 complete + 8 legacy)', $visible === 757, (string) $visible);
$cats = $pdo->query('SELECT COUNT(DISTINCT category) FROM VSKR_products WHERE shop_id = 1 AND available = 1')->fetchColumn();
$check('mapped categories visible to the frontend', (int) $cats >= 1, (string) $cats);

/* =============================================================
 * SECOND COMPLETE IMPORT (idempotency + current-run safety)
 * ============================================================= */
// fresh HTTP pages for the second run (each import is a separate source run)
$result2 = (new ImportOrchestrator(
    new \Versandkostenretter\Import\SourceAdapterRegistry(new ShopifyAdapter(new ScriptedHttp($pages))),
    $repo
))->import($shop);
$check('second import: inserted 0 (idempotent)', $result2['inserted'] === 0);
$check('second import: updated 971', $result2['updated'] === 971);
$check('second import: stale-marked 0 (all still present)', $result2['unavailable'] === 0, (string) $result2['unavailable']);

/* =============================================================
 * Genuinely missing product from a LATER complete run
 * ============================================================= */
// remove one AVAILABLE product (available = 1 rows are the only ones stale
// marking can flip; removing an already-unavailable row would be invisible)
$missingId = $rawProducts[0]['id']; // first = AVAILABLE
$shrunk = array_values(array_filter($rawProducts, static fn ($p): bool => $p['id'] !== $missingId));
$pages = array_chunk($shrunk, 250);
$res3 = (new ImportOrchestrator(
    new \Versandkostenretter\Import\SourceAdapterRegistry(new ShopifyAdapter(new ScriptedHttp(array_slice($pages, 0, 4)))),
    $repo
))->import($shop);
$check('later complete run missing 1 product -> stale-marked 1', $res3['unavailable'] === 1,
    (string) $res3['unavailable']);
$check('the missing AVAILABLE product is now UNAVAILABLE',
    (int) $pdo->query('SELECT COUNT(*) FROM VSKR_products WHERE external_id = ' . $missingId . ' AND available = 0')->fetchColumn() === 1);
$check('still not deleted (row retained)', (int) $pdo->query(
    'SELECT COUNT(*) FROM VSKR_products WHERE external_id = ' . $missingId)->fetchColumn() === 1);

/* =============================================================
 * Partial pagination failure: NO stale marking
 * ============================================================= */

// reset the removed product to available first so a wrong stale pass would show
$pdo->exec('UPDATE VSKR_products SET available = 1 WHERE external_id = ' . $missingId);
// Scripted HTTP that serves page 1, then fails (partial pagination).
$failHttp = new class($pages) extends ScriptedHttp {
    private bool $failed = false;
    public function get(string $url): array
    {
        if ($this->failed) {
            throw new SourceException('simulated page 2 failure');
        }
        $this->failed = true;
        return parent::get($url); // serves page 1, then the NEXT get throws
    }
};
$res4 = null;
$threw = false;
try {
    $res4 = (new ImportOrchestrator(
        new \Versandkostenretter\Import\SourceAdapterRegistry(new ShopifyAdapter($failHttp)),
        $repo
    ))->import($shop);
} catch (SourceException $e) {
    $threw = true;
}
$check('partial pagination failure -> import fails closed', $threw || ($res4 !== null && $res4['complete'] === false));
$check('no stale marking after partial failure (available rows unchanged)',
    (int) $pdo->query('SELECT COUNT(*) FROM VSKR_products WHERE shop_id = 1 AND available = 1')->fetchColumn() === 757,
    (string) (int) $pdo->query('SELECT COUNT(*) FROM VSKR_products WHERE shop_id = 1 AND available = 1')->fetchColumn());

/* =============================================================
 * Cross-shop isolation (other shop untouched)
 * ============================================================= */
$pdo->exec('INSERT INTO VSKR_shops (id,name,slug,website_url,shipping_cost,free_shipping_threshold,active)
    VALUES (2,"Other","other","https://other.example",5,50,1)');
$pdo->exec('INSERT INTO VSKR_products (shop_id,external_id,name,url,price,available,source_type,source_scope)
    VALUES (2,"O1","Other Prod","https://other.example/p/1",9.99,1,"shopify","complete")');
$res5 = (new ImportOrchestrator(
    new \Versandkostenretter\Import\SourceAdapterRegistry(new ShopifyAdapter(new ScriptedHttp([$pages[0], $pages[1], $pages[2], $pages[3]]))),
    $repo
))->import($shop);
$check('other shop product unaffected by lootforge stale runs',
    (int) $pdo->query('SELECT available FROM VSKR_products WHERE shop_id = 2')->fetchColumn() === 1);
$check('other shop still has its row', (int) $pdo->query(
    'SELECT COUNT(*) FROM VSKR_products WHERE shop_id = 2')->fetchColumn() === 1);

print("\nShop-assignment reproduction: {$pass} passed, {$fail} failed\n");
exit($fail === 0 ? 0 : 1);
