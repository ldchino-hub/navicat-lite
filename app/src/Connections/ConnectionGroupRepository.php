<?php
declare(strict_types=1);

namespace Navicat\Connections;

use Navicat\App;
use Navicat\Util\Id;
use PDO;

final class ConnectionGroupRepository
{
    /** @return list<array{id:string,name:string,sort_order:int,created_at:string}> */
    public static function list(): array
    {
        $rows = App::db()->query(
            'SELECT id, name, sort_order AS sortOrder, created_at AS createdAt
             FROM connection_groups ORDER BY sort_order ASC, name ASC'
        )->fetchAll(PDO::FETCH_ASSOC);
        return array_map(static function (array $r): array {
            return [
                'id' => (string)$r['id'],
                'name' => (string)$r['name'],
                'sortOrder' => (int)($r['sortOrder'] ?? 0),
                'createdAt' => (string)$r['createdAt'],
            ];
        }, $rows ?: []);
    }

    public static function find(string $id): ?array
    {
        $st = App::db()->prepare('SELECT * FROM connection_groups WHERE id = ?');
        $st->execute([$id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public static function create(string $name): array
    {
        $trim = trim($name);
        if ($trim === '') {
            throw new \RuntimeException('Group name required', 400);
        }
        $id = Id::cuid();
        $st = App::db()->prepare(
            'INSERT INTO connection_groups (id, name, sort_order) VALUES (?, ?, ?)'
        );
        try {
            $st->execute([$id, $trim, self::nextSortOrder()]);
        } catch (\PDOException $e) {
            if (str_contains($e->getMessage(), 'UNIQUE constraint failed')) {
                throw new \RuntimeException('A group with this name already exists', 409);
            }
            throw new \RuntimeException('Failed to create group', 500);
        }
        return self::rowToPublic(self::requireRow($id));
    }

    public static function update(string $id, string $name, ?int $sortOrder = null): array
    {
        $existing = self::find($id);
        if (!$existing) {
            throw new \RuntimeException('Group not found', 404);
        }
        $trim = trim($name);
        if ($trim === '') {
            throw new \RuntimeException('Group name required', 400);
        }
        $sets = ['name = ?'];
        $vals = [$trim];
        if ($sortOrder !== null) {
            $sets[] = 'sort_order = ?';
            $vals[] = $sortOrder;
        }
        $vals[] = $id;
        $st = App::db()->prepare('UPDATE connection_groups SET ' . implode(', ', $sets) . ' WHERE id = ?');
        try {
            $st->execute($vals);
        } catch (\PDOException $e) {
            if (str_contains($e->getMessage(), 'UNIQUE constraint failed')) {
                throw new \RuntimeException('A group with this name already exists', 409);
            }
            throw new \RuntimeException('Failed to update group', 500);
        }
        return self::rowToPublic(self::requireRow($id));
    }

    public static function delete(string $id): void
    {
        if (!self::find($id)) {
            throw new \RuntimeException('Group not found', 404);
        }
        self::unlinkConnectionsFromGroup($id);
        App::db()->prepare('DELETE FROM connection_groups WHERE id = ?')->execute([$id]);
    }

    private static function unlinkConnectionsFromGroup(string $groupId): void
    {
        $st = App::db()->query('SELECT id, meta_json FROM connections');
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($rows as $row) {
            $cid = (string)$row['id'];
            $meta = json_decode((string)($row['meta_json'] ?? '{}'), true) ?: [];
            if (($meta['sidebarGroupId'] ?? null) !== $groupId) {
                continue;
            }
            unset($meta['sidebarGroupId']);
            App::db()->prepare('UPDATE connections SET meta_json = ?, updated_at = ' . \Navicat\Database::nowSql() . ' WHERE id = ?')
                ->execute([json_encode($meta), $cid]);
        }
    }

    private static function nextSortOrder(): int
    {
        $row = App::db()->query('SELECT COALESCE(MAX(sort_order), 0) + 1 AS n FROM connection_groups')->fetch(PDO::FETCH_ASSOC);
        return (int)($row['n'] ?? 1);
    }

    /** @param array<string,mixed> $row */
    private static function rowToPublic(array $row): array
    {
        return [
            'id' => (string)$row['id'],
            'name' => (string)$row['name'],
            'sortOrder' => (int)($row['sort_order'] ?? 0),
            'createdAt' => (string)$row['created_at'],
        ];
    }

    private static function requireRow(string $id): array
    {
        $row = self::find($id);
        if (!$row) {
            throw new \RuntimeException('Group not found', 404);
        }
        return $row;
    }
}
