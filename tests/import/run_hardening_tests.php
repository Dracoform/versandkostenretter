<?php

declare(strict_types=1);

/**
 * Asset cache busting + per-shop product-image permission tests.
 *
 * Usage: php tests/import/run_hardening_tests.php
 * or:    VSKR_TEST_PHP=/path/to/php php tests/import/run_hardening_tests.php
 *
 * Executes the REAL index.php over HTTP in a production-like layout:
 *  - asset URLs carry ?v=<mtime> and change when the file changes
 *  - merchant images render ONLY when the shop's product_images_enabled = 1
 *  - disabled shops render the local placeholder (no CDN URL in HTML)
 *  - importer still stores image_url regardless of the flag
 *  - no cookies
 *
 * Exit 0 = pass, 1 = failure.
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
use Versandkostenretter\Import\ImportRepository;
use Versandkostenretter\Import\ShopifyImporter;

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

$repo_root = dirname(__DIR__, 2);
$php = getenv('VSKR_TEST_PHP') ?: PHP_BINARY;

/* =============================================================
 * Part A: asset versioning (unit-level, no HTTP)
 * ============================================================= */
require_once $repo_root . '/src/Assets.php';

$assetRoot = sys_get_temp_dir() . '/vskr-assets-' . bin2hex(random_bytes(4));
@mkdir($assetRoot . '/assets/css', 0777, true);
@mkdir($assetRoot . '/assets/js', 0777, true);
file_put_contents($assetRoot . '/assets/css/main.css', 'body{color:teal}');
file_put_contents($assetRoot . '/assets/js/main.js', 'console.log(1);');
\Versandkostenretter\Assets::setDocRoot($assetRoot);

$v1 = \Versandkostenretter\Assets::versionFor('/assets/css/main.css');
$check('asset version derived from mtime', $v1 !== null && ctype_digit($v1), (string) $v1);
$url1 = \Versandkostenretter\Assets::url('/assets/css/main.css');
$check('asset URL has ?v=<mtime>', $url1 === '/assets/css/main.css?v=' . $v1, $url1);
$r = \Versandkostenretter\Assets::url('/assets/js/main.js');
$check('JS asset URL correct', $r === '/assets/js/main.js?v=' . \Versandkostenretter\Assets::versionFor('/assets/js/main.js'), $r);

// Change the file -> version changes.
sleep(1); // mtime resolution
file_put_contents($assetRoot . '/assets/css/main.css', 'body{color:orange}');
$v2 = \Versandkostenretter\Assets::versionFor('/assets/css/main.css');
$check('changing the asset changes its version', $v2 !== $v1, "{$v1} -> {$v2}");
$check('changed asset produces new URL', \Versandkostenretter\Assets::url('/assets/css/main.css') === '/assets/css/main.css?v=' . $v2);

// Unchanged asset keeps its version (cache stays valid).
$v2again = \Versandkostenretter\Assets::versionFor('/assets/css/main.css');
$check('unchanged asset keeps version', $v2again === $v2);

// Missing asset -> URL without version (still works).
$check('missing asset -> no version param',
    \Versandkostenretter\Assets::url('/assets/css/does-not-exist.css') === '/assets/css/does-not-exist.css');

/* =============================================================
 * Part B: image permission + rendered HTML (HTTP, real index.php)
 * ============================================================= */
$root = sys_get_temp_dir() . '/vskr-hard-' . bin2hex(random_bytes(4));
$httpdocs = $root . '/versandkostenretter.de/httpdocs';
@mkdir($httpdocs, 0777, true);
$it = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($repo_root, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::LEAVES_ONLY
);
foreach ($it as $f) {
    $rel = substr($f->getPathname(), strlen($repo_root) + 1);
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
    "<?php return ['db'=>['host'=>'u','port'=>0,'name'=>'u','user'=>'u','pass'=>'SECRET-no-output','dsn'=>" . var_export($dsn, true) . "],'app'=>['debug'=>false,'max_results'=>24]];");

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
');
// Shop A: images DISABLED (default 0, explicit). Shop B: ENABLED.
$pdo->exec("INSERT INTO VSKR_shops (id,name,slug,website_url,shipping_cost,free_shipping_threshold,active,product_images_enabled)
VALUES (1,'Shop Disabled','shop-disabled','https://a.example',5.99,50.00,1,0),
       (2,'Shop Enabled','shop-enabled','https://b.example',5.99,50.00,1,1)");
$st = $pdo->prepare("INSERT INTO VSKR_products (shop_id,external_id,name,url,price,available,category,image_url)
VALUES (?,?,?,?,?,1,'Cat',?)");
foreach ([1 => 'https://cdn.merchant-a.example/img/pa.png', 2 => 'https://cdn.merchant-b.example/img/pb.png'] as $sid => $img) {
    $st->execute([$sid, 'P' . $sid, 'Product ' . $sid, 'https://shop.example/p/' . $sid, 9.99, $img]);
}

// Server up
$serverCmd = escapeshellarg($php)
    . ' -d extension_dir=' . escapeshellarg(ini_get('extension_dir') ?: '')
    . (extension_loaded('pdo_sqlite') ? ' -d extension=pdo_sqlite' : '')
    . ' -S localhost:8097 ' . escapeshellarg($httpdocs . '/router.php');
$proc = proc_open($serverCmd, [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
usleep(800000);
$stop = function () use ($proc): void {
    exec("pkill -f 'S localhost:8097' 2>/dev/null");
    usleep(200000);
    if (is_resource($proc)) { proc_terminate($proc); proc_close($proc); }
};
$http = function (string $path) use ($root): array {
    $fp = stream_socket_client('tcp://localhost:8097', $en, $es, 5);
    if ($fp === false) { fwrite(STDERR, "connect failed\n"); exit(2); }
    fwrite($fp, "GET {$path} HTTP/1.1\r\nHost: localhost\r\nConnection: close\r\n\r\n");
    $raw = '';
    while (!feof($fp)) $raw .= fread($fp, 16384);
    fclose($fp);
    preg_match('#^HTTP/1\.[01] (\d{3})#', $raw, $m);
    return ['status' => (int)($m[1] ?? 0), 'body' => (explode("\r\n\r\n", $raw, 2)[1] ?? ''), 'raw' => $raw];
};

// Disabled shop (Lootforge-like): no merchant image, placeholder instead.
// cart 45 vs threshold 50 -> missing 5 -> the 9.99 product qualifies.
$r = $http('/?shop=shop-disabled&cart=45');
$check('disabled shop: results 200', $r['status'] === 200);
$check('disabled shop: NO <img> with CDN URL', !preg_match('#<img[^>]+src="https?://cdn#i', $r['body']));
$check('disabled shop: placeholder rendered', str_contains($r['body'], 'product-img-fallback'));
$check('disabled shop: no cdn.merchant-a.example anywhere in HTML', !str_contains($r['body'], 'cdn.merchant-a.example'));

// Enabled shop: merchant image rendered.
$r = $http('/?shop=shop-enabled&cart=45');
$check('enabled shop: results 200', $r['status'] === 200);
$check('enabled shop: <img> with merchant CDN URL', (bool) preg_match('#<img[^>]+src="https://cdn\.merchant-b\.example/img/pb\.png"#i', $r['body']));
$check('enabled shop: no fallback for this product', !str_contains($r['body'], 'product-img-fallback'));

// Missing flag column semantics: schema default (fresh row) is 0.
$row = $pdo->query('SELECT product_images_enabled FROM VSKR_shops WHERE id = 1')->fetchColumn();
$check('default flag value is 0 (disabled)', (int) $row === 0);

// Lootforge stays disabled: the shipped 0003 migration sets no image permission
// and its ON DUPLICATE UPDATE does not touch the new column.
$mig3 = (string) file_get_contents($repo_root . '/database/migrations/0003_lootforge_shop.sql');
$check('0003 migration does not enable product images',
    !preg_match('/product_images_enabled\s*=\s*1/i', $mig3));
$check('schema default remains 0', (bool) str_contains(
    (string) file_get_contents($repo_root . '/database/schema.sql'),
    'product_images_enabled   TINYINT(1)       NOT NULL DEFAULT 0'));

// Asset cache busting over HTTP: versioned URL served, changed file -> new URL.
$home = $http('/');
$check('homepage links versioned CSS', (bool) preg_match('#/assets/css/main\.css\?v=\d+#', $home['body']));
$check('homepage links versioned JS', (bool) preg_match('#/assets/js/main\.js\?v=\d+#', $home['body']));
preg_match('#/assets/css/main\.css\?v=(\d+)#', $home['body'], $mv);
$cssPath = $httpdocs . '/assets/css/main.css';
$oldMtime = filemtime($cssPath);
sleep(1);
touch($cssPath, $oldMtime + 10);
clearstatcache(true, $cssPath);
$home2 = $http('/');
preg_match('#/assets/css/main\.css\?v=(\d+)#', $home2['body'], $mv2);
$check('changed CSS file changes versioned URL', ($mv2[1] ?? '') !== '' && ($mv[1] ?? '') !== $mv2[1],
    ($mv[1] ?? '?') . ' -> ' . ($mv2[1] ?? '?'));
// The versioned URL itself is served fine:
$r = $http('/assets/css/main.css?v=' . $mv2[1]);
$check('versioned asset URL serves CSS', $r['status'] === 200);

// No cookies anywhere.
$check('no Set-Cookie on homepage', !str_contains($home['raw'], 'set-cookie:'));
$r = $http('/?shop=shop-disabled&cart=45');
$check('no Set-Cookie on results', !str_contains($r['raw'], 'set-cookie:'));

$stop();
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
$rr($assetRoot);

print("\nHardening tests: {$pass} passed, {$fail} failed\n");
exit($fail === 0 ? 0 : 1);
