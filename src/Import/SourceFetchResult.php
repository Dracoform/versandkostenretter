<?php

declare(strict_types=1);

namespace Versandkostenretter\Import;

/**
 * Result of one COMPLETE source run: normalized products plus skips.
 *
 * complete === true is the explicit precondition for stale handling.
 */
final class SourceFetchResult
{
    /**
     * @param list<NormalizedProduct> $products
     * @param list<array{id:string,title:string,reason:string}> $skipped
     * @param bool $complete true only when the whole source was fetched and
     *                       validated successfully (never true after a
     *                       partial/paginated failure)
     * @param int $requests number of HTTP requests made (observability)
     */
    public function __construct(
        public readonly array $products,
        public readonly array $skipped,
        public readonly bool $complete,
        public readonly int $requests = 0,
    ) {
    }
}
