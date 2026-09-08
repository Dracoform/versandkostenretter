-- ============================================================================
-- MIGRATION 0008 — VSKR_product_categories: many-to-many category membership
--
-- Target: MySQL 8.4. VSKR namespace ONLY. Run MANUALLY by the operator.
-- DO NOT run against production until reviewed.
--
-- Why: Shopify products may belong to MULTIPLE collections; a single
-- `category` column silently discards that membership. This table stores
-- every (shop, product, category) edge from merchant-defined collections.
--
-- The existing VSKR_products.category column is KEPT (primary/display
-- category, backward compatibility); the category filter matches either the
-- column or any membership row.
--
-- Idempotent: CREATE TABLE IF NOT EXISTS. Re-imports replace a shop's
-- memberships (delete + insert inside the import transaction), so removed
-- memberships never remain stale. The FK cascades if VSKR_shops is ever
-- cleaned up.
-- ============================================================================

CREATE TABLE IF NOT EXISTS VSKR_product_categories (
    shop_id     INT UNSIGNED NOT NULL,
    external_id VARCHAR(190) NOT NULL,
    category    VARCHAR(190) NOT NULL,
    PRIMARY KEY (shop_id, external_id, category),
    CONSTRAINT fk_vskr_pc_shop
        FOREIGN KEY (shop_id) REFERENCES VSKR_shops (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Many-to-many product <-> merchant category/collection membership';
