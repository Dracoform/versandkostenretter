<?php

declare(strict_types=1);

namespace Versandkostenretter;

/**
 * Pure business logic for the cart / free-shipping calculation.
 * No database, no HTTP — fully unit-testable.
 */
final class Cart
{
    /**
     * @param int $cartCents      current cart value in cents
     * @param int $thresholdCents free-shipping threshold in cents
     * @return int missing amount in cents (0 when threshold reached)
     */
    public static function missingCents(int $cartCents, int $thresholdCents): int
    {
        return max(0, $thresholdCents - $cartCents);
    }

    public static function thresholdReached(int $cartCents, int $thresholdCents): bool
    {
        return $cartCents >= $thresholdCents;
    }

    /**
     * Does a product qualify for the recommendation list?
     *
     * Rules (all strict integer comparisons on cents):
     *  - product must be available
     *  - product must belong to the selected shop
     *  - product price >= missing amount
     */
    public static function qualifies(
        int $productPriceCents,
        int $missingCents,
        bool $available,
        int $productShopId,
        int $selectedShopId
    ): bool {
        if (!$available) {
            return false;
        }
        if ($productShopId !== $selectedShopId) {
            return false;
        }
        return $productPriceCents >= $missingCents;
    }

    /**
     * Effective extra cost of adding the product compared to paying
     * standard shipping on the current cart:
     *
     *   effective_extra_cost = product_price - standard_shipping_cost
     *
     * Can be negative (product cheaper than shipping itself).
     */
    public static function effectiveExtraCostCents(int $productPriceCents, int $shippingCostCents): int
    {
        return $productPriceCents - $shippingCostCents;
    }
}
