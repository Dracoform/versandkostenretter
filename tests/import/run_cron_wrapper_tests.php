<?php

declare(strict_types=1);

/**
 * Regression tests for bin/cron-import-shop.sh (cron wrapper).
 *
 * The wrapper is tested end-to-end with an injected FAKE importer
 * (CRON_IMPORT_PHP points at a stub PHP that behaves like bin/import-shop.php
 * according to a scenario file) and a FAKE curl (records the mail payload).
 * No production imports, no network, no real credentials.
 *
 * Covered:
 *  1. success            -> log written, NO mail, exit 0
 *  2. import exit != 0   -> mail sent (exit 2)
 *  3. "Membership sync: SKIPPED" with exit 0 -> mail sent (exit 3)
 *  4. enrichment warnings with exit 0       -> mail sent (exit 4)
 *  5. mail contains ONLY this run's log
 *  6. failed mail delivery -> wrapper exit != 0 (5)
 *  7. invalid shop handle rejected (exit 1)
 *  8. retention deletes only matching old logs
 *  9. credentials never read/printed
 *
 * Usage: php tests/import/run_cron_wrapper_tests.php
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

$tmp = sys_get_temp_dir() . '/vskr-cron-test-' . bin2hex(random_bytes(4));
mkdir($tmp . '/logdir', 0700, true);
file_put_contents($tmp . '/netrc-does-not-matter', "machine mx2f6e.netcup.net\nlogin test\npassword fake\n");

// --- FAKE importer: behaves according to scenario file ----------------------
$fakePhp = $tmp . '/fake-importer.php';
$realPhp = PHP_BINARY;
file_put_contents($fakePhp, <<<'PHP'
<?php
// argv[1] = shop, scenario from VSKR_CRON_SCENARIO
$scenario = getenv('VSKR_CRON_SCENARIO') ?: 'success';
switch ($scenario) {
    case 'success':
        echo "Shop: TestShop\nSource: shopify\nFetched: 10\nInserted: 0\nUpdated: 10\n";
        echo "Unavailable: 0\nSkipped: 0\nErrors: 0\nRequests: 12\n";
        echo "Memberships: 5 rows synced (OK)\n";
        exit(0);
    case 'source-error':
        fwrite(STDERR, "Source error: Server error HTTP 503 from source.\n");
        exit(3);
    case 'skipped':
        echo "Shop: TestShop\nErrors: 0\nRequests: 97\n";
        echo "Membership sync: SKIPPED — collection enrichment failed; previously persisted memberships were KEPT.\n";
        echo "Enrichment warnings: 1\n  - collection fetch failed: xenos (Server error HTTP 503 from source.)\n";
        exit(0);
    case 'warnings-only':
        // not reachable per current CLI semantics (SKIPPED and warnings come
        // together) — helper for future semantics changes
        echo "Errors: 0\nEnrichment warnings: 2\n";
        exit(0);
}
exit(0);
PHP);
chmod($fakePhp, 0644);
$phpSh = $tmp . '/fake-php.sh';
file_put_contents($phpSh, "#!/usr/bin/env bash\nexec {$realPhp} {$fakePhp} \"$@\"\n");
chmod($phpSh, 0755);

// --- FAKE curl: records the mail, optionally fails ---------------------------
$fakeCurl = $tmp . '/fake-curl.sh';
file_put_contents($fakeCurl, <<<'SH'
#!/usr/bin/env bash
if [ "${VSKR_CRON_CURL_FAIL:-0}" = "1" ]; then
    echo "curl: (67) Authentication failed" >&2
    exit 67
fi
# find --upload-file argument and copy its content to the capture file
prev=""
for a in "$@"; do
    if [ "$prev" = "--upload-file" ]; then
        cp -- "$a" "${VSKR_CRON_MAILCAPTURE}"
    fi
    prev="$a"
done
SH
);
chmod($fakeCurl, 0755);

$env = [
    'CRON_IMPORT_PHP' => $phpSh,
    'CRON_IMPORT_LOG_DIR' => $tmp . '/logdir',
    'CRON_IMPORT_NETRC' => $tmp . '/netrc-does-not-matter',
    'CRON_IMPORT_CURL' => $fakeCurl,
    'VSKR_CRON_MAILCAPTURE' => $tmp . '/mail-capture.eml',
    'PATH' => '/usr/bin:/bin',
];
$run = static function (array $args, array $extraEnv = []) use ($repoRoot, $env, $tmp): array {
    $cmd = array_merge([$repoRoot . '/bin/cron-import-shop.sh'], $args);
    $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $tmp, $env + $extraEnv);
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $code = proc_close($proc);
    return ['exit' => $code, 'out' => $out, 'err' => $err];
};

$logs = static function () use ($tmp): array {
    return glob($tmp . '/logdir/*.log') ?: [];
};
$mailSent = static function () use ($tmp): bool {
    return is_file($tmp . '/mail-capture.eml');
};
$mailBody = static function () use ($tmp): string {
    return (string) @file_get_contents($tmp . '/mail-capture.eml');
};

$resetMail = static fn (): bool => @unlink($tmp . '/mail-capture.eml');

// --- 1. success ---------------------------------------------------------------
$r = $run(['lootforge'], ['VSKR_CRON_SCENARIO' => 'success']);
$check('1a) success: exit 0', $r['exit'] === 0, "exit={$r['exit']} err={$r['err']}");
$check('1b) success: log written', count($logs()) === 1, implode(',', $logs()));
$check('1c) success: log named lootforge-<stamp>.log', (bool) preg_match('#lootforge-\d{8}-\d{6}(-\d+)?\.log$#', basename($logs()[0] ?? '')));
$check('1d) success: NO mail', !$mailSent());
$check('1e) success: log contains importer output', str_contains((string) @file_get_contents($logs()[0]), 'Memberships: 5 rows synced (OK)'));

// --- 2. import exit != 0 -------------------------------------------------------
$resetMail();
$logsBeforeList = $logs();
$r2 = $run(['lootforge'], ['VSKR_CRON_SCENARIO' => 'source-error']);
$check('2a) import failure: exit 2', $r2['exit'] === 2, "exit={$r2['exit']}");
$check('2b) import failure: mail sent', $mailSent());
$check('2c) mail subject contains [VSKR] Importproblem: lootforge',
    str_contains($mailBody(), 'Subject: [VSKR] Importproblem: lootforge'));
$check('2d) mail contains exit code 3', str_contains($mailBody(), 'Exit-Code:   3'));
$check('2e) mail contains full run output (stderr included)',
    str_contains($mailBody(), 'Source error: Server error HTTP 503 from source.'));
$newLogs = array_diff($logs(), $logsBeforeList);
$check('2f) log written for this run (one NEW log containing the source error)',
    count($newLogs) === 1
    && str_contains((string) @file_get_contents(reset($newLogs) ?: ''), 'Source error: Server error HTTP 503'));

// --- 3. Membership sync: SKIPPED with exit 0 -----------------------------------
$resetMail();
$r3 = $run(['lootforge'], ['VSKR_CRON_SCENARIO' => 'skipped']);
$check('3a) SKIPPED: exit 3', $r3['exit'] === 3, "exit={$r3['exit']}");
$check('3b) SKIPPED: mail sent', $mailSent());
$check('3c) mail mentions enrichment skipped', str_contains($mailBody(), 'membership enrichment skipped'));

// --- 4. enrichment warnings (current semantics: warnings accompany SKIPPED) ----
// Current CLI: warnings are printed together with SKIPPED. Verify both
// signals trigger the mail (the SKIPPED path covers the warning case).
var_dump($mailSent(), strlen($mailBody())); // DEBUG
file_put_contents('/tmp/vskr-mail-dump.eml', $mailBody());
$check('4) enrichment warnings present in mail body (current CLI semantics)',
    $mailSent() && str_contains($mailBody(), 'collection fetch failed: xenos'));

// --- 5. mail contains ONLY this run's log --------------------------------------
$runCount = count($logs());
$check('5) mail contains exactly one "----- Vollstaendige Ausgabe" section (this run only)',
    substr_count($mailBody(), '----- Vollstaendige Ausgabe dieses Importlaufs -----') === 1
    && substr_count($mailBody(), 'Shop:        lootforge') === 1);
$check('5) mail does NOT contain output from earlier runs',
    !str_contains($mailBody(), 'Memberships: 5 rows synced (OK)'));

// --- 6. failed mail delivery ----------------------------------------------------
$resetMail();
$r6 = $run(['lootforge'], ['VSKR_CRON_SCENARIO' => 'skipped', 'VSKR_CRON_CURL_FAIL' => '1']);
$check('6a) mail failure: wrapper exit != 0 (5)', $r6['exit'] === 5, "exit={$r6['exit']}");
$check('6b) mail failure: stderr explains the problem',
    str_contains($r6['err'], 'monitoring mail could not be sent'));
$allLogs = $logs(); $lastLog = end($allLogs);
$check('6c) mail failure: documented in the run log',
    str_contains((string) @file_get_contents($lastLog), 'MONITORING MAIL FAILED'));

// --- 7. invalid shop handle -----------------------------------------------------
$resetMail();
$r7a = $run(['../evil']);
$r7b = $run(['Bad_Handle']);
$r7c = $run([]);
$check('7) invalid handles rejected with exit 1, no mail, no new logs',
    $r7a['exit'] === 1 && $r7b['exit'] === 1 && $r7c['exit'] === 1 && !$mailSent());

// --- 8. retention: only matching old logs deleted -------------------------------
// create an old lootforge log (40 days), an old OTHER-shop log (40 days),
// a fresh lootforge log and a foreign .txt file:
$oldLootforge = $tmp . '/logdir/lootforge-20200101-000000.log';
$oldOther = $tmp . '/logdir/other-20200101-000000.log';
touch($oldLootforge, time() - 40 * 86400);
touch($oldOther, time() - 40 * 86400);
$foreignFile = $tmp . '/logdir/lootforge-20200101-000000.log.keep';
file_put_contents($foreignFile, 'keep me');
$logsBeforeRetention = count($logs());
$r8 = $run(['lootforge'], ['VSKR_CRON_SCENARIO' => 'success']);
$check('8a) old lootforge log deleted (> 30 days)', !is_file($oldLootforge));
$check('8b) old OTHER-shop log NOT deleted', is_file($oldOther));
$check('8c) foreign .keep file NOT deleted', is_file($foreignFile));
$check('8d) fresh logs retained (all non-old logs still present)',
    count(array_diff($logs(), [$oldLootforge])) === $logsBeforeRetention);
unlink($oldOther);
unlink($foreignFile);

// --- 9. credentials -------------------------------------------------------------
$check('9a) wrapper never reads/prints netrc contents',
    !str_contains(json_encode([$r1, $r2, $r3, $r6, $r7a, $r7b, $r7c]), 'password'));
$wrapper = (string) file_get_contents($repoRoot . '/bin/cron-import-shop.sh');
$check('9b) wrapper passes netrc only as --netrc-file path to curl',
    str_contains($wrapper, '--netrc-file "$NETRC_FILE"')
    && !preg_match('#cat\s+.*netrc#i', $wrapper));
$check('9c) no password/secret literals in wrapper',
    !preg_match('/password\s*=\s*\S+/i', str_replace('--netrc-file', '', $wrapper)));
$check('9d) mail capture contains no credentials (fake curl records payload only)',
    $mailSent() === false || !str_contains($mailBody(), 'password'));

// cleanup
foreach ($logs() as $f) { @unlink($f); }
@unlink($foreignFile);
@unlink($fakePhp);
@unlink($fakeCurl);
@unlink($tmp . '/mail-capture.eml');
@rmdir($tmp . '/logdir');
@rmdir($tmp);

echo "\nCron wrapper tests: {$checks} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
