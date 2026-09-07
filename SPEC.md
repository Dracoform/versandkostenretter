# SPEC — Versandkostenretter MVP

Status: implemented (feature branch), pending operator deployment.

## 1. Purpose

Help a user who already has a shopping cart at one specific shop find cheap
additional products **in that same shop** to reach the free-shipping
threshold. The user always picks the shop first; other shops are never
suggested or compared.

## 2. Core calculation

```
missing_amount = free_shipping_threshold − current_cart_value
```

- If `current_cart_value ≥ threshold` → the user is told shipping is already
  free. No products are shown.
- Otherwise: available products of the selected shop with
  `price ≥ missing_amount`, ordered by price ascending, limited
  (default 24, config `app.max_results`).
- For every product additionally:
  `effective_extra_cost = product_price − standard_shipping_cost`.
  The UI explains this as "Produktpreis minus Versandkosten, die du sonst
  zahlen würdest" and never claims the user saves the full product price.

All money values are handled as **integer cents** internally (see
`src/Money.php`). No float arithmetic on money anywhere.

## 3. Reference example (test data)

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

## 4. Shipping assumption

No shipping-rule engine. The shop's stored `shipping_cost` and
`free_shipping_threshold` are used verbatim. The results page states:

> Aus technischen Gründen beziehen sich alle Berechnungen auf die
> Standardlieferung per DHL. Abweichende Versandarten, Sperrgut-, Auslands-
> oder sonstige Sonderversandkosten werden nicht berücksichtigt.

## 5. Architecture

- PHP 8.4, MySQL 8.4, PDO with prepared statements only (read-only usage).
- Front controller `public/index.php` + PHP templates, semantic HTML.
- One modern CSS file, one small vanilla JS file (progressive enhancement
  only — the form works without JavaScript).
- No frameworks, no npm/build chain, no Redis, no Docker, no external
  services.

```
(Plesk: repository root IS the document root /versandkostenretter.de/httpdocs)
index.php          front controller (/, ?page=impressum|datenschutz, /?...health)
.htaccess          blocks src/templates/tests/database/config/assets-design,
                   sensitive file types, security headers
router.php         dev-server router only (denied over HTTP)
assets/            css/, js/, images/ (all local, no CDN)
src/               Money, Cart, Shop/ProductRepository, Database, OutboundLink,
                   CategoryFilter, View
templates/         home, results, impressum, datenschutz, 404, layout
config/            config.example.php (committed); real config.php lives
                   OUTSIDE the document root: /versandkostenretter.de/config/config.php
database/          schema.sql, seed-development.sql, migrations/
tests/             run.php, check_no_cookies.php, check_http_exposure.php,
                   smoke_server.php (local dev only)
assets-design/     original design references (never web-accessible)
```

## 6. Database contract

- Only tables prefixed `VSKR_` are referenced; `Database::assertOnlyVskrTables()`
  is tested to flag any other table name.
- Existing schema (`VSKR_shops`, `VSKR_products`, index
  `(shop_id, available, price)`) is kept; `database/schema.sql` reproduces it.
- The application performs reads only. No migrations, no writes, no dropping.
- Credentials live in `config/config.php` (gitignored, outside the document
  root). `config/config.example.php` is the committed template without secrets.

## 7. Security & privacy

- Prepared statements, server-side validation, output escaping
  (`htmlspecialchars` via `View::e`), URL whitelist (http/https only) for
  product links and images.
- CSRF token (session-based, `hash_equals`) on the form POST; session cookie
  is HttpOnly, SameSite=Strict.
- `display_errors=0`; production errors are generic, details go to the error
  log only.
- No analytics, tracking, ads, affiliate parameters, external fonts, CDNs,
  social widgets, embeds, contact forms, newsletter or accounts.
- Only technically necessary "cookie": the CSRF session. No consent banner
  required.

## 8. Placeholder pages

`/?page=datenschutz` and `/?page=impressum` exist with clearly marked
`TODO (Betreiber)` placeholders. **No legal identity/address data is
invented.**

## 9. Deployment

Source of truth: GitHub. Plesk pulls `main`. Deployment is manual and
operator-driven; nothing in this repo deploys anything. Feature work lands on
feature branches and must be reviewed before merging into `main`.

## 10. Operator TODOs

- Fill in `config/config.php` on the host (DB name/user/password).
- Run `database/schema.sql` against the shared DB (VSKR_ namespace only).
- Complete Datenschutz/Impressum placeholder fields.
- Optional: provide a TinyPNG API key to further optimize
  `public/assets/images/hero-raccoon.jpg` (currently 123 KB optimized
  progressive JPEG from `assets/02-hero-raccoon.png`).
