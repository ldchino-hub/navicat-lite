<?php
declare(strict_types=1);

namespace Navicat\Mongo;

/** Explicit BSON int64 / timestamp wrapper (PHP int is 64-bit on this host). */
final class Int64 implements \JsonSerializable
{
    public function __construct(public readonly int $value)
    {
    }

    public function __toString(): string
    {
        return (string)$this->value;
    }

    public function jsonSerialize(): int
    {
        return $this->value;
    }
}
