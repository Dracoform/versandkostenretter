-- ============================================================================
-- MIGRATION 0002 — VSKR_products: import provenance + idempotency
--
-- Target: MySQL 8.4. VSKR namespace ONLY. Run MANUALLY by the operator.
-- DO NOT run against production until reviewed. NOT required for the web
-- frontend; required before the first real product import.
--
-- Adds:
--   source_type  VARCHAR(50)  NULL       -- e.g. 'shopify'
--   source_scope VARCHAR(190) NOT NULL DEFAULT 'default'
--                               -- e.g. 'zubehor-furs-malen' (collection handle)
--   UNIQUE (shop_id, source_type, source_scope, external_id)
--                               -- makes imports idempotent (no duplicates)
--
-- Safe properties:
--   * touches only VSKR_products
--   * additive; source_scope has a default so existing rows keep working
--   * NULL source_type rows (manual/legacy) are exempt from the UNIQUE key
--     (MySQL treats NULLs as distinct)
--   * the old non-unique helper index ix_vskr_products_external is replaced
--     by the new UNIQUE key which covers the same leading columns
--   * idempotent via information_schema checks (second run = no-op)
-- ============================================================================

SET @col_source_type := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'VSKR_products'
      AND COLUMN_NAME = 'source_type'
);
SET @ddl := IF(
    @col_source_type = 0,
    'ALTER TABLE VSKR_products
        ADD COLUMN source_type VARCHAR(50) NULL,
        ADD COLUMN source_scope VARCHAR(190) NOT NULL DEFAULT ''default''',
    'SELECT ''VSKR_products already has source columns — nothing to do'' AS note'
);
PREPARE vskr_mig FROM @ddl; EXECUTE vskr_mig; DEALLOCATE PREPARE vskr_mig;

-- Replace the non-unique helper index with the idempotency UNIQUE key.
SET @idx_old := (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'VSKR_products'
      AND INDEX_NAME = 'ix_vskr_products_external'
);
SET @ddl := IF(
    @idx_old > 0,
    'ALTER TABLE VSKR_products DROP INDEX ix_vskr_products_external',
    'SELECT ''ix_vskr_products_external not present — nothing to do'' AS note'
);
PREPARE vskr_mig FROM @ddl; EXECUTE vskr_mig; DEALLOCATE PREPARE vskr_mig;

SET @idx_new := (
    SELECT COUNT(DISTINCT INDEX_NAME) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'VSKR_products'
      AND INDEX_NAME = 'uq_vskr_products_source'
);
SET @ddl := IF(
    @idx_new = 0,
    'ALTER TABLE VSKR_products
        ADD UNIQUE KEY uq_vskr_products_source (shop_id, source_type, source_scope, external_id)',
    'SELECT ''uq_vskr_products_source already present — nothing to do'' AS note'
);
PREPARE vskr_mig FROM @ddl; EXECUTE vskr_mig; DEALLOCATE PREPARE vskr_mig;
