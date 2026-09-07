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
        if ((function_exists('mb_strlen') ? mb_strlen($requested) : strlen($requested)) > 190) {
            return false;
        }
        return in_array($requested, $categories, true);
    }
}
