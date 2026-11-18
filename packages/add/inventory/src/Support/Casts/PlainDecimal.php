<?php

namespace Add\Inventory\Support\Casts;

use Add\Inventory\Support\Decimal;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * A DECIMAL column read as a plain string ("1.5", not "1.500"), rounded to
 * the column's own scale (`PlainDecimal::class.':3'`).
 *
 * @implements CastsAttributes<string, mixed>
 */
class PlainDecimal implements CastsAttributes
{
    private int $places;

    public function __construct(int|string $places = 2)
    {
        $this->places = (int) $places;
    }

    public function get(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        return $value === null ? null : Decimal::round($value, $this->places);
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        return $value === null || $value === '' ? null : Decimal::round($value, $this->places);
    }
}
