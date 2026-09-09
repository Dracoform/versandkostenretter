# Design decisions and non-goals

Deliberate, binding decisions — not TODOs. Later contributors and agents
should not "optimize" these away. Each entry exists because a real bug or a
production incident showed the alternative fails.

## 1. Merchant data is opaque

Product names and categories are stored and displayed **verbatim**. No
prefix/suffix stripping (`B:`, `S:`, `12ML`), no semantic normalization, no
product-type detection from names, no renaming, no own taxonomy.

Examples that stay untouched:

- `B: ABADDON BLACK 12ML`
- `NECRON COMPOUND 12ML`
- `PK-Aluminiumpalette-Eckig-6-Näpfe-(8x13cm)`

Rationale: Lootforge names are inconsistent across categories (`B:`, `L:`,
`S:`, `Shade`, `CONTRAST:`, no prefix at all). Any name rule works for one
category and silently corrupts others. The user-facing requirement is a
stable alphabetical order among equal prices — the full-name sort provides
exactly that without touching data.

## 2. Visibility is not taxonomy

The category dropdown shows only categories with eligible products in the
current price mode, but the full merchant taxonomy stays persisted. Never:

- delete or hide categories in the database
- merge, rename, group or deduplicate categories
- invent a hierarchy or parent/child relations
- interpret category semantics

Merchant collections are the truth.

## 3. Category counts: direct, per-category, no roll-up

- Count = eligible products directly assigned to exactly this category
  (`COUNT(DISTINCT external_id)` per category).
- A product in `Paint` and `Speedpaint` counts once for each — therefore the
  sum of category counts does not equal the total. This is correct.
- Counts follow the current strict/expanded mode and never the current page.
- `Alle` never carries a count (the total lives in the result text).

## 4. Stateless frontend

No sessions, no cookies, no CSRF token for search/pagination/filters. All
state is in GET parameters (`shop`, `cart`, `category`, `expanded`, `page`),
making result URLs shareable and bookmarkable. Privacy by design: no
analytics, no tracking, no external requests.

## 5. Fail-closed enrichment — never destroy known-good data

`SourceFetchResult::$categoryMemberships`:

- non-null array = authoritative set → replace shop memberships
- `null` = enrichment untrustworthy (discovery/parse/any collection fetch
  failed) → **keep previously persisted memberships**, report warnings

A partial map must never replace known-good data (it would silently drop the
failed collections' memberships). Enrichment failures are visible in the CLI
output but are not import `Errors`, because the product import succeeded.

Single transaction owner: the import transaction spans upserts, stale marking
and membership replacement; helpers never commit/roll back on their own.

## 6. Deterministic ordering over "smart" ordering

`price ASC, name ASC, external_id ASC`. Ties are broken by data, never by
import order or heuristics. Pagination requires that a product never jumps
between pages with identical data.

## 7. Minimal shop-specific frontend

Pagination, price modes, category facets/counts and sorting are generic and
work with any correctly normalized data. Shop-specific knowledge lives only in
the shop's `VSKR_shops` row and, if unavoidable, in the shop's adapter — never
in templates or the product repository.

## 8. Fail-closed imports

Any source exception aborts the import before the first write. Stale marking
only happens for COMPLETE runs, scoped to `(shop_id, source_type,
source_scope)`; products are flagged, never deleted. A failed fetch never
changes availability.

## 9. Availability is tri-state

`available`: 1 = AVAILABLE, 0 = UNAVAILABLE, NULL = UNKNOWN. UNKNOWN never
appears in public search. "Missing data" never becomes "unavailable".

## 10. No frameworks, no build chain

Pure PHP 8.4, PDO, semantic HTML, one CSS file, one small vanilla JS file
(progressive enhancement only). No Composer/npm/Redis/Docker/external
services. Deployed as plain files via Plesk; no deploy automation in the repo.
