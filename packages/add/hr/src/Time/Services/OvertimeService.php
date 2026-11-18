<?php

namespace Add\Hr\Time\Services;

use Add\Hr\Approvals\Services\ApprovalEngine;
use Add\Hr\Audit\HrAudit;
use Add\Hr\Core\Models\Employee;
use Add\Hr\Support\HrException;
use Add\Hr\Time\Enums\OvertimeKind;
use Add\Hr\Time\Enums\OvertimeStatus;
use Add\Hr\Time\Models\AttendanceDay;
use Add\Hr\Time\Models\AttendancePolicy;
use Add\Hr\Time\Models\OvertimeRequest;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Overtime is paid only once approved. Asked ahead (pre: today or later)
 * it may cover any minutes; justified afterwards (post: a past day) it
 * cannot claim more than the attendance recorded beyond the plan, less
 * what other requests for that day already hold — and only when the
 * policy does not require approval ahead.
 */
class OvertimeService
{
    public const APPROVAL_TYPE = 'overtime';

    public function __construct(
        private readonly ScheduleResolver $schedule,
        private readonly PeriodGuard $periods,
        private readonly ApprovalEngine $approvals,
    ) {}

    /**
     * @param  array{work_date: string, minutes: int, reason: string}  $data
     */
    public function submit(Employee $employee, array $data, int|string|null $actorId): OvertimeRequest
    {
        return DB::transaction(function () use ($employee, $data, $actorId) {
            $employee = Employee::query()->lockForUpdate()->findOrFail($employee->id);
            $date = CarbonImmutable::parse($data['work_date']);
            $kind = $date->lt($this->schedule->today()) ? OvertimeKind::Post : OvertimeKind::Pre;

            if (! $employee->isEmployedOn($date)) {
                HrException::fail('hr::time.not_employed');
            }

            $this->periods->assertOpen($date);

            if ($kind === OvertimeKind::Post) {
                if (AttendancePolicy::forDay($date)->overtime_requires_pre_approval) {
                    HrException::fail('hr::time.overtime_needs_pre_approval');
                }

                $this->assertRecorded($employee, $date, (int) $data['minutes']);
            }

            $overtime = OvertimeRequest::query()->create([
                'employee_id' => $employee->id,
                'work_date' => $date,
                'minutes' => (int) $data['minutes'],
                'kind' => $kind,
                'reason' => $data['reason'],
                'status' => OvertimeStatus::Pending,
                'submitted_by' => $actorId,
            ]);

            $overtime->approval_request_id = $this->approvals->submit($overtime, self::APPROVAL_TYPE, $employee, $actorId)->id;
            $overtime->save();

            HrAudit::record('overtime_requested', $overtime, ['minutes' => $overtime->minutes, 'kind' => $kind->value]);

            return $overtime;
        });
    }

    public function cancel(OvertimeRequest $overtime): void
    {
        DB::transaction(function () use ($overtime) {
            $overtime = OvertimeRequest::query()->lockForUpdate()->findOrFail($overtime->id);

            if ($overtime->status !== OvertimeStatus::Pending) {
                HrException::fail('hr::time.not_pending');
            }

            $this->approvals->cancel($overtime->approval);
            $overtime->update(['status' => OvertimeStatus::Cancelled]);
        });
    }

    /**
     * A past day's request fits in the overtime its attendance recorded.
     */
    public function assertRecorded(Employee $employee, CarbonImmutable $date, int $minutes, ?int $exceptId = null): void
    {
        $recorded = (int) AttendanceDay::query()
            ->where('employee_id', $employee->id)
            ->where('work_date', $date->toDateString())
            ->value('overtime_minutes');

        $held = (int) OvertimeRequest::query()
            ->where('employee_id', $employee->id)
            ->where('work_date', $date->toDateString())
            ->whereIn('status', [OvertimeStatus::Pending->value, OvertimeStatus::Approved->value])
            ->when($exceptId !== null, fn ($q) => $q->whereKeyNot($exceptId))
            ->sum('minutes');

        if ($minutes > $recorded - $held) {
            HrException::fail('hr::time.overtime_exceeds_recorded', ['minutes' => max(0, $recorded - $held)]);
        }
    }

    /**
     * Approved minutes per employee over a date range, for payroll.
     *
     * @return array<int, int>
     */
    public function approvedMinutes(CarbonImmutable $from, CarbonImmutable $to): array
    {
        return OvertimeRequest::query()
            ->where('status', OvertimeStatus::Approved->value)
            ->whereBetween('work_date', [$from->toDateString(), $to->toDateString()])
            ->selectRaw('employee_id, SUM(minutes) as total')
            ->groupBy('employee_id')
            ->pluck('total', 'employee_id')
            ->map(fn ($total) => (int) $total)
            ->all();
    }
}
