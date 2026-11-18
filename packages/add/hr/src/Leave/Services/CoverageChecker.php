<?php

namespace Add\Hr\Leave\Services;

use Add\Hr\Core\Models\Assignment;
use Add\Hr\Leave\Enums\LeaveRequestStatus;
use Add\Hr\Leave\Models\CoverageRule;
use Add\Hr\Leave\Models\LeaveRequest;
use Add\Hr\Support\HrException;
use Carbon\CarbonImmutable;

/**
 * Approving a full-day leave may not leave a unit or a position below its
 * minimum headcount on any working day of it. Only approved full-day
 * leaves count as away; pending ones do not hold anyone off yet, and a
 * half day or a few hours leaves the person at work.
 */
class CoverageChecker
{
    public function __construct(private readonly WorkCalendar $calendar) {}

    /**
     * Fails with the first day that would fall short.
     */
    public function assertCovered(LeaveRequest $request): void
    {
        if (! $request->isFullDay()) {
            return;
        }

        $rules = CoverageRule::query()->where('is_active', true)->get();

        if ($rules->isEmpty()) {
            return;
        }

        foreach ($this->calendar->workingDays($request->starts_on, $request->ends_on) as $day) {
            $assignment = $request->employee->currentAssignment($day);

            if ($assignment === null) {
                continue;
            }

            foreach ($rules as $rule) {
                $applies = ($rule->org_unit_id !== null && (int) $rule->org_unit_id === (int) $assignment->org_unit_id)
                    || ($rule->position_id !== null && (int) $rule->position_id === (int) $assignment->position_id);

                if ($applies && $this->presentOn($rule, $day, (int) $request->employee_id) < $rule->min_present) {
                    HrException::fail('hr::leave.coverage_breached', [
                        'date' => $day->toDateString(),
                        'min' => $rule->min_present,
                    ]);
                }
            }
        }
    }

    /**
     * Everyone the rule covers who is employed that day and not on an
     * approved full-day leave, without the requester.
     */
    private function presentOn(CoverageRule $rule, CarbonImmutable $day, int $exceptEmployeeId): int
    {
        $date = $day->toDateString();

        $away = LeaveRequest::query()
            ->where('status', LeaveRequestStatus::Approved->value)
            ->whereNull('half_day')
            ->whereNull('hours')
            ->overlapping($day, $day)
            ->select('employee_id');

        return Assignment::query()
            ->activeOn($day)
            ->when($rule->org_unit_id !== null,
                fn ($q) => $q->where('org_unit_id', $rule->org_unit_id),
                fn ($q) => $q->where('position_id', $rule->position_id))
            ->where('employee_id', '!=', $exceptEmployeeId)
            ->whereNotIn('employee_id', $away)
            ->whereHas('employee', fn ($q) => $q->where('hired_on', '<=', $date)
                ->where(fn ($q) => $q->whereNull('terminated_on')->orWhere('terminated_on', '>=', $date)))
            ->distinct()
            ->count('employee_id');
    }
}
