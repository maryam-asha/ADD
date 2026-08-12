<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Foundation\Models\Floor;
use App\Http\Requests\Admin\StoreFloorRequest;
use App\Http\Requests\Admin\UpdateFloorRequest;
use App\Http\Resources\FloorResource;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FloorController extends AdminResourceController
{
    protected function modelClass(): string
    {
        return Floor::class;
    }

    protected function resourceClass(): string
    {
        return FloorResource::class;
    }

    protected function hasOrderColumn(): bool
    {
        return false;
    }

    protected function applyIndexFilters(Builder $query, Request $request): void
    {
        $query->when(
            $request->filled('building_id'),
            fn (Builder $q) => $q->where('building_id', $request->query('building_id'))
        );
    }

    public function store(StoreFloorRequest $request): FloorResource
    {
        $data = $request->validated();
        $data['sort_order'] ??= 0;

        return new FloorResource(Floor::create($data));
    }

    public function update(UpdateFloorRequest $request, Floor $floor): JsonResponse
    {
        $floor->update($request->validated());

        return response()->json(['message' => __('api.admin.floor_updated')]);
    }
}
