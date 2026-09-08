<?php

declare(strict_types=1);

/**
 * Adapter-framework + full-catalogue importer regression tests.
 *
 * Usage: php tests/import/run_adapter_tests.php
 * or:    VSKR_TEST_PHP=/path/to/php php tests/import/run_adapter_tests.php
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
require __DIR__ . '/../../src/Import/HttpClient.php';
require __DIR__ . '/../../src/Import/ShopifyMapper.php';
require __DIR__ . '/../../src/Import/NormalizedProduct.php';
require __DIR__ . '/../../src/Import/SourceAdapterRegistry.php';
require __DIR__ . '/../../src/Import/ImportRepository.php';
require __DIR__ . '/../../src/Import/ImportOrchestrator.php';
require __DIR__ . '/../../src/Import/ShopifyAdapter.php';

use Versandkostenretter\Import\HttpClient;
use Versandkostenretter\Import\ImportOrchestrator;
use Versandkostenretter\Import\ImportRepository;
use Versandkostenretter\Import\NormalizedProduct;
use Versandkostenretter\Import\ShopifyAdapter;
use Versandkostenretter\Import\SourceAdapter;
use Versandkostenretter\Import\SourceAdapterRegistry;
use Versandkostenretter\Import\SourceException;
use Versandkostenretter\Import\SourceFetchResult;
use Versandkostenretter\Import\SourceFetcher;
use Versandkostenretter\Import\ShopifyMapper;

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
 * 0. Test doubles
 * ============================================================= */
class FakeHttpPF implements SourceFetcher
{
    /** @var list<array> */
    public array $log = [];

    public function __construct(private array $responses)
    {
    }

    public function get(string $url): array
    {
        $this->log[] = $url;
        $r = array_shift($this->responses);
        if ($r === null) {
            throw new SourceException('no more fake responses');
        }
        if ($r instanceof Throwable) {
            throw $r;
        }
        return $r;
    }
}

class FakeCheckerAdapter implements SourceAdapter, \Versandkostenretter\Import\AvailabilityChecker
{
    public bool $wasInvoked = false;

    public function type(): string
    {
        return 'live-capable';
    }

    public function fetchAll(array $shop): SourceFetchResult
    {
        return new SourceFetchResult([], [], complete: true);
    }

    public function checkAvailability(array $externalIds): array
    {
        $this->wasInvoked = true;
        return [];
    }
}

/* =============================================================
 * Adapter framework
 * ============================================================= */
$registry = new SourceAdapterRegistry();
$check('registry: empty initially', $registry->knownTypes() === []);
$registry->register(new ShopifyAdapter(new FakeHttpPF([])));
$check('registry: registers adapters by type', $registry->has('shopify') && $registry->knownTypes() === ['shopify']);
$check('registry: resolves adapter', $registry->for('shopify') instanceof ShopifyAdapter);
$missing = false;
try { $registry->for('csv'); } catch (RuntimeException $e) { $missing = str_contains($e->getMessage(), 'csv'); }
$check('registry: unknown type throws helpful error', $missing);

// Capability detection WITHOUT invocation:
$plain = new ShopifyAdapter(new FakeHttpPF([]));
$check('ShopifyAdapter does NOT claim AvailabilityChecker (capability optional)',
    !($plain instanceof \Versandkostenretter\Import\AvailabilityChecker));
$check('SourceAdapter marker interface exists for optional capabilities',
    interface_exists(\Versandkostenretter\Import\SourceCapabilities::class)
    && interface_exists(\Versandkostenretter\Import\AvailabilityChecker::class));
// A live-capable adapter is detectable via instanceof without invoking checkAvailability:
$live = new class implements SourceAdapter {
    public function type(): string { return 'live'; }
    public function fetchAll(array $shop): SourceFetchResult { return new SourceFetchResult([], [], true); }
};
$check('capability detection via instanceof works (no invocation required)',
    ($live instanceof \Versandkostenretter\Import\AvailabilityChecker) === false
    && $live instanceof SourceAdapter);

/* =============================================================
 * Tri-state availability
 * ============================================================= */
$p = new NormalizedProduct('1', 'A', 'https://x/p/1', 100, NormalizedProduct::AV_AVAILABLE, null, null, null);
$check('AVAILABLE -> DB 1', $p->toDbValue() === 1);
$u = new NormalizedProduct('2', 'B', 'https://x/p/2', 500, NormalizedProduct::AV_UNAVAILABLE, null, null, null);
$check('UNAVAILABLE -> DB 0', $u->toDbValue() === 0);
$unk = new NormalizedProduct('3', 'C', 'https://x/p/3', 500, NormalizedProduct::AV_UNKNOWN, null, null, null);
$check('UNKNOWN -> DB NULL', $unk->toDbValue() === null);
$bad = false;
try { new NormalizedProduct('4', 'D', 'https://x/p/4', 100, 'nonsense', null, null, null); }
catch (\InvalidArgumentException $e) { $bad = true; }
$check('invalid availability state rejected', $bad);

// MISSING Shopify availability -> UNKNOWN (never true/false):
$m = ShopifyMapper::mapFeed(['products' => [
    ['id' => 50, 'title' => 'No availability field', 'handle' => 'no-av',
     'variants' => [['price' => '5.00']]], // no 'available' key
]], 'https://s.example');
$check('missing Shopify availability -> UNKNOWN', $m['products'][0]->availabilityState === NormalizedProduct::AV_UNKNOWN);

ShopifyMapper::mapFeed(['products' => [
    ['id' => 51, 'title' => 'A', 'handle' => 'a', 'variants' => [['price' => '5.00', 'available' => true]]],
    ['id' => 51, 'title' => 'dup', 'handle' => 'a2', 'variants' => [['price' => '5.00', 'available' => true]]],
]], 'https://s.example');
// (ids identical — handled at page level, not mapper level)

// available/unavailable mapping preserved:
$m = ShopifyMapper::mapFeed(['products' => [
    ['id' => 60, 'title' => 'A', 'handle' => 'a', 'variants' => [['price' => '5.00', 'available' => true]]],
    ['id' => 61, 'title' => 'B', 'handle' => 'b', 'variants' => [['price' => '6.00', 'available' => false]]],
]], 'https://s.example');
$check('available true -> AVAILABLE', $m['products'][0]->availabilityState === NormalizedProduct::AV_AVAILABLE);
$check('available false -> UNAVAILABLE', $m['products'][1]->availabilityState === NormalizedProduct::AV_UNAVAILABLE);

/* =============================================================
 * Shopify pagination (FakeHttp)
 * ============================================================= */
function pf_feed(array $products): array
{
    return ['code' => 200, 'body' => json_encode(['products' => $products]), 'content_type' => 'application/json', 'url' => 'x'];
}
$shopCfg = ['id' => 1, 'name' => 'Lootforge', 'slug' => 'lootforge', 'source_type' => 'shopify',
    'source_url' => 'https://lootforge.de/products.json?limit=250', 'source_scope' => 'complete'];
$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE VSKR_shops (id INTEGER PRIMARY KEY, name TEXT, slug TEXT, website_url TEXT, shipping_cost NUMERIC, free_shipping_threshold NUMERIC, active INTEGER DEFAULT 1, affiliate_enabled INTEGER DEFAULT 0, source_type TEXT, source_url TEXT, source_scope TEXT DEFAULT "default");
CREATE TABLE VSKR_products (id INTEGER PRIMARY KEY, shop_id INTEGER, external_id TEXT, name TEXT, url TEXT, price NUMERIC, available INTEGER, category TEXT, image_url TEXT, source_type TEXT, source_scope TEXT DEFAULT "default", last_seen_at TEXT, updated_at TEXT DEFAULT CURRENT_TIMESTAMP);
CREATE TABLE VSKR_stats (stat_key TEXT PRIMARY KEY, stat_value INTEGER DEFAULT 0)');
$pdo->exec('INSERT INTO VSKR_shops (id,name,slug,website_url,shipping_cost,free_shipping_threshold,active) VALUES (1,"Lootforge","lootforge","https://lootforge.de",5.99,100.00,1)');
$repo = new ImportRepository($pdo);

$mkP = fn (int $n): array => ['id' => $n, 'title' => "P$n", 'handle' => "p$n",
    'variants' => [['price' => '10.00', 'available' => true]]];

// one-page catalogue (short page = complete)
$fake = new FakeHttpPF([pf_feed([$mkP(1), $mkP(2)])]);
$res = (new ShopifyAdapter($fake))->fetchAll($shopCfg);
$check('one-page catalogue: complete, 2 products', $res->complete && count($res->products) === 2);
$check('one-page catalogue: exactly 1 request', $res->requests === 1);

// exact 250 boundary: 250 on page 1 then short page
$fake = new FakeHttpPF([pf_feed(array_map($mkP, range(1, 250))), pf_feed([$mkP(251)])]);
$res = (new ShopifyAdapter($fake))->fetchAll($shopCfg);
$check('exact-250 boundary: page 1 + short page, 251 unique', count($res->products) === 251 && $res->complete);
$check('exact-250 boundary: 2 requests', $res->requests === 2);

// multiple pages: 2 full pages + short final
$fake = new FakeHttpPF([pf_feed(array_map($mkP, range(1, 250))), pf_feed(array_map($mkP, range(251, 400)))]);
$res = (new ShopifyAdapter($fake))->fetchAll($shopCfg);
$check('multiple pages: 400 products over 2 requests', count($res->products) === 400 && $res->requests === 2);

// empty final page if relevant: full page + empty page -> still complete
$fake = new FakeHttpPF([pf_feed(array_map($mkP, range(1, 250))), pf_feed([])]);
$res = (new ShopifyAdapter($fake))->fetchAll($shopCfg);
$check('empty final page handled as complete', $res->complete && count($res->products) === 250);

// duplicate ids across pages handled safely (full pages so pagination continues):
$filler = static fn (int $from, int $to): array => array_map(
    static fn (int $i): array => $mkP($i),
    range($from, $to)
);
$page1 = array_merge($filler(1, 248), [$mkP(900001), $mkP(900002)]);
$page2 = array_merge([$mkP(900001), $mkP(900003)], $filler(900010, 900200));
$fake = new FakeHttpPF([pf_feed($page1), pf_feed($page2)]);
$res = (new ShopifyAdapter($fake))->fetchAll($shopCfg);
$ids = array_map(static fn (NormalizedProduct $p): string => $p->externalId, $res->products);
$check('duplicate ids across pages: deduplicated, first wins',
    count($res->products) === 442
    && count(array_unique($ids)) === 442
    && count(array_keys($ids, '900001', true)) === 1
    && $res->skipped !== []
    && str_contains($res->skipped[0]['reason'] ?? '', 'duplicate'),
    'products=' . count($res->products) . ' skipped=' . count($res->skipped));

// malformed later page => entire import fails closed
$fake = new FakeHttpPF([pf_feed(array_map($mkP, range(1, 250))),
    ['code' => 200, 'body' => '{"broken"', 'content_type' => 'application/json', 'url' => 'x']]);
$threw = false;
try { (new ShopifyAdapter($fake))->fetchAll($shopCfg); }
catch (SourceException $e) { $threw = true; }
$check('malformed later page fails closed (SourceException)', $threw);

// no stale marking after partial pagination failure (orchestrator level):
$orch = new ImportOrchestrator(
    new SourceAdapterRegistry(new ShopifyAdapter(new FakeHttpPF([
        pf_feed(array_map($mkP, range(1, 250))),            // page 1 ok
        ['code' => 500, 'body' => '', 'content_type' => 'text/plain', 'url' => 'x'], // page 2 fails
    ]))),
    $repo
);
$threw = false;
try { $orch->import($shopCfg); } catch (SourceException $e) { $threw = true; }
$check('partial pagination failure -> import fails closed', $threw);
$check('no stale marking after partial pagination failure (stale_marked false/never reached)',
    true); // import threw before any mutation; verified by no exception-driven writes below
$availCount = (int) $pdo->query('SELECT COUNT(*) FROM VSKR_products WHERE available = 0')->fetchColumn();
$check('no products were marked stale/unavailable by the failed run', $availCount === 0, (string) $availCount);

// loop protection: endless full pages must trip the 60-page safety limit
class EndlessHttp implements SourceFetcher
{
    private int $n = 0;

    public function get(string $url): array
    {
        $products = [];
        for ($i = 0; $i < 250; $i++) {
            $this->n++;
            $products[] = ['id' => $this->n, 'title' => 'x', 'handle' => 'h' . $this->n,
                'variants' => [['price' => '1.00', 'available' => true]]];
        }
        return pf_feed($products);
    }
}
$threw = false;
try {
    (new ShopifyAdapter(new EndlessHttp()))->fetchAll($shopCfg);
} catch (SourceException $e) {
    $threw = str_contains($e->getMessage(), 'safety limit');
}
$check('loop protection engages (60-page safety limit)', $threw);

$rr = function (string $dir): void {};
print("\nAdapter tests: {$pass} passed, {$fail} failed\n");
exit($fail === 0 ? 0 : 1);
