<?php

namespace Add\Hr\Approvals\Models;

use Add\Hr\Core\Models\Employee;
use Add\Hr\Support\Casts\DateOnly;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An approver away: from starts_on to ends_on, the delegate decides what
 * would otherwise wait for the delegator.
 */
class ApprovalDelegation extends Model
{
    protected $table = 'hr_approval_delegations';

    protected $guarded = [];

    protected $casts = [
        'starts_on' => DateOnly::class,
        'ends_on' => DateOnly::class,
    ];

    /**
     * @param  Builder<self>  $query
     */
    public function scopeActiveOn(Builder $query, CarbonInterface $on, ?string $requestType = null): void
    {
        $day = $on->toDateString();

        $query->where('starts_on', '<=', $day)
            ->where('ends_on', '>=', $day)
            ->when($requestType !== null, fn ($q) => $q->where(
                fn ($q) => $q->whereNull('request_type')->orWhere('request_type', $requestType)
            ));
    }

    public function delegator(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'delegator_employee_id');
    }

    public function delegate(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'delegate_employee_id');
    }
}
