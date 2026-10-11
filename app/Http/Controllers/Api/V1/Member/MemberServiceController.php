<?php

namespace App\Http\Controllers\Api\V1\Member;

use App\Domain\Services\Services\ServiceCatalogService;
use App\Domain\Services\Services\ServiceRequestService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Services\StoreServiceRequestRequest;
use App\Http\Resources\Forms\FormFieldResource;
use App\Http\Resources\Services\ServiceAxisResource;
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
     * Supports ?axis={slug} to filter by a single axis, or ?flat=1 to return a flat list.
     */
    public function index(Request $request): AnonymousResourceCollection|JsonResponse
    {
        // ?flat=1 delegates to the paginated flat list, so this route returns
        // that endpoint's data+meta envelope rather than a bare collection.
        if ($request->boolean('flat')) {
            return $this->all($request);
        }

        $axes = $this->catalog->forMember($request->user(), $request->query('axis'));

        return ServiceAxisResource::collection($axes);
    }

    /**
     * GET /api/v1/member/services/axes
     * Return main service sections (axes) separately (e.g. ADD Core, ADD Experience).
     */
    public function axes(Request $request): JsonResponse
    {
        $axes = $this->catalog->axesForMember($request->user());

        return response()->json([
            'data' => ServiceAxisResource::collection($axes),
        ]);
    }

    /**
     * GET /api/v1/member/services/axes/{axis}
     * Return a specific service section and its services.
     */
    public function axisServices(Request $request, string $axis): ServiceAxisResource|JsonResponse
    {
        $axisModel = $this->catalog->servicesForAxis($axis, $request->user());

        if ($axisModel === null) {
            return response()->json(['message' => __('api.services.not_found')], Response::HTTP_NOT_FOUND);
        }

        return new ServiceAxisResource($axisModel);
    }

    /**
     * GET /api/v1/member/services/all
     * Return all accessible services as a flat list without grouping by axes.
     * Paginated — `?per_page=` (1..100, default 15) and `?page=`.
     */
    public function all(Request $request): JsonResponse
    {
        $paginator = $this->catalog->allServicesForMember($request->user(), $request->all());

        // Same envelope the other member listings use: the rows under `data`,
        // the page counters under `meta`.
        return response()->json([
            'data' => ServiceTypeResource::collection($paginator->items()),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
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
     * GET /api/v1/member/services/{id}/registration-form
     * Fetch the service dynamic form directly, mirroring the events registration-form endpoint,
     * which is keyed on the id too.
     */
    public function registrationForm(Request $request, int|string $id): JsonResponse
    {
        $serviceType = $this->catalog->findByIdForMember($id, $request->user());

        if ($serviceType === null) {
            return response()->json(['message' => __('api.services.not_found')], Response::HTTP_NOT_FOUND);
        }

        return response()->json([
            'data' => [
                'external_form_url' => null,
                'request_method' => $serviceType->request_method->value,
                'registration_form' => FormFieldResource::collection($serviceType->requestForm?->activeFields ?? collect()),
            ],
        ]);
    }

    /**
     * POST /api/v1/member/services/{id}/requests
     * Submit a new service request (only for form-based service types).
     */
    public function submitRequest(StoreServiceRequestRequest $request, int|string $id): JsonResponse
    {
        $serviceType = $this->catalog->findByIdForMember($id, $request->user());

        if ($serviceType === null) {
            return response()->json(['message' => __('api.services.not_found')], Response::HTTP_NOT_FOUND);
        }

        if (! $serviceType->createsRequest()) {
            return response()->json(
                ['message' => __('api.services.not_form_based')],
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        $this->requestService->submit(
            type: $serviceType,
            member: $request->user(),
            fieldValues: $request->formFieldValues(),
            branchId: $request->input('branch_id'),
            spaceId: $request->input('space_id'),
            locationSource: $request->locationSource(),
        );

        $serviceName = $serviceType->translate('name', app()->getLocale());

        return response()->json([
            'message' => __('api.services.request_submitted', ['service' => $serviceName]),
        ], Response::HTTP_CREATED);
    }
}
