<?php
declare(strict_types=1);

namespace Navicat\Util;

use Navicat\App;

final class Crypto
{
    public static function encrypt(string $text): string
    {
        $key = hex2bin((string)App::config()['meta_enc_key']);
        if ($key === false || strlen($key) !== 32) {
            throw new \RuntimeException('meta_enc_key must be 64 hex chars');
        }
        $iv = random_bytes(12);
        $tag = '';
        $encrypted = openssl_encrypt($text, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
        if ($encrypted === false) throw new \RuntimeException('encrypt failed');
        return bin2hex($iv) . ':' . bin2hex($tag) . ':' . bin2hex($encrypted);
    }

    public static function decrypt(string $payload): string
    {
        $parts = explode(':', $payload, 3);
        if (count($parts) !== 3) throw new \InvalidArgumentException('Invalid encrypted payload');
        [$ivHex, $tagHex, $dataHex] = $parts;
        $key = hex2bin((string)App::config()['meta_enc_key']);
        if ($key === false || strlen($key) !== 32) {
            throw new \RuntimeException('meta_enc_key must be 64 hex chars');
        }
        $plain = openssl_decrypt(hex2bin($dataHex), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, hex2bin($ivHex), hex2bin($tagHex));
        if ($plain === false) throw new \RuntimeException('decrypt failed');
        return $plain;
    }
}
