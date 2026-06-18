<?php
declare(strict_types=1);

namespace Navicat\Support;

final class DbJobId
{
    /** @return array{engine: string, db?: string, name?: string, jobid?: int} */
    public static function decode(string $jobId): array
    {
        $raw = base64_decode(strtr($jobId, '-_', '+/'), true);
        if ($raw === false) {
            throw new \InvalidArgumentException('Invalid job id');
        }
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            throw new \InvalidArgumentException('Invalid job id');
        }
        return $data;
    }

    public static function encodeMysql(string $db, string $name): string
    {
        return self::encode(['engine' => 'mysql', 'db' => $db, 'name' => $name]);
    }

    public static function encodePg(int $jobId): string
    {
        return self::encode(['engine' => 'postgres', 'jobid' => $jobId]);
    }

    /** @param array<string, mixed> $payload */
    public static function encode(array $payload): string
    {
        $json = json_encode($payload, JSON_THROW_ON_ERROR);
        return rtrim(strtr(base64_encode($json), '+/', '-_'), '=');
    }
}
