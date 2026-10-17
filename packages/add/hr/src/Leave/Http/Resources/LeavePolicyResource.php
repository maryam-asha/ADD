<?php

namespace Add\Hr\Leave\Http\Resources;

use Add\Hr\Leave\Models\LeavePolicy;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin LeavePolicy
 */
class LeavePolicyResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'leave_type_id' => $this->leave_type_id,
            'name' => $this->name,
            'effective_from' => $this->effective_from->toDateString(),
            'effective_to' => $this->effective_to?->toDateString(),
            'accrual_method' => $this->accrual_method->value,
            'annual_entitlement' => $this->annual_entitlement,
            'service_tiers' => $this->service_tiers,
            'carry_over_max' => $this->carry_over_max,
            'carry_over_expiry_months' => $this->carry_over_expiry_months,
            'max_negative' => $this->max_negative,
            'contract_types' => $this->contract_types,
            'job_grade_ids' => $this->job_grade_ids,
            'org_unit_ids' => $this->org_unit_ids,
        ];
    }
}
