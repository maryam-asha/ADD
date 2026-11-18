<?php

namespace Add\Hr\Leave\Services;

use Add\Hr\Core\Models\Employee;
use Add\Hr\Leave\Enums\GrantCycle;
use Add\Hr\Leave\Enums\LedgerKind;
use Add\Hr\Leave\Models\LeaveType;
use Add\Hr\Leave\Models\LedgerEntry;
use Add\Hr\Leave\Support\LeaveCycle;
use Carbon\CarbonImmutable;

/**
 * What happens to unused days when a cycle ends, safe to run daily. A cycle
 * is the calendar year, or under a hire-anniversary policy the year from one
 * anniversary to the day before the next (LeaveCycle):
 *
 * - carry-over: on the last day of the cycle that ended, whatever exceeds
 *   what the policy lets through expires, oldest cycles first — everything
 *   when nothing carries over, the excess over carry_over_max when capped,
 *   nothing when all of it carries (CarryOverMode). The expiry is dated that
 *   day, so the next run sees the balance already within the limit;
 * - carried-days expiry: days of a past cycle still unused
 *   carry_over_expiry_months into the following one expire.
 *
 * Both follow the policy in force on the last day of the cycle concerned;
 * which kind of cycle an employee is on follows the policy in force today.
 */
class LeaveYearEnd
{
    public function __construct(
        private readonly LeaveLedger $ledger,
        private readonly LeavePolicyResolver $policies,
    ) {}

    public function run(CarbonImmutable $today): int
    {
        $created = 0;

        $pairs = LedgerEntry::query()
            ->where('period_year', '<', $today->year)
            ->distinct()
            ->get(['employee_id', 'leave_type_id']);

        foreach ($pairs as $pair) {
            $employee = Employee::query()->find($pair->employee_id);
            $type = LeaveType::query()->find($pair->leave_type_id);

            if ($employee === null || $type === null || ! $type->tracks_balance) {
                continue;
            }

            $kind = LeaveCycle::kindOf($this->policies->for($employee, $type, $today));
            $current = LeaveCycle::containing($kind, $employee, $today);

            $created += $this->cap($employee, $type, $current->year - 1, $current->start->subDay());
            $created += $this->expireCarried($employee, $type, $today, $kind, $current);
        }

        return $created;
    }

    private function cap(Employee $employee, LeaveType $type, int $year, CarbonImmutable $cycleEnd): int
    {
        $limit = $this->policies->for($employee, $type, $cycleEnd)?->carryOverLimit();

        if ($limit === null) {
            return 0;
        }

        $buckets = array_filter($this->ledger->buckets($employee, $type, $cycleEnd, $year), fn (float $left) => $left > 0);
        $excess = round(array_sum($buckets) - $limit, 2);
        $created = 0;

        foreach ($buckets as $bucket => $left) {
            if ($excess <= 0) {
                break;
            }

            $expire = min($left, $excess);
            $this->ledger->record($employee, $type, LedgerKind::Expiry, -$expire, $cycleEnd, (int) $bucket, [
                'notes' => 'carry-over cap',
            ]);
            $excess = round($excess - $expire, 2);
            $created++;
        }

        return $created;
    }

    private function expireCarried(Employee $employee, LeaveType $type, CarbonImmutable $today, GrantCycle $kind, LeaveCycle $current): int
    {
        $created = 0;

        foreach ($this->ledger->buckets($employee, $type, $today, $current->year - 1) as $bucket => $left) {
            if ($left <= 0) {
                continue;
            }

            $cycle = LeaveCycle::startingIn($kind, $employee, (int) $bucket);
            $months = $this->policies->for($employee, $type, $cycle->end)?->carry_over_expiry_months;

            if ($months === null || $today->lt($cycle->end->addDay()->addMonths($months))) {
                continue;
            }

            $this->ledger->record($employee, $type, LedgerKind::Expiry, -$left, $today, (int) $bucket, [
                'notes' => 'carried days expired',
            ]);
            $created++;
        }

        return $created;
    }
}
