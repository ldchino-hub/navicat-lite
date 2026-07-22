<?php
declare(strict_types=1);

namespace Navicat\Drivers;

/** Proxies MySQL driver calls through db.ldjr.me when NAS IP is blocked remotely. */
final class BridgeMySqlDriver
{
    /** @param array<string,mixed> $creds */
    public function __construct(
        private array $creds,
        private string $bridgeUrl,
        private string $bridgeKey,
    ) {
    }

    public static function enabledFor(array $creds): bool
    {
        $url = trim((string)(getenv('MYSQL_BRIDGE_URL') ?: ''));
        if ($url === '') {
            return false;
        }
        $hosts = array_filter(array_map('trim', explode(',', (string)(getenv('MYSQL_BRIDGE_HOSTS') ?: 'ldjr.me,208.109.41.242'))));
        $host = strtolower((string)($creds['host'] ?? ''));
        return in_array($host, $hosts, true);
    }

    /** @param list<mixed> $args */
    private function rpc(string $method, array $args): mixed
    {
        $payload = json_encode([
            'method' => $method,
            'args' => $args,
            'creds' => $this->creds,
        ], JSON_UNESCAPED_UNICODE);
        if ($payload === false) {
            throw new \RuntimeException('bridge payload encode failed');
        }

        $ctx = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => "Content-Type: application/json\r\nX-Bridge-Key: {$this->bridgeKey}\r\n",
                'content' => $payload,
                'timeout' => 60,
                'ignore_errors' => true,
            ],
            'ssl' => [
                'verify_peer' => false,
                'verify_peer_name' => false,
            ],
        ]);
        $raw = @file_get_contents($this->bridgeUrl, false, $ctx);
        if ($raw === false) {
            throw new \RuntimeException('bridge request failed');
        }
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            throw new \RuntimeException('bridge invalid json');
        }
        if (empty($data['ok'])) {
            throw new \RuntimeException((string)($data['error'] ?? 'bridge error'));
        }
        return $data['result'] ?? null;
    }

    public function testConnection(): bool
    {
        return (bool)$this->rpc('testConnection', []);
    }

    /** @return list<string> */
    public function listDatabases(): array
    {
        return $this->rpc('listDatabases', []);
    }

    /** @return list<array<string,mixed>> */
    public function listTablesLight(string $database): array
    {
        return $this->rpc('listTablesLight', [$database]);
    }

    /** @return list<array<string,mixed>> */
    public function listViews(string $database): array
    {
        return $this->rpc('listViews', [$database]);
    }

    /** @return list<array<string,mixed>> */
    public function listRoutines(string $database): array
    {
        return $this->rpc('listRoutines', [$database]);
    }

    /** @return list<array<string,mixed>> */
    public function listTriggers(string $database): array
    {
        return $this->rpc('listTriggers', [$database]);
    }

    public function getRoutineSource(string $database, string $name): string
    {
        return (string)$this->rpc('getRoutineSource', [$database, $name]);
    }

    public function getViewDefinition(string $database, string $view): string
    {
        return (string)$this->rpc('getViewDefinition', [$database, $view]);
    }

    public function getObjectDdl(string $database, string $type, string $name): string
    {
        return (string)$this->rpc('getObjectDdl', [$database, $type, $name]);
    }

    /** Clone an object definition (and optionally table data) into another
     *  database on the same MySQL server, via the bridge RPC. */
    public function cloneObject(string $sourceDb, string $type, string $name, string $targetDb, string $newName, bool $copyData = false): array
    {
        $t = strtolower($type);
        $ddl = $this->getObjectDdl($sourceDb, $t, $name);
        $rewritten = $this->rewriteObjectName($ddl, $name, $newName);
        $this->executeDDL($rewritten, $targetDb);
        if ($t === 'table' && $copyData) {
            $src = $this->quoteIdent($sourceDb) . '.' . $this->quoteIdent($name);
            $dst = $this->quoteIdent($targetDb) . '.' . $this->quoteIdent($newName);
            $this->execute("INSERT INTO {$dst} SELECT * FROM {$src}", null);
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

    private function quoteIdent(string $name): string
    {
        return '`' . str_replace('`', '``', $name) . '`';
    }

    /** @return array<string,mixed> */
    public function getTableInfo(string $database, string $table): array
    {
        return $this->rpc('getTableInfo', [$database, $table]);
    }

    /** @return array<string,mixed> */
    public function execute(string $sql, ?string $database = null): array
    {
        return $this->rpc('execute', [$sql, $database]);
    }

    /** @return array<string,mixed> */
    public function executeMany(string $sql, ?string $database = null): array
    {
        return $this->rpc('executeMany', [$sql, $database]);
    }

    /** @return array<string,mixed> */
    public function queryPaginated(string $database, string $table, array $options): array
    {
        return $this->rpc('queryPaginated', [$database, $table, $options]);
    }

    /** @return list<string> */
    public function getPrimaryKeys(string $database, string $table): array
    {
        return $this->rpc('getPrimaryKeys', [$database, $table]);
    }

    /** @param array<string,mixed> $data */
    public function insertRow(string $database, string $table, array $data): void
    {
        $this->rpc('insertRow', [$database, $table, $data]);
    }

    /** @param array<string,mixed> $pk @param array<string,mixed> $data */
    public function updateRow(string $database, string $table, array $pk, array $data): void
    {
        $this->rpc('updateRow', [$database, $table, $pk, $data]);
    }

    /** @param array<string,mixed> $pk */
    public function deleteRow(string $database, string $table, array $pk): void
    {
        $this->rpc('deleteRow', [$database, $table, $pk]);
    }

    public function explain(string $sql, ?string $database = null, bool $analyze = false): mixed
    {
        return $this->rpc('explain', [$sql, $database, $analyze]);
    }

    /** @return list<array<string,mixed>> */
    public function getProcessList(): array
    {
        return $this->rpc('getProcessList', []);
    }

    public function killProcess(string|int $id): void
    {
        $this->rpc('killProcess', [$id]);
    }

    /** @return list<array<string,mixed>> */
    public function listEngineUsers(): array
    {
        return $this->rpc('listEngineUsers', []);
    }

    public function createEngineUser(string $username, string $password, string $host = '%'): void
    {
        $this->rpc('createEngineUser', [$username, $password, $host]);
    }

    public function grantPrivileges(string $username, string $database, string $privileges, string $host = '%'): void
    {
        $this->rpc('grantPrivileges', [$username, $database, $privileges, $host]);
    }

    public function revokePrivileges(string $username, string $database, string $privileges, string $host = '%'): void
    {
        $this->rpc('revokePrivileges', [$username, $database, $privileges, $host]);
    }

    public function dropEngineUser(string $username, string $host = '%'): void
    {
        $this->rpc('dropEngineUser', [$username, $host]);
    }

    /** @return array<string,mixed> */
    public function getServerInfo(): array
    {
        return $this->rpc('getServerInfo', []);
    }

    public function executeDDL(string $ddl, ?string $database = null): void
    {
        $this->rpc('executeDDL', [$ddl, $database]);
    }

    /** @return list<array<string,mixed>> */
    public function getForeignKeys(string $database): array
    {
        return $this->rpc('getForeignKeys', [$database]);
    }

    public function createDatabase(string $name, string $charset = 'utf8mb4'): void
    {
        $this->rpc('createDatabase', [$name, $charset]);
    }

    public function dropDatabase(string $name): void
    {
        $this->rpc('dropDatabase', [$name]);
    }

    public function truncateTable(string $database, string $table): void
    {
        $this->rpc('truncateTable', [$database, $table]);
    }

    public function dropTable(string $database, string $table): void
    {
        $this->rpc('dropTable', [$database, $table]);
    }

    public function dropView(string $database, string $view): void
    {
        $this->rpc('dropView', [$database, $view]);
    }

    public function renameTable(string $database, string $oldName, string $newName): void
    {
        $this->rpc('renameTable', [$database, $oldName, $newName]);
    }
}
