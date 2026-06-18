<?php
declare(strict_types=1);

namespace Navicat\Mongo;

/** BSON UTC datetime: milliseconds since the Unix epoch. */
final class UTCDateTime implements \JsonSerializable
{
    public function __construct(public readonly int $milliseconds)
    {
    }

    public function toIso8601(): string
    {
        $sec = intdiv($this->milliseconds, 1000);
        $ms = $this->milliseconds - $sec * 1000;
        return gmdate('Y-m-d\TH:i:s', $sec) . sprintf('.%03dZ', $ms);
    }

    public function __toString(): string
    {
        return $this->toIso8601();
    }

    public function jsonSerialize(): array
    {
        return ['$date' => $this->toIso8601()];
    }
}
