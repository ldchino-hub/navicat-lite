<?php
declare(strict_types=1);

namespace Navicat\Auth;

use Navicat\App;
use Navicat\Util\Id;
use Navicat\Util\Jwt;
use PDO;

final class AuthService
{
    /** @return array{token:string,user:array<string,mixed>} */
    public static function login(string $email, string $password): array
    {
        $st = App::db()->prepare('SELECT * FROM users WHERE email = ?');
        $st->execute([$email]);
        $user = $st->fetch();
        if (!$user || !password_verify($password, (string)$user['password_hash'])) {
            throw new \RuntimeException('Invalid credentials');
        }
        $payload = ['id' => $user['id'], 'email' => $user['email'], 'role' => $user['role']];
        return [
            'token' => Jwt::encode($payload),
            'user' => ['id' => $user['id'], 'email' => $user['email'], 'role' => $user['role']],
        ];
    }

    /** @return array<string,mixed>|null */
    public static function userFromRequest(): ?array
    {
        $hdr = navicat_authorization_header();
        if (!preg_match('/^Bearer\s+(.+)$/i', $hdr, $m)) return null;
        try {
            return Jwt::decode(trim($m[1]));
        } catch (\Throwable) {
            return null;
        }
    }

    /** @return array<string,mixed> */
    public static function requireUser(): array
    {
        $u = self::userFromRequest();
        if (!$u) throw new \RuntimeException('Unauthorized', 401);
        return $u;
    }

    public static function requireAdmin(): array
    {
        $u = self::requireUser();
        if (($u['role'] ?? '') !== 'admin') throw new \RuntimeException('Admin required', 403);
        return $u;
    }

    public static function createUser(string $email, string $password, string $role = 'viewer'): array
    {
        $id = Id::cuid();
        $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
        App::db()->prepare('INSERT INTO users (id,email,password_hash,role) VALUES (?,?,?,?)')
            ->execute([$id, $email, $hash, $role]);
        return ['id' => $id, 'email' => $email, 'role' => $role];
    }

    /** @return array<int,array<string,mixed>> */
    public static function listUsers(): array
    {
        return App::db()->query('SELECT id,email,role,created_at AS createdAt FROM users ORDER BY email')->fetchAll();
    }

    /** @param array<string,mixed> $user */
    public static function connectionRole(array $user, string $connectionId): ?string
    {
        if (($user['role'] ?? '') === 'admin') return 'admin';
        $st = App::db()->prepare('SELECT role FROM connection_grants WHERE user_id = ? AND connection_id = ?');
        $st->execute([$user['id'], $connectionId]);
        $row = $st->fetch();
        return $row ? (string)$row['role'] : null;
    }

    public static function hasMinRole(?string $actual, string $required): bool
    {
        $rank = ['viewer' => 1, 'editor' => 2, 'dba' => 3, 'admin' => 4];
        if (!$actual) return false;
        return ($rank[$actual] ?? 0) >= ($rank[$required] ?? 99);
    }

    /** @return array{conn:array<string,mixed>,role:string} */
    public static function requireConnectionAccess(array $user, string $connectionId, string $minRole = 'viewer'): array
    {
        $conn = \Navicat\Connections\ConnectionRepository::find($connectionId);
        if (!$conn) throw new \RuntimeException('Connection not found', 404);
        $role = self::connectionRole($user, $connectionId);
        if (!self::hasMinRole($role, $minRole)) throw new \RuntimeException('Insufficient permissions for this connection', 403);
        return ['conn' => $conn, 'role' => $role ?? 'viewer'];
    }
}
