<?php
// Единственная точка входа в API. Роутинг простой руками: маршрутов десяток,
// тащить ради них фреймворк смысла нет.

declare(strict_types=1);

define('APP', true);
require __DIR__ . '/../app/bootstrap.php';

use Shop\ApiException;
use Shop\Config;
use Shop\Db;
use Shop\DeliveryService;
use Shop\Http;
use Shop\OrderService;
use Shop\PaymentService;
use Shop\PromoService;
use Shop\SupplierStub;

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$path = '/' . trim(preg_replace('#^/api#', '', $path) ?? '', '/');
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$input = Http::input();

try {
    // ── Витрина ──────────────────────────────────────────────────────
    if ($path === '/products' && $method === 'GET') {
        Http::json([
            'status'   => 'ok',
            'products' => Db::all('SELECT sku, name, type, price, currency, image FROM products ORDER BY id'),
        ]);
    }

    // ── Заказы ───────────────────────────────────────────────────────
    if ($path === '/orders' && $method === 'POST') {
        $order = OrderService::create(
            (string) ($input['sku'] ?? ''),
            isset($input['client_token']) ? (string) $input['client_token'] : null,
            isset($input['promo_code']) ? trim((string) $input['promo_code']) : null
        );

        Http::json(['status' => 'ok', 'order' => OrderService::present($order)], 201);
    }

    if (preg_match('#^/orders/([\w-]+)$#', $path, $m) && $method === 'GET') {
        $order = OrderService::byPublicId($m[1]);
        if ($order === null) {
            Http::fail('unknown_order', 404);
        }
        Http::json(['status' => 'ok', 'order' => OrderService::present($order)]);
    }

    // Эмуляция оплаты: кнопка «оплатить (успех/неуспех)». Реальных денег нет,
    // задача этой ручки — отправить вебхук по контракту, как это сделала бы платёжка.
    if (preg_match('#^/orders/([\w-]+)/pay$#', $path, $m) && $method === 'POST') {
        $order = OrderService::byPublicId($m[1]);
        if ($order === null) {
            Http::fail('unknown_order', 404);
        }

        $ok = !in_array((string) ($input['result'] ?? 'success'), ['fail', 'failed', 'false', '0'], true);
        $event = [
            'event_id'   => $input['event_id'] ?? ('evt_' . bin2hex(random_bytes(6))),
            'order_id'   => $order['public_id'],
            'status'     => $ok ? 'paid' : 'failed',
            'amount'     => (int) $order['amount'],
            'currency'   => 'RUB',
            'created_at' => gmdate('c'),
        ];

        $ch = curl_init(Config::baseUrl() . '/api/webhook/payment');
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($event, JSON_UNESCAPED_UNICODE),
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
        ]);
        $body = curl_exec($ch);
        curl_close($ch);

        Http::json([
            'status'   => 'ok',
            'sent'     => $event,
            'webhook'  => json_decode((string) $body, true),
        ]);
    }

    // ── Вебхук платёжной системы ─────────────────────────────────────
    if ($path === '/webhook/payment' && $method === 'POST') {
        Http::json(PaymentService::handleWebhook($input));
    }

    // ── Промокоды ────────────────────────────────────────────────────
    if ($path === '/promo/quote' && $method === 'POST') {
        Http::json(PromoService::quote(
            trim((string) ($input['code'] ?? '')),
            (string) ($input['sku'] ?? '')
        ));
    }

    // ── Заглушки поставщиков ─────────────────────────────────────────
    if (preg_match('#^/supplier/([ab])/issue$#', $path, $m) && $method === 'POST') {
        $supplier = strtoupper($m[1]);
        $faults = Config::get('supplier_faults')[$supplier] ?? [];

        // Долю сбоев можно задрать прямо в запросе — так сценарии этапа 3
        // воспроизводятся без правки конфига и перезапуска
        foreach (['fail_rate', 'timeout_rate', 'hang_seconds'] as $knob) {
            if (isset($_GET[$knob])) {
                $faults[$knob] = (float) $_GET[$knob];
            }
        }

        [$code, $body] = SupplierStub::issue($supplier, $input, $faults);
        Http::json($body, $code);
    }

    // ── Админка ──────────────────────────────────────────────────────
    if (str_starts_with($path, '/admin/')) {
        requireAdmin();

        if ($path === '/admin/orders' && $method === 'GET') {
            $status = (string) ($_GET['status'] ?? '');
            $sql = 'SELECT o.*, p.sku FROM orders o JOIN products p ON p.id = o.product_id';
            $params = [];
            if ($status !== '') {
                // «оплачен, но не выдан» — это два восстановимых статуса разом
                if ($status === 'stuck') {
                    $sql .= " WHERE o.status IN ('paid', 'delivering', 'out_of_stock', 'delivery_failed')";
                } else {
                    $sql .= ' WHERE o.status = ?';
                    $params[] = $status;
                }
            }
            $sql .= ' ORDER BY o.id DESC LIMIT 200';

            Http::json(['status' => 'ok', 'orders' => Db::all($sql, $params)]);
        }

        if (preg_match('#^/admin/orders/([\w-]+)/redeliver$#', $path, $m) && $method === 'POST') {
            $order = OrderService::byPublicId($m[1]);
            if ($order === null) {
                Http::fail('unknown_order', 404);
            }

            // Повторная выдача идемпотентна: если код уже привязан,
            // DeliveryService сразу вернёт delivered и никуда не пойдёт
            $result = DeliveryService::deliver((int) $order['id']);

            Http::json([
                'status' => 'ok',
                'result' => $result,
                'order'  => OrderService::present(OrderService::byPublicId($m[1]) ?? $order),
            ]);
        }

        // Пополнение склада поставщика — вторая половина сценария «остаток кончился»
        if ($path === '/admin/stock/refill' && $method === 'POST') {
            $supplier = strtoupper((string) ($input['supplier'] ?? 'A'));
            $count = max(1, min(200, (int) ($input['count'] ?? 10)));
            $added = 0;

            for ($i = 0; $i < $count; $i++) {
                $added += Db::insertIgnoringDuplicate(
                    'INSERT INTO supplier_stock (supplier, code) VALUES (?, ?)',
                    [$supplier, randomCode()]
                ) ? 1 : 0;
            }

            Http::json(['status' => 'ok', 'supplier' => $supplier, 'added' => $added]);
        }

        if ($path === '/admin/stock' && $method === 'GET') {
            Http::json([
                'status' => 'ok',
                'stock'  => Db::all(
                    'SELECT supplier, COUNT(*) AS total, SUM(taken_by IS NULL) AS free
                     FROM supplier_stock GROUP BY supplier'
                ),
            ]);
        }

        // Догнать события, которые пришли раньше своих заказов
        if ($path === '/admin/webhooks/replay' && $method === 'POST') {
            $pending = Db::all(
                'SELECT DISTINCT order_public FROM webhook_events WHERE applied_at IS NULL'
            );
            foreach ($pending as $row) {
                PaymentService::applyPending($row['order_public']);
            }

            Http::json(['status' => 'ok', 'checked' => count($pending)]);
        }
    }

    Http::fail('not_found', 404);
} catch (ApiException $e) {
    Http::fail($e->getMessage(), $e->httpStatus());
} catch (Throwable $e) {
    // Наружу — ровный 500 без внутренностей, подробности в лог
    error_log(sprintf('%s: %s (%s:%d)', $path, $e->getMessage(), $e->getFile(), $e->getLine()));
    Http::fail('internal_error', 500);
}

function requireAdmin(): void
{
    $expected = (string) Config::get('admin_token');
    $given = $_SERVER['HTTP_X_ADMIN_TOKEN'] ?? ($_GET['token'] ?? ($_POST['token'] ?? ''));

    if ($expected === '' || !hash_equals($expected, (string) $given)) {
        Http::fail('forbidden', 403);
    }
}

function randomCode(): string
{
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
    $parts = [];
    for ($block = 0; $block < 3; $block++) {
        $chunk = '';
        for ($i = 0; $i < 4; $i++) {
            $chunk .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }
        $parts[] = $chunk;
    }

    return implode('-', $parts);
}
