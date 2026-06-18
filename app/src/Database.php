<?php
declare(strict_types=1);

namespace Navicat;

use PDO;

final class Database
{
    public static function connect(string $path): PDO
    {
        $pdo = new PDO('sqlite:' . $path, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $pdo->exec('PRAGMA foreign_keys = ON');
        return $pdo;
    }

    public static function migrateAll(PDO $db, string $migrationsDir): void
    {
        if (!is_dir($migrationsDir)) {
            return;
        }

        $db->exec(
            'CREATE TABLE IF NOT EXISTS schema_migrations (
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
            $db->prepare('INSERT INTO schema_migrations (name) VALUES (?)')->execute([$name]);
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
            || str_contains($msg, 'duplicate column name');
    }
}
