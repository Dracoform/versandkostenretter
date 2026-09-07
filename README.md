# Versandkostenretter

> **Rette deinen Warenkorb!** — Kleine Extras. Große Wirkung.
> Mehr Hobby. Weniger Hürden.

MVP of versandkostenretter.de: a user with a shopping cart at a specific shop
finds inexpensive additional products **from that same shop** to reach the
shop's free-shipping threshold.

Not a shop comparison engine — the user picks the shop first, and only that
shop's products are ever recommended.

## Quick start (local development)

Requirements: PHP 8.4+ with `pdo_mysql`, MySQL/MariaDB with the shared `VSKR_`
tables available (see `database/schema.sql`).

```bash
cp config/config.example.php config/config.php   # then fill in real DB creds (NOT committed)
mysql -u USER -p DBNAME < database/schema.sql    # creates VSKR_shops / VSKR_products only
mysql -u USER -p DBNAME < database/seed-development.sql   # OPTIONAL: fictional test data
php -S localhost:8080 -t public public/router.php
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

- `public/` — document root (only this directory is web-accessible)
- `src/` — application logic (Money, Cart, repositories)
- `templates/` — PHP templates (semantic HTML)
- `config/` — gitignored real config + committed example
- `database/` — schema + development seed (VSKR_ namespace only)
- `tests/` — framework-free test runner
- `assets/` — original design references (NOT deployed)

## Privacy

No analytics, no tracking, no ads, no cookies beyond the technically necessary
CSRF token session, no external fonts/CDNs/JS libraries. All assets local.
