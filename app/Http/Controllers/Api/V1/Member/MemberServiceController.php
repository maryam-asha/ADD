<?php

namespace App\Http\Controllers\Api\V1\Member;

use App\Domain\Services\Services\ServiceCatalogService;
use App\Domain\Services\Services\ServiceRequestService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Services\StoreServiceRequestRequest;
use App\Http\Resources\Services\ServiceAxisResource;
use App\Http\Resources\Services\ServiceRequestResource;
use App\Http\Resources\Services\ServiceTypeResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpFoundation\Response;

class MemberServiceController extends Controller
{
    public function __construct(
        private readonly ServiceCatalogService $catalog,
        private readonly ServiceRequestService $requestService,
    ) {}

    /**
     * GET /api/v1/member/services
     * Return all axes with their accessible service types for the authenticated member.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $axes = $this->catalog->forMember($request->user());

        return ServiceAxisResource::collection($axes);
    }

    /**
     * GET /api/v1/member/services/{slug}
     * Return a single service type with its form fields (for form rendering).
     */
    public function show(Request $request, string $slug): ServiceTypeResource|JsonResponse
    {
        $serviceType = $this->catalog->findForMember($slug, $request->user());

        if ($serviceType === null) {
            return response()->json(['message' => __('api.services.not_found')], Response::HTTP_NOT_FOUND);
        }

        return new ServiceTypeResource($serviceType);
    }

    /**
     * POST /api/v1/member/services/{slug}/requests
     * Submit a new service request (only for form-based service types).
     */
    public function submitRequest(StoreServiceRequestRequest $request, string $slug): ServiceRequestResource|JsonResponse
    {
        $serviceType = $this->catalog->findForMember($slug, $request->user());

        if ($serviceType === null) {
            return response()->json(['message' => __('api.services.not_found')], Response::HTTP_NOT_FOUND);
        }

        if (! $serviceType->createsRequest()) {
            return response()->json(
                ['message' => __('api.services.not_form_based')],
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        $serviceRequest = $this->requestService->submit(
            type:           $serviceType,
            member:         $request->user(),
            fieldValues:    $request->input('fields', []),
            branchId:       $request->input('branch_id'),
            spaceId:        $request->input('space_id'),
            locationSource: $request->locationSource(),
        );

        return (new ServiceRequestResource($serviceRequest))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }
}
