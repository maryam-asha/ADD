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
            'rate_usd_to_syp' => $this->rate_usd_to_syp,
            'effective_from' => $this->effective_from,
            'set_by' => $this->set_by,
            'created_at' => $this->created_at,
        ];
    }
}
