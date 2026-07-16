<?php
declare(strict_types=1);

namespace Navicat\Services;

use Navicat\Drivers\DriverFactory;
use Navicat\Drivers\MongoDriver;

final class TransferService
{
    /**
     * Copy one or more tables from source to target (same table names on destination).
     *
     * @param list<string> $tables
     * @param callable(array<string,mixed>):void $emit
     */
    public static function runBatch(
        array $sourceConn,
        string $sourceDb,
        array $targetConn,
        string $targetDb,
        array $tables,
        int $batchSize,
        callable $emit,
        bool $truncateTarget = false,
        bool $createIfMissing = false
    ): void {
        if ($tables === []) {
            throw new \RuntimeException('At least one table is required', 400);
        }
        if ($batchSize < 1) {
            throw new \RuntimeException('batchSize must be at least 1', 400);
        }
        $batchSize = min(5000, $batchSize);

        $srcDrv = DriverFactory::getDriver($sourceConn);
        $dstDrv = DriverFactory::getDriver($targetConn);

        $srcIsMongo = $srcDrv instanceof MongoDriver;
        $dstIsMongo = $dstDrv instanceof MongoDriver;
        $crossEngine = ($sourceConn['engine'] ?? '') !== ($targetConn['engine'] ?? '');

        $emit(['type' => 'start', 'tableCount' => count($tables)]);

        $totalCopied = 0;
        foreach ($tables as $table) {
            $table = trim($table);
            if ($table === '') {
                continue;
            }

            $emit(['type' => 'table_start', 'table' => $table, 'message' => "Starting {$table}…"]);

            if ($createIfMissing && !self::tableExists($dstDrv, $targetDb, $table)) {
                if ($crossEngine || $srcIsMongo) {
                    $emit(['type' => 'warning', 'table' => $table, 'message' => "Skipping CREATE TABLE for {$table}: cross-engine DDL not supported — create the table manually on the target."]);
                } else {
                    $ddl = $srcDrv->getObjectDdl($sourceDb, 'table', $table);
                    if (trim($ddl) === '') {
                        throw new \RuntimeException("Could not obtain CREATE TABLE for {$table}", 500);
                    }
                    $dstDrv->executeDDL($ddl, $targetDb);
                    $emit(['type' => 'table_created', 'table' => $table, 'message' => "Created table {$table} on target"]);
                }
            }

            if ($truncateTarget) {
                $dstDrv->truncateTable($targetDb, $table);
                $emit(['type' => 'truncated', 'table' => $table]);
            }

            // Mongo→Mongo: use native dumpCollection/insertMany to preserve document
            // fidelity (nested objects, ObjectId, dates, etc.) instead of the
            // flattened grid path.
            if ($srcIsMongo && $dstIsMongo) {
                $copied = self::copyMongo(
                    $srcDrv, $sourceDb, $table,
                    $dstDrv, $targetDb, $table,
                    $batchSize,
                    static function (array $event) use ($emit, $table): void {
                        $emit([...$event, 'table' => $table]);
                    }
                );
            } else {
                $copied = self::copyTable(
                    $srcDrv,
                    $sourceDb,
                    $table,
                    $dstDrv,
                    $targetDb,
                    $table,
                    $batchSize,
                    static function (array $event) use ($emit, $table): void {
                        $emit([...$event, 'table' => $table]);
                    }
                );
            }

            $totalCopied += $copied;
            $emit([
                'type' => 'table_done',
                'table' => $table,
                'copiedRows' => $copied,
                'message' => "Finished {$table}: {$copied} row(s)",
            ]);
        }

        $emit(['type' => 'done', 'copiedRows' => $totalCopied, 'message' => "Transfer complete — {$totalCopied} row(s) total"]);
    }

    /**
     * Mongo→Mongo native copy using dumpCollection / insertMany in batches.
     * Preserves full document fidelity (nested objects, BSON types, etc.).
     *
     * @param callable(array<string,mixed>):void $emit
     */
    private static function copyMongo(
        MongoDriver $srcDrv,
        string $sourceDb,
        string $sourceTable,
        MongoDriver $dstDrv,
        string $targetDb,
        string $targetTable,
        int $batchSize,
        callable $emit
    ): int {
        $copied = 0;
        $batch = [];
        $srcDrv->dumpCollection($sourceDb, $sourceTable, function (string $json) use (
            $dstDrv, $targetDb, $targetTable, $batchSize, $emit, &$batch, &$copied
        ): void {
            $doc = json_decode($json, true);
            if (!is_array($doc)) {
                return;
            }
            $batch[] = $doc;
            if (count($batch) >= $batchSize) {
                $dstDrv->insertMany($targetDb, $targetTable, $batch);
                $copied += count($batch);
                $emit(['type' => 'progress', 'copiedRows' => $copied, 'totalRows' => null, 'offset' => $copied]);
                $batch = [];
            }
        });
        if ($batch !== []) {
            $dstDrv->insertMany($targetDb, $targetTable, $batch);
            $copied += count($batch);
        }
        return $copied;
    }

    /**
     * @param callable(array<string,mixed>):void $emit
     */
    private static function copyTable(
        object $srcDrv,
        string $sourceDb,
        string $sourceTable,
        object $dstDrv,
        string $targetDb,
        string $targetTable,
        int $batchSize,
        callable $emit
    ): int {
        $sample = $srcDrv->queryPaginated($sourceDb, $sourceTable, ['offset' => 0, 'limit' => 1]);
        /** @var list<string> $columns */
        $columns = $sample['columns'] ?? [];
        if ($columns === []) {
            // Empty collection/table — nothing to copy, not an error.
            $emit(['type' => 'progress', 'copiedRows' => 0, 'totalRows' => 0, 'offset' => 0]);
            return 0;
        }

        $totalApprox = (int)($sample['total'] ?? 0);
        $offset = 0;
        $copied = 0;

        while (true) {
            $batch = $srcDrv->queryPaginated($sourceDb, $sourceTable, [
                'offset' => $offset,
                'limit' => $batchSize,
            ]);
            /** @var list<array<string,mixed>> $rows */
            $rows = $batch['rows'] ?? [];
            if (!$rows) {
                break;
            }

            foreach ($rows as $row) {
                $payload = [];
                foreach ($columns as $c) {
                    if (!array_key_exists($c, $row)) {
                        throw new \RuntimeException("Missing column {$c} while copying row payload", 500);
                    }
                    $payload[$c] = $row[$c];
                }
                $dstDrv->insertRow($targetDb, $targetTable, $payload);
                $copied++;
            }

            $offset += count($rows);
            $emit([
                'type' => 'progress',
                'copiedRows' => $copied,
                'totalRows' => $totalApprox ?: null,
                'offset' => $offset,
            ]);

            if (count($rows) < $batchSize) {
                break;
            }
        }

        return $copied;
    }

    private static function tableExists(object $driver, string $database, string $table): bool
    {
        foreach ($driver->listTablesLight($database) as $t) {
            if ((string)($t['name'] ?? '') === $table) {
                return true;
            }
        }
        return false;
    }
}
