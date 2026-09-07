<?php

declare(strict_types=1);

/**
 * HTTP exposure tests for the fixed-document-root deployment layout.
 *
 * Verifies over real HTTP that:
 *   1. non-public directories (src/, templates/, tests/, database/, config/,
 *      assets-design/) CANNOT be retrieved (404/403)
 *   2. sensitive file types (.sql, .md, config example) cannot be retrieved
 *   3. public assets and pages still work
 *   4. normal requests emit no Set-Cookie
 *
 * Usage (from repo root, with the smoke server running on :8081):
 *   php tests/check_http_exposure.php [base-url]
 * Exit 0 = all safe, 1 = exposure found, 2 = server unreachable.
 */

$base = rtrim($argv[1] ?? 'http://localhost:8081', '/');

function vskr_http(string $url, string $method = 'GET', array $post = []): array
{
    $parts = parse_url($url);
    $host = $parts['host'] ?? 'localhost';
    $port = $parts['port'] ?? 80;
    $path = ($parts['path'] ?? '/') . (isset($parts['query']) ? '?' . $parts['query'] : '');

    $fp = stream_socket_client("tcp://{$host}:{$port}", $errno, $errstr, 5);
    if ($fp === false) {
        fwrite(STDERR, "connect failed: {$errstr}({$errno})\n");
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
    $response = '';
    while (!feof($fp)) {
        $response .= fread($fp, 8192);
    }
    fclose($fp);

    preg_match('#^HTTP/1\.[01] (\d{3})#', $response, $m);
    $status = (int) ($m[1] ?? 0);
    [$headers, $body] = explode("\r\n\r\n", $response, 2) + [1 => '', 2 => ''];
    $setCookie = stripos($headers, 'set-cookie:') !== false;
    return [$status, $body, $setCookie];
}

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

// 1. Non-public directories must NOT be retrievable.
foreach (['src/Money.php', 'templates/home.php', 'tests/run.php', 'tests/smoke_server.php',
          'bin/import-shop.php', 'bin/.htaccess', 'src/Import/ShopifyImporter.php',
          'database/schema.sql', 'database/seed-development.sql',
          'database/migrations/0001_shops_affiliate_columns.sql',
          'config/config.example.php', 'assets-design/01-design-reference.png'] as $sensitive) {
    [$status, , ] = vskr_http($base . '/' . $sensitive);
    $check("deny {$sensitive}", $status === 404 || $status === 403, "got {$status}");
}

// Directory listings must not work either.
foreach (['src', 'templates', 'tests', 'database', 'config', 'assets-design', 'bin'] as $dir) {
    [$status, , ] = vskr_http($base . '/' . $dir);
    $check("deny dir /{$dir}", $status === 404 || $status === 403, "got {$status}");
}

// Sensitive file types anywhere.
[$status, , ] = vskr_http($base . '/README.md');
$check('deny README.md', $status === 404 || $status === 403, "got {$status}");
[$status, , ] = vskr_http($base . '/router.php');
$check('deny router.php (dev only)', $status === 404 || $status === 403, "got {$status}");

// 2. Public surface must keep working.
[$status, $body, ] = vskr_http($base . '/');
$check('homepage reachable', $status === 200 && str_contains($body, 'Show me the goods!'));
[$status, $body, ] = vskr_http($base . '/assets/css/main.css');
$check('CSS served', $status === 200 && str_contains($body, 'Versandkostenretter'));
[$status, , ] = vskr_http($base . '/assets/images/hero-raccoon.jpg');
$check('hero image served', $status === 200);
[$status, $body, ] = vskr_http($base . '/?shop=games-island-test&cart=125.34');
$check('cart lookup works', $status === 200 && str_contains($body, 'Basisspiel'));

// 3. No cookies on any normal request.
foreach (['/' , '/?shop=games-island-test&cart=125.34', '/?page=datenschutz', '/?page=impressum'] as $path) {
    [, , $cookie] = vskr_http($base . $path);
    $check("no Set-Cookie on {$path}", $cookie === false);
}

print("\nHTTP exposure: {$pass} passed, {$fail} failed\n");
exit($fail === 0 ? 0 : 1);
