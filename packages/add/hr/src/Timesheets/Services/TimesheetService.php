<?php

namespace Add\Hr\Timesheets\Services;

use Add\Hr\Approvals\Services\ApprovalEngine;
use Add\Hr\Audit\HrAudit;
use Add\Hr\Core\Models\Employee;
use Add\Hr\Support\HrException;
use Add\Hr\Time\Services\PeriodGuard;
use Add\Hr\Timesheets\Enums\TimesheetStatus;
use Add\Hr\Timesheets\Models\CostCenter;
use Add\Hr\Timesheets\Models\Timesheet;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The only writer of timesheets. An employee fills a week (replacing its
 * lines while it is a draft or was rejected), submits it into the approval
 * engine, and may recall it while it is still waiting. Hours come in
 * steps of hr.timesheets.increment_hours, on days the employee was
 * employed, at most 24 a day, never in a locked month.
 */
class TimesheetService
{
    public const APPROVAL_TYPE = 'timesheet';

    public function __construct(
        private readonly ApprovalEngine $approvals,
        private readonly PeriodGuard $periods,
        private readonly TimesheetReconciliation $reconciliation,
    ) {}

    public function find(Employee $employee, CarbonImmutable $weekStart): ?Timesheet
    {
        return Timesheet::query()->where('employee_id', $employee->id)->where('week_start', $weekStart->toDateString())->first();
    }

    /**
     * @param  list<array{cost_center_id: int, work_date: string, hours: float|int|string, note?: ?string}>  $lines
     */
    public function save(Employee $employee, CarbonImmutable $weekStart, array $lines): Timesheet
    {
        return DB::transaction(function () use ($employee, $weekStart, $lines) {
            Employee::query()->lockForUpdate()->findOrFail($employee->id);
            $timesheet = $this->find($employee, $weekStart)
                ?? Timesheet::query()->create(['employee_id' => $employee->id, 'week_start' => $weekStart, 'status' => TimesheetStatus::Draft]);

            if (! $timesheet->isEditable()) {
                HrException::fail('hr::timesheets.not_editable');
            }

            $this->assertLines($employee, $timesheet, $lines);

            $timesheet->entries()->delete();
            $timesheet->entries()->createMany(array_map(fn (array $line) => [
                'cost_center_id' => $line['cost_center_id'],
                'work_date' => CarbonImmutable::parse($line['work_date']),
                'hours' => $line['hours'],
                'note' => $line['note'] ?? null,
            ], $lines));

            $timesheet->update([
                'status' => TimesheetStatus::Draft,
                'total_hours' => array_sum(array_map(fn (array $line) => (float) $line['hours'], $lines)),
            ]);

            return $timesheet->load('entries');
        });
    }

    public function submit(Employee $employee, CarbonImmutable $weekStart, int|string|null $actorId): Timesheet
    {
        return DB::transaction(function () use ($employee, $weekStart, $actorId) {
            $timesheet = $this->find($employee, $weekStart);

            if ($timesheet === null || $timesheet->entries()->doesntExist()) {
                HrException::fail('hr::timesheets.empty');
            }

            $timesheet = Timesheet::query()->lockForUpdate()->findOrFail($timesheet->id);

            if ($timesheet->status !== TimesheetStatus::Draft) {
                HrException::fail('hr::timesheets.not_editable');
            }

            $this->assertOpenWeek($timesheet);
            $this->assertWithinVariance($timesheet);

            $timesheet->update([
                'status' => TimesheetStatus::Submitted,
                'submitted_at' => now(),
                'submitted_by' => $actorId,
            ]);
            $timesheet->approval_request_id = $this->approvals->submit($timesheet, self::APPROVAL_TYPE, $employee, $actorId)->id;
            $timesheet->save();

            HrAudit::record('timesheet_submitted', $timesheet, ['hours' => $timesheet->total_hours]);

            return $timesheet;
        });
    }

    /**
     * Takes a submitted week back to draft while nobody has decided it.
     */
    public function recall(Timesheet $timesheet): void
    {
        DB::transaction(function () use ($timesheet) {
            $timesheet = Timesheet::query()->lockForUpdate()->findOrFail($timesheet->id);

            if ($timesheet->status !== TimesheetStatus::Submitted) {
                HrException::fail('hr::timesheets.not_submitted');
            }

            $this->approvals->cancel($timesheet->approval);
            $timesheet->update(['status' => TimesheetStatus::Draft, 'approval_request_id' => null, 'submitted_at' => null]);
        });
    }

    public function assertOpenWeek(Timesheet $timesheet): void
    {
        $this->periods->assertOpen($timesheet->week_start);
        $this->periods->assertOpen($timesheet->weekEnd());
    }

    /**
     * With hr.timesheets.max_variance_hours set, a week whose hours differ
     * from attendance by more than that is refused; without it, the gap is
     * shown and nothing more.
     */
    private function assertWithinVariance(Timesheet $timesheet): void
    {
        $limit = config('hr.timesheets.max_variance_hours');

        if ($limit === null) {
            return;
        }

        $difference = abs($this->reconciliation->for($timesheet->employee, $timesheet->week_start, $timesheet)['difference_hours']);

        if ($difference > (float) $limit + 0.001) {
            HrException::fail('hr::timesheets.variance_too_large', ['hours' => $limit]);
        }
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     */
    private function assertLines(Employee $employee, Timesheet $timesheet, array $lines): void
    {
        $this->assertOpenWeek($timesheet);
        $step = (float) config('hr.timesheets.increment_hours', 0.5);
        $perDay = [];

        foreach ($lines as $line) {
            $day = CarbonImmutable::parse($line['work_date']);
            $hours = (float) $line['hours'];

            if ($day->lt($timesheet->week_start) || $day->gt($timesheet->weekEnd())) {
                HrException::fail('hr::timesheets.outside_week');
            }

            if (! $employee->isEmployedOn($day)) {
                HrException::fail('hr::time.not_employed');
            }

            if (abs($hours / $step - round($hours / $step)) > 0.0001) {
                HrException::fail('hr::timesheets.increment', ['step' => $step]);
            }

            if (! CostCenter::query()->whereKey($line['cost_center_id'])->where('is_active', true)->exists()) {
                HrException::fail('hr::timesheets.cost_center_inactive');
            }

            $perDay[$day->toDateString()] = ($perDay[$day->toDateString()] ?? 0) + $hours;

            if ($perDay[$day->toDateString()] > 24) {
                HrException::fail('hr::timesheets.day_over_24');
            }
        }
    }
}
