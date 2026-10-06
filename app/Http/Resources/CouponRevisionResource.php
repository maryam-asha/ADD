<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One version of an offer or a coupon, as an admin saved it, and from when
 * (CouponHistory): its terms, dates, switch, targets and audience.
 */
class CouponRevisionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'action' => $this->action,
            'recorded_at' => $this->recorded_at->toIso8601String(),
            'changed_by' => $this->changed_by,
            'code' => $this->code,
            'name' => $this->name,
            'discount_type' => $this->discount_type,
            'discount_value' => $this->discount_value,
            'max_discount_points' => $this->max_discount_points,
            'min_amount_points' => $this->min_amount_points,
            'combinable_with_offers' => $this->combinable_with_offers,
            'usage_limit' => $this->usage_limit,
            'per_user_limit' => $this->per_user_limit,
            'new_members_only' => $this->new_members_only,
            'company_id' => $this->company_id,
            'targets' => $this->targets,
            'user_ids' => $this->user_ids,
            'starts_at' => $this->starts_at?->toIso8601String(),
            'ends_at' => $this->ends_at?->toIso8601String(),
            'is_active' => $this->is_active,
        ];
    }
}
