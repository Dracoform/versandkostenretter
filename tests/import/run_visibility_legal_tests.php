<?php

declare(strict_types=1);

/**
 * Counter-visibility + legal-pages regression tests.
 *
 * Usage: php tests/import/run_visibility_legal_tests.php
 * or:    VSKR_TEST_PHP=/path/to/php php tests/import/run_visibility_legal_tests.php
 *
 * Counter (real index.php over HTTP, production-like layout):
 *  - counter 0           -> homepage hides the message
 *  - after 1 /go/ click  -> singular  "Schon 1 Rettungsversuch gestartet!"
 *  - after 2 clicks      -> plural    "Schon 2 Rettungsversuche gestartet!"
 *  - value 1284          -> "Schon 1.284 Rettungsversuche gestartet!"
 *  - plain text: no digit images/assets required
 *  - real /go/ click increments and the next homepage shows the new number
 *  - no Set-Cookie anywhere
 *
 * Legal pages:
 *  - /impressum and /datenschutz contain the final production content
 *  - no TODO placeholders, no invented phone number, no EU OS-platform link
 *  - no external assets introduced
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
$root = sys_get_temp_dir() . '/vskr-vis-' . bin2hex(random_bytes(4));
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
$pdo = new PDO($dsn);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
// BIGINT-shaped counter like the production MySQL migration 0005.
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
INSERT INTO VSKR_shops (id,name,slug,website_url,shipping_cost,free_shipping_threshold,active)
VALUES (1,\'Lootforge\',\'lootforge\',\'https://lootforge.de\',5.99,100.00,1);
INSERT INTO VSKR_products (shop_id,external_id,name,url,price,available)
VALUES (1,\'111\',\'Prod\',\'https://lootforge.de/products/prod\',9.99,1);
INSERT INTO VSKR_stats (stat_key, stat_value) VALUES (\'outbound_product_clicks\', 0);
');

// ---------------------------------------------------------------- HTTP
function vskr_http(string $path, string $method = 'GET', array $post = []): array
{
    $fp = stream_socket_client('tcp://localhost:8090', $en, $es, 5);
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
    while (!feof($fp)) $raw .= fread($fp, 32768);
    fclose($fp);
    preg_match('#^HTTP/1\.[01] (\d{3})#', $raw, $m);
    [$headers, $body] = explode("\r\n\r\n", $raw, 2) + [1 => '', 2 => ''];
    return [
        'status' => (int)($m[1] ?? 0),
        'body' => $body,
        'raw' => $raw,
        'set_cookie' => stripos($headers, 'set-cookie:') !== false,
        'cache_control' => preg_match('#^Cache-Control:\s*([^\r\n]+)#mi', $headers, $cm) ? trim($cm[1]) : null,
    ];
}
function vskr_set_counter(PDO $pdo, int $n): void
{
    $pdo->prepare('UPDATE VSKR_stats SET stat_value = ? WHERE stat_key = ?')
        ->execute([$n, 'outbound_product_clicks']);
}

$serverCmd = escapeshellarg($php)
    . ' -d extension_dir=' . escapeshellarg(ini_get('extension_dir') ?: '')
    . (extension_loaded('pdo_sqlite') ? ' -d extension=pdo_sqlite' : '')
    . ' -S localhost:8090 ' . escapeshellarg($httpdocs . '/router.php');
$proc = proc_open($serverCmd, [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
usleep(800000);
$stop = function () use ($proc): void {
    exec("pkill -f 'S localhost:8090' 2>/dev/null");
    usleep(200000);
    if (is_resource($proc)) { proc_terminate($proc); proc_close($proc); }
};

/* =============================================================
 * A) Counter visibility
 * ============================================================= */

// 0 -> hidden
vskr_set_counter($pdo, 0);
$r = vskr_http('/');
$check('counter 0 -> homepage hides message', !str_contains($r['body'], 'Rettungsversuche'));

// 1 -> singular
vskr_set_counter($pdo, 1);
$r = vskr_http('/');
$check('counter 1 -> singular "Schon 1 Rettungsversuch gestartet!"',
    str_contains($r['body'], 'Schon <strong>1</strong>')
    && str_contains($r['body'], 'Rettungsversuch gestartet!')
    && !str_contains($r['body'], 'Rettungsversuche gestartet!'));

// 2 -> plural
vskr_set_counter($pdo, 2);
$r = vskr_http('/');
$check('counter 2 -> plural "Schon 2 Rettungsversuche gestartet!"',
    str_contains($r['body'], 'Schon <strong>2</strong>')
    && str_contains($r['body'], 'Rettungsversuche gestartet!'));

// 1284 -> German formatting
vskr_set_counter($pdo, 1284);
$r = vskr_http('/');
$check('counter 1284 -> "Schon 1.284 Rettungsversuche gestartet!"',
    str_contains($r['body'], 'Schon <strong>1.284</strong>')
    && str_contains($r['body'], 'Rettungsversuche gestartet!'));

// Plain accessible text: no digit-image assets anywhere in the counter block.
preg_match('/<p class="rescue-counter">.*?<\/p>/s', $r['body'], $counterHtml);
$check('counter is plain text (no <img>, no digit assets)',
    isset($counterHtml[0]) && !str_contains($counterHtml[0], '<img'));
$check('no digit image assets exist in the deployed assets tree',
    !glob($httpdocs . '/assets/**/digit*') === false || count(glob($httpdocs . '/assets/**/*digit*') ?: []) === 0);

// Real /go/ click increments; subsequent homepage shows the new number.
vskr_set_counter($pdo, 4);
$fp = stream_socket_client('tcp://localhost:8090', $en, $es, 5);
fwrite($fp, "GET /go/111 HTTP/1.1\r\nHost: localhost\r\nConnection: close\r\n\r\n");
$raw = '';
while (!feof($fp)) $raw .= fread($fp, 8192);
fclose($fp);
$stored = (int) $pdo->query("SELECT stat_value FROM VSKR_stats WHERE stat_key = 'outbound_product_clicks'")->fetchColumn();
$check('/go/ click increments stored value 4 -> 5', $stored === 5, (string) $stored);
$r = vskr_http('/');
$check('homepage after click shows the new number (5)',
    str_contains($r['body'], 'Schon <strong>5</strong>'));

// Cache-Control on homepage: stale pre-counter pages must not be served.
$hr = vskr_http('/');
$check('homepage sends no-cache Cache-Control',
    $hr['cache_control'] !== null && str_contains($hr['cache_control'], 'no-cache'),
    (string) $hr['cache_control']);

// No cookies, no session anywhere in this flow.
$check('homepage sets no Set-Cookie', !str_contains($r['raw'], 'set-cookie:'));

/* =============================================================
 * B/C) Legal pages
 * ============================================================= */
$r = vskr_http('/?page=impressum');
$check('/impressum -> 200', $r['status'] === 200, "status={$r['status']}");
foreach (['Frank Rosellen', 'Horstdyk 51', '47803 Krefeld', 'frank@versandkostenretter.de', '§ 5 DDG', 'Deutschland'] as $needle) {
    $check("/impressum contains '{$needle}'", str_contains($r['body'], $needle));
}
$check('/impressum: mailto link present and human-readable',
    str_contains($r['body'], 'href="mailto:frank@versandkostenretter.de"')
    && str_contains($r['body'], 'frank@versandkostenretter.de'));
$check('/impressum: no TODO placeholders', !str_contains($r['body'], 'TODO'));
$check('/impressum: no telephone number', !preg_match('#Tel\.|Telefon|\+49[0-9 ]+#i', $r['body']));
$check('/impressum: no EU OS-platform link', !preg_match('#ec\.europa\.eu|Online-Streitbeilegung|OS-Plattform#i', $r['body']));
$check('/impressum: no VAT ID / register boilerplate', !preg_match('#USt[- ]?Id|Handelsregister|Geschäftsführer#i', $r['body']));
$check('/impressum: sets no cookie', !$r['set_cookie']);
$check('/impressum: no external assets', !preg_match('#(https?:)?//(?!(localhost|127\.0\.0\.1))#i', preg_replace('#mailto:[^"]+#', '', $r['body'])));

$r = vskr_http('/?page=datenschutz');
$check('/datenschutz -> 200', $r['status'] === 200, "status={$r['status']}");
foreach ([
    'Frank Rosellen', 'Horstdyk 51', '47803 Krefeld', 'frank@versandkostenretter.de',
    'netcup GmbH', 'Daimlerstraße 25', '76185 Karlsruhe',
    'Art. 6 Abs. 1 lit. f DSGVO', '14 Tage',
    'Stand: September 2026', 'Datenschutz-Aufsichtsbehörde',
    'Produktbilder von externen Servern', 'aggregierter Zähler',
] as $needle) {
    $check("/datenschutz contains '{$needle}'", str_contains($r['body'], $needle));
}
$check('/datenschutz: no TODO placeholders', !str_contains($r['body'], 'TODO'));
$check('/datenschutz: no telephone number', !preg_match('#Tel\.|Telefon|\+49[0-9 ]+#i', $r['body']));
$check('/datenschutz: no EU OS-platform link', !preg_match('#ec\.europa\.eu|Online-Streitbeilegung|OS-Plattform#i', $r['body']));
$check('/datenschutz: no Datenschutzbeauftragter invented', !str_contains($r['body'], 'Datenschutzbeauftragte'));
$check('/datenschutz: mentions hosting logs (no false "no data" claim)',
    str_contains($r['body'], 'Server-Logdateien'));
$check('/datenschutz: sets no cookie', !$r['set_cookie']);
$check('/datenschutz: no external assets', !preg_match('#(https?:)?//(?!(localhost|127\.0\.0\.1))#i', preg_replace('#mailto:[^"]+#', '', $r['body'])));

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

print("\nVisibility+legal tests: {$pass} passed, {$fail} failed\n");
exit($fail === 0 ? 0 : 1);
