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
        $dir = dirname((string)($config['database_path'] ?? (__DIR__ . '/../storage/database.sqlite')));
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        $backupDir = (string)$config['backup_dir'];
        if (!is_dir($backupDir)) {
            mkdir($backupDir, 0755, true);
        }
        self::disconnect();
        self::$db = Database::connectFromConfig($config);
        Database::migrateAll(self::$db, Database::migrationsDir());
    }

    /** @return array<string,mixed> */
    public static function config(): array
    {
        return self::$config;
    }

    public static function disconnect(): void
    {
        self::$db = null;
    }

    public static function db(): PDO
    {
        if (!self::$db) {
            if (self::$config === []) {
                throw new \RuntimeException('App not initialized');
            }
            self::$db = Database::connectFromConfig(self::$config);
        }
        if (Database::isMysql()) {
            try {
                self::$db->query('SELECT 1');
            } catch (\PDOException $e) {
                $msg = $e->getMessage();
                if (str_contains($msg, 'Too many connections') || str_contains($msg, '[1040]')) {
                    throw $e;
                }
                self::disconnect();
                self::$db = Database::connectFromConfig(self::$config);
            }
        }
        return self::$db;
    }

    public static function isMysql(): bool
    {
        return Database::isMysql();
    }
}
