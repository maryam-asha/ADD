<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Foundation\Models\Building;
use App\Domain\Inventory\Adapters\AddLocationResolver;
use App\Http\Controllers\Api\V1\Admin\Concerns\RefusesDeletingPlacesWithInventory;
use App\Http\Requests\Admin\StoreBuildingRequest;
use App\Http\Requests\Admin\UpdateBuildingRequest;
use App\Http\Resources\BuildingResource;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class BuildingController extends AdminResourceController
{
    use RefusesDeletingPlacesWithInventory;

    protected function modelClass(): string
    {
        return Building::class;
    }

    protected function resourceClass(): string
    {
        return BuildingResource::class;
    }

    protected function hasOrderColumn(): bool
    {
        return false;
    }

    protected function applyIndexFilters(Builder $query, Request $request): void
    {
        $query->when(
            $request->filled('branch_id'),
            fn (Builder $q) => $q->where('branch_id', $request->query('branch_id'))
        );
    }

    public function store(StoreBuildingRequest $request): BuildingResource
    {
        return new BuildingResource(Building::create($request->validated()));
    }

    public function update(UpdateBuildingRequest $request, Building $building): JsonResponse
    {
        $building->update($request->validated());

        return response()->json(['message' => __('api.admin.building_updated')]);
    }

    /**
     * Not while it, or a place deleted with it, still holds inventory.
     */
    public function destroy(int $id): Response
    {
        $this->refuseIfHoldsInventory(AddLocationResolver::BUILDING, $id);

        return parent::destroy($id);
    }
}
