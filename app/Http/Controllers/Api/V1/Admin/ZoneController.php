<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Foundation\Models\Zone;
use App\Domain\Inventory\Adapters\AddLocationResolver;
use App\Http\Controllers\Api\V1\Admin\Concerns\RefusesDeletingPlacesWithInventory;
use App\Http\Requests\Admin\StoreZoneRequest;
use App\Http\Requests\Admin\UpdateZoneRequest;
use App\Http\Resources\ZoneResource;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class ZoneController extends AdminResourceController
{
    use RefusesDeletingPlacesWithInventory;

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

    /**
     * Not while it, or a place deleted with it, still holds inventory.
     */
    public function destroy(int $id): Response
    {
        $this->refuseIfHoldsInventory(AddLocationResolver::ZONE, $id);

        return parent::destroy($id);
    }
}
