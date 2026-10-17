<?php

namespace Add\Hr\Approvals\Http\Controllers\SelfService;

use Add\Hr\Approvals\Http\Requests\DecideApprovalRequest;
use Add\Hr\Approvals\Http\Resources\ApprovalRequestResource;
use Add\Hr\Approvals\Models\ApprovalRequest;
use Add\Hr\Approvals\Services\ApprovalEngine;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controller;

/**
 * hr/me/approvals: what waits for the signed-in employee — as the
 * approver, as a delegate, or as the holder of an ability a step asks for.
 * A request they cannot decide answers 404, like a missing one.
 */
class ApprovalInboxController extends Controller
{
    public function __construct(private readonly ApprovalEngine $engine) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $requests = $this->engine->inbox($request->user())
            ->with(['employee', 'subject', 'steps.approverEmployee'])
            ->oldest('id')
            ->paginate(25);

        return ApprovalRequestResource::collection($requests);
    }

    public function show(Request $request, string $approvalRequest): ApprovalRequestResource
    {
        return new ApprovalRequestResource(
            $this->decidable($request, $approvalRequest)->load(['employee', 'subject', 'steps.approverEmployee'])
        );
    }

    public function approve(DecideApprovalRequest $request, string $approvalRequest): JsonResponse
    {
        $this->engine->approve($this->decidable($request, $approvalRequest), $request->user(), $request->validated('comment'));

        return response()->json(['message' => __('hr::approvals.approved')]);
    }

    public function reject(DecideApprovalRequest $request, string $approvalRequest): JsonResponse
    {
        $this->engine->reject($this->decidable($request, $approvalRequest), $request->user(), $request->validated('comment'));

        return response()->json(['message' => __('hr::approvals.rejected')]);
    }

    private function decidable(Request $request, string $id): ApprovalRequest
    {
        $approval = ApprovalRequest::query()->findOrFail($id);

        abort_if($this->engine->decisionSource($approval, $request->user()) === null, 404);

        return $approval;
    }
}
