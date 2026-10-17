<?php

namespace Add\Hr\Leave\Http\Controllers\Admin;

use Add\Hr\Leave\Http\Requests\StoreLeaveTypeRequest;
use Add\Hr\Leave\Http\Requests\UpdateLeaveTypeRequest;
use Add\Hr\Leave\Http\Resources\LeaveTypeResource;
use Add\Hr\Leave\Models\LeaveRequest;
use Add\Hr\Leave\Models\LeaveType;
use Add\Hr\Leave\Models\LedgerEntry;
use Add\Hr\Support\HrException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;

class LeaveTypeController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return LeaveTypeResource::collection(LeaveType::query()->orderBy('id')->get());
    }

    public function store(StoreLeaveTypeRequest $request): LeaveTypeResource
    {
        return new LeaveTypeResource(LeaveType::query()->create($request->validated())->refresh());
    }

    public function show(LeaveType $leaveType): LeaveTypeResource
    {
        return new LeaveTypeResource($leaveType);
    }

    public function update(UpdateLeaveTypeRequest $request, LeaveType $leaveType): JsonResponse
    {
        $leaveType->update($request->validated());

        return response()->json(['message' => __('hr::leave.type_updated')]);
    }

    /**
     * A type with requests, balances or policies stays; deactivate it.
     */
    public function destroy(LeaveType $leaveType): Response
    {
        if ($leaveType->policies()->exists()
            || LeaveRequest::query()->where('leave_type_id', $leaveType->id)->exists()
            || LedgerEntry::query()->where('leave_type_id', $leaveType->id)->exists()) {
            HrException::fail('hr::leave.type_in_use', [], 409);
        }

        $leaveType->delete();

        return response()->noContent();
    }
}
