<?php

declare(strict_types=1);

namespace Versandkostenretter;

/**
 * Opportunistic, shop-local category filtering.
 *
 * No taxonomy, no normalization between shops, no persistence: only the
 * category values exactly as stored in VSKR_products are used. Categories
 * are untrusted merchant data — treat them purely as opaque strings.
 */
final class CategoryFilter
{
    /**
     * Distinct non-empty categories of an eligible result set,
     * ordered by first appearance (stable, price-ascending order).
     *
     * @param array<int, array{category:?string,...}> $products
     * @return list<string>
     */
    public static function distinct(array $products): array
    {
        $seen = [];
        foreach ($products as $p) {
            $cat = $p['category'] ?? null;
            if (is_string($cat) && trim($cat) !== '') {
                $cat = trim($cat);
                $seen[$cat] = true;
            }
        }
        return array_keys($seen);
    }

    /**
     * Is the requested category usable (present in the eligible set)?
     * Anything else (empty, unknown, over-long) is NOT an error — the caller
     * simply falls back to "Alle".
     */
    public static function isUsable(array $categories, ?string $requested): bool
    {
        if ($requested === null || $requested === '') {
            return false;
        }
        $len = function_exists('mb_strlen') ? mb_strlen($requested) : strlen($requested);
        if ($len > 190) {
            return false;
        }
        // Case-insensitive: the URL/dropdown value may differ in casing from
        // the stored merchant tag (production bug root cause).
        foreach ($categories as $cat) {
            $catLower = function_exists('mb_strtolower')
                ? mb_strtolower($cat, 'UTF-8')
                : strtolower($cat);
            $reqLower = function_exists('mb_strtolower')
                ? mb_strtolower($requested, 'UTF-8')
                : strtolower($requested);
            if ($catLower === $reqLower) {
                return true;
            }
        }
        return false;
    }

    /**
     * Resolve the requested category case-insensitively to the EXACT stored
     * value (merchant tags may differ in casing from a shared URL). Returns
     * the exact stored value, or null when no match exists — callers then
     * fall back to "Alle" (never an error).
     */
    public static function resolve(array $categories, ?string $requested): ?string
    {
        if (!self::isUsable($categories, $requested)) {
            return null;
        }
        foreach ($categories as $cat) {
            $lower = function_exists('mb_strtolower')
                ? mb_strtolower($cat, 'UTF-8')
                : strtolower($cat);
            $reqLower = function_exists('mb_strtolower')
                ? mb_strtolower((string) $requested, 'UTF-8')
                : strtolower((string) $requested);
            if ($lower === $reqLower) {
                return $cat;
            }
        }
        return null;
    }
}
