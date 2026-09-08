<?php

declare(strict_types=1);

/**
 * shop-toggle.php — operator CLI to deactivate/reactivate a shop and
 * optionally purge its imported catalogue data.
 *
 * This is the documented operator procedure (no admin UI):
 *
 *   Deactivate (off-switch; public presence disappears immediately, catalogue
 *   rows are RETAINED so the shop can be restored without a re-import):
 *     php bin/shop-toggle.php lootforge off
 *
 *   Reactivate (retained catalogue data becomes publicly usable again):
 *     php bin/shop-toggle.php lootforge on
 *
 *   OPTIONAL permanent deletion of that shop's imported catalogue rows
 *   (explicitly scoped by shop_id; never affects other shops):
 *     php bin/shop-toggle.php lootforge purge
 *
 * Exit codes: 0 ok, 1 usage, 2 shop/config problem, 3 DB error.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/src/Database.php';
require dirname(__DIR__) . '/src/Import/ImportRepository.php';

use Versandkostenretter\Database;
use Versandkostenretter\Import\ImportRepository;

$args = array_slice($argv, 1);
if (count($args) < 2 || !in_array(strtolower($args[1]), ['off', 'on', 'purge'], true)
    || !preg_match('/^[a-z0-9-]{1,190}$/', $args[0])) {
    fwrite(STDERR, "Usage: php bin/shop-toggle.php <shop-slug> <off|on|purge>\n"
        . "  off   deactivate the shop (public presence disappears immediately)\n"
        . "  on    reactivate the shop (retained catalogue becomes public again)\n"
        . "  purge delete that shop's imported catalogue rows (explicit, shop-scoped)\n");
    exit(1);
}
[$slug, $action] = [$args[0], strtolower($args[1])];

$home = dirname(__DIR__);
$configCandidates = [dirname($home) . '/config/config.php', $home . '/config/config.php'];
$configPath = null;
foreach ($configCandidates as $c) {
    if (is_file($c)) { $configPath = $c; break; }
}
if ($configPath === null) {
    fwrite(STDERR, "Error: database configuration not found.\n");
    exit(2);
}

try {
    $pdo = Database::fromConfigFile($configPath)->pdo();
} catch (Throwable $e) {
    fwrite(STDERR, "Error: could not open database connection.\n");
    exit(2);
}

$stmt = $pdo->prepare('SELECT id, name, active FROM VSKR_shops WHERE slug = :slug LIMIT 1');
$stmt->execute([':slug' => $slug]);
$shop = $stmt->fetch(PDO::FETCH_ASSOC);
if ($shop === false) {
    fwrite(STDERR, "Error: no shop with slug \"{$slug}\".\n");
    exit(2);
}

if ($action === 'off' || $action === 'on') {
    $active = $action === 'on' ? 1 : 0;
    $upd = $pdo->prepare('UPDATE VSKR_shops SET active = :a WHERE id = :id');
    $upd->execute([':a' => $action === 'on' ? 1 : 0, ':id' => $shop['id']]);
    fwrite(STDOUT, sprintf(
        "Shop \"%s\" (id %d) is now %s. Catalogue rows retained (%d).\n",
        $shop['name'],
        $shop['id'],
        $action === 'on' ? 'ACTIVE' : 'DEACTIVATED',
        (int) $pdo->query('SELECT COUNT(*) FROM VSKR_products WHERE shop_id = ' . (int) $shop['id'])->fetchColumn()
    ));
    exit(0);
}

// purge: explicit, shop-scoped deletion of imported catalogue rows
$repo = new ImportRepository($pdo);
$n = $repo->purgeShopProducts((int) $shop['id']);
fwrite(STDOUT, sprintf(
    "Purged %d product row(s) of shop \"%s\" (shop_id = %d). Other shops untouched.\n",
    $n, $shop['name'], $shop['id']
));
exit(0);
