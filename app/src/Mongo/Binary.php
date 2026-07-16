<?php
declare(strict_types=1);

namespace Navicat\Mongo;

/** BSON binary value (subtype 0x00 generic, 0x04 UUID, etc.). */
final class Binary implements \JsonSerializable
{
    public function __construct(
        public readonly string $data,
        public readonly int $subtype = 0
    ) {
    }

    public function jsonSerialize(): array
    {
        // UUID subtype -> canonical UUID string; otherwise hex.
        if ($this->subtype === 0x04 && strlen($this->data) === 16) {
            $h = bin2hex($this->data);
            return ['$uuid' => sprintf(
                '%s-%s-%s-%s-%s',
                substr($h, 0, 8), substr($h, 8, 4), substr($h, 12, 4),
                substr($h, 16, 4), substr($h, 20, 12)
            )];
        }
        return ['$binary' => ['base64' => base64_encode($this->data), 'subType' => sprintf('%02x', $this->subtype)]];
    }
}
