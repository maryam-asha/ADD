<?php

namespace Add\Inventory\Support;

use Add\Inventory\Inventory;

/**
 * Names for what the package stores only as ids — a place, a custodian, the
 * user who acted — remembered for the request, so a list of fifty
 * movements asks the host about each place once. Bound scoped: never shared
 * across requests or queued jobs.
 */
class Presenter
{
    /** @var array<string, array<string, mixed>> */
    private array $locations = [];

    /** @var array<string, array<string, mixed>> */
    private array $custodians = [];

    /** @var array<int, array{id: int, name: ?string}> */
    private array $actors = [];

    /**
     * @return array{type: string, id: int, label: ?string, path: list<string>}|null
     */
    public function location(?Place $place): ?array
    {
        if ($place === null) {
            return null;
        }

        return $this->locations[$place->key()] ??= Inventory::locations()->describe($place)
            ?? [...$place->toArray(), 'label' => null, 'path' => []];
    }

    /**
     * @return array{type: string, id: int, label: ?string}|null
     */
    public function custodian(?Place $custodian): ?array
    {
        if ($custodian === null) {
            return null;
        }

        return $this->custodians[$custodian->key()] ??= Inventory::custodians()->describe($custodian)
            ?? [...$custodian->toArray(), 'label' => null];
    }

    /**
     * @return array{id: int, name: ?string}|null
     */
    public function actor(?int $id): ?array
    {
        if ($id === null) {
            return null;
        }

        if (! isset($this->actors[$id])) {
            $model = Inventory::userModel();
            $user = $model::query()->find($id);
            $this->actors[$id] = ['id' => $id, 'name' => $user?->getAttribute(config('inventory.user_name_attribute', 'name'))];
        }

        return $this->actors[$id];
    }
}
