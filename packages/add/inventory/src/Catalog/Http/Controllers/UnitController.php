<?php

namespace Add\Inventory\Catalog\Http\Controllers;

use Add\Inventory\Catalog\Http\Requests\UnitRequest;
use Add\Inventory\Catalog\Http\Resources\UnitResource;
use Add\Inventory\Catalog\Models\Unit;
use Add\Inventory\Support\InventoryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;

class UnitController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return UnitResource::collection(Unit::query()->orderBy('code')->get());
    }

    public function store(UnitRequest $request): UnitResource
    {
        return new UnitResource(Unit::query()->create($request->validated()));
    }

    public function show(Unit $unit): UnitResource
    {
        return new UnitResource($unit);
    }

    public function update(UnitRequest $request, Unit $unit): JsonResponse
    {
        $unit->update($request->validated());

        return response()->json(['message' => __('inventory::catalog.unit_updated')]);
    }

    public function destroy(Unit $unit): Response
    {
        if ($unit->items()->exists()) {
            InventoryException::fail('inventory::catalog.unit_in_use');
        }

        $unit->delete();

        return response()->noContent();
    }
}
