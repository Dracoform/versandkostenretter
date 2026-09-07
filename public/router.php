<?php

/**
 * Router for PHP's built-in development server ONLY.
 * Production (Plesk/Apache) uses public/.htaccess instead.
 *
 * Usage: php -S localhost:8080 -t public public/router.php
 */

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';
$file = __DIR__ . $path;

// Serve real static files directly (css/js/images).
if ($path !== '/' && is_file($file) && str_starts_with(realpath($file) . '', realpath(__DIR__))) {
    return false;
}

// Block access to anything outside public/ (defense in depth).
if (str_contains($path, '..') || preg_match('#\.(php|sql|md)$#', $path)) {
    if ($path !== '/' && !str_starts_with($path, '/index.php')) {
        http_response_code(404);
        exit;
    }
}

require __DIR__ . '/index.php';
