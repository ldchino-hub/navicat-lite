<?php
declare(strict_types=1);

namespace Navicat\Util;

use Navicat\App;

final class Jwt
{
    /** @param array<string,mixed> $payload */
    public static function encode(array $payload, int $ttlSeconds = 86400 * 7): string
    {
        $header = ['alg' => 'HS256', 'typ' => 'JWT'];
        $payload['exp'] = time() + $ttlSeconds;
        $segments = [
            self::b64(json_encode($header)),
            self::b64(json_encode($payload)),
        ];
        $signing = implode('.', $segments);
        $sig = hash_hmac('sha256', $signing, (string)App::config()['jwt_secret'], true);
        $segments[] = self::b64($sig);
        return implode('.', $segments);
    }

    /** @return array<string,mixed> */
    public static function decode(string $token): array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) throw new \RuntimeException('Invalid token');
        [$h, $p, $s] = $parts;
        $signing = $h . '.' . $p;
        $expected = self::b64(hash_hmac('sha256', $signing, (string)App::config()['jwt_secret'], true));
        if (!hash_equals($expected, $s)) throw new \RuntimeException('Invalid signature');
        /** @var array<string,mixed> $payload */
        $payload = json_decode(self::ub64($p), true, 512, JSON_THROW_ON_ERROR);
        if (($payload['exp'] ?? 0) < time()) throw new \RuntimeException('Token expired');
        return $payload;
    }

    private static function b64(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private static function ub64(string $data): string
    {
        $pad = 4 - (strlen($data) % 4);
        if ($pad < 4) $data .= str_repeat('=', $pad);
        return base64_decode(strtr($data, '-_', '+/')) ?: '';
    }
}
