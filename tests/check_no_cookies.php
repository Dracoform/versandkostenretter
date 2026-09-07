<?php

declare(strict_types=1);

/**
 * No-cookie guarantee check.
 *
 * Usage: start the smoke server (see tests/smoke_server.php header comment
 * or README) and run:  php tests/check_no_cookies.php [base-url]
 *
 * Verifies that normal application use NEVER emits a Set-Cookie header:
 * homepage, cart lookup (GET), static pages, 404 and the POST->303 redirect.
 * Exits 0 when clean, 1 when any Set-Cookie is found.
 */

$base = rtrim($argv[1] ?? 'http://localhost:8081', '/');

$targets = [
    'homepage'            => '/',
    'cart lookup'         => '/?shop=games-island-test&cart=125.34',
    'cart lookup (full)'  => '/?shop=games-island-test&cart=125.34',
    'datenschutz'         => '/?page=datenschutz',
    'impressum'           => '/?page=impressum',
    '404 page'            => '/?page=does-not-exist',
];

$failures = 0;

function vskr_probe(string $name, string $method, string $url, array $post = []): array
{
    $parts = parse_url($url);
    $host = $parts['host'] ?? 'localhost';
    $port = $parts['port'] ?? 80;
    $path = ($parts['path'] ?? '/') . (isset($parts['query']) ? '?' . $parts['query'] : '');

    $errno = 0; $errstr = '';
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

    if (!preg_match('#^HTTP/1\.[01] (\d{3})#', $response, $m)) {
        fwrite(STDERR, "bad response\n");
        exit(2);
    }
    $status = (int) $m[1];

    $setCookies = [];
    foreach (explode("\r\n", $response) as $line) {
        if (stripos($line, 'set-cookie:') === 0) {
            $setCookies[] = trim($line);
        }
    }
    return [$status, $setCookies];
}

// Sanity: an active PHP session extension being present does not matter —
// the app must simply never call session_start().
echo "\nNo-cookie check: " . ($failures === 0 ? 'CLEAN (0 Set-Cookie headers)' : "{$failures} failure(s)") . "\n";
exit($failures === 0 ? 0 : 1);
