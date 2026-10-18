<?php

namespace Add\Hr\Time\Models;

use Add\Hr\Approvals\Models\ApprovalRequest;
use Add\Hr\Core\Models\Employee;
use Add\Hr\Support\Casts\DateOnly;
use Add\Hr\Time\Enums\OvertimeKind;
use Add\Hr\Time\Enums\OvertimeStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Overtime asked for ahead (pre) or justified afterwards (post). Payroll
 * pays approved minutes only.
 */
class OvertimeRequest extends Model
{
    protected $table = 'hr_overtime_requests';

    protected $fillable = [
        'employee_id',
        'work_date',
        'minutes',
        'kind',
        'reason',
        'status',
        'approval_request_id',
        'submitted_by',
    ];

    protected $casts = [
        'work_date' => DateOnly::class,
        'minutes' => 'integer',
        'kind' => OvertimeKind::class,
        'status' => OvertimeStatus::class,
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
