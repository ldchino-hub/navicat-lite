<?php
declare(strict_types=1);

namespace Navicat\Drivers;

use PDO;
use PDOStatement;

final class PostgresDriver
{
    /** @var array<string,mixed> */
    private array $creds;

    /** @param array<string,mixed> $creds */
    public function __construct(array $creds)
    {
        $this->creds = $creds;
    }

    private function createPdo(?string $database = null): PDO
    {
        $host = (string)$this->creds['host'];
        $port = (int)$this->creds['port'];
        $db = $database ?? ($this->creds['defaultDb'] ?? 'postgres');
        $dsn = "pgsql:host={$host};port={$port};dbname={$db}";

        $opts = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => true,
        ];
        if (in_array($this->creds['sslMode'] ?? '', ['require', 'verify-full'], true)) {
            $opts[PDO::PGSQL_ATTR_DISABLE_PREPARES] = true;
        }

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
        $system = ['template0', 'template1', 'rdsadmin'];
        return $this->withConn(null, static function (PDO $pdo) use ($system): array {
            $rows = $pdo->query(
                'SELECT datname FROM pg_database WHERE datistemplate = false ORDER BY datname'
            )->fetchAll();
            $out = [];
            foreach ($rows as $r) {
                $name = (string)$r['datname'];
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
        return $this->withConn($database, static function (PDO $pdo): array {
            $rows = $pdo->query(
                'SELECT c.relname AS name,
                        pg_total_relation_size(c.oid) AS size_bytes,
                        c.reltuples::bigint AS rows_estimate,
                        obj_description(c.oid) AS comment
                 FROM pg_class c
                 JOIN pg_namespace n ON c.relnamespace = n.oid
                 WHERE n.nspname = \'public\' AND c.relkind = \'r\'
                 ORDER BY c.relname'
            )->fetchAll();
            $out = [];
            foreach ($rows as $r) {
                $out[] = [
                    'name' => (string)$r['name'],
                    'type' => 'table',
                    'rowsEstimate' => isset($r['rows_estimate']) ? (int)$r['rows_estimate'] : null,
                    'sizeBytes' => isset($r['size_bytes']) ? (int)$r['size_bytes'] : null,
                    'comment' => $r['comment'] ?? null,
                ];
            }
            return $out;
        });
    }

    /** @return list<array<string,mixed>> */
    public function listViews(string $database): array
    {
        return $this->withConn($database, static function (PDO $pdo): array {
            $rows = $pdo->query(
                'SELECT viewname AS name FROM pg_views WHERE schemaname = \'public\' ORDER BY viewname'
            )->fetchAll();
            $out = [];
            foreach ($rows as $r) {
                $out[] = ['name' => (string)$r['name'], 'type' => 'view'];
            }
            return $out;
        });
    }

    /** @return list<array<string,mixed>> */
    public function listRoutines(string $database): array
    {
        return $this->withConn($database, static function (PDO $pdo): array {
            $rows = $pdo->query(
                'SELECT routine_name, routine_type FROM information_schema.routines
                 WHERE routine_schema = \'public\' ORDER BY routine_name'
            )->fetchAll();
            $out = [];
            foreach ($rows as $r) {
                $out[] = [
                    'name' => (string)$r['routine_name'],
                    'type' => ($r['routine_type'] ?? '') === 'PROCEDURE' ? 'procedure' : 'function',
                ];
            }
            return $out;
        });
    }

    /** @return list<array<string,mixed>> */
    public function listTriggers(string $database): array
    {
        return $this->withConn($database, static function (PDO $pdo): array {
            $rows = $pdo->query(
                'SELECT t.trigger_name, t.event_object_table, t.action_timing, t.event_manipulation, t.action_statement
                 FROM information_schema.triggers t
                 WHERE t.trigger_schema = \'public\'
                 ORDER BY t.event_object_table, t.trigger_name'
            )->fetchAll();
            $out = [];
            foreach ($rows as $r) {
                $out[] = [
                    'name' => (string)$r['trigger_name'],
                    'table' => (string)$r['event_object_table'],
                    'timing' => (string)$r['action_timing'],
                    'event' => (string)$r['event_manipulation'],
                    'definition' => (string)($r['action_statement'] ?? ''),
                ];
            }
            return $out;
        });
    }

    public function getRoutineSource(string $database, string $name): string
    {
        return $this->withConn($database, static function (PDO $pdo) use ($name): string {
            $st = $pdo->prepare(
                'SELECT pg_get_functiondef(p.oid) AS def
                 FROM pg_proc p JOIN pg_namespace n ON p.pronamespace = n.oid
                 WHERE n.nspname = \'public\' AND p.proname = ? LIMIT 1'
            );
            $st->execute([$name]);
            $row = $st->fetch();
            return (string)($row['def'] ?? '');
        });
    }

    public function getViewDefinition(string $database, string $view): string
    {
        return $this->withConn($database, static function (PDO $pdo) use ($view): string {
            $st = $pdo->prepare(
                "SELECT pg_get_viewdef((quote_ident('public') || '.' || quote_ident(?))::regclass, true) AS def"
            );
            $st->execute([$view]);
            $row = $st->fetch();
            return (string)($row['def'] ?? '');
        });
    }

    /** Types: table, view, routine, trigger */
    public function getObjectDdl(string $database, string $type, string $name): string
    {
        $t = strtolower($type);
        return match ($t) {
            'table' => $this->withConn($database, fn(PDO $pdo): string => $this->buildPostgresTableDdl($pdo, $name)),
            'view' => $this->withConn($database, function (PDO $pdo) use ($name): string {
                $st = $pdo->prepare(
                    "SELECT pg_get_viewdef((quote_ident('public') || '.' || quote_ident(?))::regclass, true) AS def"
                );
                $st->execute([$name]);
                $row = $st->fetch();
                $body = trim((string)($row['def'] ?? ''));
                if ($body === '') {
                    throw new \RuntimeException("View {$name} not found", 404);
                }
                $v = $this->quoteIdent($name);
                return "CREATE OR REPLACE VIEW public.{$v} AS\n{$body};\n";
            }),
            'routine' => $this->withConn($database, function (PDO $pdo) use ($name): string {
                $oids = $this->postgresRoutineOids($pdo, $name);
                if (!$oids) {
                    throw new \RuntimeException("Routine {$name} not found", 404);
                }
                if (count($oids) > 1) {
                    throw new \RuntimeException('Routine name is ambiguous for DDL; specify a unique overload', 400);
                }
                $st = $pdo->prepare('SELECT pg_get_functiondef(?) AS def');
                $st->execute([$oids[0]]);
                $row = $st->fetch();
                return trim((string)($row['def'] ?? '')) . "\n";
            }),
            'trigger' => $this->withConn($database, function (PDO $pdo) use ($name): string {
                $st = $pdo->prepare(
                    'SELECT pg_get_triggerdef(t.oid, true) AS def
                     FROM pg_trigger t
                     JOIN pg_class c ON c.oid = t.tgrelid
                     JOIN pg_namespace n ON n.oid = c.relnamespace
                     WHERE n.nspname = \'public\' AND NOT t.tgisinternal AND t.tgname = ?'
                );
                $st->execute([$name]);
                $rows = $st->fetchAll();
                if ($rows === []) {
                    throw new \RuntimeException("Trigger {$name} not found", 404);
                }
                if (count($rows) > 1) {
                    throw new \RuntimeException(
                        'Trigger name is ambiguous; multiple triggers share this name across tables.',
                        400
                    );
                }
                return trim((string)($rows[0]['def'] ?? '')) . "\n";
            }),
            default => throw new \RuntimeException('Unsupported object type', 400),
        };
    }

    /** Clone an object definition into another database within the same
     *  PostgreSQL connection. Cross-database row copy is not supported by PG
     *  (a single connection cannot reference two databases); copyData only
     *  works when sourceDb === targetDb. */
    public function cloneObject(string $sourceDb, string $type, string $name, string $targetDb, string $newName, bool $copyData = false): array
    {
        $t = strtolower($type);
        $ddl = $this->getObjectDdl($sourceDb, $t, $name);
        $rewritten = $this->rewriteObjectName($ddl, $name, $newName);
        $this->executeDDL($rewritten, $targetDb);
        if ($t === 'table' && $copyData) {
            if ($sourceDb !== $targetDb) {
                throw new \RuntimeException(
                    'Cross-database data copy is not supported for PostgreSQL. Clone the definition only, or copy data within the same database.',
                    400
                );
            }
            $src = 'public.' . $this->quoteIdent($name);
            $dst = 'public.' . $this->quoteIdent($newName);
            $this->withConn($targetDb, static function (PDO $pdo) use ($src, $dst): void {
                $pdo->exec("INSERT INTO {$dst} SELECT * FROM {$src}");
            });
        }
        return ['ok' => true, 'type' => $t, 'newName' => $newName, 'targetDb' => $targetDb];
    }

    /** Replace the first occurrence of the (quoted or bare) object name in the DDL. */
    private function rewriteObjectName(string $ddl, string $old, string $new): string
    {
        $quoted = '/"' . preg_quote($old, '/') . '"/';
        if (preg_match($quoted, $ddl)) {
            return preg_replace($quoted, '"' . str_replace('"', '""', $new) . '"', $ddl, 1);
        }
        return preg_replace('/\b' . preg_quote($old, '/') . '\b/', $new, $ddl, 1);
    }

    /** @return list<string> pg_proc oids as strings */
    private function postgresRoutineOids(PDO $pdo, string $name): array
    {
        $st = $pdo->prepare(
            'SELECT p.oid::text
             FROM pg_proc p JOIN pg_namespace n ON p.pronamespace = n.oid
             WHERE n.nspname = \'public\' AND p.proname = ?'
        );
        $st->execute([$name]);
        return array_map(static fn(array $r): string => (string)$r['oid'], $st->fetchAll());
    }

    private function buildPostgresTableDdl(PDO $pdo, string $table): string
    {
        $st = $pdo->prepare(
            'SELECT a.attname AS name,
                    pg_catalog.format_type(a.atttypid, a.atttypmod) AS typ,
                    a.attnotnull AS notnull,
                    pg_catalog.pg_get_expr(ad.adbin, ad.adrelid) AS def
             FROM pg_catalog.pg_attribute a
             JOIN pg_catalog.pg_class c ON a.attrelid = c.oid
             JOIN pg_catalog.pg_namespace n ON c.relnamespace = n.oid
             LEFT JOIN pg_catalog.pg_attrdef ad ON a.attrelid = ad.adrelid AND a.attnum = ad.adnum
             WHERE n.nspname = \'public\' AND c.relname = ? AND a.attnum > 0 AND NOT a.attisdropped
             ORDER BY a.attnum'
        );
        $st->execute([$table]);
        $cols = $st->fetchAll();
        if (!$cols) {
            throw new \RuntimeException("Table {$table} not found", 404);
        }
        $lines = [];
        foreach ($cols as $c) {
            $line = $this->quoteIdent((string)$c['name']) . ' ' . (string)$c['typ'];
            if (!empty($c['notnull'])) {
                $line .= ' NOT NULL';
            }
            $def = $c['def'] ?? null;
            if (is_string($def) && $def !== '') {
                $line .= ' DEFAULT ' . $def;
            }
            $lines[] = '    ' . $line;
        }

        $pkSt = $pdo->prepare(
            'SELECT pg_get_constraintdef(con.oid, true) AS def
             FROM pg_constraint con
             JOIN pg_class rel ON rel.oid = con.conrelid
             JOIN pg_namespace n ON n.oid = rel.relnamespace
             WHERE n.nspname = \'public\' AND rel.relname = ? AND con.contype = \'p\''
        );
        $pkSt->execute([$table]);
        if ($pkRow = $pkSt->fetch()) {
            $lines[] = '    ' . (string)$pkRow['def'];
        }

        return 'CREATE TABLE public.' . $this->quoteIdent($table) . " (\n" . implode(",\n", $lines) . "\n);\n";
    }

    /** @return array<string,mixed> */
    public function getTableInfo(string $database, string $table): array
    {
        return $this->withConn($database, function (PDO $pdo) use ($table): array {
            $st = $pdo->prepare(
                'SELECT c.column_name, c.data_type, c.udt_name, c.is_nullable, c.column_default,
                        c.character_maximum_length, c.ordinal_position,
                        CASE WHEN pk.column_name IS NOT NULL THEN true ELSE false END AS is_pk,
                        fk.foreign_table_name, fk.foreign_column_name,
                        pgd.description AS comment
                 FROM information_schema.columns c
                 LEFT JOIN (
                   SELECT ku.column_name FROM information_schema.table_constraints tc
                   JOIN information_schema.key_column_usage ku ON tc.constraint_name = ku.constraint_name
                   WHERE tc.table_schema = \'public\' AND tc.table_name = ? AND tc.constraint_type = \'PRIMARY KEY\'
                 ) pk ON c.column_name = pk.column_name
                 LEFT JOIN (
                   SELECT kcu.column_name, ccu.table_name AS foreign_table_name, ccu.column_name AS foreign_column_name
                   FROM information_schema.table_constraints tc
                   JOIN information_schema.key_column_usage kcu ON tc.constraint_name = kcu.constraint_name
                   JOIN information_schema.constraint_column_usage ccu ON tc.constraint_name = ccu.constraint_name
                   WHERE tc.table_schema = \'public\' AND tc.table_name = ? AND tc.constraint_type = \'FOREIGN KEY\'
                 ) fk ON c.column_name = fk.column_name
                 LEFT JOIN pg_catalog.pg_statio_all_tables st ON st.schemaname = \'public\' AND st.relname = ?
                 LEFT JOIN pg_catalog.pg_description pgd ON pgd.objoid = st.relid AND pgd.objsubid = c.ordinal_position
                 WHERE c.table_schema = \'public\' AND c.table_name = ?
                 ORDER BY c.ordinal_position'
            );
            $st->execute([$table, $table, $table, $table]);

            $columns = [];
            foreach ($st->fetchAll() as $row) {
                $default = $row['column_default'] ?? null;
                $columns[] = [
                    'name' => (string)$row['column_name'],
                    'type' => (string)($row['udt_name'] ?? $row['data_type']),
                    'nullable' => ($row['is_nullable'] ?? '') === 'YES',
                    'defaultValue' => $default,
                    'isPrimaryKey' => filter_var($row['is_pk'] ?? false, FILTER_VALIDATE_BOOLEAN),
                    'isForeignKey' => !empty($row['foreign_table_name']),
                    'referencedTable' => $row['foreign_table_name'] ?? null,
                    'referencedColumn' => $row['foreign_column_name'] ?? null,
                    'comment' => (string)($row['comment'] ?? ''),
                    'length' => isset($row['character_maximum_length']) ? (int)$row['character_maximum_length'] : null,
                    'ordinalPosition' => isset($row['ordinal_position']) ? (int)$row['ordinal_position'] : null,
                    'autoIncrement' => is_string($default) && str_contains($default, 'nextval'),
                ];
            }

            $idxSt = $pdo->prepare(
                'SELECT indexname, indexdef FROM pg_indexes WHERE tablename = ? AND schemaname = \'public\''
            );
            $idxSt->execute([$table]);
            $indexes = [];
            foreach ($idxSt->fetchAll() as $idx) {
                $idxName = (string)$idx['indexname'];
                $indexdef = (string)$idx['indexdef'];
                $indexes[] = [
                    'name' => $idxName,
                    'columns' => [],
                    'unique' => str_contains($indexdef, 'UNIQUE'),
                    'primary' => str_ends_with($idxName, '_pkey'),
                ];
            }

            $typeSt = $pdo->prepare(
                'SELECT table_type FROM information_schema.tables WHERE table_name = ? AND table_schema = \'public\''
            );
            $typeSt->execute([$table]);
            $typeRow = $typeSt->fetch();

            $fkSt = $pdo->prepare(
                'SELECT con.conname AS name,
                        array_agg(kcu.column_name ORDER BY kcu.ordinal_position) AS columns,
                        ccu.table_name AS ref_table,
                        array_agg(ccu.column_name ORDER BY kcu.ordinal_position) AS ref_columns
                 FROM pg_constraint con
                 JOIN pg_class rel ON rel.oid = con.conrelid
                 JOIN pg_namespace n ON n.oid = rel.relnamespace
                 JOIN information_schema.key_column_usage kcu ON kcu.constraint_name = con.conname
                 JOIN information_schema.constraint_column_usage ccu ON ccu.constraint_name = con.conname
                 WHERE n.nspname = \'public\' AND rel.relname = ? AND con.contype = \'f\'
                 GROUP BY con.conname, ccu.table_name'
            );
            $fkSt->execute([$table]);
            $foreignKeys = [];
            foreach ($fkSt->fetchAll() as $r) {
                $foreignKeys[] = [
                    'name' => (string)$r['name'],
                    'columns' => $this->pgArrayToList($r['columns'] ?? '{}'),
                    'referencedTable' => (string)$r['ref_table'],
                    'referencedColumns' => $this->pgArrayToList($r['ref_columns'] ?? '{}'),
                ];
            }

            $checkSt = $pdo->prepare(
                'SELECT con.conname AS name, pg_get_constraintdef(con.oid) AS expression
                 FROM pg_constraint con
                 JOIN pg_class rel ON rel.oid = con.conrelid
                 JOIN pg_namespace n ON n.oid = rel.relnamespace
                 WHERE n.nspname = \'public\' AND rel.relname = ? AND con.contype = \'c\''
            );
            $checkSt->execute([$table]);
            $checks = [];
            foreach ($checkSt->fetchAll() as $r) {
                $checks[] = [
                    'name' => (string)$r['name'],
                    'expression' => (string)$r['expression'],
                ];
            }

            return [
                'name' => $table,
                'type' => ($typeRow['table_type'] ?? '') === 'VIEW' ? 'view' : 'table',
                'columns' => $columns,
                'indexes' => $indexes,
                'foreignKeys' => $foreignKeys,
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
                    'rows' => $rows,
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
        return $this->withConn($database, function (PDO $pdo) use ($sql, $start): array {
            $statements = [];
            $parts = preg_split('/;(?=([^\'"]*[\'"][^\'"]*[\'"])*[^\'"]*$)/', $sql) ?: [];
            foreach ($parts as $part) {
                $stmt = trim($part);
                if ($stmt === '') {
                    continue;
                }
                $t0 = microtime(true);
                try {
                    $res = $pdo->query($stmt);
                    if ($res instanceof PDOStatement && $res->columnCount() > 0) {
                        $rows = $res->fetchAll();
                        $columns = [];
                        for ($i = 0; $i < $res->columnCount(); $i++) {
                            $meta = $res->getColumnMeta($i);
                            $columns[] = (string)($meta['name'] ?? "col{$i}");
                        }
                        if (!$columns && $rows) {
                            $columns = array_keys($rows[0]);
                        }
                        $statements[] = [
                            'columns' => $columns,
                            'rows' => $rows,
                            'rowCount' => count($rows),
                            'executionTimeMs' => (int)round((microtime(true) - $t0) * 1000),
                        ];
                    } else {
                        $statements[] = [
                            'columns' => [],
                            'rows' => [],
                            'rowCount' => 0,
                            'affectedRows' => $res ? $res->rowCount() : 0,
                            'executionTimeMs' => (int)round((microtime(true) - $t0) * 1000),
                            'message' => ($res ? $res->rowCount() : 0) . ' row(s) affected',
                        ];
                    }
                } catch (\Throwable $e) {
                    $statements[] = [
                        'columns' => [],
                        'rows' => [],
                        'rowCount' => 0,
                        'executionTimeMs' => (int)round((microtime(true) - $t0) * 1000),
                        'message' => 'ERROR: ' . $e->getMessage(),
                    ];
                    break;
                }
            }

            return [
                'statements' => $statements,
                'totalTimeMs' => (int)round((microtime(true) - $start) * 1000),
            ];
        });
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
            [$sfClause, $sfParams] = $this->buildStructuredWherePg($structured);

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

            $orderBy = !empty($options['orderBy'])
                ? $this->quoteIdent((string)$options['orderBy'])
                : null;
            $orderDir = ($options['orderDir'] ?? '') === 'DESC' ? 'DESC' : 'ASC';
            $orderClause = $orderBy ? "ORDER BY {$orderBy} {$orderDir}" : '';

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

            $dataSql = "SELECT * FROM {$tableEsc} {$where} {$orderClause} LIMIT ? OFFSET ?";
            $dataParams = [...$params, $limit, $offset];
            $st = $pdo->prepare($dataSql);
            $st->execute($dataParams);
            $rows = $st->fetchAll();

            $columns = [];
            if ($st->columnCount() > 0) {
                for ($i = 0; $i < $st->columnCount(); $i++) {
                    $meta = $st->getColumnMeta($i);
                    $columns[] = (string)($meta['name'] ?? "col{$i}");
                }
            }
            if (!$columns && $rows) {
                $columns = array_keys($rows[0]);
            }

            return [
                'columns' => $columns,
                'rows' => $rows,
                'rowCount' => count($rows),
                'total' => $total,
                'executionTimeMs' => (int)round((microtime(true) - $start) * 1000),
            ];
        });
    }

    /** @param list<array<string,mixed>> $filters @return array{0:string,1:list<mixed>} */
    private function buildStructuredWherePg(array $filters): array
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
                    $parts[] = "{$quoted} IS NOT DISTINCT FROM ?";
                    $params[] = $f['value'] ?? null;
                    break;
                case '!=':
                case '<>':
                case 'ne':
                    $parts[] = "{$quoted} IS DISTINCT FROM ?";
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
                    $parts[] = "{$quoted} ILIKE ?";
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
        return $this->withConn($database, static function (PDO $pdo) use ($table): array {
            $st = $pdo->prepare(
                'SELECT kcu.column_name
                 FROM information_schema.table_constraints tc
                 JOIN information_schema.key_column_usage kcu ON tc.constraint_name = kcu.constraint_name
                 WHERE tc.table_schema = \'public\' AND tc.table_name = ? AND tc.constraint_type = \'PRIMARY KEY\'
                 ORDER BY kcu.ordinal_position'
            );
            $st->execute([$table]);
            return array_map(static fn(array $r): string => (string)$r['column_name'], $st->fetchAll());
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
            $setKeys = array_keys($data);
            $pkKeys = array_keys($pk);
            $setClause = implode(', ', array_map(fn(string $k) => $this->quoteIdent($k) . ' = ?', $setKeys));
            $whereClause = implode(' AND ', array_map(fn(string $k) => $this->quoteIdent($k) . ' = ?', $pkKeys));
            $st = $pdo->prepare("UPDATE {$this->quoteIdent($table)} SET {$setClause} WHERE {$whereClause}");
            $st->execute([...array_values($data), ...array_values($pk)]);
        });
    }

    /** @param array<string,mixed> $pk */
    public function deleteRow(string $database, string $table, array $pk): void
    {
        $this->withConn($database, function (PDO $pdo) use ($table, $pk): void {
            $pkKeys = array_keys($pk);
            $whereClause = implode(' AND ', array_map(
                static fn(string $k): string => '"' . str_replace('"', '""', $k) . '" = ?',
                $pkKeys
            ));
            $st = $pdo->prepare('DELETE FROM "' . str_replace('"', '""', $table) . "\" WHERE {$whereClause}");
            $st->execute(array_values($pk));
        });
    }

    public function explain(string $sql, ?string $database = null, bool $analyze = false): mixed
    {
        return $this->withConn($database, static function (PDO $pdo) use ($sql, $analyze): mixed {
            $prefix = $analyze ? 'EXPLAIN (ANALYZE, FORMAT JSON)' : 'EXPLAIN (FORMAT JSON)';
            $row = $pdo->query("{$prefix} {$sql}")->fetch();
            return $row['QUERY PLAN'] ?? $row;
        });
    }

    /** @return list<array<string,mixed>> */
    public function getProcessList(): array
    {
        return $this->withConn(null, static function (PDO $pdo): array {
            return $pdo->query(
                'SELECT pid, usename, datname, state, query, query_start, wait_event_type, wait_event
                 FROM pg_stat_activity WHERE pid <> pg_backend_pid()
                 ORDER BY query_start DESC NULLS LAST'
            )->fetchAll();
        });
    }

    public function killProcess(string|int $id): void
    {
        $this->withConn(null, static function (PDO $pdo) use ($id): void {
            $st = $pdo->prepare('SELECT pg_terminate_backend(?)');
            $st->execute([(int)$id]);
        });
    }

    /** @return list<array<string,mixed>> */
    public function listEngineUsers(): array
    {
        return $this->withConn(null, static function (PDO $pdo): array {
            return $pdo->query(
                'SELECT rolname AS username, rolsuper AS is_super, rolcreatedb AS can_create_db, rolcanlogin AS can_login
                 FROM pg_roles ORDER BY rolname'
            )->fetchAll();
        });
    }

    public function createEngineUser(string $username, string $password, string $host = '%'): void
    {
        $this->withConn(null, function (PDO $pdo) use ($username, $password): void {
            $userEsc = $this->quoteIdent($username);
            $passEsc = str_replace("'", "''", $password);
            $pdo->exec("CREATE USER {$userEsc} WITH PASSWORD '{$passEsc}'");
        });
    }

    public function grantPrivileges(string $username, string $database, string $privileges, string $host = '%'): void
    {
        $this->withConn(null, function (PDO $pdo) use ($username, $database, $privileges): void {
            $pdo->exec(
                "GRANT {$privileges} ON DATABASE {$this->quoteIdent($database)} TO {$this->quoteIdent($username)}"
            );
        });
    }

    public function revokePrivileges(string $username, string $database, string $privileges, string $host = '%'): void
    {
        $this->withConn(null, function (PDO $pdo) use ($username, $database, $privileges): void {
            $pdo->exec(
                "REVOKE {$privileges} ON DATABASE {$this->quoteIdent($database)} FROM {$this->quoteIdent($username)}"
            );
        });
    }

    public function dropEngineUser(string $username, string $host = '%'): void
    {
        $this->withConn(null, function (PDO $pdo) use ($username): void {
            $pdo->exec('DROP USER ' . $this->quoteIdent($username));
        });
    }

    /** @return array<string,mixed> */
    public function getServerInfo(): array
    {
        return $this->withConn(null, static function (PDO $pdo): array {
            $vSt = $pdo->prepare('SELECT version() AS version, current_setting(?) AS port, inet_server_addr() AS host');
            $vSt->execute(['port']);
            $vRow = $vSt->fetch();
            $stats = $pdo->query(
                'SELECT datname, numbackends, xact_commit, xact_rollback, blks_read, blks_hit
                 FROM pg_stat_database WHERE datname IS NOT NULL ORDER BY numbackends DESC LIMIT 10'
            )->fetchAll();
            $settings = $pdo->query(
                "SELECT name, setting FROM pg_settings
                 WHERE name IN ('max_connections','shared_buffers','work_mem','effective_cache_size','timezone')"
            )->fetchAll();
            return [
                'engine' => 'PostgreSQL',
                'version' => $vRow['version'] ?? '',
                'port' => $vRow['port'] ?? null,
                'host' => $vRow['host'] ?? null,
                'status' => $stats,
                'variables' => $settings,
            ];
        });
    }

    public function executeDDL(string $ddl, ?string $database = null): void
    {
        $this->withConn($database, static function (PDO $pdo) use ($ddl): void {
            $pdo->exec($ddl);
        });
    }

    /** @return list<array<string,mixed>> */
    public function getForeignKeys(string $database): array
    {
        return $this->withConn($database, static function (PDO $pdo): array {
            $rows = $pdo->query(
                'SELECT tc.constraint_name, kcu.table_name AS from_table, kcu.column_name AS from_column,
                        ccu.table_name AS to_table, ccu.column_name AS to_column
                 FROM information_schema.table_constraints tc
                 JOIN information_schema.key_column_usage kcu ON tc.constraint_name = kcu.constraint_name
                 JOIN information_schema.constraint_column_usage ccu ON tc.constraint_name = ccu.constraint_name
                 WHERE tc.constraint_type = \'FOREIGN KEY\' AND tc.table_schema = \'public\''
            )->fetchAll();
            $out = [];
            foreach ($rows as $r) {
                $out[] = [
                    'constraintName' => (string)$r['constraint_name'],
                    'fromTable' => (string)$r['from_table'],
                    'fromColumn' => (string)$r['from_column'],
                    'toTable' => (string)$r['to_table'],
                    'toColumn' => (string)$r['to_column'],
                ];
            }
            return $out;
        });
    }

    public function createDatabase(string $name, string $charset = 'utf8mb4'): void
    {
        $this->withConn(null, function (PDO $pdo) use ($name): void {
            $pdo->exec('CREATE DATABASE ' . $this->quoteIdent($name));
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
                'ALTER TABLE ' . $this->quoteIdent($oldName) . ' RENAME TO ' . $this->quoteIdent($newName)
            );
        });
    }

    /** @return array{jobs: list<array<string,mixed>>, pg_cron_missing: bool} */
    public function getDbJobs(): array
    {
        return $this->withConn(null, function (PDO $pdo): array {
            try {
                $rows = $pdo->query(
                    'SELECT jobid, jobname, schedule, command, nodename, nodeport, database, username, active, jobclass
                     FROM cron.job ORDER BY jobid'
                )->fetchAll(PDO::FETCH_ASSOC);
            } catch (\Throwable) {
                return ['jobs' => [], 'pg_cron_missing' => true];
            }
            $jobs = [];
            foreach ($rows as $r) {
                $jobId = (int)($r['jobid'] ?? 0);
                $jobs[] = [
                    'jobId' => \Navicat\Support\DbJobId::encodePg($jobId),
                    'name' => (string)($r['jobname'] ?? ''),
                    'database' => (string)($r['database'] ?? ''),
                    'engine' => 'postgres',
                    'jobType' => 'pg_cron',
                    'status' => !empty($r['active']) ? 'ENABLED' : 'DISABLED',
                    'enabled' => !empty($r['active']),
                    'schedule' => (string)($r['schedule'] ?? ''),
                    'definition' => (string)($r['command'] ?? ''),
                    'username' => (string)($r['username'] ?? ''),
                ];
            }
            return ['jobs' => $jobs, 'pg_cron_missing' => false];
        });
    }

    /** @return list<array<string,mixed>> */
    public function getDbJobHistory(int $jobId, int $limit = 100): array
    {
        return $this->withConn(null, function (PDO $pdo) use ($jobId, $limit): array {
            try {
                $st = $pdo->prepare(
                    'SELECT jobid, runid, job_pid, database, username, command, status, return_message,
                            start_time, end_time,
                            ROUND(EXTRACT(EPOCH FROM (end_time - start_time))::numeric, 3) AS duration_sec
                     FROM cron.job_run_details WHERE jobid = ? ORDER BY start_time DESC LIMIT ?'
                );
                $st->execute([$jobId, $limit]);
                return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
            } catch (\Throwable) {
                return [];
            }
        });
    }

    public function enableDbJob(int $jobId): void
    {
        $this->withConn(null, static function (PDO $pdo) use ($jobId): void {
            $st = $pdo->prepare('UPDATE cron.job SET active = true WHERE jobid = ?');
            $st->execute([$jobId]);
        });
    }

    public function disableDbJob(int $jobId): void
    {
        $this->withConn(null, static function (PDO $pdo) use ($jobId): void {
            $st = $pdo->prepare('UPDATE cron.job SET active = false WHERE jobid = ?');
            $st->execute([$jobId]);
        });
    }

    public function dropDbJob(int $jobId): void
    {
        $this->withConn(null, static function (PDO $pdo) use ($jobId): void {
            $st = $pdo->prepare('SELECT cron.unschedule(?)');
            $st->execute([$jobId]);
        });
    }

    public function checkQueryStoreAvailable(): bool
    {
        return $this->withConn(null, static function (PDO $pdo): bool {
            try {
                $n = (int)$pdo->query(
                    "SELECT COUNT(*) FROM pg_extension WHERE extname = 'pg_stat_statements'"
                )->fetchColumn();
                return $n > 0;
            } catch (\Throwable) {
                return false;
            }
        });
    }

    /** @return list<array<string,mixed>> */
    public function getTopQueries(int $limit = 25): array
    {
        return $this->withConn(null, static function (PDO $pdo) use ($limit): array {
            try {
                $st = $pdo->prepare(
                    'SELECT d.datname AS database, s.queryid,
                            LEFT(s.query, 500) AS query, s.calls,
                            ROUND(s.total_exec_time::numeric, 3) AS total_ms,
                            ROUND(s.mean_exec_time::numeric, 3) AS mean_ms,
                            s.rows, s.shared_blks_hit, s.shared_blks_read
                     FROM pg_stat_statements s
                     JOIN pg_database d ON d.oid = s.dbid
                     ORDER BY s.total_exec_time DESC NULLS LAST LIMIT ?'
                );
                $st->bindValue(1, $limit, PDO::PARAM_INT);
                $st->execute();
                return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
            } catch (\Throwable) {
                return [];
            }
        });
    }

    public function resetQueryStats(): void
    {
        $this->withConn(null, static function (PDO $pdo): void {
            try {
                $pdo->query('SELECT pg_stat_statements_reset()');
            } catch (\Throwable) {
            }
        });
    }

    /** @return array<string,mixed> */
    public function getLogConfig(): array
    {
        return $this->withConn(null, static function (PDO $pdo): array {
            $vars = [
                'log_directory', 'log_filename', 'log_statement',
                'log_min_duration_statement', 'log_connections', 'log_disconnections',
                'logging_collector', 'log_rotation_age', 'log_rotation_size',
            ];
            $placeholders = implode(',', array_fill(0, count($vars), '?'));
            $st = $pdo->prepare("SELECT name, setting FROM pg_settings WHERE name IN ({$placeholders})");
            $st->execute($vars);
            $out = [];
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $out[(string)$r['name']] = $r['setting'];
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
                    'SELECT d.datname AS database, s.queryid,
                            LEFT(s.query, 500) AS query, s.calls,
                            ROUND(s.total_exec_time::numeric, 3) AS total_ms,
                            ROUND(s.mean_exec_time::numeric, 3) AS mean_ms,
                            s.rows
                     FROM pg_stat_statements s
                     JOIN pg_database d ON d.oid = s.dbid
                     WHERE s.mean_exec_time > 0
                     ORDER BY s.total_exec_time DESC NULLS LAST LIMIT ?'
                );
                $st->bindValue(1, $limit, PDO::PARAM_INT);
                $st->execute();
                return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
            } catch (\Throwable) {
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
                    'SELECT d.datname AS database, s.queryid,
                            LEFT(s.query, 500) AS query, s.calls,
                            ROUND(s.total_exec_time::numeric, 3) AS total_ms
                     FROM pg_stat_statements s
                     JOIN pg_database d ON d.oid = s.dbid
                     ORDER BY s.calls DESC LIMIT ?'
                );
                $st->bindValue(1, $limit, PDO::PARAM_INT);
                $st->execute();
                return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
            } catch (\Throwable) {
                return [];
            }
        });
    }

    /** @return list<array<string,mixed>> */
    public function getBinaryLogs(): array
    {
        return $this->withConn(null, static function (PDO $pdo): array {
            try {
                $st = $pdo->query(
                    "SELECT name, setting FROM pg_settings WHERE name IN ('wal_level','archive_mode','archive_command')"
                );
                return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
            } catch (\Throwable) {
                return [];
            }
        });
    }

    private function quoteIdent(string $value): string
    {
        return '"' . str_replace('"', '""', $value) . '"';
    }

    /** @return list<string> */
    private function pgArrayToList(mixed $value): array
    {
        if (is_array($value)) {
            return array_map('strval', $value);
        }
        $s = trim((string)$value, '{}');
        if ($s === '') {
            return [];
        }
        return array_map('trim', explode(',', $s));
    }
}
