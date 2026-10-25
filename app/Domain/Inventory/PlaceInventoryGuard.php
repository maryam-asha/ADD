<?php

namespace App\Domain\Inventory;

use Add\Inventory\Assets\Enums\AssetStatus;
use Add\Inventory\Assets\Models\Asset;
use Add\Inventory\Stock\Models\StockBalance;
use Add\Inventory\Support\Place;
use App\Domain\Foundation\Models\Zone;
use App\Domain\Inventory\Adapters\AddLocationResolver;

/**
 * Whether deleting a place would strand inventory. The package stores a
 * place as a type and an id, never a foreign key, so nothing in the
 * database stops a space being deleted with a projector still in it — the
 * asset would point at a place that no longer exists. Deleting is refused
 * while any place the delete removes holds an asset not yet disposed of, or
 * stock on hand; disposed assets keep their history and do not count.
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

        if ($removed === []) {
            return false;
        }

        return Asset::query()->at($removed)->where('status', '!=', AssetStatus::Disposed)->exists()
            || StockBalance::query()->at($removed)->where('quantity', '>', 0)->exists();
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
