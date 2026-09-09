# Operations

Deployment, migrations, CLI tools, and operator procedures.

## Deployment layout

Plesk deploys the repository to `/versandkostenretter.de/httpdocs`, which **is
the document root**. The whole repository is deployed, so non-public resources
are denied from HTTP access — not removed from the deployment:

- Root `.htaccess`:
  - `RedirectMatch 404` for `src/`, `templates/`, `tests/`, `database/`,
    `config/`, `assets-design/`, `bin/`
  - `FilesMatch` deny for sensitive file types: `.sql`, `.md`, `.sh`, `.ini`,
    `.log`, `.env`, `*.example.php`, and dotfiles (`.git`, `.htaccess`,
    `.gitignore`)
  - `router.php` and `config.example.php` explicitly denied
  - security headers (nosniff, X-Frame-Options DENY, Referrer-Policy,
    Permissions-Policy)
- Every non-public directory additionally carries its own deny-all
  `.htaccess` (defense in depth; `storage/` too).
- `storage/snapshots/` (catalogue snapshots from
  `bin/catalogue-snapshot.php`) is gitignored and HTTP-denied.

Consequences for documentation: `README.md`, `SPEC.md` and `docs/*.md` are
part of the repository (and thus land in the deployment directory), but they
are **not publicly served** — `.md` is in the denied `FilesMatch` list and
`docs/` would additionally be caught by the directory rules' spirit; this is
proven by `tests/check_http_exposure.php` (HTTP 404 for `.md` files and
non-public directories). There is no deployment step that copies documentation
anywhere else.

## Migrations

Applied **manually** by the operator, in filename order:

```bash
# on the host, from the deployment directory:
php bin/run-migration.php 0008_product_categories.sql
# or directly:
mysql -u USER -p DBNAME < database/migrations/0008_product_categories.sql
```

`bin/run-migration.php` wraps a single migration file with validation (it
rejects statements outside the `VSKR_` namespace — the guard that once caught
comment words like "the"/"information_schema" as table references).

Current migrations:

| File | Purpose |
|---|---|
| `0001_shops_affiliate_columns.sql` | affiliate columns on `VSKR_shops` (all shops start disabled) |
| `0002_products_source_columns.sql` | source provenance columns on `VSKR_products` |
| `0003_lootforge_shop.sql` | source config schema + idempotent Lootforge shop row (affiliate stays OFF) |
| `0004_shops_product_images_enabled.sql` | per-shop merchant-image display permission (default 0) |
| `0005_stats_counter.sql` | `VSKR_stats` aggregate counter table |
| `0006_tri_state_availability.sql` | `available` becomes tri-state (1/0/NULL) |
| `0007_lootforge_complete_catalogue.sql` | switches Lootforge to the `complete` catalogue scope |
| `0008_product_categories.sql` | `VSKR_product_categories` many-to-many memberships |

Migrations are idempotent (`CREATE TABLE IF NOT EXISTS`, upserts, guarded
`ALTER`s) and safe to run repeatedly. They only ever touch `VSKR_` tables.

## CLI tools

All CLI tools are HTTP-denied (`bin/` in `RedirectMatch`, verified by
`tests/check_http_exposure.php`).

### `bin/import-shop.php` — shop import

```bash
php bin/import-shop.php <slug> --dry-run   # fetch + map + report, NO writes
php bin/import-shop.php <slug>             # real import
php bin/import-shop.php <slug> --force     # ignore the "shop deactivated" guard
```

Exit codes: `0` ok, `1` usage, `2` shop/config missing, `3` source failure,
`4` database failure, `5` already running (MySQL name-lock), `6` shop
deactivated.

Output fields: see [import-and-adapters.md](import-and-adapters.md)
(CLI output semantics — including the meaning of `Errors: 0` vs. the
membership-sync state).

### `bin/shop-toggle.php` — shop off-switch

```bash
php bin/shop-toggle.php lootforge off    # hide publicly, retain catalogue rows
php bin/shop-toggle.php lootforge on     # reactivate
php bin/shop-toggle.php lootforge purge  # PERMANENTLY delete that shop's imported rows
```

`off` is the documented emergency procedure: the shop disappears from the
public site immediately, its catalogue data is retained so it can be restored
without a re-import. `purge` is explicitly scoped by `shop_id`.

### `bin/run-migration.php` — migration runner

```bash
php bin/run-migration.php <migration-filename.sql>
```

### `bin/stats.php` — aggregate counter diagnostic

```bash
php /versandkostenretter.de/httpdocs/bin/stats.php
# outbound_product_clicks: 3
```

Read-only; prints aggregate numbers only (no credentials, no user data).

### `bin/catalogue-snapshot.php` — catalogue dry run + snapshot

```bash
php bin/catalogue-snapshot.php <shop-slug> [--out <dir>]
```

Fetches a source via the adapter (with retry), normalizes, writes JSON/CSV
snapshots to `storage/snapshots/` (gitignored, HTTP-denied) and prints a
summary. No database writes.

## Configuration

- **Real config lives OUTSIDE the document root:**
  `/versandkostenretter.de/config/config.php` (sibling of `httpdocs`). This is
  the credential boundary — the web root contains no secrets.
- Repo-local `config/config.php` is a gitignored local-development fallback;
  `config/config.example.php` is the committed template (no secrets).

| Key | Meaning |
|---|---|
| `db.host/port/name/user/pass/charset` | MySQL credentials (PDO, prepared statements only) |
| `app.debug` | detailed errors in development only; production MUST be `false` |
| `app.max_results` | legacy fallback for the per-page limit; the current frontend uses a fixed 10 per page with pagination, so this value is effectively unused by the results page (kept for backwards compatibility) |

Shop-level configuration lives in `VSKR_shops` (shipping cost/threshold,
source config, affiliate config — disabled by default, merchant-image display
permission — default off), not in files.

## Scheduled imports (cron wrapper)

`bin/cron-import-shop.sh` is the wrapper for Plesk scheduled tasks. It runs
the PHP importer for one shop, writes a dedicated log per run, detects
problems and sends a monitoring mail via curl/SMTP **only on problems**
(successful runs send no mail). No locking: the host has no `flock`; the
residual overlap risk with two daily runs is explicitly accepted.

```bash
bin/cron-import-shop.sh <shop-handle>
```

- **Log path:** `<repo>/storage/import-logs/<shop>-<YYYYMMDD>-<HHMMSS>-<pid>.log`
  (outside the public web root; `storage/` is gitignored and HTTP-denied).
  stdout + stderr of the import run go into this single file.
- **Retention:** logs older than 30 days matching `<shop>-*.log` are deleted
  after each run (`find -mtime +30 -delete`). Foreign files and other shops'
  logs are never touched.
- **Monitoring mail** is sent when:
  - the import exits non-zero (source/db/config/lock failures), or
  - the output contains `Membership sync: SKIPPED` (fail-closed enrichment —
    existing memberships were kept, catalogue possibly outdated), or
  - enrichment warnings are present (current CLI emits them together with the
    SKIPPED block).
  Note: `Errors: 0` alone does NOT mean the enrichment ran; always check the
  membership-sync state.
- **Mail content:** subject `[VSKR] Importproblem: <shop>`, timestamp, shop,
  exit code, detected reason, and the full output of exactly this run.
- **Mail delivery:** `/usr/bin/curl --ssl-reqd --netrc-file
  ~/.config/versandkostenretter/.netrc` against
  `smtp://mx2f6e.netcup.net:587` (STARTTLS), sender and recipient
  `monitoring@versandkostenretter.de`. Credentials live only in the `.netrc`
  outside the repository; the wrapper never reads or prints them. If the mail
  delivery itself fails, the failure is documented in the run log, a message
  goes to stderr (visible in Plesk) and the wrapper exits 5.
- **Config overrides via environment:** `CRON_IMPORT_PHP`,
  `CRON_IMPORT_LOG_DIR`, `CRON_IMPORT_LOG_DAYS`, `CRON_IMPORT_NETRC`,
  `CRON_IMPORT_SMTP`, `CRON_IMPORT_MAIL_FROM`, `CRON_IMPORT_MAIL_TO`,
  `CRON_IMPORT_CURL`.

**Recommended Plesk scheduled tasks (twice daily):**

```
bin/cron-import-shop.sh lootforge        # e.g. 04:30
bin/cron-import-shop.sh lootforge        # e.g. 16:30
```

(In Plesk as "PHP script" or shell command task with the full absolute path,
e.g. `/versandkostenretter.de/httpdocs/bin/cron-import-shop.sh lootforge`.)

Regression: `tests/import/run_cron_wrapper_tests.php` covers success (no
mail), non-zero exit, SKIPPED, warnings, per-run mail content, failed mail
delivery, handle validation, retention scoping and credential handling.

## Operator procedures

**Full shop import (e.g. after a long pause):**

```bash
php bin/import-shop.php lootforge --dry-run   # sanity check
php bin/import-shop.php lootforge             # real run
# expect: Fetched/Updated = catalogue size, Errors: 0,
#         Membership sync: OK (or SKIPPED + warnings, fail-closed)
```

**Emergency shop off-switch:** `php bin/shop-toggle.php <slug> off`

**Check aggregate clicks:** `php bin/stats.php`

**Health:** `GET /?...&health` returns the health endpoint used by uptime
monitoring.
