<?php

namespace Add\Inventory\Tests\Concerns;

use Add\Inventory\Catalog\Models\Item;
use Add\Inventory\Catalog\Models\Location;
use Add\Inventory\Integrations\OwnLocationResolver;
use Add\Inventory\Support\Place;
use Add\Inventory\Warehouses\Models\Warehouse;

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

    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function warehouse(array $attributes = []): Warehouse
    {
        return Warehouse::factory()->create($attributes);
    }

    /**
     * Where stock sits: a warehouse, as the ledger names it.
     */
    protected function in(Warehouse $warehouse): Place
    {
        return $warehouse->asPlace();
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
