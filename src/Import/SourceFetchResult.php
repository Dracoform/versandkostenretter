<?php

declare(strict_types=1);

namespace Versandkostenretter\Import;

/**
 * Result of one source fetch run.
 *
 * categoryMemberships semantics (optional source capability):
 *   - null:  collection enrichment was NOT available (discovery, fetch or
 *            parsing failed, or the source has no collection support).
 *            Callers must NOT replace previously persisted memberships.
 *   - array: enrichment succeeded end-to-end and this map is the
 *            authoritative complete membership set for the shop. An empty
 *            array is legitimate (the merchant has no usable collections).
 */
final class SourceFetchResult
{
    /**
     * @param list<NormalizedProduct> $products
     * @param list<array{id:string,title:string,reason:string}> $skipped
     * @param array<string, list<string>>|null $categoryMemberships
     * @param list<string> $enrichmentWarnings
     */
    public function __construct(
        public readonly array $products,
        public readonly array $skipped = [],
        public readonly bool $complete = false,
        public readonly int $requests = 0,
        public readonly ?array $categoryMemberships = null,
        public readonly array $enrichmentWarnings = [],
    ) {
    }
}
