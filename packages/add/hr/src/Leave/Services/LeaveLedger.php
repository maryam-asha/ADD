<?php

namespace Add\Hr\Leave\Services;

use Add\Hr\Core\Models\Employee;
use Add\Hr\Leave\Enums\LeaveRequestStatus;
use Add\Hr\Leave\Enums\LedgerKind;
use Add\Hr\Leave\Models\LeaveRequest;
use Add\Hr\Leave\Models\LeaveType;
use Add\Hr\Leave\Models\LedgerEntry;
use Add\Hr\Support\LocalDay;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;

/**
 * Leave balances as a ledger: every credit and debit is a row, a balance is
 * their sum up to a day. Rows belong to an entitlement year (period_year);
 * usage takes the oldest year with days left first, so carried-over days
 * are spent before they can expire.
 */
class LeaveLedger
{
    public function balance(Employee $employee, LeaveType $type, ?CarbonInterface $asOf = null): float
    {
        return round((float) $this->entries($employee, $type, $asOf)->sum('amount'), 2);
    }

    /**
     * Days held by requests still waiting for approval.
     */
    public function pending(Employee $employee, LeaveType $type, ?int $exceptRequestId = null): float
    {
        return round((float) LeaveRequest::query()
            ->where('employee_id', $employee->id)
            ->where('leave_type_id', $type->id)
            ->where('status', LeaveRequestStatus::Pending->value)
            ->when($exceptRequestId !== null, fn ($q) => $q->whereKeyNot($exceptRequestId))
            ->sum('amount'), 2);
    }

    /**
     * What is left of each entitlement year, oldest first.
     *
     * @return array<int, float>
     */
    public function buckets(Employee $employee, LeaveType $type, ?CarbonInterface $asOf = null, ?int $upToYear = null): array
    {
        return $this->entries($employee, $type, $asOf)
            ->when($upToYear !== null, fn ($q) => $q->where('period_year', '<=', $upToYear))
            ->selectRaw('period_year, SUM(amount) as remaining')
            ->groupBy('period_year')
            ->orderBy('period_year')
            ->pluck('remaining', 'period_year')
            ->map(fn ($remaining) => round((float) $remaining, 2))
            ->all();
    }

    /**
     * @param  array<string, mixed>  $extra  leave_request_id, reference, notes, created_by
     */
    public function record(Employee $employee, LeaveType $type, LedgerKind $kind, float $amount, CarbonInterface $on, int $periodYear, array $extra = []): LedgerEntry
    {
        return LedgerEntry::query()->create([
            ...$extra,
            'employee_id' => $employee->id,
            'leave_type_id' => $type->id,
            'kind' => $kind,
            'amount' => round($amount, 2),
            'effective_on' => $on,
            'period_year' => $periodYear,
        ]);
    }

    public function hasReference(Employee $employee, LeaveType $type, string $reference): bool
    {
        return LedgerEntry::query()
            ->where('employee_id', $employee->id)
            ->where('leave_type_id', $type->id)
            ->where('reference', $reference)
            ->exists();
    }

    /**
     * Debits an approved request today, from the oldest year with days left;
     * whatever they do not cover goes on the current year (a negative
     * balance, when the policy allows one).
     */
    public function consume(LeaveRequest $request, int|string|null $actorId = null): void
    {
        $employee = $request->employee;
        $type = $request->leaveType;
        $today = LocalDay::today();
        $left = (float) $request->amount;

        foreach ($this->buckets($employee, $type, $today) as $year => $remaining) {
            if ($left <= 0) {
                break;
            }

            if ($remaining <= 0 || $year > $today->year) {
                continue;
            }

            $take = min($remaining, $left);
            $this->recordUsage($request, -$take, $year, $actorId);
            $left = round($left - $take, 2);
        }

        if ($left > 0) {
            $this->recordUsage($request, -$left, $today->year, $actorId);
        }
    }

    /**
     * Gives back every day an approved request took, to the years it took
     * them from.
     */
    public function reverse(LeaveRequest $request, int|string|null $actorId = null): void
    {
        $byYear = $request->ledgerEntries()
            ->whereIn('kind', [LedgerKind::Usage->value, LedgerKind::Reversal->value])
            ->selectRaw('period_year, SUM(amount) as net')
            ->groupBy('period_year')
            ->pluck('net', 'period_year');

        foreach ($byYear as $year => $net) {
            if ((float) $net < 0) {
                $this->record($request->employee, $request->leaveType, LedgerKind::Reversal, -(float) $net, LocalDay::today(), (int) $year, [
                    'leave_request_id' => $request->id,
                    'created_by' => $actorId,
                ]);
            }
        }
    }

    private function recordUsage(LeaveRequest $request, float $amount, int $year, int|string|null $actorId): void
    {
        $this->record($request->employee, $request->leaveType, LedgerKind::Usage, $amount, LocalDay::today(), $year, [
            'leave_request_id' => $request->id,
            'created_by' => $actorId,
        ]);
    }

    /**
     * @return Builder<LedgerEntry>
     */
    private function entries(Employee $employee, LeaveType $type, ?CarbonInterface $asOf): Builder
    {
        return LedgerEntry::query()
            ->where('employee_id', $employee->id)
            ->where('leave_type_id', $type->id)
            ->where('effective_on', '<=', ($asOf ?? LocalDay::today())->toDateString());
    }
}
