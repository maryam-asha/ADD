<?php

namespace App\Http\Resources\Retail;

use Add\Inventory\Support\Presenter;
use App\Domain\Retail\Models\ItemSale;
use App\Http\Resources\Finance\PaymentResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ItemSale
 */
class ItemSaleResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'member' => ['id' => $this->user->id, 'name' => $this->user->name],
            // The warehouse the stock was issued from.
            'warehouse' => app(Presenter::class)->warehouse($this->location_id),
            'lines' => $this->lines->map(fn ($line) => [
                'item' => ['id' => $line->item->id, 'sku' => $line->item->sku, 'name' => $line->item->name],
                'quantity' => $line->quantity,
                'unit_price_points' => $line->unit_price_points,
                'line_points' => $line->line_points,
            ])->values(),
            'total_points' => $this->total_points,
            'sold_by' => $this->seller === null ? null : ['id' => $this->seller->id, 'name' => $this->seller->name],
            'paid_at' => $this->paid_at?->toIso8601String(),
            'payment' => $this->whenLoaded('payment', fn () => $this->payment === null ? null : new PaymentResource($this->payment)),
        ];
    }
}
