<?php

declare(strict_types=1);

namespace Versandkostenretter\Import;

use PDO;
use RuntimeException;
use Versandkostenretter\Money;

/**
 * Persistence for imported products: safe UPSERT + source-scoped stale handling.
 *
 * Tri-state availability mapping (VSKR_products.available TINYINT NULL):
 *   AVAILABLE   -> 1
 *   UNAVAILABLE -> 0
 *   UNKNOWN     -> NULL (data-minimal: explicitly "no reliable data";
 *                         never silently treated as 0 or 1)
 *
 * Guarantees:
 *  - idempotent via UNIQUE (shop_id, source_type, source_scope, external_id)
 *  - transactional: upserts + stale marking in ONE transaction
 *  - shop/source/scope scoped everywhere
 *  - stale marking only after the caller confirms a COMPLETE source run
 *  - never DELETEs; stale products get available = 0
 *  - affiliate columns untouched
 */
final class ImportRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    /** @param list<NormalizedProduct> $products */
    public function upsertProducts(int $shopId, string $sourceType, string $sourceScope, array $products): array
    {
        $this->pdo->beginTransaction();
        try {
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
            foreach ($products as $p) {
                $stmtExisting->execute([
                    ':sid' => $shopId, ':st' => $sourceType, ':sc' => $sourceScope,
                    ':eid' => $p->externalId,
                ]);
                $existing = $stmtExisting->fetchColumn();
                $availDb = $p->toDbValue();

                if ($existing === false) {
                    $insert->execute([
                        ':sid' => $shopId, ':eid' => $p->externalId,
                        ':name' => $p->name, ':url' => $p->canonicalUrl,
                        ':price' => Money::centsToDecimal($p->priceCents),
                        ':avail' => $availDb,
                        ':cat' => $p->category, ':img' => $p->imageUrl,
                        ':st' => $sourceType, ':sc' => $sourceScope,
                    ]);
                    $inserted++;
                } else {
                    $update->execute([
                        ':name' => $p->name, ':url' => $p->canonicalUrl,
                        ':price' => Money::centsToDecimal($p->priceCents),
                        ':avail' => $availDb,
                        ':cat' => $p->category, ':img' => $p->imageUrl,
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
     * Mark products of ONE shop from the SAME (source_type, source_scope) not
     * present in $seenExternalIds as UNAVAILABLE (available = 0). Complete-
     * source-scope only; never after a partial/failed run; never deletes.
     *
     * Set-based anti-join via a TEMPORARY table of seen ids: a chunked
     * NOT IN over multiple UPDATE statements over-marks (an id present in
     * chunk A but absent from chunk B gets flipped by B — the production bug
     * that flipped 749 freshly imported rows).
     *
     * @param list<string> $seenExternalIds
     */
    public function markStaleUnavailable(int $shopId, string $sourceType, string $sourceScope, array $seenExternalIds): int
    {
        $this->pdo->beginTransaction();
        try {
            $this->pdo->exec('CREATE TEMPORARY TABLE VSKR_tmp_seen (
                external_id VARCHAR(190) NOT NULL PRIMARY KEY
            )');
            $isSqlite = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite';
            $ins = $this->pdo->prepare($isSqlite
                ? 'INSERT OR IGNORE INTO VSKR_tmp_seen (external_id) VALUES (?)'
                : 'INSERT IGNORE INTO VSKR_tmp_seen (external_id) VALUES (?)');
            foreach ($seenExternalIds as $eid) {
                $ins->execute([$eid]);
            }

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
                $stmt = $this->pdo->prepare(
                    'UPDATE VSKR_products SET available = 0
                     WHERE shop_id = :sid AND source_type = :st AND source_scope = :sc
                       AND available = 1
                       AND external_id NOT IN (SELECT external_id FROM VSKR_tmp_seen)'
                );
                $stmt->execute([':sid' => $shopId, ':st' => $sourceType, ':sc' => $sourceScope]);
                $n = $stmt->rowCount();
            }

            // ROOT CAUSE of the production failure: a plain
            // 'DROP TABLE IF EXISTS VSKR_tmp_seen' causes an IMPLICIT COMMIT
            // in MySQL/MariaDB even when the table is temporary. That
            // silently committed the whole import (stale UPDATE included)
            // and made the subsequent commit() throw
            // 'There is no active transaction'. 'DROP TEMPORARY TABLE' is
            // the documented exception that preserves the transaction.
            // SQLite has no implicit-commit DDL and keeps 'DROP TABLE'.
            $this->pdo->exec($isSqlite
                ? 'DROP TABLE IF EXISTS VSKR_tmp_seen'
                : 'DROP TEMPORARY TABLE IF EXISTS VSKR_tmp_seen');
            $this->pdo->commit();
            return $n;
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw new RuntimeException('Stale-marking transaction rolled back: ' . $e->getMessage(), 0, $e);
        }
    }

    /** MySQL name-lock: prevents concurrent imports of the same shop. */
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

    /**
     * Operator shop off-switch helper: purge ALL imported products of ONE
     * shop, explicitly scoped by shop_id. Never touches other shops.
     * Only invoked by the documented operator procedure, never automatically.
     */
    public function purgeShopProducts(int $shopId): int
    {
        $stmt = $this->pdo->prepare('DELETE FROM VSKR_products WHERE shop_id = :sid');
        $stmt->execute([':sid' => $shopId]);
        return $stmt->rowCount();
    }
}
