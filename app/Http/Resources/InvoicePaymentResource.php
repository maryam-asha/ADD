<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InvoicePaymentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'amount' => (string) $this->amount,
            'received_amount' => (string) $this->received_amount,
            'received_currency' => $this->received_currency,
            'method' => $this->method,
            'receipt_number' => $this->receipt_number,
            'paid_at' => $this->paid_at?->toDateString(),
            'voided' => $this->voided_at !== null,
            'voided_at' => $this->voided_at?->toIso8601String(),
            ...$this->staffFields(),
        ];
    }

    /** Who at ADD recorded and voided it — the provider view leaves these out. */
    protected function staffFields(): array
    {
        return [
            'recorded_by' => $this->whenLoaded('recorder', fn () => $this->recorder === null ? null : ['id' => $this->recorder->id, 'name' => $this->recorder->name]),
            'exchange_rate' => (string) $this->exchange_rate,
            'voided_by' => $this->whenLoaded('voider', fn () => $this->voider === null ? null : ['id' => $this->voider->id, 'name' => $this->voider->name]),
            'void_reason' => $this->void_reason,
        ];
    }
}
