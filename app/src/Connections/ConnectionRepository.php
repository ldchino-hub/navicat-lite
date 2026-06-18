<?php
declare(strict_types=1);

namespace Navicat\Connections;

use Navicat\App;
use Navicat\Util\Crypto;
use Navicat\Util\Id;
use PDO;

final class ConnectionRepository
{
    /**
     * Export all connections without passwords — suitable for JSON backup / sharing.
     *
     * @return array<string,mixed>
     */
    public static function exportPublic(bool $includePasswords = false): array
    {
        $rows = App::db()->query('SELECT * FROM connections ORDER BY name')->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $items = [];
        foreach ($rows as $row) {
            $meta = json_decode((string)($row['meta_json'] ?? '{}'), true) ?: [];
            $item = [
                'name' => (string)$row['name'],
                'engine' => (string)$row['engine'],
                'host' => (string)$row['host'],
                'port' => (int)$row['port'],
                'username' => (string)$row['username'],
                'defaultDb' => $row['default_db'],
                'sslMode' => $row['ssl_mode'],
                'useVpn' => (bool)$row['use_vpn'],
                'configGroup' => $row['config_group'],
                'metaJson' => $meta,
            ];
            if ($includePasswords) {
                $item['password'] = \Navicat\Crypto\Crypto::decrypt((string)($row['password_enc'] ?? ''));
            }
            $items[] = $item;
        }
        return ['version' => 1, 'exportedAt' => gmdate(DATE_ATOM), 'connections' => $items];
    }

    /**
     * @param list<mixed>|array<string,mixed> $payload
     * @return array{created:list<string>,skipped:list<string>}
     */
    public static function import(array $payload): array
    {
        $items = isset($payload['connections']) && is_array($payload['connections'])
            ? $payload['connections']
            : (array_values($payload) === $payload ? $payload : []);

        $created = [];
        $skipped = [];

        foreach ($items as $i => $entry) {
            if (!is_array($entry)) {
                $skipped[] = 'invalid_entry_' . $i;
                continue;
            }
            $name = trim((string)($entry['name'] ?? ''));
            if ($name === '') {
                $skipped[] = 'missing_name_' . $i;
                continue;
            }
            $chk = App::db()->prepare('SELECT id FROM connections WHERE name = ?');
            $chk->execute([$name]);
            if ($chk->fetch()) {
                throw new \RuntimeException("Connection name already exists: {$name}", 409);
            }

            $password = (string)($entry['password'] ?? '');
            if ($password === '') {
                throw new \RuntimeException("Password required for connection: {$name}", 400);
            }

            $input = [
                'name' => $name,
                'engine' => (string)($entry['engine'] ?? 'mysql'),
                'host' => (string)($entry['host'] ?? ''),
                'port' => (int)($entry['port'] ?? 3306),
                'username' => (string)($entry['username'] ?? ''),
                'password' => $password,
                'defaultDb' => $entry['defaultDb'] ?? null,
                'sslMode' => $entry['sslMode'] ?? 'preferred',
                'useVpn' => !empty($entry['useVpn']),
                'configGroup' => $entry['configGroup'] ?? null,
            ];

            foreach (['host', 'engine', 'username'] as $rq) {
                if (empty($input[$rq])) {
                    throw new \RuntimeException("Missing {$rq} for connection: {$name}", 400);
                }
            }

            $conn = self::create($input);

            $meta = $entry['metaJson'] ?? $entry['meta_json'] ?? null;
            if (is_array($meta) && $meta !== []) {
                App::db()->prepare("UPDATE connections SET meta_json = ?, updated_at = datetime('now') WHERE id = ?")
                    ->execute([json_encode($meta), $conn['id']]);
            }

            $created[] = (string)$conn['id'];
        }

        return ['created' => $created, 'skipped' => $skipped];
    }

    public static function assignGroup(string $id, ?string $groupId): array
    {
        $conn = self::find($id);
        if (!$conn) {
            throw new \RuntimeException('Connection not found', 404);
        }
        if ($groupId !== null) {
            $g = ConnectionGroupRepository::find($groupId);
            if (!$g) {
                throw new \RuntimeException('Group not found', 404);
            }
            self::updateMeta($id, ['sidebarGroupId' => $groupId]);
        } else {
            $meta = json_decode((string)($conn['meta_json'] ?? '{}'), true) ?: [];
            unset($meta['sidebarGroupId']);
            App::db()->prepare("UPDATE connections SET meta_json = ?, updated_at = datetime('now') WHERE id = ?")
                ->execute([json_encode($meta), $id]);
        }
        return self::toPublic(self::findOrFail($id));
    }

    /** @return array<string,mixed> */
    private static function findOrFail(string $id): array
    {
        $c = self::find($id);
        if (!$c) {
            throw new \RuntimeException('Connection not found', 404);
        }
        return $c;
    }

    /** @return array<int,array<string,mixed>> */
    public static function listPublic(): array
    {
        $rows = App::db()->query('SELECT * FROM connections ORDER BY name')->fetchAll();
        return array_map([self::class, 'toPublic'], $rows);
    }

    public static function find(string $id): ?array
    {
        $st = App::db()->prepare('SELECT * FROM connections WHERE id = ?');
        $st->execute([$id]);
        $row = $st->fetch();
        return $row ?: null;
    }

    /** @param array<string,mixed> $input */
    public static function upsertByName(array $input): array
    {
        $name = trim((string)($input['name'] ?? ''));
        if ($name === '') {
            throw new \RuntimeException('Connection name required', 400);
        }
        $st = App::db()->prepare('SELECT id FROM connections WHERE name = ?');
        $st->execute([$name]);
        $row = $st->fetch();
        if ($row) {
            return self::update((string)$row['id'], $input);
        }
        return self::create($input);
    }

    /** @param array<string,mixed> $input */
    public static function create(array $input): array
    {
        $id = Id::cuid();
        $st = App::db()->prepare(
            'INSERT INTO connections (id,name,engine,host,port,username,password_enc,default_db,ssl_mode,use_vpn,config_group,meta_json)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?)'
        );
        $st->execute([
            $id,
            $input['name'],
            $input['engine'],
            $input['host'],
            (int)$input['port'],
            $input['username'],
            Crypto::encrypt((string)($input['password'] ?? '')),
            $input['defaultDb'] ?? null,
            $input['sslMode'] ?? 'preferred',
            !empty($input['useVpn']) ? 1 : 0,
            $input['configGroup'] ?? null,
            self::encodeMeta($input),
        ]);
        return self::toPublic(self::find($id));
    }

    /**
     * Build the meta_json payload, keeping engine-specific options
     * (Mongo: authDb, caFile, tls, tlsAllowInvalid, authMechanism).
     *
     * @param array<string,mixed> $input
     */
    private static function encodeMeta(array $input): string
    {
        $meta = is_array($input['metaJson'] ?? null) ? $input['metaJson'] : [];
        foreach (['authDb', 'caFile', 'tls', 'tlsAllowInvalid', 'authMechanism'] as $k) {
            if (array_key_exists($k, $input)) {
                $meta[$k] = $input[$k];
            }
        }
        return json_encode($meta ?: new \stdClass());
    }

    /** @param array<string,mixed> $input */
    public static function update(string $id, array $input): array
    {
        $conn = self::find($id);
        if (!$conn) throw new \RuntimeException('Connection not found');
        $fields = [];
        $vals = [];
        $map = [
            'name' => 'name', 'engine' => 'engine', 'host' => 'host', 'port' => 'port',
            'username' => 'username', 'defaultDb' => 'default_db', 'sslMode' => 'ssl_mode',
            'useVpn' => 'use_vpn', 'configGroup' => 'config_group',
        ];
        foreach ($map as $in => $col) {
            if (array_key_exists($in, $input)) {
                $fields[] = "$col = ?";
                $vals[] = $in === 'useVpn' ? (!empty($input[$in]) ? 1 : 0) : $input[$in];
            }
        }
        if (!empty($input['password'])) {
            $fields[] = 'password_enc = ?';
            $vals[] = Crypto::encrypt((string)$input['password']);
        }
        // Merge engine-specific options (Mongo) into meta_json.
        $metaKeys = ['authDb', 'caFile', 'tls', 'tlsAllowInvalid', 'authMechanism'];
        $hasMeta = is_array($input['metaJson'] ?? null);
        foreach ($metaKeys as $k) {
            if (array_key_exists($k, $input)) {
                $hasMeta = true;
            }
        }
        if ($hasMeta) {
            $meta = json_decode((string)($conn['meta_json'] ?? '{}'), true) ?: [];
            if (is_array($input['metaJson'] ?? null)) {
                $meta = array_merge($meta, $input['metaJson']);
            }
            foreach ($metaKeys as $k) {
                if (array_key_exists($k, $input)) {
                    $meta[$k] = $input[$k];
                }
            }
            $fields[] = 'meta_json = ?';
            $vals[] = json_encode($meta ?: new \stdClass());
        }
        if ($fields) {
            $fields[] = "updated_at = datetime('now')";
            $vals[] = $id;
            App::db()->prepare('UPDATE connections SET ' . implode(', ', $fields) . ' WHERE id = ?')->execute($vals);
        }
        return self::toPublic(self::find($id));
    }

    public static function delete(string $id): void
    {
        App::db()->prepare('DELETE FROM backups WHERE connection_id = ?')->execute([$id]);
        App::db()->prepare('DELETE FROM connection_grants WHERE connection_id = ?')->execute([$id]);
        App::db()->prepare('DELETE FROM saved_queries WHERE connection_id = ?')->execute([$id]);
        App::db()->prepare('DELETE FROM connections WHERE id = ?')->execute([$id]);
    }

    /** @param array<string,mixed> $row */
    public static function credentials(array $row): array
    {
        return [
            'host' => $row['host'],
            'port' => (int)$row['port'],
            'username' => $row['username'],
            'password' => Crypto::decrypt($row['password_enc']),
            'defaultDb' => $row['default_db'],
            'sslMode' => $row['ssl_mode'],
            'configGroup' => $row['config_group'],
            'useVpn' => (bool)$row['use_vpn'],
            'engine' => $row['engine'],
            'meta' => json_decode((string)($row['meta_json'] ?? '{}'), true) ?: [],
        ];
    }

    /** @param array<string,mixed> $row */
    public static function toPublic(array $row): array
    {
        $meta = json_decode((string)($row['meta_json'] ?? '{}'), true) ?: [];
        return [
            'id' => $row['id'],
            'name' => $row['name'],
            'engine' => $row['engine'],
            'host' => $row['host'],
            'port' => (int)$row['port'],
            'username' => $row['username'],
            'defaultDb' => $row['default_db'],
            'sslMode' => $row['ssl_mode'],
            'useVpn' => (bool)$row['use_vpn'],
            'configGroup' => $row['config_group'],
            'metaJson' => $meta,
        ];
    }

    /** @param array<string,mixed> $patch */
    public static function updateMeta(string $id, array $patch): void
    {
        $row = self::find($id);
        if (!$row) throw new \RuntimeException('Connection not found');
        $meta = json_decode((string)($row['meta_json'] ?? '{}'), true) ?: [];
        $meta = array_merge($meta, $patch);
        App::db()->prepare("UPDATE connections SET meta_json = ?, updated_at = datetime('now') WHERE id = ?")
            ->execute([json_encode($meta), $id]);
    }
}
