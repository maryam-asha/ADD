<?php

namespace Add\Inventory\Catalog\Services;

use Add\Inventory\Assets\Models\Asset;
use Add\Inventory\Catalog\Enums\ItemKind;
use Add\Inventory\Catalog\Models\AttributeDefinition;
use Add\Inventory\Catalog\Models\Category;
use Add\Inventory\Catalog\Models\Item;
use Add\Inventory\Catalog\Models\Location;
use Add\Inventory\Integrations\OwnLocationResolver;
use Add\Inventory\Stock\Models\StockMovement;
use Add\Inventory\Support\InventoryException;
use Illuminate\Support\Facades\DB;

/**
 * The rules that keep the catalog consistent: a branch of the tree shares
 * one kind, a field key means one thing along a branch, and nothing that
 * has history (an asset registered, a stock movement) loses what it was.
 */
class CatalogService
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function createCategory(array $data): Category
    {
        if (isset($data['parent_id'])) {
            $parent = Category::query()->findOrFail($data['parent_id']);

            if (isset($data['kind']) && $data['kind'] !== $parent->kind->value) {
                InventoryException::fail('inventory::catalog.kind_follows_parent');
            }

            $data['kind'] = $parent->kind;
        }

        return Category::query()->create($data);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateCategory(Category $category, array $data): Category
    {
        return DB::transaction(function () use ($category, $data) {
            $parentId = array_key_exists('parent_id', $data) && $data['parent_id'] !== null ? (int) $data['parent_id'] : null;

            if (array_key_exists('parent_id', $data) && $parentId !== $category->parent_id) {
                $this->moveCategory($category, $parentId);
            }

            unset($data['parent_id']);

            if (isset($data['kind']) && $data['kind'] !== $category->kind->value) {
                $this->changeKind($category, ItemKind::from($data['kind']));
            }

            unset($data['kind']);
            $category->update($data);

            return $category;
        });
    }

    public function deleteCategory(Category $category): void
    {
        if ($category->children()->exists() || $category->items()->exists()) {
            InventoryException::fail('inventory::catalog.category_not_empty');
        }

        $category->delete();
    }

    /**
     * A key may not repeat along the branch: not in its ancestors (the
     * items here already answer it), not below it (theirs would answer it
     * twice).
     *
     * @param  array<string, mixed>  $data
     */
    public function defineAttribute(Category $category, array $data): AttributeDefinition
    {
        $branch = [...$category->ancestors()->pluck('id')->all(), ...$category->subtreeIds()];

        if (AttributeDefinition::query()->whereIn('category_id', $branch)->where('key', $data['key'])->exists()) {
            InventoryException::fail('inventory::catalog.key_taken', ['key' => $data['key']]);
        }

        return $category->attributeDefinitions()->create($data);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function createItem(array $data): Item
    {
        return DB::transaction(function () use ($data) {
            $item = Item::query()->create($data);

            if ($item->sku === null) {
                $item->update(['sku' => config('inventory.items.sku_prefix').str_pad((string) $item->id, (int) config('inventory.items.sku_pad'), '0', STR_PAD_LEFT)]);
            }

            return $item;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateItem(Item $item, array $data): Item
    {
        if (isset($data['category_id']) && (int) $data['category_id'] !== $item->category_id) {
            $target = Category::query()->findOrFail($data['category_id']);

            if ($target->kind !== $item->kind() && $item->hasHistory()) {
                InventoryException::fail('inventory::catalog.item_kind_locked');
            }
        }

        if (isset($data['unit_id']) && (int) $data['unit_id'] !== $item->unit_id && $item->stockMovements()->exists()) {
            InventoryException::fail('inventory::catalog.unit_locked');
        }

        $item->update($data);

        return $item;
    }

    public function deleteItem(Item $item): void
    {
        if ($item->hasHistory()) {
            InventoryException::fail('inventory::catalog.item_has_history');
        }

        $item->delete();
    }

    public function deleteLocation(Location $location): void
    {
        $type = OwnLocationResolver::TYPE;
        $used = Asset::query()->where('location_type', $type)->where('location_id', $location->id)->exists()
            || StockMovement::query()->where('location_type', $type)->where('location_id', $location->id)->exists();

        if ($location->children()->exists() || $used) {
            InventoryException::fail('inventory::catalog.location_in_use');
        }

        $location->delete();
    }

    private function moveCategory(Category $category, ?int $parentId): void
    {
        if ($parentId === null) {
            $category->update(['parent_id' => null]);

            return;
        }

        if (in_array($parentId, $category->subtreeIds(), true)) {
            InventoryException::fail('inventory::catalog.parent_in_subtree');
        }

        $parent = Category::query()->findOrFail($parentId);

        if ($parent->kind !== $category->kind) {
            InventoryException::fail('inventory::catalog.kind_follows_parent');
        }

        $category->update(['parent_id' => $parentId]);
    }

    /**
     * Only a root decides its branch's kind, and only while nothing in the
     * branch holds an item.
     */
    private function changeKind(Category $category, ItemKind $kind): void
    {
        if ($category->parent_id !== null) {
            InventoryException::fail('inventory::catalog.kind_follows_parent');
        }

        $branch = $category->subtreeIds();

        if (Item::query()->whereIn('category_id', $branch)->exists()) {
            InventoryException::fail('inventory::catalog.kind_has_items');
        }

        Category::query()->whereIn('id', $branch)->update(['kind' => $kind]);
        $category->refresh();
    }
}
