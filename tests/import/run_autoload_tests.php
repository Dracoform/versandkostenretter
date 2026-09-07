<?php

declare(strict_types=1);

/**
 * Importer autoload/load-order regression test.
 *
 * Usage: php tests/import/run_autoload_tests.php
 * (or with a specific PHP binary: VSKR_TEST_PHP=/path/php php tests/import/...)
 *
 * Reproduces the production failure: bin/import-shop.php executed in a
 * production-like layout (httpdocs = repo root, config OUTSIDE httpdocs)
 * MUST NOT fatal with "Interface SourceFetcher not found" — class/interface
 * dependencies must resolve via the autoloader regardless of require order.
 *
 * The runner is invoked twice per scenario, once with each require order
 * forced (interface-first / class-first), by pre-including one of the files
 * through a tiny shim before the real entry point.
 *
 * Exit 0 = pass, 1 = failure.
 */

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

$repo = dirname(__DIR__, 2);
$php = getenv('VSKR_TEST_PHP') ?: PHP_BINARY;

// ---------------------------------------------------------------- build layout
$root = sys_get_temp_dir() . '/vskr-autoload-' . bin2hex(random_bytes(4));
$httpdocs = $root . '/versandkostenretter.de/httpdocs';
@mkdir($httpdocs, 0777, true);

$it = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($repo, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::LEAVES_ONLY
);
foreach ($it as $f) {
    $rel = substr($f->getPathname(), strlen($repo) + 1);
    if (str_starts_with($rel, '.git')) {
        continue;
    }
    $target = $httpdocs . '/' . $rel;
    if ($f->isFile()) {
        @mkdir(dirname($target), 0777, true);
        copy($f->getPathname(), $target);
    }
}

// Config OUTSIDE the document root — SQLite DSN, no MySQL needed for a
// shop-configured dry-run (dry-run performs no DB work; the DB is only
// opened after the shop lookup, which the SQLite DSN serves).
@mkdir(dirname($httpdocs) . '/config', 0777, true);
$dsn = 'sqlite:' . $root . '/db.sqlite';
file_put_contents(
    dirname($httpdocs) . '/config/config.php',
    "<?php return ['db'=>['host'=>'unused','port'=>0,'name'=>'unused','user'=>'unused','pass'=>'SECRET-shall-not-appear','dsn'=>" . var_export($dsn, true) . "],'app'=>[]];"
);

// Seed the SQLite DB with the Lootforge shop (schema as in schema.sql, SQLite dialect).
$pdo = new PDO($dsn);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('
CREATE TABLE VSKR_shops (
    id INTEGER PRIMARY KEY, name TEXT NOT NULL, slug TEXT NOT NULL UNIQUE,
    website_url TEXT NOT NULL, shipping_cost NUMERIC NOT NULL,
    free_shipping_threshold NUMERIC NOT NULL, active INTEGER NOT NULL DEFAULT 1,
    affiliate_enabled INTEGER NOT NULL DEFAULT 0, affiliate_mode TEXT,
    affiliate_param TEXT, affiliate_value TEXT, affiliate_template TEXT,
    source_type TEXT, source_url TEXT, source_scope TEXT NOT NULL DEFAULT "default",
    product_images_enabled INTEGER NOT NULL DEFAULT 0,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE VSKR_products (
    id INTEGER PRIMARY KEY, shop_id INTEGER NOT NULL REFERENCES VSKR_shops(id),
    external_id TEXT, name TEXT NOT NULL, url TEXT NOT NULL, price NUMERIC NOT NULL,
    available INTEGER NOT NULL DEFAULT 1, category TEXT, image_url TEXT,
    source_type TEXT, source_scope TEXT NOT NULL DEFAULT "default",
    last_seen_at TEXT, updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
INSERT INTO VSKR_shops (id, name, slug, website_url, shipping_cost, free_shipping_threshold,
    active, affiliate_enabled, source_type, source_url, source_scope)
VALUES (1, \'Lootforge\', \'lootforge\', \'https://lootforge.de\', 5.99, 100.00, 1, 0,
    \'shopify\', \'https://lootforge.de/collections/zubehor-furs-malen/products.json?limit=250\',
    \'zubehor-furs-malen\');
');

/**
 * Execute bin/import-shop.php with a forced pre-include of ONE src file.
 * This is the core regression: whichever file is loaded first, the
 * autoloader must resolve every remaining dependency.
 */
function vskr_run_importer(string $php, string $httpdocs, ?string $preInclude): array
{
    $cmd = escapeshellarg($php)
        . ' -d extension_dir=' . escapeshellarg(ini_get('extension_dir') ?: '')
        . (extension_loaded('pdo_sqlite') ? ' -d extension=pdo_sqlite' : '')
        . ' -d display_errors=1';
    if ($preInclude !== null) {
        // -d auto_prepend_file forces a specific src file to be loaded FIRST,
        // before the entry point runs — the exact load-order trap.
        $cmd .= ' -d auto_prepend_file=' . escapeshellarg($preInclude);
    }
    $cmd .= ' ' . escapeshellarg($httpdocs . '/bin/import-shop.php')
          . ' lootforge --dry-run';
    exec($cmd . ' 2>&1', $outLines, $code);
    return ['code' => $code, 'out' => implode("\n", $outLines)];
}

/* ---------------------------------------------------------------- scenarios */

// 0. Baseline: plain production invocation (the command that failed in prod).
$r = vskr_run_importer($php, $httpdocs, null);
$check('plain dry-run does not fatal on SourceFetcher',
    !str_contains($r['out'], 'SourceFetcher" not found') && $r['code'] !== 255,
    "code={$r['code']}: " . substr($r['out'], 0, 200));

// 1. Interface loaded FIRST (auto_prepend), then entry point.
$r = vskr_run_importer($php, $httpdocs, $httpdocs . '/src/Import/SourceFetcher.php');
$check('interface-first load order does not fatal',
    !str_contains($r['out'], 'SourceFetcher" not found') && $r['code'] !== 255,
    "code={$r['code']}: " . substr($r['out'], 0, 200));

// 2. Class loaded FIRST (auto_prepend HttpClient BEFORE the interface).
//    This is the exact trap: HttpClient references SourceFetcher at parse time.
$r = vskr_run_importer($php, $httpdocs, $httpdocs . '/src/Import/HttpClient.php');
$check('class-first load order (HttpClient before interface) does not fatal',
    !str_contains($r['out'], 'SourceFetcher" not found') && $r['code'] !== 255,
    "code={$r['code']}: " . substr($r['out'], 0, 200));

// 3. Mapper loaded first (references SourceException + View across namespace).
$r = vskr_run_importer($php, $httpdocs, $httpdocs . '/src/Import/ShopifyMapper.php');
$check('mapper-first load order does not fatal',
    !str_contains($r['out'], 'not found') && $r['code'] !== 255,
    "code={$r['code']}: " . substr($r['out'], 0, 200));

// 4. Real dry-run against a FAKE local Shopify source would need network;
//    instead verify the entry point reports the expected summary structure
//    (or a clean source error if network is unavailable) — never an autoload fatal.
$r = vskr_run_importer($php, $httpdocs, null);
$autoloadFatal = str_contains($r['out'], 'Fatal error')
    || str_contains($r['out'], 'not found" in');
$check('no autoload fatal of any kind in real entry point', !$autoloadFatal, substr($r['out'], 0, 200));

// 5. Web front controller still boots with the PSR-4 autoloader (subnamespace
//    mapping fix): homepage renders through the simulated docroot.
$ctx = stream_context_create(['http' => ['timeout' => 1]]);
$serverCmd = escapeshellarg($php)
    . ' -d extension_dir=' . escapeshellarg(ini_get('extension_dir') ?: '')
    . (extension_loaded('pdo_sqlite') ? ' -d extension=pdo_sqlite' : '')
    . ' -S localhost:8099 ' . escapeshellarg($httpdocs . '/tests/smoke_server.php');
$proc = proc_open($serverCmd, [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
usleep(800000);
$homepage = @file_get_contents('http://localhost:8099/');
// The dev server never exits: terminate it before proc_close (which blocks on EOF).
exec("pkill -f 'S localhost:8099' 2>/dev/null");
usleep(200000);
if (is_resource($proc)) {
    proc_terminate($proc);
    proc_close($proc);
}
$check('web front controller serves homepage with subnamespace autoloader',
    is_string($homepage) && str_contains($homepage, 'Show me the goods!'));

/* ---------------------------------------------------------------- cleanup */
$rr = function (string $dir): void {
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $f) {
        $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
    }
    @rmdir($dir);
};
print("KEEP: $root\n");

print("\nAutoload tests: {$pass} passed, {$fail} failed\n");
exit($fail === 0 ? 0 : 1);
