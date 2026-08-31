<?php

declare(strict_types=1);

namespace Shop;

// Тонкая обёртка над массивом конфига, чтобы не таскать его параметром
// через все слои и не заводить глобальную переменную.
final class Config
{
    private static array $values = [];

    public static function set(array $values): void
    {
        self::$values = $values;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        return self::$values[$key] ?? $default;
    }

    /**
     * Свой базовый адрес. Обращаться к заглушкам по 127.0.0.1 нельзя:
     * без заголовка Host запрос уедет в чужой виртуальный хост, поэтому
     * берём тот же адрес, по которому пришёл текущий запрос.
     */
    public static function baseUrl(): string
    {
        $configured = (string) (self::$values['base_url'] ?? '');
        if ($configured !== '') {
            return rtrim($configured, '/');
        }

        $host = $_SERVER['HTTP_HOST'] ?? '127.0.0.1';
        $https = ($_SERVER['HTTPS'] ?? '') === 'on'
            || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';

        return ($https ? 'https://' : 'http://') . $host;
    }
}
