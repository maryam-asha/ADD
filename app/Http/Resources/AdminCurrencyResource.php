<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AdminCurrencyResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'code' => $this->code,
            'name' => $this->name,
            'symbol' => $this->symbol,
            'decimal_places' => $this->decimal_places,
            'is_base' => (bool) $this->is_base,
            'is_default' => (bool) ($this->is_default ?? $this->is_base),
            'is_active' => (bool) $this->is_active,
            'order' => $this->order,
            'created_at' => $this->created_at,
        ];
    }
}
