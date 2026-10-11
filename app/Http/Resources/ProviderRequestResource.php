<?php

namespace App\Http\Resources;

use App\Support\PlainAmount;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A provider request. `result_id` is what approval created: a plan
 * (plan_design), a membership (plan_assignment), a wallet transaction
 * (wallet_top_up), a user (company_member when linked, company_admin) —
 * or null (pending, refused, or a member parked as an invitation).
 */
class ProviderRequestResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'status' => $this->status,
            'provider' => $this->whenLoaded('provider', fn () => ['id' => $this->provider->id, 'name' => $this->provider->name]),
            'company' => $this->company_id === null ? null : [
                'id' => $this->company_id,
                'legal_name' => $this->company?->legal_name,
            ],
            'payload' => $this->payload,
            'submitted_by' => $this->submitter === null ? null : ['id' => $this->submitter->id, 'name' => $this->submitter->name],
            'reviewed_at' => $this->reviewed_at?->toIso8601String(),
            'rejection_reason' => $this->rejection_reason,
            'result_id' => $this->result_id,
            'invoice_preview' => $this->invoice_preview,
            'invoice' => $this->relationLoaded('invoice') && $this->invoice !== null ? [
                'id' => $this->invoice->id,
                'reference' => $this->invoice->reference,
                'status' => $this->invoice->status,
                'amount' => PlainAmount::of($this->invoice->amount),
                'currency' => $this->invoice->currency,
            ] : null,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
