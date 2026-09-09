<?php

declare(strict_types=1);

/**
 * Versandkostenretter — front controller.
 *
 * Single entry point. Reads config from ../config/config.php (outside the
 * public web root). All DB access is read-only and VSKR_-table-scoped.
 *
 * The cart lookup is a strictly READ-ONLY operation (select shop, enter
 * cart value, get products — nothing is modified, no accounts exist).
 * It therefore uses GET semantics (?shop=slug&cart=125.34) and requires
 * NO session, NO CSRF token and NO cookies. Normal use of this
 * application emits no Set-Cookie header at all.
 */

error_reporting(E_ALL);
ini_set('display_errors', '0');       // never expose internals to users
ini_set('log_errors', '1');
ini_set('error_log', ini_get('error_log') ?? 'syslog'); // hosting default

// DB credentials live OUTSIDE the document root:
//   /versandkostenretter.de/config/config.php  (sibling of httpdocs)
// Fallback for local development: ./config/config.php inside the repo
// (gitignored). The example file config/config.example.php is committed.
$home = dirname(__DIR__);                      // .../versandkostenretter.de (Plesk: parent of httpdocs)
$configCandidates = [
    $home . '/config/config.php',              // Plesk: OUTSIDE the document root
    __DIR__ . '/config/config.php',            // local dev only (gitignored)
];
$configPath = null;
foreach ($configCandidates as $candidate) {
    if (is_file($candidate)) {
        $configPath = $candidate;
        break;
    }
}
$GLOBALS['vskr_config_path'] = $configPath;

$config = [];
if ($configPath !== null && is_file($configPath)) {
    try {
        $loaded = require $configPath;
        if (is_array($loaded)) {
            $config = $loaded;
        }
    } catch (\Throwable $e) {
        error_log('Config error: ' . $e->getMessage());
    }
}

$debug = (bool) ($config['app']['debug'] ?? false);
$maxResults = (int) ($config['app']['max_results'] ?? 24);
if ($maxResults < 1 || $maxResults > 100) {
    $maxResults = 24;
}

// PSR-4-style autoloader: Versandkostenretter\... => src/...
// (subnamespaces map to subdirectories, e.g. Import\ => src/Import/)
spl_autoload_register(function (string $class): void {
    if (!str_starts_with($class, 'Versandkostenretter\\')) {
        return;
    }
    $file = __DIR__ . '/src/' . str_replace('\\', '/', substr($class, strlen('Versandkostenretter\\'))) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

// Asset cache busting: version URLs from the deployed file's mtime
// (deterministic; no sessions, no cookies).
\Versandkostenretter\Assets::setDocRoot(__DIR__);

/**
 * Resolved path of the DB config file (outside the document root on Plesk:
 * /versandkostenretter.de/config/config.php).
 */
function vskr_config_path(): string
{
    $path = $GLOBALS['vskr_config_path'] ?? null;
    if (!is_string($path) || $path === '') {
        throw new RuntimeException(
            'Database configuration missing. Expected at '
            . dirname(__DIR__) . '/config/config.php (outside the document root).'
        );
    }
    return $path;
}

set_exception_handler(function (\Throwable $e) use ($debug): void {
    error_log('[vskr] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    if ($debug) {
        http_response_code(500);
        echo '<pre>' . htmlspecialchars((string) $e, ENT_QUOTES, 'UTF-8') . '</pre>';
        exit;
    }
    http_response_code(500);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html lang="de"><meta charset="utf-8">'
        . '<title>Versandkostenretter — Fehler</title>'
        . '<body style="font-family:system-ui,sans-serif;max-width:32rem;margin:4rem auto;padding:0 1rem">'
        . '<h1>Etwas ist schiefgelaufen</h1>'
        . '<p>Bitte versuche es in wenigen Augenblicken erneut.</p>'
        . '</body></html>';
});

// ---------------------------------------------------------------
// routing
// ---------------------------------------------------------------
// Privacy-preserving outbound click endpoint: /go/<product-id>
// (aggregate +1, then redirect to the merchant — see StatsRepository
//  and OutboundLink for the data-minimisation and redirect policies).
$goUri = strtok($_SERVER['REQUEST_URI'] ?? '', '?') ?: '';
if (str_starts_with($goUri, '/go/')) {
    // Anything under /go/ that is not a valid product id is a safe 404
    // (never the homepage, never an increment).
    if (preg_match('#^/go/([A-Za-z0-9_-]{1,64})$#', $goUri, $goMatch)) {
        vskr_handle_go($goMatch[1]);
    }
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    header('X-Robots-Tag: noindex');
    exit('404');
}

$route = isset($_GET['page']) ? (string) $_GET['page'] : '';

match (true) {
    $route === ''              => vskr_handle_home($config, $maxResults),
    $route === 'impressum'     => vskr_render_static('impressum'),
    $route === 'datenschutz'   => vskr_render_static('datenschutz'),
    $route === 'projekt'       => vskr_render_static('projekt'),
    $route === 'health'        => vskr_health($config),
    // Pagination: ?page=2 gehoert zur Ergebnis-URL (numerisch = Seitenzahl).
    // Nicht-numerische page-Werte mit shop/cart fallen sicher auf Seite 1
    // (Clamping in vskr_render_results); statische Seiten bleiben textuell.
    $route !== '' && (isset($_GET['shop']) || isset($_GET['cart'])) => vskr_handle_home($config, $maxResults),
    default                    => vskr_render_404(),
};

// ---------------------------------------------------------------
// controllers
// ---------------------------------------------------------------
function vskr_handle_home(array $config, int $maxResults): void
{
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

    // Form submissions are turned into clean GET URLs (POST -> 303 redirect).
    // The form itself works with plain GET; the redirect just normalizes
    // the URL for shareable/bookmarkable results. No state, no CSRF.
    if ($method === 'POST') {
        $shopSlug = trim((string) ($_POST['shop'] ?? ''));
        $cartRaw = (string) ($_POST['cart'] ?? '');
        $target = '/';
        if ($shopSlug !== '' && $cartRaw !== '') {
            $target = '/?' . http_build_query(['shop' => $shopSlug, 'cart' => $cartRaw]);
        }
        header('Location: ' . $target, true, 303);
        exit;
    }

    if (isset($_GET['shop']) || isset($_GET['cart'])) {
        vskr_render_results($config, $maxResults);
        return;
    }

    vskr_render_home();
}

/**
 * Outbound product click: increment the global aggregate counter, then
 * redirect to the merchant. Privacy-preserving (aggregate only) and
 * fail-open for the user: a counter failure never blocks the click.
 */
function vskr_handle_go(string $productId): never
{
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Robots-Tag: noindex');

    // Strictly numeric external ids (Shopify numeric ids, future sources
    // must stay within [A-Za-z0-9_-]); anything else is a safe 404.
    if (!preg_match('/^[0-9]{1,19}$/', $productId)) {
        exit('404');
    }

    try {
        $db = \Versandkostenretter\Database::fromConfigFile(vskr_config_path());
        $stmt = $db->pdo()->prepare(
            'SELECT p.url, s.affiliate_enabled, s.affiliate_mode, s.affiliate_param,
                    s.affiliate_value, s.affiliate_template
             FROM VSKR_products p
             JOIN VSKR_shops s ON s.id = p.shop_id
             WHERE p.external_id = :eid
             LIMIT 1'
        );
        $stmt->execute([':eid' => $productId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
    } catch (\Throwable $e) {
        error_log('[vskr-go] product lookup failed'); // no internals leaked
        exit('404');
    }

    if ($row === false) {
        exit('404'); // unknown product: no increment, no redirect
    }

    $affiliate = \Versandkostenretter\OutboundLink::configFromShopRow($row);
    try {
        $link = \Versandkostenretter\OutboundLink::build((string) $row['url'], $affiliate);
    } catch (\Throwable $e) {
        exit('404'); // unsafe merchant URL: fail closed, no increment
    }
    if ($link['is_affiliate'] && !str_starts_with($link['url'], 'https://')) {
        exit('404');
    }

    // The click is valid: count it best-effort, then redirect regardless.
    $newCount = (new \Versandkostenretter\StatsRepository($db->pdo()))
        ->increment(\Versandkostenretter\StatsRepository::OUTBOUND_PRODUCT_CLICKS);
    if ($newCount === null) {
        // Privacy-safe diagnostic (no credentials/user data): an operator can
        // correlate this with bin/stats.php output.
        error_log('[vskr-go] counter increment failed; click proceeded anyway');
    }

    // Aggregate-only redirect: strip cache/robot confusion, set no cookies,
    // start no session, add no tracking parameters.
    header('Location: ' . $link['url'], true, 302);
    header('Cache-Control: no-store');
    header('X-Robots-Tag: noindex');
    exit;
}

function vskr_render_home(): void
{
    $db = \Versandkostenretter\Database::fromConfigFile(vskr_config_path());
    $shopRepo = new \Versandkostenretter\ShopRepository($db);
    $shops = $shopRepo->activeShops();

    // Aggregate rescue-attempt counter (privacy-preserving, data-minimal).
    // Display choice: hidden at 0 (cleaner than showing an empty brag).
    $rescueAttempts = (new \Versandkostenretter\StatsRepository($db->pdo()))
        ->get(\Versandkostenretter\StatsRepository::OUTBOUND_PRODUCT_CLICKS);

    $pageTitle = 'Versandkostenretter — Rette deinen Warenkorb!';
    // The homepage displays the live aggregate counter: disallow stale
    // cached copies (a pre-counter cached page would look like a broken
    // counter). Assets stay cacheable via their versioned URLs.
    header('Cache-Control: no-cache, must-revalidate');
    require __DIR__ . '/templates/home.php';
}

function vskr_render_results(array $config, int $maxResults): void
{
    $db = \Versandkostenretter\Database::fromConfigFile(vskr_config_path());
    $shopRepo = new \Versandkostenretter\ShopRepository($db);

    $cartRaw = (string) ($_GET['cart'] ?? '');
    $cartCents = \Versandkostenretter\Money::parseToCents($cartRaw);
    $shopSlug = trim((string) ($_GET['shop'] ?? ''));

    $errors = [];
    if ($shopSlug === '') {
        $errors[] = 'Bitte wähle einen Shop aus.';
        $shop = null;
    } elseif (strlen($shopSlug) > 190 || !preg_match('/^[a-z0-9-]+$/i', $shopSlug)) {
        $errors[] = 'Unbekannter Shop. Bitte wähle aus der Liste.';
        $shop = null;
    } else {
        $shop = $shopRepo->findBySlug($shopSlug);
        if ($shop === null) {
            $errors[] = 'Unbekannter Shop. Bitte wähle aus der Liste.';
        }
    }

    if ($cartRaw !== '' && $cartCents === null) {
        $errors[] = 'Bitte gib deinen aktuellen Warenkorbwert ein (z. B. 125.34 oder 125,34).';
    } elseif ($cartRaw === '' && $cartCents === null) {
        $errors[] = 'Bitte gib deinen aktuellen Warenkorbwert ein.';
    }

    $results = null;
    $categories = [];
    $selectedCategory = null;

    if ($shop !== null && $cartCents !== null && $errors === []) {
        $missing = \Versandkostenretter\Cart::missingCents($cartCents, $shop['free_shipping_threshold_cents']);
        if (\Versandkostenretter\Cart::thresholdReached($cartCents, $shop['free_shipping_threshold_cents'])) {
            $results = ['free_reached' => true, 'products' => []];
        } else {
            $productRepo = new \Versandkostenretter\ProductRepository($db);
            $importRepo = new \Versandkostenretter\Import\ImportRepository($db->pdo());

            // Opportunistic, shop-local category filter (stateless GET param).
            $requestedCategory = isset($_GET['category']) ? trim((string) $_GET['category']) : '';
            if ($requestedCategory !== '') {
                $requestedCategory = function_exists('mb_substr')
                    ? mb_substr($requestedCategory, 0, 190)
                    : substr($requestedCategory, 0, 190);
            }

            // Filler-item price window: default mode caps the price at
            // missing + 2.00 EUR so results actually get the user just over
            // the threshold; expanded mode ("Mehr Auswahl anzeigen") removes
            // the cap. Stateless GET param, no cookies/sessions.
            $expanded = isset($_GET['expanded']) && $_GET['expanded'] === '1';
            $maxPriceCents = $expanded ? null : $missing + 200;

            // Dropdown-/Resolve-Basis: ALLE wählbaren Kategorien des Shops —
            // die VSKR_products.category-Spalte UND die Membership-Tabelle.
            // Ohne den Membership-Anteil resolve()t die Frontend-Auswahl
            // Collection-Kategorien zu null und der Filter fällt still auf
            // "Alle" zurück (Production-Bug).
            $memberships = $importRepo->categoryMemberships((int) $shop['id']);
            if ($memberships === []) { $memberships = null; } // no membership data -> single-column filtering
            // Kategorie-SICHTBARKEIT folgt dem aktuellen Preis-Modus (Strict/
            // Expanded): nur Kategorien mit mindestens einem eligible Produkt.
            // Die vollstaendige Taxonomie bleibt in der DB unveraendert.
            $categoryFacets = $productRepo->visibleCategories(
                (int) $shop['id'], $missing, $maxPriceCents, $memberships
            );
            // Resolve-Basis: reine Namen (isUsable/resolve erwarten Strings).
            $categories = array_column($categoryFacets, 'name');

            // Case-insensitive matching against the stored categories —
            // fixes the production bug where the dropdown value's casing
            // differed from the stored merchant tag and the filter silently
            // fell back to "Alle".
            $selectedCategory = \Versandkostenretter\CategoryFilter::resolve(
                $categories, $requestedCategory
            );

            // Many-to-many memberships: the requested category may map to a
            // stored value carried by products via the membership table.

            // Pagination: 10 pro Seite; invalide page-Werte werden geclampt.
            $pageParam = isset($_GET['page']) ? (string) $_GET['page'] : '1';
            $page = ctype_digit($pageParam) ? max(1, (int) $pageParam) : 1;

            $total = $productRepo->countEligible(
                $shop['id'], $missing, $maxPriceCents, $selectedCategory, $memberships
            );
            $totalPages = max(1, (int) ceil($total / 10));
            $page = min($page, $totalPages);

            $products = $productRepo->eligibleProducts(
                $shop['id'], $missing, 10, $selectedCategory, $maxPriceCents, $memberships, $page
            );

            $results = [
                'free_reached' => false,
                'products' => $products,
                'total' => $total,
                'missing_cents' => $missing,
                'page' => $page,
                'total_pages' => $totalPages,
            ];
        }
    }

    $shops = $shopRepo->activeShops();
    $pageTitle = 'Versandkostenretter — Ergebnisse';
    require __DIR__ . '/templates/results.php';
}

function vskr_render_static(string $page): void
{
    $allowed = ['impressum' => 'Impressum', 'datenschutz' => 'Datenschutz',
                'projekt' => 'Das Projekt'];
    if (!isset($allowed[$page])) {
        vskr_render_404();
        return;
    }
    $pageTitle = 'Versandkostenretter — ' . $allowed[$page];
    require __DIR__ . '/templates/' . $page . '.php';
}

function vskr_render_404(): never
{
    http_response_code(404);
    $pageTitle = 'Seite nicht gefunden';
    require __DIR__ . '/templates/404.php';
}

function vskr_health(array $config): never
{
    header('Content-Type: application/json; charset=utf-8');
    $payload = ['status' => 'ok', 'app' => 'versandkostenretter-mvp'];
    try {
        $db = \Versandkostenretter\Database::fromConfigFile(vskr_config_path());
        $count = $db->pdo()->query('SELECT COUNT(*) FROM VSKR_shops')->fetchColumn();
        $payload['shops'] = (int) $count;
    } catch (\Throwable $e) {
        $payload['status'] = 'degraded';
        $payload['error'] = 'database';
    }
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}
