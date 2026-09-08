<?php

declare(strict_types=1);

namespace Versandkostenretter\Import;

use RuntimeException;

/**
 * Optional adapter capability: a fresh availability check for a FEW products.
 *
 * Intended future flow (deliberately NOT wired into the current search):
 *   catalogue import
 *     -> DB filters thousands down to relevant candidates
 *     -> ONLY those candidates may optionally get a live availability check
 *     -> confirmed-unavailable removed before display
 *
 * Design notes:
 *  - OPTIONAL capability: adapters implement it only when their source can
 *    provide reliable live stock data. Shopify needs no live checking merely
 *    because the interface exists (its catalogue import already carries
 *    availability).
 *  - Never invoked during normal page rendering or catalogue import; no
 *    network requests happen in searches. Caching is deliberately out of
 *    scope until the feature is built.
 */
interface AvailabilityChecker
{
    /**
     * Check live availability for the given external ids (small batches only;
     * callers are expected to pass a handful of display candidates, never a
     * whole catalogue).
     *
     * @param list<string> $externalIds
     * @return array<string,string> external_id => AvailabilityChecker result
     *                              state (AV_AVAILABLE / AV_UNAVAILABLE /
     *                              AV_UNKNOWN); ids the source could not
     *                              verify are simply absent
     */
    public function checkAvailability(array $externalIds): array;
}
