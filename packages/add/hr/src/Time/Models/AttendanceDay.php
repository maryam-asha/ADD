<?php

namespace Add\Hr\Time\Models;

use Add\Hr\Core\Models\Employee;
use Add\Hr\Support\Casts\DateOnly;
use Add\Hr\Time\Enums\DayStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One employee's work date as AttendanceProcessor last computed it from
 * the plan and the punches. Rebuilt, never edited by hand.
 */
class AttendanceDay extends Model
{
    protected $table = 'hr_attendance_days';

    protected $fillable = [
        'employee_id',
        'work_date',
        'status',
        'shift_template_id',
        'scheduled_start',
        'scheduled_end',
        'first_in',
        'last_out',
        'punch_count',
        'worked_minutes',
        'late_minutes',
        'early_leave_minutes',
        'overtime_minutes',
    ];

    protected $casts = [
        'work_date' => DateOnly::class,
        'status' => DayStatus::class,
        'scheduled_start' => 'immutable_datetime',
        'scheduled_end' => 'immutable_datetime',
        'first_in' => 'immutable_datetime',
        'last_out' => 'immutable_datetime',
        'punch_count' => 'integer',
        'worked_minutes' => 'integer',
        'late_minutes' => 'integer',
        'early_leave_minutes' => 'integer',
        'overtime_minutes' => 'integer',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function shiftTemplate(): BelongsTo
    {
        return $this->belongsTo(ShiftTemplate::class);
    }

    public function exceptions(): HasMany
    {
        return $this->hasMany(AttendanceException::class);
    }
}
