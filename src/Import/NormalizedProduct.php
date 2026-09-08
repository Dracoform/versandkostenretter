<?php

declare(strict_types=1);

namespace Versandkostenretter\Import;

/**
 * Neutral, source-independent product record.
 *
 * The core importer and the VSKR_* persistence layer operate exclusively on
 * this shape; source adapters translate their native feeds into it.
 */
final class NormalizedProduct
{
    /** Availability: reliable stock data says "buyable". */
    public const AV_AVAILABLE = 'available';
    /** Availability: reliable stock data says "not buyable". */
    public const AV_UNAVAILABLE = 'unavailable';
    /** Availability: the source exposes no reliable stock status. */
    public const AV_UNKNOWN = 'unknown';

    /**
     * @param string $externalId       stable id within the source
     * @param string $name
     * @param string $canonicalUrl     merchant product URL (never affiliate-modified)
     * @param int $priceCents
     * @param string $availabilityState one of the AV_* constants
     * @param string|null $category
     * @param string|null $imageUrl
     * @param string|null $sourceUpdatedAt
     */
    public function __construct(
        public readonly string $externalId,
        public readonly string $name,
        public readonly string $canonicalUrl,
        public readonly int $priceCents,
        public readonly string $availabilityState,
        public readonly ?string $category,
        public readonly ?string $imageUrl,
        public readonly ?string $sourceUpdatedAt,
    ) {
        if (!in_array($this->availabilityState, self::allStates(), true)) {
            throw new \InvalidArgumentException('Unknown availability state: ' . $this->availabilityState);
        }
    }

    /** @return list<string> */
    public static function allStates(): array
    {
        return [self::AV_AVAILABLE, self::AV_UNAVAILABLE, self::AV_UNKNOWN];
    }

    /** DB representation for VSKR_products.available (TINYINT NULL). */
    public function toDbValue(): ?int
    {
        return match ($this->availabilityState) {
            self::AV_AVAILABLE => 1,
            self::AV_UNAVAILABLE => 0,
            self::AV_UNKNOWN => null,
        };
    }

    /** @return array<string,mixed> plain row for the persistence layer */
    public function toRow(): array
    {
        return [
            'external_id' => $this->externalId,
            'name' => $this->name,
            'url' => $this->canonicalUrl,
            'price_cents' => $this->priceCents,
            'available' => $this->toDbValue(),
            'availability_state' => $this->availabilityState,
            'category' => $this->category,
            'image_url' => $this->imageUrl,
            'source_updated_at' => $this->sourceUpdatedAt,
        ];
    }
}
