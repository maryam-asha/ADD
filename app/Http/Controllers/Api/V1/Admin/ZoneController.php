<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Foundation\Models\Zone;
use App\Http\Requests\Admin\StoreZoneRequest;
use App\Http\Requests\Admin\UpdateZoneRequest;
use App\Http\Resources\ZoneResource;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ZoneController extends AdminResourceController
{
    protected function modelClass(): string
    {
        return Zone::class;
    }

    protected function resourceClass(): string
    {
        return ZoneResource::class;
    }

    protected function hasOrderColumn(): bool
    {
        return false;
    }

    protected function applyIndexFilters(Builder $query, Request $request): void
    {
        $query->when(
            $request->filled('floor_id'),
            fn (Builder $q) => $q->where('floor_id', $request->query('floor_id'))
        );
    }

    public function store(StoreZoneRequest $request): ZoneResource
    {
        $data = $request->validated();
        $data['sort_order'] ??= 0;

        return new ZoneResource(Zone::create($data));
    }

    public function update(UpdateZoneRequest $request, Zone $zone): JsonResponse
    {
        $zone->update($request->validated());

        return response()->json(['message' => __('api.admin.zone_updated')]);
    }
}
