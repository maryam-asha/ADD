<?php

namespace App\Http\Resources\Kitchen;

use App\Domain\Kitchen\Enums\DeliveryType;
use App\Domain\Kitchen\Models\KitchenOrder;
use App\Domain\Kitchen\Models\KitchenOrderLine;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One kitchen order as its member sees it. `bill.type` uses the coworking
 * names ('booking' | 'walkin'), as `coworking-sessions/{type}/{id}` does.
 *
 * @mixin KitchenOrder
 */
class KitchenOrderResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $this->loadMissing('lines.item', 'seatDesk', 'space');

        return [
            'id' => $this->id,
            'status' => $this->status->value,
            'source' => $this->source->value,
            'delivery' => [
                'type' => $this->delivery_type->value,
                'label' => $this->deliveryLabel(),
                'note' => $this->delivery_note,
            ],
            'bill' => ['type' => $this->billType(), 'id' => $this->billable_id],
            'lines' => $this->lines->map(fn (KitchenOrderLine $line) => [
                'sale_item_id' => $line->sale_item_id,
                'name' => $line->item?->translate('name'),
                'quantity' => $line->quantity,
                'unit_price_points' => $line->unit_price_points,
                'line_points' => $line->line_points,
            ])->values(),
            'total_points' => $this->total_points,
            'rejection_reason' => $this->rejection_reason,
            'cancelled_by' => $this->cancelled_by?->value,
            'timestamps' => [
                'submitted_at' => $this->created_at?->toIso8601String(),
                'accepted_at' => $this->accepted_at?->toIso8601String(),
                'ready_at' => $this->ready_at?->toIso8601String(),
                'delivered_at' => $this->delivered_at?->toIso8601String(),
            ],
            'paid' => $this->paid_at !== null,
        ];
    }

    /** The desk's label, the space's title, the kitchen counter, or the member's note. */
    private function deliveryLabel(): ?string
    {
        return match ($this->delivery_type) {
            DeliveryType::Desk => $this->seatDesk?->label,
            DeliveryType::Space => $this->space?->displayTitle(),
            DeliveryType::Pickup => __('api.kitchen.pickup_label'),
            DeliveryType::Note => $this->delivery_note,
        };
    }
}
