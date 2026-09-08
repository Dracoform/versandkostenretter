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
    public function eligibleProducts(
        int $shopId,
        int $minPriceCents,
        int $limit,
        ?string $category = null,
        ?int $maxPriceCents = null,
        ?array $categoryMemberships = null
    ): array {
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
        if ($maxPriceCents !== null) {
            // Filler-item price window (default mode): missing <= price <= missing + X.
            $sql .= ' AND price <= :max_price';
        }
        if ($category !== null && $categoryMemberships !== null) {
            // Many-to-many membership: match the category against EVERY stored
            // variant (single column + memberships), case-insensitively.
            $variants = $this->resolveCategoryVariants($shopId, $category, $categoryMemberships);
            if ($variants === []) {
                return []; // category exists but no product carries it
            }
            $placeholders = implode(',', array_map(
                static fn (int $i): string => ':cat' . $i,
                array_keys($variants)
            ));
            // Membership-aware: a product matches when it carries the
            // category via the single column OR via any stored collection.
            $sql .= " AND (category IN ($placeholders)
                     OR external_id IN (
                        SELECT external_id FROM VSKR_product_categories
                         WHERE shop_id = :shop_id_mc AND category IN ($placeholders)
                     ))";
            $catBind = $variants;
        } elseif ($category !== null) {
            $sql .= ' AND category = :category';
        }
        $sql .= ' ORDER BY price ASC, id ASC LIMIT ' . (int) $limit;

        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->bindValue(':shop_id', $shopId, PDO::PARAM_INT);
        $stmt->bindValue(':min_price', Money::centsToDecimal($minPriceCents));
        if ($maxPriceCents !== null) {
            $stmt->bindValue(':max_price', Money::centsToDecimal($maxPriceCents));
        }
        if ($category !== null && $categoryMemberships !== null) {
            foreach ($catBind as $i => $v) {
                $stmt->bindValue(':cat' . $i, $v);
            }
            $stmt->bindValue(':shop_id_mc', $shopId, PDO::PARAM_INT);
        } elseif ($category !== null) {
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
    public function countEligible(int $shopId, int $minPriceCents, ?int $maxPriceCents = null): int
    {
        $sql = 'SELECT COUNT(*) FROM VSKR_products
                WHERE shop_id = :shop_id AND available = 1 AND price >= :min_price';
        if ($maxPriceCents !== null) {
            $sql .= ' AND price <= :max_price';
        }
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->bindValue(':shop_id', $shopId, PDO::PARAM_INT);
        $stmt->bindValue(':min_price', Money::centsToDecimal($minPriceCents));
        if ($maxPriceCents !== null) {
            $stmt->bindValue(':max_price', Money::centsToDecimal($maxPriceCents));
        }
        $stmt->execute();

        return (int) $stmt->fetchColumn();
    }

    /**
     * Distinct non-empty categories of the eligible set (same constraints as
     * eligibleProducts, without the LIMIT) — the dropdown must offer every
     * category that exists in the qualifying window, not only those on the
     * first results page.
     *
     * @return list<string>
     */
    public function distinctCategories(int $shopId, ?array $categoryMemberships = null): array
    {
        $sql = "SELECT DISTINCT category FROM VSKR_products
                WHERE shop_id = :shop_id AND available = 1
                  AND category IS NOT NULL AND category <> ''";
        $bind = [];
        if ($categoryMemberships !== null && $categoryMemberships !== []) {
            $sql .= " UNION
                   SELECT DISTINCT pc.category
                     FROM VSKR_product_categories pc
                     JOIN VSKR_products p
                       ON p.shop_id = pc.shop_id AND p.external_id = pc.external_id
                    WHERE pc.shop_id = :shop_id AND p.available = 1";
        }
        $sql .= ' ORDER BY category ASC';

        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->bindValue(':shop_id', $shopId, PDO::PARAM_INT);
        $stmt->execute();

        return array_values(array_unique(array_map(
            'strval',
            (array) $stmt->fetchAll(PDO::FETCH_COLUMN)
        )));
    }

    /**
     * Resolve a requested category to ALL stored category values that match
     * case-insensitively — across the single `category` column and the
     * many-to-many membership table.
     *
     * @return list<string>
     */
    private function resolveCategoryVariants(int $shopId, string $category, array $memberships): array
    {
        $lower = function_exists('mb_strtolower')
            ? mb_strtolower($category, 'UTF-8')
            : strtolower($category);
        $variants = [];
        foreach ($memberships as $externalId => $cats) {
            foreach ($cats as $cat) {
                $catLower = function_exists('mb_strtolower')
                    ? mb_strtolower($cat, 'UTF-8')
                    : strtolower($cat);
                if ($catLower === $lower && !in_array($cat, $variants, true)) {
                    $variants[] = $cat;
                }
            }
        }
        return $variants;
    }
}