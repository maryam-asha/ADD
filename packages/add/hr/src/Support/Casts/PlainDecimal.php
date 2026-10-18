<?php

namespace Add\Hr\Support\Casts;

use Add\Hr\Support\PlainNumber;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * A DECIMAL column read as a plain string ("1.5", not "1.50").
 *
 * @implements CastsAttributes<string, mixed>
 */
class PlainDecimal implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        return PlainNumber::of($value);
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        return PlainNumber::of($value);
    }
}
