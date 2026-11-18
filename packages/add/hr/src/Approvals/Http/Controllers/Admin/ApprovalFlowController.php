<?php

namespace Add\Hr\Approvals\Http\Controllers\Admin;

use Add\Hr\Approvals\Http\Requests\StoreApprovalFlowRequest;
use Add\Hr\Approvals\Http\Requests\UpdateApprovalFlowRequest;
use Add\Hr\Approvals\Http\Resources\ApprovalFlowResource;
use Add\Hr\Approvals\Models\ApprovalFlow;
use Add\Hr\Approvals\Services\ApprovalTypes;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;

/**
 * Flows are copied into each request when it is submitted: editing or
 * deleting one changes only what is submitted afterwards.
 */
class ApprovalFlowController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return ApprovalFlowResource::collection(ApprovalFlow::query()->orderBy('request_type')->get());
    }

    /**
     * The request types a flow can be defined for.
     */
    public function types(ApprovalTypes $types): JsonResponse
    {
        return response()->json(['data' => $types->types()]);
    }

    public function store(StoreApprovalFlowRequest $request): ApprovalFlowResource
    {
        return new ApprovalFlowResource(ApprovalFlow::query()->create([
            ...$request->safe()->only(['request_type', 'name', 'is_active']),
            'steps' => $request->steps(),
        ]));
    }

    public function show(ApprovalFlow $approvalFlow): ApprovalFlowResource
    {
        return new ApprovalFlowResource($approvalFlow);
    }

    public function update(UpdateApprovalFlowRequest $request, ApprovalFlow $approvalFlow): JsonResponse
    {
        $approvalFlow->fill($request->safe()->only(['name', 'is_active']));

        if (($steps = $request->steps()) !== null) {
            $approvalFlow->steps = $steps;
        }

        $approvalFlow->save();

        return response()->json(['message' => __('hr::approvals.flow_updated')]);
    }

    /**
     * Without a flow the type goes back to the direct manager alone.
     */
    public function destroy(ApprovalFlow $approvalFlow): Response
    {
        $approvalFlow->delete();

        return response()->noContent();
    }
}
