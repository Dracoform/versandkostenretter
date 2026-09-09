# SPEC — Versandkostenretter

Status: production (Lootforge catalogue live; 978 products, memberships synced).

## 1. Purpose

Help a user who already has a shopping cart at one specific shop find cheap
additional products **in that same shop** to reach the free-shipping
threshold. The user always picks the shop first; other shops are never
suggested or compared.

## 2. Core calculation

```
missing_amount = free_shipping_threshold − current_cart_value
```

- If `current_cart_value >= threshold` → the user is told shipping is already
  free. No products are shown.
- For every product additionally:
  `effective_extra_cost = product_price − standard_shipping_cost`.
  The UI explains this as "Produktpreis minus Versandkosten, die du sonst
  zahlen würdest" and never claims the user saves the full product price.

All money values are handled as **integer cents** internally (see
`src/Money.php`). No float arithmetic on money anywhere.

## 3. Result query, price modes

Two price modes; both share the same eligibility rules (shop, `available = 1`,
UNKNOWN availability excluded, optional category/collection filter):

- **Strict (default):** `missing <= price <= missing + 2 EUR` — products that
  just push the cart over the threshold.
- **Expanded** (`&expanded=1`, "Mehr Auswahl anzeigen"): `price >= missing`,
  no upper bound. Expanded only lifts the *upper* price limit.

The result text always shows the **unlimited total** (`countEligible()` with
the exact same filter conditions as the product query), never the number of
products on the current page: e.g. "253 passende Produkte gefunden – Seite 1
von 26". With a single page: "7 passende Produkte gefunden".

## 4. Reference example (test data)

Shop: Games Island (TEST DATA), shipping 5.99 €, free from 150.00 €.
Cart 125.34 € → missing 24.66 €.

| product price | qualifies? |
|---------------|------------|
| 24.49         | no (< 24.66) |
| 24.66         | yes (boundary) |
| 24.69         | yes |
| 24.99         | yes |
| 25.49         | yes |
| 24.70 (available = 0) | never (unavailable) |
| any price, other shop | never (shop scoping) |

## 5. Pagination

- 10 products per page, `LIMIT 10 OFFSET (page-1)*10` in SQL — never the full
  result set loaded into PHP and sliced.
- URL state is fully carried in GET parameters (`shop`, `cart`, `category`,
  `expanded`, `page`). No session, no cookies.
- Invalid pages are clamped safely: `page < 1` → 1, `page > last` → last,
  non-numeric → 1.
- Changing shop / cart value / category / price mode resets to page 1; pure
  page changes keep all other filters.
- With only one page no pagination is rendered.
- Pagination must never influence category counts or facet lists (they always
  use the full eligible set).

## 6. Sorting — merchant data is opaque

Final deterministic order:

1. `price ASC`
2. full product name ASC (case-insensitive via `utf8mb4_*_ci` collation)
3. `external_id ASC` (stable final tie-breaker)

**Design decision: merchant data is opaque.** Product names and categories
are never "intelligently" interpreted, normalized, renamed or hierarchized.
No prefix/suffix stripping, no name heuristics, no product-type detection.

Examples that stay exactly as the merchant provides them:

- `B: ABADDON BLACK 12ML`
- `NECRON COMPOUND 12ML`
- `PK-Aluminiumpalette-Eckig-6-Näpfe-(8x13cm)`

`B:` works for some Lootforge base colours but is **not** a general scheme;
other categories use `S:`, `Shade`, `CONTRAST:` or no prefix at all. Deriving
rules from names would silently corrupt merchant data, so we don't.

## 7. Categories / facets

- Categories/collections come from the merchant (Lootforge: Shopify
  collections). Products may belong to **multiple** categories.
- Memberships are persisted separately in `VSKR_product_categories`
  (many-to-many). The legacy `VSKR_products.category` column is kept as
  fallback. The full merchant taxonomy is never modified, renamed, grouped
  or deleted.
- **Visibility is not taxonomy:** the dropdown shows only categories with at
  least one eligible product under the *current* price mode. If the merchant
  has 88 persisted categories but only 3 have strict-window hits, the dropdown
  shows `Alle` + those 3. Expanded can make up to all 88 visible; back to
  strict shrinks the list again. Nothing is ever deleted.
- **Counts:** each visible category shows its own eligible product count,
  e.g. `Speedpaint (90)`.
  - direct membership only — no hierarchy, no accumulation
  - one product in `Paint` AND `Speedpaint` counts once for each
  - a product counted twice via column + membership for the same category
    counts once (`COUNT(DISTINCT external_id)`)
  - therefore the sum of category counts does NOT equal the total
  - counts depend on strict/expanded, never on `page`
  - selecting a category filters the product list but does not shrink the
    facet list to itself
- `Alle` never carries a count (the total is shown in the result text).

## 8. Shipping assumption

No shipping-rule engine. The shop's stored `shipping_cost` and
`free_shipping_threshold` are used verbatim. The results page states:

> Aus technischen Gründen beziehen sich alle Berechnungen auf die
> Standardlieferung per DHL. Abweichende Versandarten, Sperrgut-, Auslands-
> oder sonstige Sonderversandkosten werden nicht berücksichtigt.

## 9. Architecture

- PHP 8.4, MySQL 8.4, PDO with prepared statements only (read-only usage).
- Front controller `index.php` (repository root = document root) + PHP
  templates, semantic HTML.
- One modern CSS file, one small vanilla JS file (progressive enhancement
  only — the forms work without JavaScript).
- No frameworks, no npm/build chain, no Redis, no Docker, no external
  services.

```
(Plesk: repository root IS the document root /versandkostenretter.de/httpdocs)
index.php          front controller (/, ?page=impressum|datenschutz|projekt,
                   ?...&health, /go/<id> outbound endpoint)
.htaccess          blocks src/templates/tests/database/config/assets-design/bin,
                   sensitive file types (incl. .md), security headers
router.php         dev-server router only (denied over HTTP)
assets/            css/, js/, images/ (all local, no CDN)
src/               Money, Cart, CategoryFilter, View, Database,
                   Shop/ProductRepository, OutboundLink, StatsRepository,
                   Import/ (adapters, orchestrator, repositories)
templates/         home, results, impressum, datenschutz, projekt, 404, layout
config/            config.example.php (committed); real config.php lives
                   OUTSIDE the document root: /versandkostenretter.de/config/config.php
database/          schema.sql, seed-development.sql, migrations/
bin/               CLI tools (import-shop, run-migration, shop-toggle,
                   stats, catalogue-snapshot) — HTTP-denied
tests/             framework-free tests + HTTP exposure/no-cookie probes
assets-design/     original design references (never web-accessible)
```

## 10. Database contract

- Only tables prefixed `VSKR_` are referenced; `Database::assertOnlyVskrTables()`
  is tested to flag any other table name.
- Central tables: `VSKR_shops` (shop + source + affiliate + image config),
  `VSKR_products` (catalogue, tri-state availability, source provenance),
  `VSKR_product_categories` (many-to-many memberships), `VSKR_stats`
  (aggregate counters).
- The application frontend performs reads only. Writes happen exclusively
  through the import CLI, which is fail-closed (see
  [docs/import-and-adapters.md](import-and-adapters.md)).
- Credentials live in `config/config.php` (gitignored, outside the document
  root). `config/config.example.php` is the committed template without secrets.

## 11. Security & privacy

- Prepared statements, server-side validation, output escaping
  (`htmlspecialchars` via `View::e`), URL whitelist (http/https only, plus a
  loopback exception for local test servers) for product links and images.
- **No sessions, no CSRF token, no cookies at all.** Search, pagination and
  category filtering are fully stateless GET parameters (an earlier CSRF
  session design was removed; `index.php` documents this in its header).
- `display_errors=0`; production errors are generic, details go to the error
  log only.
- No analytics, tracking, ads, external fonts, CDNs, social widgets, embeds,
  contact forms, newsletter or accounts.
- Affiliate functionality exists in `OutboundLink` (query/template modes) but
  is **disabled by default for every shop** (`affiliate_enabled = 0`); with no
  configuration the canonical URL is returned unchanged. No click tracking,
  no redirects operated by us.
- Aggregate outbound-click statistics (`VSKR_stats`) contain no per-click
  rows, IPs, timestamps, user agents or any user identifiers.

## 12. Placeholder pages

`/?page=datenschutz`, `/?page=impressum` and `/?page=projekt` exist; legal
identity/address fields are clearly marked `TODO (Betreiber)` placeholders.
**No legal identity/address data is invented.**

## 13. Deployment

Source of truth: GitHub. Plesk pulls `main`. Deployment is manual and
operator-driven; nothing in this repository deploys anything. Feature work
lands on feature branches and must be reviewed before merging into `main`.

Documentation (README.md, SPEC.md, docs/) is part of the repository and thus
lands in the deployment directory, but is **blocked from HTTP access** by the
root `.htaccess` (`FilesMatch` for `.md` plus `RedirectMatch 404` for
non-public directories) and verified by `tests/check_http_exposure.php`.
