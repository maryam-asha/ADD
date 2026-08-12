<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Concerns\LogsSensitiveActions;
use App\Domain\Foundation\Enums\OperationalStatus;
use App\Domain\Foundation\Models\Resource;
use App\Http\Requests\Admin\StoreResourceRequest;
use App\Http\Requests\Admin\UpdateResourceRequest;
use App\Http\Requests\Admin\UpdateResourceStatusRequest;
use App\Http\Resources\ResourceResource;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ResourceController extends AdminResourceController
{
    use LogsSensitiveActions;

    protected function modelClass(): string
    {
        return Resource::class;
    }

    protected function resourceClass(): string
    {
        return ResourceResource::class;
    }

    protected function hasOrderColumn(): bool
    {
        return false;
    }

    protected function applyIndexFilters(Builder $query, Request $request): void
    {
        $query->when(
            $request->filled('space_id'),
            fn (Builder $q) => $q->where('space_id', $request->query('space_id'))
        );
    }

    public function store(StoreResourceRequest $request): ResourceResource
    {
        return new ResourceResource(Resource::create(array_merge(
            ['quantity' => 1, 'status' => OperationalStatus::Active],
            $request->validated()
        )));
    }

    public function update(UpdateResourceRequest $request, Resource $resource): JsonResponse
    {
        $resource->update($request->validated());

        return response()->json(['message' => __('api.admin.resource_updated')]);
    }

    public function updateStatus(UpdateResourceStatusRequest $request, Resource $resource): JsonResponse
    {
        $before = $resource->status;

        $resource->update($request->validated());

        $this->logSensitiveAction('resource_status_changed', $resource, [
            'before' => $before,
            'after' => $resource->status,
        ]);

        return response()->json(['message' => __('api.admin.resource_status_updated')]);
    }
}
