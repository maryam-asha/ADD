<?php

namespace Add\Inventory\Stock\Http\Resources;

use Add\Inventory\Stock\Models\StockMovement;
use Add\Inventory\Support\Presenter;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin StockMovement
 */
class StockMovementResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $presenter = app(Presenter::class);

        return [
            'id' => $this->id,
            'item' => [
                'id' => $this->item->id,
                'sku' => $this->item->sku,
                'name' => $this->item->name,
            ],
            'location' => $presenter->location($this->location()),
            'type' => $this->type->value,
            'quantity' => $this->quantity,
            'unit_cost' => $this->unit_cost,
            'quantity_after' => $this->quantity_after,
            'average_cost_after' => $this->average_cost_after,
            'transfer_id' => $this->transfer_id,
            'reason' => $this->reason?->value,
            'source' => $this->source_type === null ? null : ['type' => $this->source_type, 'id' => $this->source_id],
            'note' => $this->note,
            'actor' => $presenter->actor($this->actor_id),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
