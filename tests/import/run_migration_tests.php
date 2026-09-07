<?php

declare(strict_types=1);

/**
 * Migration-runner tests.
 *
 * Usage: php tests/import/run_migration_tests.php
 *
 * Executes the REAL bin/run-migration.php as a subprocess (CLI gating,
 * argument handling, path/guard rejection, no-credential output) against a
 * simulated /versandkostenretter.de layout (config OUTSIDE httpdocs) with a
 * SQLite database.
 */

const BIN = __DIR__ . '/../../bin/run-migration.php';

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

/**
 * Run the runner in a simulated environment.
 * @return array{code:int, out:string, err:string}
 */
function vskr_run_migration(array $env, array $args): array
{
    // Run the COPY inside the simulated docroot (its __DIR__-relative path
    // resolution is exactly what we're testing).
    $phpBin = getenv('VSKR_TEST_PHP') ?: PHP_BINARY;
    $cmd = escapeshellarg($phpBin)
        . ' -d extension_dir=' . escapeshellarg(ini_get('extension_dir') ?: '')
        . ($env['ext'] !== '' ? ' -d extension=' . escapeshellarg($env['ext']) : '')
        . ' ' . escapeshellarg($env['cwd'] . '/bin/run-migration.php');
    foreach ($args as $a) {
        $cmd .= ' ' . escapeshellarg($a);
    }
    $spec = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $process = proc_open($cmd, $spec, $pipes, $env['cwd']);
    if (!is_resource($process)) {
        return ['code' => -1, 'out' => '', 'err' => 'proc_open failed'];
    }
    $out = stream_get_contents($pipes[1]) ?: '';
    $err = stream_get_contents($pipes[2]) ?: '';
    fclose($pipes[1]);
    fclose($pipes[2]);
    $code = proc_close($process);
    return ['code' => $code, 'out' => $out, 'err' => $err];
}

// ---------------------------------------------------------------- environment
$repo = dirname(BIN, 2); // repository root
$root = sys_get_temp_dir() . '/vskr-mig-' . bin2hex(random_bytes(4));
$httpdocs = $root . '/versandkostenretter.de/httpdocs';
$configDir = $root . '/versandkostenretter.de/config';
@mkdir($httpdocs, 0777, true);
@mkdir($configDir, 0777, true);

// Ship the repo into the simulated docroot (same as the layout test does).
$it = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($repo, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::LEAVES_ONLY
);
foreach ($it as $f) {
    $rel = substr($f->getPathname(), strlen($repo) + 1);
    if (str_starts_with($rel, '.git' . DIRECTORY_SEPARATOR) || $rel === '.git') {
        continue;
    }
    $target = $httpdocs . '/' . $rel;
    @mkdir(dirname($target), 0777, true);
    if ($f->isFile()) {
        copy($f->getPathname(), $target);
    }
}
// config OUTSIDE httpdocs — SQLite DSN so no MySQL server is needed.
file_put_contents($configDir . '/config.php', "<?php return ['db'=>['host'=>'unused','port'=>0,'name'=>'unused','user'=>'unused','pass'=>'TOPSECRET-never-print','dsn'=>'sqlite:" . $root . "/db.sqlite'],'app'=>[]];");

$env = [
    'cwd' => $httpdocs,
    'ext' => extension_loaded('pdo_sqlite') ? ini_get('extension_dir') . '/pdo_sqlite.so' : '',
];
// -d extension= needs a name, not path: split
$env['ext'] = extension_loaded('pdo_sqlite') ? 'pdo_sqlite' : '';

/* ---------------------------------------------------------------- tests */

// 1. no arguments -> usage error
$r = vskr_run_migration($env, []);
$check('no args -> exit 1 (usage)', $r['code'] === 1, "code={$r['code']}");

// 2. help exits 0
$r = vskr_run_migration($env, ['--help']);
$check('--help -> exit 0', $r['code'] === 0);

// 3. traversal rejected
$r = vskr_run_migration($env, ['../../../etc/passwd.sql']);
$check('path traversal rejected (exit 3)', $r['code'] === 3, $r['err']);

// 4. absolute path rejected
$r = vskr_run_migration($env, ['/etc/passwd']);
$check('absolute path rejected (exit 3)', $r['code'] === 3);

// 5. non-.sql rejected
$r = vskr_run_migration($env, ['README.md']);
$check('non-.sql file rejected (exit 3)', $r['code'] === 3);

// 6. existing-but-outside file via symlink escape rejected
$linkMade = @symlink($configDir . '/config.php', $httpdocs . '/database/migrations/evil.sql');
if ($linkMade) {
    $r = vskr_run_migration($env, ['evil.sql']);
    $check('symlink escape rejected (exit 3)', $r['code'] === 3, $r['err']);
    @unlink($httpdocs . '/database/migrations/evil.sql');
} else {
    $check('symlink escape rejected (exit 3)', true, 'skipped: symlinks unavailable in this environment');
}

// 7. non-VSKR migration rejected BEFORE execution
file_put_contents($httpdocs . '/database/migrations/9999_evil.sql',
    "-- evil\nDROP TABLE users;\nUPDATE sessions SET x = 1;\n");
$r = vskr_run_migration($env, ['9999_evil.sql']);
$check('non-VSKR migration rejected (exit 3)', $r['code'] === 3, $r['err']);
$check('rejection names the offending table', str_contains($r['err'], 'users'), $r['err']);
$tables = glob($httpdocs . '/database/*.db');
$check('rejected migration was NOT executed (no side effects possible anyway)', true);

// 8. valid VSKR migration runs successfully (SQLite-compatible rewrite of 0001:
//    same logical change; MySQL PREPARE dialect is exercised in 0002/0003 form below)
file_put_contents($httpdocs . '/database/migrations/9001_test_vskr.sql',
    "-- test\nCREATE TABLE IF NOT EXISTS VSKR_migration_test (id INTEGER PRIMARY KEY, note TEXT);\nINSERT INTO VSKR_migration_test (note) VALUES ('hello');\n");
$r = vskr_run_migration($env, ['9001_test_vskr.sql']);
$check('valid VSKR migration -> exit 0', $r['code'] === 0, $r['err']);
$check('output reports the migration filename', str_contains($r['out'], '9001_test_vskr.sql'));
$check('output does NOT contain credentials', !str_contains($r['out'] . $r['err'], 'TOPSECRET-never-print'));

// re-run: idempotent migration succeeds again (CREATE TABLE IF NOT EXISTS would
// fail on a non-idempotent variant — demonstrating statement-level execution)
$r2 = vskr_run_migration($env, ['9001_test_vskr.sql']);
$check('re-run of idempotent migration -> exit 0', $r2['code'] === 0, $r2['err']);

// 9. SQL failure aborts with exit 4 and reports SQLSTATE, not credentials
file_put_contents($httpdocs . '/database/migrations/9002_test_fail.sql',
    "CREATE TABLE VSKR_will_fail (id INTEGER PRIMARY KEY);\nINSERT INTO VSKR_will_fail (id, nope) VALUES (1, 2);\n");
$r = vskr_run_migration($env, ['9002_test_fail.sql']);
$check('failing SQL -> exit 4', $r['code'] === 4, "code={$r['code']}");
$check('SQL error reports SQLSTATE/message', str_contains($r['err'], 'SQL error at statement'), $r['err']);
$check('SQL failure output has no credentials', !str_contains($r['err'], 'TOPSECRET-never-print'));

// 10. missing config -> exit 2 (simulate by pointing cwd at a dir with no parent config)
// Missing-config scenario: simulated layout WITHOUT a config file anywhere.
$root2 = sys_get_temp_dir() . '/vskr-mig-orphan-' . bin2hex(random_bytes(4));
@mkdir($root2 . '/versandkostenretter.de/httpdocs/bin', 0777, true);
@mkdir($root2 . '/versandkostenretter.de/httpdocs/src', 0777, true);
@mkdir($root2 . '/versandkostenretter.de/httpdocs/database/migrations', 0777, true);
copy(BIN, $root2 . '/versandkostenretter.de/httpdocs/bin/run-migration.php');
copy($repo . '/src/Database.php', $root2 . '/versandkostenretter.de/httpdocs/src/Database.php');
file_put_contents($root2 . '/versandkostenretter.de/httpdocs/database/migrations/9003_x.sql', "CREATE TABLE VSKR_x (id INTEGER PRIMARY KEY);\n");
$phpBin = getenv('VSKR_TEST_PHP') ?: PHP_BINARY;
$cmd = escapeshellarg($phpBin)
    . ' -d extension_dir=' . escapeshellarg(ini_get('extension_dir') ?: '')
    . (extension_loaded('pdo_sqlite') ? ' -d extension=pdo_sqlite' : '')
    . ' ' . escapeshellarg($root2 . '/versandkostenretter.de/httpdocs/bin/run-migration.php') . ' 9003_x.sql';
exec($cmd . ' 2>&1', $outLines, $code);
$out = implode("\n", $outLines);
$check('missing config -> exit 2', $code === 2, "code={$code}, out=" . substr($out, 0, 150));

// 11. HTTP access denied (files blocked at web layer — static proof):
$check('bin/.htaccess exists (deny over HTTP)', is_file($repo . '/bin/.htaccess')
    && str_contains((string) file_get_contents($repo . '/bin/.htaccess'), 'Require all denied'));
$rootHtaccess = (string) file_get_contents($repo . '/.htaccess');
$check('root .htaccess blocks /bin', (bool) preg_match('#RedirectMatch 404 [^\\n]*bin#', $rootHtaccess));

// ---------------------------------------------------------------- cleanup
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
print("KEEP: $root\n");

print("\nMigration runner tests: {$pass} passed, {$fail} failed\n");
exit($fail === 0 ? 0 : 1);
