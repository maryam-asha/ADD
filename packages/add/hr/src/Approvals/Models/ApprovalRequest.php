<?php

namespace Add\Hr\Approvals\Models;

use Add\Hr\Approvals\Enums\ApprovalStatus;
use Add\Hr\Approvals\Enums\StepStatus;
use Add\Hr\Core\Models\Employee;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * One employee request (a leave, an overtime, ...) on its way through its
 * flow. Changed only by ApprovalEngine.
 */
class ApprovalRequest extends Model
{
    protected $table = 'hr_approval_requests';

    protected $guarded = [];

    protected $casts = [
        'status' => ApprovalStatus::class,
        'current_step' => 'integer',
        'decided_at' => 'datetime',
    ];

    public function isPending(): bool
    {
        return $this->status === ApprovalStatus::Pending;
    }

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function steps(): HasMany
    {
        return $this->hasMany(ApprovalStep::class)->orderBy('sort');
    }

    public function pendingStep(): HasOne
    {
        return $this->hasOne(ApprovalStep::class)->where('status', StepStatus::Pending->value);
    }
}
