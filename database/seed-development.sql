-- ============================================================================
-- Versandkostenretter — DEVELOPMENT / TEST SEED
--
-- ⚠  ALL DATA IN THIS FILE IS FICTIONAL SAMPLE DATA.
--    Shop names, prices and shipping values are NOT verified current
--    merchant information. They exist only so the application can be
--    exercised locally / in CI.
--
-- SAFE BY CONSTRUCTION:
--   * only touches VSKR_-prefixed tables
--   * inserts are idempotent-ish for a fresh dev database
--   * never drops or alters anything outside the VSKR_ namespace
--
-- Example scenario: cart 125.34 EUR, threshold 150.00 EUR
--   -> missing amount 24.66 EUR
--   -> seed contains products at 24.49 (must NOT qualify),
--      24.66 / 24.69 / 24.99 / 25.49 (must qualify)
-- ============================================================================

-- ---------------------------------------------------------------- shop 1 (test)
INSERT INTO VSKR_shops (id, name, slug, website_url, shipping_cost, free_shipping_threshold, active)
VALUES (9001, 'Games Island (TEST DATA)', 'games-island-test',
        'https://example.com/games-island', 5.99, 150.00, 1)
ON DUPLICATE KEY UPDATE
        name = VALUES(name), shipping_cost = VALUES(shipping_cost),
        free_shipping_threshold = VALUES(free_shipping_threshold), active = 1;

-- ------------------------------------------------- products for shop 1 (test)
-- price 24.49  -> BELOW missing amount 24.66  -> must NOT appear in results
INSERT INTO VSKR_products
    (shop_id, external_id, name, url, price, available, category, image_url)
VALUES
    (9001, 'TEST-0001', '[TEST] Kleines Würfelset',            'https://example.com/p/1', 24.49, 1, 'Zubehör', NULL),
    (9001, 'TEST-0002', '[TEST] Basisspiel (Ausstellung)',     'https://example.com/p/2', 24.66, 1, 'Brettspiel', NULL),
    (9001, 'TEST-0003', '[TEST] Erweiterung Alpha',            'https://example.com/p/3', 24.69, 1, 'Erweiterung', NULL),
    (9001, 'TEST-0004', '[TEST] Erweiterung Beta',             'https://example.com/p/4', 24.99, 1, 'Erweiterung', NULL),
    (9001, 'TEST-0005', '[TEST] Spielmatte Standard',          'https://example.com/p/5', 25.49, 1, 'Zubehör', NULL),
    -- unavailable product at qualifying price -> must NEVER appear
    (9001, 'TEST-0006', '[TEST] Ausverkauftes Sonderangebot',  'https://example.com/p/6', 24.70, 0, 'Sale', NULL),
    -- below threshold, should appear for other (higher) missing amounts only
    (9001, 'TEST-0007', '[TEST] Kartenschutzhüllen (50)',      'https://example.com/p/7',  4.99, 1, 'Zubehör', NULL);

-- ---------------------------------------------------------------- shop 2 (test)
INSERT INTO VSKR_shops (id, name, slug, website_url, shipping_cost, free_shipping_threshold, active)
VALUES (9002, 'Modellbau Hinterhof (TEST DATA)', 'modellbau-test',
        'https://example.com/modellbau', 6.49, 90.00, 1)
ON DUPLICATE KEY UPDATE
        name = VALUES(name), shipping_cost = VALUES(shipping_cost),
        free_shipping_threshold = VALUES(free_shipping_threshold), active = 1;

-- products for shop 2 — must NEVER appear when shop 1 is selected
INSERT INTO VSKR_products
    (shop_id, external_id, name, url, price, available, category, image_url)
VALUES
    (9002, 'TEST-2001', '[TEST] Farben-Set für Modellbauer (ANDERER SHOP)', 'https://example.com/m/1', 24.66, 1, 'Farben', NULL),
    (9002, 'TEST-2002', '[TEST] Kleinteile-Box (ANDERER SHOP)',             'https://example.com/m/2', 25.00, 1, 'Werkzeug', NULL);

-- ---------------------------------------------------------------- inactive shop
INSERT INTO VSKR_shops (id, name, slug, website_url, shipping_cost, free_shipping_threshold, active)
VALUES (9099, 'Deaktivierter Testshop (TEST DATA)', 'inaktiv-test',
        'https://example.com/inaktiv', 4.99, 50.00, 0)
ON DUPLICATE KEY UPDATE name = VALUES(name), active = 0;
