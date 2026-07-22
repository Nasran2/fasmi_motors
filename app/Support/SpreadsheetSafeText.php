<?php

namespace App\Support;

final class SpreadsheetSafeText
{
    public static function escape(mixed $value): mixed
    {
        if (is_string($value) && preg_match('/^\s*[=+\-@]/u', $value) === 1) {
            return "'{$value}";
        }

        return $value;
    }

    public static function row(array $values): array
    {
        return array_map(self::escape(...), $values);
    }
}
