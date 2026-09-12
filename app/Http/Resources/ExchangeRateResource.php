<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ExchangeRateResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $numericRate = (float) $this->rate_to_base;
        $marketRate = $numericRate > 0 && $numericRate < 1
            ? round(1 / $numericRate, 2)
            : $numericRate;

        return [
            'id' => $this->id,
            'currency_code' => $this->currency_code,
            'rate_to_base' => $this->rate_to_base,
            'market_rate' => $marketRate,
            'display_rate' => $numericRate > 0 && $numericRate < 1
                ? '1 USD = ' . number_format($marketRate) . ' ' . $this->currency_code
                : '1 ' . $this->currency_code . ' = ' . $numericRate . ' USD',
            'source' => $this->source instanceof \BackedEnum ? $this->source->value : (string) $this->source,
            'effective_from' => $this->effective_from,
            'set_by' => $this->set_by,
            'created_at' => $this->created_at,
        ];
    }
}
