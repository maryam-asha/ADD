<?php

namespace Add\Hr\Support\Casts;

use Add\Hr\Support\Decimal;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * A DECIMAL column as an exact string without trailing zeros — no
 * rounding, unlike PlainDecimal (money rates keep their four places).
 *
 * @implements CastsAttributes<string, mixed>
 */
class DecimalString implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        return $value === null ? null : Decimal::trim((string) $value);
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        return $value === null ? null : Decimal::trim(Decimal::of($value));
    }
}
