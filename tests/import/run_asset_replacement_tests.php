<?php

declare(strict_types=1);

/**
 * Deployment-asset replacement test (homepage raccoon image).
 *
 * Usage: php tests/import/run_asset_replacement_tests.php
 *
 * Simulates the real deployment chain:
 *   Git commit (same filename)  ->  Plesk pull into httpdocs
 *   -> Assets::url() mtime cache busting -> clients fetch the new contents.
 *
 * Proves:
 *  - the deployed asset file is TRACKED in Git (a production-local replacement
 *    would be wiped by the next Plesk deployment — committing is mandatory)
 *  - the filename is stable (/assets/images/hero-raccoon.jpg)
 *  - the template references it through Assets::url() (mtime version)
 *  - replacing the tracked file under the SAME filename changes the served
 *    versioned URL without renaming files or disabling caching
 *  - the asset is web-accessible in the deployed tree (not denied)
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
$assetRel = '/assets/images/hero-raccoon.jpg';
$assetAbs = $repo . $assetRel;

/* =============================================================
 * 1. Repository state: tracked, stable filename, template wiring
 * ============================================================= */
$check('asset file exists in the repository', is_file($assetAbs));
$check('asset is a JPEG (image/jpeg)', str_contains(
    (string) shell_exec('file -b --mime-type ' . escapeshellarg($assetAbs) . ' 2>/dev/null'),
    'image/jpeg'));

$tracked = trim((string) shell_exec(
    'cd ' . escapeshellarg($repo) . ' && git ls-files ' . escapeshellarg(ltrim($assetRel, '/'))
));
$check('asset is TRACKED in Git (deploys via Plesk)', $tracked === ltrim($assetRel, '/'), "git ls-files: '{$tracked}'");

$ignored = trim((string) shell_exec(
    'cd ' . escapeshellarg($repo) . ' && git check-ignore ' . escapeshellarg(ltrim($assetRel, '/')) . '; echo $?'
));
$check('asset is NOT gitignored', $ignored === '1', "check-ignore exit={$ignored}");

$homeTpl = (string) file_get_contents($repo . '/templates/home.php');
$check('template references the stable filename via Assets::url()',
    str_contains($homeTpl, "Assets::url('/assets/images/hero-raccoon.jpg')"));
$check('template does NOT hardcode an unversioned image URL',
    !str_contains($homeTpl, 'src="/assets/images/hero-raccoon.jpg"'));

// Root .htaccess must not deny the assets directory.
$htaccess = (string) file_get_contents($repo . '/.htaccess');
$check('.htaccess does not deny /assets',
    !preg_match('#RedirectMatch 404[^\n]*\|assets\)#i', str_replace('|assets-design', '', $htaccess)));

/* =============================================================
 * 2. Replacement simulation in a deployed tree
 * ============================================================= */
require_once $repo . '/src/Assets.php';

$root = sys_get_temp_dir() . '/vskr-assetrep-' . bin2hex(random_bytes(4));
$httpdocs = $root . '/httpdocs';
@mkdir($httpdocs . '/assets/images', 0777, true);
// "Deploy" the current tracked asset.
copy($assetAbs, $httpdocs . $assetRel);
\Versandkostenretter\Assets::setDocRoot($httpdocs);

$urlBefore = \Versandkostenretter\Assets::url($assetRel);
$check('deployed asset URL is versioned before replacement',
    (bool) preg_match('#\?v=\d+$#', $urlBefore), $urlBefore);

// Operator commits a replacement (same filename); Plesk deploys the new bytes.
sleep(1); // mtime resolution
$replacement = $root . '/replacement.jpg';
file_put_contents($replacement, 'REPLACED-IMAGE-BYTES-' . random_bytes(8));
copy($replacement, $httpdocs . $assetRel);
clearstatcache(true, $httpdocs . $assetRel);

$urlAfter = \Versandkostenretter\Assets::url($assetRel);
$check('same-filename replacement produces a NEW versioned URL', $urlAfter !== $urlBefore,
    "{$urlBefore} -> {$urlAfter}");
$check('filename unchanged after replacement', pathinfo(parse_url($urlAfter, PHP_URL_PATH), PATHINFO_BASENAME) === 'hero-raccoon.jpg');
$check('replacement is a different file (bytes changed)',
    hash('sha256', $httpdocs . $assetRel) !== hash('sha256', $assetAbs) || filesize($httpdocs . $assetRel) !== filesize($assetAbs));

// Old URL must no longer be generated (clients fetch the new one).
$check('old versioned URL no longer generated', !str_contains(\Versandkostenretter\Assets::url($assetRel), 'v=' . ($urlBefore ? substr($urlBefore, (int) strrpos($urlBefore, 'v=') + 2) : '')));

/* =============================================================
 * 3. Web-serving: the deployed asset is reachable (not denied)
 * ============================================================= */
// Serve the simulated docroot and fetch the asset (also proves /assets/ is not
// caught by the RedirectMatch deny list).
$serverCmd = '/home/hermy/.local/php83/usr/bin/php8.3'
    . ' -d extension_dir=' . ini_get('extension_dir')
    . ' -S localhost:8085 ' . escapeshellarg($repo . '/router.php');
// Serve from a DEPLOYED COPY (repo root layout): router + docroot are the same tree.
$proc = proc_open($serverCmd, [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
usleep(700000);
$fp = stream_socket_client('tcp://localhost:8085', $en, $es, 5);
if ($fp !== false) {
    fwrite($fp, "GET {$assetRel} HTTP/1.1\r\nHost: localhost\r\nConnection: close\r\n\r\n");
    $raw = '';
    while (!feof($fp)) $raw .= fread($fp, 32768);
    fclose($fp);
    preg_match('#^HTTP/1\.[01] (\d{3})#m', $raw, $m);
    $check('asset is web-accessible in the deployed tree (200)', (int) ($m[1] ?? 0) === 200, 'status=' . ($m[1] ?? '?'));
} else {
    $check('asset is web-accessible in the deployed tree (200)', false, 'server did not start');
}
if (is_resource($proc)) { proc_terminate($proc); proc_close($proc); }

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

print("\nAsset replacement tests: {$pass} passed, {$fail} failed\n");
exit($fail === 0 ? 0 : 1);
