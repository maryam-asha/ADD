<?php

namespace Add\Hr\Reports\Services;

use Add\Hr\Core\Models\Employee;
use Add\Hr\Leave\Models\LeaveType;
use Add\Hr\Leave\Services\LeaveLedger;
use Add\Hr\Payroll\Enums\InstallmentStatus;
use Add\Hr\Payroll\Enums\LoanStatus;
use Add\Hr\Payroll\Models\LoanInstallment;
use Add\Hr\Support\Decimal;
use Add\Hr\Time\Enums\ExceptionStatus;
use Add\Hr\Time\Models\AttendanceException;
use Add\Hr\Timesheets\Models\Timesheet;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * The cross-module reports that read one module each: attendance
 * exceptions, timesheet compliance, and what is owed (leave days, loans).
 */
class OperationsReports
{
    public function __construct(private readonly LeaveLedger $ledger) {}

    /**
     * Exceptions per employee and type in the range, and how many are still open.
     *
     * @return array{totals: list<array<string, mixed>>, rows: list<array<string, mixed>>}
     */
    public function exceptions(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $grouped = AttendanceException::query()
            ->whereBetween('work_date', [$from->toDateString(), $to->toDateString()])
            ->selectRaw('employee_id, type, count(*) as total, sum(case when status = ? then 1 else 0 end) as still_open', [ExceptionStatus::Open->value])
            ->groupBy('employee_id', 'type')
            ->orderBy('employee_id')
            ->get();
        $employees = $this->employees($grouped->pluck('employee_id'));

        return [
            'totals' => $grouped->groupBy(fn ($row) => $row->type instanceof \BackedEnum ? $row->type->value : $row->type)
                ->map(fn (Collection $rows, string $type) => ['type' => $type, 'total' => (int) $rows->sum('total'), 'open' => (int) $rows->sum('still_open')])
                ->values()->all(),
            'rows' => $grouped->map(fn ($row) => [
                'employee' => $employees[$row->employee_id] ?? null,
                'type' => $row->type instanceof \BackedEnum ? $row->type->value : $row->type,
                'total' => (int) $row->total,
                'open' => (int) $row->still_open,
            ])->all(),
        ];
    }

    /**
     * Every employee employed during the week, with their timesheet's
     * status for it, or `missing`.
     *
     * @return array{summary: array<string, int>, rows: list<array<string, mixed>>}
     */
    public function timesheetCompliance(CarbonImmutable $weekStart): array
    {
        $weekEnd = $weekStart->addDays(6);
        $timesheets = Timesheet::query()->where('week_start', $weekStart->toDateString())->get()->keyBy('employee_id');
        $employees = Employee::query()
            ->where('hired_on', '<=', $weekEnd->toDateString())
            ->where(fn ($q) => $q->whereNull('terminated_on')->orWhere('terminated_on', '>=', $weekStart->toDateString()))
            ->orderBy('employee_number')
            ->get();

        $rows = $employees->map(fn (Employee $employee) => [
            'employee' => $this->person($employee),
            'status' => $timesheets->get($employee->id)?->status->value ?? 'missing',
            'total_hours' => $timesheets->get($employee->id)?->total_hours,
        ]);

        return [
            'summary' => $rows->countBy('status')->all(),
            'rows' => $rows->values()->all(),
        ];
    }

    /**
     * Leave days still owed per employee and type (balances that track one,
     * non-zero), and — with money — loan installments still to deduct.
     *
     * @return array{leave: list<array<string, mixed>>, loans: list<array<string, mixed>>|null}
     */
    public function liabilities(CarbonImmutable $asOf, bool $withMoney): array
    {
        $types = config('hr.modules.leave', false)
            ? LeaveType::query()->where('is_active', true)->where('tracks_balance', true)->orderBy('id')->get()
            : collect();
        $leave = [];
        foreach (HeadcountReport::employedOn($asOf)->orderBy('employee_number')->get() as $employee) {
            foreach ($types as $type) {
                $days = $this->ledger->balance($employee, $type, $asOf);
                if ($days != 0) {
                    $leave[] = ['employee' => $this->person($employee), 'leave_type' => ['id' => $type->id, 'code' => $type->code, 'name' => $type->name], 'days' => $days];
                }
            }
        }

        $loans = null;
        if ($withMoney && config('hr.modules.payroll', false)) {
            $due = LoanInstallment::query()
                ->where('hr_loan_installments.status', InstallmentStatus::Due->value)
                ->join('hr_loans', 'hr_loans.id', '=', 'hr_loan_installments.loan_id')
                ->where('hr_loans.status', LoanStatus::Active->value)
                ->selectRaw('hr_loans.employee_id, hr_loans.currency, sum(hr_loan_installments.amount) as outstanding, count(*) as installments')
                ->groupBy('hr_loans.employee_id', 'hr_loans.currency')
                ->get();
            $employees = $this->employees($due->pluck('employee_id'));
            $loans = $due->map(fn ($row) => [
                'employee' => $employees[$row->employee_id] ?? null,
                'currency' => $row->currency,
                'outstanding' => Decimal::trim(Decimal::of($row->outstanding)),
                'installments' => (int) $row->installments,
            ])->all();
        }

        return ['leave' => $leave, 'loans' => $loans];
    }

    /**
     * @param  Collection<int, int>  $ids
     * @return array<int, array<string, mixed>>
     */
    private function employees(Collection $ids): array
    {
        return Employee::query()->whereIn('id', $ids->unique())->get()->mapWithKeys(fn (Employee $employee) => [$employee->id => $this->person($employee)])->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function person(Employee $employee): array
    {
        return ['id' => $employee->id, 'employee_number' => $employee->employee_number, 'name' => $employee->name];
    }
}
