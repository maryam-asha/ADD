<?php

namespace App\Http\Controllers\Api\V1\Admin\Concerns;

use Add\Inventory\Support\Place;
use App\Domain\Inventory\Adapters\AddLocationResolver;
use App\Domain\Inventory\PlaceInventoryGuard;

/**
 * For the spatial controllers' destroy(): a place that still holds an
 * asset not disposed of — itself or a place the delete takes with it — is
 * not deleted; its assets are moved or disposed of first. A branch that
 * still has warehouses is not deleted either (PlaceInventoryGuard).
 */
trait RefusesDeletingPlacesWithInventory
{
    protected function refuseIfHoldsInventory(string $type, int $id): void
    {
        $guard = app(PlaceInventoryGuard::class);
        $place = new Place($type, $id);

        abort_if($type === AddLocationResolver::BRANCH && $guard->hasWarehouses($place), 422, __('api.admin.branch_has_warehouses'));
        abort_if($guard->holdsInventory($place), 422, __('api.admin.place_holds_inventory'));
    }
}
