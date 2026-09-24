<?php

namespace App\Http\Controllers\Api\V1\Member;

use App\Domain\Services\Models\ServiceRequest;
use App\Http\Controllers\Controller;
use App\Http\Resources\Services\ServiceRequestResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MemberServiceRequestController extends Controller
{
    /**
     * GET /api/v1/member/service-requests
     * List the authenticated member's own service requests, newest first.
     * Paginated — `?per_page=` (1..100, default 15) and `?page=`.
     */
    public function index(Request $request): JsonResponse
    {
        // Same 1..100 clamp the other member listings use.
        $perPage = max(1, min(100, (int) $request->query('per_page', 15)));

        $paginator = ServiceRequest::with('serviceType', 'branch', 'space')
            ->forUser($request->user()->id)
            ->orderByDesc('submitted_at')
            ->paginate($perPage);

        // The envelope the other member listings use, built by hand rather
        // than letting the resource collection emit Laravel's default: that
        // one adds a `links` block and a `meta.links` array of absolute URLs
        // (built from the app URL, and carrying HTML entities in their
        // labels) that a mobile client has no use for.
        return response()->json([
            'data' => ServiceRequestResource::collection($paginator->items()),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    /**
     * GET /api/v1/member/service-requests/{serviceRequest}
     * Show full detail of a single request (including field values).
     */
    public function show(Request $request, ServiceRequest $serviceRequest): ServiceRequestResource|JsonResponse
    {
        if ($serviceRequest->user_id !== $request->user()->id) {
            return response()->json(['message' => __('api.services.request_not_found')], 404);
        }

        $serviceRequest->load('serviceType', 'branch', 'space', 'values.field');

        return new ServiceRequestResource($serviceRequest);
    }
}
