<?php

declare(strict_types=1);

/**
 * Regression: 429-Retry-After-Verkabelung über den ECHTEN HttpClient.
 *
 * Production-Bug: HttpClient::get() rief $this->retryAfterOf() auf, die
 * Methode war beim Umschreiben der internen Retry-Schleife verlorengegangen
 * (nur der verwaiste Docblock blieb). Jede 429/5xx-Antwort führte dadurch zu
 * "Call to undefined method ... retryAfterOf()" → Adapter-Fail-Closed →
 * Membership sync SKIPPED.
 *
 * Diese Suite startet einen ECHTEN lokalen HTTP-Server (php -S), der 429 mit
 * bzw. ohne Retry-After und 503/404-Antworten skriptet, und läuft dann den
 * realen Pfad: HttpClient → SourceException::http(status, retryAfter) →
 * RetryingSourceFetcher.
 *
 * Usage: /usr/bin/php8.3 tests/import/run_retry_http_endtoend_tests.php
 * (keine DB, kein externer Traffic)
 */

$repoRoot = dirname(__DIR__, 2);
require $repoRoot . '/src/Import/SourceException.php';
require $repoRoot . '/src/Import/SourceFetcher.php';
require $repoRoot . '/src/Import/HttpClient.php';
require $repoRoot . '/src/Import/RetryingSourceFetcher.php';

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

// --- lokaler skriptbarer HTTP-Server ----------------------------------------
$script = sys_get_temp_dir() . '/vskr-retry-server-' . bin2hex(random_bytes(3)) . '.php';
file_put_contents($script, <<<'PHP'
<?php
// ?mode=429with|429without|503x2then200|503always|404|ok — serverseitiger
// Aufrufzähler pro mode (State-Datei), damit ein Retry dieselbe URL erneut
// abrufen kann und trotzdem den NÄCHsten Plan-Schritt sieht.
$mode = $_GET['mode'] ?? 'ok';
$stateFile = sys_get_temp_dir() . '/vskr-retry-state-' . $mode;
$call = (int) (@file_get_contents($stateFile) ?: 0);
@file_put_contents($stateFile, (string) ($call + 1));
header('Content-Type: application/json');
switch ($mode) {
    case '429with':
        if ($call === 0) { header('Retry-After: 1'); http_response_code(429); echo 'rate limited'; }
        else { echo '{"ok":true}'; }
        return;
    case '429without':
        if ($call === 0) { http_response_code(429); echo 'rate limited'; }
        else { echo '{"ok":true}'; }
        return;
    case '503x2then200':
        if ($call < 2) { http_response_code(503); echo 'boom'; }
        else { echo '{"ok":true}'; }
        return;
    case '503always':
        http_response_code(503); echo 'boom';
        return;
    case '404':
        http_response_code(404); echo 'nope';
        return;
    default:
        echo '{"ok":true}';
}
PHP);
$port = 18080 + random_int(1, 800);
$docroot = sys_get_temp_dir();
$proc = proc_open(
    PHP_BINARY . " -S 127.0.0.1:{$port} -t " . escapeshellarg($docroot) . ' ' . escapeshellarg($script),
    [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
    $pipes
);
if (!is_resource($proc)) {
    echo "SKIP  lokaler HTTP-Server konnte nicht gestartet werden\n";
    exit(0);
}
usleep(300_000);
register_shutdown_function(static function () use ($proc, $script): void {
    if (is_resource($proc)) {
        proc_terminate($proc);
    }
    @unlink($script);
    foreach (['429with', '429without', '503x2then200', '503always', '404'] as $m) {
        @unlink(sys_get_temp_dir() . '/vskr-retry-state-' . $m);
    }
});

$base = "http://127.0.0.1:{$port}/?mode=%s";
$urlFor = static fn (string $mode): string => sprintf($base, $mode);
foreach (['429with', '429without', '503x2then200', '503always', '404'] as $m) {
    @unlink(sys_get_temp_dir() . '/vskr-retry-state-' . $m);
}

// Sleeps stubben (kein echtes Warten im Test):
$GLOBALS['sleeps'] = [];
$sleepStub = static fn (int $s): bool => ($GLOBALS['sleeps'][] = $s) !== null;

$mkFetcher = static fn (): \Versandkostenretter\Import\RetryingSourceFetcher =>
    new \Versandkostenretter\Import\RetryingSourceFetcher(new \Versandkostenretter\Import\HttpClient(2, 3), 3, $sleepStub);

// --- 1. 429 MIT Retry-After: echter Pfad bis zum Erfolg ----------------------
$GLOBALS['sleeps'] = [];
$r1 = $mkFetcher()->get($urlFor('429with'));
$check('429 mit Retry-After -> Retry -> HTTP 200 (echter HttpClient-Pfad)',
    $r1['code'] === 200 && str_contains($r1['body'], '"ok"'));
$check('Retry-After=1s aus Header wurde an RetryingSourceFetcher übergeben',
    $GLOBALS['sleeps'] === [1], json_encode($GLOBALS['sleeps']));

// --- 2. 429 OHNE Retry-After: Fallback auf Backoff ---------------------------
$GLOBALS['sleeps'] = [];
$r2 = $mkFetcher()->get($urlFor('429without'));
$check('429 ohne Retry-After -> Retry -> HTTP 200', $r2['code'] === 200);
$check('Fallback-Backoff 1s (exponentiell)', $GLOBALS['sleeps'] === [1], json_encode($GLOBALS['sleeps']));

// --- 3. 503 x2 -> Erfolg (5xx-Retry unverändert) ------------------------------
$GLOBALS['sleeps'] = [];
$r3 = $mkFetcher()->get($urlFor('503x2then200'));
$check('503 x2 -> Retry x2 -> HTTP 200', $r3['code'] === 200);
$check('5xx-Backoff 1s, 2s', $GLOBALS['sleeps'] === [1, 2], json_encode($GLOBALS['sleeps']));

// --- 4. 503 immer -> Retries erschöpft -> SourceException mit Status ----------
$GLOBALS['sleeps'] = [];
$thrown = null;
try {
    $mkFetcher()->get($urlFor('503always'));
} catch (\Versandkostenretter\Import\SourceException $e) {
    $thrown = $e;
}
$check('503 immer -> nach 3 Versuchen SourceException (kein undefined method)',
    $thrown !== null && $thrown->httpStatus() === 503,
    $thrown ? $thrown->getMessage() : 'keine Exception');
$check('keine undefined-method-Fehler mehr',
    $thrown === null || !str_contains($thrown->getMessage(), 'undefined method'));

// --- 5. permanente 404: kein Retry --------------------------------------------
$GLOBALS['sleeps'] = [];
$thrown4 = null;
try {
    $mkFetcher()->get($urlFor('404'));
} catch (\Versandkostenretter\Import\SourceException $e) {
    $thrown4 = $e;
}
$check('404 -> sofortige SourceException OHNE Retry',
    $thrown4 !== null && $thrown4->httpStatus() === 404 && $GLOBALS['sleeps'] === [],
    json_encode([$GLOBALS['sleeps'], $thrown4?->getMessage()]));

// --- 6. 429-Exception trägt Retry-After maschinenlesbar (SourceException) -----
$probe = \Versandkostenretter\Import\SourceException::http(429, 7);
$check('SourceException::http transportiert Status + Retry-After',
    $probe->httpStatus() === 429 && $probe->retryAfter() === 7);

echo "\nRetry-HTTP-Endtoend tests: {$checks} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
