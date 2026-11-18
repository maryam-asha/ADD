<?php

namespace Add\Inventory\Stock\Http\Resources;

use Add\Inventory\Stock\Models\StockOperation;
use Add\Inventory\Support\Presenter;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin StockOperation
 */
class StockOperationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $presenter = app(Presenter::class);

        return [
            'id' => $this->id,
            'kind' => $this->kind->value,
            'warehouse' => $presenter->warehouse($this->warehouse_id),
            'to_warehouse' => $presenter->warehouse($this->to_warehouse_id),
            'operation_date' => $this->operation_date?->toDateString(),
            'reference' => $this->reference,
            'reason' => $this->reason?->value,
            'note' => $this->note,
            'cost_method' => $this->cost_method,
            'attachment_url' => $this->attachment_url,
            'actor' => $presenter->actor($this->actor_id),
            // Item lines (a transfer's two halves count once).
            'lines_count' => $this->when(isset($this->lines_count), fn () => (int) $this->lines_count),
            'movements' => StockMovementResource::collection($this->whenLoaded('movements')),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
