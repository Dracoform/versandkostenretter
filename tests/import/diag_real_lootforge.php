<?php

declare(strict_types=1);

/**
 * DIAGNOSE-/INTEGRATIONSSKRIPT: reale öffentliche Lootforge-Storefront gegen
 * den REALEN ShopifyAdapter (mit Retry-Decorator) — OHNE Datenbank.
 *
 * Zweck: verifiziert den kompletten Collection-Enrichment-Datenpfad gegen
 * echte Shopify-Antworten (Request-Breakdown, handle↔external_id-Join,
 * Adapter-Endzustand null/[]/N). Schreibgeschützt, keine Credentials, keine
 * Production-DB.
 *
 * ACHTUNG Rate-Limit: Shopify drosselt Burst-Requests (real beobachtet:
 * 429/503). Das Skript macht EINEN Adapter-Lauf (~100 Requests, mit
 * eingebauter Drossel); bei 429 einige Minuten warten und erneut ausführen.
 *
 * Usage: /usr/bin/php8.3 tests/import/diag_real_lootforge.php
 *        (PHP 8.3+; http-Wrapper; kein DB-Zugriff)
 *
 * Erwartung bei intaktem Enrichment: categoryMemberships !== null mit
 * ~900+ Einträgen; enrichmentWarnings leer.
 */
$repoRoot = dirname(__DIR__, 2);
require $repoRoot . '/src/Money.php';
require $repoRoot . '/src/Cart.php';
require $repoRoot . '/src/View.php';
require $repoRoot . '/src/Import/SourceException.php';
require $repoRoot . '/src/Import/SourceFetcher.php';
require $repoRoot . '/src/Import/HttpClient.php';
require $repoRoot . '/src/Import/RetryingSourceFetcher.php';
require $repoRoot . '/src/Import/SourceAdapter.php';
require $repoRoot . '/src/Import/SourceFetchResult.php';
require $repoRoot . '/src/Import/NormalizedProduct.php';
require $repoRoot . '/src/Import/ShopifyMapper.php';
require $repoRoot . '/src/Import/ShopifyAdapter.php';

use Versandkostenretter\Import\ShopifyAdapter;

/** HTTP-Client mit Request-Log + Body-Cache (wrappt den REALEN HttpClient). */
final class LoggingHttp implements \Versandkostenretter\Import\SourceFetcher
{
    /** @var list<string> */
    public array $log = [];
    /** @var array<string,string> */
    public array $bodies = [];

    public function __construct(private \Versandkostenretter\Import\HttpClient $inner) {}

    public function get(string $url): array
    {
        $this->log[] = $url;
        $resp = $this->inner->get($url);
        $this->bodies[$url] = $resp['body'];
        return $resp;
    }
}

$http = new LoggingHttp(new Versandkostenretter\Import\HttpClient());
$adapter = new ShopifyAdapter($http);
$shop = [
    'name' => 'Lootforge',
    'source_type' => 'shopify',
    'source_url' => 'https://lootforge.de',
    'source_scope' => 'complete',
];

$result = $adapter->fetchAll($shop);

echo "== REQUEST-BREAKDOWN ==\n";
$catalogue = 0; $discovery = 0; $collectionReqs = [];
foreach ($http->log as $url) {
    if (str_contains($url, '/collections.json')) {
        $discovery++;
    } elseif (preg_match('~/collections/([^/]+)/products\.json~', $url, $m)) {
        $collectionReqs[] = $m[1];
    } elseif (str_contains($url, '/products.json')) {
        $catalogue++;
    }
}
echo "Produktpagination:    {$catalogue}\n";
echo "Collection-Discovery: {$discovery}\n";
echo "Collection-Products:  " . count($collectionReqs) . "\n";
echo "Gesamt:               " . count($http->log) . "\n\n";

// Discovery-Body aus dem Lauf selbst (kein zweiter Request):
$discoveryUrl = null;
foreach ($http->log as $u) { if (str_contains($u, '/collections.json')) { $discoveryUrl = $u; break; } }
$cols = json_decode($http->bodies[$discoveryUrl] ?? '[]', true)['collections'] ?? [];
echo "== COLLECTIONS ==\n";
echo "entdeckte Collections: " . count($cols) . "\n";

$sbUrl = null;
foreach ($http->log as $u) {
    if (str_contains($u, '/collections/soulblight-gravelords-age-of-sigma/products.json')) { $sbUrl = $u; break; }
}
echo "\n== SOULBLIGHT-COLLECTION ==\n";
$sbMeta = array_values(array_filter($cols, static fn ($c) => ($c['handle'] ?? '') === 'soulblight-gravelords-age-of-sigma'));
echo "entdeckt: " . ($sbMeta !== [] ? 'JA' : 'NEIN') . "\n";
if ($sbMeta !== []) {
    echo "handle: {$sbMeta[0]['handle']}\n";
    echo "title:  {$sbMeta[0]['title']}\n";
    echo "products_count laut discovery: {$sbMeta[0]['products_count']}\n";
}
echo "im Lauf angefragt: " . ($sbUrl !== null ? 'JA' : 'NEIN') . "\n";
if ($sbUrl !== null) {
    $sbProds = json_decode($http->bodies[$sbUrl], true)['products'] ?? [];
    echo "products.json Produkte: " . count($sbProds) . "\n";
    $rep = $sbProds[0] ?? null;
    if ($rep) {
        echo "repräsentatives Produkt: id={$rep['id']} handle={$rep['handle']} title={$rep['title']}\n";
        echo "Struktur-Keys: " . implode(',', array_slice(array_keys($rep), 0, 8)) . "...\n";
    }

    echo "\n== JOIN: handle (aus canonicalUrl) vs collection products.json ==\n";
    $byHandle = [];
    foreach ($result->products as $p) {
        if (preg_match('~/products/([^/?#]+)(?:[?#].*)?$~', $p->canonicalUrl, $m)) {
            $byHandle[$m[1]] = $p->externalId;
        }
    }
    foreach ($sbProds as $cp) {
        $h = $cp['handle'];
        echo "collection handle={$h}  catalogue external_id=" . ($byHandle[$h] ?? 'KEIN MATCH') . "\n";
    }
}

echo "\n== ADAPTER-ENDZUSTAND ==\n";
$m = $result->categoryMemberships;
echo "categoryMemberships: " . ($m === null ? 'NULL' : (count($m) === 0 ? '[] (leer)' : count($m) . ' Einträge')) . "\n";
echo "result->requests: {$result->requests}\n";
echo "enrichmentWarnings: " . (count($result->enrichmentWarnings) === 0 ? '(keine)' : '') . "\n";
foreach (array_slice($result->enrichmentWarnings, 0, 10) as $w) {
    echo "  - {$w}\n";
}
if (is_array($m) && $m !== []) {
    echo "\nBeispiel-Memberships (erste 5):\n";
    $i = 0;
    foreach ($m as $eid => $cats) {
        echo "  {$eid} => " . implode(' | ', $cats) . "\n";
        if (++$i >= 5) break;
    }
    // Soulblight-Produkt in der Membership-Map?
    $sbEids = [];
    foreach ($sbProds ?? [] as $cp) {
        $eid = $byHandle[$cp['handle']] ?? null;
        if ($eid !== null) { $sbEids[] = $eid; }
    }
    foreach ($sbEids as $eid) {
        echo "Soulblight-Produkt {$eid} in Memberships: " . (isset($m[$eid]) ? 'JA (' . implode(' | ', $m[$eid]) . ')' : 'NEIN') . "\n";
    }
}
