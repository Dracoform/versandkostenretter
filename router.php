<?php

/**
 * Router for PHP's built-in development server ONLY.
 * Production (Plesk/Apache) uses the root .htaccess instead.
 *
 * Mirrors the Apache protection from .htaccess: non-public directories and
 * sensitive files are never served.
 *
 * Usage: php -S localhost:8080 router.php   (from the repository root)
 */

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';
$file = __DIR__ . $path;

// Serve real static files directly (css/js/images) — but only public ones.
$protectedDirs = ['src', 'templates', 'tests', 'database', 'config', 'assets-design'];
foreach ($protectedDirs as $dir) {
    if ($path === '/' . $dir || str_starts_with($path, '/' . $dir . '/')) {
        http_response_code(404);
        exit;
    }
}

// Only index.php is executable over HTTP; no other PHP file is served.
if ($path !== '/index.php' && preg_match('#\.(php|sql|md|sh|ini|log|env)$#', $path)) {
    http_response_code(404);
    exit;
}

if ($path !== '/' && is_file($file) && str_starts_with(realpath($file) . '', realpath(__DIR__))) {
    return false;
}

if (str_contains($path, '..')) {
    http_response_code(404);
    exit;
}

require __DIR__ . '/index.php';
