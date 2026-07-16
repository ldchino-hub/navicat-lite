<?php
declare(strict_types=1);

namespace Navicat\Services;

use Navicat\Drivers\DriverFactory;

final class SchemaCache
{
    private const TTL_SECONDS = 60;

    /** @return array<string,mixed> */
    public static function getSnapshot(array $connRow, ?string $database = null): array
    {
        $cacheKey = $connRow['id'] . ':' . ($database ?? '__all__');
        $path = self::cachePath($cacheKey);
        if (is_file($path)) {
            $raw = file_get_contents($path);
            if ($raw !== false) {
                /** @var array{expiresAt:int,snapshot:array<string,mixed>}|null $cached */
                $cached = json_decode($raw, true);
                if ($cached && ($cached['expiresAt'] ?? 0) > time()) {
                    return $cached['snapshot'];
                }
            }
        }

        $driver = DriverFactory::getDriver($connRow);
        $databases = $database ? [$database] : $driver->listDatabases();
        $tables = [];
        $routines = [];

        foreach ($databases as $db) {
            try {
                $tablesLight = $driver->listTablesLight($db);
                $viewsLight = $driver->listViews($db);
                $tables[$db] = [
                    ...array_map(static fn(array $t): array => [...$t, 'columns' => [], 'indexes' => []], $tablesLight),
                    ...array_map(static fn(array $v): array => [...$v, 'columns' => [], 'indexes' => []], $viewsLight),
                ];
                $routines[$db] = [];
            } catch (\Throwable) {
                $tables[$db] = [];
                $routines[$db] = [];
            }
        }

        $snapshot = [
            'databases' => $databases,
            'tables' => $tables,
            'routines' => $routines,
            'fetchedAt' => (int)(microtime(true) * 1000),
        ];

        self::writeCache($cacheKey, $snapshot);
        return $snapshot;
    }

    public static function invalidate(string $connectionId): void
    {
        $dir = self::cacheDir();
        if (!is_dir($dir)) {
            return;
        }
        foreach (glob($dir . '/' . preg_quote($connectionId, '/') . '_*.json') ?: [] as $file) {
            @unlink($file);
        }
    }

    /** @param array<string,mixed> $snapshot */
    private static function writeCache(string $cacheKey, array $snapshot): void
    {
        $dir = self::cacheDir();
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        $safe = str_replace(':', '_', $cacheKey);
        file_put_contents($dir . '/' . $safe . '.json', json_encode([
            'expiresAt' => time() + self::TTL_SECONDS,
            'snapshot' => $snapshot,
        ], JSON_UNESCAPED_UNICODE));
    }

    private static function cacheDir(): string
    {
        return dirname(__DIR__, 2) . '/storage/schema_cache';
    }

    private static function cachePath(string $cacheKey): string
    {
        $safe = str_replace(':', '_', $cacheKey);
        return self::cacheDir() . '/' . $safe . '.json';
    }
}
