#!/usr/bin/env php
<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';

use Navicat\Connections\ConnectionRepository;
use Navicat\Drivers\DriverFactory;
use Navicat\Services\VpnService;

$connId = $argv[1] ?? '';
if ($connId === '') {
    $rows = \Navicat\App::db()->query(
        "SELECT id, name FROM connections WHERE use_vpn = 1 OR config_group LIKE '%-vpn' LIMIT 5"
    )->fetchAll(PDO::FETCH_ASSOC);
    if ($rows === []) {
        fwrite(STDERR, "No VPN connections found.\n");
        exit(1);
    }
    $connId = (string)$rows[0]['id'];
    echo "Using connection: {$rows[0]['name']} ({$connId})\n";
}

$conn = ConnectionRepository::find($connId);
echo "VPN before: " . json_encode(VpnService::status()) . "\n";

try {
    $driver = DriverFactory::getDriver($conn);
    $driver->testConnection();
    echo "MySQL testConnection: OK\n";
    $info = $driver->getServerInfo();
    echo "Server: " . ($info['version'] ?? 'unknown') . "\n";
} catch (Throwable $e) {
    fwrite(STDERR, "FAIL: " . $e->getMessage() . "\n");
    exit(1);
} finally {
    VpnService::shutdownCleanup();
    echo "VPN after cleanup: " . json_encode(VpnService::status()) . "\n";
}
