<?php
// Точка входа для всего серверного кода: конфиг, автозагрузка, единый режим ошибок.
// Каталог app/ закрыт от веба (.htaccess + проверка ниже), сюда попадают
// только через api/index.php и скрипты из tools/.

declare(strict_types=1);

if (!defined('APP')) {
    http_response_code(404);
    exit;
}

date_default_timezone_set('UTC');
mb_internal_encoding('UTF-8');

// Ошибки не показываем наружу, но и не глотаем — пишем в лог рядом с кодом.
ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', __DIR__ . '/../storage/php-error.log');
error_reporting(E_ALL);

$config = require __DIR__ . '/config.php';
if (is_file(__DIR__ . '/config.local.php')) {
    $config = array_replace_recursive($config, require __DIR__ . '/config.local.php');
}

spl_autoload_register(static function (string $class): void {
    if (!str_starts_with($class, 'Shop\\')) {
        return;
    }
    $file = __DIR__ . '/' . str_replace('\\', '/', substr($class, 5)) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

Shop\Db::configure($config['db']);
Shop\Config::set($config);
