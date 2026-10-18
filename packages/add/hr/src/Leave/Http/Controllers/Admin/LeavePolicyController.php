<?php

namespace Add\Hr\Leave\Http\Controllers\Admin;

use Add\Hr\Leave\Http\Requests\StoreLeavePolicyRequest;
use Add\Hr\Leave\Http\Requests\UpdateLeavePolicyRequest;
use Add\Hr\Leave\Http\Resources\LeavePolicyResource;
use Add\Hr\Leave\Models\LeavePolicy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;

class LeavePolicyController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        return LeavePolicyResource::collection(
            LeavePolicy::query()
                ->when($request->query('leave_type_id'), fn ($q, $id) => $q->where('leave_type_id', $id))
                ->orderBy('leave_type_id')
                ->orderBy('effective_from')
                ->get()
        );
    }

    public function store(StoreLeavePolicyRequest $request): LeavePolicyResource
    {
        return new LeavePolicyResource(LeavePolicy::query()->create($request->validated())->refresh());
    }

    public function show(LeavePolicy $leavePolicy): LeavePolicyResource
    {
        return new LeavePolicyResource($leavePolicy);
    }

    public function update(UpdateLeavePolicyRequest $request, LeavePolicy $leavePolicy): JsonResponse
    {
        $leavePolicy->update($request->validated());

        return response()->json(['message' => __('hr::leave.policy_updated')]);
    }

    /**
     * Booked accruals stay in the ledger; only future ones stop.
     */
    public function destroy(LeavePolicy $leavePolicy): Response
    {
        $leavePolicy->delete();

        return response()->noContent();
    }
}
