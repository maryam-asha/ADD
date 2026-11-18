<?php

namespace Add\Hr\Leave\Services;

use Add\Hr\Core\Models\Employee;
use Add\Hr\Leave\Enums\AccrualMethod;
use Add\Hr\Leave\Enums\GrantCycle;
use Add\Hr\Leave\Enums\HireGrant;
use Add\Hr\Leave\Enums\LedgerKind;
use Add\Hr\Leave\Enums\TierTiming;
use Add\Hr\Leave\Models\LeavePolicy;
use Add\Hr\Leave\Models\LeaveType;
use Add\Hr\Leave\Support\LeaveCycle;
use Carbon\CarbonImmutable;

/**
 * Scheduled credits, safe to run any day and any number of times: each
 * credit carries a reference (accrual:2026-09, grant:2026, grant:a2026,
 * tier:2026) and is booked once. Missed runs catch up, back to 1 January of
 * last year. Also run at once for one employee when they are hired, and for
 * one type when its policy is saved, so a balance shows without waiting for
 * the night.
 *
 * - monthly: after each month ends, a twelfth of the yearly entitlement,
 *   prorated by the days of that month the employee was employed under a
 *   monthly policy;
 * - annual_grant: the cycle's entitlement when the cycle starts — 1 January,
 *   or the hire anniversary (GrantCycle). In the cycle someone joins, the
 *   policy's HireGrant decides: a share for the days left, the whole of it,
 *   or nothing until probation is over and then a share from that day.
 *   Shares are rounded to the half day.
 *
 * The entitlement follows seniority on the day it is earned. With
 * TierTiming::OnAnniversary a calendar-year grant also gets, on the service
 * anniversary that reaches a higher tier, the difference for what is left of
 * the year. A policy only credits days it was in force for, so a policy
 * dated from go-live never reaches back before it — opening balances are
 * entered as `opening`.
 */
class LeaveAccrual
{
    public function __construct(
        private readonly LeaveLedger $ledger,
        private readonly LeavePolicyResolver $policies,
    ) {}

    public function run(CarbonImmutable $today, ?Employee $only = null, ?LeaveType $onlyType = null): int
    {
        $since = $today->subYear()->startOfYear();
        $types = LeaveType::query()
            ->where('is_active', true)
            ->where('tracks_balance', true)
            ->when($onlyType, fn ($q, LeaveType $type) => $q->whereKey($type->id))
            ->get();
        $created = 0;

        if ($types->isEmpty()) {
            return 0;
        }

        Employee::query()
            ->when($only, fn ($q, Employee $employee) => $q->whereKey($employee->id))
            ->where('hired_on', '<=', $today->toDateString())
            ->where(fn ($q) => $q->whereNull('terminated_on')->orWhere('terminated_on', '>=', $since->toDateString()))
            ->orderBy('id')
            ->each(function (Employee $employee) use ($types, $today, $since, &$created) {
                foreach ($types as $type) {
                    $created += $this->monthly($employee, $type, $today, $since);
                    $created += $this->annual($employee, $type, $today, $since);
                }
            });

        return $created;
    }

    /**
     * Credits one employee everything due so far: a new hire's first grant.
     */
    public function forEmployee(Employee $employee, CarbonImmutable $today): int
    {
        return $this->run($today, $employee);
    }

    /**
     * Credits everyone what one type's policies now say is due: after a
     * policy is added or changed.
     */
    public function forType(LeaveType $type, CarbonImmutable $today): int
    {
        return $this->run($today, null, $type);
    }

    private function monthly(Employee $employee, LeaveType $type, CarbonImmutable $today, CarbonImmutable $since): int
    {
        $created = 0;
        $month = max($since, $employee->hired_on->startOfMonth());

        for (; $month->endOfMonth()->lt($today); $month = $month->addMonth()) {
            $reference = 'accrual:'.$month->format('Y-m');
            [$first, $last] = $this->employedWithin($employee, $month, $month->endOfMonth());

            if ($first === null || $this->ledger->hasReference($employee, $type, $reference)) {
                continue;
            }

            $policy = $this->policies->for($employee, $type, $last);

            if ($policy?->accrual_method !== AccrualMethod::Monthly) {
                continue;
            }

            $days = $this->daysUnder($policy, $first, $last);
            $amount = round($this->entitlementOn($policy, $employee, $last) / 12 * $days / $month->daysInMonth, 2);

            if ($amount > 0) {
                $this->ledger->record($employee, $type, LedgerKind::Accrual, $amount, $month->endOfMonth(), $month->year, ['reference' => $reference]);
                $created++;
            }
        }

        return $created;
    }

    /**
     * The yearly grants due from last year on, for whichever cycle the
     * policy in force on the grant day runs: each kind is tried, and only the
     * one its own policy names is booked, so a policy that moves from one
     * cycle to the other neither skips nor doubles a year.
     */
    private function annual(Employee $employee, LeaveType $type, CarbonImmutable $today, CarbonImmutable $since): int
    {
        $created = 0;

        foreach ([$since->year, $today->year] as $year) {
            foreach (GrantCycle::cases() as $kind) {
                $created += $this->grant($employee, $type, LeaveCycle::startingIn($kind, $employee, $year), $kind, $today);
            }

            $created += $this->tierTopUp($employee, $type, $year, $today);
        }

        return $created;
    }

    private function grant(Employee $employee, LeaveType $type, LeaveCycle $cycle, GrantCycle $kind, CarbonImmutable $today): int
    {
        $reference = ($kind === GrantCycle::HireAnniversary ? 'grant:a' : 'grant:').$cycle->year;
        [$first] = $this->employedWithin($employee, $cycle->start, $cycle->end);

        if ($first === null || $first->gt($today) || $this->ledger->hasReference($employee, $type, $reference)) {
            return 0;
        }

        $policy = $this->policies->for($employee, $type, $first);

        if ($policy?->accrual_method !== AccrualMethod::AnnualGrant || LeaveCycle::kindOf($policy) !== $kind) {
            return 0;
        }

        $joined = $cycle->contains(CarbonImmutable::parse($employee->hired_on));
        $from = $joined ? $this->grantDay($employee, $policy, $first) : $first;

        if ($from === null || $from->gt($cycle->end) || $from->gt($today)) {
            return 0;
        }

        $share = $joined && $policy->hire_grant === HireGrant::Full ? 1.0 : $cycle->shareFrom($from);
        $amount = round($this->entitlementOn($policy, $employee, $from) * $share * 2) / 2;

        if ($amount <= 0) {
            return 0;
        }

        $this->ledger->record($employee, $type, LedgerKind::Accrual, $amount, $from, $cycle->year, ['reference' => $reference]);

        return 1;
    }

    /**
     * The day a new hire's first grant is due: the day they start, or under
     * HireGrant::AfterProbation the day probation is over (the confirmation,
     * else the day after it was due to end). Null while it is still running
     * with no end on file.
     */
    private function grantDay(Employee $employee, LeavePolicy $policy, CarbonImmutable $first): ?CarbonImmutable
    {
        if ($policy->hire_grant !== HireGrant::AfterProbation) {
            return $first;
        }

        if ($employee->confirmed_on !== null) {
            return max($first, CarbonImmutable::parse($employee->confirmed_on));
        }

        return $employee->probation_ends_on === null
            ? $first
            : max($first, CarbonImmutable::parse($employee->probation_ends_on)->addDay());
    }

    /**
     * Under TierTiming::OnAnniversary, the service anniversary inside a
     * calendar year that reaches a higher tier pays the difference for what
     * is left of that year, once. An anniversary cycle needs none: it renews
     * on that very day at the new tier.
     */
    private function tierTopUp(Employee $employee, LeaveType $type, int $year, CarbonImmutable $today): int
    {
        $reference = 'tier:'.$year;
        $hired = CarbonImmutable::parse($employee->hired_on);
        $anniversary = LeaveCycle::anniversary($employee, $year);

        if ($year <= $hired->year || $anniversary->gt($today) || ! $employee->isEmployedOn($anniversary)
            || ! $this->ledger->hasReference($employee, $type, 'grant:'.$year)
            || $this->ledger->hasReference($employee, $type, $reference)) {
            return 0;
        }

        $policy = $this->policies->for($employee, $type, $anniversary);

        if ($policy?->accrual_method !== AccrualMethod::AnnualGrant
            || LeaveCycle::kindOf($policy) !== GrantCycle::CalendarYear
            || $policy->tier_timing !== TierTiming::OnAnniversary) {
            return 0;
        }

        $before = $this->entitlementOn($policy, $employee, $anniversary->subDay());
        $after = $this->entitlementOn($policy, $employee, $anniversary);
        $cycle = LeaveCycle::startingIn(GrantCycle::CalendarYear, $employee, $year);
        $amount = round(($after - $before) * $cycle->shareFrom($anniversary) * 2) / 2;

        if ($amount <= 0) {
            return 0;
        }

        $this->ledger->record($employee, $type, LedgerKind::Accrual, $amount, $anniversary, $year, ['reference' => $reference]);

        return 1;
    }

    /**
     * The first and last day of [from, to] the employee was employed, or
     * nulls when none.
     *
     * @return array{0: ?CarbonImmutable, 1: ?CarbonImmutable}
     */
    private function employedWithin(Employee $employee, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $first = max($from->startOfDay(), $employee->hired_on);
        $last = $employee->terminated_on === null ? $to->startOfDay() : min($to->startOfDay(), $employee->terminated_on);

        return $first->gt($last) ? [null, null] : [$first, $last];
    }

    private function daysUnder(LeavePolicy $policy, CarbonImmutable $first, CarbonImmutable $last): int
    {
        $from = max($first, $policy->effective_from);
        $to = $policy->effective_to === null ? $last : min($last, $policy->effective_to);

        return $from->gt($to) ? 0 : (int) $from->diffInDays($to) + 1;
    }

    private function entitlementOn(LeavePolicy $policy, Employee $employee, CarbonImmutable $on): float
    {
        return $policy->entitlementAfter((int) $employee->hired_on->diffInYears($on));
    }
}
