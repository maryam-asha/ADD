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
            'warehouse' => $presenter->warehouse($this->location_id),
            'type' => $this->type->value,
            'quantity' => $this->quantity,
            'unit_cost' => $this->unit_cost,
            'quantity_after' => $this->quantity_after,
            'average_cost_after' => $this->average_cost_after,
            'transfer_id' => $this->transfer_id,
            'operation_id' => $this->operation_id,
            // What was typed, when it was a pack of the main unit ("2 box");
            // for a count, the count. `quantity` is always in the main unit.
            'entered' => $this->entered_unit_id === null ? null : [
                'unit' => [
                    'id' => $this->enteredUnit->id,
                    'code' => $this->enteredUnit->code,
                    'name' => $this->enteredUnit->name,
                ],
                'quantity' => $this->entered_quantity,
            ],
            'reason' => $this->reason?->value,
            'source' => $this->source_type === null ? null : ['type' => $this->source_type, 'id' => $this->source_id],
            'note' => $this->note,
            'actor' => $presenter->actor($this->actor_id),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
