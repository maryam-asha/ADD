<?php

namespace Add\Hr\Approvals\Http\Controllers\SelfService;

use Add\Hr\Approvals\Http\Requests\StoreDelegationRequest;
use Add\Hr\Approvals\Http\Resources\DelegationResource;
use Add\Hr\Approvals\Models\ApprovalDelegation;
use Add\Hr\Audit\HrAudit;
use Add\Hr\Core\Models\Employee;
use Add\Hr\Http\Middleware\EnsureActiveEmployee;
use Add\Hr\Support\HrException;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;

/**
 * hr/me/delegations: an approver hands their approvals to a colleague for
 * a period. Only their own delegations are visible or removable.
 */
class DelegationController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        return DelegationResource::collection(
            ApprovalDelegation::query()
                ->where('delegator_employee_id', $this->employee($request)->id)
                ->where('ends_on', '>=', CarbonImmutable::today()->toDateString())
                ->with('delegate')
                ->orderBy('starts_on')
                ->get()
        );
    }

    public function store(StoreDelegationRequest $request): DelegationResource
    {
        $me = $this->employee($request);
        $delegate = Employee::query()->findOrFail($request->validated('delegate_employee_id'));

        if ($delegate->id === $me->id) {
            HrException::fail('hr::approvals.delegate_self');
        }

        if (! $delegate->isEmployedOn(CarbonImmutable::parse($request->validated('starts_on')))) {
            HrException::fail('hr::approvals.delegate_not_employed');
        }

        $delegation = ApprovalDelegation::query()->create([
            ...$request->validated(),
            'delegator_employee_id' => $me->id,
            'created_by' => $request->user()?->getAuthIdentifier(),
        ]);

        HrAudit::record('approval_delegated', $delegation, ['delegate' => $delegate->id]);

        return new DelegationResource($delegation->load('delegate'));
    }

    public function destroy(Request $request, string $delegation): Response
    {
        ApprovalDelegation::query()
            ->where('delegator_employee_id', $this->employee($request)->id)
            ->findOrFail($delegation)
            ->delete();

        return response()->noContent();
    }

    private function employee(Request $request): Employee
    {
        return $request->attributes->get(EnsureActiveEmployee::ATTRIBUTE);
    }
}
