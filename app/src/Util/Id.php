<?php
declare(strict_types=1);

namespace Navicat\Util;

final class Id
{
    public static function cuid(): string
    {
        return bin2hex(random_bytes(12));
    }
}
