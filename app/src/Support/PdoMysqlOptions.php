<?php
declare(strict_types=1);

namespace Navicat\Support;

use PDO;

/** MySQL PDO driver options compatible with PHP 8.5+ (Pdo\Mysql) and older releases. */
final class PdoMysqlOptions
{
    public const DEFAULT_CONNECT_TIMEOUT_SEC = 5;

    public static function connectTimeoutSeconds(): int
    {
        $env = getenv('NAVICAT_PDO_CONNECT_TIMEOUT');
        if ($env !== false && is_numeric($env)) {
            return max(1, (int)$env);
        }
        return self::DEFAULT_CONNECT_TIMEOUT_SEC;
    }

    /**
     * @return array<int|string,mixed>
     */
    public static function build(bool $multiStatements, ?string $sslMode = null, ?string $host = null): array
    {
        $opts = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => true,
            PDO::ATTR_STRINGIFY_FETCHES => true,
            PDO::ATTR_TIMEOUT => self::connectTimeoutSeconds(),
        ];

        if ($multiStatements) {
            $opts[self::attrMultiStatements()] = true;
        }

        $mode = strtolower(trim((string)($sslMode ?? 'preferred')));
        if ($mode === '' || $mode === 'prefer') {
            $mode = 'preferred';
        }
        $useSsl = $mode !== 'disabled' && in_array($mode, ['required', 'verify-ca', 'verify-full'], true);
        if ($useSsl) {
            $opts[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = false;
            $opts[PDO::MYSQL_ATTR_SSL_CA] = '';
        }

        return $opts;
    }

    private static function attrMultiStatements(): int
    {
        if (PHP_VERSION_ID >= 80500 && class_exists(\Pdo\Mysql::class)) {
            return \Pdo\Mysql::ATTR_MULTI_STATEMENTS;
        }

        return PDO::MYSQL_ATTR_MULTI_STATEMENTS;
    }
}
