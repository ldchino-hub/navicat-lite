<?php
declare(strict_types=1);

namespace Navicat\Http;

use Navicat\App;
use Navicat\Auth\AuthService;
use Navicat\Connections\ConnectionGroupRepository;
use Navicat\Connections\ConnectionRepository;
use Navicat\Drivers\DriverFactory;
use Navicat\Drivers\MongoDriver;
use Navicat\Drivers\MySqlDriver;
use Navicat\Drivers\PostgresDriver;
use Navicat\Response;
use Navicat\Services\AuditService;
use Navicat\Services\BackupService;
use Navicat\Services\DesignerService;
use Navicat\Services\DiffService;
use Navicat\Services\SchemaCache;
use Navicat\Services\TransferService;
use Navicat\Services\VpnService;
use Navicat\Support\DbJobId;
use Navicat\Util\Id;
use PDO;

final class Router
{
    private const MAX_SQL_ROWS = 2000;
    private const MAX_CELL_CHARS = 8192;

    private string $method;
    private string $path;
    /** @var array<string,mixed> */
    private array $body;
    /** @var array<string,string> */
    private array $query;

    public function __construct()
    {
        $this->path = navicat_request_path();
        $this->method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        $this->query = is_array($_GET) ? $_GET : [];
        if ($this->query === [] && !empty($_SERVER['REQUEST_URI'])) {
            parse_str(parse_url((string)$_SERVER['REQUEST_URI'], PHP_URL_QUERY) ?? '', $this->query);
        }
        $this->body = $this->parseBody();
    }

    public function dispatch(): void
    {
        try {
            if ($this->path === '/api/health' && $this->method === 'GET') {
                $versionFile = dirname(__DIR__, 2) . '/VERSION';
                $version = is_file($versionFile) ? trim((string)file_get_contents($versionFile)) : '1.0.0';
                Response::json(['ok' => true, 'version' => $version, 'edition' => 'lite']);
                return;
            }

            if (str_starts_with($this->path, '/api/auth/')) {
                $this->dispatchAuth();
                return;
            }
            if (preg_match('#^/api/connections/[^/]+/logs#', $this->path)) {
                $this->dispatchConnectionLogs();
                return;
            }
            if (preg_match('#^/api/connections/[^/]+/db-jobs#', $this->path)) {
                $this->dispatchDbJobs();
                return;
            }
            if (str_starts_with($this->path, '/api/connections')) {
                $this->dispatchConnections();
                return;
            }
            if (str_starts_with($this->path, '/api/sql/')) {
                $this->dispatchSql();
                return;
            }
            if (str_starts_with($this->path, '/api/data/')) {
                $this->dispatchData();
                return;
            }
            if (str_starts_with($this->path, '/api/schema/')) {
                $this->dispatchSchema();
                return;
            }
            if (str_starts_with($this->path, '/api/designer/')) {
                $this->dispatchDesigner();
                return;
            }
            if (str_starts_with($this->path, '/api/er/')) {
                $this->dispatchEr();
                return;
            }
            if (str_starts_with($this->path, '/api/monitor/')) {
                $this->dispatchMonitor();
                return;
            }
            if (str_starts_with($this->path, '/api/explain/')) {
                $this->dispatchExplain();
                return;
            }
            if (str_starts_with($this->path, '/api/engine-users/')) {
                $this->dispatchEngineUsers();
                return;
            }
            if (str_starts_with($this->path, '/api/vpn')) {
                $this->dispatchVpn();
                return;
            }
            if (str_starts_with($this->path, '/api/backup/')) {
                $this->dispatchBackup();
                return;
            }
            if (str_starts_with($this->path, '/api/queries')) {
                $this->dispatchQueries();
                return;
            }
            if (str_starts_with($this->path, '/api/connection-groups')) {
                $this->dispatchConnectionGroups();
                return;
            }
            if (str_starts_with($this->path, '/api/transfer')) {
                $this->dispatchTransfer();
                return;
            }
            if (str_starts_with($this->path, '/api/diff')) {
                $this->dispatchDiff();
                return;
            }
            if (str_starts_with($this->path, '/api/history')) {
                $this->dispatchHistory();
                return;
            }
            if (str_starts_with($this->path, '/api/audit')) {
                $this->dispatchAudit();
                return;
            }

            Response::error('Not found', 404);
        } catch (\RuntimeException $e) {
            $code = $e->getCode();
            Response::error($e->getMessage(), is_int($code) && $code >= 400 ? $code : 400);
        } catch (\Throwable $e) {
            $debug = App::config()['debug'] ?? false;
            Response::error($debug ? $e->getMessage() : 'Internal server error', 500);
        }
    }

    /** @return array<string,mixed> */
    private function parseBody(): array
    {
        $ct = $_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? '';
        if (str_contains($ct, 'application/json')) {
            $raw = file_get_contents('php://input') ?: '';
            if ($raw === '') {
                return [];
            }
            $decoded = json_decode($raw, true);
            return is_array($decoded) ? $decoded : [];
        }
        return [];
    }

    private function dispatchAuth(): void
    {
        if ($this->path === '/api/auth/login' && $this->method === 'POST') {
            $email = (string)($this->body['email'] ?? '');
            $password = (string)($this->body['password'] ?? '');
            if ($email === '' || $password === '') {
                Response::error('Email and password required', 400);
                return;
            }
            try {
                Response::json(AuthService::login($email, $password));
            } catch (\RuntimeException) {
                Response::error('Invalid credentials', 401);
            }
            return;
        }

        if ($this->path === '/api/auth/me' && $this->method === 'GET') {
            $user = AuthService::requireUser();
            Response::json(['user' => ['id' => $user['id'], 'email' => $user['email'], 'role' => $user['role']]]);
            return;
        }

        if ($this->path === '/api/auth/users' && $this->method === 'GET') {
            AuthService::requireAdmin();
            Response::json(AuthService::listUsers());
            return;
        }

        if ($this->path === '/api/auth/users' && $this->method === 'POST') {
            $admin = AuthService::requireAdmin();
            $email = (string)($this->body['email'] ?? '');
            $password = (string)($this->body['password'] ?? '');
            $role = (string)($this->body['role'] ?? 'viewer');
            if (!in_array($role, ['admin', 'dba', 'editor', 'viewer'], true)) {
                Response::error("Invalid role '{$role}'. Allowed: admin, dba, editor, viewer", 400);
                return;
            }
            if (strlen($password) < 8) {
                Response::error('Password must be at least 8 characters', 400);
                return;
            }
            $st = App::db()->prepare('SELECT id FROM users WHERE email = ?');
            $st->execute([$email]);
            if ($st->fetch()) {
                Response::error('User already exists', 409);
                return;
            }
            $newUser = AuthService::createUser($email, $password, $role);
            AuditService::log($admin['id'] ?? null, 'user.create', $email, ['role' => $role]);
            Response::json($newUser);
            return;
        }

        Response::error('Not found', 404);
    }

    private function dispatchConnections(): void
    {
        $user = AuthService::requireUser();

        if ($this->path === '/api/connections' && $this->method === 'GET') {
            Response::json($this->listConnectionsForUser($user));
            return;
        }

        if ($this->path === '/api/connections' && $this->method === 'POST') {
            AuthService::requireAdmin();
            $this->validateConnectionInput($this->body, true);
            $name = trim((string)($this->body['name'] ?? ''));
            $chk = App::db()->prepare('SELECT id FROM connections WHERE name = ?');
            $chk->execute([$name]);
            if ($chk->fetch()) {
                Response::error('A connection with this name already exists', 409);
                return;
            }
            try {
                $newConn = ConnectionRepository::create($this->body);
                AuditService::log($user['id'] ?? null, 'connection.create', $newConn['name'] ?? '');
                Response::json($newConn);
            } catch (\PDOException $e) {
                if (str_contains($e->getMessage(), 'UNIQUE constraint')) {
                    Response::error('A connection with this name already exists', 409);
                    return;
                }
                throw $e;
            }
            return;
        }

        if ($this->path === '/api/connections/test' && $this->method === 'POST') {
            $this->validateConnectionInput($this->body, true);
            $driver = $this->driverFromInput($this->body);
            try {
                $driver->testConnection();
                Response::json(['ok' => true]);
            } catch (\Throwable $e) {
                Response::json(['ok' => false, 'error' => $e->getMessage()], 502);
            }
            return;
        }

        if ($this->path === '/api/connections/export' && $this->method === 'GET') {
            AuthService::requireAdmin();
            $inc = ($_GET['includePasswords'] ?? '') === '1' || ($_GET['includePasswords'] ?? '') === 'true';
            $payload  = ConnectionRepository::exportPublic($inc);
            $filename = $inc ? 'connections-with-passwords.json' : 'navicat-connections.json';
            header('Content-Type: application/json; charset=utf-8');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
            return;
        }

        if ($this->path === '/api/connections/import' && $this->method === 'POST') {
            AuthService::requireAdmin();
            $result = ConnectionRepository::import((array)$this->body);
            Response::json($result);
            return;
        }

        if (preg_match('#^/api/connections/([^/]+)/group$#', $this->path, $m) && $this->method === 'PUT') {
            AuthService::requireAdmin();
            if (!array_key_exists('groupId', $this->body)) {
                Response::error('groupId required (may be null to unassign)', 400);
                return;
            }
            $gidRaw = $this->body['groupId'];
            $gid = ($gidRaw === null || $gidRaw === '') ? null : (string)$gidRaw;
            Response::json(ConnectionRepository::assignGroup($m[1], $gid));
            return;
        }

        if (preg_match('#^/api/connections/([^/]+)/grants$#', $this->path, $m) && $this->method === 'POST') {
            AuthService::requireAdmin();
            $connId = $m[1];
            $conn = ConnectionRepository::find($connId);
            if (!$conn) {
                Response::error('Connection not found', 404);
                return;
            }
            $userId = (string)($this->body['userId'] ?? '');
            $role = (string)($this->body['role'] ?? '');
            if ($userId === '') {
                Response::error('userId required', 400);
                return;
            }
            $allowedRoles = ['viewer', 'editor', 'dba'];
            if (!in_array($role, $allowedRoles, true)) {
                Response::error("Invalid role '{$role}'. Allowed: " . implode(', ', $allowedRoles), 400);
                return;
            }
            $st = App::db()->prepare('SELECT id FROM users WHERE id = ?');
            $st->execute([$userId]);
            if (!$st->fetch()) {
                Response::error('User not found', 404);
                return;
            }
            $grantId = Id::cuid();
            App::db()->prepare(
                'INSERT INTO connection_grants (id, user_id, connection_id, role)
                 VALUES (?, ?, ?, ?)
                 ON CONFLICT(user_id, connection_id) DO UPDATE SET role = excluded.role'
            )->execute([$grantId, $userId, $connId, $role]);
            AuditService::log($user['id'] ?? null, 'grant.upsert', $conn['name'] ?? $connId, ['userId' => $userId, 'role' => $role]);
            $row = App::db()->prepare('SELECT id, user_id AS userId, connection_id AS connectionId, role FROM connection_grants WHERE user_id = ? AND connection_id = ?');
            $row->execute([$userId, $connId]);
            Response::json($row->fetch(PDO::FETCH_ASSOC));
            return;
        }

        if (preg_match('#^/api/connections/([^/]+)/grants/([^/]+)$#', $this->path, $m) && $this->method === 'DELETE') {
            AuthService::requireAdmin();
            $connId = $m[1];
            $grantUserId = $m[2];
            App::db()->prepare('DELETE FROM connection_grants WHERE user_id = ? AND connection_id = ?')->execute([$grantUserId, $connId]);
            AuditService::log($user['id'] ?? null, 'grant.delete', $connId, ['userId' => $grantUserId]);
            Response::json(['ok' => true]);
            return;
        }

        if (preg_match('#^/api/connections/([^/]+)/grants$#', $this->path, $m) && $this->method === 'GET') {
            AuthService::requireAdmin();
            $connId = $m[1];
            $st = App::db()->prepare(
                'SELECT g.id, g.user_id AS userId, g.connection_id AS connectionId, g.role, u.email
                 FROM connection_grants g JOIN users u ON u.id = g.user_id
                 WHERE g.connection_id = ? ORDER BY u.email'
            );
            $st->execute([$connId]);
            Response::json(['grants' => $st->fetchAll(PDO::FETCH_ASSOC)]);
            return;
        }

        if (preg_match('#^/api/connections/([^/]+)$#', $this->path, $m)) {
            $id = $m[1];
            if ($this->method === 'GET') {
                $access = AuthService::requireConnectionAccess($user, $id, 'viewer');
                Response::json(ConnectionRepository::toPublic($access['conn']));
                return;
            }
            if ($this->method === 'PUT') {
                AuthService::requireAdmin();
                $conn = ConnectionRepository::update($id, $this->body);
                SchemaCache::invalidate($id);
                AuditService::log($user['id'] ?? null, 'connection.update', $conn['name'] ?? $id);
                Response::json($conn);
                return;
            }
            if ($this->method === 'DELETE') {
                AuthService::requireAdmin();
                $conn = ConnectionRepository::find($id);
                ConnectionRepository::delete($id);
                SchemaCache::invalidate($id);
                AuditService::log($user['id'] ?? null, 'connection.delete', $conn['name'] ?? $id);
                Response::json(['ok' => true]);
                return;
            }
        }

        if (preg_match('#^/api/connections/([^/]+)/test$#', $this->path, $m) && $this->method === 'POST') {
            $access = AuthService::requireConnectionAccess($user, $m[1], 'viewer');
            try {
                DriverFactory::getDriver($access['conn'])->testConnection();
                Response::json(['ok' => true]);
            } catch (\Throwable $e) {
                Response::json(['ok' => false, 'error' => $e->getMessage()], 502);
            }
            return;
        }

        if (preg_match('#^/api/connections/([^/]+)/databases$#', $this->path, $m)) {
            $access = AuthService::requireConnectionAccess($user, $m[1], 'viewer');
            $driver = DriverFactory::getDriver($access['conn']);
            if ($this->method === 'GET') {
                Response::json(['databases' => $driver->listDatabases()]);
                return;
            }
            if ($this->method === 'POST') {
                AuthService::requireConnectionAccess($user, $m[1], 'dba');
                $name = (string)($this->body['name'] ?? '');
                $charset = (string)($this->body['charset'] ?? 'utf8mb4');
                $driver->createDatabase($name, $charset);
                SchemaCache::invalidate($m[1]);
                Response::json(['ok' => true]);
                return;
            }
        }

        if (preg_match('#^/api/connections/([^/]+)/databases/([^/]+)$#', $this->path, $m) && $this->method === 'DELETE') {
            $access = AuthService::requireConnectionAccess($user, $m[1], 'dba');
            $db = rawurldecode($m[2]);
            DriverFactory::getDriver($access['conn'])->dropDatabase($db);
            SchemaCache::invalidate($m[1]);
            Response::json(['ok' => true]);
            return;
        }

        if (preg_match('#^/api/connections/([^/]+)/databases/([^/]+)/tables$#', $this->path, $m) && $this->method === 'GET') {
            $access = AuthService::requireConnectionAccess($user, $m[1], 'viewer');
            $db = rawurldecode($m[2]);
            Response::json(['tables' => DriverFactory::getDriver($access['conn'])->listTablesLight($db)]);
            return;
        }

        if (preg_match('#^/api/connections/([^/]+)/databases/([^/]+)/views$#', $this->path, $m) && $this->method === 'GET') {
            $access = AuthService::requireConnectionAccess($user, $m[1], 'viewer');
            $db = rawurldecode($m[2]);
            Response::json(['views' => DriverFactory::getDriver($access['conn'])->listViews($db)]);
            return;
        }

        if (preg_match('#^/api/connections/([^/]+)/databases/([^/]+)/routines$#', $this->path, $m) && $this->method === 'GET') {
            $access = AuthService::requireConnectionAccess($user, $m[1], 'viewer');
            $db = rawurldecode($m[2]);
            Response::json(['routines' => DriverFactory::getDriver($access['conn'])->listRoutines($db)]);
            return;
        }

        if (preg_match('#^/api/connections/([^/]+)/databases/([^/]+)/triggers$#', $this->path, $m) && $this->method === 'GET') {
            $access = AuthService::requireConnectionAccess($user, $m[1], 'viewer');
            $db = rawurldecode($m[2]);
            Response::json(['triggers' => DriverFactory::getDriver($access['conn'])->listTriggers($db)]);
            return;
        }

        if (preg_match('#^/api/connections/([^/]+)/databases/([^/]+)/routines/([^/]+)/source$#', $this->path, $m) && $this->method === 'GET') {
            $access = AuthService::requireConnectionAccess($user, $m[1], 'viewer');
            $db = rawurldecode($m[2]);
            $name = rawurldecode($m[3]);
            $source = DriverFactory::getDriver($access['conn'])->getRoutineSource($db, $name);
            Response::json(['source' => $source]);
            return;
        }

        if (preg_match('#^/api/connections/([^/]+)/databases/([^/]+)/views/([^/]+)/source$#', $this->path, $m) && $this->method === 'GET') {
            $access = AuthService::requireConnectionAccess($user, $m[1], 'viewer');
            $db = rawurldecode($m[2]);
            $view = rawurldecode($m[3]);
            $def = DriverFactory::getDriver($access['conn'])->getViewDefinition($db, $view);
            Response::json(['definition' => $def]);
            return;
        }

        if (preg_match('#^/api/connections/([^/]+)/databases/([^/]+)/tables/([^/]+)$#', $this->path, $m)) {
            $access = AuthService::requireConnectionAccess($user, $m[1], 'viewer');
            $db = rawurldecode($m[2]);
            $table = rawurldecode($m[3]);
            $driver = DriverFactory::getDriver($access['conn']);
            if ($this->method === 'GET') {
                Response::json($driver->getTableInfo($db, $table));
                return;
            }
            if ($this->method === 'DELETE') {
                AuthService::requireConnectionAccess($user, $m[1], 'dba');
                $driver->dropTable($db, $table);
                SchemaCache::invalidate($m[1]);
                Response::json(['ok' => true]);
                return;
            }
        }

        if (preg_match('#^/api/connections/([^/]+)/databases/([^/]+)/views/([^/]+)$#', $this->path, $m) && $this->method === 'DELETE') {
            $access = AuthService::requireConnectionAccess($user, $m[1], 'dba');
            $db = rawurldecode($m[2]);
            $view = rawurldecode($m[3]);
            DriverFactory::getDriver($access['conn'])->dropView($db, $view);
            SchemaCache::invalidate($m[1]);
            Response::json(['ok' => true]);
            return;
        }

        if (preg_match('#^/api/connections/([^/]+)/databases/([^/]+)/tables/([^/]+)/truncate$#', $this->path, $m) && $this->method === 'POST') {
            $access = AuthService::requireConnectionAccess($user, $m[1], 'dba');
            $db = rawurldecode($m[2]);
            $table = rawurldecode($m[3]);
            DriverFactory::getDriver($access['conn'])->truncateTable($db, $table);
            Response::json(['ok' => true]);
            return;
        }

        if (preg_match('#^/api/connections/([^/]+)/server-info$#', $this->path, $m) && $this->method === 'GET') {
            $access = AuthService::requireConnectionAccess($user, $m[1], 'viewer');
            Response::json(DriverFactory::getDriver($access['conn'])->getServerInfo());
            return;
        }

        if (
            preg_match(
                '#^/api/connections/([^/]+)/databases/([^/]+)/tables/([^/]+)/ddl$#',
                $this->path,
                $m
            ) && $this->method === 'GET'
        ) {
            $access = AuthService::requireConnectionAccess($user, $m[1], 'viewer');
            $db = rawurldecode($m[2]);
            $name = rawurldecode($m[3]);
            $ddl = DriverFactory::getDriver($access['conn'])->getObjectDdl($db, 'table', $name);
            Response::json(['ddl' => $ddl]);
            return;
        }

        if (
            preg_match(
                '#^/api/connections/([^/]+)/databases/([^/]+)/views/([^/]+)/ddl$#',
                $this->path,
                $m
            ) && $this->method === 'GET'
        ) {
            $access = AuthService::requireConnectionAccess($user, $m[1], 'viewer');
            $db = rawurldecode($m[2]);
            $name = rawurldecode($m[3]);
            $ddl = DriverFactory::getDriver($access['conn'])->getObjectDdl($db, 'view', $name);
            Response::json(['ddl' => $ddl]);
            return;
        }

        if (
            preg_match(
                '#^/api/connections/([^/]+)/databases/([^/]+)/routines/([^/]+)/ddl$#',
                $this->path,
                $m
            ) && $this->method === 'GET'
        ) {
            $access = AuthService::requireConnectionAccess($user, $m[1], 'viewer');
            $db = rawurldecode($m[2]);
            $name = rawurldecode($m[3]);
            $ddl = DriverFactory::getDriver($access['conn'])->getObjectDdl($db, 'routine', $name);
            Response::json(['ddl' => $ddl]);
            return;
        }

        if (
            preg_match(
                '#^/api/connections/([^/]+)/databases/([^/]+)/triggers/([^/]+)/ddl$#',
                $this->path,
                $m
            ) && $this->method === 'GET'
        ) {
            $access = AuthService::requireConnectionAccess($user, $m[1], 'viewer');
            $db = rawurldecode($m[2]);
            $name = rawurldecode($m[3]);
            $ddl = DriverFactory::getDriver($access['conn'])->getObjectDdl($db, 'trigger', $name);
            Response::json(['ddl' => $ddl]);
            return;
        }

        Response::error('Not found', 404);
    }

    private function dispatchSql(): void
    {
        if (!preg_match('#^/api/sql/([^/]+)/execute$#', $this->path, $m) || $this->method !== 'POST') {
            Response::error('Not found', 404);
            return;
        }

        $user = AuthService::requireUser();
        $sql = (string)($this->body['sql'] ?? '');
        $database = isset($this->body['database']) ? (string)$this->body['database'] : null;
        $multi = ($this->body['multi'] ?? true) !== false;

        if ($sql === '') {
            Response::error('SQL required', 400);
            return;
        }

        $minRole = $this->isReadOnlySql($sql) ? 'viewer' : 'editor';
        $access = AuthService::requireConnectionAccess($user, $m[1], $minRole);
        $driver = DriverFactory::getDriver($access['conn']);

        try {
            if ($multi) {
                $sqlForExec = $this->maybeApplyPreviewLimit($sql);
                $result = $this->sanitizeMulti($driver->executeMany($sqlForExec, $database));
                Response::json($result);
                return;
            }
            $result = $driver->execute($sql, $database);
            Response::json([
                'statements' => [$this->sanitizeResult($result)],
                'totalTimeMs' => $result['executionTimeMs'] ?? 0,
            ]);
        } catch (\Throwable $e) {
            Response::json([
                'error' => $e->getMessage(),
                'statements' => [[
                    'columns' => [],
                    'rows' => [],
                    'rowCount' => 0,
                    'executionTimeMs' => 0,
                    'message' => 'ERROR: ' . $e->getMessage(),
                ]],
            ], 400);
        }
    }

    private function dispatchData(): void
    {
        $user = AuthService::requireUser();

        if (preg_match('#^/api/data/([^/]+)/([^/]+)/([^/]+)/export$#', $this->path, $m) && $this->method === 'GET') {
            $access = AuthService::requireConnectionAccess($user, $m[1], 'viewer');
            $db = rawurldecode($m[2]);
            $table = rawurldecode($m[3]);
            $format = $this->query['format'] ?? 'json';
            $driver = DriverFactory::getDriver($access['conn']);
            $exportSortKeysRaw = $this->query['sortKeys'] ?? null;
            $exportSortKeys = null;
            if ($exportSortKeysRaw) {
                $decoded = json_decode($exportSortKeysRaw, true);
                if (is_array($decoded)) {
                    $exportSortKeys = array_values(array_filter($decoded, fn($k) => isset($k['col'])));
                }
            }
            $result = $driver->queryPaginated($db, $table, [
                'offset' => 0,
                'limit' => 100000,
                'orderBy' => $this->query['orderBy'] ?? null,
                'orderDir' => $this->query['orderDir'] ?? null,
                'sortKeys' => $exportSortKeys,
                'filter' => $this->query['filter'] ?? null,
            ]);

            if ($format === 'csv') {
                $header = implode(',', $result['columns']);
                $rows = [];
                foreach ($result['rows'] as $row) {
                    $cells = [];
                    foreach ($result['columns'] as $col) {
                        $v = $row[$col] ?? '';
                        if ($v === null) {
                            $cells[] = '';
                        } else {
                            $s = (string)$v;
                            $cells[] = (str_contains($s, ',') || str_contains($s, '"'))
                                ? '"' . str_replace('"', '""', $s) . '"'
                                : $s;
                        }
                    }
                    $rows[] = implode(',', $cells);
                }
                header('Content-Type: text/csv');
                header('Content-Disposition: attachment; filename="' . $table . '.csv"');
                echo implode("\n", [$header, ...$rows]);
                return;
            }

            if ($format === 'sql') {
                $maxRows = 10000;
                $tableEsc = str_replace('`', '``', $table);
                $colList = implode(', ', array_map(
                    static fn(string $c): string => '`' . str_replace('`', '``', $c) . '`',
                    $result['columns']
                ));
                $lines = [];
                $allRows = $result['rows'];
                if (count($allRows) > $maxRows) {
                    $lines[] = '-- Export limited to ' . $maxRows . ' of ' . count($allRows) . ' rows';
                    $allRows = array_slice($allRows, 0, $maxRows);
                }
                foreach ($allRows as $row) {
                    $vals = [];
                    foreach ($result['columns'] as $col) {
                        $v = $row[$col] ?? null;
                        if ($v === null) {
                            $vals[] = 'NULL';
                        } elseif (is_bool($v)) {
                            $vals[] = $v ? 'TRUE' : 'FALSE';
                        } elseif (is_int($v) || is_float($v)) {
                            $vals[] = (string)$v;
                        } else {
                            $vals[] = "'" . str_replace(["\\", "'"], ["\\\\", "''"], (string)$v) . "'";
                        }
                    }
                    $lines[] = 'INSERT INTO `' . $tableEsc . '` (' . $colList . ') VALUES (' . implode(', ', $vals) . ');';
                }
                header('Content-Type: application/sql; charset=utf-8');
                header('Content-Disposition: attachment; filename="' . $table . '.sql"');
                echo implode("\n", $lines);
                return;
            }

            header('Content-Type: application/json');
            header('Content-Disposition: attachment; filename="' . $table . '.json"');
            echo json_encode($result['rows'], JSON_UNESCAPED_UNICODE);
            return;
        }

        if (!preg_match('#^/api/data/([^/]+)/([^/]+)/([^/]+)$#', $this->path, $m)) {
            Response::error('Not found', 404);
            return;
        }

        $connId = $m[1];
        $db = rawurldecode($m[2]);
        $table = rawurldecode($m[3]);
        $driver = DriverFactory::getDriver(AuthService::requireConnectionAccess($user, $connId, 'viewer')['conn']);

        if ($this->method === 'GET') {
            $structuredFilters = null;
            $filtersRaw = $this->query['filters'] ?? $this->query['structuredFilters'] ?? null;
            if ($filtersRaw !== null && $filtersRaw !== '') {
                $decoded = json_decode((string)$filtersRaw, true);
                if ($decoded === null && json_last_error() !== JSON_ERROR_NONE) {
                    Response::error('Invalid filters JSON (must be UTF-8 JSON array of filter objects)', 400);
                    return;
                }
                if (!is_array($decoded)) {
                    Response::error('filters JSON must decode to an array or object with clauses', 400);
                    return;
                }
                if (isset($decoded['clauses']) && is_array($decoded['clauses'])) {
                    $structuredFilters = array_values(array_filter($decoded['clauses'], 'is_array'));
                } elseif ($decoded !== [] && isset($decoded['column'])) {
                    $structuredFilters = [$decoded];
                } else {
                    $structuredFilters = array_values(array_filter($decoded, 'is_array'));
                }
            }

            $sortKeysRaw = $this->query['sortKeys'] ?? null;
            $sortKeys = null;
            if ($sortKeysRaw) {
                $decoded = json_decode($sortKeysRaw, true);
                if (is_array($decoded)) {
                    $sortKeys = array_values(array_filter($decoded, fn($k) => isset($k['col'])));
                }
            }
            $opts = [
                'offset' => (int)($this->query['offset'] ?? 0),
                'limit' => (int)($this->query['limit'] ?? 100),
                'orderBy' => $this->query['orderBy'] ?? null,
                'orderDir' => $this->query['orderDir'] ?? null,
                'sortKeys' => $sortKeys,
                'filter' => $this->query['filter'] ?? null,
                'structuredFilters' => $structuredFilters ?? [],
            ];
            $result = $driver->queryPaginated($db, $table, $opts);
            $pks = $driver->getPrimaryKeys($db, $table);
            Response::json([...$result, 'primaryKeys' => $pks]);
            return;
        }

        if ($this->method === 'POST') {
            AuthService::requireConnectionAccess($user, $connId, 'editor');
            $driver->insertRow($db, $table, $this->body);
            Response::json(['ok' => true]);
            return;
        }

        if ($this->method === 'PUT') {
            AuthService::requireConnectionAccess($user, $connId, 'editor');
            $pk = (array)($this->body['pk'] ?? []);
            $data = (array)($this->body['data'] ?? []);
            $driver->updateRow($db, $table, $pk, $data);
            Response::json(['ok' => true]);
            return;
        }

        if ($this->method === 'DELETE') {
            AuthService::requireConnectionAccess($user, $connId, 'editor');
            $driver->deleteRow($db, $table, $this->body);
            Response::json(['ok' => true]);
            return;
        }

        Response::error('Not found', 404);
    }

    private function dispatchSchema(): void
    {
        $user = AuthService::requireUser();

        if (preg_match('#^/api/schema/([^/]+)/snapshot$#', $this->path, $m) && $this->method === 'GET') {
            $access = AuthService::requireConnectionAccess($user, $m[1], 'viewer');
            $database = $this->query['database'] ?? null;
            Response::json(SchemaCache::getSnapshot($access['conn'], $database));
            return;
        }

        if (preg_match('#^/api/schema/([^/]+)/invalidate$#', $this->path, $m) && $this->method === 'POST') {
            AuthService::requireConnectionAccess($user, $m[1], 'viewer');
            SchemaCache::invalidate($m[1]);
            Response::json(['ok' => true]);
            return;
        }

        Response::error('Not found', 404);
    }

    private function dispatchDesigner(): void
    {
        $user = AuthService::requireUser();

        if (preg_match('#^/api/designer/([^/]+)/([^/]+)/([^/]+)$#', $this->path, $m) && $this->method === 'GET') {
            $access = AuthService::requireConnectionAccess($user, $m[1], 'viewer');
            $db = rawurldecode($m[2]);
            $table = rawurldecode($m[3]);
            $info = DriverFactory::getDriver($access['conn'])->getTableInfo($db, $table);
            Response::json(DesignerService::tableInfoToDesign($table, $info['columns'], $info['indexes']));
            return;
        }

        if (preg_match('#^/api/designer/([^/]+)/([^/]+)/preview$#', $this->path, $m) && $this->method === 'POST') {
            $access = AuthService::requireConnectionAccess($user, $m[1], 'viewer');
            $db = rawurldecode($m[2]);
            $design = (array)($this->body['design'] ?? $this->body);
            $existingName = isset($this->body['existingName']) ? (string)$this->body['existingName'] : null;
            $ddl = $this->generateDesignerDDL($access['conn'], $db, $design, $existingName);
            Response::json(['ddl' => $ddl]);
            return;
        }

        if (preg_match('#^/api/designer/([^/]+)/([^/]+)/apply$#', $this->path, $m) && $this->method === 'POST') {
            AuthService::requireConnectionAccess($user, $m[1], 'dba');
            $conn = ConnectionRepository::find($m[1]);
            $db = rawurldecode($m[2]);
            $design = (array)($this->body['design'] ?? $this->body);
            $existingName = isset($this->body['existingName']) ? (string)$this->body['existingName'] : null;
            $ddl = $this->generateDesignerDDL($conn, $db, $design, $existingName);
            try {
                DriverFactory::getDriver($conn)->executeDDL($ddl, $db);
                SchemaCache::invalidate($m[1]);
                Response::json(['ok' => true, 'ddl' => $ddl]);
            } catch (\Throwable $e) {
                Response::json(['error' => $e->getMessage(), 'ddl' => $ddl], 400);
            }
            return;
        }

        Response::error('Not found', 404);
    }

    private function dispatchEr(): void
    {
        $user = AuthService::requireUser();

        if (preg_match('#^/api/er/([^/]+)/([^/]+)/positions$#', $this->path, $m) && $this->method === 'PUT') {
            AuthService::requireConnectionAccess($user, $m[1], 'editor');
            $db = rawurldecode($m[2]);
            $conn = ConnectionRepository::find($m[1]);
            $meta = json_decode((string)($conn['meta_json'] ?? '{}'), true) ?: [];
            $erPositions = $meta['erPositions'] ?? [];
            $erPositions[$db] = $this->body;
            ConnectionRepository::updateMeta($m[1], ['erPositions' => $erPositions]);
            Response::json(['ok' => true]);
            return;
        }

        if (preg_match('#^/api/er/([^/]+)/([^/]+)$#', $this->path, $m) && $this->method === 'GET') {
            $access = AuthService::requireConnectionAccess($user, $m[1], 'viewer');
            $db = rawurldecode($m[2]);
            $driver = DriverFactory::getDriver($access['conn']);
            $tablesLight = $driver->listTablesLight($db);
            $validTables = [];
            foreach ($tablesLight as $t) {
                try {
                    $validTables[] = $driver->getTableInfo($db, $t['name']);
                } catch (\Throwable) {
                    // skip
                }
            }
            $foreignKeys = $driver->getForeignKeys($db);
            $meta = json_decode((string)($access['conn']['meta_json'] ?? '{}'), true) ?: [];
            $erPositions = $meta['erPositions'] ?? [];
            $positions = $erPositions[$db] ?? [];

            $nodes = [];
            foreach ($validTables as $i => $t) {
                $nodes[] = [
                    'id' => $t['name'],
                    'type' => 'tableNode',
                    'position' => $positions[$t['name']] ?? ['x' => ($i % 5) * 260, 'y' => (int)floor($i / 5) * 220],
                    'data' => [
                        'label' => $t['name'],
                        'columns' => array_map(static fn(array $c): array => [
                            'name' => $c['name'],
                            'type' => $c['type'],
                            'isPrimaryKey' => $c['isPrimaryKey'],
                            'isForeignKey' => $c['isForeignKey'],
                        ], $t['columns']),
                    ],
                ];
            }

            $edges = [];
            foreach ($foreignKeys as $fk) {
                $edges[] = [
                    'id' => $fk['fromTable'] . '.' . $fk['fromColumn'] . '->' . $fk['toTable'] . '.' . $fk['toColumn'],
                    'source' => $fk['fromTable'],
                    'target' => $fk['toTable'],
                    'label' => $fk['constraintName'],
                    'data' => ['fromColumn' => $fk['fromColumn'], 'toColumn' => $fk['toColumn']],
                ];
            }

            Response::json(['nodes' => $nodes, 'edges' => $edges]);
            return;
        }

        Response::error('Not found', 404);
    }

    private function dispatchConnectionLogs(): void
    {
        $user = AuthService::requireUser();
        if (!preg_match('#^/api/connections/([^/]+)/logs#', $this->path, $m)) {
            Response::error('Not found', 404);
            return;
        }
        $access = AuthService::requireConnectionAccess($user, $m[1], 'viewer');
        $driver = DriverFactory::getDriver($access['conn']);
        $sub = preg_replace('#^/api/connections/[^/]+/logs/?#', '', $this->path) ?: '';
        $limit = min(500, max(1, (int)($this->query['limit'] ?? 50)));
        $engine = (string)($access['conn']['engine'] ?? '');

        if ($sub === '' && $this->method === 'GET') {
            $config = [];
            if ($driver instanceof MySqlDriver || $driver instanceof PostgresDriver || $driver instanceof MongoDriver) {
                $config = $driver->getLogConfig();
            }
            Response::json(['engine' => $engine, 'config' => $config]);
            return;
        }
        if ($sub === 'slow-query' && $this->method === 'GET') {
            $fn = null;
            if ($driver instanceof MySqlDriver) $fn = 'getSlowQueryLog';
            elseif ($driver instanceof PostgresDriver) $fn = 'getSlowQueryLog';
            elseif ($driver instanceof MongoDriver) $fn = 'getSlowQueryLog';
            if ($fn) {
                $st = $pdo ?? null;
                $entries = $driver->$fn($limit);
                Response::json(['entries' => $entries]);
                return;
            }
        }
        if ($sub === 'general' && $this->method === 'GET') {
            $fn = null;
            if ($driver instanceof MySqlDriver) $fn = 'getGeneralLog';
            elseif ($driver instanceof PostgresDriver) $fn = 'getGeneralLog';
            elseif ($driver instanceof MongoDriver) $fn = 'getGeneralLog';
            if ($fn) {
                Response::json(['entries' => $driver->$fn($limit)]);
                return;
            }
        }
        if ($sub === 'binary' && $this->method === 'GET' && $driver instanceof MySqlDriver) {
            Response::json(['logs' => $driver->getBinaryLogs()]);
            return;
        }
        Response::error('Not found', 404);
    }

    private function dispatchDbJobs(): void
    {
        $user = AuthService::requireUser();

        if (preg_match('#^/api/connections/([^/]+)/db-jobs/history$#', $this->path, $m) && $this->method === 'GET') {
            $access = AuthService::requireConnectionAccess($user, $m[1], 'viewer');
            $limit = min(500, max(1, (int)($this->query['limit'] ?? 200)));
            Response::json($this->dbJobsHistoryAll($access['conn'], $limit));
            return;
        }

        if (preg_match('#^/api/connections/([^/]+)/db-jobs/([^/]+)/history$#', $this->path, $m) && $this->method === 'GET') {
            $access = AuthService::requireConnectionAccess($user, $m[1], 'viewer');
            Response::json($this->dbJobHistoryOne($access['conn'], $m[2]));
            return;
        }

        if (preg_match('#^/api/connections/([^/]+)/db-jobs/([^/]+)/enable$#', $this->path, $m) && $this->method === 'POST') {
            $access = AuthService::requireConnectionAccess($user, $m[1], 'dba');
            $this->dbJobEnable($access['conn'], $m[2]);
            Response::json(['ok' => true]);
            return;
        }

        if (preg_match('#^/api/connections/([^/]+)/db-jobs/([^/]+)/disable$#', $this->path, $m) && $this->method === 'POST') {
            $access = AuthService::requireConnectionAccess($user, $m[1], 'dba');
            $this->dbJobDisable($access['conn'], $m[2]);
            Response::json(['ok' => true]);
            return;
        }

        if (preg_match('#^/api/connections/([^/]+)/db-jobs/([^/]+)/run$#', $this->path, $m) && $this->method === 'POST') {
            $access = AuthService::requireConnectionAccess($user, $m[1], 'dba');
            $this->dbJobRunNow($user, $access['conn'], $m[2]);
            Response::json(['ok' => true]);
            return;
        }

        if (preg_match('#^/api/connections/([^/]+)/db-jobs/([^/]+)$#', $this->path, $m) && $this->method === 'GET') {
            $access = AuthService::requireConnectionAccess($user, $m[1], 'viewer');
            Response::json($this->dbJobDetail($access['conn'], $m[2]));
            return;
        }

        if (preg_match('#^/api/connections/([^/]+)/db-jobs/([^/]+)$#', $this->path, $m) && $this->method === 'DELETE') {
            $access = AuthService::requireConnectionAccess($user, $m[1], 'dba');
            $this->dbJobDrop($access['conn'], $m[2]);
            Response::json(['ok' => true]);
            return;
        }

        if (preg_match('#^/api/connections/([^/]+)/db-jobs$#', $this->path, $m) && $this->method === 'GET') {
            $access = AuthService::requireConnectionAccess($user, $m[1], 'viewer');
            $database = isset($this->query['database']) ? (string)$this->query['database'] : null;
            Response::json($this->dbJobsList($access['conn'], $database));
            return;
        }

        if (preg_match('#^/api/connections/([^/]+)/db-jobs$#', $this->path, $m) && $this->method === 'POST') {
            $access = AuthService::requireConnectionAccess($user, $m[1], 'dba');
            $database = (string)($this->body['database'] ?? $this->query['database'] ?? '');
            if ($database === '') {
                Response::error('database required', 400);
                return;
            }
            $driver = DriverFactory::getDriver($access['conn']);
            if (!$driver instanceof MySqlDriver) {
                Response::error('Create DB job not supported for this engine yet', 501);
                return;
            }
            try {
                $driver->createDbJob($database, $this->body);
                AuditService::log($user['id'] ?? null, 'dbjob.create', $access['conn']['name'] ?? $m[1]);
                Response::json(['ok' => true], 201);
            } catch (\Throwable $e) {
                Response::error($e->getMessage(), 400);
            }
            return;
        }

        Response::error('Not found', 404);
    }

    /** @return array<string,mixed> */
    private function dbJobsList(array $conn, ?string $database): array
    {
        $engine = (string)($conn['engine'] ?? '');
        if ($engine === 'mysql') {
            $driver = DriverFactory::getDriver($conn);
            if (!$driver instanceof MySqlDriver) {
                Response::error('Invalid MySQL driver', 500);
                return [];
            }
            return [
                'engine' => 'mysql',
                'jobs' => $driver->getDbJobs($database !== '' ? $database : null),
            ];
        }
        if ($engine === 'mongodb') {
            return [
                'engine' => 'mongodb',
                'jobs' => [],
                'note' => 'MongoDB app jobs require the scheduler and are not available in Lite',
            ];
        }
        if ($engine === 'postgres') {
            $driver = DriverFactory::getDriver($conn);
            if ($driver instanceof PostgresDriver) {
                $result = $driver->getDbJobs();
                return [
                    'engine' => 'postgres',
                    'jobs' => $result['jobs'] ?? [],
                    'pg_cron_missing' => (bool)($result['pg_cron_missing'] ?? true),
                ];
            }
            return ['engine' => 'postgres', 'jobs' => [], 'pg_cron_missing' => true];
        }
        return ['engine' => $engine, 'jobs' => []];
    }

    /** @return array<string,mixed> */
    private function dbJobDetail(array $conn, string $jobId): array
    {
        $engine = (string)($conn['engine'] ?? '');
        if ($engine === 'mongodb') {
            Response::error('MongoDB app jobs are not available in Lite', 501);
            return [];
        }
        if ($engine === 'postgres') {
            $decoded = DbJobId::decode($jobId);
            $jobid = (int)($decoded['jobid'] ?? 0);
            foreach ($this->dbJobsList($conn, null)['jobs'] as $j) {
                if (($j['jobId'] ?? '') === $jobId) {
                    return $j;
                }
            }
            Response::error('Job not found', 404);
            return [];
        }
        if ($engine !== 'mysql') {
            Response::error('Not supported for this engine', 501);
            return [];
        }
        $decoded = DbJobId::decode($jobId);
        $db = (string)($decoded['db'] ?? '');
        $name = (string)($decoded['name'] ?? '');
        $driver = DriverFactory::getDriver($conn);
        if (!$driver instanceof MySqlDriver) {
            Response::error('Invalid driver', 500);
            return [];
        }
        return $driver->getDbJob($db, $name);
    }

    private function dbJobEnable(array $conn, string $jobId): void
    {
        $this->mutateDbJob($conn, $jobId, 'enable');
    }

    private function dbJobDisable(array $conn, string $jobId): void
    {
        $this->mutateDbJob($conn, $jobId, 'disable');
    }

    private function dbJobDrop(array $conn, string $jobId): void
    {
        $this->mutateDbJob($conn, $jobId, 'drop');
    }

    private function dbJobRunNow(array $user, array $conn, string $jobId): void
    {
        $engine = (string)($conn['engine'] ?? '');
        if ($engine === 'mongodb') {
            Response::error('MongoDB app jobs are not available in Lite', 501);
            return;
        }
        Response::error('Run now not supported for this engine yet', 501);
    }

    private function mutateDbJob(array $conn, string $jobId, string $action): void
    {
        $engine = (string)($conn['engine'] ?? '');
        if ($engine === 'mongodb') {
            Response::error('MongoDB app jobs are not available in Lite', 501);
            return;
        }
        if ($engine === 'postgres') {
            $decoded = DbJobId::decode($jobId);
            $jobid = (int)($decoded['jobid'] ?? 0);
            $driver = DriverFactory::getDriver($conn);
            if (!$driver instanceof PostgresDriver) {
                Response::error('Invalid driver', 500);
                return;
            }
            if ($action === 'enable') {
                $driver->enableDbJob($jobid);
            } elseif ($action === 'disable') {
                $driver->disableDbJob($jobid);
            } elseif ($action === 'drop') {
                $driver->dropDbJob($jobid);
            }
            return;
        }
        if ($engine !== 'mysql') {
            Response::error('Not supported', 501);
            return;
        }
        $decoded = DbJobId::decode($jobId);
        $db = (string)($decoded['db'] ?? '');
        $name = (string)($decoded['name'] ?? '');
        $driver = DriverFactory::getDriver($conn);
        if (!$driver instanceof MySqlDriver) {
            Response::error('Invalid driver', 500);
            return;
        }
        if ($action === 'enable') {
            $driver->enableDbJob($db, $name);
        } elseif ($action === 'disable') {
            $driver->disableDbJob($db, $name);
        } elseif ($action === 'drop') {
            $driver->dropDbJob($db, $name);
        }
    }

    /** @return array<string,mixed> */
    private function dbJobHistoryOne(array $conn, string $jobId): array
    {
        $engine = (string)($conn['engine'] ?? '');
        if ($engine === 'mongodb') {
            return ['engine' => 'mongodb', 'history' => [], 'note' => 'MongoDB app jobs are not available in Lite'];
        }
        if ($engine === 'postgres') {
            $decoded = DbJobId::decode($jobId);
            $jobid = (int)($decoded['jobid'] ?? 0);
            $driver = DriverFactory::getDriver($conn);
            if (!$driver instanceof PostgresDriver) {
                Response::error('Invalid driver', 500);
                return [];
            }
            return ['engine' => 'postgres', 'history' => $driver->getDbJobHistory($jobid)];
        }
        if ($engine !== 'mysql') {
            return ['engine' => $engine, 'history' => [], 'note' => 'History not available yet'];
        }
        $decoded = DbJobId::decode($jobId);
        $driver = DriverFactory::getDriver($conn);
        if (!$driver instanceof MySqlDriver) {
            Response::error('Invalid driver', 500);
            return [];
        }
        return [
            'engine' => 'mysql',
            'history' => $driver->getDbJobHistory((string)($decoded['db'] ?? ''), (string)($decoded['name'] ?? '')),
            'note' => 'MySQL event history is limited to LAST_EXECUTED',
        ];
    }

    /** @return array<string,mixed> */
    private function dbJobsHistoryAll(array $conn, int $limit): array
    {
        $engine = (string)($conn['engine'] ?? '');
        if ($engine === 'mysql') {
            $driver = DriverFactory::getDriver($conn);
            if (!$driver instanceof MySqlDriver) {
                return ['history' => []];
            }
            $history = [];
            foreach ($driver->getDbJobs(null) as $job) {
                $history = array_merge(
                    $history,
                    $driver->getDbJobHistory((string)$job['database'], (string)$job['name'])
                );
            }
            return ['engine' => 'mysql', 'history' => array_slice($history, 0, $limit)];
        }
        return ['engine' => $engine, 'history' => []];
    }

    private function dispatchMonitor(): void
    {
        $user = AuthService::requireUser();

        if (preg_match('#^/api/monitor/([^/]+)/processlist$#', $this->path, $m) && $this->method === 'GET') {
            $access = AuthService::requireConnectionAccess($user, $m[1], 'viewer');
            Response::json(DriverFactory::getDriver($access['conn'])->getProcessList());
            return;
        }

        if (preg_match('#^/api/monitor/([^/]+)/kill/([^/]+)$#', $this->path, $m) && $this->method === 'POST') {
            $access = AuthService::requireConnectionAccess($user, $m[1], 'dba');
            DriverFactory::getDriver($access['conn'])->killProcess($m[2]);
            Response::json(['ok' => true]);
            return;
        }

        if (preg_match('#^/api/monitor/([^/]+)/status$#', $this->path, $m) && $this->method === 'GET') {
            $access = AuthService::requireConnectionAccess($user, $m[1], 'viewer');
            Response::json(DriverFactory::getDriver($access['conn'])->getServerInfo());
            return;
        }

        Response::error('Not found', 404);
    }

    private function dispatchExplain(): void
    {
        if (!preg_match('#^/api/explain/([^/]+)$#', $this->path, $m) || $this->method !== 'POST') {
            Response::error('Not found', 404);
            return;
        }

        $user = AuthService::requireUser();
        $access = AuthService::requireConnectionAccess($user, $m[1], 'viewer');
        $sql = (string)($this->body['sql'] ?? '');
        $database = isset($this->body['database']) ? (string)$this->body['database'] : null;
        $analyze = !empty($this->body['analyze']);

        if ($sql === '') {
            Response::error('SQL required', 400);
            return;
        }

        try {
            $plan = DriverFactory::getDriver($access['conn'])->explain($sql, $database, $analyze);
            Response::json(['plan' => $plan, 'engine' => $access['conn']['engine']]);
        } catch (\Throwable $e) {
            Response::error($e->getMessage(), 400);
        }
    }

    private function dispatchEngineUsers(): void
    {
        $user = AuthService::requireUser();

        if (preg_match('#^/api/engine-users/([^/]+)/batch-preview$#', $this->path, $m) && $this->method === 'POST') {
            $access = AuthService::requireConnectionAccess($user, $m[1], 'dba');
            Response::json(['sql' => $this->buildBatchScript($access['conn']['engine'], $this->body)]);
            return;
        }

        if (preg_match('#^/api/engine-users/([^/]+)/batch-apply$#', $this->path, $m) && $this->method === 'POST') {
            $access = AuthService::requireConnectionAccess($user, $m[1], 'dba');
            $engine = (string)$access['conn']['engine'];
            $sql = $this->buildBatchScript($engine, $this->body);
            try {
                if ($engine === 'mongodb') {
                    $this->applyMongoBatch(DriverFactory::getDriver($access['conn']), $this->body);
                } else {
                    DriverFactory::getDriver($access['conn'])->executeDDL($sql);
                }
                Response::json(['ok' => true, 'sql' => $sql]);
            } catch (\Throwable $e) {
                Response::json(['error' => $e->getMessage(), 'sql' => $sql], 400);
            }
            return;
        }

        if (preg_match('#^/api/engine-users/([^/]+)/grant$#', $this->path, $m) && $this->method === 'POST') {
            $access = AuthService::requireConnectionAccess($user, $m[1], 'dba');
            $driver = DriverFactory::getDriver($access['conn']);
            $driver->grantPrivileges(
                (string)$this->body['username'],
                (string)$this->body['database'],
                (string)($this->body['privileges'] ?? 'ALL PRIVILEGES'),
                (string)($this->body['host'] ?? '%')
            );
            Response::json(['ok' => true]);
            return;
        }

        if (preg_match('#^/api/engine-users/([^/]+)/revoke$#', $this->path, $m) && $this->method === 'POST') {
            $access = AuthService::requireConnectionAccess($user, $m[1], 'dba');
            $driver = DriverFactory::getDriver($access['conn']);
            $driver->revokePrivileges(
                (string)$this->body['username'],
                (string)$this->body['database'],
                (string)($this->body['privileges'] ?? 'ALL PRIVILEGES'),
                (string)($this->body['host'] ?? '%')
            );
            Response::json(['ok' => true]);
            return;
        }

        if (preg_match('#^/api/engine-users/([^/]+)/([^/]+)$#', $this->path, $m) && $this->method === 'DELETE') {
            $access = AuthService::requireConnectionAccess($user, $m[1], 'dba');
            $host = $this->query['host'] ?? '%';
            DriverFactory::getDriver($access['conn'])->dropEngineUser(rawurldecode($m[2]), $host);
            Response::json(['ok' => true]);
            return;
        }

        if (preg_match('#^/api/engine-users/([^/]+)$#', $this->path, $m)) {
            $access = AuthService::requireConnectionAccess($user, $m[1], 'dba');
            $driver = DriverFactory::getDriver($access['conn']);
            if ($this->method === 'GET') {
                Response::json($driver->listEngineUsers());
                return;
            }
            if ($this->method === 'POST') {
                $driver->createEngineUser(
                    (string)$this->body['username'],
                    (string)$this->body['password'],
                    (string)($this->body['host'] ?? '%')
                );
                Response::json(['ok' => true]);
                return;
            }
        }

        Response::error('Not found', 404);
    }

    private function dispatchVpn(): void
    {
        AuthService::requireUser();

        if ($this->path === '/api/vpn/status' && $this->method === 'GET') {
            Response::json(VpnService::status());
            return;
        }
        if ($this->path === '/api/vpn/up' && $this->method === 'POST') {
            AuthService::requireAdmin();
            Response::json(VpnService::up());
            return;
        }
        if ($this->path === '/api/vpn/down' && $this->method === 'POST') {
            AuthService::requireAdmin();
            Response::json(VpnService::down());
            return;
        }

        if (preg_match('#^/api/vpn/([^/]+)/status$#', $this->path, $m) && $this->method === 'GET') {
            $access = AuthService::requireConnectionAccess(AuthService::requireUser(), $m[1], 'viewer');
            Response::json([
                ...VpnService::status(),
                'connectionId' => $access['conn']['id'],
                'configGroup' => $access['conn']['config_group'] ?? $access['conn']['id'],
                'enabled' => VpnService::connectionRequiresVpn($access['conn']),
            ]);
            return;
        }

        if (preg_match('#^/api/vpn/([^/]+)/up$#', $this->path, $m) && $this->method === 'POST') {
            AuthService::requireAdmin();
            $access = AuthService::requireConnectionAccess(AuthService::requireUser(), $m[1], 'viewer');
            VpnService::ensureForConnection($access['conn']);
            Response::json([
                ...VpnService::status(),
                'connectionId' => $access['conn']['id'],
                'configGroup' => $access['conn']['config_group'] ?? $access['conn']['id'],
                'enabled' => true,
            ]);
            return;
        }

        if (preg_match('#^/api/vpn/([^/]+)/down$#', $this->path, $m) && $this->method === 'POST') {
            AuthService::requireAdmin();
            $access = AuthService::requireConnectionAccess(AuthService::requireUser(), $m[1], 'viewer');
            Response::json([
                ...VpnService::down(),
                'connectionId' => $access['conn']['id'],
                'configGroup' => $access['conn']['config_group'] ?? $access['conn']['id'],
                'enabled' => VpnService::connectionRequiresVpn($access['conn']),
            ]);
            return;
        }

        Response::error('Not found', 404);
    }

    private function dispatchBackup(): void
    {
        $user = AuthService::requireUser();

        if (preg_match('#^/api/backup/([^/]+)/list$#', $this->path, $m) && $this->method === 'GET') {
            AuthService::requireConnectionAccess($user, $m[1], 'viewer');
            $filterDb = trim((string)($_GET['database'] ?? ''));
            $st = App::db()->prepare(
                'SELECT b.*, u.email AS user_email FROM backups b
                 LEFT JOIN users u ON u.id = b.user_id
                 WHERE b.connection_id = ? ORDER BY b.started_at DESC LIMIT 100'
            );
            $st->execute([$m[1]]);
            $rows = [];
            foreach ($st->fetchAll() as $b) {
                if ($filterDb !== '' && (string)$b['database_name'] !== $filterDb) {
                    continue;
                }
                $opts = json_decode((string)($b['options_json'] ?? '{}'), true);
                $rows[] = [
                    'id' => $b['id'],
                    'database' => $b['database_name'],
                    'filePath' => $b['file_path'],
                    'sizeBytes' => (string)$b['size_bytes'],
                    'status' => $b['status'],
                    'startedAt' => $b['started_at'],
                    'finishedAt' => $b['finished_at'],
                    'user' => $b['user_email'] ? ['email' => $b['user_email']] : null,
                    'options' => is_array($opts) ? $opts : [],
                ];
            }
            Response::json($rows);
            return;
        }

        if (preg_match('#^/api/backup/download/([^/]+)$#', $this->path, $m) && $this->method === 'GET') {
            $st = App::db()->prepare('SELECT * FROM backups WHERE id = ?');
            $st->execute([$m[1]]);
            $backup = $st->fetch();
            if (!$backup) {
                Response::error('Backup not found', 404);
                return;
            }
            AuthService::requireConnectionAccess($user, (string)$backup['connection_id'], 'viewer');
            $path = (string)$backup['file_path'];
            if (!is_file($path)) {
                Response::error('Backup file not found', 404);
                return;
            }
            $filename = basename($path);
            header('Content-Type: application/octet-stream');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            readfile($path);
            return;
        }

        if (preg_match('#^/api/backup/([^/]+)/run$#', $this->path, $m) && $this->method === 'POST') {
            $access = AuthService::requireConnectionAccess($user, $m[1], 'dba');
            $database = (string)($this->body['database'] ?? '');
            $options = (array)($this->body['options'] ?? []);
            Response::sseStart();
            try {
                $this->runBackup($access['conn'], $database, (string)$user['id'], $options);
            } catch (\Throwable $e) {
                Response::sse(['type' => 'error', 'message' => $e->getMessage()]);
            }
            return;
        }

        if (preg_match('#^/api/backup/([^/]+)/restore$#', $this->path, $m) && $this->method === 'POST') {
            $sourceId = $m[1];
            AuthService::requireConnectionAccess($user, $sourceId, 'viewer');
            Response::sseStart();
            try {
                $database = '';
                $filePath = '';
                $restoreOptions = [];
                $targetId = $sourceId;
                $contentType = $_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? '';

                if (str_contains($contentType, 'multipart/form-data')) {
                    // Multipart file upload
                    if (empty($_FILES['file'])) {
                        throw new \RuntimeException('No file uploaded');
                    }
                    $database = (string)($_POST['database'] ?? '');
                    $targetId = (string)($_POST['targetConnectionId'] ?? $sourceId);
                    $restoreOptions = [
                        'override' => !empty($_POST['override']) && $_POST['override'] !== 'false',
                        'sourceDatabase' => trim((string)($_POST['sourceDatabase'] ?? '')),
                    ];
                    $uploadDir = rtrim((string)(App::config()['backup_dir'] ?? sys_get_temp_dir()), '/') . '/uploads';
                    if (!is_dir($uploadDir)) {
                        mkdir($uploadDir, 0755, true);
                    }
                    $orig = basename((string)($_FILES['file']['name'] ?? 'backup.sql'));
                    $filePath = $uploadDir . '/' . time() . '_' . preg_replace('/[^A-Za-z0-9._\-]/', '_', $orig);
                    if (!move_uploaded_file($_FILES['file']['tmp_name'], $filePath)) {
                        throw new \RuntimeException('Failed to save uploaded file');
                    }
                } else {
                    // JSON body
                    $database = (string)($this->body['database'] ?? '');
                    $filePath = (string)($this->body['filePath'] ?? '');
                    $backupId = (string)($this->body['backupId'] ?? '');
                    $targetId = (string)($this->body['targetConnectionId'] ?? $sourceId);
                    $restoreOptions = [
                        'override' => !empty($this->body['override']),
                        'sourceDatabase' => trim((string)($this->body['sourceDatabase'] ?? '')),
                    ];
                    if ($filePath === '' && $backupId !== '') {
                        $st = App::db()->prepare('SELECT file_path, connection_id FROM backups WHERE id = ?');
                        $st->execute([$backupId]);
                        $row = $st->fetch();
                        if (!$row || (string)$row['connection_id'] !== $sourceId) {
                            throw new \RuntimeException('Backup not found');
                        }
                        $filePath = (string)$row['file_path'];
                    }
                    if ($filePath === '') {
                        throw new \RuntimeException('filePath, backupId, or file upload required');
                    }
                }

                if ($database === '') {
                    throw new \RuntimeException('database required');
                }
                $targetAccess = AuthService::requireConnectionAccess($user, $targetId, 'dba');
                $service = new BackupService();
                $service->restore($targetAccess['conn'], $database, $filePath, static function (array $ev): void {
                    Response::sse($ev);
                }, $restoreOptions);
                AuditService::log($user['id'] ?? null, 'backup.restore', $targetAccess['conn']['name'] ?? $targetId, ['database' => $database, 'filePath' => basename($filePath)]);
            } catch (\Throwable $e) {
                Response::sse(['type' => 'error', 'message' => $e->getMessage()]);
            }
            return;
        }

        Response::error('Not found', 404);
    }

    /** @param array<string,mixed> $user @return list<array<string,mixed>> */
    private function listConnectionsForUser(array $user): array
    {
        if (($user['role'] ?? '') === 'admin') {
            return ConnectionRepository::listPublic();
        }
        $st = App::db()->prepare(
            'SELECT c.* FROM connections c
             JOIN connection_grants g ON g.connection_id = c.id
             WHERE g.user_id = ? ORDER BY c.name'
        );
        $st->execute([$user['id']]);
        $out = [];
        foreach ($st->fetchAll() as $row) {
            $out[] = ConnectionRepository::toPublic($row);
        }
        return $out;
    }

    /** @param array<string,mixed> $input */
    private function validateConnectionInput(array $input, bool $requirePassword = false): void
    {
        foreach (['name', 'engine', 'host', 'port', 'username'] as $field) {
            if (empty($input[$field])) {
                throw new \RuntimeException("Missing field: {$field}", 400);
            }
        }
        if ($requirePassword && empty($input['password'])) {
            throw new \RuntimeException('Password required', 400);
        }
    }

    /** @param array<string,mixed> $input */
    private function driverFromInput(array $input): MySqlDriver|PostgresDriver|MongoDriver
    {
        $meta = is_array($input['metaJson'] ?? null) ? $input['metaJson'] : [];
        foreach (['authDb', 'caFile', 'tls', 'tlsAllowInvalid', 'authMechanism'] as $k) {
            if (array_key_exists($k, $input)) {
                $meta[$k] = $input[$k];
            }
        }
        $creds = [
            'host' => (string)$input['host'],
            'port' => (int)$input['port'],
            'username' => (string)$input['username'],
            'password' => (string)($input['password'] ?? ''),
            'defaultDb' => $input['defaultDb'] ?? null,
            'sslMode' => $input['sslMode'] ?? 'preferred',
            'useVpn' => !empty($input['useVpn']),
            'configGroup' => $input['configGroup'] ?? null,
            'engine' => (string)$input['engine'],
            'meta' => $meta,
        ];
        return match ($input['engine'] ?? '') {
            'postgres' => new PostgresDriver($creds),
            'mongodb' => new MongoDriver($creds),
            default => new MySqlDriver($creds),
        };
    }

    /** @param array<string,mixed> $conn @param array<string,mixed> $design */
    private function generateDesignerDDL(array $conn, string $db, array $design, ?string $existingName): string
    {
        if (($conn['engine'] ?? '') === 'mongodb') {
            // Mongo collections are schemaless: the "DDL" is just create/keep.
            $name = (string)($design['name'] ?? $existingName ?? '');
            if ($existingName) {
                return "// MongoDB: collection '{$existingName}' is schemaless; no column DDL needed.";
            }
            return json_encode(['create' => $name], JSON_UNESCAPED_SLASHES);
        }
        if (($conn['engine'] ?? '') === 'mysql' && $existingName) {
            $info = DriverFactory::getDriver($conn)->getTableInfo($db, $existingName);
            $current = DesignerService::tableInfoToDesign($existingName, $info['columns'], $info['indexes']);
            return DesignerService::generateMySqlAlterDDL($design, $current);
        }
        if (($conn['engine'] ?? '') === 'mysql') {
            return DesignerService::generateMySqlDDL($design, $existingName);
        }
        return DesignerService::generatePostgresDDL($design, $existingName);
    }

    private function isReadOnlySql(string $sql): bool
    {
        return (bool)preg_match('/^\s*(SELECT|SHOW|DESCRIBE|EXPLAIN|WITH)\b/i', $sql);
    }

    private function maybeApplyPreviewLimit(string $sql): string
    {
        $trimmed = trim($sql);
        if ($this->isReadOnlySql($trimmed) && !preg_match('/\bLIMIT\s+\d+/i', $trimmed) && !str_contains($trimmed, ';')) {
            return $trimmed . ' LIMIT ' . self::MAX_SQL_ROWS;
        }
        return $sql;
    }

    /** @param array<string,mixed> $r @return array<string,mixed> */
    private function sanitizeResult(array $r): array
    {
        if (empty($r['rows'])) {
            return $r;
        }
        $total = count($r['rows']);
        $limited = array_slice($r['rows'], 0, self::MAX_SQL_ROWS);
        $rows = [];
        foreach ($limited as $row) {
            $out = [];
            foreach ($row as $k => $v) {
                $out[$k] = $this->sanitizeValue($v);
            }
            $rows[] = $out;
        }
        $truncated = $total > self::MAX_SQL_ROWS;
        return [
            ...$r,
            'rows' => $rows,
            'rowCount' => count($rows),
            'message' => $truncated
                ? "{$total} rows returned. Showing first " . self::MAX_SQL_ROWS . '. Add LIMIT for full control.'
                : ($r['message'] ?? null),
        ];
    }

    /** @param array<string,mixed> $m @return array<string,mixed> */
    private function sanitizeMulti(array $m): array
    {
        $statements = [];
        foreach ($m['statements'] ?? [] as $s) {
            $statements[] = $this->sanitizeResult($s);
        }
        return [...$m, 'statements' => $statements];
    }

    private function sanitizeValue(mixed $v): mixed
    {
        if ($v === null) {
            return null;
        }
        if (is_string($v)) {
            return strlen($v) > self::MAX_CELL_CHARS
                ? substr($v, 0, self::MAX_CELL_CHARS) . '… [truncated]'
                : $v;
        }
        if (is_array($v)) {
            $out = [];
            foreach ($v as $k => $item) {
                $out[$k] = $this->sanitizeValue($item);
            }
            return $out;
        }
        return $v;
    }

    /** Apply a user/role batch to MongoDB via the driver. @param array<string,mixed> $body */
    private function applyMongoBatch(MongoDriver $driver, array $body): void
    {
        $username = (string)($body['username'] ?? '');
        $password = (string)($body['password'] ?? '');
        $databases = (array)($body['databases'] ?? []);
        $privileges = (string)($body['privileges'] ?? 'readWrite');
        $action = (string)($body['action'] ?? 'grant');

        if (!empty($body['createUser']) && $username !== '') {
            $driver->createEngineUser($username, $password);
        }
        foreach ($databases as $db) {
            if ($action === 'revoke') {
                $driver->revokePrivileges($username, (string)$db, $privileges);
            } else {
                $driver->grantPrivileges($username, (string)$db, $privileges);
            }
        }
    }

    /** @param array<string,mixed> $body */
    private function buildBatchScript(string $engine, array $body): string
    {
        if ($engine === 'mongodb') {
            // MongoDB grants are applied directly via the driver (createUser /
            // grantRolesToUser); there is no SQL batch to preview.
            $username = (string)($body['username'] ?? '');
            $action = (string)($body['action'] ?? 'grant');
            $dbs = implode(', ', array_map('strval', (array)($body['databases'] ?? [])));
            return "// MongoDB: '{$action}' roles for user '{$username}'"
                . ($dbs !== '' ? " on databases [{$dbs}]" : '')
                . "\n// Applied via driver (no SQL script).";
        }
        $username = (string)($body['username'] ?? '');
        $password = (string)($body['password'] ?? '');
        $host = (string)($body['host'] ?? '%');
        $databases = (array)($body['databases'] ?? []);
        $privileges = (string)($body['privileges'] ?? 'ALL PRIVILEGES');
        $createUser = !empty($body['createUser']);
        $action = (string)($body['action'] ?? 'grant');
        $lines = [];

        if ($createUser) {
            if ($engine === 'mysql') {
                $lines[] = 'CREATE USER IF NOT EXISTS '
                    . $this->quoteString($username) . '@' . $this->quoteString($host)
                    . ' IDENTIFIED BY ' . $this->quoteString($password) . ';';
            } else {
                $passPart = $password !== '' ? ' WITH PASSWORD ' . $this->quoteString($password) : '';
                $lines[] = "DO \$\$ BEGIN CREATE USER {$this->quotePgIdent($username)}{$passPart}; EXCEPTION WHEN duplicate_object THEN NULL; END \$\$;";
            }
        } elseif ($password !== '') {
            if ($engine === 'mysql') {
                $lines[] = 'ALTER USER ' . $this->quoteString($username) . '@' . $this->quoteString($host)
                    . ' IDENTIFIED BY ' . $this->quoteString($password) . ';';
            } else {
                $lines[] = 'ALTER USER ' . $this->quotePgIdent($username) . ' WITH PASSWORD ' . $this->quoteString($password) . ';';
            }
        }

        foreach ($databases as $db) {
            $dbIdent = $engine === 'mysql'
                ? $this->quoteMySqlIdent((string)$db)
                : $this->quotePgIdent((string)$db);
            $userRef = $engine === 'mysql'
                ? $this->quoteString($username) . '@' . $this->quoteString($host)
                : $this->quotePgIdent($username);
            if ($action === 'revoke') {
                $lines[] = "REVOKE {$privileges} ON {$dbIdent}.* FROM {$userRef};";
            } else {
                $lines[] = "GRANT {$privileges} ON {$dbIdent}.* TO {$userRef};";
            }
        }
        if ($engine === 'mysql') {
            $lines[] = 'FLUSH PRIVILEGES;';
        }
        return implode("\n", $lines);
    }

    /** @param array<string,mixed> $conn @param array<string,mixed> $options */
    private function runBackup(array $conn, string $database, string $userId, array $options): void
    {
        $connName = preg_replace('/[^a-zA-Z0-9_-]/', '_', (string)$conn['name']) ?: 'conn';
        $dir = rtrim((string)App::config()['backup_dir'], '/') . '/' . $connName;
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $timestamp = gmdate('YmdHi');
        $gzip = ($options['gzip'] ?? true) !== false;
        $ext = ($conn['engine'] ?? '') === 'postgres'
            ? ($gzip ? 'sql.gz' : 'sql')
            : ($gzip ? 'sql.gz' : 'sql');
        $filePath = $dir . '/' . $database . '_' . $timestamp . '.' . $ext;
        $backupId = Id::cuid();

        App::db()->prepare(
            'INSERT INTO backups (id, connection_id, user_id, database_name, file_path, status, options_json)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        )->execute([$backupId, $conn['id'], $userId, $database, $filePath, 'running', json_encode($options)]);

        try {
            $service = new BackupService();
            $service->backup($conn, $database, $filePath, $options, static function (array $ev): void {
                Response::sse($ev);
            });
            $size = is_file($filePath) ? filesize($filePath) : 0;
            $sha256 = is_file($filePath) ? hash_file('sha256', $filePath) : null;
            App::db()->prepare(
                'UPDATE backups SET status = \'completed\', size_bytes = ?, sha256 = ?, finished_at = ' . \Navicat\Database::nowSql() . ' WHERE id = ?'
            )->execute([$size, $sha256, $backupId]);
            AuditService::log($userId, 'backup.run', $conn['name'] ?? '', ['database' => $database, 'filePath' => basename($filePath)]);
            Response::sse(['type' => 'done', 'message' => $filePath]);
        } catch (\Throwable $e) {
            App::db()->prepare(
                'UPDATE backups SET status = \'failed\', error = ?, finished_at = ' . \Navicat\Database::nowSql() . ' WHERE id = ?'
            )->execute([$e->getMessage(), $backupId]);
            throw $e;
        }
    }

    private function quoteString(string $value): string
    {
        return "'" . str_replace("'", "''", $value) . "'";
    }

    private function quoteMySqlIdent(string $value): string
    {
        return '`' . str_replace('`', '``', $value) . '`';
    }

    private function quotePgIdent(string $value): string
    {
        return '"' . str_replace('"', '""', $value) . '"';
    }

    private function dispatchQueries(): void
    {
        $user = AuthService::requireUser();

        if (!preg_match('#^/api/queries/([^/]+)$#', $this->path, $m)) {
            Response::error('Not found', 404);
            return;
        }

        $paramId = $m[1];

        if ($this->method === 'GET') {
            AuthService::requireConnectionAccess($user, $paramId, 'viewer');
            $st = App::db()->prepare(
                'SELECT id, title, sql_text AS sql, updated_at AS updatedAt, created_at AS createdAt
                 FROM saved_queries WHERE connection_id = ? AND user_id = ? ORDER BY updated_at DESC'
            );
            $st->execute([$paramId, $user['id']]);
            Response::json($st->fetchAll(PDO::FETCH_ASSOC));
            return;
        }

        if ($this->method === 'POST') {
            AuthService::requireConnectionAccess($user, $paramId, 'editor');
            $title = trim((string)($this->body['title'] ?? ''));
            $sql = (string)($this->body['sql'] ?? '');
            if ($title === '' || $sql === '') {
                Response::error('Title and SQL required', 400);
                return;
            }
            $id = Id::cuid();
            App::db()->prepare(
                'INSERT INTO saved_queries (id, user_id, connection_id, title, sql_text) VALUES (?, ?, ?, ?, ?)'
            )->execute([$id, $user['id'], $paramId, $title, $sql]);
            $st = App::db()->prepare(
                'SELECT id, title, sql_text AS sql, updated_at AS updatedAt, created_at AS createdAt FROM saved_queries WHERE id = ?'
            );
            $st->execute([$id]);
            Response::json($st->fetch(PDO::FETCH_ASSOC));
            return;
        }

        if ($this->method === 'PUT' || $this->method === 'DELETE') {
            $st = App::db()->prepare('SELECT id, connection_id FROM saved_queries WHERE id = ? AND user_id = ?');
            $st->execute([$paramId, $user['id']]);
            $existing = $st->fetch(PDO::FETCH_ASSOC);
            if (!$existing) {
                Response::error('Query not found', 404);
                return;
            }

            if ($this->method === 'DELETE') {
                App::db()->prepare('DELETE FROM saved_queries WHERE id = ?')->execute([$paramId]);
                Response::json(['ok' => true]);
                return;
            }

            $title = isset($this->body['title']) ? trim((string)$this->body['title']) : null;
            $sql = isset($this->body['sql']) ? (string)$this->body['sql'] : null;
            if ($title === null && $sql === null) {
                Response::error('Nothing to update', 400);
                return;
            }
            if ($title !== null && $title === '') {
                Response::error('Title required', 400);
                return;
            }
            if ($sql !== null && $sql === '') {
                Response::error('SQL required', 400);
                return;
            }

            $fields = [];
            $values = [];
            if ($title !== null) {
                $fields[] = 'title = ?';
                $values[] = $title;
            }
            if ($sql !== null) {
                $fields[] = 'sql_text = ?';
                $values[] = $sql;
            }
            $fields[] = 'updated_at = ' . \Navicat\Database::nowSql();
            $values[] = $paramId;
            App::db()->prepare('UPDATE saved_queries SET ' . implode(', ', $fields) . ' WHERE id = ?')->execute($values);

            $out = App::db()->prepare(
                'SELECT id, title, sql_text AS sql, updated_at AS updatedAt, created_at AS createdAt FROM saved_queries WHERE id = ?'
            );
            $out->execute([$paramId]);
            Response::json($out->fetch(PDO::FETCH_ASSOC));
            return;
        }

        Response::error('Not found', 404);
    }

    private function dispatchConnectionGroups(): void
    {
        AuthService::requireUser();

        if ($this->path === '/api/connection-groups' && $this->method === 'GET') {
            Response::json(['groups' => ConnectionGroupRepository::list()]);
            return;
        }

        if ($this->path === '/api/connection-groups' && $this->method === 'POST') {
            AuthService::requireAdmin();
            Response::json(ConnectionGroupRepository::create((string)($this->body['name'] ?? '')));
            return;
        }

        if (preg_match('#^/api/connection-groups/([^/]+)$#', $this->path, $m) && $this->method === 'PUT') {
            AuthService::requireAdmin();
            $sortOrder = isset($this->body['sortOrder']) ? (int)$this->body['sortOrder'] : null;
            Response::json(ConnectionGroupRepository::update($m[1], (string)($this->body['name'] ?? ''), $sortOrder));
            return;
        }

        if (preg_match('#^/api/connection-groups/([^/]+)$#', $this->path, $m) && $this->method === 'DELETE') {
            AuthService::requireAdmin();
            ConnectionGroupRepository::delete($m[1]);
            Response::json(['ok' => true]);
            return;
        }

        Response::error('Not found', 404);
    }

    private function dispatchTransfer(): void
    {
        $user = AuthService::requireUser();

        if ($this->path !== '/api/transfer/run' || $this->method !== 'POST') {
            Response::error('Not found', 404);
            return;
        }

        $srcRef = (array)($this->body['source'] ?? []);
        $tgtRef = (array)($this->body['target'] ?? []);
        $srcId = (string)($srcRef['connectionId'] ?? '');
        $tgtId = (string)($tgtRef['connectionId'] ?? '');
        $srcDb = (string)($srcRef['database'] ?? '');
        $tgtDb = (string)($tgtRef['database'] ?? '');
        $batch = (int)($this->body['batchSize'] ?? 1000);
        $truncate = !empty($this->body['truncate']) || !empty($this->body['truncateTarget']);
        $createIfMissing = !empty($this->body['createIfMissing']);

        /** @var list<string> $tables */
        $tables = [];
        if (isset($this->body['tables']) && is_array($this->body['tables'])) {
            $tables = array_values(array_filter(array_map('strval', $this->body['tables']), static fn(string $t): bool => trim($t) !== ''));
        }
        if ($tables === []) {
            $legacy = (string)($srcRef['table'] ?? '');
            if ($legacy !== '') {
                $tables = [$legacy];
            }
        }

        if ($srcId === '' || $tgtId === '' || $srcDb === '' || $tgtDb === '' || $tables === []) {
            Response::error('source/target require connectionId and database; tables must be a non-empty array', 400);
            return;
        }

        $srcAccess = AuthService::requireConnectionAccess($user, $srcId, 'viewer');
        $tgtAccess = AuthService::requireConnectionAccess($user, $tgtId, 'editor');

        Response::sseStart();
        try {
            TransferService::runBatch(
                $srcAccess['conn'],
                $srcDb,
                $tgtAccess['conn'],
                $tgtDb,
                $tables,
                max(1, $batch),
                static function (array $event): void {
                    Response::sse($event);
                },
                $truncate,
                $createIfMissing
            );
        } catch (\Throwable $e) {
            Response::sse(['type' => 'error', 'message' => $e->getMessage()]);
        }
    }

    private function dispatchDiff(): void
    {
        $user = AuthService::requireUser();

        if ($this->path === '/api/diff/schema' && $this->method === 'POST') {
            [$src, $tgt] = $this->parseDiffSides();
            $srcId = (string)($src['connectionId'] ?? '');
            $tgtId = (string)($tgt['connectionId'] ?? '');
            $srcDb = (string)($src['database'] ?? '');
            $tgtDb = (string)($tgt['database'] ?? '');
            if ($srcId === '' || $tgtId === '' || $srcDb === '' || $tgtDb === '') {
                Response::error('source and target require connectionId and database', 400);
                return;
            }
            $srcConn = AuthService::requireConnectionAccess($user, $srcId, 'viewer')['conn'];
            $tgtConn = AuthService::requireConnectionAccess($user, $tgtId, 'viewer')['conn'];
            /** @var list<string>|null $tables */
            $tables = isset($this->body['tables']) && is_array($this->body['tables'])
                ? array_values(array_map('strval', $this->body['tables']))
                : null;
            if ($tables === null) {
                $fromSide = trim((string)($src['table'] ?? ''));
                $fromBody = trim((string)($this->body['table'] ?? ''));
                $pick = $fromSide !== '' ? $fromSide : $fromBody;
                if ($pick !== '') {
                    $tables = [$pick];
                }
            }
            Response::json(DiffService::schemaDiff($srcConn, $srcDb, $tgtConn, $tgtDb, $tables));
            return;
        }

        if ($this->path === '/api/diff/data' && $this->method === 'POST') {
            [$src, $tgt] = $this->parseDiffSides();
            $srcId = (string)($src['connectionId'] ?? '');
            $tgtId = (string)($tgt['connectionId'] ?? '');
            $srcDb = (string)($src['database'] ?? '');
            $tgtDb = (string)($tgt['database'] ?? '');
            $srcTable = (string)($src['table'] ?? '');
            $tgtTableRaw = isset($tgt['table']) ? (string)$tgt['table'] : '';
            $tgtTable = $tgtTableRaw !== '' ? $tgtTableRaw : $srcTable;
            if ($srcId === '' || $tgtId === '' || $srcDb === '' || $tgtDb === '' || $srcTable === '' || $tgtTable === '') {
                Response::error('source and target require connectionId, database and table', 400);
                return;
            }

            $srcConn = AuthService::requireConnectionAccess($user, $srcId, 'viewer')['conn'];
            $tgtConn = AuthService::requireConnectionAccess($user, $tgtId, 'viewer')['conn'];
            $page = (int)($this->body['pageSize'] ?? 500);
            $sample = (int)($this->body['maxSampleDiffRows'] ?? 200);
            Response::json(DiffService::dataDiff($srcConn, $srcDb, $srcTable, $tgtConn, $tgtDb, $tgtTable, $page, $sample));
            return;
        }

        Response::error('Not found', 404);
    }

    /** @return array{0:array<string,mixed>,1:array<string,mixed>} */
    private function parseDiffSides(): array
    {
        $src = (array)($this->body['source'] ?? $this->body['left'] ?? []);
        $tgt = (array)($this->body['target'] ?? $this->body['right'] ?? []);
        return [$src, $tgt];
    }

    private function dispatchAudit(): void
    {
        AuthService::requireAdmin();

        // GET /api/audit — paginated list
        if ($this->path === '/api/audit' && $this->method === 'GET') {
            $page   = max(1, (int)($_GET['page'] ?? 1));
            $limit  = min(200, max(1, (int)($_GET['limit'] ?? 50)));
            $offset = ($page - 1) * $limit;
            $action = trim((string)($_GET['action'] ?? ''));
            $target = trim((string)($_GET['target'] ?? ''));

            $where  = [];
            $params = [];
            if ($action !== '') {
                $where[]  = 'e.action LIKE ?';
                $params[] = '%' . $action . '%';
            }
            if ($target !== '') {
                $where[]  = 'e.target LIKE ?';
                $params[] = '%' . $target . '%';
            }
            $whereClause = $where ? 'WHERE ' . implode(' AND ', $where) : '';

            $countSt = App::db()->prepare('SELECT COUNT(*) FROM audit_events e ' . $whereClause);
            $countSt->execute($params);
            $total = (int)$countSt->fetchColumn();

            $st = App::db()->prepare(
                'SELECT e.id, e.user_id AS userId, u.email, e.action, e.target,
                        e.payload_json AS payload, e.created_at AS createdAt
                 FROM audit_events e LEFT JOIN users u ON u.id = e.user_id
                 ' . $whereClause . '
                 ORDER BY e.created_at DESC LIMIT ' . $limit . ' OFFSET ' . $offset
            );
            $st->execute($params);
            $events = array_map(function ($row) {
                $row['payload'] = json_decode((string)($row['payload'] ?? '{}'), true) ?? [];
                return $row;
            }, $st->fetchAll(\PDO::FETCH_ASSOC));

            Response::json([
                'total'  => $total,
                'page'   => $page,
                'limit'  => $limit,
                'pages'  => (int)ceil($total / $limit),
                'events' => $events,
            ]);
            return;
        }

        // DELETE /api/audit — purge old events
        if ($this->path === '/api/audit' && $this->method === 'DELETE') {
            $days   = max(1, (int)($_GET['olderThanDays'] ?? 90));
            $cutoff = date('Y-m-d H:i:s', time() - $days * 86400);
            $st = App::db()->prepare('DELETE FROM audit_events WHERE created_at < ?');
            $st->execute([$cutoff]);
            Response::json(['ok' => true, 'deleted' => $st->rowCount()]);
            return;
        }

        Response::error('Not found', 404);
    }

    private function dispatchHistory(): void
    {
        $user = AuthService::requireUser();

        // GET /api/history/:connId — list
        if (preg_match('#^/api/history/([^/]+)$#', $this->path, $m) && $this->method === 'GET') {
            $connId  = $m[1];
            AuthService::requireConnectionAccess($user, $connId, 'viewer');
            $limit   = min((int)($_GET['limit'] ?? 200), 500);
            $db      = $_GET['database'] ?? null;
            $params  = [$user['id'], $connId];
            $dbWhere = '';
            if ($db !== null) {
                $dbWhere = ' AND database = ?';
                $params[] = $db;
            }
            $st = App::db()->prepare(
                'SELECT id, sql_text AS sql, database, executed_at AS executedAt, duration_ms AS durationMs, affected_rows AS affectedRows
                 FROM sql_history
                 WHERE user_id = ? AND connection_id = ?' . $dbWhere . '
                 ORDER BY executed_at DESC LIMIT ' . $limit
            );
            $st->execute($params);
            Response::json(['history' => $st->fetchAll(\PDO::FETCH_ASSOC)]);
            return;
        }

        // POST /api/history/:connId — append
        if (preg_match('#^/api/history/([^/]+)$#', $this->path, $m) && $this->method === 'POST') {
            $connId = $m[1];
            AuthService::requireConnectionAccess($user, $connId, 'viewer');
            $sql        = (string)($this->body['sql'] ?? '');
            $db         = isset($this->body['database']) ? (string)$this->body['database'] : null;
            $duration   = isset($this->body['durationMs']) ? (int)$this->body['durationMs'] : null;
            $affected   = isset($this->body['affectedRows']) ? (int)$this->body['affectedRows'] : null;
            if ($sql === '') {
                Response::error('sql is required', 400);
                return;
            }
            App::db()->prepare(
                'INSERT INTO sql_history (id, user_id, connection_id, database, sql_text, duration_ms, affected_rows)
                 VALUES (?, ?, ?, ?, ?, ?, ?)'
            )->execute([Id::cuid(), $user['id'], $connId, $db, $sql, $duration, $affected]);
            Response::json(['ok' => true]);
            return;
        }

        // DELETE /api/history/:connId — clear all
        if (preg_match('#^/api/history/([^/]+)$#', $this->path, $m) && $this->method === 'DELETE') {
            $connId = $m[1];
            AuthService::requireConnectionAccess($user, $connId, 'viewer');
            App::db()->prepare('DELETE FROM sql_history WHERE user_id = ? AND connection_id = ?')
                ->execute([$user['id'], $connId]);
            Response::json(['ok' => true]);
            return;
        }

        // DELETE /api/history/:connId/:id — delete single entry
        if (preg_match('#^/api/history/([^/]+)/([^/]+)$#', $this->path, $m) && $this->method === 'DELETE') {
            $connId = $m[1];
            $entryId = $m[2];
            AuthService::requireConnectionAccess($user, $connId, 'viewer');
            App::db()->prepare('DELETE FROM sql_history WHERE id = ? AND user_id = ? AND connection_id = ?')
                ->execute([$entryId, $user['id'], $connId]);
            Response::json(['ok' => true]);
            return;
        }

        Response::error('Not found', 404);
    }

}
