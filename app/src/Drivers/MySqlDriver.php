<?php
declare(strict_types=1);

namespace Navicat\Drivers;

use Navicat\Support\PdoMysqlOptions;
use PDO;
use PDOStatement;

final class MySqlDriver
{
    /** @var array<string,mixed> */
    private array $creds;

    /** @param array<string,mixed> $creds */
    public function __construct(array $creds)
    {
        $this->creds = $creds;
    }

    private function createPdo(?string $database = null, bool $multiStatements = false): PDO
    {
        $host = (string)$this->creds['host'];
        $port = (int)$this->creds['port'];
        $db = $database ?? ($this->creds['defaultDb'] ?? null);
        $dsn = 'mysql:host=' . $host . ';port=' . $port . ';charset=utf8mb4';
        if ($db) {
            $dsn .= ';dbname=' . $db;
        }

        $opts = PdoMysqlOptions::build($multiStatements, (string)($this->creds['sslMode'] ?? 'preferred'));

        return new PDO($dsn, (string)$this->creds['username'], (string)$this->creds['password'], $opts);
    }

    /** @template T @param callable(PDO): T $fn @return T */
    private function withConn(?string $database, callable $fn): mixed
    {
        $pdo = $this->createPdo($database);
        try {
            return $fn($pdo);
        } finally {
            $pdo = null;
        }
    }

    public function testConnection(): bool
    {
        return $this->withConn(null, static function (PDO $pdo): bool {
            $pdo->query('SELECT 1');
            return true;
        });
    }

    /** @return list<string> */
    public function listDatabases(): array
    {
        $system = ['information_schema', 'performance_schema', 'mysql', 'sys'];
        return $this->withConn(null, static function (PDO $pdo) use ($system): array {
            $rows = $pdo->query('SHOW DATABASES')->fetchAll();
            $out = [];
            foreach ($rows as $r) {
                $name = (string)$r['Database'];
                if (!in_array($name, $system, true)) {
                    $out[] = $name;
                }
            }
            return $out;
        });
    }

    /** @return list<array<string,mixed>> */
    public function listTablesLight(string $database): array
    {
        return $this->withConn(null, static function (PDO $pdo) use ($database): array {
            $st = $pdo->prepare(
                'SELECT TABLE_NAME, TABLE_TYPE, ENGINE, TABLE_ROWS,
                        DATA_LENGTH + INDEX_LENGTH AS SIZE_BYTES,
                        TABLE_COLLATION, TABLE_COMMENT, UPDATE_TIME
                 FROM information_schema.TABLES
                 WHERE TABLE_SCHEMA = ? AND TABLE_TYPE IN (\'BASE TABLE\',\'SYSTEM VERSIONED\')
                 ORDER BY TABLE_NAME'
            );
            $st->execute([$database]);
            $out = [];
            foreach ($st->fetchAll() as $r) {
                $out[] = [
                    'name' => (string)$r['TABLE_NAME'],
                    'type' => 'table',
                    'engine' => $r['ENGINE'] ?? null,
                    'rowsEstimate' => isset($r['TABLE_ROWS']) ? (int)$r['TABLE_ROWS'] : null,
                    'sizeBytes' => isset($r['SIZE_BYTES']) ? (int)$r['SIZE_BYTES'] : null,
                    'collation' => $r['TABLE_COLLATION'] ?? null,
                    'comment' => $r['TABLE_COMMENT'] ?? null,
                    'updatedAt' => $r['UPDATE_TIME'] ?? null,
                ];
            }
            return $out;
        });
    }

    /** @return list<array<string,mixed>> */
    public function listViews(string $database): array
    {
        return $this->withConn(null, static function (PDO $pdo) use ($database): array {
            $st = $pdo->prepare(
                'SELECT TABLE_NAME FROM information_schema.VIEWS WHERE TABLE_SCHEMA = ? ORDER BY TABLE_NAME'
            );
            $st->execute([$database]);
            $out = [];
            foreach ($st->fetchAll() as $r) {
                $out[] = ['name' => (string)$r['TABLE_NAME'], 'type' => 'view'];
            }
            return $out;
        });
    }

    /** @return list<array<string,mixed>> */
    public function listRoutines(string $database): array
    {
        return $this->withConn(null, static function (PDO $pdo) use ($database): array {
            $st = $pdo->prepare(
                'SELECT ROUTINE_NAME, ROUTINE_TYPE FROM information_schema.ROUTINES
                 WHERE ROUTINE_SCHEMA = ? ORDER BY ROUTINE_NAME'
            );
            $st->execute([$database]);
            $out = [];
            foreach ($st->fetchAll() as $r) {
                $out[] = [
                    'name' => (string)$r['ROUTINE_NAME'],
                    'type' => ($r['ROUTINE_TYPE'] ?? '') === 'PROCEDURE' ? 'procedure' : 'function',
                ];
            }
            return $out;
        });
    }

    /** @return list<array<string,mixed>> */
    public function listTriggers(string $database): array
    {
        return $this->withConn(null, static function (PDO $pdo) use ($database): array {
            $st = $pdo->prepare(
                'SELECT TRIGGER_NAME, EVENT_OBJECT_TABLE, ACTION_TIMING, EVENT_MANIPULATION, ACTION_STATEMENT
                 FROM information_schema.TRIGGERS
                 WHERE TRIGGER_SCHEMA = ? ORDER BY EVENT_OBJECT_TABLE, TRIGGER_NAME'
            );
            $st->execute([$database]);
            $out = [];
            foreach ($st->fetchAll() as $r) {
                $out[] = [
                    'name' => (string)$r['TRIGGER_NAME'],
                    'table' => (string)$r['EVENT_OBJECT_TABLE'],
                    'timing' => (string)$r['ACTION_TIMING'],
                    'event' => (string)$r['EVENT_MANIPULATION'],
                    'definition' => (string)($r['ACTION_STATEMENT'] ?? ''),
                ];
            }
            return $out;
        });
    }

    public function getRoutineSource(string $database, string $name): string
    {
        return $this->withConn(null, function (PDO $pdo) use ($database, $name): string {
            $st = $pdo->prepare(
                'SELECT ROUTINE_TYPE, ROUTINE_DEFINITION FROM information_schema.ROUTINES
                 WHERE ROUTINE_SCHEMA = ? AND ROUTINE_NAME = ?'
            );
            $st->execute([$database, $name]);
            $row = $st->fetch();
            if (!$row) {
                throw new \RuntimeException("Routine {$name} not found");
            }
            $type = (string)$row['ROUTINE_TYPE'];
            $dbEsc = $this->quoteIdent($database);
            $nameEsc = $this->quoteIdent($name);
            $show = $pdo->query("SHOW CREATE {$type} {$dbEsc}.{$nameEsc}")->fetch();
            if (!$show) {
                return (string)($row['ROUTINE_DEFINITION'] ?? '');
            }
            foreach ($show as $k => $v) {
                if (str_starts_with((string)$k, 'Create')) {
                    return (string)$v;
                }
            }
            return (string)($row['ROUTINE_DEFINITION'] ?? '');
        });
    }

    public function getViewDefinition(string $database, string $view): string
    {
        return $this->withConn(null, function (PDO $pdo) use ($database, $view): string {
            $dbEsc = $this->quoteIdent($database);
            $viewEsc = $this->quoteIdent($view);
            $row = $pdo->query("SHOW CREATE VIEW {$dbEsc}.{$viewEsc}")->fetch();
            return (string)($row['Create View'] ?? '');
        });
    }

    /** Types: table, view, routine, trigger */
    public function getObjectDdl(string $database, string $type, string $name): string
    {
        $t = strtolower($type);
        return match ($t) {
            'table' => $this->withConn(null, function (PDO $pdo) use ($database, $name): string {
                $dbEsc = $this->quoteIdent($database);
                $tblEsc = $this->quoteIdent($name);
                $row = $pdo->query("SHOW CREATE TABLE {$dbEsc}.{$tblEsc}")->fetch();
                if (!$row) {
                    throw new \RuntimeException("Table {$name} not found", 404);
                }
                foreach ($row as $k => $v) {
                    if (is_string($k) && str_starts_with((string)$k, 'Create')) {
                        return (string)$v;
                    }
                }
                return '';
            }),
            'view' => $this->withConn(null, function (PDO $pdo) use ($database, $name): string {
                $dbEsc = $this->quoteIdent($database);
                $viewEsc = $this->quoteIdent($name);
                $row = $pdo->query("SHOW CREATE VIEW {$dbEsc}.{$viewEsc}")->fetch();
                if (!$row) {
                    throw new \RuntimeException("View {$name} not found", 404);
                }
                foreach ($row as $k => $v) {
                    if (is_string($k) && str_starts_with((string)$k, 'Create')) {
                        return (string)$v;
                    }
                }
                return '';
            }),
            'routine' => $this->getRoutineSource($database, $name),
            'trigger' => $this->withConn($database, function (PDO $pdo) use ($name): string {
                $tgEsc = $this->quoteIdent($name);
                $row = $pdo->query("SHOW CREATE TRIGGER {$tgEsc}")->fetch();
                if (!$row) {
                    throw new \RuntimeException("Trigger {$name} not found", 404);
                }
                foreach ($row as $k => $v) {
                    $ks = strtolower((string)$k);
                    if (
                        str_contains($ks, 'create')
                        || str_contains((string)$k, 'Original')
                    ) {
                        return (string)$v;
                    }
                }
                return '';
            }),
            default => throw new \RuntimeException('Unsupported object type', 400),
        };
    }

    /** Clone an object definition (and optionally table data) into another
     *  database on the same MySQL server. Driver-aware name rewrite on the DDL. */
    public function cloneObject(string $sourceDb, string $type, string $name, string $targetDb, string $newName, bool $copyData = false): array
    {
        $t = strtolower($type);
        $ddl = $this->getObjectDdl($sourceDb, $t, $name);
        $rewritten = $this->rewriteObjectName($ddl, $name, $newName);
        $this->executeDDL($rewritten, $targetDb);
        if ($t === 'table' && $copyData) {
            $src = $this->quoteIdent($sourceDb) . '.' . $this->quoteIdent($name);
            $dst = $this->quoteIdent($targetDb) . '.' . $this->quoteIdent($newName);
            $this->withConn(null, static function (PDO $pdo) use ($src, $dst): void {
                $pdo->exec("INSERT INTO {$dst} SELECT * FROM {$src}");
            });
        }
        return ['ok' => true, 'type' => $t, 'newName' => $newName, 'targetDb' => $targetDb];
    }

    /** Replace the first occurrence of the (quoted or bare) object name in the DDL. */
    private function rewriteObjectName(string $ddl, string $old, string $new): string
    {
        $quoted = '/`' . preg_quote($old, '/') . '`/';
        if (preg_match($quoted, $ddl)) {
            return preg_replace($quoted, '`' . str_replace('`', '``', $new) . '`', $ddl, 1);
        }
        return preg_replace('/\b' . preg_quote($old, '/') . '\b/', $new, $ddl, 1);
    }

    /** @return array<string,mixed> */
    public function getTableInfo(string $database, string $table): array
    {
        return $this->withConn($database, function (PDO $pdo) use ($database, $table): array {
            $st = $pdo->prepare(
                'SELECT c.COLUMN_NAME AS name, c.DATA_TYPE AS type, c.COLUMN_TYPE AS full_type,
                        c.IS_NULLABLE AS is_nullable, c.COLUMN_DEFAULT AS default_value,
                        c.COLUMN_KEY AS col_key, c.EXTRA AS extra, c.COLUMN_COMMENT AS comment,
                        c.CHARACTER_MAXIMUM_LENGTH AS max_length, c.ORDINAL_POSITION AS pos
                 FROM information_schema.COLUMNS c
                 WHERE c.TABLE_SCHEMA = ? AND c.TABLE_NAME = ?
                 ORDER BY c.ORDINAL_POSITION'
            );
            $st->execute([$database, $table]);
            $colRows = $st->fetchAll();

            $fkSt = $pdo->prepare(
                'SELECT k.COLUMN_NAME AS col, k.REFERENCED_TABLE_NAME AS ref_table, k.REFERENCED_COLUMN_NAME AS ref_col
                 FROM information_schema.KEY_COLUMN_USAGE k
                 WHERE k.TABLE_SCHEMA = ? AND k.TABLE_NAME = ? AND k.REFERENCED_TABLE_NAME IS NOT NULL'
            );
            $fkSt->execute([$database, $table]);
            $fkMap = [];
            foreach ($fkSt->fetchAll() as $r) {
                $fkMap[(string)$r['col']] = [
                    'table' => (string)$r['ref_table'],
                    'column' => (string)$r['ref_col'],
                ];
            }

            $columns = [];
            foreach ($colRows as $c) {
                $colName = (string)$c['name'];
                $fk = $fkMap[$colName] ?? null;
                $extra = (string)($c['extra'] ?? '');
                $columns[] = [
                    'name' => $colName,
                    'type' => (string)$c['full_type'],
                    'nullable' => ($c['is_nullable'] ?? '') === 'YES',
                    'defaultValue' => $c['default_value'],
                    'isPrimaryKey' => ($c['col_key'] ?? '') === 'PRI',
                    'isForeignKey' => $fk !== null,
                    'referencedTable' => $fk['table'] ?? null,
                    'referencedColumn' => $fk['column'] ?? null,
                    'comment' => (string)($c['comment'] ?? ''),
                    'autoIncrement' => str_contains($extra, 'auto_increment'),
                    'length' => isset($c['max_length']) ? (int)$c['max_length'] : null,
                    'ordinalPosition' => isset($c['pos']) ? (int)$c['pos'] : null,
                ];
            }

            $indexMap = [];
            try {
                $tableEsc = $this->quoteIdent($table);
                $idxRows = $pdo->query("SHOW INDEX FROM {$tableEsc}")->fetchAll();
                foreach ($idxRows as $idx) {
                    $idxName = (string)$idx['Key_name'];
                    if (!isset($indexMap[$idxName])) {
                        $indexMap[$idxName] = [
                            'name' => $idxName,
                            'columns' => [],
                            'unique' => (int)($idx['Non_unique'] ?? 1) === 0,
                            'primary' => $idxName === 'PRIMARY',
                        ];
                    }
                    $indexMap[$idxName]['columns'][] = (string)$idx['Column_name'];
                }
            } catch (\Throwable) {
                // view or permissions
            }

            $fkDetailSt = $pdo->prepare(
                'SELECT CONSTRAINT_NAME, COLUMN_NAME, REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME
                 FROM information_schema.KEY_COLUMN_USAGE
                 WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND REFERENCED_TABLE_NAME IS NOT NULL
                 ORDER BY CONSTRAINT_NAME, ORDINAL_POSITION'
            );
            $fkDetailSt->execute([$database, $table]);
            $fkGroups = [];
            foreach ($fkDetailSt->fetchAll() as $r) {
                $name = (string)$r['CONSTRAINT_NAME'];
                if (!isset($fkGroups[$name])) {
                    $fkGroups[$name] = [
                        'name' => $name,
                        'columns' => [],
                        'referencedTable' => (string)$r['REFERENCED_TABLE_NAME'],
                        'referencedColumns' => [],
                    ];
                }
                $fkGroups[$name]['columns'][] = (string)$r['COLUMN_NAME'];
                $fkGroups[$name]['referencedColumns'][] = (string)$r['REFERENCED_COLUMN_NAME'];
            }

            $checks = [];
            try {
                $checkSt = $pdo->prepare(
                    'SELECT CONSTRAINT_NAME, CHECK_CLAUSE
                     FROM information_schema.CHECK_CONSTRAINTS
                     WHERE CONSTRAINT_SCHEMA = ? AND TABLE_NAME = ?'
                );
                $checkSt->execute([$database, $table]);
                foreach ($checkSt->fetchAll() as $r) {
                    $checks[] = [
                        'name' => (string)$r['CONSTRAINT_NAME'],
                        'expression' => (string)$r['CHECK_CLAUSE'],
                    ];
                }
            } catch (\Throwable) {
            }

            $typeSt = $pdo->prepare(
                'SELECT TABLE_TYPE FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?'
            );
            $typeSt->execute([$database, $table]);
            $typeRow = $typeSt->fetch();

            return [
                'name' => $table,
                'type' => ($typeRow['TABLE_TYPE'] ?? '') === 'VIEW' ? 'view' : 'table',
                'columns' => $columns,
                'indexes' => array_values($indexMap),
                'foreignKeys' => array_values($fkGroups),
                'checks' => $checks,
            ];
        });
    }

    /** @return array<string,mixed> */
    public function execute(string $sql, ?string $database = null): array
    {
        $start = microtime(true);
        return $this->withConn($database, static function (PDO $pdo) use ($sql, $start): array {
            $stmt = $pdo->query($sql);
            $elapsed = (int)round((microtime(true) - $start) * 1000);

            if ($stmt instanceof PDOStatement && $stmt->columnCount() > 0) {
                $rows = $stmt->fetchAll();
                $columns = [];
                for ($i = 0; $i < $stmt->columnCount(); $i++) {
                    $meta = $stmt->getColumnMeta($i);
                    $columns[] = (string)($meta['name'] ?? "col{$i}");
                }
                if (!$columns && $rows) {
                    $columns = array_keys($rows[0]);
                }
                return [
                    'columns' => $columns,
                    'rows' => self::normalizeRows($rows),
                    'rowCount' => count($rows),
                    'executionTimeMs' => $elapsed,
                ];
            }

            return [
                'columns' => [],
                'rows' => [],
                'rowCount' => 0,
                'affectedRows' => $stmt ? $stmt->rowCount() : 0,
                'executionTimeMs' => $elapsed,
                'message' => ($stmt ? $stmt->rowCount() : 0) . ' row(s) affected',
            ];
        });
    }

    /** @return array<string,mixed> */
    public function executeMany(string $sql, ?string $database = null): array
    {
        $start = microtime(true);
        $pdo = $this->createPdo($database, true);
        try {
            $stmt = $pdo->query($sql);
            $statements = [];
            if (!$stmt) {
                return ['statements' => [], 'totalTimeMs' => 0];
            }
            do {
                if ($stmt->columnCount() > 0) {
                    $rows = $stmt->fetchAll();
                    $columns = [];
                    for ($i = 0; $i < $stmt->columnCount(); $i++) {
                        $meta = $stmt->getColumnMeta($i);
                        $columns[] = (string)($meta['name'] ?? "col{$i}");
                    }
                    if (!$columns && $rows) {
                        $columns = array_keys($rows[0]);
                    }
                    $statements[] = [
                        'columns' => $columns,
                        'rows' => self::normalizeRows($rows),
                        'rowCount' => count($rows),
                        'executionTimeMs' => 0,
                    ];
                } else {
                    $statements[] = [
                        'columns' => [],
                        'rows' => [],
                        'rowCount' => 0,
                        'affectedRows' => $stmt->rowCount(),
                        'executionTimeMs' => 0,
                        'message' => $stmt->rowCount() . ' row(s) affected',
                    ];
                }
            } while ($stmt->nextRowset());

            return [
                'statements' => $statements,
                'totalTimeMs' => (int)round((microtime(true) - $start) * 1000),
            ];
        } finally {
            $pdo = null;
        }
    }

    /**
     * @param array{
     *     offset:int,
     *     limit:int,
     *     orderBy?:string|null,
     *     orderDir?:string|null,
     *     filter?:string|null,
     *     structuredFilters?:list<array{column:string,op:string,value?:mixed}>|mixed
     * } $options
     * @return array<string,mixed>
     */
    public function queryPaginated(string $database, string $table, array $options): array
    {
        return $this->withConn($database, function (PDO $pdo) use ($table, $options): array {
            $start = microtime(true);
            $tableEsc = $this->quoteIdent($table);
            $structured = self::normalizeStructuredFilters($options['structuredFilters'] ?? null);
            [$sfClause, $sfParams] = $this->buildStructuredWhereMysql($structured);

            $rawFilter = isset($options['filter']) ? trim((string)$options['filter']) : '';

            $whereParts = [];
            $params = $sfParams;
            if ($sfClause !== '') {
                $whereParts[] = '(' . $sfClause . ')';
            }
            if ($rawFilter !== '') {
                $whereParts[] = '(' . $rawFilter . ')';
            }
            $where = $whereParts ? 'WHERE ' . implode(' AND ', $whereParts) : '';

            $sortKeys = $options['sortKeys'] ?? null;
            if (!empty($sortKeys) && is_array($sortKeys)) {
                $parts = array_map(function ($k) {
                    $dir = ($k['dir'] ?? 'ASC') === 'DESC' ? 'DESC' : 'ASC';
                    return $this->quoteIdent((string)$k['col']) . " {$dir}";
                }, $sortKeys);
                $orderClause = 'ORDER BY ' . implode(', ', $parts);
            } elseif (!empty($options['orderBy'])) {
                $orderBy = $this->quoteIdent((string)$options['orderBy']);
                $orderDir = ($options['orderDir'] ?? '') === 'DESC' ? 'DESC' : 'ASC';
                $orderClause = "ORDER BY {$orderBy} {$orderDir}";
            } else {
                $orderClause = '';
            }

            $limit = max(1, min(10000, (int)$options['limit']));
            $offset = max(0, (int)$options['offset']);

            $countSql = "SELECT COUNT(*) AS cnt FROM {$tableEsc} {$where}";
            if ($params) {
                $cst = $pdo->prepare($countSql);
                $cst->execute($params);
                $total = (int)($cst->fetch()['cnt'] ?? 0);
            } else {
                $countRow = $pdo->query($countSql)->fetch();
                $total = (int)($countRow['cnt'] ?? 0);
            }

            $sql = "SELECT * FROM {$tableEsc} {$where} {$orderClause} LIMIT {$limit} OFFSET {$offset}";
            $stmt = $params ? $pdo->prepare($sql) : null;
            if ($stmt) {
                $stmt->execute($params);
            } else {
                $stmt = $pdo->query($sql);
            }
            $rows = $stmt->fetchAll();

            $columns = [];
            if ($stmt->columnCount() > 0) {
                for ($i = 0; $i < $stmt->columnCount(); $i++) {
                    $meta = $stmt->getColumnMeta($i);
                    $columns[] = (string)($meta['name'] ?? "col{$i}");
                }
            }
            if (!$columns && $rows) {
                $columns = array_keys($rows[0]);
            }

            return [
                'columns' => $columns,
                'rows' => self::normalizeRows($rows),
                'rowCount' => count($rows),
                'total' => $total,
                'executionTimeMs' => (int)round((microtime(true) - $start) * 1000),
            ];
        });
    }

    /**
     * @param list<array<string,mixed>> $filters @return array{0:string,1:list<mixed>}
     */
    private function buildStructuredWhereMysql(array $filters): array
    {
        if ($filters === []) {
            return ['', []];
        }
        $parts = [];
        $params = [];
        foreach ($filters as $f) {
            $colRaw = trim((string)($f['column'] ?? ''));
            $col = $this->assertValidFilterIdent($colRaw);
            $op = strtolower(trim(str_replace(['-', ' '], '_', (string)($f['op'] ?? ''))));
            $quoted = $this->quoteIdent($col);
            switch ($op) {
                case '=':
                case 'eq':
                    $parts[] = "{$quoted} <=> ?";
                    $params[] = $f['value'] ?? null;
                    break;
                case '!=':
                case '<>':
                case 'ne':
                    $parts[] = "NOT ({$quoted} <=> ?)";
                    $params[] = $f['value'] ?? null;
                    break;
                case 'gt':
                case '>':
                    $parts[] = "{$quoted} > ?";
                    $params[] = $f['value'];
                    break;
                case 'gte':
                case '>=':
                    $parts[] = "{$quoted} >= ?";
                    $params[] = $f['value'];
                    break;
                case 'lt':
                case '<':
                    $parts[] = "{$quoted} < ?";
                    $params[] = $f['value'];
                    break;
                case 'lte':
                case '<=':
                    $parts[] = "{$quoted} <= ?";
                    $params[] = $f['value'];
                    break;
                case 'like':
                    $parts[] = "{$quoted} LIKE ?";
                    $params[] = (string)($f['value'] ?? '');
                    break;
                case 'ilike':
                    $parts[] = "LOWER({$quoted}) LIKE LOWER(?)";
                    $params[] = (string)($f['value'] ?? '');
                    break;
                case 'is_null':
                case 'isnull':
                    $parts[] = "{$quoted} IS NULL";
                    break;
                case 'is_not_null':
                case 'isnotnull':
                    $parts[] = "{$quoted} IS NOT NULL";
                    break;
                default:
                    throw new \RuntimeException('Unsupported structured filter operator: ' . $op, 400);
            }
        }
        return [implode(' AND ', $parts), $params];
    }

    private function assertValidFilterIdent(string $column): string
    {
        if ($column === '' || !preg_match('/^[A-Za-z0-9_]{1,64}$/', $column)) {
            throw new \RuntimeException('Invalid filter column identifier', 400);
        }
        return $column;
    }

    /** @param mixed $raw @return list<array<string,mixed>> */
    private static function normalizeStructuredFilters(mixed $raw): array
    {
        if ($raw === null) {
            return [];
        }
        if (!is_array($raw)) {
            throw new \RuntimeException('structuredFilters must be an array', 400);
        }
        if ($raw !== [] && isset($raw['column']) && isset($raw['op'])) {
            /** @var array<string,mixed> $raw */
            return [$raw];
        }
        $out = [];
        foreach ($raw as $entry) {
            if (is_array($entry)) {
                $out[] = $entry;
            }
        }
        return $out;
    }

    /** @return list<string> */
    public function getPrimaryKeys(string $database, string $table): array
    {
        return $this->withConn(null, static function (PDO $pdo) use ($database, $table): array {
            $st = $pdo->prepare(
                'SELECT COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE
                 WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND CONSTRAINT_NAME = \'PRIMARY\'
                 ORDER BY ORDINAL_POSITION'
            );
            $st->execute([$database, $table]);
            return array_map(static fn(array $r): string => (string)$r['COLUMN_NAME'], $st->fetchAll());
        });
    }

    /** @param array<string,mixed> $data */
    public function insertRow(string $database, string $table, array $data): void
    {
        $this->withConn($database, function (PDO $pdo) use ($table, $data): void {
            $keys = array_keys($data);
            $placeholders = implode(', ', array_fill(0, count($keys), '?'));
            $cols = implode(', ', array_map(fn(string $k): string => $this->quoteIdent($k), $keys));
            $tableEsc = $this->quoteIdent($table);
            $st = $pdo->prepare("INSERT INTO {$tableEsc} ({$cols}) VALUES ({$placeholders})");
            $st->execute(array_values($data));
        });
    }

    /** @param array<string,mixed> $pk @param array<string,mixed> $data */
    public function updateRow(string $database, string $table, array $pk, array $data): void
    {
        $this->withConn($database, function (PDO $pdo) use ($table, $pk, $data): void {
            $setClause = implode(', ', array_map(
                fn(string $k): string => $this->quoteIdent($k) . ' = ?',
                array_keys($data)
            ));
            $whereClause = implode(' AND ', array_map(
                fn(string $k): string => $this->quoteIdent($k) . ' = ?',
                array_keys($pk)
            ));
            $tableEsc = $this->quoteIdent($table);
            $st = $pdo->prepare("UPDATE {$tableEsc} SET {$setClause} WHERE {$whereClause}");
            $st->execute([...array_values($data), ...array_values($pk)]);
        });
    }

    /** @param array<string,mixed> $pk */
    public function deleteRow(string $database, string $table, array $pk): void
    {
        $this->withConn($database, function (PDO $pdo) use ($table, $pk): void {
            $whereClause = implode(' AND ', array_map(
                fn(string $k): string => $this->quoteIdent($k) . ' = ?',
                array_keys($pk)
            ));
            $tableEsc = $this->quoteIdent($table);
            $st = $pdo->prepare("DELETE FROM {$tableEsc} WHERE {$whereClause}");
            $st->execute(array_values($pk));
        });
    }

    public function explain(string $sql, ?string $database = null, bool $analyze = false): mixed
    {
        return $this->withConn($database, static function (PDO $pdo) use ($sql, $analyze): mixed {
            $prefix = $analyze ? 'EXPLAIN ANALYZE FORMAT=JSON' : 'EXPLAIN FORMAT=JSON';
            $row = $pdo->query("{$prefix} {$sql}")->fetch();
            if (!$row) {
                return [];
            }
            $raw = $row['EXPLAIN'] ?? null;
            if (is_string($raw)) {
                $decoded = json_decode($raw, true);
                return json_last_error() === JSON_ERROR_NONE ? $decoded : $raw;
            }
            return $row;
        });
    }

    /** @return list<array<string,mixed>> */
    public function getProcessList(): array
    {
        return $this->withConn(null, static function (PDO $pdo): array {
            return self::normalizeRows($pdo->query('SHOW FULL PROCESSLIST')->fetchAll());
        });
    }

    public function killProcess(string|int $id): void
    {
        $this->withConn(null, static function (PDO $pdo) use ($id): void {
            $pdo->exec('KILL ' . (int)$id);
        });
    }

    /** @return list<array<string,mixed>> */
    public function listEngineUsers(): array
    {
        return $this->withConn(null, static function (PDO $pdo): array {
            return self::normalizeRows(
                $pdo->query('SELECT User, Host, account_locked, password_expired FROM mysql.user ORDER BY User, Host')->fetchAll()
            );
        });
    }

    public function createEngineUser(string $username, string $password, string $host = '%'): void
    {
        $this->withConn(null, static function (PDO $pdo) use ($username, $password, $host): void {
            $st = $pdo->prepare('CREATE USER ?@? IDENTIFIED BY ?');
            $st->execute([$username, $host, $password]);
        });
    }

    public function grantPrivileges(string $username, string $database, string $privileges, string $host = '%'): void
    {
        $this->withConn(null, function (PDO $pdo) use ($username, $database, $privileges, $host): void {
            $dbEsc = $this->quoteIdent($database);
            $st = $pdo->prepare("GRANT {$privileges} ON {$dbEsc}.* TO ?@?");
            $st->execute([$username, $host]);
            $pdo->exec('FLUSH PRIVILEGES');
        });
    }

    public function revokePrivileges(string $username, string $database, string $privileges, string $host = '%'): void
    {
        $this->withConn(null, function (PDO $pdo) use ($username, $database, $privileges, $host): void {
            $dbEsc = $this->quoteIdent($database);
            $st = $pdo->prepare("REVOKE {$privileges} ON {$dbEsc}.* FROM ?@?");
            $st->execute([$username, $host]);
            $pdo->exec('FLUSH PRIVILEGES');
        });
    }

    public function dropEngineUser(string $username, string $host = '%'): void
    {
        $this->withConn(null, static function (PDO $pdo) use ($username, $host): void {
            $st = $pdo->prepare('DROP USER ?@?');
            $st->execute([$username, $host]);
            $pdo->exec('FLUSH PRIVILEGES');
        });
    }

    /** @return array<string,mixed> */
    public function getServerInfo(): array
    {
        return $this->withConn(null, static function (PDO $pdo): array {
            $version = $pdo->query(
                'SELECT VERSION() AS version, @@hostname AS hostname, @@port AS port, @@datadir AS datadir'
            )->fetch();
            $status = $pdo->query(
                "SHOW GLOBAL STATUS WHERE Variable_name IN ('Uptime','Threads_connected','Queries','Slow_queries')"
            )->fetchAll();
            $vars = $pdo->query(
                "SHOW GLOBAL VARIABLES WHERE Variable_name IN ('max_connections','innodb_buffer_pool_size','character_set_server','collation_server','time_zone','sql_mode')"
            )->fetchAll();
            return [
                'engine' => 'MySQL',
                'version' => $version['version'] ?? '',
                'hostname' => $version['hostname'] ?? null,
                'port' => isset($version['port']) ? (int)$version['port'] : null,
                'datadir' => $version['datadir'] ?? null,
                'status' => self::normalizeRows($status),
                'variables' => self::normalizeRows($vars),
            ];
        });
    }

    public function executeDDL(string $ddl, ?string $database = null): void
    {
        $parts = array_filter(array_map('trim', explode(';', $ddl)), static fn(string $s): bool => $s !== '');
        $multi = count($parts) > 1;
        $pdo = $this->createPdo($database, $multi);
        try {
            if ($multi) {
                $pdo->exec($ddl);
            } else {
                $pdo->exec($ddl);
            }
        } finally {
            $pdo = null;
        }
    }

    /** @return list<array<string,mixed>> */
    public function getForeignKeys(string $database): array
    {
        return $this->withConn(null, static function (PDO $pdo) use ($database): array {
            $st = $pdo->prepare(
                'SELECT CONSTRAINT_NAME, TABLE_NAME, COLUMN_NAME, REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME
                 FROM information_schema.KEY_COLUMN_USAGE
                 WHERE TABLE_SCHEMA = ? AND REFERENCED_TABLE_NAME IS NOT NULL'
            );
            $st->execute([$database]);
            $out = [];
            foreach ($st->fetchAll() as $r) {
                $out[] = [
                    'constraintName' => (string)$r['CONSTRAINT_NAME'],
                    'fromTable' => (string)$r['TABLE_NAME'],
                    'fromColumn' => (string)$r['COLUMN_NAME'],
                    'toTable' => (string)$r['REFERENCED_TABLE_NAME'],
                    'toColumn' => (string)$r['REFERENCED_COLUMN_NAME'],
                ];
            }
            return $out;
        });
    }

    public function createDatabase(string $name, string $charset = 'utf8mb4'): void
    {
        $this->withConn(null, function (PDO $pdo) use ($name, $charset): void {
            $nameEsc = $this->quoteIdent($name);
            $pdo->exec("CREATE DATABASE {$nameEsc} DEFAULT CHARACTER SET {$charset}");
        });
    }

    public function dropDatabase(string $name): void
    {
        $this->withConn(null, function (PDO $pdo) use ($name): void {
            $pdo->exec('DROP DATABASE ' . $this->quoteIdent($name));
        });
    }

    public function truncateTable(string $database, string $table): void
    {
        $this->withConn($database, function (PDO $pdo) use ($table): void {
            $pdo->exec('TRUNCATE TABLE ' . $this->quoteIdent($table));
        });
    }

    public function dropTable(string $database, string $table): void
    {
        $this->withConn($database, function (PDO $pdo) use ($table): void {
            $pdo->exec('DROP TABLE ' . $this->quoteIdent($table));
        });
    }

    public function dropView(string $database, string $view): void
    {
        $this->withConn($database, function (PDO $pdo) use ($view): void {
            $pdo->exec('DROP VIEW ' . $this->quoteIdent($view));
        });
    }

    public function renameTable(string $database, string $oldName, string $newName): void
    {
        $this->withConn($database, function (PDO $pdo) use ($oldName, $newName): void {
            $pdo->exec(
                'RENAME TABLE ' . $this->quoteIdent($oldName) . ' TO ' . $this->quoteIdent($newName)
            );
        });
    }

    /** @return list<array<string,mixed>> */
    public function getDbJobs(?string $database = null): array
    {
        $system = ['information_schema', 'performance_schema', 'mysql', 'sys'];
        return $this->withConn(null, function (PDO $pdo) use ($database, $system): array {
            if ($database !== null && $database !== '') {
                $st = $pdo->prepare(
                    'SELECT EVENT_SCHEMA, EVENT_NAME, EVENT_TYPE, EXECUTE_AT, INTERVAL_VALUE, INTERVAL_FIELD,
                            STATUS, LAST_EXECUTED, EVENT_DEFINITION, ON_COMPLETION, STARTS, ENDS
                     FROM information_schema.EVENTS WHERE EVENT_SCHEMA = ? ORDER BY EVENT_NAME'
                );
                $st->execute([$database]);
            } else {
                $placeholders = implode(',', array_fill(0, count($system), '?'));
                $st = $pdo->prepare(
                    "SELECT EVENT_SCHEMA, EVENT_NAME, EVENT_TYPE, EXECUTE_AT, INTERVAL_VALUE, INTERVAL_FIELD,
                            STATUS, LAST_EXECUTED, EVENT_DEFINITION, ON_COMPLETION, STARTS, ENDS
                     FROM information_schema.EVENTS
                     WHERE EVENT_SCHEMA NOT IN ({$placeholders}) ORDER BY EVENT_SCHEMA, EVENT_NAME"
                );
                $st->execute($system);
            }
            $rows = self::normalizeRows($st->fetchAll());
            $out = [];
            foreach ($rows as $r) {
                $db = (string)($r['EVENT_SCHEMA'] ?? '');
                $name = (string)($r['EVENT_NAME'] ?? '');
                $interval = trim((string)($r['INTERVAL_VALUE'] ?? '') . ' ' . (string)($r['INTERVAL_FIELD'] ?? ''));
                $schedule = ($r['EVENT_TYPE'] ?? '') === 'ONE TIME'
                    ? (string)($r['EXECUTE_AT'] ?? '')
                    : ($interval !== '' ? "EVERY {$interval}" : (string)($r['STARTS'] ?? ''));
                $out[] = [
                    'jobId' => \Navicat\Support\DbJobId::encodeMysql($db, $name),
                    'name' => $name,
                    'database' => $db,
                    'engine' => 'mysql',
                    'jobType' => 'EVENT',
                    'status' => (string)($r['STATUS'] ?? ''),
                    'enabled' => strtoupper((string)($r['STATUS'] ?? '')) === 'ENABLED',
                    'eventType' => (string)($r['EVENT_TYPE'] ?? ''),
                    'schedule' => $schedule,
                    'lastExecuted' => $r['LAST_EXECUTED'] ?? null,
                    'definition' => (string)($r['EVENT_DEFINITION'] ?? ''),
                    'onCompletion' => (string)($r['ON_COMPLETION'] ?? ''),
                ];
            }
            return $out;
        });
    }

    /** @return array<string,mixed> */
    public function getDbJob(string $database, string $name): array
    {
        $jobs = $this->getDbJobs($database);
        foreach ($jobs as $j) {
            if (($j['name'] ?? '') === $name) {
                return $j;
            }
        }
        throw new \RuntimeException('Event not found');
    }

    public function enableDbJob(string $database, string $name): void
    {
        $this->alterEventStatus($database, $name, 'ENABLE');
    }

    public function disableDbJob(string $database, string $name): void
    {
        $this->alterEventStatus($database, $name, 'DISABLE');
    }

    public function dropDbJob(string $database, string $name): void
    {
        $this->withConn(null, function (PDO $pdo) use ($database, $name): void {
            $pdo->exec(
                'DROP EVENT IF EXISTS ' . $this->quoteIdent($database) . '.' . $this->quoteIdent($name)
            );
        });
    }

    /** @param array<string,mixed> $def */
    public function createDbJob(string $database, array $def): void
    {
        $sql = trim((string)($def['sql'] ?? $def['definition'] ?? ''));
        if ($sql === '') {
            throw new \InvalidArgumentException('sql/definition required');
        }
        $name = trim((string)($def['name'] ?? ''));
        if ($name === '') {
            throw new \InvalidArgumentException('name required');
        }
        $schedule = trim((string)($def['schedule'] ?? 'EVERY 1 DAY'));
        $status = !empty($def['enabled']) ? '' : 'DISABLE';
        $onCompletion = strtoupper((string)($def['onCompletion'] ?? 'NOT PRESERVE')) === 'PRESERVE'
            ? 'ON COMPLETION PRESERVE' : 'ON COMPLETION NOT PRESERVE';
        $ddl = sprintf(
            'CREATE EVENT %s.%s ON SCHEDULE %s %s %s DO %s',
            $this->quoteIdent($database),
            $this->quoteIdent($name),
            $schedule,
            $onCompletion,
            $status !== '' ? $status : '',
            $sql
        );
        $this->withConn($database, static function (PDO $pdo) use ($ddl): void {
            $pdo->exec($ddl);
        });
    }

    /** @return list<array<string,mixed>> */
    public function getDbJobHistory(string $database, string $eventName): array
    {
        $job = $this->getDbJob($database, $eventName);
        return [[
            'jobName' => $eventName,
            'database' => $database,
            'lastExecuted' => $job['lastExecuted'] ?? null,
            'status' => $job['status'] ?? null,
            'note' => 'MySQL does not store per-run event history; only LAST_EXECUTED from information_schema.EVENTS',
        ]];
    }

    /** @return array<string,mixed> */
    public function getLogConfig(): array
    {
        return $this->withConn(null, static function (PDO $pdo): array {
            $vars = [
                'log_error', 'general_log', 'general_log_file', 'slow_query_log',
                'slow_query_log_file', 'log_bin', 'relay_log',
            ];
            $placeholders = implode(',', array_fill(0, count($vars), '?'));
            $st = $pdo->prepare(
                "SHOW GLOBAL VARIABLES WHERE Variable_name IN ({$placeholders})"
            );
            $st->execute($vars);
            $out = [];
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $out[(string)$r['Variable_name']] = $r['Value'];
            }
            return $out;
        });
    }

    /** @return list<array<string,mixed>> */
    public function getSlowQueryLog(int $limit = 50): array
    {
        return $this->withConn(null, static function (PDO $pdo) use ($limit): array {
            try {
                $st = $pdo->prepare(
                    'SELECT start_time, user_host, query_time, lock_time, rows_sent, rows_examined, db, sql_text
                     FROM mysql.slow_log ORDER BY start_time DESC LIMIT ?'
                );
                $st->bindValue(1, $limit, PDO::PARAM_INT);
                $st->execute();
                return self::normalizeRows($st->fetchAll());
            } catch (\Throwable $e) {
                return [];
            }
        });
    }

    /** @return list<array<string,mixed>> */
    public function getGeneralLog(int $limit = 100): array
    {
        return $this->withConn(null, static function (PDO $pdo) use ($limit): array {
            try {
                $st = $pdo->prepare(
                    'SELECT event_time, user_host, thread_id, server_id, command_type, argument
                     FROM mysql.general_log ORDER BY event_time DESC LIMIT ?'
                );
                $st->bindValue(1, $limit, PDO::PARAM_INT);
                $st->execute();
                return self::normalizeRows($st->fetchAll());
            } catch (\Throwable $e) {
                return [];
            }
        });
    }

    /** @return list<array<string,mixed>> */
    public function getBinaryLogs(): array
    {
        return $this->withConn(null, static function (PDO $pdo): array {
            try {
                return self::normalizeRows($pdo->query('SHOW BINARY LOGS')->fetchAll());
            } catch (\Throwable $e) {
                return [];
            }
        });
    }

    public function checkQueryStoreAvailable(): bool
    {
        return $this->withConn(null, static function (PDO $pdo): bool {
            $n = (int)$pdo->query(
                "SELECT COUNT(*) FROM information_schema.TABLES
                 WHERE TABLE_SCHEMA = 'performance_schema' AND TABLE_NAME = 'events_statements_summary_by_digest'"
            )->fetchColumn();
            return $n > 0;
        });
    }

    /** @return list<array<string,mixed>> */
    public function getTopQueries(int $limit = 25): array
    {
        return $this->withConn(null, static function (PDO $pdo) use ($limit): array {
            $st = $pdo->prepare(
                'SELECT SCHEMA_NAME, DIGEST_TEXT, COUNT_STAR AS calls,
                        ROUND(SUM_TIMER_WAIT/1e12, 4) AS total_sec,
                        ROUND(AVG_TIMER_WAIT/1e12, 6) AS avg_sec,
                        SUM_ROWS_EXAMINED AS rows_examined, SUM_ROWS_SENT AS rows_sent,
                        FIRST_SEEN, LAST_SEEN
                 FROM performance_schema.events_statements_summary_by_digest
                 WHERE SCHEMA_NAME IS NOT NULL
                 ORDER BY SUM_TIMER_WAIT DESC LIMIT ?'
            );
            $st->bindValue(1, $limit, PDO::PARAM_INT);
            $st->execute();
            return self::normalizeRows($st->fetchAll());
        });
    }

    public function resetQueryStats(): void
    {
        $this->withConn(null, static function (PDO $pdo): void {
            $pdo->exec('TRUNCATE TABLE performance_schema.events_statements_summary_by_digest');
        });
    }

    private function alterEventStatus(string $database, string $name, string $action): void
    {
        $this->withConn(null, function (PDO $pdo) use ($database, $name, $action): void {
            $pdo->exec(
                'ALTER EVENT ' . $this->quoteIdent($database) . '.' . $this->quoteIdent($name) . ' ' . $action
            );
        });
    }

    private function quoteIdent(string $value): string
    {
        return '`' . str_replace('`', '``', $value) . '`';
    }

    /** @param list<array<string,mixed>> $rows @return list<array<string,mixed>> */
    private static function normalizeRows(array $rows): array
    {
        return array_map(static function (array $row): array {
            $out = [];
            foreach ($row as $k => $v) {
                if (is_resource($v)) {
                    $out[$k] = stream_get_contents($v);
                } elseif (is_string($v) && preg_match('/[^\x09\x0A\x0D\x20-\x7E]/', $v)) {
                    $out[$k] = '0x' . strtoupper(bin2hex($v));
                } else {
                    $out[$k] = $v;
                }
            }
            return $out;
        }, $rows);
    }
}
