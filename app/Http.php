<?php

declare(strict_types=1);

namespace Shop;

final class Http
{
    private static bool $sent = false;

    public static function json(array $data, int $status = 200): never
    {
        if (!self::$sent) {
            http_response_code($status);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        exit;
    }

    /**
     * Отдать ответ и продолжить работу. Нужно вебхуку: платёжка ждёт быстрый 200,
     * а выдача может упереться в зависшего поставщика на несколько секунд.
     * В проде на этом месте была бы очередь, здесь достаточно php-fpm.
     */
    public static function respondEarly(array $data, int $status = 200): void
    {
        if (self::$sent) {
            return;
        }
        self::$sent = true;

        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        $body = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        header('Content-Length: ' . strlen((string) $body));
        echo $body;

        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        } else {
            @ob_end_flush();
            flush();
        }

        // Клиент отвалился — доводить выдачу всё равно надо
        ignore_user_abort(true);
        set_time_limit(60);
    }

    public static function fail(string $message, int $status = 400, array $extra = []): never
    {
        self::json(['status' => 'error', 'reason' => $message] + $extra, $status);
    }

    /** Тело запроса. Принимаем и JSON, и обычную форму — тестам так удобнее. */
    public static function input(): array
    {
        $raw = file_get_contents('php://input') ?: '';
        if ($raw !== '' && str_contains((string) ($_SERVER['CONTENT_TYPE'] ?? ''), 'json')) {
            $data = json_decode($raw, true);

            return is_array($data) ? $data : [];
        }

        return $_POST + $_GET;
    }
}
