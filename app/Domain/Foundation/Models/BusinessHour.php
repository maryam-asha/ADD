<?php

namespace App\Domain\Foundation\Models;

use App\Domain\Foundation\Enums\DayOfWeek;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row per recurring period. Multiple rows for the same
 * (branch, day_of_week) are how a two-period day (e.g. a midday closure)
 * is expressed — there is no separate "periods" child table. Absence of
 * any row for a given (branch, day_of_week) means closed that day; see
 * App\Domain\Foundation\Services\BusinessHoursService for the resolution
 * that depends on this.
 */
class BusinessHour extends Model
{
    use HasFactory;

    protected $fillable = [
        'branch_id',
        'day_of_week',
        'open_time',
        'close_time',
    ];

    protected function casts(): array
    {
        return [
            'day_of_week' => DayOfWeek::class,
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }
}
