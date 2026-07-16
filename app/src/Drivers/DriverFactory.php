<?php
declare(strict_types=1);

namespace Navicat\Drivers;

use Navicat\Connections\ConnectionRepository;
use Navicat\Services\VpnService;

final class DriverFactory
{
    /** @param array<string,mixed> $connRow */
    public static function getDriver(array $connRow): MySqlDriver|BridgeMySqlDriver|PostgresDriver|MongoDriver
    {
        VpnService::ensureForConnection($connRow);
        $creds = ConnectionRepository::credentials($connRow);
        return match ($creds['engine']) {
            'postgres' => new PostgresDriver($creds),
            'mongodb' => new MongoDriver($creds),
            default => self::mysqlDriver($creds),
        };
    }

    /** @param array<string,mixed> $creds */
    public static function fromCreds(array $creds): MySqlDriver|BridgeMySqlDriver|PostgresDriver|MongoDriver
    {
        return match ($creds['engine'] ?? 'mysql') {
            'postgres' => new PostgresDriver($creds),
            'mongodb' => new MongoDriver($creds),
            default => self::mysqlDriver($creds),
        };
    }

    /** @param array<string,mixed> $creds */
    private static function mysqlDriver(array $creds): MySqlDriver|BridgeMySqlDriver
    {
        if (BridgeMySqlDriver::enabledFor($creds)) {
            $url = trim((string)getenv('MYSQL_BRIDGE_URL'));
            $key = (string)(getenv('MYSQL_BRIDGE_KEY') ?: '');
            return new BridgeMySqlDriver($creds, $url, $key);
        }
        return new MySqlDriver($creds);
    }
}
