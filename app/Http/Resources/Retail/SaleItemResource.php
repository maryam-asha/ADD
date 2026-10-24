<?php

namespace App\Http\Resources\Retail;

use App\Domain\Retail\Models\SaleItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin SaleItem
 */
class SaleItemResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'item' => [
                'id' => $this->item->id,
                'sku' => $this->item->sku,
                'name' => $this->item->name,
                'unit' => $this->item->unit?->name,
            ],
            'price_points' => $this->price_points,
            'is_active' => $this->is_active,
        ];
    }
}
