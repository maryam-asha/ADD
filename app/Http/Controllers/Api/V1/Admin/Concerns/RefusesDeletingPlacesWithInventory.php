<?php

namespace App\Http\Controllers\Api\V1\Admin\Concerns;

use Add\Inventory\Support\Place;
use App\Domain\Inventory\PlaceInventoryGuard;

/**
 * For the spatial controllers' destroy(): a place that still holds
 * inventory (an asset not disposed of, or stock on hand) — itself or a place
 * the delete takes with it — is not deleted; its assets are moved or
 * disposed of and its stock transferred first (PlaceInventoryGuard).
 */
trait RefusesDeletingPlacesWithInventory
{
    protected function refuseIfHoldsInventory(string $type, int $id): void
    {
        abort_if(app(PlaceInventoryGuard::class)->holdsInventory(new Place($type, $id)), 422, __('api.admin.place_holds_inventory'));
    }
}
