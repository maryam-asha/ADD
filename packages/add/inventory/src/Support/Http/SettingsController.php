<?php

namespace Add\Inventory\Support\Http;

use Add\Inventory\Assets\AssetPlaces;
use Add\Inventory\Assets\Enums\DepreciationMethod;
use Add\Inventory\Catalog\Enums\SkuMode;
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
            // auto | on_create | editable: whether an item's code is typed, and when.
            'item_code_mode' => SkuMode::current()->value,
            // The methods an asset may be depreciated by; the first is the default.
            'depreciation_methods' => DepreciationMethod::enabledValues(),
            // Whether an asset category may be marked intangible.
            'intangible_assets' => (bool) config('inventory.assets.intangible'),
            // Whether capital additions may be recorded on an asset.
            'capital_additions' => (bool) config('inventory.assets.capital_additions'),
        ]]);
    }
}
