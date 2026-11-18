<?php

namespace Add\Pm\Support\Casts;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * A calendar day, stored as `Y-m-d` on every driver. Laravel's own `date`
 * cast writes `Y-m-d H:i:s`, which SQLite keeps, so a comparison against a
 * plain day would stop matching the same day. Storing the plain day keeps
 * string comparison and indexes correct everywhere.
 *
 * @implements CastsAttributes<CarbonImmutable, mixed>
 */
class DateOnly implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): ?CarbonImmutable
    {
        return $value === null ? null : CarbonImmutable::parse($value)->startOfDay();
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        return $value === null ? null : CarbonImmutable::parse($value)->toDateString();
    }
}
