<?php

declare(strict_types=1);

/**
 * Importer test suite (framework-free, mirrors tests/run.php style).
 *
 * Usage: php tests/import/run_import_tests.php
 *
 * DB-backed tests use an in-memory SQLite database with the VSKR_ schema —
 * the same read/write SQL the production MySQL code path uses (standard SQL
 * only: transactions, bound parameters, chunked NOT IN).
 */

require __DIR__ . '/../../src/Money.php';
require __DIR__ . '/../../src/Cart.php';
require __DIR__ . '/../../src/View.php';
require __DIR__ . '/../../src/Database.php';
require __DIR__ . '/../../src/Import/SourceException.php';
require __DIR__ . '/../../src/Import/SourceFetcher.php';
require __DIR__ . '/../../src/Import/HttpClient.php';
require __DIR__ . '/../../src/Import/ShopifyMapper.php';
require __DIR__ . '/../../src/Import/NormalizedProduct.php';
require __DIR__ . '/../../src/Import/ImportRepository.php';
require __DIR__ . '/../../src/Import/ShopifyImporter.php';

use Versandkostenretter\Import\HttpClient;
use Versandkostenretter\Import\ImportRepository;
use Versandkostenretter\Import\ShopifyImporter;
use Versandkostenretter\Import\ShopifyMapper;
use Versandkostenretter\Import\SourceException;

final class ImportTestRunner
{
    public int $pass = 0;
    public int $fail = 0;
    public array $messages = [];

    public function check(string $name, mixed $actual, mixed $expected): void
    {
        if ($actual === $expected) {
            $this->pass++;
            $this->messages[] = "PASS  {$name}";
        } else {
            $this->fail++;
            $this->messages[] = sprintf("FAIL  %s\n      expected: %s\n      actual:   %s",
                $name, var_export($expected, true), var_export($actual, true));
        }
    }

    public function assertTrue(string $name, bool $cond, string $detail = ''): void
    {
        if ($cond) {
            $this->pass++;
            $this->messages[] = "PASS  {$name}";
        } else {
            $this->fail++;
            $this->messages[] = "FAIL  {$name}" . ($detail !== '' ? " — {$detail}" : '');
        }
    }

    public function report(): int
    {
        echo implode("\n", $this->messages), "\n\n";
        printf("Import tests: %d, Failures: %d\n", $this->pass + $this->fail, $this->fail);
        return $this->fail === 0 ? 0 : 1;
    }
}

$t = new ImportTestRunner();

/* ------------------------------------------------------------------ *
 * Shared fixture: valid Shopify feed
 * ------------------------------------------------------------------ */
$validFeed = [
    'products' => [
        [
            'id' => 111111,
            'title' => 'Wet Palette',
            'handle' => 'wet-palette',
            'updated_at' => '2026-09-01T10:00:00+02:00',
            'product_type' => '',
            'tags' => ['Wet Palette', 'Farben Zubehör'],
            'variants' => [
                ['id' => 1, 'sku' => 'WP-1', 'available' => true, 'price' => '26.99'],
            ],
            'images' => [
                ['src' => 'https://cdn.shopify.com/s/files/1/1/files/wp.webp?v=1'],
            ],
        ],
        [
            'id' => 222222,
            'title' => 'Brush Set',
            'handle' => 'brush-set',
            'product_type' => 'Pinsel',
            'tags' => ['ignored-tag'],
            'variants' => [
                ['id' => 2, 'available' => false, 'price' => '13.50'],
            ],
            'images' => [],
        ],
    ],
];

/* =============================================================
 * ShopifyMapper — valid feed
 * ============================================================= */
$mapped = ShopifyMapper::mapFeed($validFeed, 'https://lootforge.de');
$t->check('valid feed: 2 products mapped', count($mapped['products']), 2);
$t->check('valid feed: nothing skipped', $mapped['skipped'], []);

$p0 = $mapped['products'][0];
$t->check('external_id = stringified product id', $p0->externalId, '111111');
$t->check('name = title', $p0->name, 'Wet Palette');
$t->check('canonical URL from handle', $p0->canonicalUrl, 'https://lootforge.de/products/wet-palette');
$t->check('price mapped to cents', $p0->priceCents, 2699);
$t->check('availability true', $p0->availabilityState === \Versandkostenretter\Import\NormalizedProduct::AV_AVAILABLE, true);
$t->check('image_url = first image src', $p0->imageUrl, 'https://cdn.shopify.com/s/files/1/1/files/wp.webp?v=1');
$t->check('category: first tag when product_type empty', $p0->category, 'Wet Palette');
$t->check('source_updated_at carried', $p0->sourceUpdatedAt, '2026-09-01T10:00:00+02:00');

$p1 = $mapped['products'][1];
$t->check('category: product_type wins over tags', $p1->category, 'Pinsel');
$t->assertTrue('unavailable product mapped as UNAVAILABLE state', $p1->availabilityState === \Versandkostenretter\Import\NormalizedProduct::AV_UNAVAILABLE);
$t->check('missing image -> image_url null', $p1->imageUrl, null);

/* =============================================================
 * Category mapping determinism
 * ============================================================= */
$tagsString = ['products' => [
    ['id' => 5, 'title' => 'X', 'handle' => 'x', 'tags' => 'Wet Palette, Zubehör',
     'variants' => [['price' => '1.00', 'available' => true]]],
]];
$m = ShopifyMapper::mapFeed($tagsString, 'https://s.example');
$t->check('category: comma-string tags handled', $m['products'][0]->category, 'Wet Palette');

$noCats = ['products' => [
    ['id' => 6, 'title' => 'X', 'handle' => 'x', 'tags' => [], 'product_type' => '',
     'variants' => [['price' => '1.00', 'available' => true]]],
]];
$m = ShopifyMapper::mapFeed($noCats, 'https://s.example');
$t->check('category: null when merchant provides none', $m['products'][0]->category, null);

/* =============================================================
 * Canonical URL / unsafe URL rejection
 * ============================================================= */
$m = ShopifyMapper::mapFeed(['products' => [
    ['id' => 7, 'title' => 'Ünicode prödukt', 'handle' => 'ünicode-prödukt',
     'variants' => [['price' => '2.00', 'available' => true]]],
]], 'https://lootforge.de');
$t->assertTrue('canonical URL: handle is rawurlencoded',
    $m['products'][0]->canonicalUrl === 'https://lootforge.de/products/' . rawurlencode('ünicode-prödukt'),
    $m['products'][0]->canonicalUrl);

$badHandle = ['products' => [
    ['id' => 8, 'title' => 'X', 'handle' => '',
     'variants' => [['price' => '2.00', 'available' => true]]],
]];
$m = ShopifyMapper::mapFeed($badHandle, 'https://lootforge.de');
$t->check('unsafe/missing handle -> product skipped (not sanitized)', count($m['products']), 0);
$t->check('skip reason recorded', $m['skipped'][0]['reason'] ?? '', 'unsafe or missing product handle/URL');

/* =============================================================
 * Variant policy
 * ============================================================= */
$single = ['products' => [
    ['id' => 10, 'title' => 'S', 'handle' => 's', 'variants' => [['price' => '5.00', 'available' => true]]],
]];
$m = ShopifyMapper::mapFeed($single, 'https://s.example');
$t->check('single variant: used directly', $m['products'][0]->priceCents, 500);

$samePrice = ['products' => [
    ['id' => 11, 'title' => 'S', 'handle' => 's', 'variants' => [
        ['price' => '5.00', 'available' => true], ['price' => '5.00', 'available' => false],
    ]],
]];
$m = ShopifyMapper::mapFeed($samePrice, 'https://s.example');
$t->check('multi-variant same price: first variant used', $m['products'][0]->priceCents, 500);

$multiPrice = ['products' => [
    ['id' => 12, 'title' => 'M', 'handle' => 'm', 'variants' => [
        ['price' => '5.00', 'available' => true], ['price' => '9.99', 'available' => true],
    ]],
]];
$m = ShopifyMapper::mapFeed($multiPrice, 'https://s.example');
$t->check('multi-variant different prices: SKIPPED (no silent misrepresentation)', count($m['products']), 0);
$t->assertTrue('multi-variant skip reason documents the policy',
    str_contains($m['skipped'][0]['reason'] ?? '', 'different prices'));

$noVariants = ['products' => [
    ['id' => 13, 'title' => 'N', 'handle' => 'n', 'variants' => []],
]];
$m = ShopifyMapper::mapFeed($noVariants, 'https://s.example');
$t->check('no variants: skipped', count($m['products']), 0);

/* =============================================================
 * Invalid / unexpected source data (fail-closed)
 * ============================================================= */
$bad = false;
try { ShopifyMapper::mapFeed('not-an-array', 'https://s.example'); }
catch (SourceException $e) { $bad = true; }
$t->assertTrue('invalid JSON shape (string) -> SourceException', $bad);

$bad = false;
try { ShopifyMapper::mapFeed(['no_products_key' => 1], 'https://s.example'); }
catch (SourceException $e) { $bad = true; }
$t->assertTrue('wrong structure (no products key) -> SourceException', $bad);

$bad = false;
try { ShopifyMapper::mapFeed(['products' => 'not-an-array'], 'https://s.example'); }
catch (SourceException $e) { $bad = true; }
$t->assertTrue('wrong structure (products not array) -> SourceException', $bad);

$bad = false;
try { ShopifyMapper::mapFeed($validFeed, 'ftp://evil.example'); }
catch (SourceException $e) { $bad = true; }
$t->assertTrue('unsafe shop origin -> SourceException', $bad);

/* =============================================================
 * DB-backed tests (in-memory SQLite with the VSKR_ schema)
 * ============================================================= */
$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('
CREATE TABLE VSKR_shops (
    id INTEGER PRIMARY KEY, name TEXT NOT NULL, slug TEXT NOT NULL UNIQUE,
    website_url TEXT NOT NULL, shipping_cost NUMERIC NOT NULL,
    free_shipping_threshold NUMERIC NOT NULL, active INTEGER NOT NULL DEFAULT 1,
    affiliate_enabled INTEGER NOT NULL DEFAULT 0, affiliate_mode TEXT,
    affiliate_param TEXT, affiliate_value TEXT, affiliate_template TEXT,
    source_type TEXT, source_url TEXT, source_scope TEXT NOT NULL DEFAULT "default",
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE VSKR_products (
    id INTEGER PRIMARY KEY, shop_id INTEGER NOT NULL REFERENCES VSKR_shops(id),
    external_id TEXT, name TEXT NOT NULL, url TEXT NOT NULL, price NUMERIC NOT NULL,
    available INTEGER NOT NULL DEFAULT 1, category TEXT, image_url TEXT,
    source_type TEXT, source_scope TEXT NOT NULL DEFAULT "default",
    last_seen_at TEXT, updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE UNIQUE INDEX uq_vskr_products_source
    ON VSKR_products (shop_id, source_type, source_scope, external_id);
CREATE INDEX ix_vskr_products_shop_avail_price ON VSKR_products (shop_id, available, price);
');
$pdo->exec("INSERT INTO VSKR_shops (id,name,slug,website_url,shipping_cost,free_shipping_threshold,active,affiliate_enabled,source_type,source_url,source_scope)
    VALUES (1,'Lootforge','lootforge','https://lootforge.de',5.99,100.00,1,0,'shopify','https://lootforge.de/collections/zubehor-furs-malen/products.json?limit=250','zubehor-furs-malen'),
           (2,'Other Shop','other','https://other.example',4.99,50.00,1,0,'shopify','https://other.example/collections/x/products.json','x')");

$repo = new ImportRepository($pdo);

$mkProduct = fn (string $id, string $name, int $cents, bool $avail = true, string $cat = 'Cat')
    => new \Versandkostenretter\Import\NormalizedProduct(
        externalId: $id, name: $name, canonicalUrl: "https://lootforge.de/products/{$id}",
        priceCents: $cents,
        availabilityState: $avail ? \Versandkostenretter\Import\NormalizedProduct::AV_AVAILABLE : \Versandkostenretter\Import\NormalizedProduct::AV_UNAVAILABLE,
        category: $cat, imageUrl: null, sourceUpdatedAt: null,
    );

// first import: inserts
$batch1 = [$mkProduct('A1', 'Prod A1', 1099), $mkProduct('A2', 'Prod A2', 2499)];
$stats = $repo->upsertProducts(1, 'shopify', 'zubehor-furs-malen', $batch1);
$t->check('first import: 2 inserted', $stats, ['inserted' => 2, 'updated' => 0]);

// identical second import: idempotent (0 inserts, 0 value changes -> 2 "updated" rows)
$stats = $repo->upsertProducts(1, 'shopify', 'zubehor-furs-malen', $batch1);
$t->check('duplicate import does NOT duplicate products (still 2 rows)', $stats['inserted'], 0);
$count = (int) $pdo->query("SELECT COUNT(*) FROM VSKR_products WHERE shop_id = 1")->fetchColumn();
$t->check('duplicate import: row count unchanged', $count, 2);

// price update on existing product
$batch2 = [$mkProduct('A1', 'Prod A1', 999), $mkProduct('A2', 'Prod A2', 2499)];
$stats = $repo->upsertProducts(1, 'shopify', 'zubehor-furs-malen', $batch2);
$t->check('existing product price update: 1 updated, 0 inserted', $stats, ['inserted' => 0, 'updated' => 2]);
$price = (float) $pdo->query("SELECT price FROM VSKR_products WHERE shop_id = 1 AND external_id = 'A1'")->fetchColumn();
$t->check('price updated in DB', abs($price - 9.99) < 0.001, true);

// availability update
$batch3 = [$mkProduct('A1', 'Prod A1', 999, false), $mkProduct('A2', 'Prod A2', 2499)];
$repo->upsertProducts(1, 'shopify', 'zubehor-furs-malen', $batch3);
$avail = (int) $pdo->query("SELECT available FROM VSKR_products WHERE shop_id = 1 AND external_id = 'A1'")->fetchColumn();
$t->check('availability updated in DB', $avail, 0);

// source/shop isolation: same external_id in another shop/source must not clash or be touched
$stats = $repo->upsertProducts(2, 'shopify', 'x', [$mkProduct('A1', 'Other shop product', 4242)]);
$t->check('other shop same external_id: independent insert', $stats['inserted'], 1);
$other = $pdo->query("SELECT name FROM VSKR_products WHERE shop_id = 2 AND external_id = 'A1'")->fetchColumn();
$t->check('other shop product unaffected by shop 1 imports', $other, 'Other shop product');

// different source scope, same shop: independent
$stats = $repo->upsertProducts(1, 'shopify', 'other-collection', [$mkProduct('A1', 'Scoped product', 777)]);
$t->check('same shop, different source scope: independent insert', $stats['inserted'], 1);

// stale marking scoped to (shop, source, scope)
// restore A1 to available so stale-marking has something to flip
$repo->upsertProducts(1, 'shopify', 'zubehor-furs-malen', [$mkProduct('A1', 'Prod A1', 999, true)]);
// shop1/default has A1 (available), A2 (available). Complete feed sees only A2.
$n = $repo->markStaleUnavailable(1, 'shopify', 'zubehor-furs-malen', ['A2']);
$t->check('complete import marks missing product unavailable', $n, 1);
$avail = (int) $pdo->query("SELECT available FROM VSKR_products WHERE shop_id = 1 AND source_scope = 'zubehor-furs-malen' AND external_id = 'A1'")->fetchColumn();
$t->check('stale product available=0', $avail, 0);
// other scope + other shop unaffected:
$row = $pdo->query("SELECT available, name FROM VSKR_products WHERE shop_id = 2 AND external_id = 'A1'")->fetch(PDO::FETCH_ASSOC);
$t->check('stale marking does not touch other shop', $row['name'], 'Other shop product');
$availScoped = (int) $pdo->query("SELECT available FROM VSKR_products WHERE shop_id = 1 AND source_scope = 'other-collection' AND external_id = 'A1'")->fetchColumn();
$t->check('stale marking does not touch other source scope of same shop', $availScoped, 1);
// no deletes happened:
$total = (int) $pdo->query('SELECT COUNT(*) FROM VSKR_products')->fetchColumn();
$t->check('stale marking never deletes', $total, 4);

// affiliate fields untouched by any import statement
$aff = $pdo->query('SELECT affiliate_enabled, affiliate_mode, affiliate_param, affiliate_value, affiliate_template FROM VSKR_shops WHERE id = 1')->fetch(PDO::FETCH_ASSOC);
$t->check('affiliate fields remain disabled/untouched', $aff, [
    'affiliate_enabled' => 0, 'affiliate_mode' => null,
    'affiliate_param' => null, 'affiliate_value' => null, 'affiliate_template' => null,
]);
// canonical URLs in DB are never affiliate-modified
$urls = $pdo->query("SELECT url FROM VSKR_products WHERE shop_id = 1")->fetchAll(PDO::FETCH_COLUMN);
$t->assertTrue('all stored URLs are canonical (no affiliate params)',
    count(array_filter($urls, fn ($u) => !preg_match('/[?&](ref|aff|affiliate)=/i', $u))) === count($urls), implode(',', $urls));

// importer SQL cannot touch non-VSKR tables
$violations = \Versandkostenretter\Database::assertOnlyVskrTables(
    'INSERT INTO VSKR_products (shop_id) VALUES (1); UPDATE VSKR_products SET available = 0; SELECT 1 FROM VSKR_shops'
);
$t->check('importer SQL only references VSKR_ tables', $violations, []);

/* =============================================================
 * ShopifyImporter orchestration (fake HttpClient, real repo/SQLite)
 * ============================================================= */
class FakeHttp implements \Versandkostenretter\Import\SourceFetcher
{
    public function __construct(private array $responses, private bool $throwOnCall = false)
    {
    }

    public function get(string $url): array
    {
        if ($this->throwOnCall) {
            throw new SourceException('simulated network failure');
        }
        $r = array_shift($this->responses);
        if ($r === null) {
            throw new SourceException('no more fake responses');
        }
        return $r;
    }
}

$feedJson = json_encode($validFeed);
$okResponse = fn (): array => ['code' => 200, 'body' => $feedJson, 'content_type' => 'application/json; charset=utf-8', 'url' => 'x'];

$shopCfg = ['id' => 1, 'name' => 'Lootforge', 'slug' => 'lootforge',
            'source_type' => 'shopify', 'source_url' => 'https://lootforge.de/collections/zubehor-furs-malen/products.json?limit=250',
            'source_scope' => 'zubehor-furs-malen'];

// fresh DB for orchestration tests
$pdo2 = new PDO('sqlite::memory:');
$pdo2->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo2->exec('CREATE TABLE VSKR_shops (id INTEGER PRIMARY KEY, name TEXT, slug TEXT, website_url TEXT, shipping_cost NUMERIC, free_shipping_threshold NUMERIC, active INTEGER DEFAULT 1, affiliate_enabled INTEGER DEFAULT 0, source_type TEXT, source_url TEXT, source_scope TEXT DEFAULT "default");
CREATE TABLE VSKR_products (id INTEGER PRIMARY KEY, shop_id INTEGER, external_id TEXT, name TEXT, url TEXT, price NUMERIC, available INTEGER DEFAULT 1, category TEXT, image_url TEXT, source_type TEXT, source_scope TEXT DEFAULT "default", last_seen_at TEXT, updated_at TEXT DEFAULT CURRENT_TIMESTAMP)');
$repo2 = new ImportRepository($pdo2);
$importer = new ShopifyImporter(new FakeHttp([$okResponse(), $okResponse(), $okResponse()]), $repo2);

// dry run: zero writes
$res = $importer->import($shopCfg, dryRun: true);
$t->check('dry run: reports fetched count', $res['fetched'], 2);
$t->check('dry run: reports mapped products', count($res['mapped']), 2);
$rows = (int) $pdo2->query('SELECT COUNT(*) FROM VSKR_products')->fetchColumn();
$t->check('dry run performs ZERO database writes', $rows, 0);

// live import
$res = $importer->import($shopCfg, dryRun: false);
$t->check('live import summary shape', [
    $res['inserted'], $res['updated'], $res['unavailable'], $res['errors'],
], [2, 0, 0, 0]);
$rows = (int) $pdo2->query('SELECT COUNT(*) FROM VSKR_products')->fetchColumn();
$t->check('live import wrote rows', $rows, 2);

// idempotent second run
$res2 = $importer->import($shopCfg, dryRun: false);
$t->check('second run: 0 inserted (idempotent refresh)', $res2['inserted'], 0);
$rows = (int) $pdo2->query('SELECT COUNT(*) FROM VSKR_products')->fetchColumn();
$t->check('second run: still 2 rows', $rows, 2);

// failure behavior: network failure must throw and NOT touch availability
$failingImporter = new ShopifyImporter(new FakeHttp([], throwOnCall: true), $repo2);
// make A2 stale-eligible first (feed without it)
$feedNoA2 = json_encode(['products' => [$validFeed['products'][1]]]);
$res3 = (new ShopifyImporter(new FakeHttp([['code' => 200, 'body' => $feedNoA2, 'content_type' => 'application/json', 'url' => 'x']]), $repo2))
    ->import($shopCfg, dryRun: false);
$t->check('complete feed without A1 -> A1 marked unavailable', $res3['unavailable'], 1);
$availA1 = (int) $pdo2->query("SELECT available FROM VSKR_products WHERE external_id = '111111'")->fetchColumn();
$t->check('A1 is unavailable now', $availA1, 0);

// NOW a network failure: must throw, must NOT change availability further
$threw = false;
try {
    $failingImporter->import($shopCfg, dryRun: false);
} catch (SourceException $e) {
    $threw = true;
}
$t->assertTrue('network failure -> SourceException (fail closed)', $threw);
$availA1After = (int) $pdo2->query("SELECT available FROM VSKR_products WHERE external_id = '111111'")->fetchColumn();
$t->check('failed import did not mutate availability', $availA1After, 0);
$availA2After = (int) $pdo2->query("SELECT available FROM VSKR_products WHERE external_id = '222222'")->fetchColumn();
$t->check('failed import did not mutate other products', $availA2After, 0); // A2 was legitimately marked stale by the previous COMPLETE import; the failed run changed nothing

// wrong content type rejected
$threw = false;
try {
    (new ShopifyImporter(new FakeHttp([['code' => 200, 'body' => '<html>nope</html>', 'content_type' => 'text/html', 'url' => 'x']]), $repo2))
        ->import($shopCfg);
} catch (SourceException $e) {
    $threw = true;
}
$t->assertTrue('HTML response (unexpected content type) rejected', $threw);

// invalid JSON rejected
$threw = false;
try {
    (new ShopifyImporter(new FakeHttp([['code' => 200, 'body' => '{invalid json', 'content_type' => 'application/json', 'url' => 'x']]), $repo2))
        ->import($shopCfg);
} catch (SourceException $e) {
    $threw = true;
}
$t->assertTrue('invalid JSON rejected', $threw);

// wrong structure rejected
$threw = false;
try {
    (new ShopifyImporter(new FakeHttp([['code' => 200, 'body' => '{"foo":1}', 'content_type' => 'application/json', 'url' => 'x']]), $repo2))
        ->import($shopCfg);
} catch (SourceException $e) {
    $threw = true;
}
$t->assertTrue('wrong JSON structure rejected', $threw);

// HTTP failure rejected
$threw = false;
try {
    (new ShopifyImporter(new FakeHttp([['code' => 500, 'body' => '', 'content_type' => 'text/plain', 'url' => 'x']]), $repo2))
        ->import($shopCfg);
} catch (SourceException $e) {
    $threw = true;
}
$t->assertTrue('HTTP 500 from source rejected', $threw);

// shop isolation through the orchestrator: shop 2 import never touches shop 1
$shop2Cfg = ['id' => 2, 'name' => 'Other Shop', 'slug' => 'other',
             'source_type' => 'shopify', 'source_url' => 'https://other.example/collections/x/products.json',
             'source_scope' => 'x'];
$feed2 = json_encode(['products' => [
    ['id' => 999, 'title' => 'Other P', 'handle' => 'other-p',
     'variants' => [['price' => '10.00', 'available' => true]]],
]]);
$res4 = (new ShopifyImporter(new FakeHttp([['code' => 200, 'body' => $feed2, 'content_type' => 'application/json', 'url' => 'x']]), $repo2))
    ->import($shop2Cfg, dryRun: false);
$countShop1 = (int) $pdo2->query('SELECT COUNT(*) FROM VSKR_products WHERE shop_id = 1')->fetchColumn();
$t->check('shop 2 import left shop 1 untouched (rows still 2)', $countShop1, 2);

exit($t->report());
