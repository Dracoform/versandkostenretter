<?php

declare(strict_types=1);

namespace Versandkostenretter;

use RuntimeException;

/**
 * Read access to VSKR_shops.
 */
final class ShopRepository
{
    public function __construct(private Database $db)
    {
    }

    /**
     * All active shops for the dropdown, ordered by name.
     *
     * @return array<int, array{id:int,name:string,slug:string,shipping_cost_cents:int,free_shipping_threshold_cents:int}>
     */
    public function activeShops(): array
    {
        $sql = 'SELECT id, name, slug, shipping_cost, free_shipping_threshold
                FROM VSKR_shops
                WHERE active = 1
                ORDER BY name ASC';

        $rows = $this->db->pdo()->query($sql)->fetchAll();

        $shops = [];
        foreach ($rows as $row) {
            $shipping = Money::parseToCents((string) $row['shipping_cost']);
            $threshold = Money::parseToCents((string) $row['free_shipping_threshold']);
            if ($shipping === null || $threshold === null) {
                throw new RuntimeException('Shop ' . $row['id'] . ' has invalid monetary values.');
            }
            $shops[] = [
                'id' => (int) $row['id'],
                'name' => (string) $row['name'],
                'slug' => (string) $row['slug'],
                'shipping_cost_cents' => $shipping,
                'free_shipping_threshold_cents' => $threshold,
            ];
        }
        return $shops;
    }

    /** Fetch one shop by id (any active state), or null. */
    public function find(int $id): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, name, slug, shipping_cost, free_shipping_threshold
             FROM VSKR_shops WHERE id = :id LIMIT 1'
        );
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();

        if ($row === false) {
            return null;
        }

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
        ];
    }
}
