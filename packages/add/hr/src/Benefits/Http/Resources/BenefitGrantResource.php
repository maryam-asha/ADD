<?php

namespace Add\Hr\Benefits\Http\Resources;

use Add\Hr\Benefits\Models\BenefitGrant;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin BenefitGrant
 */
class BenefitGrantResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'benefit' => $this->whenLoaded('benefit', fn () => [
                'id' => $this->benefit->id,
                'code' => $this->benefit->code,
                'name' => $this->benefit->name,
                'kind' => $this->benefit->kind->value,
            ]),
            'employee' => $this->whenLoaded('employee', fn () => [
                'id' => $this->employee->id,
                'employee_number' => $this->employee->employee_number,
                'name' => $this->employee->name,
            ]),
            'period' => $this->period,
            'granted_on' => $this->granted_on->toDateString(),
            'amount' => $this->amount,
            'currency' => $this->currency,
            'unit' => $this->unit,
            'employer_cost' => $this->employer_cost,
            'cost_currency' => $this->cost_currency,
            'status' => $this->status->value,
            'reference' => $this->reference,
            'failure' => $this->failure,
            'attempts' => $this->attempts,
            'fulfilled_at' => $this->fulfilled_at?->toIso8601String(),
            'payslip_id' => $this->payslip_id,
        ];
    }
}
