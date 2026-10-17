<?php

namespace Add\Hr\Approvals\Models;

use Add\Hr\Approvals\Enums\ApproverType;
use Add\Hr\Approvals\Enums\DecisionSource;
use Add\Hr\Approvals\Enums\StepStatus;
use Add\Hr\Approvals\Enums\TimeoutAction;
use Add\Hr\Core\Models\Employee;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One step of one request: who may decide it, and what they decided.
 */
class ApprovalStep extends Model
{
    protected $table = 'hr_approval_steps';

    protected $fillable = [
        'approval_request_id',
        'sort',
        'approver_type',
        'approver_employee_id',
        'approver_ability',
        'status',
        'decided_by',
        'decided_via',
        'comment',
        'timeout_hours',
        'on_timeout',
        'due_at',
        'escalated_at',
        'decided_at',
    ];

    protected $casts = [
        'sort' => 'integer',
        'approver_type' => ApproverType::class,
        'status' => StepStatus::class,
        'decided_via' => DecisionSource::class,
        'timeout_hours' => 'integer',
        'on_timeout' => TimeoutAction::class,
        'due_at' => 'datetime',
        'escalated_at' => 'datetime',
        'decided_at' => 'datetime',
    ];

    public function request(): BelongsTo
    {
        return $this->belongsTo(ApprovalRequest::class, 'approval_request_id');
    }

    public function approverEmployee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'approver_employee_id');
    }
}
