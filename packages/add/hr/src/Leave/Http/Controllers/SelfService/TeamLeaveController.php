<?php

namespace Add\Hr\Leave\Http\Controllers\SelfService;

use Add\Hr\Core\Models\Employee;
use Add\Hr\Http\Middleware\EnsureActiveEmployee;
use Add\Hr\Leave\Models\LeaveRequest;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * hr/me/team/leave: the manager's direct reports away (or asking to be) in
 * a range of at most three months. A type marked hide_reason shows as
 * plain leave, and no reason is ever shown here.
 */
class TeamLeaveController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'from' => ['required', 'date'],
            'to' => ['required', 'date', 'after_or_equal:from', 'before_or_equal:'.CarbonImmutable::parse((string) $request->query('from', 'today'))->addMonths(3)->toDateString()],
        ]);
        $from = CarbonImmutable::parse($request->query('from'));
        $to = CarbonImmutable::parse($request->query('to'));
        /** @var Employee $me */
        $me = $request->attributes->get(EnsureActiveEmployee::ATTRIBUTE);

        $leave = LeaveRequest::query()
            ->live()
            ->overlapping($from, $to)
            ->whereHas('employee.assignments', fn ($q) => $q->activeOn(CarbonImmutable::today())->where('manager_id', $me->id))
            ->with(['employee', 'leaveType'])
            ->orderBy('starts_on')
            ->get();

        return response()->json(['data' => $leave->map(fn (LeaveRequest $request) => [
            'id' => $request->id,
            'employee' => ['id' => $request->employee->id, 'name' => $request->employee->name],
            'leave_type' => $request->leaveType->hide_reason
                ? null
                : ['id' => $request->leaveType->id, 'code' => $request->leaveType->code, 'name' => $request->leaveType->name],
            'starts_on' => $request->starts_on->toDateString(),
            'ends_on' => $request->ends_on->toDateString(),
            'half_day' => $request->half_day?->value,
            'hours' => $request->hours,
            'status' => $request->status->value,
        ])->values()]);
    }
}
