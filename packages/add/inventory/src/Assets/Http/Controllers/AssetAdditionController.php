<?php

namespace Add\Inventory\Assets\Http\Controllers;

use Add\Inventory\Assets\Http\Requests\AssetAdditionRequest;
use Add\Inventory\Assets\Http\Resources\AssetAdditionResource;
use Add\Inventory\Assets\Models\Asset;
use Add\Inventory\Assets\Models\AssetAddition;
use Add\Inventory\Assets\Services\AssetService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controller;

/**
 * An asset's capital additions (config inventory.assets.capital_additions):
 * listed newest first, added, and removed when entered by mistake.
 */
class AssetAdditionController extends Controller
{
    public function index(Asset $asset): AnonymousResourceCollection
    {
        return AssetAdditionResource::collection($asset->additions()->orderByDesc('added_on')->orderByDesc('id')->get());
    }

    public function store(AssetAdditionRequest $request, Asset $asset, AssetService $service): JsonResponse
    {
        return (new AssetAdditionResource($service->addCapital($asset, $request->validated())))->response()->setStatusCode(201);
    }

    public function destroy(AssetAddition $addition, AssetService $service): JsonResponse
    {
        $service->removeCapital($addition);

        return response()->json(['message' => __('inventory::assets.addition_removed')]);
    }
}
