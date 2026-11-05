<?php

namespace Add\Inventory\Stock\Http\Resources;

use Add\Inventory\Stock\Models\StockBalance;
use Add\Inventory\Support\Decimal;
use Add\Inventory\Support\Presenter;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin StockBalance
 */
class StockBalanceResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'item' => [
                'id' => $this->item->id,
                'sku' => $this->item->sku,
                'name' => $this->item->name,
                'unit' => ['id' => $this->item->unit->id, 'code' => $this->item->unit->code, 'name' => $this->item->unit->name],
            ],
            'warehouse' => app(Presenter::class)->warehouse($this->location_id),
            'quantity' => $this->quantity,
            'average_cost' => $this->average_cost,
            'value' => Decimal::round(Decimal::mul($this->quantity, $this->average_cost), 2),
            'currency' => config('inventory.stock.currency'),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
