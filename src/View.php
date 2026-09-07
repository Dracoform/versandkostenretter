<?php

declare(strict_types=1);

namespace Versandkostenretter;

/**
 * Tiny view helper: context-aware output escaping. XSS-safe by default.
 */
final class View
{
    /** HTML body text / attributes. */
    public static function e(?string $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * Safe href for product links / image URLs coming from the database.
     * Only http(s) is allowed; anything else (javascript:, data:, ...) is dropped.
     */
    public static function safeUrl(?string $url): ?string
    {
        if ($url === null || trim($url) === '') {
            return null;
        }
        $url = trim($url);
        // Reject control chars, newlines and whitespace tricks.
        if (preg_match('/[\x00-\x20]/', $url)) {
            $url = preg_replace('/[\x00-\x20]+/', '', $url) ?? '';
        }
        if (!preg_match('#^https?://#i', $url)) {
            return null;
        }
        return $url;
    }
}
