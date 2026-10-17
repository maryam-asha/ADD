<?php

namespace Add\Hr\Time\Models;

use Add\Hr\Support\Casts\DateOnly;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * Attendance rules over [effective_from, effective_to].
 */
class AttendancePolicy extends Model
{
    protected $table = 'hr_attendance_policies';

    protected $guarded = [];

    protected $casts = [
        'effective_from' => DateOnly::class,
        'effective_to' => DateOnly::class,
        'grace_minutes' => 'integer',
        'early_leave_grace_minutes' => 'integer',
        'min_overtime_minutes' => 'integer',
        'overtime_requires_pre_approval' => 'boolean',
        'entry_tolerance_minutes' => 'integer',
    ];

    /**
     * The policy in force that day, else one built from hr.attendance
     * defaults (not saved).
     */
    public static function forDay(CarbonInterface $day): self
    {
        $date = $day->toDateString();

        return static::query()
            ->where('effective_from', '<=', $date)
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>=', $date))
            ->latest('effective_from')
            ->latest('id')
            ->first()
            ?? new static([
                'grace_minutes' => (int) config('hr.attendance.grace_minutes', 10),
                'early_leave_grace_minutes' => (int) config('hr.attendance.early_leave_grace_minutes', 10),
                'min_overtime_minutes' => (int) config('hr.attendance.min_overtime_minutes', 30),
                'overtime_requires_pre_approval' => (bool) config('hr.attendance.overtime_requires_pre_approval', false),
                'entry_tolerance_minutes' => (int) config('hr.attendance.entry_tolerance_minutes', 15),
            ]);
    }
}
