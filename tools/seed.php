<?php
// Накатывает схему и заливает данные из приложения к ТЗ.
// Запуск: php tools/seed.php [--reset]
//
// --reset чистит заказы, события и выданные коды, но каталог и склад оставляет —
// удобно между прогонами проверок.

declare(strict_types=1);

define('APP', true);
require __DIR__ . '/../app/bootstrap.php';

use Shop\Db;

$reset = in_array('--reset', $argv, true);

// Схему гоняем по одному запросу: PDO не умеет несколько DDL в одном вызове
$schema = file_get_contents(__DIR__ . '/../db/schema.sql');
foreach (array_filter(array_map('trim', explode(';', $schema))) as $statement) {
    if (!str_starts_with($statement, '--') || str_contains($statement, 'CREATE')) {
        Db::pdo()->exec($statement);
    }
}
echo "схема на месте\n";

if ($reset) {
    Db::pdo()->exec('SET FOREIGN_KEY_CHECKS = 0');
    foreach (['promo_redemptions', 'delivery_attempts', 'webhook_events', 'orders'] as $table) {
        Db::pdo()->exec("TRUNCATE TABLE {$table}");
    }
    Db::pdo()->exec('SET FOREIGN_KEY_CHECKS = 1');
    Db::exec('UPDATE supplier_stock SET taken_by = NULL, taken_at = NULL');
    Db::exec('UPDATE promo_codes SET used = 0');
    echo "заказы и выданные коды сброшены\n";
}

// ── Каталог. Список и цены — из материалов ТЗ ────────────────────────
$products = [
    ['STEAM-TOPUP-500', 'Пополнение Steam 500 ₽', 'topup', 500, 'assets/img/steam.png'],
    ['STEAM-TOPUP-1000', 'Пополнение Steam 1000 ₽', 'topup', 1000, 'assets/img/steam.png'],
    ['STEAM-TOPUP-2500', 'Пополнение Steam 2500 ₽', 'topup', 2500, 'assets/img/steam.png'],
    ['KEY-CS2-PRIME', 'CS2 Prime Status', 'key', 1290, 'assets/img/zombie.jpg'],
    ['KEY-GTA5', 'GTA V ключ активации', 'key', 1990, 'assets/img/wildcat.jpg'],
    ['KEY-EFT', 'Escape from Tarkov ключ', 'key', 3490, 'assets/img/rogue.jpg'],
    ['SUB-DISCORD-1M', 'Discord Nitro 1 месяц', 'subscription', 399, null],
    ['SUB-YT-3M', 'YouTube Premium 3 месяца', 'subscription', 1490, null],
    ['SUB-SPOTIFY-1M', 'Spotify Premium 1 месяц', 'subscription', 299, null],
    ['GIFT-PSN-1000', 'PlayStation Store карта 1000 ₽', 'giftcard', 1000, 'assets/img/playstation.png'],
    ['GIFT-XBOX-1500', 'Xbox Gift Card 1500 ₽', 'giftcard', 1500, null],
    ['GIFT-ROBLOX-800', 'Roblox 800 Robux', 'giftcard', 890, 'assets/img/roblox.png'],
];

foreach ($products as [$sku, $name, $type, $price, $image]) {
    Db::exec(
        'INSERT INTO products (sku, name, type, price, image) VALUES (?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE name = VALUES(name), type = VALUES(type),
                                 price = VALUES(price), image = VALUES(image)',
        [$sku, $name, $type, $price, $image]
    );
}
echo 'каталог: ', count($products), " позиций\n";

// ── Склад поставщика A: те самые 50 ключей из ТЗ ─────────────────────
$keys = [
    'LFXC-TNCS-BPCD', 'P3EI-W8UO-9B4K', 'FEL3-GUXN-TCCH', 'YPLV-QK2Z-IUS5', '0K9E-P1FR-BY1U',
    '5LZV-UQ48-RXCZ', 'X93K-NYAQ-GEC1', 'EIO5-CQT5-35KO', 'M58F-GIIR-VJAP', 'NU8Y-SWYB-6252',
    'OODW-CCHF-MBAF', 'DNA5-WFJM-NE49', 'QRDD-MJ3F-A8TF', 'TAT9-5ZJN-G1T2', 'LI39-4330-ISMB',
    'BKJY-8Q79-8NHI', 'HHW6-4RX2-DX62', '1RG2-L28O-O80G', 'EF63-F39X-MTEA', '8XS7-P53H-JKIV',
    'JPE6-MQV6-P7ST', 'SAPG-A2GR-0ULS', 'T2DU-IJ1S-U16P', 'WSSY-QTR7-Z57J', 'U74E-EPCI-CY26',
    'FZXF-58H8-OR93', 'FPSM-HLZA-TPAL', 'WSC9-28DJ-B2JE', 'P63J-F7UZ-DCYP', 'C7W2-D4C5-QMT7',
    'JESI-DFBH-LK1K', 'SGMA-JA0T-GR7D', '3PR4-OSY9-M3ZW', 'OMBE-C0JF-D45Y', 'KIKQ-FQJ8-9TI8',
    'LMAN-RSHS-AJDO', 'BAKI-VT1X-Z5OL', '9F0X-B46W-03FS', 'S423-V6YY-IBEM', 'D4UW-WYRA-20ST',
    'XC0J-CJ0H-09RN', 'RY1W-XCFJ-0KUA', 'CJYY-YKSQ-QE6H', '97AQ-38QJ-H8HU', 'FS8E-3S5Z-I6RA',
    'ARQK-FML4-A14E', '7Z6K-NO9V-MPJB', 'D4K7-IJSG-N853', 'W67T-ZB0Q-1XKB', '7EQM-K09J-XKUO',
];

foreach ($keys as $code) {
    Db::insertIgnoringDuplicate('INSERT INTO supplier_stock (supplier, code) VALUES (?, ?)', ['A', $code]);
}

// У резервного поставщика свой склад: он должен выдавать другие коды,
// иначе непонятно, кто из двоих на самом деле сработал
for ($i = 1; $i <= 20; $i++) {
    Db::insertIgnoringDuplicate(
        'INSERT INTO supplier_stock (supplier, code) VALUES (?, ?)',
        ['B', sprintf('BKUP-%04d-%s', $i, strtoupper(substr(md5((string) $i), 0, 4)))]
    );
}
echo "склад: A — 50 кодов, B — 20 резервных\n";

// ── Промокоды из ТЗ ──────────────────────────────────────────────────
$promos = [
    ['WELCOME10', 'percent', 10, 100],
    ['GG500', 'amount', 500, 20],
    ['LIMIT3', 'percent', 25, 3],
    ['ONCEONLY', 'percent', 50, 1],
];

foreach ($promos as [$code, $type, $value, $maxUses]) {
    Db::exec(
        'INSERT INTO promo_codes (code, type, value, max_uses) VALUES (?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE type = VALUES(type), value = VALUES(value), max_uses = VALUES(max_uses)',
        [$code, $type, $value, $maxUses]
    );
}
echo 'промокоды: ', count($promos), " штук\n";

echo "готово\n";
