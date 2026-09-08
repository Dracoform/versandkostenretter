<?php

declare(strict_types=1);

namespace Versandkostenretter\Import;

/**
 * Contract every source adapter must fulfil.
 *
 * An adapter translates ONE complete source run (all pages/entries of one
 * shop source) into normalized products plus per-item skip reasons.
 * The orchestration, DB persistence, locking, stale handling and statistics
 * are shared and remain identical for every source type.
 *
 * Source types register themselves in SourceAdapterRegistry (shopify, csv,
 * json, xml, affiliate-feed, bespoke ...). Adding a second Shopify shop
 * requires only a new VSKR_shops row — never new importer code.
 */
interface SourceAdapter
{
    /** Machine name stored in VSKR_shops.source_type (e.g. 'shopify'). */
    public function type(): string;

    /**
     * Enumerate and normalize the COMPLETE catalogue of one source scope.
     *
     * Implementations must fail closed: on any fetch/parse/validation error a
     * SourceException is thrown and the caller must not mutate the DB
     * (in particular never mark unseen products stale after a partial run).
     *
     * @param array<string,mixed> $shop shop row incl. source_url/source_scope
     * @return SourceFetchResult
     */
    public function fetchAll(array $shop): SourceFetchResult;
}
