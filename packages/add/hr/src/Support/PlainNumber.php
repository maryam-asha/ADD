<?php

namespace Add\Hr\Support;

/**
 * Quantities leave the package as strings without trailing zeros ("21",
 * "1.5"), the same shape ADD uses for money, rounded to two decimals.
 */
final class PlainNumber
{
    public static function of(float|int|string|null $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $formatted = number_format(round((float) $value, 2), 2, '.', '');
        $formatted = rtrim(rtrim($formatted, '0'), '.');

        return $formatted === '-0' ? '0' : $formatted;
    }
}
