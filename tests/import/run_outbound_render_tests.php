<?php

declare(strict_types=1);

/**
 * Outbound-link rendering regression test (results template).
 *
 * Usage: php tests/import/run_outbound_render_tests.php
 * or:    VSKR_TEST_PHP=/path/to/php php tests/import/run_outbound_render_tests.php
 *
 * Regression for the production bug: the results loop built $link but the
 * markup checked $outboundUrl (never assigned), so valid products rendered
 * WITHOUT links/buttons. This test executes the REAL templates/results.php
 * over HTTP and proves:
 *  - valid imported Lootforge products render /go/<external_id> links
 *    (product name AND "Zum Shop" button)
 *  - products with unsafe merchant URLs render NO outbound link
 *  - /go/ itself still redirects via the OutboundLink pipeline
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

// ---------------------------------------------------------------- layout
$root = sys_get_temp_dir() . '/vskr-outb-' . bin2hex(random_bytes(4));
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
    "<?php return ['db'=>['host'=>'u','port'=>0,'name'=>'u','user'=>'u','pass'=>'SECRET-no-output','dsn'=>" . var_export($dsn, true) . "],'app'=>['debug'=>false,'max_results'=>24]];");

// ---------------------------------------------------------------- DB
// Real imported Lootforge catalogue shape (affiliate disabled, images off):
// three safe products + one with an unsafe merchant URL.
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
$pdo->exec("INSERT INTO VSKR_shops (id,name,slug,website_url,shipping_cost,free_shipping_threshold,active,
    affiliate_enabled,source_type,source_url,source_scope)
    VALUES (1,'Lootforge','lootforge','https://lootforge.de',5.99,100.00,1,0,
    'shopify','https://lootforge.de/collections/zubehor-furs-malen/products.json?limit=250','zubehor-furs-malen')");
$st = $pdo->prepare('INSERT INTO VSKR_products (shop_id,external_id,name,url,price,available,category)
    VALUES (1,?,?,?,?,1,?)');
// Threshold 100, cart 95,34 -> missing 4,66: all four qualify.
$safe = [
    ['10293528625481', 'RedgrasGames - Hydration Paper Painter 50 sheets', 'https://lootforge.de/products/redgrasgames-hydration-paper-painter-50-sheets', 7.20, 'Wet Palette'],
    ['10293528494409', 'RedgrasGames - Painter Lite 50sheets', 'https://lootforge.de/products/redgrasgames-new-painter-lite-50sheets-2foams', 24.00, 'Wet Palette'],
    ['10293485502793', 'Wet Palette', 'https://lootforge.de/products/wet-palette', 26.99, 'Wet Palette'],
];
foreach ($safe as $p) {
    $st->execute([$p[0], $p[1], $p[2], $p[3], $p[4]]);
}
$st->execute(['10293485535561', 'Unsafe URL Product', 'javascript:alert(1)', 27.50, 'Wet Palette']);

// ---------------------------------------------------------------- HTTP helper
function vskr_http(string $path): array
{
    $fp = stream_socket_client('tcp://localhost:8091', $en, $es, 5);
    if ($fp === false) { fwrite(STDERR, "connect failed\n"); exit(2); }
    fwrite($fp, "GET {$path} HTTP/1.1\r\nHost: localhost\r\nConnection: close\r\n\r\n");
    $raw = '';
    while (!feof($fp)) $raw .= fread($fp, 32768);
    fclose($fp);
    preg_match('#^HTTP/1\.[01] (\d{3})#', $raw, $m);
    return ['status' => (int)($m[1] ?? 0), 'body' => (explode("\r\n\r\n", $raw, 2)[1] ?? '')];
}

// ---------------------------------------------------------------- server
$serverCmd = escapeshellarg($php)
    . ' -d extension_dir=' . escapeshellarg(ini_get('extension_dir') ?: '')
    . (extension_loaded('pdo_sqlite') ? ' -d extension=pdo_sqlite' : '')
    . ' -S localhost:8091 ' . escapeshellarg($httpdocs . '/router.php');
$proc = proc_open($serverCmd, [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
usleep(800000);
$stop = function () use ($proc): void {
    exec("pkill -f 'S localhost:8091' 2>/dev/null");
    usleep(200000);
    if (is_resource($proc)) { proc_terminate($proc); proc_close($proc); }
};

$r = vskr_http('/?shop=lootforge&cart=95,34');
$check('results page 200', $r['status'] === 200, "status={$r['status']}");

/* =============================================================
 * Valid imported products render /go/<external_id> links
 * ============================================================= */
foreach ($safe as $p) {
    $go = '/go/' . $p[0];
    $check("valid product renders /go link ({$p[0]})",
        str_contains($r['body'], 'href="' . $go . '"'));
}
// Name link AND Zum Shop button both present (count = 2 links per product).
$check('all three safe products have TWO /go/ links each (name + button)',
    substr_count($r['body'], '/go/10293528625481') === 2
    && substr_count($r['body'], '/go/10293528494409') === 2
    && substr_count($r['body'], '/go/10293485502793') === 2,
    'counts: ' . substr_count($r['body'], '/go/'));
$check('"Zum Shop" button rendered 3 times', substr_count($r['body'], 'Zum Shop') === 3,
    (string) substr_count($r['body'], 'Zum Shop'));

/* =============================================================
 * Unsafe merchant URL renders NO outbound link
 * ============================================================= */
$check('unsafe product renders NO /go/ link', !str_contains($r['body'], '/go/10293485535561'));
$check('unsafe product renders NO javascript: href', !str_contains($r['body'], 'javascript:'));
// Its name still shows (as plain text, no anchor):
$check('unsafe product name still visible', str_contains($r['body'], 'Unsafe URL Product'));
// And it has no Zum Shop button of its own (only the 3 safe ones).
$check('unsafe product adds no extra Zum Shop button', substr_count($r['body'], 'Zum Shop') === 3);

/* =============================================================
 * /go/ redirect itself still works through the OutboundLink pipeline
 * ============================================================= */
$fp = stream_socket_client('tcp://localhost:8091', $en, $es, 5);
fwrite($fp, "GET /go/10293528625481 HTTP/1.1\r\nHost: localhost\r\nConnection: close\r\n\r\n");
$raw = '';
while (!feof($fp)) $raw .= fread($fp, 8192);
fclose($fp);
preg_match('#^HTTP/1\.[01] (\d{3})#', $raw, $m);
preg_match('#^Location:\s*(\S+)#mi', $raw, $lm);
$check('/go/<id> redirects to canonical merchant URL',
    (int) ($m[1] ?? 0) === 302 && ($lm[1] ?? '') === 'https://lootforge.de/products/redgrasgames-hydration-paper-painter-50-sheets',
    'status=' . ($m[1] ?? '?') . ' loc=' . var_export($lm[1] ?? null, true));

$fp = stream_socket_client('tcp://localhost:8091', $en, $es, 5);
fwrite($fp, "GET /go/10293485535561 HTTP/1.1\r\nHost: localhost\r\nConnection: close\r\n\r\n");
$raw = '';
while (!feof($fp)) $raw .= fread($fp, 8192);
fclose($fp);
preg_match('#^HTTP/1\.[01] (\d{3})#', $raw, $m);
$check('/go/ for unsafe URL row -> 404 (fail closed)', (int) ($m[1] ?? 0) === 404, 'status=' . ($m[1] ?? '?'));

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

print("\nOutbound render tests: {$pass} passed, {$fail} failed\n");
exit($fail === 0 ? 0 : 1);
