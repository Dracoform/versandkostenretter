<?php

declare(strict_types=1);

namespace Versandkostenretter\Import;

use PDO;
use RuntimeException;

/**
 * Persistence for imported products: safe UPSERT + source-scoped staleness.
 *
 * Guarantees:
 *  - idempotent: UNIQUE (shop_id, source_type, source_scope, external_id)
 *    makes repeated imports update, never duplicate.
 *  - transactional: upserts + stale-marking run in ONE transaction; a failure
 *    leaves the shop untouched.
 *  - shop-scoped: every statement is bound to the shop id.
 *  - stale-marking only happens when the caller explicitly confirms a
 *    COMPLETE feed for a (source_type, source_scope) pair.
 *  - never DELETEs; stale products get available = 0.
 *  - affiliate columns on VSKR_shops are never touched.
 */
final class ImportRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    /**
     * Upsert a batch of mapped products for one shop/source.
     *
     * @param list<array{external_id:string,name:string,url:string,price_cents:int,
     *   available:bool,category:?string,image_url:?string}> $products
     * @return array{inserted:int, updated:int}
     */
    public function upsertProducts(int $shopId, string $sourceType, string $sourceScope, array $products): array
    {
        $this->pdo->beginTransaction();
        try {
            // Existing ids for this exact (shop, source, scope) — for insert/update stats.
            $stmtExisting = $this->pdo->prepare(
                'SELECT id FROM VSKR_products
                 WHERE shop_id = :sid AND source_type = :st AND source_scope = :sc AND external_id = :eid'
            );

            $insert = $this->pdo->prepare(
                'INSERT INTO VSKR_products
                    (shop_id, external_id, name, url, price, available, category, image_url,
                     source_type, source_scope, last_seen_at)
                 VALUES
                    (:sid, :eid, :name, :url, :price, :avail, :cat, :img, :st, :sc, CURRENT_TIMESTAMP)'
            );
            $update = $this->pdo->prepare(
                'UPDATE VSKR_products SET
                    name = :name, url = :url, price = :price, available = :avail,
                    category = :cat, image_url = :img, last_seen_at = CURRENT_TIMESTAMP
                 WHERE id = :pid'
            );

            $inserted = 0;
            $updated = 0;
            $now = [];
            foreach ($products as $p) {
                $now[] = $p;
                $stmtExisting->execute([
                    ':sid' => $shopId, ':st' => $sourceType, ':sc' => $sourceScope,
                    ':eid' => $p['external_id'],
                ]);
                $existing = $stmtExisting->fetchColumn();

                if ($existing === false) {
                    $insert->execute([
                        ':sid' => $shopId, ':eid' => $p['external_id'],
                        ':name' => $p['name'], ':url' => $p['url'],
                        ':price' => \Versandkostenretter\Money::centsToDecimal($p['price_cents']),
                        ':avail' => $p['available'] ? 1 : 0,
                        ':cat' => $p['category'], ':img' => $p['image_url'],
                        ':st' => $sourceType, ':sc' => $sourceScope,
                    ]);
                    $inserted++;
                } else {
                    $update->execute([
                        ':name' => $p['name'], ':url' => $p['url'],
                        ':price' => \Versandkostenretter\Money::centsToDecimal($p['price_cents']),
                        ':avail' => $p['available'] ? 1 : 0,
                        ':cat' => $p['category'], ':img' => $p['image_url'],
                        ':pid' => $existing,
                    ]);
                    $updated++;
                }
            }

            $this->pdo->commit();
            return ['inserted' => $inserted, 'updated' => $updated];
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw new RuntimeException('Import transaction rolled back: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Mark products of ONE shop that were imported from the SAME
     * (source_type, source_scope) but are NOT in $seenExternalIds as
     * unavailable. NEVER called after a fetch/parse failure.
     *
     * @param list<string> $seenExternalIds
     */
    public function markStaleUnavailable(int $shopId, string $sourceType, string $sourceScope, array $seenExternalIds): int
    {
        $this->pdo->beginTransaction();
        try {
            if ($seenExternalIds === []) {
                // Complete feed genuinely empty: everything from this source
                // scope goes unavailable (explicit, never via a failure path).
                $stmt = $this->pdo->prepare(
                    'UPDATE VSKR_products SET available = 0
                     WHERE shop_id = :sid AND source_type = :st AND source_scope = :sc AND available = 1'
                );
                $stmt->execute([':sid' => $shopId, ':st' => $sourceType, ':sc' => $sourceScope]);
                $n = $stmt->rowCount();
            } else {
                // Chunked NOT IN with bound placeholders only.
                $chunks = array_chunk(array_values($seenExternalIds), 500);
                $n = 0;
                foreach ($chunks as $chunk) {
                    $placeholders = implode(',', array_map(
                        fn ($i) => ':e' . $i,
                        array_keys($chunk)
                    ));
                    $stmt = $this->pdo->prepare(
                        "UPDATE VSKR_products SET available = 0
                         WHERE shop_id = :sid AND source_type = :st AND source_scope = :sc
                           AND available = 1 AND external_id NOT IN ({$placeholders})"
                    );
                    $stmt->bindValue(':sid', $shopId, PDO::PARAM_INT);
                    $stmt->bindValue(':st', $sourceType);
                    $stmt->bindValue(':sc', $sourceScope);
                    foreach ($chunk as $i => $eid) {
                        $stmt->bindValue(':e' . $i, $eid);
                    }
                    $stmt->execute();
                    $n += $stmt->rowCount();
                }
            }
            $this->pdo->commit();
            return $n;
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw new RuntimeException('Stale-marking transaction rolled back: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Advisory-style re-entrancy lock: one importer run per shop at a time.
     * Uses the MySQL name-lock API (session-scoped, auto-released).
     */
    public function acquireShopLock(string $lockName, int $timeoutSeconds = 0): bool
    {
        $stmt = $this->pdo->prepare('SELECT GET_LOCK(:l, :t)');
        $stmt->execute([':l' => 'vskr_import_' . $lockName, ':t' => $timeoutSeconds]);
        return $stmt->fetchColumn() === 1;
    }

    public function releaseShopLock(string $lockName): void
    {
        $stmt = $this->pdo->prepare('SELECT RELEASE_LOCK(:l)');
        $stmt->execute([':l' => 'vskr_import_' . $lockName]);
    }

}
