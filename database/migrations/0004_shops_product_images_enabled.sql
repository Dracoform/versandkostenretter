-- ============================================================================
-- MIGRATION 0004 — VSKR_shops: per-shop product-image display permission
--
-- Target: MySQL 8.4. VSKR namespace ONLY. Run MANUALLY by the operator.
-- DO NOT run against production until reviewed.
--
-- Adds:
--   product_images_enabled TINYINT(1) NOT NULL DEFAULT 0
--
-- Semantics:
--   * DEFAULT OFF for existing and newly added shops.
--   * This is a DISPLAY permission for the frontend only: merchant product
--     images (VSKR_products.image_url) may be rendered as <img> only when
--     the product's shop has product_images_enabled = 1.
--   * When disabled, the frontend renders a neutral local placeholder and
--     makes NO external image/CDN request.
--   * The importer keeps storing image_url from the source regardless of
--     this flag; existing values are never deleted.
--   * Unrelated to affiliate settings (which stay untouched).
--
-- Lootforge remains DISABLED: no display permission has been obtained.
--
-- Safe properties:
--   * touches only VSKR_shops
--   * additive column with default — no data loss
--   * idempotent via information_schema check (second run = no-op)
-- ============================================================================

SET @col := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'VSKR_shops'
      AND COLUMN_NAME = 'product_images_enabled'
);
SET @ddl := IF(
    @col = 0,
    'ALTER TABLE VSKR_shops
        ADD COLUMN product_images_enabled TINYINT(1) NOT NULL DEFAULT 0',
    'SELECT ''VSKR_shops already has product_images_enabled — nothing to do'' AS note'
);
PREPARE vskr_mig FROM @ddl; EXECUTE vskr_mig; DEALLOCATE PREPARE vskr_mig;
