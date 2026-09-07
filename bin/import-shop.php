<?php

declare(strict_types=1);

/**
 * import-shop.php — manually-triggered product importer.
 *
 * Production usage (via SSH on the host):
 *     php /versandkostenretter.de/httpdocs/bin/import-shop.php lootforge
 *     php /versandkostenretter.de/httpdocs/bin/import-shop.php lootforge --dry-run
 *
 * Suitable for unattended cron later: no prompts, meaningful exit codes,
 * no secrets in output. Not web-accessible (bin/.htaccess denies HTTP).
 *
 * Exit codes: 0 success, 1 usage error, 2 shop/config not found,
 *             3 source fetch/validation failure, 4 database failure,
 *             5 another import is already running.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

// One robust autoloader for the whole Versandkostenretter namespace
// (PSR-4-style, mapping Versandkostenretter\ => src/ and
//  Versandkostenretter\Import\ => src/Import/). Class/interface dependency
// order is resolved by PHP at first use — no manual require ordering that
// can break (this previously fatalled: HttpClient implements SourceFetcher
// before the interface file had been required).
spl_autoload_register(function (string $class): void {
    if (!str_starts_with($class, 'Versandkostenretter\\')) {
        return;
    }
    $relative = substr($class, strlen('Versandkostenretter\\'));
    $file = dirname(__DIR__) . '/src/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

use Versandkostenretter\Database;

const EXIT_OK = 0;
const EXIT_USAGE = 1;
const EXIT_SHOP = 2;
const EXIT_SOURCE = 3;
const EXIT_DB = 4;
const EXIT_LOCKED = 5;

// ---------------------------------------------------------------- args
$args = array_slice($argv, 1);
$dryRun = false;
$slug = null;
foreach ($args as $a) {
    if ($a === '--dry-run') {
        $dryRun = true;
    } elseif ($a === '--help' || $a === '-h') {
        fwrite(STDOUT, "Usage: php bin/import-shop.php <shop-slug> [--dry-run]\n");
        exit(EXIT_OK);
    } elseif ($slug === null && $a[0] !== '-') {
        $slug = $a;
    }
}
if ($slug === null || !preg_match('/^[a-z0-9-]{1,190}$/', $slug)) {
    fwrite(STDERR, "Usage: php bin/import-shop.php <shop-slug> [--dry-run]\n");
    exit(EXIT_USAGE);
}

// ---------------------------------------------------------------- config
// Same resolution order as the web app: OUTSIDE the document root first.
$home = dirname(__DIR__);                       // .../httpdocs
$configCandidates = [
    dirname($home) . '/config/config.php',      // /versandkostenretter.de/config/config.php
    $home . '/config/config.php',               // local dev fallback (gitignored)
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

// ---------------------------------------------------------------- db
set_exception_handler(function (Throwable $e) use ($dryRun): void {
    // Never leak credentials: print a generic class of error only.
    fwrite(STDERR, 'Error: ' . $e->getMessage() . "\n");
    exit(EXIT_DB);
});

try {
    $db = Database::fromConfigFile($configPath);
    $pdo = $db->pdo();
} catch (Throwable $e) {
    fwrite(STDERR, "Error: could not open database connection.\n");
    exit(EXIT_DB);
}

// ---------------------------------------------------------------- shop lookup
$shopCols = 'id, name, slug, source_type, source_url, source_scope';
$stmt = $pdo->prepare("SELECT {$shopCols} FROM VSKR_shops WHERE slug = :slug AND active = 1 LIMIT 1");
$stmt->execute([':slug' => $slug]);
/** @var array<string,mixed>|false $shop */
$shop = $stmt->fetch(PDO::FETCH_ASSOC);
if ($shop === false) {
    fwrite(STDERR, "Error: no active shop with slug \"{$slug}\".\n");
    exit(EXIT_SHOP);
}
if (($shop['source_type'] ?? null) === null || ($shop['source_url'] ?? null) === null || $shop['source_url'] === '') {
    fwrite(STDERR, "Error: shop \"{$slug}\" has no import source configured.\n");
    exit(EXIT_SHOP);
}

// ---------------------------------------------------------------- run
$repo = new Versandkostenretter\Import\ImportRepository($pdo);
$importer = new Versandkostenretter\Import\ShopifyImporter(new Versandkostenretter\Import\HttpClient(), $repo);

if (!$repo->acquireShopLock((string) $shop['slug'])) {
    fwrite(STDERR, "Error: an import for \"{$slug}\" is already running. Aborting.\n");
    exit(EXIT_LOCKED);
}

$exitCode = EXIT_OK;
try {
    $result = $importer->import($shop, $dryRun);
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
fwrite(STDOUT, ($dryRun ? 'DRY RUN — no database writes\n' : '') . <<<TXT
Shop: {$result['shop']}
Source: {$result['source']}
Fetched: {$result['fetched']}
Inserted: {$result['inserted']}
Updated: {$result['updated']}
Unavailable: {$result['unavailable']}
Skipped: {$result['skipped']}
Errors: {$result['errors']}

TXT);

if ($dryRun) {
    foreach ($result['mapped'] as $i => $p) {
        $price = \Versandkostenretter\Money::formatEuro($p['price_cents']);
        $avail = $p['available'] ? 'yes' : 'NO';
        $cat = $p['category'] ?? '-';
        fwrite(STDOUT, sprintf(
            "  %d. %s | %s | %s | cat=%s | avail=%s\n      %s\n",
            $i + 1,
            $p['external_id'],
            $p['name'],
            $price,
            $cat,
            $avail,
            $p['url']
        ));
    }
}
foreach ($result['skipped_items'] as $s) {
    fwrite(STDERR, "  skipped: [{$s['id']}] {$s['title']}: {$s['reason']}\n");
}
if (!$dryRun && $result['unavailable'] > 0) {
    fwrite(STDOUT, "  note: {$result['unavailable']} product(s) not present in the current complete feed were marked unavailable.\n");
}

exit(EXIT_OK);
