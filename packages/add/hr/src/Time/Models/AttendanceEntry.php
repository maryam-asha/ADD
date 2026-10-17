<?php

namespace Add\Hr\Time\Models;

use Add\Hr\Approvals\Models\ApprovalRequest;
use Add\Hr\Core\Models\Employee;
use Add\Hr\Support\Casts\DateOnly;
use Add\Hr\Time\Enums\EntryStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The employee's own arrival / departure times for a day. Confirmed by a
 * card scan near each time, or else decided by their manager.
 */
class AttendanceEntry extends Model
{
    protected $table = 'hr_attendance_entries';

    protected $guarded = [];

    protected $casts = [
        'work_date' => DateOnly::class,
        'check_in_at' => 'immutable_datetime',
        'check_out_at' => 'immutable_datetime',
        'status' => EntryStatus::class,
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function approval(): BelongsTo
    {
        return $this->belongsTo(ApprovalRequest::class, 'approval_request_id');
    }
}
