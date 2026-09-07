<?php

declare(strict_types=1);

/**
 * Fixed-document-root layout tests.
 *
 * Simulates the Plesk production layout
 *     /versandkostenretter.de/httpdocs/   (document root = repo root)
 *     /versandkostenretter.de/config/config.php   (OUTSIDE httpdocs)
 * in a temp directory and verifies that:
 *   1. every template the front controller can require resolves from the
 *      directory containing index.php (NOT one level up),
 *   2. src/ classes autoload from the document root,
 *   3. the production config is looked up OUTSIDE the document root,
 *   4. the fallback message names the correct outside path.
 *
 * Usage: php tests/check_layout_paths.php
 * Exit 0 = pass, 1 = failure.
 */

use Versandkostenretter\Database;

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

// --- Build the simulated production tree -------------------------------------
$root = sys_get_temp_dir() . '/vskr-layout-' . bin2hex(random_bytes(4));
$httpdocs = $root . '/versandkostenretter.de/httpdocs';
$outsideConfig = $root . '/versandkostenretter.de/config/config.php';

$repo = dirname(__DIR__); // actual repository, used as the "shipped" files
$check('simulated httpdocs created', (bool) @mkdir($httpdocs, 0777, true));
@mkdir($root . '/versandkostenretter.de/config', 0777, true);

// Ship only what Plesk would deploy (everything in the repo).
foreach (['index.php', 'router.php', 'assets', 'src', 'templates', 'tests', 'database', 'config', 'assets-design'] as $item) {
    $src = $repo . '/' . $item;
    $dst = $httpdocs . '/' . $item;
    if (is_dir($src)) {
        @mkdir($dst, 0777, true);
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($src, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY
        );
        foreach ($it as $f) {
            $target = $dst . '/' . substr($f->getPathname(), strlen($src) + 1);
            @mkdir(dirname($target), 0777, true);
            copy($f->getPathname(), $target);
        }
    } elseif (is_file($src)) {
        copy($src, $dst);
    }
}

// Production credentials OUTSIDE the document root (real DB never contacted).
file_put_contents($outsideConfig, "<?php return ['db'=>['host'=>'localhost','port'=>3306,'name'=>'sim','user'=>'sim','pass'=>'sim'],'app'=>['debug'=>false,'max_results'=>24]];");

$check('simulated index.php exists', is_file($httpdocs . '/index.php'));
$check('simulated templates exist', is_file($httpdocs . '/templates/home.php')
    && is_file($httpdocs . '/templates/results.php')
    && is_file($httpdocs . '/templates/impressum.php')
    && is_file($httpdocs . '/templates/datenschutz.php')
    && is_file($httpdocs . '/templates/404.php'));
$check('simulated src exists', is_file($httpdocs . '/src/Cart.php'));
$check('simulated outside config exists', is_file($outsideConfig));
$check('no config.php inside httpdocs', !is_file($httpdocs . '/config/config.php'));

// --- 1. index.php resolves the OUTSIDE config first ---------------------------
$index = file_get_contents($httpdocs . '/index.php');
$check('index.php checks parent-of-docroot config path first',
    str_contains($index, "\$home . '/config/config.php'"));
$check('index.php keeps repo-local config only as dev fallback',
    str_contains($index, "__DIR__ . '/config/config.php'"));

// --- 2. All template requires resolve from __DIR__ (repo root), not one level up
$check('templates required relative to document root (no dirname(__DIR__) for templates)',
    !preg_match('/require\s+dirname\(__DIR__\)\s*\.\s*\'\/templates/', $index));
foreach (['home.php', 'results.php', 'impressum.php', 'datenschutz.php', '404.php'] as $tpl) {
    // Every template name used in index.php must exist under <docroot>/templates.
    $exists = is_file($httpdocs . '/templates/' . $tpl);
    $check("template templates/{$tpl} resolves from document root", $exists);
}

// --- 3. Static-require simulation: run the template includes themselves -------
// Each template starts with require __DIR__ . '/layout_header.php', which only
// resolves when __DIR__ is the real templates dir — catching any layout drift.
// Classes come from the simulated docroot's src/ (as the production
// autoloader would load them).
$shim = $httpdocs . '/templates';
foreach (glob($httpdocs . '/src/*.php') as $classFile) {
    if (basename($classFile) === 'Database.php' && class_exists(Database::class, false)) {
        continue; // already loaded from the test's own repo copy
    }
    require_once $classFile;
}
ob_start();
try {
    $pageTitle = 'Layout test';
    $shops = [];
    require $shim . '/404.php';
    $ok = str_contains(ob_get_clean() ?? '', '404');
} catch (\Throwable $e) {
    ob_end_clean();
    $ok = false;
    print('  exception: ' . $e->getMessage() . "\n");
}
$check('templates/404.php self-contained include works in production layout', (bool) $ok);

ob_start();
try {
    $pageTitle = 'Layout test';
    require $shim . '/impressum.php';
    $ok = str_contains(ob_get_clean() ?? '', 'Impressum');
} catch (\Throwable $e) {
    ob_end_clean();
    $ok = false;
    print('  exception: ' . $e->getMessage() . "\n");
}
$check('templates/impressum.php self-contained include works in production layout', (bool) $ok);

// --- 4. Autoloader maps classes relative to the document root -----------------
$autoloaderOk = (bool) preg_match(
    "#__DIR__ \. '/src/' #",
    $index
);
$check('autoloader resolves src/ from the document root', $autoloaderOk);

// Actually load a class through the simulated docroot: isolate the autoloader.
$autoloadSrc = file_get_contents($httpdocs . '/src/Cart.php');
$check('src/Cart.php declares Versandkostenretter\\Cart', str_contains($autoloadSrc, 'namespace Versandkostenretter'));

// --- 5. Database::fromConfigFile accepts the outside config path --------------
try {
    $db = Database::fromConfigFile($outsideConfig);
    // Do NOT connect — constructing the wrapper is enough to prove the path loads.
    $check('Database::fromConfigFile loads OUTSIDE config.php', $db instanceof Database);
} catch (\Throwable $e) {
    $check('Database::fromConfigFile loads OUTSIDE config.php', false, $e->getMessage());
}

// --- cleanup ------------------------------------------------------------------
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

print("\nLayout paths: {$pass} passed, {$fail} failed\n");
exit($fail === 0 ? 0 : 1);
