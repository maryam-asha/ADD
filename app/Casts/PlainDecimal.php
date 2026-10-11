<?php

namespace App\Casts;

use App\Support\PlainAmount;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * A money column: DECIMAL(…, 2) in the database, a plain string without
 * trailing zeros going out ("300.00" → "300"). Exact — bcmath, never a float.
 */
class PlainDecimal implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        return PlainAmount::of($value);
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        if (! is_int($value) && ! is_string($value) && ! is_float($value)) {
            throw new InvalidArgumentException("{$key} must be a numeric amount.");
        }

        $string = is_float($value) ? number_format($value, 2, '.', '') : (string) $value;

        if (! is_numeric($string) || str_contains(strtolower($string), 'e')) {
            throw new InvalidArgumentException("{$key} must be a numeric amount.");
        }

        return bcadd($string, '0', 2);
    }
}
