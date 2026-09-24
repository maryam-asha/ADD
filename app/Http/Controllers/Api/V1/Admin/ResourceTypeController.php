<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Membership\Models\ResourceType;
use App\Http\Requests\Admin\StoreResourceTypeRequest;
use App\Http\Requests\Admin\UpdateResourceTypeRequest;
use App\Http\Resources\ResourceTypeResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Str;

class ResourceTypeController extends AdminResourceController
{
    protected function modelClass(): string
    {
        return ResourceType::class;
    }

    protected function resourceClass(): string
    {
        return ResourceTypeResource::class;
    }

    public function store(StoreResourceTypeRequest $request): ResourceTypeResource
    {
        $data = $request->validated();

        if (empty($data['slug'])) {
            $nameForSlug = $data['name']['en'] ?? ($data['name']['ar'] ?? 'resource-type');
            $baseSlug = Str::slug($nameForSlug);
            if (empty($baseSlug)) {
                $baseSlug = 'resource-type';
            }
            $slug = $baseSlug;
            $counter = 1;
            while (ResourceType::where('slug', $slug)->exists()) {
                $slug = $baseSlug.'-'.$counter++;
            }
            $data['slug'] = $slug;
        }

        $resourceType = ResourceType::create(array_merge(
            ['order' => 0, 'is_active' => true],
            $data
        ));

        return new ResourceTypeResource($resourceType);
    }

    public function update(UpdateResourceTypeRequest $request, ResourceType $resourceType): JsonResponse
    {
        $data = $request->validated();

        if (empty($data['slug'])) {
            unset($data['slug']);
        }

        $resourceType->update($data);

        return response()->json(['message' => __('api.admin.resource_type_updated')]);
    }

    /**
     * Overrides the generic destroy(): a resource type in use by at least
     * one PlanResourceGrant would otherwise bubble up as a raw 500 from the
     * grant's restrictOnDelete() FK constraint — check first and refuse
     * cleanly instead.
     */
    public function destroy(int $id): Response
    {
        $resourceType = ResourceType::findOrFail($id);

        if ($resourceType->planResourceGrants()->exists()) {
            abort(422, __('api.resource_type.in_use'));
        }

        $resourceType->delete();

        return response()->noContent();
    }
}
