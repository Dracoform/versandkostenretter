<?php

declare(strict_types=1);

/**
 * End-to-end form-flow regression test (Lootforge + 95,34).
 *
 * Usage: php tests/import/run_form_flow_tests.php
 * or:    VSKR_TEST_PHP=/path/to/php php tests/import/run_form_flow_tests.php
 *
 * Executes the REAL index.php (HTTP, PHP dev server, real templates, real
 * form field names shop/cart) against a production-like layout
 * (httpdocs = repo root, config OUTSIDE httpdocs) seeded with the imported
 * Lootforge catalogue. No network needed: the source feed was already
 * imported into the DB.
 *
 * Covers:
 *  - GET  ?shop=lootforge&cart=95,34   (comma decimal)
 *  - GET  ?shop=lootforge&cart=95.34   (dot decimal)
 *  - POST form submit -> 303 -> same results (field names shop/cart)
 *  - missing/invalid input -> proper validation errors
 *  - results retained: shop + cart carried through (shareable URL)
 *  - missing amount = 4,66 EUR (threshold 100,00)
 *  - qualifying products: 7,20 / 24,00 / 26,99 / 37,99 (ascending)
 *  - category filter still works
 *  - JS field IDs in main.js match the template (root cause of the prod bug)
 *  - no Set-Cookie regression
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
$root = sys_get_temp_dir() . '/vskr-form-' . bin2hex(random_bytes(4));
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

// Config OUTSIDE httpdocs (SQLite).
@mkdir(dirname($httpdocs) . '/config', 0777, true);
$dsn = 'sqlite:' . $root . '/db.sqlite';
file_put_contents(
    dirname($httpdocs) . '/config/config.php',
    "<?php return ['db'=>['host'=>'unused','port'=>0,'name'=>'unused','user'=>'unused','pass'=>'SECRET-no-credentials-in-output','dsn'=>" . var_export($dsn, true) . "],'app'=>['debug'=>false,'max_results'=>24]];"
);

// ---------------------------------------------------------------- seed DB
// The imported Lootforge catalogue (values as imported from the real feed).
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
$pdo->exec("INSERT INTO VSKR_shops (id,name,slug,website_url,shipping_cost,free_shipping_threshold,active,affiliate_enabled,source_type,source_url,source_scope)
VALUES (1,'Lootforge','lootforge','https://lootforge.de',5.99,100.00,1,0,'shopify','https://lootforge.de/collections/zubehor-furs-malen/products.json?limit=250','zubehor-furs-malen')");
$products = [
    // below missing amount (4.66): must NOT appear
    [10293528363337, 'PK-Aluminiumpalette-Rund-6-Näpfe-(11cm)', 'pk-aluminiumpalette-rund-6-n-pfe-11cm', 2.65, 1, 'Farben Zubehör'],
    [10293528428873, 'PK-Aluminiumpalette-Rund-10-Näpfe-(17cm)', 'pk-aluminiumpalette-rund-10-n-pfe-17cm', 3.10, 1, 'Farben Zubehör'],
    [10293528265033, 'PK-Agitatorkugel-Set-(50x)', 'pk-agitatorkugel-set-50x', 3.95, 1, 'Farben Zubehör'],
    [10293528297801, 'PK-Aluminiumpalette-Eckig-6-Näpfe-(8x13cm)', 'pk-aluminiumpalette-eckig-6-n-pfe-8x13cm', 2.50, 1, 'Farben Zubehör'],
    // qualifying (>= 4.66)
    [10293528625481, 'RedgrasGames - Hydration Paper Painter 50 sheets', 'redgrasgames-hydration-paper-painter-50-sheets', 7.20, 1, 'Wet Palette'],
    [10293528494409, 'RedgrasGames - NEW - Painter Lite - 50sheets/2foams', 'redgrasgames-new-painter-lite-50sheets-2foams', 24.00, 1, 'Wet Palette'],
    [10293485502793, 'Wet Palette', 'wet-palette', 26.99, 1, 'Wet Palette'],
    [10293485535561, 'Wet Palette Hydro Bundle', 'wet-palette-hydro-bundle', 37.99, 1, 'Wet Palette'],
];
$st = $pdo->prepare('INSERT INTO VSKR_products (shop_id,external_id,name,url,price,available,category,source_type,source_scope)
VALUES (1,?,?,?,?,?,?,\'shopify\',\'zubehor-furs-malen\')');
foreach ($products as $p) {
    $st->execute([$p[0], $p[1], 'https://lootforge.de/products/' . $p[2], $p[3], $p[4], $p[5]]);
}

// ---------------------------------------------------------------- HTTP helper
function vskr_http(string $url, string $method = 'GET', array $post = []): array
{
    $parts = parse_url($url);
    $host = $parts['host'] ?? 'localhost';
    $port = $parts['port'] ?? 80;
    $path = ($parts['path'] ?? '/') . (isset($parts['query']) ? '?' . $parts['query'] : '');
    $fp = stream_socket_client("tcp://{$host}:{$port}", $errno, $errstr, 5);
    if ($fp === false) {
        fwrite(STDERR, "connect failed: {$errstr}\n");
        exit(2);
    }
    $req = "{$method} {$path} HTTP/1.1\r\nHost: {$host}\r\nConnection: close\r\n";
    if ($post !== []) {
        $body = http_build_query($post);
        $req .= "Content-Type: application/x-www-form-urlencoded\r\nContent-Length: " . strlen($body) . "\r\n\r\n" . $body;
    } else {
        $req .= "\r\n";
    }
    fwrite($fp, $req);
    $raw = '';
    while (!feof($fp)) {
        $raw .= fread($fp, 16384);
    }
    fclose($fp);
    preg_match('#^HTTP/1\.[01] (\d{3})#', $raw, $m);
    [$headers, $body] = explode("\r\n\r\n", $raw, 2) + [1 => '', 2 => ''];
    return [
        'status' => (int) ($m[1] ?? 0),
        'headers' => $headers,
        'body' => $body,
        'set_cookie' => stripos($headers, 'set-cookie:') !== false,
        'location' => preg_match('#^Location:\s*(\S+)#mi', $headers, $lm) ? trim($lm[1]) : null,
    ];
}

// ---------------------------------------------------------------- server
$serverCmd = escapeshellarg($php)
    . ' -d extension_dir=' . escapeshellarg(ini_get('extension_dir') ?: '')
    . (extension_loaded('pdo_sqlite') ? ' -d extension=pdo_sqlite' : '')
    . ' -S localhost:8098 ' . escapeshellarg($httpdocs . '/router.php');
$proc = proc_open($serverCmd, [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
usleep(800000);
$stopServer = function () use ($proc): void {
    exec("pkill -f 'S localhost:8098' 2>/dev/null");
    usleep(200000);
    if (is_resource($proc)) {
        proc_terminate($proc);
        proc_close($proc);
    }
};

$base = 'http://localhost:8098';

/* =============================================================
 * 1. GET comma decimal — the exact production scenario
 * ============================================================= */
$r = vskr_http($base . '/?shop=lootforge&cart=95%2C34');
$check('comma decimal: results page 200', $r['status'] === 200, "status={$r['status']}");
$check('comma decimal: no validation errors', !str_contains($r['body'], 'Bitte wähle einen Shop aus.')
    && !str_contains($r['body'], 'Bitte gib deinen aktuellen Warenkorbwert ein'));
$check('comma decimal: Lootforge shown as shop', str_contains($r['body'], 'Lootforge'));
$check('comma decimal: cart value shown (95,34 €)', str_contains($r['body'], '95,34'));
$check('comma decimal: threshold 100,00 € shown', str_contains($r['body'], '100,00'));
$check('comma decimal: missing amount 4,66 € shown', str_contains($r['body'], '4,66'));
foreach (['7,20', '24,00', '26,99', '37,99'] as $price) {
    $check("comma decimal: qualifying product {$price} listed", str_contains($r['body'], $price));
}
foreach (['2,65', '3,10', '3,95', '2,50'] as $price) {
    $check("comma decimal: below-threshold product {$price} NOT listed", !str_contains($r['body'], $price));
}
$check('comma decimal: ascending order (7,20 before 24,00 before 26,99 before 37,99)',
    strpos($r['body'], '7,20') < strpos($r['body'], '24,00')
    && strpos($r['body'], '24,00') < strpos($r['body'], '26,99')
    && strpos($r['body'], '26,99') < strpos($r['body'], '37,99'));

/* =============================================================
 * 2. GET dot decimal — still works
 * ============================================================= */
$r = vskr_http($base . '/?shop=lootforge&cart=95.34');
$check('dot decimal: results 200, no errors', $r['status'] === 200
    && !str_contains($r['body'], 'Bitte gib deinen aktuellen Warenkorbwert ein'));
$check('dot decimal: missing amount 4,66 € shown', str_contains($r['body'], '4,66'));
$check('dot decimal: same qualifying products', str_contains($r['body'], '7,20')
    && str_contains($r['body'], '37,99'));

/* =============================================================
 * 3. POST form submit with the REAL field names (shop, cart)
 * ============================================================= */
$r = vskr_http($base . '/', 'POST', ['shop' => 'lootforge', 'cart' => '95,34']);
$check('POST submit -> 303 redirect', $r['status'] === 303, "status={$r['status']}");
$check('POST redirect target carries shop+cart (shareable URL)',
    $r['location'] !== null && str_contains($r['location'], 'shop=lootforge')
    && str_contains($r['location'], 'cart=95'),
    (string) $r['location']);
$follow = vskr_http($base . ($r['location'] ?? '/'));
$check('POST follow-up shows results', $follow['status'] === 200
    && str_contains($follow['body'], '4,66'));
$check('POST follow-up: no Set-Cookie', !$follow['set_cookie']);

/* =============================================================
 * 4. Invalid / missing input still validated server-side
 * ============================================================= */
$r = vskr_http($base . '/?cart=95,34'); // shop missing
$check('missing shop -> validation error', str_contains($r['body'], 'Bitte wähle einen Shop aus.'));
$check('missing shop: no results block', !str_contains($r['body'], 'Passende Produkte'));

$r = vskr_http($base . '/?shop=lootforge'); // cart missing
$check('missing cart -> validation error', str_contains($r['body'], 'Bitte gib deinen aktuellen Warenkorbwert ein.'));

$r = vskr_http($base . '/?shop=lootforge&cart=abc');
$check('non-numeric cart -> validation error', str_contains($r['body'], 'z. B. 125.34 oder 125,34'));

$r = vskr_http($base . '/?shop=nonexistent-shop&cart=95,34');
$check('unknown shop -> validation error', str_contains($r['body'], 'Unbekannter Shop'));

/* =============================================================
 * 5. Category filter still works
 * ============================================================= */
$r = vskr_http($base . '/?shop=lootforge&cart=95,34&category=' . urlencode('Wet Palette'));
$check('category filter: results 200', $r['status'] === 200);
$check('category filter: Wet Palette products listed', str_contains($r['body'], '7,20') && str_contains($r['body'], '37,99'));
// nothing from the other category leaked in:
$check('category filter: other category excluded (2,65 not listed)', !str_contains($r['body'], '2,65'));

/* =============================================================
 * 6. JS/HTML field consistency (root cause of the production bug)
 * ============================================================= */
$home = vskr_http($base . '/');
preg_match_all('/name="(shop|cart|category)[^"]*"/', $home['body'], $names);
$js = (string) file_get_contents($httpdocs . '/assets/js/main.js');
$check('form field names are shop/cart (not legacy shop_id/cart_value)',
    in_array('name="shop"', $names[0], true) && in_array('name="cart"', $names[0], true)
    && !str_contains($home['body'], 'name="shop_id"') && !str_contains($home['body'], 'name="cart_value"'));
$check('main.js references the real field IDs (shop, cart — not legacy shop_id/cart_value)',
    str_contains($js, "getElementById('shop')") && str_contains($js, "getElementById('cart')")
    && !str_contains($js, "getElementById('shop_id')") && !str_contains($js, "getElementById('cart_value')"));

// Field IDs referenced by JS must exist in the rendered form.
foreach (['shop', 'cart'] as $id) {
    $check("template contains id=\"{$id}\"", str_contains($home['body'], "id=\"{$id}\""));
}

/* =============================================================
 * 7. No cookie/session regression
 * ============================================================= */
foreach (['/', '/?shop=lootforge&cart=95%2C34', '/?page=datenschutz'] as $path) {
    $r = vskr_http($base . $path);
    $check("no Set-Cookie on {$path}", !$r['set_cookie']);
}

$stopServer();
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

print("\nForm-flow tests: {$pass} passed, {$fail} failed\n");
exit($fail === 0 ? 0 : 1);
