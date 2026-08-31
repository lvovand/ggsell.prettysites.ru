<?php
// Состязательные проверки. Запускать на сервере, рядом с приложением:
//
//   php tools/seed.php --reset && php tools/race_test.php
//
// Параллельные запросы идут по HTTP через curl_multi — это настоящая
// конкуренция между воркерами php-fpm, а не эмуляция внутри одного процесса.
// Результат сверяем прямо в базе: важно не что ответил API, а что реально легло.

declare(strict_types=1);

define('APP', true);
require __DIR__ . '/../app/bootstrap.php';

use Shop\Db;

// Nginx на этом сервере слушает только публичный адрес, поэтому по 127.0.0.1
// не достучаться — ходим через домен. И сразу по https: с http стоит редирект,
// а curl на 301 теряет тело POST-запроса.
$base = 'https://ggsell.prettysites.ru';
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--base=')) {
        $base = rtrim(substr($arg, 7), '/');
    }
}
// Без Host запрос уедет в дефолтный виртуальный хост
$host = parse_url($base, PHP_URL_HOST) ?: 'ggsell.prettysites.ru';

$failures = 0;

// ── Мелкие помощники ─────────────────────────────────────────────────

function request(string $method, string $path, array $body = null): array
{
    global $base, $host;

    $ch = curl_init($base . $path);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Host: ' . $host],
        CURLOPT_POSTFIELDS     => $body === null ? null : json_encode($body, JSON_UNESCAPED_UNICODE),
    ]);
    $raw = curl_exec($ch);
    curl_close($ch);

    return json_decode((string) $raw, true) ?? ['raw' => $raw];
}

/**
 * Пачка запросов строго одновременно. Ручки складываем заранее и стартуем
 * одним curl_multi_exec — иначе «параллельность» размажется по времени.
 *
 * @param array<int, array{0:string, 1:array}> $calls путь и тело
 * @return array<int, array>
 */
function parallel(array $calls): array
{
    global $base, $host;

    $multi = curl_multi_init();
    $handles = [];

    foreach ($calls as $i => [$path, $body]) {
        $ch = curl_init($base . $path);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 60,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Host: ' . $host],
            CURLOPT_POSTFIELDS     => json_encode($body, JSON_UNESCAPED_UNICODE),
        ]);
        curl_multi_add_handle($multi, $ch);
        $handles[$i] = $ch;
    }

    do {
        curl_multi_exec($multi, $running);
        if ($running > 0) {
            curl_multi_select($multi, 0.2);
        }
    } while ($running > 0);

    $out = [];
    foreach ($handles as $i => $ch) {
        $out[$i] = json_decode((string) curl_multi_getcontent($ch), true) ?? [];
        curl_multi_remove_handle($multi, $ch);
        curl_close($ch);
    }
    curl_multi_close($multi);

    return $out;
}

function check(string $title, bool $ok, string $detail = ''): void
{
    global $failures;

    if (!$ok) {
        $failures++;
    }
    printf("  [%s] %s%s\n", $ok ? ' ok ' : 'ПРОВАЛ', $title, $detail === '' ? '' : ' — ' . $detail);
}

function usedCodes(): int
{
    return (int) Db::row('SELECT COUNT(*) AS n FROM supplier_stock WHERE taken_by IS NOT NULL')['n'];
}

function newOrder(string $sku = 'STEAM-TOPUP-500', array $extra = []): array
{
    return request('POST', '/api/orders', ['sku' => $sku, 'client_token' => bin2hex(random_bytes(8))] + $extra)['order'];
}

// ── 1. Пятьдесят параллельных вебхуков по одному заказу ──────────────

echo "\n1. 50 параллельных вебхуков «оплачено» по одному заказу\n";
{
    $order = newOrder();
    $before = usedCodes();

    $calls = [];
    for ($i = 0; $i < 50; $i++) {
        $calls[] = ['/api/webhook/payment', [
            'event_id'   => 'evt_' . $order['order_id'] . '_' . $i,
            'order_id'   => $order['order_id'],
            'status'     => 'paid',
            'amount'     => $order['amount'],
            'currency'   => 'RUB',
            'created_at' => gmdate('c'),
        ]];
    }
    parallel($calls);

    // Выдача доводится после ответа платёжке — даём воркеру закончить
    sleep(2);

    $row = Db::row('SELECT status, delivered_code FROM orders WHERE public_id = ?', [$order['order_id']]);
    $issued = (int) Db::row(
        'SELECT COUNT(*) AS n FROM delivery_attempts WHERE order_id = (SELECT id FROM orders WHERE public_id = ?) AND outcome = ?',
        [$order['order_id'], 'ok']
    )['n'];

    check('заказ доставлен', $row['status'] === 'delivered', 'статус ' . $row['status']);
    check('факт выдачи ровно один', $issued === 1, 'успешных попыток: ' . $issued);
    check('израсходован ровно один код', usedCodes() - $before === 1, 'потрачено: ' . (usedCodes() - $before));
    check('код привязан к заказу', !empty($row['delivered_code']), (string) $row['delivered_code']);
}

// ── 2. Повторная доставка того же события ────────────────────────────

echo "\n2. Повтор вебхука с тем же event_id\n";
{
    $order = newOrder();
    $event = ['event_id' => 'evt_repeat_' . $order['order_id'], 'order_id' => $order['order_id'],
              'status' => 'paid', 'amount' => $order['amount'], 'currency' => 'RUB', 'created_at' => gmdate('c')];

    request('POST', '/api/webhook/payment', $event);
    sleep(2);
    $codeAfterFirst = Db::row('SELECT delivered_code FROM orders WHERE public_id = ?', [$order['order_id']])['delivered_code'];
    $before = usedCodes();

    // Тот же event_id ещё двадцать раз, в том числе одновременно
    $calls = array_fill(0, 20, ['/api/webhook/payment', $event]);
    $responses = parallel($calls);
    sleep(1);

    $codeAfterRepeats = Db::row('SELECT delivered_code FROM orders WHERE public_id = ?', [$order['order_id']])['delivered_code'];
    $duplicates = count(array_filter($responses, static fn ($r) => ($r['result'] ?? '') === 'duplicate_ignored'));

    check('повторы распознаны как дубликаты', $duplicates === 20, $duplicates . ' из 20');
    check('код не поменялся', $codeAfterFirst === $codeAfterRepeats, (string) $codeAfterRepeats);
    check('новые коды не тратились', usedCodes() === $before);
}

// ── 3. Вебхук пришёл раньше, чем создан заказ ────────────────────────

echo "\n3. Вебхук раньше создания заказа\n";
{
    // Номер следующего заказа знаем заранее: он идёт по автоинкременту.
    // Через information_schema его брать нельзя — MySQL 8 отдаёт оттуда
    // кешированную статистику и значение отстаёт.
    $next = (int) Db::row('SELECT COALESCE(MAX(id), 0) + 1 AS n FROM orders')['n'];
    $publicId = sprintf('ord_%05d', $next);
    $before = usedCodes();

    $early = request('POST', '/api/webhook/payment', [
        'event_id' => 'evt_early_' . $publicId, 'order_id' => $publicId,
        'status' => 'paid', 'amount' => 500, 'currency' => 'RUB', 'created_at' => gmdate('c'),
    ]);

    check('вебхук принят с 200, а не потерян', ($early['result'] ?? '') === 'stored_until_order_appears', (string) ($early['result'] ?? '?'));

    $order = newOrder();
    sleep(2);
    $row = Db::row('SELECT status, delivered_code FROM orders WHERE public_id = ?', [$order['order_id']]);

    check('заказ получил тот самый номер', $order['order_id'] === $publicId, $order['order_id']);
    check('опоздавший заказ доставлен', $row['status'] === 'delivered', 'статус ' . $row['status']);
    check('израсходован ровно один код', usedCodes() - $before === 1);
}

// ── 4. Пустой пул и восстановление ───────────────────────────────────

echo "\n4. Пустой пул: восстановимое состояние и повторная выдача\n";
{
    // Опустошаем оба склада — сценарий «остаток кончился в момент выдачи»
    Db::exec("UPDATE supplier_stock SET taken_by = CONCAT('drain-', id), taken_at = NOW() WHERE taken_by IS NULL");

    $order = newOrder();
    request('POST', '/api/orders/' . $order['order_id'] . '/pay', ['result' => 'success']);
    sleep(3);

    $row = Db::row('SELECT status FROM orders WHERE public_id = ?', [$order['order_id']]);
    check('заказ в восстановимом статусе, а не в ошибке', $row['status'] === 'out_of_stock', 'статус ' . $row['status']);

    // Пополняем склад и просим выдать повторно — как это сделал бы админ
    Db::exec("INSERT INTO supplier_stock (supplier, code) VALUES ('A', ?)", ['RFIL-' . strtoupper(bin2hex(random_bytes(3)))]);
    $before = usedCodes();

    // Дважды подряд и одновременно: повторная выдача обязана быть идемпотентной
    $token = (string) Shop\Config::get('admin_token');
    $responses = parallel(array_fill(0, 5, ['/api/admin/orders/' . $order['order_id'] . '/redeliver?token=' . $token, []]));
    sleep(2);

    $row = Db::row('SELECT status, delivered_code FROM orders WHERE public_id = ?', [$order['order_id']]);
    check('после пополнения заказ доставлен', $row['status'] === 'delivered', 'статус ' . $row['status']);
    check('пять повторных выдач потратили один код', usedCodes() - $before === 1, 'потрачено: ' . (usedCodes() - $before));
    check('код один и тот же во всех ответах',
        count(array_unique(array_filter(array_map(static fn ($r) => $r['order']['code'] ?? null, $responses)))) === 1);
}

// ── 5. Промокод с лимитом под параллельными запросами ────────────────

echo "\n5. Промокод LIMIT3 под параллельными запросами\n";
{
    Db::exec("UPDATE promo_codes SET used = 0 WHERE code = 'LIMIT3'");

    $calls = [];
    for ($i = 0; $i < 15; $i++) {
        $calls[] = ['/api/orders', [
            'sku'          => 'STEAM-TOPUP-500',
            'promo_code'   => 'LIMIT3',
            'client_token' => 'promo-race-' . bin2hex(random_bytes(6)),
        ]];
    }
    $responses = parallel($calls);

    $accepted = count(array_filter($responses, static fn ($r) => ($r['order']['discount'] ?? 0) > 0));
    $used = (int) Db::row("SELECT used FROM promo_codes WHERE code = 'LIMIT3'")['used'];
    $redemptions = (int) Db::row("SELECT COUNT(*) AS n FROM promo_redemptions WHERE code = 'LIMIT3'")['n'];

    check('скидку получили ровно трое из пятнадцати', $accepted === 3, 'получили: ' . $accepted);
    check('счётчик использований равен трём', $used === 3, 'used = ' . $used);
    check('записей о применении тоже три', $redemptions === 3, 'записей: ' . $redemptions);
}

// ── 6. Двойной клик по «Купить» ──────────────────────────────────────

echo "\n6. Двойной клик по «Купить» (один токен, десять запросов разом)\n";
{
    $token = 'double-click-' . bin2hex(random_bytes(6));
    $responses = parallel(array_fill(0, 10, ['/api/orders', ['sku' => 'KEY-CS2-PRIME', 'client_token' => $token]]));

    $ids = array_unique(array_filter(array_map(static fn ($r) => $r['order']['order_id'] ?? null, $responses)));
    $inDb = (int) Db::row('SELECT COUNT(*) AS n FROM orders WHERE client_token = ?', [$token])['n'];

    check('все десять запросов вернули один заказ', count($ids) === 1, 'разных номеров: ' . count($ids));
    check('в базе один заказ, а не десять', $inDb === 1, 'заказов: ' . $inDb);
}

printf("\n%s\n", $failures === 0 ? 'Все проверки пройдены.' : "Провалов: {$failures}.");
exit($failures === 0 ? 0 : 1);
