<?php
declare(strict_types=1);

namespace Navicat\Drivers;

use Navicat\Mongo\Binary;
use Navicat\Mongo\Bson;
use Navicat\Mongo\Int64;
use Navicat\Mongo\MongoClient;
use Navicat\Mongo\ObjectId;
use Navicat\Mongo\Regex;
use Navicat\Mongo\UTCDateTime;

/**
 * MongoDB / Amazon DocumentDB driver implemented on top of the pure-PHP
 * MongoClient. Exposes the same method surface as MySqlDriver / PostgresDriver
 * so Router and the services treat it uniformly.
 *
 * Conceptual mapping (Mongo is NoSQL — no relational schema):
 *   database  -> database
 *   table     -> collection
 *   row       -> document (flattened with dotted keys for the grid)
 *   primary key -> _id
 * Relational-only concepts (views/routines/triggers/foreign keys/DDL/grants)
 * degrade gracefully: they return empty results or throw a clear 400.
 */
final class MongoDriver
{
    private const SYSTEM_DBS = ['admin', 'local', 'config'];
    private const SCHEMA_SAMPLE = 200;

    /** @var array<string,mixed> */
    private array $creds;
    private ?MongoClient $client = null;

    /** @param array<string,mixed> $creds */
    public function __construct(array $creds)
    {
        $this->creds = $creds;
    }

    private function client(): MongoClient
    {
        if ($this->client === null) {
            $meta = is_array($this->creds['meta'] ?? null) ? $this->creds['meta'] : [];
            $this->client = new MongoClient([
                'host' => (string)$this->creds['host'],
                'port' => (int)($this->creds['port'] ?? 27017),
                'username' => (string)$this->creds['username'],
                'password' => (string)($this->creds['password'] ?? ''),
                'authDb' => (string)($meta['authDb'] ?? 'admin'),
                'tls' => array_key_exists('tls', $meta) ? (bool)$meta['tls'] : true,
                'caFile' => $this->resolveCaFile($meta['caFile'] ?? null),
                'tlsAllowInvalid' => !empty($meta['tlsAllowInvalid']),
                'authMechanism' => (string)($meta['authMechanism'] ?? 'auto'),
                'appName' => 'navicat-php',
            ]);
            $this->client->connect();
        }
        return $this->client;
    }

    private function resolveCaFile(mixed $caFile): ?string
    {
        if (!is_string($caFile) || $caFile === '') {
            return null;
        }
        if (is_file($caFile)) {
            return $caFile;
        }
        // Allow a bare filename relative to the user's home (e.g. global-bundle.pem).
        $home = getenv('HOME') ?: '';
        if ($home !== '' && is_file($home . '/' . $caFile)) {
            return $home . '/' . $caFile;
        }
        return $caFile;
    }

    // ---- connection / databases -------------------------------------------

    public function testConnection(): bool
    {
        $this->client()->runCommand('admin', ['ping' => 1]);
        return true;
    }

    /** @return list<string> */
    public function listDatabases(): array
    {
        $reply = $this->client()->runCommand('admin', ['listDatabases' => 1, 'nameOnly' => true]);
        $out = [];
        foreach ($reply['databases'] ?? [] as $d) {
            $name = (string)($d['name'] ?? '');
            if ($name !== '' && !in_array($name, self::SYSTEM_DBS, true)) {
                $out[] = $name;
            }
        }
        return $out;
    }

    // ---- collections (= tables) -------------------------------------------

    /** @return list<array<string,mixed>> */
    public function listTablesLight(string $database): array
    {
        // DocumentDB rejects the `type` filter on listCollections, so filter
        // client-side instead of server-side.
        $colls = $this->client()->cursorAll($database, ['listCollections' => 1]);
        $out = [];
        foreach ($colls as $c) {
            $name = (string)($c['name'] ?? '');
            $type = (string)($c['type'] ?? 'collection');
            if ($name === '' || str_starts_with($name, 'system.') || $type === 'view') {
                continue;
            }
            $rows = null;
            $size = null;
            try {
                $stats = $this->client()->runCommand($database, ['collStats' => $name]);
                $rows = isset($stats['count']) ? (int)$this->unwrapScalar($stats['count']) : null;
                $size = isset($stats['size']) ? (int)$this->unwrapScalar($stats['size']) : null;
            } catch (\Throwable) {
                // collStats may be unavailable for some DocumentDB collections.
            }
            $out[] = [
                'name' => $name,
                'type' => 'table',
                'engine' => 'mongodb',
                'rowsEstimate' => $rows,
                'sizeBytes' => $size,
                'collation' => null,
                'comment' => null,
                'updatedAt' => null,
            ];
        }
        usort($out, static fn($a, $b) => strcmp($a['name'], $b['name']));
        return $out;
    }

    /** Mongo views exist but are uncommon; list them when present. @return list<array<string,mixed>> */
    public function listViews(string $database): array
    {
        try {
            $colls = $this->client()->cursorAll($database, ['listCollections' => 1]);
        } catch (\Throwable) {
            return [];
        }
        $out = [];
        foreach ($colls as $c) {
            if ((string)($c['type'] ?? '') === 'view') {
                $out[] = ['name' => (string)($c['name'] ?? ''), 'type' => 'view'];
            }
        }
        return $out;
    }

    /** No stored routines in Mongo. @return list<array<string,mixed>> */
    public function listRoutines(string $database): array
    {
        return [];
    }

    /** No triggers in Mongo. @return list<array<string,mixed>> */
    public function listTriggers(string $database): array
    {
        return [];
    }

    public function getRoutineSource(string $database, string $name): string
    {
        throw new \RuntimeException('MongoDB has no stored routines', 400);
    }

    public function getViewDefinition(string $database, string $view): string
    {
        $colls = $this->client()->cursorAll($database, [
            'listCollections' => 1,
            'filter' => ['name' => $view],
        ]);
        $opts = $colls[0]['options'] ?? [];
        return json_encode($this->toPlain($opts), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }

    public function getObjectDdl(string $database, string $type, string $name): string
    {
        $t = strtolower($type);
        if ($t === 'view') {
            return $this->getViewDefinition($database, $name);
        }
        if ($t === 'table') {
            // No DDL in Mongo: return the inferred schema + indexes as a readable summary.
            $info = $this->getTableInfo($database, $name);
            return "// MongoDB collection: {$name}\n"
                . "// (schema inferred by sampling documents)\n"
                . json_encode($this->toPlain($info), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        }
        throw new \RuntimeException('Object type not applicable to MongoDB: ' . $type, 400);
    }

    /** MongoDB has no DDL to clone across databases in this form. */
    public function cloneObject(string $sourceDb, string $type, string $name, string $targetDb, string $newName, bool $copyData = false): array
    {
        throw new \RuntimeException('Cloning object definitions is not supported for MongoDB', 400);
    }

    // ---- schema (inferred) -------------------------------------------------

    /** @return array<string,mixed> */
    public function getTableInfo(string $database, string $table): array
    {
        // Sample documents to infer a column set; _id is always the primary key.
        $docs = $this->client()->cursorAll($database, [
            'find' => $table,
            'limit' => self::SCHEMA_SAMPLE,
        ], self::SCHEMA_SAMPLE);

        $fields = [];
        foreach ($docs as $doc) {
            foreach ($this->flatten($this->toPlain($doc)) as $k => $v) {
                if (!isset($fields[$k])) {
                    $fields[$k] = ['types' => [], 'present' => 0];
                }
                $fields[$k]['types'][$this->jsType($v)] = true;
                $fields[$k]['present']++;
            }
        }

        $columns = [];
        $pos = 1;
        // Ensure _id first.
        if (!isset($fields['_id'])) {
            $fields = ['_id' => ['types' => ['objectId' => true], 'present' => count($docs)]] + $fields;
        }
        foreach ($fields as $name => $meta) {
            $types = array_keys($meta['types']);
            $columns[] = [
                'name' => $name,
                'type' => implode('|', $types ?: ['mixed']),
                'nullable' => $meta['present'] < count($docs),
                'defaultValue' => null,
                'isPrimaryKey' => $name === '_id',
                'isForeignKey' => false,
                'referencedTable' => null,
                'referencedColumn' => null,
                'comment' => '',
                'autoIncrement' => false,
                'length' => null,
                'ordinalPosition' => $pos++,
            ];
        }

        // Indexes via listIndexes.
        $indexes = [];
        try {
            $idx = $this->client()->cursorAll($database, ['listIndexes' => $table]);
            foreach ($idx as $i) {
                $keys = array_keys($this->toPlain($i['key'] ?? []));
                $indexes[] = [
                    'name' => (string)($i['name'] ?? ''),
                    'columns' => $keys,
                    'unique' => !empty($i['unique']),
                    'primary' => ($i['name'] ?? '') === '_id_',
                ];
            }
        } catch (\Throwable) {
        }

        return [
            'name' => $table,
            'type' => 'table',
            'columns' => $columns,
            'indexes' => $indexes,
            'foreignKeys' => [],
            'checks' => [],
        ];
    }

    /** @return list<string> */
    public function getPrimaryKeys(string $database, string $table): array
    {
        return ['_id'];
    }

    // ---- data grid ---------------------------------------------------------

    /**
     * @param array<string,mixed> $options
     * @return array<string,mixed>
     */
    public function queryPaginated(string $database, string $table, array $options): array
    {
        $start = microtime(true);
        $limit = max(1, min(10000, (int)($options['limit'] ?? 100)));
        $offset = max(0, (int)($options['offset'] ?? 0));

        $filter = $this->buildFilter($options);
        $sort = $this->buildSort($options);

        $cmd = [
            'find' => $table,
            'filter' => $filter ?: new \stdClass(),
            'skip' => $offset,
            'limit' => $limit,
        ];
        if ($sort !== []) {
            $cmd['sort'] = $sort;
        }
        $reply = $this->client()->runCommand($database, $cmd);
        $batch = array_values($reply['cursor']['firstBatch'] ?? []);
        // Drain extra batches if the server chunked the result.
        $cursorId = $this->unwrapScalar($reply['cursor']['id'] ?? 0);
        while ($cursorId != 0 && count($batch) < $limit) {
            $more = $this->client()->runCommand($database, [
                'getMore' => new Int64((int)$cursorId),
                'collection' => $table,
                'batchSize' => 1000,
            ]);
            $next = array_values($more['cursor']['nextBatch'] ?? []);
            $batch = array_merge($batch, $next);
            $cursorId = $this->unwrapScalar($more['cursor']['id'] ?? 0);
            if ($next === []) {
                break;
            }
        }
        $batch = array_slice($batch, 0, $limit);

        $total = $this->countDocuments($database, $table, $filter);
        [$columns, $rows] = $this->documentsToGrid($batch);

        return [
            'columns' => $columns,
            'rows' => $rows,
            'rowCount' => count($rows),
            'total' => $total,
            'executionTimeMs' => (int)round((microtime(true) - $start) * 1000),
        ];
    }

    private function countDocuments(string $database, string $table, array $filter): int
    {
        try {
            $reply = $this->client()->runCommand($database, [
                'count' => $table,
                'query' => $filter ?: new \stdClass(),
            ]);
            return (int)$this->unwrapScalar($reply['n'] ?? 0);
        } catch (\Throwable) {
            return 0;
        }
    }

    // ---- query console -----------------------------------------------------

    /**
     * Accepts either a Mongo shell-style expression (db.coll.find({...}),
     * db.coll.aggregate([...])) or a raw command document as JSON.
     *
     * @return array<string,mixed>
     */
    public function execute(string $sql, ?string $database = null): array
    {
        $start = microtime(true);
        $db = $database ?: $this->defaultDb();
        $parsed = $this->parseShell($sql);

        if ($parsed === null) {
            // Treat as a raw runCommand JSON document.
            $cmd = json_decode($sql, true);
            if (!is_array($cmd)) {
                throw new \RuntimeException('Mongo query must be db.collection.method(...) or a JSON command', 400);
            }
            $reply = $this->client()->runCommand($db, $this->jsonToBson($cmd));
            return $this->commandResultToGrid($reply, $start);
        }

        [$coll, $method, $args] = $parsed;
        $db = $parsed[3] ?? $db;

        switch ($method) {
            case 'find':
                $filter = $args[0] ?? [];
                $proj = $args[1] ?? null;
                $cmd = ['find' => $coll, 'filter' => $filter ?: new \stdClass(), 'limit' => 1000];
                if (is_array($proj) && $proj !== []) {
                    $cmd['projection'] = $proj;
                }
                $docs = $this->client()->cursorAll($db, $cmd, 1000);
                return $this->docsToGridResult($docs, $start);
            case 'findOne':
                $cmd = ['find' => $coll, 'filter' => ($args[0] ?? []) ?: new \stdClass(), 'limit' => 1];
                $docs = $this->client()->cursorAll($db, $cmd, 1);
                return $this->docsToGridResult($docs, $start);
            case 'aggregate':
                $pipeline = $args[0] ?? [];
                $reply = $this->client()->runCommand($db, [
                    'aggregate' => $coll,
                    'pipeline' => $pipeline,
                    'cursor' => new \stdClass(),
                ]);
                $docs = $this->client()->cursorAll($db, [
                    'aggregate' => $coll,
                    'pipeline' => $pipeline,
                    'cursor' => ['batchSize' => 1000],
                ], 10000);
                return $this->docsToGridResult($docs, $start);
            case 'countDocuments':
            case 'count':
                $reply = $this->client()->runCommand($db, ['count' => $coll, 'query' => ($args[0] ?? []) ?: new \stdClass()]);
                return $this->scalarResult('count', (int)$this->unwrapScalar($reply['n'] ?? 0), $start);
            case 'distinct':
                $reply = $this->client()->runCommand($db, [
                    'distinct' => $coll,
                    'key' => (string)($args[0] ?? '_id'),
                    'query' => ($args[1] ?? []) ?: new \stdClass(),
                ]);
                $vals = array_map([$this, 'toPlain'], $reply['values'] ?? []);
                return $this->docsToGridResult(array_map(static fn($v) => ['value' => $v], $vals), $start);
            case 'insertOne':
                $doc = $args[0] ?? [];
                $this->client()->runCommand($db, ['insert' => $coll, 'documents' => [$doc]]);
                return $this->affectedResult(1, 'inserted', $start);
            case 'updateOne':
            case 'updateMany':
                $reply = $this->client()->runCommand($db, ['update' => $coll, 'updates' => [[
                    'q' => $args[0] ?? [],
                    'u' => $args[1] ?? [],
                    'multi' => $method === 'updateMany',
                ]]]);
                return $this->affectedResult((int)$this->unwrapScalar($reply['nModified'] ?? 0), 'modified', $start);
            case 'deleteOne':
            case 'deleteMany':
                $reply = $this->client()->runCommand($db, ['delete' => $coll, 'deletes' => [[
                    'q' => $args[0] ?? [],
                    'limit' => $method === 'deleteOne' ? 1 : 0,
                ]]]);
                return $this->affectedResult((int)$this->unwrapScalar($reply['n'] ?? 0), 'deleted', $start);
            default:
                throw new \RuntimeException("Unsupported Mongo method: {$method}", 400);
        }
    }

    /** @return array<string,mixed> */
    public function executeMany(string $sql, ?string $database = null): array
    {
        $start = microtime(true);
        $result = $this->execute($sql, $database);
        return [
            'statements' => [$result],
            'totalTimeMs' => (int)round((microtime(true) - $start) * 1000),
        ];
    }

    // ---- CRUD on documents -------------------------------------------------

    /** @param array<string,mixed> $data */
    public function insertRow(string $database, string $table, array $data): void
    {
        $doc = $this->coerceForWrite($data);
        $this->client()->runCommand($database, ['insert' => $table, 'documents' => [$doc]]);
    }

    /** @param array<string,mixed> $pk @param array<string,mixed> $data */
    public function updateRow(string $database, string $table, array $pk, array $data): void
    {
        $q = $this->coerceFilter($pk);
        $set = $this->coerceForWrite($data);
        unset($set['_id']); // never modify the immutable _id
        $this->client()->runCommand($database, ['update' => $table, 'updates' => [[
            'q' => $q,
            'u' => ['$set' => $set],
            'multi' => false,
        ]]]);
    }

    /** @param array<string,mixed> $pk */
    public function deleteRow(string $database, string $table, array $pk): void
    {
        $q = $this->coerceFilter($pk);
        $this->client()->runCommand($database, ['delete' => $table, 'deletes' => [['q' => $q, 'limit' => 1]]]);
    }

    // ---- explain / processes ----------------------------------------------

    public function explain(string $sql, ?string $database = null, bool $analyze = false): mixed
    {
        $db = $database ?: $this->defaultDb();
        $parsed = $this->parseShell($sql);
        if ($parsed === null) {
            throw new \RuntimeException('explain requires db.collection.find/aggregate(...)', 400);
        }
        [$coll, $method, $args] = $parsed;
        $inner = $method === 'aggregate'
            ? ['aggregate' => $coll, 'pipeline' => $args[0] ?? [], 'cursor' => new \stdClass()]
            : ['find' => $coll, 'filter' => ($args[0] ?? []) ?: new \stdClass()];
        $reply = $this->client()->runCommand($db, [
            'explain' => $inner,
            'verbosity' => $analyze ? 'executionStats' : 'queryPlanner',
        ]);
        return $this->toPlain($reply);
    }

    /** @return list<array<string,mixed>> */
    public function getProcessList(): array
    {
        try {
            $reply = $this->client()->runCommand('admin', ['currentOp' => 1]);
            $ops = $reply['inprog'] ?? [];
            $out = [];
            foreach ($ops as $op) {
                $op = $this->toPlain($op);
                $out[] = [
                    'opid' => $op['opid'] ?? null,
                    'ns' => $op['ns'] ?? null,
                    'op' => $op['op'] ?? null,
                    'client' => $op['client'] ?? null,
                    'secs_running' => $op['secs_running'] ?? null,
                    'desc' => $op['desc'] ?? null,
                ];
            }
            return $out;
        } catch (\Throwable $e) {
            return [];
        }
    }

    public function killProcess(string|int $id): void
    {
        $this->client()->runCommand('admin', ['killOp' => 1, 'op' => (int)$id]);
    }

    // ---- users -------------------------------------------------------------

    /** @return list<array<string,mixed>> */
    public function listEngineUsers(): array
    {
        try {
            $reply = $this->client()->runCommand('admin', ['usersInfo' => 1]);
            $out = [];
            foreach ($reply['users'] ?? [] as $u) {
                $u = $this->toPlain($u);
                $roles = [];
                foreach ($u['roles'] ?? [] as $r) {
                    $roles[] = ($r['role'] ?? '') . '@' . ($r['db'] ?? '');
                }
                $out[] = [
                    'User' => $u['user'] ?? '',
                    'Host' => $u['db'] ?? '',
                    'roles' => implode(', ', $roles),
                ];
            }
            return $out;
        } catch (\Throwable) {
            return [];
        }
    }

    public function createEngineUser(string $username, string $password, string $host = '%'): void
    {
        $this->client()->runCommand('admin', [
            'createUser' => $username,
            'pwd' => $password,
            'roles' => [['role' => 'readWrite', 'db' => $username]],
        ]);
    }

    public function grantPrivileges(string $username, string $database, string $privileges, string $host = '%'): void
    {
        $role = $this->mapRole($privileges);
        $this->client()->runCommand('admin', [
            'grantRolesToUser' => $username,
            'roles' => [['role' => $role, 'db' => $database]],
        ]);
    }

    public function revokePrivileges(string $username, string $database, string $privileges, string $host = '%'): void
    {
        $role = $this->mapRole($privileges);
        $this->client()->runCommand('admin', [
            'revokeRolesFromUser' => $username,
            'roles' => [['role' => $role, 'db' => $database]],
        ]);
    }

    public function dropEngineUser(string $username, string $host = '%'): void
    {
        $this->client()->runCommand('admin', ['dropUser' => $username]);
    }

    private function mapRole(string $privileges): string
    {
        $p = strtoupper($privileges);
        if (str_contains($p, 'ALL')) {
            return 'dbOwner';
        }
        if (str_contains($p, 'SELECT') && !str_contains($p, 'INSERT') && !str_contains($p, 'UPDATE')) {
            return 'read';
        }
        return 'readWrite';
    }

    // ---- server info -------------------------------------------------------

    /** @return array<string,mixed> */
    public function getServerInfo(): array
    {
        $build = $this->toPlain($this->client()->runCommand('admin', ['buildInfo' => 1]));
        $status = [];
        try {
            $status = $this->toPlain($this->client()->runCommand('admin', ['serverStatus' => 1]));
        } catch (\Throwable) {
        }
        $hello = $this->toPlain($this->client()->helloInfo());

        $statusRows = [];
        foreach ([
            'Uptime' => $status['uptime'] ?? null,
            'Connections_current' => $status['connections']['current'] ?? null,
            'Connections_available' => $status['connections']['available'] ?? null,
            'Network_bytesIn' => $status['network']['bytesIn'] ?? null,
            'Network_bytesOut' => $status['network']['bytesOut'] ?? null,
        ] as $k => $v) {
            if ($v !== null) {
                $statusRows[] = ['Variable_name' => $k, 'Value' => $v];
            }
        }

        return [
            'engine' => 'MongoDB',
            'version' => $build['version'] ?? ($status['version'] ?? ''),
            'hostname' => $status['host'] ?? null,
            'port' => (int)($this->creds['port'] ?? 27017),
            'datadir' => null,
            'status' => $statusRows,
            'variables' => [
                ['Variable_name' => 'maxWireVersion', 'Value' => $hello['maxWireVersion'] ?? null],
                ['Variable_name' => 'storageEngine', 'Value' => $status['storageEngine']['name'] ?? 'n/a'],
                ['Variable_name' => 'process', 'Value' => $status['process'] ?? null],
            ],
        ];
    }

    // ---- DDL-ish operations -----------------------------------------------

    public function executeDDL(string $ddl, ?string $database = null): void
    {
        // Strip comment-only lines (the designer emits `// ...` for schemaless ops).
        $lines = array_filter(
            array_map('trim', explode("\n", $ddl)),
            static fn(string $l): bool => $l !== '' && !str_starts_with($l, '//')
        );
        $payload = trim(implode("\n", $lines));
        if ($payload === '') {
            return; // nothing actionable (schemaless no-op)
        }
        // A create-collection JSON command, a Mongo command doc, or shell syntax.
        $decoded = json_decode($payload, true);
        if (is_array($decoded)) {
            $db = $database ?: $this->defaultDb();
            $this->client()->runCommand($db, $this->jsonToBson($decoded));
            return;
        }
        $this->execute($payload, $database);
    }

    /** @return list<array<string,mixed>> */
    public function getForeignKeys(string $database): array
    {
        return [];
    }

    public function createDatabase(string $name, string $charset = 'utf8mb4'): void
    {
        // Mongo creates databases lazily; materialize with a placeholder collection.
        $this->client()->runCommand($name, ['create' => '_navicat_init']);
    }

    public function dropDatabase(string $name): void
    {
        $this->client()->runCommand($name, ['dropDatabase' => 1]);
    }

    public function truncateTable(string $database, string $table): void
    {
        $this->client()->runCommand($database, ['delete' => $table, 'deletes' => [['q' => new \stdClass(), 'limit' => 0]]]);
    }

    public function dropTable(string $database, string $table): void
    {
        try {
            $this->client()->runCommand($database, ['drop' => $table]);
        } catch (\RuntimeException $e) {
            // Error 26 = ns not found — collection didn't exist, not an error.
            if (!str_contains($e->getMessage(), '[26]') && !str_contains($e->getMessage(), 'ns not found')) {
                throw $e;
            }
        }
    }

    public function dropView(string $database, string $view): void
    {
        $this->client()->runCommand($database, ['drop' => $view]);
    }

    public function renameTable(string $database, string $oldName, string $newName): void
    {
        // renameCollection lives on admin and needs full namespaces.
        $this->client()->runCommand('admin', [
            'renameCollection' => "{$database}.{$oldName}",
            'to' => "{$database}.{$newName}",
            'dropTarget' => false,
        ]);
    }

    // ---- backup / restore support ----------------------------------------

    /**
     * Stream every document of a collection to $emit as an extended-JSON string
     * (one document per call). Used by BackupService for native Mongo dumps.
     *
     * @param callable(string):void $emit
     */
    public function dumpCollection(string $database, string $collection, callable $emit): int
    {
        $count = 0;
        $cursorId = 0;
        $reply = $this->client()->runCommand($database, [
            'find' => $collection,
            'filter' => new \stdClass(),
            'batchSize' => 1000,
        ]);
        $batch = array_values($reply['cursor']['firstBatch'] ?? []);
        $cursorId = $this->unwrapScalar($reply['cursor']['id'] ?? 0);

        do {
            foreach ($batch as $doc) {
                $emit(json_encode($this->toExtended($doc), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                $count++;
            }
            if ($cursorId == 0) {
                break;
            }
            $more = $this->client()->runCommand($database, [
                'getMore' => new Int64((int)$cursorId),
                'collection' => $collection,
                'batchSize' => 1000,
            ]);
            $batch = array_values($more['cursor']['nextBatch'] ?? []);
            $cursorId = $this->unwrapScalar($more['cursor']['id'] ?? 0);
        } while ($batch !== [] || $cursorId != 0);

        return $count;
    }

    /**
     * Insert a batch of documents (decoded from extended JSON) into a collection.
     *
     * @param list<array<string,mixed>> $docs
     */
    public function insertMany(string $database, string $collection, array $docs): void
    {
        if ($docs === []) {
            return;
        }
        $bson = array_map([$this, 'jsonToBson'], $docs);
        $reply = $this->client()->runCommand($database, [
            'insert' => $collection,
            'documents' => $bson,
            'ordered' => false,
        ]);
        // Surface write errors — with ordered:false they don't throw but are in writeErrors.
        if (!empty($reply['writeErrors'])) {
            $first = $this->toPlain($reply['writeErrors'][0] ?? []);
            $total = count($reply['writeErrors']);
            throw new \RuntimeException(
                "insertMany: {$total} write error(s) — first: code={$first['code']} msg={$first['errmsg']}",
            );
        }
    }

    /** @return list<string> Collection names (no system collections). */
    public function listCollectionNames(string $database): array
    {
        return array_map(static fn($t) => (string)$t['name'], $this->listTablesLight($database));
    }

    // =======================================================================
    // Observabilidad (Sprint 4–5)
    // =======================================================================

    public function checkQueryStoreAvailable(): bool
    {
        try {
            $c = $this->client();
            $dbName = $this->defaultDb();
            $profile = $c->db($dbName)->command(['profile' => -1])->toArray();
            return isset($profile[0]['was']);
        } catch (\Throwable) {
            return false;
        }
    }

    /** @return list<array<string,mixed>> */
    public function getTopQueries(int $limit = 25): array
    {
        try {
            $c = $this->client();
            $dbName = $this->defaultDb();
            $rows = $c->db($dbName)->collection('system.profile')->aggregate([
                ['$group' => [
                    '_id' => ['op' => '$op', 'ns' => '$ns'],
                    'calls' => ['$sum' => 1],
                    'total_ms' => ['$sum' => '$millis'],
                    'avg_ms' => ['$avg' => '$millis'],
                ]],
                ['$sort' => ['total_ms' => -1]],
                ['$limit' => $limit],
            ])->toArray();
            return array_map(function ($r) {
                return [
                    'op' => $r['_id']['op'] ?? null,
                    'ns' => $r['_id']['ns'] ?? null,
                    'calls' => $r['calls'] ?? 0,
                    'total_ms' => $r['total_ms'] ?? 0,
                    'avg_ms' => $r['avg_ms'] ?? 0,
                ];
            }, $this->toPlain($rows));
        } catch (\Throwable) {
            return [];
        }
    }

    public function resetQueryStats(): void
    {
        try {
            $c = $this->client();
            $dbName = $this->defaultDb();
            $c->db($dbName)->command(['profile' => 0]);
        } catch (\Throwable) {
        }
    }

    /** @return array<string,mixed> */
    public function getLogConfig(): array
    {
        try {
            $status = $this->toPlain($this->client()->runCommand('admin', ['serverStatus' => 1]));
            return [
                'logLevel' => $status['logLevel'] ?? null,
                'quiet' => $status['quiet'] ?? null,
                'process' => $status['process'] ?? null,
                'storageEngine' => $status['storageEngine']['name'] ?? null,
            ];
        } catch (\Throwable) {
            return [];
        }
    }

    /** @return list<array<string,mixed>> */
    public function getSlowQueryLog(int $limit = 50): array
    {
        try {
            $c = $this->client();
            $dbName = $this->defaultDb();
            $rows = $c->db($dbName)->collection('system.profile')->find(
                ['millis' => ['$gt' => 0]],
                ['sort' => ['millis' => -1], 'limit' => $limit]
            )->toArray();
            return array_map(function ($r) {
                $r = $this->toPlain($r);
                return [
                    'op' => $r['op'] ?? null,
                    'ns' => $r['ns'] ?? null,
                    'millis' => $r['millis'] ?? 0,
                    'ts' => $r['ts'] ?? null,
                    'client' => $r['client'] ?? null,
                    'command' => json_encode($r['command'] ?? null),
                ];
            }, $rows);
        } catch (\Throwable) {
            return [];
        }
    }

    /** @return list<array<string,mixed>> */
    public function getGeneralLog(int $limit = 100): array
    {
        try {
            $c = $this->client();
            $dbName = $this->defaultDb();
            $rows = $c->db($dbName)->collection('system.profile')->find(
                [],
                ['sort' => ['ts' => -1], 'limit' => $limit]
            )->toArray();
            return array_map(function ($r) {
                $r = $this->toPlain($r);
                return [
                    'op' => $r['op'] ?? null,
                    'ns' => $r['ns'] ?? null,
                    'millis' => $r['millis'] ?? 0,
                    'ts' => $r['ts'] ?? null,
                    'client' => $r['client'] ?? null,
                ];
            }, $rows);
        } catch (\Throwable) {
            return [];
        }
    }

    /** @return list<array<string,mixed>> */
    public function getBinaryLogs(): array
    {
        return [];
    }

    // =======================================================================
    // Helpers
    // =======================================================================

    private function defaultDb(): string
    {
        $d = $this->creds['defaultDb'] ?? null;
        return is_string($d) && $d !== '' ? $d : 'admin';
    }

    /**
     * Parse a Mongo shell expression: db.<coll>.<method>(<json args>)
     * or db.getSiblingDB('x').<coll>.<method>(...).
     *
     * @return array{0:string,1:string,2:list<mixed>,3?:string}|null
     */
    private function parseShell(string $sql): ?array
    {
        $sql = trim($sql);
        $siblingDb = null;
        if (preg_match('/^db\.getSiblingDB\(\s*[\'"]([^\'"]+)[\'"]\s*\)\.(.*)$/s', $sql, $m)) {
            $siblingDb = $m[1];
            $sql = 'db.' . $m[2];
        }
        if (!preg_match('/^db\.([A-Za-z0-9_.$-]+)\.([A-Za-z]+)\((.*)\)\s*;?\s*$/s', $sql, $m)) {
            return null;
        }
        $coll = $m[1];
        $method = $m[2];
        $argStr = trim($m[3]);
        $args = $argStr === '' ? [] : $this->parseArgs($argStr);
        $out = [$coll, $method, $args];
        if ($siblingDb !== null) {
            $out[3] = $siblingDb;
        }
        return $out;
    }

    /** Split top-level comma-separated JSON arguments and decode each. @return list<mixed> */
    private function parseArgs(string $s): array
    {
        $args = [];
        $depth = 0;
        $start = 0;
        $inStr = false;
        $strCh = '';
        $len = strlen($s);
        for ($i = 0; $i < $len; $i++) {
            $ch = $s[$i];
            if ($inStr) {
                if ($ch === '\\') {
                    $i++;
                } elseif ($ch === $strCh) {
                    $inStr = false;
                }
                continue;
            }
            if ($ch === '"' || $ch === "'") {
                $inStr = true;
                $strCh = $ch;
            } elseif ($ch === '{' || $ch === '[' || $ch === '(') {
                $depth++;
            } elseif ($ch === '}' || $ch === ']' || $ch === ')') {
                $depth--;
            } elseif ($ch === ',' && $depth === 0) {
                $args[] = $this->decodeArg(substr($s, $start, $i - $start));
                $start = $i + 1;
            }
        }
        $args[] = $this->decodeArg(substr($s, $start));
        return $args;
    }

    private function decodeArg(string $raw): mixed
    {
        $raw = trim($raw);
        if ($raw === '') {
            return [];
        }
        // Allow single quotes and unquoted keys (relaxed JSON -> strict JSON).
        $json = $this->relaxedToJson($raw);
        $decoded = json_decode($json, true);
        if ($decoded === null && json_last_error() !== JSON_ERROR_NONE) {
            throw new \RuntimeException('Could not parse Mongo argument: ' . $raw, 400);
        }
        return $this->jsonToBson($decoded);
    }

    private function relaxedToJson(string $s): string
    {
        // ObjectId("...") -> {"$oid":"..."}
        $s = preg_replace('/ObjectId\(\s*[\'"]([0-9a-fA-F]{24})[\'"]\s*\)/', '{"$oid":"$1"}', $s);
        // ISODate("...") -> {"$date":"..."}
        $s = preg_replace('/ISODate\(\s*[\'"]([^\'"]+)[\'"]\s*\)/', '{"$date":"$1"}', $s);
        // Single quotes -> double quotes (naive; fine for typical console use).
        $s = preg_replace('/\'([^\']*)\'/', '"$1"', $s);
        // Unquoted object keys -> quoted.
        $s = preg_replace('/([{,]\s*)([A-Za-z_$][A-Za-z0-9_$.]*)\s*:/', '$1"$2":', $s);
        return $s;
    }

    /** Convert extended-JSON markers ($oid/$date/$binary/$regularExpression) into BSON value objects. */
    private function jsonToBson(mixed $v): mixed
    {
        if (is_array($v)) {
            if (isset($v['$oid']) && is_string($v['$oid'])) {
                return new ObjectId($v['$oid']);
            }
            if (isset($v['$date'])) {
                $d = $v['$date'];
                $ms = is_int($d) ? $d : (int)(strtotime((string)$d) * 1000);
                return new UTCDateTime($ms);
            }
            if (isset($v['$binary']['base64'])) {
                return new Binary(
                    (string)base64_decode($v['$binary']['base64']),
                    (int)hexdec($v['$binary']['subType'] ?? '00')
                );
            }
            if (isset($v['$uuid'])) {
                return new Binary((string)hex2bin(str_replace('-', '', (string)$v['$uuid'])), 0x04);
            }
            if (isset($v['$regularExpression']['pattern'])) {
                return new Regex(
                    (string)$v['$regularExpression']['pattern'],
                    (string)($v['$regularExpression']['options'] ?? '')
                );
            }
            $out = [];
            foreach ($v as $k => $item) {
                $out[$k] = $this->jsonToBson($item);
            }
            return $out;
        }
        return $v;
    }

    // ---- filters / sort ----------------------------------------------------

    /** @param array<string,mixed> $options @return array<string,mixed> */
    private function buildFilter(array $options): array
    {
        $filter = [];

        // Raw filter: accept a JSON document.
        $raw = isset($options['filter']) ? trim((string)$options['filter']) : '';
        if ($raw !== '') {
            $decoded = json_decode($this->relaxedToJson($raw), true);
            if (is_array($decoded)) {
                $filter = $this->jsonToBson($decoded);
            }
        }

        // Structured filters from the grid.
        $structured = $options['structuredFilters'] ?? null;
        if (is_array($structured)) {
            $clauses = (isset($structured['column']) && isset($structured['op'])) ? [$structured] : $structured;
            foreach ($clauses as $f) {
                if (!is_array($f) || !isset($f['column'])) {
                    continue;
                }
                $col = (string)$f['column'];
                $op = strtolower(str_replace(['-', ' '], '_', (string)($f['op'] ?? 'eq')));
                $val = $f['value'] ?? null;
                $filter = array_merge($filter, $this->filterClause($col, $op, $val));
            }
        }

        return $filter;
    }

    /** @return array<string,mixed> */
    private function filterClause(string $col, string $op, mixed $val): array
    {
        if ($col === '_id' && is_string($val) && preg_match('/^[0-9a-f]{24}$/i', $val)) {
            $val = new ObjectId($val);
        }
        return match ($op) {
            '=', 'eq' => [$col => $val],
            '!=', '<>', 'ne' => [$col => ['$ne' => $val]],
            '>', 'gt' => [$col => ['$gt' => $val]],
            '>=', 'gte' => [$col => ['$gte' => $val]],
            '<', 'lt' => [$col => ['$lt' => $val]],
            '<=', 'lte' => [$col => ['$lte' => $val]],
            'like', 'ilike' => [$col => new Regex(preg_quote(str_replace('%', '', (string)$val), null), 'i')],
            'is_null', 'isnull' => [$col => null],
            'is_not_null', 'isnotnull' => [$col => ['$ne' => null]],
            default => [$col => $val],
        };
    }

    /** @param array<string,mixed> $options @return array<string,int> */
    private function buildSort(array $options): array
    {
        $sort = [];
        $keys = $options['sortKeys'] ?? null;
        if (is_array($keys) && $keys !== []) {
            foreach ($keys as $k) {
                if (isset($k['col'])) {
                    $sort[(string)$k['col']] = ($k['dir'] ?? 'ASC') === 'DESC' ? -1 : 1;
                }
            }
        } elseif (!empty($options['orderBy'])) {
            $sort[(string)$options['orderBy']] = ($options['orderDir'] ?? '') === 'DESC' ? -1 : 1;
        }
        return $sort;
    }

    // ---- document -> grid --------------------------------------------------

    /**
     * Flatten documents into a uniform column/row grid for the data table.
     * Nested objects/arrays are rendered as compact JSON in their cell.
     *
     * @param list<array<string,mixed>> $docs
     * @return array{0:list<string>,1:list<array<string,mixed>>}
     */
    private function documentsToGrid(array $docs): array
    {
        $columns = [];
        $rows = [];
        foreach ($docs as $doc) {
            $plain = $this->toPlain($doc);
            $row = [];
            foreach ($plain as $k => $v) {
                $key = (string)$k;
                if (!in_array($key, $columns, true)) {
                    $columns[] = $key;
                }
                $row[$key] = $this->cellValue($v);
            }
            $rows[] = $row;
        }
        // _id first for readability.
        if (in_array('_id', $columns, true)) {
            $columns = array_merge(['_id'], array_values(array_filter($columns, static fn($c) => $c !== '_id')));
        }
        // Ensure every row has every column (grid expects rectangular data).
        foreach ($rows as &$r) {
            foreach ($columns as $c) {
                if (!array_key_exists($c, $r)) {
                    $r[$c] = null;
                }
            }
        }
        unset($r);
        return [$columns, $rows];
    }

    private function cellValue(mixed $v): mixed
    {
        if (is_array($v)) {
            return json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        return $v;
    }

    /** @param list<array<string,mixed>> $docs @return array<string,mixed> */
    private function docsToGridResult(array $docs, float $start): array
    {
        $plain = array_map([$this, 'toPlain'], $docs);
        [$columns, $rows] = $this->documentsToGrid($plain);
        return [
            'columns' => $columns,
            'rows' => $rows,
            'rowCount' => count($rows),
            'executionTimeMs' => (int)round((microtime(true) - $start) * 1000),
        ];
    }

    /** @param array<string,mixed> $reply @return array<string,mixed> */
    private function commandResultToGrid(array $reply, float $start): array
    {
        $plain = $this->toPlain($reply);
        if (isset($plain['cursor']['firstBatch'])) {
            return $this->docsToGridResult($plain['cursor']['firstBatch'], $start);
        }
        // Single-document result: render as one row.
        return $this->docsToGridResult([$plain], $start);
    }

    /** @return array<string,mixed> */
    private function scalarResult(string $label, int|float|string $value, float $start): array
    {
        return [
            'columns' => [$label],
            'rows' => [[$label => $value]],
            'rowCount' => 1,
            'executionTimeMs' => (int)round((microtime(true) - $start) * 1000),
        ];
    }

    /** @return array<string,mixed> */
    private function affectedResult(int $n, string $verb, float $start): array
    {
        return [
            'columns' => [],
            'rows' => [],
            'rowCount' => 0,
            'affectedRows' => $n,
            'executionTimeMs' => (int)round((microtime(true) - $start) * 1000),
            'message' => "{$n} document(s) {$verb}",
        ];
    }

    // ---- write coercion ----------------------------------------------------

    /** Convert incoming grid/JSON values into BSON-friendly types. @param array<string,mixed> $data @return array<string,mixed> */
    private function coerceForWrite(array $data): array
    {
        $out = [];
        foreach ($data as $k => $v) {
            $out[$k] = $this->coerceValue($k, $v);
        }
        return $out;
    }

    private function coerceValue(string $key, mixed $v): mixed
    {
        if ($key === '_id' && is_string($v) && preg_match('/^[0-9a-f]{24}$/i', $v)) {
            return new ObjectId($v);
        }
        if (is_string($v)) {
            $trimmed = trim($v);
            // Re-hydrate JSON objects/arrays typed into grid cells.
            if ($trimmed !== '' && ($trimmed[0] === '{' || $trimmed[0] === '[')) {
                $decoded = json_decode($trimmed, true);
                if (is_array($decoded)) {
                    return $this->jsonToBson($decoded);
                }
            }
        }
        return $v;
    }

    /** @param array<string,mixed> $pk @return array<string,mixed> */
    private function coerceFilter(array $pk): array
    {
        $out = [];
        foreach ($pk as $k => $v) {
            $out[$k] = $this->coerceValue((string)$k, $v);
        }
        return $out;
    }

    // ---- BSON value normalization -----------------------------------------

    /** Recursively convert BSON value objects to plain PHP scalars/arrays. */
    private function toPlain(mixed $v): mixed
    {
        if ($v instanceof ObjectId) {
            return $v->hex();
        }
        if ($v instanceof UTCDateTime) {
            return $v->toIso8601();
        }
        if ($v instanceof Int64) {
            return $v->value;
        }
        if ($v instanceof Binary) {
            return $v->jsonSerialize();
        }
        if ($v instanceof Regex) {
            return '/' . $v->pattern . '/' . $v->flags;
        }
        if (is_array($v)) {
            $out = [];
            foreach ($v as $k => $item) {
                $out[$k] = $this->toPlain($item);
            }
            return $out;
        }
        return $v;
    }

    /**
     * Recursively convert BSON objects to extendedJSON markers ($oid, $date, etc.)
     * that jsonToBson() can later rehidrate back to BSON types.
     * Used by dumpCollection so backup/transfer preserves full document fidelity.
     */
    private function toExtended(mixed $v): mixed
    {
        if ($v instanceof ObjectId) {
            return ['$oid' => $v->hex()];
        }
        if ($v instanceof UTCDateTime) {
            return ['$date' => $v->toIso8601()];
        }
        if ($v instanceof Int64) {
            return $v->value;
        }
        if ($v instanceof Binary) {
            return $v->jsonSerialize();
        }
        if ($v instanceof Regex) {
            return ['$regularExpression' => ['pattern' => $v->pattern, 'options' => $v->flags]];
        }
        if (is_array($v)) {
            $out = [];
            foreach ($v as $k => $item) {
                $out[$k] = $this->toExtended($item);
            }
            return $out;
        }
        return $v;
    }

    private function unwrapScalar(mixed $v): int|float|string
    {
        if ($v instanceof Int64) {
            return $v->value;
        }
        if (is_int($v) || is_float($v) || is_string($v)) {
            return $v;
        }
        return 0;
    }

    /** @param array<string,mixed> $doc @return array<string,mixed> */
    private function flatten(array $doc, string $prefix = ''): array
    {
        $out = [];
        foreach ($doc as $k => $v) {
            $key = $prefix === '' ? (string)$k : $prefix . '.' . $k;
            if (is_array($v) && $v !== [] && array_keys($v) !== range(0, count($v) - 1)) {
                $out += $this->flatten($v, $key);
            } else {
                $out[$key] = $v;
            }
        }
        return $out;
    }

    private function jsType(mixed $v): string
    {
        return match (true) {
            is_bool($v) => 'bool',
            is_int($v) => 'int',
            is_float($v) => 'double',
            is_string($v) => 'string',
            is_array($v) => array_keys($v) === range(0, count($v) - 1) ? 'array' : 'object',
            is_null($v) => 'null',
            default => 'mixed',
        };
    }
}
