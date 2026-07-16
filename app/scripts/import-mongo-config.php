<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/bootstrap.php';

use Navicat\App;
use Navicat\Connections\ConnectionRepository;

/**
 * Import MongoDB / Amazon DocumentDB connections from ~/.mongo_config.json.
 *
 * Expected format:
 * {
 *   "hosts": [
 *     {"name":"dev","host":"...","port":27017,"username":"root",
 *      "password":"...","auth_db":"admin","ca_file":"global-bundle.pem"}
 *   ]
 * }
 */

$path = getenv('MONGO_CONFIG_PATH') ?: (getenv('HOME') . '/.mongo_config.json');
if (!is_file($path)) {
    fwrite(STDERR, "File not found: {$path}\n");
    exit(1);
}

$data = json_decode((string)file_get_contents($path), true);
if (!is_array($data) || !isset($data['hosts']) || !is_array($data['hosts'])) {
    fwrite(STDERR, "Invalid .mongo_config.json (expected { \"hosts\": [...] })\n");
    exit(1);
}

$imported = 0;
$skipped = 0;
foreach ($data['hosts'] as $h) {
    if (!is_array($h)) {
        continue;
    }
    $label = (string)($h['name'] ?? $h['host'] ?? '');
    $name = 'mongodb:' . $label;

    $existing = App::db()->prepare('SELECT id FROM connections WHERE name = ?');
    $existing->execute([$name]);
    if ($existing->fetch()) {
        echo "skip (exists): {$name}\n";
        $skipped++;
        continue;
    }
    if (empty($h['host']) || empty($h['username'])) {
        echo "skip (missing host/username): {$label}\n";
        $skipped++;
        continue;
    }

    ConnectionRepository::create([
        'name' => $name,
        'engine' => 'mongodb',
        'host' => (string)$h['host'],
        'port' => isset($h['port']) ? (int)$h['port'] : 27017,
        'username' => (string)$h['username'],
        'password' => (string)($h['password'] ?? ''),
        'defaultDb' => $h['default_db'] ?? null,
        'sslMode' => 'required',
        'useVpn' => false,
        'configGroup' => $label,
        // Mongo-specific options persisted in meta_json.
        'authDb' => (string)($h['auth_db'] ?? 'admin'),
        'caFile' => (string)($h['ca_file'] ?? 'global-bundle.pem'),
        'tls' => true,
        'authMechanism' => 'auto',
    ]);
    echo "imported: {$name} ({$h['host']}:" . ($h['port'] ?? 27017) . ")\n";
    $imported++;
}

echo "\nDone. Imported {$imported}, skipped {$skipped}.\n";
