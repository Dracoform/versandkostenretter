<?php

declare(strict_types=1);

namespace Versandkostenretter;

/**
 * Minimal CSRF protection using the session (technical necessity -> allowed cookie).
 * No tracking data is stored in the session beyond the random token itself.
 */
final class Csrf
{
    private const KEY = 'vskr_csrf';

    public static function token(): string
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start([
                'cookie_httponly' => '1',
                'cookie_samesite' => 'Strict',
                'use_strict_mode' => '1',
            ]);
        }
        if (empty($_SESSION[self::KEY])) {
            $_SESSION[self::KEY] = bin2hex(random_bytes(32));
        }
        return $_SESSION[self::KEY];
    }

    public static function validate(?string $token): bool
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start([
                'cookie_httponly' => '1',
                'cookie_samesite' => 'Strict',
                'use_strict_mode' => '1',
            ]);
        }
        $expected = $_SESSION[self::KEY] ?? '';
        return is_string($token)
            && $expected !== ''
            && strlen($token) === 64
            && hash_equals($expected, $token);
    }
}
