<?php

declare(strict_types=1);

namespace App\Support;

final class Validation
{
    public static function isValidEmail(string $value): bool
    {
        return (bool) preg_match('/^[^@\s]+@[^@\s]+\.[^@\s]+$/', trim($value));
    }
}
