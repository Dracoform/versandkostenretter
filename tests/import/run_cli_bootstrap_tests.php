<?php

declare(strict_types=1);

/**
 * Regression: CLI-Bootstrap lädt RetryingSourceFetcher über den use-Alias.
 *
 * Production-Bug: bin/import-shop.php instanzierte den unqualifizierten
 * Namen `new RetryingSourceFetcher(...)` OHNE use-Alias. Das Skript läuft im
 * globalen Namespace, PHP resolved also global — und der PSR-4-Autoloader
 * (Prefix 'Versandkostenretter\') feuert für globale Namen nicht.
 * => "Error: Class "RetryingSourceFetcher" not found" — exakt ohne Prefix.
 *
 * Der Test führt den ECHTEN CLI-Bootstrap-Symbolpfad aus: er lädt
 * bin/import-shop.php NICHT (der macht HTTP/DB), sondern prüft die
 * Symbol-Resolution exakt so, wie PHP sie im CLI ausführt:
 *  1. use-Alias vorhanden?
 *  2. PHP-Compiler-Resolution: unqualified Name in dem Datei-Kontext
 *     (alias registriert) → FQCN → Autoloader lädt real die Datei.
 *  3. catalogue-snapshot.php: gleiche Prüfung (hat beides schon).
 *
 * Usage: php tests/import/run_cli_bootstrap_tests.php
 */

$repoRoot = dirname(__DIR__, 2);
$checks = 0;
$failed = 0;
$check = function (string $name, bool $ok, string $detail = '') use (&$checks, &$failed): void {
    $checks++;
    if ($ok) {
        echo "PASS  {$name}\n";
    } else {
        $failed++;
        echo "FAIL  {$name}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
    }
};

// Realer Autoloader identisch zu bin/import-shop.php:
spl_autoload_register(static function (string $class): void {
    if (!str_starts_with($class, 'Versandkostenretter\\')) {
        return;
    }
    $file = $repoRoot . '/src/' . str_replace('\\', '/', substr($class, strlen('Versandkostenretter\\'))) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

foreach (['bin/import-shop.php', 'bin/catalogue-snapshot.php'] as $cli) {
    $src = (string) file_get_contents($repoRoot . '/' . $cli);
    $hasUse = (bool) preg_match('/^use\s+Versandkostenretter\\\\Import\\\\RetryingSourceFetcher;/m', $src);
    $hasNew = (bool) preg_match('/new\s+RetryingSourceFetcher\s*\(/', $src);
    $check("{$cli}: use-Alias für RetryingSourceFetcher vorhanden", $hasUse);
    $check("{$cli}: Instanziierung vorhanden (Fix betrifft die richtige Datei)", $hasNew);
}

// PHP-Compiler-Resolution beweisen: in einem Datei-Kontext MIT dem Alias
// resolved der unqualified Name zum FQCN und der Autoloader lädt real.
$tmpFile = sys_get_temp_dir() . '/vskr-cli-resolve-' . bin2hex(random_bytes(3)) . '.php';
file_put_contents($tmpFile, <<<'PHP'
<?php
use Versandkostenretter\Import\RetryingSourceFetcher;
spl_autoload_register(static function (string $class): void {
    if (!str_starts_with($class, 'Versandkostenretter\\')) { return; }
    $f = getenv('VSKR_REPO_ROOT') . '/src/' . str_replace('\\', '/', substr($class, strlen('Versandkostenretter\\'))) . '.php';
    if (is_file($f)) { require $f; }
});
// unqualified new — exakt wie im CLI:
$c = new RetryingSourceFetcher(new Versandkostenretter\Import\HttpClient(1, 1));
echo get_class($c);
PHP);
$env = 'VSKR_REPO_ROOT=' . escapeshellarg($repoRoot);
$php = PHP_BINARY;
exec("$env $php -d error_reporting=E_ALL " . escapeshellarg($tmpFile) . ' 2>&1', $out, $code);
unlink($tmpFile);
$check('echter CLI-Symbolpfad: unqualified new resolved via use-Alias → Klasse geladen',
    $code === 0 && trim(implode("\n", $out)) === 'Versandkostenretter\Import\RetryingSourceFetcher',
    implode(' | ', $out));

// Und der Negative-Beweis (Production-Zustand ohne use-Alias reproduziert die
// exakte Fehlermeldung):
$tmpFile2 = sys_get_temp_dir() . '/vskr-cli-resolve-' . bin2hex(random_bytes(3)) . '.php';
file_put_contents($tmpFile2, <<<'PHP'
<?php
// KEIN use-Alias — wie bin/import-shop.php vor dem Fix:
spl_autoload_register(static function (string $class): void {
    if (!str_starts_with($class, 'Versandkostenretter\\')) { return; }
    $f = getenv('VSKR_REPO_ROOT') . '/src/' . str_replace('\\', '/', substr($class, strlen('Versandkostenretter\\'))) . '.php';
    if (is_file($f)) { require $f; }
});
try {
    new RetryingSourceFetcher(null);
    echo 'geladen (unerwartet)';
} catch (Throwable $e) {
    echo get_class($e) . ': ' . $e->getMessage();
}
PHP);
exec("$env $php " . escapeshellarg($tmpFile2) . ' 2>&1', $out2, $code2);
unlink($tmpFile2);
$check('Negative-Beweis: ohne use-Alias exakte Production-Fehlermeldung',
    str_contains(implode("\n", $out2), 'Class "RetryingSourceFetcher" not found'),
    implode(' | ', $out2));

echo "\nCLI-Bootstrap tests: {$checks} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
