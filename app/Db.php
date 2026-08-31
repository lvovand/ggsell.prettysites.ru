<?php

declare(strict_types=1);

namespace Shop;

use PDO;
use PDOException;

// Одно соединение на запрос. Ленивое: админка и часть эндпоинтов
// до базы могут и не дойти.
final class Db
{
    private static array $config = [];
    private static ?PDO $pdo = null;

    public static function configure(array $config): void
    {
        self::$config = $config;
    }

    public static function pdo(): PDO
    {
        if (self::$pdo instanceof PDO) {
            return self::$pdo;
        }

        $c = self::$config;
        $dsn = sprintf('mysql:host=%s;dbname=%s;charset=%s', $c['host'], $c['name'], $c['charset'] ?? 'utf8mb4');

        self::$pdo = new PDO($dsn, $c['user'], $c['password'], [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            // Настоящие подготовленные выражения, а не эмуляция: параметры уходят
            // на сервер отдельно от текста запроса, и числа остаются числами —
            // иначе условные UPDATE, на которых всё держится, сравнивают строки
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);

        return self::$pdo;
    }

    /** @return array<int, array<string, mixed>> */
    public static function all(string $sql, array $params = []): array
    {
        $st = self::pdo()->prepare($sql);
        $st->execute($params);

        return $st->fetchAll();
    }

    public static function row(string $sql, array $params = []): ?array
    {
        $st = self::pdo()->prepare($sql);
        $st->execute($params);
        $row = $st->fetch();

        return $row === false ? null : $row;
    }

    /** Сколько строк реально изменилось — на этом держатся все переходы статусов. */
    public static function exec(string $sql, array $params = []): int
    {
        $st = self::pdo()->prepare($sql);
        $st->execute($params);

        return $st->rowCount();
    }

    /**
     * INSERT, который может упереться в UNIQUE. Дубликат для нас не ошибка,
     * а нормальный ответ «такое уже было» — возвращаем false.
     */
    public static function insertIgnoringDuplicate(string $sql, array $params = []): bool
    {
        try {
            self::pdo()->prepare($sql)->execute($params);

            return true;
        } catch (PDOException $e) {
            if ($e->errorInfo[1] === 1062) {
                return false;
            }
            throw $e;
        }
    }

    public static function transaction(callable $fn): mixed
    {
        $pdo = self::pdo();
        $pdo->beginTransaction();
        try {
            $result = $fn($pdo);
            $pdo->commit();

            return $result;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }
}
