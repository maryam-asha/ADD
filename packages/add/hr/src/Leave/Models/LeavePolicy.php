<?php

namespace Add\Hr\Leave\Models;

use Add\Hr\Leave\Enums\AccrualMethod;
use Add\Hr\Support\Casts\DateOnly;
use Add\Hr\Support\Casts\PlainDecimal;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * How much of a leave type who gets, over [effective_from, effective_to].
 * A change of rules is a new policy, not an edit, so past accruals keep
 * the rules they were made under.
 */
class LeavePolicy extends Model
{
    protected $table = 'hr_leave_policies';

    protected $guarded = [];

    protected $casts = [
        'name' => 'array',
        'effective_from' => DateOnly::class,
        'effective_to' => DateOnly::class,
        'accrual_method' => AccrualMethod::class,
        'annual_entitlement' => PlainDecimal::class,
        'service_tiers' => 'array',
        'carry_over_max' => PlainDecimal::class,
        'carry_over_expiry_months' => 'integer',
        'max_negative' => PlainDecimal::class,
        'contract_types' => 'array',
        'job_grade_ids' => 'array',
        'org_unit_ids' => 'array',
    ];

    /**
     * @param  Builder<self>  $query
     */
    public function scopeActiveOn(Builder $query, CarbonInterface $on): void
    {
        $day = $on->toDateString();

        $query->where('effective_from', '<=', $day)
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>=', $day));
    }

    /**
     * The yearly entitlement after `$years` full years of service: the
     * highest tier reached, else the base.
     */
    public function entitlementAfter(int $years): float
    {
        $entitlement = (float) $this->annual_entitlement;
        $reached = -1;

        foreach ($this->service_tiers ?? [] as $tier) {
            if ($years >= (int) $tier['min_years'] && (int) $tier['min_years'] > $reached) {
                $reached = (int) $tier['min_years'];
                $entitlement = (float) $tier['entitlement'];
            }
        }

        return $entitlement;
    }

    public function leaveType(): BelongsTo
    {
        return $this->belongsTo(LeaveType::class);
    }
}
