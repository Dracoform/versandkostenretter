<?php

declare(strict_types=1);

namespace Versandkostenretter\Import;

use RuntimeException;

/**
 * Source-agnostic import orchestration.
 *
 * Drives any registered SourceAdapter through the shared pipeline:
 *   adapter fetchAll (fail-closed) -> upsert (transactional) -> stale marking
 *   (only when the adapter reports a COMPLETE run) -> statistics.
 *
 * The orchestrator never contains source-specific logic; adapters do.
 */
final class ImportOrchestrator
{
    public function __construct(
        private SourceAdapterRegistry $adapters,
        private ImportRepository $repo
    ) {
    }

    /**
     * @param array<string,mixed> $shop shop row incl. id/name/source_type/
     *                                  source_url/source_scope
     * @return array<string,mixed> run statistics
     */
    public function import(array $shop, bool $dryRun = false): array
    {
        $sourceType = (string) ($shop['source_type'] ?? '');
        if ($sourceType === '') {
            throw new RuntimeException('Shop has no source_type configured.');
        }
        $adapter = $this->adapters->for($sourceType);

        // Fail-closed fetch: any error throws before any DB mutation.
        $fetched = $adapter->fetchAll($shop);

        $result = [
            'shop' => (string) ($shop['name'] ?? $shop['slug'] ?? '?'),
            'source' => $sourceType,
            'fetched' => count($fetched->products) + count($fetched->skipped),
            'inserted' => 0,
            'updated' => 0,
            'unavailable' => 0,
            'skipped' => count($fetched->skipped),
            'errors' => 0,
            'skipped_items' => $fetched->skipped,
            'requests' => $fetched->requests,
            'complete' => $fetched->complete,
            'stale_marked' => false,
        ];

        if ($dryRun) {
            $result['mapped'] = $fetched->products;
            return $result;
        }

        $scope = (string) ($shop['source_scope'] ?? 'default');
        $shopId = (int) $shop['id'];

        $stats = $this->repo->upsertProducts($shopId, $sourceType, $scope, $fetched->products);
        $result['inserted'] = $stats['inserted'];
        $result['updated'] = $stats['updated'];

        // Stale handling ONLY after a demonstrably COMPLETE run (all pages
        // fetched/validated). A paginated partial failure throws earlier and
        // never reaches this point.
        if ($fetched->complete) {
            $seen = array_map(
                static fn (NormalizedProduct $p): string => $p->externalId,
                $fetched->products
            );
            $result['unavailable'] = $this->repo->markStaleUnavailable($shopId, $sourceType, $scope, $seen);
            $result['stale_marked'] = true;

            // Optional source capability: many-to-many merchant category /
            // collection memberships (delete + replace per shop; stale
            // memberships cannot survive a complete run).
            //
            // null = enrichment unavailable (discovery/fetch/parse failed):
            // previous memberships are deliberately KEPT (fail closed) —
            // never replace known-good data with a malfunction's empty set.
            // array (even empty) = enrichment ran successfully end-to-end
            // and is the authoritative set; [] is legitimate for shops
            // without usable collections.
            if ($fetched->categoryMemberships !== null) {
                $result['memberships'] = $this->repo->replaceCategoryMemberships(
                    $shopId, $fetched->categoryMemberships
                );
            } else {
                $result['memberships_skipped'] = true;
                if ($fetched->enrichmentWarnings !== []) {
                    $result['enrichment_warnings'] = $fetched->enrichmentWarnings;
                }
            }
        }

        return $result;
    }
}
