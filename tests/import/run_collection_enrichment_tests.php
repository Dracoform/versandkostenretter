<?php

declare(strict_types=1);

/**
 * Regression: ShopifyAdapter collection enrichment (the REAL preg_match path).
 *
 * Production bug: the regex '#/products/([^/?#]+)$#' used '#' as delimiter
 * while the character class [^/?#] contains an unescaped '#'. PCRE treats the
 * delimiter as terminating the pattern EVEN INSIDE a character class, so the
 * pattern ended after [^/? and everything after ('#]+)$#') was parsed as
 * flags -> "preg_match(): Unknown modifier ']'". preg_match() returned false,
 * the handle->external_id map stayed EMPTY, and enrichment produced zero
 * memberships while reporting success.
 *
 * This test executes fetchCollectionMemberships() through a public seam:
 * a fake HTTP client feeding realistic Shopify fixtures (including the
 * soulblight-gravelords-age-of-sigma handle) into the REAL adapter.
 *
 * Usage: php tests/import/run_collection_enrichment_tests.php (any PHP 8.3+)
 */

$repoRoot = dirname(__DIR__, 2);
require $repoRoot . '/src/Money.php';
require $repoRoot . '/src/Cart.php';
require $repoRoot . '/src/View.php';
require $repoRoot . '/src/Database.php';
require $repoRoot . '/src/Import/SourceException.php';
require $repoRoot . '/src/Import/SourceFetcher.php';
require $repoRoot . '/src/Import/SourceAdapter.php';
require $repoRoot . '/src/Import/SourceFetchResult.php';
require $repoRoot . '/src/Import/NormalizedProduct.php';
require $repoRoot . '/src/Import/ShopifyMapper.php';
require $repoRoot . '/src/Import/ShopifyAdapter.php';

use Versandkostenretter\Import\NormalizedProduct;
use Versandkostenretter\Import\ShopifyAdapter;

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

/** Minimal HTTP client double recording requests and serving fixtures. */
final class FakeHttp implements \Versandkostenretter\Import\SourceFetcher
{
    /** @var list<string> */
    public array $requests = [];

    public function __construct(private array $routes) {}

    public function get(string $url): array
    {
        $this->requests[] = $url;
        // Most-specific route first: '/collections/<h>/products.json' must
        // win over the bare '/products.json' catalogue pattern.
        $patterns = array_keys($this->routes);
        usort($patterns, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));
        foreach ($patterns as $pattern) {
            if (str_contains($url, $pattern)) {
                $response = $this->routes[$pattern];
                if ($response === null) {
                    throw new RuntimeException('connection failed for ' . $url);
                }
                return ['body' => $response, 'content_type' => 'application/json'];
            }
        }
        throw new RuntimeException('no fixture for ' . $url);
    }
}

$catPage = json_encode(['products' => [
    ['id' => 1001, 'handle' => 'soulblight-mini', 'title' => 'Product A', 'variants' => [['price' => '24.66']], 'tags' => []],
    ['id' => 1002, 'handle' => 'warpaint-set', 'title' => 'Product B', 'variants' => [['price' => '6.50']], 'tags' => []],
    ['id' => 1003, 'handle' => 'with-query', 'title' => 'Product C', 'variants' => [['price' => '5.20']], 'tags' => []],
]], JSON_THROW_ON_ERROR);

$mkAdapter = static fn (FakeHttp $http): ShopifyAdapter =>
    new ShopifyAdapter($http);

// Representative REAL handles (incl. the production one).
$HANDLE_MAIN = 'soulblight-gravelords-age-of-sigma';
$HANDLE_PAINT = 'warpaints-fanatic';

$collectionsJson = json_encode([
    'collections' => [
        ['handle' => $HANDLE_MAIN, 'title' => 'Soulblight Gravelords'],
        ['handle' => $HANDLE_PAINT, 'title' => 'Warpaints Fanatic'],
        ['handle' => 'frontpage', 'title' => 'Startseite'], // blocklisted
        ['handle' => 'https://evil.example/x', 'title' => 'URL handle'], // rejected
    ],
], JSON_THROW_ON_ERROR);

$col = static fn (array $handles): string => json_encode(
    ['products' => array_map(static fn (string $h): array => ['handle' => $h], $handles)],
    JSON_THROW_ON_ERROR
);

$mkProducts = static fn (): array => [
    new NormalizedProduct(
        externalId: '1001', name: 'Product A',
        canonicalUrl: 'https://lootforge.de/products/soulblight-mini',
        priceCents: 2466, availabilityState: 'available',
        category: null, imageUrl: null, sourceUpdatedAt: null,
    ),
    new NormalizedProduct(
        externalId: '1002', name: 'Product B',
        canonicalUrl: 'https://lootforge.de/products/warpaint-set',
        priceCents: 650, availabilityState: 'available',
        category: null, imageUrl: null, sourceUpdatedAt: null,
    ),
    new NormalizedProduct(
        externalId: '1003', name: 'Product C (query URL)',
        canonicalUrl: 'https://lootforge.de/products/with-query?ref=x',
        priceCents: 520, availabilityState: 'available',
        category: null, imageUrl: null, sourceUpdatedAt: null,
    ),
];

$shop = ['source_type' => 'shopify', 'source_url' => 'https://lootforge.de', 'source_scope' => 'complete'];

// --- 1. Happy path: non-zero memberships, multi-collection, request counting --
$http = new FakeHttp([
    '/products.json' => $catPage,
    '/collections.json' => $collectionsJson,
    '/collections/' . $HANDLE_MAIN . '/products.json' => $col(['soulblight-mini', 'warpaint-set']),
    '/collections/' . $HANDLE_PAINT . '/products.json' => $col(['warpaint-set', 'with-query']),
]);
$result = $mkAdapter($http)->fetchAll($shop);

$check('no preg warnings on the real path (fixed regex)',
    !in_array('The preg_match path emitted warnings', $result->enrichmentWarnings, true)
    && count($result->categoryMemberships ?? []) > 0,
    json_encode($result->enrichmentWarnings));

$m = $result->categoryMemberships ?? [];
$check('SourceFetchResult contains non-zero memberships', count($m) === 3, json_encode($m));
$check('product 1001 mapped via its handle',
    ($m['1001'] ?? []) === ['Soulblight Gravelords'], json_encode($m['1001'] ?? null));
$check('ONE product belongs to MULTIPLE collections',
    ($m['1002'] ?? []) === ['Soulblight Gravelords', 'Warpaints Fanatic'], json_encode($m['1002'] ?? null));
$check('canonical URL with query string still maps',
    ($m['1003'] ?? []) === ['Warpaints Fanatic'], json_encode($m['1003'] ?? null));
$check('blocklisted/URL-handle collections excluded from membership titles',
    !in_array('Startseite', array_merge(...array_values($m)), true)
    && !in_array('URL handle', array_merge(...array_values($m)), true));
$check('request count = 1 catalogue page + 1 discovery + 2 usable collections',
    $result->requests === 1 + 1 + 2, 'requests=' . $result->requests);

// --- 2. Discovery failure => null memberships (fail closed) --------------------
$http2 = new FakeHttp(['/products.json' => $catPage, '/collections.json' => 'not-json']);
$result2 = $mkAdapter($http2)->fetchAll($shop);
$check('broken discovery => categoryMemberships === null (never [])',
    $result2->categoryMemberships === null, var_export($result2->categoryMemberships, true));
$check('broken discovery recorded as warning, not silent',
    $result2->enrichmentWarnings !== []);

// --- 3. Single collection fetch failure => null (partial data not trusted) ------
$http3 = new FakeHttp([
    '/products.json' => $catPage,
    '/collections.json' => $collectionsJson,
    '/collections/' . $HANDLE_MAIN . '/products.json' => $col(['soulblight-mini']),
    '/collections/' . $HANDLE_PAINT . '/products.json' => null, // transport fails
]);
$result3 = $mkAdapter($http3)->fetchAll($shop);
$check('one failed collection fetch => null memberships (fail closed)',
    $result3->categoryMemberships === null, var_export($result3->categoryMemberships, true));
$check('failed collection named in warnings',
    (bool) array_filter($result3->enrichmentWarnings,
        static fn (string $w): bool => str_contains($w, $HANDLE_PAINT)),
    json_encode($result3->enrichmentWarnings));

// --- 4. Legit zero: shop without usable collections => [] (authoritative) -------
$http4 = new FakeHttp([
    '/products.json' => $catPage,
    '/collections.json' => json_encode(['collections' => []]),
]);
$result4 = $mkAdapter($http4)->fetchAll($shop);
$check('zero usable collections => authoritative [] (replace allowed)',
    $result4->categoryMemberships === [], var_export($result4->categoryMemberships, true));

// --- 5. Shop WITHOUT collection support unaffected (single-collection scope) ----
$http5 = new FakeHttp([
    '/products.json' => $catPage,
    '/collections/zubehor-furs-malen/products.json' => json_encode([
        'products' => [['id' => 1001, 'handle' => 'soulblight-mini', 'title' => 'Product A', 'variants' => [['price' => '24.66']]]],
    ]),
]);
$shopSingle = ['source_type' => 'shopify', 'source_url' => 'https://lootforge.de/collections/zubehor-furs-malen/products.json', 'source_scope' => 'default'];
$result5 = $mkAdapter($http5)->fetchAll($shopSingle);
$check('single-collection scope imports normally, no memberships attempted',
    count($result5->products) === 1 && $result5->categoryMemberships === null,
    json_encode([count($result5->products), $result5->categoryMemberships]));

echo "\nCollection-enrichment tests: {$checks} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
