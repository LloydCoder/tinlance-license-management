<?php

declare(strict_types=1);

namespace App\Support;

final class SemanticVersion
{
    private const PATTERN = '/\A(0|[1-9]\d*)\.(0|[1-9]\d*)\.(0|[1-9]\d*)(?:-((?:0|[1-9]\d*|\d*[A-Za-z-][0-9A-Za-z-]*)(?:\.(?:0|[1-9]\d*|\d*[A-Za-z-][0-9A-Za-z-]*))*))?(?:\+([0-9A-Za-z-]+(?:\.[0-9A-Za-z-]+)*))?\z/D';

    public static function isValid(string $version): bool
    {
        return preg_match(self::PATTERN, $version) === 1;
    }
}
