<?php

namespace Add\Hr\Time\Services;

use Add\Hr\Audit\HrAudit;
use Add\Hr\Core\Models\Employee;
use Add\Hr\Support\HrException;
use Add\Hr\Time\Enums\EntryStatus;
use Add\Hr\Time\Enums\ExceptionStatus;
use Add\Hr\Time\Enums\OvertimeStatus;
use Add\Hr\Time\Enums\PeriodStatus;
use Add\Hr\Time\Models\AttendanceEntry;
use Add\Hr\Time\Models\AttendanceException;
use Add\Hr\Time\Models\OvertimeRequest;
use Add\Hr\Time\Models\TimePeriod;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Locking a month freezes its attendance for payroll. It is rebuilt one
 * last time first, and the lock is refused while anything in it is still
 * undecided: an open exception, a pending entry or overtime request.
 */
class TimePeriodService
{
    public function __construct(
        private readonly AttendanceProcessor $processor,
        private readonly ScheduleResolver $schedule,
    ) {}

    public function lock(int $year, int $month, int|string|null $actorId): TimePeriod
    {
        $first = CarbonImmutable::create($year, $month, 1);
        $last = $first->endOfMonth()->startOfDay();

        if ($last->gte($this->schedule->today())) {
            HrException::fail('hr::time.period_not_ended');
        }

        // Outside the lock's transaction: what the rebuild finds stays, so a
        // refused lock leaves the exceptions to review behind.
        $this->rebuild($first, $last);

        return DB::transaction(function () use ($year, $month, $first, $last, $actorId) {
            $period = TimePeriod::query()->lockForUpdate()->firstOrCreate(['year' => $year, 'month' => $month], ['status' => PeriodStatus::Open]);

            if ($period->status === PeriodStatus::Locked) {
                HrException::fail('hr::time.period_locked', ['month' => $first->format('Y-m')]);
            }

            $range = [$first->toDateString(), $last->toDateString()];
            $open = AttendanceException::query()->where('status', ExceptionStatus::Open->value)->whereBetween('work_date', $range)->count();

            if ($open > 0) {
                HrException::fail('hr::time.period_has_open_exceptions', ['count' => $open]);
            }

            $pending = AttendanceEntry::query()->where('status', EntryStatus::Pending->value)->whereBetween('work_date', $range)->count()
                + OvertimeRequest::query()->where('status', OvertimeStatus::Pending->value)->whereBetween('work_date', $range)->count();

            if ($pending > 0) {
                HrException::fail('hr::time.period_has_pending_requests', ['count' => $pending]);
            }

            $period->update(['status' => PeriodStatus::Locked, 'locked_at' => now(), 'locked_by' => $actorId]);
            HrAudit::record('time_period_locked', $period);

            return $period;
        });
    }

    public function unlock(int $year, int $month): TimePeriod
    {
        return DB::transaction(function () use ($year, $month) {
            $period = TimePeriod::query()->where(['year' => $year, 'month' => $month])->lockForUpdate()->first();

            if ($period === null || $period->status !== PeriodStatus::Locked) {
                HrException::fail('hr::time.period_not_locked');
            }

            $period->update(['status' => PeriodStatus::Open, 'locked_at' => null, 'locked_by' => null]);
            HrAudit::record('time_period_unlocked', $period);

            return $period;
        });
    }

    /**
     * Every day of the range for everyone employed in it.
     */
    public function rebuild(CarbonImmutable $from, CarbonImmutable $to, ?int $employeeId = null): int
    {
        $count = 0;

        Employee::query()
            ->when($employeeId !== null, fn ($q) => $q->whereKey($employeeId))
            ->where('hired_on', '<=', $to->toDateString())
            ->where(fn ($q) => $q->whereNull('terminated_on')->orWhere('terminated_on', '>=', $from->toDateString()))
            ->orderBy('id')
            ->each(function (Employee $employee) use ($from, $to, &$count) {
                for ($day = $from; $day->lte($to); $day = $day->addDay()) {
                    $this->processor->process($employee, $day);
                    $count++;
                }
            });

        return $count;
    }
}
