<?php
declare(strict_types=1);

namespace Navicat\Mongo;

/** 12-byte MongoDB ObjectId, represented as a 24-char hex string. */
final class ObjectId implements \JsonSerializable
{
    private string $hex;

    public function __construct(?string $hex = null)
    {
        if ($hex === null) {
            $this->hex = self::generateHex();
            return;
        }
        $hex = strtolower($hex);
        if (!preg_match('/^[0-9a-f]{24}$/', $hex)) {
            throw new \InvalidArgumentException("Invalid ObjectId: {$hex}");
        }
        $this->hex = $hex;
    }

    public function hex(): string
    {
        return $this->hex;
    }

    public function bytes(): string
    {
        return hex2bin($this->hex);
    }

    public function __toString(): string
    {
        return $this->hex;
    }

    /** Match the extended-JSON shape MongoDB tools use. */
    public function jsonSerialize(): array
    {
        return ['$oid' => $this->hex];
    }

    private static function generateHex(): string
    {
        // 4-byte timestamp + 5-byte random + 3-byte counter
        static $counter = null;
        if ($counter === null) {
            $counter = random_int(0, 0xFFFFFF);
        }
        $counter = ($counter + 1) & 0xFFFFFF;
        return bin2hex(pack('N', time()))
            . bin2hex(random_bytes(5))
            . substr(bin2hex(pack('N', $counter)), 2);
    }
}
