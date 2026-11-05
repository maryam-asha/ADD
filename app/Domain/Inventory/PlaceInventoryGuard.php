<?php

namespace App\Domain\Inventory;

use Add\Inventory\Assets\Enums\AssetStatus;
use Add\Inventory\Assets\Models\Asset;
use Add\Inventory\Support\Place;
use Add\Inventory\Warehouses\Models\Warehouse;
use App\Domain\Foundation\Models\Zone;
use App\Domain\Inventory\Adapters\AddLocationResolver;

/**
 * Whether deleting a place would strand inventory. The package stores a
 * place as a type and an id, never a foreign key, so nothing in the
 * database stops a space being deleted with a projector still in it — the
 * asset would point at a place that no longer exists. Deleting is refused
 * while any place the delete removes holds an asset not yet disposed of;
 * disposed assets keep their history and do not count.
 *
 * Stock is not on places: it sits in warehouses, and a warehouse sits in a
 * branch. A branch with any warehouse is not deleted, so no warehouse ever
 * points at a branch that is gone or stays its primary.
 *
 * What a delete removes follows the foreign keys: a branch or a building
 * takes everything inside it, spaces included; a floor takes its zones and
 * a zone only itself — their spaces stay, unzoned (`zone_id` is
 * null-on-delete), so the inventory in those spaces is not at risk.
 */
class PlaceInventoryGuard
{
    public function __construct(private readonly AddLocationResolver $locations) {}

    public function holdsInventory(Place $place): bool
    {
        $removed = $this->removedWith($place);

        return $removed !== []
            && Asset::query()->at($removed)->where('status', '!=', AssetStatus::Disposed)->exists();
    }

    public function hasWarehouses(Place $branch): bool
    {
        return Warehouse::query()->where('place_type', $branch->type)->where('place_id', $branch->id)->exists();
    }

    /**
     * @return list<Place>
     */
    private function removedWith(Place $place): array
    {
        return match ($place->type) {
            AddLocationResolver::FLOOR => [
                $place,
                ...Zone::query()->where('floor_id', $place->id)->pluck('id')->map(fn (int $id) => new Place(AddLocationResolver::ZONE, $id))->all(),
            ],
            AddLocationResolver::ZONE => [$place],
            default => $this->locations->within($place),
        };
    }
}
