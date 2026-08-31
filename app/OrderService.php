<?php

declare(strict_types=1);

namespace Shop;

use PDOException;

final class OrderService
{
    /**
     * Создание заказа. Всё считаем на сервере: с клиента приходят только sku,
     * промокод и токен от двойного клика — цене и скидке из браузера не верим.
     */
    public static function create(string $sku, ?string $clientToken, ?string $promoCode): array
    {
        $product = Db::row('SELECT * FROM products WHERE sku = ?', [$sku]);
        if ($product === null) {
            Http::fail('unknown_sku', 404);
        }

        // Двойной клик по «Купить»: если заказ с таким токеном уже есть, отдаём его,
        // а не плодим второй. Гонку двух одновременных кликов ловим ниже, на UNIQUE.
        if ($clientToken !== null && $clientToken !== '') {
            $existing = self::findByToken($clientToken);
            if ($existing !== null) {
                return $existing;
            }
        }

        try {
            $order = Db::transaction(static function () use ($product, $clientToken, $promoCode): array {
                $base = (int) $product['price'];
                $discount = 0;

                if ($promoCode !== null && $promoCode !== '') {
                    $discount = PromoService::discountFor($promoCode, $base);
                }

                $amount = max(0, $base - $discount);

                // public_id хочется человеческий и по порядку, а он зависит от
                // автоинкремента — поэтому сначала вставляем с временным значением
                Db::exec(
                    'INSERT INTO orders (public_id, product_id, amount, base_amount, promo_code, discount, client_token)
                     VALUES (?, ?, ?, ?, ?, ?, ?)',
                    [
                        'tmp_' . bin2hex(random_bytes(8)),
                        $product['id'],
                        $amount,
                        $base,
                        $discount > 0 ? $promoCode : null,
                        $discount,
                        $clientToken !== '' ? $clientToken : null,
                    ]
                );

                $id = (int) Db::pdo()->lastInsertId();
                $publicId = sprintf('ord_%05d', $id);
                Db::exec('UPDATE orders SET public_id = ? WHERE id = ?', [$publicId, $id]);

                // Использование промокода списываем здесь же: если лимит исчерпан,
                // транзакция откатится целиком и заказа со скидкой не появится
                if ($discount > 0) {
                    PromoService::consume((string) $promoCode, $id);
                }

                return self::byId($id);
            });
        } catch (PDOException $e) {
            // Два одновременных клика: второй упёрся в UNIQUE(client_token).
            // Это не ошибка — просто возвращаем заказ, который создал первый.
            if ($e->errorInfo[1] === 1062 && $clientToken !== null) {
                $existing = self::findByToken($clientToken);
                if ($existing !== null) {
                    return $existing;
                }
            }
            throw $e;
        }

        // Вебхук мог прийти раньше, чем заказ появился в базе. Такие события
        // лежат непринятыми — самое время их применить.
        PaymentService::applyPending($order['public_id']);

        return self::byPublicId($order['public_id']) ?? $order;
    }

    public static function byId(int $id): array
    {
        $row = Db::row('SELECT * FROM orders WHERE id = ?', [$id]);
        if ($row === null) {
            throw new \RuntimeException('заказ ' . $id . ' исчез между вставкой и чтением');
        }

        return $row;
    }

    public static function byPublicId(string $publicId): ?array
    {
        return Db::row('SELECT * FROM orders WHERE public_id = ?', [$publicId]);
    }

    private static function findByToken(string $token): ?array
    {
        return Db::row('SELECT * FROM orders WHERE client_token = ?', [$token]);
    }

    /** То, что уходит на страницу статуса заказа. */
    public static function present(array $order): array
    {
        $product = Db::row('SELECT sku, name, image FROM products WHERE id = ?', [$order['product_id']]);

        return [
            'order_id'    => $order['public_id'],
            'status'      => $order['status'],
            'product'     => $product,
            'base_amount' => (int) $order['base_amount'],
            'discount'    => (int) $order['discount'],
            'amount'      => (int) $order['amount'],
            'promo_code'  => $order['promo_code'],
            'currency'    => 'RUB',
            // Код показываем только когда он действительно выдан
            'code'        => $order['status'] === 'delivered' ? $order['delivered_code'] : null,
            'created_at'  => $order['created_at'],
        ];
    }
}
