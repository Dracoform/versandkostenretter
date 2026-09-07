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
     * Interior whitespace is percent-encoded (never silently dropped), and
     * control characters make the URL invalid.
     */
    public static function safeUrl(?string $url): ?string
    {
        if ($url === null || trim($url) === '') {
            return null;
        }
        $url = trim($url);
        // Control characters (except regular spaces) make the URL invalid —
        // they are a classic filter-evasion vector, never encode them.
        if (preg_match('/[\x00-\x1F\x7F]/', $url)) {
            return null;
        }
        // Encode interior spaces so they cannot break attribute parsing.
        if (str_contains($url, ' ')) {
            $url = str_replace(' ', '%20', $url);
        }
        if (!preg_match('#^https?://#i', $url)) {
            return null;
        }
        return $url;
    }
}
