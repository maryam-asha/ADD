<?php

namespace Add\Hr\Timesheets\Models;

use Add\Hr\Support\Casts\DateOnly;
use Add\Hr\Support\Casts\PlainDecimal;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TimesheetEntry extends Model
{
    protected $table = 'hr_timesheet_entries';

    protected $fillable = [
        'timesheet_id',
        'cost_center_id',
        'work_date',
        'hours',
        'note',
    ];

    protected $casts = [
        'work_date' => DateOnly::class,
        'hours' => PlainDecimal::class,
    ];

    public function timesheet(): BelongsTo
    {
        return $this->belongsTo(Timesheet::class);
    }

    public function costCenter(): BelongsTo
    {
        return $this->belongsTo(CostCenter::class);
    }
}
