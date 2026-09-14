<?php

namespace App\Http\Controllers\Api\V1\Member;

use App\Domain\Services\Models\ServiceRequest;
use App\Http\Controllers\Controller;
use App\Http\Resources\Services\ServiceRequestResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class MemberServiceRequestController extends Controller
{
    /**
     * GET /api/v1/member/service-requests
     * List the authenticated member's own service requests, newest first.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $requests = ServiceRequest::with('serviceType', 'branch', 'space')
            ->forUser($request->user()->id)
            ->orderByDesc('submitted_at')
            ->paginate(15);

        return ServiceRequestResource::collection($requests);
    }

    /**
     * GET /api/v1/member/service-requests/{serviceRequest}
     * Show full detail of a single request (including field values).
     */
    public function show(Request $request, ServiceRequest $serviceRequest): ServiceRequestResource|\Illuminate\Http\JsonResponse
    {
        if ($serviceRequest->user_id !== $request->user()->id) {
            return response()->json(['message' => __('api.services.request_not_found')], 404);
        }

        $serviceRequest->load('serviceType', 'branch', 'space', 'values.field');

        return new ServiceRequestResource($serviceRequest);
    }
}
