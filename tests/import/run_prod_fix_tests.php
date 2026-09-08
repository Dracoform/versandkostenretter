<?php

declare(strict_types=1);

/**
 * Production-fix regression tests: new-tab links + counter root-cause guards.
 *
 * Usage: php tests/import/run_prod_fix_tests.php
 * or:    VSKR_TEST_PHP=/path/to/php php tests/import/run_prod_fix_tests.php
 *
 * Covers:
 *  A) target="_blank" + rel="nofollow noopener noreferrer" on both outbound
 *     links (name + Zum Shop), href still /go/<id>, unsafe URLs still linkless
 *  B) counter semantics over the REAL entry point: /go/ increments, exact
 *     stat-key consistency between migration SQL, increment and read,
 *     singular/plural homepage rendering, no Set-Cookie, increment failure
 *     does not block the merchant redirect
 *  C) MySQL-compatibility check of the exact upsert SQL used in production
 *     (static analysis of the prepared statement string in StatsRepository)
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

/* =============================================================
 * C) Static MySQL/SQLite compatibility + key consistency
 *    (runs without any DB — validates the exact strings shipped)
 * ============================================================= */
require $repo . '/src/StatsRepository.php';

$statsSrc = (string) file_get_contents($repo . '/src/StatsRepository.php');
$mig5 = (string) file_get_contents($repo . '/database/migrations/0005_stats_counter.sql');
$schema = (string) file_get_contents($repo . '/database/schema.sql');

// Key consistency: migration, schema comment/seed and repository constant.
preg_match("/public const OUTBOUND_PRODUCT_CLICKS = '([^']+)'/", $statsSrc, $constM);
$key = $constM[1] ?? '';
$check('repository key constant exists', $key !== '', $key);
$check('migration 0005 uses the SAME stat_key as the repository', str_contains($mig5, "'{$key}'"), $key);
$check('schema.sql references the same stat_key (seed)', str_contains($schema, "'{$key}'"));

// The increment SQL must be a single atomic upsert (no SELECT+UPDATE race).
$check('increment uses ON DUPLICATE KEY UPDATE (MySQL atomic upsert)',
    str_contains($statsSrc, 'ON DUPLICATE KEY UPDATE stat_value = stat_value + 1'));
$check('increment has SQLite ON CONFLICT fallback for tests',
    str_contains($statsSrc, 'ON CONFLICT(stat_key) DO UPDATE SET stat_value = stat_value + 1'));
$check('read queries the same stat_key column',
    str_contains($statsSrc, "SELECT stat_value FROM VSKR_stats WHERE stat_key = :key"));

// MySQL compatibility of the upsert: 'ON DUPLICATE KEY UPDATE' requires a
// UNIQUE/PK on stat_key — migration + schema must define it.
$check('migration 0005 defines PRIMARY KEY (stat_key)', (bool) preg_match('/PRIMARY KEY\s*\(\s*stat_key\s*\)/i', $mig5));
$check('schema.sql defines PRIMARY KEY (stat_key)', (bool) preg_match('/PRIMARY KEY\s*\(\s*stat_key\s*\)/i', $schema));
// BIGINT UNSIGNED as in production:
$check('migration uses BIGINT UNSIGNED for stat_value', (bool) preg_match('/stat_value\s+BIGINT UNSIGNED/i', $mig5));

// No SELECT-then-UPDATE race pattern in the increment method.
$incStart = (int) strpos($statsSrc, 'public function increment');
$incBody = substr($statsSrc, $incStart, (int) strpos('x' . $statsSrc, 'public function get', $incStart) - $incStart);
$upsertPos = strpos($incBody, 'INSERT INTO VSKR_stats');
$selectPos = strpos($incBody, 'SELECT stat_value');
$check('increment performs the atomic upsert BEFORE any SELECT (no race)',
    $upsertPos !== false && ($selectPos === false || $upsertPos < $selectPos));

/* =============================================================
 * A/B) HTTP end-to-end over the REAL entry point
 * ============================================================= */
$root = sys_get_temp_dir() . '/vskr-prodfix-' . bin2hex(random_bytes(4));
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
CREATE TABLE VSKR_stats (
    stat_key TEXT PRIMARY KEY,
    stat_value INTEGER NOT NULL DEFAULT 0
);
');
$pdo->exec("INSERT INTO VSKR_shops (id,name,slug,website_url,shipping_cost,free_shipping_threshold,active,affiliate_enabled)
    VALUES (1,'Lootforge','lootforge','https://lootforge.de',5.99,100.00,1,0)");
$st = $pdo->prepare('INSERT INTO VSKR_products (shop_id,external_id,name,url,price,available) VALUES (1,?,?,?,?,1)');
$st->execute(['111', 'Safe Product', 'https://lootforge.de/products/safe', 9.99]);
$st->execute(['222', 'Unsafe Product', 'javascript:alert(1)', 12.00]);
$pdo->exec("INSERT INTO VSKR_stats (stat_key, stat_value) VALUES ('outbound_product_clicks', 0)");

function vskr_http_pf(string $path): array
{
    $fp = stream_socket_client('tcp://localhost:8086', $en, $es, 5);
    if ($fp === false) { fwrite(STDERR, "connect failed\n"); exit(2); }
    fwrite($fp, "GET {$path} HTTP/1.1\r\nHost: localhost\r\nConnection: close\r\n\r\n");
    $raw = '';
    while (!feof($fp)) $raw .= fread($fp, 32768);
    fclose($fp);
    preg_match('#^HTTP/1\.[01] (\d{3})#', $raw, $m);
    preg_match('#^Location:\s*([^\r\n]+)#mi', $raw, $lm);
    [$headers, $body] = explode("\r\n\r\n", $raw, 2) + [1 => '', 2 => ''];
    return [
        'status' => (int)($m[1] ?? 0),
        'location' => $lm[1] ?? null,
        'body' => $body,
        'set_cookie' => stripos($headers, 'set-cookie:') !== false,
    ];
}
function vskr_pf_counter(PDO $pdo): int
{
    return (int) $pdo->query("SELECT stat_value FROM VSKR_stats WHERE stat_key = 'outbound_product_clicks'")->fetchColumn();
}

$serverCmd = escapeshellarg($php)
    . ' -d extension_dir=' . escapeshellarg(ini_get('extension_dir') ?: '')
    . (extension_loaded('pdo_sqlite') ? ' -d extension=pdo_sqlite' : '')
    . ' -S localhost:8086 ' . escapeshellarg($httpdocs . '/router.php');
$proc = proc_open($serverCmd, [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
usleep(800000);
$stop = function () use ($proc): void {
    exec("pkill -f 'S localhost:8086' 2>/dev/null");
    usleep(200000);
    if (is_resource($proc)) { proc_terminate($proc); proc_close($proc); }
};

/* ---------------------------------------------------------------
 * A) Link markup
 * --------------------------------------------------------------- */
$r = vskr_http_pf('/?shop=lootforge&cart=95,34');
$check('results 200', $r['status'] === 200);
$check('product-name link has target="_blank"',
    (bool) preg_match('#<a href="/go/111" target="_blank" rel="nofollow noopener noreferrer">#', $r['body']));
$check('"Zum Shop" button has target="_blank"',
    (bool) preg_match('#<a class="btn-secondary" href="/go/111" target="_blank" rel="nofollow noopener noreferrer">#', $r['body']));
$check('both links keep rel noopener+noreferrer',
    substr_count($r['body'], 'rel="nofollow noopener noreferrer"') >= 2);
$check('hrefs still point to /go/<external_id>', str_contains($r['body'], 'href="/go/111"'));
$check('unsafe product still renders no /go/ link', !str_contains($r['body'], '/go/222'));

/* ---------------------------------------------------------------
 * B) Counter semantics through the real entry point
 * --------------------------------------------------------------- */
$check('counter starts at 0', vskr_pf_counter($pdo) === 0);
$r = vskr_http_pf('/go/111');
$check('first /go/ -> 302 + counter 1', $r['status'] === 302 && vskr_pf_counter($pdo) === 1);
$check('first /go/ redirects to canonical merchant URL',
    $r['location'] === 'https://lootforge.de/products/safe', (string) $r['location']);
$r = vskr_http_pf('/go/111');
$check('second /go/ -> counter 2', vskr_pf_counter($pdo) === 2);

// Local image assets (homepage hero) use the same mtime cache busting.
$homeBody = vskr_http_pf('/');
$check('homepage hero image URL is versioned (?v=<mtime>)',
    (bool) preg_match('#/assets/images/hero-raccoon\.webp\?v=\d+#', $homeBody['body']));
// Replacing the image file under the SAME filename must change its URL.
$heroPath = $httpdocs . '/assets/images/hero-raccoon.webp';
preg_match('#/assets/images/hero-raccoon\.webp\?v=(\d+)#', $homeBody['body'], $hv1);
sleep(1);
touch($heroPath, filemtime($heroPath) + 10);
clearstatcache(true, $heroPath);
$r2 = vskr_http_pf('/');
preg_match('#/assets/images/hero-raccoon\.webp\?v=(\d+)#', $r2['body'], $hv2);
$check('replaced image (same filename) gets a new versioned URL',
    ($hv1[1] ?? '') !== '' && ($hv2[1] ?? '') !== '' && $hv1[1] !== $hv2[1],
    ($hv1[1] ?? '?') . ' -> ' . ($hv2[1] ?? '?'));
$check('merchant/external image URLs are NOT cache-busted',
    !preg_match('#cdn\.[^"]*\?v=#', $r2['body']));

// Homepage renders singular at 1 / plural at 2 via stored values.
$pdo->exec("UPDATE VSKR_stats SET stat_value = 1 WHERE stat_key = 'outbound_product_clicks'");
$r = vskr_http_pf('/');
$check('homepage singular at 1: "1 Rettungsversuch gestartet!"',
    str_contains($r['body'], 'Schon <strong>1</strong>')
    && str_contains($r['body'], 'Rettungsversuch gestartet!'));
$pdo->exec("UPDATE VSKR_stats SET stat_value = 2 WHERE stat_key = 'outbound_product_clicks'");
$r = vskr_http_pf('/');
$check('homepage plural at 2: "2 Rettungsversuche gestartet!"',
    str_contains($r['body'], 'Schon <strong>2</strong>')
    && str_contains($r['body'], 'Rettungsversuche gestartet!'));
$check('homepage sets no Set-Cookie', !$r['set_cookie']);
$check('/go/ sets no Set-Cookie', !vskr_http_pf('/go/111')['set_cookie']);

// Increment failure must NOT block the redirect: point the stats table away
// (drop it) — the upsert will fail, /go/ must still 302 to the merchant.
$pdo->exec('DROP TABLE VSKR_stats');
$r = vskr_http_pf('/go/111');
$check('counter failure (missing table) still redirects to merchant',
    $r['status'] === 302 && $r['location'] === 'https://lootforge.de/products/safe',
    "status={$r['status']}");
// Recreate for cleanliness.
$pdo->exec('CREATE TABLE VSKR_stats (stat_key TEXT PRIMARY KEY, stat_value INTEGER NOT NULL DEFAULT 0)');

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

print("\nProd-fix tests: {$pass} passed, {$fail} failed\n");
exit($fail === 0 ? 0 : 1);
