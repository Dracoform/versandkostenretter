<?php

declare(strict_types=1);

/**
 * catalogue-snapshot.php — read-only full-catalogue dry run + snapshot export.
 *
 * Fetches a source (adapter-driven), normalizes products, writes a JSON and
 * CSV snapshot under storage/snapshots/ (gitignored — operational data, not
 * user data, no customer/order info) and prints a summary. NO database writes.
 *
 * Usage:
 *     php bin/catalogue-snapshot.php <shop-slug> [--out <dir>]
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/src/Money.php';
require dirname(__DIR__) . '/src/View.php';
require dirname(__DIR__) . '/src/Database.php';
require dirname(__DIR__) . '/src/Import/SourceException.php';
require dirname(__DIR__) . '/src/Import/SourceFetcher.php';
require dirname(__DIR__) . '/src/Import/HttpClient.php';
require dirname(__DIR__) . '/src/Import/SourceAdapter.php';
require dirname(__DIR__) . '/src/Import/SourceFetchResult.php';
require dirname(__DIR__) . '/src/Import/SourceCapabilities.php';
require dirname(__DIR__) . '/src/Import/AvailabilityChecker.php';
require dirname(__DIR__) . '/src/Import/SourceAdapterRegistry.php';
require dirname(__DIR__) . '/src/Import/NormalizedProduct.php';
require dirname(__DIR__) . '/src/Import/ShopifyMapper.php';
require dirname(__DIR__) . '/src/Import/ShopifyAdapter.php';
require dirname(__DIR__) . '/src/Import/ImportRepository.php';
require dirname(__DIR__) . '/src/Import/ImportOrchestrator.php';

use Versandkostenretter\Database;
use Versandkostenretter\Import\HttpClient;
use Versandkostenretter\Import\ImportOrchestrator;
use Versandkostenretter\Import\ShopifyAdapter;
use Versandkostenretter\Import\SourceAdapterRegistry;

$args = array_slice($argv, 1);
$slug = null;
$outDir = dirname(__DIR__) . '/storage/snapshots';
for ($i = 0; $i < count($args); $i++) {
    if ($args[$i] === '--out' && isset($args[$i + 1])) {
        $outDir = $args[$i + 1];
        $i++;
    } elseif ($slug === null && $args[$i][0] !== '-') {
        $slug = $args[$i];
    }
}
if ($slug === null || !preg_match('/^[a-z0-9-]{1,190}$/', $slug)) {
    fwrite(STDERR, "Usage: php bin/catalogue-snapshot.php <shop-slug> [--out <dir>]\n");
    exit(1);
}

$home = dirname(__DIR__);
$configCandidates = [dirname($home) . '/config/config.php', $home . '/config/config.php'];
$configPath = null;
foreach ($configCandidates as $c) {
    if (is_file($c)) { $configPath = $c; break; }
}
if ($configPath === null) {
    fwrite(STDERR, "Error: database configuration not found.\n");
    exit(2);
}

try {
    $pdo = Database::fromConfigFile($configPath)->pdo();
    $stmt = $pdo->prepare('SELECT id, name, slug, source_type, source_url, source_scope
        FROM VSKR_shops WHERE slug = :slug LIMIT 1');
    $stmt->execute([':slug' => $slug]);
    $shop = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    fwrite(STDERR, "Error: could not read shop configuration.\n");
    exit(2);
}
if ($shop === false) {
    fwrite(STDERR, "Error: no shop with slug \"{$slug}\".\n");
    exit(2);
}

$orchestrator = new ImportOrchestrator(
    new SourceAdapterRegistry(new ShopifyAdapter(new HttpClient())),
    new \Versandkostenretter\Import\ImportRepository($pdo)
);

try {
    $result = $orchestrator->import($shop, dryRun: true);
} catch (\Versandkostenretter\Import\SourceException $e) {
    fwrite(STDERR, 'Source error: ' . $e->getMessage() . "\n");
    exit(3);
}

/** @var list<\Versandkostenretter\Import\NormalizedProduct> $mapped */
$mapped = $result['mapped'];

// ------------------------------------------------------- stats
$states = ['available' => 0, 'unavailable' => 0, 'unknown' => 0];
$categories = [];
$prices = [];
$multiVarSkips = 0;
foreach ($mapped as $p) {
    $states[$p->availabilityState] = ($states[$p->availabilityState] ?? 0) + 1;
    if ($p->category !== null && $p->category !== '') {
        $categories[$p->category] = ($categories[$p->category] ?? 0) + 1;
    }
    $prices[] = $p->priceCents;
}
foreach ($result['skipped_items'] as $s) {
    if (str_contains($s['reason'] ?? '', 'different prices')) {
        $multiVarSkips++;
    }
}
arsort($categories); // most common first

$summary = [
    'shop' => $result['shop'],
    'source_type' => $result['source'],
    'source_scope' => $shop['source_scope'] ?? 'default',
    'generated_at' => date('c'),
    'requests' => $result['requests'],
    'complete' => $result['complete'],
    'total_products' => count($mapped),
    'available' => $states['available'],
    'unavailable' => $states['unavailable'],
    'unknown' => $states['unknown'],
    'skipped' => $result['skipped'],
    'variant_policy_skips' => $multiVarSkips,
    'category' => [
        'coverage_products_with_category' => count($categories) > 0 ? array_sum($categories) : 0,
        'distinct_categories' => count($categories),
        'top' => array_slice($categories, 0, 15, true),
    ],
    'price' => $prices === [] ? null : [
        'lowest' => \Versandkostenretter\Money::formatEuro(min($prices)),
        'highest' => \Versandkostenretter\Money::formatEuro(max($prices)),
    ],
];

// ------------------------------------------------------- snapshot files
if (!is_dir($outDir)) {
    @mkdir($outDir, 0775, true);
}
$stamp = date('Ymd-His');
$jsonPath = "{$outDir}/{$slug}-catalogue-{$stamp}.json";
$csvPath = "{$outDir}/{$slug}-catalogue-{$stamp}.csv";

file_put_contents($jsonPath, json_encode(
    ['summary' => $summary, 'products' => array_map(
        static fn ($p) => [
            'external_id' => $p->externalId,
            'name' => $p->name,
            'price' => \Versandkostenretter\Money::formatEuro($p->priceCents),
            'availability_state' => $p->availabilityState,
            'category' => $p->category,
            'canonical_url' => $p->canonicalUrl,
            'image_url' => $p->imageUrl,
            'source_updated_at' => $p->sourceUpdatedAt,
        ],
        $mapped
    )],
    JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
));

$csv = fopen($csvPath, 'w');
fwrite($csv, "\xEF\xBB\xBF"); // UTF-8 BOM for Excel
fputcsv($csv, ['external_id', 'name', 'price', 'availability_state', 'category', 'canonical_url', 'image_url', 'source_updated_at'], ';', '"', '\\');
foreach ($mapped as $p) {
    fputcsv($csv, [
        $p->externalId, $p->name,
        \Versandkostenretter\Money::formatEuro($p->priceCents),
        $p->availabilityState, $p->category, $p->canonicalUrl, $p->imageUrl ?? '', $p->sourceUpdatedAt ?? '',
    ], ';', '"', '\\');
}
fclose($csv);

// ------------------------------------------------------- stdout summary
fwrite(STDOUT, <<<TXT
DRY RUN — no database writes (complete={$result['complete']})
Shop: {$result['shop']}
Source: {$result['source']} (scope: {$summary['source_scope']})
Fetched: {$result['fetched']}
  available:   {$states['available']}
  unavailable: {$states['unavailable']}
  unknown:     {$states['unknown']}
Skipped: {$result['skipped']} (variant-policy skips: {$multiVarSkips})
Requests: {$result['requests']}
Price range: {$summary['price']['lowest']} – {$summary['price']['highest']}
Distinct categories: {$summary['category']['distinct_categories']}
Snapshot JSON: {$jsonPath}
Snapshot CSV:  {$csvPath}

TXT);
fwrite(STDOUT, sprintf(
    "Snapshot sizes: JSON %.0f KB, CSV %.0f KB\n",
    is_file($jsonPath) ? filesize($jsonPath) / 1024 : 0,
    is_file($csvPath) ? filesize($csvPath) / 1024 : 0
));
foreach (array_slice($summary['category']['top'], 0, 10, true) as $cat => $n) {
    fwrite(STDOUT, sprintf("  cat %4d  %s\n", $n, $cat));
}
exit(0);
