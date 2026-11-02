<?php

namespace Add\Hr\Training\Services;

use Add\Hr\Audit\HrAudit;
use Add\Hr\Contracts\HrClock;
use Add\Hr\Core\Models\Employee;
use Add\Hr\Support\Decimal;
use Add\Hr\Support\HrException;
use Add\Hr\Support\LocalDay;
use Add\Hr\Training\Enums\BudgetEntryKind;
use Add\Hr\Training\Enums\LearningRequestStatus;
use Add\Hr\Training\Models\BudgetEntry;
use Add\Hr\Training\Models\LearningRequest;
use Illuminate\Support\Facades\DB;

/**
 * Each employee's learning budget for a year, kept as a ledger: the balance
 * is the sum of its entries, and what is available also holds back the
 * requests still pending.
 */
class LearningBudget
{
    public function __construct(private readonly HrClock $clock) {}

    public function currency(): string
    {
        return (string) config('hr.training.currency', 'USD');
    }

    public function balance(int $employeeId, int $year): string
    {
        return Decimal::trim(Decimal::of(BudgetEntry::query()->where('employee_id', $employeeId)->where('year', $year)->sum('amount')));
    }

    public function available(int $employeeId, int $year, ?int $exceptRequestId = null): string
    {
        $pending = LearningRequest::query()
            ->where('employee_id', $employeeId)
            ->where('year', $year)
            ->where('status', LearningRequestStatus::Pending->value)
            ->when($exceptRequestId !== null, fn ($q) => $q->whereKeyNot($exceptRequestId))
            ->sum('amount');

        return Decimal::sub($this->balance($employeeId, $year), $pending);
    }

    public function assertAvailable(int $employeeId, int $year, string $amount, ?int $exceptRequestId = null): void
    {
        $available = $this->available($employeeId, $year, $exceptRequestId);
        if (Decimal::cmp($amount, $available) > 0) {
            HrException::fail('hr::training.budget_insufficient', ['available' => $available, 'currency' => $this->currency()]);
        }
    }

    /**
     * Gives `$amount` for `$year` to the listed employees, or to everyone
     * employed today; anyone already allocated that year is skipped.
     *
     * @param  list<int>|null  $employeeIds
     */
    public function allocate(int $year, string $amount, ?array $employeeIds, int|string|null $actorId): int
    {
        return DB::transaction(function () use ($year, $amount, $employeeIds, $actorId) {
            $today = LocalDay::today($this->clock);
            $allocated = BudgetEntry::query()->where('year', $year)->where('kind', BudgetEntryKind::Allocation->value)->pluck('employee_id')->all();

            $employees = Employee::query()
                ->when($employeeIds !== null, fn ($q) => $q->whereIn('id', $employeeIds))
                ->whereNotIn('id', $allocated)
                ->orderBy('id')
                ->get()
                ->filter(fn (Employee $employee) => $employeeIds !== null || $employee->isEmployedOn($today));

            foreach ($employees as $employee) {
                $this->entry($employee->id, $year, BudgetEntryKind::Allocation, $amount, null, null, $actorId);
            }
            HrAudit::record('learning_budget_allocated', null, ['year' => $year, 'amount' => $amount, 'employees' => $employees->count()]);

            return $employees->count();
        });
    }

    public function adjust(Employee $employee, int $year, string $amount, ?string $note, int|string|null $actorId): BudgetEntry
    {
        $entry = $this->entry($employee->id, $year, BudgetEntryKind::Adjustment, $amount, null, $note, $actorId);
        HrAudit::record('learning_budget_adjusted', $entry, ['amount' => $amount]);

        return $entry;
    }

    /**
     * Records an approved request's spending. Inside the approval's transaction.
     */
    public function spend(LearningRequest $request): BudgetEntry
    {
        return $this->entry($request->employee_id, $request->year, BudgetEntryKind::Spend, Decimal::sub(0, $request->amount), $request->id, $request->title, null);
    }

    private function entry(int $employeeId, int $year, BudgetEntryKind $kind, string $amount, ?int $requestId, ?string $note, int|string|null $actorId): BudgetEntry
    {
        return BudgetEntry::query()->create([
            'employee_id' => $employeeId,
            'year' => $year,
            'kind' => $kind,
            'amount' => $amount,
            'currency' => $this->currency(),
            'learning_request_id' => $requestId,
            'note' => $note === null ? null : mb_substr($note, 0, 500),
            'created_by' => $actorId,
        ]);
    }
}
