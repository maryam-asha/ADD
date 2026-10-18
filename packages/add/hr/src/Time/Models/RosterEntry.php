<?php

namespace Add\Hr\Time\Models;

use Add\Hr\Core\Models\Employee;
use Add\Hr\Support\Casts\DateOnly;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One employee's plan for one day: a shift, or rest when the shift is null.
 * Overrides the work pattern and public holidays.
 */
class RosterEntry extends Model
{
    protected $table = 'hr_roster_entries';

    protected $fillable = [
        'employee_id',
        'work_date',
        'shift_template_id',
        'note',
        'created_by',
    ];

    protected $casts = [
        'work_date' => DateOnly::class,
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function shiftTemplate(): BelongsTo
    {
        return $this->belongsTo(ShiftTemplate::class);
    }
}
