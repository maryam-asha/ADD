<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Services\Models\ServiceType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Services\Admin\StoreServiceTypeRequest;
use App\Http\Requests\Services\Admin\UpdateServiceTypeRequest;
use App\Http\Resources\Services\ServiceTypeResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpFoundation\Response;

class AdminServiceTypeController extends Controller
{
    // ── Service Types ─────────────────────────────────────────────────────────

    /**
     * GET /api/v1/admin/service-types
     */
    public function index(): AnonymousResourceCollection
    {
        $types = ServiceType::with('axis')->ordered()->get();

        return ServiceTypeResource::collection($types);
    }

    /**
     * POST /api/v1/admin/service-types
     */
    public function store(StoreServiceTypeRequest $request): JsonResponse
    {
        $type = ServiceType::create($request->validated());

        return (new ServiceTypeResource($type->load('axis')))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    /**
     * GET /api/v1/admin/service-types/{serviceType}
     *
     * Carries the active request-form fields, as members see them; the
     * full editor list, inactive fields included, is
     * GET service-types/{serviceType}/fields (AdminFormFieldController).
     */
    public function show(ServiceType $serviceType): ServiceTypeResource
    {
        return new ServiceTypeResource($serviceType->load('axis', 'requestForm.activeFields'));
    }

    /**
     * PATCH /api/v1/admin/service-types/{serviceType}
     */
    public function update(UpdateServiceTypeRequest $request, ServiceType $serviceType): JsonResponse
    {
        $serviceType->update($request->validated());

        return response()->json(['message' => __('api.services.type_updated')]);
    }

    /**
     * DELETE /api/v1/admin/service-types/{serviceType}
     */
    public function destroy(ServiceType $serviceType): JsonResponse
    {
        $serviceType->delete();

        return response()->json(['message' => __('api.services.type_deleted')]);
    }
}
