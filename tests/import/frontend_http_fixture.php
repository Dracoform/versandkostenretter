<?php

declare(strict_types=1);

/** Frontend-HTTP-Fixture: production-shaped Daten in die MariaDB-Test-DB. */
if (getenv('VSKR_TEST_DB_DSN') === false) {
    fwrite(STDERR, "VSKR_TEST_DB_* fehlt\n");
    exit(1);
}
$pdo = new PDO(getenv('VSKR_TEST_DB_DSN'), getenv('VSKR_TEST_DB_USER'), getenv('VSKR_TEST_DB_PASSWORD'), [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);
$pdo->exec('SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci');
$stmts = static function (string $sql) use ($pdo): void {
    $clean = implode("\n", array_filter(explode("\n", $sql), static fn (string $l): bool => !str_starts_with(ltrim($l), '--')));
    foreach (array_filter(array_map('trim', explode(';', $clean))) as $stmt) {
        if ($stmt !== '' && !str_starts_with(strtoupper($stmt), 'USE ')) {
            $pdo->exec($stmt);
        }
    }
};
$pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
foreach ($pdo->query("SHOW TABLES LIKE 'VSKR_%'")->fetchAll(PDO::FETCH_COLUMN) as $t) {
    $pdo->exec("DROP TABLE `{$t}`");
}
$stmts((string) file_get_contents(dirname(__DIR__, 2) . '/database/schema.sql'));
$pdo->exec('SET FOREIGN_KEY_CHECKS = 1');

$pdo->exec("INSERT INTO VSKR_shops (id,name,slug,website_url,shipping_cost,free_shipping_threshold,active)
VALUES (1,'Lootforge','lootforge','https://lootforge.example',5.99,100.00,1)");
$ins = $pdo->prepare('INSERT INTO VSKR_products
    (shop_id, external_id, name, url, price, available, category, last_seen_at, updated_at)
    VALUES (1, ?, ?, ?, ?, 1, NULL, NOW(), NOW())');
// Warenkorb 95.00, Threshold 100.00 -> missing 5.00 -> Default-Fenster 5.00-7.00:
$ins->execute(['EC1', "Emperor's Children Noise Marine", 'https://s.example/p/ec1', 5.50]);
$ins->execute(['EC2', "Emperor's Children Sonic Blaster", 'https://s.example/p/ec2', 6.50]);
$ins->execute(['WF1', 'Warpaints Fanatic Brush', 'https://s.example/p/wf1', 5.90]);
$ins->execute(['SB1', 'Soulblight Vampire', 'https://s.example/p/sb1', 6.20]);
$ins->execute(['EC3', "Emperor's Children ABOVE window", 'https://s.example/p/ec3', 9.99]); // über missing+2
$ins->execute(['XX1', 'No membership product', 'https://s.example/p/xx1', 6.90]);

$pdo->exec("INSERT INTO VSKR_product_categories (shop_id, external_id, category) VALUES
    (1,'EC1',\"Emperor's Children\"),
    (1,'EC2',\"Emperor's Children\"),
    (1,'EC3',\"Emperor's Children\"),
    (1,'WF1','Warpaints Fanatic'),
    (1,'SB1','Soulblight Gravelords Age of Sigma')");

// 25 in-window Produkte fuer Pagination (10/10/5) — EC1/EC2/WF1/SB1/XX1
// liegen ebenfalls im Fenster, also weitere 20 mit eindeutigen Namen:
$insP = $pdo->prepare('INSERT INTO VSKR_products
    (shop_id, external_id, name, url, price, available, category, last_seen_at, updated_at)
    VALUES (1, ?, ?, ?, ?, 1, NULL, NOW(), NOW())');
$insCatP = $pdo->prepare('INSERT INTO VSKR_product_categories (shop_id, external_id, category) VALUES (1, ?, ?)');
for ($i = 1; $i <= 20; $i++) {
    $eid = sprintf('PP%02d', $i);
    $insP->execute([$eid, 'Filler Item ' . str_pad((string) $i, 2, '0', STR_PAD_LEFT), 'https://s.example/p/' . strtolower($eid), 5.00 + $i * 0.09]);
    $insCatP->execute([$eid, 'Filler Category']);
}
echo "Fixture bereit: 6 Produkte, 5 Memberships\n";
