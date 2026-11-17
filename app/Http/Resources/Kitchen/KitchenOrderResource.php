<?php

namespace App\Http\Resources\Kitchen;

use App\Domain\Kitchen\Enums\DeliveryType;
use App\Domain\Kitchen\Models\KitchenOrder;
use App\Domain\Kitchen\Models\KitchenOrderLine;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One kitchen order as its member sees it: the `route` it was sent on, how it
 * is paid (`payment.refunded` once its payment was given back, `cash_return`
 * when cash is owed back), `payment_due_at` while a held card order waits,
 * and `bill` null when it has none. `bill.type` uses the coworking
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
        $this->loadMissing('lines.item', 'seatDesk', 'space', 'acceptedPayment');

        return [
            'id' => $this->id,
            'status' => $this->status->value,
            'source' => $this->source->value,
            'delivery' => [
                'type' => $this->delivery_type->value,
                'label' => $this->deliveryLabel(),
                'note' => $this->delivery_note,
            ],
            'route' => $this->route?->value,
            'bill' => $this->hasBill() ? ['type' => $this->billType(), 'id' => $this->billable_id] : null,
            'payment' => [
                'choice' => $this->payment_choice?->value,
                'paid' => $this->paid_at !== null,
                'refunded' => $this->acceptedPayment?->refunded_at !== null,
                'cash_return' => $this->cash_return?->value,
            ],
            'payment_due_at' => $this->payment_due_at?->toIso8601String(),
            // `quantity` is what is made; `ordered_quantity` what the member
            // ordered — more, or the item taken off (0), when the café
            // accepted the order with less of it. `id` is the line, as the
            // café's accept names it.
            'lines' => $this->lines->map(fn (KitchenOrderLine $line) => [
                'id' => $line->id,
                'sale_item_id' => $line->sale_item_id,
                'name' => $line->item?->translate('name'),
                'image_url' => $line->item?->image_url,
                'quantity' => $line->quantity,
                'ordered_quantity' => $line->orderedQuantity(),
                'unit_price_points' => $line->unit_price_points,
                'line_points' => $line->line_points,
            ])->values(),
            // How many items the order holds: every line's quantity.
            'items_count' => (int) $this->lines->sum('quantity'),
            'total_points' => $this->total_points,
            // Accepted with fewer items than were ordered: what was ordered
            // came to `ordered_total_points`; `total_points` is what is made.
            'adjusted' => $this->wasAdjusted(),
            'ordered_total_points' => $this->orderedTotalPoints(),
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
