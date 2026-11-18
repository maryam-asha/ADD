<?php

namespace Add\Hr\Leave\Models;

use Add\Hr\Leave\Enums\AccrualMethod;
use Add\Hr\Leave\Enums\CarryOverMode;
use Add\Hr\Leave\Enums\GrantCycle;
use Add\Hr\Leave\Enums\HireGrant;
use Add\Hr\Leave\Enums\TierTiming;
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

    protected $fillable = [
        'leave_type_id',
        'name',
        'effective_from',
        'effective_to',
        'accrual_method',
        'grant_cycle',
        'annual_entitlement',
        'service_tiers',
        'tier_timing',
        'hire_grant',
        'carry_over_mode',
        'carry_over_max',
        'carry_over_expiry_months',
        'max_negative',
        'contract_types',
        'job_grade_ids',
        'org_unit_ids',
    ];

    protected $casts = [
        'name' => 'array',
        'effective_from' => DateOnly::class,
        'effective_to' => DateOnly::class,
        'accrual_method' => AccrualMethod::class,
        'grant_cycle' => GrantCycle::class,
        'annual_entitlement' => PlainDecimal::class,
        'service_tiers' => 'array',
        'tier_timing' => TierTiming::class,
        'hire_grant' => HireGrant::class,
        'carry_over_mode' => CarryOverMode::class,
        'carry_over_max' => PlainDecimal::class,
        'carry_over_expiry_months' => 'integer',
        'max_negative' => PlainDecimal::class,
        'contract_types' => 'array',
        'job_grade_ids' => 'array',
        'org_unit_ids' => 'array',
    ];

    /**
     * What the package did before these were settings, so a policy that
     * names none of them behaves as it always has.
     */
    protected $attributes = [
        'grant_cycle' => 'calendar_year',
        'tier_timing' => 'next_cycle',
        'hire_grant' => 'prorated',
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
     * What unused days do when a cycle ends. A policy saved before the mode
     * existed carries it in carry_over_max alone: empty, no limit; 0, none.
     */
    public function carryOver(): CarryOverMode
    {
        if ($this->carry_over_mode !== null) {
            return $this->carry_over_mode;
        }

        return match (true) {
            $this->carry_over_max === null => CarryOverMode::Unlimited,
            (float) $this->carry_over_max <= 0 => CarryOverMode::None,
            default => CarryOverMode::Capped,
        };
    }

    /**
     * The most days a cycle may hand to the next: 0 when none carry over,
     * null when all do.
     */
    public function carryOverLimit(): ?float
    {
        return match ($this->carryOver()) {
            CarryOverMode::None => 0.0,
            CarryOverMode::Capped => (float) $this->carry_over_max,
            CarryOverMode::Unlimited => null,
        };
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
