<?php

namespace Add\Inventory\Tests\Concerns;

use Add\Inventory\Catalog\Models\Item;
use Add\Inventory\Catalog\Models\Location;
use Add\Inventory\Integrations\OwnLocationResolver;
use Add\Inventory\Support\Place;

trait BuildsInventory
{
    protected function place(?Location $location = null): Place
    {
        return new Place(OwnLocationResolver::TYPE, ($location ?? Location::factory()->create())->id);
    }

    /**
     * @return array{type: string, id: int}
     */
    protected function at(Location $location): array
    {
        return ['type' => OwnLocationResolver::TYPE, 'id' => $location->id];
    }

    protected function stockItem(bool $fractional = false): Item
    {
        $factory = Item::factory()->stock();

        return ($fractional ? $factory->fractional() : $factory)->create();
    }

    protected function assetItem(): Item
    {
        return Item::factory()->create();
    }
}
