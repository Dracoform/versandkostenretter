<?php

declare(strict_types=1);

/**
 * Rescue-counter (/go/) regression tests.
 *
 * Usage: php tests/import/run_counter_tests.php
 * or:    VSKR_TEST_PHP=/path/to/php php tests/import/run_counter_tests.php
 *
 * Executes the REAL index.php over HTTP in a production-like layout
 * (httpdocs = repo root, config OUTSIDE httpdocs, SQLite) and verifies the
 * privacy-preserving aggregate counter semantics end to end.
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

$repo_root = dirname(__DIR__, 2);
$php = getenv('VSKR_TEST_PHP') ?: PHP_BINARY;

// ---------------------------------------------------------------- layout
$root = sys_get_temp_dir() . '/vskr-counter-' . bin2hex(random_bytes(4));
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

// ---------------------------------------------------------------- DB
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
// Shop 1: affiliate DISABLED. Shop 2: affiliate ENABLED (query mode).
$pdo->exec("INSERT INTO VSKR_shops (id,name,slug,website_url,shipping_cost,free_shipping_threshold,active,
    affiliate_enabled,affiliate_mode,affiliate_param,affiliate_value,source_scope)
    VALUES (1,'Shop NoAff','shop-noaff','https://shopa.example',5.99,50.00,1,0,NULL,NULL,NULL,'default'),
           (2,'Shop Aff','shop-aff','https://shopb.example',5.99,50.00,1,1,'query','ref','partner','default')");
$st = $pdo->prepare('INSERT INTO VSKR_products (shop_id,external_id,name,url,price,available) VALUES (?,?,?,?,?,1)');
$st->execute([1, '111111', 'NoAff Product', 'https://shopa.example/product/111111', 9.99]);
$st->execute([2, '222222', 'Aff Product', 'https://shopb.example/product/222222', 9.99]);
$st->execute([1, '333333', 'Unsafe Product', 'javascript:alert(1)', 9.99]); // unsafe URL row
$pdo->exec("INSERT INTO VSKR_stats (stat_key, stat_value) VALUES ('outbound_product_clicks', 0)");

// ---------------------------------------------------------------- HTTP helper
function vskr_go(string $path, string $method = 'GET', array $post = []): array
{
    static $base = 'http://localhost:8093';
    $parts = parse_url($base . $path);
    $path = ($parts['path'] ?? '/') . (isset($parts['query']) ? '?' . $parts['query'] : '');
    $fp = stream_socket_client('tcp://localhost:8093', $en, $es, 5);
    if ($fp === false) { fwrite(STDERR, "connect failed\n"); exit(2); }
    $req = "{$method} {$path} HTTP/1.1\r\nHost: localhost\r\nConnection: close\r\n";
    if ($post !== []) {
        $body = http_build_query($post);
        $req .= "Content-Type: application/x-www-form-urlencoded\r\nContent-Length: " . strlen($body) . "\r\n\r\n" . $body;
    } else {
        $req .= "\r\n";
    }
    fwrite($fp, $req);
    $raw = '';
    while (!feof($fp)) $raw .= fread($fp, 16384);
    fclose($fp);
    preg_match('#^HTTP/1\.[01] (\d{3})#', $raw, $m);
    preg_match('#^Location:\s*(\S+)#mi', $raw, $lm);
    [$headers, $body] = explode("\r\n\r\n", $raw, 2) + [1 => '', 2 => ''];
    return [
        'status' => (int)($m[1] ?? 0),
        'location' => $lm[1] ?? null,
        'body' => $body,
        'set_cookie' => stripos($headers, 'set-cookie:') !== false,
        'headers' => $headers,
    ];
}
function go_call(string $path): void { vskr_go($path); }
function vskr_counter(PDO $pdo): int
{
    return (int) $pdo->query("SELECT stat_value FROM VSKR_stats WHERE stat_key = 'outbound_product_clicks'")->fetchColumn();
}
function vskr_stat_rows(PDO $pdo): int
{
    return (int) $pdo->query('SELECT COUNT(*) FROM VSKR_stats')->fetchColumn();
}

// ---------------------------------------------------------------- server up
$serverCmd = escapeshellarg($php)
    . ' -d extension_dir=' . escapeshellarg(ini_get('extension_dir') ?: '')
    . (extension_loaded('pdo_sqlite') ? ' -d extension=pdo_sqlite' : '')
    . ' -S localhost:8093 ' . escapeshellarg($httpdocs . '/router.php');
$proc = proc_open($serverCmd, [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
usleep(800000);
$stop = function () use ($proc): void {
    exec("pkill -f 'S localhost:8093' 2>/dev/null");
    usleep(200000);
    if (is_resource($proc)) { proc_terminate($proc); proc_close($proc); }
};

/* =============================================================
 * 1. Non-counting surfaces
 * ============================================================= */
$c0 = vskr_counter($pdo);
$r = vskr_go('/', 'GET');
$check('homepage 200, counter not incremented', $r['status'] === 200 && vskr_counter($pdo) === $c0);
$r = vskr_go('/?shop=shop-noaff&cart=30', 'GET');
$check('results page does NOT increment counter', vskr_counter($pdo) === $c0);
$r = vskr_go('/assets/css/main.css');
$check('asset requests do NOT increment counter', vskr_counter($pdo) === $c0);

/* =============================================================
 * 2. Valid /go/ increments exactly once per request
 * ============================================================= */
$r = vskr_go('/go/111111');
$check('valid /go/ -> 302 redirect', $r['status'] === 302, "status={$r['status']}");
$check('valid /go/ redirects to canonical merchant URL (affiliate disabled)',
    $r['location'] === 'https://shopa.example/product/111111', (string) $r['location']);
$check('valid /go/ incremented counter by exactly 1', vskr_counter($pdo) === $c0 + 1, (string) vskr_counter($pdo));

go_call('/go/111111');
go_call('/go/111111');
$check('two more valid requests => +2 total', vskr_counter($pdo) === $c0 + 3, (string) vskr_counter($pdo));

/* =============================================================
 * 3. Affiliate-enabled shop transforms the redirect
 * ============================================================= */
$r = vskr_go('/go/222222');
$check('affiliate-enabled /go/ redirects to transformed URL',
    $r['status'] === 302 && $r['location'] === 'https://shopb.example/product/222222?ref=partner',
    (string) $r['location']);
$check('affiliate /go/ also counted', vskr_counter($pdo) === $c0 + 4);

/* =============================================================
 * 4. Unknown / malformed / unsafe => no increment, no redirect
 * ============================================================= */
$r = vskr_go('/go/999999');
$check('unknown product -> 404', $r['status'] === 404);
$check('unknown product -> no increment', vskr_counter($pdo) === $c0 + 4);
$check('unknown product -> no Location header', $r['location'] === null);

$r = vskr_go('/go/abc;DROP%20TABLE');
$check('malformed id -> 404, no increment', $r['status'] === 404 && vskr_counter($pdo) === $c0 + 4);

$r = vskr_go('/go/333333'); // unsafe URL row
$check('unsafe product URL -> 404, no increment, no redirect',
    $r['status'] === 404 && $r['location'] === null && vskr_counter($pdo) === $c0 + 4);

$r = vskr_go('/go/');
$check('empty id -> not counted', vskr_counter($pdo) === $c0 + 4);

// Open-redirect safety: no user-supplied destination is ever honored.
$r = vskr_go('/go/111111?to=https://evil.example');
$check('no open redirect via query params',
    $r['status'] === 302 && str_starts_with((string) $r['location'], 'https://shopa.example/'),
    (string) $r['location']);

/* =============================================================
 * 5. Privacy: no cookies, no per-click rows
 * ============================================================= */
$r = vskr_go('/go/111111');
$check('/go/ sets no Set-Cookie', !$r['set_cookie']);
$check('VSKR_stats still has exactly ONE aggregate row', vskr_stat_rows($pdo) === 1);

// Row-level content check: the stats table contains only the aggregate.
$row = $pdo->query("SELECT stat_key, stat_value FROM VSKR_stats")->fetch(PDO::FETCH_ASSOC);
$check('stats row contains only key+value',
    count($row) === 2
    && $row['stat_key'] === 'outbound_product_clicks'
    && (int) $row['stat_value'] === vskr_counter($pdo),
    json_encode($row));

/* =============================================================
 * 6. Atomicity / concurrency (practical check)
 * ============================================================= */
$before = vskr_counter($pdo);
$phpBin = $php;
$code = '<?php $p = new PDO(' . var_export('sqlite:' . $root . '/db.sqlite', true)
    . '); $p->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);'
    . ' $p->prepare("INSERT INTO VSKR_stats (stat_key, stat_value) VALUES (:k, 1)'
    . ' ON CONFLICT(stat_key) DO UPDATE SET stat_value = stat_value + 1")'
    . '->execute([":k" => "outbound_product_clicks"]);';
file_put_contents($root . '/inc.php', $code);
$concurrent = [];
for ($i = 0; $i < 10; $i++) {
    $concurrent[] = escapeshellarg($phpBin)
        . ' -d extension_dir=' . escapeshellarg(ini_get('extension_dir') ?: '')
        . (extension_loaded('pdo_sqlite') ? ' -d extension=pdo_sqlite' : '')
        . ' ' . escapeshellarg($root . '/inc.php');
}
// run 10 concurrent increments
$procs = [];
foreach ($concurrent as $cmd) {
    $procs[] = proc_open($cmd, [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $x);
}
$failures = 0;
foreach ($procs as $p) {
    if (proc_close($p) !== 0) $failures++;
}
$after = vskr_counter($pdo);
$check('10 concurrent increments -> exactly +10 (atomic)', $after === $before + 10, "{$before} -> {$after}, failures={$failures}");

/* =============================================================
 * 7. Homepage display + no-cookie on results
 * ============================================================= */
$r = vskr_go('/');
$check('homepage shows Rettungsversuche message', str_contains($r['body'], 'Rettungsversuche gestartet'));
$check('homepage formats the number in German', str_contains($r['body'], 'Rettungsversuche gestartet')
    && preg_match('#Schon\s+<strong>[0-9.]+</strong>#', $r['body']) === 1);
$check('homepage does not claim purchases/savings',
    !str_contains($r['body'], 'gerettet') && !str_contains($r['body'], 'erfolgreich'));
$r = vskr_go('/?shop=shop-noaff&cart=30');
$check('results page carries no Set-Cookie', !$r['set_cookie']);

// Static check: wording never appears on results page (counter is homepage-only).
$check('results page does not display the counter', !str_contains($r['body'], 'Rettungsversuche'));

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

print("\nCounter tests: {$pass} passed, {$fail} failed\n");
exit($fail === 0 ? 0 : 1);
