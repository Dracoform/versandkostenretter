<?php

declare(strict_types=1);

/**
 * import-shop.php — manually-triggered product importer (cron-ready).
 *
 * Production usage (via SSH on the host):
 *     php /versandkostenretter.de/httpdocs/bin/import-shop.php lootforge
 *     php /versandkostenretter.de/httpdocs/bin/import-shop.php lootforge --dry-run
 *
 * Source-adapter architecture: VSKR_shops.source_type selects a registered
 * adapter (shopify, csv/json/xml/affiliate feeds later). Adding a second
 * Shopify shop requires only a new VSKR_shops row — never new importer code.
 *
 * The operator off-switch is VSKR_shops.active: a deactivated shop is skipped
 * (use bin/shop-toggle.php to deactivate/reactivate, see docs there).
 *
 * Exit codes: 0 success, 1 usage, 2 shop/config not found,
 *             3 source fetch/validation failure, 4 database failure,
 *             5 another import is already running, 6 shop deactivated.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

use Versandkostenretter\Database;
use Versandkostenretter\Import\AvailabilityChecker;
use Versandkostenretter\Import\HttpClient;
use Versandkostenretter\Import\ImportOrchestrator;
use Versandkostenretter\Import\ImportRepository;
use Versandkostenretter\Import\NormalizedProduct;
use Versandkostenretter\Import\RetryingSourceFetcher;
use Versandkostenretter\Import\ShopifyAdapter;
use Versandkostenretter\Import\ShopifyMapper;
use Versandkostenretter\Import\SourceAdapter;
use Versandkostenretter\Import\SourceAdapterRegistry;
use Versandkostenretter\Import\SourceException;
use Versandkostenretter\Import\SourceCapabilities;
use Versandkostenretter\Import\SourceFetchResult;
use Versandkostenretter\Import\SourceFetcher;

// PSR-4-style autoloader: Versandkostenretter\... => src/... (subnamespaces
// map to subdirectories). Class/interface dependency order is resolved by
// PHP at first use — no manual require ordering that can break.
spl_autoload_register(static function (string $class): void {
    if (!str_starts_with($class, 'Versandkostenretter\\')) {
        return;
    }
    $file = dirname(__DIR__) . '/src/' . str_replace('\\', '/', substr($class, strlen('Versandkostenretter\\'))) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

const EXIT_OK = 0;
const EXIT_USAGE = 1;
const EXIT_SHOP = 2;
const EXIT_SOURCE = 3;
const EXIT_DB = 4;
const EXIT_LOCKED = 5;
const EXIT_INACTIVE = 6;

// ---------------------------------------------------------------- args
$args = array_slice($argv, 1);
$dryRun = false;
$force = false;
$slug = null;
foreach ($args as $a) {
    if ($a === '--dry-run') {
        $dryRun = true;
    } elseif ($a === '--force') {
        $force = true; // run even if the shop is deactivated (operator override)
    } elseif ($a === '--help' || $a === '-h') {
        fwrite(STDOUT, "Usage: php bin/import-shop.php <shop-slug> [--dry-run] [--force]\n");
        exit(EXIT_OK);
    } elseif ($slug === null && $a[0] !== '-') {
        $slug = $a;
    }
}
if ($slug === null || !preg_match('/^[a-z0-9-]{1,190}$/', $slug)) {
    fwrite(STDERR, "Usage: php bin/import-shop.php <shop-slug> [--dry-run] [--force]\n");
    exit(EXIT_USAGE);
}

// ---------------------------------------------------------------- config
// Same resolution order as the web app: OUTSIDE the document root first.
$home = dirname(__DIR__);                                   // .../httpdocs
$configCandidates = [
    dirname($home) . '/config/config.php',                  // /versandkostenretter.de/config/config.php
    $home . '/config/config.php',                           // local dev fallback (gitignored)
];
$configPath = null;
foreach ($configCandidates as $c) {
    if (is_file($c)) {
        $configPath = $c;
        break;
    }
}
if ($configPath === null) {
    fwrite(STDERR, "Error: database configuration not found (expected outside the document root).\n");
    exit(EXIT_SHOP);
}
$config = require $configPath;
if (!is_array($config)) {
    fwrite(STDERR, "Error: invalid configuration file.\n");
    exit(EXIT_SHOP);
}

set_exception_handler(static function (Throwable $e): void {
    // Never leak credentials: print the exception message class only.
    fwrite(STDERR, 'Error: ' . $e->getMessage() . "\n");
    exit(EXIT_DB);
});

try {
    $pdo = Database::fromConfigFile($configPath)->pdo();
} catch (Throwable $e) {
    fwrite(STDERR, "Error: could not open database connection.\n");
    exit(EXIT_DB);
}

// ---------------------------------------------------------------- shop lookup
$stmt = $pdo->prepare(
    'SELECT id, name, slug, active, source_type, source_url, source_scope
     FROM VSKR_shops WHERE slug = :slug LIMIT 1'
);
$stmt->execute([':slug' => $slug]);
/** @var array<string,mixed>|false $shop */
$shop = $stmt->fetch(PDO::FETCH_ASSOC);
if ($shop === false) {
    fwrite(STDERR, "Error: no shop with slug \"{$slug}\".\n");
    exit(EXIT_SHOP);
}

// Operator off-switch: deactivated shops are skipped by default.
if ((int) ($shop['active'] ?? 1) !== 1) {
    if (!$force) {
        fwrite(STDERR, "Shop \"{$slug}\" is deactivated (active = 0). Import skipped.\n"
            . "Use --force to import anyway (e.g. while preparing a reactivation).\n");
        exit(EXIT_INACTIVE);
    }
    fwrite(STDOUT, "Note: shop is deactivated (active = 0) — running because --force was given.\n");
}

if (($shop['source_type'] ?? null) === null || ($shop['source_url'] ?? null) === null || $shop['source_url'] === '') {
    fwrite(STDERR, "Error: shop \"{$slug}\" has no import source configured.\n");
    exit(EXIT_SHOP);
}

// ---------------------------------------------------------------- run
$registry = new SourceAdapterRegistry(
    new ShopifyAdapter(new RetryingSourceFetcher(new HttpClient()))
);
$repo = new ImportRepository($pdo);
$orchestrator = new ImportOrchestrator($registry, $repo);

if (!$repo->acquireShopLock((string) $shop['slug'])) {
    fwrite(STDERR, "Error: an import for \"{$slug}\" is already running. Aborting.\n");
    exit(EXIT_LOCKED);
}

$exitCode = EXIT_OK;
try {
    $result = $orchestrator->import($shop, $dryRun);
} catch (Versandkostenretter\Import\SourceException $e) {
    fwrite(STDERR, 'Source error: ' . $e->getMessage() . "\n");
    $exitCode = EXIT_SOURCE;
} catch (Throwable $e) {
    fwrite(STDERR, 'Error: ' . $e->getMessage() . "\n");
    $exitCode = EXIT_DB;
}
$repo->releaseShopLock((string) $shop['slug']);
if ($exitCode !== EXIT_OK) {
    exit($exitCode);
}

// ---------------------------------------------------------------- report
fwrite(STDOUT, ($dryRun ? 'DRY RUN — no database writes (complete='
    . var_export($result['complete'], true) . ")\n" : '') . <<<TXT
Shop: {$result['shop']}
Source: {$result['source']}
Fetched: {$result['fetched']}
Inserted: {$result['inserted']}
Updated: {$result['updated']}
Unavailable: {$result['unavailable']}
Skipped: {$result['skipped']}
Errors: {$result['errors']}
Requests: {$result['requests']}

TXT);

if ($dryRun && isset($result['mapped'])) {
    foreach ($result['mapped'] as $i => $p) {
        $price = \Versandkostenretter\Money::formatEuro($p->priceCents);
        $avail = ['available' => 'yes', 'unavailable' => 'NO', 'unknown' => '?'][$p->availabilityState] ?? '?';
        $cat = $p->category ?? '-';
        fwrite(STDOUT, sprintf(
            "  %d. %s | %s | %s | cat=%s | avail=%s\n      %s\n",
            $i + 1, $p->externalId, $p->name, $price, $cat, $avail, $p->canonicalUrl
        ));
    }
}
foreach ($result['skipped_items'] as $s) {
    fwrite(STDERR, "  skipped: [{$s['id']}] {$s['title']}: {$s['reason']}\n");
}
if (!$dryRun && ($result['unavailable'] ?? 0) > 0) {
    fwrite(STDOUT, "  note: {$result['unavailable']} product(s) absent from this COMPLETE source run were marked unavailable.\n");
}

// Membership-Enrichment: Zustand UNÜBERSEHBAR ausgeben. Ein Fail-Closed-Skip
// ist kein Import-Fehler (Errors bleibt korrekt 0), muss aber für den
// Operator sichtbar sein.
if (!$dryRun && array_key_exists('complete', $result) && $result['complete']) {
    if (isset($result['memberships'])) {
        fwrite(STDOUT, "Memberships: {$result['memberships']} rows synced (OK)\n");
    } elseif (!empty($result['memberships_skipped'])) {
        fwrite(STDOUT, "Membership sync: SKIPPED — collection enrichment failed; previously persisted memberships were KEPT.\n");
        $warnings = $result['enrichment_warnings'] ?? [];
        fwrite(STDOUT, 'Enrichment warnings: ' . count($warnings) . "\n");
        foreach (array_slice($warnings, 0, 5) as $w) {
            fwrite(STDOUT, "  - {$w}\n");
        }
        if (count($warnings) > 5) {
            fwrite(STDOUT, '  ... and ' . (count($warnings) - 5) . " more\n");
        }
    } else {
        // Quellen ohne Collection-Support (z. B. ältere Adapter): kein Zustand.
        fwrite(STDOUT, "Membership sync: not applicable for this source\n");
    }
}

exit(EXIT_OK);
