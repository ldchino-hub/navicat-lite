<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/bootstrap.php';

use Navicat\App;
use Navicat\Connections\ConnectionRepository;

function parseIniFile(string $content): array
{
    $groups = [];
    $current = null;
    foreach (preg_split('/\r\n|\n|\r/', $content) as $line) {
        $trimmed = trim($line);
        if ($trimmed === '' || str_starts_with($trimmed, '#') || str_starts_with($trimmed, ';')) continue;
        if (preg_match('/^\[(.+)\]$/', $trimmed, $m)) {
            $groups[] = ['name' => $m[1]];
            continue;
        }
        if ($groups === []) continue;
        $eq = strpos($trimmed, '=');
        if ($eq === false) continue;
        $key = strtolower(trim(substr($trimmed, 0, $eq)));
        $value = trim(substr($trimmed, $eq + 1));
        if ((str_starts_with($value, '"') && str_ends_with($value, '"')) || (str_starts_with($value, "'") && str_ends_with($value, "'"))) {
            $value = substr($value, 1, -1);
        }
        $idx = count($groups) - 1;
        $groups[$idx][$key] = $value;
    }
    return $groups;
}

$path = (string)App::config()['pg_service_path'];
if (!is_file($path)) {
    fwrite(STDERR, "File not found: {$path}\n");
    exit(1);
}
$content = file_get_contents($path);
$groups = parseIniFile($content ?: '');
$imported = 0;
foreach ($groups as $group) {
    $name = 'pg:' . $group['name'];
    $existing = App::db()->prepare('SELECT id FROM connections WHERE name = ?');
    $existing->execute([$name]);
    if ($existing->fetch()) continue;
    if (empty($group['host']) || empty($group['user']) || empty($group['password'])) continue;
    ConnectionRepository::create([
        'name' => $name,
        'engine' => 'postgres',
        'host' => $group['host'],
        'port' => isset($group['port']) ? (int)$group['port'] : 5432,
        'username' => $group['user'],
        'password' => $group['password'],
        'defaultDb' => $group['dbname'] ?? 'postgres',
        'sslMode' => $group['sslmode'] ?? 'prefer',
        'useVpn' => false,
        'configGroup' => $group['name'],
    ]);
    $imported++;
}
echo "Imported {$imported} PostgreSQL connection(s) from {$path}\n";
