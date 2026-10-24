<?php

namespace Add\Inventory\Catalog\Http\Controllers;

use Add\Inventory\Catalog\Http\Requests\LocationRequest;
use Add\Inventory\Catalog\Http\Resources\LocationResource;
use Add\Inventory\Catalog\Models\Location;
use Add\Inventory\Catalog\Services\CatalogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;

/**
 * The package's own places. Mounted only while the host binds no
 * LocationResolver of its own.
 */
class LocationController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return LocationResource::collection(Location::query()->orderBy('parent_id')->orderBy('id')->get());
    }

    public function store(LocationRequest $request): LocationResource
    {
        return new LocationResource(Location::query()->create($request->validated()));
    }

    public function show(Location $location): LocationResource
    {
        return new LocationResource($location);
    }

    public function update(LocationRequest $request, Location $location): JsonResponse
    {
        $location->update($request->validated());

        return response()->json(['message' => __('inventory::catalog.location_updated')]);
    }

    public function destroy(Location $location, CatalogService $catalog): Response
    {
        $catalog->deleteLocation($location);

        return response()->noContent();
    }
}
