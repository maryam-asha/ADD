<?php

namespace Add\Hr\Leave\Services;

use Add\Hr\Core\Models\Employee;
use Add\Hr\Leave\Enums\AccrualMethod;
use Add\Hr\Leave\Enums\LedgerKind;
use Add\Hr\Leave\Models\LeavePolicy;
use Add\Hr\Leave\Models\LeaveType;
use Carbon\CarbonImmutable;

/**
 * Scheduled credits, safe to run any day and any number of times: each
 * credit carries a reference (accrual:2026-09, grant:2026) and is booked
 * once. Missed runs catch up, back to 1 January of last year.
 *
 * - monthly: after each month ends, a twelfth of the yearly entitlement,
 *   prorated by the days of that month the employee was employed under a
 *   monthly policy;
 * - annual_grant: the year's entitlement on 1 January (or on the hire day,
 *   prorated to what is left of the year, in halves).
 *
 * The entitlement follows seniority on the day it is earned. A policy only
 * credits days it was in force for, so a policy dated from go-live never
 * reaches back before it — opening balances are entered as `opening`.
 */
class LeaveAccrual
{
    public function __construct(
        private readonly LeaveLedger $ledger,
        private readonly LeavePolicyResolver $policies,
    ) {}

    public function run(CarbonImmutable $today): int
    {
        $since = $today->subYear()->startOfYear();
        $types = LeaveType::query()->where('is_active', true)->where('tracks_balance', true)->get();
        $created = 0;

        Employee::query()
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

    private function annual(Employee $employee, LeaveType $type, CarbonImmutable $today, CarbonImmutable $since): int
    {
        $created = 0;

        foreach ([$since->year, $today->year] as $year) {
            $reference = "grant:{$year}";
            $jan1 = CarbonImmutable::create($year)->startOfYear();
            [$first] = $this->employedWithin($employee, $jan1, $jan1->endOfYear());

            if ($first === null || $first->gt($today) || $this->ledger->hasReference($employee, $type, $reference)) {
                continue;
            }

            $policy = $this->policies->for($employee, $type, $first);

            if ($policy?->accrual_method !== AccrualMethod::AnnualGrant) {
                continue;
            }

            $share = ($first->diffInDays($jan1->endOfYear()->startOfDay()) + 1) / $jan1->daysInYear;
            $amount = round($this->entitlementOn($policy, $employee, $first) * $share * 2) / 2;

            if ($amount > 0) {
                $this->ledger->record($employee, $type, LedgerKind::Accrual, $amount, $first, $year, ['reference' => $reference]);
                $created++;
            }
        }

        return $created;
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
