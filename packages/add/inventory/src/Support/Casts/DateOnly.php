<?php

namespace Add\Inventory\Support\Casts;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * A calendar day, stored as `Y-m-d` on every driver. Laravel's own `date`
 * cast writes `Y-m-d H:i:s`, which a DATE column truncates on MySQL but
 * SQLite keeps — and then `next_due_on <= '2026-10-24'` stops matching the
 * same day. Storing the plain day keeps string comparison correct
 * everywhere.
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
