<?php

namespace Add\Inventory\Assets;

use Add\Inventory\Contracts\LocationResolver;
use Add\Inventory\Support\Place;
use Add\Inventory\Warehouses\Models\Warehouse;

/**
 * Where an asset may be: the host's places, and — when config
 * inventory.assets.store_in_warehouses is on — a warehouse, for an asset
 * bought and stored before it is handed out. Wraps the host's resolver;
 * a warehouse is always described, so an asset that stayed in one still
 * reads by its name after the option is turned off.
 */
class AssetPlaces implements LocationResolver
{
    public function __construct(private readonly LocationResolver $places) {}

    public static function warehousesAllowed(): bool
    {
        return (bool) config('inventory.assets.store_in_warehouses', false);
    }

    public function types(): array
    {
        return [...$this->places->types(), ...(self::warehousesAllowed() ? [Warehouse::TYPE] : [])];
    }

    public function exists(Place $place): bool
    {
        if ($place->type === Warehouse::TYPE) {
            return self::warehousesAllowed() && Warehouse::query()->active()->whereKey($place->id)->exists();
        }

        return $this->places->exists($place);
    }

    public function describe(Place $place): ?array
    {
        if ($place->type !== Warehouse::TYPE) {
            return $this->places->describe($place);
        }

        $warehouse = Warehouse::query()->find($place->id);

        if ($warehouse === null) {
            return null;
        }

        $sitsIn = $warehouse->place() === null ? null : $this->places->describe($warehouse->place());

        return [
            ...$place->toArray(),
            'label' => $warehouse->translate('name'),
            'path' => $sitsIn === null ? [] : [...$sitsIn['path'], $sitsIn['label']],
        ];
    }

    /**
     * A place and everything inside it — the warehouses sitting there
     * included, when assets may be kept in them.
     */
    public function within(Place $place): array
    {
        if ($place->type === Warehouse::TYPE) {
            return [$place];
        }

        $places = $this->places->within($place);

        if (! self::warehousesAllowed() || $places === []) {
            return $places;
        }

        return [
            ...$places,
            ...Warehouse::query()->within($place)->pluck('id')->map(fn (int $id) => new Place(Warehouse::TYPE, $id))->all(),
        ];
    }
}
