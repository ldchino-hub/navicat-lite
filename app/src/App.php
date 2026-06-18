<?php
declare(strict_types=1);

namespace Navicat;

use Navicat\Database;
use PDO;

final class App
{
    /** @var array<string,mixed> */
    private static array $config = [];
    private static ?PDO $db = null;

    /** @param array<string,mixed> $config */
    public static function init(array $config): void
    {
        self::$config = $config;
        $dir = dirname((string)$config['database_path']);
        if (!is_dir($dir)) mkdir($dir, 0755, true);
        $backupDir = (string)$config['backup_dir'];
        if (!is_dir($backupDir)) mkdir($backupDir, 0755, true);
        self::$db = Database::connect((string)$config['database_path']);
        Database::migrateAll(self::$db, dirname(__DIR__) . '/migrations');
    }

    /** @return array<string,mixed> */
    public static function config(): array
    {
        return self::$config;
    }

    public static function db(): PDO
    {
        if (!self::$db) throw new \RuntimeException('App not initialized');
        return self::$db;
    }
}
