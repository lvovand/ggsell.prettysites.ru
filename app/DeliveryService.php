<?php

declare(strict_types=1);

namespace Shop;

final class DeliveryService
{
    /**
     * Получение кода у поставщика и привязка его к заказу.
     *
     * Идемпотентность держится на двух вещах:
     *   - request_id детерминированный (номер заказа + буква поставщика), поэтому
     *     повтор после таймаута уходит с тем же request_id и поставщик по контракту
     *     обязан вернуть тот же код — двойной выдачи не будет;
     *   - код проставляется условным UPDATE с проверкой delivered_code IS NULL,
     *     так что второй заход по уже выданному заказу ничего не перезапишет.
     *
     * Метод безопасно вызывать повторно: из вебхука, из админки, из фонового добора.
     */
    public static function deliver(int $orderId): string
    {
        $order = OrderService::byId($orderId);

        if ($order['status'] === 'delivered') {
            return 'delivered';
        }
        if (!in_array($order['status'], ['paid', 'delivering', 'out_of_stock', 'delivery_failed'], true)) {
            // created, payment_failed — выдавать нечего
            return $order['status'];
        }

        // Помечаем, что пошли за кодом. Заодно это отсекает второй одновременный
        // заход: строку в delivering переводит только один.
        Db::exec(
            "UPDATE orders SET status = 'delivering'
             WHERE id = ? AND status IN ('paid', 'out_of_stock', 'delivery_failed')",
            [$orderId]
        );

        $sku = (string) Db::row('SELECT sku FROM products WHERE id = ?', [$order['product_id']])['sku'];
        $lastReason = 'unknown';

        // Сначала основной поставщик, потом резервный — как в контракте
        foreach (['A', 'B'] as $supplier) {
            $requestId = sprintf('req_%s-%s', $order['public_id'], strtolower($supplier));
            $result = self::ask($supplier, $requestId, $sku, $order['public_id'], $orderId);

            if ($result['status'] === 'ok') {
                self::attach($orderId, (string) $result['code']);

                return 'delivered';
            }

            $lastReason = (string) ($result['reason'] ?? 'error');

            // Кончился остаток — резервный поставщик тут не поможет,
            // это восстановимое состояние, ждём пополнения
            if ($lastReason === 'out_of_stock') {
                Db::exec(
                    "UPDATE orders SET status = 'out_of_stock' WHERE id = ? AND delivered_code IS NULL",
                    [$orderId]
                );

                return 'out_of_stock';
            }
        }

        // Оба поставщика не смогли: заказ оплачен, кода нет — но это не падение,
        // а состояние, из которого админка умеет вытащить
        Db::exec(
            "UPDATE orders SET status = 'delivery_failed' WHERE id = ? AND delivered_code IS NULL",
            [$orderId]
        );
        error_log(sprintf('заказ %s: выдача не удалась, последняя причина %s', $order['public_id'], $lastReason));

        return 'delivery_failed';
    }

    /** Один поход к заглушке-поставщику по HTTP. */
    private static function ask(string $supplier, string $requestId, string $sku, string $orderPublic, int $orderId): array
    {
        $config = Config::get('suppliers')[$supplier] ?? null;
        if ($config === null) {
            return ['status' => 'error', 'reason' => 'no_supplier'];
        }

        // Попытку фиксируем до вызова: если процесс умрёт посреди запроса,
        // в базе всё равно останется след, по какому request_id надо переспросить
        Db::insertIgnoringDuplicate(
            'INSERT INTO delivery_attempts (request_id, order_id, supplier) VALUES (?, ?, ?)',
            [$requestId, $orderId, $supplier]
        );

        $payload = json_encode([
            'request_id' => $requestId,
            'sku'        => $sku,
            'order_id'   => $orderPublic,
        ], JSON_UNESCAPED_UNICODE);

        $ch = curl_init(Config::baseUrl() . $config['path']);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => (int) $config['timeout'],
            CURLOPT_CONNECTTIMEOUT => 3,
        ]);
        $body = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_errno($ch);
        curl_close($ch);

        if ($curlError === CURLE_OPERATION_TIMEDOUT) {
            // Ключевая ловушка: таймаут не значит отказ. Поставщик мог выдать код,
            // а ответ не дошёл — поэтому не выдаём новый, а переспрашиваем тем же request_id
            self::finishAttempt($requestId, 'timeout', null, 'ответ не дошёл');

            return self::retryAfterTimeout($supplier, $requestId, $sku, $orderPublic, $config);
        }

        $data = json_decode((string) $body, true);
        if (!is_array($data)) {
            self::finishAttempt($requestId, 'error', null, 'нечитаемый ответ, http ' . $httpCode);

            return ['status' => 'error', 'reason' => 'bad_response'];
        }

        if ($httpCode === 200 && ($data['status'] ?? '') === 'ok') {
            self::finishAttempt($requestId, 'ok', (string) $data['code']);

            return $data;
        }

        $reason = (string) ($data['reason'] ?? 'error');
        self::finishAttempt($requestId, $reason === 'out_of_stock' ? 'out_of_stock' : 'error', null, $reason);

        return ['status' => 'error', 'reason' => $reason];
    }

    /**
     * Переспрос после таймаута. Тот же request_id — если код уже был выдан,
     * поставщик вернёт именно его.
     */
    private static function retryAfterTimeout(string $supplier, string $requestId, string $sku, string $orderPublic, array $config): array
    {
        $ch = curl_init(Config::baseUrl() . $config['path']);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode([
                'request_id' => $requestId,
                'sku'        => $sku,
                'order_id'   => $orderPublic,
            ], JSON_UNESCAPED_UNICODE),
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'X-Retry: 1'],
            CURLOPT_RETURNTRANSFER => true,
            // на повторе ждём дольше: висящая заглушка к этому моменту обычно отпускает
            CURLOPT_TIMEOUT        => (int) $config['timeout'] + 5,
            CURLOPT_CONNECTTIMEOUT => 3,
        ]);
        $body = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $data = json_decode((string) $body, true);
        if ($httpCode === 200 && is_array($data) && ($data['status'] ?? '') === 'ok') {
            self::finishAttempt($requestId, 'ok', (string) $data['code'], 'получено переспросом после таймаута');

            return $data;
        }

        return ['status' => 'error', 'reason' => is_array($data) ? (string) ($data['reason'] ?? 'timeout') : 'timeout'];
    }

    private static function finishAttempt(string $requestId, string $outcome, ?string $code, ?string $note = null): void
    {
        Db::exec(
            'UPDATE delivery_attempts SET outcome = ?, code = ?, note = ? WHERE request_id = ?',
            [$outcome, $code, $note, $requestId]
        );
    }

    /**
     * Привязка кода к заказу. Условие delivered_code IS NULL — последний рубеж:
     * даже если сюда каким-то образом придут два кода, запишется первый.
     */
    private static function attach(int $orderId, string $code): void
    {
        Db::exec(
            "UPDATE orders SET status = 'delivered', delivered_code = ?
             WHERE id = ? AND delivered_code IS NULL",
            [$code, $orderId]
        );
    }
}
