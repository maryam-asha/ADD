<?php

namespace Add\Hr\Time\Http\Controllers\Admin;

use Add\Hr\Core\Models\Employee;
use Add\Hr\Time\Http\Requests\CopyRosterWeekRequest;
use Add\Hr\Time\Http\Requests\RangeRequest;
use Add\Hr\Time\Http\Requests\SaveRosterRequest;
use Add\Hr\Time\Models\RosterEntry;
use Add\Hr\Time\Services\RosterService;
use Add\Hr\Time\Services\ScheduleResolver;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

/**
 * The roster grid: every employee of a unit (or one employee) with each
 * day's plan as ScheduleResolver sees it, and whether a roster entry set it.
 */
class RosterController extends Controller
{
    public function index(RangeRequest $request, ScheduleResolver $schedule): JsonResponse
    {
        $from = CarbonImmutable::parse($request->validated('from'));
        $to = CarbonImmutable::parse($request->validated('to'));

        $employees = Employee::query()
            ->when($request->validated('employee_id'), fn ($q, $id) => $q->whereKey($id))
            ->when($request->validated('org_unit_id'), fn ($q, $unit) => $q->whereHas('assignments', fn ($q) => $q
                ->where('org_unit_id', $unit)
                ->where('effective_from', '<=', $to->toDateString())
                ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>=', $from->toDateString()))))
            ->where('hired_on', '<=', $to->toDateString())
            ->where(fn ($q) => $q->whereNull('terminated_on')->orWhere('terminated_on', '>=', $from->toDateString()))
            ->orderBy('id')
            ->limit(200)
            ->get();

        return response()->json(['data' => $employees->map(fn (Employee $employee) => [
            'employee' => ['id' => $employee->id, 'employee_number' => $employee->employee_number, 'name' => $employee->name],
            'days' => $this->days($schedule, $employee, $from, $to),
        ])->values()]);
    }

    public function save(SaveRosterRequest $request, RosterService $roster): JsonResponse
    {
        $roster->save($request->validated('entries'), $request->user()?->getAuthIdentifier());

        return response()->json(['message' => __('hr::time.roster_saved')]);
    }

    public function copyWeek(CopyRosterWeekRequest $request, RosterService $roster): JsonResponse
    {
        $from = CarbonImmutable::parse($request->validated('from'));
        $employeeIds = $request->validated('employee_ids') ?? Employee::query()
            ->whereHas('assignments', fn ($q) => $q->where('org_unit_id', $request->validated('org_unit_id'))->activeOn($from))
            ->pluck('id')
            ->all();

        $count = $roster->copyWeek($from, CarbonImmutable::parse($request->validated('to')), $employeeIds, $request->user()?->getAuthIdentifier());

        return response()->json(['message' => __('hr::time.roster_copied', ['count' => $count])]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function days(ScheduleResolver $schedule, Employee $employee, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $rostered = RosterEntry::query()
            ->where('employee_id', $employee->id)
            ->whereBetween('work_date', [$from->toDateString(), $to->toDateString()])
            ->get()
            ->keyBy(fn ($entry) => $entry->work_date->toDateString());
        $days = [];

        for ($day = $from; $day->lte($to); $day = $day->addDay()) {
            $plan = $schedule->for($employee, $day);
            $days[] = [
                'date' => $day->toDateString(),
                'kind' => $plan->kind->value,
                'shift_template_id' => $plan->shift?->id,
                'rostered' => $rostered->has($day->toDateString()),
            ];
        }

        return $days;
    }
}
