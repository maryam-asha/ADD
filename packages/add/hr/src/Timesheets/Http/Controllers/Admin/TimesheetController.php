<?php

namespace Add\Hr\Timesheets\Http\Controllers\Admin;

use Add\Hr\Core\Models\Employee;
use Add\Hr\Timesheets\Enums\TimesheetStatus;
use Add\Hr\Timesheets\Http\Resources\TimesheetResource;
use Add\Hr\Timesheets\Models\Timesheet;
use Add\Hr\Timesheets\Services\TimesheetReconciliation;
use Add\Hr\Timesheets\Services\TimesheetWeeks;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controller;

/**
 * Every timesheet, one with its reconciliation, and who has not handed in
 * a week. Decisions go through the approval engine.
 */
class TimesheetController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate(['week_start' => ['nullable', 'date'], 'status' => ['nullable', 'string'], 'employee_id' => ['nullable', 'integer']]);

        return TimesheetResource::collection(
            Timesheet::query()
                ->with('employee')
                ->when($request->input('week_start'), fn ($q, $week) => $q->where('week_start', TimesheetWeeks::parse($week)->toDateString()))
                ->when($request->input('status'), fn ($q, $status) => $q->where('status', $status))
                ->when($request->input('employee_id'), fn ($q, $id) => $q->where('employee_id', $id))
                ->latest('week_start')
                ->orderBy('employee_id')
                ->paginate(50)
        );
    }

    public function show(Timesheet $timesheet, TimesheetReconciliation $reconciliation): TimesheetResource
    {
        return (new TimesheetResource($timesheet->load(['employee', 'entries'])))
            ->additional(['reconciliation' => $reconciliation->for($timesheet->employee, $timesheet->week_start, $timesheet)]);
    }

    /**
     * Employees employed during the week (optionally of one unit) with no
     * submitted or approved timesheet for it.
     */
    public function compliance(Request $request): JsonResponse
    {
        $request->validate(['week_start' => ['required', 'date'], 'org_unit_id' => ['nullable', 'integer']]);
        $week = TimesheetWeeks::parse($request->input('week_start'));
        $end = $week->addDays(6);

        $missing = Employee::query()
            ->where('hired_on', '<=', $end->toDateString())
            ->where(fn ($q) => $q->whereNull('terminated_on')->orWhere('terminated_on', '>=', $week->toDateString()))
            ->when($request->input('org_unit_id'), fn ($q, $unit) => $q->whereHas('assignments', fn ($q) => $q
                ->where('org_unit_id', $unit)
                ->where('effective_from', '<=', $end->toDateString())
                ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>=', $week->toDateString()))))
            ->whereNotIn('id', Timesheet::query()
                ->where('week_start', $week->toDateString())
                ->whereIn('status', [TimesheetStatus::Submitted->value, TimesheetStatus::Approved->value])
                ->select('employee_id'))
            ->orderBy('id')
            ->get();

        return response()->json(['data' => [
            'week_start' => $week->toDateString(),
            'missing' => $missing->map(fn (Employee $employee) => [
                'id' => $employee->id,
                'employee_number' => $employee->employee_number,
                'name' => $employee->name,
                'status' => Timesheet::query()->where('employee_id', $employee->id)->where('week_start', $week->toDateString())->value('status'),
            ])->values(),
        ]]);
    }
}
