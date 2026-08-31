<?php

declare(strict_types=1);

namespace Shop;

final class PaymentService
{
    /**
     * Обработка вебхука. Платёжка доставляет at-least-once и не по порядку,
     * поэтому здесь три отдельные защиты, и ни одна не полагается на PHP:
     *   1. UNIQUE(event_id) — повторную доставку отсекает база;
     *   2. UPDATE ... WHERE status = 'created' — из 50 параллельных вебхуков
     *      к выдаче проходит ровно тот, чей UPDATE изменил строку;
     *   3. событие для несуществующего заказа сохраняется и ждёт своего заказа.
     */
    public static function handleWebhook(array $payload): array
    {
        $eventId = trim((string) ($payload['event_id'] ?? ''));
        $orderPublic = trim((string) ($payload['order_id'] ?? ''));
        $status = (string) ($payload['status'] ?? '');

        if ($eventId === '' || $orderPublic === '' || !in_array($status, ['paid', 'failed'], true)) {
            Http::fail('bad_payload', 400);
        }

        $fresh = Db::insertIgnoringDuplicate(
            'INSERT INTO webhook_events (event_id, order_public, status, amount, payload)
             VALUES (?, ?, ?, ?, ?)',
            [
                $eventId,
                $orderPublic,
                $status,
                isset($payload['amount']) ? (int) $payload['amount'] : null,
                json_encode($payload, JSON_UNESCAPED_UNICODE),
            ]
        );

        if (!$fresh) {
            // Ровно тот случай, ради которого event_id и нужен: повтор ничего не меняет
            return ['status' => 'ok', 'result' => 'duplicate_ignored'];
        }

        $order = OrderService::byPublicId($orderPublic);
        if ($order === null) {
            // Вебхук обогнал создание заказа. Ответить надо 200, иначе платёжка
            // будет долбиться повторами; событие подхватится в OrderService::create
            return ['status' => 'ok', 'result' => 'stored_until_order_appears'];
        }

        [$result, $deliverId] = self::apply($eventId, $order);

        if ($deliverId !== null) {
            // Платёжке отвечаем сразу: она ждёт быстрый 200, а поставщик
            // по контракту умеет висеть по несколько секунд
            Http::respondEarly(['status' => 'ok', 'result' => $result]);
            DeliveryService::deliver($deliverId);
        }

        return ['status' => 'ok', 'result' => $result];
    }

    /**
     * Применение уже сохранённого события к заказу.
     *
     * Сама выдача отсюда не запускается: этот метод зовут из двух разных мест
     * (вебхук и создание заказа), а отвечать раньше времени можно только вебхуку.
     * Возвращаем номер заказа, который надо доставить, и решение принимает вызывающий.
     *
     * @return array{0:string, 1:int|null}
     */
    private static function apply(string $eventId, array $order): array
    {
        $event = Db::row('SELECT * FROM webhook_events WHERE event_id = ?', [$eventId]);
        if ($event === null || $event['applied_at'] !== null) {
            return ['already_applied', null];
        }

        $orderId = (int) $order['id'];

        if ($event['status'] === 'failed') {
            // Финальные статусы не трогаем: если заказ уже оплачен и выдан,
            // опоздавший «failed» не должен его ломать
            Db::exec("UPDATE orders SET status = 'payment_failed' WHERE id = ? AND status = 'created'", [$orderId]);
            self::markApplied($eventId);

            return ['payment_failed', null];
        }

        // Сумму сверяем, но заказ из-за расхождения не рушим — только пишем в лог:
        // по ТЗ реальных денег нет, а падать на этом под нагрузкой незачем
        if ($event['amount'] !== null && (int) $event['amount'] !== (int) $order['amount']) {
            error_log(sprintf(
                'вебхук %s: сумма %d не совпала с заказом %s (%d)',
                $eventId, $event['amount'], $order['public_id'], $order['amount']
            ));
        }

        // Вот здесь и решается гонка. Строку меняет ровно один запрос,
        // остальные получают rowCount 0 и до поставщика не доходят.
        $won = Db::exec("UPDATE orders SET status = 'paid' WHERE id = ? AND status = 'created'", [$orderId]) === 1;
        self::markApplied($eventId);

        if (!$won) {
            return ['already_paid', null];
        }

        return ['paid', $orderId];
    }

    /** События, пришедшие раньше заказа. Вызывается сразу после его создания. */
    public static function applyPending(string $orderPublic): void
    {
        $order = OrderService::byPublicId($orderPublic);
        if ($order === null) {
            return;
        }

        $pending = Db::all(
            'SELECT event_id FROM webhook_events
             WHERE order_public = ? AND applied_at IS NULL
             ORDER BY id',
            [$orderPublic]
        );

        foreach ($pending as $row) {
            [, $deliverId] = self::apply($row['event_id'], $order);

            if ($deliverId !== null) {
                // Здесь торопиться некуда: это ответ на создание заказа,
                // покупателю всё равно нужен готовый статус
                DeliveryService::deliver($deliverId);
            }

            // Статус заказа мог измениться предыдущим событием — перечитываем
            $order = OrderService::byPublicId($orderPublic) ?? $order;
        }
    }

    private static function markApplied(string $eventId): void
    {
        Db::exec('UPDATE webhook_events SET applied_at = NOW() WHERE event_id = ? AND applied_at IS NULL', [$eventId]);
    }
}
