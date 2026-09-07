-- ============================================================================
-- MIGRATION 0005 — VSKR_stats: privacy-preserving aggregate click counter
--
-- Target: MySQL 8.4. VSKR namespace ONLY. Run MANUALLY by the operator.
-- DO NOT run against production until reviewed.
--
-- Deliberately data-minimal:
--   * ONE aggregate key/value row — never one row per click
--   * no IP addresses, timestamps per click, user agents, referrers,
--     shop/product identity, cart values, session ids, cookies,
--     fingerprints, click history or user identifiers
--   * the database cannot reconstruct who clicked what
--
-- Initial row starts at 0 (INSERT IGNORE = idempotent).
-- ============================================================================

CREATE TABLE IF NOT EXISTS VSKR_stats (
    stat_key   VARCHAR(64)  NOT NULL,
    stat_value BIGINT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (stat_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Privacy-preserving aggregate counters (data-minimal, no per-click data)';

-- Seed the counter at zero; safe on re-run.
INSERT IGNORE INTO VSKR_stats (stat_key, stat_value)
VALUES ('outbound_product_clicks', 0);
