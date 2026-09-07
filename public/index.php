<?php

declare(strict_types=1);

/**
 * Versandkostenretter — MVP front controller.
 *
 * Single entry point. Reads config from ../config/config.php (outside the
 * public web root). All DB access is read-only and VSKR_-table-scoped.
 */

error_reporting(E_ALL);
ini_set('display_errors', '0');       // never expose internals to users
ini_set('log_errors', '1');
ini_set('error_log', ini_get('error_log') ?? 'syslog'); // hosting default

$configPath = dirname(__DIR__) . '/config/config.php';
$config = [];
if (is_file($configPath)) {
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

// ---------------------------------------------------------------
// helpers
// ---------------------------------------------------------------
function vskr_view(): string
{
    return \Versandkostenretter\View::class;
}

function vskr_redirect(string $target): never
{
    header('Location: ' . $target, true, 303);
    exit;
}

function vskr_json_error(int $status, string $message): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => $message], JSON_UNESCAPED_UNICODE);
    exit;
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
    ''         => vskr_handle_home($config, $maxResults),
    'impressum'    => vskr_render_static('impressum'),
    'datenschutz'  => vskr_render_static('datenschutz'),
    'health'   => vskr_health($config),
    default    => vskr_render_404(),
};

// ---------------------------------------------------------------
// controllers
// ---------------------------------------------------------------
function vskr_handle_home(array $config, int $maxResults): void
{
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

    // Everything except the actual search goes through GET.
    if ($method === 'POST' && isset($_POST['shop_id'])) {
        vskr_validate_csrf();
        $shopId = filter_var($_POST['shop_id'] ?? '', FILTER_VALIDATE_INT);
        $cartRaw = (string) ($_POST['cart_value'] ?? '');
        vskr_redirect(vskr_search_url($shopId, $cartRaw));
    }

    if ($method === 'GET' && isset($_GET['shop_id'])) {
        vskr_render_results($config, $maxResults);
        return;
    }

    vskr_render_home();
}

function vskr_validate_csrf(): void
{
    $token = $_POST['csrf_token'] ?? null;
    if (!\Versandkostenretter\Csrf::validate(is_string($token) ? $token : null)) {
        vskr_json_error(400, 'Ungültiges Formular-Token. Bitte Seite neu laden.');
    }
}

function vskr_search_url(?int $shopId, string $cartRaw): string
{
    $params = ['shop_id' => (string) ($shopId ?? ''), 'cart' => $cartRaw];
    return '/?' . http_build_query($params);
}

function vskr_render_home(): void
{
    $db = \Versandkostenretter\Database::fromConfigFile(dirname(__DIR__) . '/config/config.php');
    $shopRepo = new \Versandkostenretter\ShopRepository($db);
    $shops = $shopRepo->activeShops();

    $csrfToken = \Versandkostenretter\Csrf::token();
    $pageTitle = 'Versandkostenretter — Rette deinen Warenkorb!';

    require dirname(__DIR__) . '/templates/home.php';
}

function vskr_render_results(array $config, int $maxResults): void
{
    $db = \Versandkostenretter\Database::fromConfigFile(dirname(__DIR__) . '/config/config.php');
    $shopRepo = new \Versandkostenretter\ShopRepository($db);
    $cartRaw = (string) ($_GET['cart'] ?? '');
    $cartCents = \Versandkostenretter\Money::parseToCents($cartRaw);
    $shopId = filter_var($_GET['shop_id'] ?? '', FILTER_VALIDATE_INT);

    $errors = [];
    if ($shopId === false || $shopId === null || $shopId < 1) {
        $errors[] = 'Bitte wähle einen Shop aus.';
        $shopId = null;
    }
    if ($cartCents === null) {
        $errors[] = 'Bitte gib deinen aktuellen Warenkorbwert ein (z. B. 125.34 oder 125,34).';
    }

    $shop = $shopId !== null ? $shopRepo->find($shopId) : null;
    if ($shopId !== null && $shop === null) {
        $errors[] = 'Unbekannter Shop. Bitte wähle aus der Liste.';
        $shopId = null;
    }
    if ($shop !== null && $cartCents !== null && $cartCents < 0) {
        $errors[] = 'Warenkorbwert darf nicht negativ sein.';
        $cartCents = null;
    }

    $results = null;
    if ($shop !== null && $cartCents !== null && $errors === []) {
        $missing = \Versandkostenretter\Cart::missingCents($cartCents, $shop['free_shipping_threshold_cents']);
        if (\Versandkostenretter\Cart::thresholdReached($cartCents, $shop['free_shipping_threshold_cents'])) {
            $results = ['free_reached' => true, 'products' => []];
        } else {
            $products = (new \Versandkostenretter\ProductRepository($db))
                ->eligibleProducts($shop['id'], $missing, $maxResults);
            $total = (new \Versandkostenretter\ProductRepository($db))
                ->countEligible($shop['id'], $missing);
            $results = [
                'free_reached' => false,
                'products' => $products,
                'total' => $total,
                'missing_cents' => $missing,
            ];
        }
    }

    $csrfToken = \Versandkostenretter\Csrf::token();
    $shops = $shopRepo->activeShops();
    $pageTitle = 'Versandkostenretter — Ergebnisse';
    require dirname(__DIR__) . '/templates/results.php';
}

function vskr_render_static(string $page): void
{
    $allowed = ['impressum' => 'Impressum', 'datenschutz' => 'Datenschutz'];
    if (!isset($allowed[$page])) {
        vskr_render_404();
        return;
    }
    $pageTitle = 'Versandkostenretter — ' . $allowed[$page];
    require dirname(__DIR__) . '/templates/' . $page . '.php';
}

function vskr_render_404(): never
{
    http_response_code(404);
    $pageTitle = 'Seite nicht gefunden';
    require dirname(__DIR__) . '/templates/404.php';
}

function vskr_health(array $config): never
{
    header('Content-Type: application/json; charset=utf-8');
    $payload = ['status' => 'ok', 'app' => 'versandkostenretter-mvp'];
    try {
        $db = \Versandkostenretter\Database::fromConfigFile(dirname(__DIR__) . '/config/config.php');
        $count = $db->pdo()->query('SELECT COUNT(*) FROM VSKR_shops')->fetchColumn();
        $payload['shops'] = (int) $count;
    } catch (\Throwable $e) {
        $payload['status'] = 'degraded';
        $payload['error'] = 'database';
    }
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}
