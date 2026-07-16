<?php
declare(strict_types=1);

namespace Navicat\Mongo;

/** BSON regular expression. */
final class Regex implements \JsonSerializable
{
    public function __construct(
        public readonly string $pattern,
        public readonly string $flags = ''
    ) {
    }

    public function jsonSerialize(): array
    {
        return ['$regularExpression' => ['pattern' => $this->pattern, 'options' => $this->flags]];
    }
}
