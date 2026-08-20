<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ExchangeRateResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'currency_code' => $this->currency_code,
            'rate_to_base' => $this->rate_to_base,
            'effective_from' => $this->effective_from,
            'set_by' => $this->set_by,
            'created_at' => $this->created_at,
        ];
    }
}
