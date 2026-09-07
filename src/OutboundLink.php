<?php

declare(strict_types=1);

namespace Versandkostenretter;

use RuntimeException;

/**
 * Single component that builds ALL outbound product links.
 *
 * Contract:
 *  - VSKR_products.url ALWAYS stores the canonical merchant URL and is never
 *    modified by this component (it only reads).
 *  - Affiliate configuration belongs to the SHOP and is optional.
 *  - Affiliate functionality is DISABLED by default: with no (or disabled)
 *    configuration the canonical URL is returned unchanged.
 *  - Two modes:
 *      A) 'query'    — append/merge one query parameter into the canonical URL
 *                      (existing query parameters are preserved).
 *      B) 'template' — substitute the urlencoded canonical URL into a
 *                      provider URL template containing the {url} placeholder.
 *  - No click tracking, no redirects operated by us, no cookies, no
 *    external services. Pure string/URL transformation.
 *  - Unsafe URLs are rejected exactly like everywhere else (View::safeUrl).
 *
 * Future disclosure support: the returned array always carries an
 * 'is_affiliate' flag so the frontend can later show a visible label when —
 * and only when — affiliate functionality is actually enabled. While every
 * shop is disabled, is_affiliate is false and nothing implies affiliate use.
 */
final class OutboundLink
{
    /**
     * Shop-side affiliate configuration shape (from VSKR_shops):
     * [
     *   'enabled'  => bool,
     *   'mode'     => 'query'|'template',
     *   'param'    => string|null,  // query mode: parameter name
     *   'value'    => string|null,  // query mode: parameter value
     *   'template' => string|null,  // template mode: URL with {url} placeholder
     * ]
     */
    public static function build(string $canonicalUrl, ?array $affiliate): array
    {
        // Reject malformed/unsafe URLs first — same rules as everywhere else.
        $safe = View::safeUrl($canonicalUrl);
        if ($safe === null) {
            throw new RuntimeException('Refusing to build an outbound link from an unsafe URL.');
        }

        if ($affiliate === null || empty($affiliate['enabled'])) {
            return ['url' => $safe, 'is_affiliate' => false];
        }

        $mode = $affiliate['mode'] ?? null;

        if ($mode === 'query') {
            $param = $affiliate['param'] ?? null;
            $value = $affiliate['value'] ?? null;
            if (!is_string($param) || $param === '' || !is_string($value) || $value === '') {
                // Incomplete configuration -> fail safe to the canonical URL.
                return ['url' => $safe, 'is_affiliate' => false];
            }
            return [
                'url' => self::appendQueryParam($safe, $param, $value),
                'is_affiliate' => true,
            ];
        }

        if ($mode === 'template') {
            $template = $affiliate['template'] ?? null;
            if (!is_string($template) || $template === '' || !str_contains($template, '{url}')) {
                return ['url' => $safe, 'is_affiliate' => false];
            }
            $built = str_replace('{url}', rawurlencode($safe), $template);
            $builtSafe = View::safeUrl($built);
            if ($builtSafe === null) {
                // Template produced something unsafe -> canonical URL, not affiliate.
                return ['url' => $safe, 'is_affiliate' => false];
            }
            return ['url' => $builtSafe, 'is_affiliate' => true];
        }

        // Unknown mode -> disabled.
        return ['url' => $safe, 'is_affiliate' => false];
    }

    /**
     * Append a query parameter, preserving existing parameters.
     * Existing parameter values stay untouched; if the parameter name already
     * exists it is NOT duplicated (first occurrence wins — merchant pages
     * usually carry their own params).
     */
    public static function appendQueryParam(string $url, string $param, string $value): string
    {
        $parts = parse_url($url);
        if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
            return $url; // unreachable in practice: URL already validated
        }

        $query = [];
        if (isset($parts['query']) && $parts['query'] !== '') {
            parse_str($parts['query'], $query);
            if (array_key_exists($param, $query)) {
                return $url; // already present — do not duplicate or overwrite
            }
        }
        $query[$param] = $value;

        // RFC 3986 encoding: spaces become %20 (not '+'), which is the safer
        // and more universally accepted form for outbound affiliate URLs.
        $newQuery = http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        $base = $url;
        if (isset($parts['query'])) {
            // Strip the old query string (it is re-appended in $newQuery).
            $base = substr($url, 0, strlen($url) - strlen($parts['query']));
            $base = rtrim($base, '?');
        }

        return $base . '?' . $newQuery;
    }

    /** Normalize a raw DB row into the configuration array above (or null). */
    public static function configFromShopRow(array $row): ?array
    {
        if (empty($row['affiliate_enabled'])) {
            return null; // disabled by default for every shop
        }
        return [
            'enabled'  => true,
            'mode'     => ($row['affiliate_mode'] ?? null) === 'template' ? 'template' : 'query',
            'param'    => $row['affiliate_param'] ?? null,
            'value'    => $row['affiliate_value'] ?? null,
            'template' => $row['affiliate_template'] ?? null,
        ];
    }
}
