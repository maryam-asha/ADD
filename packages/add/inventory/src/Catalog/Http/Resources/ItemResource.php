<?php

namespace Add\Inventory\Catalog\Http\Resources;

use Add\Inventory\Catalog\Models\Item;
use Add\Inventory\Support\Decimal;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Item
 */
class ItemResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'sku' => $this->sku,
            'name' => $this->name,
            'description' => $this->description,
            'kind' => $this->kind()->value,
            'category' => [
                'id' => $this->category->id,
                'name' => $this->category->name,
            ],
            'unit' => new UnitResource($this->unit),
            'properties' => (object) ($this->properties ?? []),
            'is_active' => $this->is_active,
            // Stock: everything on hand, every location together.
            'on_hand' => $this->when(
                array_key_exists('stock_balances_sum_quantity', $this->resource->getAttributes()),
                fn () => Decimal::round($this->stock_balances_sum_quantity ?? 0, 3),
            ),
            'assets_count' => $this->whenCounted('assets'),
        ];
    }
}
