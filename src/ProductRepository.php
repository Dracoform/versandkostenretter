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
        ?array $categoryMemberships = null,
        int $page = 1
    ): array {
        if ($shopId <= 0) {
            throw new RuntimeException('Invalid shop id.');
        }
        $perPage = max(1, min(200, $limit));
        $page = max(1, $page);
        $offset = ($page - 1) * $perPage;

        [$where, $params] = $this->buildEligibilityWhere(
            $shopId, $minPriceCents, $maxPriceCents, $category, $categoryMemberships
        );

        // Deterministische Reihenfolge: price ASC, dann vollständiger Name
        // case-insensitive (utf8mb4_*_ci-Collation), dann external_id als
        // finaler stabiler Tie-Breaker. Merchant data bleibt opaque: keine
        // Prefix-/Suffix-Manipulation.
        $sql = 'SELECT id, external_id, name, url, price, category, image_url
                FROM VSKR_products ' . $where . '
                ORDER BY price ASC, name ASC, external_id ASC
                LIMIT ' . $perPage . ' OFFSET ' . $offset;

        $stmt = $this->db->pdo()->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value[0], $value[1]);
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
     * Zentrale Eligibility-/WHERE-Logik für COUNT, Produktquery und die
     * sichtbaren Kategorien — eine Implementierung, keine abweichenden Kopien.
     *
     * @return array{0:string,1:array<string,array{0:mixed,1:int}>} SQL-WHERE (mit 'WHERE ...') + Bind-Parameter [value, type]
     */
    private function buildEligibilityWhere(
        int $shopId,
        int $minPriceCents,
        ?int $maxPriceCents,
        ?string $category,
        ?array $categoryMemberships,
        string $tablePrefix = ''
    ): array {
        // $tablePrefix (z. B. 'p.') qualifiziert Spalten bei JOINs (MySQL
        // wirft sonst 'ambiguous column' bei unqualifizierten Namen).
        $t = $tablePrefix;
        $sql = "WHERE {$t}shop_id = :shop_id AND {$t}available = 1 AND {$t}price >= :min_price";
        $params = [
            ':shop_id' => [$shopId, PDO::PARAM_INT],
            ':min_price' => [Money::centsToDecimal($minPriceCents), PDO::PARAM_STR],
        ];
        if ($maxPriceCents !== null) {
            // Filler-item price window (default mode): missing <= price <= missing + X.
            $sql .= " AND {$t}price <= :max_price";
            $params[':max_price'] = [Money::centsToDecimal($maxPriceCents), PDO::PARAM_STR];
        }
        if ($category !== null && $categoryMemberships !== null) {
            // Many-to-many membership: match the category against EVERY stored
            // variant (single column + memberships), case-insensitively.
            $variants = $this->resolveCategoryVariants($shopId, $category, $categoryMemberships);
            if ($variants !== []) {
                // Eigene Platzhalternamen pro Vorkommen: MySQL native prepares
                // (ATTR_EMULATE_PREPARES = false) erlauben keinen named
                // Placeholder zweimal im Statement.
                $placeholders = implode(',', array_map(
                    static fn (int $i): string => ':cat' . $i,
                    array_keys($variants)
                ));
                $subPlaceholders = implode(',', array_map(
                    static fn (int $i): string => ':mcat' . $i,
                    array_keys($variants)
                ));
                $sql .= " AND ({$t}category IN ($placeholders)
                         OR {$t}external_id IN (
                            SELECT external_id FROM VSKR_product_categories
                             WHERE shop_id = :shop_id_mc AND category IN ($subPlaceholders)
                         ))";
                foreach ($variants as $i => $v) {
                    $params[':cat' . $i] = [$v, PDO::PARAM_STR];
                    $params[':mcat' . $i] = [$v, PDO::PARAM_STR];
                }
                $params[':shop_id_mc'] = [$shopId, PDO::PARAM_INT];
            } else {
                // Kategorie existiert, aber kein Produkt trägt sie -> garantiert
                // leere Menge (immer falsche Bedingung).
                $sql .= ' AND 1 = 0';
            }
        } elseif ($category !== null) {
            $sql .= " AND {$t}category = :category";
            $params[':category'] = [$category, PDO::PARAM_STR];
        }
        return [$sql, $params];
    }

    /**
     * How many more products would match beyond $limit (for "more available" hint).
     */
    public function countEligible(
        int $shopId,
        int $minPriceCents,
        ?int $maxPriceCents = null,
        ?string $category = null,
        ?array $categoryMemberships = null
    ): int {
        [$where, $params] = $this->buildEligibilityWhere(
            $shopId, $minPriceCents, $maxPriceCents, $category, $categoryMemberships
        );
        $stmt = $this->db->pdo()->prepare('SELECT COUNT(*) FROM VSKR_products ' . $where);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value[0], $value[1]);
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
    public function visibleCategories(
        int $shopId,
        int $minPriceCents,
        ?int $maxPriceCents = null,
        ?array $categoryMemberships = null
    ): array {
        // SICHTBARKEIT != TAXONOMIE: die vollständige Taxonomie bleibt
        // persistiert; hier werden nur die Kategorien bestimmt, die unter den
        // AKTUELLEN Eligibility-Bedingungen mindestens einen Treffer haben,
        // inkl. Trefferzahl pro Kategorie.
        // Eine einzige Query (kein N+1): UNION ALL der beiden Zuordnungs-
        // pfade + äußeres GROUP BY mit COUNT(DISTINCT external_id) — damit
        // zählt ein Produkt, das über Spalte UND Membership dieselbe
        // Kategorie erhält, nur einmal.
        [$where, $params] = $this->buildEligibilityWhere(
            $shopId, $minPriceCents, $maxPriceCents, null, null
        );

        // Teil 1: Zuordnung über die products.category-Spalte (Fallback).
        $parts = ['SELECT external_id, category
                   FROM VSKR_products ' . $where . "
                   AND category IS NOT NULL AND category <> ''"];

        // Teil 2: Zuordnung über die Membership-Tabelle, soweit der tragende
        // Produkt-Eintrag selbst die Eligibility-Bedingungen erfüllt.
        if ($categoryMemberships !== null && $categoryMemberships !== []) {
            [$whereP, $paramsP] = $this->buildEligibilityWhere(
                $shopId, $minPriceCents, $maxPriceCents, null, null, 'p.'
            );
            // UNION-ALL-Teile brauchen DISJIKTE Platzhalternamen (MySQL native
            // prepares erlaubt keinen Namen zweimal): Suffix _p2 fuer alle.
            $whereP = str_replace(':', ':p2_', $whereP);
            $renamed = [];
            foreach ($paramsP as $k => $v) {
                $renamed[':p2_' . substr($k, 1)] = $v;
            }
            $paramsP = $renamed;
            $parts[] = 'SELECT p.external_id, pc.category
                        FROM VSKR_product_categories pc
                        JOIN VSKR_products p
                          ON p.shop_id = pc.shop_id AND p.external_id = pc.external_id ' . $whereP . "
                        AND pc.shop_id = :shop_id_pc
                        AND pc.category IS NOT NULL AND pc.category <> ''";
            foreach ($paramsP as $k => $v) {
                $params[$k] = $v;
            }
            $params[':shop_id_pc'] = [$shopId, PDO::PARAM_INT];
        }

        // Äußere Aggregation: ein Produkt zählt pro Kategorie genau einmal
        // (COUNT(DISTINCT external_id)), auch wenn beide Pfade es liefern.
        // Mehrfach-Memberships zählen bewusst pro Kategorie einzeln (kein
        // kategorie-übergreifender Roll-up). Keine Sortierung nach Count.
        $sql = 'SELECT category, COUNT(DISTINCT external_id) AS hits
                FROM (' . implode(' UNION ALL ', $parts) . ') AS facet
                GROUP BY category
                ORDER BY category ASC';
        $stmt = $this->db->pdo()->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value[0], $value[1]);
        }
        $stmt->execute();

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $out = [];
        foreach ($rows as $row) {
            $name = (string) $row['category'];
            if ($name !== '') {
                $out[] = ['name' => $name, 'count' => (int) $row['hits']];
            }
        }
        return $out;
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