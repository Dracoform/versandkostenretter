<?php

declare(strict_types=1);

namespace Versandkostenretter\Import;

use Versandkostenretter\Import\SourceException;
use Versandkostenretter\View;

/**
 * Maps a Shopify collection products.json feed to the neutral VSKR product
 * model. Pure mapping/validation — no I/O.
 *
 * Mapping (documented policy):
 *   product.id            -> external_id  (string, Shopify numeric id)
 *   product.title         -> name
 *   product.handle        -> canonical product URL:
 *                            {shopOrigin}/products/{handle}
 *   variant.price         -> price         (Shopify decimal string, e.g. "24.99")
 *   variant.available     -> available
 *   images[0].src         -> image_url     (https only, else NULL)
 *   product.updated_at    -> kept for staleness bookkeeping in the result set
 *   category              -> product.product_type if non-empty, otherwise the
 *                            FIRST tag (merchant's own metadata, verbatim;
 *                            no taxonomy, no normalization)
 *
 * Multi-variant policy (documented, fail-safe):
 *   - exactly one variant            -> used directly
 *   - several variants, SAME price   -> first variant used (price unambiguous)
 *   - several variants, DIFFERENT prices -> product is SKIPPED and reported,
 *     because a single price row would misrepresent the product.
 *   - zero variants                  -> SKIPPED (no price representable)
 *
 * The canonical URL is NEVER affiliate-modified. Unsafe URLs (non-https,
 * control characters) cause the product to be skipped, not sanitized.
 */
final class ShopifyMapper
{
    /** @return array{products:list<NormalizedProduct>, skipped:list<array{id:string,title:string,reason:string}>} */
    public static function mapFeed(mixed $decoded, string $shopOrigin): array
    {
        if (!is_array($decoded) || !array_key_exists('products', $decoded) || !is_array($decoded['products'])) {
            throw new SourceException('Invalid Shopify response: missing "products" array.');
        }

        $origin = rtrim($shopOrigin, '/');
        if (!preg_match('#^https://[a-z0-9.-]+#i', $origin)) {
            throw new SourceException('Invalid shop origin URL.');
        }

        $products = [];
        $skipped = [];

        foreach ($decoded['products'] as $p) {
            if (!is_array($p)) {
                $skipped[] = ['id' => '?', 'title' => '?', 'reason' => 'product entry is not an object'];
                continue;
            }

            $externalId = isset($p['id']) ? (string) $p['id'] : '';
            $title = isset($p['title']) && is_string($p['title']) ? trim($p['title']) : '';
            if ($externalId === '' || $externalId === '0' || $title === '') {
                $skipped[] = ['id' => $externalId, 'title' => $title, 'reason' => 'missing id or title'];
                continue;
            }

            // Canonical merchant URL from the handle.
            $handle = isset($p['handle']) && is_string($p['handle']) ? $p['handle'] : '';
            $url = $origin . '/products/' . rawurlencode($handle);
            if ($handle === '' || View::safeUrl($url) === null) {
                $skipped[] = ['id' => $externalId, 'title' => $title, 'reason' => 'unsafe or missing product handle/URL'];
                continue;
            }

            $variants = is_array($p['variants'] ?? null) ? $p['variants'] : [];
            $chosen = self::chooseVariant($variants);
            if (is_string($chosen)) {
                $skipped[] = ['id' => $externalId, 'title' => $title, 'reason' => $chosen];
                continue;
            }
            [$priceCents, $availabilityState] = $chosen;

            // Image: first image, https only; otherwise NULL (never an unsafe URL).
            $imageUrl = null;
            if (isset($p['images'][0]['src']) && is_string($p['images'][0]['src'])) {
                $candidate = View::safeUrl($p['images'][0]['src']);
                $imageUrl = $candidate; // may still be null after safety check
            }

            // Category: product_type first, else first tag. Merchant data verbatim.
            $category = self::categoryOf($p);

            $products[] = new NormalizedProduct(
                externalId: $externalId,
                name: $title,
                canonicalUrl: $url,
                priceCents: $priceCents,
                availabilityState: $availabilityState,
                category: $category,
                imageUrl: $imageUrl,
                sourceUpdatedAt: isset($p['updated_at']) && is_string($p['updated_at'])
                    ? $p['updated_at'] : null,
            );
        }

        return ['products' => $products, 'skipped' => $skipped];
    }

    /**
     * Variant selection policy (see class docblock).
     *
     * @return array{0:int,1:string}|string  [priceCents, availabilityState] or skip-reason
     */
    private static function chooseVariant(array $variants): array|string
    {
        if ($variants === []) {
            return 'product has no variants (no price representable)';
        }

        $prices = [];
        $first = null;
        foreach ($variants as $v) {
            if (!is_array($v) || !isset($v['price']) || !is_scalar($v['price'])) {
                return 'variant without price';
            }
            $cents = \Versandkostenretter\Money::parseToCents((string) $v['price']);
            if ($cents === null || $cents < 0) {
                return 'variant with unparsable price';
            }
            if ($first === null) {
                $first = [$cents, self::availabilityStateOf($v)];
            }
            $prices[$cents] = true;
        }

        if (count($prices) > 1) {
            return sprintf('product has %d variants with different prices (skipped, would misrepresent price)', count($prices));
        }

        return $first; // single variant, or identical prices across variants
    }

    /**
     * Tri-state availability mapping:
     *   true  -> AVAILABLE, false -> UNAVAILABLE,
     *   missing/unreliable -> UNKNOWN (never silently true/false).
     */
    private static function availabilityStateOf(array $variant): string
    {
        $a = $variant['available'] ?? null;
        if ($a === true) {
            return NormalizedProduct::AV_AVAILABLE;
        }
        if ($a === false) {
            return NormalizedProduct::AV_UNAVAILABLE;
        }
        return NormalizedProduct::AV_UNKNOWN; // missing/unreliable data
    }

    /** @return string|null */
    private static function categoryOf(array $p): ?string
    {
        $type = $p['product_type'] ?? null;
        if (is_string($type) && trim($type) !== '') {
            return trim($type);
        }
        $tags = $p['tags'] ?? null;
        if (is_string($tags)) {
            // Some Shopify responses deliver tags as a comma-separated string.
            $first = trim(explode(',', $tags)[0]);
            return $first !== '' ? $first : null;
        }
        if (is_array($tags)) {
            foreach ($tags as $tag) {
                if (is_string($tag) && trim($tag) !== '') {
                    return trim($tag);
                }
            }
        }
        return null;
    }
}
