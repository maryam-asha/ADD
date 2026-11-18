<?php

namespace Add\Hr\Time\Http\Controllers\SelfService;

use Add\Hr\Core\Models\Employee;
use Add\Hr\Http\Middleware\EnsureActiveEmployee;
use Add\Hr\Time\Http\Requests\RangeRequest;
use Add\Hr\Time\Http\Requests\SubmitAttendanceEntryRequest;
use Add\Hr\Time\Http\Requests\SubmitOvertimeRequest;
use Add\Hr\Time\Http\Resources\AttendanceDayResource;
use Add\Hr\Time\Http\Resources\AttendanceEntryResource;
use Add\Hr\Time\Http\Resources\OvertimeRequestResource;
use Add\Hr\Time\Models\AttendanceDay;
use Add\Hr\Time\Models\AttendanceEntry;
use Add\Hr\Time\Models\OvertimeRequest;
use Add\Hr\Time\Services\AttendanceEntryService;
use Add\Hr\Time\Services\OvertimeService;
use Add\Hr\Time\Services\ScheduleResolver;
use Add\Hr\Time\Support\LocalTime;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controller;

/**
 * hr/me: my schedule, my attendance, my own time entries and overtime, and
 * my direct reports' day. Someone else's entry or request answers 404.
 */
class MyAttendanceController extends Controller
{
    public function schedule(RangeRequest $request, ScheduleResolver $schedule): JsonResponse
    {
        $employee = $this->employee($request);
        $days = [];

        for ($day = CarbonImmutable::parse($request->validated('from')); $day->lte(CarbonImmutable::parse($request->validated('to'))); $day = $day->addDay()) {
            $plan = $schedule->for($employee, $day);
            $days[] = [
                'date' => $day->toDateString(),
                'kind' => $plan->kind->value,
                'shift' => $plan->shift === null ? null : [
                    'id' => $plan->shift->id,
                    'name' => $plan->shift->name,
                    'starts_at' => LocalTime::iso($plan->start),
                    'ends_at' => LocalTime::iso($plan->end),
                ],
            ];
        }

        return response()->json(['data' => $days]);
    }

    public function days(RangeRequest $request): AnonymousResourceCollection
    {
        return AttendanceDayResource::collection(
            AttendanceDay::query()
                ->where('employee_id', $this->employee($request)->id)
                ->whereBetween('work_date', [$request->validated('from'), $request->validated('to')])
                ->with(['shiftTemplate', 'exceptions'])
                ->orderBy('work_date')
                ->get()
        );
    }

    public function entries(Request $request): AnonymousResourceCollection
    {
        return AttendanceEntryResource::collection(
            AttendanceEntry::query()->where('employee_id', $this->employee($request)->id)->latest('work_date')->latest('id')->paginate(25)
        );
    }

    public function storeEntry(SubmitAttendanceEntryRequest $request, AttendanceEntryService $entries): AttendanceEntryResource
    {
        return new AttendanceEntryResource(
            $entries->submit($this->employee($request), $request->validated(), $request->user()?->getAuthIdentifier())
        );
    }

    public function cancelEntry(Request $request, string $entry, AttendanceEntryService $entries): JsonResponse
    {
        $entries->cancel(AttendanceEntry::query()->where('employee_id', $this->employee($request)->id)->findOrFail($entry));

        return response()->json(['message' => __('hr::time.cancelled')]);
    }

    public function overtime(Request $request): AnonymousResourceCollection
    {
        return OvertimeRequestResource::collection(
            OvertimeRequest::query()->where('employee_id', $this->employee($request)->id)->latest('work_date')->latest('id')->paginate(25)
        );
    }

    public function storeOvertime(SubmitOvertimeRequest $request, OvertimeService $overtime): OvertimeRequestResource
    {
        return new OvertimeRequestResource(
            $overtime->submit($this->employee($request), $request->validated(), $request->user()?->getAuthIdentifier())
        );
    }

    public function cancelOvertime(Request $request, string $overtimeRequest, OvertimeService $overtime): JsonResponse
    {
        $overtime->cancel(OvertimeRequest::query()->where('employee_id', $this->employee($request)->id)->findOrFail($overtimeRequest));

        return response()->json(['message' => __('hr::time.cancelled')]);
    }

    /**
     * My direct reports on one day (today by default): their plan and what
     * their attendance shows so far.
     */
    public function team(Request $request, ScheduleResolver $schedule): JsonResponse
    {
        $request->validate(['date' => ['nullable', 'date']]);
        $date = $request->filled('date') ? CarbonImmutable::parse($request->input('date')) : $schedule->today();
        $me = $this->employee($request);

        $team = Employee::query()
            ->whereHas('assignments', fn ($q) => $q->activeOn($date)->where('manager_id', $me->id))
            ->orderBy('id')
            ->get()
            ->filter(fn (Employee $employee) => $employee->isEmployedOn($date));

        $days = AttendanceDay::query()
            ->whereIn('employee_id', $team->pluck('id'))
            ->where('work_date', $date->toDateString())
            ->get()
            ->keyBy('employee_id');

        return response()->json(['data' => $team->map(function (Employee $employee) use ($schedule, $date, $days) {
            $day = $days->get($employee->id);

            return [
                'employee' => ['id' => $employee->id, 'name' => $employee->name],
                'plan' => $schedule->for($employee, $date)->kind->value,
                'status' => $day?->status->value,
                'first_in' => LocalTime::iso($day?->first_in),
                'last_out' => LocalTime::iso($day?->last_out),
                'late_minutes' => $day?->late_minutes,
            ];
        })->values()]);
    }

    private function employee(Request $request): Employee
    {
        return $request->attributes->get(EnsureActiveEmployee::ATTRIBUTE);
    }
}
