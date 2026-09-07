<?php

declare(strict_types=1);

namespace Versandkostenretter\Import;

use RuntimeException;
use Versandkostenretter\Import\SourceException;

/**
 * Orchestrates one import run for a shop with a Shopify source:
 * fetch -> validate -> map -> (dry-run? report) -> upsert -> stale-mark.
 *
 * Fail-closed: any fetch/validation problem throws BEFORE any database
 * mutation; availability is never derived from a failed response.
 */
final class ShopifyImporter
{
    public function __construct(
        private SourceFetcher $http,
        private ?ImportRepository $repo
    ) {
    }

    /**
     * @param array{id:int,name:string,slug:string,source_type:string,source_url:string,source_scope:string} $shop
     * @return array{
     *   shop:string, source:string, fetched:int, inserted:int, updated:int,
     *   unavailable:int, skipped:int, errors:int, skipped_items:list<array{id:string,title:string,reason:string}>,
     *   mapped:list<array<string,mixed>>, stale_marked:bool
     * }
     */
    public function import(array $shop, bool $dryRun = false): array
    {
        if (($shop['source_type'] ?? '') !== 'shopify') {
            throw new SourceException('ShopifyImporter requires source_type "shopify".');
        }
        $sourceUrl = (string) ($shop['source_url'] ?? '');
        if ($sourceUrl === '' || !preg_match('#^https://#i', $sourceUrl)) {
            throw new SourceException('Shop source_url must be an https:// URL.');
        }

        // --- fetch (fail-closed) ---
        $response = $this->http->get($sourceUrl);
        if (!preg_match('#application/(json|javascript)#i', $response['content_type'])) {
            throw new SourceException(sprintf(
                'Unexpected content type "%s" from source (expected JSON).',
                $response['content_type'] !== '' ? strtok($response['content_type'], ';') : '(none)'
            ));
        }

        // --- parse + validate structure (fail-closed) ---
        $decoded = json_decode($response['body'], true, 64);
        if (!is_array($decoded)) {
            throw new SourceException('Source returned invalid JSON (parse error ' . json_last_error_msg() . ').');
        }
        if (!isset($decoded['products']) || !is_array($decoded['products'])) {
            throw new SourceException('Source JSON has unexpected structure (no "products" array).');
        }

        // Origin for canonical product URLs = scheme+host of the source URL.
        $origin = 'https://' . (string) parse_url($sourceUrl, PHP_URL_HOST);
        $mapped = ShopifyMapper::mapFeed($decoded, $origin);

        $result = [
            'shop' => (string) $shop['name'],
            'source' => 'Shopify',
            'fetched' => count($mapped['products']) + count($mapped['skipped']),
            'inserted' => 0,
            'updated' => 0,
            'unavailable' => 0,
            'skipped' => count($mapped['skipped']),
            'errors' => 0,
            'skipped_items' => $mapped['skipped'],
            'mapped' => $mapped['products'],
            'stale_marked' => false,
        ];

        if ($dryRun) {
            return $result; // NO database writes of any kind
        }

        $scope = (string) ($shop['source_scope'] ?? 'default');

        if ($this->repo === null) {
            throw new \RuntimeException('Importer has no repository configured (live mode unavailable).');
        }

        $stats = $this->repo->upsertProducts(
            (int) $shop['id'], 'shopify', $scope, $mapped['products']
        );
        $result['inserted'] = $stats['inserted'];
        $result['updated'] = $stats['updated'];

        // Complete-feed staleness, scoped to (shop, source, scope).
        // Reached ONLY because fetch+parse above succeeded.
        $seen = array_map(
            static fn (array $p): string => (string) $p['external_id'],
            $mapped['products']
        );
        $result['unavailable'] = $this->repo->markStaleUnavailable(
            (int) $shop['id'], 'shopify', $scope, $seen
        );
        $result['stale_marked'] = true;

        return $result;
    }
}
