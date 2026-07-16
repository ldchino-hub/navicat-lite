<?php
declare(strict_types=1);

namespace Navicat\Services;

use Navicat\App;
use Navicat\Util\Id;

final class AuditService
{
    public static function log(?string $userId, string $action, ?string $target = null, array $payload = []): void
    {
        try {
            App::db()->prepare(
                'INSERT INTO audit_events (id, user_id, action, target, payload_json, created_at)
                 VALUES (?, ?, ?, ?, ?, ' . \Navicat\Database::nowSql() . ')'
            )->execute([
                Id::cuid(),
                $userId,
                $action,
                $target,
                json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
            ]);
        } catch (\Throwable) {
            // Audit failures must never break the main flow
        }
    }
}
