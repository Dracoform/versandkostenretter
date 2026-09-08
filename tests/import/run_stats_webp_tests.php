<?php

declare(strict_types=1);

/**
 * Regression tests: bin/stats.php namespace fix + transparent WebP hero.
 *
 * Usage: php tests/import/run_stats_webp_tests.php
 * or:    VSKR_TEST_PHP=/path/to/php php tests/import/run_stats_webp_tests.php
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
 * 1. bin/stats.php — executes the REAL script in a production layout
 * ============================================================= */
$root = sys_get_temp_dir() . '/vskr-stats-' . bin2hex(random_bytes(4));
$httpdocs = $root . '/versandkostenretter.de/httpdocs';
@mkdir($httpdocs . '/bin', 0777, true);
@mkdir($httpdocs . '/src', 0777, true);
@mkdir($httpdocs . '/database/migrations', 0777, true);
@mkdir(dirname($httpdocs) . '/config', 0777, true);
copy($repo . '/bin/stats.php', $httpdocs . '/bin/stats.php');
foreach (glob($repo . '/src/*.php') as $f) {
    copy($f, $httpdocs . '/src/' . basename($f));
}
$dsn = 'sqlite:' . $root . '/db.sqlite';
file_put_contents(dirname($httpdocs) . '/config/config.php',
    "<?php return ['db'=>['host'=>'u','port'=>0,'name'=>'u','user'=>'u','pass'=>'SECRET-no-output','dsn'=>" . var_export($dsn, true) . "],'app'=>[]];");
$pdo = new PDO($dsn);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE VSKR_stats (stat_key TEXT PRIMARY KEY, stat_value INTEGER NOT NULL DEFAULT 0)');
$pdo->exec("INSERT INTO VSKR_stats (stat_key, stat_value) VALUES ('outbound_product_clicks', 3)");

function vskr_run_stats(string $php, string $httpdocs): array
{
    $cmd = escapeshellarg($php)
        . ' -d extension_dir=' . escapeshellarg(ini_get('extension_dir') ?: '')
        . (extension_loaded('pdo_sqlite') ? ' -d extension=pdo_sqlite' : '')
        . ' ' . escapeshellarg($httpdocs . '/bin/stats.php') . ' 2>&1';
    $out = [];
    exec($cmd, $out, $code);
    return ['code' => $code, 'out' => implode("\n", $out)];
}

// THE regression: the current unqualified-Database bug makes this fatal
// ("Class Database not found"); the fix must run and print the counter.
$r = vskr_run_stats($php, $httpdocs);
$check('bin/stats.php runs without fatal (Database resolves)', $r['code'] === 0, "code={$r['code']}: {$r['out']}");
$check('stats.php prints the aggregate counter', str_contains($r['out'], 'outbound_product_clicks: 3'), $r['out']);
$check('stats.php output contains no credentials', !str_contains($r['out'], 'SECRET-no-output'));

// Key consistency: migration, repo constant and CLI all use the same key.
$mig5 = (string) file_get_contents($repo . '/database/migrations/0005_stats_counter.sql');
$statsSrc = (string) file_get_contents($repo . '/src/StatsRepository.php');
$statsCli = (string) file_get_contents($httpdocs . '/bin/stats.php');
preg_match("/public const OUTBOUND_PRODUCT_CLICKS = '([^']+)'/", $statsSrc, $m);
$key = $m[1] ?? '';
$check('stat key consistent: migration vs repository vs CLI', $key !== ''
    && str_contains($mig5, "'{$key}'") && str_contains($statsCli, $key), $key);

// Open DB once: the script must contain exactly one fromConfigFile call.
$check('stats.php opens the DB only once (single fromConfigFile call)',
    substr_count($statsCli, 'fromConfigFile') === 1);

// HTTP: bin/stats.php must be unreachable.
$check('stats.php has CLI gate (HTTP 404)', str_contains($statsCli, "http_response_code(404)"));

/* =============================================================
 * 2. WebP hero asset
 * ============================================================= */
$deployed = $repo . '/assets/images/hero-raccoon.webp';
$master = $repo . '/assets-design/02-hero-raccoon.webp';
$oldJpg = $repo . '/assets/images/hero-raccoon.jpg';

$check('production WebP exists at assets/images/hero-raccoon.webp', is_file($deployed));
$check('master WebP tracked under assets-design/', is_file($master));
$check('old JPEG removed from production assets', !is_file($oldJpg));
$check('deployed WebP and master WebP are identical (no regeneration)',
    is_file($deployed) && is_file($master) && hash_file('sha256', $deployed) === hash_file('sha256', $master));

$homeTpl = (string) file_get_contents($repo . '/templates/home.php');
$check('homepage references /assets/images/hero-raccoon.webp via Assets::url()',
    str_contains($homeTpl, "Assets::url('/assets/images/hero-raccoon.webp')"));
$check('old hero-raccoon.jpg no longer referenced by any template',
    !preg_match('#hero-raccoon\.jpg#', implode('', array_map(
        'file_get_contents', glob($repo . '/templates/*.php')))));

require_once $repo . '/src/Assets.php';
\Versandkostenretter\Assets::setDocRoot($repo);
$url = \Versandkostenretter\Assets::url('/assets/images/hero-raccoon.webp');
preg_match('#\?v=(\d+)$#', $url, $mv);
$check('Assets::url() versions the WebP with filemtime', ($mv[1] ?? '') === (string) filemtime($deployed), $url);

// Replacing the WebP under the same filename changes the URL.
sleep(1);
$oldUrl = $url;
copy($master, $deployed); // same bytes, fresh mtime
touch($deployed, filemtime($deployed) + 10);
clearstatcache(true, $deployed);
$newUrl = \Versandkostenretter\Assets::url('/assets/images/hero-raccoon.webp');
$check('replacing the WebP (same filename) changes the versioned URL', $newUrl !== $oldUrl,
    "{$oldUrl} -> {$newUrl}");

// Old JPEG URL must not be generated anywhere anymore.
$check('old .jpg URL no longer produced', \Versandkostenretter\Assets::url('/assets/images/hero-raccoon.jpg')
    === '/assets/images/hero-raccoon.jpg'); // missing file -> unversioned; and the file must not exist
$check('old .jpg file is gone', !is_file($oldJpg));

// Metadata-based transparency verification of the deployed file.
$bytes = (string) file_get_contents($deployed);
$fmt = substr($bytes, 12, 4);
$check('deployed WebP has an explicit ALPH chunk (transparency)',
    $fmt === 'VP8X' && str_contains($bytes, 'ALPH'));
preg_match('/VP8X.{6}/s', $bytes, $mm);
$flags = ord($bytes[20] ?? "\0");
$check('deployed WebP VP8X alpha bit set', (bool) ($flags & 0x10));
$w = 1 + intfrombytes(substr($bytes, 24, 3));
$h = 1 + intfrombytes(substr($bytes, 27, 3));
$check("deployed WebP canvas = 900x450 (got {$w}x{$h})", $w === 900 && $h === 450);

function intfrombytes(string $b): int
{
    return ord($b[0]) + (ord($b[1]) << 8) + (ord($b[2]) << 16);
}

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

print("\nStats/WebP tests: {$pass} passed, {$fail} failed\n");
exit($fail === 0 ? 0 : 1);
