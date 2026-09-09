# Import and adapters

How the importer fetches merchant data, how memberships work, and why the
importer fails closed. Code: `src/Import/`, entry point `bin/import-shop.php`.

## Pipeline

```
bin/import-shop.php <slug>
  -> config + DB, shop lookup, MySQL name-lock (one import per shop)
  -> SourceAdapterRegistry -> ShopifyAdapter (wrapped in RetryingSourceFetcher)
  -> fetchAll($shop) -> SourceFetchResult
  -> ImportOrchestrator::import()
       -> ImportRepository::upsertProducts()      [single transaction]
       -> markStaleUnavailable()                   [complete runs only]
       -> replaceCategoryMemberships()             [complete runs, see below]
  -> result report (CLI output)
```

Any exception before the first write aborts the run with a non-zero exit code
and **no** database changes (fail-closed). The stale-marking transaction is
owned by `markStaleUnavailable()`; `DROP TEMPORARY TABLE` (not plain
`DROP TABLE`) is used inside it — a plain `DROP TABLE` would implicitly commit
in MySQL/MariaDB.

## Shop configuration

The `VSKR_shops` row drives everything:

| Column | Meaning |
|---|---|
| `source_type` | `'shopify'` (currently the only adapter) |
| `source_url` | feed URL (`https://` enforced) |
| `source_scope` | `'complete'` = full catalogue, or a collection handle; scopes stale-marking |
| `active` | 0 = shop hidden publicly, import rejected with exit code 6 |

## Shopify adapter (`ShopifyAdapter`)

`source_scope = 'complete'` (full catalogue):

1. **Product pagination:** `GET /products.json?limit=250&page=N` until a short
   page (< 250 items) or an empty page. Loop guard: max 60 pages (15000
   products). Duplicate `external_id`s within the run are skipped and reported.
2. **Collection discovery:** `GET /collections.json?limit=250` — all merchant
   collections. Utility collections are excluded via a small blocklist
   (`startseite`, `homepage`, `frontpage`, `all`, `products`); handles that
   look like URLs are rejected.
3. **Collection products:** for every remaining collection
   `GET /collections/<handle>/products.json?limit=250`. There is a 500 ms
   pause between collection requests (see retry/rate limiting below).
4. **Membership mapping:** each collection product's `handle` is matched
   against the handle extracted from the catalogue product's `canonicalUrl`;
   the resulting membership is recorded for the catalogue row's
   `external_id` (= Shopify numeric id as string). A product in multiple
   collections gets multiple memberships; duplicates within a collection are
   deduplicated.

**Request count:** 1 discovery request + roughly one request per collection
(plus catalogue pages). For Lootforge (~96 collections, ~978 products) that is
~100 requests per complete import. The public Shopify storefront API has no
bulk product→collection endpoint, so one request per collection is
fundamentally required — the earlier "~6 requests" estimate was wrong.

`source_scope != 'complete'` (legacy single-collection scope): one request to
`source_url`, no collection enrichment.

## `SourceFetchResult::$categoryMemberships` — fail-closed semantics

| Value | Meaning | Orchestrator behaviour |
|---|---|---|
| `array` (may be empty) | enrichment ran end-to-end; this is the **authoritative** set. Empty = the merchant genuinely has no usable collections | `replaceCategoryMemberships()` (delete + insert per shop) |
| `null` | enrichment unavailable or untrustworthy: discovery failed/unparsable, any single collection fetch/parse failed, or transport error | **memberships skipped** — previously persisted rows are KEPT, run reports `memberships_skipped` + `enrichmentWarnings` |

Rationale: a partial membership map (e.g. 90 of 95 collections fetched, then a
503) must never replace known-good data, because the replace would silently
drop all memberships of the failed collections. Partial results are therefore
never trusted. Enrichment problems are reported as warnings — they are **not**
counted as import `Errors`, because the product import itself succeeded.

## Retry / rate limiting / observability

`RetryingSourceFetcher` wraps the `HttpClient` used by the adapter:

- **Retried:** HTTP 429 and HTTP 5xx (transient). Max **3 attempts total** per
  request.
- **429:** `Retry-After` header honoured when parseable (clamped to 1–30 s);
  fallback backoff **5 s, 10 s**.
- **5xx:** short backoff **1 s, 2 s**.
- **Permanent 4xx** (400/401/403/404): never retried.
- After exhausted retries the last `SourceException` propagates → the adapter's
  fail-closed path applies (memberships `null`, existing data kept, warning
  reported).
- **Throttle:** 500 ms pause between collection-product requests (strictly
  serial; real-world bursts of ~95 requests triggered 429/503 without it).

**CLI "Requests" semantics:** the number counts *logical adapter requests*
(catalogue pages + discovery + collection requests), as incremented by the
adapter. Physical retry attempts inside `RetryingSourceFetcher` are **not**
added. A run reporting `Requests: 98` made up to 98 additional physical
requests through retries.

## CLI output semantics (`bin/import-shop.php`)

```
Shop: Lootforge
Source: shopify
Fetched: 978          products seen (products + skipped)
Inserted: 0           new rows
Updated: 978          existing rows updated (idempotent re-import)
Unavailable: 0        rows marked available=0 (absent from COMPLETE run)
Skipped: 0            items rejected during mapping (reasons go to STDERR)
Errors: 0             hard import errors (source/db) — see note below
Requests: 98          logical adapter requests (retries not included)
```

Note: `Errors: 0` does **not** imply successful membership enrichment. The
enrichment state is reported separately and unmissably:

```
Memberships: 1629 rows synced (OK)
```
or, when fail-closed triggered:
```
Membership sync: SKIPPED — collection enrichment failed; previously persisted memberships were KEPT.
Enrichment warnings: 1
  - collection fetch failed: <handle> (Server error HTTP 503 from source.)
```
For sources without collection support: `Membership sync: not applicable for
this source`.

## Adding a new adapter

See [architecture.md § Contract for a NEW SHOP](architecture.md#contract-for-a-new-shop).
Required: `SourceAdapter` implementation, registration in `bin/import-shop.php`
and `bin/catalogue-snapshot.php`, correct `NormalizedProduct` mapping, honest
`SourceFetchResult` (complete flag, memberships null-vs-array), and tests
covering mapping, pagination, duplicates, membership persistence and
fail-closed behaviour.

## Deliberate non-goals

- No live availability checking during searches (the `AvailabilityChecker`
  capability interface exists but is deliberately not wired into imports or
  page rendering).
- No interpretation of merchant names/categories (merchant data is opaque).
- No automatic deletion of products (stale rows are flagged, never removed).
- No parallel requests to the merchant.
