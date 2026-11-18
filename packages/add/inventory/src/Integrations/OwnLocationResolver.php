<?php

namespace Add\Inventory\Integrations;

use Add\Inventory\Catalog\Models\Location;
use Add\Inventory\Contracts\LocationResolver;
use Add\Inventory\Support\Place;

/**
 * The package's own tree of locations (inv_locations), for a host that has
 * none of its own: a warehouse, its rooms, their shelves.
 */
class OwnLocationResolver implements LocationResolver
{
    public const TYPE = 'location';

    public function types(): array
    {
        return [self::TYPE];
    }

    public function exists(Place $place): bool
    {
        return $place->type === self::TYPE && Location::query()->whereKey($place->id)->exists();
    }

    public function describe(Place $place): ?array
    {
        $location = $place->type === self::TYPE ? Location::query()->find($place->id) : null;

        if ($location === null) {
            return null;
        }

        $path = [];

        for ($parent = $location->parent; $parent !== null; $parent = $parent->parent) {
            array_unshift($path, $parent->translate('name'));
        }

        return [...$place->toArray(), 'label' => $location->translate('name'), 'path' => $path];
    }

    public function within(Place $place): array
    {
        if (! $this->exists($place)) {
            return [];
        }

        $ids = [$place->id];

        for ($level = [$place->id]; $level !== [];) {
            $level = Location::query()->whereIn('parent_id', $level)->pluck('id')->all();
            $ids = [...$ids, ...$level];
        }

        return array_map(fn (int $id) => new Place(self::TYPE, $id), $ids);
    }
}
