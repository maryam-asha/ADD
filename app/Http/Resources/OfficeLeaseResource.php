<?php

namespace App\Http\Resources;

use App\Domain\Access\Enums\AccessGrantStatus;
use App\Domain\Invoicing\Models\Invoice;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A lease as staff see it. Load company, space.building, provider,
 * periods.invoice and accessGrants for the full view (OfficeLeaseController
 * ::loadForView). Never carries a passcode.
 */
class OfficeLeaseResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'reference' => $this->reference,
            'status' => $this->status,
            'company' => $this->whenLoaded('company', fn () => ['id' => $this->company->id, 'legal_name' => $this->company->legal_name]),
            'space' => $this->whenLoaded('space', fn () => [
                'id' => $this->space->id,
                'name' => $this->space->displayTitle(),
                'branch_id' => $this->space->relationLoaded('building') ? $this->space->building?->branch_id : null,
            ]),
            'provider' => $this->whenLoaded('provider', fn () => $this->provider === null ? null : ['id' => $this->provider->id, 'name' => $this->provider->name]),
            'billed_to' => $this->billed_to,
            'currency' => $this->currency,
            'monthly_amount' => (string) $this->monthly_amount,
            'starts_on' => $this->starts_on->toDateString(),
            'ends_on' => $this->ends_on->toDateString(),
            'access_ends_on' => $this->accessEndsOn()->toDateString(),
            'duration_months' => $this->duration_months,
            'billing_cycle_months' => $this->billing_cycle_months,
            'terminated_on' => $this->terminated_on?->toDateString(),
            'periods' => $this->whenLoaded('periods', fn () => $this->periods->map(fn ($period) => [
                'sequence' => $period->sequence,
                'starts_on' => $period->starts_on->toDateString(),
                'ends_on' => $period->ends_on->toDateString(),
                'amount' => (string) $period->amount,
                'status' => $period->status,
                'invoice' => $period->relationLoaded('invoice') && $period->invoice !== null && $this->showsInvoice($period->invoice, $request) ? [
                    'id' => $period->invoice->id,
                    'reference' => $period->invoice->reference,
                    'status' => $period->invoice->status,
                    'is_overdue' => $period->invoice->isOverdue(),
                    'amount' => (string) $period->invoice->amount,
                    'paid_amount' => (string) $period->invoice->paid_amount,
                    'remaining' => $period->invoice->remaining(),
                    'due_at' => $period->invoice->due_at?->toDateString(),
                ] : null,
            ])->values()),
            'created_at' => $this->created_at,
            ...$this->staffFields(),
        ];
    }

    /** Staff see every period's invoice. */
    protected function showsInvoice(Invoice $invoice, Request $request): bool
    {
        return true;
    }

    /** ADD-internal fields — the provider view leaves these out. */
    protected function staffFields(): array
    {
        return [
            'private_office_request_id' => $this->private_office_request_id,
            'termination_reason' => $this->termination_reason,
            'access' => $this->whenLoaded('accessGrants', fn () => $this->access()),
        ];
    }

    /** @return array{status: string, expires_at: string|null, warning: string|null} */
    private function access(): array
    {
        $live = $this->accessGrants->first(fn ($grant) => in_array($grant->status, [AccessGrantStatus::Issued, AccessGrantStatus::Activated], true));
        $hasLock = $this->relationLoaded('space') && $this->space->devices()->where('type', 'lock')->exists();

        return [
            'status' => $live !== null ? 'active' : 'none',
            'expires_at' => $live?->expires_at?->toIso8601String(),
            'warning' => $hasLock ? null : 'no_lock',
        ];
    }
}
