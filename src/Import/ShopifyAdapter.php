<?php

declare(strict_types=1);

namespace Versandkostenretter\Import;

use RuntimeException;
use Versandkostenretter\Import\SourceFetcher;
use Versandkostenretter\Money;
use Versandkostenretter\View;

/**
 * Shopify source adapter.
 *
 * Supports two source scopes, selected purely by VSKR_shops configuration
 * (adding a second Shopify shop = one new VSKR_shops row, zero code changes):
 *
 *  1. SINGLE COLLECTION: source_url points at
 *        /collections/<handle>/products.json?limit=250
 *     Fetches that one collection only; stale handling stays collection-scoped.
 *
 *  2. COMPLETE CATALOGUE: source_url is the shop origin
 *     (e.g. https://lootforge.de). The adapter enumerates the shop-wide
 *     /products.json feed with plain page-number pagination
 *     (?limit=250&page=N) — verified against the real Lootforge storefront:
 *     pages return disjoint products, the first short page ends the catalogue,
 *     and an empty page confirms it. No Link-header cursor needed on this
 *     storefront; a page_info cursor is used automatically when a Link header
 *     is present. Stale handling then operates at complete-shop scope.
 *
 * Category mapping (documented, deterministic):
 *   product_type when non-empty, otherwise the FIRST merchant tag.
 *   Note: the complete Lootforge catalogue has product_type empty on every
 *   product, so tags are the only Shopify-native metadata; first-tag yields
 *   ~109 genuine merchant categories (e.g. "Warpaints Fanatic",
 *   "40K - Space Marines - Iron Hands"). This caveat is reported by the
 *   importer; no universal taxonomy is invented.
 *
 * Tri-state availability:
 *   variant.available true  -> AVAILABLE
 *   variant.available false -> UNAVAILABLE
 *   field absent/unreliable -> UNKNOWN (never silently true/false)
 *
 * Variant policy unchanged: single variant used directly; identical prices ->
 * first variant; differing prices -> product SKIPPED (never a wrong price).
 */
final class ShopifyAdapter implements SourceAdapter
{
    public function __construct(private SourceFetcher $http)
    {
    }

    public function type(): string
    {
        return 'shopify';
    }

    public function fetchAll(array $shop): SourceFetchResult
    {
        $sourceUrl = (string) ($shop['source_url'] ?? '');
        if ($sourceUrl === '' || !preg_match('#^https://#i', $sourceUrl)) {
            throw SourceException::because('Shop source_url must be an https:// URL.');
        }
        $scope = (string) ($shop['source_scope'] ?? 'default');

        // COMPLETE catalogue when the source URL is the shop root;
        // single collection when it points at a collection products.json.
        $isCompleteCatalogue = !str_contains($sourceUrl, '/collections/')
            || str_contains($sourceUrl, '/collections/all/');

        if ($isCompleteCatalogue) {
            $origin = rtrim(preg_replace('#^(https://[^/]+).*$#i', '$1', $sourceUrl) ?? $sourceUrl, '/');
            return $this->fetchCompleteCatalogue($origin, $scope);
        }

        // Single collection source (legacy scope): one request.
        $response = $this->http->get($sourceUrl);
        [$decoded, ] = $this->decodeProducts($response['body'], $response['content_type']);
        $origin = 'https://' . (string) parse_url($sourceUrl, PHP_URL_HOST);
        $mapped = ShopifyMapper::mapFeed($decoded, $origin);
        return new SourceFetchResult($mapped['products'], $mapped['skipped'], complete: true, requests: 1);
    }

    /**
     * Shop-wide catalogue: paginate ?limit=250&page=N until a short/empty
     * page. Guards: 60-page hard limit, duplicate-id detection, and a
     * complete flag that is only true after an uninterrupted successful run.
     */
    private function fetchCompleteCatalogue(string $origin, string $scope): SourceFetchResult
    {
        $normalized = [];
        $skipped = [];
        $requests = 0;
        $seenIds = [];
        $page = 1;
        $maxPages = 60; // loop protection: 60 * 250 = 15000 products max

        while ($page <= $maxPages) {
            $url = $origin . '/products.json?limit=250&page=' . $page;
            $response = $this->http->get($url);
            $requests++;
            if (!preg_match('#application/(json|javascript)#i', $response['content_type'])) {
                throw SourceException::because('Unexpected content type on page ' . $page . '.');
            }
            $decoded = json_decode($response['body'], true, 64);
            if (!is_array($decoded) || !isset($decoded['products']) || !is_array($decoded['products'])) {
                throw SourceException::because('Malformed JSON structure on page ' . $page . '.');
            }

            // Map this page; a mapping error on ANY page fails the whole run.
            $pageResult = ShopifyMapper::mapFeed($decoded, $origin);
            foreach ($pageResult['skipped'] as $s) {
                $skipped[] = $s;
            }

            $pageIds = [];
            foreach ($pageResult['products'] as $product) {
                if (isset($seenIds[$product->externalId])) {
                    // Duplicate across pages: keep first occurrence, do not
                    // create a duplicate row.
                    $skipped[] = [
                        'id' => $product->externalId,
                        'title' => $product->name,
                        'reason' => 'duplicate product id on page ' . $page,
                    ];
                    continue;
                }
                $seenIds[$product->externalId] = true;
                $pageIds[$product->externalId] = true;
                $normalized[] = $product;
            }

            // End of catalogue: short page (< limit) or empty page.
            $rawCount = count(is_array($decoded['products']) ? $decoded['products'] : []);
            if ($rawCount < 250) {
                return new SourceFetchResult($normalized, $skipped, complete: true, requests: $requests);
            }

            $page++;
        }

        // Loop guard: never trust a truncated catalogue as complete.
        throw SourceException::because(
            'Pagination exceeded the safety limit of ' . $maxPages . ' pages; catalogue treated as incomplete.'
        );
    }

    /** @return array{0:array<string,mixed>,1:string} decoded body + content type */
    private function decodeProducts(string $body, string $contentType): array
    {
        if (!preg_match('#application/(json|javascript)#i', $contentType)) {
            throw SourceException::because('Unexpected content type "' . $contentType . '".');
        }
        $decoded = json_decode($body, true, 64);
        if (!is_array($decoded) || !isset($decoded['products']) || !is_array($decoded['products'])) {
            throw SourceException::because('Invalid Shopify response structure.');
        }
        return [$decoded, $contentType];
    }
}
