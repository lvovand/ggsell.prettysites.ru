<?php

declare(strict_types=1);

namespace Shop;

final class PromoService
{
    /**
     * Скидку считает сервер и только сервер: с клиента приходит один код,
     * ни сумма, ни размер скидки из браузера не принимаются.
     */
    public static function discountFor(string $code, int $baseAmount): int
    {
        $promo = self::find($code);

        if ((int) $promo['used'] >= (int) $promo['max_uses']) {
            throw new ApiException('promo_limit_reached', 409);
        }

        $discount = $promo['type'] === 'percent'
            ? (int) floor($baseAmount * (int) $promo['value'] / 100)
            : (int) $promo['value'];

        // Скидка больше суммы — не уходим в минус и не платим покупателю
        return min($discount, $baseAmount);
    }

    /**
     * Списание одного использования. Здесь и держится лимит под параллельными
     * запросами: условие used < max_uses стоит в самом UPDATE, поэтому решение
     * принимает база, а не PHP. Проверить отдельным SELECT нельзя — между
     * чтением и записью успевают влезть соседние запросы.
     */
    public static function consume(string $code, int $orderId): void
    {
        $first = Db::insertIgnoringDuplicate(
            'INSERT INTO promo_redemptions (code, order_id) VALUES (?, ?)',
            [$code, $orderId]
        );

        if (!$first) {
            // Этот заказ код уже применил, второй раз счётчик не крутим
            return;
        }

        $taken = Db::exec(
            'UPDATE promo_codes SET used = used + 1 WHERE code = ? AND used < max_uses',
            [$code]
        );

        if ($taken !== 1) {
            // Лимит выбрали, пока мы считали скидку. Исключение откатит
            // транзакцию целиком — и запись об использовании, и сам заказ
            throw new ApiException('promo_limit_reached', 409);
        }
    }

    public static function find(string $code): array
    {
        $promo = Db::row('SELECT * FROM promo_codes WHERE code = ?', [$code]);
        if ($promo === null) {
            throw new ApiException('unknown_promo', 404);
        }

        return $promo;
    }

    /** Предпросмотр для витрины: сколько будет стоить с этим кодом. */
    public static function quote(string $code, string $sku): array
    {
        $product = Db::row('SELECT price FROM products WHERE sku = ?', [$sku]);
        if ($product === null) {
            throw new ApiException('unknown_sku', 404);
        }

        $base = (int) $product['price'];
        $discount = self::discountFor($code, $base);

        return [
            'status'      => 'ok',
            'code'        => $code,
            'base_amount' => $base,
            'discount'    => $discount,
            'amount'      => $base - $discount,
        ];
    }
}
