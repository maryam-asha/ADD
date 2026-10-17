<?php

namespace Add\Hr\Time\Models;

use Add\Hr\Time\Enums\PeriodStatus;
use Illuminate\Database\Eloquent\Model;

/**
 * A calendar month of attendance. Once locked, nothing in it changes;
 * payroll reads locked months only.
 */
class TimePeriod extends Model
{
    protected $table = 'hr_time_periods';

    protected $guarded = [];

    protected $casts = [
        'year' => 'integer',
        'month' => 'integer',
        'status' => PeriodStatus::class,
        'locked_at' => 'datetime',
    ];
}
