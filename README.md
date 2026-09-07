# Versandkostenretter

> **Rette deinen Warenkorb!** — Kleine Extras. Große Wirkung.
> Mehr Hobby. Weniger Hürden.

MVP of versandkostenretter.de: a user with a shopping cart at a specific shop
finds inexpensive additional products **from that same shop** to reach the
shop's free-shipping threshold.

Not a shop comparison engine — the user picks the shop first, and only that
shop's products are ever recommended.

## Deployment layout (fixed document root)

Plesk deploys the repository to `/versandkostenretter.de/httpdocs`, which IS
the document root. Consequences:

- `index.php`, `assets/`, `.htaccess` live at the repository root.
- `src/`, `templates/`, `tests/`, `database/`, `config/`, `assets-design/`
  are deployed too but are blocked from HTTP access by the root `.htaccess`
  plus a deny-all `.htaccess` in each directory.
- Only `index.php` is executable over HTTP (`router.php` is dev-server-only
  and denied).
- **DB credentials live OUTSIDE the document root** at
  `/versandkostenretter.de/config/config.php` (sibling of `httpdocs`).
  `index.php` looks there first; a repo-local `config/config.php` (gitignored)
  is only a local-development fallback. Never commit credentials.
- `tests/check_http_exposure.php` proves over HTTP that non-public paths
  return 404/403.

## Quick start (local development)

Requirements: PHP 8.4+ with `pdo_mysql`, MySQL/MariaDB with the shared `VSKR_`
tables available (see `database/schema.sql`).

```bash
cp config/config.example.php config/config.php   # then fill in real DB creds (NOT committed)
mysql -u USER -p DBNAME < database/schema.sql    # creates VSKR_shops / VSKR_products only
mysql -u USER -p DBNAME < database/seed-development.sql   # OPTIONAL: fictional test data
php -S localhost:8080 router.php
```

Then open http://localhost:8080.

The dev seed contains clearly marked fictional shops/products
(`(TEST DATA)`) — none of the prices or shipping values are verified merchant
information.

## Tests

```bash
php tests/run.php
```

No external dependencies, no Composer, no Node. Pure PHP + assertions.

## Deployment (manual, Plesk)

Plesk pulls `main`. **Do not deploy from a feature branch.** Deployment is
operator-driven and currently manual — this repository intentionally contains
no deploy automation.

## Layout

- `index.php` — front controller (only HTTP-executable PHP file)
- `assets/` — public web assets (css/js/images)
- `src/` — application logic (Money, Cart, repositories, OutboundLink)
- `templates/` — PHP templates (semantic HTML)
- `config/` — gitignored real config + committed example
- `database/` — schema, seed, migrations (VSKR_ namespace only)
- `tests/` — framework-free tests + HTTP exposure/no-cookie probes
- `assets-design/` — original design references (never web-accessible)

## Privacy

No analytics, no tracking, no ads, no cookies beyond the technically necessary
CSRF token session, no external fonts/CDNs/JS libraries. All assets local.
