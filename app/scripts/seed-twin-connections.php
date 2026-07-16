<?php
declare(strict_types=1);

/**
 * Seed/update twin connections shared with navicat-web (see scripts/twin-connections.json).
 */
require_once dirname(__DIR__) . '/src/bootstrap.php';

use Navicat\Connections\ConnectionRepository;

$path = dirname(__DIR__, 2) . '/scripts/twin-connections.json';
if (!is_file($path)) {
    fwrite(STDERR, "Missing {$path}\n");
    exit(1);
}

$payload = json_decode((string)file_get_contents($path), true);
if (!is_array($payload) || !isset($payload['connections']) || !is_array($payload['connections'])) {
    fwrite(STDERR, "Invalid twin-connections.json\n");
    exit(1);
}

$upserted = 0;
foreach ($payload['connections'] as $entry) {
    if (!is_array($entry)) {
        continue;
    }
    ConnectionRepository::upsertByName([
        'name' => (string)$entry['name'],
        'engine' => (string)$entry['engine'],
        'host' => (string)$entry['host'],
        'port' => (int)$entry['port'],
        'username' => (string)$entry['username'],
        'password' => (string)($entry['password'] ?? ''),
        'defaultDb' => $entry['defaultDb'] ?? null,
        'sslMode' => $entry['sslMode'] ?? 'preferred',
        'useVpn' => !empty($entry['useVpn']),
        'configGroup' => $entry['configGroup'] ?? null,
    ]);
    $upserted++;
    echo "  ✓ {$entry['name']} ({$entry['engine']})\n";
}

echo "PHP: upserted {$upserted} twin connection(s).\n";
