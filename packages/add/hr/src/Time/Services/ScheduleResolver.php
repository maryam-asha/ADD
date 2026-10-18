<?php

namespace Add\Hr\Time\Services;

use Add\Hr\Contracts\HrClock;
use Add\Hr\Core\Models\Employee;
use Add\Hr\Leave\Enums\LeaveRequestStatus;
use Add\Hr\Leave\Services\WorkCalendar;
use Add\Hr\Time\Data\ScheduledDay;
use Add\Hr\Time\Enums\DayKind;
use Add\Hr\Time\Enums\WorkPatternKind;
use Add\Hr\Time\Models\RosterEntry;
use Add\Hr\Time\Models\ShiftTemplate;
use Add\Hr\Time\Models\WorkPattern;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * An employee's plan for a local work date, in this order:
 *
 * 1. not employed that day;
 * 2. a roster entry (a shift or rest) — it wins over everything else, so
 *    someone can be rostered on a holiday;
 * 3. a public holiday;
 * 4. the work pattern on their assignment that day (fixed weekday shifts,
 *    a roster pattern with no entry = rest, flexible = any working day);
 * 5. no pattern: the global calendar (working day or weekend), unplanned.
 *
 * `for()` then turns a working day covered by approved full-day leave
 * into leave.
 */
class ScheduleResolver
{
    public function __construct(
        private readonly HrClock $clock,
        private readonly WorkCalendar $calendar,
    ) {}

    public function for(Employee $employee, CarbonInterface $date): ScheduledDay
    {
        $day = $this->planned($employee, $date);

        if ($day->isWorking() && $this->onLeave($employee, $day->date)) {
            return new ScheduledDay($day->date, DayKind::Leave, $day->planned);
        }

        return $day;
    }

    /**
     * The plan without leave: what leave itself is counted against.
     */
    public function planned(Employee $employee, CarbonInterface $date): ScheduledDay
    {
        $date = CarbonImmutable::parse($date->toDateString());

        if (! $employee->isEmployedOn($date)) {
            return new ScheduledDay($date, DayKind::NotEmployed, false);
        }

        $roster = RosterEntry::query()
            ->where('employee_id', $employee->id)
            ->where('work_date', $date->toDateString())
            ->first();

        if ($roster !== null) {
            return $roster->shift_template_id === null
                ? new ScheduledDay($date, DayKind::Rest, true)
                : $this->shift($date, ShiftTemplate::query()->findOrFail($roster->shift_template_id));
        }

        $patternId = $employee->currentAssignment($date)?->work_pattern_id;
        $pattern = $patternId === null ? null : WorkPattern::query()->find($patternId);

        if ($this->calendar->isHoliday($date)) {
            return new ScheduledDay($date, DayKind::Holiday, $pattern !== null);
        }

        if ($pattern === null) {
            return new ScheduledDay($date, $this->calendar->isWorkingDay($date) ? DayKind::Unscheduled : DayKind::Rest, false);
        }

        return match ($pattern->kind) {
            WorkPatternKind::Fixed => ($shiftId = $pattern->shiftIdFor($date->isoWeekday())) === null
                ? new ScheduledDay($date, DayKind::Rest, true)
                : $this->shift($date, ShiftTemplate::query()->findOrFail($shiftId)),
            WorkPatternKind::Roster => new ScheduledDay($date, DayKind::Rest, true),
            WorkPatternKind::Flexible => new ScheduledDay($date, $this->calendar->isWorkingDay($date) ? DayKind::Flexible : DayKind::Rest, true),
        };
    }

    /**
     * Today, in the organisation's timezone.
     */
    public function today(): CarbonImmutable
    {
        return CarbonImmutable::parse(CarbonImmutable::now($this->clock->timezone())->toDateString());
    }

    /**
     * The local work date an instant falls on, before any night-shift rule.
     */
    public function localDate(CarbonInterface $instant): CarbonImmutable
    {
        return CarbonImmutable::parse(CarbonImmutable::parse($instant)->setTimezone($this->clock->timezone())->toDateString());
    }

    public function timezone(): string
    {
        return $this->clock->timezone();
    }

    private function shift(CarbonImmutable $date, ShiftTemplate $shift): ScheduledDay
    {
        [$start, $end] = $shift->windowOn($date, $this->clock->timezone());

        return new ScheduledDay($date, DayKind::Shift, true, $shift, $start, $end);
    }

    private function onLeave(Employee $employee, CarbonImmutable $date): bool
    {
        return $employee->leaveRequests()
            ->where('status', LeaveRequestStatus::Approved->value)
            ->whereNull('half_day')
            ->whereNull('hours')
            ->overlapping($date, $date)
            ->exists();
    }
}
