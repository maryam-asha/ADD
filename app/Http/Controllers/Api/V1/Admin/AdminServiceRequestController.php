<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Services\Enums\RequestStatus;
use App\Domain\Services\Models\ServiceRequest;
use App\Domain\Services\Services\ServiceRequestService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Services\Admin\UpdateServiceRequestStatusRequest;
use App\Http\Resources\Services\ServiceRequestResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class AdminServiceRequestController extends Controller
{
    public function __construct(
        private readonly ServiceRequestService $requestService,
    ) {}

    /**
     * GET /api/v1/admin/service-requests
     *
     * All staff see all requests. Optional filters:
     *   ?branch_id=   filter by branch
     *   ?status=      filter by status
     *   ?service_type_id=  filter by service type
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = ServiceRequest::with('serviceType', 'user', 'branch', 'space')
            ->orderByDesc('submitted_at');

        if ($branchId = $request->integer('branch_id') ?: null) {
            $query->forBranch($branchId);
        }

        if ($status = $request->string('status')->toString()) {
            $resolved = RequestStatus::tryFrom($status);
            if ($resolved) {
                $query->withStatus($resolved);
            }
        }

        if ($typeId = $request->integer('service_type_id') ?: null) {
            $query->where('service_type_id', $typeId);
        }

        return ServiceRequestResource::collection($query->paginate(20));
    }

    /**
     * GET /api/v1/admin/service-requests/{serviceRequest}
     */
    public function show(ServiceRequest $serviceRequest): ServiceRequestResource
    {
        $serviceRequest->load('serviceType', 'user', 'branch', 'space', 'values.field');

        return new ServiceRequestResource($serviceRequest);
    }

    /**
     * PATCH /api/v1/admin/service-requests/{serviceRequest}
     * Update status + optional resolution note.
     */
    public function update(
        UpdateServiceRequestStatusRequest $request,
        ServiceRequest $serviceRequest,
    ): JsonResponse {
        $this->requestService->updateStatus(
            serviceRequest: $serviceRequest,
            newStatus:      RequestStatus::from($request->validated('status')),
            resolutionNote: $request->validated('resolution_note'),
        );

        return response()->json(['message' => __('api.services.request_updated')]);
    }
}
