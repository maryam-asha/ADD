<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InvoiceResource extends JsonResource
{
    /** The payment resource class this view renders payments with. */
    protected string $paymentResource = InvoicePaymentResource::class;

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'reference' => $this->reference,
            'status' => $this->status,
            'is_overdue' => $this->isOverdue(),
            'billed_to' => $this->billed_to,
            'provider' => $this->whenLoaded('provider', fn () => $this->provider === null ? null : ['id' => $this->provider->id, 'name' => $this->provider->name]),
            'company' => $this->whenLoaded('company', fn () => ['id' => $this->company->id, 'legal_name' => $this->company->legal_name]),
            'provider_request_id' => $this->provider_request_id,
            'office_lease_period_id' => $this->office_lease_period_id,
            'description' => $this->description,
            'points' => $this->points,
            'currency' => $this->currency,
            'amount' => (string) $this->amount,
            'paid_amount' => (string) $this->paid_amount,
            'remaining' => $this->remaining(),
            'issued_at' => $this->issued_at?->toDateString(),
            'due_at' => $this->due_at?->toDateString(),
            'cancelled_at' => $this->cancelled_at?->toIso8601String(),
            'payments' => $this->whenLoaded('payments', fn () => $this->paymentResource::collection($this->payments)),
            ...$this->staffFields(),
        ];
    }

    /** ADD-internal fields — the provider view leaves these out. */
    protected function staffFields(): array
    {
        return [
            'computed_amount' => (string) $this->computed_amount,
            'exchange_rate_to_base' => (string) $this->exchange_rate_to_base,
            'issued_by' => $this->whenLoaded('issuer', fn () => $this->issuer === null ? null : ['id' => $this->issuer->id, 'name' => $this->issuer->name]),
            'cancelled_by' => $this->cancelled_by,
            'cancellation_reason' => $this->cancellation_reason,
        ];
    }
}
