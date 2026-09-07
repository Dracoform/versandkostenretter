<?php

declare(strict_types=1);

namespace Versandkostenretter;

use RuntimeException;

/**
 * Read access to VSKR_shops, including optional affiliate configuration
 * (disabled by default for every shop).
 */
final class ShopRepository
{
    /** Columns selected for shop records. */
    private const COLS = 'id, name, slug, shipping_cost, free_shipping_threshold,
        affiliate_enabled, affiliate_mode, affiliate_param, affiliate_value, affiliate_template,
        product_images_enabled';

    public function __construct(private Database $db)
    {
    }

    /**
     * All active shops for the dropdown, ordered by name.
     *
     * @return array<int, array{
     *   id:int,name:string,slug:string,shipping_cost_cents:int,
     *   free_shipping_threshold_cents:int,affiliate:?array
     * }>
     */
    public function activeShops(): array
    {
        $sql = 'SELECT ' . self::COLS . '
                FROM VSKR_shops
                WHERE active = 1
                ORDER BY name ASC';

        $rows = $this->db->pdo()->query($sql)->fetchAll();

        $shops = [];
        foreach ($rows as $row) {
            $shops[] = $this->hydrate($row);
        }
        return $shops;
    }

    /** Fetch one shop by id (any active state), or null. */
    public function find(int $id): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT ' . self::COLS . ' FROM VSKR_shops WHERE id = :id LIMIT 1'
        );
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();

        return $row === false ? null : $this->hydrate($row);
    }

    /** Fetch one active shop by slug, or null. */
    public function findBySlug(string $slug): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT ' . self::COLS . ' FROM VSKR_shops WHERE slug = :slug AND active = 1 LIMIT 1'
        );
        $stmt->execute([':slug' => $slug]);
        $row = $stmt->fetch();

        return $row === false ? null : $this->hydrate($row);
    }

    private function hydrate(array $row): array
    {
        $shipping = Money::parseToCents((string) $row['shipping_cost']);
        $threshold = Money::parseToCents((string) $row['free_shipping_threshold']);
        if ($shipping === null || $threshold === null) {
            throw new RuntimeException('Shop ' . $row['id'] . ' has invalid monetary values.');
        }

        return [
            'id' => (int) $row['id'],
            'name' => (string) $row['name'],
            'slug' => (string) $row['slug'],
            'shipping_cost_cents' => $shipping,
            'free_shipping_threshold_cents' => $threshold,
            // Per-shop display permission for merchant product images.
            // Default OFF: the frontend renders image_url only when true.
            'product_images_enabled' => !empty($row['product_images_enabled']),
            // Affiliate config belongs to the shop; null = disabled.
            'affiliate' => OutboundLink::configFromShopRow($row),
        ];
    }
}
