<?php

namespace Add\Inventory\Contracts;

use Add\Inventory\Support\Place;

/**
 * Where things are kept. The package's default is its own tree of
 * locations (inv_locations); a host with places of its own (ADD's branches,
 * buildings, floors, zones and spaces) binds a resolver over them, so an
 * asset sits in a real room and stock is counted on a real shelf.
 */
interface LocationResolver
{
    /**
     * The location types a request may name.
     *
     * @return list<string>
     */
    public function types(): array;

    public function exists(Place $place): bool;

    /**
     * For display: its name and the names of the places it sits in,
     * outermost first. Null when it no longer exists.
     *
     * @return array{type: string, id: int, label: ?string, path: list<string>}|null
     */
    public function describe(Place $place): ?array;

    /**
     * This place and every place inside it, at any depth — what "stock in
     * this branch" adds up.
     *
     * @return list<Place>
     */
    public function within(Place $place): array;
}
