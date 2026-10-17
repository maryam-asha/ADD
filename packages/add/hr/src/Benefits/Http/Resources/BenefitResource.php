<?php

namespace Add\Hr\Benefits\Http\Resources;

use Add\Hr\Benefits\Models\Benefit;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Benefit
 */
class BenefitResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'description' => $this->description,
            'kind' => $this->kind->value,
            'frequency' => $this->frequency->value,
            'grant_month' => $this->grant_month,
            'amount' => $this->amount,
            'currency' => $this->currency,
            'unit' => $this->unit,
            'employer_cost' => $this->employer_cost,
            'cost_currency' => $this->cost_currency,
            'pay_component_id' => $this->pay_component_id,
            // An object, so JsonResource keeps its keys.
            'fulfillment' => $this->fulfillment === null ? null : (object) $this->fulfillment,
            'eligible_after_days' => $this->eligible_after_days,
            'is_active' => $this->is_active,
            'assignments_count' => $this->whenCounted('assignments'),
        ];
    }
}
