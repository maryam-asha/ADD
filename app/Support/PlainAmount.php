<?php

namespace App\Support;

/**
 * A money amount as the API shows it: exact to the cent, with no trailing
 * zeros ("300.00" → "300", "150.50" → "150.5"). Still a string, so a large
 * SYP amount never passes through a float on the way out.
 */
final class PlainAmount
{
    public static function of(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $fixed = is_string($value) && is_numeric($value) && ! str_contains(strtolower($value), 'e')
            ? bcadd($value, '0', 2)
            : number_format((float) $value, 2, '.', '');

        $plain = rtrim(rtrim($fixed, '0'), '.');

        return $plain === '' || $plain === '-0' ? '0' : $plain;
    }
}
