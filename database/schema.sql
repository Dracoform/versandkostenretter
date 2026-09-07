-- ============================================================================
-- Versandkostenretter — reproducible schema definition
--
-- Target: MySQL 8.4 (Netcup shared hosting / Plesk), charset utf8mb4.
--
-- CONTRACT (hard operator requirement):
--   * This file only ever creates or alters tables with the prefix "VSKR_".
--   * It must NEVER touch, drop or modify any table outside that namespace.
--   * Run this manually on the shared database. There is NO web-accessible
--     database initializer in this application (by design).
-- ============================================================================

CREATE TABLE IF NOT EXISTS VSKR_shops (
    id                       INT UNSIGNED     NOT NULL AUTO_INCREMENT,
    name                     VARCHAR(190)     NOT NULL,
    slug                     VARCHAR(190)     NOT NULL,
    website_url              VARCHAR(500)     NOT NULL,
    shipping_cost            DECIMAL(10,2)    NOT NULL,
    free_shipping_threshold  DECIMAL(10,2)    NOT NULL,
    active                   TINYINT(1)       NOT NULL DEFAULT 1,
    -- Optional affiliate configuration. DISABLED BY DEFAULT for every shop;
    -- NULL everywhere means "no affiliate program". No real IDs are stored.
    -- url column of VSKR_products ALWAYS holds the canonical merchant URL;
    -- affiliate transformation happens only at link-rendering time.
    affiliate_enabled        TINYINT(1)       NOT NULL DEFAULT 0,
    affiliate_mode           ENUM('query','template') NULL,
    affiliate_param          VARCHAR(64)      NULL,
    affiliate_value          VARCHAR(190)     NULL,
    affiliate_template       VARCHAR(500)     NULL,
    updated_at               TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_vskr_shops_slug (slug),
    KEY ix_vskr_shops_active (active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Shops known to Versandkostenretter (VSKR namespace)';

CREATE TABLE IF NOT EXISTS VSKR_products (
    id             INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    shop_id        INT UNSIGNED  NOT NULL,
    external_id    VARCHAR(190)  NULL,
    name           VARCHAR(500)  NOT NULL,
    url            VARCHAR(1000) NOT NULL,
    price          DECIMAL(10,2) NOT NULL,
    available      TINYINT(1)    NOT NULL DEFAULT 1,
    category       VARCHAR(190)  NULL,
    image_url      VARCHAR(1000) NULL,
    last_seen_at   TIMESTAMP     NULL DEFAULT NULL,
    updated_at     TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    -- Existing useful index (kept verbatim):
    KEY ix_vskr_products_shop_avail_price (shop_id, available, price),
    KEY ix_vskr_products_external (shop_id, external_id),
    CONSTRAINT fk_vskr_products_shop
        FOREIGN KEY (shop_id) REFERENCES VSKR_shops (id)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Product catalogue per shop (VSKR namespace)';
