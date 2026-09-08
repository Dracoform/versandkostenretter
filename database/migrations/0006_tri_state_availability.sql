-- ============================================================================
-- MIGRATION 0006 — VSKR_products: tri-state availability
--
-- Target: MySQL 8.4. VSKR namespace ONLY. Run MANUALLY by the operator.
-- DO NOT run against production until reviewed.
--
-- Semantics (three states, replacing the boolean-only model):
--   available = 1    -> AVAILABLE   (reliable stock data says "buyable")
--   available = 0    -> UNAVAILABLE (reliable stock data says "not buyable")
--   available = NULL -> UNKNOWN     (source exposes no reliable stock status)
--
-- Data-minimal and explicit: missing data never silently means true or false.
-- Public search behavior remains conservative: ONLY available = 1 is shown.
--
-- Existing data compatibility:
--   * existing 1/0 rows keep their meaning unchanged
--   * no NULLs are created retroactively; rows imported by sources that later
--     report no availability data will use NULL from then on
--
-- Safe properties:
--   * touches only VSKR_products
--   * additive; existing rows keep their values
--   * idempotent via information_schema check (second run = no-op)
--   * index unchanged: (shop_id, available, price) — NULL values are simply
--     excluded by the frontend's available = 1 filter
-- ============================================================================

SET @col := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'VSKR_products'
      AND COLUMN_NAME = 'available'
);
-- Sanity guard: the column must exist (it is part of the base schema). If it
-- somehow doesn't, create it as NULLable — the tri-state representation.
SET @ddl := IF(
    @col = 0,
    'ALTER TABLE VSKR_products ADD COLUMN available TINYINT(1) NULL DEFAULT NULL',
    'SELECT ''VSKR_products.available exists — tri-state ready'' AS note'
);
PREPARE vskr_mig FROM @ddl; EXECUTE vskr_mig; DEALLOCATE PREPARE vskr_mig;

-- Relax NOT NULL if a previous schema version made it NOT NULL:
SET @is_nullable := (
    SELECT IS_NULLABLE FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'VSKR_products'
      AND COLUMN_NAME = 'available'
);
SET @ddl := IF(
    @is_nullable = 'NO',
    'ALTER TABLE VSKR_products MODIFY available TINYINT(1) NULL DEFAULT NULL',
    'SELECT ''available already nullable — nothing to do'' AS note'
);
PREPARE vskr_mig FROM @ddl; EXECUTE vskr_mig; DEALLOCATE PREPARE vskr_mig;

-- Update the schema-comment contract (documentation only):
--   1 = AVAILABLE, 0 = UNAVAILABLE, NULL = UNKNOWN
-- Existing values remain semantically unchanged.
