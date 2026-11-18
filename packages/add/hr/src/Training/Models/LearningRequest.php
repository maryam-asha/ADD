<?php

namespace Add\Hr\Training\Models;

use Add\Hr\Approvals\Models\ApprovalRequest;
use Add\Hr\Core\Models\Employee;
use Add\Hr\Support\Casts\DateOnly;
use Add\Hr\Support\Casts\DecimalString;
use Add\Hr\Training\Enums\LearningRequestStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An employee asking to spend their learning budget on outside learning,
 * decided through approvals.
 */
class LearningRequest extends Model
{
    protected $table = 'hr_learning_requests';

    protected $fillable = [
        'employee_id',
        'course_id',
        'title',
        'provider',
        'starts_on',
        'year',
        'amount',
        'currency',
        'justification',
        'status',
        'approval_request_id',
        'requested_by',
    ];

    protected $casts = [
        'starts_on' => DateOnly::class,
        'year' => 'integer',
        'amount' => DecimalString::class,
        'status' => LearningRequestStatus::class,
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function approvalRequest(): BelongsTo
    {
        return $this->belongsTo(ApprovalRequest::class);
    }
}
