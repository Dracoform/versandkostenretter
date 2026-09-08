-- ============================================================================
-- MIGRATION 0007 — Lootforge source: COMPLETE catalogue scope
--
-- Target: MySQL 8.4. VSKR namespace ONLY. Run MANUALLY by the operator.
-- DO NOT run against production until reviewed.
--
-- Changes Lootforge's import source from the single collection
--   /collections/zubehor-furs-malen/products.json?limit=250
-- to the shop-wide Shopify catalogue feed:
--   https://lootforge.de/products.json?limit=250
--
-- The adapter detects "shop origin" vs "collection URL" purely from the URL
-- shape; with the shop origin the importer paginates the complete catalogue
-- (verified against the real storefront: 4 pages, 971 products, page 5 empty)
-- and stale handling operates at complete-shop scope (source_scope becomes
-- 'complete').
--
-- IMPORTANT SCOPE NOTE:
--   switching the scope means the FIRST complete import will insert the whole
--   catalogue as new rows for the new source_scope ('complete') — the old
--   collection-scoped rows (source_scope = 'zubehor-furs-malen') are not
--   reused. If desired, purge them afterwards with the documented operator
--   procedure:
--     DELETE FROM VSKR_products WHERE shop_id = <lootforge-id>
--       AND source_scope = 'zubehor-furs-malen';
--   (never affects other shops)
--
-- Idempotent; safe to run repeatedly. Affiliate stays DISABLED.
-- ============================================================================

UPDATE VSKR_shops
SET source_type   = 'shopify',
    source_url    = 'https://lootforge.de/products.json?limit=250',
    source_scope  = 'complete',
    -- never enable affiliate/images here:
    affiliate_enabled = 0,
    product_images_enabled = 0
WHERE slug = 'lootforge';

-- Report what was changed (safe on re-run):
SELECT id, name, slug, source_type, source_url, source_scope, affiliate_enabled, product_images_enabled
FROM VSKR_shops WHERE slug = 'lootforge';
