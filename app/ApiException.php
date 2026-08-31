<?php

declare(strict_types=1);

namespace Shop;

// Ожидаемая ошибка бизнес-логики: неизвестный sku, исчерпанный промокод и т.п.
// Отличается от обычного исключения тем, что её текст можно показать наружу,
// а роутер превращает её в аккуратный JSON вместо 500.
final class ApiException extends \RuntimeException
{
    public function __construct(string $reason, private readonly int $httpStatus = 400)
    {
        parent::__construct($reason);
    }

    public function httpStatus(): int
    {
        return $this->httpStatus;
    }
}
