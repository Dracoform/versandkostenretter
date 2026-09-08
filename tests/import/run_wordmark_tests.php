<?php

declare(strict_types=1);

/**
 * Wordmark branding regression tests.
 *
 * Usage: php tests/import/run_wordmark_tests.php
 * or:    VSKR_TEST_PHP=/path/to/php php tests/import/run_wordmark_tests.php
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
 * 1. Asset verification (file metadata, untouched artwork)
 * ============================================================= */
$master = $repo . '/assets/04-Wortmarke.webp';
$deployed = $repo . '/assets/images/wordmark.webp';
$check('master wordmark exists under assets-design/', is_file($master));
$check('production wordmark exists at assets/images/wordmark.webp', is_file($deployed));
$check('deployed file is byte-identical to the master (not regenerated/altered)',
    is_file($deployed) && hash_file('sha256', $deployed) === hash_file('sha256', $master));

$bytes = (string) file_get_contents($deployed);
$check('file size ≈128 KB (125,534 bytes)', strlen($bytes) === 125534, (string) strlen($bytes));
$check('WebP VP8X format with alpha bit set',
    substr($bytes, 12, 4) === 'VP8X' && ((ord($bytes[20]) & 0x10) !== 0));
$check('explicit ALPH chunk present (transparency)', str_contains($bytes, 'ALPH'));
$w = 1 + (ord($bytes[24]) + (ord($bytes[25]) << 8) + (ord($bytes[26]) << 16));
$h = 1 + (ord($bytes[27]) + (ord($bytes[28]) << 8) + (ord($bytes[29]) << 16));
$check('dimensions 2172 x 724 (expected)', $w === 2172 && $h === 724, "{$w}x{$h}");

/* =============================================================
 * 2. Cache busting via the existing Assets::url() mechanism
 * ============================================================= */
require_once $repo . '/src/Assets.php';
\Versandkostenretter\Assets::setDocRoot($repo);
$url = \Versandkostenretter\Assets::url('/assets/images/wordmark.webp');
preg_match('#\?v=(\d+)$#', $url, $mv);
$check('wordmark URL is versioned via filemtime',
    ($mv[1] ?? '') === (string) filemtime($deployed), $url);

sleep(1);
touch($deployed, filemtime($deployed) + 5);
clearstatcache(true, $deployed);
$newUrl = \Versandkostenretter\Assets::url('/assets/images/wordmark.webp');
$check('replaced wordmark (same filename) gets a new versioned URL', $newUrl !== $url,
    "{$url} -> {$newUrl}");
// restore mtime for reproducibility
touch($deployed, time());

/* =============================================================
 * 3. Template wiring (HTTP over the real index.php)
 * ============================================================= */
$root = sys_get_temp_dir() . '/vskr-wm-' . bin2hex(random_bytes(4));
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
$pdo->exec('CREATE TABLE VSKR_shops (id INTEGER PRIMARY KEY, name TEXT, slug TEXT UNIQUE, website_url TEXT, shipping_cost NUMERIC, free_shipping_threshold NUMERIC, active INTEGER DEFAULT 1, affiliate_enabled INTEGER DEFAULT 0, affiliate_mode TEXT, affiliate_param TEXT, affiliate_value TEXT, affiliate_template TEXT, source_type TEXT, source_url TEXT, source_scope TEXT DEFAULT "default", product_images_enabled INTEGER DEFAULT 0, updated_at TEXT);
CREATE TABLE VSKR_products (id INTEGER PRIMARY KEY, shop_id INTEGER, external_id TEXT, name TEXT, url TEXT, price NUMERIC, available INTEGER DEFAULT 1, category TEXT, image_url TEXT, source_type TEXT, source_scope TEXT DEFAULT "default", last_seen_at TEXT, updated_at TEXT);
CREATE TABLE VSKR_stats (stat_key TEXT PRIMARY KEY, stat_value INTEGER DEFAULT 0)');

$serverCmd = escapeshellarg($php)
    . ' -d extension_dir=' . escapeshellarg(ini_get('extension_dir') ?: '')
    . (extension_loaded('pdo_sqlite') ? ' -d extension=pdo_sqlite' : '')
    . ' -S localhost:8083 ' . escapeshellarg($httpdocs . '/router.php');
$proc = proc_open($serverCmd, [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
usleep(800000);
$stop = function () use ($proc): void {
    exec("pkill -f 'S localhost:8083' 2>/dev/null");
    usleep(200000);
    if (is_resource($proc)) { proc_terminate($proc); proc_close($proc); }
};
$http = function (string $path) {
    $fp = stream_socket_client('tcp://localhost:8083', $en, $es, 5);
    if ($fp === false) { fwrite(STDERR, "connect failed\n"); exit(2); }
    fwrite($fp, "GET {$path} HTTP/1.1\r\nHost: localhost\r\nConnection: close\r\n\r\n");
    $raw = '';
    while (!feof($fp)) $raw .= fread($fp, 32768);
    fclose($fp);
    preg_match('#^HTTP/1\.[01] (\d{3})#', $raw, $m);
    return ['status' => (int)($m[1] ?? 0), 'body' => explode("\r\n\r\n", $raw, 2)[1] ?? '', 'raw' => $raw];
};

$home = $http('/');
$check('homepage 200', $home['status'] === 200);
$check('header renders the versioned wordmark image',
    (bool) preg_match('#<img class="brand-wordmark"\s*src="/assets/images/wordmark\.webp\?v=\d+"#', $home['body']));
$check('wordmark has accessible alt text (brand name)',
    str_contains($home['body'], 'alt="Versandkostenretter"'));
$check('wordmark carries intrinsic dimensions (2172/724)',
    str_contains($home['body'], 'width="2172" height="724"'));

// Old text-only branding removed where replaced:
$check('no text-only brand-name span in the header', !str_contains($home['body'], 'brand-name'));
$check('no brand-badge SVG logo in the header', !str_contains($home['body'], 'brand-badge'));

// Redundant small raccoon removed from the homepage hero:
$check('old small raccoon mascot removed from homepage', !str_contains($home['body'], 'hero-mascot'));
$check('old small raccoon SVG removed from homepage', !str_contains($home['body'], 'Maskottchen: Waschbär mit Rettungsring'));
$check('hero title still present ("Rette deinen Warenkorb!")', str_contains($home['body'], 'Rette deinen Warenkorb!'));
$check('large hero artwork unchanged (still referenced and present)',
    str_contains($home['body'], 'hero-raccoon.webp') && is_file($httpdocs . '/assets/images/hero-raccoon.webp'));

// Responsive CSS, no fixed overflowing width:
$css = (string) file_get_contents($httpdocs . '/assets/css/main.css');
$check('wordmark CSS scales responsively (desktop cap 620px, mobile 90vw)',
    str_contains($css, 'max-width: min(620px, 90%)'));
$check('wordmark height is auto', str_contains($css, 'height: auto'));
$check('wordmark keeps aspect ratio via object-fit: contain',
    str_contains($css, 'object-fit: contain'));
$check('no teal/green header band behind the wordmark',
    !preg_match('#\.site-header\s*{[^}]*background:\s*linear-gradient#i', $css)
    && !preg_match('#\.site-header\s*{[^}]*background:\s*(var\(--teal|#[0-9a-f]{3,6})#i', $css));
$check('no white pill/background around the wordmark',
    !preg_match('#\.brand-wordmark\s*{[^}]*background#i', $css));
$check('old small raccoon remains absent from homepage', !str_contains($home['body'], 'hero-mascot'));
$check('wordmark centered in header', str_contains($css, 'justify-content: center'));

// Mobile layout intact (small width does not overflow):
preg_match('/<img class="brand-wordmark"/', $home['body']) === 1;
$check('search form still present on homepage', str_contains($home['body'], 'Show me the goods!'));

// Wordmark asset served over HTTP:
$r = $http('/assets/images/wordmark.webp');
$check('wordmark asset served over HTTP (200)', $r['status'] === 200);
// Versioned URL also served:
preg_match('#/assets/images/wordmark\.webp\?v=(\d+)#', $home['body'], $wv);
$r = $http('/assets/images/wordmark.webp?v=' . ($wv[1] ?? '1'));
$check('versioned wordmark URL serves (200)', $r['status'] === 200);

// Hero raccoon asset untouched:
$check('hero WebP asset still byte-identical to tracked master',
    hash_file('sha256', $httpdocs . '/assets/images/hero-raccoon.webp')
    === hash_file('sha256', $repo . '/assets/images/hero-raccoon.webp'));

// No cookies / no external requests on homepage:
$check('homepage sets no Set-Cookie', !str_contains($home['raw'], 'set-cookie:'));
$check('homepage introduces no external requests',
    !preg_match('#(https?:)?//(?!localhost)#i', preg_replace('#mailto:[^"]+#', '', $home['body'])));

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

print("\nWordmark tests: {$pass} passed, {$fail} failed\n");
exit($fail === 0 ? 0 : 1);
