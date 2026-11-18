<?php

namespace Add\Hr\Timesheets\Models;

use Add\Hr\Approvals\Models\ApprovalRequest;
use Add\Hr\Core\Models\Employee;
use Add\Hr\Support\Casts\DateOnly;
use Add\Hr\Support\Casts\PlainDecimal;
use Add\Hr\Timesheets\Enums\TimesheetStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One employee's week of hours by cost center. Changed only by
 * TimesheetService and its approval handler.
 */
class Timesheet extends Model
{
    protected $table = 'hr_timesheets';

    protected $fillable = [
        'employee_id',
        'week_start',
        'status',
        'total_hours',
        'approval_request_id',
        'submitted_at',
        'submitted_by',
    ];

    protected $casts = [
        'week_start' => DateOnly::class,
        'status' => TimesheetStatus::class,
        'total_hours' => PlainDecimal::class,
        'submitted_at' => 'datetime',
    ];

    public function weekEnd(): CarbonImmutable
    {
        return $this->week_start->addDays(6);
    }

    public function isEditable(): bool
    {
        return in_array($this->status, [TimesheetStatus::Draft, TimesheetStatus::Rejected], true);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function entries(): HasMany
    {
        return $this->hasMany(TimesheetEntry::class)->orderBy('work_date')->orderBy('id');
    }

    public function approval(): BelongsTo
    {
        return $this->belongsTo(ApprovalRequest::class, 'approval_request_id');
    }
}
