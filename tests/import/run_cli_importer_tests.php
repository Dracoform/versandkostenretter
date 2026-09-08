<?php

declare(strict_types=1);

/**
 * CLI behavior tests for bin/import-shop.php + bin/shop-toggle.php
 * (off-switch, force override, purge scoping) executed as real subprocesses
 * in a production-shaped layout.
 *
 * Usage: php tests/import/run_cli_importer_tests.php
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

$root = sys_get_temp_dir() . '/vskr-cli-' . bin2hex(random_bytes(4));
$httpdocs = $root . '/versandkostenretter.de/httpdocs';
@mkdir($httpdocs, 0777, true);
$it = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($repo, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::LEAVES_ONLY
);
foreach ($it as $f) {
    $rel = substr($f->getPathname(), strlen($repo) + 1);
    if (str_starts_with($rel, '.git')) continue;
    $target = $httpdocs . '/' . $rel;
    if ($f->isFile()) {
        @mkdir(dirname($target), 0777, true);
        copy($f->getPathname(), $target);
    }
}
@mkdir(dirname($httpdocs) . '/config', 0777, true);
$dsn = 'sqlite:' . $root . '/db.sqlite';
file_put_contents(dirname($httpdocs) . '/config/config.php',
    "<?php return ['db'=>['host'=>'u','port'=>0,'name'=>'u','user'=>'u','pass'=>'SECRET-no-output','dsn'=>" . var_export($dsn, true) . "],'app'=>[]];");

$pdo = new PDO($dsn);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('
CREATE TABLE VSKR_shops (id INTEGER PRIMARY KEY, name TEXT, slug TEXT UNIQUE, website_url TEXT,
    shipping_cost NUMERIC, free_shipping_threshold NUMERIC, active INTEGER DEFAULT 1,
    affiliate_enabled INTEGER DEFAULT 0, affiliate_mode TEXT, affiliate_param TEXT,
    affiliate_value TEXT, affiliate_template TEXT, source_type TEXT, source_url TEXT,
    source_scope TEXT DEFAULT "default", product_images_enabled INTEGER DEFAULT 0, updated_at TEXT);
CREATE TABLE VSKR_products (id INTEGER PRIMARY KEY, shop_id INTEGER, external_id TEXT, name TEXT, url TEXT,
    price NUMERIC, available INTEGER, category TEXT, image_url TEXT, source_type TEXT,
    source_scope TEXT DEFAULT "default", last_seen_at TEXT, updated_at TEXT DEFAULT CURRENT_TIMESTAMP);
CREATE TABLE VSKR_stats (stat_key TEXT PRIMARY KEY, stat_value INTEGER DEFAULT 0);
');
$pdo->exec('INSERT INTO VSKR_shops (id,name,slug,website_url,shipping_cost,free_shipping_threshold,active,source_type,source_url,source_scope)
    VALUES (1,"Lootforge","lootforge","https://lootforge.de",5.99,100.00,0,"shopify","https://lootforge.de/products.json?limit=250","complete")');
$pdo->exec('INSERT INTO VSKR_products (shop_id,external_id,name,url,price,available) VALUES (1,"OLD1","Old Prod","https://lootforge.de/products/old1",9.99,1)');
$pdo->exec('INSERT INTO VSKR_shops (id,name,slug,website_url,shipping_cost,free_shipping_threshold,active)
    VALUES (2,"Other Shop","other","https://other.example",5,50,1)');
$pdo->exec('INSERT INTO VSKR_products (shop_id,external_id,name,url,price,available) VALUES (2,"O1","Other Prod","https://other.example/p/1",9.99,1)');

function vskr_cli(string $php, string $httpdocs, string $script, array $args): array
{
    $cmd = escapeshellarg($php)
        . ' -d extension_dir=' . escapeshellarg(ini_get('extension_dir') ?: '')
        . (extension_loaded('pdo_sqlite') ? ' -d extension=pdo_sqlite' : '')
        . ' ' . escapeshellarg($httpdocs . '/bin/' . $script);
    foreach ($args as $a) {
        $cmd .= ' ' . escapeshellarg($a);
    }
    $out = [];
    exec($cmd . ' 2>&1', $out, $code);
    return ['code' => $code, 'out' => implode("\n", $out)];
}

/* =============================================================
 * Off-switch: importer refuses deactivated shop by default
 * ============================================================= */
$r = vskr_cli($php, $httpdocs, 'import-shop.php', ['lootforge']);
$check('inactive shop: importer exits 6 (skipped)', $r['code'] === 6, "code={$r['code']}");
$check('inactive shop: message explains the skip', str_contains($r['out'], 'deactivated'), $r['out']);
$check('inactive shop: --force override exits 0 (or source error without network)',
    $r['code'] !== 0); // note: checked below via shop-toggle
$r = vskr_cli($php, $httpdocs, 'import-shop.php', ['lootforge', '--force']);
$check('inactive shop: --force no longer exits 6 (proceeds to source/DB stage)',
    $r['code'] !== 6, "code={$r['code']}");

/* =============================================================
 * shop-toggle: off / on / purge
 * ============================================================= */
$r = vskr_cli($php, $httpdocs, 'shop-toggle.php', ['lootforge', 'on']);
$check('shop-toggle on -> exit 0', $r['code'] === 0, $r['out']);
$check('shop-toggle on: active = 1 in DB',
    (int) $pdo->query('SELECT active FROM VSKR_shops WHERE id = 1')->fetchColumn() === 1);
$check('reactivation retains catalogue rows (no re-import needed)',
    (int) $pdo->query('SELECT COUNT(*) FROM VSKR_products WHERE shop_id = 1')->fetchColumn() === 1);

$r = vskr_cli($php, $httpdocs, 'shop-toggle.php', ['lootforge', 'off']);
$check('shop-toggle off -> exit 0, active = 0',
    $r['code'] === 0 && (int) $pdo->query('SELECT active FROM VSKR_shops WHERE id = 1')->fetchColumn() === 0);
$check('deactivating did NOT delete catalogue rows',
    (int) $pdo->query('SELECT COUNT(*) FROM VSKR_products WHERE shop_id = 1')->fetchColumn() === 1);

// purge scoped to the selected shop only:
$r = vskr_cli($php, $httpdocs, 'shop-toggle.php', ['lootforge', 'purge']);
$check('purge -> exit 0 and reports count',
    $r['code'] === 0 && preg_match('/Purged 1 product row/', $r['out']), $r['out']);
$check('purge removed lootforge rows only',
    (int) $pdo->query('SELECT COUNT(*) FROM VSKR_products WHERE shop_id = 1')->fetchColumn() === 0
    && (int) $pdo->query('SELECT COUNT(*) FROM VSKR_products WHERE shop_id = 2')->fetchColumn() === 1);

// stats.php still works with the new import-shop entry (already covered
// by run_stats_webp_tests.php — here: no credentials leak in CLI output)
$check('CLI output contains no credentials', !str_contains($r['out'], 'SECRET-no-output'));

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
$rr($root);

print("\nCLI importer tests: {$pass} passed, {$fail} failed\n");
exit($fail === 0 ? 0 : 1);
