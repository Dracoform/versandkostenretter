-- ============================================================================
-- MIGRATION 0001 — VSKR_shops: optional affiliate configuration
--
-- Target: MySQL 8.4. VSKR namespace ONLY. Run MANUALLY by the operator.
-- DO NOT run against production until reviewed.
--
-- Safe properties:
--   * touches only VSKR_shops
--   * additive (new nullable / default-off columns) — no data loss
--   * idempotent via the information_schema check (second run = no-op)
--
-- After migration every shop remains in the DEFAULT state: affiliate DISABLED.
-- No real affiliate IDs/codes are configured by this migration.
-- ============================================================================

SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'VSKR_shops'
      AND COLUMN_NAME  = 'affiliate_enabled'
);

SET @ddl := IF(
    @col_exists = 0,
    'ALTER TABLE VSKR_shops
        ADD COLUMN affiliate_enabled  TINYINT(1) NOT NULL DEFAULT 0,
        ADD COLUMN affiliate_mode     ENUM(''query'',''template'') NULL,
        ADD COLUMN affiliate_param    VARCHAR(64)  NULL,
        ADD COLUMN affiliate_value    VARCHAR(190) NULL,
        ADD COLUMN affiliate_template VARCHAR(500) NULL',
    'SELECT ''VSKR_shops already has affiliate columns — nothing to do'' AS note'
);

PREPARE vskr_migration_stmt FROM @ddl;
EXECUTE vskr_migration_stmt;
DEALLOCATE PREPARE vskr_migration_stmt;
