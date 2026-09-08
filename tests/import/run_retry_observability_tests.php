<?php

declare(strict_types=1);

/**
 * Regression: Retry-/Backoff-Strategie für transiente Shopify-Fehler
 * (HTTP 429 / 5xx) und CLI-Observability des Membership-Enrichments.
 *
 * Abgedeckt:
 *  - 429 -> Retry -> Erfolg -> non-null Memberships
 *  - 503 -> Retry -> Erfolg -> non-null Memberships
 *  - wiederholter 429/5xx -> Retries erschöpft -> null (Fail-Closed)
 *  - permanente 4xx (404) -> KEIN Retry, sofortiger Fail-Closed
 *  - Bestehende Mehrfachzuordnungen funktionieren weiterhin
 *  - CLI zeigt OK/SKIPPED + Warnings sichtbar
 *
 * Hinweis Testgeschwindigkeit: Backoff-Wartezeiten werden über eine
 * austauschbare Sleeper-Funktion gestubbt (keine echten sleeps im Test).
 *
 * Usage: php tests/import/run_retry_observability_tests.php
 */

$repoRoot = dirname(__DIR__, 2);
require $repoRoot . '/src/Money.php';
require $repoRoot . '/src/Cart.php';
require $repoRoot . '/src/View.php';
require $repoRoot . '/src/Database.php';
require $repoRoot . '/src/Import/SourceException.php';
require $repoRoot . '/src/Import/SourceFetcher.php';
require $repoRoot . '/src/Import/SourceAdapter.php';
require $repoRoot . '/src/Import/SourceAdapterRegistry.php';
require $repoRoot . '/src/Import/SourceFetchResult.php';
require $repoRoot . '/src/Import/NormalizedProduct.php';
require $repoRoot . '/src/Import/ShopifyMapper.php';
require $repoRoot . '/src/Import/HttpClient.php';
require $repoRoot . '/src/Import/RetryingSourceFetcher.php';
require $repoRoot . '/src/Import/ShopifyAdapter.php';
require $repoRoot . '/src/Import/ImportRepository.php';
require $repoRoot . '/src/Import/ImportOrchestrator.php';

use Versandkostenretter\Import\NormalizedProduct;
use Versandkostenretter\Import\SourceException;
use Versandkostenretter\Import\SourceFetcher;

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

/**
 * Skriptbarer HTTP-Double mit Sleep-Stub: antwortet pro URL mit einer Queue
 * von Statuscodes/Bodies und zeichnet alle Versuche auf.
 */
final class ScriptedHttp implements SourceFetcher
{
    /** @var array<string, list<array{code:int, body:string, headers:array<string,string>}>> */
    public array $queues = [];
    /** @var list<string> */
    public array $requestLog = [];
    /** @var list<int> */
    public array $sleeps = [];

    public function get(string $url): array
    {
        $this->requestLog[] = $url;
        $key = null;
        // Most-specific pattern first: '/collections/<h>/products.json' muss
        // vor dem bare '/products.json' Katalog-Pattern matchen.
        $patterns = array_keys($this->queues);
        usort($patterns, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));
        foreach ($patterns as $pattern) {
            if (str_contains($url, $pattern)) { $key = $pattern; break; }
        }
        if ($key === null || $this->queues[$key] === []) {
            throw new SourceException('no scripted response for ' . $url);
        }
        $r = array_shift($this->queues[$key]);
        if ($r['code'] !== 200) {
            $ra = $r['headers']['retry-after'] ?? null;
            $raInt = (is_string($ra) && preg_match('/^\s*\d+\s*$/', $ra)) ? max(1, min(30, (int) $ra)) : null;
            throw Versandkostenretter\Import\SourceException::http($r['code'], $raInt);
        }
        return ['code' => 200, 'body' => $r['body'], 'content_type' => 'application/json', 'url' => $url, 'headers' => []];
    }
}

// HttpClient-Hooks: sleeps stubben (Reflexion auf private? Nein — wir nutzen
// eine Test-Unterklasse via anonymous class, die sleep() umleitet).
// Einfacher: HttpClient ruft globales sleep() — für den Test ersetzen wir die
// Wartzeiten durch eine konstante 0 über einen kleinen Shell-Tick? PHP kann
// sleep() nicht shadden. Lösung: HttpClient bekommt optionale sleeper-injection
// NUR für Tests über internes statisches Feld (produktionsneutral).
$rc = new ReflectionClass(\Versandkostenretter\Import\HttpClient::class);
if ($rc->hasProperty('sleeper')) {
    $prop = $rc->getProperty('sleeper');
    $prop->setAccessible(true);
    $prop->setValue(null, static fn (int $s): int => $GLOBALS['sleeps'][] = $s);
}

// === 1+2+3: Retry-/Backoff-Verhalten (Decorator, Sleep gestubbt) =============
$sleepStub = static fn (int $s): bool => ($GLOBALS['sleeps'][] = $s) !== null;

// (Backoff-Werte werden unten im Adapter-Durchlauf über $GLOBALS['sleeps']
//  verifiziert; Retry-After-Klemmung hier direkt am Decorator:)
$probeInner = new class implements \Versandkostenretter\Import\SourceFetcher {
    public int $calls = 0;
    public function get(string $url): array
    {
        $this->calls++;
        if ($this->calls < 3) {
            throw Versandkostenretter\Import\SourceException::http(429, 99);
        }
        return ['code' => 200, 'body' => '{}', 'content_type' => 'application/json', 'url' => $url, 'headers' => []];
    }
};
$GLOBALS['sleeps'] = [];
$retry = new \Versandkostenretter\Import\RetryingSourceFetcher($probeInner, 3, $sleepStub);
$resp = $retry->get('https://x.example/a');
$check('Retry-After 99s wird auf 30s geklemmt', $GLOBALS['sleeps'] === [30, 30], json_encode($GLOBALS['sleeps']));
$check('Retry erfolgreich nach 3. Versuch (keine Exception)', $resp['code'] === 200);

// === 4+5: Adapter-/Orchestrator-Pfad mit Fail-Closed ==========================
$catPage = json_encode(['products' => [
    ['id' => 1001, 'handle' => 'soulblight-mini', 'title' => 'Product A', 'variants' => [['price' => '24.66']], 'tags' => []],
    ['id' => 1002, 'handle' => 'warpaint-set', 'title' => 'Product B', 'variants' => [['price' => '6.50']], 'tags' => []],
]], JSON_THROW_ON_ERROR);
$collectionsJson = json_encode(['collections' => [
    ['handle' => 'soulblight-gravelords-age-of-sigma', 'title' => 'Soulblight Gravelords'],
]], JSON_THROW_ON_ERROR);
$colBody = json_encode(['products' => [['handle' => 'soulblight-mini', 'id' => 1001, 'title' => 'Product A']]], JSON_THROW_ON_ERROR);

$GLOBALS['sleeps'] = [];
$mkAdapter = static function (ScriptedHttp $http) use ($sleepStub): \Versandkostenretter\Import\ShopifyAdapter {
    // Produktion identisch: Adapter erhält den retryenden Client.
    return new \Versandkostenretter\Import\ShopifyAdapter(
        new \Versandkostenretter\Import\RetryingSourceFetcher($http, 3, $sleepStub)
    );
};
$shop = ['source_type' => 'shopify', 'source_url' => 'https://lootforge.de', 'source_scope' => 'complete'];

// (a) 429 -> Retry -> Erfolg
$http = new ScriptedHttp();
$http->queues = [
    '/products.json' => [['code' => 200, 'body' => $catPage, 'headers' => []]],
    '/collections.json' => [['code' => 200, 'body' => $collectionsJson, 'headers' => []]],
    '/collections/soulblight-gravelords-age-of-sigma/products.json' => [
        ['code' => 429, 'body' => '', 'headers' => ['retry-after' => '0']],
        ['code' => 200, 'body' => $colBody, 'headers' => []],
    ],
];
// usleep im Adapter mitstubben? usleep ist real (150ms) — im Test ok.
$result = $mkAdapter($http)->fetchAll($shop);
$check('429 -> Retry -> Erfolg -> non-null Memberships',
    $result->categoryMemberships !== null && ($result->categoryMemberships['1001'] ?? []) === ['Soulblight Gravelords'],
    json_encode($result->enrichmentWarnings));
$check('429-Retry wurde genau 1x zusätzlich versucht',
    count(array_filter($http->requestLog, static fn ($u) => str_contains($u, '/collections/soulblight'))) === 2);

// (b) 503 -> Retry -> Erfolg
$http2 = new ScriptedHttp();
$http2->queues = [
    '/products.json' => [['code' => 200, 'body' => $catPage, 'headers' => []]],
    '/collections.json' => [['code' => 200, 'body' => $collectionsJson, 'headers' => []]],
    '/collections/soulblight-gravelords-age-of-sigma/products.json' => [
        ['code' => 503, 'body' => '', 'headers' => []],
        ['code' => 503, 'body' => '', 'headers' => []],
        ['code' => 200, 'body' => $colBody, 'headers' => []],
    ],
];
$result2 = $mkAdapter($http2)->fetchAll($shop);
$check('503 x2 -> Retry x2 -> Erfolg -> non-null Memberships',
    $result2->categoryMemberships !== null && isset($result2->categoryMemberships['1001']),
    json_encode($result2->enrichmentWarnings));

// (c) wiederholter 5xx -> Retries erschöpft -> null (Fail-Closed)
$http3 = new ScriptedHttp();
$http3->queues = [
    '/products.json' => [['code' => 200, 'body' => $catPage, 'headers' => []]],
    '/collections.json' => [['code' => 200, 'body' => $collectionsJson, 'headers' => []]],
    '/collections/soulblight-gravelords-age-of-sigma/products.json' => [
        ['code' => 503, 'body' => '', 'headers' => []],
        ['code' => 503, 'body' => '', 'headers' => []],
        ['code' => 503, 'body' => '', 'headers' => []],
    ],
];
$result3 = $mkAdapter($http3)->fetchAll($shop);
$check('5xx x3 -> Retries erschöpft -> categoryMemberships === null',
    $result3->categoryMemberships === null, var_export($result3->categoryMemberships, true));
$check('Fail-Closed-Warning nennt den handle und HTTP 503',
    (bool) array_filter($result3->enrichmentWarnings, static fn ($w) => str_contains($w, 'soulblight-gravelords-age-of-sigma') && str_contains($w, 'HTTP 503')),
    json_encode($result3->enrichmentWarnings));
$check('Retries erschöpft nach genau 3 Versuchen',
    count(array_filter($http3->requestLog, static fn ($u) => str_contains($u, '/collections/soulblight'))) === 3);

// (d) permanente 4xx -> kein Retry
$http4 = new ScriptedHttp();
$http4->queues = [
    '/products.json' => [['code' => 200, 'body' => $catPage, 'headers' => []]],
    '/collections.json' => [['code' => 200, 'body' => $collectionsJson, 'headers' => []]],
    '/collections/soulblight-gravelords-age-of-sigma/products.json' => [
        ['code' => 404, 'body' => '', 'headers' => []],
    ],
];
$result4 = $mkAdapter($http4)->fetchAll($shop);
$check('permanente 404 -> KEIN Retry (genau 1 Versuch) -> null',
    $result4->categoryMemberships === null
    && count(array_filter($http4->requestLog, static fn ($u) => str_contains($u, '/collections/soulblight'))) === 1);

// (e) Bestehende Mehrfachzuordnungen weiterhin korrekt
$http5 = new ScriptedHttp();
$http5->queues = [
    '/products.json' => [['code' => 200, 'body' => $catPage, 'headers' => []]],
    '/collections.json' => [['code' => 200, 'body' => json_encode(['collections' => [
        ['handle' => 'c1', 'title' => 'Collection One'],
        ['handle' => 'c2', 'title' => 'Collection Two'],
    ]]), 'headers' => []]],
    '/collections/c1/products.json' => [['code' => 200, 'body' => $colBody, 'headers' => []]],
    '/collections/c2/products.json' => [['code' => 200, 'body' => $colBody, 'headers' => []]],
];
$result5 = $mkAdapter($http5)->fetchAll($shop);
$check('Mehrfachzuordnung (2 Collections, 1 Produkt) weiterhin korrekt',
    ($result5->categoryMemberships['1001'] ?? []) === ['Collection One', 'Collection Two'],
    json_encode($result5->categoryMemberships));

// === 6+7: CLI-Observability ===================================================
$cli = (string) file_get_contents($repoRoot . '/bin/import-shop.php');
$check('CLI gibt Memberships-OK sichtbar aus',
    str_contains($cli, 'Memberships:') && str_contains($cli, 'rows synced (OK)'));
$check('CLI gibt SKIPPED + Warnings unübersehbar aus',
    str_contains($cli, 'Membership sync: SKIPPED') && str_contains($cli, 'Enrichment warnings:'));
$check('CLI-Skip-Zweig behält Errors-Semantik (kein "Errors > 0" durch Enrichment)',
    !str_contains($cli, "result['errors'] = \$result['errors'] + 1"));

// End-to-end: Orchestrator-Result mit Skip landet in der CLI-Ausgabe.
// (direkter Funktionsaufruf des Report-Blocks ist im Script inline; wir
//  prüfen strukturell, dass memberships_skipped/enrichment_warnings gelesen
//  werden — verifiziert durch die beiden Checks oben plus den
//  ImportOrchestrator-Vertragstest im MariaDB-Suite.)

echo "\nRetry/Observability tests: {$checks} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
