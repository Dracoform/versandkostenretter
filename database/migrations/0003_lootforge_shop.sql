-- ============================================================================
-- MIGRATION 0003 — VSKR_shops: import source configuration + Lootforge record
--
-- Target: MySQL 8.4. VSKR namespace ONLY. Run MANUALLY by the operator.
-- DO NOT run against production until reviewed.
--
-- Part 1 (schema): adds source columns to VSKR_shops:
--   source_type   VARCHAR(50)  NULL   -- 'shopify', future: other feed types
--   source_url    VARCHAR(1000) NULL  -- the exact feed URL to fetch
--   source_scope  VARCHAR(190) NOT NULL DEFAULT 'default'
--                 -- names the feed scope for stale-marking (e.g. a collection
--                 -- handle); products imported from one scope are never
--                 -- marked stale because of another scope
--
-- Part 2 (data): idempotent upsert of the Lootforge shop record.
--   Affiliate stays DISABLED (defaults). No affiliate codes anywhere.
--
-- Idempotent: safe to run repeatedly.
-- ============================================================================

-- ---------- Part 1: schema ----------
SET @col := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'VSKR_shops'
      AND COLUMN_NAME = 'source_type'
);
SET @ddl := IF(
    @col = 0,
    'ALTER TABLE VSKR_shops
        ADD COLUMN source_type  VARCHAR(50)   NULL,
        ADD COLUMN source_url   VARCHAR(1000) NULL,
        ADD COLUMN source_scope VARCHAR(190)  NOT NULL DEFAULT ''default''',
    'SELECT ''VSKR_shops already has source columns — nothing to do'' AS note'
);
PREPARE vskr_mig FROM @ddl; EXECUTE vskr_mig; DEALLOCATE PREPARE vskr_mig;

-- ---------- Part 2: Lootforge shop record ----------
-- Values supplied by the operator (shipping/threshold); pickup deliberately
-- ignored. Store pickup is NOT modelled.
INSERT INTO VSKR_shops
    (name, slug, website_url, shipping_cost, free_shipping_threshold, active,
     affiliate_enabled, source_type, source_url, source_scope)
VALUES
    ('Lootforge', 'lootforge', 'https://lootforge.de', 5.99, 100.00, 1,
     0, 'shopify',
     'https://lootforge.de/collections/zubehor-furs-malen/products.json?limit=250',
     'zubehor-furs-malen')
ON DUPLICATE KEY UPDATE
    name                    = VALUES(name),
    website_url             = VALUES(website_url),
    shipping_cost           = VALUES(shipping_cost),
    free_shipping_threshold = VALUES(free_shipping_threshold),
    active                  = 1,
    -- Affiliate explicitly stays OFF on every re-run:
    affiliate_enabled       = 0,
    source_type             = VALUES(source_type),
    source_url              = VALUES(source_url),
    source_scope            = VALUES(source_scope);
