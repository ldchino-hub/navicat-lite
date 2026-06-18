<?php
declare(strict_types=1);

namespace Navicat\Support;

use PDO;

/** MySQL PDO driver options compatible with PHP 8.5+ (Pdo\Mysql) and older releases. */
final class PdoMysqlOptions
{
    /**
     * @return array<int|string,mixed>
     */
    public static function build(bool $multiStatements, ?string $sslMode = null): array
    {
        $opts = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => true,
            PDO::ATTR_STRINGIFY_FETCHES => true,
        ];

        if ($multiStatements) {
            $opts[self::attrMultiStatements()] = true;
        }

        if (($sslMode ?? 'preferred') !== 'disabled') {
            $opts[self::attrSslVerifyServerCert()] = false;
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

    private static function attrSslVerifyServerCert(): int
    {
        if (PHP_VERSION_ID >= 80500 && class_exists(\Pdo\Mysql::class)) {
            return \Pdo\Mysql::ATTR_SSL_VERIFY_SERVER_CERT;
        }

        return PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT;
    }
}
