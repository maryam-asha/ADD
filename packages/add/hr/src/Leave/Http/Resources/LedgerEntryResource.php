<?php

namespace Add\Hr\Leave\Http\Resources;

use Add\Hr\Leave\Models\LedgerEntry;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin LedgerEntry
 */
class LedgerEntryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'leave_type_id' => $this->leave_type_id,
            'kind' => $this->kind->value,
            'amount' => $this->amount,
            'effective_on' => $this->effective_on->toDateString(),
            'period_year' => $this->period_year,
            'leave_request_id' => $this->leave_request_id,
            'notes' => $this->notes,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
