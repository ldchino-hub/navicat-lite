<?php
declare(strict_types=1);

namespace Navicat\Services;

use Navicat\Connections\ConnectionRepository;
use Navicat\Drivers\DriverFactory;
use Navicat\Support\PdoMysqlOptions;
use Navicat\Drivers\MongoDriver;
use Navicat\Drivers\MySqlDriver;
use Navicat\Drivers\PostgresDriver;
use PDO;

/** Native backup/restore via PDO — no mysqldump, pg_dump, mysql, or psql. */
final class BackupService
{
    private const ROW_BATCH = 250;
    private const INSERT_BATCH = 100;

    /** @param array<string,mixed> $conn @param array<string,mixed> $options @param callable(array<string,mixed>):void $emit */
    public function backup(array $conn, string $database, string $filePath, array $options, callable $emit): int
    {
        $engine = (string)($conn['engine'] ?? 'mysql');
        if ($engine === 'postgres' && ($options['format'] ?? 'plain') === 'custom') {
            throw new \RuntimeException('Custom (.dump) format is not supported. Use plain SQL.');
        }

        $gzip = ($options['gzip'] ?? true) !== false;
        $writer = new BackupWriter($filePath, $gzip);

        try {
            if ($engine === 'mongodb') {
                $this->exportMongo($conn, $database, $options, $writer, $emit);
            } elseif ($engine === 'postgres') {
                $this->exportPostgres($conn, $database, $options, $writer, $emit);
            } else {
                $this->exportMysql($conn, $database, $options, $writer, $emit);
            }
        } finally {
            $writer->close();
        }

        return $writer->bytes();
    }

    /** @param array<string,mixed> $conn @param callable(array<string,mixed>):void $emit @param array{override?:bool,sourceDatabase?:string} $options */
    public function restore(array $conn, string $database, string $filePath, callable $emit, array $options = []): void
    {
        if (str_ends_with(strtolower($filePath), '.dump')) {
            throw new \RuntimeException('Custom (.dump) backups require pg_restore. Re-create the backup as plain SQL.');
        }

        $creds = ConnectionRepository::credentials($conn);
        $engine = (string)($conn['engine'] ?? 'mysql');

        if ($engine === 'mongodb') {
            $this->restoreMongo($conn, $database, $filePath, $emit);
            return;
        }

        $pdo = $engine === 'postgres'
            ? $this->postgresPdo($creds, $database)
            : $this->mysqlPdo($creds, $database, true);

        $emit(['type' => 'log', 'message' => 'Reading backup file…']);
        SqlScriptRunner::runFile($pdo, $filePath, function (string $stmt) use ($emit): void {
            $preview = strlen($stmt) > 120 ? substr($stmt, 0, 117) . '…' : $stmt;
            $emit(['type' => 'log', 'message' => $preview]);
        }, $options, $database, $engine);
        $emit(['type' => 'done']);
    }

    /** @param array<string,mixed> $conn @param array<string,mixed> $options @param callable(array<string,mixed>):void $emit */
    private function exportMysql(
        array $conn,
        string $database,
        array $options,
        BackupWriter $writer,
        callable $emit,
    ): void {
        $driver = DriverFactory::getDriver($conn);
        if (!$driver instanceof MySqlDriver) {
            throw new \RuntimeException('Expected MySQL connection');
        }

        $creds = ConnectionRepository::credentials($conn);
        $pdo = $this->mysqlPdo($creds, $database);
        $filter = $this->normalizeObjectFilter($options);
        $isPartial = $filter !== null;

        $writer->write("-- DB Tool Box native backup\n");
        $writer->write("-- Database: {$database}\n");
        if ($isPartial) {
            $writer->write("-- Scope: partial\n");
        }
        $writer->write("-- Generated: " . gmdate('c') . "\n\n");
        $writer->write("SET NAMES utf8mb4;\n");
        $writer->write("SET FOREIGN_KEY_CHECKS=0;\n\n");

        $inTx = false;
        if (($options['singleTransaction'] ?? true) !== false) {
            $pdo->exec('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            $pdo->beginTransaction();
            $inTx = true;
        }

        try {
            $tables = $driver->listTablesLight($database);
            foreach ($tables as $t) {
                $name = (string)$t['name'];
                if ($isPartial && !in_array($name, $filter['tables'], true)) {
                    continue;
                }
                $emit(['type' => 'log', 'message' => "Table: {$name}"]);
                $ddl = $driver->getObjectDdl($database, 'table', $name);
                $writer->write("DROP TABLE IF EXISTS " . $this->mysqlIdent($database) . '.' . $this->mysqlIdent($name) . ";\n");
                $writer->write($ddl . ";\n\n");
                $this->exportMysqlTableData($pdo, $database, $name, $writer, $emit);
            }

            foreach ($driver->listViews($database) as $v) {
                $name = (string)$v['name'];
                if ($isPartial && !in_array($name, $filter['views'], true)) {
                    continue;
                }
                $emit(['type' => 'log', 'message' => "View: {$name}"]);
                $ddl = $driver->getObjectDdl($database, 'view', $name);
                $writer->write("DROP VIEW IF EXISTS " . $this->mysqlIdent($database) . '.' . $this->mysqlIdent($name) . ";\n");
                $writer->write($ddl . ";\n\n");
            }

            if ((!$isPartial && !empty($options['routines'])) || ($isPartial && $filter['routines'] !== [])) {
                foreach ($driver->listRoutines($database) as $r) {
                    $name = (string)$r['name'];
                    if ($isPartial && !in_array($name, $filter['routines'], true)) {
                        continue;
                    }
                    $emit(['type' => 'log', 'message' => "Routine: {$name}"]);
                    $src = $driver->getRoutineSource($database, $name);
                    $writer->write("DROP {$r['type']} IF EXISTS " . $this->mysqlIdent($database) . '.' . $this->mysqlIdent($name) . ";\n");
                    $writer->write($this->ensureStatementEnd($src) . "\n\n");
                }
            }

            if ((!$isPartial && ($options['triggers'] ?? true) !== false) || ($isPartial && $filter['triggers'] !== [])) {
                foreach ($driver->listTriggers($database) as $tr) {
                    $name = (string)$tr['name'];
                    if ($isPartial && !in_array($name, $filter['triggers'], true)) {
                        continue;
                    }
                    $emit(['type' => 'log', 'message' => "Trigger: {$name}"]);
                    $ddl = $driver->getObjectDdl($database, 'trigger', $name);
                    $writer->write("DROP TRIGGER IF EXISTS " . $this->mysqlIdent($name) . ";\n");
                    $writer->write($this->ensureStatementEnd($ddl) . "\n\n");
                }
            }
        } finally {
            if ($inTx && $pdo->inTransaction()) {
                $pdo->commit();
            }
        }

        $writer->write("SET FOREIGN_KEY_CHECKS=1;\n");
        $this->emitProgress($writer, $emit);
    }

    private function exportMysqlTableData(
        PDO $pdo,
        string $database,
        string $table,
        BackupWriter $writer,
        callable $emit,
    ): void {
        $tableRef = $this->mysqlIdent($database) . '.' . $this->mysqlIdent($table);
        $offset = 0;
        $columns = null;

        while (true) {
            $sql = "SELECT * FROM {$tableRef} LIMIT " . self::ROW_BATCH . " OFFSET {$offset}";
            $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
            if ($rows === []) {
                break;
            }
            if ($columns === null) {
                $columns = array_keys($rows[0]);
            }
            $colList = implode(', ', array_map(fn(string $c): string => $this->mysqlIdent($c), $columns));

            $chunk = [];
            foreach ($rows as $row) {
                $vals = [];
                foreach ($columns as $col) {
                    $vals[] = $this->sqlLiteral($pdo, $row[$col] ?? null, 'mysql');
                }
                $chunk[] = '(' . implode(', ', $vals) . ')';
                if (count($chunk) >= self::INSERT_BATCH) {
                    $writer->write("INSERT INTO {$tableRef} ({$colList}) VALUES\n" . implode(",\n", $chunk) . ";\n");
                    $chunk = [];
                    $this->emitProgress($writer, $emit);
                }
            }
            if ($chunk !== []) {
                $writer->write("INSERT INTO {$tableRef} ({$colList}) VALUES\n" . implode(",\n", $chunk) . ";\n");
                $this->emitProgress($writer, $emit);
            }
            $offset += self::ROW_BATCH;
        }

        $writer->write("\n");
    }

    /** @param array<string,mixed> $conn @param array<string,mixed> $options @param callable(array<string,mixed>):void $emit */
    private function exportPostgres(
        array $conn,
        string $database,
        array $options,
        BackupWriter $writer,
        callable $emit,
    ): void {
        $driver = DriverFactory::getDriver($conn);
        if (!$driver instanceof PostgresDriver) {
            throw new \RuntimeException('Expected PostgreSQL connection');
        }

        $creds = ConnectionRepository::credentials($conn);
        $pdo = $this->postgresPdo($creds, $database);
        $filter = $this->normalizeObjectFilter($options);
        $isPartial = $filter !== null;

        $writer->write("-- DB Tool Box native backup\n");
        $writer->write("-- Database: {$database}\n");
        if ($isPartial) {
            $writer->write("-- Scope: partial\n");
        }
        $writer->write("-- Generated: " . gmdate('c') . "\n\n");

        foreach ($driver->listTablesLight($database) as $t) {
            $name = (string)$t['name'];
            if ($isPartial && !in_array($name, $filter['tables'], true)) {
                continue;
            }
            $emit(['type' => 'log', 'message' => "Table: {$name}"]);
            $writer->write("DROP TABLE IF EXISTS public." . $this->pgIdent($name) . " CASCADE;\n");
            $writer->write($driver->getObjectDdl($database, 'table', $name));
            $this->exportPostgresTableData($pdo, $name, $writer, $emit);
        }

        foreach ($driver->listViews($database) as $v) {
            $name = (string)$v['name'];
            if ($isPartial && !in_array($name, $filter['views'], true)) {
                continue;
            }
            $emit(['type' => 'log', 'message' => "View: {$name}"]);
            $writer->write("DROP VIEW IF EXISTS public." . $this->pgIdent($name) . " CASCADE;\n");
            $writer->write($driver->getObjectDdl($database, 'view', $name));
        }

        if ((!$isPartial && !empty($options['routines'])) || ($isPartial && $filter['routines'] !== [])) {
            foreach ($driver->listRoutines($database) as $r) {
                $name = (string)$r['name'];
                if ($isPartial && !in_array($name, $filter['routines'], true)) {
                    continue;
                }
                $emit(['type' => 'log', 'message' => "Routine: {$name}"]);
                $writer->write("DROP {$r['type']} IF EXISTS public." . $this->pgIdent($name) . " CASCADE;\n");
                $writer->write($driver->getObjectDdl($database, 'routine', $name));
            }
        }

        if ((!$isPartial && ($options['triggers'] ?? true) !== false) || ($isPartial && $filter['triggers'] !== [])) {
            foreach ($driver->listTriggers($database) as $tr) {
                $name = (string)$tr['name'];
                if ($isPartial && !in_array($name, $filter['triggers'], true)) {
                    continue;
                }
                $emit(['type' => 'log', 'message' => "Trigger: {$name}"]);
                $writer->write("DROP TRIGGER IF EXISTS " . $this->pgIdent($name) . " ON public." . $this->pgIdent((string)$tr['table']) . " CASCADE;\n");
                $writer->write($driver->getObjectDdl($database, 'trigger', $name));
            }
        }

        $this->emitProgress($writer, $emit);
    }

    private function exportPostgresTableData(PDO $pdo, string $table, BackupWriter $writer, callable $emit): void
    {
        $tableRef = 'public.' . $this->pgIdent($table);
        $offset = 0;
        $columns = null;

        while (true) {
            $sql = "SELECT * FROM {$tableRef} LIMIT " . self::ROW_BATCH . ' OFFSET ' . $offset;
            $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
            if ($rows === []) {
                break;
            }
            if ($columns === null) {
                $columns = array_keys($rows[0]);
            }
            $colList = implode(', ', array_map(fn(string $c): string => $this->pgIdent($c), $columns));

            $chunk = [];
            foreach ($rows as $row) {
                $vals = [];
                foreach ($columns as $col) {
                    $vals[] = $this->sqlLiteral($pdo, $row[$col] ?? null, 'postgres');
                }
                $chunk[] = '(' . implode(', ', $vals) . ')';
                if (count($chunk) >= self::INSERT_BATCH) {
                    $writer->write("INSERT INTO {$tableRef} ({$colList}) VALUES\n" . implode(",\n", $chunk) . ";\n");
                    $chunk = [];
                    $this->emitProgress($writer, $emit);
                }
            }
            if ($chunk !== []) {
                $writer->write("INSERT INTO {$tableRef} ({$colList}) VALUES\n" . implode(",\n", $chunk) . ";\n");
                $this->emitProgress($writer, $emit);
            }
            $offset += self::ROW_BATCH;
        }

        $writer->write("\n");
    }

    /** @param array<string,mixed> $creds */
    private function mysqlPdo(array $creds, string $database, bool $multiStatements = false): PDO
    {
        $host = (string)$creds['host'];
        $port = (int)$creds['port'];
        $dsn = 'mysql:host=' . $host . ';port=' . $port . ';dbname=' . $database . ';charset=utf8mb4';
        $opts = PdoMysqlOptions::build($multiStatements, (string)($creds['sslMode'] ?? 'preferred'));
        return new PDO($dsn, (string)$creds['username'], (string)$creds['password'], $opts);
    }

    /** @param array<string,mixed> $creds */
    private function postgresPdo(array $creds, string $database): PDO
    {
        $host = (string)$creds['host'];
        $port = (int)$creds['port'];
        $dsn = "pgsql:host={$host};port={$port};dbname={$database}";
        $opts = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => true,
        ];
        return new PDO($dsn, (string)$creds['username'], (string)$creds['password'], $opts);
    }

    /** @param mixed $value */
    private function sqlLiteral(PDO $pdo, mixed $value, string $engine = 'mysql'): string
    {
        if ($value === null) {
            return 'NULL';
        }
        if (is_bool($value)) {
            if ($engine === 'postgres') {
                return $value ? 'TRUE' : 'FALSE';
            }
            return $value ? '1' : '0';
        }
        if (is_int($value) || is_float($value)) {
            return (string)$value;
        }
        return $pdo->quote((string)$value);
    }

    private function mysqlIdent(string $name): string
    {
        return '`' . str_replace('`', '``', $name) . '`';
    }

    private function pgIdent(string $name): string
    {
        return '"' . str_replace('"', '""', $name) . '"';
    }

    private function ensureStatementEnd(string $sql): string
    {
        $trim = rtrim(trim($sql), ';');
        return $trim . ';';
    }

    /**
     * @param array<string,mixed> $options
     * @return array{tables:list<string>,views:list<string>,routines:list<string>,triggers:list<string>}|null
     */
    private function normalizeObjectFilter(array $options): ?array
    {
        if (!empty($options['objects']) && is_array($options['objects'])) {
            $out = ['tables' => [], 'views' => [], 'routines' => [], 'triggers' => []];
            foreach ($options['objects'] as $obj) {
                if (!is_array($obj)) {
                    continue;
                }
                $kind = strtolower((string)($obj['kind'] ?? $obj['type'] ?? ''));
                $name = (string)($obj['name'] ?? '');
                if ($name === '') {
                    continue;
                }
                match ($kind) {
                    'table' => $out['tables'][] = $name,
                    'view' => $out['views'][] = $name,
                    'routine', 'function', 'procedure' => $out['routines'][] = $name,
                    'trigger' => $out['triggers'][] = $name,
                    default => null,
                };
            }
            if ($out['tables'] !== [] || $out['views'] !== [] || $out['routines'] !== [] || $out['triggers'] !== []) {
                return $out;
            }
        }

        $tables = $this->normalizeTableFilter($options['tables'] ?? null);
        if ($tables !== null) {
            return ['tables' => $tables, 'views' => $tables, 'routines' => [], 'triggers' => []];
        }

        return null;
    }

    /** @param list<string>|null $tables @return list<string>|null */
    private function normalizeTableFilter(mixed $tables): ?array
    {
        if (!is_array($tables) || $tables === []) {
            return null;
        }
        return array_values(array_map('strval', $tables));
    }

    /** @param callable(array<string,mixed>):void $emit */
    private function emitProgress(BackupWriter $writer, callable $emit): void
    {
        $emit(['type' => 'progress', 'bytes' => $writer->bytes()]);
    }

    /**
     * Native MongoDB/DocumentDB dump: NDJSON, one document per line, with a
     * `// @collection: <name>` marker before each collection's documents.
     *
     * @param array<string,mixed> $conn @param array<string,mixed> $options @param callable(array<string,mixed>):void $emit
     */
    private function exportMongo(
        array $conn,
        string $database,
        array $options,
        BackupWriter $writer,
        callable $emit,
    ): void {
        $driver = DriverFactory::getDriver($conn);
        if (!$driver instanceof MongoDriver) {
            throw new \RuntimeException('Expected MongoDB connection');
        }
        $filter = $this->normalizeTableFilter($options['tables'] ?? null);

        $writer->write("// DB Tool Box native MongoDB backup (NDJSON)\n");
        $writer->write("// Database: {$database}\n");
        $writer->write("// Generated: " . gmdate('c') . "\n");

        foreach ($driver->listCollectionNames($database) as $coll) {
            if ($filter !== null && !in_array($coll, $filter, true)) {
                continue;
            }
            $emit(['type' => 'log', 'message' => "Collection: {$coll}"]);
            $writer->write("// @collection: {$coll}\n");
            $n = $driver->dumpCollection($database, $coll, function (string $json) use ($writer): void {
                $writer->write($json . "\n");
            });
            $emit(['type' => 'log', 'message' => "  {$n} document(s)"]);
            $this->emitProgress($writer, $emit);
        }
    }

    /** @param array<string,mixed> $conn @param callable(array<string,mixed>):void $emit */
    private function restoreMongo(array $conn, string $database, string $filePath, callable $emit): void
    {
        $driver = DriverFactory::getDriver($conn);
        if (!$driver instanceof MongoDriver) {
            throw new \RuntimeException('Expected MongoDB connection');
        }
        $gz = str_ends_with(strtolower($filePath), '.gz');
        $handle = $gz ? gzopen($filePath, 'rb') : fopen($filePath, 'rb');
        if (!$handle) {
            throw new \RuntimeException('Could not open backup file');
        }

        $emit(['type' => 'log', 'message' => 'Reading MongoDB backup…']);
        $currentColl = null;
        $batch = [];
        $flush = function () use (&$batch, &$currentColl, $driver, $database, $emit): void {
            if ($currentColl !== null && $batch !== []) {
                $driver->insertMany($database, $currentColl, $batch);
                $emit(['type' => 'log', 'message' => '  inserted ' . count($batch) . " into {$currentColl}"]);
            }
            $batch = [];
        };

        try {
            while (($line = $gz ? gzgets($handle) : fgets($handle)) !== false) {
                $line = rtrim($line, "\r\n");
                if ($line === '') {
                    continue;
                }
                if (str_starts_with($line, '// @collection:')) {
                    $flush();
                    $currentColl = trim(substr($line, strlen('// @collection:')));
                    continue;
                }
                if (str_starts_with($line, '//')) {
                    continue;
                }
                $doc = json_decode($line, true);
                if (is_array($doc)) {
                    $batch[] = $doc;
                    if (count($batch) >= 500) {
                        $flush();
                    }
                }
            }
            $flush();
        } finally {
            $gz ? gzclose($handle) : fclose($handle);
        }
        $emit(['type' => 'done']);
    }
}

/** @internal */
final class BackupWriter
{
    /** @var resource */
    private $handle;
    private bool $gzip;
    private int $bytes = 0;

    public function __construct(string $path, bool $gzip)
    {
        $this->gzip = $gzip;
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        $this->handle = $gzip ? gzopen($path, 'wb9') : fopen($path, 'wb');
        if ($this->handle === false) {
            throw new \RuntimeException('Cannot write backup file: ' . $path);
        }
    }

    public function write(string $chunk): void
    {
        if (!isset($this->handle) || !is_resource($this->handle)) {
            return;
        }
        if ($this->gzip) {
            gzwrite($this->handle, $chunk);
        } else {
            fwrite($this->handle, $chunk);
        }
        $this->bytes += strlen($chunk);
    }

    public function bytes(): int
    {
        return $this->bytes;
    }

    public function close(): void
    {
        if (!isset($this->handle) || !is_resource($this->handle)) {
            return;
        }
        if ($this->gzip) {
            gzclose($this->handle);
        } else {
            fclose($this->handle);
        }
        unset($this->handle);
    }
}
