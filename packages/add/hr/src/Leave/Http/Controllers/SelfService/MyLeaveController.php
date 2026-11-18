<?php

namespace Add\Hr\Leave\Http\Controllers\SelfService;

use Add\Hr\Core\Models\Employee;
use Add\Hr\Http\Middleware\EnsureActiveEmployee;
use Add\Hr\Leave\Http\Requests\SubmitLeaveRequest;
use Add\Hr\Leave\Http\Resources\LeaveRequestResource;
use Add\Hr\Leave\Http\Resources\LedgerEntryResource;
use Add\Hr\Leave\Models\LeaveType;
use Add\Hr\Leave\Models\LedgerEntry;
use Add\Hr\Leave\Services\LeaveLedger;
use Add\Hr\Leave\Services\LeaveService;
use Add\Hr\Support\PlainNumber;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controller;

/**
 * hr/me/leave: the employee's balances, ledger and requests. The employee
 * always comes from the request; another employee's request answers 404.
 */
class MyLeaveController extends Controller
{
    public function __construct(private readonly LeaveService $leave) {}

    /**
     * What the employee may request: the balances list, which already
     * leaves out types no policy gives them.
     */
    public function balances(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->leave->balances($this->employee($request))]);
    }

    public function ledger(Request $request): AnonymousResourceCollection
    {
        return LedgerEntryResource::collection(
            LedgerEntry::query()
                ->where('employee_id', $this->employee($request)->id)
                ->when($request->query('leave_type_id'), fn ($q, $id) => $q->where('leave_type_id', $id))
                ->orderBy('effective_on')
                ->orderBy('id')
                ->paginate(50)
        );
    }

    /**
     * How much a request would take and what would be left, without filing it.
     */
    public function quote(SubmitLeaveRequest $request, LeaveLedger $ledger): JsonResponse
    {
        $employee = $this->employee($request);
        $type = LeaveType::query()->where('is_active', true)->findOrFail($request->validated('leave_type_id'));
        $amount = $this->leave->quote($type, $request->validated(), $employee);
        $available = $type->tracks_balance
            ? $ledger->balance($employee, $type) - $ledger->pending($employee, $type)
            : null;

        return response()->json(['data' => [
            'amount' => PlainNumber::of($amount),
            'unit' => $type->unit->value,
            'available' => PlainNumber::of($available),
            'remaining_after' => $available === null ? null : PlainNumber::of($available - $amount),
        ]]);
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        return LeaveRequestResource::collection(
            $this->employee($request)->leaveRequests()
                ->with(['employee', 'leaveType', 'approval'])
                ->latest('starts_on')
                ->latest('id')
                ->paginate(25)
        );
    }

    public function store(SubmitLeaveRequest $request): LeaveRequestResource
    {
        $created = $this->leave->submit(
            $this->employee($request),
            $request->validated(),
            $request->file('attachment'),
            $request->user()?->getAuthIdentifier(),
        );

        return new LeaveRequestResource($created->load(['employee', 'leaveType', 'approval']));
    }

    public function cancel(Request $request, string $leaveRequest): JsonResponse
    {
        $this->leave->cancel(
            $this->employee($request)->leaveRequests()->findOrFail($leaveRequest),
            $request->user()?->getAuthIdentifier(),
        );

        return response()->json(['message' => __('hr::leave.cancelled')]);
    }

    private function employee(Request $request): Employee
    {
        return $request->attributes->get(EnsureActiveEmployee::ATTRIBUTE);
    }
}
