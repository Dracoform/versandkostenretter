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
$route = isset($_GET['page']) ? (string) $_GET['page'] : '';

match ($route) {
    ''             => vskr_handle_home($config, $maxResults),
    'impressum'    => vskr_render_static('impressum'),
    'datenschutz'  => vskr_render_static('datenschutz'),
    'health'       => vskr_health($config),
    default        => vskr_render_404(),
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

function vskr_render_home(): void
{
    $db = \Versandkostenretter\Database::fromConfigFile(vskr_config_path());
    $shopRepo = new \Versandkostenretter\ShopRepository($db);
    $shops = $shopRepo->activeShops();

    $pageTitle = 'Versandkostenretter — Rette deinen Warenkorb!';
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

            // Opportunistic, shop-local category filter (stateless GET param).
            $requestedCategory = isset($_GET['category']) ? trim((string) $_GET['category']) : '';
            if ($requestedCategory !== '') {
                $requestedCategory = mb_substr($requestedCategory, 0, 190);
            }

            // Unfiltered eligible set defines the available choices.
            $allProducts = $productRepo->eligibleProducts($shop['id'], $missing, $maxResults);
            $total = $productRepo->countEligible($shop['id'], $missing);
            $categories = \Versandkostenretter\CategoryFilter::distinct($allProducts);

            if (\Versandkostenretter\CategoryFilter::isUsable($categories, $requestedCategory)) {
                $selectedCategory = $requestedCategory;
                $products = $productRepo->eligibleProducts($shop['id'], $missing, $maxResults, $selectedCategory);
            } else {
                // Invalid/unknown category: graceful fallback to "Alle".
                $products = $allProducts;
            }

            $results = [
                'free_reached' => false,
                'products' => $products,
                'total' => $total,
                'missing_cents' => $missing,
            ];
        }
    }

    $shops = $shopRepo->activeShops();
    $pageTitle = 'Versandkostenretter — Ergebnisse';
    require __DIR__ . '/templates/results.php';
}

function vskr_render_static(string $page): void
{
    $allowed = ['impressum' => 'Impressum', 'datenschutz' => 'Datenschutz'];
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
