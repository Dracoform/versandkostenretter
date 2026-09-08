<?php

declare(strict_types=1);

/**
 * "Das Projekt" page regression tests.
 *
 * Usage: php tests/import/run_project_page_tests.php
 * or:    VSKR_TEST_PHP=/path/to/php php tests/import/run_project_page_tests.php
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
$root = sys_get_temp_dir() . '/vskr-proj-' . bin2hex(random_bytes(4));
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

// Minimal schema so the homepage/search flow is unaffected and functional.
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
CREATE TABLE VSKR_stats (stat_key TEXT PRIMARY KEY, stat_value INTEGER NOT NULL DEFAULT 0);
INSERT INTO VSKR_shops (id,name,slug,website_url,shipping_cost,free_shipping_threshold,active)
VALUES (1,\'Lootforge\',\'lootforge\',\'https://lootforge.de\',5.99,100.00,1);
INSERT INTO VSKR_products (shop_id,external_id,name,url,price,available,category)
VALUES (1,\'111\',\'Wet Palette\',\'https://lootforge.de/products/wet-palette\',7.20,1,\'Wet Palette\');
');

// ---------------------------------------------------------------- HTTP
function vskr_http(string $path, string $method = 'GET', array $post = []): array
{
    $fp = stream_socket_client('tcp://localhost:8084', $en, $es, 5);
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
        'set_cookie' => stripos($headers, 'set-cookie:') !== false,
    ];
}

$serverCmd = escapeshellarg($php)
    . ' -d extension_dir=' . escapeshellarg(ini_get('extension_dir') ?: '')
    . (extension_loaded('pdo_sqlite') ? ' -d extension=pdo_sqlite' : '')
    . ' -S localhost:8084 ' . escapeshellarg($httpdocs . '/router.php');
$proc = proc_open($serverCmd, [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
usleep(800000);
$stop = function () use ($proc): void {
    exec("pkill -f 'S localhost:8084' 2>/dev/null");
    usleep(200000);
    if (is_resource($proc)) { proc_terminate($proc); proc_close($proc); }
};

/* =============================================================
 * 1. Route + content
 * ============================================================= */
$r = vskr_http('/?page=projekt');
$check('/?page=projekt -> 200', $r['status'] === 200, "status={$r['status']}");
$check('page title "Das Projekt" rendered', str_contains($r['body'], '<h1>Das Projekt</h1>'));
// Whitespace-normalized view for multi-line copy needles (tags stripped):
$text = preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($r['body'])));

foreach ([
    'Warum gibt es den Versandkostenretter?',
    'DEM EINEN Schnäppchen',
    '„Ausverkauft“',
    'Hier kommt der Versandkostenretter ins Spiel!',
    'Grenze zum Gratisversand',
    'Kategorien filtern',
    'Caliban Green',
    'Wie kam es dazu?',
    'neutrale Seite im Netz',
    'selbst anzugehen',
    'Probiert es aus – und viel Spaß beim Warenkorb-Retten!',
    'Ein Hobbyprojekt',
    'Es sollte von Anfang an ein kleines Hobbyprojekt sein.',
    'Keine Benutzerkonten, kein Tracking, keine Werbung – nicht einmal Affiliate-Links.',
    'Werkzeug, das ich selbst gerne gehabt hätte',
] as $needle) {
    $check("contains '{$needle}'", str_contains($text, $needle));
}

$check('emoji mascot present (🦝)', str_contains($r['body'], '🦝'));

/* =============================================================
 * 2. No placeholders / no invented personal data
 * ============================================================= */
$check('no TODO placeholders', !str_contains($r['body'], 'TODO'));
$check('no placeholder class', !str_contains($r['body'], 'placeholder'));
// No personal data beyond what the legal pages already carry:
$check('no address on the project page', !str_contains($r['body'], 'Horstdyk'));
$check('no postal code on the project page', !str_contains($r['body'], '47803'));
$check('no phone-like content', !preg_match('#\+49|Telefon#i', $r['body']));
$check('no GitHub/source link (decided separately)', !preg_match('#github\.com#i', $r['body']) && !str_contains($r['body'], 'Quellcode'));
$check('no social media links', !preg_match('#(twitter|x\.com|instagram|facebook|mastodon)#i', $r['body']));

/* =============================================================
 * 3. Footer navigation
 * ============================================================= */
$home = vskr_http('/');
$check('footer on homepage contains "Das Projekt"', str_contains($home['body'], '/?page=projekt'));
$check('footer order: Das Projekt · Datenschutz · Impressum',
    strpos($home['body'], '/?page=projekt') < strpos($home['body'], '/?page=datenschutz')
    && strpos($home['body'], '/?page=datenschutz') < strpos($home['body'], '/?page=impressum'));
$r = vskr_http('/?page=datenschutz');
$check('footer on Datenschutz also links Das Projekt', str_contains($r['body'], '/?page=projekt'));
$r = vskr_http('/?page=impressum');
$check('footer on Impressum also links Das Projekt', str_contains($r['body'], '/?page=projekt'));

/* =============================================================
 * 4. Privacy
 * ============================================================= */
$check('project page sets no Set-Cookie', !$r['set_cookie']);
$r = vskr_http('/?page=projekt');
$check('project page sets no Set-Cookie', !$r['set_cookie']);
$check('project page introduces no external requests',
    !preg_match('#(https?:)?//(?!localhost)#i', preg_replace('#mailto:[^"]+#', '', $r['body'])));
$check('no external fonts', !preg_match('#fonts\.(googleapis|gstatic)#i', $r['body']));
$check('no analytics/tracking snippets', !preg_match('#(gtag|analytics|matomo|hotjar)#i', $r['body']));

/* =============================================================
 * 5. Affiliate-disabled claim is truthful in code/config
 * ============================================================= */
$mig3 = (string) file_get_contents($httpdocs . '/database/migrations/0003_lootforge_shop.sql');
$check('migration 0003 forces affiliate_enabled = 0', (bool) preg_match('/affiliate_enabled\s*=\s*0/', $mig3));
$schema = (string) file_get_contents($httpdocs . '/database/schema.sql');
$check('schema default for affiliate is 0 (disabled)', (bool) preg_match('/affiliate_enabled\s+TINYINT\(1\)\s+NOT NULL DEFAULT 0/', $schema));
$outbound = (string) file_get_contents($httpdocs . '/src/OutboundLink.php');
$check('OutboundLink: disabled => canonical URL unchanged (code truth)',
    str_contains($outbound, "return ['url' => \$safe, 'is_affiliate' => false];"));
$check('no affiliate codes configured anywhere', !preg_match('/affiliate_value\s*=>\s*\'[^\']+\'>?/', $httpdocs . '/src/OutboundLink.php'));

/* =============================================================
 * 6. Homepage/search/results unaffected
 * ============================================================= */
$home = vskr_http('/');
$check('homepage still renders search form', str_contains($home['body'], 'Show me the goods!'));
$check('homepage shows no project text (full text lives on its own page)',
    !str_contains($home['body'], 'Warum gibt es den Versandkostenretter?'));
$r = vskr_http('/?shop=lootforge&cart=95,34');
$check('search/results flow unaffected', $r['status'] === 200 && str_contains($r['body'], 'Passende Produkte'));
$check('results page unaffected by project page', !str_contains($r['body'], 'Das Projekt</h1>'));

// Counter still hidden at 0, homepage otherwise identical
$check('homepage counter still hidden at 0', !str_contains($home['body'], 'Rettungsversuche'));

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

print("\nProject-page tests: {$pass} passed, {$fail} failed\n");
exit($fail === 0 ? 0 : 1);
