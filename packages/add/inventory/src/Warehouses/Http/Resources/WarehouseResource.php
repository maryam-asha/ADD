<?php

namespace Add\Inventory\Warehouses\Http\Resources;

use Add\Inventory\Catalog\Models\Item;
use Add\Inventory\Support\Presenter;
use Add\Inventory\Warehouses\Models\Warehouse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Warehouse
 */
class WarehouseResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $presenter = app(Presenter::class);

        return [
            'id' => $this->id,
            'name' => $this->name,
            'code' => $this->code,
            'manager' => $presenter->actor($this->manager_id),
            'address' => $this->address,
            'location_url' => $this->location_url,
            'notes' => $this->notes,
            'place' => $presenter->location($this->place()),
            'item_scope' => $this->item_scope->value,
            'items' => $this->whenLoaded('items', fn () => $this->items->map(fn (Item $item) => [
                'id' => $item->id,
                'sku' => $item->sku,
                'name' => $item->name,
            ])->values()),
            'allows_sales' => $this->allows_sales,
            'is_primary' => $this->is_primary,
            'is_active' => $this->is_active,
            // Stock items with something on hand here.
            'items_with_balance' => $this->whenCounted('balances'),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
