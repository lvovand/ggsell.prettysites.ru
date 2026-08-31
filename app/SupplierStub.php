<?php

declare(strict_types=1);

namespace Shop;

use PDOException;

/**
 * Заглушка поставщика кодов. Реального поставщика нам не дали, поэтому
 * реализуем его по контракту — вместе с умением падать и зависать.
 *
 * Обращаемся к ней по HTTP, а не вызовом метода, специально: только так
 * воспроизводятся таймаут и «ответ не дошёл, а код уже выдан».
 */
final class SupplierStub
{
    /** @return array{0:int, 1:array} http-код и тело ответа */
    public static function issue(string $supplier, array $request, array $faults): array
    {
        $requestId = trim((string) ($request['request_id'] ?? ''));
        if ($requestId === '') {
            return [400, ['status' => 'error', 'reason' => 'request_id_required']];
        }

        // Повтор с тем же request_id обязан вернуть тот же код — это прямое
        // требование контракта и единственная защита от двойной выдачи по таймауту
        $already = self::codeFor($supplier, $requestId);
        if ($already !== null) {
            return [200, ['status' => 'ok', 'request_id' => $requestId, 'code' => $already, 'repeat' => true]];
        }

        // Отказ имитируем до того, как взяли код: поставщик просто не смог обслужить
        if (self::roll((float) ($faults['fail_rate'] ?? 0))) {
            return [503, ['status' => 'error', 'reason' => 'supplier_unavailable']];
        }

        $code = self::take($supplier, $requestId);
        if ($code === null) {
            return [409, ['status' => 'error', 'reason' => 'out_of_stock']];
        }

        // А вот зависание — уже ПОСЛЕ выдачи. В этом вся ловушка: код израсходован,
        // ответ клиент не получил. Наш повтор с тем же request_id вернётся сюда
        // и попадёт в ветку $already выше.
        if (self::roll((float) ($faults['timeout_rate'] ?? 0))) {
            sleep((int) ($faults['hang_seconds'] ?? 6));
        }

        return [200, ['status' => 'ok', 'request_id' => $requestId, 'code' => $code]];
    }

    /** Атомарный захват свободного кода одним UPDATE — без гонки «прочитал и занял». */
    private static function take(string $supplier, string $requestId): ?string
    {
        try {
            $claimed = Db::exec(
                'UPDATE supplier_stock SET taken_by = ?, taken_at = NOW()
                 WHERE supplier = ? AND taken_by IS NULL
                 ORDER BY id LIMIT 1',
                [$requestId, $supplier]
            );
        } catch (PDOException $e) {
            // Два одновременных запроса с одним request_id: второй упёрся
            // в UNIQUE(taken_by). Значит код уже взят — его и отдаём.
            if ($e->errorInfo[1] === 1062) {
                return self::codeFor($supplier, $requestId);
            }
            throw $e;
        }

        return $claimed === 1 ? self::codeFor($supplier, $requestId) : null;
    }

    private static function codeFor(string $supplier, string $requestId): ?string
    {
        $row = Db::row(
            'SELECT code FROM supplier_stock WHERE supplier = ? AND taken_by = ?',
            [$supplier, $requestId]
        );

        return $row === null ? null : (string) $row['code'];
    }

    private static function roll(float $probability): bool
    {
        return $probability > 0 && mt_rand(1, 1000) <= (int) round($probability * 1000);
    }
}
