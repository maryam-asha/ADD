<?php

namespace Add\Inventory\Support\Http;

use Add\Inventory\Assets\AssetPlaces;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

/**
 * The package's options a host's screens follow, so turning one on in the
 * config needs no change on the screens.
 */
class SettingsController extends Controller
{
    public function __invoke(): JsonResponse
    {
        return response()->json(['data' => [
            'assets_in_warehouses' => AssetPlaces::warehousesAllowed(),
        ]]);
    }
}
