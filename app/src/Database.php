<?php
declare(strict_types=1);

namespace Navicat;

use Navicat\Support\PdoMysqlOptions;
use PDO;

final class Database
{
    private static string $driver = 'sqlite';

    public static function driver(): string
    {
        return self::$driver;
    }

    public static function isMysql(): bool
    {
        return self::$driver === 'mysql';
    }

    /** SQL expression for current UTC/local timestamp (dialect-aware). */
    public static function nowSql(): string
    {
        return self::isMysql() ? 'NOW()' : "datetime('now')";
    }

    public static function migrationsDir(): string
    {
        $root = dirname(__DIR__);
        return self::isMysql() ? $root . '/migrations/mysql' : $root . '/migrations';
    }

    /** @param array<string,mixed> $cfg */
    public static function connectFromConfig(array $cfg): PDO
    {
        $driver = (string)($cfg['database']['driver'] ?? 'sqlite');
        if ($driver === 'mysql') {
            return self::connectMysql($cfg['database']);
        }
        self::$driver = 'sqlite';
        return self::connect((string)($cfg['database_path'] ?? ''));
    }

    /** @param array<string,mixed> $dbCfg */
    public static function connectMysql(array $dbCfg): PDO
    {
        self::$driver = 'mysql';
        $host = (string)($dbCfg['host'] ?? '127.0.0.1');
        $port = (int)($dbCfg['port'] ?? 3306);
        $name = (string)($dbCfg['database'] ?? '');
        $user = (string)($dbCfg['username'] ?? '');
        $pass = (string)($dbCfg['password'] ?? '');
        $timeout = PdoMysqlOptions::connectTimeoutSeconds();
        $dsn = "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4;connect_timeout={$timeout}";
        $opts = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => true,
            PDO::ATTR_STRINGIFY_FETCHES => true,
            PDO::ATTR_TIMEOUT => $timeout,
        ];
        $pdo = new PDO($dsn, $user, $pass, $opts);
        $pdo->exec("SET SESSION time_zone = '+00:00'");
        $pdo->exec('SET SESSION wait_timeout = 600');
        $pdo->exec('SET SESSION interactive_timeout = 600');
        return $pdo;
    }

    public static function connect(string $path): PDO
    {
        self::$driver = 'sqlite';
        $pdo = new PDO('sqlite:' . $path, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec('PRAGMA busy_timeout = 15000');
        try {
            $pdo->exec('PRAGMA journal_mode = WAL');
        } catch (\PDOException) {
        }
        return $pdo;
    }

    public static function migrateAll(PDO $db, string $migrationsDir): void
    {
        if (!is_dir($migrationsDir)) {
            return;
        }

        $db->exec(
            self::isMysql()
                ? 'CREATE TABLE IF NOT EXISTS schema_migrations (
                    name VARCHAR(255) PRIMARY KEY,
                    applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                )'
                : 'CREATE TABLE IF NOT EXISTS schema_migrations (
                    name TEXT PRIMARY KEY,
                    applied_at TEXT NOT NULL DEFAULT (datetime(\'now\'))
                )'
        );

        $files = glob($migrationsDir . '/*.sql') ?: [];
        sort($files);

        foreach ($files as $file) {
            $name = basename($file);
            $check = $db->prepare('SELECT 1 FROM schema_migrations WHERE name = ?');
            $check->execute([$name]);
            if ($check->fetchColumn()) {
                continue;
            }

            self::applySqlFile($db, $file);
            $insert = self::isMysql()
                ? 'INSERT IGNORE INTO schema_migrations (name) VALUES (?)'
                : 'INSERT OR IGNORE INTO schema_migrations (name) VALUES (?)';
            $db->prepare($insert)->execute([$name]);
        }
    }

    /** @deprecated Use migrateAll; kept for single-file callers */
    public static function migrate(PDO $db, string $migrationFile): void
    {
        if (!is_file($migrationFile)) {
            return;
        }
        self::applySqlFile($db, $migrationFile);
    }

    private static function applySqlFile(PDO $db, string $path): void
    {
        $sql = (string)file_get_contents($path);
        foreach (self::splitStatements($sql) as $stmt) {
            if ($stmt === '') {
                continue;
            }
            try {
                $db->exec($stmt);
            } catch (\PDOException $e) {
                if (!self::isIgnorableMigrationError($e)) {
                    throw $e;
                }
            }
        }
    }

    /** @return list<string> */
    private static function splitStatements(string $sql): array
    {
        $parts = preg_split('/;\s*\n/', $sql) ?: [];
        $out = [];
        foreach ($parts as $part) {
            $stmt = self::stripLeadingComments($part);
            if ($stmt === '') {
                continue;
            }
            $out[] = $stmt;
        }
        return $out;
    }

    private static function stripLeadingComments(string $sql): string
    {
        $lines = preg_split('/\R/', $sql) ?: [];
        $kept = [];
        foreach ($lines as $line) {
            $trimmed = trim($line);
            if ($trimmed === '' || str_starts_with($trimmed, '--')) {
                continue;
            }
            $kept[] = $line;
        }
        return trim(implode("\n", $kept));
    }

    private static function isIgnorableMigrationError(\PDOException $e): bool
    {
        $msg = $e->getMessage();
        return str_contains($msg, 'duplicate column')
            || str_contains($msg, 'already exists')
            || str_contains($msg, 'Duplicate column')
            || str_contains($msg, 'Duplicate key name');
    }
}
