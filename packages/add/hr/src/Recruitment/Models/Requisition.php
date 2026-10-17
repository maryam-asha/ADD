<?php

namespace Add\Hr\Recruitment\Models;

use Add\Hr\Approvals\Models\ApprovalRequest;
use Add\Hr\Core\Enums\EmploymentType;
use Add\Hr\Core\Models\Employee;
use Add\Hr\Core\Models\JobGrade;
use Add\Hr\Core\Models\OrgUnit;
use Add\Hr\Core\Models\Position;
use Add\Hr\Recruitment\Enums\RequisitionReason;
use Add\Hr\Recruitment\Enums\RequisitionStatus;
use Add\Hr\Recruitment\Enums\StageType;
use Add\Hr\Support\Casts\DecimalString;
use Add\Hr\Support\Concerns\HasTranslations;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A request to hire `headcount` people into a unit, decided through
 * approvals with the hiring manager as the requester.
 */
class Requisition extends Model
{
    use HasTranslations;

    protected $table = 'hr_requisitions';

    protected $fillable = [
        'org_unit_id',
        'position_id',
        'job_grade_id',
        'title',
        'headcount',
        'employment_type',
        'reason',
        'replaces_employee_id',
        'hiring_manager_id',
        'budget_min',
        'budget_max',
        'budget_currency',
        'justification',
        'status',
        'approval_request_id',
        'requested_by',
        'approved_at',
        'filled_at',
    ];

    protected $casts = [
        'title' => 'array',
        'headcount' => 'integer',
        'employment_type' => EmploymentType::class,
        'reason' => RequisitionReason::class,
        'budget_min' => DecimalString::class,
        'budget_max' => DecimalString::class,
        'status' => RequisitionStatus::class,
        'approved_at' => 'datetime',
        'filled_at' => 'datetime',
    ];

    public function hiredCount(): int
    {
        return Application::query()
            ->whereIn('job_opening_id', $this->openings()->select('id'))
            ->where('stage', PipelineStage::codeOf(StageType::Hired))
            ->count();
    }

    public function orgUnit(): BelongsTo
    {
        return $this->belongsTo(OrgUnit::class);
    }

    public function position(): BelongsTo
    {
        return $this->belongsTo(Position::class);
    }

    public function jobGrade(): BelongsTo
    {
        return $this->belongsTo(JobGrade::class);
    }

    public function hiringManager(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'hiring_manager_id');
    }

    public function replacesEmployee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'replaces_employee_id');
    }

    public function approvalRequest(): BelongsTo
    {
        return $this->belongsTo(ApprovalRequest::class);
    }

    public function openings(): HasMany
    {
        return $this->hasMany(JobOpening::class);
    }
}
