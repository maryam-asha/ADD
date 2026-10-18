<?php

namespace Add\Hr\Leave\Services;

use Add\Hr\Core\Models\Employee;
use Add\Hr\Leave\Enums\LedgerKind;
use Add\Hr\Leave\Models\LeaveType;
use Add\Hr\Leave\Models\LedgerEntry;
use Carbon\CarbonImmutable;

/**
 * What happens to unused days when a year ends, safe to run daily:
 *
 * - carry-over cap: on 31 December of the year that ended, whatever exceeds
 *   the policy's carry_over_max expires, oldest years first. The expiry is
 *   dated that day, so the next run sees the balance already within the cap;
 * - carried-days expiry: days of a past year still unused
 *   carry_over_expiry_months into the following year expire.
 *
 * Both follow the policy in force on 31 December of the year concerned.
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

            $created += $this->cap($employee, $type, $today->year - 1);
            $created += $this->expireCarried($employee, $type, $today);
        }

        return $created;
    }

    private function cap(Employee $employee, LeaveType $type, int $year): int
    {
        $yearEnd = CarbonImmutable::create($year, 12, 31);
        $policy = $this->policies->for($employee, $type, $yearEnd);

        if ($policy === null || $policy->carry_over_max === null) {
            return 0;
        }

        $buckets = array_filter($this->ledger->buckets($employee, $type, $yearEnd, $year), fn (float $left) => $left > 0);
        $excess = round(array_sum($buckets) - (float) $policy->carry_over_max, 2);
        $created = 0;

        foreach ($buckets as $bucket => $left) {
            if ($excess <= 0) {
                break;
            }

            $expire = min($left, $excess);
            $this->ledger->record($employee, $type, LedgerKind::Expiry, -$expire, $yearEnd, (int) $bucket, [
                'notes' => 'carry-over cap',
            ]);
            $excess = round($excess - $expire, 2);
            $created++;
        }

        return $created;
    }

    private function expireCarried(Employee $employee, LeaveType $type, CarbonImmutable $today): int
    {
        $created = 0;

        foreach ($this->ledger->buckets($employee, $type, $today, $today->year - 1) as $bucket => $left) {
            if ($left <= 0) {
                continue;
            }

            $policy = $this->policies->for($employee, $type, CarbonImmutable::create((int) $bucket, 12, 31));
            $months = $policy?->carry_over_expiry_months;

            if ($months === null || $today->lt(CarbonImmutable::create((int) $bucket + 1)->startOfYear()->addMonths($months))) {
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
