<?php

namespace Add\Inventory\Warehouses\Http\Requests;

use Add\Inventory\Catalog\Enums\ItemKind;
use Add\Inventory\Catalog\Models\Item;
use Add\Inventory\Inventory;
use Add\Inventory\Support\Place;
use Add\Inventory\Support\TranslatableField;
use Add\Inventory\Warehouses\Enums\ItemScope;
use Add\Inventory\Warehouses\Models\Warehouse;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A warehouse's details. Its place is one of the types the host allows for
 * warehouses (config inventory.warehouses.place_types; every type its
 * resolver knows when unset); the items of a selected scope are stock
 * items. The rules that depend on stock are the service's.
 */
class WarehouseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        /** @var Warehouse|null $warehouse */
        $warehouse = $this->route('warehouse');
        $creating = $warehouse === null;
        $sometimes = $creating ? [] : ['sometimes'];
        $userTable = (new (Inventory::userModel()))->getTable();
        $scope = $this->input('item_scope', $warehouse?->item_scope?->value ?? ItemScope::All->value);

        $placeExists = function (string $attribute, mixed $value, Closure $fail) {
            if (is_array($value) && is_string($value['type'] ?? null) && is_numeric($value['id'] ?? null)
                && in_array($value['type'], $this->placeTypes(), true)
                && ! Inventory::locations()->exists(Place::from($value))) {
                $fail(__('inventory::catalog.unknown_location'));
            }
        };

        $stockItem = function (string $attribute, mixed $value, Closure $fail) {
            $item = is_numeric($value) ? Item::query()->with('category')->find($value) : null;

            if ($item !== null && $item->kind() !== ItemKind::Stock) {
                $fail(__('inventory::warehouses.not_stock_item'));
            }
        };

        return [
            ...($creating ? TranslatableField::rules('name') : TranslatableField::sometimes('name')),
            'code' => [...$sometimes, 'nullable', 'string', 'max:40', Rule::unique('inv_warehouses', 'code')->ignore($warehouse)],
            'manager_id' => [...$sometimes, 'nullable', 'integer', Rule::exists($userTable, 'id')],
            'address' => [...$sometimes, 'nullable', 'string', 'max:2000'],
            'location_url' => [...$sometimes, 'nullable', 'string', 'max:2048'],
            'notes' => [...$sometimes, 'nullable', 'string', 'max:2000'],
            'place' => [...$sometimes, 'nullable', 'array', $placeExists],
            'place.type' => ['required_with:place', 'string', Rule::in($this->placeTypes())],
            'place.id' => ['required_with:place', 'integer', 'min:1'],
            'item_scope' => [...$sometimes, Rule::enum(ItemScope::class)],
            'item_ids' => [
                ...($scope === ItemScope::Selected->value && ($creating || $this->has('item_scope')) ? ['required'] : ['sometimes']),
                'array',
                ...($scope === ItemScope::Selected->value ? ['min:1'] : []),
                'max:1000',
            ],
            'item_ids.*' => ['integer', 'distinct', Rule::exists('inv_items', 'id'), $stockItem],
            'allows_sales' => ['sometimes', 'boolean'],
            'is_primary' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return list<string>
     */
    private function placeTypes(): array
    {
        return config('inventory.warehouses.place_types') ?? Inventory::locations()->types();
    }
}
