<?php
declare(strict_types=1);

namespace Navicat\Services;

use Navicat\App;
use Navicat\Database;
use Navicat\Auth\AuthService;
use Navicat\Connections\ConnectionRepository;
use Navicat\Drivers\DriverFactory;
use Navicat\Util\Id;

final class SchedulerService
{
    private const ALLOWED_CRON = ['every_15min', 'hourly', 'daily', 'weekly'];
    private const LOCK_TTL_SEC = 90;
    private const MAYBE_TICK_DEBOUNCE_SEC = 30;
    private const TICK_INTERVAL_SEC = 60;

    /** @return list<array<string,mixed>> */
    public static function listForUser(string $userId): array
    {
        $st = App::db()->prepare(
            'SELECT id, title, type, cron_expr AS cronExpr, payload_json AS payloadJson,
                    enabled, last_run_at AS lastRunAt, next_run_at AS nextRunAt,
                    created_at AS createdAt, updated_at AS updatedAt
             FROM scheduled_jobs WHERE user_id = ? ORDER BY created_at DESC'
        );
        $st->execute([$userId]);
        $rows = $st->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        foreach ($rows as &$row) {
            $row['enabled'] = (bool)($row['enabled'] ?? 0);
            $row['payload'] = json_decode((string)($row['payloadJson'] ?? '{}'), true) ?: [];
            unset($row['payloadJson']);
        }
        return $rows;
    }

    /** @param array<string,mixed> $body @return array<string,mixed> */
    public static function create(string $userId, array $body): array
    {
        $title = trim((string)($body['title'] ?? ''));
        $type = (string)($body['type'] ?? '');
        $cron = (string)($body['cronExpr'] ?? $body['cron_expr'] ?? '');
        $payload = (array)($body['payload'] ?? []);
        $enabled = ($body['enabled'] ?? true) !== false;

        if ($title === '' || !in_array($type, ['sql', 'backup'], true)) {
            throw new \InvalidArgumentException('Invalid job title or type');
        }
        self::validateCron($cron);
        self::validatePayload($type, $payload);

        $id = Id::cuid();
        $next = self::computeNextRun($cron);
        App::db()->prepare(
            'INSERT INTO scheduled_jobs (id, user_id, title, type, cron_expr, payload_json, enabled, next_run_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $id,
            $userId,
            $title,
            $type,
            $cron,
            json_encode($payload, JSON_UNESCAPED_UNICODE),
            $enabled ? 1 : 0,
            $next,
        ]);

        return self::getById($userId, $id);
    }

    /** @param array<string,mixed> $body @return array<string,mixed> */
    public static function update(string $userId, string $jobId, array $body): array
    {
        $job = self::requireJob($userId, $jobId);
        $title = isset($body['title']) ? trim((string)$body['title']) : (string)$job['title'];
        $cron = (string)($body['cronExpr'] ?? $body['cron_expr'] ?? $job['cronExpr']);
        $payload = isset($body['payload']) ? (array)$body['payload'] : (array)$job['payload'];
        $enabled = array_key_exists('enabled', $body) ? ($body['enabled'] !== false) : (bool)$job['enabled'];
        $type = (string)$job['type'];

        if ($title === '') {
            throw new \InvalidArgumentException('Title required');
        }
        self::validateCron($cron);
        self::validatePayload($type, $payload);

        $next = self::computeNextRun($cron);
        App::db()->prepare(
            'UPDATE scheduled_jobs SET title = ?, cron_expr = ?, payload_json = ?, enabled = ?,
             next_run_at = ?, updated_at = datetime(\'now\') WHERE id = ? AND user_id = ?'
        )->execute([
            $title,
            $cron,
            json_encode($payload, JSON_UNESCAPED_UNICODE),
            $enabled ? 1 : 0,
            $next,
            $jobId,
            $userId,
        ]);

        return self::getById($userId, $jobId);
    }

    public static function delete(string $userId, string $jobId): void
    {
        self::requireJob($userId, $jobId);
        App::db()->prepare('DELETE FROM scheduled_jobs WHERE id = ? AND user_id = ?')->execute([$jobId, $userId]);
    }

    /** @return array<string,mixed> */
    public static function runNow(string $userId, string $jobId): array
    {
        $job = self::requireJob($userId, $jobId);
        return self::executeJob($job);
    }

    /** @return array{ran: int, skipped: bool, source: string} */
    public static function tick(string $source = 'guardian'): array
    {
        if (!self::acquireLock($source)) {
            return ['ran' => 0, 'skipped' => true, 'source' => $source];
        }

        $ran = 0;
        try {
            $now = gmdate('Y-m-d H:i:s');
            $st = App::db()->prepare(
                'SELECT id, user_id, title, type, cron_expr AS cronExpr, payload_json AS payloadJson, enabled
                 FROM scheduled_jobs WHERE enabled = 1 AND next_run_at IS NOT NULL AND next_run_at <= ?'
            );
            $st->execute([$now]);
            $jobs = $st->fetchAll(\PDO::FETCH_ASSOC) ?: [];
            foreach ($jobs as $row) {
                $row['payload'] = json_decode((string)($row['payloadJson'] ?? '{}'), true) ?: [];
                unset($row['payloadJson']);
                $row['cronExpr'] = (string)($row['cronExpr'] ?? 'hourly');
                $row['enabled'] = true;
                try {
                    self::executeJob($row);
                    $ran++;
                } catch (\Throwable $e) {
                    error_log('Scheduler job ' . ($row['id'] ?? '') . ' failed: ' . $e->getMessage());
                }
            }
            self::recordTickState($source, $ran);
            return ['ran' => $ran, 'skipped' => false, 'source' => $source];
        } finally {
            self::releaseLock();
        }
    }

    /** Opportunistic tick on API traffic (debounced). */
    public static function maybeTick(string $source = 'api'): void
    {
        if ($source === 'api') {
            $last = (int)(self::getState('last_attempt_at') ?? '0');
            if (time() - $last < self::MAYBE_TICK_DEBOUNCE_SEC) {
                return;
            }
            self::setState('last_attempt_at', (string)time());
        }
        self::tick($source);
    }

    public static function tickIntervalSeconds(): int
    {
        return self::TICK_INTERVAL_SEC;
    }

    /** @return array<string, mixed> */
    public static function getStatus(): array
    {
        self::ensureSchedulerSchema();
        $db = App::db();
        $lock = $db->query('SELECT acquired_at, holder FROM scheduler_lock WHERE id = 1')->fetch(\PDO::FETCH_ASSOC);
        $active = false;
        $holder = null;
        if (is_array($lock)) {
            $age = time() - (int)($lock['acquired_at'] ?? 0);
            $active = $age < self::LOCK_TTL_SEC;
            $holder = (string)($lock['holder'] ?? '');
        }

        $lastTick = self::getState('last_tick_at');
        $lastRan = (int)(self::getState('last_ran_count') ?? '0');
        $lastSource = self::getState('last_source') ?? '';
        $guardianOk = false;
        if ($lastTick !== null && $lastTick !== '') {
            $ts = strtotime($lastTick . ' UTC');
            $guardianOk = $ts !== false && (time() - $ts) < 120;
        }

        return [
            'active' => $guardianOk,
            'lockHeld' => $active,
            'lockHolder' => $holder,
            'lastTickAt' => $lastTick,
            'lastRanCount' => $lastRan,
            'lastSource' => $lastSource,
            'tickIntervalSec' => self::TICK_INTERVAL_SEC,
            'guardianExpected' => true,
        ];
    }

    private static function ensureSchedulerSchema(): void
    {
        static $checked = false;
        if ($checked) {
            return;
        }
        $checked = true;
        $db = App::db();
        $has = $db->query(
            "SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = 'scheduler_lock'"
        )->fetchColumn();
        if ($has) {
            return;
        }
        $root = dirname(__DIR__, 2);
        Database::migrateAll($db, $root . '/migrations');
        $has = $db->query(
            "SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = 'scheduler_lock'"
        )->fetchColumn();
        if ($has) {
            return;
        }
        // Fallback if migrations dir missing on host (FTP layout)
        $db->exec(
            'CREATE TABLE IF NOT EXISTS scheduler_lock (
                id INTEGER PRIMARY KEY DEFAULT 1 CHECK (id = 1),
                acquired_at INTEGER NOT NULL,
                holder TEXT NOT NULL DEFAULT \'guardian\'
            )'
        );
        $db->exec(
            'CREATE TABLE IF NOT EXISTS scheduler_state (
                key TEXT PRIMARY KEY,
                value TEXT NOT NULL
            )'
        );
    }

    private static function acquireLock(string $holder): bool
    {
        self::ensureSchedulerSchema();
        $db = App::db();
        $now = time();
        $expiry = $now - self::LOCK_TTL_SEC;
        $db->exec('DELETE FROM scheduler_lock WHERE id = 1 AND acquired_at < ' . (int)$expiry);
        $st = $db->prepare('INSERT OR IGNORE INTO scheduler_lock (id, acquired_at, holder) VALUES (1, ?, ?)');
        $st->execute([$now, $holder]);
        return $st->rowCount() > 0;
    }

    private static function releaseLock(): void
    {
        App::db()->exec('DELETE FROM scheduler_lock WHERE id = 1');
    }

    private static function recordTickState(string $source, int $ran): void
    {
        self::setState('last_tick_at', gmdate('Y-m-d H:i:s'));
        self::setState('last_ran_count', (string)$ran);
        self::setState('last_source', $source);
    }

    private static function getState(string $key): ?string
    {
        $st = App::db()->prepare('SELECT value FROM scheduler_state WHERE key = ?');
        $st->execute([$key]);
        $v = $st->fetchColumn();
        return $v === false ? null : (string)$v;
    }

    private static function setState(string $key, string $value): void
    {
        App::db()->prepare(
            'INSERT INTO scheduler_state (key, value) VALUES (?, ?)
             ON CONFLICT(key) DO UPDATE SET value = excluded.value'
        )->execute([$key, $value]);
    }

    /** @return list<array<string,mixed>> */
    public static function listRuns(string $userId, string $jobId, int $limit = 20): array
    {
        self::requireJob($userId, $jobId);
        $st = App::db()->prepare(
            'SELECT r.id, r.status, r.started_at AS startedAt, r.finished_at AS finishedAt, r.log_text AS logText
             FROM scheduled_runs r
             INNER JOIN scheduled_jobs j ON j.id = r.job_id
             WHERE r.job_id = ? AND j.user_id = ?
             ORDER BY r.started_at DESC LIMIT ?'
        );
        $st->execute([$jobId, $userId, $limit]);
        return $st->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    }

    /** @param array<string,mixed> $job @return array<string,mixed> */
    private static function executeJob(array $job): array
    {
        $runId = Id::cuid();
        $started = gmdate('Y-m-d H:i:s');
        $log = [];
        App::db()->prepare(
            'INSERT INTO scheduled_runs (id, job_id, status, started_at) VALUES (?, ?, ?, ?)'
        )->execute([$runId, $job['id'], 'running', $started]);

        try {
            $stUser = App::db()->prepare('SELECT id, email, role FROM users WHERE id = ?');
            $stUser->execute([(string)$job['user_id']]);
            $user = $stUser->fetch(\PDO::FETCH_ASSOC);
            if (!$user) {
                throw new \RuntimeException('Job owner not found');
            }
            $payload = (array)($job['payload'] ?? []);
            $connId = (string)($payload['connectionId'] ?? '');
            if ($connId === '') {
                throw new \RuntimeException('connectionId required in payload');
            }

            if ((string)$job['type'] === 'sql') {
                $sql = trim((string)($payload['sql'] ?? ''));
                $database = isset($payload['database']) ? (string)$payload['database'] : null;
                if ($sql === '') {
                    throw new \RuntimeException('SQL required');
                }
                $access = AuthService::requireConnectionAccess($user, $connId, 'editor');
                $driver = DriverFactory::getDriver($access['conn']);
                $result = $driver->executeMany($sql, $database);
                $log[] = 'SQL executed: ' . count($result['statements'] ?? []) . ' statement(s)';
            } else {
                $database = (string)($payload['database'] ?? '');
                $options = (array)($payload['options'] ?? []);
                if ($database === '') {
                    throw new \RuntimeException('database required for backup job');
                }
                $access = AuthService::requireConnectionAccess($user, $connId, 'dba');
                $conn = $access['conn'];
                $connName = preg_replace('/[^a-zA-Z0-9_-]/', '_', (string)$conn['name']) ?: 'conn';
                $dir = rtrim((string)App::config()['backup_dir'], '/') . '/' . $connName;
                if (!is_dir($dir)) {
                    mkdir($dir, 0755, true);
                }
                $timestamp = gmdate('YmdHi');
                $gzip = ($options['gzip'] ?? true) !== false;
                $ext = ($conn['engine'] ?? '') === 'postgres' ? ($gzip ? 'sql.gz' : 'sql') : ($gzip ? 'sql.gz' : 'sql');
                $filePath = $dir . '/' . $database . '_sched_' . $timestamp . '.' . $ext;
                $backupId = Id::cuid();
                App::db()->prepare(
                    'INSERT INTO backups (id, connection_id, user_id, database_name, file_path, status, options_json)
                     VALUES (?, ?, ?, ?, ?, ?, ?)'
                )->execute([
                    $backupId,
                    $conn['id'],
                    (string)$job['user_id'],
                    $database,
                    $filePath,
                    'running',
                    json_encode($options),
                ]);
                $service = new BackupService();
                $bytes = $service->backup($conn, $database, $filePath, $options, static function (array $ev) use (&$log): void {
                    if (($ev['type'] ?? '') === 'log' && isset($ev['message'])) {
                        $log[] = (string)$ev['message'];
                    }
                });
                $sha256 = is_file($filePath) ? hash_file('sha256', $filePath) : null;
                App::db()->prepare(
                    "UPDATE backups SET status = 'completed', size_bytes = ?, sha256 = ?, finished_at = datetime('now') WHERE id = ?"
                )->execute([$bytes, $sha256, $backupId]);
                $log[] = 'Backup completed: ' . basename($filePath);
                AuditService::log((string)$job['user_id'], 'backup.scheduled', $conn['name'] ?? '', ['database' => $database]);
            }

            $finished = gmdate('Y-m-d H:i:s');
            $logText = implode("\n", $log);
            App::db()->prepare(
                "UPDATE scheduled_runs SET status = 'completed', finished_at = ?, log_text = ? WHERE id = ?"
            )->execute([$finished, $logText, $runId]);

            $cron = (string)($job['cronExpr'] ?? 'hourly');
            App::db()->prepare(
                'UPDATE scheduled_jobs SET last_run_at = ?, next_run_at = ?, updated_at = datetime(\'now\') WHERE id = ?'
            )->execute([$finished, self::computeNextRun($cron, $finished), $job['id']]);

            return ['runId' => $runId, 'status' => 'completed', 'log' => $logText];
        } catch (\Throwable $e) {
            $finished = gmdate('Y-m-d H:i:s');
            $logText = implode("\n", $log) . ($log ? "\n" : '') . 'ERROR: ' . $e->getMessage();
            App::db()->prepare(
                "UPDATE scheduled_runs SET status = 'failed', finished_at = ?, log_text = ? WHERE id = ?"
            )->execute([$finished, $logText, $runId]);
            $cron = (string)($job['cronExpr'] ?? 'hourly');
            App::db()->prepare(
                'UPDATE scheduled_jobs SET last_run_at = ?, next_run_at = ?, updated_at = datetime(\'now\') WHERE id = ?'
            )->execute([$finished, self::computeNextRun($cron, $finished), $job['id']]);
            throw $e;
        }
    }

    private static function validateCron(string $cron): void
    {
        $cron = trim($cron);
        if ($cron === '' || (!in_array($cron, self::ALLOWED_CRON, true) && !self::isFiveFieldCron($cron))) {
            throw new \InvalidArgumentException('Invalid cron expression');
        }
    }

    private static function isFiveFieldCron(string $cron): bool
    {
        return (bool)preg_match('/^[\d\*\/,\-]+\s+[\d\*\/,\-]+\s+[\d\*\/,\-]+\s+[\d\*\/,\-]+\s+[\d\*\/,\-]+$/', $cron);
    }

    /** @param array<string,mixed> $payload */
    private static function validatePayload(string $type, array $payload): void
    {
        if (empty($payload['connectionId'])) {
            throw new \InvalidArgumentException('payload.connectionId required');
        }
        if ($type === 'sql' && trim((string)($payload['sql'] ?? '')) === '') {
            throw new \InvalidArgumentException('payload.sql required for sql jobs');
        }
        if ($type === 'backup' && trim((string)($payload['database'] ?? '')) === '') {
            throw new \InvalidArgumentException('payload.database required for backup jobs');
        }
    }

    private static function computeNextRun(string $cron, ?string $from = null): string
    {
        $base = $from !== null && $from !== '' ? strtotime($from . ' UTC') : time();
        if ($base === false) {
            $base = time();
        }
        $next = match ($cron) {
            'every_15min' => $base + 900,
            'hourly' => $base + 3600,
            'daily' => strtotime('+1 day', $base),
            'weekly' => strtotime('+1 week', $base),
            default => self::parseCronFive($cron, $base),
        };
        return gmdate('Y-m-d H:i:s', $next);
    }

    private static function parseCronFive(string $expr, int $base): int
    {
        $parts = preg_split('/\s+/', trim($expr)) ?: [];
        if (count($parts) !== 5) {
            return $base + 3600;
        }
        if ($parts === ['0', '*', '*', '*', '*']) {
            return strtotime('+1 hour', $base) ?: ($base + 3600);
        }
        if ($parts[0] === '0' && $parts[1] === '0') {
            return strtotime('tomorrow', $base) ?: ($base + 86400);
        }
        return $base + 3600;
    }

    /** @return array<string,mixed> */
    private static function getById(string $userId, string $jobId): array
    {
        $st = App::db()->prepare(
            'SELECT id, user_id, title, type, cron_expr AS cronExpr, payload_json AS payloadJson,
                    enabled, last_run_at AS lastRunAt, next_run_at AS nextRunAt
             FROM scheduled_jobs WHERE id = ? AND user_id = ?'
        );
        $st->execute([$jobId, $userId]);
        $row = $st->fetch(\PDO::FETCH_ASSOC);
        if (!$row) {
            throw new \RuntimeException('Job not found');
        }
        $row['enabled'] = (bool)($row['enabled'] ?? 0);
        $row['payload'] = json_decode((string)($row['payloadJson'] ?? '{}'), true) ?: [];
        unset($row['payloadJson']);
        return $row;
    }

    /** @return array<string,mixed> */
    private static function requireJob(string $userId, string $jobId): array
    {
        return self::getById($userId, $jobId);
    }
}
