<?php

namespace Add\Inventory\Warehouses\Services;

use Add\Inventory\Stock\Models\StockBalance;
use Add\Inventory\Stock\Models\StockMovement;
use Add\Inventory\Support\InventoryException;
use Add\Inventory\Support\Place;
use Add\Inventory\Warehouses\Enums\ItemScope;
use Add\Inventory\Warehouses\Models\Warehouse;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/**
 * The rules that keep warehouses consistent with the stock in them: one
 * primary per place, and nothing that holds stock is emptied of its right
 * to hold it — not deactivated, deleted, or narrowed past what it holds.
 */
class WarehouseService
{
    /**
     * @param  array<string, mixed>  $data  validated; `place` as {type, id}, `item_ids` for a selected scope
     */
    public function create(array $data): Warehouse
    {
        return DB::transaction(function () use ($data) {
            $warehouse = new Warehouse;
            $this->fill($warehouse, $data);
            $this->ensurePrimaryRules($warehouse);
            $warehouse->save();
            $this->syncItems($warehouse, $data);
            $this->clearOtherPrimaries($warehouse);

            return $warehouse;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Warehouse $warehouse, array $data): Warehouse
    {
        return DB::transaction(function () use ($warehouse, $data) {
            $warehouse = Warehouse::query()->lockForUpdate()->findOrFail($warehouse->id);
            $this->fill($warehouse, $data);

            if ($warehouse->isDirty('is_active') && ! $warehouse->is_active && $this->holdsStock($warehouse)) {
                InventoryException::fail('inventory::warehouses.holds_stock');
            }

            if ($warehouse->item_scope === ItemScope::Selected && ($warehouse->isDirty('item_scope') || array_key_exists('item_ids', $data))) {
                $this->ensureKeepsWhatItHolds($warehouse, array_map('intval', $data['item_ids'] ?? []));
            }

            $this->ensurePrimaryRules($warehouse);
            $warehouse->save();
            $this->syncItems($warehouse, $data);
            $this->clearOtherPrimaries($warehouse);

            return $warehouse;
        });
    }

    public function delete(Warehouse $warehouse): void
    {
        if (StockMovement::query()->where('location_type', Warehouse::TYPE)->where('location_id', $warehouse->id)->exists()) {
            InventoryException::fail('inventory::warehouses.has_history');
        }

        $warehouse->delete();
    }

    public function holdsStock(Warehouse $warehouse): bool
    {
        return StockBalance::query()
            ->where('location_type', Warehouse::TYPE)
            ->where('location_id', $warehouse->id)
            ->where('quantity', '>', 0)
            ->exists();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function fill(Warehouse $warehouse, array $data): void
    {
        if (array_key_exists('place', $data)) {
            $place = $data['place'] === null ? null : Place::from($data['place']);
            $warehouse->place_type = $place?->type;
            $warehouse->place_id = $place?->id;
        }

        $warehouse->fill(Arr::except($data, ['place', 'item_ids']));
    }

    private function ensurePrimaryRules(Warehouse $warehouse): void
    {
        if (! $warehouse->is_primary) {
            return;
        }

        if ($warehouse->place() === null) {
            InventoryException::fail('inventory::warehouses.primary_needs_place');
        }

        if (! $warehouse->allows_sales || ! $warehouse->is_active) {
            InventoryException::fail('inventory::warehouses.primary_needs_sales');
        }
    }

    /**
     * Narrowing the scope may not leave stock in a warehouse that no
     * longer allows it.
     *
     * @param  list<int>  $itemIds
     */
    private function ensureKeepsWhatItHolds(Warehouse $warehouse, array $itemIds): void
    {
        $stranded = StockBalance::query()
            ->where('location_type', Warehouse::TYPE)
            ->where('location_id', $warehouse->id)
            ->where('quantity', '>', 0)
            ->whereNotIn('item_id', $itemIds)
            ->exists();

        if ($stranded) {
            InventoryException::fail('inventory::warehouses.scope_strands_stock');
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function syncItems(Warehouse $warehouse, array $data): void
    {
        if ($warehouse->item_scope === ItemScope::All) {
            $warehouse->items()->detach();

            return;
        }

        if (array_key_exists('item_ids', $data)) {
            $warehouse->items()->sync(array_map('intval', $data['item_ids']));
        }
    }

    private function clearOtherPrimaries(Warehouse $warehouse): void
    {
        if (! $warehouse->is_primary) {
            return;
        }

        Warehouse::query()
            ->where('place_type', $warehouse->place_type)
            ->where('place_id', $warehouse->place_id)
            ->whereKeyNot($warehouse->id)
            ->where('is_primary', true)
            ->lockForUpdate()
            ->get()
            ->each(fn (Warehouse $other) => $other->update(['is_primary' => false]));
    }
}
