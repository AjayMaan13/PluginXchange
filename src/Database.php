<?php

declare(strict_types=1);

namespace PluginXchange;

use PDO;
use Throwable;

final class Database
{
    private static ?PDO $pdo = null;

    public static function connection(): PDO
    {
        return self::$pdo ??= new PDO(
            self::dsn(),
            self::env('DB_USER', 'pluginxchange'),
            self::env('DB_PASS', 'pluginxchange'),
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]
        );
    }

    public static function dsn(): string
    {
        return sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
            self::env('DB_HOST', '127.0.0.1'),
            self::env('DB_PORT', '3306'),
            self::env('DB_NAME', 'pluginxchange')
        );
    }

    public static function ping(): bool
    {
        try {
            return (int) self::connection()->query('SELECT 1')->fetchColumn() === 1;
        } catch (Throwable $e) {
            error_log('DB ping failed: ' . $e->getMessage());
            return false;
        }
    }

    private static function env(string $name, string $default): string
    {
        $value = getenv($name);
        return $value === false || $value === '' ? $default : $value;
    }
}
