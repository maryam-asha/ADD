<?php

namespace Add\Hr\Approvals\Http\Controllers\Admin;

use Add\Hr\Approvals\Http\Requests\DecideApprovalRequest;
use Add\Hr\Approvals\Http\Resources\ApprovalRequestResource;
use Add\Hr\Approvals\Models\ApprovalRequest;
use Add\Hr\Approvals\Services\ApprovalEngine;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controller;

/**
 * Every request, whoever it waits for, and deciding one for an approver
 * who is away (recorded as an override). Separation of duties still holds:
 * nobody overrides on a request of their own.
 */
class ApprovalRequestController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $requests = ApprovalRequest::query()
            ->with(['employee', 'subject', 'steps.approverEmployee'])
            ->when($request->query('status'), fn ($q, $status) => $q->where('status', $status))
            ->when($request->query('request_type'), fn ($q, $type) => $q->where('request_type', $type))
            ->when($request->query('employee_id'), fn ($q, $id) => $q->where('employee_id', $id))
            ->latest('id')
            ->paginate(25);

        return ApprovalRequestResource::collection($requests);
    }

    public function show(ApprovalRequest $approvalRequest): ApprovalRequestResource
    {
        return new ApprovalRequestResource($approvalRequest->load(['employee', 'subject', 'steps.approverEmployee']));
    }

    public function approve(DecideApprovalRequest $request, ApprovalRequest $approvalRequest, ApprovalEngine $engine): JsonResponse
    {
        $engine->approve($approvalRequest, $request->user(), $request->validated('comment'), override: true);

        return response()->json(['message' => __('hr::approvals.approved')]);
    }

    public function reject(DecideApprovalRequest $request, ApprovalRequest $approvalRequest, ApprovalEngine $engine): JsonResponse
    {
        $engine->reject($approvalRequest, $request->user(), $request->validated('comment'), override: true);

        return response()->json(['message' => __('hr::approvals.rejected')]);
    }
}
