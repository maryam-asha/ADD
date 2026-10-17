<?php

namespace Add\Hr\Time\Models;

use Add\Hr\Core\Models\Employee;
use Add\Hr\Support\Casts\DateOnly;
use Add\Hr\Time\Enums\ExceptionResolution;
use Add\Hr\Time\Enums\ExceptionStatus;
use Add\Hr\Time\Enums\ExceptionType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Something about a day a person has to look at.
 */
class AttendanceException extends Model
{
    protected $table = 'hr_attendance_exceptions';

    protected $fillable = [
        'attendance_day_id',
        'employee_id',
        'work_date',
        'type',
        'status',
        'resolution',
        'note',
        'resolved_by',
        'resolved_at',
    ];

    protected $casts = [
        'work_date' => DateOnly::class,
        'type' => ExceptionType::class,
        'status' => ExceptionStatus::class,
        'resolution' => ExceptionResolution::class,
        'resolved_at' => 'datetime',
    ];

    public function day(): BelongsTo
    {
        return $this->belongsTo(AttendanceDay::class, 'attendance_day_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
