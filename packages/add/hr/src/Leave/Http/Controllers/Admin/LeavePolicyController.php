<?php

namespace Add\Hr\Leave\Http\Controllers\Admin;

use Add\Hr\Leave\Http\Requests\StoreLeavePolicyRequest;
use Add\Hr\Leave\Http\Requests\UpdateLeavePolicyRequest;
use Add\Hr\Leave\Http\Resources\LeavePolicyResource;
use Add\Hr\Leave\Models\LeavePolicy;
use Add\Hr\Leave\Services\LeaveAccrual;
use Add\Hr\Support\LocalDay;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;

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

    /**
     * A policy in force today credits what it owes at once, so HR sees the
     * balances without waiting for the night (LeaveAccrual::forType).
     */
    public function store(StoreLeavePolicyRequest $request, LeaveAccrual $accrual): LeavePolicyResource
    {
        $policy = DB::transaction(function () use ($request, $accrual) {
            $policy = LeavePolicy::query()->create($request->validated())->refresh();
            $accrual->forType($policy->leaveType, LocalDay::today());

            return $policy;
        });

        return new LeavePolicyResource($policy);
    }

    public function show(LeavePolicy $leavePolicy): LeavePolicyResource
    {
        return new LeavePolicyResource($leavePolicy);
    }

    /**
     * Credits whatever the changed policy now owes and was not yet booked
     * (someone newly in its scope, a cycle it now reaches). A grant already
     * booked keeps the amount it was booked at: a correction to it is a
     * balance adjustment.
     */
    public function update(UpdateLeavePolicyRequest $request, LeavePolicy $leavePolicy, LeaveAccrual $accrual): JsonResponse
    {
        DB::transaction(function () use ($request, $leavePolicy, $accrual) {
            $leavePolicy->update($request->validated());
            $accrual->forType($leavePolicy->leaveType, LocalDay::today());
        });

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
