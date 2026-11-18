<?php

namespace App\Http\Resources\Kitchen;

use App\Domain\Kitchen\Models\KitchenOrder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One row of reception's cash-to-return list: the order (which carries why
 * it was not made — its rejection reason, or who cancelled it and when)
 * beside what was taken for it — its `points` (what the payment is worth, which crediting gives back) and the cash `received_amount`
 * in `received_currency`.
 *
 * @mixin KitchenOrder
 */
class KitchenCashReturnResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $this->loadMissing('acceptedPayment');
        $payment = $this->acceptedPayment;

        return [
            'order' => new AdminKitchenOrderResource($this->resource),
            'points' => $payment?->amount_points ?? $this->total_points,
            'received_amount' => $payment?->received_amount,
            'received_currency' => $payment?->received_currency,
        ];
    }
}
