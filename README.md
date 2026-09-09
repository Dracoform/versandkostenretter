# Versandkostenretter

> Rette deinen Warenkorb! — Kleine Extras. Große Wirkung.
> Mehr Hobby. Weniger Hürden.

MVP of versandkostenretter.de: a user with a shopping cart at a specific shop
finds inexpensive additional products **from that same shop** to reach the
shop's free-shipping threshold.

Not a shop comparison engine — the user picks the shop first, and only that
shop's products are ever recommended.

## Documentation

- [SPEC.md](SPEC.md) — product behaviour, core calculation, current state
- [docs/architecture.md](docs/architecture.md) — data flow, components,
  database contract, adding a new shop (adapter contract)
- [docs/import-and-adapters.md](docs/import-and-adapters.md) — importer,
  Shopify adapter, memberships, fail-closed semantics, retry/rate limiting
- [docs/operations.md](docs/operations.md) — deployment layout, migrations,
  CLI tools, operator procedures, privacy notes
- [docs/testing.md](docs/testing.md) — test suites, local + MariaDB
  integration, how to run them

## Quick start (local development)

Requirements: PHP 8.4+ with `pdo_mysql` (and `pdo_sqlite` for the local test
suites), MySQL 8.4 / MariaDB with the shared `VSKR_` tables (see
`database/schema.sql`).

```bash
cp config/config.example.php config/config.php   # then fill in real DB creds (NOT committed)
mysql -u USER -p DBNAME < database/schema.sql    # creates the VSKR_ tables
mysql -u USER -p DBNAME < database/seed-development.sql   # OPTIONAL: fictional test data
php -S localhost:8080 router.php
```

Then open http://localhost:8080.

The dev seed contains clearly marked fictional shops/products
(`(TEST DATA)`) — none of the prices or shipping values are verified merchant
information.

## How it works (user view)

1. Pick the shop, enter the current cart value.
2. Versandkostenretter calculates the missing amount to the shop's free
   shipping threshold and lists eligible products from **that shop only**.
3. Default ("strict") mode: `missing <= price <= missing + 2 EUR` — products
   that just push the cart over the threshold. "Mehr Auswahl anzeigen"
   (expanded) removes the upper price bound (`price >= missing`).
4. Results are paginated (10 per page), sorted by `price ASC, name ASC,
   external_id ASC`, and the category dropdown shows only categories that
   actually have eligible products in the current mode, each with its
   own hit count.

See [SPEC.md](SPEC.md) for the complete product behaviour.

## Product importer (CLI, cron-ready)

Migrations are applied manually by the operator (see
[docs/operations.md](docs/operations.md)); `bin/run-migration.php` is the
CLI wrapper for a single migration file.

Import manually:

```bash
php bin/import-shop.php lootforge --dry-run   # fetch + map + report, NO writes
php bin/import-shop.php lootforge             # real import
```

Exit codes: 0 ok, 1 usage, 2 shop/config missing, 3 source failure,
4 database failure, 5 already running, 6 shop deactivated. Concurrent runs
for the same shop are prevented via a MySQL name-lock. A failed fetch NEVER
changes product availability. Stale products (absent from a COMPLETE feed)
are marked `available = 0`, scoped to (shop, source, scope) — never deleted.

## Tests

```bash
php tests/run.php                                     # app logic
php tests/import/run_import_tests.php                 # importer (needs pdo_sqlite locally)
php tests/check_layout_paths.php                      # Plesk layout simulation
php tests/check_http_exposure.php                     # needs local smoke server running
php tests/check_no_cookies.php                        # needs local smoke server running
source ~/.config/versandkostenretter/test-db.env      # MariaDB integration (see docs/testing.md)
/usr/bin/php8.3 tests/import/run_membership_mariadb_tests.php
```

No external dependencies, no Composer, no Node. Pure PHP + assertions. See
[docs/testing.md](docs/testing.md) for the full suite list.

## Deployment (manual, Plesk)

Plesk pulls `main`. **Do not deploy from a feature branch.** Deployment is
operator-driven and currently manual — this repository intentionally contains
no deploy automation. See [docs/operations.md](docs/operations.md) for the
deployment layout and why documentation never reaches the web root.

## Privacy

No analytics, no tracking, no ads, no cookies at all for search and
pagination (stateless GET parameters only), no external fonts/CDNs/JS
libraries. All assets local. Aggregate outbound-click statistics only — no
per-click rows, no IPs, no user identifiers.
