<?php

declare(strict_types=1);

/**
 * Live dry-run check: real HTTP fetch + mapping against the Lootforge feed,
 * ZERO database access. Usage: php tests/import/live_dry_run_check.php
 */

require __DIR__ . '/../../src/Money.php';
require __DIR__ . '/../../src/Cart.php';
require __DIR__ . '/../../src/View.php';
require __DIR__ . '/../../src/Database.php';
require __DIR__ . '/../../src/Import/SourceFetcher.php';
require __DIR__ . '/../../src/Import/HttpClient.php';
require __DIR__ . '/../../src/Import/SourceException.php';
require __DIR__ . '/../../src/Import/ShopifyMapper.php';
require __DIR__ . '/../../src/Import/ImportRepository.php';
require __DIR__ . '/../../src/Import/ShopifyImporter.php';

use Versandkostenretter\Import\HttpClient;
use Versandkostenretter\Import\ShopifyImporter;

$shop = ['id' => 0, 'name' => 'Lootforge', 'slug' => 'lootforge', 'source_type' => 'shopify',
    'source_url' => 'https://lootforge.de/collections/zubehor-furs-malen/products.json?limit=250',
    'source_scope' => 'zubehor-furs-malen'];

$importer = new ShopifyImporter(new HttpClient(), null); // repo never used in dry-run
$res = $importer->import($shop, dryRun: true);

foreach ($res['mapped'] as $i => $p) {
    printf("  %d. %s | %s | %.2f EUR | cat=%s | avail=%s\n      %s\n",
        $i + 1, $p['external_id'], $p['name'], $p['price_cents'] / 100,
        $p['category'] ?? '-', $p['available'] ? 'yes' : 'NO', $p['url']);
}
foreach ($res['skipped_items'] ?? [] as $s) {
    printf("  skipped: [%s] %s: %s\n", $s['id'], $s['title'], $s['reason']);
}
