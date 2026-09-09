# Testing

Framework-free PHP tests: plain scripts with assertions, no Composer, no Node.
Two layers: fast local suites (mostly SQLite) and MariaDB integration suites
(real production semantics).

## Quick reference

```bash
# App logic (fast, SQLite-free):
php tests/run.php

# Importer + adapter suites (local, need pdo_sqlite):
php tests/import/run_import_tests.php
php tests/import/run_adapter_tests.php
php tests/import/run_membership_tests.php
php tests/import/run_transaction_semantics_tests.php
php tests/import/run_cli_importer_tests.php
php tests/import/run_guard_tests.php
php tests/import/run_migration_tests.php
php tests/import/run_form_flow_tests.php
php tests/import/run_cli_bootstrap_tests.php
php tests/import/run_collection_enrichment_tests.php
php tests/import/run_retry_observability_tests.php
php tests/import/run_outbound_render_tests.php
php tests/import/run_counter_tests.php
php tests/import/run_hardening_tests.php
php tests/import/run_autoload_tests.php
php tests/import/run_asset_replacement_tests.php
php tests/import/run_wordmark_tests.php
php tests/import/run_project_page_tests.php
php tests/import/run_visibility_legal_tests.php
php tests/import/run_stats_webp_tests.php
php tests/import/run_shop_assignment_tests.php
php tests/import/run_prod_fix_tests.php

# HTTP exposure / layout (need the local smoke server):
php -S localhost:8081 tests/smoke_server.php   # in a second terminal
php tests/check_http_exposure.php              # .md/src/config blocked over HTTP
php tests/check_no_cookies.php                 # no Set-Cookie anywhere

# MariaDB integration (production semantics; see section below):
source ~/.config/versandkostenretter/test-db.env
/usr/bin/php8.3 tests/import/run_membership_mariadb_tests.php
/usr/bin/php8.3 tests/import/run_pagination_sort_visibility_mariadb_tests.php
/usr/bin/php8.3 tests/import/run_frontend_category_mariadb_tests.php
/usr/bin/php8.3 tests/import/run_results_total_mariadb_tests.php

# Live storefront diagnostics (read-only, no DB, rate-limited by Shopify):
/usr/bin/php8.3 tests/import/diag_real_lootforge.php
```

## Local suites (default)

Plain PHP scripts, each printing `PASS/FAIL` lines and exiting 0/1. Most run
against in-memory or temp-file SQLite databases and need the
`pdo_sqlite`-capable PHP binary on the host. Purpose per suite:

| Suite | Covers |
|---|---|
| `tests/run.php` | core app logic: Money, Cart, View, OutboundLink, safe URLs |
| `run_import_tests.php` | importer: fetch/map/normalize, upserts, stale marking |
| `run_adapter_tests.php` | adapter framework, registry, Shopify mapping |
| `run_membership_tests.php` | membership persistence semantics (replace, dedup, fallback) |
| `run_transaction_semantics_tests.php` | import transaction ownership, no implicit commits via DDL |
| `run_cli_importer_tests.php` | import CLI wiring/behaviour |
| `run_cli_bootstrap_tests.php` | CLI symbol resolution (catches missing `use` aliases — production incident lesson) |
| `run_guard_tests.php` | migration namespace guard (only `VSKR_` tables touched) |
| `run_migration_tests.php` | idempotency of the real migration files |
| `run_form_flow_tests.php` | HTTP form flow: validation, result page, escaping |
| `run_collection_enrichment_tests.php` | collection discovery/membership mapping incl. fail-closed |
| `run_retry_observability_tests.php` | retry semantics (429/5xx vs. permanent 4xx), CLI output contract |
| `run_cli_bootstrap_tests.php` / `run_outbound_render_tests.php` / `run_counter_tests.php` | CLI bootstrap, `/go/` rendering, click counter |
| `run_hardening_tests.php` | security hardening regressions (image permission flag, asset serving) |
| `run_asset_replacement_tests.php`, `run_wordmark_tests.php`, `run_stats_webp_tests.php` | asset/cache-busting/wordmark regressions |
| `run_project_page_tests.php`, `run_visibility_legal_tests.php` | static pages, legal placeholder pages |
| `run_shop_assignment_tests.php` | shop_id scoping of imports vs. frontend (incident regression) |
| `run_prod_fix_tests.php` | production incident regressions (new-tab links, rel attributes) |
| `run_autoload_tests.php` | PSR-4 autoloader resolution |

### HTTP smoke tests

`tests/smoke_server.php` starts a local PHP server with a production-shaped
layout. `check_http_exposure.php` then verifies over real HTTP that
`src/`, `templates/`, `tests/`, `database/`, `config/`, `assets-design/`,
`bin/`, `.md`/`.sql`/`.env` files and `router.php` return 404/403, while pages
and assets work; `check_no_cookies.php` asserts no `Set-Cookie` on any page.
These suites prove the documentation/webroot separation at runtime.

## MariaDB integration suites

Production runs MySQL 8.4; these suites exercise real server semantics on a
local MariaDB 10.11 (utf8mb4, InnoDB, native prepares). They use
**`/usr/bin/php8.3`** — the only PHP on this host with `pdo_mysql` (the static
local builds lack PDO).

Environment (host-specific, never committed):

```bash
source ~/.config/versandkostenretter/test-db.env
# provides VSKR_TEST_DB_DSN, VSKR_TEST_DB_USER, VSKR_TEST_DB_PASSWORD
# target database: versandkostenretter_test (DISPOSABLE — suites DROP and
# recreate all VSKR_ tables from database/schema.sql on every run)
```

The suites **skip cleanly** when the variables are unset. Credentials live
only in `~/.config/versandkostenretter/test-db.env` and must never be
committed or printed.

| Suite | Covers |
|---|---|
| `run_membership_mariadb_tests.php` | membership persistence end-to-end: multi-memberships, idempotent re-import, stale sync, failed enrichment keeps known-good data, transaction rollback |
| `run_pagination_sort_visibility_mariadb_tests.php` | pagination (10/10/5, clamping), stable sort (price → name → external_id), dynamic category visibility + counts (strict/expanded, dedup, no N+1) |
| `run_frontend_category_mariadb_tests.php` | category filter path incl. the resolve-basis regression (membership categories must be resolvable) |
| `run_results_total_mariadb_tests.php` | result text shows the unlimited total (253-treffer fixture: page text stays "253 … Seite N von 26") |

### Frontend HTTP suite

`run_frontend_http_tests.php` runs against a **live local dev server**
(`php -S 127.0.0.1:8099 router.php` with the MariaDB fixture loaded via
`frontend_http_fixture.php`; set `VSKR_BASE=http://127.0.0.1:8099`). It tests
the real request/render path: GET parameters, category resolution, label
counts vs. values, pagination links with full URL state, expanded mode,
`Set-Cookie` absence.

### Live storefront diagnostic

`diag_real_lootforge.php` runs the real adapter (with retry decorator) against
the public Lootforge storefront — read-only, no database. Verifies the full
collection-enrichment data path against real Shopify responses (request
breakdown, handle↔external_id join, membership counts). Mind Shopify rate
limits: one run per invocation; on 429 wait a few minutes and retry.

## Conventions

- Each suite is a standalone script; add new suites as
  `tests/import/run_<topic>_tests.php` with the same PASS/FAIL + exit-code
  pattern.
- Tests must not print credentials, must skip cleanly when their environment
  (e.g. `VSKR_TEST_DB_*`) is missing, and must not require network access
  unless explicitly a live diagnostic.
- MariaDB suites reset only `VSKR_` tables in the disposable test database.
