<?php

declare(strict_types=1);

namespace Versandkostenretter;

use PDO;

/**
 * Privacy-preserving aggregate statistics.
 *
 * Data-minimal by design: the only persisted state is a handful of named
 * aggregate numbers. No per-click rows, no IPs, no timestamps per event,
 * no user agents, referrers, shop/product identity, cart values, session
 * ids, cookies, fingerprints or user identifiers. It is impossible to
 * reconstruct who clicked what from this data.
 */
final class StatsRepository
{
    public const OUTBOUND_PRODUCT_CLICKS = 'outbound_product_clicks';

    public function __construct(private PDO $pdo)
    {
    }

    /**
     * Atomically increment a counter by 1.
     *
     * Single-statement upsert — no SELECT+UPDATE race; safe under
     * concurrent requests. Returns the new value, or null on failure
     * (callers must treat statistics as best-effort).
     */
    public function increment(string $key): ?int
    {
        try {
            try {
                // MySQL atomic upsert
                $stmt = $this->pdo->prepare(
                    'INSERT INTO VSKR_stats (stat_key, stat_value)
                     VALUES (:key, 1)
                     ON DUPLICATE KEY UPDATE stat_value = stat_value + 1'
                );
                $stmt->execute([':key' => $key]);
            } catch (\PDOException) {
                // SQLite atomic upsert (test/dev environments)
                $stmt = $this->pdo->prepare(
                    'INSERT INTO VSKR_stats (stat_key, stat_value)
                     VALUES (:key, 1)
                     ON CONFLICT(stat_key) DO UPDATE SET stat_value = stat_value + 1'
                );
                $stmt->execute([':key' => $key]);
            }

            $stmt = $this->pdo->prepare('SELECT stat_value FROM VSKR_stats WHERE stat_key = :key');
            $stmt->execute([':key' => $key]);
            $value = $stmt->fetchColumn();
            return $value === false ? null : (int) $value;
        } catch (\Throwable) {
            return null; // statistics are best-effort, never business-critical
        }
    }

    /** Current aggregate value (0 when the counter does not exist yet). */
    public function get(string $key): int
    {
        try {
            $stmt = $this->pdo->prepare('SELECT stat_value FROM VSKR_stats WHERE stat_key = :key');
            $stmt->execute([':key' => $key]);
            $value = $stmt->fetchColumn();
            return $value === false ? 0 : (int) $value;
        } catch (\Throwable $e) {
            // Still fail-soft for the user (0), but log so operators can
            // diagnose an invisible counter instead of guessing.
            error_log('[vskr-stats] reading counter failed: ' . $e->getMessage());
            return 0;
        }
    }
}
