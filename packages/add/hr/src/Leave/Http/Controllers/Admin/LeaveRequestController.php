<?php

namespace Add\Hr\Leave\Http\Controllers\Admin;

use Add\Hr\Core\Models\Employee;
use Add\Hr\Leave\Http\Requests\SubmitLeaveRequest;
use Add\Hr\Leave\Http\Resources\LeaveRequestResource;
use Add\Hr\Leave\Models\LeaveRequest;
use Add\Hr\Leave\Services\LeaveService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controller;

/**
 * Every leave request, and HR filing or cancelling one for an employee.
 * A request HR files still goes through the employee's approval flow.
 */
class LeaveRequestController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $requests = LeaveRequest::query()
            ->with(['employee', 'leaveType', 'approval'])
            ->when($request->query('status'), fn ($q, $status) => $q->where('status', $status))
            ->when($request->query('employee_id'), fn ($q, $id) => $q->where('employee_id', $id))
            ->when($request->query('leave_type_id'), fn ($q, $id) => $q->where('leave_type_id', $id))
            ->when($request->date('from'), fn ($q, $from) => $q->where('ends_on', '>=', $from->toDateString()))
            ->when($request->date('to'), fn ($q, $to) => $q->where('starts_on', '<=', $to->toDateString()))
            ->latest('starts_on')
            ->latest('id')
            ->paginate(25);

        return LeaveRequestResource::collection($requests);
    }

    public function show(LeaveRequest $leaveRequest): LeaveRequestResource
    {
        return new LeaveRequestResource($leaveRequest->load(['employee', 'leaveType', 'approval']));
    }

    public function store(SubmitLeaveRequest $request, Employee $employee, LeaveService $leave): LeaveRequestResource
    {
        $created = $leave->submit($employee, $request->validated(), $request->file('attachment'), $request->user()?->getAuthIdentifier());

        return new LeaveRequestResource($created->load(['employee', 'leaveType', 'approval']));
    }

    public function cancel(Request $request, LeaveRequest $leaveRequest, LeaveService $leave): JsonResponse
    {
        $leave->cancel($leaveRequest, $request->user()?->getAuthIdentifier());

        return response()->json(['message' => __('hr::leave.cancelled')]);
    }
}
