<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Services\Models\ServiceType;
use App\Domain\Services\Models\ServiceTypeField;
use App\Http\Controllers\Controller;
use App\Http\Requests\Services\Admin\StoreServiceTypeFieldRequest;
use App\Http\Requests\Services\Admin\StoreServiceTypeRequest;
use App\Http\Requests\Services\Admin\UpdateServiceTypeFieldRequest;
use App\Http\Requests\Services\Admin\UpdateServiceTypeRequest;
use App\Http\Resources\Services\ServiceTypeFieldResource;
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
     */
    public function show(ServiceType $serviceType): ServiceTypeResource
    {
        return new ServiceTypeResource($serviceType->load('axis', 'fields'));
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

    // ── Fields ────────────────────────────────────────────────────────────────

    /**
     * GET /api/v1/admin/service-types/{serviceType}/fields
     */
    public function indexFields(ServiceType $serviceType): AnonymousResourceCollection
    {
        return ServiceTypeFieldResource::collection($serviceType->fields);
    }

    /**
     * POST /api/v1/admin/service-types/{serviceType}/fields
     */
    public function storeField(StoreServiceTypeFieldRequest $request, ServiceType $serviceType): JsonResponse
    {
        $field = $serviceType->fields()->create($request->validated());

        return (new ServiceTypeFieldResource($field))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    /**
     * PATCH /api/v1/admin/service-types/{serviceType}/fields/{field}
     */
    public function updateField(
        UpdateServiceTypeFieldRequest $request,
        ServiceType $serviceType,
        ServiceTypeField $field,
    ): JsonResponse {
        abort_if($field->service_type_id !== $serviceType->id, Response::HTTP_NOT_FOUND);

        $field->update($request->validated());

        return response()->json(['message' => __('api.services.field_updated')]);
    }

    /**
     * DELETE /api/v1/admin/service-types/{serviceType}/fields/{field}
     */
    public function destroyField(ServiceType $serviceType, ServiceTypeField $field): JsonResponse
    {
        abort_if($field->service_type_id !== $serviceType->id, Response::HTTP_NOT_FOUND);

        $field->delete();

        return response()->json(['message' => __('api.services.field_deleted')]);
    }
}
