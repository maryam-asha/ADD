<?php

namespace Add\Hr\Payroll\Http\Resources;

use Add\Hr\Payroll\Models\Compensation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Compensation
 */
class CompensationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'component' => ['id' => $this->component->id, 'code' => $this->component->code, 'name' => $this->component->name, 'kind' => $this->component->kind->value],
            'amount' => $this->amount,
            'currency' => $this->currency,
            'effective_from' => $this->effective_from->toDateString(),
            'effective_to' => $this->effective_to?->toDateString(),
            'event_id' => $this->event_id,
        ];
    }
}
