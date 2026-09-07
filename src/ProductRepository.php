<?php

declare(strict_types=1);

namespace Versandkostenretter;

use PDO;
use RuntimeException;

/**
 * Read access to VSKR_products — always scoped to ONE shop.
 */
final class ProductRepository
{
    public function __construct(private Database $db)
    {
    }

    /**
     * Available products of the given shop with price >= minPriceCents,
     * ordered by price ascending, limited.
     *
     * @return array<int, array{
     *     id:int, external_id:?string, name:string, url:string,
     *     price_cents:int, category:?string, image_url:?string
     * }>
     */
    public function eligibleProducts(int $shopId, int $minPriceCents, int $limit, ?string $category = null): array
    {
        if ($shopId <= 0) {
            throw new RuntimeException('Invalid shop id.');
        }
        if ($limit < 1 || $limit > 200) {
            $limit = 24;
        }

        $sql = 'SELECT id, external_id, name, url, price, category, image_url
                FROM VSKR_products
                WHERE shop_id = :shop_id
                  AND available = 1
                  AND price >= :min_price';
        if ($category !== null) {
            // Bound value via prepared statement; it can never influence SQL structure.
            $sql .= ' AND category = :category';
        }
        $sql .= ' ORDER BY price ASC, id ASC LIMIT ' . (int) $limit;

        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->bindValue(':shop_id', $shopId, PDO::PARAM_INT);
        $stmt->bindValue(':min_price', Money::centsToDecimal($minPriceCents));
        if ($category !== null) {
            $stmt->bindValue(':category', $category);
        }
        $stmt->execute();
        $rows = $stmt->fetchAll();

        $products = [];
        foreach ($rows as $row) {
            $price = Money::parseToCents((string) $row['price']);
            if ($price === null) {
                // Skip malformed rows rather than failing the whole page.
                continue;
            }
            $url = trim((string) $row['url']);
            if ($url === '') {
                continue;
            }
            $products[] = [
                'id' => (int) $row['id'],
                'external_id' => $row['external_id'] !== null ? (string) $row['external_id'] : null,
                'name' => (string) $row['name'],
                'url' => $url,
                'price_cents' => $price,
                'category' => $row['category'] !== null && $row['category'] !== '' ? (string) $row['category'] : null,
                'image_url' => $row['image_url'] !== null && $row['image_url'] !== '' ? (string) $row['image_url'] : null,
            ];
        }
        return $products;
    }

    /**
     * How many more products would match beyond $limit (for "more available" hint).
     */
    public function countEligible(int $shopId, int $minPriceCents): int
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT COUNT(*) FROM VSKR_products
             WHERE shop_id = :shop_id AND available = 1 AND price >= :min_price'
        );
        $stmt->bindValue(':shop_id', $shopId, PDO::PARAM_INT);
        $stmt->bindValue(':min_price', Money::centsToDecimal($minPriceCents));
        $stmt->execute();

        return (int) $stmt->fetchColumn();
    }
}
