# Architecture

Data flow and components of the Versandkostenretter frontend and importer, as
implemented on `main`. See [SPEC.md](../SPEC.md) for product behaviour and
[import-and-adapters.md](import-and-adapters.md) for the importer details.

## Data flow

```
Merchant (e.g. lootforge.de, Shopify storefront)
  -> SourceFetcher (HttpClient + RetryingSourceFetcher)   [import only]
  -> SourceAdapter (ShopifyAdapter)                       [import only]
  -> NormalizedProduct / SourceFetchResult                [import only]
  -> ImportOrchestrator
  -> ImportRepository -> MySQL (VSKR_ tables)
  -> ProductRepository                                    [frontend reads]
  -> index.php + templates/ -> rendered HTML
  -> /go/<external_id> -> OutboundLink -> Merchant        [user click]
```

The frontend never talks to the merchant. It reads exclusively from the local
`VSKR_` tables. All merchant interaction happens during imports.

## Components

### Front controller — `index.php`

Single HTTP-executable PHP file (repository root = document root). Routes:

- `/?shop=<slug>&cart=<value>[&category=…][&expanded=1][&page=N]` — results
- `/` — homepage with shop/cart form
- `/?page=impressum|datenschutz|projekt` — static pages (placeholder fields)
- `/?...&health` — health check
- `/go/<external_id>` — privacy-preserving outbound click endpoint: increments
  the aggregate counter (`VSKR_stats`), then 302-redirects to the canonical
  merchant URL (or affiliate-transformed URL if the shop has affiliate
  configuration enabled — disabled by default for every shop).

No session, no CSRF token, no cookies anywhere. All filter/pagination state
lives in GET parameters.

### `src/` — application logic

| Class | Purpose |
|---|---|
| `Money` | integer-cent parsing/formatting (never float money) |
| `Cart` | missing-amount calculation, threshold check |
| `CategoryFilter` | case-insensitive category resolution, safe fallback to "Alle" |
| `ProductRepository` | eligibility queries: `eligibleProducts()` (paginated), `countEligible()` (unlimited total), `visibleCategories()` (facets with counts) — all sharing `buildEligibilityWhere()` |
| `ShopRepository` | read access to `VSKR_shops` incl. affiliate/image config |
| `OutboundLink` | affiliate URL transformation (off by default), safe URLs only |
| `StatsRepository` | aggregate click counter (`VSKR_stats`), no per-click data |
| `View` | output escaping, safe-URL checks |
| `Assets` | local asset URLs with mtime cache busting |
| `Database` | PDO factory, VSKR_-namespace assertion, credentials from config |

### `src/Import/` — importer

See [import-and-adapters.md](import-and-adapters.md).

## Eligibility and the shared WHERE builder

All three read paths (product page, total count, category facets) use the same
private `ProductRepository::buildEligibilityWhere()`:

- `shop_id = :shop_id`
- `available = 1` (unavailable = 0 and UNKNOWN = NULL are both excluded)
- `price >= :min_price` (always `missing`)
- strict mode additionally: `price <= missing + 2 EUR`
- optional category: matches the `VSKR_products.category` column **or** any
  `VSKR_product_categories` membership row (case-insensitive)

This guarantees the total count, the paginated product list and the category
counts never drift apart.

## Sorting

```
ORDER BY price ASC, name ASC, external_id ASC
```

Deterministic across pages (same data → same page assignment). The name sort
is case-insensitive via the `utf8mb4_unicode_ci` collation — no expression in
SQL, no normalization in PHP.

**Merchant data is opaque** — see [SPEC.md § 6 "Sorting"](../SPEC.md#6-sorting--merchant-data-is-opaque).
This order is a deliberate, documented design decision; do not "improve" it
with prefix stripping or semantic rules.

## Categories / facets — `visibleCategories()`

Single SQL query (no N+1): a `UNION ALL` of both assignment paths
(`VSKR_products.category` fallback and `VSKR_product_categories` memberships,
each joined against eligibility-qualified products) aggregated with
`GROUP BY category, COUNT(DISTINCT external_id)`.

Returns `list<array{name: string, count: int}>`:

- only categories with ≥ 1 eligible product in the current price mode
  (visibility is context-dependent, never a taxonomy change)
- counts are direct per-category counts (no hierarchy, no roll-up)
- a product in `Paint` and `Speedpaint` counts once for each; a product
  counted via both column and membership for the same category counts once
- independent of `page` and of the currently selected category
- alphabetical by name, never by count

The template renders `<option value="Speedpaint">Speedpaint (90)</option>` —
the value stays the exact merchant category name, the count is label-only.

## Database contract

- MySQL 8.4 (production) / MariaDB 10.11 (local integration tests). Only
  `utf8mb4` tables with the `VSKR_` prefix; `Database::assertOnlyVskrTables()`
  guards this.
- Schema source of truth: `database/schema.sql` (reproduces all tables).
  Incremental changes: `database/migrations/00NN_*.sql`, applied manually by
  the operator (see [operations.md](operations.md)).
- Frontend: reads only. Writes: import CLI only.

Central tables (high level):

| Table | Purpose |
|---|---|
| `VSKR_shops` | shop identity, shipping cost/threshold, `active` flag, source config (`source_type`, `source_url`, `source_scope`), affiliate config (disabled by default), `product_images_enabled` |
| `VSKR_products` | catalogue rows: `external_id`, `name`, `url`, `price`, tri-state `available` (1/0/NULL), `category` fallback column, `image_url`, provenance (`source_type`, `source_scope`) |
| `VSKR_product_categories` | many-to-many memberships `(shop_id, external_id, category)`, PK dedupes, FK cascades with the shop |
| `VSKR_stats` | aggregate counters (e.g. `outbound_product_clicks`) — no per-click data |

## Contract for a NEW SHOP

Adding a shop whose source is a supported type requires **no code changes**:

1. Insert/configure the `VSKR_shops` row (idempotent migration or manual SQL):
   - `slug` (used in URLs), `name`, `website_url`
   - `shipping_cost`, `free_shipping_threshold` (used verbatim, no rule engine)
   - `source_type` = `'shopify'` (currently the only adapter),
     `source_url` = feed URL, `source_scope` = `'complete'` for a full
     catalogue (scope names the stale-marking namespace)
   - `affiliate_*` stays NULL/0 unless a program exists; `product_images_enabled`
     defaults to 0 (merchant images off unless explicitly allowed)
2. Run `php bin/import-shop.php <slug> --dry-run` first, then the real import.

A **new adapter** is only needed when the source is *not* a Shopify storefront:

- implement `SourceAdapter` (`type(): string`, `fetchAll(array $shop): SourceFetchResult`)
- register it in `bin/import-shop.php` (and `bin/catalogue-snapshot.php`)
- return `NormalizedProduct` list with:
  - `externalId` — stable merchant product identifier (Shopify: numeric `id`
    as string); uniqueness within `(shop_id, source_type, source_scope)` is
    enforced by a UNIQUE key and drives idempotent upserts
  - `name` — merchant title **verbatim** (merchant data is opaque)
  - `canonicalUrl` — https product URL built from the merchant handle
  - `priceCents` + `availabilityState` — one of `available` / `unavailable` /
    `unknown` (UNKNOWN never appears in public search)
  - `category` (optional fallback), `imageUrl` (optional, https-only)
- `SourceFetchResult::$categoryMemberships` semantics (fail-closed — see
  [import-and-adapters.md](import-and-adapters.md)):
  - `null` = enrichment unavailable/untrustworthy → existing memberships kept
  - `array` = authoritative complete set → replaces the shop's memberships
    (an empty array is legitimate for shops without collections)
- `complete: true` is the precondition for stale marking; partial results must
  throw, never write.

Generic frontend features (pagination, strict/expanded, category counts,
facet visibility, sorting) work with any correctly normalized data — no shop
special-casing in the frontend.

Required tests for a new adapter: fetch/map/normalize cases, pagination
handling, duplicate detection, category-membership persistence (MariaDB suite
`run_membership_mariadb_tests.php` is the pattern), fail-closed on partial
enrichment.
